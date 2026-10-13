<?php
namespace Services;

use Classes\ConflictoIdempotenciaException;
use Classes\Email;
use Classes\Idempotencia;
use Classes\OperacionEnCursoException;
use Classes\Transaccion;
use Repositories\CitaRepository;
use Repositories\NotificacionCitaRepository;
use Repositories\PersistenceException;
use Repositories\ProfesionalRepository;
use Repositories\ReasignacionCitasRepository;

/**
 * Revisión y reasignación ASISTIDA de las citas futuras de un profesional antes de su baja o suspensión.
 *
 * Qué hace:
 *  - revisar(): lista las citas que ocupan agenda y, por cada una, los profesionales sustitutos que prestan TODOS
 *    sus servicios y están realmente disponibles. La disponibilidad sale de DisponibilidadService::consultar()
 *    (la misma validación que usa la reserva pública: horario, descansos, bloqueos y ocupación);
 *  - aplicar(): ejecuta las decisiones del operador. Las reasignaciones son atómicas en lote (todo o nada) y
 *    se revalidan bajo bloqueo con la lógica de la reserva real; las cancelaciones usan el flujo de cancelación
 *    existente (CitaService::cancelarCita) con su aviso al cliente; lo demás queda como «revisión manual».
 *
 * La baja NO se confirma aquí: la confirma ColaboradorService::cambiarAcceso(), que sigue rechazando la baja o
 * suspensión mientras exista cualquier cita futura sin resolver.
 *
 * Privacidad: los datos del cliente (nombre) solo se devuelven si el actor tiene `clientes.ver`.
 */
final class ReasignacionCitasService
{
    public const OK = 'ok';
    public const INVALID = 'invalid';
    public const FORBIDDEN = 'forbidden';
    public const NOT_FOUND = 'not_found';
    public const CONFLICT = 'conflict';
    public const CONFLICT_KEY = 'conflict_key';
    public const IN_PROGRESS = 'in_progress';
    public const ERROR = 'error';

    public const TIMEZONE = 'America/Guayaquil';
    private const MAX_CITAS_CON_SUSTITUTOS = 120;

    private ReasignacionCitasRepository $repo;
    private ?DisponibilidadService $disponibilidad;
    private ?CitaService $citas;
    private ?\DateTimeImmutable $ahoraReferencia;
    private ?NotificacionCitaService $notificaciones;

    public function __construct(
        ?ReasignacionCitasRepository $repo = null,
        ?DisponibilidadService $disponibilidad = null,
        ?CitaService $citas = null,
        ?\DateTimeImmutable $ahoraReferencia = null,
        ?NotificacionCitaService $notificaciones = null
    ) {
        $this->repo = $repo ?? new ReasignacionCitasRepository();
        $this->disponibilidad = $disponibilidad;
        $this->citas = $citas;
        $this->ahoraReferencia = $ahoraReferencia;
        $this->notificaciones = $notificaciones;
    }

    private function ahora(): \DateTimeImmutable
    {
        $tz = new \DateTimeZone(self::TIMEZONE);
        return $this->ahoraReferencia ? $this->ahoraReferencia->setTimezone($tz) : new \DateTimeImmutable('now', $tz);
    }

    private function disponibilidad(): DisponibilidadService
    {
        return $this->disponibilidad ??= new DisponibilidadService(new ProfesionalRepository($this->repo->conexion()));
    }

    private function citas(): CitaService
    {
        if ($this->citas === null) {
            $this->citas = new CitaService(new CitaRepository($this->repo->conexion()), null, $this->ahoraReferencia);
        }
        return $this->citas;
    }

    private function notificaciones(): NotificacionCitaService
    {
        return $this->notificaciones ??= new NotificacionCitaService(
            new NotificacionCitaRepository($this->repo->conexion()),
            new CitaRepository($this->repo->conexion()),
            $this->ahoraReferencia
        );
    }

    /** @return string[]|null permisos del actor si tiene acceso laboral vigente */
    private function permisos(int $actor): ?array
    {
        $c = (new AuthorizationService($this->repo->conexion()))->contexto($actor);
        if (!$c || (int)$c['activo'] !== 1 || (int)$c['confirmado'] !== 1) { return null; }
        return AuthorizationService::ROLES[$c['rol']] ?? [];
    }

    // ------------------------------------------------------------------ Revisión

    /**
     * @return array{status:string,errores?:string[],profesional?:array,citas?:array,resumen?:array,ver_cliente?:bool}
     */
    public function revisar(int $actor, int $colaboradorId): array
    {
        $permisos = $this->permisos($actor);
        if ($permisos === null || !in_array('agenda.gestionar', $permisos, true)) {
            return ['status' => self::FORBIDDEN, 'errores' => ['No tienes permiso para revisar o reasignar citas.']];
        }
        $verCliente = in_array('clientes.ver', $permisos, true);
        try {
            $ficha = $this->repo->fichaDeColaborador($colaboradorId);
            if (!$ficha) {
                return ['status' => self::NOT_FOUND, 'errores' => ['Esta persona no tiene una ficha profesional con agenda.']];
            }
            $ahora = $this->ahora();
            $pendientes = $this->repo->citasPendientes((int)$ficha['profesional_id'], $ahora->format('Y-m-d'));
            $ids = array_map(static fn(array $c): int => (int)$c['id'], $pendientes);
            $servicios = $this->repo->serviciosDeCitas($ids);
            $conComision = $this->repo->citasConComisionDevengada($ids);
            $candidatos = $this->candidatos((int)$ficha['profesional_id']);

            $salida = [];
            $cacheDisponibilidad = [];
            $evaluadas = 0;
            foreach ($pendientes as $c) {
                $id = (int)$c['id'];
                $serviciosCita = $servicios[$id] ?? [];
                $idsServicios = array_values(array_unique(array_map(static fn(array $s): int => $s['servicio_id'], $serviciosCita)));
                $inicio = (string)($c['hora_inicio'] ?: $c['hora']);
                $iniciada = ($c['fecha'] . ' ' . $inicio) <= $ahora->format('Y-m-d H:i:s');
                $bloqueo = null;
                if ($c['estado'] !== 'confirmada') {
                    $bloqueo = 'Está en servicio: complétala desde la agenda antes de continuar.';
                } elseif ($iniciada) {
                    $bloqueo = 'Ya comenzó: márcala como atendida o como no asistida desde la agenda.';
                } elseif (!$c['hora_inicio'] || !$c['hora_fin'] || !(int)$c['duracion_total_minutos']) {
                    $bloqueo = 'Cita histórica sin horario completo: requiere gestión manual o cancelación.';
                } elseif (!$idsServicios) {
                    $bloqueo = 'La cita no tiene servicios registrados: requiere gestión manual o cancelación.';
                } elseif (isset($conComision[$id])) {
                    $bloqueo = 'Su pago ya devengó comisión para este profesional: no se reasigna automáticamente (cancela o consulta con finanzas).';
                }
                $sustitutos = [];
                if ($bloqueo === null) {
                    if ($evaluadas++ < self::MAX_CITAS_CON_SUSTITUTOS) {
                        $sustitutos = $this->sustitutosDe($c, $idsServicios, $candidatos, $cacheDisponibilidad);
                    } else {
                        $bloqueo = 'Hay demasiadas citas para calcular sustitutos en una sola revisión: resuelve las primeras y vuelve a abrir esta pantalla.';
                    }
                }
                $salida[] = self::proyectarCita([
                    'id' => $id,
                    'fecha' => (string)$c['fecha'],
                    'hora_inicio' => substr($inicio, 0, 5),
                    'hora_fin' => $c['hora_fin'] ? substr((string)$c['hora_fin'], 0, 5) : null,
                    'duracion' => (int)$c['duracion_total_minutos'],
                    'estado' => (string)$c['estado'],
                    'estado_pago' => (string)$c['estado_pago'],
                    'cliente' => $c['cliente'] !== '' ? (string)$c['cliente'] : 'Cliente sin nombre',
                    'servicios' => array_map(static fn(array $s): string => $s['nombre'], $serviciosCita),
                    'bloqueo' => $bloqueo,
                    'sustitutos' => $sustitutos,
                    'cancelable' => $c['estado'] === 'confirmada' && !$iniciada,
                ], $verCliente);
            }
            $resumen = [
                'total' => count($salida),
                'con_sustituto' => count(array_filter($salida, static fn(array $c): bool => $c['sustitutos'] !== [])),
                'bloqueadas' => count(array_filter($salida, static fn(array $c): bool => $c['bloqueo'] !== null)),
            ];
            return ['status' => self::OK, 'ver_cliente' => $verCliente, 'resumen' => $resumen, 'citas' => $salida,
                'profesional' => ['id' => (int)$ficha['profesional_id'], 'nombre' => (string)$ficha['profesional_nombre'], 'colaborador_id' => (int)$ficha['colaborador_id'],
                    'colaborador' => trim($ficha['nombre'] . ' ' . $ficha['apellido']), 'rol' => (string)$ficha['rol'], 'estado' => (string)$ficha['colaborador_estado']]];
        } catch (PersistenceException $e) {
            error_log('[ReasignacionCitasService::revisar] ' . $e->getMessage());
            return ['status' => self::ERROR, 'errores' => ['No fue posible cargar las citas del profesional. Inténtalo de nuevo.']];
        }
    }

    /**
     * Privacidad: sin `clientes.ver` la cita se muestra sin ningún dato del cliente (ni nombre ni identificador de cuenta).
     * Único punto por el que pasan los datos del cliente hacia la vista.
     */
    public static function proyectarCita(array $cita, bool $verCliente): array
    {
        if (!$verCliente) { $cita['cliente'] = null; }
        return $cita;
    }

    /** @return array<int, array{id:int,nombre:string,servicios:int[]}> profesionales activos distintos del origen */
    private function candidatos(int $origenId): array
    {
        $lista = [];
        foreach ((new ProfesionalRepository($this->repo->conexion()))->findAll(true) as $p) {
            if ((int)$p->id === $origenId) { continue; }
            $lista[] = ['id' => (int)$p->id, 'nombre' => (string)$p->nombre, 'servicios' => array_map('intval', $p->servicioIds)];
        }
        return $lista;
    }

    /**
     * Sustitutos válidos para una cita: prestan todos sus servicios y DisponibilidadService confirma un intervalo
     * con el mismo inicio y la misma duración de la cita. (La confirmación definitiva se hace bajo bloqueo al aplicar.)
     *
     * @return array<int, array{id:int,nombre:string}>
     */
    private function sustitutosDe(array $cita, array $idsServicios, array $candidatos, array &$cache): array
    {
        $inicio = substr((string)($cita['hora_inicio'] ?: $cita['hora']), 0, 5);
        $duracion = (int)$cita['duracion_total_minutos'];
        $res = [];
        foreach ($candidatos as $p) {
            if (array_diff($idsServicios, $p['servicios'])) { continue; }
            $clave = $p['id'] . '|' . $cita['fecha'] . '|' . implode(',', $idsServicios);
            if (!isset($cache[$clave])) {
                // Paso de 1 minuto: la reserva real acepta cualquier inicio dentro de una ventana libre.
                $cache[$clave] = $this->disponibilidad()->consultar($p['id'], (string)$cita['fecha'], $idsServicios, 1);
            }
            $d = $cache[$clave];
            if (empty($d['resultado']) || empty($d['disponible'])) { continue; }
            foreach ($d['intervalos'] as $i) {
                if ($i['inicio'] === $inicio && (int)$i['duracion_minutos'] === $duracion) {
                    $res[] = ['id' => $p['id'], 'nombre' => $p['nombre']];
                    break;
                }
            }
        }
        return $res;
    }

    // ------------------------------------------------------------------ Aplicación

    /**
     * @param array<int|string,mixed> $decisiones citaId → 'reasignar:<profesionalId>' | 'cancelar' | 'manual'
     * @return array{status:string,errores?:string[],reasignadas?:int,canceladas?:int,manuales?:int,pendientes?:int,repetida?:bool,avisos?:string[]}
     */
    public function aplicar(int $actor, int $colaboradorId, array $decisiones, string $motivo, ?string $clave): array
    {
        $permisos = $this->permisos($actor);
        if ($permisos === null || !in_array('agenda.gestionar', $permisos, true)) {
            return ['status' => self::FORBIDDEN, 'errores' => ['No tienes permiso para reasignar o cancelar citas.']];
        }
        if ($clave === null || !Idempotencia::claveValida($clave)) {
            return ['status' => self::INVALID, 'errores' => ['El formulario venció. Recárgalo antes de enviar.']];
        }
        $motivo = trim(preg_replace('/\s+/u', ' ', $motivo) ?? '');
        if (mb_strlen($motivo) < 3 || mb_strlen($motivo) > 200 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $motivo)) {
            return ['status' => self::INVALID, 'errores' => ['Indica el motivo (entre 3 y 200 caracteres): quedará en el historial de cada cita.']];
        }
        try {
            $ficha = $this->repo->fichaDeColaborador($colaboradorId);
            if (!$ficha) { return ['status' => self::NOT_FOUND, 'errores' => ['Esta persona no tiene una ficha profesional con agenda.']]; }
            $origen = (int)$ficha['profesional_id'];
            $pendientes = $this->repo->citasPendientes($origen, $this->ahora()->format('Y-m-d'));
            $vigentes = [];
            foreach ($pendientes as $c) { $vigentes[(int)$c['id']] = $c; }

            // Sintaxis estricta de las decisiones (no se confía en el formulario).
            $reasignar = [];
            $cancelar = [];
            $soloManual = [];
            $manuales = 0;
            foreach ($decisiones as $citaRaw => $valor) {
                $citaId = filter_var((string)$citaRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($citaId === false || !is_string($valor)) {
                    return ['status' => self::INVALID, 'errores' => ['La lista de citas cambió o contiene datos no válidos. Recarga la página.']];
                }
                if ($valor === 'cancelar') { $cancelar[$citaId] = $citaId; continue; }
                if ($valor === '' || $valor === 'manual') { $manuales++; $soloManual[$citaId] = $citaId; continue; }
                if (preg_match('/^reasignar:([1-9][0-9]{0,9})$/', $valor, $m)) { $reasignar[$citaId] = (int)$m[1]; continue; }
                return ['status' => self::INVALID, 'errores' => ['Hay una decisión no válida. Recarga la página.']];
            }
            ksort($reasignar);
            ksort($cancelar);
            if (!$reasignar && !$cancelar) {
                return ['status' => self::INVALID, 'errores' => ['No elegiste ninguna acción: reasigna o cancela al menos una cita, o déjalas para revisión manual y la baja seguirá bloqueada.']];
            }

            // Reenvío del mismo formulario (doble clic, respuesta perdida): si la fase de reasignación ya se registró con esta
            // clave se devuelve su resultado original; esas citas ya no están pendientes y no deben tomarse por una lista alterada.
            $actorId = Idempotencia::actorUsuario($actor, 'equipo');
            $contenido = ['colaborador' => $colaboradorId, 'reasignar' => $reasignar, 'motivo' => $motivo];
            $previa = $reasignar ? Idempotencia::buscar($this->repo->conexion(), 'colaborador.reasignar', $actorId, $clave, $contenido) : null;
            $hechasPrevias = $previa ? array_column($previa['datos']['hechas'] ?? [], 'cita') : [];

            // Cada cita debe seguir pendiente DE ESTE profesional, salvo que ya esté resuelta por esta misma operación.
            foreach (array_keys($reasignar) as $citaId) {
                if (!isset($vigentes[$citaId]) && !in_array($citaId, $hechasPrevias, true)) {
                    return ['status' => self::INVALID, 'errores' => ['La lista de citas cambió o contiene datos no válidos. Recarga la página.']];
                }
            }
            foreach (array_keys($cancelar) as $citaId) {
                if (!isset($vigentes[$citaId]) && !$this->repo->cancelacionRegistrada($citaId, $origen)) {
                    return ['status' => self::INVALID, 'errores' => ['La lista de citas cambió o contiene datos no válidos. Recarga la página.']];
                }
            }
            foreach (array_keys($soloManual) as $citaId) {
                if (!isset($vigentes[$citaId])) {
                    return ['status' => self::INVALID, 'errores' => ['La lista de citas cambió o contiene datos no válidos. Recarga la página.']];
                }
            }

            $resultado = ['status' => self::OK, 'reasignadas' => 0, 'canceladas' => 0, 'manuales' => $manuales, 'avisos' => []];
            $nombreOrigen = (string)$ficha['profesional_nombre'];

            // Fase A: reasignaciones, TODAS o NINGUNA, con la clave de operación del formulario.
            if ($reasignar && $previa) {
                $resultado['reasignadas'] = count($previa['datos']['hechas'] ?? []);
                $resultado['repetida'] = true;
            } elseif ($reasignar) {
                if (Transaccion::nivel($this->repo->conexion()) === 0) {
                    // Mismo aislamiento que la reserva real: las lecturas bloqueantes ven siempre lo último confirmado.
                    @$this->repo->conexion()->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                }
                $ahoraLocal = $this->ahora()->format('Y-m-d H:i:s');
                $op = Idempotencia::ejecutar($this->repo->conexion(), 'colaborador.reasignar', $actorId, $clave, $contenido,
                    function () use ($actor, $origen, $reasignar, $motivo, $ahoraLocal, $ficha): array {
                        $hechas = [];
                        $notificaciones = [];
                        foreach ($reasignar as $citaId => $destino) {
                            $r = $this->repo->reasignarEnTransaccion($actor, $citaId, $origen, $destino, $motivo, $ahoraLocal);
                            if (!$r['ok']) { throw new ReasignacionRechazada($citaId, (string)$r['codigo'], (string)$r['error']); }
                            $hechas[] = ['cita' => $citaId, 'destino' => $destino, 'reasignacion' => $r['reasignacion_id']];
                            if ($r['notificacion_id']) { $notificaciones[] = $r['notificacion_id']; }
                        }
                        $this->repo->auditarEquipo($actor, $ficha['usuario_id'] !== null ? (int)$ficha['usuario_id'] : null, 'citas_reasignadas',
                            (string)$ficha['codigo'] . ': ' . count($hechas) . ' cita(s)');
                        return ['recurso_tipo' => 'profesional', 'recurso_id' => $origen, 'datos' => ['hechas' => $hechas, 'notificaciones' => $notificaciones]];
                    });
                $resultado['reasignadas'] = count($op['datos']['hechas'] ?? []);
                $resultado['repetida'] = $op['repetida'];
                if (!$op['repetida']) { $this->despachar($op['datos']['notificaciones'] ?? []); }
            }

            // Fase B: cancelaciones por el flujo existente (cada una con su aviso al cliente). Idempotentes por naturaleza.
            foreach ($cancelar as $citaId) {
                if (!isset($vigentes[$citaId])) { $resultado['canceladas']++; continue; } // ya cancelada por esta decisión
                $snapshot = ['fecha' => (string)$vigentes[$citaId]['fecha'], 'hora_inicio' => substr((string)($vigentes[$citaId]['hora_inicio'] ?: $vigentes[$citaId]['hora']), 0, 5),
                    'estado_pago' => (string)$vigentes[$citaId]['estado_pago'], 'estado_previo' => (string)$vigentes[$citaId]['estado']];
                $r = $this->citas()->cancelarCita($citaId, $actor, true, mb_substr('Baja o suspensión del profesional ' . $nombreOrigen . ': ' . $motivo, 0, 255));
                if (!empty($r['resultado'])) {
                    $this->repo->registrarCancelacion($actor, $citaId, ['id' => $origen, 'nombre' => $nombreOrigen], $motivo, $snapshot);
                    $resultado['canceladas']++;
                } else {
                    $resultado['avisos'][] = 'Cita #' . $citaId . ': ' . ($r['error'] ?? 'no se pudo cancelar.');
                }
            }
            $resultado['pendientes'] = count($this->repo->citasPendientes($origen, $this->ahora()->format('Y-m-d')));
            return $resultado;
        } catch (ReasignacionRechazada $e) {
            $estado = $e->codigoNegocio === 'conflicto_ocupacion' ? self::CONFLICT : self::INVALID;
            return ['status' => $estado, 'errores' => ['No se aplicó ninguna reasignación. Cita #' . $e->citaId . ': ' . $e->getMessage()], 'codigo' => $e->codigoNegocio];
        } catch (ConflictoIdempotenciaException) {
            return ['status' => self::CONFLICT_KEY, 'errores' => ['Esta solicitud ya se usó con otras decisiones. Recarga la página.']];
        } catch (OperacionEnCursoException) {
            return ['status' => self::IN_PROGRESS, 'errores' => ['La solicitud anterior sigue procesándose. Espera unos segundos y recarga.']];
        } catch (PersistenceException $e) {
            error_log('[ReasignacionCitasService::aplicar] ' . $e->getMessage());
            return ['status' => self::ERROR, 'errores' => ['No se pudo completar la reasignación. No se aplicaron cambios.']];
        } catch (\mysqli_sql_exception $e) {
            error_log('[ReasignacionCitasService::aplicar] ' . $e->getMessage());
            return ['status' => self::ERROR, 'errores' => ['No se pudo completar la reasignación. No se aplicaron cambios.']];
        }
    }

    /**
     * Envía de inmediato (mejor esfuerzo, fuera de toda transacción) los avisos del outbox recién creados.
     * Si el envío falla, la fila queda pendiente/fallida y la recoge el worker de notificaciones: nunca se duplica,
     * porque cada intento adquiere la fila con un arrendamiento exclusivo.
     *
     * @param int[] $ids
     */
    private function despachar(array $ids): void
    {
        $repoNotif = new NotificacionCitaRepository($this->repo->conexion());
        $servicio = $this->notificaciones();
        foreach ($ids as $id) {
            try {
                $n = $repoNotif->findById((int)$id);
                if (!$n || $n->estado === 'enviado') { continue; }
                $token = $servicio->generarWorkerToken();
                if (!$servicio->adquirirParaEnvio((int)$id, $token)) { continue; }
                $mensaje = $servicio->construirContenidoHtml($n->tipo, $n->obtenerPayload(), $n->asunto);
                $d = Email::despacharNotificacionCita($n->tipo, $n->destinatario_email, $n->destinatario_nombre, $mensaje['asunto'], $mensaje['html'], [
                    'cita_id' => $n->cita_referencia_id, 'clave_idempotencia' => $n->clave_idempotencia,
                    'enlace' => $mensaje['enlace'], 'intento_numero' => $n->intentos + 1,
                ], null, $mensaje['texto']);
                if ($d['exito']) {
                    $servicio->marcarComoEnviado((int)$id, $token);
                } else {
                    $servicio->marcarComoFallido((int)$id, $token, (string)($d['error'] ?? 'Fallo de envío'), $servicio->calcularProximoIntentoUtc($n->intentos + 1, $n->max_intentos));
                }
            } catch (\Throwable $e) {
                error_log('[ReasignacionCitasService::despachar] ' . $e->getMessage());
            }
        }
    }
}

<?php

namespace Services;

use Classes\Email;
use Model\NotificacionCita;
use Repositories\CitaRepository;
use Repositories\NotificacionCitaRepository;
use Repositories\PersistenceException;

/**
 * Servicio de dominio para las notificaciones transaccionales de citas (Fase 6A):
 * - Confirmación de cita (`confirmacion`)
 * - Cancelación de cita (`cancelacion`)
 * - Recordatorios configurables de citas próximas (`recordatorio`)
 * - Procesamiento idempotente y cola de reintentos seguros con backoff sin duplicados.
 * - Aislamiento total de fallos SMTP para que ningún error de correo revierta operaciones confirmadas.
 */
class NotificacionCitaService
{
    public const STATUS_OK = 'ok';
    public const STATUS_YA_ENVIADO = 'ya_enviado';
    public const STATUS_FALLIDO = 'fallido';
    public const STATUS_OMITIDO = 'omitido';
    public const STATUS_LEASE_PERDIDO = 'lease_perdido';
    public const STATUS_ERROR = 'error';

    public const TIMEZONE = 'America/Guayaquil';
    public const DEFAULT_RECORDATORIO_HORAS = 24;

    private NotificacionCitaRepository $notificacionRepository;
    private CitaRepository $citaRepository;
    private ?\DateTimeImmutable $ahoraReferencia;
    /** @var callable|null */
    private $transport;
    private ?int $recordatorioHorasConfig = null;

    public function __construct(
        ?NotificacionCitaRepository $notificacionRepository = null,
        ?CitaRepository $citaRepository = null,
        ?\DateTimeImmutable $ahoraReferencia = null,
        ?callable $transport = null
    ) {
        $this->notificacionRepository = $notificacionRepository ?? new NotificacionCitaRepository();
        $this->citaRepository = $citaRepository ?? new CitaRepository($this->notificacionRepository->getDb());
        $this->ahoraReferencia = $ahoraReferencia;
        $this->transport = $transport;
    }

    /**
     * Permite configurar un reloj de referencia sustituible para pruebas deterministas.
     */
    public function setAhoraReferencia(?\DateTimeImmutable $ahoraReferencia): void
    {
        $this->ahoraReferencia = $ahoraReferencia;
    }

    /**
     * Permite inyectar un transporte de correo sustituible en esta instancia.
     */
    public function setTransport(?callable $transport): void
    {
        $this->transport = $transport;
    }

    /**
     * Permite configurar la ventana de anticipación de recordatorios en horas.
     */
    public function setRecordatorioHoras(?int $horas): void
    {
        $this->recordatorioHorasConfig = $horas;
    }

    /**
     * Retorna la zona horaria oficial de la agenda (`America/Guayaquil`).
     */
    public function obtenerZonaHoraria(): \DateTimeZone
    {
        return new \DateTimeZone(self::TIMEZONE);
    }

    /**
     * Retorna el instante actual en `America/Guayaquil`.
     */
    public function obtenerAhora(): \DateTimeImmutable
    {
        $tz = $this->obtenerZonaHoraria();
        if ($this->ahoraReferencia !== null) {
            return $this->ahoraReferencia->setTimezone($tz);
        }
        return new \DateTimeImmutable('now', $tz);
    }

    /**
     * Retorna el instante actual en UTC (`Y-m-d H:i:s`) para marcas de tiempo de base de datos.
     */
    public function obtenerAhoraUtc(): \DateTimeImmutable
    {
        $utc = new \DateTimeZone('UTC');
        if ($this->ahoraReferencia !== null) {
            return $this->ahoraReferencia->setTimezone($utc);
        }
        return new \DateTimeImmutable('now', $utc);
    }

    /**
     * Resuelve la ventana de anticipación en horas para recordatorios (`1..720`),
     * priorizando argumento explícito, configuración de instancia, variable de entorno
     * `NOTIFICACIONES_RECORDATORIO_HORAS` y el valor por defecto `24`.
     */
    public function resolverHorasAnticipacionRecordatorio(?int $horasOverride = null): int
    {
        $candidato = $horasOverride ?? $this->recordatorioHorasConfig;

        if ($candidato === null) {
            $envVal = $_ENV['NOTIFICACIONES_RECORDATORIO_HORAS'] ?? getenv('NOTIFICACIONES_RECORDATORIO_HORAS');
            if ($envVal !== false && $envVal !== null && trim((string)$envVal) !== '') {
                $valInt = filter_var(trim((string)$envVal), FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1, 'max_range' => 720]
                ]);
                if ($valInt !== false) {
                    return (int)$valInt;
                }
            }
            return self::DEFAULT_RECORDATORIO_HORAS;
        }

        if ($candidato < 1 || $candidato > 720) {
            return self::DEFAULT_RECORDATORIO_HORAS;
        }

        return $candidato;
    }

    /**
     * Genera la clave única de idempotencia para confirmación de cita.
     */
    public static function generarClaveConfirmacion(int $citaId): string
    {
        return "cita:{$citaId}:confirmacion";
    }

    /**
     * Genera la clave única de idempotencia para cancelación de cita.
     */
    public static function generarClaveCancelacion(int $citaId): string
    {
        return "cita:{$citaId}:cancelacion";
    }

    /**
     * Genera la clave única de idempotencia para recordatorio de cita.
     */
    public static function generarClaveRecordatorio(int $citaId, string $fecha, string $horaInicio): string
    {
        $horaLimpia = substr(trim($horaInicio), 0, 5);
        return "cita:{$citaId}:recordatorio:{$fecha}_{$horaLimpia}";
    }

    /**
     * Calcula el próximo instante de reintento en UTC aplicando backoff progresivo:
     * - Intento 1 fallido -> +5 minutos
     * - Intento 2 fallido -> +15 minutos
     * - Intento >= max_intentos -> null (agotado)
     */
    public function calcularProximoIntentoUtc(int $intentosRealizados, int $maxIntentos): ?string
    {
        if ($intentosRealizados >= $maxIntentos) {
            return null;
        }

        $minutosEspera = match ($intentosRealizados) {
            1 => 5,
            2 => 15,
            default => 30,
        };

        return $this->obtenerAhoraUtc()
            ->modify("+{$minutosEspera} minutes")
            ->format('Y-m-d H:i:s');
    }

    /**
     * Registra y envía de forma aislada la notificación de confirmación para una cita ya confirmada en BD.
     * Nunca lanza excepciones hacia el flujo llamador para garantizar que un fallo SMTP jamás revierta la reserva.
     *
     * @return array<string, mixed>
     */
    public function notificarConfirmacionCita(int $citaId): array
    {
        try {
            $snapshot = $this->notificacionRepository->obtenerSnapshotCompletoCita($citaId);
            if ($snapshot === null) {
                return [
                    'status' => self::STATUS_OMITIDO,
                    'enviado' => false,
                    'motivo' => 'cita_no_encontrada'
                ];
            }

            $emailDestino = trim((string)($snapshot['cliente_email'] ?? ''));
            if ($emailDestino === '' || filter_var($emailDestino, FILTER_VALIDATE_EMAIL) === false) {
                return [
                    'status' => self::STATUS_OMITIDO,
                    'enviado' => false,
                    'motivo' => 'destinatario_invalido'
                ];
            }

            $snapshot['tipo_notificacion'] = NotificacionCita::TIPO_CONFIRMACION;
            $clave = self::generarClaveConfirmacion($citaId);

            return $this->registrarYDespachar(
                NotificacionCita::TIPO_CONFIRMACION,
                $clave,
                $snapshot,
                true
            );
        } catch (\Throwable $e) {
            error_log("[NotificacionCitaService::notificarConfirmacionCita] Error aislado para cita {$citaId}: " . $e->getMessage());
            return [
                'status' => self::STATUS_ERROR,
                'enviado' => false,
                'error' => Email::sanearMensajeError($e->getMessage())
            ];
        }
    }

    /**
     * Captura el snapshot completo de una cita antes de que sea eliminada de `citas`.
     *
     * @return array<string, mixed>|null
     */
    public function capturarSnapshotCitaParaCancelacion(int $citaId, string $canceladoPor = 'cliente'): ?array
    {
        try {
            $snapshot = $this->notificacionRepository->obtenerSnapshotCompletoCita($citaId);
            if ($snapshot === null) {
                return null;
            }
            $snapshot['tipo_notificacion'] = NotificacionCita::TIPO_CANCELACION;
            $snapshot['cancelado_por'] = ($canceladoPor === 'admin') ? 'admin' : 'cliente';
            return $snapshot;
        } catch (\Throwable $e) {
            error_log("[NotificacionCitaService::capturarSnapshotCitaParaCancelacion] Error al capturar snapshot de cita {$citaId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Registra y envía de forma aislada la notificación de cancelación a partir de un snapshot previamente capturado.
     * Como la cita ya fue eliminada de `citas`, persiste `cita_id = NULL` y conserva `cita_referencia_id = $citaId`.
     *
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public function notificarCancelacionCitaDesdeSnapshot(array $snapshot, bool $citaAunExisteEnBd = false): array
    {
        try {
            $citaId = (int)($snapshot['cita_id'] ?? 0);
            if ($citaId <= 0) {
                return [
                    'status' => self::STATUS_OMITIDO,
                    'enviado' => false,
                    'motivo' => 'cita_id_invalido'
                ];
            }

            $emailDestino = trim((string)($snapshot['cliente_email'] ?? ''));
            if ($emailDestino === '' || filter_var($emailDestino, FILTER_VALIDATE_EMAIL) === false) {
                return [
                    'status' => self::STATUS_OMITIDO,
                    'enviado' => false,
                    'motivo' => 'destinatario_invalido'
                ];
            }

            $snapshot['tipo_notificacion'] = NotificacionCita::TIPO_CANCELACION;
            if (!isset($snapshot['cancelado_por'])) {
                $snapshot['cancelado_por'] = 'cliente';
            }

            $clave = self::generarClaveCancelacion($citaId);

            return $this->registrarYDespachar(
                NotificacionCita::TIPO_CANCELACION,
                $clave,
                $snapshot,
                $citaAunExisteEnBd
            );
        } catch (\Throwable $e) {
            error_log("[NotificacionCitaService::notificarCancelacionCitaDesdeSnapshot] Error aislado: " . $e->getMessage());
            return [
                'status' => self::STATUS_ERROR,
                'enviado' => false,
                'error' => Email::sanearMensajeError($e->getMessage())
            ];
        }
    }

    /**
     * Método de conveniencia para notificar cancelación consultando la cita si aún existe en BD.
     *
     * @return array<string, mixed>
     */
    public function notificarCancelacionCita(int $citaId, string $canceladoPor = 'cliente'): array
    {
        $snapshot = $this->capturarSnapshotCitaParaCancelacion($citaId, $canceladoPor);
        if ($snapshot === null) {
            return [
                'status' => self::STATUS_OMITIDO,
                'enviado' => false,
                'motivo' => 'cita_no_encontrada'
            ];
        }

        return $this->notificarCancelacionCitaDesdeSnapshot($snapshot, true);
    }

    /**
     * Evalúa todas las citas futuras activas cuya hora de inicio en `America/Guayaquil` se encuentre
     * dentro de la ventana `(ahora, ahora + $horasAnticipacion]` y envía sus recordatorios de forma idempotente.
     *
     * @return array<string, mixed>
     */
    public function procesarRecordatorios(?int $horasAnticipacion = null): array
    {
        $horas = $this->resolverHorasAnticipacionRecordatorio($horasAnticipacion);
        $tz = $this->obtenerZonaHoraria();
        $ahora = $this->obtenerAhora();
        $limiteVentana = $ahora->modify("+{$horas} hours");

        $fechaDesde = $ahora->format('Y-m-d');
        $fechaHasta = $limiteVentana->format('Y-m-d');

        try {
            $citasEnRango = $this->notificacionRepository->buscarCitasEnRangoFechasParaRecordatorio($fechaDesde, $fechaHasta);
        } catch (PersistenceException $e) {
            return [
                'status' => self::STATUS_ERROR,
                'ventana_horas' => $horas,
                'evaluadas' => 0,
                'en_ventana' => 0,
                'enviados' => 0,
                'ya_enviados' => 0,
                'fallidos' => 0,
                'error' => 'No fue posible consultar las citas para recordatorios.'
            ];
        }

        $evaluadas = count($citasEnRango);
        $enVentana = 0;
        $enviados = 0;
        $yaEnviados = 0;
        $fallidos = 0;
        $detalles = [];

        foreach ($citasEnRango as $snapshot) {
            $citaId = (int)($snapshot['cita_id'] ?? 0);
            $fecha = trim((string)($snapshot['fecha'] ?? ''));
            $horaInicio = trim((string)($snapshot['hora_inicio'] ?? ($snapshot['hora'] ?? '')));
            $horaInicioHm = substr($horaInicio, 0, 5);

            if ($citaId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !preg_match('/^\d{2}:\d{2}$/', $horaInicioHm)) {
                continue;
            }

            $instanteCita = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', "{$fecha} {$horaInicioHm}", $tz);
            if (!$instanteCita) {
                continue;
            }

            // Solo citas estrictamente futuras dentro de la ventana [ahora, limiteVentana]
            if ($instanteCita <= $ahora || $instanteCita > $limiteVentana) {
                continue;
            }

            $emailDestino = trim((string)($snapshot['cliente_email'] ?? ''));
            if ($emailDestino === '' || filter_var($emailDestino, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            $enVentana++;
            $snapshot['tipo_notificacion'] = NotificacionCita::TIPO_RECORDATORIO;
            $clave = self::generarClaveRecordatorio($citaId, $fecha, $horaInicioHm);

            $res = $this->registrarYDespachar(
                NotificacionCita::TIPO_RECORDATORIO,
                $clave,
                $snapshot,
                true
            );

            if (($res['status'] ?? '') === self::STATUS_OK) {
                $enviados++;
            } elseif (($res['status'] ?? '') === self::STATUS_YA_ENVIADO) {
                $yaEnviados++;
            } else {
                $fallidos++;
            }

            $detalles[] = [
                'cita_id' => $citaId,
                'clave_idempotencia' => $clave,
                'status' => $res['status'] ?? self::STATUS_ERROR,
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'ventana_horas' => $horas,
            'zona_horaria' => self::TIMEZONE,
            'ahora' => $ahora->format('Y-m-d H:i:s'),
            'limite_ventana' => $limiteVentana->format('Y-m-d H:i:s'),
            'evaluadas' => $evaluadas,
            'en_ventana' => $enVentana,
            'enviados' => $enviados,
            'ya_enviados' => $yaEnviados,
            'fallidos' => $fallidos,
            'detalles' => $detalles,
        ];
    }

    /**
     * Procesa la cola de notificaciones en estado `'pendiente'` o `'fallido'` cuyo `proximo_intento_at`
     * haya vencido y cuyos `intentos < max_intentos`.
     * Garantiza reintentos seguros e idempotentes sin duplicados mediante bloqueo `FOR UPDATE`.
     *
     * @return array<string, mixed>
     */
    /**
     * Procesa la cola de notificaciones pendientes y reintentos con arrendamiento exclusivo (worker lease).
     *
     * Reglas obligatorias:
     * - Si la cita asociada se encuentra en estado 'cancelada', descarta confirmaciones y recordatorios pendientes.
     * - Si la notificación es un recordatorio y la fecha/hora de la cita ya transcurrió en America/Guayaquil,
     *   descarta el envío con `ultimo_error = 'Recordatorio descartado por cita ya transcurrida'`.
     * - Recupera tareas atascadas en `enviando` cuyo `lease_expires_at` haya vencido.
     * - Excluye a workers lentos u obsoletos impidiendo sobrescritura de registros enviados por un nuevo worker.
     *
     * @param int $limite
     * @param string|null $workerToken
     * @param int $duracionLeaseSegundos
     * @return array<string, mixed>
     */
    public function procesarPendientesYReintentos(
        int $limite = 50,
        ?string $workerToken = null,
        int $duracionLeaseSegundos = 300
    ): array {
        $ahoraUtc = $this->obtenerAhoraUtc()->format('Y-m-d H:i:s');
        $ahoraLocal = $this->obtenerAhora();
        $tzGuayaquil = $this->obtenerZonaHoraria();

        try {
            $candidatas = $this->notificacionRepository->buscarCandidatasParaReintento($ahoraUtc, $limite);
        } catch (PersistenceException $e) {
            return [
                'status' => self::STATUS_ERROR,
                'procesadas' => 0,
                'enviados' => 0,
                'fallidos' => 0,
                'omitidos' => 0,
                'error' => 'No fue posible consultar la cola de reintentos.'
            ];
        }

        $procesadas = 0;
        $enviados = 0;
        $fallidos = 0;
        $omitidos = 0;
        $detalles = [];

        foreach ($candidatas as $candidata) {
            $idNotif = (int)$candidata->id;
            if ($idNotif <= 0) {
                continue;
            }

            $citaRefId = (int)($candidata->cita_referencia_id ?? $candidata->cita_id ?? 0);

            // Comprobación compartida y unificada de vigencia de la cita antes de arrendar y enviar
            $vigenciaWorker = $this->verificarVigenciaParaDespacho($candidata->tipo, $citaRefId, $idNotif);
            if ($vigenciaWorker['valido'] === false) {
                if ($vigenciaWorker['status'] === self::STATUS_FALLIDO) {
                    $fallidos++;
                    $detalles[] = [
                        'id' => $idNotif,
                        'clave_idempotencia' => $candidata->clave_idempotencia,
                        'resultado' => $vigenciaWorker['motivo'],
                    ];
                } else {
                    $omitidos++;
                    $detalles[] = [
                        'id' => $idNotif,
                        'clave_idempotencia' => $candidata->clave_idempotencia,
                        'resultado' => $vigenciaWorker['motivo'],
                    ];
                }
                continue;
            }
            $cita = $vigenciaWorker['cita'];

            // Regla 4.A: Si es recordatorio y la fecha/hora de la cita ya pasó en America/Guayaquil, descartar
            if ($candidata->tipo === NotificacionCita::TIPO_RECORDATORIO) {
                $payload = $candidata->obtenerPayload();
                $fechaCita = $cita !== null ? (string)$cita->fecha : (string)($payload['fecha'] ?? '');
                $horaCita = $cita !== null ? (string)(!empty($cita->hora_inicio) ? $cita->hora_inicio : $cita->hora) : (string)($payload['hora_inicio'] ?? ($payload['hora'] ?? ''));
                $horaCorta = substr(trim($horaCita), 0, 5);

                if ($fechaCita !== '' && $horaCorta !== '') {
                    $instanteCita = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', "{$fechaCita} {$horaCorta}", $tzGuayaquil);
                    if ($instanteCita && $instanteCita <= $ahoraLocal) {
                        $this->notificacionRepository->descartarNotificacion($idNotif, 'Recordatorio descartado por cita ya transcurrida', null, NotificacionCita::ESTADO_DESCARTADA);
                        $omitidos++;
                        $detalles[] = [
                            'id' => $idNotif,
                            'clave_idempotencia' => $candidata->clave_idempotencia,
                            'resultado' => 'recordatorio_vencido',
                        ];
                        continue;
                    }
                }
            }

            // Adquisición atómica bajo arrendamiento exclusivo (worker lease token)
            $workerTokenActual = $workerToken ?? $this->generarWorkerToken();
            try {
                $adquirido = $this->notificacionRepository->adquirirParaEnvio($idNotif, $workerTokenActual, $duracionLeaseSegundos);
            } catch (PersistenceException $e) {
                $fallidos++;
                continue;
            }

            if (!$adquirido) {
                $omitidos++;
                $detalles[] = [
                    'id' => $idNotif,
                    'clave_idempotencia' => $candidata->clave_idempotencia,
                    'resultado' => 'omitir',
                ];
                continue;
            }

            // Revalidate after claiming the lease so reschedules/preferences changed during acquisition cannot use a stale payload.
            $vigenciaDespacho = $this->verificarVigenciaParaDespacho($candidata->tipo,$citaRefId,$idNotif,$workerTokenActual);
            if (!$vigenciaDespacho['valido']) { $omitidos++; continue; }
            $procesadas++;
            $payload = $candidata->obtenerPayload();
            $mensaje = $this->construirContenidoHtml($candidata->tipo, $payload, $candidata->asunto);

            $despacho = Email::despacharNotificacionCita(
                $candidata->tipo,
                $candidata->destinatario_email,
                $candidata->destinatario_nombre,
                $mensaje['asunto'],
                $mensaje['html'],
                [
                    'cita_id' => $candidata->cita_referencia_id,
                    'clave_idempotencia' => $candidata->clave_idempotencia,
                    'enlace' => $mensaje['enlace'],
                    'reintento' => true,
                    'intento_numero' => $candidata->intentos + 1,
                ],
                $this->transport
            );

            $instanteFinUtc = $this->obtenerAhoraUtc()->format('Y-m-d H:i:s');

            if ($despacho['exito']) {
                $confirmado = $this->notificacionRepository->marcarComoEnviado($idNotif, $workerTokenActual);
                if ($confirmado) {
                    $enviados++;
                    $detalles[] = [
                        'id' => $idNotif,
                        'clave_idempotencia' => $candidata->clave_idempotencia,
                        'resultado' => 'enviado',
                        'intentos' => $candidata->intentos + 1,
                    ];
                } else {
                    // El lease expiró y otro worker tomó la tarea; no contabilizar éxito de este worker
                    $omitidos++;
                    $detalles[] = [
                        'id' => $idNotif,
                        'clave_idempotencia' => $candidata->clave_idempotencia,
                        'resultado' => 'lease_expirado_durante_despacho',
                    ];
                }
            } else {
                $proximoUtc = $this->calcularProximoIntentoUtc(
                    $candidata->intentos + 1,
                    $candidata->max_intentos
                );
                $confirmadoFallo = $this->notificacionRepository->marcarComoFallido(
                    $idNotif,
                    $workerTokenActual,
                    (string)($despacho['error'] ?? 'Fallo en reintento SMTP.'),
                    $proximoUtc
                );
                if ($confirmadoFallo) {
                    $fallidos++;
                    $detalles[] = [
                        'id' => $idNotif,
                        'clave_idempotencia' => $candidata->clave_idempotencia,
                        'resultado' => 'fallido',
                        'intentos' => $candidata->intentos + 1,
                        'error' => $despacho['error'],
                    ];
                } else {
                    $omitidos++;
                    $detalles[] = [
                        'id' => $idNotif,
                        'clave_idempotencia' => $candidata->clave_idempotencia,
                        'resultado' => 'lease_expirado_durante_fallo',
                    ];
                }
            }
        }

        return [
            'status' => self::STATUS_OK,
            'procesadas' => $procesadas,
            'enviados' => $enviados,
            'fallidos' => $fallidos,
            'omitidos' => $omitidos,
            'detalles' => $detalles,
        ];
    }

    /**
     * Construye el asunto, enlace seguro y cuerpo HTML escapado para una notificación de cita.
     *
     * @param string $tipo `'confirmacion'`, `'cancelacion'` o `'recordatorio'`
     * @param array<string, mixed> $payload
     * @param string|null $asuntoPredefinido
     * @return array{asunto: string, html: string, enlace: string}
     */
    public function construirContenidoHtml(string $tipo, array $payload, ?string $asuntoPredefinido = null): array
    {
        $appUrl = Email::resolverAppUrl();
        $enlaceCitas = "{$appUrl}/cita";

        $citaId = (int)($payload['cita_id'] ?? 0);
        $clienteNombre = htmlspecialchars((string)($payload['cliente_nombre'] ?? 'Cliente'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $fecha = htmlspecialchars((string)($payload['fecha'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $horaInicioRaw = trim((string)($payload['hora_inicio'] ?? ($payload['hora'] ?? '')));
        $horaFinRaw = trim((string)($payload['hora_fin'] ?? ''));
        $duracionTotal = isset($payload['duracion_total_minutos']) && (int)$payload['duracion_total_minutos'] > 0
            ? (int)$payload['duracion_total_minutos']
            : null;

        if ($horaInicioRaw !== '' && $horaFinRaw !== '') {
            $horarioTexto = $horaInicioRaw . ' - ' . $horaFinRaw;
            if ($duracionTotal !== null) {
                $horarioTexto .= " ({$duracionTotal} min)";
            }
        } else {
            $horarioTexto = $horaInicioRaw !== '' ? $horaInicioRaw : 'Horario registrado';
        }
        $horarioEscapado = htmlspecialchars($horarioTexto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $profNombreRaw = isset($payload['profesional_nombre']) && trim((string)$payload['profesional_nombre']) !== ''
            ? trim((string)$payload['profesional_nombre'])
            : 'Asignación general del salón';
        $profEscapado = htmlspecialchars($profNombreRaw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $totalFormateado = htmlspecialchars(
            (string)($payload['total_formateado'] ?? number_format((float)($payload['total'] ?? 0), 2, '.', '')),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        $servicios = is_array($payload['servicios'] ?? null) ? $payload['servicios'] : [];
        $itemsHtml = '';
        foreach ($servicios as $srv) {
            $srvNombre = htmlspecialchars((string)($srv['nombre'] ?? 'Servicio'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $srvPrecio = htmlspecialchars((string)($srv['precio'] ?? '0.00'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $srvDur = isset($srv['duracion_minutos']) && (int)$srv['duracion_minutos'] > 0
                ? ' (' . (int)$srv['duracion_minutos'] . ' min)'
                : '';
            $itemsHtml .= "<li>{$srvNombre}{$srvDur} — \${$srvPrecio}</li>";
        }
        if ($itemsHtml === '') {
            $itemsHtml = '<li>Servicios registrados en la reserva</li>';
        }

        $enlaceEscapado = htmlspecialchars($enlaceCitas, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if ($tipo === NotificacionCita::TIPO_CANCELACION) {
            $asunto = $asuntoPredefinido !== null && trim($asuntoPredefinido) !== ''
                ? $asuntoPredefinido
                : "Cancelación de tu cita #{$citaId} en AppSalon";
            $canceladoPor = ($payload['cancelado_por'] ?? 'cliente') === 'admin'
                ? 'por la administración del salón'
                : 'a solicitud tuya';

            $html = "<html><body>"
                . "<h2>Cita #{$citaId} Cancelada — AppSalon</h2>"
                . "<p>Hola <strong>{$clienteNombre}</strong>,</p>"
                . "<p>Te informamos que tu cita <strong>#{$citaId}</strong> ha sido cancelada {$canceladoPor}.</p>"
                . "<ul>"
                . "<li><strong>Fecha:</strong> {$fecha}</li>"
                . "<li><strong>Horario:</strong> {$horarioEscapado}</li>"
                . "<li><strong>Profesional:</strong> {$profEscapado}</li>"
                . "</ul>"
                . "<p><strong>Servicios de la cita cancelada:</strong></p>"
                . "<ul>{$itemsHtml}</ul>"
                . "<p><strong>Total de referencia:</strong> \${$totalFormateado}</p>"
                . "<p>Si deseas programar una nueva cita, puedes hacerlo en: <a href='{$enlaceEscapado}'>Agendar en AppSalon</a></p>"
                . "</body></html>";
        } elseif ($tipo === NotificacionCita::TIPO_RECORDATORIO) {
            $horaCorta = substr($horaInicioRaw, 0, 5);
            $asunto = $asuntoPredefinido !== null && trim($asuntoPredefinido) !== ''
                ? $asuntoPredefinido
                : "Recordatorio de tu cita #{$citaId} en AppSalon ({$payload['fecha']} {$horaCorta})";

            $html = "<html><body>"
                . "<h2>Recordatorio de tu próxima cita #{$citaId} — AppSalon</h2>"
                . "<p>Hola <strong>{$clienteNombre}</strong>,</p>"
                . "<p>Te recordamos que tienes una cita próxima programada en AppSalon:</p>"
                . "<ul>"
                . "<li><strong>Reserva:</strong> #{$citaId}</li>"
                . "<li><strong>Fecha:</strong> {$fecha}</li>"
                . "<li><strong>Horario:</strong> {$horarioEscapado}</li>"
                . "<li><strong>Profesional:</strong> {$profEscapado}</li>"
                . "</ul>"
                . "<p><strong>Servicios reservados:</strong></p>"
                . "<ul>{$itemsHtml}</ul>"
                . "<p><strong>Total:</strong> \${$totalFormateado}</p>"
                . "<p>Puedes consultar o gestionar tus citas en: <a href='{$enlaceEscapado}'>Ver mis citas</a></p>"
                . "</body></html>";
        } else {
            $asunto = $asuntoPredefinido !== null && trim($asuntoPredefinido) !== ''
                ? $asuntoPredefinido
                : "Confirmación de tu cita #{$citaId} en AppSalon";

            $html = "<html><body>"
                . "<h2>Confirmación de Reserva #{$citaId} — AppSalon</h2>"
                . "<p>Hola <strong>{$clienteNombre}</strong>,</p>"
                . "<p>Tu cita ha sido registrada exitosamente con el siguiente detalle:</p>"
                . "<ul>"
                . "<li><strong>Reserva:</strong> #{$citaId}</li>"
                . "<li><strong>Fecha:</strong> {$fecha}</li>"
                . "<li><strong>Horario:</strong> {$horarioEscapado}</li>"
                . "<li><strong>Profesional:</strong> {$profEscapado}</li>"
                . "</ul>"
                . "<p><strong>Servicios seleccionados:</strong></p>"
                . "<ul>{$itemsHtml}</ul>"
                . "<p><strong>Total:</strong> \${$totalFormateado}</p>"
                . "<p>Puedes consultar o gestionar tus citas en: <a href='{$enlaceEscapado}'>Ver mis citas</a></p>"
                . "</body></html>";
        }

        return [
            'asunto' => Email::sanearTextoCabecera($asunto, 180),
            'html' => $html,
            'enlace' => $enlaceCitas
        ];
    }

    /**
     * Ejecuta el flujo transaccional de idempotencia + despacho SMTP + actualización de estado final.
     *
     * @param string $tipo
     * @param string $claveIdempotencia
     * @param array<string, mixed> $snapshot
     * @param bool $citaExisteEnBd
     * @return array<string, mixed>
     */
    /**
     * Adquiere una notificación para envío bajo arrendamiento de worker delegando al repositorio.
     */
    public function adquirirParaEnvio(int $id, string $workerToken, int $duracionLeaseSegundos = 300): bool
    {
        return $this->notificacionRepository->adquirirParaEnvio($id, $workerToken, $duracionLeaseSegundos);
    }

    /**
     * Marca una notificación como enviada validando el token del worker activo.
     */
    public function marcarComoEnviado(int $id, string $workerToken): bool
    {
        return $this->notificacionRepository->marcarComoEnviado($id, $workerToken);
    }

    /**
     * Marca una notificación como fallida con worker token.
     */
    public function marcarComoFallido(
        int $id,
        string $workerToken,
        string $mensajeError,
        ?string $proximoIntentoAt = null
    ): bool {
        return $this->notificacionRepository->marcarComoFallido($id, $workerToken, $mensajeError, $proximoIntentoAt);
    }

    /**
     * Descarta una notificación obsoleta marcándola como 'descartada' o 'cancelada'.
     */
    public function descartarNotificacion(
        int $id,
        string $motivo,
        ?string $workerToken = null,
        string $nuevoEstado = NotificacionCita::ESTADO_DESCARTADA
    ): bool {
        return $this->notificacionRepository->descartarNotificacion($id, $motivo, $workerToken, $nuevoEstado);
    }

    /**
     * Registra un evento de notificación durable en la base de datos (patrón Outbox).
     *
     * @param array<string, mixed> $datos
     * @return NotificacionCita
     */
    public function registrarEventoOutbox(array $datos): NotificacionCita
    {
        $ahoraUtc = $this->obtenerAhoraUtc()->format('Y-m-d H:i:s');
        return $this->notificacionRepository->registrarEventoOutbox($datos, $ahoraUtc);
    }

    /**
     * Genera un identificador único para el arrendamiento de worker.
     */
    public function generarWorkerToken(): string
    {
        return 'worker_' . bin2hex(random_bytes(16));
    }

    /**
     * Comprueba de manera unificada si una cita asociada sigue siendo válida para el envío de notificaciones.
     * Si la cita está cancelada o no existe, descarta la notificación y bloquea el despacho SMTP.
     *
     * @return array{valido: bool, status: string, motivo: string, cita: ?object}
     */
    private function verificarVigenciaParaDespacho(
        string $tipo,
        int $citaId,
        int $notificacionId,
        ?string $workerToken = null
    ): array {
        // Notificaciones de tipo 'cancelacion' sí deben enviarse cuando la cita está cancelada
        if ($tipo === NotificacionCita::TIPO_CANCELACION) {
            return ['valido' => true, 'status' => self::STATUS_OK, 'motivo' => '', 'cita' => null];
        }

        $cita = null;
        $errorConsulta = false;
        try {
            $cita = $this->citaRepository->findById($citaId);
        } catch (\Throwable $e) {
            $errorConsulta = true;
            error_log("[NotificacionCitaService::verificarVigenciaParaDespacho] Error al consultar cita {$citaId}: " . $e->getMessage());
        }

        if ($errorConsulta) {
            // Ante fallo de DB al verificar la cita, no enviar para prevenir despachos erróneos
            return [
                'valido' => false,
                'status' => self::STATUS_FALLIDO,
                'motivo' => 'error_comprobacion_cita',
                'cita' => null
            ];
        }

        $citaInexistente = ($cita === null);
        $citaCancelada = ($cita !== null && $cita->estado === 'cancelada');

        if ($citaInexistente || $citaCancelada) {
            $motivo = $citaInexistente
                ? ($tipo === NotificacionCita::TIPO_RECORDATORIO
                    ? 'Recordatorio descartado: la cita asociada no existe o fue eliminada'
                    : 'Confirmación descartada: la cita asociada no existe o fue eliminada')
                : ($tipo === NotificacionCita::TIPO_RECORDATORIO
                    ? 'Recordatorio descartado: la cita asociada está cancelada'
                    : 'Confirmación descartada: la cita asociada está cancelada');

            if ($notificacionId > 0) {
                $this->notificacionRepository->descartarNotificacion(
                    $notificacionId,
                    $motivo,
                    $workerToken,
                    NotificacionCita::ESTADO_DESCARTADA
                );
            }

            return [
                'valido' => false,
                'status' => self::STATUS_OMITIDO,
                'motivo' => $citaInexistente ? 'cita_no_encontrada' : 'cita_cancelada',
                'cita' => $cita
            ];
        }

        try {
            $notificacion = $notificacionId > 0 ? $this->notificacionRepository->findById($notificacionId) : null;
            $payload = $notificacion ? $notificacion->obtenerPayload() : [];
            $horarioCambiado = isset($payload['fecha']) && isset($payload['hora_inicio']) && (
                (string)($payload['fecha'] ?? '') !== (string)$cita->fecha ||
                substr((string)($payload['hora_inicio'] ?? $payload['hora'] ?? ''),0,5) !== substr((string)($cita->hora_inicio ?: $cita->hora),0,5)
            );
            $terminada = in_array($cita->estado,['completada','no_asistio'],true);
            $optout = $tipo === NotificacionCita::TIPO_RECORDATORIO && !$this->notificacionRepository->recordatoriosPermitidos((int)$cita->usuarioId);
            if ($horarioCambiado || $terminada || $optout) {
                $motivo = $horarioCambiado ? 'horario_reprogramado' : ($terminada ? 'cita_finalizada' : 'recordatorios_desactivados');
                if ($notificacionId > 0) { $this->notificacionRepository->descartarNotificacion($notificacionId,$motivo,$workerToken,NotificacionCita::ESTADO_DESCARTADA); }
                return ['valido'=>false,'status'=>self::STATUS_OMITIDO,'motivo'=>$motivo,'cita'=>$cita];
            }
        } catch (\Throwable $e) {
            return ['valido'=>false,'status'=>self::STATUS_FALLIDO,'motivo'=>'error_comprobacion_preferencias','cita'=>$cita];
        }

        return ['valido' => true, 'status' => self::STATUS_OK, 'motivo' => '', 'cita' => $cita];
    }

    /**
     * Ejecuta el flujo transaccional de idempotencia + despacho SMTP + actualización de estado final.
     *
     * @param string $tipo
     * @param string $claveIdempotencia
     * @param array<string, mixed> $snapshot
     * @param bool $citaExisteEnBd
     * @return array<string, mixed>
     */
    private function registrarYDespachar(
        string $tipo,
        string $claveIdempotencia,
        array $snapshot,
        bool $citaExisteEnBd
    ): array {
        $citaId = (int)($snapshot['cita_id'] ?? 0);
        $usuarioId = isset($snapshot['usuario_id']) && (int)$snapshot['usuario_id'] > 0
            ? (int)$snapshot['usuario_id']
            : null;
        $destEmail = trim((string)($snapshot['cliente_email'] ?? ''));
        $destNombre = trim((string)($snapshot['cliente_nombre'] ?? 'Cliente AppSalon'));

        $mensaje = $this->construirContenidoHtml($tipo, $snapshot);
        $ahoraUtc = $this->obtenerAhoraUtc()->format('Y-m-d H:i:s');
        $workerToken = $this->generarWorkerToken();

        $adquisicion = $this->notificacionRepository->registrarOAdquirirParaEnvio([
            'cita_id' => $citaExisteEnBd ? $citaId : null,
            'cita_referencia_id' => $citaId,
            'usuario_id' => $usuarioId,
            'tipo' => $tipo,
            'clave_idempotencia' => $claveIdempotencia,
            'destinatario_email' => $destEmail,
            'destinatario_nombre' => $destNombre,
            'asunto' => $mensaje['asunto'],
            'payload_json' => $snapshot,
            'max_intentos' => NotificacionCita::DEFAULT_MAX_INTENTOS,
            'worker_token' => $workerToken,
            'lease_segundos' => 300,
        ], $ahoraUtc, false);

        $accion = $adquisicion['accion'];
        /** @var NotificacionCita $notificacion */
        $notificacion = $adquisicion['notificacion'];

        if ($accion === 'ya_enviado') {
            return [
                'status' => self::STATUS_YA_ENVIADO,
                'enviado' => false,
                'ya_enviado' => true,
                'id' => $notificacion->id,
                'clave_idempotencia' => $claveIdempotencia,
                'estado' => $notificacion->estado,
                'intentos' => $notificacion->intentos,
            ];
        }

        if ($accion !== 'enviar') {
            return [
                'status' => self::STATUS_OMITIDO,
                'enviado' => false,
                'motivo' => $accion,
                'id' => $notificacion->id,
                'clave_idempotencia' => $claveIdempotencia,
                'estado' => $notificacion->estado,
                'intentos' => $notificacion->intentos,
            ];
        }

        // Fuente canónica: utilizar el payload y datos persistidos en el registro de outbox
        $payloadCanonico = $notificacion->obtenerPayload();
        if (empty($payloadCanonico)) {
            $payloadCanonico = $snapshot;
        }

        $destEmailFinal = trim((string)$notificacion->destinatario_email) !== '' ? (string)$notificacion->destinatario_email : $destEmail;
        $destNombreFinal = trim((string)$notificacion->destinatario_nombre) !== '' ? (string)$notificacion->destinatario_nombre : $destNombre;
        $asuntoFinal = trim((string)$notificacion->asunto) !== '' ? (string)$notificacion->asunto : ($mensaje['asunto'] ?? null);

        // Validación unificada de vigencia de la cita antes del despacho SMTP
        $vigencia = $this->verificarVigenciaParaDespacho($tipo, $citaId, (int)$notificacion->id, $workerToken);
        if ($vigencia['valido'] === false) {
            return [
                'status' => $vigencia['status'],
                'enviado' => false,
                'motivo' => $vigencia['motivo'],
                'id' => $notificacion->id,
                'clave_idempotencia' => $claveIdempotencia,
                'estado' => $notificacion->estado,
                'intentos' => $notificacion->intentos,
            ];
        }

        $mensajeFinal = $this->construirContenidoHtml($tipo, $payloadCanonico, $asuntoFinal);

        $despacho = Email::despacharNotificacionCita(
            $tipo,
            $destEmailFinal,
            $destNombreFinal,
            $mensajeFinal['asunto'],
            $mensajeFinal['html'],
            [
                'cita_id' => $citaId,
                'clave_idempotencia' => $claveIdempotencia,
                'enlace' => $mensajeFinal['enlace'],
                'intento_numero' => $notificacion->intentos,
            ],
            $this->transport
        );

        $instanteFinUtc = $this->obtenerAhoraUtc()->format('Y-m-d H:i:s');

        if ($despacho['exito']) {
            $confirmado = $this->notificacionRepository->marcarComoEnviado((int)$notificacion->id, $workerToken);
            if ($confirmado) {
                return [
                    'status' => self::STATUS_OK,
                    'enviado' => true,
                    'id' => $notificacion->id,
                    'clave_idempotencia' => $claveIdempotencia,
                    'estado' => NotificacionCita::ESTADO_ENVIADO,
                    'intentos' => $notificacion->intentos,
                ];
            }

            // El lease expiró y otro worker tomó la tarea; no declarar enviado=true
            return [
                'status' => self::STATUS_LEASE_PERDIDO,
                'enviado' => false,
                'id' => $notificacion->id,
                'clave_idempotencia' => $claveIdempotencia,
                'estado' => $notificacion->estado,
                'intentos' => $notificacion->intentos,
                'motivo' => 'lease_expirado_durante_despacho',
            ];
        }

        $proximoUtc = $this->calcularProximoIntentoUtc(
            $notificacion->intentos,
            $notificacion->max_intentos
        );
        $errorTexto = (string)($despacho['error'] ?? 'Fallo en despacho SMTP.');

        $confirmadoFallo = $this->notificacionRepository->marcarComoFallido(
            (int)$notificacion->id,
            $workerToken,
            $errorTexto,
            $proximoUtc
        );

        if (!$confirmadoFallo) {
            return [
                'status' => self::STATUS_LEASE_PERDIDO,
                'enviado' => false,
                'id' => $notificacion->id,
                'clave_idempotencia' => $claveIdempotencia,
                'estado' => $notificacion->estado,
                'intentos' => $notificacion->intentos,
                'motivo' => 'lease_expirado_durante_fallo',
                'error' => $errorTexto,
            ];
        }

        return [
            'status' => self::STATUS_FALLIDO,
            'enviado' => false,
            'id' => $notificacion->id,
            'clave_idempotencia' => $claveIdempotencia,
            'estado' => NotificacionCita::ESTADO_FALLIDO,
            'intentos' => $notificacion->intentos,
            'proximo_intento_at' => $proximoUtc,
            'error' => $errorTexto,
        ];
    }
}

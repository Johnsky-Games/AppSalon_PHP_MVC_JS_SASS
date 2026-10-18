<?php
namespace Services;

use Classes\ConflictoIdempotenciaException;
use Classes\Identidad;
use Classes\Idempotencia;
use Classes\OperacionEnCursoException;
use Classes\RateLimiter;
use Repositories\ColaboradorRepository;
use Repositories\DuplicateEntryException;
use Repositories\PersistenceException;
use Repositories\ProfesionalHomonimosRepository;

/**
 * Ciclo de vida del colaborador: invitación formal, reenvío, aceptación, ficha laboral y acceso.
 * Reglas de dominio y de idempotencia; el SQL vive en ColaboradorRepository y el envío en CorreoSalidaService.
 */
final class ColaboradorService
{
    public const OK = 'ok';
    public const INVALID = 'invalid';
    public const CONFLICT = 'conflict';
    public const CONFLICT_KEY = 'conflict_key';
    public const IN_PROGRESS = 'in_progress';
    public const FORBIDDEN = 'forbidden';
    public const RATE_LIMITED = 'rate_limited';
    public const ERROR = 'error';
    /** El operador canceló ante el aviso de homónimos: no se creó nada. */
    public const CANCELADO = 'cancelado';

    public function __construct(private ColaboradorRepository $repo, private CorreoSalidaService $correos) {}

    private function texto(mixed $v, int $max, bool $requerido = true): ?string
    {
        if (!is_string($v)) { return $requerido ? null : ''; }
        $t = trim(preg_replace('/\s+/u', ' ', $v) ?? '');
        if (($requerido && $t === '') || mb_strlen($t) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $t)) { return null; }
        return $t;
    }

    /** @return array{0:?array,1:string[]} datos normalizados o lista de errores */
    public function validarInvitacion(array $post): array
    {
        $e = [];
        foreach (['nombre', 'apellido', 'email', 'telefono', 'puesto', 'fecha_ingreso', 'nombre_profesional', 'rol', 'profesional_id'] as $campo) {
            if (isset($post[$campo]) && !is_string($post[$campo]) && !is_int($post[$campo])) { return [null, ['Los datos enviados no tienen el formato esperado.']]; }
        }
        $nombre = $this->texto($post['nombre'] ?? null, 60); if ($nombre === null) { $e[] = 'Indica el nombre (hasta 60 caracteres).'; }
        $apellido = $this->texto($post['apellido'] ?? null, 60); if ($apellido === null) { $e[] = 'Indica el apellido (hasta 60 caracteres).'; }
        $email = is_string($post['email'] ?? null) ? trim($post['email']) : '';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 60) { $e[] = 'Introduce un correo válido de hasta 60 caracteres.'; }
        $telefono = is_string($post['telefono'] ?? null) ? trim($post['telefono']) : '';
        if ($telefono !== '' && !preg_match('/^[0-9]{7,10}$/', $telefono)) { $e[] = 'El teléfono debe tener entre 7 y 10 dígitos.'; }
        $puesto = $this->texto($post['puesto'] ?? '', 80, false); if ($puesto === null) { $e[] = 'El puesto admite hasta 80 caracteres.'; }
        $rol = is_string($post['rol'] ?? null) ? $post['rol'] : '';
        if (!in_array($rol, ColaboradorRepository::ROLES_INVITABLES, true)) { $e[] = 'Selecciona un rol del equipo.'; }
        $fecha = is_string($post['fecha_ingreso'] ?? null) ? trim($post['fecha_ingreso']) : '';
        if ($fecha !== '') {
            $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
            if (!$dt || $dt->format('Y-m-d') !== $fecha) { $e[] = 'La fecha de ingreso no es válida.'; }
        }
        $servicios = [];
        $profesionalId = null;
        $nombreProf = '';
        if ($rol === 'profesional') {
            $heredado = $post['profesional_id'] ?? '';
            if ($heredado !== '' && $heredado !== null) {
                $profesionalId = filter_var($heredado, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($profesionalId === false) { $e[] = 'La ficha profesional seleccionada no es válida.'; $profesionalId = null; }
            } else {
                $nombreProf = $this->texto($post['nombre_profesional'] ?? '', 120, false) ?? '';
                if ($nombreProf === '') { $nombreProf = mb_substr(trim(($nombre ?? '') . ' ' . ($apellido ?? '')), 0, 120, 'UTF-8'); }
                $crudo = $post['servicios'] ?? [];
                if (!is_array($crudo)) { $crudo = []; }
                foreach ($crudo as $sid) {
                    $id = filter_var($sid, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if ($id === false) { $e[] = 'Hay servicios seleccionados que no son válidos.'; break; }
                    $servicios[$id] = $id;
                }
                if (!$servicios) { $e[] = 'Selecciona al menos un servicio que podrá realizar el profesional.'; }
            }
        }
        // Asistente del profesional: servicios (ya leídos arriba para fichas nuevas) y horario semanal.
        $agenda = ['franjas' => [], 'descansos' => [], 'aplicar' => false];
        if ($rol === 'profesional') {
            $ajustar = $profesionalId !== null && ($post['ajustar_agenda'] ?? '') === '1';
            if ($ajustar) {
                // Ficha heredada: solo se reescribe su configuración si el operador lo pide expresamente.
                $crudo = $post['servicios'] ?? [];
                foreach (is_array($crudo) ? $crudo : [] as $sid) {
                    $id = filter_var($sid, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if ($id === false) { $e[] = 'Hay servicios seleccionados que no son válidos.'; break; }
                    $servicios[$id] = $id;
                }
                if (!$servicios) { $e[] = 'Selecciona al menos un servicio para reemplazar los de la ficha existente.'; }
            }
            if ($profesionalId === null || $ajustar) {
                $r = AgendaSemanalValidador::normalizar($post['franjas'] ?? null, $post['descansos'] ?? null);
                array_push($e, ...$r['errores']);
                $agenda = ['franjas' => $r['franjas'], 'descansos' => $r['descansos'], 'aplicar' => $ajustar];
            }
        }
        if ($e) { return [null, $e]; }
        $servicios = array_values($servicios);
        sort($servicios);
        return [[
            'nombre' => $nombre, 'apellido' => $apellido, 'email' => $email, 'telefono' => $telefono === '' ? null : $telefono,
            'puesto' => $puesto, 'rol' => $rol, 'fecha_ingreso' => $fecha === '' ? null : $fecha,
            'servicios' => $servicios, 'nombre_profesional' => $nombreProf, 'profesional_id' => $profesionalId,
            'decision' => is_string($post['decision'] ?? null) ? $post['decision'] : 'crear',
            'token_confirmacion' => is_string($post['token_confirmacion'] ?? null) ? $post['token_confirmacion'] : null,
            'agenda' => $agenda,
        ], []];
    }

    /**
     * Invita a un colaborador (y, si es profesional, crea su ficha operativa) de forma idempotente.
     * El correo se escribe en el outbox dentro de la misma transacción y se envía después de confirmar.
     */
    public function invitar(int $actor, array $post, ?string $clave, ?string $ipHash = null): array
    {
        [$d, $errores] = $this->validarInvitacion($post);
        if ($d === null) { return ['status' => self::INVALID, 'errores' => $errores]; }
        if ($clave === null || !Idempotencia::claveValida($clave)) {
            return ['status' => self::INVALID, 'errores' => ['El formulario venció. Recárgalo antes de enviar.']];
        }
        $db = \Model\ActiveRecord::getDB();
        $actorId = Idempotencia::actorUsuario($actor, 'equipo');
        $contenido = ['email' => Identidad::normalizarEmail($d['email']), 'nombre' => mb_strtolower($d['nombre']), 'apellido' => mb_strtolower($d['apellido']),
            'telefono' => $d['telefono'], 'puesto' => $d['puesto'], 'rol' => $d['rol'], 'fecha' => $d['fecha_ingreso'],
            'servicios' => $d['servicios'], 'ficha' => $d['profesional_id'], 'nombre_prof' => mb_strtolower($d['nombre_profesional']),
            'agenda' => $d['agenda']];
        try {
            $previa = Idempotencia::buscar($db, 'colaborador.invitar', $actorId, $clave, $contenido);
            if ($previa !== null) { return $this->resultadoInvitacion($previa, true); }
            if ($d['rol'] === 'profesional' && $d['profesional_id'] === null && $this->repo->fichaReinvitable($d['email']) === null) {
                // Aviso de posibles homónimos (mismas garantías que el alta de clientes): token HMAC, decisión auditada en la misma transacción.
                $homonimos = new HomonimosProfesionalesService(new ProfesionalHomonimosRepository($db));
                $rolActor = null;
                try { $rolActor = (new AuthorizationService($db))->contexto($actor)['rol'] ?? null; } catch (\Throwable) {}
                $eval = $homonimos->evaluar($actor, $rolActor, $d['nombre_profesional'], $clave, $d['decision'], $d['token_confirmacion'], $ipHash, HomonimosProfesionalesService::AMBITO_ASISTENTE);
                if ($eval['estado'] === HomonimosProfesionalesService::CANCELADO) {
                    return ['status' => self::CANCELADO, 'id_existente' => $eval['ids'][0] ?? null, 'errores' => ['No se creó nada. Si es la misma persona, vincula su ficha existente en «Vincular una ficha existente».']];
                }
                if ($eval['estado'] === HomonimosProfesionalesService::REQUIERE_CONFIRMACION) {
                    // Carrera con el propio doble clic: la primera solicitud pudo confirmarse entre la búsqueda previa y este punto.
                    $previa = Idempotencia::buscar($db, 'colaborador.invitar', $actorId, $clave, $contenido);
                    if ($previa !== null) { return $this->resultadoInvitacion($previa, true); }
                    return ['status' => self::INVALID, 'requiere_homonimo' => true, // INVALID: la invitación NO se creó hasta confirmar (token)
                        'homonimos' => ['coincidencias' => $eval['coincidencias'], 'token' => $eval['token'], 'confirmacion_invalida' => $eval['confirmacion_invalida']],
                        'errores' => [!empty($eval['confirmacion_invalida'])
                            ? 'La confirmación no corresponde a las coincidencias actuales. Revísalas de nuevo antes de continuar.'
                            : 'Ya existe una ficha con un nombre igual o muy parecido a «' . $d['nombre_profesional'] . '». Revisa las coincidencias y confirma si es otra persona.']];
                }
                if ($eval['estado'] === HomonimosProfesionalesService::CONFIRMADA) {
                    $d['homonimo'] = ['servicio' => $homonimos, 'decision' => $eval['decision']];
                }
            }
            $op = Idempotencia::ejecutar($db, 'colaborador.invitar', $actorId, $clave, $contenido, function () use ($actor, $d): array {
                $r = $this->repo->crearInvitacion($actor, $d, (int)($d['profesional_id'] ?? 0));
                return ['recurso_tipo' => 'colaborador', 'recurso_id' => $r['colaborador_id'], 'datos' => $r];
            });
            $resultado = $this->resultadoInvitacion($op, $op['repetida']);
            if (!$op['repetida']) { $resultado['correo'] = $this->enviarTrasConfirmar((int)$op['datos']['correo_id']); }
            return $resultado;
        } catch (ConflictoIdempotenciaException) {
            return ['status' => self::CONFLICT_KEY, 'errores' => ['Esta solicitud ya se registró con otros datos. Recarga el formulario para invitar a otra persona.']];
        } catch (OperacionEnCursoException) {
            return ['status' => self::IN_PROGRESS, 'errores' => ['Tu solicitud anterior sigue procesándose. Espera unos segundos y revisa la lista del equipo.']];
        } catch (DuplicateEntryException) {
            return ['status' => self::CONFLICT, 'errores' => ['Ya existe un colaborador con este correo. Búscalo en la lista del equipo para reenviar su invitación o editar su ficha.']];
        } catch (\DomainException $ex) {
            return ['status' => $ex->getCode() === 422 ? self::INVALID : self::FORBIDDEN, 'errores' => [$ex->getMessage()]];
        } catch (PersistenceException $ex) {
            return ['status' => self::ERROR, 'errores' => ['No se pudo guardar la invitación. Inténtalo de nuevo.']];
        }
    }

    private function resultadoInvitacion(array $op, bool $repetida): array
    {
        $datos = $op['datos'];
        $correo = $repetida ? $this->estadoCorreo((int)($datos['invitacion_id'] ?? 0)) : 'pendiente';
        return ['status' => self::OK, 'repetida' => $repetida, 'colaborador_id' => $op['recurso_id'], 'codigo' => $datos['codigo'] ?? null,
            'profesional_id' => $datos['profesional_id'] ?? null, 'correo' => $correo];
    }

    private function estadoCorreo(int $invitacionId): string
    {
        $c = $this->repo->correo('invitacion:' . $invitacionId);
        return match ($c['estado'] ?? '') { 'enviado' => 'enviado', 'fallido' => 'fallido', default => 'pendiente' };
    }

    private function enviarTrasConfirmar(int $correoId): string
    {
        try { return $this->correos->despachar($correoId) ? 'enviado' : 'pendiente'; }
        catch (\Throwable) { return 'pendiente'; }
    }

    /** Reenvío explícito (otra acción legítima, con límite de frecuencia y auditoría). */
    public function reenviar(int $actor, int $colaboradorId, ?string $clave): array
    {
        if ($clave === null || !Idempotencia::claveValida($clave)) { return ['status' => self::INVALID, 'errores' => ['El formulario venció. Recárgalo antes de enviar.']]; }
        $db = \Model\ActiveRecord::getDB();
        $actorId = Idempotencia::actorUsuario($actor, 'equipo');
        $contenido = ['colaborador' => $colaboradorId];
        try {
            $previa = Idempotencia::buscar($db, 'colaborador.reenviar', $actorId, $clave, $contenido);
            if ($previa !== null) { return ['status' => self::OK, 'repetida' => true, 'correo' => $this->estadoCorreo((int)($previa['datos']['invitacion_id'] ?? 0))]; }
            $op = Idempotencia::ejecutar($db, 'colaborador.reenviar', $actorId, $clave, $contenido, function () use ($actor, $colaboradorId): array {
                $r = $this->repo->reenviarInvitacion($actor, $colaboradorId);
                return ['recurso_tipo' => 'invitacion', 'recurso_id' => $r['invitacion_id'], 'datos' => $r];
            });
            return ['status' => self::OK, 'repetida' => $op['repetida'], 'correo' => $op['repetida'] ? $this->estadoCorreo((int)$op['recurso_id']) : $this->enviarTrasConfirmar((int)$op['datos']['correo_id'])];
        } catch (ConflictoIdempotenciaException) {
            return ['status' => self::CONFLICT_KEY, 'errores' => ['Esta solicitud ya se usó con otros datos. Recarga la página.']];
        } catch (OperacionEnCursoException) {
            return ['status' => self::IN_PROGRESS, 'errores' => ['La solicitud anterior sigue procesándose.']];
        } catch (\DomainException $ex) {
            return ['status' => self::FORBIDDEN, 'errores' => [$ex->getMessage()]];
        } catch (PersistenceException) {
            return ['status' => self::ERROR, 'errores' => ['No se pudo reenviar la invitación.']];
        }
    }

    public function revocar(int $actor, int $colaboradorId): array
    {
        try { $this->repo->revocarInvitacion($actor, $colaboradorId); return ['status' => self::OK]; }
        catch (\DomainException $ex) { return ['status' => self::FORBIDDEN, 'errores' => [$ex->getMessage()]]; }
        catch (PersistenceException) { return ['status' => self::ERROR, 'errores' => ['No se pudo revocar la invitación.']]; }
    }

    public function vistaInvitacion(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) { return ['estado' => 'invalida']; }
        return $this->repo->vistaInvitacion($token);
    }

    /** Acepta la invitación: contraseña propia (cuenta nueva o sin confirmar) o la actual (cuenta confirmada). */
    public function aceptar(string $token, array $post, string $ip): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) { return ['status' => self::INVALID, 'errores' => ['La invitación no es válida, ya se usó o venció.']]; }
        if (($post['acepto'] ?? '') !== '1') { return ['status' => self::INVALID, 'errores' => ['Confirma que aceptas formar parte del equipo.']]; }
        $vista = $this->repo->vistaInvitacion($token);
        if ($vista['estado'] !== 'valida') { return ['status' => self::INVALID, 'errores' => ['La invitación no es válida, ya se usó o venció.'], 'estado' => $vista['estado']]; }
        $nueva = is_string($post['password'] ?? null) ? $post['password'] : '';
        $actual = is_string($post['password_actual'] ?? null) ? $post['password_actual'] : '';
        if ($vista['identidad'] === 'existente_confirmada') {
            if ($actual === '' || strlen($actual) > 72) { return ['status' => self::INVALID, 'errores' => ['Ingresa la contraseña actual de tu cuenta para vincular tu acceso al equipo.']]; }
        } else {
            $confirm = is_string($post['password_confirmacion'] ?? null) ? $post['password_confirmacion'] : '';
            if (strlen($nueva) < 8 || strlen($nueva) > 72 || str_contains($nueva, "\0")) { return ['status' => self::INVALID, 'errores' => ['La contraseña debe tener entre 8 y 72 caracteres.']]; }
            if (!hash_equals($nueva, $confirm)) { return ['status' => self::INVALID, 'errores' => ['Las contraseñas no coinciden.']]; }
        }
        try {
            $db = \Model\ActiveRecord::getDB();
            $adm = RateLimiter::admitirDosVectores($db, 'ip_invitacion', $ip, 'inv_token', substr(hash('sha256', $token), 0, 32), 20, 8);
            if (!$adm['admitido']) { return ['status' => self::RATE_LIMITED, 'errores' => ['Demasiados intentos. Espera unos minutos.'], 'segundos' => max(1, (int)$adm['segundos_restantes'])]; }
            $r = $this->repo->aceptar($token, $nueva, $actual);
            return ['status' => self::OK] + $r;
        } catch (\DomainException $ex) {
            return ['status' => $ex->getCode() === 2 ? self::FORBIDDEN : self::INVALID, 'errores' => [$ex->getCode() === 2 ? 'La contraseña actual no es correcta.' : 'La invitación no es válida, ya se usó o venció.']];
        } catch (DuplicateEntryException) {
            return ['status' => self::CONFLICT, 'errores' => ['Esta cuenta ya está vinculada a otra ficha del equipo. Contacta con la administración.']];
        } catch (PersistenceException) {
            return ['status' => self::ERROR, 'errores' => ['No se pudo completar la aceptación. Inténtalo de nuevo.']];
        }
    }

    /**
     * Cambia rol y estado laboral. Con $clave (formulario web) la confirmación es idempotente: repetir el POST con la
     * misma clave devuelve el resultado original sin volver a aplicar nada (p. ej. doble clic en «Guardar acceso»).
     * Una suspensión o baja de un profesional se rechaza mientras queden citas futuras sin resolver.
     */
    public function cambiarAcceso(int $actor, int $colaboradorId, string $rol, string $estado, ?string $clave = null): array
    {
        try {
            if ($clave === null) {
                $this->repo->cambiarAcceso($actor, $colaboradorId, $rol, $estado);
                return ['status' => self::OK];
            }
            if (!Idempotencia::claveValida($clave)) { return ['status' => self::INVALID, 'errores' => ['El formulario venció. Recárgalo antes de enviar.']]; }
            $db = \Model\ActiveRecord::getDB();
            $actorId = Idempotencia::actorUsuario($actor, 'equipo');
            $contenido = ['colaborador' => $colaboradorId, 'rol' => $rol, 'estado' => $estado];
            $op = Idempotencia::ejecutar($db, 'colaborador.acceso', $actorId, $clave, $contenido, function () use ($actor, $colaboradorId, $rol, $estado): array {
                $this->repo->cambiarAcceso($actor, $colaboradorId, $rol, $estado);
                return ['recurso_tipo' => 'colaborador', 'recurso_id' => $colaboradorId, 'datos' => ['rol' => $rol, 'estado' => $estado]];
            });
            return ['status' => self::OK, 'repetida' => $op['repetida']];
        }
        catch (ConflictoIdempotenciaException) { return ['status' => self::CONFLICT_KEY, 'errores' => ['Esta solicitud ya se usó con otros datos. Recarga la página.']]; }
        catch (OperacionEnCursoException) { return ['status' => self::IN_PROGRESS, 'errores' => ['La solicitud anterior sigue procesándose.']]; }
        catch (\InvalidArgumentException|\DomainException $ex) { return ['status' => self::FORBIDDEN, 'errores' => [$ex->getMessage()]]; }
        catch (PersistenceException) { return ['status' => self::ERROR, 'errores' => ['No se pudo actualizar el acceso.']]; }
    }

    /**
     * Asistente de la ficha: servicios que presta y horario semanal (franjas y descansos) del profesional.
     * Idempotente por clave de formulario; no activa la ficha (una persona invitada sigue sin ser reservable).
     *
     * @param array $post servicios[], franjas[dia][n][inicio|fin], descansos[dia][n][inicio|fin|motivo]
     */
    public function configurarAgenda(int $actor, int $colaboradorId, array $post, ?string $clave): array
    {
        if ($clave === null || !Idempotencia::claveValida($clave)) {
            return ['status' => self::INVALID, 'errores' => ['El formulario venció. Recárgalo antes de enviar.']];
        }
        $errores = [];
        $servicios = [];
        $crudo = $post['servicios'] ?? [];
        if (!is_array($crudo)) { $crudo = []; }
        foreach ($crudo as $sid) {
            $id = filter_var($sid, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) { $errores[] = 'Hay servicios seleccionados que no son válidos.'; break; }
            $servicios[$id] = $id;
        }
        if (!$servicios && !$errores) { $errores[] = 'Selecciona al menos un servicio que prestará el profesional.'; }
        $r = AgendaSemanalValidador::normalizar($post['franjas'] ?? null, $post['descansos'] ?? null);
        array_push($errores, ...$r['errores']);
        if ($errores) { return ['status' => self::INVALID, 'errores' => $errores]; }
        $servicios = array_values($servicios);
        sort($servicios);

        $db = \Model\ActiveRecord::getDB();
        $actorId = Idempotencia::actorUsuario($actor, 'equipo');
        $contenido = ['colaborador' => $colaboradorId, 'servicios' => $servicios, 'franjas' => $r['franjas'], 'descansos' => $r['descansos']];
        try {
            $op = Idempotencia::ejecutar($db, 'colaborador.agenda', $actorId, $clave, $contenido, function () use ($actor, $colaboradorId, $servicios, $r): array {
                $datos = $this->repo->configurarAgendaProfesional($actor, $colaboradorId, $servicios, $r['franjas'], $r['descansos']);
                return ['recurso_tipo' => 'profesional', 'recurso_id' => $datos['profesional_id'], 'datos' => $datos];
            });
            return ['status' => self::OK, 'repetida' => $op['repetida'], 'profesional_id' => $op['recurso_id'], 'estado' => $op['datos']['estado'] ?? null];
        } catch (ConflictoIdempotenciaException) {
            return ['status' => self::CONFLICT_KEY, 'errores' => ['Esta solicitud ya se usó con otros datos. Recarga la página.']];
        } catch (OperacionEnCursoException) {
            return ['status' => self::IN_PROGRESS, 'errores' => ['La solicitud anterior sigue procesándose.']];
        } catch (\DomainException $ex) {
            return ['status' => $ex->getCode() === 422 ? self::INVALID : self::FORBIDDEN, 'errores' => [$ex->getMessage()]];
        } catch (PersistenceException) {
            return ['status' => self::ERROR, 'errores' => ['No se pudo guardar la agenda del profesional. Inténtalo de nuevo.']];
        }
    }

    public function actualizarFicha(int $actor, int $colaboradorId, array $post): array
    {
        $puesto = $this->texto($post['puesto'] ?? '', 80, false);
        $notas = $this->texto($post['notas_rh'] ?? '', 500, false);
        $telefono = is_string($post['telefono'] ?? null) ? trim($post['telefono']) : '';
        $fecha = is_string($post['fecha_ingreso'] ?? null) ? trim($post['fecha_ingreso']) : '';
        $errores = [];
        if ($puesto === null) { $errores[] = 'El puesto admite hasta 80 caracteres.'; }
        if ($notas === null) { $errores[] = 'Las notas admiten hasta 500 caracteres.'; }
        if ($telefono !== '' && !preg_match('/^[0-9]{7,10}$/', $telefono)) { $errores[] = 'El teléfono debe tener entre 7 y 10 dígitos.'; }
        if ($fecha !== '') { $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $fecha); if (!$dt || $dt->format('Y-m-d') !== $fecha) { $errores[] = 'La fecha de ingreso no es válida.'; } }
        if ($errores) { return ['status' => self::INVALID, 'errores' => $errores]; }
        try {
            $this->repo->actualizarFicha($actor, $colaboradorId, ['puesto' => $puesto, 'notas_rh' => $notas === '' ? null : $notas, 'telefono' => $telefono === '' ? null : $telefono, 'fecha_ingreso' => $fecha === '' ? null : $fecha]);
            return ['status' => self::OK];
        } catch (\DomainException $ex) { return ['status' => self::FORBIDDEN, 'errores' => [$ex->getMessage()]]; }
        catch (PersistenceException) { return ['status' => self::ERROR, 'errores' => ['No se pudo guardar la ficha.']]; }
    }
}

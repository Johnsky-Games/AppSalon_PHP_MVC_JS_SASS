<?php
namespace Services;

use Classes\ConflictoIdempotenciaException;
use Classes\Idempotencia;
use Classes\Identidad;
use Classes\OperacionEnCursoException;
use Classes\Secretos;
use Model\ActiveRecord;
use Model\Usuario;
use Repositories\AltaClienteRepository;
use Repositories\DuplicateEntryException;
use Repositories\PersistenceException;
use Repositories\UsuarioRepository;

/**
 * Alta de un cliente por el equipo (recepción, gerencia, propietario) con AVISO de posibles homónimos.
 *
 * Principios (documentados en docs/CRM_CLIENTES.md):
 *  - Nunca se fusiona ni se bloquea automáticamente: dos personas distintas pueden llamarse igual. Lo único que impide un
 *    alta es el correo (índice único ya existente) y la validación de datos.
 *  - Con coincidencias, la cuenta NO se crea hasta que el operador confirma «Es una persona distinta». El servidor RECALCULA
 *    las coincidencias al recibir la confirmación y la acepta solo si el token (HMAC ligado al operador, a la clave del
 *    formulario y al conjunto exacto de ids coincidentes) corresponde: no se puede forjar ni reutilizar con otro conjunto.
 *  - La decisión («continuó» con la cuenta creada, o «canceló») queda en altas_homonimos_decisiones (append-only). La de
 *    «continuó» se escribe en la MISMA transacción del alta; con la clave de operación del formulario el doble clic no crea
 *    dos cuentas, no repite la decisión y no vuelve a mostrar el aviso.
 *  - El aviso muestra lo mínimo: nombre, correo enmascarado, última visita y teléfono parcial.
 *  - La cuenta creada no tiene contraseña utilizable ni correo confirmado, no envía correos y no otorga consentimiento comercial
 *    (el consentimiento solo lo da la propia persona). La persona activa su acceso con «Reenviar confirmación»: el enlace abre el formulario de activación donde ELIGE su contraseña definitiva.
 */
final class AltaClienteService
{
    public const OK = 'ok';
    public const INVALID = 'invalid';
    public const CONFLICT_EMAIL = 'conflict_email';
    public const REQUIERE_CONFIRMACION = 'requiere_confirmacion';
    public const CANCELADO = 'cancelado';
    public const CONFLICT_KEY = 'conflict_key';
    public const IN_PROGRESS = 'in_progress';
    public const ERROR = 'error';

    public const SCOPE = 'cliente.alta_recepcion';
    public const MAX_COINCIDENCIAS = 25;
    public const MOSTRAR = 5;
    private const CRITERIOS_ORDEN = [HomonimosService::NOMBRE_EXACTO => 0, HomonimosService::TELEFONO => 1, HomonimosService::NOMBRE_PARECIDO => 2];

    public function __construct(private UsuarioRepository $usuarios, private AltaClienteRepository $repo, private ?\DateTimeImmutable $ahora = null) {}

    private function hoy(): string
    {
        return ($this->ahora ?? new \DateTimeImmutable('now', new \DateTimeZone(CrmService::TIMEZONE)))->setTimezone(new \DateTimeZone(CrmService::TIMEZONE))->format('Y-m-d');
    }

    /** @return array{0:?array{nombre:string,apellido:string,email:string,telefono:string},1:string[]} */
    public function validar(array $post): array
    {
        $e = [];
        $texto = static fn(string $k): ?string => is_string($post[$k] ?? null) ? trim($post[$k]) : null;
        $nombre = $texto('nombre'); $apellido = $texto('apellido'); $email = $texto('email'); $telefono = $texto('telefono');
        foreach (['nombre' => $nombre, 'apellido' => $apellido] as $k => $v) {
            $v = $v === null ? null : trim((string)preg_replace('/\s+/u', ' ', $v));
            if ($k === 'nombre') { $nombre = $v; } else { $apellido = $v; }
            if ($v === null || $v === '') { $e[] = 'Escribe el ' . $k . ' de la persona.'; }
            elseif (mb_strlen($v) > 60 || preg_match('/[\x00-\x1F\x7F]/u', $v)) { $e[] = 'El ' . $k . ' debe tener entre 1 y 60 caracteres, sin caracteres de control.'; }
        }
        if ($email === null || $email === '') { $e[] = 'Escribe el correo electrónico.'; }
        elseif (strlen($email) > 60 || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $e[] = 'Introduce un correo válido de hasta 60 caracteres.'; }
        if ($telefono === null || !preg_match('/^[0-9]{7,10}$/D', $telefono)) { $e[] = 'El teléfono debe tener entre 7 y 10 dígitos.'; }
        if ($e) { return [null, $e]; }
        return [['nombre' => $nombre, 'apellido' => $apellido, 'email' => Identidad::normalizarEmail($email), 'telefono' => $telefono], []];
    }

    /**
     * Posibles homónimos de una persona, con lo mínimo para decidir.
     * @param array{nombre:string,apellido:string,telefono:string} $p
     * @return array<int,array{id:int,nombre:string,correo:string,telefono:string,ultima_visita:?string,criterios:string[]}> ordenadas por relevancia y luego por id
     */
    public function buscarHomonimos(array $p): array
    {
        $hallados = [];
        foreach ($this->repo->candidatos() as $c) {
            $criterios = HomonimosService::criterios($p, $c);
            if ($criterios) { $hallados[] = ['fila' => $c, 'criterios' => $criterios]; }
        }
        usort($hallados, static function (array $a, array $b): int {
            $ra = min(array_map(static fn(string $k): int => self::CRITERIOS_ORDEN[$k] ?? 9, $a['criterios']));
            $rb = min(array_map(static fn(string $k): int => self::CRITERIOS_ORDEN[$k] ?? 9, $b['criterios']));
            return [$ra, $a['fila']['id']] <=> [$rb, $b['fila']['id']];
        });
        $hallados = array_slice($hallados, 0, self::MAX_COINCIDENCIAS);
        $visitas = $this->repo->ultimasVisitas(array_map(static fn(array $h): int => $h['fila']['id'], $hallados), $this->hoy());
        return array_map(static fn(array $h): array => [
            'id' => $h['fila']['id'], 'nombre' => trim($h['fila']['nombre'] . ' ' . $h['fila']['apellido']),
            'correo' => HomonimosService::enmascararCorreo($h['fila']['email']), 'telefono' => HomonimosService::telefonoParcial($h['fila']['telefono']),
            'ultima_visita' => $visitas[$h['fila']['id']] ?? null, 'criterios' => $h['criterios'],
        ], $hallados);
    }

    /** Token de confirmación: HMAC ligado al operador, a la clave de operación del formulario y al conjunto exacto de ids. */
    public static function tokenConfirmacion(int $actorId, string $clave, array $ids): string
    {
        $ids = array_values(array_unique(array_map('intval', $ids))); sort($ids);
        return hash_hmac('sha256', 'homonimos|' . $actorId . '|' . $clave . '|' . implode(',', $ids), Secretos::clave('homonimos-confirmacion'));
    }

    /**
     * @param string|null $clave clave de operación del formulario (Classes\Idempotencia)
     * @param string|null $ipHash AuditoriaHuella::ip()
     * @return array<string,mixed> status + datos según el caso (id, repetida, coincidencias, token, errores, ...)
     */
    public function registrar(int $actorId, ?string $actorRol, array $post, ?string $clave, ?string $ipHash = null): array
    {
        [$p, $errores] = $this->validar($post);
        if ($p === null) { return ['status' => self::INVALID, 'errores' => $errores]; }
        if ($clave === null || !Idempotencia::claveValida($clave)) {
            return ['status' => self::INVALID, 'errores' => ['El formulario venció. Recárgalo antes de enviar.']];
        }
        $db = ActiveRecord::getDB();
        $actor = Idempotencia::actorUsuario($actorId, 'equipo');
        $contenido = ['email' => $p['email'], 'nombre' => mb_strtolower($p['nombre']), 'apellido' => mb_strtolower($p['apellido']), 'telefono' => $p['telefono']];
        $decision = is_string($post['decision'] ?? null) ? $post['decision'] : 'crear';
        try {
            // Un reintento de un alta ya confirmada devuelve el resultado original: ni crea otra cuenta ni repite el aviso.
            $previa = Idempotencia::buscar($db, self::SCOPE, $actor, $clave, $contenido);
            if ($previa !== null) { return ['status' => self::OK, 'id' => $previa['recurso_id'], 'repetida' => true]; }
            if ($this->usuarios->existsByEmail($p['email'])) { return ['status' => self::CONFLICT_EMAIL, 'errores' => ['Ya existe una cuenta con ese correo. Búscala en Clientes: no se crea una segunda.']]; }

            $coincidencias = $this->buscarHomonimos($p);
            $ids = array_map(static fn(array $c): int => $c['id'], $coincidencias);
            $criterios = [];
            foreach ($coincidencias as $c) { $criterios = array_merge($criterios, $c['criterios']); }
            $criterios = array_values(array_unique($criterios));
            // «Cancelar» jamás crea nada, haya o no coincidencias en este momento.
            if ($decision === 'cancelar' && !$coincidencias) { return ['status' => self::CANCELADO, 'id_existente' => null, 'coincidencias_ids' => []]; }
            $confirma = false; $tokenHash = null;
            if ($coincidencias) {
                $esperado = self::tokenConfirmacion($actorId, $clave, $ids);
                if ($decision === 'cancelar') {
                    $this->repo->registrarDecision(['actor_usuario_id' => $actorId, 'actor_rol' => $actorRol, 'decision' => 'cancelo', 'coincidencias_ids' => $ids, 'criterios' => $criterios,
                        'token_hash' => null, 'usuario_creado_id' => null, 'clave_hash' => hash('sha256', $clave), 'ip_hash' => $ipHash]);
                    return ['status' => self::CANCELADO, 'id_existente' => $ids[0], 'coincidencias_ids' => $ids];
                }
                $enviado = is_string($post['token_confirmacion'] ?? null) ? $post['token_confirmacion'] : '';
                if ($decision === 'confirmar' && $enviado !== '' && hash_equals($esperado, $enviado)) {
                    $confirma = true; $tokenHash = hash('sha256', $enviado);
                } else {
                    // Carrera con el propio doble clic: la primera solicitud pudo confirmarse entre la búsqueda previa y este punto.
                    $previa = Idempotencia::buscar($db, self::SCOPE, $actor, $clave, $contenido);
                    if ($previa !== null) { return ['status' => self::OK, 'id' => $previa['recurso_id'], 'repetida' => true]; }
                    return ['status' => self::REQUIERE_CONFIRMACION, 'coincidencias' => $coincidencias, 'token' => $esperado,
                        'confirmacion_invalida' => $decision === 'confirmar', 'mostrar' => self::MOSTRAR];
                }
            }
            $op = Idempotencia::ejecutar($db, self::SCOPE, $actor, $clave, $contenido, function () use ($p, $confirma, $actorId, $actorRol, $ids, $criterios, $tokenHash, $clave, $ipHash): array {
                $usuario = new Usuario(['nombre' => $p['nombre'], 'apellido' => $p['apellido'], 'email' => $p['email'], 'telefono' => $p['telefono'],
                    // Contraseña aleatoria que nadie conoce (provisional): la persona fija la suya al activar la cuenta desde el enlace de confirmación.
                    'password' => password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT), 'admin' => '0', 'confirmado' => '0']);
                $id = $this->usuarios->createCliente($usuario, 'recepcion');
                if ($confirma) {
                    $this->repo->registrarDecision(['actor_usuario_id' => $actorId, 'actor_rol' => $actorRol, 'decision' => 'continuo', 'coincidencias_ids' => $ids, 'criterios' => $criterios,
                        'token_hash' => $tokenHash, 'usuario_creado_id' => $id, 'clave_hash' => hash('sha256', $clave), 'ip_hash' => $ipHash]);
                }
                return ['recurso_tipo' => 'usuario', 'recurso_id' => $id, 'datos' => ['con_aviso' => $confirma]];
            });
            return ['status' => self::OK, 'id' => $op['recurso_id'], 'repetida' => $op['repetida'], 'con_aviso' => !empty($op['datos']['con_aviso'])];
        } catch (ConflictoIdempotenciaException) {
            return ['status' => self::CONFLICT_KEY, 'errores' => ['Esta solicitud ya se registró con otros datos. Recarga el formulario para crear otro cliente.']];
        } catch (OperacionEnCursoException) {
            return ['status' => self::IN_PROGRESS, 'errores' => ['Tu solicitud anterior sigue procesándose. Espera unos segundos y revisa la lista de clientes.']];
        } catch (DuplicateEntryException) {
            return ['status' => self::CONFLICT_EMAIL, 'errores' => ['Ya existe una cuenta con ese correo. Búscala en Clientes: no se crea una segunda.']];
        } catch (PersistenceException $e) {
            error_log('[AltaClienteService] persistencia: ' . $e->getMessage());
            return ['status' => self::ERROR, 'errores' => ['No se pudo guardar el cliente. Inténtalo de nuevo.']];
        }
    }
}

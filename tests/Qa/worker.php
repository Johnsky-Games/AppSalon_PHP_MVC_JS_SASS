<?php
/**
 * Worker de concurrencia del QA: espera a una marca de tiempo común y ejecuta UNA acción de dominio real.
 * Uso: php tests/Qa/worker.php <accion> '<json args>' <epoch_inicio>
 * Salida: una línea JSON {ok, resultado|error:{clase,codigo,errno,mensaje}, t0, t1}
 */
if (PHP_SAPI !== 'cli') { exit; }
$nombre = (string)(getenv('DB_NAME') ?: '');
if (!preg_match('/^appsalon_qa_[a-z0-9_]*test$/', $nombre)) { fwrite(STDERR, "BD no autorizada\n"); exit(2); }
require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../includes/app.php';

use Classes\Idempotencia;
use Model\ActiveRecord;
use Repositories\ColaboradorRepository;
use Repositories\CorreoSalidaRepository;
use Services\AuthService;
use Services\ColaboradorService;
use Services\CorreoSalidaService;

[, $accion, $json, $inicio] = $argv + [null, '', '{}', '0'];
$a = json_decode($json, true) ?: [];
$db = ActiveRecord::getDB();

$servicioColab = static fn() => new ColaboradorService(new ColaboradorRepository($db), new CorreoSalidaService(new CorreoSalidaRepository($db)));
$espera = static function () use ($inicio): void { while (microtime(true) < (float)$inicio) { usleep(200); } };
$errInfo = static function (\Throwable $e) use ($db): array {
    $p = $e->getPrevious();
    return ['clase' => get_class($e), 'codigo' => $e->getCode(), 'mensaje' => $e->getMessage(), 'previo' => $p ? get_class($p) . ':' . $p->getCode() : null, 'mysql_errno' => $db->errno];
};

try {
    switch ($accion) {
        case 'registrar':
            $correos = [];
            $svc = new AuthService(new Repositories\UsuarioRepository($db), static function ($tipo, $email, $nombre, $tok) use (&$correos) { $correos[] = $email; return true; });
            $espera();
            $t0 = microtime(true);
            $r = $svc->registrar($a['post'], '10.1.' . random_int(1, 250) . '.' . random_int(1, 250), $a['clave'], $a['actor']);
            $res = ['status' => $r['status'], 'repetida' => $r['repetida'] ?? null, 'correos_enviados' => count($correos), 'alertas' => $r['alertas'] ?? []];
            break;
        case 'invitar':
            $svc = $servicioColab();
            $espera();
            $t0 = microtime(true);
            $r = $svc->invitar((int)$a['actor'], $a['post'], $a['clave']);
            $res = array_intersect_key($r, array_flip(['status', 'repetida', 'colaborador_id', 'correo', 'errores']));
            break;
        case 'reenviar':
            $svc = $servicioColab();
            $espera();
            $t0 = microtime(true);
            $res = $svc->reenviar((int)$a['actor'], (int)$a['id'], $a['clave']);
            break;
        case 'revocar':
            $svc = $servicioColab();
            $espera();
            $t0 = microtime(true);
            $res = $svc->revocar((int)$a['actor'], (int)$a['id']);
            break;
        case 'aceptar':
            $svc = $servicioColab();
            $espera();
            $t0 = microtime(true);
            $res = $svc->aceptar($a['token'], $a['post'], $a['ip'] ?? '10.9.9.9');
            break;
        case 'cambiarAcceso':
            $svc = $servicioColab();
            $espera();
            $t0 = microtime(true);
            $res = $svc->cambiarAcceso((int)$a['actor'], (int)$a['id'], $a['rol'], $a['estado']);
            break;
        case 'equipoActualizar':
            // Camino heredado de EquipoRepository::actualizar (usado también por cambiarAcceso)
            $repo = new Repositories\EquipoRepository($db);
            $espera();
            $t0 = microtime(true);
            $repo->actualizar((int)$a['actor'], (int)$a['usuario'], $a['rol'], (bool)$a['activo'], null, $a['estado'] ?? null);
            $res = ['status' => 'ok'];
            break;
        case 'profesionalCrear':
            $svc = new Services\ProfesionalService(new Repositories\ProfesionalRepository($db), new Repositories\ServicioRepository($db));
            $espera();
            $t0 = microtime(true);
            $r = $svc->crear($a['post'], $a['clave'], $a['actor']);
            $res = ['status' => $r['status'], 'id' => $r['id'] ?? null, 'repetida' => $r['repetida'] ?? null];
            break;
        case 'reservarProfesional':
            $svc = new Services\CitaService();
            $espera();
            $t0 = microtime(true);
            $r = $svc->reservarConProfesional((int)$a['usuario'], ['profesionalId' => $a['profesional'], 'fecha' => $a['fecha'], 'hora' => $a['hora'], 'servicios' => (string)$a['servicio']]);
            $res = ['status' => $r['status'], 'codigo' => $r['codigo'] ?? null, 'id' => $r['id'] ?? null];
            break;
        case 'propietarioInicial':
            $repo = new ColaboradorRepository($db);
            $espera();
            $t0 = microtime(true);
            $res = $repo->aprovisionarPropietarioInicial($a['email'], 'Du', 'Eño', $a['password'] ?? null, (bool)($a['regenerar'] ?? false));
            unset($res['token']);
            break;
        case 'idem':
            // Alta genérica con Idempotencia para medir el bloqueo y el resultado.
            $espera();
            $t0 = microtime(true);
            $r = Idempotencia::ejecutar($db, 'qa.generico', 'usuario:1', $a['clave'], ['x' => 1], static function () use ($db, $a) {
                $db->query("INSERT INTO negocio_ajustes_qa_fantasma VALUES (1)"); // no existe: fuerza fallo
                return [];
            });
            $res = $r;
            break;
        default:
            throw new InvalidArgumentException("accion desconocida $accion");
    }
    echo json_encode(['ok' => true, 'resultado' => $res, 't0' => $t0, 't1' => microtime(true)], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $errInfo($e), 't0' => $t0 ?? null, 't1' => microtime(true)], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
}

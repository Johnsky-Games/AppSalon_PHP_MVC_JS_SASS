<?php

/**
 * Worker auxiliar para pruebas de concurrencia real en reservas con profesional y mutaciones de agenda.
 * Abre una conexión MySQL/InnoDB independiente, emite señal READY y espera barrera GO por STDIN
 * para ejecutar la operación simultáneamente con otros procesos.
 */

require_once __DIR__ . '/../../includes/app.php';

use Controllers\APIController;
use Model\ActiveRecord;
use Repositories\CitaRepository;
use Repositories\ProfesionalRepository;
use Repositories\ServicioRepository;
use Services\CitaService;
use Services\ProfesionalService;

$host = getenv('DB_HOST') ?: 'appsalon-test-db';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: 'root';
$name = getenv('DB_NAME') ?: 'appsalon_test';
$port = (int)(getenv('DB_PORT') ?: 3306);

$db = new mysqli($host, $user, $pass, $name, $port);
$db->set_charset('utf8mb4');
ActiveRecord::setDB($db);

$payloadRaw = $argv[1] ?? '{}';
$config = json_decode($payloadRaw, true);
if (!is_array($config)) {
    $config = [];
}

$accion = (string)($config['accion'] ?? 'reservar');
$usuarioId = (int)($config['usuarioId'] ?? 1);

if (session_status() === PHP_SESSION_NONE) {
    session_id(bin2hex(random_bytes(16)));
    session_start();
}

$csrf = bin2hex(random_bytes(32));
$_SESSION['login'] = true;
$_SESSION['id'] = $usuarioId;
$_SESSION['admin'] = (string)($config['admin'] ?? '0');
$_SESSION['csrf_token'] = $csrf;

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ACCEPT'] = 'application/json';

if ($accion === 'reservar') {
    $_POST = [
        'csrf_token' => $csrf,
        'profesionalId' => (string)($config['profesionalId'] ?? ''),
        'fecha' => (string)($config['fecha'] ?? ''),
        'hora' => (string)($config['hora'] ?? ''),
        'servicios' => (string)($config['servicios'] ?? ''),
    ];
    APIController::setCitaService(new CitaService(
        new CitaRepository($db),
        new ServicioRepository($db)
    ));
}

// Emitir señal de preparación completada por STDERR para no enviar cabeceras en STDOUT
fwrite(STDERR, "READY\n");
fflush(STDERR);

// Esperar señal de disparo sincronizado
fgets(STDIN);

$httpCode = 200;
$output = '';
$resultadoDirecto = null;

$errorException = null;

if ($accion === 'reservar') {
    ob_start();
    try {
        APIController::guardar();
        $httpCode = http_response_code() ?: 200;
    } catch (\AppTerminationException $e) {
        $httpCode = $e->getStatusCode();
    } catch (\Classes\AppTerminationException $e) {
        $httpCode = $e->getStatusCode();
    } catch (\Throwable $e) {
        $httpCode = 500;
        $errorException = get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
    } finally {
        $output = (string)ob_get_clean();
    }
    $resultadoDirecto = json_decode($output, true);
} elseif ($accion === 'bloquear_agenda') {
    $profService = new ProfesionalService(
        new ProfesionalRepository($db),
        new ServicioRepository($db)
    );
    $resBloq = $profService->crearBloqueo(
        (int)($config['profesionalId'] ?? 0),
        [
            'fecha_inicio' => (string)($config['fecha_inicio'] ?? $config['fecha'] ?? ''),
            'fecha_fin' => (string)($config['fecha_fin'] ?? $config['fecha'] ?? ''),
            'hora_inicio' => $config['hora_inicio'] ?? null,
            'hora_fin' => $config['hora_fin'] ?? null,
            'motivo' => (string)($config['motivo'] ?? 'Bloqueo concurrente'),
        ]
    );
    $httpCode = $resBloq['status'] === ProfesionalService::STATUS_OK ? 200 : 422;
    $resultadoDirecto = $resBloq;
} elseif ($accion === 'reemplazar_horarios') {
    $profService = new ProfesionalService(
        new ProfesionalRepository($db),
        new ServicioRepository($db)
    );
    $resHor = $profService->guardarHorariosSemanales(
        (int)($config['profesionalId'] ?? 0),
        $config['horarios'] ?? []
    );
    $httpCode = $resHor['status'] === ProfesionalService::STATUS_OK ? 200 : 422;
    $resultadoDirecto = $resHor;
} elseif ($accion === 'inactivar_profesional') {
    $profService = new ProfesionalService(
        new ProfesionalRepository($db),
        new ServicioRepository($db)
    );
    $resInact = $profService->cambiarEstado((int)($config['profesionalId'] ?? 0), 0);
    $httpCode = $resInact['status'] === ProfesionalService::STATUS_OK ? 200 : 422;
    $resultadoDirecto = $resInact;
}

$db->close();

echo json_encode([
    'accion' => $accion,
    'httpCode' => $httpCode,
    'decoded' => $resultadoDirecto,
    'raw' => $output,
    'error_exception' => $errorException,
]);

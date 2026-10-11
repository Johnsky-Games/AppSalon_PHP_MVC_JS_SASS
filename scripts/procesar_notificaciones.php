<?php

declare(strict_types=1);

/**
 * Script CLI para el procesamiento periódico de notificaciones de citas (Fase 6A):
 * 1. Generación y envío idempotente de recordatorios para citas próximas dentro de la ventana configurable (`--horas=24`).
 * 2. Reintento seguro e idempotente de notificaciones en estado `'pendiente'` o `'fallido'` cuyo backoff haya vencido (`--limite=50`).
 *
 * Uso:
 *   php scripts/procesar_notificaciones.php [--recordatorios] [--reintentos] [--horas=24] [--limite=50] [--ahora="YYYY-MM-DD HH:MM:SS"] [--json]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Este script solo puede ejecutarse desde la línea de comandos (CLI).\n";
    exit(1);
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/app.php';

use Model\ActiveRecord;
use Repositories\CitaRepository;
use Repositories\NotificacionCitaRepository;
use Services\NotificacionCitaService;

$opciones = getopt('', [
    'recordatorios',
    'reintentos',
    'horas:',
    'limite:',
    'ahora:',
    'json',
    'simular-envio',
    'help',
]);

if (array_key_exists('simular-envio', $opciones)) {
    \Classes\Email::setTransport(static function () {
        return true;
    });
}

if (isset($opciones['help'])) {
    echo "Uso: php scripts/procesar_notificaciones.php [opciones]\n";
    echo "  --recordatorios             Ejecuta únicamente el envío de recordatorios en ventana.\n";
    echo "  --reintentos                Ejecuta únicamente la cola de reintentos pendientes/fallidos.\n";
    echo "  --horas=<1..720>            Ventana de anticipación en horas para recordatorios (por defecto: NOTIFICACIONES_RECORDATORIO_HORAS o 24).\n";
    echo "  --limite=<1..500>           Máximo de notificaciones a reintentar en esta ejecución (por defecto: 50).\n";
    echo "  --ahora=\"YYYY-MM-DD HH:MM:SS\" Reloj de referencia en America/Guayaquil para pruebas deterministas.\n";
    echo "  --json                      Imprime el resumen en formato JSON.\n";
    exit(0);
}

$soloRecordatorios = array_key_exists('recordatorios', $opciones);
$soloReintentos = array_key_exists('reintentos', $opciones);
$ejecutarRecordatorios = $soloRecordatorios || (!$soloRecordatorios && !$soloReintentos);
$ejecutarReintentos = $soloReintentos || (!$soloRecordatorios && !$soloReintentos);
$formatoJson = array_key_exists('json', $opciones);

$horasOverride = null;
if (isset($opciones['horas'])) {
    $valHoras = filter_var((string)$opciones['horas'], FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 720]
    ]);
    if ($valHoras === false) {
        fwrite(STDERR, "[ERROR] El parámetro --horas debe ser un entero entre 1 y 720.\n");
        exit(1);
    }
    $horasOverride = (int)$valHoras;
}

$limite = 50;
if (isset($opciones['limite'])) {
    $valLimite = filter_var((string)$opciones['limite'], FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 500]
    ]);
    if ($valLimite === false) {
        fwrite(STDERR, "[ERROR] El parámetro --limite debe ser un entero entre 1 y 500.\n");
        exit(1);
    }
    $limite = (int)$valLimite;
}

$tzGuayaquil = new \DateTimeZone(NotificacionCitaService::TIMEZONE);
$ahoraReferencia = null;
if (isset($opciones['ahora'])) {
    $ahoraStr = trim((string)$opciones['ahora']);
    $dt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $ahoraStr, $tzGuayaquil);
    if (!$dt) {
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $ahoraStr, $tzGuayaquil);
    }
    if (!$dt) {
        fwrite(STDERR, "[ERROR] Formato inválido en --ahora. Use 'YYYY-MM-DD HH:MM:SS'.\n");
        exit(1);
    }
    $ahoraReferencia = $dt;
}

$db = ActiveRecord::getDB();
$notifRepo = new NotificacionCitaRepository($db);
$citaRepo = new CitaRepository($db);
$service = new NotificacionCitaService($notifRepo, $citaRepo, $ahoraReferencia);

$resultadoGlobal = [
    'status' => 'ok',
    'zona_horaria' => NotificacionCitaService::TIMEZONE,
    'ejecutado_en' => $service->obtenerAhora()->format('Y-m-d H:i:s'),
    'recordatorios' => null,
    'reintentos' => null,
];

$codigoSalida = 0;

if ($ejecutarRecordatorios) {
    $resRec = $service->procesarRecordatorios($horasOverride);
    $resultadoGlobal['recordatorios'] = $resRec;
    if (($resRec['status'] ?? '') === NotificacionCitaService::STATUS_ERROR) {
        $resultadoGlobal['status'] = 'error';
        $codigoSalida = 1;
    }
}

if ($ejecutarReintentos) {
    $resReint = $service->procesarPendientesYReintentos($limite);
    $resultadoGlobal['reintentos'] = $resReint;
    if (($resReint['status'] ?? '') === NotificacionCitaService::STATUS_ERROR) {
        $resultadoGlobal['status'] = 'error';
        $codigoSalida = 1;
    }
}

if ($formatoJson) {
    echo json_encode($resultadoGlobal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit($codigoSalida);
}

echo "=== Procesador de Notificaciones de Citas (AppSalon) ===\n";
echo "Instante de referencia (" . NotificacionCitaService::TIMEZONE . "): " . $resultadoGlobal['ejecutado_en'] . "\n";

if ($resultadoGlobal['recordatorios'] !== null) {
    $rec = $resultadoGlobal['recordatorios'];
    echo sprintf(
        "[Recordatorios] Ventana: %dh | Evaluadas: %d | En ventana: %d | Enviados: %d | Ya enviados (idempotentes): %d | Fallidos: %d\n",
        (int)($rec['ventana_horas'] ?? 24),
        (int)($rec['evaluadas'] ?? 0),
        (int)($rec['en_ventana'] ?? 0),
        (int)($rec['enviados'] ?? 0),
        (int)($rec['ya_enviados'] ?? 0),
        (int)($rec['fallidos'] ?? 0)
    );
}

if ($resultadoGlobal['reintentos'] !== null) {
    $rei = $resultadoGlobal['reintentos'];
    echo sprintf(
        "[Reintentos]    Procesadas: %d | Enviados: %d | Fallidos: %d | Omitidos: %d\n",
        (int)($rei['procesadas'] ?? 0),
        (int)($rei['enviados'] ?? 0),
        (int)($rei['fallidos'] ?? 0),
        (int)($rei['omitidos'] ?? 0)
    );
}

exit($codigoSalida);

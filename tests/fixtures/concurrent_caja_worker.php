<?php
/**
 * Proceso independiente que ejecuta UNA operación de caja tras una barrera ("READY" → "GO").
 * Uso: php concurrent_caja_worker.php <cobrar|verificar|rechazar|devolver> <actor> <json con los datos>
 * Emite una línea JSON: {ok:true,...resultado} o {ok:false,http,mensaje}.
 */
require __DIR__ . '/../bootstrap.php';

[$script, $accion, $actor, $json] = array_pad($argv, 4, '');
$datos = json_decode($json, true);
if (!in_array($accion, ['cobrar', 'verificar', 'rechazar', 'devolver'], true) || !is_array($datos) || (int)$actor <= 0) { exit(2); }
echo "READY\n";
flush();
if (trim((string)fgets(STDIN)) !== 'GO') { exit(3); }
try {
    $svc = new \Services\CobroCitaService();
    echo json_encode(['ok' => true] + $svc->$accion((int)$actor, $datos)) . "\n";
} catch (\Services\CobroRechazado $e) {
    echo json_encode(['ok' => false, 'http' => $e->http(), 'mensaje' => $e->getMessage()]) . "\n";
} catch (\Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage());
    exit(1);
}

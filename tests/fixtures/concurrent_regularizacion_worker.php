<?php
/**
 * Proceso independiente que ejecuta UNA operación sobre la atención de una cita tras una barrera ("READY" → "GO").
 * Uso: php concurrent_regularizacion_worker.php <regularizar|solicitar|rechazar|ausencia|finalizar> <actor> <json con los datos>
 * Emite una línea JSON: {ok:true,...} o {ok:false,http,mensaje}.
 */
require __DIR__ . '/../bootstrap.php';

[$script, $accion, $actor, $json] = array_pad($argv, 4, '');
$datos = json_decode($json, true);
if (!in_array($accion, ['regularizar', 'solicitar', 'rechazar', 'ausencia', 'finalizar'], true) || !is_array($datos) || (int)$actor <= 0) { exit(2); }
echo "READY\n";
flush();
if (trim((string)fgets(STDIN)) !== 'GO') { exit(3); }
try {
    if (in_array($accion, ['ausencia', 'finalizar'], true)) {
        $r = (new \Services\CitaService())->actualizarAgenda((int)$datos['cita_id'], (int)$actor, true, false, 'estado', ['estado' => $accion === 'ausencia' ? 'no_asistio' : 'completada']);
        echo json_encode($r['resultado'] ? ['ok' => true] + $r : ['ok' => false, 'http' => $r['httpCode'], 'mensaje' => $r['error']]) . "\n";
    } else {
        echo json_encode(['ok' => true] + (new \Services\RegularizacionAtencionService())->$accion((int)$actor, $datos)) . "\n";
    }
} catch (\Services\AtencionRechazada $e) {
    echo json_encode(['ok' => false, 'http' => $e->http(), 'mensaje' => $e->getMessage()]) . "\n";
} catch (\Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage());
    exit(1);
}

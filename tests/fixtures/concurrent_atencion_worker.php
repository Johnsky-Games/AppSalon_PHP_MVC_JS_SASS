<?php
/**
 * Proceso independiente que ejecuta UNA transición de atención de una cita tras una barrera («READY» → «GO»).
 * Uso: php concurrent_atencion_worker.php <citaId> <actorId> <estado> <gestiona 0|1> <propia 0|1> <ahora 'Y-m-d H:i:s' (Guayaquil)> [demoraMs]
 * `demoraMs` retiene el bloqueo de la cita ya leída (CitaRepository::$observadorTrasBloqueoAgenda) para ensanchar la ventana de carrera.
 * Emite una línea JSON con el resultado.
 */
require __DIR__ . '/../bootstrap.php';

[$script, $cita, $actor, $estado, $gestiona, $propia, $ahora] = array_pad($argv, 7, '');
$demoraMs = (int)($argv[7] ?? 0);
if ($cita === '' || $estado === '' || $ahora === '') {
    exit(2);
}
echo "READY\n";
flush();
if (trim((string)fgets(STDIN)) !== 'GO') {
    exit(3);
}
if ($demoraMs > 0) {
    \Repositories\CitaRepository::$observadorTrasBloqueoAgenda = static function () use ($demoraMs): void {
        usleep($demoraMs * 1000);
    };
}
try {
    $reloj = new \DateTimeImmutable($ahora, new \DateTimeZone('America/Guayaquil'));
    $servicio = new \Services\CitaService(null, null, $reloj);
    $r = $servicio->actualizarAgenda((int)$cita, (int)$actor, $gestiona === '1', $propia === '1', 'estado', ['estado' => $estado]);
    echo json_encode([
        'resultado' => (bool)($r['resultado'] ?? false), 'httpCode' => $r['httpCode'] ?? null, 'idempotente' => $r['idempotente'] ?? null,
        'estado' => $r['estado'] ?? null, 'estado_actual' => $r['estado_actual'] ?? null, 'error' => $r['error'] ?? null, 'mensaje' => $r['mensaje'] ?? null,
    ], JSON_UNESCAPED_UNICODE) . "\n";
} catch (\Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage());
    exit(1);
}

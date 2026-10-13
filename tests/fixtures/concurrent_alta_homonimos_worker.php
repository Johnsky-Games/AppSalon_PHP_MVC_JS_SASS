<?php
/**
 * Proceso independiente que ejecuta UN alta de cliente por el equipo (con aviso de homónimos) tras una barrera ("READY" → "GO").
 * Uso: php concurrent_alta_homonimos_worker.php <actorId> <clave> <email> <nombre> <apellido> <telefono> <decision> <token|-> [demoraMs]
 * Emite una línea JSON con el resultado para que la prueba compare procesos reales.
 */
require __DIR__ . '/../bootstrap.php';

[$script, $actor, $clave, $email, $nombre, $apellido, $telefono, $decision, $token] = array_pad($argv, 9, '');
$demoraMs = (int)($argv[9] ?? 0);
if ($actor === '' || $clave === '' || $email === '') {
    exit(2);
}
echo "READY\n";
flush();
if (trim((string)fgets(STDIN)) !== 'GO') {
    exit(3);
}
if ($demoraMs > 0) {
    \Classes\Idempotencia::$observadorTrasReserva = static function () use ($demoraMs): void {
        usleep($demoraMs * 1000);
    };
}
$db = \Model\ActiveRecord::getDB();
try {
    $servicio = new \Services\AltaClienteService(new \Repositories\UsuarioRepository($db), new \Repositories\AltaClienteRepository($db));
    $post = ['nombre' => $nombre, 'apellido' => $apellido, 'email' => $email, 'telefono' => $telefono, 'decision' => $decision];
    if ($token !== '-') { $post['token_confirmacion'] = $token; }
    $r = $servicio->registrar((int)$actor, 'recepcion', $post, $clave, null);
    echo json_encode(['status' => $r['status'], 'id' => $r['id'] ?? null, 'repetida' => $r['repetida'] ?? null, 'ids' => array_column($r['coincidencias'] ?? [], 'id')]) . "\n";
} catch (\Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage());
    exit(1);
}

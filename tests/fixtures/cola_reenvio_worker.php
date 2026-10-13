<?php
/**
 * Proceso independiente que ejecuta UN reenvío de confirmación tras una barrera ("READY" -> "GO").
 * Uso: php cola_reenvio_worker.php <email> <ip>
 * Emite una línea JSON con el estado para que la prueba compare procesos reales (no hilos simulados).
 */
require __DIR__ . '/../bootstrap.php';

[, $email, $ip] = $argv + [null, '', '198.51.100.1'];
if ($email === '') { exit(2); }
echo "READY\n";
flush();
if (trim((string)fgets(STDIN)) !== 'GO') { exit(3); }
try {
    $r = (new \Services\AuthService(new \Repositories\UsuarioRepository(\Model\ActiveRecord::getDB())))->reenviarConfirmacion(['email' => $email], $ip);
    echo json_encode(['status' => $r['status'], 'pid' => getmypid()]) . "\n";
} catch (\Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage());
    echo json_encode(['status' => 'excepcion']) . "\n";
    exit(1);
}

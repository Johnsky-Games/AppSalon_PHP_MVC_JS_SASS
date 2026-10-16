<?php
/**
 * Proceso independiente que ejecuta UNA activación de cuenta (AuthService::activarCuenta) tras una barrera ("READY" -> "GO").
 * Uso: php activacion_worker.php <token> <password> <clave_operacion> <actor> [conIp=0|1] [contacto_json]
 * `contacto_json`: {"nombre":..,"apellido":..,"telefono":..}; por defecto los datos de las cuentas de prueba (Pen / Diente / 0991234567).
 * Emite una línea JSON con el resultado visible, para que la prueba compare procesos reales.
 */
require __DIR__ . '/../bootstrap.php';

[$script, $token, $password, $clave, $actor] = array_pad($argv, 5, '');
$conIp = ($argv[5] ?? '0') === '1';
$contacto = json_decode($argv[6] ?? '', true) ?: ['nombre' => 'Pen', 'apellido' => 'Diente', 'telefono' => '0991234567'];
if ($token === '' || $password === '' || $clave === '' || $actor === '') {
    exit(2);
}
echo "READY\n";
flush();
if (trim((string)fgets(STDIN)) !== 'GO') {
    exit(3);
}
$db = \Model\ActiveRecord::getDB();
try {
    $servicio = new \Services\AuthService(new \Repositories\UsuarioRepository($db));
    $r = $servicio->activarCuenta(['token' => $token, 'password' => $password, 'password2' => $password] + $contacto, $conIp ? '203.0.113.' . random_int(1, 250) : null, $clave, $actor);
    echo json_encode(['status' => $r['status'], 'repetida' => $r['repetida'] ?? null, 'alertas' => $r['alertas']]) . "\n";
} catch (\Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage());
    exit(1);
}

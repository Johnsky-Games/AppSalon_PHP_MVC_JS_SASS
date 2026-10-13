<?php
/**
 * Proceso independiente que ejecuta UN registro público (AuthService::registrar con la cola real de correos) tras una
 * barrera ("READY" -> "GO"). Emite una línea JSON con el resultado visible y los efectos.
 * Uso: php registro_uniforme_worker.php <email> <clave> <actor> [conIp=0|1]
 * El transporte SMTP lo define el entorno (EMAIL_HOST/EMAIL_PORT apuntan al SMTP simulado de la prueba).
 */
require __DIR__ . '/../bootstrap.php';

[$script, $email, $clave, $actor] = array_pad($argv, 4, '');
$conIp = ($argv[4] ?? '0') === '1';
if ($email === '' || $clave === '' || $actor === '') {
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
    $r = $servicio->registrar(
        ['nombre' => 'Conc', 'apellido' => 'Urrente', 'telefono' => '0991234567', 'email' => $email, 'password' => 'ClaveSegura123'],
        $conIp ? '203.0.113.' . random_int(1, 250) : null,
        $clave,
        $actor
    );
    echo json_encode(['status' => $r['status'], 'id' => $r['id'] ?? null, 'repetida' => $r['repetida'] ?? null, 'alertas' => $r['alertas']]) . "\n";
} catch (\Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage());
    exit(1);
}

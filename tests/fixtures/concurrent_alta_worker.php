<?php
/**
 * Proceso independiente que ejecuta UNA alta idempotente tras una barrera ("READY" → "GO").
 * Uso: php concurrent_alta_worker.php <cliente|profesional> <clave> <dato> [demoraMs] [actor] [servicioId]
 * Emite una línea JSON con el resultado para que la prueba compare procesos reales.
 */
require __DIR__ . '/../bootstrap.php';

[$script, $flujo, $clave, $dato] = array_pad($argv, 4, '');
$demoraMs = (int)($argv[4] ?? 0);
$actor = $argv[5] ?? 'anonimo:fixture';
$servicioId = (int)($argv[6] ?? 0);
if ($flujo === '' || $clave === '' || $dato === '') {
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
    if ($flujo === 'cliente') {
        $correos = 0;
        $servicio = new \Services\AuthService(new \Repositories\UsuarioRepository($db), function () use (&$correos): bool {
            $correos++;
            return true;
        });
        $r = $servicio->registrar(['nombre' => 'Conc', 'apellido' => 'Urrente', 'telefono' => '0991234567', 'email' => $dato, 'password' => 'ClaveSegura123'], null, $clave, $actor);
        echo json_encode(['status' => $r['status'], 'id' => $r['id'] ?? null, 'repetida' => $r['repetida'] ?? null, 'correos' => $correos]) . "\n";
    } else {
        $servicio = new \Services\ProfesionalService(new \Repositories\ProfesionalRepository($db), new \Repositories\ServicioRepository($db));
        $r = $servicio->crear(['nombre' => $dato, 'activo' => '1', 'servicios' => [(string)$servicioId]], $clave, $actor);
        echo json_encode(['status' => $r['status'], 'id' => $r['id'] ?? null, 'repetida' => $r['repetida'] ?? null]) . "\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage());
    exit(1);
}

<?php
/**
 * Proceso independiente para pruebas de concurrencia de reasignación de citas (barrera "READY" → "GO").
 * Uso: php concurrent_reasignacion_worker.php '<json>'
 *   modo=reasignar: actor, colab, decisiones{citaId:"reasignar:<id>"|"cancelar"}, motivo, clave, demoraMs
 *   modo=reservar : usuario, prof, fecha, hora, servicios[], esperaMs
 *   modo=baja     : actor, colab, rol, estado, clave
 *   modo=agenda   : actor, colab, post{servicios,franjas,descansos}, clave, demoraMs
 * La demora (demoraMs) se aplica con los bloqueos de profesional ya tomados para forzar el solapamiento real.
 * Emite una línea JSON con el resultado.
 */
require __DIR__ . '/../bootstrap.php';

$cfg = json_decode((string)($argv[1] ?? '{}'), true);
if (!is_array($cfg) || empty($cfg['modo'])) { exit(2); }
echo "READY\n";
flush();
if (trim((string)fgets(STDIN)) !== 'GO') { exit(3); }
$db = \Model\ActiveRecord::getDB();
try {
    if ($cfg['modo'] === 'reasignar') {
        $demora = (int)($cfg['demoraMs'] ?? 0);
        if ($demora > 0) {
            \Repositories\ReasignacionCitasRepository::$observadorTrasBloqueos = static function () use ($demora): void { usleep($demora * 1000); };
        }
        $r = (new \Services\ReasignacionCitasService())->aplicar((int)$cfg['actor'], (int)$cfg['colab'], (array)$cfg['decisiones'], (string)($cfg['motivo'] ?? 'prueba'), (string)$cfg['clave']);
        echo json_encode(['status' => $r['status'], 'codigo' => $r['codigo'] ?? null, 'reasignadas' => $r['reasignadas'] ?? 0, 'errores' => $r['errores'] ?? []]) . "\n";
    } elseif ($cfg['modo'] === 'reservar') {
        if (!empty($cfg['esperaMs'])) { usleep((int)$cfg['esperaMs'] * 1000); } // entra cuando la otra operación ya tiene su bloqueo
        $r = (new \Services\CitaService())->reservarConProfesional((int)$cfg['usuario'], ['profesionalId' => (int)$cfg['prof'], 'fecha' => $cfg['fecha'], 'hora' => $cfg['hora'], 'servicios' => $cfg['servicios']]);
        echo json_encode(['status' => $r['status'], 'codigo' => $r['codigo'] ?? null]) . "\n";
    } elseif ($cfg['modo'] === 'agenda') {
        $svc = new \Services\ColaboradorService(new \Repositories\ColaboradorRepository($db), new \Services\CorreoSalidaService(new \Repositories\CorreoSalidaRepository($db)));
        if (!empty($cfg['demoraMs'])) {
            \Classes\Idempotencia::$observadorTrasReserva = static function () use ($cfg): void { usleep((int)$cfg['demoraMs'] * 1000); };
        }
        $r = $svc->configurarAgenda((int)$cfg['actor'], (int)$cfg['colab'], (array)$cfg['post'], (string)$cfg['clave']);
        echo json_encode(['status' => $r['status'], 'repetida' => $r['repetida'] ?? null]) . "\n";
    } elseif ($cfg['modo'] === 'baja') {
        $svc = new \Services\ColaboradorService(new \Repositories\ColaboradorRepository($db), new \Services\CorreoSalidaService(new \Repositories\CorreoSalidaRepository($db)));
        $r = $svc->cambiarAcceso((int)$cfg['actor'], (int)$cfg['colab'], (string)$cfg['rol'], (string)$cfg['estado'], $cfg['clave'] ?? null);
        echo json_encode(['status' => $r['status'], 'repetida' => $r['repetida'] ?? null]) . "\n";
    }
} catch (\Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage());
    exit(1);
}

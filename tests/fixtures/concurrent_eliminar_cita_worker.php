<?php
/**
 * Proceso independiente: intenta eliminar físicamente UNA cita con CitaRepository::eliminarCitaAtomica.
 * Uso: php concurrent_eliminar_cita_worker.php <cita_id>
 * Emite una línea JSON: {resultado:'eliminada'|'inexistente'|'protegida', motivo?}.
 */
require __DIR__ . '/../bootstrap.php';

$citaId = (int)($argv[1] ?? 0);
if ($citaId <= 0) { exit(2); }
echo "READY\n";
flush();
try {
    $ok = (new \Repositories\CitaRepository(\Model\ActiveRecord::getDB()))->eliminarCitaAtomica($citaId);
    echo json_encode(['resultado' => $ok ? 'eliminada' : 'inexistente']) . "\n";
} catch (\Repositories\CitaProtegidaException $e) {
    echo json_encode(['resultado' => 'protegida', 'motivo' => $e->motivo]) . "\n";
} catch (\Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage());
    exit(1);
}

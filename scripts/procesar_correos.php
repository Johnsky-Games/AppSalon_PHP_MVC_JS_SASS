<?php
/**
 * Procesa la cola de correos transaccionales (invitaciones): reintenta con retroceso exponencial.
 *   php scripts/procesar_correos.php [--limite=20]
 * Pensado para cron o un worker; cada evento se toma con arrendamiento exclusivo, de modo que varios
 * procesos simultáneos no envían el mismo correo dos veces desde esta cola.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/app.php';

use Repositories\CorreoSalidaRepository;
use Services\CorreoSalidaService;

$opciones = getopt('', ['limite::']);
$limite = max(1, min(100, (int)($opciones['limite'] ?? 20)));
$db = Model\ActiveRecord::getDB();
if (!$db instanceof mysqli) { fwrite(STDERR, "Sin conexión a la base de datos.\n"); exit(1); }
$enviados = (new CorreoSalidaService(new CorreoSalidaRepository($db)))->procesarPendientes($limite);
echo "Correos enviados: {$enviados}\n";

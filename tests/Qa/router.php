<?php
// Enrutador del servidor embebido para el QA: sirve estáticos y delega lo demás en public/index.php.
$pub = realpath(__DIR__ . '/../../public');
$ruta = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($ruta !== '/' && is_file($pub . $ruta) && !str_ends_with($ruta, '.php')) { return false; }
chdir($pub);
require $pub . '/index.php';

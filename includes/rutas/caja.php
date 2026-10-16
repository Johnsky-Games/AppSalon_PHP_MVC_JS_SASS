<?php
// Rutas de la ronda 5 — módulo «caja». Archivo propio de un solo agente: evita conflictos al integrar. $router ya existe aquí.
// Todas exigen `finanzas.gestionar` en el servidor (CajaController::exigir); recepción y profesional reciben 403.
$router->get('/caja', [\Controllers\CajaController::class, 'index']);
$router->get('/caja/cita', [\Controllers\CajaController::class, 'cita']);
$router->post('/caja/cita/cobrar', [\Controllers\CajaController::class, 'cobrar']);
$router->post('/caja/cita/devolver', [\Controllers\CajaController::class, 'devolver']);
$router->post('/caja/pago/verificar', [\Controllers\CajaController::class, 'verificar']);
$router->post('/caja/pago/rechazar', [\Controllers\CajaController::class, 'rechazar']);
$router->get('/caja/comprobante', [\Controllers\CajaController::class, 'comprobante']);
$router->get('/caja/exportar', [\Controllers\CajaController::class, 'exportar']);

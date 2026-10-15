<?php
// Rutas de la ronda 5 — módulo «agenda». Archivo propio de un solo agente: evita conflictos al integrar. $router ya existe aquí.
// Detalle de una cita para el equipo (cliente, profesional, servicios con precios históricos, atención, pago y accesos a caja).
$router->get('/agenda/cita', [\Controllers\AgendaController::class, 'detalle']);

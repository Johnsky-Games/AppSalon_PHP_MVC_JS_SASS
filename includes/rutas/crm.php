<?php
// Rutas de la ronda 5 — módulo «crm». Archivo propio de un solo agente: evita conflictos al integrar. $router ya existe aquí.
// Listado que explica cada indicador/segmento del CRM (misma autorización, filtros y universo que /admin/clientes).
$router->get('/admin/clientes/listado', [\Controllers\CrmController::class, 'listado']);

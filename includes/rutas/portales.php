<?php
// Rutas de la ronda 5 — módulo «portales». Archivo propio de un solo agente: evita conflictos al integrar. $router ya existe aquí.

// El enrutador queda disponible para el menú (enlaces de módulos opcionales como /caja solo si su ruta existe).
$GLOBALS['__appsalon_router'] = $router;

// Guardias de portal: las pantallas y API personales del cliente (reserva, sus citas, su bienestar) no se abren con una sesión del equipo.
proteger_rutas_por_portal($router);

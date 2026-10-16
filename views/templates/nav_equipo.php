<?php
/**
 * Navegación del portal del EQUIPO: Citas del día, Agenda, Caja, Servicios, Profesionales, Equipo, Finanzas, Inventario, Reportes,
 * Crecimiento, Clientes y Ajustes, según los permisos del servidor (includes/destinos.php · navegacion_portal). Marca la sección
 * actual con aria-current="page". Se incluye desde views/layout.php; las vistas no deben pintar otra barra de secciones.
 */
$uiGrupos = navegacion_portal('equipo');
$uiEnlaceActivo = navegacion_enlace_activo($uiGrupos, $uiRuta ?? ($_SERVER['REQUEST_URI'] ?? '/'));
$uiNavEtiqueta = 'Secciones del equipo';
$uiNavClase = 'nav-equipo';
include __DIR__ . '/_nav_grupos.php';

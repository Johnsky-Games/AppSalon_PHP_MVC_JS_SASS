<?php
/** Navegación del portal de CLIENTES: reservar, sus citas, su perfil y su bienestar. Nunca incluye enlaces del equipo. */
$uiGrupos = navegacion_portal('cliente');
$uiEnlaceActivo = navegacion_enlace_activo($uiGrupos, $uiRuta ?? ($_SERVER['REQUEST_URI'] ?? '/'));
$uiNavEtiqueta = 'Secciones del cliente';
$uiNavClase = 'nav-cliente';
include __DIR__ . '/_nav_grupos.php';

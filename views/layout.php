<?php
$uiAutenticado = !empty($_SESSION['id']);
$uiAdmin = !empty($_SESSION['admin']);
$uiRuta = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$uiEnlaces = [['/perfil', 'Mi Perfil', 'users']];
if (puede('agenda.gestionar')) $uiEnlaces[] = ['/admin', 'Ver Citas', 'calendar'];
if (puede('agenda.gestionar') || puede('agenda.ver_propia')) $uiEnlaces[] = ['/agenda', 'Agenda', 'calendar'];
if (puede('servicios.gestionar')) {
    $uiEnlaces[] = ['/servicios', 'Servicios', 'sparkles'];
    $uiEnlaces[] = ['/servicios/crear', 'Nuevo Servicio', 'plus'];
}
foreach ([
    ['profesionales.gestionar','/profesionales','Profesionales','users'],
    ['equipo.gestionar','/admin/equipo','Equipo y Permisos','users'],
    ['finanzas.gestionar','/admin/finanzas','Caja y Cobros','chart'],
    ['inventario.gestionar','/admin/inventario','Inventario','check'],
    ['reportes.ver','/admin/reportes','Ver Reportes','chart'],
    ['marketing.gestionar','/admin/crecimiento','Clientes y Fidelidad','sparkles'],
    ['ajustes.gestionar','/admin/ajustes','Ajustes','check'],
] as [$permiso,$href,$texto,$icono]) if (puede($permiso)) $uiEnlaces[] = [$href,$texto,$icono];
if (!$uiAdmin && rol_actual() === 'cliente') {
    $uiEnlaces[] = ['/cita','Reservar Cita','calendar'];
    $uiEnlaces[] = ['/mis-citas','Mis Citas','check'];
    $uiEnlaces[] = ['/mi-bienestar','Mi Bienestar','sparkles'];
}
$uiIconos = [
    'calendar' => '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M16 3v4M8 3v4M3 11h18m-13 4h2m4 0h2"/>',
    'sparkles' => '<path d="m12 3 2.7 6.3L21 12l-6.3 2.7L12 21l-2.7-6.3L3 12l6.3-2.7Z"/>',
    'plus' => '<path d="M12 5v14M5 12h14"/><circle cx="12" cy="12" r="10"/>',
    'users' => '<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3m3-16a3 3 0 0 1 0 6m3 10v-3a6 6 0 0 0-3-5"/>',
    'chart' => '<path d="M4 3v18h17M8 16v-5m5 5V7m5 9V4"/>',
    'check' => '<rect x="3" y="3" width="18" height="18" rx="4"/><path d="m7 12 3 3 7-7"/>',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#123b42">
    <title>App Salón · Tu espacio de bienestar</title>
    <link rel="icon" href="/build/img/avatar-professional.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/build/vendor/sweetalert2.min.css">
    <link rel="stylesheet" href="/build/vendor/toastify.css">
    <link rel="stylesheet" href="/build/css/app.css">
</head>
<body class="<?php echo $uiAutenticado ? 'ui-workspace' : 'ui-auth'; ?>">
    <a class="ui-skip-link" href="#contenido-principal">Saltar al contenido</a>
    <div class="contenedor-app">
        <?php if ($uiAutenticado) { ?>
        <aside class="ui-sidebar" aria-label="Navegación principal">
            <a class="ui-brand" href="<?php echo $uiAdmin ? '/admin' : '/cita'; ?>" aria-label="App Salón, inicio">
                <span class="ui-brand-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 3c0 6-9 6-9 12a9 9 0 0 0 18 0c0-6-9-6-9-12Z"/><path d="M12 10v11m-5-7 5 4 5-4"/></svg></span>
                <span>App Salón<small>El arte de cuidarte</small></span>
            </a>
            <p class="ui-nav-label"><?php echo $uiAdmin ? 'TU ESPACIO DE GESTIÓN' : 'TU MOMENTO, A TU RITMO'; ?></p>
            <nav class="ui-main-nav" aria-label="Secciones">
                <?php foreach ($uiEnlaces as [$uiHref, $uiTexto, $uiIcono]) {
                    $uiActiva = $uiRuta === $uiHref || ($uiHref === '/profesionales' && str_starts_with($uiRuta, '/profesionales/')) || ($uiHref === '/servicios' && $uiRuta === '/servicios/actualizar');
                ?>
                <a href="<?php echo s($uiHref); ?>" class="ui-nav-link<?php echo $uiActiva ? ' is-active' : ''; ?>"<?php echo $uiActiva ? ' aria-current="page"' : ''; ?>>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?php echo $uiIconos[$uiIcono]; ?></svg>
                    <span><?php echo s($uiTexto); ?></span>
                </a>
                <?php } ?>
            </nav>
            <div class="ui-sidebar-note"><span aria-hidden="true">✦</span><strong>Todo empieza por ti.</strong><p><?php echo $uiAdmin ? 'Organiza el día. Cuida cada detalle. Haz que cada visita cuente.' : 'Elige tu servicio favorito y encuentra el momento perfecto.'; ?></p></div>
            <p class="ui-sidebar-footer">Belleza, tiempo y bienestar.</p>
        </aside>
        <?php } else { ?>
        <aside class="imagen ui-auth-hero" aria-label="Bienvenida a App Salón">
            <a class="ui-brand" href="/"><span class="ui-brand-mark" aria-hidden="true">✦</span><span>App Salón<small>El arte de cuidarte</small></span></a>
            <div class="ui-auth-story"><span class="ui-eyebrow">UN MOMENTO SOLO PARA TI</span><h2>Sentirte bien.<br>Verte increíble.</h2><p>Tu próximo cambio empieza aquí. Conecta con profesionales y reserva a tu ritmo.</p><div class="ui-hero-chips"><span>Reserva fácil</span><span>Atención personal</span></div></div>
            <p class="ui-auth-caption">Tu tiempo es valioso. Hazlo tuyo.</p>
        </aside>
        <?php } ?>
        <main class="app" id="contenido-principal" tabindex="-1">
            <?php echo $contenido; ?>
            <footer class="ui-content-footer"><span>App Salón</span><span>Diseñado para cuidar de tu tiempo.</span></footer>
        </main>
    </div>
    <div id="ui-notifications" class="ui-visually-hidden" role="status" aria-live="polite" aria-atomic="true"></div>
    <script src="/build/vendor/sweetalert2.all.min.js"></script>
    <script src="/build/vendor/toastify.js"></script>
    <script src="/build/js/ui.js"></script>
    <?php echo $script ?? ''; ?>
</body>
</html>

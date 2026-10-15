<?php
$uiAutenticado = !empty($_SESSION['id']);
$uiPortal = $uiAutenticado ? portal_sesion() : 'cliente';
$uiNombreComercial = 'App Salón';
$uiContacto = '';
try {
    $uiAjustes = (new \Repositories\CrecimientoRepository())->ajustes();
    $uiNombreComercial = trim((string)($uiAjustes['nombre_comercial'] ?? '')) ?: $uiNombreComercial;
    $uiContacto = trim((string)($uiAjustes['contacto'] ?? ''));
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['ui_empresa'] = ['nombre_comercial' => $uiNombreComercial, 'contacto' => $uiContacto];
    }
} catch (\Throwable $e) { /* La identidad predeterminada permite renderizar ante fallo de consulta. */ }
$uiRuta = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';
$uiRol = $uiAutenticado ? rol_actual() : 'cliente';
// El menú depende del PORTAL de la sesión: nunca mezcla accesos del equipo con los del cliente, aunque la persona tenga ambas membresías.
$uiGestion = $uiAutenticado && $uiPortal === 'equipo' && $uiRol !== 'cliente';
$uiInicio = $uiAutenticado ? inicio_portal() : '/';
$uiAviso = $uiAutenticado && function_exists('consumir_aviso_portal') ? consumir_aviso_portal() : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#123b42">
    <title><?php echo s($uiNombreComercial); ?> · Tu espacio de bienestar</title>
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
            <div class="ui-sidebar-header">
            <a class="ui-brand" href="<?php echo s($uiInicio); ?>" aria-label="<?php echo s($uiNombreComercial); ?>, inicio">
                <span class="ui-brand-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 3c0 6-9 6-9 12a9 9 0 0 0 18 0c0-6-9-6-9-12Z"/><path d="M12 10v11m-5-7 5 4 5-4"/></svg></span>
                <span><?php echo s($uiNombreComercial); ?><small>El arte de cuidarte</small></span>
            </a>
            <button class="ui-nav-toggle" type="button" data-nav-toggle aria-expanded="false" aria-controls="ui-navigation-panel"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg><span data-nav-toggle-label>Menú</span></button>
            </div>
            <div class="ui-sidebar-scroll" id="ui-navigation-panel">
            <p class="ui-nav-label"><?php echo $uiGestion ? 'TU ESPACIO DE GESTIÓN' : 'TU MOMENTO, A TU RITMO'; ?></p>
            <?php include __DIR__ . '/templates/' . ($uiGestion ? 'nav_equipo.php' : 'nav_cliente.php'); ?>
            <div class="ui-sidebar-note"><span aria-hidden="true">✦</span><strong>Todo empieza por ti.</strong><p><?php echo $uiGestion ? 'Organiza el día. Cuida cada detalle. Haz que cada visita cuente.' : 'Elige tu servicio favorito y encuentra el momento perfecto.'; ?></p></div>
            </div>
            <p class="ui-sidebar-footer">Belleza, tiempo y bienestar.</p>
        </aside>
        <?php } else { ?>
        <aside class="imagen ui-auth-hero" aria-label="Bienvenida a App Salón">
            <a class="ui-brand" href="/"><span class="ui-brand-mark" aria-hidden="true">✦</span><span><?php echo s($uiNombreComercial); ?><small>El arte de cuidarte</small></span></a>
            <div class="ui-auth-story"><span class="ui-eyebrow">UN MOMENTO SOLO PARA TI</span><h2>Sentirte bien.<br>Verte increíble.</h2><p>Tu próximo cambio empieza aquí. Conecta con profesionales y reserva a tu ritmo.</p><div class="ui-hero-chips"><span>Reserva fácil</span><span>Atención personal</span></div></div>
            <p class="ui-auth-caption">Tu tiempo es valioso. Hazlo tuyo.</p>
        </aside>
        <?php } ?>
        <main class="app" id="contenido-principal" tabindex="-1">
            <?php if ($uiAviso !== '') { ?><div class="nav-equipo-aviso" role="status"><span aria-hidden="true">ℹ</span><p><?php echo s($uiAviso); ?></p></div><?php } ?>
            <?php echo $contenido; ?>
            <footer class="ui-content-footer"><span><?php echo s($uiNombreComercial); ?></span><span><?php echo s($uiContacto ?: 'Diseñado para cuidar de tu tiempo.'); ?></span></footer>
        </main>
    </div>
    <div id="ui-notifications" class="ui-visually-hidden" role="status" aria-live="polite" aria-atomic="true"></div>
    <script src="/build/vendor/sweetalert2.all.min.js"></script>
    <script src="/build/vendor/toastify.js"></script>
    <script src="/build/js/ui.js"></script>
    <?php echo $script ?? ''; ?>
</body>
</html>

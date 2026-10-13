<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#123b42">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo $errorEscape($errorDetails['title']); ?> · <?php echo $errorEscape($errorCompany['nombre']); ?></title>
    <link rel="icon" href="/build/img/avatar-professional.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/build/css/app.css">
</head>
<body class="ui-error">
    <a class="ui-skip-link" href="#contenido-principal">Saltar al contenido</a>
    <div class="ui-error-shell">
        <header class="ui-error-header">
            <a class="ui-brand" href="<?php echo $errorEscape($errorHome); ?>">
                <span class="ui-brand-mark" aria-hidden="true">✦</span>
                <span><?php echo $errorEscape($errorCompany['nombre']); ?><small>El arte de cuidarte</small></span>
            </a>
            <span class="ui-error-label">TU ESPACIO DE BIENESTAR</span>
        </header>
        <main id="contenido-principal" class="ui-error-main" tabindex="-1">
            <section class="ui-error-card" aria-labelledby="error-title">
                <div class="ui-error-art" aria-hidden="true">
                    <span class="ui-error-orbit"></span>
                    <span class="ui-error-number"><?php echo $errorStatus; ?></span>
                    <span class="ui-error-art-caption">Un nuevo camino<br>para continuar.</span>
                    <span class="ui-error-spark">✦</span>
                </div>
                <div class="ui-error-content">
                    <p class="ui-eyebrow">ESTAMOS AQUÍ PARA AYUDARTE</p>
                    <h1 id="error-title"><?php echo $errorEscape($errorDetails['title']); ?></h1>
                    <p class="ui-error-description"><?php echo $errorEscape($errorDescription); ?></p>
                    <p class="ui-error-hint"><?php echo $errorEscape($errorDetails['hint']); ?></p>
                    <div class="ui-state-actions">
                        <a class="boton" href="<?php echo $errorEscape($errorHome); ?>"><?php echo $errorStatus === 401 ? 'Iniciar sesión' : 'Volver al inicio'; ?></a>
                        <?php if ($errorStatus === 410): ?>
                        <a class="boton ui-button-quiet" href="/olvide">Solicitar un enlace</a>
                        <?php elseif (!empty($_SESSION['login']) && $errorHome !== '/perfil'): ?>
                        <a class="boton ui-button-quiet" href="/perfil">Ir a mi perfil</a>
                        <?php endif; ?>
                    </div>
                    <?php if ($errorReference !== ''): ?>
                    <p class="ui-error-reference">Referencia de ayuda: <span><?php echo $errorEscape($errorReference); ?></span></p>
                    <?php endif; ?>
                </div>
            </section>
        </main>
        <footer class="ui-error-footer">
            <span><?php echo $errorEscape($errorCompany['nombre']); ?></span>
            <span><?php echo $errorEscape($errorCompany['contacto'] ?: 'Tu tiempo, tu bienestar.'); ?></span>
        </footer>
    </div>
</body>
</html>

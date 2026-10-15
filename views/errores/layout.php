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
            <?php include __DIR__ . '/tarjeta.php'; ?>
        </main>
        <footer class="ui-error-footer">
            <span><?php echo $errorEscape($errorCompany['nombre']); ?></span>
            <span><?php echo $errorEscape($errorCompany['contacto'] ?: 'Tu tiempo, tu bienestar.'); ?></span>
        </footer>
    </div>
</body>
</html>

<?php
/** Tarjeta de error compartida por la página independiente (errores/layout.php) y por el layout del portal. Variables: ver ErrorResponse::render(). */
?>
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
            <?php if ($errorVolver !== ''): ?>
            <a class="boton" href="<?php echo $errorEscape($errorVolver); ?>" data-error-volver><?php echo $errorEscape($errorVolverEtiqueta); ?></a>
            <?php endif; ?>
            <?php if ($errorVolver === '' || $errorVolver !== $errorHome): ?>
            <a class="boton<?php echo $errorVolver !== '' ? ' ui-button-quiet' : ''; ?>" href="<?php echo $errorEscape($errorHome); ?>"><?php echo $errorEscape($errorHomeEtiqueta); ?></a>
            <?php endif; ?>
            <?php if ($errorStatus === 410): ?>
            <a class="boton ui-button-quiet" href="/olvide">Solicitar un enlace</a>
            <?php elseif (!empty($_SESSION['login']) && $errorHome !== '/perfil' && $errorStatus !== 401): ?>
            <a class="boton ui-button-quiet" href="/perfil">Ir a mi perfil</a>
            <?php endif; ?>
        </div>
        <?php if ($errorReference !== ''): ?>
        <p class="ui-error-reference">Referencia de ayuda: <span><?php echo $errorEscape($errorReference); ?></span></p>
        <?php endif; ?>
    </div>
</section>

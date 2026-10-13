<p class="ui-eyebrow">CUIDAMOS TU ACCESO</p>
<h1 class="nombre-pagina"><?php echo !empty($error) ? 'Recuperemos tu acceso' : 'Crea una nueva contraseña'; ?></h1>

<?php if (!empty($error)): ?>
<section class="ui-state-card ui-state-card--warning" aria-labelledby="recuperacion-estado-titulo">
    <?php $uiEstadoIcono = 'lock'; include __DIR__ . '/../errores/icono.php'; ?>
    <h2 id="recuperacion-estado-titulo"><?php echo http_response_code() >= 500 ? 'No pudimos validar tu enlace' : 'Necesitas un enlace nuevo'; ?></h2>
    <p><?php echo http_response_code() >= 500 ? 'Ocurrió un inconveniente temporal. Vuelve a abrir el enlace de tu correo en unos minutos.' : 'Este enlace venció, ya fue utilizado o no es válido. Solicita otro y utiliza el correo más reciente.'; ?></p>
    <?php include __DIR__ . '/../templates/alertas.php'; ?>
    <div class="ui-state-actions">
        <a class="boton" href="/olvide">Solicitar un enlace nuevo</a>
        <a class="boton ui-button-quiet" href="/">Iniciar sesión</a>
    </div>
</section>
<?php else: ?>
<p class="descripcion-pagina">Elige una contraseña segura para volver a tu espacio.</p>
<?php include __DIR__ . '/../templates/alertas.php'; ?>
<form method="POST" class="formulario">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
    <div class="campo">
        <label for="password">Nueva contraseña</label>
        <input type="password" autocomplete="new-password" id="password" name="password" placeholder="Escribe tu nueva contraseña" minlength="8" required aria-describedby="recuperacion-password-ayuda">
        <p class="ui-form-help" id="recuperacion-password-ayuda">Usa al menos 8 caracteres.</p>
    </div>

    <input type="submit" class="boton" value="Guardar contraseña">
</form>

<div class="acciones">
    <a href="/">Iniciar sesión</a>
    <a href="/olvide">Solicitar otro enlace</a>
</div>
<?php endif; ?>

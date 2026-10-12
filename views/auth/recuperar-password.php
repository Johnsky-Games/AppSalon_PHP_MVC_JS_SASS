<p class="ui-eyebrow">BIENVENIDO A APPSALÓN</p>
<h1 class="nombre-pagina">Recuperar Password</h1>
<p class="descripcion-pagina">Ingresa tu nueva contraseña a continuación</p>

<?php include __DIR__ . '/../templates/alertas.php'; ?>

<?php if ($error) {
    return;
} ?>

<form method='POST' class="formulario">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
    <div class="campo">
        <label for="password">Contraseña</label>
        <input type="password" autocomplete="new-password" id="password" name="password" placeholder="Tu nuevo password">
    </div>

    <input type="submit" class="boton" value="Confirmar Nuevo Password">
</form>

<div class="acciones">
    <a href="/">¿Ya tienes una cuenta? Iniciar Sesión</a>
    <a href="/crear-cuenta">¿Aún no tienes una cuenta? Crear una</a>
</div>
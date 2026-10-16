<p class="ui-eyebrow">BIENVENIDO A APPSALÓN</p>
<h1 class="nombre-pagina">Recupera tu contraseña</h1>
<p class="descripcion-pagina">Introduce tu email para recuperar tu contraseña</p>
<?php include __DIR__ . '/../templates/alertas.php'; ?>
<?php $uiEquipo = ($_GET['portal'] ?? '') === 'equipo'; ?>
<form action="/olvide" method="POST" class="formulario">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
    <div class="campo">
        <label for="email">Email</label>
        <input type="email" name="email" id="email" placeholder="Tu correo electrónico" autocomplete="email" inputmode="email" required>
    </div>

    <input type="submit" class="boton" value="Enviar Instrucciones">
</form>


<div class="acciones">
<?php if ($uiEquipo) { ?>
    <a href="/equipo/login">Volver al acceso del equipo</a>
<?php } else { ?>
    <a href="/">¿Ya tienes una cuenta? Iniciar Sesión</a>
    <a href="/cliente/registro">¿Aún no tienes una cuenta? Crear una</a>
<?php } ?>
</div>

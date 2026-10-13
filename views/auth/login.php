<p class="ui-eyebrow">ACCESO DE CLIENTES</p>
<h1 class='nombre-pagina'>Qué bueno verte</h1>
<p class="descripcion-pagina">Tu próxima experiencia de cuidado empieza aquí. Inicia sesión para reservar.</p>
<?php @include __DIR__ . '/../templates/alertas.php'; ?>
<form action="/cliente/login" method="POST" class="formulario">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
    <div class="campo">
        <label for="email">Email</label>
        <input type="email" autocomplete="username" id="email" placeholder="Tu Email" name="email" value="<?php echo s($auth->email); ?>">
    </div>

    <div class=" campo">
        <label for="password">Contraseña</label>
        <input type="password" autocomplete="current-password" id="password" placeholder="Tu contraseña" name="password">
    </div>

    <input type="submit" class="boton" value="Iniciar Sesión">
</form>
<div class="acciones">
    <a href="/cliente/registro">¿Aún no tienes una cuenta? Crear una</a>
    <a href="/olvide">¿Olvidaste tu contraseña?</a>
    <a href="/reenviar-confirmacion">¿No confirmaste tu cuenta? Reenviar confirmación</a>
</div>
<div class="acciones ui-portal-switch">
    <a href="/equipo/login"<?php echo !empty($enlaceEquipo) ? ' class="boton ui-button-quiet"' : ''; ?>>¿Eres parte del equipo? Acceso del equipo</a>
</div>

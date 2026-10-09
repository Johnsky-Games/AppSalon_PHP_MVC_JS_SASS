<h1 class="nombre-pagina">Reenviar Confirmación</h1>
<p class="descripcion-pagina">Ingresa tu email para recibir un nuevo enlace de confirmación</p>

<?php include __DIR__ . '/../templates/alertas.php'; ?>

<form action="/reenviar-confirmacion" method="POST" class="formulario">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
    <div class="campo">
        <label for="email">Email</label>
        <input type="email" name="email" id="email" placeholder="Tu Email" required>
    </div>

    <input type="submit" class="boton" value="Reenviar Confirmación">
</form>

<div class="acciones">
    <a href="/">¿Ya tienes una cuenta confirmada? Iniciar Sesión</a>
    <a href="/crear-cuenta">¿Aún no tienes una cuenta? Crear una</a>
</div>

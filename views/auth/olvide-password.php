<h1 class="nombre-pagina">Olvide Mi Password</h1>
<p class="descripcion-pagina">Introduce tu email para recuperar tu password</p>
<?php include_once __DIR__ . '/../templates/alertas.php'; ?>
<form action="/olvide" method="POST" class="formulario">
    <div class="campo">
        <label for="email">Email</label>
        <input type="email" name="email" id="name" placeholder="Tu Email">
    </div>

    <input type="submit" class="boton" value="Enviar Instrucciones">
</form>


<div class="acciones">
    <a href="/">¿Ya tienes una cuenta? Iniciar Sessión</a>
    <a href="/crear-cuenta">¿Aún no tienes una cuenta? Crear una</a>
</div>
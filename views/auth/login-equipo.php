<p class="ui-eyebrow">ACCESO DEL EQUIPO</p>
<h1 class="nombre-pagina">Tu espacio de trabajo</h1>
<p class="descripcion-pagina">Ingresa con la cuenta de tu invitación. Este acceso es solo para colaboradoras y colaboradores del salón.</p>
<?php @include __DIR__ . '/../templates/alertas.php'; ?>
<form action="/equipo/login" method="POST" class="formulario">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
    <div class="campo">
        <label for="email">Email</label>
        <input type="email" autocomplete="username" id="email" placeholder="Tu correo de trabajo" name="email" value="<?php echo s($auth->email ?? ''); ?>">
    </div>
    <div class="campo">
        <label for="password">Contraseña</label>
        <input type="password" autocomplete="current-password" id="password" placeholder="Tu contraseña" name="password">
    </div>
    <input type="submit" class="boton" value="Entrar al equipo">
</form>
<div class="acciones">
    <a href="/olvide?portal=equipo">¿Olvidaste tu contraseña?</a>
    <p class="ui-form-help">¿Aún no aceptaste tu invitación? Abre el enlace del correo que recibiste. El acceso del equipo no tiene registro público.</p>
</div>
<div class="acciones ui-portal-switch">
    <a href="/">¿Eres cliente? Acceso de clientes</a>
</div>

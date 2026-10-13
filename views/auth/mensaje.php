<p class="ui-eyebrow">TU CUENTA, A UN PASO</p>
<h1 class="nombre-pagina">Revisa tu correo</h1>
<p class="descripcion-pagina">Si el correo es válido, te enviamos instrucciones para continuar. Esta pantalla es la misma para todas las solicitudes.</p>

<section class="ui-state-card ui-state-card--success" aria-labelledby="confirmacion-pendiente-titulo">
    <?php $uiEstadoIcono = 'mail'; include __DIR__ . '/../errores/icono.php'; ?>
    <h2 id="confirmacion-pendiente-titulo">Tu próximo momento empieza aquí</h2>
    <ol class="ui-state-steps">
        <li>Busca el mensaje en tu bandeja de entrada.</li>
        <li>Si es tu primera vez, abre el enlace del correo para activar tu cuenta; si ya tenías cuenta, el correo te indica cómo entrar.</li>
        <li>Inicia sesión y elige el servicio que quieres reservar.</li>
    </ol>
    <p>Si no encuentras el mensaje, revisa la carpeta de correo no deseado o solicita otro enlace.</p>
    <div class="ui-state-actions">
        <a class="boton" href="/">Iniciar sesión</a>
        <a class="boton ui-button-quiet" href="/reenviar-confirmacion">Reenviar correo</a>
    </div>
</section>

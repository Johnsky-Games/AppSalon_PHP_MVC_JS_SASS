<?php
$modo = $modo ?? 'invalido';
$uiExito = $modo === 'exito';
$uiFormulario = $modo === 'formulario';
$uiTemporal = $modo === 'error';
?>
<p class="ui-eyebrow">TU ACCESO, CON SEGURIDAD</p>
<h1 class="nombre-pagina"><?php echo $uiExito ? 'Tu cuenta está activada' : ($uiFormulario ? 'Elige tu contraseña definitiva' : ($uiTemporal ? 'Intentemos en un momento' : 'Renueva tu enlace')); ?></h1>
<p class="descripcion-pagina"><?php echo $uiExito ? 'Ya puedes iniciar sesión y reservar tu próximo momento de bienestar.' : ($uiFormulario ? 'Para activar tu cuenta elige ahora tu contraseña. La del registro era provisional y se descarta.' : 'Te ayudamos a continuar con la activación de tu cuenta.'); ?></p>

<?php if ($uiFormulario): ?>
<?php include __DIR__ . '/../templates/alertas.php'; ?>
<form class="formulario" method="post" action="/confirmar-cuenta" data-una-vez>
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
    <input type="hidden" name="idem_key" value="<?php echo s(clave_operacion_formulario()); ?>">
    <input type="hidden" name="token" value="<?php echo s($token ?? ''); ?>">
    <div class="ui-form-aviso" role="note" id="activar-datos-aviso">
        <strong>Revisa tus datos.</strong> Estos datos los escribió quien registró el correo antes de que se verificara. Revísalos y corrígelos.
        Tu correo queda verificado al activar; tu nombre y teléfono no se verifican.
    </div>
    <div class="campo">
        <label for="nombre">Nombre</label>
        <input type="text" id="nombre" name="nombre" autocomplete="given-name" maxlength="60" required aria-describedby="activar-datos-aviso" value="<?php echo s($datos['nombre'] ?? ''); ?>">
    </div>
    <div class="campo">
        <label for="apellido">Apellido</label>
        <input type="text" id="apellido" name="apellido" autocomplete="family-name" maxlength="60" required value="<?php echo s($datos['apellido'] ?? ''); ?>">
    </div>
    <div class="campo">
        <label for="telefono">Teléfono</label>
        <input type="tel" id="telefono" name="telefono" autocomplete="tel" inputmode="numeric" pattern="[0-9]{7,10}" maxlength="10" required value="<?php echo s($datos['telefono'] ?? ''); ?>">
    </div>
    <div class="campo">
        <label for="password">Contraseña definitiva</label>
        <input type="password" autocomplete="new-password" id="password" name="password" placeholder="Escribe tu contraseña" minlength="8" maxlength="72" required aria-describedby="activar-password-ayuda">
        <p class="ui-form-help" id="activar-password-ayuda">Usa al menos 8 caracteres. Reemplaza a la contraseña provisional del registro: quien la escribió ya no podrá entrar.</p>
    </div>
    <div class="campo">
        <label for="password2">Repite la contraseña</label>
        <input type="password" autocomplete="new-password" id="password2" name="password2" placeholder="Repite tu contraseña" minlength="8" maxlength="72" required>
    </div>
    <input type="submit" class="boton" value="Activar mi cuenta">
</form>
<?php else: ?>
<section class="ui-state-card <?php echo $uiExito ? 'ui-state-card--success' : 'ui-state-card--warning'; ?>" aria-labelledby="confirmacion-estado-titulo">
    <?php $uiEstadoIcono = $uiExito ? 'success' : 'clock'; include __DIR__ . '/../errores/icono.php'; ?>
    <h2 id="confirmacion-estado-titulo"><?php echo $uiExito ? 'Bienvenido a tu espacio' : ($uiTemporal ? 'No pudimos completar la activación' : 'Este enlace ya no está disponible'); ?></h2>
    <p><?php echo $uiExito ? 'Tu correo quedó confirmado y tu contraseña definitiva está guardada. Inicia sesión para consultar servicios y encontrar el horario ideal.' : ($uiTemporal ? 'Hay un inconveniente temporal. Abre de nuevo el enlace de tu correo en unos minutos.' : 'El enlace venció, ya se utilizó o no es válido. Si tu cuenta ya está activada, puedes iniciar sesión.'); ?></p>
    <?php include __DIR__ . '/../templates/alertas.php'; ?>
    <div class="ui-state-actions">
        <a href="/" class="boton">Iniciar sesión</a>
        <?php if (!$uiExito): ?>
        <a href="/reenviar-confirmacion" class="boton ui-button-quiet">Solicitar otro enlace</a>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php
$uiConfirmada = ($estadoConfirmacion ?? '') === \Services\AuthService::STATUS_OK;
$uiConfirmacionTemporal = ($estadoConfirmacion ?? '') === \Services\AuthService::STATUS_ERROR;
?>
<p class="ui-eyebrow">TU ACCESO, CON SEGURIDAD</p>
<h1 class="nombre-pagina"><?php echo $uiConfirmada ? 'Tu cuenta está lista' : ($uiConfirmacionTemporal ? 'Intentemos en un momento' : 'Renueva tu enlace'); ?></h1>
<p class="descripcion-pagina"><?php echo $uiConfirmada ? 'Ya puedes reservar tu próximo momento de bienestar.' : 'Te ayudamos a continuar con la confirmación de tu cuenta.'; ?></p>

<section class="ui-state-card <?php echo $uiConfirmada ? 'ui-state-card--success' : 'ui-state-card--warning'; ?>" aria-labelledby="confirmacion-estado-titulo">
    <?php $uiEstadoIcono = $uiConfirmada ? 'success' : 'clock'; include __DIR__ . '/../errores/icono.php'; ?>
    <h2 id="confirmacion-estado-titulo"><?php echo $uiConfirmada ? 'Bienvenido a tu espacio' : ($uiConfirmacionTemporal ? 'No pudimos completar la confirmación' : 'Este enlace ya no está disponible'); ?></h2>
    <p><?php echo $uiConfirmada ? 'Tu dirección de correo fue confirmada. Inicia sesión para consultar servicios y encontrar el horario ideal.' : ($uiConfirmacionTemporal ? 'Hay un inconveniente temporal. Abre de nuevo el enlace de tu correo en unos minutos.' : 'El enlace venció, ya se utilizó o no es válido. Si tu cuenta ya está confirmada, puedes iniciar sesión.'); ?></p>
    <?php include __DIR__ . '/../templates/alertas.php'; ?>
    <div class="ui-state-actions">
        <a href="/" class="boton">Iniciar sesión</a>
        <?php if (!$uiConfirmada): ?>
        <a href="/reenviar-confirmacion" class="boton ui-button-quiet">Solicitar otro enlace</a>
        <?php endif; ?>
    </div>
</section>

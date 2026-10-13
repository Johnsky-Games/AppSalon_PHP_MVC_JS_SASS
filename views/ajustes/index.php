<?php $ajustes=$ajustes??[]; ?>
<header class="page-header"><p class="ui-eyebrow">Configuración</p><h1>Tu negocio, a tu manera</h1><p>Información de contacto y beneficio del programa de fidelidad.</p></header>
<?php if($mensaje):?><p class="alerta exito" role="status"><?=s($mensaje)?></p><?php endif;?>
<?php if($error):?><p class="alerta error" role="alert"><?=s($error)?></p><?php endif;?>
<section class="growth-card"><form method="post" class="formulario">
<input type="hidden" name="csrf_token" value="<?=s(csrf_token())?>">
<div class="campo"><label for="negocio">Nombre comercial</label><input id="negocio" name="nombre_comercial" maxlength="120" required value="<?=s($ajustes['nombre_comercial']??'AppSalon')?>"></div>
<div class="campo"><label for="contacto">Contacto de recepción</label><input id="contacto" name="contacto" maxlength="200" value="<?=s($ajustes['contacto']??'')?>"></div>
<div class="ui-form-grid">
<div class="campo"><label for="email-contacto">Correo de soporte</label><input id="email-contacto" name="email_contacto" type="email" maxlength="254" autocomplete="email" value="<?=s($ajustes['email_contacto']??'')?>"><small class="ui-form-help">Opcional. Se muestra en los correos del negocio.</small></div>
<div class="campo"><label for="telefono-negocio">Teléfono del negocio</label><input id="telefono-negocio" name="telefono" type="tel" maxlength="60" autocomplete="tel" value="<?=s($ajustes['telefono']??'')?>"><small class="ui-form-help">Opcional. Incluye el código de país si corresponde.</small></div>
</div>
<div class="campo"><label for="direccion-negocio">Dirección del negocio</label><input id="direccion-negocio" name="direccion" maxlength="300" autocomplete="street-address" value="<?=s($ajustes['direccion']??'')?>"><small class="ui-form-help">Opcional. Usa la dirección donde atiendes a tus clientes.</small></div>
<div class="campo"><label for="beneficio">Beneficio por 100 puntos</label><input id="beneficio" name="beneficio_fidelidad" maxlength="300" required value="<?=s($ajustes['beneficio_fidelidad']??'Beneficio de cortesía a coordinar con recepción')?>"></div>
<p class="growth-muted">Cada cita completada otorga 10 puntos. El beneficio se entrega en recepción y no modifica automáticamente el valor de tus servicios.</p>
<button class="boton" type="submit">Guardar información</button>
</form></section>

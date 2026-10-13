<?php
$c = $vista['colaborador'] ?? [];
$etiquetaRol = ['gerente' => 'Gerencia', 'recepcion' => 'Recepción', 'profesional' => 'Profesional', 'finanzas' => 'Finanzas', 'propietario' => 'Propietario'][$c['rol'] ?? ''] ?? '';
?>
<p class="ui-eyebrow">INVITACIÓN AL EQUIPO</p>
<?php if (($vista['estado'] ?? '') === 'valida') { ?>
<h1 class="nombre-pagina">Hola, <?php echo s($c['nombre'] ?? ''); ?></h1>
<p class="descripcion-pagina">La administración del salón te invita a unirte como <strong><?php echo s($etiquetaRol); ?></strong><?php echo !empty($c['puesto']) ? ' · ' . s($c['puesto']) : ''; ?>. Hasta que aceptes no tendrás acceso al equipo.</p>
<?php include __DIR__ . '/../templates/alertas.php'; ?>
<form action="/equipo/aceptar?token=<?php echo s(rawurlencode($token)); ?>" method="POST" class="formulario" data-una-vez>
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
    <?php if (($vista['identidad'] ?? '') === 'existente_confirmada') { ?>
    <p class="ui-form-help">Ya existe una cuenta con <?php echo s($c['email_norm'] ?? ''); ?>. Conservamos tu cuenta y tus reservas como cliente; solo se añade tu acceso al equipo.</p>
    <div class="campo">
        <label for="password_actual">Contraseña actual de tu cuenta</label>
        <input type="password" id="password_actual" name="password_actual" autocomplete="current-password" required maxlength="72">
    </div>
    <?php } else { ?>
    <p class="ui-form-help"><?php echo ($vista['identidad'] ?? '') === 'existente_sin_confirmar' ? 'Tu correo ya tenía un registro sin confirmar. Al aceptar quedará verificado con la contraseña que elijas.' : 'Elige la contraseña con la que entrarás al equipo.'; ?></p>
    <div class="campo">
        <label for="password">Nueva contraseña</label>
        <input type="password" id="password" name="password" autocomplete="new-password" required minlength="8" maxlength="72">
    </div>
    <div class="campo">
        <label for="password_confirmacion">Repite la contraseña</label>
        <input type="password" id="password_confirmacion" name="password_confirmacion" autocomplete="new-password" required minlength="8" maxlength="72">
    </div>
    <?php } ?>
    <div class="campo">
        <label for="acepto"><input type="checkbox" id="acepto" name="acepto" value="1" required> Acepto unirme al equipo con el rol de <?php echo s($etiquetaRol); ?>.</label>
    </div>
    <input type="submit" class="boton" value="Aceptar invitación">
</form>
<?php } else {
    $mensajes = [
        'usada' => ['Esta invitación ya fue aceptada', 'Si fuiste tú, ya puedes ingresar al equipo con tu cuenta.'],
        'expirada' => ['La invitación venció', 'Pide a la administración del salón que te la reenvíe.'],
        'revocada' => ['La invitación ya no está disponible', 'La administración la canceló o la reemplazó por una más reciente. Revisa tu correo o consulta con ellos.'],
        'invalida' => ['No reconocemos este enlace', 'Revisa que hayas abierto el enlace completo del correo.'],
    ];
    [$titulo, $detalle] = $mensajes[$vista['estado'] ?? 'invalida'] ?? $mensajes['invalida'];
?>
<h1 class="nombre-pagina"><?php echo s($titulo); ?></h1>
<?php include __DIR__ . '/../templates/alertas.php'; ?>
<section class="ui-state-card ui-state-card--warning"><p><?php echo s($detalle); ?></p>
<div class="ui-state-actions"><a class="boton" href="/equipo/login">Ir al acceso del equipo</a></div></section>
<?php } ?>

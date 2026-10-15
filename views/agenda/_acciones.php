<?php
/**
 * Acciones de atención de una cita (tarjeta de la agenda y detalle). Variables esperadas:
 *   $cita (con ['acciones'] de AgendaAtencionService), $retornoClave ('agenda' | 'agenda_cita'), $retornoParams, $desde, $hasta.
 * Lo que se pinta lo decide el servidor (estado, reloj del negocio y permisos): un botón deshabilitado trae su motivo visible.
 */
$citaId = (int)$cita['id'];
?>
<?php foreach ($cita['acciones']['operar'] as $accion):
    $idMotivo = 'agenda-motivo-' . $citaId . '-' . $accion['clave'];
    $discreta = $accion['clave'] === 'ausencia';
?>
    <?php if ($accion['habilitada']): ?>
    <form method="POST" action="/agenda/estado" class="agenda-accion" data-atencion-form
        <?php if ($accion['confirmar']): ?>data-confirmar-titulo="¿Registrar la ausencia de <?php echo s($cita['cliente']); ?>?" data-confirmar-texto="La cita del <?php echo s($cita['fecha']); ?> a las <?php echo s(substr((string)($cita['hora_inicio'] ?: $cita['hora']), 0, 5)); ?> quedará como «No asistió». Es un registro histórico y no se puede deshacer." data-confirmar-boton="Sí, registrar ausencia"<?php endif; ?>>
        <input type="hidden" name="csrf_token" value="<?php echo s(csrf_token()); ?>">
        <input type="hidden" name="id" value="<?php echo $citaId; ?>">
        <input type="hidden" name="estado" value="<?php echo s($accion['estado']); ?>">
        <?php if ($accion['cobrar']): ?><input type="hidden" name="cobrar" value="1"><?php endif; ?>
        <?php echo campos_retorno($retornoClave, $retornoParams); ?>
        <button class="boton<?php echo $discreta ? ' ui-button-quiet' : ''; ?>" type="submit" data-procesando="<?php echo s($accion['procesando']); ?>"><?php echo s($accion['texto']); ?></button>
    </form>
    <?php else: ?>
    <div class="agenda-accion agenda-accion--bloqueada">
        <button class="boton<?php echo $discreta ? ' ui-button-quiet' : ''; ?>" type="button" disabled aria-describedby="<?php echo s($idMotivo); ?>"><?php echo s($accion['texto']); ?></button>
        <p class="agenda-motivo" id="<?php echo s($idMotivo); ?>"><?php echo s($accion['motivo']); ?></p>
    </div>
    <?php endif; ?>
<?php endforeach; ?>
<?php foreach ($cita['acciones']['finanzas'] as $enlace):
    $href = destino_portal($enlace['destino'], ['id' => $citaId, 'desde' => $desde, 'hasta' => $hasta]);
    if ($href === '') continue; // sin permiso o sin ruta en este portal: no se pinta el enlace
?>
    <a class="boton ui-button-quiet agenda-enlace" href="<?php echo s($href); ?>"><?php echo s($enlace['texto']); ?></a>
<?php endforeach; ?>

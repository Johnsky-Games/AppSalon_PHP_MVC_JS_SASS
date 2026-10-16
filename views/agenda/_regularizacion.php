<?php
/**
 * Regularización de la atención en el detalle de una cita (decisión sobre citas de días anteriores). Variables: $cita (con ['acciones']),
 * $retornoClave, $retornoParams. Lo que se ofrece lo decide el servidor (permiso + cita pendiente de revisión); el servidor lo vuelve a comprobar.
 *  · Propietario / gerente: «Regularizar atención» (motivo + fecha y hora efectivas, nunca futuras) y «Rechazar solicitud».
 *  · Profesional: «Solicitar regularización» de su propia cita; nunca la aprueba.
 */
use Services\AgendaAtencionService;

$citaId = (int)$cita['id'];
$acc = $cita['acciones'];
$zona = new \DateTimeZone(\Classes\ReglaAtencion::ZONA);
$maxEfectiva = (new \DateTimeImmutable('now', $zona))->format('Y-m-d\TH:i');
$minEfectiva = (string)$cita['fecha'] . 'T00:00';
$abierta = null;
foreach (($cita['regularizaciones'] ?? []) as $r) { if ($r['estado'] === 'solicitada') { $abierta = $r; break; } }
$propuestaEfectiva = $abierta && $abierta['efectiva_propuesta_at'] ? str_replace(' ', 'T', substr((string)$abierta['efectiva_propuesta_at'], 0, 16)) : '';
?>
<?php if ($acc['pendiente_revision']): ?>
<p class="agenda-ayuda"><span class="agenda-estado agenda-estado--pendiente_revision">Pendiente de revisión</span>
    La cita era del <?php echo s($cita['fecha']); ?> y nadie la resolvió. No se marca sola como atendida ni como ausencia: se reprograma, se regulariza o se registra la ausencia.</p>
<?php endif; ?>
<?php if ($abierta && !$acc['regularizar']): ?>
<p class="agenda-ayuda">Solicitud de regularización enviada el <?php echo s(AgendaAtencionService::fechaHora($abierta['solicitada_at'])); ?>, pendiente de decisión del propietario o del gerente.</p>
<?php endif; ?>

<?php if ($acc['regularizar']): ?>
<button type="button" class="boton" data-modal-open="agenda-regularizar-<?php echo $citaId; ?>">Regularizar atención</button>
<dialog id="agenda-regularizar-<?php echo $citaId; ?>" class="agenda-regularizar" aria-labelledby="agenda-regularizar-titulo-<?php echo $citaId; ?>">
    <h2 id="agenda-regularizar-titulo-<?php echo $citaId; ?>">Regularizar la atención de la cita #<?php echo $citaId; ?></h2>
    <button type="button" data-modal-close aria-label="Cerrar">Cerrar</button>
    <p class="agenda-ayuda">Úselo solo si el servicio SÍ ocurrió y faltó registrarlo. La fecha de la cita no cambia; se guardan aparte la fecha y hora en que se atendió, y quién y cuándo regulariza. Los pagos y comprobantes llevarán su fecha real.</p>
    <?php if ($abierta): ?><p class="agenda-ayuda">Hay una solicitud de <?php echo s($abierta['solicitada_por_nombre'] ?? 'un profesional'); ?>: «<?php echo s($abierta['motivo_solicitud'] ?? ''); ?>». Esta decisión la resuelve.</p><?php endif; ?>
    <form method="POST" action="/agenda/regularizar" class="formulario" data-una-vez>
        <input type="hidden" name="csrf_token" value="<?php echo s(csrf_token()); ?>">
        <input type="hidden" name="idem_key" value="<?php echo s(\Classes\Idempotencia::nuevaClave()); ?>">
        <input type="hidden" name="id" value="<?php echo $citaId; ?>">
        <?php echo campos_retorno($retornoClave, $retornoParams); ?>
        <label>Fecha y hora en que se atendió <input type="datetime-local" name="efectiva" required min="<?php echo s($minEfectiva); ?>" max="<?php echo s($maxEfectiva); ?>" value="<?php echo s($propuestaEfectiva); ?>"></label>
        <label>Motivo de la regularización <textarea name="motivo" required minlength="<?php echo (int)\Services\RegularizacionAtencionService::MOTIVO_MIN; ?>" maxlength="<?php echo (int)\Services\RegularizacionAtencionService::MOTIVO_MAX; ?>"></textarea></label>
        <button type="submit" class="boton" data-procesando="Regularizando…">Confirmar regularización</button>
    </form>
</dialog>
<?php if ($abierta): ?>
<button type="button" class="boton ui-button-quiet" data-modal-open="agenda-rechazar-<?php echo $citaId; ?>">Rechazar solicitud</button>
<dialog id="agenda-rechazar-<?php echo $citaId; ?>" class="agenda-regularizar" aria-labelledby="agenda-rechazar-titulo-<?php echo $citaId; ?>">
    <h2 id="agenda-rechazar-titulo-<?php echo $citaId; ?>">Rechazar la solicitud de regularización</h2>
    <button type="button" data-modal-close aria-label="Cerrar">Cerrar</button>
    <form method="POST" action="/agenda/regularizar/rechazar" class="formulario" data-una-vez>
        <input type="hidden" name="csrf_token" value="<?php echo s(csrf_token()); ?>">
        <input type="hidden" name="idem_key" value="<?php echo s(\Classes\Idempotencia::nuevaClave()); ?>">
        <input type="hidden" name="regularizacion_id" value="<?php echo (int)$abierta['id']; ?>">
        <?php echo campos_retorno($retornoClave, $retornoParams); ?>
        <label>Motivo del rechazo <textarea name="motivo" required minlength="<?php echo (int)\Services\RegularizacionAtencionService::MOTIVO_MIN; ?>" maxlength="<?php echo (int)\Services\RegularizacionAtencionService::MOTIVO_MAX; ?>"></textarea></label>
        <button type="submit" class="boton" data-procesando="Rechazando…">Rechazar solicitud</button>
    </form>
</dialog>
<?php endif; ?>
<?php endif; ?>

<?php if ($acc['solicitar']): ?>
<button type="button" class="boton" data-modal-open="agenda-solicitar-<?php echo $citaId; ?>">Solicitar regularización</button>
<dialog id="agenda-solicitar-<?php echo $citaId; ?>" class="agenda-regularizar" aria-labelledby="agenda-solicitar-titulo-<?php echo $citaId; ?>">
    <h2 id="agenda-solicitar-titulo-<?php echo $citaId; ?>">Solicitar la regularización de la cita #<?php echo $citaId; ?></h2>
    <button type="button" data-modal-close aria-label="Cerrar">Cerrar</button>
    <p class="agenda-ayuda">Si atendiste esta visita y no se registró, pídelo aquí. El propietario o el gerente decidirán; tú no puedes aprobarla.</p>
    <form method="POST" action="/agenda/regularizar/solicitar" class="formulario" data-una-vez>
        <input type="hidden" name="csrf_token" value="<?php echo s(csrf_token()); ?>">
        <input type="hidden" name="idem_key" value="<?php echo s(\Classes\Idempotencia::nuevaClave()); ?>">
        <input type="hidden" name="id" value="<?php echo $citaId; ?>">
        <?php echo campos_retorno($retornoClave, $retornoParams); ?>
        <label>Fecha y hora en que se atendió <input type="datetime-local" name="efectiva" required min="<?php echo s($minEfectiva); ?>" max="<?php echo s($maxEfectiva); ?>"></label>
        <label>Motivo <textarea name="motivo" required minlength="<?php echo (int)\Services\RegularizacionAtencionService::MOTIVO_MIN; ?>" maxlength="<?php echo (int)\Services\RegularizacionAtencionService::MOTIVO_MAX; ?>"></textarea></label>
        <button type="submit" class="boton" data-procesando="Enviando…">Enviar solicitud</button>
    </form>
</dialog>
<?php endif; ?>

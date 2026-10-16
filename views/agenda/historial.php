<section class="agenda-integral">
<div class="ui-page-heading"><div><p class="ui-eyebrow">Tu bienestar</p><h1>Mis visitas</h1><p>Consulta próximas citas, atenciones anteriores y cancelaciones.</p></div><a href="/cita" class="boton">Reservar una cita</a></div>
<?php include __DIR__ . '/../templates/alertas.php'; ?>
<?php if (!$citas): ?><div class="agenda-vacia">Aún no tienes citas. Elige tu próximo momento de cuidado.</div><?php endif; ?>
<div class="agenda-lista">
<?php foreach ($citas as $cita): ?>
<article class="agenda-tarjeta"><div class="agenda-momento"><time><?php echo s($cita['fecha']); ?></time><strong><?php echo s($cita['hora_inicio'] ?: $cita['hora']); ?></strong><span><?php echo s(str_replace('_', ' ', $cita['estado'])); ?></span></div>
<div class="agenda-detalle"><h2><?php echo s($cita['profesional_nombre'] ?? 'Tu salón'); ?></h2><p><?php echo s(implode(', ', array_column($cita['servicios'], 'nombre'))); ?></p><strong>$<?php echo s($cita['total_formateado']); ?></strong><p>Pago <?php echo s($cita['estado_pago']); ?></p>
<?php if ($cita['motivo_cancelacion']): ?><p><?php echo s($cita['motivo_cancelacion']); ?></p><?php endif; ?>
</div><div class="agenda-acciones">
<?php if ($cita['puede_cancelar']): ?>
<?php if (!$cita['es_historica_sin_profesional']): include __DIR__ . '/reprogramar.php'; endif; ?>
<form method="POST" action="/api/eliminar"><input type="hidden" name="csrf_token" value="<?php echo s(csrf_token()); ?>"><input type="hidden" name="id" value="<?php echo (int)$cita['id']; ?>"><button type="submit" class="boton boton-eliminar">Cancelar visita</button></form>
<?php endif; ?>
</div></article>
<?php endforeach; ?>
</div></section>

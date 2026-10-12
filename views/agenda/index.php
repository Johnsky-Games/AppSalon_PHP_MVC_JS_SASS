<section class="agenda-integral">
    <div class="ui-page-heading"><div><p class="ui-eyebrow">Operación del salón</p><h1>Agenda de atención</h1><p>Gestiona cada visita, desde su reserva hasta su finalización.</p></div></div>
    <?php include __DIR__ . '/../templates/alertas.php'; ?>
    <form class="agenda-filtros formulario" method="GET" action="/agenda">
        <label>Desde <input type="date" name="desde" value="<?php echo s($desde); ?>" required></label>
        <label>Hasta <input type="date" name="hasta" value="<?php echo s($hasta); ?>" required></label>
        <button class="boton" type="submit">Consultar agenda</button>
    </form>
    <p class="agenda-ayuda">Hasta 31 días y 500 citas por consulta. Los profesionales ven exclusivamente sus visitas asignadas.</p>
    <?php if (!$citas): ?><div class="agenda-vacia">No hay citas en este período.</div><?php endif; ?>
    <div class="agenda-lista">
    <?php foreach ($citas as $cita): ?>
        <article class="agenda-tarjeta">
            <div class="agenda-momento"><time><?php echo s($cita['fecha']); ?></time><strong><?php echo s(substr($cita['hora_inicio'] ?: $cita['hora'], 0, 5)); ?></strong><span><?php echo s(str_replace('_', ' ', $cita['estado'])); ?></span></div>
            <div class="agenda-detalle"><h2><?php echo s($cita['cliente']); ?></h2><p><?php echo s($cita['profesional_nombre'] ?? 'Profesional sin asignar'); ?></p><p><?php echo s($cita['servicios'] ?? 'Servicios históricos'); ?></p><small>Cita #<?php echo (int)$cita['id']; ?> · Pago <?php echo s($cita['estado_pago']); ?></small></div>
            <div class="agenda-acciones">
            <?php $acciones = ['confirmada' => ['en_servicio' => 'Iniciar atención', 'no_asistio' => 'Registrar ausencia'], 'en_servicio' => ['completada' => 'Finalizar atención']]; foreach ($acciones[$cita['estado']] ?? [] as $estado => $texto): ?>
                <form method="POST" action="/agenda/estado"><input type="hidden" name="csrf_token" value="<?php echo s(csrf_token()); ?>"><input type="hidden" name="id" value="<?php echo (int)$cita['id']; ?>"><input type="hidden" name="estado" value="<?php echo s($estado); ?>"><button class="boton" type="submit"><?php echo s($texto); ?></button></form>
            <?php endforeach; ?>
            <?php if ($gestiona && $cita['estado'] === 'confirmada'): include __DIR__ . '/reprogramar.php'; endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
    </div>
</section>

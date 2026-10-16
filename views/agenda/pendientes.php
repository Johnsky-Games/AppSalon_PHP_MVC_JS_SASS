<?php
use Services\AgendaAtencionService;
use Classes\ReglaAtencion;

$volverAgenda = destino_portal('agenda') ?: inicio_portal();
?>
<section class="agenda-integral">
    <div class="ui-page-heading">
        <div><p class="ui-eyebrow">Operación del salón</p><h1>Citas pendientes de revisión</h1>
            <p>Citas de días anteriores que nadie resolvió. No se marcan solas como atendidas ni como ausencias: reprograma la visita, regulariza la atención si el servicio ocurrió, o registra la ausencia.</p></div>
        <a class="boton ui-button-quiet" href="<?php echo s($volverAgenda); ?>">Volver a la agenda</a>
    </div>
    <?php include __DIR__ . '/../templates/alertas.php'; ?>
    <div class="ui-visually-hidden" role="status" aria-live="polite" data-atencion-estado></div>
    <?php if (!$citas): ?><div class="agenda-vacia">No hay citas pendientes de revisión.</div>
    <?php else: ?><p class="agenda-conteo"><?php echo count($citas); ?> cita<?php echo count($citas) === 1 ? '' : 's'; ?> pendiente<?php echo count($citas) === 1 ? '' : 's'; ?> de revisión (la más antigua primero).</p><?php endif; ?>
    <div class="agenda-lista">
    <?php foreach ($citas as $cita):
        $citaId = (int)$cita['id'];
        $retornoClave = 'agenda_pendientes'; $retornoParams = [];
        $desde = null; $hasta = null;
        $detalle = destino_portal('agenda_cita', ['id' => $citaId]);
    ?>
        <article class="agenda-tarjeta" id="cita-<?php echo $citaId; ?>" aria-labelledby="cita-titulo-<?php echo $citaId; ?>">
            <div class="agenda-momento"><time datetime="<?php echo s($cita['fecha']); ?>"><?php echo s($cita['fecha']); ?></time><strong><?php echo s(substr((string)($cita['hora_inicio'] ?: $cita['hora']), 0, 5)); ?></strong>
                <span class="agenda-estado agenda-estado--pendiente_revision">Pendiente de revisión</span></div>
            <div class="agenda-detalle"><h2 id="cita-titulo-<?php echo $citaId; ?>"><?php echo s($cita['cliente']); ?></h2><p><?php echo s($cita['profesional_nombre'] ?? 'Profesional sin asignar'); ?><?php echo !empty($cita['servicios']) ? ' · ' . s($cita['servicios']) : ''; ?></p>
                <small>Cita #<?php echo $citaId; ?> · <?php echo s(ReglaAtencion::etiqueta((string)$cita['estado'])); ?></small>
                <?php if (!empty($cita['solicitud_abierta_id'])): ?><small class="agenda-atencion-linea">Solicitud de regularización pendiente de decisión</small><?php endif; ?>
                <?php if ($detalle !== ''): ?><a class="agenda-enlace-detalle" href="<?php echo s($detalle); ?>">Ver detalle y decidir (cita #<?php echo $citaId; ?>)</a><?php endif; ?>
            </div>
            <div class="agenda-acciones">
            <?php include __DIR__ . '/_acciones.php'; ?>
            <?php if ($cita['acciones']['reprogramar']): include __DIR__ . '/reprogramar.php'; endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
    </div>
</section>

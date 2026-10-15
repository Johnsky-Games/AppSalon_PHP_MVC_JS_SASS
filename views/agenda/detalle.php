<?php
use Services\AgendaAtencionService;
use Classes\ReglaAtencion;

$citaId = (int)$cita['id'];
$periodo = ['desde' => $desde, 'hasta' => $hasta];
$volverAgenda = destino_portal('agenda', $periodo) ?: inicio_portal();
$retornoClave = 'agenda_cita'; $retornoParams = ['id' => $citaId] + $periodo;
$hora = substr((string)($cita['hora_inicio'] ?: $cita['hora']), 0, 5);
$horaFin = $cita['hora_fin'] ? substr((string)$cita['hora_fin'], 0, 5) : '';
$resumen = AgendaAtencionService::resumenAtencion($cita);
$verFinanzas = !empty($permisos['finanzas']);
?>
<section class="agenda-integral agenda-detalle-cita">
    <div class="ui-page-heading">
        <div>
            <p class="ui-eyebrow">Atención de la visita</p>
            <h1>Cita #<?php echo $citaId; ?> · <?php echo s($cita['cliente']); ?></h1>
            <p><span class="agenda-estado agenda-estado--<?php echo s($cita['estado']); ?>"><?php echo s(ReglaAtencion::etiqueta($cita['estado'])); ?></span></p>
        </div>
        <a class="boton ui-button-quiet" href="<?php echo s($volverAgenda); ?>">Volver a la agenda</a>
    </div>
    <?php include __DIR__ . '/../templates/alertas.php'; ?>
    <div class="ui-visually-hidden" role="status" aria-live="polite" data-atencion-estado></div>

    <div class="agenda-detalle-rejilla">
        <section class="ui-card" aria-labelledby="det-visita">
            <h2 id="det-visita">La visita</h2>
            <dl class="agenda-datos">
                <div><dt>Cliente</dt><dd><?php echo s($cita['cliente']); ?></dd></div>
                <div><dt>Profesional</dt><dd><?php echo s($cita['profesional_nombre'] ?? 'Sin asignar'); ?></dd></div>
                <div><dt>Fecha</dt><dd><time datetime="<?php echo s($cita['fecha']); ?>"><?php echo s($cita['fecha']); ?></time></dd></div>
                <div><dt>Horario</dt><dd><?php echo s($hora . ($horaFin !== '' ? ' – ' . $horaFin : '')); ?></dd></div>
            </dl>
        </section>

        <section class="ui-card" aria-labelledby="det-servicios">
            <h2 id="det-servicios">Servicios<?php echo $verFinanzas ? ' y precios históricos' : ''; ?></h2>
            <?php if (!$cita['servicios']): ?><p>Esta cita no tiene servicios registrados.</p><?php else: ?>
            <ul class="agenda-servicios">
                <?php foreach ($cita['servicios'] as $servicio): ?>
                <li><span><?php echo s($servicio['nombre'] ?? 'Servicio'); ?><?php echo !empty($servicio['duracion_minutos']) ? ' · ' . (int)$servicio['duracion_minutos'] . ' min' : ''; ?></span><?php if ($verFinanzas): ?><strong><?php echo s(AgendaAtencionService::dinero((int)$servicio['precio_centavos'])); ?></strong><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
            <?php if ($verFinanzas): ?><p class="agenda-total">Total de la visita: <strong><?php echo s(AgendaAtencionService::dinero((int)$cita['total_centavos'])); ?></strong></p><?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="ui-card" aria-labelledby="det-atencion">
            <h2 id="det-atencion">Atención</h2>
            <p>Estado: <strong><?php echo s(ReglaAtencion::etiqueta($cita['estado'])); ?></strong><?php echo $cita['estado'] === 'completada' ? ' · Atendió: ' . s($cita['profesional_nombre'] ?? 'sin profesional asignado') : ''; ?></p>
            <?php if ($resumen): ?><ul class="agenda-atencion-lista"><?php foreach ($resumen as $linea): ?><li><?php echo s($linea); ?></li><?php endforeach; ?></ul>
            <?php else: ?><p class="agenda-ayuda">Todavía no se registra ninguna acción de atención.</p><?php endif; ?>
        </section>

        <?php if ($verFinanzas): ?>
        <section class="ui-card" aria-labelledby="det-pago">
            <h2 id="det-pago">Estado de pago</h2>
            <p><strong><?php echo s(AgendaAtencionService::etiquetaPago((string)($cita['estado_pago'] ?? 'pendiente'))); ?></strong></p>
            <p class="agenda-ayuda">Finalizar la atención no marca la visita como pagada: el cobro se registra por separado en caja.</p>
        </section>
        <?php endif; ?>
    </div>

    <section class="ui-card" aria-labelledby="det-acciones">
        <h2 id="det-acciones">Acciones</h2>
        <div class="agenda-acciones agenda-acciones--detalle">
            <?php include __DIR__ . '/_acciones.php'; ?>
            <?php if ($cita['acciones']['reprogramar']): include __DIR__ . '/reprogramar.php'; endif; ?>
            <?php if (!$cita['acciones']['operar'] && !$cita['acciones']['finanzas'] && !$cita['acciones']['reprogramar']): ?><p class="agenda-ayuda">No hay acciones disponibles para una cita «<?php echo s(mb_strtolower(ReglaAtencion::etiqueta($cita['estado']))); ?>».</p><?php endif; ?>
        </div>
    </section>

    <section class="ui-card" aria-labelledby="det-historial">
        <h2 id="det-historial">Historial de la cita</h2>
        <?php if (!$cita['historial']): ?><p class="agenda-ayuda">Sin movimientos registrados.</p><?php else: ?>
        <ol class="agenda-historial">
            <?php foreach ($cita['historial'] as $evento): ?>
            <li><strong><?php echo s($evento['descripcion']); ?></strong> · <?php echo s($evento['actor'] ?: 'Cuenta eliminada'); ?> · <time><?php echo s(AgendaAtencionService::fechaHora($evento['creado_en'])); ?></time></li>
            <?php endforeach; ?>
        </ol>
        <?php endif; ?>
    </section>
</section>

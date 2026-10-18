<?php
use Services\AgendaAtencionService;
use Classes\ReglaAtencion;

$periodo = ['desde' => $desde, 'hasta' => $hasta, 'estado'=>$estado??'', 'pagina'=>$pagina??1];
$etiquetaEstado = static fn(string $e): string => ReglaAtencion::etiqueta($e);
?>
<section class="agenda-integral">
    <div class="ui-page-heading"><div><p class="ui-eyebrow">Operación del salón</p><h1>Agenda de atención</h1><p>Gestiona cada visita, desde su reserva hasta su finalización.</p></div></div>
    <?php include __DIR__ . '/../templates/alertas.php'; ?>
    <form class="agenda-filtros formulario ui-filtros" method="GET" action="/agenda">
        <?php $periodoContexto="agenda"; include __DIR__."/../templates/_periodo_filtro.php"; ?>
        <div class="campo"><label for="agenda-desde">Desde</label><input id="agenda-desde" type="date" name="desde" value="<?php echo s($desde); ?>" required></div>
        <div class="campo"><label for="agenda-hasta">Hasta</label><input id="agenda-hasta" type="date" name="hasta" value="<?php echo s($hasta); ?>" required></div>
        <div class="campo"><label for="agenda-estado">Estado</label>
            <select id="agenda-estado" name="estado">
                <option value="">Todos los estados</option>
                <?php foreach (AgendaAtencionService::ESTADOS_FILTRO as $e): ?>
                <option value="<?php echo s($e); ?>"<?php echo ($estado ?? null) === $e ? ' selected' : ''; ?>><?php echo s($etiquetaEstado($e)); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="boton" type="submit">Consultar agenda</button>
    </form>
    <?php if (!empty($pendientes)): $enlacePend = destino_portal('agenda_pendientes'); ?>
    <p class="agenda-aviso-pendientes" role="status"><span><strong><?php echo (int)$pendientes; ?></strong> cita<?php echo $pendientes === 1 ? '' : 's'; ?> de días anteriores <?php echo $pendientes === 1 ? 'espera' : 'esperan'; ?> revisión. No se marcan solas como atendidas ni como ausencias.</span>
        <?php if ($enlacePend !== ''): ?><a class="boton ui-button-quiet" href="<?php echo s($enlacePend); ?>">Revisar pendientes</a><?php endif; ?></p>
    <?php endif; ?>
    <p class="agenda-ayuda">Hasta 31 días por consulta, con 25 citas por página. Los profesionales ven exclusivamente sus visitas asignadas.</p>
    <details class="agenda-leyenda">
        <summary>¿Qué significa cada estado?</summary>
        <ul>
            <li><span class="agenda-estado agenda-estado--confirmada">Confirmada</span> Reservada, aún sin iniciar. Se puede iniciar desde <?php echo (int)ReglaAtencion::ADELANTO_INICIO_MINUTOS; ?> minutos antes de la hora y, si nadie la inició, también después (queda registrado quién y cuándo lo hizo).</li>
            <li><span class="agenda-estado agenda-estado--en_servicio">En servicio</span> La atención está en curso.</li>
            <li><span class="agenda-estado agenda-estado--completada">Atendida</span> Atención finalizada. El cobro es independiente.</li>
            <li><span class="agenda-estado agenda-estado--no_asistio">No asistió</span> Ausencia registrada.</li>
            <li><span class="agenda-estado agenda-estado--cancelada">Cancelada</span> La visita se canceló.</li>
        </ul>
    </details>
    <?php if (!$citas): ?><div class="agenda-vacia">No hay citas<?php echo !empty($estado) ? ' en estado «' . s($etiquetaEstado($estado)) . '»' : ''; ?> en este período.</div>
    <?php else: ?><p class="agenda-conteo"><?php echo (int)($totalCitas??count($citas)); ?> cita<?php echo ($totalCitas??count($citas)) === 1 ? '' : 's'; ?> en el período.</p><?php endif; ?>
    <div class="ui-visually-hidden" role="status" aria-live="polite" data-atencion-estado></div>
    <div class="agenda-lista">
    <?php foreach ($citas as $cita):
        $citaId = (int)$cita['id'];
        $retornoClave = 'agenda'; $retornoParams = $periodo;
        $resumen = AgendaAtencionService::resumenAtencion($cita);
        $detalle = destino_portal('agenda_cita', ['id' => $citaId] + $periodo);
    ?>
        <article class="agenda-tarjeta" id="cita-<?php echo $citaId; ?>" aria-labelledby="cita-titulo-<?php echo $citaId; ?>">
            <div class="agenda-momento"><time datetime="<?php echo s($cita['fecha']); ?>"><?php echo s($cita['fecha']); ?></time><strong><?php echo s(substr($cita['hora_inicio'] ?: $cita['hora'], 0, 5)); ?></strong><span class="agenda-estado agenda-estado--<?php echo s($cita['estado']); ?>"><?php echo s($etiquetaEstado($cita['estado'])); ?></span></div>
            <div class="agenda-detalle"><h2 id="cita-titulo-<?php echo $citaId; ?>"><?php echo s($cita['cliente']); ?></h2><p><?php echo s($cita['profesional_nombre'] ?? 'Profesional sin asignar'); ?></p><p><?php echo s($cita['servicios'] ?? 'Servicios históricos'); ?></p>
                <?php if (!empty($cita['acciones']['pendiente_revision'])): ?><small><span class="agenda-estado agenda-estado--pendiente_revision">Pendiente de revisión</span></small><?php endif; ?>
                <small>Cita #<?php echo $citaId; ?><?php echo isset($cita['estado_pago']) ? ' · Pago ' . s(AgendaAtencionService::etiquetaPago((string)$cita['estado_pago'])) : ''; ?></small>
                <?php foreach ($resumen as $linea): ?><small class="agenda-atencion-linea"><?php echo s($linea); ?></small><?php endforeach; ?>
                <?php if ($detalle !== ''): ?><a class="agenda-enlace-detalle" href="<?php echo s($detalle); ?>">Ver detalle de la cita #<?php echo $citaId; ?></a><?php endif; ?>
            </div>
            <div class="agenda-acciones">
            <?php include __DIR__ . '/_acciones.php'; ?>
            <?php if ($cita['acciones']['reprogramar']): include __DIR__ . '/reprogramar.php'; endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
    </div>
<nav class="ops-toolbar" aria-label="Páginas de agenda"><span>Página <?= (int)($pagina??1) ?> de <?= (int)($paginas??1) ?></span>
<?php if(($pagina??1)>1): ?><a href="/agenda?<?= s(http_build_query(array_replace($periodo,['pagina'=>$pagina-1]))) ?>">Anterior</a><?php endif; ?>
<?php if(($pagina??1)<($paginas??1)): ?><a href="/agenda?<?= s(http_build_query(array_replace($periodo,['pagina'=>$pagina+1]))) ?>">Siguiente</a><?php endif; ?></nav>
</section>

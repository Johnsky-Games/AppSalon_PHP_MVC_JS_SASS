<header class="ui-page-header"><p class="ui-eyebrow">Decisiones con información</p><h1 class="nombre-pagina">Reportes Administrativos</h1><p class="descripcion-pagina">Agenda, ocupación y movimientos financieros en una vista clara.</p></header>

<?php
include __DIR__ . '/../templates/barra.php';
$alertas = $alertas ?? [];
include __DIR__ . '/../templates/alertas.php';

$filtros = $filtros ?? [];
$catalogos = $catalogos ?? ['profesionales' => [], 'servicios' => []];
$resumenGlobal = $resumenGlobal ?? [];
$resumenProfesionales = $resumenProfesionales ?? [];
$resumenServicios = $resumenServicios ?? [];
$reservas = $reservas ?? [];
$movimientosFinancieros = $movimientosFinancieros ?? [];

$fechaInicioVal = (string)($filtros['fecha_inicio'] ?? '');
$fechaFinVal = (string)($filtros['fecha_fin'] ?? '');
$criterioFechaVal = (string)($filtros['criterio_fecha'] ?? 'servicio');
$profFiltroVal = (string)($filtros['profesional_id'] ?? '');
$servFiltroVal = (string)($filtros['servicio_id'] ?? '');
$tieneFiltroServicio = !empty($filtros['tiene_filtro_servicio']);

$queryExportar = http_build_query([
    'fecha_inicio' => $fechaInicioVal,
    'fecha_fin' => $fechaFinVal,
    'criterio_fecha' => $criterioFechaVal,
    'profesional_id' => $profFiltroVal,
    'servicio_id' => $servFiltroVal,
]);
?>

<h2 class="ui-section-title">Filtrar Reporte de Reservas, Ocupación y Movimientos Financieros</h2><p class="ui-form-help">Elige un periodo y compara la agenda por fecha de servicio o los ingresos por fecha de cobro.</p>
<div class="busqueda reportes-filtros ui-card">
    <form action="/admin/reportes" method="GET" class="formulario" id="form-filtros-reportes">
        <div class="campo">
            <label for="fecha_inicio">Desde</label>
            <input
                type="date"
                id="fecha_inicio"
                name="fecha_inicio"
                value="<?php echo s($fechaInicioVal); ?>"
                required
            >
        </div>

        <div class="campo">
            <label for="fecha_fin">Hasta</label>
            <input
                type="date"
                id="fecha_fin"
                name="fecha_fin"
                value="<?php echo s($fechaFinVal); ?>"
                required
            >
        </div>

        <div class="campo">
            <label for="criterio_fecha">Criterio de Fecha</label>
            <select id="criterio_fecha" name="criterio_fecha">
                <option value="servicio" <?php echo $criterioFechaVal === 'servicio' ? 'selected' : ''; ?>>
                    Fecha de Servicio (Cita en Agenda)
                </option>
                <option value="cobro" <?php echo $criterioFechaVal === 'cobro' ? 'selected' : ''; ?>>
                    Fecha de Cobro (Ingreso en Pagos)
                </option>
            </select>
        </div>

        <div class="campo">
            <label for="profesional_id">Profesional</label>
            <select id="profesional_id" name="profesional_id">
                <option value="">-- Todos los profesionales --</option>
                <?php foreach ($catalogos['profesionales'] ?? [] as $prof) {
                    $pIdStr = (string)$prof['id'];
                    $etiquetaEstado = ((int)($prof['activo'] ?? 1) === 1) ? '' : ' (Inactivo)';
                    ?>
                    <option value="<?php echo s($pIdStr); ?>" <?php echo $profFiltroVal === $pIdStr ? 'selected' : ''; ?>>
                        <?php echo s((string)$prof['nombre'] . $etiquetaEstado); ?>
                    </option>
                <?php } ?>
                <option value="sin_profesional" <?php echo $profFiltroVal === 'sin_profesional' ? 'selected' : ''; ?>>
                    Sin profesional asignado (citas históricas)
                </option>
            </select>
        </div>

        <div class="campo">
            <label for="servicio_id">Servicio</label>
            <select id="servicio_id" name="servicio_id">
                <option value="">-- Todos los servicios --</option>
                <?php foreach ($catalogos['servicios'] ?? [] as $srv) {
                    $sIdStr = (string)$srv['id'];
                    ?>
                    <option value="<?php echo s($sIdStr); ?>" <?php echo $servFiltroVal === $sIdStr ? 'selected' : ''; ?>>
                        <?php echo s((string)$srv['nombre']); ?>
                    </option>
                <?php } ?>
            </select>
        </div>

        <div class="acciones-reportes">
            <button type="submit" id="btn-filtrar-reportes" class="boton">Aplicar Filtros</button>
            <a
                href="/admin/reportes/exportar?<?php echo s($queryExportar); ?>"
                id="btn-exportar-csv"
                class="boton boton-exportar-csv"
            >Exportar CSV</a>
            <a href="/admin/reportes/exportar?<?php echo s($queryExportar . '&formato=csv&bloque=completo'); ?>" class="boton boton-secundario" data-reporte-descarga="completo-csv" title="Todas las secciones del reporte en un solo CSV (no es una tabla plana)">Reporte completo (CSV, varias secciones)</a>
            <a href="/admin/reportes/exportar?<?php echo s($queryExportar . '&formato=csv&bloque=reservas'); ?>" class="boton boton-secundario" data-reporte-descarga="reservas-csv">Reservas (CSV)</a>
            <a href="/admin/reportes/exportar?<?php echo s($queryExportar . '&formato=xlsx&bloque=reservas'); ?>" class="boton boton-secundario" data-reporte-descarga="reservas-xlsx">Reservas (Excel XLSX)</a>
            <a href="/admin/reportes/exportar?<?php echo s($queryExportar . '&formato=csv&bloque=movimientos'); ?>" class="boton boton-secundario" data-reporte-descarga="movimientos-csv">Movimientos (CSV)</a>
            <a href="/admin/reportes/exportar?<?php echo s($queryExportar . '&formato=xlsx&bloque=movimientos'); ?>" class="boton boton-secundario" data-reporte-descarga="movimientos-xlsx">Movimientos (Excel XLSX)</a>
        </div>
    </form>
</div>

<section id="resumen-global-reportes" class="reportes-seccion">
    <h2>Resumen General del Rango (<?php echo s($fechaInicioVal); ?> a <?php echo s($fechaFinVal); ?> — Filtro: <?php echo $criterioFechaVal === 'cobro' ? 'Fecha de Cobro' : 'Fecha de Servicio'; ?>)</h2>
    <p class="reportes-nota-pagos" id="nota-distincion-pagos">
        <?php echo s((string)($resumenGlobal['nota_pagos'] ?? '')); ?>
    </p>

    <div class="reportes-kpis">
        <article class="reporte-kpi ui-card ui-stat" data-kpi="total-citas">
            <h3>Total Citas Reservadas</h3>
            <p class="kpi-valor" id="kpi-total-citas"><?php echo s((string)($resumenGlobal['total_citas'] ?? 0)); ?></p>
            <p class="kpi-detalle">
                Con profesional: <?php echo s((string)($resumenGlobal['total_citas_con_profesional'] ?? 0)); ?> |
                Históricas: <?php echo s((string)($resumenGlobal['total_citas_historicas_sin_profesional'] ?? 0)); ?>
                <?php if (!empty($resumenGlobal['total_citas_canceladas'])) { ?>
                    | Canceladas: <?php echo s((string)$resumenGlobal['total_citas_canceladas']); ?>
                <?php } ?>
            </p>
        </article>

        <article class="reporte-kpi ui-card ui-stat" data-kpi="ocupacion-global">
            <h3>Ocupación sobre Horas Disponibles</h3>
            <p class="kpi-valor" id="kpi-ocupacion-global">
                <?php echo s(number_format((float)($resumenGlobal['porcentaje_ocupacion_global'] ?? 0), 2, '.', '')); ?>%
            </p>
            <p class="kpi-detalle">
                <?php echo s((string)($resumenGlobal['minutos_ocupados_profesionales'] ?? 0)); ?> min ocupados /
                <?php echo s((string)($resumenGlobal['minutos_disponibles_profesionales'] ?? 0)); ?> min disponibles
                (<?php echo s(number_format((float)($resumenGlobal['horas_ocupadas_profesionales'] ?? 0), 2, '.', '')); ?> h /
                <?php echo s(number_format((float)($resumenGlobal['horas_disponibles_profesionales'] ?? 0), 2, '.', '')); ?> h)
            </p>
        </article>

        <article class="reporte-kpi ui-card ui-stat" data-kpi="importe-reservado">
            <h3>Importe Reservado (Comprometido)</h3>
            <p class="kpi-valor" id="kpi-importe-reservado">
                $<?php echo s(number_format((float)($resumenGlobal['importe_reservado_total'] ?? 0), 2, '.', '')); ?>
            </p>
            <p class="kpi-detalle">
                Calculado con precios históricos capturados al reservar
                <?php if ($tieneFiltroServicio) { ?>
                    (Total bruto citas: $<?php echo s(number_format((float)($resumenGlobal['importe_bruto_citas_filtradas'] ?? 0), 2, '.', '')); ?>)
                <?php } ?>
            </p>
        </article>

        <article class="reporte-kpi ui-card ui-stat" data-kpi="ingreso-pagado">
            <h3>Cobros Confirmados (Recaudado)</h3>
            <p class="kpi-valor" id="kpi-ingreso-pagado">
                $<?php echo s(number_format((float)($resumenGlobal['ingreso_pagado_total'] ?? 0), 2, '.', '')); ?>
            </p>
            <p class="kpi-detalle">
                Pendiente de cobro: $<?php echo s(number_format((float)($resumenGlobal['saldo_pendiente_cobro_total'] ?? 0), 2, '.', '')); ?>
                <?php if (!empty($resumenGlobal['cobros_confirmados_simulados']) && (float)$resumenGlobal['cobros_confirmados_simulados'] > 0) { ?>
                    <br><small>(Simulado/Mock: $<?php echo s(number_format((float)$resumenGlobal['cobros_confirmados_simulados'], 2, '.', '')); ?> | Real: $<?php echo s(number_format((float)($resumenGlobal['cobros_confirmados_reales'] ?? 0), 2, '.', '')); ?>)</small>
                <?php } ?>
            </p>
        </article>

        <article class="reporte-kpi ui-card ui-stat" data-kpi="reembolsos">
            <h3>Reembolsos (Devoluciones)</h3>
            <p class="kpi-valor" id="kpi-reembolsos">
                $<?php echo s(number_format((float)($resumenGlobal['reembolsos_total'] ?? 0), 2, '.', '')); ?>
            </p>
            <p class="kpi-detalle">
                Devoluciones registradas en la tabla de pagos
            </p>
        </article>

        <article class="reporte-kpi ui-card ui-stat" data-kpi="neto-cobrado">
            <h3>Neto Cobrado</h3>
            <p class="kpi-valor" id="kpi-neto-cobrado">
                $<?php echo s(number_format((float)($resumenGlobal['neto_cobrado_total'] ?? 0), 2, '.', '')); ?>
            </p>
            <p class="kpi-detalle">
                Cobros confirmados menos reembolsos
            </p>
        </article>
    </div>
</section>

<?php
$datosFinancierosUi = [
    'Importe reservado' => (float)($resumenGlobal['importe_reservado_total'] ?? 0),
    'Cobros confirmados' => (float)($resumenGlobal['ingreso_pagado_total'] ?? 0),
    'Reembolsos' => (float)($resumenGlobal['reembolsos_total'] ?? 0),
    'Neto cobrado' => (float)($resumenGlobal['neto_cobrado_total'] ?? 0),
];
$escalaFinancieraUi = max(array_map('abs', array_values($datosFinancierosUi)));
$serviciosGraficaUi = $resumenServicios;
usort($serviciosGraficaUi, static function ($a, $b) { return (int)$b['total_reservas'] <=> (int)$a['total_reservas']; });
$serviciosGraficaUi = array_slice($serviciosGraficaUi, 0, 6);
$escalaServiciosUi = empty($serviciosGraficaUi) ? 0 : max(array_map(static function ($fila) { return (int)$fila['total_reservas']; }, $serviciosGraficaUi));
?>
<div class="ui-toolbar"><button type="button" class="boton ui-button-quiet" data-chart-switch="graficas-periodo" aria-controls="graficas-periodo" aria-expanded="true">Mostrar u ocultar gráficas <span aria-hidden="true">↕</span></button></div>
<section id="graficas-periodo" class="ui-grid ui-report-charts" aria-label="Gráficas del periodo seleccionado">
    <figure class="ui-card ui-chart">
        <figcaption><h2 class="ui-section-title">Panorama financiero</h2><p class="ui-form-help">Valores del periodo en dólares. Barras comparativas por magnitud; el signo del neto se muestra en su valor.</p></figcaption>
        <?php foreach ($datosFinancierosUi as $etiquetaUi => $valorUi) {
            $anchoUi = $escalaFinancieraUi > 0 ? min(100, abs($valorUi) / $escalaFinancieraUi * 100) : 0;
        ?>
        <div class="ui-chart-row">
            <span class="ui-chart-label"><?php echo s($etiquetaUi); ?></span>
            <span class="ui-chart-track" aria-hidden="true"><span class="ui-chart-bar<?php echo $valorUi < 0 ? ' ui-chart-bar-negative' : ''; ?>" style="width: <?php echo number_format($anchoUi, 2, '.', ''); ?>%"></span></span>
            <strong class="ui-chart-value">$<?php echo s(number_format($valorUi, 2, '.', '')); ?></strong>
        </div>
        <?php } ?>
        <?php if ($escalaFinancieraUi === 0.0) { ?><p class="ui-form-help">Sin importes registrados para este periodo.</p><?php } ?>
    </figure>
    <figure class="ui-card ui-chart">
        <figcaption><h2 class="ui-section-title">Servicios más reservados</h2><p class="ui-form-help">Hasta seis servicios por número de reservas en el rango seleccionado.</p></figcaption>
        <?php if (empty($serviciosGraficaUi)) { ?><p class="ui-empty">Aún no hay servicios reservados para graficar.</p><?php } ?>
        <?php foreach ($serviciosGraficaUi as $filaGraficaUi) {
            $cantidadUi = (int)$filaGraficaUi['total_reservas'];
            $anchoUi = $escalaServiciosUi > 0 ? min(100, max(0, $cantidadUi) / $escalaServiciosUi * 100) : 0;
        ?>
        <div class="ui-chart-row">
            <span class="ui-chart-label"><?php echo s((string)$filaGraficaUi['servicio_nombre']); ?></span>
            <span class="ui-chart-track" aria-hidden="true"><span class="ui-chart-bar" style="width: <?php echo number_format($anchoUi, 2, '.', ''); ?>%"></span></span>
            <strong class="ui-chart-value"><?php echo $cantidadUi; ?> reservas</strong>
        </div>
        <?php } ?>
    </figure>
</section>

<section class="reportes-seccion" id="seccion-ocupacion-profesionales">
    <h2>Ocupación e Importes por Profesional</h2>
    <?php if (empty($resumenProfesionales)) { ?>
        <p class="reportes-vacio ui-empty">No hay profesionales registrados para el criterio seleccionado.</p>
    <?php } else { ?>
        <div class="ui-card ui-chart" aria-label="Ocupación por profesional">
            <h3>Disponibilidad utilizada</h3>
            <?php foreach ($resumenProfesionales as $filaOcupacionUi) {
                if (!empty($filaOcupacionUi['es_historico_sin_profesional'])) { continue; }
                $disponiblesUi = (float)($filaOcupacionUi['minutos_disponibles'] ?? 0);
                $ocupadosUi = (float)($filaOcupacionUi['minutos_ocupados'] ?? 0);
                $porcentajeUi = $disponiblesUi > 0 ? $ocupadosUi / $disponiblesUi * 100 : null;
            ?>
            <div class="ui-chart-row">
                <span class="ui-chart-label"><?php echo s((string)$filaOcupacionUi['profesional_nombre']); ?></span>
                <?php if ($porcentajeUi !== null) { ?><span class="ui-chart-track" aria-hidden="true"><span class="ui-chart-bar" style="width: <?php echo number_format(min(100, max(0, $porcentajeUi)), 2, '.', ''); ?>%"></span></span><?php } ?>
                <strong class="ui-chart-value"><?php echo $porcentajeUi === null ? 'Sin horas disponibles' : s(number_format($porcentajeUi, 2, '.', '')) . '%'; ?></strong>
            </div>
            <?php } ?>
            <p class="ui-form-help">Ocupación = minutos ocupados / minutos disponibles. Las barras llegan hasta 100%; el valor conserva cualquier exceso.</p>
        </div>
        <div class="tabla-contenedor ui-table-scroll" tabindex="0" role="region" aria-label="Tabla de reporte; desplaza horizontalmente para ver todas las columnas">
            <table class="tabla-reportes" id="tabla-ocupacion-profesionales">
                <thead>
                    <tr>
                        <th>Profesional</th>
                        <th>Estado</th>
                        <th>Citas</th>
                        <th>Minutos Ocupados / Disponibles</th>
                        <th>Horas Ocupadas / Disponibles</th>
                        <th>% Ocupación</th>
                        <th>Importe Reservado ($)</th>
                        <th>Ingreso Pagado ($)</th>
                        <th>Reembolsos ($)</th>
                        <th>Neto Cobrado ($)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($resumenProfesionales as $filaProf) {
                        $attrProfId = $filaProf['profesional_id'] !== null
                            ? (string)$filaProf['profesional_id']
                            : 'sin_profesional';
                        $estadoProfTexto = !empty($filaProf['es_historico_sin_profesional'])
                            ? 'Cita histórica'
                            : (!empty($filaProf['activo']) ? 'Activo' : 'Inactivo');
                        ?>
                        <tr data-profesional-id="<?php echo s($attrProfId); ?>">
                            <td class="col-profesional"><?php echo s((string)$filaProf['profesional_nombre']); ?></td>
                            <td><?php echo s($estadoProfTexto); ?></td>
                            <td class="col-citas"><?php echo s((string)$filaProf['total_citas']); ?></td>
                            <td class="col-minutos">
                                <?php echo s((string)$filaProf['minutos_ocupados']); ?> min /
                                <?php echo s((string)$filaProf['minutos_disponibles']); ?> min
                            </td>
                            <td class="col-horas">
                                <?php echo s(number_format((float)$filaProf['horas_ocupadas'], 2, '.', '')); ?> h /
                                <?php echo s(number_format((float)$filaProf['horas_disponibles'], 2, '.', '')); ?> h
                            </td>
                            <td class="col-ocupacion"><?php echo s((string)$filaProf['etiqueta_ocupacion']); ?></td>
                            <td class="col-importe-reservado">
                                $<?php echo s(number_format((float)$filaProf['importe_reservado'], 2, '.', '')); ?>
                            </td>
                            <td class="col-ingreso-pagado">
                                $<?php echo s(number_format((float)$filaProf['ingreso_pagado'], 2, '.', '')); ?>
                            </td>
                            <td class="col-reembolsos">
                                $<?php echo s(number_format((float)($filaProf['reembolsos'] ?? 0), 2, '.', '')); ?>
                            </td>
                            <td class="col-neto-cobrado">
                                $<?php echo s(number_format((float)($filaProf['neto_cobrado'] ?? $filaProf['ingreso_pagado']), 2, '.', '')); ?>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
</section>

<section class="reportes-seccion" id="seccion-desglose-servicios">
    <h2>Desglose por Servicio (Datos Históricos de Reserva)</h2>
    <?php if (empty($resumenServicios)) { ?>
        <p class="reportes-vacio ui-empty" id="estado-vacio-servicios">
            No se registraron servicios reservados en el rango y filtros seleccionados.
        </p>
    <?php } else { ?>
        <div class="tabla-contenedor ui-table-scroll" tabindex="0" role="region" aria-label="Tabla de reporte; desplaza horizontalmente para ver todas las columnas">
            <table class="tabla-reportes" id="tabla-desglose-servicios">
                <thead>
                    <tr>
                        <th>Servicio</th>
                        <th>Veces Reservado</th>
                        <th>Minutos Reservados</th>
                        <th>Importe Reservado ($)</th>
                        <th>Ingreso Pagado ($)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($resumenServicios as $filaSrv) {
                        $attrSrvId = $filaSrv['servicio_id'] !== null
                            ? (string)$filaSrv['servicio_id']
                            : 'eliminado_catalogo';
                        ?>
                        <tr data-servicio-id="<?php echo s($attrSrvId); ?>">
                            <td class="col-servicio-nombre">
                                <?php echo s((string)$filaSrv['servicio_nombre']); ?>
                                <?php if (!empty($filaSrv['es_eliminado_catalogo'])) { ?>
                                    <span class="etiqueta-historico">(Eliminado del catálogo)</span>
                                <?php } ?>
                            </td>
                            <td class="col-servicio-cantidad"><?php echo s((string)$filaSrv['total_reservas']); ?></td>
                            <td class="col-servicio-minutos"><?php echo s((string)$filaSrv['minutos_reservados']); ?> min</td>
                            <td class="col-servicio-reservado">
                                $<?php echo s(number_format((float)$filaSrv['importe_reservado'], 2, '.', '')); ?>
                            </td>
                            <td class="col-servicio-pagado">
                                $<?php echo s(number_format((float)$filaSrv['ingreso_pagado'], 2, '.', '')); ?>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
</section>

<?php if (!empty($movimientosFinancieros)) { ?>
<section class="reportes-seccion" id="seccion-movimientos-financieros">
    <h2>Historial de Movimientos Financieros (Desacoplado de la Agenda)</h2>
    <div class="tabla-contenedor ui-table-scroll" tabindex="0" role="region" aria-label="Tabla de reporte; desplaza horizontalmente para ver todas las columnas">
        <table class="tabla-reportes" id="tabla-movimientos-financieros">
            <thead>
                <tr>
                    <th>Ref. Pago</th>
                    <th>Proveedor</th>
                    <th>Monto ($)</th>
                    <th>Estado</th>
                    <th>Fecha Cobro</th>
                    <th>Fecha Cita</th>
                    <th>Estado Cita</th>
                    <th>Profesional</th>
                    <th>Cliente</th>
                    <th>Servicios Históricos</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($movimientosFinancieros as $mov) { ?>
                    <tr data-pago-id="<?php echo s((string)$mov['pago_id']); ?>">
                        <td><code><?php echo s((string)$mov['referencia_pago']); ?></code></td>
                        <td>
                            <?php echo s(strtoupper((string)$mov['proveedor'])); ?>
                            <?php if (!empty($mov['es_simulado'])) { ?>
                                <span class="etiqueta-historico">(Simulado)</span>
                            <?php } ?>
                        </td>
                        <td>$<?php echo s(number_format((float)$mov['monto'], 2, '.', '')); ?></td>
                        <td><span class="etiqueta-estado etiqueta-<?php echo s((string)$mov['estado']); ?>"><?php echo s(ucfirst((string)$mov['estado'])); ?></span></td>
                        <td><?php echo s((string)$mov['fecha_cobro']); ?></td>
                        <td><?php echo s((string)($mov['cita_fecha'] ?? 'N/A')); ?></td>
                        <td><?php echo s(ucfirst((string)($mov['cita_estado'] ?? 'N/A'))); ?></td>
                        <td><?php echo s((string)$mov['profesional_nombre']); ?></td>
                        <td><?php echo s((string)$mov['cliente']); ?></td>
                        <td>
                            <?php
                            $nombresSrv = [];
                            foreach ($mov['servicios'] as $sItem) {
                                $nombresSrv[] = $sItem['nombre'] . ' ($' . number_format((float)$sItem['precio'], 2, '.', '') . ')';
                            }
                            echo s(implode(', ', $nombresSrv) ?: 'Sin servicios registrados');
                            ?>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
    <?php $tm = $totalesMovimientos ?? []; if (!empty($tm)) { ?>
    <p class="reportes-nota-pagos" id="totales-movimientos">Total de movimientos: <strong><?php echo s((string)$tm['n']); ?></strong> · monto $<?php echo s(number_format($tm['monto'] / 100, 2, '.', '')); ?> · cobros del periodo $<?php echo s(number_format($tm['cobro_periodo'] / 100, 2, '.', '')); ?> · reembolsos $<?php echo s(number_format($tm['reembolso_periodo'] / 100, 2, '.', '')); ?> · neto $<?php echo s(number_format($tm['neto_periodo'] / 100, 2, '.', '')); ?>. Coinciden con la fila «Total» de los archivos.</p>
    <?php } ?>
</section>
<?php } ?>

<section class="reportes-seccion" id="seccion-detalle-reservas">
    <h2>Listado Detallado de Reservas</h2>
    <?php if (empty($reservas)) { ?>
        <p class="reportes-vacio ui-empty" id="estado-vacio-reportes">
            No se encontraron reservas para el rango de fechas y filtros seleccionados.
        </p>
    <?php } else { ?>
        <ul class="citas" id="listado-reservas-reporte">
            <?php foreach ($reservas as $res) { ?>
                <li
                    data-cita-id="<?php echo s((string)$res['id']); ?>"
                    data-tipo-cita="<?php echo !empty($res['es_historica_sin_profesional']) ? 'historica' : 'con-profesional'; ?>"
                    class="<?php echo !empty($res['es_cancelada']) ? 'cita-cancelada' : ''; ?>"
                >
                    <p>ID Cita: <span><?php echo s((string)$res['id']); ?></span></p>
                    <p>Fecha: <span><?php echo s((string)$res['fecha']); ?></span></p>
                    <p>Horario: <span><?php echo s((string)$res['horario']); ?> (<?php echo s((string)$res['duracion_total_minutos']); ?> min)</span></p>
                    <p>Profesional: <span><?php echo s((string)$res['profesional_nombre']); ?></span></p>
                    <p>Cliente: <span><?php echo s((string)$res['cliente']); ?><?php if ((string)($res['cliente_email'] ?? '') !== '') { ?> (<?php echo s((string)$res['cliente_email']); ?>)<?php } ?></span></p>
                    <p>Estado Cita: <span><strong><?php echo s((string)($res['etiqueta_estado_cita'] ?? 'Confirmada')); ?></strong></span></p>
                    <h3>Servicios (Snapshot Histórico)</h3>
                    <?php foreach ($res['servicios'] as $srvItem) { ?>
                        <p class="servicio">
                            <?php echo s((string)$srvItem['nombre']); ?> —
                            $<?php echo s(number_format((float)$srvItem['precio'], 2, '.', '')); ?>
                            <?php if ((int)$srvItem['duracion_minutos'] > 0) { ?>
                                (<?php echo s((string)$srvItem['duracion_minutos']); ?> min)
                            <?php } ?>
                        </p>
                    <?php } ?>
                    <p class="total">
                        Importe Reservado: <span>$<?php echo s(number_format((float)$res['importe_reservado'], 2, '.', '')); ?></span>
                        <?php if ($tieneFiltroServicio && (float)$res['importe_total_cita'] !== (float)$res['importe_reservado']) { ?>
                            <small>(Total cita completa: $<?php echo s(number_format((float)$res['importe_total_cita'], 2, '.', '')); ?>)</small>
                        <?php } ?>
                    </p>
                    <p class="ingreso-pagado-item">
                        Ingreso Pagado: <span>$<?php echo s(number_format((float)$res['ingreso_pagado'], 2, '.', '')); ?></span>
                    </p>
                    <p class="estado-pago-item">
                        Estado de Pago: <span><?php echo s((string)$res['etiqueta_estado_pago']); ?></span>
                    </p>
                </li>
            <?php } ?>
        </ul>
        <?php $tr = $totalesReservas ?? []; if (!empty($tr)) { ?>
        <p class="reportes-nota-pagos" id="totales-listado-reservas">Total del listado: <strong><?php echo s((string)$tr['n']); ?></strong> reservas · importe total de las citas $<?php echo s(number_format($tr['total_cita'] / 100, 2, '.', '')); ?> · importe reservado $<?php echo s(number_format($tr['reservado'] / 100, 2, '.', '')); ?> · ingreso pagado $<?php echo s(number_format($tr['pagado'] / 100, 2, '.', '')); ?>. Son las mismas cifras de la fila «Total» de los archivos descargados.</p>
        <?php } ?>
    <?php } ?>
</section>

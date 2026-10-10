<h1 class="nombre-pagina">Reportes Administrativos</h1>

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

$fechaInicioVal = (string)($filtros['fecha_inicio'] ?? '');
$fechaFinVal = (string)($filtros['fecha_fin'] ?? '');
$profFiltroVal = (string)($filtros['profesional_id'] ?? '');
$servFiltroVal = (string)($filtros['servicio_id'] ?? '');
$tieneFiltroServicio = !empty($filtros['tiene_filtro_servicio']);

$queryExportar = http_build_query([
    'fecha_inicio' => $fechaInicioVal,
    'fecha_fin' => $fechaFinVal,
    'profesional_id' => $profFiltroVal,
    'servicio_id' => $servFiltroVal,
]);
?>

<h2>Filtrar Reporte de Reservas y Ocupación</h2>
<div class="busqueda reportes-filtros">
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
        </div>
    </form>
</div>

<section id="resumen-global-reportes" class="reportes-seccion">
    <h2>Resumen General del Rango (<?php echo s($fechaInicioVal); ?> a <?php echo s($fechaFinVal); ?>)</h2>
    <p class="reportes-nota-pagos" id="nota-distincion-pagos">
        <?php echo s((string)($resumenGlobal['nota_pagos'] ?? '')); ?>
    </p>

    <div class="reportes-kpis">
        <article class="reporte-kpi" data-kpi="total-citas">
            <h3>Total Citas Reservadas</h3>
            <p class="kpi-valor" id="kpi-total-citas"><?php echo s((string)($resumenGlobal['total_citas'] ?? 0)); ?></p>
            <p class="kpi-detalle">
                Con profesional: <?php echo s((string)($resumenGlobal['total_citas_con_profesional'] ?? 0)); ?> |
                Históricas: <?php echo s((string)($resumenGlobal['total_citas_historicas_sin_profesional'] ?? 0)); ?>
            </p>
        </article>

        <article class="reporte-kpi" data-kpi="ocupacion-global">
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

        <article class="reporte-kpi" data-kpi="importe-reservado">
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

        <article class="reporte-kpi" data-kpi="ingreso-pagado">
            <h3>Ingreso Efectivamente Pagado</h3>
            <p class="kpi-valor" id="kpi-ingreso-pagado">
                $<?php echo s(number_format((float)($resumenGlobal['ingreso_pagado_total'] ?? 0), 2, '.', '')); ?>
            </p>
            <p class="kpi-detalle">
                Pendiente de cobro: $<?php echo s(number_format((float)($resumenGlobal['saldo_pendiente_cobro_total'] ?? 0), 2, '.', '')); ?>
            </p>
        </article>
    </div>
</section>

<section class="reportes-seccion" id="seccion-ocupacion-profesionales">
    <h2>Ocupación e Importes por Profesional</h2>
    <?php if (empty($resumenProfesionales)) { ?>
        <p class="reportes-vacio">No hay profesionales registrados para el criterio seleccionado.</p>
    <?php } else { ?>
        <div class="tabla-contenedor">
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
        <p class="reportes-vacio" id="estado-vacio-servicios">
            No se registraron servicios reservados en el rango y filtros seleccionados.
        </p>
    <?php } else { ?>
        <div class="tabla-contenedor">
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

<section class="reportes-seccion" id="seccion-detalle-reservas">
    <h2>Listado Detallado de Reservas</h2>
    <?php if (empty($reservas)) { ?>
        <p class="reportes-vacio" id="estado-vacio-reportes">
            No se encontraron reservas para el rango de fechas y filtros seleccionados.
        </p>
    <?php } else { ?>
        <ul class="citas" id="listado-reservas-reporte">
            <?php foreach ($reservas as $res) { ?>
                <li
                    data-cita-id="<?php echo s((string)$res['id']); ?>"
                    data-tipo-cita="<?php echo !empty($res['es_historica_sin_profesional']) ? 'historica' : 'con-profesional'; ?>"
                >
                    <p>ID Cita: <span><?php echo s((string)$res['id']); ?></span></p>
                    <p>Fecha: <span><?php echo s((string)$res['fecha']); ?></span></p>
                    <p>Horario: <span><?php echo s((string)$res['horario']); ?> (<?php echo s((string)$res['duracion_total_minutos']); ?> min)</span></p>
                    <p>Profesional: <span><?php echo s((string)$res['profesional_nombre']); ?></span></p>
                    <p>Cliente: <span><?php echo s((string)$res['cliente']); ?> (<?php echo s((string)$res['cliente_email']); ?>)</span></p>
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
    <?php } ?>
</section>

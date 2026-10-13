<?php
use Services\CrmService;

$modo = $panel['modo'];
$f = $panel['filtros'];
$res = $panel['resumen'] ?? [];
$analitica = !empty($acceso['analitica']);
$puedeExportar = !empty($acceso['exportar']);
$catalogos = $catalogos ?? ['servicios' => [], 'profesionales' => []];
$listado = $panel['listado'] ?? null;
$rutaBase = '/admin/clientes';
$pct = static fn(?float $v): string => $v === null ? 'Sin datos' : number_format($v, 1, '.', '') . ' %';
$entero = static fn($v): string => (string)(int)$v;
$fechaCorta = static fn($d): string => $d ? substr((string)$d, 0, 10) : '—';
$claves = ['q', 'desde', 'hasta', 'alta_desde', 'alta_hasta', 'profesional_id', 'servicio_id', 'segmento', 'membresia', 'consentimiento', 'dias_activo', 'orden', 'dir', 'por_pagina'];
/** Query string con los filtros vigentes; $extra reemplaza claves (p. ej. la página). */
$consulta = static function (array $extra = []) use ($f, $claves): string {
    $datos = [];
    foreach ($claves as $k) { if (isset($f[$k]) && $f[$k] !== '' && $f[$k] !== null) { $datos[$k] = $f[$k]; } }
    foreach ($extra as $k => $v) { if ($v === null) { unset($datos[$k]); } else { $datos[$k] = $v; } }
    return http_build_query($datos);
};
$dias = (int)($f['dias_activo'] ?? CrmService::DIAS_ACTIVO_DEFECTO);
$hastaEf = $res['hasta_efectivo'] ?? ($f['hasta'] ?? '');
?>
<header class="ui-page-header">
    <div>
        <p class="ui-eyebrow">Relación con clientes</p>
        <h1 class="nombre-pagina"><?= $modo === 'propios' ? 'Mis clientes' : 'Clientes' ?></h1>
        <p class="descripcion-pagina"><?php if ($modo === 'propios'): ?>Personas a las que has atendido. Solo contacto operativo y servicios realizados contigo.<?php elseif ($modo === 'analitica'): ?>Indicadores agregados de la base de clientes. No incluye datos personales individuales.<?php else: ?>Quiénes son, cuándo vinieron, qué prefieren y cómo cuidar la relación con ellos.<?php endif; ?></p>
    </div>
    <?php if ($modo !== 'propios'): ?><a class="boton ui-button-quiet" href="#como-se-calcula">Cómo se calcula</a><?php endif; ?>
</header>
<?php include __DIR__ . '/../templates/barra.php'; include __DIR__ . '/../templates/alertas.php'; ?>

<?php if ($modo === 'propios'): ?>
    <section class="crm-card" aria-labelledby="crm-propios-titulo">
        <h2 id="crm-propios-titulo">Buscar entre tus clientes</h2>
        <form class="crm-filtros" action="<?= s($rutaBase) ?>" method="GET" role="search">
            <div class="campo"><label for="crm-q">Nombre, correo o teléfono</label><input id="crm-q" name="q" type="search" maxlength="80" value="<?= s($f['q']) ?>" autocomplete="off"></div>
            <div class="campo"><label for="crm-pp">Por página</label><select id="crm-pp" name="por_pagina"><?php foreach (CrmService::POR_PAGINA as $n): ?><option value="<?= (int)$n ?>"<?= (int)$f['por_pagina'] === $n ? ' selected' : '' ?>><?= (int)$n ?></option><?php endforeach; ?></select></div>
            <div class="crm-filtros__acciones"><button class="boton" type="submit">Buscar</button><a class="boton ui-button-quiet" href="<?= s($rutaBase) ?>">Limpiar</a></div>
        </form>
    </section>
    <?php if (empty($panel['vinculado'])): ?>
        <p class="crm-vacio" role="status">Tu cuenta aún no está vinculada a una ficha profesional, por eso no hay clientes que mostrar. Pide a la administración que enlace tu cuenta en «Equipo y Permisos».</p>
    <?php elseif (empty($listado['filas'])): ?>
        <p class="crm-vacio" role="status"><?= $f['q'] !== '' ? 'Ninguno de tus clientes coincide con la búsqueda.' : 'Todavía no tienes visitas completadas con clientes. Cuando completes una cita, la persona aparecerá aquí.' ?></p>
    <?php else: ?>
        <div class="crm-tabla" tabindex="0" role="region" aria-label="Tus clientes; desplaza horizontalmente para ver todas las columnas">
            <table>
                <caption class="crm-sr">Clientes atendidos por ti</caption>
                <thead><tr><th scope="col">Cliente</th><th scope="col">Contacto operativo</th><th scope="col">Visitas contigo</th><th scope="col">Última visita contigo</th></tr></thead>
                <tbody>
                <?php foreach ($listado['filas'] as $r): ?>
                    <tr>
                        <th scope="row"><a href="/admin/clientes/ficha?id=<?= (int)$r['id'] ?>"><?= s(trim($r['nombre'] . ' ' . $r['apellido'])) ?></a></th>
                        <td><?= s($r['email']) ?><small><?= s((string)$r['telefono']) ?></small></td>
                        <td class="ui-table-number"><?= $entero($r['visitas']) ?></td>
                        <td class="ui-table-date"><?= s($fechaCorta($r['ultima'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php $pag = $listado; $noun = 'clientes'; include __DIR__ . '/_paginacion.php'; ?>
    <?php endif; ?>
    <p class="crm-nota">Esta vista muestra solo lo necesario para atender: no incluye gasto, consentimiento comercial ni formularios.</p>
<?php else: ?>

<section class="crm-card" aria-labelledby="crm-filtros-titulo">
    <h2 id="crm-filtros-titulo"><?= $modo === 'completo' ? 'Buscar y filtrar' : 'Periodo del análisis' ?></h2>
    <form class="crm-filtros" action="<?= s($rutaBase) ?>" method="GET"<?= $modo === 'completo' ? ' role="search"' : '' ?>>
        <?php if ($modo === 'completo'): ?>
        <div class="campo crm-ancho"><label for="crm-q">Nombre, correo o teléfono</label><input id="crm-q" name="q" type="search" maxlength="80" value="<?= s($f['q']) ?>" autocomplete="off"></div>
        <?php endif; ?>
        <div class="campo"><label for="crm-desde">Periodo desde</label><input id="crm-desde" name="desde" type="date" value="<?= s($f['desde']) ?>" max="<?= s($panel['hoy']) ?>"></div>
        <div class="campo"><label for="crm-hasta">Periodo hasta</label><input id="crm-hasta" name="hasta" type="date" value="<?= s($f['hasta']) ?>" max="<?= s($panel['hoy']) ?>"></div>
        <div class="campo"><label for="crm-dias">Días para «activo» (30–365)</label><input id="crm-dias" name="dias_activo" type="number" inputmode="numeric" min="<?= (int)CrmService::DIAS_ACTIVO_MIN ?>" max="<?= (int)CrmService::DIAS_ACTIVO_MAX ?>" value="<?= (int)$dias ?>"></div>
        <?php if ($modo === 'completo'): ?>
        <div class="campo"><label for="crm-alta-desde">Alta desde</label><input id="crm-alta-desde" name="alta_desde" type="date" value="<?= s((string)$f['alta_desde']) ?>"></div>
        <div class="campo"><label for="crm-alta-hasta">Alta hasta</label><input id="crm-alta-hasta" name="alta_hasta" type="date" value="<?= s((string)$f['alta_hasta']) ?>"></div>
        <div class="campo"><label for="crm-prof">Atendido por</label><select id="crm-prof" name="profesional_id"><option value="">Cualquier profesional</option><?php foreach ($catalogos['profesionales'] as $p): ?><option value="<?= (int)$p['id'] ?>"<?= (int)$f['profesional_id'] === (int)$p['id'] ? ' selected' : '' ?>><?= s($p['nombre'] . ((int)$p['activo'] === 1 ? '' : ' (inactivo)')) ?></option><?php endforeach; ?></select></div>
        <div class="campo"><label for="crm-serv">Servicio recibido</label><select id="crm-serv" name="servicio_id"><option value="">Cualquier servicio</option><?php foreach ($catalogos['servicios'] as $sv): ?><option value="<?= (int)$sv['id'] ?>"<?= (int)$f['servicio_id'] === (int)$sv['id'] ? ' selected' : '' ?>><?= s($sv['nombre']) ?></option><?php endforeach; ?></select></div>
        <div class="campo"><label for="crm-seg">Segmento</label><select id="crm-seg" name="segmento"><option value="">Todos</option><?php foreach (CrmService::SEGMENTOS as $k => $et): if ($k === 'vip' && !$analitica) { continue; } ?><option value="<?= s($k) ?>"<?= $f['segmento'] === $k ? ' selected' : '' ?>><?= s($et) ?></option><?php endforeach; ?></select></div>
        <div class="campo"><label for="crm-mem">Membresía</label><select id="crm-mem" name="membresia"><option value="">Todas</option><?php foreach (CrmService::MEMBRESIAS as $k => $et): ?><option value="<?= s($k) ?>"<?= $f['membresia'] === $k ? ' selected' : '' ?>><?= s($et) ?></option><?php endforeach; ?></select></div>
        <div class="campo"><label for="crm-con">Consentimiento comercial</label><select id="crm-con" name="consentimiento"><option value="">Todos</option><option value="si"<?= $f['consentimiento'] === 'si' ? ' selected' : '' ?>>Aceptó</option><option value="no"<?= $f['consentimiento'] === 'no' ? ' selected' : '' ?>>No aceptó</option></select></div>
        <div class="campo"><label for="crm-orden">Ordenar por</label><select id="crm-orden" name="orden">
            <?php foreach (['nombre' => 'Apellido y nombre', 'alta' => 'Fecha de alta', 'ultima' => 'Última visita', 'visitas' => 'Visitas del periodo', 'visitas_total' => 'Visitas totales'] + ($analitica ? ['gasto' => 'Gasto neto real'] : []) as $k => $et): ?><option value="<?= s($k) ?>"<?= $f['orden'] === $k ? ' selected' : '' ?>><?= s($et) ?></option><?php endforeach; ?></select></div>
        <div class="campo"><label for="crm-dir">Dirección</label><select id="crm-dir" name="dir"><option value="asc"<?= $f['dir'] === 'asc' ? ' selected' : '' ?>>Ascendente</option><option value="desc"<?= $f['dir'] === 'desc' ? ' selected' : '' ?>>Descendente</option></select></div>
        <div class="campo"><label for="crm-pp2">Por página</label><select id="crm-pp2" name="por_pagina"><?php foreach (CrmService::POR_PAGINA as $n): ?><option value="<?= (int)$n ?>"<?= (int)$f['por_pagina'] === $n ? ' selected' : '' ?>><?= (int)$n ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        <div class="crm-filtros__acciones"><button class="boton" type="submit">Aplicar</button><a class="boton ui-button-quiet" href="<?= s($rutaBase) ?>">Restablecer</a></div>
    </form>
    <p class="crm-nota">Periodo analizado: <strong><?= s($f['desde']) ?></strong> a <strong><?= s($hastaEf) ?></strong> (nunca después de hoy, <?= s($panel['hoy']) ?>, hora de Guayaquil). Los indicadores y gráficos describen a <em>todos</em> los clientes y no cambian con los filtros del listado.</p>
</section>

<section aria-labelledby="crm-ind-titulo" class="crm-seccion">
    <h2 id="crm-ind-titulo">Indicadores</h2>
    <div class="crm-kpis">
        <article class="crm-kpi">
            <h3 class="crm-kpi__titulo">Clientes totales</h3>
            <p class="crm-kpi__valor"><?= $entero($res['clientes_total']) ?></p>
            <p class="crm-kpi__nota">Membresías: <?= $entero($res['membresias']['activo']) ?> activas · <?= $entero($res['membresias']['suspendido']) ?> suspendidas · <?= $entero($res['membresias']['sin_registro']) ?> cuentas legadas sin registro. No incluye cuentas solo laborales.</p>
        </article>
        <article class="crm-kpi">
            <h3 class="crm-kpi__titulo">Altas en el periodo</h3>
            <p class="crm-kpi__valor"><?= $entero($res['nuevos']) ?></p>
            <p class="crm-kpi__nota">Clientes con fecha de alta dentro del periodo. Su primera visita completada ocurrió en el periodo para <?= $entero($res['primera_visita_en_rango']) ?> clientes (es otro dato: puede ser de una alta anterior).</p>
        </article>
        <article class="crm-kpi">
            <h3 class="crm-kpi__titulo">Clientes activos (<?= (int)$dias ?> días)</h3>
            <p class="crm-kpi__valor"><?= $entero($res['activos']) ?></p>
            <p class="crm-kpi__nota"><?= s($pct($res['pct_activos'])) ?> de <?= $entero($res['clientes_total']) ?> clientes tuvo al menos una visita completada en los últimos <?= (int)$dias ?> días hasta hoy.</p>
        </article>
        <article class="crm-kpi">
            <h3 class="crm-kpi__titulo">Visitas completadas</h3>
            <p class="crm-kpi__valor"><?= $entero($res['visitas_rango']) ?></p>
            <p class="crm-kpi__nota">En el periodo, de <?= $entero($res['con_visita_rango']) ?> clientes distintos. Frecuencia media: <?= $res['frecuencia_media'] === null ? 'sin datos' : s(number_format($res['frecuencia_media'], 2, '.', '')) . ' visitas por cliente con visita' ?>. Las canceladas y las no asistidas no son visitas.</p>
        </article>
        <?php if ($analitica): ?>
        <article class="crm-kpi">
            <h3 class="crm-kpi__titulo">Repetición en el periodo</h3>
            <p class="crm-kpi__valor"><?= s($pct($res['pct_repiten'])) ?></p>
            <p class="crm-kpi__nota"><?= $entero($res['repiten_rango']) ?> de <?= $entero($res['con_visita_rango']) ?> clientes con visita en el periodo tuvieron dos o más visitas completadas en él.</p>
        </article>
        <?php endif; ?>
        <article class="crm-kpi">
            <h3 class="crm-kpi__titulo">Recencia media</h3>
            <p class="crm-kpi__valor"><?= $res['recencia_media'] === null ? 'Sin datos' : s(number_format($res['recencia_media'], 1, '.', '')) . ' d' ?></p>
            <p class="crm-kpi__nota">Días desde la última visita completada, promedio de los <?= $entero($res['con_historial']) ?> clientes que ya han tenido alguna visita.</p>
        </article>
        <article class="crm-kpi">
            <h3 class="crm-kpi__titulo">Cancelaciones</h3>
            <p class="crm-kpi__valor"><?= s($pct($res['pct_cancelacion'])) ?></p>
            <p class="crm-kpi__nota"><?= $entero($res['citas']['canceladas']) ?> canceladas de <?= $entero($res['citas']['total']) ?> citas con fecha en el periodo (todos los estados, hasta hoy).</p>
        </article>
        <article class="crm-kpi">
            <h3 class="crm-kpi__titulo">No asistencia</h3>
            <p class="crm-kpi__valor"><?= s($pct($res['pct_no_asistencia'])) ?></p>
            <p class="crm-kpi__nota"><?= $entero($res['citas']['no_asistio']) ?> «no asistió» de las mismas <?= $entero($res['citas']['total']) ?> citas. Es un estado distinto de cancelada.</p>
        </article>
        <article class="crm-kpi">
            <h3 class="crm-kpi__titulo">Fidelidad</h3>
            <p class="crm-kpi__valor"><?= $entero($res['fidelidad']['puntos']) ?> pts</p>
            <p class="crm-kpi__nota">Puntos vigentes registrados (acumulados menos canjes). Canjes: <?= $entero($res['fidelidad']['canjes_pendientes']) ?> pendientes · <?= $entero($res['fidelidad']['canjes_entregados']) ?> entregados.</p>
        </article>
        <article class="crm-kpi">
            <h3 class="crm-kpi__titulo">Consentimiento comercial</h3>
            <p class="crm-kpi__valor"><?= $entero($res['con_consentimiento']) ?></p>
            <p class="crm-kpi__nota">Clientes que aceptaron recibir promociones por correo, de <?= $entero($res['clientes_total']) ?>. No aceptar es el estado por defecto.</p>
        </article>
    </div>

    <?php if ($analitica && is_array($res['gasto'])): $g = $res['gasto']; ?>
    <h3 class="crm-subtitulo">Dinero del periodo (solo analítica)</h3>
    <div class="crm-kpis">
        <article class="crm-kpi crm-kpi--dinero">
            <h3 class="crm-kpi__titulo">Gasto neto real</h3>
            <p class="crm-kpi__valor"><?= s(CrmService::dinero($g['neto_real'])) ?></p>
            <p class="crm-kpi__nota">Cobros reales <?= s(CrmService::dinero($g['cobros_real'])) ?> menos devoluciones <?= s(CrmService::dinero($g['devoluciones_real'])) ?>. Cada cobro cuenta en su fecha de cobro y cada devolución en su fecha de reembolso, aunque sea de otro mes.</p>
        </article>
        <article class="crm-kpi crm-kpi--dinero">
            <h3 class="crm-kpi__titulo">Ticket promedio por cobro</h3>
            <p class="crm-kpi__valor"><?= $g['ticket_por_cobro'] === null ? 'Sin datos' : s(CrmService::dinero($g['ticket_por_cobro'])) ?></p>
            <p class="crm-kpi__nota">Cobros reales brutos ÷ número de cobros reales (<?= $entero($g['n_cobros_real']) ?>). Es por cobro, no por prestación ni por visita; no descuenta devoluciones.</p>
        </article>
        <article class="crm-kpi crm-kpi--sim">
            <h3 class="crm-kpi__titulo">Pasarela simulada (no es ingreso)</h3>
            <p class="crm-kpi__valor"><?= s(CrmService::dinero($g['neto_simulado'])) ?></p>
            <p class="crm-kpi__nota">Neto de pagos del proveedor «mock». Se muestra aparte y nunca se suma al gasto real.</p>
        </article>
        <article class="crm-kpi">
            <h3 class="crm-kpi__titulo">Clientes con movimiento de dinero</h3>
            <p class="crm-kpi__valor"><?= $entero($g['clientes_con_gasto']) ?></p>
            <p class="crm-kpi__nota">Clientes con al menos un cobro o devolución real en el periodo.</p>
        </article>
    </div>
    <?php endif; ?>
</section>

<section aria-labelledby="crm-graf-titulo" class="crm-seccion">
    <h2 id="crm-graf-titulo">Gráficos</h2>
    <div class="crm-graficos">
        <?php
        $grafico = ['id' => 'altas', 'titulo' => 'Altas por mes', 'descripcion' => 'Clientes que se dieron de alta cada mes del periodo.', 'unidad' => 'altas', 'dinero' => false, 'datos' => $panel['series']['altas']];
        include __DIR__ . '/_grafico_barras.php';
        $grafico = ['id' => 'visitas', 'titulo' => 'Visitas completadas por mes', 'descripcion' => 'Citas completadas con fecha de cada mes (no incluye canceladas ni no asistidas).', 'unidad' => 'visitas', 'dinero' => false,
            'datos' => array_map(static fn(array $d): array => $d + ['extra' => (string)$d['clientes'], 'extra_titulo' => 'Clientes distintos'], $panel['series']['visitas'])];
        include __DIR__ . '/_grafico_barras.php';
        if ($analitica && $panel['series']['cobros'] !== null) {
            $grafico = ['id' => 'cobros', 'titulo' => 'Cobros reales por mes', 'descripcion' => 'Cobros reales (sin pasarela simulada) según su fecha de cobro. Las devoluciones se restan en el mes de su reembolso: están en la tabla.', 'unidad' => 'USD', 'dinero' => true,
                'datos' => array_map(static fn(array $d): array => $d + ['extra' => \Services\CrmService::dinero($d['devoluciones']), 'extra_titulo' => 'Devoluciones del mes'], $panel['series']['cobros'])];
            include __DIR__ . '/_grafico_barras.php';
        }
        $grafico = ['id' => 'segmentos', 'titulo' => 'Distribución de clientes por segmento', 'descripcion' => 'Cuántos clientes hay hoy en cada segmento (excluyentes; reglas más abajo).', 'unidad' => 'clientes', 'vacio' => 'Aún no hay clientes para segmentar.',
            'datos' => array_map(static fn(array $d): array => ['etiqueta' => $d['etiqueta'], 'valor' => $d['valor'], 'clase' => 'crm-seg--' . $d['clave']], $panel['segmentos'])];
        include __DIR__ . '/_grafico_horizontal.php';
        $grafico = ['id' => 'servicios', 'titulo' => 'Servicios más usados', 'descripcion' => 'Prestaciones de citas completadas en el periodo (hasta 8 servicios). Una cita con dos servicios suma una prestación a cada uno.', 'unidad' => 'prestaciones', 'vacio' => 'No hay citas completadas en el periodo.',
            'datos' => array_map(static fn(array $d): array => ['etiqueta' => $d['nombre'], 'valor' => (int)$d['prestaciones'], 'detalle' => $entero($d['clientes']) . ' clientes distintos'], $panel['servicios'])];
        include __DIR__ . '/_grafico_horizontal.php';
        ?>
    </div>
</section>

<section aria-labelledby="crm-seg-titulo" class="crm-seccion">
    <h2 id="crm-seg-titulo">Segmentos y reglas</h2>
    <p>Cada cliente cae en <strong>un solo</strong> segmento, evaluados en este orden con la fecha de hoy (<?= s($panel['hoy']) ?>) y «activo» = <?= (int)$dias ?> días:</p>
    <ol class="crm-reglas">
        <?php if ($analitica): ?><li><strong>VIP</strong>: entra en el tercio superior de recencia, frecuencia y gasto neto real (RFM, abajo) y su última visita es de hace <?= (int)$dias ?> días o menos.</li><?php endif; ?>
        <li><strong>Nuevo</strong>: su alta como cliente tiene <?= (int)CrmService::DIAS_NUEVO ?> días o menos.</li>
        <li><strong>Activo</strong>: su última visita completada fue hace <?= (int)$dias ?> días o menos.</li>
        <li><strong>En riesgo</strong>: su última visita fue hace entre <?= (int)$dias + 1 ?> y <?= 2 * (int)$dias ?> días.</li>
        <li><strong>Inactivo</strong>: su última visita fue hace más de <?= 2 * (int)$dias ?> días, o nunca ha completado una visita (y no es nuevo).</li>
    </ol>
    <?php if (!$analitica): ?><p class="crm-nota">Los segmentos que dependen del gasto solo los calcula la analítica; en esta vista esas personas aparecen como Nuevo o Activo.</p><?php endif; ?>
    <div class="crm-tabla" tabindex="0" role="region" aria-label="Clientes por segmento; desplaza horizontalmente si no caben las columnas">
        <table>
            <caption class="crm-sr">Número de clientes por segmento</caption>
            <thead><tr><th scope="col">Segmento</th><th scope="col">Clientes</th><?php if ($modo === 'completo'): ?><th scope="col">Listado</th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($panel['segmentos'] as $sg): ?>
                <tr><th scope="row"><?= s($sg['etiqueta']) ?></th><td class="ui-table-number"><?= $entero($sg['valor']) ?></td><?php if ($modo === 'completo'): ?><td><a href="<?= s($rutaBase . '?' . $consulta(['segmento' => $sg['clave'], 'pagina' => null])) ?>">Ver clientes <?= s(mb_strtolower($sg['etiqueta'])) ?></a></td><?php endif; ?></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($analitica && is_array($panel['rfm'])): $rfm = $panel['rfm']; ?>
    <div class="crm-rfm" id="crm-rfm">
        <h3 class="crm-subtitulo">RFM (recencia, frecuencia, gasto)</h3>
        <?php if (!$rfm['disponible']): ?>
            <p class="crm-vacio" role="status"><?= s($rfm['mensaje']) ?></p>
        <?php else: $u = $rfm['umbrales']; ?>
            <p>Población: <strong><?= (int)$rfm['poblacion'] ?></strong> clientes con al menos una visita completada en los últimos <?= (int)CrmService::VENTANA_RFM_DIAS ?> días. Cada dimensión se divide en terciles: puntaje 3 si supera el segundo corte, 2 si supera el primero, 1 en otro caso (los empates reciben el mismo puntaje).</p>
            <ul class="crm-reglas">
                <li><strong>Recencia (R)</strong>: 3 si la última visita fue hace menos de <?= (int)$u['recencia']['r3'] ?> días; 2 si hace menos de <?= (int)$u['recencia']['r2'] ?> días.</li>
                <li><strong>Frecuencia (F)</strong>: visitas completadas en <?= (int)CrmService::VENTANA_RFM_DIAS ?> días. 3 si son más de <?= (int)$u['frecuencia']['f3'] ?>; 2 si son más de <?= (int)$u['frecuencia']['f2'] ?>.</li>
                <li><strong>Gasto (M)</strong>: <?php if ($rfm['usa_gasto']): ?>gasto neto real en <?= (int)CrmService::VENTANA_RFM_DIAS ?> días. 3 si supera <?= s(CrmService::dinero($u['gasto']['m3'])) ?>; 2 si supera <?= s(CrmService::dinero($u['gasto']['m2'])) ?>.<?php else: ?>ningún cliente tiene gasto real registrado, así que esta dimensión no se usa.<?php endif; ?></li>
            </ul>
            <p><strong>VIP</strong> = R 3, F 3<?= $rfm['usa_gasto'] ? ', M 3' : '' ?> y activo. Hay <strong><?= (int)count($rfm['vip_ids']) ?></strong> clientes VIP. Sin variación entre clientes nadie alcanza el puntaje 3: es una regla conservadora, no una predicción.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</section>

<?php if ($analitica): ?>
<section aria-labelledby="crm-coh-titulo" class="crm-seccion">
    <h2 id="crm-coh-titulo">Retención por cohorte</h2>
    <p>Cada fila agrupa a los clientes por el mes de su <strong>primera visita completada</strong> (solo citas completadas hasta hoy; nunca reservas futuras). «Repiten» son quienes tuvieron otra visita completada en un día posterior dentro de <?= (int)CrmService::VENTANA_RETENCION_DIAS ?> días de la primera. El denominador es el número de clientes de la cohorte. Se muestran las últimas 12 cohortes del periodo.</p>
    <?php if (!$panel['cohortes']): ?>
        <p class="crm-vacio" role="status">No hay primeras visitas completadas en el periodo seleccionado.</p>
    <?php else: ?>
    <div class="crm-tabla" tabindex="0" role="region" aria-label="Retención por cohorte; desplaza horizontalmente si no caben las columnas">
        <table>
            <caption class="crm-sr">Retención a <?= (int)CrmService::VENTANA_RETENCION_DIAS ?> días por mes de primera visita</caption>
            <thead><tr><th scope="col">Cohorte (mes)</th><th scope="col">Clientes</th><th scope="col">Repiten</th><th scope="col">Retención</th><th scope="col">Estado de la cohorte</th></tr></thead>
            <tbody>
            <?php foreach ($panel['cohortes'] as $co): ?>
                <tr><th scope="row"><?= s($co['cohorte']) ?></th><td class="ui-table-number"><?= $entero($co['clientes']) ?></td><td class="ui-table-number"><?= $entero($co['repiten']) ?></td><td class="ui-table-number"><?= s($pct($co['pct'])) ?></td><td><?= $co['madura'] ? 'Completa' : 'En maduración: aún no pasan ' . (int)CrmService::VENTANA_RETENCION_DIAS . ' días desde el fin del mes' ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($modo === 'completo' && is_array($listado)): ?>
<section aria-labelledby="crm-lista-titulo" class="crm-seccion">
    <div class="crm-encabezado-lista">
        <h2 id="crm-lista-titulo">Listado de clientes</h2>
        <?php if ($puedeExportar): ?>
        <div class="crm-exportar">
            <a class="boton" href="/admin/clientes/exportar?<?= s($consulta(['tipo' => 'clientes', 'pagina' => null])) ?>">Exportar clientes (CSV)</a>
            <a class="boton boton-secundario" href="/admin/clientes/exportar?<?= s($consulta(['tipo' => 'comercial', 'pagina' => null])) ?>">Exportar lista comercial (CSV)</a>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($puedeExportar): ?><p class="crm-nota">La exportación respeta los filtros actuales, hasta <?= (int)CrmService::EXPORT_MAX_FILAS ?> filas, y nunca incluye respuestas de formularios. La <strong>lista comercial</strong> solo trae a quienes aceptaron promociones, con cuenta confirmada y membresía no suspendida: vuelve a validar el consentimiento antes de enviar.</p><?php endif; ?>
    <?php if ($listado['total'] === 0): ?>
        <p class="crm-vacio" role="status"><?php if ((int)$res['clientes_total'] === 0): ?>Aún no hay clientes registrados. Cuando alguien cree una cuenta o reserve una cita, aparecerá aquí.<?php else: ?>Ningún cliente coincide con los filtros. <a href="<?= s($rutaBase) ?>">Restablecer filtros</a>.<?php endif; ?></p>
    <?php else: ?>
    <div class="crm-tabla" tabindex="0" role="region" aria-label="Listado de clientes; desplaza horizontalmente para ver todas las columnas">
        <table class="crm-tabla-clientes">
            <caption class="crm-sr">Clientes, <?= s((string)$listado['primero']) ?> a <?= s((string)$listado['ultimo']) ?> de <?= s((string)$listado['total']) ?></caption>
            <thead><tr>
                <th scope="col">Cliente</th><th scope="col">Contacto</th><th scope="col">Membresía</th><th scope="col">Segmento</th>
                <th scope="col">Visitas del periodo</th><th scope="col">Última visita</th><th scope="col">Consentimiento comercial</th>
                <?php if ($analitica): ?><th scope="col">Gasto neto real</th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($listado['filas'] as $r): ?>
                <tr>
                    <th scope="row"><a href="/admin/clientes/ficha?id=<?= (int)$r['id'] ?>"><?= s(trim($r['nombre'] . ' ' . $r['apellido'])) ?></a><?php if ($r['rol_efectivo'] !== 'cliente'): ?><small>También tiene acceso laboral</small><?php endif; ?></th>
                    <td><?= s($r['email']) ?><small><?= s((string)$r['telefono']) ?></small></td>
                    <td><?= s(CrmService::MEMBRESIAS[$r['membresia']] ?? $r['membresia']) ?><small>Alta: <?= s($fechaCorta($r['alta'])) ?></small></td>
                    <td><span class="crm-etiqueta crm-seg--<?= s($r['segmento']) ?>"><?= s(CrmService::SEGMENTOS[$r['segmento']] ?? $r['segmento']) ?></span></td>
                    <td class="ui-table-number"><?= $entero($r['visitas_rango']) ?><small>Total: <?= $entero($r['visitas_total']) ?></small></td>
                    <td class="ui-table-date"><?= $r['ultima_visita'] === null ? 'Sin visitas' : s($r['ultima_visita']) ?><?php if ($r['recencia'] !== null): ?><small>hace <?= $entero($r['recencia']) ?> días</small><?php endif; ?></td>
                    <td><?= (int)$r['consentimiento'] === 1 ? 'Aceptó' : 'No aceptó' ?></td>
                    <?php if ($analitica): ?><td class="ui-table-number"><?= s(CrmService::dinero((int)$r['cobros_real'] - (int)$r['dev_real'])) ?><?php if ((int)$r['cobros_sim'] !== 0 || (int)$r['dev_sim'] !== 0): ?><small>Simulado: <?= s(CrmService::dinero((int)$r['cobros_sim'] - (int)$r['dev_sim'])) ?></small><?php endif; ?></td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php $pag = $listado; $noun = 'clientes'; include __DIR__ . '/_paginacion.php'; ?>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="crm-seccion crm-definiciones" id="como-se-calcula" aria-labelledby="crm-calc-titulo">
    <h2 id="crm-calc-titulo">Cómo se calcula</h2>
    <p>Todas las fechas «de hoy» se calculan en la zona America/Guayaquil. Una <strong>visita</strong> es una cita en estado <em>completada</em> con fecha hasta hoy; las citas canceladas, no asistidas, confirmadas o en servicio no son visitas. Nada aquí es una predicción ni un valor de vida estimado.</p>
    <dl class="crm-dl">
        <dt>Clientes totales</dt><dd>Personas con membresía de cliente, más cuentas legadas sin membresía cuyo rol efectivo es cliente, más cuentas con alguna cita. Las cuentas solo laborales (propietario, gerente, recepción, profesional o finanzas) sin membresía ni citas no cuentan; si además tienen membresía de cliente sí cuentan.</dd>
        <dt>Clientes nuevos</dt><dd>Altas con fecha de alta (<code>clientes.creado_en</code>) dentro del periodo. «Primera visita completada» es otro indicador y se informa por separado.</dd>
        <dt>Clientes activos</dt><dd>Al menos una visita completada en los últimos <?= (int)$dias ?> días hasta hoy. La ventana se cambia (30 a 365 días) en el formulario.</dd>
        <dt>Frecuencia</dt><dd>Visitas completadas por cliente dentro del periodo. La media divide las visitas entre los clientes que tuvieron al menos una.</dd>
        <dt>Recencia</dt><dd>Días entre hoy y la fecha de la última visita completada.</dd>
        <?php if ($analitica): ?><dt>Repetición y retención</dt><dd>Repetición del periodo: clientes con 2 o más visitas completadas en el periodo ÷ clientes con al menos una. Retención por cohorte: ver la tabla de cohortes (<?= (int)CrmService::VENTANA_RETENCION_DIAS ?> días). Solo cuentan citas completadas hasta hoy.</dd><?php endif; ?>
        <dt>Cancelaciones y no asistencia</dt><dd>Estados separados. Denominador: todas las citas de clientes con fecha dentro del periodo y hasta hoy (cualquier estado). Una no asistencia no es una cancelación.</dd>
        <?php if ($analitica): ?><dt>Gasto observado</dt><dd>Cobros reales (pagos completados o luego reembolsados, de cualquier proveedor distinto de la pasarela simulada) en su fecha de cobro, menos devoluciones en su fecha de reembolso. Una devolución de otro mes se resta en el mes de la devolución. Los pagos del proveedor «mock» se muestran aparte y no se suman. Se atribuye al titular de la cita. Solo visible con permiso de analítica.</dd>
        <dt>Ticket</dt><dd>Por <strong>cobro</strong>: cobros reales brutos del periodo ÷ número de cobros reales. No es por prestación ni por visita y no descuenta devoluciones.</dd><?php endif; ?>
        <dt>Fidelidad</dt><dd>Puntos vigentes = suma de movimientos registrados (10 por visita sincronizada, −100 por canje). Los canjes están pendientes o entregados. Los puntos se registran cuando el cliente consulta su espacio de bienestar, por lo que pueden ir por detrás de las visitas.</dd>
        <dt>Consentimiento</dt><dd>Estado de aceptación de promociones por correo y su fecha. El esquema actual no guarda la versión del texto aceptado, por eso no se muestra.</dd>
        <dt>Datos privados</dt><dd>Las respuestas de formularios y fichas privadas no se leen ni se muestran en este módulo, ni en pantalla ni en exportaciones.</dd>
    </dl>
</section>
<?php endif; ?>

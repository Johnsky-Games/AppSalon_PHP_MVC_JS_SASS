<?php
use Services\CrmService;

$modo = $panel['modo'];
$f = $panel['filtros'];
$res = $panel['resumen'] ?? [];
$analitica = !empty($acceso['analitica']);
$puedeExportar = !empty($acceso['exportar']);
$catalogos = $catalogos ?? ['servicios' => [], 'profesionales' => []];
$listado = $panel['listado'] ?? null;
$script = '<script src="/build/js/crm.js" defer></script>';
$rutaBase = '/admin/clientes/indicadores';
$pct = static fn(?float $v): string => $v === null ? 'Sin datos' : number_format($v, 1, '.', '') . ' %';
$entero = static fn($v): string => (string)(int)$v;
$fechaCorta = static fn($d): string => $d ? substr((string)$d, 0, 10) : '—';
/** Query string con los filtros vigentes; $extra reemplaza claves (p. ej. la página). */
$consulta = static fn(array $extra = []): string => CrmService::consulta($f, $extra);
/** Enlace «Ver listado» de una cifra: abre la lista real de esas personas con los filtros vigentes (solo con acceso nominal). */
$verListado = static function (string $indicador, int $n, string $texto = '') use ($modo, $f): string {
    if ($modo !== 'completo' || $n < 1) { return ''; }
    return '<a class="crm-kpi__listado" href="/admin/clientes/listado?' . s(CrmService::consulta($f, ['indicador' => $indicador, 'pagina' => null])) . '">Ver listado de ' . s($texto !== '' ? $texto : ((string)$n . ' ' . ($n === 1 ? 'persona' : 'personas'))) . '</a>';
};
$dias = (int)($f['dias_activo'] ?? CrmService::DIAS_ACTIVO_DEFECTO);
$hastaEf = $res['hasta_efectivo'] ?? ($f['hasta'] ?? '');
?>
<header class="ui-page-header">
    <div>
        <p class="ui-eyebrow">Relación con clientes</p>
        <h1 class="nombre-pagina"><?= $modo === 'propios' ? 'Mis clientes' : 'Indicadores de clientes' ?></h1>
        <p class="descripcion-pagina"><?php if ($modo === 'propios'): ?>Personas a las que has atendido. Solo contacto operativo y servicios realizados contigo.<?php elseif ($modo === 'analitica'): ?>Indicadores agregados de la base de clientes. No incluye datos personales individuales.<?php else: ?>Quiénes son, cuándo vinieron, qué prefieren y cómo cuidar la relación con ellos.<?php endif; ?></p>
    </div>
    <?php if ($modo !== 'propios'): ?>
    <div class="crm-exportar">
        <?php if (!empty($acceso['alta'])): ?><a class="boton" href="/admin/clientes/nuevo">Nuevo cliente</a><?php endif; ?>
        <?php if ($puedeExportar): ?><a class="boton ui-button-quiet" href="/admin/clientes/auditoria">Auditoría de exportaciones</a><?php endif; ?>
        <a class="boton ui-button-quiet" href="#como-se-calcula">Cómo se calcula</a>
    </div>
    <?php endif; ?>
</header>
<?php include __DIR__ . '/../templates/barra.php'; include __DIR__ . '/../templates/alertas.php'; ?>
<?php $seccionClientes = 'indicadores'; include __DIR__ . '/_secciones.php'; ?>

<?php if ($modo === 'propios'): ?>
    <?php include __DIR__ . '/_propios.php'; ?>
<?php else: ?>

<details class="crm-card crm-filtros-panel"<?= $errores ? ' open' : '' ?>>
    <summary><h2 class="crm-filtros-panel__titulo" id="crm-filtros-titulo"><?= $modo === 'completo' ? 'Buscar y filtrar' : 'Periodo del análisis' ?></h2></summary>
    <?php $panelHoy = $panel['hoy']; include __DIR__ . '/_formulario_filtros.php'; ?>
    <p class="crm-nota">Periodo analizado: <strong><?= s($f['desde']) ?></strong> a <strong><?= s($hastaEf) ?></strong> (nunca después de hoy, <?= s($panel['hoy']) ?>, hora de Guayaquil). <strong>Los indicadores y sus listados comparten los filtros seleccionados.</strong> Por defecto se analizan clientes externos.</p>
</details>
<?php $chips = $panel['filtros_activos']; $total = (int)($res['clientes_total'] ?? 0); $sustantivo = 'clientes'; include __DIR__ . '/_filtros_activos.php'; ?>

<section aria-labelledby="crm-ind-titulo" class="crm-seccion">
    <h2 id="crm-ind-titulo">Indicadores</h2>
    <div class="crm-kpis">
        <article class="crm-kpi">
            <h3 class="crm-kpi__titulo">Clientes totales</h3>
            <p class="crm-kpi__valor"><?= $entero($res['clientes_total']) ?></p>
            <p class="crm-kpi__nota">Membresías: <?= $entero($res['membresias']['activo']) ?> activas · <?= $entero($res['membresias']['suspendido']) ?> suspendidas · <?= $entero($res['membresias']['sin_registro']) ?> cuentas legadas sin registro. No incluye cuentas solo laborales.</p>
            <p class="crm-kpi__enlaces"><?= $verListado('totales', (int)$res['clientes_total']) ?> <?= $verListado('membresia_activo', (int)$res['membresias']['activo'], 'las activas') ?> <?= $verListado('membresia_suspendido', (int)$res['membresias']['suspendido'], 'las suspendidas') ?> <?= $verListado('membresia_sin_registro', (int)$res['membresias']['sin_registro'], 'las cuentas legadas') ?></p>
        </article>
        <article class="crm-kpi">
            <h3 class="crm-kpi__titulo">Altas en el periodo</h3>
            <p class="crm-kpi__valor"><?= $entero($res['nuevos']) ?></p>
            <p class="crm-kpi__nota">Clientes con fecha de alta dentro del periodo. Su primera visita completada ocurrió en el periodo para <?= $entero($res['primera_visita_en_rango']) ?> clientes (es otro dato: puede ser de una alta anterior).</p>
            <p class="crm-kpi__enlaces"><?= $verListado('altas', (int)$res['nuevos']) ?></p>
        </article>
        <article class="crm-kpi">
            <h3 class="crm-kpi__titulo">Clientes activos (<?= (int)$dias ?> días)</h3>
            <p class="crm-kpi__valor"><?= $entero($res['activos']) ?></p>
            <p class="crm-kpi__nota"><?= s($pct($res['pct_activos'])) ?> de <?= $entero($res['clientes_total']) ?> clientes tuvo al menos una visita completada en los últimos <?= (int)$dias ?> días hasta hoy.</p>
            <p class="crm-kpi__enlaces"><?= $verListado('activos', (int)$res['activos']) ?></p>
        </article>
        <article class="crm-kpi">
            <h3 class="crm-kpi__titulo">Visitas completadas</h3>
            <p class="crm-kpi__valor"><?= $entero($res['visitas_rango']) ?></p>
            <p class="crm-kpi__nota">En el periodo, de <?= $entero($res['con_visita_rango']) ?> clientes distintos. Frecuencia media: <?= $res['frecuencia_media'] === null ? 'sin datos' : s(number_format($res['frecuencia_media'], 2, '.', '')) . ' visitas por cliente con visita' ?>. Las canceladas y las no asistidas no son visitas.</p>
            <p class="crm-kpi__enlaces"><?= $verListado('con_visita', (int)$res['con_visita_rango'], 'los ' . $entero($res['con_visita_rango']) . ' clientes con visita') ?></p>
        </article>
    </div>
    <details class="gestion-detalle"><summary>Más indicadores de actividad y relación</summary>
    <div class="crm-kpis">
        <?php if ($analitica): ?>
        <article class="crm-kpi">
            <h3 class="crm-kpi__titulo">Repetición en el periodo</h3>
            <p class="crm-kpi__valor"><?= s($pct($res['pct_repiten'])) ?></p>
            <p class="crm-kpi__nota"><?= $entero($res['repiten_rango']) ?> de <?= $entero($res['con_visita_rango']) ?> clientes con visita en el periodo tuvieron dos o más visitas completadas en él.</p>
            <p class="crm-kpi__enlaces"><?= $verListado('repiten', (int)$res['repiten_rango']) ?></p>
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
            <p class="crm-kpi__nota">Clientes que aceptaron recibir promociones por correo, de <?= $entero($res['clientes_total']) ?>. No aceptar es el estado por defecto. De ellos, <?= $entero($res['con_consentimiento_vigente']) ?> aceptaron la versión vigente del texto y <?= $entero($res['con_consentimiento_anterior']) ?> una versión anterior (esos no entran en la lista comercial hasta que reconfirmen).</p>
            <p class="crm-kpi__enlaces"><?= $verListado('consentimiento_si', (int)$res['con_consentimiento'], 'las ' . $entero($res['con_consentimiento']) . ' otorgadas') ?> <?= $verListado('consentimiento_vigente', (int)$res['con_consentimiento_vigente'], 'las del texto vigente') ?> <?= $verListado('consentimiento_anterior', (int)$res['con_consentimiento_anterior'], 'las de un texto anterior') ?></p>
        </article>
        <article class="crm-kpi">
            <h3 class="crm-kpi__titulo">Suscripción a comunicaciones comerciales</h3>
            <p class="crm-kpi__valor"><?= $entero($res['comunicaciones']['otorgado']) ?></p>
            <p class="crm-kpi__nota">Otorgado: <?= $entero($res['comunicaciones']['otorgado']) ?> · Revocado (retiraron su consentimiento): <?= $entero($res['comunicaciones']['revocado']) ?> · Sin decisión (nunca decidieron): <?= $entero($res['comunicaciones']['sin_decision']) ?>. Este indicador corresponde al consentimiento para recibir promociones por correo; los contratos comerciales de sesiones se gestionan por separado en Suscripciones.</p>
            <p class="crm-kpi__enlaces"><?= $verListado('consentimiento_si', (int)$res['comunicaciones']['otorgado'], 'las otorgadas') ?> <?= $verListado('comunicaciones_revocado', (int)$res['comunicaciones']['revocado'], 'las revocadas') ?> <?= $verListado('comunicaciones_sin_decision', (int)$res['comunicaciones']['sin_decision'], 'las sin decisión') ?></p>
        </article>
        <article class="crm-kpi">
            <h3 class="crm-kpi__titulo">Cuentas sin confirmar</h3>
            <p class="crm-kpi__valor"><?= $entero($res['cuenta_sin_confirmar']) ?></p>
            <p class="crm-kpi__nota">Clientes que aún no confirmaron su correo (<?= $entero($res['cuenta_confirmada']) ?> sí lo hicieron). Sin confirmar no entran en la lista comercial.</p>
            <p class="crm-kpi__enlaces"><?= $verListado('cuenta_sin_confirmar', (int)$res['cuenta_sin_confirmar']) ?></p>
        </article>
    </div>

    </details>

    <?php if ($analitica && is_array($res['gasto'])): $g = $res['gasto']; ?>
    <h3 class="crm-subtitulo">Dinero del periodo (solo analítica)</h3>
    <div class="crm-kpis">
        <article class="crm-kpi crm-kpi--dinero">
            <h3 class="crm-kpi__titulo">Gasto neto real</h3>
            <p class="crm-kpi__valor"><?= s(CrmService::dinero($g['neto_real'])) ?></p>
            <p class="crm-kpi__nota">Cobros reales <?= s(CrmService::dinero($g['cobros_real'])) ?> menos devoluciones <?= s(CrmService::dinero($g['devoluciones_real'])) ?>. Cada cobro cuenta en su fecha de cobro y cada devolución en su fecha de reembolso, aunque sea de otro mes.</p>
        </article>
        <article class="crm-kpi crm-kpi--dinero">
            <h3 class="crm-kpi__titulo">Saldo pendiente de cobro</h3>
            <p class="crm-kpi__valor"><?= s(CrmService::dinero((int)$res['saldo_total'])) ?></p>
            <p class="crm-kpi__nota">De <?= $entero($res['con_saldo']) ?> clientes con citas atendidas aún no cobradas (total de sus servicios menos cobros reales confirmados). No depende del periodo: es lo que está por cobrar hoy.</p>
            <p class="crm-kpi__enlaces"><?= $verListado('con_saldo', (int)$res['con_saldo']) ?></p>
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
            <caption class="crm-sr">Número de clientes por segmento (con los filtros activos)</caption>
            <thead><tr><th scope="col">Segmento</th><th scope="col">Clientes</th><?php if ($modo === 'completo'): ?><th scope="col">Listado</th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($panel['segmentos'] as $sg): ?>
                <tr><th scope="row"><?= s($sg['etiqueta']) ?></th><td class="ui-table-number"><?= $entero($sg['valor']) ?></td><?php if ($modo === 'completo'): ?><td><?php if ((int)$sg['valor'] > 0): ?><a href="/admin/clientes/listado?<?= s($consulta(['segmento' => $sg['clave'], 'pagina' => null, 'indicador' => null])) ?>">Ver listado: <?= $entero($sg['valor']) ?> <?= s(mb_strtolower($sg['etiqueta'])) ?><span class="crm-sr"> (abre la lista de clientes de este segmento)</span></a><?php else: ?><span class="crm-vacio-celda">Ninguno con estos filtros</span><?php endif; ?></td><?php endif; ?></tr>
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

<details class="crm-seccion crm-definiciones gestion-detalle" id="como-se-calcula">
    <summary>Cómo se calcula</summary>
    <p>Todas las fechas «de hoy» se calculan en la zona America/Guayaquil. Una <strong>visita</strong> es una cita en estado <em>completada</em> con fecha hasta hoy; las citas canceladas, no asistidas, confirmadas o en servicio no son visitas. Nada aquí es una predicción ni un valor de vida estimado.</p>
    <dl class="crm-dl">
        <dt>Clientes totales</dt><dd>Personas con membresía de cliente, más cuentas legadas sin membresía cuyo rol efectivo es cliente, más cuentas con alguna cita. Por defecto se excluye a todo el equipo. El filtro de vínculo permite consultar a personas del equipo que también tienen perfil de cliente; nunca incluye cuentas solo laborales sin membresía ni citas.</dd>
        <dt>Clientes nuevos</dt><dd>Altas con fecha de alta (<code>clientes.creado_en</code>) dentro del periodo. «Primera visita completada» es otro indicador y se informa por separado.</dd>
        <dt>Clientes activos</dt><dd>Al menos una visita completada en los últimos <?= (int)$dias ?> días hasta hoy. La ventana se cambia (30 a 365 días) en el formulario.</dd>
        <dt>Frecuencia</dt><dd>Visitas completadas por cliente dentro del periodo. La media divide las visitas entre los clientes que tuvieron al menos una.</dd>
        <dt>Recencia</dt><dd>Días entre hoy y la fecha de la última visita completada.</dd>
        <?php if ($analitica): ?><dt>Repetición y retención</dt><dd>Repetición del periodo: clientes con 2 o más visitas completadas en el periodo ÷ clientes con al menos una. Retención por cohorte: ver la tabla de cohortes (<?= (int)CrmService::VENTANA_RETENCION_DIAS ?> días). Solo cuentan citas completadas hasta hoy.</dd><?php endif; ?>
        <dt>Cancelaciones y no asistencia</dt><dd>Estados separados. Denominador: todas las citas de clientes con fecha dentro del periodo y hasta hoy (cualquier estado). Una no asistencia no es una cancelación.</dd>
        <?php if ($analitica): ?><dt>Gasto observado</dt><dd>Cobros reales (pagos completados o luego reembolsados, de cualquier proveedor distinto de la pasarela simulada) en su fecha de cobro, menos devoluciones en su fecha de reembolso. Una devolución de otro mes se resta en el mes de la devolución. Los pagos del proveedor «mock» se muestran aparte y no se suman. Se atribuye al titular de la cita. Solo visible con permiso de analítica.</dd>
        <dt>Ticket</dt><dd>Por <strong>cobro</strong>: cobros reales brutos del periodo ÷ número de cobros reales. No es por prestación ni por visita y no descuenta devoluciones.</dd><?php endif; ?>
        <dt>Fidelidad</dt><dd>Puntos vigentes = suma de movimientos registrados (10 por visita sincronizada, −100 por canje). Los canjes están pendientes o entregados. Los puntos se registran cuando el cliente consulta su espacio de bienestar, por lo que pueden ir por detrás de las visitas.</dd>
        <dt>Consentimiento</dt><dd>Estado de aceptación de promociones por correo, su fecha y la <strong>versión del texto</strong> que la persona vio al decidir. «Aceptó una versión anterior» marca a quienes aceptaron un texto que ya no es el vigente: siguen registrados tal como lo dieron, pero la lista comercial y las campañas solo incluyen la versión vigente hasta que reconfirmen. La versión 1 es la histórica: se asignó a los consentimientos existentes antes del versionado, sin inventar su fecha.</dd>
        <dt>Datos privados</dt><dd>Las respuestas de formularios y fichas privadas no se leen ni se muestran en este módulo, ni en pantalla ni en exportaciones.</dd>
    </dl>
</details>
<?php endif; ?>

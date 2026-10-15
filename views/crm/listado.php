<?php
use Services\CrmService;

/** @var array $datos @var array $acceso @var array $catalogos @var array $errores */
$modo = $datos['modo'];
$f = $datos['filtros'];
$rutaBase = '/admin/clientes/listado';
$analitica = !empty($acceso['analitica']);
$puedeExportar = !empty($acceso['exportar']) && $modo === 'completo';
$listado = $datos['listado'];
$indicador = $datos['indicador'] ?? null;
$titulo = $indicador['etiqueta'] ?? ($modo === 'propios' ? 'Mis clientes' : 'Listado de clientes');
$consulta = static fn(array $extra = []): string => CrmService::consulta($f, $extra);
$volver = '/admin/clientes' . (($q = CrmService::consulta(CrmService::sinIndicador($f), ['pagina' => null])) !== '' ? '?' . $q : '');
$hoy = $datos['hoy'];
?>
<header class="ui-page-header">
    <div>
        <p class="ui-eyebrow">Relación con clientes · Listado</p>
        <h1 class="nombre-pagina" id="crm-listado-titulo"><?= s($titulo) ?></h1>
        <p class="descripcion-pagina"><?php if ($indicador): ?><?= s($indicador['explica']) ?> Es la lista de las personas que forman esa cifra en <a href="<?= s($volver) ?>">el panel de clientes</a>.<?php elseif ($modo === 'propios'): ?>Personas a las que has atendido. Solo contacto operativo y servicios realizados contigo.<?php else: ?>Las personas que cumplen los filtros elegidos: la misma lista que explica los indicadores y la que se descarga.<?php endif; ?></p>
    </div>
    <div class="crm-exportar">
        <a class="boton ui-button-quiet" href="<?= s($volver) ?>">Volver a clientes</a>
    </div>
</header>
<?php include __DIR__ . '/../templates/barra.php'; include __DIR__ . '/../templates/alertas.php'; ?>

<?php if ($modo === 'propios'): ?>
    <?php $panel = $datos; include __DIR__ . '/_propios.php'; ?>
<?php else: ?>
<details class="crm-card crm-filtros-panel"<?= $errores || !$datos['filtros_activos'] ? ' open' : '' ?>>
    <summary><h2 class="crm-filtros-panel__titulo" id="crm-filtros-titulo">Filtros</h2></summary>
    <?php $panelHoy = $hoy; include __DIR__ . '/_formulario_filtros.php'; ?>
</details>

<section class="crm-seccion" aria-labelledby="crm-listado-titulo">
    <?php $chips = $datos['filtros_activos']; $total = (int)$listado['total']; $sustantivo = 'clientes'; include __DIR__ . '/_filtros_activos.php'; ?>
    <?php if (!empty($listado['totales'])): $t = $listado['totales']; ?>
    <p class="crm-nota crm-nota--ancha" id="crm-totales">Totales de este listado (todas las páginas): <strong><?= s((string)(int)$t['clientes']) ?></strong> clientes · <strong><?= s((string)(int)$t['visitas']) ?></strong> visitas completadas en el periodo (<?= s($f['desde']) ?> a <?= s($datos['hasta_efectivo']) ?>)<?php if (($t['gasto_neto'] ?? null) !== null): ?> · gasto neto real <strong><?= s(CrmService::dinero((int)$t['gasto_neto'])) ?></strong> · saldo pendiente <strong><?= s(CrmService::dinero((int)$t['saldo'])) ?></strong><?php endif; ?>.</p>
    <?php endif; ?>

    <?php if ($listado['total'] === 0): ?>
        <p class="crm-vacio" role="status">Ningún cliente cumple estos filtros. <a href="<?= s($rutaBase) ?>">Limpiar filtros</a> o <a href="<?= s($volver) ?>">volver al panel de clientes</a>.</p>
    <?php else: ?>
        <?php $descripcion = $titulo; include __DIR__ . '/_tabla_clientes.php'; ?>
        <?php $pag = $listado; $noun = 'clientes'; include __DIR__ . '/_paginacion.php'; ?>
    <?php endif; ?>
    <?php include __DIR__ . '/_descargas.php'; ?>
</section>
<?php endif; ?>

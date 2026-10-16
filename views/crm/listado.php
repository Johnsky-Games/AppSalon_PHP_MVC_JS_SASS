<?php
use Services\CrmService;

/** @var array $datos @var array $acceso @var array $catalogos @var array $errores */
$modo = $datos['modo'];
$f = $datos['filtros'];
$directorio = !empty($directorio);
$compacto = $directorio;
$rutaBase = $directorio ? '/admin/clientes' : '/admin/clientes/listado';
$analitica = !empty($acceso['analitica']);
$puedeExportar = !empty($acceso['exportar']) && $modo === 'completo';
$listado = $datos['listado'];
$indicador = $datos['indicador'] ?? null;
$titulo = $indicador['etiqueta'] ?? ($modo === 'propios' ? 'Mis clientes' : ($directorio ? 'Base de clientes' : 'Listado de clientes'));
$consulta = static fn(array $extra = []): string => CrmService::consulta($f, $extra);
$volver = '/admin/clientes/indicadores' . (($q = CrmService::consulta(CrmService::sinIndicador($f), ['pagina' => null])) !== '' ? '?' . $q : '');
$hoy = $datos['hoy'];
?>
<header class="ui-page-header">
    <div>
        <p class="ui-eyebrow">Relación con clientes</p>
        <h1 class="nombre-pagina" id="crm-listado-titulo"><?= s($titulo) ?></h1>
        <p class="descripcion-pagina"><?php if ($indicador): ?><?= s($indicador['explica']) ?> Es la lista de las personas que forman esa cifra en <a href="<?= s($volver) ?>">los indicadores de clientes</a>.<?php elseif ($modo === 'propios'): ?>Personas a las que has atendido. Solo contacto operativo y servicios realizados contigo.<?php else: ?>Busca a una persona, consulta su ficha y gestiona su relación con el negocio.<?php endif; ?></p>
    </div>
    <div class="crm-exportar">
        <?php if (!empty($acceso['alta'])): ?><a class="boton" href="/admin/clientes/nuevo">Nuevo cliente</a><?php endif; ?>
        <?php if (!$directorio && $modo !== 'propios'): ?><a class="boton ui-button-quiet" href="<?= s($volver) ?>">Volver a indicadores</a><?php endif; ?>
    </div>
</header>
<?php include __DIR__ . '/../templates/barra.php'; include __DIR__ . '/../templates/alertas.php'; ?>
<?php $seccionClientes = 'base'; include __DIR__ . '/_secciones.php'; ?>

<?php if ($modo === 'propios'): ?>
    <?php $panel = $datos; include __DIR__ . '/_propios.php'; ?>
<?php else: ?>
<?php if ($directorio): ?>
<form class="gestion-busqueda" method="GET" action="/admin/clientes" role="search">
    <?php parse_str(CrmService::consulta($f, ['q' => null, 'pagina' => null, 'vinculo' => null]), $ocultos); foreach ($ocultos as $clave => $valor): ?>
    <input type="hidden" name="<?= s($clave) ?>" value="<?= s($valor) ?>">
    <?php endforeach; ?>
    <div class="campo"><label for="base-q">Buscar cliente</label><input id="base-q" type="search" name="q" maxlength="80" value="<?= s($f['q']) ?>" placeholder="Nombre, correo o teléfono" autocomplete="off"></div>
    <div class="campo"><label for="base-vinculo">Mostrar</label><select id="base-vinculo" name="vinculo"><?php foreach (CrmService::VINCULOS as $clave => $etiqueta): ?><option value="<?= s($clave) ?>"<?= ($f['vinculo'] ?? 'externo') === $clave ? ' selected' : '' ?>><?= s($etiqueta) ?></option><?php endforeach; ?></select></div>
    <button class="boton" type="submit">Buscar</button>
</form>
<p class="ui-form-help">Las cuentas del equipo se consultan por separado.<?php if (puede('rh.gestionar')): ?> <a href="/admin/recursos-humanos">Ir a Recursos Humanos</a>.<?php endif; ?></p>
<?php endif; ?>
<details class="crm-card crm-filtros-panel"<?= $errores ? ' open' : '' ?>>
    <summary><h2 class="crm-filtros-panel__titulo" id="crm-filtros-titulo">Filtros avanzados</h2></summary>
    <?php $panelHoy = $hoy; include __DIR__ . '/_formulario_filtros.php'; ?>
</details>

<section class="crm-seccion" aria-labelledby="crm-listado-titulo">
    <?php $chips = $datos['filtros_activos']; $total = (int)$listado['total']; $sustantivo = 'clientes'; include __DIR__ . '/_filtros_activos.php'; ?>
    <?php if (!$directorio && !empty($listado['totales'])): $t = $listado['totales']; ?>
    <p class="crm-nota crm-nota--ancha" id="crm-totales">Totales de este listado (todas las páginas): <strong><?= s((string)(int)$t['clientes']) ?></strong> clientes · <strong><?= s((string)(int)$t['visitas']) ?></strong> visitas completadas en el periodo (<?= s($f['desde']) ?> a <?= s($datos['hasta_efectivo']) ?>)<?php if (($t['gasto_neto'] ?? null) !== null): ?> · gasto neto real <strong><?= s(CrmService::dinero((int)$t['gasto_neto'])) ?></strong> · saldo pendiente <strong><?= s(CrmService::dinero((int)$t['saldo'])) ?></strong><?php endif; ?>.</p>
    <?php endif; ?>

    <?php if ($listado['total'] === 0): ?>
        <p class="crm-vacio" role="status">Ningún cliente cumple estos filtros. <a href="<?= s($rutaBase) ?>">Limpiar filtros</a> o <a href="<?= s($volver) ?>">volver al panel de clientes</a>.</p>
    <?php else: ?>
        <?php $descripcion = $titulo; include __DIR__ . '/_tabla_clientes.php'; ?>
        <?php $pag = $listado; $noun = 'clientes'; include __DIR__ . '/_paginacion.php'; ?>
    <?php endif; ?>
    <?php if ($puedeExportar): ?><details class="gestion-detalle"><summary>Descargar este listado</summary><?php include __DIR__ . '/_descargas.php'; ?></details><?php endif; ?>
</section>
<?php endif; ?>

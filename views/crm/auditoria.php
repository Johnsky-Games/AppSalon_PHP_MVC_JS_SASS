<?php
use Services\CrmAuditoriaService;

/** @var array $filtros @var array $pag */
$rutaBase = '/admin/clientes/auditoria';
$claves = ['resultado', 'tipo', 'actor_id', 'desde', 'hasta', 'por_pagina'];
$consulta = static function (array $extra = []) use ($filtros, $claves): string {
    $datos = [];
    foreach ($claves as $k) { if (isset($filtros[$k]) && $filtros[$k] !== '' && $filtros[$k] !== null) { $datos[$k] = $filtros[$k]; } }
    foreach ($extra as $k => $v) { if ($v === null) { unset($datos[$k]); } else { $datos[$k] = $v; } }
    return http_build_query($datos);
};
$zona = new \DateTimeZone(CrmAuditoriaService::TIMEZONE);
$fechaUtc = static fn($d): string => $d ? substr((string)$d, 0, 16) . ' UTC' : '—';
$fechaLocal = static function ($d) use ($zona): string {
    if (!$d) { return ''; }
    return (new \DateTimeImmutable((string)$d, new \DateTimeZone('UTC')))->setTimezone($zona)->format('Y-m-d H:i') . ' hora de Guayaquil';
};
$etiquetaRol = ['propietario' => 'Propietario', 'gerente' => 'Gerencia', 'recepcion' => 'Recepción', 'profesional' => 'Profesional', 'finanzas' => 'Finanzas', 'cliente' => 'Cliente'];
/** Resumen legible de los filtros guardados; la búsqueda aparece solo como «sí/no, N caracteres». */
$resumenFiltros = static function (array $f): string {
    $partes = [];
    if (!empty($f['q_presente'])) { $partes[] = 'búsqueda de texto (' . (int)($f['q_longitud'] ?? 0) . ' caracteres, no se guarda el texto)'; }
    foreach (['desde' => 'Periodo desde', 'hasta' => 'hasta', 'alta_desde' => 'Alta desde', 'alta_hasta' => 'Alta hasta', 'profesional_id' => 'Profesional n.º', 'servicio_id' => 'Servicio n.º',
        'segmento' => 'Segmento', 'membresia' => 'Membresía', 'consentimiento' => 'Consentimiento', 'dias_activo' => 'Días activo'] as $k => $et) {
        if (isset($f[$k]) && $f[$k] !== '' && $f[$k] !== null) { $partes[] = $et . ' ' . $f[$k]; }
    }
    return $partes ? implode(' · ', $partes) : 'Sin filtros adicionales';
};
?>
<header class="ui-page-header">
    <div>
        <p class="ui-eyebrow">Relación con clientes</p>
        <h1 class="nombre-pagina">Auditoría de exportaciones</h1>
        <p class="descripcion-pagina">Quién exportó datos de clientes, cuándo y con qué filtros. Es un registro de solo lectura: no guarda lo exportado ni datos personales, y nadie puede editarlo ni borrarlo desde la aplicación.</p>
    </div>
    <a class="boton ui-button-quiet" href="/admin/clientes">Volver a clientes</a>
</header>
<?php include __DIR__ . '/../templates/barra.php'; include __DIR__ . '/../templates/alertas.php'; ?>

<section class="crm-card" aria-labelledby="aud-filtros-titulo">
    <h2 id="aud-filtros-titulo">Filtrar el registro</h2>
    <form class="crm-filtros" action="<?= s($rutaBase) ?>" method="GET" role="search">
        <div class="campo"><label for="aud-resultado">Resultado</label><select id="aud-resultado" name="resultado"><option value="">Todos</option><?php foreach (CrmAuditoriaService::RESULTADOS as $k => $et): ?><option value="<?= s($k) ?>"<?= ($filtros['resultado'] ?? null) === $k ? ' selected' : '' ?>><?= s($et) ?></option><?php endforeach; ?></select></div>
        <div class="campo"><label for="aud-tipo">Tipo</label><select id="aud-tipo" name="tipo"><option value="">Todos</option><?php foreach (CrmAuditoriaService::TIPOS as $k => $et): ?><option value="<?= s($k) ?>"<?= ($filtros['tipo'] ?? null) === $k ? ' selected' : '' ?>><?= s($et) ?></option><?php endforeach; ?></select></div>
        <div class="campo"><label for="aud-actor">N.º de usuario</label><input id="aud-actor" name="actor_id" type="text" inputmode="numeric" maxlength="10" pattern="[0-9]*" value="<?= s((string)($filtros['actor_id'] ?? '')) ?>" autocomplete="off"></div>
        <div class="campo"><label for="aud-desde">Desde</label><input id="aud-desde" name="desde" type="date" value="<?= s((string)($filtros['desde'] ?? '')) ?>"></div>
        <div class="campo"><label for="aud-hasta">Hasta</label><input id="aud-hasta" name="hasta" type="date" value="<?= s((string)($filtros['hasta'] ?? '')) ?>"></div>
        <div class="campo"><label for="aud-pp">Por página</label><select id="aud-pp" name="por_pagina"><?php foreach (CrmAuditoriaService::POR_PAGINA as $n): ?><option value="<?= (int)$n ?>"<?= (int)$filtros['por_pagina'] === $n ? ' selected' : '' ?>><?= (int)$n ?></option><?php endforeach; ?></select></div>
        <div class="crm-filtros__acciones"><button class="boton" type="submit">Aplicar</button><a class="boton ui-button-quiet" href="<?= s($rutaBase) ?>">Restablecer</a></div>
    </form>
    <p class="crm-nota">Las fechas del filtro son días de Guayaquil. Cada fila muestra la hora en UTC (la que guarda la base) y su equivalente local.</p>
</section>

<section class="crm-seccion" aria-labelledby="aud-lista-titulo">
    <h2 id="aud-lista-titulo">Registros</h2>
    <?php if ((int)$pag['total'] === 0): ?>
        <p class="crm-vacio" role="status">No hay exportaciones registradas con estos filtros. Cada exportación del CRM (exitosa, denegada o fallida) aparecerá aquí.</p>
    <?php else: ?>
    <div class="crm-tabla" tabindex="0" role="region" aria-label="Registro de exportaciones; desplaza horizontalmente para ver todas las columnas">
        <table class="crm-tabla-auditoria">
            <caption class="crm-sr">Exportaciones, <?= s((string)$pag['primero']) ?> a <?= s((string)$pag['ultimo']) ?> de <?= s((string)$pag['total']) ?>, de la más reciente a la más antigua</caption>
            <thead><tr>
                <th scope="col">Cuándo</th><th scope="col">Quién</th><th scope="col">Tipo</th><th scope="col">Resultado</th>
                <th scope="col">Filas · columnas</th><th scope="col">Filtros</th><th scope="col">Consentimiento aplicado</th>
            </tr></thead>
            <tbody>
            <?php foreach ($pag['filas'] as $r): ?>
                <tr>
                    <th scope="row"><?= s($fechaUtc($r['ocurrido_en'])) ?><small><?= s($fechaLocal($r['ocurrido_en'])) ?></small></th>
                    <td><?php if ($r['actor_usuario_id'] === null): ?>Sin identificar<?php else: ?><?= s(trim((string)$r['actor_nombre'] . ' ' . (string)$r['actor_apellido']) ?: 'Cuenta n.º ' . $r['actor_usuario_id']) ?><small>N.º <?= (int)$r['actor_usuario_id'] ?> · <?= s($etiquetaRol[$r['actor_rol'] ?? ''] ?? ($r['actor_rol'] ?: 'rol desconocido')) ?></small><?php endif; ?></td>
                    <td><?= s(CrmAuditoriaService::TIPOS[$r['tipo']] ?? $r['tipo']) ?><small>Formato <?= s(strtoupper((string)$r['formato'])) ?></small></td>
                    <td><span class="crm-etiqueta crm-res--<?= s($r['resultado']) ?>"><?= s(CrmAuditoriaService::RESULTADOS[$r['resultado']] ?? $r['resultado']) ?></span><?php if ($r['motivo']): ?><small><?= s(str_replace('_', ' ', (string)$r['motivo'])) ?></small><?php endif; ?></td>
                    <td class="ui-table-number"><?php if ($r['filas'] === null): ?>—<?php else: ?><?= (int)$r['filas'] ?> filas<small><?= (int)$r['columnas_n'] ?> columnas<?= (int)$r['truncado'] === 1 ? ' · truncada' : '' ?></small><?php endif; ?>
                        <?php if ($r['columnas']): ?><details class="crm-datos"><summary>Columnas</summary><ul class="crm-lista-simple"><?php foreach ($r['columnas'] as $col): ?><li><?= s($col) ?></li><?php endforeach; ?></ul></details><?php endif; ?></td>
                    <td><?= $r['filtros'] ? s($resumenFiltros($r['filtros'])) : '—' ?></td>
                    <td><?php if ($r['consentimiento_version_id'] === null): ?>—<?php else: ?>Versión <?= (int)$r['consentimiento_version'] ?><small><?= $r['consentimiento_politica'] === 'solo_version_vigente' ? 'Solo consentimientos de la versión vigente' : 'Informativo: sin filtrar por consentimiento' ?></small><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php $noun = 'registros'; include __DIR__ . '/_paginacion.php'; ?>
    <?php endif; ?>
    <p class="crm-nota">Este registro es solo de anexado: la aplicación no tiene ninguna acción para editarlo o borrarlo y la base de datos rechaza esos cambios. Las exportaciones denegadas por falta de permiso también quedan anotadas.</p>
</section>

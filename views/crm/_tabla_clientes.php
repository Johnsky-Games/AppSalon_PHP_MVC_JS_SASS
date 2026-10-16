<?php
/**
 * Tabla de clientes con orden por columna (enlaces; sin JavaScript). La comparten /admin/clientes y /admin/clientes/listado.
 * Espera $listado (filas + paginación + totales), $f, $rutaBase, $analitica y $descripcion (texto del caption).
 */
use Services\CrmService;

$entero = static fn($v): string => (string)(int)$v;
$compacto = !empty($compacto);
$fechaCorta = static fn($d): string => $d ? substr((string)$d, 0, 10) : '—';
/** Cabecera ordenable: enlace que alterna la dirección y marca aria-sort. */
$cabeceraOrden = static function (string $clave, string $texto) use ($f, $rutaBase): string {
    $activa = ($f['orden'] ?? 'nombre') === $clave;
    $dir = ($f['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
    $siguiente = $activa && $dir === 'asc' ? 'desc' : 'asc';
    $aria = $activa ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none';
    $flecha = $activa ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
    $href = $rutaBase . '?' . CrmService::consulta($f, ['orden' => $clave, 'dir' => $siguiente, 'pagina' => null]);
    return '<th scope="col" aria-sort="' . $aria . '"><a class="crm-orden" href="' . s($href) . '">' . s($texto) . '<span aria-hidden="true">' . $flecha . '</span><span class="crm-sr"> (ordenar ' . ($siguiente === 'asc' ? 'ascendente' : 'descendente') . ')</span></a></th>';
};
?>
<div class="crm-tabla" tabindex="0" role="region" aria-label="Listado de clientes; desplaza horizontalmente para ver todas las columnas">
    <table class="crm-tabla-clientes<?= $compacto ? ' crm-tabla-clientes--compacta' : '' ?>">
        <caption class="crm-sr"><?= s($descripcion ?? 'Clientes') ?>, <?= s((string)$listado['primero']) ?> a <?= s((string)$listado['ultimo']) ?> de <?= s((string)$listado['total']) ?></caption>
        <thead><tr>
            <?= $cabeceraOrden('nombre', 'Cliente') ?>
            <th scope="col">Contacto</th>
            <?= $cabeceraOrden('alta', 'Cuenta y membresía') ?>
            <?php if (!$compacto): ?><th scope="col">Comunicaciones comerciales</th>
            <th scope="col">Segmento</th>
            <?= $cabeceraOrden('visitas', 'Visitas del periodo') ?><?php endif; ?>
            <?= $cabeceraOrden('ultima', 'Última visita') ?>
            <?= $cabeceraOrden('proxima', 'Próxima cita') ?>
            <?php if ($analitica && !$compacto): ?><?= $cabeceraOrden('gasto', 'Gasto neto real') ?><?= $cabeceraOrden('saldo', 'Saldo pendiente') ?><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($listado['filas'] as $r): ?>
            <tr>
                <th scope="row"><a href="/admin/clientes/ficha?id=<?= (int)$r['id'] ?>"><?= s(trim($r['nombre'] . ' ' . $r['apellido'])) ?></a><?php if ($r['rol_efectivo'] !== 'cliente'): ?><small>También tiene acceso laboral</small><?php endif; ?></th>
                <td><?= s($r['email']) ?><small><?= s((string)$r['telefono']) ?></small></td>
                <td>Cuenta <?= (int)$r['confirmado'] === 1 ? 'confirmada' : '<strong>sin confirmar</strong>' ?><small>Membresía: <?= s(CrmService::MEMBRESIAS[$r['membresia']] ?? $r['membresia']) ?> · alta <?= s($fechaCorta($r['alta'])) ?></small></td>
                <?php if (!$compacto): ?>
                <td><?= s(CrmService::textoComunicaciones($r)) ?><?php if ((int)$r['consentimiento_anterior'] === 1): ?> <span class="crm-etiqueta crm-etiqueta--aviso" title="Aceptó una versión del texto que ya no está vigente">Versión anterior (v<?= (int)$r['consentimiento_version'] ?>)</span><?php elseif ((int)$r['consentimiento'] === 1 && (int)$r['consentimiento_version_historica'] === 1): ?><small>Versión 1 · histórica</small><?php endif; ?></td>
                <td><span class="crm-etiqueta crm-seg--<?= s($r['segmento']) ?>"><?= s(CrmService::SEGMENTOS[$r['segmento']] ?? $r['segmento']) ?></span></td>
                <td class="ui-table-number"><?= $entero($r['visitas_rango']) ?><small>Total: <?= $entero($r['visitas_total']) ?></small></td>
                <?php endif; ?>
                <td class="ui-table-date"><?= $r['ultima_visita'] === null ? 'Sin visitas' : s($r['ultima_visita']) ?><?php if ($r['recencia'] !== null): ?><small>hace <?= $entero($r['recencia']) ?> días</small><?php endif; ?></td>
                <td class="ui-table-date"><?= $r['proxima_cita'] === null ? 'Sin cita futura' : s($fechaCorta($r['proxima_cita'])) ?></td>
                <?php if ($analitica && !$compacto): ?>
                <td class="ui-table-number"><?= s(CrmService::dinero((int)$r['cobros_real'] - (int)$r['dev_real'])) ?><?php if ((int)$r['cobros_sim'] !== 0 || (int)$r['dev_sim'] !== 0): ?><small>Simulado: <?= s(CrmService::dinero((int)$r['cobros_sim'] - (int)$r['dev_sim'])) ?></small><?php endif; ?></td>
                <td class="ui-table-number"><?= (int)$r['saldo'] > 0 ? s(CrmService::dinero((int)$r['saldo'])) . '<small>' . $entero($r['citas_saldo']) . ' ' . ((int)$r['citas_saldo'] === 1 ? 'cita' : 'citas') . '</small>' : '—' ?></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

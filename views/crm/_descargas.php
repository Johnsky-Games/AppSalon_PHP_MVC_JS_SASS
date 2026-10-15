<?php
/**
 * Botones de descarga CSV y XLSX del MISMO universo filtrado, con los totales y el aviso de truncado ANTES de descargar.
 * La autorización real se vuelve a comprobar en el servidor en cada descarga; esto solo evita ofrecer lo que no se puede usar.
 * Espera $f, $listado (con 'totales' y 'comercial_total'), $puedeExportar.
 */
use Services\CrmService;

if (empty($puedeExportar)) { return; }
$t = $listado['totales'] ?? ['clientes' => (int)$listado['total']];
$max = CrmService::EXPORT_MAX_FILAS;
$excede = (int)$t['clientes'] > $max;
$comercial = isset($listado['comercial_total']) ? (int)$listado['comercial_total'] : null;
$enlace = static fn(string $tipo, string $formato): string => '/admin/clientes/exportar?' . CrmService::consulta($f, ['tipo' => $tipo, 'formato' => $formato, 'pagina' => null]);
?>
<div class="crm-descargas" role="group" aria-label="Descargar este listado">
    <h3 class="crm-subtitulo">Descargar este listado</h3>
    <p class="crm-nota crm-nota--ancha">El archivo contiene exactamente los <strong><?= s((string)(int)$t['clientes']) ?></strong> clientes de este listado, con los mismos filtros<?php if (isset($t['visitas'])): ?>, y una fila final «Total» con las mismas cifras de arriba (<?= s((string)(int)$t['visitas']) ?> visitas del periodo<?php if (($t['gasto_neto'] ?? null) !== null): ?>, gasto neto real <?= s(CrmService::dinero((int)$t['gasto_neto'])) ?>, saldo pendiente <?= s(CrmService::dinero((int)$t['saldo'])) ?><?php endif; ?>)<?php endif; ?>. Nunca incluye respuestas de formularios.</p>
    <?php if ($excede): ?>
    <p class="crm-aviso" role="alert"><strong>Exportación parcial.</strong> Este filtro tiene <?= s((string)(int)$t['clientes']) ?> clientes y cada archivo admite hasta <?= s((string)$max) ?>. Se descargarán los primeros <?= s((string)$max) ?>, el nombre del archivo terminará en «_parcial» y su última fila lo avisará. Afina los filtros para obtener el resto.</p>
    <?php endif; ?>
    <div class="crm-exportar">
        <a class="boton" data-crm-descarga href="<?= s($enlace('clientes', 'csv')) ?>">Exportar clientes (CSV)</a>
        <a class="boton" data-crm-descarga href="<?= s($enlace('clientes', 'xlsx')) ?>">Exportar clientes (Excel XLSX)</a>
        <a class="boton boton-secundario" data-crm-descarga href="<?= s($enlace('comercial', 'csv')) ?>">Exportar lista comercial (CSV)</a>
        <a class="boton boton-secundario" data-crm-descarga href="<?= s($enlace('comercial', 'xlsx')) ?>">Exportar lista comercial (Excel XLSX)</a>
    </div>
    <p class="crm-nota crm-nota--ancha">La <strong>lista comercial</strong> trae solo a quienes otorgaron el texto <em>vigente</em> de comunicaciones comerciales, con cuenta confirmada y membresía no suspendida<?php if ($comercial !== null): ?>: con estos filtros son <strong><?= s((string)$comercial) ?></strong> contactos<?php endif; ?>. Vuelve a validar el consentimiento antes de enviar.</p>
    <p class="crm-nota" id="crm-estado-descarga" role="status" aria-live="polite"></p>
</div>

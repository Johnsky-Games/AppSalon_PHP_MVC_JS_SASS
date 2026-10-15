<?php
/**
 * Filtros activos como chips, conteo de resultados y «Limpiar filtros». Todo son enlaces: funciona sin JavaScript y sin perder el
 * resto de filtros. Espera $chips (CrmService::filtrosActivos), $total (int), $f (filtros normalizados), $rutaBase (string),
 * $sustantivo (p. ej. «clientes»).
 */
use Services\CrmService;

$chips = $chips ?? [];
$sustantivo = $sustantivo ?? 'clientes';
?>
<div class="crm-activos" role="region" aria-label="Resultados y filtros activos">
    <p class="crm-activos__conteo" id="crm-conteo" role="status" aria-live="polite">
        <strong><?= s((string)(int)$total) ?></strong> <?= (int)$total === 1 ? s(rtrim($sustantivo, 's')) : s($sustantivo) ?>
        <?= $chips ? 'con los filtros activos' : 'en total (sin filtros adicionales)' ?>.
    </p>
    <?php if ($chips): ?>
    <ul class="crm-chips" aria-label="Filtros activos">
        <?php foreach ($chips as $ch): ?>
        <li class="crm-chip">
            <span class="crm-chip__texto"><span class="crm-chip__et"><?= s($ch['etiqueta']) ?>:</span> <?= s($ch['valor']) ?></span>
            <a class="crm-chip__quitar" href="<?= s($rutaBase . '?' . CrmService::consulta($f, array_fill_keys($ch['quitar'], null) + ['pagina' => null])) ?>"
               aria-label="Quitar el filtro <?= s($ch['etiqueta']) ?>: <?= s($ch['valor']) ?>"><span aria-hidden="true">×</span></a>
        </li>
        <?php endforeach; ?>
    </ul>
    <a class="boton ui-button-quiet crm-activos__limpiar" href="<?= s($rutaBase) ?>">Limpiar filtros</a>
    <?php endif; ?>
</div>

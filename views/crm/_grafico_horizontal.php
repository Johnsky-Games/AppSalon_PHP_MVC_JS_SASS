<?php
/**
 * Barras horizontales con el valor siempre escrito en texto (la barra es un refuerzo visual, no la única señal).
 * Espera $grafico = ['id'=>string, 'titulo'=>string, 'descripcion'=>string, 'unidad'=>string, 'vacio'=>string,
 *                    'datos'=>[['etiqueta'=>string,'valor'=>int,'detalle'=>?string,'clase'=>?string], ...]]
 */
$hId = preg_replace('/[^a-z0-9_-]/i', '', (string)$grafico['id']);
$hDatos = $grafico['datos'];
$hMax = max(1, ...(array_map(static fn(array $d): int => (int)$d['valor'], $hDatos) ?: [0]));
$hTotal = array_sum(array_map(static fn(array $d): int => (int)$d['valor'], $hDatos));
?>
<figure class="crm-grafico" id="grafico-<?= s($hId) ?>">
    <figcaption>
        <h3 class="crm-grafico__titulo"><?= s($grafico['titulo']) ?></h3>
        <p class="crm-grafico__desc"><?= s($grafico['descripcion']) ?></p>
    </figcaption>
    <?php if ($hTotal === 0): ?>
        <p class="crm-vacio"><?= s($grafico['vacio'] ?? 'Sin datos para mostrar.') ?></p>
    <?php else: ?>
    <ul class="crm-hbarras" aria-label="<?= s($grafico['titulo']) ?>">
        <?php foreach ($hDatos as $hD): $hAncho = min(100, max(0, (int)$hD['valor'] / $hMax * 100)); ?>
        <li class="crm-hbarra">
            <span class="crm-hbarra__etq"><?= s($hD['etiqueta']) ?></span>
            <span class="crm-hbarra__pista" aria-hidden="true"><span class="crm-hbarra__relleno <?= s((string)($hD['clase'] ?? '')) ?>" style="width: <?= s(number_format($hAncho, 2, '.', '')) ?>%"></span></span>
            <strong class="crm-hbarra__valor"><?= s((string)(int)$hD['valor']) ?> <?= s($grafico['unidad']) ?><?php if (!empty($hD['detalle'])): ?><small><?= s($hD['detalle']) ?></small><?php endif; ?></strong>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</figure>

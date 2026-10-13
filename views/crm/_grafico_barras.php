<?php
/**
 * Gráfico de barras verticales por mes, en SVG, con alternativa en tabla.
 * Espera $grafico = ['id'=>string, 'titulo'=>string, 'descripcion'=>string, 'unidad'=>string, 'dinero'=>bool,
 *                    'datos'=>[['etiqueta'=>'AAAA-MM','valor'=>int,'extra'=>?string], ...]]
 */
$gId = preg_replace('/[^a-z0-9_-]/i', '', (string)$grafico['id']);
$gDatos = $grafico['datos'];
$gN = count($gDatos);
$gDinero = !empty($grafico['dinero']);
$gValores = array_map(static fn(array $d): int => (int)$d['valor'], $gDatos);
$gMax = max(1, ...($gValores ?: [0]));
$gTotal = array_sum($gValores);
$gFmt = static fn(int $v): string => $gDinero ? \Services\CrmService::dinero($v) : (string)$v;
$gAncho = max(320, $gN * 34 + 48);
$gAlto = 190;
$gBase = $gAlto - 34;
$gAreaAlto = $gBase - 22;
$gSlot = ($gAncho - 48) / max(1, $gN);
$gPaso = max(1, (int)ceil($gN / 12));
$gResumen = $gN === 0 ? 'Sin datos en el periodo.' : 'Total del periodo: ' . $gFmt($gTotal) . ' ' . $grafico['unidad'] . '; máximo mensual: ' . $gFmt($gMax === 1 && $gTotal === 0 ? 0 : $gMax) . '.';
?>
<figure class="crm-grafico" id="grafico-<?= s($gId) ?>">
    <figcaption>
        <h3 class="crm-grafico__titulo" id="grafico-<?= s($gId) ?>-t"><?= s($grafico['titulo']) ?></h3>
        <p class="crm-grafico__desc" id="grafico-<?= s($gId) ?>-d"><?= s($grafico['descripcion']) ?> <?= s($gResumen) ?></p>
    </figcaption>
    <?php if ($gN === 0): ?>
        <p class="crm-vacio">No hay meses para mostrar en este periodo.</p>
    <?php else: ?>
    <div class="crm-grafico__lienzo" tabindex="0" role="region" aria-label="Gráfico: <?= s($grafico['titulo']) ?>; desplaza horizontalmente si no cabe completo">
        <svg viewBox="0 0 <?= (int)$gAncho ?> <?= (int)$gAlto ?>" role="img" aria-labelledby="grafico-<?= s($gId) ?>-t grafico-<?= s($gId) ?>-d" focusable="false" style="min-width: <?= (int)$gAncho ?>px">
            <line class="crm-eje" x1="40" y1="<?= (int)$gBase ?>" x2="<?= (int)$gAncho ?>" y2="<?= (int)$gBase ?>"/>
            <text class="crm-etq-eje" x="36" y="<?= (int)$gBase ?>" text-anchor="end">0</text>
            <text class="crm-etq-eje" x="36" y="<?= (int)($gBase - $gAreaAlto + 10) ?>" text-anchor="end"><?= s($gDinero ? \Services\CrmService::dinero($gMax) : (string)$gMax) ?></text>
            <?php foreach ($gDatos as $gI => $gD):
                $gV = (int)$gD['valor'];
                $gH = $gMax > 0 ? ($gV / $gMax) * $gAreaAlto : 0;
                $gX = 44 + $gI * $gSlot + $gSlot * 0.14;
                $gW = $gSlot * 0.72;
                $gEtq = substr((string)$gD['etiqueta'], 5, 2) . '/' . substr((string)$gD['etiqueta'], 2, 2);
            ?>
            <g>
                <title><?= s($gD['etiqueta'] . ': ' . $gFmt($gV) . ' ' . $grafico['unidad']) ?></title>
                <rect class="crm-barra" x="<?= s(number_format($gX, 2, '.', '')) ?>" y="<?= s(number_format($gBase - $gH, 2, '.', '')) ?>" width="<?= s(number_format($gW, 2, '.', '')) ?>" height="<?= s(number_format($gH, 2, '.', '')) ?>" rx="3"/>
                <?php if ($gI % $gPaso === 0): ?>
                <text class="crm-etq" x="<?= s(number_format($gX + $gW / 2, 2, '.', '')) ?>" y="<?= (int)($gBase + 16) ?>" text-anchor="middle"><?= s($gEtq) ?></text>
                <?php endif; ?>
            </g>
            <?php endforeach; ?>
        </svg>
    </div>
    <details class="crm-datos">
        <summary>Ver los datos de «<?= s($grafico['titulo']) ?>» en tabla</summary>
        <div class="crm-tabla" tabindex="0" role="region" aria-label="Datos de <?= s($grafico['titulo']) ?>; desplaza horizontalmente si no caben las columnas">
            <table>
                <caption class="crm-sr"><?= s($grafico['titulo']) ?> por mes</caption>
                <thead><tr><th scope="col">Mes</th><th scope="col"><?= s(ucfirst($grafico['unidad'])) ?></th><?php if (!empty($gDatos[0]['extra_titulo'])): ?><th scope="col"><?= s($gDatos[0]['extra_titulo']) ?></th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($gDatos as $gD): ?>
                    <tr><th scope="row"><?= s($gD['etiqueta']) ?></th><td class="ui-table-number"><?= s($gFmt((int)$gD['valor'])) ?></td><?php if (!empty($gDatos[0]['extra_titulo'])): ?><td class="ui-table-number"><?= s((string)($gD['extra'] ?? '')) ?></td><?php endif; ?></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </details>
    <?php endif; ?>
</figure>

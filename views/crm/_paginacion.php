<?php
/**
 * Paginación accesible. Espera $pag (CrmService::paginar), $consulta (callable que arma la query conservando filtros),
 * $rutaBase (string) y $noun (p. ej. "clientes").
 */
$pPaginas = (int)$pag['paginas'];
$pActual = (int)$pag['pagina'];
$pVentana = array_values(array_unique(array_filter([1, $pActual - 2, $pActual - 1, $pActual, $pActual + 1, $pActual + 2, $pPaginas], static fn(int $n): bool => $n >= 1 && $n <= $pPaginas)));
sort($pVentana);
?>
<nav class="crm-paginacion" aria-label="Paginación de <?= s($noun) ?>">
    <p class="crm-paginacion__estado" role="status">
        <?php if ((int)$pag['total'] === 0): ?>Sin <?= s($noun) ?> para mostrar.
        <?php else: ?>Mostrando <?= s((string)$pag['primero']) ?>–<?= s((string)$pag['ultimo']) ?> de <?= s((string)$pag['total']) ?> <?= s($noun) ?> · Página <?= s((string)$pActual) ?> de <?= s((string)$pPaginas) ?><?php endif; ?>
    </p>
    <?php if ($pPaginas > 1): ?>
    <ul class="crm-paginacion__lista">
        <li><?php if ($pActual > 1): ?><a class="crm-pagina" rel="prev" href="<?= s($rutaBase . '?' . $consulta(['pagina' => $pActual - 1])) ?>">Anterior</a><?php else: ?><span class="crm-pagina is-deshabilitada" aria-disabled="true">Anterior</span><?php endif; ?></li>
        <?php $pPrevio = 0; foreach ($pVentana as $pN): ?>
            <?php if ($pN - $pPrevio > 1): ?><li aria-hidden="true"><span class="crm-pagina is-deshabilitada">…</span></li><?php endif; ?>
            <li><?php if ($pN === $pActual): ?><span class="crm-pagina is-actual" aria-current="page"><span class="crm-sr">Página </span><?= s((string)$pN) ?></span><?php else: ?><a class="crm-pagina" href="<?= s($rutaBase . '?' . $consulta(['pagina' => $pN])) ?>"><span class="crm-sr">Página </span><?= s((string)$pN) ?></a><?php endif; ?></li>
        <?php $pPrevio = $pN; endforeach; ?>
        <li><?php if ($pActual < $pPaginas): ?><a class="crm-pagina" rel="next" href="<?= s($rutaBase . '?' . $consulta(['pagina' => $pActual + 1])) ?>">Siguiente</a><?php else: ?><span class="crm-pagina is-deshabilitada" aria-disabled="true">Siguiente</span><?php endif; ?></li>
    </ul>
    <?php endif; ?>
</nav>

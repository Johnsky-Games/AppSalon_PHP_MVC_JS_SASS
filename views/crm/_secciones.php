<?php
$consultaSeccion = \Services\CrmService::consulta(\Services\CrmService::sinIndicador($f), ['pagina' => null]);
$sufijoSeccion = $consultaSeccion !== '' ? '?' . $consultaSeccion : '';
?>
<?php if ($modo !== 'propios'): ?>
<nav class="gestion-secciones" aria-label="Vistas de clientes">
    <?php if (!empty($acceso['ver'])): ?><a href="/admin/clientes<?= s($sufijoSeccion) ?>"<?= ($seccionClientes ?? '') === 'base' ? ' aria-current="page"' : '' ?>>Base de clientes</a><?php endif; ?>
    <a href="/admin/clientes/indicadores<?= s($sufijoSeccion) ?>"<?= ($seccionClientes ?? '') === 'indicadores' ? ' aria-current="page"' : '' ?>>Indicadores de clientes</a>
</nav>
<?php endif; ?>

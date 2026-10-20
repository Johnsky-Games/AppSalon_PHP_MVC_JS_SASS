<?php
$dinero=static fn($n)=>'$'.number_format((int)$n/100,2,'.',',');
$cantidad=static fn($n)=>number_format((int)$n/1000,3,'.',',');
$clave=static function(string $accion,?int $venta=null):void { ?>
<input type="hidden" name="csrf_token" value="<?= s(csrf_token()) ?>"><input type="hidden" name="idem_key" value="<?= s(\Classes\Idempotencia::nuevaClave()) ?>"><input type="hidden" name="accion" value="<?= s($accion) ?>"><?php if($venta): ?><input type="hidden" name="venta_id" value="<?= $venta ?>"><?php endif; ?>
<?php };
?>
<header class="ui-page-header"><p class="ui-eyebrow">Gestión del negocio</p><h1><?= s($titulo??'Ventas') ?></h1></header>
<?php include __DIR__.'/../templates/barra.php';include __DIR__.'/../templates/alertas.php'; ?>
<nav class="ops-toolbar" aria-label="Accesos comerciales">
<?php if(puede('erp.ventas.ver')): ?><a href="/pdv">Punto de venta</a><?php endif; ?>
<?php if(puede('erp.caja')): ?><a href="/gestion/caja">Mi caja</a><?php endif; ?>
<?php if(puede('erp.compras')): ?><a href="/gestion/compras">Compras</a><?php endif; ?>
<?php if(puede('erp.reportes')): ?><a href="/gestion/indicadores">Indicadores del negocio</a><?php endif; ?>
<?php if(puede('contabilidad.ver')): ?><a href="/gestion/contabilidad">Contabilidad</a><?php endif; ?>
</nav>

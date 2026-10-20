<?php
$titulo=($ride['tipo']??'01')==='04'?'RIDE de nota de crédito':'RIDE de factura'; include __DIR__.'/_inicio.php';
$e=$ride['emisor']; $f=$ride['factura']; $a=$ride['autorizacion'];
$numero=$e['estab'].'-'.$e['ptoEmi'].'-'.$e['secuencial'];
?>
<nav class="ops-toolbar" aria-label="Acciones del comprobante"><button class="boton" type="button" data-erp-imprimir>Imprimir / guardar PDF</button><a href="/gestion/facturacion?id=<?= (int)$id ?>">Volver al comprobante</a><a href="/gestion/facturacion/pdf?id=<?= (int)$id ?>">Descargar PDF</a><a href="/gestion/facturacion/xml?id=<?= (int)$id ?>&amp;formato=autorizado">Descargar XML autorizado</a></nav>
<p class="sri-print-help">La opción de impresión permite elegir una impresora o guardar como PDF desde el navegador.</p>
<?php include __DIR__.'/_fiscal-ride-documento.php'; ?>

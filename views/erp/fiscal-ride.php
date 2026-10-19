<?php
$titulo='RIDE de factura'; include __DIR__.'/_inicio.php';
$e=$ride['emisor']; $f=$ride['factura']; $a=$ride['autorizacion'];
$numero=$e['estab'].'-'.$e['ptoEmi'].'-'.$e['secuencial'];
?>
<nav class="ops-toolbar" aria-label="Acciones del comprobante"><button class="boton" type="button" data-erp-imprimir>Imprimir / guardar PDF</button><a href="/gestion/facturacion?id=<?= (int)$id ?>">Volver al comprobante</a><a href="/gestion/facturacion/xml?id=<?= (int)$id ?>&amp;formato=autorizado">Descargar XML autorizado</a></nav>
<p class="sri-print-help">La opción de impresión permite elegir una impresora o guardar como PDF desde el navegador.</p>
<article class="sri-ride" aria-label="Representación impresa de factura <?= s($numero) ?>">
    <?php if($e['ambiente']==='1'): ?><p class="sri-test-warning"><strong>AMBIENTE DE PRUEBAS · SIN VALIDEZ TRIBUTARIA</strong></p><?php endif; ?>
    <header class="sri-ride-heading">
        <section><h2><?= s($e['razonSocial']) ?></h2><?php if($e['nombreComercial']!==''): ?><p><?= s($e['nombreComercial']) ?></p><?php endif; ?>
            <p><strong>Dirección matriz:</strong> <?= s($e['dirMatriz']) ?></p>
            <?php if($f['dirEstablecimiento']!==''): ?><p><strong>Dirección del establecimiento:</strong> <?= s($f['dirEstablecimiento']) ?></p><?php endif; ?>
            <p><strong>Obligado a llevar contabilidad:</strong> <?= s($f['obligadoContabilidad']) ?></p>
            <?php foreach(['contribuyenteEspecial'=>'Contribuyente especial'] as $campo=>$etiqueta):if($f[$campo]!==''): ?><p><strong><?= s($etiqueta) ?>:</strong> <?= s($f[$campo]) ?></p><?php endif;endforeach; ?>
            <?php foreach(['agenteRetencion'=>'Agente de retención','contribuyenteRimpe'=>'Régimen RIMPE'] as $campo=>$etiqueta):if($e[$campo]!==''): ?><p><strong><?= s($etiqueta) ?>:</strong> <?= s($e[$campo]) ?></p><?php endif;endforeach; ?>
        </section>
        <section><p><strong>RUC:</strong> <?= s($e['ruc']) ?></p><h2>FACTURA</h2><p><strong>N.º <?= s($numero) ?></strong></p>
            <p><strong>Número de autorización:</strong><span class="sri-access-key"><?= s($a['numero']) ?></span></p>
            <p><strong>Fecha de autorización:</strong> <?= s($a['fecha']) ?></p><p><strong>Ambiente:</strong> <?= $e['ambiente']==='1'?'PRUEBAS':'PRODUCCIÓN' ?> · <strong>Emisión:</strong> NORMAL</p>
            <p><strong>Clave de acceso:</strong><span class="sri-access-key"><?= s($e['claveAcceso']) ?></span></p>
        </section>
    </header>
    <section class="sri-buyer"><h3>Datos del comprador</h3><p><strong>Razón social / nombres:</strong> <?= s($f['razonSocialComprador']) ?></p><p><strong>Identificación:</strong> <?= s($f['identificacionComprador']) ?> · <strong>Fecha de emisión:</strong> <?= s($f['fechaEmision']) ?></p><?php if($f['direccionComprador']!==''): ?><p><strong>Dirección:</strong> <?= s($f['direccionComprador']) ?></p><?php endif; ?></section>
    <div class="sri-lines-wrap" tabindex="0" aria-label="Detalle de la factura, desplazamiento horizontal en pantallas pequeñas"><table class="sri-lines"><caption>Detalle del comprobante · USD</caption><thead><tr><th scope="col">Código</th><th scope="col">Cantidad</th><th scope="col">Descripción</th><th scope="col">Precio unitario</th><th scope="col">Descuento</th><th scope="col">Total sin impuesto</th></tr></thead><tbody>
    <?php foreach($ride['lineas'] as $l): ?><tr><td><?= s($l['codigoPrincipal']) ?><?php if($l['codigoAuxiliar']!==''): ?><small><?= s($l['codigoAuxiliar']) ?></small><?php endif; ?></td><td class="sri-number"><?= s($l['cantidad']) ?></td><td><?= s($l['descripcion']) ?><?php foreach($l['adicionales'] as $extra): ?><small><?= s($extra['nombre']) ?>: <?= s($extra['valor']) ?></small><?php endforeach; ?></td><td class="sri-number"><?= s($l['precioUnitario']) ?></td><td class="sri-number"><?= s($l['descuento']) ?></td><td class="sri-number"><?= s($l['precioTotalSinImpuesto']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <div class="sri-ride-summary">
        <section><h3>Formas de pago pactadas</h3><p>Estos valores corresponden al comprobante; no acreditan por sí solos un nuevo cobro.</p><dl><?php foreach($ride['pagos'] as $p): ?><dt><?= s($p['codigo'].' · '.$p['etiqueta']) ?><?php if($p['plazo']!==''): ?> · <?= s($p['plazo'].' '.$p['unidad']) ?><?php endif; ?></dt><dd>USD <?= s($p['total']) ?></dd><?php endforeach; ?></dl>
        <?php if($ride['adicionales']): ?><h3>Información adicional</h3><?php foreach($ride['adicionales'] as $extra): ?><p><strong><?= s($extra['nombre']) ?>:</strong> <?= s($extra['valor']) ?></p><?php endforeach;endif; ?></section>
        <section><h3>Totales · USD</h3><dl class="sri-totals">
            <?php foreach($ride['impuestos'] as $impuesto): ?><dt>Subtotal <?= s($impuesto['etiqueta']) ?></dt><dd><?= s($impuesto['base']) ?></dd><?php endforeach; ?>
            <dt>Subtotal sin impuestos</dt><dd><?= s($f['totalSinImpuestos']) ?></dd><dt>Total descuentos</dt><dd><?= s($f['totalDescuento']) ?></dd>
            <?php foreach($ride['impuestos'] as $impuesto): ?><dt><?= s($impuesto['etiqueta']) ?></dt><dd><?= s($impuesto['valor']) ?></dd><?php endforeach; ?>
            <?php if($f['propina']!==''): ?><dt>Propina</dt><dd><?= s($f['propina']) ?></dd><?php endif; ?>
            <dt class="sri-grand-total">VALOR TOTAL</dt><dd class="sri-grand-total"><?= s($f['importeTotal']) ?></dd>
        </dl></section>
    </div>
    <footer>Representación impresa del documento electrónico. Datos conservados del comprobante autorizado.</footer>
</article>

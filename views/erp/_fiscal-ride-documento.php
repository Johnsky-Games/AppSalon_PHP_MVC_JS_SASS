<?php
$e=$ride['emisor']; $f=$ride['factura']; $a=$ride['autorizacion'];
$numero=$e['estab'].'-'.$e['ptoEmi'].'-'.$e['secuencial'];
$esc=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?>
<article class="sri-ride" aria-label="Representación impresa de factura <?= $esc($numero) ?>">
    <?php if($e['ambiente']==='1'): ?><p class="sri-test-warning"><strong>AMBIENTE DE PRUEBAS · SIN VALIDEZ TRIBUTARIA</strong></p><?php endif; ?>
    <header class="sri-ride-heading">
        <section><h2><?= $esc($e['razonSocial']) ?></h2><?php if($e['nombreComercial']!==''): ?><p><?= $esc($e['nombreComercial']) ?></p><?php endif; ?>
            <p><strong>Dirección matriz:</strong> <?= $esc($e['dirMatriz']) ?></p>
            <?php if($f['dirEstablecimiento']!==''): ?><p><strong>Dirección del establecimiento:</strong> <?= $esc($f['dirEstablecimiento']) ?></p><?php endif; ?>
            <p><strong>Obligado a llevar contabilidad:</strong> <?= $esc($f['obligadoContabilidad']) ?></p>
            <?php foreach(['contribuyenteEspecial'=>'Contribuyente especial'] as $campo=>$etiqueta):if($f[$campo]!==''): ?><p><strong><?= $esc($etiqueta) ?>:</strong> <?= $esc($f[$campo]) ?></p><?php endif;endforeach; ?>
            <?php foreach(['agenteRetencion'=>'Agente de retención','contribuyenteRimpe'=>'Régimen RIMPE'] as $campo=>$etiqueta):if($e[$campo]!==''): ?><p><strong><?= $esc($etiqueta) ?>:</strong> <?= $esc($e[$campo]) ?></p><?php endif;endforeach; ?>
        </section>
        <section><p><strong>RUC:</strong> <?= $esc($e['ruc']) ?></p><h2>FACTURA</h2><p><strong>N.º <?= $esc($numero) ?></strong></p>
            <p><strong>Número de autorización:</strong><span class="sri-access-key"><?= $esc($a['numero']) ?></span></p>
            <p><strong>Fecha de autorización:</strong> <?= $esc($a['fecha']) ?></p><p><strong>Ambiente:</strong> <?= $e['ambiente']==='1'?'PRUEBAS':'PRODUCCIÓN' ?> · <strong>Emisión:</strong> NORMAL</p>
            <p><strong>Clave de acceso:</strong><span class="sri-access-key"><?= $esc($e['claveAcceso']) ?></span></p>
        </section>
    </header>
    <section class="sri-buyer"><h3>Datos del comprador</h3><p><strong>Razón social / nombres:</strong> <?= $esc($f['razonSocialComprador']) ?></p><p><strong>Identificación:</strong> <?= $esc($f['identificacionComprador']) ?> · <strong>Fecha de emisión:</strong> <?= $esc($f['fechaEmision']) ?></p><?php if($f['direccionComprador']!==''): ?><p><strong>Dirección:</strong> <?= $esc($f['direccionComprador']) ?></p><?php endif; ?></section>
    <div class="sri-lines-wrap" tabindex="0" aria-label="Detalle de la factura, desplazamiento horizontal en pantallas pequeñas"><table class="sri-lines"><caption>Detalle del comprobante · USD</caption><thead><tr><th scope="col">Código</th><th scope="col">Cantidad</th><th scope="col">Descripción</th><th scope="col">Precio unitario</th><th scope="col">Descuento</th><th scope="col">Total sin impuesto</th></tr></thead><tbody>
    <?php foreach($ride['lineas'] as $l): ?><tr><td><?= $esc($l['codigoPrincipal']) ?><?php if($l['codigoAuxiliar']!==''): ?><small><?= $esc($l['codigoAuxiliar']) ?></small><?php endif; ?></td><td class="sri-number"><?= $esc($l['cantidad']) ?></td><td><?= $esc($l['descripcion']) ?><?php foreach($l['adicionales'] as $extra): ?><small><?= $esc($extra['nombre']) ?>: <?= $esc($extra['valor']) ?></small><?php endforeach; ?></td><td class="sri-number"><?= $esc($l['precioUnitario']) ?></td><td class="sri-number"><?= $esc($l['descuento']) ?></td><td class="sri-number"><?= $esc($l['precioTotalSinImpuesto']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <div class="sri-ride-summary">
        <section><h3>Formas de pago pactadas</h3><p>Estos valores corresponden al comprobante; no acreditan por sí solos un nuevo cobro.</p><dl><?php foreach($ride['pagos'] as $p): ?><dt><?= $esc($p['codigo'].' · '.$p['etiqueta']) ?><?php if($p['plazo']!==''): ?> · <?= $esc($p['plazo'].' '.$p['unidad']) ?><?php endif; ?></dt><dd>USD <?= $esc($p['total']) ?></dd><?php endforeach; ?></dl>
        <?php if($ride['adicionales']): ?><h3>Información adicional</h3><?php foreach($ride['adicionales'] as $extra): ?><p><strong><?= $esc($extra['nombre']) ?>:</strong> <?= $esc($extra['valor']) ?></p><?php endforeach;endif; ?></section>
        <section><h3>Totales · USD</h3><table class="sri-totals"><tbody>
            <?php foreach($ride['impuestos'] as $impuesto): ?><tr><th scope="row">Subtotal <?= $esc($impuesto['etiqueta']) ?></th><td><?= $esc($impuesto['base']) ?></td></tr><?php endforeach; ?>
            <tr><th scope="row">Subtotal sin impuestos</th><td><?= $esc($f['totalSinImpuestos']) ?></td></tr><tr><th scope="row">Total descuentos</th><td><?= $esc($f['totalDescuento']) ?></td></tr>
            <?php foreach($ride['impuestos'] as $impuesto): ?><tr><th scope="row"><?= $esc($impuesto['etiqueta']) ?></th><td><?= $esc($impuesto['valor']) ?></td></tr><?php endforeach; ?>
            <?php if($f['propina']!==''): ?><tr><th scope="row">Propina</th><td><?= $esc($f['propina']) ?></td></tr><?php endif; ?>
            <tr><th scope="row" class="sri-grand-total">VALOR TOTAL</th><td class="sri-grand-total"><?= $esc($f['importeTotal']) ?></td></tr>
        </tbody></table></section>
    </div>
    <footer>Representación impresa del documento electrónico. Datos conservados del comprobante autorizado.</footer>
</article>

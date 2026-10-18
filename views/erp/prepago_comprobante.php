<?php $titulo='Comprobante '.$recibo['codigo'];include __DIR__.'/_inicio.php';$c=$recibo['contenido']; ?>
<article class="ui-card erp-recibo"><h2><?= s($c['empresa']['nombre']) ?></h2><p><?= s($c['empresa']['identificacion']??'') ?> · <?= s($c['empresa']['direccion']) ?></p>
<p><strong><?= s($c['leyenda']) ?></strong></p><p><?= s($recibo['codigo']) ?> · <?= s($recibo['creado_en']) ?></p>
<p><?= $c['tipo']==='carga'?'Carga de saldo recibido':'Reembolso de saldo no utilizado' ?> · Prepago #<?= (int)$recibo['prepago_id'] ?></p>
<p>Beneficiario: <?= s($c['beneficiario']['nombre']) ?> · <?= s($c['beneficiario']['identificacion']??'') ?></p><p><?= s($c['beneficiario']['prepago']) ?> · Carga #<?= (int)$c['carga']['id'] ?></p>
<p>Importe: <strong><?= s($dinero(abs((int)$c['monto_centavos']))) ?> USD</strong> · Medio: <?= s($c['carga']['metodo']) ?></p>
<?php if($c['carga']['referencia']): ?><p>Referencia: <?= s($c['carga']['referencia']) ?></p><?php endif; ?>
<?php if($c['tipo']==='carga'&&$c['carga']['recibido_centavos']!==null): ?><p>Efectivo recibido: <?= s($dinero($c['carga']['recibido_centavos'])) ?> · Cambio: <?= s($dinero($c['carga']['cambio_centavos'])) ?></p><?php endif; ?>
<p>Saldo disponible al emitir: <?= s($dinero($c['saldo_despues'])) ?></p><p>Motivo: <?= s($c['motivo']) ?></p><p>Registrado por: <?= s(trim($c['operador']['nombre'].' '.$c['operador']['apellido'])) ?></p>
<p>Esta copia conserva los datos del momento de emisión. Una carga no acredita una atención realizada.</p><button class="boton" type="button" data-erp-imprimir>Imprimir comprobante</button> <a href="/gestion/prepagos?id=<?= (int)$recibo['prepago_id'] ?>">Volver al prepago</a></article>

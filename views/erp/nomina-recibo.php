<?php $titulo='Recibo de pago';include __DIR__.'/_inicio.php';
$l=$linea;$f=static fn($v)=>$dinero($v);
$ingresos=[['Sueldo ('.(int)$l['dias'].' días)',$l['sueldo_centavos']],['Horas con recargo',$l['horas_centavos']],['Bonos',$l['bonos_centavos']],['Comisiones pagadas por nómina',$l['comisiones_centavos']],['Décimo tercero mensualizado',$l['d13_mensual_centavos']],['Décimo cuarto mensualizado',$l['d14_mensual_centavos']],['Fondos de reserva mensualizados',$l['fondos_mensual_centavos']]];
$egresos=[['Aporte personal al IESS',$l['iess_personal_centavos']],['Retención de impuesto a la renta',$l['ir_centavos']],['Descuentos',$l['descuentos_centavos']]];
?>
<section class="ui-card">
<h2>Rol de pagos <?= s(sprintf('%04d-%02d',$l['anio'],$l['mes'])) ?></h2>
<p><?= s($empresa['nombre']??'') ?><?= !empty($empresa['identificacion'])?' · '.s($empresa['identificacion']):'' ?></p>
<p>Colaborador: <strong><?= s($l['nombre']) ?></strong><?= $l['puesto']?' · '.s($l['puesto']):'' ?> · Estado de la corrida: <?= s($l['corrida_estado']) ?></p>
<div class="ops-table-wrap"><table class="ops-table"><thead><tr><th>Concepto</th><th>Ingresos</th><th>Egresos</th></tr></thead><tbody>
<?php foreach($ingresos as [$t,$v]): if((int)$v===0)continue; ?><tr><td><?= s($t) ?></td><td><?= s($f($v)) ?></td><td></td></tr><?php endforeach; ?>
<?php foreach($egresos as [$t,$v]): if((int)$v===0)continue; ?><tr><td><?= s($t) ?></td><td></td><td><?= s($f($v)) ?></td></tr><?php endforeach; ?>
<tr><th>Total ingresos</th><th><?= s($f($l['ingresos_centavos'])) ?></th><th></th></tr><tr><th>Neto a recibir</th><th colspan="2"><?= s($f($l['neto_centavos'])) ?></th></tr>
</tbody></table></div>
<p><small>Aporte patronal al IESS (a cargo del empleador): <?= s($f($l['iess_patronal_centavos'])) ?> · Décimos y fondos acumulados del período: <?= s($f((int)$l['d13_prov_centavos']+(int)$l['d14_prov_centavos']+(int)$l['fondos_acum_centavos'])) ?>. Documento de ensayo: no sustituye el rol de pagos oficial hasta que el contador valide los parámetros.</small></p>
<p>Para imprimir o guardar como PDF usa la impresión del navegador. <a href="/gestion/nomina">Volver a la nómina</a></p>
</section>

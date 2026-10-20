<?php $titulo='Parámetros fiscales · SRI';include __DIR__.'/_inicio.php'; ?>
<p role="status">AppSalon no trae cifras legales cargadas. Cada parámetro lo registra una persona con permiso de configuración, después de verificarlo en la fuente oficial del SRI, y queda con su norma, enlace y fecha de consulta. Sin un parámetro vigente no se emite el comprobante que depende de él. Las versiones se conservan; una corrección es una versión nueva desde hoy o una fecha futura.</p>
<section class="ui-card"><h2>Vigentes hoy (<?= s($hoy) ?>)</h2>
<div class="ops-table-wrap"><table class="ops-table"><thead><tr><th>Parámetro</th><th>Régimen</th><th>Valor</th><th>Desde</th><th>Fuente</th><th>Consultado</th></tr></thead><tbody>
<?php $hay=false;foreach($catalogo as $cp=>$def): foreach($regimenes as $reg): $p=$vigentes[$cp.'|'.$reg]??null; if(!$p)continue; $hay=true; ?>
<tr><td><?= s($cp) ?><br><small><?= s($def['descripcion']) ?></small></td><td><?= s($reg==='*'?'Todos':$reg) ?></td><td><?= $p['estado']==='derogado'?'<em>Derogado: sin regla</em>':s($def['tipo']==='importe'?number_format((int)$p['valor_entero']/100,2,'.',''):($p['valor_entero']!==null?$p['valor_entero']:$p['valor_texto'])) ?></td><td><?= s($p['vigente_desde']) ?></td><td><?= s($p['fuente']) ?><?php if($p['fuente_url']): ?><br><a href="<?= s($p['fuente_url']) ?>" rel="noopener noreferrer" target="_blank">Enlace</a><?php endif; ?></td><td><?= s($p['consultado_en']) ?></td></tr>
<?php endforeach; endforeach; if(!$hay): ?><tr><td colspan="6">No hay parámetros registrados. La factura necesita al menos las tarifas de IVA que use; el consumidor final, la leyenda de régimen y el cobro anticipado requieren los suyos.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?php if($configura): ?>
<section class="ui-card"><h2>Registrar una versión</h2>
<?php $opc=[];foreach($catalogo as $cp=>$def)$opc[$cp]=$cp.' — '.$def['descripcion'];
$formulario=['accion'=>'fiscal_parametro','campos'=>[
 'clave'=>['etiqueta'=>'Parámetro','tipo'=>'select','opciones'=>$opc],
 'regimen'=>['etiqueta'=>'Régimen (* = todos)','tipo'=>'select','opciones'=>array_combine($regimenes,array_map(fn($r)=>$r==='*'?'* · Todos':$r,$regimenes)),'valor'=>'*'],
 'estado'=>['etiqueta'=>'Estado','tipo'=>'select','opciones'=>['vigente'=>'Vigente','derogado'=>'Derogado (retira la regla)'],'valor'=>'vigente'],
 'valor'=>['etiqueta'=>'Valor (importes en USD, p. ej. 50.00; tarifas en centésimas de punto, p. ej. 1500; texto tal como debe aparecer)','longitud'=>160,'requerido'=>false],
 'vigente_desde'=>['etiqueta'=>'Rige desde','tipo'=>'date','valor'=>$hoy],
 'fuente'=>['etiqueta'=>'Norma o documento oficial consultado (nombre y número)','longitud'=>200],
 'fuente_url'=>['etiqueta'=>'Enlace https a la fuente','tipo'=>'url','longitud'=>300,'requerido'=>false],
 'consultado_en'=>['etiqueta'=>'Fecha en que se consultó la fuente','tipo'=>'date','valor'=>$hoy],
 'nota'=>['etiqueta'=>'Nota interna','longitud'=>300,'requerido'=>false],
],'boton'=>'Registrar parámetro'];include __DIR__.'/_formulario.php'; ?>
<p>El momento del hecho generador solo admite «prestacion» por ahora: la factura se emite al prestar el servicio y no al recibir un anticipo, una carga de saldo, un paquete o un ciclo de suscripción. La opción de facturar en el cobro todavía no está implementada.</p></section>
<?php else: ?><p>Tu rol puede consultar los parámetros, pero registrarlos requiere el permiso de configuración.</p><?php endif; ?>
<section class="ui-card"><h2>Historial</h2><div class="ops-table-wrap"><table class="ops-table"><thead><tr><th>Registrado</th><th>Parámetro</th><th>Régimen</th><th>Desde</th><th>Estado</th><th>Fuente</th></tr></thead><tbody>
<?php foreach($historial as $h): ?><tr><td><?= s($h['creado_en']) ?></td><td><?= s($h['clave']) ?></td><td><?= s($h['regimen']) ?></td><td><?= s($h['vigente_desde']) ?></td><td><?= s($h['estado']) ?></td><td><?= s($h['fuente']) ?></td></tr><?php endforeach; if(!$historial): ?><tr><td colspan="6">Sin versiones registradas.</td></tr><?php endif; ?>
</tbody></table></div><p><a href="/gestion/facturacion">Directorio fiscal</a></p></section>

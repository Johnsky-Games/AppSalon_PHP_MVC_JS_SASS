<?php
$titulo='Conteo físico de inventario';include __DIR__.'/_inicio.php';
$estadoTxt=['en_conteo'=>'En conteo','pendiente'=>'Pendiente de aprobación','aprobado'=>'Aprobado','rechazado'=>'Rechazado','cancelado'=>'Cancelado','revertido'=>'Revertido'];
$c=$conteo;
?>
<section class="ui-card"><h2>Conteos de inventario</h2>
<p>Un conteo registra al iniciar su <strong>alcance</strong>, su <strong>responsable</strong> y las <strong>existencias de referencia</strong> de cada producto. Lo <strong>sin contar</strong> no es lo mismo que lo <strong>contado en cero</strong>. Al aprobarlo, cada diferencia genera <strong>un solo ajuste</strong> por el flujo de ajustes de inventario (concepto, valoración y hecho contable). Las <strong>reservas</strong> no son faltantes: solo impiden dejar el stock por debajo de lo reservado. Nada se borra: un conteo aprobado se revierte con ajustes inversos.</p>
<div class="ops-table-wrap"><table class="ops-table"><caption>Conteos (más recientes primero)</caption><thead><tr><th>#</th><th>Alcance</th><th>Responsable</th><th>Estado</th><th>Productos</th><th>Contados</th><th>Ajustes</th><th>Iniciado</th><th></th></tr></thead><tbody>
<?php foreach($lista as $x): ?><tr><td><?= (int)$x['id'] ?></td><td><?= s($x['descripcion']) ?> <small>(<?= $x['alcance']==='todos_activos'?'todos los activos':'selección' ?>)</small></td><td><?= s($x['responsable']??'—') ?></td><td><?= s($estadoTxt[$x['estado']]) ?></td><td><?= (int)$x['productos'] ?></td><td><?= (int)$x['contadas'] ?></td><td><?= (int)$x['ajustes'] ?></td><td><?= s(substr($x['iniciado_en'],0,16)) ?></td><td><a href="?conteo=<?= (int)$x['id'] ?>">Ver</a></td></tr>
<?php endforeach; ?><?php if(!$lista): ?><tr><td colspan="9">Todavía no hay conteos.</td></tr><?php endif; ?></tbody></table></div>
<details><summary>Iniciar un conteo</summary>
<?php $formulario=['accion'=>'inv_conteo_iniciar','campos'=>[
 'alcance'=>['tipo'=>'select','etiqueta'=>'Alcance','opciones'=>['todos_activos'=>'Todos los productos activos','seleccion'=>'Una selección de productos (elige abajo)']],
 'descripcion'=>['etiqueta'=>'Descripción del alcance (p. ej. «Bodega principal, cierre de octubre»)','longitud'=>200],
 'responsable_id'=>['tipo'=>'select','etiqueta'=>'Responsable del conteo','opciones'=>array_column(array_map(static fn($u)=>['id'=>$u['id'],'n'=>trim($u['nombre'].' '.$u['apellido'])],$responsables),'n','id')],
 'productos'=>['tipo'=>'casillas','etiqueta'=>'Productos (solo si el alcance es una selección)','opciones'=>array_column($productos,'nombre','id'),'requerido'=>false]],'boton'=>'Iniciar conteo (toma las existencias de referencia)'];include __DIR__.'/_formulario.php'; ?>
</details></section>

<?php if($c): $abierto=in_array($c['estado'],['en_conteo','pendiente'],true);$t=$c['totales']; ?>
<section class="ui-card"><h2>Conteo #<?= (int)$c['id'] ?> · <?= s($estadoTxt[$c['estado']]) ?></h2>
<p><strong>Alcance:</strong> <?= s($c['descripcion']) ?> (<?= $c['alcance']==='todos_activos'?'todos los productos activos':'selección de productos' ?>) · <strong>Responsable:</strong> <?= s($c['responsable_nombre']??'—') ?> · <strong>Iniciado</strong> por <?= s($c['iniciado_nombre']??'—') ?> el <?= s(substr($c['iniciado_en'],0,16)) ?> (referencia: último movimiento de inventario #<?= (int)$c['ultimo_movimiento_inicio'] ?>)
<?php if($c['cerrado_en']): ?> · <strong>Enviado a aprobación</strong> por <?= s($c['cerrado_nombre']??'—') ?> el <?= s(substr($c['cerrado_en'],0,16)) ?><?php endif; ?>
<?php if($c['resuelto_en']): ?> · <strong><?= s($estadoTxt[$c['estado']==='revertido'?'aprobado':$c['estado']]) ?></strong> por <?= s($c['resuelto_nombre']??'—') ?> el <?= s(substr($c['resuelto_en'],0,16)) ?>: <?= s($c['motivo']??'') ?><?php endif; ?>
<?php if($c['revertido_en']): ?> · <strong>Revertido</strong> por <?= s($c['revertido_nombre']??'—') ?> el <?= s(substr($c['revertido_en'],0,16)) ?>: <?= s($c['motivo_reverso']??'') ?><?php endif; ?></p>
<p>Valor estimado de las diferencias al costo actual: faltantes <strong><?= $dinero($t['faltante_centavos']) ?></strong> · sobrantes <strong><?= $dinero($t['sobrante_centavos']) ?></strong> · con diferencia <?= (int)$t['con_diferencia'] ?> · sin contar <?= (int)$t['sin_contar'] ?> · lecturas registradas <?= (int)$c['registros'] ?>
<?php if($abierto&&$t['bloqueos']): ?> · <strong>conflictos que bloquean la aprobación: <?= (int)$t['bloqueos'] ?></strong><?php endif; ?>
<?php if($abierto&&$t['avisos']): ?> · avisos de movimientos posteriores al conteo: <?= (int)$t['avisos'] ?><?php endif; ?></p>
<div class="ops-table-wrap"><table class="ops-table"><caption>Productos del conteo (existencia física; las reservas no se restan del contado)</caption><thead><tr><th>Producto</th><th>Referencia al iniciar</th><th>Sistema al contar</th><th>Stock actual</th><th>Reservado</th><th>Disponible</th><th>Contado</th><th>Diferencia</th><th>Valor</th><th>Conflictos</th><th><?= $c['estado']==='en_conteo'?'Contar':'' ?></th></tr></thead><tbody>
<?php foreach($c['lineas'] as $l): ?><tr><td><?= s($l['nombre']) ?> <small><?= s($l['sku']) ?></small></td>
<td><?= $cantidad($l['referencia_milesimas']) ?></td>
<td><?= $l['contada']?$cantidad($l['sistema_al_contar_milesimas']):'—' ?></td>
<td><?= $cantidad($l['stock_actual']) ?></td><td><?= $cantidad($l['reservado_actual']) ?></td><td><?= $cantidad($l['disponible_actual']) ?></td>
<td><?= $l['contada']?'<strong>'.$cantidad($l['contado_milesimas']).'</strong>'.((int)$l['contado_milesimas']===0?' <small>(contado en cero)</small>':''):'<em>sin contar</em>' ?></td>
<td><?= !$l['contada']?'—':($l['tipo']===null?'sin diferencia':(($l['tipo']==='entrada'?'+':'−').$cantidad($l['cantidad']).' ('.($l['tipo']==='entrada'?'sobrante':'faltante').')')) ?></td>
<td><?= $l['tipo']!==null?$dinero($l['valor_centavos']):'—' ?></td>
<td><?php foreach($l['conflictos'] as $k): if($k['codigo']==='sin_contar')continue; ?><div><?= $k['bloquea']?'<strong>⛔ ':'⚠️ ' ?><?= s($k['mensaje']) ?><?= $k['bloquea']?'</strong>':'' ?></div><?php endforeach; ?><?php if($l['contada']): ?><a href="?conteo=<?= (int)$c['id'] ?>&amp;linea=<?= (int)$l['id'] ?>">historial</a><?php endif; ?></td>
<td><?php if($c['estado']==='en_conteo'): ?><form class="formulario ui-filtros" method="POST" action="/gestion/accion" data-una-vez><?php $clave('inv_conteo_contar'); ?><input type="hidden" name="conteo_id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="linea_id" value="<?= (int)$l['id'] ?>"><input name="cantidad" type="number" step="0.001" min="0" required aria-label="Cantidad contada de <?= s($l['nombre']) ?>" placeholder="0 si no hay"><button class="boton boton-secundario"><?= $l['contada']?'Recontar':'Contar' ?></button></form><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php if($lineaRegistros&&$registros): ?><div class="ops-table-wrap"><table class="ops-table"><caption>Historial de lecturas de la línea (solo de anexar)</caption><thead><tr><th>Cuándo</th><th>Quién</th><th>Contado</th><th>Sistema en ese instante</th><th>Reservado</th><th>Último movimiento</th></tr></thead><tbody>
<?php foreach($registros as $g): ?><tr><td><?= s(substr($g['creado_en'],0,19)) ?></td><td><?= s($g['actor']??'—') ?></td><td><?= $cantidad($g['contado_milesimas']) ?></td><td><?= $cantidad($g['sistema_milesimas']) ?></td><td><?= $cantidad($g['reservado_milesimas']) ?></td><td>#<?= (int)$g['ultimo_mov_id'] ?></td></tr>
<?php endforeach; ?></tbody></table></div><?php endif; ?>

<?php if($c['estado']==='en_conteo'): ?>
<?php $formulario=['accion'=>'inv_conteo_cerrar','ocultos'=>['conteo_id'=>$c['id']],'campos'=>[],'boton'=>'Cerrar lecturas y enviar a aprobación (lo sin contar queda fuera de los ajustes)'];include __DIR__.'/_formulario.php'; ?>
<?php endif; ?>
<?php if($abierto): ?>
<details><summary>Cancelar el conteo</summary><?php $formulario=['accion'=>'inv_conteo_cancelar','ocultos'=>['conteo_id'=>$c['id']],'campos'=>['motivo'=>['etiqueta'=>'Motivo','longitud'=>300]],'boton'=>'Cancelar conteo (no cambia el stock)'];include __DIR__.'/_formulario.php'; ?></details>
<?php endif; ?>
<?php if($c['estado']==='pendiente'&&$aprueba):
 $cf=[];$cs=[];foreach($invConceptos as $k){if($k['tipo']==='salida')$cf[$k['id']]=$k['nombre'];else $cs[$k['id']]=$k['nombre'];} ?>
<h3>Aprobar el conteo</h3>
<p>Aprobar aplica los ajustes de las diferencias como <strong>diferencia sobre el stock actual</strong> (no se sobrescribe el stock). Exige el <strong>concepto</strong> de cada sentido y el <strong>motivo</strong>. Si hay conflictos que bloquean, no se aplica nada.</p>
<?php $campos=[];
 if(array_filter($c['lineas'],static fn($l)=>$l['tipo']==='salida'))$campos['concepto_faltante_id']=['tipo'=>'select','etiqueta'=>'Concepto de los faltantes (salidas)','opciones'=>$cf];
 if(array_filter($c['lineas'],static fn($l)=>$l['tipo']==='entrada'))$campos['concepto_sobrante_id']=['tipo'=>'select','etiqueta'=>'Concepto de los sobrantes (entradas)','opciones'=>$cs];
 $campos['motivo']=['etiqueta'=>'Motivo de la aprobación','longitud'=>300];
 if($t['avisos'])$campos['confirmar_movimientos']=['tipo'=>'checkbox','etiqueta'=>'Revisé los movimientos posteriores al conteo','requerido'=>false];
 $formulario=['accion'=>'inv_conteo_aprobar','ocultos'=>['conteo_id'=>$c['id']],'campos'=>$campos,'boton'=>'Aprobar y generar los ajustes'];include __DIR__.'/_formulario.php'; ?>
<details><summary>Rechazar el conteo</summary><?php $formulario=['accion'=>'inv_conteo_rechazar','ocultos'=>['conteo_id'=>$c['id']],'campos'=>['motivo'=>['etiqueta'=>'Motivo del rechazo','longitud'=>300]],'boton'=>'Rechazar (no cambia el stock)'];include __DIR__.'/_formulario.php'; ?></details>
<?php endif; ?>
<?php if($c['ajustes']): ?>
<div class="ops-table-wrap"><table class="ops-table"><caption>Ajustes generados por este conteo (se conservan; un reverso es otro ajuste)</caption><thead><tr><th>Producto</th><th>Rol</th><th>Sentido</th><th>Cantidad</th><th>Costo unitario</th><th>Valor</th><th>Concepto</th><th>Movimiento</th><th>Solicitud</th><th>Aplicado</th></tr></thead><tbody>
<?php foreach($c['ajustes'] as $a): ?><tr><td><?= s($a['nombre']) ?></td><td><?= $a['rol']==='conteo'?'Conteo':'Reverso' ?></td><td><?= s($a['tipo']) ?></td><td><?= $cantidad($a['cantidad_milesimas']) ?></td><td><?= $dinero($a['costo_centavos']) ?></td><td><?= $dinero($a['valor_centavos']) ?></td><td><?= s($a['concepto']) ?></td><td>#<?= (int)$a['movimiento_id'] ?></td><td>#<?= (int)$a['solicitud_id'] ?></td><td><?= s(substr($a['creado_en'],0,16)) ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php endif; ?>
<?php if($c['estado']==='aprobado'&&$aprueba && $c['ajustes']):
 $campos=[];
 if(array_filter($c['ajustes'],static fn($a)=>$a['tipo']==='salida'&&$a['rol']==='conteo'))$campos['concepto_entrada_id']=['tipo'=>'select','etiqueta'=>'Concepto de las entradas inversas (revierten faltantes)','opciones'=>array_column(array_filter($invConceptos,static fn($k)=>$k['tipo']==='entrada'),'nombre','id')];
 if(array_filter($c['ajustes'],static fn($a)=>$a['tipo']==='entrada'&&$a['rol']==='conteo'))$campos['concepto_salida_id']=['tipo'=>'select','etiqueta'=>'Concepto de las salidas inversas (revierten sobrantes)','opciones'=>array_column(array_filter($invConceptos,static fn($k)=>$k['tipo']==='salida'),'nombre','id')];
 $campos['motivo']=['etiqueta'=>'Motivo del reverso','longitud'=>300]; ?>
<details><summary>Revertir el conteo aprobado</summary><p>Genera un ajuste inverso por cada ajuste aplicado (una sola vez); los ajustes originales se conservan. No procede si el stock quedaría por debajo de lo reservado.</p><?php $formulario=['accion'=>'inv_conteo_revertir','ocultos'=>['conteo_id'=>$c['id']],'campos'=>$campos,'boton'=>'Revertir con ajustes inversos'];include __DIR__.'/_formulario.php'; ?></details>
<?php endif; ?>
</section>
<?php endif; ?>

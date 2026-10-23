<?php
$titulo='Presupuestos';include __DIR__.'/_inicio.php';
$meses=\Services\ErpPresupuestoService::MESES;
$pct=static fn(?int $c)=>$c===null?'—':number_format($c/100,2,'.',',').' %';
$dev=static fn(?int $c)=>$c===null?'—':(($c>0?'+':'').$dinero($c));
$estados=['borrador'=>'Borrador','revision'=>'Revisión','aprobada'=>'Aprobada','reemplazada'=>'Reemplazada'];
$estadoTxt=['sin_presupuesto'=>'Sin presupuesto','presupuesto_cero'=>'Presupuesto cero','con_presupuesto'=>'Con presupuesto'];
$tipoTxt=['servicio'=>'Servicio','producto'=>'Producto','gasto'=>'Gasto','comisiones'=>'Comisiones','sin_clasificar'=>'Sin clasificar'];
$v=$version;
?>
<section class="ui-card"><h2>Presupuestos</h2>
<p>Un presupuesto es un año y un escenario. Empieza vacío: aquí solo se guardan las cifras que tú escribes o cargas. <strong>No genera ventas, obligaciones, cobros ni asientos.</strong> Cada versión aprobada se conserva; los cambios se hacen en una revisión nueva.</p>
<div class="ops-table-wrap"><table class="ops-table"><caption>Presupuestos registrados</caption><thead><tr><th>Año</th><th>Escenario</th><th>Versión aprobada</th><th>Versión abierta</th></tr></thead><tbody>
<?php foreach($lista as $p): ?><tr><td><?= (int)$p['anio'] ?></td><td><?= s($p['escenario']) ?></td>
<td><?= $p['vigente_id']?'<a href="?version='.(int)$p['vigente_id'].'">v'.(int)$p['vigente_numero'].'</a>':'—' ?></td>
<td><?= $p['abierta_id']?'<a href="?version='.(int)$p['abierta_id'].'">v'.(int)$p['abierta_numero'].'</a>':'—' ?></td></tr>
<?php endforeach; ?><?php if(!$lista): ?><tr><td colspan="4">Todavía no hay presupuestos.</td></tr><?php endif; ?></tbody></table></div>
<?php if($edita): ?><details><summary>Nuevo presupuesto</summary>
<?php $formulario=['accion'=>'presupuesto_crear','campos'=>['anio'=>['etiqueta'=>'Año','tipo'=>'number','min'=>2000,'max'=>2100,'valor'=>(int)date('Y')],'escenario'=>['etiqueta'=>'Escenario (p. ej. base, optimista)','longitud'=>40,'valor'=>'base']],'boton'=>'Crear presupuesto vacío'];include __DIR__.'/_formulario.php'; ?></details><?php endif; ?>
</section>

<?php if($v): $editable=in_array($v['estado'],['borrador','revision'],true);$ocultos=['version_id'=>$v['id']]; ?>
<section class="ui-card"><h2>Presupuesto <?= (int)$v['anio'] ?> · <?= s($v['escenario']) ?> · versión <?= (int)$v['numero'] ?></h2>
<p><strong><?= s($estados[$v['estado']]) ?></strong>
<?php if($v['motivo']): ?> · Motivo de la revisión: <?= s($v['motivo']) ?><?php endif; ?>
<?php if($v['aprobado_en']): ?> · Aprobada por <?= s($v['aprobado_por_nombre']??'—') ?> el <?= s(substr($v['aprobado_en'],0,16)) ?><?php endif; ?>
 · Creada por <?= s($v['creado_por_nombre']??'—') ?> el <?= s(substr($v['creado_en'],0,16)) ?></p>
<p>Versiones: <?php foreach($v['versiones'] as $x): ?><a href="?version=<?= (int)$x['id'] ?>"<?= (int)$x['id']===(int)$v['id']?' aria-current="page"':'' ?>>v<?= (int)$x['numero'] ?> (<?= s(mb_strtolower($estados[$x['estado']])) ?>)</a> <?php endforeach; ?></p>
<?php if(!$editable): ?><p>Esta versión no se edita. <?php if($v['estado']==='aprobada'&&$edita): ?>Para cambiar cifras abre una revisión: parte de una copia y la aprobada sigue vigente hasta que apruebes la nueva.<?php endif; ?></p><?php endif; ?>
<?php if($v['estado']==='aprobada'&&$edita): ?><?php $formulario=['accion'=>'presupuesto_revisar','ocultos'=>['version_id'=>$v['id'],'presupuesto_id'=>$v['presupuesto_id']],'campos'=>['motivo'=>['etiqueta'=>'Motivo de la revisión','longitud'=>200]],'boton'=>'Abrir revisión'];include __DIR__.'/_formulario.php'; ?><?php endif; ?>
<?php if($editable&&$aprueba): ?><form class="formulario ui-filtros" method="POST" action="/gestion/accion" data-una-vez><?php $clave('presupuesto_aprobar'); ?><input type="hidden" name="version_id" value="<?= (int)$v['id'] ?>"><button class="boton">Aprobar esta versión</button><span> Queda fija y reemplaza a la aprobada anterior (que se conserva). Requiere al menos una cifra.</span></form><?php endif; ?>
</section>

<?php if($editable&&$edita): ?>
<section class="ui-card"><h2>Cifras del mes</h2>
<p>Elige el concepto y el mes. Mismo mes y concepto = misma cifra (se modifica). Ventas: base sin impuestos e impuesto, por separado (con impuestos = base + impuesto), netas de descuentos. Gastos y comisiones: solo base. Deja ambos importes vacíos para quitar la cifra; escribe 0 para un presupuesto en cero.</p>
<form class="formulario ui-filtros" method="POST" action="/gestion/accion" data-una-vez><?php $clave('presupuesto_linea'); ?><input type="hidden" name="version_id" value="<?= (int)$v['id'] ?>">
<div class="campo"><label for="p-concepto">Concepto</label><select id="p-concepto" name="concepto" required><option value="">Selecciona</option>
<optgroup label="Ventas · servicios"><?php foreach($conceptos['servicio']??[] as $c): ?><option value="servicio:<?= (int)$c['id'] ?>"><?= s($c['nombre']) ?></option><?php endforeach; ?></optgroup>
<optgroup label="Ventas · productos"><?php foreach($conceptos['producto']??[] as $c): ?><option value="producto:<?= (int)$c['id'] ?>"><?= s($c['nombre']) ?></option><?php endforeach; ?></optgroup>
<optgroup label="Gastos · cuenta contable"><?php foreach($conceptos['gasto']??[] as $c): ?><option value="gasto:<?= (int)$c['id'] ?>"><?= s($c['nombre']) ?></option><?php endforeach; ?></optgroup>
<optgroup label="Comisiones (partida propia)"><option value="comisiones:0">Comisiones</option></optgroup></select></div>
<div class="campo"><label for="p-mes">Mes</label><select id="p-mes" name="mes" required><option value="">Selecciona</option><?php foreach($meses as $n=>$m): ?><option value="<?= $n ?>"><?= s($m) ?></option><?php endforeach; ?></select></div>
<div class="campo"><label for="p-base">Base sin impuestos</label><input id="p-base" name="base" inputmode="decimal" maxlength="12" placeholder="0.00"></div>
<div class="campo"><label for="p-imp">Impuesto (solo ventas)</label><input id="p-imp" name="impuesto" inputmode="decimal" maxlength="12" placeholder="0.00"></div>
<button class="boton">Guardar cifra</button></form>
</section>

<section class="ui-card"><h2>Carga por plantilla CSV</h2>
<p><a href="/gestion/presupuestos/plantilla?version=<?= (int)$v['id'] ?>">Descargar plantilla</a> (todos los conceptos y meses, con las cifras actuales; vacía donde no hay presupuesto). Separador «;», punto decimal. Una fila sin base ni impuesto <strong>no cambia nada</strong> (no equivale a cero); para fijar cero escribe 0. Se valida todo el archivo y verás una vista previa; nada se guarda hasta que la confirmes.</p>
<form class="formulario ui-filtros" method="POST" action="/gestion/accion" enctype="multipart/form-data" data-una-vez><?php $clave('presupuesto_cargar'); ?><input type="hidden" name="version_id" value="<?= (int)$v['id'] ?>">
<div class="campo"><label for="p-archivo">Archivo CSV</label><input id="p-archivo" type="file" name="archivo" accept=".csv,text/csv"></div>
<div class="campo"><label for="p-csv">…o pega el contenido</label><textarea id="p-csv" name="csv" rows="6" maxlength="<?= \Services\ErpPresupuestoService::MAX_BYTES ?>"></textarea></div>
<button class="boton">Validar y ver vista previa</button></form>
<?php if($carga): ?>
<h3>Vista previa de la carga #<?= (int)$carga['id'] ?> · <?= s($carga['estado']==='previa'?'pendiente de confirmar':$carga['estado']) ?></h3>
<div class="ops-table-wrap"><table class="ops-table"><caption><?= (int)$carga['n_filas'] ?> filas que cambian cifras</caption><thead><tr><th>Mes</th><th>Tipo</th><th>Concepto</th><th>Antes (base / impuesto)</th><th>Después (base / impuesto)</th><th>Efecto</th></tr></thead><tbody>
<?php foreach($carga['filas'] as $f): ?><tr><td><?= s($meses[(int)$f['mes']]) ?></td><td><?= s($tipoTxt[$f['tipo']]) ?></td><td><?= s($f['nombre']) ?></td>
<td><?= $f['base_antes']===null?'—':$dinero($f['base_antes']).' / '.$dinero($f['impuesto_antes']) ?></td><td><?= $dinero($f['base']) ?> / <?= $dinero($f['impuesto']) ?></td><td><?= $f['accion']==='crear'?'Nueva':'Modifica' ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php if($carga['estado']==='previa'): ?><div class="ops-toolbar">
<form method="POST" action="/gestion/accion" data-una-vez><?php $clave('presupuesto_carga_confirmar'); ?><input type="hidden" name="version_id" value="<?= (int)$v['id'] ?>"><input type="hidden" name="carga_id" value="<?= (int)$carga['id'] ?>"><button class="boton">Confirmar y aplicar</button></form>
<form method="POST" action="/gestion/accion" data-una-vez><?php $clave('presupuesto_carga_descartar'); ?><input type="hidden" name="version_id" value="<?= (int)$v['id'] ?>"><input type="hidden" name="carga_id" value="<?= (int)$carga['id'] ?>"><button class="boton boton-secundario">Descartar</button></form></div><?php endif; ?>
<?php endif; ?>
</section>
<?php endif; ?>

<section class="ui-card"><h2>Cifras presupuestadas</h2>
<div class="ops-table-wrap"><table class="ops-table"><thead><tr><th>Concepto</th><th>Tipo</th><th>Mes</th><th>Base sin impuestos</th><th>Impuesto</th><th>Con impuestos</th><?php if($editable&&$edita): ?><th></th><?php endif; ?></tr></thead><tbody>
<?php foreach($lineas as $l): ?><tr><td><?= s($l['nombre']) ?></td><td><?= s($tipoTxt[$l['tipo']]) ?></td><td><?= s($meses[(int)$l['mes']]) ?></td><td><?= $dinero($l['base_centavos']) ?></td><td><?= $dinero($l['impuesto_centavos']) ?></td><td><?= $dinero($l['total_centavos']) ?></td>
<?php if($editable&&$edita): ?><td><form method="POST" action="/gestion/accion" data-una-vez><?php $clave('presupuesto_linea'); ?><input type="hidden" name="version_id" value="<?= (int)$v['id'] ?>"><input type="hidden" name="concepto" value="<?= s($l['tipo'].':'.$l['objetivo_id']) ?>"><input type="hidden" name="mes" value="<?= (int)$l['mes'] ?>"><input type="hidden" name="base" value=""><input type="hidden" name="impuesto" value=""><button class="boton boton-secundario">Quitar</button></form></td><?php endif; ?></tr>
<?php endforeach; ?><?php if(!$lineas): ?><tr><td colspan="7">Esta versión no tiene cifras: el presupuesto empieza vacío.</td></tr><?php endif; ?></tbody></table></div></section>

<?php $res=$comparativo['resumen']; ?>
<section class="ui-card"><h2>Presupuesto frente a lo real · <?= (int)$comparativo['anio'] ?></h2>
<details><summary>Fuente y fecha de cada métrica</summary><ul>
<li><strong>Ventas (devengado)</strong>: líneas de ventas confirmadas, por la fecha de confirmación de la venta, <strong>netas de descuentos</strong> (el descuento ya no está en el total de la línea). Fuente: <code>bi_presupuesto_real_mes</code>.</li>
<li><strong>Devoluciones</strong>: restan de las ventas en el mes en que se registra la devolución (fecha propia), con la proporción de base e impuesto de su línea. <strong>Una devolución posterior no reescribe el mes de la venta original</strong>: cae en el mes en que se hizo.</li>
<li><strong>Anulaciones</strong>: una venta anulada deja de contar en el mes en que se confirmó, y también sus devoluciones. Por eso <strong>anular una venta cambia el comparativo de meses ya pasados</strong>; la anulación (quién y cuándo) queda en el registro de eventos, no en la venta.</li>
<li><strong>Venta bruta, descuentos y devoluciones</strong> se muestran para explicar la cifra: bruta − descuentos = venta; venta − devoluciones = venta neta, que es la que se compara con el presupuesto (base sin impuestos; el impuesto se compara aparte).</li>
<li><strong>Cobros (caja)</strong>: flujo de dinero por la fecha del movimiento de caja, con impuestos; no es venta ni tiene presupuesto en esta versión. No incluye ventas pagadas con saldo prepago.</li>
<li><strong>Gastos (devengado)</strong>: libro contable, por la fecha del asiento, en cuentas de gasto y costo; un reverso resta. Las comisiones tienen su partida y <strong>no se cuentan otra vez</strong> dentro de «Gastos».</li>
<li><strong>Desviación</strong> = real − presupuesto. Porcentaje sobre el presupuesto; con presupuesto cero no se calcula porcentaje; sin cifra presupuestada se muestra «sin presupuesto» y no cuenta en la desviación. En ventas, positiva = vendiste más; en gastos, positiva = gastaste más.</li></ul></details>
<div class="ops-table-wrap"><table class="ops-table"><caption>Resumen del año (la desviación compara solo los meses y conceptos con presupuesto)</caption><thead><tr><th>Grupo</th><th>Presupuesto</th><th>Real (año completo)</th><th>Real comparable</th><th>Desviación</th><th>%</th></tr></thead><tbody>
<?php foreach([['Ventas sin impuestos','ventas','pb','rb','dev_base'],['Ventas con impuestos','ventas','pt','rt','dev_total']] as [$et,$g,$p,$r,$d]): ?><tr><td><?= s($et) ?></td><td><?= $res[$g]['hay']?$dinero($res[$g][$p]):'—' ?></td><td><?= $dinero($res[$g][$r]) ?></td><td><?= $dinero($res[$g][$r.'_comparable']) ?></td><td><?= $dev($res[$g][$d]['desviacion']) ?></td><td><?= $pct($res[$g][$d]['pct_centesimas']) ?> <small><?= s($estadoTxt[$res[$g][$d]['estado']]) ?></small></td></tr>
<?php endforeach; ?>
<tr><td>Gastos de cuentas (sin comisiones)</td><td><?= $res['gastos']['hay']?$dinero($res['gastos']['pb']):'—' ?></td><td><?= $dinero($res['gastos']['rb']) ?></td><td><?= $dinero($res['gastos']['rb_comparable']) ?></td><td><?= $dev($res['gastos']['dev_base']['desviacion']) ?></td><td><?= $pct($res['gastos']['dev_base']['pct_centesimas']) ?> <small><?= s($estadoTxt[$res['gastos']['dev_base']['estado']]) ?></small></td></tr>
<tr><td>Comisiones (partida propia)</td><td><?= $res['comisiones']['hay']?$dinero($res['comisiones']['pb']):'—' ?></td><td><?= $dinero($res['comisiones']['rb']) ?></td><td><?= $dinero($res['comisiones']['rb_comparable']) ?></td><td><?= $dev($res['comisiones']['dev_base']['desviacion']) ?></td><td><?= $pct($res['comisiones']['dev_base']['pct_centesimas']) ?> <small><?= s($estadoTxt[$res['comisiones']['dev_base']['estado']]) ?></small></td></tr>
<tr><th>Gastos totales (cuentas + comisiones, una vez)</th><th><?= $res['gastos_totales']['hay']?$dinero($res['gastos_totales']['pb']):'—' ?></th><th><?= $dinero($res['gastos_totales']['rb']) ?></th><th><?= $dinero($res['gastos_totales']['rb_comparable']) ?></th><th><?= $dev($res['gastos_totales']['dev']['desviacion']) ?></th><th><?= $pct($res['gastos_totales']['dev']['pct_centesimas']) ?> <small><?= s($estadoTxt[$res['gastos_totales']['dev']['estado']]) ?></small></th></tr>
</tbody></table></div>
<?php $k=$res['ventas']['componentes']; ?>
<div class="ops-table-wrap"><table class="ops-table"><caption>De dónde sale la venta que se compara (base sin impuestos, año completo)</caption><thead><tr><th>Venta bruta (antes de descuentos)</th><th>− Descuentos</th><th>= Venta</th><th>− Devoluciones (en el mes en que se registran)</th><th>= Venta neta (la que se compara con el presupuesto)</th></tr></thead><tbody>
<tr><td><?= $dinero($k['bruta']) ?></td><td><?= $dinero($k['descuentos']) ?></td><td><?= $dinero($k['venta']) ?></td><td><?= $dinero($k['devoluciones']) ?></td><td><strong><?= $dinero($k['neta']) ?></strong></td></tr></tbody></table></div>
<div class="ops-table-wrap"><table class="ops-table"><caption>Consolidado anual por concepto (la desviación compara solo los meses con presupuesto)</caption><thead><tr><th>Tipo</th><th>Concepto</th><th>Meses con presupuesto</th><th>Presup. base</th><th>Presup. con imp.</th><th>Real base (año)</th><th>Real base comparable</th><th>Desv. base</th><th>%</th><th>Desv. con imp.</th><th>%</th><th>Estado</th></tr></thead><tbody>
<?php foreach($comparativo['anual'] as $x): ?><tr><td><?= s($tipoTxt[$x['tipo']]) ?></td><td><?= s($x['nombre']??'') ?></td><td><?= (int)$x['meses'] ?></td><td><?= $x['meses']?$dinero($x['pb']):'—' ?></td><td><?= $x['meses']?$dinero($x['pt']):'—' ?></td><td><?= $dinero($x['rb']) ?></td><td><?= $dinero($x['rb_comparable']) ?></td>
<td><?= $dev($x['dev_base']['desviacion']) ?></td><td><?= $pct($x['dev_base']['pct_centesimas']) ?></td><td><?= $dev($x['dev_total']['desviacion']) ?></td><td><?= $pct($x['dev_total']['pct_centesimas']) ?></td><td><?= s($estadoTxt[$x['dev_base']['estado']]) ?></td></tr>
<?php endforeach; ?><?php if(!$comparativo['anual']): ?><tr><td colspan="12">Sin presupuesto ni movimientos reales en el año.</td></tr><?php endif; ?></tbody></table></div>
<div class="ops-table-wrap"><table class="ops-table"><caption>Detalle por mes y concepto</caption><thead><tr><th>Mes</th><th>Tipo</th><th>Concepto</th><th>Presup. base</th><th>Presup. impuesto</th><th>Venta bruta</th><th>Descuentos</th><th>Devoluciones</th><th>Real base</th><th>Real impuesto</th><th>Desv. base</th><th>%</th><th>Desv. con imp.</th><th>%</th><th>Estado</th></tr></thead><tbody>
<?php foreach($comparativo['filas'] as $f): ?><tr><td><?= s($meses[$f['mes']]) ?></td><td><?= s($tipoTxt[$f['tipo']]) ?></td><td><?= s($f['nombre']??'') ?></td>
<td><?= $f['presupuesto_base']===null?'—':$dinero($f['presupuesto_base']) ?></td><td><?= $f['presupuesto_impuesto']===null?'—':$dinero($f['presupuesto_impuesto']) ?></td>
<?php if($f['componentes']!==null): ?><td><?= $dinero($f['componentes']['bruta']) ?></td><td><?= $dinero($f['componentes']['descuentos']) ?></td><td><?= $dinero($f['componentes']['devoluciones']) ?></td><?php else: ?><td>—</td><td>—</td><td>—</td><?php endif; ?>
<td><?= $dinero($f['real_base']) ?></td><td><?= $dinero($f['real_impuesto']) ?></td>
<td><?= $dev($f['dev_base']['desviacion']) ?></td><td><?= $pct($f['dev_base']['pct_centesimas']) ?></td><td><?= $dev($f['dev_total']['desviacion']) ?></td><td><?= $pct($f['dev_total']['pct_centesimas']) ?></td><td><?= s($estadoTxt[$f['dev_base']['estado']]) ?></td></tr>
<?php endforeach; ?><?php if(!$comparativo['filas']): ?><tr><td colspan="15">Sin presupuesto ni movimientos reales en el año.</td></tr><?php endif; ?></tbody></table></div>
<div class="ops-table-wrap"><table class="ops-table"><caption>Cobros de caja del año (flujo de dinero, con impuestos; sin presupuesto en esta versión)</caption><thead><tr><th>Mes</th><th>Cobros</th><th>Devoluciones de cobro</th><th>Cobros netos</th></tr></thead><tbody>
<?php foreach($comparativo['cobros'] as $c): ?><tr><td><?= s($meses[(int)$c['mes']]) ?></td><td><?= $dinero($c['cobros_centavos']) ?></td><td><?= $dinero($c['devoluciones_de_cobro_centavos']) ?></td><td><?= $dinero($c['cobros_netos_centavos']) ?></td></tr>
<?php endforeach; ?><?php if(!$comparativo['cobros']): ?><tr><td colspan="4">Sin cobros de caja en el año.</td></tr><?php endif; ?></tbody></table></div>
</section>

<section class="ui-card"><h2>Historial de cambios de esta versión</h2>
<div class="ops-table-wrap"><table class="ops-table"><caption>Hasta 100 cambios recientes: quién, cuándo y qué valor</caption><thead><tr><th>Fecha</th><th>Quién</th><th>Mes</th><th>Tipo</th><th>Acción</th><th>Antes</th><th>Después</th><th>Origen</th></tr></thead><tbody>
<?php foreach($cambios as $c): ?><tr><td><?= s(substr($c['creado_en'],0,16)) ?></td><td><?= s($c['actor']??'—') ?></td><td><?= s($meses[(int)$c['mes']]) ?></td><td><?= s($tipoTxt[$c['tipo']]) ?></td><td><?= s($c['accion']) ?></td>
<td><?= $c['base_antes_centavos']===null?'—':$dinero($c['base_antes_centavos']).' / '.$dinero($c['impuesto_antes_centavos']) ?></td><td><?= $c['base_despues_centavos']===null?'—':$dinero($c['base_despues_centavos']).' / '.$dinero($c['impuesto_despues_centavos']) ?></td><td><?= s($c['origen']) ?></td></tr>
<?php endforeach; ?><?php if(!$cambios): ?><tr><td colspan="8">Sin cambios registrados.</td></tr><?php endif; ?></tbody></table></div></section>
<?php endif; ?>

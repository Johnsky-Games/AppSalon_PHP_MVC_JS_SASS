<?php $titulo='Contabilidad';include __DIR__.'/_inicio.php';
$ver=['resumen'=>'Resumen','diario'=>'Libro diario','mayor'=>'Libro mayor','balances'=>'Balances y resultados','conciliacion'=>'Conciliación','plan'=>'Plan de cuentas','periodos'=>'Períodos y cierre'];
if($registra)$ver['asiento']='Asiento manual';
$tiposTexto=['activo'=>'Activo','pasivo'=>'Pasivo','patrimonio'=>'Patrimonio','ingreso'=>'Ingreso','costo'=>'Costo','gasto'=>'Gasto'];
$filtro=static function(string $vista,array $q,string $desde,string $hasta,bool $fechas=true):void{ ?>
<form class="formulario ui-filtros ui-card" method="GET"><input type="hidden" name="vista" value="<?= s($vista) ?>">
<?php foreach($q as $k=>$v): ?><input type="hidden" name="<?= s($k) ?>" value="<?= s($v) ?>"><?php endforeach; ?>
<div class="campo"><label for="c-desde">Desde</label><input id="c-desde" type="date" name="desde" value="<?= s($desde) ?>" required></div>
<div class="campo"><label for="c-hasta">Hasta</label><input id="c-hasta" type="date" name="hasta" value="<?= s($hasta) ?>" required></div><button class="boton">Consultar</button></form>
<?php };
$tabla=static function(array $filas,callable $f):string{$h='';foreach($filas as $x)$h.=$f($x);return $h;};
?>
<p role="status">Contabilidad propia por partida doble. Las cuentas, los roles y las reglas son una política operativa de ensayo que debe validar el contador antes de usarse como contabilidad oficial. Los asientos no se editan ni se borran: se corrigen con un reverso.</p>
<nav class="ops-toolbar" aria-label="Contabilidad"><?php foreach($ver as $k=>$t): ?><a href="?vista=<?= s($k) ?>"<?= $k===$vista?' aria-current="page"':'' ?>><?= s($t) ?></a><?php endforeach; ?></nav>
<?php if(!$config['inicio_contable']): ?>
<section class="ui-card"><h2>Inicio contable pendiente</h2><p>Mientras no se defina la fecha de inicio, los hechos económicos esperan en la cola y ningún asiento se registra. Los hechos anteriores a la fecha elegida se confirman sin asiento: el saldo de apertura se registra con un asiento de apertura.</p>
<?php if($configura): $formulario=['accion'=>'cont_inicio','ocultos'=>['vista'=>'resumen'],'campos'=>['fecha'=>['etiqueta'=>'Fecha de inicio contable','tipo'=>'date','valor'=>$hoy]],'boton'=>'Definir inicio contable'];include __DIR__.'/_formulario.php'; else: ?><p>Solo quien configura la contabilidad puede definirlo.</p><?php endif; ?></section>
<?php endif; ?>

<?php if($vista==='resumen'): ?>
<section class="ui-card"><h2>Estado</h2>
<p>Inicio contable: <strong><?= s($config['inicio_contable']??'sin definir') ?></strong> · Moneda <?= s($config['moneda']) ?></p>
<p>Libro: <strong><?= $conc['integridad']?'con inconsistencias':'íntegro' ?></strong> · Balance de comprobación: <strong><?= $conc['balance_cuadra']?'cuadra':'NO cuadra' ?></strong></p>
<p>Hechos del negocio sin contabilizar: <strong><?= (int)$conc['eventos_sin_contabilizar'] ?></strong> · Servicios prestados sin contabilizar: <strong><?= (int)$conc['prestaciones_sin_contabilizar'] ?></strong> <small>(el supervisor <code>scripts/procesar_erp.php</code> los procesa; los que fallan quedan visibles en <a href="/gestion/integraciones">Integraciones</a>)</small></p>
<?php foreach($conc['integridad'] as $m): ?><p role="alert"><?= s($m) ?></p><?php endforeach; ?></section>
<section class="ui-card"><h2>Períodos recientes</h2><div class="ops-table-wrap"><table class="ops-table"><thead><tr><th>Período</th><th>Estado</th><th>Cerrado</th></tr></thead><tbody>
<?= $tabla($periodos,fn($p)=>'<tr><td>'.s(sprintf('%04d-%02d',$p['anio'],$p['mes'])).'</td><td>'.s($p['estado']).'</td><td>'.s($p['cerrado_en']??'').'</td></tr>') ?>
<?php if(!$periodos): ?><tr><td colspan="3">Aún no hay períodos: se crean con el primer asiento.</td></tr><?php endif; ?></tbody></table></div></section>
<?php endif; ?>

<?php if($vista==='diario'): $filtro('diario',[],$desde,$hasta); ?>
<p><?= (int)$diario['total'] ?> asientos · página <?= (int)$diario['pagina'] ?> de <?= (int)$diario['paginas'] ?></p>
<?php foreach($diario['asientos'] as $a): ?>
<section class="ui-card"><h2>Asiento <?= (int)$a['numero'] ?> · <?= s($a['fecha']) ?> · <?= s($a['tipo']) ?></h2><p><?= s($a['concepto']) ?></p><p><small>Origen <code><?= s($a['origen']) ?></code><?= $a['reversa_de_id']?' · reversa el asiento #'.(int)$a['reversa_de_id']:'' ?><?= $a['reversado_por']?' · reversado por el asiento #'.(int)$a['reversado_por']:'' ?></small></p>
<div class="ops-table-wrap"><table class="ops-table"><thead><tr><th>Cuenta</th><th>Debe</th><th>Haber</th><th>Tercero / referencia</th></tr></thead><tbody>
<?= $tabla($a['lineas'],fn($l)=>'<tr><td>'.s($l['codigo'].' '.$l['nombre']).'</td><td>'.($l['debe_centavos']?s($dinero($l['debe_centavos'])):'').'</td><td>'.($l['haber_centavos']?s($dinero($l['haber_centavos'])):'').'</td><td>'.s(trim(($l['contacto_id']?'#'.$l['contacto_id'].' ':'').($l['referencia']??''))).'</td></tr>') ?>
</tbody></table></div>
<?php if($registra&&$a['tipo']!=='reverso'&&!$a['reversado_por']): $formulario=['accion'=>'cont_reverso','ocultos'=>['asiento_id'=>$a['id'],'vista'=>'diario'],'campos'=>['motivo'=>['etiqueta'=>'Motivo del reverso','longitud'=>160],'fecha'=>['etiqueta'=>'Fecha del reverso (período abierto)','tipo'=>'date','valor'=>$hoy]],'boton'=>'Reversar este asiento'];include __DIR__.'/_formulario.php'; endif; ?></section>
<?php endforeach; ?>
<nav class="ops-toolbar"><?php foreach(['Anterior'=>$diario['pagina']-1,'Siguiente'=>$diario['pagina']+1] as $t=>$n): if($n<1||$n>$diario['paginas'])continue; ?><a href="?<?= s(http_build_query(['vista'=>'diario','desde'=>$desde,'hasta'=>$hasta,'pagina'=>$n])) ?>"><?= s($t) ?></a><?php endforeach; ?></nav>
<?php endif; ?>

<?php if($vista==='mayor'): ?>
<form class="formulario ui-filtros ui-card" method="GET"><input type="hidden" name="vista" value="mayor">
<div class="campo"><label for="m-cuenta">Cuenta</label><select id="m-cuenta" name="cuenta" required><option value="">Selecciona</option><?php foreach($cuentas as $c): if(!$c['imputable'])continue; ?><option value="<?= (int)$c['id'] ?>"<?= (int)$c['id']===$cuentaId?' selected':'' ?>><?= s($c['codigo'].' '.$c['nombre']) ?></option><?php endforeach; ?></select></div>
<div class="campo"><label for="m-desde">Desde</label><input id="m-desde" type="date" name="desde" value="<?= s($desde) ?>" required></div><div class="campo"><label for="m-hasta">Hasta</label><input id="m-hasta" type="date" name="hasta" value="<?= s($hasta) ?>" required></div><button class="boton">Consultar</button></form>
<?php if(isset($mayor)): ?><section class="ui-card"><h2><?= s($mayor['cuenta']['codigo'].' '.$mayor['cuenta']['nombre']) ?></h2><p>Saldo inicial <?= s($dinero($mayor['saldo_inicial'])) ?> · Saldo final <strong><?= s($dinero($mayor['saldo_final'])) ?></strong></p>
<div class="ops-table-wrap"><table class="ops-table"><thead><tr><th>Fecha</th><th>Asiento</th><th>Concepto</th><th>Debe</th><th>Haber</th><th>Saldo</th></tr></thead><tbody>
<?= $tabla($mayor['movimientos'],fn($m)=>'<tr><td>'.s($m['fecha']).'</td><td>'.(int)$m['numero'].'</td><td>'.s($m['concepto']).'</td><td>'.($m['debe_centavos']?s($dinero($m['debe_centavos'])):'').'</td><td>'.($m['haber_centavos']?s($dinero($m['haber_centavos'])):'').'</td><td>'.s($dinero($m['saldo'])).'</td></tr>') ?>
<?php if(!$mayor['movimientos']): ?><tr><td colspan="6">Sin movimientos en el rango.</td></tr><?php endif; ?></tbody></table></div></section><?php endif; ?>
<?php endif; ?>

<?php if($vista==='balances'): $filtro('balances',[],$desde,$hasta); ?>
<section class="ui-card"><h2>Estado de resultados · <?= s($desde) ?> a <?= s($hasta) ?></h2>
<?php foreach(['ingreso'=>'Ingresos','costo'=>'Costos','gasto'=>'Gastos'] as $t=>$titulo2): ?><h3><?= s($titulo2) ?> <?= s($dinero($resultados['totales'][$t])) ?></h3><div class="ops-table-wrap"><table class="ops-table"><tbody><?= $tabla($resultados['secciones'][$t],fn($x)=>'<tr><td>'.s($x['codigo'].' '.$x['nombre']).'</td><td>'.s($dinero($x['valor'])).'</td></tr>') ?></tbody></table></div><?php endforeach; ?>
<p>Utilidad bruta <strong><?= s($dinero($resultados['utilidad_bruta'])) ?></strong> · Resultado <strong><?= s($dinero($resultados['resultado'])) ?></strong> <small>(no incluye el asiento de cierre del ejercicio)</small></p></section>
<section class="ui-card"><h2>Balance general al <?= s($hasta) ?></h2>
<?php foreach(['activo'=>'Activo','pasivo'=>'Pasivo','patrimonio'=>'Patrimonio'] as $t=>$titulo2): ?><h3><?= s($titulo2) ?> <?= s($dinero($general['totales'][$t])) ?></h3><div class="ops-table-wrap"><table class="ops-table"><tbody><?= $tabla($general['secciones'][$t],fn($x)=>'<tr><td>'.s($x['codigo'].' '.$x['nombre']).'</td><td>'.s($dinero($x['valor'])).'</td></tr>') ?></tbody></table></div><?php endforeach; ?>
<p>Resultado del ejercicio sin cerrar <?= s($dinero($general['resultado_sin_cerrar'])) ?> · Pasivo + patrimonio <?= s($dinero($general['pasivo_mas_patrimonio'])) ?> · <strong><?= $general['cuadra']?'El balance cuadra':'El balance NO cuadra' ?></strong></p></section>
<section class="ui-card"><h2>Balance de comprobación al <?= s($hasta) ?></h2><div class="ops-table-wrap"><table class="ops-table"><thead><tr><th>Cuenta</th><th>Debe</th><th>Haber</th><th>Saldo deudor</th><th>Saldo acreedor</th></tr></thead><tbody>
<?= $tabla($comprobacion['filas'],fn($f)=>'<tr><td>'.s($f['codigo'].' '.$f['nombre']).'</td><td>'.s($dinero($f['debe'])).'</td><td>'.s($dinero($f['haber'])).'</td><td>'.($f['saldo_deudor']?s($dinero($f['saldo_deudor'])):'').'</td><td>'.($f['saldo_acreedor']?s($dinero($f['saldo_acreedor'])):'').'</td></tr>') ?>
<tr><th>Totales</th><th><?= s($dinero($comprobacion['debe'])) ?></th><th><?= s($dinero($comprobacion['haber'])) ?></th><th colspan="2"><?= $comprobacion['cuadra']?'Cuadra':'NO cuadra' ?></th></tr></tbody></table></div></section>
<?php endif; ?>

<?php if($vista==='conciliacion'): $filtro('conciliacion',[],$desde,$hasta); ?>
<section class="ui-card"><h2>Libro frente a los auxiliares del negocio</h2><p>Una diferencia nunca se ajusta sola: se explica (hecho sin contabilizar o movimiento sin regla) y se corrige con la regla o con un asiento.</p>
<div class="ops-table-wrap"><table class="ops-table"><thead><tr><th>Control</th><th>Libro</th><th>Auxiliar</th><th>Diferencia</th><th>Fuente del auxiliar</th></tr></thead><tbody>
<?= $tabla($conc['controles'],fn($c)=>'<tr><td>'.s($c['nombre']).'</td><td>'.s($dinero($c['libro'])).'</td><td>'.s($dinero($c['auxiliar'])).'</td><td>'.($c['diferencia']===0?'0.00':'<strong>'.s($dinero($c['diferencia'])).'</strong>').'</td><td><small>'.s($c['fuente']).'</small></td></tr>') ?>
</tbody></table></div><p>Hechos sin contabilizar: <?= (int)$conc['eventos_sin_contabilizar'] ?> · Servicios prestados sin contabilizar: <?= (int)$conc['prestaciones_sin_contabilizar'] ?> · Libro <?= $conc['integridad']?'con inconsistencias':'íntegro' ?></p></section>
<?php endif; ?>

<?php if($vista==='plan'): ?>
<section class="ui-card"><h2>Plan de cuentas</h2><div class="ops-table-wrap"><table class="ops-table"><thead><tr><th>Código</th><th>Nombre</th><th>Tipo</th><th>Uso</th><th>Estado</th><th></th></tr></thead><tbody>
<?php foreach($cuentas as $c): ?><tr><td><?= s($c['codigo']) ?></td><td><?= s($c['nombre']) ?></td><td><?= s($tiposTexto[$c['tipo']]) ?></td><td><?= $c['imputable']?'Imputable':'Agrupación' ?></td><td><?= $c['activa']?'Activa':'Inactiva' ?></td>
<td><?php if($configura&&$c['imputable']): ?><form method="POST" action="/gestion/accion" data-una-vez><?php $clave('cont_cuenta_estado'); ?><input type="hidden" name="vista" value="plan"><input type="hidden" name="cuenta_id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="activa" value="<?= $c['activa']?'0':'1' ?>"><button class="boton"><?= $c['activa']?'Desactivar':'Activar' ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php if($configura): ?><section class="ui-card"><h2>Nueva cuenta</h2>
<?php $formulario=['accion'=>'cont_cuenta','ocultos'=>['vista'=>'plan'],'campos'=>['codigo'=>['etiqueta'=>'Código (p. ej. 1.1.07)','longitud'=>20],'nombre'=>['etiqueta'=>'Nombre','longitud'=>120],'tipo'=>['etiqueta'=>'Tipo','tipo'=>'select','opciones'=>$tiposTexto],'padre'=>['etiqueta'=>'Código de la cuenta de agrupación padre','longitud'=>20,'requerido'=>false],'imputable'=>['etiqueta'=>'Uso','tipo'=>'select','opciones'=>['1'=>'Imputable (recibe asientos)','0'=>'Agrupación'],'valor'=>'1']],'boton'=>'Crear cuenta'];include __DIR__.'/_formulario.php'; ?></section>
<section class="ui-card"><h2>Roles contables</h2><p>Cada regla automática contabiliza contra un rol, no contra un código fijo; reasignar un rol afecta solo los asientos futuros.</p><div class="ops-table-wrap"><table class="ops-table"><thead><tr><th>Rol</th><th>Cuenta</th></tr></thead><tbody><?= $tabla($roles,fn($x)=>'<tr><td>'.s($x['rol']).'</td><td>'.s($x['codigo'].' '.$x['nombre']).'</td></tr>') ?></tbody></table></div>
<?php $formulario=['accion'=>'cont_rol','ocultos'=>['vista'=>'plan'],'campos'=>['rol'=>['etiqueta'=>'Rol','tipo'=>'select','opciones'=>array_combine(array_keys($rolesDisponibles),array_map(fn($k,$t)=>$k.' ('.$t.')',array_keys($rolesDisponibles),$rolesDisponibles))],'cuenta'=>['etiqueta'=>'Código de la cuenta','longitud'=>20]],'boton'=>'Asignar rol'];include __DIR__.'/_formulario.php'; ?></section><?php endif; ?>
<?php endif; ?>

<?php if($vista==='periodos'): ?>
<section class="ui-card"><h2>Períodos</h2><div class="ops-table-wrap"><table class="ops-table"><thead><tr><th>Período</th><th>Estado</th><th>Cerrado</th></tr></thead><tbody>
<?= $tabla($periodos,fn($p)=>'<tr><td>'.s(sprintf('%04d-%02d',$p['anio'],$p['mes'])).'</td><td>'.s($p['estado']).'</td><td>'.s($p['cerrado_en']??'').'</td></tr>') ?><?php if(!$periodos): ?><tr><td colspan="3">Aún no hay períodos.</td></tr><?php endif; ?></tbody></table></div></section>
<?php if($configura): ?>
<section class="ui-card"><h2>Cerrar un período</h2><p>Se cierra en orden, sin hechos del negocio pendientes de contabilizar y con el libro íntegro. Un período cerrado no recibe asientos.</p>
<?php $formulario=['accion'=>'cont_periodo_cerrar','ocultos'=>['vista'=>'periodos'],'campos'=>['anio'=>['etiqueta'=>'Año','tipo'=>'number','valor'=>substr($hoy,0,4)],'mes'=>['etiqueta'=>'Mes (1-12)','tipo'=>'number','valor'=>(int)substr($hoy,5,2)]],'boton'=>'Cerrar período'];include __DIR__.'/_formulario.php'; ?></section>
<section class="ui-card"><h2>Reabrir un período</h2><p>Solo el último período cerrado, con motivo; queda en la auditoría.</p>
<?php $formulario=['accion'=>'cont_periodo_reabrir','ocultos'=>['vista'=>'periodos'],'campos'=>['anio'=>['etiqueta'=>'Año','tipo'=>'number','valor'=>substr($hoy,0,4)],'mes'=>['etiqueta'=>'Mes (1-12)','tipo'=>'number','valor'=>(int)substr($hoy,5,2)],'motivo'=>['etiqueta'=>'Motivo','longitud'=>200]],'boton'=>'Reabrir período'];include __DIR__.'/_formulario.php'; ?></section>
<section class="ui-card"><h2>Cerrar el ejercicio</h2><p>Traslada ingresos, costos y gastos del año al resultado del ejercicio con un asiento fechado el 31 de diciembre. Exige enero a noviembre cerrados y diciembre abierto.</p>
<?php $formulario=['accion'=>'cont_ejercicio_cerrar','ocultos'=>['vista'=>'periodos'],'campos'=>['anio'=>['etiqueta'=>'Año','tipo'=>'number','valor'=>substr($hoy,0,4)]],'boton'=>'Cerrar ejercicio'];include __DIR__.'/_formulario.php'; ?></section>
<?php endif; endif; ?>

<?php if($vista==='asiento'&&$registra): ?>
<section class="ui-card"><h2>Asiento manual</h2><p>El debe y el haber deben sumar lo mismo. Usa asientos manuales para la apertura, ajustes y hechos que el sistema no genera; el tercero es opcional (número de contacto).</p>
<form class="formulario" method="POST" action="/gestion/accion" data-una-vez><?php $clave('cont_asiento'); ?><input type="hidden" name="vista" value="asiento">
<div class="campo"><label for="a-fecha">Fecha</label><input id="a-fecha" type="date" name="fecha" value="<?= s($hoy) ?>" required></div>
<div class="campo"><label for="a-concepto">Concepto</label><input id="a-concepto" name="concepto" maxlength="240" required></div>
<div class="campo"><label><input type="checkbox" name="apertura" value="1"> Es el asiento de apertura (lleva la fecha de inicio contable)</label></div>
<div class="ops-table-wrap"><table class="ops-table"><thead><tr><th>Cuenta imputable</th><th>Debe</th><th>Haber</th><th>Tercero</th><th>Referencia</th></tr></thead><tbody>
<?php for($i=0;$i<6;$i++): ?><tr><td><input list="cuentas-imputables" name="lineas[<?= $i ?>][cuenta]" aria-label="Cuenta de la línea <?= $i+1 ?>" autocomplete="off"></td><td><input name="lineas[<?= $i ?>][debe]" inputmode="decimal" aria-label="Debe de la línea <?= $i+1 ?>"></td><td><input name="lineas[<?= $i ?>][haber]" inputmode="decimal" aria-label="Haber de la línea <?= $i+1 ?>"></td><td><input name="lineas[<?= $i ?>][contacto_id]" inputmode="numeric" aria-label="Tercero de la línea <?= $i+1 ?>"></td><td><input name="lineas[<?= $i ?>][referencia]" maxlength="80" aria-label="Referencia de la línea <?= $i+1 ?>"></td></tr><?php endfor; ?>
</tbody></table></div>
<datalist id="cuentas-imputables"><?php foreach($cuentas as $c): if(!$c['imputable']||!$c['activa'])continue; ?><option value="<?= s($c['codigo']) ?>"><?= s($c['nombre']) ?></option><?php endforeach; ?></datalist>
<button class="boton">Registrar asiento</button></form></section>
<?php endif; ?>

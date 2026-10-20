<?php
/** $formulario: contrato de presentación definido por la vista; las reglas viven en el servicio. */
$erpFormN=($erpFormN??0)+1;$prefijo='erp-form-'.$erpFormN;
?>
<form class="formulario ui-filtros" method="POST" action="/gestion/accion" data-una-vez>
<?php $clave($formulario['accion'],$formulario['venta_id']??null);foreach($formulario['ocultos']??[] as $k=>$v): ?><input type="hidden" name="<?= s($k) ?>" value="<?= s($v) ?>"><?php endforeach; ?>
<?php foreach($formulario['campos'] as $k=>$campo):$fid=$prefijo.'-'.$k;$tipo=$campo['tipo']??'text'; ?>
<div class="campo"><label for="<?= s($fid) ?>"><?= s($campo['etiqueta']) ?></label>
<?php if($tipo==='select'): ?><select id="<?= s($fid) ?>" name="<?= s($k) ?>"<?= ($campo['requerido']??true)?' required':'' ?>><option value="">Selecciona</option><?php foreach($campo['opciones'] as $val=>$et): ?><option value="<?= s($val) ?>"<?= (string)($campo['valor']??'')===(string)$val?' selected':'' ?>><?= s($et) ?></option><?php endforeach; ?></select>
<?php elseif($tipo==='textarea'): ?><textarea id="<?= s($fid) ?>" name="<?= s($k) ?>" rows="<?= (int)($campo['filas']??6) ?>" maxlength="<?= (int)($campo['longitud']??2000) ?>"<?= ($campo['requerido']??true)?' required':'' ?>><?= s($campo['valor']??'') ?></textarea>
<?php elseif($tipo==='checkbox'): ?><input type="checkbox" id="<?= s($fid) ?>" name="<?= s($k) ?>" value="1">
<?php elseif($tipo==='casillas'): ?><div class="ui-day-group" id="<?= s($fid) ?>"><?php foreach($campo['opciones'] as $val=>$et): ?><label><input type="checkbox" name="<?= s($k) ?>[]" value="<?= s($val) ?>"> <?= s($et) ?></label><?php endforeach; ?></div>
<?php elseif($tipo==='dias'): ?><div class="ui-day-group" id="<?= s($fid) ?>"><?php foreach([1=>'Lun',2=>'Mar',3=>'Mié',4=>'Jue',5=>'Vie',6=>'Sáb',7=>'Dom'] as $val=>$et): ?><label><input type="checkbox" name="<?= s($k) ?>[]" value="<?= $val ?>"><?= s($et) ?></label><?php endforeach; ?></div>
<?php else: ?><input id="<?= s($fid) ?>" name="<?= s($k) ?>" type="<?= s($tipo) ?>" value="<?= s($campo['valor']??'') ?>"<?= ($campo['requerido']??true)?' required':'' ?><?= isset($campo['paso'])?' step="'.s($campo['paso']).'"':'' ?><?= isset($campo['min'])?' min="'.s($campo['min']).'"':'' ?><?= isset($campo['max'])?' max="'.s($campo['max']).'"':'' ?><?= in_array($tipo,['text','email'],true)?' maxlength="'.(int)($campo['longitud']??180).'"':'' ?>><?php endif; ?></div>
<?php endforeach; ?><button class="boton"><?= s($formulario['boton']??'Guardar') ?></button></form>

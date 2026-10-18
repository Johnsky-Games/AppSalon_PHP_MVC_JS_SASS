<?php
// El servidor resuelve el atajo. Las URLs de resultados y exportación conservan las fechas concretas.
$periodoSeleccionado=is_string($_GET['periodo']??null)?$_GET['periodo']:'personalizado';
$periodoId='periodo-'.($periodoContexto??'consulta');
?>
<div class="campo ui-periodo"><label for="<?= s($periodoId) ?>">Período</label><select id="<?= s($periodoId) ?>" name="periodo" data-periodo>
<?php foreach (\Classes\PeriodoFiltro::OPCIONES as $valor=>$etiqueta): if (!empty($periodoHistorico)&&in_array($valor,['manana','proxima_semana'],true)) continue; ?>
<option value="<?= s($valor) ?>"<?= $valor===$periodoSeleccionado?' selected':'' ?>><?= s($etiqueta) ?></option>
<?php endforeach; ?></select><small>Para elegir fechas propias, usa «Personalizado».</small></div>

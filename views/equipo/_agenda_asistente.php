<?php
/**
 * Asistente de agenda del profesional: servicios que presta + horario semanal (varias franjas por día) + descansos.
 * Lo comparten la invitación (views/equipo/index.php) y la ficha (views/equipo/ficha.php).
 *
 * Variables esperadas en $ag:
 *   prefijo (string, para ids únicos), servicios (catálogo), seleccion (ids elegidos), franjas[dia][n][inicio|fin],
 *   descansos[dia][n][inicio|fin|motivo], mostrarServicios (bool), editable (bool, por defecto true).
 * El servidor valida TODO de nuevo (Services\AgendaSemanalValidador); esto es solo ayuda de captura.
 */
$dias = \Model\HorarioProfesional::DIAS_SEMANA;
$pref = preg_replace('/[^a-z0-9_-]/i', '', (string)($ag['prefijo'] ?? 'ag'));
$franjas = is_array($ag['franjas'] ?? null) ? $ag['franjas'] : [];
$descansos = is_array($ag['descansos'] ?? null) ? $ag['descansos'] : [];
$seleccion = array_map('strval', array_filter((array)($ag['seleccion'] ?? []), 'is_scalar'));
$editable = ($ag['editable'] ?? true) !== false;
$val = static fn($fila, string $k): string => is_array($fila) && is_string($fila[$k] ?? null) ? $fila[$k] : '';
$fila = static function (string $tipo, int $dia, int $i, $datos, string $nombreDia) use ($val, $editable): string {
    $n = $tipo === 'franja' ? 'franjas' : 'descansos';
    $dis = $editable ? '' : ' disabled';
    ob_start(); ?>
<div class="ag-fila" data-ag-fila>
  <label class="ag-hora"><span>Desde</span><input type="time" step="300" name="<?= s($n) ?>[<?= $dia ?>][<?= $i ?>][inicio]" value="<?= s($val($datos, 'inicio')) ?>" aria-label="Hora de inicio del <?= $tipo === 'franja' ? 'turno' : 'descanso' ?> del <?= s($nombreDia) ?>"<?= $dis ?>></label>
  <label class="ag-hora"><span>Hasta</span><input type="time" step="300" name="<?= s($n) ?>[<?= $dia ?>][<?= $i ?>][fin]" value="<?= s($val($datos, 'fin')) ?>" aria-label="Hora de fin del <?= $tipo === 'franja' ? 'turno' : 'descanso' ?> del <?= s($nombreDia) ?>"<?= $dis ?>></label>
  <?php if ($tipo === 'descanso') { ?><label class="ag-motivo"><span>Motivo (opcional)</span><input type="text" maxlength="120" name="descansos[<?= $dia ?>][<?= $i ?>][motivo]" value="<?= s($val($datos, 'motivo')) ?>" placeholder="Almuerzo"<?= $dis ?>></label><?php } ?>
  <?php if ($editable) { ?><button type="button" class="boton ui-button-quiet ag-quitar" data-ag-quitar aria-label="Quitar este <?= $tipo === 'franja' ? 'turno' : 'descanso' ?> del <?= s($nombreDia) ?>">Quitar</button><?php } ?>
</div>
<?php return (string)ob_get_clean();
};
?>
<div class="ag-asistente" data-agenda-asistente>
<?php if (!empty($ag['mostrarServicios'])) { ?>
  <p class="ui-section-title">Servicios que presta</p>
  <?php if (empty($ag['servicios'])) { ?><p class="ui-form-help">Aún no hay servicios en el catálogo. Créalos primero en «Servicios».</p><?php } ?>
  <div class="listado-servicios-checks ui-grid"><?php foreach ((array)($ag['servicios'] ?? []) as $srv) { ?>
    <div class="check-servicio"><label for="<?= s($pref) ?>-srv-<?= s($srv->id) ?>"><input type="checkbox" id="<?= s($pref) ?>-srv-<?= s($srv->id) ?>" name="servicios[]" value="<?= s($srv->id) ?>" <?= in_array((string)$srv->id, $seleccion, true) ? 'checked' : '' ?><?= $editable ? '' : ' disabled' ?>> <?= s($srv->nombre) ?> (<?= s($srv->duracion_minutos) ?> min)</label></div>
  <?php } ?></div>
<?php } ?>
  <p class="ui-section-title">Horario semanal</p>
  <p class="ui-form-help">Añade una o varias franjas por día (por ejemplo una jornada partida) y, dentro de ellas, los descansos. Horas entre 00:00 y 23:59 en múltiplos de 5 minutos. Un día sin franjas no se atiende. Los bloqueos por fecha (vacaciones, permisos) se gestionan en «Profesionales».</p>
<?php if ($editable) { ?>
  <div class="ag-grupos">
    <?php $grupo = ['prefijo' => $pref . '-jornada', 'tipo' => 'horario', 'asistente' => true]; include __DIR__ . '/../templates/_grupo_dias.php'; ?>
    <?php $grupo = ['prefijo' => $pref . '-pausa', 'tipo' => 'descanso', 'asistente' => true]; include __DIR__ . '/../templates/_grupo_dias.php'; ?>
  </div>
  <details class="gestion-detalle"><summary>Revisar y ajustar día por día</summary>
  <div class="ag-herramientas"><button type="button" class="boton ui-button-quiet" data-ag-copiar-lunes>Copiar el lunes a martes–viernes</button></div>
<?php } ?>
  <div class="ag-dias">
<?php foreach ($dias as $d => $nombreDia) {
    $f = array_values(is_array($franjas[$d] ?? null) ? $franjas[$d] : []);
    if (!$f) { $f = [[]]; }
    $ds = array_values(is_array($descansos[$d] ?? null) ? $descansos[$d] : []);
    $hayDescansos = (bool)array_filter($ds, static fn($x) => is_array($x) && (($x['inicio'] ?? '') !== '' || ($x['fin'] ?? '') !== ''));
    if (!$ds) { $ds = [[]]; }
?>
    <fieldset class="ag-dia" data-ag-dia="<?= $d ?>">
      <legend><?= s($nombreDia) ?></legend>
      <div class="ag-lista" data-ag-lista="franja" data-ag-siguiente="<?= count($f) ?>">
<?php foreach ($f as $i => $datos) { echo $fila('franja', $d, $i, $datos, $nombreDia); } ?>
      </div>
<?php if ($editable) { ?><button type="button" class="boton ui-button-quiet ag-agregar" data-ag-agregar="franja">+ Añadir turno</button><?php } ?>
      <details class="ag-descansos"<?= $hayDescansos ? ' open' : '' ?>>
        <summary>Descansos del <?= s(mb_strtolower($nombreDia)) ?></summary>
        <div class="ag-lista" data-ag-lista="descanso" data-ag-siguiente="<?= count($ds) ?>">
<?php foreach ($ds as $i => $datos) { echo $fila('descanso', $d, $i, $datos, $nombreDia); } ?>
        </div>
<?php if ($editable) { ?><button type="button" class="boton ui-button-quiet ag-agregar" data-ag-agregar="descanso">+ Añadir descanso</button><?php } ?>
      </details>
    </fieldset>
<?php } ?>
  </div>
<?php if ($editable) { ?>
  </details>
  <template data-ag-plantilla="franja"><?= $fila('franja', 0, 0, [], '__DIA__') ?></template>
  <template data-ag-plantilla="descanso"><?= $fila('descanso', 0, 0, [], '__DIA__') ?></template>
<?php } ?>
</div>

<?php
/** Captura de días y un intervalo común. En el asistente edita el formulario; en agenda se envía al servidor. */
$prefijoGrupo = (string)$grupo['prefijo'];
$tipoGrupo = $grupo['tipo'];
$asistenteGrupo = !empty($grupo['asistente']);
$previoGrupo = is_array($grupo['previo'] ?? null) ? $grupo['previo'] : [];
$diasGrupo = is_array($previoGrupo['dias'] ?? null) ? array_filter($previoGrupo['dias'], 'is_scalar') : [1, 2, 3, 4, 5];
$diasGrupo = array_map('strval', $diasGrupo);
$inicioGrupo = is_string($previoGrupo['hora_inicio'] ?? null) ? $previoGrupo['hora_inicio'] : ($tipoGrupo === 'horario' ? '09:00' : '14:00');
$finGrupo = is_string($previoGrupo['hora_fin'] ?? null) ? $previoGrupo['hora_fin'] : ($tipoGrupo === 'horario' ? '18:00' : '15:00');
?>
<fieldset class="gestion-grupo-dias" data-grupo-dias data-tipo="<?= s($tipoGrupo) ?>">
    <legend><?= $tipoGrupo === 'horario' ? 'Una jornada para varios días' : 'Un descanso para varios días' ?></legend>
    <div class="gestion-presets" aria-label="Selección rápida de días">
        <button type="button" class="boton ui-button-quiet" data-dias-preset="1,2,3,4,5">Lunes a viernes</button>
        <button type="button" class="boton ui-button-quiet" data-dias-preset="2,3,4,5,6">Martes a sábado</button>
        <button type="button" class="boton ui-button-quiet" data-dias-preset="1,2,3,4,5,6,7">Toda la semana</button>
    </div>
    <div class="gestion-dias" role="group" aria-label="Días a los que se aplicará el <?= $tipoGrupo === 'horario' ? 'horario' : 'descanso' ?>">
        <?php foreach (\Model\HorarioProfesional::DIAS_SEMANA as $numero => $dia): ?>
        <label for="<?= s($prefijoGrupo) ?>-dia-<?= $numero ?>"><input type="checkbox" id="<?= s($prefijoGrupo) ?>-dia-<?= $numero ?>"<?= $asistenteGrupo ? '' : ' name="dias[]"' ?> value="<?= $numero ?>" data-grupo-dia<?= in_array((string)$numero, $diasGrupo, true) ? ' checked' : '' ?>><span><?= s($dia) ?></span></label>
        <?php endforeach; ?>
    </div>
    <div class="gestion-intervalo">
        <div class="campo"><label for="<?= s($prefijoGrupo) ?>-inicio"><?= $tipoGrupo === 'horario' ? 'Entrada' : 'Inicio del descanso' ?></label><input id="<?= s($prefijoGrupo) ?>-inicio" type="time" step="300"<?= $asistenteGrupo ? '' : ' name="hora_inicio" required' ?> data-grupo-inicio value="<?= s($inicioGrupo) ?>"></div>
        <div class="campo"><label for="<?= s($prefijoGrupo) ?>-fin"><?= $tipoGrupo === 'horario' ? 'Salida' : 'Fin del descanso' ?></label><input id="<?= s($prefijoGrupo) ?>-fin" type="time" step="300"<?= $asistenteGrupo ? '' : ' name="hora_fin" required' ?> data-grupo-fin value="<?= s($finGrupo) ?>"></div>
        <?php if ($tipoGrupo === 'descanso'): ?><div class="campo"><label for="<?= s($prefijoGrupo) ?>-motivo">Motivo (opcional)</label><input id="<?= s($prefijoGrupo) ?>-motivo" type="text" maxlength="120"<?= $asistenteGrupo ? '' : ' name="motivo"' ?> data-grupo-motivo value="<?= s(is_string($previoGrupo['motivo'] ?? null) ? $previoGrupo['motivo'] : '') ?>" placeholder="Almuerzo"></div><?php endif; ?>
    </div>
    <p class="ui-form-help"><?= $tipoGrupo === 'horario' ? 'Reemplaza las franjas de los días seleccionados. Los demás días conservan su horario.' : 'Añade la misma pausa en los días seleccionados. Debe quedar dentro de su jornada y respetar las citas ya reservadas.' ?></p>
    <button class="boton" type="<?= $asistenteGrupo ? 'button' : 'submit' ?>"<?= $asistenteGrupo ? ' data-ag-aplicar-grupo' : '' ?>><?= $asistenteGrupo ? 'Preparar' : 'Guardar' ?> <?= $tipoGrupo === 'horario' ? 'jornada' : 'descanso' ?></button>
    <?php if ($asistenteGrupo): ?><p class="ui-form-help" data-grupo-resultado role="status" aria-live="polite">Prepara los días y guarda la configuración al terminar.</p><?php endif; ?>
</fieldset>

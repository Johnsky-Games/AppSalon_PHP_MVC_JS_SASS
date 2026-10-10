<h1 class="nombre-pagina">Agenda y Horarios</h1>
<p class="descripcion-pagina">
    Profesional: <strong><?php echo s($profesional->nombre); ?></strong>
    (Zona horaria: <span><?php echo s($zonaHoraria ?? 'America/Guayaquil'); ?></span>)
</p>

<?php
include __DIR__ . '/../templates/barra.php';
$alertas = $alertas ?? [];
include __DIR__ . '/../templates/alertas.php';
?>

<div class="acciones" style="margin-bottom: 2rem;">
    <a class="boton" href="/profesionales">Volver a Profesionales</a>
</div>

<h2>1. Horario Semanal</h2>
<form action="/profesionales/horarios?id=<?php echo s($profesional->id); ?>" method="POST" class="formulario" id="form-horarios-semanales">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
    <input type="hidden" name="accion" value="guardar_horarios">
    <input type="hidden" name="profesionalId" value="<?php echo s($profesional->id); ?>">

    <?php foreach (($diasSemana ?? []) as $numDia => $nombreDia) {
        $horarioDia = $horariosPorDia[$numDia] ?? null;
        $activoDia = $horarioDia !== null;
        $horaInicioVal = $horarioDia ? $horarioDia->hora_inicio : '09:00';
        $horaFinVal = $horarioDia ? $horarioDia->hora_fin : '18:00';
    ?>
    <div class="campo horario-dia-fila" data-dia="<?php echo (int)$numDia; ?>">
        <input type="hidden" name="horarios[<?php echo (int)$numDia; ?>][enviado]" value="1">
        <input type="hidden" name="horarios[<?php echo (int)$numDia; ?>][dia_semana]" value="<?php echo (int)$numDia; ?>">
        <label for="horario_activo_<?php echo (int)$numDia; ?>">
            <input
                type="checkbox"
                name="horarios[<?php echo (int)$numDia; ?>][activo]"
                id="horario_activo_<?php echo (int)$numDia; ?>"
                value="1"
                <?php echo $activoDia ? 'checked' : ''; ?>
            >
            <?php echo s($nombreDia); ?>
        </label>
        <input
            type="time"
            name="horarios[<?php echo (int)$numDia; ?>][hora_inicio]"
            id="horario_inicio_<?php echo (int)$numDia; ?>"
            value="<?php echo s($horaInicioVal); ?>"
        >
        <span>a</span>
        <input
            type="time"
            name="horarios[<?php echo (int)$numDia; ?>][hora_fin]"
            id="horario_fin_<?php echo (int)$numDia; ?>"
            value="<?php echo s($horaFinVal); ?>"
        >
    </div>
    <?php } ?>

    <input type="submit" value="Guardar Horario Semanal" class="boton">
</form>

<hr>

<h2>2. Descansos Semanales</h2>
<form action="/profesionales/descansos/crear?id=<?php echo s($profesional->id); ?>" method="POST" class="formulario" id="form-crear-descanso">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
    <input type="hidden" name="accion" value="crear_descanso">
    <input type="hidden" name="profesionalId" value="<?php echo s($profesional->id); ?>">

    <div class="campo">
        <label for="descanso_dia_semana">Día</label>
        <select name="dia_semana" id="descanso_dia_semana">
            <?php foreach (($diasSemana ?? []) as $numDia => $nombreDia) { ?>
                <option value="<?php echo (int)$numDia; ?>"><?php echo s($nombreDia); ?></option>
            <?php } ?>
        </select>
    </div>
    <div class="campo">
        <label for="descanso_hora_inicio">Hora Inicio</label>
        <input type="time" name="hora_inicio" id="descanso_hora_inicio" required>
    </div>
    <div class="campo">
        <label for="descanso_hora_fin">Hora Fin</label>
        <input type="time" name="hora_fin" id="descanso_hora_fin" required>
    </div>
    <div class="campo">
        <label for="descanso_motivo">Motivo (opcional)</label>
        <input type="text" name="motivo" id="descanso_motivo" maxlength="120" placeholder="Ej. Almuerzo">
    </div>

    <input type="submit" value="Añadir Descanso" class="boton">
</form>

<ul class="servicios descansos-lista">
    <?php foreach (($descansos ?? []) as $desc) { ?>
    <li data-descanso-id="<?php echo s($desc->id); ?>">
        <p>Día: <span><?php echo s($desc->nombreDia()); ?></span></p>
        <p>Intervalo: <span><?php echo s($desc->hora_inicio); ?> - <?php echo s($desc->hora_fin); ?></span></p>
        <?php if ($desc->motivo !== null) { ?>
            <p>Motivo: <span><?php echo s($desc->motivo); ?></span></p>
        <?php } ?>
        <div class="acciones">
            <form action="/profesionales/descansos/eliminar?id=<?php echo s($profesional->id); ?>" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="accion" value="eliminar_descanso">
                <input type="hidden" name="profesionalId" value="<?php echo s($profesional->id); ?>">
                <input type="hidden" name="descansoId" value="<?php echo s($desc->id); ?>">
                <input type="submit" value="Eliminar Descanso" class="boton-eliminar">
            </form>
        </div>
    </li>
    <?php } ?>
</ul>

<hr>

<h2>3. Bloqueos por Fecha o Intervalo</h2>
<form action="/profesionales/bloqueos/crear?id=<?php echo s($profesional->id); ?>" method="POST" class="formulario" id="form-crear-bloqueo">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
    <input type="hidden" name="accion" value="crear_bloqueo">
    <input type="hidden" name="profesionalId" value="<?php echo s($profesional->id); ?>">

    <div class="campo">
        <label for="bloqueo_fecha_inicio">Fecha Inicio</label>
        <input type="date" name="fecha_inicio" id="bloqueo_fecha_inicio" required>
    </div>
    <div class="campo">
        <label for="bloqueo_fecha_fin">Fecha Fin</label>
        <input type="date" name="fecha_fin" id="bloqueo_fecha_fin">
    </div>
    <div class="campo">
        <label for="bloqueo_hora_inicio">Hora Inicio (opcional para día completo)</label>
        <input type="time" name="hora_inicio" id="bloqueo_hora_inicio">
    </div>
    <div class="campo">
        <label for="bloqueo_hora_fin">Hora Fin (opcional para día completo)</label>
        <input type="time" name="hora_fin" id="bloqueo_hora_fin">
    </div>
    <div class="campo">
        <label for="bloqueo_motivo">Motivo (opcional)</label>
        <input type="text" name="motivo" id="bloqueo_motivo" maxlength="160" placeholder="Ej. Cita médica o vacaciones">
    </div>

    <input type="submit" value="Añadir Bloqueo" class="boton">
</form>

<ul class="servicios bloqueos-lista">
    <?php foreach (($bloqueos ?? []) as $bloq) { ?>
    <li data-bloqueo-id="<?php echo s($bloq->id); ?>">
        <p>Fecha:
            <span>
                <?php
                echo s($bloq->fecha_inicio);
                if ($bloq->fecha_fin !== $bloq->fecha_inicio) {
                    echo ' al ' . s($bloq->fecha_fin);
                }
                ?>
            </span>
        </p>
        <p>Horario:
            <span>
                <?php
                if ($bloq->esDiaCompleto()) {
                    echo 'Día completo';
                } else {
                    echo s($bloq->hora_inicio) . ' - ' . s($bloq->hora_fin);
                }
                ?>
            </span>
        </p>
        <?php if ($bloq->motivo !== null) { ?>
            <p>Motivo: <span><?php echo s($bloq->motivo); ?></span></p>
        <?php } ?>
        <div class="acciones">
            <form action="/profesionales/bloqueos/eliminar?id=<?php echo s($profesional->id); ?>" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="accion" value="eliminar_bloqueo">
                <input type="hidden" name="profesionalId" value="<?php echo s($profesional->id); ?>">
                <input type="hidden" name="bloqueoId" value="<?php echo s($bloq->id); ?>">
                <input type="submit" value="Eliminar Bloqueo" class="boton-eliminar">
            </form>
        </div>
    </li>
    <?php } ?>
</ul>

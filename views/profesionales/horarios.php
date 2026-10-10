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
        $numDiaInt = (int)$numDia;
        $franjasDia = $horariosPorDia[$numDiaInt] ?? [];
        if ($franjasDia instanceof \Model\HorarioProfesional) {
            $franjasDia = [$franjasDia];
        }
        $filasRenderizar = !empty($franjasDia) ? array_values($franjasDia) : [null];
    ?>
    <div class="horario-dia-grupo" data-dia="<?php echo $numDiaInt; ?>" data-next-index="<?php echo count($filasRenderizar); ?>">
        <div class="horario-dia-cabecera" style="display: flex; align-items: center; justify-content: space-between; margin: 1rem 0 0.5rem 0;">
            <strong><?php echo s($nombreDia); ?></strong>
            <button
                type="button"
                class="boton btn-agregar-franja"
                data-dia="<?php echo $numDiaInt; ?>"
                data-nombre-dia="<?php echo s($nombreDia); ?>"
                style="margin: 0; padding: 0.6rem 1.2rem; font-size: 1.4rem;"
            >+ Añadir franja</button>
        </div>
        <div class="horario-dia-franjas" data-dia-contenedor="<?php echo $numDiaInt; ?>">
            <?php foreach ($filasRenderizar as $idxFranja => $horarioDia) {
                $idxInt = (int)$idxFranja;
                $claveFila = "{$numDiaInt}_{$idxInt}";
                $sufijoDom = ($idxInt === 0) ? (string)$numDiaInt : "{$numDiaInt}_{$idxInt}";
                $activoDia = ($horarioDia !== null);
                $horaInicioVal = $horarioDia ? $horarioDia->hora_inicio : '09:00';
                $horaFinVal = $horarioDia ? $horarioDia->hora_fin : '18:00';
            ?>
            <div class="campo horario-dia-fila horario-franja-fila" data-dia="<?php echo $numDiaInt; ?>" data-franja-index="<?php echo $idxInt; ?>">
                <input type="hidden" name="horarios[<?php echo $claveFila; ?>][enviado]" value="1">
                <input type="hidden" name="horarios[<?php echo $claveFila; ?>][dia_semana]" value="<?php echo $numDiaInt; ?>">
                <input type="hidden" name="horarios[<?php echo $claveFila; ?>][activo]" value="0">
                <label for="horario_activo_<?php echo $sufijoDom; ?>">
                    <input
                        type="checkbox"
                        class="horario-activo-check"
                        name="horarios[<?php echo $claveFila; ?>][activo]"
                        id="horario_activo_<?php echo $sufijoDom; ?>"
                        value="1"
                        <?php echo $activoDia ? 'checked' : ''; ?>
                    >
                    <?php echo s($nombreDia); ?>
                </label>
                <input
                    type="time"
                    class="horario-inicio-input"
                    name="horarios[<?php echo $claveFila; ?>][hora_inicio]"
                    id="horario_inicio_<?php echo $sufijoDom; ?>"
                    value="<?php echo s($horaInicioVal); ?>"
                >
                <span>a</span>
                <input
                    type="time"
                    class="horario-fin-input"
                    name="horarios[<?php echo $claveFila; ?>][hora_fin]"
                    id="horario_fin_<?php echo $sufijoDom; ?>"
                    value="<?php echo s($horaFinVal); ?>"
                >
                <button
                    type="button"
                    class="boton-eliminar btn-retirar-franja"
                    data-dia="<?php echo $numDiaInt; ?>"
                    style="margin: 0 0 0 0.8rem; padding: 0.6rem 1rem; font-size: 1.3rem;"
                >Retirar franja</button>
            </div>
            <?php } ?>
        </div>
    </div>
    <?php } ?>

    <input type="submit" value="Guardar Horario Semanal" class="boton">
</form>

<script>
(function () {
    const formHorarios = document.getElementById('form-horarios-semanales');
    if (!formHorarios) return;

    formHorarios.addEventListener('click', function (e) {
        const btnAgregar = e.target.closest('.btn-agregar-franja');
        if (btnAgregar) {
            e.preventDefault();
            const numDia = btnAgregar.getAttribute('data-dia');
            const nombreDia = btnAgregar.getAttribute('data-nombre-dia') || '';
            const grupo = formHorarios.querySelector('.horario-dia-grupo[data-dia="' + numDia + '"]');
            const contenedor = formHorarios.querySelector('.horario-dia-franjas[data-dia-contenedor="' + numDia + '"]');
            if (!grupo || !contenedor) return;

            const idx = parseInt(grupo.getAttribute('data-next-index') || '1', 10);
            grupo.setAttribute('data-next-index', String(idx + 1));

            const claveFila = numDia + '_' + idx;
            const sufijoDom = numDia + '_' + idx;

            const fila = document.createElement('div');
            fila.className = 'campo horario-dia-fila horario-franja-fila';
            fila.setAttribute('data-dia', numDia);
            fila.setAttribute('data-franja-index', String(idx));

            const inputEnviado = document.createElement('input');
            inputEnviado.type = 'hidden';
            inputEnviado.name = 'horarios[' + claveFila + '][enviado]';
            inputEnviado.value = '1';

            const inputDia = document.createElement('input');
            inputDia.type = 'hidden';
            inputDia.name = 'horarios[' + claveFila + '][dia_semana]';
            inputDia.value = numDia;

            const inputActivoHidden = document.createElement('input');
            inputActivoHidden.type = 'hidden';
            inputActivoHidden.name = 'horarios[' + claveFila + '][activo]';
            inputActivoHidden.value = '0';

            const label = document.createElement('label');
            label.setAttribute('for', 'horario_activo_' + sufijoDom);

            const check = document.createElement('input');
            check.type = 'checkbox';
            check.className = 'horario-activo-check';
            check.name = 'horarios[' + claveFila + '][activo]';
            check.id = 'horario_activo_' + sufijoDom;
            check.value = '1';
            check.checked = true;

            label.appendChild(check);
            label.appendChild(document.createTextNode(' ' + nombreDia));

            const inputInicio = document.createElement('input');
            inputInicio.type = 'time';
            inputInicio.className = 'horario-inicio-input';
            inputInicio.name = 'horarios[' + claveFila + '][hora_inicio]';
            inputInicio.id = 'horario_inicio_' + sufijoDom;
            inputInicio.value = '14:00';

            const spanA = document.createElement('span');
            spanA.textContent = 'a';

            const inputFin = document.createElement('input');
            inputFin.type = 'time';
            inputFin.className = 'horario-fin-input';
            inputFin.name = 'horarios[' + claveFila + '][hora_fin]';
            inputFin.id = 'horario_fin_' + sufijoDom;
            inputFin.value = '18:00';

            const btnRetirar = document.createElement('button');
            btnRetirar.type = 'button';
            btnRetirar.className = 'boton-eliminar btn-retirar-franja';
            btnRetirar.setAttribute('data-dia', numDia);
            btnRetirar.style.cssText = 'margin: 0 0 0 0.8rem; padding: 0.6rem 1rem; font-size: 1.3rem;';
            btnRetirar.textContent = 'Retirar franja';

            fila.appendChild(inputEnviado);
            fila.appendChild(inputDia);
            fila.appendChild(inputActivoHidden);
            fila.appendChild(label);
            fila.appendChild(inputInicio);
            fila.appendChild(spanA);
            fila.appendChild(inputFin);
            fila.appendChild(btnRetirar);

            contenedor.appendChild(fila);
            return;
        }

        const btnRetirar = e.target.closest('.btn-retirar-franja');
        if (btnRetirar) {
            e.preventDefault();
            const fila = btnRetirar.closest('.horario-franja-fila');
            const contenedor = btnRetirar.closest('.horario-dia-franjas');
            if (!fila || !contenedor) return;

            const filasDelDia = contenedor.querySelectorAll('.horario-franja-fila');
            if (filasDelDia.length > 1) {
                fila.remove();
            } else {
                const check = fila.querySelector('.horario-activo-check');
                if (check) {
                    check.checked = false;
                }
            }
        }
    });
})();
</script>

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

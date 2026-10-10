<div class="campo">
    <label for="nombre">Nombre</label>
    <input
        type="text"
        name="nombre"
        id="nombre"
        placeholder="Nombre completo del Profesional"
        maxlength="120"
        value="<?php echo s($profesional->nombre); ?>"
    >
</div>

<div class="campo">
    <label for="activo">Estado</label>
    <select name="activo" id="activo">
        <option value="1" <?php echo (string)$profesional->activo === '1' ? 'selected' : ''; ?>>Activo</option>
        <option value="0" <?php echo (string)$profesional->activo === '0' ? 'selected' : ''; ?>>Inactivo</option>
    </select>
</div>

<div class="campo">
    <label>Servicios que puede realizar</label>
    <div class="listado-servicios-checks">
        <?php foreach (($serviciosDisponibles ?? []) as $srv) {
            $idSrv = (int)$srv->id;
            $checked = in_array($idSrv, $profesional->servicioIds ?? [], true) ? 'checked' : '';
        ?>
            <div class="check-servicio">
                <label for="servicio_<?php echo s($srv->id); ?>">
                    <input
                        type="checkbox"
                        name="servicios[]"
                        id="servicio_<?php echo s($srv->id); ?>"
                        value="<?php echo s($srv->id); ?>"
                        <?php echo $checked; ?>
                    >
                    <?php echo s($srv->nombre); ?> ($<?php echo s($srv->precio); ?> — <?php echo s($srv->duracion_minutos); ?> min)
                </label>
            </div>
        <?php } ?>
    </div>
</div>

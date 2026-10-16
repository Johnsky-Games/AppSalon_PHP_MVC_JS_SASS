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
    <p class="ui-section-title">Servicios que puede realizar</p><p class="ui-form-help">Selecciona las especialidades para las que este profesional estará disponible.</p>
    <div class="listado-servicios-checks ui-grid">
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

<?php if (empty($serviciosDisponibles)) { ?><p class="ui-empty">Aún no hay servicios en el catálogo. Añádelos desde Servicios para poder asignarlos.</p><?php } ?>

<div class="campo">
    <label for="nombre">Nombre</label>
    <input type="text" name="nombre" id="nombre" placeholder="Nombre del Servicio"
        value="<?php echo s($servicio->nombre); ?>">
</div>
<div class="campo">
    <label for="precio">Precio</label>
    <input type="number" name="precio" id="precio" placeholder="Precio del Servicio" step="0.01"
        value="<?php echo s($servicio->precio); ?>">
</div>
<div class="campo">
    <label for="duracion_minutos">Duración (Minutos)</label>
    <input type="number" name="duracion_minutos" id="duracion_minutos" placeholder="Duración en minutos (ej. 30)" min="1" step="1"
        value="<?php echo s($servicio->duracion_minutos); ?>">
</div>
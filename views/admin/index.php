<h1 class="nombre-pagina">Panel de Administración</h1>

<?php
include __DIR__ . '/../templates/barra.php';
$alertas = $alertas ?? [];
include __DIR__ . '/../templates/alertas.php';
?>
<h2>Buscar Citas</h2>
<div class="busqueda">
    <form action="" class="formulario" method="">
        <div class="campo">
            <label for="fecha">Fecha</label>
            <input type="date" name="fecha" id="fecha" value="<?php echo trim(s($fecha)); ?>">
        </div>
    </form>
</div>

<?php if (empty($alertas['error']) && count($citas) === 0) {
    echo "<h2>No se registra citas para la fecha seleccionada</h2>";
} ?>

<div class="citas-admin">
    <ul class="citas">
        <?php
        $idCita = 0;
        foreach ($citas as $key => $cita) {

            if ($idCita !== $cita->id) {
                $total = 0;
                ?>
        <li>
            <p>ID: <span><?php echo s((string)$cita->id); ?></span></p>
            <p>Hora: <span><?php echo s((string)$cita->hora); ?></span></p>
            <p>Cliente: <span><?php echo s((string)$cita->cliente); ?></span></p>
            <p>Email: <span><?php echo s((string)$cita->email); ?></span></p>
            <p>Telefono: <span><?php echo s((string)$cita->telefono); ?></span></p>
            <h3>Servicios</h3>
            <?php
                    $idCita = $cita->id;
            } // End If 
            $total += (float)($cita->precio ?? 0);
            ?>
            <p class="servicio"><?php echo s((string)$cita->servicio) . " " . s((string)$cita->precio); ?></p>
            <?php
                $actual = $cita->id;
                $proximo = $citas[$key + 1]->id ?? 0;
                if (esUltimo($actual, $proximo)) { ?>
            <p class="total">Total: <span>$ <?php echo $total; ?></span></p>
            <form action="/api/eliminar" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="id" value="<?php echo s((string)$cita->id); ?>">
                <input type="submit" class="boton-eliminar" value="Eliminar">
            </form>
            <?php } //End If ?>
            <?php } //End foreach ?>
    </ul>
</div>

<?php
$script = "<script src='build/js/buscador.js'></script>";
?>
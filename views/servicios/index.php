<h1 class="nombre-pagina">Servicios</h1>
<p class="descripcion-pagina">Administración de Servicios</p>

<?php
include __DIR__ . '/../templates/barra.php';
$alertas = $alertas ?? [];
include __DIR__ . '/../templates/alertas.php';
?>


<ul class="servicios">

    <?php foreach ($servicios as $servicio) { ?>
    <li>
        <p>Nombre: <span><?php echo s($servicio->nombre); ?></span></p>
        <p>Precio: <span> $
                <?php echo s($servicio->precio); ?>
            </span></p>
        <p>Duración: <span><?php echo s($servicio->duracion_minutos); ?> min</span></p>

        <div class="acciones">
            <a class="boton" href="/servicios/actualizar?id=<?php echo s($servicio->id); ?>">Actualizar</a>
            <form action="/servicios/eliminar" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="id" value="<?php echo s($servicio->id); ?>">
                <input type="submit" value="Eliminar" class="boton-eliminar">
            </form>
        </div>
    </li>
    <?php } ?>
</ul>
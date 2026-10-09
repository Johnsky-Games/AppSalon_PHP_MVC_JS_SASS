<h1 class="nombre-pagina">Nuevo Servicio</h1>
<p class="descripcion-pagina">Llena toso los campos para añadir un nuevo servico</p>

<?php
include __DIR__ . '/../templates/barra.php';
include __DIR__ . '/../templates/alertas.php';
?>

<form action="/servicios/crear" method="POST" class="formulario">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
    <?php include_once __DIR__ . '/formulario.php' ?>
    <input type="submit" value="Guardar Servicio" class="boton">
</form>
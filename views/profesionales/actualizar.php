<h1 class="nombre-pagina">Actualizar Profesional</h1>
<p class="descripcion-pagina">Modifica los datos, estado o servicios habilitados del profesional</p>

<?php
include __DIR__ . '/../templates/barra.php';
$alertas = $alertas ?? [];
include __DIR__ . '/../templates/alertas.php';
?>

<form method="POST" class="formulario">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
    <?php include __DIR__ . '/formulario.php'; ?>
    <input type="submit" value="Actualizar Profesional" class="boton">
</form>

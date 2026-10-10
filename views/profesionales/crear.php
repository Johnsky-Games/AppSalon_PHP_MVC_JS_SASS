<h1 class="nombre-pagina">Nuevo Profesional</h1>
<p class="descripcion-pagina">Registra un nuevo profesional y asigna los servicios que puede realizar</p>

<?php
include __DIR__ . '/../templates/barra.php';
$alertas = $alertas ?? [];
include __DIR__ . '/../templates/alertas.php';
?>

<form action="/profesionales/crear" method="POST" class="formulario">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
    <?php include __DIR__ . '/formulario.php'; ?>
    <input type="submit" value="Guardar Profesional" class="boton">
</form>

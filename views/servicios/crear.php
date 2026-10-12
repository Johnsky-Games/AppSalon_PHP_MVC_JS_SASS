<header class="ui-page-header"><p class="ui-eyebrow">Gestión del salón</p><h1 class="nombre-pagina">Nuevo Servicio</h1><p class="descripcion-pagina">Define el nombre, precio y duración que verán tus clientes.</p></header>

<?php
include __DIR__ . '/../templates/barra.php';
include __DIR__ . '/../templates/alertas.php';
?>

<form action="/servicios/crear" method="POST" class="formulario ui-card">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
    <?php include_once __DIR__ . '/formulario.php' ?>
    <input type="submit" value="Guardar Servicio" class="boton">
</form>
<div class="acciones"><a href="/servicios" class="ui-back-link">← Volver a servicios</a></div>

<header class="ui-page-header"><p class="ui-eyebrow">Gestión del salón</p><h1 class="nombre-pagina">Actualizar Servicio</h1><p class="descripcion-pagina">Mantén tu catálogo al día con información clara y precisa.</p></header>

<?php
include __DIR__ . '/../templates/barra.php';
include __DIR__ . '/../templates/alertas.php';
?>

<form method="POST" class="formulario ui-card">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
    <?php include_once __DIR__ . '/formulario.php' ?>
    <input type="submit" value="Actualizar" class="boton">
</form>
<div class="acciones"><a href="/servicios" class="ui-back-link">← Volver a servicios</a></div>

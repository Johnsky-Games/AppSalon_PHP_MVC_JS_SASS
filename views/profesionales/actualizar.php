<header class="ui-page-header"><p class="ui-eyebrow">Gestión del salón</p><h1 class="nombre-pagina">Actualizar Profesional</h1><p class="descripcion-pagina">Actualiza los datos, estado y servicios habilitados del profesional.</p></header>

<?php
include __DIR__ . '/../templates/barra.php';
$alertas = $alertas ?? [];
include __DIR__ . '/../templates/alertas.php';
?>

<form method="POST" class="formulario ui-card">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
    <?php include __DIR__ . '/formulario.php'; ?>
    <input type="submit" value="Actualizar Profesional" class="boton">
</form>

<div class="acciones"><a href="/profesionales" class="ui-back-link">← Volver a profesionales</a></div>

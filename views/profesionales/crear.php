<header class="ui-page-header"><p class="ui-eyebrow">Gestión del salón</p><h1 class="nombre-pagina">Nuevo Profesional</h1><p class="descripcion-pagina">Añade talento a tu salón y asigna sus especialidades.</p></header>

<?php
include __DIR__ . '/../templates/barra.php';
$alertas = $alertas ?? [];
include __DIR__ . '/../templates/alertas.php';
?>

<form action="/profesionales/crear" method="POST" class="formulario ui-card">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
    <?php include __DIR__ . '/formulario.php'; ?>
    <input type="submit" value="Guardar Profesional" class="boton">
</form>

<div class="acciones"><a href="/profesionales" class="ui-back-link">← Volver a profesionales</a></div>

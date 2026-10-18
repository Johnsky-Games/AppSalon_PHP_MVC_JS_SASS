<header class="ui-page-header"><p class="ui-eyebrow">Gestión del salón</p><h1 class="nombre-pagina"><?= $profesional->permiteConfigurarAgenda() ? 'Actualizar Profesional' : 'Ficha profesional' ?></h1><p class="descripcion-pagina">Consulta la ficha operativa o configura el nombre en agenda, su disponibilidad y sus servicios.</p></header>

<?php
include __DIR__ . '/../templates/barra.php';
$alertas = $alertas ?? [];
include __DIR__ . '/../templates/alertas.php';
?>

<?php if (!$profesional->permiteConfigurarAgenda()): ?>
<section class="ui-card"><h2>Ficha de consulta</h2><p>La persona está dada de baja o ya no tiene rol Profesional. Su configuración se conserva para consultar el historial.</p><p><?= s($profesional->nombre) ?></p><p><?= s(implode(', ', $profesional->serviciosNombres)) ?></p><a href="/profesionales/horarios?id=<?= (int)$profesional->id ?>">Consultar horarios y bloqueos</a></section>
<?php else: ?>
<form method="POST" class="formulario ui-card">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
    <?php include __DIR__ . '/formulario.php'; ?>
    <input type="submit" value="Actualizar Profesional" class="boton">
</form>
<?php endif; ?>

<div class="acciones"><?php if ($profesional->colaborador_id && puede('rh.gestionar')): ?><a href="/admin/recursos-humanos/ficha?id=<?= (int)$profesional->colaborador_id ?>">Ficha laboral en Recursos Humanos</a><?php endif; ?><a href="/profesionales" class="ui-back-link">← Volver a profesionales</a></div>

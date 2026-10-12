<header class="ui-page-header"><p class="ui-eyebrow">El talento detrás de cada cita</p><h1 class="nombre-pagina">Profesionales</h1><p class="descripcion-pagina">Gestiona el equipo, sus especialidades y la disponibilidad de cada profesional.</p></header>

<?php
include __DIR__ . '/../templates/barra.php';
$alertas = $alertas ?? [];
include __DIR__ . '/../templates/alertas.php';
?>

<div class="ui-toolbar"><div class="campo"><label for="buscar-profesionales">Buscar en tu equipo</label><input type="search" id="buscar-profesionales" placeholder="Nombre o servicio…" data-list-filter="#equipo-profesionales" autocomplete="off"></div>
    <a class="boton" href="/profesionales/crear">Nuevo Profesional</a>
</div>

<?php if (empty($profesionales)) { ?><div class="ui-empty"><h2>Construye tu equipo</h2><p>Registra un profesional y asigna los servicios que puede realizar.</p></div><?php } ?>
<p class="ui-form-help" data-filter-empty="#equipo-profesionales" hidden>No hay profesionales que coincidan con tu búsqueda.</p>
<ul class="servicios profesionales-lista ui-grid" id="equipo-profesionales">
    <?php foreach ($profesionales as $prof) { ?>
    <li class="ui-card" data-filter-item data-profesional-id="<?php echo s($prof->id); ?>">
        <div class="ui-person-card"><img class="ui-avatar" src="/build/img/avatar-professional.svg" alt="" width="64" height="64"><div><p class="ui-eyebrow">Equipo del salón</p><p>Nombre: <span><?php echo s($prof->nombre); ?></span></p></div></div>
        <p>Estado: <span class="ui-badge"><?php echo $prof->estaActivo() ? 'Activo' : 'Inactivo'; ?></span></p>
        <p>Servicios habilitados:
            <span>
                <?php
                if (!empty($prof->serviciosNombres)) {
                    echo s(implode(', ', $prof->serviciosNombres));
                } else {
                    echo 'Sin servicios asignados';
                }
                ?>
            </span>
        </p>

        <div class="acciones">
            <a class="boton" href="/profesionales/actualizar?id=<?php echo s($prof->id); ?>">Actualizar</a>
            <a class="boton" href="/profesionales/horarios?id=<?php echo s($prof->id); ?>">Horarios y Agenda</a>

            <form action="/profesionales/estado" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="id" value="<?php echo s($prof->id); ?>">
                <input type="hidden" name="activo" value="<?php echo $prof->estaActivo() ? '0' : '1'; ?>">
                <input
                    type="submit"
                    value="<?php echo $prof->estaActivo() ? 'Desactivar' : 'Activar'; ?>"
                    class="<?php echo $prof->estaActivo() ? 'boton-eliminar' : 'boton'; ?>"
                >
            </form>
        </div>
    </li>
    <?php } ?>
</ul>

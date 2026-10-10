<h1 class="nombre-pagina">Profesionales</h1>
<p class="descripcion-pagina">Administración de Profesionales y Asignación de Servicios</p>

<?php
include __DIR__ . '/../templates/barra.php';
$alertas = $alertas ?? [];
include __DIR__ . '/../templates/alertas.php';
?>

<div class="acciones" style="margin-bottom: 2rem;">
    <a class="boton" href="/profesionales/crear">Nuevo Profesional</a>
</div>

<ul class="servicios profesionales-lista">
    <?php foreach ($profesionales as $prof) { ?>
    <li data-profesional-id="<?php echo s($prof->id); ?>">
        <p>Nombre: <span><?php echo s($prof->nombre); ?></span></p>
        <p>Estado: <span><?php echo $prof->estaActivo() ? 'Activo' : 'Inactivo'; ?></span></p>
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

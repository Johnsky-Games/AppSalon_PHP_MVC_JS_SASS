<header class="ui-page-header"><p class="ui-eyebrow">Catálogo del salón</p><h1 class="nombre-pagina">Servicios</h1><p class="descripcion-pagina">Organiza tus experiencias, precios y duración en un solo lugar.</p></header>

<?php
include __DIR__ . '/../templates/barra.php';
$alertas = $alertas ?? [];
include __DIR__ . '/../templates/alertas.php';
?>


<div class="ui-toolbar"><div class="campo"><label for="buscar-servicios">Buscar en el catálogo</label><input type="search" id="buscar-servicios" placeholder="Nombre del servicio…" data-list-filter="#catalogo-servicios" autocomplete="off"></div><a class="boton" href="/servicios/crear">+ Nuevo Servicio</a></div>
<?php if (empty($servicios)) { ?><div class="ui-empty"><h2>Tu catálogo empieza aquí</h2><p>Añade un servicio para ofrecerlo a tus clientes.</p></div><?php } ?>
<p class="ui-form-help" data-filter-empty="#catalogo-servicios" hidden>No hay servicios que coincidan con tu búsqueda.</p>
<ul class="servicios ui-grid" id="catalogo-servicios">

    <?php foreach ($servicios as $servicio) { ?>
    <li class="ui-card" data-filter-item>
        <p>Nombre: <span><?php echo s($servicio->nombre); ?></span></p>
        <p>Precio: <span> $
                <?php echo s($servicio->precio); ?>
            </span></p>
        <p>Duración: <span><?php echo s($servicio->duracion_minutos); ?> min</span></p>

        <div class="acciones">
            <a class="boton" href="/servicios/actualizar?id=<?php echo s($servicio->id); ?>">Actualizar</a>
            <form action="/servicios/eliminar" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="id" value="<?php echo s($servicio->id); ?>">
                <input type="submit" value="Eliminar" class="boton-eliminar">
            </form>
        </div>
    </li>
    <?php } ?>
</ul>
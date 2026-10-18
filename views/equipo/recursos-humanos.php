<?php
use Services\RecursosHumanosService;
$f = $filtros;
$query = static fn(array $extra = []): string => http_build_query(array_merge($f, $extra));
?>
<header class="ui-page-header">
    <div><p class="ui-eyebrow">Personas del negocio</p><h1 class="nombre-pagina">Recursos Humanos</h1><p class="descripcion-pagina">Consulta el directorio de colaboradores, sus datos de contacto y sus fichas laborales.</p></div>
    <?php if ($puedeAccesos): ?><a class="boton ui-button-quiet" href="/admin/equipo">Registrar colaborador / invitaciones</a><?php endif; ?>
</header>
<?php include __DIR__ . '/../templates/barra.php'; include __DIR__ . '/../templates/alertas.php'; ?>
<form class="gestion-busqueda gestion-busqueda--rh" method="GET" action="/admin/recursos-humanos" role="search">
    <div class="campo"><label for="rh-q">Buscar colaborador</label><input id="rh-q" name="q" type="search" maxlength="80" value="<?= s($f['q']) ?>" placeholder="Nombre, correo, código o puesto"></div>
    <div class="campo"><label for="rh-rol">Rol</label><select id="rh-rol" name="rol"><option value="">Todos los roles</option><?php foreach (RecursosHumanosService::ROLES as $k => $et): ?><option value="<?= s($k) ?>"<?= $f['rol'] === $k ? ' selected' : '' ?>><?= s($et) ?></option><?php endforeach; ?></select></div>
    <div class="campo"><label for="rh-estado">Estado</label><select id="rh-estado" name="estado"><option value="">Todos los estados</option><?php foreach (RecursosHumanosService::ESTADOS as $k => $et): ?><option value="<?= s($k) ?>"<?= $f['estado'] === $k ? ' selected' : '' ?>><?= s($et) ?></option><?php endforeach; ?></select></div>
    <button class="boton" type="submit">Buscar</button>
</form>
<section class="crm-seccion" aria-labelledby="rh-directorio">
    <div class="crm-encabezado-lista"><h2 id="rh-directorio">Colaboradores <span class="gestion-total"><?= (int)$directorio['total'] ?></span></h2><?php if ($f['q'] !== '' || $f['rol'] || $f['estado']): ?><a href="/admin/recursos-humanos">Limpiar filtros</a><?php endif; ?></div>
    <?php if (!$directorio['filas']): ?><p class="crm-vacio" role="status">No hay colaboradores que coincidan con la búsqueda.</p><?php else: ?>
    <div class="crm-tabla" tabindex="0" role="region" aria-label="Directorio de Recursos Humanos; desplaza horizontalmente para ver todas las columnas">
        <table class="rh-tabla"><caption class="crm-sr">Directorio de colaboradores, página <?= (int)$directorio['pagina'] ?> de <?= (int)$directorio['paginas'] ?></caption>
            <thead><tr><th scope="col">Colaborador</th><th scope="col">Puesto y rol</th><th scope="col">Contacto</th><th scope="col">Ingreso</th><th scope="col">Estado</th><th scope="col">Acciones</th></tr></thead>
            <tbody><?php foreach ($directorio['filas'] as $c): ?>
                <tr>
                    <th scope="row"><a href="/admin/recursos-humanos/ficha?id=<?= (int)$c['id'] ?>"><?= s(trim($c['nombre'] . ' ' . $c['apellido'])) ?></a><small><?= s($c['codigo'] ?? '') ?></small></th>
                    <td><?= s($c['puesto'] ?: 'Sin puesto definido') ?><small><?= s(RecursosHumanosService::ROLES[$c['rol']] ?? $c['rol']) ?></small></td>
                    <td><?= s($c['email']) ?><small><?= s($c['telefono'] ?: 'Sin teléfono') ?></small></td>
                    <td><?= s($c['fecha_ingreso'] ?: 'Sin registrar') ?></td>
                    <td><span class="ui-badge<?= $c['estado'] === 'activo' ? '' : ' ui-badge--warning' ?>"><?= s(RecursosHumanosService::ESTADOS[$c['estado']] ?? $c['estado']) ?></span></td>
                    <td><div class="gestion-acciones"><a class="gestion-abrir" href="/admin/recursos-humanos/ficha?id=<?= (int)$c['id'] ?>">Ficha laboral</a><?php if ($puedeProfesionales && $c['profesional_id']): ?><a class="gestion-abrir" href="/profesionales/horarios?id=<?= (int)$c['profesional_id'] ?>">Horarios</a><?php endif; ?></div></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table>
    </div>
    <nav class="crm-paginacion" aria-label="Páginas del directorio">
        <span>Página <?= (int)$directorio['pagina'] ?> de <?= (int)$directorio['paginas'] ?> · <?= (int)$directorio['total'] ?> colaboradores</span>
        <?php if ($directorio['pagina'] > 1): ?><a href="/admin/recursos-humanos?<?= s($query(['pagina' => $directorio['pagina'] - 1])) ?>">Anterior</a><?php endif; ?>
        <?php if ($directorio['pagina'] < $directorio['paginas']): ?><a href="/admin/recursos-humanos?<?= s($query(['pagina' => $directorio['pagina'] + 1])) ?>">Siguiente</a><?php endif; ?>
    </nav>
    <?php endif; ?>
</section>

<?php if (!empty($fichasSinVinculo)): ?>
<section class="ui-card" aria-labelledby="rh-pendientes"><h2 id="rh-pendientes">Profesionales pendientes de ficha laboral</h2>
<details><summary>Ver fichas pendientes de vinculación</summary><p>Estas fichas antiguas conservan su agenda e historial. Identifica a cada persona y vincula su ficha al invitarla; no crees una segunda ficha.</p>
<ul><?php foreach ($fichasSinVinculo as $f): ?><li>#<?= (int)$f['id'] ?> · <?= s($f['nombre']) ?><?php if ($puedeProfesionales): ?> · <a href="/profesionales/horarios?id=<?= (int)$f['id'] ?>">Consultar agenda</a><?php endif; ?><?php if ($puedeAccesos): ?> · <a href="/admin/equipo?rol=profesional&amp;profesional_id=<?= (int)$f['id'] ?>">Vincular ficha laboral</a><?php endif; ?></li><?php endforeach; ?></ul>
<?php if ($puedeAccesos): ?><a class="boton" href="/admin/equipo">Vincular desde la invitación</a><?php endif; ?>
</details></section>
<?php endif; ?>

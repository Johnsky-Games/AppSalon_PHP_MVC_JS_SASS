<?php
/**
 * Vista mínima del profesional: solo sus clientes, solo contacto operativo y visitas con él. Sin importes ni consentimiento.
 * Espera $panel (modo propios), $f, $rutaBase y $listado.
 */
$entero = static fn($v): string => (string)(int)$v;
$fechaCorta = static fn($d): string => $d ? substr((string)$d, 0, 10) : '—';
?>
    <section class="crm-card" aria-labelledby="crm-propios-titulo">
        <h2 id="crm-propios-titulo">Buscar entre tus clientes</h2>
        <form class="crm-filtros" action="<?= s($rutaBase) ?>" method="GET" role="search">
            <div class="campo"><label for="crm-q">Nombre, correo o teléfono</label><input id="crm-q" name="q" type="search" maxlength="80" value="<?= s($f['q']) ?>" autocomplete="off"></div>
            <div class="campo"><label for="crm-pp">Por página</label><select id="crm-pp" name="por_pagina"><?php foreach (\Services\CrmService::POR_PAGINA as $n): ?><option value="<?= (int)$n ?>"<?= (int)$f['por_pagina'] === $n ? ' selected' : '' ?>><?= (int)$n ?></option><?php endforeach; ?></select></div>
            <div class="crm-filtros__acciones"><button class="boton" type="submit">Buscar</button><a class="boton ui-button-quiet" href="<?= s($rutaBase) ?>">Limpiar filtros</a></div>
        </form>
    </section>
    <?php if (empty($panel['vinculado'])): ?>
        <p class="crm-vacio" role="status">Tu cuenta aún no está vinculada a una ficha profesional, por eso no hay clientes que mostrar. Pide a la administración que enlace tu cuenta en «Equipo y Permisos».</p>
    <?php elseif (empty($listado['filas'])): ?>
        <p class="crm-vacio" role="status"><?= $f['q'] !== '' ? 'Ninguno de tus clientes coincide con la búsqueda.' : 'Todavía no tienes visitas completadas con clientes. Cuando completes una cita, la persona aparecerá aquí.' ?></p>
    <?php else: ?>
        <div class="crm-tabla" tabindex="0" role="region" aria-label="Tus clientes; desplaza horizontalmente para ver todas las columnas">
            <table>
                <caption class="crm-sr">Clientes atendidos por ti</caption>
                <thead><tr><th scope="col">Cliente</th><th scope="col">Contacto operativo</th><th scope="col">Visitas contigo</th><th scope="col">Última visita contigo</th></tr></thead>
                <tbody>
                <?php foreach ($listado['filas'] as $r): ?>
                    <tr>
                        <th scope="row"><a href="/admin/clientes/ficha?id=<?= (int)$r['id'] ?>"><?= s(trim($r['nombre'] . ' ' . $r['apellido'])) ?></a></th>
                        <td><?= s($r['email']) ?><small><?= s((string)$r['telefono']) ?></small></td>
                        <td class="ui-table-number"><?= $entero($r['visitas']) ?></td>
                        <td class="ui-table-date"><?= s($fechaCorta($r['ultima'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php $pag = $listado; $noun = 'clientes'; $consulta = static fn(array $extra = []): string => \Services\CrmService::consulta($f, $extra); include __DIR__ . '/_paginacion.php'; ?>
    <?php endif; ?>
    <p class="crm-nota">Esta vista muestra solo lo necesario para atender: no incluye gasto, consentimiento comercial ni formularios.</p>

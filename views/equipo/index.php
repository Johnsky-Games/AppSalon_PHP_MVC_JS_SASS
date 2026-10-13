<?php
$etiquetasPermisos = [
    'equipo.gestionar' => 'Administrar accesos del equipo', 'servicios.gestionar' => 'Gestionar servicios',
    'profesionales.gestionar' => 'Gestionar profesionales y horarios', 'perfiles.gestionar' => 'Gestionar perfiles de clientes',
    'agenda.gestionar' => 'Gestionar toda la agenda', 'agenda.ver_propia' => 'Ver y atender su agenda asignada',
    'finanzas.gestionar' => 'Gestionar caja, cobros y comisiones', 'inventario.gestionar' => 'Gestionar inventario',
    'marketing.gestionar' => 'Gestionar fidelidad y formularios', 'ajustes.gestionar' => 'Configurar el negocio',
    'reportes.ver' => 'Consultar reportes', 'clientes.ver' => 'Consultar clientes (datos operativos)',
    'clientes.ver_propios' => 'Ver los clientes que atiende', 'clientes.analitica' => 'Analítica de clientes y gasto',
    'clientes.exportar' => 'Exportar listados de clientes', 'rh.gestionar' => 'Fichas laborales de personal',
];
$etiquetaRol = ['propietario' => 'Propietario', 'gerente' => 'Gerencia', 'recepcion' => 'Recepción', 'profesional' => 'Profesional', 'finanzas' => 'Finanzas'];
$etiquetaEstado = ['invitado' => 'Invitación pendiente', 'activo' => 'Activo', 'suspendido' => 'Suspendido', 'baja' => 'Baja'];
$etiquetaCorreo = ['enviado' => 'Correo enviado', 'pendiente' => 'Correo en cola', 'fallido' => 'Correo no enviado', 'enviando' => 'Enviando correo', 'cancelado' => ''];
$previo = $previo ?? [];
$idemInvitacion = clave_operacion_formulario();
?>
<header class="ui-page-header"><p class="ui-eyebrow">Personas y accesos</p><h1 class="nombre-pagina">Equipo y permisos</h1><p class="descripcion-pagina">Invita a cada persona con su propio correo. Hasta que acepte la invitación no tiene acceso laboral; los cambios de rol o estado cierran sus sesiones anteriores.</p></header>
<?php include __DIR__ . '/../templates/barra.php'; include __DIR__ . '/../templates/alertas.php'; ?>

<section class="ui-card" aria-labelledby="equipo-invitar-titulo">
<h2 id="equipo-invitar-titulo">Invitar a una persona</h2>
<p class="ui-form-help">Un mismo correo corresponde a una sola ficha laboral. Si ya es cliente conserva su cuenta y sus reservas; solo se añade su acceso al equipo cuando acepte.</p>
<form action="/admin/equipo/crear" method="POST" class="formulario" data-una-vez data-rol-form>
<input type="hidden" name="csrf_token" value="<?= s(csrf_token()) ?>">
<input type="hidden" name="idem_key" value="<?= s($idemInvitacion) ?>">
<div class="ui-form-grid">
<div class="campo"><label for="eq-nombre">Nombre</label><input id="eq-nombre" name="nombre" required maxlength="60" autocomplete="off" value="<?= s($previo['nombre'] ?? '') ?>"></div>
<div class="campo"><label for="eq-apellido">Apellido</label><input id="eq-apellido" name="apellido" required maxlength="60" autocomplete="off" value="<?= s($previo['apellido'] ?? '') ?>"></div>
<div class="campo"><label for="eq-email">Correo electrónico</label><input id="eq-email" name="email" type="email" required maxlength="60" autocomplete="off" value="<?= s($previo['email'] ?? '') ?>"></div>
<div class="campo"><label for="eq-telefono">Teléfono (opcional)</label><input id="eq-telefono" name="telefono" inputmode="numeric" pattern="[0-9]{7,10}" maxlength="10" autocomplete="off" value="<?= s($previo['telefono'] ?? '') ?>"></div>
<div class="campo"><label for="eq-puesto">Puesto (opcional)</label><input id="eq-puesto" name="puesto" maxlength="80" value="<?= s($previo['puesto'] ?? '') ?>"></div>
<div class="campo"><label for="eq-ingreso">Fecha de ingreso (opcional)</label><input id="eq-ingreso" name="fecha_ingreso" type="date" value="<?= s($previo['fecha_ingreso'] ?? '') ?>"></div>
<div class="campo"><label for="eq-rol">Rol</label><select name="rol" id="eq-rol" data-rol-select><?php foreach ($roles as $rol) { ?><option value="<?= s($rol) ?>" <?= ($previo['rol'] ?? '') === $rol ? 'selected' : '' ?>><?= s($etiquetaRol[$rol] ?? $rol) ?></option><?php } ?></select></div>
</div>
<fieldset class="ui-profile-preferences" data-solo-rol="profesional"><legend>Si el rol es Profesional: ficha operativa</legend>
<?php if (!empty($fichasSinCuenta)) { ?>
<div class="campo"><label for="eq-ficha">Vincular una ficha existente (conserva su historial)</label><select id="eq-ficha" name="profesional_id"><option value="">Crear una ficha nueva</option><?php foreach ($fichasSinCuenta as $f) { ?><option value="<?= s($f['id']) ?>" <?= (string)($previo['profesional_id'] ?? '') === (string)$f['id'] ? 'selected' : '' ?>><?= s($f['nombre']) ?><?= (int)$f['activo'] === 0 ? ' (inactiva)' : '' ?></option><?php } ?></select></div>
<?php } ?>
<div class="campo"><label for="eq-nombre-prof">Nombre en la agenda (si es una ficha nueva)</label><input id="eq-nombre-prof" name="nombre_profesional" maxlength="120" placeholder="Por defecto: nombre y apellido" value="<?= s($previo['nombre_profesional'] ?? '') ?>"></div>
<p class="ui-section-title">Servicios que podrá realizar (ficha nueva)</p>
<div class="listado-servicios-checks ui-grid"><?php foreach ($servicios as $srv) { $sel = in_array((string)$srv->id, array_map('strval', (array)($previo['servicios'] ?? [])), true); ?>
<div class="check-servicio"><label for="eq-srv-<?= s($srv->id) ?>"><input type="checkbox" id="eq-srv-<?= s($srv->id) ?>" name="servicios[]" value="<?= s($srv->id) ?>" <?= $sel ? 'checked' : '' ?>> <?= s($srv->nombre) ?> (<?= s($srv->duracion_minutos) ?> min)</label></div><?php } ?></div>
<div class="campo"><label for="eq-homonimo"><input type="checkbox" id="eq-homonimo" name="confirmar_homonimo" value="1"> Es otra persona con el mismo nombre que un profesional existente</label></div>
<p class="ui-form-help">La ficha queda inactiva en la agenda hasta que la persona acepte; después se activa y podrás ajustar sus horarios en Profesionales.</p>
</fieldset>
<button class="boton" type="submit">Enviar invitación</button>
</form>
</section>

<section class="ui-card" aria-labelledby="equipo-lista-titulo"><h2 id="equipo-lista-titulo">Personas del equipo</h2>
<div class="ui-grid">
<?php foreach ($colaboradores as $p) {
    $esPropia = (int)($p['usuario_id'] ?? 0) === (int)($_SESSION['id'] ?? -1);
?>
<article class="ui-card" id="colaborador-<?= s($p['id']) ?>">
<h3><?= s($p['nombre'] . ' ' . $p['apellido']) ?></h3>
<p><?= s($p['email']) ?> · <span class="ui-badge"><?= s($p['codigo'] ?? '') ?></span></p>
<p><span class="ui-badge"><?= s($etiquetaEstado[$p['estado']] ?? $p['estado']) ?> · <?= s($etiquetaRol[$p['rol']] ?? $p['rol']) ?></span><?php if (!empty($p['puesto'])) { ?> <?= s($p['puesto']) ?><?php } ?></p>
<?php if (!empty($p['profesional'])) { ?><p>Ficha en agenda: <a href="/profesionales/actualizar?id=<?= s($p['profesional_id']) ?>"><?= s($p['profesional']) ?></a></p><?php } ?>
<?php if ($p['estado'] === 'invitado') { ?>
<p class="ui-form-help">Vence: <?= s($p['invitacion_expira'] ?? '—') ?> UTC<?php if (!empty($p['correo_estado']) && ($etiquetaCorreo[$p['correo_estado']] ?? '') !== '') { ?> · <?= s($etiquetaCorreo[$p['correo_estado']]) ?><?php } ?></p>
<form method="POST" action="/admin/equipo/invitar" data-una-vez><input type="hidden" name="csrf_token" value="<?= s(csrf_token()) ?>"><input type="hidden" name="idem_key" value="<?= s(\Classes\Idempotencia::nuevaClave()) ?>"><input type="hidden" name="id" value="<?= s($p['id']) ?>"><button class="boton" type="submit">Reenviar invitación</button></form>
<form method="POST" action="/admin/equipo/revocar"><input type="hidden" name="csrf_token" value="<?= s(csrf_token()) ?>"><input type="hidden" name="id" value="<?= s($p['id']) ?>"><button class="boton ui-button-quiet" type="submit">Revocar invitación</button></form>
<?php } else { ?>
<form method="POST" action="/admin/equipo/actualizar" class="formulario"><input type="hidden" name="csrf_token" value="<?= s(csrf_token()) ?>"><input type="hidden" name="id" value="<?= s($p['id']) ?>">
<div class="campo"><label for="rol-<?= s($p['id']) ?>">Rol</label><select id="rol-<?= s($p['id']) ?>" name="rol"><?php foreach ($rolesAcceso as $rol) { ?><option value="<?= s($rol) ?>" <?= $rol === $p['rol'] ? 'selected' : '' ?>><?= s($etiquetaRol[$rol] ?? $rol) ?></option><?php } ?></select></div>
<div class="campo"><label for="estado-<?= s($p['id']) ?>">Estado laboral</label><select id="estado-<?= s($p['id']) ?>" name="estado"><?php foreach (['activo' => 'Activo', 'suspendido' => 'Suspendido', 'baja' => 'Baja'] as $v => $t) { ?><option value="<?= s($v) ?>" <?= $p['estado'] === $v ? 'selected' : '' ?>><?= s($t) ?></option><?php } ?></select></div>
<button type="submit" class="boton">Guardar acceso</button></form>
<?php if ($esPropia) { ?><p class="ui-form-help">Esta es tu propia cuenta: se protege al último propietario activo.</p><?php } ?>
<?php } ?>
<?php if (!empty($puedeRh)) { ?><a class="boton ui-button-quiet" href="/admin/equipo/ficha?id=<?= s($p['id']) ?>">Ficha laboral</a><?php } ?>
</article>
<?php } ?>
<?php if (!$colaboradores) { ?><p>Todavía no hay personas en el equipo. Invita a la primera con el formulario de arriba.</p><?php } ?>
</div></section>

<section class="ui-card"><h2>Matriz de permisos por rol</h2><div class="ui-table-scroll" tabindex="0" role="region" aria-label="Matriz de permisos; desplaza horizontalmente para ver todas las columnas"><table class="ui-permissions-table"><thead><tr><th scope="col">Rol</th><th scope="col">Funciones permitidas</th></tr></thead><tbody><?php foreach (\Services\AuthorizationService::ROLES as $rol => $permisos) { ?><tr><td><?= s($etiquetaRol[$rol] ?? ucfirst($rol)) ?></td><td><?= s(implode(' · ', array_map(static fn($permiso) => $etiquetasPermisos[$permiso] ?? 'Función autorizada', $permisos)) ?: 'Reservas y perfil propios (portal de clientes)') ?></td></tr><?php } ?></tbody></table></div>
<h2>Estados laborales</h2>
<ul>
<li><strong>Invitación pendiente:</strong> sin acceso laboral; el enlace vence a los 7 días y solo sirve una vez.</li>
<li><strong>Activo:</strong> accede al portal del equipo con los permisos de su rol.</li>
<li><strong>Suspendido:</strong> conserva ficha e historial pero no entra; sus sesiones se cierran.</li>
<li><strong>Baja:</strong> cierre de la relación; no se borra nada. Si es profesional, antes deben resolverse sus citas futuras.</li>
</ul>
<p>Solo un propietario administra accesos y el último propietario activo no puede desactivarse.</p></section>

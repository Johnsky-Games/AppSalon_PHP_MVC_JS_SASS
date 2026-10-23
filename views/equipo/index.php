<?php
$etiquetasPermisos = [
    'erp.ventas.ver'=>'Consultar ventas',
    'erp.ventas.crear'=>'Gestionar PDV y cotizaciones',
    'erp.cobrar'=>'Registrar cobros',
    'erp.caja'=>'Abrir y arquear caja propia',
    'erp.caja.movimientos'=>'Movimientos de caja',
    'erp.catalogo'=>'Precios e impuestos',
    'erp.configurar'=>'Datos de la empresa',
    'erp.descuento'=>'Autorizar descuentos',
    'erp.verificar'=>'Verificar transferencias',
    'erp.devolver'=>'Devoluciones y anulaciones',
    'erp.compras'=>'Compras y recepciones',
    'erp.rh'=>'Expediente, contratos y asistencia',
    'erp.promociones'=>'Promociones y cupones',
    'erp.comisiones'=>'Liquidar comisiones',
    'erp.aprobaciones'=>'Resolver aprobaciones',
    'erp.reportes'=>'Indicadores y exportación ERP',
    'erp.obligaciones.pagar'=>'Pagar obligaciones',
    'erp.recursos'=>'Recursos, activos e insumos',
    'erp.consumos'=>'Consumos de todas las atenciones',
    'erp.consumos.propios'=>'Consumos de atenciones propias',
    'erp.documentos.propios'=>'Documentos laborales propios autorizados',
    'contabilidad.ver'=>'Consultar libros y estados contables',
    'contabilidad.registrar'=>'Registrar y reversar asientos contables',
    'contabilidad.configurar'=>'Configurar plan de cuentas, roles contables y cierres',
    'presupuestos.ver'=>'Consultar presupuestos y compararlos con lo real',
    'presupuestos.editar'=>'Preparar presupuestos: cifras, plantilla CSV y revisiones',
    'presupuestos.aprobar'=>'Aprobar versiones de presupuesto',
    'nomina.ver'=>'Consultar la nómina y todos los recibos',
    'nomina.calcular'=>'Registrar novedades y calcular la nómina',
    'nomina.aprobar'=>'Revisar y aprobar novedades y nómina; liquidar beneficios',
    'nomina.configurar'=>'Configurar parámetros legales y condiciones de nómina',
    'nomina.recibo_propio'=>'Ver el propio recibo de pago',

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
$previo = array_map(static fn($v) => is_array($v) ? $v : (string)$v, $previo ?? []);
// Con aviso de homónimos se conserva la clave del intento: el token de confirmación está ligado a ella.
$idemInvitacion = !empty($homonimos['clave']) ? $homonimos['clave'] : clave_operacion_formulario();
?>
<header class="ui-page-header"><p class="ui-eyebrow">Personas y accesos</p><h1 class="nombre-pagina">Equipo y permisos</h1><p class="descripcion-pagina">Invita a cada persona con su propio correo. Hasta que acepte la invitación no tiene acceso laboral; los cambios de rol o estado cierran sus sesiones anteriores.</p></header>
<?php include __DIR__ . '/../templates/barra.php'; include __DIR__ . '/../templates/alertas.php'; ?>

<?php if (!empty($puedeRh)) { ?><nav class="gestion-secciones" aria-label="Gestión de personal"><a href="/admin/recursos-humanos">Recursos Humanos</a><a href="/admin/equipo" aria-current="page">Accesos e invitaciones</a></nav><?php } ?>

<section class="ui-card" aria-labelledby="equipo-invitar-titulo">
<?php if (!empty($fichasSinCuenta)): ?>
<details><summary>Vincular a un colaborador ya registrado</summary><p>Selecciona la persona y su ficha antigua. Sus servicios, horarios y citas se conservan.</p>
<form class="formulario ui-filtros" method="POST" action="/admin/equipo/vincular">
<input type="hidden" name="csrf_token" value="<?= s(csrf_token()) ?>">
<div class="campo"><label for="vincular-colaborador">Colaborador existente</label><select id="vincular-colaborador" name="colaborador_id" required><option value="">Selecciona</option><?php foreach($colaboradores as $c): if($c['rol']!=='profesional'||$c['estado']==='baja'||!empty($c['profesional_id'])) continue; ?><option value="<?= (int)$c['id'] ?>"><?= s($c['nombre'].' '.$c['apellido'].' · '.$c['email']) ?></option><?php endforeach; ?></select></div>
<div class="campo"><label for="vincular-profesional">Ficha profesional antigua</label><select id="vincular-profesional" name="profesional_id" required><?php foreach($fichasSinCuenta as $f): ?><option value="<?= (int)$f['id'] ?>"<?= (is_scalar($previo['profesional_id'] ?? null) ? (string)$previo['profesional_id'] : '')===(string)$f['id']?' selected':'' ?>>#<?= (int)$f['id'] ?> · <?= s($f['nombre']) ?></option><?php endforeach; ?></select></div><button class="boton">Confirmar vínculo</button></form></details>
<?php endif; ?>
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
<div class="campo"><label for="eq-ficha">Vincular una ficha existente (conserva su historial)</label><select id="eq-ficha" name="profesional_id"><option value="">Crear una ficha nueva</option><?php foreach ($fichasSinCuenta as $f) { ?><option value="<?= s($f['id']) ?>" <?= (is_scalar($previo['profesional_id'] ?? null) ? (string)$previo['profesional_id'] : '') === (string)$f['id'] ? 'selected' : '' ?>><?= s($f['nombre']) ?><?= (int)$f['activo'] === 0 ? ' (inactiva)' : '' ?></option><?php } ?></select></div>
<?php } ?>
<div class="campo"><label for="eq-nombre-prof">Nombre en la agenda (si es una ficha nueva)</label><input id="eq-nombre-prof" name="nombre_profesional" maxlength="120" placeholder="Por defecto: nombre y apellido" value="<?= s($previo['nombre_profesional'] ?? '') ?>"></div>
<?php if (!empty($fichasSinCuenta)) { ?>
<div class="campo"><label for="eq-ajustar"><input type="checkbox" id="eq-ajustar" name="ajustar_agenda" value="1" <?= ($previo['ajustar_agenda'] ?? '') === '1' ? 'checked' : '' ?>> Al vincular una ficha existente, reemplazar sus servicios y su horario por lo indicado abajo</label><small class="ui-form-help">Si no lo marcas, la ficha conserva su configuración actual y lo de abajo solo se usa para fichas nuevas.</small></div>
<?php } ?>
<?php $ag = ['prefijo' => 'eq', 'mostrarServicios' => true, 'servicios' => $servicios, 'seleccion' => (array)($previo['servicios'] ?? []), 'franjas' => (array)($previo['franjas'] ?? []), 'descansos' => (array)($previo['descansos'] ?? [])]; include __DIR__ . '/_agenda_asistente.php'; ?>
<p class="ui-form-help">Una ficha nueva guarda su horario y servicios desde ya, pero no aparece en disponibilidad ni en reservas hasta que la persona acepte la invitación; entonces se activa con esta configuración (una ficha existente conserva servicios, horarios e historial y también espera la aceptación). Puedes ajustarla después en su ficha laboral.</p>
</fieldset>
<?php if (!empty($homonimos)) { $puedeVerFicha = !empty($puedeProfesionales); include __DIR__ . '/_aviso_homonimos_profesional.php'; } else { ?>
<button class="boton" type="submit">Enviar invitación</button>
<?php } ?>
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
<form method="POST" action="/admin/equipo/actualizar" class="formulario" data-una-vez><input type="hidden" name="csrf_token" value="<?= s(csrf_token()) ?>"><input type="hidden" name="idem_key" value="<?= s(\Classes\Idempotencia::nuevaClave()) ?>"><input type="hidden" name="id" value="<?= s($p['id']) ?>">
<div class="campo"><label for="rol-<?= s($p['id']) ?>">Rol</label><select id="rol-<?= s($p['id']) ?>" name="rol"><?php foreach ($rolesAcceso as $rol) { ?><option value="<?= s($rol) ?>" <?= $rol === $p['rol'] ? 'selected' : '' ?>><?= s($etiquetaRol[$rol] ?? $rol) ?></option><?php } ?></select></div>
<div class="campo"><label for="estado-<?= s($p['id']) ?>">Estado laboral</label><select id="estado-<?= s($p['id']) ?>" name="estado"><?php foreach (['activo' => 'Activo', 'suspendido' => 'Suspendido', 'baja' => 'Baja'] as $v => $t) { ?><option value="<?= s($v) ?>" <?= $p['estado'] === $v ? 'selected' : '' ?>><?= s($t) ?></option><?php } ?></select></div>
<button type="submit" class="boton">Guardar acceso</button></form>
<?php if ($esPropia) { ?><p class="ui-form-help">Esta es tu propia cuenta: se protege al último propietario activo.</p><?php } ?>
<?php } ?>
<?php if (!empty($puedeRh)) { ?><a class="boton ui-button-quiet" href="/admin/equipo/ficha?id=<?= s($p['id']) ?>">Ficha laboral</a><?php } ?>
<?php if (!empty($puedeAgenda) && !empty($p['profesional_id']) && $p['rol'] === 'profesional' && $p['estado'] !== 'invitado') { ?><a class="boton ui-button-quiet" href="/admin/equipo/reasignar?id=<?= s($p['id']) ?>">Revisar citas futuras</a><?php } ?>
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
<li><strong>Baja:</strong> cierre de la relación; no se borra nada. Si es profesional, antes deben resolverse sus citas futuras: usa «Revisar citas futuras» para reasignarlas a un sustituto disponible, cancelarlas con aviso al cliente o revisarlas a mano.</li>
</ul>
<p>Solo un propietario administra accesos y el último propietario activo no puede desactivarse.</p></section>

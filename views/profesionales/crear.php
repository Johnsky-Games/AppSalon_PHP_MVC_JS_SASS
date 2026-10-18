<header class="ui-page-header"><p class="ui-eyebrow">Recursos Humanos · Alta profesional</p><h1 class="nombre-pagina">Nuevo Profesional</h1><p class="descripcion-pagina">Registra a la persona una sola vez: su ficha laboral, invitación y agenda quedan vinculadas.</p></header>
<?php include __DIR__ . '/../templates/barra.php'; include __DIR__ . '/../templates/alertas.php'; ?>
<form action="/profesionales/crear" method="POST" class="formulario ui-card" data-una-vez>
<input type="hidden" name="csrf_token" value="<?= s(csrf_token()) ?>">
<input type="hidden" name="idem_key" value="<?= s(clave_operacion_formulario()) ?>">
<div class="ui-form-grid">
<?php foreach (['nombre' => 'Nombre', 'apellido' => 'Apellido', 'email' => 'Correo electrónico'] as $campo => $etiqueta): ?>
<div class="campo"><label for="<?= $campo ?>"><?= $etiqueta ?></label><input id="<?= $campo ?>" name="<?= $campo ?>" type="<?= $campo === 'email' ? 'email' : 'text' ?>" maxlength="60" required value="<?= s($previo[$campo] ?? '') ?>"></div>
<?php endforeach; ?>
<div class="campo"><label for="telefono">Teléfono (opcional)</label><input id="telefono" name="telefono" inputmode="numeric" pattern="[0-9]{7,10}" maxlength="10" value="<?= s($previo['telefono'] ?? '') ?>"></div>
<div class="campo"><label for="puesto">Puesto (opcional)</label><input id="puesto" name="puesto" maxlength="80" value="<?= s($previo['puesto'] ?? '') ?>"></div>
<div class="campo"><label for="fecha-ingreso">Fecha de ingreso (opcional)</label><input id="fecha-ingreso" name="fecha_ingreso" type="date" value="<?= s($previo['fecha_ingreso'] ?? '') ?>"></div>
</div>
<div class="campo"><label for="nombre-agenda">Nombre en agenda (opcional)</label><input id="nombre-agenda" name="nombre_agenda" maxlength="120" placeholder="Por defecto: nombre y apellido" value="<?= s($previo['nombre_agenda'] ?? '') ?>"></div>
<?php if ($fichasSinCuenta): ?>
<div class="campo"><label for="ficha-existente">Ficha antigua de esta persona</label><select id="ficha-existente" name="profesional_id"><option value="">Crear ficha nueva</option><?php foreach ($fichasSinCuenta as $f): ?><option value="<?= (int)$f['id'] ?>"<?= ($previo['profesional_id'] ?? '') === (string)$f['id'] ? ' selected' : '' ?>>#<?= (int)$f['id'] ?> · <?= s($f['nombre']) ?></option><?php endforeach; ?></select><small>Selecciona explícitamente su ficha para conservar citas, servicios y horarios. Nunca se fusionan personas por nombre.</small></div>
<?php endif; ?>
<?php $ag = ['prefijo' => 'alta-prof', 'mostrarServicios' => true, 'servicios' => $serviciosDisponibles, 'seleccion' => $previo['servicios'] ?? [], 'franjas' => $previo['franjas'] ?? [], 'descansos' => $previo['descansos'] ?? []]; include __DIR__ . '/../equipo/_agenda_asistente.php'; ?>
<p class="ui-form-help">Los servicios y horarios se guardan en la misma ficha que podrás consultar desde Recursos Humanos. Una ficha nueva no admite reservas hasta aceptar la invitación. Al vincular una ficha antigua se conserva su configuración.</p>
<?php if (!empty($homonimos)) { $puedeVerFicha = true; include __DIR__ . '/../equipo/_aviso_homonimos_profesional.php'; } else { ?>
<input type="submit" value="Invitar profesional" class="boton">
<?php } ?>
</form>
<div class="acciones"><a href="/admin/recursos-humanos">Directorio de Recursos Humanos</a><a href="/profesionales">Volver a profesionales</a></div>

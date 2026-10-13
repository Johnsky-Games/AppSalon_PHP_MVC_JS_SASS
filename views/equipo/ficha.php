<?php $etiquetaRol = ['propietario' => 'Propietario', 'gerente' => 'Gerencia', 'recepcion' => 'Recepción', 'profesional' => 'Profesional', 'finanzas' => 'Finanzas']; ?>
<header class="ui-page-header"><p class="ui-eyebrow">Ficha laboral · privada</p><h1 class="nombre-pagina"><?= s($colaborador['nombre'] . ' ' . $colaborador['apellido']) ?></h1><p class="descripcion-pagina"><?= s($colaborador['codigo'] ?? '') ?> · <?= s($etiquetaRol[$colaborador['rol']] ?? $colaborador['rol']) ?> · <?= s($colaborador['estado']) ?>. Solo se muestra a quien tiene permiso de RR. HH.; nunca a clientes, recepción ni finanzas.</p></header>
<?php include __DIR__ . '/../templates/barra.php'; include __DIR__ . '/../templates/alertas.php'; ?>
<form method="POST" action="/admin/equipo/ficha?id=<?= s($colaborador['id']) ?>" class="formulario ui-card" data-una-vez>
<input type="hidden" name="csrf_token" value="<?= s(csrf_token()) ?>">
<div class="campo"><label for="f-email">Correo</label><input id="f-email" value="<?= s($colaborador['email']) ?>" disabled></div>
<div class="campo"><label for="f-telefono">Teléfono</label><input id="f-telefono" name="telefono" inputmode="numeric" pattern="[0-9]{7,10}" maxlength="10" value="<?= s($colaborador['telefono'] ?? '') ?>"></div>
<div class="campo"><label for="f-puesto">Puesto</label><input id="f-puesto" name="puesto" maxlength="80" value="<?= s($colaborador['puesto'] ?? '') ?>"></div>
<div class="campo"><label for="f-ingreso">Fecha de ingreso</label><input id="f-ingreso" name="fecha_ingreso" type="date" value="<?= s($colaborador['fecha_ingreso'] ?? '') ?>"></div>
<div class="campo"><label for="f-notas">Notas internas de RR. HH.</label><textarea id="f-notas" name="notas_rh" maxlength="500" rows="4"><?= s($colaborador['notas_rh'] ?? '') ?></textarea><small class="ui-form-help">No es nómina ni contrato: solo notas operativas. No registres datos sensibles innecesarios.</small></div>
<button class="boton" type="submit">Guardar ficha</button>
</form>
<div class="acciones"><a href="/admin/equipo" class="ui-back-link">← Volver al equipo</a></div>

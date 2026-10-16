<?php
$p = $revision['profesional'];
$citas = $revision['citas'];
$resumen = $revision['resumen'];
$verCliente = !empty($revision['ver_cliente']);
$pendientes = count($citas);
$dias = ['', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
$fechaLegible = static function (string $f) use ($dias): string {
    $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $f);
    return $dt ? ucfirst($dias[(int)$dt->format('N')]) . ' ' . $dt->format('d/m/Y') : $f;
};
$etiquetaEstado = ['confirmada' => 'Confirmada', 'en_servicio' => 'En servicio'];
$etiquetaPago = ['pendiente' => 'Pago pendiente', 'pagado' => 'Pagada', 'parcial' => 'Pago parcial', 'reembolsado' => 'Reembolsada'];
?>
<header class="ui-page-header"><p class="ui-eyebrow">Equipo · baja o suspensión asistida</p><h1 class="nombre-pagina">Citas futuras de <?= s($p['nombre'] ?: 'este profesional') ?></h1>
<p class="descripcion-pagina">Antes de suspender o dar de baja a un profesional hay que resolver cada cita que ocupa su agenda. Para cada una puedes reasignarla a un sustituto que preste todos sus servicios y esté realmente libre, cancelarla (se avisa al cliente) o dejarla para revisión manual. La baja se confirma solo cuando no queda ninguna sin resolver.</p></header>
<?php include __DIR__ . '/../templates/barra.php'; include __DIR__ . '/../templates/alertas.php'; ?>

<section class="ui-card" aria-labelledby="rea-resumen-titulo">
<h2 id="rea-resumen-titulo">Resumen</h2>
<p><span class="ui-badge"><?= (int)$resumen['total'] ?> cita(s) por resolver</span>
<span class="ui-badge"><?= (int)$resumen['con_sustituto'] ?> con sustituto disponible</span>
<span class="ui-badge ui-badge--warning"><?= (int)$resumen['bloqueadas'] ?> requieren gestión manual</span></p>
<?php if (!$verCliente) { ?><p class="ui-form-help">Tu rol no incluye la consulta de datos de clientes: se muestran las citas sin nombre del cliente.</p><?php } ?>
</section>

<?php if ($pendientes > 0) { ?>
<form method="POST" action="/admin/equipo/reasignar" class="formulario" data-una-vez>
<input type="hidden" name="csrf_token" value="<?= s(csrf_token()) ?>">
<input type="hidden" name="idem_key" value="<?= s($idemReasignar) ?>">
<input type="hidden" name="id" value="<?= s($p['colaborador_id']) ?>">
<section class="ui-card" aria-labelledby="rea-citas-titulo">
<h2 id="rea-citas-titulo">Decide qué hacer con cada cita</h2>
<div class="rea-lista">
<?php foreach ($citas as $c) { ?>
<article class="rea-cita" id="cita-<?= s($c['id']) ?>">
<header><h3><?= s($fechaLegible($c['fecha'])) ?> · <?= s($c['hora_inicio']) ?><?= $c['hora_fin'] ? '–' . s($c['hora_fin']) : '' ?></h3>
<p><span class="ui-badge"><?= s($etiquetaEstado[$c['estado']] ?? $c['estado']) ?></span> <span class="ui-badge ui-badge--muted"><?= s($etiquetaPago[$c['estado_pago']] ?? $c['estado_pago']) ?></span> <span class="ui-badge ui-badge--muted">Cita #<?= s($c['id']) ?></span></p></header>
<p class="rea-dato"><strong>Cliente:</strong> <?= $verCliente ? s($c['cliente']) : 'Datos reservados' ?></p>
<p class="rea-dato"><strong>Servicios:</strong> <?= s(implode(', ', $c['servicios']) ?: 'Sin servicios registrados') ?></p>
<?php if ($c['bloqueo']) { ?><p class="ui-form-help rea-bloqueo" role="note"><?= s($c['bloqueo']) ?></p>
<?php } elseif (!$c['sustitutos']) { ?><p class="ui-form-help rea-bloqueo" role="note">Ningún otro profesional activo presta todos estos servicios y está libre en ese horario. Puedes cancelarla o dejarla para revisión manual.</p><?php } ?>
<div class="campo"><label for="decision-<?= s($c['id']) ?>">Decisión</label>
<select id="decision-<?= s($c['id']) ?>" name="decision[<?= s($c['id']) ?>]">
<option value="manual">Dejar para revisión manual (la baja seguirá bloqueada)</option>
<?php foreach ($c['sustitutos'] as $sus) { ?><option value="reasignar:<?= s($sus['id']) ?>">Reasignar a <?= s($sus['nombre']) ?></option><?php } ?>
<?php if ($c['cancelable']) { ?><option value="cancelar">Cancelar la cita y avisar al cliente</option><?php } ?>
</select></div>
</article>
<?php } ?>
</div>
<div class="campo"><label for="rea-motivo">Motivo (queda en el historial de cada cita)</label><input id="rea-motivo" name="motivo" required minlength="3" maxlength="200" placeholder="Ej.: baja del profesional" autocomplete="off"></div>
<p class="ui-form-help">Las reasignaciones se aplican todas juntas o ninguna: si un sustituto deja de estar libre mientras confirmas, no se mueve nada y verás el motivo. El cliente recibe un aviso por cada cita reasignada o cancelada.</p>
<button class="boton" type="submit">Aplicar decisiones</button>
</section>
</form>
<?php } else { ?>
<section class="ui-state-card"><p>No quedan citas futuras asignadas a este profesional. Ya puedes confirmar la suspensión o la baja.</p></section>
<?php } ?>

<?php if (!empty($puedeBaja)) { ?>
<section class="ui-card" aria-labelledby="rea-baja-titulo">
<h2 id="rea-baja-titulo">Confirmar suspensión o baja</h2>
<?php if ($pendientes > 0) { ?><p class="ui-form-help">Bloqueado: quedan <?= $pendientes ?> cita(s) futura(s) sin resolver. El servidor también lo rechaza si lo intentas por otra vía.</p><?php } ?>
<form method="POST" action="/admin/equipo/actualizar" class="formulario" data-una-vez>
<input type="hidden" name="csrf_token" value="<?= s(csrf_token()) ?>">
<input type="hidden" name="idem_key" value="<?= s($idemAcceso) ?>">
<input type="hidden" name="id" value="<?= s($p['colaborador_id']) ?>">
<input type="hidden" name="rol" value="<?= s($p['rol']) ?>">
<div class="campo"><label for="rea-estado">Nuevo estado laboral</label><select id="rea-estado" name="estado"><option value="suspendido">Suspendido (conserva ficha e historial)</option><option value="baja">Baja (cierra la relación)</option></select></div>
<button class="boton" type="submit" <?= $pendientes > 0 ? 'disabled aria-disabled="true"' : '' ?>>Confirmar</button>
</form>
</section>
<?php } ?>
<div class="acciones"><a href="/admin/equipo" class="ui-back-link">← Volver al equipo</a></div>

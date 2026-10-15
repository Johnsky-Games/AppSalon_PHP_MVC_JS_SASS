<?php
include __DIR__ . '/_helpers.php';
$c = $d['cita']; $citaId = $c['id'];
[$etqPago, $clsPago] = caja_estado_pago($d['estado']);
$volverCita = campos_retorno('caja_cita', ['id' => $citaId] + $ctx);
$hayPorVerificar = false; foreach ($d['pagos'] as $p) { if ($p['por_verificar']) { $hayPorVerificar = true; } }
?>
<section class="caja caja-cita" aria-labelledby="cobro-titulo">
<header class="ui-page-header"><p class="ui-eyebrow">Cobro de cita</p><h1 id="cobro-titulo">Cita #<?php echo (int)$citaId; ?> · <?php echo s($c['cliente'] ?: 'Cliente'); ?></h1>
<p><span class="caja-badge <?php echo s($clsPago); ?>"><?php echo s($etqPago); ?></span> · Atención: <strong><?php echo s(caja_estado_cita($c['estado'])); ?></strong></p></header>
<?php include __DIR__ . '/../templates/barra.php'; include __DIR__ . '/../templates/alertas.php'; ?>
<nav class="ops-toolbar" aria-label="Navegación del cobro">
<a class="boton ui-button-quiet" href="<?php echo s(destino_portal('caja', $ctx)); ?>">Volver a caja</a>
<?php if (($a = destino_portal('agenda_cita', ['id' => $citaId] + $ctx)) !== ''): ?><a class="boton ui-button-quiet" href="<?php echo s($a); ?>">Ver atención de la cita</a><?php endif; ?>
<?php if ($comprobantes): ?><a class="boton" href="<?php echo s(destino_portal('caja_comprobante', ['id' => $citaId])); ?>">Ver comprobante</a><?php endif; ?>
</nav>

<div class="caja-ficha ui-card"><dl>
<div><dt>Cliente</dt><dd><?php echo s($c['cliente'] ?: '—'); ?></dd></div><div><dt>Profesional</dt><dd><?php echo s($c['profesional'] ?: 'Sin asignar'); ?></dd></div>
<div><dt>Fecha</dt><dd><?php echo s($c['fecha']); ?> <?php echo s($c['hora']); ?></dd></div></dl></div>

<h2>Servicios y precios históricos</h2>
<div class="ops-table-wrap" tabindex="0" role="region" aria-label="Servicios de la cita"><table class="ops-table"><thead><tr><th scope="col">Servicio</th><th scope="col">Precio al reservar</th></tr></thead><tbody>
<?php foreach ($d['servicios'] as $s_): ?><tr><td><?php echo s($s_['nombre']); ?></td><td class="ui-table-number"><?php echo s(caja_dinero($s_['precio_centavos'])); ?></td></tr><?php endforeach; ?>
<?php if (!$d['servicios']): ?><tr><td colspan="2">La cita no tiene servicios con precio.</td></tr><?php endif; ?></tbody>
<tfoot><tr><th scope="row">Total de la cita</th><td class="ui-table-number"><strong><?php echo s(caja_dinero($d['total_centavos'])); ?></strong></td></tr></tfoot></table></div>

<div class="ui-grid ui-dashboard-stats caja-resumen" role="group" aria-label="Resumen financiero">
<article class="ui-card ui-stat"><p>Total</p><strong><?php echo s(caja_dinero($d['total_centavos'])); ?></strong></article>
<article class="ui-card ui-stat"><p>Cobrado confirmado</p><strong><?php echo s(caja_dinero($d['cobrado_centavos'])); ?></strong><?php if ($d['cobrado_simulado_centavos'] > 0): ?><small>Incluye <?php echo s(caja_dinero($d['cobrado_simulado_centavos'])); ?> de la pasarela simulada (no entra a caja)</small><?php endif; ?></article>
<article class="ui-card ui-stat"><p>Devuelto</p><strong><?php echo s(caja_dinero($d['devuelto_centavos'])); ?></strong></article>
<article class="ui-card ui-stat"><p>Por verificar</p><strong><?php echo s(caja_dinero($d['pendiente_verificacion_centavos'])); ?></strong><small>No cuenta como dinero hasta verificarla</small></article>
<article class="ui-card ui-stat caja-saldo"><p>Saldo</p><strong><?php echo s(caja_dinero($d['por_cobrar_centavos'])); ?></strong><small>Total − cobrado + devuelto<?php echo $d['por_cobrar_centavos'] !== $d['saldo_centavos'] ? ' (esta cita no admite cobro)' : ''; ?></small></article>
</div>
<?php if ($d['anticipo_sin_resolver']): ?><div class="alerta error" role="alert">Esta cita está <?php echo s(mb_strtolower(caja_estado_cita($c['estado']))); ?> y conserva <strong><?php echo s(caja_dinero($d['anticipo_sin_resolver_centavos'])); ?></strong> cobrados. No se devuelve solo: decida si se retiene (dejándolo así) o se devuelve con el botón «Devolver» del pago correspondiente.</div><?php endif; ?>

<h2>Pagos</h2>
<div class="ops-table-wrap" tabindex="0" role="region" aria-label="Pagos de la cita; desplaza horizontalmente para ver todas las columnas"><table class="ops-table"><thead><tr><th scope="col">Pago</th><th scope="col">Método</th><th scope="col">Estado</th><th scope="col">Importe</th><th scope="col">Detalle</th><th scope="col">Acciones</th></tr></thead><tbody>
<?php foreach ($d['pagos'] as $p): ?>
<tr><td><?php echo s($p['referencia']); ?><small><?php echo caja_fecha($p['pagado_at'] ?: $p['creado']); ?></small></td>
<td><?php echo s(caja_metodo($p['metodo'])); ?><?php if ($p['simulado']): ?><small>Simulado: no es dinero real</small><?php endif; ?></td>
<td><?php
  if ($p['por_verificar']) { echo 'Por verificar'; } elseif ($p['estado_verificacion'] === 'rechazado') { echo 'Rechazada'; } elseif ($p['estado'] === 'reembolsado') { echo 'Devuelto por completo'; }
  elseif ($p['estado'] === 'completado') { echo 'Confirmado' . ($p['devuelto_centavos'] > 0 ? ' (devuelto ' . s(caja_dinero($p['devuelto_centavos'])) . ')' : ''); } else { echo s(ucfirst($p['estado'])); } ?></td>
<td class="ui-table-number"><?php echo s(caja_dinero($p['monto_centavos'])); ?></td>
<td><?php if ($p['recibido_centavos'] !== null): ?>Recibido <?php echo s(caja_dinero($p['recibido_centavos'])); ?> · cambio <?php echo s(caja_dinero((int)$p['cambio_centavos'])); ?><br><?php endif; ?>
<?php if ($p['referencia_transferencia']): ?>Ref. <?php echo s($p['referencia_transferencia']); ?><br><?php endif; ?>
<?php if ($p['registrado_por_nombre']): ?>Registró: <?php echo s($p['registrado_por_nombre']); ?><br><?php endif; ?>
<?php if ($p['verificado_por_nombre']): ?><?php echo $p['estado_verificacion'] === 'rechazado' ? 'Rechazó' : 'Verificó'; ?>: <?php echo s($p['verificado_por_nombre']); ?> · <?php echo caja_fecha($p['verificado_en']); ?><?php if ($p['verificacion_nota']): ?><br>Nota: <?php echo s($p['verificacion_nota']); ?><?php endif; ?><?php endif; ?></td>
<td class="caja-acciones">
<?php if ($p['por_verificar']): $propia = $p['registrado_por'] === $actorId && !$esPropietario; ?>
  <?php if ($propia): ?><p class="caja-nota">Lo registraste tú: otra persona con permiso de finanzas (o el propietario) debe verificarlo.</p><?php else: ?>
  <form method="POST" action="/caja/pago/verificar" data-una-vez><input type="hidden" name="csrf_token" value="<?php echo s(csrf_token()); ?>"><input type="hidden" name="idem_key" value="<?php echo s(\Classes\Idempotencia::nuevaClave()); ?>"><input type="hidden" name="pago_id" value="<?php echo (int)$p['id']; ?>"><input type="hidden" name="cita_id" value="<?php echo (int)$citaId; ?>"><?php echo $volverCita; ?><div class="campo"><label for="ver-nota-<?php echo (int)$p['id']; ?>">Nota (opcional)</label><input id="ver-nota-<?php echo (int)$p['id']; ?>" name="nota" maxlength="180"></div><button class="boton" type="submit">Confirmar que el dinero llegó</button></form><?php endif; ?>
  <button type="button" class="boton ui-button-quiet" data-modal-open="rechazo-<?php echo (int)$p['id']; ?>">Rechazar</button>
  <dialog id="rechazo-<?php echo (int)$p['id']; ?>" class="ops-modal" aria-labelledby="rechazo-t-<?php echo (int)$p['id']; ?>"><button type="button" class="ops-modal-close" data-modal-close aria-label="Cerrar">×</button><h2 id="rechazo-t-<?php echo (int)$p['id']; ?>">Rechazar transferencia</h2><p>No entra a caja ni genera comisión; la referencia queda libre. Queda registrado quién la rechazó y por qué.</p>
  <form class="formulario" method="POST" action="/caja/pago/rechazar" data-una-vez><input type="hidden" name="csrf_token" value="<?php echo s(csrf_token()); ?>"><input type="hidden" name="idem_key" value="<?php echo s(\Classes\Idempotencia::nuevaClave()); ?>"><input type="hidden" name="pago_id" value="<?php echo (int)$p['id']; ?>"><input type="hidden" name="cita_id" value="<?php echo (int)$citaId; ?>"><?php echo $volverCita; ?><div class="campo"><label for="rec-nota-<?php echo (int)$p['id']; ?>">Motivo del rechazo</label><input id="rec-nota-<?php echo (int)$p['id']; ?>" name="nota" required minlength="3" maxlength="180"></div><button class="boton" type="submit">Rechazar transferencia</button></form></dialog>
<?php elseif ($p['proveedor'] === 'manual' && $p['estado'] === 'completado'): $resta = $p['monto_centavos'] - $p['devuelto_centavos']; ?>
  <button type="button" class="boton ui-button-quiet" data-modal-open="devol-<?php echo (int)$p['id']; ?>">Devolver</button>
  <dialog id="devol-<?php echo (int)$p['id']; ?>" class="ops-modal" aria-labelledby="devol-t-<?php echo (int)$p['id']; ?>"><button type="button" class="ops-modal-close" data-modal-close aria-label="Cerrar">×</button><h2 id="devol-t-<?php echo (int)$p['id']; ?>">Devolver dinero</h2>
  <p>Confirme solo si va a devolver o ya devolvió el dinero: el sistema registra un egreso de caja de ese importe y ajusta la comisión. Máximo devolvible de este pago: <strong><?php echo s(caja_dinero($resta)); ?></strong>.</p>
  <form class="formulario" method="POST" action="/caja/cita/devolver" data-una-vez><input type="hidden" name="csrf_token" value="<?php echo s(csrf_token()); ?>"><input type="hidden" name="idem_key" value="<?php echo s(\Classes\Idempotencia::nuevaClave()); ?>"><input type="hidden" name="pago_id" value="<?php echo (int)$p['id']; ?>"><input type="hidden" name="cita_id" value="<?php echo (int)$citaId; ?>"><?php echo $volverCita; ?>
  <div class="campo"><label for="dev-monto-<?php echo (int)$p['id']; ?>">Importe a devolver (USD)</label><input id="dev-monto-<?php echo (int)$p['id']; ?>" name="monto" inputmode="decimal" required pattern="\d{1,7}(\.\d{1,2})?" value="<?php echo s(\Services\CobroCalculo::plano($resta)); ?>"></div>
  <div class="campo"><label for="dev-motivo-<?php echo (int)$p['id']; ?>">Motivo</label><input id="dev-motivo-<?php echo (int)$p['id']; ?>" name="motivo" required minlength="3" maxlength="180"></div><button class="boton" type="submit">Registrar devolución</button></form></dialog>
<?php else: ?>—<?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if (!$d['pagos']): ?><tr><td colspan="6">Todavía no hay pagos registrados para esta cita.</td></tr><?php endif; ?>
</tbody></table></div>

<?php if ($d['devoluciones']): ?><h2>Devoluciones</h2>
<div class="ops-table-wrap" tabindex="0" role="region" aria-label="Devoluciones de la cita"><table class="ops-table"><thead><tr><th scope="col">Fecha</th><th scope="col">Importe</th><th scope="col">Método</th><th scope="col">Motivo</th><th scope="col">Registró</th></tr></thead><tbody>
<?php foreach ($d['devoluciones'] as $v): ?><tr><td><?php echo caja_fecha($v['registrado_en']); ?></td><td class="ui-table-number"><?php echo s(caja_dinero($v['monto_centavos'])); ?></td><td><?php echo s(caja_metodo($v['metodo'])); ?></td><td><?php echo s($v['motivo']); ?></td><td><?php echo s($v['actor'] ?: '—'); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>

<h2>Registrar cobro</h2>
<?php if ($d['cobrable']): ?>
<form class="formulario caja-form-cobro" method="POST" action="/caja/cita/cobrar" data-una-vez data-caja-cobro data-disponible="<?php echo (int)$d['disponible_centavos']; ?>">
<input type="hidden" name="csrf_token" value="<?php echo s(csrf_token()); ?>"><input type="hidden" name="idem_key" value="<?php echo s(\Classes\Idempotencia::nuevaClave()); ?>"><input type="hidden" name="cita_id" value="<?php echo (int)$citaId; ?>"><?php echo $volverCita; ?>
<fieldset class="campo"><legend>Método de pago</legend>
<label><input type="radio" name="metodo" value="efectivo" checked> Efectivo</label>
<label><input type="radio" name="metodo" value="transferencia"> Transferencia (queda por verificar)</label></fieldset>
<div class="campo"><label for="cobro-monto">Importe a aplicar (USD)</label><input id="cobro-monto" name="monto" inputmode="decimal" required pattern="\d{1,7}(\.\d{1,2})?" value="<?php echo s(\Services\CobroCalculo::plano($d['disponible_centavos'])); ?>" aria-describedby="cobro-monto-ayuda"><small id="cobro-monto-ayuda">Disponible para cobrar: <?php echo s(caja_dinero($d['disponible_centavos'])); ?>. Puede cobrar un anticipo o un pago parcial; nunca más que el saldo.</small></div>
<div class="campo" data-metodo-campo="efectivo"><label for="cobro-recibido">Efectivo recibido (USD)</label><input id="cobro-recibido" name="recibido" inputmode="decimal" pattern="\d{1,7}(\.\d{1,2})?" placeholder="Igual al importe si no hay cambio"><small>Cambio a entregar: <strong data-cambio>$0.00</strong>. El cambio no es ingreso: en caja entra solo el importe aplicado.</small></div>
<div class="campo" data-metodo-campo="transferencia"><label for="cobro-ref">Referencia de la transferencia</label><input id="cobro-ref" name="referencia" maxlength="80" autocomplete="off"><small>Número de comprobante u operación. Es obligatoria y no puede repetirse. No es dinero confirmado hasta que alguien con permiso de finanzas la verifique.</small></div>
<button class="boton" type="submit">Registrar cobro</button>
</form>
<?php else: ?>
<div class="alerta <?php echo $d['intento_en_linea'] ? 'error' : 'exito'; ?>" role="status"><?php
  if (!in_array($c['estado'], \Services\CobroCitaService::ESTADOS_COBRABLES, true)) { echo 'Una cita ' . s(mb_strtolower(caja_estado_cita($c['estado']))) . ' no admite nuevos cobros.'; }
  elseif ($d['intento_en_linea']) { echo 'Hay un pago en línea de esta cita en proceso. Espere a que termine antes de cobrar en caja.'; }
  elseif ($d['total_centavos'] <= 0) { echo 'La cita no tiene servicios con importe a cobrar.'; }
  elseif ($hayPorVerificar && $d['disponible_centavos'] === 0) { echo 'Las transferencias por verificar ya cubren el saldo. Verifíquelas o recházelas arriba.'; }
  else { echo 'La cita está totalmente cobrada. No queda saldo.'; } ?></div>
<?php endif; ?>

<?php if ($comprobantes): ?><h2>Comprobantes emitidos</h2><ul class="caja-lista-simple"><?php foreach ($comprobantes as $k): ?><li><a href="<?php echo s(destino_portal('caja_comprobante', ['id' => $citaId]) . '&comprobante=' . (int)$k['id']); ?>"><?php echo s($k['codigo']); ?></a> · <?php echo $k['tipo'] === 'devolucion' ? 'devolución' : 'cobro'; ?> · <?php echo caja_fecha($k['emitido_en']); ?></li><?php endforeach; ?></ul><?php endif; ?>
<?php if ($eventos): ?><details><summary>Historial de movimientos (auditoría)</summary><ul class="caja-lista-simple"><?php foreach ($eventos as $e): ?><li><?php echo caja_fecha($e['registrado_en']); ?> · <?php echo s(str_replace('_', ' ', $e['tipo'])); ?> · <?php echo s($e['actor'] ?: 'cuenta eliminada'); ?> (<?php echo s($e['actor_rol']); ?>)</li><?php endforeach; ?></ul></details><?php endif; ?>
</section>
<script src="/build/js/caja.js" defer></script>

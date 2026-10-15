<?php
/** Comprobante INTERNO imprimible y descargable (documento autónomo, sin layout). Variables: $c (ComprobanteService::obtener), $volver ('' al descargar). */
use Services\CobroCalculo;

$k = $c['contenido'];
$m = static fn($x): string => '$' . CobroCalculo::formato((int)$x);
$t = $k['totales'];
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Comprobante interno <?php echo s($c['codigo']); ?></title>
<style>
:root{color-scheme:light;--tinta:#18343e;--suave:#5d7078;--linea:#cfdadb;--fondo:#f4f7f7}
*{box-sizing:border-box}body{margin:0;background:var(--fondo);color:var(--tinta);font:15px/1.5 "Segoe UI",Inter,system-ui,sans-serif}
.hoja{max-width:760px;margin:24px auto;padding:32px;background:#fff;border:1px solid var(--linea);border-radius:12px}
header{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;border-bottom:2px solid var(--tinta);padding-bottom:12px;margin-bottom:16px}
h1{font-size:1.4rem;margin:0}h2{font-size:1rem;margin:20px 0 6px;text-transform:uppercase;letter-spacing:.04em;color:var(--suave)}
.codigo{font-size:1.5rem;font-weight:700;letter-spacing:.03em}
.aviso{margin:12px 0;padding:10px 12px;border:1px solid var(--tinta);border-radius:8px;font-weight:700;text-align:center}
table{width:100%;border-collapse:collapse}th,td{padding:6px 8px;border-bottom:1px solid var(--linea);text-align:left;vertical-align:top}th{color:var(--suave);font-weight:600}
td.n,th.n{text-align:right;font-variant-numeric:tabular-nums}tfoot td,tfoot th{border-bottom:0;font-weight:700}
dl{display:grid;grid-template-columns:max-content 1fr;gap:2px 16px;margin:0}dt{color:var(--suave)}dd{margin:0}
.nota{color:var(--suave);font-size:.85rem}.acciones{max-width:760px;margin:16px auto;display:flex;gap:12px;flex-wrap:wrap;padding:0 8px}
.acciones a,.acciones button{font:inherit;padding:8px 14px;border:1px solid var(--tinta);border-radius:8px;background:#fff;color:var(--tinta);text-decoration:none;cursor:pointer}
.total td,.total th{font-size:1.1rem}
@media print{body{background:#fff}.hoja{border:0;margin:0;padding:0;max-width:none}.acciones{display:none}}
@media (max-width:520px){.hoja{margin:0;border-radius:0;padding:16px}}
</style></head><body>
<?php if ($volver !== ''): ?><div class="acciones"><a href="<?php echo s($volver); ?>">Volver al cobro</a><button type="button" onclick="window.print()">Imprimir</button><a href="<?php echo s(destino_portal('caja_comprobante', ['id' => (int)$k['cita']['id']]) . '&comprobante=' . (int)$c['id'] . '&descargar=1'); ?>">Descargar (HTML)</a></div><?php endif; ?>
<main class="hoja">
<header><div><h1><?php echo s($k['negocio']); ?></h1><div><?php echo $c['tipo'] === 'devolucion' ? 'Comprobante de devolución' : 'Comprobante de cobro'; ?></div></div>
<div><div class="codigo"><?php echo s($c['codigo']); ?></div><div class="nota">Emitido: <?php echo s($k['emitido_en']); ?> (hora de Guayaquil)<br>Por: <?php echo s($k['emitido_por'] ?: '—'); ?></div></div></header>
<p class="aviso"><?php echo s($k['leyenda']); ?>. No sustituye a una factura electrónica ni tiene validez tributaria.</p>
<h2>Cita</h2>
<dl><dt>Cita</dt><dd>#<?php echo (int)$k['cita']['id']; ?> · <?php echo s($k['cita']['fecha']); ?> <?php echo s($k['cita']['hora']); ?></dd>
<dt>Cliente</dt><dd><?php echo s($k['cita']['cliente'] ?: '—'); ?></dd><dt>Profesional</dt><dd><?php echo s($k['cita']['profesional'] ?: 'Sin asignar'); ?></dd></dl>
<h2>Servicios (precios al reservar)</h2>
<table><thead><tr><th>Servicio</th><th class="n">Precio</th></tr></thead><tbody>
<?php foreach ($k['servicios'] as $sv): ?><tr><td><?php echo s($sv['nombre']); ?></td><td class="n"><?php echo s($m($sv['precio_centavos'])); ?></td></tr><?php endforeach; ?></tbody>
<tfoot><tr><td>Total de la cita</td><td class="n"><?php echo s($m($t['total_centavos'])); ?></td></tr></tfoot></table>
<h2>Pagos registrados</h2>
<table><thead><tr><th>Pago</th><th>Método y estado</th><th class="n">Importe aplicado</th></tr></thead><tbody>
<?php foreach ($k['pagos'] as $p):
    $estado = $p['estado_verificacion'] === 'pendiente_verificacion' ? 'por verificar (no cuenta como pago)' : ($p['estado_verificacion'] === 'rechazado' ? 'rechazada' : ($p['estado'] === 'reembolsado' ? 'devuelto por completo' : ($p['estado'] === 'completado' ? 'confirmado' : $p['estado']))); ?>
<tr><td><?php echo s($p['referencia']); ?><div class="nota"><?php echo s(substr((string)($p['pagado_at'] ?? ''), 0, 16)); ?><?php if ($p['simulado']): ?> · pasarela simulada, no es dinero real<?php endif; ?></div></td>
<td><?php echo s(ucfirst($p['metodo'] ?: '—')); ?> · <?php echo s($estado); ?><?php if ($p['recibido_centavos'] !== null): ?><div class="nota">Recibido <?php echo s($m($p['recibido_centavos'])); ?> · cambio <?php echo s($m((int)$p['cambio_centavos'])); ?></div><?php endif; ?>
<?php if ($p['referencia_transferencia']): ?><div class="nota">Ref. transferencia: <?php echo s($p['referencia_transferencia']); ?><?php if ($p['verificado_en']): ?> · verificada <?php echo s(substr((string)$p['verificado_en'], 0, 16)); ?><?php endif; ?></div><?php endif; ?></td>
<td class="n"><?php echo s($m($p['monto_centavos'])); ?></td></tr><?php endforeach; ?>
<?php if (!$k['pagos']): ?><tr><td colspan="3">Sin pagos.</td></tr><?php endif; ?></tbody></table>
<?php if ($k['devoluciones']): ?><h2>Devoluciones</h2><table><thead><tr><th>Fecha y motivo</th><th class="n">Importe</th></tr></thead><tbody>
<?php foreach ($k['devoluciones'] as $v): ?><tr><td><?php echo s(substr((string)$v['registrado_en'], 0, 16)); ?> · <?php echo s($v['motivo']); ?></td><td class="n">−<?php echo s($m($v['monto_centavos'])); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
<h2>Resumen</h2>
<table><tbody><tr><td>Total de la cita</td><td class="n"><?php echo s($m($t['total_centavos'])); ?></td></tr>
<tr><td>Cobrado confirmado</td><td class="n"><?php echo s($m($t['cobrado_centavos'])); ?></td></tr>
<tr><td>Devuelto</td><td class="n">−<?php echo s($m($t['devuelto_centavos'])); ?></td></tr>
<?php if ($t['pendiente_verificacion_centavos'] > 0): ?><tr><td>Transferencias por verificar (no incluidas)</td><td class="n"><?php echo s($m($t['pendiente_verificacion_centavos'])); ?></td></tr><?php endif; ?></tbody>
<tfoot><tr class="total"><th>Saldo a la fecha de emisión</th><td class="n"><?php echo s($m(max(0, $t['saldo_centavos']))); ?></td></tr></tfoot></table>
<p class="nota">Estado del pago de la cita al emitir: <?php echo s(str_replace('_', ' ', $t['estado'])); ?>. Integridad: SHA-256 <?php echo s(substr($c['contenido_sha256'], 0, 16)); ?>…</p>
<p class="nota">Documento interno de control. La facturación electrónica autorizada por el SRI no está integrada en este sistema.</p>
</main></body></html>

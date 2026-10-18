<?php include __DIR__ . '/_helpers.php'; ?>
<section class="caja caja-lista" aria-labelledby="caja-titulo">
<header class="ui-page-header"><p class="ui-eyebrow">Caja y conciliación</p><h1 id="caja-titulo">Cobros por cita</h1>
<p>Cobre desde la cita, sin teclear identificadores. Atención y cobro son independientes: una cita atendida puede quedar con saldo pendiente.</p></header>
<?php include __DIR__ . '/../templates/barra.php'; include __DIR__ . '/../templates/alertas.php'; ?>
<?php
$rango = ['desde' => $filtros['desde'], 'hasta' => $filtros['hasta']];
$activos = [];
if ($filtros['estado'] !== '') { $activos['Estado: ' . caja_estado_pago($filtros['estado'])[0]] = 'estado'; }
if ($filtros['metodo'] !== '') { $activos['Método: ' . caja_metodo($filtros['metodo'])] = 'metodo'; }
if ($filtros['profesional'] > 0) { foreach ($profesionales as $p) { if ($p['id'] === $filtros['profesional']) { $activos['Profesional: ' . $p['nombre']] = 'profesional'; } } }
if ($filtros['q'] !== '') { $activos['Cliente: ' . $filtros['q']] = 'q'; }
$consulta = static function (array $extra = []) use ($filtros): string {
    $q = array_filter(['desde' => $filtros['desde'], 'hasta' => $filtros['hasta'], 'estado' => $filtros['estado'], 'metodo' => $filtros['metodo'], 'profesional' => $filtros['profesional'] ?: '', 'q' => $filtros['q']] + $extra, static fn($v) => $v !== '' && $v !== null);
    return http_build_query($q, '', '&', PHP_QUERY_RFC3986);
};
?>
<div class="ui-grid ui-dashboard-stats caja-kpis" role="group" aria-label="Indicadores del universo filtrado (<?php echo (int)$totales['citas']; ?> citas)">
<article class="ui-card ui-stat"><p>Por cobrar</p><strong><?php echo s(caja_dinero($totales['por_cobrar'])); ?></strong><small>Saldo de citas que admiten cobro</small></article>
<article class="ui-card ui-stat"><p>Por verificar</p><strong><?php echo s(caja_dinero($totales['pendiente'])); ?></strong><small>Transferencias declaradas: aún no son dinero</small></article>
<article class="ui-card ui-stat"><p>Cobrado confirmado</p><strong><?php echo s(caja_dinero($totales['cobrado'])); ?></strong><small>Incluye pagos de la pasarela simulada, que no entran a caja</small></article>
<article class="ui-card ui-stat"><p>Devuelto</p><strong><?php echo s(caja_dinero($totales['devuelto'])); ?></strong></article>
<?php if ($totales['anticipo_sin_resolver'] > 0): ?><article class="ui-card ui-stat"><p>Anticipos sin resolver</p><strong><?php echo s(caja_dinero($totales['anticipo_sin_resolver'])); ?></strong><small>Citas canceladas o con ausencia que conservan dinero</small></article><?php endif; ?>
</div>

<?php if ($porVerificar): ?>
<section class="caja-pendientes" aria-labelledby="caja-pend-titulo"><h2 id="caja-pend-titulo">Transferencias por verificar (<?php echo count($porVerificar); ?>)</h2>
<p>Confirme solo después de ver el dinero en la cuenta. Quien registró la transferencia no puede verificarla (salvo el propietario).</p>
<div class="ops-table-wrap" tabindex="0" role="region" aria-label="Transferencias por verificar; desplaza horizontalmente para ver todas las columnas"><table class="ops-table"><thead><tr><th scope="col">Cita</th><th scope="col">Cliente</th><th scope="col">Referencia</th><th scope="col">Importe</th><th scope="col">Registró</th><th scope="col">Acciones</th></tr></thead><tbody>
<?php foreach ($porVerificar as $t): $propia = (int)$t['registrado_por'] === $actorId; ?>
<tr><td><a href="<?php echo s(destino_portal('caja_cita', ['id' => (int)$t['cita_id']] + $rango)); ?>">#<?php echo (int)$t['cita_id']; ?></a></td><td><?php echo s($t['cliente']); ?></td><td><?php echo s($t['referencia_transferencia']); ?></td><td class="ui-table-number"><?php echo s(caja_dinero(\Services\CobroCalculo::centavos((string)$t['monto']))); ?></td><td><?php echo s($t['registrado_por_nombre']); ?><small><?php echo caja_fecha($t['created_at']); ?></small></td>
<td class="caja-acciones">
<form method="POST" action="/caja/pago/verificar" data-una-vez>

<input type="hidden" name="csrf_token" value="<?php echo s(csrf_token()); ?>"><input type="hidden" name="idem_key" value="<?php echo s(\Classes\Idempotencia::nuevaClave()); ?>"><input type="hidden" name="pago_id" value="<?php echo (int)$t['pago_id']; ?>"><input type="hidden" name="cita_id" value="<?php echo (int)$t['cita_id']; ?>"><?php echo campos_retorno('caja', $rango + ['estado' => $filtros['estado'] ?: null]); ?><button class="boton" type="submit" aria-label="Verificar la transferencia de la cita <?php echo (int)$t['cita_id']; ?>">Verificar</button></form>
<a class="boton ui-button-quiet" href="<?php echo s(destino_portal('caja_cita', ['id' => (int)$t['cita_id']] + $rango)); ?>">Ver cita y rechazar</a></td></tr>
<?php endforeach; ?></tbody></table></div></section>
<?php endif; ?>

<p class="ui-help caja-nota-fechas">Las fechas filtran por la <strong>fecha programada de la cita</strong> (no por la efectiva de la atención ni por la del cobro). Una cita antigua (por ejemplo, una atención regularizada) se cobra hoy y conserva la fecha real de su cobro: el efectivo del día está en <a href="/admin/finanzas">Finanzas</a> (movimientos de caja por fecha real).</p>
<form class="formulario ops-filter caja-filtros" method="GET" action="/caja" aria-label="Filtros de caja">
<?php $periodoContexto="caja"; $periodoHistorico=false; include __DIR__."/../templates/_periodo_filtro.php"; ?>
<div class="campo"><label for="caja-desde">Desde</label><input id="caja-desde" name="desde" type="date" value="<?php echo s($filtros['desde']); ?>"></div>
<div class="campo"><label for="caja-hasta">Hasta</label><input id="caja-hasta" name="hasta" type="date" value="<?php echo s($filtros['hasta']); ?>"></div>
<div class="campo"><label for="caja-q">Cliente</label><input id="caja-q" name="q" type="search" maxlength="80" value="<?php echo s($filtros['q']); ?>" placeholder="Nombre, correo o teléfono"></div>
<div class="campo"><label for="caja-prof">Profesional</label><select id="caja-prof" name="profesional"><option value="">Todos</option><?php foreach ($profesionales as $p): ?><option value="<?php echo (int)$p['id']; ?>"<?php echo $p['id'] === $filtros['profesional'] ? ' selected' : ''; ?>><?php echo s($p['nombre']); ?></option><?php endforeach; ?></select></div>
<div class="campo"><label for="caja-metodo">Método</label><select id="caja-metodo" name="metodo"><option value="">Todos</option><option value="efectivo"<?php echo $filtros['metodo'] === 'efectivo' ? ' selected' : ''; ?>>Efectivo</option><option value="transferencia"<?php echo $filtros['metodo'] === 'transferencia' ? ' selected' : ''; ?>>Transferencia</option></select></div>
<div class="campo"><label for="caja-estado">Estado de pago</label><select id="caja-estado" name="estado"><option value="">Todos</option><?php foreach (\Repositories\CajaRepository::ESTADOS as $e): ?><option value="<?php echo s($e); ?>"<?php echo $e === $filtros['estado'] ? ' selected' : ''; ?>><?php echo s(caja_estado_pago($e)[0]); ?></option><?php endforeach; ?></select></div>
<button class="boton" type="submit">Filtrar</button>
<a class="boton ui-button-quiet" href="<?php echo s(destino_portal('caja')); ?>">Limpiar filtros</a>
</form>
<?php if ($activos): ?><p class="caja-activos" aria-live="polite">Filtros activos: <?php foreach ($activos as $texto => $k): ?><span class="ops-badge"><?php echo s($texto); ?></span> <?php endforeach; ?></p><?php endif; ?>
<div class="ops-toolbar"><span><?php echo (int)$totales['citas']; ?> citas · <?php echo s($filtros['desde']); ?> a <?php echo s($filtros['hasta']); ?></span>
<a class="boton" href="/caja/exportar?<?php echo s($consulta(['formato' => 'csv'])); ?>">Descargar CSV</a>
<a class="boton" href="/caja/exportar?<?php echo s($consulta(['formato' => 'xlsx'])); ?>">Descargar Excel (XLSX)</a>
<a class="boton ui-button-quiet" href="<?php echo s(destino_portal('finanzas')); ?>">Movimientos de caja, comisiones y devoluciones</a></div>

<div class="ops-table-wrap" tabindex="0" role="region" aria-label="Citas y su estado de cobro; desplaza horizontalmente para ver todas las columnas"><table class="ops-table"><caption>Citas del período</caption>
<thead><tr><th scope="col">Cita</th><th scope="col">Cliente y profesional</th><th scope="col">Atención</th><th scope="col">Estado de pago</th><th scope="col">Total</th><th scope="col">Cobrado</th><th scope="col">Devuelto</th><th scope="col">Por cobrar</th><th scope="col">Acciones</th></tr></thead><tbody>
<?php foreach ($filas as $f): [$etq, $cls] = caja_estado_pago($f['estado_pago']); ?>
<tr><td><a href="<?php echo s(destino_portal('caja_cita', ['id' => $f['id']] + $rango)); ?>">#<?php echo (int)$f['id']; ?></a><small><?php echo s($f['fecha']); ?> <?php echo s($f['hora']); ?></small></td>
<td><?php echo s($f['cliente']); ?><small><?php echo s($f['profesional'] ?: 'Sin profesional'); ?></small></td>
<td><?php echo s(caja_estado_cita($f['cita_estado'])); ?></td>
<td><span class="caja-badge <?php echo s($cls); ?>"><?php echo s($etq); ?></span><?php if ($f['metodos']): ?><small><?php echo s(implode(', ', array_map('caja_metodo', $f['metodos']))); ?></small><?php endif; ?><?php if ($f['anticipo_sin_resolver'] > 0): ?><small>Anticipo sin resolver <?php echo s(caja_dinero($f['anticipo_sin_resolver'])); ?></small><?php endif; ?></td>
<td class="ui-table-number"><?php echo s(caja_dinero($f['total'])); ?></td><td class="ui-table-number"><?php echo s(caja_dinero($f['cobrado'])); ?></td><td class="ui-table-number"><?php echo s(caja_dinero($f['devuelto'])); ?></td><td class="ui-table-number"><?php echo s(caja_dinero($f['por_cobrar'])); ?><?php if ($f['pendiente'] > 0): ?><small>+ <?php echo s(caja_dinero($f['pendiente'])); ?> por verificar</small><?php endif; ?></td>
<td class="caja-acciones"><a class="boton" href="<?php echo s(destino_portal('caja_cita', ['id' => $f['id']] + $rango)); ?>"><?php echo $f['cobrable'] && $f['por_cobrar'] > 0 ? 'Cobrar' : 'Ver cobro'; ?></a><?php if ($f['cobrado'] > 0): ?> <a class="boton ui-button-quiet" href="<?php echo s(destino_portal('caja_comprobante', ['id' => $f['id']])); ?>">Comprobante</a><?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if (!$filas): ?><tr><td colspan="9">No hay citas con estos filtros. <a href="<?php echo s(destino_portal('caja')); ?>">Limpiar filtros</a></td></tr><?php endif; ?>
</tbody>
<?php if ($filas): ?><tfoot><tr><th scope="row" colspan="4">Totales del universo filtrado (<?php echo (int)$totales['citas']; ?> citas, todas las páginas)</th><td class="ui-table-number"><?php echo s(caja_dinero($totales['total'])); ?></td><td class="ui-table-number"><?php echo s(caja_dinero($totales['cobrado'])); ?></td><td class="ui-table-number"><?php echo s(caja_dinero($totales['devuelto'])); ?></td><td class="ui-table-number"><?php echo s(caja_dinero($totales['por_cobrar'])); ?></td><td></td></tr></tfoot><?php endif; ?>
</table></div>
<nav class="ops-toolbar" aria-label="Páginas de caja"><?php if ($filtros['pagina'] > 1): ?><a href="?<?php echo s($consulta(['pagina' => $filtros['pagina'] - 1])); ?>">Anterior</a><?php endif; ?><span>Página <?php echo (int)$filtros['pagina']; ?> de <?php echo (int)$paginas; ?></span><?php if ($filtros['pagina'] < $paginas): ?><a href="?<?php echo s($consulta(['pagina' => $filtros['pagina'] + 1])); ?>">Siguiente</a><?php endif; ?></nav>
</section>

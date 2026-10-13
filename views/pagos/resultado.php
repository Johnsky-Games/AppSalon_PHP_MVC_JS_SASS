<?php
/** Estado informado exclusivamente por la consulta de pago en el servidor. */
$estadoPago = strtolower(trim((string)($pago['estado'] ?? 'desconocido')));
$uiPagoEstados = [
    'completado' => ['titulo' => 'Pago confirmado', 'detalle' => 'Tu pago fue confirmado. Puedes consultar los detalles de tu reserva en Mis citas.', 'clase' => 'success', 'icono' => 'success'],
    'pendiente' => ['titulo' => 'Pendiente de confirmación', 'detalle' => 'Estamos esperando la confirmación del proveedor. Revisa el estado más tarde antes de realizar otro pago.', 'clase' => 'warning', 'icono' => 'clock'],
    'procesando' => ['titulo' => 'Estamos procesando tu pago', 'detalle' => 'La operación sigue en proceso. Espera la confirmación y evita repetir el pago por ahora.', 'clase' => 'warning', 'icono' => 'clock'],
    'fallido' => ['titulo' => 'El pago no se completó', 'detalle' => 'El proveedor no confirmó el pago. Revisa el estado de tu reserva y tu medio de pago antes de intentarlo nuevamente.', 'clase' => 'error', 'icono' => 'error'],
    'reembolsado' => ['titulo' => 'Reembolso registrado', 'detalle' => 'El reembolso quedó registrado. El tiempo para ver el dinero en tu cuenta depende de tu medio de pago.', 'clase' => 'success', 'icono' => 'success'],
];
$uiPagoEstado = $uiPagoEstados[$estadoPago] ?? ['titulo' => 'Consultemos tu pago', 'detalle' => 'No hay un estado confirmado para esta operación. Revisa tus citas o consulta con el salón.', 'clase' => 'warning', 'icono' => 'info'];
$uiPagoNoDisponible = !is_array($pago);
$uiPagoCodigo = http_response_code();
$uiPagoErrorTitulo = match ($uiPagoCodigo) {
    403 => 'Este pago pertenece a un acceso protegido',
    404 => 'No encontramos este pago',
    422 => 'Necesitamos la referencia del pago',
    default => 'No pudimos consultar tu pago',
};
?>
<p class="ui-eyebrow">TU RESERVA, CON TRANQUILIDAD</p>
<h1 class="nombre-pagina">Estado de tu pago</h1>
<p class="descripcion-pagina">Consulta el estado actualizado de tu operación.</p>

<?php if ($uiPagoNoDisponible): ?>
<section class="ui-state-card ui-state-card--warning" aria-labelledby="pago-sin-resultado-titulo">
    <?php $uiEstadoIcono = 'info'; include __DIR__ . '/../errores/icono.php'; ?>
    <h2 id="pago-sin-resultado-titulo"><?php echo s($uiPagoErrorTitulo); ?></h2>
    <?php include __DIR__ . '/../templates/alertas.php'; ?>
    <p>Abre tu reserva desde Mis citas para consultar la operación correspondiente. Si ya realizaste un pago, comprueba su estado antes de volver a pagar.</p>
    <div class="ui-state-actions"><a class="boton" href="/mis-citas">Ir a mis citas</a><a class="boton ui-button-quiet" href="/perfil">Ir a mi perfil</a></div>
</section>
<?php else: ?>
<section class="ui-state-card ui-state-card--<?php echo s($uiPagoEstado['clase']); ?>" aria-labelledby="pago-estado-real">
    <?php $uiEstadoIcono = $uiPagoEstado['icono']; include __DIR__ . '/../errores/icono.php'; ?>
    <h2 id="pago-estado-real" data-estado="<?php echo s($estadoPago); ?>"><?php echo s($uiPagoEstado['titulo']); ?></h2>
    <p><?php echo s($uiPagoEstado['detalle']); ?></p>
    <?php include __DIR__ . '/../templates/alertas.php'; ?>
    <?php if (!empty($advertenciaSeguridad)): ?>
    <p class="ui-error-hint" id="pago-advertencia-redirect" role="status">El regreso a esta página no confirma el pago. Aquí mostramos únicamente el estado validado por el proveedor.</p>
    <?php endif; ?>
    <div class="ui-state-actions"><a class="boton" href="/mis-citas">Ver mis citas</a></div>
</section>

<section id="resumen-resultado-pago" aria-label="Detalle del pago">
    <div class="ui-payment-summary">
        <dl><dt>Referencia de pago</dt><dd id="pago-referencia"><?php echo s((string)($pago['referencia_pago'] ?? '')); ?></dd><dd><small>Proveedor: <?php echo s(strtoupper((string)($pago['proveedor'] ?? ''))); ?></small></dd></dl>
        <dl><dt>Importe de la operación</dt><dd class="ui-payment-amount" id="pago-monto-servidor">$<?php echo number_format((float)($pago['monto'] ?? 0), 2, '.', ''); ?> <?php echo s((string)($pago['moneda'] ?? 'USD')); ?></dd><dd><small>Reserva #<?php echo (int)($pago['cita_referencia_id'] ?? $pago['cita_id'] ?? 0); ?></small></dd></dl>
        <dl><dt>Confirmación del pago</dt><dd><?php echo !empty($pago['pagado_at']) ? s((string)$pago['pagado_at']) : 'Aún sin confirmación'; ?></dd><dd><small>Conserva la referencia para cualquier consulta.</small></dd></dl>
    </div>
    <?php if (is_array($cita)): ?>
    <article class="ui-card">
        <h2>Tu reserva</h2>
        <dl class="ui-reservation-detail">
            <div><dt>Fecha</dt><dd><?php echo s((string)($cita['fecha'] ?? '')); ?></dd></div>
            <div><dt>Horario</dt><dd><?php
                $hIni = substr((string)($cita['hora_inicio'] ?? $cita['hora'] ?? ''), 0, 5);
                $hFin = !empty($cita['hora_fin']) ? substr((string)$cita['hora_fin'], 0, 5) : null;
                echo s($hFin ? "{$hIni} – {$hFin}" : $hIni);
            ?></dd></div>
            <?php if (!empty($cita['profesional_nombre'])): ?>
            <div><dt>Profesional</dt><dd><?php echo s((string)$cita['profesional_nombre']); ?></dd></div>
            <?php endif; ?>
        </dl>
    </article>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php
/**
 * Vista informativa de estado de pago (`GET /pago/resultado` — Fase 6C).
 * Muestra exclusivamente el estado verificado en la base de datos del servidor,
 * nunca parámetros pasados por el query string del navegador.
 */
$estadoPago = strtolower(trim((string)($pago['estado'] ?? 'desconocido')));
$etiquetaEstado = match ($estadoPago) {
    'completado' => 'Pago Completado (Confirmado en Servidor)',
    'pendiente' => 'Pendiente de Confirmación (En espera de notificación del proveedor)',
    'procesando' => 'En Procesamiento',
    'fallido' => 'Pago Rechazado / Fallido',
    'reembolsado' => 'Pago Reembolsado',
    default => 'Estado Desconocido',
};
?>

<h1 class="nombre-pagina">Estado del Pago</h1>
<p class="descripcion-pagina">Información verificada directamente en el servidor de AppSalon</p>

<?php include_once __DIR__ . '/../templates/alertas.php'; ?>

<?php if (!empty($advertenciaSeguridad)): ?>
    <div class="alerta error" id="pago-advertencia-redirect" role="alert">
        <?php echo s((string)$advertenciaSeguridad); ?>
    </div>
<?php endif; ?>

<?php if (is_array($pago)): ?>
    <section class="seccion-reportes" id="resumen-resultado-pago" aria-label="Detalle del estado de pago">
        <div class="reportes-kpis">
            <article class="reporte-kpi">
                <span class="reporte-kpi__titulo">Referencia de Pago</span>
                <strong class="reporte-kpi__valor" id="pago-referencia"><?php echo s((string)($pago['referencia_pago'] ?? '')); ?></strong>
                <span class="reporte-kpi__sub">Proveedor: <?php echo s(strtoupper((string)($pago['proveedor'] ?? 'mock'))); ?></span>
            </article>

            <article class="reporte-kpi <?php echo $estadoPago === 'completado' ? 'reporte-kpi--pagado' : 'reporte-kpi--reservado'; ?>">
                <span class="reporte-kpi__titulo">Estado Verificado en Servidor</span>
                <strong class="reporte-kpi__valor" id="pago-estado-real" data-estado="<?php echo s($estadoPago); ?>">
                    <?php echo s($etiquetaEstado); ?>
                </strong>
                <span class="reporte-kpi__sub">
                    <?php if (!empty($pago['pagado_at'])): ?>
                        Confirmado: <?php echo s((string)$pago['pagado_at']); ?>
                    <?php else: ?>
                        Sin confirmación definitiva del proveedor aún
                    <?php endif; ?>
                </span>
            </article>

            <article class="reporte-kpi">
                <span class="reporte-kpi__titulo">Importe Calculado en Servidor</span>
                <strong class="reporte-kpi__valor" id="pago-monto-servidor">
                    $<?php echo number_format((float)($pago['monto'] ?? 0), 2, '.', ''); ?> <?php echo s((string)($pago['moneda'] ?? 'USD')); ?>
                </strong>
                <span class="reporte-kpi__sub">
                    Cita #<?php echo (int)($pago['cita_referencia_id'] ?? $pago['cita_id'] ?? 0); ?>
                </span>
            </article>
        </div>

        <?php if (is_array($cita)): ?>
            <div class="reporte-bloque">
                <h2 class="reporte-bloque__titulo">Detalle de la Reserva Asociada</h2>
                <p><strong>Fecha:</strong> <?php echo s((string)($cita['fecha'] ?? '')); ?></p>
                <p><strong>Horario:</strong>
                    <?php
                    $hIni = !empty($cita['hora_inicio']) ? substr((string)$cita['hora_inicio'], 0, 5) : substr((string)($cita['hora'] ?? ''), 0, 5);
                    $hFin = !empty($cita['hora_fin']) ? substr((string)$cita['hora_fin'], 0, 5) : null;
                    echo s($hFin ? "{$hIni} - {$hFin}" : $hIni);
                    ?>
                </p>
                <?php if (!empty($cita['profesional_nombre'])): ?>
                    <p><strong>Profesional:</strong> <?php echo s((string)$cita['profesional_nombre']); ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<div class="acciones">
    <a class="boton" href="/cita">Volver a Mis Citas</a>
</div>

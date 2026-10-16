<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' || getenv('DB_NAME') !== 'appsalon_e2e_test') {
    fwrite(STDERR, "Verificación permitida únicamente en appsalon_e2e_test.\n");
    exit(1);
}
require __DIR__ . '/../includes/app.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$report = json_decode(file_get_contents($argv[1] ?? ''), true, 512, JSON_THROW_ON_ERROR);
$id = filter_var($report['citaCreadaId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    throw new RuntimeException('El reporte no identifica una cita creada válida.');
}
$stmt = $db->prepare('SELECT c.fecha, c.usuarioId, c.profesionalId, c.hora_inicio, c.hora_fin,
    c.duracion_total_minutos, c.estado, c.estado_pago, COUNT(cs.id) AS servicios
    FROM citas c LEFT JOIN citasservicios cs ON cs.citaId=c.id WHERE c.id=? GROUP BY c.id');
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$row || $row['fecha'] !== ($report['fechaReservaEsperada'] ?? null)
    || (int)$row['usuarioId'] !== 1 || (int)$row['profesionalId'] !== 2
    || $row['hora_inicio'] !== '11:30:00' || $row['hora_fin'] !== '13:00:00'
    || (int)$row['duracion_total_minutos'] !== 90 || (int)$row['servicios'] !== 2
    || $row['estado'] !== 'confirmada' || $row['estado_pago'] !== 'pagado') {
    throw new RuntimeException('La cita persistida no coincide con la reserva interactiva y su pago.');
}
$stmt = $db->prepare("SELECT COUNT(*) AS total FROM pagos WHERE cita_id=? AND estado='completado' AND monto=195.00 AND moneda='USD'");
$stmt->bind_param('i', $id);
$stmt->execute();
if ((int)$stmt->get_result()->fetch_assoc()['total'] !== 1) {
    throw new RuntimeException('Se esperaba exactamente un pago completado de USD 195.00 para la cita.');
}
$stmt->close();
$notifications = $db->query("SELECT SUM(tipo='confirmacion' AND estado='enviado') AS confirmaciones,
    SUM(tipo='cancelacion' AND estado='enviado') AS cancelaciones FROM notificaciones_citas")->fetch_assoc();
if ((int)$notifications['confirmaciones'] < 2 || (int)$notifications['cancelaciones'] < 1) {
    throw new RuntimeException('El outbox no conserva confirmaciones y cancelación enviadas.');
}
echo "Persistencia E2E verificada: cita, dos servicios, pago único y outbox enviado.\n";

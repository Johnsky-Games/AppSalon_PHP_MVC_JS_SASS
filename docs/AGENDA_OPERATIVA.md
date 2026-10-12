# Agenda operativa

La agenda usa la zona America/Guayaquil. `GET /agenda` admite fechas `desde`/`hasta` hasta 31 días, con límite visible de 500 registros. Recepción/gerencia/propietario necesitan `agenda.gestionar`. Profesional necesita `agenda.ver_propia`: el listado y las mutaciones verifican `profesionales.usuarioId` en el servidor. `GET /mis-citas` muestra exclusivamente historial del cliente autenticado, incluyendo cancelaciones.

## Flujo de atención

- `confirmada → en_servicio → completada`.
- `confirmada → no_asistio` cuando ya comenzó la hora programada.
- No se permite saltar de confirmada a completada, ni reabrir estados terminales.
- La repetición del mismo estado es idempotente.
- Cancelación conserva su endpoint y metadata históricos; sólo permite confirmadas antes del inicio exacto. No se sustituye por eliminación física.
- Confirmadas, en servicio y completadas preservan intervalos ocupados. Ausencias y canceladas liberan disponibilidad.

## Reprogramación

Cliente dueño o personal con `agenda.gestionar` puede reprogramar una cita confirmada que todavía no comenzó. Se exige fecha posterior a hoy, hora estricta HH:MM, profesional activo e intervalo histórico válido. Se mantiene el mismo profesional; los cambios de profesional/servicios requieren una política comercial adicional y no se hacen implícitamente. Citas antiguas sin profesional requieren gestión del salón.

El bloqueo del profesional, seguido por la cita, usa el mismo recurso de serialización de las nuevas reservas. Bajo bloqueo se comprueban turnos, descansos, bloqueos y otras visitas. No se toca `citasservicios`, precios históricos, estado de pago ni pagos: la duración procede del snapshot original y nunca del catálogo modificado.

El cambio y su evento de auditoría `agenda_eventos` se confirman en la misma transacción. Se genera un outbox con nuevo horario y se descartan confirmaciones/recordatorios pendientes del horario anterior, manteniendo intactos mensajes ya enviados. No se llama SMTP bajo bloqueo. Un mensaje ya en tránsito hacia SMTP no se puede retirar; las comprobaciones de vigencia del worker reducen mensajes obsoletos.

## Pruebas

`tests/Integration/AgendaOperativaIntegrationTest.php` cubre dueño/ajeno, alcance profesional, solapamiento, encaje de turno, snapshots y pago, transiciones válidas/terminales/idempotencia, historial cancelado, hora pasada, fallo SQL de auditoría con rollback y outbox durable. Ejecutar mediante la suite PHPUnit del repositorio en MySQL real tras migraciones 010 y 012.

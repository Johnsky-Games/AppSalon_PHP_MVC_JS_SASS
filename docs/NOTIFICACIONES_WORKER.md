# Arquitectura de Notificaciones, Patrón Outbox y Resiliencia de Workers — AppSalon

Este documento describe el diseño técnico, las garantías de entrega, el ciclo de vida de los arrendamientos (*worker leases*) y la mitigación de fallos en el subsistema de notificaciones de AppSalon.

---

## 1. Diseño Arquitectónico: Patrón Transaccional Outbox

En versiones anteriores, las notificaciones transaccionales (confirmaciones y cancelaciones) se despachaban inmediatamente tras el commit de la base de datos mediante llamadas directas a SMTP. Esta arquitectura presentaba dos riesgos críticos:
1. **Pérdida de eventos por caída post-commit:** Si el proceso PHP terminaba inesperadamente entre el commit de la cita y la llamada SMTP (por fallo de red, agotamiento de memoria, reinicio del contenedor o kill del proceso), el evento se perdía definitivamente sin dejar rastro en la base de datos.
2. **Dependencia de la disponibilidad de un tercero:** La latencia o indisponibilidad temporal del servidor SMTP degradaba el tiempo de respuesta de la reserva para el cliente.

### Solución Implementada: Outbox Durable

1. **Persistencia Durable Dentro de la Transacción:**
   - Durante la creación (`reservarConProfesional`) o cancelación (`cancelarCitaLogica`), se inserta de forma atómica en la tabla `notificaciones_citas` el evento con estado `pendiente` y clave de idempotencia única `uq_notificaciones_clave_idempotencia`.
   - Si la transacción de base de datos se revierte, la notificación se revierte. Si la transacción se confirma, el evento queda registrado de forma durable en MySQL.
2. **Aislamiento Estricto SMTP:**
   - Si el intento inmediato de despacho SMTP falla, la fila permanece registrada como `pendiente` o `reintentando` con su `proximo_intento_at` calculado mediante *backoff* exponencial.
   - **Regla inmutable:** Un fallo en el transporte de correo NUNCA revierte una cita confirmada en la base de datos.
3. **Procesamiento Asíncrono Desacoplado:**
   - El script worker (`scripts/procesar_notificaciones.php`) procesa periódicamente la cola de notificaciones pendientes y reintentos.

---

## 2. Mecanismo de Arrendamiento (*Worker Lease Tokens*) y Prevención de Colisiones

Para permitir que múltiples procesos worker se ejecuten concurrentemente (o se recuperen tras un bloqueo o reinicio abrupto) sin duplicar envíos de correo, la tabla `notificaciones_citas` cuenta con dos columnas añadidas en la migración `008`:
- `worker_token VARCHAR(64) NULL DEFAULT NULL`: Token criptográfico único (`worker_<hex32>`) generado por el worker que adquiere el registro.
- `lease_expires_at DATETIME NULL DEFAULT NULL`: Instante en UTC hasta el cual el worker ostenta la propiedad exclusiva del registro (duración predeterminada: 300 segundos).
- `INDEX idx_notificaciones_lease (estado, lease_expires_at)`.

### Protocolo de Adquisición y Transición de Estados

```
              ┌──────────────────────────┐
              │        PENDIENTE         │
              └─────────────┬────────────┘
                            │
              adquirirParaEnvio(token, 300s)
                            │
                            ▼
              ┌──────────────────────────┐
   ┌─────────►│         ENVIANDO         │◄─────────┐
   │          │ (con worker_token activo)│          │
   │          └─────────────┬────────────┘          │
   │                        │                       │
   │   marcarComoEnviado    │   marcarComoFallido   │
   │   (token verificado)   │   (token verificado)  │
   │                        ▼                       │
   │          ┌──────────────────────────┐          │
   │          │         ENVIADO          │          │
   │          │      (Estado Final)      │          │
   │          └──────────────────────────┘          │
   │                                                │
   │  Recuperación por vencimiento de lease         │
   │  (lease_expires_at < NOW())                    │
   └────────────────────────────────────────────────┘
```

1. **Adquisición Atómica:**
   ```sql
   UPDATE notificaciones_citas
   SET estado = 'enviando',
       worker_token = ?,
       lease_expires_at = NOW() + INTERVAL ? SECOND
   WHERE id = ?
     AND (
       estado IN ('pendiente', 'reintentando')
       OR (estado = 'enviando' AND lease_expires_at < NOW())
     )
   ```
2. **Confirmación con Verificación de Token:**
   ```sql
   UPDATE notificaciones_citas
   SET estado = 'enviado',
       enviado_at = NOW(),
       worker_token = NULL,
       lease_expires_at = NULL
   WHERE id = ? AND worker_token = ?
   ```
   Si el token no coincide (porque el lease expiró y otro worker ya tomó la tarea), la actualización reporta `0` filas afectadas y el worker antiguo es excluido.
3. **Manejo de Reintentos:**
   Si el envío falla, se programa el siguiente intento con backoff exponencial y se libera el lease:
   ```sql
   UPDATE notificaciones_citas
   SET estado = 'reintentando',
       intentos = intentos + 1,
       ultimo_error = ?,
       proximo_intento_at = ?,
       worker_token = NULL,
       lease_expires_at = NULL
   WHERE id = ? AND worker_token = ?
   ```

---

## 3. Descarte de Recordatorios Vencidos y Citas Canceladas

Para evitar despachos anacrónicos o contradictorios que confundan al cliente:

1. **Recordatorios de Citas Pasadas:**
   - Si una notificación de tipo `recordatorio` es evaluada cuando la fecha y hora de la cita ya han transcurrido en la zona horaria oficial `America/Guayaquil`, el sistema **descarta inmediatamente el envío**, marcando `estado = 'descartada'` con el mensaje `Recordatorio descartado por cita ya transcurrida`.
2. **Citas Canceladas:**
   - Si una cita fue cancelada previamente (`citas.estado = 'cancelada'`), cualquier confirmación o recordatorio pendiente asociado a esa cita se descarta con `estado = 'descartada'` y motivo `Cita cancelada previamente`.

---

## 4. Semántica de Entrega: *At-Least-Once* y el Caso de Desconexión Local

El protocolo SMTP no proporciona una garantía de entrega atómica de dos fases (2PC). Es técnicamente imposible garantizar *exactly-once* absoluto cuando intervienen redes públicas y servidores de correo de terceros.

### Escenario de Pérdida de Confirmación Local:
1. El worker envía el comando SMTP `DATA` y el servidor remoto responde `250 OK: Message queued for delivery`.
2. En ese preciso milisegundo, se produce una desconexión de red o caída del proceso worker antes de que MySQL registre `UPDATE notificaciones_citas SET estado = 'enviado'`.
3. Tras expirar el lease (5 minutos), un worker de respaldo adquiere la notificación y la reenvía.

**Garantía de AppSalon:**
- El sistema garantiza entrega **al menos una vez (*at-least-once*)**: ningún correo de confirmación ni cancelación se pierde.
- La duplicación ante fallos catastróficos se minimiza gracias a:
  - Tokens de lease de 300 segundos que evitan carreras en condiciones normales.
  - Trazabilidad en base de datos con la cabecera `Message-ID` y clave de idempotencia `clave_idempotencia` para auditoría en el proveedor de correo.

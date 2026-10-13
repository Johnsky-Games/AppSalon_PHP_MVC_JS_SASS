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

---

## 5. Cola de correos de cuenta (`correos_salida`)

Los correos de **confirmación de cuenta**, de **recuperación de contraseña**, el **aviso de cuenta existente** y las **invitaciones de colaboradores** no se envían de forma síncrona y "a ver si sale": se escriben en una cola persistente (outbox) dentro de la misma transacción que el hecho de negocio, los envía un worker (cada `CORREOS_INTERVALO_SEGUNDOS`, 5 s por defecto) y, si el SMTP falla, los reintenta con espera creciente. **Los correos de rutas públicas (registro, recuperación, reenvío) no se envían dentro de la petición HTTP** (salvo `CORREOS_ENVIO_INMEDIATO=1`): si lo hicieran, el tiempo de respuesta delataría si una cuenta existe. **Excepción documentada:** las invitaciones de colaboradores (ruta autenticada del propietario, sin oráculo de enumeración) y los avisos de cita/reasignación sí intentan el envío tras el `COMMIT` en la propia petición; si falla, quedan en cola para el worker. Un SMTP caído **no hace fallar** el registro, la recuperación ni el reenvío; el correo queda en cola y se reintenta hasta 6 veces (≈31 min acumulados). Pasado ese punto el evento queda `fallido` y **requiere** `--reintentar`: la cola no entrega «para siempre».

### 5.1 Flujo

```
registrar / reenviarConfirmacion
   └─ UNA transacción:  emitir token (reemplaza el hash anterior)  +  INSERT en correos_salida (carga CIFRADA)
                        +  cancelar los eventos pendientes de tokens anteriores del mismo usuario   ── COMMIT
        └─ despacharTrasCommit(): intento inmediato (PHPMailer, Timeout 5 s / Timelimit 10 s)
              ├─ éxito  → estado `enviado`, la carga (con el token) se BORRA
              └─ fallo  → estado `pendiente`, intentos+1, proximo_intento = ahora + espera (tabla 5.3)
                    └─ scripts/procesar_correos.php (cada NOTIFICACIONES_INTERVALO_SEGUNDOS, 60 s por defecto)
```

* `usuarios.token_hash` guarda un único hash vigente: emitir un token nuevo **invalida el anterior** (el enlace viejo da "no válido").
* El token en claro **solo** existe en `carga_cifrada` (XSalsa20-Poly1305, clave derivada de `APP_KEY`). No aparece en la cola, en `ultimo_error` ni en los logs; `ultimo_error` pasa por `Email::sanearMensajeError` (elimina saltos de línea, `token=…`, secuencias de 32+ caracteres y el usuario/contraseña SMTP).
* Antes de enviar una confirmación el worker comprueba que el enlace **siga vigente** (mismo usuario, token vigente y no vencido, cuenta sin confirmar). Si no, el evento pasa a `cancelado` y **no se envía**: cubre cuentas confirmadas por otra vía y la ventana entre un reenvío y la cancelación.
* Un reenvío concurrente del mismo usuario se serializa por el bloqueo de su fila en `usuarios`: queda **un** token válido (el último en confirmarse) y **como máximo un** evento activo (`pendiente`/`enviando`). Los interbloqueos (error 1213) se reintentan hasta 3 veces, como en `Classes\Idempotencia`.
* Con un `$emailDispatcher` inyectado en `AuthService` (pruebas unitarias) el reenvío conserva el comportamiento histórico: síncrono, sin cola ni transacción.

### 5.2 Estados

| Estado | Significado | Siguiente |
|---|---|---|
| `pendiente` | Listo o esperando su `proximo_intento`. | `enviando` al tomarlo un worker |
| `enviando` | Un worker lo tiene con arrendamiento (`worker_token`, `lease_expires_at`, 120 s). | `enviado`, `pendiente` (fallo), `fallido` o `cancelado`; si el worker muere, al vencer el lease otro lo retoma |
| `enviado` | El servidor SMTP aceptó el mensaje. Terminal; la carga se borra. | — |
| `fallido` | Agotó `MAX_INTENTOS = 6` (o la carga es ilegible). **Terminal y visible**; conserva la carga para poder reactivarlo. El procesamiento normal NO lo toca. | `pendiente` solo con `--reintentar` |
| `cancelado` | Obsoleto: enlace reemplazado por un reenvío, cuenta ya confirmada, invitación revocada… La carga se borra. | — |

### 5.3 Tiempos de reintento exactos

Calculados del SQL real de `CorreoSalidaRepository::marcarFallido`: `proximo_intento = UTC_TIMESTAMP() + LEAST(2^n · 30, 3600) s`, con `n` = nº de intentos ya realizados (incluido el que acaba de fallar). El intento nº 1 es la primera pasada del worker (≤ `CORREOS_INTERVALO_SEGUNDOS`) en los correos de rutas públicas, y el inmediato posterior al commit en las invitaciones de colaboradores.

| Fallo nº | Espera hasta el siguiente intento | Instante acumulado desde el primer fallo |
|---|---|---|
| 1 | 60 s | 1 min |
| 2 | 120 s | 3 min |
| 3 | 240 s | 7 min |
| 4 | 480 s | 15 min |
| 5 | 960 s | 31 min |
| 6 | — (`fallido`) | ≈ 31 min |

El worker revisa la cola de correos cada `CORREOS_INTERVALO_SEGUNDOS` (5 s por defecto; los recordatorios de citas siguen en `NOTIFICACIONES_INTERVALO_SEGUNDOS`, 60 s), así que cada intento ocurre en el primer ciclo posterior a su vencimiento. El enlace de confirmación vale 24 h; una caída del SMTP mayor de ~31 min deja los eventos en `fallido` y requiere el reintento manual de 5.5 (si pasaron más de 24 h el worker los cancela y el cliente debe pedir un reenvío). Con el SMTP caído, el worker corta la tanda tras 3 fallos seguidos para no gastar un intento ni 5 s de espera en cada evento; los demás quedan intactos para el siguiente ciclo. `creado_en` y `proximo_intento` se escriben en UTC explícitamente (no dependen de la zona horaria de MySQL).

### 5.4 Diagnóstico

```bash
php scripts/procesar_correos.php --estado          # conteos por estado/tipo, antigüedad, fallidos (sin datos personales)
php scripts/procesar_correos.php --estado --json   # igual, para monitoreo
```

Códigos de salida: `0` correcto · `1` error de ejecución/BD (o `--reintentar=<id>` sin efecto) · `2` uso incorrecto · `3` solo con `--estado`: hay eventos `fallido` (útil para alertas). En modo worker la salida es `0` aunque haya fallos de envío (se reintentan) y escribe `ATENCIÓN: N correo(s) en estado fallido` en STDERR si existen fallidos. Cuando un evento llega a `fallido` también queda una línea `[correos] evento #N … FALLIDO` en el log de errores de PHP (sin correo ni token).

Consultas SQL de apoyo (solo lectura; no muestres `destinatario_email` fuera de quien deba verlo):

```sql
SELECT estado, tipo, COUNT(*) FROM correos_salida GROUP BY estado, tipo;
SELECT id, tipo, estado, intentos, proximo_intento, ultimo_error FROM correos_salida WHERE estado IN ('pendiente','enviando','fallido') ORDER BY id;
```

### 5.5 Reintento manual de eventos `fallido`

```bash
php scripts/procesar_correos.php --reintentar=<id>             # un evento
php scripts/procesar_correos.php --reintentar=todos-fallidos   # todos los fallidos que conservan carga
```

Solo reactiva eventos `fallido` con carga (vuelven a `pendiente`, 0 intentos, vencimiento inmediato); no toca `enviado`, `cancelado` ni `pendiente`. Se enviarán en el siguiente ciclo del worker o ejecutando `php scripts/procesar_correos.php`. Si el enlace ya venció o se reemplazó, el worker lo cancela y el cliente debe usar "Reenviar confirmación".

### 5.6 Comandos por entorno

**Docker (Linux, macOS o Windows con Docker Desktop)** — el servicio `notificaciones` (arranca con el `docker compose up` normal desde la ronda 2) ejecuta `scripts/notificaciones_worker.php`, que lanza `scripts/procesar_notificaciones.php` y `scripts/procesar_correos.php` en cada ciclo:

```bash
docker compose up -d notificaciones                            # arrancar solo el worker
docker compose exec web php scripts/procesar_correos.php --estado
docker compose exec web php scripts/procesar_correos.php --reintentar=todos-fallidos
docker compose exec web php scripts/procesar_correos.php      # procesar la cola ahora
docker compose logs -f notificaciones                          # ver el worker
```

> Antes de la ronda 2 el servicio llevaba `profiles: ["worker"]` y no arrancaba con `docker compose up`; se retiró para que los reintentos de correo no dependan de un paso manual. `--profile worker` sigue siendo válido (se ignora). Si detienes el worker, un correo que falle quedará `pendiente` hasta que lo arranques o ejecutes `procesar_correos.php` a mano.

**Windows PowerShell (con Docker)** — mismos comandos; si el proyecto usa un nombre/`--env-file` propio añade `--env-file includes/.env -p <proyecto>`:

```powershell
docker compose exec web php scripts/procesar_correos.php --estado
docker compose exec web php scripts/procesar_correos.php --reintentar=todos-fallidos
```

**Windows sin Docker** (PHP en el PATH): programa `php scripts\procesar_correos.php` cada minuto con el Programador de tareas:

```powershell
schtasks /Create /SC MINUTE /MO 1 /TN "AppSalon correos" /TR "php C:\ruta\AppSalon\scripts\procesar_correos.php"
php scripts\procesar_correos.php --estado
```

**Linux sin Docker** (cron, cada minuto; varios procesos simultáneos son seguros por el arrendamiento):

```cron
* * * * * cd /var/www/appsalon && php scripts/procesar_correos.php >> storage/logs/correos.log 2>&1
```

### 5.7 Garantía: al menos una vez, no exactamente una vez

* **Garantizado:** un correo de confirmación aceptado por el sistema no se pierde por un SMTP caído, lento o colgado, ni por la muerte de un worker (el lease vence y otro lo retoma). Un solo evento por token (clave única `confirmacion:<usuario>:<huella del token>`), un solo aviso por correo y ventana, y un solo worker por evento a la vez (`UPDATE … WHERE worker_token = ?` en cada transición).
* **No garantizado (límite real):** si el servidor SMTP **aceptó** el mensaje y la conexión se corta antes de que lo sepamos (o el worker muere entre el envío y el `UPDATE … 'enviado'`), el evento se reintenta y el destinatario puede recibir el correo **duplicado**. Ambas copias llevan el mismo enlace vigente. Ocurre también si un envío dura más que el lease (120 s) y otro worker lo retoma. Además, un correo ya en vuelo cuando un reenvío posterior invalida su enlace puede llegar con un enlace que ya no sirve (la cola lo cancela, pero no puede "des-enviar"): el cliente usa el correo más reciente.
* Las rutas públicas no esperan al SMTP (solo encolan), de modo que su tiempo de respuesta no depende de él. Las invitaciones de colaboradores sí intentan el envío en la petición del propietario: con el SMTP colgado esa petición puede tardar hasta ≈10 s (timeouts de PHPMailer); no es un canal de enumeración porque exige sesión de propietario.
* Retención: los eventos `enviado`/`cancelado` conservan solo destinatario, tipo y estado (sin carga). No se purgan automáticamente.

### 5.8 Migración 019

`019_correos_cola_usuario.sql` añade `correos_salida.usuario_id` (informativa, sin FK) e `idx_correo_usuario (usuario_id, tipo)` —deliberadamente sin `estado` para no cruzar bloqueos con el worker— y rellena `usuario_id` de las confirmaciones existentes desde su clave única. Es idempotente. Su rollback solo retira columna e índice: **no borra eventos**. (Recuerda que `migrador.php down` revierte *todas* las migraciones; para volver solo de la 019 ejecuta el archivo `019_correos_cola_usuario_rollback.sql`.)

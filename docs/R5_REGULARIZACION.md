# Citas de días anteriores: reprogramar o regularizar (decisión del usuario)

Contrato de la funcionalidad. Implementación: `classes/ReglaAtencion.php`, `services/RegularizacionAtencionService.php`, `repositories/CitaRepository.php` (`mutarAgenda`, `listarPendientesRevision`), `controllers/AgendaController.php`, vistas `views/agenda/*`, migración **027**.

## 1. Reglas (las cinco decisiones)

1. **No hay inicio ordinario de una cita de un día anterior.** «Iniciar atención» se cierra al terminar el día de la cita (la ventana abre 60 min antes y cierra a las 24:00 del día de la cita, hora del negocio). El servidor responde 422 («La cita era del dd/mm/aaaa: reprográmala, o regulariza la atención si el servicio sí ocurrió») y la pantalla muestra el botón deshabilitado con ese motivo. Si el cliente llega otro día, la gestión **reprograma** con las validaciones habituales (fecha futura, disponibilidad del profesional, duración que cabe en su horario, sin choques); `agenda_eventos` conserva el horario original (`antes_json`), el nuevo (`despues_json` con `desde_pendiente_revision`) y quién/cuándo. Solo la gestión (`agenda.gestionar`) reprograma una cita ya vencida; el cliente no.
2. **«Regularizar atención»** — exclusivamente `agenda.regularizar` (**propietario y gerente**), cuando el servicio ocurrió y faltó registrarlo. Exige **motivo** (10–500 caracteres) y **fecha/hora efectiva** de la atención: nunca futura, ni anterior al día de la cita. Solo citas **confirmadas o en servicio de un día anterior**; la de hoy sigue el flujo ordinario; las completadas, canceladas y con ausencia son históricas (409). La cita pasa a `completada` con comparación-y-cambio atómico. Se registran **por separado**: `citas.atencion_efectiva_at` (cuándo ocurrió el servicio), `citas.regularizada_por` y `citas.regularizada_at` (quién y **cuándo lo hizo**, momento real). `atencion_inicio_*`/`atencion_fin_*` quedan vacíos: no se inventa un actor ni una hora de inicio/fin.
3. **No se modifica silenciosamente la fecha original ni se retrofechan pagos, comprobantes o caja.** `citas.fecha`/`hora` no cambian. El cobro posterior usa su propia fecha real (ver §3). Nada se recalcula hacia atrás.
4. **El profesional solicita, no aprueba.** `agenda.solicitar_regularizacion` (rol profesional): solo para citas propias (una ajena se comporta como inexistente: 404); nunca regulariza ni rechaza. Una sola solicitud abierta por cita. El **cliente** no puede nada de esto (sin permisos de equipo ni portal). Propietario/gerente deciden: aprobar (regularizar, que resuelve la solicitud abierta) o **rechazar con motivo** (la cita sigue pendiente; el profesional puede solicitar de nuevo). Recepción no regulariza ni solicita.
5. **Citas antiguas sin resolver = «pendientes de revisión»**: confirmadas o en servicio con `fecha` < hoy. Se listan en `/agenda/pendientes` (profesional: solo las suyas) y la agenda muestra un aviso con el conteo y una etiqueta en cada tarjeta. **Nunca** se marcan solas como atendidas ni como ausencias: las lecturas no escriben nada. Salidas: reprogramar, regularizar, registrar la ausencia (acción ordinaria, que registra su propia fecha real) o, si estaba «en servicio», finalizarla (con la hora real).

## 2. Permisos

| Acción | Permiso | Quién |
|---|---|---|
| Regularizar / rechazar solicitud | `agenda.regularizar` | propietario, gerente |
| Solicitar regularización | `agenda.solicitar_regularizacion` + cita propia | profesional |
| Reprogramar cita vencida | `agenda.gestionar` | propietario, gerente, recepción |
| Ver pendientes | `agenda.gestionar` (todas) · `agenda.ver_propia` (las suyas) | equipo |

Los permisos se comprueban en el SERVIDOR en cada operación (no en la vista) y con el rol vigente (un cambio de rol en caliente aplica). Rutas: `GET /agenda/pendientes`, `POST /agenda/regularizar`, `POST /agenda/regularizar/solicitar`, `POST /agenda/regularizar/rechazar` (todas con CSRF; las POST con clave de idempotencia `idem_key`).

## 3. Efecto financiero (definido)

| Dato | Fecha que lleva | ¿Cambia por regularizar? |
|---|---|---|
| `citas.fecha` / hora | la original de la reserva | **No** |
| Atención | `atencion_efectiva_at` (cuándo ocurrió) + `regularizada_at` (cuándo se registró) | Se añaden, separadas |
| Pago (`pagos.pagado_at`), movimiento de caja (`caja_movimientos.registrado_en`), comprobante (`emitido_en`) | el momento REAL de cada operación | **Nunca se retrodatan** |
| Comisión (`comisiones_movimientos.registrado_en`) | momento real en que se confirma el dinero | Idem |
| Reportes «por fecha de servicio» | `citas.fecha` original: la cita regularizada figura en su día original | No se mueve |
| Reportes «por fecha de cobro» y caja | fecha real del cobro | Figura el día que se cobró |
| Liquidaciones de comisiones ya cerradas | — | **Protegidas**: la comisión de una cita regularizada nace en la fecha real del cobro, de modo que una liquidación cerrada (`estado='liquidada'`, con su `hasta_fecha`) nunca la absorbe ni cambia; entra en la siguiente liquidación |

## 4. Auditoría

* `citas_regularizaciones`: una fila por solicitud/decisión (`solicitada` → `aprobada` | `rechazada`; regularización directa = fila `aprobada` sin solicitante), con quién y cuándo (hora del negocio) de la solicitud y de la decisión, motivos y fecha efectiva. Índices únicos: una solicitud abierta y una aprobación por cita. `CHECK`: la fecha efectiva no es posterior al momento de la decisión.
* `agenda_eventos`: `regularizar`, `regularizacion_solicitud`, `regularizacion_rechazo`, `reprogramar` (con `desde_pendiente_revision`); visibles en el historial de la cita.
* La migración 027 es reversible con guarda: se **niega** si hay regularizaciones (evidencia de negocio).

## 5. Concurrencia

Cada operación bloquea la cita (`FOR UPDATE`) y cambia el estado con comparación-y-cambio. Dos regularizaciones simultáneas (claves distintas): una gana, la otra recibe 409. Regularizar contra registrar ausencia, finalizar o reprogramar simultáneos: gana una; la perdedora recibe 409 y no escribe. Doble envío con la misma clave: un solo efecto.

## 6. Límites conocidos

* Reprogramar una cita vencida exige una fecha futura (regla habitual de reprogramación); si el cliente llega hoy, se regulariza o se registra como nueva reserva.
* Las citas pendientes de revisión se muestran como lista de tarjetas (no tabular), sin exportación.
* Recepción puede reprogramar y registrar ausencias pero no solicita regularizaciones.

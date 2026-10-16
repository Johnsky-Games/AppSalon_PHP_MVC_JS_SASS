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

## 3. Las tres fechas y su efecto (definido)

Una atención regularizada tiene **tres fechas distintas** y cada informe usa la que le corresponde. Ninguna se presenta como otra: la fecha programada **no** se etiqueta como fecha real del servicio.

| Fecha | Qué es | Dónde se guarda | La usan |
|---|---|---|---|
| **Programada (original)** | La de la reserva en agenda. Nunca se modifica al regularizar | `citas.fecha` / `hora` | Reportes «Fecha programada» (programación, ocupación, reservas), pantalla `/caja` y su exportación (lista de citas por cobrar) |
| **Efectiva de atención** | Cuándo ocurrió realmente el servicio, según la regularización (nunca futura) | `citas.atencion_efectiva_at` | Reportes «Fecha del servicio realizado» (solo citas atendidas; sin regularización vale la programada, porque una atención ordinaria ocurre el día de su cita) |
| **Real del movimiento** | Cuándo se cobró, devolvió o liquidó | `pagos.pagado_at`, `caja_movimientos.registrado_en`, `comprobantes.emitido_en`, `comisiones_movimientos.registrado_en` | Reportes «Fecha del cobro o la devolución», Finanzas (caja por fecha real), comprobantes y comisiones |

Por tanto: pagos, comprobantes, caja y comisiones **nunca se retrodatan** (llevan el momento real de cada operación), y regularizar no recalcula nada hacia atrás.

**Reportes (`/admin/reportes`, criterio de fecha):** `programacion` (predeterminado), `realizado` y `cobro`. El valor histórico `servicio` se sigue aceptando como alias de `programacion` (era la fecha de la cita) pero ya no se llama «fecha de servicio». Los listados y exportaciones traen columnas separadas «Fecha programada» y «Fecha efectiva de atención», y los movimientos financieros traen «Fecha programada de la cita» y «Fecha efectiva de atención» junto a la fecha del cobro/devolución.

**Liquidaciones de comisiones ya cerradas — protegidas:** la comisión de una cita regularizada nace en la fecha real del cobro, así que una liquidación cerrada (`estado='liquidada'`, con su `hasta_fecha`) nunca la absorbe ni cambia; entra en la siguiente.

**`/caja`:** se mantiene **por fecha programada de la cita** (contrato de esta entrega; la pantalla lo dice con una etiqueta). El efectivo del día real (cobros y devoluciones) se concilia en **Finanzas** (`caja_movimientos.registrado_en`). *Mejora pendiente:* un filtro «por fecha de cobro» en `/caja` y su exportación; requiere separar los importes cobrados/devueltos dentro del rango de los totales por cita y no se hizo en esta entrega.

## 4. Auditoría

* `citas_regularizaciones`: una fila por solicitud/decisión (`solicitada` → `aprobada` | `rechazada`; regularización directa = fila `aprobada` sin solicitante), con quién y cuándo (hora del negocio) de la solicitud y de la decisión, motivos y fecha efectiva. Índices únicos: una solicitud abierta y una aprobación por cita. `CHECK`: la fecha efectiva no es posterior al momento de la decisión.
* `agenda_eventos`: `regularizar`, `regularizacion_solicitud`, `regularizacion_rechazo`, `reprogramar` (con `desde_pendiente_revision`); visibles en el historial de la cita.
* La migración 027 es reversible con guarda: se **niega** si hay regularizaciones (evidencia de negocio).

## 5. Concurrencia

Cada operación bloquea la cita (`FOR UPDATE`) y cambia el estado con comparación-y-cambio. Dos regularizaciones simultáneas (claves distintas): una gana, la otra recibe 409. Regularizar contra registrar ausencia, finalizar o reprogramar simultáneos: gana una; la perdedora recibe 409 y no escribe. Doble envío con la misma clave: un solo efecto.

## 6. Límites conocidos

* Reprogramar una cita vencida exige una fecha futura (regla habitual de reprogramación); si el cliente llega hoy, se regulariza o se registra como nueva reserva.
* Las citas pendientes de revisión se muestran como lista de tarjetas (no tabular), sin exportación.
* `/caja` filtra por la fecha programada de la cita; el filtro «por fecha de cobro» es una **mejora pendiente** (el efectivo del día real se ve en Finanzas).
* Recepción puede reprogramar y registrar ausencias pero no solicita regularizaciones.

## 7. Integridad referencial y filas huérfanas

**Qué se observó.** En una base de pruebas reutilizada aparecieron filas de `citas_regularizaciones`, `cobros_eventos` y `comprobantes` cuyo `cita_id` apuntaba a citas que ya no existían (y cuyo id volvió a asignarse a otra cita de otra prueba). Causa: una prueba heredada (`Fase5InterfazReservasIntegrationTest`) hace `TRUNCATE` de `citas` con `FOREIGN_KEY_CHECKS=0`; con las claves foráneas desactivadas nada se propaga y el contador de `AUTO_INCREMENT` vuelve a 1.

**¿Puede pasar en producción?**

| Tabla | ¿Puede quedar con una cita inexistente? | Por qué |
|---|---|---|
| `citas_regularizaciones` | **No.** | `cita_id` tiene clave foránea `ON DELETE RESTRICT`: la base rechaza (1451) eliminar una cita con solicitudes o decisiones, y la aplicación responde 409 antes («tiene una regularización… no se puede eliminar para conservar su auditoría»). Solo podría ocurrir si alguien desactiva las claves foráneas a mano (como hace el arnés de pruebas). |
| `agenda_eventos` | No | `ON DELETE CASCADE` (diseño anterior: el historial de agenda se va con la cita; una cita regularizada no se puede eliminar, así que su historial se conserva) |
| `pagos` (`cita_id`) | No | `ON DELETE SET NULL` |
| `comprobantes.cita_id`, `cobros_eventos.cita_id`, `pagos.cita_referencia_id` | **Sí, por diseño** | Son evidencia contable **sin clave foránea** a propósito (los comprobantes son de solo anexar y guardan una instantánea con SHA-256; los reportes tienen un camino explícito «cita eliminada físicamente»). Si el propietario elimina una cita que ya tenía pagos, esas referencias quedan apuntando a una cita inexistente. Los ids de `citas` no se reutilizan en MySQL 8, así que el vínculo no se confunde con otra cita. |

Lo último **no es un artefacto del arnés**: es el comportamiento vigente de eliminar citas con movimientos. *Decisión pendiente (no tomada):* impedir (409) la eliminación de citas con pagos o comprobantes, o pasar a baja lógica. Hasta decidirlo, la comprobación de solo lectura de abajo permite medirlo en tu instalación.

**La limpieza de pruebas no oculta nada.** Las pruebas de regularización borran *sus propias* filas (hijos primero) en `tearDown`; la prueba heredada que vacía `citas` con claves foráneas desactivadas ahora también borra `citas_regularizaciones` (igual que ya borraba sus otras tablas huérfanas). Y hay una prueba que demuestra la garantía real: eliminar por la aplicación → 409, `DELETE` directo → error 1451, y ninguna fila huérfana.

**Comprobación de solo lectura para tu instalación** (no modifica nada):

```sql
SELECT 'citas_regularizaciones' t, COUNT(*) n FROM citas_regularizaciones x LEFT JOIN citas c ON c.id=x.cita_id WHERE c.id IS NULL
UNION ALL SELECT 'agenda_eventos', COUNT(*) FROM agenda_eventos x LEFT JOIN citas c ON c.id=x.citaId WHERE c.id IS NULL
UNION ALL SELECT 'comprobantes (referencia a cita eliminada)', COUNT(*) FROM comprobantes x LEFT JOIN citas c ON c.id=x.cita_id WHERE c.id IS NULL
UNION ALL SELECT 'cobros_eventos (referencia a cita eliminada)', COUNT(*) FROM cobros_eventos x LEFT JOIN citas c ON c.id=x.cita_id WHERE x.cita_id IS NOT NULL AND c.id IS NULL
UNION ALL SELECT 'pagos.cita_referencia_id (cita eliminada)', COUNT(*) FROM pagos x LEFT JOIN citas c ON c.id=x.cita_referencia_id WHERE x.cita_referencia_id IS NOT NULL AND c.id IS NULL;
```

Las dos primeras filas deben dar 0. Las otras tres pueden ser mayores que 0 solo si en esa instalación se eliminaron citas con movimientos.

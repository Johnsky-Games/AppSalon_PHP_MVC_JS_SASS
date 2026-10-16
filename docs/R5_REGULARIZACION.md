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

Lo último **no es un artefacto del arnés**: era el comportamiento de eliminar citas con movimientos. **Corregido tras la reverificación independiente:** `CitaService::eliminar` responde 409 si la cita tiene pagos (`cita_id` o `cita_referencia_id`), devoluciones, comprobantes o eventos de cobro («cancélala o gestiona su devolución»), con prueba (`R5bQaFase2Test::test_E_eliminar_cita_con_cobros_criterio_QA`). Importante para dimensionar: **ninguna ruta HTTP elimina físicamente una cita** (`/api/eliminar` hace una cancelación lógica); `eliminar` solo la usa código de dominio y pruebas, así que esta regla y la FK `RESTRICT` son defensa en profundidad. Las instalaciones que ya eliminaron citas con cobros antes de esta versión pueden tener referencias huérfanas: la consulta de abajo las mide.

**Limitaciones que siguen (anotadas, no resueltas):**
- `citas.regularizada_por`, `citas_regularizaciones.*_por` y `agenda_eventos.actorId` usan `ON DELETE SET NULL`: si se suprime a un usuario, la auditoría conserva el hecho pero pierde «quién». Mejora pendiente: guardar una instantánea del nombre en el momento del evento.
- `citas.usuarioId` no tiene clave foránea (anterior a esta ronda).
- «Los ids de cita no se reutilizan» vale mientras no haya `TRUNCATE` ni restauración de un respaldo anterior.

**La limpieza de pruebas no oculta nada.** Las pruebas de regularización borran *sus propias* filas (hijos primero) en `tearDown`; la prueba heredada que vacía `citas` con claves foráneas desactivadas ahora también borra `citas_regularizaciones` (igual que ya borraba sus otras tablas huérfanas). Y hay una prueba que demuestra la garantía real: eliminar por la aplicación → 409, `DELETE` directo → error 1451, y ninguna fila huérfana.

### 7.1 Todas las vías de eliminación revisadas (citas y usuarios)

Revisión de `controllers/`, `services/`, `repositories/`, `models/`, `scripts/` y `public/index.php` (búsqueda de `DELETE`, `TRUNCATE`, `DROP`, y de todo método `eliminar/delete/borrar/remove`). La guarda de `CitaService` **no** es lo único que protege: se reforzó donde hacía falta y una prueba de arquitectura vigila el resto.

| Vía | Qué hace | Estado |
|---|---|---|
| `POST /api/eliminar` (`APIController::eliminar`) | Llama a `cancelar()`: cancelación lógica, conserva cita, servicios y pagos | Sin riesgo (no borra) |
| `CitaService::eliminar` | Eliminación física; solo la usan el dominio y las pruebas, **ninguna ruta HTTP** | 409 con regularizaciones, pagos, devoluciones, comprobantes o eventos de cobro; mensaje con la alternativa (cancelar, ausencia, devolución auditada) |
| `CitaRepository::eliminarCitaAtomica` | Único `DELETE FROM citas` de la aplicación | **Dos defectos hallados y corregidos en esta revisión.** (1) La comprobación vivía solo en el servicio, *antes* de la transacción: un cobro confirmado entre ambas habría dejado evidencia sin cita. Ahora la guarda está **dentro de la transacción con la cita bloqueada** y lanza `CitaProtegidaException`; la lectura previa se hace *fuera* de la transacción para que la instantánea no se fije antes del candado (la primera versión de la guarda leía una instantánea vieja y no veía el cobro: lo detectó la propia prueba de la carrera). (2) **Interbloqueo** (hallado por el QA independiente: 7 de 15 rondas, una con HTTP 500): bloqueaba cita→profesional mientras `mutarAgenda` bloquea profesional→cita. Ahora el orden es profesional→cita, con reintento acotado si la cita cambió de profesional entre la lectura y el candado; la prueba de 15 rondas contra cambios de estado da 0 interbloqueos |
| `Model\ActiveRecord::eliminar` (heredado, genérico) | `DELETE FROM {tabla} WHERE id=?` | Hoy **ningún modelo lo extiende ni se llama**; aun así rechaza las tablas con historial (`citas`, `usuarios`, `clientes`, `pagos`, `comprobantes`, `cobros_*`, `caja_movimientos`, `comisiones_movimientos`, `agenda_eventos`, `citas_regularizaciones`, `equipo_auditoria`) por si un modelo futuro lo hereda |
| Resto del código | Prueba de arquitectura `testNingunCodigoDeLaAplicacionBorraCitasUsuariosOEvidencia`: ningún `DELETE`/`TRUNCATE` sobre esas tablas fuera de `CitaRepository` | Falla si alguien añade uno |
| `scripts/restore_db.php` | Restaura un respaldo completo (operación del operador, con respaldo y SHA-256) | Fuera del flujo de la aplicación; reemplaza datos por diseño |
| `POST /servicios/eliminar` | Eliminación física de un **servicio** del catálogo (`DELETE FROM servicios`); `citasservicios` no tiene FK hacia `servicios` | **Observación fuera del alcance pedido (citas y usuarios), sin cambios:** las citas históricas conservan el `servicioId` y su instantánea de precio, pero apuntarían a un servicio inexistente. Pendiente de decisión (baja lógica del servicio) |

**Qué protege una cita (motivos del 409).** `regularizacion` (cualquier solicitud o decisión), `cobros` (pagos por `cita_id` o `cita_referencia_id`, devoluciones, comprobantes, eventos de cobro) y `auditoria` (historial de agenda —inicio, finalización, ausencia, reprogramación—, reasignaciones y puntos de fidelidad de la cita). Las claves foráneas reales hacia `citas` son: `citas_regularizaciones` RESTRICT; `agenda_eventos` CASCADE (se perdería la auditoría); `citas_reasignaciones`, `fidelidad_movimientos`, `notificaciones_citas` y `pagos.cita_id` SET NULL (quedarían sin cita). Las **notificaciones** no bloquean la eliminación (son operativas, no evidencia); `caja_movimientos` y `comisiones_movimientos` no se comprueban directamente porque cuelgan de un pago: se asume que todo movimiento nace de un pago, comprobante o evento de cobro de la misma cita (el QA no encontró un escritor que los cree de otro modo).

**Usuarios.** No existe ninguna ruta, servicio ni script que elimine filas de `usuarios`. La identidad histórica se conserva **desactivando**: `/admin/equipo/actualizar` (`usuario_accesos.activo`, estado laboral `activo|suspendido|baja`, con `equipo_auditoria`; el último propietario no puede desactivarse). Lo que ocurriría si alguien borrara un usuario **a mano en la base**:

| Dependencia (claves foráneas reales hacia `usuarios`, leídas de `information_schema`) | Efecto del borrado directo |
|---|---|
| `clientes`, `perfiles_usuario`, `perfil_fotos`, `usuario_accesos`, `consentimientos_historial`, `fidelidad_movimientos`, `formulario_respuestas`, `lista_espera` | `ON DELETE CASCADE`: se pierden |
| Actores y autores: `agenda_eventos.actorId`, `caja_movimientos.actor_id`, `cobros_devoluciones.actor_id`, `comisiones_config/liquidaciones.actor_id`, `inventario_movimientos.actor_id`, `citas_reasignaciones.actor_id`, `citas.atencion_inicio_por/atencion_fin_por/ausencia_por/regularizada_por`, `citas_regularizaciones.solicitada_por/resuelta_por`, `pagos.registrado_por/verificado_por` | `ON DELETE SET NULL`: el hecho se conserva pero **se pierde quién** |
| `pagos.usuario_id`, `notificaciones_citas.usuario_id`, `colaboradores.usuario_id`, `profesionales.usuarioId` | `ON DELETE SET NULL`: el pago/notificación/ficha queda sin dueño (la base **no** lo rechaza; corrección de una afirmación anterior de este documento) |
| `citas.usuarioId` | **Sin clave foránea** (anterior a esta ronda): las citas quedarían apuntando a un usuario inexistente |

No se debilitó ninguna clave foránea ni se añadió una limpieza para resolverlo. **Pendiente de decisión (no implementado):** (a) política de **anonimización** de usuarios que piden su baja (sustituir datos personales por un marcador conservando el id y la auditoría), (b) guardar una instantánea del nombre del actor en cada evento de auditoría, (c) una restricción en base de datos (migración nueva) que impida borrar usuarios con historial. Mientras tanto, la regla operativa es: **desactivar, nunca eliminar**.

### 7.2 Referencias huérfanas históricas: cómo detectarlas (solo lectura)

Pueden existir en instalaciones que, antes de esta versión, eliminaron citas con cobros o usuarios directamente en la base. **No se borran ni se reconstruyen** (no se inventan citas ni usuarios): se informan al operador para que decida con su contabilidad. La consulta no modifica nada:

```sql
SELECT 'citas_regularizaciones sin cita' t, COUNT(*) n FROM citas_regularizaciones x LEFT JOIN citas c ON c.id=x.cita_id WHERE c.id IS NULL
UNION ALL SELECT 'agenda_eventos sin cita', COUNT(*) FROM agenda_eventos x LEFT JOIN citas c ON c.id=x.citaId WHERE c.id IS NULL
UNION ALL SELECT 'citasservicios sin cita', COUNT(*) FROM citasservicios x LEFT JOIN citas c ON c.id=x.citaId WHERE c.id IS NULL
UNION ALL SELECT 'comprobantes de cita inexistente', COUNT(*) FROM comprobantes x LEFT JOIN citas c ON c.id=x.cita_id WHERE c.id IS NULL
UNION ALL SELECT 'cobros_eventos de cita inexistente', COUNT(*) FROM cobros_eventos x LEFT JOIN citas c ON c.id=x.cita_id WHERE x.cita_id IS NOT NULL AND c.id IS NULL
UNION ALL SELECT 'cobros_devoluciones de cita inexistente', COUNT(*) FROM cobros_devoluciones x LEFT JOIN citas c ON c.id=x.cita_id WHERE x.cita_id IS NOT NULL AND c.id IS NULL
UNION ALL SELECT 'pagos.cita_referencia_id de cita inexistente', COUNT(*) FROM pagos x LEFT JOIN citas c ON c.id=x.cita_referencia_id WHERE x.cita_referencia_id IS NOT NULL AND c.id IS NULL
UNION ALL SELECT 'citas_reasignaciones de cita inexistente', COUNT(*) FROM citas_reasignaciones x LEFT JOIN citas c ON c.id=x.cita_id WHERE x.cita_id IS NOT NULL AND c.id IS NULL
UNION ALL SELECT 'notificaciones_citas de cita inexistente', COUNT(*) FROM notificaciones_citas x LEFT JOIN citas c ON c.id=x.cita_id WHERE x.cita_id IS NOT NULL AND c.id IS NULL
UNION ALL SELECT 'citas de usuario inexistente', COUNT(*) FROM citas x LEFT JOIN usuarios u ON u.id=x.usuarioId WHERE x.usuarioId IS NOT NULL AND u.id IS NULL
UNION ALL SELECT 'citasservicios de servicio inexistente', COUNT(*) FROM citasservicios x LEFT JOIN servicios s ON s.id=x.servicioId WHERE s.id IS NULL;
```

Las dos primeras filas deben dar 0 en cualquier instalación íntegra. (Con `SET NULL`, una cita eliminada deja la referencia en NULL y no es detectable en `fidelidad_movimientos`, `pagos.cita_id`: solo las columnas sin FK o con la FK desactivada delatan huérfanas.) Las demás, mayores que 0, indican eliminaciones hechas fuera de la aplicación o antes de esta versión; para ver el detalle, cambia `COUNT(*)` por las columnas de `x`. Las citas eliminadas conservan su id (MySQL no lo reutiliza salvo `TRUNCATE` o restauración de un respaldo anterior), de modo que cada referencia puede relacionarse con su comprobante, que guarda una instantánea con SHA-256.

**En bases de pruebas reutilizadas durante muchas corridas** (no en una recién creada: el QA obtuvo 0 en la suya) esta consulta puede devolver cifras mayores que 0 en comprobantes, eventos de cobro y devoluciones: son residuo de pruebas cuya limpieza borra citas directamente en la base (sin pasar por la aplicación, que desde esta revisión lo rechaza). No son un hallazgo del producto, pero tampoco se esconden: la consulta las muestra tal cual y las pruebas nuevas de eliminación limpian sus propios comprobantes, pagos y eventos antes de salir.

### 7.3 Cobertura limitada de las pruebas de eliminación (honesto)

Mutaciones (romper el código a propósito y comprobar que alguna prueba falla), hechas por el integrador y el QA independiente:

| Mutación | ¿Detectada? |
|---|---|
| Quitar la guarda dentro de la transacción | **Sí** (carrera con un proceso real; la espera es determinista —se observa `LOCK WAIT` en `innodb_trx`—, no un `sleep`) |
| Quitar el `FOR UPDATE` de la cita | **Sí** (la eliminación no queda esperando el candado) |
| Quitar la comprobación de cada tipo de evidencia (pagos por `cita_id`, `cita_referencia_id`, comprobantes, eventos de cobro, devoluciones, regularizaciones) | **Sí**, con el conjunto de datos aislado del QA (`R5bQaFase3Test`: una sola fila de evidencia por tipo). El conjunto del integrador no discrimina por tabla (los *triggers* de solo anexar impiden retirar la otra evidencia): **no se cuenta como cobertura** |
| Quitar una tabla de `TABLAS_PROTEGIDAS` | **Sí**, la lista obligatoria se comprueba por reflexión; el borrado genérico no tiene llamadores, así que la prueba protege el contrato, no un camino vivo |
| Quitar la comprobación de `agenda_eventos`/reasignaciones/fidelidad (motivo `auditoria`) | Solo `agenda_eventos` tiene prueba propia; reasignaciones y fidelidad **sin prueba específica** |
| La prueba de arquitectura | Solo ve PHP con nombres de tabla literales fuera de `vendor`, `tests`, `node_modules` y migraciones: no detecta SQL dinámico, `.sh`, `DROP`/`REPLACE` ni SQL fuera de PHP |
| Rondas de regularizar contra cobrar y contra rechazar | **Cobertura limitada**: pasan aun sin las protecciones que se quitaron; no se citan como evidencia de esos bloqueos |
| Aislamiento por orden aleatorio | Persiste un patrón latente (`setDB` + `close` sin restaurar) en `AgendaOperativaIntegrationTest`, `R5AtencionCitaIntegrationTest` y `RevocacionApiIntegrationTest`; el QA no observó fallos (ni con la semilla que dio 89 errores antes del arreglo). Riesgo de orden, no defecto observado |

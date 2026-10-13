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

## Servicios y horario dentro del asistente del profesional (migración 021 no interviene; usa tablas 003/012)

* **Dónde.** Formulario de invitación (`/admin/equipo`, rol Profesional) y ficha laboral (`/admin/equipo/ficha?id=`, sección «Servicios y horario en la agenda», `POST /admin/equipo/ficha/agenda`). Partial compartido `views/equipo/_agenda_asistente.php`; JS en `src/js/ui.js` (añadir/quitar turnos, copiar el lunes a martes–viernes); sin JavaScript el formulario sigue siendo válido.
* **Contrato de entrada.** `servicios[]`, `franjas[dia 1..7][n][inicio|fin]`, `descansos[dia][n][inicio|fin|motivo]`. Una fila vacía se ignora; una fila a medias es un error.
* **Validación servidor** (`Services\AgendaSemanalValidador`, pura): horas HH:MM entre 00:00 y 23:59 (límite del módulo de agenda y del `<input type=time>`; **24:00 no se admite**), minutos múltiplos de 5 (el fin puede ser 23:59), franja ≥ 15 min, descanso ≥ 5 min, máx. 6 franjas y 4 descansos por día, sin solapes (turnos contiguos sí), cada descanso dentro de una franja y sin cubrirla entera, sin descansos sin franja. Servicios: existentes en el catálogo (`servicios` no tiene marca de activo; se verifican con `LOCK IN SHARE MODE`).
* **Atomicidad.** `ProfesionalRepository::reemplazarConfiguracionAgenda()` es anidable (`Classes\Transaccion`): bloquea la ficha `FOR UPDATE`, sincroniza servicios por diferencia, reemplaza horario y descansos. No retira un servicio que una cita futura confirmada/en servicio necesita, ni deja fuera o choca con citas futuras (mismas reglas que `replaceHorarios`/`createDescanso`). `replaceHorarios` pasó a usar `Transaccion` (mismo comportamiento) para poder componerse.
* **Idempotencia y permisos.** Invitación: `colaborador.invitar` (incluye la agenda en la huella). Ficha: `colaborador.agenda`. Exige CSRF, clave de operación y `rh.gestionar` + `profesionales.gestionar` (comprobados en la transacción; hoy solo el propietario los reúne). Misma clave con otro contenido → conflicto.
* **Invitado ≠ reservable.** La ficha nueva nace con `activo=0`; disponibilidad (`DisponibilidadService`), catálogo de reserva y `crearReservaConProfesionalAtomica` exigen `activo=1`. Al aceptar la invitación se activa con la configuración ya guardada. Revocar la invitación conserva la configuración en la ficha (queda sin colaborador, reutilizable). Fichas heredadas (`colaborador_id` NULL): al vincularlas no se toca su configuración salvo la casilla «reemplazar sus servicios y su horario».

## Revisión y reasignación asistida de citas futuras (baja/suspensión)

* **Pantalla.** `/admin/equipo/reasignar?id=<colaborador>` (acceso: `agenda.gestionar`; el botón aparece en «Personas del equipo»). Lista las citas que ocupan agenda (`confirmada`, `en_servicio`) desde hoy (America/Guayaquil): fecha, hora, cliente, servicios, estado y pago. **Privacidad:** el nombre del cliente solo se muestra con `clientes.ver`; sin él, «Datos reservados» (`ReasignacionCitasService::proyectarCita`). Los roles sin `agenda.gestionar` (profesional, finanzas, cliente) reciben 403 y ningún dato.
* **Sustitutos propuestos.** Por cada cita, los profesionales activos que prestan **todos** sus servicios y para los que `DisponibilidadService::consultar()` (paso 1 min) ofrece un intervalo con el mismo inicio y la misma duración: es la validación real de horario, descansos, bloqueos y ocupación, no otra lógica.
* **Decisiones por cita:** reasignar a un sustituto, cancelar (flujo existente `CitaService::cancelarCita`, actor `admin`, con su aviso `cancelacion` y su historial) o dejar para revisión manual. La baja se bloquea mientras haya citas sin resolver: `ColaboradorService::cambiarAcceso` lo sigue verificando en el servidor (un `FOR UPDATE` sobre la ficha serializa con reservas concurrentes). Una cita `en_servicio` o ya iniciada no se reasigna ni se cancela: se resuelve en la agenda (atendida / no asistió).
* **Reasignación atómica y concurrente-segura** (`ReasignacionCitasRepository::reasignarEnTransaccion`): bloquea origen y destino `FOR UPDATE` en orden ascendente de id (como cualquier reserva), la cita, y revalida el hueco con `CitaRepository::validarHuecoEnTransaccion` (reutiliza `cabeEnAgendaDelDia` + ocupación `FOR UPDATE` de la reserva real, aislamiento READ COMMITTED). El lote se aplica **todo o nada** dentro de `Idempotencia::ejecutar('colaborador.reasignar')`; dos reasignaciones o una reserva simultánea sobre el mismo hueco se serializan y quien pierde recibe «el horario ya está ocupado». Reenviar el mismo formulario devuelve el resultado original.
* **Historial.** Tabla `citas_reasignaciones` (migración 021): cita, acción (`reasignada`/`cancelada`), profesional origen y destino (id y nombre), actor, motivo, snapshot del horario/servicios/estado de pago y fecha. Además `agenda_eventos` (tipo `reasignacion`) y `equipo_auditoria`. **Solo cambia `citas.profesionalId`**: los snapshots de `citasservicios` y los pagos no se tocan. Los avisos en cola de esa cita (`recordatorio`/`confirmacion` pendientes) pasan a nombrar al profesional nuevo.
* **Notificación.** En la misma transacción se escribe un evento `confirmacion` en `notificaciones_citas` (clave `cita:<id>:reasignacion:<id_reasignacion>`, asunto «Cambio de profesional…») y, tras confirmar, se envía de inmediato por `Email::despacharNotificacionCita` con arrendamiento de worker; si falla lo reintenta el worker. Un aviso por reasignación.
* **Comisiones.** La comisión se devenga al cobrar, con el profesional que tiene la cita en ese momento (`FinanzasService::registrar`). Una cita aún sin devengo que se reasigna **sigue al destino** (su cobro posterior devenga para el destino con su porcentaje). Si el pago de la cita **ya devengó** (movimiento `devengo` pendiente o liquidado) la reasignación se **bloquea** (`comision_devengada`): hay que cancelarla o gestionarla con finanzas; no se reescriben movimientos de comisión.
* **Rollback seguro de 021.** Si `citas_reasignaciones` tiene filas se renombra a `citas_reasignaciones_respaldo_021` (y se quitan sus claves foráneas); si ya existe ese respaldo el rollback falla a propósito sin perder datos.

## Aviso de posibles homónimos al dar de alta un profesional

Aplica a `/profesionales/crear` y a la **ficha nueva** del asistente de invitación (vincular una ficha heredada existente no crea ficha y no lo dispara). Reutiliza `Services\HomonimosService` (normalización, orden invertido, parecido) tal cual; una ficha solo tiene nombre, así que se compara el nombre completo (sin criterio de teléfono). Mismas garantías que el alta de clientes del CRM:

* **Nunca fusiona ni bloquea.** Con coincidencias la ficha **no se crea** hasta que el operador pulsa «Es una persona distinta: crear ficha»; también puede «Cancelar y usar la ficha existente».
* **Token no forjable.** HMAC (`Secretos::clave('homonimos-confirmacion')`, prefijo propio `homonimos-profesional`) ligado al operador, a la clave de operación del formulario y al conjunto exacto de ids; el servidor recalcula las coincidencias al recibir la confirmación. El aviso se vuelve a mostrar con la **misma** clave (el token depende de ella). La antigua casilla `confirmar_homonimo` ya no confirma nada.
* **Auditoría.** `profesionales_homonimos_decisiones` (migración 021, solo anexado con disparadores): «continuó» se escribe en la **misma transacción** que la ficha (y la invitación en el asistente); «canceló» al cancelar. Único por (operador, clave, decisión): el doble clic no duplica.
* **Idempotencia.** Un reintento de un alta ya confirmada devuelve el resultado original (`Idempotencia::buscar`) sin repetir el aviso ni la decisión.
* **PII mínima.** El aviso muestra nombre, si la ficha está activa y si está vinculada a una cuenta; nunca correos ni teléfonos. El enlace a la ficha solo aparece con `profesionales.gestionar`.
* Piezas: `HomonimosProfesionalesService`, `ProfesionalHomonimosRepository`, `ProfesionalService::crear(..., $homonimos)`, `ColaboradorService::invitar(..., $ipHash)`, `views/equipo/_aviso_homonimos_profesional.php`.

## Pruebas

`tests/Integration/AgendaOperativaIntegrationTest.php` cubre dueño/ajeno, alcance profesional, solapamiento, encaje de turno, snapshots y pago, transiciones válidas/terminales/idempotencia, historial cancelado, hora pasada, fallo SQL de auditoría con rollback y outbox durable. Ejecutar mediante la suite PHPUnit del repositorio en MySQL real tras migraciones 010 y 012.

También: `tests/Integration/AsistenteProfesionalIntegrationTest.php` (asistente de servicios y horario) y `tests/Integration/ReasignacionCitasIntegrationTest.php` (reasignación, concurrencia con procesos reales, atomicidad, comisiones, privacidad y rollback de 021; worker `tests/fixtures/concurrent_reasignacion_worker.php`).
`tests/Integration/HomonimosProfesionalesIntegrationTest.php` cubre el aviso de homónimos de profesionales (token, auditoría, atomicidad, HTTP y procesos concurrentes).

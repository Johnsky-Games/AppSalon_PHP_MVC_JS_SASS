# Matriz de destinos por portal (ronda 5)

Un mismo usuario puede tener membresía de cliente y laboral, pero **el portal de la sesión decide** qué ve y a dónde vuelve. Los destinos salen de un único catálogo (`includes/destinos.php`: `destino_portal`, `campos_retorno`, `destino_volver`, `responder_error_operacion`); no se usa el `Referer` ni parámetros de retorno libres. Los parámetros de retorno (`volver` + `r_*`) se tipan, se revalidan contra el catálogo, el portal y los permisos **actuales** y, si algo no encaja, se degrada al inicio del portal.

## 1. Inicio y navegación

| Portal | Inicio (`inicio_portal()`) | Navegación |
|---|---|---|
| Equipo | profesional → `/agenda`; finanzas → `/admin/finanzas`; resto → `/admin` | `views/templates/nav_equipo.php`, filtrada por permisos del servidor |
| Cliente | `/cita` | `views/templates/nav_cliente.php` (reservar, mis citas, mi bienestar, perfil) |

## 2. Catálogo (clave → ruta, portal, permiso)

Equipo: `agenda` `/agenda`, `agenda_cita` `/agenda/cita` (agenda.gestionar o agenda.ver_propia) · `admin_citas` `/admin` · `caja` `/caja`, `caja_cita` `/caja/cita`, `caja_comprobante` `/caja/comprobante`, `finanzas` `/admin/finanzas` (finanzas.gestionar) · `clientes_nuevo` · `servicios`, `servicio_nuevo` · `profesionales`, `profesional_horarios` · `equipo`, `equipo_ficha`, `equipo_reasignar` · `inventario` · `reportes` · `crecimiento` · `ajustes` · `inicio_equipo`.
Cliente: `cliente_reservar` `/cita` · `cliente_citas` `/mis-citas` · `cliente_bienestar` `/mi-bienestar`.
Compartido: `perfil` (ambos portales). Públicos: `login_cliente` `/`, `login_equipo` `/equipo/login`.
Una clave de otro portal, desconocida o sin permiso devuelve `''` y el llamador usa el inicio de su portal.

## 3. Guardias de portal

Pantallas y API **personales del cliente** (`GET /cita`, `/mis-citas`, `/mi-bienestar`, `/api/mis-citas`, `/api/disponibilidad`, `/api/profesionales`; `POST /mi-bienestar`, `/api/citas`):

| Sesión | Respuesta |
|---|---|
| Sin sesión / sesión caducada / invalidada | La autenticación propia de la acción (login del portal; JSON 401 con el cuerpo de su contrato). La guardia no responde en su lugar. |
| Equipo (HTML) | 302 al inicio de su portal con aviso («esa pantalla es del portal de clientes…») |
| Equipo (JSON) | 403 `portal_incorrecto` con `destino` |
| Cliente | Normal |

Pantallas del **equipo** con sesión de cliente (o doble membresía en el portal cliente): 403 con «Volver» al inicio del cliente. Nunca se amplía un permiso para ocultar el error.
Anónimo en pantallas exclusivas del equipo (`/agenda`, `/caja`, `/admin…`, `/profesionales`, `/servicios`) → `/equipo/login`; en el resto → `/`.

## 4. Destinos tras una operación

| Operación | Éxito | Error (403/404/409/422/500/503) |
|---|---|---|
| Iniciar / finalizar / ausencia (agenda) | `/agenda` con el mismo período (`desde`, `hasta`) | Página de error **dentro del layout del equipo**, «Volver a la agenda» con el período; nunca `/mis-citas` |
| «Finalizar y cobrar» | `/caja/cita?id=N` con el período (cubierto por el E2E de navegador `R5QaNavegadorTest`) | Si el cobro no procede, la cita queda atendida con saldo pendiente y se reintenta desde la caja |
| Cobro, devolución, verificar/rechazar | `/caja/cita?id=N` | Error en layout con «Volver al cobro» (probado: exceso de saldo muestra el mensaje concreto en una alerta persistente) |
| Cancelar cita (cliente) | `/mis-citas` con aviso | `/mis-citas` |
| Cancelar/eliminar cita (equipo) | Inicio de su portal | Página de error del equipo |
| Exportación (CRM, caja, reportes) | Descarga | 403 / 422 / 503 con la página de error estándar (sin auditoría de destino específica en esta fase) |

## 5. Páginas de error

`ErrorResponse::render` acepta `volver` y `volver_etiqueta` (validados contra el catálogo). El «inicio» se calcula con el **portal de la sesión**, no con `$_SESSION['rol']`. La descripción se anuncia como `role="alert"`.

## 6. Pruebas que lo respaldan

`tests/Unit` del catálogo (tipado, inyección `//evil`, `http:`, `javascript:`, CRLF, arreglos), `tests/Qa/R5DevPortalesTest.php`, `tests/Qa/R5QaPortalesTest.php` (escáner de enlaces equipo↔cliente, doble membresía, suspensión por portal, sesión expirada, rol cambiado en caliente, errores desde acciones del equipo y del cliente) y la matriz de autorización (`AutorizacionMatrizTest`) actualizada a estos contratos.

## 7. Enlaces de correos y notificaciones (auditados)

Inventario de correos y su destino (`classes/Email.php`, `services/NotificacionCitaService.php`, `scripts/instalar.php`):

| Correo | Destinatario | Enlace principal | Portal |
|---|---|---|---|
| Confirmación de cuenta | cliente | `/confirmar-cuenta?token=…` (GET solo muestra el formulario) | cliente |
| Recuperación de contraseña | cliente o equipo | `/recuperar?token=…` (+ `&portal=equipo` si es del equipo) | el de la cuenta |
| Invitación | colaborador | `/equipo/aceptar?token=…` | equipo |
| «Ya tienes una cuenta» | titular | `/cliente/login` o `/equipo/login` según su portal | el de la cuenta |
| Confirmación, recordatorio, reprogramación y cancelación de cita | cliente | `/cita` | cliente |

Hallazgo corregido: el enlace de marca y «Visitar el sitio» de **todos** los correos apuntaba a `/` (acceso de clientes); en los correos del equipo (invitación, recuperación y «cuenta existente» del equipo) ahora apunta a `/equipo/login`.
Pruebas (escritas por el integrador, no son revisión independiente): `tests/Integration/R5EnlacesCorreoTest.php` (contenido: mismo origen, destino por portal, ningún cruce cliente↔equipo, solo el enlace personal lleva token, una sola vez; verificada con mutación: sin la corrección fallan 3 casos) y `tests/Qa/R5IntEnlacesCorreoHttpTest.php` (HTTP real: `/cita` anónimo → acceso de clientes, cliente → reserva, equipo → su inicio; sesión invalidada de cliente → `/` y de equipo → `/equipo/login`; membresía de cliente suspendida sin acceso y sin afectar al equipo; tokens inválidos de invitación, recuperación y confirmación sin filtrar cuentas ni redirigir a pantallas privadas).
Límite: `scripts/instalar.php` imprime en consola el enlace de invitación del propietario inicial (comportamiento de la instalación, ya existente); no es un correo.

## 8. Pendiente

* La validación de estos recorridos en el equipo real del usuario sigue fuera de esta fase.

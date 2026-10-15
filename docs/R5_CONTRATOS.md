# Ronda 5 — contratos compartidos (agenda → atención → finalización → cobro → comprobante y conciliación)

Documento de trabajo del líder. Lo leen todos los agentes **antes de escribir código**. Un contrato solo cambia por solicitud al líder.

## 1. Causas raíz de los fallos observados (reproducidas con HTTP real: `tests/Qa/R5DiagnosticoTest.php`)

| Fallo | Causa raíz comprobada |
|---|---|
| **A** «Iniciar atención» lleva a una página inesperada | `views/agenda/index.php` ofrece «Iniciar atención» para **toda** cita `confirmada`, también las de un horario futuro. El servidor (`CitaRepository::mutarAgenda`) responde **422 «La atención todavía no ha comenzado»**. `AgendaController::modificar` pinta ese error como HTML **sin layout** (`<h1>No se pudo actualizar la cita</h1>…`). Con una cita ya comenzada la acción sí funciona y vuelve a `/agenda`, pero **pierde el período consultado** (`desde`/`hasta`). |
| **B** «Volver a las citas» abre `/mis-citas` con «Reservar una cita» | El enlace de esa página de error está **fijo** a `/mis-citas` (portal de clientes) y `GET /mis-citas` **no comprueba el portal**: una sesión de equipo recibe la pantalla del cliente. Es un defecto de destino, no de permiso. Misma familia: `ErrorResponse::render` calcula «Volver al inicio» con `$_SESSION['rol']` (que el login de equipo no escribe) → cae en `/perfil`. |
| **C** No hay ruta para marcar atendida y cobrar | `/admin/finanzas` solo cobra tecleando el **ID de la cita**; la agenda no enlaza a cobro, pagos ni comprobante; el cobro de cita es siempre «importe histórico completo en un solo pago» (sin saldo, sin anticipo parcial, sin verificación de transferencia, sin comprobante imprimible). |
| **D** Filtros del CRM | El CRM no filtra por estado de la membresía (activo/suspendido), cuenta confirmada ni por estado del consentimiento (otorgado/revocado/sin decisión), y no muestra filtros activos ni «Limpiar». |
| **E** «Listado» | En la tabla de segmentos la columna «Listado» no abre una lista con los clientes del segmento y no hay descarga clara. |
| **F** Exportaciones | Solo hay CSV (CRM y reportes) y ningún XLSX real. Cada sección arma su CSV por su cuenta. |

## 2. Reparto y propiedad de archivos (un archivo = un dueño)

| Agente | Rama / BD | Dueño de |
|---|---|---|
| **Agenda y atención** (`a5`) | `wt/a5` · `appsalon_a5_test` · HTTP 8130-8139 | `controllers/AgendaController.php`, `services/CitaService.php` (solo `actualizarAgenda` y nuevas funciones de atención), `repositories/CitaRepository.php` (solo `mutarAgenda`, `listarAgenda`, nuevas funciones de atención), `views/agenda/*`, `includes/rutas/agenda.php`, migración **023** |
| **Pagos, caja y conciliación** (`p5`) | `wt/p5` · `appsalon_p5_test` · 8160-8169 | `controllers/CajaController.php` (nuevo), `controllers/FinanzasController.php`, `controllers/PagoController.php`, `services/FinanzasService.php`, `services/CobroCitaService.php` (nuevo), `services/ComprobanteService.php` (nuevo), `repositories/OperacionRepository.php`, `repositories/PagoRepository.php`, `repositories/ComprobanteRepository.php` (nuevo), `views/caja/*` (nuevo), `views/finanzas/*`, `views/pagos/*`, `includes/rutas/caja.php`, migraciones **024** y **025** |
| **Portales, permisos y navegación** (`n5`) | `wt/n5` · `appsalon_n5_test` · 8170-8179 | `includes/funciones.php`, `includes/destinos.php`, `includes/rutas/portales.php`, `classes/ErrorResponse.php`, `views/errores/*`, `views/layout.php`, `views/templates/*`, `controllers/CitaController.php`, `controllers/AdminController.php`, `controllers/ProfileController.php`, `controllers/LoginController.php` (solo destinos), `services/AuthorizationService.php`, correos/enlaces profundos que apuntan a pantallas, JS de navegación |
| **CRM, filtros y exportaciones** (`c5`) | `wt/c5` · `appsalon_c5_test` · 8180-8189 | `controllers/CrmController.php`, `services/CrmService.php`, `repositories/CrmRepository.php`, `views/crm/*`, `controllers/AdminReporteController.php`, `controllers/ReporteController.php`, `services/ReporteService.php`, `repositories/ReporteRepository.php`, `views/admin/reportes.php`, `controllers/CrecimientoController.php` (exportación), `includes/rutas/crm.php`, `classes/Exportacion/*` (ya existe; extender, no romper), migración **026** |
| **QA independiente** (`q5`) | `wt/q5` · `appsalon_qa_q5_test` · 8150-8159 | `tests/Qa/R5*.php` (nuevos), `tests/Qa/R5Case.php` |
| **Líder** | rama de entrega | `docs/*`, `public/index.php` (solo el `foreach` de rutas), `phpunit.xml`, CI, integración |

Un cambio mínimo en archivo ajeno se lista aparte en el informe como «solicitud al líder» con el diff exacto.
Puertos SMTP simulados: 2630-2639 (a5), 2650-2659 (p5), 2660-2669 (n5), 2640-2649 (c5), 2590-2599 (q5). Nada sale a Internet ni a correos reales.

## 3. Migraciones reservadas

* **023** `atencion_citas` (agenda): trazabilidad de la atención.
* **024** `cobros_verificacion` y **025** `comprobantes` (pagos).
* **026** `exportaciones_auditoria_general` (CRM/exportaciones): auditoría append-only de exportaciones que no son del CRM (caja, reportes, comprobantes).

Cada una con su `*_rollback.sql` **seguro** (patrón de la 022: nunca perder datos de negocio sin guardián), idempotente en pruebas y compatible con MySQL 8.0.13+.

## 4. Destinos por portal (`includes/destinos.php`, dueño: `n5`; ya existe un primer borrador funcional)

* Todo botón «Volver», redirección posterior a guardar/cancelar/fallar y página de error de operación usa `destino_portal()`, `campos_retorno()`, `destino_volver()` o `responder_error_operacion()`. **Prohibido** el `Referer`, URL completas en parámetros y rutas escritas a mano en controladores/vistas.
* Los formularios de acciones del equipo llevan `campos_retorno('agenda', ['desde'=>…,'hasta'=>…])` y el controlador responde `redirigir(destino_volver('agenda'))`. Un fallo (403/404/409/422/500/503) se muestra con `responder_error_operacion(...)` dentro del layout, con el «Volver» al **mismo portal**.
* Una sesión de equipo que abre una pantalla **exclusiva del cliente** (`/mis-citas`, `/cita`, `/mi-bienestar`, `/api/mis-citas`, reserva) es redirigida al inicio de su portal con aviso; una sesión de cliente que abre pantallas del equipo recibe 403 con «Volver» a `/cita`. Nunca se amplía un permiso para ocultar el error.
* Matriz de destinos por portal y por pantalla/estado de error: entregable de `n5` en `docs/R5_MATRIZ_DESTINOS.md`.

## 5. Máquina de estados de la atención (dueño: `a5`)

```
confirmada ──iniciar──▶ en_servicio ──finalizar──▶ completada
    │
    └──registrar ausencia──▶ no_asistio          cancelada (flujo propio, no se toca aquí)
```
* `completada`, `no_asistio` y `cancelada` son terminales. Una cita histórica no se reabre ni se modifica en silencio.
* **Iniciar** se permite desde `ADELANTO_INICIO_MINUTOS = 60` antes del horario de inicio (decisión de negocio documentada: el cliente puede llegar antes) y hasta que termine el día de la cita. Antes de ese margen la agenda muestra el botón **deshabilitado con el motivo** («Disponible desde las HH:MM»), nunca un error de página. El servidor sigue validando.
* Quién: `agenda.gestionar` (propietario, gerente, recepción) y el **profesional solo sobre su propia agenda**. El cliente no cambia estados de atención.
* Cada transición registra **actor, fecha y hora (America/Guayaquil, como ya guarda el proyecto), estado anterior y nuevo** (`agenda_eventos` y las columnas de 023), es **atómica** (`FOR UPDATE`) e **idempotente** (doble clic = una transición, la segunda responde «ya actualizado» sin segundo evento). Dos procesos simultáneos: gana uno, el otro recibe el estado vigente.
* Estado de atención y **estado financiero son independientes**: finalizar nunca marca «pagado».
* «Finalizar y cobrar» = finaliza (transición normal) y continúa a `destino_portal('caja_cita', ['id'=>…])`. Si el cobro falla, la cita queda **atendida con saldo pendiente** y se puede reintentar sin duplicar.
* Detalle de la cita para el equipo: `GET /agenda/cita?id=N` (cliente, profesional, servicios con precios históricos, estado de atención, quién atendió/cuándo, estado de pago, accesos a cobro/pagos/comprobante).
* Acciones por tarjeta y detalle (según estado y permisos del servidor): «Iniciar atención», «Registrar ausencia», «Finalizar atención», «Finalizar y cobrar» (exige `agenda.gestionar` **y** `finanzas.gestionar`: propietario y gerente), «Cobrar saldo» (`finanzas.gestionar`), «Ver pagos» y «Ver comprobante» (`finanzas.gestionar`).

## 6. Cobro, caja, comprobante y conciliación (dueño: `p5`)

* Dinero en **centavos enteros** (`ValoresExactos`); nunca `float` para sumar. El total de la cita es la suma de los precios **históricos** de `citasservicios`, nunca el catálogo actual.
* `saldo = total − Σ pagos confirmados + Σ devoluciones`. Estados financieros de la cita: `pendiente · parcial · por_verificar · pagado · reembolsado` (columna existente `citas.estado_pago`; se amplía de forma compatible).
* Rutas: `GET /caja` (vista operativa de citas atendidas con filtros fecha/cliente/profesional/método/estado), `GET /caja/cita?id=N` (formulario de cobro con cliente, profesional, fecha, servicios, total, anticipos, pagos, devoluciones, saldo, métodos), `POST /caja/cita/cobrar`, `POST /caja/pago/verificar` y `/caja/pago/rechazar` (transferencias), `GET /caja/comprobante?id=N` (imprimible/descargable), `GET /caja/exportar?formato=csv|xlsx`.
* Métodos: **Efectivo** (monto recibido y **cambio**; el cambio no es ingreso); **Transferencia** (referencia + estado de verificación; lo declarado/subido **no** es dinero confirmado hasta que `finanzas.gestionar` lo verifica; quién verifica y cuándo queda auditado); la pasarela simulada sigue marcada como simulada y separada.
* Prohibido: doble cobro por doble clic o reintento (idempotencia persistente + bloqueo `FOR UPDATE` de la cita), imputar un pago a otra cita manipulando el formulario, superar el saldo, devolver más de lo cobrado, confirmar pagos sin permiso. Comisiones y caja se actualizan en la **misma transacción** que el pago confirmado.
* Comprobante **interno** (no es factura electrónica): identificador único y correlativo, fecha, detalle, importes coherentes, imprimible. Debe decir «Comprobante interno — no es factura autorizada por el SRI». La integración fiscal queda documentada como **pendiente**.
* Si adjunta comprobantes de transferencia: validar tipo/tamaño, almacenamiento privado y acceso autorizado; si no se implementa, se documenta como pendiente (basta la referencia).

## 7. Exportaciones (dueño: `c5`; base ya integrada en `classes/Exportacion`)

Flujo obligatorio en TODA exportación tabular: **autorizar en servidor → `Exportador::generar()` → auditar (si falla, no se entrega) → `Exportador::entregar()`**. Parámetro `formato=csv|xlsx` (csv por omisión; otro valor ⇒ 422). Los totales salen del **mismo universo filtrado** que la lista. Auditoría con actor, rol, filtros, número de filas, formato, hash y resultado (sin datos personales innecesarios; sin tokens). Sin dependencias nuevas: el XLSX lo escribe `XlsxEscritor` (el contenedor no instala `ext-zip`). Las secciones no tabulares (comprobante, ficha) no se fuerzan a Excel.

## 8. CRM: definiciones de los filtros (dueño: `c5`)

* **Cuenta**: confirmada / sin confirmar (`usuarios.confirmado`).
* **Membresía de cliente**: activa / suspendida (`clientes.estado`). *No* es «suscripción».
* **Comunicaciones comerciales (consentimiento)**: otorgado / revocado / sin decisión, con versión y fecha. En este sistema **no existe una suscripción de pago**; «suscripción» se interpreta y se rotula como **suscripción a comunicaciones comerciales** = consentimiento otorgado vigente. No se inventa una entidad de pago ni se muestran datos ficticios.
* **Actividad comercial**: reciente, inactivo por periodo, nunca atendido.
* Filtros combinables, paginación, orden, búsqueda por nombre/correo/teléfono, fechas de alta/última visita/próxima cita, profesional, frecuencia/gasto (con definición visible), saldo pendiente solo si el rol lo ve. Se muestran **filtros activos**, **conteo** y «Limpiar filtros». Lista, indicadores y exportación usan el **mismo universo**.
* Alcance por rol intacto: el profesional no recibe toda la base; finanzas no recibe datos personales que su permiso no autorice. Las estrategias comerciales respetan consentimiento y bajas.
* «Listado» (y todo «Ver detalle»/«Descargar») abre la vista o modal accesible con paginación, filtros/permisos heredados, estados vacío y de error, y descarga CSV/XLSX con los mismos registros y totales.

## 9. Pruebas (todos)

* Prueba roja primero (reproduce) → corrección → verde; para cada garantía, mutación o revert que demuestre que la prueba falla sin ella.
* Concurrencia con procesos PHP reales; nada de omitir pruebas; ningún 302 vale como prueba de navegación sin comprobar `Location` y la pantalla final.
* BD aislada por agente; **no** ejecutar suites completas en paralelo en la misma BD.

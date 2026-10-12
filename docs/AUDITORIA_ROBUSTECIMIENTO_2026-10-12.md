# Auditoría de robustecimiento y comparación de mercado — AppSalon

Fecha: 12 de octubre de 2026. Código examinado: `d8e00f8f25ab61c5c074746305a82b4d4cb30fd6`, rama `feature/interfaz-moderna`. Esta entrega contiene diagnóstico y especificaciones; no implementa las funcionalidades propuestas ni modifica la instalación Windows del propietario.

## 1. Dictamen y alcance

AppSalon es un MVP funcional de reservas para un salón con una base técnica comprobada. Todavía no es una solución completa de operación comercial, administración delegada o cobros reales. El cierre previo de pruebas verifica los escenarios implementados; no acredita que todas las reglas de un salón estén cubiertas ni sustituye una prueba de penetración.

Evidencia previa comprobada: [CI #38279476360](https://github.com/Johnsky-Games/AppSalon_PHP_MVC_JS_SASS/actions/runs/38279476360), aprobada sobre el SHA citado: imagen Apache/PHP 8.2, MySQL 8, nueve migraciones, 218 pruebas/2727 aserciones, 21 comprobaciones E2E, tres fallos de carga del catálogo y backup/restore. La interfaz tuvo 16 comprobaciones de ancho en cuatro pantallas a 320/390/768/1440px y capturas revisadas. No equivale a certificación WCAG ni a verificar todos los teléfonos/navegadores.

En esta ronda se inspeccionaron rutas, modelos, servicios, repositorios, esquema/migraciones, permisos/sesiones, configuración Docker/CI, notificaciones, reportes y vistas. Se ejecutaron consultas nuevas de advisories NPM y Packagist y se contrastaron páginas oficiales de competidores. No se ejecutó de nuevo PHP/MySQL localmente, no se accedió a la base del usuario ni a una instalación pública de producción. Los defectos de flujo identificados por inspección requieren las pruebas de regresión indicadas antes de cerrarse.

### Fortalezas que deben conservarse

- Separación entre controladores, servicios y repositorios; consultas preparadas en los repositorios revisados.
- Identidad de sesión y propiedad de citas, CSRF, regeneración del identificador de sesión y rate limiting de login/recuperación/reenvío.
- Tokens criptográficos, hash, expiración y consumo atómico; protección contra asignación masiva de campos administrativos.
- Disponibilidad por profesional, horarios, descansos y bloqueos; reserva transaccional y snapshots de precio/nombre/duración.
- Cancelación lógica con conservación histórica; reportes de cobros y reembolsos por fecha del movimiento.
- Pagos simulados con idempotencia y conciliación trazable; proveedor mock expresamente bloqueado en producción.
- Outbox durable con leases/reintentos; scripts de respaldo y restore exclusivo a base nueva/vacía.
- Interfaz clara, búsquedas locales, SweetAlert2/Toastify locales, foco visible, skiplink y movimiento reducido.
- Exportación CSV con neutralización de prefijos de fórmula. Mantener pruebas de Excel/LibreOffice y Unicode; no afirmar que reabrir CSV en cualquier aplicación siempre es seguro.

## 2. Hallazgos concretos

P0 = corregir antes de cobros reales o de delegar administración. P1 = siguiente entrega funcional. P2 = madurez operativa/crecimiento. Estas prioridades no son una clasificación CVSS de la aplicación; las severidades NPM se reportan aparte.

| ID | Prioridad y evidencia | Problema e implicación | Corrección y criterio de cierre |
|---|---|---|---|
| A01 | P0. `package-lock.json`, nueva ejecución `npm audit --json` | 117 paquetes reportados vulnerables: 2 críticos, 47 altos, 65 moderados, 3 bajos. Los críticos son `decompress@4.2.1` y `fast-xml-parser@4.3.6`, en cadenas de imágenes de Gulp. No demuestra 117 exploits distintos ni ejecución remota del PHP. | Modernizar/sustituir la cadena de imágenes/compilación, evaluar exposición de CI y descargas de binarios, auditoría automática y actualización controlada del lockfile. Cero alertas críticas/altas alcanzables sin resolución o excepción justificada. No aplicar `npm audit fix --force` sin revisar cambios mayores. |
| A02 | P0. `PagoRepository::obtenerCitaConImporteServidor`, `PagoService::iniciarPago` | La consulta no devuelve `citas.estado` y el inicio del pago no rechaza una cita cancelada. Una cita cancelada conserva servicios/snapshots y puede entrar al flujo de un nuevo intento de pago. Hallazgo estático; falta repro dedicado. | Leer estado bajo el mismo bloqueo y rechazar nuevos pagos de citas canceladas. Prueba: cancelar cita sin pago, iniciar pago => conflicto, ningún nuevo pago ni invocación al gateway. Prueba concurrente cancelar/iniciar. Los callbacks tardíos de un cobro anterior deben registrarse/conciliarse, no ocultarse. Definir por separado cobros de servicios ya atendidos. |
| A03 | P0 al delegar acceso. `usuarios.admin`, `isAdmin()` y `LoginController::login` | Todos los administradores comparten la misma capacidad global. El rol se copia a `$_SESSION`; bajar `admin` en SQL no revoca por sí mismo una sesión ya abierta. No hay rol propietario/superadmin ni permisos granulares. | Rol+permisos+alcance del recurso y versión de autorización/sesión. Tras revocación, la siguiente petición sensible devuelve 403. Un recepcionista no puede otorgarse permisos, exportar clientes ni reembolsar sin autorización. Proteger el último propietario contra borrado/degradación concurrente. |
| A04 | P1 seguridad/datos. `Usuario::validarNuevaCuenta`, `AuthService::registrar` | Registro valida presencia y password de 6 caracteres; no valida formato de email, teléfono ni longitudes conforme al esquema. Email/nombre/apellido VARCHAR(60), teléfono VARCHAR(10). El HTML no sustituye validación de servidor. | Email validado, longitudes explícitas, teléfono normalizado según mercado y política; respuestas 422 útiles en lugar de depender de error SQL. Política de password y MFA para administración; evitar truncamiento de bcrypt si se amplían longitudes. Si se cambia algoritmo, ampliar `password` a VARCHAR(255) y migrar mediante rehash sin romper hashes existentes. |
| A05 | P1. Ruta de registro y `AuthService::registrar` | No se observa admisión/rate limiting de registro como el de login/recuperación. Riesgo de abuso de altas y correos. | Límite de altas/envíos por origen y destinatario, observación de abuso y respuesta compatible con accesibilidad. Pruebas de ráfaga sin saturar SMTP. |
| A06 | P1. Modelo y migración 003 de `profesionales` | Un profesional tiene nombre, activo, fecha y servicios; no tiene usuario vinculado, email/teléfono/bio/foto ni acceso individual. Crear un profesional no crea un empleado que pueda iniciar sesión. | Alta/invitación de colaborador, relación `usuario_id` única opcional con profesional y rol independiente de agenda. Desactivar acceso y actividad profesional como operaciones distintas, con impacto explícito sobre citas futuras. |
| A07 | P1. `public/index.php`, vistas y esquema | No hay edición de perfil, cambio autenticado de contraseña/email, preferencias ni upload de fotos. Los avatares son ilustraciones compartidas. | Panel Mi perfil, seguridad, preferencias y almacenamiento seguro de medios con autorización por propietario/recurso. No hacer públicos los perfiles/fotos de clientes por defecto. |
| A08 | P1 defecto UX. `consultarCitasCliente()` y `validarPoliticaCancelacion()` | `puede_cancelar` compara solo la fecha, mientras la validación final compara fecha y hora. Una cita de hoy que ya empezó puede mostrar Cancelar y luego ser rechazada. | Una única política de elegibilidad para el listado y la operación final. Casos hoy antes/después/exactamente al inicio, zona Guayaquil y límite aprobado. |
| A09 | P1. `APIController::misCitas()` | Usa `soloConfirmadas=true`; las citas canceladas no aparecen en ese listado. No es un historial completo de cliente. | Separar próximas/anteriores/canceladas, paginación y detalle histórico sin alterar snapshots ni cobros. Prueba de cancelación visible en historial y fuera de próximas. |
| A10 | P1/P0 al cobrar. `PagoController::resolverGateway()` | Solo existe mock; cualquier proveedor en producción lanza excepción. No hay cobro real, caja ni conciliación bancaria. Esto es una limitación consciente, no un bypass demostrado. | Selección contractual del proveedor compatible con el negocio, sandbox real, webhook verificable, idempotencia, conciliación y retorno informativo. Confirmar política de anticipos, reembolsos/comisiones e impuestos antes de modelar pagos parciales. |
| A11 | P1 operaciones. Compose y guía de worker | Outbox existe, pero Compose solo inicia web/db: no programa por sí solo el worker. La guía usa contenedor antiguo y `exec -t` para cron. SMTP de prueba/ejecución manual no acredita entrega continua. | Worker/scheduler administrado, comando sin TTY para tareas programadas, healthcheck de última ejecución, backlog/leases/reintentos y alertas. Reiniciar stack y probar despacho sin comandos manuales. |
| A12 | P1 antes de publicar. Docker/Compose, configuración de sesiones | Stack de desarrollo con volumen fuente, usuario DB root por defecto y puerto DB publicado; sin perfil de producción separado, servicio web saludable o gestión de proxy TLS. `Secure` de cookie depende de `$_SERVER['HTTPS']`. | Perfil de producción con código/assets/vendor en imagen, secretos externos, usuario DB mínimo, DB no pública, HTTPS/proxy de confianza y cookies explícitas. No confiar en X-Forwarded-* arbitrario. Healthchecks HTTP, logs estructurados, restore/rollback ensayados. |
| A13 | P1 negocio. Estado de cita/rutas | Estados principales confirmada/cancelada; faltan llegada, atención, finalizada/no-show, reprogramación atómica y reserva administrativa asistida. | Máquina de estados con actores/horas/motivos y pruebas negativas de transición. Reprogramación protege viejo y nuevo intervalo en transacción; falla => cita anterior intacta. |
| A14 | P2 escalabilidad. Listados/repositorios y vistas | Catálogos/citas se cargan completos y se filtran localmente; no se observa paginación de negocio en esos listados. Reportes sí limitan el rango de fechas, pero aún necesitan evaluación con volumen real. | Paginación y búsqueda de servidor, índices basados en EXPLAIN, límites/exportación en segundo plano si corresponde; prueba de carga y objetivos de latencia aprobados. |
| A15 | P2 documentación | La matriz anterior afirma profesional con email/teléfono y teléfono cliente de 10 dígitos validado, sin respaldo en el modelo/validador actual. | Cambiar aceptación a requisitos concretos con evidencia y pruebas. No usar conteo de tests como prueba de funciones ausentes. |

### Dependencias: evidencia y límites

La cadena crítica inspeccionada es `gulp-imagemin -> imagemin-gifsicle -> gifsicle -> bin-build/download -> decompress`, y `gulp-imagemin -> imagemin-svgo -> is-svg -> fast-xml-parser`. También hay alertas heredadas en Gulp/browser-sync/cssnano y otros plugins. Debe distinguirse la severidad del advisory, la fase donde se ejecuta y la posibilidad real de entregar contenido no confiable.

Se consultaron los 33 paquetes de `composer.lock`, incluidos desarrollo, en la API pública de Packagist; el cruce de las versiones fijadas con los rangos devueltos no encontró coincidencias ni rangos sin interpretar. No se ejecutó `composer audit` nativo en esta ronda; añadirlo a CI y comprobar también paquetes abandonados. El snapshot reproducible y hashes de lockfiles están en `evidencias/DEPENDENCIAS_2026-10-12.json`.

## 3. Cobertura funcional actual

| Área | Existente | Brecha principal |
|---|---|---|
| Identidad | Registro, login, confirmación, recuperación | Perfil, MFA, suspensión, sesiones activas, invitaciones y roles |
| Equipo | Alta/edición/desactivación, servicios, horarios/descansos/bloqueos | Cuenta individual, contacto, foto, especialidad, permisos y comisiones |
| Clientes | Datos de cuenta y citas propias confirmadas | Ficha, historial completo, preferencias, consentimientos y notas privadas |
| Agenda | Disponibilidad, intervalos y conflictos por profesional | Calendario día/semana, reprogramación, atención/no-show, recepción, buffers/recursos |
| Servicios | Precio/duración y snapshot histórico | Foto/descripción, variantes, extras, paquetes y precio/duración por profesional |
| Notificaciones | Email y outbox con worker CLI | Operación continua, preferencias, centro de avisos y canales adicionales autorizados |
| Finanzas | Simulación, eventos, reembolso total y reportes/CSV | Proveedor real, caja/conciliación, anticipos, facturación y comisiones |
| UX | Rediseño, búsquedas, gráficas, Toastify/SweetAlert | Preferencias persistentes, perfiles reales, navegación por tareas y pruebas WCAG completas |
| Operaciones | CI, migraciones, backups y restore seguro | Seguridad de dependencias, producción reproducible, monitoreo y carga |

## 4. Benchmark con referencias oficiales

Comparación de capacidades declaradas por los proveedores, no una prueba de sus cuentas privadas ni certificación independiente de calidad. Plan, país, add-ons y condiciones pueden limitar funciones. No se afirma disponibilidad de todos sus medios de pago en Ecuador ni se comparan precios.

| Referencia | Capacidades verificadas públicamente | Brecha relevante de AppSalon |
|---|---|---|
| Fresha | Cuentas de equipo/permisos, ficha y formularios de cliente, recursos, lista de espera, comisiones, inventario y pagos | Operación delegada y ficha de cliente; completar agenda y monetización |
| Booksy | Perfiles de empleados y cinco niveles de acceso, fotos/portfolio, buffers/procesamiento, lista de espera, depósitos, comisiones e inventario | Equipo con acceso propio, fotos y reglas de calendario/atención |
| AgendaPro | Agenda, fichas, comisiones, cobros/caja, inventario, multisucursal y recordatorios por canales | Herramientas administrativas adaptadas a operación de un salón latinoamericano |
| Square Appointments | Cuentas de empleado, permisos por función, perfiles/notas, historial, comisiones y depósitos/lista de espera documentados | Referencia de autorización por acción y experiencia de recepción/caja |

No hace falta construir un marketplace, nómina completa o IA para alcanzar una primera versión comercial útil. La ventaja inicial debe ser facilitar la operación diaria del salón, con control de equipo, datos y reglas claras.

## 5. Usuarios, colaboradores y permisos: especificación propuesta

Diferenciar identidad (`usuarios`), actividad profesional (`profesionales`) y permisos. Un recepcionista puede ser empleado sin ejecutar servicios; un profesional puede existir para agenda sin acceso al sistema; el propietario puede también atender. No convertir automáticamente a todos los profesionales en administradores.

| Rol inicial | Acceso predeterminado propuesto |
|---|---|
| Propietario / superadmin del salón | Configuración, invitaciones, roles, auditoría, finanzas y todas las operaciones del negocio |
| Administrador / gerente | Agenda, equipo, catálogo y reportes autorizados; no otorgar rol propietario ni cambiar controles críticos sin permiso |
| Recepción | Reservar/reprogramar/cancelar para clientes y ver agenda autorizada; no exportar base ni reembolsar por defecto |
| Profesional | Agenda propia, estados de atención y ficha mínima necesaria de sus clientes; sin finanzas globales |
| Caja / finanzas | Registrar cobros, consultar caja y conciliación; reembolso con permiso separado y posible aprobación |
| Cliente | Perfil, preferencias y citas/pagos propios |

Permisos de ejemplo: `citas.ver_propias`, `citas.ver_todas`, `citas.crear_para_cliente`, `citas.reprogramar`, `clientes.ver`, `clientes.exportar`, `clientes.notas_privadas`, `profesionales.gestionar`, `servicios.gestionar`, `pagos.cobrar`, `pagos.reembolsar`, `reportes.financieros`, `usuarios.invitar`, `roles.asignar`, `ajustes.modificar`, `auditoria.ver`. El alcance propio/asignado/sucursal se comprueba además del permiso. Ocultar un menú es presentación; el servidor siempre autoriza la acción y el recurso.

Flujo de colaborador: el administrador registra/invita -> asigna rol y servicios -> correo de activación de un solo uso -> colaborador define contraseña -> agenda/perfil según permisos. Conservar invitaciones vencidas/revocadas en auditoría. No compartir passwords de equipo. El primer propietario se provisiona con comando CLI idempotente, no con una contraseña predeterminada incluida en una migración ni un endpoint público.

Modelo mínimo orientativo: `roles`, `permisos`, `rol_permisos`, `usuario_roles`, `usuarios.activo/auth_version`, `profesionales.usuario_id`, `invitaciones`, `audit_events`. Mantener compatibilidad del bit actual durante una transición probada; evitar tener dos fuentes de autorización contradictorias.

## 6. Fotos y perfiles

- Cliente: nombre/contacto, avatar, historial, favorito de profesional/servicio, preferencias y seguridad. Cambiar correo requiere verificar el nuevo; cambiar contraseña requiere reautenticación y revocar sesiones según política.
- Profesional: foto pública aprobada, bio/especialidades, servicios, horarios y opcional portfolio. Datos internos/contacto/comisiones separados del perfil público.
- Foto: selector/arrastrar, vista previa, recorte, progreso, sustituir/eliminar y fallback accesible. El alt debe describir al profesional o ser vacío si el nombre adyacente ya lo identifica.
- Upload servidor: allowlist JPEG/PNG/WebP, MIME real/decodificación, límites de bytes/dimensiones, reencodificación y eliminación EXIF, nombre aleatorio y almacenamiento sin ejecución. Propuesta inicial 5MB/4096px, configurable y aprobada; no un requisito ya implementado.
- No aceptar SVG arbitrario, URLs remotas o nombres/rutas elegidos por el usuario en esta primera versión. Los SVG de ilustraciones existentes son recursos controlados del proyecto.
- Autorización y CSRF para cambiar foto; impedir acceso cruzado y sobrescritura. Cliente no público por defecto; foto pública del profesional conforme a su publicación autorizada. Eliminar derivados/archivos huérfanos con registro y política de retención.
- Tabla `media_assets` con dueño/tipo/checksum/MIME/tamaño/dimensiones/ruta controlada, referenciada desde perfil. Los snapshots históricos no deben depender de que un archivo de perfil siga existiendo.

## 7. Preferencias y ajustes persistentes

Separar configuración del negocio (base de datos, versionada/auditada) de infraestructura/secrets (.env).

| Cliente | Administrador/negocio | Profesional |
|---|---|---|
| Contacto, avatar y seguridad | Marca/logo, contacto, zona horaria/moneda | Foto/bio y contacto autorizado |
| Profesional/servicios favoritos | Antelación mínima y horizonte de reserva | Agenda, horario y descansos según permisos |
| Canales de recordatorios | Ventana de cancelación/no-show y reembolsos | Avisos de citas/cambios |
| Consentimiento de marketing separado | Anticipo/pago total, comisiones e impuestos | Vista de agenda preferida |
| Idioma y movimiento/contraste | Feriados, buffers y recursos | Datos de atención limitados a clientes asignados |
| Historial y exportación de datos propios | Límites de invitación, sesiones y auditoría | Comisiones propias cuando exista ese módulo |

No usar el opt-out de marketing para cancelar avisos de seguridad necesarios. Antes de guardar notas sensibles, definir finalidad, acceso y retención con el negocio. No se acredita cumplimiento legal ni se decide automáticamente el régimen tributario del salón.

## 8. Agenda, negocio, UI y crecimiento

Primero calendario día/semana por profesional, agenda de hoy, clientes que llegaron, tareas de atención y reserva desde recepción. Arrastrar una cita debe abrir confirmación con duración/precio y ejecutar la misma regla transaccional que cualquier reprogramación; ningún cambio meramente visual debe modificar disponibilidad.

Ciclo propuesto: confirmada -> llegada -> en atención -> finalizada; confirmada -> cancelada/no-show según política. Cada transición registra actor, fecha, razón y reglas de cobro. No marcar automáticamente un cobro real por cambiar el estado de una cita.

Otras reglas a decidir: antelación mínima, horizonte máximo, limpieza entre servicios, tiempo de procesamiento, recursos compartidos (silla/cabina/equipo), choque de agenda del mismo cliente, grupos/acompañantes, precio por profesional, feriados y recurrencia. Eliminar un colaborador o cambiar horario con citas futuras requiere un flujo de reasignación/reprogramación; las garantías transaccionales actuales de agenda deben mantenerse.

Después: lista de espera con oferta temporal e idempotente de un hueco; paquetes/membresías y consumo de sesiones; descuentos/cupones con límites y snapshot; fidelización/reseñas de citas realmente atendidas; inventario de venta/consumo y alertas; comisiones liquidadas sobre regla aprobada de cobros/atenciones/reembolsos. Multisucursal si hay demanda real, con alcance en cada query desde el diseño; un superadmin del salón no equivale a un operador global multiempresa.

UX siguiente: navegación según tareas/rol, calendario móvil en lista, perfil con edición y preview, onboarding del salón (catálogo/equipo/horarios/SMTP), empty states con acción útil, centro de avisos, búsqueda/paginación y filtros persistentes. Mantener contraste, teclado, 200% zoom, lector de pantalla, targets táctiles, textos de error asociados y movimiento reducido. PWA opcional; no confirmar reservas offline sin reconciliar disponibilidad.

KPIs siguientes: visitas atendidas/no-show, cancelación, recurrencia/retención, ticket cobrado, ocupación productiva y comisiones. Separar reservas, efectivo recibido, devoluciones, ingresos simulados y costos. No presentar importes reservados como ventas cobradas. Si hay facturación electrónica ecuatoriana, definir el proceso con referencia técnica oficial del SRI y el responsable contable.

## 9. Secuencia de entregas y aceptación

| Entrega | Alcance | Pruebas mínimas de aceptación |
|---|---|---|
| R0: integridad/seguridad | A01/A02/A04/A08, registro antiabuso y matriz corregida | Cita cancelada no inicia nuevo cobro; catálogo/registros con datos inválidos =>422; botón Cancelar respeta hora; dependencias críticas resueltas; suite existente verde |
| R1: identidad delegada | Propietario, roles/permisos, invitación, vínculo usuario-profesional y revocación | Matriz positiva/negativa por rol/ruta/recurso; IDOR; permiso revocado invalida siguiente petición; último propietario protegido; expiración/replay de invitación |
| R2: perfiles/fotos | Mi perfil, profesionales reales, uploads, privacidad y sesiones | MIME/bytes/píxeles/rutas maliciosos rechazados; dueño distinto=>403; recambio/borra derivados; correo confirmado; clave cambia/revoca; responsive/teclado |
| R3: operación de agenda | Estados de atención, calendario, recepción, historial y reprogramación | Dos reprogramaciones simultáneas no ocupan mismo hueco; fallo conserva cita vieja; estados inválidos rechazados; historial/pagos preservados |
| R4: reglas/preferencias | Ajustes versionados, límites, buffers, no-show, consentimiento y worker continuo | Reglas idénticas UI/servidor; cambio no altera historial; worker recupera fallo/reinicio; opt-in/out respetado; avisos sin duplicados |
| R5: finanzas reales | Proveedor, caja, conciliación, anticipos/comisiones y facturación definida | Sandbox real, webhook repetido/fuera de orden, cancelación-pago concurrentes, reembolso total/parcial aprobado y balance consistente |
| R6: crecimiento | Lista de espera, promociones, fidelización, inventario y sucursales según negocio | Asignación de hueco única, límites de cupones, saldos/stock no negativos, aislamiento de sucursales y métricas conciliables |

No se estiman días de desarrollo sin aprobar reglas, alcance y recursos. Cada entrega usa rama y commit revisable, pruebas de regresión y documentación actualizada. Cerrar un hallazgo exige evidencia, no solo marcar una casilla. La auditoría solicita estas mejoras; no autoriza una fusión a main ni un despliegue.

## 10. Referencias consultadas

Fuentes oficiales de mercado, consultadas el 12/10/2026:

- [Fresha — funcionalidades](https://www.fresha.com/for-business/features).
- [Booksy — funcionalidades](https://biz.booksy.com/features).
- [AgendaPro — software para salones](https://agendapro.com/es/salon-de-belleza/software-para-salon-de-belleza).
- [Square Appointments — funcionalidades](https://squareup.com/us/en/appointments/features).
- [Square — depósitos](https://squareup.com/help/us/en/article/8096-deposits-on-square-appointments).
- [Square — lista de espera](https://squareup.com/help/us/en/article/7923-waitlist-with-square-appointments).

Referencias técnicas:

- [OWASP — autorización](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html).
- [OWASP — carga de archivos](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html).
- [OWASP — CSV injection](https://owasp.org/www-community/attacks/CSV_Injection).
- [GitHub Advisory — decompress](https://github.com/advisories/GHSA-mp2f-45pm-3cg9).
- [GitHub Advisory — fast-xml-parser](https://github.com/advisories/GHSA-m7jm-9gc2-mpf2).
- [Packagist — API de advisories](https://packagist.org/api/security-advisories/).
- [SRI — facturación electrónica](https://www.sri.gob.ec/facturacion-electronica).

Las fuentes comerciales sustentan la comparación de funciones, no promesas de rentabilidad, cobertura ecuatoriana o una recomendación de contratación. Las recomendaciones de arquitectura/prioridad son inferencias de esta auditoría basadas en el código y el objetivo del propietario.

# Registro de Progreso del Proyecto AppSalon

Este archivo mantiene la trazabilidad estricta del avance del proyecto de acuerdo con la metodología de entregas auditables por **ChatGPT** (revisión estática de código) y ejecución/verificación por **Antigravity** (desarrollo y pruebas dinámicas automatizadas), sujeto a la aprobación final del **Propietario**.

> **Roles y Criterios:**
> - **Desarrollo y Pruebas Automatizadas (Antigravity):** Implementación de código y ejecución de la suite completa de pruebas unitarias e integrales (49 pruebas, 322 aserciones en PHPUnit).
> - **Revisión Estática Externa (ChatGPT):** Auditoría independiente de código, patrones de seguridad, contratos transaccionales y cobertura de casos límite.
> - **Aprobación Final y Despliegue (Propietario):** Decisión formal sobre fusiones hacia `main` y despliegues en producción.

---

## Entrega 1: Seguridad y Consistencia del Sistema Actual

- **Rama:** `feature/seguridad-y-consistencia-inicial`
- **Commit Base:** `e816154b96985c2773e4fb418bbbf3e92530cca3`
- **Revisión Estática de Código (ChatGPT):** Cerrada y aprobada para esta ronda sobre commit `da7aa87`.
- **Pruebas Automatizadas Ejecutadas (Antigravity):** **Probado** (52 pruebas automatizadas, 360 aserciones, 0 fallos, 0 errores, 0 advertencias en entorno Docker PHP 8.2 + MySQL 8.0, incluyendo `FunctionalRunnerSecurityTest` que garantiza rechazo estricto de bases no autorizadas antes de modificar datos).
- **Verificación Funcional HTTP (Antigravity):** **Ejecutado** (7 de 7 escenarios probados de extremo a extremo contra servidor real `http://localhost:3000`, base de datos aislada `appsalon_func_test`, tokens sanitizados y receptor SMTP mock).
- **Comprobación Funcional en Navegador con JavaScript (UI Cliente):** **Pendiente** (Interacción DOM interactiva con [src/js/app.js](file:///src/js/app.js): navegación de pestañas de cita, datepicker en navegador y alertas dinámicas en cliente, pendiente de verificación manual o suite E2E de navegador).
- **Documentación Completa:** Disponible en [GUIA_ENTREGA_1.md](file:///GUIA_ENTREGA_1.md) y [database/README.md](file:///database/README.md).
- **Estado General de Entrega 1:** **Listo para Revisión Final del Propietario** (Pendiente de aprobación previa para merge a `main`).

### Verificación Funcional HTTP en Servidor Real (Antigravity):
Ejecución de extremo a extremo contra servidor PHP (`http://appsalon-web:3000`), base de datos aislada MySQL 8 (`appsalon_func_test`), validación previa de `SELECT DATABASE()`, timeouts de cURL y captura de tokens desde buzón SMTP de pruebas (`tests/smtp_mock_server.php` / `tests/functional_test_suite.php`). Evidencia sanitizada sin exposición de tokens.

| Escenario Funcional | Estado | Petición y Respuesta HTTP | Evidencia Técnica en Base de Datos / Buzón |
| :--- | :---: | :--- | :--- |
| **1. Registro de Cuenta** | **Ejecutado** | `POST /crear-cuenta` $\rightarrow$ `302 /mensaje` | Fila creada en `usuarios` con `confirmado=0`, `admin=0`, `token=NULL` (sin texto plano), `token_hash` presente. Correo recibido en buzón y hash coincidente (token no expuesto). |
| **2. Confirmación de Cuenta** | **Ejecutado** | `GET /confirmar-cuenta?token={tok}` $\rightarrow$ `200 OK` | `confirmado=1`, `token_hash=NULL`, `token_tipo=NULL` (consumo atómico verificado). Reintento con el mismo token consumido es rechazado como inválido. |
| **3. Login y Rate Limiting** | **Ejecutado** | Intentos 1-5: `200`<br>Intento 6: `429 Too Many Requests`<br>Login válido: `302 /cita` | Ventana fija de 15m; intento 6 bloqueado con `Retry-After` dinámico (segundos restantes). Límite por IP mitiga ataques desde el mismo origen. Login válido regenera sesión y permite acceso a `/cita` (`200 OK`). |
| **4. Recuperación de Contraseña** | **Ejecutado** | `POST /olvide` $\rightarrow$ `200 OK`<br>`POST /recuperar?token={tok}` $\rightarrow$ `302 /` | Correo de recuperación recibido en buzón con hash coincidente. Token consumido atómicamente (`token_hash=NULL`). Clave vieja rechazada (`200`); clave nueva permite login exitoso (`302`). |
| **5. Reserva de Cita (API)** | **Ejecutado** | `POST /api/citas` $\rightarrow$ `200 OK` JSON | Cita persistida en `citas` con `usuarioId` forzado de sesión, fecha futura válida y hora `10:30:00`. Dos servicios vinculados en `citasservicios` bajo transacción atómica. |
| **6. Eliminación Autorizada y Anti-IDOR** | **Ejecutado** | IDOR: `403 Forbidden`<br>Dueño: `302 /cita` | Intento de eliminación por un segundo cliente rechazado con 403 (cita permanece intacta en BD). Eliminación autorizada por el dueño borra cita y servicios en transacción. |
| **7. Logout Seguro por POST con CSRF** | **Ejecutado** | `GET /logout`: `302` (sesión activa)<br>`POST /logout`: `302 /` (sesión destruida) | Petición posterior a `/cita` es rechazada con `302 /` al quedar destruida la sesión. |

---

### Verificación en Navegador con JavaScript (UI Cliente):
| Área de Interfaz | Estado | Alcance / Detalle |
| :--- | :---: | :--- |
| Pestañas de Reserva (Pasos 1, 2 y 3) | `Pendiente` | Cambio dinámico de pasos en DOM gestionado por `src/js/app.js` |
| Carga de Servicios vía Fetch | `Pendiente` | Consumo asíncrono de `/api/servicios` y resaltado con clase `.seleccionado` |
| Restricciones en Datepicker | `Pendiente` | Bloqueo interactivo en UI de fines de semana y fechas pasadas |
| Alertas DOM Dinámicas | `Pendiente` | Inserción y remoción automática de alertas temporizadas en cliente |

---

### Controles de Seguridad y Pruebas Automatizadas (PHPUnit):
| :--- | :---: | :--- |
| Whitelist en Registro (`sincronizarRegistro`) | **Probado** | `UsuarioSeguridadTest::testRegistroBloqueaAsignacionMasivaDePrivilegios`, `SecurityIntegrationTest::testRegistroEnBaseDeDatosForzaPrivilegiosEnCero` |
| Reconstrucción de Sesión y Aislamiento de Roles (Admin -> Cliente) | **Probado** | `SecurityIntegrationTest::testTransicionDeRolAdminAClienteEnMismaSesionRevocaAccesoAdmin` |
| Centralización de Sesiones Seguras (`iniciar_sesion_segura`) | **Probado** | Centralizado en todos los controladores (`CitaController`, `LoginController`, `AdminController`, `ServicioController`, `APIController`) |
| Logout Seguro por Método POST con Token CSRF | **Probado** | `SecurityIntegrationTest::testLogoutExigePostConCsrfYDestruyeSesion`, formulario en `views/templates/barra.php` y ruta en `public/index.php` |
| Autenticación e IDOR en Citas (`/api/citas`, `/api/eliminar`) | **Probado** | `ApiSeguridadTest::testVisitanteNoPuedeCrearCitasSinAutenticacion`, `ApiSeguridadTest::testClienteNoPuedeEliminarCitaDeOtroClienteIdor`, `ApiSeguridadTest::testClientePuedeEliminarSuPropiaCita`, `ApiSeguridadTest::testReservaValidaGuardaCitaConUsuarioDeSesion` |
| Aislamiento Transaccional en Eliminación (`APIController::eliminar`) | **Probado** | `ApiSeguridadTest::testEliminarCitaExitosaNoEjecutaRollbackFalso` (aislamiento de redirección/terminación fuera del bloque try-catch transaccional) |
| Terminación Unificada con `AppTerminationException` (Sin bypass de pruebas) | **Probado** | `includes/funciones.php`, manejado en `public/index.php` y verificado en suite completa sin `PHPUNIT_RUNNING` |
| Protección contra Limpieza Destructiva en BD no autorizada | **Probado** | `tests/bootstrap.php` y `validar_base_datos_prueba()`, bloqueando bases que no terminen en `_test` |
| Consultas Preparadas y Verificación Estricta de Errores SQL | **Probado** | `models/ActiveRecord.php` verifica `execute()` y `affected_rows` en `crear()`, `actualizar()`, `eliminar()`; `SecurityIntegrationTest::testEntradasMaliciosasSeProcesanComoDatosEnConsultasPreparadas` |
| Eliminación de Texto Plano en Tokens y Supresión de Fallback Legacy | **Probado** | `Usuario::generarTokenSeguro()` almacena `null` en `token` y hash en `token_hash`; eliminado fallback en `buscarPorTokenSeguro()` |
| Consumo Atómico de Tokens por Hash, Propósito y Expiración | **Probado** | `Usuario::confirmarCuentaPorToken()`, `Usuario::restablecerPasswordPorToken()`, `testTokenConfirmacionUsoUnicoYRechazoConcurrente`, `testTokenPropositoIncorrectoEsRechazado`, `testTokenSustituidoPorUnoNuevoInvalidaElAnterior`, `testExpiracionEntreLecturaYActualizacionRechazaConsumo` |
| Emisión Atómica de Tokens Condicionada al Estado de Cuenta | **Probado** | `Usuario::generarYPersistirTokenRecuperacion()`, `Usuario::generarYPersistirTokenConfirmacion()`, `SecurityIntegrationTest::testEmisionTokenRecuperacionConcurrenteNoRevierteCambioDePassword`, `SecurityIntegrationTest::testEmisionTokenConfirmacionConcurrenteNoRevierteConfirmacion` |
| Validación y Protección CSRF Global (Formularios y Fetch JS) | **Probado** | `CsrfTest` (8 casos de prueba cubriendo nulos, vacíos, arrays, cabeceras, POST y excepción 403) |
| Rate Limiting Atómico en MySQL con Ventana Temporal y Código 429 | **Probado** | `classes/RateLimiter.php` con `INSERT ... ON DUPLICATE KEY UPDATE` serializado en InnoDB, `SecurityIntegrationTest::testRateLimiterVentanaYBloqueo429` |
| Rate Limiter: Umbral Exacto Intento por Intento (`intentos >= ?`) | **Probado** | `SecurityIntegrationTest::testRateLimiterUmbralExactoIntentoPorIntento` (bloqueo exacto en intento 5, intentos 1-4 permitidos) |
| Rate Limiter: Admisión Atómica Previa a Bcrypt (`admitirIntentoLogin`) | **Probado** | `LoginController::login()` reserva intento atómicamente antes de verificar credenciales costosas; limpieza en login exitoso |
| Rate Limiter: Presupuesto Compartido de IP y Limpieza por Cuenta | **Probado** | `SecurityIntegrationTest::testLoginExitosoNoReiniciaPresupuestoIpAtaqueMultiplesCuentas` (login exitoso en cuenta de control no reinicia contador compartido de IP ante ataques intercalados) |
| Rate Limiter: Concurrencia de Procesos CLI en Controlador con Señal, Verificación Observada y Timeout | **Probado** | `SecurityIntegrationTest::testRateLimiterProcesosSimultaneosSincronizadosFlujoRealLogin` (Ejecución directa del controlador `LoginController::login()` desde 10 subprocesos CLI de PHP independientes vía `proc_open` con sesiones y conexiones MySQL separadas —no prueba HTTP de servidor web—. Verificación previa de existencia, confirmación y hash del usuario; barrera sincronizada STDIN con señal READY; exactamente 5 admitidos con llamada real observada a `comprobarPasswordAndVerificado` y 5 rechazados con 429 con cero verificaciones de contraseña; tiempo límite de 10s y limpieza garantizada de subprocesos en bloque finally) |
| Rate Limiter: Postura Fail-Secure ante Fallos SQL | **Probado** | `SecurityIntegrationTest::testRateLimiterManejoFalloSqlFailSecure` (retorna bloqueo ante conexión cerrada o fallo) |
| Supresión de Generación de Tokens bajo Rate Limiting en `/olvide` | **Probado** | `SecurityIntegrationTest::testOlvideConRateLimitBloqueaSinGenerarNiPersistirToken` (detiene flujo antes de token/email ante 429) |
| Control de Fallo en Persistencia Previa al Envío de Correo | **Probado** | `SecurityIntegrationTest::testOlvideNoIntentaEnviarCorreoSiFallaPersistenciaToken`, `SecurityIntegrationTest::testReenviarConfirmacionNoEnviaCorreoSiCuentaYaEstaConfirmada` |
| Reenvío de Confirmación para Cuentas No Confirmadas (`/reenviar-confirmacion`) | **Probado** | `SecurityIntegrationTest::testReenviarConfirmacionGeneraTokenNuevoSoloParaCuentasNoConfirmadas`, vista `views/auth/reenviar-confirmacion.php` |
| Correo Transaccional Desacoplado y Validación de Objeto PHPMailer | **Probado** | `classes/Email.php` despacha a `$this->email`, remitente configurable por `EMAIL_FROM`, `prepararMailer()` construye objeto real y se verifica destinatario, remitente, asunto y enlace con token en `EmailTest` |
| Transacciones ACID Reales en Citas con Rollback Integral | **Probado** | `SecurityIntegrationTest::testFallaAlGuardarServiciosRevierteCitaCompletaEnFlujoRealApi` (falla provocada con trigger MySQL tras inserción de cita, revirtiendo cita completa) |
| Validación Estricta de Tipos Escalares (Anti-Array Injection) | **Probado** | `ApiSeguridadTest::testLoginRechazaCargaNoEscalarTipoInvalido`, constructores y validaciones en `models/Usuario.php` |
| Validación Servidor: Fechas (futuras sin fin de semana), Horas estrictas `HH:MM` y Desduplicación | **Probado** | `ApiSeguridadTest::testGuardarRechazaReservaMismoDiaOFechaPasada`, `testGuardarRechazaHorarioInvalidoPasadoLimite`, `testGuardarRechazaFormatoHoraConSegundos`, `testGuardarDesduplicaServiciosRepetidosPoliticaExplicita` |
| Migraciones Versionadas: Comparación Exhaustiva de Esquema y Datos | **Probado** | `MigrationTest::testActualizacionDesdeInstalacionPreviaPreservaDatosYGeneraMismoEsquemaQueFresh` (ejecutado por `migrador.php up`, omitiendo 001, aplicando `002_innodb_and_rate_limit_window.sql`, preservando 100% de datos de clientes, administradores, citas y servicios, e igualando 100% esquema fresh en columnas, nulabilidad, defaults, índices y motores InnoDB) |
| Integridad del Historial de Migraciones y Manejo de Estado Parcial | **Probado** | `MigrationTest::testMigradorFallaConEstadoParcialCuandoInsercionEnHistorialFalla` (falla forzada por trigger en inserción de historial, salida con código 1, mensaje explicativo de estado parcial sin imprimir [OK]) |
| Detección de Errores Intermedios y Salida no Cero en Migrador | **Probado** | `MigrationTest::testMigradorFallaConCodigoDistintoDeCeroAnteErrorSqlIntermedio`, `database/migrador.php` y `database/README.md` |

---

## Fases Posteriores (Pendientes de Inicio)

| Fase | Alcance Principal | Estado |
| :--- | :--- | :---: |
| **Fase 2** | Migraciones y separación gradual de responsabilidades | `Pendiente` |
| **Fase 3** | Profesionales, servicios con duración, horarios, descansos y bloqueos | `Pendiente` |
| **Fase 4** | Disponibilidad real y prevención de reservas simultáneas | `Pendiente` |
| **Fase 5** | Interfaz accesible de reservas y panel administrativo | `Pendiente` |
| **Fase 6** | Pagos (Stripe / Mercado Pago), notificaciones multicanal y reportes | `Pendiente` |

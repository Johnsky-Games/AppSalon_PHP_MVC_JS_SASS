# Registro de Progreso del Proyecto AppSalon

Este archivo mantiene la trazabilidad estricta del avance del proyecto de acuerdo con la metodología de entregas auditables por **ChatGPT** y aprobación del **Propietario**.

> **Estados posibles:**
> - `Pendiente`: Requerimiento identificado sin comenzar.
> - `En Desarrollo`: Trabajo en curso.
> - `Implementado`: Código escrito y compilado.
> - `Probado`: Pruebas automatizadas o manuales ejecutadas exitosamente con evidencia.
> - `Auditado`: Revisado formalmente por la auditoría independiente externa (ChatGPT).

---

## Entrega 1: Seguridad y Consistencia del Sistema Actual

- **Rama:** `feature/seguridad-y-consistencia-inicial`
- **Commit Base:** `e816154b96985c2773e4fb418bbbf3e92530cca3`
- **Commit de Entrega (Revisión 3):** `d67adf4e86d8e617715b565b71bf2b624e274a0e`
- **Estado General:** **Probado** (39 pruebas automatizadas, 155 aserciones, 0 fallos, 0 errores, 0 advertencias)

| Tarea / Control de Seguridad | Estado | Evidencia / Pruebas Asociadas |
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
| Validación y Protección CSRF Global (Formularios y Fetch JS) | **Probado** | `CsrfTest` (8 casos de prueba cubriendo nulos, vacíos, arrays, cabeceras, POST y excepción 403) |
| Rate Limiting Atómico en MySQL con Ventana Temporal y Código 429 | **Probado** | `classes/RateLimiter.php` con `INSERT ... ON DUPLICATE KEY UPDATE` serializado en InnoDB, `SecurityIntegrationTest::testRateLimiterVentanaYBloqueo429` |
| Rate Limiter: Umbral Exacto Intento por Intento (`intentos >= ?`) | **Probado** | `SecurityIntegrationTest::testRateLimiterUmbralExactoIntentoPorIntento` (bloqueo exacto en intento 5, intentos 1-4 permitidos) |
| Rate Limiter: Concurrencia Real con Dos Conexiones `mysqli` Independientes | **Probado** | `SecurityIntegrationTest::testRateLimiterConexionesIndependientesConcurrencia` (serialización y bloqueo cruzado verificado) |
| Rate Limiter: Postura Fail-Secure ante Fallos SQL | **Probado** | `SecurityIntegrationTest::testRateLimiterManejoFalloSqlFailSecure` (retorna bloqueo ante conexión cerrada o fallo) |
| Supresión de Generación de Tokens bajo Rate Limiting en `/olvide` | **Probado** | `SecurityIntegrationTest::testOlvideConRateLimitBloqueaSinGenerarNiPersistirToken` (detiene flujo antes de token/email ante 429) |
| Reenvío de Confirmación para Cuentas No Confirmadas (`/reenviar-confirmacion`) | **Probado** | `SecurityIntegrationTest::testReenviarConfirmacionGeneraTokenNuevoSoloParaCuentasNoConfirmadas`, vista `views/auth/reenviar-confirmacion.php` |
| Transacciones ACID Reales en Citas con Rollback Integral | **Probado** | `SecurityIntegrationTest::testFallaAlGuardarServiciosRevierteCitaCompletaEnFlujoRealApi` (falla provocada con trigger MySQL tras inserción de cita, revirtiendo cita completa) |
| Validación Estricta de Tipos Escalares (Anti-Array Injection) | **Probado** | `ApiSeguridadTest::testLoginRechazaCargaNoEscalarTipoInvalido`, constructores y validaciones en `models/Usuario.php` |
| Validación Servidor: Fechas (futuras sin fin de semana), Horas estrictas `HH:MM` y Desduplicación | **Probado** | `ApiSeguridadTest::testGuardarRechazaReservaMismoDiaOFechaPasada`, `testGuardarRechazaHorarioInvalidoPasadoLimite`, `testGuardarRechazaFormatoHoraConSegundos`, `testGuardarDesduplicaServiciosRepetidosPoliticaExplicita` |
| Migraciones Versionadas Reproducibles (001 + 002) y Rollback Simétrico | **Probado** | `MigrationTest::testInstalacionDesdeCeroYActualizacionIncrementalGeneranMismoEsquema` (compara esquemas y motores fresh vs upgrade) |
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

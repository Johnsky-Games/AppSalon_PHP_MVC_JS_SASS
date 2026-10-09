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
- **Estado General:** **Probado** (Listo para auditoría de ChatGPT)

| Tarea / Control de Seguridad | Estado | Evidencia / Pruebas Asociadas |
| :--- | :---: | :--- |
| Whitelist en Registro (`sincronizarRegistro`) | **Probado** | `UsuarioSeguridadTest::testRegistroBloqueaAsignacionMasivaDePrivilegios`, `SecurityIntegrationTest::testRegistroEnBaseDeDatosForzaPrivilegiosEnCero` |
| Autenticación e IDOR en Citas (`/api/citas`, `/api/eliminar`) | **Probado** | `ApiSeguridadTest::testVisitanteNoPuedeCrearCitasSinAutenticacion`, `ApiSeguridadTest::testClienteNoPuedeEliminarCitaDeOtroClienteIdor`, `ApiSeguridadTest::testClientePuedeEliminarSuPropiaCita` |
| Detención estricta en `isAuth()` e `isAdmin()` | **Probado** | Implementado con `detener_ejecucion()` y redirección/código HTTP correspondiente |
| Consultas Preparadas en Capa de Datos (`ActiveRecord`, `Usuario`, `AdminController`) | **Probado** | `SecurityIntegrationTest::testEntradasMaliciosasSeProcesanComoDatosEnConsultasPreparadas` |
| Validación y Protección CSRF Global (Formularios y Fetch JS) | **Probado** | `CsrfTest` (7 casos de prueba cubriendo nulos, vacíos, arrays, cabeceras y POST) y `ApiSeguridadTest::testOperacionGuardarRechazaPeticionSinCsrfValido` |
| Endurecimiento de Sesiones (Cookies seguras, `session_regenerate_id`, logout limpio) | **Probado** | Implementado en `includes/funciones.php` y `LoginController` |
| Tokens Criptográficos con Hash, Expiración y Consumo Atómico | **Probado** | `UsuarioSeguridadTest::testGeneracionDeTokenCriptograficoConHashYExpiracion`, `SecurityIntegrationTest::testTokenExpiradoOReutilizadoSeRechaza` |
| Rate Limiting Atómico en Autenticación y Recuperación (`intentos_login`) | **Probado** | `SecurityIntegrationTest::testRateLimiterRegistraYBloqueaTrasMaximosIntentos` |
| Atomicidad Transaccional en Citas (`begin_transaction`, `commit`, `rollback`) | **Probado** | `SecurityIntegrationTest::testFallaAlGuardarServiciosRevierteCitaCompletaEnTransaccion` |
| Validación de Reglas en Servidor (Fechas pasadas, fines de semana, rangos de hora y servicios existentes) | **Probado** | Implementado en `APIController::guardar()`, verificado en `ApiSeguridadTest` |
| Migraciones Versionadas y Mecanismo de Rollback | **Probado** | `database/migrations/001_security_hardening.sql`, `001_security_hardening_rollback.sql`, `database/migrador.php` |

---

## Fases Posteriores (Pendientes de Inicio)

| Fase | Alcance Principal | Estado |
| :--- | :--- | :---: |
| **Fase 2** | Migraciones y separación gradual de responsabilidades | `Pendiente` |
| **Fase 3** | Profesionales, servicios con duración, horarios, descansos y bloqueos | `Pendiente` |
| **Fase 4** | Disponibilidad real y prevención de reservas simultáneas | `Pendiente` |
| **Fase 5** | Interfaz accesible de reservas y panel administrativo | `Pendiente` |
| **Fase 6** | Pagos (Stripe / Mercado Pago), notificaciones multicanal y reportes | `Pendiente` |

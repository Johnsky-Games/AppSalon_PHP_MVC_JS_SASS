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
- **Commit de Entrega (Revisión 2):** `52578187a3055c6f17199d9e0b175be579925daf`
- **Estado General:** **Probado** (29 pruebas automatizadas, 86 aserciones, 0 fallos, 0 errores)

| Tarea / Control de Seguridad | Estado | Evidencia / Pruebas Asociadas |
| :--- | :---: | :--- |
| Whitelist en Registro (`sincronizarRegistro`) | **Probado** | `UsuarioSeguridadTest::testRegistroBloqueaAsignacionMasivaDePrivilegios`, `SecurityIntegrationTest::testRegistroEnBaseDeDatosForzaPrivilegiosEnCero` |
| Reconstrucción de Sesión y Aislamiento de Roles (Admin -> Cliente) | **Probado** | `SecurityIntegrationTest::testTransicionDeRolAdminAClienteEnMismaSesionRevocaAccesoAdmin` |
| Centralización de Sesiones Seguras (`iniciar_sesion_segura`) | **Probado** | Centralizado en todos los controladores (`CitaController`, `LoginController`, `AdminController`, `ServicioController`, `APIController`) |
| Logout Seguro por Método POST con Token CSRF | **Probado** | `SecurityIntegrationTest::testLogoutExigePostConCsrfYDestruyeSesion`, formulario en `views/templates/barra.php` y ruta en `public/index.php` |
| Autenticación e IDOR en Citas (`/api/citas`, `/api/eliminar`) | **Probado** | `ApiSeguridadTest::testVisitanteNoPuedeCrearCitasSinAutenticacion`, `ApiSeguridadTest::testClienteNoPuedeEliminarCitaDeOtroClienteIdor`, `ApiSeguridadTest::testClientePuedeEliminarSuPropiaCita`, `ApiSeguridadTest::testReservaValidaGuardaCitaConUsuarioDeSesion` |
| Terminación Unificada con `AppTerminationException` (Sin bypass de pruebas) | **Probado** | `includes/funciones.php`, manejado en `public/index.php` y verificado en suite completa sin `PHPUNIT_RUNNING` |
| Protección contra Limpieza Destructiva en BD no autorizada | **Probado** | `tests/bootstrap.php` y `validar_base_datos_prueba()`, bloqueando bases que no terminen en `_test` |
| Consultas Preparadas y Verificación Estricta de Errores SQL | **Probado** | `models/ActiveRecord.php` verifica `execute()` y `affected_rows` en `crear()`, `actualizar()`, `eliminar()`; `SecurityIntegrationTest::testEntradasMaliciosasSeProcesanComoDatosEnConsultasPreparadas` |
| Eliminación de Texto Plano en Tokens y Supresión de Fallback Legacy | **Probado** | `Usuario::generarTokenSeguro()` almacena `null` en `token` y hash en `token_hash`; eliminado fallback en `buscarPorTokenSeguro()` |
| Consumo Atómico de Tokens por Hash, Propósito y Expiración | **Probado** | `Usuario::confirmarCuentaPorToken()`, `Usuario::restablecerPasswordPorToken()`, `testTokenConfirmacionUsoUnicoYRechazoConcurrente`, `testTokenPropositoIncorrectoEsRechazado`, `testTokenSustituidoPorUnoNuevoInvalidaElAnterior`, `testExpiracionEntreLecturaYActualizacionRechazaConsumo` |
| Validación y Protección CSRF Global (Formularios y Fetch JS) | **Probado** | `CsrfTest` (8 casos de prueba cubriendo nulos, vacíos, arrays, cabeceras, POST y excepción 403) |
| Rate Limiting Atómico en MySQL con Ventana Temporal y Código 429 | **Probado** | `classes/RateLimiter.php` con `INSERT ... ON DUPLICATE KEY UPDATE` serializado en InnoDB, `SecurityIntegrationTest::testRateLimiterVentanaYBloqueo429` |
| Transacciones ACID Reales en Citas con Rollback Integral | **Probado** | `SecurityIntegrationTest::testFallaAlGuardarServiciosRevierteCitaCompletaEnFlujoRealApi` (falla provocada con trigger MySQL tras inserción de cita, revirtiendo cita completa) |
| Validación Servidor: Fechas (mínimo mañana), Horas (10:00-18:00) y Desduplicación | **Probado** | `ApiSeguridadTest::testGuardarRechazaReservaMismoDiaOFechaPasada`, `testGuardarRechazaHorarioInvalidoPasadoLimite`, `testGuardarDesduplicaServiciosRepetidosPoliticaExplicita` |
| Migraciones Versionadas con Conversión a InnoDB y Rollback | **Probado** | `database/migrations/001_security_hardening.sql` (convierte tablas a InnoDB), `001_security_hardening_rollback.sql`, `database/migrador.php` |

---

## Fases Posteriores (Pendientes de Inicio)

| Fase | Alcance Principal | Estado |
| :--- | :--- | :---: |
| **Fase 2** | Migraciones y separación gradual de responsabilidades | `Pendiente` |
| **Fase 3** | Profesionales, servicios con duración, horarios, descansos y bloqueos | `Pendiente` |
| **Fase 4** | Disponibilidad real y prevención de reservas simultáneas | `Pendiente` |
| **Fase 5** | Interfaz accesible de reservas y panel administrativo | `Pendiente` |
| **Fase 6** | Pagos (Stripe / Mercado Pago), notificaciones multicanal y reportes | `Pendiente` |

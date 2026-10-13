# Entrega — Identidades, clientes y equipo (2026-10-13)

Rama `claude/blissful-hopper-f5k7a0`, partiendo de `52c82e0` (`feature/responsividad-correos-errores`; **no** de `main`).
No se fusionó a `main` ni se desplegó. Los hechos de esta tabla están comprobados con salida real; lo no comprobado figura en «Límites».

## 1. Diagnóstico reproducido (prioridad 1 y 2)

| Escenario | Antes | Ahora |
|---|---|---|
| Esquema al día, cliente registrado → confirmado → login | funcionaba (302 → `/cita`) | igual |
| Base **sin migración 010** (código nuevo sobre BD vieja) | **403 «cuenta de acceso inactiva»** para un cliente verificado | **503** + `Retry-After`; el log indica `ESQUEMA DESACTUALIZADO` |
| Cuenta con acceso desactivado / membresía suspendida | 403 genérico | 403 «cuenta suspendida» (cliente) o «acceso al equipo suspendido» |
| Cuenta sin confirmar | flujo de reenvío | igual (sin mencionar roles laborales) |

**Causa raíz (comprobada):** `AuthorizationService::contexto()` devolvía `null` ante *cualquier* error SQL y `LoginController` lo trataba como «inactiva». Es un problema
de datos/esquema *y* de código: el código ocultaba el fallo técnico. Corrección: el contexto lanza `ServiceUnavailableException` (503) y sólo un estado explícito suspende.
Hipótesis **no** comprobada: que la base del propietario esté realmente sin la migración 010 (no se pudo inspeccionar su máquina); la guía de actualización incluye los comandos de verificación.

**Duplicado de profesionales (comprobado en código y en pruebas):** el formulario enviaba un POST sin clave de operación ni restricción de negocio; dos envíos → dos `INSERT`.

## 2. Qué se entregó

| Área | Archivos principales |
|---|---|
| Acceso/clientes | `services/AuthorizationService.php`, `controllers/LoginController.php`, `repositories/UsuarioRepository.php`, migración **016** |
| Idempotencia/duplicados | `classes/Idempotencia.php`, `classes/Transaccion.php`, `classes/Secretos.php`, migración **017** (+ `.precheck.php`), `src/js/ui.js`, `scripts/diagnosticar_duplicados.php` |
| Portales/perfiles | `includes/funciones.php` (`portal_sesion`, `puede`, `isAuth`), `views/auth/login-equipo.php`, `views/layout.php`, `views/perfil/index.php`, `services/ProfileService.php` |
| Colaboradores/RR. HH. | migración **018**, `repositories/ColaboradorRepository.php`, `services/ColaboradorService.php`, `controllers/EquipoController.php`, `controllers/InvitacionController.php`, `views/equipo/*`, `repositories/CorreoSalidaRepository.php`, `services/CorreoSalidaService.php`, `scripts/procesar_correos.php` |
| Primer propietario | `scripts/instalar.php`, `scripts/crear_propietario.php` (envoltura), servicio `instalador` en `docker-compose.yml` |
| CRM | `controllers/CrmController.php`, `services/CrmService.php`, `repositories/CrmRepository.php`, `views/crm/*`, `docs/CRM_CLIENTES.md` |
| CI | `.github/workflows/ci.yml` (rama `claude/**`, `workflow_dispatch`, `sodium`, paso del instalador), `tests/run_browser_e2e.sh` |

Documentos: `ARQUITECTURA_IDENTIDADES.md` (decisión, contrato de idempotencia, matrices de roles y estados), `GUIA_ACTUALIZACION_IDENTIDADES.md` (Windows Git Bash/Compose, respaldo, `.env`),
`CRM_CLIENTES.md` (definiciones de métricas), `database/README.md`.

## 3. Evidencia ejecutada

| Verificación | Resultado |
|---|---|
| PHPUnit local (PHP 8.3, MySQL 8.0.46, orden aleatorio) | **OK — 462 pruebas, 5361 aserciones** (línea base antes de cambios: 323 / 3617) |
| Pruebas nuevas | `AccesoCliente` 8 · `IdempotenciaAltas` 23 · `ColaboradoresPortales` 35 · `InstaladorPropietario` 10 · `PerfilPortales` 4 · CRM 58 (subagente) |
| Mutación | Con la idempotencia de profesionales desactivada, 3 pruebas fallan; restaurada, pasan |
| E2E doble envío en Chromium (`tests/double_submit_e2e.js`) | **18/18**: cliente, profesional y colaborador con doble clic, Enter repetido y 3–4 POST simultáneos |
| Regresión E2E de navegador completa (nativa, sin Docker) | 7 etapas con salida 0: negocio+SMTP, persistencia, contratos HTTP, errores, navegación, responsive (**549 comprobaciones, 0 fallos**), correos |
| Instalador sobre HTTP real | enlace → aceptar → contraseña propia → `/equipo/login` → `/admin/equipo`; token reutilizado → 410; segunda ejecución no cambia nada |
| Respaldo/restauración | 35 tablas, conteos idénticos, índices funcionales y UNIQUE conservados |
| **CI GitHub (PHP 8.2 + MySQL 8 + Apache)** | SHA `07eb1ae`: [run 38435530180](https://github.com/Johnsky-Games/AppSalon_PHP_MVC_JS_SASS/actions/runs/38435530180) **success** · SHA `58e985a`: [run 38435914232](https://github.com/Johnsky-Games/AppSalon_PHP_MVC_JS_SASS/actions/runs/38435914232) **success** (lint, migraciones 001–018, instalador, PHPUnit, imagen Docker con `sodium`, E2E en Apache, respaldo/restauración) |

Pruebas existentes **modificadas** (contrato cambiado a propósito, no debilitadas): `EmailTest` (la invitación usa `/equipo/aceptar`), `SecurityIntegrationTest` (el administrador entra por el portal del equipo y se
verifica que el portal de clientes lo rechaza), `ui_navigation_test.js` (enlaces nuevos), logins de los E2E de navegador, y una prueba de recordatorios que dependía del orden
(también fallaba **antes** de mis cambios en la línea base).

## 4. Matriz de aceptación (resumen)

| # | Criterio | Verificado por |
|---|---|---|
| 1–2 | Instalación nueva: un propietario; base existente sin cambios | `InstaladorPropietarioIntegrationTest` |
| 3 | Registro malicioso (admin/rol/colaborador) → solo cliente | `AccesoClienteIntegrationTest` |
| 4–5 | Registro → confirmación → cliente sin admin; no verificado/suspendido/fallo SQL | `AccesoCliente…`, E2E Chromium, HTTP real |
| 6–7 | Cliente sin membresía laboral no entra al equipo; invitación pendiente/expirada/revocada sin privilegios | `ColaboradoresPortales…` |
| 8 | Un solo uso; propósito invitación ≠ recuperación | `ColaboradoresPortales…`, `EmailTest` |
| 9–10 | Asistente de profesional atómico; ficha heredada conserva reservas | `ColaboradoresPortales…` |
| 11–12 | Cambio de rol/suspensión revoca sesión; último propietario protegido | `ColaboradoresPortales…`, `EquipoIntegrationTest` |
| 13–14 | Ficha laboral solo `rh.gestionar`; perfiles sin filtración entre portales | `ColaboradoresPortales…`, `PerfilPortales…` |
| 15–16 | Métricas CRM contra SQL independiente | `CrmIntegrationTest` (subagente; reverificada en la suite integrada) |
| 17 | Responsive 320–1440, teclado, reduced-motion, sin JS | E2E responsive/navegación/Chromium |
| 18 | Correos reflejan el portal | `EmailTest`, `ColaboradoresPortales…` |
| 19 | Migración desde esquema actual = fresh; respaldo/restauración | `MigrationTest`, round-trip de 35 tablas |
| 20 | Reserva/pago/inventario/worker intactos | suite completa + E2E de negocio |
| 21–26 | Doble clic, concurrencia, reintento tras commit, claves cruzadas, correo equivalente, rollback | `IdempotenciaAltas…`, `ColaboradoresPortales…`, E2E 18/18 |
| 27 | Duplicados existentes: diagnóstico sin borrar | `scripts/diagnosticar_duplicados.php` (verificado con fixtures) |

## 5. QA independiente

Un subagente de QA independiente (sin tocar código de la aplicación) escribió 203 pruebas adversariales en `tests/Qa/` (concurrencia con procesos reales, HTTP real, navegador, esquema, correo, instalador, privacidad). Informe completo: `docs/QA_INDEPENDIENTE_IDENTIDADES.md`.

- **Primera pasada (commit `4e542af`):** 22 rojas intencionadas = 18 defectos distintos (`DEF-01`…`DEF-18`).
- **Corrección:** 16 defectos corregidos en la aplicación, con regresiones propias en `tests/Integration/CorreccionesQaIntegrationTest.php` (9 pruebas, 56 aserciones).
- **Riesgo aceptado y documentado (2):** `DEF-13` y `DEF-16`; sus pruebas quedan omitidas con la justificación en el propio test y en `GUIA_ACTUALIZACION_IDENTIDADES.md` §10.
- **Re-ejecución tras las correcciones (local, PHP 8.3.6 + MySQL 8.0.46):** suite por defecto `OK (471 tests, 5417 assertions)` en orden aleatorio; suite QA `OK, but some tests were skipped! Tests: 203, Assertions: 1521, Skipped: 3`, 0 fallos. Una de las expectativas del QA (`AutorizacionHttpTest`, sesión forjada) se ajustó a `[302,401,403]` porque las tres respuestas deniegan el acceso; el cambio es visible en el diff.
- Cómo correrla: `DB_NAME=appsalon_qa_test` (base migrada propia) y `php -d variables_order=EGPCS vendor/bin/phpunit --do-not-cache-result tests/Qa`. No forma parte del CI por defecto (≈3 min).

## 6. Límites y pendientes (sin ocultar)

* El E2E de navegador que ejecuté fuera de CI usa `php -S` + un adaptador de `docker exec`, no Apache/Docker; el de CI (Apache real) sí pasó.
* Los horarios del profesional no están dentro del asistente: se configuran tras aceptar. Una ficha nueva queda **inactiva** hasta la aceptación.
* Suspender a un profesional con citas futuras se **bloquea**; no hay reasignación asistida de citas.
* No hay aviso de homónimos en `/profesionales/crear` (solo en el asistente); en ese alta heredada la protección es la idempotencia.
* CRM: sin auditoría de exportaciones; el consentimiento comercial no guarda versión; fechas `DATETIME` de MySQL se interpretan tal cual (servidor en UTC).
* `APP_KEY` es obligatoria en producción; sin ella el instalador se detiene y las altas fallan cerradas (503).
* Outbox: un evento único por causa evita dobles correos por clics/reintentos, pero no garantiza entrega SMTP «exactamente una vez».
* Cada `puede()` consulta la base (la navegación hace ~15 consultas por página); no se añadió caché para no enmascarar cambios de permisos en la misma petición.
* No se tocó: pasarela real, nómina/legalidad laboral de Ecuador, WhatsApp/SMS, despliegue público. Pruebas aprobadas no equivalen a certificación de producción ni cumplimiento legal.
* Corregido de paso: la plantilla y Compose usaban `EMAIL_PASS` y el código leía `EMAIL_PASSWORD`; ahora se aceptan ambos.

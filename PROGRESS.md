# Registro de Progreso del Proyecto AppSalon

Este archivo mantiene la trazabilidad estricta del avance del proyecto de acuerdo con la metodología de entregas auditables por **ChatGPT** (revisión estática de código) y ejecución/verificación por **Antigravity** (desarrollo y pruebas dinámicas automatizadas), sujeto a la aprobación final del **Propietario**.

> **Roles y Criterios:**
> - **Desarrollo y Pruebas Automatizadas (Antigravity):** Implementación de código y ejecución de la suite completa de pruebas unitarias e integrales (172 pruebas, 2046 aserciones en PHPUnit 10.5.66), escenarios funcionales HTTP y suite E2E en navegador real Headless Chrome (`tests/verificar_navegador.ps1` y `tests/browser_e2e_test.js`).
> - **Revisión Estática Externa (ChatGPT):** Auditoría independiente de código de aplicación, arquitectura por capas, contratos transaccionales y revisión estática de scripts Bash.
> - **Aprobación Final y Despliegue (Propietario):** Decisión formal sobre fusiones hacia `main` y despliegues en producción.

---

## Cierre Técnico: Consolidación de Seguridad, CI/CD, Docker, Respaldos y Operaciones (REABIERTO)

- **Rama Base / Cierre Revocado:** `feature/cierre-tecnico` en `d4124f27abcb46fb947f158cf4a44d04d481e6eb` (cierre revocado por auditoría externa).
- **Rama de Integración Activa:** `feature/correcciones-auditoria-integral`
- **Código Auditado Externamente:** `f33076c304e766239b1bfc13d9dde1927d749827`
- **Cierre Documental Revocado:** `d4124f27abcb46fb947f158cf4a44d04d481e6eb`
- **Estado General:** **RESOLUCIÓN Y VERIFICACIÓN COMPLETA DE HALLAZGOS DE AUDITORÍA EXTERNA — 6 Especialidades Integradas y Verificadas**
- **Matriz de Negocio:** Documentada en `docs/MATRIZ_ACEPTACION_NEGOCIO.md` con trazabilidad de requisitos, reglas, pruebas y decisiones del negocio.

### 0. Consolidación de Correcciones de Especialistas

#### Especialista 1: Reservas, Estados de Citas y Agenda (Fase 4B)
- **Migración `007_citas_estado_cancelacion.sql` y rollback:** Columnas `estado`, `cancelado_por`, `cancelado_at`, `motivo_cancelacion` con índices `idx_citas_estado` e `idx_citas_prof_fecha_estado`.
- **Cancelación Lógica Idempotente:** `CitaRepository::cancelarCitaLogica()` libera disponibilidad manteniendo filas en `citas`, `citasservicios` y `pagos`. Prohíbe cancelar citas pasadas (HTTP 422).
- **Protección de Agenda y Transacciones InnoDB:** Modificaciones en `horarios`, `descansos` o `bloqueos` mediante `replaceHorarios()`, `createDescanso()` y `createBloqueo()` adquieren bloqueos pesimistas y rechazan (HTTP 422) cualquier alteración que colisione con citas confirmadas futuras.
- **Pruebas:** `tests/Integration/CitaCancelacionAgendaIntegrationTest.php` (6 tests, 58 aserciones, salida 0).

#### Especialista 2: Seguridad HTTP y Frontend
- **Erradicación de XSS:** Eliminación total de `.innerHTML` inseguro en `src/js/app.js` y `public/build/js/app.js`. Reemplazado por nodos DOM estructurados (`document.createElement`, `document.createTextNode` y `.textContent`).
- **Sanitización de Salida:** Verificación estricta de que datos de usuario y catálogo se representen como texto plano.
- **Pruebas:** `tests/Integration/SeguridadFrontendIntegrationTest.php` (5 tests, 229 aserciones, salida 0).

#### Especialista 3: Pagos, Autorización e Idempotencia (Fase 6C)
- **Huella de Idempotencia y Protección Cross-Tenant:** `Pago::coincideConOperacion()` valida `usuario_id`, `cita_id` y `monto`. Si la clave de idempotencia existe para otra operación, responde HTTP 409 sin filtrar datos ajenos.
- **Estado Terminal Reembolsado:** Una vez en `reembolsado`, webhooks tardíos no pueden revertirlo. Rechazo de reembolsos parciales (HTTP 422).
- **Orden Uniforme de Bloqueo:** Siempre `citas` (`FOR UPDATE`) primero, luego `pagos` (`FOR UPDATE`), previniendo interbloqueos (deadlocks).
- **Protección de Producción:** Bloqueo de `MockPaymentGateway` y secretos por defecto cuando `APP_ENV=production`.
- **Pruebas:** `tests/Integration/PagoIdempotenciaSeguridadIntegrationTest.php` (5 tests, 56 aserciones, salida 0).

#### Especialista 4: Notificaciones y Recuperación de Workers (Fase 6A)
- **Migración `008_notificaciones_outbox_lease.sql` y rollback:** Columnas `worker_token`, `lease_expires_at` con índice `idx_notificaciones_lease`.
- **Arrendamiento Atómico de Workers (Worker Lease):** Adquisición mediante `adquirirParaEnvio()`, recuperación de workers atascados tras expiración de lease, exclusión de workers zombis con token caduco.
- **Descarte de Notificaciones Obsoletas:** Citas canceladas y recordatorios transcurridos se descartan automáticamente sin despachar correos.
- **Documentación:** `docs/NOTIFICACIONES_WORKER.md`.
- **Pruebas:** `tests/Integration/NotificacionesWorkerIntegrationTest.php` (5 tests, 19 aserciones, salida 0).

#### Especialista 5: Reportes e Historial Financiero (Fase 6B)
- **Persistencia Contable Desacoplada:** Consultas financieras alimentadas directamente desde la tabla `pagos`, garantizando persistencia inmutable de pagos incluso si las citas fueron canceladas o los servicios fueron eliminados del catálogo.
- **Distinción Cita vs Cobro:** Filtro explícito `criterio_fecha = 'servicio'` (fecha operativa de agenda) vs `criterio_fecha = 'cobro'` (fecha contable de recaudación).
- **Controladores y Vistas:** `AdminReporteController` (`/admin/reportes`, `/admin/reportes/exportar`), `views/admin/reportes.php`, exportación CSV con snapshots históricos inmutables.
- **Pruebas:** `tests/Integration/ReportesFinancierosIntegrationTest.php` (4 tests, 62 aserciones, salida 0).

#### Especialista 6: Docker, Respaldos, Restauración y CI
- **Front Controller y Apache:** `public/.htaccess` con mod_rewrite seguro (`RewriteRule ^ index.php [QSA,L]`). `Dockerfile` y `docker/apache.conf` con `DocumentRoot /var/www/html/public`, `AllowOverride All` y extensión `mbstring`.
- **Integridad Criptográfica de Respaldos:** `scripts/backup_db.php` genera manifiesto estructurado con hash SHA-256. `scripts/restore_db.php` valida pre-restauración el hash SHA-256, marcadores de inicio/EOF y utiliza base de staging para restauraciones atómicas.
- **Integración Continua:** `.github/workflows/ci.yml` con Composer, npm, PHPUnit con orden aleatorio (`--order-by=random`) y prueba de integridad de respaldos.
- **Pruebas:** `tests/Integration/BackupRestoreIntegridadIntegrationTest.php` (4 tests, 42 aserciones, salida 0) y `tests/Integration/MigrationTest.php` (7 tests, 171 aserciones, salida 0).

### 1. Evidencia de Ejecución de Suites Automatizadas
- **PHPUnit 10.5.66 en contenedor aislado (`appsalon-test-int-db`, PHP 8.2.34):**
  - **Comando:** `vendor/bin/phpunit --do-not-cache-result --colors=never`
  - **Resultado:** `OK (203 tests, 2530 assertions)` — Código de salida `0`.
- **Suite de Navegador Real Headless Chrome (`tests/verificar_navegador.ps1`):**
  - **Comando:** `powershell -ExecutionPolicy Bypass -File tests/verificar_navegador.ps1`
  - **Resultado:** 19/19 comprobaciones `[OK]`, `0` errores de consola, `0` solicitudes de red fallidas, verificación de persistencia en MySQL — Código de salida `0`.

### 1. Alcance Consolidado del Cierre Técnico

1. **Auditoría Integral de Seguridad, Rutas, Permisos y Cabeceras HTTP (`includes/funciones.php`, `Router.php`, `public/index.php`, `controllers/PagoController.php`):**
   - **Cabeceras de seguridad consistentes (`emitir_cabeceras_seguridad()`):** Emitidas de forma centralizada en `iniciar_sesion_segura()`, `Router::comprobarRutas()`, `Router::render()` y `public/index.php`:
     - `X-Content-Type-Options: nosniff`
     - `X-Frame-Options: SAMEORIGIN`
     - `Referrer-Policy: strict-origin-when-cross-origin`
   - **Cobertura completa de rutas y permisos:**
     - Se registra `POST /api/pagos/reembolsar` en `public/index.php` hacia `PagoController::reembolsar()`, protegido con `isAdmin()` y validación CSRF (`validar_csrf()`).
     - `Router::comprobarRutas()` emite explícitamente `http_response_code(404)` ante rutas inexistentes.
     - Ningún controlador o repositorio filtra trazas SQL, contraseñas, tokens ni secretos en respuestas HTTP.

2. **Revisión de Dependencias y Scripts (`composer.json`, `package.json`, `.gitignore`):**
   - `composer.json` incorpora scripts estandarizados (`composer test`, `composer migrate:status`, `composer migrate:up`, `composer migrate:down`, `composer db:backup`, `composer db:restore`).
   - `package.json` documenta metadatos y scripts de compilación reproducible (`npm run build`, `npm run build:css`, `npm run build:js`).
   - `.gitignore` protege explícitamente `includes/.env` y `storage/` frente a versionado accidental.

3. **Revisión de Consultas SQL, Claves Foráneas e Índices (`database/migrations/001` a `006`, `database/README.md`):**
   - Se incorpora el índice `idx_citas_estado_pago (estado_pago)` en `006_base_pagos.sql` (y su `DROP INDEX` correspondiente en `006_base_pagos_rollback.sql`).
   - Se actualiza `database/README.md` documentando las 6 migraciones incrementales (`001` a `006`) y sus políticas de integridad referencial (`ON DELETE RESTRICT` en `fk_citas_profesional`, `ON DELETE SET NULL` en `notificaciones_citas` y `pagos` preservando `cita_referencia_id`, y `ON DELETE CASCADE` en relaciones de agenda de profesionales).

4. **Integración Continua Automatizada (`.github/workflows/ci.yml`):**
   - Configura PHP 8.2 (`mysqli`, `pdo_mysql`, `mbstring`, `zlib`) y servicio `mysql:8.0` con healthcheck en cada `push` y `pull_request` sobre `main` y `feature/**`.
   - Ejecuta `composer install`, verificación de sintaxis `php -l` sobre todos los archivos `.php` del proyecto, carga de `database/schema_base.sql`, aplicación y verificación de migraciones (`php database/migrador.php up` y `status`), suite completa de PHPUnit y prueba funcional en vivo de `scripts/backup_db.php` y `scripts/restore_db.php`.

5. **Entorno Docker Reproducible y Plantilla de Variables de Entorno (`Dockerfile`, `docker-compose.yml`, `includes/.env.example`):**
   - `includes/.env.example` documenta exhaustivamente todas las variables del sistema (`APP_ENV`, `APP_URL`, `APP_TIMEZONE=America/Guayaquil`, `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS`, `DB_NAME`, `EMAIL_HOST`, `EMAIL_PORT`, `EMAIL_USER`, `EMAIL_PASS`, `EMAIL_PASSWORD`, `EMAIL_FROM`, `EMAIL_FROM_NAME`, `NOTIFICACIONES_RECORDATORIO_HORAS`, `RESERVA_EXIGIR_PROFESIONAL`, `PAYMENT_GATEWAY_PROVIDER`, `PAYMENT_WEBHOOK_SECRET`).
   - `Dockerfile` y `docker-compose.yml` proveen una definición reproducible para PHP 8.2 Apache (`mod_rewrite`, `mod_headers`, `mysqli`, `pdo_mysql`) y MySQL 8.0 (`utf8mb4_unicode_ci`) con nombres de contenedores parametrizables que preservan intactos los contenedores locales existentes.

6. **Scripts CLI de Respaldo y Restauración Probados en Aislamiento (`scripts/backup_db.php`, `scripts/restore_db.php`, `tests/Integration/BackupRestoreIntegrationTest.php`):**
   - `scripts/backup_db.php`: Genera volcados SQL consistentes (`REPEATABLE READ` + `START TRANSACTION WITH CONSISTENT SNAPSHOT`) en texto plano (`.sql`) o comprimidos con GZIP (`.sql.gz` / `--gzip`) y calcula su hash `SHA-256`, sin depender de binarios externos del sistema operativo (`mysqldump`).
   - `scripts/restore_db.php`: Restaura volcados `.sql` o `.sql.gz` verificando el resultado de cada sentencia SQL y garantizando la reactivación de `FOREIGN_KEY_CHECKS = 1` y `UNIQUE_CHECKS = 1`.
   - `tests/Integration/BackupRestoreIntegrationTest.php`: Verifica en bases de datos aisladas el ciclo completo de respaldo (`.sql` y `.sql.gz`) y restauración sobre las 13 tablas del esquema migrado (`001` a `006`), incluyendo caracteres especiales (`;`, `'`, `--`, `&`), payloads JSON y registros históricos con `cita_id = NULL` (`ON DELETE SET NULL`), además de auditar cabeceras HTTP de seguridad, permisos de `POST /api/pagos/reembolsar` e índices en `INFORMATION_SCHEMA`.

7. **Guía de Migración y Operaciones (`docs/GUIA_MIGRACION_OPERACIONES.md`):**
   - Documenta requisitos de sistema, instalación desde cero, ejecución de migraciones y rollbacks, programación de tareas (Cron en Linux y Task Scheduler en Windows para `scripts/procesar_notificaciones.php`), política de respaldos y restauraciones, matriz de seguridad de rutas y monitoreo de salud.

### 2. Validación Ejecutada en el Cierre Técnico (Desarrollador)

#### A. Suite Completa PHPUnit y Verificación de Sintaxis (Docker PHP 8.2.34 + MySQL 8.0 Aislado)
- **Versión efectiva:** `PHPUnit 10.5.66 by Sebastian Bergmann and contributors.` (`Runtime: PHP 8.2.34`, `Configuration: /app/phpunit.xml`).
- **Comando ejecutado:**
  `docker run --rm --name appsalon-web-phpunit-ct --network appsalon-net-phpunit-ct -v "${PWD}:/app" -w /app -e APP_ENV=testing -e DB_HOST=appsalon-db-phpunit-ct -e DB_PORT=3306 -e DB_USER=root -e DB_PASS=root -e DB_NAME=appsalon_ct_test appsalon-php-test php -d variables_order=EGPCS vendor/bin/phpunit --do-not-cache-result --colors=never`
- **Resultado PHPUnit:** `OK (172 tests, 2046 assertions)` (`Time: 00:43.683, Memory: 16.00 MB`) — Código de salida `0` (con restauración estricta de superglobales `$_SERVER`, `$_POST`, `$_GET` y `$_SESSION` en `BackupRestoreIntegrationTest::tearDown()` y `try/finally`).
- **Verificación de sintaxis (`php -l`):** `0` errores de sintaxis en todos los archivos `.php` del proyecto — Código de salida `0`.

#### B. Verificación E2E en Navegador con JavaScript Habilitado (`tests/verificar_navegador.ps1` + `tests/browser_e2e_test.js`)
- **RUN_ID:** `3d118172c3e6461b9b07a841bf7e5d5b` (Contenedores efímeros aislados, puerto dinámico `http://localhost:32800`, BD aislada `appsalon_browser_test`).
- **Resultado:** 19/19 comprobaciones E2E superadas (`ADMIN-01` a `ADMIN-05`, `PROF-01`, `PROF-02`, `PRE-01`, `DISP-01`, `REC-01` a `REC-05`, `RES-01`, `REP-01`, `PAG-01`, `AUTH-01`, `NOTIF-01`), `0` errores de consola, `0` peticiones de red fallidas + verificación de persistencia en MySQL — Código de salida `0`.

### 3. Validación Independiente de Auditoría (Subagente Auditor en `auditing_fase_4b_reservas`)

- **Ronda 1 (`a1de022faf087dfd609e6acb72b737f12e20c34a`):** Veredicto `CORRECCIONES NECESARIAS`. Se identificó fuga de estado global en `BackupRestoreIntegrationTest.php` (`$_SERVER['REQUEST_URI'] = '/api/pagos/reembolsar'`) que causaba fallo en `CitaModuloIntegrationTest` durante ejecuciones limpias de PHPUnit sin caché.
- **Ronda 2 (`f33076c304e766239b1bfc13d9dde1927d749827`):** Veredicto **`APROBADO`**.
  - **Aislamiento verificado:** `stash@{0}` y recursos locales de OneDrive/Docker intactos.
  - **PHPUnit 10.5.66 en contenedor aislado (`appsalon-auditct-r2-db`):** `vendor/bin/phpunit --do-not-cache-result --colors=never` ejecutado con éxito: `OK (172 tests, 2046 assertions)` — Código de salida `0`.
  - **Suite Navegador E2E (`RUN_ID: c092a389eb3e480cb2659aa15529d4d6`):** 19/19 comprobaciones `[OK]`, `0` errores de consola, `0` solicitudes de red fallidas — Código de salida `0`.
  - **Veredicto:** Registrado en `docs/AUDITORIA.md` autorizando la integración final de la rama `feature/cierre-tecnico`.

---

## Fase 6C: Base para Pagos

- **Rama:** `feature/fase-6c-base-pagos`
- **Commit de Código Auditado:** `8178b830bc03197775fa653857e66ec74eb290db`
- **Commit Base (Fase 6B aprobada):** `70a186dadf0d89d93d7f8b7c24572f39cc0ff3df` (`fb82ec7f59571dca82bf738a30e79c2d19489894` + registro de auditoría Fase 6B)
- **Estado General de Fase 6C:** **Completada y Auditada (`APROBADO` en `docs/AUDITORIA.md`)**

### 1. Diseño Arquitectónico, Interfaz de Pasarela Sustituible, Cálculo en Servidor, Idempotencia y Prohibición de Datos de Tarjeta

1. **Migraciones Incrementales y Reversibles (`database/migrations/006_base_pagos.sql` y `006_base_pagos_rollback.sql`):**
   - Añade la columna `estado_pago VARCHAR(20) NOT NULL DEFAULT 'pendiente'` e índice `idx_citas_estado_pago` en `citas` de manera idempotente.
   - Crea la tabla `pagos` (`InnoDB`, `utf8mb4_unicode_ci`) con `cita_id INT NULL` (`ON DELETE SET NULL`), `cita_referencia_id INT NOT NULL` (para preservar la trazabilidad histórica incluso si la cita es eliminada), `usuario_id INT NULL` (`ON DELETE SET NULL`), `referencia_pago VARCHAR(64) NOT NULL`, `proveedor VARCHAR(50) NOT NULL`, `transaccion_externa_id VARCHAR(120) NULL`, `idempotency_key VARCHAR(100) NULL`, `monto DECIMAL(10, 2) NOT NULL`, `moneda CHAR(3) NOT NULL DEFAULT 'USD'`, `estado VARCHAR(20) NOT NULL DEFAULT 'pendiente'`, `payload_resumen TEXT NULL`, `error_detalle VARCHAR(255) NULL`, `intentos_notificacion INT NOT NULL DEFAULT 0`, `confirmado_at DATETIME NULL`, `reembolsado_at DATETIME NULL`, `created_at` y `updated_at`.
   - Restricciones únicas: `UNIQUE KEY uq_pagos_referencia (referencia_pago)`, `UNIQUE KEY uq_pagos_transaccion_externa (transaccion_externa_id)` y `UNIQUE KEY uq_pagos_idempotency_key (idempotency_key)`.
   - Script de reversión limpia e idempotente en `006_base_pagos_rollback.sql` verificado en `MigrationTest::testMigracion006BasePagosUpIdempotenciaSetNullYRollback`.

2. **Interfaz Sustituible de Pasarela (`Interfaces\PaymentGatewayInterface`) y Proveedor Simulado (`Classes\MockPaymentGateway`):**
   - **`interfaces/PaymentGatewayInterface.php`:** Define el contrato desacoplado de proveedor de pagos (`obtenerNombreProveedor`, `iniciarPago`, `verificarPago`, `procesarNotificacionWebhook`, `reembolsarPago`) permitiendo conectar en el futuro cualquier pasarela comercial (Payphone, Kushki, Datafast/Paymentez, Stripe) sin modificar controladores ni servicios.
   - **`classes/MockPaymentGateway.php`:** Implementación simulada determinista para desarrollo y pruebas automatizadas que soporta escenarios de aprobación (`aprobado`), rechazo por fondos insuficientes (`fondos_insuficientes`), error de comunicación (`error_comunicacion`), verificación de firma HMAC-SHA256 en cabecera `X-Mock-Signature` (`PAYMENT_WEBHOOK_SECRET`), y detección/sanitización estricta contra datos sensibles de tarjeta (`contieneDatosTarjetaSensibles` y `sanearPayloadSinDatosTarjeta`).
   - **Resolución PSR-4 (`composer.json` e `includes/app.php`):** Registra el namespace `"Interfaces\\": "./interfaces"` en `composer.json` y un autoloader PSR-4 de respaldo en `includes/app.php` para garantizar compatibilidad inmediata en cualquier worktree.

3. **Arquitectura por Capas (`Controller -> Service -> Repository`):**
   - **`Model\Pago` (`models/Pago.php`):** Entidad de dominio con constantes explícitas de estado de transacción (`ESTADO_PENDIENTE`, `ESTADO_COMPLETADO`, `ESTADO_FALLIDO`, `ESTADO_REEMBOLSADO`, `ESTADO_CANCELADO`) y de estado de pago de la cita (`CITA_ESTADO_PENDIENTE`, `CITA_ESTADO_PAGADO`, `CITA_ESTADO_FALLIDO`, `CITA_ESTADO_REEMBOLSADO`).
   - **`Repositories\PagoRepository` (`repositories/PagoRepository.php`):** Consultas y escrituras exclusivamente mediante sentencias preparadas y `PersistenceException`:
     - `obtenerCitaConImporteServidor(int $citaId, bool $forUpdate)`: Calcula el importe total en servidor sumando `COALESCE(cs.precio_servicio, s.precio, 0)` desde `citasservicios` (snapshot histórico inmutable).
     - Operaciones con bloqueo pesimista `FOR UPDATE` (`findByReferencia`, `findByIdempotencyKey`, `findPagoActivoPorCita`) y actualización sincronizada de `citas.estado_pago` (`actualizarEstadoPagoCita`).
   - **`Services\PagoService` (`services/PagoService.php`):**
     - **Prohibición estricta de datos de tarjeta:** Rechaza de inmediato (`STATUS_INVALID` / HTTP `422`) cualquier intento de enviar números de tarjeta (`PAN` de 13 a 19 dígitos o claves como `card_number`, `numero_tarjeta`, `pan`, `cvv`, `cvc`, `fecha_expiracion`, `track_data`) y sanea `payload_resumen` antes de persistir en MySQL.
     - **Cálculo de importes exclusivamente en servidor:** Ignora cualquier `monto`, `total`, `precio`, `amount` o `moneda` enviados por el cliente; determina el importe únicamente en el servidor a partir de los servicios históricos de la cita (`obtenerCitaConImporteServidor`) y genera una referencia única (`PAY-YYYYMMDD-<16_HEX>`) en zona horaria `America/Guayaquil`.
     - **Idempotencia al iniciar pago (`iniciarPagoCita`):** Dentro de transacción MySQL con `FOR UPDATE`, reutiliza el pago existente si se repite `idempotency_key` o si ya existe un pago `pendiente` para la cita, y rechaza (`409`) iniciar un nuevo cobro si la cita ya figura como `pagado` (`completado`).
     - **Prohibición de confirmar pagos por redirección del navegador (`consultarResultadoRetorno`):** `GET /pago/resultado` ignora cualquier parámetro de query string manipulable (`?status=approved`, `?estado=completado`, `?pagado=1`) y devuelve exclusivamente el estado persistido en servidor (reconciliado únicamente vía webhook firmado o consulta servidor-a-servidor al proveedor).
     - **Idempotencia y validación de firma en webhooks (`procesarWebhookProveedor`):** Verifica la firma criptográfica HMAC-SHA256 del proveedor (`401` si es inválida) y procesa la transición de estado dentro de una transacción con bloqueo `FOR UPDATE`. Si una notificación `completado` o `fallido` llega repetida, responde de forma idempotente (`idempotente = true`, `ya_procesado = true`) sin duplicar efectos ni alterar `confirmado_at`, y protege un pago ya `completado` frente a notificaciones tardías de `fallido`.
     - **Reembolso administrativo (`reembolsarPagoCita`):** Permite a un administrador reembolsar un pago `completado`, actualizando `pagos.estado = 'reembolsado'` y `citas.estado_pago = 'reembolsado'` de forma idempotente.
   - **`Controllers\PagoController` (`controllers/PagoController.php`) y Rutas (`public/index.php`):**
     - `POST /api/pagos/iniciar` (requiere sesión autenticada + CSRF válido).
     - `POST /api/pagos/webhook` (endpoint de notificación firmada servidor-a-servidor).
     - `GET /pago/resultado` (vista segura `views/pagos/resultado.php` que alerta cuando se detectan parámetros manipulados en la URL y muestra únicamente el estado real del servidor).
     - `POST /api/pagos/reembolsar` (requiere `isAdmin()` + CSRF válido).
   - **Integración con Reportes Administrativos (`repositories/ReporteRepository.php`, `services/ReporteService.php`):**
     - `ReporteRepository::obtenerFilasReservasEnRango()` incorpora `c.estado_pago` y el `ultimo_pago_estado` de `pagos`, permitiendo que `ReporteService::evaluarEstadoPagoCita()` refleje automáticamente en `/admin/reportes` y en la exportación CSV los ingresos efectivamente cobrados (`estado_pago = 'pagado'`) y los reembolsos (`estado_pago = 'reembolsado'`, con ingreso neto pagado `$0.00`).
   - **Documentación de Decisiones de Negocio Pendientes (`docs/DECISIONES_PAGOS.md`):**
     - Registra explícitamente las decisiones comerciales abiertas que debe definir el propietario antes de activar cobros reales en producción: (1) cobro total vs. anticipo/seña, (2) ventana de cancelación sin penalización y política de devoluciones, (3) absorción o traslado de comisiones de pasarela, (4) criterios de selección del proveedor comercial en Ecuador sin *vendor lock-in*, y (5) manejo de expiración de intentos de pago pendientes.

### 2. Validación Ejecutada en Fase 6C (Desarrollador)

#### A. Suite Completa PHPUnit (Ejecutada en Docker PHP 8.2.34 + MySQL 8.0 Aislado)
- **Versión efectiva:** `PHPUnit 10.5.66 by Sebastian Bergmann and contributors.` (`Runtime: PHP 8.2.34`, `Configuration: /app/phpunit.xml`).
- **Comando ejecutado:**
  `docker run --rm --name appsalon-web-phpunit-f6c --network appsalon-net-phpunit-f6c -v "${PWD}:/app" -w /app -e APP_ENV=testing -e DB_HOST=appsalon-db-phpunit-f6c -e DB_PORT=3306 -e DB_USER=root -e DB_PASS=root -e DB_NAME=appsalon_f6c_test appsalon-php-test php -d variables_order=EGPCS vendor/bin/phpunit --colors=never`
- **Resultado:** `OK (170 tests, 1949 assertions)` (`Time: 00:34.375, Memory: 16.00 MB`) — Código de salida `0`.
- **Cobertura añadida en Fase 6C:**
  - `Tests\Integration\MigrationTest::testMigracion006BasePagosUpIdempotenciaSetNullYRollback` (`tests/Integration/MigrationTest.php`): verifica la aplicación idempotente de `006_base_pagos.sql`, la preservación de `cita_referencia_id` con `cita_id = NULL` (`ON DELETE SET NULL`) al eliminar una cita, y el rollback limpio con `006_base_pagos_rollback.sql`.
  - `Tests\Unit\PagoServiceTest` (`tests/Unit/PagoServiceTest.php` — 4 pruebas unitarias):
    1. `testIniciarPagoCalculaImporteExclusivamenteEnServidorIgnorandoMontoDelCliente`: verifica que el importe cobrado (`$80.00`) proviene únicamente del servidor ignorando `monto = 0.01` y `moneda = EUR` enviados por el cliente, y que la referencia cumple el formato `PAY-YYYYMMDD-<16_HEX>`.
    2. `testRechazaAlmacenarOProcesarDatosDeTarjetaSensibles`: verifica que cualquier payload con `numero_tarjeta`/`cvv` o PAN en metadatos es rechazado con `STATUS_INVALID` (`422`) sin invocar la pasarela ni persistir en BD, y que `sanearPayloadSinDatosTarjeta` redacta cualquier dato sensible.
    3. `testRetornoDeNavegadorIgnoraQueryParamsManipuladosYNoConfirmaPago`: verifica que `consultarResultadoRetorno()` con `?status=approved&estado=completado&pagado=1` nunca confirma el pago si la pasarela no lo reporta pagado en el servidor.
    4. `testWebhookRechazaFirmaInvalidaYProcesaNotificacionDuplicadaConIdempotencia`: verifica rechazo `401` ante firma HMAC inválida, transición única a `completado` con firma válida, e idempotencia ante webhooks repetidos o notificaciones tardías de fallo.
  - `Tests\Integration\Fase6CBasePagosIntegrationTest` (`tests/Integration/Fase6CBasePagosIntegrationTest.php` — 4 pruebas de integración con MySQL real y HTTP aisladas):
    1. `testFlujoCompletoDePagoCalculoServidorIdempotenciaEIntegracionConReportes`: verifica contra MySQL real el inicio de pago (calculando `$80.00` desde `citasservicios` e ignorando `$0.50` del cliente), reutilización idempotente por `idempotency_key`, bloqueo de confirmación por redirect, confirmación vía webhook firmado, idempotencia ante segundo webhook idéntico, actualización de `ingreso_pagado_total` (`$80.00`) en `ReporteService`, y reembolso administrativo que regresa el ingreso neto pagado a `$0.00`.
    2. `testEscenariosProveedorSimuladoRechazoFondosYErrorComunicacion`: verifica el manejo de `fondos_insuficientes` (`estado = 'fallido'`, `citas.estado_pago = 'fallido'`), reintento posterior con nueva referencia, y `error_comunicacion` (`502`).
    3. `testSeguridadProhibicionDeDatosDeTarjetaYControlDeAccesoEntreUsuarios`: verifica que nunca se persisten PAN ni CVV en la tabla `pagos` y que un cliente no puede iniciar ni consultar pagos de citas ajenas (`403`).
    4. `testEndpointsHttpDePagosIniciacionRetornoSeguroyWebhookFirmado`: verifica por HTTP real (`POST /api/pagos/iniciar`, `GET /pago/resultado`, `POST /api/pagos/webhook`) el rechazo `422` de tarjeta, la creación `201` con monto de servidor, la resistencia de `/pago/resultado` frente a `?status=approved&pagado=1`, el rechazo `401` de webhook sin firma válida y la confirmación idempotente `200` con firma HMAC válida.

#### B. Verificación E2E en Navegador con JavaScript Habilitado (`tests/verificar_navegador.ps1` + `tests/browser_e2e_test.js`)
- **Ejecución del Desarrollador (`comprehensive_project_audit_roadmap`):**
  - **RUN_ID:** `195d4b1980c34ed5bb8203d4ed1be696` (Contenedores efímeros `appsalon-db-test-195d4b1980c34ed5bb8203d4ed1be696`, `appsalon-smtp-test-195d4b1980c34ed5bb8203d4ed1be696` y `appsalon-web-test-195d4b1980c34ed5bb8203d4ed1be696`, puerto dinámico `http://localhost:32796`, BD aislada `appsalon_browser_test`).
  - **Resultado:** 19/19 comprobaciones E2E superadas (`ADMIN-01` a `ADMIN-05`, `PROF-01`, `PROF-02`, `PRE-01`, `DISP-01`, `REC-01` a `REC-05`, `RES-01`, `REP-01`, `PAG-01`, `AUTH-01`, `NOTIF-01`) + verificación de persistencia en MySQL — Código de salida `0`.
  - **Detalle de `PAG-01`:** Verifica en navegador real el rechazo `422` al intentar enviar datos de tarjeta en `POST /api/pagos/iniciar`, la creación de la orden de pago `PAY-20261010-176A7B2550635E17` calculada en servidor por `$195.00` (ignorando `$1.00` enviado por el cliente), la protección de `GET /pago/resultado?referencia=...&status=approved&estado=completado&pagado=1` manteniendo el estado `pendiente` y mostrando la alerta de advertencia, la confirmación e idempotencia mediante `POST /api/pagos/webhook` con firma HMAC-SHA256 válida, y la actualización de `/admin/reportes` reflejando `$195.00` en `#kpi-ingreso-pagado`.

---

## Fase 6B: Reportes Administrativos

- **Rama:** `feature/fase-6b-reportes`
- **Commit de Código Auditado:** `fb82ec7f59571dca82bf738a30e79c2d19489894`
- **Commit Base (Fase 6A aprobada):** `4e85266eb33a3c0b890384a1303e800d771c2340` (`4fe475c02cc3dcd38a79ad7ec4ce5ef48f9e58ec` + registro de auditoría Fase 6A)
- **Estado General de Fase 6B:** **Completada y Auditada (`APROBADO` en `docs/AUDITORIA.md`)**

### 1. Diseño Arquitectónico, Cálculo de Ocupación, Integridad Histórica y Exportación CSV Segura

1. **Arquitectura por Capas (`Controller -> Service -> Repository`):**
   - **`Controllers\ReporteController` (`controllers/ReporteController.php`):**
     - `GET /admin/reportes` (`ReporteController::index`): Protegido con `isAdmin()`. Lee los filtros desde `$_GET`, delega en `ReporteService::generarReporte()` y renderiza `views/reportes/index.php`. Si los filtros son inválidos (`STATUS_INVALID`), responde con código HTTP `422` mostrando las alertas de validación y conservando los selectores del formulario sin ejecutar consultas malformadas. Si ocurre una excepción de persistencia (`STATUS_ERROR`), responde con HTTP `500` y mensaje controlado.
     - `GET /admin/reportes/exportar` (`ReporteController::exportarCsv`): Protegido con `isAdmin()`. Valida los mismos filtros mediante `ReporteService::generarReporte()` y, cuando el estado es `STATUS_OK`, emite el archivo CSV (`Content-Type: text/csv; charset=UTF-8`, `Content-Disposition: attachment; filename="reporte_reservas_YYYY-MM-DD_a_YYYY-MM-DD.csv"`, `X-Content-Type-Options: nosniff`, `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`) generado por `ReporteService::generarCsv()`.
   - **`Services\ReporteService` (`services/ReporteService.php`):**
     - **Validación estricta de filtros (`validarYNormalizarFiltros`):**
       - Zona horaria explícita `America/Guayaquil` (`DateTimeZone('America/Guayaquil')`); cuando no se suministran fechas, aplica por defecto el primer día del mes actual (`Y-m-01`) hasta la fecha actual (`Y-m-d`).
       - Valida formato calendario real `YYYY-MM-DD` (`DateTimeImmutable::createFromFormat('!Y-m-d', ...)`), que `fecha_inicio <= fecha_fin` y que el rango no supere `MAX_RANGO_DIAS = 366` días inclusivos.
       - Rechaza cualquier entrada no escalar (arrays en parámetros GET) y valida `profesional_id` (`''` = todos, `'sin_profesional'` = solo citas históricas sin profesional, o entero positivo `> 0`) y `servicio_id` (`''` = todos o entero positivo `> 0`).
     - **Cálculo de minutos disponibles netos y porcentaje de ocupación (`calcularMinutosDisponiblesPorProfesional` y `restarRestriccionesDeTurno`):**
       - Para cada fecha del rango `[fecha_inicio, fecha_fin]` y cada profesional seleccionado, determina el día de la semana ISO-8601 (`1` = Lunes .. `7` = Domingo) en `America/Guayaquil`.
       - Parte de los turnos laborales configurados en `horarios_profesionales` (`[hora_inicio, hora_fin)`).
       - Si existe un bloqueo de día completo en `bloqueos_profesionales` (`hora_inicio IS NULL AND hora_fin IS NULL`) para ese profesional y fecha, la disponibilidad neta del día es `0` minutos.
       - En caso contrario, recorta iterativamente del intervalo semiabierto `[inicio, fin)` tanto los descansos semanales (`descansos_profesionales`) como los bloqueos parciales de esa fecha (`bloqueos_profesionales`), evitando restar dos veces solapamientos entre descanso y bloqueo.
       - Calcula los minutos ocupados sumando la duración real de las citas reservadas del profesional (en la vista general usa la duración total de la cita `duracion_total_minutos` o el intervalo `[hora_inicio, hora_fin)` o la suma de servicios; cuando se filtra por `servicio_id`, contabiliza únicamente los minutos históricos del servicio filtrado).
       - Calcula `porcentaje_ocupacion = round((minutos_ocupados / minutos_disponibles) * 100, 2)` y, cuando `minutos_disponibles === 0`, devuelve `0.00%` sin división por cero.
     - **Importes históricos e inmutabilidad frente a cambios de catálogo:**
       - Todos los cálculos de precios y duraciones por servicio y por cita utilizan los valores históricos capturados al reservar (`COALESCE(cs.nombre_servicio, s.nombre)`, `COALESCE(cs.precio_servicio, s.precio)`, `COALESCE(cs.duracion_minutos, s.duracion_minutos)`), identificando además si el servicio sigue activo en el catálogo o si fue eliminado posteriormente (`servicio_eliminado_catalogo = true` cuando `cs.servicioId IS NULL` o `s.id IS NULL`).
       - Cuando se aplica el filtro `servicio_id`, el importe reservado y los minutos de cada cita reflejan exactamente el subtotal histórico de las líneas que coinciden con dicho servicio (`importe_Total_Cita` conserva el total completo de la cita como referencia informativa).
     - **Distinción explícita entre Importe Reservado Histórico e Ingreso Efectivamente Pagado (`evaluarEstadoPagoCita`):**
       - Distingue en el resumen ejecutivo, en la tabla de reservas y en el CSV entre:
         - **`importe_reservado_total` (`Importe Reservado (Comprometido Histórico)`):** Suma de los importes históricos de las reservas confirmadas en agenda.
         - **`ingreso_pagado_total` (`Ingreso Efectivamente Pagado`):** Suma únicamente de las citas cuyo estado de pago sea efectivamente `'pagado'`. Mientras no exista cobro confirmado (o antes de la integración de pasarela de Fase 6C), toda reserva se reporta explícitamente con `estado_pago = 'pendiente_pago'` (`Pendiente de pago`) e `ingreso_pagado = 0.00`, sin confundir reservas agendadas con ingresos cobrados.
     - **Exportación CSV segura contra *CSV Formula Injection* (`sanearCeldaCsv` y `generarCsv`):**
       - Incluye cabecera UTF-8 BOM (`\xEF\xBB\xBF`) para compatibilidad directa con Excel y hojas de cálculo en español.
       - `ReporteService::sanearCeldaCsv()` inspecciona tanto el primer carácter de la cadena como el primer carácter tras espacios iniciales (`ltrim`); si comienza con `=`, `+`, `-`, `@`, tabulador (`\t`) o salto de línea (`\r`, `\n`), antepone un apóstrofe (`'`) para neutralizar la ejecución de fórmulas al abrir el archivo en Excel, LibreOffice Calc o Google Sheets, y normaliza saltos de línea internos a espacios antes de serializar con `fputcsv()`.
   - **`Repositories\ReporteRepository` (`repositories/ReporteRepository.php`):**
     - Implementa exclusivamente sentencias preparadas (`prepare` + `bind_param` + `execute`) y traduce cualquier error de MySQL en `PersistenceException`.
     - Métodos: `listarProfesionalesCatalogo()`, `listarServiciosCatalogo()`, `obtenerHorariosProfesionales()`, `obtenerDescansosProfesionales()`, `obtenerBloqueosEnRango()` y `obtenerFilasReservasEnRango()` (con filtro `EXISTS` cuando se selecciona `servicio_id`, garantizando que se carguen todas las líneas de la cita que contiene ese servicio y ordenando determinísticamente por `c.fecha ASC, COALESCE(c.hora_inicio, c.hora) ASC, c.id ASC, cs.id ASC`).
   - **Interfaz Administrativa (`views/reportes/index.php`, `views/templates/barra.php`, `src/scss/layout/_admin.scss`, `public/build/css/app.css`):**
     - Enlace `"Ver Reportes"` en la barra de navegación administrativa (`views/templates/barra.php`).
     - Formulario accesible de filtros (`fecha_inicio`, `fecha_fin`, `profesional_id`, `servicio_id`), acciones `"Filtrar Reporte"`, `"Exportar CSV"` (conservando los parámetros activos en la query string) y `"Restablecer"`.
     - Tarjetas KPI ejecutivas (Total de citas, Minutos ocupados vs disponibles, Ocupación global, **Importe Reservado Histórico** e **Ingreso Efectivamente Pagado** con nota aclaratoria explícita).
     - Tabla de ocupación por profesional, tabla de desglose por servicio histórico y listado detallado de reservas con estados vacíos claros cuando no existen registros en el rango.

### 2. Validación Ejecutada en Fase 6B (Desarrollador)

#### A. Suite Completa PHPUnit (Ejecutada en Docker PHP 8.2.34 + MySQL 8.0 Aislado)
- **Versión efectiva:** `PHPUnit 10.5.66 by Sebastian Bergmann and contributors.` (`Runtime: PHP 8.2.34`, `Configuration: /app/phpunit.xml`).
- **Comando ejecutado:**
  `docker run --rm --name appsalon-web-phpunit-f6b --network appsalon-net-phpunit-f6b -v "${PWD}:/app" -w /app -e APP_ENV=testing -e DB_HOST=appsalon-db-phpunit-f6b -e DB_PORT=3306 -e DB_USER=root -e DB_PASS=root -e DB_NAME=appsalon_f6b_test appsalon-php-test php -d variables_order=EGPCS vendor/bin/phpunit --colors=never`
- **Resultado:** `OK (161 tests, 1817 assertions)` (`Time: 00:27.402, Memory: 16.00 MB`) — Código de salida `0`.
- **Cobertura añadida en Fase 6B:**
  - `Tests\Unit\ReporteServiceTest` (`tests/Unit/ReporteServiceTest.php` — 4 pruebas unitarias):
    1. `testValidacionDeFiltrosRechazaRangosInvalidosFechasInvertidasYParametrosNoEscalares`: verifica rechazo `422` (`STATUS_INVALID`) ante fechas invertidas, formatos no válidos, rangos mayores a 366 días, IDs no numéricos y parámetros tipo array, sin ejecutar consultas al repositorio.
    2. `testCalculoOcupacionDescuentaDescansosYBloqueosSinDivisionPorCero`: verifica el recorte exacto de descansos y bloqueos parciales (`540 - 60 - 90 = 390 min`), el bloqueo de día completo (`0 min` disponibles y `0.00%` sin división por cero) y el cálculo de ocupación (`150 / 390 = 38.46%`).
    3. `testImportesHistoricosYDistincionExplicitaEntreReservadoYPagado`: verifica preservación del snapshot histórico (`$45.00` y `60 min` frente a cambios posteriores del catálogo a `$99.00` y `90 min`) y distinción entre `importe_reservado_total` (`$45.00`) e `ingreso_pagado_total` (`$0.00` cuando `estado_pago = 'pendiente_pago'`).
    4. `testSanitizacionCsvNeutralizaFormulaInjectionEnCeldasPeligrosas`: verifica neutralización de `=`, `+`, `-`, `@`, `\t`, `\r` y fórmulas precedidas por espacios en `sanearCeldaCsv()` y `generarCsv()`.
  - `Tests\Integration\Fase6BReportesIntegrationTest` (`tests/Integration/Fase6BReportesIntegrationTest.php` — 4 pruebas de integración con MySQL real y HTTP aisladas):
    1. `testReporteEnBaseDatosRealPreservaSnapshotsHistoricosOcupacionEIngresoPagadoCero`: verifica contra MySQL real la preservación de precios/nombres/duraciones históricos tras modificar un servicio y eliminar otro del catálogo, el cálculo de ocupación con horarios/descansos/bloqueos reales, el filtro por `servicio_id`, el filtro `profesional_id = 'sin_profesional'` para citas históricas y el estado vacío.
    2. `testExportacionCsvConDatosRealesNeutralizaInyeccionDeFormulas`: verifica la exportación CSV con datos reales cuando el nombre de cliente o servicio inicia con `=cmd|' /C calc'!A0` o `@SUM(1+1)*cmd|' /C calc'!A0`.
    3. `testPermisosAdministrativosEnRutasDeReportesBloqueanAnonimosYClientes`: verifica que `/admin/reportes` y `/admin/reportes/exportar` redirigen (`302 -> /`) tanto a usuarios anónimos como a clientes autenticados no administradores (`admin = 0`).
    4. `testEndpointsHttpDeReportesYExportacionCsvParaAdministrador`: verifica respuestas HTTP `200` en `GET /admin/reportes` y `GET /admin/reportes/exportar`, encabezados `text/csv`, y respuesta HTTP `422` ante filtros inválidos.

#### B. Verificación E2E en Navegador con JavaScript Habilitado (`tests/verificar_navegador.ps1` + `tests/browser_e2e_test.js`)
- **Ejecución del Desarrollador (`comprehensive_project_audit_roadmap`):**
  - **RUN_ID:** `a1ca5acb73e54a49a7e83ec784e0eb4e` (Contenedores efímeros `appsalon-db-test-a1ca5acb73e54a49a7e83ec784e0eb4e`, `appsalon-smtp-test-a1ca5acb73e54a49a7e83ec784e0eb4e` y `appsalon-web-test-a1ca5acb73e54a49a7e83ec784e0eb4e`, puerto dinámico `http://localhost:32793`, BD aislada `appsalon_browser_test`).
  - **Resultado:** 18/18 comprobaciones E2E superadas (`ADMIN-01` a `ADMIN-05`, `PROF-01`, `PROF-02`, `PRE-01`, `DISP-01`, `REC-01` a `REC-05`, `RES-01`, `REP-01`, `AUTH-01`, `NOTIF-01`) + verificación de persistencia en MySQL — Código de salida `0`.
  - **Detalle de `REP-01`:** Verifica en navegador real `/admin/reportes` para la fecha de reserva (`2026-10-12`), comprobando la ocupación de `Valeria Castro` (`16.67%`, `90 / 540 min`) y `Sofía Andrade` (`0.00%`, `0 / 390 min` tras descontar descanso y bloqueo), la distinción explícita entre importe reservado (`$195.00`) e ingreso efectivamente pagado (`$0.00` — `Pendiente de pago`), el filtrado por servicio (`#4` -> `$115.00`) y la descarga válida de `/admin/reportes/exportar`.

---

## Fase 6A: Notificaciones de Citas

- **Rama:** `feature/fase-6a-notificaciones`
- **Commit de Código Auditado (Ronda 2):** `4fe475c02cc3dcd38a79ad7ec4ce5ef48f9e58ec` (Ronda 1: `0aa1e11d9328aa5558d43d50b5483a492850ce7f`)
- **Commit Base (Fase 5 aprobada):** `1f63cd88f3fd6cab1dd958a6b8c82371b5f1d7b0` (`1fb3475fe2eec5ed4dc2f6afd22bc6b1bcaabb27` + registro de auditoría Fase 5)
- **Estado General de Fase 6A:** **Completada y Auditada (`APROBADO` en `docs/AUDITORIA.md`)**

### 1. Diseño Arquitectónico, Migración Incremental e Idempotencia Transaccional

1. **Migración Incremental (`database/migrations/005_notificaciones_citas.sql` + `005_notificaciones_citas_rollback.sql`):**
   - Crea la tabla `notificaciones_citas` (`ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`) con las columnas:
     - `id INT NOT NULL AUTO_INCREMENT PRIMARY KEY`
     - `cita_id INT NULL DEFAULT NULL` (FK `fk_notificaciones_cita` hacia `citas(id)` con `ON DELETE SET NULL ON UPDATE CASCADE`)
     - `cita_referencia_id INT NOT NULL` (conserva permanentemente el ID original de la cita aunque la fila en `citas` sea eliminada al cancelar)
     - `usuario_id INT NULL DEFAULT NULL` (FK `fk_notificaciones_usuario` hacia `usuarios(id)` con `ON DELETE SET NULL ON UPDATE CASCADE`)
     - `tipo VARCHAR(30) NOT NULL` (`'confirmacion'`, `'cancelacion'`, `'recordatorio'`)
     - `clave_idempotencia VARCHAR(120) NOT NULL` con restricción única `UNIQUE KEY uq_notificaciones_clave_idempotencia (clave_idempotencia)` (p. ej. `cita:42:confirmacion`, `cita:42:cancelacion`, `cita:42:recordatorio:2026-11-16_11:30`)
     - `destinatario_email VARCHAR(60) NOT NULL`, `destinatario_nombre VARCHAR(120) NOT NULL`, `asunto VARCHAR(180) NOT NULL`, `payload_json JSON NULL DEFAULT NULL`
     - `estado VARCHAR(20) NOT NULL DEFAULT 'pendiente'` (`'pendiente'`, `'enviando'`, `'enviado'`, `'fallido'`)
     - `intentos INT NOT NULL DEFAULT 0`, `max_intentos INT NOT NULL DEFAULT 3`, `ultimo_error VARCHAR(255) NULL DEFAULT NULL`, `enviado_at DATETIME NULL DEFAULT NULL`, `proximo_intento_at DATETIME NULL DEFAULT NULL`, `created_at` y `updated_at`.
   - **Preservación de historial tras cancelación:** Gracias a `cita_id INT NULL` con `ON DELETE SET NULL` y `cita_referencia_id INT NOT NULL`, al eliminar una cita en `citas` (durante `POST /api/eliminar`), el registro previo de `confirmacion` pasa a tener `cita_id = NULL` sin violar integridad referencial y el nuevo registro de `cancelacion` se inserta con `cita_id = NULL` y `cita_referencia_id = <id>`, preservando ambos en la bitácora.

2. **Endurecimiento de Enlaces, Remitentes y Transporte Sustituible (`Classes\Email`):**
   - **`Email::resolverAppUrl(?string $appUrlOverride = null)`:** Valida estrictamente `APP_URL` aceptando únicamente esquemas `http` o `https` con `host` válido y sin credenciales embebidas, saltos de línea (`\r`, `\n`), bytes nulos ni comillas/ángulos (`'`, `"`, `<`, `>`). Rechaza vectores como `javascript:...` o `data:...` aplicando fallback seguro a `http://localhost:3000`.
   - **`Email::resolverRemitente()` y `Email::sanearTextoCabecera()`:** Validan `EMAIL_FROM` (`filter_var(..., FILTER_VALIDATE_EMAIL)`, con fallback a `cuentas@appsalon.com`) y sanean `EMAIL_FROM_NAME`, nombres de destinatarios y asuntos eliminando cualquier carácter de control o CRLF para impedir *SMTP Header Injection*.
   - **`Email::prepararMailerNotificacionCita()` y `Email::despacharNotificacionCita()`:** Construyen el mensaje `PHPMailer` completo (`HTML` + `AltBody` en texto plano) y delegan en:
     1. Transporte sustituible (`Email::setTransport(...)` o callable por instancia en `NotificacionCitaService`) para pruebas unitarias e integrales deterministas.
     2. Captura segura en memoria (`Email::$emailsEnviados`) cuando se ejecuta bajo PHPUnit sin transporte personalizado ni `EMAIL_FORCE_SMTP=1`, evitando conexiones TCP externas accidentales.
     3. Servidor SMTP configurado (`EMAIL_HOST` / `EMAIL_PORT`, incluyendo el servidor local de pruebas `tests/smtp_mock_server.php` durante la suite E2E en navegador).

3. **Repositorio con Bloqueo Transaccional e Idempotencia (`Repositories\NotificacionCitaRepository` y `Model\NotificacionCita`):**
   - Entidad pura `Model\NotificacionCita` desacoplada de `ActiveRecord`.
   - Todas las consultas en `NotificacionCitaRepository` usan exclusivamente sentencias preparadas (`prepare` + `bind_param` + `execute`) y encapsulan fallos SQL en `PersistenceException`.
   - **`registrarOAdquirirParaEnvio()`:** Ejecuta una transacción con `SELECT ... WHERE clave_idempotencia = ? LIMIT 1 FOR UPDATE`:
     - Si la notificación ya tiene `estado = 'enviado'`, devuelve `debe_enviar = false` y `motivo = 'ya_enviado'` sin incrementar intentos ni reenviar correo.
     - Si está en `estado = 'enviando'` o agotó `intentos >= max_intentos` (salvo `$forzarReintento = true`), omite el envío de forma segura.
     - En caso contrario, inserta o actualiza el registro en `estado = 'enviando'` antes de liberar la transacción y despachar por SMTP, evitando envíos duplicados concurrentes.
   - **`adquirirCandidataParaReintento()`:** Revalida con `FOR UPDATE` cada notificación pendiente/fallida elegible (`intentos < max_intentos` y `proximo_intento_at <= ahora`). Si es de tipo `recordatorio` y `cita_id IS NULL` (porque la cita fue cancelada antes del reintento), la marca como descartada sin enviar un recordatorio obsoleto.

4. **Servicio de Notificaciones y Aislamiento de Fallos SMTP (`Services\NotificacionCitaService` y `Services\CitaService`):**
   - **Aislamiento total post-commit en `CitaService`:**
     - En `CitaService::reservar()` y `CitaService::reservarConProfesional()`, una vez confirmada la transacción de la cita en MySQL (`COMMIT`), se invoca `$this->despacharNotificacionConfirmacionSegura($citaId)` dentro de un bloque `try / catch (\Throwable)`. Si el servidor SMTP falla o lanza excepción, la cita permanece creada en base de datos, el endpoint responde `200` con el contrato JSON intacto y la notificación queda registrada en `notificaciones_citas` con `estado = 'fallido'`, `intentos = 1`, `ultimo_error` saneado y `proximo_intento_at` programado para reintento.
     - En `CitaService::eliminar()`, tras validar permisos (`esPropietarioOAdmin`), se captura previamente el snapshot completo de la cita (`capturarSnapshotCitaParaCancelacion`), se ejecuta la eliminación atómica en `citas` y, únicamente si `filas > 0`, se invoca `notificarCancelacionCitaDesdeSnapshot()` en un bloque `try / catch (\Throwable)` aislado.
   - **Escapado HTML completo:** `NotificacionCitaService::construirContenidoHtml()` escapa todos los campos dinámicos (nombre del cliente, profesional, fecha, horario, nombres de servicios, precios y enlace `/cita`) con `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
   - **Recordatorios configurables en `America/Guayaquil` (`procesarRecordatorios`):**
     - Ventana configurable mediante argumento `$horasAnticipacion` o variable de entorno `NOTIFICACIONES_RECORDATORIO_HORAS` (`1..720`, por defecto `24` horas).
     - Evalúa el rango `[ahora, ahora + N horas]` en zona horaria explícita `America/Guayaquil`, filtra citas futuras cuya fecha/hora de inicio cae dentro de la ventana y despacha como máximo un recordatorio por cita y horario programado (`cita:{id}:recordatorio:{fecha}_{hora}`).
   - **Cola de reintentos con *backoff* exponencial (`procesarPendientesYReintentos`):**
     - Reconstruye el correo a partir del `payload_json` persistido (lo que permite reintentar tanto confirmaciones/recordatorios de citas activas como cancelaciones cuya fila en `citas` ya no existe) y aplica backoff progresivo (`5 min`, `15 min`, `45 min`, hasta `120 min`).

5. **Tarea Programada Documentada (`scripts/procesar_notificaciones.php`):**
   - Script CLI ejecutable exclusivamente desde consola (`PHP_SAPI === 'cli'`).
   - Opciones soportadas:
     - `--recordatorios`: Procesa únicamente recordatorios de citas próximas.
     - `--reintentos`: Procesa únicamente la cola de notificaciones pendientes o fallidas.
     - *(Sin flags de modo)*: Ejecuta ambos procesos en secuencia (`recordatorios` + `reintentos`).
     - `--horas=<1..720>`: Sobrescribe la ventana de anticipación en horas para recordatorios (por defecto `NOTIFICACIONES_RECORDATORIO_HORAS` o `24`).
     - `--limite=<1..500>`: Límite máximo de notificaciones a procesar en la corrida (por defecto `50`).
     - `--ahora="YYYY-MM-DD HH:MM:SS"`: Reloj de referencia en `America/Guayaquil` para pruebas deterministas.
     - `--json`: Emite resumen estructurado en JSON (`status`, `timezone`, `ahora`, `recordatorios`, `reintentos`).
   - **Ejemplo de programación en Cron (Linux / contenedor Docker cada 15 minutos):**
     ```cron
     */15 * * * * cd /var/www/html && /usr/local/bin/php scripts/procesar_notificaciones.php --json >> /var/log/appsalon_notificaciones.log 2>&1
     ```
   - **Ejemplo en Programador de Tareas de Windows (`schtasks` cada 15 minutos):**
     ```powershell
     schtasks /Create /SC MINUTE /MO 15 /TN "AppSalon_ProcesarNotificaciones" /TR "docker exec appsalon-web php /var/www/html/scripts/procesar_notificaciones.php --json" /F
     ```

### 2. Validación Ejecutada en Fase 6A (Desarrollador + Auditor Independiente — Ronda 2)

- **Correcciones aplicadas en Ronda 2 (sobre `0aa1e11d9328aa5558d43d50b5483a492850ce7f`):**
  1. **Orden determinista en `CitaRepository::findAdminCitasByFecha(string $fecha)` (`repositories/CitaRepository.php`):** Se añadió `ORDER BY citas.id ASC, citasservicios.id ASC` al final de la consulta SQL para garantizar que el orden de las citas y de sus servicios asociados (`citasservicios`) sea siempre determinista incluso cuando un servicio haya sido eliminado del catálogo (`citasservicios.servicioId = NULL` por `ON DELETE SET NULL`), preservando tanto la aserción de `ReservaConcurrenciaIntegrationTest::testInmutabilidadHistoricaAnteCambioOEliminacionPosteriorDelServicioEnCatalogo` como la agrupación secuencial por `$idCita !== $cita->id` en `views/admin/index.php`.
  2. **Limpieza explícita de `notificaciones_citas` en `setUp()` de las suites de integración:** Se añadió `DELETE FROM notificaciones_citas` (o `TRUNCATE TABLE notificaciones_citas` en `Fase5InterfazReservasIntegrationTest`) en `setUp()` de todas las pruebas de integración que limpian `citas` (`ReservaConcurrenciaIntegrationTest`, `Fase5InterfazReservasIntegrationTest`, `CitaModuloIntegrationTest`, `ApiSeguridadTest`, `DisponibilidadIntegrationTest`, `ProfesionalAgendaIntegrationTest`, `SecurityIntegrationTest`, `ServicioModuloIntegrationTest` y `UsuarioAuthIntegrationTest`), evitando que registros huérfanos con `cita_id = NULL` mantengan ocupada `clave_idempotencia` entre pruebas.
  3. **Alineación documental del formato de `clave_idempotencia` de recordatorios:** Se actualizó la documentación para reflejar el formato real generado por `NotificacionCitaService::generarClaveRecordatorio()`: `cita:{id}:recordatorio:{fecha}_{hora}` (ej. `cita:42:recordatorio:2026-11-16_11:30`).

#### A. Suite Completa PHPUnit (Ejecutada en Docker PHP 8.2.34 + MySQL 8.0 Aislado)
- **Versión efectiva:** `PHPUnit 10.5.66 by Sebastian Bergmann and contributors.` (`Runtime: PHP 8.2.34`, `Configuration: /app/phpunit.xml`).
- **Ejecución del Desarrollador (`comprehensive_project_audit_roadmap`):**
  - Comando: `docker run --rm --name appsalon-web-phpunit-f6a-r2 --network appsalon-net-phpunit-f6a-r2 -v "${PWD}:/app" -w /app -e APP_ENV=testing -e DB_HOST=appsalon-db-phpunit-f6a-r2 -e DB_PORT=3306 -e DB_USER=root -e DB_PASS=root -e DB_NAME=appsalon_f6a_test appsalon-php-test php -d variables_order=EGPCS vendor/bin/phpunit --colors=never`
  - Resultado: `OK (152 tests, 1714 assertions)` (`Time: 00:26.485, Memory: 16.00 MB`) — Código de salida `0`.
- **Ejecución Independiente del Auditor (`eaf68f52-b7bb-4e2f-80cf-485ff299e602` en `auditing_fase_4b_reservas`):**
  - Comando: `docker run --rm --network appsalon-audit6a-r2-net -v "${PWD}:/app" -w /app -e DB_HOST=appsalon-audit6a-r2-db -e DB_PORT=3306 -e DB_USER=root -e DB_PASS=root -e DB_NAME=appsalon_audit_test appsalon-php-test php -d variables_order=EGPCS vendor/bin/phpunit --colors=never`
  - Resultado: `OK (152 tests, 1714 assertions)` (`Time: 00:26.652, Memory: 14.00 MB`) — Código de salida `0`.
- **Cobertura añadida en Fase 6A:**
  - `Tests\Integration\MigrationTest`: actualización a 5 migraciones e incorporación de `testMigracion005NotificacionesCitasUpIdempotenciaSetNullYRollback()`, comprobando la creación de `notificaciones_citas`, su índice único `uq_notificaciones_clave_idempotencia`, el comportamiento `ON DELETE SET NULL` al borrar una cita conservando `cita_referencia_id` y el rollback limpio.
  - `Tests\Unit\NotificacionCitaServiceTest`: validación de `Email::resolverAppUrl()` (bloqueo de `javascript:`, `data:`, CRLF, credenciales y comillas con fallback seguro), `Email::resolverRemitente()` y prevención de *header injection*, entidad `NotificacionCita`, escapado HTML (`XSS`) e inclusión de detalles completos en `construirContenidoHtml()`, idempotencia unitaria, cálculo de ventana de recordatorios en `America/Guayaquil` y *backoff* exponencial.
  - `Tests\Integration\Fase6ANotificacionesIntegrationTest`:
    - Envío de correo de confirmación al reservar en `POST /api/citas` y de cancelación al eliminar en `POST /api/eliminar`, verificando que ambos registros (`confirmacion` y `cancelacion`) persisten en `notificaciones_citas` con `estado = 'enviado'`, `cita_id = NULL` tras el borrado y `cita_referencia_id` intacto.
    - Aislamiento de fallos SMTP (transporte que lanza excepción o devuelve `false`) tanto en `POST /api/citas` como en `POST /api/eliminar`: la cita se crea/elimina con HTTP `200`, mientras la notificación queda en `estado = 'fallido'`, `intentos = 1` y `proximo_intento_at` programado.
    - Idempotencia frente a invocaciones repetidas (`ya_enviado`, `1` solo correo despachado) y recuperación mediante `procesarPendientesYReintentos()` reconstruyendo el mensaje desde `payload_json`.
    - Recordatorios configurables en `America/Guayaquil` (`procesarRecordatorios` y script CLI `scripts/procesar_notificaciones.php --json`), incluyendo descarte automático de recordatorios pendientes si la cita fue cancelada antes del reintento.

#### B. Verificación E2E en Navegador con JavaScript Habilitado (`tests/verificar_navegador.ps1` + `tests/browser_e2e_test.js` + `tests/browser_test_report.json`)
- **Comando ejecutado:** `powershell -ExecutionPolicy Bypass -File tests/verificar_navegador.ps1`
- **Resultado Desarrollador (`RUN_ID: 2ecae295c89d4fc789f2491b65cd3ee5`):** 17 comprobaciones E2E superadas (`ADMIN-01` a `ADMIN-04`, `PROF-01`, `PROF-02`, `PRE-01`, `DISP-01`, `REC-01` a `REC-04`, `RES-01`, `ADMIN-05`, `AUTH-01`, `NOTIF-01`, `REC-05`) con `0` errores de consola, `0` peticiones fallidas, verificación en buzón SMTP mock (`tests/mailbox.json`) de los correos de confirmación y cancelación de citas (`NOTIF-01`) y verificación en MySQL de `notificaciones_citas` (`3` confirmaciones enviadas y `2` cancelaciones enviadas con historial preservado) — Código de salida `0`.
- **Resultado Auditor Independiente (`RUN_ID: 853769e6acbd48fcbb266d5713b9b725` en `auditing_fase_4b_reservas`):** 17 comprobaciones E2E superadas (`[OK]`), `0` errores de consola, `0` peticiones fallidas y verificación en MySQL (`3` confirmaciones y `2` cancelaciones) — Código de salida `0`.

---

## Fase 5: Interfaz de Reservas y Panel Administrativo

- **Rama:** `feature/fase-5-interfaz-reservas`
- **Commit de Código Auditado:** `1fb3475fe2eec5ed4dc2f6afd22bc6b1bcaabb27`
- **Commit Base (Fase 4B aprobada):** `c3353300f409a84c29254950b4be1abb2cb03125` (`83e2a24b235a72c2865453ec13ea055d96419700` + registro de auditoría Fase 4B)
- **Estado General de Fase 5:** **Completada y Auditada (`APROBADO` en `docs/AUDITORIA.md`)**

### 1. Diseño Arquitectónico, Nuevos Endpoints y Flujo Reactivo de Reserva

1. **Catálogo de Profesionales Compatibles para Reserva (`GET /api/profesionales` — `APIController::profesionales` $\rightarrow$ `ProfesionalService::consultarParaReserva`):**
   - Endpoint autenticado (`401` si no existe sesión activa).
   - Consulta únicamente profesionales activos (`activo = 1`) mediante `ProfesionalRepository::findActivos()` y sus servicios habilitados (`profesionales_servicios`) en una sola consulta agrupada (`findServicioIdsByProfesionalIds`).
   - Si recibe `?servicios=1,4` (o arreglo `servicios[]`), valida que cada ID sea un entero positivo (`422` `solicitud_invalida` si es malformado) y evalúa la compatibilidad de cada profesional (`compatible: true|false`) contra `profesionales_servicios`, devolviendo tanto la lista general `profesionales` como `profesionales_compatibles` (aquellos habilitados para realizar **todos** los servicios seleccionados).
   - Mapea cualquier `PersistenceException` a HTTP `500` (`status: 'error'`, `codigo: 'error_persistencia'`) sin exponer detalles SQL.

2. **Consulta y Cancelación Autorizada de Citas del Cliente (`GET /api/mis-citas` y `POST /api/eliminar`):**
   - **`GET /api/mis-citas` (`APIController::misCitas` $\rightarrow$ `CitaService::consultarCitasCliente` $\rightarrow$ `CitaRepository::findCitasClienteByUsuarioId`):**
     - Obtiene exclusivamente las citas cuyo `citas.usuarioId` coincide con `$_SESSION['id']` (aislamiento estricto por sesión; ignora cualquier `usuarioId` enviado por query string).
     - Proyecta mediante sentencias preparadas el profesional asignado (`LEFT JOIN profesionales`), el intervalo `[hora_inicio, hora_fin)`, `duracion_total_minutos` y el snapshot histórico de servicios (`COALESCE(cs.nombre_servicio, s.nombre)`, `COALESCE(cs.precio_servicio, s.precio)`, `COALESCE(cs.duracion_minutos, s.duracion_minutos)`).
     - Identifica explícitamente citas históricas sin profesional (`profesionalId === null` $\rightarrow$ `es_historica_sin_profesional: true`, sin inventar profesional ni duración).
     - Evalúa `es_futura` y `puede_cancelar` en zona horaria explícita `America/Guayaquil` (`CitaService::TIMEZONE`).
   - **`POST /api/eliminar` (`APIController::eliminar` $\rightarrow$ `CitaService::eliminar`):**
     - Soporta tanto el envío tradicional por formulario HTML en `/admin` (respondiendo `302` hacia `HTTP_REFERER` / `/admin`) como peticiones asíncronas `fetch` desde `#mis-citas` con `Accept: application/json` (`solicitaRespuestaJsonExplicita()`), devolviendo JSON estructurado (`200` `ok`, `403` `forbidden`, `404` `not_found`, `422` `invalid`, `500` `error`) y exigiendo validación CSRF (`exigir_csrf()`) y autorización de propietario o administrador (`esPropietarioOAdmin`).
     - Al cancelar una cita con profesional, la transacción atómica `CitaRepository::eliminarCitaAtomica()` libera de inmediato el intervalo en `citas`, permitiendo que vuelva a ofrecerse en `GET /api/disponibilidad`.

3. **Política Explícita sobre el Flujo Clásico vs. Flujo con Profesional en `POST /api/citas` (`Services\CitaService`):**
   - El frontend de Fase 5 (`src/js/app.js` / `public/build/js/app.js`) envía siempre `modo_reserva: 'profesional'` y `profesionalId` en el cuerpo de `POST /api/citas`.
   - `CitaService::debeUsarFlujoConProfesional(array $datos)` aplica una política explícita en tres niveles para evitar que el nuevo flujo cree citas sin profesional por accidente:
     1. **Exigencia global configurable (`CitaService::setExigirProfesional(true)`, `$service->setExigirProfesionalInstancia(true)` o variable de entorno `RESERVA_EXIGIR_PROFESIONAL=1`):** todas las llamadas a `POST /api/citas` exigen `profesionalId` válido y rechazan con HTTP `422` (`codigo: 'solicitud_invalida'`) cualquier intento sin profesional.
     2. **Marca explícita del nuevo flujo (`modo_reserva = 'profesional'` o `flujo_profesional = '1'`):** fuerza la validación de `profesionalId` aunque el campo llegue vacío o nulo.
     3. **Presencia de la clave `profesionalId` / `profesional_id` / `profesional` en el payload (`array_key_exists`):** incluso si su valor es `""` o `null`, se encamina a `crearReservaConProfesional()` y se rechaza con HTTP `422` (`Debe seleccionar un profesional válido.`) sin caer silenciosamente al flujo clásico.
   - Únicamente los clientes o pruebas legadas que omiten por completo dichas claves (y cuando `RESERVA_EXIGIR_PROFESIONAL` no está activo) utilizan el flujo clásico de compatibilidad hacia atrás.

4. **Interfaz Reactiva de Reservas (`views/cita/index.php`, `src/js/app.js`, `public/build/js/app.js` y SASS compilado):**
   - **Paso 1 (Servicios):** Muestra nombre, precio y duración en minutos (`duracion_minutos min`) de cada servicio. Cada tarjeta es accesible por teclado (`role="button"`, `tabindex="0"`, `aria-pressed`, activación con `Enter` / `Espacio`).
   - **Paso 2 (Profesional, Fecha y Horarios Disponibles):**
     - Selector `<select id="profesional">` poblado dinámicamente desde `GET /api/profesionales?servicios=...` mostrando únicamente profesionales activos compatibles con todos los servicios seleccionados.
     - Selector `<input id="fecha" type="date">` cuyo atributo `min` se calcula en servidor (`CitaController::index`) en zona horaria `America/Guayaquil` (`tomorrow`).
     - Grilla dinámica de intervalos `#contenedor-horarios-disponibles` alimentada desde `GET /api/disponibilidad` con botones nativos accesibles (`<button type="button" class="intervalo-btn" aria-pressed="true|false">`) que muestran `HH:MM - HH:MM`.
     - **Invalidación reactiva y control de concurrencia en cliente:** Cualquier cambio en servicios, profesional o fecha limpia inmediatamente la hora de inicio/fin seleccionada, actualiza `#hora` y descarta respuestas obsoletas de `fetch` mediante contador incremental `disponibilidadRequestId`. Si al cambiar servicios el profesional previamente elegido deja de ser compatible, se limpia `cita.profesionalId` y se informa al usuario.
     - **Estados claros de interfaz:** Mensajes diferenciados con `aria-live="polite"` para carga (`Consultando horarios disponibles...`), disponibilidad vacía (`status === 'empty'`), profesional inactivo/incompatible y error de red.
   - **Paso 3 (Resumen y Confirmación) y Mis Citas (`#mis-citas`):**
     - Muestra cliente, fecha formateada, profesional asignado, horario `[hora_inicio - hora_fin]`, duración total acumulada (`X min`), desglose de servicios con duración individual y total a pagar (`$X.XX`).
     - Previene envíos duplicados deshabilitando `#btn-reservar-cita` (`enviandoReserva = true`, `aria-busy="true"`) durante la petición `POST /api/citas`.
     - Ante conflicto HTTP `409` (`conflicto_ocupacion` o `profesional_inactivo`), conserva las selecciones del usuario, muestra alerta explicativa, retorna al Paso 2, limpia el intervalo agotado y recarga automáticamente `GET /api/disponibilidad`.
     - La sección `#mis-citas` lista las reservas del cliente autenticado y permite cancelar citas futuras con token CSRF, refrescando tanto el listado como la disponibilidad.

5. **Panel Administrativo (`views/admin/index.php`, `Models\AdminCita`, `CitaRepository::findAdminCitasByFecha`):**
   - Muestra para cada cita el profesional asignado (`Profesional: {nombre}`), el intervalo horario (`Horario: {hora_inicio} - {hora_fin} ({duracion_total_minutos} min)`) y los servicios con su nombre, duración (`({duracion_minutos} min)`) y precio históricos capturados al reservar.
   - Cuando una cita histórica carece de profesional (`profesionalId === null`), muestra explícitamente `Profesional: Sin profesional asignado (cita histórica)` y su hora original sin inventar datos.

### 2. Validación Ejecutada en Fase 5 (Desarrollador + Auditor Independiente)

#### A. Suite Completa PHPUnit (Ejecutada en Docker PHP 8.2.34 + MySQL 8.0 Aislado)
- **Versión efectiva:** `PHPUnit 10.5.66 by Sebastian Bergmann and contributors.` (`Runtime: PHP 8.2.34`, `Configuration: /app/phpunit.xml`).
- **Ejecución del Desarrollador (`comprehensive_project_audit_roadmap`):**
  - Comando: `docker run --rm --network appsalon-phpunit-f5-final-net -v "${PWD}:/app" -w /app -e DB_HOST=appsalon-phpunit-f5-final-db -e DB_PORT=3306 -e DB_USER=root -e DB_PASS=root -e DB_NAME=appsalon_test appsalon-php-test php -d variables_order=EGPCS vendor/bin/phpunit --colors=never`
  - Resultado: `OK (143 tests, 1512 assertions)` (`Time: 00:19.528, Memory: 14.00 MB`) — Código de salida `0`.
- **Ejecución Independiente del Auditor (`eaf68f52-b7bb-4e2f-80cf-485ff299e602` en `auditing_fase_4b_reservas`):**
  - Comando: `docker run --rm --network appsalon-audit5-net -v "C:/Users/jonat/.gemini/antigravity/worktrees/AppSalon_PHP_MVC_JS_SASS/auditing_fase_4b_reservas:/app" -w /app -e DB_HOST=appsalon-audit5-db -e DB_PORT=3306 -e DB_USER=root -e DB_PASS=root -e DB_NAME=appsalon_audit_test appsalon-php-test php -d variables_order=EGPCS vendor/bin/phpunit --colors=never`
  - Resultado: `OK (143 tests, 1512 assertions)` (`Time: 00:19.193, Memory: 14.00 MB`) — Código de salida `0`.
- **Cobertura añadida en Fase 5:**
  - `Tests\Unit\CitaServiceTest`: verificación de la política del endpoint clásico (`modo_reserva = 'profesional'`, clave `profesionalId` vacía/nula y flag `setExigirProfesionalInstancia(true)` / `setExigirProfesional(true)` rechazados con `422` sin caer al flujo sin profesional) y consulta de citas del cliente (`consultarCitasCliente`) con cálculo de `puede_cancelar` en `America/Guayaquil` e identificación de citas históricas sin profesional.
  - `Tests\Integration\Fase5InterfazReservasIntegrationTest`:
    - `GET /api/profesionales`: control de autenticación (`401`), listado de profesionales activos, filtrado de compatibilidad por múltiples servicios (`?servicios=1,2`) y rechazo de IDs inválidos (`422`).
    - `GET /api/mis-citas`: aislamiento estricto por `$_SESSION['id']`, preservación de snapshot histórico tras editar/eliminar servicios en catálogo e identificación explícita de citas históricas con `profesionalId = NULL`.
    - Política anti-bypass en `POST /api/citas`: rechazo `422` cuando se envía `modo_reserva = 'profesional'` sin profesional, `profesionalId = ""` o cuando `RESERVA_EXIGIR_PROFESIONAL=1` está activo, verificando `0` filas insertadas en `citas`.
    - Cancelación en modo JSON (`POST /api/eliminar` con `Accept: application/json`): rechazo `403` para otro cliente sin borrar la cita, y cancelación `200` por el propietario liberando inmediatamente el intervalo en `GET /api/disponibilidad`.
    - Renderizado integrado de `views/cita/index.php` (`min` en `America/Guayaquil`, controles accesibles y `#mis-citas`) y `views/admin/index.php` (profesional asignado, intervalo `[hora_inicio, hora_fin)`, duración total, snapshot histórico e indicación explícita `Sin profesional asignado (cita histórica)`).

#### B. Verificación E2E en Navegador con JavaScript Habilitado (`tests/verificar_navegador.ps1` + `tests/browser_e2e_test.js` + `tests/browser_test_report.json`)
- **Comando ejecutado:** `powershell -ExecutionPolicy Bypass -File tests/verificar_navegador.ps1`
- **Resultado Desarrollador (`timestamp: 2026-10-10T22:00:26.084Z`):** 15 comprobaciones E2E superadas (`ADMIN-01` a `ADMIN-04`, `PROF-01`, `PROF-02`, `PRE-01`, `DISP-01`, `REC-01` a `REC-04`, `RES-01`, `ADMIN-05`, `AUTH-01`, `REC-05`) con `0` errores de consola, `0` peticiones fallidas y verificación estricta en MySQL (`profesionalId = 2`, `hora_inicio = 11:30:00`, `hora_fin = 13:00:00`, `duracion_total_minutos = 90`, `2` servicios asociados) — Código de salida `0`.
- **Resultado Auditor Independiente (`RUN_ID: 7823d303480a43ac9f4f4326c4810d5d` en `auditing_fase_4b_reservas`):** 15 comprobaciones E2E superadas (`[OK]`), `0` errores de consola, `0` peticiones fallidas y verificación en MySQL — Código de salida `0`.

---

## Fase 4B: Reservas con Profesional, Ocupación Real y Protección contra Reservas Concurrentes

- **Rama:** `feature/fase-4b-reservas-concurrencia`
- **Commit de Código Auditado:** `83e2a24b235a72c2865453ec13ea055d96419700`
- **Commit Base (Fase 4A aceptada):** `939d84dbb9dcec67bc83421eaa5cbdc90389034a`
- **Estado General de Fase 4B:** **Completada y Auditada (`APROBADO` en `docs/AUDITORIA.md`)**

### 1. Diseño Arquitectónico, Migración Incremental e Inmutabilidad Histórica

1. **Migración Incremental (`database/migrations/004_reservas_profesional_ocupacion_historico.sql` + `_rollback.sql`):**
   - **Tabla `citas`:** Incorpora las columnas anulables `hora_inicio TIME NULL DEFAULT NULL`, `hora_fin TIME NULL DEFAULT NULL`, `duracion_total_minutos INT NULL DEFAULT NULL` y `profesionalId INT NULL DEFAULT NULL`, el índice compuesto `idx_citas_prof_fecha_intervalo (profesionalId, fecha, hora_inicio, hora_fin)` y la clave foránea `fk_citas_profesional` hacia `profesionales(id)` (`ON DELETE RESTRICT ON UPDATE CASCADE`).
   - **Tabla `citasservicios`:** Incorpora las columnas anulables de snapshot histórico `nombre_servicio VARCHAR(60) NULL DEFAULT NULL`, `precio_servicio DECIMAL(6,2) NULL DEFAULT NULL` y `duracion_minutos INT NULL DEFAULT NULL`.
   - **Representación explícita de citas históricas:** Las citas creadas antes de la Fase 4B (o mediante el flujo clásico del frontend actual mientras la Fase 5 no envíe `profesionalId`) conservan `profesionalId = NULL`, `hora_inicio = NULL`, `hora_fin = NULL` y `duracion_total_minutos = NULL`, así como `nombre_servicio = NULL`, `precio_servicio = NULL` y `duracion_minutos = NULL` en `citasservicios`. **No se inventan ni asignan profesionales retrospectivamente a citas históricas.**
   - **Inmutabilidad de reservas con profesional:** Al crear una reserva con profesional, `CitaRepository::crearReservaConProfesionalAtomica()` captura y persiste `nombre_servicio`, `precio_servicio` y `duracion_minutos` de cada servicio desde el catálogo oficial (`servicios`), además de `hora_inicio`, `hora_fin` y `duracion_total_minutos` en `citas`. `CitaRepository::findAdminCitasByFecha()` proyecta `COALESCE(citasservicios.nombre_servicio, servicios.nombre)`, `COALESCE(citasservicios.precio_servicio, servicios.precio)` y `COALESCE(citasservicios.duracion_minutos, servicios.duracion_minutos)` con `LEFT JOIN servicios`, preservando los importes y duraciones históricos si el servicio se edita o elimina posteriormente en el catálogo, y haciendo fallback transparente al catálogo en citas históricas (`NULL`).

2. **Descuento de Ocupación Real y Semántica Semiabierta `[inicio, fin)` (`Services\DisponibilidadService`):**
   - `DisponibilidadService::consultar()` incorpora `CitaRepository::findOcupacionByProfesionalEnFecha($profesionalId, $fecha)` y resta los intervalos `[hora_inicio, hora_fin)` de las citas existentes del profesional en esa fecha junto con los descansos y bloqueos vigentes.
   - **Semántica semiabierta `[inicio, fin)`:** Dos intervalos `[A_inicio, A_fin)` y `[B_inicio, B_fin)` se solapan si y solo si `A_inicio < B_fin && B_inicio < A_fin`. En consecuencia:
     - Se permiten reservas contiguas donde `finA == inicioB` o `finB == inicioA` (y reservas que terminan exactamente al iniciar un descanso, bloqueo o fin de turno, o que comienzan exactamente al finalizar uno).
     - Se rechazan todos los solapamientos parciales o totales con HTTP `409` (`status: 'conflict'`, `codigo: 'conflicto_ocupacion'`).
   - **Liberación de ocupación al eliminar:** Al eliminar una cita autorizada mediante `POST /api/eliminar` (`CitaService::eliminar` $\rightarrow$ `CitaRepository::eliminarCitaAtomica`), su intervalo deja de existir en `citas` y vuelve a ofrecerse inmediatamente en `GET /api/disponibilidad` y a admitirse en `POST /api/citas`.

3. **Orden de Bloqueo Transaccional InnoDB y Garantía de Lecturas Actuales (`Repositories\CitaRepository` y `Repositories\ProfesionalRepository`):**
   - Para evitar condiciones de carrera (*race conditions*), reservas duplicadas/solapadas y *deadlocks* entre operaciones concurrentes, todas las transacciones que reservan, eliminan citas de un profesional o mutan la agenda/estado de un profesional siguen un **orden canónico estricto de adquisición de bloqueos InnoDB**:
     1. **`profesionales` (`FOR UPDATE` por `PRIMARY KEY id`):** `SELECT id, nombre, activo FROM profesionales WHERE id = ? LIMIT 1 FOR UPDATE`. Serializa en el punto de entrada todas las operaciones concurrentes sobre el **mismo** profesional (reservas simultáneas, eliminación de citas y mutaciones administrativas en `ProfesionalRepository`: `updateWithServicios`, `updateActivo`, `replaceHorarios`, `createDescanso`, `deleteDescanso`, `createBloqueo`, `deleteBloqueo`).
     2. **`servicios` (`FOR SHARE` en orden ascendente de `PRIMARY KEY id`):** `SELECT id, nombre, precio, duracion_minutos FROM servicios WHERE id = ? LIMIT 1 FOR SHARE` iterado por IDs ordenados ascendentemente. Garantiza lectura actual de precios y duraciones oficiales y orden determinista de bloqueo entre múltiples servicios.
     3. **`profesionales_servicios` (`FOR SHARE`):** `SELECT servicioId FROM profesionales_servicios WHERE profesionalId = ? ORDER BY servicioId ASC FOR SHARE`.
     4. **Agenda del profesional (`FOR SHARE`):** `horarios_profesionales`, `descansos_profesionales` y `bloqueos_profesionales` del profesional para el día/fecha solicitados.
     5. **`citas` del profesional en la fecha (`FOR UPDATE`):** `SELECT id, fecha, hora, hora_inicio, hora_fin, duracion_total_minutos, usuarioId, profesionalId FROM citas WHERE profesionalId = ? AND fecha = ? ORDER BY COALESCE(hora_inicio, hora) ASC, id ASC FOR UPDATE`.
     6. **Escrituras (`INSERT INTO citas` e `INSERT INTO citasservicios`) y `COMMIT` (o `ROLLBACK` íntegro ante cualquier conflicto o fallo SQL).**
   - **Aislamiento `READ COMMITTED` + Lecturas Bloqueantes en `crearReservaConProfesionalAtomica`:** La transacción de reserva establece `SET TRANSACTION ISOLATION LEVEL READ COMMITTED` antes de `begin_transaction()` y ejecuta todas las lecturas de decisión con `FOR UPDATE` / `FOR SHARE`. Esto garantiza que:
     - Todas las lecturas realizadas tras adquirir el bloqueo exclusivo de `profesionales.id` sean **lecturas actuales** (*current reads*) del último estado confirmado (viendo cualquier cita, bloqueo, cambio de horario o desactivación recién confirmada por una transacción previa, y nunca un *snapshot* MVCC anterior).
     - Las lecturas sobre índices secundarios (`citas`, `horarios_profesionales`, etc.) utilicen bloqueos de registro (`REC_NOT_GAP`) sin bloquear el hueco `supremum` entre profesionales distintos, permitiendo que reservas simultáneas sobre **profesionales diferentes** (`profA != profB`) en el mismo intervalo y con los mismos servicios se ejecuten en paralelo con respuesta `200` sin interbloqueos (*deadlocks*).

4. **Contrato HTTP/JSON en `POST /api/citas` (`Controllers\APIController::guardar` y `Services\CitaService`):**
   - El `usuarioId` se toma exclusivamente de `$_SESSION['id']`. Se ignoran `usuarioId`, `id`, duraciones, precios y `hora_fin` enviados por el cliente.
   - Si la petición no incluye `profesionalId` (`profesionalId` / `profesional_id` / `profesional`), se conserva intacto el flujo clásico compatible con `src/js/app.js`.
   - Si incluye `profesionalId`, revalida y persiste atómicamente devolviendo:
     - `200`: `{"resultado": {"resultado": true, "id": ...}, "id": ..., "profesionalId": ..., "fecha": "...", "hora_inicio": "HH:MM", "hora_fin": "HH:MM", "duracion_total_minutos": ...}` (manteniendo `resultado.resultado = true` requerido por el frontend actual).
     - `401`: No autenticado.
     - `403`: Token CSRF inválido o ausente.
     - `404`: Profesional inexistente (`codigo: 'profesional_no_encontrado'`).
     - `409`: Profesional inactivo (`codigo: 'profesional_inactivo'`) o conflicto de ocupación por solapamiento (`codigo: 'conflicto_ocupacion'`).
     - `422`: Parámetros/fecha/hora inválidos (`codigo: 'solicitud_invalida'`), servicio inexistente (`codigo: 'servicio_inexistente'`), servicio incompatible (`codigo: 'servicios_incompatibles'`) o intervalo fuera de horario / en descanso / en bloqueo (`codigo: 'fuera_de_horario'`).
     - `500`: Fallo SQL real o dato corrupto en catálogo con rollback completo y sin filtrar detalles internos de MySQL.

### 2. Validación Ejecutada en Fase 4B (Desarrollador + Auditor Independiente)

#### A. Suite Completa PHPUnit (Ejecutada en Docker PHP 8.2.34 + MySQL 8.0 Aislado)
- **Versión efectiva:** `PHPUnit 10.5.66 by Sebastian Bergmann and contributors.` (`Runtime: PHP 8.2.34`, `Configuration: /app/phpunit.xml`).
- **Ejecución del Desarrollador (`comprehensive_project_audit_roadmap`):**
  - Comando: `docker run --rm --network appsalon-phpunit-net -v "${PWD}:/app" -w /app -e DB_HOST=appsalon-phpunit-db -e DB_PORT=3306 -e DB_USER=root -e DB_PASS=root -e DB_NAME=appsalon_test appsalon-php-test php -d variables_order=EGPCS vendor/bin/phpunit --colors=never`
  - Resultado: `OK (137 tests, 1399 assertions)` (`Time: 00:17.179, Memory: 14.00 MB`) — Código de salida `0`.
- **Ejecución Independiente del Auditor (`1b62e9dd-6cdf-4566-bb28-b9c138feff52` en `auditing_fase_4b_reservas`):**
  - Comando: `docker run --rm --network appsalon-audit4b-net -v "C:/Users/jonat/.gemini/antigravity/worktrees/AppSalon_PHP_MVC_JS_SASS/auditing_fase_4b_reservas:/app" -w /app -e DB_HOST=appsalon-audit4b-db -e DB_PORT=3306 -e DB_USER=root -e DB_PASS=root -e DB_NAME=appsalon_audit_test appsalon-php-test php -d variables_order=EGPCS vendor/bin/phpunit --colors=never`
  - Resultado: `OK (137 tests, 1399 assertions)` (`Time: 00:15.455, Memory: 14.00 MB`) — Código de salida `0`.
- **Cobertura añadida en Fase 4B:**
  - `Tests\Integration\MigrationTest`: verificación de migración `004_reservas_profesional_ocupacion_historico` en instalación limpia y actualización con datos previos, comprobando que las citas históricas conservan `profesionalId = NULL`, `hora_inicio = NULL`, `hora_fin = NULL`, `duracion_total_minutos = NULL` y que `citasservicios` conserva `nombre_servicio = NULL`, `precio_servicio = NULL`, `duracion_minutos = NULL`.
  - `Tests\Unit\DisponibilidadServiceTest`: descuento de citas existentes del profesional en la fecha, admisión de citas contiguas (`finA == inicioB`) y rechazo de solapamientos parciales o totales.
  - `Tests\Unit\CitaServiceTest`: delegación de reservas con profesional a la transacción atómica, cálculo de `hora_fin` y `duracion_total_minutos`, ignorado de `usuarioId`/`id`/precios/duraciones/`hora_fin` del cliente, y mapeo de estados (`200`, `404`, `409`, `422`, `500`).
  - `Tests\Integration\ReservaConcurrenciaIntegrationTest` + `tests/Integration/concurrent_reserva_worker.php`:
    - Reserva válida de uno y varios servicios con cálculo de duración, precios y `hora_fin` desde el catálogo ignorando valores manipulados del cliente.
    - Inmutabilidad histórica de `citas` y `citasservicios` tras actualizar nombre/precio/duración de un servicio o eliminar un servicio del catálogo.
    - Descuento de ocupación real en `GET /api/disponibilidad` y aceptación de citas contiguas (`finA == inicioB` e `inicio == finExistente`).
    - Rechazo con HTTP `409` (`conflicto_ocupacion`) de solapamientos parciales (por inicio, por fin, envolvente, contenido) y totales.
    - Rechazo por profesional inexistente (`404`), inactivo (`409`), servicio incompatible (`422`), fuera de horario (`422`), cruce con descanso (`422`), cruce con bloqueo (`422`) y fecha pasada o mismo día (`422`).
    - **Concurrencia real multiproceso (workers paralelos sincronizados con barrera `READY`/`GO` y conexiones InnoDB independientes):**
      1. Dos reservas simultáneas con profesionales distintos en el mismo intervalo: ambas exitosas (`200`) y `2` citas persistidas.
      2. Dos reservas simultáneas sobre el mismo profesional e intervalo solapado: exactamente una `200` y otra `409` (`conflicto_ocupacion`), verificando en MySQL que solo existe `1` cita.
      3. Mutación simultánea de agenda (inserción de bloqueo con lock `FOR UPDATE` activo, reemplazo de horarios semanales e inactivación de profesional) frente a reserva concurrente sobre ese rango: lectura actual tras adquirir el bloqueo y rechazo consistente (`422` / `409`) sin insertar citas inválidas.
    - Liberación de disponibilidad en `GET /api/disponibilidad` y nueva reserva exitosa tras eliminar una cita autorizada.
    - Conservación de citas históricas sin profesional (`NULL`) y del flujo de reserva sin `profesionalId` usado por el frontend actual.
    - Preservación de autenticación (`401`), CSRF (`403`), autorización en eliminación y rollback completo ante fallo SQL real (`500`) mediante trigger en `citasservicios` sin dejar filas huérfanas en `citas`.

#### B. Verificación E2E en Navegador con JavaScript Habilitado (`tests/verificar_navegador.ps1` + `tests/browser_e2e_test.js` + `tests/browser_test_report.json`)
- **Comando ejecutado:** `powershell -ExecutionPolicy Bypass -File tests/verificar_navegador.ps1`
- **Resultado Desarrollador (`RUN_ID: 94efb31fe250475cab44d2ecd181d5eb`):** 16 comprobaciones E2E superadas (`ADMIN-01` a `ADMIN-04`, `PROF-01`, `PROF-02`, `PRE-01`, `DISP-01`, `REC-01` a `REC-04`, `RES-01`, `ADMIN-05`, `AUTH-01`, `REC-05`) + verificación de persistencia en MySQL — Código de salida `0`.
- **Resultado Auditor Independiente (`RUN_ID: b9819badc55b4a2588a4db444a3f4b1c`):** 16 comprobaciones E2E superadas + verificación de persistencia en MySQL — Código de salida `0`.
- **Veredicto del Auditor para `83e2a24b235a72c2865453ec13ea055d96419700`:** **`APROBADO`** (ver `docs/AUDITORIA.md`).

---

## Fase 4A: Cálculo de Disponibilidad por Profesional

- **Rama:** `feature/fase-4a-disponibilidad` (commit revisado y aceptado `939d84dbb9dcec67bc83421eaa5cbdc90389034a`)
- **Commit Base (Fase 3A):** `8ad121ea1b02c6ff51e0eb3891207e50bb383218` (corrección documental sobre `25c7e23e56cb28036d72f7e6feb17dad7492a39c`)
- **Commit Previo Auditado (Fase 4A):** `7ecbc87c0dfdee096fdb7b42ce009b160244328b`
- **Estado General de Fase 4A:** **Completada y Revisada** (`939d84dbb9dcec67bc83421eaa5cbdc90389034a`)

### 1. Diseño Arquitectónico y Reglas de Dominio (`Services\DisponibilidadService`)

1. **Verificación de Profesional Activo y Compatibilidad de Servicios:**
   - Exige que el profesional exista (`ProfesionalRepository::findById`) y tenga estado activo (`activo = 1`).
   - Verifica que todos los servicios solicitados existan en el catálogo oficial (`servicios`) y que el profesional esté habilitado para realizar **todos** ellos (`profesionales_servicios`).
2. **Cálculo de Duración Total Exclusivamente desde el Catálogo y Alineación de Dominio (`1..2147483647`):**
   - Obtiene `duracion_minutos` de cada entidad `Model\Servicio` mediante `ServicioRepository::findById($id)` y suma `$duracionTotalMinutos`.
   - Ignora deliberadamente cualquier valor `duracion`, `duracion_minutos` o `duracion_total_minutos` enviado por el cliente en parámetros o estructuras de entrada.
   - **Alineación con el dominio del catálogo (`ServicioService`):** `DisponibilidadService::validarDuracionCatalogo()` valida que `duracion_minutos` sea un entero positivo en el rango `[1, 2147483647]` (sin imponer un tope artificial de `1440` al catálogo). Si un servicio válido individual (ej. `1500` minutos) o la suma de varios servicios supera la duración de las ventanas laborales del día, el cálculo produce HTTP `200` con disponibilidad vacía (`status: 'empty'`, `codigo: 'disponibilidad_vacia'`, `resultado: true`, `disponible: false`, `intervalos: []`) conservando íntegramente `duracion_total_minutos` calculada desde la base.
   - **Protección frente a datos corruptos y fallos SQL:** Si la base contiene un valor corrupto en `duracion_minutos` (cero, negativo, decimal/no entero, cadena no numérica o nulo) o se produce un fallo SQL real, se lanza `PersistenceException` y se responde HTTP `500` (`status: 'error'`, `codigo: 'error_persistencia'`).
3. **Zona Horaria `America/Guayaquil` y Múltiples Franjas Laborales del Día:**
   - Evalúa la fecha `AAAA-MM-DD` y construye los instantes de cada intervalo explícitamente en `new \DateTimeZone('America/Guayaquil')` (`DisponibilidadService::TIMEZONE`).
   - Obtiene todas las franjas laborales (`horarios_profesionales`) del día de la semana ISO-8601 (`1 = Lunes` .. `7 = Domingo`) mediante `ProfesionalRepository::findHorariosByProfesionalYDia()`.
   - Cada franja laboral se procesa de forma independiente sin fusionar turnos, garantizando que ningún intervalo atraviese huecos entre turnos ni abarque dos turnos distintos.
4. **Exclusión de Descansos y Bloqueos Aplicables con Semántica `[inicio, fin)`:**
   - Consulta los descansos del día (`findDescansosByProfesionalYDia`) y los bloqueos vigentes en la fecha (`findBloqueosByProfesionalEnFecha`, donde `fecha_inicio <= $fecha AND fecha_fin >= $fecha`).
   - Los bloqueos de día completo (`hora_inicio IS NULL OR hora_fin IS NULL`) restan el intervalo `[00:00, 24:00)` (`[0, 1440)`).
   - Los descansos y bloqueos parciales restan `[hora_inicio, hora_fin)` de cada turno laboral mediante aritmética de intervalos semiabiertos `[inicio, fin)`:
     - Dos intervalos `[A, B)` y `[R_inicio, R_fin)` se solapan si y solo si `A < R_fin && R_inicio < B`.
     - El remanente izquierdo `[A, R_inicio)` permite que una atención termine exactamente cuando comienza un descanso, bloqueo o fin de turno (`fin == R_inicio`).
     - El remanente derecho `[R_fin, B)` permite que una atención comience exactamente cuando termina una restricción (`inicio == R_fin`).
   - Solo se devuelven intervalos `[inicio, inicio + duracion_total_minutos)` contenidos íntegramente dentro de una misma ventana libre continua (`inicio + duracion_total_minutos <= ventana_fin`).
5. **Configuración del Paso entre Horas de Inicio (`DEFAULT_PASO_MINUTOS = 15`):**
   - **Supuesto técnico configurable:** Se define `DisponibilidadService::DEFAULT_PASO_MINUTOS = 15` (15 minutos) como valor inicial configurable (parametrizable por constructor, `setPasoMinutos()`, variable de entorno `AGENDA_PASO_MINUTOS` o query param `paso_minutos`), declarándolo explícitamente como supuesto técnico configurable y no como regla fija confirmada del negocio.
6. **Frontera de Alcance con Fase 4B:**
   - Esta entrega calcula disponibilidad según la configuración de agenda del profesional (`horarios_profesionales`, `descansos_profesionales`, `bloqueos_profesionales`).
   - El endpoint no está conectado todavía al botón de reservar en `/cita`, ni presenta los intervalos como garantizados frente a reservas existentes o concurrentes (cuyo cruce y bloqueo transaccional corresponden a la **Fase 4B**).

### 2. Contrato JSON del Endpoint Autenticado (`GET /api/disponibilidad`)

- **Ruta y Método:** `GET /api/disponibilidad` (`Controllers\APIController::disponibilidad`)
- **Autenticación:** Requiere sesión autenticada activa (`$_SESSION['login'] === true` e `id` entero positivo).
- **Parámetros de Consulta (`Query String`):**
  - `profesionalId` (o `profesional_id` / `profesional`): ID entero positivo del profesional.
  - `fecha`: Fecha en formato `AAAA-MM-DD`.
  - `servicios`: Lista CSV (`"1,2"`) o arreglo (`servicios[]=1&servicios[]=2`) de IDs enteros positivos de servicios.
  - `paso_minutos` *(opcional)*: Entero positivo (`1..1440`) para sobrescribir el paso entre horas de inicio.

| Caso / Estado | HTTP | `status` | `codigo` | `resultado` | `disponible` | Campos Principales |
| :--- | :---: | :--- | :--- | :---: | :---: | :--- |
| **No autenticado** | `401` | `unauthorized` | `no_autenticado` | `false` | `false` | `intervalos: []`, `error: "No autenticado"` |
| **Solicitud inválida** (parámetros ausentes/malformados, fecha inválida o servicio inexistente) | `422` | `invalid` | `solicitud_invalida` | `false` | `false` | `intervalos: []`, `error: "..."` |
| **Profesional inexistente** | `404` | `professional_not_found` | `profesional_no_encontrado` | `false` | `false` | `intervalos: []`, `error: "El profesional seleccionado no existe."` |
| **Profesional inactivo** | `409` | `professional_inactive` | `profesional_inactivo` | `false` | `false` | `intervalos: []`, `error: "El profesional seleccionado se encuentra inactivo."` |
| **Servicios incompatibles** | `422` | `incompatible_services` | `servicios_incompatibles` | `false` | `false` | `servicios_incompatibles: [id, ...]`, `intervalos: []`, `error: "..."` |
| **Disponibilidad vacía** (día sin turnos, bloqueo completo o duración válida que no cabe en las franjas) | `200` | `empty` | `disponibilidad_vacia` | `true` | `false` | `duracion_total_minutos`, `paso_minutos`, `zona_horaria`, `intervalos: []`, `aviso_alcance` |
| **Disponibilidad encontrada** | `200` | `ok` | `disponibilidad_encontrada` | `true` | `true` | `duracion_total_minutos`, `paso_minutos`, `zona_horaria`, `intervalos: [{inicio, fin, hora_inicio, hora_fin, duracion_minutos}, ...]`, `aviso_alcance` |
| **Fallo SQL o dato corrupto en catálogo (`PersistenceException`)** | `500` | `error` | `error_persistencia` | `false` | `false` | `intervalos: []`, `error: "No fue posible consultar la disponibilidad en este momento."` |

### 3. Validación Ejecutada en Fase 4A (Antigravity)

#### A. Suite Completa PHPUnit (Ejecutada en Docker PHP 8.2.34 + MySQL 8.0 Aislado)
- **Versión efectiva:** `PHPUnit 10.5.66 by Sebastian Bergmann and contributors.` (`Runtime: PHP 8.2.34`, `Configuration: /app/phpunit.xml`).
- **Comando ejecutado:**
  `docker run --rm --network appsalon-phpunit-net -v "${PWD}:/app" -w /app -e DB_HOST=appsalon-phpunit-db -e DB_PORT=3306 -e DB_USER=root -e DB_PASS=root -e DB_NAME=appsalon_test appsalon-php-test php -d variables_order=EGPCS vendor/bin/phpunit`
- **Resultado:** `OK (124 tests, 1216 assertions)` (`Time: 00:14.374, Memory: 14.00 MB`) — Código de salida `0`.
- **Cobertura añadida en Fase 4A (incluyendo regresión de auditoría):**
  - `Tests\Unit\DisponibilidadServiceTest`: configuración de `DEFAULT_PASO_MINUTOS = 15` y `America/Guayaquil`, rechazo de fechas y solicitudes inválidas, distinción entre profesional inexistente (`404`), inactivo (`409`) y servicios incompatibles (`422`), cálculo de duración total combinada desde el catálogo ignorando duraciones del cliente, múltiples franjas laborales sin cruzar huecos entre turnos, límites exactos `[inicio, fin)` con descansos y bloqueos parciales, disponibilidad vacía (`200`, `status: 'empty'`) por bloqueo de día completo o ventanas insuficientes, regresión de servicio válido de `1500` minutos (`200`, `intervalos: []`, `duracion_total_minutos: 1500`), regresión de varios servicios cuya suma supera una jornada (`200`, `intervalos: []`), rechazo de duraciones corruptas en catálogo (`0`, negativas, decimales/no enteras, nulas $\rightarrow$ `500`) y manejo de fallos SQL reales (`500`).
  - `Tests\Integration\DisponibilidadIntegrationTest`: pruebas integrales contra MySQL 8 real y `GET /api/disponibilidad` (`APIController::disponibilidad`) verificando autenticación (`401`), solicitud inválida (`422`), profesional inexistente (`404`), profesional inactivo (`409`), servicios incompatibles (`422`), múltiples franjas con duraciones combinadas, descansos y bloqueos parciales (`200`), disponibilidad vacía (`200`), regresión con servicio válido de `1500` minutos (`200`, `intervalos: []`, `duracion_total_minutos: 1500`), varios servicios cuya suma supera la jornada (`200`, `intervalos: []`), duración corrupta en base (`0`, `-45` $\rightarrow$ `500`) y fallo SQL real (`500`).

#### B. Verificación E2E en Navegador con JavaScript Habilitado (`tests/verificar_navegador.ps1` + `tests/browser_e2e_test.js` + `tests/browser_test_report.json`)
- **Comando ejecutado:** `powershell -ExecutionPolicy Bypass -File tests/verificar_navegador.ps1`
- **Resultado (`RUN_ID: b996653a5f4242819dfffd61d576d52a`):** 15 comprobaciones E2E superadas (`ADMIN-01` a `ADMIN-04`, `PROF-01`, `PROF-02`, `PRE-01`, `DISP-01`, `REC-01` a `REC-04`, `ADMIN-05`, `AUTH-01`, `REC-05`) + verificación de persistencia en MySQL — Código de salida `0`.

---

## Fase 3A: Profesionales, Duración de Servicios y Configuración de Horarios

- **Rama:** `feature/fase-3a-profesionales-horarios` (commit final `8ad121ea1b02c6ff51e0eb3891207e50bb383218` / código auditado `25c7e23e56cb28036d72f7e6feb17dad7492a39c`)
- **Commit Base (Fase 2C):** `fe16c67211d5583e859fd6ec4eb8ba55b7c03983`
- **Estado General de Fase 3A:** **Completada y Revisada**

### 1. Diseño Arquitectónico y de Dominio Elegido para Fase 3A

#### A. Esquema y Migraciones Versionadas (`database/migrations/003_profesionales_duracion_y_horarios.sql`)
1. **Duración en `servicios` (`duracion_minutos`):**
   - Se añade la columna `duracion_minutos INT NOT NULL DEFAULT 30 AFTER precio` en la tabla `servicios`.
   - **Supuesto configurable sobre servicios existentes:** El valor inicial de `30` minutos (`ServicioService::DEFAULT_DURACION_MINUTOS = 30`) asignado en la migración a los servicios preexistentes se documenta explícitamente como un **supuesto técnico configurable** para mantener compatibilidad en bases con datos previos, y **no** como un dato confirmado del negocio. Cada servicio puede configurarse individualmente desde `/servicios/crear` y `/servicios/actualizar`.
2. **Catálogo de Profesionales (`profesionales`):**
   - Tabla InnoDB con `id INT AUTO_INCREMENT PRIMARY KEY`, `nombre VARCHAR(120) NOT NULL`, `activo TINYINT(1) NOT NULL DEFAULT 1` e índice `idx_profesionales_activo (activo)`.
   - **Política de desactivación:** Se prefiere la desactivación lógica (`activo = 0`) mediante `POST /profesionales/estado` o edición en `/profesionales/actualizar` en lugar del borrado físico, conservando la integridad de las referencias históricas.
3. **Relación Profesional–Servicio (`profesionales_servicios`):**
   - Tabla puente InnoDB con `id INT AUTO_INCREMENT PRIMARY KEY`, `profesionalId INT NOT NULL`, `servicioId INT NOT NULL`, restricción única `uk_profesional_servicio (profesionalId, servicioId)` y claves foráneas hacia `profesionales(id)` y `servicios(id)`.
4. **Horarios Semanales (`horarios_profesionales`):**
   - Tabla InnoDB con `id`, `profesionalId`, `dia_semana TINYINT NOT NULL` (convención ISO-8601 `1 = Lunes` a `7 = Domingo`), `hora_inicio TIME NOT NULL` y `hora_fin TIME NOT NULL`.
5. **Descansos Semanales (`descansos_profesionales`):**
   - Tabla InnoDB con `id`, `profesionalId`, `dia_semana TINYINT NOT NULL` (`1..7`), `hora_inicio TIME NOT NULL`, `hora_fin TIME NOT NULL` y `motivo VARCHAR(120) NULL`.
6. **Bloqueos por Fecha o Intervalo (`bloqueos_profesionales`):**
   - Tabla InnoDB con `id`, `profesionalId`, `fecha_inicio DATE NOT NULL`, `fecha_fin DATE NOT NULL`, `hora_inicio TIME NULL` (nulo cuando el bloqueo es de día completo), `hora_fin TIME NULL` y `motivo VARCHAR(160) NULL`.

#### B. Capas y Responsabilidades (`Controller` $\rightarrow$ `Service` $\rightarrow$ `Repository`)
- **Entidades desacopladas de `ActiveRecord`:**
  - `Model\Servicio`: incorpora `duracion_minutos` en `sincronizarEditable()` y `validar()`.
  - `Model\Profesional`, `Model\HorarioProfesional`, `Model\DescansoProfesional`, `Model\BloqueoProfesional`: entidades de dominio puras sin herencia de `ActiveRecord` ni acceso SQL.
- **Repositorios con sentencias preparadas y `PersistenceException`:**
  - `Repositories\ServicioRepository`: extiende lecturas y escrituras preparadas para incluir `duracion_minutos`.
  - `Repositories\ProfesionalRepository`: gestiona CRUD y estado de profesionales, sincronización transaccional de `profesionales_servicios`, reemplazo/alta/baja transaccional de `horarios_profesionales`, `descansos_profesionales` y `bloqueos_profesionales`.
- **Servicios de Dominio:**
  - `Services\ServicioService`: exige `duracion_minutos` como entero positivo (`>= 1`) en `validarDatos()`, `crear()` y `actualizar()`.
  - `Services\ProfesionalService`:
    - Valida identidad de profesional, `nombre` (`1..120` caracteres), estado `activo` (`0`/`1`) y existencia real de todos los `servicioId` asociados.
    - Valida intervalos horarios (`HH:MM`, `hora_inicio < hora_fin`), rechaza horarios invertidos o de duración cero y previene solapamientos dentro del mismo día y profesional.
    - En `guardarHorariosSemanales()`, exige que `$horariosRaw` sea un arreglo no vacío de filas válidas, rechaza valores malformados de `activo` o `enviado` y permite desactivar todos los días mediante el envío explícito de filas inactivas (`activo = '0'`).
    - Verifica coherencia entre horarios y descansos: todo descanso debe estar contenido dentro de una franja laboral activa del mismo día sin consumir la totalidad del turno ni solaparse con otro descanso; asimismo, impide guardar o eliminar horarios si dejarían descansos existentes fuera de turno.
    - Valida bloqueos por fecha completa o por intervalo horario (`fecha_inicio <= fecha_fin`, `hora_inicio < hora_fin`, sin solapamiento con otros bloqueos del mismo profesional).
    - **Zona horaria explícita `America/Guayaquil`:** Todas las reglas de fechas de agenda en `ProfesionalService` (`TIMEZONE = 'America/Guayaquil'`) y `CitaService` (`TIMEZONE = 'America/Guayaquil'`) operan explícitamente sobre `new \DateTimeZone('America/Guayaquil')` mediante `DateTimeImmutable`, garantizando coherencia entre el cálculo del día actual, el día de la semana y las validaciones de reservas y bloqueos sin desalinear los `DATETIME` UTC de tokens en MySQL.
- **Controladores y Vistas:**
  - `Controllers\ProfesionalController`:
    - Protege todas las acciones con `iniciar_sesion_segura()`, `isAdmin()` y `exigir_csrf()` en peticiones `POST`; distingue errores de validación (`422`), no encontrado (`302`) y fallos de persistencia (`500`).
    - **Validación estructural previa en `/profesionales/horarios` (`validarSolicitudAgenda`):** Rechaza antes de ejecutar cualquier operación en la capa de servicio o base de datos las acciones desconocidas o no escalares (eliminando cualquier `default` hacia `guardar_horarios`), la ausencia o carácter escalar de `horarios`, y los valores inválidos de `activo` en las filas, respondiendo HTTP `422` y conservando íntegramente la agenda persistida.
    - **Conservación y edición de múltiples franjas diarias (`renderizarVistaHorarios` + `views/profesionales/horarios.php`):** Agrupa y renderiza todas las franjas existentes por día (`$horariosPorDia[$dia][]`), permitiendo añadir nuevas franjas (`+ Añadir franja`), retirar franjas individuales (`Retirar franja`), guardar sin cambios preservando todas las franjas y sus horas exactas, o desactivar explícitamente todos los días mediante el envío válido del formulario (`activo = '0'`).
  - **Frontera de compatibilidad:** El flujo de reserva del cliente (`/cita`, `/api/servicios`, `/api/citas`) y la tabla `citas` mantienen su contrato actual sin añadir columnas obligatorias ni atribuir profesionales retrospectivamente.

### 2. Correcciones de Auditoría sobre `d54a66c3f2c9a9ed769d1ea39fb6a1bfa06d594d`

1. **Rechazo de solicitudes inválidas o malformadas antes de modificar la agenda (HTTP `422`):**
   - `ProfesionalController::validarSolicitudAgenda()` valida la acción (`guardar_horarios`, `agregar_descanso`, `eliminar_descanso`, `agregar_bloqueo`, `eliminar_bloqueo`) y la estructura de los datos antes de invocar `ProfesionalService`.
   - `ProfesionalService::guardarHorariosSemanales()` exige un arreglo no vacío de filas y valida explícitamente `activo` (`'0'`/`'1'`) y `enviado`, rechazando entradas ausentes, escalares o con valores inválidos de `activo` sin interpretarlas silenciosamente como días desactivados, al tiempo que conserva la desactivación explícita de todos los días cuando el formulario envía filas válidas con `activo = '0'`.
2. **Conservación de todas las franjas horarias por día en controlador y vista:**
   - `ProfesionalController::renderizarVistaHorarios()` agrupa todas las franjas de cada día en `$horariosPorDia[$h->dia_semana][]` en lugar de conservar solo la primera.
   - `views/profesionales/horarios.php` renderiza todas las franjas de cada día con claves únicas `horarios[{dia}_{indice}]`, controles para añadir (`+ Añadir franja`) y retirar (`Retirar franja`) franjas explícitamente, y respaldo `activo = 0` por fila para enviar desactivaciones válidas.

### 3. Validación Ejecutada en Fase 3A (Antigravity)

#### A. Suite Completa PHPUnit (Ejecutada en Docker PHP 8.2.34 + MySQL 8.0 Aislado)
- **Versión efectiva (según `composer.lock` / `composer.json` `^10.5`):** `PHPUnit 10.5.66 by Sebastian Bergmann and contributors.` (`Runtime: PHP 8.2.34`, `Configuration: /app/phpunit.xml`).
- **Comando ejecutado:**
  `docker run --rm --network appsalon-phpunit-net -v "${PWD}:/app" -w /app -e DB_HOST=appsalon-phpunit-db -e DB_PORT=3306 -e DB_USER=root -e DB_PASS=root -e DB_NAME=appsalon_test appsalon-php-test php -d variables_order=EGPCS vendor/bin/phpunit`
- **Resultado:** `OK (101 tests, 905 assertions)` (`Time: 00:15.450, Memory: 12.00 MB`) — Código de salida `0`.
- **Cobertura añadida en Fase 3A y corrección de auditoría:**
  - `Tests\Unit\ServicioServiceTest`: validación de `duracion_minutos` como entero positivo (`>= 1`), rechazo de `0`, negativos, decimales, vacíos o no escalares, y verificación del supuesto configurable `DEFAULT_DURACION_MINUTOS = 30`.
  - `Tests\Unit\ProfesionalAgendaServiceTest`: validación de profesionales y servicios asociados, intervalos horarios, múltiples franjas diarias, rechazo de entradas malformadas (`horarios` vacío/no array, `activo` inválido), desactivación explícita de todos los días (`activo = '0'`), rechazo de horarios invertidos y solapados, coherencia entre horarios y descansos, bloqueos por día completo e intervalo, y coherencia de zona horaria `America/Guayaquil` entre `ProfesionalService` y `CitaService`.
  - `Tests\Integration\MigrationTest`: verificación de migración `003_profesionales_duracion_y_horarios` en instalaciones desde cero y con datos previos (`duracion_minutos = 30` y tablas `profesionales`, `profesionales_servicios`, `horarios_profesionales`, `descansos_profesionales`, `bloqueos_profesionales`).
  - `Tests\Integration\ServicioModuloIntegrationTest`: ciclo CRUD de servicios con `duracion_minutos` en MySQL real y vistas HTTP.
  - `Tests\Integration\ProfesionalAgendaIntegrationTest`: control de permisos `isAdmin()` y CSRF en todas las rutas de `/profesionales*`, rechazo con HTTP `422` y preservación íntegra de la base de datos ante acción desconocida, acción no escalar, `horarios` ausente, `horarios` escalar y `activo` inválido, conservación y renderizado de múltiples franjas por día y desactivación explícita de todos los días, ciclo completo con MySQL real, rollback transaccional con respuesta HTTP 500 ante fallos SQL y compatibilidad con el flujo actual de reservas.

#### B. Verificación E2E en Navegador con JavaScript Habilitado (`tests/verificar_navegador.ps1` + `tests/browser_e2e_test.js` + `tests/browser_test_report.json`)
- **Comando ejecutado:** `powershell -ExecutionPolicy Bypass -File tests/verificar_navegador.ps1` (servidor SMTP simulado aislado mediante `tests/smtp_mock_server.php`, sin uso de Mailpit).
- **Resultado (`RUN_ID: 20c51cdf44484c8dadefbcdfebb9bd5b`, registrado en `tests/browser_test_report.json`):** 14 comprobaciones E2E superadas — Código de salida `0`:
  - `ADMIN-01`: Acceso de administrador a `/servicios` y listado inicial del catálogo (`3 servicios listados en /servicios.`).
  - `ADMIN-02`: Validación de entradas inválidas y creación de nuevo servicio con duración en `/servicios/crear` (`'Masaje Capilar Relax' ($95.50, 45 min) creado.`).
  - `ADMIN-03`: Actualización de servicio existente y su duración en `/servicios/actualizar` (`'Masaje Capilar Relax' actualizado a 'Masaje Capilar Premium' ($115.00, 60 min).`).
  - `ADMIN-04`: Eliminación de servicio con CSRF en `/servicios/eliminar` (`'Servicio Temporal Borrar' eliminado; catálogo conserva 4 servicios activos.`).
  - `PROF-01`: Gestión de profesionales, servicios asociados, horarios, descansos, bloqueos y desactivación/reactivación (`'Sofía Andrade' creada con servicios, horario Lunes 09:00-18:00, descanso 13:00-14:00, bloqueo 2026-10-12 15:00-16:30 y ciclo Inactivo/Activo verificado.`).
  - `PROF-02`: Carga de múltiples franjas del mismo día, guardado sin cambios verificado en MySQL y retirada explícita de franja individual (`Martes 08:00-12:00 y 14:00-18:00 preservados en MySQL al guardar sin cambios; retirada explícita eliminó solo 08:00-12:00 conservando 14:00-18:00.`).
  - `PRE-01`: Login interactivo de cliente y redirección autorizada a `/cita`.
  - `REC-01`: Carga asíncrona de `/api/servicios` reflejando CRUD y alternancia de `.seleccionado` (`4 servicios renderizados; IDs seleccionados: [1, 4]`).
  - `REC-02`: Navegación fluida por paginador y tabs con preservación de estado en cliente (`nombre prellenado: 'Carlos Mendoza'`).
  - `REC-03`: Validación interactiva de restricciones en fecha (no FDS, sábado probado `2026-10-17`) y horario (`10:00-18:00`), aceptando fecha `2026-10-12` y hora `11:30`.
  - `REC-04`: Renderizado de resumen, envío asíncrono con CSRF, respuesta 200 JSON y alerta SweetAlert2 (`POST /api/citas exitoso (id: 1)`).
  - `ADMIN-05`: Consulta administrativa por fecha en `/admin?fecha=2026-10-12` y eliminación de cita temporal ID 2 con CSRF en `/api/eliminar`, conservando cita principal ID 1 (`$195`).
  - `AUTH-01`: Ciclo de autenticación y ciclo de vida de cuenta (`/logout`, `/crear-cuenta` con `smtp_mock_server.php`, rechazo de login no confirmado con mensaje unificado, `/reenviar-confirmacion` y `/olvide` sin enumeración).
  - `REC-05`: Ausencia estricta de errores de consola (`0`) y peticiones de red fallidas (`0`).
- **Verificación de persistencia en MySQL (`appsalon_browser_test`):**
  - Servicio CRUD: `4 | Masaje Capilar Premium | 115.00 | 60`
  - Profesional y agenda (`id | activo | servicios | horarios | descansos | bloqueos`): `1 | 1 | 2 | 2 | 1 | 1` (Lunes `09:00-18:00` y Martes `14:00-18:00` tras retirar `08:00-12:00` en `PROF-02`).
  - Cita persistida (`id | fecha | hora | usuarioId | servicios`): `1 | 2026-10-12 | 11:30:00 | 1 | 2`.

---

## Fase 2C: Separación de Responsabilidades en Usuarios y Autenticación

- **Rama:** `feature/fase-2c-usuarios-autenticacion`
- **Commit Base (Fase 2B):** `b0295c1378be0d7bf816ffd32e090a8e9f114292`
- **Estado General de Fase 2C:** **Completada y Revisada** (`fe16c67211d5583e859fd6ec4eb8ba55b7c03983`)

### 1. Arquitectura Implementada (`Controller` $\rightarrow$ `Service` $\rightarrow$ `Repository`)

| Capa / Clase | Archivo | Responsabilidad Exclusiva |
| :--- | :--- | :--- |
| **Controlador HTTP** (`Controllers\LoginController`) | [controllers/LoginController.php](file:///controllers/LoginController.php) | Gestión exclusiva de transporte HTTP, inicio y destrucción de sesión (`iniciar_sesion_segura`, `session_regenerate_id(true)`, `session_destroy`), protección CSRF (`exigir_csrf`), códigos de estado HTTP (`200`, `302`, `422`, `429`, `500`), cabeceras (`Retry-After`, `Location`), renderizado de vistas y redirecciones. Inyección sencilla mediante `setAuthService()`. |
| **Servicio de Dominio** (`Services\AuthService`) | [services/AuthService.php](file:///services/AuthService.php) | Reglas de negocio de inicio de sesión (`login`), registro público (`registrar`), confirmación de cuenta (`confirmarCuenta`), solicitud de recuperación (`solicitarRecuperacion`), reenvío de confirmación (`reenviarConfirmacion`) y validación/restablecimiento de contraseña (`validarTokenRecuperacion`, `restablecerPassword`). Recibe datos y contexto explícitos (`$datos`, `$ip`, `$tokenRaw`); jamás accede a `$_POST`, `$_GET` ni `$_SESSION`. |
| **Repositorio de Persistencia** (`Repositories\UsuarioRepository`) | [repositories/UsuarioRepository.php](file:///repositories/UsuarioRepository.php) | Consultas y escrituras sobre la tabla `usuarios` mediante sentencias preparadas (`findById`, `findByEmail`, `existsByEmail`, `create`, `findByValidToken`, `confirmAccountByToken`, `resetPasswordByToken`, `issueRecoveryToken`, `issueConfirmationToken`) con manejo explícito de errores SQL mediante `PersistenceException`. |
| **Entidad de Dominio** (`Model\Usuario`) | [models/Usuario.php](file:///models/Usuario.php) | Entidad desacoplada de `ActiveRecord` (`is_subclass_of(Usuario::class, ActiveRecord::class) === false`). Conserva las propiedades de dominio, protección contra asignación masiva (`sincronizarRegistro` y `sincronizar`), validaciones de campos, hashing/verificación de contraseñas (`password_hash`, `password_verify`, `$observadorVerificacionPassword`) y generación de tokens criptográficos (`generarTokenSeguro`). `Model\ActiveRecord` se conserva disponible en el proyecto. |

### 2. Garantías de Seguridad y Reglas de Negocio Preservadas (Fase 2C)

- **Protección contra asignación masiva (*Mass Assignment*):** `Usuario::sincronizarRegistro()` (y su alias `sincronizar()`) acepta únicamente la lista blanca `['nombre', 'apellido', 'email', 'password', 'telefono']` de tipo `string`, forzando en el servidor `admin = '0'`, `confirmado = '0'`, `id = null`, `token = ''`, `token_hash = null`, `token_tipo = null` y `token_expira = null`. `UsuarioRepository::create()` nunca inserta un `id` proveniente del cliente.
- **Hash y verificación de contraseñas:** Se mantienen `password_hash(..., PASSWORD_BCRYPT)` y `password_verify()` en `Model\Usuario`, junto con el hook de instrumentación `Usuario::$observadorVerificacionPassword` empleado por las pruebas de concurrencia real en el flujo de login.
- **Tokens criptográficos seguros, almacenamiento con hash SHA-256 y consumo atómico de un solo uso:**
  - Generación con `bin2hex(random_bytes(32))`, almacenando `token = NULL`, `token_hash = hash('sha256', $tokenRaw)`, `token_tipo` (`'confirmacion'` o `'recuperacion'`) y `token_expira`.
  - Consumo atómico en `UsuarioRepository::confirmAccountByToken()` y `UsuarioRepository::resetPasswordByToken()` mediante una única sentencia `UPDATE ... WHERE token_hash = ? AND token_tipo = ? AND token_expira >= NOW() LIMIT 1` comprobando `$stmt->affected_rows === 1`.
- **Emisión atómica de tokens sin sobrescribir cambios concurrentes:**
  - `UsuarioRepository::issueRecoveryToken()` ejecuta `UPDATE usuarios SET token = NULL, token_hash = ?, token_tipo = 'recuperacion', token_expira = ? WHERE id = ? AND confirmado = '1' LIMIT 1`.
  - `UsuarioRepository::issueConfirmationToken()` ejecuta `UPDATE usuarios SET token = NULL, token_hash = ?, token_tipo = 'confirmacion', token_expira = ? WHERE id = ? AND confirmado = '0' LIMIT 1`.
  - Ninguna de las dos operaciones toca `password`, `admin` ni revierte `confirmado = '1'` ante solicitudes concurrentes.
- **Envío de correo estrictamente posterior a la persistencia exitosa:** En `AuthService::registrar()`, `solicitarRecuperacion()` y `reenviarConfirmacion()`, el despacho de `Email::enviarConfirmacion()` o `Email::enviarInstrucciones()` ocurre únicamente después de que `create()`, `issueRecoveryToken()` o `issueConfirmationToken()` confirmen la escritura en MySQL. Si la persistencia falla o afecta `0` filas, no se envía ningún correo.
- **Presupuesto compartido de Rate Limiting por IP:** Un inicio de sesión válido ejecuta exclusivamente `RateLimiter::limpiarIntentos($db, RateLimiter::TIPO_EMAIL_LOGIN, $email)` y jamás reinicia `RateLimiter::TIPO_IP_LOGIN`.
- **Regeneración de sesión y revocación de privilegios:** En `LoginController::login()`, tras `STATUS_OK`, se limpia `$_SESSION = []`, se ejecuta `session_regenerate_id(true)` y solo se asigna `$_SESSION['admin'] = '1'` cuando `(string)$usuario->admin === '1'` (`unset($_SESSION['admin'])` para clientes).
- **Diferenciación de estados y prevención de enumeración / fugas SQL:**
  - `STATUS_INVALID_TYPE` (`422`): cargas no escalares (arreglos en `email` o `password`).
  - `STATUS_INVALID` / `STATUS_CONFLICT`: errores de validación de campos o tokens inválidos/expirados.
  - `STATUS_UNAUTHORIZED`: credenciales inválidas o cuenta no verificada con mensaje unificado (`"Credenciales incorrectas o la cuenta no ha sido verificada"`).
  - `STATUS_RATE_LIMITED` (`429` + cabecera `Retry-After`): bloqueo por límite de intentos.
  - `STATUS_ERROR` (`500`): fallo SQL capturado mediante `PersistenceException` y registrado en `error_log` sin exponer mensajes internos de MySQL al usuario. En `/olvide` y `/reenviar-confirmacion` se preservan los mensajes genéricos anti-enumeración ante cuentas inexistentes o en estado distinto.

### 3. Validación Ejecutada en Fase 2C (Antigravity)

#### A. Suite Completa PHPUnit (Ejecutada en Docker PHP 8.2 + MySQL 8.0 Aislado)
- **Resultado:** `OK (87 tests, 667 assertions)` — Código de salida `0`.

#### B. Verificación E2E en Navegador con JavaScript Habilitado (`tests/verificar_navegador.ps1` + `tests/browser_e2e_test.js`)
- **Resultado:** 12 comprobaciones E2E superadas (`ADMIN-01` a `ADMIN-05`, `PRE-01`, `REC-01` a `REC-05`, `AUTH-01`) + verificación de persistencia en MySQL — Código de salida `0`.

---

## Fase 2B: Separación de Responsabilidades en el Flujo de Citas y Reservas

- **Rama:** `feature/fase-2b-citas-repositorios` (commit revisado `b0295c1378be0d7bf816ffd32e090a8e9f114292`)
- **Commit Base (Fase 2A):** `4a450301304be25a4829d89303ad52edfd40028f`
- **Estado General de Fase 2B:** **Completada y Revisada**

---

## Fase 2A: Separación de Responsabilidades en el Módulo de Catálogo de Servicios

- **Rama:** `feature/fase-2-servicios-repositorios` (commit revisado `4a450301304be25a4829d89303ad52edfd40028f`)
- **Commit Base (`main`):** `bc1529bde644e1187be98332e3db912b80773cab`
- **Estado General de Fase 2A:** **Completada y Revisada**

---

## Entrega 1: Seguridad y Consistencia del Sistema Actual (Cerrada y Fusionada en `main`)

- **Rama:** `feature/seguridad-y-consistencia-inicial` (fusionada en `main` en `bc1529bde644e1187be98332e3db912b80773cab`)
- **Estado:** **Aprobada y Cerrada**

---

## Estado Global de Fases del Proyecto

| Fase | Alcance Principal | Estado |
| :--- | :--- | :---: |
| **Entrega 1** | Seguridad y consistencia inicial (Auth, CSRF, Rate Limiting, Tokens, Transacciones, Migraciones) | `Completada y en main` |
| **Fase 2A** | Separación de responsabilidades en módulo de catálogo de servicios (`Controller -> Service -> Repository`) | `Completada` |
| **Fase 2B** | Separación de responsabilidades en el flujo de citas y reservas (`CitaRepository`, `CitaService`) | `Completada` |
| **Fase 2C** | Separación de responsabilidades en usuarios y autenticación (`UsuarioRepository`, `AuthService`, desacoplamiento de `Usuario`) | `Completada` |
| **Fase 3A** | Profesionales, servicios con duración, horarios, descansos y bloqueos | `Completada` |
| **Fase 4A** | Cálculo de disponibilidad por profesional según agenda (`DisponibilidadService`, `GET /api/disponibilidad`) | `Completada` |
| **Fase 4B** | Integración de disponibilidad con citas existentes, bloqueo transaccional y prevención de reservas simultáneas | `Completada` |
| **Fase 5** | Interfaz accesible de reservas y panel administrativo | `Completada` |
| **Fase 6A** | Notificaciones transaccionales de citas, recordatorios configurables y cola de reintentos idempotente | `Completada` |
| **Fase 6B** | Reportes administrativos de reservas, ocupación por profesional, importes históricos y exportación CSV segura | `Completada` |
| **Fase 6C** | Base para pagos (*Payment Gateway Interface*, proveedor simulado e idempotencia de notificaciones) | `Completada` |
| **Cierre Técnico** | Auditoría de seguridad/rutas/cabeceras, revisión de índices SQL, CI en GitHub Actions, Docker reproducible, scripts de backup/restore probados y guía de operaciones | `Completada (En Auditoría)` |



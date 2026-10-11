# Hoja de Ruta (Roadmap) y Ciclo de Entregas — AppSalon

## 1. Metodología y Organización de Agentes

Cada fase del proyecto se ejecuta bajo la coordinación de tres roles con responsabilidades separadas:

- **Coordinador (`e9788218-5667-449d-8c01-3245505116ca`):** Mantiene el contexto global (`docs/CONTEXTO_PROYECTO.md`, `docs/ROADMAP.md`, `docs/AUDITORIA.md`, `PROGRESS.md`), controla el alcance de cada fase, coordina la comunicación directa entre Desarrollador y Auditor, y verifica que ninguna entrega avance sin aprobación formal de auditoría.
- **Desarrollador (`subagente desarrollador`):** Trabaja en `C:\Users\jonat\.gemini\antigravity\worktrees\AppSalon_PHP_MVC_JS_SASS\comprehensive_project_audit_roadmap`. Implementa funcionalidades, ejecuta pruebas unitarias, de integración, concurrencia y navegador en Docker aislado, corrige hallazgos y publica ramas `feature/*`.
- **Auditor (`subagente 1b62e9dd-6cdf-4566-bb28-b9c138feff52`):** Trabaja en un checkout separado (`C:\Users\jonat\.gemini\antigravity\worktrees\AppSalon_PHP_MVC_JS_SASS\auditing_fase_4b_reservas`). Inspecciona un SHA específico sin modificar el código del desarrollador, ejecuta las suites de pruebas en su propio entorno Docker aislado y emite veredicto (`APROBADO`, `CORRECCIONES NECESARIAS` o `BLOQUEADO`) en `docs/AUDITORIA.md`.

### Ciclo Obligatorio por Entrega
`implementar → probar → commit → auditoría del SHA → corregir → volver a probar y auditar → cerrar fase`

> **Regla de control de bloqueos:** Si tras tres rondas no se resuelve un mismo problema, se detiene el ciclo y se informa del bloqueo con evidencia técnica y una propuesta concreta.

---

## 2. Estado de Entregas Completadas y En Curso

| Fase | Rama | SHA de Referencia | Estado |
| :--- | :--- | :--- | :---: |
| **Entrega 1** | `feature/seguridad-y-consistencia-inicial` | `bc1529bde644e1187be98332e3db912b80773cab` | **Aprobada y en `main`** |
| **Fase 2A** | `feature/fase-2-servicios-repositorios` | `4a450301304be25a4829d89303ad52edfd40028f` | **Aprobada** |
| **Fase 2B** | `feature/fase-2b-citas-repositorios` | `b0295c1378be0d7bf816ffd32e090a8e9f114292` | **Aprobada** |
| **Fase 2C** | `feature/fase-2c-usuarios-autenticacion` | `fe16c67211d5583e859fd6ec4eb8ba55b7c03983` | **Aprobada** |
| **Fase 3A** | `feature/fase-3a-profesionales-horarios` | `8ad121ea1b02c6ff51e0eb3891207e50bb383218` | **Aprobada** |
| **Fase 4A** | `feature/fase-4a-disponibilidad` | `939d84dbb9dcec67bc83421eaa5cbdc90389034a` | **Aprobada** |
| **Fase 4B** | `feature/fase-4b-reservas-concurrencia` | `83e2a24b235a72c2865453ec13ea055d96419700` | **Aprobada (`APROBADO`)** |
| **Fase 5** | `feature/fase-5-interfaz-reservas` | `1fb3475fe2eec5ed4dc2f6afd22bc6b1bcaabb27` | **Aprobada (`APROBADO`)** |
| **Fase 6A** | `feature/fase-6a-notificaciones` | `4fe475c02cc3dcd38a79ad7ec4ce5ef48f9e58ec` | **Aprobada (`APROBADO`)** |
| **Fase 6B** | `feature/fase-6b-reportes` | `fb82ec7f59571dca82bf738a30e79c2d19489894` | **Aprobada (`APROBADO`)** |
| **Fase 6C** | `feature/fase-6c-base-pagos` | `8178b830bc03197775fa653857e66ec74eb290db` | **Aprobada (`APROBADO`)** |
| **Cierre Técnico** | `feature/cierre-tecnico` | *(pendiente de auditoría)* | **En auditoría** |

---

## 3. Alcance Detallado de Fases

### Fase 4B — Cerrada con Veredicto `APROBADO` (`83e2a24b235a72c2865453ec13ea055d96419700`)
- Verificada en checkout separado (`auditing_fase_4b_reservas`) con `PHPUnit 10.5.66` (`137 tests, 1399 assertions`, exit `0`) y suite E2E en navegador (`RUN_ID: b9819badc55b4a2588a4db444a3f4b1c`, 16 comprobaciones `[OK]`, exit `0`). Ver detalle en `docs/AUDITORIA.md`.

### Fase 5 — Cerrada con Veredicto `APROBADO` (`1fb3475fe2eec5ed4dc2f6afd22bc6b1bcaabb27`)
- Selección de servicios con duración visible (`duracion_minutos min`) y filtrado dinámico de profesionales activos compatibles (`GET /api/profesionales`).
- Calendario con fecha mínima calculada en `America/Guayaquil` y horarios disponibles obtenidos dinámicamente (`GET /api/disponibilidad`).
- Resumen completo con profesional, intervalo `[hora_inicio, hora_fin)`, duración total y precio total.
- Actualización reactiva al cambiar servicios, profesional o fecha, invalidando selecciones obsoletas y descartando respuestas asíncronas desfasadas (`disponibilidadRequestId`).
- Estados visuales de carga, error y disponibilidad vacía (`status === 'empty'`).
- Gestión de conflictos HTTP `409` con recarga automática de intervalos y prevención de envíos duplicados (`enviandoReserva`).
- Consulta (`GET /api/mis-citas`) y cancelación autorizada (`POST /api/eliminar` con soporte JSON y CSRF) de citas por parte del cliente en `#mis-citas`.
- Panel administrativo (`/admin`) con profesional asignado, intervalo horario (`hora_inicio - hora_fin`) y snapshot histórico de servicios, identificando explícitamente citas antiguas (`Sin profesional asignado (cita histórica)`) sin inventar información.
- Diseño móvil accesible, navegación por teclado (`role="button"`, `aria-pressed`, `:focus-visible`) y etiquetas asociadas.
- Política explícita anti-bypass del endpoint clásico en `CitaService` (`modo_reserva = 'profesional'`, presencia de clave `profesionalId` y flag configurable `RESERVA_EXIGIR_PROFESIONAL`).
- Verificada por Desarrollador y Auditor independiente (`eaf68f52-b7bb-4e2f-80cf-485ff299e602` en `auditing_fase_4b_reservas`) con `PHPUnit 10.5.66` (`OK (143 tests, 1512 assertions)`, exit `0`) y suite E2E en navegador (`RUN_ID: 7823d303480a43ac9f4f4326c4810d5d`, 15 comprobaciones `[OK]`, `0` errores de consola, `0` peticiones fallidas, exit `0`). Ver detalle en `docs/AUDITORIA.md`.

### Fase 6A — Cerrada con Veredicto `APROBADO` (`4fe475c02cc3dcd38a79ad7ec4ce5ef48f9e58ec`)
- Migración incremental `005_notificaciones_citas.sql` + `005_notificaciones_citas_rollback.sql` (`notificaciones_citas` con `UNIQUE KEY uq_notificaciones_clave_idempotencia`, `cita_id INT NULL` con `ON DELETE SET NULL` y `cita_referencia_id INT NOT NULL` para conservar el historial de confirmación y cancelación tras borrar la cita).
- Correos transaccionales de confirmación y cancelación de citas con escapado HTML completo (`htmlspecialchars`) e información de profesional, fecha, horario, duración, desglose de servicios y total.
- Recordatorios configurables en zona horaria `America/Guayaquil` (`NOTIFICACIONES_RECORDATORIO_HORAS`, por defecto `24` horas) mediante tarea programada documentada (`scripts/procesar_notificaciones.php`).
- Envíos idempotentes con bloqueo transaccional `FOR UPDATE` (`registrarOAdquirirParaEnvio`) y cola de reintentos seguros con *backoff* exponencial (`procesarPendientesYReintentos`), descartando recordatorios pendientes si la cita fue cancelada.
- Aislamiento estricto de fallos SMTP post-commit en `CitaService` (`reservar`, `reservarConProfesional`, `eliminar`) para que un error de correo nunca revierta una reserva o cancelación confirmada en base de datos.
- Configuración segura de enlaces (`Email::resolverAppUrl`) y remitentes (`Email::resolverRemitente`, `Email::sanearTextoCabecera`); pruebas automatizadas con transporte sustituible y buzón SMTP mock (`tests/smtp_mock_server.php`).
- Verificada por Desarrollador y Auditor independiente (`eaf68f52-b7bb-4e2f-80cf-485ff299e602` en `auditing_fase_4b_reservas`, Ronda 2 sobre `4fe475c02cc3dcd38a79ad7ec4ce5ef48f9e58ec`) con `PHPUnit 10.5.66` (`OK (152 tests, 1714 assertions)`, exit `0`) y suite E2E en navegador (`RUN_ID: 853769e6acbd48fcbb266d5713b9b725`, 17 comprobaciones `[OK]` incluyendo `NOTIF-01`, `0` errores de consola, `0` peticiones fallidas, exit `0`). Ver detalle en `docs/AUDITORIA.md`.

### Fase 6B — Cerrada con Veredicto `APROBADO` (`fb82ec7f59571dca82bf738a30e79c2d19489894`)
- Reportes administrativos de reservas por rango de fechas (`fecha_inicio`, `fecha_fin` hasta 366 días en `America/Guayaquil`), profesional (`profesional_id`, incluyendo `'sin_profesional'`) y servicio (`servicio_id`) en `GET /admin/reportes`.
- Métricas de ocupación respecto de la jornada neta disponible del profesional (`horarios_profesionales` menos `descansos_profesionales` y `bloqueos_profesionales` mediante recorte de intervalos semiabiertos `[inicio, fin)`, manejando disponibilidad `0` sin división por cero).
- Importes y duraciones calculados estrictamente a partir de los snapshots históricos capturados al reservar (`citasservicios.nombre_servicio`, `precio_servicio`, `duracion_minutos`), inmutables frente a ediciones o eliminaciones posteriores del catálogo.
- Distinción explícita en UI, servicio y CSV entre **Importe Reservado (Comprometido Histórico)** (`importe_reservado_total`) e **Ingreso Efectivamente Pagado** (`ingreso_pagado_total`, `$0.00` para citas en `pendiente_pago`).
- Filtros validados con respuesta `422` ante entradas inválidas, estados vacíos, exportación CSV segura (`GET /admin/reportes/exportar` con UTF-8 BOM y neutralización de *CSV formula injection* `=`, `+`, `-`, `@`, `\t`, `\r`, `\n`), permisos administrativos (`isAdmin()`).
- Verificada por Desarrollador y Auditor independiente (`b7cab7d9-9fd1-466e-857a-25c0463a23ad` en `auditing_fase_4b_reservas` sobre `fb82ec7f59571dca82bf738a30e79c2d19489894`) con `PHPUnit 10.5.66` (`OK (161 tests, 1817 assertions)`, exit `0`) y suite E2E en navegador (`RUN_ID: 89a23c7e2ff64515b28c6a771a8f3839`, 18 comprobaciones `[OK]` incluyendo `REP-01`, `0` errores de consola, `0` peticiones fallidas, exit `0`). Ver detalle en `docs/AUDITORIA.md`.

### Fase 6C — Cerrada con Veredicto `APROBADO` (`8178b830bc03197775fa653857e66ec74eb290db`)
- Migración incremental `006_base_pagos.sql` + `006_base_pagos_rollback.sql` (`citas.estado_pago` y tabla `pagos` con `cita_id` `ON DELETE SET NULL`, `cita_referencia_id`, y claves únicas `uq_pagos_referencia`, `uq_pagos_transaccion_externa`, `uq_pagos_idempotency_key`).
- Estados de pago (`pendiente`, `completado`, `fallido`, `reembolsado`, `cancelado`) e interfaz sustituible de proveedor de pagos (`Interfaces\PaymentGatewayInterface` en `interfaces/PaymentGatewayInterface.php`).
- Proveedor simulado (`Classes\MockPaymentGateway` en `classes/MockPaymentGateway.php`) para pruebas automatizadas de pagos aprobados, rechazados por fondos insuficientes, errores de comunicación y webhooks firmados con HMAC-SHA256 (`X-Mock-Signature`).
- Cálculo de importes exclusivamente en servidor (`PagoRepository::obtenerCitaConImporteServidor` desde snapshots históricos `citasservicios`) y generación de referencias únicas (`PAY-YYYYMMDD-<16_HEX>` en `America/Guayaquil`).
- Idempotencia transaccional con bloqueo `FOR UPDATE` en `POST /api/pagos/iniciar` y `POST /api/pagos/webhook`.
- Prohibición estricta de almacenar o procesar datos sensibles de tarjetas (`PAN`, `CVV`, expiración) con rechazo HTTP `422` y sanitización antes de persistir, y prohibición de confirmar pagos únicamente por parámetros de redirección del navegador en `GET /pago/resultado`.
- Integración con `ReporteRepository` y `ReporteService` para reflejar en `/admin/reportes` los ingresos efectivamente cobrados (`pagado`) y reembolsados (`reembolsado`), y registro de decisiones de negocio pendientes en `docs/DECISIONES_PAGOS.md`.
- Verificada por Desarrollador y Auditor independiente (`b7cab7d9-9fd1-466e-857a-25c0463a23ad` en `auditing_fase_4b_reservas` sobre `8178b830bc03197775fa653857e66ec74eb290db`) con `PHPUnit 10.5.66` (`OK (170 tests, 1949 assertions)`, exit `0`) y suite E2E en navegador (`RUN_ID: 8effe3d0db0e46bebc3ee51779558903`, 19 comprobaciones `[OK]` incluyendo `PAG-01`, `0` errores de consola, `0` peticiones fallidas, exit `0`). Ver detalle en `docs/AUDITORIA.md`.

### Cierre Técnico (`feature/cierre-tecnico`) — En Auditoría
- Auditoría integral de rutas, permisos (`isAuth()`, `isAdmin()`, `exigir_csrf()`), registro de `POST /api/pagos/reembolsar` y emisión consistente de cabeceras HTTP de seguridad (`X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`) mediante `emitir_cabeceras_seguridad()`.
- Revisión de dependencias y scripts estandarizados en `composer.json` y `package.json`, y endurecimiento de `.gitignore` (`includes/.env`, `storage/`).
- Revisión de las 6 migraciones incrementales (`001` a `006`), índice `idx_citas_estado_pago`, claves foráneas (`ON DELETE RESTRICT`, `ON DELETE SET NULL`, `ON DELETE CASCADE`) y documentación actualizada en `database/README.md`.
- Flujo de Integración Continua en GitHub Actions (`.github/workflows/ci.yml`) con PHP 8.2, MySQL 8.0, linter `php -l`, migraciones, PHPUnit y prueba CLI de respaldo/restauración.
- Entorno Docker reproducible (`Dockerfile`, `docker-compose.yml`, `Dockerfile.test`) y `includes/.env.example` documentado con todas las variables del sistema.
- Scripts CLI de respaldo y restauración consistentes en InnoDB (`scripts/backup_db.php` y `scripts/restore_db.php` con soporte GZIP `.sql.gz` y hash SHA-256) probados en aislamiento mediante `tests/Integration/BackupRestoreIntegrationTest.php`, junto con la guía operativa completa en `docs/GUIA_MIGRACION_OPERACIONES.md`.
- Verificada por Desarrollador en `comprehensive_project_audit_roadmap` con `PHPUnit 10.5.66` (`--do-not-cache-result`, `OK (172 tests, 2046 assertions)`, exit `0`) y suite E2E en navegador (`RUN_ID: 3d118172c3e6461b9b07a841bf7e5d5b`, 19 comprobaciones `[OK]`, `0` errores de consola, `0` peticiones fallidas, exit `0`).

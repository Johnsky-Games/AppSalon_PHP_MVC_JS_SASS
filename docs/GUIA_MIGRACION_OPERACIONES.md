# Guía de Migración, Despliegue y Operaciones — AppSalon (PHP 8.2 + MySQL 8.0)

Este documento describe los procedimientos operativos oficiales para instalar, configurar, migrar, respaldar, restaurar y monitorear **AppSalon** en entornos de desarrollo, preproducción (*staging*) y producción.

---

## 1. Requisitos del Sistema

| Componente | Versión Requerida | Notas Operativas |
| :--- | :--- | :--- |
| **PHP** | `8.2.x` o superior | Extensiones obligatorias: `mysqli`, `pdo_mysql`, `mbstring`, `json`, `openssl`, `zlib`. Directiva recomendada en `php.ini`: `variables_order = "EGPCS"`. |
| **MySQL** | `8.0.x` | Motor `InnoDB` obligatorio (transacciones ACID y bloqueos `FOR UPDATE`), juego de caracteres `utf8mb4` y colación `utf8mb4_unicode_ci`. |
| **Servidor Web** | Apache `2.4+` o Nginx | En Apache deben estar habilitados `mod_rewrite` y `mod_headers`, apuntando `DocumentRoot` a `/public`. |
| **Composer** | `2.x` | Gestión de dependencias PHP (`vlucas/phpdotenv`, `phpmailer/phpmailer`, `phpunit/phpunit`) y autoloading PSR-4. |
| **Node.js / NPM** | Node `18+` / NPM `9+` | Compilación reproducible de hojas de estilo Sass (`src/scss`), scripts JS (`src/js`) e imágenes (`gulpfile.js` -> `public/build`). |
| **Docker (Opcional / Recomendado)** | Docker Engine `24+` y Docker Compose `v2` | Permite despliegue y ejecución de pruebas en contenedores aislados (`Dockerfile`, `Dockerfile.test`, `docker-compose.yml`). |

---

## 2. Instalación y Despliegue desde Cero

### 2.1. Preparación de Variables de Entorno (`includes/.env`)
1. Copie la plantilla documentada `includes/.env.example` hacia `includes/.env`:
   ```bash
   cp includes/.env.example includes/.env
   ```
2. Configure las variables según el entorno:
   - **Entorno y Zona Horaria:** `APP_ENV=production`, `APP_URL=https://reservas.tudominio.com`, `APP_TIMEZONE=America/Guayaquil`.
   - **Base de Datos MySQL:** `DB_HOST`, `DB_PORT=3306`, `DB_USER`, `DB_PASS`, `DB_NAME=appsalon_mvc`.
   - **Servidor SMTP:** `EMAIL_HOST`, `EMAIL_PORT`, `EMAIL_USER`, `EMAIL_PASS`, `EMAIL_FROM`, `EMAIL_FROM_NAME`.
   - **Recordatorios y Reservas:** `NOTIFICACIONES_RECORDATORIO_HORAS=24`, `RESERVA_EXIGIR_PROFESIONAL=1`.
   - **Pasarela de Pagos:** `PAYMENT_GATEWAY_PROVIDER=mock`, `PAYMENT_WEBHOOK_SECRET=<secreto_hex_32_bytes>`.

> **Seguridad:** El archivo `includes/.env` está excluido en `.gitignore`. Jamás versione secretos ni credenciales en el repositorio.

### 2.2. Instalación de Dependencias y Compilación de Assets
```bash
# 1. Instalar dependencias PHP (en producción usar --no-dev --optimize-autoloader)
composer install --prefer-dist --no-progress --no-interaction

# 2. Instalar dependencias de frontend y compilar hacia public/build/
npm ci
npm run build
```

### 2.3. Despliegue con Docker Compose
Para levantar un stack aislado y reproducible utilizando `docker-compose.yml`:
```bash
docker compose up -d --build
```
El contenedor `db` (`mysql:8.0`) carga automáticamente `database/schema_base.sql` al inicializar el volumen por primera vez y expone un `healthcheck` antes de iniciar el servicio `web` (`php:8.2-apache`).

---

## 3. Gestión de Esquema y Migraciones (`database/migrador.php`)

El proyecto evoluciona su esquema mediante un esquema inicial (`database/schema_base.sql`) seguido de **6 migraciones incrementales versionadas** en `database/migrations/`, registradas en la tabla de control `migraciones`.

### 3.1. Catálogo de Migraciones (`001` a `006`)

| Migración | Archivo | Cambios Principales |
| :---: | :--- | :--- |
| **Base** | `database/schema_base.sql` | Crea las tablas iniciales `usuarios`, `servicios`, `citas` y `citasservicios`. |
| **001** | `001_security_hardening.sql` | Crea la tabla `migraciones`, añade `token_hash`, `token_tipo`, `token_expira` (`idx_token_hash`, `idx_token_expira`) a `usuarios`, y crea `intentos_login` (`uk_tipo_identificador`, `idx_bloqueado_hasta`). |
| **002** | `002_innodb_and_rate_limit_window.sql` | Asegura motor `InnoDB` en todas las tablas y añade `primera_peticion` a `intentos_login`. |
| **003** | `003_profesionales_duracion_y_horarios.sql` | Añade `duracion_minutos` a `servicios` y crea `profesionales`, `profesionales_servicios`, `horarios_profesionales`, `descansos_profesionales` y `bloqueos_profesionales` con índices y claves foráneas `ON DELETE CASCADE`. |
| **004** | `004_reservas_profesional_ocupacion_historico.sql` | Añade `hora_inicio`, `hora_fin`, `duracion_total_minutos`, `profesionalId` (`idx_citas_prof_fecha_intervalo`, `fk_citas_profesional` `ON DELETE RESTRICT`) a `citas` y columnas de snapshot histórico (`nombre_servicio`, `precio_servicio`, `duracion_minutos`) a `citasservicios`. |
| **005** | `005_notificaciones_citas.sql` | Crea `notificaciones_citas` con `uq_notificaciones_clave_idempotencia`, `idx_notificaciones_estado_proximo` y claves foráneas `ON DELETE SET NULL` hacia `citas` y `usuarios` (conservando `cita_referencia_id`). |
| **006** | `006_base_pagos.sql` | Añade `estado_pago` (`idx_citas_estado_pago`) a `citas` y crea `pagos` con `uq_pagos_referencia`, `uq_pagos_transaccion_externa`, `uq_pagos_idempotency_key` y claves foráneas `ON DELETE SET NULL` hacia `citas` y `usuarios`. |

### 3.2. Comandos de Migración y Rollback
```bash
# 1. Inicializar esquema base (sólo en bases de datos nuevas/vacías)
mysql -h 127.0.0.1 -P 3306 -u root -p appsalon_mvc < database/schema_base.sql

# 2. Consultar el estado actual de las migraciones (APLICADA vs PENDIENTE)
php database/migrador.php status
# o mediante Composer:
composer migrate:status

# 3. Aplicar todas las migraciones pendientes en orden (001 -> 006)
php database/migrador.php up
# o mediante Composer:
composer migrate:up

# 4. Revertir migraciones registradas hacia el esquema base (orden inverso 006 -> 001)
php database/migrador.php down
```
> **Recomendación de Producción:** Antes de ejecutar `php database/migrador.php up` o `down` en producción, genere siempre un respaldo completo con `php scripts/backup_db.php --gzip`.

---

## 4. Configuración de Tareas Programadas (Notificaciones y Recordatorios)

El script CLI `scripts/procesar_notificaciones.php` evalúa en zona horaria `America/Guayaquil` las citas próximas dentro de la ventana `NOTIFICACIONES_RECORDATORIO_HORAS` (por defecto `24` horas), encola recordatorios de manera idempotente (`cita:{id}:recordatorio`) y procesa los envíos pendientes o reintentos con *backoff* exponencial.

### 4.1. Ejecución Manual
```bash
php scripts/procesar_notificaciones.php --limite=100
```

### 4.2. Configuración con Cron en Linux / Docker Host
Ejecute `crontab -e` y agregue la siguiente tarea cada 10 minutos:
```cron
*/10 * * * * /usr/bin/php /var/www/html/scripts/procesar_notificaciones.php --limite=100 >> /var/log/appsalon_notificaciones.log 2>&1
```
Si la aplicación corre dentro de un contenedor Docker (`appsalon-web`):
```cron
*/10 * * * * docker exec appsalon-web php /var/www/html/scripts/procesar_notificaciones.php --limite=100 >> /var/log/appsalon_notificaciones.log 2>&1
```

### 4.3. Configuración en el Programador de Tareas de Windows (*Task Scheduler*)
Para registrar la tarea cada 10 minutos desde PowerShell (Administrador):
```powershell
$Action = New-ScheduledTaskAction -Execute "docker" -Argument "exec appsalon-web php /var/www/html/scripts/procesar_notificaciones.php --limite=100"
$Trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 10)
Register-ScheduledTask -TaskName "AppSalon_ProcesarNotificaciones" -Action $Action -Trigger $Trigger -Description "Despacho idempotente de recordatorios y reintentos SMTP de AppSalon"
```

---

## 5. Procedimiento de Respaldo y Restauración (`backup_db.php` y `restore_db.php`)

AppSalon incluye utilidades CLI autónomas en PHP (`scripts/backup_db.php` y `scripts/restore_db.php`) que operan directamente sobre la extensión `mysqli` usando snapshots consistentes de `InnoDB` (`REPEATABLE READ` + `START TRANSACTION WITH CONSISTENT SNAPSHOT`), sin depender de binarios externos del sistema operativo.

### 5.1. Generación de Respaldo (`scripts/backup_db.php`)
```bash
# Respaldo en ruta por defecto (storage/backups/backup_<db>_<YYYYmmdd_His>.sql)
php scripts/backup_db.php

# Respaldo comprimido con GZIP (.sql.gz) en una ruta específica
php scripts/backup_db.php --db=appsalon_mvc --output=storage/backups/appsalon_prod.sql.gz --gzip
```
El comando imprime el resumen operativo incluyendo el número de tablas, total de filas respaldadas, tamaño en bytes y el hash `sha256` del volcado SQL:
```text
[OK] Respaldo completado: bd=appsalon_mvc | tablas=13 | filas=142 | archivo=storage/backups/appsalon_prod.sql.gz | bytes=4821 | sha256=...
```

### 5.2. Restauración de un Respaldo (`scripts/restore_db.php`)
```bash
# Restaurar desde archivo .sql o .sql.gz sobre la base configurada
php scripts/restore_db.php --input=storage/backups/appsalon_prod.sql.gz

# Restaurar en una base de datos específica (creándola si no existe con --create-db)
php scripts/restore_db.php --db=appsalon_recuperacion --input=storage/backups/appsalon_prod.sql.gz --create-db
```

### 5.3. Política Recomendada de Respaldos en Producción
- **Frecuencia:** Respaldo automático diario comprimido (`--gzip`) a las `02:00 America/Guayaquil` y respaldo previo obligatorio antes de cada despliegue o migración.
- **Retención:** Conservar 7 respaldos diarios, 4 semanales y 6 mensuales en almacenamiento externo cifrado fuera del servidor de base de datos.
- **Verificación periódica:** Ejecutar mensualmente una restauración de prueba en una base aislada (`--db=appsalon_verify_restore_test --create-db`) para comprobar la integridad del archivo.

---

## 6. Matriz de Seguridad, Rutas, Permisos y Monitoreo

### 6.1. Cabeceras HTTP de Seguridad
Todas las respuestas emitidas por el enrutador (`MVC\Router`) y `public/index.php` incluyen mediante `emitir_cabeceras_seguridad()`:
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: SAMEORIGIN`
- `Referrer-Policy: strict-origin-when-cross-origin`

### 6.2. Control de Acceso y Protección de Rutas (`public/index.php`)

| Grupo de Rutas | Endpoints | Control de Autenticación / Rol | Protección Mutación |
| :--- | :--- | :--- | :--- |
| **Autenticación Pública** | `GET/POST /`, `/olvide`, `/recuperar`, `/crear-cuenta`, `/confirmar-cuenta`, `/mensaje`, `/reenviar-confirmacion` | Público con Rate Limiting atómico por IP y email (`intentos_login`) | `exigir_csrf()` en todos los `POST` |
| **Cierre de Sesión** | `POST /logout` (`GET /logout` redirige sin cerrar sesión) | Sesión activa | `exigir_csrf()` obligatorio |
| **Reserva de Cliente** | `GET /cita`, `GET /api/profesionales`, `GET /api/disponibilidad`, `GET /api/mis-citas`, `POST /api/citas`, `POST /api/eliminar` | `isAuth()` / verificación de `$_SESSION['login']` e `id` de sesión | `validar_csrf()` / `exigir_csrf()` en `POST` |
| **Pagos (Fase 6C)** | `POST /api/pagos/iniciar`, `GET /pago/resultado`, `POST /api/pagos/webhook`, `POST /api/pagos/reembolsar` | `isAuth()` en iniciar/resultado; firma HMAC-SHA256 en webhook; `isAdmin()` en reembolsar | CSRF en iniciar y reembolsar; HMAC en webhook |
| **Administración** | `GET /admin`, `/servicios/*`, `/profesionales/*`, `/admin/reportes`, `/admin/reportes/exportar` | `isAdmin()` (`$_SESSION['admin'] === '1'`) | `exigir_csrf()` en todos los `POST` |

### 6.3. Verificación de Salud y Suites Automatizadas
1. **Verificación de estado de migraciones:**
   ```bash
   php database/migrador.php status
   ```
2. **Ejecución de la suite completa de PHPUnit (Unitarias + Integración + Concurrencia + Backup/Restore):**
   ```bash
   php -d variables_order=EGPCS vendor/bin/phpunit --colors=never
   ```
3. **Ejecución de la suite E2E de Navegador Real (Puppeteer + Docker Aislado + SMTP Mock):**
   ```powershell
   powershell -ExecutionPolicy Bypass -File tests/verificar_navegador.ps1
   ```

# Contexto Técnico y Operativo del Proyecto AppSalon

## 1. Identificación del Repositorio y Ubicaciones de Trabajo

- **Repositorio oficial:** `https://github.com/Johnsky-Games/AppSalon_PHP_MVC_JS_SASS`
- **Copia local que sirve la aplicación (`http://localhost:3000`):**
  - Ruta: `C:\Users\jonat\OneDrive\Documentos\Projects\Salón-App\AppSalon_PHP_MVC_JS_SASS`
  - Rama y commit actual: `main` (`bc1529bde644e1187be98332e3db912b80773cab`, Entrega 1 fusionada).
  - Estado de migraciones en `appsalon_mvc`: `001_security_hardening` y `002_innodb_and_rate_limit_window` aplicadas (las migraciones `003` y `004` existen en las ramas de desarrollo `feature/fase-3a-*` a `feature/fase-4b-*`, aún no fusionadas en `main`).
  - **Regla estricta:** Esta copia local y sus contenedores (`appsalon-web`, `appsalon-db`, red `appsalon-net`, base `appsalon_mvc` y archivo `includes/.env`) no deben modificarse, reiniciarse destructivamente ni usarse para pruebas.
- **Worktree de desarrollo:**
  - Ruta: `C:\Users\jonat\.gemini\antigravity\worktrees\AppSalon_PHP_MVC_JS_SASS\comprehensive_project_audit_roadmap`
  - Uso exclusivo: implementación del desarrollador, pruebas en contenedores aislados, actualización documental, commits y publicación de ramas `feature/*`.
- **Worktree del auditor:**
  - Ruta: `C:\Users\jonat\.gemini\antigravity\worktrees\AppSalon_PHP_MVC_JS_SASS\auditing_fase_4b_reservas`
  - Uso exclusivo: checkout en modo lectura del SHA auditado y ejecución independiente de pruebas sobre contenedores y bases aisladas (`*_test`).
- **Registro de Stash existente (`stash@{0}`):**
  - Identificador: `stash@{0}: On auditing_fase_4b_reservas: temp stash for checkout`
  - Ubicación: almacén Git compartido del repositorio (creado desde el worktree `auditing_fase_4b_reservas` antes del checkout del SHA `83e2a24b235a72c2865453ec13ea055d96419700`).
  - Contenido verificado (`git stash show --stat stash@{0}`): modifica únicamente `PROGRESS.md` (`1 file changed, 10 insertions(+), 97 deletions(-)`), sin credenciales, sin `.env` ni cambios en código de aplicación. Se conserva intacto sin eliminarlo ni aplicarlo automáticamente.

---

## 2. Arquitectura y Stack Tecnológico

- **Lenguaje y Runtime:** PHP 8.2 (`mysqli`, `PDO`, `pdo_mysql`) bajo arquitectura MVC con separación explícita por capas:
  - `Controllers\*`: transporte HTTP, sesiones (`iniciar_sesion_segura`), autenticación (`isAuth`, `isAdmin`), validación CSRF (`exigir_csrf`, `validar_csrf`), códigos de estado HTTP, cabeceras y renderizado de vistas/JSON.
  - `Services\*`: reglas de negocio y validaciones de dominio (`AuthService`, `ServicioService`, `CitaService`, `ProfesionalService`, `DisponibilidadService`). No acceden a superglobales (`$_POST`, `$_GET`, `$_SESSION`).
  - `Repositories\*`: persistencia mediante sentencias preparadas (`UsuarioRepository`, `ServicioRepository`, `CitaRepository`, `ProfesionalRepository`), transacciones ACID en InnoDB y encapsulación de fallos SQL en `PersistenceException`.
  - `Model\*`: entidades de dominio desacopladas de `ActiveRecord` (`Usuario`, `Servicio`, `Cita`, `CitaServicio`, `AdminCita`, `Profesional`, `HorarioProfesional`, `DescansoProfesional`, `BloqueoProfesional`). Se conserva `Model\ActiveRecord` por compatibilidad.
- **Base de Datos:** MySQL 8.0 con motor transaccional `InnoDB` y codificación `utf8mb4`.
  - Tabla real de relación entre citas y servicios: `citasservicios`.
  - Sistema de migraciones incrementales versionadas: `database/migrador.php` (`up`, `down`, `status`, `fresh`) y esquema inicial `database/schema_base.sql`.
- **Zona Horaria de Reglas de Agenda y Reservas:** `America/Guayaquil` (`DateTimeImmutable` con `DateTimeZone('America/Guayaquil')`), manteniendo los `DATETIME` UTC de expiración de tokens en MySQL.
- **Frontend:** JavaScript ES6+ (`src/js/app.js`, `src/js/buscador.js`), SASS (`src/scss/`) y Gulp 4.

---

## 3. Recursos Docker para Ejecución de Pruebas Aisladas

La ausencia de binarios nativos de PHP o Composer en el host Windows no impide la ejecución de pruebas; todas las suites se ejecutan mediante contenedores Docker aislados:

- **`appsalon-php-test:latest`:** Imagen PHP 8.2.34 CLI con extensiones `mysqli`, `PDO` y `pdo_mysql` habilitadas.
- **`composer:2`:** Imagen oficial para instalación de dependencias desde `composer.lock` (`composer install --no-interaction --prefer-dist`, incluyendo dependencias `require-dev` como PHPUnit `^10.5`).
- **`mysql:8.0`:** Imagen oficial para levantar instancias MySQL efímeras en redes Docker dedicadas por ejecución, inicializadas con `database/schema_base.sql` y `php database/migrador.php up` sobre bases cuyo nombre termina obligatoriamente en `_test`.
- **`node:18` + Headless Chrome (`tests/verificar_navegador.ps1`):** Ejecución de pruebas E2E de navegador (`tests/browser_e2e_test.js` con `puppeteer-core`) contra contenedores web y MySQL efímeros con respaldo y verificación SHA-256 de `includes/.env`.

---

## 4. Contratos e Invariantes de Seguridad

1. **Autenticación y Sesiones:** Regeneración de ID de sesión en login (`session_regenerate_id(true)`), cookies `HttpOnly` y `SameSite=Lax`, revocación inmediata de privilegios de administrador si el rol cambia, y derivación exclusiva de `usuarioId` desde `$_SESSION['id']`.
2. **Protección CSRF:** Obligatoria en todas las peticiones `POST` de formularios y endpoints JSON (`POST /api/citas`, `POST /api/eliminar`, `/servicios/*`, `/profesionales/*`, `/login`, `/logout`, etc.).
3. **Tokens Criptográficos de Un Solo Uso:** Generados con `bin2hex(random_bytes(32))`, almacenados como hash SHA-256 (`token_hash`) con tipo (`confirmacion` / `recuperacion`) y expiración (`token_expira`), y consumidos atómicamente (`affected_rows === 1`).
4. **Rate Limiting Atómico:** Control de intentos por cuenta (`email_login`, `email_olvide`, `email_reenviar`) y presupuesto compartido por IP (`ip_login`, `ip_olvide`, `ip_reenviar`), donde un login exitoso limpia únicamente el contador de la cuenta sin reiniciar el presupuesto compartido de la IP.
5. **Inmutabilidad Histórica de Citas:** Las citas anteriores sin profesional conservan `profesionalId = NULL` sin inventar asignaciones; las nuevas reservas con profesional capturan `nombre_servicio`, `precio_servicio` y `duracion_minutos` en `citasservicios` y `hora_inicio`, `hora_fin` y `duracion_total_minutos` en `citas`.

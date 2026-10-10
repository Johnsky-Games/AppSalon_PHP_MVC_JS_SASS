# Registro de Progreso del Proyecto AppSalon

Este archivo mantiene la trazabilidad estricta del avance del proyecto de acuerdo con la metodología de entregas auditables por **ChatGPT** (revisión estática de código) y ejecución/verificación por **Antigravity** (desarrollo y pruebas dinámicas automatizadas), sujeto a la aprobación final del **Propietario**.

> **Roles y Criterios:**
> - **Desarrollo y Pruebas Automatizadas (Antigravity):** Implementación de código y ejecución de la suite completa de pruebas unitarias e integrales (99 pruebas, 824 aserciones en PHPUnit), escenarios funcionales HTTP y suite E2E en navegador real Headless Chrome (`tests/verificar_navegador.ps1` y `tests/browser_e2e_test.js`).
> - **Revisión Estática Externa (ChatGPT):** Auditoría independiente de código de aplicación, arquitectura por capas, contratos transaccionales y revisión estática de scripts Bash.
> - **Aprobación Final y Despliegue (Propietario):** Decisión formal sobre fusiones hacia `main` y despliegues en producción.

---

## Fase 3A: Profesionales, Duración de Servicios y Configuración de Horarios

- **Rama:** `feature/fase-3a-profesionales-horarios`
- **Commit Base (Fase 2C):** `fe16c67211d5583e859fd6ec4eb8ba55b7c03983`
- **Estado General de Fase 3A:** **Completada (Pendiente de Revisión Externa)**

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
    - Verifica coherencia entre horarios y descansos: todo descanso debe estar contenido dentro de una franja laboral activa del mismo día sin consumir la totalidad del turno ni solaparse con otro descanso; asimismo, impide guardar o eliminar horarios si dejarían descansos existentes fuera de turno.
    - Valida bloqueos por fecha completa o por intervalo horario (`fecha_inicio <= fecha_fin`, `hora_inicio < hora_fin`, sin solapamiento con otros bloqueos del mismo profesional).
    - **Zona horaria explícita `America/Guayaquil`:** Todas las reglas de fechas de agenda en `ProfesionalService` (`TIMEZONE = 'America/Guayaquil'`) y `CitaService` (`TIMEZONE = 'America/Guayaquil'`) operan explícitamente sobre `new \DateTimeZone('America/Guayaquil')` mediante `DateTimeImmutable`, garantizando coherencia entre el cálculo del día actual, el día de la semana y las validaciones de reservas y bloqueos sin desalinear los `DATETIME` UTC de tokens en MySQL.
- **Controladores y Vistas:**
  - `Controllers\ProfesionalController`: protege todas las acciones con `iniciar_sesion_segura()`, `isAdmin()` y `exigir_csrf()` en peticiones `POST`; distingue errores de validación, no encontrado (`302`) y fallos de persistencia (`500`).
  - **Frontera de compatibilidad:** El flujo de reserva del cliente (`/cita`, `/api/servicios`, `/api/citas`) y la tabla `citas` mantienen su contrato actual sin añadir columnas obligatorias ni atribuir profesionales retrospectivamente.

### 2. Validación Ejecutada en Fase 3A (Antigravity)

#### A. Suite Completa PHPUnit (Ejecutada en Docker PHP 8.2 + MySQL 8.0 Aislado)
- **Resultado:** `OK (99 tests, 824 assertions)` — Código de salida `0`.
- **Cobertura añadida en Fase 3A:**
  - `Tests\Unit\ServicioServiceTest`: validación de `duracion_minutos` como entero positivo (`>= 1`), rechazo de `0`, negativos, decimales, vacíos o no escalares, y verificación del supuesto configurable `DEFAULT_DURACION_MINUTOS = 30`.
  - `Tests\Unit\ProfesionalAgendaServiceTest`: validación de profesionales y servicios asociados, intervalos horarios, rechazo de horarios invertidos y solapados, coherencia entre horarios y descansos, bloqueos por día completo e intervalo, y coherencia de zona horaria `America/Guayaquil` entre `ProfesionalService` y `CitaService`.
  - `Tests\Integration\MigrationTest`: verificación de migración `003_profesionales_duracion_y_horarios` en instalaciones desde cero y con datos previos (`duracion_minutos = 30` y tablas `profesionales`, `profesionales_servicios`, `horarios_profesionales`, `descansos_profesionales`, `bloqueos_profesionales`).
  - `Tests\Integration\ServicioModuloIntegrationTest`: ciclo CRUD de servicios con `duracion_minutos` en MySQL real y vistas HTTP.
  - `Tests\Integration\ProfesionalAgendaIntegrationTest`: control de permisos `isAdmin()` y CSRF en todas las rutas de `/profesionales*`, ciclo completo con MySQL real (creación, edición de servicios asociados, horarios semanales, descansos, bloqueos, desactivación conservando agenda histórica), rollback transaccional con respuesta HTTP 500 ante fallos SQL y compatibilidad con el flujo actual de reservas.

#### B. Verificación E2E en Navegador con JavaScript Habilitado (`tests/verificar_navegador.ps1` + `tests/browser_e2e_test.js`)
- **Resultado:** 13 comprobaciones E2E superadas (`ADMIN-01` a `ADMIN-05`, `PROF-01`, `PRE-01`, `REC-01` a `REC-05`, `AUTH-01`) + verificación de persistencia en MySQL (`duracion_minutos=60`, profesional `Sofía Andrade` con servicios, horario, descanso y bloqueo, y cita de cliente) — Código de salida `0`.

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
| **Fase 3A** | Profesionales, servicios con duración, horarios, descansos y bloqueos | `Completada (En Revisión)` |
| **Fase 4** | Disponibilidad real y prevención de reservas simultáneas | `Pendiente` |
| **Fase 5** | Interfaz accesible de reservas y panel administrativo | `Pendiente` |
| **Fase 6** | Pagos (Stripe / Mercado Pago), notificaciones multicanal y reportes | `Pendiente` |


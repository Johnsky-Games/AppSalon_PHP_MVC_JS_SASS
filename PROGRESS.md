# Registro de Progreso del Proyecto AppSalon

Este archivo mantiene la trazabilidad estricta del avance del proyecto de acuerdo con la metodología de entregas auditables por **ChatGPT** (revisión estática de código) y ejecución/verificación por **Antigravity** (desarrollo y pruebas dinámicas automatizadas), sujeto a la aprobación final del **Propietario**.

> **Roles y Criterios:**
> - **Desarrollo y Pruebas Automatizadas (Antigravity):** Implementación de código y ejecución de la suite completa de pruebas unitarias e integrales (80 pruebas, 586 aserciones en PHPUnit), escenarios funcionales HTTP y suite E2E en navegador real Headless Chrome (`tests/verificar_navegador.ps1` y `tests/browser_e2e_test.js`).
> - **Revisión Estática Externa (ChatGPT):** Auditoría independiente de código de aplicación, arquitectura por capas, contratos transaccionales y revisión estática de scripts Bash.
> - **Aprobación Final y Despliegue (Propietario):** Decisión formal sobre fusiones hacia `main` y despliegues en producción.

---

## Fase 2B: Separación de Responsabilidades en el Flujo de Citas y Reservas

- **Rama:** `feature/fase-2b-citas-repositorios`
- **Commit Base (Fase 2A):** `4a450301304be25a4829d89303ad52edfd40028f`
- **Estado General de Fase 2B:** **Completada y Lista para Auditoría**

### 1. Arquitectura Implementada (`Controller` $\rightarrow$ `Service` $\rightarrow$ `Repository`)

| Capa / Clase | Archivo | Responsabilidad Exclusiva |
| :--- | :--- | :--- |
| **Controladores HTTP** (`Controllers\APIController`, `Controllers\AdminController`, `Controllers\CitaController`) | [controllers/APIController.php](file:///controllers/APIController.php)<br>[controllers/AdminController.php](file:///controllers/AdminController.php)<br>[controllers/CitaController.php](file:///controllers/CitaController.php) | Gestión exclusiva de transporte HTTP, sesión segura (`iniciar_sesion_segura`), autenticación (`isAuth`, `isAdmin`), protección CSRF (`validar_csrf`, `exigir_csrf`), serialización JSON, renderizado de vistas y redirecciones. Se eliminó la herencia indebida `extends ActiveRecord` en `CitaController`. Inyección sencilla mediante `setCitaService()`. |
| **Servicio de Dominio** (`Services\CitaService`) | [services/CitaService.php](file:///services/CitaService.php) | Reglas de negocio para reservar (`reservar`), eliminar (`eliminar`) y consultar citas administrativas por fecha (`consultarCitasAdmin`, `resolverFechaConsultaAdmin`). Recibe la identidad (`$usuarioIdAutenticado`) y permisos (`$esAdmin`) ya verificados por el controlador; nunca consulta `$_SESSION` directamente ni confía en `usuarioId` o `id` enviados por el cliente. |
| **Repositorio de Persistencia** (`Repositories\CitaRepository`) | [repositories/CitaRepository.php](file:///repositories/CitaRepository.php) | Consultas y escrituras sobre las tablas `citas` y `citasservicios` (incluyendo la proyección administrativa con `LEFT OUTER JOIN`) mediante sentencias preparadas (`findById`, `createCita`, `createCitaServicio`, `crearReservaAtomica`, `eliminarCitaAtomica`, `findAdminCitasByFecha`). |
| **Entidades y Proyección de Dominio** (`Model\Cita`, `Model\CitaServicio`, `Model\AdminCita`) | [models/Cita.php](file:///models/Cita.php)<br>[models/CitaServicio.php](file:///models/CitaServicio.php)<br>[models/AdminCita.php](file:///models/AdminCita.php) | Entidades y proyecciones desacopladas de `ActiveRecord`. `Model\Cita::sincronizarEditable()` acepta únicamente `fecha` y `hora` escalares, bloqueando cualquier modificación de `id` o `usuarioId`. |

### 2. Reglas de Negocio, Transacciones y Distinción de Estados (Fase 2B)

- **Identidad y permisos verificados:** `CitaService` nunca accede a `$_SESSION`. En `reservar($usuarioIdAutenticado, array $datos)`, asigna la cita estrictamente al `$usuarioIdAutenticado` recibido del controlador e ignora cualquier `usuarioId` o `id` presente en `$datos` (`$_POST`). En `eliminar($idCitaRaw, $usuarioIdAutenticado, bool $esAdmin)`, comprueba que el solicitante sea el propietario de la cita (`(int)$cita->usuarioId === $usuarioId`) o un administrador (`$esAdmin === true`).
- **Reglas de validación de reserva preservadas:**
  - Fecha obligatoria en formato estricto `AAAA-MM-DD`, válida en el calendario (`checkdate`), estrictamente futura (`$fecha > date('Y-m-d')`) y en día laborable de lunes a viernes (rechaza sábados `6` y domingos `0`).
  - Hora obligatoria en formato estricto `HH:MM` (sin segundos) dentro del horario comercial `10:00` a `18:00` inclusive (`18:00` permitido; $\ge 18:01$ rechazado).
  - Servicios seleccionados como lista de enteros positivos separados por coma, desduplicados (`array_unique`) y verificados uno a uno en base de datos mediante `ServicioRepository::findById()` sobre la misma conexión.
- **Transacción atómica en una única conexión (`mysqli`):**
  - `CitaRepository::crearReservaAtomica()` y `CitaRepository::eliminarCitaAtomica()` operan sobre una única instancia `mysqli`, verificando explícitamente `begin_transaction()`, cada sentencia preparada (`prepare`, `bind_param`, `execute`, `affected_rows`, `insert_id`) y `commit()`.
  - Ante cualquier fallo intermedio o de confirmación (`commit() === false`), se ejecuta `rollback()` garantizando que no queden registros parciales en `citas` o `citasservicios` ni se devuelva éxito falso.
- **Distinción explícita de estados:**
  - `STATUS_UNAUTHORIZED` (`401`): usuario no autenticado o identidad inválida.
  - `STATUS_FORBIDDEN` (`403`): intento de eliminar una cita ajena sin rol de administrador (IDOR bloqueado).
  - `STATUS_INVALID` (`422` / redirección en formulario): formato o regla de negocio inválida en fecha, hora, identificadores o lista de servicios.
  - `STATUS_NOT_FOUND` (`404` / `422` en servicios de reserva): cita inexistente al eliminar o servicio inexistente al reservar.
  - `STATUS_ERROR` (`500`): fallo SQL o de transacción. En `/admin` responde HTTP `500` y muestra alerta de error en `views/admin/index.php` sin confundirse con el mensaje `"No se registra citas para la fecha seleccionada"`. En `APIController::eliminar()` devuelve HTTP `500` tanto para peticiones JSON (`{"resultado":false,"error":"..."}`) como para peticiones HTML del formulario administrativo (`Error 500: No fue posible eliminar la cita debido a un error de base de datos.`, escapado con `s()` y deteniendo la ejecución antes de cualquier redirección `302`).
- **Pendiente separado documentado (Conservación histórica de nombres y precios en `citasservicios`):**
  - Esta entrega corresponde exclusivamente a una extracción estructural a repositorios y servicios sin modificaciones de esquema. Actualmente la tabla `citasservicios` almacena únicamente `(id, citaId, servicioId)` y la consulta administrativa obtiene `servicios.nombre` y `servicios.precio` mediante `LEFT OUTER JOIN` sobre el catálogo vivo. La incorporación de columnas de instantánea histórica (*snapshot* de `nombre_servicio` y `precio_unitario` al momento de la reserva en `citasservicios`) queda documentada como pendiente separado para las siguientes fases de evolución del dominio, junto con profesionales, duraciones, disponibilidad real y pagos.

### 3. Validación Ejecutada en Fase 2B (Antigravity)

#### A. Suite Completa PHPUnit (Ejecutada en Docker PHP 8.2 + MySQL 8.0 Aislado)
- **Resultado:** `OK (80 tests, 586 assertions)` — Código de salida `0`.
- **Nuevas suites añadidas para Fase 2B:**
  - `Tests\Unit\CitaServiceTest` ([tests/Unit/CitaServiceTest.php](file:///tests/Unit/CitaServiceTest.php)): 5 pruebas unitarias verificando desacoplamiento de `Model\Cita`, `Model\CitaServicio`, `Model\AdminCita` y `Controllers\CitaController` respecto de `ActiveRecord`, protección de `id` y `usuarioId` en `Cita::sincronizarEditable()`, validación de identificadores, resolución de fechas administrativas con fallback seguro y validación de reglas de fecha, día laborable y horario sin consultar `$_SESSION`.
  - `Tests\Integration\CitaModuloIntegrationTest` ([tests/Integration/CitaModuloIntegrationTest.php](file:///tests/Integration/CitaModuloIntegrationTest.php)): 10 pruebas de integración contra MySQL 8.0 real verificando:
    1. Reserva atómica ignorando `usuarioId` e `id` manipulados en el payload y desduplicando servicios repetidos.
    2. Rechazo de servicios inexistentes sin insertar citas parciales.
    3. Rollback atómico en una única conexión ante fallo SQL real (trigger MySQL `SIGNAL SQLSTATE '45000'` en el segundo registro de `citasservicios`), revirtiendo tanto la fila de `citas` como el primer `citasservicio`.
    4. Respuesta HTTP `500` con rollback completo en `APIController::guardar()` ante fallo SQL intermedio.
    5. Comprobación explícita de fallos en `begin_transaction()` y `commit()`, ejecutando `rollback()` cuando `commit()` devuelve `false`.
    6. Verificación de permisos de propietario y administrador en `CitaService::eliminar()`, bloqueo de otros clientes (`STATUS_FORBIDDEN`) y distinción entre `STATUS_INVALID` y `STATUS_NOT_FOUND`.
    7. Rollback transaccional en eliminación cuando falla el borrado de la cita principal en `citas`, restaurando los registros de `citasservicios` previamente eliminados en la transacción.
    8. Consulta administrativa por fecha en `AdminController::index` distinguiendo lista vacía (HTTP `200`) de fallo SQL (HTTP `500` con alerta de error).
    9. Exigencia de rol administrador en `AdminController::index`.
    10. Respuesta HTTP `500` sin redirección `302` en `APIController::eliminar()` ante fallo de persistencia tanto en modalidad HTML (mensaje visible y escapado sin exponer detalles SQL internos) como en modalidad JSON, conservando la cita y sus servicios asociados tras el rollback y permitiendo su eliminación posterior cuando se restablece la persistencia.

#### B. Verificación E2E en Navegador con JavaScript Habilitado (`tests/verificar_navegador.ps1` + `tests/browser_e2e_test.js`)
- **Resultado:** 11 comprobaciones E2E superadas (`ADMIN-01` a `ADMIN-05`, `PRE-01`, `REC-01` a `REC-05`) + verificación de persistencia en MySQL — Código de salida `0`.
- **Nuevo recorrido añadido en Fase 2B (`ADMIN-05`):** Autenticación como administrador en `/admin`, filtrado por fecha mediante `#fecha` (`buscador.js`), verificación en DOM de la cita reservada por el cliente con sus servicios y total calculado (`$ 195`), y eliminación de una cita temporal desde `/admin` vía `POST /api/eliminar` con CSRF comprobando su desaparición y la preservación de la cita principal en pantalla y en MySQL.

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
| **Fase 2B** | Separación de responsabilidades en el flujo de citas y reservas (`CitaRepository`, `CitaService`) | `Completada (En Auditoría)` |
| **Fase 3** | Profesionales, servicios con duración, horarios, descansos y bloqueos | `Pendiente` |
| **Fase 4** | Disponibilidad real y prevención de reservas simultáneas | `Pendiente` |
| **Fase 5** | Interfaz accesible de reservas y panel administrativo | `Pendiente` |
| **Fase 6** | Pagos (Stripe / Mercado Pago), notificaciones multicanal y reportes | `Pendiente` |

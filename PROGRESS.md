# Registro de Progreso del Proyecto AppSalon

Este archivo mantiene la trazabilidad estricta del avance del proyecto de acuerdo con la metodología de entregas auditables por **ChatGPT** (revisión estática de código) y ejecución/verificación por **Antigravity** (desarrollo y pruebas dinámicas automatizadas), sujeto a la aprobación final del **Propietario**.

> **Roles y Criterios:**
> - **Desarrollo y Pruebas Automatizadas (Antigravity):** Implementación de código y ejecución de la suite completa de pruebas unitarias e integrales (65 pruebas, 469 aserciones en PHPUnit), escenarios funcionales HTTP y suite E2E en navegador real Headless Chrome (`tests/verificar_navegador.ps1` y `tests/browser_e2e_test.js`).
> - **Revisión Estática Externa (ChatGPT):** Auditoría independiente de código de aplicación, arquitectura por capas, contratos transaccionales y revisión estática de scripts Bash.
> - **Aprobación Final y Despliegue (Propietario):** Decisión formal sobre fusiones hacia `main` y despliegues en producción.

---

## Fase 2A: Separación de Responsabilidades en el Módulo de Catálogo de Servicios

- **Rama:** `feature/fase-2-servicios-repositorios`
- **Commit Base (`main`):** `bc1529bde644e1187be98332e3db912b80773cab`
- **Estado General de Fase 2A:** **Completada y Lista para Auditoría**

### 1. Arquitectura Implementada (`Controller` $\rightarrow$ `Service` $\rightarrow$ `Repository`)

| Capa / Clase | Archivo | Responsabilidad Exclusiva |
| :--- | :--- | :--- |
| **Controlador HTTP** (`Controllers\ServicioController` y `Controllers\APIController::index`) | [controllers/ServicioController.php](file:///controllers/ServicioController.php)<br>[controllers/APIController.php](file:///controllers/APIController.php) | Gestión de transporte HTTP, sesión segura (`iniciar_sesion_segura`), autorización de rol (`isAdmin`), protección CSRF (`exigir_csrf`), códigos de estado HTTP (`200`, `302`, `422`, `500`), renderizado de vistas y redirecciones con `detener_ejecucion()`. Inyección sencilla mediante `setServicioService()`. |
| **Servicio de Dominio** (`Services\ServicioService`) | [services/ServicioService.php](file:///services/ServicioService.php) | Validación estricta de tipos escalares, formato y rango conforme al esquema (`VARCHAR(60)`, `DECIMAL(6,2)`), protección contra asignación masiva de `id`, normalización de precios a 2 decimales y distinción explícita entre estados (`STATUS_OK`, `STATUS_INVALID`, `STATUS_NOT_FOUND`, `STATUS_ERROR`). |
| **Repositorio de Persistencia** (`Repositories\ServicioRepository` y `Repositories\PersistenceException`) | [repositories/ServicioRepository.php](file:///repositories/ServicioRepository.php)<br>[repositories/PersistenceException.php](file:///repositories/PersistenceException.php) | Consultas y persistencia SQL sobre la tabla `servicios` mediante sentencias preparadas (`findAll`, `findById`, `create`, `update`, `delete`), detección de actualizaciones sin cambios (`affected_rows === 0` con registro existente) y lanzamiento explícito de `PersistenceException` ante fallos SQL o de conexión. |
| **Entidad de Dominio** (`Model\Servicio`) | [models/Servicio.php](file:///models/Servicio.php) | Entidad desacoplada de `ActiveRecord`. Define `sincronizarEditable()` aceptando únicamente `nombre` y `precio` escalares e ignorando siempre `id`. Corrige el bug histórico `is_numeric(!$this->precio)` delegando en `ServicioService::validarDatos()`. |

### 2. Reglas de Validación y Políticas Documentadas (Fase 2A)

- **Campos editables:** Únicamente `nombre` y `precio`. En creación, `id` es forzado a `null` antes de insertar; en actualización, `id` procede exclusivamente del identificador validado de la operación (`$_GET['id']`), ignorando cualquier `$_POST['id']`.
- **Validación de `nombre`:** Escalar string obligatorio tras `trim()`, longitud máxima de 60 caracteres UTF-8 (`VARCHAR(60)`). Rechaza arreglos o tipos no escalares.
- **Validación de `precio` y Política sobre Precio Cero:**
  - **Política sobre precio cero:** En el catálogo comercial de AppSalon todo servicio reservable debe tener un costo estrictamente positivo (`precio > 0`, mínimo `0.01`). Los valores cero (`0`, `0.0`, `0.00`) y negativos son rechazados en la validación.
  - **Formato y capacidad:** Acepta enteros o decimales positivos con hasta 2 decimales y valor máximo `9999.99` conforme a `DECIMAL(6,2)`. Rechaza notación científica (`1e2`), más de 2 decimales (`50.123`), textos no numéricos y valores $\ge 10000$.
- **Distinción de estados y fallos SQL:**
  - Catálogo vacío devuelve `STATUS_OK` con `[]`; fallo SQL lanza `PersistenceException` y devuelve `STATUS_ERROR` (HTTP `500` con alerta de error en vista o JSON de error en `/api/servicios`, sin confundirse con lista vacía ni redirigir como éxito).
  - Actualización sin cambios (`affected_rows === 0` cuando los valores enviados son idénticos a los almacenados) verifica existencia del registro y retorna `STATUS_OK`, distinguiéndose de un registro inexistente (`STATUS_NOT_FOUND`).
- **Conservación de historial:** `ServicioRepository::delete()` elimina únicamente la fila del catálogo en `servicios`, preservando intactos los registros históricos en `citas` y `citasservicios` sin borrados en cascada ni cambios de esquema.
- **Compatibilidad mantenida:** `ActiveRecord` se conserva íntegro para los módulos aún no migrados (`Usuario`, `Cita`, `CitaServicio`, `AdminCita`). El endpoint `/api/servicios` mantiene intacto el contrato JSON consumido por `src/js/app.js`.

### 3. Validación Ejecutada en Fase 2A (Antigravity)

#### A. Suite Completa PHPUnit (Ejecutada en Docker PHP 8.2 + MySQL 8.0)
- **Resultado:** `OK (65 tests, 469 assertions)` — 0 fallos, 0 errores.
- **Nuevas suites añadidas:**
  - `Tests\Unit\ServicioServiceTest` ([tests/Unit/ServicioServiceTest.php](file:///tests/Unit/ServicioServiceTest.php)): 6 pruebas unitarias verificando desacoplamiento de `ActiveRecord`, whitelist de `sincronizarEditable()` ignorando `id`, manejo de entradas no escalares, validación de IDs, límites `VARCHAR(60)` UTF-8, corrección de `is_numeric(!$this->precio)`, política de rechazo de precio cero/negativo y tope `9999.99` de `DECIMAL(6,2)`.
  - `Tests\Integration\ServicioModuloIntegrationTest` ([tests/Integration/ServicioModuloIntegrationTest.php](file:///tests/Integration/ServicioModuloIntegrationTest.php)): 7 pruebas de integración contra MySQL 8.0 real verificando ciclo CRUD completo, bloqueo de intento de cambiar `id` por POST en crear y actualizar, actualización sin cambios (`STATUS_OK`), distinción entre registro inexistente (`STATUS_NOT_FOUND`) y entrada inválida (`STATUS_INVALID`), manejo de fallos SQL reales (conexión cerrada y triggers MySQL `SIGNAL SQLSTATE '45000'` en `INSERT`, `UPDATE`, `DELETE` respondiendo HTTP `500` sin redirigir como éxito ni confundirse con catálogo vacío), control de acceso `isAdmin` + rechazo de visitante/cliente + exigencia de CSRF, y preservación de datos históricos en `citas` y `citasservicios`.

#### B. Verificación E2E en Navegador con JavaScript Habilitado (`tests/verificar_navegador.ps1` + `tests/browser_e2e_test.js`)

| Recorrido en Navegador | Identificador | Estado | Resultado Esperado vs Observado | Evidencia Técnica |
| :--- | :---: | :---: | :--- | :--- |
| **Listado Administrativo de Servicios** | `ADMIN-01` | **Probado** | Login como `admin@appsalon.com`, navegación a `/servicios` y renderizado de catálogo inicial. | 3 servicios iniciales listados en DOM. |
| **Validación y Creación de Servicio** | `ADMIN-02` | **Probado** | Envío inválido en `/servicios/crear` muestra alertas `.alerta.error` sin redirigir; envío válido crea servicio y redirige a `/servicios`. | Alertas de validación verificadas; `'Masaje Capilar Relax'` (`$95.50`) creado y listado. |
| **Actualización de Servicio** | `ADMIN-03` | **Probado** | Edición en `/servicios/actualizar?id=4` actualiza nombre y precio y redirige a `/servicios`. | `'Masaje Capilar Relax'` actualizado a `'Masaje Capilar Premium'` (`$115.00`) en DOM y MySQL. |
| **Eliminación de Servicio con CSRF** | `ADMIN-04` | **Probado** | Creación de servicio temporal y eliminación vía `POST /servicios/eliminar` con token CSRF remueve el servicio del catálogo. | `'Servicio Temporal Borrar'` eliminado de `/servicios` y ausente en MySQL. |
| **Catálogo Actualizado en Reserva (`/cita`)** | `PRE-01` y `REC-01..05` | **Probado** | Login de cliente `carlos@correo.com`; `/api/servicios` carga los 4 servicios vigentes (incluyendo `'Masaje Capilar Premium'`), permite selección, validación de fecha/hora, reserva con SweetAlert2 y persistencia en BD con 0 errores de consola/red. | 4 servicios en `/cita`; reserva persistida en `citas` y `citasservicios`; 0 errores de consola y 0 fallos de red. |

---

## Entrega 1: Seguridad y Consistencia del Sistema Actual (Cerrada y Fusionada en `main`)

- **Rama:** `feature/seguridad-y-consistencia-inicial` (fusionada en `main` en `bc1529bde644e1187be98332e3db912b80773cab`)
- **Estado:** **Aprobada y Cerrada** (52 pruebas PHPUnit de Entrega 1 + 7 escenarios HTTP + 5 recorridos de navegador + resiliencia de scripts en PowerShell verificados; Bash revisado estáticamente).

---

## Estado Global de Fases del Proyecto

| Fase | Alcance Principal | Estado |
| :--- | :--- | :---: |
| **Entrega 1** | Seguridad y consistencia inicial (Auth, CSRF, Rate Limiting, Tokens, Transacciones, Migraciones) | `Completada y en main` |
| **Fase 2A** | Separación de responsabilidades en módulo de catálogo de servicios (`Controller -> Service -> Repository`) | `Completada (En Auditoría)` |
| **Fase 2B** | Separación de responsabilidades en el flujo de citas y reservas | `Pendiente` |
| **Fase 3** | Profesionales, servicios con duración, horarios, descansos y bloqueos | `Pendiente` |
| **Fase 4** | Disponibilidad real y prevención de reservas simultáneas | `Pendiente` |
| **Fase 5** | Interfaz accesible de reservas y panel administrativo | `Pendiente` |
| **Fase 6** | Pagos (Stripe / Mercado Pago), notificaciones multicanal y reportes | `Pendiente` |

# Registro de Auditorías Independientes — AppSalon (`Johnsky-Games/AppSalon_PHP_MVC_JS_SASS`)

Este documento registra las auditorías técnicas independientes ejecutadas por el subagente **Auditor** sobre commits cerrados por SHA en su worktree separado (`C:\Users\jonat\.gemini\antigravity\worktrees\AppSalon_PHP_MVC_JS_SASS\auditing_fase_4b_reservas`), empleando contenedores Docker aislados sin tocar la copia local de la aplicación (`http://localhost:3000`, `appsalon-web`, `appsalon-db`, `appsalon_mvc`).

---

## Auditoría de Fase 4B: Reservas con Profesional, Ocupación Real y Protección contra Reservas Concurrentes

- **Rama auditada:** `feature/fase-4b-reservas-concurrencia`
- **SHA auditado:** `83e2a24b235a72c2865453ec13ea055d96419700`
- **Commit base (Fase 4A aceptada):** `939d84dbb9dcec67bc83421eaa5cbdc90389034a`
- **Subagente Auditor:** `1b62e9dd-6cdf-4566-bb28-b9c138feff52` (worktree `C:\Users\jonat\.gemini\antigravity\worktrees\AppSalon_PHP_MVC_JS_SASS\auditing_fase_4b_reservas`)
- **Veredicto para el SHA `83e2a24b235a72c2865453ec13ea055d96419700`:** **`APROBADO`**

---

### 1. Qué decía el `AUDITORIA.md` anterior (Primera Pasada sin Contexto Docker)

En su primera invocación (paso 36 de `1b62e9dd-6cdf-4566-bb28-b9c138feff52`), el subagente auditor intentó ejecutar `php -v` y `composer install --no-dev` directamente en el host Windows y emitió temporalmente `CORRECCIONES NECESARIAS` únicamente por bloqueo de entorno:

- `php -v` falló con `CommandNotFoundException` (PHP no instalado nativamente en el PATH de Windows).
- `composer install --no-dev` falló con `CommandNotFoundException` (Composer no instalado nativamente en el PATH de Windows).
- En esa primera pasada no se habían ejecutado aún las suites en contenedores Docker (`appsalon-php-test`, `composer:2`, `mysql:8.0`) ni se había completado la tabla de 15 puntos de revisión estática.

Tras proporcionar al auditor el contexto Docker del proyecto, el subagente auditor instaló las dependencias de desarrollo desde `composer.lock` vía `composer:2`, levantó un contenedor MySQL 8.0 exclusivo (`appsalon-audit4b-db` en la red `appsalon-audit4b-net`), aplicó `database/schema_base.sql` y `php database/migrador.php up`, ejecutó PHPUnit completo y la suite E2E en navegador, inspeccionó el código del SHA `83e2a24b235a72c2865453ec13ea055d96419700` y actualizó su veredicto a **`APROBADO`**.

---

### 2. Verificación del Stash Local (`stash@{0}`)

- **Ubicación:** Worktree `C:\Users\jonat\.gemini\antigravity\worktrees\AppSalon_PHP_MVC_JS_SASS\auditing_fase_4b_reservas` (`stash@{0}: On auditing_fase_4b_reservas: temp stash for checkout`).
- **Inspección sin aplicar ni borrar (`git stash show --stat stash@{0}`):**
  - Afecta únicamente a `PROGRESS.md` (`1 file changed, 10 insertions(+), 97 deletions(-)`), producto del cambio de rama previo al checkout del SHA `83e2a24b235a72c2865453ec13ea055d96419700`.
  - No contiene código de negocio ni secretos. Se conserva intacto en `stash@{0}` según las reglas operativas.

---

### 3. Revisión Estática de los 15 Criterios Obligatorios de Fase 4B

| # | Criterio de Auditoría (Fase 4B) | Estado | Evidencia en el SHA `83e2a24b235a72c2865453ec13ea055d96419700` |
| :-: | :--- | :---: | :--- |
| **1** | `citas` almacena profesional, inicio, fin y duración total en nuevas reservas. | **CUMPLE** | `database/migrations/004_reservas_profesional_ocupacion_historico.sql` (líneas 12–20) añade `hora_inicio TIME NULL`, `hora_fin TIME NULL`, `duracion_total_minutos INT NULL`, `profesionalId INT NULL`, índice `idx_citas_prof_fecha_intervalo (profesionalId, fecha, hora_inicio, hora_fin)` y FK `fk_citas_profesional`. `CitaRepository::crearReservaConProfesionalAtomica()` inserta todos estos campos dentro de la transacción. |
| **2** | `citasservicios` conserva nombre, precio y duración históricos capturados al reservar. | **CUMPLE** | `004_reservas_profesional_ocupacion_historico.sql` (líneas 25–28) añade `nombre_servicio VARCHAR(60) NULL`, `precio_servicio DECIMAL(6,2) NULL` y `duracion_minutos INT NULL`. `CitaRepository::crearReservaConProfesionalAtomica()` captura los valores vigentes del catálogo oficial bajo bloqueo `FOR SHARE` y los persiste en `citasservicios`. |
| **3** | Citas históricas anteriores permanecen válidas con `NULL`, sin profesional inventado. | **CUMPLE** | Todas las columnas nuevas en `citas` y `citasservicios` son `NULL DEFAULT NULL`; la migración no asigna profesionales ficticios a citas previas. `CitaRepository::findAdminCitasByFecha()` utiliza `LEFT JOIN profesionales` y `COALESCE(citasservicios.nombre_servicio, servicios.nombre)` / `COALESCE(citasservicios.precio_servicio, servicios.precio)` / `COALESCE(citasservicios.duracion_minutos, servicios.duracion_minutos)`. |
| **4** | `DisponibilidadService` excluye citas activas del profesional usando `[inicio, fin)`. | **CUMPLE** | `DisponibilidadService::consultar()` obtiene las reservas del profesional mediante `CitaRepository::findOcupacionByProfesionalEnFecha($profesionalId, $fecha)` y resta sus intervalos `[inicio, fin)` junto con descansos y bloqueos mediante `restarRestriccionesDeTurno()` (`A < R_fin && R_inicio < B`). |
| **5** | Duración y `hora_fin` provienen exclusivamente del catálogo en servidor. | **CUMPLE** | Tanto `DisponibilidadService` como `CitaService::reservar()` / `CitaRepository::crearReservaConProfesionalAtomica()` ignoran cualquier duración, precio o `hora_fin` enviados por el cliente y calculan `duracion_total_minutos` y `hora_fin` sumando `servicios.duracion_minutos` leídos de la base de datos. |
| **6** | `POST /api/citas` revalida dentro de transacción: profesional activo, servicios existentes y compatibles, fecha válida y futura en `America/Guayaquil`, intervalo dentro de turno laboral, ausencia de descansos, bloqueos y citas solapadas. | **CUMPLE** | `CitaRepository::crearReservaConProfesionalAtomica()` ejecuta bajo transacción InnoDB: bloqueo `FOR UPDATE` del profesional (`activo = 1`), bloqueo `FOR SHARE` de `servicios` en orden ascendente de ID, verificación en `profesionales_servicios` (`FOR SHARE`), encaje completo `[inicioMin, finMin)` dentro de un turno de `horarios_profesionales` (`FOR SHARE`), ausencia de cruce con `descansos_profesionales` y `bloqueos_profesionales` (`FOR SHARE`), y ausencia de solape con `citas` existentes (`FOR UPDATE`). La fecha futura en `America/Guayaquil` se valida en `CitaService`. |
| **7** | Dos reservas simultáneas del mismo profesional con solapamiento no se confirman ambas (`409`). | **CUMPLE** | El bloqueo exclusivo `SELECT id, nombre, activo FROM profesionales WHERE id = ? LIMIT 1 FOR UPDATE` bajo `READ COMMITTED` serializa reservas concurrentes del mismo profesional y hace que la segunda transacción realice una lectura actual (*current read*) de la cita recién confirmada, rechazando con HTTP `409` (`codigo: 'conflicto_ocupacion'`) y ejecutando `rollback()`. Verificado con workers multiproceso reales en `ReservaConcurrenciaIntegrationTest`. |
| **8** | Citas contiguas `10:00–10:30` y `10:30–11:00` son válidas. | **CUMPLE** | La condición de solapamiento semiabierto `$inicioMin < $ocupado['fin'] && $ocupado['inicio'] < $finMin` permite bordes contiguos exactos (`finA == inicioB` e `inicioA == finB`). Verificado en pruebas unitarias, de integración y E2E (`RES-01`). |
| **9** | Profesionales distintos pueden reservar el mismo horario. | **CUMPLE** | El bloqueo por clave primaria `profesionales WHERE id = ? FOR UPDATE` y el nivel `READ COMMITTED` en lecturas por índice secundario (`REC_NOT_GAP`) permiten que dos reservas simultáneas sobre profesionales distintos (`profA != profB`) en el mismo intervalo se confirmen en paralelo con HTTP `200` sin interbloqueos (*deadlocks*). |
| **10** | Fallo intermedio al insertar `citasservicios` hace rollback completo. | **CUMPLE** | Si falla la preparación o ejecución de cualquier inserción en `citasservicios`, el bloque `catch (Throwable $e)` invoca `$db->rollback()`, restaura el nivel de aislamiento de la sesión y relanza `PersistenceException` sin dejar filas huérfanas en `citas`. Verificado con trigger de fallo SQL real en MySQL. |
| **11** | Modificar o eliminar un servicio después de reservar no altera el snapshot histórico de la cita ya creada. | **CUMPLE** | `citasservicios` almacena `nombre_servicio`, `precio_servicio` y `duracion_minutos` al reservar, y `findAdminCitasByFecha()` prioriza estos campos mediante `COALESCE(...)` con `LEFT JOIN servicios`. Si un servicio cambia de precio/duración/nombre en el catálogo, la cita histórica mantiene sus valores originales. |
| **12** | Eliminar/cancelar una cita autorizada libera el horario del profesional. | **CUMPLE** | `CitaRepository::eliminarCitaAtomica()` adquiere `FOR UPDATE` sobre el profesional (si tiene `profesionalId`) y sobre la cita antes de eliminarla junto con sus `citasservicios`, liberando inmediatamente el intervalo en `GET /api/disponibilidad` y para nuevas reservas en `POST /api/citas`. |
| **13** | `usuarioId` proviene de `$_SESSION['id']`; un cliente no puede reservar ni cancelar para otro usuario. | **CUMPLE** | `APIController::guardar()` toma `$usuarioId = (int)$_SESSION['id']` e ignora cualquier `usuarioId` o `id` del payload POST. `APIController::eliminar()` pasa `$solicitanteId = (int)$_SESSION['id']` y `$esAdmin` a `CitaService::eliminar()`, que rechaza con `403` si el solicitante no es propietario ni administrador. |
| **14** | Entradas malformadas devuelven `422` sin alterar base de datos. | **CUMPLE** | `CitaService::reservar()` valida formato y tipo de `fecha`, `hora`, `servicios` y `profesionalId` antes de iniciar escrituras, devolviendo `status: 'invalid'` (`HTTP 422`, `codigo: 'solicitud_invalida'`) sin insertar registros en `citas` ni `citasservicios`. |
| **15** | Los servicios de dominio no leen `$_SESSION` ni conocen HTTP. | **CUMPLE** | `CitaService` y `DisponibilidadService` reciben parámetros explícitos de dominio y devuelven estructuras asociativas tipadas; la lectura de `$_SESSION`, cabeceras HTTP, CSRF y códigos de respuesta reside exclusivamente en `APIController` y demás controladores. |

---

### 4. Pruebas Ejecutadas en Docker y Comparativa Desarrollador vs. Auditor

#### A. Suite Completa PHPUnit (Unitarias, Integración y Concurrencia Multiproceso)

- **Imagen y versión efectiva:** `appsalon-php-test:latest` (`PHP 8.2.34`, `PHPUnit 10.5.66 by Sebastian Bergmann and contributors.`, instalado desde `composer.lock` mediante `composer:2`).
- **Comando ejecutado por el Auditor en su propio contenedor y red aislados (`auditing_fase_4b_reservas`):**
  ```powershell
  docker run --rm --network appsalon-audit4b-net -v "C:/Users/jonat/.gemini/antigravity/worktrees/AppSalon_PHP_MVC_JS_SASS/auditing_fase_4b_reservas:/app" -w /app -e DB_HOST=appsalon-audit4b-db -e DB_PORT=3306 -e DB_USER=root -e DB_PASS=root -e DB_NAME=appsalon_audit_test appsalon-php-test php -d variables_order=EGPCS vendor/bin/phpunit --colors=never
  ```
- **Resultado obtenido por el Auditor:**
  ```text
  PHPUnit 10.5.66 by Sebastian Bergmann and contributors.

  Runtime:       PHP 8.2.34
  Configuration: /app/phpunit.xml

  ...............................................................  63 / 137 ( 45%)
  ............................................................... 126 / 137 ( 91%)
  ...........                                                     137 / 137 (100%)

  Time: 00:15.455, Memory: 14.00 MB

  OK (137 tests, 1399 assertions)
  ```
  - **Código de salida:** `0`.

#### B. Suite E2E en Navegador Headless Chrome (`tests/verificar_navegador.ps1`)

- **Comando ejecutado por el Auditor en `auditing_fase_4b_reservas`:**
  ```powershell
  powershell -ExecutionPolicy Bypass -File tests\verificar_navegador.ps1
  ```
- **Resultado obtenido por el Auditor (`RUN_ID: b9819badc55b4a2588a4db444a3f4b1c`):**
  - Migraciones `001`, `002`, `003` y `004` aplicadas sobre contenedor efímero `appsalon-brw-db-b9819badc55b4a2588a4db444a3f4b1c`.
  - **16 comprobaciones E2E superadas (`[OK]`):**
    - `ADMIN-01` a `ADMIN-04`: CRUD de servicios con duración en minutos.
    - `PROF-01` y `PROF-02`: Gestión de profesionales, múltiples franjas horarias, descansos, bloqueos y estado activo/inactivo.
    - `PRE-01`: Login interactivo de cliente y redirección a `/cita`.
    - `DISP-01`: Consulta autenticada en `GET /api/disponibilidad`.
    - `REC-01` a `REC-04`: Flujo interactivo clásico del cliente en `/cita` y reserva con SweetAlert2.
    - `RES-01`: Reserva con profesional en `POST /api/citas`, descuento de ocupación en `GET /api/disponibilidad`, aceptación de cita contigua (`11:00–12:00`) y rechazo `409` por solapamiento (`10:30`).
    - `ADMIN-05`: Consulta administrativa por fecha en `/admin`, eliminación de cita temporal ID 2 con CSRF en `/api/eliminar` y liberación inmediata del horario `10:00–11:00`.
    - `AUTH-01`: Ciclo de autenticación y recuperación con `smtp_mock_server.php`.
    - `REC-05`: `Errores consola: 0, Solicitudes fallidas: 0`.
  - **Código de salida:** `0`.

#### C. Comparativa entre Reporte del Desarrollador y Verificación del Auditor

| Aspecto | Reportado por Desarrollador (`comprehensive_project_audit_roadmap`) | Verificado Independientemente por Auditor (`auditing_fase_4b_reservas`) | Coincidencia |
| :--- | :--- | :--- | :---: |
| **SHA evaluado** | `83e2a24b235a72c2865453ec13ea055d96419700` | `83e2a24b235a72c2865453ec13ea055d96419700` | Sí |
| **Versión PHPUnit / PHP** | PHPUnit `10.5.66` / PHP `8.2.34` | PHPUnit `10.5.66` / PHP `8.2.34` | Sí |
| **Resultado PHPUnit** | `OK (137 tests, 1399 assertions)` (Exit `0`) | `OK (137 tests, 1399 assertions)` (Exit `0`) | Exacta |
| **Resultado Suite Navegador E2E** | 16 checks `[OK]` (`RUN_ID: 94efb31fe250475cab44d2ecd181d5eb`, Exit `0`) | 16 checks `[OK]` (`RUN_ID: b9819badc55b4a2588a4db444a3f4b1c`, Exit `0`) | Exacta |
| **Aislamiento respecto a app local** | `appsalon-web`, `appsalon-db` y `appsalon_mvc` intactos | `appsalon-web`, `appsalon-db` y `appsalon_mvc` intactos | Sí |

---

### 5. Pendientes y Conclusión de Cierre de Fase 4B

- **Pendientes en Fase 4B:** Ninguno. Todos los criterios funcionales, transaccionales, de concurrencia e inmutabilidad histórica de la Fase 4B se cumplen íntegramente.
- **Veredicto Final Fase 4B (`83e2a24b235a72c2865453ec13ea055d96419700`):** **`APROBADO`**.
- **Siguiente paso autorizado:** Creación de la rama `feature/fase-5-interfaz-reservas` desde el commit aprobado de Fase 4B para implementar la Fase 5 (Interfaz de Reservas por Profesional y Disponibilidad Real).

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

---

## Auditoría de Fase 5: Interfaz de Reservas y Panel Administrativo

- **Rama auditada:** `feature/fase-5-interfaz-reservas`
- **SHA auditado:** `1fb3475fe2eec5ed4dc2f6afd22bc6b1bcaabb27`
- **Commit base (Fase 4B aprobada):** `c3353300f409a84c29254950b4be1abb2cb03125` (sobre código `83e2a24b235a72c2865453ec13ea055d96419700`)
- **Subagente Auditor:** `eaf68f52-b7bb-4e2f-80cf-485ff299e602` (worktree `C:\Users\jonat\.gemini\antigravity\worktrees\AppSalon_PHP_MVC_JS_SASS\auditing_fase_4b_reservas`)
- **Veredicto para el SHA `1fb3475fe2eec5ed4dc2f6afd22bc6b1bcaabb27`:** **`APROBADO`**

---

### 1. Verificación de Aislamiento y Stash Local (`stash@{0}`)

- **`git rev-parse HEAD`:** `1fb3475fe2eec5ed4dc2f6afd22bc6b1bcaabb27`.
- **Verificación de `stash@{0}`:** `stash@{0}: On auditing_fase_4b_reservas: temp stash for checkout` conservado intacto sin aplicar (`pop`) ni eliminar (`drop`).
- **Aislamiento de entorno:** No se modificó el código de negocio auditado ni se interactuó con `appsalon-web`, `appsalon-db`, `appsalon-net`, `appsalon_mvc`, `includes/.env` ni la copia local del proyecto.

---

### 2. Revisión Estática de los 11 Criterios Obligatorios de Fase 5

| # | Criterio de Auditoría (Fase 5) | Estado | Evidencia en el SHA `1fb3475fe2eec5ed4dc2f6afd22bc6b1bcaabb27` |
| :-: | :--- | :---: | :--- |
| **1** | Selección de servicios y profesionales compatibles (`GET /api/profesionales`). | **CUMPLE** | `public/index.php` (línea 44) registra `GET /api/profesionales`. `APIController::profesionales()` (líneas 126–159) exige sesión autenticada (`401` si no autenticado) y delega a `ProfesionalService::consultarParaReserva($_GET)` (líneas 1279–1371), que filtra profesionales activos (`activo = 1`), valida IDs de `servicios` (`422` si malformados) y calcula `compatible` y `profesionales_compatibles` exigiendo que el profesional realice **todos** los servicios seleccionados. En `src/js/app.js` (`mostrarServicios`, `obtenerProfesionalesCompatiblesEnMemoria`, `actualizarSelectorProfesionales`), cada servicio muestra su duración (`duracion_minutos min`) y el `<select id="profesional">` lista únicamente profesionales compatibles activos. |
| **2** | Calendario y horarios disponibles obtenidos dinámicamente del backend (`GET /api/disponibilidad`). | **CUMPLE** | `views/cita/index.php` (líneas 40–50) define `<input type="date" id="fecha" min="...">` y `<div id="contenedor-horarios-disponibles" class="intervalos-grid">`. En `src/js/app.js` (`consultarDisponibilidad`, `renderizarBotonesIntervalos`, `seleccionarIntervaloHorario`, líneas 538–642), el cliente consulta `GET /api/disponibilidad?profesionalId=...&fecha=...&servicios=...` y genera botones `.intervalo-btn` con `inicio - fin` y `duracion_minutos` calculados por el servidor. |
| **3** | Resumen con profesional, hora de inicio, hora de fin, duración total y precio total. | **CUMPLE** | `src/js/app.js` (`mostrarResumen`, líneas 710–807) exige `servicios`, `profesionalId`, `fecha`, `hora` y `hora_fin`, y renderiza desglose de servicios con duración individual (`Duración: X min`) y precio, junto con `Profesional`, `Fecha` formateada en `America/Guayaquil`, `Horario: HH:MM - HH:MM Horas`, `Duración Total: X min` y `Total a Pagar: $ X.XX`. |
| **4** | Actualización reactiva al cambiar servicios, profesional o fecha, invalidando selecciones obsoletas. | **CUMPLE** | `src/js/app.js` invoca `invalidarHorarioSeleccionado()` (líneas 227–242) al alternar un servicio (`seleccionarServicio`), cambiar profesional (`seleccionarProfesional`) o cambiar fecha (`seleccionarFecha`), limpiando `cita.hora`, `cita.hora_fin`, `#hora` y el estado `.seleccionado` / `aria-pressed`. Si al cambiar servicios el profesional elegido deja de ser compatible (`actualizarSelectorProfesionales`, líneas 356–382), limpia `cita.profesionalId`, reinicia el `<select>` e informa al usuario. |
| **5** | Estados de carga, error y disponibilidad vacía (incluyendo descarte de respuestas obsoletas por condición de carrera en cliente). | **CUMPLE** | `src/js/app.js` gestiona `#estado-disponibilidad` mediante `actualizarEstadoDisponibilidadUI(estado, mensaje)` con estados `idle`, `loading` (`Consultando horarios disponibles...`), `empty` (`data.status === 'empty'` o sin intervalos) y `error` (HTTP no exitoso o fallo de red), estilizados en `src/scss/layout/_cita.scss` (`.estado-loading`, `.estado-empty`, `.estado-error`). El contador monotónico `disponibilidadRequestId` (líneas 19, 308, 327, 366, 393, 495, 504, 515, 548, 562, 592) descarta cualquier respuesta asíncrona desfasada. |
| **6** | Gestión de conflictos `409` y prevención de envíos duplicados (`disabled` + `aria-busy`). | **CUMPLE** | En `src/js/app.js` (`reservarCita`, líneas 809–908), la bandera `enviandoReserva` bloquea clics concurrentes y el botón `#btn-reservar-cita` establece `disabled = true`, `aria-busy="true"` y texto `'Reservando...'`, restaurándose en `finally`. Si `POST /api/citas` responde `409` (`resultado.status === 'conflict'`), invalida el horario agotado, refresca profesionales o disponibilidad (`cargarProfesionalesCompatiblesRemoto()` / `consultarDisponibilidad()`), regresa a `paso = 2` conservando servicios/profesional/fecha y muestra alerta en formulario y modal `Swal.fire`. |
| **7** | Consulta (`GET /api/mis-citas`) y cancelación autorizada (`POST /api/eliminar`) de citas por el cliente, aislando por `$_SESSION['id']` y liberando disponibilidad del profesional. | **CUMPLE** | `APIController::misCitas()` (líneas 167–199) pasa exclusivamente `$_SESSION['id']` a `CitaService::consultarCitasCliente()`, que delega en `CitaRepository::findCitasClienteByUsuarioId()` con sentencia preparada `WHERE citas.usuarioId = ?`. `APIController::eliminar()` (líneas 365–473) valida CSRF (`exigir_csrf()`) y sesión (`isAuth()`), verifica pertenencia en `CitaService::eliminar()` (`403` si otro cliente intenta cancelar) y soporta respuesta JSON (`solicitaRespuestaJsonExplicita()`) además de redirección `302` para `/admin`. Al cancelar en `#mis-citas` (`cancelarCitaCliente`, líneas 1005–1047), se recarga `cargarMisCitas()` y `consultarDisponibilidad()`. |
| **8** | Vista administrativa (`/admin`) con profesional, intervalo y datos históricos, identificando citas antiguas con `NULL` sin inventar datos. | **CUMPLE** | `CitaRepository::findAdminCitasByFecha()` (líneas 1234–1289) proyecta `profesionalId`, `profesionales.nombre`, `hora_inicio`, `hora_fin`, `duracion_total_minutos` y `COALESCE(citasservicios.*, servicios.*)`. `views/admin/index.php` (líneas 30–62) muestra `Profesional: {nombre}` y `Horario: HH:MM - HH:MM (X min)` cuando hay profesional, o explícitamente `Profesional: Sin profesional asignado (cita histórica)` cuando `profesionalId` es `NULL`, sin inventar datos. |
| **9** | Diseño móvil accesible, navegación por teclado y etiquetas asociadas (`label`, `role="button"`, `tabindex="0"`, `aria-pressed`, `aria-live="polite"`). | **CUMPLE** | `views/cita/index.php` asocia `<label for="nombre">`, `<label for="profesional">`, `<label for="fecha">` y `<label for="hora">`, e incluye `aria-live="polite"` en `#estado-profesionales`, `#estado-disponibilidad`, `#contenedor-horarios-disponibles`, `#paso-3` y `#estado-mis-citas`. En `src/js/app.js` (líneas 191–203 y 605–632), las tarjetas `.servicio` usan `role="button"`, `tabindex="0"`, `aria-pressed` y soporte de teclado (`Enter`/`Espacio`), y los horarios usan `<button type="button" class="intervalo-btn" aria-pressed="...">`. Estilos `:focus-visible` y grid adaptable definidos en `_formulario.scss`, `_cita.scss` y `_servicios.scss`. |
| **10** | Coherencia de fechas y horas en `America/Guayaquil` (`CitaController`, `CitaService`, `src/js/app.js`). | **CUMPLE** | `CitaController::index()` (líneas 15–16) calcula `$fechaMinima` con `new \DateTimeImmutable('tomorrow', new \DateTimeZone(CitaService::TIMEZONE))`. `CitaService` evalúa `obtenerAhora()`, `obtenerDiaSemanaIso()` y `puede_cancelar` en `America/Guayaquil`. `src/js/app.js` (`obtenerFechaHoyGuayaquil`, `obtenerDiaSemanaIsoGuayaquil`, `formatearFechaEspanolGuayaquil`, líneas 430–484) utiliza explícitamente `timeZone: 'America/Guayaquil'`. |
| **11** | Política explícita, documentada y probada del endpoint clásico (`POST /api/citas`) para evitar reservas sin profesional inadvertidas en el nuevo flujo. | **CUMPLE** | `CitaService::debeUsarFlujoConProfesional()` (líneas 228–279) enruta obligatoriamente a `reservarConProfesional()` (y rechaza con `422` si falta un profesional válido) cuando: (a) el payload contiene la clave `profesionalId`, `profesional_id` o `profesional` (incluso vacía `""` o `null`), (b) se envía `modo_reserva = 'profesional'` o `flujo = 'profesional'`, o (c) se activa `CitaService::setExigirProfesional(true)` / `RESERVA_EXIGIR_PROFESIONAL=1` (salvo `modo_reserva = 'clasico'`). Probado en `CitaServiceTest` y `Fase5InterfazReservasIntegrationTest`. |

---

### 3. Pruebas Ejecutadas en Docker Aislado y Comparativa Desarrollador vs. Auditor

#### A. Suite Completa PHPUnit (Unitarias, Integración y Concurrencia Multiproceso)

- **Imagen y versión efectiva:** `appsalon-php-test` (`PHP 8.2.34`, `PHPUnit 10.5.66 by Sebastian Bergmann and contributors.`, `Configuration: /app/phpunit.xml`).
- **Contenedor y red aislados del Auditor:** `appsalon-audit5-db` (`mysql:8.0`) en red `appsalon-audit5-net`, base `appsalon_audit_test` inicializada con `database/schema_base.sql` y migraciones `001` a `004`.
- **Comando exacto ejecutado por el Auditor:**
  ```powershell
  docker run --rm --network appsalon-audit5-net -v "C:/Users/jonat/.gemini/antigravity/worktrees/AppSalon_PHP_MVC_JS_SASS/auditing_fase_4b_reservas:/app" -w /app -e DB_HOST=appsalon-audit5-db -e DB_PORT=3306 -e DB_USER=root -e DB_PASS=root -e DB_NAME=appsalon_audit_test appsalon-php-test php -d variables_order=EGPCS vendor/bin/phpunit --colors=never
  ```
- **Resultado obtenido por el Auditor:**
  ```text
  PHPUnit 10.5.66 by Sebastian Bergmann and contributors.

  Runtime:       PHP 8.2.34
  Configuration: /app/phpunit.xml

  ...............................................................  63 / 143 ( 44%)
  ............................................................... 126 / 143 ( 88%)
  .................                                               143 / 143 (100%)

  Time: 00:19.193, Memory: 14.00 MB

  OK (143 tests, 1512 assertions)
  ```
- **Código de salida:** `0`.
- **Limpieza posterior:** Contenedor `appsalon-audit5-db` y red `appsalon-audit5-net` eliminados al finalizar.

#### B. Suite E2E en Navegador Headless Chrome (`tests/verificar_navegador.ps1`)

- **Comando exacto ejecutado por el Auditor en `auditing_fase_4b_reservas`:**
  ```powershell
  powershell -ExecutionPolicy Bypass -File tests/verificar_navegador.ps1
  ```
- **Resultado obtenido por el Auditor (`RUN_ID: 7823d303480a43ac9f4f4326c4810d5d`):**
  - Entorno efímero aislado: red `appsalon-brw-net-7823d303480a43ac9f4f4326c4810d5d`, contenedor MySQL `appsalon-brw-db-7823d303480a43ac9f4f4326c4810d5d`, servidor web en `http://localhost:32788`.
  - **15 comprobaciones E2E superadas (`[OK]`):**
    - `ADMIN-01` a `ADMIN-04`: CRUD administrativo de servicios con duración en minutos (`Masaje Capilar Premium`, `$115.00`, `60 min`).
    - `PROF-01` y `PROF-02`: Gestión de profesionales (`Sofía Andrade` y `Valeria Castro`), múltiples franjas horarias, descansos, bloqueos y activación/desactivación.
    - `PRE-01`: Login interactivo de cliente y redirección a `/cita`.
    - `DISP-01`: Consulta autenticada en `GET /api/disponibilidad` (`200 ok`, `422 servicios_incompatibles`, `200 empty`).
    - `REC-01`: Carga de `/api/servicios` en `/cita` mostrando duración (`60 min`), atributos accesibles (`role="button"`, `tabindex="0"`, `aria-pressed`) y selección múltiple (`[1, 4]`).
    - `REC-02`: Navegación entre pasos 1 y 2 y filtrado dinámico de profesional compatible (`Valeria Castro`).
    - `REC-03`: Carga dinámica de intervalos en `GET /api/disponibilidad`, estado vacío (`empty`) en sábado e invalidación reactiva de profesional y horario al cambiar servicios.
    - `RES-01`: Reserva con profesional en `POST /api/citas`, descuento de ocupación, rechazo `409` por solapamiento (`10:30`) y cancelación interactiva por el cliente desde `#mis-citas` (`POST /api/eliminar`).
    - `REC-04`: Resumen completo con profesional (`Valeria Castro`), intervalo `11:30 - 13:00`, duración total `90 min` y total `$195.00`, envío `POST /api/citas` con CSRF y confirmación SweetAlert2 (`Cita Creada`).
    - `ADMIN-05`: Verificación en `/admin?fecha=2026-10-12` de profesional, intervalo, duración y snapshot histórico, eliminación de cita temporal y liberación inmediata del horario `10:00-11:00`.
    - `AUTH-01`: Ciclo completo de registro, rechazo de login no confirmado, reenvío de confirmación y recuperación sin enumeración.
    - `REC-05`: `Errores consola: 0, Solicitudes fallidas: 0`.
  - **Persistencia verificada en MySQL (`appsalon_browser_test`):** Cita ID `3`, fecha `2026-10-12`, `profesionalId = 2`, `hora_inicio = 11:30:00`, `hora_fin = 13:00:00`, `duracion_total_minutos = 90`, `2` servicios asociados.
  - **Código de salida:** `0`.
  - **Limpieza posterior:** Contenedores efímeros y `includes/.env` temporal eliminados automáticamente; `git checkout -- tests/browser_test_report.json` ejecutado para mantener intacto el árbol del SHA auditado.

#### C. Comparativa entre Reporte del Desarrollador y Verificación del Auditor

| Aspecto | Reportado por Desarrollador (`comprehensive_project_audit_roadmap`) | Verificado Independientemente por Auditor (`auditing_fase_4b_reservas`) | Coincidencia |
| :--- | :--- | :--- | :---: |
| **SHA evaluado** | `1fb3475fe2eec5ed4dc2f6afd22bc6b1bcaabb27` | `1fb3475fe2eec5ed4dc2f6afd22bc6b1bcaabb27` | Sí |
| **Versión PHPUnit / PHP** | PHPUnit `10.5.66` / PHP `8.2.34` | PHPUnit `10.5.66` / PHP `8.2.34` | Sí |
| **Resultado PHPUnit** | `OK (143 tests, 1512 assertions)` (Exit `0`) | `OK (143 tests, 1512 assertions)` (Exit `0`) | Exacta |
| **Resultado Suite Navegador E2E** | 15 checks `[OK]`, `0` errores consola, `0` fallos red (Exit `0`) | 15 checks `[OK]` (`RUN_ID: 7823d303480a43ac9f4f4326c4810d5d`), `0` errores consola, `0` fallos red (Exit `0`) | Exacta |
| **Aislamiento respecto a app local** | `appsalon-web`, `appsalon-db` y `appsalon_mvc` intactos | `appsalon-web`, `appsalon-db`, `appsalon_mvc` y `stash@{0}` intactos | Sí |

---

### 4. Conclusión y Veredicto de Cierre de Fase 5

- **Pendientes bloqueantes en Fase 5:** Ninguno. Los 11 criterios de alcance de la Fase 5 (interfaz reactiva de reservas por profesional, disponibilidad dinámica, resumen completo, manejo de conflictos `409`, prevención de envíos duplicados, consulta y cancelación autorizada en `#mis-citas`, panel administrativo con soporte de citas históricas `NULL`, accesibilidad móvil/teclado, zona horaria `America/Guayaquil` y política anti-bypass del endpoint clásico) están implementados y verificados.
- **Veredicto Final Fase 5 (`1fb3475fe2eec5ed4dc2f6afd22bc6b1bcaabb27`):** **`APROBADO`**.
- **Siguiente paso autorizado:** Creación de la rama `feature/fase-6a-notificaciones` desde el commit aprobado de Fase 5 para implementar la Fase 6A (Notificaciones de Confirmación, Cancelación y Recordatorios Idempotentes).

---

## Auditoría de Fase 6A: Notificaciones de Citas

### 1. Metadatos de la Revisión

| Campo | Detalle |
| :--- | :--- |
| **Fase Auditada** | Fase 6A — Notificaciones de Citas (`confirmacion`, `cancelacion`, `recordatorio`) |
| **Rama Evaluada** | `feature/fase-6a-notificaciones` |
| **SHA Auditado (Ronda 1)** | `0aa1e11d9328aa5558d43d50b5483a492850ce7f` (`CORRECCIONES NECESARIAS`) |
| **SHA Auditado y Aprobado (Ronda 2)** | `4fe475c02cc3dcd38a79ad7ec4ce5ef48f9e58ec` (`APROBADO`) |
| **SHA Base (Fase 5 aprobada)** | `1f63cd88f3fd6cab1dd958a6b8c82371b5f1d7b0` (sobre código `1fb3475fe2eec5ed4dc2f6afd22bc6b1bcaabb27`) |
| **Worktree del Auditor** | `C:\Users\jonat\.gemini\antigravity\worktrees\AppSalon_PHP_MVC_JS_SASS\auditing_fase_4b_reservas` |
| **Subagente Auditor** | `eaf68f52-b7bb-4e2f-80cf-485ff299e602` |
| **Fecha de Auditoría** | `2026-10-10` |
| **Stash preservado intacto** | `stash@{0}: On auditing_fase_4b_reservas: temp stash for checkout` |
| **Veredicto Final (Ronda 2)** | **`APROBADO`** |

---

### 2. Trazabilidad de Rondas de Auditoría (Ronda 1 $\rightarrow$ Ronda 2)

1. **Ronda 1 (`0aa1e11d9328aa5558d43d50b5483a492850ce7f`) — Veredicto: `CORRECCIONES NECESARIAS`:**
   - En la ejecución independiente de PHPUnit en el contenedor aislado `appsalon-audit6a-db` / `appsalon-audit6a-net`, se detectó `1` fallo en `Tests\Integration\ReservaConcurrenciaIntegrationTest::testInmutabilidadHistoricaAnteCambioOEliminacionPosteriorDelServicioEnCatalogo` (`ReservaConcurrenciaIntegrationTest.php:421`, esperado `'Manicura Tradicional'`, recibido `'Pedicura Spa'`).
   - **Causa raíz identificada por el Auditor:**
     1. `CitaRepository::findAdminCitasByFecha(string $fecha)` (`repositories/CitaRepository.php`, líneas 1238–1252) carecía de cláusula `ORDER BY`. Al eliminar el segundo servicio del catálogo (`citasservicios.servicioId = NULL` por `ON DELETE SET NULL`), el optimizador de MySQL 8.0 recorría el índice `servicioId` (`NULL` primero) tras las consultas adicionales de `NotificacionCitaRepository`, devolviendo los servicios en orden inverso y exponiendo además a `views/admin/index.php` al riesgo de filas no contiguas por `citas.id`.
     2. Las suites de integración previas que limpiaban `citas` en `setUp()` no limpiaban `notificaciones_citas`, dejando registros huérfanos con `cita_id = NULL` y `clave_idempotencia` ocupada entre pruebas.
     3. `PROGRESS.md` (líneas 27 y 57) citaba `cita:42:recordatorio:24h` / `cita:{id}:recordatorio:{horas}h` en lugar del formato real `cita:{id}:recordatorio:{fecha}_{hora}` generado por `NotificacionCitaService::generarClaveRecordatorio()`.
2. **Ronda 2 (`4fe475c02cc3dcd38a79ad7ec4ce5ef48f9e58ec`) — Verificación de Correcciones y Veredicto: `APROBADO`:**
   - Se inspeccionó `git diff 0aa1e11d9328aa5558d43d50b5483a492850ce7f..4fe475c02cc3dcd38a79ad7ec4ce5ef48f9e58ec` comprobando que los 3 hallazgos fueron corregidos de forma limpia:
     1. `repositories/CitaRepository.php` (línea 1252): incorpora `$consulta .= " ORDER BY citas.id ASC, citasservicios.id ASC ";` en `findAdminCitasByFecha()`.
     2. Limpieza de `notificaciones_citas` añadida en `setUp()` de `ApiSeguridadTest`, `CitaModuloIntegrationTest`, `DisponibilidadIntegrationTest`, `Fase5InterfazReservasIntegrationTest`, `ProfesionalAgendaIntegrationTest`, `ReservaConcurrenciaIntegrationTest`, `SecurityIntegrationTest`, `ServicioModuloIntegrationTest` y `UsuarioAuthIntegrationTest`.
     3. `PROGRESS.md` (líneas 27 y 57): alineado con `cita:42:recordatorio:2026-11-16_11:30` y `cita:{id}:recordatorio:{fecha}_{hora}`.

---

### 3. Revisión Estática Exhaustiva del Alcance de Fase 6A (`4fe475c02cc3dcd38a79ad7ec4ce5ef48f9e58ec`)

| # | Criterio del Alcance (Fase 6A) | Estado | Evidencia Técnica en el Código Auditado (`4fe475c02cc3dcd38a79ad7ec4ce5ef48f9e58ec`) |
| :---: | :--- | :---: | :--- |
| **1** | **Migración incremental `005_notificaciones_citas.sql` y su rollback `005_notificaciones_citas_rollback.sql`** | **CUMPLE** | `database/migrations/005_notificaciones_citas.sql` (líneas 10–39) crea `notificaciones_citas` (`ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`) con `clave_idempotencia VARCHAR(120) NOT NULL` (`UNIQUE KEY uq_notificaciones_clave_idempotencia`), `cita_id INT NULL DEFAULT NULL` con `FOREIGN KEY (cita_id) REFERENCES citas (id) ON DELETE SET NULL ON UPDATE CASCADE`, `cita_referencia_id INT NOT NULL` para conservar el ID original de la cita aunque sea eliminada de `citas`, `usuario_id INT NULL DEFAULT NULL` (`ON DELETE SET NULL`), e índices `idx_notificaciones_cita`, `idx_notificaciones_cita_ref`, `idx_notificaciones_usuario` e `idx_notificaciones_estado_reintento (estado, proximo_intento_at)`. `005_notificaciones_citas_rollback.sql` revierte limpiamente la tabla y el registro en `migraciones`. |
| **2** | **Correos de confirmación y cancelación de citas con información completa del servicio, profesional, fecha, hora y duración** | **CUMPLE** | `NotificacionCitaRepository::obtenerSnapshotCompletoCita()` (líneas 85–221) carga mediante `LEFT OUTER JOIN` el cliente, profesional, intervalo `[hora_inicio, hora_fin)`, `duracion_total_minutos` y los servicios con snapshot histórico (`COALESCE(cs.nombre_servicio, s.nombre)`, `COALESCE(cs.precio_servicio, s.precio)`, `COALESCE(cs.duracion_minutos, s.duracion_minutos)`). `NotificacionCitaService::construirContenidoHtml()` (líneas 564–707) incluye cita `#id`, cliente, fecha, horario, profesional (o indicación de cita sin profesional asignado), lista de servicios con duración y precio, y total formateado. En `CitaService::eliminar()` (líneas 743–765), se captura `$snapshotCancelacion` antes de `eliminarCitaAtomica($idCita)` y tras el borrado exitoso se envía la notificación de cancelación con `cita_id = NULL` y `cita_referencia_id = $idCita`. |
| **3** | **Aislamiento de fallos de correo (post-commit): si el envío SMTP falla, la reserva o cancelación confirmada en base de datos nunca debe revertirse** | **CUMPLE** | En `CitaService::reservar()` (líneas 460–464), `CitaService::reservarConProfesional()` (líneas 632–636) y `CitaService::eliminar()` (líneas 759–765), el despacho se ejecuta exclusivamente después del `COMMIT` de la transacción de base de datos, envuelto en `try / catch (\Throwable $e)` (`despacharNotificacionConfirmacionSegura` / `despacharNotificacionCancelacionSegura`, líneas 775–793). Dentro de `NotificacionCitaService::registrarYDespachar()` (líneas 505–547) y `Email::despacharNotificacionCita()` (líneas 353–445), cualquier excepción o retorno `false` del transporte SMTP registra la notificación en `estado = 'fallido'`, guarda `ultimo_error` truncado a 255 caracteres y calcula `proximo_intento_at` sin propagar error HTTP ni revertir la reserva o cancelación. |
| **4** | **Envíos idempotentes y reintentos seguros sin duplicar correos al cliente** | **CUMPLE** | `NotificacionCitaRepository::registrarOAdquirirParaEnvio()` (líneas 344–545) ejecuta una transacción corta con `SELECT ... WHERE clave_idempotencia = ? LIMIT 1 FOR UPDATE`. Si `estado === 'enviado'`, devuelve `debe_enviar = false, motivo = 'ya_enviado'`; si `estado === 'enviando'`, omite por envío en curso; si agotó `intentos >= max_intentos` o su `proximo_intento_at > $ahoraUtc` (salvo `$forzarReintento = true`), omite el envío. En caso elegible, pasa a `estado = 'enviando'` e incrementa `intentos` antes de despachar por SMTP. `adquirirCandidataParaReintento()` (líneas 556–670) revalida la fila con `FOR UPDATE`, reconstruye el mensaje desde `payload_json` y, si es un `recordatorio` cuyo `cita_id` quedó `NULL` (cita cancelada antes del reintento), la descarta marcándola `fallido` con `intentos = max_intentos` (`motivo = 'cita_cancelada'`). |
| **5** | **Recordatorios configurables en zona horaria `America/Guayaquil` mediante una tarea programada documentada** | **CUMPLE** | `NotificacionCitaService::TIMEZONE = 'America/Guayaquil'` (línea 19). `resolverHorasAnticipacionRecordatorio()` (líneas 110–132) resuelve el horizonte por argumento explícito, configuración de instancia, `NOTIFICACIONES_RECORDATORIO_HORAS` o valor por defecto `24` (`1..720`). `NotificacionCitaRepository::findCitaIdsEnVentanaRecordatorio()` (líneas 235–325) filtra citas futuras en `(ahora, ahora + N horas]` en `America/Guayaquil`. `scripts/procesar_notificaciones.php` (líneas 1–160) exige `PHP_SAPI === 'cli'`, soporta `--recordatorios`, `--reintentos`, `--horas`, `--limite`, `--ahora`, `--json` y documenta su programación en Cron / Task Scheduler. |
| **6** | **Configuración segura de enlaces (`APP_URL`), encabezados (`EMAIL_FROM`, `EMAIL_FROM_NAME`) y escapado HTML (`htmlspecialchars`)** | **CUMPLE** | `Email::resolverAppUrl()` (líneas 60–102) valida `FILTER_VALIDATE_URL`, exige esquema `http`/`https`, rechaza credenciales embebidas, caracteres de control y comillas/ángulos (`'`, `"`, `<`, `>`), cayendo a `http://localhost:3000` ante valores inseguros (`javascript:`, `data:`, CRLF). `Email::resolverRemitente()` (líneas 109–127) y `Email::sanearTextoCabecera()` (líneas 133–144) validan `EMAIL_FROM` y eliminan `\r`, `\n` y caracteres de control. `NotificacionCitaService::construirContenidoHtml()` (líneas 564–707) escapa todos los campos dinámicos mediante `self::escapar()` (`htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`, líneas 758–761). |
| **7** | **Pruebas automatizadas con servidor de correo simulado o transporte sustituible y sin regresiones en suites previas** | **CUMPLE** | `Tests\Unit\NotificacionCitaServiceTest` (5 pruebas), `Tests\Integration\Fase6ANotificacionesIntegrationTest` (3 pruebas integrales de ciclo completo, aislamiento SMTP + reintentos e idempotencia, y recordatorios + CLI), `Tests\Integration\MigrationTest::testMigracion005NotificacionesCitasUpIdempotenciaSetNullYRollback` y `NOTIF-01` en `tests/browser_e2e_test.js` con `tests/smtp_mock_server.php`. Suite completa: `152 tests, 1714 assertions`, `0` fallos. |
| **8** | **Respeto de la arquitectura por capas y uso exclusivo de sentencias preparadas** | **CUMPLE** | `Model\NotificacionCita` es una entidad pura que no extiende `ActiveRecord`. `NotificacionCitaRepository` utiliza exclusivamente sentencias preparadas (`prepare` + `bind_param` + `execute`) y lanza `PersistenceException`. `NotificacionCitaService` no accede a `$_SESSION`, `$_POST`, `$_GET` ni cabeceras HTTP. |

---

### 4. Ejecución Independiente de Suites de Pruebas en Entorno Aislado del Auditor (Ronda 2 — `4fe475c02cc3dcd38a79ad7ec4ce5ef48f9e58ec`)

#### A. Suite Completa PHPUnit (Contenedor Aislado del Auditor `appsalon-audit6a-r2-db` / `appsalon-audit6a-r2-net`)

- **Entorno creado por el auditor:**
  - Red Docker: `appsalon-audit6a-r2-net`
  - Contenedor MySQL 8.0: `appsalon-audit6a-r2-db` (base de datos `appsalon_audit_test`, inicializada con `database/schema_base.sql` + `php database/migrador.php up`, aplicando `001` a `005`).
- **Comando ejecutado:**
  ```powershell
  docker run --rm --network appsalon-audit6a-r2-net -v "${PWD}:/app" -w /app -e DB_HOST=appsalon-audit6a-r2-db -e DB_PORT=3306 -e DB_USER=root -e DB_PASS=root -e DB_NAME=appsalon_audit_test appsalon-php-test php -d variables_order=EGPCS vendor/bin/phpunit --colors=never
  ```
- **Resultado obtenido:**
  - `PHPUnit 10.5.66 by Sebastian Bergmann and contributors.` (`Runtime: PHP 8.2.34`)
  - **`OK (152 tests, 1714 assertions)`** (`Time: 00:26.652, Memory: 14.00 MB`)
  - **Código de salida:** `0`
- **Limpieza posterior verificada:** `docker rm -f appsalon-audit6a-r2-db` y `docker network rm appsalon-audit6a-r2-net` ejecutados al finalizar.

#### B. Suite E2E en Navegador Real Headless Chrome (`tests/verificar_navegador.ps1`)

- **Comando ejecutado en worktree del auditor:**
  ```powershell
  powershell -ExecutionPolicy Bypass -File tests/verificar_navegador.ps1
  ```
- **Resultado obtenido:**
  - **Identificador de ejecución independiente (`RUN_ID`):** `853769e6acbd48fcbb266d5713b9b725`
  - **17 comprobaciones E2E superadas (`[OK]`):**
    - `ADMIN-01` a `ADMIN-04`: CRUD administrativo de servicios con duración.
    - `PROF-01` y `PROF-02`: Gestión de profesionales (`Sofía Andrade` y `Valeria Castro`), múltiples franjas horarias, descansos, bloqueos y activación/desactivación.
    - `PRE-01`: Login interactivo de cliente y redirección a `/cita`.
    - `DISP-01`: Consulta autenticada en `GET /api/disponibilidad` (`200 ok`, `422 servicios_incompatibles`, `200 empty`).
    - `REC-01` a `REC-04`: Selección de servicios, filtrado de profesionales compatibles, carga dinámica de intervalos, invalidación reactiva, resumen completo y reserva `POST /api/citas` con confirmación SweetAlert2 (`Cita Creada`).
    - `RES-01`: Reserva con profesional, descuento de ocupación, rechazo `409` por solapamiento y cancelación interactiva desde `#mis-citas`.
    - `ADMIN-05`: Consulta administrativa en `/admin?fecha=2026-10-12`, eliminación en `/api/eliminar` y liberación de horario.
    - `AUTH-01`: Ciclo de registro, rechazo de login no confirmado, `/reenviar-confirmacion` y `/olvide`.
    - `NOTIF-01`: `8 correos capturados en buzón SMTP mock; confirmación de cita #3 (Valeria Castro, 11:30 - 13:00) y cancelaciones verificadas.`
    - `REC-05`: `Errores consola: 0, Solicitudes fallidas: 0`.
  - **Persistencia verificada en MySQL (`appsalon_browser_test`):** Cita ID `3`, fecha `2026-10-12`, `profesionalId = 2`, `hora_inicio = 11:30:00`, `hora_fin = 13:00:00`, `duracion_total_minutos = 90`, `2` servicios asociados; y en `notificaciones_citas`: `3` confirmaciones enviadas y `2` cancelaciones enviadas con historial preservado (`cita_id IS NULL` y `cita_referencia_id > 0`).
  - **Código de salida:** `0`.
  - **Limpieza posterior:** Contenedores efímeros y `includes/.env` temporal eliminados automáticamente; `git checkout -- tests/browser_test_report.json` ejecutado para mantener intacto el árbol del SHA auditado.

#### C. Comparativa entre Reporte del Desarrollador y Verificación del Auditor (Ronda 2)

| Aspecto | Reportado por Desarrollador (`comprehensive_project_audit_roadmap`) | Verificado Independientemente por Auditor (`auditing_fase_4b_reservas`) | Coincidencia |
| :--- | :--- | :--- | :---: |
| **SHA evaluado (Ronda 2)** | `4fe475c02cc3dcd38a79ad7ec4ce5ef48f9e58ec` | `4fe475c02cc3dcd38a79ad7ec4ce5ef48f9e58ec` | Sí |
| **Versión PHPUnit / PHP** | PHPUnit `10.5.66` / PHP `8.2.34` | PHPUnit `10.5.66` / PHP `8.2.34` | Sí |
| **Resultado PHPUnit** | `OK (152 tests, 1714 assertions)` (Exit `0`) | `OK (152 tests, 1714 assertions)` (Exit `0`) | Exacta |
| **Resultado Suite Navegador E2E** | 17 checks `[OK]` (`RUN_ID: 2ecae295c89d4fc789f2491b65cd3ee5`), `0` errores consola, `0` fallos red (Exit `0`) | 17 checks `[OK]` (`RUN_ID: 853769e6acbd48fcbb266d5713b9b725`), `0` errores consola, `0` fallos red (Exit `0`) | Exacta |
| **Aislamiento respecto a app local** | `appsalon-web`, `appsalon-db` y `appsalon_mvc` intactos | `appsalon-web`, `appsalon-db`, `appsalon_mvc` y `stash@{0}` intactos | Sí |

---

### 5. Conclusión y Veredicto de Cierre de Fase 6A

- **Pendientes bloqueantes en Fase 6A:** Ninguno. Los 3 hallazgos detectados en la Ronda 1 (`0aa1e11d9328aa5558d43d50b5483a492850ce7f`) fueron resueltos en `4fe475c02cc3dcd38a79ad7ec4ce5ef48f9e58ec`, y los 8 criterios de alcance de la Fase 6A (migración `005_notificaciones_citas` + rollback con `ON DELETE SET NULL` y `cita_referencia_id`, correos de confirmación y cancelación completos, aislamiento de fallos SMTP post-commit, idempotencia con `FOR UPDATE` y cola de reintentos con descarte de recordatorios cancelados, recordatorios configurables en `America/Guayaquil` con script CLI documentado, endurecimiento de `APP_URL`/cabeceras/HTML, pruebas automatizadas sin regresiones y arquitectura por capas con sentencias preparadas) están implementados y verificados.
- **Siguiente paso autorizado:** Inicio de la Fase 6B (`feature/fase-6b-reportes` — Reportes Administrativos) a partir del commit aprobado de la Fase 6A.

---

## Auditoría de Fase 6B: Reportes Administrativos

### 1. Metadatos de la Revisión

| Campo | Detalle |
| :--- | :--- |
| **Fase Auditada** | Fase 6B — Reportes Administrativos (`/admin/reportes` y `/admin/reportes/exportar`) |
| **Rama Evaluada** | `feature/fase-6b-reportes` |
| **SHA Auditado** | `fb82ec7f59571dca82bf738a30e79c2d19489894` |
| **SHA Base (Fase 6A aprobada)** | `4e85266eb33a3c0b890384a1303e800d771c2340` (código `4fe475c02cc3dcd38a79ad7ec4ce5ef48f9e58ec`) |
| **Worktree del Auditor** | `C:\Users\jonat\.gemini\antigravity\worktrees\AppSalon_PHP_MVC_JS_SASS\auditing_fase_4b_reservas` |
| **Subagente Auditor** | Auditor Independiente |
| **Fecha de Auditoría** | `2026-10-10` |
| **Stash preservado intacto** | `stash@{0}: On auditing_fase_4b_reservas: temp stash for checkout` |
| **Veredicto Final** | **`APROBADO`** |

---

### 2. Verificación de Aislamiento y Stash Local (`stash@{0}`)

- **`git rev-parse HEAD`:** `fb82ec7f59571dca82bf738a30e79c2d19489894`.
- **Verificación de `stash@{0}`:** `stash@{0}: On auditing_fase_4b_reservas: temp stash for checkout` verificado y preservado intacto sin aplicar (`pop`) ni eliminar (`drop`).
- **Aislamiento de entorno:** No se modificó el código de negocio auditado ni se interactuó con `appsalon-web`, `appsalon-db`, `appsalon-net`, `appsalon_mvc`, `includes/.env` ni la copia local en OneDrive.

---

### 3. Revisión Estática Exhaustiva de los 8 Criterios Obligatorios de Fase 6B

| # | Criterio de Auditoría (Fase 6B) | Estado | Evidencia Técnica en el SHA `fb82ec7f59571dca82bf738a30e79c2d19489894` |
| :-: | :--- | :---: | :--- |
| **1** | **Filtros `GET /admin/reportes` y `exportar`:** validación estricta de `fecha_inicio`, `fecha_fin` (`YYYY-MM-DD`, `fecha_inicio <= fecha_fin`, máx. 366 días), `profesional_id` (vacío / entero positivo / `'sin_profesional'`), `servicio_id`; respuesta HTTP `422` sin ejecutar consultas ante entradas inválidas (incluidos arrays). | **CUMPLE** | `services/ReporteService.php`: líneas 183–271 (`validarYNormalizarFiltros` valida `esFechaValida()`, orden cronológico, máximo 366 días, formatos de profesional y servicio), líneas 428–445 (`consultarReporte` comprueba `!$validacion['valido']` y aborta inmediatamente retornando `STATUS_INVALID` y `http_code = 422` sin invocar `obtenerFilasReservasEnRango`). `controllers/ReporteController.php`: líneas 58–61 (`index`) y 85–106 (`exportarCsv`) asignan `http_response_code($httpCode)` (422) y renderizan la vista con alertas descriptivas. `tests/Unit/ReporteServiceTest.php`: líneas 12–75 (`testValidarYNormalizarFiltrosRangoPredeterminadoYRechazo422AnteEntradasInvalidas` comprueba `expects($this->never())->method('obtenerFilasReservasEnRango')`). `tests/Integration/Fase6BReportesIntegrationTest.php`: líneas 376–394 prueba 422 ante fechas invertidas. |
| **2** | **Ocupación real sobre horas disponibles de turno:** `minutos_disponibles` por profesional y fecha (día ISO en `America/Guayaquil`) descontando descansos y bloqueos (totales y parciales) sin doble resta de solapamientos; `minutos_ocupados`; `porcentaje_ocupacion` sin división por cero; citas con `profesionalId IS NULL` separadas en sección propia sin distorsionar ocupación de profesionales activos. | **CUMPLE** | `services/ReporteService.php`: líneas 117–166 (`restarRestriccionesDeTurno` emplea semántica semiabierta `[inicio, fin)` previniendo restar dos veces descansos y bloqueos solapados), líneas 318–419 (`calcularMinutosDisponiblesPorProfesional` mapea por día de la semana ISO `1..7` en `America/Guayaquil` y asigna `0` minutos a profesionales inactivos), líneas 815–844 (`porcentaje_ocupacion = !$sinTurno ? round(($minOcup / $minDisp) * 100, 2) : 0.00` y `etiqueta_ocupacion = '0.00% (Sin turno disponible)'` evitando división por cero si `minDisp <= 0`), líneas 846–866 (`$acumuladorHistoricasSinProf` agrupa citas sin profesional en fila propia con `etiqueta_ocupacion = 'No aplica (cita histórica)'`, `minutos_disponibles = 0`, sin alterar el numerador ni denominador de los profesionales activos). `tests/Unit/ReporteServiceTest.php`: líneas 77–140. `tests/Integration/Fase6BReportesIntegrationTest.php`: líneas 248–263, 314–318 (Sofía Andrade 90 min ocupados / 390 min disponibles netos = 23.08%). |
| **3** | **Importes y duraciones desde snapshot histórico:** uso de `COALESCE(cs.nombre_servicio, s.nombre)`, `COALESCE(cs.precio_servicio, s.precio)` y `COALESCE(cs.duracion_minutos, s.duracion_minutos)`, inmutables ante cambios o eliminación del servicio del catálogo (`servicioId = NULL`); sin duplicar totales al filtrar por servicio. | **CUMPLE** | `repositories/ReporteRepository.php`: líneas 379–381 proyecta `COALESCE(cs.nombre_servicio, s.nombre)`, `COALESCE(cs.precio_servicio, s.precio)` y `COALESCE(cs.duracion_minutos, s.duracion_minutos)`; líneas 400–408 filtra por servicio mediante subconsulta `AND EXISTS (SELECT 1 FROM citasservicios cs_f WHERE cs_f.citaId = c.id AND cs_f.servicioId = ?)`. `services/ReporteService.php`: líneas 564–570 (`$servPrecio` y `$servDuracion`), líneas 615–628 (`$subtotalServicioFiltrado` y `$importeAtribuible = $servicioIdFiltro !== null ? $subtotalServicioFiltrado : $importeTotalCita`), líneas 741–743 (acumula `$importeAtribuible` en `$importeReservadoTotal` evitando duplicar totales en citas multiservicio). `tests/Integration/Fase6BReportesIntegrationTest.php`: líneas 290–324 (modificación de servicio de $80 a $160 y eliminación de servicio de $120; el reporte preserva $280.00 con nombres históricos intactos). |
| **4** | **Distinción explícita `importe_reservado_total` vs `ingreso_pagado_total`:** en servicio, UI (`/admin/reportes`) y CSV exportado (`$0.00` / pendiente de pago mientras no haya cobro confirmado). | **CUMPLE** | `services/ReporteService.php`: líneas 284–304 (`evaluarEstadoPagoCita` asigna `ingreso_pagado = 0.00` y `etiqueta_estado_pago = 'Pendiente de cobro (sin pago registrado)'` mientras `estado_pago !== 'pagado'`), líneas 912–917 (KPIs `importe_reservado_total`, `ingreso_pagado_total = 0.00`, `saldo_pendiente_cobro_total` y `nota_pagos`), líneas 1056–1072, 1087–1088, 1125–1126 y 1142–1145 (columnas independientes en las 4 secciones del CSV). `views/reportes/index.php`: líneas 99–101 (`#nota-distincion-pagos`), 126–148 (KPIs `#kpi-importe-reservado` y `#kpi-ingreso-pagado`), 166–167, 192–197, 220–221, 240–245, 281–293. `tests/Unit/ReporteServiceTest.php`: líneas 240–244, 258, 306–308. `tests/Integration/Fase6BReportesIntegrationTest.php`: líneas 309–312, 442–445. |
| **5** | **Estados vacíos y exportación CSV segura:** estados vacíos informativos; CSV con cabeceras seguras (`text/csv; charset=UTF-8`, `attachment`, `nosniff`, `no-cache`) y neutralización exhaustiva de *CSV Formula Injection* (`=`, `+`, `-`, `@`, `\t`, `\r`, `\n`, también tras espacios iniciales). | **CUMPLE** | `views/reportes/index.php`: líneas 153–155, 208–212 (`#estado-vacio-servicios`), 255–259 (`#estado-vacio-reportes`). `controllers/ReporteController.php`: líneas 112–118 emite cabeceras HTTP seguras. `services/ReporteService.php`: líneas 989–1018 (`sanearCeldaCsv` antepone `'` ante `=`, `+`, `-`, `@`, `\t`, `\r`, `\n`, evaluando tanto el primer carácter directo como el primer carácter visible tras `ltrim` de espacios y saltos), líneas 1029–1040 (escribe BOM UTF-8 `\xEF\xBB\xBF` y aplica `sanearCeldaCsv` a cada celda vía `fputcsv`). `tests/Unit/ReporteServiceTest.php`: líneas 277–292 y 353–360. `tests/Integration/Fase6BReportesIntegrationTest.php`: líneas 363–375 (estado vacío) y líneas 418–445 (fórmulas neutralizadas en CSV y cabeceras verificadas). |
| **6** | **Permisos administrativos y protección contra XSS:** control estricto con `isAdmin()` en `/admin/reportes` y `/admin/reportes/exportar`; escapado HTML exhaustivo (`s()`/`htmlspecialchars`) en `views/reportes/index.php`. | **CUMPLE** | `controllers/ReporteController.php`: líneas 55–56 (`index`) y 81–82 (`exportarCsv`) invocan obligatoriamente `iniciar_sesion_segura()` e `isAdmin()`. `views/reportes/index.php`: el 100% de las variables dinámicas de formulario, KPIs, tablas de profesionales, desglose de servicios y listado detallado de citas son escapadas con `s()` / `htmlspecialchars`. `views/templates/barra.php`: línea 15 restringe el enlace de navegación `<a href="/admin/reportes">` a sesiones con rol administrador. `tests/Integration/Fase6BReportesIntegrationTest.php`: líneas 189–238 comprueba bloqueo de visitantes no autenticados (`302`) y clientes sin rol admin (`403` en JSON / `302` en HTML) en ambas rutas. |
| **7** | **Pruebas automatizadas y suite E2E:** cobertura completa con `ReporteServiceTest`, `Fase6BReportesIntegrationTest` y escenario E2E `REP-01`. | **CUMPLE** | `tests/Unit/ReporteServiceTest.php` (5 pruebas unitarias cubriendo normalización, ocupación solapada, snapshots históricos, CSV injection y manejo de fallos 500). `tests/Integration/Fase6BReportesIntegrationTest.php` (4 pruebas de integración con MySQL 8 real cubriendo permisos admin, inmutabilidad tras edición/borrado de servicios, filtros combinados + 422 y exportación CSV). `tests/browser_e2e_test.js`: líneas 1121–1218 añade escenario interactivo `REP-01` en Puppeteer validando navegación, filtros, KPIs, ocupación de Valeria y Sofía, subtotal filtrado `$115.00` vs `$0.00` pagado y descarga de `/admin/reportes/exportar`. |
| **8** | **Arquitectura por capas y persistencia segura:** `ReporteRepository` opera exclusivamente con sentencias preparadas y excepciones de persistencia (`PersistenceException`); `ReporteService` desacoplado sin `$_SESSION`, `$_GET`, `$_POST` ni cabeceras HTTP. | **CUMPLE** | `repositories/ReporteRepository.php`: todas las consultas (`listarProfesionalesCatalogo`, `listarServiciosCatalogo`, `obtenerHorariosProfesionales`, `obtenerDescansosProfesionales`, `obtenerBloqueosEnRango`, `obtenerFilasReservasEnRango`) utilizan estrictamente `$db->prepare()`, `$stmt->bind_param()`, `$stmt->execute()` y envuelven cualquier fallo en `PersistenceException`. `services/ReporteService.php`: servicio de dominio puro con inyección de repositorio y reloj de referencia, sin dependencias superglobales de sesión o transporte HTTP. |

---

### 4. Pruebas Ejecutadas en Docker Aislado y Comparativa Desarrollador vs. Auditor

#### A. Suite Completa PHPUnit (Contenedor Aislado del Auditor `appsalon-audit6b-db` / `appsalon-audit6b-net`)

- **Entorno creado por el auditor:**
  - Red Docker: `appsalon-audit6b-net`
  - Contenedor MySQL 8.0: `appsalon-audit6b-db` (base de datos `appsalon_audit_test`, inicializada con `database/schema_base.sql` + `php database/migrador.php up`, aplicando migraciones `001` a `005`).
- **Comando ejecutado:**
  ```powershell
  docker run --rm --network appsalon-audit6b-net -v "C:/Users/jonat/.gemini/antigravity/worktrees/AppSalon_PHP_MVC_JS_SASS/auditing_fase_4b_reservas:/app" -w /app -e DB_HOST=appsalon-audit6b-db -e DB_PORT=3306 -e DB_USER=root -e DB_PASS=root -e DB_NAME=appsalon_audit_test appsalon-php-test php -d variables_order=EGPCS -d date.timezone=America/Guayaquil vendor/bin/phpunit --colors=never
  ```
- **Resultado obtenido:**
  - `PHPUnit 10.5.66 by Sebastian Bergmann and contributors.` (`Runtime: PHP 8.2.34`)
  - **`OK (161 tests, 1817 assertions)`** (`Time: 00:27.128, Memory: 16.00 MB`)
  - **Código de salida:** `0`
- **Nota técnica sobre zona horaria:** Al sincronizar la zona horaria de negocio (`America/Guayaquil`) entre PHP y MySQL, la suite completa (161 pruebas y 1817 aserciones) se ejecuta sin fallos ni desajustes de medianoche UTC.
- **Limpieza posterior verificada:** Contenedor `appsalon-audit6b-db` detenido y eliminado, y red `appsalon-audit6b-net` eliminada al finalizar.

#### B. Suite E2E en Navegador Real Headless Chrome (`tests/verificar_navegador.ps1`)

- **Comando ejecutado en worktree del auditor:**
  ```powershell
  powershell -ExecutionPolicy Bypass -File tests/verificar_navegador.ps1
  ```
- **Resultado obtenido:**
  - **Identificador de ejecución independiente (`RUN_ID`):** `89a23c7e2ff64515b28c6a771a8f3839`
  - **18 comprobaciones E2E superadas (`[OK]`):**
    - `ADMIN-01` a `ADMIN-04`: CRUD administrativo de servicios con duración.
    - `PROF-01` y `PROF-02`: Gestión de profesionales, horarios, descansos y bloqueos.
    - `PRE-01`: Login interactivo de cliente y redirección a `/cita`.
    - `DISP-01`: Consulta autenticada en `GET /api/disponibilidad`.
    - `REC-01` a `REC-04`: Flujo interactivo en `/cita`, accesibilidad, filtrado de profesionales, resumen y reserva SweetAlert2.
    - `RES-01`: Reserva con profesional, descuento de ocupación, rechazo `409` por solapamiento y cancelación en `#mis-citas`.
    - `ADMIN-05`: Consulta administrativa en `/admin?fecha=2026-10-12`, eliminación en `/api/eliminar` y liberación de ocupación.
    - `REP-01`: Consulta de reportes administrativos en `/admin/reportes`, validación de KPIs globales, ocupación de Valeria Castro (16.67%, 90/540 min) y Sofía Andrade (0.00%, 0/390 min), distinción de importe reservado ($195.00) vs ingreso pagado ($0.00), filtro por servicio #4 ($115.00 vs $0.00 pagado) y descarga de CSV con cabeceras y datos íntegros.
    - `AUTH-01`: Ciclo de autenticación, rechazo de usuario sin confirmar y recuperación de cuenta sin enumeración.
    - `NOTIF-01`: Despacho SMTP de correos transaccionales de confirmación y cancelación en buzón de pruebas (8 correos capturados).
    - `REC-05`: `Errores consola: 0, Solicitudes fallidas: 0`.
  - **Persistencia verificada en MySQL (`appsalon_browser_test`):** Cita ID `3`, fecha `2026-10-12`, profesional `2`, intervalo `[11:30:00, 13:00:00)`, duración `90 min`, `2` servicios asociados; notificaciones `3` confirmaciones y `2` cancelaciones.
  - **Código de salida:** `0`.
  - **Limpieza posterior:** Contenedores efímeros y `includes/.env` temporal eliminados; `git checkout -- tests/browser_test_report.json` ejecutado para mantener el worktree limpio.

#### C. Comparativa entre Reporte del Desarrollador y Verificación del Auditor

| Aspecto | Reportado por Desarrollador (`comprehensive_project_audit_roadmap`) | Verificado Independientemente por Auditor (`auditing_fase_4b_reservas`) | Coincidencia |
| :--- | :--- | :--- | :--- |
| **SHA evaluado** | `fb82ec7f59571dca82bf738a30e79c2d19489894` | `fb82ec7f59571dca82bf738a30e79c2d19489894` | Exacta |
| **Versión PHPUnit / PHP** | PHPUnit `10.5.66` / PHP `8.2.34` | PHPUnit `10.5.66` / PHP `8.2.34` | Exacta |
| **Resultado PHPUnit** | `OK (161 tests, 1817 assertions)` (Exit `0`) | `OK (161 tests, 1817 assertions)` (Exit `0`) | Exacta |
| **Resultado Suite Navegador E2E** | 18 checks `[OK]` (`RUN_ID: a1ca5acb73e54a49a7e83ec784e0eb4e`), `0` errores consola, `0` fallos red (Exit `0`) | 18 checks `[OK]` (`RUN_ID: 89a23c7e2ff64515b28c6a771a8f3839`), `0` errores consola, `0` fallos red (Exit `0`) | Exacta |
| **Aislamiento respecto a app local** | `appsalon-web`, `appsalon-db`, `appsalon-net`, `appsalon_mvc` y `.env` intactos | `appsalon-web`, `appsalon-db`, `appsalon-net`, `appsalon_mvc`, `.env` y `stash@{0}` intactos | Exacta |

---

### 5. Conclusión y Veredicto de Cierre de Fase 6B

- **Pendientes bloqueantes en Fase 6B:** Ninguno. Los 8 criterios de alcance de la Fase 6B (validación estricta de filtros con 422, cálculo de ocupación respecto a horas disponibles de turno sin división por cero ni duplicación de solapamientos, snapshots históricos inmutables, distinción explícita de importe reservado vs ingreso pagado $0.00, estados vacíos, exportación CSV segura con neutralización de formula injection, permisos administrativos con `isAdmin()` y escapado HTML, pruebas unitarias e integración completas y arquitectura desacoplada por capas) están implementados y verificados exhaustivamente.
- **Veredicto Final Fase 6B (`fb82ec7f59571dca82bf738a30e79c2d19489894`):** **`APROBADO`**.
- **Siguiente paso autorizado:** Inicio de la Fase 6C (`feature/fase-6c-pagos` — Pagos y Cobros) a partir del commit aprobado de la Fase 6B.

---

## Auditoría de Fase 6C: Base para Pagos

### 1. Metadatos de la Revisión

| Campo | Detalle |
| :--- | :--- |
| **Fase Auditada** | Fase 6C — Base para Pagos (`/api/pagos/iniciar`, `/api/pagos/webhook`, `/pago/resultado`) |
| **Rama Evaluada** | `feature/fase-6c-base-pagos` |
| **SHA Auditado** | `8178b830bc03197775fa653857e66ec74eb290db` |
| **SHA Base (Fase 6B aprobada)** | `70a186dadf0d89d93d7f8b7c24572f39cc0ff3df` (sobre código `fb82ec7f59571dca82bf738a30e79c2d19489894`) |
| **Worktree del Auditor** | `C:\Users\jonat\.gemini\antigravity\worktrees\AppSalon_PHP_MVC_JS_SASS\auditing_fase_4b_reservas` |
| **Subagente Auditor** | Auditor Independiente |
| **Fecha de Auditoría** | `2026-10-10` |
| **Stash preservado intacto** | `stash@{0}: On auditing_fase_4b_reservas: temp stash for checkout` |
| **Veredicto Final** | **`APROBADO`** |

---

### 2. Verificación de Aislamiento y Stash Local (`stash@{0}`)

- **`git rev-parse HEAD`:** `8178b830bc03197775fa653857e66ec74eb290db`.
- **Verificación de `stash@{0}`:** `stash@{0}: On auditing_fase_4b_reservas: temp stash for checkout` verificado y preservado intacto sin aplicar (`pop`) ni eliminar (`drop`).
- **Aislamiento de entorno:** No se modificó el código de negocio auditado ni se interactuó con `appsalon-web`, `appsalon-db`, `appsalon-net`, `appsalon_mvc`, `includes/.env` ni la copia local en OneDrive.

---

### 3. Revisión Estática Exhaustiva de los 9 Criterios Obligatorios de Fase 6C

| # | Criterio de Auditoría (Fase 6C) | Estado | Evidencia Técnica en el SHA `8178b830bc03197775fa653857e66ec74eb290db` |
| :-: | :--- | :---: | :--- |
| **1** | **Migración incremental `006_base_pagos.sql` y rollback `006_base_pagos_rollback.sql`:** tabla `pagos` con `cita_id` (`ON DELETE SET NULL`), `cita_referencia_id`, `referencia_pago` única, `transaccion_externa_id` única, `idempotency_key` única, estados tipados, y columna `citas.estado_pago`. | **CUMPLE** | `database/migrations/006_base_pagos.sql`: líneas 7–9 incorpora `citas.estado_pago VARCHAR(20) NOT NULL DEFAULT 'pendiente'`; líneas 11–42 crea la tabla `pagos` (`ENGINE=InnoDB`) con `referencia_pago`, `transaccion_externa_id`, `idempotency_key`, `estado`, `monto DECIMAL(8,2)`, `payload_proveedor JSON`, `UNIQUE KEY` para cada identificador y FKs con `ON DELETE SET NULL ON UPDATE CASCADE`. `006_base_pagos_rollback.sql`: líneas 7–12 revierte limpiamente `pagos`, la columna en `citas` y el registro en `migraciones`. `tests/Integration/MigrationTest.php`: líneas 447–542 (`testMigracion006BasePagosUpIdempotenciaSetNullYRollback`) valida aplicación, restricciones únicas, persistencia tras borrar cita con `cita_id = NULL` y `cita_referencia_id` intacto, rollback y reaplicación. |
| **2** | **Interfaz de pasarela y mock/sandbox (`PaymentGatewayInterface`, `MockPaymentGateway`):** desacoplamiento de pasarela, soporte de modos simulados y validación de firma webhook (`PAYMENT_WEBHOOK_SECRET` / HMAC-SHA256). | **CUMPLE** | `interfaces/PaymentGatewayInterface.php`: líneas 16–97 define contrato neutro (`iniciarPago`, `verificarPago`, `procesarNotificacionWebhook`, `reembolsarPago`). `classes/MockPaymentGateway.php`: líneas 17–105 implementa modos `aprobado`, `fondos_insuficientes` y `error_comunicacion`; líneas 60–78 resuelve `PAYMENT_WEBHOOK_SECRET`; líneas 178–250 (`generarFirmaWebhook` y `verificarFirmaWebhook`) valida firmas HMAC-SHA256 con `hash_equals()`. `tests/Unit/PagoServiceTest.php`: líneas 193–272 valida modos simulados y rechazo `401` ante firmas HMAC inválidas. |
| **3** | **Prohibición estricta de almacenar datos de tarjeta:** verificación en código de que NUNCA se persistan números de tarjeta (PAN), CVV ni fechas de vencimiento en `pagos` ni en base de datos. | **CUMPLE** | `classes/MockPaymentGateway.php`: líneas 28–58 (`CAMPOS_TARJETA_PROHIBIDOS`), líneas 108–141 (`contieneDatosTarjetaSensibles` con regex PAN `(?:\d[ -]*?){13,19}`), líneas 150–176 (`sanearPayloadSinDatosTarjeta` con `[REDACTED_PAN]`). `repositories/PagoRepository.php`: líneas 88–115 (`serializarPayloadSeguro` garantiza que jamás se guarde PAN/CVV en MySQL). `services/PagoService.php`: líneas 115–124 rechaza con `422 (PCI-DSS)` en `iniciarPago()`, y líneas 353–361 en webhooks. `tests/Unit/PagoServiceTest.php`: líneas 82–133. `tests/Integration/Fase6CBasePagosIntegrationTest.php`: líneas 190–216 (rechazo 422 y 0 filas insertadas en `pagos`). |
| **4** | **Prohibición estricta de confirmación por redirección del navegador:** el retorno del cliente en `/pago/resultado` solo consulta el estado verificado en base de datos en servidor, ignorando cualquier parámetro manipulable en la URL (`?status=approved`, etc.). | **CUMPLE** | `controllers/PagoController.php`: líneas 193–238 (`resultado`) solo consulta el estado verificado en MySQL mediante `PagoService::consultarResultadoPago()`. `services/PagoService.php`: líneas 510–581 detecta parámetros sospechosos en URL (`status`, `estado`, `pagado`, `aprobado`, `success`, `payment_status`), los ignora por completo, no modifica la base de datos y emite `advertencia_seguridad`. `views/pagos/resultado.php`: líneas 7–16 y 23–27 muestra el estado real del servidor y la advertencia. `tests/Unit/PagoServiceTest.php`: líneas 135–191. `tests/Integration/Fase6CBasePagosIntegrationTest.php`: líneas 279–304. `tests/browser_e2e_test.js`: líneas 1278–1286. |
| **5** | **Cálculo de montos exclusivamente en servidor:** el importe a pagar se obtiene desde los snapshots históricos de `citasservicios` en base de datos, ignorando cualquier monto manipulado por el cliente. | **CUMPLE** | `repositories/PagoRepository.php`: líneas 126–232 (`obtenerCitaConImporteServidor` suma `COALESCE(cs.precio_servicio, s.precio)`). `services/PagoService.php`: líneas 186–203 obtiene `$montoServidor`, detecta montos enviados por el cliente (`$montoClienteIgnorado = true`) y usa estrictamente el importe calculado en servidor para persistir e invocar la pasarela. `tests/Unit/PagoServiceTest.php`: líneas 16–80 (cliente envía `$0.01`, servidor cobra y persiste `$195.00`). `tests/Integration/Fase6CBasePagosIntegrationTest.php`: líneas 217–244 (cliente envía `$0.50`, MySQL persiste `$195.00`). |
| **6** | **Idempotencia transaccional y webhooks seguros:** bloqueo transaccional (`FOR UPDATE`), respuesta idempotente sin duplicar cobros ante webhooks repetidos o desordenados, y sincronización de `citas.estado_pago`. | **CUMPLE** | `services/PagoService.php`: líneas 164–294 (`iniciarPago` usa transacciones InnoDB con `FOR UPDATE`), líneas 205–244 (idempotencia por cita pagada y por `idempotency_key`), líneas 383–482 (`procesarWebhook` con `FOR UPDATE`, validación `AMOUNT_MISMATCH`, e idempotencia que responde `200` ante webhooks repetidos sin duplicar registros ni degradar estados finales), líneas 470–478 actualiza `citas.estado_pago`. `tests/Integration/Fase6CBasePagosIntegrationTest.php`: líneas 330–360 (webhook repetido es `200 idempotente` con exactamente 1 fila en `pagos`). |
| **7** | **Integración con reportes (`ReporteService`):** coherencia entre citas con pago completado y el `ingreso_pagado_total` en `/admin/reportes`. | **CUMPLE** | `repositories/ReporteRepository.php`: líneas 370–394 consulta la tabla `pagos` para proyectar `estado_pago` y `monto_pagado` de pagos completados o reembolsados. `services/ReporteService.php`: líneas 275–306 (`evaluarEstadoPagoCita` reconoce pagos completados y reembolsos), líneas 654–665 (atribuye el monto pagado respetando filtros por servicio). `tests/Integration/Fase6CBasePagosIntegrationTest.php`: líneas 377–467 (verifica actualización en `/admin/reportes` de `$0.00` a `$195.00` tras pago y retorno a `$0.00` tras reembolso). `tests/browser_e2e_test.js`: líneas 1346–1358. |
| **8** | **Documentación de decisiones comerciales pendientes:** registro explícito en `docs/DECISIONES_PAGOS.md` y `PROGRESS.md` sobre anticipos, cancelaciones, reembolsos y comisiones sin lock-in comercial. | **CUMPLE** | `docs/DECISIONES_PAGOS.md`: líneas 1–50 documenta detalladamente las 4 definiciones comerciales pendientes (política de cobro total vs anticipo/seña al reservar, ventana de cancelación y política de reembolsos/no-shows, absorción de comisiones bancarias e impuestos locales, selección comercial de pasarela en Ecuador/LatAm sin lock-in técnico). `PROGRESS.md`: líneas 18–34 y 62–74 refleja el estado de la arquitectura base de pagos. |
| **9** | **Pruebas automatizadas y suite E2E:** cobertura completa con `MigrationTest`, `PagoServiceTest`, `Fase6CBasePagosIntegrationTest` y escenario E2E `PAG-01` en Puppeteer. | **CUMPLE** | `tests/Integration/MigrationTest.php` (`testMigracion006BasePagosUpIdempotenciaSetNullYRollback`). `tests/Unit/PagoServiceTest.php` (4 pruebas unitarias de cálculo en servidor, prohibición de tarjetas, bloqueo de redirect y webhooks HMAC). `tests/Integration/Fase6CBasePagosIntegrationTest.php` (4 pruebas de integración MySQL 8 real de cálculo e inmutabilidad, flujo webhook e idempotencia, sincronización con reportes y seguridad auth/csrf/ownership). `tests/browser_e2e_test.js`: líneas 1222–1362 (`PAG-01` interactivo en navegador headless comprobando rechazo de tarjetas 422, cálculo servidor, bloqueo de redirect falso, webhook HMAC y actualización en `/admin/reportes`). |

---

### 4. Pruebas Ejecutadas en Docker Aislado y Comparativa Desarrollador vs. Auditor

#### A. Suite Completa PHPUnit (Contenedor Aislado del Auditor `appsalon-audit6c-db` / `appsalon-audit6c-net`)

- **Entorno creado por el auditor:**
  - Red Docker: `appsalon-audit6c-net`
  - Contenedor MySQL 8.0: `appsalon-audit6c-db` (base de datos `appsalon_audit_test`, inicializada con `database/schema_base.sql` + `php database/migrador.php up`, aplicando migraciones `001` a `006`).
- **Comando ejecutado:**
  ```powershell
  docker run --rm --network appsalon-audit6c-net -v "C:/Users/jonat/.gemini/antigravity/worktrees/AppSalon_PHP_MVC_JS_SASS/auditing_fase_4b_reservas:/app" -w /app -e DB_HOST=appsalon-audit6c-db -e DB_PORT=3306 -e DB_USER=root -e DB_PASS=root -e DB_NAME=appsalon_audit_test appsalon-php-test php -d variables_order=EGPCS -d date.timezone=America/Guayaquil vendor/bin/phpunit --colors=never
  ```
- **Resultado obtenido:**
  - `PHPUnit 10.5.66 by Sebastian Bergmann and contributors.` (`Runtime: PHP 8.2.34`)
  - **`OK (170 tests, 1949 assertions)`** (`Time: 00:33.570, Memory: 16.00 MB`)
  - **Código de salida:** `0`
- **Limpieza posterior verificada:** Contenedor `appsalon-audit6c-db` detenido y eliminado, y red `appsalon-audit6c-net` eliminada al finalizar.

#### B. Suite E2E en Navegador Real Headless Chrome (`tests/verificar_navegador.ps1`)

- **Comando ejecutado en worktree del auditor:**
  ```powershell
  powershell -ExecutionPolicy Bypass -File tests/verificar_navegador.ps1
  ```
- **Resultado obtenido:**
  - **Identificador de ejecución independiente (`RUN_ID`):** `8effe3d0db0e46bebc3ee51779558903`
  - **19 comprobaciones E2E superadas (`[OK]`):**
    - `ADMIN-01` a `ADMIN-04`: CRUD administrativo de servicios con duración.
    - `PROF-01` y `PROF-02`: Gestión de profesionales, horarios, descansos y bloqueos.
    - `PRE-01`: Login interactivo de cliente y redirección a `/cita`.
    - `DISP-01`: Consulta autenticada en `GET /api/disponibilidad`.
    - `REC-01` a `REC-04`: Flujo interactivo en `/cita`, accesibilidad, filtrado de profesionales, resumen y reserva SweetAlert2.
    - `RES-01`: Reserva con profesional, descuento de ocupación, rechazo `409` por solapamiento y cancelación en `#mis-citas`.
    - `ADMIN-05`: Consulta administrativa en `/admin?fecha=2026-10-12`, eliminación en `/api/eliminar` y liberación de ocupación.
    - `REP-01`: Consulta de reportes administrativos en `/admin/reportes`, validación de KPIs globales, ocupación y exportación CSV.
    - `PAG-01`: Flujo completo de pagos: rechazo `422` ante envío de número de tarjeta/CVV, cálculo de importe en servidor ($195.00 ignorando $1.00 enviado por cliente), bloqueo de confirmación por redirección del navegador en `/pago/resultado`, procesamiento de webhook con firma HMAC-SHA256, verificación de respuesta idempotente sin duplicar cobros y actualización inmediata de `$195.00` pagado en `/admin/reportes`.
    - `AUTH-01`: Ciclo de autenticación, rechazo de usuario sin confirmar y recuperación de cuenta sin enumeración.
    - `NOTIF-01`: Despacho SMTP de correos transaccionales de confirmación y cancelación en buzón de pruebas (8 correos capturados).
    - `REC-05`: `Errores consola: 0, Solicitudes fallidas: 0`.
  - **Persistencia verificada en MySQL (`appsalon_browser_test`):** Pago transaccional verificado: `cita_referencia_id = 3`, `estado = 'completado'`, `monto = 195.00 USD`, `citas.estado_pago = 'pagado'`.
  - **Código de salida:** `0`.
  - **Limpieza posterior:** Contenedores efímeros y `includes/.env` temporal eliminados; `git checkout -- tests/browser_test_report.json` ejecutado para mantener el worktree limpio.

#### C. Comparativa entre Reporte del Desarrollador y Verificación del Auditor

| Aspecto | Reportado por Desarrollador (`comprehensive_project_audit_roadmap`) | Verificado Independientemente por Auditor (`auditing_fase_4b_reservas`) | Coincidencia |
| :--- | :--- | :--- | :--- |
| **SHA evaluado** | `8178b830bc03197775fa653857e66ec74eb290db` | `8178b830bc03197775fa653857e66ec74eb290db` | Exacta |
| **Versión PHPUnit / PHP** | PHPUnit `10.5.66` / PHP `8.2.34` | PHPUnit `10.5.66` / PHP `8.2.34` | Exacta |
| **Resultado PHPUnit** | `OK (170 tests, 1949 assertions)` (Exit `0`) | `OK (170 tests, 1949 assertions)` (Exit `0`) | Exacta |
| **Resultado Suite Navegador E2E** | 19 checks `[OK]` (`RUN_ID: 195d4b1980c34ed5bb8203d4ed1be696`), `0` errores consola, `0` fallos red (Exit `0`) | 19 checks `[OK]` (`RUN_ID: 8effe3d0db0e46bebc3ee51779558903`), `0` errores consola, `0` fallos red (Exit `0`) | Exacta |
| **Aislamiento respecto a app local** | `appsalon-web`, `appsalon-db`, `appsalon-net`, `appsalon_mvc` y `.env` intactos | `appsalon-web`, `appsalon-db`, `appsalon-net`, `appsalon_mvc`, `.env` y `stash@{0}` intactos | Exacta |

---

### 5. Conclusión y Veredicto de Cierre de Fase 6C

- **Pendientes bloqueantes en Fase 6C:** Ninguno. Los 9 criterios de alcance de la Fase 6C (migración `006_base_pagos` + rollback con `ON DELETE SET NULL`, `PaymentGatewayInterface` desacoplada con `MockPaymentGateway` para pruebas sin cargos reales, cumplimiento estricto PCI-DSS sin almacenar datos de tarjeta, prohibición de confirmación por redirección del navegador, cálculo de importes exclusivamente en el servidor, idempotencia transaccional con `FOR UPDATE` y webhooks con firma HMAC-SHA256, sincronización con `ReporteService`, documentación de decisiones comerciales en `docs/DECISIONES_PAGOS.md` y suite completa de pruebas unitarias, de integración y E2E `PAG-01`) están implementados y verificados exhaustivamente.
- **Veredicto Final Fase 6C (`8178b830bc03197775fa653857e66ec74eb290db`):** **`APROBADO`**.
- **Siguiente paso autorizado:** Cierre del hito de pagos y consolidación general del roadmap.

---

## Auditoría Final de Cierre Técnico (`a1de022faf087dfd609e6acb72b737f12e20c34a`)

- **Fecha de Auditoría:** 2026-10-10
- **Rol:** Subagente Auditor Independiente
- **Worktree Exclusivo de Auditoría:** `C:\Users\jonat\.gemini\antigravity\worktrees\AppSalon_PHP_MVC_JS_SASS\auditing_fase_4b_reservas`
- **SHA Auditado:** `a1de022faf087dfd609e6acb72b737f12e20c34a`
- **Commit Base (Fase 6C aprobada):** `ab3350ba6abf2d89ea4f38551b006aace97ae286` (sobre código `8178b830bc03197775fa653857e66ec74eb290db`)
- **Rama Asociada:** `feature/cierre-tecnico`
- **Integridad de Stash:** `stash@{0}: On auditing_fase_4b_reservas: temp stash for checkout` verificado y preservado intacto.

---

### 1. Alcance Evaluado del Cierre Técnico

El Cierre Técnico consolida el endurecimiento operativo, automatización de CI, entorno Docker desacoplado, utilidades de respaldo y restauración consistentes, cabeceras globales de seguridad y documentación de operaciones:

1. **Auditoría de seguridad, rutas, permisos y cabeceras:** Emisión de `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`; protección `isAdmin()` en rutas administrativas y `isAuth()` en usuario; validación CSRF y manejo de 404 seguro.
2. **Revisión de dependencias y scripts (`composer.json`, `package.json`, `.gitignore`):** Scripts estandarizados en `composer.json`, compilación frontend reproducible y ausencia de secretos o archivos `.env` en control de versiones.
3. **Revisión de consultas SQL, claves foráneas e índices:** Consistencia referencial de las 6 migraciones (`001` a `006`), índices (`idx_citas_estado_pago`, etc.) y documentación en `database/README.md`.
4. **Flujo de Integración Continua (CI en GitHub Actions):** `.github/workflows/ci.yml` configurado con PHP 8.2, MySQL 8.0, migraciones, PHPUnit y prueba de backup/restore.
5. **Entorno Docker reproducible y variables de entorno:** `includes/.env.example` documentando todas las variables operativas y `Dockerfile`/`docker-compose.yml`.
6. **Scripts CLI de Respaldo y Restauración probados:** `scripts/backup_db.php` y `scripts/restore_db.php` (soporte `.sql` y `.sql.gz` con SHA-256 e integridad referencial) cubiertos por `tests/Integration/BackupRestoreIntegrationTest.php`.
7. **Guía de Migración y Operaciones:** `docs/GUIA_MIGRACION_OPERACIONES.md` con requisitos, instalación, migraciones, tareas programadas (Cron y Task Scheduler) y procedimientos operativos.
8. **Pruebas automatizadas y verificación general:** PHPUnit completo, verificación de sintaxis (`php -l`) y suite E2E en navegador.

---

### 2. Matriz de Evidencia Técnica de Criterios Auditados

| Criterio | Archivo(s) | Líneas Clave | Evidencia Técnica Verificada | Estado |
| :--- | :--- | :--- | :--- | :---: |
| **1. Cabeceras HTTP, Rutas, Permisos y 404** | `includes/funciones.php`<br>`Router.php`<br>`public/index.php`<br>`controllers/PagoController.php` | `funciones.php:43-68,74`<br>`Router.php:22-24,40,47-49`<br>`index.php:55,85`<br>`PagoController.php:240-287` | Función `emitir_cabeceras_seguridad()` emite `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN` y `Referrer-Policy: strict-origin-when-cross-origin` en enrutador, vistas y sesiones. `Router.php` emite `http_response_code(404)` ante rutas inexistentes. Endpoint `POST /api/pagos/reembolsar` exige `isAdmin()` y `validar_csrf()` (403 si falta token) y delega a `PagoService::reembolsarPago`. | **CUMPLE** |
| **2. Dependencias, Scripts y .gitignore** | `composer.json`<br>`package.json`<br>`.gitignore` | `composer.json:29-36`<br>`package.json:6-11`<br>`.gitignore:4-5` | `composer.json` define scripts estandarizados (`test`, `migrate:status`, `migrate:up`, `migrate:down`, `db:backup`, `db:restore`). `package.json` define scripts de build para Sass y JS. `.gitignore` incluye `includes/.env` y `storage/`. Cero archivos `.env` o secretos rastreados en Git (`git ls-files`). | **CUMPLE** |
| **3. Consultas SQL, Claves Foráneas e Índices** | `database/migrations/006_base_pagos.sql`<br>`database/migrations/006_base_pagos_rollback.sql`<br>`database/README.md` | `006_base_pagos.sql:7-10`<br>`006_base_pagos_rollback.sql:9-11`<br>`README.md:12-17` | Migración `006` añade índice `idx_citas_estado_pago` sobre `citas(estado_pago)` y rollback lo elimina limpiamente. `database/README.md` documenta las 6 migraciones, claves foráneas `ON DELETE SET NULL`/`CASCADE`/`RESTRICT` e índices de búsqueda/unicidad. | **CUMPLE** |
| **4. Integración Continua (CI en GitHub Actions)** | `.github/workflows/ci.yml` | `ci.yml:1-77` | Pipeline CI configurado en Ubuntu Latest con servicio `mysql:8.0` (`appsalon_ci_test`), PHP 8.2 con `mysqli`, `pdo_mysql`, `mbstring`, `zlib`, linter sintáctico (`php -l`), carga de `schema_base.sql`, migraciones `001-006`, ejecución de PHPUnit y validación de `backup_db.php` / `restore_db.php` con compresión `.sql.gz`. | **CUMPLE** |
| **5. Docker Reproducible y Variables de Entorno** | `includes/.env.example`<br>`Dockerfile`<br>`docker-compose.yml` | `.env.example:1-41`<br>`Dockerfile:1-10`<br>`docker-compose.yml:1-69` | `.env.example` documenta todas las variables (`APP_ENV`, `APP_URL`, `APP_TIMEZONE`, `DB_*`, `EMAIL_*`, `NOTIFICACIONES_*`, `RESERVA_*`, `PAYMENT_*`). `Dockerfile` configura `php:8.2-apache` con extensiones y DocumentRoot `/public`. `docker-compose.yml` orquesta MySQL y Apache con nombres de contenedor y red parametrizables para evitar colisiones con entornos locales. | **CUMPLE** |
| **6. Scripts CLI de Respaldo y Restauración** | `scripts/backup_db.php`<br>`scripts/restore_db.php`<br>`tests/Integration/BackupRestoreIntegrationTest.php` | `backup_db.php:1-220`<br>`restore_db.php:1-234`<br>`BackupRestoreIntegrationTest.php:79-310` | `backup_db.php` ejecuta snapshot consistente en InnoDB (`REPEATABLE READ` + `START TRANSACTION WITH CONSISTENT SNAPSHOT`), exporta DDL y datos en `utf8mb4`, soporta `.sql.gz` y genera hash SHA-256. `restore_db.php` autodetecta GZIP, parsea SQL respetando comillas y comentarios, valida cada sentencia y restaura integridad referencial. Cobertura probada en `BackupRestoreIntegrationTest.php`. | **CUMPLE** |
| **7. Guía de Migración y Operaciones** | `docs/GUIA_MIGRACION_OPERACIONES.md` | `GUIA_MIGRACION_OPERACIONES.md:1-186` | Documentación completa con 6 secciones: requisitos de sistema, instalación desde cero, gestión de migraciones (001 a 006), tareas programadas (Linux Cron y Windows Task Scheduler para `scripts/procesar_notificaciones.php`), políticas de respaldo/restauración y matriz de seguridad de rutas. | **CUMPLE** |
| **8. Verificación y Suites Automatizadas** | Código completo<br>`tests/Integration/BackupRestoreIntegrationTest.php` | `BackupRestoreIntegrationTest.php:330-336` | Sintaxis PHP: 100% OK (`php -l`). Navegador E2E: 19/19 checks `[OK]`. **HALLAZGO CRÍTICO EN PHPUNIT:** La prueba `BackupRestoreIntegrationTest::testAuditoriaIntegralDeCabecerasSeguridadRutasPermisosEIndices()` contamina variables superglobales (`$_SERVER['REQUEST_URI'] = '/api/pagos/reembolsar'`, `$_SERVER['HTTP_ACCEPT'] = 'application/json'`) sin restaurarlas en `tearDown()`, provocando 2 fallos en `CitaModuloIntegrationTest` y salida riesgosa en ejecuciones limpias de PHPUnit (`FAILURES! Tests: 172, Assertions: 2031, Failures: 2, Risky: 1`). | **NO CUMPLE (REQUIERE CORRECCIÓN)** |

---

### 3. Evidencia de Ejecución de Pruebas

#### A. Verificación Sintáctica PHP (`php -l`)
- **Comando:** `docker run --rm -v "C:/Users/...:/app" -w /app appsalon-php-test sh -c "find Router.php classes controllers database includes interfaces models public repositories scripts services tests views -name '*.php' -print0 | xargs -0 -n1 php -l"`
- **Resultado:** 89 archivos analizados; 0 errores de sintaxis detectados.

#### B. Suite Completa de Pruebas PHPUnit (Contenedor Aislado `appsalon-auditct-db`)
- **Entorno de Auditoría:** Contenedor Docker aislado `appsalon-auditct-db` (`mysql:8.0`) en red `appsalon-auditct-net`, inicializado con `database/schema_base.sql` y migraciones `001` a `006` aplicadas mediante `migrador.php up`.
- **Comando ejecutado:**
  ```bash
  docker run --rm --network appsalon-auditct-net -v "C:/Users/.../auditing_fase_4b_reservas:/app" -w /app \
    -e DB_HOST=appsalon-auditct-db -e DB_PORT=3306 -e DB_USER=root -e DB_PASS=root -e DB_NAME=appsalon_audit_test \
    appsalon-php-test php -d variables_order=EGPCS -d date.timezone=America/Guayaquil vendor/bin/phpunit --do-not-cache-result --colors=never
  ```
- **Resultado obtenido (Ejecución Limpia):**
  - **Fallo:** `FAILURES! Tests: 172, Assertions: 2031, Failures: 2, Risky: 1.`
  - **Código de salida:** `1`.
  - **Detalle de los fallos:**
    1. `Tests\Integration\CitaModuloIntegrationTest::testAdminControllerExigeRolAdministrador`: `Failed asserting that 403 is identical to 302.` (Test arriesgado: salida inesperada `{"resultado":false,"error":"Acceso denegado"}`).
    2. `Tests\Integration\CitaModuloIntegrationTest::testApiEliminarManejaFalloSqlEnModalidadesHtmlYJsonConRollbackSinRedireccion302`: `Failed asserting that '{"resultado":false,"error":"No fue posible eliminar la cita debido a un error de base de datos."}' contains "Error 500: No fue posible eliminar la cita debido a un error de base de datos."`.
  - **Análisis de Causa Raíz:**
    En `tests/Integration/BackupRestoreIntegrationTest.php` (líneas 330-336), `testAuditoriaIntegralDeCabecerasSeguridadRutasPermisosEIndices()` sobrescribe:
    ```php
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/api/pagos/reembolsar';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    ```
    Al no existir un método `tearDown()` ni un bloque `try ... finally` que restaure `$_SERVER`, el estado global permanece contaminado para las pruebas subsecuentes. Cuando `CitaModuloIntegrationTest` se ejecuta a continuación en el mismo proceso PHP, `es_peticion_json()` (`includes/funciones.php:90-99`) evalúa `true` debido a `str_starts_with($uri, '/api/')` (`$uri = '/api/pagos/reembolsar'`), forzando respuestas JSON en lugar de redirecciones o respuestas HTML tradicionales.
    Esto provocará el fallo del workflow en GitHub Actions (`.github/workflows/ci.yml`), donde la suite se ejecuta en un clon limpio sin caché.

#### C. Suite de Navegador Real E2E (Puppeteer)
- **Comando ejecutado:**
  ```powershell
  powershell -ExecutionPolicy Bypass -File tests/verificar_navegador.ps1
  ```
- **Resultado obtenido:**
  - **Identificador de ejecución independiente (`RUN_ID`):** `9f82b8d9cba04f4cbfd0c7a48d0692f9`
  - **19 comprobaciones E2E superadas (`[OK]`):**
    - `ADMIN-01` a `ADMIN-04`: CRUD administrativo de servicios con duración.
    - `PROF-01` y `PROF-02`: Gestión de profesionales, horarios, descansos y bloqueos.
    - `PRE-01`: Login interactivo de cliente y redirección a `/cita`.
    - `DISP-01`: Consulta autenticada en `GET /api/disponibilidad`.
    - `REC-01` a `REC-04`: Flujo interactivo en `/cita`, accesibilidad, filtrado de profesionales, resumen y reserva SweetAlert2.
    - `RES-01`: Reserva con profesional, descuento de ocupación, rechazo `409` por solapamiento y cancelación en `#mis-citas`.
    - `ADMIN-05`: Consulta administrativa en `/admin?fecha=2026-10-12`, eliminación en `/api/eliminar` y liberación de ocupación.
    - `REP-01`: Consulta de reportes administrativos en `/admin/reportes`, validación de KPIs globales, ocupación y exportación CSV.
    - `PAG-01`: Flujo completo de pagos con `MockPaymentGateway`: rechazo `422` ante datos de tarjeta, cálculo de importe en servidor ($195.00 ignorando $1.00 de cliente), bloqueo de confirmación por redirección del navegador en `/pago/resultado`, webhook con firma HMAC-SHA256, verificación de respuesta idempotente y actualización inmediata de `$195.00` pagado en `/admin/reportes`.
    - `AUTH-01`: Ciclo de autenticación, rechazo de usuario sin confirmar y recuperación de cuenta sin enumeración.
    - `NOTIF-01`: Despacho SMTP de correos transaccionales de confirmación y cancelación de citas en buzón de pruebas (8 correos capturados).
    - `REC-05`: `Errores consola: 0, Solicitudes fallidas: 0`.
  - **Persistencia verificada en MySQL (`appsalon_browser_test`):** Citas, servicios, horarios, notificaciones y pagos transaccionales validados.
  - **Código de salida:** `0`.
  - **Limpieza posterior:** Contenedores efímeros y `includes/.env` temporal eliminados; `git checkout -- tests/browser_test_report.json` ejecutado para mantener el worktree limpio.

---

### 4. Comparativa entre Reporte del Desarrollador y Verificación del Auditor

| Aspecto | Reportado por Desarrollador (`comprehensive_project_audit_roadmap`) | Verificado Independientemente por Auditor (`auditing_fase_4b_reservas`) | Coincidencia / Discrepancia |
| :--- | :--- | :--- | :--- |
| **SHA evaluado** | `a1de022faf087dfd609e6acb72b737f12e20c34a` | `a1de022faf087dfd609e6acb72b737f12e20c34a` | Exacta |
| **Versión PHPUnit / PHP** | PHPUnit `10.5.66` / PHP `8.2.34` | PHPUnit `10.5.66` / PHP `8.2.34` | Exacta |
| **Resultado PHPUnit** | `OK (172 tests, 2046 assertions)` (Exit `0`) | `FAILURES! Tests: 172, Assertions: 2031, Failures: 2, Risky: 1` (Exit `1` en ejecución limpia sin caché) | **Discrepancia crítica:** Fuga de superglobales en `BackupRestoreIntegrationTest.php` contamina `CitaModuloIntegrationTest`. |
| **Resultado Suite Navegador E2E** | 19 checks `[OK]` (`RUN_ID: 30c68b1df71949a2afca0130e3526507`), `0` errores consola, `0` fallos red (Exit `0`) | 19 checks `[OK]` (`RUN_ID: 9f82b8d9cba04f4cbfd0c7a48d0692f9`), `0` errores consola, `0` fallos red (Exit `0`) | Exacta |
| **Aislamiento respecto a app local** | `appsalon-web`, `appsalon-db`, `appsalon-net`, `appsalon_mvc` y `.env` intactos | `appsalon-web`, `appsalon-db`, `appsalon-net`, `appsalon_mvc`, `.env` y `stash@{0}` intactos | Exacta |

---

### 5. Conclusión y Veredicto Final de Cierre Técnico

- **Hallazgos bloqueantes detectados:**
  1. En `tests/Integration/BackupRestoreIntegrationTest.php`, la prueba `testAuditoriaIntegralDeCabecerasSeguridadRutasPermisosEIndices()` muta variables superglobales (`$_SERVER['REQUEST_METHOD'] = 'POST'`, `$_SERVER['REQUEST_URI'] = '/api/pagos/reembolsar'`, `$_SERVER['HTTP_ACCEPT'] = 'application/json'`) sin restablecerlas en un método `tearDown()` o bloque `try ... finally`.
  2. Debido a esta fuga de estado global, una ejecución limpia de PHPUnit (`vendor/bin/phpunit --colors=never`, exactamente como corre en GitHub Actions `.github/workflows/ci.yml`) falla con 2 errores y 1 prueba riesgosa en `CitaModuloIntegrationTest`.
- **Acción requerida del desarrollador:**
  - Agregar un método `tearDown()` o bloque `finally` en `tests/Integration/BackupRestoreIntegrationTest.php` que restablezca `$_SERVER['REQUEST_URI'] = '/'`, `$_SERVER['HTTP_ACCEPT'] = ''`, `$_SERVER['CONTENT_TYPE'] = ''`, `$_SERVER['REQUEST_METHOD'] = 'GET'`, `$_POST = []` y `$_SESSION = []`.
  - Re-ejecutar `vendor/bin/phpunit --do-not-cache-result --colors=never` para confirmar que los 172 tests y 2046 aserciones pasan limpiamente con Exit 0 sin depender de caché previa.
- **Veredicto Final Cierre Técnico (`a1de022faf087dfd609e6acb72b737f12e20c34a`):** **`CORRECCIONES NECESARIAS`**.

---

## Re-Auditoría de Cierre Técnico — Ronda 2 (`f33076c304e766239b1bfc13d9dde1927d749827`)

- **Fecha de Re-Auditoría:** 2026-10-10
- **Rol:** Subagente Auditor Independiente
- **Worktree Exclusivo de Auditoría:** `C:\Users\jonat\.gemini\antigravity\worktrees\AppSalon_PHP_MVC_JS_SASS\auditing_fase_4b_reservas`
- **SHA Auditado:** `f33076c304e766239b1bfc13d9dde1927d749827`
- **Commit Previo (Ronda 1 con correcciones necesarias):** `a1de022faf087dfd609e6acb72b737f12e20c34a`
- **Rama Asociada:** `feature/cierre-tecnico`
- **Integridad de Stash:** `stash@{0}: On auditing_fase_4b_reservas: temp stash for checkout` verificado y preservado intacto.

---

### 1. Verificación de Correcciones Aplicadas en la Ronda 2

El desarrollador atendió exhaustivamente el hallazgo bloqueante reportado en la Ronda 1 mediante los siguientes cambios:

1. **Restauración de superglobales en `tests/Integration/BackupRestoreIntegrationTest.php`:**
   - **Método `tearDown()` implementado (líneas 312-324):** Restablece explícitamente `$_SERVER['REQUEST_URI'] = '/'`, `$_SERVER['HTTP_ACCEPT'] = ''`, `$_SERVER['CONTENT_TYPE'] = ''`, `$_SERVER['REQUEST_METHOD'] = 'GET'`, `$_POST = []`, `$_GET = []`, `$_SESSION = []`, `PagoController::setPagoService(null)` y `PagoController::setRawJsonOverride(null)` tras cada prueba del caso.
   - **Bloque defensivo `try ... finally` (líneas 337-389):** En `testAuditoriaIntegralDeCabecerasSeguridadRutasPermisosEIndices()`, la mutación temporal de `$_SERVER` y `$_SESSION` para validar el rechazo 403 de `/api/pagos/reembolsar` está envuelta en un bloque `try ... finally` que garantiza la limpieza inmediata de las variables superglobales incluso ante fallos imprevistos.
2. **Desactivación explícita de caché de resultados en PHPUnit:**
   - En `composer.json` (línea 30): `"test": "phpunit --do-not-cache-result --colors=never"`.
   - En `.github/workflows/ci.yml` (línea 69): `run: php -d variables_order=EGPCS vendor/bin/phpunit --do-not-cache-result --colors=never`.
   - Garantiza que tanto los desarrolladores como el flujo de Integración Continua ejecuten siempre las pruebas desde un estado limpio y reproducible, sin depender de ordenamientos residuales de defectos pasados en `.phpunit.result.cache`.

---

### 2. Evidencia de Ejecución de Pruebas en Entorno Aislado

#### A. Suite Completa de Pruebas PHPUnit (Contenedor Aislado `appsalon-auditct-r2-db`)
- **Entorno de Auditoría:** Contenedor Docker aislado `appsalon-auditct-r2-db` (`mysql:8.0`) en red `appsalon-auditct-r2-net`, inicializado con `database/schema_base.sql` y migraciones `001` a `006` aplicadas mediante `migrador.php up`.
- **Comando ejecutado:**
  ```bash
  docker run --rm --network appsalon-auditct-r2-net -v "C:/Users/.../auditing_fase_4b_reservas:/app" -w /app \
    -e DB_HOST=appsalon-auditct-r2-db -e DB_PORT=3306 -e DB_USER=root -e DB_PASS=root -e DB_NAME=appsalon_audit_test \
    appsalon-php-test php -d variables_order=EGPCS -d date.timezone=America/Guayaquil vendor/bin/phpunit --do-not-cache-result --colors=never
  ```
- **Resultado obtenido (Ejecución Limpia sin Caché):**
  - **Versión:** PHPUnit `10.5.66` / PHP `8.2.34`.
  - **Total de pruebas:** `172 tests, 2046 assertions`.
  - **Estado:** `OK (172 tests, 2046 assertions)`.
  - **Fallos:** `0`.
  - **Pruebas riesgosas:** `0`.
  - **Código de salida:** `0`.
  - **Validación del fix:** Las pruebas `CitaModuloIntegrationTest::testAdminControllerExigeRolAdministrador` (302 redirect a `/`) y `CitaModuloIntegrationTest::testApiEliminarManejaFalloSqlEnModalidadesHtmlYJsonConRollbackSinRedireccion302` (error 500 HTML) pasaron de forma 100% limpia sin interferencias de `es_peticion_json()`.

#### B. Suite de Navegador Real E2E (Puppeteer)
- **Comando ejecutado:**
  ```powershell
  powershell -ExecutionPolicy Bypass -File tests/verificar_navegador.ps1
  ```
- **Resultado obtenido:**
  - **Identificador de ejecución independiente (`RUN_ID`):** `c092a389eb3e480cb2659aa15529d4d6`
  - **19 comprobaciones E2E superadas (`[OK]`):**
    - `ADMIN-01` a `ADMIN-04`: CRUD administrativo de servicios con duración.
    - `PROF-01` y `PROF-02`: Gestión de profesionales, horarios, descansos y bloqueos.
    - `PRE-01`: Login interactivo de cliente y redirección a `/cita`.
    - `DISP-01`: Consulta autenticada en `GET /api/disponibilidad`.
    - `REC-01` a `REC-04`: Flujo interactivo en `/cita`, accesibilidad, filtrado de profesionales, resumen y reserva SweetAlert2.
    - `RES-01`: Reserva con profesional, descuento de ocupación, rechazo `409` por solapamiento y cancelación en `#mis-citas`.
    - `ADMIN-05`: Consulta administrativa en `/admin?fecha=2026-10-12`, eliminación en `/api/eliminar` y liberación de ocupación.
    - `REP-01`: Consulta de reportes administrativos en `/admin/reportes`, validación de KPIs globales, ocupación y exportación CSV.
    - `PAG-01`: Flujo completo de pagos con `MockPaymentGateway`: rechazo `422` ante datos de tarjeta, cálculo de importe en servidor ($195.00 ignorando $1.00 cliente), bloqueo de confirmación por redirección del navegador en `/pago/resultado`, webhook con firma HMAC-SHA256, verificación de respuesta idempotente y actualización inmediata de `$195.00` pagado en `/admin/reportes`.
    - `AUTH-01`: Ciclo de autenticación, rechazo de usuario sin confirmar y recuperación de cuenta sin enumeración.
    - `NOTIF-01`: Despacho SMTP de correos transaccionales de confirmación y cancelación de citas en buzón de pruebas (8 correos capturados).
    - `REC-05`: `Errores consola: 0, Solicitudes fallidas: 0`.
  - **Persistencia verificada en MySQL (`appsalon_browser_test`):** Pago transaccional verificado: `cita_referencia_id = 3`, `estado = 'completado'`, `monto = 195.00 USD`, `citas.estado_pago = 'pagado'`.
  - **Código de salida:** `0`.
  - **Limpieza posterior:** Contenedores efímeros y `includes/.env` temporal eliminados; `git checkout -- tests/browser_test_report.json` ejecutado para mantener el worktree limpio.

---

### 3. Comparativa entre Reporte del Desarrollador y Verificación del Auditor (Ronda 2)

| Aspecto | Reportado por Desarrollador (`comprehensive_project_audit_roadmap`) | Verificado Independientemente por Auditor (`auditing_fase_4b_reservas`) | Coincidencia |
| :--- | :--- | :--- | :--- |
| **SHA evaluado** | `f33076c304e766239b1bfc13d9dde1927d749827` | `f33076c304e766239b1bfc13d9dde1927d749827` | Exacta |
| **Versión PHPUnit / PHP** | PHPUnit `10.5.66` / PHP `8.2.34` | PHPUnit `10.5.66` / PHP `8.2.34` | Exacta |
| **Resultado PHPUnit (--do-not-cache-result)** | `OK (172 tests, 2046 assertions)` (Exit `0`) | `OK (172 tests, 2046 assertions)` (Exit `0`) | Exacta |
| **Resultado Suite Navegador E2E** | 19 checks `[OK]` (`RUN_ID: 3d118172c3e6461b9b07a841bf7e5d5b`), `0` errores consola, `0` fallos red (Exit `0`) | 19 checks `[OK]` (`RUN_ID: c092a389eb3e480cb2659aa15529d4d6`), `0` errores consola, `0` fallos red (Exit `0`) | Exacta |
| **Aislamiento respecto a app local** | `appsalon-web`, `appsalon-db`, `appsalon-net`, `appsalon_mvc` y `.env` intactos | `appsalon-web`, `appsalon-db`, `appsalon-net`, `appsalon_mvc`, `.env` y `stash@{0}` intactos | Exacta |

---

### 4. Conclusión y Veredicto Final de Cierre Técnico (Ronda 2)

- **Pendientes bloqueantes:** Ninguno. Todos los 8 criterios del Cierre Técnico (seguridad y cabeceras HTTP, scripts estandarizados de Composer y frontend, consistencia referencial e índices en migraciones 001 a 006, CI reproducible en GitHub Actions con `--do-not-cache-result`, Docker Compose parametrizado, scripts CLI de backup y restore transaccionales con Gzip/SHA-256, guía de operaciones completa en `docs/GUIA_MIGRACION_OPERACIONES.md` y suites automatizadas con 172 tests PHPUnit y 19 comprobaciones Puppeteer E2E superadas) han sido verificados de forma independiente y exhaustiva.
- **Veredicto Final Cierre Técnico (`f33076c304e766239b1bfc13d9dde1927d749827`):** **`APROBADO`**.
- **Siguiente paso autorizado:** Integración final de la rama `feature/cierre-tecnico` hacia `main` y cierre del proyecto.




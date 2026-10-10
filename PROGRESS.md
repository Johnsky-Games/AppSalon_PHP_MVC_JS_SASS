# Registro de Progreso del Proyecto AppSalon

Este archivo mantiene la trazabilidad estricta del avance del proyecto de acuerdo con la metodología de entregas auditables por **ChatGPT** (revisión estática de código) y ejecución/verificación por **Antigravity** (desarrollo y pruebas dinámicas automatizadas), sujeto a la aprobación final del **Propietario**.

> **Roles y Criterios:**
> - **Desarrollo y Pruebas Automatizadas (Antigravity):** Implementación de código y ejecución de la suite completa de pruebas unitarias e integrales (124 pruebas, 1216 aserciones en PHPUnit 10.5.66), escenarios funcionales HTTP y suite E2E en navegador real Headless Chrome (`tests/verificar_navegador.ps1` y `tests/browser_e2e_test.js`).
> - **Revisión Estática Externa (ChatGPT):** Auditoría independiente de código de aplicación, arquitectura por capas, contratos transaccionales y revisión estática de scripts Bash.
> - **Aprobación Final y Despliegue (Propietario):** Decisión formal sobre fusiones hacia `main` y despliegues en producción.

---

## Fase 4A: Cálculo de Disponibilidad por Profesional

- **Rama:** `feature/fase-4a-disponibilidad`
- **Commit Base (Fase 3A):** `8ad121ea1b02c6ff51e0eb3891207e50bb383218` (corrección documental sobre `25c7e23e56cb28036d72f7e6feb17dad7492a39c`)
- **Commit Previo Auditado (Fase 4A):** `7ecbc87c0dfdee096fdb7b42ce009b160244328b`
- **Estado General de Fase 4A:** **Completada (Pendiente de Revisión Externa)**

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
| **Fase 4A** | Cálculo de disponibilidad por profesional según agenda (`DisponibilidadService`, `GET /api/disponibilidad`) | `Completada (En Revisión)` |
| **Fase 4B** | Integración de disponibilidad con citas existentes, bloqueo transaccional y prevención de reservas simultáneas | `Pendiente` |
| **Fase 5** | Interfaz accesible de reservas y panel administrativo | `Pendiente` |
| **Fase 6** | Pagos (Stripe / Mercado Pago), notificaciones multicanal y reportes | `Pendiente` |


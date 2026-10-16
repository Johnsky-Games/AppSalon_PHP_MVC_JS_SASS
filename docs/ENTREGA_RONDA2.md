# Entrega ronda 2 — cierre de QA, correo, equipo y CRM

Rama `claude/blissful-hopper-f5k7a0` (sin merge a `main`, sin despliegue). Parte de `ddade89` (CI satisfactorio de la ronda 1).
El SHA final se informa en el mensaje de entrega (un commit de documentación posterior no puede citar su propio SHA). Los resultados de §2 corresponden al código de `a72a041`; los commits siguientes solo tocan documentación y su CI se verifica aparte.

## 1. Qué se hizo, por responsable

Cada agente trabajó en su propio *worktree* con su base de datos y puertos, con propiedad de archivos y contratos explícitos; el líder integró y reverificó.

| Responsable | Entregó | Principales archivos |
|---|---|---|
| **Líder (integración)** | Suite `tests/Qa` como paso obligatorio del CI con verificación de omitidas; contrato de la cola de correo; cola también para recuperación; correcciones de los hallazgos R2/R2B; guía Windows/Docker; matriz. | `.github/workflows/ci.yml`, `scripts/ci_verificar_omitidas.php`, `.github/qa_omitidas_permitidas.txt`, `services/CorreoSalidaService.php`, `docs/GUIA_ACTUALIZACION_RONDA2.md` |
| **identidad** | Registro público con respuesta **uniforme** (DEF-13); migrador `down` con bloqueo, guardas y respaldo verificable; resguardo de suspensiones (DEF-16); `login` con hash señuelo. | `services/AuthService.php`, `database/migrador.php`, `database/migrador_reversion.php`, `database/migrations/016/017/018 *.guard.php` |
| **correo** | Confirmaciones de cliente en cola persistente con reintentos, cancelación de enlaces obsoletos, `procesar_correos.php --estado/--reintentar`. | `repositories/CorreoSalidaRepository.php`, `services/CorreoSalidaService.php`, `scripts/procesar_correos.php`, migración 019 |
| **agenda** | Servicios y horarios en el asistente del profesional; reasignación asistida de citas antes de la baja; aviso de homónimos de profesionales. | `services/ReasignacionCitasService.php`, `services/AgendaSemanalValidador.php`, `views/equipo/*`, migración 021 |
| **crm** | Auditoría de exportaciones (solo anexado), versión del consentimiento, alta de cliente con aviso de homónimos. | `services/CrmAuditoriaService.php`, `repositories/ConsentimientoRepository.php`, `services/HomonimosService.php`, migración 022 |
| **qa / qa2 (independientes)** | Matriz de autorización exacta (86 rutas × 19 modalidades), pruebas de la cola, de la reversión, del registro, del asistente y del CRM; reverificación sin tocar código de la aplicación. | `tests/Qa/**`, `docs/QA_INDEPENDIENTE_IDENTIDADES.md` |

## 2. Resultados (separados, sin mezclar)

Salida literal. Los tres bloques son suites distintas y no se suman:

* **PHPUnit por defecto** (`tests/Unit` + `tests/Integration`): local (PHP 8.3.6, MySQL 8.0.46, orden aleatorio, semilla 1791934319): `OK (674 tests, 10173 assertions)`; en el CI (PHP 8.2, orden aleatorio) el paso pasó en 166 s.
* **QA independiente** (`tests/Qa`): local: `OK (595 tests, 5986 assertions)` en 13 min 27 s (807 s), sin fallos ni omitidas; en el CI (PHP 8.2, Chromium del runner, `QA_EXIGIR_NAVEGADOR=1`) el paso pasó en 591 s. La primera ejecución en el CI (run 38481255271) falló con 41 fallos: 40 eran una incompatibilidad del arnés con PHP 8.2 (`proc_close()` devuelve -1 tras `proc_get_status()`), corregida por el QA; el otro era una expectativa de documentación ya cambiada. No hubo ningún defecto de la aplicación exclusivo de 8.2.
* **E2E de navegador** (Apache real en CI; en local `php -S`): CI sobre Apache real + MySQL + SMTP simulado: paso «Recorrido E2E aislado» pasó (217 s). En local, el QA ejecutó las 8 etapas del E2E con `php -S` y un sustituto de `docker` (código 0) sobre un SHA anterior (`461dbf8`); **no se repitió en local sobre el SHA final** (no hay Docker aquí).
* **Pruebas omitidas:** 0 en `tests/Qa` y 0 en la suite por defecto. La lista permitida `.github/qa_omitidas_permitidas.txt` está vacía.
* **CI del mismo SHA:** [run 38485211769](https://github.com/Johnsky-Games/AppSalon_PHP_MVC_JS_SASS/actions/runs/38485211769) sobre `a72a041`: **success** (migraciones, instalador del propietario, PHPUnit, QA, imagen Docker, E2E en Apache, respaldo/restauración con SHA-256).

## 3. Cómo se resolvió cada punto pedido

1. **Suite `tests/Qa` obligatoria en CI.** Paso propio con base `appsalon_qa_test` aislada (el arnés rechaza cualquier otra), Chromium del runner (`CHROME_BIN`, `QA_EXIGIR_NAVEGADOR=1` hace **fallar**, no omitir, si falta), informe JUnit como artefacto y `scripts/ci_verificar_omitidas.php`: toda prueba omitida que no esté en `.github/qa_omitidas_permitidas.txt` rompe el CI (hoy la lista permitida está vacía). Se conservan las pruebas de concurrencia real (procesos PHP) DEF-02 y DEF-06 y se añadieron muchas más; la duración (≈12 min) no justificó excluirlas.
2. **DEF-13 y DEF-16 ya no figuran como «riesgo aceptado»** (nadie los aceptó). Se registraron como abiertos, se corrigieron y se **reverificaron** de forma independiente:
   * Registro: misma respuesta pública (status, `Location`, cuerpo, cabeceras, alertas) para correo nuevo, cliente confirmado, sin confirmar, personal laboral y suspendido; efectos distintos solo en el servidor. El correo ya no se envía dentro de la petición para que el tiempo de respuesta no delate la cuenta (también `/olvide` y `/reenviar-confirmacion`).
   * Reversión: `down` se niega (código 2, sin tocar nada) si destruiría datos, salvo `--aceptar-perdida=<migración>` **y** respaldo verificado (SHA-256, misma base, ≤ 24 h); la 016 conserva las suspensiones en `clientes_resguardo_016` y el `up` las restaura; la 022 se niega siempre mientras haya evidencia de negocio. Procedimiento exacto y probado en `docs/GUIA_ACTUALIZACION_RONDA2.md` §7.
3. **`AutorizacionHttpTest` y matriz exacta.** Se sustituyeron las aserciones laxas por status, `Location`, cuerpo/JSON, cabeceras, ausencia de datos privados (centinelas únicos) y ausencia de efectos (huella de 32 tablas + buzón). 3 defectos reales encontrados y corregidos (DEF-R2-01 PII en reportes de finanzas, DEF-R2-02 callejón en `/api/eliminar`, DEF-R2-03 redirección abierta por `Referer`).
4. **Cola persistente de correos.** Confirmación, recuperación y avisos por `correos_salida`; SMTP caído/colgado/rechazos, recuperación con el worker real, reenvíos concurrentes (procesos reales), worker muerto con *lease* y dos workers probados. **No se promete entrega «exactamente una vez»**: es *al menos una vez* y el duplicado posible está probado y documentado.
5. **Pendientes operativos.** Servicios y horarios en el asistente; reasignación asistida con validación de disponibilidad real, historial (`citas_reasignaciones`) y bloqueo si hay comisión devengada; auditoría de exportaciones CRM (solo anexado, sin PII); versión del consentimiento; aviso de posibles homónimos en el alta de clientes y de profesionales (nunca fusiona ni bloquea; la decisión queda auditada).
6. **Matriz de aceptación** actualizada con estados *implementado / probado / pendiente / riesgo abierto*: `docs/MATRIZ_ACEPTACION_NEGOCIO.md` §3.

## 4. Pruebas omitidas y su motivo

Ninguna. Las dos omisiones de la ronda 1 (`TokensTest` DEF-13 y `EsquemaTest` DEF-16, que figuraban como «riesgo aceptado») se eliminaron: ahora se ejecutan contra la corrección. Las pruebas de navegador solo se omitirían sin Chromium y sin `QA_EXIGIR_NAVEGADOR=1`; el CI fija esa variable y, sin Chromium, **fallan**.

## 5. Pendientes y riesgos que siguen abiertos (sin ocultar)

* **Toma de cuenta por pre-registro (corregido en la ronda 3; ya no figura como riesgo abierto).** Antes se describía como «inherente al alta con confirmación por correo»; no lo era: la cuenta sin confirmar conservaba la contraseña de quien la pre-registró y el titular, al activar, la dejaba utilizable por el atacante. Ahora la activación (`POST /confirmar-cuenta`) exige elegir una contraseña definitiva que reemplaza a la provisional de forma atómica, y `GET`/`HEAD` solo validan el enlace. Cubierto por `ActivacionCuentaIntegrationTest` (suite por defecto) y reproducido por el QA independiente (R3).

Ver la matriz (columnas *Pendiente* y *Riesgo abierto*). Lo más relevante:

* **Hallazgos abiertos del QA al cierre:** ninguno de la lista R2B-01…13 (los últimos, R2B-03c y R2B-13, se corrigieron en `a72a041` y los cubre la suite que pasó). El QA no volvió a revisar esas dos últimas correcciones más allá de sus propias pruebas.
* **No verificado aquí:** PHP 8.2 y Apache/Docker (solo el CI los ejercita); SMTP real (prohibido enviar correo real); zona horaria real (el desfase se simuló).
* **Entrega de correo «al menos una vez»**: posible duplicado; un evento llega a `fallido` a los ≈31 min acumulados y requiere `--reintentar`; con el worker detenido no sale ningún correo.
* **Tiempo constante exacto** del registro/login no se garantiza (se mide un cociente < 2 con SMTP lento, no igualdad).
* **Invitaciones de colaboradores y avisos de cita** siguen intentando el envío dentro de la petición del usuario autenticado (documentado; no es oráculo de enumeración).
* **Auditoría «solo anexado»** lo es frente a la aplicación; un DBA con `TRUNCATE`/`DROP` puede borrarla (mitigación `REVOKE` documentada).
* Fuera de alcance, sin cambio: pasarela real, normativa laboral de Ecuador, WhatsApp/SMS, despliegue público.

## 6. Guía de actualización

Windows (Git Bash / PowerShell), Docker Compose y Linux: `docs/GUIA_ACTUALIZACION_RONDA2.md`. Complementa `docs/GUIA_ACTUALIZACION_IDENTIDADES.md`.

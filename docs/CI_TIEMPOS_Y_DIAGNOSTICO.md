# CI: tiempos máximos por etapa y diagnóstico de cuelgues

Workflow `.github/workflows/ci.yml`, job `tests`.

## Por qué
El 2026-10-23 el run 409 (PR #48, `3393a39`) estuvo **3 h 56 min** en el paso de PHPUnit (de 00:34 a 04:30 UTC) sin avanzar, hasta que se canceló a mano. El único tope era el de 6 h por defecto de GitHub y el paso no dejaba ningún registro de qué prueba estaba en ejecución, por lo que **la causa no se pudo determinar**. No hay evidencia para atribuirla a la infraestructura ni a una prueba concreta: no se conserva registro por prueba y el log del paso no estaba disponible mientras corría. La cabeza siguiente del PR (`73175f3`) pasó en 77 min, pero con código distinto, así que no prueba nada sobre la causa. Este cambio no pretende explicarla, sino acotar su costo y dejar evidencia si vuelve a ocurrir.

## Duraciones observadas (2026-10-23)
Corridas rápidas: #48 `73175f3`, #49 `e6117a6`, #50 `5ebae83`. Corrida lenta: #51 `e2ee7b8` (mismo tipo de código; el runner y la red fueron más lentos). La corrida lenta es la que obligó a recalibrar: **mi primer cálculo (topes de ~2 veces las corridas rápidas) era demasiado estrecho**; el paso de lectores XLSX falló por su propio tope de 25 min y PHPUnit tardó 60 min en vez de 33–38. La causa de esa lentitud no se determinó (el log de ese paso no quedó accesible y no hay evidencia para atribuirla a la red, al runner ni al código).

| Etapa | Rápidas | Lenta | Tope (`timeout-minutes`) |
|---|---|---|---|
| Checkout, PHP, Node | 1–4 s | 1–3 s | 5 |
| Composer / NPM + build | 3–7 s | 3–5 s | 10 |
| `php -l` | 20–30 s | 22 s | 5 |
| Migraciones | 3–5 s | 12 s | 10 |
| Instalador del propietario | 4–6 s | 8 s | 5 |
| Imagen Docker | 1,5 min | 1 min | 15 |
| E2E en Apache | 5–7,5 min | 5,4 min | 20 |
| **PHPUnit (suite completa)** | **33–38 min** | **60 min** | **100** (límite del script, 6000 s) / 105 (paso) |
| Lectores XLSX (openpyxl + LibreOffice) | 22 s – 12 min | **> 25 min** (falló por su tope) | 45, con reintentos y tiempos de red de apt |
| **QA independiente** | **33–37 min** | no llegó a ejecutarse | **100** (límite del script) / 105 (paso) |
| Respaldo y restauración | 6–8 s | no llegó | 15 |
| Diagnóstico (solo en fallo/cancelación) | — | < 1 s | 5 |
| **Job completo** | **76–95 min** | > 93 min sin terminar | **240** |

Criterio: ~1,7 veces lo más lento observado en PHPUnit y QA (el run 409 estuvo 236 min colgado: 100 min sigue ahorrando más de 2 h); el job, ~1,5 veces la suma de lo más lento. Si la suite crece o el runner sigue lento, hay que revisar esta tabla con datos, no por intuición.

## Qué se conserva al fallar o cancelarse
* **Captura ANTES de terminar la suite** (`scripts/ci_con_limite.sh`): PHPUnit y el QA se ejecutan con un límite de 6000 s (100 min). Si se supera, el script ejecuta `scripts/ci_diagnostico.sh` **con la suite todavía viva** (procesos con su antigüedad, `SHOW FULL PROCESSLIST`, `data_lock_waits`, estado de InnoDB, memoria, contenedores, log de MySQL, `dmesg`) y solo después termina el grupo de procesos (TERM y, a los 20 s, KILL) y sale con 124. Esto es necesario porque, cuando el ejecutor agota `timeout-minutes` de un paso, mata su árbol de procesos y las consultas y bloqueos ya no existen cuando corre un paso posterior. `timeout-minutes: 105` del paso queda solo como red de seguridad. La captura sale en el log del propio paso.
* **Registro por prueba** (`--log-teamcity`, se escribe prueba a prueba, de unos pocos MB): artefactos `phpunit-eventos` y `qa-eventos`. La última línea `testStarted` sin su `testFinished` es la prueba en curso. El informe JUnit solo se escribe al terminar la suite, por lo que no sirve si el proceso se cuelga.
* **Paso «Diagnóstico del entorno»** (`failure() || cancelled()`, no bloqueante, mismo script): cubre el fallo o la cancelación de cualquier paso (runner, MySQL, contenedores, OOM). No sustituye la captura anterior para un cuelgue de la suite.
* El tope del job (240 min) queda como último recurso.

## Cómo leerlo ante un nuevo cuelgue
1. ¿Qué paso expiró? (el que dice «timed out»).
2. Artefacto `phpunit-eventos` o `qa-eventos`: última prueba iniciada sin terminar.
3. En el log de ese paso, bajo «Tiempo agotado»: procesos de prueba vivos, qué consulta tiene MySQL en curso y si hay esperas por bloqueo, antes de concluir si es la prueba, la base o el runner.

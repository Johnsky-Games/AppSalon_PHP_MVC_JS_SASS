# CI: tiempos máximos por etapa y diagnóstico de cuelgues

Workflow `.github/workflows/ci.yml`, job `tests`.

## Por qué
El 2026-10-23 el run 409 (PR #48, `3393a39`) estuvo **3 h 56 min** en el paso de PHPUnit (de 00:34 a 04:30 UTC) sin avanzar, hasta que se canceló a mano. El único tope era el de 6 h por defecto de GitHub y el paso no dejaba ningún registro de qué prueba estaba en ejecución, por lo que **la causa no se pudo determinar**. No hay evidencia para atribuirla a la infraestructura (ni a una prueba concreta): esa corrida se repitió sobre el mismo código y terminó en verde. Este cambio no pretende explicarla, sino acotar su costo y dejar evidencia si vuelve a ocurrir.

## Duraciones observadas (corridas en verde del 2026-10-23: #48 `73175f3`, #49 `e6117a6`, #50 `5ebae83`)

| Etapa | Observado | Tope (`timeout-minutes`) |
|---|---|---|
| Checkout, PHP, Node | 1–4 s | 5 |
| Composer / NPM + build | 3–7 s | 10 |
| `php -l` | 20–30 s | 5 |
| Migraciones | 3–5 s | 10 |
| Instalador del propietario | 4–6 s | 5 |
| Imagen Docker | 1,5 min | 15 |
| E2E en Apache | 5–7,5 min | 20 |
| **PHPUnit (suite completa)** | **33–38 min** | **75** |
| Lectores XLSX (openpyxl + LibreOffice) | 22 s – 12 min | 25 |
| **QA independiente** | **33–37 min** | **75** |
| Respaldo y restauración | 6–8 s | 15 |
| Diagnóstico (solo en fallo/cancelación) | — | 5 |
| **Job completo** | **76–95 min** | **180** |

Criterio: las etapas largas (PHPUnit y QA) tienen un tope de ~2 veces el máximo observado; las cortas, un margen de 10 o más veces porque su costo de esperar es bajo. El job tiene ~1,9 veces la corrida más larga. Si la suite crece de forma sostenida, hay que revisar esta tabla.

## Qué se conserva al fallar o cancelarse
* **Registro por prueba** (`--log-teamcity`, se escribe prueba a prueba, de unos pocos MB): artefactos `phpunit-eventos` y `qa-eventos`. La última línea `testStarted` sin su `testFinished` es la prueba en curso al momento del fallo. El informe JUnit sigue existiendo, pero solo se escribe al terminar la suite, por lo que no sirve si el proceso se cuelga.
* **Paso «Diagnóstico del entorno»** (`failure() || cancelled()`, no bloqueante): procesos con su antigüedad, memoria y disco, conexiones y bloqueos de MySQL (`SHOW FULL PROCESSLIST`, `data_lock_waits`, estado de InnoDB), contenedores, registro del servicio MySQL y el final de `dmesg` (OOM).
* Los topes por etapa hacen que el paso falle y el job siga hasta el diagnóstico; el tope del job queda como último recurso.

## Cómo leerlo ante un nuevo cuelgue
1. ¿Qué paso expiró? (el que dice «timed out»).
2. Artefacto `phpunit-eventos` o `qa-eventos`: última prueba iniciada sin terminar.
3. En «Diagnóstico»: si hay procesos de prueba vivos, qué consulta tiene MySQL en curso y si hay esperas por bloqueo, antes de concluir si es la prueba, la base o el runner.

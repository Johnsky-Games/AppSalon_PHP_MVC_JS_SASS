# Entrega ronda 5 — agenda → atención → finalización → cobro → comprobante, portales separados, segmentación y exportaciones

Rama de origen `claude/blissful-hopper-f5k7a0`; integración en `main` mediante [PR #1](https://github.com/Johnsky-Games/AppSalon_PHP_MVC_JS_SASS/pull/1). Parte de `3c71d3d`. El SHA validado y el CI figuran en §8. Alcance y procedimiento de actualización: [INTEGRACION_MAIN_R5B.md](INTEGRACION_MAIN_R5B.md). La instalación del usuario no se ha desplegado ni migrado.

## 0. Estado honesto — leer primero

* **Cierre técnico R5b validado:** código y pruebas `39c4fc5`; resultados actuales en §4/§8. Las cifras de `6b9ae6f` corresponden a la entrega anterior a la regularización.

* **Tu equipo NO está validado.** Todo se hizo y se probó en la nube (Linux, sin tus contenedores, `.env`, volúmenes ni respaldo). `docs/IDENTIFICAR_ENTORNO_LOCAL.md` y `docs/GUIA_ACTUALIZACION_RONDA5.md` dan los comandos para tu entorno; la validación en tu computadora queda fuera de esta fase.
* **Decisión tomada por ti — citas de días anteriores:** no hay inicio ordinario de una cita de un día anterior; se **reprograma** (con las validaciones habituales) o el propietario/gerente la **regulariza** (motivo + fecha/hora efectiva, nunca futura; quién y cuándo se registran aparte), el profesional puede **solicitarlo** pero no aprobarlo, y las citas antiguas sin resolver quedan **pendientes de revisión** sin marcarse solas. Detalle, efecto en reportes y comisiones y pruebas: `docs/R5_REGULARIZACION.md` (migración 027).
* **Otras decisiones de negocio tomadas (revísalas):** margen de 60 minutos para iniciar antes de la hora; «Finalizar y cobrar» solo para quien tiene permiso de agenda **y** de finanzas (propietario y gerente), recepción no cobra; quien registra una transferencia no la verifica (salvo el propietario); «suscripción» del CRM = suscripción a comunicaciones comerciales (no existe suscripción de pago); el comprobante es interno.
* **Enlaces de correos y notificaciones:** auditados (inventario, corrección del enlace de sitio en correos del equipo y pruebas de contenido y HTTP), ver `docs/R5_MATRIZ_DESTINOS.md` §7.
* **No hecho / pendiente real:** factura fiscal electrónica (no existe integración); adjuntar comprobante de transferencia; permiso propio para que recepción cobre; algunos informes finales de los agentes de desarrollo no se recibieron por la misma limitación (el trabajo está en sus commits y se integró y probó).

## 1. Causa raíz de cada fallo observado (reproducida con HTTP real)

| Fallo | Causa raíz |
|---|---|
| A «Iniciar atención» lleva a una página inesperada | La agenda ofrecía el botón en **toda** cita confirmada, también futuras; el servidor respondía 422 «La atención todavía no ha comenzado» y el controlador lo pintaba como HTML crudo sin diseño; en el caso exitoso se perdía el período consultado |
| B «Volver a las citas» abre `/mis-citas` con «Reservar una cita» | Enlace fijo a `/mis-citas` en esa página de error, y `GET /mis-citas` no comprobaba el portal (una sesión de equipo recibía la pantalla del cliente); además el «inicio» de los errores usaba `$_SESSION['rol']`, que el login de equipo no escribe |
| C Sin ruta para atender y cobrar | El cobro de una cita solo existía tecleando su ID en Finanzas; cobro siempre «completo y de una vez», sin saldo, verificación de transferencia ni comprobante |
| D Faltan filtros de clientes | No había filtros de cuenta, membresía ni consentimiento, ni chips, ni «Limpiar filtros» |
| E «Listado» sin lista útil | La columna no abría la lista del segmento ni ofrecía descarga |
| F Exportaciones | Solo CSV, armado por cada sección; ningún XLSX real |

## 2. Qué se implementó

* **Atención** (`ReglaAtencion`, `CitaRepository::mutarAgenda`, migraciones 023 y 027, `GET /agenda/cita`, `GET /agenda/pendientes`): una sola regla para vista y servidor; transiciones confirmada→en_servicio→completada y confirmada→no_asistio, terminales inmutables, atómicas, idempotentes y auditadas (actor, fecha y hora, estado anterior y nuevo); detalle de la cita; acciones por permiso del servidor.
* **Portales y destinos** (`includes/destinos.php`, `includes/portales.php`): catálogo central; guardias de portal; errores de operación dentro del layout con «Volver» correcto; navegación por portal; anónimo en pantallas del equipo → `/equipo/login`. Matriz en `docs/R5_MATRIZ_DESTINOS.md`.
* **Cobro, caja y comprobante** (migraciones 024 y 025): ver `docs/CAJA_Y_COBROS.md`. Incluye corrección de comisiones con pagos parciales (redondeo acumulado).
* **CRM** (`docs/R5_CRM_FILTROS.md`, `docs/R5_INVENTARIO_LISTADOS.md`): filtros de cuenta, membresía, comunicaciones comerciales (versión y fecha), actividad, fechas, profesional, frecuencia y gasto; chips y «Limpiar filtros»; lista = indicadores = exportación; «Listado» abre la lista real, paginada, con descarga.
* **Exportaciones** (`classes/Exportacion`, migración 026): fachada única CSV/XLSX; XLSX real en flujo **sin dependencias nuevas** (el contenedor no instala `ext-zip`); fórmulas neutralizadas, importes desde centavos, celdas recortadas al límite de Excel, tope de filas con aviso, autorización y auditoría previas a la entrega.

## 3. Migraciones

023 `atencion_citas`, 024 `cobros_verificacion`, 025 `comprobantes`, 026 `exportaciones_auditoria_general`, 027 `regularizacion_atencion` (regularización de citas de días anteriores): con guardián y reversión segura (se niegan si perderían datos de negocio). Tablas del esquema migrado: 42 → 50.

## 4. Resultados por suite (sin sumar)

| Ejecución sobre `39c4fc5` | Pruebas | Resultado |
|---|---:|---|
| PHPUnit local, semilla `1792111571` (la que había dado 89 errores) | 1125 | OK; 0 omitidas |
| PHPUnit local, semilla nueva `1792127001` | 1125 | OK; 0 omitidas |
| QA local completo (`tests/Qa`) | 958 | 0 fallos y 0 errores; 36 omitidas por navegador local no disponible |
| PHPUnit en CI (PHP 8.2, orden aleatorio) | 1125 | OK; semilla `1792117420` |
| QA en CI (MySQL 8.0, Chromium, openpyxl y LibreOffice) | 958 | OK; **0 omitidas** |
| Mutaciones en árbol separado | 30 | **30 detectadas** por fallos de aserción; fuentes restauradas |
| Integridad en base nueva migrada | 11 consultas / 33 FK | Todas las consultas dan 0; FK contrastadas con `information_schema` |

[CI run 79 — éxito completo](https://github.com/Johnsky-Games/AppSalon_PHP_MVC_JS_SASS/actions/runs/38840115357). También pasó compilación frontend, sintaxis PHP, instalación idempotente, imagen Docker, E2E en Apache con SMTP simulado y respaldo/restauración con SHA-256.

La ejecución local se hizo en Linux con PHP 8.3.6 y MySQL 8.0.46. Sus omisiones de navegador se declaran; la validación completa del navegador corresponde al CI, que exige `QA_EXIGIR_NAVEGADOR=1` y **no permite omisiones**. Evidencia por SHA, comandos, semillas, fechas y casos omitidos: [validacion.json](evidencia_r5/validacion.json), [mutaciones.json](evidencia_r5/mutaciones.json) e [integridad.json](evidencia_r5/integridad.json). No se suman suites ni repeticiones.

## 5. Ejemplos ficticios

`docs/ejemplos_r5/`: `comprobante_ejemplo.html` (comprobante interno CI-000077), `caja_ejemplo.csv`, `caja_ejemplo.xlsx`. Generados por el propio código con datos de prueba inventados.

## 6. Hallazgos del QA independiente y ajustes a pruebas

Ver `docs/R5_QA_INDEPENDIENTE.md` (hallazgos reales corregidos, tabla de pruebas ajustadas con su motivo y cómo se demuestra «un solo envío real» con botón en proceso).

## 7. Riesgos que quedan

* La prueba de arquitectura detecta SQL literal en PHP; no SQL dinámico ni sentencias fuera de PHP. Las rondas regularizar contra cobrar/rechazar siguen siendo cobertura limitada: no discriminan las mutaciones ensayadas. No se presentan como evidencia de esos bloqueos.
* Anonimización de usuarios y baja lógica de servicios del catálogo: decisiones de negocio pendientes, fuera del cierre autorizado; ver `docs/R5_REGULARIZACION.md` §7.

* Las pruebas de Chromium dependen del navegador del runner; se endureció la captura del estado, pero conviene vigilar su estabilidad.
* La verificación de transferencias es manual (sin conciliación con extractos bancarios reales).
* Una exportación grande se genera en un archivo temporal antes de entregarse (memoria acotada, disco temporal).
* No se ejecutó nada contra una instalación real con datos del usuario.

## 8. Rama, SHA y CI

Rama de origen `claude/blissful-hopper-f5k7a0`. El [PR #1](https://github.com/Johnsky-Games/AppSalon_PHP_MVC_JS_SASS/pull/1) registra la revisión integrada y el SHA del merge en `main`; la evidencia siguiente corresponde al cierre técnico anterior a la integración.

| SHA | Qué es | Resultado real |
|---|---|---|
| `89952f0` | Primer CI con lectores XLSX | Pasó PHPUnit y el QA de navegador; no validó los cambios posteriores |
| `9ec7eaf` | Auditoría de enlaces de correo | CI **falló** en PHPUnit: interbloqueo real en cobros simultáneos de citas distintas (bloqueos de hueco en `idx_pagos_cita_id`) |
| `b2aaef9` | Corrección (`READ COMMITTED` en las operaciones de caja) | Local: QA 887 OK, 0 omitidas |
| `33605ff` | Ajuste de dos pruebas de concurrencia | CI **falló** solo en QA: «Sin puertos HTTP libres» (las clases QA antiguas y las R5 competían por 8150-8159 con orden aleatorio); PHPUnit completo pasó |
| `3d6152f` | Puertos R5 de 8199 hacia abajo; cierre de servidores con su árbol; pruebas del integrador adicionales | CI **falló** solo en el E2E de Apache (primera vez que llegaba): `/api/disponibilidad` leída con sesión del equipo (ahora 403 por diseño), menú del equipo sin catálogo en la prueba de navegación y falta de `noindex` en errores dentro del layout. PHPUnit, QA e imagen Docker pasaron |
| **`6b9ae6f`** | **Código validado: corrige lo anterior (`noindex` en errores, E2E y navegación ajustados)** | **CI run 71 en éxito completo:** PHPUnit (PHP 8.2, orden aleatorio), QA con openpyxl y LibreOffice, imagen Docker, E2E en Apache con SMTP simulado, respaldo y restauración SHA-256. **Local (nube, PHP 8.3, MySQL 8.0.46, bases nuevas): PHPUnit 1074 pruebas OK; QA 889 pruebas OK, 0 omitidas.** El E2E se ensayó además en emulación local (`php -S`, sin Apache): 8 etapas OK |

La ampliación R5b posterior a `6b9ae6f` **sí cambia código y pruebas** (incluye la migración 027). El cierre actual es:

| SHA | Evidencia del cierre R5b |
|---|---|
| `5526f81`, `6ac8c4e` | Revisiones sustituidas por correcciones posteriores; sus ejecuciones locales invalidadas no se utilizan para este cierre |
| `b254642` | CI run 77 falló en `ColaSinEnvioEnLineaIntegrationTest`: el worker procesó un evento ajeno a la prueba (2 envíos en vez de 1) |
| `e39572a` | Aislamiento de la cola, restauración de conexiones, evidencia de reasignaciones/fidelidad y orden de bloqueos. CI run 78 falló en una prueba de privacidad: buscaba el dígito del id `1` en todo el HTML. El QA local se interrumpió al corregirla; no se cuenta como validación final |
| **`39c4fc5`** | **Código y pruebas validados:** dos PHPUnit locales aleatorios, QA local con omisiones de navegador explícitas, 30 mutaciones detectadas y [CI run 79 — éxito completo](https://github.com/Johnsky-Games/AppSalon_PHP_MVC_JS_SASS/actions/runs/38840115357). El CI completó PHPUnit, QA sin omisiones, imagen Docker, E2E en Apache y respaldo/restauración |

El commit `f6c9794` incorporó este cierre modificando únicamente documentación y evidencia (`[skip ci]`); el código y las pruebas siguen siendo exactamente los de `39c4fc5`. En ese momento aún no había PR ni merge. La integración posterior, autorizada por el usuario, se documenta en [INTEGRACION_MAIN_R5B.md](INTEGRACION_MAIN_R5B.md) y en el PR #1. No se ejecuta un despliegue ni una migración de la instalación del usuario.

Cambios de expectativas de pruebas y su justificación: `docs/R5_QA_INDEPENDIENTE.md` §3. Pruebas del integrador (no independientes) y su reverificación: §3 bis.

# Presupuestos v1 (migraciones 064, 065 y 066)

Un presupuesto es un **año + un escenario** («base», «optimista»…). Empieza **vacío**: solo guarda las cifras que una persona escribe o carga. **Nunca genera ventas, obligaciones, cobros ni asientos** (la prueba cuenta esas tablas antes y después de crear, cargar, aprobar y revisar).

Pantalla: `/gestion/presupuestos` (menú «Presupuestos» de Gestión). Plantilla CSV: `/gestion/presupuestos/plantilla?version=ID`.

## Qué se presupuesta

| Tipo | Destino | Importes |
|---|---|---|
| `servicio` | un servicio | base sin impuestos **y** impuesto, por separado (con impuestos = base + impuesto) |
| `producto` | un producto de inventario | igual |
| `gasto` | una cuenta contable de **gasto o costo**, imputable y activa | solo base (las cuentas de gasto son netas de IVA) |
| `comisiones` | partida propia (cuenta del rol contable `gasto_comisiones`) | solo base |

* Una cifra es **mes + concepto**. Mismo mes y concepto = misma línea (se modifica). No hay cifra por defecto.
* **Línea ausente = «sin presupuesto»; línea con 0 = «presupuesto cero».** Son casos distintos y los comparativos no los confunden.
* **Comisiones sin duplicar:** la cuenta de comisiones **no se admite como `gasto`** (lo impide el servicio, la carga CSV y un trigger de la base). Va en su partida; el total de gastos es *cuentas de gasto + comisiones*, una sola vez, y es igual al neto deudor de gasto y costo del libro.

## Tratamiento de descuentos y devoluciones

* **Descuentos:** el presupuesto de ventas es **venta neta de descuentos**. El descuento ya está fuera del total de la línea; no se presupuesta aparte. El real lo muestra como columna informativa (`descuentos_informativo_centavos`), sin restarlo otra vez.
* **Devoluciones:** restan de las ventas **en el mes en que se registra la devolución** (`erp_devoluciones.creado_en`, fecha propia), no en el mes de la venta original. Su base e impuesto se reparten con la proporción de su línea sobre el **acumulado devuelto de la línea** (redondeo a mitad hacia arriba): varias devoluciones parciales suman exactamente el impuesto de la línea, sin impuesto residual ni base negativa.
* Solo cuentan las ventas en estado `confirmada`, y sus devoluciones también: una venta devuelta y luego anulada no suma ingreso negativo; las que están en borrador o anuladas no cuentan.
* **Decisión del propietario (2026-10-23): la desviación principal se compara contra la venta neta de devoluciones**, en base sin impuestos y, aparte, en impuestos (la desviación «con impuestos» usa base + impuesto). Para explicarla se muestran **venta bruta, descuentos y devoluciones** como componentes: `bruta − descuentos = venta` y `venta − devoluciones = venta neta` (065 añade `ventas_bruta_base_centavos` al final de `bi_presupuesto_real_mes`; la bruta es la base antes de descuentos porque el descuento se resta antes del impuesto). Los componentes no tienen presupuesto ni desviación propia.

## Dos criterios para «lo real» (066, oficial desde 067)

La pantalla permite elegir el criterio y **siempre dice cuál se está viendo** (`criterio` y `corte` en el resultado del servicio y en el encabezado de la sección). Los dos criterios **no son intercambiables**.

| | **Por eventos** (OFICIAL, por defecto; con fecha de corte opcional) | **Estado actual** (análisis operativo) |
|---|---|---|
| Fuente | `bi_presupuesto_real_eventos` | `bi_presupuesto_real_mes` |
| Qué es | cada hecho en el día en que ocurrió; no se reescribe | lo real recalculado hoy con el estado vigente de cada venta |
| Venta | cuenta (+) el día que se confirmó, aunque se anule después | cuenta en el mes de su confirmación si hoy está confirmada |
| Devolución | resta (−) el día en que se registra (si es anterior a una anulación) | en el mes en que se registra, solo si la venta hoy está confirmada |
| Anulación | resta (−) **el neto que quedaba en ese momento** (venta − devoluciones hasta la anulación) el día en que se anuló | la venta (y sus devoluciones) desaparece de **todos** sus meses: **reescribe meses pasados** |
| Fecha de corte | solo cuentan los eventos hasta esa fecha (inclusive): lo que se sabía a ella | no aplica |
| Gasto | igual, con el mismo corte; la fecha es la contable del asiento, no la de su registro | libro por la fecha del asiento (un reverso resta en su fecha); se excluye el cierre del ejercicio |

**Sin duplicar.** Sumados todos los eventos, el resultado coincide con el estado actual: una venta anulada netea 0 (`venta − devoluciones − anulaciones = 0`). Solo difieren **por año** cuando la venta y su anulación caen en años distintos (la venta queda en el año anterior y la anulación resta en el siguiente); entre los dos años no se duplica ni se pierde nada. Cadena de componentes en cada fila: `bruta − descuentos = venta` y `venta − devoluciones − anulaciones = neta`.

**Fecha de la anulación.** La venta no guarda fecha de anulación: se toma del evento `venta_anulada` de `erp_eventos` (quién y cuándo). Una venta anulada **sin** ese evento (dato antiguo) se trata como anulada en el instante en que se confirmó (neto 0 desde el inicio, como el estado actual) y la fila lo marca en `anulaciones_sin_evento`. Una devolución posterior a la anulación (no se permite) se ignora. Una venta nunca confirmada (borrador) no cuenta.

**Qué cambia un mes ya pasado según el criterio**

| Hecho posterior | Estado actual | Por eventos |
|---|---|---|
| **Devolución** de una venta de un mes anterior | cae en el mes en que se registra; no reescribe el mes de la venta | igual |
| **Anulación** de una venta confirmada | reescribe: la venta sale de su mes y sus devoluciones de los suyos | no reescribe: la anulación resta en el mes en que ocurrió |
| **Reverso contable** | resta en la fecha del reverso | igual |
| Asiento de **cierre** del ejercicio y su reverso | no cuentan como gasto | igual |
| Cambio de la cuenta del rol `gasto_comisiones` | no se permite mientras existan presupuestos | igual |

Las pruebas (`testElCriterioPorEventosPreservaLoOcurridoAntesDeUnaAnulacionPosterior…`, `testLaFechaDeCorte…`) fijan ambos comportamientos con ventas, descuentos, devoluciones parciales y totales, anulaciones, cambios de mes y de año.

### Criterio oficial (067)
**Decisión del propietario:** el comparativo oficial es **por eventos y fecha de corte**, con la **venta neta de devoluciones** (base y impuestos por separado). El de **estado actual** queda como **análisis operativo**, identificado en la pantalla («Análisis operativo (no oficial)»).
* **Pantalla y servicio**: `comparativo($version)` usa `eventos` por defecto; `estado_actual` hay que pedirlo. La fecha de corte solo existe en el criterio oficial; una anulación o devolución posterior a la fecha de corte **no altera** ese corte.
* **Vistas BI**: `bi_presupuesto_vs_real` y `bi_presupuesto_anual` leen `bi_presupuesto_real_mes_eventos` (suma por mes de `bi_presupuesto_real_eventos`) y traen la columna `criterio = 'eventos'`. Una anulación aparece en el mes en que se anuló: los meses anteriores no se reescriben. Una vista no recibe parámetros, así que **el corte por fecha lo da la pantalla/servicio** (y, más adelante, la foto inmutable); en BI se puede filtrar por mes. `bi_presupuesto_real_mes` (estado actual) se conserva sin cambios para análisis operativo.
* **Sin sumar versiones ni escenarios**: cada presupuesto (año + escenario) se compara solo con su versión **aprobada vigente**; las reemplazadas y los borradores no entran, y dos escenarios del mismo año producen filas separadas (lo real se compara con cada uno, no se duplica). Los consolidados anuales agrupan por presupuesto.
* El gasto por eventos usa la fecha **contable** del asiento (no la fecha en que se registró), igual en los dos criterios.

### Pendientes (siguen abiertos)
* La **foto inmutable** (068, abajo) congela lo informado en un corte; el comparativo por eventos con corte, en cambio, **se recalcula** con los hechos que hoy se conocen (un dato registrado tarde con fecha anterior al corte cambiaría ese recálculo, no la foto).
* **Históricos incompletos**: una venta anulada sin evento `venta_anulada` (dato antiguo) se trata como anulada al confirmarse y la fila lo marca (`anulaciones_sin_evento`); no se inventa fecha.

## Foto inmutable del comparativo (068)

Una **foto** congela el comparativo **oficial (por eventos)** de la **versión aprobada** de un presupuesto a una **fecha de corte**. Guarda: la versión del presupuesto, el corte, el criterio, la **versión del cálculo** (`VERSION_CALCULO`, hoy 1; sube si cambia la regla), los **componentes reales por mes y concepto** (presupuesto base e impuesto —NULL = «sin presupuesto», distinto de 0—, venta bruta, descuentos, devoluciones, anulaciones, real base e impuesto y anulaciones sin fecha), el nombre del concepto de ese momento, **quién** la generó, **cuándo** y una **huella** sha256 del contenido.

* **Quién y dónde**: permiso `presupuestos.aprobar` (la persona que aprueba, no la que prepara). En la pantalla de la versión aprobada, sección «Fotos del comparativo (cierres)»; acciones `presupuesto_foto` y `presupuesto_foto_corregir` (idempotentes y auditadas: `presupuesto_foto_generada` / `presupuesto_foto_corregida`).
* **Reglas del corte**: debe caer en el año del presupuesto y **no puede ser futuro** (lo que aún no ocurrió no se congela). Solo se fotografía la versión **aprobada vigente**. Un corte ya fotografiado no se repite: se corrige.
* **Inmutabilidad**: la base rechaza `UPDATE`/`DELETE` sobre las fotos y sus líneas y no admite líneas nuevas una vez completa (`n_lineas`). Una anulación o devolución posterior **no cambia** una foto anterior; tampoco una venta registrada tarde con fecha anterior al corte.
* **Correcciones**: `corregirFoto` genera una foto **nueva** (número siguiente, mismo corte, `corrige_foto_id` a la anterior y **motivo obligatorio**), recalculada con la versión aprobada vigente (cada foto conserva la suya). La original y las anteriores se conservan; solo se corrige la más reciente de un corte. La pantalla muestra «Corregida por la foto #N» y comprueba la huella («contenido íntegro»).
* **Históricos incompletos**: una venta anulada sin evento `venta_anulada` (dato antiguo) se informa en la foto (`anulaciones_sin_evento`, por línea y total) y se trató como anulada al confirmarse: **no se inventa fecha** ni se presenta como exacta. El gasto usa la fecha contable del asiento. Los cobros de caja no forman parte de la foto (no tienen presupuesto).
* **BI**: `bi_presupuesto_fotos` (cabecera; `es_vigente` = ninguna otra la corrige; corte como AAAAMMDD, sin columnas de fecha ni personas) y `bi_presupuesto_foto_lineas`.
* Revertir 068 (guarda) avisa si existen fotos: son el registro de lo que se informó.
* **Respaldo y restauración**: las fotos, sus líneas y la huella se conservan, y la restauración repone los triggers que las protegen (la prueba QA de respaldo/restauración siembra una foto y comprueba datos, huella y protecciones). Esa prueba destapó un defecto previo: el respaldo incluía las columnas **generadas** (`abierta_ref`, `vigente_ref`, `objetivo_id`) en los `INSERT` y MySQL rechazaba la restauración de cualquier base con versiones de presupuesto; `scripts/backup_db.php` ahora omite las columnas generadas, que MySQL recalcula.

## Cierre mensual con foto (069)

**Decisión del propietario (26 de octubre):** el cierre mensual de presupuestos usa la **foto inmutable** como referencia oficial de lo informado. **No equivale al cierre del período contable.**

* **Qué es un cierre**: vincula explícitamente **presupuesto + mes + foto elegida + la versión aprobada** con la que se fotografió (`erp_presupuesto_cierres`). La foto debe estar **cortada el último día de ese mes** y ser la **vigente** de su corte (no una ya corregida). El responsable (`presupuestos.aprobar`), la fecha y el motivo (en correcciones) quedan registrados y auditados (`presupuesto_mes_cerrado`, `presupuesto_cierre_corregido`).
* **Correcciones**: una corrección es un **cierre nuevo** (número siguiente, motivo obligatorio, `corrige_cierre_id`) que apunta a otra foto vigente del mismo mes (típicamente la corrección de la foto). El cierre anterior y su foto se **conservan** con su vínculo; solo se corrige el cierre más reciente de un mes; no se puede «corregir» hacia la misma foto.
* **Inmutabilidad y coherencia en la base**: los cierres no se editan ni se borran (triggers), y la base rechaza un cierre que no coincida con su foto (presupuesto, versión aprobada, año y corte en el último día del mes).
* **Lo que NO hace**: no escribe en el libro, no cierra períodos contables ni genera asientos (la prueba lo comprueba con los conteos del libro y de períodos). El cierre del período contable sigue su propio proceso.
* **BI**: `bi_presupuesto_cierres` (cierre, mes, foto, versión aprobada; `es_vigente`); sin fechas ni personas. El respaldo y la restauración conservan los cierres y sus protecciones.

## Fuente y fecha de cada métrica

| Métrica | Fuente | Fecha |
|---|---|---|
| Ventas (devengado) | líneas de `erp_ventas` confirmadas (`bi_presupuesto_real_eventos` oficial; `bi_presupuesto_real_mes` estado actual) | `confirmado_en` de la venta |
| Devoluciones | `erp_devoluciones` | `creado_en` de la devolución |
| **Cobros (caja)** | `bi_flujo_dinero` (cobros − devoluciones de cobro), con impuestos | fecha del movimiento de caja |
| Gasto devengado | libro contable (`bi_libro_lineas`), cuentas de tipo gasto o costo, neto deudor; un reverso resta. **Se excluye el asiento de cierre del ejercicio y su reverso** (trasladan el resultado, no son gasto) | fecha del asiento |
| Comisiones devengadas | mismo libro, cuenta del rol `gasto_comisiones` | fecha del asiento |

* **Ventas, cobros y gastos devengados son tres cosas distintas** y se muestran separadas. Los **cobros no tienen presupuesto ni desviación** en esta versión (se informan para contrastar). No incluyen ventas pagadas con saldo prepago, que no pasan por caja.
* Las líneas de venta sin servicio ni producto aparecen como `sin_clasificar` (solo real) para que el total de ventas cuadre.

## Desviación

`desviación = real − presupuesto`, en dinero y en porcentaje sobre el presupuesto (2 decimales, mitad hacia arriba). Estados: `con_presupuesto`, `presupuesto_cero` (desviación en dinero, **sin porcentaje**), `sin_presupuesto` (ni desviación ni porcentaje). En ventas, positiva = se vendió más; en gastos, positiva = se gastó más (`sentido`). El consolidado anual compara **solo los meses y conceptos que tienen presupuesto**; el real del año completo se muestra aparte.

## Escenarios y versiones

`borrador` (primera versión) → `aprobada` → `reemplazada`; una **revisión** (`revision`) parte de una copia de la aprobada vigente y, al aprobarse, la anterior queda `reemplazada` y **se conserva intacta**. Una sola versión abierta y una sola aprobada por presupuesto (índices únicos). Aprobada o reemplazada no se edita ni se borra (triggers). Una versión sin cifras no se aprueba.

Auditoría: `erp_presupuesto_cambios` (solo de anexar) guarda quién, cuándo, valor anterior y nuevo de cada cifra y su origen (manual, carga o revisión); `erp_presupuestos`/`erp_presupuesto_versiones` guardan quién creó y quién aprobó; los eventos van a `erp_eventos`.

## Permisos (segregados)

| Permiso | Alcance | Roles |
|---|---|---|
| `presupuestos.ver` | consultar y comparar | propietario, gerente, finanzas |
| `presupuestos.editar` | crear, cifras, plantilla CSV, revisiones | propietario, finanzas |
| `presupuestos.aprobar` | aprobar versiones | propietario |

Las cifras de gasto son totales por cuenta, no por persona, por lo que las vistas BI van al grupo **comercial** (como `bi_libro_lineas`); ninguna lleva datos personales ni identifica colaboradores. La comisión presupuestada es un total, no por colaborador.

## Carga por plantilla CSV

Columnas `mes;tipo;referencia;nombre;base;impuesto` (separador «;», punto decimal, UTF-8, hasta 1 MB y 5.000 filas). `referencia` = id del servicio, id del producto o **código** de la cuenta; vacía en `comisiones`. `nombre` es informativo. La plantilla descargada trae las cifras actuales.

1. **Validar:** se valida el archivo entero. Con cualquier error se rechaza completo (hasta 20 avisos con su número de línea) y **no se guarda nada**.
2. **Vista previa:** se guarda lo validado como carga `previa` (qué se crea o modifica y cuánto valía antes). No cambia ninguna cifra.
3. **Confirmar o descartar:** al confirmar se aplica **exactamente lo previsualizado**, no el archivo otra vez, y solo si la versión sigue abierta. Además, si otra persona cambió, quitó o creó alguna de esas cifras después de la vista previa, la confirmación se rechaza (409) sin aplicar nada y hay que cargar de nuevo: no se pisa una edición más reciente.

Una fila con base e impuesto vacíos **no cambia nada** (no equivale a cero; para fijar cero se escribe `0`). Quitar una cifra se hace desde la pantalla. Subir de nuevo la plantilla sin tocar no cambia nada (se avisa).

## Vistas BI (solo lectura, `SQL SECURITY DEFINER`; sin columnas de fecha salvo `bi_presupuesto_real_eventos`)

| Vista | Grano |
|---|---|
| `bi_presupuesto_real_mes` | **lo real con el estado actual (análisis operativo, no oficial)** por año, mes y destino (una sola definición, usada por la pantalla y por BI); trae también `ventas_bruta_base_centavos` (065) |
| `bi_presupuesto_real_eventos` (066) | **lo real por eventos**: día + tipo + destino, con venta, descuentos, devoluciones y anulaciones en la fecha en que ocurrieron (única de estas vistas con una columna de fecha, `fecha_evento`, vigilada por `bi_control_calendario`); filtrar por `fecha_evento` da la fecha de corte |
| `bi_presupuesto_lineas` | cifras presupuestadas de versiones aprobadas y reemplazadas (`es_vigente`); los borradores y revisiones abiertas **no salen** |
| `bi_presupuesto_real_mes_eventos` (067) | lo real **por eventos** sumado por año, mes y destino (fuente de las dos vistas siguientes) |
| `bi_presupuesto_vs_real` | versión **vigente** frente a lo real **por eventos (oficial, 067)**, por mes y destino, con desviación y estado; columna `criterio` |
| `bi_presupuesto_anual` | consolidado anual por destino (por eventos, oficial; una fila por presupuesto y concepto) |
| `bi_presupuesto_cobros_mes` | cobros de caja por mes (sin presupuesto) |
| `bi_presupuesto_cierres` (069) | cierres mensuales: mes, foto de referencia y versión aprobada, con `es_vigente` |
| `bi_presupuesto_fotos` / `bi_presupuesto_foto_lineas` (068) | cierres inmutables del comparativo oficial: cabecera y componentes por mes y concepto |

El mes se une con `bi_dim_fecha.anio_mes` (AAAAMM). **La dimensión de fecha se amplía** (064 redefine `bi_dim_fecha`) para cubrir del 1 de enero al 31 de diciembre de cada año con presupuesto: un presupuesto suele ser futuro y sin esto sus meses quedarían fuera del calendario. La prueba usa un presupuesto de 2031.

## Límites conocidos
* **El rol contable `gasto_comisiones` no se reasigna a otra cuenta mientras existan presupuestos** (`ErpContabilidadService::rol()` responde 409): la partida de comisiones se define por ese rol y reasignarlo reclasificaría el histórico y las versiones aprobadas, y haría fallar la copia de una revisión. Volver a asignar la misma cuenta sí se permite.
* La plantilla CSV incluye también los productos y cuentas archivados que la versión ya presupuesta (y una fila sin cambios de una cuenta archivada se acepta al subirla; cambiarla no), y neutraliza fórmulas (`=`, `+`, `-`, `@`) en los nombres.
* Una cuenta de gasto archivada después de presupuestarla **no impide abrir una revisión** (la copia la conserva); solo se puede quitar su cifra, no escribirla de nuevo. El trigger exige cuenta de gasto o costo y no la de comisiones; que sea imputable y activa lo exige el servicio al escribir o cargar.

* Cobros sin presupuesto; no hay presupuesto por profesional ni por centro de costo.
* No hay prorrateo automático anual→mensual: cada mes se escribe o se carga.
* Los responsables de cada indicador siguen siendo decisión del propietario (la comparación contra venta neta de devoluciones ya está decidida).
* Los importes presupuestados admiten hasta 9.999.999,99 por línea y mes. Un producto archivado conserva su cifra (y se puede quitar) pero no admite cifras nuevas ni cambios, igual que una cuenta de gasto archivada.

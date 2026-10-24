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

## Dos criterios para «lo real» (066)

La pantalla permite elegir el criterio y **siempre dice cuál se está viendo** (`criterio` y `corte` en el resultado del servicio y en el encabezado de la sección). Los dos criterios **no son intercambiables**.

| | **Estado actual** (por defecto) | **Por eventos** (con fecha de corte opcional) |
|---|---|---|
| Fuente | `bi_presupuesto_real_mes` | `bi_presupuesto_real_eventos` |
| Qué es | lo real recalculado hoy con el estado vigente de cada venta | cada hecho en el día en que ocurrió; no se reescribe |
| Venta | cuenta en el mes de su confirmación si hoy está confirmada | cuenta (+) el día que se confirmó, aunque se anule después |
| Devolución | en el mes en que se registra, solo si la venta hoy está confirmada | resta (−) el día en que se registra (si es anterior a una anulación) |
| Anulación | la venta (y sus devoluciones) desaparece de **todos** sus meses: **reescribe meses pasados** | resta (−) **el neto que quedaba en ese momento** (venta − devoluciones hasta la anulación) el día en que se anuló |
| Fecha de corte | no aplica | solo cuentan los eventos hasta esa fecha (inclusive): lo que se sabía a ella |
| Gasto | libro por la fecha del asiento (un reverso resta en su fecha); se excluye el cierre del ejercicio | igual, con el mismo corte; la fecha es la contable del asiento, no la de su registro |

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

### Pendiente funcional (sigue abierto)
* **El comparativo oficial sigue siendo el de estado actual**: la pantalla lo usa por defecto y las vistas `bi_presupuesto_vs_real` y `bi_presupuesto_anual` (versión aprobada frente a lo real, para tableros) **siguen calculadas con el estado actual**, por lo que anular una venta sigue reescribiendo los meses pasados en esos tableros. El criterio por eventos está en la pantalla y en `bi_presupuesto_real_eventos`; falta llevarlo a las vistas comparativas de BI y decidir (propietario) cuál criterio es el oficial para tableros y cierres.
* No hay **foto del comparativo al cierre de un mes** (el criterio por eventos con corte la reconstruye a partir de los hechos; no es un registro inmutable de lo que se informó entonces).
* El gasto por eventos usa la fecha **contable** del asiento: un asiento con fecha anterior registrado después aparece en su fecha contable, no en la fecha en que se supo.

## Fuente y fecha de cada métrica

| Métrica | Fuente | Fecha |
|---|---|---|
| Ventas (devengado) | líneas de `erp_ventas` confirmadas (`bi_presupuesto_real_mes`) | `confirmado_en` de la venta |
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
| `bi_presupuesto_real_mes` | **lo real con el estado actual** por año, mes y destino (una sola definición, usada por la pantalla y por BI); trae también `ventas_bruta_base_centavos` (065) |
| `bi_presupuesto_real_eventos` (066) | **lo real por eventos**: día + tipo + destino, con venta, descuentos, devoluciones y anulaciones en la fecha en que ocurrieron (única de estas vistas con una columna de fecha, `fecha_evento`, vigilada por `bi_control_calendario`); filtrar por `fecha_evento` da la fecha de corte |
| `bi_presupuesto_lineas` | cifras presupuestadas de versiones aprobadas y reemplazadas (`es_vigente`); los borradores y revisiones abiertas **no salen** |
| `bi_presupuesto_vs_real` | versión **vigente** frente a lo real, por mes y destino, con desviación y estado |
| `bi_presupuesto_anual` | consolidado anual por destino |
| `bi_presupuesto_cobros_mes` | cobros de caja por mes (sin presupuesto) |

El mes se une con `bi_dim_fecha.anio_mes` (AAAAMM). **La dimensión de fecha se amplía** (064 redefine `bi_dim_fecha`) para cubrir del 1 de enero al 31 de diciembre de cada año con presupuesto: un presupuesto suele ser futuro y sin esto sus meses quedarían fuera del calendario. La prueba usa un presupuesto de 2031.

## Límites conocidos
* **El rol contable `gasto_comisiones` no se reasigna a otra cuenta mientras existan presupuestos** (`ErpContabilidadService::rol()` responde 409): la partida de comisiones se define por ese rol y reasignarlo reclasificaría el histórico y las versiones aprobadas, y haría fallar la copia de una revisión. Volver a asignar la misma cuenta sí se permite.
* La plantilla CSV incluye también los productos y cuentas archivados que la versión ya presupuesta (y una fila sin cambios de una cuenta archivada se acepta al subirla; cambiarla no), y neutraliza fórmulas (`=`, `+`, `-`, `@`) en los nombres.
* Una cuenta de gasto archivada después de presupuestarla **no impide abrir una revisión** (la copia la conserva); solo se puede quitar su cifra, no escribirla de nuevo. El trigger exige cuenta de gasto o costo y no la de comisiones; que sea imputable y activa lo exige el servicio al escribir o cargar.

* Cobros sin presupuesto; no hay presupuesto por profesional ni por centro de costo.
* No hay prorrateo automático anual→mensual: cada mes se escribe o se carga.
* Los responsables de cada indicador siguen siendo decisión del propietario (la comparación contra venta neta de devoluciones ya está decidida).
* Los importes presupuestados admiten hasta 9.999.999,99 por línea y mes. Un producto archivado conserva su cifra (y se puede quitar) pero no admite cifras nuevas ni cambios, igual que una cuenta de gasto archivada.

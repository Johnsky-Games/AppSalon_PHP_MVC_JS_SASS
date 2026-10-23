# Presupuestos v1 (migración 064)

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
* **Devoluciones:** restan de las ventas **en el mes en que se registra la devolución** (`erp_devoluciones.creado_en`, fecha propia), no en el mes de la venta original. Su base e impuesto se reparten con la proporción de su línea (redondeo a mitad hacia arriba, al centavo, por devolución); una devolución completa devuelve exactamente el impuesto de la línea.
* Solo cuentan las ventas en estado `confirmada`, y sus devoluciones también: una venta devuelta y luego anulada no suma ingreso negativo; las que están en borrador o anuladas no cuentan.
* Pendiente de decisión del propietario: si la política de comparación debe ser venta *bruta* de devoluciones. Hoy la desviación usa venta **neta** de devoluciones y las dos componentes se ven por separado en `bi_presupuesto_real_mes`.

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
3. **Confirmar o descartar:** al confirmar se aplica **exactamente lo previsualizado**, no el archivo otra vez, y solo si la versión sigue abierta.

Una fila con base e impuesto vacíos **no cambia nada** (no equivale a cero; para fijar cero se escribe `0`). Quitar una cifra se hace desde la pantalla. Subir de nuevo la plantilla sin tocar no cambia nada (se avisa).

## Vistas BI (solo lectura, `SQL SECURITY DEFINER`, sin columnas de fecha)

| Vista | Grano |
|---|---|
| `bi_presupuesto_real_mes` | **lo real** por año, mes y destino (una sola definición, usada por la pantalla y por BI) |
| `bi_presupuesto_lineas` | cifras presupuestadas de versiones aprobadas y reemplazadas (`es_vigente`); los borradores y revisiones abiertas **no salen** |
| `bi_presupuesto_vs_real` | versión **vigente** frente a lo real, por mes y destino, con desviación y estado |
| `bi_presupuesto_anual` | consolidado anual por destino |
| `bi_presupuesto_cobros_mes` | cobros de caja por mes (sin presupuesto) |

El mes se une con `bi_dim_fecha.anio_mes` (AAAAMM). **La dimensión de fecha se amplía** (064 redefine `bi_dim_fecha`) para cubrir del 1 de enero al 31 de diciembre de cada año con presupuesto: un presupuesto suele ser futuro y sin esto sus meses quedarían fuera del calendario. La prueba usa un presupuesto de 2031.

## Límites conocidos
* Una cuenta de gasto archivada después de presupuestarla **no impide abrir una revisión** (la copia la conserva); solo se puede quitar su cifra, no escribirla de nuevo. El trigger exige cuenta de gasto o costo y no la de comisiones; que sea imputable y activa lo exige el servicio al escribir o cargar.

* Cobros sin presupuesto; no hay presupuesto por profesional ni por centro de costo.
* No hay prorrateo automático anual→mensual: cada mes se escribe o se carga.
* La política de venta bruta vs neta de devoluciones y los responsables de cada indicador son decisiones del propietario.
* Los importes presupuestados admiten hasta 9.999.999,99 por línea y mes.

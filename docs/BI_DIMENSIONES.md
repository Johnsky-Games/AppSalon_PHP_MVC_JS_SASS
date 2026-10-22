# Dimensiones para BI (migración 062)

Sexto corte del modelo de lectura (ver `docs/BI_MODELO_LECTURA.md`). Da a Power BI las **tablas de dimensión** con las que se filtran y agrupan los hechos de los cortes anteriores. Mismas garantías: solo lectura (la prueba verifica que ninguna admite `UPDATE` ni `DELETE`), `SQL SECURITY DEFINER` y **sin datos personales**: ninguna lleva nombres de personas, documentos, contactos, notas ni costos.

| Vista | Grupo de acceso | Grano | Clave |
| --- | --- | --- | --- |
| `bi_dim_fecha` | comercial | un día | `fecha` |
| `bi_dim_producto` | comercial | un producto de inventario | `producto_id` |
| `bi_dim_servicio` | comercial | un servicio | `servicio_id` |
| `bi_dim_profesional` | comercial | un profesional de la agenda | `profesional_id` |
| `bi_rh_dim_colaborador` | **laboral** (con `bi_rh_*`) | un colaborador | `colaborador_id` |

## `bi_dim_fecha` y los roles de fecha
Una **sola** tabla de fechas, continua (un día por fila, sin huecos), desde la fecha más antigua de **cualquier columna de fecha de las vistas de hechos** (las de la tabla de abajo) hasta la más reciente o el día de hoy, lo que sea posterior; el rango incluye siempre hoy (hora de Ecuador, UTC−5 sin cambio de hora), hasta 10.000 días. Sin ningún hecho tiene una sola fila: hoy.

Columnas: `fecha`, `anio`, `trimestre`, `mes`, `anio_mes` (aaaamm, ordena bien), `nombre_mes`, `dia_del_mes`, `dia_semana` (ISO, 1 = lunes), `nombre_dia`, `es_fin_de_semana`, `anio_iso` y `semana_iso` (el 1 de enero de 2027 es la semana 53 de 2026).

**Roles:** no hay una copia de la dimensión por rol. Cada hecho se relaciona con `bi_dim_fecha[fecha]` por su propia columna de fecha; una relación es la activa y las demás se activan en la medida con `USERELATIONSHIP`:

| Hecho | Columna de fecha | Rol |
| --- | --- | --- |
| `bi_atenciones` | `fecha_programada` / `fecha_servicio_realizado` | cita programada / servicio realizado |
| `bi_venta_lineas`, `bi_saldo_por_cobrar_venta` | `fecha_venta` | venta |
| `bi_flujo_dinero` | `fecha_movimiento` (y `fecha_venta_original`, `fecha_cobro_original`) | movimiento de dinero / venta de origen / cobro de origen |
| `bi_devoluciones_dinero` | `fecha_movimiento_devolucion` (y `fecha_venta_original`, `fecha_cobro_original`) | devolución de dinero |
| `bi_devoluciones_venta` | `fecha_devolucion` (y `fecha_venta_original`) | devolución comercial |
| `bi_aplicaciones_saldo` | `fecha_aplicacion` (y `fecha_venta_original`) | aplicación de prepago o paquete |
| `bi_notas_credito` | `fecha_nota` | nota de crédito |
| `bi_cxc_movimientos` | `fecha` | movimiento de cartera |
| `bi_libro_lineas` | `fecha` | contable |
| `bi_inventario_movimientos` | `fecha` | movimiento de inventario |
| `bi_ocupacion_profesional_dia` | `fecha` | día de capacidad |
| `bi_rh_asistencia` | `fecha` | día trabajado |
| `bi_rh_ausencias` | `desde`, `hasta` | inicio / fin de ausencia (rango; el cruce día por día se hace en el modelo) |
| `bi_rh_comisiones_movimientos`, `bi_rh_comisiones_liquidaciones` | `fecha` | devengo / liquidación |

Las columnas de fecha y hora (`atencion_inicio_at`, `entrada`, `salida`, …) se relacionan por su parte de fecha; el rango de la dimensión las cubre.

```DAX
Ventas por fecha de cobro = CALCULATE ( [Ventas netas], USERELATIONSHIP ( bi_dim_fecha[fecha], bi_flujo_dinero[fecha_movimiento] ) )
```
(Los nombres de medida de ejemplo son ilustrativos; la relación activa y las medidas las decide el modelo de Power BI. No se ejecutó DAX desde este entorno.)

## Producto y servicio
Atributos del catálogo **actual**: `sku`/`nombre`/`unidad`/`activo` (producto), `nombre`/`precio_actual_centavos`/`duracion_minutos` (servicio) y los del catálogo (`en_catalogo_de_venta`, `catalogo_activo`, `tipo_catalogo`, `impuesto_pb`, y `precio_catalogo_centavos` y `es_insumo` en producto). `en_catalogo_de_venta` significa **a la venta hoy**, con la misma regla que el punto de venta: tipo `venta` o `ambos`, **entrada de catálogo activa y producto activo** (el criterio de la lista de venta: `catalogo.activo = 1 AND producto.activo = 1 AND tipo <> 'consumo'`). Un servicio no tiene activo propio: cuenta la entrada de catálogo. Una entrada desactivada (`catalogo_activo = 0`) deja de estar a la venta y deja de mostrar precio de venta de producto; `tipo_catalogo` y `catalogo_activo` conservan el dato para analizarla. un artículo de **consumo** interno (`tipo = 'consumo'`) no se vende, así que tiene `es_insumo = 1`, `en_catalogo_de_venta = 0` y **sin precio de venta**. Un **producto** que no está en el catálogo de venta aparece con `en_catalogo_de_venta = 0` y precio de catálogo nulo: **no se inventa precio**. Un **servicio** siempre tiene `precio_actual_centavos`: es el precio base de la tabla de servicios (el que usa la agenda para reservar), exista o no en el catálogo de venta; `en_catalogo_de_venta` indica solo si además se vende en el punto de venta. Los hechos guardan el precio y la duración **de cuando ocurrieron** (`bi_venta_lineas`, `bi_atencion_servicios`), de modo que cambiar el catálogo no reescribe lo vendido ni lo reservado (la prueba lo comprueba). No incluye costo ni existencias: el costo del momento está en el hecho (`costo_total_centavos`) y las existencias se calculan con los movimientos.

## Profesional y colaborador (separación de datos)
* `bi_dim_profesional` (comercial) lleva solo `profesional_id`, `activo` y `tiene_ficha_laboral`. **No lleva nombre** (dato personal) **ni `colaborador_id`**: el puente entre la agenda y la persona laboral no se entrega al grupo comercial. Las etiquetas legibles las pone el propietario en una tabla propia del modelo de Power BI, o se muestra el identificador. Las citas históricas sin profesional no se unen a ninguna fila y se ven como «sin profesional».
* `bi_rh_dim_colaborador` (laboral): `colaborador_id`, `rol`, `estado`, `fecha_ingreso`, `fecha_baja` y `profesional_id`. Son **datos laborales identificables por `colaborador_id`, no anónimos**; sin nombre, documento, contacto, sueldo ni notas. Se concede solo al grupo laboral (el que lee `bi_rh_*`).

```sql
GRANT SELECT ON appsalon.bi_dim_fecha TO 'bi_comercial'@'%';      -- y bi_dim_producto, bi_dim_servicio, bi_dim_profesional
GRANT SELECT ON appsalon.bi_rh_dim_colaborador TO 'bi_laboral'@'%';
```
El proyecto no crea usuarios ni toca Docker; los crea el propietario.

## Reconciliación (pruebas)
`ErpBiDimensionesIntegrationTest` (5 pruebas, MySQL real):
* Fecha: continua y sin repetidos entre la fecha más antigua y la más reciente, incluye hoy, **cada fecha de cada columna de fecha de cada vista de hechos tiene su día** (la prueba recorre `information_schema`, de modo que una vista nueva con fechas que no se añada al rango falla), incluidas una ausencia futura y una liquidación antigua, atributos exactos (día de la semana, trimestre, `anio_mes`, semana ISO, fin de semana, año ISO de enero de 2027); unir un hecho a la dimensión no duplica ni pierde filas ni cambia la suma de minutos; sin hechos tiene una fila (hoy).
* Producto, servicio y profesional: una fila por entidad (también las que no están en el catálogo), `19.99 → 1999` centavos exactos, sin inventar precio, unir hechos no duplica, y cambiar el precio del catálogo no cambia el precio reservado.
* Colaborador: una fila por colaborador, rol, estado, fechas y enlace con su agenda; se une a los hechos laborales sin duplicarlos.
* Acceso: usuarios reales de MySQL; el comercial lee sus cuatro dimensiones y **no** la laboral, el laboral lee la suya y no las comerciales, ninguno lee las tablas de origen; ninguna vista admite `UPDATE`/`DELETE`; sin columnas ni valores de datos personales (nombres, correos, teléfonos, notas).
* Un artículo de consumo no se marca como de venta ni expone precio de venta.
* Mutaciones comprobadas (cada una rompe la prueba): quitar `DISTINCT` a `bi_dim_profesional` (vista actualizable), perder el último día de la fecha, tomar los límites solo de unas tablas de origen, truncar el precio en lugar de redondearlo y usar el año calendario como año ISO.
* `EsquemaTest` (respaldo y restauración, con y sin manifiesto) exige las 23 vistas (057–062) y compara su esquema restaurado columna por columna.

## Límites conocidos
* El rango de fechas se calcula con los hechos existentes y hoy: no es un calendario fijo. Si hace falta un calendario más largo (por ejemplo, para presupuestos futuros), se amplía en el modelo de Power BI o con una decisión de producto.
* Los atributos de producto y servicio son los vigentes: no hay historial de cambios de catálogo. Para analizar precios históricos se usan los hechos, que guardan el suyo.
* Sin nombres de profesional ni de colaborador en las vistas: es deliberado; la identificación legible es decisión del propietario y se resuelve fuera del repositorio.
* **Presupuestos:** el sistema no tiene presupuestos (ni tablas ni pantallas). No se inventan cifras ni cuentas. Qué se presupuesta, con qué periodicidad y cómo se carga es una decisión del propietario (ver `docs/BI_MODELO_LECTURA.md`).

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
Una **sola** tabla de fechas, continua (un día por fila, sin huecos), desde el hecho más antiguo (cita, venta confirmada, asiento contable, pago o movimiento de inventario) hasta el más reciente o el día de hoy, lo que sea posterior; el rango incluye siempre hoy (hora de Ecuador, UTC−5 sin cambio de hora), hasta 10.000 días. Sin ningún hecho tiene una sola fila: hoy.

Columnas: `fecha`, `anio`, `trimestre`, `mes`, `anio_mes` (aaaamm, ordena bien), `nombre_mes`, `dia_del_mes`, `dia_semana` (ISO, 1 = lunes), `nombre_dia`, `es_fin_de_semana`, `anio_iso` y `semana_iso` (el 1 de enero de 2027 es la semana 53 de 2026).

**Roles:** no hay una copia de la dimensión por rol. Cada hecho se relaciona con `bi_dim_fecha[fecha]` por su propia columna de fecha; una relación es la activa y las demás se activan en la medida con `USERELATIONSHIP`:

| Hecho | Columna de fecha | Rol |
| --- | --- | --- |
| `bi_atenciones` | `fecha_programada` / `fecha_servicio_realizado` | cita programada / servicio realizado |
| `bi_venta_lineas` | `fecha_venta` | venta |
| `bi_flujo_dinero`, `bi_devoluciones_dinero` | `fecha` | movimiento de dinero |
| `bi_cxc_movimientos` | `fecha` | movimiento de cartera |
| `bi_libro_lineas` | `fecha` | contable |
| `bi_inventario_movimientos` | `fecha` | movimiento de inventario |
| `bi_ocupacion_profesional_dia` | `fecha` | día de capacidad |
| `bi_rh_asistencia`, `bi_rh_ausencias`, `bi_rh_jornadas` | `fecha`, `desde`/`hasta` | día trabajado / rango de ausencia |
| `bi_rh_comisiones_movimientos` | `fecha` | devengo |

```DAX
Ventas por fecha de cobro = CALCULATE ( [Ventas netas], USERELATIONSHIP ( bi_dim_fecha[fecha], bi_flujo_dinero[fecha] ) )
```
(Los nombres de medida de ejemplo son ilustrativos; la relación activa y las medidas las decide el modelo de Power BI. No se ejecutó DAX desde este entorno.)

## Producto y servicio
Atributos del catálogo **actual**: `sku`/`nombre`/`unidad`/`activo` (producto), `nombre`/`precio_actual_centavos`/`duracion_minutos` (servicio) y los del catálogo de venta (`en_catalogo_de_venta`, `catalogo_activo`, `tipo_catalogo`, `impuesto_pb`, y `precio_catalogo_centavos` en producto). Un producto o servicio que no está en el catálogo aparece con `en_catalogo_de_venta = 0` y precio de catálogo nulo: **no se inventa precio**. Los hechos guardan el precio y la duración **de cuando ocurrieron** (`bi_venta_lineas`, `bi_atencion_servicios`), de modo que cambiar el catálogo no reescribe lo vendido ni lo reservado (la prueba lo comprueba). No incluye costo ni existencias: el costo del momento está en el hecho (`costo_total_centavos`) y las existencias se calculan con los movimientos.

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
* Fecha: continua y sin repetidos entre el hecho más antiguo y el más reciente, incluye hoy, ningún hecho queda sin su día (citas y pagos), atributos exactos (día de la semana, trimestre, `anio_mes`, semana ISO, fin de semana, año ISO de enero de 2027); unir un hecho a la dimensión no duplica ni pierde filas ni cambia la suma de minutos; sin hechos tiene una fila (hoy).
* Producto, servicio y profesional: una fila por entidad (también las que no están en el catálogo), `19.99 → 1999` centavos exactos, sin inventar precio, unir hechos no duplica, y cambiar el precio del catálogo no cambia el precio reservado.
* Colaborador: una fila por colaborador, rol, estado, fechas y enlace con su agenda; se une a los hechos laborales sin duplicarlos.
* Acceso: usuarios reales de MySQL; el comercial lee sus cuatro dimensiones y **no** la laboral, el laboral lee la suya y no las comerciales, ninguno lee las tablas de origen; ninguna vista admite `UPDATE`/`DELETE`; sin columnas ni valores de datos personales (nombres, correos, teléfonos, notas).
* Mutaciones comprobadas (cada una rompe la prueba): quitar `DISTINCT` a `bi_dim_profesional` (vista actualizable), perder el último día de la fecha, truncar el precio en lugar de redondearlo y usar el año calendario como año ISO.
* `EsquemaTest` (respaldo y restauración, con y sin manifiesto) exige las 23 vistas (057–062) y compara su esquema restaurado columna por columna.

## Límites conocidos
* El rango de fechas se calcula con los hechos existentes y hoy: no es un calendario fijo. Si hace falta un calendario más largo (por ejemplo, para presupuestos futuros), se amplía en el modelo de Power BI o con una decisión de producto.
* Los atributos de producto y servicio son los vigentes: no hay historial de cambios de catálogo. Para analizar precios históricos se usan los hechos, que guardan el suyo.
* Sin nombres de profesional ni de colaborador en las vistas: es deliberado; la identificación legible es decisión del propietario y se resuelve fuera del repositorio.
* **Presupuestos:** el sistema no tiene presupuestos (ni tablas ni pantallas). No se inventan cifras ni cuentas. Qué se presupuesta, con qué periodicidad y cómo se carga es una decisión del propietario (ver `docs/BI_MODELO_LECTURA.md`).

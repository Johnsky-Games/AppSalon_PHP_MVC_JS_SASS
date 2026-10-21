# Modelo de lectura para BI: cobro neto, devoluciones y notas de crédito (migración 058)

Segundo corte del modelo de lectura (el primero es `docs/BI_MODELO_LECTURA.md`, migración 057). Define **qué mide cada métrica de dinero** antes de calcularla. Las vistas son de solo lectura, no tienen datos personales y se conectan con el mismo usuario de BI (solo `SELECT` sobre las vistas `bi_*`).

## Principio rector: tres cosas distintas que no se suman
| Concepto | Qué es | Fuente | Fecha |
| --- | --- | --- | --- |
| **Flujo de dinero** | Dinero que **entró o salió de verdad** (caja y bancos) | `caja_movimientos` | fecha del movimiento |
| **Aplicación de saldo** | Uso de un prepago o paquete ya cobrado para pagar una venta. **No entra dinero nuevo** | `erp_prepago_movimientos` (aplicación / restitución) | fecha de la aplicación |
| **Venta neta y nota de crédito** | Hechos comerciales y fiscales. **No son flujo de caja** | `erp_venta_lineas`, `erp_devoluciones`, notas de crédito fiscales | fecha de venta, de devolución y de emisión |

Sumar de un grupo con otro duplica importes (un prepago se cobra una vez —carga— y se aplica después; una devolución comercial genera una nota de crédito y, si hubo cobro, un reembolso). Por eso cada grupo vive en su propia vista y cada columna lleva el nombre de lo que mide.

## Métricas
| Métrica | Definición exacta | Vista y filtro |
| --- | --- | --- |
| **Cobros efectivos** | Dinero recibido por ventas o citas (efectivo, o transferencia ya verificada), en la fecha en que se registró en caja | `bi_flujo_dinero`, `tipo_flujo = 'cobro'` |
| **Devoluciones de dinero** | Dinero devuelto al cliente por un cobro anterior, en la fecha en que salió de caja | `bi_flujo_dinero`, `tipo_flujo = 'devolucion_de_cobro'` |
| **Cobro neto de ventas** | Cobros efectivos − devoluciones de dinero (ambos con su propia fecha; un mes puede tener neto negativo si devuelve cobros de meses anteriores) | `bi_flujo_dinero`, suma de `importe_con_signo_centavos` con esos dos `tipo_flujo` |
| **Cargas de prepago / paquete** | Dinero recibido por saldos o paquetes. Es entrada de dinero, **no** venta ni servicio | `bi_flujo_dinero`, `tipo_flujo = 'carga_prepago'` |
| **Reembolsos de prepago** | Dinero devuelto por saldo no utilizado | `bi_flujo_dinero`, `tipo_flujo = 'reembolso_prepago'` |
| **Otros ingresos y egresos de caja** | Movimientos manuales con concepto y pagos como los de comisiones; no son cobro de venta | `bi_flujo_dinero`, `tipo_flujo IN ('otro_ingreso','otro_egreso')` |
| **Flujo neto de caja** | Σ entradas − Σ salidas de todos los `tipo_flujo` | `bi_flujo_dinero`, suma de `importe_con_signo_centavos` |
| **Aplicación de prepago / paquete** | Saldo ya cobrado que se usa para pagar una venta. `ingresa_dinero = 0` siempre. Se separa **paquete** de **saldo/regalo** | `bi_aplicaciones_saldo` |
| **Venta neta (con impuesto)** | Total de las líneas de venta confirmadas − devoluciones comerciales, cada una en su fecha | `bi_venta_lineas` (primer corte) y `bi_devoluciones_venta` |
| **Nota de crédito** | Documento fiscal que respalda devoluciones comerciales; métrica propia, con su base, IVA y total | `bi_notas_credito` |

## Fechas y ejes de análisis (nombres)
Las devoluciones se pueden mirar de dos maneras y cada columna lo dice en su nombre:
* **Por fecha del movimiento**: `fecha_movimiento` (cuándo salió el dinero) en `bi_flujo_dinero` y `fecha_movimiento_devolucion` en `bi_devoluciones_dinero`.
* **Por venta original**: `venta_original_id`, `fecha_venta_original` y `fecha_cobro_original` (cuándo se cobró lo que ahora se devuelve). Permite ver, p. ej., cuánto de lo vendido en septiembre se devolvió en octubre.
* En las devoluciones comerciales (`bi_devoluciones_venta`): `fecha_devolucion` y `fecha_venta_original`.

## Exclusiones (todas las vistas de dinero)
* **Pagos simulados**: proveedor `mock` o vacío (misma regla que los reportes existentes). Nunca cuentan como dinero.
* **Transferencias no verificadas**: un pago con verificación `pendiente_verificacion` o `rechazado` no es dinero. En el ERP la caja solo se asienta al confirmar; las vistas lo vuelven a exigir por si existiera un asiento previo a la verificación.
* **Cargas de prepago por transferencia** pendientes o rechazadas: no tienen asiento en caja y por tanto no entran.
* **Devoluciones a saldo de prepago** (restitución): no son salida de dinero; aparecen en `bi_devoluciones_dinero` con `sale_de_caja = 0` y en `bi_aplicaciones_saldo` como restitución.

## Grano y control de duplicados al unir
Cada vista tiene **una fila por hecho** con su identificador único, y todas las uniones son 1 a 0..1 por clave única, así que ninguna importe se multiplica:
| Vista | Una fila por | Uniones (todas por clave única) |
| --- | --- | --- |
| `bi_flujo_dinero` | movimiento de caja | `pagos` (id), `erp_pago_aplicaciones` (pago_id), `erp_prepago_movimientos` (caja_id único), `erp_ventas` (id) |
| `bi_aplicaciones_saldo` | movimiento de aplicación o restitución de prepago | `erp_paquetes` (prepago_id único) |
| `bi_devoluciones_dinero` | devolución de cobro (`cobros_devoluciones`) | `pagos` (id), `erp_pago_aplicaciones` (pago_id), `erp_ventas` (id) |
| `bi_devoluciones_venta` | devolución comercial por línea | `erp_ventas` (id), `erp_fiscal_nc_devoluciones` (devolucion_id único) |
| `bi_notas_credito` | nota de crédito fiscal | totales por documento calculados aparte (sin multiplicar por líneas) |

Los pagos de citas del módulo anterior (sin venta ERP) conservan `cita_origen_id` y tienen `venta_original_id` nulo; no se inventa la venta.

## Reconciliación con los registros existentes (pruebas)
1. Σ de `bi_flujo_dinero` (entradas y salidas de dinero real) = Σ `caja_movimientos` menos las filas de pagos simulados o no verificados.
2. Cada pago en efectivo `completado` y confirmado tiene exactamente un ingreso en `bi_flujo_dinero` (y ninguno si es simulado o está pendiente).
3. Σ salidas `devolucion_de_cobro` = Σ `bi_devoluciones_dinero` con `sale_de_caja = 1`; y Σ de `bi_devoluciones_dinero` con `retorna_a_saldo_prepago = 1` = Σ restituciones de `bi_aplicaciones_saldo`.
4. Σ aplicaciones de saldo (`bi_aplicaciones_saldo`, aplicación − restitución) = Σ `pagos` con método `prepago` (no entran en `bi_flujo_dinero`).
5. Por venta: dinero devuelto ≤ lo cobrado y ≤ la devolución comercial; la devolución comercial coincide con las líneas (`devuelto_milesimas` ↔ `bi_devoluciones_venta`).
6. Notas de crédito: total de `bi_notas_credito` = Σ de sus líneas fiscales y no aparece en `bi_flujo_dinero`.

## Límites y decisiones pendientes (no bloquean el modelo)
* **Responsables de cada indicador** y aprobación del glosario: pendiente, necesaria solo para publicar tableros.
* **Política de devoluciones en la venta neta sin impuesto**: la devolución comercial se guarda con impuesto; la base y el IVA exactos solo existen en las notas de crédito. Hasta definir la política, la venta neta es **con impuesto**.
* Los cobros del módulo anterior de citas se identifican por cita, no por venta ERP.
* Los pagos de pasarela reales no generan asiento en `caja_movimientos` en el módulo actual; si se activaran, se incorporarán con su propia regla y verificación.

# Saldo por cobrar para BI (migración 060)

Cuarto corte del modelo de lectura para Power BI (ver `docs/BI_MODELO_LECTURA.md`, `docs/BI_COBRO_NETO.md` y `docs/BI_ATENCIONES_OCUPACION.md`). Responde **cuánto deben los clientes por ventas confirmadas, hoy y a cualquier fecha de corte**, con las mismas reglas que el sistema (`Services\ErpVentaService::resumen`) y el informe CxC (`Services\ErpInformeService`), contra los que las pruebas reconcilian.

Mismas garantías que los cortes anteriores: solo lectura (la prueba verifica que ninguna vista admite `UPDATE` ni `DELETE`), `SQL SECURITY DEFINER` (el usuario de BI necesita `SELECT` solo sobre las vistas), sin datos personales (sin comprador, contacto, referencias ni notas) y una fila por hecho.

## Definición
**Saldo por cobrar de una venta confirmada** = `máx(0, total − devolución comercial − cobrado + devuelto)`, donde:

| Componente | Qué es | Fuente |
| --- | --- | --- |
| **Total** | Total de la venta al confirmarla | `erp_ventas.total_centavos` |
| **Devolución comercial** | Lo devuelto en mercancía o servicio: reduce lo que se debe, sin mover dinero | `erp_devoluciones` |
| **Cobrado** | Pagos aplicados a la venta y completados (o reembolsados después): efectivo, transferencia **verificada** y también la **aplicación de un prepago o paquete**, que cancela la deuda sin ser dinero nuevo (`es_aplicacion_de_saldo = 1`) | `erp_pago_aplicaciones` + `pagos` |
| **Devuelto** | Dinero devuelto, o lo restituido al saldo de prepago, de un cobro: la deuda se restablece | `cobros_devoluciones` (y el remanente de un pago reembolsado por completo sin filas de devolución) |

* **No cuentan como cobro**: pagos simulados (proveedor `mock` o vacío) y transferencias pendientes o rechazadas. Una transferencia declarada y sin verificar aparece aparte como `por_verificar_centavos`: es dinero **esperado**, no cobrado, y no reduce el saldo hasta que se verifica (entonces entra con la fecha de la verificación).
* **Saldo a favor del cliente** (`saldo_a_favor_cliente_centavos`): si se cobró más de lo que ahora corresponde (p. ej. una devolución comercial sin devolver el dinero), el exceso se muestra aparte; el saldo por cobrar nunca es negativo (piso en 0 por venta, igual que el sistema).
* **Solo ventas que llegaron a confirmarse**: un borrador (anulado o no) nunca fue deuda. Una venta confirmada y luego **anulada** (el sistema solo lo permite sin cobros netos ni pendientes) conserva toda su historia y se compensa con una fila `anulacion` fechada el día de la anulación (evento `venta_anulada`; sin evento, el día de la confirmación), así que los cortes anteriores a la anulación todavía ven la deuda. La cartera actual (`bi_saldo_por_cobrar_venta`) no la incluye, igual que el informe CxC.
* **Sin vencimiento**: las ventas no guardan fecha de vencimiento, así que la antigüedad se mide desde la fecha de la venta. No se inventan plazos.

## Vistas y grano
### `bi_cxc_movimientos` — una fila por hecho que cambia lo que se debe
`origen_hecho` y `hecho_id` (juntos identifican el hecho: `venta`, `devolucion_comercial`, `pago`, `devolucion_de_cobro` y `reembolso_total`, que es el remanente de un pago reembolsado por completo sin todas sus filas de devolución), `tipo_movimiento`, `fecha`, `venta_id`, `importe_centavos` (positivo), `importe_con_signo_centavos` (+ aumenta la deuda: la venta y la devolución de un cobro; − la reduce: la devolución comercial y el cobro), `metodo`, `es_aplicacion_de_saldo`.

Cada hecho lleva **su propia fecha**: la venta, su fecha de confirmación; el cobro, la fecha en que se confirmó (la de la verificación en las transferencias); la devolución comercial y la de cobro, la del movimiento. Por eso el saldo a un corte se reconstruye con exactitud.

### `bi_saldo_por_cobrar_venta` — una fila por venta confirmada, con su saldo **actual**
`venta_id`, `fecha_venta`, `total_original_centavos`, `devolucion_comercial_centavos`, `total_neto_centavos`, `cobrado_centavos`, `devuelto_centavos`, `saldo_por_cobrar_centavos`, `saldo_a_favor_cliente_centavos`, `por_verificar_centavos`.

## Saldo a una fecha de corte (DAX)
El piso en 0 es por venta, así que el saldo a un corte **no** es la suma simple de los movimientos: se calcula venta por venta.

```DAX
Saldo por cobrar al corte =
SUMX (
    VALUES ( bi_cxc_movimientos[venta_id] ),
    MAX ( 0, CALCULATE ( SUM ( bi_cxc_movimientos[importe_con_signo_centavos] ),
                         bi_cxc_movimientos[fecha] <= MAX ( dim_fecha[fecha] ) ) )
)
```

Sobre `bi_saldo_por_cobrar_venta` (saldo de hoy) basta `SUM ( [saldo_por_cobrar_centavos] )`. La antigüedad por tramos usa `fecha_venta` contra la fecha de corte (la dimensión fecha es un pendiente del plan).

## Reconciliación (pruebas)
`ErpBiSaldoPorCobrarIntegrationTest` (8 pruebas, MySQL real, flujos reales del sistema):
* Saldo de cada venta = `ErpVentaService::resumen()['saldo']`; total = informe CxC existente; valores esperados calculados a mano (venta pagada y devuelta, parcial, sin cobro, con transferencia sin verificar, con prepago).
* Saldo reconstruido desde los movimientos a la fecha actual = saldo actual; a fechas intermedias (fechas fijas: venta, dos cobros, devolución comercial) cada tramo coincide con el cálculo a mano; un cobro posterior no altera el saldo anterior.
* Pago simulado y pago sin verificar aplicados a una venta: no reducen la deuda; transferencia real: pendiente → verificada reduce el saldo; rechazada lo deja intacto.
* Prepago: la aplicación reduce la deuda y se marca como saldo aplicado; devolver la venta restituye al saldo de prepago y la deuda queda en cero.
* Pago reembolsado sin filas de devolución (histórico): se reconoce por su fecha de reembolso; con devoluciones parciales registradas no se cuenta dos veces.
* Venta anulada: conserva su deuda hasta el día de la anulación (la prueba falla sin la fila `anulacion`: 0 en vez de 2000 el día anterior) y la anulación de un borrador no deja rastro.
* Una fila por hecho; una venta confirmada = un cargo = una fila de saldo; pagos idénticos no se colapsan; borradores y anuladas fuera.
* Usuario real de MySQL con `SELECT` solo sobre las dos vistas: las lee, no lee ninguna tabla de origen, no escribe; ninguna vista es actualizable; sin columnas ni valores de datos personales.
* Mutaciones comprobadas (cada una rompe la prueba): contar pagos simulados o sin verificar como cobro, fechar el cobro con la fecha de la venta, quitar el piso en 0, invertir el signo de la devolución de cobro.

## Límites conocidos
* Un pago simulado o sin verificar aplicado a una venta es un dato anómalo (el sistema no los aplica a ventas): el BI sigue la regla del propietario y no los cuenta, aunque `ErpVentaService::resumen` sí contara un pago `completado`.
* Sin plazos de crédito no hay «vencido» ni «por vencer»; sería una decisión de producto (plazo por cliente o por venta) antes de agregar esa columna.
* Cuentas por cobrar del libro contable (asientos) y este saldo comercial son dos mediciones distintas del mismo hecho; su conciliación vive en el control de contabilidad, no aquí.

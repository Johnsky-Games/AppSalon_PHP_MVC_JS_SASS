# Finanzas, inventario y comisiones

## Recepción manual y caja

`/admin/finanzas` exige `finanzas.gestionar`. Registre una entrada o salida con medio efectivo/transferencia y referencia del comprobante. Una recepción manual declara que el personal verificó realmente el dinero: **no ejecuta una transferencia ni emite factura fiscal**.

Para cobrar una cita, indique su ID. El servidor calcula el importe completo usando los precios históricos de sus servicios; no acepta el importe del formulario como fuente del cobro. Rechaza citas canceladas/no asistidas, pagos ya completados y operaciones pendientes para evitar dobles cobros. El recibo queda en `pagos`, proveedor `manual`, separado de los pagos simulados.

La devolución manual requiere la referencia `MAN-...` y evidencia de que ya se devolvió el dinero. Se registra el egreso y el estado `reembolsado` atómicamente. Solo se admiten devoluciones completas. Una pasarela de pruebas no puede reembolsar ni modificar ese recibo manual.

Los movimientos son inmutables. El saldo acumulado mezcla medios de recepción: **no equivale al efectivo físico de la caja**. No hay arqueo por denominación ni conciliación bancaria automática. Los importes se almacenan en centavos y las fechas operativas usan America/Guayaquil.

## Comisiones

Configure un porcentaje por profesional (0 a 100, dos decimales). Se aplica exclusivamente a nuevos cobros manuales verificados; no se devengan retrospectivamente pagos antiguos o simulados. La comisión conserva profesional, base histórica, porcentaje y referencia del pago. El redondeo es a centavo, mitad hacia arriba, usando enteros.

La liquidación agrupa los movimientos pendientes hasta el corte elegido, siempre compensando ajustes negativos pendientes aunque sean posteriores al corte. Confirme solo después del abono real al profesional. Registra un egreso de caja, comprobante y relación de comisiones incluidas; no ejecuta transferencias ni sustituye nómina o cálculo fiscal.

Una devolución de pago con comisión pendiente anula esa comisión. Si ya estaba abonada, conserva la liquidación original y crea un ajuste negativo a compensar. No se modifica el abono histórico. Un saldo neto cero o negativo impide un nuevo egreso; el ajuste se compensa con futuras comisiones.

Una reprogramación posterior del profesional no reasigna el devengo histórico: corresponde al profesional asignado al cobrar. Para políticas de comisión por finalización del servicio, salario fijo o porcentajes distintos por servicio se requiere una extensión explícita.

## Inventario

`/admin/inventario` exige `inventario.gestionar`. Cree un producto con SKU único, unidad, mínimo y costo unitario. La existencia comienza en cero. Registre la recepción inicial como entrada y cada consumo como salida.

Las cantidades permiten tres decimales y se almacenan en milésimas de la unidad elegida. Un bloqueo transaccional impide salidas superiores al stock. Cada movimiento conserva saldo resultante, costo unitario y motivo. Los productos en mínimo o por debajo muestran alerta de reposición.

El movimiento de compra no crea automáticamente un egreso de caja; registre ese comprobante por separado. El costo es una referencia operativa, no una valorización contable FIFO, costo promedio, impuestos o ventas POS. La actual implementación no administra lotes, fechas de vencimiento ni reservas automáticas por cita.

## Integridad y pruebas

Las mutaciones exigen permiso y CSRF. Caja, inventario y liquidaciones usan identificadores de idempotencia por actor y huella del contenido: repetir la misma solicitud devuelve el registro original; reutilizar su clave con otro contenido se rechaza. Los saldos se bloquean y actualizan dentro de una transacción.

Pruebas: `tests/Integration/FinanzasInventarioIntegrationTest.php` y `tests/Unit/ValoresExactosTest.php`. Las migraciones `013` y `015` incorporan los módulos, y sus rollbacks eliminan únicamente estas tablas; revertirlos borra su historial, por lo que debe hacerse solo con respaldo y en un entorno de prueba o una reversión planificada.

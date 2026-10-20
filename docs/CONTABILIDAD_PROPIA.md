# Contabilidad propia (migración 043)

AppSalon lleva su propia contabilidad por partida doble, conectada a los hechos del negocio. Esta etapa es de **ensayo**: el plan de cuentas, los roles y las reglas son una política operativa que **debe validar el contador** antes de usarse como contabilidad oficial. No sustituye declaraciones tributarias, anexos ni estados financieros presentados a entidades de control.

## Modelo

* **Libro de solo anexar.** `erp_cont_asientos` y `erp_cont_lineas` tienen triggers que prohíben `UPDATE` y `DELETE`. Un asiento se corrige con un **reverso** (otro asiento de signo contrario, enlazado por `reversa_de_id`, único). Un reverso no se revierte: el hecho se registra de nuevo.
* **Partida doble garantizada por la base.** El encabezado lleva `total_centavos` y `n_lineas`; la última línea insertada comprueba que el debe y el haber sumen el total, y no se admiten más líneas que las anunciadas. Cada línea tiene un valor positivo en el debe o en el haber. Todo en centavos enteros.
* **Identidad permanente por hecho económico.** `origen` es único: `outbox:<referencia>#n` (evento del negocio), `prestacion:v<venta>`, `manual:<huella>`, `reverso:<asiento>`, `cierre:<año>`. Repetir un hecho devuelve su asiento; reutilizar el origen con otro contenido se rechaza (409). El supervisor puede repetirse, caerse o reprocesar la cola sin duplicar.
* **Períodos.** Un asiento exige un período abierto que contenga su fecha (también lo comprueba un trigger). El cierre es en orden, exige que no haya hechos del negocio pendientes de contabilizar hasta el fin del período y que el libro esté íntegro. Reabrir exige motivo, solo el último cerrado, y queda en la auditoría. La numeración de asientos es continua y sin huecos (la reserva del número es parte de la misma transacción).
* **Inicio contable.** Hasta definirlo no se registra ningún asiento y la cola espera sin perder hechos. Los hechos anteriores a esa fecha se confirman sin asiento (`previo-a-inicio-contable`); el saldo de apertura se registra con un asiento de apertura (uno solo, con la fecha de inicio).
* **Plan de cuentas y roles.** Se instala un plan base editable. Las cuentas con movimientos conservan código, tipo y jerarquía; se desactivan, no se borran. Las reglas automáticas contabilizan contra un **rol** (`caja`, `banco`, `cxc`, `inventario`, `proveedores`, `anticipos_clientes`, `ingreso_servicios`, `iva_ventas`…), de modo que reasignar un rol afecta solo los asientos futuros.

## Permisos (segregación)

| Permiso | Qué permite | Roles |
| --- | --- | --- |
| `contabilidad.ver` | Libros, estados, conciliación | propietario, gerente, finanzas |
| `contabilidad.registrar` | Asientos manuales y reversos | propietario, finanzas |
| `contabilidad.configurar` | Inicio contable, plan de cuentas, roles, cierre y reapertura de períodos, cierre del ejercicio | propietario |

Recepción, profesional y el portal de cliente no tienen acceso (403 o redirección), aunque la cuenta sea propietaria en otro portal. Toda acción exige CSRF e idempotencia y deja evento de auditoría.

## Hechos económicos y asientos

Cada hecho distinto genera su asiento. Un evento con varios hechos genera varios asientos con sufijo (`#1`, `#2`…), todos en la misma transacción.

| Hecho (evento del negocio) | Asiento |
| --- | --- |
| Cobro de una venta, en efectivo o transferencia confirmada (`pago_confirmado`, `transferencia_confirmada`) | Dr caja/banco · Cr cuentas por cobrar del cliente. Si aún no hay ingreso, queda como saldo a favor del cliente |
| Carga de un saldo, regalo, paquete o ciclo (`prepago_cargado`) | Dr caja/banco · Cr anticipos de clientes. **No es una venta** |
| Aplicación del anticipo a una venta (`prepago_aplicado`) | Dr anticipos de clientes · Cr cuentas por cobrar |
| Reembolso de saldo sin utilizar (`prepago_reembolsado`) | Dr anticipos de clientes · Cr caja/banco |
| Entrega de productos (`productos_entregados`) | **Ingreso:** Dr CxC · Cr ingreso por productos · Cr IVA cobrado. **Costo (asiento aparte):** Dr costo de ventas · Cr inventario |
| Servicio prestado (cita completada; lo detecta el supervisor) | Dr CxC · Cr ingreso por servicios · Cr IVA cobrado, con origen `prestacion:v<venta>` |
| Devolución comercial (`venta_devuelta`) | Reverso del ingreso **solo por lo ya reconocido** (producto entregado o servicio ya prestado); reintegro de existencias al costo si hubo; y **cada devolución de dinero o restitución de saldo, un asiento propio** |
| Recepción de compra (`compra_recibida`) | Dr inventario · Cr recepciones por facturar |
| Factura del proveedor (`factura_proveedor`) | Dr recepciones por facturar · Dr IVA pagado en compras (estimado con la proporción de la orden) · Cr proveedores |
| Pago al proveedor o de una comisión (`obligacion_pagada`) | Dr proveedores (o comisiones por pagar) · Cr caja/banco |
| Devolución al proveedor, crédito y reintegro (`compra_devuelta`, `proveedor_credito`, `proveedor_reintegro`) | Ajustan inventario, recepciones por facturar, proveedores y caja/banco |
| Comisión liquidada por pago directo (`comision_liquidada`) | Dr gasto de comisiones · Cr comisiones por pagar. La que va por nómina **no** pasa por aquí: se contabiliza con la nómina |
| Insumos consumidos (`insumos_consumidos`) | Dr consumo de insumos · Cr inventario, al costo del movimiento |
| Activo registrado (`activo_registrado`) | Dr activos fijos · Cr recepciones por facturar |

**Reglas deliberadas.** La venta confirmada (`venta_confirmada`) se confirma **sin asiento**: es un compromiso comercial; el ingreso nace con la entrega o la prestación y el dinero con el cobro. Un evento sin regla no se descarta: queda en estado «fallido» en Integraciones y bloquea el cierre del período. Un hecho de un período ya cerrado se contabiliza con la fecha de hoy y su concepto conserva la fecha original.

## Cierres y estados

* **Cierre del ejercicio:** asiento `cierre:<año>` fechado el 31 de diciembre que traslada ingresos, costos y gastos al resultado del ejercicio (exige enero a noviembre cerrados y diciembre abierto). El estado de resultados no cuenta el asiento de cierre; el balance general incluye el resultado aún sin cerrar y siempre cuadra.
* **Libros:** diario con reversos enlazados, mayor con saldo inicial y corrido, balance de comprobación, estado de resultados, balance general y auxiliar por tercero (cuentas por cobrar, proveedores, anticipos).
* **Conciliación** (`/gestion/contabilidad?vista=conciliacion`): proveedores contra las obligaciones, comisiones por pagar contra las obligaciones de comisión, anticipos contra el libro de saldos prepagados y caja contra los movimientos de caja en efectivo. Una diferencia nunca se ajusta sola.

### Reglas de cierre (revisión de la PR #13)

* **Cierre de período:** todo mes desde el inicio contable es predecesor y debe estar cerrado, tenga o no movimientos (se materializan al cerrar). Se rechaza si hay hechos del negocio sin contabilizar o servicios prestados sin asiento de prestación hasta el fin del período.
* **Cierre del ejercicio:** exige además que no haya hechos ni servicios sin contabilizar hasta el 31 de diciembre. Con el ejercicio cerrado (asiento de cierre sin reverso) no se admite ningún otro asiento fechado en ese año; para registrar algo omitido se revierte el cierre (siempre con fecha 31 de diciembre) y se vuelve a cerrar (`cierre:<año>:2`, `:3`…).
* **Mayor:** el saldo final se calcula sobre todo el rango; el listado se acota a 2000 renglones y lo avisa (`truncado`, `total_movimientos`).
* **Conciliación a una fecha:** libro y auxiliares se calculan con sus movimientos fechados hasta el fin del día de corte (facturas, créditos, pagos y reintegros de proveedor; obligaciones de comisión; movimientos prepagados; efectivo de caja). Los contadores de hechos y servicios sin contabilizar son siempre el pendiente actual.

## Puesta en marcha

1. Actualizar y migrar con respaldo previo (en Docker: `docker compose --env-file includes/.env -p appsalon-validacion run --rm --no-deps -T web php database/migrador.php up`, con `web` detenido, incluye 043; el procedimiento completo está en `IMPLEMENTACION_ERP_Y_VALIDACION.md`).
2. Con el propietario: `/gestion/contabilidad` → definir la fecha de inicio contable; revisar el plan de cuentas con el contador y, si hace falta, reasignar roles.
3. Registrar el asiento de apertura con los saldos iniciales (opcional pero recomendado).
4. El supervisor `scripts/procesar_erp.php` contabiliza la cola y detecta los servicios prestados; los hechos anteriores a la fecha de inicio se confirman sin asiento.

## Límites conocidos

* **Ingresos y egresos manuales de caja** y los **ajustes manuales de inventario** todavía no tienen regla contable: la conciliación de caja los muestra como diferencia.
* El IVA pagado en compras se estima con la proporción de la orden hasta que la factura del proveedor registre su desglose.
* Sin retenciones, anexos tributarios, depreciación automática, centros de costo, presupuestos, multimoneda ni conciliación bancaria contra extractos.
* La nómina propia (siguiente etapa) agregará su contabilización; hoy solo se contabiliza la comisión pagada de forma directa.
* Las políticas de reconocimiento (ingreso a la entrega o prestación, gasto de comisión al liquidar) son una propuesta de ensayo sujeta a la validación del contador.

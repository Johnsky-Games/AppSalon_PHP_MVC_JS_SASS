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

* Los **ajustes de inventario aprobados** se contabilizan con conceptos configurables (migración 055, ver «Ajustes de inventario: conceptos configurables»); los anteriores a 055 no se reclasifican. Los **movimientos manuales de efectivo** se contabilizan con conceptos configurables (migración 054, ver «Caja: conceptos configurables»); los movimientos anteriores no se reclasifican y la conciliación de caja los muestra como diferencia. Los ingresos y egresos manuales del módulo financiero heredado (`FinanzasService`, p. ej. transferencias recibidas sin cita) siguen sin regla contable.
* El IVA pagado en compras se estima con la proporción de la orden hasta que la factura del proveedor registre su desglose.
* Sin retenciones, anexos tributarios, depreciación tributaria (la depreciación contable por política está en `docs/DEPRECIACION_ACTIVOS.md`), centros de costo, presupuestos ni multimoneda. La conciliación bancaria contra extractos existe desde la migración 051 (ver «Tesorería bancaria»); no hay importación automática de archivos de cada banco ni asientos generados desde el extracto.
* La nómina propia (siguiente etapa) agregará su contabilización; hoy solo se contabiliza la comisión pagada de forma directa.
* Las políticas de reconocimiento (ingreso a la entrega o prestación, gasto de comisión al liquidar) son una propuesta de ensayo sujeta a la validación del contador.

## Tesorería bancaria (migración 051)

Pestaña **Tesorería** de Contabilidad (`/gestion/contabilidad?vista=tesoreria`). Concilia el extracto del banco con el libro de la cuenta contable del banco; **no crea ni modifica asientos**: lo que está en el extracto y no en el libro se registra con un asiento manual y después se concilia. Una diferencia nunca se ajusta sola.

* **Cuenta bancaria** (`contabilidad.configurar`): se liga a una cuenta contable de activo, imputable y activa, una por cuenta. Solo se guardan los últimos 2 a 6 dígitos del número; el número completo no se almacena.
* **Extracto** (`contabilidad.registrar`): texto con un renglón por movimiento, `AAAA-MM-DD;descripción;referencia;monto` (monto con signo y punto decimal; positivo entra al banco, negativo sale; referencia opcional; encabezado opcional; UTF-8; hasta 2000 renglones y 512 KB). Se informan período y saldos inicial y final: **saldo inicial + movimientos debe ser el saldo final** (si no, se rechaza con la diferencia), todas las fechas caen dentro del período, el extracto empieza después del anterior y su saldo inicial es el final del anterior. Importar dos veces el mismo contenido devuelve el extracto ya cargado. Extractos y renglones son de solo anexar (triggers).
* **Conciliación**: un vínculo vigente por renglón del extracto y por línea del libro (lo garantiza la base con una columna generada única). El importe y el sentido deben coincidir **exactamente**; las fechas, dentro de **5 días** (criterio operativo, no una regla legal) o con una nota que explique la diferencia. Deshacer exige motivo y conserva la historia (la fila pasa de vigente a deshecha; nada más cambia ni se borra).
* **Conciliar lo inequívoco**: vincula solo un renglón con un único candidato (mismo importe y sentido, dentro de la ventana) que a su vez no sea candidato de otro renglón. Lo ambiguo (p. ej. dos cobros iguales el mismo día) se decide a mano.
* **Informe** al cierre del último extracto: saldo del banco, saldo del libro, diferencia, extracto sin registrar en el libro, libro sin aparecer en el extracto (partidas en tránsito) y la diferencia de apertura (saldo inicial del primer extracto frente al libro anterior). Se comprueba la identidad `banco − libro = apertura + extracto sin registrar − libro sin extracto`; si no se cumple se informa como incoherencia. «Conciliada» exige además apertura en cero y ninguna partida pendiente.
* **Límites**: no hay importación de formatos propios de cada banco (solo el texto descrito), ni conciliación uno-a-muchos (un depósito que agrupa varios cobros se registra como un asiento) ni generación automática de asientos a partir del extracto; los ingresos y egresos manuales de caja siguen sin regla contable. El nombre, la entidad y la descripción del extracto son datos del propio negocio y se muestran escapados; la auditoría no copia las descripciones de los movimientos.

## Caja: conceptos configurables (migración 054)
Un movimiento de dinero no es por sí mismo ingreso ni gasto. Cada **movimiento manual de efectivo** (Mi caja → «Registrar movimiento manual de efectivo») se clasifica con un **concepto** que el negocio crea en Contabilidad → Caja y conceptos y asocia a una cuenta de **su** plan contable. El sistema no siembra conceptos ni códigos de cuenta; solo valida que la cuenta exista, sea imputable y activa y sea de un tipo que la naturaleza admite:

| Naturaleza | Sentido en caja | Cuenta admitida |
| --- | --- | --- |
| Aporte del propietario | entra | patrimonio |
| Retiro del propietario | sale | patrimonio |
| Transferencia de caja a banco (depósito) | sale | activo (distinta de la cuenta de caja) |
| Transferencia de banco a caja (retiro) | entra | activo (distinta de la cuenta de caja) |
| Gasto pagado en efectivo | sale | gasto o costo |
| Ajuste por sobrante | entra | ingreso |
| Ajuste por faltante | sale | gasto |

* **Asiento:** entra efectivo → Dr caja · Cr cuenta del concepto; sale efectivo → Dr cuenta del concepto · Cr caja. El sentido lo fija el concepto.
* **Se exige:** concepto activo, motivo (≥ 5 caracteres) y el permiso `erp.caja.movimientos`; crear conceptos, asignar cuenta y activar o desactivar exigen `contabilidad.configurar`.
* **Si falta configuración** (concepto sin cuenta o con cuenta inválida): el movimiento se registra en caja y **solo su contabilización queda pendiente**; la pestaña muestra qué debe completarse. Al asignar la cuenta, los pendientes de ese concepto se reintentan de inmediato.
* **Reverso** (`contabilidad.registrar` y una caja propia abierta): registra el movimiento contrario y revierte el asiento (si el original fue anterior al inicio contable y no tiene asiento, el reverso se contabiliza como un movimiento actual de sentido contrario); el original se conserva, se reversa una sola vez y un reverso no se reversa.
* **Sin duplicados:** cada movimiento tiene una sola clasificación (clave primaria) y un solo asiento (origen único por evento). Los pagos de obligaciones, devoluciones y cobros siguen su propio flujo; este concepto es para dinero movido a mano.
* **Auditoría:** eventos `caja_concepto_creado`, `caja_concepto_cuenta`, `caja_concepto_estado`, `caja_movimiento` y `caja_movimiento_reversado` con actor; conceptos solo cambian de cuenta y estado; las clasificaciones no se editan ni se borran.
* **Límites:** no se reclasifican movimientos históricos (queda pendiente una acción explícita, movimiento por movimiento); el módulo financiero heredado (`FinanzasService`) no pasa por conceptos; los ajustes de inventario siguen sin regla.

## Ajustes de inventario: conceptos configurables (migración 055)
Un ajuste de stock no es por sí mismo pérdida ni ganancia. La solicitud de ajuste (`ajuste_stock`) exige ahora un **concepto** activo, de sentido coincidente, que el negocio crea en Contabilidad → Ajustes de inventario y asocia a una cuenta de **su** plan; no se siembran conceptos ni códigos de cuenta.

| Naturaleza | Sentido | Cuenta admitida |
| --- | --- | --- |
| Merma o pérdida | disminuye stock | gasto o costo |
| Faltante de conteo físico | disminuye stock | gasto o costo |
| Sobrante de conteo físico | incrementa stock | ingreso o costo |
| Uso interno u obsequio | disminuye stock | gasto |

* **Asiento al aprobar:** valorizado con el costo del propio movimiento de inventario (foto al aprobar; cantidad × costo, redondeo comercial). Disminuye → Dr cuenta del concepto · Cr inventario; incrementa → Dr inventario · Cr cuenta del concepto. La cuenta de la contrapartida no puede ser la de inventario.
* **Bloqueo acotado:** si el concepto no tiene cuenta válida, el ajuste queda aprobado y aplicado al stock, y solo su contabilización queda pendiente; la pestaña muestra qué completar y, al asignar la cuenta, se reintenta de inmediato.
* **Corrección:** un ajuste se corrige con otro ajuste contrario aprobado (el inventario es de solo anexar), cada uno con su concepto y su asiento.
* **Sin duplicados ni reclasificación:** una clasificación por movimiento (clave primaria) y una por solicitud (única); las solicitudes creadas antes de 055 (sin concepto) se aprueban como antes y no se contabilizan solas.
* **Permisos y auditoría:** crear conceptos, asignar cuenta y activar o desactivar = `contabilidad.configurar`; solicitar = `erp.ventas.crear`; aprobar = `erp.aprobaciones` + `inventario.gestionar`. Eventos `inv_concepto_*` e `inventario_ajuste` con actor; conceptos solo cambian cuenta y estado; clasificaciones inmutables.
* **Límites:** el módulo de inventario heredado (`InventarioService`, movimientos directos fuera de la solicitud) y las ventas, consumos y compras siguen su propio flujo contable; no hay control de conciliación libro-inventario valorizado todavía.


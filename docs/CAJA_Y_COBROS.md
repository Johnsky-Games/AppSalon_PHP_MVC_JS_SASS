# Caja y cobros desde la cita (ronda 5)

Alcance: cobrar una cita sin teclear su ID, con anticipos y saldo, efectivo y transferencia, devoluciones, comisiones coherentes, comprobante interno y exportación. Implementado en `CobroCitaService`, `ComprobanteService`, `CajaController` y `views/caja/*`; migraciones **024** (cobros y verificación) y **025** (comprobantes). La atención de la cita y su estado financiero son **independientes**: finalizar una atención nunca marca «pagado».

## 1. Quién puede hacer qué

| Acción | Permiso del servidor |
|---|---|
| Ver caja, cobrar, devolver, verificar/rechazar transferencias, ver y exportar | `finanzas.gestionar` (propietario, gerente, finanzas) |
| «Finalizar y cobrar» desde la agenda | `agenda.gestionar` **y** `finanzas.gestionar` (propietario, gerente) |
| Recepción | Puede finalizar la atención; **no** cobra (no se amplió ningún permiso). Decisión de negocio pendiente: si recepción debe cobrar, hay que crear un permiso propio. |
| Profesional / cliente / sesión de cliente de una persona con doble membresía | 403 (no hay acceso a `/caja*`) |

## 2. Dinero y estados

* Todo el dinero se calcula en **centavos enteros** (`CobroCalculo`); el total de la cita es la suma de los **precios históricos** de `citasservicios`, nunca del catálogo actual.
* `saldo = total − cobrado confirmado + devuelto`. «Disponible para cobrar» además descuenta lo que está por verificar, para no sobrecobrar con transferencias pendientes.
* Estado financiero de la cita (`citas.estado_pago`): `pendiente` · `parcial` · `por_verificar` · `pagado` · `reembolsado`.
* No se puede cobrar más que el saldo disponible ni devolver más de lo cobrado en ese pago (devoluciones **por importe**, parciales o totales, acumulables).
* Solo se cobran citas `confirmada`, `en_servicio` o `completada`. Una cita cancelada o con ausencia no admite nuevos cobros; si tiene anticipos, la pantalla los marca como «anticipo sin resolver» para que se devuelvan explícitamente (el sistema no los reembolsa solo).

## 3. Métodos

* **Efectivo.** Se indica el importe a aplicar y el efectivo recibido; la diferencia es el **cambio** y **no** es ingreso. En caja entra solo el importe aplicado.
* **Transferencia.** Exige referencia (única: la misma referencia no respalda dos cobros). Queda **por verificar**: no entra a caja, no devenga comisión y no marca pagado. Alguien con `finanzas.gestionar` la **verifica** o la **rechaza con motivo**. Separación de funciones: quien la registró **no** puede verificarla (salvo el propietario); verificar o rechazar queda auditado (quién, cuándo, nota) y no se puede hacer dos veces.
* **Pasarela simulada.** Se mantiene y sigue separada de los ingresos reales (`proveedor` distinto de `manual`).
* **Comprobante de transferencia adjunto:** no implementado. Basta la referencia (pendiente documentado).

## 4. Garantías

* Idempotencia persistente: cada formulario lleva una clave; el mismo envío repetido devuelve el mismo resultado y la misma clave con otros datos responde 409.
* La cita se bloquea (`FOR UPDATE`) en toda mutación financiera; pago, caja, comisión y estado de la cita se escriben en **la misma transacción**.
* El `cita_id` del formulario se revalida: no se puede imputar un pago a otra cita manipulándolo.
* Comisiones: se devengan al confirmarse el dinero, con la tarifa vigente de ese momento y **redondeo acumulado por cita y tarifa** (diez pagos de $0,15 al 10 % suman $0,15, no $0,20). Una devolución parcial revierte la comisión en proporción.
* Si el cobro falla después de finalizar la atención, la cita queda **atendida con saldo pendiente** y se puede reintentar sin duplicar.

## 5. Pantallas y rutas

`GET /caja` (citas atendidas y por cobrar, transferencias por verificar, filtros por fecha, cliente, profesional, método y estado, totales del universo filtrado) · `GET /caja/cita?id=N` (cliente, profesional, servicios con precios históricos, pagos, devoluciones, saldo, formulario de cobro) · `POST /caja/cita/cobrar` · `POST /caja/cita/devolver` · `POST /caja/pago/verificar` · `POST /caja/pago/rechazar` · `GET /caja/comprobante?id=N` · `GET /caja/exportar?formato=csv|xlsx`.
El «Volver» y las redirecciones salen del catálogo central de destinos (`includes/destinos.php`) y conservan el período consultado.

## 6. Comprobante interno

Identificador correlativo único (`CI-000001`…), fecha, cita, cliente, profesional, desglose con precios históricos, pagos y devoluciones, importes coherentes y botón de imprimir. La numeración se incrementa dentro de la transacción que emite el comprobante (sin huecos ni duplicados bajo concurrencia) y la tabla es de solo anexar con la instantánea y su SHA-256.
**Es un comprobante interno: no es factura electrónica ni está autorizado por el SRI.** La integración fiscal real **no existe** y queda como trabajo pendiente.

## 7. Exportación de caja

`/caja/exportar?formato=csv|xlsx` usa el exportador común (`classes/Exportacion`): el mismo universo filtrado que la pantalla, totales iguales, fórmulas neutralizadas, XLSX real, tope de filas con aviso visible y auditoría previa a la entrega (tabla `exportaciones_auditoria_general`, migración 026).

## 8. Ejemplo numérico

Cita de $40,00. Anticipo en efectivo de $15,00 (recibe $20,00, cambio $5,00) → estado **parcial**, saldo $25,00, comisión 10 % = $1,50. Transferencia de $25,00 con referencia → **por verificar** (saldo disponible $0, pero aún no es dinero). Otra persona la verifica → **pagado**; caja suma $40,00 y la comisión total queda en $4,00. Se devuelven $10,00 → **parcial**, saldo $10,00, comisión neta $3,00. Cada paso genera su comprobante.

## 8 bis. Fechas y citas regularizadas

Cobrar una cita cuya atención se regularizó no retrodata nada: pago, caja, comprobante y comisión llevan la fecha REAL del cobro. `/caja` filtra por el día de la cita (lo dice la pantalla); el efectivo por fecha real se concilia en Finanzas. Ver `docs/R5_REGULARIZACION.md` §3.

## 9. Límites y pendientes

Factura fiscal electrónica; adjuntar comprobante de transferencia; permiso específico para que recepción cobre; conciliación contra extractos bancarios reales (hoy la verificación es manual).

## Continuidad: arqueo común de Caja y PDV

`CajaArqueoService` enlaza los nuevos cobros por cita, devoluciones, movimientos libres y pagos de comisiones con la sesión abierta del operador que registra el dinero. El vínculo usa el mismo movimiento del libro general; no crea un segundo ingreso o egreso. Las transferencias solo se enlazan al confirmarse y no alteran el efectivo esperado. Una devolución o comisión se descuenta de la caja de quien entrega el dinero, aunque el cobro original lo registrara otra persona o sesión.

Cada operación retiene el bloqueo de la sesión hasta confirmar su movimiento y vínculo. El cierre usa ese mismo bloqueo; los retiros en efectivo comprueban el saldo mediante lectura actual, incluso bajo REPEATABLE READ. El repositorio propaga también los fallos al recibir resultados de SELECT: un interbloqueo no puede interpretarse como sesión inexistente. El reintento revierte y repite toda la transacción.

Compatibilidad explícita: las pantallas anteriores permiten movimientos sin sesión, que quedan únicamente en el libro general. Abrir «Mi caja» antes de operar permite incluirlos en el arqueo. El PDV sigue exigiendo apertura. No se importan movimientos anteriores ni se reasignan al abrir otra sesión; el fondo inicial ya representa el efectivo contado. Repetir una operación conserva su vínculo original. Los avisos de Caja y Finanzas explican esta regla y enlazan a «Mi caja».

No hay migración nueva ni alteración de datos históricos. El enlace utiliza las tablas introducidas en 028. No se cambia la autoridad de las reglas de comisión antiguas frente a las del ERP; esta revisión conecta su pago con el arqueo.

Regresión específica: diez pruebas de integración con 67 aserciones, incluidas dos conexiones concurrentes para retiros antiguos/PDV y cierre. Cubren cambio, transferencias, devolución repetida, insuficiencia de efectivo, pago de comisión, aislamiento entre operadores, reapertura y conservación del histórico. La suite principal y la QA independiente se registran por SHA en el PR; el resultado de un commit anterior no acredita esta continuación.

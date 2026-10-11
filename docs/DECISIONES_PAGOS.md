# Decisiones de Negocio Pendientes y Arquitectura de Pagos (Fase 6C)

Este documento registra la arquitectura técnica de pagos, las garantías de seguridad transaccional implementadas y las decisiones comerciales y operativas que deben ser definidas formalmente por la dirección del negocio antes de conectar un proveedor de cobros real en producción.

---

## 1. Estado Técnico Actual (Sin Lock-In Comercial ni Cobros Reales)

El módulo de pagos establece la **infraestructura técnica segura de pagos** bajo las siguientes garantías de ingeniería:

1. **Arquitectura desacoplada mediante `Interfaces\PaymentGatewayInterface`:**
   - El dominio (`Services\PagoService`) y la persistencia (`Repositories\PagoRepository`, `Model\Pago`) no dependen de ningún SDK ni contrato propietario (Stripe, Mercado Pago, PayPhone, Kushki, Datafast, etc.).
   - En desarrollo y pruebas se utiliza `Classes\MockPaymentGateway` (*sandbox/mock* local sin conexión a redes financieras ni cargos reales).
2. **Cálculo de importes exclusivamente en el servidor:**
   - El monto de cada transacción se calcula en el servidor sumando los precios históricos capturados en `citasservicios` (`COALESCE(cs.precio_servicio, s.precio)`). Cualquier monto enviado desde el navegador o cliente HTTP es ignorado.
3. **Cumplimiento estricto PCI-DSS (prohibición de datos de tarjeta):**
   - El servidor rechaza (`HTTP 422`) cualquier intento de enviar números de tarjeta (`PAN`), códigos de seguridad (`CVV`/`CVC`) o fechas de expiración, y sanea recursivamente `payload_proveedor` antes de persistir en MySQL.
4. **Prohibición de confirmación por redirección del navegador:**
   - El endpoint `GET /pago/resultado` es estrictamente informativo y lee el estado real desde MySQL. Ningún parámetro en la URL (`?status=completado`, `?pagado=1`, etc.) puede confirmar un pago; la transición a `'completado'` exige notificación servidor a servidor (`POST /api/pagos/webhook`) con firma HMAC-SHA256 válida.
5. **Huella de Operación e Idempotencia Cross-Tenant (`iniciarPago`):**
   - Se valida la huella de operación (`operacion_fingerprint`: coincidencia de `usuario_id`, `cita_id` y `monto`).
   - Si una clave de idempotencia (`idempotency_key`) ya existe en base de datos pero pertenece a otro usuario o a otra cita, el sistema responde inmediatamente con **HTTP 409 Conflicto** (`codigo_error: IDEMPOTENCY_KEY_CONFLICT`, `error: La clave de idempotencia ya fue utilizada para una operación diferente`), sin revelar información ni datos del pago ajeno.
   - Solo cuando la clave coincide exactamente con la misma cita y usuario se retorna la respuesta idempotente correspondiente.
6. **Máquina de Estados Explícita y Protección de Reembolsos:**
   - **Estado Terminal:** El estado `reembolsado` es estrictamente terminal. Un replay tardío de webhook con evento `completado` (o cualquier otro) **NUNCA puede revertir un pago que ya se encuentra en estado `reembolsado`**.
   - **Deduplicación:** Las notificaciones asíncronas se deduplican mediante `transaccion_externa_id` y registro de estado.
   - **Reembolsos Parciales Rechazados:** Dado que el modelo no admite descuadres parciales, cualquier solicitud de reembolso por un monto inferior al total pagado es rechazada con **HTTP 422** (`codigo_error: REEMBOLSO_PARCIAL_NO_PERMITIDO`).
7. **Prevención de Deadlocks y Orden Uniforme de Bloqueos Pesimistas:**
   - Para prevenir *deadlocks* cíclicos entre `iniciarPago`, `webhook` y `reembolsarPago` concurrentes en InnoDB, todas las transacciones siguen el orden estricto de adquisición de cerrojos:
     1. Bloquear primero la fila de la cita en `citas` (`FOR UPDATE` por `id`).
     2. Bloquear posteriormente la fila del pago en `pagos` (`FOR UPDATE` por `id` o `referencia_pago`).
8. **Protección Estricta de Entorno en `PagoController`:**
   - `PagoController::resolverGateway()` inspecciona `APP_ENV`, `PAYMENT_GATEWAY_PROVIDER` y `PAYMENT_WEBHOOK_SECRET`.
   - Si `APP_ENV === 'production'`:
     - Se prohíbe terminantemente instanciar o utilizar `MockPaymentGateway` (arroja `RuntimeException` con respuesta HTTP 500 de configuración).
     - Se rechazan secretos de webhook ausentes o con valores por defecto inseguros (`appsalon_mock_webhook_secret_2026`, `mock_secret_cambiar_en_produccion`).
   - Los importes de prueba se identifican explícitamente como simulados (`proveedor = 'mock'`).
9. **Manejo de Reintentos Tardíos, Saldo Acumulado y Conciliación Trazable:**
   - En situaciones donde una transacción $K_1$ falla inicialmente (timeout/fondos insuficientes), el cliente inicia un reintento $K_2$ y posteriormente ambas transacciones terminan siendo confirmadas exitosamente por la pasarela:
     - **Conservación Integral de Cobros:** El sistema **conserva ambos registros de cobro** en la base de datos con sus referencias, montos, timestamps y firmas de webhook para garantizar auditoría contable completa.
     - **Cálculo de Balance Acumulado:** El servidor computa el total pagado acumulado sumando todos los pagos en estado `completado` para la cita.
     - **Detección de Exceso de Cobro:** Si `total_pagado_acumulado > total_cita_esperado`, el sistema detecta formalmente el excedente (`exceso_cobro = total_pagado_acumulado - total_cita_esperado`) y marca `requiere_conciliacion = true`.
     - **Prohibición de Reembolsos Automáticos No Autorizados:** El sistema **NO dispara reembolsos automáticos** que pudieran ocasionar pérdidas de comisiones bancarias o revertir transacciones legítimas sin revisión humana. En su lugar, estampa una auditoría de conciliación en el campo `payload_proveedor` del pago excedente (`motivo: 'exceso_cobro_sobre_cita'`) e instruye conciliación manual por parte del administrador.

---

## 2. Decisiones de Negocio Pendientes de Definición con el Propietario

Antes de implementar un adaptador concreto de pasarela comercial en producción, la administración del salón debe definir las siguientes reglas de negocio:

### A. Política de Cobro Total vs. Anticipo / Seña al Reservar
- **Interrogante de negocio:** ¿Se exigirá el pago del **100% del importe** de la cita al momento de reservar, un **anticipo porcentual** (ej. 30% o 50% de seña para asegurar el turno) o un **monto fijo de reserva**, o bien el pago anticipado será **opcional** (permitiendo pagar en el local)?
- **Comportamiento actual:** La reserva de citas (`POST /api/citas`) permanece funcional sin exigir pago previo obligatorio (la cita nace con `estado_pago = 'pendiente'`), y cuando se inicia el flujo de pago (`POST /api/pagos/iniciar`), el servidor calcula el 100% del importe histórico de los servicios reservados.

### B. Ventana de Cancelación, Inasistencias (*No-Show*) y Política de Reembolsos
- **Interrogante de negocio:**
  1. ¿Con cuántas horas de anticipación en zona horaria `America/Guayaquil` (ej. 24 horas o 12 horas antes de `hora_inicio`) puede cancelar un cliente con derecho a **reembolso del 100%**?
  2. Si el cliente cancela fuera de la ventana permitida o no asiste (*no-show*), ¿se retiene el anticipo/pago parcial como penalización o se genera saldo a favor para reprogramación?
  3. ¿Los reembolsos se ejecutarán de forma **automática** al cancelar en `#mis-citas` o requerirán **aprobación manual** de un administrador?
- **Comportamiento actual:** `PagoService::reembolsarPago()` y `PaymentGatewayInterface::reembolsarPago()` proveen la capacidad técnica transaccional para ejecutar reembolsos totales y actualizar `pagos.estado = 'reembolsado'` y `citas.estado_pago = 'reembolsado'`, sin disparar reembolsos automáticos no autorizados al cancelar citas. Los reembolsos parciales están expresamente bloqueados (HTTP 422).

### C. Absorción de Comisiones de Pasarela e Impuestos Locales
- **Interrogante de negocio:**
  1. Las pasarelas de pago aplican una comisión por transacción (típicamente entre `2.9% + $0.30` y `4.5%` más IVA). ¿El salón absorberá íntegramente dicha comisión dentro del precio de lista del servicio o la reflejará en sus reportes contables netos?
  2. En caso de reembolso por cancelación voluntaria del cliente, muchas pasarelas no devuelven la comisión original de procesamiento. ¿Ese costo lo asume el salón o se descuenta del monto reembolsado?
  3. ¿Los precios del catálogo de servicios ya incluyen impuestos locales (IVA Ecuador) o requieren desglose tributario en el comprobante de pago?

### D. Selección Comercial de Proveedor y Conciliación Bancaria
- **Interrogante de negocio:**
  - Selección definitiva del proveedor según cobertura bancaria en Ecuador/Latinoamérica (p. ej. **PayPhone**, **Kushki**, **Datafast**, **Mercado Pago** o **Stripe**) y definición de la cuenta bancaria receptora, moneda oficial (`USD`) y plazos de liquidación.
  - Una vez elegido el proveedor y firmadas las condiciones comerciales, bastará con crear una clase que implemente `Interfaces\PaymentGatewayInterface` e inyectarla en `PagoService` mediante variables de entorno en `includes/.env`.

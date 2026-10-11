# Decisiones de Negocio Pendientes — Módulo de Pagos (Fase 6C)

Este documento registra la arquitectura técnica entregada en la **Fase 6C (`feature/fase-6c-base-pagos`)** y las decisiones comerciales y operativas que deben ser definidas formalmente por la dirección del negocio antes de conectar un proveedor de cobros real en producción.

---

## 1. Estado Técnico Actual (Sin Lock-In Comercial ni Cobros Reales)

La Fase 6C establece la **infraestructura técnica segura de pagos** bajo las siguientes garantías de ingeniería:

1. **Arquitectura desacoplada mediante `Interfaces\PaymentGatewayInterface`:**
   - El dominio (`Services\PagoService`) y la persistencia (`Repositories\PagoRepository`, `Model\Pago`) no dependen de ningún SDK ni contrato propietario (Stripe, Mercado Pago, PayPhone, Kushki, Datafast, etc.).
   - En desarrollo y pruebas se utiliza exclusivamente `Classes\MockPaymentGateway` (*sandbox/mock* local sin conexión a redes financieras ni cargos reales).
2. **Cálculo de importes exclusivamente en el servidor:**
   - El monto de cada transacción se calcula en el servidor sumando los precios históricos capturados en `citasservicios` (`COALESCE(cs.precio_servicio, s.precio)`). Cualquier monto enviado desde el navegador o cliente HTTP es ignorado.
3. **Cumplimiento estricto PCI-DSS (prohibición de datos de tarjeta):**
   - El servidor rechaza (`HTTP 422`) cualquier intento de enviar números de tarjeta (`PAN`), códigos de seguridad (`CVV`/`CVC`) o fechas de expiración, y sanea recursivamente `payload_proveedor` antes de persistir en MySQL.
4. **Prohibición de confirmación por redirección del navegador:**
   - El endpoint `GET /pago/resultado` es estrictamente informativo y lee el estado real desde MySQL. Ningún parámetro en la URL (`?status=completado`, `?pagado=1`, etc.) puede confirmar un pago; la transición a `'completado'` exige notificación servidor a servidor (`POST /api/pagos/webhook`) con firma HMAC-SHA256 válida.
5. **Idempotencia transaccional (`FOR UPDATE` en InnoDB):**
   - Tanto el inicio de pago (`POST /api/pagos/iniciar`) como la recepción de webhooks (`POST /api/pagos/webhook`) emplean bloqueos de fila `FOR UPDATE` y restricciones únicas (`uq_pagos_referencia`, `uq_pagos_transaccion_externa`, `uq_pagos_idempotency_key`), evitando cobros o confirmaciones duplicadas ante reintentos de red.

---

## 2. Decisiones de Negocio Pendientes de Definición con el Propietario

Antes de implementar un adaptador concreto de pasarela comercial en producción, la administración del salón debe definir las siguientes reglas de negocio:

### A. Política de Cobro Total vs. Anticipo / Seña al Reservar
- **Interrogante de negocio:** ¿Se exigirá el pago del **100% del importe** de la cita al momento de reservar, un **anticipo porcentual** (ej. 30% o 50% de seña para asegurar el turno) o un **monto fijo de reserva**, o bien el pago anticipado será **opcional** (permitiendo pagar en el local)?
- **Comportamiento actual en Fase 6C:** La reserva de citas (`POST /api/citas`) permanece funcional sin exigir pago previo obligatorio (la cita nace con `estado_pago = 'pendiente'`), y cuando se inicia el flujo de pago (`POST /api/pagos/iniciar`), el servidor calcula el 100% del importe histórico de los servicios reservados.

### B. Ventana de Cancelación, Inasistencias (*No-Show*) y Política de Reembolsos
- **Interrogante de negocio:**
  1. ¿Con cuántas horas de anticipación en zona horaria `America/Guayaquil` (ej. 24 horas o 12 horas antes de `hora_inicio`) puede cancelar un cliente con derecho a **reembolso del 100%**?
  2. Si el cliente cancela fuera de la ventana permitida o no asiste (*no-show*), ¿se retiene el anticipo/pago parcial como penalización o se genera saldo a favor para reprogramación?
  3. ¿Los reembolsos se ejecutarán de forma **automática** al cancelar en `#mis-citas` o requerirán **aprobación manual** de un administrador?
- **Comportamiento actual en Fase 6C:** `PagoService::reembolsarPago()` y `PaymentGatewayInterface::reembolsarPago()` proveen la capacidad técnica transaccional para ejecutar reembolsos y actualizar `pagos.estado = 'reembolsado'` y `citas.estado_pago = 'reembolsado'`, sin disparar reembolsos automáticos no autorizados al cancelar citas.

### C. Absorción de Comisiones de Pasarela e Impuestos Locales
- **Interrogante de negocio:**
  1. Las pasarelas de pago aplican una comisión por transacción (típicamente entre `2.9% + $0.30` y `4.5%` más IVA). ¿El salón absorberá íntegramente dicha comisión dentro del precio de lista del servicio o la reflejará en sus reportes contables netos?
  2. En caso de reembolso por cancelación voluntaria del cliente, muchas pasarelas no devuelven la comisión original de procesamiento. ¿Ese costo lo asume el salón o se descuenta del monto reembolsado?
  3. ¿Los precios del catálogo de servicios ya incluyen impuestos locales (IVA Ecuador) o requieren desglose tributario en el comprobante de pago?

### D. Selección Comercial de Proveedor y Conciliación Bancaria
- **Interrogante de negocio:**
  - Selección definitiva del proveedor según cobertura bancaria en Ecuador/Latinoamérica (p. ej. **PayPhone**, **Kushki**, **Datafast**, **Mercado Pago** o **Stripe**) y definición de la cuenta bancaria receptora, moneda oficial (`USD`) y plazos de liquidación.
  - Una vez elegido el proveedor y firmadas las condiciones comerciales, bastará con crear una clase que implemente `Interfaces\PaymentGatewayInterface` e inyectarla en `PagoService` mediante variables de entorno en `includes/.env`.

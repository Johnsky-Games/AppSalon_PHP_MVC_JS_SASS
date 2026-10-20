# Reconciliación del plan maestro con el estado del repositorio (19 de octubre de 2026)

Compara las fases F0–F9 de `PLAN_MAESTRO_ERP_SALON_2026-10-17.md` con lo que existe en `main` tras fusionar los PR #6 a #20. «Hecho» significa implementado y probado en ensayo; no significa certificado ni listo para producción. Sin expandir a un ERP industrial: lo no cubierto se lista con su siguiente paso.

| Fase | Estado | Evidencia / qué falta |
| --- | --- | --- |
| F0 Consistencia existente | Hecho en rondas anteriores | Rondas R2–R5b (acceso de clientes, idempotencia, portales, regularización de citas, eliminación protegida). Pendientes registrados: anonimización de usuarios, baja lógica de servicios, filtro de caja por fecha de cobro |
| F1 Base empresarial | Parcial | Empresa única, maestros, permisos finos por función. **Falta** multi-sede y precios/impuestos con vigencia por sede (hoy un solo establecimiento) |
| F2 Comercial y venta | Hecho | Ventas por línea, cotización, anticipos, devoluciones comerciales, comprador sin cuenta |
| F3 Stock y abastecimiento | Hecho salvo conteos | Compras, recepción parcial, devoluciones, reservas, consumo de insumos. **Falta** conteo físico de inventario y ajustes con motivo contabilizados |
| F4 Caja y pagos | Hecho salvo cuentas bancarias | Caja/turnos/arqueo, efectivo y transferencias verificadas, prepago, paquetes, suscripciones. **Falta** cuentas bancarias propias y conciliación contra extractos |
| F5 Fiscal y enlace contable | Hecho en ensayo (SRI pruebas) | Facturas, notas de crédito, sustitución, consumidor final, anticipos, parámetros por régimen. **Falta** certificación con emisor y certificado reales, facturar en el cobro y el límite porcentual de notas de crédito si se confirma |
| F5b Contabilidad | Hecho en ensayo | Libro por partida doble, períodos, cierre, reversos, estados, conciliaciones (#13). **Falta** manuales de caja, ajustes de inventario, retenciones, anexos tributarios, depreciación |
| F6 RH y remuneración | Hecho en ensayo | Jornada, asistencia, ausencias, contratos, comisiones por colaborador con vigencia, nómina propia (#14). IR de relación de dependencia calculado a partir de la tabla que registre el usuario (045; sin cifras de fábrica). Finiquito calculado con parámetros registrados (046). Utilidades repartidas desde el libro con porcentajes registrados (047). Ausencias con tipo y saldo de vacaciones integrado al finiquito (048). **Falta** planillas y avisos al IESS, formulario 107/anexos del SRI, jubilación patronal, retención de renta sobre indemnizaciones y utilidades, y contabilizar el destino del excedente de utilidades |
| F7 CRM y crecimiento | Parcial | Seguimientos, cupones de porcentaje con vigencia, mínimo y topes de uso por cliente; puntos por asistencia (no económicos); paquetes y suscripciones |
| F8 Gestión y BI | Parcial | Indicadores operativos y exportaciones; CxC/CxP en contabilidad. **Falta** presupuestos, modelo de lectura para Power BI y glosario de métricas |
| F9 Piloto | Pendiente | Requiere datos reales del emisor, ensayo de recuperación en el entorno del usuario y aceptación por rol |

## Puntos que se pidieron reconciliar

| Punto | Hoy | Siguiente paso concreto |
| --- | --- | --- |
| **Promociones avanzadas** | Solo cupón de porcentaje con vigencia, mínimo, usos totales y por cliente. No se acumula con paquetes | Monto fijo, alcance por servicio/producto/categoría, canal, prioridad y acumulación, tope por venta, explicación al operador de por qué aplica o no; reversas con la regla histórica |
| **Fidelización económica** | Puntos por cita completada (10 por cita, canje de 100): reconocen asistencia, **no son dinero** | Decidir política (puntos con valor, cashback o descuento diferido), registrarla como pasivo contable con vigencia y reversa; hoy no hay obligación contable por puntos |
| **Propinas** | No existen en las ventas. El XML fiscal admite el campo `propina` pero no se usa | Definir con el contador: no son ingreso del salón; entran por cobro, quedan como pasivo hasta repartirse (pago directo o nómina) y no forman parte de la base de IVA. Requiere decisión del propietario |
| **Reglas de comisiones** | Porcentaje por colaborador con vigencia; devengo tras prestación y cobro; liquidación por pago directo o nómina, nunca por ambos | Regla por línea/servicio/categoría, escalones o metas, beneficiarios múltiples y novedades; la base ya ajusta devoluciones |
| **Tesorería** | Caja física con turnos y arqueo; transferencias verificadas; CxP y CxC en el libro | Cuentas bancarias, movimientos manuales de caja contabilizados (hoy aparecen como diferencia en la conciliación), conciliación contra extractos y flujo de caja proyectado |
| **Activos** | Registro con costo, fecha, responsable y bloqueo por mantenimiento; asiento de alta | Depreciación mensual con asientos y baja; revalorización queda fuera |
| **BI** | Pantalla de indicadores operativos | Esquema de lectura (vistas de solo lectura de hechos y dimensiones) con glosario, para Power BI; no se escribe desde el BI |

## Orden recomendado
1. **Certificación fiscal** con el emisor y el certificado reales (sin esto no hay producción y bloquea el piloto).
2. **Tesorería bancaria + manuales de caja contabilizados** (cierra las diferencias que hoy muestra la conciliación).
3. **Propinas y fidelización económica**, tras decidir su política con el contador (afectan IVA y pasivos).
4. **Promociones avanzadas y reglas de comisiones** (reglas de negocio acotadas, con reversas históricas).
5. **Modelo de lectura para Power BI** y presupuestos.
6. **Depreciación** y cierre de los límites de nómina (renta, finiquito).

## Registro de estado (20 de octubre de 2026)
«Completo» = implementado, probado en ensayo y fusionado en `main`; no significa certificado ni listo para producción. Todo parámetro legal se registra con su fuente: **ninguna cifra legal viene cargada**, y la emisión SRI sigue solo en pruebas.

| Estado | Qué |
| --- | --- |
| **Completo (ensayo)** | Ventas, caja, prepago, paquetes y suscripciones; facturas, notas de crédito (reparto acumulado del redondeo, sin tolerancia registrada) y sustitución en ambiente de pruebas; contabilidad por partida doble con cierre y reversos; nómina con décimos y fondos; retención de renta desde tabla registrada (045); finiquito (046); utilidades desde el libro (047); ausencias con tipo y saldo de vacaciones (048) |
| **Parcial** | Verificación de las reglas contra fuentes oficiales (SRI, IESS y Ministerio del Trabajo no son accesibles desde el entorno de desarrollo: cada parámetro queda con su fuente y fecha de consulta para que el contador/abogado lo valide); multi-sede; conteo físico de inventario; CRM avanzado; BI |
| **Pendiente** | Certificación SRI con emisor y certificado reales; planillas y avisos al IESS; formulario 107 y anexos del SRI (RDEP); jubilación patronal; retención de renta sobre indemnizaciones y utilidades; destino contable del excedente de utilidades; tesorería bancaria y manuales de caja contabilizados; depreciación; retenciones y anexos tributarios; propinas y fidelización económica (requieren decisión del propietario); presupuestos y modelo de lectura para Power BI; piloto con datos reales |
| **Decisión del propietario** | Registrar `nota_credito.tolerancia_centavos` solo tras verificarla en la ficha técnica del SRI o en una prueba controlada del ambiente de pruebas; mientras no exista, las notas parciales cuyo acumulado difiere se rechazan (409) |

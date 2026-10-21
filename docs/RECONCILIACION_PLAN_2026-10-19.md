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
| F5b Contabilidad | Hecho en ensayo | Libro por partida doble, períodos, cierre, reversos, estados, conciliaciones (#13). **Falta** manuales de caja, ajustes de inventario, retenciones, anexos tributarios; depreciación contable hecha en 052 (ver registro) |
| F6 RH y remuneración | Hecho en ensayo | Jornada, asistencia, ausencias, contratos, comisiones por colaborador con vigencia, nómina propia (#14). IR de relación de dependencia calculado a partir de la tabla que registre el usuario (045; sin cifras de fábrica). Finiquito calculado con parámetros registrados (046). Utilidades repartidas desde el libro con porcentajes registrados (047). Ausencias con tipo y saldo de vacaciones integrado al finiquito (048). Control de avisos al IESS y borrador de aportes cuadrado con la nómina (049; no presenta nada en el portal). Identificación tributaria del trabajador y consolidado anual de control para el 107/RDEP (050; no es el archivo oficial). **Falta** archivo de importación y plazos oficiales del IESS, archivo oficial del formulario 107 y del RDEP (faltan ficha técnica, catálogo y esquema vigentes; ver `docs/NOMINA_PROPIA.md`), jubilación patronal, retención de renta sobre indemnizaciones y utilidades, y contabilizar el destino del excedente de utilidades |
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
| **Completo (ensayo)** | Ventas, caja, prepago, paquetes y suscripciones; facturas, notas de crédito (reparto acumulado del redondeo, sin tolerancia registrada) y sustitución en ambiente de pruebas; contabilidad por partida doble con cierre y reversos; nómina con décimos y fondos; retención de renta desde tabla registrada (045); finiquito (046); utilidades desde el libro (047); ausencias con tipo y saldo de vacaciones (048); control de IESS (049); **depreciación de activos fijos: solo el cálculo lineal y los cambios de estimación prospectivos** (ver «Depreciación de activos fijos» abajo, con sus límites); **caja y contabilidad: ver «Caja y contabilidad: alcance real» abajo** |
| **Parcial** | Caja y contabilidad (ver sección propia); Tesorería bancaria: cuentas, extractos importados como texto y conciliación línea a línea con el libro, con informe de partidas pendientes (051; sin formatos de banco ni asientos automáticos); Formulario 107 y RDEP: identificación del trabajador y consolidado anual de control hechos (050); el archivo oficial depende de la ficha técnica, el catálogo, el esquema `RDEP.xsd` y la resolución vigentes (ver `docs/NOMINA_PROPIA.md`); verificación de las reglas contra fuentes oficiales (SRI, IESS y Ministerio del Trabajo no son accesibles desde el entorno de desarrollo: cada parámetro queda con su fuente y fecha de consulta para que el contador/abogado lo valide); depreciación: tratamiento tributario, venta de activos y revalorización (ver sección propia); multi-sede; conteo físico de inventario; CRM avanzado; BI |
| **Pendiente** | Certificación SRI con emisor y certificado reales; presentación oficial al IESS (formato y plazos); archivo oficial del formulario 107 y del RDEP; jubilación patronal; retención de renta sobre indemnizaciones y utilidades; destino contable del excedente de utilidades; asientos automáticos desde el extracto bancario, importación por formato de cada banco, reclasificación explícita de movimientos de caja históricos y contabilización de los movimientos manuales del módulo financiero heredado; retenciones y anexos tributarios; propinas y fidelización económica (requieren decisión del propietario); presupuestos y modelo de lectura para Power BI; piloto con datos reales |
| **Bloqueado por falta de evidencia (el sistema decide, no el propietario)** | Tolerancia de notas de crédito: modo estricto (0) hasta que haya evidencia; máximo admisible 1 centavo (demostrado). Solo se bloquea la nota que no cuadra. Falta (A) la ficha técnica oficial vigente (versión y sección) o (B) una nota de prueba AUTORIZADA en el ambiente de pruebas del SRI (requiere RUC de pruebas y certificado del emisor). Procedimiento y veredicto en `docs/FACTURACION_PROPIA_SRI.md` |

## Caja y contabilidad: alcance real (21 de octubre de 2026)
Verificado tras fusionar #31 (`e08c09a`). Detalle técnico en `docs/CONTABILIDAD_PROPIA.md`.

| Estado | Qué | Evidencia |
| --- | --- | --- |
| **Completo (ensayo)** | Libro por partida doble inmutable con períodos, cierre, reversos y estados; asientos automáticos desde los hechos del negocio (ventas, cobros, prepago, compras, obligaciones, comisiones, nómina, finiquito, utilidades, activos y depreciación); controles de conciliación del libro contra sus auxiliares (proveedores, comisiones, anticipos, caja en efectivo, depreciación acumulada); **movimientos manuales de efectivo del ERP** clasificados con conceptos configurables ligados al plan propio (aporte y retiro del propietario, depósito y retiro de banco, gasto, sobrante, faltante), con reverso, auditoría, permisos y bloqueo solo de la contabilización sin configurar (054) | CI verde del `main` en cada fusión; 8 pruebas de integración de conceptos de caja |
| **Parcial** | Movimientos manuales del **módulo financiero heredado** (`FinanzasService`: p. ej. transferencias recibidas sin cita): no pasan por conceptos y siguen sin regla; tesorería bancaria (051): conciliación línea a línea sin asientos automáticos desde el extracto ni formatos de cada banco; ajustes de inventario aprobados con concepto y cuenta propios (055: mermas, faltantes y sobrantes de conteo, uso interno) contabilizados al costo del movimiento, sin tocar los anteriores; el control de caja muestra como diferencia todo movimiento manual anterior a 054 | Documentado en `docs/CONTABILIDAD_PROPIA.md` |
| **Pendiente** | Reclasificación **explícita** de los ajustes de inventario anteriores a 055 (la de los movimientos manuales históricos de caja ya existe: ver «Caja: reclasificación explícita»); control de conciliación libro-inventario valorizado; movimientos directos del módulo de inventario heredado; retenciones y anexos tributarios (requieren fuente oficial verificada); centros de costo, presupuestos y multimoneda | Sin decisión automática: nada se reclasifica solo |

**Garantías comprobadas en #31 (con evidencia):**
* La migración 054 es solo aditiva (dos tablas nuevas, ningún `UPDATE`, `DELETE` ni `ALTER` sobre `caja_movimientos`). Con tres movimientos históricos y una sesión de caja cargados antes de aplicarla, las sumas de control de `caja_movimientos`, `erp_caja_movimientos` y `erp_caja_sesiones` fueron idénticas antes y después, y quedaron 0 conceptos y 0 clasificaciones: los históricos se conservan sin reclasificar.
* Un movimiento histórico (sin clasificación) no se puede reversar: el reverso exige un movimiento clasificado.
* El reverso de un movimiento anterior al inicio contable no tiene asiento que anular: se contabiliza **una sola vez** como movimiento actual de sentido contrario (origen único por evento), no toca el asiento de apertura ni sus saldos, y reprocesar la cola no lo duplica (prueba `testReversoDeUnMovimientoAnteriorAlInicioContable…`).

**Caja: reclasificación explícita (21 de octubre de 2026).** Los movimientos manuales de efectivo anteriores a 054 se pueden clasificar **uno por uno** desde Contabilidad → Caja y conceptos («Movimientos históricos sin clasificar»), con permiso `contabilidad.configurar`, concepto activo de sentido coincidente y motivo. Nada se clasifica solo.
* **Alcance:** solo movimientos manuales del ERP (clave `mov:`), sin cobro ni devolución asociados y en efectivo. Cobros, devoluciones, prepagos y pagos de obligaciones tienen su propio hecho contable y no se ofrecen, para no contabilizarlos dos veces.
* **Efecto:** el movimiento de caja no cambia; se anexa su clasificación (inmutable, una por movimiento) y se emite el hecho contable, que genera **un solo asiento en la fecha de la decisión** (no reabre períodos cerrados) con la fecha original en su texto; si el movimiento es anterior al inicio contable no genera asiento. Sin cuenta en el concepto, solo ese movimiento queda pendiente. Una vez clasificado se reversa con las reglas normales.
* **Evidencia:** 3 pruebas de integración nuevas (permisos, rechazo de movimientos ajenos y de sentido contrario, una sola vez, sin duplicar al reprocesar, caja intacta, control de caja sin la diferencia histórica, reverso posterior, auditoría, pendiente sin cuenta, y movimiento anterior al inicio contable sin asiento); mutación comprobada (sin el filtro de alcance la prueba falla).
* **Sigue pendiente:** movimientos del módulo financiero heredado (`FinanzasService`), cuya clave no permite distinguirlos con certeza de otros flujos.

## Inventario (055): garantías comprobadas (21 de octubre de 2026)
Fusionado en `main` con #33 (`229b6b5`). Detalle en `docs/CONTABILIDAD_PROPIA.md`.
* **Históricos:** la migración es solo aditiva (dos tablas nuevas y sus disparadores; ningún `UPDATE`, `DELETE` ni `ALTER` sobre tablas existentes). Con un movimiento histórico de inventario cargado antes de aplicarla, las sumas de control de `inventario_productos`, `inventario_movimientos`, `erp_cont_asientos`, `erp_cont_lineas` y `erp_eventos` fueron idénticas antes y después; 0 conceptos, 0 clasificaciones y 0 asientos. Las solicitudes anteriores (sin concepto) se aprueban como antes y no se contabilizan solas.
* **Trazabilidad:** solicitud → movimiento → evento → asiento, con el concepto y quien lo creó; el asiento remite al evento que lo originó. Cambiar la cuenta de un concepto no reescribe asientos ya publicados.
* **Duplicados:** una clasificación por movimiento (clave primaria) y por solicitud (única); aprobar de nuevo la misma solicitud no reaplica el stock; reprocesar la cola no duplica asientos.
* **Reversos:** un ajuste se corrige con otro contrario aprobado (el inventario es de solo anexar); el gasto original se conserva y el inventario se compensa. Un ajuste cuyo valor no cabe en un asiento se rechaza antes de tocar el stock.
* **Sigue pendiente:** reclasificación explícita de ajustes anteriores a 055; control de conciliación libro-inventario valorizado; movimientos directos del módulo de inventario heredado.

## Depreciación de activos fijos (052): estado por componente (21 de octubre de 2026)
El módulo **no se da por completo**: solo están implementados y validados el cálculo lineal y los cambios de estimación prospectivos; el resto sigue pendiente o bloqueado. Detalle técnico en `docs/DEPRECIACION_ACTIVOS.md`.

| Componente | Estado | Evidencia / motivo |
| --- | --- | --- |
| **Depreciación lineal por meses completos** (políticas con fuente, fichas, corridas mensuales en orden, asientos, reversos, bajas, permisos, auditoría, reportes, control de conciliación) | **Implementada y validada en ensayo** (fusionada en `13c3075`, PR #28) | Cuotas calculadas a mano (`DepreciacionLinealTest`), 8 pruebas de integración, `EsquemaTest` (141 tablas), CI verde del PR. Política contable del propietario; no trae cifras. |
| **Cambios de estimación** (vida restante o valor residual de un activo ya depreciado, prospectivos) | **Implementados y validados en ensayo** (migración 053) | Vigencias por tramos desde un mes sin cuota; lo depreciado y los períodos cerrados no se tocan ni se revierten; el activo debe estar al día. Pendiente aparte: cambio de método de depreciación. |
| **Tratamiento tributario** (límites de deducibilidad, conciliación contable-tributaria, valor residual a efectos tributarios) | **Pendiente / bloqueado por fuente oficial** | No hay fuente oficial verificada accesible; no se asume ningún valor. |
| **Venta de activos** | **Pendiente / bloqueado** | Exige comprobante electrónico y tratamiento tributario de la ganancia o pérdida. Hoy solo hay baja sin venta. |
| **Revalorización** | **Pendiente** | Fuera de alcance del plan actual. |
| Otros métodos (saldos decrecientes, unidades) | Pendiente | Decisión del propietario y del contador. |

## Aclaración de SHA del PR #27 (prueba R2B-02)
* La autorización de fusión mencionaba la cabeza `2eeabd0`; el PR se fusionó desde `bd12968` (merge `4db4e22`).
* **Qué cambió entre ambas:** un solo commit (`bd12968`) que no toca lógica: +6 líneas de comentario en `tests/Qa/R2bRegistroUniformeTest.php` (garantía y límites de la prueba: tres rondas reducen ruido pero no demuestran que la fragilidad esté resuelta; el mínimo oculta retardos intermitentes) y +4 líneas en `docs/IMPLEMENTACION_ERP_Y_VALIDACION.md`. Se pidió documentar esa garantía después de revisar `2eeabd0`.
* **Validación recibida:** `2eeabd0` tuvo CI verde y Codex sin hallazgos. `bd12968` tuvo su propio CI completo (run 39513470656, éxito el 21-oct 00:23 UTC), cero hilos de revisión y mergeable limpio; antes de fusionar se comprobó que la cabeza era exactamente `bd12968`. El push posterior a main (run 267) también terminó en éxito.

# Depreciación de activos fijos (migración 052)

Rama `feature/erp-depreciacion`. Ensayo: no es contabilidad oficial hasta que el contador valide las políticas.

## Qué es política contable y qué es regla tributaria

| Tema | Tratamiento aquí |
| --- | --- |
| Vida útil, valor residual, método, mes de inicio | **Política contable configurable.** La define el propietario y queda registrada con su *fuente o criterio* (texto obligatorio). El sistema **no trae** vidas útiles ni porcentajes. |
| Método | Solo línea recta por meses completos. |
| Límites de deducibilidad tributaria (porcentajes máximos, vidas mínimas), conciliación contable-tributaria, impuesto diferido | **No implementado.** Requiere fuente oficial verificada (ley, reglamento, resoluciones del SRI) que no se pudo consultar. No se asume ningún valor. |
| Venta de un activo | **No implementada.** Exige comprobante electrónico y tratamiento tributario de la ganancia o pérdida. |
| Revalorización | **No implementada** (fuera de alcance del plan). |

## Modelo de datos (+5 tablas)

* `erp_dep_categorias`: política (nombre único, vida 1–600 meses, residual en puntos básicos 0–9999, método `lineal`, fuente ≥ 5 caracteres). Inmutable: otra política = otra fila.
* `erp_dep_fichas`: **una por activo** (única en la base). Copia vida y residual de la política, con inicio (día 1), costo y, solo para un activo anterior al libro, el acumulado inicial.
* `erp_dep_corridas`: una corrida **vigente** por mes (columna generada única); estados `borrador → contabilizada → anulada`; versiones al recalcular.
* `erp_dep_lineas`: cuota por activo y mes (única por corrida+activo); inmutable.
* `erp_dep_bajas`: una baja vigente por activo (columna generada única); `vigente → anulada`.

Triggers: política, ficha y líneas no se editan ni borran; corridas y bajas solo cambian de estado (los importes nunca). Cuentas y roles nuevos (`1.2.02`, `6.1.07`, `6.1.08`; roles `depreciacion_acumulada`, `gasto_depreciacion`, `perdida_baja_activos`) se crean sin pisar un plan editado.

## Cálculo

Reparto acumulado en centavos enteros: `objetivo(k) = ⌊(2·base·k + vida) / (2·vida)⌋` (mitad hacia arriba); la cuota del mes *k* es `objetivo(k) − objetivo(k−1)`. La suma de las cuotas es exactamente la base (costo − residual − acumulado inicial) y ninguna cuota excede lo que falta.
Ejemplo calculado a mano: base 900,00, 7 meses → 128,57 · 128,57 · 128,57 · 128,58 · 128,57 · 128,57 · 128,57.

Activo anterior al libro: con acumulado inicial se **exigen los meses de vida que le quedan** (≤ la vida de la política) y el saldo se reparte en ese plazo. Un activo no puede empezar en un mes que ya tiene corrida vigente (ni posterior).

Reglas: solo meses ya terminados; no antes del inicio contable ni del mes de adquisición; los meses se calculan y contabilizan **en orden y sin saltos**; un activo con meses sin contabilizar bloquea el cálculo (el sistema compara lo contabilizado con lo esperado); sin depreciación en el mes de la baja.

## Flujo, asientos y reversos

1. `dep_calcular` (borrador, permiso `contabilidad.registrar`).
2. `dep_contabilizar`: recalcula y compara con el borrador; publica el evento `depreciacion_contabilizada`. Asiento fechado el último día del mes: **Dr** Gasto por depreciación · **Cr** Depreciación acumulada (período cerrado → se registra en el primer período abierto, como el resto del adaptador).
3. `dep_anular` (`contabilidad.configurar`): borrador → se descarta sin asiento; contabilizada → reverso (`depreciacion_anulada`). Se anula de la corrida más reciente a la más antigua y no si hay una baja posterior que dependa de ella.
4. `dep_baja` (`contabilidad.configurar`; motivos: obsolescencia, daño, pérdida, donación, otro con nota): **Dr** Depreciación acumulada (lo acumulado) · **Dr** Pérdida por baja (valor en libros) · **Cr** Activos fijos (costo). `dep_baja_anular` revierte; no se puede si ya hay corridas del mes de la baja o posteriores.

Idempotencia: cada acción usa clave de idempotencia; repetir contabilizar sobre una corrida ya contabilizada no duplica el asiento.

## Reportes y control

Pestaña **Contabilidad → Activos fijos**: activos con acumulado y valor en libros, cuadro por activo (contabilizado + proyección), corridas, depreciación contabilizada por mes del año y bajas. El control **Depreciación acumulada** de la conciliación compara el libro con (acumulado inicial de las fichas + corridas contabilizadas − acumulado dado de baja).

## Permisos y auditoría

Se reutilizan `contabilidad.ver / registrar / configurar` (sin permisos nuevos). Finanzas calcula y contabiliza; configurar políticas/fichas, anular y dar de baja es del propietario. Cada operación genera evento con actor; los datos del evento no incluyen claves de idempotencia.

## Pruebas

`tests/Unit/DepreciacionLinealTest.php` (cálculo a mano) y `tests/Integration/ErpDepreciacionIntegrationTest.php` (6 casos: políticas y fichas, corridas en orden y sin duplicados, reversos y recálculo, bajas, permisos y auditoría, pantalla y formularios). `tests/Qa/EsquemaTest.php` ajusta el conteo de tablas.

## Pendiente / bloqueado

* **Bloqueado por falta de fuente oficial:** límites de deducibilidad y conciliación contable-tributaria; venta de activos (comprobante electrónico); tratamiento del valor residual a efectos tributarios.
* **Pendiente por decisión del propietario/contador:** categorías y vidas útiles reales; cambio de estimaciones (hoy: se anula y se recalcula; no hay cambio prospectivo de vida útil); depreciación por unidades o saldos decrecientes; revalorización.
* **Pendiente (utilidades):** el destino del excedente de la participación de trabajadores sobre el tope **sigue sin asumirse**: no se ha fundamentado su tratamiento ni se da por libre de requisitos legales.

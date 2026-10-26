# Conteo físico de inventario (migración 070)

Un conteo verifica las existencias físicas contra el sistema y, al aprobarse, genera los ajustes **por el flujo que ya existe** (solicitud de ajuste de stock → movimiento de inventario → concepto → hecho contable). No crea un camino nuevo para tocar el stock.

## Flujo
1. **Iniciar** (`inventario.gestionar`): se registra el **alcance** (todos los productos activos o una selección, con descripción), el **responsable** (alguien que gestione inventario) y, por producto, la **existencia de referencia**, el costo de referencia y el último movimiento. Un producto no puede estar en dos conteos abiertos a la vez.
2. **Contar**: cada lectura guarda lo contado, **lo que el sistema tenía en ese instante** (con el producto bloqueado, así stock, reservas y último movimiento son coherentes), las reservas y quién lo hizo. Se puede recontar mientras el conteo está abierto; **cada lectura queda en el historial** (solo de anexar).
   * **Sin contar ≠ contado en cero.** Una cantidad vacía se rechaza («sin cantidad el producto queda sin contar»); `0` es un cero contado. Lo sin contar **no genera ajuste**.
3. **Cerrar**: envía el conteo a aprobación (al menos un producto contado).
4. **Aprobar** (`erp.aprobaciones` + `inventario.gestionar`; **otra persona** distinta de quien lo cerró, salvo el propietario): exige el **concepto** de cada sentido (faltantes = salidas, sobrantes = entradas) y el **motivo** (≥ 5 caracteres). Rechazar y cancelar no cambian el stock.
5. **Revertir** un conteo aprobado: genera un **ajuste inverso** por cada ajuste aplicado (una sola vez), con concepto del sentido contrario y motivo. Los ajustes originales se conservan. No procede si el stock quedaría por debajo de lo reservado.

## Cómo se calcula la diferencia
* `diferencia = contado − existencia física del sistema al contar` (no contra la referencia del inicio). Se aplica como **diferencia sobre el stock actual**: **nunca se sobrescribe el stock con una lectura antigua**.
* Los movimientos entre el inicio y el conteo, y los posteriores al conteo, se muestran como **conflictos**. Los posteriores exigen **confirmación explícita** al aprobar; los previos solo informan (la diferencia ya es contra el sistema al contar).
* **Reservas**: la mercadería reservada sigue en el estante, así que **no es un faltante** (contar todo lo físico da diferencia 0). Una reserva solo limita: si el ajuste dejaría el stock por debajo de lo reservado, es un conflicto que **bloquea** la aprobación (todo o nada: no se aplica ninguna línea).
* **Valoración**: cada ajuste se valoriza con el costo del movimiento al aplicarse (cantidad × costo, redondeo comercial). La pantalla muestra una estimación al costo actual; el vínculo guarda el costo y el valor reales.

## Un ajuste, una vez
`erp_inv_conteo_ajustes` tiene `UNIQUE(linea_id, rol)` (`conteo` o `reverso`), `UNIQUE(solicitud_id)` y `UNIQUE(movimiento_id)`: la base impide generar dos veces el ajuste de una línea. Además la aprobación es idempotente por formulario y un conteo ya aprobado se rechaza (409). Cada ajuste vincula conteo, línea, solicitud, movimiento, concepto, costo y valor.

## Qué no se borra
Los triggers rechazan borrar conteos, líneas, lecturas y ajustes, y editar lecturas o ajustes. Un conteo solo avanza de estado (`en_conteo → pendiente|cancelado`, `pendiente → aprobado|rechazado|cancelado`, `aprobado → revertido`) y lo fijado al iniciar no cambia; las lecturas solo cambian mientras el conteo está en conteo. El respaldo y la restauración conservan los datos y las protecciones (la columna `diferencia_milesimas` es generada y MySQL la recalcula).

## Límites
* Una pantalla en `/gestion/inventario/conteos` (permiso `inventario.gestionar`); no hay lectura por código de barras ni carga por archivo.
* No hay vistas BI del conteo en esta entrega.
* Los movimientos directos del módulo de inventario heredado siguen su propio flujo (ver RECONCILIACION).

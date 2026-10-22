# Modelo de lectura para BI (migración 057)

Vistas de **solo lectura** para conectar Power BI (o cualquier herramienta de análisis) a hechos ya registrados en AppSalon. Es el **primer corte** del modelo: ventas por línea, libro contable e inventario valorizado. Los cobros, las atenciones, la asistencia y las comisiones quedan para cortes siguientes (ver «Alcance y pendientes»).

## Principios
* **Solo lectura.** Las vistas no son actualizables (`SELECT DISTINCT`: sin él MySQL las trataría como actualizables y un `UPDATE` o `DELETE` sobre la vista llegaría a la tabla de origen; la prueba lo rechaza y falla si se quita el `DISTINCT`). Aun así, la herramienta de BI debe conectarse con un usuario de base de datos que **solo tenga `SELECT`** sobre las vistas `bi_*` (ver «Conexión»).
* **Sin datos personales.** No hay comprador, contacto, correo, teléfono ni identificación. Un cruce con clientes se hará con un identificador seudónimo en un corte posterior, con decisión explícita del propietario.
* **Mismas cifras que el sistema.** Importes en centavos y cantidades en milésimas (enteros), como el resto del ERP. Las pruebas comparan las vistas con las tablas de origen y con los controles de conciliación existentes.
* **BI no corrige datos.** Si una cifra no cuadra, se corrige en su fuente (el control de conciliación del libro explica la diferencia); el BI no ajusta nada.
* **Mínimo privilegio**: las vistas son `SQL SECURITY DEFINER`, así que el usuario de BI necesita `SELECT` **solo sobre las tres vistas** y no puede leer ninguna tabla de origen (que contiene datos personales). Con `INVOKER` ese usuario no podría consultarlas (error 1356: se comprobó). La seguridad descansa en que las vistas exponen únicamente las columnas listadas abajo y no son actualizables. El definer es la cuenta que aplica la migración o que restaura el respaldo.

## Vistas y grano

### `bi_venta_lineas` — una fila por línea de venta **confirmada**
| Columna | Definición |
| --- | --- |
| `linea_id`, `venta_id` | Identificadores (la venta no se duplica al cruzar con pagos: los pagos no entran en esta vista) |
| `fecha_venta` | Fecha de confirmación de la venta (fecha comercial) |
| `producto_id`, `servicio_id`, `profesional_id` | Dimensiones (nulas si no aplican) |
| `cantidad_milesimas`, `entregado_milesimas`, `devuelto_milesimas` | Cantidad vendida, entregada y devuelta, en milésimas |
| `precio_centavos`, `descuento_centavos` | Precio unitario y descuento de la línea |
| `neto_sin_impuesto_centavos` | `total − impuesto` |
| `impuesto_centavos`, `total_centavos` | Impuesto y total de la línea |
| `costo_total_centavos` | Costo congelado de la línea: `(costo unitario × cantidad + 500) ÷ 1000` (redondeo comercial) |

Excluye borradores y ventas anuladas. **Venta neta** = Σ `neto_sin_impuesto_centavos` (las devoluciones comerciales se tratan según la política vigente; la columna `devuelto_milesimas` permite medirlas, aún no se netean aquí).

### `bi_libro_lineas` — una fila por línea de asiento contable
| Columna | Definición |
| --- | --- |
| `linea_id`, `asiento_id`, `asiento_numero` | Identificadores |
| `fecha`, `asiento_tipo`, `es_reverso`, `reversa_de_id` | Fecha contable, tipo (apertura, automático, manual, reverso, cierre) y a qué asiento anula |
| `cuenta_id`, `cuenta_codigo`, `cuenta_nombre`, `cuenta_tipo` | Cuenta del plan |
| `debe_centavos`, `haber_centavos` | Importes |
| `neto_deudor_centavos` | `debe − haber` (positivo: saldo deudor) |

Σ `debe` = Σ `haber` en toda la vista (partida doble). El saldo de una cuenta a una fecha es Σ `neto_deudor_centavos` con `fecha ≤ corte`. Los reversos entran con su propio signo: no se «netean» ni se ocultan.

### `bi_inventario_movimientos` — una fila por movimiento de inventario
| Columna | Definición |
| --- | --- |
| `movimiento_id`, `fecha`, `producto_id`, `sku`, `tipo` | Identificación del movimiento (`entrada` o `salida`) |
| `cantidad_milesimas`, `cantidad_con_signo_milesimas` | Cantidad y su signo (entrada +, salida −) |
| `costo_centavos` | Costo unitario del movimiento |
| `valor_con_signo_centavos` | `± (costo × cantidad + 500) ÷ 1000`: valor al **costo propio del movimiento**, igual que el auxiliar del control de conciliación |

Σ `cantidad_con_signo_milesimas` por producto = existencia. Σ `valor_con_signo_centavos` = auxiliar valorizado de la conciliación inventario ↔ libro.

## Controles que respaldan el modelo (pruebas)
`ErpBiModeloLecturaIntegrationTest`: las vistas existen con `SQL SECURITY DEFINER`; un usuario de MySQL con `SELECT` solo sobre las tres vistas las consulta, no lee ninguna tabla de origen, no escribe ni borra vistas y `SHOW GRANTS` muestra solo `SELECT` sobre ellas; `DISTINCT` no colapsa filas legítimas (líneas de venta y movimientos idénticos salvo el id se conservan, y cada vista tiene un identificador único por fila); rechazan escrituras y dejan intactas las tablas de origen (mutación comprobada: sin `DISTINCT`, la vista de ventas era actualizable); no exponen columnas ni valores de datos personales; ventas cuadran con su origen y solo entran las confirmadas; el libro cuadra debe = haber y una fila por línea, y el saldo de inventario de la vista = el del control de conciliación; inventario de la vista = auxiliar del control y = existencias; consultar las vistas no modifica ninguna tabla.

## Respaldo y restauración
Las vistas no guardan datos, pero la migración 057 ya figura como aplicada, así que un respaldo restaurado las perdería sin cuidado especial. Por eso `scripts/backup_db.php` registra los **nombres** de las vistas en el manifiesto (`vistas`) y `scripts/restore_db.php` las **recrea desde las migraciones del repositorio** (SQL de confianza), nunca desde el archivo de respaldo: así el validador de sentencias del respaldo sigue aceptando solo tablas. Se recrea **la definición más reciente de cada vista entre las migraciones aplicadas en la base restaurada** (igual que las protecciones versionadas): un respaldo histórico con solo 057 recupera la definición de 057 aunque una migración posterior la redefina, y con ambas aplicadas rige solo la última (la base ya tiene el esquema final, y una definición antigua podría apuntar a una columna renombrada). Una migración que retire una vista con `DROP VIEW` la excluye, y las vistas que dependen de otras se crean reintentando las que fallen hasta que no haya progreso (lo decide el motor de la base, sin interpretar el texto SQL; si alguna no se puede crear, la restauración falla con el motivo de la base), así que también funciona al restaurar solo con `--hash` o con un respaldo hecho con `--no-manifest`. Si el manifiesto, cuando existe, declara una vista sin definición en el repositorio, la restauración se rechaza antes de tocar nada. La prueba `EsquemaTest::test_E4…` comprueba el ciclo completo, con y sin manifiesto (mutaciones comprobadas: sin recrearlas, el esquema restaurado difiere; con la versión que dependía del manifiesto, restaurar solo con `--hash` perdía las vistas). Las vistas nuevas deben verificarse con este ciclo: la nulabilidad que MySQL infiere para una columna de vista puede depender del `sql_mode` de la sesión que la crea (se comprobó con `CONCAT` dentro de una `UNION`: nulable con el modo estricto del migrador, no nulable con el modo del restaurador), y `test_E4` compara el esquema restaurado columna por columna; por eso las vistas evitan llaves de texto concatenadas. Los conteos de «tablas» del respaldo y de `EsquemaTest` siguen contando solo tablas base (147).

## Conexión (a cargo del propietario)
El proyecto **no crea usuarios de base de datos ni toca la configuración de Docker**. Para conectar Power BI, el administrador de la base crea un usuario de solo lectura, por ejemplo:

```sql
CREATE USER 'bi_lectura'@'%' IDENTIFIED BY '<contraseña propia>';
GRANT SELECT ON appsalon.bi_venta_lineas TO 'bi_lectura'@'%';
GRANT SELECT ON appsalon.bi_libro_lineas TO 'bi_lectura'@'%';
GRANT SELECT ON appsalon.bi_inventario_movimientos TO 'bi_lectura'@'%';
```

Ese usuario **no** necesita permisos sobre las tablas de origen (las vistas son `DEFINER`). Elegir importación programada o consulta directa (DirectQuery) tras medir volumen y latencia; no se promete tiempo real. Si la cuenta que aplicó la migración se elimina o pierde privilegios, las vistas dejan de funcionar hasta recrearlas (re-aplicar la definición con una cuenta vigente).

## Alcance y pendientes
| Estado | Qué |
| --- | --- |
| **Entregado (ensayo)** | Las tres vistas, este glosario y las pruebas de cuadre contra origen y contra el control de conciliación. **Segundo corte (058): flujo de dinero, devoluciones, aplicaciones de saldo y notas de crédito**, en `docs/BI_COBRO_NETO.md`. **Tercer corte (059): atenciones, servicios y ocupación de profesionales**, en `docs/BI_ATENCIONES_OCUPACION.md`. **Cuarto corte (060): saldo por cobrar**, hoy y a cualquier corte, en `docs/BI_SALDO_POR_COBRAR.md` |
| **Pendiente** | asistencia y comisiones (con separación de datos laborales); dimensión fecha con sus roles; dimensiones de producto, servicio y colaborador (sin datos personales); cohortes de retención con identificador seudónimo; **presupuestos** |
| **Decisión del propietario** | Responsables de cada indicador y aprobación del glosario antes de distribuir tableros; política de devoluciones en la venta neta; alcance por sede/rol cuando exista multi-sede |

# Asistencia y comisiones del personal para BI (migración 061)

Quinto corte del modelo de lectura (ver `docs/BI_MODELO_LECTURA.md`, `BI_COBRO_NETO.md`, `BI_ATENCIONES_OCUPACION.md` y `BI_SALDO_POR_COBRAR.md`). Son datos **laborales**: cuánto trabajó cada persona y cuánto ganó en comisiones. Por eso tienen su **propio grupo de acceso**, separado del comercial.

## Separación del acceso laboral y el comercial
* Todas las vistas de este corte llevan el prefijo **`bi_rh_`**. Las comerciales (`bi_venta_*`, `bi_libro_*`, `bi_flujo_*`, `bi_atenciones`, `bi_cxc_*`, ...) no tienen datos de personas.
* Se conceden a **usuarios de base de datos distintos** (los crea el propietario; el proyecto no crea usuarios ni toca Docker):

```sql
-- Grupo comercial: ventas, caja, contabilidad, inventario, atenciones, saldo por cobrar. SIN bi_rh_*.
CREATE USER 'bi_comercial'@'%' IDENTIFIED BY '<contraseña propia>';
GRANT SELECT ON appsalon.bi_venta_lineas TO 'bi_comercial'@'%';   -- ... y las demás vistas bi_* que no empiezan por bi_rh_

-- Grupo laboral: solo recursos humanos / propietario. SIN vistas comerciales.
CREATE USER 'bi_laboral'@'%' IDENTIFIED BY '<contraseña propia>';
GRANT SELECT ON appsalon.bi_rh_asistencia TO 'bi_laboral'@'%';
GRANT SELECT ON appsalon.bi_rh_jornadas TO 'bi_laboral'@'%';
GRANT SELECT ON appsalon.bi_rh_ausencias TO 'bi_laboral'@'%';
GRANT SELECT ON appsalon.bi_rh_comisiones_movimientos TO 'bi_laboral'@'%';
GRANT SELECT ON appsalon.bi_rh_comisiones_liquidaciones TO 'bi_laboral'@'%';
```

* En Power BI conviene un **conjunto de datos y un área de trabajo distintos** para lo laboral, con acceso solo para quien deba verlo: un modelo combinado dejaría a cualquier lector del informe cruzar personas con ventas.
* **Qué NO se expone**: nombre, apellido, documento, contacto, sueldo o base contractual, motivos y notas libres, ni quién registró cada hecho. La persona se identifica solo por `colaborador_id` (clave interna) y la unión con otras fuentes la decide el propietario. Aun así, asistencia y comisiones por persona son datos personales laborales: la distribución de los tableros es una decisión del propietario.
* La prueba lo verifica con usuarios reales de MySQL: el laboral lee las cinco vistas y ninguna comercial ni tabla de origen; el comercial lee las comerciales y ninguna `bi_rh_`.

## Vistas y grano
| Vista | Grano | Columnas |
| --- | --- | --- |
| `bi_rh_asistencia` | un registro de asistencia | `asistencia_id`, `colaborador_id`, `fecha` (día de entrada), `entrada`, `salida`, `descanso_minutos`, `anulada`, `minutos_trabajados`, `minutos_trabajados_vigentes` |
| `bi_rh_jornadas` | una jornada semanal programada | `jornada_id`, `colaborador_id`, `dia_semana` (ISO, 1 = lunes), `inicio`, `fin`, `minutos_programados` |
| `bi_rh_ausencias` | una ausencia (rango) | `ausencia_id`, `colaborador_id`, `desde`, `hasta`, `tipo`, `estado`, `dias_calendario` (ambos extremos), `es_aprobada` |
| `bi_rh_comisiones_movimientos` | un devengo o ajuste de comisión | `sistema`, `movimiento_id`, `colaborador_id`, `fecha`, `venta_id` (ERP) o `pago_id` (heredado), `monto_centavos` (con signo), `liquidada`, `liquidacion_id` |
| `bi_rh_comisiones_liquidaciones` | una liquidación de comisiones | `sistema`, `liquidacion_id`, `colaborador_id`, `fecha`, `canal`, `monto_centavos`, `pagado_centavos`, `en_corrida_activa`, `corrida_id` |

### Asistencia
* `minutos_trabajados` = (salida − entrada) − descanso, nunca negativo. Un turno nocturno cuenta en el **día en que entró**. Las **anuladas** se conservan con `anulada = 1` y no suman en `minutos_trabajados_vigentes`: para totales usa siempre la columna «vigentes».
* La asistencia **no alimenta la nómina**: las horas extra se registran manualmente (ver `docs/NOMINA_PROPIA.md`). Este corte mide presencia, no paga nada.
* Las ausencias y jornadas son rangos y plantillas semanales; el cruce día por día se hace con la dimensión fecha (pendiente en el plan).

### Comisiones: dos libros, sin duplicar
Las comisiones existen en dos libros: el **heredado por profesional** (`anticipo_previo`, de los cobros de citas anteriores al punto de venta) y el **ERP por colaborador** (`erp`, por línea de venta). El ERP **ya descuenta** lo devengado en el libro heredado (`Services\ErpComisionService::sincronizar` resta ese devengo de su objetivo: «no se devengan dos veces»), de modo que **sumar ambos libros no duplica**. El BI no recalcula nada: se apoya en esa regla.
* Los devengos heredados **anulados** no entran. Los ajustes (reversas, devoluciones) llevan signo negativo y se compensan con devengos futuros.
* El profesional heredado se atribuye al colaborador de su ficha (`profesionales.colaborador_id`); si no tiene ficha, `colaborador_id` queda **nulo** (no se inventa).

### Liquidaciones: un solo canal por comisión
Cada liquidación aparece **una vez** y por **un solo canal**:
| Canal | Origen | Cómo se paga | `pagado_centavos` |
| --- | --- | --- | --- |
| `caja_directa` | libro heredado | desde caja, al instante | todo el monto |
| `obligacion_directa` | ERP, vía directa | queda como obligación por pagar | lo ya pagado de la obligación |
| `nomina` | ERP, vía nómina | dentro de la corrida de nómina | 0 aquí: se paga con la corrida; `en_corrida_activa` y `corrida_id` indican en cuál |

Una comisión pagada por nómina **no puede pagarse además por la vía directa**: la base lo impide con un disparador (solo las liquidadas «por nómina» entran a una corrida) y no puede estar en dos corridas activas (índice único sobre el vínculo vigente). Anular una corrida libera el vínculo (`en_corrida_activa = 0`) sin duplicar la fila.

Reconciliación: Σ comisiones liquidadas en nómina de la vista (`en_corrida_activa = 1`) = Σ vínculos activos de la nómina = `comisiones_centavos` de las líneas de la corrida.

## Reconciliación (pruebas)
`ErpBiRhAsistenciaComisionesIntegrationTest` (5 pruebas, MySQL real, nómina real):
* Asistencia: minutos con descanso, anulada excluida de vigentes, turno nocturno en su día de entrada, descanso mayor que el turno sin negativos, una fila por registro.
* Jornadas y ausencias: minutos programados, días calendario, solicitada ≠ aprobada, sin motivos.
* Comisiones: Σ vista = Σ libro ERP + Σ libro heredado sin anuladas; conteo por fila; sistema y colaborador correctos; profesional sin ficha con colaborador nulo.
* Liquidaciones: tres canales con sus montos y pagado, una fila por liquidación, la corrida de nómina real toma solo la de nómina y la vista lo refleja (`corrida_id`), la directa nunca está en una corrida, la base rechaza meter una directa en nómina, liberar el vínculo la saca sin duplicar.
* Acceso: usuarios reales de MySQL laboral y comercial se ven mutuamente denegados; sin columnas ni valores de datos personales; ninguna vista admite UPDATE/DELETE (la prueba lo exigió tras comprobar que, sin `SELECT DISTINCT`, un `DELETE` sobre `bi_rh_asistencia` llegaba a la tabla).
* Mutaciones comprobadas (cada una rompe la prueba): contar devengos heredados anulados, no filtrar el vínculo de nómina vigente, ignorar la anulación en los minutos vigentes, marcar la liquidación de nómina como obligación directa.

### Comisión de una cita cobrada antes del PDV (extremo a extremo)
`ErpBiRhComisionesEntreLibrosIntegrationTest` (3 pruebas, MySQL real, servicios reales de cobro en caja, venta, liquidación, pago de obligación y devolución). Cita de $100.00 al 10 %: anticipo de $40.00 cobrado en caja (libro heredado, $4.00), cita atendida, vendida en el ERP y cobrado el resto (libro ERP, $6.00):
* La comisión es **$10.00 en total** ($4.00 + $6.00), no $14.00; recalcular no devenga de nuevo.
* Liquidada y pagada por su canal (`caja_directa` $4.00, `obligacion_directa` $6.00; pagado = devengado).
* Devolución completa **después** de liquidar y pagar: cada libro reversa lo suyo (−$4.00 y −$6.00), neto 0, y −$10.00 queda pendiente para compensar con comisiones futuras; las liquidaciones pagadas no se alteran.
* Devolución completa **antes** de liquidar: el devengo heredado se anula, el ERP se compensa y no hay doble reverso; sin saldo positivo no se puede liquidar.
* Mutaciones comprobadas (cada una rompe la prueba): ignorar el devengo heredado al calcular el ERP (daría $16.00 en lugar de $10.00) y no descontar las reversas heredadas (el ERP quedaría en −$4.00).

## Límites conocidos
* **Los datos por `colaborador_id` son laborales identificables (seudónimo), no anónimos.** Se conserva la separación de permisos; la distribución de tableros con detalle por persona es decisión del propietario.
* El descuento entre libros se prueba de extremo a extremo solo con una cita de un servicio y devolución completa; las devoluciones parciales con importes que redondeen se apoyan en la regla de `ErpComisionService::sincronizar` sin prueba propia.
* Las comisiones por pagar de nómina se pagan cuando se paga la corrida; el estado de pago de la nómina vive en sus obligaciones y no se replica aquí.
* Sin dimensión fecha aún no hay cuadrícula día por día de ausencias ni de cobertura de jornadas.

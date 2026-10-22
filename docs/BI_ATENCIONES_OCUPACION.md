# Atenciones y ocupación para BI (migración 059)

Tercer corte del modelo de lectura para Power BI (ver `docs/BI_MODELO_LECTURA.md` y `docs/BI_COBRO_NETO.md`). Mide **qué se reservó, qué se atendió, qué no ocurrió** y **cuánta de la capacidad disponible se ocupó**, con las mismas reglas que `/admin/reportes` (`Services\ReporteService`), contra el que las pruebas reconcilian.

Mismas garantías que los cortes anteriores: solo lectura (la prueba verifica que ninguna vista admite `UPDATE` ni `DELETE`), `SQL SECURITY DEFINER` (el usuario de BI necesita `SELECT` solo sobre las vistas), sin datos personales (no hay cliente, usuario, actor que registró ni notas) y una fila por hecho.

## Métricas (definiciones)
| Métrica | Definición | Fuente |
| --- | --- | --- |
| **Cita reservada (activa)** | Cita que no está cancelada, sea cual sea su estado de atención | `bi_atenciones.es_cancelada = 0` |
| **Servicio realizado** | Cita `completada`. Su fecha es la **efectiva** de la regularización si existe; si no, la programada (una atención ordinaria ocurre el día de su cita) | `es_atendida`, `fecha_servicio_realizado` |
| **No asistencia** | Cita en estado `no_asistio`. Métrica propia: no es cancelación ni servicio realizado | `es_no_asistencia` |
| **Cancelación** | Cita en estado `cancelada`. Se conserva para medir cancelaciones, pero **no ocupa** minutos | `es_cancelada` |
| **Pendiente de atención** | `confirmada` o `en_servicio` | `es_pendiente_de_atencion` |
| **Minutos reservados** | Duración total guardada en la cita; si falta, `hora_fin − hora_inicio`; si falta, suma de la duración de sus servicios (instantánea histórica, y si no, la del catálogo) | `minutos_reservados` |
| **Minutos disponibles** | Horario semanal del profesional ese día menos descansos y bloqueos, con semántica `[inicio, fin)` y **sin restar dos veces** el solape entre descansos y bloqueos (lunes 09:00–18:00, descanso 13:00–14:00, bloqueo 13:30–15:00 → 420 min, no 390). Un bloqueo sin horas cubre el día completo; uno de varios días aplica a cada día | `minutos_disponibles` |
| **Ocupación programada** | `Σ minutos_reservados / Σ minutos_disponibles` sobre el periodo; si no hay minutos disponibles (sin turno, inactivo) es 0 y se muestra como «sin turno», nunca como infinito | cálculo en DAX (ver abajo) |
| **Ocupación atendida / perdida** | Lo mismo con `minutos_atendidos` o `minutos_no_asistidos` en el numerador | cálculo en DAX |

La ocupación **no** es una columna porque un cociente no se suma: promediar los porcentajes diarios da un resultado distinto del cociente de las sumas. En Power BI:

```DAX
Minutos reservados = SUM ( bi_ocupacion_profesional_dia[minutos_reservados] )
Minutos disponibles = SUM ( bi_ocupacion_profesional_dia[minutos_disponibles] )
Ocupación programada = DIVIDE ( [Minutos reservados], [Minutos disponibles] )  -- BLANK si no hay turno
Ocupación atendida = DIVIDE ( SUM ( bi_ocupacion_profesional_dia[minutos_atendidos] ), [Minutos disponibles] )
```

## Vistas y grano
### `bi_atenciones` — una fila por cita
`cita_id`, `fecha_programada`, `profesional_id` (nulo en citas históricas sin profesional), `estado`, las banderas `es_cancelada` / `es_no_asistencia` / `es_atendida` / `es_pendiente_de_atencion` (exactamente una vale 1), `fecha_servicio_realizado` (solo atendidas), `fue_regularizada`, `hora_programada`, `hora_fin_programada`, `atencion_inicio_at`, `atencion_fin_at`, `ausencia_at`, `minutos_reservados`, `cantidad_servicios`, `importe_servicios_centavos`.

### `bi_atencion_servicios` — una fila por servicio de una cita
`cita_servicio_id`, `cita_id`, `servicio_id` (nulo si el servicio se retiró del catálogo), `servicio_nombre`, `servicio_fuera_de_catalogo`, `precio_centavos`, `duracion_minutos`. Precio, nombre y duración son los **capturados al reservar**: editar o retirar un servicio del catálogo no cambia lo ya reservado (igual que el reporte). `Σ precio_centavos` por cita = `importe_servicios_centavos`. Es el importe **reservado**, no el cobrado: el dinero real está en `bi_flujo_dinero` (ver `docs/BI_COBRO_NETO.md`).

### `bi_ocupacion_profesional_dia` — una fila por profesional y día
`profesional_id`, `fecha`, `profesional_activo`, `dia_semana` (ISO, 1 = lunes), `minutos_disponibles`, `minutos_reservados`, `minutos_atendidos`, `minutos_no_asistidos`, `citas_activas`, `citas_atendidas`, `citas_no_asistidas`, `citas_canceladas`. Cubre **todos** los profesionales y **todos** los días desde la primera cita registrada hasta la última o el día de hoy (hora de Ecuador, UTC−5 sin cambio de hora), lo que sea posterior, hasta 10.000 días; los días sin citas también están (capacidad sin usar). Los minutos reservados de la vista suman exactamente los de las citas con profesional no canceladas: ninguno se pierde.

## Citas sin profesional y profesionales inactivos
* Las citas históricas sin profesional **no** entran a la ocupación (no hay capacidad contra la cual medirlas); se leen en `bi_atenciones` con `profesional_id` nulo, igual que la fila «No aplica (cita histórica)» del reporte.
* Un profesional **inactivo** tiene 0 minutos disponibles (igual que el reporte): sus reservas existen, pero su ocupación queda indefinida, no infinita.

## Reconciliación (pruebas)
`ErpBiAtencionesOcupacionIntegrationTest` (6 pruebas, MySQL real, semana de fechas fijas, reloj sin intervención):
* Minutos disponibles por profesional = los de `ReporteService` (descanso + bloqueo solapados, día completo, varios turnos, bloqueo de varios días, inactivo).
* Minutos reservados, número de citas y porcentaje de ocupación por profesional = los de `ReporteService`, con los tres orígenes de la duración (guardada, `hora_fin − hora_inicio`, servicios), la cancelada sin ocupar y las citas sin profesional separadas.
* Servicios realizados de la semana = reporte con criterio «realizado»; fecha efectiva de la regularización frente a la programada; cada cita en exactamente un estado.
* Precio histórico intacto aunque el catálogo cambie o el servicio se retire; una fila por cita y por servicio; una cita sin servicios no se pierde ni inventa minutos.
* Una fila por profesional y día, también los sin citas; `Σ minutos_reservados` vista = origen.
* Usuario real de MySQL con `SELECT` solo sobre las tres vistas: las lee, no lee ninguna tabla de origen, no escribe; ninguna vista es actualizable; sin columnas ni valores de datos personales.
* Mutaciones comprobadas (cada una rompe la prueba): restar dos veces el solape (390 en vez de 420), contar canceladas como ocupadas, usar la fecha programada como fecha del servicio realizado, dar disponibilidad a inactivos.

## Límites conocidos
* **El horario es el vigente, no histórico.** No hay historial de cambios de horario: la capacidad de un día pasado se calcula con el horario de hoy (igual que el reporte). Si el negocio cambia horarios con frecuencia, la ocupación histórica se distorsiona; la solución es un historial de horarios (decisión de producto, no incluida).
* Una cita eliminada (no cancelada) con `/api/eliminar` desaparece de la tabla y por tanto de estas vistas; solo las canceladas se conservan para medir cancelaciones.
* La cuadrícula usa la fecha de hoy en el servidor de base de datos (UTC−5 fijo); la dimensión fecha (pendiente) permitirá ampliar el rango y asignar roles de fecha.
* La rotación de personal (asistencia y comisiones) y el cruce con clientes quedan para cortes posteriores (con separación de datos laborales y seudónimo, respectivamente).

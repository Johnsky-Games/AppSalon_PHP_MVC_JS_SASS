# Ronda 5 — CRM: filtros, universo único, «Listado» y exportaciones (módulo c5)

## 1. Definiciones de los filtros (visibles también en la pantalla, `CrmService::DEFINICIONES`)

| Filtro (parámetro) | Definición | Fuente |
|---|---|---|
| Cuenta (`cuenta`) `confirmada` / `sin_confirmar` | Correo confirmado o no | `usuarios.confirmado` |
| Membresía (`membresia`) `activo` / `suspendido` / `sin_registro` | Estado de la ficha de cliente. **No es una suscripción de pago.** `sin_registro` = cuenta legada con rol cliente y sin fila en `clientes` | `clientes.estado` |
| Comunicaciones comerciales (`consentimiento`) | `si` otorgado (cualquier versión del texto) · `vigente` otorgado con el texto en vigor · `anterior` otorgado con un texto que ya no rige · `no` no otorgado (revocado o sin decisión) · `revocado` su **última** decisión fue retirarlo · `sin_decision` nunca decidió. Se muestran versión y fecha | `perfiles_usuario.marketing_email/consentimiento_marketing_*` + `consentimientos_historial` (última decisión) |
| Actividad comercial (`actividad`) | `reciente`: última visita completada dentro de la ventana de días (`dias_activo`, 30–365, por defecto 90) · `inactivo`: tuvo visitas pero la última es más antigua · `nunca`: ninguna visita completada | `citas.estado='completada'`, fecha ≤ hoy |
| Segmento (`segmento`) | VIP, Nuevo, Activo, En riesgo, Inactivo (excluyentes, reglas en pantalla) | sin cambios |
| Alta (`alta_desde`, `alta_hasta`) | Fecha de creación de la membresía | `clientes.creado_en` |
| Última visita (`ultima_desde`, `ultima_hasta`) | Fecha de la última cita completada, límites inclusivos | `citas` |
| Próxima cita (`proxima` con/sin, `proxima_desde/hasta`) | Primera cita futura (hoy incluido) en estado `confirmada` o `en_servicio` | `citas` |
| Atendido por / Servicio (`profesional_id`, `servicio_id`) | ≥1 visita completada **dentro del periodo** con esa persona / servicio | `citas`, `citasservicios` |
| Frecuencia (`visitas_min`, `visitas_max`) | Visitas completadas dentro del periodo (con el profesional/servicio elegidos). `visitas_max=0` = sin visitas en el periodo | `citas` |
| Gasto (`gasto_min`, `gasto_max`, USD) | Cobros reales − devoluciones del periodo; excluye la pasarela simulada (`mock`). Solo con `clientes.analitica` | `pagos` |
| Saldo pendiente (`saldo` con/sin) | Citas **atendidas** (`completada`) con `estado_pago` ∈ {pendiente, parcial, por_verificar}: total de sus servicios (precio histórico) − cobros reales confirmados de esa cita, nunca negativo. Solo con `clientes.analitica`. Un pago `mock` no reduce el saldo | `citas`, `citasservicios`, `pagos` |
| Periodo (`desde`, `hasta`) | Rango de las visitas del periodo, frecuencia, gasto y gráficos; nunca después de hoy | — |

**«Suscripción».** En este sistema no existe suscripción de pago. «Suscripción» solo se rotula como *Suscripción a comunicaciones
comerciales* = consentimiento otorgado vigente. Se investigó `fidelidad`: `fidelidad_movimientos` es un **programa de puntos** (10 por
visita completada sincronizada, −100 por canje de un beneficio de cortesía; `negocio_ajustes.beneficio_fidelidad`); no es una
suscripción ni un plan de pago, y por eso **no** se mezcla con el filtro (sigue como KPI «Fidelidad» y en la ficha).
Dependencia: el saldo lee `citas.estado_pago`; los valores `parcial` y `por_verificar` los introduce el módulo de caja (p5). Hasta que
existan, el filtro solo ve `pendiente` (el comportamiento actual de la aplicación).

Los valores fuera de catálogo, arreglos (`x[]=…`), fechas imposibles, rangos invertidos y los importes mal escritos se rechazan con **422**
y un mensaje fijo que no refleja la entrada. Sin `clientes.analitica`, `gasto_*`, `saldo` y el orden por gasto/saldo también dan 422 (no se
ignoran en silencio).

## 2. Un solo universo

`CrmRepository::universo($c, $f)` = `desde()` + `donde()`. Lista, conteo, `totales()`, indicadores, citas por estado, altas/visitas/cobros
por mes, servicios, cohortes, fidelidad, distribución por segmentos y exportación parten de ese universo (los agregados se unen a
`universoIds()`). Excepciones documentadas: la **RFM/VIP** se calcula siempre sobre toda la base (los terciles dependen de la población) y
las cifras de *segmentos* y *comunicaciones* **omiten su propio filtro** para que cada cifra sea lo que mostraría el listado al elegirla.

## 3. «Listado» (`GET /admin/clientes/listado`)

Página propia (funciona sin JavaScript, enlaces y formulario GET; elegida sobre un modal porque conserva URL compartible, botón atrás,
foco natural y accesibilidad nativa). Parámetros: los mismos filtros + `indicador=` (catálogo `CrmService::INDICADORES`; el **servidor**
impone los filtros del indicador, la URL no puede contradecirlo). Alcance: `clientes.ver` (modo completo), profesional (solo sus clientes,
vista mínima), solo analítica → 403 (sin datos personales). Inventario de enlaces: `docs/R5_INVENTARIO_LISTADOS.md`.

## 4. Exportaciones (contrato)

`formato=csv|xlsx` (omitido = csv; otro valor, arreglo o vacío con espacios ⇒ **422**, auditado). Orden: autenticar → **autorizar en la
descarga** (403 + fila `denegada`) → validar (422 + fila `fallida`) → `Exportador::generar()` (flujo temporal, generadores, memoria acotada)
→ **auditar el éxito** (si falla, no sale ni un byte: 503) → `Exportador::entregar()`.

* Fila final **«Total»** con las mismas cifras que la pantalla (`CrmRepository::totales()`, `ReporteService::totalesBloque()`).
* Límite de filas (`CrmService::EXPORT_MAX_FILAS` = 10 000; `Exportador` 50 000 por omisión, 1 048 575 en XLSX): si se excede el archivo se
  llama `…_parcial.<ext>`, la **última fila** dice «AVISO…», la cabecera `X-Exportacion-Truncada: 1`, la auditoría guarda `truncado=1`, no hay
  fila «Total» y **antes de descargar** la pantalla del listado muestra un aviso `role="alert"` con el tope.
* Neutralización CWE-1236 en CSV (incluye espacios/tabuladores iniciales); en XLSX todo texto es cadena en línea, nunca fórmula.
* **Reportes**: el CSV multi-sección (`bloque=completo`, por omisión) se conserva tal cual y **no** se ofrece en XLSX (422). Los bloques
  tabulares `bloque=reservas|movimientos` salen en CSV o XLSX por `Exportador`. Los reportes se calculan en memoria por rango de fechas
  (acotado por la validación del rango), no por generador.
* **Contactos de crecimiento** (`POST /admin/crecimiento`, `accion=segmento`, `formato`): auditoría en `crm_exportaciones_auditoria`
  (`tipo=campana`, con política de consentimiento vigente). El repositorio limita a 1 000 contactos; si llega al tope se avisa como truncado.
* Auditoría CRM: `crm_exportaciones_auditoria` (022) ahora guarda `formato` real, `sha256` y filas; la pantalla `/admin/clientes/auditoria`
  muestra además las últimas exportaciones de otras secciones.

### `Services\ExportacionAuditoriaService` (migración 026, para p5 y futuras secciones)

```php
$aud = new Services\ExportacionAuditoriaService(new Repositories\ExportacionAuditoriaRepository(Model\ActiveRecord::getDB()));
$aud->registrar(string $origen, string $formato, array $actor, ?array $filtros, array $resultado): int
// $origen   'modulo.bloque' en minúsculas, [a-z0-9._-], 1–40 (p. ej. 'caja.pagos'); si no, InvalidArgumentException
// $formato  'csv' | 'xlsx' (otro valor se guarda 'invalido')
// $actor    ['id'=>?int,'rol'=>?string] → Services\ExportacionAuditoriaService::actorDeSesion() lo resuelve en servidor
// $filtros  filtros normalizados (se pasan por filtrosSeguros(): texto libre personal → _presente/_longitud/_hmac; claves tipo token/csrf/clave se descartan)
// $resultado ['resultado'=>'exitosa|denegada|fallida','motivo'=>?string] + (en éxito) el arreglo devuelto por Exportador::generar()
//            (filas, columnas, truncado, bytes, sha256)
```
Uso: denegado → `registrar($o, $f, $actor, null, ['resultado'=>'denegada','motivo'=>'sin_permiso'])` y responder 403 (las denegaciones
idénticas del mismo actor se agrupan 10 min); éxito → `registrar($o, $r['formato'], $actor, $filtros, $r + ['resultado'=>'exitosa'])` **antes** de
`Exportador::entregar($r)` (si lanza, no entregar: 503). Tabla `exportaciones_auditoria_general`: solo anexado por triggers; rollback 026
se niega (error 1242 / guardián) mientras tenga filas.

# CRM de clientes

Módulo de **solo lectura** para conocer a la base de clientes: listado con filtros, ficha 360°, indicadores, segmentación, gráficos y exportación CSV. No modifica datos, no envía mensajes y no usa IA ni predicciones.

| Ruta (todas GET) | Controlador | Permiso |
|---|---|---|
| `/admin/clientes` | `CrmController::index` | `clientes.ver`, `clientes.analitica` o `clientes.ver_propios` (el modo depende de cuál) |
| `/admin/clientes/ficha?id=N` | `CrmController::ficha` | `clientes.ver`, o `clientes.ver_propios` solo para clientes atendidos por ese profesional |
| `/admin/clientes/exportar?tipo=clientes\|comercial` | `CrmController::exportar` | `clientes.exportar` (y `clientes.ver`) |

Archivos: `repositories/CrmRepository.php` (SQL preparado, solo SELECT), `services/CrmService.php` (reglas, validación, RFM, exportación), `controllers/CrmController.php`, `views/crm/*.php`, `src/scss/componentes/_crm.scss`. No hay JavaScript: los gráficos son SVG/HTML renderizados en servidor, con descripción, valores en texto y tabla alternativa.

## Permisos

La autorización se comprueba en el servidor en cada petición con `puede()` / `exigir_permiso()` (rol efectivo desde la base, nunca `$_SESSION['admin']`). Los permisos vienen de `AuthorizationService` (no modificado).

| Rol | Qué ve |
|---|---|
| propietario | Todo: listado, ficha, gasto, VIP/RFM, retención, cohortes, gráficos financieros, exportación. |
| gerente | Igual que propietario en este módulo (`ver`, `analitica`, `exportar`). |
| recepción (`clientes.ver`) | Listado y ficha operativa **sin gasto**: sin importes, sin pagos, sin cobros/devoluciones en la cronología, sin VIP, sin repetición/retención/cohortes, sin gráfico de cobros. No exporta. El servidor ni siquiera consulta esos datos para este rol. |
| finanzas (`clientes.analitica` solo) | Indicadores agregados y gráficos (incluido gasto), **sin listado de personas, sin ficha y sin exportación**. |
| profesional (`clientes.ver_propios`) | «Mis clientes»: nombre, contacto operativo, visitas y última visita **con él/ella**, y en la ficha los servicios que realizó a esa persona. Sin gasto, sin consentimiento comercial, sin formularios, sin datos de otros profesionales. Cliente ajeno o inexistente: 404 indistinguible. |
| cliente / sin sesión | Sin sesión: redirección al inicio (302) o 401 si la petición pide JSON. Cliente autenticado: 403. |

Un fallo técnico de datos (conexión, esquema desactualizado) responde **503** (`ServiceUnavailableException`) y registra un diagnóstico sin datos personales; nunca se presenta como «sin permiso».

Los filtros `segmento=vip` y `orden=gasto` se rechazan (HTTP 422 con mensaje) sin permiso de analítica.

## Quién es «cliente»

Una cuenta es cliente si cumple **cualquiera** de:

1. tiene fila en `clientes` (membresía; estados `activo`/`suspendido`);
2. no tiene fila pero su rol efectivo es cliente: `COALESCE(usuario_accesos.rol, IF(usuarios.admin=1,'propietario','cliente')) = 'cliente'` (misma regla que `AuthorizationService::contexto()`; se muestra como membresía «sin registro»);
3. tiene alguna cita (decisión de este módulo, ver «Hipótesis»).

Una cuenta solo laboral (propietario, gerente, recepción, profesional o finanzas) sin membresía ni citas **no** cuenta. Una persona con rol laboral **y** fila en `clientes` sí cuenta. Los homónimos no se fusionan: toda agrupación es por `usuarios.id`.

## Definiciones de métricas

«Hoy» se calcula en `America/Guayaquil` en PHP y viaja como parámetro; el SQL no usa `CURDATE()`/`NOW()`. Una **visita** es una cita `completada` con fecha ≤ hoy. Estados `cancelada`, `no_asistio`, `confirmada` y `en_servicio` no son visitas. Todo periodo parte de dos fechas visibles (`desde`, `hasta`; por defecto los 12 meses calendario que terminan hoy; máximo 1.096 días; `hasta` nunca posterior a hoy).

| Métrica | Definición exacta |
|---|---|
| Clientes totales | Cuentas que cumplen la regla anterior, con desglose de membresía (activa, suspendida, sin registro) y de consentimiento. |
| Clientes nuevos (altas) | Filas de `clientes` con `creado_en` dentro del periodo. «Primera visita completada en el periodo» se informa **por separado** (primera visita histórica dentro del periodo). |
| Clientes activos | ≥ 1 visita con `DATEDIFF(hoy, fecha) ≤ N`, N = 90 por defecto, parametrizable 30–365 (`dias_activo`, visible en el formulario). Es independiente del segmento. |
| Frecuencia | Visitas completadas por cliente en el periodo. Media = visitas ÷ clientes con ≥ 1 visita en el periodo (denominador visible). |
| Recencia | `DATEDIFF(hoy, última visita completada)`. Media sobre clientes con alguna visita. |
| Repetición (solo analítica) | Clientes con ≥ 2 visitas en el periodo ÷ clientes con ≥ 1 visita en el periodo. |
| Retención por cohorte (solo analítica) | Cohorte = mes de la **primera** visita completada (≤ hoy), dentro del periodo; últimas 12 cohortes. «Repiten» = otra visita completada en un **día posterior** a la primera y dentro de 90 días, sin pasar de hoy (una segunda cita el mismo día no cuenta; las futuras tampoco). Denominador = clientes de la cohorte. Se marca «en maduración» si no han pasado 90 días desde el fin del mes. |
| Cancelaciones / no asistencia | Estados separados. Denominador = todas las citas de clientes con fecha en `[desde, min(hasta, hoy)]` (cualquier estado). Ventana y denominador se muestran en pantalla. |
| Gasto observado (solo analítica) | Cobro real = pago `completado` o `reembolsado` con proveedor distinto de `mock`, contado en `COALESCE(pagado_at, created_at)`. Devolución = pago `reembolsado`, restada en `COALESCE(reembolsado_at, pagado_at, created_at)`. Es el criterio de `ReporteService`: una devolución de otro mes resta en el mes de la devolución. Se atribuye a `pagos.usuario_id` o, si es NULL, al titular de la cita (`cita_id`/`cita_referencia_id`). Pagos `pendiente`/`fallido` no cuentan. |
| Pasarela simulada | Pagos con proveedor `mock` (o vacío, como en `ReporteService`): se muestran aparte («no es ingreso») y **nunca** se suman al gasto real. |
| Ticket | **Por cobro**: cobros reales brutos ÷ número de cobros reales del periodo. No es por prestación ni por visita y no descuenta devoluciones. |
| Fidelidad | Puntos vigentes = `SUM(fidelidad_movimientos.puntos)` (acumulados menos canjes); canjes pendientes y entregados (`tipo='canje'`). Los movimientos son los registrados: se materializan cuando el cliente consulta «Mi bienestar», así que pueden ir por detrás de las visitas. Cada movimiento es trazable en la ficha (referencia, cita, estado del canje, quién y cuándo lo entregó). |
| Consentimiento | `perfiles_usuario.marketing_email = 1` **y** `consentimiento_marketing_at` no nulo. Se muestra estado y fecha. El esquema **no guarda versión** del texto aceptado; la ficha lo dice. |

### Segmentos (excluyentes, evaluados en este orden)

Con hoy = fecha actual y N = ventana de activos:

1. **VIP** (solo analítica): ver RFM y recencia ≤ N.
2. **Nuevo**: `DATEDIFF(hoy, fecha de alta) ≤ 30` (constante `DIAS_NUEVO`).
3. **Activo**: última visita hace ≤ N días.
4. **En riesgo**: última visita hace entre N+1 y 2N días.
5. **Inactivo**: última visita hace más de 2N días, o ninguna visita y no es nuevo. Las cuentas legadas sin fecha de alta nunca son «nuevas».

Sin analítica, quienes serían VIP aparecen como Nuevo o Activo (el VIP usa gasto).

### RFM (solo analítica)

Población: clientes con ≥ 1 visita completada en los últimos 365 días. **Solo se calcula con ≥ 30 clientes en la población**; si no, la pantalla muestra el mensaje y no hay VIP.

Para cada dimensión se ordenan los valores y se toman los cortes `c1`, `c2` en las posiciones `ceil(n/3)` y `ceil(2n/3)`. Puntaje 3 si el valor supera `c2`, 2 si supera `c1`, 1 en otro caso. Los empates reciben el mismo puntaje y, sin variación, nadie llega a 3 (regla conservadora). R usa los días de recencia con el orden invertido (menos días es mejor); F son las visitas de los últimos 365 días; M es el gasto neto real de los últimos 365 días. **VIP = R 3, F 3 y M 3, y activo.** Si ningún cliente de la población tiene gasto real, M se omite y VIP = R 3 y F 3. La pantalla publica los cortes reales usados. Función pura y verificada: `CrmService::calcularRfm()`.

## Listado, filtros, orden y paginación

Filtros (GET, validados en servidor con mensajes en español y HTTP 422 si alguno es inválido; se aplican los valores válidos o por defecto): búsqueda `q` (nombre, apellido, nombre completo, correo, teléfono; hasta 80 caracteres; comodines `%` y `_` del usuario se escapan; el teléfono admite separadores), `desde`/`hasta`, `alta_desde`/`alta_hasta`, `profesional_id`, `servicio_id`, `segmento`, `membresia` (`activo`, `suspendido`, `sin_registro`), `consentimiento` (`si`/`no`), `dias_activo`, `orden` (lista blanca: nombre, alta, ultima, visitas, visitas_total y gasto con analítica) y `dir`, `por_pagina` (10, 25, 50 o 100; por defecto 25) y `pagina`. «Atendido por» y «Servicio» exigen ≥ 1 visita completada en el periodo con esa condición **sobre la misma cita**, y entonces «Visitas del periodo» cuenta solo las visitas que la cumplen. Una página fuera de rango se recorta a la última. `LIMIT/OFFSET` son enteros validados.

Los indicadores y gráficos describen a **todos** los clientes y no cambian con los filtros del listado (se dice en pantalla).

## Ficha 360°

Datos y membresía (incluido si también tiene acceso laboral), visitas y asistencia por estado, preferencias **realmente usadas** (servicios y profesionales más frecuentes en visitas completadas; sin inferencias), fidelidad con movimientos, consentimiento, lista de espera, gasto observado (analítica), citas (hasta 100) y **cronología** (alta, citas con estado y cancelaciones, cobros y devoluciones [analítica], puntos y canjes, consentimiento y lista de espera; hasta 150 eventos, de lo más reciente a lo más antiguo).

**Formularios privados: omitidos.** No existe un permiso específico para leer fichas de salud en este módulo, así que `formulario_respuestas` no se lee ni se muestra ni se exporta con ningún rol. La consulta existente en `/admin/crecimiento` (`perfiles.gestionar`) sigue siendo el único camino.

## Exportación CSV

- Solo `clientes.exportar` (propietario, gerente). GET sin efectos secundarios; respeta los filtros vigentes; máximo 10.000 filas (si se supera, una última fila avisa de la truncación).
- Cada celda pasa por `Services\ReporteService::sanearCeldaCsv()` (reutilizada, no duplicada): `=`, `+`, `-`, `@`, tabulación y saltos al inicio se neutralizan con una comilla simple. Efecto conocido: un teléfono que empieza por `+` o un importe negativo salen con `'` delante.
- `tipo=clientes`: ID, nombre, apellido, correo, teléfono, membresía, origen, alta, cuenta confirmada, consentimiento y su fecha, visitas, primera/última visita, recencia, segmento y, con analítica, cobros/devoluciones/neto real y neto simulado. Es una exportación operativa: incluye el indicador de consentimiento, no filtra por él.
- `tipo=comercial` («lista comercial»): ID, nombre, apellido, correo y fecha de consentimiento, **solo** de quienes aceptaron promociones (`marketing_email=1` con fecha), con cuenta confirmada y membresía no suspendida. Es una instantánea: vuelve a validar el consentimiento antes de enviar.
- Nunca incluye respuestas de formularios. Cabeceras: `text/csv; charset=UTF-8`, `attachment`, `nosniff`, `no-store`, BOM UTF-8.

## Accesibilidad y responsividad

Tablas dentro de regiones enfocables con `aria-label` y scroll horizontal; paginación con `nav`, `aria-current` y enlaces ≥ 44 px; gráficos con `role="img"`, título, descripción, valores en texto y tabla alternativa en `<details>`; color nunca como única señal (siempre hay etiqueta y valor); `prefers-reduced-motion` respetado. Revisado con Chromium headless a 320, 360, 390, 768, 1024 y 1440 px: sin desbordamiento horizontal de la página y sin controles menores de 44 px.

## Rendimiento y migración 019

**No se crea migración 019**: el EXPLAIN no demuestra que haga falta un índice. Medición con 20.000 clientes, 120.000 citas y 60.000 pagos (MySQL 8.0 local):

| Operación | Tiempo |
|---|---|
| Panel completo (analítica) | ≈ 3,1 s |
| Una página del listado | ≈ 0,7 s |
| Población RFM / ficha | ≈ 0,5 s |
| Exportación de 10.000 filas | ≈ 4,3 s |

`EXPLAIN ANALYZE` de la agregación de visitas por cliente (la consulta dominante) usa `idx_citas_estado`:

```
-> Aggregate using temporary table  (actual time=184..184 rows=12000)
   -> Filter: (citas.fecha <= DATE'2026-10-13')  (actual time=2.23..140 rows=72000)
      -> Index lookup on citas using idx_citas_estado (estado='completada')  (actual time=2.22..134 rows=72000)
```

Se probó `CREATE INDEX (estado, fecha, usuarioId)` en `citas`: el optimizador **no lo elige** por sí solo (sigue con `idx_citas_estado`) y el tiempo del panel no cambió (3.399 → 3.376 ms). Forzándolo con `FORCE INDEX` esa consulta baja de 184 a 94 ms, pero el coste total es de agregación sobre todas las citas completadas, no de falta de índice. Los pagos se filtran por `COALESCE(pagado_at, created_at)`, que ningún índice simple cubre, y se recorren de forma completa (≈ 40 ms por cada 60.000). El índice de prueba se eliminó. Para volúmenes muy superiores a los medidos, la salida correcta no es un índice sino una tabla resumen por cliente (fuera de alcance).

Optimizaciones aplicadas: `COUNT(*)` en lugar de `COUNT(DISTINCT)` cuando no hay unión con servicios; exportación por lotes de 2.500; cada consulta del panel se omite si el rol no ve ese dato.

## Pruebas

- `tests/Unit/CrmServiceTest.php`: RFM (menos de 30 clientes no calcula, terciles y fronteras calculados a mano, empates, ausencia de gasto, VIP exige actividad), validación de filtros (fechas imposibles, periodo futuro, ventana 30–365, listas blancas, parámetros en arreglo), paginación, formato y zona horaria.
- `tests/Integration/CrmIntegrationTest.php` (MySQL real, fixtures propias, conciliación contra cálculos independientes en PHP sobre filas crudas): cero actividad; cancelada/no asistió/futura no son visitas; devolución en otro mes; pago mock excluido; solo laboral excluido y doble perfil incluido; homónimos; segmentos y sus fronteras exactas; cohortes sin inflar con citas futuras ni mismo día; paginación (total, páginas, sin solapes, página fuera de rango); filtros combinados y búsqueda; ficha y cronología; permisos por URL directa para anónimo (302/401), cliente (403), recepción, profesional, finanzas, gerente y propietario; fallo técnico = 503; escape HTML; CSV con `=`, `+`, `-`, `@`; lista comercial; ausencia de respuestas de formularios en pantalla y CSV.

## Límites y hipótesis conocidos

- **Zona horaria de timestamps**: `clientes.creado_en`, `fidelidad_movimientos.creado_en`, `perfiles_usuario.consentimiento_marketing_at` y los `pagos.created_at` usan el reloj del servidor MySQL (`CURRENT_TIMESTAMP`/`NOW()`), que puede estar en UTC; `pagado_at` y `reembolsado_at` manuales se escriben en Guayaquil. El CRM interpreta cada DATETIME tal como está guardado: un alta entre las 19:00 y las 23:59 locales con MySQL en UTC aparece con la fecha del día siguiente. `citas.fecha` es una fecha civil local y no tiene este problema.
- «Cliente» incluye a quien tenga citas aunque no tenga membresía (hipótesis de este módulo, tomada del enunciado «sin membresía en `clientes` ni citas»).
- Pagos sin cita ni `usuario_id` no se atribuyen a nadie y no entran en el gasto de clientes.
- Sin tabla de auditoría de exportaciones (pendiente: registrar quién exportó, cuándo y con qué filtros).
- Puntos de fidelidad: solo los movimientos registrados; no se calculan puntos virtuales.
- La ficha limita citas (100), movimientos de fidelidad (100), pagos (100) y espera (50), y la cronología a 150 eventos.
- El enlace de navegación al módulo no está incluido (`views/layout.php` no es de este módulo); ver el fragmento en el informe de entrega.

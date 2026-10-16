# CRM de clientes

Módulo para conocer a la base de clientes: listado con filtros, ficha 360°, indicadores, segmentación, gráficos y exportación CSV (con auditoría). Las vistas de análisis son de **solo lectura**; las únicas escrituras del módulo son el registro append-only de exportaciones, el alta de un cliente por el equipo (con aviso de posibles homónimos) y su registro de decisiones. No envía mensajes y no usa IA ni predicciones.

| Ruta (todas GET) | Controlador | Permiso |
|---|---|---|
| `/admin/clientes` | `CrmController::index` | `clientes.ver`, `clientes.analitica` o `clientes.ver_propios` (el modo depende de cuál) |
| `/admin/clientes/indicadores` | `CrmController::indicadores` | `clientes.ver` o `clientes.analitica`; agregados sin tabla nominal |
| `/admin/clientes/ficha?id=N` | `CrmController::ficha` | `clientes.ver`, o `clientes.ver_propios` solo para clientes atendidos por ese profesional |
| `/admin/clientes/exportar?tipo=clientes\|comercial` | `CrmController::exportar` | `clientes.exportar` (y `clientes.ver`) |
| `/admin/clientes/auditoria` | `CrmController::auditoria` | `clientes.exportar` (solo lectura, paginada) |
| `/admin/clientes/nuevo` (GET y POST) | `AltaClienteController::nuevo` | `perfiles.gestionar` y `clientes.ver` (recepción, gerencia, propietario) |

Archivos: `repositories/CrmRepository.php` (SQL preparado, solo SELECT), `services/CrmService.php` (reglas, validación, RFM, exportación), `controllers/CrmController.php`, `views/crm/*.php`; auditoría: `repositories/CrmAuditoriaRepository.php`, `services/CrmAuditoriaService.php`, `services/AuditoriaHuella.php`; consentimiento: `repositories/ConsentimientoRepository.php`; alta y homónimos: `controllers/AltaClienteController.php`, `services/AltaClienteService.php`, `services/HomonimosService.php`, `repositories/AltaClienteRepository.php`; `src/scss/componentes/_crm.scss`. No hay JavaScript: los gráficos son SVG/HTML renderizados en servidor, con descripción, valores en texto y tabla alternativa.

## Permisos

La autorización se comprueba en el servidor en cada petición con `puede()` / `exigir_permiso()` (rol efectivo desde la base, nunca `$_SESSION['admin']`). Los permisos vienen de `AuthorizationService` (no modificado: no se añadieron permisos).

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
| Consentimiento | `perfiles_usuario.marketing_email = 1` **y** `consentimiento_marketing_at` no nulo. Se muestra estado, fecha y la **versión del texto** aceptado (ver «Versión del consentimiento»). «Versión anterior» = aceptó un texto que ya no es el vigente. |

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

Filtro de vínculo `vinculo`: `externo` (por defecto), `equipo`, `todos`. Se conserva también en descargas y auditoría.

Filtros (GET, validados en servidor con mensajes en español y HTTP 422 si alguno es inválido; se aplican los valores válidos o por defecto): búsqueda `q` (nombre, apellido, nombre completo, correo, teléfono; hasta 80 caracteres; comodines `%` y `_` del usuario se escapan; el teléfono admite separadores), `desde`/`hasta`, `alta_desde`/`alta_hasta`, `profesional_id`, `servicio_id`, `segmento`, `membresia` (`activo`, `suspendido`, `sin_registro`), `consentimiento` (`si` = aceptó cualquier versión, `vigente`, `anterior`, `no`), `dias_activo`, `orden` (lista blanca: nombre, alta, ultima, visitas, visitas_total y gasto con analítica) y `dir`, `por_pagina` (10, 25, 50 o 100; por defecto 25) y `pagina`. «Atendido por» y «Servicio» exigen ≥ 1 visita completada en el periodo con esa condición **sobre la misma cita**, y entonces «Visitas del periodo» cuenta solo las visitas que la cumplen. Una página fuera de rango se recorta a la última. `LIMIT/OFFSET` son enteros validados.

La base, los indicadores, sus listados y las descargas comparten los filtros. Por defecto se muestran clientes externos (`vinculo=externo`); `equipo` permite consultar perfiles de cliente del personal y `todos` reúne ambos. Las cuentas solo laborales sin membresía ni citas nunca se incluyen. La reportería tiene su propia vista en `/admin/clientes/indicadores`.

## Ficha 360°

Datos y membresía (incluido si también tiene acceso laboral), visitas y asistencia por estado, preferencias **realmente usadas** (servicios y profesionales más frecuentes en visitas completadas; sin inferencias), fidelidad con movimientos, consentimiento, lista de espera, gasto observado (analítica), citas (hasta 100) y **cronología** (alta, citas con estado y cancelaciones, cobros y devoluciones [analítica], puntos y canjes, consentimiento y lista de espera; hasta 150 eventos, de lo más reciente a lo más antiguo).

**Formularios privados: omitidos.** No existe un permiso específico para leer fichas de salud en este módulo, así que `formulario_respuestas` no se lee ni se muestra ni se exporta con ningún rol. La consulta existente en `/admin/crecimiento` (`perfiles.gestionar`) sigue siendo el único camino.

## Exportación CSV

- Solo `clientes.exportar` (propietario, gerente). GET sin efectos secundarios en los datos de clientes; respeta los filtros vigentes; máximo 10.000 filas (si se supera, una última fila avisa de la truncación).
- Cada celda pasa por `Services\ReporteService::sanearCeldaCsv()` (reutilizada, no duplicada): `=`, `+`, `-`, `@`, tabulación, retorno de carro y salto de línea al inicio (también tras espacios) se neutralizan con una comilla simple. Efecto conocido: un teléfono que empieza por `+` o un importe negativo salen con `'` delante. Pruebas con tabulador/CR solos y con fórmulas, y mutación que las rompe.
- `tipo=clientes`: ID, nombre, apellido, correo, teléfono, membresía, origen, alta, cuenta confirmada, consentimiento, su fecha, **versión del consentimiento** y **«Consentimiento con versión anterior»**, visitas, primera/última visita, recencia, segmento y, con analítica, cobros/devoluciones/neto real y neto simulado. Es operativa: informa, no filtra por consentimiento.
- `tipo=comercial` («lista comercial»): ID, nombre, apellido, correo y fecha de consentimiento, **solo** de quienes aceptaron promociones **con la versión vigente del texto** (`marketing_email=1`, con fecha), con cuenta confirmada y membresía no suspendida. Es una instantánea: vuelve a validar el consentimiento antes de enviar. Las columnas de esta lista no cambian.
- Nunca incluye respuestas de formularios. Cabeceras: `text/csv; charset=UTF-8`, `attachment`, `nosniff`, `no-store`, BOM UTF-8.

### Auditoría de exportaciones (migración 022)

Toda exportación deja una fila en `crm_exportaciones_auditoria`, **append-only**, sea exitosa, **denegada** (sesión sin `clientes.exportar`: 403 + intento registrado) o **fallida** (filtros inválidos → 422, fallo técnico → 503). La exportación de campañas de «Clientes y Fidelidad» (`/admin/crecimiento`, `CrecimientoController`, tipo `campana`) usa el mismo registro.

| Campo | Contenido |
|---|---|
| quién | `actor_usuario_id` y `actor_rol` (rol efectivo del servidor, no el declarado por el navegador). Sin FK: el registro sobrevive a cualquier cambio de cuentas. |
| cuándo | `ocurrido_en`, UTC (lo fija MySQL con `UTC_TIMESTAMP()`). La vista muestra UTC y la hora de Guayaquil. |
| qué | `formato` (csv), `tipo` (`clientes`, `comercial`, `campana`, `invalido`), `resultado`, `motivo` (`sin_permiso`, `filtros_invalidos`, `error_datos`…). |
| filtros | `filtros_json` normalizados (fechas, ids de profesional/servicio, segmento, membresía, consentimiento, orden…). **El texto de búsqueda no se guarda**: solo `q_presente`, `q_longitud` y `q_hmac` (HMAC con clave derivada de `APP_KEY`). `filtros_hash`: HMAC de todos los filtros. |
| tamaño | `filas`, `columnas_n`, `columnas_lista` (nombres de columna), `truncado`. |
| consentimiento | `consentimiento_version_id` (versión vigente al exportar) y `consentimiento_politica`: `solo_version_vigente` (lista comercial y campañas) o `informativo` (exportación operativa). |
| integridad | `contenido_sha256` (SHA-256 del archivo entregado: permite comprobar un archivo sin guardarlo). |
| origen | `ip_hash`: HMAC de la IP **truncada** (IPv4 /24, IPv6 /48), de `REMOTE_ADDR` (nunca de cabeceras reenviadas). |

No contiene celdas exportadas, correos, teléfonos, nombres ni la búsqueda en claro (prueba con datos reales que inspecciona la fila completa).

**Atomicidad**: el CSV se genera en memoria, se escribe la fila de auditoría y solo entonces se emiten cabeceras y cuerpo. Si la escritura falla, 503 y **ni un byte** del archivo (prueba con auditoría rota y con un trigger que falla; mutación que lo desactiva). Un fallo al auditar un intento denegado o fallido no cambia su respuesta (403/422/503) y queda en el registro de errores. Límite honesto: si la fila se escribe y la conexión del navegador se corta durante la descarga, la fila dice «exitosa» aunque el archivo no se haya recibido completo.

**Solo anexado, aunque seas el propietario**:
- la aplicación no tiene ningún `UPDATE`/`DELETE`/`TRUNCATE` sobre estas tablas (una prueba escanea todo el código) y la ruta de consulta no tiene método de escritura;
- la base de datos rechaza `UPDATE` y `DELETE` con triggers `BEFORE UPDATE/DELETE` (`SQLSTATE 45000`, error 1644), también para el usuario de la aplicación y para `root` por SQL directo;
- **limitaciones**: `TRUNCATE`/`DROP TABLE` no activan triggers y las bajas en cascada de `usuarios` tampoco (relevante solo para `consentimientos_historial`, que tiene FK con `ON DELETE CASCADE` a propósito para poder atender una supresión de datos personales). Mitigación recomendada en producción: que el usuario de la aplicación no tenga `DROP`/`ALTER`/`TRIGGER`/`DELETE`/`UPDATE` sobre esas tablas, p. ej. `REVOKE UPDATE, DELETE, DROP ON appsalon.crm_exportaciones_auditoria FROM 'app'@'%';` (y análogo para las otras tres), manteniendo `SELECT, INSERT`. Quien administre la base puede alterar cualquier cosa: el control es contra la aplicación y sus usuarios, no contra el DBA.
- Crear triggers exige `TRIGGER` y, con registro binario, `SUPER` o `log_bin_trust_function_creators=1`. La migración lo comprueba **antes** de cambiar nada (`022_…precheck.php`) y, si no se puede, no se aplica a medias.
- **Rollback seguro**: `022_…_rollback.sql` se niega (error 1242, sin cambios) si las tablas de auditoría o decisiones tienen filas, o si el historial de consentimiento tiene algo más que el retro-llenado o hay versiones > 1. Para revertir de todos modos hay que archivar y vaciar esas tablas a mano con privilegios de administración.
- Una exportación de un anónimo (302/401) no genera fila: no hay persona que auditar y sería una vía de inundación de la tabla. Un usuario autenticado sin permiso sí.

**Vista de consulta** `/admin/clientes/auditoria`: paginada (25/50/100), de la más reciente a la más antigua, con filtros por resultado, tipo, usuario y fechas (días de Guayaquil). Solo `clientes.exportar`; recepción, finanzas, profesional y cliente reciben 403 sin ver una fila. No se creó un permiso nuevo: `AuthorizationService` no se tocó.

## Versión del consentimiento (migración 022)

El consentimiento comercial sigue teniendo **una fuente única** (`perfiles_usuario.marketing_email` + `consentimiento_marketing_at`); ahora además guarda **qué texto se aceptó**:

- `consentimiento_versiones` (propósito, versión, texto, `texto_hash`, `vigente_desde` UTC, `es_historica`): **inmutable** (triggers). Vigente = la mayor versión ya en vigor. Publicar una nueva (`ConsentimientoRepository::publicar`, o directamente `INSERT`) no toca ningún consentimiento previo.
- `consentimientos_historial` (usuario, propósito, valor aceptó/retiró, versión, `version_informada`, origen, canal, actor, IP hasheada, fecha UTC): **append-only**; una fila por cambio real (no se escribe si no hay cambio).
- `perfiles_usuario.consentimiento_marketing_version_id`: versión del consentimiento **vigente** de la persona. `NULL` con consentimiento válido = «versión 1 histórica».
- **Se guarda la versión mostrada**, no la última: el formulario de Mi perfil y el de Mi bienestar llevan un campo oculto con el id de la versión que se está pintando; si entre tanto se publicó otra, se registra la que la persona vio. Una versión enviada que no exista, sea futura o de otro propósito se rechaza (422) y no guarda nada del perfil (transacción única). Sin campo (cliente antiguo) se asume la vigente y se marca `version_informada=0`.
- **Un consentimiento nunca sube de versión solo**: guardar el perfil con la casilla ya marcada no cambia nada; hay que marcar «He leído el texto actual y lo acepto (versión N)» (reconfirmación expresa). Un formulario viejo no hace retroceder.
- **Retirar nunca falla** por la versión: ante cualquier problema se registra con la vigente. El portal del equipo no toca el consentimiento (ni para otorgarlo ni para «corregirlo»).
- **Retro-llenado**: los consentimientos válidos (marca + fecha) pasan a la versión 1 «histórica / sin versión registrada»; se copia la fecha original tal cual (sin inventar ninguna) y el historial lleva `origen='migracion'`. `vigente_desde` de la versión 1 queda `NULL` (no se sabe desde cuándo regía). Marcas sin fecha no son consentimiento válido y no reciben versión. El texto de la versión 1 es el de «Mi perfil»; «Mi bienestar» tenía otra redacción equivalente y ahora ambos pintan el texto de la versión vigente.
- **Política decidida** para campañas y listas comerciales: **solo versión vigente**. Quien aceptó una versión anterior sigue registrado tal como lo dio (se ve en la ficha, el listado —badge «Versión anterior (vN)»— y el filtro `anterior`), pero queda fuera de la lista comercial y de la exportación de campañas hasta que reconfirme. Motivo: no se puede afirmar que aceptó el texto nuevo. Las exportaciones lo auditan (`consentimiento_politica`). Quitar el consentimiento sigue excluyendo de inmediato, como siempre.
- Zona horaria: la fecha de `perfiles_usuario` mantiene el reloj del servidor (`NOW()`); el historial usa UTC.
- Publicar una versión nueva hoy se hace por código (`ConsentimientoRepository::publicar($texto, $vigenteDesdeUtc)`) o SQL: no hay pantalla de administración (fuera de alcance).

## Alta de un cliente por el equipo y aviso de posibles homónimos

«Alta heredada» = el alta de un cliente hecha por recepción/gerencia/propietario (origen `recepcion`). Antes no existía pantalla (solo `UsuarioRepository::createCliente(…, 'recepcion')` sin llamadores); ahora está en `/admin/clientes/nuevo`.

- Nunca se fusiona ni se bloquea automáticamente: dos personas distintas pueden llamarse igual. Lo único que impide el alta es el correo (índice único de la migración 017) y la validación de datos.
- **Coincidencias** (`HomonimosService`, reglas puras): mismo nombre y apellido tras normalizar (minúsculas, sin tildes, la ñ como n, signos fuera, espacios simples; también en otro orden o repartido distinto entre nombre y apellido); nombre muy parecido (distancia de Damerau-Levenshtein ≤ 2 y ≤ 16 %, nombres completos de al menos 6 letras) o mismo apellido y primer nombre; mismo teléfono (últimos 9 dígitos). Se comparan en PHP sobre el universo de clientes del CRM (hasta 200.000 filas de 5 columnas): coste lineal, aceptable para miles de clientes.
- **Aviso**: la cuenta no se crea hasta que el operador pulsa «Es una persona distinta: crear cliente»; también puede «Cancelar y usar la ficha existente» (lo lleva a la ficha). Cada coincidencia muestra solo nombre, correo enmascarado (`a***@g***.com`), teléfono parcial (`••••4567`), última visita y por qué coincide, más un enlace a la ficha. La tecla Intro en un campo nunca confirma (el primer botón solo vuelve a comprobar).
- **No se puede forjar**: el servidor recalcula las coincidencias al recibir la confirmación y la acepta solo si `token_confirmacion` (HMAC ligado al operador, a la clave de operación del formulario y al **conjunto exacto de ids** coincidentes) corresponde. Sin token, token de otro operador, de otra clave, de otro conjunto o con una decisión distinta de `confirmar`: no se crea nada (409 si se intentó confirmar). Si aparece un homónimo nuevo mientras tanto, el token viejo deja de valer y se muestra el conjunto real.
- **Auditoría**: tabla propia `altas_homonimos_decisiones` (append-only; se justifica por tener otra forma que la de exportaciones): operador y rol, decisión `continuo`/`cancelo`, ids coincidentes, criterios, huella del token, `usuario_creado_id`, huella de la clave de operación y de la IP. La decisión «continuó» se escribe **en la misma transacción** que el alta (prueba con un trigger que falla: ni cuenta ni rastro de idempotencia). Un aviso mostrado sin decisión no deja fila.
- **Idempotencia**: con la clave de operación del formulario (`Classes\Idempotencia`), el doble clic no crea dos cuentas, no repite la decisión y **no vuelve a mostrar el aviso** tras confirmar (se consulta la idempotencia antes y otra vez antes de avisar, para cerrar la carrera). Probado con procesos PHP reales (misma clave: una cuenta y una decisión; misma cuenta de correo con claves distintas: gana una, la otra recibe «ya existe»).
- La cuenta creada: `confirmado=0`, contraseña aleatoria que nadie conoce, sin token, **sin correo enviado** y **sin consentimiento comercial** (solo lo otorga la propia persona). Para entrar la persona usa «Reenviar confirmación»: el enlace abre el formulario de activación y ahí ELIGE su contraseña definitiva (la aleatoria se descarta). Límite conocido: no hay aún un flujo de «invitación de cliente» por correo.
- Privacidad: solo roles con `perfiles.gestionar` + `clientes.ver` ven el formulario y el aviso; finanzas, profesional, cliente y anónimo reciben 403/302 sin pista alguna (prueba).
- Fuera de alcance: el alta heredada de **profesionales** (`/profesionales/crear`, área de agenda) sigue sin aviso de homónimos (ver `docs/ENTREGA_IDENTIDADES.md`).

## Accesibilidad y responsividad

Tablas dentro de regiones enfocables con `aria-label` y scroll horizontal; paginación con `nav`, `aria-current` y enlaces ≥ 44 px; gráficos con `role="img"`, título, descripción, valores en texto y tabla alternativa en `<details>`; color nunca como única señal (siempre hay etiqueta y valor); `prefers-reduced-motion` respetado. Revisado con Chromium headless a 320, 360, 390, 768, 1024 y 1440 px: sin desbordamiento horizontal de la página y sin controles menores de 44 px. La auditoría, el formulario de alta y el aviso de homónimos se revisaron además a 360, 768 y 1440 px (sin desbordes ni errores de consola; la tabla de auditoría se desplaza dentro de su región).

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
- `tests/Integration/CrmAuditoriaConsentimientoIntegrationTest.php`: auditoría (fila que coincide con lo exportado, sin PII, política de consentimiento, 403 con intento registrado, 422/503 registrados, atomicidad, solo anexado en BD y en el código, vista paginada y por permisos, fórmulas CSV), versionado (versión mostrada, no mejora silenciosa, opt-out siempre posible, equipo no toca, campañas, CRM distingue, ficha) y migración 022 (retro-llenado sin inventar fechas, reaplicable, rollback protegido).
- `tests/Integration/AltaHomonimosIntegrationTest.php`: reglas puras, homónimo exacto/tildes/parcial/teléfono/sin coincidencias, personas distintas con el mismo nombre tras confirmar, cancelar, tokens forjados, recálculo, correo duplicado, idempotencia, atomicidad, permisos y no filtración de PII, flujo HTML completo y procesos reales concurrentes.
- `tests/Integration/CrmIntegrationTest.php` (MySQL real, fixtures propias, conciliación contra cálculos independientes en PHP sobre filas crudas): cero actividad; cancelada/no asistió/futura no son visitas; devolución en otro mes; pago mock excluido; solo laboral excluido y doble perfil incluido; homónimos; segmentos y sus fronteras exactas; cohortes sin inflar con citas futuras ni mismo día; paginación (total, páginas, sin solapes, página fuera de rango); filtros combinados y búsqueda; ficha y cronología; permisos por URL directa para anónimo (302/401), cliente (403), recepción, profesional, finanzas, gerente y propietario; fallo técnico = 503; escape HTML; CSV con `=`, `+`, `-`, `@`; lista comercial; ausencia de respuestas de formularios en pantalla y CSV.

## Límites y hipótesis conocidos

- **Zona horaria de timestamps**: `clientes.creado_en`, `fidelidad_movimientos.creado_en`, `perfiles_usuario.consentimiento_marketing_at` y los `pagos.created_at` usan el reloj del servidor MySQL (`CURRENT_TIMESTAMP`/`NOW()`), que puede estar en UTC; `pagado_at` y `reembolsado_at` manuales se escriben en Guayaquil. El CRM interpreta cada DATETIME tal como está guardado: un alta entre las 19:00 y las 23:59 locales con MySQL en UTC aparece con la fecha del día siguiente. `citas.fecha` es una fecha civil local y no tiene este problema.
- «Cliente» incluye a quien tenga citas aunque no tenga membresía (hipótesis de este módulo, tomada del enunciado «sin membresía en `clientes` ni citas»).
- Pagos sin cita ni `usuario_id` no se atribuyen a nadie y no entran en el gasto de clientes.
- La auditoría es contra la aplicación y sus usuarios, no contra quien administra la base (ver «Solo anexado»).
- Puntos de fidelidad: solo los movimientos registrados; no se calculan puntos virtuales.
- La ficha limita citas (100), movimientos de fidelidad (100), pagos (100) y espera (50), y la cronología a 150 eventos.
- El enlace de navegación al módulo no está incluido (`views/layout.php` no es de este módulo); ver el fragmento en el informe de entrega. «Nuevo cliente» y «Auditoría de exportaciones» se enlazan desde la cabecera de `/admin/clientes`.


Organización actual de vistas, Recursos Humanos y carga semanal: [guía de gestión](GESTION_CLIENTES_RH_HORARIOS.md).

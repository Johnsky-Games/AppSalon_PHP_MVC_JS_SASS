# QA independiente — ronda 5

Suite `tests/Qa/R5Qa*.php` (116 pruebas en 6 clases) escrita **a partir de los requisitos del usuario y de `docs/R5_CONTRATOS.md`**, no del código de los otros agentes, con HTTP real (`php -S`) y Chromium real. Se ejecuta con `scripts_qa_r5.sh <árbol>` (rama del agente QA `wt/q5`) o, en CI, dentro de `tests/Qa`. Un 302 nunca se acepta como prueba de navegación: se verifica `Location` y la pantalla final.

## 1. Cobertura requisito → clase

| Requisito del usuario | Clase | Pruebas |
|---|---|---|
| A/B «Iniciar atención» y volver sin mezclar portales; clic real en Chromium; POST forjado; doble clic; concurrencia real de dos procesos; actor/fecha/antes/después; terminales inmutables; profesional propio vs ajeno | `R5QaAtencionTest`, `R5QaNavegadorTest` | 19 + 18 |
| Separación de portales: escáner de enlaces equipo↔cliente, doble membresía, suspensión por portal, sesión expirada/invalidada, rol cambiado en caliente, errores 403/404/409/422/500/503 desde acciones del equipo y del cliente | `R5QaPortalesTest` | 16 |
| C Cobro: efectivo con cambio, transferencia pendiente/verificada/rechazada, anticipo+saldo, exceso de saldo, devoluciones parciales/totales, manipulación de `cita_id`/monto/cliente, doble clic, idempotencia (misma clave otros datos = 409), dos procesos cobrando la misma cita, cobro fallido tras finalizar, recepción sin permiso financiero, comprobante único y coherente, conciliación `pagos`↔`caja_movimientos`↔`comisiones`↔`citas.estado_pago`, comisiones (redondeo acumulado, cambio de tarifa, pago tras devolución parcial) | `R5QaCobroTest` | 36 |
| D/E CRM: cada filtro y sus combinaciones, lista = indicadores = exportación, alcance por rol, chips/«Limpiar filtros»/paginación, sin suscripciones de pago inventadas | `R5QaCrmTest` | 13 |
| F Listado y exportaciones: Listado→detalle→CSV/XLSX con mismos registros y totales, descarga directa sin permiso (403 + auditoría), `formato` inválido, datos hostiles y fórmulas, XLSX leídos con **openpyxl 3.1.5 y LibreOffice**, miles de filas con tope visible | `R5QaExportacionTest` | 14 |
| UX/accesibilidad en navegador: modales (foco atrapado, Escape, retorno al disparador), botones «procesando» con **un solo POST real**, mensaje de exceso persistente, teclado/foco visible, sin desbordes de 320 a 1440 px | `R5QaNavegadorTest` | 18 |

## 2. Hallazgos reales que dejó la revisión independiente (corregidos)

| Hallazgo | Corrección |
|---|---|
| Comisión con pagos parciales deriva por redondeo por pago (10 × $0,15 al 10 % = $0,20 en vez de $0,15) | `ComisionService::devengar` redondea de forma acumulada por cita y tarifa |
| Celdas XLSX mayores al límite de Excel (32 767 caracteres) | El escritor las recorta con «…» |
| La tabla de clientes desbordaba la página en horizontal (textos solo-lectores `position:absolute` fuera del contenedor desplazable) | `.crm-tabla { position: relative }` |
| Páginas anónimas (`/mensaje`) sin `Cache-Control` al dejar de iniciar sesión por el menú | `Router::render` declara `no-store` si la pantalla no lo hizo |
| La guardia de portal respondía en lugar de la acción ante sesiones caducadas (perdía el JSON de su contrato) | `exigir_portal` solo actúa con sesión vigente |
| Un anónimo en pantallas del equipo iba al acceso del cliente | `/equipo/login` por prefijo de ruta |
| Las páginas de error pintadas dentro del layout general no llevaban `<meta robots noindex>` (lo detectó la etapa «diseño de errores» del E2E; la prueba exigía `noindex` en todo error) | `views/layout.php` lo emite cuando `ErrorResponse` pinta un error; prueba `testLaPaginaDeErrorDentroDelLayoutNuncaSeIndexa` |
| El mensaje de error no se anunciaba | `role="alert"` en la tarjeta de error |
| Dos cobros simultáneos de citas distintas se interbloqueaban (bloqueo de huecos en `idx_pagos_cita_id` al leer `pagos` con `FOR UPDATE` por rango) y tras 3 reintentos respondían 409. Lo detectó el CI de `9ec7eaf` (no se reprodujo en reposo; sí bajo carga de CPU) | Las operaciones de caja usan `READ COMMITTED` (sin bloqueos de hueco; la cita sigue bloqueada y la lectura ve lo último confirmado); 25 repeticiones bajo carga sin fallos |

## 3. Ajustes hechos a las pruebas (y por qué no reducen garantías)

Los hizo el líder al integrar, tras reproducir cada fallo; se listan para revisión:

| Prueba | Cambio | Motivo |
|---|---|---|
| `C2 transferencia rechazada` | Quien rechaza/verifica es otra persona (rol finanzas) y el rechazo lleva motivo; **se añadió** una aserción de que el registrante no ve «verificar» | La separación de funciones es la regla documentada del producto; el rechazo exige motivo |
| `C3 anticipo más saldo` | El «cobro de una cita ya pagada» se envía con el formulario abierto **antes** de pagar | La pantalla de una cita pagada ya no ofrece formulario (correcto) |
| `C4 devolución parcial y total` | Las reversas de comisión son negativas: se suma `devengo + reversa` | Error de signo en la prueba |
| `K5 filtros activos` | Se exige que no exista el **bloque de filtros activos** sin filtros aplicados | El formulario conserva siempre su propio «Limpiar filtros» |
| `X5 caja` | Los totales se contrastan con toda la caja del día (SQL), no con lo sembrado | La base de la clase acumula días de otras pruebas |
| `E cita de días anteriores` | (1.ª versión) «se registra con retraso»; **decisión del usuario:** vuelve a «no se inicia de forma ordinaria» (422, sin escribir) y se reprograma o regulariza. Mismo cambio en `R5DevAgendaTest`, `ReglaAtencionTest` y `R5AtencionCitaIntegrationTest` | Decisión del usuario, §5 |
| E2E de navegador (`tests/browser_e2e_test.js`, ADMIN-05) | La liberación del horario tras eliminar una cita se lee con una **sesión de cliente** (contexto aparte); además se exige que la sesión del equipo reciba 403 en `/api/disponibilidad` (desde una página auxiliar, para que ese 403 esperado no cuente como error de consola) | `/api/disponibilidad` es API del portal de clientes; antes la leía la sesión del equipo, que ahora se rechaza por diseño |
| Navegación (`tests/ui_navigation_test.js`) | El escenario simulado carga el catálogo real `includes/destinos.php`; el propietario ve 14 enlaces (nueva sección Caja) y finanzas ve `/caja` | El menú del equipo sale de `navegacion_portal()` y Caja es sección nueva de la ronda |
| Pruebas heredadas (matriz de autorización, redirecciones R2B, reversión de migraciones 022, esquema, R4) | Actualizadas a los contratos nuevos (anónimo→`/equipo/login`, equipo en pantallas del cliente→redirección/403, destino de cancelación, migraciones 023–026, bitácora de exportaciones, tabla de revisión de caja) | Cambios de contrato intencionados de la ronda, no relajaciones |

**Envío único y botón «procesando».** El script de navegador ejecuta, en el **mismo turno síncrono**, el primer clic, la lectura del estado inmediato y varios clics/`requestSubmit` adicionales; guarda el resultado en `sessionStorage` por si la navegación destruye el contexto y borra el valor previo antes de cada paso. Las pruebas exigen **a la vez** (a) estado de proceso (botón deshabilitado, `aria-busy` o texto «Registrando…») y (b) **exactamente 1 POST de red** al endpoint, contado con `page.on('request')`, no con el valor almacenado.

## 3 bis. Pruebas del integrador y su reverificación independiente

Escritas por quien integró (no por el QA), para cerrar huecos: `R5QaCobroTest::test_C3_cambio_de_tarifa…` y `::test_C4_pago_despues_de_devolucion_parcial…` (comisiones; viven en la clase del QA pero **las escribió el integrador**), el refuerzo del script de navegador, `tests/Integration/R5EnlacesCorreoTest.php`, `tests/Qa/R5IntEnlacesCorreoHttpTest.php` y `tests/Qa/R5IntInicioTardioTest.php`.

**Reverificación hecha por el QA independiente sobre `33605ff`** (clon y BD propios, mutaciones aplicadas y revertidas):

| Qué | Resultado |
|---|---|
| Aritmética de `C3_cambio_de_tarifa` y `C4_pago_despues_de_devolucion` | Correcta (100+200=300; devengo 300, reversa −100, nuevo devengo 100, neta 300) |
| Mutación: redondeo por pago (sin acumulado) | La detecta `C3_redondeo` (no `C3_cambio_de_tarifa`: sus importes son exactos) |
| Mutación: ignorar la tarifa configurada | La detecta `C3_cambio_de_tarifa` |
| Mutación: no revertir en proporción | La detectan `C4_devolucion_parcial_y_total` y `C4_pago_despues_de_devolucion` |
| Mutación: reversa sin descontar reversas previas | Solo la detectaba `C4_devolucion_parcial_y_total`; **hueco**: `C4_pago_despues_de_devolucion` hace una sola devolución |
| Envío único en navegador | Cobro e «Iniciar atención» exigen a la vez estado de proceso y 1 POST (`page.on('request')`). **Límite:** con el JS vacío el POST sigue siendo 1 (el navegador cancela la primera navegación); quien discrimina es el estado de proceso, no el conteo |
| `READ COMMITTED` en `CobroCitaService::ejecutar` | 11/11 pruebas de concurrencia; la exclusión por cita no se debilita (quitar los dos bloqueos rompe `C7`). Quitar solo `FOR UPDATE OF c` no rompe nada porque lo cubre el bloqueo de `pagos`: el bloqueo de cita aislado **no está cubierto**. No se probó con una transacción ya abierta |
| Inicio tardío | `R5QaAtencionTest` 19/19 y `R5IntInicioTardioTest` pasan |

Huecos que halló y que el integrador cerró después con pruebas propias (**no independientes**): `test_C4_dos_devoluciones_parciales_sucesivas…` (verificada con la mutación «reversa sin descontar previas»: falla), y en `R5IntInicioTardioTest` la ausencia tardía de una cita de hace más de un año, finalización por otra persona (`atencion_fin_por`) y fecha original conservada en `agenda_eventos`. Sin cubrir: profesional propio iniciando tarde.

## 4. Resultados (SHA y entorno)

* Código validado: `6b9ae6f`. **CI (PHP 8.2, MySQL 8.0): éxito completo** (PHPUnit, QA, imagen Docker, E2E en Apache, respaldo y restauración). **Local (nube, PHP 8.3, MySQL 8.0.46):** PHPUnit 1074 pruebas OK; QA 889 pruebas OK, 0 omitidas (Chromium real, XLSX leídos con openpyxl y LibreOffice).
* CI previos que fallaron y por qué: `9ec7eaf` (interbloqueo real en cobros simultáneos), `33605ff` (puertos del arnés de QA), `3d6152f` (E2E de Apache: contratos de la ronda y `noindex`). Detalle en `docs/ENTREGA_RONDA5.md` §8.
* Reverificación independiente de las pruebas del integrador: §3 bis.

## 5. Citas de días anteriores: decisión del usuario y su QA

El usuario decidió: sin inicio ordinario de una cita de un día anterior (reprogramar o **regularizar**), regularización exclusiva de propietario y gerente, el profesional solo solicita, nada se retrodata y las citas antiguas quedan «pendientes de revisión». Contrato: `docs/R5_REGULARIZACION.md`.

**QA independiente (clases `R5bQa*`, escritas desde el contrato, HTTP real y Chromium):**

| Requisito | Clase | Pruebas |
|---|---|---|
| Permisos por rol, portal, POST forjados, datos hostiles | `R5bQaPermisosTest` | 11 |
| Auditoría (quién/cuándo/efectiva), concurrencia real con procesos | `R5bQaAuditoriaTest` | 11 |
| Consistencia financiera (cobro con fecha real, comisión, liquidación cerrada, reportes servicio vs cobro, devolución) | `R5bQaFinanzasTest` | 6 |
| Pendientes de revisión (definición, alcance, aviso, sin escrituras al mirar) | `R5bQaPendientesTest` | 6 |
| Navegador (modal, «procesando» con estado real, sin desbordes 320–1440 px, foco) | `R5bQaNavegadorTest` | 6 |

**Hallazgos reales del QA sobre el primer WIP (todos corregidos):**

| Hallazgo | Corrección |
|---|---|
| Regularizar y rechazar respondían siempre 409: `affected_rows` leído tras cerrar el statement valía -1 | Comparación-y-cambio con `ROW_COUNT()` |
| Un éxito se mostraba como 500: el `catch (\Throwable)` capturaba la excepción de redirección | El controlador deja pasar `AppTerminationException` |
| Un propietario con doble membresía en el portal de clientes podía regularizar (el servicio ignoraba el portal) | El controlador exige el permiso con `exigir_permiso` (portal-aware) antes del CSRF |
| Fecha efectiva con byte NUL aceptada (`trim` lo eliminaba) | La fecha se valida sin recortar |
| `/caja` filtraba por día de la cita mientras el contrato decía «día del cobro» | Contrato precisado: `/caja` es la lista de citas por cobrar (día de la cita, la pantalla lo avisa) y el efectivo del día real se concilia en **Finanzas**; la prueba `test_F_caja_por_dia_real` se ajustó a ese contrato (**cambio de expectativa del integrador, a reverificar por el QA**) |

**Pruebas del integrador (NO independientes):** `R5RegularizacionIntegrationTest` (19), `R5RegularizacionConcurrenciaIntegrationTest` (6, procesos reales: dos regularizaciones, mismo formulario dos veces, regularizar vs ausencia, vs finalizar, solicitud vs regularización directa, dos rechazos), pruebas unitarias de `esPendienteRevision` y los ajustes de contrato de la matriz de autorización (3 rutas POST + `/agenda/pendientes` + permisos), `EsquemaTest` (50 tablas) y `MatrizHttp`.

**Observación sobre `finalizar` vs regularizar:** si la regularización gana, «Finalizar» encuentra la cita ya completada y responde idempotente («Estado ya actualizado») sin escribir; la prueba exige una única escritura.

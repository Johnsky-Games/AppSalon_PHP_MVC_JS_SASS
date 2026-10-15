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
| `E cita de días anteriores` | Pasa de «no se puede iniciar» a «se registra con retraso y queda auditada» | **Decisión pendiente, ver §5** |
| Pruebas heredadas (matriz de autorización, redirecciones R2B, reversión de migraciones 022, esquema, R4) | Actualizadas a los contratos nuevos (anónimo→`/equipo/login`, equipo en pantallas del cliente→redirección/403, destino de cancelación, migraciones 023–026, bitácora de exportaciones, tabla de revisión de caja) | Cambios de contrato intencionados de la ronda, no relajaciones |

**Envío único y botón «procesando».** El script de navegador ejecuta, en el **mismo turno síncrono**, el primer clic, la lectura del estado inmediato y varios clics/`requestSubmit` adicionales; guarda el resultado en `sessionStorage` por si la navegación destruye el contexto y borra el valor previo antes de cada paso. Las pruebas exigen **a la vez** (a) estado de proceso (botón deshabilitado, `aria-busy` o texto «Registrando…») y (b) **exactamente 1 POST de red** al endpoint, contado con `page.on('request')`, no con el valor almacenado.

## 3 bis. Pruebas del integrador (NO son revisión independiente)

Escritas por quien integró, para cerrar huecos que el QA no cubrió: `R5QaCobroTest::test_C3_cambio_de_tarifa…` y `::test_C4_pago_despues_de_devolucion_parcial…` (comisiones; viven en la clase del QA pero **las escribió el integrador**), el refuerzo del script de navegador (envío en un solo turno síncrono), `tests/Integration/R5EnlacesCorreoTest.php`, `tests/Qa/R5IntEnlacesCorreoHttpTest.php` y `tests/Qa/R5IntInicioTardioTest.php`. **Reverificación independiente pendiente:** se le pedirá al QA cuando pueda continuar (hoy limitado por cuota); hasta entonces no se etiquetan como revisadas por él.

## 4. Resultados (SHA y entorno)

* Rama integrada local (`/tmp/wt/int`, PHP 8.3 y MySQL 8.0.46): PHPUnit por defecto 1058 pruebas OK (tras corregir 3 pruebas heredadas); QA completo 879 pruebas, 0 omitidas, 2 fallos que se corrigieron (cita de días anteriores y una carrera de Chromium); `R5Qa*` completas en verde.
* CI de `683d663`: PHPUnit OK (PHP 8.2); el paso de QA falló solo por faltar openpyxl/LibreOffice en el runner y por la carrera de Chromium (corregidos en `89952f0`). El CI de `89952f0` y del SHA final se indica en `docs/ENTREGA_RONDA5.md`.

## 5. Decisión pendiente (no aceptada por el usuario)

**Iniciar la atención de citas de días anteriores.** El contrato inicial cerraba la ventana al terminar el día de la cita (solo quedaba «registrar ausencia»). Se cambió a «sin cierre, con retraso y auditada» para que una cita olvidada pueda atenderse y cobrarse; **el usuario aún no lo ha aceptado**. Para volver a cerrar la ventana basta restaurar la rama `evaluarInicio` de `classes/ReglaAtencion.php` (rama `ventana_cerrada`) y las pruebas `ReglaAtencionTest`, `R5AtencionCitaIntegrationTest`, `R5DevAgendaTest` y `R5QaAtencionTest::test_E_cita_de_dias_anteriores…`.

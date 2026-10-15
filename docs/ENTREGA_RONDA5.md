# Entrega ronda 5 — agenda → atención → finalización → cobro → comprobante, portales separados, segmentación y exportaciones

Rama `claude/blissful-hopper-f5k7a0` (sin merge a `main`, sin despliegue, sin PR). Parte de `3c71d3d`. El SHA final y el CI figuran en §8.

## 0. Estado honesto — leer primero

* **Tu equipo NO está validado.** Todo se hizo y se probó en la nube (Linux, sin tus contenedores, `.env`, volúmenes ni respaldo). `docs/IDENTIFICAR_ENTORNO_LOCAL.md` y `docs/GUIA_ACTUALIZACION_RONDA5.md` dan los comandos para tu entorno; la validación en tu computadora queda fuera de esta fase.
* **Decisión tomada por ti — citas de días anteriores:** no hay inicio ordinario de una cita de un día anterior; se **reprograma** (con las validaciones habituales) o el propietario/gerente la **regulariza** (motivo + fecha/hora efectiva, nunca futura; quién y cuándo se registran aparte), el profesional puede **solicitarlo** pero no aprobarlo, y las citas antiguas sin resolver quedan **pendientes de revisión** sin marcarse solas. Detalle, efecto en reportes y comisiones y pruebas: `docs/R5_REGULARIZACION.md` (migración 027).
* **Otras decisiones de negocio tomadas (revísalas):** margen de 60 minutos para iniciar antes de la hora; «Finalizar y cobrar» solo para quien tiene permiso de agenda **y** de finanzas (propietario y gerente), recepción no cobra; quien registra una transferencia no la verifica (salvo el propietario); «suscripción» del CRM = suscripción a comunicaciones comerciales (no existe suscripción de pago); el comprobante es interno.
* **Enlaces de correos y notificaciones:** auditados (inventario, corrección del enlace de sitio en correos del equipo y pruebas de contenido y HTTP), ver `docs/R5_MATRIZ_DESTINOS.md` §7.
* **No hecho / pendiente real:** factura fiscal electrónica (no existe integración); adjuntar comprobante de transferencia; permiso propio para que recepción cobre; algunos informes finales de los agentes de desarrollo no se recibieron por la misma limitación (el trabajo está en sus commits y se integró y probó).

## 1. Causa raíz de cada fallo observado (reproducida con HTTP real)

| Fallo | Causa raíz |
|---|---|
| A «Iniciar atención» lleva a una página inesperada | La agenda ofrecía el botón en **toda** cita confirmada, también futuras; el servidor respondía 422 «La atención todavía no ha comenzado» y el controlador lo pintaba como HTML crudo sin diseño; en el caso exitoso se perdía el período consultado |
| B «Volver a las citas» abre `/mis-citas` con «Reservar una cita» | Enlace fijo a `/mis-citas` en esa página de error, y `GET /mis-citas` no comprobaba el portal (una sesión de equipo recibía la pantalla del cliente); además el «inicio» de los errores usaba `$_SESSION['rol']`, que el login de equipo no escribe |
| C Sin ruta para atender y cobrar | El cobro de una cita solo existía tecleando su ID en Finanzas; cobro siempre «completo y de una vez», sin saldo, verificación de transferencia ni comprobante |
| D Faltan filtros de clientes | No había filtros de cuenta, membresía ni consentimiento, ni chips, ni «Limpiar filtros» |
| E «Listado» sin lista útil | La columna no abría la lista del segmento ni ofrecía descarga |
| F Exportaciones | Solo CSV, armado por cada sección; ningún XLSX real |

## 2. Qué se implementó

* **Atención** (`ReglaAtencion`, `CitaRepository::mutarAgenda`, migraciones 023 y 027, `GET /agenda/cita`, `GET /agenda/pendientes`): una sola regla para vista y servidor; transiciones confirmada→en_servicio→completada y confirmada→no_asistio, terminales inmutables, atómicas, idempotentes y auditadas (actor, fecha y hora, estado anterior y nuevo); detalle de la cita; acciones por permiso del servidor.
* **Portales y destinos** (`includes/destinos.php`, `includes/portales.php`): catálogo central; guardias de portal; errores de operación dentro del layout con «Volver» correcto; navegación por portal; anónimo en pantallas del equipo → `/equipo/login`. Matriz en `docs/R5_MATRIZ_DESTINOS.md`.
* **Cobro, caja y comprobante** (migraciones 024 y 025): ver `docs/CAJA_Y_COBROS.md`. Incluye corrección de comisiones con pagos parciales (redondeo acumulado).
* **CRM** (`docs/R5_CRM_FILTROS.md`, `docs/R5_INVENTARIO_LISTADOS.md`): filtros de cuenta, membresía, comunicaciones comerciales (versión y fecha), actividad, fechas, profesional, frecuencia y gasto; chips y «Limpiar filtros»; lista = indicadores = exportación; «Listado» abre la lista real, paginada, con descarga.
* **Exportaciones** (`classes/Exportacion`, migración 026): fachada única CSV/XLSX; XLSX real en flujo **sin dependencias nuevas** (el contenedor no instala `ext-zip`); fórmulas neutralizadas, importes desde centavos, celdas recortadas al límite de Excel, tope de filas con aviso, autorización y auditoría previas a la entrega.

## 3. Migraciones

023 `atencion_citas`, 024 `cobros_verificacion`, 025 `comprobantes`, 026 `exportaciones_auditoria_general`, 027 `regularizacion_atencion` (regularización de citas de días anteriores): con guardián y reversión segura (se niegan si perderían datos de negocio). Tablas del esquema migrado: 42 → 50.

## 4. Resultados por suite (sin sumar)

Se detallan con SHA en §8. Resumen de lo comprobado en la rama integrada (nube): PHPUnit por defecto 1073 pruebas; QA completo (`tests/Qa`) 889 pruebas con 0 omitidas; clases `R5Qa*` (116 pruebas) en verde; CSV y XLSX generados validados con openpyxl 3.1.5 y LibreOffice. El E2E de navegador con Chromium cubre el clic en «Iniciar atención», «Finalizar y cobrar», modales y descargas.

## 5. Ejemplos ficticios

`docs/ejemplos_r5/`: `comprobante_ejemplo.html` (comprobante interno CI-000077), `caja_ejemplo.csv`, `caja_ejemplo.xlsx`. Generados por el propio código con datos de prueba inventados.

## 6. Hallazgos del QA independiente y ajustes a pruebas

Ver `docs/R5_QA_INDEPENDIENTE.md` (hallazgos reales corregidos, tabla de pruebas ajustadas con su motivo y cómo se demuestra «un solo envío real» con botón en proceso).

## 7. Riesgos que quedan

* Las pruebas de Chromium dependen del navegador del runner; se endureció la captura del estado, pero conviene vigilar su estabilidad.
* La verificación de transferencias es manual (sin conciliación con extractos bancarios reales).
* Una exportación grande se genera en un archivo temporal antes de entregarse (memoria acotada, disco temporal).
* No se ejecutó nada contra una instalación real con datos del usuario.

## 8. Rama, SHA y CI

Rama `claude/blissful-hopper-f5k7a0` (sin merge a `main`, sin despliegue, sin PR).

| SHA | Qué es | Resultado real |
|---|---|---|
| `89952f0` | Primer CI con lectores XLSX | Pasó PHPUnit y el QA de navegador; no validó los cambios posteriores |
| `9ec7eaf` | Auditoría de enlaces de correo | CI **falló** en PHPUnit: interbloqueo real en cobros simultáneos de citas distintas (bloqueos de hueco en `idx_pagos_cita_id`) |
| `b2aaef9` | Corrección (`READ COMMITTED` en las operaciones de caja) | Local: QA 887 OK, 0 omitidas |
| `33605ff` | Ajuste de dos pruebas de concurrencia | CI **falló** solo en QA: «Sin puertos HTTP libres» (las clases QA antiguas y las R5 competían por 8150-8159 con orden aleatorio); PHPUnit completo pasó |
| `3d6152f` | Puertos R5 de 8199 hacia abajo; cierre de servidores con su árbol; pruebas del integrador adicionales | CI **falló** solo en el E2E de Apache (primera vez que llegaba): `/api/disponibilidad` leída con sesión del equipo (ahora 403 por diseño), menú del equipo sin catálogo en la prueba de navegación y falta de `noindex` en errores dentro del layout. PHPUnit, QA e imagen Docker pasaron |
| **`6b9ae6f`** | **Código validado: corrige lo anterior (`noindex` en errores, E2E y navegación ajustados)** | **CI run 71 en éxito completo:** PHPUnit (PHP 8.2, orden aleatorio), QA con openpyxl y LibreOffice, imagen Docker, E2E en Apache con SMTP simulado, respaldo y restauración SHA-256. **Local (nube, PHP 8.3, MySQL 8.0.46, bases nuevas): PHPUnit 1074 pruebas OK; QA 889 pruebas OK, 0 omitidas.** El E2E se ensayó además en emulación local (`php -S`, sin Apache): 8 etapas OK |

Los commits posteriores a `6b9ae6f` son solo de documentación (no cambian código ni pruebas); su CI, si corre, no es una validación nueva del código.

Cambios de expectativas de pruebas y su justificación: `docs/R5_QA_INDEPENDIENTE.md` §3. Pruebas del integrador (no independientes) y su reverificación: §3 bis.

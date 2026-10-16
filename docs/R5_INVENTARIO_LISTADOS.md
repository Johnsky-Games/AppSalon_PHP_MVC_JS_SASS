# Inventario de «Listado», «Ver detalle», «Exportar» y «Descargar» (ronda 5, módulo c5)

Búsqueda: `grep -rniE "listado|ver detalle|exportar|descargar|ver (clientes|lista|todo|más|pagos|historial)" views src/js` y rutas de exportación en
`public/index.php` + `includes/rutas/*.php`. Estado: ✔ corregido y probado · ➖ no aplica / sin cambio (con motivo) · ⏳ de otro módulo.

| # | Dónde | Texto del enlace | Qué mostraba antes | Qué muestra ahora | Estado |
|---|---|---|---|---|---|
| 1 | CRM · tabla «Segmentos y reglas», columna **Listado** (`views/crm/index.php`) | «Ver clientes <segmento>» | Recargaba `/admin/clientes?segmento=…`: la misma página, con la lista enterrada al final y sin foco en ella | `/admin/clientes/listado?segmento=…` + filtros vigentes: página propia con el conteo, la tabla paginada y ordenable, chips, descargas. La cifra de la fila = total del listado. Sin clientes: «Ninguno con estos filtros» (sin enlace) | ✔ `testListaConteoTotales…`, `R5DevCrmListadoTest` |
| 2 | CRM · tarjeta «Clientes totales» y membresías (activas / suspendidas / legadas) | (no había enlace) | Solo una cifra | «Ver listado de …» por cada cifra → `indicador=totales\|membresia_activo\|membresia_suspendido\|membresia_sin_registro` | ✔ `testCadaIndicadorConListadoCoincideConSuCifra` |
| 3 | CRM · «Altas en el periodo» | — | Cifra | `indicador=altas` (alta dentro del periodo) | ✔ |
| 4 | CRM · «Clientes activos (N días)» | — | Cifra | `indicador=activos` (`actividad=reciente`) | ✔ |
| 5 | CRM · «Visitas completadas» | — | Cifra | `indicador=con_visita` (clientes con ≥1 visita en el periodo) | ✔ |
| 6 | CRM · «Repetición en el periodo» (solo analítica) | — | Cifra | `indicador=repiten` (≥2 visitas) | ✔ |
| 7 | CRM · «Consentimiento comercial» y nueva «Suscripción a comunicaciones comerciales» | — | Cifra | `consentimiento_si\|vigente\|anterior`, `comunicaciones_revocado`, `comunicaciones_sin_decision` | ✔ |
| 8 | CRM · «Cuentas sin confirmar» (nueva) y «Saldo pendiente de cobro» (nueva, solo analítica) | — | — | `indicador=cuenta_sin_confirmar`, `indicador=con_saldo` | ✔ |
| 9 | CRM · «Recencia media», «Cancelaciones», «No asistencia», «Fidelidad», «Ticket», «Pasarela simulada», «Clientes con movimiento de dinero» | — | Cifras | Sin enlace a propósito: son promedios, porcentajes de citas o sumas de dinero/puntos, no un conjunto de clientes (un listado no las «explicaría»). Su definición está en «Cómo se calcula» | ➖ |
| 10 | CRM · sección «Listado de clientes» (`index`) | (no había) | Tabla embebida | La misma tabla (`_tabla_clientes.php`) + «Abrir como página completa» → `/admin/clientes/listado` con los mismos filtros | ✔ |
| 11 | CRM · «Exportar clientes (CSV)» / «Exportar lista comercial (CSV)» | solo CSV | CSV armado en memoria, sin XLSX, sin totales ni aviso de límite | CSV **y** XLSX de clientes y de lista comercial, mismos filtros, fila «Total», aviso de truncado previo y en el archivo, auditoría previa a la entrega | ✔ |
| 12 | CRM · «Auditoría de exportaciones» | — | Solo exportaciones del CRM | Añade formato real y una sección con las últimas exportaciones de otras secciones (migración 026) | ✔ |
| 13 | CRM · tabla del listado: nombre del cliente → ficha | «Nombre» | Ficha 360° | Sin cambio (alcance: `clientes.ver`; el profesional solo sus clientes) | ➖ |
| 14 | Reportes · «Exportar CSV» (`views/admin/reportes.php`) | «Exportar CSV» | CSV multi-sección | Se conserva (id `btn-exportar-csv`) + «Reporte completo (CSV, varias secciones)», «Reservas (CSV / Excel XLSX)», «Movimientos (CSV / Excel XLSX)»; el reporte completo no se ofrece en Excel porque no es una tabla plana | ✔ `R5ExportacionesIntegrationTest` |
| 15 | Reportes · sección «Listado Detallado de Reservas» y «Historial de Movimientos Financieros» | (encabezados) | Lista/tabla sin totales | Se añade el total del listado y de movimientos (mismas cifras que la fila «Total» de los archivos) | ✔ |
| 16 | Crecimiento · «Exportar contactos CSV» (`views/crecimiento/index.php`, formulario POST) | «Exportar contactos CSV» | CSV sin BOM ni totales | Selector de formato CSV/XLSX, auditoría previa a la entrega, 403 auditado sin permiso, aviso al llegar al tope de 1 000 | ✔ (cambio mínimo en vista ajena, ver informe) |
| 17 | Panel admin (`views/admin/index.php`) «Importe de servicios · Importe listado» | — | Texto, no enlace | Sin cambio | ➖ |
| 18 | Citas del cliente (`#listado-mis-citas`, `src/js/app.js`) | — | Lista dinámica del portal del cliente | Fuera de alcance de c5 (portal de clientes, n5/a5) | ⏳ |
| 19 | Caja `GET /caja/exportar` | — | — | Lo implementa p5; debe usar `Exportador` + `ExportacionAuditoriaService` (contrato en `docs/R5_CRM_FILTROS.md` §4) | ⏳ |
| 20 | Equipo · permiso «Exportar listados de clientes» (`clientes.exportar`) | texto | Etiqueta del permiso | Sin cambio: rige ahora CSV y XLSX | ➖ |

No se halló ningún otro «Ver detalle» ni «Descargar» en `views/` ni en `src/js/` (los "Volver a …" son navegación, no listados).
Las rutas que entregan archivos son exactamente cuatro: `/admin/clientes/exportar`, `/admin/reportes/exportar`, `POST /admin/crecimiento`
(contactos) y `/caja/exportar` (p5, pendiente de integrar).

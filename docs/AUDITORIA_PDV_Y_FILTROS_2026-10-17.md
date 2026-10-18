# Auditoría de continuidad operativa y propuesta de PDV

Fecha: 17 de octubre de 2026, America/Guayaquil.

## 1. Alcance y evidencia

Base publicada examinada: `main`, commit `530009e52561e2c0ee0363165bfd8bf246a9559b`. El código local inspeccionado está en `298b6c02ddfd1245629305cff06274fb12271249`; ambos comparten exactamente el árbol `f58966872896c49a617c08725b0c12fef0e61c0a`.

Se revisaron arquitectura MVC, rutas, permisos, identidades, RH/profesionales, agenda, CRM, caja/pagos, comprobantes, inventario, comisiones, fidelidad, reportes, filtros, estilos, migraciones, Docker y CI. También se contrastó la captura adjunta de Agenda de atención. Es una auditoría transversal de código y diseño de negocio; no es una prueba de penetración exhaustiva ni una inspección de la base o Docker del equipo Windows del usuario.

El CI del PR #3 terminó correctamente sobre este árbol: https://github.com/Johnsky-Games/AppSalon_PHP_MVC_JS_SASS/actions/runs/39082716474. Al cierre de la auditoría también se verificó la ejecución de `main` posterior a la fusión, completada con éxito: https://github.com/Johnsky-Games/AppSalon_PHP_MVC_JS_SASS/actions/runs/39089383107. No se presenta la suite histórica como una ejecución nueva de esta auditoría. Las cifras de PHPUnit y QA corresponden a suites con solapamiento y no deben sumarse como pruebas únicas.

Esta entrega documenta hallazgos y una propuesta; no modifica el comportamiento de la aplicación, no aplica migraciones ni publica cambios en el repositorio.

Comprobación nueva realizada en esta auditoría: reproducción de FIN-01 sobre MySQL 8.0.46 y PHP 8.3, con esquema/migraciones reales en una base efímera `appsalon_fixture_auditoria_pdv_*_test`, eliminada al terminar. Se usaron `R5CajaFixture`, `CobroCitaService::cobrar`, `CobroCitaService::devolver` y `CrmRepository::gastoDeCliente` sin modificar esos componentes. El cobro fue de 2000 centavos y la devolución parcial de 500, el mismo día. Caja devolvió cobrado=2000, devuelto=500, neto=1500; CRM devolvió cobros_real=2000 y dev_real=0 (neto=2000). El hallazgo quedó reproducido, no solo inferido del SQL. No se ejecutó de nuevo la suite completa ni el navegador durante esta auditoría; la revisión visual usa la captura y los estilos del código.

## 2. Diagnóstico

La aplicación tiene bases útiles para evolucionar a un punto de venta: identidad laboral, servicios con precios históricos, cobros parciales, verificación de transferencias, devoluciones, movimientos de caja, comisión y comprobante interno. El problema central es que el documento comercial todavía es la cita. No existe una venta con líneas de servicios y productos que sea independiente de la reserva y que conecte inventario, cobros, impuestos, descuentos y facturación.

Se recomienda ampliar el monolito MVC con servicios transaccionales por dominio. No se justifica introducir microservicios para resolver esta necesidad. La interfaz de PDV debe entrar en la misma aplicación, con su autorización, cliente, colaborador y trazabilidad existentes.

## 3. Mapa actual y brechas

| Área | Implementado y reutilizable | Brecha para el objetivo |
|---|---|---|
| Identidad | Usuarios, membresía de cliente, colaboradores e invitaciones; permisos en servidor | Comprador fiscal puede necesitar identificación y datos que no son credenciales de acceso; no exigir crear cuenta para vender |
| RH y profesionales | Vínculo único; configuración compartida; suspensión/baja preservan ficha e historial | CTA ausente en tarjetas antiguas; reconciliación con un colaborador ya existente necesita un flujo explícito |
| Agenda | Disponibilidad, horarios, descansos, estados de atención, regularización y reasignación | Filtros visualmente distintos; sin atajos de período; lista limitada a 500 sin paginación real |
| Caja por cita | Efectivo/cambio, anticipos, parciales, transferencias pendientes/verificadas y devoluciones parciales | No carrito, venta de mostrador ni detalle de productos; no caja por terminal/turno |
| Finanzas | Ingresos/egresos libres y liquidaciones | Suma varios medios; no representa efectivo contado ni libro contable completo |
| Productos | SKU, unidad, stock mínimo, costo, saldo y movimientos manuales | Precio de venta, impuestos, código de barras, clasificación venta/consumo, edición y estado comercial |
| Inventario | Milésimas, control de stock, bloqueo e idempotencia | Referencia estructurada a venta/devolución/consumo; reservas, conteos, proveedores y valoración |
| Comisiones | Porcentaje por profesional, tarifa histórica por pago, reversas y liquidación | Atribución por línea, venta de productos, varios beneficiarios, criterio de devengo y tarifas con vigencia |
| Crecimiento | Lista de espera, consentimiento, formularios, puntos y entrega de beneficios | No motor de promociones/cupones ni integración económica del beneficio con la venta |
| Comprobantes | Recibo interno numerado, instantánea, hash y devolución | Documento fiscal, impuestos, XML/firma, autorización, RIDE y notas de crédito |
| Pasarela | Interfaz y simulador; producción bloquea proveedores no implementados | Adaptador real, conciliación, eventos auténticos y liquidación bancaria |
| CRM/reportes | Listado, analítica, exportaciones y fechas operativas diferenciadas | Conciliación de devoluciones parciales y futura cobertura de ventas sin cita |
| Operación | Migraciones incrementales, respaldo/restauración y CI amplio | Despliegue de producción separado del montaje local; mínimos privilegios, monitoreo y alertas |

## 4. Hallazgos priorizados

Prioridades: P0 = requisito previo para habilitar PDV real; P1 = resolver primero en el producto actual; P2 = ampliación y robustecimiento. Una brecha P0 de funcionalidad no significa que hoy exista un ataque explotable.

| ID | Prioridad | Hallazgo y evidencia de código | Consecuencia y propuesta |
|---|---|---|---|
| UX-01 | P1 | `views/profesionales/index.php`: la rama sin `colaborador_id` solo imprime el aviso | Agregar «Vincular a Recursos Humanos» con ID preseleccionado. Reutilizar la entrada que RH ya ofrece a `/admin/equipo?rol=profesional&profesional_id=ID` |
| RH-01 | P1 | `ColaboradorRepository::invitar` crea colaborador por correo; `vincularFicha` enlaza dentro de la invitación | Distinguir «persona nueva» y «colaborador ya registrado». Vincular al existente sin duplicarlo ni eludir su aceptación o estado; resolver conflicto si ya tiene otra ficha |
| UX-02 | P1 | `views/agenda/index.php` usa etiquetas contenedoras sin `.campo`; `_responsive.scss` estiliza `.agenda-filtros input`, pero no su `select`; `_agenda-atencion.scss` solo le da ancho | La captura confirma diferente altura, borde y alineación. Adoptar estructura común `.campo` con etiqueta y control de igual altura; alinear acciones en la misma rejilla |
| UX-03 | P1 | Parámetros de fecha diferentes en Agenda, Caja, Finanzas, CRM y Reportes; sin resolvedor común de períodos | Añadir componente común de filtros y períodos; conservar adaptadores compatibles con las URLs actuales |
| FIN-01 | P1, previa a PDV | `CrmRepository::eventosPagos` genera devolución por `pagos.estado='reembolsado'` y por el monto completo; no consulta `cobros_devoluciones`. `CobroCitaService::devolver` sí registra parciales | Gasto neto y segmentación pueden diferir de Caja. Unificar eventos monetarios y probar conciliación por fecha, incluyendo devoluciones parciales en otro mes |
| POS-01 | P0 | `CobroCitaService` calcula total desde `citasservicios`; `ComprobanteService` y migración 025 requieren `cita_id` | Introducir venta y líneas; una venta de productos no debe inventar una cita |
| INV-01 | P0 | Migración 013 e `InventarioService`: producto tiene costo, no precio de venta ni impuesto; movimiento solo entrada/salida con motivo | Completar catálogo y enlazar movimientos a documentos/líneas mediante claves; bloquear stock al confirmar entrega |
| FIS-01 | P0 para facturación real | `ComprobanteService::LEYENDA` y migración 025 declaran recibo interno | Crear dominio fiscal separado; el recibo de cobro no se convierte en factura cambiando el título |
| CAJ-01 | P0 para arqueo | `OperacionRepository::caja` calcula saldo acumulado de todos los medios; esquema no tiene terminal/sesión | Añadir caja, apertura, cierre, retiros, arqueo, diferencias y responsable; transferencias fuera de efectivo físico |
| COM-01 | P0 para nuevas reglas | `ComisionService::devengar` usa profesional y porcentaje vigente al cobrar; puede devengar anticipos antes de la atención | Definir si el anticipo es provisional, y cuándo queda ganada la comisión. Registrar ejecutor y vendedor por línea; no inferirlos del usuario que cobra |
| ACL-01 | P1 | `AuthorizationService`: recepción no tiene `finanzas.gestionar`; ese permiso agrupa cobro, devolución, verificación y comisión | Crear permisos de PDV específicos. Dar acceso a caja a recepción no debe abrir liquidaciones o configuración financiera |
| PRO-01 | P0 para promociones | No hay entidades de promoción/cupón/descuento en las migraciones; fidelidad es un flujo separado | Motor de cálculo en servidor, vigencias, elegibilidad, prioridades, acumulación y consumo concurrente del cupón |
| REP-01 | P1 | `CitaRepository::listarAgenda` termina en `LIMIT 500`; historiales de inventario/comisiones tienen límites 30/50 | Incorporar conteo, paginación y advertencia de parcialidad; un mes concurrido no debe aparentar estar completo |
| OPS-01 | P1 para producción | `docker-compose.yml` permite usuario y clave root predeterminados, montaje de todo el proyecto; Dockerfile prepara runtime sin empaquetar código/dependencias | Mantener perfil local y crear despliegue con imagen completa, usuario SQL restringido, secretos obligatorios, TLS y respaldo externo |
| QA-01 | P2 | CI compila/linta y corre pruebas, pero no contiene auditoría recurrente de dependencias; acciones por etiqueta | Añadir revisión de dependencias, actualización controlada y fijación de acciones/imágenes; no afirmar vulnerabilidades sin un escaneo actualizado |
| DOC-01 | P2 | `docs/FINANZAS_INVENTARIO_COMISIONES.md` aún habla de cobrar por ID y solo devoluciones completas | Actualizar documentos antiguos: el controlador actual deriva a Caja y ya hay devoluciones parciales. Señalar documentación canónica por módulo |

También se detectó una URL heredada con espacios (`?fecha = ...`) en `src/js/buscador.js` y en el JS construido. `AdminController` admite `fecha_` como compatibilidad; no se clasifica como filtro completamente roto. Debe normalizarse a GET válido con botón y funcionamiento sin JavaScript al unificar filtros.

## 5. Vinculación de profesionales: recorrido propuesto

La tarjeta antigua tendrá una acción visible «Vincular a Recursos Humanos». Para el propietario abrirá la persona/ficha ya seleccionada; un gerente sin permiso de altas verá una explicación con la acción asignada al propietario, sin enlace que termine innecesariamente en 403.

La pantalla resolverá dos casos:

1. **Colaborador nuevo:** completar identidad y usar la invitación actual. Preservar ID profesional, servicios, horarios, descansos, bloqueos, citas y comisiones. Informar que la ficha quedará sin nuevas reservas hasta aceptar la invitación, como funciona hoy.
2. **Colaborador existente:** seleccionar por identidad verificable (ID/código/correo), mostrar ficha de origen y destino y confirmar vínculo. Si el colaborador ya tiene profesional, no reemplazarlo ni fusionar historiales automáticamente. Si su rol/estado no permite atención, resolverlo mediante RH.

El servidor revalidará permisos, rol, identidad, vínculo vacío y cuenta preexistente en la misma transacción. Concurrencia y reenvíos deben producir un único vínculo. No emparejar por nombre. El modelo actual liga capacidad profesional a rol de acceso: antes de soportar gerente/propietario que también atiende, separar permisos de la capacidad de prestar servicios; no crear una segunda persona para representarla.

## 6. Patrón de filtros para toda la aplicación

Unificar presentación y comportamiento compartido; cada vista conserva sus filtros de negocio. No añadir fechas a un catálogo sin explicar qué fecha filtra.

| Vista/contexto | Filtros y fechas pertinentes | Intervención |
|---|---|---|
| Citas del día `/admin` | Día programado | Hoy/ayer/mañana y navegación por día; GET correcto; períodos amplios abren Agenda |
| Agenda `/agenda` | Fecha programada, estado; profesional para quien gestiona | Hoy, mañana, esta semana, próxima semana, este mes, personalizado; anterior/siguiente período; paginación |
| Pendientes de atención | Fecha programada pasada, estado de revisión y profesional | Filtros de revisión sin ocultar el total global de pendientes |
| Mis citas | Fecha programada y estado | Próximas/historial, período y paginación; alcance del titular |
| Caja `/caja` | Hoy usa fecha de la cita; estado de pago, medio, cliente y profesional | Conservar etiqueta «fecha de la cita». Para ingresos reales, selector explícito por fecha de cobro; no cambiar el criterio silenciosamente |
| Movimientos de Finanzas | Fecha real de ingreso/egreso, tipo, medio y responsable | Hoy/ayer/semana/mes/mes anterior; filtros por caja/turno cuando existan |
| Reportes | Programación, atención efectiva o cobro/devolución | Mantener criterio visible; mismos filtros para pantalla, exportación y totales |
| Clientes: base e indicadores | Búsqueda, actividad, membresía, vínculos y períodos ya definidos | Barra breve, filtros avanzados plegables, chips y limpiar; mantener separación entre directorio e indicadores |
| Auditoría CRM | Fecha UTC mostrada en zona del negocio, evento, actor, resultado | Atajos y rango coherente; no perder filtros al exportar/paginar |
| RH | Persona, rol, estado; ingreso si se añade período | Mantener búsqueda y estados; fechas solo para ingreso/baja seleccionados explícitamente |
| Profesionales y Servicios | Búsqueda; activo/inactivo, servicio o vínculo pendiente cuando corresponda | Mismo aspecto; sin selector de semana/mes artificial para el catálogo |
| Inventario | SKU/nombre/estado/stock mínimo; movimientos por fecha/tipo/producto | Reemplazar los últimos 30 por consulta paginada y sustituir ID manual por búsqueda de producto |
| Comisiones/liquidaciones | Fecha devengo o pago, beneficiario y estado | Vistas separadas de Finanzas y fechas claramente rotuladas |
| Crecimiento | Lista de espera, formularios, canjes y sus estados | Filtros específicos por sección y fecha del evento correspondiente; sin mezclar preguntas de formulario con filtros |
| PDV, ventas y facturación nuevos | Fecha comercial, estado de venta/pago/fiscal, caja, vendedor, cliente | Reutilizar el componente desde el principio |

Contrato propuesto: componente de campos y acciones, `PeriodoFiltro` con reloj inyectable y zona `America/Guayaquil`, normalizadores propios por módulo y parámetros GET canónicos. Las claves heredadas (`inicio/fin`, `fecha_inicio/fecha_fin`) se traducen durante la transición.

Reglas de experiencia y consistencia:

- Atajos de semana calendario de lunes a domingo; distinguir «esta semana» de «últimos 7 días». Mes calendario no equivale a 30 días. En analítica histórica, «este mes» llega hasta hoy; Agenda puede incluir el resto del mes.
- Resolver el período en servidor para funcionar sin JS. La URL y las exportaciones contienen límites concretos reproducibles; si se edita manualmente una fecha, el período pasa a personalizado.
- Encabezado breve con rango aplicado; controles de 48 px de alto, foco visible, etiquetas asociadas, grid adaptable y acciones alineadas. Avanzados plegables y chips removibles; no ampliar toda la pantalla con filtros secundarios.
- Cambiar filtros vuelve a página 1. Ordenación, navegación a detalle/retorno y descargas conservan filtros y alcance de autorización. No exponer filtros o importes que el rol no puede consultar.
- Pantalla, indicador y exportación comparten normalización y consulta. Un resultado limitado lo comunica; paginación no cambia el universo de totales.
- Para timestamps, consultas por intervalo `[inicio, día siguiente a fin)`, sin envolver columnas indexadas en `DATE()`. Para fecha de cita, límites de fecha coherentes.
- Conservar límites por dominio. Agenda hoy anuncia hasta 31 días, pero valida diferencia entre extremos y limita a 500 filas; precisar conteo inclusivo y paginar. Caja/Reportes permiten 366 días de diferencia y CRM 1096: no elevar todos a un máximo global sin medir consultas.

## 7. Modelo de negocio del PDV

La **cita** organiza la prestación. La **venta** registra qué se entrega/vende, a quién, precio, descuentos e impuestos. El **pago** registra dinero recibido y aplicado. La **factura** documenta fiscalmente una operación. La **devolución** relaciona líneas devueltas, dinero, inventario y corrección fiscal. Sus estados son independientes.

Recorridos previstos:

1. Desde Agenda: «Finalizar y cobrar» abre una venta con las líneas de servicios de la cita y sus precios históricos, anticipos y profesional. No cobra de nuevo un anticipo importado.
2. Desde PDV: venta de producto sin cita ni cuenta de acceso obligatoria; cliente identificado o comprador permitido por la configuración fiscal aplicable.
3. Carrito mixto: servicio realizado y producto, con ejecutor de servicio y vendedor de producto por línea. Se puede separar la atención del cobro sin que marcar pagado finalice la cita.
4. Pago mixto: varios medios aplicados al mismo saldo. Efectivo contabiliza importe aplicado y cambio; transferencia pendiente no se presenta como dinero confirmado.
5. Devolución: seleccionar líneas y cantidades, motivo, autorización, dinero por devolver y destino del stock. Un producto consumido/dañado no vuelve automáticamente a disponible.

Diseño de pantalla: catálogo/buscador a la izquierda, ticket a la derecha; cliente y origen visibles; precio/descuento/impuesto por línea; resumen final y cobro. Teclado, lector de código de barras y tablet. Historial, informes, reglas de promoción y configuración viven en vistas propias.

## 8. Entidades propuestas y continuidad

Nombres orientativos; no son migraciones ya implementadas.

| Entidades | Función y relaciones |
|---|---|
| `ventas`, `venta_lineas` | Cliente/membresía opcional y snapshot del comprador; origen/cita opcional; moneda; cantidades escaladas; precio, descuento, base, impuesto y total históricos; ejecutor y vendedor por línea |
| Catálogo de productos ampliado | Reutilizar `inventario_productos` y sus IDs; precio, impuesto, código de barras, activo y tipo vendible/consumible/ambos; no duplicar inventario |
| Impuestos y tarifas con vigencia | Código/tasa y fecha de aplicación; cada línea congela la tarifa efectiva. No hardcodear una tasa general para todos los artículos |
| `pago_aplicaciones` | Relación entre pago y venta/líneas; anticipos existentes, pagos mixtos y reembolsos. Reutilizar identidad del pago; no duplicar el dinero |
| `cajas`, `caja_sesiones`, arqueos | Terminal, responsable, fondo inicial, apertura/cierre; enlazar movimientos existentes/nuevos a su sesión cuando corresponda |
| Orígenes de inventario y reservas | Venta/línea, devolución, compra, consumo o ajuste; unicidad por evento. Saldo disponible distingue stock físico y reservado |
| `devoluciones_venta`, líneas y aplicaciones | Cantidad/importe original, dinero devuelto, reintegro de stock y relación con corrección fiscal; migrar compatibilidad de `cobros_devoluciones` |
| Promociones, reglas, cupones, aplicaciones/usos | Vigencia, canal, alcance, límites, concurrencia y reglas congeladas por venta |
| Reglas/comisiones por línea | Beneficiario vinculado a colaborador y, para atención, profesional; tarifa/base/devengo/reversa; conservar las liquidaciones existentes |
| Documentos fiscales y eventos | Venta de origen, emisor, comprador, numeración, XML firmado, clave/autorización, RIDE y referencias a notas de crédito; distinto del recibo interno |
| Outbox comercial/fiscal | Evento persistido en la transacción y procesado después; intentos, respuesta externa, deduplicación, error y próxima ejecución |

Para el alcance inicial, una cita tendrá como máximo una venta comercial principal, con sus líneas de servicio únicas. Un añadido posterior se incorpora mientras es borrador o se vende con referencia explícita; una venta de productos independiente sigue permitida. Ventas consolidadas de varias citas o divisiones de factura requieren una ampliación expresa de esa cardinalidad.

No renumerar ni sustituir los recibos, pagos o comisiones anteriores. Preparar conciliación y tablas de correspondencia. La retrocarga crea documentos históricos marcados, sin generar ingresos, salidas de inventario o comisiones otra vez. Nunca genera facturas fiscales retroactivas automáticamente.

## 9. Cálculo, descuentos, promociones y cupones

El servidor obtiene catálogo y tarifas, conserva el precio histórico de los servicios reservados y vuelve a validar stock, elegibilidad y total al confirmar. Si cambió una condición del carrito, devuelve el nuevo detalle para aceptación; no cobra silenciosamente otra cifra.

Usar enteros en centavos, cantidades escaladas y operaciones decimales controladas. Fijar precisión de precio/costo unitario, regla de redondeo, distribución de descuento global y residuo por línea. Los formatos en pantalla pueden usar decimales, pero nunca se usan floats como fuente del asiento.

Reglas comerciales propuestas para primera versión:

- Descuento manual por línea o ticket con monto/% y motivo; límite por permiso y autorización superior. No descuentos negativos ni total menor que cero.
- Promociones con inicio/fin en zona del negocio, servicios/productos/categorías elegibles, mínimo, canal y tope. Prioridad determinista y no acumulación por defecto, salvo regla explícita.
- Un cupón puede limitar usos globales y por cliente. Reservar al iniciar el cierre de una venta; confirmar consumo según el hito comercial definido y liberar al cancelar/expirar. Dos cajas no pueden consumir el último uso simultáneamente.
- El reparto de descuentos por líneas conserva exactamente el total; las devoluciones usan el precio neto realmente pagado y la proporción del descuento original.
- Fidelidad se vincula a la venta/canje y a una entrega o descuento concreto, sin convertir el texto del beneficio existente en dinero. Definir si puntos siguen por visita o pasan a gasto; preservar el historial y evitar doble premio.
- Promociones complejas (segunda unidad, paquetes, bonos/saldos a favor) se añaden después con reglas explícitas. Un cupón no es un medio de pago; una tarjeta de regalo o saldo a favor necesitaría su propio libro de obligaciones.

## 10. Stock, servicios y compras

En una venta inmediata, descuento de stock y confirmación comercial son una sola transacción. Para transferencia pendiente, reservar durante un plazo visible; liberar al rechazar/expirar y descontar al entregar. No mantener un bloqueo SQL mientras un usuario paga. La entrega, no cada abono parcial, produce una única salida física.

Ordenar bloqueos de productos por ID y definir una política global de bloqueos que incluya cita, venta, pago, profesional/comisión, inventario, caja y numeración. Revisar también los caminos existentes antes de fijar el orden definitivo: añadir una llamada dentro de otra transacción no basta y puede introducir interbloqueos.

Los insumos de un servicio se registran por receta/configuración y consumo real al ejecutar la atención; se pueden ajustar con motivo. No esperar al cobro para reconocer un producto ya consumido. Un servicio cancelado antes de consumirse no descuenta insumos.

Compras/proveedores y pago al proveedor son eventos distintos: recibir mercadería no implica pagarla. Incorporar recepción, costo, referencia y eventual cuenta por pagar. Definir método de costo (por ejemplo promedio ponderado) antes de declarar margen/rentabilidad; el costo de referencia actual no equivale a valoración. Lotes/vencimientos, almacenes y transferencias se priorizan según los productos y sedes reales.

## 11. Comisiones y caja

Política propuesta para servicios: comisión ganada al cumplirse atención y cobro verificado; anticipo previo se registra como base provisional. Para productos: comisión por venta entregada y cobrada. Validar estas políticas con el negocio antes de cambiar el criterio actual, que devenga al cobrar. Congelar regla, porcentaje, base neta tras descuento y beneficiario; parametrizar si impuestos o propinas se excluyen y definir pagos parciales prorrateados.

Una devolución genera reversa de la comisión atribuida a esas líneas. Si ya se liquidó, crear ajuste posterior; no reescribir una liquidación. Separar el vendedor del cajero y del ejecutor. La continuidad laboral debe conservar beneficiario e historial aunque deje el rol profesional.

Caja física: fondo inicial + cobros en efectivo − devoluciones en efectivo + otros ingresos en efectivo − egresos/retiros en efectivo = efectivo esperado. Comparar con contado y registrar diferencia/motivo/responsable. Transferencias y tarjeta se concilian por su medio, no se suman al efectivo de la gaveta. Comisiones bancarias no reducen arbitrariamente el monto que el cliente pagó: tienen movimiento propio. Fechas comercial, atención, cobro y liquidación bancaria deben permanecer diferenciadas.

## 12. Facturación y pagos externos

Se toma Ecuador como hipótesis de diseño, pendiente de confirmar el país y emisor del despliegue. El SRI describe comprobantes con firma electrónica y requisitos para autorización y publica especificaciones técnicas: https://www.sri.gob.ec/facturacion-electronica (consulta 17/10/2026). No se afirma que el recibo actual tenga validez fiscal, ni se fija una tasa, régimen o plazo legal sin conocer el emisor y revisar su normativa aplicable.

Preparar un adaptador de facturación para proveedor o integración directa, con ambientes de prueba/producción. El modelo prevé datos del emisor/comprador, impuestos vigentes, numeración fiscal, XML, firma, recepción/autorización, RIDE y correcciones mediante documentos relacionados según las reglas aplicables. Certificado y credenciales fuera del repositorio, almacenamiento protegido y alertas de vencimiento.

Estados internos sugeridos: pendiente de emisión, en proceso, autorizado y rechazado; guardar además estado/respuesta real del proveedor/SRI y reintentos. No marcar autorizado por imprimir un PDF. Un timeout se concilia por identidad externa antes de reintentar emisión o reembolso. Devolución monetaria, reintegro de producto y corrección fiscal se relacionan pero conservan estados propios.

La transacción SQL confirma venta, pagos verificados, movimientos y evento fiscal pendiente. El worker envía después, sin sostener bloqueos durante la llamada externa. Un fallo externo no borra dinero real recibido; deja un estado de incidencia visible y acciones de resolución conforme al régimen aplicable. La política de entregar/facturar en contingencia se configura solo después de confirmar requisitos y plazos del emisor.

La pasarela real usa `PaymentGatewayInterface`, pero implementarla requiere conciliación, firma de eventos, referencias únicas y recuperación tras timeout; no basta añadir una opción al selector. No almacenar PAN/CVV. Un datáfono externo registrado manualmente y una integración automática son modalidades distintas que deben quedar identificadas.

## 13. Seguridad, auditoría y operación

Conservar controles ya presentes: permisos consultados en servidor, CSRF, consultas preparadas en los repositorios revisados, idempotencia persistente, bloqueo de saldos, separación de verificación de transferencia y restricciones de historial. La subida de fotos valida MIME/tamaño/dimensiones y recodifica; no reutilizar ese almacenamiento para certificados fiscales sin una política propia.

Permisos propuestos: `pdv.ver`, `pdv.vender`, `pdv.cobrar`, `pdv.descuento.aplicar`, `pdv.descuento.autorizar`, `pdv.devolver`, `transferencias.verificar`, `caja.abrir`, `caja.cerrar`, `inventario.ajustar`, `comisiones.configurar`, `comisiones.liquidar`, `facturacion.emitir` y `facturacion.corregir`. Recepción puede operar ventas/cobros autorizados sin gestionar reglas, devoluciones especiales o nómina; el profesional consulta sus comisiones si se habilita ese alcance.

Registrar actor/cuenta y colaborador, fecha, origen, documento, antes/después, motivo, autorización y clave de idempotencia. Para nuevos libros de eventos, solo anexado; para documentos con estados, eventos de transición auditados. El hash detecta divergencia de contenido, pero no sustituye control de acceso ni firma fiscal. Complementar con usuario SQL de aplicación restringido y usuario de migración separado; hoy el compose admite root por defecto y los triggers no son una barrera frente a un administrador de base de datos.

Monitorear documentos pendientes/rechazados, cola fiscal/correo, transferencias pendientes, diferencias de caja, stock, inconsistencias de conciliación y restauración de respaldos. El CI prueba restauración, pero no demuestra por sí solo que el equipo tenga copias externas programadas. Usar despliegue con imagen inmutable, HTTPS, configuración validada al iniciar y secretos sin valores genéricos. Mantener la validación `APP_URL` del entorno para que correos apunten al portal correcto.

## 14. Plan por entregas y criterios de aceptación

| Fase | Resultado concreto | Condición para cerrar |
|---|---|---|
| 0. Consistencia actual | CTA de vínculo RH y resolución de persona existente; filtros compartidos, períodos y alineación; conciliación de parciales | Sin duplicación de personas/fichas; navegación conserva ID y contexto; devolución parcial concilia Caja/CRM/exportación; controles alineados en escritorio/móvil y accesibles sin JS |
| 1. Contrato comercial | Venta, líneas, catálogo/impuestos/precios; pagos aplicados; plan de migración histórica | Totales exactos y snapshots; documento sin cita; anticipos existentes no se duplican; reglas de estados y cardinalidades probadas |
| 2. PDV operativo | Carrito mixto, pagos, entrega/stock, turnos/arqueos y devoluciones | Dos cajas no sobrevenden; reintento no duplica venta/movimientos; efectivo contado concilia; flujo completo desde Agenda y mostrador |
| 3. Facturación y cobro externo | Adaptadores, cola, estado fiscal y conciliación | Emisión/corrección en ambiente de prueba del proveedor/SRI; timeout/reenvío no duplica; documentos y dinero coinciden; producción bloqueada si falta configuración requerida |
| 4. Reglas comerciales | Comisiones por línea, descuentos autorizados, promociones/cupones y fidelidad | Límites y usos concurrentes correctos; devolución revierte precio neto, comisión y beneficio según política; snapshots históricos no cambian |
| 5. Gestión y despliegue | Compras/costos, reportes integrados, operación y alertas | Cierres, ventas, cobros, stock, comisiones, facturas y devoluciones conciliados; migración/restauración de ensayo y monitoreo verificados |

El cálculo de impuestos y descuentos forma parte del contrato desde fase 1 aunque la administración avanzada de promociones llegue en fase 4. No habilitar ventas reales que requieran comprobante fiscal antes de completar fase 3. Incorporar a cada fase migraciones, pruebas y compatibilidad, no dejarlas como una revisión final.

No se estima calendario sin resolver el emisor fiscal, medios de pago, complejidad de comisiones y número de sedes/cajas. Esas decisiones cambian materialmente el alcance.

## 15. Matriz mínima de pruebas nuevas

1. Vincular legado al colaborador correcto; rechazar rol incompatible, identidad ajena, vínculo ocupado y carrera entre dos operadores. Conservar citas, servicios, horarios, IDs y comisiones.
2. Atajos en domingo/lunes, fin de mes/año y febrero bisiesto; parámetros malformados; rango máximo inclusivo; sin JS; paginación y descargas reproducibles.
3. Alineación geométrica de fechas/select/botón y capturas de referencia a 360/768/1280 px; teclado, foco, etiquetas, textos largos y estado vacío/error.
4. Cobro de 20 y devolución parcial de 5: Caja, CRM, ficha, reportes y archivos muestran 15 netos en el mismo período. En períodos separados, +20 en cobro y −5 en devolución. Comisiones y saldo deben seguir la política explícita.
5. Carrito servicio/producto, sin cita, con anticipo, pago mixto y transferencia rechazada; totales manipulados por navegador, cambio de precio y reintento tras pérdida de respuesta.
6. Última unidad vendida en dos cajas; orden inverso de productos, deadlock/reintento, reserva expirada y entrega única pese a varios abonos.
7. Devolución parcial repetida, acumulada superior a cantidad/importe original, producto no revendible y comisión previamente liquidada.
8. Cupón de último uso concurrente, límites por cliente, acumulación, vigencia y residuo de prorrateo. Devolución no recalcula con promoción vigente hoy.
9. Dos cierres de caja simultáneos, sesión cerrada, diferencia de efectivo, retiros y conciliación por medio.
10. Emisión fiscal rechazada, aceptada con timeout, reenvío duplicado, certificado vencido, nota de crédito y conciliación del identificador externo. Ninguna llamada externa dentro del bloqueo SQL.
11. Permisos por rol y alcance; revocación de sesión durante operación; CSRF, exportación privada y evidencias sin secretos.
12. Ensayo con copia aislada de datos heredados, cantidades iniciales, vínculos y saldos; snapshot antes/después y restauración. No truncar tablas productivas ni regenerar movimientos históricos.

## 16. Decisiones pendientes de negocio

Antes de implementación fiscal/comercial se necesita confirmar: país y emisor/RUC, régimen y proveedor de facturación; sedes/cajas/almacenes; medios reales (efectivo, transferencia, datáfono o pasarela); precios con/sin impuesto; quién puede vender/cobrar/autorizar; si comisión se gana al cobrar o al atender, tarifas por servicio/producto y varios ejecutores; productos vendibles/insumos, lotes y vencimiento; política de descuentos, cupones, fidelidad, anulaciones, devoluciones, anticipos y saldos a favor.

Las políticas sugeridas en este documento son propuestas verificables; no se presentan como decisiones ya autorizadas. Estas definiciones no impiden iniciar la fase 0 de consistencia y filtros.

## 17. Archivos principales examinados

Rutas y acceso: `public/index.php`, `includes/rutas/caja.php`, `includes/funciones.php`, `includes/database.php`, `Router.php`, `services/AuthorizationService.php`.

Personas: `controllers/EquipoController.php`, `controllers/ProfesionalController.php`, `repositories/ColaboradorRepository.php`, `repositories/EquipoRepository.php`, `services/RecursosHumanosService.php`, `views/profesionales/index.php`, `views/equipo/recursos-humanos.php`, migración 018.

Operación y filtros: `controllers/AgendaController.php`, `services/AgendaAtencionService.php`, `repositories/CitaRepository.php`, `views/agenda/index.php`, `views/admin/index.php`, `views/admin/reportes.php`, `views/caja/index.php`, parciales CRM, `src/js/buscador.js`, estilos de formulario/agenda/responsive.

Comercial: `services/CobroCitaService.php`, `services/CobroCalculo.php`, `repositories/CajaRepository.php`, `services/ComprobanteService.php`, `services/FinanzasService.php`, `services/InventarioService.php`, `services/ComisionService.php`, `repositories/OperacionRepository.php`, `services/PagoService.php`, `controllers/PagoController.php`, `classes/MockPaymentGateway.php`, `repositories/CrmRepository.php`, `services/CrmService.php`, `repositories/ReporteRepository.php`, `services/ReporteService.php`, `repositories/CrecimientoRepository.php`, `services/CrecimientoService.php`, migraciones 006, 013–015 y 024–027.

Operación técnica: `composer.json`, `package.json`, `Dockerfile`, `docker-compose.yml`, `.github/workflows/ci.yml`, scripts de respaldo/restauración, pruebas de caja/CRM/concurrencia y documentación de continuidad/finanzas/caja.

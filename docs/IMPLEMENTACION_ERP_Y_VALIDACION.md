# Plataforma operativa ERP de AppSalon

Esta entrega desarrolla el núcleo operativo del plan maestro del 17 de octubre de 2026. El plan sigue siendo la referencia de alcance; no todos sus componentes equivalen a funciones terminadas. Decisión vigente del usuario (18 de octubre, America/Guayaquil): facturación, contabilidad y nómina serán módulos propios de AppSalon. La facturación ecuatoriana se conectará directamente al SRI; no depende de contratar otro software de facturación. La emisión real requiere configurar el emisor y su certificado de firma. Los servicios bancarios/adquirentes para cobros con tarjeta siguen siendo conexiones externas distintas.

## Procesos incorporados

| Área | Recorrido disponible | Registro compartido |
|---|---|---|
| RH y profesionales | Conciliación explícita de fichas antiguas con colaboradores; conserva agenda e historial | Colaboradores, usuarios y profesionales existentes |
| Consulta | Atajos de período en agenda, caja, finanzas, reportes y CRM; agenda paginada | Fechas concretas resueltas por servidor |
| Venta | Mostrador o desde cita; productos y servicios pactados; descuentos autorizados; confirmación con versión | Una venta por cita; no duplica anticipos existentes |
| Cotización | Instantánea, condiciones y vencimiento; aceptación que confirma la misma venta | Cambiar conceptos exige una nueva cotización |
| Cobro | Efectivo, importe recibido y cambio; transferencia pendiente de verificación; abonos | Tabla `pagos` y caja histórica, sin un segundo libro de dinero |
| Caja | Apertura personal, movimientos, arqueo, cierre y diferencia motivada | Transferencias excluidas del efectivo físico |
| Inventario | Reservar al confirmar; descontar al entregar; devolución física explícita | Productos y movimientos existentes; stock reservado protegido también en ajustes manuales |
| Compras | Orden, autorización, recepción parcial, costo promedio, factura, obligación y abonos | Recibir, facturar y pagar son actos independientes |
| Proveedor | Devolución de mercadería, nota de crédito y reintegro recibido | Un crédito no inventa una entrada de dinero |
| RR. HH. | Contratos, finalización, jornadas por grupos de días, asistencia y anulación motivada | Conservación de evidencia en eventos |
| Ausencias | Solicitud propia, aprobación/rechazo, anulación y bloqueo de agenda | Exige resolver citas afectadas antes de aprobar |
| Comisiones | Regla por colaborador y vigencia; devengo por cobro y entrega/atención; ajustes por devoluciones | Compensa devengos históricos importados; liquidar crea obligación o novedad de nómina, no pago automático |
| Aprobaciones | Descuento, gasto o ajuste de inventario; decisión motivada | Gasto aprobado crea cuenta por pagar, no egreso |
| Promociones | Cupones porcentuales, vigencia, mínimo, cupo y límite por comprador; pausa/reactivación | Reserva de cupo, validación al confirmar y reversión al anular |
| CRM | Contactos sin login, vínculo explícito con cliente registrado, oportunidades, próximas acciones y reclamos | No fusiona personas automáticamente por nombre o correo |
| Recursos | Cabinas/equipos por tipo, requisitos por servicio, mantenimiento y activos | Reserva física dentro de la transacción de cita; intervalos sin solapamiento |
| Insumos | Receta por servicio y consumo una vez por atención | Salida física sin generar cobro; respeta reservas comerciales |
| Documentos | Subida, versiones, vencimiento, descarga y acceso de titular autorizado | Cifrado con clave derivada de `APP_KEY`; documentos privados de RH protegidos en servidor |
| BI | Ventas, créditos, caja por día, saldos actuales y stock disponible | Agregados por fuente; exportación CSV/Excel auditada |
| Integraciones | Contrato de adaptador, outbox, referencia estable, lease, consulta de conciliación y reintento | Sin proveedor, permanece `sin_configurar`; nunca simula autorización fiscal |

Las tarifas de servicios configuradas en el ERP actualizan el precio final mostrado al reservar. Las reservas nuevas conservan el desglose de su tarifa; las antiguas conservan su importe y no reciben un impuesto inventado retrospectivamente. El editor anterior permite nombre/duración y remite los cambios de tarifas gestionadas al PDV.

## Garantías y límites

- Dinero en centavos y cantidades en milésimas; validación en servidor y transacciones.
- Idempotencia por operación, actor y contenido; autorización comprobada de nuevo dentro de la escritura.
- Eventos y recibos internos de solo anexado; recibos con instantánea y SHA-256.
- No se convierte la base histórica en ventas nuevas ni se mueve dinero por migrar.
- Los recibos internos y cotizaciones no son facturas electrónicas autorizadas.
- Una caja corresponde al operador; aún no modela terminales físicos, múltiples sedes o almacenes.
- El recurso se reserva para todo el intervalo de la visita; no hay asignación por etapas ni varios operadores simultáneos.
- Las pantallas con consultas limitadas indican su alcance; ventas y agenda tienen paginación. No todos los directorios tienen todavía paginación/exportación completa.
- La regla de comisión de esta entrega es por colaborador y vigencia; no ofrece una matriz por categoría/producto ni calcula prestaciones legales.
- El CRM anterior y sus puntos/beneficios siguen disponibles. La continuación 034 incorpora saldos prepagados y tarjetas regalo nominativas con su libro y reversión. Siguen pendientes paquetes de sesiones, suscripciones, propinas y canjes de fidelidad convertidos en descuentos.
- Los cupones no se acumulan con descuentos manuales. No hay todavía segmentación de promoción por canal o categoría ni promoción automática por prioridad.
- No se incorporan WMS avanzado, MRP, reclutamiento/evaluación de talento ni contabilidad de doble partida propia. Son alcances condicionales del plan maestro.

La integración real debe definir emisor/país, impuestos y documentos admitidos, proveedor, cuentas contables, reglas de nómina y conciliación. El contrato `IntegracionErpInterface` exige consulta por referencia y confirmación verificable. La cola incluye un sobre versionado con evento, entidad, fecha y datos. No se deben marcar eventos como enviados manualmente para aparentar integración.

## Verificación de continuidad del 18 de octubre

El CI del commit `f2086ce` aprobó el recorrido real de navegador, la navegación por rol y la suite principal (1172 pruebas, 18394 aserciones). La QA independiente encontró defectos de formularios y contratos anteriores a la ampliación; ese resultado no se considera una validación completa del ERP.

Esta revisión corrige el HTML de los formularios de CRM, la reproducción de entradas no escalares en equipo y los retornos de agenda. Refuerza la separación entre portales incluso para un propietario con membresía de cliente: una sesión del portal cliente no puede ejecutar acciones del ERP ni abrir expedientes laborales. Las rutas empresariales sin sesión llevan al acceso del equipo.

La revisión de las capturas de navegador detectó una regla heredada que daba a las etiquetas de agenda una base flex de 16 rem en el eje vertical. Se retira esa reserva de altura y se aplica el ancho móvil al contenedor del campo. La regresión responsive comprueba también la separación entre etiqueta y control, en todos sus anchos y tamaños de texto; comprobar solo desbordamientos horizontales no detectaba ese hueco.

Los contratos de QA incluyen ahora todos los archivos de rutas registrados, los permisos explícitos por rol y el esquema de 92 tablas. Las pruebas de acciones de agenda recorren los enlaces reales de paginación, conservando el formulario de cada página. La auditoría de exportaciones de caja exige exactamente un registro por intento autenticado; no se excluyen otros movimientos. Recepción solo ve el nombre del producto necesario para solicitar un ajuste, sin extender esa excepción al SKU, costos o movimientos.

Validación local dirigida: los 13 casos de CRM finalizaron sin fallos; las dos pruebas de formas hostiles de RH aprobaron 523 aserciones; las siete pruebas de períodos, consistencia y portales aprobaron 26 aserciones. Se aprobaron 26 casos de agenda con 375 aserciones, incluido el regreso desde una cita a la segunda página con su filtro de estado; y los seis casos de login real con 18 aserciones. El ciclo de respaldo/restauración con manifiesto SHA-256 recuperó las 92 tablas de la base de pruebas. La matriz completa de autorización se amplió a 211 casos. Los resultados definitivos de la regresión completa deben consultarse en el CI del PR #4.


## Actualización Docker de un entorno con datos

Realizar después de revisar la rama y sus comprobaciones. Estos comandos no actualizan el equipo Windows desde esta sesión. Ejecutar en el repositorio local, conservando `includes/.env`, `APP_KEY` y los volúmenes existentes.

```bash
git fetch origin
git switch main
git pull --ff-only

docker compose --env-file includes/.env -p appsalon-validacion stop web notificaciones
docker compose --env-file includes/.env -p appsalon-validacion up -d db
docker compose --env-file includes/.env -p appsalon-validacion build web instalador notificaciones

docker compose --env-file includes/.env -p appsalon-validacion run --rm --no-deps -T instalador php scripts/backup_db.php --gzip
docker compose --env-file includes/.env -p appsalon-validacion run --rm --no-deps -T instalador php scripts/instalar.php --migrar
docker compose --env-file includes/.env -p appsalon-validacion run --rm --no-deps -T instalador php scripts/instalar.php --comprobar

docker compose --env-file includes/.env -p appsalon-validacion up -d web notificaciones
docker compose --env-file includes/.env -p appsalon-validacion ps -a
docker compose --env-file includes/.env -p appsalon-validacion logs --no-color --tail 80 notificaciones
```

Comprobar cada salida antes de ejecutar el comando siguiente; detenerse si falla respaldo, migración o comprobación. El bind del proyecto conserva el respaldo en `storage/backups`; copiarlo a un destino seguro y verificar su manifiesto. Conservar `APP_KEY` por separado: cambiarla impide descifrar documentos y otras cargas cifradas. Nunca publicar respaldos o claves en Git.

No ejecutar `down -v`, no eliminar `appsalon-validacion_appsalon_db_data` y no recrear cuentas para actualizar. La sesión `instalador` finalizada con código cero es normal. El nombre del proyecto y la red deben coincidir con la configuración que ya utiliza el entorno.

## Prueba de aceptación del piloto

1. Vincular una ficha profesional antigua con el colaborador correcto y comprobar su agenda/historial.
2. Configurar empresa, artículos, impuestos, recursos y reglas de comisión con datos de ensayo.
3. Abrir caja; registrar una cita y abrir su venta; añadir producto; confirmar, cobrar y entregar. Comprobar que el anticipo anterior no se repite.
4. Registrar transferencia; verificar con otro usuario autorizado; comprobar que no aumenta el efectivo físico.
5. Devolver parcialmente un producto; comprobar crédito, reintegro, stock y ajuste de comisión. Revisar el recibo emitido antes del cambio.
6. Crear compra, recibir parcialmente, facturar y abonar. Registrar una nota de crédito sin inventar un reintegro.
7. Solicitar ausencia propia; comprobar que otra persona no puede leer el expediente privado y que la aprobación protege las citas existentes.
8. Reservar dos profesionales que necesitan la misma cabina; el segundo intervalo incompatible debe rechazarse sin guardar media reserva.
9. Registrar consumo de receta dos veces; la salida física debe existir una sola vez.
10. Descargar documento privado con un usuario ajeno; denegación. Descargar la versión autorizada con su titular; contenido original.
11. Cotizar, cambiar un concepto e intentar aceptar la versión antigua; exigir una cotización nueva.
12. Conciliar indicadores y exportaciones con sus fuentes y fechas. Verificar que las integraciones aún no configuradas siguen pendientes.

Los resultados automatizados y el SHA validado se registrarán en el PR. Un CI anterior de `main` no acredita esta ampliación. La aprobación de un piloto real y la selección de proveedores siguen pendientes.

## Continuidad: arqueo común de Caja y PDV

`CajaArqueoService` enlaza los nuevos cobros por cita, devoluciones, movimientos libres y pagos de comisiones con la sesión abierta del operador que registra el dinero. El vínculo usa el mismo movimiento del libro general; no crea un segundo ingreso o egreso. Las transferencias solo se enlazan al confirmarse y no alteran el efectivo esperado. Una devolución o comisión se descuenta de la caja de quien entrega el dinero, aunque el cobro original lo registrara otra persona o sesión.

Cada operación retiene el bloqueo de la sesión hasta confirmar su movimiento y vínculo. El cierre usa ese mismo bloqueo; los retiros en efectivo comprueban el saldo mediante lectura actual, incluso bajo REPEATABLE READ. El repositorio propaga también los fallos al recibir resultados de SELECT: un interbloqueo no puede interpretarse como sesión inexistente. El reintento revierte y repite toda la transacción.

Compatibilidad explícita: las pantallas anteriores permiten movimientos sin sesión, que quedan únicamente en el libro general. Abrir «Mi caja» antes de operar permite incluirlos en el arqueo. El PDV sigue exigiendo apertura. No se importan movimientos anteriores ni se reasignan al abrir otra sesión; el fondo inicial ya representa el efectivo contado. Repetir una operación conserva su vínculo original. Los avisos de Caja y Finanzas explican esta regla y enlazan a «Mi caja».

No hay migración nueva ni alteración de datos históricos. El enlace utiliza las tablas introducidas en 028. No se cambia la autoridad de las reglas de comisión antiguas frente a las del ERP; esta revisión conecta su pago con el arqueo.

Regresión específica: diez pruebas de integración con 67 aserciones, incluidas dos conexiones concurrentes para retiros antiguos/PDV y cierre. Cubren cambio, transferencias, devolución repetida, insuficiencia de efectivo, pago de comisión, aislamiento entre operadores, reapertura y conservación del histórico. La suite principal y la QA independiente se registran por SHA en el PR; el resultado de un commit anterior no acredita esta continuación.

## Saldos prepagados y tarjetas regalo nominativas (034)

Este bloque añade una cuenta de saldo por contacto beneficiario, sin exigir credenciales de acceso. «Saldo» y «Tarjeta regalo nominativa» comparten el mismo libro; la tarjeta no es anónima ni transferible mediante un código. Esta entrega no aplica caducidad automática, no renueva contratos y no vende paquetes de sesiones. La carga de dinero no crea una venta de servicios, no marca atención realizada y no devenga comisión.

El flujo es: crear cuenta del beneficiario → abrir caja → registrar carga en efectivo o transferencia pendiente → verificar la transferencia → identificar al comprador en una venta confirmada → aplicar saldo → entregar producto o prestar el servicio. Si el contacto está vinculado explícitamente a una membresía de cliente, el prepago también puede liquidar su venta originada en una cita. Una venta de mostrador sin comprador identificado no puede consumir el saldo de otra persona.

Fuentes de verdad:

| Hecho | Registro | Efecto |
|---|---|---|
| Cuenta del beneficiario | `erp_prepagos` | Identidad y tipo; crearla no inventa saldo |
| Recepción declarada | `erp_prepago_cargas` | Efectivo confirmado o transferencia por verificar |
| Dinero confirmado o reintegrado | `caja_movimientos` y vínculo con sesión | Un ingreso por carga; un egreso por reintegro real |
| Derechos monetarios disponibles | `erp_prepago_movimientos` | Carga/restitución positivas; aplicación/reembolso negativas |
| Liquidación con saldo | `pagos` con método `prepago` y `erp_pago_aplicaciones` | Reduce lo pendiente de la venta; no vuelve a entrar dinero a caja |
| Referencia bancaria | `erp_transferencia_referencias` | Exclusión mutua entre Caja anterior, PDV y cargas de prepago |

El saldo se calcula del libro de solo anexado, con bloqueos de cuenta y lecturas actuales. La aplicación bloquea primero la venta; dos ventas compiten por el mismo saldo y dos medios compiten por el mismo importe pendiente. El resumen de venta consulta los últimos cobros confirmados aunque la transacción haya esperado el bloqueo bajo REPEATABLE READ. Idempotencia evita duplicar carga, aplicación, restitución o reembolso.

Una devolución comercial restituye a la cuenta los importes originalmente liquidados con prepago. No los entrega en efectivo. El reintegro de saldo no utilizado es otra operación autorizada: está limitado por el saldo actual y lo no reembolsado de una carga confirmada, se registra por el medio original y comprueba el fondo disponible si es efectivo. Las transferencias bancarias se ejecutan fuera de la aplicación; el operador confirma el movimiento real. Restituir saldo y reintegrar dinero son hechos distintos.

Las referencias de transferencias pendientes/confirmadas anteriores se incorporan al registro común sin inventar movimientos. Una referencia no puede financiar una venta y un prepago en paralelo. Rechazar una transferencia libera su referencia para corregirla; confirmar la conserva. Sigue vigente la separación entre quien declara y quien verifica, con la excepción ya existente del propietario.

Las cargas y reintegros emiten comprobantes internos `SP-…` en el mismo movimiento. Guardan empresa, beneficiario, operador, referencia, recibido/cambio y saldo resultante del momento, con SHA-256 y protección de solo anexado. Cambiar después los datos de empresa o contacto no cambia esas copias. Los pagos de ventas con saldo conservan el recibo ERP de la venta.

La comisión se calcula al aplicar saldo y cumplir la entrega/atención exigida, sobre los importes históricos de la venta; no se paga por cargar dinero. Las devoluciones revierten el devengo comercial. BI mantiene separados el flujo de caja, las ventas y el saldo prepagado pendiente de aplicar. El saldo de la cuenta no representa por sí solo todas las obligaciones de servicios cobrados aún sin prestar; tampoco sustituye un asiento contable o documento fiscal.

Permisos: propietario, gerente, recepción y finanzas pueden consultar, crear, cargar y aplicar (`erp.cobrar`). Verificar requiere `erp.verificar`; reintegrar dinero requiere `erp.devolver`. Profesional y portal cliente no acceden a esta gestión, incluso si la cuenta propietaria tiene doble membresía. Crear un contacto no crea acceso laboral ni credenciales de cliente.

La nueva vista separa directorio y detalle. El directorio busca por beneficiario/nombre y pagina de 25 en 25; el detalle pagina cargas y movimientos por separado. El libro utiliza los períodos compartidos de semana/mes y fechas propias. El saldo mostrado siempre es el actual, independiente del período histórico consultado.

Migración 034: cuatro tablas nuevas, 96 en total con el esquema actual; no altera importes históricos. El rollback exige conservar las cuentas y su evidencia antes de eliminar el bloque. Ensayar sobre una copia con respaldo y APP_KEY conservada. El recorrido de navegador `PLAT-08` usa formularios reales para carga, impresión, compra con saldo, entrega, devolución y reintegro. Las pruebas de negocio cubren concurrencia, identidad del beneficiario, comisiones, banco, portales y comprobantes. La evidencia definitiva se registra por SHA en el PR de esta entrega.

Pendientes posteriores a 034: paquetes con derechos de sesiones y reglas de consumo/cancelación, suscripciones con ciclos y cobros recurrentes, fidelidad económica y motores propios fiscal/contable/nómina. Su avance se registra en la continuación siguiente.

La revisión del CI del PR #5 corrigió dos conciliaciones de prueba de CRM: la referencia independiente incluía al equipo mientras la pantalla aplicaba el filtro predeterminado de clientes externos. La referencia respeta ahora ese vínculo; los casos incluyen colaboradores con visitas y cobros para verificar su exclusión. No se cambia el filtro del producto. La suite dirigida aprobó 25 pruebas/1080 aserciones; la validación completa del nuevo SHA se registra en el PR.

La actualización de `main` instala las entregas ya fusionadas. Para ensayar este bloque 034 antes de su fusión, seleccionar explícitamente `feature/erp-prepagados` y actualizar esa rama antes de ejecutar el mismo procedimiento Docker. No confundir el piloto de una rama con una instalación ya disponible en `main`.

El ensayo de recuperación detectó que el formato portable conservaba tablas y datos, pero omitía los triggers. El restaurador ahora repone las protecciones desde las migraciones versionadas presentes en el código, primero en staging y después en el destino nuevo. No admite código de triggers proporcionado por el archivo de respaldo. Una migración desconocida o una forma de protección no soportada detiene la validación. El ensayo con cuentas, cargas, saldo aplicado y reintegro recuperó 96 tablas/94 filas, el saldo de 5900 centavos, el comprobante idéntico, la referencia bancaria y las 31 protecciones; UPDATE/DELETE del libro restaurado vuelven a rechazarse. Es necesario conservar una versión de código compatible con las migraciones del respaldo.

Validación local de esta continuación: suite principal en orden aleatorio, 1202 pruebas/18673 aserciones; QA dirigida de rutas, navegación, esquema, caja y finanzas, 241/7335; recuperación, 12/443. La comprobación adicional de la restricción SQL del efectivo aprobó 1/10: una carga en efectivo no admite recibido/cambio nulos. PHP y los dos scripts de navegador pasan la revisión de sintaxis. El recorrido real nuevo PLAT-08 y la QA independiente completa deben finalizar en el CI del SHA publicado; no se presentan como ejecutados localmente.

Las cargas conservan además una clave y huella permanentes en su registro financiero. Vencer o depurar la caché temporal de idempotencia no autoriza a cobrar de nuevo un formulario antiguo, incluso si la caja original ya está cerrada; la misma clave con otros datos se rechaza.

La última ampliación de protección de cargas aprobó los 18 casos de prepagos/95 aserciones, incluido el reenvío después de expirar la caché y con la caja original cerrada. El resultado se presenta como operación ya registrada; no añade carga, dinero ni evento. El CI ejecutará la suite completa del SHA final publicado.


## Continuación 035–036: paquetes y contratos mensuales

El paquete identifica un plan de servicio, beneficiario, sesiones y precio por sesión antes de impuesto. Su financiación utiliza una cuenta nominativa de `erp_prepagos`; no aparece una segunda caja ni una venta al crear derechos. Las condiciones del contrato quedan congeladas. Los planes pueden archivarse para altas nuevas sin cambiar contratos anteriores.

El directorio y la ficha de paquetes están separados y paginados. El beneficiario se busca por nombre, identificación o correo, sin exigir un login. Desde la venta en borrador de una cita se selecciona el paquete de cada servicio; una operación puede cubrir varias sesiones y planes. Se valida identidad, servicio, impuesto, financiación y unidades. No admite anticipos previos ni cupones simultáneos; un descuento anterior de la línea cubierta debe retirarse. Se conserva el precio original y se registra la diferencia como descuento pactado, con recálculo de impuesto. La confirmación, las asignaciones y sus pagos se confirman o revierten juntos.

Una sesión queda reservada al asignarse y consumida al registrar la atención, mediante la misma conciliación que procesa comisiones. El saldo de paquete no se gasta por el formulario de prepago genérico. La comisión de una sesión financiada y atendida utiliza su propio pago, aunque otros productos de la venta sigan sin pagar. La devolución se hace por sesión completa: restituye la unidad y su importe en el prepago original; no entrega efectivo. El reintegro de saldo sin utilizar se registra por separado y reduce las sesiones financiadas disponibles. Un pago de paquete no devuelve otros conceptos de la venta.

La operación de asignación y el alta de suscripción conservan claves y huellas permanentes. Reenviar un formulario tras vencer la caché no duplica sesiones ni contratos, tampoco revive una cancelación. Las cuentas de financiación se bloquean en orden estable y las lecturas de unidades son actuales. Las guardas 035 y 036 bloquean revertir contratos con evidencia; el restaurador repone también sus protecciones versionadas.

Las suscripciones comerciales son distintas de la membresía de cliente para acceder al portal y del consentimiento a marketing. Exigen registrar la aceptación de ciclos mensuales. Cada ciclo crea un paquete independiente, sin cobrar ni conceder sesiones financiadas automáticamente. El cliente carga saldo por efectivo o transferencia verificada. El supervisor abre únicamente el ciclo actual; no genera deudas por meses pausados. Se conserva el día original de anclaje (31 enero → fin de febrero → 31 marzo). El final de cada período es exclusivo.

Pausar o cancelar detiene ciclos futuros. Reactivar una pausa abre o reutiliza el ciclo actual; cancelar es terminal y exige un contrato nuevo para otra alta. Las sesiones se asignan a visitas del período antes de que termine; las asignaciones anteriores conservan su historial. El saldo de un período vencido permanece reintegrable, sin confiscación ni traslado automático a otro ciclo. No se admiten nuevas cargas a ciclos vencidos o contratos cancelados. Un contrato defectuoso no bloquea procesar los demás; tiene diagnóstico y reintento diferido.

Los cobros con tarjeta y renovaciones con débito automático requieren conectar la banca/adquisición y registrar el mandato correspondiente. Esta entrega no solicita datos de tarjeta ni simula cargos. La facturación propia directa al SRI, contabilidad, nómina, fidelidad económica y ampliaciones del plan continúan como trabajos separados.

Se incorpora PLAT-09 para formularios reales de plan, beneficiario vinculado, financiación, reserva, restitución y estados del contrato mensual, además de responsive. Los resultados por SHA y el estado de su ejecución se registran en el PR; añadir el caso no equivale a haberlo ejecutado localmente. La validación local inicial aprobó 52 pruebas/191 aserciones; después se amplió el soporte a varias sesiones y el cálculo de comisiones. La regresión dirigida de esa ampliación aprobó 69/264; los ensayos siguientes y el CI completan su alcance.


## Continuación 037: facturación propia directa al SRI

Se incorpora un piloto nativo de certificación, sin facturador intermediario: clave de acceso, XML factura 2.1.0/nota de crédito 1.1.0, validación con XSD oficiales locales, firma XAdES-BES con tres referencias y transporte SOAP HTTPS a los endpoints oficiales. La factura preparada desde PDV conserva venta, emisor, comprador, precio, descuento, IVA, número, XML y huella; no genera otro ingreso. El certificado se configura por consola y se cifra con APP_KEY.

El supervisor reserva un lease y consulta la misma clave antes de transmitir. Recepción y autorización tienen estados distintos; el XML firmado nunca se regenera tras timeout. Códigos 43/70 se consultan sin retransmitir, el error temporal 50 se reintenta y los rechazos se conservan. No se acepta marcar un documento como autorizado desde un formulario. El contador pertenece a RUC/ambiente/serie/tipo, independiente de versiones del perfil.

El directorio y ficha fiscal separan filtros, preparación y evidencia. El XML firmado solo se descarga con permiso de equipo y sin caché. Se añade PLAT-10 de preparación por formulario, persistencia, integridad, descarga y responsive, sin contactar al SRI. Su ejecución definitiva se registra por SHA en el PR. El detalle del alcance, consola y pendientes está en `FACTURACION_PROPIA_SRI.md`.

La migración 037 deja 104 tablas y 42 protecciones SQL. El ensayo dirigido inicial aprobó 50 pruebas/142 aserciones, incluidos dos procesos reales, numeración al cambiar el emisor, respaldo/restauración del XML y certificado cifrado, timeout, estados y restricciones. La QA de esquema aprobó 13/224. La firma también se comprobó con Python/lxml/cryptography, independientemente del firmador PHP. Las ampliaciones de pantallas y permisos se verifican a continuación y en CI. Producción, corrección de rechazos, integración de notas de crédito, anticipos, RIDE, entrega al correo y reglas fiscales por régimen continúan pendientes.


La continuación 038 protege el número fiscal mediante un índice único por RUC, ambiente y número, tomando la identidad del snapshot inmutable. No añade tablas ni columnas a los datos exportados. El precheck detecta identidades incompletas o números duplicados antes de modificar el esquema; el rollback conserva la protección cuando existen documentos. La regresión fiscal de esta ampliación aprobó 20 pruebas/86 aserciones y la comprobación adicional del índice restaurado aprobó 1 prueba/12 aserciones, incluida la denegación de números duplicados después de restaurar.

El CI de los SHA iniciales de #6 y #7 aprobó los recorridos E2E (incluido PLAT-10 en #7) y las suites principales 1243/18996 y 1294/19240, respectivamente. La QA encontró una expectativa antigua que prohibía toda tabla de suscripciones: se reemplaza por un contrato real de sesiones y la comprobación de que CRM no lo muestra como marketing ni crea ciclos/cobros al consultar. Se corrige además una omisión de QA después de las 23:30: la prueba HTTP conserva el inicio a 30 minutos aunque cruce medianoche, según la misma regla de 60 minutos cubierta por pruebas unitarias. Los dos casos dirigidos aprobaron 2/19. Los CI de los nuevos SHA siguen siendo necesarios para cerrar la entrega.


La descarga fiscal admite ahora el contenedor autónomo de autorización. Solo se ofrece en estado autorizado y se reconstruye desde la respuesta inmutable del SRI, con nueva verificación de clave, ambiente, fecha y XML canónico completo. Conserva el XML firmado dentro de CDATA sin alterar sus bytes ni su firma, incluso cuando contiene CDATA interno. La descarga usa los mismos permisos y política privada/sin caché, no contacta al SRI y no genera movimientos ni números. El respaldo/restauración comprueba también esta evidencia autorizada. Las pruebas unitarias de autorización y SOAP aprobaron 28/49; integración fiscal y portales, 26/123; el controlador autenticado entregó ambos formatos en 1/2 adicionales. La QA de esquema tras 038 aprobó 13/224. La suite completa de CRM con el contrato comercial real aprobó 13/969. PLAT-10 añade la denegación HTTP del contenedor mientras el comprobante está pendiente; se ejecutará en el CI del nuevo SHA. RIDE y entrega al correo continúan pendientes.


La siguiente ampliación incorpora el RIDE de facturas autorizadas en una vista privada e imprimible. Su proyección usa exclusivamente el XML conservado, distingue tarifa cero/exento/no objeto y conserva los datos tributarios y formas pactadas de pago. El catálogo puede cambiar sin cambiar la representación. El botón usa la impresión del navegador para papel/PDF; no existe todavía PDF generado por servidor ni adjunto de correo. La hoja de impresión separa navegación del comprobante, mantiene el aviso de pruebas y dispone encabezado repetido para detalles largos. Se añade PLAT-11 con autorización sintética por el transporte de ensayo exclusivamente en la base E2E, cuatro anchos y PDF A4 de navegador. Su verificación visual sigue pendiente del CI del nuevo PR.

La regresión unitaria de RIDE/autorización/SOAP aprobó 31 pruebas/68 aserciones; integración de controlador, conservación del catálogo y portales, 6/33; matriz HTTP de la ruta nueva y cobertura del registro de rutas, 2/5. Compilación CSS, sintaxis PHP/JavaScript y shell correctas. El auxiliar de autorización sintética rechaza ejecutarse fuera de `appsalon_e2e_test` antes de cargar la aplicación. PLAT-11 y el PDF visual siguen pendientes de su CI; estas pruebas dirigidas no acreditan ejecución local del navegador ni certificación real.

CI confirmado del 19/10/2026: #6, SHA 0a99f2e, aprobó E2E, PHPUnit 1243/18996, QA 1003/20921 y respaldo/restauración. #7, SHA 2f6c815, aprobó E2E, PHPUnit 1313/19300, QA 1005/20951 y respaldo/restauración. En #8, SHA b4af7e0, PLAT-11 aprobó RIDE responsive e impresión PDF; la suite aleatoria encontró que la referencia independiente de CRM omitía devoluciones parciales del libro. Se corrige únicamente la referencia de prueba y se añade una devolución parcial de 1900 centavos dentro de una transacción que se revierte. La suite CRM con la misma semilla 1792415378 aprobó 25/1085. El nuevo SHA requiere repetir el CI completo.


## Continuación 039: PDF de servidor y entrega fiscal transaccional

Se añade PDF local desde el mismo fragmento RIDE, sin navegación ni recursos externos y con soporte de facturas largas. La ficha autorizada permite descargar PDF y programar su entrega junto al XML, confirmando el destino. Usa el outbox existente y sus leases/reintentos; la migración 039 amplía carga_cifrada a MEDIUMTEXT sin añadir tablas ni triggers. La deduplicación por documento/destino permanece tras vencer la caché. Las lecturas actuales tras el bloqueo cubren concurrencia; el worker liga el destino a la carga autenticada y valida adjuntos y evidencia antes de SMTP. Se auditan hashes, no adjuntos en claro. Al aceptar SMTP se borra la carga y se muestra el estado sin afirmar entrega al buzón. No se duplica caja, venta o numeración.

Los archivos XML/PDF se congelan juntos antes de encolar, hasta 5 MB. Los reintentos conservan contenido y Message-ID. La garantía sigue siendo al menos una vez: una caída tras la aceptación SMTP puede repetir un correo. La ficha permite reactivar un fallo, no generar otra fila. El PDF descargado es una nueva representación de los mismos datos; la evidencia fiscal original permanece en XML. El respaldo de entregas pendientes se restaura junto con APP_KEY por canal separado.

PLAT-12 añade descarga PDF privada y programación por formulario, despacho al SMTP mock, comparación independiente de hashes de los adjuntos MIME y repetición sin otra cola ni caja. El CI del SHA publicado debe verificarlo. Continúan pendientes notas de crédito integradas/RIDE, corrección de rechazos, fiscalidad de anticipos/paquetes/suscripciones, régimen fiscal definitivo y certificación real. Contabilidad y nómina propias continúan después del circuito fiscal.

Validación dirigida de 039: integración fiscal, portales y enlaces de cuenta 44/238; regresión de colas de cuenta y entrega 34 casos correctos y un hallazgo de restauración corregido después; cierre de respaldo/restauración (incluidos adjuntos pendientes y SQL hostil) y concurrencia real 12/214; unitarias de PDF, correo y lectura SQL 13/79; QA de esquema y ruta PDF 15/229. La lectura de estructura SQL del restaurador pasa a un recorrido lineal que enmascara literales con escapes, sin depender de PCRE para cargas cifradas grandes; conserva los bloqueos de SQL externo y rechaza literales sin cerrar. Se inspeccionó el PDF con Poppler: texto con tildes, datos y totales; representación de 100 líneas en seis páginas. El ajuste final alinea los totales con una tabla compartida. Sintaxis PHP/JavaScript y compilación CSS correctas; Composer valida el esquema y su lock, con el aviso de licencia del proyecto ya existente. Las suites completas y PLAT-12 quedan pendientes del CI del SHA publicado.

## Continuación 040: notas de crédito fiscales de extremo a extremo

Rama `feature/erp-notas-credito`, apilada sobre `feature/erp-entrega-fiscal` (#9). Conecta la factura autorizada, las devoluciones comerciales y la nota de crédito XSD 1.1.0 sin mezclar hechos: la devolución de dinero, el reintegro físico al inventario, el reverso de comisión y el asiento contable siguen siendo operaciones propias de `ErpDevolucionService` (y, a futuro, de contabilidad); la nota solo acredita fiscalmente y la prueba verifica que no modifica pagos, caja, inventario, devoluciones ni comisiones.

Detalle funcional, reglas de topes y redondeo, y el cambio de identidad fiscal por tipo: `docs/FACTURACION_PROPIA_SRI.md`, sección «Notas de crédito». Resumen técnico:

* **Migración 040** (aditiva; 035–039 sin tocar): `tipo`, `documento_origen_id`, unicidad «una factura por venta» (índice funcional) y «número único por emisor, ambiente y tipo»; tablas de solo anexar `erp_fiscal_nc_devoluciones` (UNIQUE por devolución) y `erp_fiscal_nc_lineas`; trigger de inmutabilidad ampliado. Verificada sobre una base con una factura previa: queda como `01`, y el rollback se niega si existen notas. Deja 106 tablas.
* **Concurrencia:** orden de bloqueo venta → documento fiscal (como las devoluciones) y lecturas `FOR SHARE` para ver lo confirmado por otro proceso; la prueba con dos procesos reales falló sin ellas (1062) y pasa con ellas.
* **Reutiliza** worker con lease, consulta de la misma clave, XML autorizado, RIDE/PDF y la cola de correo cifrada (asunto y adjuntos propios de la nota).
* **Respaldo:** el primer diseño usaba una columna generada que el respaldo portable intentaba escribir (error en la restauración, detectado por la prueba existente); se sustituyó por un índice funcional.
* **Contrato de pruebas cambiado y justificado:** `testRespaldoRestauraXmlCertificadoNumeracionYProtecciones` verificaba el índice de 3 partes `uq_fiscal_numero_emisor`; ahora verifica `uq_fiscal_identidad_documento` (4 partes, con tipo) y `uq_fiscal_factura_unica`. `EsquemaTest`: 104 → 106 tablas.

## Continuación 041: corrección de comprobantes rechazados por sustitución

Rama `feature/erp-fiscal-sustitucion`, apilada sobre `feature/erp-notas-credito` (#10). Verificación previa con el SRI (worker), sustitución de facturas y de notas de crédito con herencia de devoluciones, cadena de trazabilidad y topes que excluyen lo rechazado. Detalle y reglas: `docs/FACTURACION_PROPIA_SRI.md`, sección «Corrección de comprobantes rechazados».

* **Migración 041** (aditiva): `sustituye_a_id` (FK, único, inmutable), unicidad de factura raíz por venta, tabla `erp_fiscal_verificaciones` (una solicitud abierta por comprobante; resultados cerrados inmutables). Deja 107 tablas. Aplicada sobre una base con factura y nota previas; rollback verificado (restaura el esquema de 039 y se vuelve a aplicar; la guarda lo niega si hay sustituciones o verificaciones).
* **Contrato de pruebas cambiado:** el respaldo/restauración verificaba `uq_fiscal_factura_unica`; ahora `uq_fiscal_factura_raiz`. `EsquemaTest`: 106 → 107 tablas.

## Continuación 042: reglas fiscales por parámetros y controles previos a transmitir

Rama `feature/erp-fiscal-reglas`, apilada sobre `feature/erp-fiscal-sustitucion` (#11, validada en CI en `598ea17`). Detalle y fuentes: `docs/FACTURACION_PROPIA_SRI.md`, sección «Reglas fiscales por parámetros (migración 042)».

* **Migración 042** (aditiva; el número 042 estaba libre: ninguna rama remota la usaba): tabla `erp_fiscal_parametros` (solo de anexar, con triggers), estado `bloqueada` en `erp_fiscal_documentos` (congelado por el trigger de inmutabilidad) y guarda de rollback que se niega si hay parámetros o documentos bloqueados. Deja 108 tablas.
* **Parámetros por régimen y vigencia:** IVA por código, máximo de consumidor final, leyenda de régimen, momento del hecho generador y vigencia de la verificación. AppSalon no trae cifras legales: una persona con `erp.configurar` las registra con fuente, enlace y fecha de consulta; sin parámetro vigente no se emite. La instantánea del comprobante conserva la referencia usada. Pantalla `/gestion/facturacion/parametros`.
* **Consumidor final**, **anticipos/prepago/paquetes/suscripciones** y **notas sobre consumidor final** (bloqueadas): reglas y límites en el documento fiscal. Recibir dinero, asignar la sesión, prestar y emitir son hechos separados; la factura se emite una vez, al prestar, y no mueve caja, saldo ni sesiones.
* **Revisión de la entrega 041:** (1) los 120 minutos son una política interna, no una cifra del SRI; ahora son un parámetro y el control decisivo es la reconsulta del sustituido inmediatamente antes de transmitir el sustituto (estado `bloqueada` si aparece autorizado); (2) la trazabilidad de notas rechazadas ya estaba garantizada por el vínculo único por devolución; se añadió una prueba con tres procesos reales (la sustituta, una nota independiente sobre la devolución reservada y otra sobre la pendiente) que confirma que cada devolución queda vinculada a una sola nota; (3) ver el registro siguiente.
* **Contrato de pruebas cambiado y justificado:** las suites fiscales registran parámetros de ensayo (`Fixtures::parametros`, valores marcados «no oficial») porque ahora la factura los exige. `EsquemaTest`: 107 → 108 tablas.

### Registro del fallo intermitente del E2E (PR #11, run 170)

El paso «Recorrido E2E aislado en Apache, MySQL y SMTP mock» falló en `tests/browser_e2e_test.js:1157` ([RECORRIDO 4], resumen de reserva: la espera de 30 s terminó con la alerta «Faltan datos de servicios, profesional, fecha u horario disponible», sin errores de consola). El mismo commit pasó el E2E al reintentar, y el commit anterior (mismo código) lo había pasado en el run 168. **Causa no confirmada.** Pasar un reintento no demuestra que el fallo sea ajeno al cambio: el comentario inicial en el PR lo presentó como ajeno y esa conclusión no estaba demostrada. Evidencia disponible: el artefacto `apache-browser-e2e` del primer intento (id 12205134531, caduca el 17/01/2027) y el registro del job; no se pudo descargar desde el entorno de desarrollo (la política de red bloquea el almacenamiento de artefactos). Si reaparece: conservar el artefacto, comparar `browser.log` con el run verde y tratarlo como defecto hasta explicarlo (hipótesis abiertas: disponibilidad de horarios que depende de la hora del día de la ejecución, o carrera entre la carga de profesionales y el paso 3).

### Registro del fallo intermitente de QA (PR #12, run 172)

El paso «QA independiente (tests/Qa)» del run 172 (evento `pull_request`, SHA `faba312`) falló en una sola prueba de 1008: `R2bColaRecuperacionTest::test_ninguna_ruta_publica_envia_correo_dentro_de_la_peticion_con_un_smtp_lento_...` (línea 115): «registro pendiente: 390 ms» frente al umbral de 300 ms. El run 171 (evento `push`, mismo SHA) pasó la misma prueba. **Causa no confirmada.** La aserción anterior (que ninguna petición envíe correo en línea) sí pasó, y el cambio no toca registro, recuperación, cola de correo ni autenticación; la hipótesis más probable es una latencia puntual del ejecutor (la prueba mide tiempo de reloj contra un SMTP simulado de 400 ms), pero la cercanía de 390 ms a esos 400 ms impide descartar un envío en línea intermitente. Que otra ejecución pase no demuestra independencia. Acción: conservar el artefacto `qa-junit` (id 12224463733); si reaparece, tratarlo como defecto de la ruta de registro (medir el desglose por paso y registrar si el envío ocurre en línea) y no como ruido.

## Pendientes registrados (decisiones y trabajo diferido)

1. **Anonimización de usuarios con historial.** Hoy se conserva la identidad histórica por desactivación y auditoría. Falta la política de anonimización (qué campos, a qué plazo, qué se conserva por obligación tributaria o laboral) y su implementación con auditoría. Requiere decisión del propietario y criterio legal.
2. **Baja lógica de servicios.** `/servicios/eliminar` borra físicamente; `citasservicios` no tiene clave foránea al servicio, de modo que el histórico conserva nombre y precio pero pierde la referencia. Debe sustituirse por baja lógica (`activo=0`, sin borrado) con bloqueo si hay uso, reactivación y auditoría.
3. **Filtro de `/caja` por fecha de cobro.** Distinguir dos ejes que hoy se pueden confundir: los **reportes de ventas/atención**, que se filtran por la fecha del hecho comercial (venta, atención o emisión), y los **movimientos de dinero**, que se filtran por la fecha del cobro, pago o devolución en caja. Un día puede tener ventas sin cobrar y cobros de ventas de otros días (anticipos, saldos). Propuesta: dos filtros con etiquetas explícitas, totales que no se mezclan, el cierre de caja por fecha de movimiento y la conciliación mostrando ambos. Pendiente de decisión y de implementación.
4. **Facturación en el cobro** (comprobantes sin venta de origen) para quien elija el hecho generador en el pago.
5. **Límite porcentual de notas de crédito** reportado para mayo de 2026: confirmar la resolución antes de implementar.
6. **Verificación de cada parámetro fiscal** contra el texto oficial vigente (la política de red del entorno de desarrollo bloquea `sri.gob.ec`).
7. **Anticipo retenido tras cancelación o inasistencia:** tratamiento fiscal sin definir; hoy el sistema obliga a resolverlo (devolverlo) y no lo convierte en ingreso.

## Continuación 043: contabilidad propia por partida doble

Rama `feature/erp-contabilidad`, apilada sobre `feature/erp-fiscal-reglas` (#12). Detalle, tabla de hechos y asientos, reglas deliberadas y límites: `docs/CONTABILIDAD_PROPIA.md`.

* **Migración 043** (aditiva): configuración con inicio contable, numeración continua, plan de cuentas (plan base editable), roles contables, períodos, asientos y líneas. Libro de solo anexar con triggers (sin `UPDATE`/`DELETE`), partida doble verificada por la base al insertar la última línea, período abierto obligatorio, cuentas con movimientos inmutables. Guarda de rollback: se niega si hay asientos o cuentas creadas por usuarios. Deja 115 tablas (+7).
* **Adaptador contable** (`services/Contabilidad/ErpContabilidadAdaptador.php`) sobre el outbox existente: cada hecho (cobro, anticipo, aplicación, reembolso, entrega, costo, prestación, devolución, restitución, compra, factura del proveedor, pago, comisión, activo, insumos) genera su asiento con identidad permanente (`outbox:<referencia>#n`); reprocesar no duplica. La venta confirmada se confirma sin asiento a propósito. El supervisor (`scripts/procesar_erp.php`) solo lo activa tras definir el inicio contable.
* **Cambio aditivo en un servicio entregado:** el evento `venta_devuelta` ahora incluye `entregada_ahora` y `reembolsos` (cada devolución de dinero o restitución de saldo) para contabilizar cada hecho por separado. Ningún consumidor anterior leía esas claves.
* **Permisos nuevos y segregados:** `contabilidad.ver` (propietario, gerente, finanzas), `contabilidad.registrar` (propietario, finanzas), `contabilidad.configurar` (propietario). Contrato de pruebas actualizado a propósito: la tabla escrita a mano de permisos por rol de `AutorizacionMatrizTest`, el registro de rutas y `EsquemaTest` (108 → 115 tablas).
* **Hallazgo corregido en el camino:** el primer trigger de balance anidaba dos `IF`; el lector de protecciones de la restauración (`ProteccionesRestauradas`) solo admite un `IF … END IF` por trigger y la restauración fallaba. La prueba de respaldo/restauración existente lo detectó; el trigger se aplanó con la misma garantía.
* **Pruebas:** ledger/inmutabilidad/períodos/cierre/reversos/permisos/concurrencia con procesos reales (15), hechos y conciliaciones con auxiliares (9), pantallas y acciones (en la misma suite).

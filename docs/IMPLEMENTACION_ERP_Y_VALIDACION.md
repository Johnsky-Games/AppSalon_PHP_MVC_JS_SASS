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

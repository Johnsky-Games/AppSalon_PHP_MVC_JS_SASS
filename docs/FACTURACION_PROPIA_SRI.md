# Facturación propia: avance del piloto

La lógica y las evidencias pertenecen a AppSalon. El receptor externo es el SRI; no se exige contratar un facturador intermediario. Esta etapa incorpora el motor XML, firma, transporte y cola de certificación, y no habilita todavía facturación de producción.

## Flujo implementado

1. Una cuenta con `erp.configurar` registra una versión del emisor y su PKCS12 desde la consola privada. Debe confirmar la titularidad. La configuración se valida mediante un documento sintético que se descarta sin enviarse. El certificado y su contraseña se cifran con una clave derivada de `APP_KEY`; no aparecen en eventos ni argumentos.
2. Una cuenta con `erp.cobrar` prepara desde el detalle del PDV una factura de una venta confirmada con servicios atendidos y productos entregados. Registra comprador identificado, categoría fiscal de IVA por línea y formas de pago pactadas. El piloto rechaza devoluciones, prepagos, consumidor final, ICE y exportación; requiere completar sus reglas antes de ampliar el circuito.
3. Se bloquea la venta y el contador de RUC/ambiente/serie/tipo de documento. Cambiar dirección o certificado del emisor no reinicia la secuencia. La factura congela comprador, conceptos, descuentos, impuestos, clave, número, XML firmado y huella. Un error de preparación revierte toda la transacción, sin consumir número ni cobrar dinero.
4. El supervisor toma un lease y consulta la misma clave antes de transmitir. HTTPS ocurre fuera de la transacción SQL. Una recepción `RECIBIDA` conserva estado pendiente de autorización. Los reintentos usan el mismo XML y clave; un timeout no genera otra factura.
5. Solo se registra `autorizada` al recibir una autorización del ambiente esperado, con la misma clave y el mismo XML firmado. Un resultado todavía no disponible se difiere; un rechazo conserva el diagnóstico. El documento no se elimina ni se reescribe.
6. Desde la ficha autorizada se descarga un XML autónomo de autorización con estado, número, fecha, ambiente, comprobante firmado en CDATA y mensajes del SRI. Se reconstruye únicamente desde la respuesta conservada e inmutable, validando de nuevo su correspondencia canónica completa con el original. La descarga no contacta al SRI ni modifica caja, venta o contador; exige el mismo permiso de equipo y no permite caché. El XML firmado original sigue disponible por separado. Si falta autorización o hay evidencia incompatible, se bloquea la descarga.

La migración 037 añade tres tablas (104 en total sobre esta rama) y cuatro protecciones SQL. Su guarda de rollback conserva cualquier emisor, secuencia o documento registrado. La migración 038 añade unicidad de número por RUC y ambiente, independiente del perfil y del contador, y exige identidad completa en el snapshot. Su prevalidación detiene datos incompatibles sin alterarlos. Un contador inconsistente no permite emitir otro documento con el mismo número; requiere revisar la evidencia. Los índices funcionales se conservan también en los respaldos portables. El directorio `/gestion/facturacion` separa listado, preparación y ficha, con filtros de semana/mes/fechas y estado, paginación de 25 y descarga privada del XML firmado. La carga del certificado continúa solo por consola privada. El certificado cifrado también debe conservarse con los respaldos, junto con `APP_KEY` por un canal separado.

## RIDE de la factura autorizada

La ficha ofrece «Ver RIDE / imprimir» solamente al autorizarse. La ruta `/gestion/facturacion/ride?id=…` vuelve a exigir permiso de equipo y evidencia íntegra. Emisor, comprador, conceptos, cantidades, importes, descuentos, subtotales por categoría de IVA, pagos pactados y régimen se proyectan del XML autorizado, sin usar precios o nombres actuales del catálogo ni recalcular con decimales flotantes. El piloto conserva el aviso de pruebas en pantalla y en impresión.

El botón «Imprimir / guardar PDF» utiliza el diálogo del navegador; permite papel o PDF, sin depender de un generador externo. La impresión A4 oculta la navegación y repite el encabezado de la tabla cuando hay varias páginas. La ficha también permite descargar un PDF generado en el servidor por Dompdf 3.1.6 o posterior compatible, usando el mismo fragmento del comprobante, con recursos remotos, PHP y JavaScript desactivados. El formato de totales usa una tabla compartida para mantener alineación en pantalla y PDF. Solo admite facturas del circuito soportado; el RIDE de notas de crédito y su integración comercial continúan pendientes. La referencia es la ficha técnica 2.34 del SRI, apartado 9 y anexo 2, indicada en `resources/sri/README.md`.

## Entrega por correo

Desde una factura autorizada, «Programar entrega del comprobante» solicita un correo confirmado por el operador. Requiere permiso `erp.cobrar` en el portal de equipo y CSRF. No utiliza el consentimiento de promociones ni obliga al comprador a tener una cuenta. La venta, sus pagos y el contador no cambian.

El XML autorizado y el RIDE PDF se congelan una sola vez, con límite conjunto de 5 MB. La cola `correos_salida` conserva ambos adjuntos cifrados con APP_KEY, además del destino ligado dentro de la carga autenticada. La migración 039 amplía únicamente esa carga a MEDIUMTEXT: continúan 104 tablas y 42 protecciones. Su guarda impide revertir entregas fiscales o truncar cargas grandes. El evento de negocio conserva las huellas de adjuntos y destino, sin copiar datos personales ni certificados.

La clave permanente por documento/destino evita que otro formulario o una caché de idempotencia vencida vuelva a programar la misma entrega. Un bloqueo actual del documento y lecturas actuales de la cola cubren dos solicitudes concurrentes. El mensaje mantiene el mismo Message-ID en los reintentos. Esto no garantiza SMTP exactamente una vez: si el servidor acepta y el proceso cae antes de marcar «enviado», el correo puede repetirse.

El worker existente `php scripts/procesar_correos.php --limite=20` valida documento, autorización, hashes y destinatario antes de SMTP, fuera de la transacción comercial. Un adjunto inconsistente termina en fallo para revisión; un fallo SMTP se reintenta con el mismo contenido y retroceso. Al aceptar SMTP, la cola elimina la carga cifrada; el XML fiscal original y las huellas auditadas permanecen. «Aceptado por SMTP» no confirma recepción en el buzón ni lectura. La ficha muestra estado, intentos y diagnóstico saneado, y permite reactivar una entrega fallida sin crear otra fila. No permite reenviar indefinidamente una entrega aceptada al mismo destino.

El PDF descargado después se vuelve a proyectar desde el XML autorizado; sus metadatos de creación pueden cambiar. Los adjuntos reintentados, en cambio, son los bytes congelados en la cola. El respaldo conserva también las entregas pendientes cifradas; es necesario restaurar APP_KEY por separado para despacharlas. El SMTP se configura con las variables existentes EMAIL_HOST/PORT/USER/PASSWORD, sin incorporar un facturador intermediario.

## Consola del piloto

Al actualizar esta rama, instalar las dependencias de composer.lock con `composer install` (o `composer install --no-dev` en el despliegue) y aplicar `php database/migrador.php up`, incluida 039. Ejecutar la consola dentro del contenedor con las migraciones al día. Guardar las configuraciones y el PKCS12 en un volumen privado, fuera de `public` y del repositorio. La contraseña se lee por entrada estándar desde un secreto, nunca como argumento.

```sh
php scripts/fiscal_sri.php perfil /run/secrets/emisor-config.json /run/secrets/emisor.p12 < /run/secrets/emisor-password
php scripts/fiscal_sri.php factura /run/secrets/factura-config.json
php scripts/fiscal_sri.php procesar
```

`emisor-config.json` contiene `actor_id`, `idem_key` (32 caracteres hexadecimales nuevos por operación), `titular_confirmado: "1"` y `emisor` con: `ruc`, `ambiente: "1"`, `establecimiento`, `punto_emision`, `razon_social`, `dir_matriz`, `obligado_contabilidad: "SI"|"NO"`. Nombre comercial, dirección del establecimiento, contribuyente especial, agente de retención y RIMPE son opcionales según el emisor; no se adivinan.

`factura-config.json` contiene `actor_id`, `idem_key`, `perfil_id`, `venta_id`, `comprador` (`tipo`, `identificacion`, `nombre`, `direccion`), `categorias_iva` (objeto de ID de línea a código SRI) y `pagos` (lista de `forma`, `total_centavos` y, si corresponde, `plazo_dias`). Los importes son enteros en centavos. Los precios y descuentos se toman de la venta; el archivo no puede cambiarlos.

La categoría IVA se indica explícitamente: tarifa cero, exento y no objeto son conceptos diferentes. La tabla soportada está en `SriComprobante`; la vigencia aplicable y el régimen del emisor deben configurarse en el circuito definitivo. Los códigos de forma de pago describen el comprobante y no registran otro ingreso de caja.

## Notas de crédito (migración 040)

**Qué es.** Un comprobante fiscal (codDoc `04`) que modifica una **factura autorizada** a partir de **devoluciones comerciales ya registradas**. Se prepara desde la ficha de la factura (`/gestion/facturacion?id=…`), eligiendo las devoluciones pendientes de acreditar y la razón de la modificación. Requiere los permisos `erp.cobrar` y `erp.devolver`; recepción y clientes reciben 403.

**Hechos que permanecen separados.** La nota **no** devuelve dinero, **no** reintegra inventario, **no** revierte comisiones y **no** genera asiento: esos son efectos de la devolución comercial (`ErpDevolucionService`) y se conservan intactos (la prueba compara pagos, devoluciones de cobro, caja, inventario, devoluciones, líneas y comisiones antes y después). Tampoco es la devolución de una sola vía: una devolución comercial puede existir sin nota (p. ej. antes de que la factura se autorice) y la ficha lista las pendientes.

**Reglas.**
- La factura debe estar `autorizada`; las líneas de la venta deben coincidir con las facturadas (cantidad, precio, descuento, tarifa) o se rechaza (409). Las facturas nuevas guardan `linea_id` en su instantánea; las anteriores se relacionan por posición verificada.
- Cada devolución puede acreditarse **una sola vez** (`UNIQUE` en `erp_fiscal_nc_devoluciones.devolucion_id`), aunque dos procesos compitan: el segundo recibe 409 tras esperar el candado de la venta.
- Límites parciales y acumulados por línea: la suma de cantidades y totales de las notas de una línea no supera lo facturado, y la de la factura no supera su total. El descuento se reparte con redondeo acumulado; la última porción absorbe el resto (ajuste ≤ 5 centavos hacia abajo, nunca por encima), de modo que la suma de las notas de una línea cierra con a lo sumo 1 centavo por debajo.
- El importe fiscal puede diferir del crédito comercial en centavos por redondeo; la instantánea guarda `monto_comercial_centavos` y `diferencia_redondeo_centavos` y la ficha la muestra.
- Idempotencia permanente por clave de operación y huella; el emisor, certificado y comprador son los de la factura.

**Identidad fiscal.** `erp_fiscal_documentos` gana `tipo` (`01` factura, `04` nota) y `documento_origen_id`; la unicidad por factura usa un índice funcional (no una columna generada, para que los respaldos portables no necesiten escribirla). Se **sustituyen** dos garantías sin perder ninguna: «una fila fiscal por venta» pasa a «una **factura** por venta» (`uq_fiscal_factura_unica`), y «número único por emisor y ambiente» pasa a «número único por emisor, ambiente **y tipo**» (`uq_fiscal_identidad_documento`), porque factura y nota comparten serie y pueden compartir secuencial. Cada tipo tiene su contador en `erp_fiscal_secuencias(documento)`. Los documentos existentes quedan como `01`. Los vínculos devolución↔nota y las líneas acreditadas son de solo anexar (triggers). El trigger de inmutabilidad incluye tipo y origen. El rollback se niega si existen notas.

**Evidencia y entrega.** Reutiliza el worker de lease/consulta/reintentos con la misma clave y XML, la descarga del XML autorizado, el RIDE (A4/impresión), el PDF y la cola de correo cifrada (asunto «Nota de crédito», adjuntos `nota-credito-…`). El RIDE se proyecta del XML autorizado (documento modificado, razón y valor modificado; sin formas de pago).

**Límites actuales.** Ambiente de pruebas. Una nota **rechazada** se corrige con la sustitución (sección siguiente). No cubre consumidor final, anticipos/prepago/paquetes/suscripciones, ni devoluciones anteriores a la factura (la factura sigue rechazando ventas con devolución previa).

## Corrección de comprobantes rechazados: sustitución (migración 041)

Un comprobante **rechazado** (factura o nota de crédito) nunca se edita ni se reutiliza: conserva su XML firmado, clave de acceso, número, instantánea, respuesta del SRI y estado (los triggers de inmutabilidad ya impedían reescribirlos). La corrección es un **documento nuevo** (otro número y otra clave) con `sustituye_a_id` apuntando al rechazado.

**Rechazo definitivo frente a incierto.** El estado `rechazada` proviene de dos fuentes: «NO AUTORIZADO» en la consulta de autorización, o «DEVUELTA» en la recepción. `pendiente` y `recibida` (incluidos los códigos 43/70, ya registrados, y el 50, temporal) siguen siendo inciertos o en proceso y **no** son sustituibles. Antes de permitir la sustitución, un operador con `erp.configurar` solicita una **verificación**; el worker (`scripts/procesar_erp.php`, fuera de transacciones) consulta la misma clave y clasifica:
- el SRI responde **AUTORIZADO** → `autorizado_en_sri`: conflicto, se audita (`fiscal_conflicto_estado`) y la sustitución queda bloqueada;
- **NO AUTORIZADO**, o sin registro cuando el rechazo original fue «DEVUELTA» en recepción → `confirmado`;
- cualquier otra cosa (timeout, sin registro tras un «NO AUTORIZADO», respuesta no verificable) → `incierta`: se reintenta la misma clave cada 5 minutos y no habilita nada.
Solo una verificación `confirmado`, de menos de 120 minutos y sin sucesor, habilita la sustitución. Los resultados cerrados son inmutables y no se borran.

**Sustitución.** `fiscal_sustituir_factura` (datos corregidos: emisor, comprador, categorías IVA, pagos; misma venta) y `fiscal_sustituir_nc` (razón corregida). Permisos `erp.configurar` + `erp.cobrar` (factura) o `erp.devolver` (nota); recepción y clientes reciben 403. Garantías de base: una sola sustituta por rechazado (`uq_fiscal_sustituto`), una factura **raíz** por venta (`uq_fiscal_factura_raiz`: la sustituta solo existe como sucesora de una rechazada) y `sustituye_a_id` inmutable. Dos procesos simultáneos producen una sola sustituta (prueba con procesos reales).

**Devoluciones de una nota rechazada.** Siguen vinculadas a la nota rechazada (no se mueven ni se borran filas): nadie puede acreditarlas en una nota independiente mientras tanto. La sustituta **hereda** todas las devoluciones de la cadena de rechazos (se resuelven recorriendo `sustituye_a_id`) y puede **sumar** devoluciones pendientes; solo las nuevas se vinculan a ella, de modo que cada devolución está vinculada exactamente una vez y la trazabilidad completa queda en la cadena. Una nota rechazada deja de contar contra los topes de cantidad, base e impuestos; la sustituta los recalcula con todas las devoluciones.

**Efectos que no ocurren.** Sustituir no genera cobros, devoluciones de dinero, movimientos de inventario ni reversos de comisión. **Límite:** si el SRI llegara a autorizar un comprobante ya marcado `rechazada`, el estado local no puede cambiarse (inmutable); se reporta el conflicto y requiere conciliación manual con la administración.

## Validación y trabajo que continúa

Las pruebas usan certificados RSA sintéticos y un transporte de ensayo inyectado exclusivamente en `APP_ENV=testing`. No envían comprobantes ficticios a producción. Existe una comprobación independiente de firma y referencias con Python/lxml/cryptography durante el desarrollo; los ensayos por SHA se registran en el PR.

La nota de crédito (XSD 1.1.0) ya está integrada de extremo a extremo (sección «Notas de crédito»). Continúan la revisión/corrección de rechazos, consumidor final con sus restricciones, fiscalidad de anticipos/paquetes/suscripciones, configuración del momento de IVA y reglas por régimen, y ensayo real con el certificado del emisor en certificación. Solo después se habilitará producción de forma explícita.

Este avance no sustituye la contabilidad propia de doble partida, cierre tributario ni nómina. Son módulos pendientes con sus propias reglas, autorizaciones y conciliaciones; no se presentan como completados por tener una factura XML o un recibo interno.

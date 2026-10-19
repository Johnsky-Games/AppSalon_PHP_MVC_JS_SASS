# Facturación propia: avance del piloto

La lógica y las evidencias pertenecen a AppSalon. El receptor externo es el SRI; no se exige contratar un facturador intermediario. Esta etapa incorpora el motor XML, firma, transporte y cola de certificación, y no habilita todavía facturación de producción.

## Flujo implementado

1. Una cuenta con `erp.configurar` registra una versión del emisor y su PKCS12 desde la consola privada. Debe confirmar la titularidad. La configuración se valida mediante un documento sintético que se descarta sin enviarse. El certificado y su contraseña se cifran con una clave derivada de `APP_KEY`; no aparecen en eventos ni argumentos.
2. Una cuenta con `erp.cobrar` prepara desde el detalle del PDV una factura de una venta confirmada con servicios atendidos y productos entregados. Registra comprador identificado, categoría fiscal de IVA por línea y formas de pago pactadas. El piloto rechaza devoluciones, prepagos, consumidor final, ICE y exportación; requiere completar sus reglas antes de ampliar el circuito.
3. Se bloquea la venta y el contador de RUC/ambiente/serie/tipo de documento. Cambiar dirección o certificado del emisor no reinicia la secuencia. La factura congela comprador, conceptos, descuentos, impuestos, clave, número, XML firmado y huella. Un error de preparación revierte toda la transacción, sin consumir número ni cobrar dinero.
4. El supervisor toma un lease y consulta la misma clave antes de transmitir. HTTPS ocurre fuera de la transacción SQL. Una recepción `RECIBIDA` conserva estado pendiente de autorización. Los reintentos usan el mismo XML y clave; un timeout no genera otra factura.
5. Solo se registra `autorizada` al recibir una autorización del ambiente esperado, con la misma clave y el mismo XML firmado. Un resultado todavía no disponible se difiere; un rechazo conserva el diagnóstico. El documento no se elimina ni se reescribe.

La migración 037 añade tres tablas (104 en total sobre esta rama) y cuatro protecciones SQL. Su guarda de rollback conserva cualquier emisor, secuencia o documento registrado. El directorio `/gestion/facturacion` separa listado, preparación y ficha, con filtros de semana/mes/fechas y estado, paginación de 25 y descarga privada del XML firmado. La carga del certificado continúa solo por consola privada. El certificado cifrado también debe conservarse con los respaldos, junto con `APP_KEY` por un canal separado.

## Consola del piloto

Ejecutar dentro del contenedor con las migraciones al día. Guardar las configuraciones y el PKCS12 en un volumen privado, fuera de `public` y del repositorio. La contraseña se lee por entrada estándar desde un secreto, nunca como argumento.

```sh
php scripts/fiscal_sri.php perfil /run/secrets/emisor-config.json /run/secrets/emisor.p12 < /run/secrets/emisor-password
php scripts/fiscal_sri.php factura /run/secrets/factura-config.json
php scripts/fiscal_sri.php procesar
```

`emisor-config.json` contiene `actor_id`, `idem_key` (32 caracteres hexadecimales nuevos por operación), `titular_confirmado: "1"` y `emisor` con: `ruc`, `ambiente: "1"`, `establecimiento`, `punto_emision`, `razon_social`, `dir_matriz`, `obligado_contabilidad: "SI"|"NO"`. Nombre comercial, dirección del establecimiento, contribuyente especial, agente de retención y RIMPE son opcionales según el emisor; no se adivinan.

`factura-config.json` contiene `actor_id`, `idem_key`, `perfil_id`, `venta_id`, `comprador` (`tipo`, `identificacion`, `nombre`, `direccion`), `categorias_iva` (objeto de ID de línea a código SRI) y `pagos` (lista de `forma`, `total_centavos` y, si corresponde, `plazo_dias`). Los importes son enteros en centavos. Los precios y descuentos se toman de la venta; el archivo no puede cambiarlos.

La categoría IVA se indica explícitamente: tarifa cero, exento y no objeto son conceptos diferentes. La tabla soportada está en `SriComprobante`; la vigencia aplicable y el régimen del emisor deben configurarse en el circuito definitivo. Los códigos de forma de pago describen el comprobante y no registran otro ingreso de caja.

## Validación y trabajo que continúa

Las pruebas usan certificados RSA sintéticos y un transporte de ensayo inyectado exclusivamente en `APP_ENV=testing`. No envían comprobantes ficticios a producción. Existe una comprobación independiente de firma y referencias con Python/lxml/cryptography durante el desarrollo; los ensayos por SHA se registran en el PR.

El generador también produce notas de crédito XSD 1.1.0, pero falta cerrar su integración con devoluciones, topes y documentos autorizados de sustento. Continúan el XML contenedor de autorización y RIDE, entrega al correo, revisión/corrección de rechazos, consumidor final con sus restricciones, fiscalidad de anticipos/paquetes/suscripciones, configuración del momento de IVA y reglas por régimen, y ensayo real con el certificado del emisor en certificación. Solo después se habilitará producción de forma explícita.

Este avance no sustituye la contabilidad propia de doble partida, cierre tributario ni nómina. Son módulos pendientes con sus propias reglas, autorizaciones y conciliaciones; no se presentan como completados por tener una factura XML o un recibo interno.

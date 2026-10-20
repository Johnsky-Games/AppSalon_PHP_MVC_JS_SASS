# Esquemas oficiales usados por el motor propio

Origen: [SRI, facturación electrónica](https://www.sri.gob.ec/facturacion-electronica), consultado el 19 de octubre de 2026. Ficha técnica offline **2.34**, actualizada a julio de 2026. Los XSD se conservan sin modificar; validar no descarga esquemas remotos.

| Archivo | Descarga oficial | SHA-256 |
| --- | --- | --- |
| factura_V2.1.0.xsd | [XML y XSD Factura](https://www.sri.gob.ec/o/sri-portlet-biblioteca-alfresco-internet/descargar/05546998-6f29-4870-be3b-62650f312a6c/XML%20y%20XSD%20Factura.zip?download=true) | `5f2c37bc1a58bb40e8bbbc366cabe05d5dc199598aeea1561137370f8bd4eace` |
| NotaCredito_V1.1.0.xsd | [XML y XSD Nota de Crédito](https://www.sri.gob.ec/o/sri-portlet-biblioteca-alfresco-internet/descargar/dfc944cd-5f18-4433-a626-3cc64cfc4549/XML%20y%20XSD%20Nota%20de%20Cr%C3%A9dito.zip?download=true) | `379e9f270d71ab4578f130a736c9abe5eb1d7969375ecdef55d2973583ec5ab0` |
| xmldsig-core-schema.xsd | [W3C XML Signature](https://www.w3.org/TR/xmldsig-core/xmldsig-core-schema.xsd) | `d102ad3df7664c307e0c2c776ba4a90513b1969974d8a940bae1a77f9f21e15d` |

El encabezado del esquema W3C contiene su aviso de copyright/licencia. La ficha técnica descargada para revisión tiene SHA-256 `7333aebfbdf2cb3ba83f9fc67a7a7f0346ca59506480a260cc42f96dbdfc13c9`; el PDF no se incluye en el repositorio.

El perfil SRI de firma exige XAdES-BES 1.3.2, RSA-SHA1 y tres referencias firmadas: comprobante, SignedProperties y KeyInfo. SHA-256 se usa además como huella interna de almacenamiento; no sustituye el algoritmo requerido por el receptor. No se fija el certificado TLS del SRI: se verifica contra las autoridades de confianza del sistema.

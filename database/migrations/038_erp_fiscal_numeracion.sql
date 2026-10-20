-- La identidad del número no depende de la versión del certificado/perfil ni solo del contador.
-- Índices funcionales: no añaden columnas a las exportaciones portables de datos.
ALTER TABLE erp_fiscal_documentos
 ADD UNIQUE KEY uq_fiscal_numero_emisor (
  (CAST(JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.emisor.ruc')) AS CHAR(13))),
  (CAST(JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.emisor.ambiente')) AS CHAR(1))),
  numero
 ),
 ADD CONSTRAINT ck_fiscal_snapshot_emisor CHECK (
  JSON_CONTAINS_PATH(snapshot_json,'all','$.emisor.ruc','$.emisor.ambiente')=1
  AND JSON_TYPE(JSON_EXTRACT(snapshot_json,'$.emisor.ruc'))='STRING'
  AND CHAR_LENGTH(JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.emisor.ruc')))=13
  AND JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.emisor.ruc')) REGEXP '^[0-9]{13}$'
  AND JSON_TYPE(JSON_EXTRACT(snapshot_json,'$.emisor.ambiente'))='STRING'
  AND CHAR_LENGTH(JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.emisor.ambiente')))=1
  AND JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.emisor.ambiente'))='1'
 );

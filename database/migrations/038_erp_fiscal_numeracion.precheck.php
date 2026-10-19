<?php
return static function(\mysqli $db):array {
    if(!$db->query("SHOW TABLES LIKE 'erp_fiscal_documentos'")->num_rows)return [];
    $faltan=$db->query("SELECT id FROM erp_fiscal_documentos WHERE JSON_CONTAINS_PATH(snapshot_json,'all','$.emisor.ruc','$.emisor.ambiente')=0 OR JSON_TYPE(JSON_EXTRACT(snapshot_json,'$.emisor.ruc'))<>'STRING' OR CHAR_LENGTH(JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.emisor.ruc')))<>13 OR JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.emisor.ruc')) NOT REGEXP '^[0-9]{13}$' OR JSON_TYPE(JSON_EXTRACT(snapshot_json,'$.emisor.ambiente'))<>'STRING' OR CHAR_LENGTH(JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.emisor.ambiente')))<>1 OR JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.emisor.ambiente'))<>'1' LIMIT 1");
    $duplicados=$db->query("SELECT numero FROM erp_fiscal_documentos GROUP BY JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.emisor.ruc')),JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.emisor.ambiente')),numero HAVING COUNT(*)>1 LIMIT 1");
    return $faltan->num_rows||$duplicados->num_rows?['Revisa identidades y números fiscales existentes antes de activar la unicidad; no se modifica evidencia automáticamente.']:[];
};

<?php
return static function(\mysqli $db):array {
    if(!$db->query("SHOW TABLES LIKE 'erp_fiscal_documentos'")->num_rows)return [];
    return $db->query('SELECT 1 FROM erp_fiscal_documentos LIMIT 1')->num_rows
        ?[['mensaje'=>'Conserve la protección de numeración y la evidencia fiscal.','tabla'=>'erp_fiscal_documentos']]:[];
};

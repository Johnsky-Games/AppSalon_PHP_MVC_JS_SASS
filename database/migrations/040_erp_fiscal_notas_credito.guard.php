<?php
return static function(\mysqli $db):array {
    if(!$db->query("SHOW COLUMNS FROM erp_fiscal_documentos LIKE 'tipo'")->num_rows)return [];
    return $db->query("SELECT 1 FROM erp_fiscal_documentos WHERE tipo<>'01' LIMIT 1")->num_rows
        ?[['mensaje'=>'Existen notas de crédito fiscales; conserva su evidencia antes de revertir 040.','tabla'=>'erp_fiscal_documentos']]:[];
};

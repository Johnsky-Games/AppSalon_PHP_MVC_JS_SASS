<?php
return static function(\mysqli $db):array {
    foreach(['erp_fiscal_documentos','erp_fiscal_perfiles','erp_fiscal_secuencias'] as $tabla){
        if($db->query("SHOW TABLES LIKE '$tabla'")->num_rows&&$db->query("SELECT 1 FROM $tabla LIMIT 1")->num_rows)return [['mensaje'=>'Conserva emisor, secuencias y evidencia fiscal antes de revertir 037.','tabla'=>$tabla]];
    }return [];
};

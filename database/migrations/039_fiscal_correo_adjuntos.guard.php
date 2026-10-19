<?php
return static function(\mysqli $db):array {
    return $db->query("SELECT 1 FROM correos_salida WHERE tipo='comprobante_fiscal' OR OCTET_LENGTH(carga_cifrada)>65535 LIMIT 1")->num_rows
        ?[['mensaje'=>'Conserve las entregas fiscales y sus adjuntos cifrados.','tabla'=>'correos_salida']]:[];
};

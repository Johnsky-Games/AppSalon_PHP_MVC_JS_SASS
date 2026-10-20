<?php
return static function(\mysqli $db):array {
    $r=[];
    foreach(['erp_nomina_ir_tablas'=>'tablas de impuesto a la renta','erp_nomina_ir_declaraciones'=>'declaraciones de impuesto a la renta'] as $tabla=>$texto){
        if($db->query("SHOW TABLES LIKE '$tabla'")->num_rows&&$db->query("SELECT 1 FROM `$tabla` LIMIT 1")->num_rows)$r[]=['mensaje'=>'Existen '.$texto.'; conserva su evidencia antes de revertir 045.','tabla'=>$tabla];
    }
    return $r;
};

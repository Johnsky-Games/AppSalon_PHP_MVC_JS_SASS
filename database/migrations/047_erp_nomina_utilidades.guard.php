<?php
return static function(\mysqli $db):array {
    $r=[];
    foreach(['erp_nomina_utilidades'=>'repartos de utilidades','erp_nomina_cargas'=>'declaraciones de cargas familiares'] as $tabla=>$texto){
        if($db->query("SHOW TABLES LIKE '$tabla'")->num_rows&&$db->query("SELECT 1 FROM `$tabla` LIMIT 1")->num_rows)$r[]=['mensaje'=>'Existen '.$texto.'; conserva su evidencia antes de revertir 047.','tabla'=>$tabla];
    }
    return $r;
};

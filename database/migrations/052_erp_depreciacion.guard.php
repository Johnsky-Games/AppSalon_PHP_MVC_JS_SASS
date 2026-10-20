<?php
return static function(\mysqli $db):array {
    $r=[];
    foreach(['erp_dep_bajas'=>'bajas de activos','erp_dep_corridas'=>'corridas de depreciación','erp_dep_fichas'=>'fichas de depreciación','erp_dep_categorias'=>'políticas de depreciación'] as $t=>$que)
        if($db->query("SHOW TABLES LIKE '$t'")->num_rows&&$db->query("SELECT 1 FROM $t LIMIT 1")->num_rows)$r[]=['mensaje'=>'Existen '.$que.' registradas; conserva su evidencia antes de revertir 052.','tabla'=>$t];
    return $r;
};

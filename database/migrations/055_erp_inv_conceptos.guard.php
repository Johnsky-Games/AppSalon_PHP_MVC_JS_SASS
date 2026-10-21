<?php
return static function(\mysqli $db):array {
    $r=[];
    foreach(['erp_inv_ajuste_conceptos'=>'ajustes de inventario clasificados','erp_inv_conceptos'=>'conceptos de ajuste de inventario'] as $t=>$que)
        if($db->query("SHOW TABLES LIKE '$t'")->num_rows&&$db->query("SELECT 1 FROM $t LIMIT 1")->num_rows)$r[]=['mensaje'=>'Existen '.$que.' registrados; conserva su evidencia antes de revertir 055.','tabla'=>$t];
    return $r;
};

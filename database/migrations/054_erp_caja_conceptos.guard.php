<?php
return static function(\mysqli $db):array {
    $r=[];
    foreach(['erp_caja_mov_conceptos'=>'movimientos de caja clasificados','erp_caja_conceptos'=>'conceptos de caja'] as $t=>$que)
        if($db->query("SHOW TABLES LIKE '$t'")->num_rows&&$db->query("SELECT 1 FROM $t LIMIT 1")->num_rows)$r[]=['mensaje'=>'Existen '.$que.' registrados; conserva su evidencia antes de revertir 054.','tabla'=>$t];
    return $r;
};

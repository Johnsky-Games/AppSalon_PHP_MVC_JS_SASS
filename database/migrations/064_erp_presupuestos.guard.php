<?php
return static function(\mysqli $db):array {
    $r=[];
    foreach(['erp_presupuestos'=>'presupuestos registrados','erp_presupuesto_cambios'=>'cambios de cifras en el historial'] as $t=>$que)
        if($db->query("SHOW TABLES LIKE '$t'")->num_rows&&$db->query("SELECT 1 FROM $t LIMIT 1")->num_rows)$r[]=['mensaje'=>'Existen '.$que.'; conserva esa información antes de revertir 064.','tabla'=>$t];
    return $r;
};

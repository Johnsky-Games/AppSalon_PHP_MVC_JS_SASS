<?php
return static function(\mysqli $db):array {
    $r=[];
    foreach(['erp_tes_extractos'=>'extractos bancarios importados','erp_tes_cuentas'=>'cuentas bancarias registradas'] as $t=>$que)
        if($db->query("SHOW TABLES LIKE '$t'")->num_rows&&$db->query("SELECT 1 FROM $t LIMIT 1")->num_rows)$r[]=['mensaje'=>'Existen '.$que.'; conserva su evidencia antes de revertir 051.','tabla'=>$t];
    return $r;
};

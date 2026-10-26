<?php
// Los conteos físicos y los ajustes que generaron son registro de lo contado: revertir 070 los borraría.
return static function(\mysqli $db):array {
    $r=[];
    foreach(['erp_inv_conteos'=>'conteos físicos de inventario','erp_inv_conteo_ajustes'=>'ajustes generados por conteos'] as $t=>$que)
        if($db->query("SHOW TABLES LIKE '$t'")->num_rows&&$db->query("SELECT 1 FROM $t LIMIT 1")->num_rows)$r[]=['mensaje'=>'Existen '.$que.'; conserva esa información antes de revertir 070.','tabla'=>$t];
    return $r;
};

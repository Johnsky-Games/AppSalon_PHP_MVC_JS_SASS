<?php
return static function(\mysqli $db):array {
    $r=[];
    if($db->query("SHOW TABLES LIKE 'erp_inv_reimputaciones'")->num_rows&&$db->query('SELECT 1 FROM erp_inv_reimputaciones LIMIT 1')->num_rows)
        $r[]=['mensaje'=>'Existen reimputaciones de ajustes de inventario registradas; conserva su evidencia antes de revertir 056.','tabla'=>'erp_inv_reimputaciones'];
    return $r;
};

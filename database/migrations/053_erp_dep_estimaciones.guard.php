<?php
return static function(\mysqli $db):array {
    $r=[];
    if($db->query("SHOW TABLES LIKE 'erp_dep_estimaciones'")->num_rows&&$db->query('SELECT 1 FROM erp_dep_estimaciones LIMIT 1')->num_rows)
        $r[]=['mensaje'=>'Existen cambios de estimación de depreciación registrados; conserva su evidencia antes de revertir 053.','tabla'=>'erp_dep_estimaciones'];
    return $r;
};

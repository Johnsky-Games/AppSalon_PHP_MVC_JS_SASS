<?php
return static function(\mysqli $db):array {
    $r=[];
    if($db->query("SHOW TABLES LIKE 'erp_rh_identificaciones'")->num_rows&&$db->query("SELECT 1 FROM erp_rh_identificaciones LIMIT 1")->num_rows)$r[]=['mensaje'=>'Existen identificaciones de trabajadores registradas; conserva su evidencia antes de revertir 050.','tabla'=>'erp_rh_identificaciones'];
    return $r;
};

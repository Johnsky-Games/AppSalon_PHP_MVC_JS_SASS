<?php
return static function(\mysqli $db):array {
    $r=[];
    if($db->query("SHOW TABLES LIKE 'erp_nomina_finiquitos'")->num_rows&&$db->query('SELECT 1 FROM erp_nomina_finiquitos LIMIT 1')->num_rows)$r[]=['mensaje'=>'Existen finiquitos; conserva su evidencia antes de revertir 046.','tabla'=>'erp_nomina_finiquitos'];
    return $r;
};

<?php
return static function(\mysqli $db):array {
    $r=[];
    if($db->query("SHOW TABLES LIKE 'erp_iess_avisos'")->num_rows&&$db->query("SELECT 1 FROM erp_iess_avisos LIMIT 1")->num_rows)$r[]=['mensaje'=>'Existen avisos al IESS registrados; conserva su evidencia antes de revertir 049.','tabla'=>'erp_iess_avisos'];
    return $r;
};

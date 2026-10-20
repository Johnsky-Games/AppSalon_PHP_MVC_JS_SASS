<?php
return static function(\mysqli $db):array {
    $r=[];
    if($db->query("SHOW COLUMNS FROM erp_ausencias LIKE 'tipo'")->num_rows&&$db->query("SELECT 1 FROM erp_ausencias WHERE tipo<>'permiso' LIMIT 1")->num_rows)$r[]=['mensaje'=>'Existen ausencias clasificadas como vacaciones o licencias; conserva su evidencia antes de revertir 048.','tabla'=>'erp_ausencias'];
    return $r;
};

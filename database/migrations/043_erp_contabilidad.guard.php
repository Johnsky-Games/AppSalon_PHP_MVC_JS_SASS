<?php
return static function(\mysqli $db):array {
    $r=[];
    if($db->query("SHOW TABLES LIKE 'erp_cont_asientos'")->num_rows&&$db->query('SELECT 1 FROM erp_cont_asientos LIMIT 1')->num_rows)$r[]=['mensaje'=>'Existen asientos contables; el libro es de solo anexar y debe conservarse antes de revertir 043.','tabla'=>'erp_cont_asientos'];
    if($db->query("SHOW TABLES LIKE 'erp_cont_cuentas'")->num_rows&&$db->query('SELECT 1 FROM erp_cont_cuentas WHERE creado_por<>0 LIMIT 1')->num_rows)$r[]=['mensaje'=>'Existen cuentas contables creadas por usuarios; conserva el plan de cuentas antes de revertir 043.','tabla'=>'erp_cont_cuentas'];
    return $r;
};

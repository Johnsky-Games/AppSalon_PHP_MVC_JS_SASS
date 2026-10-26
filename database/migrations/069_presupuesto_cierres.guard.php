<?php
// Los cierres mensuales son la referencia oficial de lo informado: revertir 069 los borraría.
return static function(\mysqli $db):array {
    $r=[];
    if($db->query("SHOW TABLES LIKE 'erp_presupuesto_cierres'")->num_rows&&$db->query('SELECT 1 FROM erp_presupuesto_cierres LIMIT 1')->num_rows)
        $r[]=['mensaje'=>'Existen cierres mensuales de presupuestos vinculados a fotos; conserva esa información antes de revertir 069.','tabla'=>'erp_presupuesto_cierres'];
    return $r;
};

<?php
// Las fotos son el registro inmutable de lo que se informó en cada corte: revertir 068 las borraría.
return static function(\mysqli $db):array {
    $r=[];
    if($db->query("SHOW TABLES LIKE 'erp_presupuesto_fotos'")->num_rows&&$db->query('SELECT 1 FROM erp_presupuesto_fotos LIMIT 1')->num_rows)
        $r[]=['mensaje'=>'Existen fotos inmutables del comparativo de presupuestos; conserva esa información antes de revertir 068.','tabla'=>'erp_presupuesto_fotos'];
    return $r;
};

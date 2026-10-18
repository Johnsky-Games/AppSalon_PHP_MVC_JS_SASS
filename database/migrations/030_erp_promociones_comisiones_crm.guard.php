<?php
return static function(\mysqli $db):array {
    $r=[];foreach(['erp_promociones','erp_comision_reglas','erp_comision_movimientos','erp_comision_liquidaciones','erp_oportunidades','erp_interacciones'] as $t){
        if($db->query("SHOW TABLES LIKE '$t'")->num_rows&&(int)$db->query("SELECT COUNT(*) n FROM $t")->fetch_assoc()['n'])$r[]=['mensaje'=>'Hay reglas o registros comerciales de negocio.','tabla'=>$t];
    }return $r;
};

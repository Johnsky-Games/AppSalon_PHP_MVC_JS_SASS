<?php
return static function(\mysqli $db):array {
    $r=[];foreach(['erp_recursos','erp_servicio_recursos','erp_mantenimientos','erp_activos','erp_recetas','erp_consumos','erp_documentos'] as $t){if($db->query("SHOW TABLES LIKE '$t'")->num_rows&&(int)$db->query("SELECT COUNT(*) n FROM $t")->fetch_assoc()['n'])$r[]=['mensaje'=>'Hay recursos o evidencia operativa que conservar.','tabla'=>$t];}return $r;
};

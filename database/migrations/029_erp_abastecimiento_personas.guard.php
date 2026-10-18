<?php
return static function(\mysqli $db):array {
    $r=[];foreach(['erp_compras','erp_proveedor_creditos','erp_proveedor_reembolsos','erp_obligaciones','erp_contratos','erp_asistencia','erp_ausencias','erp_solicitudes','erp_jornadas'] as $t){
        if($db->query("SHOW TABLES LIKE '$t'")->num_rows&&(int)$db->query("SELECT COUNT(*) n FROM $t")->fetch_assoc()['n'])$r[]=['mensaje'=>'Hay documentos operativos que requieren conservación.','tabla'=>$t];
    }return $r;
};

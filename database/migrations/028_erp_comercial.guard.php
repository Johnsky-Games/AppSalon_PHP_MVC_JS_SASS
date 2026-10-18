<?php
return static function(\mysqli $db):array {
    $avisos=[];
    foreach(['erp_contactos','erp_catalogo','erp_caja_sesiones','erp_ventas','erp_eventos','erp_integraciones_outbox'] as $tabla){
        if($db->query("SHOW TABLES LIKE '$tabla'")->num_rows && (int)$db->query("SELECT COUNT(*) n FROM $tabla")->fetch_assoc()['n']>0){
            $avisos[]=['mensaje'=>'La extensión comercial contiene datos de negocio.','tabla'=>$tabla];
        }
    }
    return $avisos;
};

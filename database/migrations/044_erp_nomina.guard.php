<?php
return static function(\mysqli $db):array {
    $r=[];
    foreach(['erp_nomina_corridas'=>'corridas de nómina','erp_nomina_novedades'=>'novedades de nómina','erp_nomina_parametros'=>'parámetros legales de nómina','erp_nomina_condiciones'=>'condiciones de nómina por colaborador','erp_nomina_beneficios'=>'liquidaciones de beneficios'] as $tabla=>$texto){
        if($db->query("SHOW TABLES LIKE '$tabla'")->num_rows&&$db->query("SELECT 1 FROM `$tabla` LIMIT 1")->num_rows)$r[]=['mensaje'=>'Existen '.$texto.'; conserva su evidencia antes de revertir 044.','tabla'=>$tabla];
    }
    return $r;
};

<?php
return static function(\mysqli $db):array {
    $r=[];
    if($db->query("SHOW TABLES LIKE 'erp_fiscal_parametros'")->num_rows&&$db->query('SELECT 1 FROM erp_fiscal_parametros LIMIT 1')->num_rows)$r[]=['mensaje'=>'Existen parámetros fiscales registrados; conserva su historial antes de revertir 042.','tabla'=>'erp_fiscal_parametros'];
    if($db->query("SELECT 1 FROM erp_fiscal_documentos WHERE estado='bloqueada' LIMIT 1")->num_rows)$r[]=['mensaje'=>'Existen documentos fiscales bloqueados por conflicto de estado; conserva su evidencia antes de revertir 042.','tabla'=>'erp_fiscal_documentos'];
    return $r;
};

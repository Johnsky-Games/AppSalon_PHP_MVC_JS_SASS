<?php
return static function(\mysqli $db):array {
    $r=[];
    if($db->query("SHOW COLUMNS FROM erp_fiscal_documentos LIKE 'sustituye_a_id'")->num_rows&&$db->query('SELECT 1 FROM erp_fiscal_documentos WHERE sustituye_a_id IS NOT NULL LIMIT 1')->num_rows)$r[]=['mensaje'=>'Existen documentos fiscales sustitutos; conserva su trazabilidad antes de revertir 041.','tabla'=>'erp_fiscal_documentos'];
    if($db->query("SHOW TABLES LIKE 'erp_fiscal_verificaciones'")->num_rows&&$db->query('SELECT 1 FROM erp_fiscal_verificaciones LIMIT 1')->num_rows)$r[]=['mensaje'=>'Existen verificaciones fiscales con el SRI; conserva su evidencia antes de revertir 041.','tabla'=>'erp_fiscal_verificaciones'];
    return $r;
};

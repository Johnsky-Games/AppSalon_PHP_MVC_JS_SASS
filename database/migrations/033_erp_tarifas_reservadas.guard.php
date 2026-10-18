<?php
return static function(\mysqli $db):array{
    if(!$db->query("SHOW TABLES LIKE 'erp_cita_tarifas'")->num_rows)return [];
    return (int)$db->query('SELECT COUNT(*) n FROM erp_cita_tarifas')->fetch_assoc()['n']?[['mensaje'=>'Conserve los precios e impuestos pactados al reservar.','tabla'=>'erp_cita_tarifas']]:[];
};

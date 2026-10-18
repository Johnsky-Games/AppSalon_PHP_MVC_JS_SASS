<?php
return static function(\mysqli $db):array{
    if(!$db->query("SHOW TABLES LIKE 'erp_cotizaciones'")->num_rows)return [];
    return (int)$db->query('SELECT COUNT(*) n FROM erp_cotizaciones')->fetch_assoc()['n']?[['mensaje'=>'Conserve las cotizaciones y sus condiciones aceptadas.','tabla'=>'erp_cotizaciones']]:[];
};

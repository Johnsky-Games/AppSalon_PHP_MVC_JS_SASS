<?php
return static function(\mysqli $db):array {
    if(!$db->query("SHOW TABLES LIKE 'erp_paquetes'")->num_rows)return [];
    return (int)$db->query('SELECT COUNT(*) n FROM erp_paquetes')->fetch_assoc()['n']
        ?[['mensaje'=>'Conserve los paquetes, su financiación y las sesiones asignadas antes de revertir.','tabla'=>'erp_paquetes']]:[];
};

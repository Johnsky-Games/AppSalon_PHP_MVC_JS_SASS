<?php
return static function(\mysqli $db):array {
    if(!$db->query("SHOW TABLES LIKE 'erp_prepagos'")->num_rows)return [];
    return (int)$db->query('SELECT COUNT(*) n FROM erp_prepagos')->fetch_assoc()['n']
        ?[['mensaje'=>'Conserve beneficiarios, saldos, cargas y aplicaciones antes de revertir.','tabla'=>'erp_prepagos']]:[];
};

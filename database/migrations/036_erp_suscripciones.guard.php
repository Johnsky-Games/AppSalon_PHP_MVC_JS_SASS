<?php
return static function(\mysqli $db):array {
    if(!$db->query("SHOW TABLES LIKE 'erp_suscripciones'")->num_rows)return [];
    return (int)$db->query('SELECT COUNT(*) n FROM erp_suscripciones')->fetch_assoc()['n']
        ?[['mensaje'=>'Conserve contratos de suscripción, ciclos y financiación antes de revertir.','tabla'=>'erp_suscripciones']]:[];
};

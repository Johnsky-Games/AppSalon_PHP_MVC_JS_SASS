<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require __DIR__.'/../includes/app.php';
try {
    $r=new \Repositories\ErpRepository();
    // El supervisor también corre durante la instalación inicial; no crea ni migra el esquema.
    if(!$r->q("SHOW TABLES LIKE 'erp_comision_movimientos'"))exit(0);
    $atenciones=(new \Services\ErpComisionService($r))->procesarAtenciones();
    $suscripciones=(new \Services\ErpSuscripcionService($r))->procesar();
    // Registro explícito de adaptadores. Se amplía al elegir los proveedores reales; ninguno se simula en producción.
    $adaptadores=[];
    $integraciones=(new \Services\ErpIntegracionService($r))->procesar($adaptadores);
    echo json_encode(['atenciones_conciliadas'=>$atenciones,'suscripciones'=>$suscripciones,'integraciones'=>$integraciones],JSON_UNESCAPED_UNICODE).PHP_EOL;
}catch(\Throwable $e){fwrite(STDERR,'Procesador ERP no disponible: '.get_class($e).' código '.$e->getCode().PHP_EOL);exit(1);}

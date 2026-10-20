<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require __DIR__.'/../includes/app.php';
try {
    $r=new \Repositories\ErpRepository();
    // El supervisor también corre durante la instalación inicial; no crea ni migra el esquema.
    if(!$r->q("SHOW TABLES LIKE 'erp_comision_movimientos'"))exit(0);
    $atenciones=(new \Services\ErpComisionService($r))->procesarAtenciones();
    $suscripciones=(new \Services\ErpSuscripcionService($r))->procesar();
    $fiscal=$r->q("SHOW TABLES LIKE 'erp_fiscal_documentos'")?(new \Services\ErpFiscalService($r))->procesar():[];
    // Adaptadores externos opcionales; la facturación propia al SRI tiene su cola y evidencias nativas.
    $adaptadores=[];
    $integraciones=(new \Services\ErpIntegracionService($r))->procesar($adaptadores);
    echo json_encode(['atenciones_conciliadas'=>$atenciones,'suscripciones'=>$suscripciones,'fiscal_sri'=>$fiscal,'integraciones'=>$integraciones],JSON_UNESCAPED_UNICODE).PHP_EOL;
}catch(\Throwable $e){fwrite(STDERR,'Procesador ERP no disponible: '.get_class($e).' código '.$e->getCode().PHP_EOL);exit(1);}

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
    $adaptadores=[];$contabilidad=[];
    // Contabilidad propia: solo después de definir el inicio contable; antes, los hechos quedan en la cola sin configurar y no se pierde ninguno.
    if($r->q("SHOW TABLES LIKE 'erp_cont_config'")&&($r->uno('SELECT inicio_contable FROM erp_cont_config WHERE id=1')['inicio_contable']??null)){
        $adaptador=new \Services\Contabilidad\ErpContabilidadAdaptador($r);$adaptadores['contabilidad']=$adaptador;$contabilidad['prestaciones']=$adaptador->prestaciones();
    }
    $integraciones=(new \Services\ErpIntegracionService($r))->procesar($adaptadores);
    echo json_encode(['atenciones_conciliadas'=>$atenciones,'suscripciones'=>$suscripciones,'fiscal_sri'=>$fiscal,'integraciones'=>$integraciones]+($contabilidad?['contabilidad'=>$contabilidad]:[]),JSON_UNESCAPED_UNICODE).PHP_EOL;
}catch(\Throwable $e){fwrite(STDERR,'Procesador ERP no disponible: '.get_class($e).' código '.$e->getCode().PHP_EOL);exit(1);}

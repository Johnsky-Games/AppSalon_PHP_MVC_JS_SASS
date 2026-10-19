<?php
require __DIR__.'/../bootstrap.php';
[$script,$accion,$actor,$json]=array_pad($argv,4,'');$datos=json_decode($json,true);
$clase=match($accion){'aplicar','cargar'=>\Services\ErpPrepagoService::class,'paquete'=>\Services\ErpPaqueteService::class,'correo_fiscal'=>\Services\ErpFiscalCorreoService::class,'factura','nota_credito'=>\Services\ErpFiscalService::class,'cobrar'=>\Services\ErpPagoService::class,default=>null};
if(!$clase||!is_array($datos)||(int)$actor<=0)exit(2);
validar_base_datos_prueba(\Model\ActiveRecord::getDB());echo "READY\n";flush();if(trim((string)fgets(STDIN))!=='GO')exit(3);
try {$metodo=match($accion){'paquete'=>'aplicar','correo_fiscal'=>'programar','nota_credito'=>'notaCredito',default=>$accion};$r=(new $clase())->$metodo((int)$actor,$datos);echo json_encode(['ok'=>true]+$r)."\n";}
catch(\Services\ErpException $e){echo json_encode(['ok'=>false,'http'=>$e->getCode(),'mensaje'=>$e->getMessage()])."\n";}
catch(\Throwable $e){fwrite(STDERR,get_class($e).'['.$e->getCode().']: '.$e->getMessage().($e->getPrevious()?' << '.get_class($e->getPrevious()).' '.$e->getPrevious()->getCode().' '.$e->getPrevious()->getMessage():''));exit(1);}

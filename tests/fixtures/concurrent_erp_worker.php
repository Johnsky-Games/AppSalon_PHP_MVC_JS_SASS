<?php
require __DIR__.'/../bootstrap.php';
[$script,$accion,$actor,$json]=array_pad($argv,4,'');$datos=json_decode($json,true);
$clase=match($accion){'confirmar'=>\Services\ErpVentaService::class,'cobrar'=>\Services\ErpPagoService::class,'aplicar'=>\Services\ErpPromocionService::class,default=>null};
if(!$clase||!is_array($datos)||(int)$actor<=0)exit(2);
validar_base_datos_prueba(\Model\ActiveRecord::getDB());echo "READY\n";flush();if(trim((string)fgets(STDIN))!=='GO')exit(3);
try{echo json_encode(['ok'=>true]+(new $clase())->$accion((int)$actor,$datos))."\n";}
catch(\Services\ErpException $e){echo json_encode(['ok'=>false,'http'=>$e->getCode(),'mensaje'=>$e->getMessage()])."\n";}
catch(\Throwable $e){fwrite(STDERR,get_class($e).': '.$e->getMessage());exit(1);}

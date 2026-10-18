<?php
require __DIR__.'/../bootstrap.php';
[$script,$accion,$actor,$json]=array_pad($argv,4,'');$datos=json_decode($json,true);
if(!in_array($accion,['registrar','movimiento','cerrar'],true)||!is_array($datos)||(int)$actor<=0)exit(2);
validar_base_datos_prueba(\Model\ActiveRecord::getDB());
echo "READY\n";flush();if(trim((string)fgets(STDIN))!=='GO')exit(3);
try {
    $s=$accion==='registrar'?new \Services\FinanzasService():new \Services\ErpCajaService();
    $r=$s->$accion((int)$actor,$datos);echo json_encode(['ok'=>true]+$r)."\n";
} catch(\InvalidArgumentException|\Services\ErpException $e) {
    echo json_encode(['ok'=>false,'mensaje'=>$e->getMessage()])."\n";
} catch(\Throwable $e) {fwrite(STDERR,get_class($e).': '.$e->getMessage());exit(1);}

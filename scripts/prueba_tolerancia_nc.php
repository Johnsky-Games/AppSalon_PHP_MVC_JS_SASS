<?php
/**
 * Prueba controlada de la tolerancia de notas de crédito en el AMBIENTE DE PRUEBAS del SRI (este piloto no emite en otro).
 * Emite UNA nota con desviación de 1 centavo marcada como prueba; no registra ni cambia la tolerancia. El worker fiscal la transmite como a cualquier otra;
 * el resultado se lee con `php scripts/auditar_notas_credito.php --evidencia-tolerancia`.
 * Uso: php scripts/prueba_tolerancia_nc.php --confirmo-ambiente-pruebas --actor=ID --factura=ID --devoluciones=ID[,ID...]
 *   --factura: id de una factura AUTORIZADA; --devoluciones: devoluciones pendientes de esa factura cuyo reparto desvíe 1 centavo (las que el modo estricto rechaza).
 */
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require __DIR__.'/../includes/app.php';
$o=[];foreach(array_slice($argv,1) as $a){if(preg_match('/^--([a-z-]+)(?:=(.*))?$/D',$a,$m))$o[$m[1]]=$m[2]??'1';}
if(!isset($o['confirmo-ambiente-pruebas'])||!ctype_digit($o['actor']??'')||!ctype_digit($o['factura']??'')||!preg_match('/^\d+(,\d+)*$/D',$o['devoluciones']??'')){
    fwrite(STDERR,"Uso: php scripts/prueba_tolerancia_nc.php --confirmo-ambiente-pruebas --actor=ID --factura=ID --devoluciones=ID[,ID...]\n");exit(1);
}
try{
    $r=new \Repositories\ErpRepository();
    $res=(new \Services\ErpFiscalService($r))->notaCreditoPruebaTolerancia((int)$o['actor'],['factura_id'=>(int)$o['factura'],'devolucion_ids'=>array_map('intval',explode(',',$o['devoluciones'])),'motivo'=>'Prueba controlada de tolerancia (ambiente de pruebas)','idem_key'=>bin2hex(random_bytes(16))]);
    $doc=$r->uno('SELECT id,numero,clave_acceso,estado FROM erp_fiscal_documentos WHERE id=?',[(int)$res['recurso_id']]);
    echo "Nota de prueba creada: documento {$doc['id']} {$doc['numero']} clave {$doc['clave_acceso']} estado {$doc['estado']}".PHP_EOL;
    echo 'Siguiente: el worker fiscal la transmite al ambiente de pruebas. Cuando el SRI responda: php scripts/auditar_notas_credito.php --evidencia-tolerancia'.PHP_EOL;
    exit(0);
}catch(\Throwable $e){fwrite(STDERR,'No se emitió la nota de prueba: '.($e instanceof \Services\ErpException?$e->getMessage():get_class($e)).PHP_EOL);exit(1);}

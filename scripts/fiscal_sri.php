<?php
/** Consola del piloto SRI; certificados y contraseñas nunca viajan como argumentos o salida. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../includes/app.php';
try{
    $accion=$argv[1]??'';$archivo=$argv[2]??'';$servicio=new \Services\ErpFiscalService();
    if($accion==='procesar'){$resultado=$servicio->procesar();}
    elseif(in_array($accion,['perfil','factura'],true)){
        if(!is_file($archivo)||filesize($archivo)>65536)throw new \RuntimeException('Archivo de configuración no disponible.');
        $d=json_decode(file_get_contents($archivo),true,32,JSON_THROW_ON_ERROR);$actor=filter_var($d['actor_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$actor)throw new \RuntimeException('Indica la cuenta autorizada.');unset($d['actor_id']);
        if($accion==='perfil'){
            $ruta=$argv[3]??'';$real=realpath($ruta);$publico=realpath(__DIR__.'/../public');
            if(!$real||!is_file($real)||filesize($real)>1048576||str_starts_with($real,$publico.DIRECTORY_SEPARATOR))throw new \RuntimeException('Coloca el PKCS12 en una ruta privada, fuera de public.');
            if(stream_isatty(STDIN))throw new \RuntimeException('Entrega la contraseña por entrada estándar desde un secreto, sin escribirla en argumentos.');
            $password=stream_get_contents(STDIN,4097);if($password===false||strlen($password)>4096)throw new \RuntimeException('Contraseña no disponible.');$password=rtrim($password,"\r\n");
            $resultado=$servicio->perfil($actor,$d,file_get_contents($real),$password);
        }else $resultado=$servicio->factura($actor,$d);
    }else throw new \RuntimeException('Uso: fiscal_sri.php perfil CONFIG_JSON PKCS12 < SECRETO | factura CONFIG_JSON | procesar');
    echo json_encode($resultado,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE).PHP_EOL;
}catch(\Throwable $e){fwrite(STDERR,($e instanceof \Services\ErpException||$e instanceof \InvalidArgumentException||get_class($e)===\RuntimeException::class?$e->getMessage():'No se pudo completar la operación fiscal. Revisa configuración y migraciones.').PHP_EOL);exit(1);}

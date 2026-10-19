<?php
namespace Services\Fiscal;

final class SriTransporteHttps implements \Interfaces\SriTransporteInterface
{
    public function post(string $url,string $xml):string
    {
        if(!preg_match('~^https://cel(cer)?\.sri\.gob\.ec/comprobantes-electronicos-ws/(Recepcion|Autorizacion)ComprobantesOffline$~D',$url))throw new \InvalidArgumentException('Destino SRI no permitido.');
        $contexto=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: text/xml; charset=UTF-8\r\nSOAPAction: \"\"\r\nConnection: close\r\n",'content'=>$xml,'timeout'=>20,'follow_location'=>0,'max_redirects'=>0,'ignore_errors'=>true],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false,'crypto_method'=>STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT|STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT]]);
        $stream=@fopen($url,'rb',false,$contexto);if(!$stream)throw new \RuntimeException('No se pudo contactar al SRI. Consulta la misma clave antes de reenviar.');
        try{
            $body=stream_get_contents($stream,5*1024*1024+1);$meta=stream_get_meta_data($stream);$headers=$meta['wrapper_data']??[];$status=$headers[0]??'';
            if($body===false||strlen($body)>5*1024*1024||($meta['timed_out']??false)||!preg_match('~^HTTP/\S+ 200(?: |$)~',$status))throw new \RuntimeException('Respuesta SRI incompleta o no disponible. Consulta la misma clave antes de reenviar.');
            return $body;
        }finally{fclose($stream);}
    }
}

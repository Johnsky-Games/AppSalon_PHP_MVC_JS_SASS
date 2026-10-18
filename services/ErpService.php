<?php
namespace Services;

use Classes\Idempotencia;
use Repositories\ErpRepository;

/** Contratos comunes: autorización vigente, idempotencia, eventos y cola persistente en la misma transacción. */
abstract class ErpService
{
    public function __construct(protected ErpRepository $r=new ErpRepository()){}
    protected function permitir(int $actor,string $permiso):void
    {
        if(!(new AuthorizationService($this->r->db))->permite($actor,$permiso))throw new ErpException('No tienes permiso para esta operación.',403);
    }
    protected function ejecutar(int $actor,string $permiso,string $accion,array $d,callable $trabajo):array
    {
        $this->permitir($actor,$permiso);
        $clave=$d['idem_key']??null;
        if(!Idempotencia::claveValida($clave))throw new ErpException('Actualiza el formulario e inténtalo otra vez.');
        unset($d['csrf_token'],$d['idem_key']);
        return Idempotencia::ejecutar($this->r->db,'erp.'.$accion,'usuario:'.$actor,$clave,$d,function()use($actor,$permiso,$trabajo){
            $this->permitir($actor,$permiso);
            $id=(int)$trabajo();
            return ['recurso_tipo'=>'erp','recurso_id'=>$id,'datos'=>[]];
        });
    }
    protected function evento(int $actor,string $tipo,string $entidad,int $id,array $datos,array $destinos=[]):int
    {
        $json=json_encode($datos,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $this->r->q('INSERT INTO erp_eventos(tipo,entidad,entidad_id,actor_id,datos_json,creado_en) VALUES(?,?,?,?,?,?)',[$tipo,$entidad,$id,$actor,$json,ErpRepository::ahora()]);
        $evento=$this->r->id();
        $payload=json_encode(['esquema'=>1,'evento_id'=>$evento,'tipo'=>$tipo,'entidad'=>$entidad,'entidad_id'=>$id,'creado_en'=>ErpRepository::ahora(),'datos'=>$datos],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        foreach($destinos as $destino){
            $this->r->q('INSERT INTO erp_integraciones_outbox(destino,evento_id,referencia,payload_json,actualizado_en) VALUES(?,?,?,?,?)',[$destino,$evento,'ERP-'.$evento.'-'.$destino,$payload,ErpRepository::ahora()]);
        }
        return $evento;
    }
    protected function id(mixed $v):int
    {
        if((!is_int($v)&&!is_string($v))||!preg_match('/^[1-9]\d{0,9}$/D',(string)$v))throw new ErpException('Identificador no válido.');
        return (int)$v;
    }
    protected function texto(mixed $v,int $max=180):string
    {
        $s=ValoresExactos::texto($v,$max);
        if(!mb_check_encoding($s,'UTF-8')||preg_match('/[\x00-\x1f\x7f]/',$s))throw new ErpException('El texto contiene caracteres no permitidos.');
        return $s;
    }
    protected function importe(mixed $v,bool $cero=false):int
    {
        $n=ValoresExactos::decimal($v,2,$cero);if($n>99999999)throw new ErpException('Importe fuera de rango.');return $n;
    }
    protected function fecha(mixed $v):string
    {
        $d=is_string($v)?\DateTimeImmutable::createFromFormat('!Y-m-d',$v,new \DateTimeZone('America/Guayaquil')):false;
        if(!$d||$d->format('Y-m-d')!==$v)throw new ErpException('Fecha no válida.');return $v;
    }
}

<?php
namespace Services;

use Services\Nomina\IdentificacionTributaria;

/**
 * Identificación tributaria del trabajador (cédula o pasaporte), necesaria para el formulario 107 y el anexo RDEP. Solo de anexar: la última declaración rige y las
 * anteriores se conservan. Dato personal: se declara con `erp.rh`, se auditan los cambios (sin copiar el número al evento) y las pantallas lo muestran enmascarado.
 */
final class ErpIdentificacionService extends ErpService
{
    public function declarar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.rh','rh_identificacion',$d,function()use($actor,$d){
            $c=$this->r->uno('SELECT id FROM colaboradores WHERE id=? FOR UPDATE',[$this->id($d['colaborador_id']??null)])??throw new ErpException('Colaborador no encontrado.',404);
            $tipo=(string)($d['tipo']??'');$numero=IdentificacionTributaria::normalizar($tipo,(string)($d['numero']??''));
            if($msg=IdentificacionTributaria::error($tipo,$numero))throw new ErpException($msg);
            $nota=($d['nota']??'')===''?null:$this->texto($d['nota'],200);
            $clave=hash('sha256','rh.identificacion:'.$actor.':'.$d['idem_key']);
            $previo=$this->r->uno('SELECT id FROM erp_rh_identificaciones WHERE clave_operacion=? FOR SHARE',[$clave]);if($previo)return (int)$previo['id'];
            // Un mismo documento no puede estar vigente en dos trabajadores distintos.
            foreach($this->r->q('SELECT i.colaborador_id FROM erp_rh_identificaciones i WHERE i.tipo=? AND i.numero=? AND i.colaborador_id<>? AND i.id=(SELECT MAX(x.id) FROM erp_rh_identificaciones x WHERE x.colaborador_id=i.colaborador_id) FOR SHARE',[$tipo,$numero,(int)$c['id']]) as $_)
                throw new ErpException('Ese documento ya es la identificación vigente de otro trabajador.',409);
            $vigente=$this->vigente((int)$c['id']);
            if($vigente&&$vigente['tipo']===$tipo&&$vigente['numero']===$numero)throw new ErpException('Esa ya es la identificación vigente del trabajador.',409);
            $this->r->q('INSERT INTO erp_rh_identificaciones(clave_operacion,colaborador_id,tipo,numero,nota,registrado_por,registrado_en) VALUES(?,?,?,?,?,?,?)',[$clave,(int)$c['id'],$tipo,$numero,$nota,$actor,$this->r::ahora()]);
            $id=$this->r->id();$this->evento($actor,'rh_identificacion_declarada','colaborador',(int)$c['id'],['identificacion_id'=>$id,'tipo'=>$tipo,'cambio'=>$vigente!==null]);return $id;
        });
    }

    /** @return ?array{id:int,tipo:string,numero:string,registrado_en:string} */
    public function vigente(int $colaborador):?array
    {
        $f=$this->r->uno('SELECT id,tipo,numero,registrado_en FROM erp_rh_identificaciones WHERE colaborador_id=? ORDER BY id DESC LIMIT 1',[$colaborador]);
        return $f?['id'=>(int)$f['id'],'tipo'=>$f['tipo'],'numero'=>$f['numero'],'registrado_en'=>$f['registrado_en']]:null;
    }

    /** Identificaciones vigentes de todos los trabajadores, por id de colaborador. @return array<int,array{tipo:string,numero:string}> */
    public function vigentes():array
    {
        $r=[];foreach($this->r->q('SELECT i.colaborador_id,i.tipo,i.numero FROM erp_rh_identificaciones i WHERE i.id=(SELECT MAX(x.id) FROM erp_rh_identificaciones x WHERE x.colaborador_id=i.colaborador_id)') as $f)$r[(int)$f['colaborador_id']]=['tipo'=>$f['tipo'],'numero'=>$f['numero']];
        return $r;
    }
}

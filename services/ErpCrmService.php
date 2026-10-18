<?php
namespace Services;
final class ErpCrmService extends ErpService
{
    public function oportunidad(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.ventas.crear','crm_oportunidad',$d,function()use($actor,$d){
            $contacto=$this->id($d['contacto_id']??null);$this->contacto($contacto);$titulo=$this->texto($d['titulo']??'',180);$origen=$this->texto($d['origen']??'',80);$fecha=$this->fecha($d['proximo_contacto']??null);
            $this->r->q('INSERT INTO erp_oportunidades(contacto_id,titulo,responsable_id,proximo_contacto,origen,creado_por,creado_en) VALUES(?,?,?,?,?,?,?)',[$contacto,$titulo,$actor,$fecha,$origen,$actor,$this->r::ahora()]);
            $id=$this->r->id();$this->evento($actor,'oportunidad_creada','contacto',$contacto,['oportunidad_id'=>$id]);return $contacto;
        });
    }
    public function etapa(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.ventas.crear','crm_etapa',$d,function()use($actor,$d){
            $id=$this->id($d['oportunidad_id']??null);$o=$this->r->uno('SELECT * FROM erp_oportunidades WHERE id=? FOR UPDATE',[$id])??throw new ErpException('Oportunidad no encontrada.');
            $etapa=$d['etapa']??'';if(!in_array($etapa,['nuevo','contactado','cotizacion','reserva','ganado','perdido'],true))throw new ErpException('Etapa no válida.');
            $fecha=$this->fecha($d['proximo_contacto']??null);$nota=$this->texto($d['nota']??'',300);
            $this->r->q('UPDATE erp_oportunidades SET etapa=?,proximo_contacto=? WHERE id=?',[$etapa,$fecha,$id]);$this->evento($actor,'oportunidad_actualizada','contacto',(int)$o['contacto_id'],['oportunidad_id'=>$id,'antes'=>$o['etapa'],'despues'=>$etapa,'nota'=>$nota]);return (int)$o['contacto_id'];
        });
    }
    public function interaccion(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.ventas.crear','crm_interaccion',$d,function()use($actor,$d){
            $c=$this->id($d['contacto_id']??null);$this->contacto($c);$tipo=$d['tipo']??'';
            if(!in_array($tipo,['nota','llamada','reclamo','seguimiento'],true))throw new ErpException('Tipo de interacción no válido.');$texto=$this->texto($d['texto']??'',1000);$fecha=$this->fecha($d['vence_en']??null);
            $this->r->q('INSERT INTO erp_interacciones(contacto_id,tipo,texto,responsable_id,vence_en,creado_por,creado_en) VALUES(?,?,?,?,?,?,?)',[$c,$tipo,$texto,$actor,$fecha,$actor,$this->r::ahora()]);$id=$this->r->id();
            $this->evento($actor,'interaccion_registrada','contacto',$c,['interaccion_id'=>$id,'tipo'=>$tipo]);return $c;
        });
    }
    public function resolver(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.ventas.crear','crm_resolver',$d,function()use($actor,$d){
            $id=$this->id($d['interaccion_id']??null);$i=$this->r->uno('SELECT * FROM erp_interacciones WHERE id=? FOR UPDATE',[$id])??throw new ErpException('Interacción no encontrada.');
            if($i['estado']==='resuelta')throw new ErpException('La interacción ya fue resuelta.',409);$nota=$this->texto($d['resolucion']??'',1000);
            $this->r->q("UPDATE erp_interacciones SET estado='resuelta',resolucion=?,resuelto_en=? WHERE id=?",[$nota,$this->r::ahora(),$id]);$this->evento($actor,'interaccion_resuelta','contacto',(int)$i['contacto_id'],['interaccion_id'=>$id,'resolucion'=>$nota]);return (int)$i['contacto_id'];
        });
    }
    private function contacto(int $id):void {if(!$this->r->uno("SELECT id FROM erp_contactos WHERE id=? AND activo=1 AND tipo IN ('cliente','ambos')",[$id]))throw new ErpException('Contacto no disponible.');}
}

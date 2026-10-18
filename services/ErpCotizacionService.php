<?php
namespace Services;
/** Cotización versionada: no cobra ni reserva stock hasta su aceptación. */
final class ErpCotizacionService extends ErpService
{
    public function emitir(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.ventas.crear','cotizacion_emitir',$d,function()use($actor,$d){
            $id=$this->id($d['venta_id']??null);$svc=new ErpVentaService($this->r);$v=$svc->bloquear($id);if($v['estado']!=='borrador')throw new ErpException('Cotiza antes de confirmar la venta.');
            $vence=$this->fecha($d['vence_en']??null);if($vence<substr($this->r::ahora(),0,10))throw new ErpException('La vigencia debe ser actual o futura.');$condiciones=$this->texto($d['condiciones']??'',1000);
            $detalle=$svc->detalle($actor,$id);if(!$detalle['lineas'])throw new ErpException('Añade conceptos antes de cotizar.');unset($detalle['eventos'],$detalle['pagos']);$detalle['condiciones']=$condiciones;$detalle['vence_en']=$vence;
            $detalle['empresa']=$this->r->uno('SELECT nombre,identificacion,direccion FROM erp_empresa WHERE id=1');$json=json_encode($detalle,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $this->r->q("UPDATE erp_cotizaciones SET estado='reemplazada' WHERE venta_id=? AND estado='vigente'",[$id]);
            $this->r->q('INSERT INTO erp_cotizaciones(venta_id,version_venta,vence_en,contenido_json,contenido_sha256,creado_por,creado_en) VALUES(?,?,?,?,?,?,?)',[$id,(int)$v['version'],$vence,$json,hash('sha256',$json),$actor,$this->r::ahora()]);$quote=$this->r->id();$this->evento($actor,'cotizacion_emitida','venta',$id,['cotizacion_id'=>$quote,'version'=>$v['version'],'vence_en'=>$vence]);return $id;
        });
    }
    public function aceptar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.ventas.crear','cotizacion_aceptar',$d,function()use($actor,$d){
            $id=$this->id($d['venta_id']??null);$svc=new ErpVentaService($this->r);$v=$svc->bloquear($id);$q=$this->r->uno('SELECT * FROM erp_cotizaciones WHERE id=? AND venta_id=? FOR UPDATE',[$this->id($d['cotizacion_id']??null),$id])??throw new ErpException('Cotización no encontrada.');
            if($q['estado']==='aceptada')return $id;
            if($q['estado']!=='vigente'||$q['vence_en']<substr($this->r::ahora(),0,10)||(int)$q['version_venta']!==(int)$v['version']||!hash_equals($q['contenido_sha256'],hash('sha256',$q['contenido_json'])))throw new ErpException('La cotización venció o sus conceptos cambiaron. Emite una versión nueva.',409);
            if($this->r->uno("SELECT venta_id FROM erp_promocion_usos WHERE venta_id=? AND estado='reservado'",[$id]))throw new ErpException('Retira el cupón y cotiza de nuevo: su vigencia y cupo no se garantizan durante una cotización.');
            $referencia=$this->texto($d['referencia_aceptacion']??'',300);
            $svc->confirmar($actor,['venta_id'=>$id,'version'=>(int)$q['version_venta'],'idem_key'=>hash('sha256','cotizacion:'.$q['id'].':'.$d['idem_key'])]);
            $this->r->q("UPDATE erp_cotizaciones SET estado='aceptada',aceptado_en=? WHERE id=?",[$this->r::ahora(),(int)$q['id']]);$this->evento($actor,'cotizacion_aceptada','venta',$id,['cotizacion_id'=>$q['id'],'referencia'=>$referencia]);return $id;
        });
    }
    public function obtener(int $actor,int $id):array
    {
        $this->permitir($actor,'erp.ventas.ver');$q=$this->r->uno('SELECT * FROM erp_cotizaciones WHERE id=?',[$id])??throw new ErpException('Cotización no encontrada.',404);
        if(!hash_equals($q['contenido_sha256'],hash('sha256',$q['contenido_json'])))throw new ErpException('No se pudo verificar la cotización.',409);$q['contenido']=json_decode($q['contenido_json'],true,512,JSON_THROW_ON_ERROR);return $q;
    }
}

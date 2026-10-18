<?php
namespace Services;

final class ErpCajaService extends ErpService
{
    public function abrir(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.caja','caja_abrir',$d,function()use($actor,$d){
            $fondo=$this->importe($d['fondo']??null,true);
            if($this->r->uno("SELECT id FROM erp_caja_sesiones WHERE actor_id=? AND estado='abierta' FOR UPDATE",[$actor]))throw new ErpException('Ya tienes una caja abierta.');
            $this->r->q('INSERT INTO erp_caja_sesiones(actor_id,fondo_centavos,apertura_en) VALUES(?,?,?)',[$actor,$fondo,$this->r::ahora()]);
            $id=$this->r->id();$this->evento($actor,'caja_abierta','caja',$id,['fondo'=>$fondo]);return $id;
        });
    }
    public function cerrar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.caja','caja_cerrar',$d,function()use($actor,$d){
            $s=$this->sesion($actor);$contado=$this->importe($d['contado']??null,true);$esperado=$this->esperado((int)$s['id']);
            $motivo=$this->texto($d['motivo']??'Arqueo sin diferencias',300);
            if($esperado!==$contado&&mb_strlen($motivo)<5)throw new ErpException('Describe el motivo de la diferencia.');
            $this->r->q("UPDATE erp_caja_sesiones SET estado='cerrada',cierre_en=?,contado_centavos=?,esperado_centavos=?,diferencia_centavos=?,motivo=? WHERE id=?",[$this->r::ahora(),$contado,$esperado,$contado-$esperado,$motivo,(int)$s['id']]);
            $this->evento($actor,'caja_cerrada','caja',(int)$s['id'],['esperado'=>$esperado,'contado'=>$contado,'diferencia'=>$contado-$esperado]);return (int)$s['id'];
        });
    }
    public function movimiento(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.caja.movimientos','caja_movimiento',$d,function()use($actor,$d){
            $s=$this->sesion($actor);$tipo=$d['tipo']??'';if(!in_array($tipo,['ingreso','egreso'],true))throw new ErpException('Tipo no válido.');
            $monto=$this->importe($d['monto']??null);$motivo=$this->texto($d['motivo']??'');
            if($tipo==='egreso'&&$monto>$this->esperado((int)$s['id']))throw new ErpException('No hay suficiente efectivo esperado en esta caja.');
            $id=$this->asentar($actor,(int)$s['id'],$tipo,$monto,'efectivo',$motivo,'mov:'.$actor.':'.$d['idem_key']);
            $this->evento($actor,'caja_movimiento','caja',(int)$s['id'],['movimiento_id'=>$id,'tipo'=>$tipo,'monto'=>$monto,'motivo'=>$motivo]);return (int)$s['id'];
        });
    }
    public function sesion(int $actor):array
    {
        return $this->r->uno("SELECT * FROM erp_caja_sesiones WHERE actor_id=? AND estado='abierta' FOR UPDATE",[$actor])??throw new ErpException('Abre tu caja antes de registrar dinero.');
    }
    public function esperado(int $sesion):int
    {
        $s=$this->r->uno('SELECT fondo_centavos FROM erp_caja_sesiones WHERE id=?',[$sesion])??throw new ErpException('Caja no encontrada.');
        $m=$this->r->uno("SELECT COALESCE(SUM(IF(c.tipo='ingreso',c.monto_centavos,-c.monto_centavos)),0) saldo FROM erp_caja_movimientos e JOIN caja_movimientos c ON c.id=e.caja_id WHERE e.sesion_id=? AND c.metodo='efectivo'",[$sesion]);
        return (int)$s['fondo_centavos']+(int)$m['saldo'];
    }
    public function asentar(int $actor,int $sesion,string $tipo,int $monto,string $metodo,string $concepto,string $clave,?int $pago=null,int $devolucion=0):int
    {
        $this->r->q('INSERT INTO caja_movimientos(actor_id,tipo,monto_centavos,concepto,metodo,pago_id,devolucion_id,idempotencia,huella,registrado_en) VALUES(?,?,?,?,?,?,?,?,?,?)',[$actor,$tipo,$monto,mb_substr($concepto,0,180),$metodo,$pago,$devolucion,$clave,hash('sha256',$clave),$this->r::ahora()]);
        $id=$this->r->id();$this->r->q('INSERT INTO erp_caja_movimientos(caja_id,sesion_id) VALUES(?,?)',[$id,$sesion]);return $id;
    }
}

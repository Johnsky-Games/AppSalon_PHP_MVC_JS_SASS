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
    /**
     * Movimiento manual de efectivo. Exige un CONCEPTO activo de sentido coincidente (la naturaleza decide si es aporte, retiro, depósito, gasto, sobrante o faltante)
     * y un motivo. La caja se registra siempre; la contabilización usa la cuenta del concepto y, si falta configurarla, queda pendiente solo ese movimiento.
     */
    public function movimiento(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.caja.movimientos','caja_movimiento',$d,function()use($actor,$d){
            $s=$this->sesion($actor);
            if(trim((string)($d['concepto_id']??''))==='')throw new ErpException('Elige el concepto del movimiento: sin concepto no se sabe si es aporte, retiro, depósito, gasto o ajuste.');
            $k=$this->r->uno('SELECT * FROM erp_caja_conceptos WHERE id=? FOR SHARE',[$this->id($d['concepto_id'])])??throw new ErpException('Concepto de caja no encontrado.',404);
            if((int)$k['activo']!==1)throw new ErpException('El concepto «'.$k['nombre'].'» está desactivado.',409);
            // El sentido lo fija el concepto; si el formulario manda otro, se rechaza en lugar de reinterpretarlo.
            $tipo=$k['tipo'];if(isset($d['tipo'])&&$d['tipo']!==$tipo)throw new ErpException('El concepto «'.$k['nombre'].'» es de '.$tipo.': no corresponde a un '.(string)$d['tipo'].'.');
            $monto=$this->importe($d['monto']??null);$motivo=$this->texto($d['motivo']??'',300);if(mb_strlen($motivo)<5)throw new ErpException('Explica el motivo del movimiento (al menos 5 caracteres).');
            if($tipo==='egreso'&&$monto>$this->esperado((int)$s['id']))throw new ErpException('No hay suficiente efectivo esperado en esta caja.');
            $id=$this->asentar($actor,(int)$s['id'],$tipo,$monto,'efectivo',$k['nombre'].': '.$motivo,'mov:'.$actor.':'.$d['idem_key']);
            $evento=$this->evento($actor,'caja_movimiento','caja',(int)$s['id'],['movimiento_id'=>$id,'tipo'=>$tipo,'monto'=>$monto,'motivo'=>$motivo,'concepto_id'=>(int)$k['id']],['contabilidad']);
            $this->r->q('INSERT INTO erp_caja_mov_conceptos(caja_id,concepto_id,motivo,evento_id,creado_por,creado_en) VALUES(?,?,?,?,?,?)',[$id,(int)$k['id'],$motivo,$evento,$actor,$this->r::ahora()]);
            return $id;
        });
    }
    /**
     * Reverso de un movimiento manual: registra el movimiento contrario en TU caja abierta (la sesión original puede estar cerrada y arqueada) y revierte su asiento.
     * Permiso `contabilidad.registrar`; un movimiento se reversa una sola vez y un reverso no se reversa.
     */
    public function movimientoReversar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'contabilidad.registrar','caja_reverso',$d,function()use($actor,$d){
            $s=$this->sesion($actor);$orig=$this->id($d['movimiento_id']??null);
            $motivo=$this->texto($d['motivo']??'',300);if(mb_strlen($motivo)<5)throw new ErpException('Explica el motivo del reverso (al menos 5 caracteres).');
            $c=$this->r->uno('SELECT * FROM erp_caja_mov_conceptos WHERE caja_id=? FOR UPDATE',[$orig])??throw new ErpException('Solo se reversa un movimiento manual clasificado con concepto.',404);
            if($c['reversa_de_id'])throw new ErpException('Un reverso no se reversa.',409);
            if($this->r->uno('SELECT caja_id FROM erp_caja_mov_conceptos WHERE reversa_de_id=?',[$orig]))throw new ErpException('Ese movimiento ya fue reversado.',409);
            $m=$this->r->uno('SELECT * FROM caja_movimientos WHERE id=?',[$orig])??throw new ErpException('Movimiento no encontrado.',404);
            $tipo=$m['tipo']==='ingreso'?'egreso':'ingreso';$monto=(int)$m['monto_centavos'];
            if($tipo==='egreso'&&$monto>$this->esperado((int)$s['id']))throw new ErpException('No hay suficiente efectivo esperado en tu caja para reversar un ingreso.',409);
            $id=$this->asentar($actor,(int)$s['id'],$tipo,$monto,'efectivo','Reverso del movimiento '.$orig.': '.$motivo,'rev:'.$actor.':'.$d['idem_key']);
            $evento=$this->evento($actor,'caja_movimiento_reversado','caja',(int)$s['id'],['movimiento_id'=>$id,'reversa_de'=>$orig,'tipo'=>$tipo,'monto'=>$monto,'motivo'=>$motivo,'aprobacion_evento_id'=>(int)($c['evento_id']??0)],['contabilidad']);
            $this->r->q('INSERT INTO erp_caja_mov_conceptos(caja_id,concepto_id,motivo,reversa_de_id,evento_id,creado_por,creado_en) VALUES(?,?,?,?,?,?,?)',[$id,(int)$c['concepto_id'],$motivo,$orig,$evento,$actor,$this->r::ahora()]);
            return $id;
        });
    }
    public function sesion(int $actor):array
    {
        return $this->r->uno("SELECT * FROM erp_caja_sesiones WHERE actor_id=? AND estado='abierta' FOR UPDATE",[$actor])??throw new ErpException('Abre tu caja antes de registrar dinero.');
    }
    public function esperado(int $sesion):int
    {
        return (new CajaArqueoService($this->r))->esperado($sesion);
    }
    public function asentar(int $actor,int $sesion,string $tipo,int $monto,string $metodo,string $concepto,string $clave,?int $pago=null,int $devolucion=0):int
    {
        $this->r->q('INSERT INTO caja_movimientos(actor_id,tipo,monto_centavos,concepto,metodo,pago_id,devolucion_id,idempotencia,huella,registrado_en) VALUES(?,?,?,?,?,?,?,?,?,?)',[$actor,$tipo,$monto,mb_substr($concepto,0,180),$metodo,$pago,$devolucion,$clave,hash('sha256',$clave),$this->r::ahora()]);
        $id=$this->r->id();$this->r->q('INSERT INTO erp_caja_movimientos(caja_id,sesion_id) VALUES(?,?)',[$id,$sesion]);return $id;
    }
}

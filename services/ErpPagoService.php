<?php
namespace Services;

final class ErpPagoService extends ErpService
{
    public function cobrar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.cobrar','cobrar',$d,function()use($actor,$d){
            $id=$this->id($d['venta_id']??null);$ventas=new ErpVentaService($this->r);$v=$ventas->bloquear($id);
            if($v['estado']!=='confirmada')throw new ErpException('Confirma la venta antes de cobrar.');
            if($v['cita_id']){
                $c=$this->r->uno('SELECT estado FROM citas WHERE id=?',[(int)$v['cita_id']]);
                if(in_array($c['estado'],['cancelada','no_asistio'],true)&&$this->r->uno('SELECT id FROM erp_venta_lineas WHERE venta_id=? AND servicio_id IS NOT NULL AND cantidad_milesimas>devuelto_milesimas',[$id]))throw new ErpException('Resuelve los servicios cancelados y sus anticipos antes de cobrar.');
            }
            $monto=$this->importe($d['monto']??null);$metodo=$d['metodo']??'';
            if(!in_array($metodo,['efectivo','transferencia'],true))throw new ErpException('Selecciona efectivo o transferencia.');
            $s=$ventas->resumen($id);if($monto>$s['disponible'])throw new ErpException('El importe supera el saldo disponible. Revisa las transferencias pendientes.',409);
            $cajas=new ErpCajaService($this->r);$sesion=$cajas->sesion($actor);
            $ref=null;$recibido=null;$cambio=null;
            if($metodo==='efectivo'){
                $recibido=$this->importe($d['recibido']??$d['monto']);if($recibido<$monto)throw new ErpException('El efectivo recibido es menor al importe aplicado.');$cambio=$recibido-$monto;
            }else{$ref=CobroCalculo::referenciaNormalizada($d['referencia']??'');}
            $clave='erp:'.hash('sha256',$actor.':'.$d['idem_key']);$referencia='MAN-ERP-'.strtoupper(bin2hex(random_bytes(10)));$ahora=$this->r::ahora();
            $this->r->q("INSERT INTO pagos(cita_id,cita_referencia_id,usuario_id,referencia_pago,proveedor,monto,moneda,estado,metodo_pago,idempotency_key,payload_proveedor,pagado_at,monto_recibido_centavos,cambio_centavos,referencia_transferencia,referencia_unica,estado_verificacion,registrado_por) VALUES(?,?,?,?,'manual',?,'USD',?,?,?,?,?,?,?,?,?,?,?)",[$v['cita_id'],(int)($v['cita_id']??0),$v['cliente_usuario_id'],$referencia,CobroCalculo::plano($monto),$metodo==='efectivo'?'completado':'pendiente',$metodo,$clave,json_encode(['venta_id'=>$id,'via'=>'pdv']),$metodo==='efectivo'?$ahora:null,$recibido,$cambio,$ref['visible']??null,$ref['unica']??null,$metodo==='efectivo'?'confirmado':'pendiente_verificacion',$actor]);
            $pago=$this->r->id();
            $this->r->q("INSERT INTO erp_pago_aplicaciones(pago_id,venta_id,sesion_id,importe_centavos,origen) VALUES(?,?,?,?,'pdv')",[$pago,$id,(int)$sesion['id'],$monto]);
            if($metodo==='efectivo')$cajas->asentar($actor,(int)$sesion['id'],'ingreso',$monto,$metodo,'Venta #'.$id,'erp.pago:'.$pago,$pago);
            $this->actualizarCita($id);(new ErpComisionService($this->r))->sincronizar($id,$actor);
            $evento=$this->evento($actor,$metodo==='efectivo'?'pago_confirmado':'transferencia_declarada','venta',$id,['pago_id'=>$pago,'importe'=>$monto,'recibido'=>$recibido,'cambio'=>$cambio],$metodo==='efectivo'?['contabilidad']:[]);
            if($metodo==='efectivo')(new ErpReciboService($this->r))->emitir($actor,$id,$evento);
            return $id;
        });
    }
    public function verificar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.verificar','verificar',$d,function()use($actor,$d){
            $id=$this->id($d['venta_id']??null);$ventas=new ErpVentaService($this->r);$ventas->bloquear($id);
            $p=$this->r->uno('SELECT p.*,a.origen FROM pagos p JOIN erp_pago_aplicaciones a ON a.pago_id=p.id WHERE a.venta_id=? AND p.id=? FOR UPDATE',[$id,$this->id($d['pago_id']??null)]);
            if(!$p||$p['metodo_pago']!=='transferencia'||$p['estado_verificacion']!=='pendiente_verificacion')throw new ErpException('La transferencia no está pendiente.',409);
            $confirmar=($d['decision']??'')==='confirmar';if(!in_array($d['decision']??'',['confirmar','rechazar'],true))throw new ErpException('Decisión no válida.');
            $rol=(new AuthorizationService($this->r->db))->contexto($actor)['rol']??'';
            if($confirmar&&(int)$p['registrado_por']===$actor&&$rol!=='propietario')throw new ErpException('Otra persona autorizada debe verificar esta transferencia.',403);
            $nota=$this->texto($d['nota']??'',180);$monto=CobroCalculo::centavos($p['monto']);
            $s=$ventas->resumen($id);if($confirmar&&$s['neto']+$monto>$s['total'])throw new ErpException('La transferencia supera el saldo de la venta.',409);
            $this->r->q('UPDATE pagos SET estado=?,estado_verificacion=?,pagado_at=?,verificado_por=?,verificado_en=?,verificacion_nota=?,referencia_unica=? WHERE id=?',[$confirmar?'completado':'fallido',$confirmar?'confirmado':'rechazado',$confirmar?$this->r::ahora():null,$actor,$this->r::ahora(),$nota,$confirmar?$p['referencia_unica']:null,(int)$p['id']]);
            if($confirmar){$cajas=new ErpCajaService($this->r);$sesion=$cajas->sesion($actor);$cajas->asentar($actor,(int)$sesion['id'],'ingreso',$monto,'transferencia','Venta #'.$id,'erp.pago:'.$p['id'],(int)$p['id']);}
            $this->actualizarCita($id);(new ErpComisionService($this->r))->sincronizar($id,$actor);$evento=$this->evento($actor,$confirmar?'transferencia_confirmada':'transferencia_rechazada','venta',$id,['pago_id'=>$p['id'],'nota'=>$nota],$confirmar?['contabilidad']:[]);
            if($confirmar)(new ErpReciboService($this->r))->emitir($actor,$id,$evento);return $id;
        });
    }
    public function actualizarCita(int $venta):void
    {
        $v=$this->r->uno('SELECT cita_id FROM erp_ventas WHERE id=?',[$venta]);
        if(!$v['cita_id'])return;
        $s=(new ErpVentaService($this->r))->resumen($venta);
        $estado=$s['devolucion_comercial']===$s['original']&&$s['neto']===0?'reembolsado':($s['saldo']===0?'pagado':($s['pendiente']>0?'por_verificar':($s['neto']>0?'parcial':'pendiente')));
        $this->r->q('UPDATE citas SET estado_pago=? WHERE id=?',[$estado,(int)$v['cita_id']]);
    }
}

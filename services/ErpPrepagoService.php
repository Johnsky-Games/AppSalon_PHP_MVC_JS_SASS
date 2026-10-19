<?php
namespace Services;

/** Dinero recibido, saldo del beneficiario y liquidación de una venta son hechos diferentes. */
final class ErpPrepagoService extends ErpService
{
    public function crear(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.cobrar','prepago_crear',$d,function()use($actor,$d){
            $contacto=$this->id($d['contacto_id']??null);
            if(!$this->r->uno("SELECT id FROM erp_contactos WHERE id=? AND activo=1 AND tipo IN ('cliente','ambos') FOR SHARE",[$contacto]))throw new ErpException('Selecciona el contacto cliente beneficiario.');
            $tipo=$d['tipo']??'';if(!in_array($tipo,['saldo','regalo'],true))throw new ErpException('Tipo de prepago no válido.');
            $nombre=$this->texto($d['nombre']??'',120);
            $this->r->q('INSERT INTO erp_prepagos(contacto_id,tipo,nombre,creado_por,creado_en) VALUES(?,?,?,?,?)',[$contacto,$tipo,$nombre,$actor,$this->r::ahora()]);
            $id=$this->r->id();$this->evento($actor,'prepago_creado','prepago',$id,['contacto_id'=>$contacto,'tipo'=>$tipo,'nombre'=>$nombre]);return $id;
        });
    }
    public function cargar(int $actor,array $d):array
    {
        $existente=false;
        $resultado=$this->ejecutar($actor,'erp.cobrar','prepago_cargar',$d,function()use($actor,$d,&$existente){
            $id=$this->id($d['prepago_id']??null);$this->bloquear($id);$monto=$this->importe($d['monto']??null);
            $metodo=$d['metodo']??'';if(!in_array($metodo,['efectivo','transferencia'],true))throw new ErpException('Selecciona efectivo o transferencia.');
            $recibido=null;$cambio=null;$ref=null;
            if($metodo==='efectivo'){$recibido=$this->importe($d['recibido']??$d['monto']);if($recibido<$monto)throw new ErpException('El efectivo recibido es menor a la carga.');$cambio=$recibido-$monto;}
            else{$ref=CobroCalculo::referenciaNormalizada($d['referencia']??null);}
            $clave=hash('sha256','prepago.cargar:'.$actor.':'.$d['idem_key']);
            $huella=hash('sha256',json_encode([$id,$metodo,$monto,$recibido,$ref['unica']??null],JSON_THROW_ON_ERROR));
            $previa=$this->r->uno('SELECT prepago_id,huella FROM erp_prepago_cargas WHERE clave_operacion=? FOR SHARE',[$clave]);
            if($previa){
                if(!hash_equals($previa['huella'],$huella))throw new ErpException('Esta clave ya identifica otra carga. Revisa el comprobante original.',409);
                $existente=true;return (int)$previa['prepago_id'];
            }
            $pendientes=$this->r->q("SELECT monto_centavos FROM erp_prepago_cargas WHERE prepago_id=? AND estado='pendiente' FOR SHARE",[$id]);
            if($this->saldo($id)+array_sum(array_column($pendientes,'monto_centavos'))+$monto>99999999)throw new ErpException('El saldo y las cargas pendientes superan el límite permitido.');
            $cajas=new ErpCajaService($this->r);$sesion=$cajas->sesion($actor);
            try {
            $this->r->q('INSERT INTO erp_prepago_cargas(prepago_id,clave_operacion,huella,monto_centavos,metodo,estado,recibido_centavos,cambio_centavos,referencia,referencia_unica,registrado_por,creado_en,resuelto_en) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',[$id,$clave,$huella,$monto,$metodo,$metodo==='efectivo'?'confirmada':'pendiente',$recibido,$cambio,$ref['visible']??null,$ref['unica']??null,$actor,$this->r::ahora(),$metodo==='efectivo'?$this->r::ahora():null]);
            } catch(\Throwable $e) {
                if((int)$e->getCode()===1062)throw new ErpException('Esa referencia de transferencia ya está registrada en otro cobro o prepago.',409);
                throw $e;
            }
            $carga=$this->r->id();
            if($ref)(new ErpTransferenciaService($this->r))->reservar($ref['unica'],null,$carga);
            if($metodo==='efectivo'){
                $caja=$cajas->asentar($actor,(int)$sesion['id'],'ingreso',$monto,'efectivo','Carga de prepago #'.$id,'prepago.carga:'.$carga);
                $this->movimiento($actor,$id,'carga',$monto,'carga:'.$carga,'Efectivo recibido',$carga,null,null,null,$caja);
            }
            $this->evento($actor,$metodo==='efectivo'?'prepago_cargado':'prepago_transferencia_declarada','prepago',$id,['carga_id'=>$carga,'monto'=>$monto,'recibido'=>$recibido,'cambio'=>$cambio],$metodo==='efectivo'?['contabilidad']:[]);return $id;
        });
        if($existente)$resultado['repetida']=true;
        return $resultado;
    }
    public function verificar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.verificar','prepago_verificar',$d,function()use($actor,$d){
            $id=$this->id($d['prepago_id']??null);$this->bloquear($id);
            $c=$this->r->uno('SELECT * FROM erp_prepago_cargas WHERE id=? AND prepago_id=? FOR UPDATE',[$this->id($d['carga_id']??null),$id]);
            if(!$c||$c['metodo']!=='transferencia'||$c['estado']!=='pendiente')throw new ErpException('La carga no está pendiente de verificación.',409);
            $decision=$d['decision']??'';if(!in_array($decision,['confirmar','rechazar'],true))throw new ErpException('Decisión no válida.');
            $confirmar=$decision==='confirmar';$nota=$this->texto($d['nota']??'');
            $rol=(new AuthorizationService($this->r->db))->contexto($actor)['rol']??'';
            if($confirmar&&(int)$c['registrado_por']===$actor&&$rol!=='propietario')throw new ErpException('Otra persona autorizada debe verificar esta transferencia.',403);
            $this->r->q('UPDATE erp_prepago_cargas SET estado=?,verificado_por=?,verificacion_nota=?,resuelto_en=?,referencia_unica=? WHERE id=?',[$confirmar?'confirmada':'rechazada',$actor,$nota,$this->r::ahora(),$confirmar?$c['referencia_unica']:null,(int)$c['id']]);
            if($confirmar){
                $cajas=new ErpCajaService($this->r);$sesion=$cajas->sesion($actor);
                $caja=$cajas->asentar($actor,(int)$sesion['id'],'ingreso',(int)$c['monto_centavos'],'transferencia','Carga de prepago #'.$id,'prepago.carga:'.$c['id']);
                $this->movimiento($actor,$id,'carga',(int)$c['monto_centavos'],'carga:'.$c['id'],$nota,(int)$c['id'],null,null,null,$caja);
            } else {(new ErpTransferenciaService($this->r))->liberarCarga((int)$c['id']);}
            $this->evento($actor,$confirmar?'prepago_cargado':'prepago_transferencia_rechazada','prepago',$id,['carga_id'=>$c['id'],'monto'=>$c['monto_centavos'],'nota'=>$nota],$confirmar?['contabilidad']:[]);return $id;
        });
    }
    public function aplicar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.cobrar','prepago_aplicar',$d,function()use($actor,$d){
            $venta=$this->id($d['venta_id']??null);$ventas=new ErpVentaService($this->r);$v=$ventas->bloquear($venta);
            if($v['estado']!=='confirmada')throw new ErpException('Confirma la venta antes de aplicar un prepago.');
            if($v['cita_id']){
                $c=$this->r->uno('SELECT estado FROM citas WHERE id=?',[(int)$v['cita_id']]);
                if(in_array($c['estado'],['cancelada','no_asistio'],true))throw new ErpException('Resuelve la cita cancelada antes de aplicar saldo.');
            }
            $id=$this->id($d['prepago_id']??null);$p=$this->bloquear($id);
            $contacto=$this->r->uno('SELECT usuario_id FROM erp_contactos WHERE id=? FOR SHARE',[(int)$p['contacto_id']]);
            if((int)$v['contacto_id']!==(int)$p['contacto_id']&&(!$v['cliente_usuario_id']||!$contacto['usuario_id']||(int)$v['cliente_usuario_id']!==(int)$contacto['usuario_id']))throw new ErpException('El prepago pertenece a otro beneficiario. Identifica al comprador correcto.',409);
            $monto=$this->importe($d['monto']??null);$s=$ventas->resumen($venta);
            if($monto>$s['disponible']||$monto>$this->saldo($id))throw new ErpException('El importe supera el saldo disponible de la venta o del prepago.',409);
            $sesion=(new ErpCajaService($this->r))->sesion($actor);
            $this->r->q("INSERT INTO pagos(cita_id,cita_referencia_id,usuario_id,referencia_pago,proveedor,monto,moneda,estado,metodo_pago,idempotency_key,payload_proveedor,pagado_at,estado_verificacion,registrado_por) VALUES(?,?,?,?,'manual',?,'USD','completado','prepago',?,?,?,'confirmado',?)",[$v['cita_id'],(int)($v['cita_id']??0),$v['cliente_usuario_id'],'PRE-'.strtoupper(bin2hex(random_bytes(12))),CobroCalculo::plano($monto),'prepago:'.hash('sha256',$actor.':'.$d['idem_key']),json_encode(['prepago_id'=>$id,'venta_id'=>$venta,'via'=>'saldo_prepagado']),$this->r::ahora(),$actor]);
            $pago=$this->r->id();
            $this->r->q("INSERT INTO erp_pago_aplicaciones(pago_id,venta_id,sesion_id,importe_centavos,origen) VALUES(?,?,?,?,'pdv')",[$pago,$venta,(int)$sesion['id'],$monto]);
            $this->movimiento($actor,$id,'aplicacion',-$monto,'aplicacion:'.$pago,'Aplicación a venta #'.$venta,null,$pago,null,$venta);
            (new ErpPagoService($this->r))->actualizarCita($venta);(new ErpComisionService($this->r))->sincronizar($venta,$actor);
            $evento=$this->evento($actor,'prepago_aplicado','venta',$venta,['prepago_id'=>$id,'pago_id'=>$pago,'monto'=>$monto],['contabilidad']);
            (new ErpReciboService($this->r))->emitir($actor,$venta,$evento);return $venta;
        });
    }
    public function reembolsar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.devolver','prepago_reembolsar',$d,function()use($actor,$d){
            $id=$this->id($d['prepago_id']??null);$this->bloquear($id);
            $c=$this->r->uno('SELECT * FROM erp_prepago_cargas WHERE id=? AND prepago_id=? FOR UPDATE',[$this->id($d['carga_id']??null),$id]);
            if(!$c||$c['estado']!=='confirmada')throw new ErpException('Selecciona una carga confirmada del beneficiario.');
            $monto=$this->importe($d['monto']??null);$motivo=$this->texto($d['motivo']??'',300);
            $devs=$this->r->q("SELECT monto_centavos FROM erp_prepago_movimientos WHERE carga_id=? AND tipo='reembolso' FOR SHARE",[(int)$c['id']]);
            if($monto>$this->saldo($id)||$monto>(int)$c['monto_centavos']+array_sum(array_column($devs,'monto_centavos')))throw new ErpException('El importe supera el saldo sin utilizar o lo pendiente de reembolsar de esa carga.',409);
            $cajas=new ErpCajaService($this->r);$sesion=$cajas->sesion($actor);
            if($c['metodo']==='efectivo'&&$monto>$cajas->esperado((int)$sesion['id']))throw new ErpException('Tu caja no dispone del efectivo esperado para el reintegro.');
            $clave='prepago.reembolso:'.$actor.':'.$d['idem_key'];
            $caja=$cajas->asentar($actor,(int)$sesion['id'],'egreso',$monto,$c['metodo'],'Reembolso de prepago #'.$id,$clave);
            $mov=$this->movimiento($actor,$id,'reembolso',-$monto,'reembolso:'.$caja,$motivo,(int)$c['id'],null,null,null,$caja);
            $this->evento($actor,'prepago_reembolsado','prepago',$id,['movimiento_id'=>$mov,'carga_id'=>$c['id'],'monto'=>$monto,'metodo'=>$c['metodo'],'motivo'=>$motivo],['contabilidad']);return $id;
        });
    }
    public function bloquear(int $id):array
    { return $this->r->uno('SELECT * FROM erp_prepagos WHERE id=? FOR UPDATE',[$id])??throw new ErpException('Prepago no encontrado.',404); }
    public function saldo(int $id):int
    {
        $movs=$this->r->q('SELECT monto_centavos FROM erp_prepago_movimientos WHERE prepago_id=? ORDER BY id FOR SHARE',[$id]);
        return (int)array_sum(array_column($movs,'monto_centavos'));
    }
    /** Solo desde la devolución comercial, en su transacción y bloqueo de venta. */
    public function restituir(int $actor,int $pago,int $devolucion,int $monto,int $venta,string $motivo):void
    {
        if(\Classes\Transaccion::nivel($this->r->db)===0)throw new \LogicException('La restitución requiere la transacción comercial.');
        $a=$this->r->uno("SELECT * FROM erp_prepago_movimientos WHERE pago_id=? AND tipo='aplicacion' FOR SHARE",[$pago]);
        if(!$a||(int)$a['venta_id']!==$venta)throw new ErpException('No se encontró el origen del saldo aplicado.');
        $id=(int)$a['prepago_id'];$this->bloquear($id);
        $devs=$this->r->q("SELECT monto_centavos FROM erp_prepago_movimientos WHERE pago_id=? AND tipo='restitucion' FOR SHARE",[$pago]);
        if($monto<=0||$monto>-(int)$a['monto_centavos']-array_sum(array_column($devs,'monto_centavos')))throw new ErpException('La restitución supera el saldo aplicado.',409);
        $this->movimiento($actor,$id,'restitucion',$monto,'restitucion:'.$devolucion,$motivo,null,$pago,$devolucion,$venta);
        $this->evento($actor,'prepago_restituido','prepago',$id,['pago_id'=>$pago,'devolucion_id'=>$devolucion,'venta_id'=>$venta,'monto'=>$monto]);
    }
    private function movimiento(int $actor,int $id,string $tipo,int $monto,string $referencia,string $motivo,?int $carga=null,?int $pago=null,?int $devolucion=null,?int $venta=null,?int $caja=null):int
    {
        $json=null;$sha=null;
        if($caja!==null){
            $datos=['empresa'=>$this->r->uno('SELECT nombre,identificacion,direccion,moneda FROM erp_empresa WHERE id=1'),
                'beneficiario'=>$this->r->uno('SELECT c.nombre,c.identificacion,p.nombre prepago,p.tipo FROM erp_prepagos p JOIN erp_contactos c ON c.id=p.contacto_id WHERE p.id=?',[$id]),
                'operador'=>$this->r->uno('SELECT nombre,apellido FROM usuarios WHERE id=?',[$actor]),
                'carga'=>$this->r->uno('SELECT id,monto_centavos,metodo,referencia,recibido_centavos,cambio_centavos FROM erp_prepago_cargas WHERE id=?',[$carga]),
                'tipo'=>$tipo,'monto_centavos'=>$monto,'motivo'=>$motivo,'saldo_despues'=>$this->saldo($id)+$monto,'leyenda'=>ComprobanteService::LEYENDA];
            $json=json_encode($datos,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$sha=hash('sha256',$json);
        }
        $this->r->q('INSERT INTO erp_prepago_movimientos(prepago_id,tipo,monto_centavos,carga_id,pago_id,devolucion_id,venta_id,caja_id,referencia,motivo,actor_id,creado_en,comprobante_json,comprobante_sha256) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$id,$tipo,$monto,$carga,$pago,$devolucion,$venta,$caja,$referencia,$motivo,$actor,$this->r::ahora(),$json,$sha]);return $this->r->id();
    }
    public function comprobante(int $actor,int $id):array
    {
        $this->permitir($actor,'erp.cobrar');
        $m=$this->r->uno('SELECT id,prepago_id,creado_en,comprobante_json,comprobante_sha256 FROM erp_prepago_movimientos WHERE id=? AND comprobante_json IS NOT NULL',[$id])??throw new ErpException('Comprobante no encontrado.',404);
        if(!hash_equals((string)$m['comprobante_sha256'],hash('sha256',$m['comprobante_json'])))throw new ErpException('No se pudo verificar la integridad del comprobante.',409);
        return ['codigo'=>'SP-'.str_pad((string)$m['id'],8,'0',STR_PAD_LEFT),'prepago_id'=>(int)$m['prepago_id'],'creado_en'=>$m['creado_en'],'contenido'=>json_decode($m['comprobante_json'],true,512,JSON_THROW_ON_ERROR)];
    }
}

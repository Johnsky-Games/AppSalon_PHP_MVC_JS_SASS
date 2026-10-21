<?php
namespace Services;
final class ErpAprobacionService extends ErpService
{
    public function solicitar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.ventas.crear','solicitud',$d,function()use($actor,$d){
            $tipo=$d['tipo']??'';if(!in_array($tipo,['descuento','gasto','ajuste_stock'],true))throw new ErpException('Tipo de solicitud no válido.');
            $motivo=$this->texto($d['motivo']??'',300);
            $datos=match($tipo){
                'descuento'=>['venta_id'=>$this->id($d['venta_id']??null),'catalogo_id'=>$this->id($d['catalogo_id']??null),'cantidad'=>$d['cantidad']??null,'descuento'=>$d['descuento']??null,'motivo'=>$motivo],
                'gasto'=>['monto'=>$this->importe($d['monto']??null)],
                'ajuste_stock'=>['producto_id'=>$this->id($d['producto_id']??null),'tipo'=>$d['movimiento']??'','cantidad'=>$d['cantidad']??null,'motivo'=>$motivo],
            };
            if($tipo==='descuento'){
                $v=$this->r->uno('SELECT version,estado FROM erp_ventas WHERE id=?',[$datos['venta_id']]);if(!$v||$v['estado']!=='borrador')throw new ErpException('La venta no se puede editar.');
                ValoresExactos::decimal($datos['cantidad'],3);$this->importe($datos['descuento']);$datos['version']=(int)$v['version'];
            }
            if($tipo==='ajuste_stock'){ValoresExactos::decimal($datos['cantidad'],3);if(!in_array($datos['tipo'],['entrada','salida'],true))throw new ErpException('Movimiento no válido.');
                // Todo ajuste nuevo lleva un concepto (merma, faltante, sobrante, uso interno…): el sentido lo valida el concepto y la contabilización usa su cuenta.
                $datos['concepto_id']=(new ErpInventarioConceptoService($this->r))->validarParaSolicitud($d['concepto_id']??null,$datos['tipo']);}
            $this->r->q('INSERT INTO erp_solicitudes(tipo,datos_json,motivo,solicitante_id,creado_en) VALUES(?,?,?,?,?)',[$tipo,json_encode($datos,JSON_THROW_ON_ERROR),$motivo,$actor,$this->r::ahora()]);
            $id=$this->r->id();$this->evento($actor,'aprobacion_solicitada','solicitud',$id,['tipo'=>$tipo]);return $id;
        });
    }
    public function resolver(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.aprobaciones','solicitud_resolver',$d,function()use($actor,$d){
            $id=$this->id($d['solicitud_id']??null);$s=$this->r->uno('SELECT * FROM erp_solicitudes WHERE id=? FOR UPDATE',[$id])??throw new ErpException('Solicitud no encontrada.');
            if($s['estado']!=='solicitada')throw new ErpException('La solicitud ya fue resuelta.',409);
            $ctx=(new AuthorizationService($this->r->db))->contexto($actor);
            if((int)$s['solicitante_id']===$actor&&$ctx['rol']!=='propietario')throw new ErpException('Otra persona autorizada debe resolver tu solicitud.',403);
            $aprobar=($d['decision']??'')==='aprobar';if(!in_array($d['decision']??'',['aprobar','rechazar'],true))throw new ErpException('Decisión no válida.');$nota=$this->texto($d['nota']??'',300);
            $datos=json_decode($s['datos_json'],true,512,JSON_THROW_ON_ERROR);
            if($aprobar){
                if($s['tipo']==='descuento'){
                    $this->permitir($actor,'erp.descuento');$venta=(new ErpVentaService($this->r))->bloquear((int)$datos['venta_id']);
                    if((int)$venta['version']!==$datos['version'])throw new ErpException('La venta cambió. Solicita de nuevo el descuento.',409);
                    (new ErpVentaService($this->r))->agregar($actor,$datos+['idem_key'=>hash('sha256','solicitud:'.$id)]);
                }elseif($s['tipo']==='gasto'){
                    $this->permitir($actor,'erp.obligaciones.pagar');
                    $this->r->q("INSERT INTO erp_obligaciones(origen,referencia,monto_centavos,vence_en,creado_por,creado_en) VALUES('gasto',?,?,?,?,?)",['SOL-'.$id,(int)$datos['monto'],substr($this->r::ahora(),0,10),$actor,$this->r::ahora()]);
                }else{
                    $this->permitir($actor,'inventario.gestionar');
                    // Usar la transacción anidable común: el resultado y la aprobación se confirman juntos.
                    $p=$this->r->uno('SELECT * FROM inventario_productos WHERE id=? FOR UPDATE',[(int)$datos['producto_id']]);if(!$p)throw new ErpException('Producto no encontrado.');
                    $qty=ValoresExactos::decimal($datos['cantidad'],3);$saldo=(int)$p['stock_milesimas']+($datos['tipo']==='entrada'?$qty:-$qty);
                    $reservas=$this->r->q("SELECT cantidad_milesimas FROM erp_stock_reservas WHERE producto_id=? AND estado='activa' FOR SHARE",[(int)$p['id']]);
                    if($saldo<array_sum(array_column($reservas,'cantidad_milesimas'))||$saldo>9999999999)throw new ErpException('El ajuste afectaría stock reservado o excede el rango.');
                    $this->r->q('UPDATE inventario_productos SET stock_milesimas=? WHERE id=?',[$saldo,(int)$p['id']]);$clave='erp.ajuste:'.$id;
                    $this->r->q('INSERT INTO inventario_movimientos(producto_id,actor_id,tipo,cantidad_milesimas,saldo_milesimas,costo_centavos,motivo,idempotencia,huella,registrado_en) VALUES(?,?,?,?,?,?,?,?,?,?)',[(int)$p['id'],$actor,$datos['tipo'],$qty,$saldo,(int)$p['costo_centavos'],mb_substr('Ajuste aprobado #'.$id.' · '.$s['motivo'],0,180),$clave,hash('sha256',$clave),$this->r::ahora()]);
                    $mov=$this->r->id();
                    $this->r->q("INSERT INTO erp_stock_origenes(movimiento_id,documento_tipo,documento_id) VALUES(?,'ajuste',?)",[$mov,$id]);
                    // Las solicitudes anteriores a los conceptos (sin concepto_id) conservan su comportamiento: no se clasifican ni se contabilizan solas.
                    if(!empty($datos['concepto_id'])){
                        $evento=$this->evento($actor,'inventario_ajuste','inventario',$mov,['movimiento_id'=>$mov,'producto_id'=>(int)$p['id'],'tipo'=>$datos['tipo'],'cantidad_milesimas'=>$qty,'costo_unitario'=>(int)$p['costo_centavos'],'concepto_id'=>(int)$datos['concepto_id'],'solicitud_id'=>$id],['contabilidad']);
                        $this->r->q('INSERT INTO erp_inv_ajuste_conceptos(movimiento_id,concepto_id,solicitud_id,evento_id,creado_por,creado_en) VALUES(?,?,?,?,?,?)',[$mov,(int)$datos['concepto_id'],$id,$evento,$actor,$this->r::ahora()]);
                    }
                }
            }
            $this->r->q('UPDATE erp_solicitudes SET estado=?,resuelto_por=?,nota=?,resuelto_en=? WHERE id=?',[$aprobar?'aprobada':'rechazada',$actor,$nota,$this->r::ahora(),$id]);
            $this->evento($actor,$aprobar?'aprobacion_aceptada':'aprobacion_rechazada','solicitud',$id,['nota'=>$nota]);return $id;
        });
    }
}

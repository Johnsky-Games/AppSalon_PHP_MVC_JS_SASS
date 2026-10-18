<?php
namespace Services;

final class ErpCompraService extends ErpService
{
    public function crear(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.compras','compra_crear',$d,function()use($actor,$d){
            $p=$this->id($d['proveedor_id']??null);
            if(!$this->r->uno("SELECT id FROM erp_contactos WHERE id=? AND activo=1 AND tipo IN ('proveedor','ambos')",[$p]))throw new ErpException('Proveedor no disponible.');
            $this->r->q('INSERT INTO erp_compras(proveedor_id,creado_por,creado_en) VALUES(?,?,?)',[$p,$actor,$this->r::ahora()]);$id=$this->r->id();$this->evento($actor,'compra_creada','compra',$id,[]);return $id;
        });
    }
    public function agregar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.compras','compra_linea',$d,function()use($actor,$d){
            $id=$this->id($d['compra_id']??null);$c=$this->compra($id);if($c['estado']!=='borrador')throw new ErpException('La orden ya no se puede editar.');
            $p=$this->id($d['producto_id']??null);if(!$this->r->uno('SELECT id FROM inventario_productos WHERE id=? AND activo=1',[$p]))throw new ErpException('Producto no disponible.');
            $qty=ValoresExactos::decimal($d['cantidad']??null,3);$costo=$this->importe($d['costo']??null,true);$iva=ValoresExactos::decimal($d['impuesto']??null,2,true);$calc=ErpCalculo::linea($qty,$costo,0,$iva);
            $this->r->q('INSERT INTO erp_compra_lineas(compra_id,producto_id,cantidad_milesimas,costo_centavos,impuesto_pb,total_centavos) VALUES(?,?,?,?,?,?)',[$id,$p,$qty,$costo,$iva,$calc['total']]);
            $total=(int)$this->r->uno('SELECT SUM(total_centavos) total FROM erp_compra_lineas WHERE compra_id=?',[$id])['total'];if($total>99999999)throw new ErpException('Importe de orden fuera de rango.');
            $this->r->q('UPDATE erp_compras SET total_centavos=? WHERE id=?',[$total,$id]);$this->evento($actor,'compra_linea','compra',$id,['producto_id'=>$p,'cantidad'=>$qty,'costo'=>$costo]);return $id;
        });
    }
    public function ordenar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.compras','compra_ordenar',$d,function()use($actor,$d){
            $id=$this->id($d['compra_id']??null);$c=$this->compra($id);if($c['estado']!=='borrador'||(int)$c['total_centavos']<=0)throw new ErpException('Revisa las líneas antes de ordenar.');
            $esperado=$this->importe($d['total_esperado']??null);if($esperado!==(int)$c['total_centavos'])throw new ErpException('El total cambió. Revisa la orden.',409);
            $this->r->q("UPDATE erp_compras SET estado='ordenada' WHERE id=?",[$id]);$this->evento($actor,'compra_ordenada','compra',$id,['total'=>$c['total_centavos']]);return $id;
        });
    }
    public function recibir(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.compras','compra_recibir',$d,function()use($actor,$d){
            $id=$this->id($d['compra_id']??null);$c=$this->compra($id);if($c['estado']!=='ordenada')throw new ErpException('La compra no está ordenada.');
            $l=$this->r->uno('SELECT * FROM erp_compra_lineas WHERE compra_id=? AND id=? FOR UPDATE',[$id,$this->id($d['linea_id']??null)]);if(!$l)throw new ErpException('Línea no encontrada.');
            $qty=ValoresExactos::decimal($d['cantidad']??null,3);if($qty+(int)$l['recibido_milesimas']>(int)$l['cantidad_milesimas'])throw new ErpException('La recepción supera la cantidad de la orden.');
            $ref=$this->texto($d['referencia']??'');$p=$this->r->uno('SELECT * FROM inventario_productos WHERE id=? FOR UPDATE',[(int)$l['producto_id']]);
            $saldo=(int)$p['stock_milesimas']+$qty;if($saldo>9999999999)throw new ErpException('Existencia fuera de rango.');
            $costo=intdiv((int)$p['stock_milesimas']*(int)$p['costo_centavos']+$qty*(int)$l['costo_centavos']+intdiv($saldo,2),$saldo);
            $this->r->q('UPDATE inventario_productos SET stock_milesimas=?,costo_centavos=? WHERE id=?',[$saldo,$costo,(int)$p['id']]);
            $clave='erp.compra:'.$actor.':'.$d['idem_key'];
            $this->r->q("INSERT INTO inventario_movimientos(producto_id,actor_id,tipo,cantidad_milesimas,saldo_milesimas,costo_centavos,motivo,idempotencia,huella,registrado_en) VALUES(?,?,'entrada',?,?,?,?,?,?,?)",[(int)$p['id'],$actor,$qty,$saldo,(int)$l['costo_centavos'],mb_substr('Compra #'.$id.' · '.$ref,0,180),$clave,hash('sha256',$clave),$this->r::ahora()]);
            $mov=$this->r->id();$this->r->q("INSERT INTO erp_stock_origenes(movimiento_id,documento_tipo,documento_id) VALUES(?,'compra',?)",[$mov,$id]);
            $this->r->q('UPDATE erp_compra_lineas SET recibido_milesimas=recibido_milesimas+? WHERE id=?',[$qty,(int)$l['id']]);
            $this->evento($actor,'compra_recibida','compra',$id,['linea_id'=>$l['id'],'cantidad'=>$qty,'movimiento_id'=>$mov,'referencia'=>$ref],['contabilidad']);return $id;
        });
    }
    public function facturar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.compras','compra_factura',$d,function()use($actor,$d){
            $id=$this->id($d['compra_id']??null);$c=$this->compra($id);if(!in_array($c['estado'],['ordenada','cerrada'],true))throw new ErpException('La compra no está ordenada.');
            $monto=$this->importe($d['monto']??null);$referencia=$this->texto($d['referencia']??'',120);$vence=$this->fecha($d['vence_en']??null);
            $previo=(int)$this->r->uno('SELECT COALESCE(SUM(monto_centavos),0) total FROM erp_obligaciones WHERE compra_id=?',[$id])['total'];
            if($previo+$monto>(int)$c['total_centavos'])throw new ErpException('Las facturas superan el total autorizado de la orden.');
            $this->r->q("INSERT INTO erp_obligaciones(compra_id,contacto_id,origen,referencia,monto_centavos,vence_en,creado_por,creado_en) VALUES(?,?,'compra',?,?,?,?,?)",[$id,(int)$c['proveedor_id'],$referencia,$monto,$vence,$actor,$this->r::ahora()]);
            $ob=$this->r->id();$this->evento($actor,'factura_proveedor','compra',$id,['obligacion_id'=>$ob,'importe'=>$monto,'referencia'=>$referencia],['contabilidad']);return $id;
        });
    }
    public function pagar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.obligaciones.pagar','obligacion_pagar',$d,function()use($actor,$d){
            $id=$this->id($d['obligacion_id']??null);$o=$this->r->uno('SELECT * FROM erp_obligaciones WHERE id=? FOR UPDATE',[$id])??throw new ErpException('Obligación no encontrada.');
            $monto=$this->importe($d['monto']??null);if($monto>(int)$o['monto_centavos']-(int)$o['ajuste_centavos']-(int)$o['pagado_centavos'])throw new ErpException('El pago supera el saldo por pagar.');
            $metodo=$d['metodo']??'';if(!in_array($metodo,['efectivo','transferencia'],true))throw new ErpException('Medio no válido.');$ref=$this->texto($d['referencia']??'',120);
            $cajas=new ErpCajaService($this->r);$sesion=$cajas->sesion($actor);
            if($metodo==='efectivo'&&$monto>$cajas->esperado((int)$sesion['id']))throw new ErpException('No hay efectivo suficiente en la caja.');
            $caja=$cajas->asentar($actor,(int)$sesion['id'],'egreso',$monto,$metodo,'Pago obligación #'.$id.' · '.$ref,'erp.obligacion:'.$actor.':'.$d['idem_key']);
            $this->r->q('INSERT INTO erp_obligacion_pagos(obligacion_id,caja_id,monto_centavos,referencia,creado_en) VALUES(?,?,?,?,?)',[$id,$caja,$monto,$ref,$this->r::ahora()]);
            $this->r->q('UPDATE erp_obligaciones SET pagado_centavos=pagado_centavos+? WHERE id=?',[$monto,$id]);
            $this->evento($actor,'obligacion_pagada','obligacion',$id,['monto'=>$monto,'referencia'=>$ref,'metodo'=>$metodo,'caja_id'=>$caja],['contabilidad']);return $id;
        });
    }
    public function devolver(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.compras','compra_devolver',$d,function()use($actor,$d){
            $id=$this->id($d['compra_id']??null);$c=$this->compra($id);if(!in_array($c['estado'],['ordenada','cerrada'],true))throw new ErpException('La orden no tiene mercadería recibida.');
            $l=$this->r->uno('SELECT * FROM erp_compra_lineas WHERE id=? AND compra_id=? FOR UPDATE',[$this->id($d['linea_id']??null),$id])??throw new ErpException('Línea no encontrada.');$qty=ValoresExactos::decimal($d['cantidad']??null,3);
            if($qty>(int)$l['recibido_milesimas']-(int)$l['devuelto_milesimas'])throw new ErpException('La devolución supera lo recibido sin devolver.');$motivo=$this->texto($d['motivo']??'',300);
            $p=$this->r->uno('SELECT * FROM inventario_productos WHERE id=? FOR UPDATE',[(int)$l['producto_id']]);$reservado=array_sum(array_column($this->r->q("SELECT cantidad_milesimas FROM erp_stock_reservas WHERE producto_id=? AND estado='activa' FOR SHARE",[(int)$p['id']]),'cantidad_milesimas'));
            if((int)$p['stock_milesimas']-$reservado<$qty)throw new ErpException('La mercadería está consumida o reservada. No se puede devolver.',409);
            $saldo=(int)$p['stock_milesimas']-$qty;$clave='erp.proveedor:'.$actor.':'.$d['idem_key'];$this->r->q('UPDATE inventario_productos SET stock_milesimas=? WHERE id=?',[$saldo,(int)$p['id']]);
            $this->r->q("INSERT INTO inventario_movimientos(producto_id,actor_id,tipo,cantidad_milesimas,saldo_milesimas,costo_centavos,motivo,idempotencia,huella,registrado_en) VALUES(?,?,'salida',?,?,?,?,?,?,?)",[(int)$p['id'],$actor,$qty,$saldo,(int)$p['costo_centavos'],mb_substr($motivo,0,180),$clave,hash('sha256',$clave),$this->r::ahora()]);$mov=$this->r->id();
            $this->r->q("INSERT INTO erp_stock_origenes(movimiento_id,documento_tipo,documento_id) VALUES(?,'devolucion_proveedor',?)",[$mov,$id]);$this->r->q('UPDATE erp_compra_lineas SET devuelto_milesimas=devuelto_milesimas+? WHERE id=?',[$qty,(int)$l['id']]);
            $this->evento($actor,'compra_devuelta','compra',$id,['linea_id'=>$l['id'],'cantidad'=>$qty,'movimiento_id'=>$mov,'motivo'=>$motivo],['contabilidad']);return $id;
        });
    }
    public function credito(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.obligaciones.pagar','proveedor_credito',$d,function()use($actor,$d){
            $id=$this->id($d['obligacion_id']??null);$o=$this->r->uno("SELECT * FROM erp_obligaciones WHERE id=? AND origen='compra' FOR UPDATE",[$id])??throw new ErpException('Factura de proveedor no encontrada.');
            $m=$this->importe($d['monto']??null);$ref=$this->texto($d['referencia']??'',120);$motivo=$this->texto($d['motivo']??'',300);if($m>(int)$o['monto_centavos']-(int)$o['ajuste_centavos'])throw new ErpException('El crédito supera el importe original sin acreditar.');
            $this->r->q('INSERT INTO erp_proveedor_creditos(obligacion_id,referencia,monto_centavos,motivo,actor_id,creado_en) VALUES(?,?,?,?,?,?)',[$id,$ref,$m,$motivo,$actor,$this->r::ahora()]);$this->r->q('UPDATE erp_obligaciones SET ajuste_centavos=ajuste_centavos+? WHERE id=?',[$m,$id]);
            $this->evento($actor,'proveedor_credito','obligacion',$id,['monto'=>$m,'referencia'=>$ref,'motivo'=>$motivo],['contabilidad']);return $id;
        });
    }
    public function recuperar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.obligaciones.pagar','proveedor_recuperar',$d,function()use($actor,$d){
            $id=$this->id($d['obligacion_id']??null);$o=$this->r->uno("SELECT * FROM erp_obligaciones WHERE id=? AND origen='compra' FOR UPDATE",[$id])??throw new ErpException('Factura de proveedor no encontrada.');
            $prev=(int)$this->r->uno('SELECT COALESCE(SUM(monto_centavos),0) n FROM erp_proveedor_reembolsos WHERE obligacion_id=?',[$id])['n'];$m=$this->importe($d['monto']??null);
            if($m>(int)$o['pagado_centavos']+(int)$o['ajuste_centavos']-(int)$o['monto_centavos']-$prev)throw new ErpException('No hay ese importe pendiente de recuperar del proveedor.');
            $ref=$this->texto($d['referencia']??'',120);$metodo=$d['metodo']??'';if(!in_array($metodo,['efectivo','transferencia'],true))throw new ErpException('Medio no válido.');$cajas=new ErpCajaService($this->r);$s=$cajas->sesion($actor);
            $caja=$cajas->asentar($actor,(int)$s['id'],'ingreso',$m,$metodo,'Reintegro proveedor #'.$id.' · '.$ref,'erp.prov.reembolso:'.$actor.':'.$d['idem_key']);
            $this->r->q('INSERT INTO erp_proveedor_reembolsos(obligacion_id,caja_id,monto_centavos,referencia,creado_en) VALUES(?,?,?,?,?)',[$id,$caja,$m,$ref,$this->r::ahora()]);$this->evento($actor,'proveedor_reintegro','obligacion',$id,['monto'=>$m,'referencia'=>$ref,'caja_id'=>$caja],['contabilidad']);return $id;
        });
    }
    public function cerrar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.compras','compra_cerrar',$d,function()use($actor,$d){$id=$this->id($d['compra_id']??null);$c=$this->compra($id);$motivo=$this->texto($d['motivo']??'',300);$anular=($d['decision']??'')==='anular';
            if(in_array($c['estado'],['cerrada','anulada'],true))throw new ErpException('La orden ya está cerrada.');
            if($anular&&($this->r->uno('SELECT id FROM erp_compra_lineas WHERE compra_id=? AND recibido_milesimas>0',[$id])||$this->r->uno('SELECT id FROM erp_obligaciones WHERE compra_id=?',[$id])))throw new ErpException('Una compra recibida o facturada se cierra conservando su historial.');
            if(!$anular&&$c['estado']!=='ordenada')throw new ErpException('Autoriza la orden antes de cerrarla.');
            $this->r->q('UPDATE erp_compras SET estado=? WHERE id=?',[$anular?'anulada':'cerrada',$id]);$this->evento($actor,$anular?'compra_anulada':'compra_cerrada','compra',$id,['motivo'=>$motivo]);return $id;
        });
    }
    private function compra(int $id):array{return $this->r->uno('SELECT * FROM erp_compras WHERE id=? FOR UPDATE',[$id])??throw new ErpException('Compra no encontrada.',404);}
}

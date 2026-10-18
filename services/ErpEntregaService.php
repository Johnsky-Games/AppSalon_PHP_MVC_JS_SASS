<?php
namespace Services;

final class ErpEntregaService extends ErpService
{
    public function entregar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.ventas.crear','entregar',$d,function()use($actor,$d){
            $id=$this->id($d['venta_id']??null);$ventas=new ErpVentaService($this->r);$v=$ventas->bloquear($id);
            if($v['estado']!=='confirmada'||$ventas->resumen($id)['saldo']>0)throw new ErpException('La venta debe estar confirmada y cobrada antes de entregar productos.');
            $lineas=$this->r->q('SELECT * FROM erp_venta_lineas WHERE venta_id=? AND producto_id IS NOT NULL ORDER BY producto_id,id',[$id]);
            foreach($lineas as $l){
                $qty=(int)$l['cantidad_milesimas']-(int)$l['entregado_milesimas']-(int)$l['devuelto_milesimas'];if($qty<=0)continue;
                $p=$this->r->uno('SELECT * FROM inventario_productos WHERE id=? FOR UPDATE',[(int)$l['producto_id']]);
                if(!$p||(int)$p['activo']!==1||(int)$p['stock_milesimas']<$qty)throw new ErpException('Stock insuficiente para '.$l['nombre'].'. No se entregó ningún producto.',409);
                $saldo=(int)$p['stock_milesimas']-$qty;
                $this->r->q('UPDATE inventario_productos SET stock_milesimas=? WHERE id=?',[$saldo,(int)$p['id']]);
                $clave='erp.entrega:'.$l['id'];
                $this->r->q("INSERT INTO inventario_movimientos(producto_id,actor_id,tipo,cantidad_milesimas,saldo_milesimas,costo_centavos,motivo,idempotencia,huella,registrado_en) VALUES(?,?,'salida',?,?,?,?,?,?,?)",[(int)$p['id'],$actor,$qty,$saldo,(int)$p['costo_centavos'],'Venta #'.$id,$clave,hash('sha256',$clave),$this->r::ahora()]);
                $mov=$this->r->id();$this->r->q("INSERT INTO erp_stock_origenes(movimiento_id,venta_linea_id,documento_tipo,documento_id) VALUES(?,?,'venta',?)",[$mov,(int)$l['id'],$id]);
                $this->r->q('UPDATE erp_venta_lineas SET entregado_milesimas=entregado_milesimas+? WHERE id=?',[$qty,(int)$l['id']]);
                $this->r->q("UPDATE erp_stock_reservas SET estado='consumida' WHERE linea_id=?",[(int)$l['id']]);
            }
            (new ErpComisionService($this->r))->sincronizar($id,$actor);
            $this->evento($actor,'productos_entregados','venta',$id,['lineas'=>$this->r->q('SELECT * FROM erp_venta_lineas WHERE venta_id=? AND producto_id IS NOT NULL ORDER BY id',[$id])],['contabilidad']);return $id;
        });
    }
}

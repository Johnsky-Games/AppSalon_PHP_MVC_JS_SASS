<?php
namespace Services;

final class ErpDevolucionService extends ErpService
{
    public function devolver(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.devolver','devolver_linea',$d,function()use($actor,$d){
            $id=$this->id($d['venta_id']??null);$ventas=new ErpVentaService($this->r);$v=$ventas->bloquear($id);
            if($v['estado']!=='confirmada')throw new ErpException('La venta no está confirmada.');
            $l=$this->r->uno('SELECT * FROM erp_venta_lineas WHERE venta_id=? AND id=? FOR UPDATE',[$id,$this->id($d['linea_id']??null)]);
            if(!$l)throw new ErpException('La línea no pertenece a esta venta.');
            $qty=ValoresExactos::decimal($d['cantidad']??null,3);$original=(int)$l['cantidad_milesimas'];$anterior=(int)$l['devuelto_milesimas'];
            if($qty+$anterior>$original)throw new ErpException('La cantidad supera lo que queda por devolver.');
            $motivo=$this->texto($d['motivo']??'',300);$stock=($d['reintegrar_stock']??'')==='1';
            $yaEntregadoDev=(int)$this->r->uno('SELECT COALESCE(SUM(cantidad_entregada_milesimas),0) n FROM erp_devoluciones WHERE linea_id=?',[(int)$l['id']])['n'];
            $entregadaAhora=min($qty,max(0,(int)$l['entregado_milesimas']-$yaEntregadoDev));
            if($stock&&(!$l['producto_id']||$entregadaAhora<$qty))throw new ErpException('Solo se reintegran productos que fueron entregados.');
            $antes=$ventas->resumen($id);if($antes['pendiente']>0)throw new ErpException('Verifica o rechaza las transferencias pendientes antes de devolver.');
            $total=(int)$l['total_centavos'];$monto=intdiv($total*($anterior+$qty)+intdiv($original,2),$original)-intdiv($total*$anterior+intdiv($original,2),$original);
            $aPagar=max(0,$antes['neto']-($antes['total']-$monto));
            $pagos=$this->r->q("SELECT p.* FROM pagos p JOIN erp_pago_aplicaciones a ON a.pago_id=p.id WHERE a.venta_id=? AND p.estado='completado' ORDER BY p.id FOR UPDATE",[$id]);
            $sesion=$aPagar>0?(new ErpCajaService($this->r))->sesion($actor):null;
            $this->r->q('INSERT INTO erp_devoluciones(venta_id,linea_id,cantidad_milesimas,monto_centavos,cantidad_entregada_milesimas,reintegrar_stock,motivo,actor_id,creado_en) VALUES(?,?,?,?,?,?,?,?,?)',[$id,(int)$l['id'],$qty,$monto,$entregadaAhora,$stock?1:0,$motivo,$actor,$this->r::ahora()]);
            $devolucion=$this->r->id();$restante=$aPagar;
            foreach($pagos as $p){
                if($restante<=0)break;
                $importe=CobroCalculo::centavos($p['monto']);$dev=(int)$this->r->uno('SELECT COALESCE(SUM(monto_centavos),0) monto FROM cobros_devoluciones WHERE pago_id=?',[(int)$p['id']])['monto'];
                $ahora=min($restante,max(0,$importe-$dev));if($ahora<=0)continue;
                if($p['metodo_pago']==='efectivo'&&(new ErpCajaService($this->r))->esperado((int)$sesion['id'])<$ahora)throw new ErpException('Tu caja no dispone del efectivo esperado para la devolución.');
                $this->r->q("INSERT INTO cobros_devoluciones(pago_id,cita_id,monto_centavos,metodo,motivo,actor_id,origen,registrado_en) VALUES(?,?,?,?,?,?,'caja',?)",[(int)$p['id'],$v['cita_id'],$ahora,$p['metodo_pago'],mb_substr($motivo,0,180),$actor,$this->r::ahora()]);
                $devId=$this->r->id();(new ErpCajaService($this->r))->asentar($actor,(int)$sesion['id'],'egreso',$ahora,$p['metodo_pago'],'Devolución venta #'.$id,'erp.dev:'.$devId,(int)$p['id'],$devId);
                if($dev+$ahora===$importe)$this->r->q("UPDATE pagos SET estado='reembolsado',reembolsado_at=? WHERE id=?",[$this->r::ahora(),(int)$p['id']]);
                // Los anticipos importados pueden tener devengos históricos; conservar su ajuste nativo.
                (new ComisionService($this->r))->revertirParcial((int)$p['id'],$devId,$importe,$dev,$dev+$ahora);
                $restante-=$ahora;
            }
            if($restante>0)throw new ErpException('No se pudo conciliar el importe por devolver.');
            if($l['producto_id']){
                $p=$this->r->uno('SELECT * FROM inventario_productos WHERE id=? FOR UPDATE',[(int)$l['producto_id']]);
                if($stock){
                    $saldo=(int)$p['stock_milesimas']+$qty;$clave='erp.devolucion:'.$devolucion;
                    $costo=intdiv((int)$p['stock_milesimas']*(int)$p['costo_centavos']+$qty*(int)$l['costo_centavos']+intdiv($saldo,2),$saldo);
                    $this->r->q('UPDATE inventario_productos SET stock_milesimas=?,costo_centavos=? WHERE id=?',[$saldo,$costo,(int)$p['id']]);
                    $this->r->q("INSERT INTO inventario_movimientos(producto_id,actor_id,tipo,cantidad_milesimas,saldo_milesimas,costo_centavos,motivo,idempotencia,huella,registrado_en) VALUES(?,?,'entrada',?,?,?,?,?,?,?)",[(int)$p['id'],$actor,$qty,$saldo,(int)$l['costo_centavos'],'Devolución venta #'.$id,$clave,hash('sha256',$clave),$this->r::ahora()]);
                    $this->r->q("INSERT INTO erp_stock_origenes(movimiento_id,venta_linea_id,documento_tipo,documento_id) VALUES(?,?,'devolucion',?)",[$this->r->id(),(int)$l['id'],$devolucion]);
                }
                if((int)$l['entregado_milesimas']===0){
                    if($anterior+$qty===$original)$this->r->q("UPDATE erp_stock_reservas SET estado='liberada' WHERE linea_id=?",[(int)$l['id']]);
                    else $this->r->q('UPDATE erp_stock_reservas SET cantidad_milesimas=? WHERE linea_id=?',[$original-$anterior-$qty,(int)$l['id']]);
                }
            }
            $this->r->q('UPDATE erp_venta_lineas SET devuelto_milesimas=devuelto_milesimas+? WHERE id=?',[$qty,(int)$l['id']]);
            (new ErpPagoService($this->r))->actualizarCita($id);(new ErpComisionService($this->r))->sincronizar($id,$actor);
            $evento=$this->evento($actor,'venta_devuelta','venta',$id,['devolucion_id'=>$devolucion,'linea_id'=>$l['id'],'linea_original'=>$l,'cantidad'=>$qty,'devuelto_anterior_milesimas'=>$anterior,'credito'=>$monto,'dinero_devuelto'=>$aPagar,'stock'=>$stock,'motivo'=>$motivo],['fiscal','contabilidad']);
            (new ErpReciboService($this->r))->emitir($actor,$id,$evento);return $id;
        });
    }
}

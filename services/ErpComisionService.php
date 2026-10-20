<?php
namespace Services;

final class ErpComisionService extends ErpService
{
    public function configurar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.comisiones','comision_regla',$d,function()use($actor,$d){
            $col=$this->id($d['colaborador_id']??null);if(!$this->r->uno("SELECT id FROM colaboradores WHERE id=? AND estado<>'baja' FOR UPDATE",[$col]))throw new ErpException('Colaborador no disponible.');
            $pb=ValoresExactos::decimal($d['porcentaje']??null,2,true);if($pb>10000)throw new ErpException('Porcentaje fuera de rango.');$desde=$this->fecha($d['desde']??null);
            $this->r->q('INSERT INTO erp_comision_reglas(colaborador_id,porcentaje_pb,desde,creado_por,creado_en) VALUES(?,?,?,?,?)',[$col,$pb,$desde,$actor,$this->r::ahora()]);
            $id=$this->r->id();$this->evento($actor,'regla_comision','colaborador',$col,['regla_id'=>$id,'porcentaje_pb'=>$pb,'desde'=>$desde]);return $id;
        });
    }
    /** Dentro de la transacción y bloqueo de la venta; la diferencia acumulada evita duplicados y deriva de centavos. */
    public function sincronizar(int $venta,int $actor):void
    {
        if(\Classes\Transaccion::nivel($this->r->db)===0)throw new \LogicException('La comisión requiere la transacción comercial.');
        $v=$this->r->uno('SELECT * FROM erp_ventas WHERE id=?',[$venta]);if(!$v||$v['estado']!=='confirmada')return;
        $s=(new ErpVentaService($this->r))->resumen($venta);$neto=max(0,min($s['neto'],$s['total']));
        $lineas=$this->r->q('SELECT * FROM erp_venta_lineas WHERE venta_id=? ORDER BY id',[$venta]);$acumulado=0;$asignado=0;$legacyBase=[];$legacyAsignado=[];
        $protegidas=[];$importeProtegido=0;$pagadoProtegido=0;
        foreach($this->r->q("SELECT a.linea_id,a.importe_centavos,p.estado FROM erp_paquete_aplicaciones a LEFT JOIN pagos p ON p.id=a.pago_id WHERE a.venta_id=? AND a.estado<>'restituida' FOR SHARE",[$venta]) as $a){$pagado=$a['estado']==='completado'?(int)$a['importe_centavos']:0;$protegidas[(int)$a['linea_id']]=$pagado;$importeProtegido+=(int)$a['importe_centavos'];$pagadoProtegido+=$pagado;}
        $totalLibre=max(0,$s['total']-$importeProtegido);$netoLibre=max(0,min($neto-$pagadoProtegido,$totalLibre));
        $cita=$v['cita_id']?$this->r->uno('SELECT * FROM citas WHERE id=?',[(int)$v['cita_id']]):null;
        foreach($lineas as $l){
            $qty=(int)$l['cantidad_milesimas'];$dev=(int)$l['devuelto_milesimas'];$original=(int)$l['total_centavos'];
            $vigente=$original-intdiv($original*$dev+intdiv($qty,2),$qty);
            if(array_key_exists((int)$l['id'],$protegidas))$pagadoLinea=$protegidas[(int)$l['id']];
            else{$acumulado+=$vigente;$cuota=$totalLibre>0?intdiv($netoLibre*$acumulado,$totalLibre):0;$pagadoLinea=$cuota-$asignado;$asignado=$cuota;}
            $movs=$this->r->q('SELECT * FROM erp_comision_movimientos WHERE linea_id=? ORDER BY id FOR UPDATE',[(int)$l['id']]);
            $prof=null;$col=$l['vendedor_colaborador_id'];$fecha=substr($v['confirmado_en']??$v['creado_en'],0,10);
            $elegible=$l['producto_id']?(int)$l['entregado_milesimas']>=$qty-$dev:($cita&&$cita['estado']==='completada');
            if($l['servicio_id']&&$cita){
                $pid=$movs?$l['profesional_id']:$cita['profesionalId'];$prof=$pid?$this->r->uno('SELECT id,colaborador_id FROM profesionales WHERE id=?',[(int)$pid]):null;$col=$prof['colaborador_id']??null;
                $fecha=substr($cita['atencion_efectiva_at']??($cita['atencion_fin_at']??$cita['fecha']),0,10);
                if(!$movs&&$pid)$this->r->q('UPDATE erp_venta_lineas SET profesional_id=? WHERE id=?',[(int)$pid,(int)$l['id']]);
            }
            if($movs){$col=(int)$movs[0]['colaborador_id'];$pb=(int)$movs[0]['porcentaje_pb'];}
            else{
                if(!$col)continue;
                $regla=$this->r->uno('SELECT porcentaje_pb FROM erp_comision_reglas WHERE colaborador_id=? AND desde<=? ORDER BY desde DESC,id DESC LIMIT 1',[(int)$col,$fecha]);
                $pb=$regla?(int)$regla['porcentaje_pb']:0;
            }
            $baseOriginal=$original-(int)$l['impuesto_centavos'];$baseVigente=$baseOriginal-intdiv($baseOriginal*$dev+intdiv($qty,2),$qty);
            $base=$vigente>0?intdiv($baseVigente*$pagadoLinea+intdiv($vigente,2),$vigente):0;
            $objetivo=$elegible?intdiv($base*$pb+5000,10000):0;
            // Los devengos de anticipos anteriores al PDV siguen en su libro original. No se devengan dos veces.
            if($prof&&$l['servicio_id']){
                $antiguos=(int)$this->r->uno("SELECT COALESCE(SUM(m.monto_centavos),0) n FROM comisiones_movimientos m JOIN erp_pago_aplicaciones a ON a.pago_id=m.pago_id WHERE a.venta_id=? AND a.origen='cita' AND m.profesional_id=? AND m.estado<>'anulada'",[$venta,(int)$prof['id']])['n'];
                $baseServicios=0;foreach($lineas as $otra)if($otra['servicio_id']&&(int)$otra['profesional_id']===(int)$prof['id'])$baseServicios+=(int)$otra['total_centavos']-(int)$otra['impuesto_centavos'];
                $pid=(int)$prof['id'];$legacyBase[$pid]=($legacyBase[$pid]??0)+$baseOriginal;
                $offset=$baseServicios>0?intdiv(max(0,$antiguos)*$legacyBase[$pid]+intdiv($baseServicios,2),$baseServicios):0;
                $objetivo-=$offset-($legacyAsignado[$pid]??0);$legacyAsignado[$pid]=$offset;
            }
            $ya=array_sum(array_map(static fn($m)=>(int)$m['monto_centavos'],$movs));$delta=$objetivo-$ya;
            if($delta===0||(!$movs&&$delta<0))continue;
            $this->r->q('INSERT INTO erp_comision_movimientos(venta_id,linea_id,colaborador_id,porcentaje_pb,monto_centavos,creado_en) VALUES(?,?,?,?,?,?)',[$venta,(int)$l['id'],(int)$col,$pb,$delta,$this->r::ahora()]);
            $this->evento($actor,'comision_ajustada','venta',$venta,['movimiento_id'=>$this->r->id(),'linea_id'=>$l['id'],'colaborador_id'=>$col,'delta'=>$delta]);
        }
        if($cita&&$cita['estado']==='completada'){
            (new ErpPaqueteService($this->r))->consumirVenta($venta,$actor);
            $this->r->q('UPDATE erp_ventas SET atencion_sync_hash=? WHERE id=?',[self::huellaAtencion($cita),$venta]);
        }
    }
    public function recalcular(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.comisiones','comision_recalcular',$d,function()use($actor,$d){$id=$this->id($d['venta_id']??null);(new ErpVentaService($this->r))->bloquear($id);$this->sincronizar($id,$actor);return $id;});
    }
    public function liquidar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.comisiones','comision_liquidar',$d,function()use($actor,$d){
            $col=$this->id($d['colaborador_id']??null);if(!$this->r->uno('SELECT id FROM colaboradores WHERE id=? FOR UPDATE',[$col]))throw new ErpException('Colaborador no encontrado.');
            $hasta=$this->fecha($d['hasta']??null);$via=$d['via']??'';if(!in_array($via,['directa','nomina'],true))throw new ErpException('Vía de liquidación no válida.');
            $movs=$this->r->q("SELECT * FROM erp_comision_movimientos WHERE colaborador_id=? AND estado='pendiente' AND (creado_en<DATE_ADD(?,INTERVAL 1 DAY) OR monto_centavos<0) ORDER BY id FOR UPDATE",[$col,$hasta]);
            $monto=array_sum(array_map(static fn($m)=>(int)$m['monto_centavos'],$movs));if($monto<=0||$monto>99999999)throw new ErpException('No hay saldo positivo liquidable; los ajustes se compensan con devengos futuros.');
            $this->r->q('INSERT INTO erp_comision_liquidaciones(colaborador_id,monto_centavos,via,creado_por,creado_en) VALUES(?,?,?,?,?)',[$col,$monto,$via,$actor,$this->r::ahora()]);$id=$this->r->id();$obligacion=null;
            if($via==='directa'){
                $this->r->q("INSERT INTO erp_obligaciones(colaborador_id,origen,referencia,monto_centavos,vence_en,creado_por,creado_en) VALUES(?,'comision',?,?,?,?,?)",[$col,'COM-ERP-'.$id,$monto,substr($this->r::ahora(),0,10),$actor,$this->r::ahora()]);$obligacion=$this->r->id();
                $this->r->q('UPDATE erp_comision_liquidaciones SET obligacion_id=? WHERE id=?',[$obligacion,$id]);
            }
            foreach($movs as $m)$this->r->q("UPDATE erp_comision_movimientos SET estado='asignada',liquidacion_id=? WHERE id=?",[$id,(int)$m['id']]);
            $this->evento($actor,'comision_liquidada','liquidacion',$id,['colaborador_id'=>$col,'monto'=>$monto,'via'=>$via,'obligacion_id'=>$obligacion,'movimientos'=>array_column($movs,'id')],[$via==='nomina'?'nomina':'contabilidad']);return $id;
        });
    }
    public static function huellaAtencion(array $c):string
    {return hash('sha256',json_encode([$c['estado'],$c['profesionalId'],$c['atencion_fin_at']??null,$c['atencion_efectiva_at']??null]));}
    /** Trabajo interno del supervisor; actor 0 identifica al sistema y no suplanta una cuenta. */
    public function procesarAtenciones():int
    {
        if(PHP_SAPI!=='cli')throw new ErpException('Procesamiento reservado al supervisor.',403);
        $filas=$this->r->q("SELECT v.id venta_id,v.atencion_sync_hash,c.estado,c.profesionalId,c.atencion_fin_at,c.atencion_efectiva_at FROM erp_ventas v JOIN citas c ON c.id=v.cita_id WHERE v.estado='confirmada' AND c.estado='completada' ORDER BY v.id");$n=0;
        foreach($filas as $c){
            if($c['atencion_sync_hash']===self::huellaAtencion($c))continue;
            \Classes\Transaccion::ejecutar($this->r->db,function()use($c){(new ErpVentaService($this->r))->bloquear((int)$c['venta_id']);$this->sincronizar((int)$c['venta_id'],0);});
            if(++$n>=100)break;
        }return $n;
    }
}

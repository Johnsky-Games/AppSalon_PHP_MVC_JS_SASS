<?php
namespace Services;
final class ErpPromocionService extends ErpService
{
    public function crear(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.promociones','promocion_crear',$d,function()use($actor,$d){
            $codigo=strtoupper($this->texto($d['codigo']??'',40));if(!preg_match('/^[A-Z0-9_-]{3,40}$/D',$codigo))throw new ErpException('Usa un código de 3 a 40 letras o números.');
            $nombre=$this->texto($d['nombre']??'',120);$desde=$this->fecha($d['desde']??null);$hasta=$this->fecha($d['hasta']??null);
            $pb=ValoresExactos::decimal($d['porcentaje']??null,2);$max=filter_var($d['max_usos']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000]]);$por=filter_var($d['max_por_cliente']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>1000000]]);
            if($hasta<$desde||$pb>10000||!$max||$por===false)throw new ErpException('Revisa vigencia, porcentaje y límites.');$min=$this->importe($d['minimo']??'0',true);
            $this->r->q('INSERT INTO erp_promociones(codigo,nombre,desde,hasta,porcentaje_pb,minimo_centavos,max_usos,max_por_cliente,creado_por,creado_en) VALUES(?,?,?,?,?,?,?,?,?,?)',[$codigo,$nombre,$desde,$hasta,$pb,$min,$max,$por,$actor,$this->r::ahora()]);
            $id=$this->r->id();$this->evento($actor,'promocion_creada','promocion',$id,['codigo'=>$codigo,'porcentaje_pb'=>$pb]);return $id;
        });
    }
    public function estado(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.promociones','promocion_estado',$d,function()use($actor,$d){$id=$this->id($d['promocion_id']??null);$this->r->uno('SELECT id FROM erp_promociones WHERE id=? FOR UPDATE',[$id])??throw new ErpException('Promoción no encontrada.');$activo=$d['activo']??null;if(!in_array($activo,['0','1'],true))throw new ErpException('Estado no válido.');$motivo=$this->texto($d['motivo']??'',300);$this->r->q('UPDATE erp_promociones SET activo=? WHERE id=?',[(int)$activo,$id]);$this->evento($actor,'promocion_estado','promocion',$id,['activo'=>(int)$activo,'motivo'=>$motivo]);return $id;});
    }
    public function aplicar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.ventas.crear','cupon_aplicar',$d,function()use($actor,$d){
            $id=$this->id($d['venta_id']??null);$v=(new ErpVentaService($this->r))->bloquear($id);if($v['estado']!=='borrador')throw new ErpException('La venta está cerrada para descuentos.');
            if($this->r->uno('SELECT venta_id FROM erp_promocion_usos WHERE venta_id=? AND estado<>\'liberado\'',[$id]))throw new ErpException('Retira el cupón anterior antes de aplicar otro.');
            $codigo=strtoupper($this->texto($d['codigo']??'',40));$p=$this->r->uno('SELECT * FROM erp_promociones WHERE codigo=? FOR UPDATE',[$codigo])??throw new ErpException('Cupón no encontrado.');
            $ident=$v['cliente_usuario_id']?'usuario:'.$v['cliente_usuario_id']:($v['contacto_id']?'contacto:'.$v['contacto_id']:'');$this->validar($p,$id,$ident);
            $lineas=$this->r->q('SELECT * FROM erp_venta_lineas WHERE venta_id=? ORDER BY id',[$id]);if(!$lineas)throw new ErpException('Añade conceptos antes de aplicar el cupón.');
            if(array_sum(array_column($lineas,'descuento_centavos'))>0)throw new ErpException('El cupón no se acumula con descuentos manuales.');
            $brutos=[];$suma=0;foreach($lineas as $l){$bruto=ErpCalculo::linea((int)$l['cantidad_milesimas'],(int)$l['precio_centavos'],0,0)['subtotal'];$brutos[(int)$l['id']]=$bruto;$suma+=$bruto;}
            if($suma<(int)$p['minimo_centavos']||$suma===0)throw new ErpException('La venta no alcanza el mínimo de la promoción.');
            $totalDes=intdiv($suma*(int)$p['porcentaje_pb']+5000,10000);$asignado=0;$acumulado=0;$total=0;$iva=0;
            foreach($lineas as $l){
                $acumulado+=$brutos[(int)$l['id']];$objetivo=intdiv($totalDes*$acumulado,$suma);$des=$objetivo-$asignado;$asignado=$objetivo;
                $c=ErpCalculo::linea((int)$l['cantidad_milesimas'],(int)$l['precio_centavos'],$des,(int)$l['impuesto_pb']);$total+=$c['total'];$iva+=$c['impuesto'];
                $this->r->q('UPDATE erp_venta_lineas SET descuento_centavos=?,impuesto_centavos=?,total_centavos=? WHERE id=?',[$des,$c['impuesto'],$c['total'],(int)$l['id']]);
            }
            if((new ErpVentaService($this->r))->resumen($id)['neto']>$total)throw new ErpException('Resuelve el anticipo antes de descontar por debajo de lo cobrado.');
            $this->r->q('UPDATE erp_ventas SET total_centavos=?,subtotal_centavos=?,descuento_centavos=?,impuesto_centavos=?,version=version+1 WHERE id=?',[$total,$suma,$totalDes,$iva,$id]);
            $this->r->q("INSERT INTO erp_promocion_usos(venta_id,promocion_id,identidad,estado,expira_en,regla_json,descuento_centavos) VALUES(?,?,?,'reservado',DATE_ADD(?,INTERVAL 15 MINUTE),?,?) ON DUPLICATE KEY UPDATE promocion_id=VALUES(promocion_id),identidad=VALUES(identidad),estado='reservado',expira_en=VALUES(expira_en),regla_json=VALUES(regla_json),descuento_centavos=VALUES(descuento_centavos)",[$id,(int)$p['id'],$ident,$this->r::ahora(),json_encode($p,JSON_THROW_ON_ERROR),$totalDes]);
            $this->evento($actor,'cupon_aplicado','venta',$id,['promocion_id'=>$p['id'],'descuento'=>$totalDes]);return $id;
        });
    }
    public function confirmar(int $venta):void
    {
        $u=$this->r->uno("SELECT * FROM erp_promocion_usos WHERE venta_id=? AND estado='reservado'",[$venta]);if(!$u)return;
        $p=$this->r->uno('SELECT * FROM erp_promociones WHERE id=? FOR UPDATE',[(int)$u['promocion_id']]);$this->validar($p,$venta,$u['identidad']);
        $this->r->q("UPDATE erp_promocion_usos SET estado='usado' WHERE venta_id=?",[$venta]);
    }
    public function quitar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.ventas.crear','cupon_quitar',$d,function()use($actor,$d){
            $id=$this->id($d['venta_id']??null);$v=(new ErpVentaService($this->r))->bloquear($id);if($v['estado']!=='borrador')throw new ErpException('La venta está cerrada.');
            $u=$this->r->uno("SELECT venta_id FROM erp_promocion_usos WHERE venta_id=? AND estado='reservado'",[$id]);if(!$u)throw new ErpException('La venta no tiene un cupón reservado.');
            $this->liberar($id);$total=0;$subtotal=0;$iva=0;
            foreach($this->r->q('SELECT * FROM erp_venta_lineas WHERE venta_id=? ORDER BY id',[$id]) as $l){
                $c=ErpCalculo::linea((int)$l['cantidad_milesimas'],(int)$l['precio_centavos'],0,(int)$l['impuesto_pb']);$total+=$c['total'];$subtotal+=$c['subtotal'];$iva+=$c['impuesto'];
                $this->r->q('UPDATE erp_venta_lineas SET descuento_centavos=0,impuesto_centavos=?,total_centavos=? WHERE id=?',[$c['impuesto'],$c['total'],(int)$l['id']]);
            }
            $this->r->q('UPDATE erp_ventas SET total_centavos=?,subtotal_centavos=?,impuesto_centavos=?,descuento_centavos=0,version=version+1 WHERE id=?',[$total,$subtotal,$iva,$id]);
            $this->evento($actor,'cupon_retirado','venta',$id,[]);return $id;
        });
    }
    public function liberar(int $venta):void
    {
        $u=$this->r->uno('SELECT promocion_id FROM erp_promocion_usos WHERE venta_id=?',[$venta]);if(!$u)return;
        $this->r->uno('SELECT id FROM erp_promociones WHERE id=? FOR UPDATE',[(int)$u['promocion_id']]);
        $this->r->q("UPDATE erp_promocion_usos SET estado='liberado' WHERE venta_id=?",[$venta]);
    }
    private function validar(array $p,int $venta,string $identidad):void
    {
        $hoy=substr($this->r::ahora(),0,10);if(!(int)$p['activo']||$hoy<$p['desde']||$hoy>$p['hasta'])throw new ErpException('El cupón no está vigente.');
        if((int)$p['max_por_cliente']>0&&$identidad==='')throw new ErpException('Selecciona un comprador identificado para usar este cupón.');
        $usos=$this->r->q("SELECT identidad FROM erp_promocion_usos WHERE promocion_id=? AND venta_id<>? AND (estado='usado' OR (estado='reservado' AND expira_en>?)) FOR SHARE",[(int)$p['id'],$venta,$this->r::ahora()]);
        if(count($usos)>=(int)$p['max_usos'])throw new ErpException('El cupón agotó sus usos.',409);
        if((int)$p['max_por_cliente']>0&&count(array_filter($usos,static fn($u)=>$u['identidad']===$identidad))>=(int)$p['max_por_cliente'])throw new ErpException('El comprador agotó sus usos de este cupón.',409);
    }
}

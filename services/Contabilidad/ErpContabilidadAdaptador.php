<?php
namespace Services\Contabilidad;

use Classes\Transaccion;
use Interfaces\IntegracionErpInterface;
use Repositories\ErpRepository;
use Services\{CobroCalculo,ErpContabilidadService,ErpException,ErpVentaService};

/**
 * Traduce los hechos económicos confirmados del ERP en asientos, sin que los servicios de negocio conozcan la contabilidad.
 * Cada evento del outbox tiene identidad permanente (`outbox:<referencia>#n`): reprocesarlo no duplica y consultarlo recupera el asiento.
 * Hechos distintos, asientos distintos: cobro, entrega (ingreso y costo), prestación, recepción, factura del proveedor, pago, devolución y reembolso.
 * Las reglas son una política contable de ensayo que el contador debe validar; los saldos se concilian contra los auxiliares del negocio.
 */
final class ErpContabilidadAdaptador implements IntegracionErpInterface
{
    /**
     * Hechos que se confirman sin asiento A PROPÓSITO. La venta confirmada es un compromiso comercial: el ingreso nace con la entrega del producto o la
     * prestación del servicio, y el dinero con el cobro. Los eventos desconocidos, en cambio, fallan a la vista para no perder ningún hecho económico.
     */
    private const SIN_ASIENTO=['venta_confirmada'];
    private ErpContabilidadService $c;
    public function __construct(private ?ErpRepository $r=null)
    {
        $this->r??=new ErpRepository();$this->c=new ErpContabilidadService($this->r);
    }
    private function hoy():string {return (new \DateTimeImmutable('now',new \DateTimeZone('America/Guayaquil')))->format('Y-m-d');}
    private function inicio():?string {return $this->c->config()['inicio_contable']??null;}
    private function dinero(string $texto):int {return CobroCalculo::centavos($texto);}
    /** Proporción redondeada al centavo: valor × parte / total. */
    private static function parte(int $valor,int $parte,int $total):int {return $total>0?intdiv($valor*$parte+intdiv($total,2),$total):0;}
    private function metodo(string $metodo):string
    {
        return match($metodo){'efectivo'=>'caja','transferencia'=>'banco',default=>throw new ErpException('Medio de cobro o pago sin cuenta contable: '.$metodo.'.',409)};
    }

    public function consultar(string $referencia):?array
    {
        $a=$this->r->uno('SELECT numero FROM erp_cont_asientos WHERE origen=?',['outbox:'.$referencia.'#1']);
        if($a)return ['estado'=>'confirmado','referencia_externa'=>'AS-'.$a['numero']];
        $inicio=$this->inicio();
        if($inicio){
            $e=$this->r->uno('SELECT e.creado_en FROM erp_integraciones_outbox o JOIN erp_eventos e ON e.id=o.evento_id WHERE o.referencia=?',[$referencia]);
            if($e&&substr($e['creado_en'],0,10)<$inicio)return ['estado'=>'confirmado','referencia_externa'=>'previo-a-inicio-contable'];
        }
        return null;
    }
    public function enviar(string $referencia,array $documento):array
    {
        $inicio=$this->inicio();if(!$inicio)throw new ErpException('Define el inicio contable antes de contabilizar.',409);
        $fecha=substr((string)($documento['creado_en']??''),0,10);if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$fecha))throw new \InvalidArgumentException('Evento sin fecha.');
        if($fecha<$inicio)return ['estado'=>'confirmado','referencia_externa'=>'previo-a-inicio-contable'];
        if(in_array($documento['tipo']??'',self::SIN_ASIENTO,true))return ['estado'=>'confirmado','referencia_externa'=>'sin-asiento:'.$documento['tipo']];
        $numero=Transaccion::ejecutar($this->r->db,function()use($referencia,$documento,$fecha,$inicio){
            $asientos=$this->reglas($documento);if($asientos===null)throw new ErpException('El evento «'.($documento['tipo']??'?').'» no tiene regla contable.',409);
            if(!$asientos)return null;
            // La nómina se contabiliza en el mes que devenga (fin de mes) cuando ese mes es posterior al inicio contable; el resto, el día del hecho.
            $base=$fecha;$devengo=$documento['datos']['fecha']??null;
            if(in_array($documento['tipo']??'',['nomina_aprobada','utilidades_aprobadas','depreciacion_contabilizada','activo_baja'],true)&&is_string($devengo)&&preg_match('/^\d{4}-\d{2}-\d{2}$/D',$devengo)&&$devengo>=$inicio&&$devengo<=$fecha)$base=$devengo;
            $fechaContable=$this->fechaContable($base);$primero=null;
            foreach($asientos as $i=>$a){
                $spec=['fecha'=>$fechaContable,'tipo'=>'automatico','origen'=>'outbox:'.$referencia.'#'.($i+1),'concepto'=>mb_substr($a['concepto'].($fechaContable!==$base?' (hecho del '.$base.')':''),0,240),'lineas'=>$this->lineas($a['lineas'])];
                if(isset($a['reversa_de'])){$spec['tipo']='reverso';$spec['reversa_de_id']=(int)$a['reversa_de'];}
                $id=$this->c->publicar(0,$spec);
                $primero??=$id;
            }
            return (int)$this->r->uno('SELECT numero FROM erp_cont_asientos WHERE id=?',[$primero])['numero'];
        });
        if($numero===null)return ['estado'=>'confirmado','referencia_externa'=>'sin-asiento:'.($documento['tipo']??'')];
        return ['estado'=>'confirmado','referencia_externa'=>'AS-'.$numero];
    }
    /** Un hecho de un período ya cerrado se registra hoy (contabilización tardía); el concepto conserva su fecha original. */
    private function fechaContable(string $fecha):string
    {
        $p=$this->r->uno('SELECT estado FROM erp_cont_periodos WHERE anio=? AND mes=?',[(int)substr($fecha,0,4),(int)substr($fecha,5,2)]);
        return ($p&&$p['estado']==='cerrado')?max($fecha,$this->hoy()):$fecha;
    }
    /** @param list<array{rol:string,debe?:int,haber?:int,contacto_id?:?int,referencia?:?string}> $lineas */
    private function lineas(array $lineas):array
    {
        $salida=[];
        foreach($lineas as $l){
            $d=(int)($l['debe']??0);$h=(int)($l['haber']??0);if($d===0&&$h===0)continue;
            $salida[]=['cuenta_id'=>isset($l['cuenta_id'])?(int)$l['cuenta_id']:$this->c->rolCuenta($l['rol']),'debe'=>$d,'haber'=>$h,'contacto_id'=>$l['contacto_id']??null,'referencia'=>$l['referencia']??null];
        }
        return $salida;
    }

    // ---------------------------------------------------------------- reglas por evento
    /** @return ?list<array{concepto:string,lineas:list<array>}> null = sin regla (falla a la vista); [] = nada que contabilizar */
    private function reglas(array $e):?array
    {
        $d=$e['datos']??[];$id=(int)($e['entidad_id']??0);
        return match($e['tipo']??''){
            'pago_confirmado','transferencia_confirmada'=>$this->cobroVenta($id,(int)$d['pago_id']),
            'prepago_cargado'=>$this->cargaPrepago((int)$d['carga_id']),
            'prepago_aplicado'=>$this->prepagoAplicado($id,(int)$d['prepago_id'],(int)$d['monto']),
            'prepago_reembolsado'=>$this->reembolsoPrepago($id,(int)$d['monto'],(string)$d['metodo']),
            'productos_entregados'=>$this->entrega($id,$d['entregas']??[]),
            'venta_devuelta'=>$this->devolucion($id,$d),
            'insumos_consumidos'=>$this->insumos($id,$d['receta']??[]),
            'compra_recibida'=>$this->recepcionCompra($id,(int)$d['linea_id'],(int)$d['cantidad']),
            'compra_devuelta'=>$this->devolucionCompra($id,(int)$d['linea_id'],(int)$d['cantidad']),
            'factura_proveedor'=>$this->facturaProveedor($id,(int)$d['obligacion_id'],(int)$d['importe']),
            'proveedor_credito'=>$this->creditoProveedor($id,(int)$d['monto']),
            'proveedor_reintegro'=>$this->reintegroProveedor($id,(int)$d['monto'],(int)$d['caja_id']),
            'obligacion_pagada'=>$this->pagoObligacion($id,(int)$d['monto'],(string)$d['metodo']),
            'comision_liquidada'=>$this->comision($id,(int)$d['monto']),
            'activo_registrado'=>[['concepto'=>'Activo registrado '.($d['codigo']??''),'lineas'=>[['rol'=>'activos_fijos','debe'=>(int)$d['costo']],['rol'=>'recepciones_por_facturar','haber'=>(int)$d['costo']]]]],
            'inventario_ajuste'=>$this->ajusteInventario((int)$d['movimiento_id'],(string)$d['tipo']),
            'caja_movimiento'=>$this->cajaManual((int)$d['movimiento_id'],(string)$d['tipo'],(int)$d['monto']),
            'caja_movimiento_reversado'=>$this->cajaReverso($d),
            'depreciacion_contabilizada'=>[['concepto'=>'Depreciación de activos fijos '.($d['periodo']??''),'lineas'=>[['rol'=>'gasto_depreciacion','debe'=>(int)$d['total'],'referencia'=>'depreciación '.($d['periodo']??'')],['rol'=>'depreciacion_acumulada','haber'=>(int)$d['total'],'referencia'=>'depreciación '.($d['periodo']??'')]]]],
            'depreciacion_anulada'=>$this->nominaAnulada($id,$d,'depreciacion'),
            'activo_baja'=>$this->activoBaja($id,$d),
            'activo_baja_anulada'=>$this->nominaAnulada($id,$d,'baja'),
            'nomina_aprobada'=>$this->nominaAprobada($id,$d),
            'nomina_anulada'=>$this->nominaAnulada($id,$d),
            'finiquito_aprobado'=>$this->finiquitoAprobado($id,$d),
            'utilidades_aprobadas'=>[['concepto'=>'Participación de trabajadores en las utilidades '.(int)$d['anio'],'lineas'=>[['rol'=>'gasto_utilidades','debe'=>(int)$d['total'],'referencia'=>'utilidades '.$id],['rol'=>'sueldos_por_pagar','haber'=>(int)$d['total'],'referencia'=>'utilidades '.$id]]]],
            'utilidades_anuladas'=>$this->nominaAnulada($id,$d,'utilidades'),
            'finiquito_anulado'=>$this->nominaAnulada($id,$d,'finiquito'),
            'nomina_beneficio_liquidado'=>[['concepto'=>'Liquidación de '.str_replace('_',' ',(string)$d['tipo']).' del colaborador '.(int)$d['colaborador_id'],'lineas'=>[['rol'=>'beneficios_por_pagar','debe'=>(int)$d['monto']],['rol'=>'sueldos_por_pagar','haber'=>(int)$d['monto'],'referencia'=>'obligación '.(int)$d['obligacion_id']]]]],
            default=>null,
        };
    }
    /** Tercero de la venta: el contacto de mostrador o, en ventas de una cita, el contacto vinculado a la cuenta del cliente (si lo hay). */
    private function ventaCliente(int $venta):?int
    {
        $v=$this->r->uno('SELECT contacto_id,cliente_usuario_id FROM erp_ventas WHERE id=?',[$venta])??throw new ErpException('Venta no encontrada.',404);
        if($v['contacto_id'])return (int)$v['contacto_id'];
        $c=$v['cliente_usuario_id']?$this->r->uno('SELECT id FROM erp_contactos WHERE usuario_id=?',[(int)$v['cliente_usuario_id']]):null;return $c?(int)$c['id']:null;
    }
    /** Cobro: el dinero entra y reduce la cuenta por cobrar del cliente (si aún no hay ingreso, queda como saldo a favor). */
    private function cobroVenta(int $venta,int $pago):array
    {
        $p=$this->r->uno('SELECT monto,metodo_pago FROM pagos WHERE id=?',[$pago])??throw new ErpException('Pago no encontrado.',404);$monto=$this->dinero($p['monto']);$cli=$this->ventaCliente($venta);
        return [['concepto'=>'Cobro de la venta '.$venta,'lineas'=>[['rol'=>$this->metodo($p['metodo_pago']),'debe'=>$monto,'referencia'=>'pago '.$pago],['rol'=>'cxc','haber'=>$monto,'contacto_id'=>$cli,'referencia'=>'venta '.$venta]]]];
    }
    private function prepagoContacto(int $prepago):?int
    {
        $p=$this->r->uno('SELECT contacto_id FROM erp_prepagos WHERE id=?',[$prepago])??throw new ErpException('Prepago no encontrado.',404);return (int)$p['contacto_id'];
    }
    /** Recibir dinero para una cuenta prepagada no es una venta: se registra como anticipo de cliente. */
    private function cargaPrepago(int $carga):array
    {
        $c=$this->r->uno('SELECT * FROM erp_prepago_cargas WHERE id=?',[$carga])??throw new ErpException('Carga no encontrada.',404);$monto=(int)$c['monto_centavos'];
        return [['concepto'=>'Anticipo de cliente (carga '.$carga.')','lineas'=>[['rol'=>$this->metodo($c['metodo']),'debe'=>$monto,'referencia'=>'carga '.$carga],['rol'=>'anticipos_clientes','haber'=>$monto,'contacto_id'=>$this->prepagoContacto((int)$c['prepago_id']),'referencia'=>'prepago '.$c['prepago_id']]]]];
    }
    /** Aplicar el anticipo a una venta cancela la cuenta por cobrar sin mover dinero. */
    private function prepagoAplicado(int $venta,int $prepago,int $monto):array
    {
        return [['concepto'=>'Aplicación de anticipo a la venta '.$venta,'lineas'=>[['rol'=>'anticipos_clientes','debe'=>$monto,'contacto_id'=>$this->prepagoContacto($prepago),'referencia'=>'prepago '.$prepago],['rol'=>'cxc','haber'=>$monto,'contacto_id'=>$this->ventaCliente($venta),'referencia'=>'venta '.$venta]]]];
    }
    private function reembolsoPrepago(int $prepago,int $monto,string $metodo):array
    {
        return [['concepto'=>'Reembolso de anticipo del prepago '.$prepago,'lineas'=>[['rol'=>'anticipos_clientes','debe'=>$monto,'contacto_id'=>$this->prepagoContacto($prepago),'referencia'=>'prepago '.$prepago],['rol'=>$this->metodo($metodo),'haber'=>$monto]]]];
    }
    /** Entrega de productos: dos hechos, el ingreso (contra la cuenta por cobrar) y el costo (contra el inventario). */
    private function entrega(int $venta,array $entregas):array
    {
        $total=0;$iva=0;$costo=0;$cli=$this->ventaCliente($venta);
        foreach($entregas as $e){
            $l=$this->r->uno('SELECT * FROM erp_venta_lineas WHERE id=? AND venta_id=?',[(int)$e['linea_id'],$venta])??throw new ErpException('Línea de venta no encontrada.',404);
            $q=(int)$e['cantidad_milesimas'];$t=self::parte((int)$l['total_centavos'],$q,(int)$l['cantidad_milesimas']);$total+=$t;$iva+=self::parte((int)$l['impuesto_centavos'],$q,(int)$l['cantidad_milesimas']);
            $costo+=self::parte((int)$e['costo_centavos'],$q,1000);
        }
        $r=[['concepto'=>'Entrega de productos de la venta '.$venta,'lineas'=>[['rol'=>'cxc','debe'=>$total,'contacto_id'=>$cli,'referencia'=>'venta '.$venta],['rol'=>'ingreso_productos','haber'=>$total-$iva],['rol'=>'iva_ventas','haber'=>$iva]]]];
        if($costo>0)$r[]=['concepto'=>'Costo de los productos entregados de la venta '.$venta,'lineas'=>[['rol'=>'costo_ventas','debe'=>$costo],['rol'=>'inventario','haber'=>$costo]]];
        return $r;
    }
    /**
     * Devolución comercial: (1) reverso del ingreso solo por lo que ya se había reconocido (producto entregado o servicio prestado),
     * (2) reintegro de existencias al costo, (3) cada devolución de dinero o restitución de saldo como hecho propio.
     */
    private function devolucion(int $venta,array $d): array
    {
        $l=$d['linea_original']??[];$cli=$this->ventaCliente($venta);$r=[];$credito=(int)$d['credito'];$qty=(int)$d['cantidad'];
        $dev=$this->r->uno('SELECT creado_en,cantidad_entregada_milesimas FROM erp_devoluciones WHERE id=?',[(int)$d['devolucion_id']])??throw new ErpException('Devolución no encontrada.',404);
        if(!empty($l['producto_id']))$reconocido=self::parte($credito,min($qty,(int)($d['entregada_ahora']??$dev['cantidad_entregada_milesimas'])),$qty);
        else{
            $c=$this->r->uno('SELECT c.estado,c.atencion_fin_at,c.atencion_efectiva_at FROM erp_ventas v JOIN citas c ON c.id=v.cita_id WHERE v.id=?',[$venta]);
            $fin=$c['atencion_fin_at']??$c['atencion_efectiva_at']??null;
            $reconocido=($c&&$c['estado']==='completada'&&(!$fin||$dev['creado_en']>=$fin))?$credito:0;
        }
        if($reconocido>0){
            $iva=self::parte($reconocido,(int)$l['impuesto_centavos'],(int)$l['total_centavos']);
            $r[]=['concepto'=>'Devolución sobre la venta '.$venta,'lineas'=>[['rol'=>'devoluciones_ventas','debe'=>$reconocido-$iva],['rol'=>'iva_ventas','debe'=>$iva],['rol'=>'cxc','haber'=>$reconocido,'contacto_id'=>$cli,'referencia'=>'devolución '.$d['devolucion_id']]]];
        }
        if(!empty($d['stock'])&&!empty($l['producto_id'])){
            $costo=self::parte((int)$l['costo_centavos'],$qty,1000);
            if($costo>0)$r[]=['concepto'=>'Reintegro de existencias de la venta '.$venta,'lineas'=>[['rol'=>'inventario','debe'=>$costo],['rol'=>'costo_ventas','haber'=>$costo]]];
        }
        foreach($d['reembolsos']??[] as $x){
            $m=(int)$x['monto'];
            if(($x['metodo']??'')==='prepago'){
                $mov=$this->r->uno('SELECT prepago_id FROM erp_prepago_movimientos WHERE devolucion_id=? AND tipo=\'restitucion\'',[(int)$x['cobro_devolucion_id']]);
                $r[]=['concepto'=>'Restitución de saldo por la devolución '.$d['devolucion_id'],'lineas'=>[['rol'=>'cxc','debe'=>$m,'contacto_id'=>$cli,'referencia'=>'devolución '.$d['devolucion_id']],['rol'=>'anticipos_clientes','haber'=>$m,'contacto_id'=>$mov?$this->prepagoContacto((int)$mov['prepago_id']):$cli]]];
            }else $r[]=['concepto'=>'Devolución de dinero de la venta '.$venta,'lineas'=>[['rol'=>'cxc','debe'=>$m,'contacto_id'=>$cli,'referencia'=>'devolución '.$d['devolucion_id']],['rol'=>$this->metodo($x['metodo']),'haber'=>$m]]];
        }
        return $r;
    }
    private function insumos(int $consumo,array $receta):array
    {
        $costo=0;foreach($receta as $l){
            $m=$this->r->uno('SELECT cantidad_milesimas,costo_centavos FROM inventario_movimientos WHERE idempotencia=?',['erp.consumo:'.$consumo.':'.(int)$l['producto_id']])??throw new ErpException('Movimiento de consumo no encontrado.',404);
            $costo+=self::parte((int)$m['costo_centavos'],(int)$m['cantidad_milesimas'],1000);
        }
        return $costo>0?[['concepto'=>'Consumo de insumos del servicio '.$consumo,'lineas'=>[['rol'=>'consumo_insumos','debe'=>$costo],['rol'=>'inventario','haber'=>$costo]]]]:[];
    }
    // Compras: recepción (inventario contra recepciones por facturar), factura (la recepción pasa a cuentas por pagar), pago y devoluciones.
    private function costoLinea(int $linea,int $cantidad):int
    {
        $l=$this->r->uno('SELECT costo_centavos FROM erp_compra_lineas WHERE id=?',[$linea])??throw new ErpException('Línea de compra no encontrada.',404);return self::parte((int)$l['costo_centavos'],$cantidad,1000);
    }
    private function recepcionCompra(int $compra,int $linea,int $cantidad):array
    {
        $m=$this->costoLinea($linea,$cantidad);return [['concepto'=>'Recepción de la compra '.$compra,'lineas'=>[['rol'=>'inventario','debe'=>$m],['rol'=>'recepciones_por_facturar','haber'=>$m,'referencia'=>'compra '.$compra]]]];
    }
    private function devolucionCompra(int $compra,int $linea,int $cantidad):array
    {
        $m=$this->costoLinea($linea,$cantidad);return [['concepto'=>'Devolución al proveedor de la compra '.$compra,'lineas'=>[['rol'=>'recepciones_por_facturar','debe'=>$m,'referencia'=>'compra '.$compra],['rol'=>'inventario','haber'=>$m]]]];
    }
    private function proveedorDeCompra(int $compra):?int
    {
        $c=$this->r->uno('SELECT proveedor_id FROM erp_compras WHERE id=?',[$compra])??throw new ErpException('Compra no encontrada.',404);return $c['proveedor_id']?(int)$c['proveedor_id']:null;
    }
    /**
     * La factura del proveedor lleva la recepción a cuentas por pagar. Mientras la factura no registre su desglose, el IVA pagado se estima en la misma
     * proporción que la orden de compra (exacto cuando la factura coincide con la orden); el resto cancela lo recibido.
     */
    private function facturaProveedor(int $compra,int $obligacion,int $importe):array
    {
        $total=0;$iva=0;foreach($this->r->q('SELECT * FROM erp_compra_lineas WHERE compra_id=?',[$compra]) as $l){$c=\Services\ErpCalculo::linea((int)$l['cantidad_milesimas'],(int)$l['costo_centavos'],0,(int)$l['impuesto_pb']);$total+=$c['total'];$iva+=$c['impuesto'];}
        $ivaFactura=self::parte($importe,$iva,$total);
        return [['concepto'=>'Factura del proveedor de la compra '.$compra,'lineas'=>[['rol'=>'recepciones_por_facturar','debe'=>$importe-$ivaFactura,'referencia'=>'compra '.$compra],['rol'=>'iva_compras','debe'=>$ivaFactura],['rol'=>'proveedores','haber'=>$importe,'contacto_id'=>$this->proveedorDeCompra($compra),'referencia'=>'obligación '.$obligacion]]]];
    }
    private function obligacion(int $id):array {return $this->r->uno('SELECT * FROM erp_obligaciones WHERE id=?',[$id])??throw new ErpException('Obligación no encontrada.',404);}
    private function pagoObligacion(int $id,int $monto,string $metodo):array
    {
        $o=$this->obligacion($id);$rol=match($o['origen']){'comision'=>'comisiones_por_pagar','nomina','nomina_beneficio','finiquito','utilidades'=>'sueldos_por_pagar','iess'=>'iess_por_pagar','sri_ir'=>'ir_por_pagar',default=>'proveedores'};
        return [['concepto'=>'Pago de la obligación '.$id,'lineas'=>[['rol'=>$rol,'debe'=>$monto,'contacto_id'=>$rol==='proveedores'&&$o['contacto_id']?(int)$o['contacto_id']:null,'referencia'=>'obligación '.$id],['rol'=>$this->metodo($metodo),'haber'=>$monto]]]];
    }
    private function creditoProveedor(int $id,int $monto):array
    {
        $o=$this->obligacion($id);
        return [['concepto'=>'Crédito del proveedor sobre la obligación '.$id,'lineas'=>[['rol'=>'proveedores','debe'=>$monto,'contacto_id'=>$o['contacto_id']?(int)$o['contacto_id']:null,'referencia'=>'obligación '.$id],['rol'=>'recepciones_por_facturar','haber'=>$monto]]]];
    }
    private function reintegroProveedor(int $id,int $monto,int $caja):array
    {
        $o=$this->obligacion($id);$c=$this->r->uno('SELECT metodo FROM caja_movimientos WHERE id=?',[$caja])??throw new ErpException('Movimiento de caja no encontrado.',404);
        return [['concepto'=>'Reintegro del proveedor sobre la obligación '.$id,'lineas'=>[['rol'=>$this->metodo($c['metodo']),'debe'=>$monto],['rol'=>'proveedores','haber'=>$monto,'contacto_id'=>$o['contacto_id']?(int)$o['contacto_id']:null,'referencia'=>'obligación '.$id]]]];
    }
    /** La comisión liquidada por pago directo es un gasto con obligación; la que va por nómina no pasa por aquí (se contabiliza con la nómina). */
    private function comision(int $liquidacion,int $monto):array
    {
        return [['concepto'=>'Comisión liquidada '.$liquidacion,'lineas'=>[['rol'=>'gasto_comisiones','debe'=>$monto,'referencia'=>'liquidación '.$liquidacion],['rol'=>'comisiones_por_pagar','haber'=>$monto,'referencia'=>'liquidación '.$liquidacion]]]];
    }

    // Nómina: gasto de sueldos, comisiones, beneficios y aportes patronales contra lo que se debe al colaborador, al IESS, al SRI y a los beneficios acumulados.
    private function nominaAprobada(int $corrida,array $t):array
    {
        return [['concepto'=>'Nómina '.($t['periodo']??'').' (corrida '.$corrida.')','lineas'=>[
            ['rol'=>'gasto_sueldos','debe'=>(int)$t['sueldos'],'referencia'=>'corrida '.$corrida],['rol'=>'gasto_comisiones','debe'=>(int)$t['comisiones']],['rol'=>'gasto_beneficios','debe'=>(int)$t['beneficios_gasto']],['rol'=>'gasto_aportes','debe'=>(int)$t['patronal']],
            ['rol'=>'sueldos_por_pagar','haber'=>(int)$t['neto']],['rol'=>'iess_por_pagar','haber'=>(int)$t['iess']],['rol'=>'ir_por_pagar','haber'=>(int)$t['ir']],['rol'=>'beneficios_por_pagar','haber'=>(int)$t['prov']],['rol'=>'descuentos_nomina','haber'=>(int)$t['descuentos']]]]];
    }
    // Finiquito: los décimos acumulados saldan la provisión (beneficios por pagar); vacaciones, desahucio e indemnización son gasto de beneficios; lo que se paga al colaborador es el neto.
    private function finiquitoAprobado(int $finiquito,array $d):array
    {
        return [['concepto'=>'Finiquito '.$finiquito.' (colaborador '.(int)$d['colaborador_id'].')','lineas'=>[
            ['rol'=>'beneficios_por_pagar','debe'=>(int)$d['d13']+(int)$d['d14'],'referencia'=>'finiquito '.$finiquito],['rol'=>'gasto_beneficios','debe'=>(int)$d['gasto_beneficios']],['rol'=>'gasto_sueldos','debe'=>(int)$d['otros']],
            ['rol'=>'sueldos_por_pagar','haber'=>(int)$d['neto'],'referencia'=>'finiquito '.$finiquito],['rol'=>'descuentos_nomina','haber'=>(int)$d['descuentos']]]]];
    }
    /** Ajuste aprobado de inventario: valorizado con el costo del propio movimiento; la contrapartida es la cuenta del concepto elegido (nunca una fija). */
    private function ajusteInventario(int $movimiento,string $tipo):array
    {
        $m=$this->r->uno('SELECT i.cantidad_milesimas,i.costo_centavos,i.tipo,k.concepto_id,c.nombre FROM inventario_movimientos i JOIN erp_inv_ajuste_conceptos k ON k.movimiento_id=i.id JOIN erp_inv_conceptos c ON c.id=k.concepto_id WHERE i.id=?',[$movimiento])??throw new ErpException('El ajuste de inventario '.$movimiento.' no tiene concepto: no se contabiliza sin clasificarlo.',409);
        $cuenta=(new \Services\ErpInventarioConceptoService($this->r))->cuentaDe((int)$m['concepto_id']);
        $valor=self::parte((int)$m['costo_centavos'],(int)$m['cantidad_milesimas'],1000);if($valor<=0)return [];$ref='ajuste '.$movimiento;
        $inv=['rol'=>'inventario','referencia'=>$ref];$otra=['cuenta_id'=>$cuenta,'referencia'=>$ref];
        return [['concepto'=>mb_substr('Ajuste de inventario: '.$m['nombre'],0,200),'lineas'=>$m['tipo']==='entrada'?[$inv+['debe'=>$valor],$otra+['haber'=>$valor]]:[$otra+['debe'=>$valor],$inv+['haber'=>$valor]]]];
    }
    /** Movimiento manual de efectivo: la contrapartida es la cuenta del concepto elegido (nunca una fija); sin concepto o sin cuenta válida el movimiento espera su configuración. */
    private function cajaManual(int $movimiento,string $tipo,int $monto):array
    {
        $m=$this->r->uno('SELECT m.concepto_id,k.nombre FROM erp_caja_mov_conceptos m JOIN erp_caja_conceptos k ON k.id=m.concepto_id WHERE m.caja_id=?',[$movimiento])??throw new ErpException('El movimiento de caja '.$movimiento.' no tiene concepto: no se contabiliza sin clasificarlo.',409);
        $cuenta=(new \Services\ErpCajaConceptoService($this->r))->cuentaDe((int)$m['concepto_id']);$ref='caja '.$movimiento;
        $caja=['rol'=>'caja','referencia'=>$ref];$otra=['cuenta_id'=>$cuenta,'referencia'=>$ref];
        return [['concepto'=>mb_substr('Caja: '.$m['nombre'],0,200),'lineas'=>$tipo==='ingreso'?[$caja+['debe'=>$monto],$otra+['haber'=>$monto]]:[$otra+['debe'=>$monto],$caja+['haber'=>$monto]]]];
    }
    /**
     * Reverso de un movimiento manual de efectivo. Si el original tiene asiento, se revierte ese asiento. Si el original fue anterior al inicio contable (se confirmó sin
     * asiento) el reverso NO tiene nada que anular, pero sí mueve el efectivo de hoy: se contabiliza como un movimiento actual de sentido contrario. Si el original aún está
     * pendiente, el reverso espera su turno.
     */
    private function cajaReverso(array $d):array
    {
        $ev=(int)$d['aprobacion_evento_id'];
        if($this->r->uno('SELECT id FROM erp_cont_asientos WHERE origen=?',['outbox:ERP-'.$ev.'-contabilidad#1']))return $this->nominaAnulada((int)$d['reversa_de'],$d,'caja');
        $o=$this->r->uno("SELECT estado FROM erp_integraciones_outbox WHERE evento_id=? AND destino='contabilidad'",[$ev]);
        if($o&&$o['estado']==='confirmado')return $this->cajaManual((int)$d['movimiento_id'],(string)$d['tipo'],(int)$d['monto']);
        throw new ErpException('El movimiento original aún no está contabilizado: el reverso espera.',409);
    }
    /** Baja de un activo fijo: se salda la depreciación acumulada y el costo; lo no depreciado se reconoce como pérdida. Sin venta (las ventas de activos exigen factura y no se automatizan). */
    private function activoBaja(int $activo,array $d):array
    {
        return [['concepto'=>'Baja del activo '.($d['codigo']??$activo).' ('.($d['motivo']??'').')','lineas'=>[
            ['rol'=>'depreciacion_acumulada','debe'=>(int)$d['acumulado'],'referencia'=>'activo '.$activo],['rol'=>'perdida_baja_activos','debe'=>(int)$d['valor_libros'],'referencia'=>'activo '.$activo],['rol'=>'activos_fijos','haber'=>(int)$d['costo'],'referencia'=>'activo '.$activo]]]];
    }
    /** Anular una nómina aprobada revierte su asiento. Si el asiento original nunca se generó (hecho previo al inicio contable) no hay nada que revertir; si aún está pendiente, espera. */
    private function nominaAnulada(int $corrida,array $d,string $que='la nómina'):array
    {
        $ev=(int)$d['aprobacion_evento_id'];$ref='ERP-'.$ev.'-contabilidad';
        $orig=$this->r->uno('SELECT id,numero FROM erp_cont_asientos WHERE origen=?',['outbox:'.$ref.'#1']);
        if(!$orig){
            $o=$this->r->uno('SELECT estado,respuesta_json FROM erp_integraciones_outbox WHERE evento_id=? AND destino=\'contabilidad\'',[$ev]);
            if($o&&$o['estado']==='confirmado')return [];
            throw new ErpException('La aprobación de la nómina aún no está contabilizada: el reverso espera.',409);
        }
        $lineas=[];foreach($this->r->q('SELECT * FROM erp_cont_lineas WHERE asiento_id=? ORDER BY nro',[(int)$orig['id']]) as $l)$lineas[]=['cuenta_id'=>(int)$l['cuenta_id'],'debe'=>(int)$l['haber_centavos'],'haber'=>(int)$l['debe_centavos'],'contacto_id'=>$l['contacto_id'],'referencia'=>$l['referencia']];
        $texto=match($que){'finiquito'=>'Anulación del finiquito '.$corrida.' (asiento '.$orig['numero'].')','utilidades'=>'Anulación del reparto de utilidades '.$corrida.' (asiento '.$orig['numero'].')','depreciacion'=>'Anulación de la depreciación (corrida '.$corrida.', asiento '.$orig['numero'].')','baja'=>'Anulación de la baja del activo '.$corrida.' (asiento '.$orig['numero'].')','caja'=>'Reverso del movimiento de caja '.$corrida.' (asiento '.$orig['numero'].')',default=>'Anulación de la nómina (corrida '.$corrida.', asiento '.$orig['numero'].')'};
        return [['concepto'=>$texto,'reversa_de'=>(int)$orig['id'],'lineas'=>$lineas]];
    }

    // ---------------------------------------------------------------- prestación de servicios (hecho propio, sin evento)
    /**
     * El servicio prestado (cita completada) reconoce el ingreso y el IVA contra la cuenta por cobrar. Se detecta por consulta del supervisor porque
     * el ERP no emite un evento de prestación; el origen `prestacion:v<venta>` garantiza un único asiento por venta, aunque el supervisor corra dos veces.
     */
    public function prestaciones(int $limite=50):int
    {
        $inicio=$this->inicio();if(!$inicio)return 0;$hechas=0;
        $ventas=$this->r->q("SELECT v.id FROM erp_ventas v JOIN citas c ON c.id=v.cita_id WHERE v.estado='confirmada' AND c.estado='completada' AND COALESCE(DATE(c.atencion_fin_at),DATE(c.atencion_efectiva_at),c.fecha)>=? AND EXISTS(SELECT 1 FROM erp_venta_lineas l WHERE l.venta_id=v.id AND l.servicio_id IS NOT NULL) AND NOT EXISTS(SELECT 1 FROM erp_cont_asientos a WHERE a.origen=CONCAT('prestacion:v',v.id)) ORDER BY v.id LIMIT ".max(1,min(500,$limite)),[$inicio]);
        foreach($ventas as $v)if(Transaccion::ejecutar($this->r->db,fn()=>$this->prestacion((int)$v['id'])))$hechas++;
        return $hechas;
    }
    private function prestacion(int $venta):bool
    {
        (new ErpVentaService($this->r))->bloquear($venta);
        $c=$this->r->uno("SELECT c.fecha,c.atencion_fin_at,c.atencion_efectiva_at FROM erp_ventas v JOIN citas c ON c.id=v.cita_id WHERE v.id=? AND v.estado='confirmada' AND c.estado='completada'",[$venta]);if(!$c)return false;
        $f=$this->r->uno('SELECT COALESCE(SUM(total_centavos),0) t,COALESCE(SUM(impuesto_centavos),0) i FROM erp_venta_lineas WHERE venta_id=? AND servicio_id IS NOT NULL',[$venta]);$total=(int)$f['t'];$iva=(int)$f['i'];if($total<=0)return false;
        // Las devoluciones registradas antes de la atención nunca reconocieron ingreso: no se reconoce lo devuelto antes de prestar.
        $fin=$c['atencion_fin_at']??$c['atencion_efectiva_at']??null;
        if($fin){
            foreach($this->r->q('SELECT d.monto_centavos,l.total_centavos,l.impuesto_centavos FROM erp_devoluciones d JOIN erp_venta_lineas l ON l.id=d.linea_id WHERE d.venta_id=? AND l.servicio_id IS NOT NULL AND d.creado_en<? FOR SHARE',[$venta,$fin]) as $x){$total-=(int)$x['monto_centavos'];$iva-=self::parte((int)$x['monto_centavos'],(int)$x['impuesto_centavos'],(int)$x['total_centavos']);}
        }
        if($total<=0)return false;
        $fecha=substr($fin??($c['atencion_efectiva_at']??$c['fecha']),0,10);$inicio=$this->inicio();if($fecha<$inicio)return false;
        $this->c->publicar(0,['fecha'=>$this->fechaContable($fecha),'tipo'=>'automatico','origen'=>'prestacion:v'.$venta,'concepto'=>'Servicios prestados de la venta '.$venta,'lineas'=>$this->lineas([['rol'=>'cxc','debe'=>$total,'contacto_id'=>$this->ventaCliente($venta),'referencia'=>'venta '.$venta],['rol'=>'ingreso_servicios','haber'=>$total-$iva],['rol'=>'iva_ventas','haber'=>$iva]])]);
        return true;
    }
}

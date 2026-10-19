<?php
namespace Services;

/** La venta es la fuente comercial; pagos, entrega y fiscalidad conservan estados propios. */
final class ErpVentaService extends ErpService
{
    public function crear(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.ventas.crear','venta_crear',$d,function()use($actor,$d){
            $cita=null;$contacto=null;$usuario=null;$comprador=['nombre'=>'Venta de mostrador'];
            if(!empty($d['cita_id'])){
                $cita=$this->r->uno('SELECT c.*,u.nombre,u.apellido,u.email FROM citas c JOIN usuarios u ON u.id=c.usuarioId WHERE c.id=? FOR UPDATE',[$this->id($d['cita_id'])]);
                if(!$cita||!in_array($cita['estado'],['confirmada','en_servicio','completada'],true))throw new ErpException('La cita no admite una venta.');
                $previa=$this->r->uno('SELECT id FROM erp_ventas WHERE cita_id=?',[(int)$cita['id']]);if($previa)return (int)$previa['id'];
                if($this->r->uno("SELECT id FROM pagos WHERE cita_id=? AND proveedor<>'manual' AND estado IN ('pendiente','procesando','completado','reembolsado')",[(int)$cita['id']]))throw new ErpException('Concilia primero los pagos en línea de la cita.');
                $usuario=(int)$cita['usuarioId'];$comprador=['nombre'=>trim($cita['nombre'].' '.$cita['apellido']),'email'=>$cita['email']];
            } elseif(!empty($d['contacto_id'])){
                $contacto=$this->r->uno("SELECT * FROM erp_contactos WHERE id=? AND activo=1 AND tipo IN ('cliente','ambos')",[$this->id($d['contacto_id'])]);
                if(!$contacto)throw new ErpException('Cliente no disponible.');$comprador=$contacto;$usuario=$contacto['usuario_id'];
            }
            $this->r->q('INSERT INTO erp_ventas(contacto_id,cita_id,cliente_usuario_id,comprador_nombre,comprador_json,creado_por,creado_en) VALUES(?,?,?,?,?,?,?)',[$contacto?(int)$contacto['id']:null,$cita?(int)$cita['id']:null,$usuario,$comprador['nombre'],json_encode($comprador,JSON_THROW_ON_ERROR),$actor,$this->r::ahora()]);
            $id=$this->r->id();
            if($cita){
                // Congelar lo pactado en la reserva: nunca añadir impuestos retrospectivos al precio histórico.
                $lineas=$this->r->q('SELECT cs.*,COALESCE(cs.nombre_servicio,s.nombre) nombre,COALESCE(cs.precio_servicio,s.precio) precio,t.precio_centavos erp_base,t.impuesto_pb erp_tasa,t.impuesto_centavos erp_iva FROM citasservicios cs LEFT JOIN servicios s ON s.id=cs.servicioId LEFT JOIN erp_cita_tarifas t ON t.cita_servicio_id=cs.id WHERE cs.citaId=? ORDER BY cs.id',[(int)$cita['id']]);
                foreach($lineas as $l){
                    $precio=CobroCalculo::centavos($l['precio']);
                    $this->r->q('INSERT INTO erp_venta_lineas(venta_id,cita_servicio_id,servicio_id,profesional_id,nombre,cantidad_milesimas,precio_centavos,impuesto_pb,impuesto_centavos,total_centavos) VALUES(?,?,?,?,?,1000,?,?,?,?)',[$id,(int)$l['id'],$l['servicioId']?(int)$l['servicioId']:null,$cita['profesionalId']?(int)$cita['profesionalId']:null,$l['nombre']??'Servicio histórico',$l['erp_base']===null?$precio:(int)$l['erp_base'],(int)($l['erp_tasa']??0),(int)($l['erp_iva']??0),$precio]);
                }
                foreach($this->r->q("SELECT * FROM pagos WHERE cita_id=? AND proveedor='manual' AND estado IN ('completado','reembolsado','pendiente') ORDER BY id FOR UPDATE",[(int)$cita['id']]) as $p){
                    $this->r->q("INSERT INTO erp_pago_aplicaciones(pago_id,venta_id,importe_centavos,origen) VALUES(?,?,?,'cita')",[(int)$p['id'],$id,CobroCalculo::centavos($p['monto'])]);
                }
            }
            $this->recalcular($id);$this->evento($actor,'venta_creada','venta',$id,['cita_id'=>$cita['id']??null]);return $id;
        });
    }
    public function agregar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.ventas.crear','venta_linea',$d,function()use($actor,$d){
            $id=$this->id($d['venta_id']??null);$v=$this->bloquear($id);$this->borrador($v);
            $this->sinCupon($id);
            $a=$this->r->uno("SELECT a.*,p.nombre producto,p.costo_centavos,s.nombre servicio FROM erp_catalogo a LEFT JOIN inventario_productos p ON p.id=a.producto_id LEFT JOIN servicios s ON s.id=a.servicio_id WHERE a.id=? AND a.activo=1 AND a.tipo<>'consumo'",[$this->id($d['catalogo_id']??null)]);
            if(!$a)throw new ErpException('Artículo no disponible para la venta.');
            if($a['servicio_id'])throw new ErpException('Añade los servicios desde una cita para conservar su atención y profesional.');
            $qty=ValoresExactos::decimal($d['cantidad']??null,3);$des=$this->importe($d['descuento']??'0',true);
            if($des>0){$this->permitir($actor,'erp.descuento');$motivo=$this->texto($d['motivo']??'');}else{$motivo=null;}
            $c=ErpCalculo::linea($qty,(int)$a['precio_centavos'],$des,(int)$a['impuesto_pb']);
            $this->r->q('INSERT INTO erp_venta_lineas(venta_id,catalogo_id,producto_id,nombre,cantidad_milesimas,precio_centavos,costo_centavos,descuento_centavos,impuesto_pb,impuesto_centavos,total_centavos) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$id,(int)$a['id'],(int)$a['producto_id'],$a['producto'],$qty,(int)$a['precio_centavos'],(int)$a['costo_centavos'],$des,(int)$a['impuesto_pb'],$c['impuesto'],$c['total']]);
            $linea=$this->r->id();
            $vendedor=$this->r->uno('SELECT id FROM colaboradores WHERE usuario_id=?',[$actor]);
            $this->r->q('UPDATE erp_venta_lineas SET vendedor_colaborador_id=? WHERE id=?',[$vendedor?(int)$vendedor['id']:null,$linea]);
            $this->recalcular($id);$this->evento($actor,'linea_agregada','venta',$id,['linea_id'=>$linea,'descuento'=>$des,'motivo'=>$motivo]);return $id;
        });
    }
    public function retirar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.ventas.crear','venta_retirar',$d,function()use($actor,$d){
            $id=$this->id($d['venta_id']??null);$v=$this->bloquear($id);$this->borrador($v);
            $this->sinCupon($id);
            $l=$this->r->uno('SELECT * FROM erp_venta_lineas WHERE id=? AND venta_id=?',[$this->id($d['linea_id']??null),$id]);
            if(!$l||$l['cita_servicio_id'])throw new ErpException('Los servicios pactados se gestionan desde la cita.');
            $this->r->q('DELETE FROM erp_venta_lineas WHERE id=?',[(int)$l['id']]);$this->recalcular($id);$this->evento($actor,'linea_retirada','venta',$id,['linea'=>$l]);return $id;
        });
    }
    public function confirmar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.ventas.crear','venta_confirmar',$d,function()use($actor,$d){
            $id=$this->id($d['venta_id']??null);$v=$this->bloquear($id);$this->borrador($v);
            if((int)$v['version']!==$this->id($d['version']??null))throw new ErpException('La venta cambió. Revisa sus importes antes de confirmar.',409);
            $lineas=$this->r->q('SELECT * FROM erp_venta_lineas WHERE venta_id=? ORDER BY id',[$id]);
            if(!$lineas)throw new ErpException('Añade al menos una línea.');
            (new ErpPromocionService($this->r))->confirmar($id);
            if($v['cita_id']){
                $cita=$this->r->uno('SELECT estado FROM citas WHERE id=?',[(int)$v['cita_id']]);
                if(in_array($cita['estado'],['cancelada','no_asistio'],true))throw new ErpException('La cita no admite nuevos cobros. Resuelve sus servicios y anticipos.');
            }
            $productos=$this->r->q('SELECT producto_id,SUM(cantidad_milesimas) cantidad FROM erp_venta_lineas WHERE venta_id=? AND producto_id IS NOT NULL GROUP BY producto_id ORDER BY producto_id',[$id]);
            foreach($productos as $producto){
                $p=$this->r->uno('SELECT * FROM inventario_productos WHERE id=? FOR UPDATE',[(int)$producto['producto_id']]);
                // Lectura actual, después de adquirir el saldo físico. Evita instantáneas antiguas tras esperar otro cierre.
                $reservas=$this->r->q("SELECT cantidad_milesimas FROM erp_stock_reservas WHERE producto_id=? AND estado='activa' FOR SHARE",[(int)$producto['producto_id']]);
                $reservado=array_sum(array_column($reservas,'cantidad_milesimas'));
                if(!$p||!(int)$p['activo']||(int)$p['stock_milesimas']-$reservado<(int)$producto['cantidad'])throw new ErpException('No hay stock disponible para confirmar esta venta.',409);
            }
            foreach($lineas as $l)if($l['producto_id'])$this->r->q('INSERT INTO erp_stock_reservas(linea_id,producto_id,cantidad_milesimas,creado_en) VALUES(?,?,?,?)',[(int)$l['id'],(int)$l['producto_id'],(int)$l['cantidad_milesimas'],$this->r::ahora()]);
            $this->r->q("UPDATE erp_ventas SET estado='confirmada',confirmado_en=?,version=version+1 WHERE id=?",[$this->r::ahora(),$id]);
            $v['estado']='confirmada';$v['confirmado_en']=$this->r::ahora();
            $this->evento($actor,'venta_confirmada','venta',$id,['venta'=>$v,'lineas'=>$lineas,'empresa'=>$this->r->uno('SELECT nombre,identificacion,direccion,moneda FROM erp_empresa WHERE id=1')],['fiscal','contabilidad']);return $id;
        });
    }
    public function anular(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.devolver','venta_anular',$d,function()use($actor,$d){
            $id=$this->id($d['venta_id']??null);$v=$this->bloquear($id);
            if($v['estado']==='anulada')return $id;
            if($v['cita_id'])throw new ErpException('Gestiona la cancelación de la cita y sus anticipos desde su atención.');
            if($this->r->uno('SELECT id FROM erp_venta_lineas WHERE venta_id=? AND entregado_milesimas>0',[$id]))throw new ErpException('Hay productos entregados. Registra una devolución.');
            $s=$this->resumen($id);if($s['neto']>0||$s['pendiente']>0)throw new ErpException('Resuelve los pagos antes de anular.');
            $motivo=$this->texto($d['motivo']??'');$this->r->q("UPDATE erp_ventas SET estado='anulada',version=version+1 WHERE id=?",[$id]);
            (new ErpPromocionService($this->r))->liberar($id);
            foreach($this->r->q('SELECT DISTINCT producto_id FROM erp_venta_lineas WHERE venta_id=? AND producto_id IS NOT NULL ORDER BY producto_id',[$id]) as $p)$this->r->uno('SELECT id FROM inventario_productos WHERE id=? FOR UPDATE',[(int)$p['producto_id']]);
            $this->r->q("UPDATE erp_stock_reservas r JOIN erp_venta_lineas l ON l.id=r.linea_id SET r.estado='liberada' WHERE l.venta_id=? AND r.estado='activa'",[$id]);
            $this->evento($actor,'venta_anulada','venta',$id,['motivo'=>$motivo]);return $id;
        });
    }
    public function bloquear(int $id):array
    {
        // Compatibilidad con caja histórica: cita antes que venta en todos los caminos con origen cita.
        $previa=$this->r->uno('SELECT cita_id FROM erp_ventas WHERE id=?',[$id]);
        if($previa&&$previa['cita_id'])$this->r->uno('SELECT id FROM citas WHERE id=? FOR UPDATE',[(int)$previa['cita_id']]);
        return $this->r->uno('SELECT * FROM erp_ventas WHERE id=? FOR UPDATE',[$id])??throw new ErpException('Venta no encontrada.',404);
    }
    private function borrador(array $v):void {if($v['estado']!=='borrador')throw new ErpException('La venta ya está cerrada para edición.',409);}
    private function sinCupon(int $id):void
    {if($this->r->uno("SELECT venta_id FROM erp_promocion_usos WHERE venta_id=? AND estado='reservado'",[$id]))throw new ErpException('Retira el cupón antes de cambiar los conceptos.');}
    private function recalcular(int $id):void
    {
        $s=$this->r->uno('SELECT COALESCE(SUM(total_centavos),0) total,COALESCE(SUM(impuesto_centavos),0) impuesto,COALESCE(SUM(descuento_centavos),0) descuento FROM erp_venta_lineas WHERE venta_id=?',[$id]);
        if((int)$s['total']>99999999)throw new ErpException('Total fuera de rango.');
        $this->r->q('UPDATE erp_ventas SET total_centavos=?,impuesto_centavos=?,descuento_centavos=?,subtotal_centavos=?,version=version+1 WHERE id=?',[(int)$s['total'],(int)$s['impuesto'],(int)$s['descuento'],(int)$s['total']-(int)$s['impuesto']+(int)$s['descuento'],$id]);
    }
    public function detalle(int $actor,int $id):array
    {
        $this->permitir($actor,'erp.ventas.ver');
        $v=$this->r->uno('SELECT * FROM erp_ventas WHERE id=?',[$id])??throw new ErpException('Venta no encontrada.',404);
        return ['venta'=>$v,'lineas'=>$this->r->q('SELECT * FROM erp_venta_lineas WHERE venta_id=? ORDER BY id',[$id]),'resumen'=>$this->resumen($id),
            'pagos'=>$this->r->q('SELECT p.*,a.origen,a.sesion_id FROM pagos p JOIN erp_pago_aplicaciones a ON a.pago_id=p.id WHERE a.venta_id=? ORDER BY p.id',[$id]),
            'eventos'=>$this->r->q("SELECT * FROM erp_eventos WHERE entidad='venta' AND entidad_id=? ORDER BY id DESC LIMIT 100",[$id])];
    }
    public function resumen(int $id):array
    {
        // Lecturas actuales: una transacción que esperó el bloqueo de venta
        // debe incluir los cobros confirmados mientras esperaba, incluso en RR.
        $v=$this->r->uno('SELECT total_centavos FROM erp_ventas WHERE id=? FOR SHARE',[$id])??throw new ErpException('Venta no encontrada.',404);
        $pagos=$this->r->q('SELECT p.id,p.estado,a.importe_centavos FROM erp_pago_aplicaciones a JOIN pagos p ON p.id=a.pago_id WHERE a.venta_id=? ORDER BY p.id FOR SHARE',[$id]);
        $cobrado=0;$devuelto=0;$pendiente=0;
        foreach($pagos as $p){
            $importe=(int)$p['importe_centavos'];
            if(in_array($p['estado'],['completado','reembolsado'],true)){
                $cobrado+=$importe;
                $devs=$this->r->q('SELECT monto_centavos FROM cobros_devoluciones WHERE pago_id=? FOR SHARE',[(int)$p['id']]);
                $devuelto+=max((int)array_sum(array_column($devs,'monto_centavos')),$p['estado']==='reembolsado'?$importe:0);
            } elseif($p['estado']==='pendiente'){$pendiente+=$importe;}
        }
        $creditos=$this->r->q('SELECT monto_centavos FROM erp_devoluciones WHERE venta_id=? FOR SHARE',[$id]);
        $devol=(int)array_sum(array_column($creditos,'monto_centavos'));
        $total=(int)$v['total_centavos']-$devol;
        return ['total'=>$total,'original'=>(int)$v['total_centavos'],'devolucion_comercial'=>$devol,'cobrado'=>$cobrado,'devuelto'=>$devuelto,'neto'=>$cobrado-$devuelto,'pendiente'=>$pendiente,'saldo'=>max(0,$total-$cobrado+$devuelto),'disponible'=>max(0,$total-$cobrado+$devuelto-$pendiente)];
    }
}

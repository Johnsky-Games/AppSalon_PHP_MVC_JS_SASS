<?php
namespace Services;

/** Una sesión se financia con saldo, se reserva al pactar la visita y se consume al atenderla. */
final class ErpPaqueteService extends ErpService
{
    public function plan(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.promociones','paquete_plan',$d,function()use($actor,$d){
            $servicio=$this->id($d['servicio_id']??null);$sesiones=$this->id($d['sesiones']??null);
            if($sesiones>100||!$this->r->uno('SELECT id FROM servicios WHERE id=?',[$servicio]))throw new ErpException('Selecciona un servicio y de 1 a 100 sesiones.');
            $precio=$this->importe($d['precio']??null);$pb=ValoresExactos::decimal($d['impuesto']??'0',2,true);
            $tarifa=$this->r->uno('SELECT precio_centavos,impuesto_pb FROM erp_catalogo WHERE servicio_id=? AND activo=1',[$servicio]);
            if($pb!==(int)($tarifa['impuesto_pb']??0))throw new ErpException('Utiliza el impuesto de la tarifa vigente del servicio.');
            $c=ErpCalculo::linea(1000,$precio,0,$pb);
            if($c['total']*$sesiones>99999999)throw new ErpException('El paquete supera el límite monetario.');
            $nombre=$this->texto($d['nombre']??'',120);
            $this->r->q('INSERT INTO erp_paquete_planes(nombre,servicio_id,sesiones,precio_centavos,impuesto_pb,creado_por,creado_en) VALUES(?,?,?,?,?,?,?)',[$nombre,$servicio,$sesiones,$precio,$pb,$actor,$this->r::ahora()]);
            $id=$this->r->id();$this->evento($actor,'paquete_plan_creado','paquete_plan',$id,['nombre'=>$nombre,'servicio_id'=>$servicio,'sesiones'=>$sesiones,'precio_sesion'=>$precio,'impuesto_pb'=>$pb]);return $id;
        });
    }
    public function crear(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.cobrar','paquete_crear',$d,function()use($actor,$d){
            $plan=$this->r->uno('SELECT * FROM erp_paquete_planes WHERE id=? AND activo=1 FOR SHARE',[$this->id($d['plan_id']??null)]);
            if(!$plan)throw new ErpException('Plan no disponible.');
            return $this->nuevo($actor,(int)$plan['id'],$this->id($d['contacto_id']??null));
        });
    }
    /** Cabeceras sin cobro: usada por el contrato humano o por el supervisor CLI de ciclos. */
    public function nuevo(int $actor,int $planId,int $contacto):int
    {
        if(\Classes\Transaccion::nivel($this->r->db)===0)throw new \LogicException('El paquete requiere una transacción.');
        if($actor!==0||PHP_SAPI!=='cli')$this->permitir($actor,'erp.cobrar');
        $plan=$this->r->uno('SELECT * FROM erp_paquete_planes WHERE id=? FOR SHARE',[$planId])??throw new ErpException('Plan no encontrado.');
        if(!$this->r->uno("SELECT id FROM erp_contactos WHERE id=? AND activo=1 AND tipo IN('cliente','ambos') FOR SHARE",[$contacto]))throw new ErpException('Selecciona un contacto cliente activo.');
        $this->r->q("INSERT INTO erp_prepagos(contacto_id,tipo,nombre,creado_por,creado_en) VALUES(?,'saldo',?,?,?)",[$contacto,'Paquete · '.$plan['nombre'],$actor,$this->r::ahora()]);$prepago=$this->r->id();
        $c=ErpCalculo::linea(1000,(int)$plan['precio_centavos'],0,(int)$plan['impuesto_pb']);
        $this->r->q('INSERT INTO erp_paquetes(plan_id,prepago_id,sesiones,precio_centavos,impuesto_pb,importe_sesion,creado_por,creado_en) VALUES(?,?,?,?,?,?,?,?)',[$planId,$prepago,(int)$plan['sesiones'],(int)$plan['precio_centavos'],(int)$plan['impuesto_pb'],$c['total'],$actor,$this->r::ahora()]);$id=$this->r->id();
        $this->evento($actor,'paquete_creado','paquete',$id,['plan'=>$plan,'prepago_id'=>$prepago]);return $id;
    }
    public function archivar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.promociones','paquete_archivar',$d,function()use($actor,$d){
            $id=$this->id($d['plan_id']??null);if(!$this->r->uno('SELECT id FROM erp_paquete_planes WHERE id=? FOR UPDATE',[$id]))throw new ErpException('Plan no encontrado.',404);
            $this->r->q('UPDATE erp_paquete_planes SET activo=0 WHERE id=?',[$id]);$this->evento($actor,'paquete_plan_archivado','paquete_plan',$id,['motivo'=>$this->texto($d['motivo']??'')]);return $id;
        });
    }
    /** Una financiación nunca libera sesiones adicionales ni puede gastar este saldo fuera del paquete. */
    public function validarCarga(int $prepago,int $monto):void
    {
        $p=$this->r->uno('SELECT * FROM erp_paquetes WHERE prepago_id=? FOR SHARE',[$prepago]);if(!$p)return;
        $ciclo=$this->r->uno('SELECT c.hasta,s.estado FROM erp_suscripcion_ciclos c JOIN erp_suscripciones s ON s.id=c.suscripcion_id WHERE c.paquete_id=? FOR SHARE',[(int)$p['id']]);
        if($ciclo&&($ciclo['hasta']<=substr($this->r::ahora(),0,10)||$ciclo['estado']==='cancelada'))throw new ErpException('El ciclo terminó o la suscripción está cancelada. Reintegra el saldo sin utilizar; no registres otra carga.');
        $cargas=$this->r->q("SELECT monto_centavos FROM erp_prepago_cargas WHERE prepago_id=? AND estado IN('pendiente','confirmada') FOR SHARE",[$prepago]);
        $reintegros=$this->r->q("SELECT monto_centavos FROM erp_prepago_movimientos WHERE prepago_id=? AND tipo='reembolso' FOR SHARE",[$prepago]);
        if(array_sum(array_column($cargas,'monto_centavos'))+array_sum(array_column($reintegros,'monto_centavos'))+$monto>(int)$p['sesiones']*(int)$p['importe_sesion'])throw new ErpException('La carga supera la financiación pactada del paquete.',409);
    }
    public function aplicar(int $actor,array $d):array
    {
        $repetida=false;
        $resultado=$this->ejecutar($actor,'erp.cobrar','paquete_aplicar',$d,function()use($actor,$d,&$repetida){
            $this->permitir($actor,'erp.ventas.crear');
            $venta=$this->id($d['venta_id']??null);$version=$this->id($d['version']??null);$seleccion=[];
            if(array_key_exists('paquetes',$d)){
                if(!is_array($d['paquetes'])||count($d['paquetes'])>100)throw new ErpException('Selecciona hasta 100 sesiones.');
                foreach($d['paquetes'] as $linea=>$paquete){if($paquete==='')continue;$seleccion[$this->id($linea)]=$this->id($paquete);}
            } else {$seleccion[$this->id($d['linea_id']??null)]=$this->id($d['paquete_id']??null);}
            if(!$seleccion)throw new ErpException('Selecciona un paquete para al menos un servicio de la visita.');ksort($seleccion,SORT_NUMERIC);
            $ventas=new ErpVentaService($this->r);$v=$ventas->bloquear($venta);
            $operacion=hash('sha256','paquete.aplicar:'.$actor.':'.$d['idem_key']);$huella=hash('sha256',json_encode([$venta,$version,$seleccion]));
            $previas=$this->r->q('SELECT venta_id,huella FROM erp_paquete_aplicaciones WHERE operacion=? FOR SHARE',[$operacion]);
            if($previas){if(count($previas)!==count($seleccion)||array_filter($previas,fn($a)=>!hash_equals($a['huella'],$huella)))throw new ErpException('Esta clave identifica otra asignación de sesiones.',409);$repetida=true;return $venta;}
            if($v['estado']!=='borrador'||(int)$v['version']!==$version||!$v['cita_id'])throw new ErpException('Selecciona una visita en borrador y revisa la versión de su venta.',409);
            $resumen=$ventas->resumen($venta);if($resumen['neto']>0||$resumen['pendiente']>0)throw new ErpException('Aplica los paquetes antes de registrar anticipos o pagos de esta visita.');
            if($this->r->uno("SELECT venta_id FROM erp_promocion_usos WHERE venta_id=? AND estado<>'liberado'",[$venta]))throw new ErpException('Los paquetes no se acumulan con cupones.');
            $ids=array_values(array_unique($seleccion));$marcas=implode(',',array_fill(0,count($ids),'?'));
            $paquetes=$this->r->q('SELECT p.*,pl.servicio_id FROM erp_paquetes p JOIN erp_paquete_planes pl ON pl.id=p.plan_id WHERE p.id IN('.$marcas.') ORDER BY p.prepago_id',$ids);
            if(count($paquetes)!==count($ids))throw new ErpException('Paquete no encontrado.',404);
            $porId=[];$veces=array_count_values($seleccion);$prepagos=new ErpPrepagoService($this->r);
            foreach($paquetes as $p){$prepagos->bloquear((int)$p['prepago_id']);$porId[(int)$p['id']]=$p;}
            $fechaCita=$this->r->uno('SELECT fecha FROM citas WHERE id=?',[(int)$v['cita_id']])['fecha'];
            foreach($paquetes as $p){
                $id=(int)$p['id'];$usos=$this->r->q("SELECT id FROM erp_paquete_aplicaciones WHERE paquete_id=? AND estado<>'restituida' FOR SHARE",[$id]);
                if(count($usos)+$veces[$id]>(int)$p['sesiones'])throw new ErpException('No quedan suficientes sesiones disponibles.',409);
                if($prepagos->saldo((int)$p['prepago_id'])<$veces[$id]*(int)$p['importe_sesion'])throw new ErpException('Financia las sesiones seleccionadas antes de asignarlas.',409);
                $ciclo=$this->r->uno('SELECT desde,hasta FROM erp_suscripcion_ciclos WHERE paquete_id=?',[$id]);
                if($ciclo&&($fechaCita<$ciclo['desde']||$fechaCita>=$ciclo['hasta']||substr($this->r::ahora(),0,10)>=$ciclo['hasta']))throw new ErpException('La visita debe pertenecer al período del ciclo y asignarse antes de que termine.');
            }
            $asignaciones=[];
            foreach($seleccion as $linea=>$id){
                $p=$porId[$id];$l=$this->r->uno('SELECT * FROM erp_venta_lineas WHERE venta_id=? AND id=? FOR UPDATE',[$venta,$linea]);
                if(!$l||(int)$l['servicio_id']!==(int)$p['servicio_id']||(int)$l['cantidad_milesimas']!==1000||(int)$l['devuelto_milesimas']!==0||(int)$l['descuento_centavos']!==0||(int)$l['impuesto_pb']!==(int)$p['impuesto_pb']||(int)$l['precio_centavos']<(int)$p['precio_centavos'])throw new ErpException('Cada sesión exige el servicio pactado, una unidad sin descuentos y el mismo impuesto.');
                $descuento=(int)$l['precio_centavos']-(int)$p['precio_centavos'];$c=ErpCalculo::linea(1000,(int)$l['precio_centavos'],$descuento,(int)$l['impuesto_pb']);
                $this->r->q('UPDATE erp_venta_lineas SET descuento_centavos=?,impuesto_centavos=?,total_centavos=? WHERE id=?',[$descuento,$c['impuesto'],$c['total'],$linea]);
                $this->r->q('INSERT INTO erp_paquete_aplicaciones(paquete_id,venta_id,linea_id,operacion,clave_operacion,huella,importe_centavos,actor_id,creado_en) VALUES(?,?,?,?,?,?,?,?,?)',[$id,$venta,$linea,$operacion,hash('sha256',$operacion.':'.$linea),$huella,$c['total'],$actor,$this->r::ahora()]);$asignacion=$this->r->id();
                $asignaciones[]=['id'=>$asignacion,'linea'=>$linea,'prepago'=>(int)$p['prepago_id'],'importe'=>$c['total']];
                $this->evento($actor,'paquete_sesion_reservada','paquete',$id,['asignacion_id'=>$asignacion,'venta_id'=>$venta,'linea_id'=>$linea,'importe'=>$c['total'],'descuento'=>$descuento]);
            }
            $this->r->q('UPDATE erp_ventas SET total_centavos=(SELECT SUM(total_centavos) FROM erp_venta_lineas WHERE venta_id=?),impuesto_centavos=(SELECT SUM(impuesto_centavos) FROM erp_venta_lineas WHERE venta_id=?),descuento_centavos=(SELECT SUM(descuento_centavos) FROM erp_venta_lineas WHERE venta_id=?),version=version+1 WHERE id=?',[$venta,$venta,$venta,$venta]);
            $versionActual=$this->r->uno('SELECT version FROM erp_ventas WHERE id=?',[$venta])['version'];
            $ventas->confirmar($actor,['idem_key'=>hash('sha256','paquete.confirmar:'.$d['idem_key']),'venta_id'=>$venta,'version'=>$versionActual]);
            foreach($asignaciones as $a)$prepagos->aplicarPaquete($actor,['idem_key'=>hash('sha256','paquete.pago:'.$d['idem_key'].':'.$a['linea']),'venta_id'=>$venta,'prepago_id'=>$a['prepago'],'monto'=>CobroCalculo::plano($a['importe'])],$a['id']);
            return $venta;
        });
        if($repetida)$resultado['repetida']=true;return $resultado;
    }
    /** Solo desde la transacción de devolución de la misma venta; no crea otro reintegro monetario. */
    public function restituir(int $actor,int $linea,int $devolucion):void
    {
        if(\Classes\Transaccion::nivel($this->r->db)===0)throw new \LogicException('Se requiere la devolución comercial.');
        $a=$this->r->uno("SELECT * FROM erp_paquete_aplicaciones WHERE linea_id=? AND estado<>'restituida' FOR UPDATE",[$linea]);if(!$a)return;
        $pago=$this->r->uno('SELECT estado FROM pagos WHERE id=?',[(int)$a['pago_id']]);
        if(($pago['estado']??'')!=='reembolsado')throw new ErpException('La sesión y su pago deben restituirse íntegramente.',409);
        $this->r->q("UPDATE erp_paquete_aplicaciones SET estado='restituida',restitucion_id=? WHERE id=?",[$devolucion,(int)$a['id']]);
        $this->evento($actor,'paquete_sesion_restituida','paquete',(int)$a['paquete_id'],['asignacion_id'=>$a['id'],'devolucion_id'=>$devolucion]);
    }
    public function consumirVenta(int $venta,int $actor):void
    {
        if(\Classes\Transaccion::nivel($this->r->db)===0)throw new \LogicException('Se requiere la transacción de atención.');
        $filas=$this->r->q("SELECT a.* FROM erp_paquete_aplicaciones a JOIN erp_ventas v ON v.id=a.venta_id JOIN citas c ON c.id=v.cita_id JOIN pagos p ON p.id=a.pago_id WHERE a.venta_id=? AND a.estado='reservada' AND c.estado='completada' AND p.estado='completado' FOR UPDATE",[$venta]);
        foreach($filas as $a){$this->r->q("UPDATE erp_paquete_aplicaciones SET estado='consumida',consumido_en=? WHERE id=?",[$this->r::ahora(),(int)$a['id']]);$this->evento($actor,'paquete_sesion_consumida','paquete',(int)$a['paquete_id'],['asignacion_id'=>$a['id'],'venta_id'=>$venta]);}
    }
}

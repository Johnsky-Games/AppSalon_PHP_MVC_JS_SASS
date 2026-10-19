<?php
namespace Controllers;
use MVC\Router;
use Repositories\ErpRepository;
use Services\ErpException;
use Services\ErpVentaService;
use Services\ErpMaestrosService;
use Services\ErpPagoService;
use Services\ErpCajaService;
use Services\ErpEntregaService;
use Services\ErpDevolucionService;

final class ErpController
{
    private static function equipo():void
    {
        iniciar_sesion_segura();isAuth();
        if(portal_sesion()!=='equipo'){self::denegar();}
    }
    private static function denegar():void
    {
        \Classes\ErrorResponse::render(403,[
            'mensaje'=>mensaje_acceso_denegado('Tu cuenta no tiene permiso para realizar esta operación. Consulta con la administración del salón si necesitas acceso.'),
            'json'=>['resultado'=>false,'error'=>'No tienes permiso para esta operación'],
        ]+opciones_volver_portal());
        detener_ejecucion(403);
    }
    private static function render(Router $router,string $vista,array $datos=[]):void
    {
        $alertas=$_SESSION['erp_alertas']??[];unset($_SESSION['erp_alertas']);
        $router->render('erp/'.$vista,$datos+['alertas'=>$alertas]);
    }
    public static function ventas(Router $router):void
    {
        exigir_permiso('erp.ventas.ver');$r=new ErpRepository();
        $q=\Classes\PeriodoFiltro::aplicar($_GET);$hoy=(new \DateTimeImmutable('now',new \DateTimeZone('America/Guayaquil')))->format('Y-m-d');
        $desde=is_string($q['desde']??null)?$q['desde']:substr($hoy,0,8).'01';$hasta=is_string($q['hasta']??null)?$q['hasta']:$hoy;
        foreach([$desde,$hasta] as $f){$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$f);if(!$d||$d->format('Y-m-d')!==$f)throw new ErpException('Rango no válido.');}
        if($hasta<$desde||(new \DateTimeImmutable($desde))->diff(new \DateTimeImmutable($hasta))->days>366)throw new ErpException('Consulta un rango de hasta 366 días.');
        $pagina=filter_var($q['pagina']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]])?:1;
        $total=(int)$r->uno('SELECT COUNT(*) n FROM erp_ventas WHERE creado_en>=? AND creado_en<DATE_ADD(?,INTERVAL 1 DAY)',[$desde,$hasta])['n'];$paginas=max(1,(int)ceil($total/25));$pagina=min($pagina,$paginas);
        $filas=$r->q('SELECT * FROM erp_ventas WHERE creado_en>=? AND creado_en<DATE_ADD(?,INTERVAL 1 DAY) ORDER BY id DESC LIMIT 25 OFFSET '.(($pagina-1)*25),[$desde,$hasta]);
        self::render($router,'ventas',compact('filas','desde','hasta','pagina','paginas','total')+['contactos'=>$r->q("SELECT id,nombre,identificacion FROM erp_contactos WHERE activo=1 AND tipo IN ('cliente','ambos') ORDER BY nombre LIMIT 500")]);
    }
    public static function detalle(Router $router):void
    {
        exigir_permiso('erp.ventas.ver');$r=new ErpRepository();$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$id)throw new ErpException('Venta no encontrada.',404);
        $datos=(new ErpVentaService($r))->detalle((int)$_SESSION['id'],$id);
        $busqueda=is_string($_GET['q']??null)?mb_substr(trim($_GET['q']),0,80):'';
        $catalogo=$r->q("SELECT a.*,p.nombre, p.stock_milesimas,COALESCE(r.reservado,0) reservado FROM erp_catalogo a JOIN inventario_productos p ON p.id=a.producto_id LEFT JOIN(SELECT producto_id,SUM(cantidad_milesimas) reservado FROM erp_stock_reservas WHERE estado='activa' GROUP BY producto_id)r ON r.producto_id=p.id WHERE a.activo=1 AND p.activo=1 AND a.tipo<>'consumo' AND (p.nombre LIKE ? OR p.sku LIKE ?) ORDER BY p.nombre LIMIT 200",['%'.$busqueda.'%','%'.$busqueda.'%']);
        $prepagos=[];if(puede('erp.cobrar')){
            $v=$datos['venta'];$prepagos=$r->q("SELECT p.id,p.nombre,(SELECT COALESCE(SUM(m.monto_centavos),0) FROM erp_prepago_movimientos m WHERE m.prepago_id=p.id) saldo FROM erp_prepagos p JOIN erp_contactos c ON c.id=p.contacto_id WHERE (p.contacto_id=? OR (c.usuario_id IS NOT NULL AND c.usuario_id=?)) AND NOT EXISTS(SELECT 1 FROM erp_paquetes pa WHERE pa.prepago_id=p.id) ORDER BY p.id",[$v['contacto_id'],$v['cliente_usuario_id']]);
        }
        $paquetes=[];if(puede('erp.cobrar')){$v=$datos['venta'];$paquetes=$r->q("SELECT pa.*,pl.nombre,pl.servicio_id,cc.desde ciclo_desde,cc.hasta ciclo_hasta,(SELECT COUNT(*) FROM erp_paquete_aplicaciones a WHERE a.paquete_id=pa.id AND a.estado<>'restituida') usadas FROM erp_paquetes pa JOIN erp_paquete_planes pl ON pl.id=pa.plan_id JOIN erp_prepagos p ON p.id=pa.prepago_id JOIN erp_contactos c ON c.id=p.contacto_id LEFT JOIN erp_suscripcion_ciclos cc ON cc.paquete_id=pa.id WHERE (p.contacto_id=? OR (c.usuario_id IS NOT NULL AND c.usuario_id=?)) AND (cc.id IS NULL OR (cc.hasta>? AND EXISTS(SELECT 1 FROM citas ca WHERE ca.id=? AND ca.fecha>=cc.desde AND ca.fecha<cc.hasta))) ORDER BY pa.id DESC LIMIT 100",[$v['contacto_id'],$v['cliente_usuario_id'],substr($r::ahora(),0,10),$v['cita_id']]);}
        self::render($router,'detalle',$datos+compact('catalogo','busqueda','prepagos','paquetes')+['cotizaciones'=>$r->q('SELECT id,estado,vence_en,creado_en,version_venta FROM erp_cotizaciones WHERE venta_id=? ORDER BY id DESC',[$id]),'recibos'=>$r->q('SELECT id,codigo,creado_en FROM erp_recibos WHERE venta_id=? ORDER BY id DESC',[$id])]);
    }
    public static function recibo(Router $router):void
    {
        exigir_permiso('erp.ventas.ver');$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$id)throw new ErpException('Recibo no encontrado.',404);
        self::render($router,'recibo',['recibo'=>(new \Services\ErpReciboService())->obtener((int)$_SESSION['id'],$id)]);
    }
    public static function cotizacion(Router $router):void
    {
        exigir_permiso('erp.ventas.ver');$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
        self::render($router,'cotizacion',['cotizacion'=>(new \Services\ErpCotizacionService())->obtener((int)$_SESSION['id'],$id)]);
    }
    public static function catalogo(Router $router):void
    {
        exigir_permiso('erp.catalogo');$r=new ErpRepository();
        self::render($router,'catalogo',['productos'=>$r->q('SELECT * FROM inventario_productos ORDER BY nombre LIMIT 500'),'servicios'=>$r->q('SELECT id,nombre,precio FROM servicios ORDER BY nombre LIMIT 500'),
            'tarifas'=>$r->q('SELECT a.*,COALESCE(p.nombre,s.nombre) nombre FROM erp_catalogo a LEFT JOIN inventario_productos p ON p.id=a.producto_id LEFT JOIN servicios s ON s.id=a.servicio_id ORDER BY a.id DESC LIMIT 200')]);
    }
    public static function contactos(Router $router):void
    {
        exigir_permiso('erp.ventas.crear');$r=new ErpRepository();$compras=puede('erp.compras');
        $q=is_string($_GET['q']??null)?mb_substr(trim($_GET['q']),0,80):'';
        $filas=$r->q("SELECT * FROM erp_contactos WHERE activo=1 AND (nombre LIKE ? OR identificacion LIKE ?)".($compras?'':" AND tipo IN ('cliente','ambos')").' ORDER BY nombre LIMIT 200',['%'.$q.'%','%'.$q.'%']);
        self::render($router,'contactos',compact('filas','q','compras')+['clientes'=>puede('clientes.ver')?$r->q("SELECT u.id,CONCAT(u.nombre,' ',u.apellido,' · ',u.email) nombre FROM usuarios u JOIN clientes c ON c.usuario_id=u.id ORDER BY u.nombre LIMIT 500"):[]]);
    }
    public static function caja(Router $router):void
    {
        exigir_permiso('erp.caja');$r=new ErpRepository();$actor=(int)$_SESSION['id'];
        $sesion=$r->uno("SELECT * FROM erp_caja_sesiones WHERE actor_id=? AND estado='abierta'",[$actor]);
        $esperado=$sesion?(new ErpCajaService($r))->esperado((int)$sesion['id']):0;
        $historial=$r->q('SELECT * FROM erp_caja_sesiones WHERE actor_id=? ORDER BY id DESC LIMIT 30',[$actor]);
        $movimientos=$sesion?$r->q('SELECT c.* FROM erp_caja_movimientos m JOIN caja_movimientos c ON c.id=m.caja_id WHERE m.sesion_id=? ORDER BY c.id DESC LIMIT 100',[(int)$sesion['id']]):[];
        self::render($router,'caja',compact('sesion','esperado','historial','movimientos'));
    }
    public static function prepagos(Router $router):void
    {
        exigir_permiso('erp.cobrar');$r=new ErpRepository();
        $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
        $q=is_string($_GET['q']??null)?mb_substr(trim($_GET['q']),0,80):'';
        $pagina=filter_var($_GET['pagina']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]])?:1;
        $args=['%'.$q.'%','%'.$q.'%'];$where=" FROM erp_prepagos p JOIN erp_contactos c ON c.id=p.contacto_id WHERE (p.nombre LIKE ? OR c.nombre LIKE ?)";
        $total=(int)$r->uno('SELECT COUNT(*) n'.$where,$args)['n'];$paginas=max(1,(int)ceil($total/25));$pagina=min($pagina,$paginas);
        $filas=$r->q('SELECT p.*,c.nombre beneficiario,(SELECT COALESCE(SUM(m.monto_centavos),0) FROM erp_prepago_movimientos m WHERE m.prepago_id=p.id) saldo'.$where.' ORDER BY p.id DESC LIMIT 25 OFFSET '.(($pagina-1)*25),$args);
        $prepago=$id?$r->uno('SELECT p.*,c.nombre beneficiario FROM erp_prepagos p JOIN erp_contactos c ON c.id=p.contacto_id WHERE p.id=?',[$id]):null;
        if($id&&!$prepago)throw new ErpException('Prepago no encontrado.',404);
        $saldo=$id?(new \Services\ErpPrepagoService($r))->saldo($id):0;
        $paqueteVinculado=$id?$r->uno('SELECT id,sesiones,importe_sesion FROM erp_paquetes WHERE prepago_id=?',[$id]):null;
        $f=(new \Services\ErpInformeService($r))->rango($_GET);$desde=$f['desde'];$hasta=$f['hasta'];
        $cp=filter_var($_GET['cargas_pagina']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]])?:1;
        $mp=filter_var($_GET['movimientos_pagina']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]])?:1;
        $nc=$id?(int)$r->uno('SELECT COUNT(*) n FROM erp_prepago_cargas WHERE prepago_id=?',[$id])['n']:0;
        $mc=[$id,$desde,$hasta];$mw=' WHERE prepago_id=? AND creado_en>=? AND creado_en<DATE_ADD(?,INTERVAL 1 DAY)';
        $nm=$id?(int)$r->uno('SELECT COUNT(*) n FROM erp_prepago_movimientos'.$mw,$mc)['n']:0;
        $cargasPaginas=max(1,(int)ceil($nc/25));$movimientosPaginas=max(1,(int)ceil($nm/25));$cp=min($cp,$cargasPaginas);$mp=min($mp,$movimientosPaginas);
        $cargas=$id?$r->q("SELECT c.*,COALESCE((SELECT -SUM(m.monto_centavos) FROM erp_prepago_movimientos m WHERE m.carga_id=c.id AND m.tipo='reembolso'),0) reembolsado FROM erp_prepago_cargas c WHERE c.prepago_id=? ORDER BY c.id DESC LIMIT 25 OFFSET ".(($cp-1)*25),[$id]):[];
        $movimientos=$id?$r->q('SELECT * FROM erp_prepago_movimientos'.$mw.' ORDER BY id DESC LIMIT 25 OFFSET '.(($mp-1)*25),$mc):[];
        $contactos=$r->q("SELECT id,nombre,identificacion FROM erp_contactos WHERE activo=1 AND tipo IN ('cliente','ambos') ORDER BY nombre LIMIT 500");
        self::render($router,'prepagos',compact('id','q','pagina','paginas','total','filas','prepago','saldo','cargas','movimientos','contactos','desde','hasta','cp','mp','nc','nm','cargasPaginas','movimientosPaginas','paqueteVinculado'));
    }
    public static function prepagoComprobante(Router $router):void
    {
        exigir_permiso('erp.cobrar');$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
        self::render($router,'prepago_comprobante',['recibo'=>(new \Services\ErpPrepagoService())->comprobante((int)$_SESSION['id'],$id)]);
    }
    public static function paquetes(Router $router):void
    {
        exigir_permiso('erp.cobrar');$r=new ErpRepository();
        $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
        $q=is_string($_GET['q']??null)?mb_substr(trim($_GET['q']),0,80):'';
        $pagina=filter_var($_GET['pagina']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]])?:1;
        $from=' FROM erp_paquetes p JOIN erp_paquete_planes pl ON pl.id=p.plan_id JOIN erp_prepagos pr ON pr.id=p.prepago_id JOIN erp_contactos c ON c.id=pr.contacto_id LEFT JOIN erp_suscripcion_ciclos cc ON cc.paquete_id=p.id';
        $where=' WHERE (pl.nombre LIKE ? OR c.nombre LIKE ?)';$args=['%'.$q.'%','%'.$q.'%'];
        $total=(int)$r->uno('SELECT COUNT(*) n'.$from.$where,$args)['n'];$paginas=max(1,(int)ceil($total/25));$pagina=min($pagina,$paginas);
        $select='SELECT p.*,pl.nombre,pl.servicio_id,c.nombre beneficiario,cc.desde ciclo_desde,cc.hasta ciclo_hasta,cc.suscripcion_id,(SELECT COUNT(*) FROM erp_paquete_aplicaciones a WHERE a.paquete_id=p.id AND a.estado<>\'restituida\') usadas,(SELECT COALESCE(SUM(m.monto_centavos),0) FROM erp_prepago_movimientos m WHERE m.prepago_id=p.prepago_id) saldo';
        $filas=$r->q($select.$from.$where.' ORDER BY p.id DESC LIMIT 25 OFFSET '.(($pagina-1)*25),$args);
        $paquete=$id?$r->uno($select.$from.' WHERE p.id=?',[$id]):null;if($id&&!$paquete)throw new ErpException('Paquete no encontrado.',404);
        $up=filter_var($_GET['usos_pagina']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]])?:1;
        $nu=$id?(int)$r->uno('SELECT COUNT(*) n FROM erp_paquete_aplicaciones WHERE paquete_id=?',[$id])['n']:0;$usosPaginas=max(1,(int)ceil($nu/25));$up=min($up,$usosPaginas);
        $usos=$id?$r->q('SELECT a.*,v.cita_id,c.fecha,c.estado estado_cita FROM erp_paquete_aplicaciones a JOIN erp_ventas v ON v.id=a.venta_id JOIN citas c ON c.id=v.cita_id WHERE a.paquete_id=? ORDER BY a.id DESC LIMIT 25 OFFSET '.(($up-1)*25),[$id]):[];
        $clienteQ=is_string($_GET['cliente_q']??null)?mb_substr(trim($_GET['cliente_q']),0,80):'';
        $contactos=$r->q("SELECT id,nombre,identificacion FROM erp_contactos WHERE activo=1 AND tipo IN('cliente','ambos') AND (nombre LIKE ? OR identificacion LIKE ? OR email LIKE ?) ORDER BY nombre LIMIT 50",array_fill(0,3,'%'.$clienteQ.'%'));
        self::render($router,'paquetes',compact('id','q','pagina','paginas','total','filas','paquete','usos','nu','up','usosPaginas','clienteQ','contactos')+['planes'=>$r->q('SELECT p.*,s.nombre servicio FROM erp_paquete_planes p JOIN servicios s ON s.id=p.servicio_id WHERE p.activo=1 ORDER BY p.nombre LIMIT 500'),'servicios'=>puede('erp.promociones')?$r->q('SELECT id,nombre FROM servicios ORDER BY nombre LIMIT 500'):[]]);
    }
    public static function integraciones(Router $router):void
    {
        exigir_permiso('erp.reportes');$r=new ErpRepository();
        self::render($router,'integraciones',['filas'=>$r->q('SELECT id,destino,referencia,estado,intentos,actualizado_en FROM erp_integraciones_outbox ORDER BY id DESC LIMIT 200')]);
    }
    public static function fiscal(Router $router):void
    {
        exigir_permiso('erp.cobrar');$r=new ErpRepository();$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;$ventaId=filter_var($_GET['venta_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
        if($id){$documento=$r->uno('SELECT d.*,v.comprador_nombre FROM erp_fiscal_documentos d JOIN erp_ventas v ON v.id=d.venta_id WHERE d.id=?',[$id])??throw new ErpException('Documento fiscal no encontrado.',404);self::render($router,'fiscal',compact('documento'));return;}
        if($ventaId){
            $previa=$r->uno('SELECT id FROM erp_fiscal_documentos WHERE venta_id=?',[$ventaId]);if($previa){header('Location: /gestion/facturacion?id='.(int)$previa['id'],true,303);detener_ejecucion(303);return;}
            $datos=(new ErpVentaService($r))->detalle((int)$_SESSION['id'],$ventaId);$perfiles=$r->q('SELECT id,ruc,establecimiento,punto_emision,creado_en FROM erp_fiscal_perfiles ORDER BY id DESC LIMIT 100');
            self::render($router,'fiscal',$datos+compact('ventaId','perfiles'));return;
        }
        $q=\Classes\PeriodoFiltro::aplicar($_GET);$hoy=substr($r::ahora(),0,10);$desde=$q['desde']??substr($hoy,0,8).'01';$hasta=$q['hasta']??$hoy;
        foreach([$desde,$hasta] as $f){$fecha=is_string($f)?\DateTimeImmutable::createFromFormat('!Y-m-d',$f):false;if(!$fecha||$fecha->format('Y-m-d')!==$f)throw new ErpException('Fecha fiscal no válida.');}if($hasta<$desde||(new \DateTimeImmutable($desde))->diff(new \DateTimeImmutable($hasta))->days>366)throw new ErpException('Consulta hasta 366 días.');
        $estado=is_string($q['estado']??null)?$q['estado']:'';if(!in_array($estado,['','pendiente','recibida','autorizada','rechazada'],true))throw new ErpException('Estado fiscal no válido.');
        $where=' WHERE d.creado_en>=? AND d.creado_en<DATE_ADD(?,INTERVAL 1 DAY)';$args=[$desde,$hasta];if($estado!==''){$where.=' AND d.estado=?';$args[]=$estado;}
        $pagina=filter_var($q['pagina']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]])?:1;$total=(int)$r->uno('SELECT COUNT(*) n FROM erp_fiscal_documentos d'.$where,$args)['n'];$paginas=max(1,(int)ceil($total/25));$pagina=min($pagina,$paginas);
        $filas=$r->q('SELECT d.id,d.numero,d.estado,d.creado_en,v.comprador_nombre FROM erp_fiscal_documentos d JOIN erp_ventas v ON v.id=d.venta_id'.$where.' ORDER BY d.id DESC LIMIT 25 OFFSET '.(($pagina-1)*25),$args);
        self::render($router,'fiscal',compact('filas','desde','hasta','estado','pagina','paginas','total'));
    }
    public static function fiscalXml():void
    {
        exigir_permiso('erp.cobrar');$r=new ErpRepository();$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;$doc=$r->uno('SELECT xml_firmado,xml_sha256,numero FROM erp_fiscal_documentos WHERE id=?',[$id])??throw new ErpException('Documento fiscal no encontrado.',404);
        if(!hash_equals($doc['xml_sha256'],hash('sha256',$doc['xml_firmado'])))throw new ErpException('No se pudo verificar la integridad del XML.',409);
        header('Content-Type: application/xml; charset=UTF-8');header('Content-Disposition: attachment; filename="pruebas-sri-'.$doc['numero'].'.xml"');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');echo $doc['xml_firmado'];
    }
    public static function suscripciones(Router $router):void
    {
        exigir_permiso('erp.cobrar');$r=new ErpRepository();$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
        $q=is_string($_GET['q']??null)?mb_substr(trim($_GET['q']),0,80):'';$estado=is_string($_GET['estado']??null)?$_GET['estado']:'';
        if(!in_array($estado,['','activa','pausada','cancelada'],true))throw new ErpException('Estado no válido.');
        $pagina=filter_var($_GET['pagina']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]])?:1;
        $from=' FROM erp_suscripciones s JOIN erp_contactos c ON c.id=s.contacto_id JOIN erp_paquete_planes p ON p.id=s.plan_id';$where=' WHERE (c.nombre LIKE ? OR p.nombre LIKE ?)';$args=['%'.$q.'%','%'.$q.'%'];
        if($estado!==''){$where.=' AND s.estado=?';$args[]=$estado;}
        $total=(int)$r->uno('SELECT COUNT(*) n'.$from.$where,$args)['n'];$paginas=max(1,(int)ceil($total/25));$pagina=min($pagina,$paginas);
        $select='SELECT s.*,c.nombre beneficiario,p.nombre plan,p.sesiones';$filas=$r->q($select.$from.$where.' ORDER BY s.id DESC LIMIT 25 OFFSET '.(($pagina-1)*25),$args);
        $suscripcion=$id?$r->uno($select.$from.' WHERE s.id=?',[$id]):null;if($id&&!$suscripcion)throw new ErpException('Suscripción no encontrada.',404);
        $cp=filter_var($_GET['ciclos_pagina']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]])?:1;
        $nc=$id?(int)$r->uno('SELECT COUNT(*) n FROM erp_suscripcion_ciclos WHERE suscripcion_id=?',[$id])['n']:0;$ciclosPaginas=max(1,(int)ceil($nc/25));$cp=min($cp,$ciclosPaginas);
        $ciclos=$id?$r->q("SELECT c.*,p.prepago_id,p.importe_sesion,p.sesiones,(SELECT COALESCE(SUM(m.monto_centavos),0) FROM erp_prepago_movimientos m WHERE m.prepago_id=p.prepago_id) saldo FROM erp_suscripcion_ciclos c JOIN erp_paquetes p ON p.id=c.paquete_id WHERE c.suscripcion_id=? ORDER BY c.desde DESC LIMIT 25 OFFSET ".(($cp-1)*25),[$id]):[];
        $clienteQ=is_string($_GET['cliente_q']??null)?mb_substr(trim($_GET['cliente_q']),0,80):'';
        $contactos=$r->q("SELECT id,nombre,identificacion FROM erp_contactos WHERE activo=1 AND tipo IN('cliente','ambos') AND (nombre LIKE ? OR identificacion LIKE ? OR email LIKE ?) ORDER BY nombre LIMIT 50",array_fill(0,3,'%'.$clienteQ.'%'));
        self::render($router,'suscripciones',compact('id','q','estado','pagina','paginas','total','filas','suscripcion','ciclos','cp','nc','ciclosPaginas','clienteQ','contactos')+['planes'=>$r->q('SELECT id,nombre,sesiones FROM erp_paquete_planes WHERE activo=1 ORDER BY nombre LIMIT 500'),'hoy'=>substr($r::ahora(),0,10)]);
    }
    public static function configuracion(Router $router):void
    {exigir_permiso('erp.configurar');self::render($router,'configuracion',['empresa'=>(new ErpRepository())->uno('SELECT * FROM erp_empresa WHERE id=1')]);}

    public static function compras(Router $router):void
    {
        exigir_permiso('erp.compras');$r=new ErpRepository();$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
        $compra=$id?$r->uno('SELECT c.*,p.nombre proveedor FROM erp_compras c JOIN erp_contactos p ON p.id=c.proveedor_id WHERE c.id=?',[$id]):null;
        if($id&&!$compra)throw new ErpException('Compra no encontrada.',404);
        self::render($router,'compras',['compra'=>$compra,'filas'=>$r->q('SELECT c.*,p.nombre proveedor FROM erp_compras c JOIN erp_contactos p ON p.id=c.proveedor_id ORDER BY c.id DESC LIMIT 100'),
            'lineas'=>$id?$r->q('SELECT l.*,p.nombre FROM erp_compra_lineas l JOIN inventario_productos p ON p.id=l.producto_id WHERE l.compra_id=? ORDER BY l.id',[$id]):[],
            'proveedores'=>$r->q("SELECT id,nombre FROM erp_contactos WHERE activo=1 AND tipo IN ('proveedor','ambos') ORDER BY nombre LIMIT 500"),
            'productos'=>$r->q('SELECT id,nombre,sku FROM inventario_productos WHERE activo=1 ORDER BY nombre LIMIT 500')]);
    }
    public static function obligaciones(Router $router):void
    {
        exigir_permiso('erp.obligaciones.pagar');$r=new ErpRepository();
        self::render($router,'obligaciones',['filas'=>$r->q('SELECT o.*,COALESCE((SELECT SUM(r.monto_centavos) FROM erp_proveedor_reembolsos r WHERE r.obligacion_id=o.id),0) recuperado,COALESCE(c.nombre,CONCAT(col.nombre,\' \',col.apellido),\'Gasto autorizado\') beneficiario FROM erp_obligaciones o LEFT JOIN erp_contactos c ON c.id=o.contacto_id LEFT JOIN colaboradores col ON col.id=o.colaborador_id ORDER BY o.vence_en,o.id DESC LIMIT 200')]);
    }
    public static function personal(Router $router):void
    {
        self::equipo();$r=new ErpRepository();$ctx=(new \Services\AuthorizationService($r->db))->contexto((int)$_SESSION['id']);
        if(!$ctx||$ctx['rol']==='cliente')throw new ErpException('Acceso reservado al equipo.',403);
        $gestionRh=puede('erp.rh');$id=$gestionRh?(filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0):0;
        $propio=$r->uno('SELECT id FROM colaboradores WHERE usuario_id=?',[(int)$_SESSION['id']]);
        $colaboradores=$gestionRh?$r->q("SELECT id,nombre,apellido FROM colaboradores WHERE estado<>'baja' ORDER BY apellido,nombre LIMIT 500"):[];
        if(!$id)$id=(int)($propio['id']??($colaboradores[0]['id']??0));
        $colaborador=$id?$r->uno('SELECT id,nombre,apellido,codigo,rol,estado FROM colaboradores WHERE id=?',[$id]):null;
        self::render($router,'personal',compact('gestionRh','colaboradores','colaborador')+[
            'contratos'=>$id?$r->q('SELECT * FROM erp_contratos WHERE colaborador_id=? ORDER BY inicio DESC',[$id]):[],
            'jornadas'=>$id?$r->q('SELECT * FROM erp_jornadas WHERE colaborador_id=? ORDER BY dia_semana',[$id]):[],
            'asistencia'=>$id?$r->q('SELECT * FROM erp_asistencia WHERE colaborador_id=? ORDER BY entrada DESC LIMIT 100',[$id]):[],
            'ausencias'=>$id?$r->q('SELECT * FROM erp_ausencias WHERE colaborador_id=? ORDER BY id DESC LIMIT 100',[$id]):[]]);
    }
    public static function aprobaciones(Router $router):void
    {
        exigir_permiso('erp.ventas.crear');$r=new ErpRepository();$gestion=puede('erp.aprobaciones');
        $filas=$r->q('SELECT s.*,CONCAT(u.nombre,\' \',u.apellido) solicitante FROM erp_solicitudes s JOIN usuarios u ON u.id=s.solicitante_id'.($gestion?'':' WHERE solicitante_id=?').' ORDER BY s.id DESC LIMIT 100',$gestion?[]:[(int)$_SESSION['id']]);
        self::render($router,'aprobaciones',compact('filas','gestion')+['productos'=>$r->q('SELECT id,nombre FROM inventario_productos WHERE activo=1 ORDER BY nombre LIMIT 500')]);
    }
    public static function promociones(Router $router):void
    {exigir_permiso('erp.promociones');self::render($router,'promociones',['filas'=>(new ErpRepository())->q('SELECT * FROM erp_promociones ORDER BY id DESC LIMIT 200')]);}
    public static function comisiones(Router $router):void
    {
        exigir_permiso('erp.comisiones');$r=new ErpRepository();
        self::render($router,'comisiones',['colaboradores'=>$r->q("SELECT id,CONCAT(nombre,' ',apellido) nombre FROM colaboradores ORDER BY nombre"),
            'saldos'=>$r->q("SELECT m.colaborador_id,CONCAT(c.nombre,' ',c.apellido) nombre,SUM(m.monto_centavos) saldo FROM erp_comision_movimientos m JOIN colaboradores c ON c.id=m.colaborador_id WHERE m.estado='pendiente' GROUP BY m.colaborador_id,c.nombre,c.apellido"),
            'movimientos'=>$r->q("SELECT m.*,CONCAT(c.nombre,' ',c.apellido) nombre FROM erp_comision_movimientos m JOIN colaboradores c ON c.id=m.colaborador_id ORDER BY m.id DESC LIMIT 100"),
            'liquidaciones'=>$r->q("SELECT l.*,CONCAT(c.nombre,' ',c.apellido) nombre,COALESCE(o.pagado_centavos,0) abonado FROM erp_comision_liquidaciones l JOIN colaboradores c ON c.id=l.colaborador_id LEFT JOIN erp_obligaciones o ON o.id=l.obligacion_id ORDER BY l.id DESC LIMIT 100")]);
    }
    public static function seguimiento(Router $router):void
    {
        exigir_permiso('erp.ventas.crear');$r=new ErpRepository();$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
        $contacto=$id?$r->uno("SELECT * FROM erp_contactos WHERE id=? AND tipo IN ('cliente','ambos')",[$id]):null;
        if($id&&!$contacto)throw new ErpException('Contacto no encontrado.',404);
        self::render($router,'seguimiento',['contacto'=>$contacto,'contactos'=>$r->q("SELECT id,nombre FROM erp_contactos WHERE activo=1 AND tipo IN ('cliente','ambos') ORDER BY nombre LIMIT 500"),
            'oportunidades'=>$r->q('SELECT o.*,c.nombre FROM erp_oportunidades o JOIN erp_contactos c ON c.id=o.contacto_id'.($id?' WHERE o.contacto_id=?':'').' ORDER BY o.proximo_contacto,o.id DESC LIMIT 100',$id?[$id]:[]),
            'interacciones'=>$r->q('SELECT i.*,c.nombre FROM erp_interacciones i JOIN erp_contactos c ON c.id=i.contacto_id'.($id?' WHERE i.contacto_id=?':'').' ORDER BY i.estado,i.vence_en,i.id DESC LIMIT 100',$id?[$id]:[]),
            'ventas'=>$id?$r->q('SELECT * FROM erp_ventas WHERE contacto_id=? ORDER BY id DESC LIMIT 100',[$id]):[]]);
    }

    public static function indicadores(Router $router):void
    {
        exigir_permiso('erp.reportes');$datos=(new \Services\ErpInformeService())->obtener((int)$_SESSION['id'],$_GET);self::render($router,'indicadores',$datos);
    }
    public static function exportar():void
    {
        iniciar_sesion_segura();isAuth();$aud=new \Services\ExportacionAuditoriaService(new \Repositories\ExportacionAuditoriaRepository());$actor=\Services\ExportacionAuditoriaService::actorDeSesion();
        $formato=\Classes\Exportacion\Exportador::formatoDe($_GET['formato']??null);
        if(!puede('erp.reportes')){$aud->registrar('erp.caja_diaria',$formato??'invalido',$actor,null,['resultado'=>'denegada','motivo'=>'sin_permiso']);self::denegar();return;}
        if(!$formato)throw new ErpException('Formato de exportación no válido.');
        $d=(new \Services\ErpInformeService())->obtener((int)$_SESSION['id'],$_GET);
        $r=\Classes\Exportacion\Exportador::generar($formato,'caja_diaria_'.$d['desde'].'_'.$d['hasta'],[
            ['clave'=>'fecha','titulo'=>'Fecha','tipo'=>'fecha'],['clave'=>'ingresos_centavos','titulo'=>'Ingresos en centavos USD','tipo'=>'entero'],
            ['clave'=>'egresos_centavos','titulo'=>'Egresos en centavos USD','tipo'=>'entero'],['clave'=>'neto_centavos','titulo'=>'Flujo neto en centavos USD','tipo'=>'entero']],$d['diario']);
        try{$aud->registrar('erp.caja_diaria',$r['formato'],$actor,['desde'=>$d['desde'],'hasta'=>$d['hasta']],$r+['resultado'=>'exitosa']);\Classes\Exportacion\Exportador::entregar($r);}finally{if(is_resource($r['flujo']))fclose($r['flujo']);}
    }
    public static function recursos(Router $router):void
    {
        exigir_permiso('erp.recursos');$r=new ErpRepository();
        self::render($router,'recursos',['recursos'=>$r->q('SELECT * FROM erp_recursos ORDER BY nombre'),
            'requisitos'=>$r->q('SELECT r.*,s.nombre FROM erp_servicio_recursos r JOIN servicios s ON s.id=r.servicio_id ORDER BY r.desde DESC,r.id DESC LIMIT 200'),
            'mantenimientos'=>$r->q('SELECT m.*,r.nombre FROM erp_mantenimientos m JOIN erp_recursos r ON r.id=m.recurso_id ORDER BY m.desde DESC LIMIT 100'),
            'activos'=>$r->q('SELECT * FROM erp_activos ORDER BY id DESC LIMIT 200'),
            'servicios'=>$r->q('SELECT id,nombre FROM servicios ORDER BY nombre'),
            'productos'=>$r->q('SELECT id,nombre FROM inventario_productos WHERE activo=1 ORDER BY nombre'),
            'recetas'=>$r->q('SELECT r.*,s.nombre servicio,p.nombre producto FROM erp_recetas r JOIN servicios s ON s.id=r.servicio_id JOIN inventario_productos p ON p.id=r.producto_id ORDER BY s.nombre,p.nombre')]);
    }
    public static function documentos(Router $router):void
    {
        self::equipo();$svc=new \Services\ErpDocumentoService();$actor=(int)$_SESSION['id'];
        $entidad=is_string($_GET['entidad']??null)?$_GET['entidad']:'';$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
        $filas=$svc->listar($actor,$entidad,$id);$editar=$entidad!=='colaborador'||puede('erp.rh');
        self::render($router,'documentos',compact('filas','entidad','id','editar'));
    }
    public static function documentoDescargar():void
    {
        self::equipo();$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
        $doc=(new \Services\ErpDocumentoService())->descargar((int)$_SESSION['id'],$id);
        header('Content-Type: '.$doc['mime']);header('Content-Disposition: attachment; filename="documento-'.$id.'.'.pathinfo($doc['nombre_archivo'],PATHINFO_EXTENSION).'"');
        header('Content-Length: '.strlen($doc['contenido']));header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');echo $doc['contenido'];detener_ejecucion(200);
    }
    public static function accion():void
    {
        self::equipo();exigir_csrf();
        $acciones=[
            'venta_crear'=>[ErpVentaService::class,'crear','venta'], 'venta_agregar'=>[ErpVentaService::class,'agregar','venta'],
            'venta_retirar'=>[ErpVentaService::class,'retirar','venta'],'venta_confirmar'=>[ErpVentaService::class,'confirmar','venta'],
            'venta_anular'=>[ErpVentaService::class,'anular','venta'],'venta_cobrar'=>[ErpPagoService::class,'cobrar','venta'],
            'venta_verificar'=>[ErpPagoService::class,'verificar','venta'],'venta_entregar'=>[ErpEntregaService::class,'entregar','venta'],
            'venta_devolver'=>[ErpDevolucionService::class,'devolver','venta'],
            'prepago_crear'=>[\Services\ErpPrepagoService::class,'crear','prepagos'],'prepago_cargar'=>[\Services\ErpPrepagoService::class,'cargar','prepagos'],
            'prepago_verificar'=>[\Services\ErpPrepagoService::class,'verificar','prepagos'],'prepago_reembolsar'=>[\Services\ErpPrepagoService::class,'reembolsar','prepagos'],
            'prepago_aplicar'=>[\Services\ErpPrepagoService::class,'aplicar','venta'],
            'cotizacion_emitir'=>[\Services\ErpCotizacionService::class,'emitir','venta'],'cotizacion_aceptar'=>[\Services\ErpCotizacionService::class,'aceptar','venta'],
            'contacto_vincular'=>[ErpMaestrosService::class,'vincularCliente','contactos'],'contacto'=>[ErpMaestrosService::class,'contacto','contactos'],'catalogo'=>[ErpMaestrosService::class,'catalogo','catalogo'],
            'empresa'=>[ErpMaestrosService::class,'empresa','configuracion'],'caja_abrir'=>[ErpCajaService::class,'abrir','caja'],
            'caja_cerrar'=>[ErpCajaService::class,'cerrar','caja'],'caja_movimiento'=>[ErpCajaService::class,'movimiento','caja'],
            'compra_crear'=>[\Services\ErpCompraService::class,'crear','compra'],'compra_agregar'=>[\Services\ErpCompraService::class,'agregar','compra'],
            'compra_ordenar'=>[\Services\ErpCompraService::class,'ordenar','compra'],'compra_recibir'=>[\Services\ErpCompraService::class,'recibir','compra'],
            'compra_devolver'=>[\Services\ErpCompraService::class,'devolver','compra'],'compra_cerrar'=>[\Services\ErpCompraService::class,'cerrar','compra'],
            'proveedor_credito'=>[\Services\ErpCompraService::class,'credito','obligaciones'],'proveedor_recuperar'=>[\Services\ErpCompraService::class,'recuperar','obligaciones'],
            'compra_facturar'=>[\Services\ErpCompraService::class,'facturar','compra'],'obligacion_pagar'=>[\Services\ErpCompraService::class,'pagar','obligaciones'],
            'rh_contrato_fin'=>[\Services\ErpRhService::class,'finalizarContrato','personal'],'rh_asistencia_anular'=>[\Services\ErpRhService::class,'anularAsistencia','personal'],'rh_ausencia_anular'=>[\Services\ErpRhService::class,'anularAusencia','personal'],
            'rh_contrato'=>[\Services\ErpRhService::class,'contrato','personal'],'rh_jornada'=>[\Services\ErpRhService::class,'jornada','personal'],
            'rh_asistencia'=>[\Services\ErpRhService::class,'asistencia','personal'],'rh_ausencia'=>[\Services\ErpRhService::class,'solicitarAusencia','personal'],
            'rh_ausencia_resolver'=>[\Services\ErpRhService::class,'resolverAusencia','personal'],'rh_nomina'=>[\Services\ErpRhService::class,'prepararNomina','personal'],
            'solicitud'=>[\Services\ErpAprobacionService::class,'solicitar','aprobaciones'],'solicitud_resolver'=>[\Services\ErpAprobacionService::class,'resolver','aprobaciones'],
            'paquete_plan'=>[\Services\ErpPaqueteService::class,'plan','paquetes'],
            'paquete_archivar'=>[\Services\ErpPaqueteService::class,'archivar','paquetes'],
            'paquete_crear'=>[\Services\ErpPaqueteService::class,'crear','paquetes'],
            'paquete_aplicar'=>[\Services\ErpPaqueteService::class,'aplicar','venta'],
            'suscripcion_crear'=>[\Services\ErpSuscripcionService::class,'crear','suscripciones'],
            'suscripcion_estado'=>[\Services\ErpSuscripcionService::class,'estado','suscripciones'],
            'fiscal_factura'=>[\Services\ErpFiscalService::class,'factura','facturacion'],
            'promocion_estado'=>[\Services\ErpPromocionService::class,'estado','promociones'],
            'promocion_crear'=>[\Services\ErpPromocionService::class,'crear','promociones'],
            'cupon_aplicar'=>[\Services\ErpPromocionService::class,'aplicar','venta'],'cupon_quitar'=>[\Services\ErpPromocionService::class,'quitar','venta'],
            'comision_regla'=>[\Services\ErpComisionService::class,'configurar','comisiones'],'comision_liquidar'=>[\Services\ErpComisionService::class,'liquidar','comisiones'],
            'comision_recalcular'=>[\Services\ErpComisionService::class,'recalcular','venta'],
            'crm_oportunidad'=>[\Services\ErpCrmService::class,'oportunidad','seguimiento'],'crm_etapa'=>[\Services\ErpCrmService::class,'etapa','seguimiento'],
            'crm_interaccion'=>[\Services\ErpCrmService::class,'interaccion','seguimiento'],'crm_resolver'=>[\Services\ErpCrmService::class,'resolver','seguimiento'],
            'recurso_crear'=>[\Services\ErpRecursoService::class,'crear','recursos'],'recurso_requisito'=>[\Services\ErpRecursoService::class,'requisito','recursos'],
            'mantenimiento_completar'=>[\Services\ErpRecursoService::class,'completarMantenimiento','recursos'],'recurso_mantenimiento'=>[\Services\ErpRecursoService::class,'mantenimiento','recursos'],'activo_crear'=>[\Services\ErpRecursoService::class,'activo','recursos'],
            'receta'=>[\Services\ErpConsumoService::class,'receta','recursos'],'consumir'=>[\Services\ErpConsumoService::class,'consumir','consumos'],
            'documento_subir'=>[\Services\ErpDocumentoService::class,'subir','documentos'],
        ];
        $accion=is_string($_POST['accion']??null)?$_POST['accion']:'';
        if(!isset($acciones[$accion])){http_response_code(422);detener_ejecucion(422);return;}
        [$clase,$metodo,$destino]=$acciones[$accion];$campoId=['compra'=>'compra_id','personal'=>'colaborador_id','prepagos'=>'prepago_id','paquetes'=>'paquete_id','suscripciones'=>'suscripcion_id'];$id=filter_var($_POST[$campoId[$destino]??'venta_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;$completada=false;
        try{
            $datos=$_POST;
            if($accion==='documento_subir'){
                $f=$_FILES['archivo']??null;
                if(!is_array($f)||($f['error']??-1)!==UPLOAD_ERR_OK||!is_string($f['tmp_name']??null)||!is_uploaded_file($f['tmp_name'])||filesize($f['tmp_name'])>5*1024*1024)throw new ErpException('Selecciona un archivo válido de hasta 5 MB.');
                $datos['contenido']=file_get_contents($f['tmp_name']);$datos['nombre_archivo']=$f['name'];
            }
            $resultado=(new $clase())->$metodo((int)$_SESSION['id'],$datos);$id=in_array($accion,['rh_nomina','paquete_plan','paquete_archivar'],true)?0:(int)$resultado['recurso_id'];$completada=true;
            $_SESSION['erp_alertas']=['exito'=>[!empty($resultado['repetida'])?'Esta operación ya estaba registrada.':'Operación registrada correctamente.']];
        }catch(ErpException $e){
            if(in_array($e->getCode(),[403,404],true)){\Classes\ErrorResponse::render($e->getCode());return;}
            $_SESSION['erp_alertas']=['error'=>[$e->getMessage()]];
        }catch(\InvalidArgumentException|\Classes\ConflictoIdempotenciaException $e){$_SESSION['erp_alertas']=['error'=>[$e->getMessage()]];}
        catch(\Throwable $e){error_log('[ERP] '.$accion.' '.get_class($e).' code='.$e->getCode());$_SESSION['erp_alertas']=['error'=>['La operación no se completó. Revisa los datos y vuelve a intentarlo.']];}
        $ruta=match($destino){'venta'=>$id?'/ventas/detalle?id='.$id:'/pdv','compra'=>'/gestion/compras'.($id?'?id='.$id:''),'personal'=>'/gestion/personal'.($id?'?id='.$id:''),'seguimiento'=>'/gestion/seguimiento'.($id?'?id='.$id:''),'prepagos'=>'/gestion/prepagos'.($id?'?id='.$id:''),'paquetes'=>'/gestion/paquetes'.($id?'?id='.$id:''),'suscripciones'=>'/gestion/suscripciones'.($id?'?id='.$id:''),default=>'/gestion/'.$destino};
        if($destino==='documentos')$ruta='/gestion/documentos?'.http_build_query(['entidad'=>is_string($_POST['entidad']??null)?$_POST['entidad']:'','id'=>(int)($_POST['entidad_id']??0)]);
        if($destino==='consumos')$ruta='/agenda/detalle?id='.(int)($_POST['cita_id']??0);
        if($destino==='facturacion')$ruta='/gestion/facturacion?'.($completada?'id=':'venta_id=').$id;
        header('Location: '.$ruta,true,303);detener_ejecucion(303);
    }
}

<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';
use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\ErpRepository;
use Services\{ErpCajaService,ErpDevolucionService,ErpMaestrosService,ErpPagoService,ErpPaqueteService,ErpPrepagoService,ErpVentaService};

/**
 * Modelo de lectura BI, cobro neto (058): flujo de dinero real, devoluciones de dinero, aplicaciones de saldo y notas de crédito,
 * reconciliados contra las tablas de origen (docs/BI_COBRO_NETO.md). Pagos simulados y transferencias sin verificar no cuentan como dinero.
 */
final class ErpBiCobroNetoIntegrationTest extends TestCase
{
    private const VISTAS=['bi_flujo_dinero','bi_devoluciones_dinero','bi_aplicaciones_saldo','bi_devoluciones_venta','bi_notas_credito'];
    private \mysqli $db;private ErpRepository $r;private int $owner;private int $contacto;private int $producto;private int $catalogo;private int $prepago;private int $sesion;
    /** @var array<string,int> */ private array $ids=[];
    protected function setUp():void
    {
        $this->db=BaseDatosAislada::crear('bicobro');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);
        $this->owner=R5CajaFixture::usuario($this->db,'propietario');$cli=R5CajaFixture::usuario($this->db);
        $this->r->q("INSERT INTO clientes(usuario_id,estado,origen) VALUES(?,'activo','registro_publico')",[$cli]);
        $m=new ErpMaestrosService($this->r);$this->contacto=$m->contacto($this->owner,$this->d(['nombre'=>'Clienta Privada Cobro','tipo'=>'cliente']))['recurso_id'];
        $m->vincularCliente($this->owner,$this->d(['contacto_id'=>$this->contacto,'usuario_id'=>$cli]));
        $this->prepago=(new ErpPrepagoService($this->r))->crear($this->owner,$this->d(['contacto_id'=>$this->contacto,'tipo'=>'regalo','nombre'=>'Regalo de ensayo']))['recurso_id'];
        $this->sesion=(new ErpCajaService($this->r))->abrir($this->owner,$this->d(['fondo'=>'0']))['recurso_id'];
        $this->r->q("INSERT INTO inventario_productos(sku,nombre,unidad,stock_milesimas) VALUES('BC','Producto de ensayo','unidad',100000)");$this->producto=$this->r->id();
        $this->catalogo=$m->catalogo($this->owner,$this->d(['origen'=>'producto','origen_id'=>$this->producto,'precio'=>'20','impuesto'=>'0','tipo'=>'venta']))['recurso_id'];
    }
    protected function tearDown():void {BaseDatosAislada::destruir($this->db);}
    private function d(array $d=[]):array {return $d+['idem_key'=>R5CajaFixture::clave()];}
    private function n(string $sql):int {return (int)($this->r->uno($sql)['n']??0);}
    private function venta(string $cantidad='1'):int
    {
        $s=new ErpVentaService($this->r);$v=$s->crear($this->owner,$this->d(['contacto_id'=>$this->contacto]))['recurso_id'];
        $s->agregar($this->owner,$this->d(['venta_id'=>$v,'catalogo_id'=>$this->catalogo,'cantidad'=>$cantidad]));
        $s->confirmar($this->owner,$this->d(['venta_id'=>$v,'version'=>$this->r->uno('SELECT version FROM erp_ventas WHERE id=?',[$v])['version']]));return $v;
    }
    private function efectivo(int $v,string $monto):void {(new ErpPagoService($this->r))->cobrar($this->owner,$this->d(['venta_id'=>$v,'monto'=>$monto,'metodo'=>'efectivo','recibido'=>$monto]));}
    private function transferencia(int $v,string $monto,string $ref):int
    {
        (new ErpPagoService($this->r))->cobrar($this->owner,$this->d(['venta_id'=>$v,'monto'=>$monto,'metodo'=>'transferencia','referencia'=>$ref]));
        return (int)$this->r->uno("SELECT p.id FROM pagos p JOIN erp_pago_aplicaciones a ON a.pago_id=p.id WHERE a.venta_id=? AND p.metodo_pago='transferencia' ORDER BY p.id DESC LIMIT 1",[$v])['id'];
    }
    private function devolver(int $v,string $cantidad='1'):void
    {
        $l=$this->r->uno('SELECT id FROM erp_venta_lineas WHERE venta_id=?',[$v]);
        (new ErpDevolucionService($this->r))->devolver($this->owner,$this->d(['venta_id'=>$v,'linea_id'=>$l['id'],'cantidad'=>$cantidad,'motivo'=>'Devolución de ensayo','reintegrar_stock'=>'0']));
    }
    private function inserta(string $sql):int {$this->db->query($sql);return (int)$this->db->insert_id;}

    /** Escenario con todos los tipos de hecho de dinero. Montos en centavos en $this->ids. */
    private function escenario():void
    {
        $pre=new ErpPrepagoService($this->r);
        // V1: 2 unidades (40,00) cobradas en efectivo; luego se devuelve una (20,00).
        $v1=$this->venta('2');$this->efectivo($v1,'40');$this->devolver($v1,'1');
        // V2: transferencia declarada y SIN verificar (10,00): no es dinero.
        $v2=$this->venta();$this->transferencia($v2,'10','TRF-PEND-0001');
        // V3: transferencia declarada y verificada (8,00): es dinero.
        $v3=$this->venta();$p3=$this->transferencia($v3,'8','TRF-CONF-0002');
        (new ErpPagoService($this->r))->verificar($this->owner,$this->d(['venta_id'=>$v3,'pago_id'=>$p3,'decision'=>'confirmar','nota'=>'Acreditada']));
        // Prepago: carga en efectivo (50,00), aplicación (15,00) + efectivo (5,00) a V4, devolución completa de V4, y reembolso de 10,00.
        $pre->cargar($this->owner,$this->d(['prepago_id'=>$this->prepago,'metodo'=>'efectivo','monto'=>'50','recibido'=>'50']));
        $v4=$this->venta();$pre->aplicar($this->owner,$this->d(['prepago_id'=>$this->prepago,'venta_id'=>$v4,'monto'=>'15']));$this->efectivo($v4,'5');$this->devolver($v4,'1');
        $carga=(int)$this->r->uno('SELECT id FROM erp_prepago_cargas WHERE prepago_id=? ORDER BY id LIMIT 1',[$this->prepago])['id'];
        $pre->reembolsar($this->owner,$this->d(['prepago_id'=>$this->prepago,'carga_id'=>$carga,'monto'=>'10','motivo'=>'Reembolso de ensayo']));
        // Hechos que NO son dinero aunque tengan fila de caja: pago simulado (mock) y pago sin verificar con asiento previo a la verificación.
        $mock=$this->inserta("INSERT INTO pagos(cita_referencia_id,referencia_pago,proveedor,monto,moneda,estado,metodo_pago,idempotency_key,pagado_at) VALUES(0,'MOCK-BC-1','mock',7.00,'USD','completado','tarjeta','mock-bc-1',NOW())");
        $this->inserta("INSERT INTO caja_movimientos(tipo,monto_centavos,concepto,metodo,pago_id,idempotencia,huella,registrado_en) VALUES('ingreso',700,'Simulado','efectivo',$mock,'bc:mock',REPEAT('a',64),NOW())");
        $pend=$this->inserta("INSERT INTO pagos(cita_referencia_id,referencia_pago,proveedor,monto,moneda,estado,metodo_pago,idempotency_key,estado_verificacion) VALUES(0,'PEND-BC-1','manual',3.00,'USD','pendiente','transferencia','pend-bc-1','pendiente_verificacion')");
        $this->inserta("INSERT INTO caja_movimientos(tipo,monto_centavos,concepto,metodo,pago_id,idempotencia,huella,registrado_en) VALUES('ingreso',300,'Sin verificar','transferencia',$pend,'bc:pend',REPEAT('b',64),NOW())");
        // Ingreso manual de caja sin pago ni prepago.
        $this->inserta("INSERT INTO caja_movimientos(tipo,monto_centavos,concepto,metodo,idempotencia,huella,registrado_en) VALUES('ingreso',1000,'Aporte de ensayo','efectivo','bc:manual',REPEAT('c',64),NOW())");
        $this->ids=['v1'=>$v1,'v2'=>$v2,'v3'=>$v3,'v4'=>$v4,'mock'=>$mock,'pend'=>$pend];
    }
    private function suma(string $vista,string $col,string $donde='1=1'):int {return $this->n("SELECT COALESCE(SUM($col),0) n FROM `$vista` WHERE $donde");}

    public function testElFlujoDeDineroSoloCuentaDineroRealYCuadraConCaja():void
    {
        $this->escenario();
        $porTipo=[];foreach($this->db->query("SELECT tipo_flujo,SUM(importe_centavos) t,COUNT(*) n FROM bi_flujo_dinero GROUP BY tipo_flujo")->fetch_all(MYSQLI_ASSOC) as $f)$porTipo[$f['tipo_flujo']]=(int)$f['t'];
        self::assertSame(4000+500+800,$porTipo['cobro']??null,'efectivo V1 + efectivo V4 + transferencia verificada V3; ni el simulado ni el pendiente');
        self::assertSame(2000+500,$porTipo['devolucion_de_cobro']??null,'devolución de V1 y la parte en efectivo de V4');
        self::assertSame(5000,$porTipo['carga_prepago']??null);self::assertSame(1000,$porTipo['reembolso_prepago']??null);
        self::assertSame(1000,$porTipo['otro_ingreso']??null);self::assertArrayNotHasKey('otro_egreso',$porTipo);
        // Reconciliación 1: vista = caja menos las filas de pagos simulados o sin verificar.
        $caja=$this->n("SELECT COALESCE(SUM(IF(tipo='ingreso',monto_centavos,-monto_centavos)),0) n FROM caja_movimientos");
        $excluidas=$this->n("SELECT COALESCE(SUM(IF(c.tipo='ingreso',c.monto_centavos,-c.monto_centavos)),0) n FROM caja_movimientos c JOIN pagos p ON p.id=c.pago_id WHERE COALESCE(NULLIF(TRIM(p.proveedor),''),'mock')='mock' OR p.estado_verificacion IN('pendiente_verificacion','rechazado')");
        self::assertSame(700+300,$excluidas);
        self::assertSame($caja-$excluidas,$this->suma('bi_flujo_dinero','importe_con_signo_centavos'),'Σ de la vista = Σ caja − simulados − sin verificar');
        self::assertSame($this->n("SELECT COUNT(*) n FROM caja_movimientos")-2,$this->n("SELECT COUNT(*) n FROM bi_flujo_dinero"));
        // El efectivo esperado de la sesión de caja coincide con el flujo (los dos movimientos insertados a mano no tienen sesión).
        $this->assertSame(0,$this->n("SELECT COUNT(*) n FROM bi_flujo_dinero WHERE pago_id IN({$this->ids['mock']},{$this->ids['pend']})"));
    }

    public function testCadaPagoConfirmadoTieneExactamenteUnIngresoYElPrepagoNoEsDineroNuevo():void
    {
        $this->escenario();
        $pagos=$this->n("SELECT COUNT(*) n FROM pagos WHERE estado IN('completado','reembolsado') AND metodo_pago IN('efectivo','transferencia') AND COALESCE(NULLIF(TRIM(proveedor),''),'mock')<>'mock' AND COALESCE(estado_verificacion,'confirmado')='confirmado'");
        self::assertSame(3,$pagos,'V1 efectivo, V3 transferencia verificada y el efectivo de V4');
        self::assertSame($pagos,$this->n("SELECT COUNT(*) n FROM bi_flujo_dinero WHERE tipo_flujo='cobro'"));
        self::assertSame(0,$this->n("SELECT COUNT(*) n FROM (SELECT pago_id FROM bi_flujo_dinero WHERE tipo_flujo='cobro' GROUP BY pago_id HAVING COUNT(*)>1) x"),'ningún pago aparece dos veces');
        // Aplicaciones de prepago: Σ = pagos con método prepago; no entran al flujo.
        self::assertSame($this->n("SELECT COALESCE(SUM(ROUND(monto*100)),0) n FROM pagos WHERE metodo_pago='prepago'"),$this->suma('bi_aplicaciones_saldo','importe_centavos',"tipo_movimiento='aplicacion'"));
        self::assertSame(1500,$this->suma('bi_aplicaciones_saldo','importe_centavos',"tipo_movimiento='aplicacion'"));
        self::assertSame(0,$this->n("SELECT COUNT(*) n FROM bi_flujo_dinero f JOIN pagos p ON p.id=f.pago_id WHERE p.metodo_pago='prepago'"),'una aplicación de prepago no es una entrada de dinero');
        self::assertSame(0,$this->suma('bi_aplicaciones_saldo','ingresa_dinero'));
        self::assertSame(1500,$this->suma('bi_aplicaciones_saldo','importe_centavos',"tipo_movimiento='restitucion'"),'la devolución de V4 restituye al saldo lo aplicado');
        self::assertSame(0,$this->suma('bi_aplicaciones_saldo','consumo_neto_con_signo_centavos'),'aplicación − restitución = 0');
    }

    public function testLasDevolucionesSePuedenVerPorFechaDelMovimientoYPorVentaOriginalYCuadranEntreSi():void
    {
        $this->escenario();
        // Salida de caja en la vista de flujo = devoluciones de dinero que salen de caja en la vista de devoluciones.
        self::assertSame($this->suma('bi_flujo_dinero','importe_centavos',"tipo_flujo='devolucion_de_cobro'"),$this->suma('bi_devoluciones_dinero','importe_centavos','sale_de_caja=1'));
        self::assertSame(1500,$this->suma('bi_devoluciones_dinero','importe_centavos','retorna_a_saldo_prepago=1'),'lo restituido a saldo no sale de caja');
        self::assertSame($this->suma('bi_devoluciones_dinero','importe_centavos','retorna_a_saldo_prepago=1'),$this->suma('bi_aplicaciones_saldo','importe_centavos',"tipo_movimiento='restitucion'"));
        self::assertSame($this->n("SELECT COUNT(*) n FROM cobros_devoluciones"),$this->n("SELECT COUNT(*) n FROM bi_devoluciones_dinero"));
        // Por venta original.
        $v1=$this->ids['v1'];$v4=$this->ids['v4'];
        self::assertSame(2000,$this->suma('bi_devoluciones_dinero','importe_centavos',"venta_original_id=$v1"));
        self::assertSame(2000,$this->suma('bi_devoluciones_dinero','importe_centavos',"venta_original_id=$v4"),'500 de caja + 1500 a saldo');
        self::assertSame(500,$this->suma('bi_flujo_dinero','importe_centavos',"tipo_flujo='devolucion_de_cobro' AND venta_original_id=$v4"));
        // Lo devuelto en dinero nunca supera lo cobrado ni la devolución comercial de esa venta.
        foreach([$v1,$v4] as $v){
            $devuelto=$this->suma('bi_devoluciones_dinero','importe_centavos',"venta_original_id=$v");
            $comercial=$this->suma('bi_devoluciones_venta','monto_con_impuesto_centavos',"venta_original_id=$v");
            $cobrado=$this->suma('bi_flujo_dinero','importe_centavos',"tipo_flujo='cobro' AND venta_original_id=$v")+$this->suma('bi_aplicaciones_saldo','importe_centavos',"tipo_movimiento='aplicacion' AND venta_original_id=$v");
            self::assertLessThanOrEqual($comercial,$devuelto);self::assertLessThanOrEqual($cobrado,$devuelto);
        }
        self::assertSame(2000,$this->suma('bi_devoluciones_venta','monto_con_impuesto_centavos',"venta_original_id=$v1"));
        self::assertSame($this->n("SELECT COUNT(*) n FROM erp_devoluciones"),$this->n("SELECT COUNT(*) n FROM bi_devoluciones_venta"));
        // Cada columna de fecha tiene nombre propio y la fecha del movimiento es independiente de la de la venta original.
        $this->db->query("UPDATE caja_movimientos c JOIN bi_flujo_dinero f ON f.movimiento_id=c.id SET c.registrado_en='2031-03-15 10:00:00' WHERE f.tipo_flujo='devolucion_de_cobro' AND f.venta_original_id=$v1");
        $f=$this->r->uno("SELECT fecha_movimiento,fecha_venta_original,fecha_cobro_original FROM bi_flujo_dinero WHERE tipo_flujo='devolucion_de_cobro' AND venta_original_id=$v1");
        self::assertSame('2031-03-15',$f['fecha_movimiento']);self::assertNotSame('2031-03-15',$f['fecha_venta_original'],'la venta original conserva su fecha');
        self::assertSame(0,$this->n("SELECT COUNT(*) n FROM bi_flujo_dinero WHERE tipo_flujo='devolucion_de_cobro' AND fecha_movimiento=fecha_venta_original AND venta_original_id=$v1"));
    }

    public function testUnPaqueteSeSeparaDelSaldoYSuAplicacionTampocoEsDineroNuevo():void
    {
        $this->r->q("INSERT INTO servicios(nombre,precio,duracion_minutos) VALUES('Servicio del paquete',30,30)");$servicio=$this->r->id();
        $paq=new ErpPaqueteService($this->r);
        $plan=$paq->plan($this->owner,$this->d(['nombre'=>'Dos sesiones','servicio_id'=>$servicio,'sesiones'=>2,'precio'=>'20','impuesto'=>'0']))['recurso_id'];
        $pk=$paq->crear($this->owner,$this->d(['plan_id'=>$plan,'contacto_id'=>$this->contacto]))['recurso_id'];
        $prepagoPaquete=(int)$this->r->uno('SELECT prepago_id FROM erp_paquetes WHERE id=?',[$pk])['prepago_id'];
        (new ErpPrepagoService($this->r))->cargar($this->owner,$this->d(['prepago_id'=>$prepagoPaquete,'metodo'=>'efectivo','monto'=>'40','recibido'=>'40']));
        $cli=(int)$this->r->uno('SELECT usuario_id FROM clientes LIMIT 1')['usuario_id'];
        $cita=R5CajaFixture::cita($this->db,$cli,null,['30.00']);$this->r->q('UPDATE citasservicios SET servicioId=? WHERE citaId=?',[$servicio,$cita]);
        $v=(new ErpVentaService($this->r))->crear($this->owner,$this->d(['cita_id'=>$cita]))['recurso_id'];
        $paq->aplicar($this->owner,$this->d(['paquete_id'=>$pk,'venta_id'=>$v,'linea_id'=>$this->r->uno('SELECT id FROM erp_venta_lineas WHERE venta_id=?',[$v])['id'],'version'=>$this->r->uno('SELECT version FROM erp_ventas WHERE id=?',[$v])['version']]));
        $fila=$this->r->uno("SELECT origen_saldo,paquete_id,ingresa_dinero,importe_centavos FROM bi_aplicaciones_saldo WHERE tipo_movimiento='aplicacion'");
        self::assertSame('paquete',$fila['origen_saldo']);self::assertSame($pk,(int)$fila['paquete_id']);self::assertSame(0,(int)$fila['ingresa_dinero']);self::assertSame(2000,(int)$fila['importe_centavos']);
        self::assertSame(4000,$this->suma('bi_flujo_dinero','importe_centavos',"tipo_flujo='carga_prepago'"),'el dinero del paquete entra una sola vez, en la carga');
        self::assertSame(0,$this->suma('bi_flujo_dinero','importe_centavos',"tipo_flujo='cobro'"),'aplicar una sesión no es un cobro');
    }

    public function testLasNotasDeCreditoSonUnaMetricaPropiaYNoAlteranElFlujoDeDinero():void
    {
        $this->escenario();
        $antes=$this->suma('bi_flujo_dinero','importe_con_signo_centavos');
        $v4=$this->ids['v4'];$dev=(int)$this->r->uno('SELECT id FROM erp_devoluciones WHERE venta_id=?',[$v4])['id'];$linea=(int)$this->r->uno('SELECT linea_id FROM erp_devoluciones WHERE id=?',[$dev])['linea_id'];
        $perfil=$this->inserta("INSERT INTO erp_fiscal_perfiles(ruc,ambiente,establecimiento,punto_emision,datos_json,certificado_cifrado,certificado_sha256,creado_por,creado_en) VALUES('1790012345001','1','001','001','{}','x',REPEAT('d',64),{$this->owner},NOW())");
        $snap=function(string $n):string{return json_encode(['emisor'=>['ruc'=>'1790012345001','ambiente'=>'1'],'numero'=>$n]);};
        $factura=$this->inserta("INSERT INTO erp_fiscal_documentos(perfil_id,tipo,venta_id,clave_operacion,huella,clave_acceso,numero,snapshot_json,xml_firmado,xml_sha256,estado,creado_por,creado_en,actualizado_en) VALUES($perfil,'01',$v4,REPEAT('e',64),REPEAT('e',64),REPEAT('1',49),'001-001-000000001','".$this->db->real_escape_string($snap('f1'))."','<x/>',REPEAT('e',64),'autorizada',{$this->owner},NOW(),NOW())");
        $nota=$this->inserta("INSERT INTO erp_fiscal_documentos(perfil_id,tipo,documento_origen_id,venta_id,clave_operacion,huella,clave_acceso,numero,snapshot_json,xml_firmado,xml_sha256,estado,creado_por,creado_en,actualizado_en) VALUES($perfil,'04',$factura,$v4,REPEAT('f',64),REPEAT('f',64),REPEAT('2',49),'001-001-000000001','".$this->db->real_escape_string($snap('n1'))."','<x/>',REPEAT('f',64),'autorizada',{$this->owner},NOW(),NOW())");
        $this->inserta("INSERT INTO erp_fiscal_nc_lineas(documento_id,linea_id,cantidad_milesimas,base_centavos,iva_centavos,total_centavos) VALUES($nota,$linea,1000,1739,261,2000)");
        $this->inserta("INSERT INTO erp_fiscal_nc_devoluciones(documento_id,devolucion_id) VALUES($nota,$dev)");
        $f=$this->r->uno("SELECT * FROM bi_notas_credito");
        self::assertSame([$nota,$v4,'autorizada',1739,261,2000,1],[(int)$f['nota_credito_id'],(int)$f['venta_original_id'],$f['estado'],(int)$f['base_centavos'],(int)$f['iva_centavos'],(int)$f['total_centavos'],(int)$f['lineas']]);
        self::assertSame(1,$this->n("SELECT COUNT(*) n FROM bi_notas_credito"),'la factura (tipo 01) no es nota de crédito');
        $e=$this->r->uno("SELECT nota_credito_vinculada_id v,nota_credito_efectiva_id e FROM bi_devoluciones_venta WHERE devolucion_id=$dev");self::assertSame([$nota,$nota],[(int)$e['v'],(int)$e['e']]);
        self::assertSame([null,null,1],array_map(fn($x)=>$x===null?null:(int)$x,array_values($this->r->uno("SELECT sustituye_a_id,reemplazada_por_id,es_nota_vigente FROM bi_notas_credito"))));
        self::assertSame($antes,$this->suma('bi_flujo_dinero','importe_con_signo_centavos'),'una nota de crédito no mueve dinero');
        self::assertSame($this->suma('bi_notas_credito','total_centavos'),$this->suma('bi_devoluciones_venta','monto_con_impuesto_centavos',"nota_credito_efectiva_id=$nota"),'la nota respalda exactamente la devolución comercial enlazada');
    }

    public function testUnaNotaRechazadaYSuSustitutaResuelvenLaNotaEfectivaDeCadaDevolucion():void
    {
        $v=$this->venta('3');$this->efectivo($v,'60');$this->devolver($v,'1');$this->devolver($v,'1');$this->devolver($v,'1');
        $devs=array_map(fn($f)=>(int)$f['id'],$this->r->q('SELECT id FROM erp_devoluciones WHERE venta_id=? ORDER BY id',[$v]));
        $linea=(int)$this->r->uno('SELECT linea_id FROM erp_devoluciones WHERE id=?',[$devs[0]])['linea_id'];
        $perfil=$this->inserta("INSERT INTO erp_fiscal_perfiles(ruc,ambiente,establecimiento,punto_emision,datos_json,certificado_cifrado,certificado_sha256,creado_por,creado_en) VALUES('1790012345001','1','001','001','{}','x',REPEAT('d',64),{$this->owner},NOW())");
        $doc=function(string $tipo,?int $origen,?int $sustituye,string $estado,string $k,string $acceso,string $numero)use($perfil,$v):int{
            $snap=$this->db->real_escape_string(json_encode(['emisor'=>['ruc'=>'1790012345001','ambiente'=>'1'],'k'=>$k]));
            return $this->inserta("INSERT INTO erp_fiscal_documentos(perfil_id,tipo,documento_origen_id,sustituye_a_id,venta_id,clave_operacion,huella,clave_acceso,numero,snapshot_json,xml_firmado,xml_sha256,estado,creado_por,creado_en,actualizado_en) VALUES($perfil,'$tipo',".($origen??'NULL').",".($sustituye??'NULL').",$v,REPEAT('$k',64),REPEAT('$k',64),REPEAT('$acceso',49),'$numero','$snap','<x/>',REPEAT('$k',64),'$estado',{$this->owner},NOW(),NOW())");
        };
        $factura=$doc('01',null,null,'autorizada','1','1','001-001-000000010');
        $n1=$doc('04',$factura,null,'rechazada','2','2','001-001-000000011');      // rechazada: cubría las devoluciones 1 y 2
        $n2=$doc('04',$factura,$n1,'rechazada','3','3','001-001-000000012');        // sustituta, también rechazada: hereda 1 y 2 y suma la 3
        $n3=$doc('04',$factura,$n2,'autorizada','4','4','001-001-000000013');       // segunda sustitución, autorizada: cadena de tres notas
        foreach([[$n1,[2000,2000],[1739,1739],[261,261]],[$n2,[2000,2000,2000],[1739,1739,1739],[261,261,261]],[$n3,[2000,2000,2000],[1739,1739,1739],[261,261,261]]] as [$nota,$tot,$base,$iva]){
            // Una línea fiscal por nota (clave primaria documento+línea): el total de la nota es la suma de lo que acredita.
            $this->inserta("INSERT INTO erp_fiscal_nc_lineas(documento_id,linea_id,cantidad_milesimas,base_centavos,iva_centavos,total_centavos) VALUES($nota,$linea,".(1000*count($tot)).",".array_sum($base).",".array_sum($iva).",".array_sum($tot).")");
        }
        $this->inserta("INSERT INTO erp_fiscal_nc_devoluciones(documento_id,devolucion_id) VALUES($n1,{$devs[0]}),($n1,{$devs[1]}),($n3,{$devs[2]})");
        $f=[];foreach($this->db->query("SELECT devolucion_id,nota_credito_vinculada_id vinculada,nota_credito_efectiva_id efectiva FROM bi_devoluciones_venta WHERE venta_original_id=$v ORDER BY devolucion_id")->fetch_all(MYSQLI_ASSOC) as $x)$f[(int)$x['devolucion_id']]=[(int)$x['vinculada'],(int)$x['efectiva']];
        self::assertSame([$devs[0]=>[$n1,$n3],$devs[1]=>[$n1,$n3],$devs[2]=>[$n3,$n3]],$f,'las devoluciones heredadas por la nota rechazada se resuelven a la nota vigente');
        self::assertSame(3,$this->n("SELECT COUNT(*) n FROM bi_devoluciones_venta WHERE venta_original_id=$v"),'una fila por devolución aunque haya cadena de notas');
        $notas=[];foreach($this->db->query("SELECT nota_credito_id,sustituye_a_id,reemplazada_por_id,es_nota_vigente,estado FROM bi_notas_credito ORDER BY nota_credito_id")->fetch_all(MYSQLI_ASSOC) as $x)$notas[(int)$x['nota_credito_id']]=[$x['sustituye_a_id']===null?null:(int)$x['sustituye_a_id'],$x['reemplazada_por_id']===null?null:(int)$x['reemplazada_por_id'],(int)$x['es_nota_vigente'],$x['estado']];
        self::assertSame([$n1=>[null,$n2,0,'rechazada'],$n2=>[$n1,$n3,0,'rechazada'],$n3=>[$n2,null,1,'autorizada']],$notas);
        // Conciliación: lo que acredita la nota vigente y autorizada = devoluciones cuya nota efectiva es esa.
        self::assertSame($this->suma('bi_notas_credito','total_centavos',"nota_credito_id=$n3 AND es_nota_vigente=1 AND estado='autorizada'"),$this->suma('bi_devoluciones_venta','monto_con_impuesto_centavos',"nota_credito_efectiva_id=$n3"));
    }

    public function testLaCitaDeUnPagoSobreviveAlBorradoDeLaCitaYUnaVentaSinCitaNoInventaUna():void
    {
        $historica=$this->inserta("INSERT INTO pagos(cita_id,cita_referencia_id,referencia_pago,proveedor,monto,moneda,estado,metodo_pago,idempotency_key,pagado_at,estado_verificacion) VALUES(NULL,555,'HIST-1','manual',12.00,'USD','completado','efectivo','hist-1',NOW(),'confirmado')");
        $this->inserta("INSERT INTO caja_movimientos(tipo,monto_centavos,concepto,metodo,pago_id,idempotencia,huella,registrado_en) VALUES('ingreso',1200,'Cobro histórico','efectivo',$historica,'bc:hist',REPEAT('d',64),NOW())");
        $this->inserta("INSERT INTO cobros_devoluciones(pago_id,cita_id,monto_centavos,metodo,motivo,origen,registrado_en) VALUES($historica,NULL,200,'efectivo','Histórica','migracion',NOW())");
        $this->inserta("INSERT INTO caja_movimientos(tipo,monto_centavos,concepto,metodo,pago_id,devolucion_id,idempotencia,huella,registrado_en) VALUES('egreso',200,'Devolución histórica','efectivo',$historica,1,'bc:hist-dev',REPEAT('e',64),NOW())");
        $sinCita=$this->inserta("INSERT INTO pagos(cita_id,cita_referencia_id,referencia_pago,proveedor,monto,moneda,estado,metodo_pago,idempotency_key,pagado_at,estado_verificacion) VALUES(NULL,0,'SINCITA-1','manual',9.00,'USD','completado','efectivo','sincita-1',NOW(),'confirmado')");
        $this->inserta("INSERT INTO caja_movimientos(tipo,monto_centavos,concepto,metodo,pago_id,idempotencia,huella,registrado_en) VALUES('ingreso',900,'Sin cita','efectivo',$sinCita,'bc:sincita',REPEAT('f',64),NOW())");
        self::assertSame([555,555,null],[
            (int)$this->r->uno("SELECT cita_origen_id FROM bi_flujo_dinero WHERE pago_id=$historica AND sentido='entrada'")['cita_origen_id'],
            (int)$this->r->uno("SELECT cita_origen_id FROM bi_flujo_dinero WHERE pago_id=$historica AND sentido='salida'")['cita_origen_id'],
            $this->r->uno("SELECT cita_origen_id FROM bi_flujo_dinero WHERE pago_id=$sinCita")['cita_origen_id'],
        ]);
        self::assertSame(555,(int)$this->r->uno("SELECT cita_origen_id FROM bi_devoluciones_dinero WHERE pago_id=$historica")['cita_origen_id'],'la devolución de dinero conserva la cita del pago original');
    }

    public function testElGranoSeConservaSinDuplicarFilasAlUnirTablas():void
    {
        $this->escenario();
        foreach(['bi_flujo_dinero'=>'movimiento_id','bi_devoluciones_dinero'=>'cobro_devolucion_id','bi_aplicaciones_saldo'=>'movimiento_id','bi_devoluciones_venta'=>'devolucion_id'] as $v=>$id){
            self::assertSame($this->n("SELECT COUNT(*) n FROM `$v`"),$this->n("SELECT COUNT(DISTINCT $id) n FROM `$v`"),"$v: una fila por hecho");
        }
        self::assertSame($this->n("SELECT COUNT(*) n FROM erp_prepago_movimientos WHERE tipo IN('aplicacion','restitucion')"),$this->n("SELECT COUNT(*) n FROM bi_aplicaciones_saldo"));
        // Pagos con el mismo importe en la misma venta no se colapsan por DISTINCT.
        $v=$this->venta('2');$this->efectivo($v,'10');$this->efectivo($v,'10');
        self::assertSame(2,$this->n("SELECT COUNT(*) n FROM bi_flujo_dinero WHERE tipo_flujo='cobro' AND venta_original_id=$v AND importe_centavos=1000"));
    }

    public function testLasVistasSonDeSoloLecturaSinDatosPersonalesYElUsuarioDeBiSoloLasLee():void
    {
        $this->escenario();
        $prohibidas=['comprador','contacto','nombre','email','correo','telefono','identificacion','ruc','cedula','direccion','json','motivo','concepto','referencia','actor'];
        foreach(self::VISTAS as $v){
            self::assertSame(1,$this->n("SELECT COUNT(*) n FROM information_schema.views WHERE table_schema=DATABASE() AND table_name='$v' AND security_type='DEFINER'"));
            $cols=array_column($this->db->query("SELECT column_name c FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='$v'")->fetch_all(MYSQLI_ASSOC),'c');
            foreach($cols as $c)foreach($prohibidas as $p)self::assertStringNotContainsString($p,strtolower($c),"$v.$c no debe exponer datos personales ni texto libre");
            $texto=json_encode($this->db->query("SELECT * FROM `$v`")->fetch_all(MYSQLI_ASSOC));
            self::assertStringNotContainsString('Clienta Privada',$texto);self::assertStringNotContainsString('Devolución de ensayo',$texto);
        }
        $bd=BaseDatosAislada::nombre($this->db);$usuario='bi_c_'.bin2hex(random_bytes(4));$clave='Bi'.bin2hex(random_bytes(6));
        $this->db->query("CREATE USER '$usuario'@'%' IDENTIFIED BY '$clave'");
        try{
            foreach(self::VISTAS as $v)$this->db->query("GRANT SELECT ON `$bd`.`$v` TO '$usuario'@'%'");
            mysqli_report(MYSQLI_REPORT_OFF);
            $bi=new \mysqli(getenv('DB_HOST')?:'appsalon-test-db',$usuario,$clave,$bd,(int)(getenv('DB_PORT')?:3306));
            self::assertSame(0,$bi->connect_errno);
            foreach(self::VISTAS as $v)self::assertNotFalse($bi->query("SELECT COUNT(*) FROM `$v`"),"$v legible por el usuario de BI: ".$bi->error);
            foreach(['pagos','caja_movimientos','cobros_devoluciones','erp_prepago_movimientos','erp_fiscal_documentos','erp_contactos'] as $t)self::assertFalse($bi->query("SELECT 1 FROM `$t` LIMIT 1"),"el usuario de BI no lee $t");
            foreach(["UPDATE bi_flujo_dinero SET importe_centavos=0","DELETE FROM bi_devoluciones_dinero","UPDATE bi_aplicaciones_saldo SET importe_centavos=0","DELETE FROM bi_devoluciones_venta","UPDATE bi_notas_credito SET total_centavos=0"] as $sql)self::assertFalse($bi->query($sql),$sql);
            $bi->close();
        }finally{mysqli_report(MYSQLI_REPORT_OFF);$this->db->query("DROP USER IF EXISTS '$usuario'@'%'");}
        // Ni siquiera el dueño de la conexión puede escribir a través de las vistas.
        $caja=$this->n("SELECT COUNT(*) n FROM caja_movimientos");
        mysqli_report(MYSQLI_REPORT_OFF);
        foreach(["UPDATE bi_flujo_dinero SET importe_centavos=0","DELETE FROM bi_flujo_dinero","UPDATE bi_devoluciones_dinero SET importe_centavos=0","DELETE FROM bi_aplicaciones_saldo","UPDATE bi_devoluciones_venta SET monto_con_impuesto_centavos=0"] as $sql)self::assertFalse($this->db->query($sql),"vista no actualizable: $sql");
        mysqli_report(MYSQLI_REPORT_OFF);
        self::assertSame($caja,$this->n("SELECT COUNT(*) n FROM caja_movimientos"));
    }
}

<?php
namespace Tests\Integration;
use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\OperacionRepository;
use Repositories\PagoRepository;
use Services\FinanzasService;
use Services\InventarioService;
use Services\PagoService;
final class FinanzasInventarioIntegrationTest extends TestCase
{
    private \mysqli $db;
    private int $actor;
    protected function setUp(): void
    {
        $this->db=new \mysqli(getenv('DB_HOST') ?: 'appsalon-test-db',getenv('DB_USER') ?: 'root',getenv('DB_PASS') ?: 'root',getenv('DB_NAME') ?: 'appsalon_test',(int)(getenv('DB_PORT') ?: 3306));
        $this->db->set_charset('utf8mb4'); ActiveRecord::setDB($this->db); validar_base_datos_prueba($this->db);
        $email='finanzas-'.bin2hex(random_bytes(8)).'@example.test';
        $stmt=$this->db->prepare("INSERT INTO usuarios (nombre,apellido,email,password,telefono,admin,confirmado) VALUES ('Caja','Prueba',?,'hash','0999999999',1,1)");
        $stmt->bind_param('s',$email);$stmt->execute();$stmt->close();$this->actor=(int)$this->db->insert_id;
    }
    public function testStockNoNegativoIdempotenteYHuella(): void
    {
        $service=new InventarioService(new OperacionRepository($this->db));
        $id=$service->crear(['sku'=>'QA-'.bin2hex(random_bytes(6)),'nombre'=>'Champú QA','unidad'=>'ml','minimo'=>'1','costo'=>'2.50']);
        $entrada=['producto_id'=>$id,'tipo'=>'entrada','cantidad'=>'2.001','motivo'=>'Compra prueba','idempotencia'=>bin2hex(random_bytes(12))];
        $primero=$service->mover($this->actor,$entrada);$replay=$service->mover($this->actor,$entrada);
        self::assertSame($primero['id'],$replay['id']); self::assertTrue($replay['idempotente']);
        $salida=['producto_id'=>$id,'tipo'=>'salida','cantidad'=>'2.002','motivo'=>'Consumo','idempotencia'=>bin2hex(random_bytes(12))];
        try { $service->mover($this->actor,$salida); self::fail('Debe impedir stock negativo.'); } catch (\InvalidArgumentException $e) { self::assertStringContainsString('Stock insuficiente',$e->getMessage()); }
        self::assertSame(2001,(int)$this->db->query("SELECT stock_milesimas FROM inventario_productos WHERE id=$id")->fetch_row()[0]);
        $entrada['cantidad']='3';
        try { $service->mover($this->actor,$entrada); self::fail('Debe detectar cambio de payload.'); } catch (\InvalidArgumentException $e) { self::assertStringContainsString('otra operación',$e->getMessage()); }
        self::assertSame(1,(int)$this->db->query("SELECT COUNT(*) FROM inventario_movimientos WHERE producto_id=$id")->fetch_row()[0]);
    }
    public function testCajaLibreNoDuplicaYMantieneCentavos(): void
    {
        $service=new FinanzasService(new OperacionRepository($this->db));
        $datos=['tipo'=>'ingreso','metodo'=>'efectivo','concepto'=>'Apertura prueba','monto'=>'10.01','idempotencia'=>bin2hex(random_bytes(12))];
        $a=$service->registrar($this->actor,$datos);$b=$service->registrar($this->actor,$datos);
        self::assertSame($a['id'],$b['id']);self::assertTrue($b['idempotente']);
        self::assertSame(1001,(int)$this->db->query('SELECT monto_centavos FROM caja_movimientos WHERE id='.(int)$a['id'])->fetch_row()[0]);
        $hoy=(new \DateTimeImmutable('now',new \DateTimeZone('America/Guayaquil')))->format('Y-m-d');
        self::assertSame($hoy,substr($this->db->query('SELECT registrado_en FROM caja_movimientos WHERE id='.(int)$a['id'])->fetch_row()[0],0,10));
        $datos['monto']='20';$this->expectException(\InvalidArgumentException::class);$service->registrar($this->actor,$datos);
    }
    public function testPagoCanceladoNoCreaCobro(): void
    {
        $this->db->query("INSERT INTO citas (fecha,hora,usuarioId,estado) VALUES ('2030-01-02','10:00:00',{$this->actor},'cancelada')");
        $cita=(int)$this->db->insert_id;
        $res=(new PagoService(new PagoRepository($this->db)))->iniciarPago($this->actor,['cita_id'=>$cita]);
        self::assertSame(409,$res['http_code']);
        self::assertSame(0,(int)$this->db->query("SELECT COUNT(*) FROM pagos WHERE cita_id=$cita")->fetch_row()[0]);
    }
    public function testCobroManualSnapshotYDevolucionSinPasarela(): void
    {
        $this->db->query("INSERT INTO servicios (nombre,precio,duracion_minutos) VALUES ('Servicio finanzas',99.00,30)");
        $servicio=(int)$this->db->insert_id;
        $this->db->query("INSERT INTO citas (fecha,hora,usuarioId,estado) VALUES ('2030-01-02','10:00:00',{$this->actor},'confirmada')");
        $cita=(int)$this->db->insert_id;
        $this->db->query("INSERT INTO citasservicios (citaId,servicioId,nombre_servicio,precio_servicio,duracion_minutos) VALUES ($cita,$servicio,'Snapshot',15.25,30)");
        $service=new FinanzasService(new OperacionRepository($this->db));
        $datos=['tipo'=>'ingreso','metodo'=>'efectivo','concepto'=>'Recepción verificada','cita_id'=>$cita,'monto'=>'0.01','idempotencia'=>bin2hex(random_bytes(12))];
        $a=$service->registrar($this->actor,$datos);$b=$service->registrar($this->actor,$datos);
        self::assertSame($a['id'],$b['id']);
        $pago=(new PagoRepository($this->db))->findCompletadoPorCitaId($cita);
        self::assertNotNull($pago);self::assertSame('manual',$pago->proveedor);self::assertSame(15.25,$pago->monto);
        self::assertSame(409,(new PagoService(new PagoRepository($this->db)))->reembolsarPago($pago->referencia_pago)['http_code']);
        $devolucion=['referencia'=>$pago->referencia_pago,'concepto'=>'Devolución realizada','idempotencia'=>bin2hex(random_bytes(12))];
        $r=$service->reembolsar($this->actor,$devolucion);$replay=$service->reembolsar($this->actor,$devolucion);
        self::assertSame($r['id'],$replay['id']);
        self::assertSame('reembolsado',(new PagoRepository($this->db))->findById($pago->id)->estado);
        self::assertSame(0,(int)$this->db->query("SELECT SUM(IF(tipo='ingreso',monto_centavos,-monto_centavos)) FROM caja_movimientos WHERE pago_id={$pago->id}")->fetch_row()[0]);
        self::assertSame(2,(int)$this->db->query("SELECT COUNT(*) FROM caja_movimientos WHERE pago_id={$pago->id}")->fetch_row()[0]);
    }

    public function testComisionesSnapshotLiquidacionYReversaCompensable(): void
    {
        $repo=new OperacionRepository($this->db);
        $comisiones=new \Services\ComisionService($repo);
        $finanzas=new FinanzasService($repo);
        $this->db->query("INSERT INTO profesionales (nombre,activo) VALUES ('Profesional comisión',1)");
        $prof=(int)$this->db->insert_id;
        $comisiones->configurar($this->actor,['profesional_id'=>$prof,'porcentaje'=>'25']);
        $this->db->query("INSERT INTO servicios (nombre,precio,duracion_minutos) VALUES ('Servicio comisión',40.00,30)");
        $serv=(int)$this->db->insert_id;
        $cobrar=function() use($finanzas,$prof,$serv) {
            $this->db->query("INSERT INTO citas (fecha,hora,usuarioId,profesionalId,estado) VALUES ('2030-01-02','10:00:00',{$this->actor},$prof,'confirmada')");
            $cita=(int)$this->db->insert_id;
            $this->db->query("INSERT INTO citasservicios (citaId,servicioId,nombre_servicio,precio_servicio,duracion_minutos) VALUES ($cita,$serv,'Snapshot comisión',40.00,30)");
            $finanzas->registrar($this->actor,['tipo'=>'ingreso','metodo'=>'efectivo','concepto'=>'Cobro QA','cita_id'=>$cita,'idempotencia'=>bin2hex(random_bytes(12))]);
            return (new PagoRepository($this->db))->findCompletadoPorCitaId($cita);
        };
        $pago1=$cobrar();
        $comisiones->configurar($this->actor,['profesional_id'=>$prof,'porcentaje'=>'50']);
        self::assertSame(1000,(int)$this->db->query("SELECT monto_centavos FROM comisiones_movimientos WHERE pago_id={$pago1->id}")->fetch_row()[0]);
        $liquidar=['profesional_id'=>$prof,'hasta'=>'2099-12-31','metodo'=>'efectivo','comprobante'=>'Abono QA','idempotencia'=>bin2hex(random_bytes(12))];
        $liq=$comisiones->liquidar($this->actor,$liquidar);$replay=$comisiones->liquidar($this->actor,$liquidar);
        self::assertSame(1000,$liq['monto_centavos']);self::assertSame($liq['id'],$replay['id']);
        $finanzas->reembolsar($this->actor,['referencia'=>$pago1->referencia_pago,'concepto'=>'Devolución QA','idempotencia'=>bin2hex(random_bytes(12))]);
        self::assertSame(-1000,(int)$this->db->query("SELECT monto_centavos FROM comisiones_movimientos WHERE pago_id={$pago1->id} AND tipo='reversa'")->fetch_row()[0]);
        $pago2=$cobrar();
        $liquidar['idempotencia']=bin2hex(random_bytes(12));
        $segunda=$comisiones->liquidar($this->actor,$liquidar);
        self::assertSame(1000,$segunda['monto_centavos']);
        self::assertSame(0,(int)$this->db->query("SELECT COUNT(*) FROM comisiones_movimientos WHERE profesional_id=$prof AND estado='pendiente'")->fetch_row()[0]);
        $pago3=$cobrar();
        $finanzas->reembolsar($this->actor,['referencia'=>$pago3->referencia_pago,'concepto'=>'Devolución sin abono','idempotencia'=>bin2hex(random_bytes(12))]);
        self::assertSame('anulada',$this->db->query("SELECT estado FROM comisiones_movimientos WHERE pago_id={$pago3->id}")->fetch_row()[0]);
    }

    public function testReintentoAcotadoRevierteCadaIntentoSql(): void
    {
        $repo=new OperacionRepository($this->db);
        $intentos=0;$sku='retry-'.bin2hex(random_bytes(6));
        $resultado=$repo->transaccion(function() use($repo,&$intentos,$sku) {
            $intentos++;
            $repo->consulta("INSERT INTO inventario_productos (sku,nombre,unidad) VALUES (?,'Retry QA','unidad')",'s',[$sku]);
            if ($intentos<3) { throw new \Repositories\PersistenceException('Fallo SQL recuperable de prueba.', $intentos===1 ? 1213 : 1205); }
            return 'confirmado';
        });
        self::assertSame(3,$intentos);self::assertSame('confirmado',$resultado);
        self::assertSame(1,count($repo->consulta('SELECT id FROM inventario_productos WHERE sku=?','s',[$sku])));
        $intentos=0;$sku='exhaust-'.bin2hex(random_bytes(6));
        try {
            $repo->transaccion(function() use($repo,&$intentos,$sku) {
                $intentos++;
                $repo->consulta("INSERT INTO inventario_productos (sku,nombre,unidad) VALUES (?,'Retry exhausted QA','unidad')",'s',[$sku]);
                throw new \Repositories\PersistenceException('Deadlock persistente de prueba.',1213);
            });
            self::fail('La tercera excepción debe propagarse.');
        } catch (\Repositories\PersistenceException $e) { self::assertSame(1213,$e->getCode()); }
        self::assertSame(3,$intentos);
        self::assertSame([], $repo->consulta('SELECT id FROM inventario_productos WHERE sku=?','s',[$sku]));
    }
    public function testErrorSqlNoRecuperableNoSeReintenta(): void
    {
        $repo=new OperacionRepository($this->db);$intentos=0;
        try {
            $repo->transaccion(function() use($repo,&$intentos) {
                $intentos++;$repo->consulta('SELECT * FROM tabla_inexistente_finanzas_qa');
            });
            self::fail('Consulta inválida debe fallar.');
        } catch (\Throwable $e) {
            self::assertSame(1146,(int)$e->getCode());
        }
        self::assertSame(1,$intentos);
    }

}

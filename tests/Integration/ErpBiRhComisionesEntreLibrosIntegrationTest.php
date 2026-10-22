<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';
use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\{ErpRepository,OperacionRepository};
use Services\{CobroCitaService,ComisionService,ErpCajaService,ErpComisionService,ErpCompraService,ErpDevolucionService,ErpPagoService,ErpVentaService};

/**
 * Caso de extremo a extremo de 061: una cita cobrada ANTES del punto de venta (libro heredado, por profesional) y vendida después en el ERP
 * (libro por colaborador). La comisión de la cita es una sola: 10 % de $100.00 = $10.00, repartida entre los dos libros y nunca duplicada,
 * tampoco al liquidarla, pagarla ni revertirla con una devolución. Se lee todo a través de las vistas bi_rh_*.
 */
final class ErpBiRhComisionesEntreLibrosIntegrationTest extends TestCase
{
    private \mysqli $db;private ErpRepository $r;private int $owner;private int $col;private int $prof;private int $cita;private int $venta;private int $linea;
    protected function setUp():void
    {
        $this->db=BaseDatosAislada::crear('birhlib');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);
        $this->owner=R5CajaFixture::usuario($this->db,'propietario');
        $this->r->q("INSERT INTO colaboradores(usuario_id,email_norm,nombre,apellido,rol,estado,fecha_ingreso,aceptado_en) SELECT id,email,'Ana','Privada','profesional','activo','2024-01-01',NOW() FROM usuarios WHERE id=?",[R5CajaFixture::usuario($this->db,'profesional')]);
        $this->col=$this->r->id();
        $this->prof=R5CajaFixture::profesional($this->db,1000);
        $this->db->query("UPDATE profesionales SET colaborador_id={$this->col} WHERE id={$this->prof}");
        (new ErpComisionService($this->r))->configurar($this->owner,$this->d(['colaborador_id'=>$this->col,'porcentaje'=>'10','desde'=>'2020-01-01']));
        (new ErpCajaService($this->r))->abrir($this->owner,$this->d(['fondo'=>'200']));
        $cli=R5CajaFixture::usuario($this->db);$this->r->q("INSERT INTO clientes(usuario_id,estado,origen) VALUES(?,'activo','registro_publico')",[$cli]);
        $this->cita=R5CajaFixture::cita($this->db,$cli,$this->prof,['100.00'],'confirmada','2026-10-05');
        // 1) Anticipo de $40.00 cobrado en caja ANTES del punto de venta: devenga en el libro heredado.
        (new CobroCitaService(new OperacionRepository($this->db)))->cobrar($this->owner,$this->d(['cita_id'=>$this->cita,'metodo'=>'efectivo','monto'=>'40.00','recibido'=>'40.00']));
        // 2) La cita se atiende, se vende en el ERP (adopta el anticipo) y se cobra el resto: devenga en el libro del ERP.
        $this->db->query("UPDATE citas SET estado='completada',atencion_fin_at='2026-10-05 11:00:00' WHERE id={$this->cita}");
        $s=new ErpVentaService($this->r);$this->venta=$s->crear($this->owner,$this->d(['cita_id'=>$this->cita]))['recurso_id'];
        $s->confirmar($this->owner,$this->d(['venta_id'=>$this->venta,'version'=>$this->r->uno('SELECT version FROM erp_ventas WHERE id=?',[$this->venta])['version']]));
        (new ErpPagoService($this->r))->cobrar($this->owner,$this->d(['venta_id'=>$this->venta,'metodo'=>'efectivo','monto'=>'60.00','recibido'=>'60.00']));
        $this->linea=(int)$this->r->uno('SELECT id n FROM erp_venta_lineas WHERE venta_id=?',[$this->venta])['n'];
    }
    protected function tearDown():void {BaseDatosAislada::destruir($this->db);}
    private function d(array $d=[]):array {return $d+['idem_key'=>R5CajaFixture::clave()];}
    private function n(string $sql):int {return (int)($this->db->query($sql)->fetch_row()[0]??0);}
    private function hoy():string {return substr(OperacionRepository::ahora(),0,10);}
    private function devolverTodo():void
    {
        (new ErpDevolucionService($this->r))->devolver($this->owner,$this->d(['venta_id'=>$this->venta,'linea_id'=>$this->linea,'cantidad'=>'1','motivo'=>'Devolución completa de ensayo','reintegrar_stock'=>'0']));
    }
    /** @return array{0:int,1:int} comisión devengada vigente en el libro heredado y en el del ERP, leída por las vistas */
    private function porLibro():array
    {
        return [$this->n("SELECT COALESCE(SUM(monto_centavos),0) FROM bi_rh_comisiones_movimientos WHERE sistema='anticipo_previo'"),$this->n("SELECT COALESCE(SUM(monto_centavos),0) FROM bi_rh_comisiones_movimientos WHERE sistema='erp'")];
    }

    public function testLaComisionDeUnaCitaCobradaAntesDelPdvSeDevengaUnaSolaVezEntreLosDosLibros():void
    {
        [$heredado,$erp]=$this->porLibro();
        self::assertSame([400,600],[$heredado,$erp],'el anticipo ($40) devenga $4.00 en el libro heredado; el ERP completa hasta el 10 % de $100');
        self::assertSame(1000,$heredado+$erp,'10 % de $100.00 una sola vez, no $14.00 (4 + 10)');
        self::assertSame($this->col,$this->n("SELECT colaborador_id FROM bi_rh_comisiones_movimientos WHERE sistema='anticipo_previo'"),'el libro heredado se atribuye al mismo colaborador');
        // Recalcular cualquier número de veces no devenga de nuevo.
        $filas=$this->n("SELECT COUNT(*) FROM bi_rh_comisiones_movimientos");
        for($i=0;$i<2;$i++)(new ErpComisionService($this->r))->recalcular($this->owner,$this->d(['venta_id'=>$this->venta]));
        self::assertSame($filas,$this->n("SELECT COUNT(*) FROM bi_rh_comisiones_movimientos"),'recalcular es idempotente');
        self::assertSame(1000,$this->n("SELECT SUM(monto_centavos) FROM bi_rh_comisiones_movimientos"));
    }

    public function testLiquidarPagarYRevertirDespuesDeLiquidarNoDuplicaNiPierdeComision():void
    {
        $com=new ComisionService(new OperacionRepository($this->db));
        // Liquidación y pago de cada libro por su propio canal: heredado desde caja, ERP como obligación pagada.
        $com->liquidar($this->owner,['profesional_id'=>$this->prof,'hasta'=>$this->hoy(),'metodo'=>'efectivo','comprobante'=>'COM-HER-1','idempotencia'=>R5CajaFixture::clave()]);
        $liq=(new ErpComisionService($this->r))->liquidar($this->owner,$this->d(['colaborador_id'=>$this->col,'hasta'=>$this->hoy(),'via'=>'directa']))['recurso_id'];
        $obligacion=(int)$this->r->uno('SELECT obligacion_id n FROM erp_comision_liquidaciones WHERE id=?',[$liq])['n'];
        (new ErpCompraService($this->r))->pagar($this->owner,$this->d(['obligacion_id'=>$obligacion,'monto'=>'6.00','metodo'=>'efectivo','referencia'=>'PAGO-COM']));
        $canal=fn(string $c)=>$this->n("SELECT COALESCE(SUM(monto_centavos),0) FROM bi_rh_comisiones_liquidaciones WHERE canal='$c'");
        self::assertSame([400,600,2],[$canal('caja_directa'),$canal('obligacion_directa'),$this->n("SELECT COUNT(*) FROM bi_rh_comisiones_liquidaciones")],'cada libro liquida lo suyo, una vez y por un solo canal');
        self::assertSame(1000,$this->n("SELECT SUM(pagado_centavos) FROM bi_rh_comisiones_liquidaciones"),'lo pagado = lo devengado: ni más ni menos');
        self::assertSame(1000,$this->n("SELECT SUM(monto_centavos) FROM bi_rh_comisiones_movimientos WHERE liquidada"));
        self::assertSame(0,$this->n("SELECT COALESCE(SUM(monto_centavos),0) FROM bi_rh_comisiones_movimientos WHERE NOT liquidada"),'nada queda por liquidar');
        // Devolución completa de la venta ya liquidada y pagada: cada libro reversa SOLO lo suyo (−$4.00 y −$6.00), no −$10.00 en el ERP.
        $this->devolverTodo();
        [$heredado,$erp]=$this->porLibro();
        self::assertSame([0,0],[$heredado,$erp],'cada libro queda en cero; si el ERP revirtiera también lo heredado, quedaría en −400');
        self::assertSame(0,$this->n("SELECT SUM(monto_centavos) FROM bi_rh_comisiones_movimientos"),'comisión neta de una venta totalmente devuelta = 0');
        self::assertSame(-1000,$this->n("SELECT SUM(monto_centavos) FROM bi_rh_comisiones_movimientos WHERE NOT liquidada"),'lo ya pagado se compensa con comisiones futuras');
        self::assertSame(1000,$this->n("SELECT SUM(monto_centavos) FROM bi_rh_comisiones_liquidaciones"),'las liquidaciones pagadas no se alteran');
        self::assertSame(2,$this->n("SELECT COUNT(*) FROM bi_rh_comisiones_movimientos WHERE monto_centavos<0"),'una reversa por libro');
        // Repetir el recálculo después de revertir no devuelve nada dos veces.
        (new ErpComisionService($this->r))->recalcular($this->owner,$this->d(['venta_id'=>$this->venta]));
        self::assertSame(0,$this->n("SELECT SUM(monto_centavos) FROM bi_rh_comisiones_movimientos"));
    }

    public function testRevertirAntesDeLiquidarAnulaLoHeredadoYCompensaElErpSinDobleReverso():void
    {
        $this->devolverTodo();
        self::assertSame(1,$this->n("SELECT COUNT(*) FROM comisiones_movimientos WHERE estado='anulada'"),'el devengo heredado aún sin liquidar se anula');
        [$heredado,$erp]=$this->porLibro();
        self::assertSame([0,0],[$heredado,$erp],'lo anulado no entra a la vista y el ERP queda en cero');
        self::assertSame(0,$this->n("SELECT COALESCE(SUM(monto_centavos),0) FROM bi_rh_comisiones_movimientos"),'neto cero entre ambos libros');
        self::assertSame(0,$this->n("SELECT COUNT(*) FROM bi_rh_comisiones_movimientos WHERE monto_centavos<0 AND sistema='anticipo_previo'"),'el libro heredado no suma una reversa además de la anulación');
        // Sin saldo positivo no hay nada que liquidar en ningún libro.
        $this->expectException(\Services\ErpException::class);
        (new ErpComisionService($this->r))->liquidar($this->owner,$this->d(['colaborador_id'=>$this->col,'hasta'=>$this->hoy(),'via'=>'directa']));
    }
}

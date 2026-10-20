<?php
namespace Tests\Integration;
require_once __DIR__.'/BaseDatosAislada.php';require_once __DIR__.'/R5CajaFixture.php';
use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\{ErpRepository,OperacionRepository};
use Services\{ErpNominaService,ErpFiniquitoService,ErpRhService,ErpVacacionesService,ErpContabilidadService,ErpException};

/**
 * Saldo de vacaciones con parámetros de ENSAYO (15 días por año, base 365). Colaboradora A: ingreso 2024-01-01, contrato de 600,00 terminado el 2026-09-30 → 1 004 días
 * de servicio → 41,26 días devengados. Valores esperados calculados aparte.
 */
final class ErpVacacionesIntegrationTest extends TestCase
{
    private \mysqli $db;private ErpRepository $r;private int $owner;private int $finanzas;private int $colA;
    protected function setUp():void
    {
        $this->db=BaseDatosAislada::crear('vacaciones');ActiveRecord::setDB($this->db);$this->r=new ErpRepository($this->db);
        $this->owner=R5CajaFixture::usuario($this->db,'propietario');$this->finanzas=R5CajaFixture::usuario($this->db,'finanzas');
        $u=R5CajaFixture::usuario($this->db,'profesional');
        $this->r->q("INSERT INTO colaboradores(usuario_id,email_norm,nombre,apellido,rol,estado,fecha_ingreso,aceptado_en) SELECT id,email,'Ana','Pérez','profesional','activo','2024-01-01',NOW() FROM usuarios WHERE id=?",[$u]);$this->colA=$this->r->id();
        (new ErpRhService($this->r))->contrato($this->owner,$this->d(['colaborador_id'=>$this->colA,'tipo'=>'empleado','inicio'=>'2024-01-01','fin'=>'2026-09-30','base'=>'600.00','observacion'=>'Contrato de ensayo']));
    }
    protected function tearDown():void {BaseDatosAislada::destruir($this->db);}
    private function d(array $d=[]):array {return $d+['idem_key'=>R5CajaFixture::clave()];}
    private function rh():ErpRhService {return new ErpRhService($this->r);}
    private function vac():ErpVacacionesService {return new ErpVacacionesService($this->r);}
    private function rechazo(callable $f,int $codigo):ErpException
    {try{$f();}catch(ErpException $e){self::assertSame($codigo,$e->getCode(),$e->getMessage());return $e;}self::fail('Se esperaba un rechazo '.$codigo);}
    private function parametros():void
    {
        foreach(['vacaciones.dias_anuales'=>15,'vacaciones.dias_base_anio'=>365] as $k=>$n)$this->r->q("INSERT INTO erp_nomina_parametros(clave,vigente_desde,estado,valor_entero,fuente,consultado_en,creado_por,creado_en) VALUES(?,'2000-01-01','vigente',?,'Valor de ensayo (no oficial)',CURDATE(),?,NOW())",[$k,$n,$this->owner]);
    }
    private function ausencia(string $tipo,string $desde,string $hasta,string $decision='aprobar'):int
    {
        $this->rh()->solicitarAusencia($this->owner,$this->d(['colaborador_id'=>$this->colA,'tipo'=>$tipo,'desde'=>$desde,'hasta'=>$hasta,'motivo'=>'Ensayo']));
        $id=(int)$this->r->uno('SELECT MAX(id) i FROM erp_ausencias')['i'];
        if($decision!=='ninguna')$this->rh()->resolverAusencia($this->owner,$this->d(['ausencia_id'=>$id,'decision'=>$decision]));
        return $id;
    }

    public function testSinParametrosNoHaySaldoYElMensajeDiceCuales():void
    {
        $s=$this->vac()->saldo($this->colA,'2026-09-30');
        self::assertFalse($s['disponible']);self::assertSame(['vacaciones.dias_anuales','vacaciones.dias_base_anio'],$s['faltan']);self::assertArrayNotHasKey('saldo',$s);
    }
    public function testSoloLasVacacionesAprobadasRestanYLosOtrosTiposNo():void
    {
        $this->parametros();
        $this->ausencia('vacaciones','2025-03-03','2025-03-12');// 10 días aprobados
        $this->ausencia('permiso','2025-05-05','2025-05-09');// permiso: no resta
        $this->ausencia('licencia_remunerada','2025-06-02','2025-06-06');// licencia: no resta
        $this->ausencia('vacaciones','2025-07-01','2025-07-05','rechazar');// rechazada: no resta
        $this->ausencia('vacaciones','2025-08-04','2025-08-08','ninguna');// solicitada: no resta
        $s=$this->vac()->saldo($this->colA,'2026-09-30');
        self::assertTrue($s['disponible']);self::assertSame(['dias_servicio'=>1004,'devengadas_cent'=>4126,'tomadas_cent'=>1000,'pendientes_cent'=>3126,'anticipadas_cent'=>0],$s['saldo']);
        // Cortes anteriores: a fines de 2024 todavía no se gozaba nada.
        self::assertSame(0,$this->vac()->saldo($this->colA,'2024-12-31')['saldo']['tomadas_cent']);
        // Un corte anterior al ingreso no es calculable.
        self::assertFalse($this->vac()->saldo($this->colA,'2023-12-31')['disponible']);
    }
    public function testElTipoSeValidaYLasVacacionesQueSeCruzanSeRechazan():void
    {
        $this->rechazo(fn()=>$this->rh()->solicitarAusencia($this->owner,$this->d(['colaborador_id'=>$this->colA,'tipo'=>'inventado','desde'=>'2025-03-03','hasta'=>'2025-03-04','motivo'=>'x'])),422);
        $this->ausencia('vacaciones','2025-03-03','2025-03-12','ninguna');
        $e=$this->rechazo(fn()=>$this->rh()->solicitarAusencia($this->owner,$this->d(['colaborador_id'=>$this->colA,'tipo'=>'vacaciones','desde'=>'2025-03-10','hasta'=>'2025-03-15','motivo'=>'x'])),409);self::assertStringContainsString('se cruzan',$e->getMessage());
        // Sin tipo explícito, la ausencia sigue siendo un permiso (compatibilidad con solicitudes anteriores).
        $this->rh()->solicitarAusencia($this->owner,$this->d(['colaborador_id'=>$this->colA,'desde'=>'2025-04-01','hasta'=>'2025-04-01','motivo'=>'Cita médica']));
        self::assertSame('permiso',$this->r->uno('SELECT tipo FROM erp_ausencias ORDER BY id DESC LIMIT 1')['tipo']);
    }
    public function testElExcesoTomadoSeMuestraComoAnticipadoYNoComoSaldoNegativo():void
    {
        $this->parametros();$this->ausencia('vacaciones','2025-01-01','2025-02-19');// 50 días calendario
        $s=$this->vac()->saldo($this->colA,'2026-09-30')['saldo'];
        self::assertSame(5000,$s['tomadas_cent']);self::assertSame(0,$s['pendientes_cent']);self::assertSame(874,$s['anticipadas_cent']);
    }
    public function testElFiniquitoSumaLasVacacionesRegistradasALasDeclaradasSinCambiarElResultadoEsperado():void
    {
        $this->parametros();
        foreach(['sbu_centavos'=>48200,'iess.aporte_personal_pb'=>945,'iess.aporte_patronal_pb'=>1115,'fondos_reserva.pb'=>833,'fondos_reserva.dias_antiguedad'=>365,'recargo.nocturno_pb'=>2500,'recargo.suplementaria_pb'=>5000,'recargo.extraordinaria_pb'=>10000,'jornada.horas_mes'=>240,'desahucio.pb'=>2500,'despido.meses_hasta_umbral'=>3,'despido.umbral_anios'=>3,'despido.meses_por_anio'=>1,'despido.max_meses'=>25] as $k=>$n)
            $this->r->q("INSERT INTO erp_nomina_parametros(clave,vigente_desde,estado,valor_entero,fuente,consultado_en,creado_por,creado_en) SELECT ?,'2000-01-01','vigente',?,'Valor de ensayo (no oficial)',CURDATE(),?,NOW() FROM DUAL WHERE NOT EXISTS(SELECT 1 FROM erp_nomina_parametros WHERE clave=?)",[$k,$n,$this->owner,$k]);
        $this->ausencia('vacaciones','2025-03-03','2025-03-12');// 10 días registrados
        (new ErpContabilidadService($this->r))->inicio($this->owner,$this->d(['fecha'=>'2026-01-01']));
        $nom=new ErpNominaService($this->r);
        $nom->condicion($this->owner,$this->d(['colaborador_id'=>$this->colA,'vigente_desde'=>'2024-01-01','region'=>'sierra_amazonia','mensualiza_d13'=>'0','mensualiza_d14'=>'0','fondos_reserva'=>'acumulado','aporta_iess'=>'1']));
        foreach([8,9] as $mes){$k=$nom->calcular($this->finanzas,$this->d(['anio'=>2026,'mes'=>$mes]))['recurso_id'];$nom->revisar($this->owner,$this->d(['corrida_id'=>$k]));$nom->aprobar($this->owner,$this->d(['corrida_id'=>$k]));}
        $fin=new ErpFiniquitoService($this->r);
        // 10 registrados + 5 declarados = 15 días: igual que declarar 15 a mano en la versión anterior (vacaciones 52 520).
        $id=$fin->calcular($this->finanzas,$this->d(['colaborador_id'=>$this->colA,'fecha_salida'=>'2026-09-30','causa'=>'renuncia','desahucio'=>'0','vacaciones_tomadas'=>'5']))['recurso_id'];
        $f=$this->r->uno('SELECT * FROM erp_nomina_finiquitos WHERE id=?',[$id]);
        self::assertSame(4126,(int)$f['vacaciones_devengadas_cent']);self::assertSame(1500,(int)$f['vacaciones_tomadas_cent']);self::assertSame(52520,(int)$f['vacaciones_centavos']);
        $s=json_decode($f['snapshot_json'],true);self::assertEqualsCanonicalizing(['registradas_cent'=>1000,'declaradas_cent'=>500],$s['vacaciones']);self::assertSame(1000,$s['vacaciones']['registradas_cent']);self::assertSame(500,$s['vacaciones']['declaradas_cent']);
    }
}

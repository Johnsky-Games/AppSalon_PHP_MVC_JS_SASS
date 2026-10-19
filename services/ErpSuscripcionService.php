<?php
namespace Services;

/** Ciclos mensuales con financiación previa; renovar derechos no implica debitar una tarjeta. */
final class ErpSuscripcionService extends ErpService
{
    private ?string $fechaPrueba;
    public function __construct(\Repositories\ErpRepository $r=new \Repositories\ErpRepository(),?\DateTimeImmutable $ahora=null)
    {
        parent::__construct($r);if($ahora!==null&&getenv('APP_ENV')!=='testing')throw new \LogicException('El reloj de ensayo solo está disponible en pruebas.');
        $this->fechaPrueba=$ahora?->setTimezone(new \DateTimeZone('America/Guayaquil'))->format('Y-m-d');
    }
    private function hoy():string {return $this->fechaPrueba??substr($this->r::ahora(),0,10);}
    public function crear(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.cobrar','suscripcion_crear',$d,function()use($actor,$d){
            if(($d['renovacion_autorizada']??'')!=='1')throw new ErpException('Registra la aceptación del cliente para los ciclos mensuales.');
            $evidencia=$this->texto($d['consentimiento']??'',300);$contacto=$this->id($d['contacto_id']??null);$plan=$this->id($d['plan_id']??null);$inicio=$this->fecha($d['inicio']??null);
            $clave=hash('sha256','suscripcion.crear:'.$actor.':'.$d['idem_key']);$huella=hash('sha256',json_encode([$contacto,$plan,$inicio,$evidencia]));
            $previa=$this->r->uno('SELECT id,huella FROM erp_suscripciones WHERE clave_operacion=? FOR SHARE',[$clave]);
            if($previa){if(!hash_equals($huella,$previa['huella']))throw new ErpException('Esta clave ya identifica otro contrato.',409);return (int)$previa['id'];}
            if(!$this->r->uno("SELECT id FROM erp_contactos WHERE id=? AND activo=1 AND tipo IN('cliente','ambos') FOR SHARE",[$contacto]))throw new ErpException('Selecciona un contacto cliente activo.');
            if(!$this->r->uno('SELECT id FROM erp_paquete_planes WHERE id=? AND activo=1 FOR SHARE',[$plan]))throw new ErpException('Plan no disponible.');
            if($inicio<$this->hoy())throw new ErpException('La suscripción comienza hoy o en una fecha futura.');
            $this->r->q('INSERT INTO erp_suscripciones(contacto_id,plan_id,inicio,clave_operacion,huella,consentimiento,creado_por,creado_en) VALUES(?,?,?,?,?,?,?,?)',[$contacto,$plan,$inicio,$clave,$huella,$evidencia,$actor,$this->r::ahora()]);$id=$this->r->id();
            $s=$this->r->uno('SELECT * FROM erp_suscripciones WHERE id=?',[$id]);$this->ciclo($actor,$s,$inicio);
            $this->evento($actor,'suscripcion_creada','suscripcion',$id,['plan_id'=>$plan,'contacto_id'=>$contacto,'inicio'=>$inicio,'consentimiento'=>$evidencia,'cobro'=>'manual']);return $id;
        });
    }
    public function estado(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.cobrar','suscripcion_estado',$d,function()use($actor,$d){
            $id=$this->id($d['suscripcion_id']??null);$s=$this->r->uno('SELECT * FROM erp_suscripciones WHERE id=? FOR UPDATE',[$id])??throw new ErpException('Suscripción no encontrada.',404);
            $anterior=$s['estado'];$estado=$d['estado']??'';$permitidos=['activa'=>['pausada','cancelada'],'pausada'=>['activa','cancelada'],'cancelada'=>[]];
            if(!in_array($estado,$permitidos[$s['estado']],true))throw new ErpException('La transición de suscripción no está disponible.',409);
            $motivo=$this->texto($d['motivo']??'',300);
            $this->r->q('UPDATE erp_suscripciones SET estado=?,proximo_intento=NULL,ultimo_error=NULL WHERE id=?',[$estado,$id]);
            if($estado==='activa'){$s['estado']='activa';$this->ciclo($actor,$s,$this->hoy());}
            $this->evento($actor,'suscripcion_estado','suscripcion',$id,['anterior'=>$anterior,'estado'=>$estado,'motivo'=>$motivo]);return $id;
        });
    }
    /** Ancla mensual original: 31 enero -> fin de febrero -> 31 marzo, sin deriva al día 28. Hasta es exclusivo. */
    public static function periodo(string $inicio,string $fecha):array
    {
        $z=new \DateTimeZone('America/Guayaquil');$a=new \DateTimeImmutable($inicio,$z);$f=new \DateTimeImmutable($fecha,$z);
        if($f<$a)return ['desde'=>$inicio,'hasta'=>self::ancla($a,1)];
        $n=((int)$f->format('Y')-(int)$a->format('Y'))*12+(int)$f->format('m')-(int)$a->format('m');
        if($f->format('Y-m-d')<self::ancla($a,$n))$n--;
        return ['desde'=>self::ancla($a,$n),'hasta'=>self::ancla($a,$n+1)];
    }
    private static function ancla(\DateTimeImmutable $a,int $n):string
    {
        $m=$a->modify('first day of this month')->modify('+'.$n.' months');return $m->setDate((int)$m->format('Y'),(int)$m->format('m'),min((int)$a->format('d'),(int)$m->format('t')))->format('Y-m-d');
    }
    private function ciclo(int $actor,array $s,string $fecha):int
    {
        $p=self::periodo($s['inicio'],$fecha);$existe=$this->r->uno('SELECT id FROM erp_suscripcion_ciclos WHERE suscripcion_id=? AND desde=? FOR SHARE',[(int)$s['id'],$p['desde']]);if($existe)return (int)$existe['id'];
        $paquete=(new ErpPaqueteService($this->r))->nuevo($actor,(int)$s['plan_id'],(int)$s['contacto_id']);
        $this->r->q('INSERT INTO erp_suscripcion_ciclos(suscripcion_id,desde,hasta,paquete_id,creado_por,creado_en) VALUES(?,?,?,?,?,?)',[(int)$s['id'],$p['desde'],$p['hasta'],$paquete,$actor,$this->r::ahora()]);$id=$this->r->id();
        $this->evento($actor,'suscripcion_ciclo_abierto','suscripcion',(int)$s['id'],['ciclo_id'=>$id,'desde'=>$p['desde'],'hasta_exclusivo'=>$p['hasta'],'paquete_id'=>$paquete,'cobro'=>'manual']);return $id;
    }
    public function procesar():array
    {
        if(PHP_SAPI!=='cli')throw new ErpException('Procesamiento reservado al supervisor.',403);
        $hoy=$this->hoy();$salida=['abiertos'=>0,'fallidos'=>0];
        $filas=$this->r->q("SELECT id FROM erp_suscripciones s WHERE estado='activa' AND inicio<=? AND (proximo_intento IS NULL OR proximo_intento<=?) AND NOT EXISTS(SELECT 1 FROM erp_suscripcion_ciclos c WHERE c.suscripcion_id=s.id AND c.desde<=? AND c.hasta>?) ORDER BY id LIMIT 100",[$hoy,$this->r::ahora(),$hoy,$hoy]);
        foreach($filas as $fila){try{
            $abierto=\Classes\Transaccion::ejecutar($this->r->db,function()use($fila,$hoy){
                $s=$this->r->uno('SELECT * FROM erp_suscripciones WHERE id=? FOR UPDATE',[(int)$fila['id']]);if($s['estado']!=='activa')return false;
                $p=self::periodo($s['inicio'],$hoy);if($this->r->uno('SELECT id FROM erp_suscripcion_ciclos WHERE suscripcion_id=? AND desde=? FOR SHARE',[(int)$s['id'],$p['desde']]))return false;
                $this->ciclo(0,$s,$hoy);$this->r->q('UPDATE erp_suscripciones SET proximo_intento=NULL,ultimo_error=NULL WHERE id=?',[(int)$s['id']]);return true;
            });if($abierto)$salida['abiertos']++;
        }catch(\Throwable $e){$this->r->q("UPDATE erp_suscripciones SET proximo_intento=DATE_ADD(?,INTERVAL 1 HOUR),ultimo_error='Revisar contrato y contacto beneficiario' WHERE id=?",[$this->r::ahora(),(int)$fila['id']]);$salida['fallidos']++;}}
        return $salida;
    }
}

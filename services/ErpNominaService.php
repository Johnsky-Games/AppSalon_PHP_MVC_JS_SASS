<?php
namespace Services;

use Classes\Transaccion;
use Services\Nomina\NominaRenta;

/**
 * Nómina propia (Ecuador). AppSalon NO trae cifras legales: sueldo básico, aportes, recargos y demás parámetros los registra una persona autorizada con su
 * fuente oficial y vigencia (versionados, solo de anexar). Sin un parámetro vigente la nómina no se calcula. El resultado es una política de ensayo que
 * debe validar el contador y el abogado laboral antes de pagar sueldos reales.
 *
 * Ciclo: novedades aprobadas → corrida calculada → revisada → aprobada (genera obligaciones y el hecho contable) → pagada con el flujo de obligaciones.
 * Una corrida aprobada no se edita: se anula antes de pagarse o se corrige con novedades de ajuste trazables en un mes abierto.
 * Una comisión se liquida por pago directo O por nómina, nunca por ambos: el vínculo con la corrida es único.
 */
final class ErpNominaService extends ErpService
{
    public const FORMULA='nomina-v1';
    /** Parámetros que exige el cálculo (clave => [tipo, descripción]). Todos obligatorios para calcular. */
    public const PARAMETROS=[
        'sbu_centavos'=>['importe','Salario básico unificado (USD)'],
        'iess.aporte_personal_pb'=>['pct','Aporte personal al IESS (% de la remuneración gravada)'],
        'iess.aporte_patronal_pb'=>['pct','Aporte patronal al IESS (% de la remuneración gravada)'],
        'fondos_reserva.pb'=>['pct','Fondos de reserva (% de la remuneración)'],
        'fondos_reserva.dias_antiguedad'=>['dias','Días de antigüedad desde los que corresponden los fondos de reserva'],
        'recargo.nocturno_pb'=>['pct','Recargo por hora nocturna (%)'],
        'recargo.suplementaria_pb'=>['pct','Recargo por hora suplementaria (%)'],
        'recargo.extraordinaria_pb'=>['pct','Recargo por hora extraordinaria (%)'],
        'jornada.horas_mes'=>['dias','Horas mensuales para el valor de la hora (p. ej. 240)'],
    ];
    /** Parámetros que exige SOLO el finiquito (no frenan el cálculo mensual). Todos obligatorios para calcular un finiquito. */
    public const PARAMETROS_FINIQUITO=[
        'vacaciones.dias_anuales'=>['dias','Días de vacaciones por año de servicio'],
        'vacaciones.dias_base_anio'=>['dias','Días que forman un año de servicio (p. ej. 365)'],
        'desahucio.pb'=>['pct','Bonificación por desahucio (% de la última remuneración mensual por año de servicio)'],
        'despido.meses_hasta_umbral'=>['dias','Meses de remuneración de indemnización por despido intempestivo hasta el umbral de años'],
        'despido.umbral_anios'=>['dias','Años de servicio hasta los que rige la indemnización mínima'],
        'despido.meses_por_anio'=>['dias','Meses de indemnización por cada año de servicio superado el umbral'],
        'despido.max_meses'=>['dias','Máximo de meses de indemnización'],
    ];
    /** @return array<string,array{0:string,1:string}> todos los parámetros de nómina registrables */
    public static function todosLosParametros():array {return self::PARAMETROS+self::PARAMETROS_FINIQUITO;}
    private function hoy():string {return (new \DateTimeImmutable('now',new \DateTimeZone('America/Guayaquil')))->format('Y-m-d');}
    private function rolActor(int $actor):string {return (new AuthorizationService($this->r->db))->contexto($actor)['rol']??'';}
    /** Quien prepara no aprueba su propio trabajo, salvo el propietario (negocio de una sola persona); queda en la auditoría. */
    private function distinto(int $actor,int $otro,string $mensaje):void
    {
        if($actor===$otro&&$this->rolActor($actor)!=='propietario')throw new ErpException($mensaje,403);
    }
    private static function pb(int $valor,int $pb):int {return intdiv($valor*$pb+5000,10000);}

    // ---------------------------------------------------------------- parámetros legales y condiciones
    public function parametro(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'nomina.configurar','nomina_parametro',$d,function()use($actor,$d){
            $clave=is_string($d['clave']??null)?$d['clave']:'';$defs=self::todosLosParametros();if(!isset($defs[$clave]))throw new ErpException('Parámetro de nómina no reconocido.');
            $hoy=$this->hoy();$desde=$this->fecha($d['vigente_desde']??null);$consultado=$this->fecha($d['consultado_en']??null);
            if($consultado>$hoy)throw new ErpException('La fecha de consulta de la fuente no puede ser futura.');
            if($desde>(new \DateTimeImmutable($hoy))->modify('+366 days')->format('Y-m-d')||$desde<'2000-01-01')throw new ErpException('Vigencia fuera de rango.');
            $estado=$d['estado']??'vigente';if(!in_array($estado,['vigente','derogado'],true))throw new ErpException('Estado no válido.');
            $entero=null;
            if($estado==='vigente'){
                try{$entero=match($defs[$clave][0]){'importe'=>$this->importe($d['valor']??null),'pct'=>ValoresExactos::decimal($d['valor']??null,2,true),'dias'=>$this->entero($d['valor']??null,1,100000)};}
                catch(\InvalidArgumentException $e){throw new ErpException($e->getMessage());}
                if($defs[$clave][0]==='pct'&&$entero>10000)throw new ErpException('Un porcentaje no supera 100.');
            }
            $fuente=$this->texto($d['fuente']??'',200);if(mb_strlen($fuente)<5)throw new ErpException('Indica la norma o documento oficial consultado.');
            $url=$d['fuente_url']??null;if($url!==null&&$url!==''){if(!is_string($url)||!preg_match('#^https://[^\s<>"\']{4,280}$#D',$url))throw new ErpException('El enlace de la fuente debe ser una URL https.');}else $url=null;
            $nota=isset($d['nota'])&&$d['nota']!==''?$this->texto($d['nota'],300):null;
            $ultima=$this->r->uno('SELECT vigente_desde FROM erp_nomina_parametros WHERE clave=? ORDER BY vigente_desde DESC LIMIT 1 FOR UPDATE',[$clave]);
            if($ultima&&$desde<=$ultima['vigente_desde'])throw new ErpException('Ya existe una versión desde '.$ultima['vigente_desde'].'. Registra la corrección con una vigencia posterior.',409);
            if($ultima&&$desde<$hoy)throw new ErpException('Una versión nueva rige desde hoy o una fecha futura; las anteriores se conservan.',409);
            $this->r->q('INSERT INTO erp_nomina_parametros(clave,vigente_desde,estado,valor_entero,fuente,fuente_url,consultado_en,nota,creado_por,creado_en) VALUES(?,?,?,?,?,?,?,?,?,?)',[$clave,$desde,$estado,$entero,$fuente,$url,$consultado,$nota,$actor,$this->r::ahora()]);
            $id=$this->r->id();$this->evento($actor,'nomina_parametro_registrado','nomina_parametro',$id,['clave'=>$clave,'vigente_desde'=>$desde,'estado'=>$estado,'valor'=>$entero,'fuente'=>$fuente,'fuente_url'=>$url,'consultado_en'=>$consultado]);return $id;
        });
    }
    private function entero(mixed $v,int $min,int $max):int
    {
        if((!is_int($v)&&!is_string($v))||!preg_match('/^\d{1,9}$/D',(string)$v)||(int)$v<$min||(int)$v>$max)throw new ErpException('Valor entero fuera de rango ('.$min.' a '.$max.').');return (int)$v;
    }
    public function resolver(string $clave,string $fecha):?array
    {
        $p=$this->r->uno('SELECT * FROM erp_nomina_parametros WHERE clave=? AND vigente_desde<=? ORDER BY vigente_desde DESC LIMIT 1',[$clave,$fecha]);return ($p&&$p['estado']==='vigente')?$p:null;
    }
    public function condicion(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'nomina.configurar','nomina_condicion',$d,function()use($actor,$d){
            $col=$this->r->uno("SELECT id FROM colaboradores WHERE id=? AND estado<>'baja' FOR UPDATE",[$this->id($d['colaborador_id']??null)])??throw new ErpException('Colaborador no disponible.',404);
            $desde=$this->fecha($d['vigente_desde']??null);$hoy=$this->hoy();
            $region=$d['region']??'';$fr=$d['fondos_reserva']??'';
            if(!in_array($region,['sierra_amazonia','costa_galapagos'],true)||!in_array($fr,['mensualizado','acumulado','no_aplica'],true))throw new ErpException('Revisa la región y los fondos de reserva.');
            $bool=fn($k)=>($d[$k]??'')==='1'?1:0;
            $ultima=$this->r->uno('SELECT vigente_desde FROM erp_nomina_condiciones WHERE colaborador_id=? ORDER BY vigente_desde DESC LIMIT 1 FOR UPDATE',[(int)$col['id']]);
            if($ultima&&($desde<=$ultima['vigente_desde']||$desde<$hoy))throw new ErpException('La condición nueva rige desde hoy o una fecha futura y posterior a la última ('.$ultima['vigente_desde'].').',409);
            $this->r->q('INSERT INTO erp_nomina_condiciones(colaborador_id,vigente_desde,region,mensualiza_d13,mensualiza_d14,fondos_reserva,aporta_iess,creado_por,creado_en) VALUES(?,?,?,?,?,?,?,?,?)',[(int)$col['id'],$desde,$region,$bool('mensualiza_d13'),$bool('mensualiza_d14'),$fr,$bool('aporta_iess'),$actor,$this->r::ahora()]);
            $id=$this->r->id();$this->evento($actor,'nomina_condicion_registrada','colaborador',(int)$col['id'],['condicion_id'=>$id,'vigente_desde'=>$desde,'region'=>$region,'fondos_reserva'=>$fr]);return (int)$col['id'];
        });
    }

    // ---------------------------------------------------------------- impuesto a la renta (tabla anual y declaración del trabajador)
    /** Tabla progresiva de un año (versionada, con fuente). Una línea por tramo: «desde;hasta;impuesto de la fracción;% del excedente», en USD y %; el último tramo sin límite superior. */
    public function tablaRenta(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'nomina.configurar','nomina_ir_tabla',$d,function()use($actor,$d){
            $hoy=$this->hoy();$desde=$this->fecha($d['vigente_desde']??null);$consultado=$this->fecha($d['consultado_en']??null);
            if($consultado>$hoy)throw new ErpException('La fecha de consulta de la fuente no puede ser futura.');
            $anio=filter_var($d['anio']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>2000,'max_range'=>2100]]);if($anio===false)throw new ErpException('Indica el año de la tabla.');
            if(substr($desde,0,4)!==(string)$anio)throw new ErpException('La vigencia debe caer dentro del año de la tabla.');
            $estado=$d['estado']??'vigente';if(!in_array($estado,['vigente','derogado'],true))throw new ErpException('Estado no válido.');
            $json=null;$tope=null;
            if($estado==='vigente'){
                $json=json_encode($this->tramos($d['tramos']??''),JSON_THROW_ON_ERROR);
                if(($d['tope_gastos']??'')!==''){try{$tope=ValoresExactos::decimal($d['tope_gastos'],2,true);}catch(\InvalidArgumentException $e){throw new ErpException($e->getMessage());}}
            }
            $fuente=$this->texto($d['fuente']??'',200);if(mb_strlen($fuente)<5)throw new ErpException('Indica la norma o documento oficial consultado.');
            $url=$d['fuente_url']??null;if($url!==null&&$url!==''){if(!is_string($url)||!preg_match('#^https://[^\s<>"\']{4,280}$#D',$url))throw new ErpException('El enlace de la fuente debe ser una URL https.');}else $url=null;
            $nota=isset($d['nota'])&&$d['nota']!==''?$this->texto($d['nota'],300):null;
            $ultima=$this->r->uno('SELECT vigente_desde FROM erp_nomina_ir_tablas WHERE anio=? ORDER BY vigente_desde DESC LIMIT 1 FOR UPDATE',[$anio]);
            if($ultima&&$desde<=$ultima['vigente_desde'])throw new ErpException('Ya existe una versión desde '.$ultima['vigente_desde'].'. Registra la corrección con una vigencia posterior.',409);
            if($ultima&&$desde<$hoy)throw new ErpException('Una versión nueva rige desde hoy o una fecha futura; las anteriores se conservan.',409);
            $this->r->q('INSERT INTO erp_nomina_ir_tablas(anio,vigente_desde,estado,tramos_json,tope_gastos_centavos,fuente,fuente_url,consultado_en,nota,creado_por,creado_en) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$anio,$desde,$estado,$json,$tope,$fuente,$url,$consultado,$nota,$actor,$this->r::ahora()]);
            $id=$this->r->id();$this->evento($actor,'nomina_ir_tabla_registrada','nomina_ir_tabla',$id,['anio'=>$anio,'vigente_desde'=>$desde,'estado'=>$estado,'tramos'=>$json?json_decode($json,true):null,'tope_gastos_centavos'=>$tope,'fuente'=>$fuente,'fuente_url'=>$url,'consultado_en'=>$consultado]);return $id;
        });
    }
    /** @return list<array{desde:int,hasta:?int,impuesto_base:int,excedente_pb:int}> */
    private function tramos(mixed $texto):array
    {
        if(!is_string($texto)||trim($texto)==='')throw new ErpException('Indica los tramos de la tabla.');
        $filas=[];
        foreach(preg_split('/\R/u',trim($texto)) as $i=>$linea){
            $linea=trim($linea);if($linea==='')continue;$c=array_map('trim',explode(';',$linea));
            if(count($c)!==4)throw new ErpException('Tramo '.($i+1).': usa «desde;hasta;impuesto de la fracción;% del excedente».');
            try{
                $filas[]=['desde'=>ValoresExactos::decimal($c[0],2,true),'hasta'=>($c[1]===''||mb_strtolower($c[1])==='adelante')?null:ValoresExactos::decimal($c[1],2,true),'impuesto_base'=>ValoresExactos::decimal($c[2],2,true),'excedente_pb'=>ValoresExactos::decimal($c[3],2,true)];
            }catch(\InvalidArgumentException $e){throw new ErpException('Tramo '.($i+1).': '.$e->getMessage());}
        }
        try{return NominaRenta::validarTramos($filas);}catch(\InvalidArgumentException $e){throw new ErpException($e->getMessage());}
    }
    /** Declaración anual del trabajador para proyectar su retención. La última del año rige; las anteriores se conservan. */
    public function declaracionRenta(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'nomina.configurar','nomina_ir_declaracion',$d,function()use($actor,$d){
            $col=$this->r->uno("SELECT id FROM colaboradores WHERE id=? AND estado<>'baja' FOR UPDATE",[$this->id($d['colaborador_id']??null)])??throw new ErpException('Colaborador no disponible.',404);
            $anio=filter_var($d['anio']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>2000,'max_range'=>2100]]);if($anio===false)throw new ErpException('Indica el año.');
            $m=[];foreach(['gastos','otras_deducciones','rebaja','otros_ingresos','retenido_otros'] as $k){
                try{$m[$k]=($d[$k]??'')===''?0:ValoresExactos::decimal($d[$k],2,true);}catch(\InvalidArgumentException $e){throw new ErpException($e->getMessage());}
            }
            $fuente=$this->texto($d['fuente']??'',200);if(mb_strlen($fuente)<5)throw new ErpException('Indica el documento de respaldo (p. ej. proyección de gastos personales o formulario 107 del empleador anterior).');
            $this->r->q('INSERT INTO erp_nomina_ir_declaraciones(colaborador_id,anio,gastos_centavos,otras_deducciones_centavos,rebaja_centavos,otros_ingresos_centavos,retenido_otros_centavos,fuente,creado_por,creado_en) VALUES(?,?,?,?,?,?,?,?,?,?)',[(int)$col['id'],$anio,$m['gastos'],$m['otras_deducciones'],$m['rebaja'],$m['otros_ingresos'],$m['retenido_otros'],$fuente,$actor,$this->r::ahora()]);
            $id=$this->r->id();$this->evento($actor,'nomina_ir_declaracion_registrada','colaborador',(int)$col['id'],['declaracion_id'=>$id,'anio'=>$anio]+$m+['fuente'=>$fuente]);return (int)$col['id'];
        });
    }

    // ---------------------------------------------------------------- novedades
    public function novedad(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'nomina.calcular','nomina_novedad',$d,function()use($actor,$d){
            $col=$this->r->uno("SELECT id FROM colaboradores WHERE id=? AND estado<>'baja' FOR SHARE",[$this->id($d['colaborador_id']??null)])??throw new ErpException('Colaborador no disponible.',404);
            $anio=filter_var($d['anio']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>2000,'max_range'=>2100]]);$mes=filter_var($d['mes']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>12]]);
            if($anio===false||$mes===false)throw new ErpException('Indica año y mes.');
            $tipo=$d['tipo']??'';if(!in_array($tipo,['hora_suplementaria','hora_extraordinaria','hora_nocturna','bono','descuento','retencion_ir'],true))throw new ErpException('Tipo de novedad no válido.');
            $horas=in_array($tipo,['hora_suplementaria','hora_extraordinaria','hora_nocturna'],true);
            try{$cant=$horas?ValoresExactos::decimal($d['cantidad']??null,3):null;$monto=$horas?null:$this->importe($d['monto']??null);}catch(\InvalidArgumentException $e){throw new ErpException($e->getMessage());}
            if($horas&&$cant>744000)throw new ErpException('Las horas del mes no superan 744.');
            $ajusta=($d['ajusta_linea_id']??'')===''?null:$this->id($d['ajusta_linea_id']);
            if($ajusta!==null){$l=$this->r->uno('SELECT l.colaborador_id FROM erp_nomina_lineas l JOIN erp_nomina_corridas k ON k.id=l.corrida_id WHERE l.id=? AND k.estado=\'aprobada\' FOR SHARE',[$ajusta]);if(!$l||(int)$l['colaborador_id']!==(int)$col['id'])throw new ErpException('El ajuste debe referirse a una línea aprobada del mismo colaborador.',409);}
            if($this->r->uno("SELECT id FROM erp_nomina_corridas WHERE anio*100+mes=? AND estado IN('revisada','aprobada') FOR SHARE",[$anio*100+$mes]))throw new ErpException('Ese mes ya tiene una nómina revisada o aprobada: registra el ajuste en un mes abierto.',409);
            $motivo=$this->texto($d['motivo']??'',300);$clave=hash('sha256','nomina.novedad:'.$actor.':'.$d['idem_key']);
            $previa=$this->r->uno('SELECT id FROM erp_nomina_novedades WHERE clave_operacion=? FOR SHARE',[$clave]);if($previa)return (int)$previa['id'];
            $this->r->q('INSERT INTO erp_nomina_novedades(clave_operacion,colaborador_id,anio,mes,tipo,cantidad_milesimas,monto_centavos,grava_iess,motivo,ajusta_linea_id,registrado_por,registrado_en) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$clave,(int)$col['id'],$anio,$mes,$tipo,$cant,$monto,($d['grava_iess']??'1')==='0'?0:1,$motivo,$ajusta,$actor,$this->r::ahora()]);
            $id=$this->r->id();$this->evento($actor,'nomina_novedad_registrada','colaborador',(int)$col['id'],['novedad_id'=>$id,'tipo'=>$tipo,'anio'=>$anio,'mes'=>$mes,'cantidad_milesimas'=>$cant,'monto'=>$monto,'ajusta_linea_id'=>$ajusta]);return $id;
        });
    }
    public function resolverNovedad(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'nomina.aprobar','nomina_novedad_resolver',$d,function()use($actor,$d){
            $n=$this->r->uno('SELECT * FROM erp_nomina_novedades WHERE id=? FOR UPDATE',[$this->id($d['novedad_id']??null)])??throw new ErpException('Novedad no encontrada.',404);
            if($n['estado']!=='pendiente')throw new ErpException('La novedad ya fue resuelta.',409);
            $decision=$d['decision']??'';if(!in_array($decision,['aprobar','rechazar'],true))throw new ErpException('Decisión no válida.');
            $this->distinto($actor,(int)$n['registrado_por'],'Otra persona autorizada debe resolver esta novedad.');
            $this->r->q('UPDATE erp_nomina_novedades SET estado=?,resuelto_por=?,resuelto_en=? WHERE id=?',[$decision==='aprobar'?'aprobada':'rechazada',$actor,$this->r::ahora(),(int)$n['id']]);
            $this->evento($actor,'nomina_novedad_resuelta','colaborador',(int)$n['colaborador_id'],['novedad_id'=>(int)$n['id'],'decision'=>$decision]);return (int)$n['id'];
        });
    }

    // ---------------------------------------------------------------- cálculo
    private function periodo(array $d):array
    {
        $anio=filter_var($d['anio']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>2000,'max_range'=>2100]]);$mes=filter_var($d['mes']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>12]]);
        if($anio===false||$mes===false)throw new ErpException('Indica año y mes.');
        $primero=sprintf('%04d-%02d-01',$anio,$mes);$ultimo=(new \DateTimeImmutable($primero))->modify('last day of this month')->format('Y-m-d');
        return [$anio,$mes,$primero,$ultimo];
    }
    /** Corrida del mes: calcula los rubros con los parámetros vigentes, sin consumir nada hasta la aprobación (salvo el vínculo de las comisiones por nómina). */
    public function calcular(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'nomina.calcular','nomina_calcular',$d,function()use($actor,$d){
            [$anio,$mes,$primero,$ultimo]=$this->periodo($d);if($primero>$this->hoy())throw new ErpException('No se calcula la nómina de un mes que aún no comienza.');
            $activa=$this->r->uno("SELECT id,estado FROM erp_nomina_corridas WHERE anio*100+mes=? AND estado<>'anulada' FOR UPDATE",[$anio*100+$mes]);
            if($activa)throw new ErpException('El mes ya tiene una corrida '.$activa['estado'].'. Anúlala para recalcular.',409);
            $p=[];$faltan=[];foreach(self::PARAMETROS as $clave=>[$tipo,$desc]){$x=$this->resolver($clave,$ultimo);if(!$x)$faltan[]=$clave;else $p[$clave]=$x;}
            if($faltan)throw new ErpException('Faltan parámetros de nómina vigentes: '.implode(', ',$faltan).'. Regístralos en Nómina › Parámetros después de verificarlos en la fuente oficial.',409);
            $contratos=$this->r->q("SELECT c.*,col.fecha_ingreso FROM erp_contratos c JOIN colaboradores col ON col.id=c.colaborador_id WHERE c.tipo='empleado' AND c.inicio<=? AND COALESCE(c.fin,'9999-12-31')>=? ORDER BY c.colaborador_id,c.inicio FOR SHARE",[$ultimo,$primero]);
            if(!$contratos)throw new ErpException('No hay empleados con relación contractual vigente en el mes.',409);
            $lineas=[];$sinCondicion=[];
            // Una sola línea por colaborador aunque cambie de contrato dentro del mes: los tramos se prorratean y se suman.
            $porColaborador=[];foreach($contratos as $c)$porColaborador[(int)$c['colaborador_id']][]=$c;
            foreach($porColaborador as $colId=>$tramos){
                $cond=$this->r->uno('SELECT * FROM erp_nomina_condiciones WHERE colaborador_id=? AND vigente_desde<=? ORDER BY vigente_desde DESC LIMIT 1 FOR SHARE',[$colId,$ultimo]);
                if(!$cond){$sinCondicion[]=$colId;continue;}
                $lineas[]=$this->lineaDe($tramos,$cond,$p,$anio,$mes,$primero,$ultimo);
            }
            if($sinCondicion)throw new ErpException('Registra las condiciones de nómina (región, décimos, fondos de reserva, IESS) de los colaboradores: '.implode(', ',$sinCondicion).'.',409);
            $huella=hash('sha256',json_encode(array_map(fn($l)=>$l['fila'],$lineas),JSON_THROW_ON_ERROR));
            $this->r->q('INSERT INTO erp_nomina_corridas(anio,mes,huella,calculada_por,calculada_en) VALUES(?,?,?,?,?)',[$anio,$mes,$huella,$actor,$this->r::ahora()]);$corrida=$this->r->id();
            foreach($lineas as $l){
                $f=$l['fila'];$cols=array_keys($f);
                $this->r->q('INSERT INTO erp_nomina_lineas(corrida_id,'.implode(',',$cols).',snapshot_json) VALUES(?,'.implode(',',array_fill(0,count($cols),'?')).',?)',[$corrida,...array_values($f),json_encode($l['snapshot'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);$lid=$this->r->id();
                foreach($l['comisiones'] as $liq)$this->r->q('INSERT INTO erp_nomina_comisiones(liquidacion_id,linea_id,monto_centavos) VALUES(?,?,?)',[(int)$liq['id'],$lid,(int)$liq['monto_centavos']]);
            }
            $this->evento($actor,'nomina_calculada','nomina',$corrida,['anio'=>$anio,'mes'=>$mes,'lineas'=>count($lineas),'huella'=>$huella,'formula'=>self::FORMULA]);return $corrida;
        });
    }
    /** $tramos: contratos de empleado del colaborador que tocan el mes (ordenados por inicio). Con varios tramos rige el mes comercial de 30 días; las horas se valoran con la base del último. */
    private function lineaDe(array $tramos,array $cond,array $p,int $anio,int $mes,string $primero,string $ultimo):array
    {
        $c=end($tramos);$col=(int)$c['colaborador_id'];$base=(int)$c['base_centavos'];
        if(count($tramos)===1){
            $ini=max($c['inicio'],$primero);$fin=min($c['fin']??$ultimo,$ultimo);
            $dias=($ini===$primero&&$fin===$ultimo)?30:min(30,(int)(new \DateTimeImmutable($ini))->diff(new \DateTimeImmutable($fin))->days+1);
            $sueldo=intdiv($base*$dias+15,30);
        }else{
            $dias=0;$sueldo=0;$previoFin=null;
            foreach($tramos as $t){
                $ini=max($t['inicio'],$primero);$fin=min($t['fin']??$ultimo,$ultimo);
                if($previoFin!==null&&$ini<=$previoFin)throw new ErpException('El colaborador '.$col.' tiene contratos de empleado que se solapan en el mes: corrige las fechas.',409);
                $previoFin=$fin;$d1=min(30,(int)substr($ini,8,2));$d2=$fin===$ultimo?30:min(30,(int)substr($fin,8,2));$dd=max(0,$d2-$d1+1);
                $dias+=$dd;$sueldo+=intdiv((int)$t['base_centavos']*$dd+15,30);
            }
            $dias=min(30,$dias);
        }
        $horasMes=(int)$p['jornada.horas_mes']['valor_entero'];$horas=0;$bonos=0;$bonosGravados=0;$desc=0;$ir=0;$usadas=[];$irManual=[];
        foreach($this->r->q("SELECT * FROM erp_nomina_novedades WHERE colaborador_id=? AND anio=? AND mes=? AND estado='aprobada' ORDER BY id FOR SHARE",[$col,$anio,$mes]) as $n){
            $usadas[]=(int)$n['id'];
            if($n['tipo']==='bono'){$bonos+=(int)$n['monto_centavos'];if((int)$n['grava_iess']===1)$bonosGravados+=(int)$n['monto_centavos'];}
            elseif($n['tipo']==='descuento')$desc+=(int)$n['monto_centavos'];
            elseif($n['tipo']==='retencion_ir'){$ir+=(int)$n['monto_centavos'];$irManual[]=(int)$n['id'];}
            else{
                $recargo=(int)$p['recargo.'.['hora_suplementaria'=>'suplementaria','hora_extraordinaria'=>'extraordinaria','hora_nocturna'=>'nocturno'][$n['tipo']].'_pb']['valor_entero'];
                $factor=$n['tipo']==='hora_nocturna'?$recargo:10000+$recargo;
                $monto=intdiv($base*(int)$n['cantidad_milesimas']*$factor+intdiv($horasMes*1000*10000,2),$horasMes*1000*10000);$horas+=$monto;
                $bonosGravados+=$monto;
            }
        }
        // Comisiones liquidadas por nómina y aún no pagadas ni incluidas en otra corrida.
        $liqs=$this->r->q("SELECT l.id,l.monto_centavos FROM erp_comision_liquidaciones l WHERE l.colaborador_id=? AND l.via='nomina' AND l.creado_en<DATE_ADD(?,INTERVAL 1 DAY) AND NOT EXISTS(SELECT 1 FROM erp_nomina_comisiones x WHERE x.liquidacion_id=l.id AND x.activa=1) ORDER BY l.id FOR UPDATE",[$col,$ultimo]);
        $comisiones=array_sum(array_map(fn($l)=>(int)$l['monto_centavos'],$liqs));
        $regular=$sueldo+$horas+$bonos+$comisiones;
        $gravado=$sueldo+$bonosGravados+$comisiones;
        $iessP=(int)$cond['aporta_iess']===1?self::pb($gravado,(int)$p['iess.aporte_personal_pb']['valor_entero']):0;
        $iessE=(int)$cond['aporta_iess']===1?self::pb($gravado,(int)$p['iess.aporte_patronal_pb']['valor_entero']):0;
        // Décimo tercero: doceava parte de lo devengado (sueldo, horas, bonos y comisiones del mes). Décimo cuarto: un SBU por año, proporcional a los días.
        $d13=intdiv($regular+6,12);
        $d14=intdiv((int)$p['sbu_centavos']['valor_entero']*$dias+180,360);
        $antig=(int)(new \DateTimeImmutable($c['fecha_ingreso']??$c['inicio']))->diff(new \DateTimeImmutable($ultimo))->days;
        $fondos=($cond['fondos_reserva']!=='no_aplica'&&$antig>=(int)$p['fondos_reserva.dias_antiguedad']['valor_entero'])?self::pb($regular,(int)$p['fondos_reserva.pb']['valor_entero']):0;
        // Impuesto a la renta: con una tabla vigente del año se calcula la retención proyectada; sin ella rige la novedad manual del contador.
        $renta=null;
        $tablaIr=$this->r->uno('SELECT * FROM erp_nomina_ir_tablas WHERE anio=? AND vigente_desde<=? ORDER BY vigente_desde DESC LIMIT 1 FOR SHARE',[$anio,$ultimo]);
        if($tablaIr&&$tablaIr['estado']==='vigente'){
            if($irManual)throw new ErpException('El colaborador '.$col.' tiene retenciones de renta manuales (novedades '.implode(', ',$irManual).') y el año tiene una tabla vigente: rechaza esas novedades para que la retención se calcule una sola vez.',409);
            $acum=$this->r->uno("SELECT COALESCE(SUM(l.sueldo_centavos+l.horas_centavos+l.bonos_centavos+l.comisiones_centavos),0) g,COALESCE(SUM(l.iess_personal_centavos),0) i,COALESCE(SUM(l.ir_centavos),0) r FROM erp_nomina_lineas l JOIN erp_nomina_corridas k ON k.id=l.corrida_id WHERE l.colaborador_id=? AND k.anio=? AND k.mes<? AND k.estado<>'anulada' FOR SHARE",[$col,$anio,$mes]);
            $decl=$this->r->uno('SELECT * FROM erp_nomina_ir_declaraciones WHERE colaborador_id=? AND anio=? ORDER BY id DESC LIMIT 1 FOR SHARE',[$col,$anio]);
            $mesFin=(!empty($c['fin'])&&substr($c['fin'],0,4)===(string)$anio)?(int)substr($c['fin'],5,2):12;
            try{
                $renta=NominaRenta::retencion(['anio_mes_restantes'=>max(1,$mesFin-$mes+1),'gravado_ytd'=>(int)$acum['g'],'gravado_mes'=>$regular,'iess_ytd'=>(int)$acum['i'],'iess_mes'=>$iessP,'retenido_ytd'=>(int)$acum['r'],
                    'otros_ingresos'=>(int)($decl['otros_ingresos_centavos']??0),'retenido_otros'=>(int)($decl['retenido_otros_centavos']??0),'gastos'=>(int)($decl['gastos_centavos']??0),'tope_gastos'=>$tablaIr['tope_gastos_centavos']===null?null:(int)$tablaIr['tope_gastos_centavos'],
                    'otras_deducciones'=>(int)($decl['otras_deducciones_centavos']??0),'rebaja'=>(int)($decl['rebaja_centavos']??0)],json_decode($tablaIr['tramos_json'],true,512,JSON_THROW_ON_ERROR));
            }catch(\InvalidArgumentException $e){throw new ErpException($e->getMessage().' (colaborador '.$col.')',409);}
            $ir=(int)$renta['retencion_mes'];
            $renta=['formula'=>NominaRenta::FORMULA,'tabla_id'=>(int)$tablaIr['id'],'declaracion_id'=>$decl?(int)$decl['id']:null,'sin_declaracion'=>$decl===null,'acumulado_previo'=>['gravado'=>(int)$acum['g'],'iess'=>(int)$acum['i'],'retenido'=>(int)$acum['r']]]+$renta;
        }
        $m13=(int)$cond['mensualiza_d13']===1?$d13:0;$m14=(int)$cond['mensualiza_d14']===1?$d14:0;$mf=$cond['fondos_reserva']==='mensualizado'?$fondos:0;
        $ingresos=$sueldo+$horas+$bonos+$comisiones+$m13+$m14+$mf;$neto=$ingresos-$iessP-$ir-$desc;
        if($neto<=0)throw new ErpException('Los descuentos o aportes dejan sin neto a pagar al colaborador '.$col.'. Revisa sus novedades.',409);
        $fila=['colaborador_id'=>$col,'contrato_id'=>(int)$c['id'],'dias'=>$dias,'base_centavos'=>$base,'sueldo_centavos'=>$sueldo,'horas_centavos'=>$horas,'bonos_centavos'=>$bonos,'comisiones_centavos'=>$comisiones,'d13_mensual_centavos'=>$m13,'d14_mensual_centavos'=>$m14,'fondos_mensual_centavos'=>$mf,'ingresos_centavos'=>$ingresos,'gravado_iess_centavos'=>$gravado,'iess_personal_centavos'=>$iessP,'ir_centavos'=>$ir,'descuentos_centavos'=>$desc,'neto_centavos'=>$neto,'iess_patronal_centavos'=>$iessE,'d13_prov_centavos'=>(int)$cond['mensualiza_d13']===1?0:$d13,'d14_prov_centavos'=>(int)$cond['mensualiza_d14']===1?0:$d14,'fondos_acum_centavos'=>$cond['fondos_reserva']==='acumulado'?$fondos:0];
        $snap=['formula'=>self::FORMULA,'periodo'=>sprintf('%04d-%02d',$anio,$mes),'parametros'=>array_map(fn($x)=>['id'=>(int)$x['id'],'vigente_desde'=>$x['vigente_desde'],'valor'=>(int)$x['valor_entero']],$p),'condicion_id'=>(int)$cond['id'],'novedades'=>$usadas,'comisiones'=>array_map(fn($l)=>(int)$l['id'],$liqs)]+($renta?['renta'=>$renta]:[]);
        return ['fila'=>$fila,'snapshot'=>$snap,'comisiones'=>$liqs];
    }

    // ---------------------------------------------------------------- revisión, aprobación, anulación
    private function corrida(int $id):array {return $this->r->uno('SELECT * FROM erp_nomina_corridas WHERE id=? FOR UPDATE',[$id])??throw new ErpException('Corrida no encontrada.',404);}
    public function revisar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'nomina.aprobar','nomina_revisar',$d,function()use($actor,$d){
            $k=$this->corrida($this->id($d['corrida_id']??null));if($k['estado']==='revisada')return (int)$k['id'];
            if($k['estado']!=='calculada')throw new ErpException('Solo se revisa una corrida recién calculada.',409);
            $this->distinto($actor,(int)$k['calculada_por'],'Otra persona autorizada debe revisar la corrida.');
            $this->r->q("UPDATE erp_nomina_corridas SET estado='revisada',revisada_por=?,revisada_en=? WHERE id=?",[$actor,$this->r::ahora(),(int)$k['id']]);
            $this->evento($actor,'nomina_revisada','nomina',(int)$k['id'],['anio'=>$k['anio'],'mes'=>$k['mes']]);return (int)$k['id'];
        });
    }
    public function aprobar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'nomina.aprobar','nomina_aprobar',$d,function()use($actor,$d){
            $k=$this->corrida($this->id($d['corrida_id']??null));if($k['estado']==='aprobada')return (int)$k['id'];
            if($k['estado']!=='revisada')throw new ErpException('Revisa la corrida antes de aprobarla.',409);
            $this->distinto($actor,(int)$k['calculada_por'],'Otra persona autorizada debe aprobar la corrida.');
            $lineas=$this->r->q('SELECT * FROM erp_nomina_lineas WHERE corrida_id=? ORDER BY id FOR UPDATE',[(int)$k['id']]);
            $t=array_fill_keys(['sueldos','comisiones','beneficios_gasto','patronal','neto','iess','ir','prov','descuentos'],0);
            [,,,$ultimo]=$this->periodo(['anio'=>$k['anio'],'mes'=>$k['mes']]);
            foreach($lineas as $l){
                $snap=json_decode($l['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
                foreach($snap['novedades'] as $nid){
                    $n=$this->r->uno('SELECT estado FROM erp_nomina_novedades WHERE id=? FOR UPDATE',[$nid]);
                    if(!$n||$n['estado']!=='aprobada')throw new ErpException('Una novedad de la corrida ya fue consumida o cambió. Anula la corrida y recalcula.',409);
                    $this->r->q("UPDATE erp_nomina_novedades SET estado='consumida',corrida_id=? WHERE id=?",[(int)$k['id'],$nid]);
                }
                $ob=$this->crearObligacion((int)$l['colaborador_id'],'nomina','NOM-'.$k['id'].'-'.$l['colaborador_id'],(int)$l['neto_centavos'],$ultimo,$actor);
                $this->r->q('UPDATE erp_nomina_lineas SET obligacion_id=? WHERE id=?',[$ob,(int)$l['id']]);
                $t['sueldos']+=(int)$l['sueldo_centavos']+(int)$l['horas_centavos']+(int)$l['bonos_centavos'];$t['comisiones']+=(int)$l['comisiones_centavos'];
                $t['beneficios_gasto']+=(int)$l['d13_mensual_centavos']+(int)$l['d14_mensual_centavos']+(int)$l['fondos_mensual_centavos']+(int)$l['d13_prov_centavos']+(int)$l['d14_prov_centavos']+(int)$l['fondos_acum_centavos'];
                $t['patronal']+=(int)$l['iess_patronal_centavos'];$t['neto']+=(int)$l['neto_centavos'];$t['iess']+=(int)$l['iess_personal_centavos']+(int)$l['iess_patronal_centavos']+(int)$l['fondos_acum_centavos'];
                $t['ir']+=(int)$l['ir_centavos'];$t['prov']+=(int)$l['d13_prov_centavos']+(int)$l['d14_prov_centavos'];$t['descuentos']+=(int)$l['descuentos_centavos'];
            }
            $iess=$t['iess']>0?$this->crearObligacion(null,'iess','IESS-'.$k['id'],$t['iess'],$ultimo,$actor):null;$ir=$t['ir']>0?$this->crearObligacion(null,'sri_ir','IR-'.$k['id'],$t['ir'],$ultimo,$actor):null;
            $t['periodo']=sprintf('%04d-%02d',$k['anio'],$k['mes']);$t['fecha']=$ultimo;
            $evento=$this->evento($actor,'nomina_aprobada','nomina',(int)$k['id'],$t,['contabilidad']);
            $this->r->q("UPDATE erp_nomina_corridas SET estado='aprobada',aprobada_por=?,aprobada_en=?,totales_json=?,evento_aprobacion_id=?,obligacion_iess_id=?,obligacion_ir_id=? WHERE id=?",[$actor,$this->r::ahora(),json_encode($t,JSON_THROW_ON_ERROR),$evento,$iess,$ir,(int)$k['id']]);
            return (int)$k['id'];
        });
    }
    private function crearObligacion(?int $colaborador,string $origen,string $referencia,int $monto,string $vence,int $actor):int
    {
        $this->r->q('INSERT INTO erp_obligaciones(colaborador_id,origen,referencia,monto_centavos,vence_en,creado_por,creado_en) VALUES(?,?,?,?,?,?,?)',[$colaborador,$origen,$referencia,$monto,$vence,$actor,$this->r::ahora()]);return $this->r->id();
    }
    /** Anula antes de que se pague nada: cancela las obligaciones, libera novedades y comisiones y revierte el hecho contable. */
    public function anular(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'nomina.aprobar','nomina_anular',$d,function()use($actor,$d){
            $k=$this->corrida($this->id($d['corrida_id']??null));$motivo=$this->texto($d['motivo']??'',300);if($k['estado']==='anulada')return (int)$k['id'];
            $lineas=$this->r->q('SELECT * FROM erp_nomina_lineas WHERE corrida_id=? ORDER BY id FOR UPDATE',[(int)$k['id']]);
            if($this->r->uno('SELECT 1 x FROM erp_nomina_beneficio_lineas bl JOIN erp_nomina_lineas l ON l.id=bl.linea_id WHERE l.corrida_id=? LIMIT 1 FOR SHARE',[(int)$k['id']]))throw new ErpException('Ya se liquidaron beneficios de esta corrida: corrige con novedades de ajuste.',409);
            $obligs=array_filter(array_merge(array_column($lineas,'obligacion_id'),[$k['obligacion_iess_id'],$k['obligacion_ir_id']]));
            foreach($obligs as $oid){
                $o=$this->r->uno('SELECT * FROM erp_obligaciones WHERE id=? FOR UPDATE',[(int)$oid]);
                if((int)$o['pagado_centavos']>0)throw new ErpException('Una obligación de esta corrida ya tiene pagos: no se puede anular. Corrige con novedades de ajuste.',409);
            }
            foreach($obligs as $oid)$this->r->q('UPDATE erp_obligaciones SET ajuste_centavos=monto_centavos WHERE id=?',[(int)$oid]);
            $this->r->q("UPDATE erp_nomina_novedades SET estado='aprobada',corrida_id=NULL WHERE corrida_id=? AND estado='consumida'",[(int)$k['id']]);
            $this->r->q('UPDATE erp_nomina_comisiones SET activa=0 WHERE activa=1 AND linea_id IN(SELECT id FROM erp_nomina_lineas WHERE corrida_id=?)',[(int)$k['id']]);
            $this->r->q("UPDATE erp_nomina_corridas SET estado='anulada',anulada_por=?,anulada_en=?,anulacion_motivo=? WHERE id=?",[$actor,$this->r::ahora(),$motivo,(int)$k['id']]);
            if($k['estado']==='aprobada')$this->evento($actor,'nomina_anulada','nomina',(int)$k['id'],['aprobacion_evento_id'=>(int)$k['evento_aprobacion_id'],'motivo'=>$motivo,'anio'=>$k['anio'],'mes'=>$k['mes'],'fecha'=>$this->hoy()],['contabilidad']);
            else $this->evento($actor,'nomina_descartada','nomina',(int)$k['id'],['motivo'=>$motivo,'estado_previo'=>$k['estado']]);
            return (int)$k['id'];
        });
    }

    // ---------------------------------------------------------------- beneficios acumulados
    /** Pasa a pagar los décimos acumulados (provisionados) de un colaborador: crea su obligación y marca las líneas para no liquidarlas dos veces. */
    public function liquidarBeneficio(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'nomina.aprobar','nomina_beneficio',$d,function()use($actor,$d){
            $col=$this->r->uno('SELECT id FROM colaboradores WHERE id=? FOR UPDATE',[$this->id($d['colaborador_id']??null)])??throw new ErpException('Colaborador no encontrado.',404);
            $tipo=$d['tipo']??'';if(!in_array($tipo,['decimo_tercero','decimo_cuarto'],true))throw new ErpException('Tipo de beneficio no válido.');
            $campo=$tipo==='decimo_tercero'?'d13_prov_centavos':'d14_prov_centavos';$clave=hash('sha256','nomina.beneficio:'.$actor.':'.$d['idem_key']);
            $previo=$this->r->uno('SELECT id FROM erp_nomina_beneficios WHERE clave_operacion=? FOR SHARE',[$clave]);if($previo)return (int)$previo['id'];
            $filas=$this->r->q("SELECT l.id,l.$campo m FROM erp_nomina_lineas l JOIN erp_nomina_corridas k ON k.id=l.corrida_id WHERE l.colaborador_id=? AND k.estado='aprobada' AND l.$campo>0 AND NOT EXISTS(SELECT 1 FROM erp_nomina_beneficio_lineas b WHERE b.linea_id=l.id AND b.tipo=?) AND NOT EXISTS(SELECT 1 FROM erp_nomina_finiquito_lineas f WHERE f.linea_id=l.id AND f.tipo=? AND f.activa=1) ORDER BY l.id FOR UPDATE",[(int)$col['id'],$tipo,$tipo]);
            $monto=array_sum(array_map(fn($f)=>(int)$f['m'],$filas));if($monto<=0)throw new ErpException('No hay beneficios acumulados por liquidar.',409);
            $ob=$this->crearObligacion((int)$col['id'],'nomina_beneficio','BEN-'.$tipo.'-'.$col['id'].'-'.substr($clave,0,10),$monto,$this->hoy(),$actor);
            $this->r->q('INSERT INTO erp_nomina_beneficios(clave_operacion,colaborador_id,tipo,monto_centavos,obligacion_id,creado_por,creado_en) VALUES(?,?,?,?,?,?,?)',[$clave,(int)$col['id'],$tipo,$monto,$ob,$actor,$this->r::ahora()]);$id=$this->r->id();
            foreach($filas as $f)$this->r->q('INSERT INTO erp_nomina_beneficio_lineas(beneficio_id,linea_id,tipo,monto_centavos) VALUES(?,?,?,?)',[$id,(int)$f['id'],$tipo,(int)$f['m']]);
            $this->evento($actor,'nomina_beneficio_liquidado','nomina_beneficio',$id,['colaborador_id'=>(int)$col['id'],'tipo'=>$tipo,'monto'=>$monto,'obligacion_id'=>$ob],['contabilidad']);return $id;
        });
    }

    // ---------------------------------------------------------------- consultas
    public function corridas(int $limite=24):array {return $this->r->q('SELECT * FROM erp_nomina_corridas ORDER BY anio DESC,mes DESC,id DESC LIMIT '.max(1,min(120,$limite)));}
    public function lineas(int $corrida):array
    {
        return $this->r->q("SELECT l.*,CONCAT(c.nombre,' ',c.apellido) nombre,c.codigo FROM erp_nomina_lineas l JOIN colaboradores c ON c.id=l.colaborador_id WHERE l.corrida_id=? ORDER BY c.apellido,c.nombre",[$corrida]);
    }
    /** Recibo de pago: lo ve quien administra la nómina o el propio colaborador una vez aprobada la corrida. */
    public function recibo(int $actor,int $linea):array
    {
        $l=$this->r->uno("SELECT l.*,k.anio,k.mes,k.estado corrida_estado,c.usuario_id,CONCAT(c.nombre,' ',c.apellido) nombre,c.codigo,c.puesto FROM erp_nomina_lineas l JOIN erp_nomina_corridas k ON k.id=l.corrida_id JOIN colaboradores c ON c.id=l.colaborador_id WHERE l.id=?",[$linea])??throw new ErpException('Recibo no encontrado.',404);
        $auth=new AuthorizationService($this->r->db);
        if($auth->permite($actor,'nomina.ver'))return $l;
        if($auth->permite($actor,'nomina.recibo_propio')&&(int)$l['usuario_id']===$actor&&$l['corrida_estado']==='aprobada')return $l;
        throw new ErpException('Recibo no encontrado.',404);
    }
    public function saldosBeneficios():array
    {
        return $this->r->q("SELECT l.colaborador_id,CONCAT(c.nombre,' ',c.apellido) nombre,SUM(CASE WHEN NOT EXISTS(SELECT 1 FROM erp_nomina_beneficio_lineas b WHERE b.linea_id=l.id AND b.tipo='decimo_tercero') AND NOT EXISTS(SELECT 1 FROM erp_nomina_finiquito_lineas f WHERE f.linea_id=l.id AND f.tipo='decimo_tercero' AND f.activa=1) THEN l.d13_prov_centavos ELSE 0 END) d13,SUM(CASE WHEN NOT EXISTS(SELECT 1 FROM erp_nomina_beneficio_lineas b WHERE b.linea_id=l.id AND b.tipo='decimo_cuarto') AND NOT EXISTS(SELECT 1 FROM erp_nomina_finiquito_lineas f WHERE f.linea_id=l.id AND f.tipo='decimo_cuarto' AND f.activa=1) THEN l.d14_prov_centavos ELSE 0 END) d14 FROM erp_nomina_lineas l JOIN erp_nomina_corridas k ON k.id=l.corrida_id JOIN colaboradores c ON c.id=l.colaborador_id WHERE k.estado='aprobada' GROUP BY l.colaborador_id,c.nombre,c.apellido HAVING d13>0 OR d14>0 ORDER BY c.apellido,c.nombre");
    }
}

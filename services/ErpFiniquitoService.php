<?php
namespace Services;

use Services\Nomina\NominaFiniquito;

/**
 * Finiquito (liquidación de haberes) de un colaborador cuya relación de empleado terminó. AppSalon no trae cifras legales: los días de vacaciones, el porcentaje de
 * desahucio y los meses de indemnización son parámetros de nómina registrados con su fuente. El resultado es una política de ensayo que debe validar el
 * abogado laboral y el contador antes de pagar.
 *
 * El sueldo del mes de salida NO se liquida aquí: lo paga la corrida de ese mes (el contrato termina en la fecha de salida y se prorratea), que además provisiona
 * los décimos del mes. El finiquito exige esa corrida aprobada y suma: décimos acumulados sin liquidar, vacaciones no gozadas, desahucio e indemnización.
 * Ciclo: calculado → aprobado (otra persona; genera UNA obligación al colaborador y el hecho contable) → pagado con Obligaciones; anulable antes del primer pago.
 */
final class ErpFiniquitoService extends ErpService
{
    private function hoy():string {return (new \DateTimeImmutable('now',new \DateTimeZone('America/Guayaquil')))->format('Y-m-d');}
    private function rolActor(int $actor):string {return (new AuthorizationService($this->r->db))->contexto($actor)['rol']??'';}
    private function distinto(int $actor,int $otro,string $mensaje):void {if($actor===$otro&&$this->rolActor($actor)!=='propietario')throw new ErpException($mensaje,403);}

    /** Décimos acumulados de líneas de nómina aprobadas que ni se liquidaron ni están en otro finiquito activo. @return array{decimo_tercero:list<array{id:int,m:int}>,decimo_cuarto:list<array{id:int,m:int}>} */
    private function pendientes(int $colaborador):array
    {
        $r=[];
        foreach(['decimo_tercero'=>'d13_prov_centavos','decimo_cuarto'=>'d14_prov_centavos'] as $tipo=>$campo){
            $r[$tipo]=array_map(fn($f)=>['id'=>(int)$f['id'],'m'=>(int)$f['m']],$this->r->q("SELECT l.id,l.$campo m FROM erp_nomina_lineas l JOIN erp_nomina_corridas k ON k.id=l.corrida_id WHERE l.colaborador_id=? AND k.estado='aprobada' AND l.$campo>0 AND NOT EXISTS(SELECT 1 FROM erp_nomina_beneficio_lineas b WHERE b.linea_id=l.id AND b.tipo=?) AND NOT EXISTS(SELECT 1 FROM erp_nomina_finiquito_lineas f WHERE f.linea_id=l.id AND f.tipo=? AND f.activa=1) ORDER BY l.id FOR UPDATE",[$colaborador,$tipo,$tipo]));
        }
        return $r;
    }
    private function suma(array $filas):int {return array_sum(array_column($filas,'m'));}

    public function calcular(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'nomina.calcular','nomina_finiquito',$d,function()use($actor,$d){
            $col=$this->r->uno('SELECT id,fecha_ingreso FROM colaboradores WHERE id=? FOR UPDATE',[$this->id($d['colaborador_id']??null)])??throw new ErpException('Colaborador no disponible.',404);
            $salida=$this->fecha($d['fecha_salida']??null);if($salida>$this->hoy())throw new ErpException('La fecha de salida no puede ser futura: liquida cuando la relación haya terminado.');
            $causa=$d['causa']??'';if(!in_array($causa,NominaFiniquito::CAUSAS,true))throw new ErpException('Indica la causa de terminación.');
            $desahucio=($d['desahucio']??'')==='1';
            try{
                $tomadas=($d['vacaciones_tomadas']??'')===''?0:ValoresExactos::decimal($d['vacaciones_tomadas'],2,true);
                $otros=($d['otros_ingresos']??'')===''?0:$this->importe($d['otros_ingresos'],true);$desc=($d['descuentos']??'')===''?0:$this->importe($d['descuentos'],true);
            }catch(\InvalidArgumentException $e){throw new ErpException($e->getMessage());}
            $nota=($d['nota']??'')===''?null:$this->texto($d['nota'],300);
            if(($otros>0||$desc>0||$causa==='otra'||$desahucio&&$causa==='otra')&&($nota===null||mb_strlen($nota)<5))throw new ErpException('Indica el respaldo de los otros ingresos, los descuentos o la causa «otra» (mínimo 5 caracteres).');
            $clave=hash('sha256','nomina.finiquito:'.$actor.':'.$d['idem_key']);
            $previo=$this->r->uno('SELECT id FROM erp_nomina_finiquitos WHERE clave_operacion=? FOR SHARE',[$clave]);if($previo)return (int)$previo['id'];
            if($this->r->uno("SELECT id FROM erp_nomina_finiquitos WHERE colaborador_id=? AND estado<>'anulado' FOR UPDATE",[(int)$col['id']]))throw new ErpException('El colaborador ya tiene un finiquito vigente. Anúlalo para recalcular.',409);
            $c=$this->r->uno("SELECT * FROM erp_contratos WHERE colaborador_id=? AND tipo='empleado' AND fin=? FOR SHARE",[(int)$col['id'],$salida]);
            if(!$c)throw new ErpException('Termina primero la relación de empleado en Recursos Humanos con fecha final igual a la fecha de salida ('.$salida.').',409);
            $ingreso=$col['fecha_ingreso']?:($this->r->uno("SELECT MIN(inicio) i FROM erp_contratos WHERE colaborador_id=? AND tipo='empleado'",[(int)$col['id']])['i']??null);
            if(!$ingreso||$ingreso>$salida)throw new ErpException('La fecha de ingreso del colaborador falta o es posterior a la salida.',409);
            $nomina=new ErpNominaService($this->r);$p=[];$faltan=[];$ids=[];
            foreach(ErpNominaService::PARAMETROS_FINIQUITO as $k=>$_){$x=$nomina->resolver($k,$salida);if(!$x)$faltan[]=$k;else{$p[$k]=(int)$x['valor_entero'];$ids[$k]=['id'=>(int)$x['id'],'vigente_desde'=>$x['vigente_desde'],'valor'=>(int)$x['valor_entero']];}}
            if($faltan)throw new ErpException('Faltan parámetros de finiquito vigentes: '.implode(', ',$faltan).'. Regístralos en Nómina › Parámetros legales tras verificarlos en la fuente oficial.',409);
            $p['vacaciones.dias_base_anio']=max(1,$p['vacaciones.dias_base_anio']);
            $anio=(int)substr($salida,0,4);$mes=(int)substr($salida,5,2);
            $linea=$this->r->uno("SELECT l.id FROM erp_nomina_lineas l JOIN erp_nomina_corridas k ON k.id=l.corrida_id WHERE l.colaborador_id=? AND k.anio=? AND k.mes=? AND k.estado='aprobada' FOR SHARE",[(int)$col['id'],$anio,$mes]);
            if(!$linea)throw new ErpException('Calcula y aprueba primero la nómina de '.sprintf('%04d-%02d',$anio,$mes).': paga los días trabajados del mes de salida y provisiona sus décimos.',409);
            $pend=$this->pendientes((int)$col['id']);$d13=$this->suma($pend['decimo_tercero']);$d14=$this->suma($pend['decimo_cuarto']);
            $dias=(int)(new \DateTimeImmutable($ingreso))->diff(new \DateTimeImmutable($salida))->days+1;
            try{
                $r=NominaFiniquito::calcular(['base'=>(int)$c['base_centavos'],'dias_servicio'=>$dias,'causa'=>$causa,'desahucio'=>$desahucio,'vacaciones_tomadas_cent'=>$tomadas,'d13'=>$d13,'d14'=>$d14,'otros_ingresos'=>$otros,'descuentos'=>$desc],$p);
            }catch(\InvalidArgumentException $e){throw new ErpException($e->getMessage(),409);}
            $snap=['formula'=>NominaFiniquito::FORMULA,'parametros'=>$ids,'contrato_id'=>(int)$c['id'],'linea_mes_salida'=>(int)$linea['id'],'decimo_tercero'=>$pend['decimo_tercero'],'decimo_cuarto'=>$pend['decimo_cuarto'],'meses_indemnizacion'=>$r['meses_indemnizacion'],'anios_completos'=>$r['anios_completos'],
                'supuestos'=>['indemnizacion:fraccion_de_anio_cuenta_completa','desahucio:solo_anios_completos','remuneracion_de_referencia=base_ultimo_contrato','vacaciones_a_base/30_por_dia','sin_proyeccion_de_renta']];
            $this->r->q('INSERT INTO erp_nomina_finiquitos(clave_operacion,colaborador_id,contrato_id,fecha_ingreso,fecha_salida,causa,desahucio,base_centavos,dias_servicio,anios_computados,vacaciones_devengadas_cent,vacaciones_tomadas_cent,d13_centavos,d14_centavos,vacaciones_centavos,desahucio_centavos,indemnizacion_centavos,otros_ingresos_centavos,descuentos_centavos,total_centavos,nota,snapshot_json,calculado_por,calculado_en) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$clave,(int)$col['id'],(int)$c['id'],$ingreso,$salida,$causa,$desahucio?1:0,(int)$c['base_centavos'],$dias,$r['anios_computados'],$r['vacaciones_devengadas_cent'],$tomadas,$d13,$d14,$r['vacaciones_centavos'],$r['desahucio_centavos'],$r['indemnizacion_centavos'],$otros,$desc,$r['total_centavos'],$nota,json_encode($snap,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$actor,$this->r::ahora()]);
            $id=$this->r->id();$this->evento($actor,'nomina_finiquito_calculado','colaborador',(int)$col['id'],['finiquito_id'=>$id,'causa'=>$causa,'fecha_salida'=>$salida,'total'=>$r['total_centavos'],'formula'=>NominaFiniquito::FORMULA]);return $id;
        });
    }
    private function finiquito(int $id):array {return $this->r->uno('SELECT * FROM erp_nomina_finiquitos WHERE id=? FOR UPDATE',[$id])??throw new ErpException('Finiquito no encontrado.',404);}

    public function aprobar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'nomina.aprobar','nomina_finiquito_aprobar',$d,function()use($actor,$d){
            $f=$this->finiquito($this->id($d['finiquito_id']??null));if($f['estado']==='aprobado')return (int)$f['id'];
            if($f['estado']!=='calculado')throw new ErpException('El finiquito está anulado.',409);
            $this->distinto($actor,(int)$f['calculado_por'],'Otra persona autorizada debe aprobar el finiquito.');
            $this->r->q('SELECT id FROM colaboradores WHERE id=? FOR UPDATE',[(int)$f['colaborador_id']]);
            $snap=json_decode($f['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
            if(!$this->r->uno("SELECT 1 x FROM erp_nomina_lineas l JOIN erp_nomina_corridas k ON k.id=l.corrida_id WHERE l.id=? AND k.estado='aprobada' FOR SHARE",[(int)$snap['linea_mes_salida']]))throw new ErpException('La nómina del mes de salida ya no está aprobada. Anula el finiquito y recalcula.',409);
            $pend=$this->pendientes((int)$f['colaborador_id']);
            if($this->suma($pend['decimo_tercero'])!==(int)$f['d13_centavos']||$this->suma($pend['decimo_cuarto'])!==(int)$f['d14_centavos'])throw new ErpException('Los décimos acumulados del colaborador cambiaron desde el cálculo. Anula el finiquito y recalcula.',409);
            foreach($pend as $tipo=>$filas)foreach($filas as $x)$this->r->q('INSERT INTO erp_nomina_finiquito_lineas(finiquito_id,linea_id,tipo,monto_centavos) VALUES(?,?,?,?)',[(int)$f['id'],$x['id'],$tipo,$x['m']]);
            $hoy=$this->hoy();$total=(int)$f['total_centavos'];$ob=null;
            if($total>0){
                $this->r->q('INSERT INTO erp_obligaciones(colaborador_id,origen,referencia,monto_centavos,vence_en,creado_por,creado_en) VALUES(?,?,?,?,?,?,?)',[(int)$f['colaborador_id'],'finiquito','FIN-'.$f['id'],$total,$hoy,$actor,$this->r::ahora()]);$ob=$this->r->id();
            }
            $gastoBen=(int)$f['vacaciones_centavos']+(int)$f['desahucio_centavos']+(int)$f['indemnizacion_centavos'];
            $datos=['finiquito_id'=>(int)$f['id'],'colaborador_id'=>(int)$f['colaborador_id'],'d13'=>(int)$f['d13_centavos'],'d14'=>(int)$f['d14_centavos'],'gasto_beneficios'=>$gastoBen,'otros'=>(int)$f['otros_ingresos_centavos'],'descuentos'=>(int)$f['descuentos_centavos'],'neto'=>$total,'fecha'=>$hoy];
            $evento=$this->evento($actor,'finiquito_aprobado','nomina_finiquito',(int)$f['id'],$datos,($datos['d13']+$datos['d14']+$gastoBen+$datos['otros'])>0?['contabilidad']:[]);
            $this->r->q("UPDATE erp_nomina_finiquitos SET estado='aprobado',aprobado_por=?,aprobado_en=?,evento_aprobacion_id=?,obligacion_id=? WHERE id=?",[$actor,$this->r::ahora(),$evento,$ob,(int)$f['id']]);
            return (int)$f['id'];
        });
    }

    /** Anula antes de que se pague nada: cancela la obligación, libera los décimos incluidos y revierte el asiento. */
    public function anular(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'nomina.aprobar','nomina_finiquito_anular',$d,function()use($actor,$d){
            $f=$this->finiquito($this->id($d['finiquito_id']??null));$motivo=$this->texto($d['motivo']??'',300);if($f['estado']==='anulado')return (int)$f['id'];
            if($f['obligacion_id']){
                $o=$this->r->uno('SELECT * FROM erp_obligaciones WHERE id=? FOR UPDATE',[(int)$f['obligacion_id']]);
                if((int)$o['pagado_centavos']>0)throw new ErpException('La obligación del finiquito ya tiene pagos: no se puede anular. Corrige con un ajuste en Obligaciones.',409);
                $this->r->q('UPDATE erp_obligaciones SET ajuste_centavos=monto_centavos WHERE id=?',[(int)$f['obligacion_id']]);
            }
            $this->r->q('UPDATE erp_nomina_finiquito_lineas SET activa=0 WHERE finiquito_id=? AND activa=1',[(int)$f['id']]);
            $this->r->q("UPDATE erp_nomina_finiquitos SET estado='anulado',anulado_por=?,anulado_en=?,anulacion_motivo=? WHERE id=?",[$actor,$this->r::ahora(),$motivo,(int)$f['id']]);
            $contabilizado=(int)$f['d13_centavos']+(int)$f['d14_centavos']+(int)$f['vacaciones_centavos']+(int)$f['desahucio_centavos']+(int)$f['indemnizacion_centavos']+(int)$f['otros_ingresos_centavos']>0;
            if($f['estado']==='aprobado'&&$f['evento_aprobacion_id'])$this->evento($actor,'finiquito_anulado','nomina_finiquito',(int)$f['id'],['aprobacion_evento_id'=>(int)$f['evento_aprobacion_id'],'motivo'=>$motivo,'fecha'=>$this->hoy()],$contabilizado?['contabilidad']:[]);
            else $this->evento($actor,'nomina_finiquito_descartado','nomina_finiquito',(int)$f['id'],['motivo'=>$motivo,'estado_previo'=>$f['estado']]);
            return (int)$f['id'];
        });
    }

    public function listar(int $limite=100):array
    {
        return $this->r->q("SELECT f.*,CONCAT(c.nombre,' ',c.apellido) nombre FROM erp_nomina_finiquitos f JOIN colaboradores c ON c.id=f.colaborador_id ORDER BY f.id DESC LIMIT ".max(1,min(500,$limite)));
    }
}

<?php
namespace Services;

use Services\Nomina\NominaUtilidades;

/**
 * Participación de trabajadores en las utilidades de un ejercicio. AppSalon no trae cifras legales: los porcentajes (y el tope opcional) son parámetros de nómina
 * con su fuente. La utilidad base sale del libro contable del ejercicio (estado de resultados, sin el propio gasto de utilidades) más los ajustes que registre el contador
 * con respaldo; si el libro no cubre todo el ejercicio, o hay hechos sin contabilizar, no se calcula. El resultado es una política de ensayo que valida el contador.
 *
 * Ciclo: calculada → aprobada (otra persona; una obligación por trabajador y el hecho contable, fechado al cierre del ejercicio) → pagada con Obligaciones; anulable antes del primer pago.
 */
final class ErpUtilidadesService extends ErpService
{
    private function hoy():string {return (new \DateTimeImmutable('now',new \DateTimeZone('America/Guayaquil')))->format('Y-m-d');}
    private function rolActor(int $actor):string {return (new AuthorizationService($this->r->db))->contexto($actor)['rol']??'';}
    private function distinto(int $actor,int $otro,string $mensaje):void {if($actor===$otro&&$this->rolActor($actor)!=='propietario')throw new ErpException($mensaje,403);}
    private function anio(mixed $v):int
    {
        $a=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>2000,'max_range'=>2100]]);if($a===false)throw new ErpException('Indica el año del ejercicio.');return $a;
    }

    /** Cargas familiares declaradas por el trabajador para un ejercicio (la última declaración rige; las anteriores se conservan). */
    public function cargas(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'nomina.configurar','nomina_cargas',$d,function()use($actor,$d){
            $col=$this->r->uno("SELECT id FROM colaboradores WHERE id=? FOR UPDATE",[$this->id($d['colaborador_id']??null)])??throw new ErpException('Colaborador no disponible.',404);
            $anio=$this->anio($d['anio']??null);$n=$d['cargas']??null;
            if((!is_int($n)&&!is_string($n))||!preg_match('/^\d{1,2}$/D',(string)$n)||(int)$n>30)throw new ErpException('Las cargas son un número entero de 0 a 30.');
            $fuente=$this->texto($d['fuente']??'',200);if(mb_strlen($fuente)<5)throw new ErpException('Indica el documento de respaldo de las cargas familiares.');
            $this->r->q('INSERT INTO erp_nomina_cargas(colaborador_id,anio,cargas,fuente,creado_por,creado_en) VALUES(?,?,?,?,?,?)',[(int)$col['id'],$anio,(int)$n,$fuente,$actor,$this->r::ahora()]);
            $id=$this->r->id();$this->evento($actor,'nomina_cargas_registradas','colaborador',(int)$col['id'],['declaracion_id'=>$id,'anio'=>$anio,'cargas'=>(int)$n,'fuente'=>$fuente]);return (int)$col['id'];
        });
    }

    /** Resultado del ejercicio según el libro, sin el gasto de utilidades (evita la circularidad si hubo un reparto anulado). Exige libro completo y sin hechos pendientes. */
    private function resultadoContable(int $anio):int
    {
        $cfg=$this->r->uno('SELECT inicio_contable FROM erp_cont_config WHERE id=1 FOR SHARE');
        if(!$cfg||!$cfg['inicio_contable'])throw new ErpException('Define primero el inicio contable: la utilidad base sale del libro.',409);
        if($cfg['inicio_contable']>$anio.'-01-01')throw new ErpException('El libro empieza el '.$cfg['inicio_contable'].' y no cubre todo el ejercicio '.$anio.': registra la utilidad con un asiento de apertura o calcula el reparto con el contador.',409);
        $pend=(int)$this->r->uno("SELECT COUNT(*) n FROM erp_integraciones_outbox o JOIN erp_eventos e ON e.id=o.evento_id WHERE o.destino='contabilidad' AND o.estado<>'confirmado' AND e.creado_en>=? AND e.creado_en<?",[$anio.'-01-01 00:00:00',($anio+1).'-01-01 00:00:00'])['n'];
        if($pend>0)throw new ErpException('Hay '.$pend.' hecho(s) económicos del ejercicio sin contabilizar. Procésalos antes de calcular las utilidades.',409);
        $er=(new ErpContabilidadService($this->r))->estadoResultados($anio.'-01-01',$anio.'-12-31');
        // El gasto de utilidades (cuenta del rol) no entra en su propia base; sin cuenta asignada, el reparto no se puede contabilizar.
        $rol=$this->r->uno('SELECT c.codigo FROM erp_cont_roles r JOIN erp_cont_cuentas c ON c.id=r.cuenta_id WHERE r.rol=\'gasto_utilidades\'');
        if(!$rol)throw new ErpException('Asigna una cuenta de gasto al rol contable «gasto_utilidades» (Contabilidad › Roles) antes de repartir utilidades.',409);
        $propio=0;foreach($er['secciones']['gasto'] as $x)if($x['codigo']===$rol['codigo'])$propio+=(int)$x['valor'];
        return (int)$er['resultado']+$propio;
    }

    public function calcular(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'nomina.calcular','nomina_utilidades',$d,function()use($actor,$d){
            $anio=$this->anio($d['anio']??null);$hoy=$this->hoy();if($anio>=(int)substr($hoy,0,4))throw new ErpException('El ejercicio '.$anio.' aún no termina: reparte las utilidades después de cerrarlo.',409);
            try{$mas=($d['ajuste_mas']??'')===''?0:$this->importe($d['ajuste_mas'],true);$menos=($d['ajuste_menos']??'')===''?0:$this->importe($d['ajuste_menos'],true);}catch(\InvalidArgumentException $e){throw new ErpException($e->getMessage());}
            $nota=($d['nota']??'')===''?null:$this->texto($d['nota'],300);if(($mas>0||$menos>0)&&($nota===null||mb_strlen($nota)<5))throw new ErpException('Indica el respaldo de los ajustes (conciliación tributaria del contador).');
            $clave=hash('sha256','nomina.utilidades:'.$actor.':'.$d['idem_key']);$previo=$this->r->uno('SELECT id FROM erp_nomina_utilidades WHERE clave_operacion=? FOR SHARE',[$clave]);if($previo)return (int)$previo['id'];
            if($this->r->uno("SELECT id FROM erp_nomina_utilidades WHERE anio=? AND estado<>'anulada' FOR UPDATE",[$anio]))throw new ErpException('El ejercicio '.$anio.' ya tiene un reparto vigente. Anúlalo para recalcular.',409);
            $nomina=new ErpNominaService($this->r);$fin=$anio.'-12-31';$faltan=[];$p=[];$ids=[];
            foreach(['utilidades.pb_trabajadores','utilidades.pb_cargas'] as $k){$x=$nomina->resolver($k,$fin);if(!$x)$faltan[]=$k;else{$p[$k]=(int)$x['valor_entero'];$ids[$k]=['id'=>(int)$x['id'],'vigente_desde'=>$x['vigente_desde'],'valor'=>(int)$x['valor_entero']];}}
            if($faltan)throw new ErpException('Faltan parámetros de utilidades vigentes: '.implode(', ',$faltan).'. Regístralos en Nómina › Parámetros legales tras verificarlos en la fuente oficial.',409);
            $tope=null;$topeSbu=$nomina->resolver('utilidades.tope_sbu',$fin);
            if($topeSbu){$sbu=$nomina->resolver('sbu_centavos',$fin);if(!$sbu)throw new ErpException('Hay un tope de utilidades en SBU y falta el parámetro sbu_centavos vigente al cierre del ejercicio.',409);$tope=(int)$topeSbu['valor_entero']*(int)$sbu['valor_entero'];$ids['utilidades.tope_sbu']=['id'=>(int)$topeSbu['id'],'vigente_desde'=>$topeSbu['vigente_desde'],'valor'=>(int)$topeSbu['valor_entero'],'sbu_centavos'=>(int)$sbu['valor_entero']];}
            $resultado=$this->resultadoContable($anio);$base=$resultado+$mas-$menos;
            if($base<=0)throw new ErpException('La utilidad base del ejercicio '.$anio.' es '.number_format($base/100,2).': no hay utilidades que repartir.',409);
            // Días trabajados en el ejercicio con relación de dependencia (cada contrato solo cuenta dentro del año).
            $trab=[];foreach($this->r->q("SELECT colaborador_id,inicio,fin FROM erp_contratos WHERE tipo='empleado' AND inicio<=? AND COALESCE(fin,'9999-12-31')>=? ORDER BY colaborador_id,inicio FOR SHARE",[$fin,$anio.'-01-01']) as $c){
                $ini=max($c['inicio'],$anio.'-01-01');$f=min($c['fin']??$fin,$fin);$dias=(int)(new \DateTimeImmutable($ini))->diff(new \DateTimeImmutable($f))->days+1;
                $trab[(int)$c['colaborador_id']]=['dias'=>min(366,($trab[(int)$c['colaborador_id']]['dias']??0)+$dias),'cargas'=>0];
            }
            foreach($trab as $id=>&$t){$c=$this->r->uno('SELECT cargas FROM erp_nomina_cargas WHERE colaborador_id=? AND anio=? ORDER BY id DESC LIMIT 1 FOR SHARE',[$id,$anio]);$t['cargas']=(int)($c['cargas']??0);}unset($t);
            try{$r=NominaUtilidades::calcular($base,$p['utilidades.pb_trabajadores'],$p['utilidades.pb_cargas'],$trab,$tope);}catch(\InvalidArgumentException $e){throw new ErpException($e->getMessage(),409);}
            $snap=['formula'=>NominaUtilidades::FORMULA,'parametros'=>$ids,'trabajadores'=>$r['lineas'],'supuestos'=>['sin_cargas_la_parte_de_cargas_se_suma_a_dias','tope_sobre_el_total_del_trabajador','excedente_sin_pagar','base=resultado_contable_del_libro+ajustes']];
            $this->r->q('INSERT INTO erp_nomina_utilidades(clave_operacion,anio,resultado_contable_centavos,ajuste_mas_centavos,ajuste_menos_centavos,base_centavos,pb_trabajadores,pb_cargas,pozo_trabajo_centavos,pozo_cargas_centavos,cargas_a_trabajo,tope_centavos,excedente_centavos,total_centavos,nota,snapshot_json,calculada_por,calculada_en) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$clave,$anio,$resultado,$mas,$menos,$base,$p['utilidades.pb_trabajadores'],$p['utilidades.pb_cargas'],$r['pozo_trabajo'],$r['pozo_cargas'],$r['cargas_a_trabajo']?1:0,$tope,$r['excedente'],$r['total'],$nota,json_encode($snap,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$actor,$this->r::ahora()]);
            $id=$this->r->id();
            foreach($r['lineas'] as $col=>$l)$this->r->q('INSERT INTO erp_nomina_utilidades_lineas(utilidad_id,colaborador_id,dias,cargas,parte_trabajo_centavos,parte_cargas_centavos,excedente_centavos,monto_centavos) VALUES(?,?,?,?,?,?,?,?)',[$id,$col,$l['dias'],$l['cargas'],$l['parte_trabajo'],$l['parte_cargas'],$l['excedente'],$l['monto']]);
            $this->evento($actor,'nomina_utilidades_calculadas','nomina_utilidades',$id,['anio'=>$anio,'base'=>$base,'total'=>$r['total'],'excedente'=>$r['excedente'],'formula'=>NominaUtilidades::FORMULA]);return $id;
        });
    }
    private function fila(int $id):array {return $this->r->uno('SELECT * FROM erp_nomina_utilidades WHERE id=? FOR UPDATE',[$id])??throw new ErpException('Reparto no encontrado.',404);}

    public function aprobar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'nomina.aprobar','nomina_utilidades_aprobar',$d,function()use($actor,$d){
            $u=$this->fila($this->id($d['utilidad_id']??null));if($u['estado']==='aprobada')return (int)$u['id'];
            if($u['estado']!=='calculada')throw new ErpException('El reparto está anulado.',409);
            $this->distinto($actor,(int)$u['calculada_por'],'Otra persona autorizada debe aprobar el reparto de utilidades.');
            if($this->resultadoContable((int)$u['anio'])!==(int)$u['resultado_contable_centavos'])throw new ErpException('El resultado contable del ejercicio cambió desde el cálculo. Anula el reparto y recalcula.',409);
            $hoy=$this->hoy();$total=0;
            foreach($this->r->q('SELECT * FROM erp_nomina_utilidades_lineas WHERE utilidad_id=? ORDER BY id FOR UPDATE',[(int)$u['id']]) as $l){
                if((int)$l['monto_centavos']<=0)continue;
                $this->r->q('INSERT INTO erp_obligaciones(colaborador_id,origen,referencia,monto_centavos,vence_en,creado_por,creado_en) VALUES(?,?,?,?,?,?,?)',[(int)$l['colaborador_id'],'utilidades','UTIL-'.$u['id'].'-'.$l['colaborador_id'],(int)$l['monto_centavos'],$hoy,$actor,$this->r::ahora()]);
                $this->r->q('UPDATE erp_nomina_utilidades_lineas SET obligacion_id=? WHERE id=?',[$this->r->id(),(int)$l['id']]);$total+=(int)$l['monto_centavos'];
            }
            if($total!==(int)$u['total_centavos'])throw new ErpException('Los montos del reparto no cuadran con su total. Anula y recalcula.',409);
            $evento=$this->evento($actor,'utilidades_aprobadas','nomina_utilidades',(int)$u['id'],['utilidad_id'=>(int)$u['id'],'anio'=>(int)$u['anio'],'total'=>$total,'fecha'=>$u['anio'].'-12-31'],$total>0?['contabilidad']:[]);
            $this->r->q("UPDATE erp_nomina_utilidades SET estado='aprobada',aprobada_por=?,aprobada_en=?,evento_aprobacion_id=? WHERE id=?",[$actor,$this->r::ahora(),$evento,(int)$u['id']]);
            return (int)$u['id'];
        });
    }

    /** Anula antes de que se pague nada: cancela las obligaciones y revierte el asiento. */
    public function anular(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'nomina.aprobar','nomina_utilidades_anular',$d,function()use($actor,$d){
            $u=$this->fila($this->id($d['utilidad_id']??null));$motivo=$this->texto($d['motivo']??'',300);if($u['estado']==='anulada')return (int)$u['id'];
            $obs=array_filter(array_column($this->r->q('SELECT obligacion_id FROM erp_nomina_utilidades_lineas WHERE utilidad_id=? ORDER BY id FOR UPDATE',[(int)$u['id']]),'obligacion_id'));
            foreach($obs as $oid){$o=$this->r->uno('SELECT * FROM erp_obligaciones WHERE id=? FOR UPDATE',[(int)$oid]);if((int)$o['pagado_centavos']>0)throw new ErpException('Una obligación del reparto ya tiene pagos: no se puede anular. Corrige con un ajuste en Obligaciones.',409);}
            foreach($obs as $oid)$this->r->q('UPDATE erp_obligaciones SET ajuste_centavos=monto_centavos WHERE id=?',[(int)$oid]);
            $this->r->q("UPDATE erp_nomina_utilidades SET estado='anulada',anulada_por=?,anulada_en=?,anulacion_motivo=? WHERE id=?",[$actor,$this->r::ahora(),$motivo,(int)$u['id']]);
            if($u['estado']==='aprobada'&&$u['evento_aprobacion_id']&&(int)$u['total_centavos']>0)$this->evento($actor,'utilidades_anuladas','nomina_utilidades',(int)$u['id'],['aprobacion_evento_id'=>(int)$u['evento_aprobacion_id'],'motivo'=>$motivo,'fecha'=>$this->hoy()],['contabilidad']);
            else $this->evento($actor,'nomina_utilidades_descartadas','nomina_utilidades',(int)$u['id'],['motivo'=>$motivo,'estado_previo'=>$u['estado']]);
            return (int)$u['id'];
        });
    }

    public function listar(int $limite=50):array {return $this->r->q('SELECT * FROM erp_nomina_utilidades ORDER BY anio DESC,id DESC LIMIT '.max(1,min(200,$limite)));}
    public function lineas(int $utilidad):array
    {
        return $this->r->q("SELECT l.*,CONCAT(c.nombre,' ',c.apellido) nombre FROM erp_nomina_utilidades_lineas l JOIN colaboradores c ON c.id=l.colaborador_id WHERE l.utilidad_id=? ORDER BY c.apellido,c.nombre",[$utilidad]);
    }
}

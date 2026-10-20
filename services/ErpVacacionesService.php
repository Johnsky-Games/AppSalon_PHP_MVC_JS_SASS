<?php
namespace Services;

use Services\Nomina\NominaVacaciones;

/**
 * Saldo de vacaciones de un colaborador (solo lectura). Los días por año y la base del año son parámetros de nómina registrados con su fuente: sin ellos no se
 * calcula ningún saldo y se devuelve la lista de lo que falta. «Tomadas» son las ausencias APROBADAS de tipo vacaciones, en días calendario. Es la misma regla que
 * usa el finiquito para no pagar dos veces días ya gozados.
 */
final class ErpVacacionesService extends ErpService
{
    public const PARAMETROS=['vacaciones.dias_anuales','vacaciones.dias_base_anio'];

    /** Inicio de la relación de empleado: fecha de ingreso de la ficha o, si falta, el primer contrato de empleado. */
    public function ingreso(int $colaborador):?string
    {
        $c=$this->r->uno('SELECT fecha_ingreso FROM colaboradores WHERE id=?',[$colaborador]);
        return ($c['fecha_ingreso']??null)?:($this->r->uno("SELECT MIN(inicio) i FROM erp_contratos WHERE colaborador_id=? AND tipo='empleado'",[$colaborador])['i']??null);
    }

    /** Días calendario de vacaciones aprobadas que caen entre ingreso y corte, en centésimas. */
    public function tomadasCent(int $colaborador,string $ingreso,string $corte):int
    {
        $n=0;foreach($this->r->q("SELECT desde,hasta FROM erp_ausencias WHERE colaborador_id=? AND tipo='vacaciones' AND estado='aprobada' AND desde<=? AND hasta>=?",[$colaborador,$corte,$ingreso]) as $a)
            $n+=NominaVacaciones::diasEnVentana($a['desde'],$a['hasta'],$ingreso,$corte)*100;
        return $n;
    }

    /** @return array{disponible:bool,faltan:list<string>,saldo?:array<string,int>,ingreso?:string,corte?:string} */
    public function saldo(int $colaborador,string $corte):array
    {
        $nomina=new ErpNominaService($this->r);$p=[];$faltan=[];
        foreach(self::PARAMETROS as $k){$x=$nomina->resolver($k,$corte);if($x)$p[$k]=(int)$x['valor_entero'];else $faltan[]=$k;}
        $ingreso=$this->ingreso($colaborador);
        if(!$ingreso)return ['disponible'=>false,'faltan'=>['fecha_ingreso']];
        if($faltan)return ['disponible'=>false,'faltan'=>$faltan,'ingreso'=>$ingreso];
        if($corte<$ingreso)return ['disponible'=>false,'faltan'=>['corte_posterior_al_ingreso'],'ingreso'=>$ingreso];
        $aprobadas=$this->r->q("SELECT desde,hasta FROM erp_ausencias WHERE colaborador_id=? AND tipo='vacaciones' AND estado='aprobada' AND desde<=? AND hasta>=?",[$colaborador,$corte,$ingreso]);
        return ['disponible'=>true,'faltan'=>[],'ingreso'=>$ingreso,'corte'=>$corte,'saldo'=>NominaVacaciones::saldo($ingreso,$corte,$aprobadas,$p['vacaciones.dias_anuales'],max(1,$p['vacaciones.dias_base_anio']))];
    }
}

<?php
namespace Services\Nomina;

/**
 * Liquidación de haberes (finiquito) — cálculo puro en centavos. Sin cifras legales: días de vacaciones, porcentaje de desahucio y meses de indemnización
 * vienen de parámetros registrados con su fuente. Supuestos que debe validar el abogado laboral: la fracción de año cuenta como año completo SOLO en la indemnización; la
 * remuneración de referencia es la base del último contrato; el desahucio cuenta años de servicio COMPLETOS (art. 185) mientras que la indemnización por despido cuenta la fracción como año (art. 188); las vacaciones se valoran a base/30 por día; las indemnizaciones no se proyectan al IR.
 */
final class NominaFiniquito
{
    public const FORMULA='finiquito-v1';
    public const CAUSAS=['renuncia','mutuo_acuerdo','fin_plazo','despido_intempestivo','visto_bueno','jubilacion','fallecimiento','otra'];
    /** Causas en las que este modelo admite registrar bonificación por desahucio (las demás requieren «otra» con su respaldo). */
    public const CAUSAS_DESAHUCIO=['renuncia','despido_intempestivo','otra'];

    /**
     * @param array{base:int,dias_servicio:int,causa:string,desahucio:bool,vacaciones_tomadas_cent:int,d13:int,d14:int,otros_ingresos:int,descuentos:int} $in
     * @param array<string,int> $p vacaciones.dias_anuales, vacaciones.dias_base_anio, desahucio.pb, despido.meses_hasta_umbral, despido.umbral_anios, despido.meses_por_anio, despido.max_meses
     * @return array<string,int|bool>
     */
    public static function calcular(array $in,array $p):array
    {
        if($in['dias_servicio']<1)throw new \InvalidArgumentException('La fecha de salida es anterior al ingreso.');
        if(!in_array($in['causa'],self::CAUSAS,true))throw new \InvalidArgumentException('Causa de terminación no válida.');
        if($in['desahucio']&&!in_array($in['causa'],self::CAUSAS_DESAHUCIO,true))throw new \InvalidArgumentException('La bonificación por desahucio solo se registra con renuncia, despido intempestivo u «otra» causa con respaldo.');
        $base=$in['base'];$dias=$in['dias_servicio'];$baseAnio=$p['vacaciones.dias_base_anio'];
        $completos=intdiv($dias,$baseAnio);
        $anios=$completos+(($dias%$baseAnio)>0?1:0);// indemnización (art. 188): la fracción cuenta como año completo
        // Vacaciones: días devengados (centésimas) proporcionales al servicio, menos los tomados; valor a base/30 por día.
        $devCent=intdiv($p['vacaciones.dias_anuales']*100*$dias+intdiv($baseAnio,2),$baseAnio);
        $pendCent=max(0,$devCent-$in['vacaciones_tomadas_cent']);
        $vacaciones=intdiv($base*$pendCent+1500,3000);
        $desahucio=$in['desahucio']?intdiv($base*$completos*$p['desahucio.pb']+5000,10000):0;// desahucio (art. 185): años de servicio completos
        $meses=0;$indemnizacion=0;
        if($in['causa']==='despido_intempestivo'){
            $meses=$dias<=$p['despido.umbral_anios']*$baseAnio?$p['despido.meses_hasta_umbral']:min($p['despido.max_meses'],$anios*$p['despido.meses_por_anio']);
            $indemnizacion=$base*$meses;
        }
        $total=$in['d13']+$in['d14']+$vacaciones+$desahucio+$indemnizacion+$in['otros_ingresos']-$in['descuentos'];
        if($total<0)throw new \InvalidArgumentException('Los descuentos superan lo que se liquida al colaborador.');
        return ['anios_completos'=>$completos,'anios_computados'=>$anios,'vacaciones_devengadas_cent'=>$devCent,'vacaciones_pendientes_cent'=>$pendCent,'vacaciones_centavos'=>$vacaciones,'desahucio_centavos'=>$desahucio,'meses_indemnizacion'=>$meses,'indemnizacion_centavos'=>$indemnizacion,'total_centavos'=>$total];
    }
}

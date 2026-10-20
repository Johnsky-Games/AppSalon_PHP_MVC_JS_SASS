<?php
namespace Services;

/**
 * Consolidado anual de relación de dependencia por trabajador (solo lectura): la base de datos de la que saldrán el formulario 107 y el anexo RDEP.
 * Suma, por concepto interno, lo que ya está APROBADO en la aplicación (corridas, beneficios liquidados, finiquitos y utilidades) y verifica que cuadre contra las
 * obligaciones al IESS y al SRI de cada corrida y que cada trabajador tenga su identificación.
 *
 * NO es el archivo oficial: no usa casilleros, códigos ni formato del SRI (la ficha técnica, el catálogo y el esquema vigentes no pudieron consultarse). El mapa de
 * cada concepto a su casillero se agrega cuando se cuente con esos documentos; mientras tanto el consolidado es un borrador de control para el contador.
 */
final class ErpNominaAnualService extends ErpService
{
    /** Conceptos de las líneas de nómina que se suman tal cual. */
    private const LINEA=['sueldo'=>'sueldo_centavos','horas'=>'horas_centavos','bonos'=>'bonos_centavos','comisiones'=>'comisiones_centavos','decimo_tercero_mensualizado'=>'d13_mensual_centavos','decimo_cuarto_mensualizado'=>'d14_mensual_centavos','fondos_reserva_mensualizado'=>'fondos_mensual_centavos','ingresos'=>'ingresos_centavos','base_aportable_iess'=>'gravado_iess_centavos','iess_personal'=>'iess_personal_centavos','impuesto_renta_retenido'=>'ir_centavos','descuentos'=>'descuentos_centavos','neto_pagado'=>'neto_centavos','iess_patronal'=>'iess_patronal_centavos','decimo_tercero_provisionado'=>'d13_prov_centavos','decimo_cuarto_provisionado'=>'d14_prov_centavos','fondos_reserva_acumulado'=>'fondos_acum_centavos'];
    private const FINIQUITO=['finiquito_decimo_tercero'=>'d13_centavos','finiquito_decimo_cuarto'=>'d14_centavos','finiquito_vacaciones'=>'vacaciones_centavos','finiquito_desahucio'=>'desahucio_centavos','finiquito_indemnizacion'=>'indemnizacion_centavos','finiquito_otros_ingresos'=>'otros_ingresos_centavos','finiquito_descuentos'=>'descuentos_centavos','finiquito_total'=>'total_centavos'];

    /** @return list<string> claves de los conceptos, en orden de presentación */
    public static function conceptos():array {return array_merge(array_keys(self::LINEA),['decimo_tercero_liquidado','decimo_cuarto_liquidado'],array_keys(self::FINIQUITO),['utilidades']);}

    /**
     * @return array{anio:int,filas:list<array<string,mixed>>,totales:array<string,int>,controles:array<string,mixed>}
     */
    public function consolidado(int $anio):array
    {
        if($anio<2000||$anio>2100)throw new ErpException('Año fuera de rango.');
        $vacio=array_fill_keys(self::conceptos(),0);$por=[];
        $nuevo=fn(int $id)=>$por[$id]??=['colaborador_id'=>$id,'meses'=>0,'dias'=>0]+$vacio;
        // 1) Corridas aprobadas del año.
        $sel=implode(',',array_map(fn($k,$c)=>"SUM(l.$c) $k",array_keys(self::LINEA),self::LINEA));
        foreach($this->r->q("SELECT l.colaborador_id,COUNT(*) meses,SUM(l.dias) dias,$sel FROM erp_nomina_lineas l JOIN erp_nomina_corridas k ON k.id=l.corrida_id WHERE k.anio=? AND k.estado='aprobada' GROUP BY l.colaborador_id",[$anio]) as $f){
            $x=$nuevo((int)$f['colaborador_id']);$x['meses']=(int)$f['meses'];$x['dias']=(int)$f['dias'];foreach(array_keys(self::LINEA) as $k)$x[$k]=(int)$f[$k];$por[(int)$f['colaborador_id']]=$x;
        }
        // 2) Décimos liquidados con «Beneficios acumulados» durante el año.
        foreach($this->r->q("SELECT colaborador_id,tipo,SUM(monto_centavos) m FROM erp_nomina_beneficios WHERE YEAR(creado_en)=? GROUP BY colaborador_id,tipo",[$anio]) as $f){
            $x=$nuevo((int)$f['colaborador_id']);$x[$f['tipo'].'_liquidado']+=(int)$f['m'];$por[(int)$f['colaborador_id']]=$x;
        }
        // 3) Finiquitos aprobados con salida en el año.
        $selF=implode(',',array_map(fn($k,$c)=>"SUM($c) $k",array_keys(self::FINIQUITO),self::FINIQUITO));
        foreach($this->r->q("SELECT colaborador_id,$selF FROM erp_nomina_finiquitos WHERE estado='aprobado' AND YEAR(fecha_salida)=? GROUP BY colaborador_id",[$anio]) as $f){
            $x=$nuevo((int)$f['colaborador_id']);foreach(array_keys(self::FINIQUITO) as $k)$x[$k]=(int)$f[$k];$por[(int)$f['colaborador_id']]=$x;
        }
        // 4) Utilidades aprobadas durante el año (el ejercicio que reparten es el anterior; se muestra aparte en la pantalla).
        foreach($this->r->q("SELECT l.colaborador_id,SUM(l.monto_centavos) m FROM erp_nomina_utilidades_lineas l JOIN erp_nomina_utilidades u ON u.id=l.utilidad_id WHERE u.estado='aprobada' AND YEAR(u.aprobada_en)=? GROUP BY l.colaborador_id",[$anio]) as $f){
            $x=$nuevo((int)$f['colaborador_id']);$x['utilidades']=(int)$f['m'];$por[(int)$f['colaborador_id']]=$x;
        }
        $nombres=[];foreach($this->r->q('SELECT id,CONCAT(apellido,\' \',nombre) n FROM colaboradores') as $c)$nombres[(int)$c['id']]=$c['n'];
        $ident=(new ErpIdentificacionService($this->r))->vigentes();
        $filas=[];$totales=array_fill_keys(self::conceptos(),0);
        foreach($por as $id=>$x){
            $x['nombre']=$nombres[$id]??('Colaborador '.$id);$x['identificacion_tipo']=$ident[$id]['tipo']??null;$x['identificacion_numero']=$ident[$id]['numero']??null;
            foreach(self::conceptos() as $k)$totales[$k]+=$x[$k];$filas[]=$x;
        }
        usort($filas,fn($a,$b)=>strcmp($a['nombre'],$b['nombre']));
        $totales['meses']=array_sum(array_column($filas,'meses'));
        return ['anio'=>$anio,'filas'=>$filas,'totales'=>$totales,'controles'=>$this->controles($anio,$filas)];
    }

    /**
     * Controles de integridad. Cada uno dice si está bien (`ok`) y qué revisar; ninguno corrige nada.
     * @param list<array<string,mixed>> $filas
     */
    private function controles(int $anio,array $filas):array
    {
        $sinIdent=array_values(array_map(fn($f)=>['colaborador_id'=>$f['colaborador_id'],'nombre'=>$f['nombre']],array_filter($filas,fn($f)=>$f['identificacion_numero']===null)));
        $meses=array_map(fn($f)=>(int)$f['m'],$this->r->q("SELECT DISTINCT mes m FROM erp_nomina_corridas WHERE anio=? AND estado='aprobada' ORDER BY mes",[$anio]));
        $faltan=array_values(array_diff(range(1,12),$meses));
        // Cada corrida aprobada: sus líneas deben coincidir con las obligaciones al IESS y al SRI que generó.
        $iess=[];$ir=[];
        foreach($this->r->q("SELECT k.id,k.mes,
                COALESCE((SELECT SUM(l.iess_personal_centavos+l.iess_patronal_centavos+l.fondos_acum_centavos) FROM erp_nomina_lineas l WHERE l.corrida_id=k.id),0) lineas_iess,
                COALESCE((SELECT o.monto_centavos FROM erp_obligaciones o WHERE o.id=k.obligacion_iess_id),0) obl_iess,
                COALESCE((SELECT SUM(l.ir_centavos) FROM erp_nomina_lineas l WHERE l.corrida_id=k.id),0) lineas_ir,
                COALESCE((SELECT o.monto_centavos FROM erp_obligaciones o WHERE o.id=k.obligacion_ir_id),0) obl_ir
            FROM erp_nomina_corridas k WHERE k.anio=? AND k.estado='aprobada' ORDER BY k.mes",[$anio]) as $c){
            if((int)$c['lineas_iess']!==(int)$c['obl_iess'])$iess[]=['mes'=>(int)$c['mes'],'lineas'=>(int)$c['lineas_iess'],'obligacion'=>(int)$c['obl_iess']];
            if((int)$c['lineas_ir']!==(int)$c['obl_ir'])$ir[]=['mes'=>(int)$c['mes'],'lineas'=>(int)$c['lineas_ir'],'obligacion'=>(int)$c['obl_ir']];
        }
        return [
            'identificacion'=>['ok'=>!$sinIdent,'sin_identificacion'=>$sinIdent],
            'meses_sin_corrida_aprobada'=>['ok'=>!$faltan,'meses'=>$faltan],
            'conciliacion_iess'=>['ok'=>!$iess,'diferencias'=>$iess],
            'conciliacion_impuesto_renta'=>['ok'=>!$ir,'diferencias'=>$ir],
        ];
    }

    /** CSV del consolidado (UTF-8 con BOM, separador «;») con las columnas internas. No es el formato del SRI. */
    public function csv(array $c):string
    {
        $texto=['nombre','identificacion_tipo','identificacion_numero'];$cols=array_merge($texto,['meses','dias'],self::conceptos());
        // Los textos que empiezan por = + - @ se neutralizan para que una hoja de cálculo no los interprete como fórmula; los importes son números.
        $celda=function(string $k,mixed $v)use($texto):string{
            if(in_array($k,$texto,true)){$t=(string)($v??'');if($t!==''&&strpos("=+-@\t\r",$t[0])!==false)$t="'".$t;return '"'.str_replace('"','""',$t).'"';}
            return '"'.(in_array($k,['meses','dias'],true)?(string)(int)$v:number_format(((int)$v)/100,2,'.','')).'"';
        };
        $out="\xEF\xBB\xBF".implode(';',array_map(fn($k)=>'"'.$k.'"',$cols))."\r\n";
        foreach($c['filas'] as $f)$out.=implode(';',array_map(fn($k)=>$celda($k,$f[$k]??null),$cols))."\r\n";
        return $out;
    }
}

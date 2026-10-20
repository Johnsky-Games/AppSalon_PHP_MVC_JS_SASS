<?php
/**
 * Auditoría de solo lectura del reparto de redondeo de las notas de crédito emitidas (hallazgo P1 de la revisión de #10).
 * No modifica la base ni los comprobantes. Uso: php scripts/auditar_notas_credito.php [--todo] [--json]
 *   por omisión lista solo las líneas NO conformes; --todo incluye también las conformes.
 *   --evidencia-tolerancia: qué dicen las notas ya procesadas por el SRI sobre desviaciones de 1 centavo (veredicto acepta / rechaza / sin_evidencia).
 * Código de salida: 0 sin líneas afectadas; 2 si hay líneas excedidas, con remanente, agotadas o desviadas.
 */
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require __DIR__.'/../includes/app.php';
try {
    $r=new \Repositories\ErpRepository();
    if(!$r->q("SHOW TABLES LIKE 'erp_fiscal_nc_lineas'")){echo "Sin notas de crédito: nada que auditar.".PHP_EOL;exit(0);}
    if(in_array('--evidencia-tolerancia',$argv,true)){
        // Qué dicen las notas que el SRI ya procesó sobre las desviaciones de 1 centavo (solo lectura).
        $ev=(new \Services\ErpFiscalParametroService($r))->evidenciaTolerancia();
        if(in_array('--json',$argv,true)){echo json_encode($ev,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;exit(0);}
        echo 'Veredicto: '.$ev['veredicto'].' | autorizadas con desviación: '.count($ev['autorizadas_con_desviacion']).' | rechazadas con desviación: '.count($ev['rechazadas_con_desviacion']).' | autorizadas exactas: '.$ev['autorizadas_exactas'].' | rechazadas exactas: '.$ev['rechazadas_exactas'].' | no evaluables: '.$ev['no_evaluables'].PHP_EOL;
        foreach(array_merge($ev['autorizadas_con_desviacion'],$ev['rechazadas_con_desviacion']) as $f)echo '- '.$f['clave_acceso'].' ambiente '.$f['ambiente'].': IVA '.$f['desviacion_iva'].' c, detalle '.$f['desviacion_detalle'].' c'.(isset($f['error_diferencias'])?' (rechazada'.($f['error_diferencias']?' con error de diferencias':'').')':' (autorizada)').PHP_EOL;
        if($ev['veredicto']==='sin_evidencia')echo 'Sin evidencia: no se registra ninguna tolerancia; cada nota debe cuadrar exactamente.'.PHP_EOL;
        exit(0);
    }
    $res=(new \Services\ErpFiscalService($r))->auditarRedondeoNotas();
    $todo=in_array('--todo',$argv,true);$afectadas=array_values(array_filter($res['lineas'],fn($l)=>$l['estado']!=='conforme'));
    if(in_array('--json',$argv,true)){echo json_encode(['resumen'=>$res['resumen'],'lineas'=>$todo?$res['lineas']:$afectadas],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;exit($afectadas?2:0);}
    echo 'Líneas auditadas: '.count($res['lineas']).' | '.implode(' | ',array_map(fn($k,$v)=>"$k: $v",array_keys($res['resumen']),$res['resumen'])).PHP_EOL;
    foreach($todo?$res['lineas']:$afectadas as $l){
        echo sprintf("- factura %s, línea %d «%s»: %s (acreditado base %d / IVA %d / total %d; objetivo base %d / IVA %d; diferencia total %+d centavos) notas: %s",
            $l['factura'],$l['linea_id'],$l['descripcion'],$l['estado'],$l['base'],$l['iva'],$l['total'],$l['objetivo']['base'],$l['objetivo']['iva'],$l['dif_total'],implode(', ',array_column($l['notas'],'numero'))).PHP_EOL;
    }
    exit($afectadas?2:0);
}catch(\Throwable $e){fwrite(STDERR,'Auditoría no disponible: '.get_class($e).' código '.$e->getCode().PHP_EOL);exit(1);}

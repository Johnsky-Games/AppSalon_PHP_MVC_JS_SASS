<?php
namespace Services\Contabilidad;

use Repositories\ErpRepository;
use Services\ErpContabilidadService;

/**
 * Control de conciliación entre el INVENTARIO VALORIZADO y el libro contable (cuenta del rol «inventario»). Es de SOLO LECTURA: explica la diferencia, nunca la corrige ni genera
 * ajustes para cuadrarla, y no contabiliza nada (compras, consumos, ventas y ajustes siguen su propio hecho contable).
 *
 * Alcance y fecha de corte
 * - Auxiliar: suma de los movimientos de inventario registrados hasta el cierre del día de corte, cada uno valorizado con SU costo (cantidad × costo, redondeo comercial al centavo);
 *   las entradas suman y las salidas restan. No valoriza las existencias al costo vigente: así cada movimiento se compara con el asiento que le corresponde.
 * - Libro: saldo de la cuenta de inventario con asientos fechados hasta el corte.
 * - Cada movimiento se liga a su hecho contable (evento) y se clasifica por su ESTADO actual: contabilizado, previo al inicio contable (ya está en la apertura), pendiente, fallido,
 *   contabilizado con fecha posterior al corte, sin asiento (valor cero), ajuste sin clasificar, sin evento contable o sin origen ERP (módulo heredado).
 * - La diferencia se descompone en partidas con signo que suman exactamente la diferencia; lo que ningún origen explica queda como RESIDUO (diferencias de valoración o redondeo,
 *   asientos manuales sobre la cuenta, etc.) y se muestra aparte para revisión. Los reversos y reimputaciones netean en la cuenta de inventario.
 */
final class InventarioValorizadoConciliacion
{
    /** Origen del movimiento (erp_stock_origenes.documento_tipo) → clase del informe. */
    private const CLASE_DOCUMENTO=['compra'=>'compras','devolucion_proveedor'=>'compras','venta'=>'ventas','devolucion'=>'ventas','consumo'=>'consumos','ajuste'=>'ajustes'];
    /** Tipo de hecho contable → clase del informe. */
    private const CLASE_EVENTO=['compra_recibida'=>'compras','compra_devuelta'=>'compras','productos_entregados'=>'ventas','venta_devuelta'=>'ventas','insumos_consumidos'=>'consumos','inventario_ajuste'=>'ajustes','inventario_reimputado'=>'ajustes'];
    public const CLASES=[
        'compras'=>'Compras (recepciones y devoluciones al proveedor)','ventas'=>'Ventas (entregas y reintegros por devolución)','consumos'=>'Consumos de insumos de servicios','ajustes'=>'Ajustes de inventario',
        'heredado'=>'Movimientos sin origen ERP (módulo de inventario heredado)','apertura'=>'Saldo de apertura del libro','manual'=>'Asientos manuales sobre la cuenta','otros'=>'Otros asientos sobre la cuenta',
    ];
    /** Estados de un movimiento frente al libro, en el orden en que se explican. */
    public const ESTADOS=[
        'contabilizado'=>'Contabilizado','previo_inicio'=>'Anterior al inicio contable (ya está en la apertura)','pendiente'=>'Pendiente de contabilizar','fallido'=>'Fallido (requiere reintento)',
        'tardio'=>'Contabilizado con fecha posterior al corte','sin_asiento'=>'Confirmado sin asiento (valor cero)','sin_clasificar'=>'Ajuste sin clasificar (anterior a los conceptos)',
        'sin_evento'=>'Sin hecho contable asociado','heredado'=>'Sin origen ERP: no tiene regla contable',
    ];
    private ErpContabilidadService $c;
    public function __construct(private ErpRepository $r)
    {
        $this->c=new ErpContabilidadService($r);
    }

    private function fecha(string $f):string
    {
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$f)||!checkdate((int)substr($f,5,2),(int)substr($f,8,2),(int)substr($f,0,4)))throw new \InvalidArgumentException('Fecha de corte no válida.');
        return $f;
    }
    /** Valor de un movimiento: cantidad × costo con redondeo comercial (igual que el adaptador contable), con signo según el sentido. */
    private static function valor(array $m):int
    {
        $v=intdiv((int)$m['costo_centavos']*(int)$m['cantidad_milesimas']+500,1000);
        return $m['tipo']==='entrada'?$v:-$v;
    }

    /** Movimientos con su hecho contable y el estado de su cola, hasta el corte. Una consulta; la clasificación se hace aquí. */
    private function movimientos(string $hasta):array
    {
        $enlaces="SELECT m.id movimiento_id,e.id evento_id FROM inventario_movimientos m JOIN erp_eventos e ON e.tipo IN('compra_recibida','compra_devuelta') AND CAST(JSON_UNQUOTE(JSON_EXTRACT(e.datos_json,'\$.movimiento_id')) AS UNSIGNED)=m.id
            UNION ALL SELECT jt.mov,e.id FROM erp_eventos e,JSON_TABLE(e.datos_json,'\$.entregas[*]' COLUMNS(mov BIGINT PATH '\$.movimiento_id')) jt WHERE e.tipo='productos_entregados'
            UNION ALL SELECT o.movimiento_id,e.id FROM erp_stock_origenes o JOIN erp_eventos e ON e.tipo='insumos_consumidos' AND e.entidad_id=o.documento_id WHERE o.documento_tipo='consumo'
            UNION ALL SELECT o.movimiento_id,e.id FROM erp_stock_origenes o JOIN erp_eventos e ON e.tipo='venta_devuelta' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(e.datos_json,'\$.devolucion_id')) AS UNSIGNED)=o.documento_id
                AND CAST(JSON_UNQUOTE(JSON_EXTRACT(e.datos_json,'\$.linea_id')) AS UNSIGNED)=o.venta_linea_id WHERE o.documento_tipo='devolucion'
            UNION ALL SELECT c.movimiento_id,c.evento_id FROM erp_inv_ajuste_conceptos c WHERE c.evento_id IS NOT NULL";
        return $this->r->q("SELECT m.id,m.producto_id,p.nombre producto,m.tipo,m.cantidad_milesimas,m.costo_centavos,m.registrado_en,m.motivo,o.documento_tipo,o.documento_id,
                v.evento_id,x.estado outbox_estado,x.intentos,x.respuesta_json,x.proximo_intento,
                (SELECT MIN(a.fecha) FROM erp_cont_asientos a WHERE a.origen IN(CONCAT('outbox:ERP-',v.evento_id,'-contabilidad#1'),CONCAT('outbox:ERP-',v.evento_id,'-contabilidad#2')) AND a.tipo<>'reverso') primer_asiento,
                (SELECT COUNT(*) FROM erp_inv_ajuste_conceptos k WHERE k.movimiento_id=m.id) clasificado
            FROM inventario_movimientos m JOIN inventario_productos p ON p.id=m.producto_id
            LEFT JOIN erp_stock_origenes o ON o.movimiento_id=m.id
            LEFT JOIN ($enlaces) v ON v.movimiento_id=m.id
            LEFT JOIN erp_integraciones_outbox x ON x.evento_id=v.evento_id AND x.destino='contabilidad'
            WHERE m.registrado_en<=? ORDER BY m.id",[$hasta.' 23:59:59']);
    }
    /** Clase y estado de un movimiento frente al libro. */
    private function clasificar(array $m,?string $inicio,string $hasta):array
    {
        $doc=$m['documento_tipo'];$clase=$doc?(self::CLASE_DOCUMENTO[$doc]??'heredado'):'heredado';
        $previo=$inicio!==null&&substr((string)$m['registrado_en'],0,10)<$inicio;
        // El inicio contable manda sobre el estado de la cola: lo anterior ya está en la apertura y su hecho contable nunca generará asiento (el adaptador lo confirma como
        // «previo-a-inicio-contable»), así que no se presenta como pendiente ni fallido aunque la cola aún no lo haya procesado. Si de todos modos tiene asiento, se trata como contabilizado.
        if($previo&&!$m['primer_asiento'])return [$clase,'previo_inicio'];
        if(!$doc)return [$clase,'heredado'];
        if($doc==='ajuste'&&!(int)$m['clasificado'])return [$clase,'sin_clasificar'];
        if(!$m['evento_id'])return [$clase,'sin_evento'];
        $e=(string)$m['outbox_estado'];
        if($e==='fallido')return [$clase,'fallido'];
        if($e!=='confirmado')return [$clase,'pendiente'];
        $resp=(string)$m['respuesta_json'];
        if(str_contains($resp,'previo-a-inicio-contable'))return [$clase,'previo_inicio'];
        if(str_contains($resp,'sin-asiento'))return [$clase,'sin_asiento'];
        if($m['primer_asiento']&&$m['primer_asiento']>$hasta)return [$clase,'tardio'];
        return [$clase,'contabilizado'];
    }
    /** Neto (debe − haber) de la cuenta de inventario por clase de origen, hasta el corte. */
    private function libroPorClase(string $hasta):array
    {
        $cuenta=$this->r->uno("SELECT r.cuenta_id FROM erp_cont_roles r WHERE r.rol='inventario'");
        $out=array_fill_keys(array_keys(self::CLASES),0);if(!$cuenta)return $out;
        $filas=$this->r->q('SELECT a.id,a.tipo,a.origen,a.reversa_de_id,SUM(l.debe_centavos)-SUM(l.haber_centavos) neto FROM erp_cont_lineas l JOIN erp_cont_asientos a ON a.id=l.asiento_id WHERE l.cuenta_id=? AND a.fecha<=? GROUP BY a.id,a.tipo,a.origen,a.reversa_de_id',[(int)$cuenta['cuenta_id'],$hasta]);
        // Un reverso se atribuye al origen del asiento que anula (un reverso no se revierte, así que basta un nivel).
        $anulados=array_values(array_unique(array_map('intval',array_filter(array_column($filas,'reversa_de_id')))));$origenDe=[];
        if($anulados)foreach($this->r->q('SELECT id,tipo,origen FROM erp_cont_asientos WHERE id IN('.implode(',',$anulados).')') as $a)$origenDe[(int)$a['id']]=$a;
        $ids=[];foreach(array_merge($filas,array_values($origenDe)) as $f)if(preg_match('/^outbox:ERP-(\d+)-contabilidad#\d+$/D',(string)$f['origen'],$mm))$ids[(int)$mm[1]]=1;
        $tipos=[];if($ids)foreach($this->r->q('SELECT id,tipo FROM erp_eventos WHERE id IN('.implode(',',array_keys($ids)).')') as $e)$tipos[(int)$e['id']]=$e['tipo'];
        foreach($filas as $f){
            $a=($f['tipo']==='reverso'&&$f['reversa_de_id']&&isset($origenDe[(int)$f['reversa_de_id']]))?$origenDe[(int)$f['reversa_de_id']]:$f;
            $clase='otros';
            if($a['tipo']==='apertura')$clase='apertura';
            elseif($a['tipo']==='manual')$clase='manual';
            elseif(preg_match('/^outbox:ERP-(\d+)-contabilidad#\d+$/D',(string)$a['origen'],$mm))$clase=self::CLASE_EVENTO[$tipos[(int)$mm[1]]??'']??'otros';
            $out[$clase]+=(int)$f['neto'];
        }
        return $out;
    }

    /**
     * Informe de conciliación al corte. Devuelve libro, auxiliar y diferencia, el cuadro por origen, las partidas que explican la diferencia (con signo, suman exactamente la
     * diferencia) y el residuo que ningún origen explica.
     */
    public function informe(string $hasta):array
    {
        $hasta=$this->fecha($hasta);$inicio=$this->c->config()['inicio_contable']??null;
        $cuentaInv=$this->r->uno("SELECT r.cuenta_id FROM erp_cont_roles r WHERE r.rol='inventario'");
        $libroClase=$this->libroPorClase($hasta);$libro=array_sum($libroClase);
        $aux=array_fill_keys(array_keys(self::CLASES),0);$estadoClase=[];$estados=array_fill_keys(array_keys(self::ESTADOS),['n'=>0,'valor'=>0]);$auxTotal=0;$n=0;
        foreach($this->movimientos($hasta) as $m){
            [$clase,$estado]=$this->clasificar($m,$inicio,$hasta);$v=self::valor($m);
            $aux[$clase]+=$v;$auxTotal+=$v;$n++;$estados[$estado]['n']++;$estados[$estado]['valor']+=$v;
            $estadoClase[$clase][$estado]=($estadoClase[$clase][$estado]??0)+$v;
        }
        $porOrigen=[];$residuo=0;
        foreach(self::CLASES as $k=>$etq){
            $contab=$estadoClase[$k]['contabilizado']??0;
            // Lo contabilizado debería estar en el libro por su valor; lo que sobre o falte es residuo (valoración, redondeo, asientos manuales).
            $res=in_array($k,['compras','ventas','consumos','ajustes'],true)?$libroClase[$k]-$contab:0;$residuo+=$res;
            $porOrigen[]=['clase'=>$k,'etiqueta'=>$etq,'auxiliar'=>$aux[$k],'libro'=>$libroClase[$k],'diferencia'=>$libroClase[$k]-$aux[$k],'residuo'=>$res,'estados'=>$estadoClase[$k]??[]];
        }
        // Partidas: aportan a (libro − auxiliar) con el signo de lo que falta en el libro (−valor del movimiento no contabilizado) o sobra en él (apertura, manuales, otros).
        $partidas=[];
        $mas=['apertura'=>'Saldo de apertura del libro (debe igualar los movimientos anteriores al inicio)','manual'=>'Asientos manuales sobre la cuenta de inventario','otros'=>'Otros asientos sobre la cuenta'];
        foreach($mas as $k=>$etq)if($libroClase[$k]!==0)$partidas[]=['codigo'=>$k,'etiqueta'=>$etq,'monto'=>$libroClase[$k],'movimientos'=>0];
        foreach(['previo_inicio','pendiente','fallido','tardio','sin_asiento','sin_clasificar','sin_evento','heredado'] as $s)
            if($estados[$s]['n']>0||$estados[$s]['valor']!==0)$partidas[]=['codigo'=>$s,'etiqueta'=>self::ESTADOS[$s],'monto'=>-$estados[$s]['valor'],'movimientos'=>$estados[$s]['n']];
        if($residuo!==0)$partidas[]=['codigo'=>'residuo','etiqueta'=>'Residuo no atribuible a un estado (valoración, redondeo o asientos distintos al movimiento)','monto'=>$residuo,'movimientos'=>0];
        $dif=$libro-$auxTotal;$suma=array_sum(array_column($partidas,'monto'));
        return ['hasta'=>$hasta,'inicio_contable'=>$inicio,'cuenta_inventario'=>$cuentaInv?(int)$cuentaInv['cuenta_id']:null,'movimientos'=>$n,'libro'=>$libro,'auxiliar'=>$auxTotal,'diferencia'=>$dif,
            'por_origen'=>$porOrigen,'estados'=>$estados,'partidas'=>$partidas,'residuo'=>$residuo,'cuadra_partidas'=>$suma===$dif,'conciliado'=>$dif===0,
            'nota'=>'Solo lectura: este control explica la diferencia; no genera ajustes ni contabiliza compras, consumos o ventas.'];
    }
    /**
     * Detalle trazable de los movimientos de un estado (y, opcionalmente, de una clase) al corte: cada fila con su producto, fecha, origen, valor, hecho contable y estado de la cola.
     * @return list<array<string,mixed>>
     */
    public function detalle(string $hasta,string $estado,?string $clase=null,int $limite=100):array
    {
        $hasta=$this->fecha($hasta);if(!isset(self::ESTADOS[$estado]))throw new \InvalidArgumentException('Estado no válido.');
        if($clase!==null&&!isset(self::CLASES[$clase]))throw new \InvalidArgumentException('Origen no válido.');
        $inicio=$this->c->config()['inicio_contable']??null;$out=[];
        foreach($this->movimientos($hasta) as $m){
            [$c,$e]=$this->clasificar($m,$inicio,$hasta);if($e!==$estado||($clase!==null&&$c!==$clase))continue;
            $out[]=['movimiento_id'=>(int)$m['id'],'fecha'=>substr((string)$m['registrado_en'],0,10),'producto'=>$m['producto'],'sentido'=>$m['tipo'],'cantidad_milesimas'=>(int)$m['cantidad_milesimas'],'valor'=>self::valor($m),
                'origen'=>self::CLASES[$c],'documento'=>$m['documento_tipo']?($m['documento_tipo'].' #'.(int)$m['documento_id']):'—','motivo'=>$m['motivo'],'evento_id'=>$m['evento_id']?(int)$m['evento_id']:null,
                'cola'=>$m['outbox_estado']?:'—','intentos'=>$m['intentos']!==null?(int)$m['intentos']:null,'proximo_intento'=>$m['proximo_intento']];
            if(count($out)>=$limite)break;
        }
        return $out;
    }
}

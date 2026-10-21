<?php
namespace Services;

/**
 * Conceptos de ajuste de inventario (migración 055). Mismo criterio que los conceptos de caja: el negocio crea el concepto y lo asocia a una cuenta de SU plan;
 * no se siembran conceptos ni códigos de cuenta. La naturaleza fija el sentido del ajuste y el tipo de cuenta admitido. Un ajuste de stock no se clasifica solo como
 * pérdida o ganancia: lo decide el concepto elegido en la solicitud. Permisos: crear, asignar cuenta y activar/desactivar = `contabilidad.configurar`.
 */
final class ErpInventarioConceptoService extends ErpService
{
    /** naturaleza => [sentido del ajuste, tipos de cuenta admitidos, etiqueta] */
    public const NATURALEZAS=[
        'merma_o_perdida'=>['salida',['gasto','costo'],'Merma o pérdida'],
        'faltante_de_conteo'=>['salida',['gasto','costo'],'Faltante de conteo físico'],
        'sobrante_de_conteo'=>['entrada',['ingreso','costo'],'Sobrante de conteo físico'],
        'uso_interno_u_obsequio'=>['salida',['gasto'],'Uso interno u obsequio'],
    ];

    private function errorCuenta(string $naturaleza,array $cuenta):?string
    {
        if((int)$cuenta['imputable']!==1||(int)$cuenta['activa']!==1)return 'La cuenta '.$cuenta['codigo'].' debe ser imputable y estar activa.';
        $admitidos=self::NATURALEZAS[$naturaleza][1];
        if(!in_array($cuenta['tipo'],$admitidos,true))return 'Un concepto de «'.self::NATURALEZAS[$naturaleza][2].'» exige una cuenta de tipo '.implode(' o ',$admitidos).'; la '.$cuenta['codigo'].' es de tipo '.$cuenta['tipo'].'.';
        $inv=$this->r->uno("SELECT cuenta_id FROM erp_cont_roles WHERE rol='inventario'");
        if($inv&&(int)$inv['cuenta_id']===(int)$cuenta['id'])return 'La contrapartida no puede ser la misma cuenta de inventario: no sería un ajuste.';
        return null;
    }
    private function cuentaPorCodigo(mixed $codigo):array
    {
        return $this->r->uno('SELECT * FROM erp_cont_cuentas WHERE codigo=? FOR SHARE',[is_string($codigo)?trim($codigo):''])??throw new ErpException('La cuenta no existe en el plan contable.',404);
    }

    public function crear(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'contabilidad.configurar','inv_concepto',$d,function()use($actor,$d){
            $nombre=$this->texto($d['nombre']??'',80);if(mb_strlen($nombre)<3)throw new ErpException('El nombre del concepto tiene al menos 3 caracteres.');
            $nat=is_string($d['naturaleza']??null)?$d['naturaleza']:'';if(!isset(self::NATURALEZAS[$nat]))throw new ErpException('Naturaleza del concepto no válida.');
            if($this->r->uno('SELECT id FROM erp_inv_conceptos WHERE nombre=? FOR UPDATE',[$nombre]))throw new ErpException('Ya existe un concepto con ese nombre.',409);
            $cuenta=null;
            if(trim((string)($d['cuenta']??''))!==''){$c=$this->cuentaPorCodigo($d['cuenta']);if($e=$this->errorCuenta($nat,$c))throw new ErpException($e);$cuenta=(int)$c['id'];}
            $this->r->q('INSERT INTO erp_inv_conceptos(nombre,naturaleza,tipo,cuenta_id,creado_por,creado_en) VALUES(?,?,?,?,?,?)',[$nombre,$nat,self::NATURALEZAS[$nat][0],$cuenta,$actor,$this->r::ahora()]);$id=$this->r->id();
            $this->evento($actor,'inv_concepto_creado','inv_concepto',$id,['nombre'=>$nombre,'naturaleza'=>$nat,'cuenta_id'=>$cuenta]);return $id;
        });
    }
    /** Asigna o cambia la cuenta: los ajustes ya contabilizados no cambian (asientos inmutables); los pendientes usarán la cuenta nueva y se reintentan de inmediato. */
    public function asignarCuenta(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'contabilidad.configurar','inv_concepto_cuenta',$d,function()use($actor,$d){
            $k=$this->r->uno('SELECT * FROM erp_inv_conceptos WHERE id=? FOR UPDATE',[$this->id($d['concepto_id']??null)])??throw new ErpException('Concepto no encontrado.',404);
            $c=$this->cuentaPorCodigo($d['cuenta']??null);if($e=$this->errorCuenta($k['naturaleza'],$c))throw new ErpException($e);
            $this->r->q('UPDATE erp_inv_conceptos SET cuenta_id=?,actualizado_por=?,actualizado_en=? WHERE id=?',[(int)$c['id'],$actor,$this->r::ahora(),(int)$k['id']]);
            $this->evento($actor,'inv_concepto_cuenta','inv_concepto',(int)$k['id'],['anterior'=>$k['cuenta_id']?(int)$k['cuenta_id']:null,'nueva'=>(int)$c['id']]);
            $this->r->q("UPDATE erp_integraciones_outbox o JOIN erp_eventos e ON e.id=o.evento_id JOIN erp_inv_ajuste_conceptos m ON m.movimiento_id=CAST(JSON_UNQUOTE(JSON_EXTRACT(e.datos_json,'\$.movimiento_id')) AS UNSIGNED) SET o.proximo_intento=NULL,o.estado='pendiente' WHERE o.destino='contabilidad' AND o.estado='fallido' AND ((e.tipo='inventario_ajuste' AND m.concepto_id=?) OR (e.tipo='inventario_reimputado' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(e.datos_json,'\$.concepto_id')) AS UNSIGNED)=?))",[(int)$k['id'],(int)$k['id']]);
            return (int)$k['id'];
        });
    }
    public function estado(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'contabilidad.configurar','inv_concepto_estado',$d,function()use($actor,$d){
            $k=$this->r->uno('SELECT * FROM erp_inv_conceptos WHERE id=? FOR UPDATE',[$this->id($d['concepto_id']??null)])??throw new ErpException('Concepto no encontrado.',404);
            $activo=($d['activo']??'')==='1'?1:0;
            $this->r->q('UPDATE erp_inv_conceptos SET activo=?,actualizado_por=?,actualizado_en=? WHERE id=?',[$activo,$actor,$this->r::ahora(),(int)$k['id']]);
            $this->evento($actor,'inv_concepto_estado','inv_concepto',(int)$k['id'],['activo'=>$activo]);return (int)$k['id'];
        });
    }

    /** Qué falta completar para contabilizar un concepto (null = listo). Lo usan el adaptador contable y la pantalla. */
    public function problema(int $conceptoId):?string
    {
        $k=$this->r->uno('SELECT k.*,c.codigo,c.tipo cuenta_tipo,c.imputable,c.activa,c.id cid FROM erp_inv_conceptos k LEFT JOIN erp_cont_cuentas c ON c.id=k.cuenta_id WHERE k.id=?',[$conceptoId]);
        if(!$k)return 'El concepto del ajuste ya no existe.';
        if(!$k['cid'])return 'Asigna una cuenta del plan contable al concepto «'.$k['nombre'].'» (Contabilidad → Ajustes de inventario).';
        $e=$this->errorCuenta($k['naturaleza'],['id'=>$k['cid'],'codigo'=>$k['codigo'],'tipo'=>$k['cuenta_tipo'],'imputable'=>$k['imputable'],'activa'=>$k['activa']]);
        return $e===null?null:'Corrige la cuenta del concepto «'.$k['nombre'].'»: '.$e;
    }
    public function cuentaDe(int $conceptoId):int
    {
        if($p=$this->problema($conceptoId))throw new ErpException($p,409);
        return (int)$this->r->uno('SELECT cuenta_id FROM erp_inv_conceptos WHERE id=?',[$conceptoId])['cuenta_id'];
    }
    /** Concepto activo y de sentido coincidente, para validar la solicitud de ajuste. */
    public function validarParaSolicitud(mixed $conceptoId,string $movimiento):int
    {
        if(trim((string)$conceptoId)==='')throw new ErpException('Elige el concepto del ajuste: sin concepto no se sabe si es merma, faltante, sobrante o uso interno.');
        $k=$this->r->uno('SELECT * FROM erp_inv_conceptos WHERE id=? FOR SHARE',[$this->id($conceptoId)])??throw new ErpException('Concepto de ajuste no encontrado.',404);
        if((int)$k['activo']!==1)throw new ErpException('El concepto «'.$k['nombre'].'» está desactivado.',409);
        if($k['tipo']!==$movimiento)throw new ErpException('El concepto «'.$k['nombre'].'» es de '.$k['tipo'].': no corresponde a una '.$movimiento.'.');
        return (int)$k['id'];
    }

    // ------------------------------------------------- reclasificación explícita (056)
    /** Valor del ajuste al costo de su movimiento, o ErpException si no cabe en una línea del libro (queda pendiente para siempre). */
    private function valorAjuste(array $mov):int
    {
        $c=(int)$mov['costo_centavos'];$q=(int)$mov['cantidad_milesimas'];
        if($q>0&&$c>intdiv(PHP_INT_MAX-500,$q)||intdiv($c*$q+500,1000)>99999999999)throw new ErpException('El valor del ajuste (cantidad × costo) excede el máximo de un asiento contable.');
        return intdiv($c*$q+500,1000);
    }
    /** Movimiento de ajuste aprobado (clave `erp.ajuste:`), bloqueado para decidir sobre él. */
    private function ajusteBloqueado(mixed $id):array
    {
        $m=$this->r->uno("SELECT * FROM inventario_movimientos WHERE id=? FOR UPDATE",[$this->id($id)])??throw new ErpException('Movimiento de inventario no encontrado.',404);
        if(!str_starts_with((string)$m['idempotencia'],'erp.ajuste:'))throw new ErpException('Solo se reclasifican ajustes de inventario aprobados; este movimiento tiene su propio hecho contable.',422);
        return $m;
    }
    private function conceptoParaAjuste(mixed $id,array $mov):array
    {
        $k=$this->r->uno('SELECT * FROM erp_inv_conceptos WHERE id=? FOR SHARE',[$this->id($id)])??throw new ErpException('Concepto de ajuste no encontrado.',404);
        if((int)$k['activo']!==1)throw new ErpException('El concepto «'.$k['nombre'].'» está desactivado.',409);
        if($k['tipo']!==$mov['tipo'])throw new ErpException('El concepto «'.$k['nombre'].'» es de '.$k['tipo'].': el ajuste fue una '.$mov['tipo'].'.');
        return $k;
    }
    private function motivoObligatorio(mixed $m):string
    {
        $m=$this->texto($m??'',300);if(mb_strlen($m)<5)throw new ErpException('Explica el motivo (al menos 5 caracteres).');return $m;
    }
    /**
     * Ajustes aprobados ANTES de los conceptos (055): existen en inventario, nunca se contabilizaron y no tienen concepto. Nada los clasifica solo.
     */
    public function historicos(int $limite=50):array
    {
        return $this->r->q("SELECT i.id,i.tipo,i.cantidad_milesimas,i.costo_centavos,i.motivo,i.registrado_en,p.nombre producto FROM inventario_movimientos i JOIN inventario_productos p ON p.id=i.producto_id
            WHERE i.idempotencia LIKE 'erp.ajuste:%' AND NOT EXISTS(SELECT 1 FROM erp_inv_ajuste_conceptos c WHERE c.movimiento_id=i.id) ORDER BY i.id LIMIT ".(int)$limite);
    }
    /**
     * Clasifica UN ajuste histórico: elige concepto activo de su sentido y deja el motivo. No toca el movimiento ni las existencias; anexa la clasificación (inmutable) y emite
     * el hecho contable, que se contabiliza una sola vez en la fecha de la decisión con la fecha original en el texto. Si el ajuste fue anterior al inicio contable, su efecto ya
     * está en la apertura y no genera asiento. Para cambiar una clasificación existente se usa reimputar().
     */
    public function reclasificar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'contabilidad.configurar','inv_reclasificacion',$d,function()use($actor,$d){
            $m=$this->ajusteBloqueado($d['movimiento_id']??null);
            if($this->r->uno('SELECT movimiento_id FROM erp_inv_ajuste_conceptos WHERE movimiento_id=? FOR UPDATE',[(int)$m['id']]))throw new ErpException('Ese ajuste ya está clasificado: para cambiar su imputación usa «Reimputar».',409);
            $k=$this->conceptoParaAjuste($d['concepto_id']??null,$m);$motivo=$this->motivoObligatorio($d['motivo']??null);$this->valorAjuste($m);
            $evento=$this->evento($actor,'inventario_ajuste','inventario',(int)$m['id'],['movimiento_id'=>(int)$m['id'],'producto_id'=>(int)$m['producto_id'],'tipo'=>$m['tipo'],'cantidad_milesimas'=>(int)$m['cantidad_milesimas'],'costo_unitario'=>(int)$m['costo_centavos'],'concepto_id'=>(int)$k['id'],'reclasificacion'=>1,'fecha_original'=>substr((string)$m['registrado_en'],0,10),'motivo'=>$motivo],['contabilidad']);
            $this->r->q('INSERT INTO erp_inv_ajuste_conceptos(movimiento_id,concepto_id,solicitud_id,evento_id,creado_por,creado_en) VALUES(?,?,NULL,?,?,?)',[(int)$m['id'],(int)$k['id'],$evento,$actor,$this->r::ahora()]);
            return (int)$m['id'];
        });
    }
    /** Concepto y evento contable VIGENTES de un ajuste: la última reimputación o, si no hay, la clasificación original. */
    private function vigente(int $movimiento):?array
    {
        $r=$this->r->uno('SELECT id,concepto_nuevo_id concepto_id,evento_id FROM erp_inv_reimputaciones WHERE movimiento_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE',[$movimiento]);
        if($r)return ['reimputacion_id'=>(int)$r['id'],'concepto_id'=>(int)$r['concepto_id'],'evento_id'=>(int)$r['evento_id']];
        $c=$this->r->uno('SELECT concepto_id,evento_id FROM erp_inv_ajuste_conceptos WHERE movimiento_id=? FOR UPDATE',[$movimiento]);
        return $c?['reimputacion_id'=>0,'concepto_id'=>(int)$c['concepto_id'],'evento_id'=>(int)($c['evento_id']??0)]:null;
    }
    /**
     * Cambia la IMPUTACIÓN contable de un ajuste ya clasificado: revierte el asiento vigente y contabiliza el nuevo concepto, sin tocar el movimiento ni las existencias. Exige el concepto
     * que la persona vio (`esperado_concepto_id`): si otra persona cambió la imputación entretanto, se rechaza (409) en lugar de pisarla. La cadena es única por estado.
     */
    public function reimputar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'contabilidad.configurar','inv_reimputacion',$d,function()use($actor,$d){
            $m=$this->ajusteBloqueado($d['movimiento_id']??null);$mov=(int)$m['id'];
            $v=$this->vigente($mov)??throw new ErpException('Ese ajuste aún no tiene concepto: clasifícalo primero.',409);
            if((int)($d['esperado_concepto_id']??0)!==$v['concepto_id'])throw new ErpException('La imputación de este ajuste cambió mientras la revisabas: recarga la pantalla.',409);
            $k=$this->conceptoParaAjuste($d['concepto_id']??null,$m);$motivo=$this->motivoObligatorio($d['motivo']??null);$this->valorAjuste($m);
            if((int)$k['id']===$v['concepto_id'])throw new ErpException('El ajuste ya está imputado a ese concepto.',422);
            $o=$this->r->uno("SELECT estado FROM erp_integraciones_outbox WHERE evento_id=? AND destino='contabilidad'",[$v['evento_id']]);
            if(!$o||$o['estado']!=='confirmado')throw new ErpException('La imputación vigente aún no está contabilizada: completa su configuración antes de cambiarla.',409);
            $fecha=substr((string)$m['registrado_en'],0,10);
            $evento=$this->evento($actor,'inventario_reimputado','inventario',$mov,['movimiento_id'=>$mov,'producto_id'=>(int)$m['producto_id'],'tipo'=>$m['tipo'],'cantidad_milesimas'=>(int)$m['cantidad_milesimas'],'costo_unitario'=>(int)$m['costo_centavos'],'concepto_anterior_id'=>$v['concepto_id'],'concepto_id'=>(int)$k['id'],'vigente_evento_id'=>$v['evento_id'],'fecha_original'=>$fecha,'motivo'=>$motivo],['contabilidad']);
            $this->r->q('INSERT INTO erp_inv_reimputaciones(movimiento_id,anterior_id,concepto_origen_id,concepto_nuevo_id,motivo,evento_id,creado_por,creado_en) VALUES(?,?,?,?,?,?,?,?)',[$mov,$v['reimputacion_id'],$v['concepto_id'],(int)$k['id'],$motivo,$evento,$actor,$this->r::ahora()]);
            return (int)$this->r->id();
        });
    }
    /** Ajustes clasificados con su imputación vigente y la cadena de asientos (original, reverso, nueva imputación). Solo lectura. */
    public function cadenas(int $limite=30):array
    {
        $out=[];
        foreach($this->r->q('SELECT i.id,i.tipo,i.cantidad_milesimas,i.costo_centavos,i.registrado_en,c.concepto_id,c.evento_id FROM erp_inv_ajuste_conceptos c JOIN inventario_movimientos i ON i.id=c.movimiento_id ORDER BY i.id DESC LIMIT '.(int)$limite) as $f){
            $pasos=[['etiqueta'=>'Clasificación original','concepto_id'=>(int)$f['concepto_id'],'evento_id'=>(int)($f['evento_id']??0),'motivo'=>'']];
            foreach($this->r->q('SELECT concepto_nuevo_id,evento_id,motivo,creado_en FROM erp_inv_reimputaciones WHERE movimiento_id=? ORDER BY id',[(int)$f['id']]) as $x)$pasos[]=['etiqueta'=>'Reimputación','concepto_id'=>(int)$x['concepto_nuevo_id'],'evento_id'=>(int)$x['evento_id'],'motivo'=>$x['motivo']];
            foreach($pasos as &$p){
                $p['concepto']=$this->r->uno('SELECT nombre FROM erp_inv_conceptos WHERE id=?',[$p['concepto_id']])['nombre']??'—';
                // Los asientos del paso y los reversos DE esos asientos (cada reverso se muestra bajo el asiento que anula), también los hechos a mano desde el Diario («reverso:<id>»), para que la cadena quede completa.
                $pat='outbox:ERP-'.(int)$p['evento_id'].'-contabilidad#%';
                $p['asientos']=array_map(fn($a)=>$a['numero'].($a['tipo']==='reverso'?(str_starts_with((string)$a['origen'],'reverso:')?' (reverso manual)':' (reverso)'):''),
                    $this->r->q("SELECT numero,tipo,origen FROM erp_cont_asientos WHERE (origen LIKE ? AND tipo<>'reverso') OR reversa_de_id IN(SELECT id FROM erp_cont_asientos WHERE origen LIKE ? AND tipo<>'reverso') ORDER BY id",[$pat,$pat]));
            }unset($p);
            $f['pasos']=$pasos;$f['vigente']=end($pasos);$out[]=$f;
        }
        return $out;
    }

    // ---------------------------------------------------------------- lectura
    public function conceptos():array
    {
        $out=[];foreach($this->r->q('SELECT k.*,c.codigo cuenta_codigo,c.nombre cuenta_nombre FROM erp_inv_conceptos k LEFT JOIN erp_cont_cuentas c ON c.id=k.cuenta_id ORDER BY k.activo DESC,k.nombre') as $k){$k['problema']=$this->problema((int)$k['id']);$out[]=$k;}return $out;
    }
    public function pendientes():array
    {
        $out=[];
        foreach($this->r->q("SELECT o.estado,o.intentos,e.tipo,e.datos_json,e.creado_en FROM erp_integraciones_outbox o JOIN erp_eventos e ON e.id=o.evento_id WHERE o.destino='contabilidad' AND o.estado<>'confirmado' AND e.tipo IN('inventario_ajuste','inventario_reimputado') ORDER BY o.id LIMIT 100") as $f){
            $d=json_decode($f['datos_json'],true)?:[];$mov=(int)($d['movimiento_id']??0);
            $m=$this->r->uno('SELECT m.concepto_id,k.nombre FROM erp_inv_ajuste_conceptos m JOIN erp_inv_conceptos k ON k.id=m.concepto_id WHERE m.movimiento_id=?',[$mov]);
            if(!empty($d['concepto_id'])&&$f['tipo']==='inventario_reimputado')$m=$this->r->uno('SELECT id concepto_id,nombre FROM erp_inv_conceptos WHERE id=?',[(int)$d['concepto_id']]);
            $out[]=['movimiento'=>$mov,'concepto'=>$m['nombre']??'—','sentido'=>$d['tipo']??'','estado'=>$f['estado'],'intentos'=>(int)$f['intentos'],'falta'=>$m?$this->problema((int)$m['concepto_id']):'El ajuste no tiene concepto.','fecha'=>$f['creado_en']];
        }
        return $out;
    }
}

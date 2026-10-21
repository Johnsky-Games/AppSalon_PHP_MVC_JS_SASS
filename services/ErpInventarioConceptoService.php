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
            $this->r->q("UPDATE erp_integraciones_outbox o JOIN erp_eventos e ON e.id=o.evento_id JOIN erp_inv_ajuste_conceptos m ON m.movimiento_id=CAST(JSON_UNQUOTE(JSON_EXTRACT(e.datos_json,'\$.movimiento_id')) AS UNSIGNED) SET o.proximo_intento=NULL,o.estado='pendiente' WHERE o.destino='contabilidad' AND o.estado='fallido' AND e.tipo='inventario_ajuste' AND m.concepto_id=?",[(int)$k['id']]);
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

    // ---------------------------------------------------------------- lectura
    public function conceptos():array
    {
        $out=[];foreach($this->r->q('SELECT k.*,c.codigo cuenta_codigo,c.nombre cuenta_nombre FROM erp_inv_conceptos k LEFT JOIN erp_cont_cuentas c ON c.id=k.cuenta_id ORDER BY k.activo DESC,k.nombre') as $k){$k['problema']=$this->problema((int)$k['id']);$out[]=$k;}return $out;
    }
    public function pendientes():array
    {
        $out=[];
        foreach($this->r->q("SELECT o.estado,o.intentos,e.datos_json,e.creado_en FROM erp_integraciones_outbox o JOIN erp_eventos e ON e.id=o.evento_id WHERE o.destino='contabilidad' AND o.estado<>'confirmado' AND e.tipo='inventario_ajuste' ORDER BY o.id LIMIT 100") as $f){
            $d=json_decode($f['datos_json'],true)?:[];$mov=(int)($d['movimiento_id']??0);
            $m=$this->r->uno('SELECT m.concepto_id,k.nombre FROM erp_inv_ajuste_conceptos m JOIN erp_inv_conceptos k ON k.id=m.concepto_id WHERE m.movimiento_id=?',[$mov]);
            $out[]=['movimiento'=>$mov,'concepto'=>$m['nombre']??'—','sentido'=>$d['tipo']??'','estado'=>$f['estado'],'intentos'=>(int)$f['intentos'],'falta'=>$m?$this->problema((int)$m['concepto_id']):'El ajuste no tiene concepto.','fecha'=>$f['creado_en']];
        }
        return $out;
    }
}

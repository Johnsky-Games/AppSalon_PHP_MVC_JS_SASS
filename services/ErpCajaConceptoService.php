<?php
namespace Services;

/**
 * Conceptos de caja (migración 054). Un movimiento manual de efectivo no es por sí mismo ingreso ni gasto: se clasifica con un concepto que el negocio crea y
 * asocia a una cuenta de su plan contable. No se siembran conceptos ni se inventan códigos de cuenta; el servicio solo valida que la cuenta elegida exista, sea
 * imputable y activa, y sea del tipo que admite la naturaleza del concepto.
 * Permisos: crear, asignar cuenta y activar/desactivar = `contabilidad.configurar`. Registrar y reversar movimientos vive en ErpCajaService.
 */
final class ErpCajaConceptoService extends ErpService
{
    /** naturaleza => [sentido del movimiento, tipos de cuenta admitidos, etiqueta] */
    public const NATURALEZAS=[
        'aporte_propietario'=>['ingreso',['patrimonio'],'Aporte del propietario'],
        'retiro_propietario'=>['egreso',['patrimonio'],'Retiro del propietario'],
        'deposito_a_banco'=>['egreso',['activo'],'Transferencia de caja a banco (depósito)'],
        'retiro_de_banco'=>['ingreso',['activo'],'Transferencia de banco a caja (retiro)'],
        'gasto'=>['egreso',['gasto','costo'],'Gasto pagado en efectivo'],
        'sobrante'=>['ingreso',['ingreso'],'Ajuste por sobrante de caja'],
        'faltante'=>['egreso',['gasto'],'Ajuste por faltante de caja'],
    ];

    /** Motivo por el que una cuenta no sirve para la naturaleza, o null si sirve. */
    private function errorCuenta(string $naturaleza,array $cuenta):?string
    {
        if((int)$cuenta['imputable']!==1||(int)$cuenta['activa']!==1)return 'La cuenta '.$cuenta['codigo'].' debe ser imputable y estar activa.';
        $admitidos=self::NATURALEZAS[$naturaleza][1];
        if(!in_array($cuenta['tipo'],$admitidos,true))return 'Un concepto de «'.self::NATURALEZAS[$naturaleza][2].'» exige una cuenta de tipo '.implode(' o ',$admitidos).'; la '.$cuenta['codigo'].' es de tipo '.$cuenta['tipo'].'.';
        if(in_array($naturaleza,['deposito_a_banco','retiro_de_banco'],true)){
            $caja=$this->r->uno("SELECT cuenta_id FROM erp_cont_roles WHERE rol='caja'");
            if($caja&&(int)$caja['cuenta_id']===(int)$cuenta['id'])return 'La cuenta de banco no puede ser la misma cuenta de caja: no sería una transferencia.';
        }
        return null;
    }
    private function cuentaPorCodigo(mixed $codigo):array
    {
        return $this->r->uno('SELECT * FROM erp_cont_cuentas WHERE codigo=? FOR SHARE',[is_string($codigo)?trim($codigo):''])??throw new ErpException('La cuenta no existe en el plan contable.',404);
    }

    public function crear(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'contabilidad.configurar','caja_concepto',$d,function()use($actor,$d){
            $nombre=$this->texto($d['nombre']??'',80);if(mb_strlen($nombre)<3)throw new ErpException('El nombre del concepto tiene al menos 3 caracteres.');
            $nat=is_string($d['naturaleza']??null)?$d['naturaleza']:'';if(!isset(self::NATURALEZAS[$nat]))throw new ErpException('Naturaleza del concepto no válida.');
            if($this->r->uno('SELECT id FROM erp_caja_conceptos WHERE nombre=? FOR UPDATE',[$nombre]))throw new ErpException('Ya existe un concepto con ese nombre.',409);
            $cuenta=null;
            if(trim((string)($d['cuenta']??''))!==''){$c=$this->cuentaPorCodigo($d['cuenta']);if($e=$this->errorCuenta($nat,$c))throw new ErpException($e);$cuenta=(int)$c['id'];}
            $this->r->q('INSERT INTO erp_caja_conceptos(nombre,naturaleza,tipo,cuenta_id,creado_por,creado_en) VALUES(?,?,?,?,?,?)',[$nombre,$nat,self::NATURALEZAS[$nat][0],$cuenta,$actor,$this->r::ahora()]);$id=$this->r->id();
            $this->evento($actor,'caja_concepto_creado','caja_concepto',$id,['nombre'=>$nombre,'naturaleza'=>$nat,'cuenta_id'=>$cuenta]);return $id;
        });
    }
    /** Asigna (o cambia) la cuenta del concepto. Los movimientos ya contabilizados no cambian: sus asientos son inmutables; los pendientes usarán la cuenta nueva. */
    public function asignarCuenta(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'contabilidad.configurar','caja_concepto_cuenta',$d,function()use($actor,$d){
            $k=$this->r->uno('SELECT * FROM erp_caja_conceptos WHERE id=? FOR UPDATE',[$this->id($d['concepto_id']??null)])??throw new ErpException('Concepto no encontrado.',404);
            $c=$this->cuentaPorCodigo($d['cuenta']??null);if($e=$this->errorCuenta($k['naturaleza'],$c))throw new ErpException($e);
            $this->r->q('UPDATE erp_caja_conceptos SET cuenta_id=?,actualizado_por=?,actualizado_en=? WHERE id=?',[(int)$c['id'],$actor,$this->r::ahora(),(int)$k['id']]);
            $this->evento($actor,'caja_concepto_cuenta','caja_concepto',(int)$k['id'],['anterior'=>$k['cuenta_id']?(int)$k['cuenta_id']:null,'nueva'=>(int)$c['id']]);
            // Reabre ya los movimientos que esperaban esta configuración (el reintento por defecto puede tardar hasta un día).
            $this->r->q("UPDATE erp_integraciones_outbox o JOIN erp_eventos e ON e.id=o.evento_id JOIN erp_caja_mov_conceptos m ON m.caja_id=CAST(JSON_UNQUOTE(JSON_EXTRACT(e.datos_json,'\$.movimiento_id')) AS UNSIGNED) SET o.proximo_intento=NULL,o.estado='pendiente' WHERE o.destino='contabilidad' AND o.estado='fallido' AND e.tipo IN('caja_movimiento','caja_movimiento_reversado') AND m.concepto_id=?",[(int)$k['id']]);
            return (int)$k['id'];
        });
    }
    public function estado(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'contabilidad.configurar','caja_concepto_estado',$d,function()use($actor,$d){
            $k=$this->r->uno('SELECT * FROM erp_caja_conceptos WHERE id=? FOR UPDATE',[$this->id($d['concepto_id']??null)])??throw new ErpException('Concepto no encontrado.',404);
            $activo=($d['activo']??'')==='1'?1:0;
            $this->r->q('UPDATE erp_caja_conceptos SET activo=?,actualizado_por=?,actualizado_en=? WHERE id=?',[$activo,$actor,$this->r::ahora(),(int)$k['id']]);
            $this->evento($actor,'caja_concepto_estado','caja_concepto',(int)$k['id'],['activo'=>$activo]);return (int)$k['id'];
        });
    }

    /** Qué falta completar para contabilizar un concepto (null = listo). Lo usan el adaptador contable y la pantalla. */
    public function problema(int $conceptoId):?string
    {
        $k=$this->r->uno('SELECT k.*,c.codigo,c.tipo cuenta_tipo,c.imputable,c.activa,c.id cid FROM erp_caja_conceptos k LEFT JOIN erp_cont_cuentas c ON c.id=k.cuenta_id WHERE k.id=?',[$conceptoId]);
        if(!$k)return 'El concepto del movimiento ya no existe.';
        if(!$k['cid'])return 'Asigna una cuenta del plan contable al concepto «'.$k['nombre'].'» (Contabilidad → Caja y conceptos).';
        $e=$this->errorCuenta($k['naturaleza'],['id'=>$k['cid'],'codigo'=>$k['codigo'],'tipo'=>$k['cuenta_tipo'],'imputable'=>$k['imputable'],'activa'=>$k['activa']]);
        return $e===null?null:'Corrige la cuenta del concepto «'.$k['nombre'].'»: '.$e;
    }
    /** Cuenta contable del concepto, o ErpException 409 con lo que debe completarse. */
    public function cuentaDe(int $conceptoId):int
    {
        if($p=$this->problema($conceptoId))throw new ErpException($p,409);
        return (int)$this->r->uno('SELECT cuenta_id FROM erp_caja_conceptos WHERE id=?',[$conceptoId])['cuenta_id'];
    }

    // ---------------------------------------------------------------- lectura
    public function conceptos():array
    {
        $out=[];foreach($this->r->q('SELECT k.*,c.codigo cuenta_codigo,c.nombre cuenta_nombre FROM erp_caja_conceptos k LEFT JOIN erp_cont_cuentas c ON c.id=k.cuenta_id ORDER BY k.activo DESC,k.nombre') as $k){$k['problema']=$this->problema((int)$k['id']);$out[]=$k;}return $out;
    }
    /**
     * Movimientos manuales de efectivo anteriores a los conceptos (054): registrados por el ERP con la clave `mov:`, sin cobro, sin devolución y sin clasificación.
     * Es lo único que se puede reclasificar; los cobros, devoluciones, prepagos y pagos de obligaciones ya tienen su propio hecho contable y nunca se ofrecen aquí.
     */
    public function historicos(int $limite=50):array
    {
        return $this->r->q("SELECT c.id,c.tipo,c.monto_centavos,c.concepto,c.registrado_en FROM caja_movimientos c JOIN erp_caja_movimientos e ON e.caja_id=c.id
            WHERE c.idempotencia LIKE 'mov:%' AND c.pago_id IS NULL AND c.devolucion_id=0 AND c.metodo='efectivo'
            AND NOT EXISTS(SELECT 1 FROM erp_caja_mov_conceptos m WHERE m.caja_id=c.id) ORDER BY c.id LIMIT ".(int)$limite);
    }
    /**
     * Reclasificación EXPLÍCITA de un movimiento histórico: una persona con `contabilidad.configurar` elige el concepto y deja el motivo; nada se reclasifica solo.
     * El movimiento de caja no se toca (sigue igual); solo se anexa su clasificación y se emite el hecho contable, que se contabiliza una sola vez en la fecha de la
     * decisión (no reabre períodos cerrados) y conserva la fecha original en su texto. Si ocurrió antes del inicio contable no genera asiento.
     */
    public function reclasificar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'contabilidad.configurar','caja_reclasificacion',$d,function()use($actor,$d){
            $id=$this->id($d['movimiento_id']??null);
            $mov=$this->r->uno('SELECT c.*,e.sesion_id FROM caja_movimientos c JOIN erp_caja_movimientos e ON e.caja_id=c.id WHERE c.id=? FOR UPDATE',[$id])??throw new ErpException('Movimiento de caja no encontrado.',404);
            if($this->r->uno('SELECT caja_id FROM erp_caja_mov_conceptos WHERE caja_id=? FOR UPDATE',[$id]))throw new ErpException('Ese movimiento ya está clasificado: la clasificación no se edita (para corregir, reversa el movimiento).',409);
            if(!str_starts_with((string)$mov['idempotencia'],'mov:')||$mov['pago_id']!==null||(int)$mov['devolucion_id']!==0||$mov['metodo']!=='efectivo')throw new ErpException('Solo se reclasifican movimientos manuales de efectivo anteriores a los conceptos; este movimiento tiene su propio hecho contable.',422);
            $k=$this->r->uno('SELECT * FROM erp_caja_conceptos WHERE id=? FOR SHARE',[$this->id($d['concepto_id']??null)])??throw new ErpException('Concepto de caja no encontrado.',404);
            if((int)$k['activo']!==1)throw new ErpException('El concepto «'.$k['nombre'].'» está desactivado.',409);
            $tipo=$mov['tipo']==='ingreso'?'ingreso':'egreso';
            if($k['tipo']!==$tipo)throw new ErpException('El concepto «'.$k['nombre'].'» es de '.$k['tipo'].': el movimiento fue un '.$tipo.' de efectivo.');
            $motivo=$this->texto($d['motivo']??'',300);if(mb_strlen($motivo)<5)throw new ErpException('Explica por qué se clasifica así el movimiento (al menos 5 caracteres).');
            $fecha=substr((string)$mov['registrado_en'],0,10);
            $evento=$this->evento($actor,'caja_movimiento','caja',(int)$mov['sesion_id'],['movimiento_id'=>$id,'tipo'=>$tipo,'monto'=>(int)$mov['monto_centavos'],'motivo'=>$motivo,'concepto_id'=>(int)$k['id'],'reclasificacion'=>1,'fecha_original'=>$fecha],['contabilidad']);
            $this->r->q('INSERT INTO erp_caja_mov_conceptos(caja_id,concepto_id,motivo,evento_id,creado_por,creado_en) VALUES(?,?,?,?,?,?)',[$id,(int)$k['id'],$motivo,$evento,$actor,$this->r::ahora()]);
            return $id;
        });
    }

    /** Movimientos manuales cuyo asiento aún no existe, con lo que falta para contabilizarlos. */
    public function pendientes():array
    {
        $out=[];
        foreach($this->r->q("SELECT o.id outbox_id,o.estado,o.intentos,e.tipo,e.datos_json,e.creado_en FROM erp_integraciones_outbox o JOIN erp_eventos e ON e.id=o.evento_id WHERE o.destino='contabilidad' AND o.estado<>'confirmado' AND e.tipo IN('caja_movimiento','caja_movimiento_reversado') ORDER BY o.id LIMIT 100") as $f){
            $d=json_decode($f['datos_json'],true)?:[];$mov=(int)($d['movimiento_id']??0);
            $m=$this->r->uno('SELECT m.concepto_id,k.nombre FROM erp_caja_mov_conceptos m JOIN erp_caja_conceptos k ON k.id=m.concepto_id WHERE m.caja_id=?',[$mov]);
            $falta=$m?$this->problema((int)$m['concepto_id']):'El movimiento no tiene concepto.';
            if($f['tipo']==='caja_movimiento_reversado'&&$falta===null)$falta='Espera la contabilización del movimiento original #'.(int)($d['reversa_de']??0).'.';
            $out[]=['movimiento'=>$mov,'tipo'=>$f['tipo'],'monto'=>(int)($d['monto']??0),'sentido'=>$d['tipo']??'','concepto'=>$m['nombre']??'—','estado'=>$f['estado'],'intentos'=>(int)$f['intentos'],'falta'=>$falta,'fecha'=>$f['creado_en']];
        }
        return $out;
    }
}

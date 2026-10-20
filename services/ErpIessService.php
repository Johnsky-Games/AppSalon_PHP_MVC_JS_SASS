<?php
namespace Services;

/**
 * Control de IESS. AppSalon no presenta avisos ni planillas en el portal del IESS: lleva el CONTROL de que se hicieron y entrega un borrador de aportes cuadrado contra la nómina.
 * No contiene plazos ni formatos oficiales de carga (no se pudieron verificar contra la fuente): cada aviso guarda la referencia que devolvió el portal.
 *   · Pendientes: contratos de empleado que empiezan (entrada) o terminan (salida) en el rango y todavía no tienen aviso registrado.
 *   · Planilla: de la corrida APROBADA del mes, el sueldo aportable, el aporte personal, el patronal y los fondos de reserva acumulados (se depositan al IESS) por colaborador; cuadra contra la obligación al IESS de la corrida.
 */
final class ErpIessService extends ErpService
{
    /** @return list<array{colaborador_id:int,nombre:string,contrato_id:int,tipo:string,fecha_evento:string}> */
    public function pendientes(string $desde,string $hasta):array
    {
        $entradas=$this->r->q("SELECT c.id colaborador_id,CONCAT(c.nombre,' ',c.apellido) nombre,k.id contrato_id,'entrada' tipo,k.inicio fecha_evento FROM erp_contratos k JOIN colaboradores c ON c.id=k.colaborador_id
            WHERE k.tipo='empleado' AND k.inicio BETWEEN ? AND ? AND NOT EXISTS(SELECT 1 FROM erp_iess_avisos a WHERE a.colaborador_id=c.id AND a.tipo='entrada' AND a.fecha_evento=k.inicio AND a.estado='registrado')",[$desde,$hasta]);
        $salidas=$this->r->q("SELECT c.id colaborador_id,CONCAT(c.nombre,' ',c.apellido) nombre,k.id contrato_id,'salida' tipo,k.fin fecha_evento FROM erp_contratos k JOIN colaboradores c ON c.id=k.colaborador_id
            WHERE k.tipo='empleado' AND k.fin IS NOT NULL AND k.fin BETWEEN ? AND ? AND NOT EXISTS(SELECT 1 FROM erp_iess_avisos a WHERE a.colaborador_id=c.id AND a.tipo='salida' AND a.fecha_evento=k.fin AND a.estado='registrado')",[$desde,$hasta]);
        $todo=array_merge($entradas,$salidas);usort($todo,fn($a,$b)=>[$a['fecha_evento'],$a['nombre'],$a['tipo']]<=>[$b['fecha_evento'],$b['nombre'],$b['tipo']]);
        return array_map(fn($x)=>['colaborador_id'=>(int)$x['colaborador_id'],'nombre'=>$x['nombre'],'contrato_id'=>(int)$x['contrato_id'],'tipo'=>$x['tipo'],'fecha_evento'=>$x['fecha_evento']],$todo);
    }

    public function listar(int $limite=200):array
    {
        return $this->r->q("SELECT a.*,CONCAT(c.nombre,' ',c.apellido) nombre FROM erp_iess_avisos a JOIN colaboradores c ON c.id=a.colaborador_id ORDER BY a.id DESC LIMIT ".max(1,min(500,$limite)));
    }

    public function registrar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'nomina.calcular','iess_aviso',$d,function()use($actor,$d){
            $contrato=$this->r->uno("SELECT k.* FROM erp_contratos k WHERE k.id=? AND k.tipo='empleado' FOR SHARE",[$this->id($d['contrato_id']??null)])??throw new ErpException('Contrato de empleado no disponible.',404);
            $tipo=(string)($d['tipo']??'');if(!in_array($tipo,['entrada','salida'],true))throw new ErpException('Indica si el aviso es de entrada o de salida.');
            $fecha=$tipo==='entrada'?$contrato['inicio']:$contrato['fin'];
            if(!$fecha)throw new ErpException('El contrato no tiene fecha final: termina primero la relación para avisar la salida.',409);
            $ref=$this->texto($d['referencia']??'',60);if(mb_strlen($ref)<3)throw new ErpException('Anota la referencia que devolvió el portal del IESS (mínimo 3 caracteres).');
            $clave=hash('sha256','iess.aviso:'.$actor.':'.$d['idem_key']);
            $previo=$this->r->uno('SELECT id FROM erp_iess_avisos WHERE clave_operacion=? FOR SHARE',[$clave]);if($previo)return (int)$previo['id'];
            if($this->r->uno("SELECT id FROM erp_iess_avisos WHERE colaborador_id=? AND tipo=? AND fecha_evento=? AND estado='registrado' FOR UPDATE",[(int)$contrato['colaborador_id'],$tipo,$fecha]))throw new ErpException('Ese aviso ya está registrado. Anúlalo si necesitas corregir la referencia.',409);
            $this->r->q('INSERT INTO erp_iess_avisos(clave_operacion,colaborador_id,contrato_id,tipo,fecha_evento,referencia,registrado_por,registrado_en) VALUES(?,?,?,?,?,?,?,?)',[$clave,(int)$contrato['colaborador_id'],(int)$contrato['id'],$tipo,$fecha,$ref,$actor,$this->r::ahora()]);
            $id=$this->r->id();$this->evento($actor,'iess_aviso_registrado','colaborador',(int)$contrato['colaborador_id'],['aviso_id'=>$id,'tipo'=>$tipo,'fecha_evento'=>$fecha,'referencia'=>$ref]);return $id;
        });
    }

    public function anular(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'nomina.calcular','iess_aviso_anular',$d,function()use($actor,$d){
            $a=$this->r->uno('SELECT * FROM erp_iess_avisos WHERE id=? FOR UPDATE',[$this->id($d['aviso_id']??null)])??throw new ErpException('Aviso no encontrado.',404);
            if($a['estado']==='anulado')return (int)$a['id'];
            $motivo=$this->texto($d['motivo']??'',300);if(mb_strlen($motivo)<5)throw new ErpException('Indica el motivo de la anulación (mínimo 5 caracteres).');
            $this->r->q("UPDATE erp_iess_avisos SET estado='anulado',anulado_por=?,anulado_en=?,motivo_anulacion=? WHERE id=?",[$actor,$this->r::ahora(),$motivo,(int)$a['id']]);
            $this->evento($actor,'iess_aviso_anulado','colaborador',(int)$a['colaborador_id'],['aviso_id'=>(int)$a['id'],'motivo'=>$motivo]);return (int)$a['id'];
        });
    }

    /**
     * Borrador de aportes del mes desde la corrida aprobada. `cuadra` compara la suma de aportes de las líneas con la obligación al IESS de la corrida.
     * @return array{disponible:bool,mensaje?:string,corrida_id?:int,filas?:list<array<string,int|string>>,total_gravado?:int,total_personal?:int,total_patronal?:int,total_fondos?:int,total?:int,obligacion?:?int,cuadra?:bool}
     */
    public function planilla(int $anio,int $mes):array
    {
        $k=$this->r->uno("SELECT * FROM erp_nomina_corridas WHERE anio=? AND mes=? AND estado='aprobada'",[$anio,$mes]);
        if(!$k)return ['disponible'=>false,'mensaje'=>'No hay una corrida aprobada de '.sprintf('%04d-%02d',$anio,$mes).'.'];
        $filas=$this->r->q("SELECT l.colaborador_id,CONCAT(c.nombre,' ',c.apellido) nombre,l.dias,l.gravado_iess_centavos gravado,l.iess_personal_centavos personal,l.iess_patronal_centavos patronal,l.fondos_acum_centavos fondos FROM erp_nomina_lineas l JOIN colaboradores c ON c.id=l.colaborador_id WHERE l.corrida_id=? AND (l.gravado_iess_centavos>0 OR l.iess_personal_centavos>0 OR l.iess_patronal_centavos>0 OR l.fondos_acum_centavos>0) ORDER BY c.apellido,c.nombre",[(int)$k['id']]);
        $filas=array_map(fn($f)=>['colaborador_id'=>(int)$f['colaborador_id'],'nombre'=>$f['nombre'],'dias'=>(int)$f['dias'],'gravado'=>(int)$f['gravado'],'personal'=>(int)$f['personal'],'patronal'=>(int)$f['patronal'],'fondos'=>(int)$f['fondos'],'total'=>(int)$f['personal']+(int)$f['patronal']+(int)$f['fondos']],$filas);
        $p=array_sum(array_column($filas,'personal'));$q=array_sum(array_column($filas,'patronal'));$fr=array_sum(array_column($filas,'fondos'));$g=array_sum(array_column($filas,'gravado'));
        $ob=$k['obligacion_iess_id']?$this->r->uno('SELECT monto_centavos FROM erp_obligaciones WHERE id=?',[(int)$k['obligacion_iess_id']]):null;
        return ['disponible'=>true,'corrida_id'=>(int)$k['id'],'filas'=>$filas,'total_gravado'=>$g,'total_personal'=>$p,'total_patronal'=>$q,'total_fondos'=>$fr,'total'=>$p+$q+$fr,'obligacion'=>$ob?(int)$ob['monto_centavos']:null,'cuadra'=>$ob?((int)$ob['monto_centavos']===$p+$q+$fr):($p+$q+$fr===0)];
    }
}

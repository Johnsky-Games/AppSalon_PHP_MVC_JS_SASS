<?php
namespace Services;

final class ErpRhService extends ErpService
{
    public function contrato(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.rh','contrato',$d,function()use($actor,$d){
            $col=$this->colaborador($this->id($d['colaborador_id']??null));$inicio=$this->fecha($d['inicio']??null);$fin=($d['fin']??'')===''?null:$this->fecha($d['fin']);
            if($fin!==null&&$fin<$inicio)throw new ErpException('La fecha final es anterior al inicio.');
            $tipo=$d['tipo']??'';if(!in_array($tipo,['empleado','prestador'],true))throw new ErpException('Tipo de relación no válido.');
            if($this->r->q("SELECT id FROM erp_contratos WHERE colaborador_id=? AND inicio<=? AND COALESCE(fin,'9999-12-31')>=? FOR SHARE",[(int)$col['id'],$fin??'9999-12-31',$inicio]))throw new ErpException('Ya hay una relación contractual en ese período.');
            $base=$this->importe($d['base']??null,true);$nota=$this->texto($d['observacion']??'',500);
            $this->r->q('INSERT INTO erp_contratos(colaborador_id,tipo,inicio,fin,base_centavos,observacion,creado_por,creado_en) VALUES(?,?,?,?,?,?,?,?)',[(int)$col['id'],$tipo,$inicio,$fin,$base,$nota,$actor,$this->r::ahora()]);
            $id=$this->r->id();$this->evento($actor,'relacion_registrada','contrato',$id,['colaborador_id'=>$col['id'],'tipo'=>$tipo,'inicio'=>$inicio,'fin'=>$fin]);return (int)$col['id'];
        });
    }
    public function jornada(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.rh','jornada',$d,function()use($actor,$d){
            $c=$this->colaborador($this->id($d['colaborador_id']??null));$dias=$d['dias']??[];
            if(!is_array($dias)||!$dias||count($dias)>7)throw new ErpException('Selecciona los días.');
            $dias=array_values(array_unique(array_map(function($dia){$id=$this->id($dia);if($id>7)throw new ErpException('Día no válido.');return $id;},$dias)));
            $inicio=$this->hora($d['inicio']??null);$fin=$this->hora($d['fin']??null);if($fin<=$inicio)throw new ErpException('La salida debe ser posterior a la entrada.');
            $antes=$this->r->q('SELECT dia_semana,inicio,fin FROM erp_jornadas WHERE colaborador_id=?',[(int)$c['id']]);
            foreach($dias as $dia)$this->r->q('INSERT INTO erp_jornadas(colaborador_id,dia_semana,inicio,fin,actualizado_por) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE inicio=VALUES(inicio),fin=VALUES(fin),actualizado_por=VALUES(actualizado_por)',[(int)$c['id'],$dia,$inicio,$fin,$actor]);
            if(($d['aplicar_agenda']??'')==='1'){
                $p=$this->r->uno('SELECT id FROM profesionales WHERE colaborador_id=?',[(int)$c['id']]);
                if(!$p||$c['rol']!=='profesional'||$c['estado']==='baja')throw new ErpException('La persona no tiene una agenda profesional configurable.');
                (new \Repositories\ProfesionalRepository($this->r->db))->aplicarGrupoSemanal((int)$p['id'],$dias,'horario',$inicio,$fin,null);
            }
            $this->evento($actor,'jornada_actualizada','colaborador',(int)$c['id'],['antes'=>$antes,'dias'=>$dias,'inicio'=>$inicio,'fin'=>$fin,'agenda'=>($d['aplicar_agenda']??'')==='1']);return (int)$c['id'];
        });
    }
    public function asistencia(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.rh','asistencia',$d,function()use($actor,$d){
            $c=$this->colaborador($this->id($d['colaborador_id']??null));$entrada=$this->momento($d['entrada']??null);$salida=$this->momento($d['salida']??null);
            $duracion=strtotime($salida)-strtotime($entrada);$descanso=filter_var($d['descanso']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>1440]]);
            if($duracion<=0||$duracion>86400||$salida>$this->r::ahora()||$descanso===false||$descanso*60>=$duracion)throw new ErpException('Revisa entrada, salida y descanso; registra horas ya realizadas de hasta 24 horas.');
            if($this->r->q('SELECT id FROM erp_asistencia WHERE colaborador_id=? AND anulado_en IS NULL AND entrada<? AND salida>? FOR SHARE',[(int)$c['id'],$salida,$entrada]))throw new ErpException('La asistencia coincide con otro registro.');
            $motivo=$this->texto($d['motivo']??'',300);
            $this->r->q('INSERT INTO erp_asistencia(colaborador_id,entrada,salida,descanso_minutos,motivo,creado_por,creado_en) VALUES(?,?,?,?,?,?,?)',[(int)$c['id'],$entrada,$salida,$descanso,$motivo,$actor,$this->r::ahora()]);
            $this->evento($actor,'asistencia_registrada','colaborador',(int)$c['id'],['registro_id'=>$this->r->id(),'entrada'=>$entrada,'salida'=>$salida,'descanso'=>$descanso,'motivo'=>$motivo]);return (int)$c['id'];
        });
    }
    public function solicitarAusencia(int $actor,array $d):array
    {
        // El colaborador puede solicitar la suya sin permiso para leer el expediente de otros.
        $auth=new AuthorizationService($this->r->db);$ctx=$auth->contexto($actor);
        if(!$ctx||$ctx['rol']==='cliente'||(int)$ctx['activo']!==1||(int)$ctx['confirmado']!==1)throw new ErpException('La cuenta no tiene acceso al equipo.',403);
        $c=$this->r->uno('SELECT id FROM colaboradores WHERE usuario_id=?',[$actor]);
        if(!$auth->permite($actor,'erp.rh')){
            if(!$c)throw new ErpException('No hay una ficha laboral vinculada.',403);
            $d['colaborador_id']=(int)$c['id'];
        }
        // Permiso propio existente de acceso: la validación de alcance se repite dentro del trabajo.
        $permiso=$auth->permite($actor,'erp.rh')?'erp.rh':($auth->permite($actor,'agenda.ver_propia')?'agenda.ver_propia':($auth->permite($actor,'agenda.gestionar')?'agenda.gestionar':'finanzas.gestionar'));
        return $this->ejecutar($actor,$permiso,'ausencia_solicitar',$d,function()use($actor,$d){
            $c=$this->colaborador($this->id($d['colaborador_id']??null));
            if(!(new AuthorizationService($this->r->db))->permite($actor,'erp.rh')&&(int)$c['usuario_id']!==$actor)throw new ErpException('Solo puedes solicitar tu propia ausencia.',403);
            $desde=$this->fecha($d['desde']??null);$hasta=$this->fecha($d['hasta']??null);if($hasta<$desde)throw new ErpException('Rango no válido.');$motivo=$this->texto($d['motivo']??'',300);
            $this->r->q('INSERT INTO erp_ausencias(colaborador_id,desde,hasta,motivo,solicitante_id,creado_en) VALUES(?,?,?,?,?,?)',[(int)$c['id'],$desde,$hasta,$motivo,$actor,$this->r::ahora()]);
            $id=$this->r->id();$this->evento($actor,'ausencia_solicitada','colaborador',(int)$c['id'],['ausencia_id'=>$id,'desde'=>$desde,'hasta'=>$hasta]);return (int)$c['id'];
        });
    }
    public function resolverAusencia(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.rh','ausencia_resolver',$d,function()use($actor,$d){
            $id=$this->id($d['ausencia_id']??null);$a=$this->r->uno('SELECT * FROM erp_ausencias WHERE id=?',[$id])??throw new ErpException('Solicitud no encontrada.');
            $c=$this->colaborador((int)$a['colaborador_id']);$p=$this->r->uno('SELECT id FROM profesionales WHERE colaborador_id=? FOR UPDATE',[(int)$c['id']]);
            $a=$this->r->uno('SELECT * FROM erp_ausencias WHERE id=? FOR UPDATE',[$id]);if($a['estado']!=='solicitada')throw new ErpException('La solicitud ya fue resuelta.',409);
            if(!in_array($d['decision']??'',['aprobar','rechazar'],true))throw new ErpException('Decisión no válida.');$aprobar=$d['decision']==='aprobar';$bloqueo=null;
            if($aprobar&&$p){
                $citas=$this->r->q("SELECT id FROM citas WHERE profesionalId=? AND fecha BETWEEN ? AND ? AND estado IN ('confirmada','en_servicio') FOR SHARE",[(int)$p['id'],$a['desde'],$a['hasta']]);
                if($citas)throw new ErpException('Reasigna o resuelve las citas de ese período antes de aprobar la ausencia.',409);
                $this->r->q('INSERT INTO bloqueos_profesionales(profesionalId,fecha_inicio,fecha_fin,motivo) VALUES(?,?,?,?)',[(int)$p['id'],$a['desde'],$a['hasta'],'Ausencia aprobada #'.$id]);$bloqueo=$this->r->id();
            }
            $this->r->q('UPDATE erp_ausencias SET estado=?,resuelto_por=?,resuelto_en=?,bloqueo_id=? WHERE id=?',[$aprobar?'aprobada':'rechazada',$actor,$this->r::ahora(),$bloqueo,$id]);
            $this->evento($actor,$aprobar?'ausencia_aprobada':'ausencia_rechazada','colaborador',(int)$c['id'],['ausencia_id'=>$id,'bloqueo_id'=>$bloqueo],['nomina']);return (int)$c['id'];
        });
    }
    public function prepararNomina(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.rh','nomina_preparar',$d,function()use($actor,$d){
            $desde=$this->fecha($d['desde']??null);$hasta=$this->fecha($d['hasta']??null);if($hasta<$desde||(new \DateTimeImmutable($desde))->diff(new \DateTimeImmutable($hasta))->days>31)throw new ErpException('Selecciona un período de hasta 31 días.');
            $asistencia=$this->r->q('SELECT * FROM erp_asistencia WHERE anulado_en IS NULL AND entrada>=? AND entrada<DATE_ADD(?,INTERVAL 1 DAY)',[$desde,$hasta]);
            $ausencias=$this->r->q("SELECT * FROM erp_ausencias WHERE estado='aprobada' AND desde<=? AND hasta>=?",[$hasta,$desde]);
            return $this->evento($actor,'novedades_preparadas','nomina',0,['desde'=>$desde,'hasta'=>$hasta,'asistencia'=>$asistencia,'ausencias'=>$ausencias],['nomina']);
        });
    }
    public function finalizarContrato(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.rh','contrato_fin',$d,function()use($actor,$d){
            $id=$this->id($d['contrato_id']??null);$prev=$this->r->uno('SELECT * FROM erp_contratos WHERE id=?',[$id])??throw new ErpException('Contrato no encontrado.');$this->colaborador((int)$prev['colaborador_id']);
            $c=$this->r->uno('SELECT * FROM erp_contratos WHERE id=? FOR UPDATE',[$id]);$fecha=$this->fecha($d['fin']??null);$motivo=$this->texto($d['motivo']??'',300);
            if($fecha<$c['inicio']||($c['fin']!==null&&$fecha>$c['fin']))throw new ErpException('La finalización debe estar dentro de la vigencia registrada.');
            $this->r->q('UPDATE erp_contratos SET fin=? WHERE id=?',[$fecha,$id]);$this->evento($actor,'contrato_finalizado','colaborador',(int)$c['colaborador_id'],['contrato_id'=>$id,'fin_anterior'=>$c['fin'],'fin'=>$fecha,'motivo'=>$motivo],['nomina']);return (int)$c['colaborador_id'];
        });
    }
    public function anularAsistencia(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.rh','asistencia_anular',$d,function()use($actor,$d){
            $id=$this->id($d['asistencia_id']??null);$a=$this->r->uno('SELECT * FROM erp_asistencia WHERE id=?',[$id])??throw new ErpException('Registro no encontrado.');$this->colaborador((int)$a['colaborador_id']);
            $a=$this->r->uno('SELECT * FROM erp_asistencia WHERE id=? FOR UPDATE',[$id]);if($a['anulado_en'])throw new ErpException('El registro ya está anulado.');$motivo=$this->texto($d['motivo']??'',300);
            $this->r->q('UPDATE erp_asistencia SET anulado_en=? WHERE id=?',[$this->r::ahora(),$id]);$this->evento($actor,'asistencia_anulada','colaborador',(int)$a['colaborador_id'],['registro'=>$a,'motivo'=>$motivo],['nomina']);return (int)$a['colaborador_id'];
        });
    }
    public function anularAusencia(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.rh','ausencia_anular',$d,function()use($actor,$d){
            $id=$this->id($d['ausencia_id']??null);$a=$this->r->uno('SELECT * FROM erp_ausencias WHERE id=?',[$id])??throw new ErpException('Solicitud no encontrada.');$c=$this->colaborador((int)$a['colaborador_id']);$this->r->uno('SELECT id FROM profesionales WHERE colaborador_id=? FOR UPDATE',[(int)$c['id']]);
            $a=$this->r->uno('SELECT * FROM erp_ausencias WHERE id=? FOR UPDATE',[$id]);if(!in_array($a['estado'],['solicitada','aprobada'],true))throw new ErpException('La ausencia ya está cerrada.');$motivo=$this->texto($d['motivo']??'',300);
            if($a['bloqueo_id'])$this->r->q('DELETE FROM bloqueos_profesionales WHERE id=?',[(int)$a['bloqueo_id']]);
            $this->r->q("UPDATE erp_ausencias SET estado='anulada',resuelto_por=?,resuelto_en=? WHERE id=?",[$actor,$this->r::ahora(),$id]);$this->evento($actor,'ausencia_anulada','colaborador',(int)$c['id'],['ausencia'=>$a,'motivo'=>$motivo],['nomina']);return (int)$c['id'];
        });
    }
    private function colaborador(int $id):array
    {
        $c=$this->r->uno('SELECT * FROM colaboradores WHERE id=? FOR UPDATE',[$id]);if(!$c||$c['estado']==='baja')throw new ErpException('Colaborador no disponible.');return $c;
    }
    private function hora(mixed $v):string
    {if(!is_string($v)||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$v))throw new ErpException('Hora no válida.');return $v.':00';}
    private function momento(mixed $v):string
    {
        $x=is_string($v)?\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$v,new \DateTimeZone('America/Guayaquil')):false;
        if(!$x||$x->format('Y-m-d\TH:i')!==$v)throw new ErpException('Fecha y hora no válidas.');return $x->format('Y-m-d H:i:s');
    }
}

<?php
namespace Services;
use Repositories\ErpRepository;
use Repositories\PersistenceException;

/** Selección de recursos físicos compartidos, además del profesional. Intervalos [inicio,fin). */
final class ErpRecursoAgendaService
{
    public function __construct(private ErpRepository $r){}
    private function requisitos(array $servicios,string $fecha,bool $bloquear=false):array
    {
        if(!$servicios)return [];$marcas=implode(',',array_fill(0,count($servicios),'?'));
        $filas=$this->r->q("SELECT r.* FROM erp_servicio_recursos r WHERE r.servicio_id IN ($marcas) AND r.desde<=? AND r.desde=(SELECT MAX(r2.desde) FROM erp_servicio_recursos r2 WHERE r2.servicio_id=r.servicio_id AND r2.tipo=r.tipo AND r2.desde<=?)".($bloquear?' FOR SHARE':''),[...array_map('intval',$servicios),$fecha,$fecha]);
        $req=[];foreach($filas as $f)$req[$f['tipo']]=max($req[$f['tipo']]??0,(int)$f['cantidad']);return array_filter($req);
    }
    public function disponibles(string $fecha,string $inicio,string $fin,array $servicios,?int $excluir=null,bool $bloquear=false):?array
    {
        $req=$this->requisitos($servicios,$fecha,$bloquear);if(!$req)return [];
        $tipos=array_keys($req);$marcas=implode(',',array_fill(0,count($tipos),'?'));
        $recursos=$this->r->q("SELECT * FROM erp_recursos WHERE tipo IN ($marcas) AND activo=1 ORDER BY id".($bloquear?' FOR UPDATE':''),$tipos);
        $elegidos=[];
        foreach($recursos as $recurso){
            $tipo=$recurso['tipo'];if(($req[$tipo]??0)===0)continue;
            $ocupado=$this->r->q("SELECT rr.id FROM erp_recurso_reservas rr JOIN citas c ON c.id=rr.cita_id WHERE rr.recurso_id=? AND rr.fecha=? AND rr.inicio<? AND rr.fin>? AND rr.cita_id<>? AND c.estado IN ('confirmada','en_servicio')".($bloquear?' FOR SHARE OF rr':''),[(int)$recurso['id'],$fecha,$fin,$inicio,$excluir??0]);
            $mant=$this->r->q("SELECT id FROM erp_mantenimientos WHERE recurso_id=? AND desde<=? AND hasta>=? AND estado='programado'".($bloquear?' FOR SHARE':''),[(int)$recurso['id'],$fecha,$fecha]);
            if($ocupado||$mant)continue;$elegidos[]=(int)$recurso['id'];$req[$tipo]--;
        }
        return array_sum($req)===0?$elegidos:null;
    }
    /** Se compone con la transacción de reserva/reprogramación del repositorio clásico; no abre otra. */
    public function reservar(int $cita):void
    {
        $c=$this->r->uno('SELECT * FROM citas WHERE id=?',[$cita]);if(!$c)throw new PersistenceException('Cita no encontrada al asignar recursos.');
        $servicios=array_map('intval',array_column($this->r->q('SELECT servicioId FROM citasservicios WHERE citaId=?',[$cita]),'servicioId'));
        $inicio=$c['hora_inicio']?:$c['hora'];$fin=$c['hora_fin'];
        if(!$fin){
            if(!$this->requisitos($servicios,$c['fecha']))return;
            $dur=(int)$this->r->uno('SELECT COALESCE(SUM(COALESCE(cs.duracion_minutos,s.duracion_minutos)),0) n FROM citasservicios cs JOIN servicios s ON s.id=cs.servicioId WHERE cs.citaId=?',[$cita])['n'];
            $dt=new \DateTimeImmutable($c['fecha'].' '.$inicio,new \DateTimeZone('America/Guayaquil'));$hasta=$dt->modify('+'.$dur.' minutes');
            if($dur<=0||$hasta->format('Y-m-d')!==$c['fecha'])throw new PersistenceException('El intervalo de recursos requiere una duración válida.',422);$fin=$hasta->format('H:i:s');
        }
        $ids=$this->disponibles($c['fecha'],$inicio,$fin,$servicios,$cita,true);
        if($ids===null)throw new \Repositories\RecursoAgendaException('No hay cabina o equipo disponible para ese intervalo.',409);
        $this->r->q('DELETE FROM erp_recurso_reservas WHERE cita_id=?',[$cita]);
        foreach($ids as $id)$this->r->q('INSERT INTO erp_recurso_reservas(cita_id,recurso_id,fecha,inicio,fin) VALUES(?,?,?,?,?)',[$cita,$id,$c['fecha'],$inicio,$fin]);
    }
}

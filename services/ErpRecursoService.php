<?php
namespace Services;
final class ErpRecursoService extends ErpService
{
    public function crear(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.recursos','recurso_crear',$d,function()use($actor,$d){$nombre=$this->texto($d['nombre']??'',120);$tipo=mb_strtolower($this->texto($d['tipo']??'',40));$this->r->q('INSERT INTO erp_recursos(nombre,tipo,creado_por,creado_en) VALUES(?,?,?,?)',[$nombre,$tipo,$actor,$this->r::ahora()]);$id=$this->r->id();$this->evento($actor,'recurso_creado','recurso',$id,['nombre'=>$nombre,'tipo'=>$tipo]);return $id;});
    }
    public function requisito(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.recursos','recurso_requisito',$d,function()use($actor,$d){
            $sid=$this->id($d['servicio_id']??null);$tipo=mb_strtolower($this->texto($d['tipo']??'',40));$desde=$this->fecha($d['desde']??null);$qty=filter_var($d['cantidad']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>20]]);if($qty===false)throw new ErpException('Cantidad de recursos no válida.');
            $this->r->uno('SELECT id FROM servicios WHERE id=? FOR UPDATE',[$sid])??throw new ErpException('Servicio no encontrado.');
            // Activación planificada: no cambia silenciosamente el recurso de citas que ya fueron aceptadas.
            if($this->r->q("SELECT c.id FROM citas c JOIN citasservicios cs ON cs.citaId=c.id WHERE cs.servicioId=? AND c.fecha>=? AND c.estado IN ('confirmada','en_servicio') FOR SHARE OF cs",[$sid,$desde]))throw new ErpException('Hay citas ya reservadas desde esa fecha. Resuélvelas o elige una fecha de activación posterior.',409);
            $this->r->q('INSERT INTO erp_servicio_recursos(servicio_id,tipo,cantidad,desde) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE cantidad=VALUES(cantidad)',[$sid,$tipo,$qty,$desde]);$this->evento($actor,'requisito_recurso','servicio',$sid,['tipo'=>$tipo,'cantidad'=>$qty,'desde'=>$desde]);return $sid;
        });
    }
    public function mantenimiento(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.recursos','mantenimiento',$d,function()use($actor,$d){
            $id=$this->id($d['recurso_id']??null);$this->r->uno('SELECT id FROM erp_recursos WHERE id=? FOR UPDATE',[$id])??throw new ErpException('Recurso no encontrado.');
            $desde=$this->fecha($d['desde']??null);$hasta=$this->fecha($d['hasta']??null);if($hasta<$desde)throw new ErpException('Rango no válido.');$motivo=$this->texto($d['motivo']??'',300);
            if($this->r->q("SELECT r.id FROM erp_recurso_reservas r JOIN citas c ON c.id=r.cita_id WHERE r.recurso_id=? AND r.fecha BETWEEN ? AND ? AND c.estado IN ('confirmada','en_servicio') FOR SHARE OF r",[$id,$desde,$hasta]))throw new ErpException('El recurso tiene citas asignadas. Resuélvelas antes del mantenimiento.',409);
            $this->r->q('INSERT INTO erp_mantenimientos(recurso_id,desde,hasta,motivo,creado_por,creado_en) VALUES(?,?,?,?,?,?)',[$id,$desde,$hasta,$motivo,$actor,$this->r::ahora()]);$m=$this->r->id();$this->evento($actor,'mantenimiento_programado','recurso',$id,['mantenimiento_id'=>$m,'desde'=>$desde,'hasta'=>$hasta]);return $id;
        });
    }
    public function activo(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.recursos','activo',$d,function()use($actor,$d){
            $nombre=$this->texto($d['nombre']??'',120);$codigo=$this->texto($d['codigo']??'',60);$ref=$this->texto($d['referencia_compra']??'');$fecha=$this->fecha($d['adquirido_en']??null);$costo=$this->importe($d['costo']??null,true);
            $recurso=empty($d['recurso_id'])?null:$this->id($d['recurso_id']);$responsable=empty($d['responsable_id'])?null:$this->id($d['responsable_id']);
            $this->r->q('INSERT INTO erp_activos(recurso_id,nombre,codigo,referencia_compra,costo_centavos,adquirido_en,responsable_id,creado_por,creado_en) VALUES(?,?,?,?,?,?,?,?,?)',[$recurso,$nombre,$codigo,$ref,$costo,$fecha,$responsable,$actor,$this->r::ahora()]);$id=$this->r->id();$this->evento($actor,'activo_registrado','activo',$id,['codigo'=>$codigo,'costo'=>$costo],['contabilidad']);return $id;
        });
    }
    public function completarMantenimiento(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.recursos','mantenimiento_completar',$d,function()use($actor,$d){
            $id=$this->id($d['mantenimiento_id']??null);$m=$this->r->uno('SELECT * FROM erp_mantenimientos WHERE id=?',[$id])??throw new ErpException('Mantenimiento no encontrado.');
            $this->r->uno('SELECT id FROM erp_recursos WHERE id=? FOR UPDATE',[(int)$m['recurso_id']]);$m=$this->r->uno('SELECT * FROM erp_mantenimientos WHERE id=? FOR UPDATE',[$id]);$nota=$this->texto($d['motivo']??'',300);
            if($m['estado']!=='programado')throw new ErpException('El mantenimiento ya está completado.');
            $this->r->q("UPDATE erp_mantenimientos SET estado='completado',completado_en=? WHERE id=?",[$this->r::ahora(),$id]);$this->evento($actor,'mantenimiento_completado','recurso',(int)$m['recurso_id'],['mantenimiento_id'=>$id,'nota'=>$nota]);return (int)$m['recurso_id'];
        });
    }
}

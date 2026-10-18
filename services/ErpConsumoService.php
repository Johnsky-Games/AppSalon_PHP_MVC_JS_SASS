<?php
namespace Services;

/** Insumos internos: la atención consume stock; nunca crea un cobro al cliente. */
final class ErpConsumoService extends ErpService
{
    public function receta(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.catalogo','receta',$d,function()use($actor,$d){
            $sid=$this->id($d['servicio_id']??null);$pid=$this->id($d['producto_id']??null);$qty=ValoresExactos::decimal($d['cantidad']??null,3,true);
            $this->r->uno('SELECT id FROM servicios WHERE id=? FOR UPDATE',[$sid])??throw new ErpException('Servicio no encontrado.');
            $this->r->uno('SELECT id FROM inventario_productos WHERE id=?',[$pid])??throw new ErpException('Producto no encontrado.');
            if($qty===0)$this->r->q('DELETE FROM erp_recetas WHERE servicio_id=? AND producto_id=?',[$sid,$pid]);
            else $this->r->q('INSERT INTO erp_recetas(servicio_id,producto_id,cantidad_milesimas,actualizado_por) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE cantidad_milesimas=VALUES(cantidad_milesimas),actualizado_por=VALUES(actualizado_por)',[$sid,$pid,$qty,$actor]);
            $this->evento($actor,'receta_actualizada','servicio',$sid,['producto_id'=>$pid,'cantidad'=>$qty]);return $sid;
        });
    }
    public function consumir(int $actor,array $d):array
    {
        $gestiona=(new AuthorizationService($this->r->db))->permite($actor,'erp.consumos');
        return $this->ejecutar($actor,$gestiona?'erp.consumos':'erp.consumos.propios','consumir',$d,function()use($actor,$d,$gestiona){
            $id=$this->id($d['cita_id']??null);$motivo=$this->texto($d['motivo']??'',300);
            $c=$this->r->uno('SELECT c.*,p.usuarioId profesional_usuario FROM citas c LEFT JOIN profesionales p ON p.id=c.profesionalId WHERE c.id=? FOR UPDATE',[$id])??throw new ErpException('Atención no encontrada.',404);
            if(!$gestiona&&(int)$c['profesional_usuario']!==$actor)throw new ErpException('Esta atención pertenece a otro profesional.',403);
            if(!in_array($c['estado'],['en_servicio','completada'],true))throw new ErpException('Inicia la atención antes de registrar sus insumos.');
            if($prev=$this->r->uno('SELECT id FROM erp_consumos WHERE cita_id=?',[$id]))return (int)$prev['id'];
            $receta=$this->r->q('SELECT r.producto_id,SUM(r.cantidad_milesimas) cantidad FROM erp_recetas r JOIN citasservicios cs ON cs.servicioId=r.servicio_id WHERE cs.citaId=? GROUP BY r.producto_id ORDER BY r.producto_id',[$id]);
            if(!$receta)throw new ErpException('Configura la receta de insumos de los servicios antes de consumir.');
            $this->r->q('INSERT INTO erp_consumos(cita_id,actor_id,motivo,creado_en) VALUES(?,?,?,?)',[$id,$actor,$motivo,$this->r::ahora()]);$consumo=$this->r->id();
            foreach($receta as $l){
                $p=$this->r->uno('SELECT * FROM inventario_productos WHERE id=? FOR UPDATE',[(int)$l['producto_id']]);
                $reservado=array_sum(array_column($this->r->q("SELECT cantidad_milesimas FROM erp_stock_reservas WHERE producto_id=? AND estado='activa' FOR SHARE",[(int)$p['id']]),'cantidad_milesimas'));
                $qty=(int)$l['cantidad'];if(!(int)$p['activo']||(int)$p['stock_milesimas']-$reservado<$qty)throw new ErpException('Stock libre insuficiente para '.$p['nombre'].'. No se consumió ningún insumo.',409);
                $saldo=(int)$p['stock_milesimas']-$qty;$clave='erp.consumo:'.$consumo.':'.$p['id'];
                $this->r->q('UPDATE inventario_productos SET stock_milesimas=? WHERE id=?',[$saldo,(int)$p['id']]);
                $this->r->q("INSERT INTO inventario_movimientos(producto_id,actor_id,tipo,cantidad_milesimas,saldo_milesimas,costo_centavos,motivo,idempotencia,huella,registrado_en) VALUES(?,?,'salida',?,?,?,?,?,?,?)",[(int)$p['id'],$actor,$qty,$saldo,(int)$p['costo_centavos'],$motivo,$clave,hash('sha256',$clave),$this->r::ahora()]);
                $this->r->q("INSERT INTO erp_stock_origenes(movimiento_id,documento_tipo,documento_id) VALUES(?,'consumo',?)",[$this->r->id(),$consumo]);
            }
            $this->evento($actor,'insumos_consumidos','consumo',$consumo,['cita_id'=>$id,'receta'=>$receta],['contabilidad']);return $consumo;
        });
    }
}

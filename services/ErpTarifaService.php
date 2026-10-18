<?php
namespace Services;
/** Composición interna con la transacción de reserva; jamás recalcula reservas históricas. */
final class ErpTarifaService extends ErpService
{
    public function serviciosActivos(array $ids):bool
    {
        if(!$ids)return true;$marcas=implode(',',array_fill(0,count($ids),'?'));
        return !$this->r->q("SELECT id FROM erp_catalogo WHERE servicio_id IN ($marcas) AND activo=0",array_map('intval',$ids));
    }
    public function registrarSnapshot(int $citaServicio,int $servicio,?string $precio):void
    {
        $a=$this->r->uno('SELECT * FROM erp_catalogo WHERE servicio_id=? FOR SHARE',[$servicio]);if(!$a)return;
        if(!(int)$a['activo'])throw new \Repositories\ReservaComercialException('El servicio ya no está disponible para nuevas reservas.',422);
        if($precio===null)return;
        $calc=ErpCalculo::linea(1000,(int)$a['precio_centavos'],0,(int)$a['impuesto_pb']);
        if(CobroCalculo::centavos($precio)!==$calc['total'])throw new \Repositories\ReservaComercialException('La tarifa cambió. Actualiza el catálogo antes de reservar.',409);
        $this->r->q('INSERT INTO erp_cita_tarifas(cita_servicio_id,precio_centavos,impuesto_pb,impuesto_centavos,total_centavos) VALUES(?,?,?,?,?)',[$citaServicio,(int)$a['precio_centavos'],(int)$a['impuesto_pb'],$calc['impuesto'],$calc['total']]);
    }
}

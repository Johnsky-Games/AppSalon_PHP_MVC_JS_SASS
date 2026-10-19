<?php
namespace Services;
use Repositories\OperacionRepository;

/** Reserva global en la misma transacción que el cobro; la clave primaria serializa referencias. */
final class ErpTransferenciaService
{
    public function __construct(private OperacionRepository $r) {}
    public function reservar(string $referencia,?int $pago,?int $carga=null):void
    {
        try {
            $this->r->consulta('INSERT INTO erp_transferencia_referencias(referencia,pago_id,carga_id) VALUES(?,?,?)','sii',[$referencia,$pago,$carga]);
        } catch(\Throwable $e) {
            if((int)$e->getCode()===1062)throw new ErpException('Esa referencia de transferencia ya está registrada en otro cobro o prepago.',409);
            throw $e;
        }
    }
    public function liberarPago(int $pago):void
    { $this->r->consulta('DELETE FROM erp_transferencia_referencias WHERE pago_id=?','i',[$pago]); }
    public function liberarCarga(int $carga):void
    { $this->r->consulta('DELETE FROM erp_transferencia_referencias WHERE carga_id=?','i',[$carga]); }
}

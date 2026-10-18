<?php
namespace Services;
use Repositories\OperacionRepository;
use Repositories\PagoRepository;
use Model\Pago;
final class FinanzasService
{
    private OperacionRepository $repo;
    public function __construct(?OperacionRepository $repo=null) { $this->repo=$repo ?? new OperacionRepository(); }
    public function registrar(int $actor,array $datos): array
    {
        $tipo=$datos['tipo'] ?? '';
        if (!in_array($tipo,['ingreso','egreso'],true) || !in_array($datos['metodo'] ?? '',['efectivo','transferencia'],true)) {
            throw new \InvalidArgumentException('Seleccione ingreso o egreso y un medio real de recepción.');
        }
        $metodo=$datos['metodo'];
        $concepto=ValoresExactos::texto($datos['concepto'] ?? null,180);
        $clave=ValoresExactos::clave($datos['idempotencia'] ?? null,$actor);
        $cita=filter_var($datos['cita_id'] ?? '',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: null;
        if (trim((string)($datos['cita_id'] ?? '')) !== '' && !$cita) { throw new \InvalidArgumentException('Cita inválida.'); }
        if ($cita && $tipo!=='ingreso') { throw new \InvalidArgumentException('El cobro de una cita debe ser un ingreso.'); }
        if ($cita) {
            // El cobro de una cita ya no es un movimiento libre: pasa por el servicio de cobro (saldo, verificación, caja,
            // comisión, estado y comprobante en una sola transacción). Se cobra TODO el saldo disponible; una transferencia
            // queda pendiente de verificación y su referencia es el concepto indicado.
            try {
                $r=(new CobroCitaService($this->repo))->cobrar($actor,['cita_id'=>$cita,'metodo'=>$metodo,'monto'=>CobroCitaService::SALDO,'referencia'=>$concepto,
                    'idem_key'=>substr(hash('sha256','finanzas:'.$clave),0,64)]);
            } catch (CobroRechazado $e) { throw new \InvalidArgumentException($e->getMessage(),0,$e); }
            return ['id'=>$r['pago_id'],'idempotente'=>$r['repetida']];
        }
        $monto=ValoresExactos::decimal($datos['monto'] ?? null);
        $huella=hash('sha256',json_encode([$tipo,$metodo,$concepto,null,$monto]));
        return $this->repo->transaccion(function() use($actor,$tipo,$metodo,$concepto,$clave,$monto,$huella) {
            $anterior=$this->repo->consulta('SELECT * FROM caja_movimientos WHERE idempotencia=? FOR UPDATE','s',[$clave])[0] ?? null;
            if ($anterior) {
                if (!hash_equals($anterior['huella'],$huella)) { throw new \InvalidArgumentException('El identificador ya pertenece a otra operación.'); }
                return ['id'=>(int)$anterior['id'],'idempotente'=>true];
            }
            $arqueo=new CajaArqueoService($this->repo);
            $sesion=$arqueo->preparar($actor,$tipo,$monto,$metodo);
            $this->repo->consulta('INSERT INTO caja_movimientos (actor_id,tipo,monto_centavos,concepto,metodo,pago_id,idempotencia,huella,registrado_en) VALUES (?,?,?,?,?,?,?,?,?)','isississs',[$actor,$tipo,$monto,$concepto,$metodo,null,$clave,$huella,OperacionRepository::ahora()]);
            $id=(int)$this->repo->db->insert_id;
            $arqueo->vincular($id,$sesion);
            return ['id'=>$id,'idempotente'=>false];
        });
    }
    /**
     * Devolución total de lo que queda de un cobro manual (compatibilidad con el formulario «Registrar devolución»). Delega en
     * CobroCitaService::devolver, que registra la devolución por importe, la caja, la comisión y el estado de la cita.
     */
    public function reembolsar(int $actor, array $datos): array
    {
        $referencia=ValoresExactos::texto($datos['referencia'] ?? null,64);
        $concepto=ValoresExactos::texto($datos['concepto'] ?? null,180);
        $clave=ValoresExactos::clave($datos['idempotencia'] ?? null,$actor);
        $pago=(new PagoRepository($this->repo->db))->findByReferencia($referencia);
        if (!$pago || $pago->proveedor!=='manual') { throw new \InvalidArgumentException('Solo se devuelve un cobro manual completado, una sola vez.'); }
        try {
            $r=(new CobroCitaService($this->repo))->devolver($actor,['pago_id'=>(int)$pago->id,'monto'=>CobroCitaService::SALDO,'motivo'=>$concepto,'idem_key'=>substr(hash('sha256','finanzas:'.$clave),0,64)]);
        } catch (CobroRechazado $e) { throw new \InvalidArgumentException($e->getMessage(),0,$e); }
        return ['id'=>$r['caja_id'],'idempotente'=>$r['repetida']];
    }

}

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
        $monto=$cita ? 0 : ValoresExactos::decimal($datos['monto'] ?? null);
        $huella=hash('sha256',json_encode([$tipo,$metodo,$concepto,$cita,$monto]));
        return $this->repo->transaccion(function() use($actor,$tipo,$metodo,$concepto,$clave,$cita,$monto,$huella) {
            $pagoRepo=new PagoRepository($this->repo->db);
            $reserva=$cita ? $pagoRepo->obtenerCitaConImporteServidor($cita,true) : null;
            $anterior=$this->repo->consulta('SELECT * FROM caja_movimientos WHERE idempotencia=? FOR UPDATE','s',[$clave])[0] ?? null;
            if ($anterior) {
                if (!hash_equals($anterior['huella'],$huella)) { throw new \InvalidArgumentException('El identificador ya pertenece a otra operación.'); }
                return ['id'=>(int)$anterior['id'],'idempotente'=>true];
            }
            $pagoId=null;
            if ($cita) {
                if (!$reserva || !in_array($reserva['estado'],['confirmada','en_servicio','completada'],true)) { throw new \InvalidArgumentException('Esta cita no admite nuevos cobros.'); }
                if ($reserva['estado_pago']==='pagado' || $pagoRepo->findCompletadoPorCitaId($cita,true) || $pagoRepo->findActivoPorCitaId($cita,true)) { throw new \InvalidArgumentException('La cita ya está cobrada o tiene un pago pendiente. Revise el historial antes de cobrar.'); }
                $monto=ValoresExactos::decimal(number_format($reserva['monto_servidor'],2,'.',''));
                $pago=new Pago(['cita_id'=>$cita,'cita_referencia_id'=>$cita,'usuario_id'=>$reserva['usuario_id'],
                    'referencia_pago'=>'MAN-'.strtoupper(bin2hex(random_bytes(12))),'proveedor'=>'manual',
                    'monto'=>$monto/100,'estado'=>'completado','metodo_pago'=>$metodo,'idempotency_key'=>'manual:'.$clave,
                    'pagado_at'=>(new \DateTimeImmutable('now',new \DateTimeZone('America/Guayaquil')))->format('Y-m-d H:i:s'),
                    'payload_proveedor'=>['registro_manual'=>true,'actor_id'=>$actor,'declaracion'=>'Recepción verificada por personal autorizado']]);
                $pagoId=$pagoRepo->create($pago);
                $pagoRepo->actualizarEstadoPagoCita($cita,'pagado');
                if ($reserva['profesional_id']) { (new ComisionService($this->repo))->devengar((int)$reserva['profesional_id'],$pagoId,$pago->referencia_pago,$monto); }
            }
            $this->repo->consulta('INSERT INTO caja_movimientos (actor_id,tipo,monto_centavos,concepto,metodo,pago_id,idempotencia,huella,registrado_en) VALUES (?,?,?,?,?,?,?,?,?)','isississs',[$actor,$tipo,$monto,$concepto,$metodo,$pagoId,$clave,$huella,OperacionRepository::ahora()]);
            return ['id'=>(int)$this->repo->db->insert_id,'idempotente'=>false];
        });
    }
    public function reembolsar(int $actor, array $datos): array
    {
        $referencia=ValoresExactos::texto($datos['referencia'] ?? null,64);
        $concepto=ValoresExactos::texto($datos['concepto'] ?? null,180);
        $clave=ValoresExactos::clave($datos['idempotencia'] ?? null,$actor);
        $huella=hash('sha256',json_encode(['reembolso',$referencia,$concepto]));
        $pagoRepo=new PagoRepository($this->repo->db);
        $citaId=$pagoRepo->obtenerCitaIdPorReferencia($referencia);
        return $this->repo->transaccion(function() use($actor,$referencia,$concepto,$clave,$huella,$pagoRepo,$citaId) {
            if ($citaId) { $pagoRepo->bloquearCitaPorId($citaId); }
            $pago=$pagoRepo->findByReferencia($referencia,true);
            $anterior=$this->repo->consulta('SELECT * FROM caja_movimientos WHERE idempotencia=? FOR UPDATE','s',[$clave])[0] ?? null;
            if ($anterior) {
                if (!hash_equals($anterior['huella'],$huella)) { throw new \InvalidArgumentException('El identificador ya pertenece a otra operación.'); }
                return ['id'=>(int)$anterior['id'],'idempotente'=>true];
            }
            if (!$pago || $pago->proveedor!=='manual' || $pago->estado!=='completado') { throw new \InvalidArgumentException('Solo se devuelve un cobro manual completado, una sola vez.'); }
            $monto=ValoresExactos::decimal(number_format($pago->monto,2,'.',''));
            $fecha=(new \DateTimeImmutable('now',new \DateTimeZone('America/Guayaquil')))->format('Y-m-d H:i:s');
            $payload=$pago->obtenerPayloadProveedor();
            $payload['devolucion_manual']=['actor_id'=>$actor,'registrado_en'=>$fecha,'concepto'=>$concepto];
            $pagoRepo->actualizarEstadoPago((int)$pago->id,'reembolsado',null,null,$payload,null,$fecha);
            (new ComisionService($this->repo))->revertir((int)$pago->id);
            if ($pago->cita_id) { $pagoRepo->actualizarEstadoPagoCita($pago->cita_id,'reembolsado'); }
            $this->repo->consulta('INSERT INTO caja_movimientos (actor_id,tipo,monto_centavos,concepto,metodo,pago_id,idempotencia,huella,registrado_en) VALUES (?,\'egreso\',?,?,?,?,?,?,?)','iississs',[$actor,$monto,$concepto,$pago->metodo_pago,(int)$pago->id,$clave,$huella,OperacionRepository::ahora()]);
            return ['id'=>(int)$this->repo->db->insert_id,'idempotente'=>false];
        });
    }

}

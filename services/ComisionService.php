<?php
namespace Services;
use Repositories\OperacionRepository;
/** Comisiones operativas: devengo por recepción manual verificada, sin nómina ni transferencias. */
final class ComisionService
{
    private OperacionRepository $repo;
    public function __construct(?OperacionRepository $repo=null) { $this->repo=$repo ?? new OperacionRepository(); }
    public static function calcular(int $baseCentavos,int $porcentajePb): int
    {
        if ($baseCentavos<0 || $porcentajePb<0 || $porcentajePb>10000 || $baseCentavos>999999999) { throw new \InvalidArgumentException('Base o porcentaje fuera de rango.'); }
        return intdiv($baseCentavos*$porcentajePb+5000,10000);
    }
    public function configurar(int $actor,array $datos): void
    {
        $prof=filter_var($datos['profesional_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $pb=ValoresExactos::decimal($datos['porcentaje'] ?? null,2,true);
        if (!$prof || $pb>10000) { throw new \InvalidArgumentException('Seleccione profesional y porcentaje entre 0 y 100.'); }
        $this->repo->transaccion(function() use($actor,$prof,$pb) {
            if (!$this->repo->consulta('SELECT id FROM profesionales WHERE id=? FOR UPDATE','i',[$prof])) { throw new \InvalidArgumentException('Profesional no encontrado.'); }
            $this->repo->consulta('INSERT INTO comisiones_config (profesional_id,porcentaje_pb,actor_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE porcentaje_pb=VALUES(porcentaje_pb),actor_id=VALUES(actor_id)','iii',[$prof,$pb,$actor]);
        });
    }
    /** Usar dentro de la transacción del cobro. La tarifa queda congelada por pago. */
    public function devengar(int $prof,int $pago,string $referencia,int $base): void
    {
        $this->repo->consulta('SELECT id FROM profesionales WHERE id=? FOR UPDATE','i',[$prof]);
        $config=$this->repo->consulta('SELECT c.*,p.nombre FROM comisiones_config c JOIN profesionales p ON p.id=c.profesional_id WHERE c.profesional_id=? FOR UPDATE','i',[$prof])[0] ?? null;
        if (!$config) { return; }
        $monto=self::calcular($base,(int)$config['porcentaje_pb']);
        if (!$monto) { return; }
        $this->repo->consulta("INSERT INTO comisiones_movimientos (profesional_id,profesional_nombre,pago_id,pago_referencia,tipo,base_centavos,porcentaje_pb,monto_centavos,registrado_en) VALUES (?,?,?,?,'devengo',?,?,?,?)",'isisiiis',[$prof,$config['nombre'],$pago,$referencia,$base,(int)$config['porcentaje_pb'],$monto,OperacionRepository::ahora()]);
    }
    /** Dentro de transacción de devolución: saldo no abonado se anula, abonado genera ajuste. */
    public function revertir(int $pago): void
    {
        $fila=$this->repo->consulta("SELECT * FROM comisiones_movimientos WHERE pago_id=? AND tipo='devengo'",'i',[$pago])[0] ?? null;
        if (!$fila) { return; }
        if ($fila['profesional_id']) { $this->repo->consulta('SELECT id FROM profesionales WHERE id=? FOR UPDATE','i',[(int)$fila['profesional_id']]); $this->repo->consulta('SELECT profesional_id FROM comisiones_config WHERE profesional_id=? FOR UPDATE','i',[(int)$fila['profesional_id']]); }
        $fila=$this->repo->consulta('SELECT * FROM comisiones_movimientos WHERE id=? FOR UPDATE','i',[(int)$fila['id']])[0];
        if ($fila['estado']==='pendiente') { $this->repo->consulta("UPDATE comisiones_movimientos SET estado='anulada' WHERE id=?",'i',[(int)$fila['id']]); return; }
        if ($fila['estado']!=='liquidada') { return; }
        $this->repo->consulta("INSERT INTO comisiones_movimientos (profesional_id,profesional_nombre,pago_id,pago_referencia,tipo,base_centavos,porcentaje_pb,monto_centavos,origen_id,registrado_en) VALUES (?,?,?,?,'reversa',?,?,?,?,?)",'isisiiiis',[$fila['profesional_id'],$fila['profesional_nombre'],$pago,$fila['pago_referencia'],(int)$fila['base_centavos'],(int)$fila['porcentaje_pb'],-(int)$fila['monto_centavos'],(int)$fila['id'],OperacionRepository::ahora()]);
    }
    /**
     * Devolución POR IMPORTE (parcial o total) de un pago, dentro de la transacción de la devolución (cita → pago → profesional).
     * La comisión se revierte en proporción a lo devuelto, con redondeo acumulado (la suma de las reversas nunca excede el devengo):
     *  · devolución total de una sola vez de un devengo aún sin liquidar → se anula (comportamiento histórico);
     *  · en cualquier otro caso se anexa una reversa negativa pendiente (se compensa con comisiones futuras o con lo no abonado).
     */
    public function revertirParcial(int $pago,int $devolucionId,int $montoPago,int $devueltoAntes,int $devueltoAhora): void
    {
        if ($montoPago<=0 || $devueltoAhora<=$devueltoAntes || $devueltoAhora>$montoPago || $devolucionId<=0) { throw new \InvalidArgumentException('Devolución fuera de rango.'); }
        $fila=$this->repo->consulta("SELECT * FROM comisiones_movimientos WHERE pago_id=? AND tipo='devengo'",'i',[$pago])[0] ?? null;
        if (!$fila) { return; }
        if ($fila['profesional_id']) { $this->repo->consulta('SELECT id FROM profesionales WHERE id=? FOR UPDATE','i',[(int)$fila['profesional_id']]); $this->repo->consulta('SELECT profesional_id FROM comisiones_config WHERE profesional_id=? FOR UPDATE','i',[(int)$fila['profesional_id']]); }
        $fila=$this->repo->consulta('SELECT * FROM comisiones_movimientos WHERE id=? FOR UPDATE','i',[(int)$fila['id']])[0];
        if ($fila['estado']==='anulada') { return; }
        if ($devueltoAntes===0 && $devueltoAhora===$montoPago && $fila['estado']==='pendiente') {
            $this->repo->consulta("UPDATE comisiones_movimientos SET estado='anulada' WHERE id=?",'i',[(int)$fila['id']]);
            return;
        }
        $acumulado=CobroCalculo::proporcional((int)$fila['monto_centavos'],$devueltoAhora,$montoPago);
        $previo=-(int)($this->repo->consulta("SELECT COALESCE(SUM(monto_centavos),0) s FROM comisiones_movimientos WHERE origen_id=? AND tipo='reversa'",'i',[(int)$fila['id']])[0]['s'] ?? 0);
        $reversa=$acumulado-$previo;
        if ($reversa<=0) { return; }
        $this->repo->consulta("INSERT INTO comisiones_movimientos (profesional_id,profesional_nombre,pago_id,pago_referencia,tipo,base_centavos,porcentaje_pb,monto_centavos,origen_id,devolucion_id,registrado_en) VALUES (?,?,?,?,'reversa',?,?,?,?,?,?)",'isisiiiiis',[$fila['profesional_id'],$fila['profesional_nombre'],$pago,$fila['pago_referencia'],$devueltoAhora-$devueltoAntes,(int)$fila['porcentaje_pb'],-$reversa,(int)$fila['id'],$devolucionId,OperacionRepository::ahora()]);
    }
    public function liquidar(int $actor,array $datos): array
    {
        $prof=filter_var($datos['profesional_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $hasta=is_string($datos['hasta'] ?? null) ? $datos['hasta'] : '';
        $fecha=\DateTimeImmutable::createFromFormat('!Y-m-d',$hasta);
        if (!$prof || !$fecha || $fecha->format('Y-m-d')!==$hasta || !in_array($datos['metodo'] ?? '',['efectivo','transferencia'],true)) { throw new \InvalidArgumentException('Profesional, fecha o medio inválido.'); }
        $metodo=$datos['metodo'];$comprobante=ValoresExactos::texto($datos['comprobante'] ?? null,180);
        $clave=ValoresExactos::clave($datos['idempotencia'] ?? null,$actor);
        $huella=hash('sha256',json_encode([$prof,$hasta,$metodo,$comprobante]));
        return $this->repo->transaccion(function() use($actor,$prof,$hasta,$metodo,$comprobante,$clave,$huella) {
            $this->repo->consulta('SELECT id FROM profesionales WHERE id=? FOR UPDATE','i',[$prof]);
            $config=$this->repo->consulta('SELECT c.*,p.nombre FROM comisiones_config c JOIN profesionales p ON p.id=c.profesional_id WHERE c.profesional_id=? FOR UPDATE','i',[$prof])[0] ?? null;
            if (!$config) { throw new \InvalidArgumentException('Configure primero la comisión del profesional.'); }
            $anterior=$this->repo->consulta('SELECT * FROM comisiones_liquidaciones WHERE idempotencia=?','s',[$clave])[0] ?? null;
            if ($anterior) { if (!hash_equals($anterior['huella'],$huella)) { throw new \InvalidArgumentException('Identificador utilizado en otra liquidación.'); } return ['id'=>(int)$anterior['id'],'idempotente'=>true]; }
            $filas=$this->repo->consulta("SELECT * FROM comisiones_movimientos WHERE profesional_id=? AND estado='pendiente' AND (registrado_en < DATE_ADD(?,INTERVAL 1 DAY) OR tipo='reversa') ORDER BY id FOR UPDATE",'is',[$prof,$hasta]);
            $total=array_sum(array_map(static fn($f)=>(int)$f['monto_centavos'],$filas));
            if ($total<=0) { throw new \InvalidArgumentException('No hay saldo positivo para liquidar. Los ajustes negativos se compensan con comisiones futuras.'); }
            $this->repo->consulta('INSERT INTO caja_movimientos (actor_id,tipo,monto_centavos,concepto,metodo,idempotencia,huella,registrado_en) VALUES (?,\'egreso\',?,?,?,?,?,?)','iisssss',[$actor,$total,mb_substr('Comisiones: '.$config['nombre'].' · '.$comprobante,0,180),$metodo,'comision:'.$clave,$huella,OperacionRepository::ahora()]);
            $caja=(int)$this->repo->db->insert_id;
            $this->repo->consulta('INSERT INTO comisiones_liquidaciones (profesional_id,profesional_nombre,actor_id,hasta_fecha,monto_centavos,metodo,comprobante,caja_id,idempotencia,huella,registrado_en) VALUES (?,?,?,?,?,?,?,?,?,?,?)','isisississs',[$prof,$config['nombre'],$actor,$hasta,$total,$metodo,$comprobante,$caja,$clave,$huella,OperacionRepository::ahora()]);
            $id=(int)$this->repo->db->insert_id;
            foreach($filas as $fila) { $this->repo->consulta("UPDATE comisiones_movimientos SET estado='liquidada',liquidacion_id=? WHERE id=?",'ii',[$id,(int)$fila['id']]); }
            return ['id'=>$id,'monto_centavos'=>$total,'idempotente'=>false];
        });
    }
    public function panel(): array
    {
        return [
            'profesionales'=>$this->repo->consulta('SELECT p.id,p.nombre,COALESCE(c.porcentaje_pb,0) porcentaje_pb FROM profesionales p LEFT JOIN comisiones_config c ON c.profesional_id=p.id ORDER BY p.nombre'),
            'saldos'=>$this->repo->consulta("SELECT profesional_nombre,profesional_id,SUM(monto_centavos) saldo FROM comisiones_movimientos WHERE estado='pendiente' GROUP BY profesional_id,profesional_nombre ORDER BY profesional_nombre"),
            'movimientos'=>$this->repo->consulta('SELECT * FROM comisiones_movimientos ORDER BY id DESC LIMIT 50'),
            'liquidaciones'=>$this->repo->consulta('SELECT * FROM comisiones_liquidaciones ORDER BY id DESC LIMIT 30')
        ];
    }
}

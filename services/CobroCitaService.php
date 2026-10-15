<?php
namespace Services;

use Classes\ConflictoIdempotenciaException;
use Classes\Idempotencia;
use Classes\OperacionEnCursoException;
use Repositories\OperacionRepository;

/**
 * Cobro desde la propia cita: pagos parciales/anticipos, efectivo con cambio, transferencias por verificar, devoluciones por
 * importe, caja, comisiones, estado financiero, auditoría y comprobante en UNA transacción.
 *
 * Garantías (ver docs/CAJA_Y_COBROS.md):
 *  · dinero en centavos enteros; el total sale de los precios HISTÓRICOS de `citasservicios`;
 *  · toda mutación bloquea la cita `FOR UPDATE` (cita → pagos → profesional → caja → comprobantes: orden único) y el pago pertenece
 *    siempre a la cita bloqueada: el `cita_id` del formulario se revalida, nunca se confía;
 *  · idempotencia persistente (`Classes\Idempotencia`): misma clave + mismos datos = mismo resultado; misma clave + otros datos = 409;
 *  · lo declarado (transferencia) no es dinero hasta que lo verifica alguien con `finanzas.gestionar` distinto de quien lo registró
 *    (salvo el propietario): no entra a caja, no devenga comisión y no marca pagado antes;
 *  · el cambio del efectivo no es ingreso: la caja registra solo el importe aplicado.
 */
final class CobroCitaService
{
    /** Roles que pueden verificar una transferencia que ellos mismos registraron (decisión de negocio documentada). */
    public const ROLES_AUTOVERIFICAN = ['propietario'];
    public const ESTADOS_COBRABLES = ['confirmada', 'en_servicio', 'completada'];
    public const MOTIVO_MIN = 3;
    public const MOTIVO_MAX = 180;
    /** Valor especial de `monto`: cobrar todo el saldo disponible / devolver todo lo que queda del pago (se resuelve DENTRO de la transacción). */
    public const SALDO = 'saldo';
    private const SCOPE = 'caja.';

    private OperacionRepository $repo;

    public function __construct(?OperacionRepository $repo = null)
    {
        $this->repo = $repo ?? new OperacionRepository();
    }

    // ------------------------------------------------------------------------------------------------ lectura

    /**
     * Foto financiera completa de una cita (null si no existe). Con $bloquear la lectura es transaccional y bloquea cita y pagos.
     * @return array<string,mixed>|null
     */
    public function detalle(int $citaId, bool $bloquear = false): ?array
    {
        $cita = $this->citaFila($citaId, $bloquear);
        return $cita ? $this->resumen($cita, $bloquear) : null;
    }

    // ------------------------------------------------------------------------------------------------ cobrar

    /**
     * @param array{cita_id:mixed,metodo:mixed,monto:mixed,recibido?:mixed,referencia?:mixed,idem_key:mixed} $datos
     * @return array{repetida:bool,pago_id:int,comprobante_id:?int,estado_pago:string,estado_verificacion:string,metodo:string,monto_centavos:int,recibido_centavos:?int,cambio_centavos:?int}
     */
    public function cobrar(int $actor, array $datos): array
    {
        $this->exigirPermiso($actor);
        $citaId = $this->idEntrada($datos['cita_id'] ?? null, 'La cita indicada no es válida.');
        $metodo = is_string($datos['metodo'] ?? null) ? $datos['metodo'] : '';
        if (!in_array($metodo, CobroCalculo::METODOS, true)) { throw new CobroRechazado('Elija efectivo o transferencia.', 422); }
        $saldoCompleto = ($datos['monto'] ?? null) === self::SALDO;
        $monto = $saldoCompleto ? null : $this->importe($datos['monto'] ?? null);
        $recibido = null; $referencia = null;
        if ($metodo === 'efectivo') {
            $texto = $datos['recibido'] ?? '';
            if ((is_string($texto) ? trim($texto) : $texto) !== '' && $texto !== null) { $recibido = $this->importe($texto); }
            if ($recibido !== null && $monto !== null) { try { CobroCalculo::cambio($recibido, $monto); } catch (\InvalidArgumentException $e) { throw new CobroRechazado($e->getMessage(), 422); } }
        } else {
            try { $referencia = CobroCalculo::referenciaNormalizada($datos['referencia'] ?? null); } catch (\InvalidArgumentException $e) { throw new CobroRechazado($e->getMessage(), 422); }
        }
        $clave = $this->claveEntrada($datos['idem_key'] ?? null);
        $contenido = ['cita' => $citaId, 'metodo' => $metodo, 'monto' => $saldoCompleto ? self::SALDO : $monto, 'recibido' => $recibido, 'referencia' => $referencia['unica'] ?? null];

        $r = $this->ejecutar('cobrar', $actor, $clave, $contenido, function () use ($actor, $citaId, $metodo, $monto, $saldoCompleto, $recibido, $referencia, $clave): array {
            $cita = $this->citaFila($citaId, true);
            if (!$cita) { throw new CobroRechazado('La cita no existe.', 404); }
            if (!in_array($cita['estado'], self::ESTADOS_COBRABLES, true)) {
                throw new CobroRechazado('Una cita cancelada o con ausencia no admite nuevos cobros. Si conserva un anticipo, regístre su devolución desde la cita.', 409);
            }
            $antes = $this->resumen($cita, true);
            if ($antes['intento_en_linea']) { throw new CobroRechazado('Hay un pago en línea de esta cita en proceso. Espere a que termine o se rechace antes de cobrar en caja.', 409); }
            if ($antes['total_centavos'] <= 0) { throw new CobroRechazado('La cita no tiene servicios con importe a cobrar.', 422); }
            if ($saldoCompleto) { $monto = $antes['disponible_centavos']; }
            if ($monto <= 0) { throw new CobroRechazado('La cita no tiene saldo disponible para cobrar.' . ($antes['pendiente_verificacion_centavos'] > 0 ? ' Hay transferencias por verificar que ya lo cubren.' : ''), 422); }
            $cambio = null;
            if ($metodo === 'efectivo') {
                $recibido ??= $monto;
                try { $cambio = CobroCalculo::cambio($recibido, $monto); } catch (\InvalidArgumentException $e) { throw new CobroRechazado($e->getMessage(), 422); }
            }
            if ($monto > $antes['disponible_centavos']) {
                $detalle = $antes['pendiente_verificacion_centavos'] > 0 ? ' (hay $' . CobroCalculo::formato($antes['pendiente_verificacion_centavos']) . ' en transferencias por verificar)' : '';
                throw new CobroRechazado('El importe $' . CobroCalculo::formato($monto) . ' supera el saldo disponible de $' . CobroCalculo::formato($antes['disponible_centavos']) . $detalle . '.', 422);
            }
            $ahora = OperacionRepository::ahora();
            $efectivo = $metodo === 'efectivo';
            $referenciaPago = 'MAN-' . strtoupper(bin2hex(random_bytes(12)));
            $payload = json_encode(['registro_manual' => true, 'actor_id' => $actor, 'via' => 'caja_cita',
                'declaracion' => $efectivo ? 'Efectivo recibido y contado por personal autorizado' : 'Transferencia declarada: pendiente de verificación'], JSON_UNESCAPED_UNICODE);
            try {
                $this->repo->consulta(
                    'INSERT INTO pagos (cita_id,cita_referencia_id,usuario_id,referencia_pago,proveedor,monto,moneda,estado,metodo_pago,idempotency_key,payload_proveedor,pagado_at,monto_recibido_centavos,cambio_centavos,referencia_transferencia,referencia_unica,estado_verificacion,registrado_por)'
                    . " VALUES (?,?,?,?,'manual',?,'USD',?,?,?,?,?,?,?,?,?,?,?)",
                    'iiisssssssiisssi',
                    [$citaId, $citaId, $cita['usuarioId'], $referenciaPago, CobroCalculo::plano($monto), $efectivo ? 'completado' : 'pendiente', $metodo, 'caja:' . hash('sha256', $actor . ':' . $clave),
                        $payload, $efectivo ? $ahora : null, $recibido, $cambio, $referencia['visible'] ?? null, $referencia['unica'] ?? null,
                        $efectivo ? 'confirmado' : 'pendiente_verificacion', $actor]
                );
            } catch (\Throwable $e) {
                if ((int)$e->getCode() === 1062 && !$efectivo) { throw new CobroRechazado('Esa referencia de transferencia ya está registrada en otro cobro. Verifique el comprobante: cada transferencia se usa una sola vez.', 409, $e); }
                throw $e;
            }
            $pagoId = (int)$this->repo->db->insert_id;
            if ($efectivo) { $this->confirmarEnCaja($actor, $cita, $pagoId, $referenciaPago, $monto, 'efectivo', $ahora); }
            $despues = $this->actualizarEstado($citaId, $cita, true);
            $this->evento($efectivo ? 'cobro_efectivo' : 'transferencia_registrada', $citaId, $pagoId, null, $actor,
                ['estado_pago' => $antes['estado'], 'saldo_centavos' => $antes['saldo_centavos'], 'cobrado_centavos' => $antes['cobrado_centavos']],
                ['estado_pago' => $despues['estado'], 'saldo_centavos' => $despues['saldo_centavos'], 'cobrado_centavos' => $despues['cobrado_centavos'], 'metodo' => $metodo,
                    'monto_centavos' => $monto, 'recibido_centavos' => $recibido, 'cambio_centavos' => $cambio, 'estado_verificacion' => $efectivo ? 'confirmado' : 'pendiente_verificacion'], $ahora);
            $comprobante = $efectivo ? (new ComprobanteService($this->repo))->emitir('cobro', $despues, $pagoId, null, $actor) : null;
            return ['recurso_tipo' => 'pago', 'recurso_id' => $pagoId, 'datos' => [
                'comprobante_id' => $comprobante['id'] ?? null, 'estado_pago' => $despues['estado'], 'estado_verificacion' => $efectivo ? 'confirmado' : 'pendiente_verificacion',
                'metodo' => $metodo, 'monto_centavos' => $monto, 'recibido_centavos' => $recibido, 'cambio_centavos' => $cambio]];
        });
        $d = $r['datos'];
        return ['repetida' => $r['repetida'], 'pago_id' => $r['recurso_id'], 'comprobante_id' => isset($d['comprobante_id']) ? (int)$d['comprobante_id'] : null,
            'estado_pago' => (string)$d['estado_pago'], 'estado_verificacion' => (string)$d['estado_verificacion'], 'metodo' => (string)$d['metodo'], 'monto_centavos' => (int)$d['monto_centavos'],
            'recibido_centavos' => isset($d['recibido_centavos']) ? (int)$d['recibido_centavos'] : null, 'cambio_centavos' => isset($d['cambio_centavos']) ? (int)$d['cambio_centavos'] : null];
    }

    // ------------------------------------------------------------------------------------------------ verificar / rechazar

    /** @return array{repetida:bool,pago_id:int,cita_id:int,comprobante_id:?int,estado_pago:string} */
    public function verificar(int $actor, array $datos): array
    {
        return $this->resolverTransferencia($actor, $datos, true);
    }

    /** @return array{repetida:bool,pago_id:int,cita_id:int,comprobante_id:?int,estado_pago:string} */
    public function rechazar(int $actor, array $datos): array
    {
        return $this->resolverTransferencia($actor, $datos, false);
    }

    private function resolverTransferencia(int $actor, array $datos, bool $confirmar): array
    {
        $this->exigirPermiso($actor);
        $pagoId = $this->idEntrada($datos['pago_id'] ?? null, 'El pago indicado no es válido.');
        $nota = $this->textoOpcional($datos['nota'] ?? null, self::MOTIVO_MAX);
        $citaDeclarada = $datos['cita_id'] ?? null;
        if (!$confirmar && ($nota === null || mb_strlen($nota) < self::MOTIVO_MIN)) { throw new CobroRechazado('Indique el motivo del rechazo (al menos ' . self::MOTIVO_MIN . ' caracteres).', 422); }
        $clave = $this->claveEntrada($datos['idem_key'] ?? null);
        $accion = $confirmar ? 'verificar' : 'rechazar';
        $r = $this->ejecutar($accion, $actor, $clave, ['pago' => $pagoId, 'accion' => $accion, 'nota' => $nota], function () use ($actor, $pagoId, $confirmar, $nota, $citaDeclarada): array {
            $citaId = $this->citaDelPago($pagoId, $citaDeclarada);
            $cita = $this->citaFila($citaId, true);
            if (!$cita) { throw new CobroRechazado('La cita del pago no existe.', 404); }
            $pago = $this->repo->consulta('SELECT * FROM pagos WHERE id=? FOR UPDATE', 'i', [$pagoId])[0] ?? null;
            if (!$pago) { throw new CobroRechazado('El pago no existe.', 404); }
            if ($pago['proveedor'] !== 'manual' || $pago['metodo_pago'] !== 'transferencia') { throw new CobroRechazado('Solo las transferencias registradas en caja se verifican.', 422); }
            if ($pago['estado_verificacion'] !== 'pendiente_verificacion' || $pago['estado'] !== 'pendiente') {
                throw new CobroRechazado($this->mensajeYaResuelta($pago), 409);
            }
            $rol = $this->rolDe($actor);
            if ($confirmar && (int)$pago['registrado_por'] === $actor && !in_array($rol, self::ROLES_AUTOVERIFICAN, true)) {
                throw new CobroRechazado('Quien registró la transferencia no puede verificarla: pida a otra persona con permiso de finanzas o al propietario que la confirme (separación de funciones).', 403);
            }
            $antes = $this->resumen($cita, true);
            $monto = CobroCalculo::centavos($pago['monto']);
            $ahora = OperacionRepository::ahora();
            if ($confirmar) {
                if ($antes['cobrado_centavos'] - $antes['devuelto_centavos'] + $monto > $antes['total_centavos']) {
                    throw new CobroRechazado('Confirmar esta transferencia superaría el total de la cita (cambió desde que se registró). Revise los cobros de la cita; si no corresponde, rechácela indicando el motivo.', 409);
                }
                $this->repo->consulta("UPDATE pagos SET estado='completado', pagado_at=?, estado_verificacion='confirmado', verificado_por=?, verificado_en=?, verificacion_nota=? WHERE id=? AND estado_verificacion='pendiente_verificacion'", 'sissi', [$ahora, $actor, $ahora, $nota, $pagoId]);
                $this->confirmarEnCaja($actor, $cita, $pagoId, (string)$pago['referencia_pago'], $monto, 'transferencia', $ahora);
            } else {
                $this->repo->consulta("UPDATE pagos SET estado='fallido', estado_verificacion='rechazado', referencia_unica=NULL, verificado_por=?, verificado_en=?, verificacion_nota=? WHERE id=? AND estado_verificacion='pendiente_verificacion'", 'issi', [$actor, $ahora, $nota, $pagoId]);
            }
            $despues = $this->actualizarEstado($citaId, $cita, true);
            $this->evento($confirmar ? 'transferencia_verificada' : 'transferencia_rechazada', $citaId, $pagoId, null, $actor,
                ['estado_pago' => $antes['estado'], 'estado_verificacion' => 'pendiente_verificacion', 'saldo_centavos' => $antes['saldo_centavos']],
                ['estado_pago' => $despues['estado'], 'estado_verificacion' => $confirmar ? 'confirmado' : 'rechazado', 'saldo_centavos' => $despues['saldo_centavos'], 'monto_centavos' => $monto,
                    'registrado_por' => (int)$pago['registrado_por'], 'autoverificada' => $confirmar && (int)$pago['registrado_por'] === $actor, 'nota' => $nota], $ahora);
            $comprobante = $confirmar ? (new ComprobanteService($this->repo))->emitir('cobro', $despues, $pagoId, null, $actor) : null;
            return ['recurso_tipo' => 'pago', 'recurso_id' => $pagoId, 'datos' => ['cita_id' => $citaId, 'comprobante_id' => $comprobante['id'] ?? null, 'estado_pago' => $despues['estado']]];
        });
        return ['repetida' => $r['repetida'], 'pago_id' => $r['recurso_id'], 'cita_id' => (int)$r['datos']['cita_id'],
            'comprobante_id' => isset($r['datos']['comprobante_id']) ? (int)$r['datos']['comprobante_id'] : null, 'estado_pago' => (string)$r['datos']['estado_pago']];
    }

    // ------------------------------------------------------------------------------------------------ devolver

    /**
     * Devolución por importe de un pago confirmado (efectivo o transferencia verificada) de la cita. Nunca más de lo cobrado.
     * @return array{repetida:bool,devolucion_id:int,caja_id:int,pago_id:int,cita_id:int,comprobante_id:?int,estado_pago:string,monto_centavos:int}
     */
    public function devolver(int $actor, array $datos): array
    {
        $this->exigirPermiso($actor);
        $pagoId = $this->idEntrada($datos['pago_id'] ?? null, 'El pago indicado no es válido.');
        $totalRestante = ($datos['monto'] ?? null) === self::SALDO;
        $monto = $totalRestante ? null : $this->importe($datos['monto'] ?? null);
        $motivo = $this->textoOpcional($datos['motivo'] ?? null, self::MOTIVO_MAX);
        $citaDeclarada = $datos['cita_id'] ?? null;
        if ($motivo === null || mb_strlen($motivo) < self::MOTIVO_MIN) { throw new CobroRechazado('Indique el motivo de la devolución (al menos ' . self::MOTIVO_MIN . ' caracteres).', 422); }
        $clave = $this->claveEntrada($datos['idem_key'] ?? null);
        $r = $this->ejecutar('devolver', $actor, $clave, ['pago' => $pagoId, 'monto' => $totalRestante ? self::SALDO : $monto, 'motivo' => $motivo], function () use ($actor, $pagoId, $monto, $totalRestante, $motivo, $citaDeclarada): array {
            $citaId = $this->citaDelPago($pagoId, $citaDeclarada);
            $cita = $this->citaFila($citaId, true);
            if (!$cita) { throw new CobroRechazado('La cita del pago no existe.', 404); }
            $pago = $this->repo->consulta('SELECT * FROM pagos WHERE id=? FOR UPDATE', 'i', [$pagoId])[0] ?? null;
            if (!$pago) { throw new CobroRechazado('El pago no existe.', 404); }
            if ($pago['proveedor'] !== 'manual') { throw new CobroRechazado('Un pago de la pasarela simulada no se devuelve desde caja: no movió dinero real.', 409); }
            if ($pago['estado'] === 'reembolsado') { throw new CobroRechazado('Este cobro ya fue devuelto por completo.', 409); }
            if ($pago['estado'] !== 'completado') { throw new CobroRechazado('Solo se devuelve un cobro confirmado. Una transferencia por verificar se rechaza, no se devuelve.', 409); }
            $antes = $this->resumen($cita, true);
            $fila = null;
            foreach ($antes['pagos'] as $p) { if ($p['id'] === $pagoId) { $fila = $p; } }
            if ($fila === null) { throw new CobroRechazado('El pago no pertenece a esa cita.', 404); }
            $devolvible = CobroCalculo::devolvible($fila['monto_centavos'], $fila['devuelto_centavos']);
            if ($totalRestante) { $monto = $devolvible; }
            if ($monto <= 0) { throw new CobroRechazado('Este cobro ya fue devuelto por completo.', 409); }
            if ($monto > $devolvible) {
                throw new CobroRechazado('No se puede devolver $' . CobroCalculo::formato($monto) . ': de este cobro de $' . CobroCalculo::formato($fila['monto_centavos']) . ' solo quedan $' . CobroCalculo::formato($devolvible) . ' por devolver.', 422);
            }
            $ahora = OperacionRepository::ahora();
            $this->repo->consulta('INSERT INTO cobros_devoluciones (pago_id,cita_id,monto_centavos,metodo,motivo,actor_id,origen,registrado_en) VALUES (?,?,?,?,?,?,\'caja\',?)', 'iiissis', [$pagoId, $citaId, $monto, $pago['metodo_pago'], $motivo, $actor, $ahora]);
            $devolucionId = (int)$this->repo->db->insert_id;
            $this->repo->consulta("INSERT INTO caja_movimientos (actor_id,tipo,monto_centavos,concepto,metodo,pago_id,devolucion_id,idempotencia,huella,registrado_en) VALUES (?,'egreso',?,?,?,?,?,?,?,?)", 'iissiisss',
                [$actor, $monto, mb_substr('Devolución de la cita #' . $citaId . ' · ' . $motivo, 0, 180), (string)$pago['metodo_pago'], $pagoId, $devolucionId, 'devolucion:' . $devolucionId, hash('sha256', json_encode(['devolucion', $devolucionId, $pagoId, $monto])), $ahora]);
            $cajaId = (int)$this->repo->db->insert_id;
            $devueltoAhora = $fila['devuelto_centavos'] + $monto;
            if ($devueltoAhora >= $fila['monto_centavos']) {
                $payload = json_decode((string)$pago['payload_proveedor'], true) ?: [];
                $payload['devolucion'] = ['actor_id' => $actor, 'registrado_en' => $ahora, 'motivo' => $motivo];
                $this->repo->consulta("UPDATE pagos SET estado='reembolsado', reembolsado_at=?, payload_proveedor=? WHERE id=?", 'ssi', [$ahora, json_encode($payload, JSON_UNESCAPED_UNICODE), $pagoId]);
            }
            (new ComisionService($this->repo))->revertirParcial($pagoId, $devolucionId, $fila['monto_centavos'], $fila['devuelto_centavos'], $devueltoAhora);
            $despues = $this->actualizarEstado($citaId, $cita, true);
            $this->evento('devolucion', $citaId, $pagoId, $devolucionId, $actor,
                ['estado_pago' => $antes['estado'], 'devuelto_centavos' => $antes['devuelto_centavos'], 'saldo_centavos' => $antes['saldo_centavos']],
                ['estado_pago' => $despues['estado'], 'devuelto_centavos' => $despues['devuelto_centavos'], 'saldo_centavos' => $despues['saldo_centavos'], 'monto_centavos' => $monto, 'motivo' => $motivo], $ahora);
            $comprobante = (new ComprobanteService($this->repo))->emitir('devolucion', $despues, $pagoId, $devolucionId, $actor);
            return ['recurso_tipo' => 'devolucion', 'recurso_id' => $devolucionId, 'datos' => ['caja_id' => $cajaId, 'pago_id' => $pagoId, 'cita_id' => $citaId, 'comprobante_id' => $comprobante['id'], 'estado_pago' => $despues['estado'], 'monto_centavos' => $monto]];
        });
        $d = $r['datos'];
        return ['repetida' => $r['repetida'], 'devolucion_id' => $r['recurso_id'], 'caja_id' => (int)$d['caja_id'], 'pago_id' => (int)$d['pago_id'], 'cita_id' => (int)$d['cita_id'],
            'comprobante_id' => isset($d['comprobante_id']) ? (int)$d['comprobante_id'] : null, 'estado_pago' => (string)$d['estado_pago'], 'monto_centavos' => (int)$d['monto_centavos']];
    }

    // ------------------------------------------------------------------------------------------------ núcleo

    /** @return array<string,mixed>|null */
    private function citaFila(int $citaId, bool $bloquear): ?array
    {
        // FOR UPDATE OF c: bloquea solo la cita (no al cliente ni al profesional, que otras operaciones usan).
        return $this->repo->consulta(
            'SELECT c.id,c.fecha,c.hora,c.hora_inicio,c.hora_fin,c.estado,c.estado_pago,c.usuarioId,c.profesionalId,u.nombre cliente_nombre,u.apellido cliente_apellido,p.nombre profesional_nombre'
            . ' FROM citas c LEFT JOIN usuarios u ON u.id=c.usuarioId LEFT JOIN profesionales p ON p.id=c.profesionalId WHERE c.id=?' . ($bloquear ? ' FOR UPDATE OF c' : ''),
            'i', [$citaId]
        )[0] ?? null;
    }

    /**
     * Resumen financiero a partir de la fila de la cita. Con $bloquear los pagos se leen con bloqueo (lectura del último dato confirmado).
     * @param array<string,mixed> $cita
     * @return array<string,mixed>
     */
    private function resumen(array $cita, bool $bloquear): array
    {
        $citaId = (int)$cita['id'];
        $servicios = $this->repo->consulta(
            'SELECT cs.id,COALESCE(cs.nombre_servicio,s.nombre) nombre,COALESCE(cs.precio_servicio,s.precio) precio FROM citasservicios cs LEFT JOIN servicios s ON s.id=cs.servicioId WHERE cs.citaId=? ORDER BY cs.id',
            'i', [$citaId]
        );
        $lineas = []; $total = 0;
        foreach ($servicios as $s) {
            $c = $s['precio'] === null ? 0 : max(0, CobroCalculo::centavos((string)$s['precio']));
            $lineas[] = ['id' => (int)$s['id'], 'nombre' => (string)($s['nombre'] ?? 'Servicio'), 'precio_centavos' => $c];
            $total += $c;
        }
        $filas = $this->repo->consulta(
            'SELECT p.*,ur.nombre reg_nombre,ur.apellido reg_apellido,uv.nombre ver_nombre,uv.apellido ver_apellido FROM pagos p LEFT JOIN usuarios ur ON ur.id=p.registrado_por LEFT JOIN usuarios uv ON uv.id=p.verificado_por'
            . ' WHERE p.cita_id=? OR p.cita_referencia_id=? ORDER BY p.id' . ($bloquear ? ' FOR UPDATE OF p' : ''),
            'ii', [$citaId, $citaId]
        );
        $devs = [];
        if ($filas) {
            $ids = array_map(static fn(array $f): int => (int)$f['id'], $filas);
            $devs = $this->repo->consulta(
                'SELECT d.*,u.nombre act_nombre,u.apellido act_apellido FROM cobros_devoluciones d LEFT JOIN usuarios u ON u.id=d.actor_id WHERE d.pago_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY d.id',
                str_repeat('i', count($ids)), $ids
            );
        }
        $porPago = [];
        foreach ($devs as $d) { $porPago[(int)$d['pago_id']] = ($porPago[(int)$d['pago_id']] ?? 0) + (int)$d['monto_centavos']; }
        $pagos = []; $cobrado = 0; $cobradoReal = 0; $cobradoSim = 0; $devuelto = 0; $pendiente = 0; $enLinea = false;
        foreach ($filas as $f) {
            $monto = CobroCalculo::centavos((string)$f['monto']);
            $manual = $f['proveedor'] === 'manual';
            $simulado = !$manual && ($f['proveedor'] === 'mock' || trim((string)$f['proveedor']) === '');
            $confirmado = in_array($f['estado'], ['completado', 'reembolsado'], true);
            $dev = $confirmado ? min($monto, max($porPago[(int)$f['id']] ?? 0, $f['estado'] === 'reembolsado' ? $monto : 0)) : 0;
            $porVerificar = $f['estado'] === 'pendiente' && $f['estado_verificacion'] === 'pendiente_verificacion';
            if ($confirmado) { $cobrado += $monto; $devuelto += $dev; if ($simulado) { $cobradoSim += $monto; } else { $cobradoReal += $monto; } }
            if ($porVerificar) { $pendiente += $monto; }
            if (!$manual && in_array($f['estado'], ['pendiente', 'procesando'], true)) { $enLinea = true; }
            $pagos[] = [
                'id' => (int)$f['id'], 'referencia' => (string)$f['referencia_pago'], 'proveedor' => (string)$f['proveedor'], 'metodo' => (string)($f['metodo_pago'] ?? ''), 'estado' => (string)$f['estado'],
                'estado_verificacion' => $f['estado_verificacion'], 'monto_centavos' => $monto, 'devuelto_centavos' => $dev, 'confirmado' => $confirmado, 'por_verificar' => $porVerificar, 'simulado' => $simulado,
                'recibido_centavos' => $f['monto_recibido_centavos'] === null ? null : (int)$f['monto_recibido_centavos'], 'cambio_centavos' => $f['cambio_centavos'] === null ? null : (int)$f['cambio_centavos'],
                'referencia_transferencia' => $f['referencia_transferencia'], 'pagado_at' => $f['pagado_at'], 'creado' => $f['created_at'],
                'registrado_por' => $f['registrado_por'] === null ? null : (int)$f['registrado_por'], 'registrado_por_nombre' => trim((string)$f['reg_nombre'] . ' ' . (string)$f['reg_apellido']),
                'verificado_por' => $f['verificado_por'] === null ? null : (int)$f['verificado_por'], 'verificado_por_nombre' => trim((string)$f['ver_nombre'] . ' ' . (string)$f['ver_apellido']),
                'verificado_en' => $f['verificado_en'], 'verificacion_nota' => $f['verificacion_nota'],
            ];
        }
        $devoluciones = [];
        foreach ($devs as $d) {
            $devoluciones[] = ['id' => (int)$d['id'], 'pago_id' => (int)$d['pago_id'], 'monto_centavos' => (int)$d['monto_centavos'], 'metodo' => (string)$d['metodo'], 'motivo' => (string)$d['motivo'],
                'actor' => trim((string)$d['act_nombre'] . ' ' . (string)$d['act_apellido']), 'registrado_en' => (string)$d['registrado_en'], 'origen' => (string)$d['origen']];
        }
        // Pagos reembolsados sin fila de devolución (pasarela simulada reembolsada): se muestran como devolución simulada, sin inventar actor.
        foreach ($pagos as $p) {
            if ($p['estado'] === 'reembolsado' && !isset($porPago[$p['id']])) {
                $devoluciones[] = ['id' => 0, 'pago_id' => $p['id'], 'monto_centavos' => $p['monto_centavos'], 'metodo' => $p['metodo'], 'motivo' => $p['simulado'] ? 'Reembolso de la pasarela simulada' : 'Devolución registrada antes de la versión por importe', 'actor' => '', 'registrado_en' => '', 'origen' => 'previa'];
            }
        }
        $saldo = CobroCalculo::saldo($total, $cobrado, $devuelto);
        $estadoAtencion = (string)$cita['estado'];
        $cobrable = in_array($estadoAtencion, self::ESTADOS_COBRABLES, true);
        $estadoAtencionCobrable = $cobrable;
        $disponible = CobroCalculo::disponible($total, $cobrado, $devuelto, $pendiente);
        return [
            'cita' => [
                'id' => $citaId, 'fecha' => (string)$cita['fecha'], 'hora' => substr((string)($cita['hora_inicio'] ?: $cita['hora']), 0, 5), 'estado' => $estadoAtencion,
                'cliente_id' => $cita['usuarioId'] === null ? null : (int)$cita['usuarioId'], 'cliente' => trim((string)$cita['cliente_nombre'] . ' ' . (string)$cita['cliente_apellido']),
                'profesional_id' => $cita['profesionalId'] === null ? null : (int)$cita['profesionalId'], 'profesional' => (string)($cita['profesional_nombre'] ?? ''),
            ],
            'estado_pago_registrado' => (string)$cita['estado_pago'],
            'servicios' => $lineas, 'pagos' => $pagos, 'devoluciones' => $devoluciones,
            'total_centavos' => $total, 'cobrado_centavos' => $cobrado, 'cobrado_real_centavos' => $cobradoReal, 'cobrado_simulado_centavos' => $cobradoSim,
            'devuelto_centavos' => $devuelto, 'pendiente_verificacion_centavos' => $pendiente, 'saldo_centavos' => $saldo, 'disponible_centavos' => $disponible,
            'estado' => CobroCalculo::estado($total, $cobrado, $devuelto, $pendiente),
            'intento_en_linea' => $enLinea, 'cobrable' => $cobrable && !$enLinea && $disponible > 0 && $total > 0,
            'anticipo_sin_resolver' => !$cobrable && ($cobrado - $devuelto) > 0,
            'anticipo_sin_resolver_centavos' => $cobrable ? 0 : max(0, $cobrado - $devuelto),
            'por_cobrar_centavos' => $estadoAtencionCobrable ? max(0, $saldo) : 0,
        ];
    }

    /**
     * Recalcula y guarda `citas.estado_pago` (única escritura de ese campo desde caja). Devuelve el resumen ya con el estado nuevo.
     * @param array<string,mixed> $cita
     * @return array<string,mixed>
     */
    private function actualizarEstado(int $citaId, array $cita, bool $bloquear): array
    {
        $r = $this->resumen($cita, $bloquear);
        $this->repo->consulta('UPDATE citas SET estado_pago=? WHERE id=?', 'si', [$r['estado'], $citaId]);
        $r['estado_pago_registrado'] = $r['estado'];
        return $r;
    }

    /** Ingreso en caja por el importe APLICADO (nunca el cambio) + devengo de comisión; ambos en la transacción del cobro. */
    private function confirmarEnCaja(int $actor, array $cita, int $pagoId, string $referenciaPago, int $monto, string $metodo, string $ahora): void
    {
        $this->repo->consulta("INSERT INTO caja_movimientos (actor_id,tipo,monto_centavos,concepto,metodo,pago_id,idempotencia,huella,registrado_en) VALUES (?,'ingreso',?,?,?,?,?,?,?)", 'iississs',
            [$actor, $monto, 'Cobro de la cita #' . (int)$cita['id'] . ' (' . $metodo . ')', $metodo, $pagoId, 'cobro:' . $pagoId, hash('sha256', json_encode(['cobro', $pagoId, $monto])), $ahora]);
        if ($cita['profesionalId']) { (new ComisionService($this->repo))->devengar((int)$cita['profesionalId'], $pagoId, $referenciaPago, $monto); }
    }

    /** @param array<string,mixed>|null $antes @param array<string,mixed>|null $despues */
    private function evento(string $tipo, int $citaId, ?int $pagoId, ?int $devolucionId, int $actor, ?array $antes, ?array $despues, string $ahora): void
    {
        $this->repo->consulta('INSERT INTO cobros_eventos (tipo,cita_id,pago_id,devolucion_id,actor_id,actor_rol,antes_json,despues_json,registrado_en) VALUES (?,?,?,?,?,?,?,?,?)', 'siiiissss',
            [$tipo, $citaId, $pagoId, $devolucionId, $actor, $this->rolDe($actor), json_encode($antes, JSON_UNESCAPED_UNICODE), json_encode($despues, JSON_UNESCAPED_UNICODE), $ahora]);
    }

    private function mensajeYaResuelta(array $pago): string
    {
        $quien = trim((string)($this->repo->consulta('SELECT CONCAT(nombre," ",apellido) n FROM usuarios WHERE id=?', 'i', [(int)$pago['verificado_por']])[0]['n'] ?? ''));
        $cuando = $pago['verificado_en'] ? ' el ' . $pago['verificado_en'] : '';
        return match ($pago['estado_verificacion']) {
            'confirmado' => 'Esta transferencia ya fue verificada' . ($quien !== '' ? ' por ' . $quien : '') . $cuando . '. Una transferencia no se verifica dos veces.',
            'rechazado' => 'Esta transferencia ya fue rechazada' . ($quien !== '' ? ' por ' . $quien : '') . $cuando . '.',
            default => 'Este cobro no está pendiente de verificación.',
        };
    }

    // ------------------------------------------------------------------------------------------------ utilidades

    /**
     * Ejecuta $alta con idempotencia persistente. Traduce los fallos a CobroRechazado (mensajes seguros).
     * @return array{repetida:bool,recurso_tipo:string,recurso_id:int,datos:array<string,mixed>}
     */
    private function ejecutar(string $operacion, int $actor, string $clave, array $contenido, callable $alta): array
    {
        try {
            return Idempotencia::ejecutar($this->repo->db, self::SCOPE . $operacion, Idempotencia::actorUsuario($actor, 'equipo'), $clave, $contenido, static fn() => $alta());
        } catch (ConflictoIdempotenciaException) {
            throw new CobroRechazado('Esta solicitud ya se había enviado con otros datos. No se cambió nada: vuelva a abrir la cita para ver su estado actual y registre la operación de nuevo.', 409);
        } catch (OperacionEnCursoException) {
            throw new CobroRechazado('La misma operación sigue procesándose. Espere unos segundos y revise la cita antes de repetirla.', 409);
        }
    }

    /** La cita bloqueada se DERIVA del pago; si el formulario declaraba otra, es una manipulación: se rechaza sin tocar nada. */
    private function citaDelPago(int $pagoId, mixed $citaDeclarada): int
    {
        $fila = $this->repo->consulta('SELECT COALESCE(cita_id,cita_referencia_id) cita FROM pagos WHERE id=?', 'i', [$pagoId])[0] ?? null;
        if (!$fila || !(int)$fila['cita']) { throw new CobroRechazado('El pago no existe.', 404); }
        if ($citaDeclarada !== null && $citaDeclarada !== '' && $this->idEntrada($citaDeclarada, 'La cita indicada no es válida.') !== (int)$fila['cita']) {
            throw new CobroRechazado('El pago no corresponde a esa cita. No se registró ningún cambio.', 422);
        }
        return (int)$fila['cita'];
    }

    private function exigirPermiso(int $actor): void
    {
        if ($actor <= 0 || !(new AuthorizationService($this->repo->db))->permite($actor, 'finanzas.gestionar')) {
            throw new CobroRechazado('Su cuenta no tiene permiso para operar la caja.', 403);
        }
    }

    private function rolDe(int $actor): string
    {
        $c = (new AuthorizationService($this->repo->db))->contexto($actor);
        return $c ? (string)$c['rol'] : '';
    }

    private function idEntrada(mixed $v, string $mensaje): int
    {
        $n = is_int($v) ? $v : (is_string($v) && preg_match('/^[1-9]\d{0,9}$/D', $v) ? (int)$v : 0);
        if ($n < 1) { throw new CobroRechazado($mensaje, 422); }
        return $n;
    }

    private function importe(mixed $v): int
    {
        try { return CobroCalculo::centavosEntrada($v); } catch (\InvalidArgumentException $e) { throw new CobroRechazado($e->getMessage(), 422); }
    }

    private function claveEntrada(mixed $v): string
    {
        if (!Idempotencia::claveValida($v)) { throw new CobroRechazado('El formulario venció o es inválido. Vuelva a abrir la cita para continuar; no se registró ningún movimiento.', 422); }
        return $v;
    }

    private function textoOpcional(mixed $v, int $max): ?string
    {
        if ($v === null) { return null; }
        if (!is_string($v)) { throw new CobroRechazado('Texto inválido.', 422); }
        $t = trim(preg_replace('/\s+/u', ' ', $v) ?? '');
        if ($t === '') { return null; }
        if (!mb_check_encoding($t, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $t)) { throw new CobroRechazado('El texto contiene caracteres no válidos.', 422); }
        if (mb_strlen($t) > $max) { throw new CobroRechazado('El texto no puede superar ' . $max . ' caracteres.', 422); }
        return $t;
    }
}

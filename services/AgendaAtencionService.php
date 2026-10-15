<?php

namespace Services;

use Classes\ReglaAtencion;
use Repositories\CitaRepository;

/**
 * Presentación de la atención para la agenda del equipo y el detalle de una cita (ronda 5).
 *
 * Decide QUÉ acciones puede ofrecer cada tarjeta según el estado, el reloj del negocio y los permisos del SERVIDOR. La vista solo
 * pinta lo que este servicio devuelve; la regla de tiempo es la misma (`Classes\ReglaAtencion`) que valida el servidor al mutar.
 *
 * `$permisos`: ['gestiona' => agenda.gestionar, 'propia' => agenda.ver_propia, 'finanzas' => finanzas.gestionar].
 */
final class AgendaAtencionService
{
    public const ESTADOS_FILTRO = ['confirmada', 'en_servicio', 'completada', 'no_asistio', 'cancelada'];

    private const ETIQUETAS_PAGO = ['pendiente' => 'Pendiente', 'parcial' => 'Parcial', 'por_verificar' => 'Por verificar', 'pagado' => 'Pagado', 'reembolsado' => 'Reembolsado'];

    private CitaRepository $citas;
    private ?\DateTimeImmutable $ahoraFijo;

    public function __construct(?CitaRepository $citas = null, ?\DateTimeImmutable $ahora = null)
    {
        $this->citas = $citas ?? new CitaRepository();
        $this->ahoraFijo = $ahora;
    }

    public function ahora(): \DateTimeImmutable
    {
        $zona = new \DateTimeZone(CitaService::TIMEZONE);
        return $this->ahoraFijo ? $this->ahoraFijo->setTimezone($zona) : new \DateTimeImmutable('now', $zona);
    }

    public static function etiquetaPago(string $estadoPago): string
    {
        return self::ETIQUETAS_PAGO[$estadoPago] ?? ucfirst(str_replace('_', ' ', $estadoPago));
    }

    /** Estado de filtro válido o null (cualquier otro valor se ignora, nunca se refleja). */
    public static function estadoFiltro(mixed $valor): ?string
    {
        return is_string($valor) && in_array($valor, self::ESTADOS_FILTRO, true) ? $valor : null;
    }

    /**
     * Citas del período con sus acciones. El profesional solo recibe su propia agenda (alcance aplicado en SQL).
     * @return array<int,array<string,mixed>>
     */
    public function listar(string $desde, string $hasta, int $actorId, array $permisos, ?string $estado = null): array
    {
        $propios = !empty($permisos['gestiona']) ? null : $actorId;
        $ahora = $this->ahora();
        $tarjetas = [];
        foreach ($this->citas->listarAgenda($desde, $hasta, $propios, $estado) as $cita) {
            if (empty($permisos['finanzas'])) { unset($cita['estado_pago']); }
            $cita['acciones'] = self::accionesDe($cita, $ahora, $permisos);
            $tarjetas[] = $cita;
        }
        return $tarjetas;
    }

    /**
     * Detalle de una cita o null si no existe o no es de la agenda de quien consulta (sin revelar cuál de las dos).
     * @return array<string,mixed>|null
     */
    public function detalle(int $id, int $actorId, array $permisos): ?array
    {
        $propios = !empty($permisos['gestiona']) ? null : $actorId;
        $cita = $this->citas->obtenerDetalleAgenda($id, $propios, !empty($permisos['finanzas']));
        if (!$cita) { return null; }
        $cita['acciones'] = self::accionesDe($cita, $this->ahora(), $permisos);
        if (!empty($permisos['finanzas'])) {
            $total = 0;
            foreach ($cita['servicios'] as $i => $servicio) {
                $centavos = self::centavos($servicio['precio_servicio'] ?? null);
                $cita['servicios'][$i]['precio_centavos'] = $centavos;
                $total += $centavos;
            }
            $cita['total_centavos'] = $total;
        }
        foreach ($cita['historial'] as $i => $evento) {
            $cita['historial'][$i]['descripcion'] = self::describirEvento($evento);
        }
        return $cita;
    }

    /** Importe en centavos enteros a partir del decimal histórico guardado (nunca float). */
    public static function centavos(mixed $precio): int
    {
        try { return ValoresExactos::decimal($precio, 2, true); } catch (\InvalidArgumentException) { return 0; }
    }

    public static function dinero(int $centavos): string
    {
        return '$' . intdiv($centavos, 100) . '.' . str_pad((string)($centavos % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Fecha y hora legibles («15/10 14:30») de un DATETIME guardado en hora del negocio; cadena vacía si no hay dato. */
    public static function fechaHora(?string $datetime): string
    {
        if (!$datetime) { return ''; }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $datetime);
        return $d ? $d->format('d/m/Y H:i') : '';
    }

    /**
     * Líneas «Etiqueta: quién · cuándo» de la atención ya registrada. Si el estado es histórico y no hay quién/cuándo (datos
     * anteriores a la trazabilidad), lo dice en lugar de inventarlo.
     * @return array<int,string>
     */
    public static function resumenAtencion(array $cita): array
    {
        $linea = static function (string $etiqueta, ?string $quien, ?string $cuando): string {
            $partes = array_filter([trim((string)$quien), self::fechaHora($cuando)], static fn($x) => $x !== '');
            return $etiqueta . ': ' . ($partes ? implode(' · ', $partes) : 'sin registro');
        };
        $estado = (string)($cita['estado'] ?? '');
        $lineas = [];
        if (in_array($estado, ['en_servicio', 'completada'], true)) {
            $lineas[] = $linea('Inició', $cita['atencion_inicio_por_nombre'] ?? null, $cita['atencion_inicio_at'] ?? null);
        }
        if ($estado === 'completada') { $lineas[] = $linea('Finalizó', $cita['atencion_fin_por_nombre'] ?? null, $cita['atencion_fin_at'] ?? null); }
        if ($estado === 'no_asistio') { $lineas[] = $linea('Ausencia registrada por', $cita['ausencia_por_nombre'] ?? null, $cita['ausencia_at'] ?? null); }
        return $lineas;
    }

    /** Frase legible de un evento de agenda (quién/cuándo lo pone la vista con actor y fecha). */
    private static function describirEvento(array $evento): string
    {
        $despues = json_decode((string)$evento['despues_json'], true) ?: [];
        if ($evento['tipo'] === 'estado') {
            return match ($despues['accion'] ?? ($despues['estado'] ?? '')) {
                'iniciar', 'en_servicio' => 'Inició la atención',
                'finalizar', 'completada' => 'Finalizó la atención',
                'ausencia', 'no_asistio' => 'Registró la ausencia',
                default => 'Cambió el estado',
            };
        }
        if ($evento['tipo'] === 'reprogramar') {
            return 'Reprogramó la cita al ' . ($despues['fecha'] ?? '—') . ' ' . substr((string)($despues['hora'] ?? ''), 0, 5);
        }
        return 'Actualizó la cita';
    }

    /**
     * Acciones de una cita en $ahora para quien tiene $permisos.
     *
     * Cada acción: clave, estado destino, texto, habilitada, motivo (si está deshabilitada), confirmar (pide confirmación), cobrar
     * (finaliza y continúa a caja), procesando (texto mientras se envía). Las de enlace financiero van en `enlaces_finanzas`.
     *
     * `reprogramar`: solo la gestión y solo mientras la cita no ha comenzado (el servidor rechaza lo contrario).
     *
     * @return array{operar:array<int,array<string,mixed>>,finanzas:array<int,array<string,string>>,reprogramar:bool}
     */
    public static function accionesDe(array $cita, \DateTimeImmutable $ahora, array $permisos): array
    {
        $estado = (string)($cita['estado'] ?? '');
        $operar = [];
        $reprogramar = false;
        if (!empty($permisos['gestiona']) || !empty($permisos['propia'])) {
            $fecha = (string)$cita['fecha'];
            $hora = (string)(($cita['hora_inicio'] ?? '') ?: ($cita['hora'] ?? '00:00:00'));
            $reprogramar = !empty($permisos['gestiona']) && $estado === 'confirmada' && $ahora < ReglaAtencion::inicioDeCita($fecha, $hora, $ahora->getTimezone());
            if ($estado === 'confirmada') {
                $inicio = ReglaAtencion::evaluarInicio($fecha, $hora, $ahora);
                $operar[] = ['clave' => 'iniciar', 'estado' => 'en_servicio', 'texto' => 'Iniciar atención', 'habilitada' => $inicio['permitido'],
                    'motivo' => $inicio['permitido'] ? '' : $inicio['mensaje'], 'confirmar' => false, 'cobrar' => false, 'procesando' => 'Iniciando…'];
                $ausencia = ReglaAtencion::evaluarAusencia($fecha, $hora, $ahora);
                $operar[] = ['clave' => 'ausencia', 'estado' => 'no_asistio', 'texto' => 'Registrar ausencia', 'habilitada' => $ausencia['permitido'],
                    'motivo' => $ausencia['permitido'] ? '' : $ausencia['mensaje'], 'confirmar' => true, 'cobrar' => false, 'procesando' => 'Registrando…'];
            } elseif ($estado === 'en_servicio') {
                $operar[] = ['clave' => 'finalizar', 'estado' => 'completada', 'texto' => 'Finalizar atención', 'habilitada' => true,
                    'motivo' => '', 'confirmar' => false, 'cobrar' => false, 'procesando' => 'Finalizando…'];
                if (!empty($permisos['gestiona']) && !empty($permisos['finanzas'])) {
                    $operar[] = ['clave' => 'finalizar_cobrar', 'estado' => 'completada', 'texto' => 'Finalizar y cobrar', 'habilitada' => true,
                        'motivo' => '', 'confirmar' => false, 'cobrar' => true, 'procesando' => 'Finalizando…'];
                }
            }
        }
        $finanzas = [];
        if (!empty($permisos['finanzas'])) {
            $pago = (string)($cita['estado_pago'] ?? 'pendiente');
            if ($estado === 'completada' && !in_array($pago, ['pagado', 'reembolsado'], true)) {
                $finanzas[] = ['clave' => 'cobrar', 'destino' => 'caja_cita', 'texto' => 'Cobrar saldo'];
            }
            // «Cobrar saldo» y «Ver pagos» llevan a la misma pantalla de la cita en caja: no se ofrecen los dos a la vez.
            if ($pago !== 'pendiente' && !$finanzas) {
                $finanzas[] = ['clave' => 'pagos', 'destino' => 'caja_cita', 'texto' => 'Ver pagos'];
            }
            if (in_array($pago, ['pagado', 'parcial', 'reembolsado'], true)) {
                $finanzas[] = ['clave' => 'comprobante', 'destino' => 'caja_comprobante', 'texto' => 'Ver comprobante'];
            }
        }
        return ['operar' => $operar, 'finanzas' => $finanzas, 'reprogramar' => $reprogramar];
    }
}

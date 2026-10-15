<?php

namespace Classes;

/**
 * Regla única de la atención de una cita (ronda 5). La usan el SERVIDOR (CitaRepository::mutarAgenda) y la VISTA (agenda y
 * detalle), de modo que lo que el botón ofrece es exactamente lo que el servidor aceptará: no hay dos copias de la regla.
 *
 * Máquina de estados:
 *   confirmada ──iniciar──▶ en_servicio ──finalizar──▶ completada
 *       └──────registrar ausencia──────▶ no_asistio          (cancelada tiene flujo propio y no se toca aquí)
 * `completada`, `no_asistio` y `cancelada` son terminales.
 *
 * Todas las comparaciones de tiempo se hacen con DateTimeImmutable en la zona del negocio (America/Guayaquil): nunca con
 * cadenas ni con la zona del proceso.
 */
final class ReglaAtencion
{
    /** El cliente puede llegar antes de su hora: se permite iniciar hasta 60 minutos antes del inicio. */
    public const ADELANTO_INICIO_MINUTOS = 60;

    /** Zona del negocio (la misma de Services\CitaService::TIMEZONE; se repite aquí para que esta regla no dependa de un servicio). */
    public const ZONA = 'America/Guayaquil';

    public const ESTADOS_TERMINALES = ['completada', 'no_asistio', 'cancelada'];

    /** estado actual => estados de atención alcanzables */
    public const TRANSICIONES = [
        'confirmada' => ['en_servicio', 'no_asistio'],
        'en_servicio' => ['completada'],
    ];

    public const ETIQUETAS = [
        'confirmada' => 'Confirmada', 'en_servicio' => 'En servicio', 'completada' => 'Atendida',
        'no_asistio' => 'No asistió', 'cancelada' => 'Cancelada',
    ];

    public static function etiqueta(string $estado): string
    {
        return self::ETIQUETAS[$estado] ?? ucfirst(str_replace('_', ' ', $estado));
    }

    public static function esTerminal(string $estado): bool
    {
        return in_array($estado, self::ESTADOS_TERMINALES, true);
    }

    public static function transicionPermitida(string $desde, string $hacia): bool
    {
        return in_array($hacia, self::TRANSICIONES[$desde] ?? [], true);
    }

    /** Acción de negocio que corresponde a una transición: iniciar | finalizar | ausencia (o null si no es una transición de atención). */
    public static function accionDeTransicion(string $hacia): ?string
    {
        return ['en_servicio' => 'iniciar', 'completada' => 'finalizar', 'no_asistio' => 'ausencia'][$hacia] ?? null;
    }

    /** Instante de inicio de la cita en la zona del negocio. */
    public static function inicioDeCita(string $fecha, string $hora, \DateTimeZone $zona): \DateTimeImmutable
    {
        $hora = substr(trim($hora), 0, 8);
        if (strlen($hora) === 5) { $hora .= ':00'; }
        $inicio = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $fecha . ' ' . $hora, $zona);
        // createFromFormat acepta 2030-02-30 y lo desborda a marzo: se exige que la fecha leída sea exactamente la recibida.
        if (!$inicio || $inicio->format('Y-m-d H:i:s') !== $fecha . ' ' . $hora) { throw new \InvalidArgumentException('Fecha u hora de cita inválida.'); }
        return $inicio;
    }

    /**
     * Evalúa si «Iniciar atención» está disponible en $ahora.
     *
     * Ventana: abierta desde (inicio − ADELANTO_INICIO_MINUTOS) inclusive y hasta el FIN DEL DÍA de la cita. Una cita de un día
     * anterior NO se inicia de forma ordinaria (decisión del usuario): si el cliente llega otro día se reprograma con las
     * validaciones habituales de disponibilidad; si el servicio ya ocurrió y faltó registrarlo, la REGULARIZAN propietario o gerente.
     *
     * @return array{permitido:bool,motivo:?string,disponible_desde:\DateTimeImmutable,cierra:\DateTimeImmutable,mensaje:string}
     *   motivo: null | 'antes_de_ventana' | 'dia_anterior'
     */
    public static function evaluarInicio(string $fecha, string $hora, \DateTimeImmutable $ahora): array
    {
        $zona = new \DateTimeZone(self::ZONA);
        $ahora = $ahora->setTimezone($zona); // un instante en UTC o en la zona del proceso se evalúa siempre en hora del negocio
        $inicio = self::inicioDeCita($fecha, $hora, $zona);
        $desde = $inicio->modify('-' . self::ADELANTO_INICIO_MINUTOS . ' minutes');
        $cierra = $inicio->setTime(0, 0)->modify('+1 day');
        if ($ahora < $desde) {
            return ['permitido' => false, 'motivo' => 'antes_de_ventana', 'disponible_desde' => $desde, 'cierra' => $cierra,
                'mensaje' => 'Disponible desde las ' . $desde->format('H:i') . ($desde->format('Y-m-d') !== $inicio->format('Y-m-d') ? ' del ' . $desde->format('d/m') : '')];
        }
        if ($ahora >= $cierra) {
            return ['permitido' => false, 'motivo' => 'dia_anterior', 'disponible_desde' => $desde, 'cierra' => $cierra,
                'mensaje' => 'La cita era del ' . $inicio->format('d/m/Y') . ': reprográmala, o regulariza la atención si el servicio sí ocurrió'];
        }
        return ['permitido' => true, 'motivo' => null, 'disponible_desde' => $desde, 'cierra' => $cierra, 'mensaje' => ''];
    }

    /**
     * ¿La cita está «pendiente de revisión»? Una cita sin resolver (confirmada o en servicio) cuyo día ya pasó. Nunca se marca sola como
     * atendida ni como ausencia: alguien con permiso decide (reprogramar, regularizar o registrar la ausencia).
     */
    public static function esPendienteRevision(string $estado, string $fecha, \DateTimeImmutable $ahora): bool
    {
        if (!in_array($estado, ['confirmada', 'en_servicio'], true)) { return false; }
        $hoy = $ahora->setTimezone(new \DateTimeZone(self::ZONA))->format('Y-m-d');
        return $fecha < $hoy;
    }

    /**
     * Evalúa si «Registrar ausencia» está disponible: solo cuando llegó la hora de la cita (antes no se puede saber que faltó).
     * @return array{permitido:bool,motivo:?string,mensaje:string}
     */
    public static function evaluarAusencia(string $fecha, string $hora, \DateTimeImmutable $ahora): array
    {
        $zona = new \DateTimeZone(self::ZONA);
        $ahora = $ahora->setTimezone($zona);
        $inicio = self::inicioDeCita($fecha, $hora, $zona);
        if ($ahora < $inicio) {
            return ['permitido' => false, 'motivo' => 'antes_de_hora', 'mensaje' => 'Disponible desde las ' . $inicio->format('H:i')];
        }
        return ['permitido' => true, 'motivo' => null, 'mensaje' => ''];
    }

    /**
     * Valida una transición completa (estado + tiempo) para el servidor.
     * @return array{ok:bool,httpCode:int,error:string,motivo?:?string}
     */
    public static function validarTransicion(string $anterior, string $nuevo, string $fecha, string $hora, \DateTimeImmutable $ahora): array
    {
        if (!self::transicionPermitida($anterior, $nuevo)) {
            return ['ok' => false, 'httpCode' => 409, 'error' => self::mensajeTransicionNoPermitida($anterior, $nuevo)];
        }
        if ($nuevo === 'en_servicio') {
            $e = self::evaluarInicio($fecha, $hora, $ahora);
            if (!$e['permitido']) {
                return ['ok' => false, 'httpCode' => 422, 'error' => $e['motivo'] === 'antes_de_ventana'
                    ? 'La atención todavía no puede iniciarse: ' . lcfirst($e['mensaje']) . '.'
                    : $e['mensaje'] . '.', 'motivo' => $e['motivo']];
            }
        } elseif ($nuevo === 'no_asistio') {
            $e = self::evaluarAusencia($fecha, $hora, $ahora);
            if (!$e['permitido']) {
                return ['ok' => false, 'httpCode' => 422, 'error' => 'La ausencia aún no se puede registrar: ' . lcfirst($e['mensaje']) . '.'];
            }
        }
        // finalizar: basta con que la atención esté en curso (se pudo iniciar hasta 60 min antes de la hora).
        return ['ok' => true, 'httpCode' => 200, 'error' => ''];
    }

    /** Mensaje concreto para una transición que el estado vigente no admite (incluye el estado actual). */
    public static function mensajeTransicionNoPermitida(string $anterior, string $nuevo): string
    {
        $actual = mb_strtolower(self::etiqueta($anterior));
        if (self::esTerminal($anterior)) {
            return 'La cita ya está «' . $actual . '» y es un registro histórico: no puede pasar a «' . mb_strtolower(self::etiqueta($nuevo)) . '».';
        }
        if ($anterior === 'confirmada' && $nuevo === 'completada') {
            return 'La cita está «confirmada»: primero hay que iniciar la atención antes de finalizarla.';
        }
        return 'La cita está «' . $actual . '» y no puede pasar a «' . mb_strtolower(self::etiqueta($nuevo)) . '».';
    }
}

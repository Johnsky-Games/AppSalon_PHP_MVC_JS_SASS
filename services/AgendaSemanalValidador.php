<?php
namespace Services;

use Model\HorarioProfesional;

/**
 * Validación servidor del horario semanal que se define en el asistente del profesional
 * (franjas de trabajo, varias por día, y descansos recurrentes dentro de ellas).
 *
 * Es una función pura: no toca la base ni superglobales, de modo que la misma regla protege la invitación
 * y la edición de la ficha. Las reglas de dominio (servicios existentes, choque con citas ya tomadas) se
 * comprueban después, dentro de la transacción, en ProfesionalRepository::reemplazarConfiguracionAgenda().
 *
 * Contrato de entrada (lo que envía el formulario):
 *   franjas[<dia 1..7>][<n>][inicio|fin]            = "HH:MM"
 *   descansos[<dia 1..7>][<n>][inicio|fin|motivo]   = "HH:MM" / texto
 * Una fila con inicio y fin vacíos se ignora (el formulario muestra filas en blanco); una fila a medias es un error.
 *
 * Límites (supuestos técnicos, ajustables aquí):
 *  - horas entre 00:00 y 23:59 como el resto del módulo de agenda (el TIME de un formulario HTML no admite 24:00);
 *  - minutos múltiplos de 5 (la hora de fin puede ser 23:59 para cerrar el día);
 *  - franja de al menos 15 minutos y descanso de al menos 5;
 *  - hasta 6 franjas y 4 descansos por día.
 */
final class AgendaSemanalValidador
{
    public const PASO_MINUTOS = 5;
    public const MIN_FRANJA_MINUTOS = 15;
    public const MIN_DESCANSO_MINUTOS = 5;
    public const MAX_FRANJAS_DIA = 6;
    public const MAX_DESCANSOS_DIA = 4;
    public const MAX_MOTIVO = 120;
    public const FIN_DE_DIA = 1439;

    /**
     * @return array{franjas:array<int,array{dia:int,inicio:string,fin:string}>,
     *               descansos:array<int,array{dia:int,inicio:string,fin:string,motivo:?string}>,
     *               errores:string[]}
     */
    public static function normalizar(mixed $franjasRaw, mixed $descansosRaw): array
    {
        $errores = [];
        $franjas = self::leerFilas($franjasRaw, 'franja', $errores, ['inicio', 'fin']);
        $descansos = self::leerFilas($descansosRaw, 'descanso', $errores, ['inicio', 'fin', 'motivo']);

        // --- Franjas de trabajo
        $franjasPorDia = [];
        foreach ($franjas as $f) {
            $nombre = HorarioProfesional::DIAS_SEMANA[$f['dia']];
            $r = self::horas($f['inicio'], $f['fin'], $nombre, 'franja', self::MIN_FRANJA_MINUTOS, $errores);
            if ($r === null) { continue; }
            $franjasPorDia[$f['dia']][] = $r + ['dia' => $f['dia']];
        }
        foreach ($franjasPorDia as $dia => &$lista) {
            usort($lista, static fn(array $a, array $b): int => $a['a'] <=> $b['a']);
            if (count($lista) > self::MAX_FRANJAS_DIA) {
                $errores[] = HorarioProfesional::DIAS_SEMANA[$dia] . ': máximo ' . self::MAX_FRANJAS_DIA . ' franjas por día.';
            }
            for ($i = 1, $n = count($lista); $i < $n; $i++) {
                if ($lista[$i]['a'] < $lista[$i - 1]['b']) {
                    $errores[] = HorarioProfesional::DIAS_SEMANA[$dia] . ': las franjas ' . $lista[$i - 1]['inicio'] . '-' . $lista[$i - 1]['fin']
                        . ' y ' . $lista[$i]['inicio'] . '-' . $lista[$i]['fin'] . ' se solapan.';
                }
            }
        }
        unset($lista);

        // --- Descansos recurrentes
        $descansosPorDia = [];
        foreach ($descansos as $d) {
            $nombre = HorarioProfesional::DIAS_SEMANA[$d['dia']];
            $r = self::horas($d['inicio'], $d['fin'], $nombre, 'descanso', self::MIN_DESCANSO_MINUTOS, $errores);
            if ($r === null) { continue; }
            $motivo = null;
            if (array_key_exists('motivo', $d) && $d['motivo'] !== '') {
                $m = trim(preg_replace('/\s+/u', ' ', (string)$d['motivo']) ?? '');
                if (mb_strlen($m) > self::MAX_MOTIVO || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $m)) {
                    $errores[] = "{$nombre}: el motivo del descanso admite hasta " . self::MAX_MOTIVO . ' caracteres.';
                    continue;
                }
                $motivo = $m === '' ? null : $m;
            }
            $descansosPorDia[$d['dia']][] = $r + ['dia' => $d['dia'], 'motivo' => $motivo];
        }
        foreach ($descansosPorDia as $dia => &$lista) {
            $nombre = HorarioProfesional::DIAS_SEMANA[$dia];
            usort($lista, static fn(array $a, array $b): int => $a['a'] <=> $b['a']);
            if (count($lista) > self::MAX_DESCANSOS_DIA) {
                $errores[] = "{$nombre}: máximo " . self::MAX_DESCANSOS_DIA . ' descansos por día.';
            }
            if (empty($franjasPorDia[$dia])) {
                $errores[] = "{$nombre}: hay un descanso pero no hay franja de trabajo ese día.";
                continue;
            }
            foreach ($lista as $i => $d) {
                $contenedora = null;
                foreach ($franjasPorDia[$dia] as $f) {
                    if ($d['a'] >= $f['a'] && $d['b'] <= $f['b']) { $contenedora = $f; break; }
                }
                if ($contenedora === null) {
                    $errores[] = "{$nombre}: el descanso {$d['inicio']}-{$d['fin']} debe quedar dentro de una franja de trabajo.";
                } elseif ($contenedora['a'] === $d['a'] && $contenedora['b'] === $d['b']) {
                    $errores[] = "{$nombre}: un descanso no puede cubrir toda la franja {$d['inicio']}-{$d['fin']}.";
                }
                if ($i > 0 && $d['a'] < $lista[$i - 1]['b']) {
                    $errores[] = "{$nombre}: los descansos {$lista[$i - 1]['inicio']}-{$lista[$i - 1]['fin']} y {$d['inicio']}-{$d['fin']} se solapan.";
                }
            }
        }
        unset($lista);

        $salidaFranjas = [];
        foreach ($franjasPorDia as $lista) {
            foreach ($lista as $f) { $salidaFranjas[] = ['dia' => $f['dia'], 'inicio' => $f['inicio'], 'fin' => $f['fin']]; }
        }
        usort($salidaFranjas, static fn(array $a, array $b): int => [$a['dia'], $a['inicio']] <=> [$b['dia'], $b['inicio']]);
        $salidaDescansos = [];
        foreach ($descansosPorDia as $lista) {
            foreach ($lista as $d) { $salidaDescansos[] = ['dia' => $d['dia'], 'inicio' => $d['inicio'], 'fin' => $d['fin'], 'motivo' => $d['motivo']]; }
        }
        usort($salidaDescansos, static fn(array $a, array $b): int => [$a['dia'], $a['inicio']] <=> [$b['dia'], $b['inicio']]);

        return ['franjas' => $salidaFranjas, 'descansos' => $salidaDescansos, 'errores' => array_values(array_unique($errores))];
    }

    /**
     * Recorre la estructura dia → lista de filas y devuelve las filas no vacías con el día validado.
     *
     * @param string[] $campos
     * @return array<int, array<string,mixed>>
     */
    private static function leerFilas(mixed $raw, string $que, array &$errores, array $campos): array
    {
        if ($raw === null || $raw === '' || $raw === []) { return []; }
        if (!is_array($raw)) { $errores[] = "El formato de los {$que}s enviados no es válido."; return []; }
        $filas = [];
        foreach ($raw as $diaRaw => $lista) {
            $dia = filter_var((string)$diaRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 7]]);
            if ($dia === false || !is_array($lista)) { $errores[] = "Hay un {$que} con un día de la semana no válido."; continue; }
            foreach ($lista as $fila) {
                if (!is_array($fila)) { $errores[] = "Hay un {$que} con un formato no válido."; continue; }
                $limpia = ['dia' => $dia];
                $valida = true;
                foreach ($campos as $campo) {
                    $v = $fila[$campo] ?? '';
                    if (!is_string($v)) { $valida = false; break; }
                    $limpia[$campo] = trim($v);
                }
                if (!$valida) { $errores[] = "Hay un {$que} con un formato no válido."; continue; }
                if ($limpia['inicio'] === '' && $limpia['fin'] === '') { continue; } // fila en blanco del formulario
                $filas[] = $limpia;
            }
        }
        return $filas;
    }

    /** @return array{inicio:string,fin:string,a:int,b:int}|null */
    private static function horas(string $inicio, string $fin, string $dia, string $que, int $minimo, array &$errores): ?array
    {
        $i = self::minutos($inicio);
        $f = self::minutos($fin);
        if ($i === null || $f === null) {
            $errores[] = "{$dia}: indica la hora de inicio y de fin " . ($que === 'franja' ? 'de la franja' : 'del descanso') . ' en formato HH:MM (entre 00:00 y 23:59).';
            return null;
        }
        if ($i % self::PASO_MINUTOS !== 0 || ($f % self::PASO_MINUTOS !== 0 && $f !== self::FIN_DE_DIA)) {
            $errores[] = "{$dia}: las horas " . ($que === 'franja' ? 'de la franja' : 'del descanso') . ' deben ser múltiplos de ' . self::PASO_MINUTOS . ' minutos.';
            return null;
        }
        if ($f <= $i) {
            $errores[] = "{$dia}: " . ($que === 'franja' ? 'la franja' : 'el descanso') . " {$inicio}-{$fin} debe terminar después de empezar.";
            return null;
        }
        if ($f - $i < $minimo) {
            $errores[] = "{$dia}: " . ($que === 'franja' ? 'la franja' : 'el descanso') . " {$inicio}-{$fin} debe durar al menos {$minimo} minutos.";
            return null;
        }
        return ['inicio' => self::hora($i), 'fin' => self::hora($f), 'a' => $i, 'b' => $f];
    }

    private static function minutos(string $hora): ?int
    {
        $hora = HorarioProfesional::normalizarHora($hora);
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hora, $m)) { return null; }
        return (int)$m[1] * 60 + (int)$m[2];
    }

    private static function hora(int $minutos): string
    {
        return sprintf('%02d:%02d', intdiv($minutos, 60), $minutos % 60);
    }
}

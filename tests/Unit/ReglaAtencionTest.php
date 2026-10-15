<?php

namespace Tests\Unit;

use Classes\ReglaAtencion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regla única de la atención (ronda 5): ventana de inicio (60 min antes … fin del día de la cita, hora de America/Guayaquil),
 * ausencia, y la máquina de estados. Es la MISMA regla que usan el servidor y la vista, por eso se prueba sola y sin base de datos.
 */
final class ReglaAtencionTest extends TestCase
{
    private static function ahora(string $texto, string $zona = 'America/Guayaquil'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($texto, new \DateTimeZone($zona));
    }

    public function test_el_adelanto_de_inicio_es_de_60_minutos(): void
    {
        $this->assertSame(60, ReglaAtencion::ADELANTO_INICIO_MINUTOS);
    }

    /** @return array<string,array{string,string,bool,?string}> */
    public static function fronterasDeInicio(): array
    {
        // Cita 2030-01-08 11:00 → ventana [10:00:00, 2030-01-09 00:00:00)
        return [
            'dos horas antes' => ['2030-01-08 09:00:00', '11:00:00', false, 'antes_de_ventana'],
            '60 min y 1 s antes' => ['2030-01-08 09:59:59', '11:00:00', false, 'antes_de_ventana'],
            'exactamente 60 min antes' => ['2030-01-08 10:00:00', '11:00:00', true, null],
            '59 min antes' => ['2030-01-08 10:01:00', '11:00:00', true, null],
            'a la hora exacta' => ['2030-01-08 11:00:00', '11:00:00', true, null],
            'ya comenzada' => ['2030-01-08 15:30:00', '11:00:00', true, null],
            'ultimo segundo del dia' => ['2030-01-08 23:59:59', '11:00:00', true, null],
            'primer segundo del dia siguiente' => ['2030-01-09 00:00:00', '11:00:00', true, null],   // sin cierre: registro con retraso
            'dia siguiente por la tarde' => ['2030-01-09 14:00:00', '11:00:00', true, null],
            'vispera' => ['2030-01-07 12:00:00', '11:00:00', false, 'antes_de_ventana'],
            // La hora puede llegar con segundos o sin ellos.
            'hora corta HH:MM' => ['2030-01-08 10:00:00', '11:00', true, null],
        ];
    }

    #[DataProvider('fronterasDeInicio')]
    public function test_fronteras_de_la_ventana_de_inicio(string $ahora, string $hora, bool $permitido, ?string $motivo): void
    {
        $r = ReglaAtencion::evaluarInicio('2030-01-08', $hora, self::ahora($ahora));
        $this->assertSame($permitido, $r['permitido'], $ahora);
        $this->assertSame($motivo, $r['motivo'], $ahora);
    }

    public function test_el_motivo_de_antes_de_la_ventana_dice_desde_que_hora(): void
    {
        $r = ReglaAtencion::evaluarInicio('2030-01-08', '15:00:00', self::ahora('2030-01-08 08:00:00'));
        $this->assertSame('Disponible desde las 14:00', $r['mensaje']);
        $this->assertSame('2030-01-08 14:00:00', $r['disponible_desde']->format('Y-m-d H:i:s'));
        $r = ReglaAtencion::evaluarInicio('2030-01-08', '09:30:00', self::ahora('2030-01-08 07:00:00'));
        $this->assertSame('Disponible desde las 08:30', $r['mensaje']);
    }

    public function test_frontera_del_dia_cita_de_madrugada_se_habilita_la_vispera_y_lo_dice(): void
    {
        // Cita a las 00:30: la ventana se abre a las 23:30 del día anterior.
        $antes = ReglaAtencion::evaluarInicio('2030-01-08', '00:30:00', self::ahora('2030-01-07 23:29:59'));
        $this->assertFalse($antes['permitido']);
        $this->assertSame('Disponible desde las 23:30 del 07/01', $antes['mensaje']);
        $this->assertTrue(ReglaAtencion::evaluarInicio('2030-01-08', '00:30:00', self::ahora('2030-01-07 23:30:00'))['permitido']);
        // Cita a las 00:00 exactas: desde las 23:00 del día anterior.
        $this->assertFalse(ReglaAtencion::evaluarInicio('2030-01-08', '00:00:00', self::ahora('2030-01-07 22:59:59'))['permitido']);
        $this->assertTrue(ReglaAtencion::evaluarInicio('2030-01-08', '00:00:00', self::ahora('2030-01-07 23:00:00'))['permitido']);
        // Sin cierre de ventana: pasada la medianoche todavía se puede registrar con retraso.
        $this->assertTrue(ReglaAtencion::evaluarInicio('2030-01-08', '23:30:00', self::ahora('2030-01-08 23:59:59'))['permitido']);
        $this->assertTrue(ReglaAtencion::evaluarInicio('2030-01-08', '23:30:00', self::ahora('2030-01-09 00:00:00'))['permitido']);
    }

    public function test_la_zona_es_la_del_negocio_aunque_el_instante_llegue_en_otra(): void
    {
        // 15:00 UTC == 10:00 en Guayaquil (UTC-5): para una cita de las 11:00 ya se puede iniciar (exactamente 60 min antes).
        $this->assertTrue(ReglaAtencion::evaluarInicio('2030-01-08', '11:00:00', self::ahora('2030-01-08 15:00:00', 'UTC'))['permitido']);
        $this->assertFalse(ReglaAtencion::evaluarInicio('2030-01-08', '11:00:00', self::ahora('2030-01-08 14:59:59', 'UTC'))['permitido']);
        // 04:59:59 UTC del día 9 == 23:59:59 del día 8 en Guayaquil: todavía es el día de la cita; 05:00:00 UTC ya no.
        $this->assertTrue(ReglaAtencion::evaluarInicio('2030-01-08', '11:00:00', self::ahora('2030-01-09 04:59:59', 'UTC'))['permitido']);
        $this->assertTrue(ReglaAtencion::evaluarInicio('2030-01-08', '11:00:00', self::ahora('2030-01-09 05:00:00', 'UTC'))['permitido'], 'sin cierre: también el día siguiente');
        // Y desde otra zona con cambio de fecha (Asia/Tokio va 14 h por delante de Guayaquil).
        $this->assertSame('antes_de_ventana', ReglaAtencion::evaluarInicio('2030-01-08', '11:00:00', self::ahora('2030-01-08 23:30:00', 'Asia/Tokyo'))['motivo']);
    }

    public function test_ausencia_solo_desde_la_hora_de_la_cita(): void
    {
        $this->assertFalse(ReglaAtencion::evaluarAusencia('2030-01-08', '11:00:00', self::ahora('2030-01-08 10:59:59'))['permitido']);
        $this->assertSame('Disponible desde las 11:00', ReglaAtencion::evaluarAusencia('2030-01-08', '11:00:00', self::ahora('2030-01-08 10:59:59'))['mensaje']);
        $this->assertTrue(ReglaAtencion::evaluarAusencia('2030-01-08', '11:00:00', self::ahora('2030-01-08 11:00:00'))['permitido']);
        // Una cita de días atrás sí admite registrar la ausencia (no hay límite superior).
        $this->assertTrue(ReglaAtencion::evaluarAusencia('2030-01-08', '11:00:00', self::ahora('2030-02-20 09:00:00'))['permitido']);
    }

    /** @return array<string,array{string,string,bool}> */
    public static function transiciones(): array
    {
        return [
            'confirmada→en_servicio' => ['confirmada', 'en_servicio', true],
            'confirmada→no_asistio' => ['confirmada', 'no_asistio', true],
            'confirmada→completada (sin iniciar)' => ['confirmada', 'completada', false],
            'confirmada→cancelada (flujo propio)' => ['confirmada', 'cancelada', false],
            'en_servicio→completada' => ['en_servicio', 'completada', true],
            'en_servicio→confirmada (retroceso)' => ['en_servicio', 'confirmada', false],
            'en_servicio→no_asistio' => ['en_servicio', 'no_asistio', false],
            'completada→confirmada' => ['completada', 'confirmada', false],
            'completada→en_servicio' => ['completada', 'en_servicio', false],
            'completada→no_asistio' => ['completada', 'no_asistio', false],
            'completada→completada' => ['completada', 'completada', false],
            'no_asistio→confirmada' => ['no_asistio', 'confirmada', false],
            'no_asistio→en_servicio' => ['no_asistio', 'en_servicio', false],
            'no_asistio→completada' => ['no_asistio', 'completada', false],
            'cancelada→en_servicio' => ['cancelada', 'en_servicio', false],
            'cancelada→confirmada' => ['cancelada', 'confirmada', false],
        ];
    }

    #[DataProvider('transiciones')]
    public function test_maquina_de_estados(string $desde, string $hacia, bool $esperado): void
    {
        $this->assertSame($esperado, ReglaAtencion::transicionPermitida($desde, $hacia));
    }

    public function test_los_estados_terminales_son_completada_ausencia_y_cancelada(): void
    {
        foreach (['completada', 'no_asistio', 'cancelada'] as $e) { $this->assertTrue(ReglaAtencion::esTerminal($e), $e); }
        foreach (['confirmada', 'en_servicio'] as $e) { $this->assertFalse(ReglaAtencion::esTerminal($e), $e); }
        foreach (ReglaAtencion::ESTADOS_TERMINALES as $t) {
            $this->assertArrayNotHasKey($t, ReglaAtencion::TRANSICIONES, "$t no tiene salida");
        }
    }

    public function test_validar_transicion_combina_estado_y_tiempo_con_codigos_http(): void
    {
        $ahora = self::ahora('2030-01-08 08:00:00');
        $this->assertSame(['ok' => true, 'httpCode' => 200, 'error' => ''], ReglaAtencion::validarTransicion('confirmada', 'en_servicio', '2030-01-08', '09:00:00', $ahora));
        $r = ReglaAtencion::validarTransicion('confirmada', 'en_servicio', '2030-01-08', '15:00:00', $ahora);
        $this->assertSame([false, 422], [$r['ok'], $r['httpCode']]);
        $this->assertStringContainsString('disponible desde las 14:00', $r['error']);
        $r = ReglaAtencion::validarTransicion('confirmada', 'en_servicio', '2030-01-07', '15:00:00', $ahora);
        $this->assertSame([true, 200], [$r['ok'], $r['httpCode']], 'una cita de un día anterior sin iniciar se puede registrar con retraso');
        $r = ReglaAtencion::validarTransicion('confirmada', 'no_asistio', '2030-01-08', '15:00:00', $ahora);
        $this->assertSame([false, 422], [$r['ok'], $r['httpCode']]);
        $r = ReglaAtencion::validarTransicion('completada', 'en_servicio', '2030-01-08', '07:00:00', $ahora);
        $this->assertSame([false, 409], [$r['ok'], $r['httpCode']]);
        $this->assertStringContainsString('registro histórico', $r['error']);
        // Finalizar no depende del reloj: se pudo iniciar 60 min antes de la hora y debe poder cerrarse.
        $this->assertTrue(ReglaAtencion::validarTransicion('en_servicio', 'completada', '2030-01-08', '15:00:00', $ahora)['ok']);
    }

    public function test_acciones_de_negocio_de_cada_transicion(): void
    {
        $this->assertSame('iniciar', ReglaAtencion::accionDeTransicion('en_servicio'));
        $this->assertSame('finalizar', ReglaAtencion::accionDeTransicion('completada'));
        $this->assertSame('ausencia', ReglaAtencion::accionDeTransicion('no_asistio'));
        $this->assertNull(ReglaAtencion::accionDeTransicion('confirmada'));
    }

    public function test_hora_invalida_se_rechaza(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReglaAtencion::evaluarInicio('2030-02-30', '11:00:00', self::ahora('2030-01-08 08:00:00'));
    }
}

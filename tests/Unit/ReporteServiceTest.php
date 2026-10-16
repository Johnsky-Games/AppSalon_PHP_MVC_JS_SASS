<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Repositories\PersistenceException;
use Repositories\ReporteRepository;
use Services\ReporteService;

class ReporteServiceTest extends TestCase
{
    public function testValidarYNormalizarFiltrosRangoPredeterminadoYRechazo422AnteEntradasInvalidas(): void
    {
        $repo = $this->createMock(ReporteRepository::class);
        $repo->method('listarProfesionalesCatalogo')->willReturn([
            ['id' => 1, 'nombre' => 'Sofía Andrade', 'email' => null, 'telefono' => null, 'activo' => 1],
        ]);
        $repo->method('listarServiciosCatalogo')->willReturn([
            ['id' => 1, 'nombre' => 'Corte de Cabello Hombre', 'precio' => 80.00, 'duracion_minutos' => 30],
        ]);

        // Ante filtros inválidos NO debe invocar obtenerFilasReservasEnRango
        $repo->expects($this->never())->method('obtenerFilasReservasEnRango');

        $reloj = new \DateTimeImmutable('2026-10-15 10:00:00', new \DateTimeZone(ReporteService::TIMEZONE));
        $service = new ReporteService($repo, $reloj);

        // 1. Rango predeterminado del mes actual en America/Guayaquil
        $normDefault = $service->validarYNormalizarFiltros([]);
        $this->assertTrue($normDefault['valido']);
        $this->assertSame('2026-10-01', $normDefault['fecha_inicio']);
        $this->assertSame('2026-10-31', $normDefault['fecha_fin']);
        $this->assertNull($normDefault['profesional_filtro']);
        $this->assertNull($normDefault['servicio_id']);
        $this->assertSame(31, $normDefault['dias_rango']);

        // 2. Fecha inválida (2026-02-30) -> 422
        $resFechaInvalida = $service->consultarReporte([
            'fecha_inicio' => '2026-02-30',
            'fecha_fin' => '2026-03-05',
        ]);
        $this->assertSame(ReporteService::STATUS_INVALID, $resFechaInvalida['status']);
        $this->assertSame(422, $resFechaInvalida['http_code']);
        $this->assertNotEmpty($resFechaInvalida['alertas']['error']);
        $this->assertSame(0, $resFechaInvalida['resumen_global']['total_citas']);
        $this->assertSame(0.0, $resFechaInvalida['resumen_global']['importe_reservado_total']);

        // 3. Rango invertido (fecha_inicio > fecha_fin) -> 422
        $resInvertido = $service->consultarReporte([
            'fecha_inicio' => '2026-10-20',
            'fecha_fin' => '2026-10-10',
        ]);
        $this->assertSame(ReporteService::STATUS_INVALID, $resInvertido['status']);
        $this->assertSame(422, $resInvertido['http_code']);
        $this->assertStringContainsString('posterior', implode(' ', $resInvertido['alertas']['error']));

        // 4. Rango que supera MAX_DIAS_RANGO (366 días) -> 422
        $resExcesivo = $service->consultarReporte([
            'fecha_inicio' => '2025-01-01',
            'fecha_fin' => '2026-06-01',
        ]);
        $this->assertSame(ReporteService::STATUS_INVALID, $resExcesivo['status']);
        $this->assertSame(422, $resExcesivo['http_code']);

        // 5. profesional_id o servicio_id malformados -> 422
        $resFiltrosMalformados = $service->consultarReporte([
            'fecha_inicio' => '2026-10-12',
            'fecha_fin' => '2026-10-12',
            'profesional_id' => '-5',
            'servicio_id' => 'abc',
        ]);
        $this->assertSame(ReporteService::STATUS_INVALID, $resFiltrosMalformados['status']);
        $this->assertSame(422, $resFiltrosMalformados['http_code']);
        $this->assertCount(2, $resFiltrosMalformados['alertas']['error']);
    }

    public function testCalculoExactoDeOcupacionDescontandoDescansosYBloqueosSolapadosYSinDivisionPorCero(): void
    {
        $repo = $this->createMock(ReporteRepository::class);
        $service = new ReporteService($repo);

        $profesionales = [
            ['id' => 1, 'nombre' => 'Sofía Andrade', 'activo' => 1],
            ['id' => 2, 'nombre' => 'Valeria Castro', 'activo' => 1],
            ['id' => 3, 'nombre' => 'Camila Inactiva', 'activo' => 0],
        ];

        // 2026-10-12 es Lunes (dia_semana = 1) y 2026-10-13 es Martes (dia_semana = 2)
        $horarios = [
            // Sofía: Lunes 09:00-18:00 (540 min), Martes 08:00-12:00 (240 min)
            ['id' => 1, 'profesionalId' => 1, 'dia_semana' => 1, 'hora_inicio' => '09:00:00', 'hora_fin' => '18:00:00'],
            ['id' => 2, 'profesionalId' => 1, 'dia_semana' => 2, 'hora_inicio' => '08:00:00', 'hora_fin' => '12:00:00'],
            // Valeria: Lunes sin turno, Martes bloqueada día completo
            ['id' => 3, 'profesionalId' => 2, 'dia_semana' => 2, 'hora_inicio' => '09:00:00', 'hora_fin' => '17:00:00'],
            // Camila (inactiva): Lunes 09:00-17:00 -> debe dar 0 por estar inactiva
            ['id' => 4, 'profesionalId' => 3, 'dia_semana' => 1, 'hora_inicio' => '09:00:00', 'hora_fin' => '17:00:00'],
        ];

        $descansos = [
            // Sofía Lunes: descanso 13:00-14:00 (60 min)
            ['id' => 1, 'profesionalId' => 1, 'dia_semana' => 1, 'hora_inicio' => '13:00:00', 'hora_fin' => '14:00:00'],
        ];

        $bloqueos = [
            // Sofía Lunes 2026-10-12: bloqueo 13:30-15:00 (solapa 30 min con descanso 13:00-14:00; unión [13:00, 15:00) = 120 min)
            [
                'id' => 1,
                'profesionalId' => 1,
                'fecha_inicio' => '2026-10-12',
                'fecha_fin' => '2026-10-12',
                'hora_inicio' => '13:30:00',
                'hora_fin' => '15:00:00',
            ],
            // Valeria Martes 2026-10-13: bloqueo de día completo
            [
                'id' => 2,
                'profesionalId' => 2,
                'fecha_inicio' => '2026-10-13',
                'fecha_fin' => '2026-10-13',
                'hora_inicio' => null,
                'hora_fin' => null,
            ],
        ];

        $mapaMinutos = $service->calcularMinutosDisponiblesPorProfesional(
            '2026-10-12',
            '2026-10-13',
            $profesionales,
            $horarios,
            $descansos,
            $bloqueos
        );

        // Sofía: Lunes (540 - 120 = 420) + Martes (240) = 660 min
        $this->assertSame(660, $mapaMinutos[1]);
        // Valeria: Lunes (0) + Martes (bloqueo día completo = 0) = 0 min
        $this->assertSame(0, $mapaMinutos[2]);
        // Camila inactiva: 0 min
        $this->assertSame(0, $mapaMinutos[3]);
    }

    public function testImportesHistoricosDistincionPagosFiltroServicioYCitasHistoricasSinProfesional(): void
    {
        $repo = $this->createMock(ReporteRepository::class);
        $repo->method('listarProfesionalesCatalogo')->willReturn([
            ['id' => 1, 'nombre' => 'Sofía Andrade', 'email' => null, 'telefono' => null, 'activo' => 1],
        ]);
        $repo->method('listarServiciosCatalogo')->willReturn([
            ['id' => 1, 'nombre' => 'Corte Actualizado', 'precio' => 150.00, 'duracion_minutos' => 45],
            ['id' => 2, 'nombre' => 'Coloración', 'precio' => 200.00, 'duracion_minutos' => 90],
        ]);
        $repo->method('obtenerHorariosProfesionales')->willReturn([
            ['id' => 1, 'profesionalId' => 1, 'dia_semana' => 1, 'hora_inicio' => '09:00:00', 'hora_fin' => '18:00:00'],
        ]);
        $repo->method('obtenerDescansosProfesionales')->willReturn([
            ['id' => 1, 'profesionalId' => 1, 'dia_semana' => 1, 'hora_inicio' => '13:00:00', 'hora_fin' => '14:00:00'],
        ]);
        $repo->method('obtenerBloqueosEnRango')->willReturn([]);

        // Filas con snapshot histórico:
        // - Cita 10 (con profesional 1): Servicio 1 capturado a $80.00 (30 min) + Servicio eliminado (id=null) capturado a $95.50 (60 min)
        // - Cita 11 (histórica sin profesional): Servicio 1 capturado a $70.00 (30 min)
        $repo->method('obtenerFilasReservasEnRango')->willReturn([
            [
                'cita_id' => 10,
                'fecha' => '2026-10-12',
                'hora' => '10:00:00',
                'hora_inicio' => '10:00:00',
                'hora_fin' => '11:30:00',
                'cita_duracion_total_minutos' => 90,
                'usuario_id' => 1,
                'profesional_id' => 1,
                'profesional_nombre' => 'Sofía Andrade',
                'profesional_activo' => 1,
                'cliente_nombre' => 'Carlos',
                'cliente_apellido' => 'Mendoza',
                'cliente_email' => 'carlos@correo.com',
                'cliente_telefono' => '0999999999',
                'citasservicio_id' => 100,
                'servicio_id' => 1,
                'servicio_nombre' => 'Corte Histórico',
                'servicio_precio' => '80.00',
                'servicio_duracion_minutos' => 30,
            ],
            [
                'cita_id' => 10,
                'fecha' => '2026-10-12',
                'hora' => '10:00:00',
                'hora_inicio' => '10:00:00',
                'hora_fin' => '11:30:00',
                'cita_duracion_total_minutos' => 90,
                'usuario_id' => 1,
                'profesional_id' => 1,
                'profesional_nombre' => 'Sofía Andrade',
                'profesional_activo' => 1,
                'cliente_nombre' => 'Carlos',
                'cliente_apellido' => 'Mendoza',
                'cliente_email' => 'carlos@correo.com',
                'cliente_telefono' => '0999999999',
                'citasservicio_id' => 101,
                'servicio_id' => null,
                'servicio_nombre' => 'Tratamiento Eliminado',
                'servicio_precio' => '95.50',
                'servicio_duracion_minutos' => 60,
            ],
            [
                'cita_id' => 11,
                'fecha' => '2026-10-12',
                'hora' => '15:00:00',
                'hora_inicio' => null,
                'hora_fin' => null,
                'cita_duracion_total_minutos' => null,
                'usuario_id' => 1,
                'profesional_id' => null,
                'profesional_nombre' => null,
                'profesional_activo' => null,
                'cliente_nombre' => 'Carlos',
                'cliente_apellido' => 'Mendoza',
                'cliente_email' => 'carlos@correo.com',
                'cliente_telefono' => '0999999999',
                'citasservicio_id' => 102,
                'servicio_id' => 1,
                'servicio_nombre' => 'Corte Histórico',
                'servicio_precio' => '70.00',
                'servicio_duracion_minutos' => 30,
            ],
        ]);

        $service = new ReporteService($repo);
        $reporte = $service->consultarReporte([
            'fecha_inicio' => '2026-10-12',
            'fecha_fin' => '2026-10-12',
        ]);

        $this->assertSame(ReporteService::STATUS_OK, $reporte['status']);
        $this->assertSame(2, $reporte['resumen_global']['total_citas']);
        $this->assertSame(1, $reporte['resumen_global']['total_citas_con_profesional']);
        $this->assertSame(1, $reporte['resumen_global']['total_citas_historicas_sin_profesional']);

        // Importe reservado = 80.00 + 95.50 + 70.00 = 245.50; Ingreso pagado = 0.00
        $this->assertSame(245.50, $reporte['resumen_global']['importe_reservado_total']);
        $this->assertSame(0.00, $reporte['resumen_global']['ingreso_pagado_total']);
        $this->assertSame(245.50, $reporte['resumen_global']['saldo_pendiente_cobro_total']);

        // Ocupación de Sofía Andrade en 2026-10-12: 90 min ocupados / 480 min disponibles = 18.75%
        // (La cita histórica sin profesional NO altera el numerador ni el denominador de Sofía)
        $this->assertSame(90, $reporte['resumen_global']['minutos_ocupados_profesionales']);
        $this->assertSame(480, $reporte['resumen_global']['minutos_disponibles_profesionales']);
        $this->assertSame(18.75, $reporte['resumen_global']['porcentaje_ocupacion_global']);

        $this->assertCount(2, $reporte['resumen_profesionales']);
        $profSofia = $reporte['resumen_profesionales'][0];
        $profHist = $reporte['resumen_profesionales'][1];

        $this->assertSame(1, $profSofia['profesional_id']);
        $this->assertSame(18.75, $profSofia['porcentaje_ocupacion']);
        $this->assertSame(175.50, $profSofia['importe_reservado']);
        $this->assertSame(0.00, $profSofia['ingreso_pagado']);

        $this->assertNull($profHist['profesional_id']);
        $this->assertTrue($profHist['es_historico_sin_profesional']);
        $this->assertSame(70.00, $profHist['importe_reservado']);

        // Verificar filtro por servicio_id = 1: subtotal atribuible = 80.00 + 70.00 = 150.00, mientras importe_bruto_citas = 245.50
        $reporteFiltradoSrv = $service->consultarReporte([
            'fecha_inicio' => '2026-10-12',
            'fecha_fin' => '2026-10-12',
            'servicio_id' => '1',
        ]);
        $this->assertSame(150.00, $reporteFiltradoSrv['resumen_global']['importe_reservado_total']);
        $this->assertSame(245.50, $reporteFiltradoSrv['resumen_global']['importe_bruto_citas_filtradas']);
        $this->assertSame(0.00, $reporteFiltradoSrv['resumen_global']['ingreso_pagado_total']);
        $this->assertCount(1, $reporteFiltradoSrv['resumen_servicios']);
        $this->assertSame(150.00, $reporteFiltradoSrv['resumen_servicios'][0]['importe_reservado']);
    }

    public function testSanearCeldaCsvYGenerarCsvNeutralizaFormulaInjection(): void
    {
        $this->assertSame("'=SUM(A1:A2)", ReporteService::sanearCeldaCsv('=SUM(A1:A2)'));
        $this->assertSame("'+1+1", ReporteService::sanearCeldaCsv('+1+1'));
        $this->assertSame("'-2+3", ReporteService::sanearCeldaCsv('-2+3'));
        $this->assertSame("'@IMPORT(\"http://evil\")", ReporteService::sanearCeldaCsv('@IMPORT("http://evil")'));
        $this->assertSame("'\t=cmd", ReporteService::sanearCeldaCsv("\t=cmd"));
        $this->assertSame("'\r=cmd", ReporteService::sanearCeldaCsv("\r=cmd"));
        $this->assertSame("'  =CMD|'/C calc'!A0", ReporteService::sanearCeldaCsv("  =CMD|'/C calc'!A0"));

        // Valores numéricos no negativos legítimos y textos normales no se alteran
        $this->assertSame('195.00', ReporteService::sanearCeldaCsv('195.00'));
        $this->assertSame('0.00', ReporteService::sanearCeldaCsv('0.00'));
        $this->assertSame('90', ReporteService::sanearCeldaCsv(90));
        $this->assertSame('Valeria Castro', ReporteService::sanearCeldaCsv('Valeria Castro'));

        $service = new ReporteService($this->createMock(ReporteRepository::class));
        $csv = $service->generarCsv([
            'filtros' => [
                'fecha_inicio' => '2026-10-12',
                'fecha_fin' => '2026-10-12',
                'profesional_id' => '',
                'servicio_id' => '',
            ],
            'resumen_global' => [
                'total_citas' => 1,
                'minutos_ocupados_profesionales' => 60,
                'minutos_disponibles_profesionales' => 480,
                'porcentaje_ocupacion_global' => 12.50,
                'importe_reservado_total' => 115.00,
                'ingreso_pagado_total' => 0.00,
                'saldo_pendiente_cobro_total' => 115.00,
            ],
            'resumen_profesionales' => [
                [
                    'profesional_id' => 1,
                    'profesional_nombre' => '=PROF_FORMULA()',
                    'activo' => true,
                    'es_historico_sin_profesional' => false,
                    'total_citas' => 1,
                    'minutos_ocupados' => 60,
                    'minutos_disponibles' => 480,
                    'horas_ocupadas' => 1.0,
                    'horas_disponibles' => 8.0,
                    'porcentaje_ocupacion' => 12.50,
                    'importe_reservado' => 115.00,
                    'ingreso_pagado' => 0.00,
                ],
            ],
            'resumen_servicios' => [
                [
                    'servicio_id' => 4,
                    'servicio_nombre' => '+SERVICIO_MALICIOSO',
                    'total_reservas' => 1,
                    'minutos_reservados' => 60,
                    'importe_reservado' => 115.00,
                    'ingreso_pagado' => 0.00,
                ],
            ],
            'reservas' => [
                [
                    'id' => 5,
                    'fecha' => '2026-10-12',
                    'horario' => '10:00 - 11:00',
                    'cliente' => '@CLIENTE_INJECT',
                    'cliente_email' => 'cliente@correo.com',
                    'profesional_nombre' => '=PROF_FORMULA()',
                    'servicios_resumen' => '+SERVICIO_MALICIOSO ($115.00 (60 min))',
                    'duracion_total_minutos' => 60,
                    'importe_total_cita' => 115.00,
                    'importe_reservado' => 115.00,
                    'ingreso_pagado' => 0.00,
                    'etiqueta_estado_pago' => 'Pendiente de cobro (sin pago registrado)',
                ],
            ],
        ]);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("'=PROF_FORMULA()", $csv);
        $this->assertStringContainsString("'+SERVICIO_MALICIOSO", $csv);
        $this->assertStringContainsString("'@CLIENTE_INJECT", $csv);
        $this->assertStringContainsString('Importe Reservado Total (USD)', $csv);
        $this->assertStringContainsString('Ingreso Pagado Total (USD)', $csv);
    }

    public function testManejoDePersistenceExceptionDevuelveEstadoError500(): void
    {
        $repo = $this->createMock(ReporteRepository::class);
        $repo->method('listarProfesionalesCatalogo')
            ->willThrowException(new PersistenceException('Fallo SQL simulado en reportes'));

        $service = new ReporteService($repo);
        $res = $service->consultarReporte([
            'fecha_inicio' => '2026-10-12',
            'fecha_fin' => '2026-10-12',
        ]);

        $this->assertSame(ReporteService::STATUS_ERROR, $res['status']);
        $this->assertSame(500, $res['http_code']);
        $this->assertNotEmpty($res['alertas']['error']);
    }
}

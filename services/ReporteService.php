<?php

namespace Services;

use Model\HorarioProfesional;
use Repositories\PersistenceException;
use Repositories\ReporteRepository;

/**
 * Servicio de dominio para Reportes Administrativos (Fase 6B):
 * - Validación estricta de filtros por rango de fechas (`fecha_inicio`, `fecha_fin`),
 *   profesional (`profesional_id`) y servicio (`servicio_id`) en zona horaria `America/Guayaquil`.
 * - Cálculo de importes y duraciones exclusivamente a partir de los datos históricos
 *   capturados al reservar (`citasservicios` con fallback a `servicios` en citas antiguas).
 * - Distinción explícita entre **Importe Reservado (`importe_reservado_total`)** e
 *   **Ingreso Efectivamente Pagado (`ingreso_pagado_total`)**, evitando presentar
 *   reservas pendientes de cobro como ingresos pagados.
 * - Cálculo de ocupación respecto de las horas netas disponibles de turno por profesional,
 *   descontando descansos y bloqueos vigentes con semántica semiabierta `[inicio, fin)`.
 * - Generación de exportación CSV segura con neutralización de *CSV Formula Injection*.
 */
class ReporteService
{
    public const STATUS_OK = 'ok';
    public const STATUS_EMPTY = 'empty';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_ERROR = 'error';

    public const TIMEZONE = 'America/Guayaquil';
    public const MAX_DIAS_RANGO = 366;

    /** Fecha PROGRAMADA original de la cita (todas las reservas). Antes se llamaba «servicio»; ese valor se sigue aceptando como alias. */
    public const CRITERIO_FECHA_PROGRAMACION = 'programacion';
    /** Servicios REALIZADOS: citas atendidas por la fecha EFECTIVA de la atención (la programada si no hubo regularización). */
    public const CRITERIO_FECHA_REALIZADO = 'realizado';
    /** @deprecated alias de CRITERIO_FECHA_PROGRAMACION (no es la fecha real del servicio). */
    public const CRITERIO_FECHA_SERVICIO = self::CRITERIO_FECHA_PROGRAMACION;
    public const CRITERIO_FECHA_COBRO = 'cobro';

    public const ESTADO_PAGO_PENDIENTE = 'pendiente';
    public const ESTADO_PAGO_PAGADO = 'pagado';
    public const ESTADO_PAGO_REEMBOLSADO = 'reembolsado';

    private ReporteRepository $reporteRepository;
    private ?\DateTimeImmutable $ahoraReferencia;
    private int $maxDiasRango;

    public function __construct(
        ?ReporteRepository $reporteRepository = null,
        ?\DateTimeImmutable $ahoraReferencia = null,
        int $maxDiasRango = self::MAX_DIAS_RANGO
    ) {
        $this->reporteRepository = $reporteRepository ?? new ReporteRepository();
        $this->ahoraReferencia = $ahoraReferencia;
        $this->maxDiasRango = max(1, $maxDiasRango);
    }

    /**
     * Permite inyectar un reloj de referencia para pruebas deterministas.
     */
    public function setAhoraReferencia(?\DateTimeImmutable $ahoraReferencia): void
    {
        $this->ahoraReferencia = $ahoraReferencia;
    }

    /**
     * Obtiene el instante actual en zona horaria `America/Guayaquil`.
     */
    public function obtenerAhora(): \DateTimeImmutable
    {
        $tz = new \DateTimeZone(self::TIMEZONE);
        if ($this->ahoraReferencia !== null) {
            return $this->ahoraReferencia->setTimezone($tz);
        }
        return new \DateTimeImmutable('now', $tz);
    }

    /**
     * Calcula el rango predeterminado (mes actual en `America/Guayaquil`).
     *
     * @return array{fecha_inicio: string, fecha_fin: string}
     */
    public function obtenerRangoPredeterminado(): array
    {
        $ahora = $this->obtenerAhora();
        return [
            'fecha_inicio' => $ahora->format('Y-m-01'),
            'fecha_fin' => $ahora->format('Y-m-t'),
        ];
    }

    /**
     * Valida una cadena de fecha en formato estricto `YYYY-MM-DD`.
     */
    public static function esFechaValida(string $fecha): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fecha, $m)) {
            return false;
        }
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    }

    /**
     * Convierte una hora `HH:MM` o `HH:MM:SS` a minutos desde las 00:00.
     */
    public static function horaAMinutos(string $hora): int
    {
        $normalizada = HorarioProfesional::normalizarHora($hora);
        if ($normalizada === '') {
            return 0;
        }
        $partes = explode(':', $normalizada);
        return ((int)$partes[0] * 60) + (int)$partes[1];
    }

    /**
     * Resta un conjunto de restricciones `[inicio, fin)` a una franja laboral `[turnoInicio, turnoFin)`
     * empleando semántica de intervalos semiabiertos `[inicio, fin)`.
     * Evita restar dos veces solapamientos entre descansos y bloqueos.
     *
     * @param int $turnoInicio
     * @param int $turnoFin
     * @param array<int, array{inicio: int, fin: int}> $restricciones
     * @return array<int, array{inicio: int, fin: int}>
     */
    public function restarRestriccionesDeTurno(int $turnoInicio, int $turnoFin, array $restricciones): array
    {
        if ($turnoInicio >= $turnoFin) {
            return [];
        }

        $ventanasLibres = [
            ['inicio' => $turnoInicio, 'fin' => $turnoFin],
        ];

        foreach ($restricciones as $restriccion) {
            $rInicio = (int)($restriccion['inicio'] ?? 0);
            $rFin = (int)($restriccion['fin'] ?? 0);
            if ($rInicio >= $rFin) {
                continue;
            }

            $nuevasVentanas = [];
            foreach ($ventanasLibres as $ventana) {
                $vInicio = $ventana['inicio'];
                $vFin = $ventana['fin'];

                if ($rFin <= $vInicio || $rInicio >= $vFin) {
                    $nuevasVentanas[] = $ventana;
                    continue;
                }

                if ($rInicio > $vInicio) {
                    $nuevasVentanas[] = [
                        'inicio' => $vInicio,
                        'fin' => $rInicio,
                    ];
                }

                if ($rFin < $vFin) {
                    $nuevasVentanas[] = [
                        'inicio' => $rFin,
                        'fin' => $vFin,
                    ];
                }
            }

            $ventanasLibres = $nuevasVentanas;
            if (empty($ventanasLibres)) {
                break;
            }
        }

        return $ventanasLibres;
    }

    /**
     * Valida y normaliza los filtros de entrada para el reporte administrativo.
     *
     * @param array<string, mixed> $parametros
     * @return array{
     *   valido: bool,
     *   errores: array<int, string>,
     *   fecha_inicio: string,
     *   fecha_fin: string,
     *   profesional_filtro: ?string,
     *   profesional_id_int: ?int,
     *   servicio_id: ?int,
     *   dias_rango: int
     * }
     */
    public function validarYNormalizarFiltros(array $parametros): array
    {
        $rangoDefault = $this->obtenerRangoPredeterminado();
        $errores = [];

        $tieneFechaInicio = array_key_exists('fecha_inicio', $parametros) && trim((string)$parametros['fecha_inicio']) !== '';
        $tieneFechaFin = array_key_exists('fecha_fin', $parametros) && trim((string)$parametros['fecha_fin']) !== '';

        if (!$tieneFechaInicio && !$tieneFechaFin) {
            $fechaInicio = $rangoDefault['fecha_inicio'];
            $fechaFin = $rangoDefault['fecha_fin'];
        } elseif ($tieneFechaInicio && !$tieneFechaFin) {
            $fechaInicio = trim((string)$parametros['fecha_inicio']);
            $fechaFin = $fechaInicio;
        } elseif (!$tieneFechaInicio && $tieneFechaFin) {
            $fechaFin = trim((string)$parametros['fecha_fin']);
            $fechaInicio = $fechaFin;
        } else {
            $fechaInicio = trim((string)$parametros['fecha_inicio']);
            $fechaFin = trim((string)$parametros['fecha_fin']);
        }

        $inicioValido = self::esFechaValida($fechaInicio);
        $finValido = self::esFechaValida($fechaFin);

        if (!$inicioValido) {
            $errores[] = 'La fecha de inicio no es válida. Use el formato AAAA-MM-DD.';
        }
        if (!$finValido) {
            $errores[] = 'La fecha de fin no es válida. Use el formato AAAA-MM-DD.';
        }

        $diasRango = 0;
        if ($inicioValido && $finValido) {
            if ($fechaInicio > $fechaFin) {
                $errores[] = 'La fecha de inicio no puede ser posterior a la fecha de fin.';
            } else {
                $tz = new \DateTimeZone(self::TIMEZONE);
                $dtInicio = new \DateTimeImmutable($fechaInicio . ' 00:00:00', $tz);
                $dtFin = new \DateTimeImmutable($fechaFin . ' 00:00:00', $tz);
                $diasRango = (int)$dtInicio->diff($dtFin)->days + 1;

                if ($diasRango > $this->maxDiasRango) {
                    $errores[] = "El rango de fechas solicitado ({$diasRango} días) supera el máximo permitido de {$this->maxDiasRango} días.";
                }
            }
        }

        // Filtro de profesional: vacío / 'todos' => null, 'sin_profesional' => 'sin_profesional', o ID entero positivo
        $profRaw = $parametros['profesional_id'] ?? $parametros['profesionalId'] ?? $parametros['profesional'] ?? null;
        $profesionalFiltro = null;
        $profesionalIdInt = null;

        if ($profRaw !== null && trim((string)$profRaw) !== '' && strtolower(trim((string)$profRaw)) !== 'todos') {
            $profStr = trim((string)$profRaw);
            if ($profStr === 'sin_profesional' || $profStr === 'historicas') {
                $profesionalFiltro = 'sin_profesional';
            } elseif (ctype_digit($profStr) && (int)$profStr > 0) {
                $profesionalIdInt = (int)$profStr;
                $profesionalFiltro = (string)$profesionalIdInt;
            } else {
                $errores[] = 'El filtro de profesional seleccionado no es válido.';
            }
        }

        // Filtro de servicio: vacío / 'todos' => null, o ID entero positivo
        $servRaw = $parametros['servicio_id'] ?? $parametros['servicioId'] ?? $parametros['servicio'] ?? null;
        $servicioId = null;

        if ($servRaw !== null && trim((string)$servRaw) !== '' && strtolower(trim((string)$servRaw)) !== 'todos') {
            $servStr = trim((string)$servRaw);
            if (ctype_digit($servStr) && (int)$servStr > 0) {
                $servicioId = (int)$servStr;
            } else {
                $errores[] = 'El filtro de servicio seleccionado no es válido.';
            }
        }

        // Criterio de fecha: 'programacion' (predeterminado; fecha original de la cita), 'realizado' (fecha efectiva de la atención) o 'cobro' (fecha del movimiento)
        $criterioRaw = $parametros['criterio_fecha'] ?? $parametros['tipo_fecha'] ?? null;
        $criterioFecha = self::CRITERIO_FECHA_SERVICIO;

        if ($criterioRaw !== null && trim((string)$criterioRaw) !== '') {
            $criterioStr = strtolower(trim((string)$criterioRaw));
            if ($criterioStr === 'cobro' || $criterioStr === 'pago') {
                $criterioFecha = self::CRITERIO_FECHA_COBRO;
            } elseif ($criterioStr === 'realizado' || $criterioStr === 'atencion') {
                $criterioFecha = self::CRITERIO_FECHA_REALIZADO;
            } elseif (in_array($criterioStr, ['programacion', 'programada', 'servicio', 'cita'], true)) {
                $criterioFecha = self::CRITERIO_FECHA_PROGRAMACION;
            } else {
                $errores[] = "El criterio de fecha seleccionado no es válido. Debe ser 'programacion', 'realizado' o 'cobro'.";
            }
        }

        return [
            'valido' => empty($errores),
            'errores' => $errores,
            'fecha_inicio' => $inicioValido ? $fechaInicio : $rangoDefault['fecha_inicio'],
            'fecha_fin' => $finValido ? $fechaFin : $rangoDefault['fecha_fin'],
            'criterio_fecha' => $criterioFecha,
            'profesional_filtro' => $profesionalFiltro,
            'profesional_id_int' => $profesionalIdInt,
            'servicio_id' => $servicioId,
            'dias_rango' => $diasRango,
        ];
    }

    /**
     * Evalúa el estado de pago de una cita distinguiendo explícitamente entre
     * importe reservado (comprometido) e ingreso efectivamente pagado.
     *
     * Si la cita registra pago confirmado (`'pagado'` / `'completado'`), retorna el monto pagado.
     * En caso contrario (`'pendiente'`, `'reembolsado'`, etc.), el ingreso pagado es estrictamente `0.00`.
     *
     * @param array<string, mixed> $datosCita
     * @param float $importeReservado
     * @return array{estado_pago: string, etiqueta_estado_pago: string, ingreso_pagado: float}
     */
    public function evaluarEstadoPagoCita(array $datosCita, float $importeReservado): array
    {
        $estadoRaw = strtolower(trim((string)($datosCita['estado_pago'] ?? '')));
        if (in_array($estadoRaw, [self::ESTADO_PAGO_PAGADO, 'completado'], true)) {
            $montoPagado = isset($datosCita['monto_pagado']) && is_numeric($datosCita['monto_pagado'])
                ? round(max(0.0, (float)$datosCita['monto_pagado']), 2)
                : round(max(0.0, $importeReservado), 2);

            return [
                'estado_pago' => self::ESTADO_PAGO_PAGADO,
                'etiqueta_estado_pago' => 'Pagado',
                'ingreso_pagado' => $montoPagado,
            ];
        }

        if ($estadoRaw === self::ESTADO_PAGO_REEMBOLSADO) {
            return [
                'estado_pago' => self::ESTADO_PAGO_REEMBOLSADO,
                'etiqueta_estado_pago' => 'Reembolsado',
                'ingreso_pagado' => 0.00,
            ];
        }

        return [
            'estado_pago' => self::ESTADO_PAGO_PENDIENTE,
            'etiqueta_estado_pago' => 'Pendiente de cobro (sin pago registrado)',
            'ingreso_pagado' => 0.00,
        ];
    }

    /**
     * Calcula los minutos disponibles netos de turno para cada profesional en `[fechaInicio, fechaFin]`,
     * descontando descansos semanales y bloqueos vigentes con semántica `[inicio, fin)`.
     *
     * @param string $fechaInicio
     * @param string $fechaFin
     * @param array<int, array<string, mixed>> $profesionales
     * @param array<int, array<string, mixed>> $horarios
     * @param array<int, array<string, mixed>> $descansos
     * @param array<int, array<string, mixed>> $bloqueos
     * @return array<int, int> Mapa `[profesionalId => minutos_disponibles_netos]`
     */
    public function calcularMinutosDisponiblesPorProfesional(
        string $fechaInicio,
        string $fechaFin,
        array $profesionales,
        array $horarios,
        array $descansos,
        array $bloqueos
    ): array {
        $horariosPorProfDia = [];
        foreach ($horarios as $h) {
            $pId = (int)($h['profesionalId'] ?? 0);
            $dia = (int)($h['dia_semana'] ?? 0);
            $horariosPorProfDia[$pId][$dia][] = [
                'inicio' => self::horaAMinutos((string)$h['hora_inicio']),
                'fin' => self::horaAMinutos((string)$h['hora_fin']),
            ];
        }

        $descansosPorProfDia = [];
        foreach ($descansos as $d) {
            $pId = (int)($d['profesionalId'] ?? 0);
            $dia = (int)($d['dia_semana'] ?? 0);
            $descansosPorProfDia[$pId][$dia][] = [
                'inicio' => self::horaAMinutos((string)$d['hora_inicio']),
                'fin' => self::horaAMinutos((string)$d['hora_fin']),
            ];
        }

        $bloqueosPorProf = [];
        foreach ($bloqueos as $b) {
            $pId = (int)($b['profesionalId'] ?? 0);
            $bloqueosPorProf[$pId][] = $b;
        }

        $tz = new \DateTimeZone(self::TIMEZONE);
        $cursor = new \DateTimeImmutable($fechaInicio . ' 00:00:00', $tz);
        $limite = new \DateTimeImmutable($fechaFin . ' 00:00:00', $tz);

        $fechasRango = [];
        while ($cursor <= $limite) {
            $fechasRango[] = [
                'fecha' => $cursor->format('Y-m-d'),
                'dia_semana' => (int)$cursor->format('N'),
            ];
            $cursor = $cursor->modify('+1 day');
        }

        $resultado = [];
        foreach ($profesionales as $prof) {
            $profId = (int)$prof['id'];
            $activo = (int)($prof['activo'] ?? 1) === 1;

            if (!$activo) {
                $resultado[$profId] = 0;
                continue;
            }

            $minutosTotalesProf = 0;
            foreach ($fechasRango as $infoFecha) {
                $fechaStr = $infoFecha['fecha'];
                $diaSemana = $infoFecha['dia_semana'];

                $turnosDia = $horariosPorProfDia[$profId][$diaSemana] ?? [];
                if (empty($turnosDia)) {
                    continue;
                }

                $restriccionesDia = $descansosPorProfDia[$profId][$diaSemana] ?? [];
                foreach ($bloqueosPorProf[$profId] ?? [] as $blk) {
                    $bInicioFecha = (string)($blk['fecha_inicio'] ?? '');
                    $bFinFecha = (string)($blk['fecha_fin'] ?? '');
                    if ($bInicioFecha <= $fechaStr && $bFinFecha >= $fechaStr) {
                        $hIni = $blk['hora_inicio'] ?? null;
                        $hFin = $blk['hora_fin'] ?? null;
                        if ($hIni === null || $hFin === null || trim((string)$hIni) === '' || trim((string)$hFin) === '') {
                            $restriccionesDia[] = ['inicio' => 0, 'fin' => 1440];
                        } else {
                            $restriccionesDia[] = [
                                'inicio' => self::horaAMinutos((string)$hIni),
                                'fin' => self::horaAMinutos((string)$hFin),
                            ];
                        }
                    }
                }

                foreach ($turnosDia as $turno) {
                    $ventanasLibres = $this->restarRestriccionesDeTurno(
                        (int)$turno['inicio'],
                        (int)$turno['fin'],
                        $restriccionesDia
                    );
                    foreach ($ventanasLibres as $ventana) {
                        $minutosTotalesProf += max(0, (int)$ventana['fin'] - (int)$ventana['inicio']);
                    }
                }
            }

            $resultado[$profId] = $minutosTotalesProf;
        }

        return $resultado;
    }

    /**
     * Genera el reporte administrativo completo aplicando validaciones, cálculos históricos
     * de importes, métricas de ocupación por profesional y desglose por servicio.
     *
     * @param array<string, mixed> $parametros
     * @return array<string, mixed>
     */
    public function consultarReporte(array $parametros = []): array
    {
        $validacion = $this->validarYNormalizarFiltros($parametros);

        try {
            $profesionalesCatalogo = $this->reporteRepository->listarProfesionalesCatalogo();
            $serviciosCatalogo = $this->reporteRepository->listarServiciosCatalogo();

            if (!$validacion['valido']) {
                return $this->construirRespuestaVaciaOError(
                    self::STATUS_INVALID,
                    422,
                    $validacion,
                    $profesionalesCatalogo,
                    $serviciosCatalogo,
                    ['error' => $validacion['errores']]
                );
            }

            $fechaInicio = $validacion['fecha_inicio'];
            $fechaFin = $validacion['fecha_fin'];
            $criterioFecha = $validacion['criterio_fecha'] ?? self::CRITERIO_FECHA_SERVICIO;
            $filtroProfesional = $validacion['profesional_filtro'];
            $profesionalIdInt = $validacion['profesional_id_int'];
            $servicioIdFiltro = $validacion['servicio_id'];

            $filas = $this->reporteRepository->obtenerFilasReservasEnRango(
                $fechaInicio,
                $fechaFin,
                $filtroProfesional,
                $servicioIdFiltro,
                $criterioFecha
            );

            $filasPagos = method_exists($this->reporteRepository, 'obtenerMovimientosFinancierosEnRango')
                ? $this->reporteRepository->obtenerMovimientosFinancierosEnRango(
                    $fechaInicio,
                    $fechaFin,
                    $criterioFecha,
                    $filtroProfesional,
                    $servicioIdFiltro
                )
                : [];

            $horarios = $this->reporteRepository->obtenerHorariosProfesionales($profesionalIdInt);
            $descansos = $this->reporteRepository->obtenerDescansosProfesionales($profesionalIdInt);
            $bloqueos = $this->reporteRepository->obtenerBloqueosEnRango($fechaInicio, $fechaFin, $profesionalIdInt);

            return $this->ensamblarReporte(
                $validacion,
                $profesionalesCatalogo,
                $serviciosCatalogo,
                $filas,
                $filasPagos,
                $horarios,
                $descansos,
                $bloqueos
            );
        } catch (PersistenceException $e) {
            return $this->construirRespuestaVaciaOError(
                self::STATUS_ERROR,
                500,
                $validacion,
                [],
                [],
                ['error' => ['No fue posible generar el reporte administrativo en este momento.']]
            );
        }
    }

    /**
     * Ensambla la estructura completa del reporte a partir de las filas e información de agenda.
     *
     * @param array<string, mixed> $validacion
     * @param array<int, array<string, mixed>> $profesionalesCatalogo
     * @param array<int, array<string, mixed>> $serviciosCatalogo
     * @param array<int, array<string, mixed>> $filas
     * @param array<int, array<string, mixed>> $filasPagos
     * @param array<int, array<string, mixed>> $horarios
     * @param array<int, array<string, mixed>> $descansos
     * @param array<int, array<string, mixed>> $bloqueos
     * @return array<string, mixed>
     */
    private function ensamblarReporte(
        array $validacion,
        array $profesionalesCatalogo,
        array $serviciosCatalogo,
        array $filas,
        array $filasPagos,
        array $horarios,
        array $descansos,
        array $bloqueos
    ): array {
        $fechaInicio = (string)$validacion['fecha_inicio'];
        $fechaFin = (string)$validacion['fecha_fin'];
        $criterioFecha = (string)($validacion['criterio_fecha'] ?? self::CRITERIO_FECHA_SERVICIO);
        $filtroProfesional = $validacion['profesional_filtro'];
        $profesionalIdInt = $validacion['profesional_id_int'];
        $servicioIdFiltro = $validacion['servicio_id'];

        // 1. Procesar movimientos financieros directamente desde la tabla duradera `pagos`
        $movimientosFinancieros = [];
        $cobrosConfirmadosTotal = 0.00;
        $cobrosConfirmadosReales = 0.00;
        $cobrosConfirmadosSimulados = 0.00;
        $reembolsosTotal = 0.00;
        $reembolsosReales = 0.00;
        $reembolsosSimulados = 0.00;
        $pagosPorProf = [];

        if (!empty($filasPagos)) {
            foreach ($filasPagos as $filaP) {
                $pId = (int)($filaP['pago_id'] ?? 0);
                if ($pId <= 0) {
                    continue;
                }

                if (!isset($movimientosFinancieros[$pId])) {
                    $prov = trim((string)($filaP['proveedor'] ?? 'mock')) ?: 'mock';
                    $esSimulado = ($prov === 'mock');
                    $montoP = isset($filaP['pago_monto']) && is_numeric($filaP['pago_monto'])
                        ? round(max(0.0, (float)$filaP['pago_monto']), 2)
                        : 0.00;
                    $estadoP = strtolower(trim((string)($filaP['pago_estado'] ?? 'pendiente')));

                    $nombreCli = trim(((string)($filaP['cliente_nombre'] ?? '')) . ' ' . ((string)($filaP['cliente_apellido'] ?? '')));
                    if ($nombreCli === '') {
                        $nombreCli = 'Cliente';
                    }

                    $pProfId = $filaP['profesional_id'] !== null && $filaP['profesional_id'] !== ''
                        ? (int)$filaP['profesional_id']
                        : null;

                    $fechaCobroStr = (string)($filaP['fecha_cobro'] ?? '');
                    $fechaReembolsoStr = isset($filaP['fecha_reembolso']) && $filaP['fecha_reembolso'] !== null
                        ? (string)$filaP['fecha_reembolso']
                        : ($filaP['reembolsado_at'] !== null ? (string)$filaP['reembolsado_at'] : null);

                    $movimientosFinancieros[$pId] = [
                        'id' => $pId,
                        'pago_id' => $pId,
                        'cita_id' => $filaP['cita_id'] !== null ? (int)$filaP['cita_id'] : null,
                        'cita_referencia_id' => (int)($filaP['cita_referencia_id'] ?? 0),
                        'referencia_pago' => (string)($filaP['referencia_pago'] ?? ''),
                        'proveedor' => $prov,
                        'es_simulado' => $esSimulado,
                        'monto' => $montoP,
                        'moneda' => (string)($filaP['pago_moneda'] ?? 'USD'),
                        'estado' => $estadoP,
                        'metodo_pago' => $filaP['metodo_pago'] !== null ? (string)$filaP['metodo_pago'] : null,
                        'fecha_cobro' => $fechaCobroStr,
                        'fecha_reembolso' => $fechaReembolsoStr,
                        'pagado_at' => $filaP['pagado_at'] !== null ? (string)$filaP['pagado_at'] : null,
                        'reembolsado_at' => $filaP['reembolsado_at'] !== null ? (string)$filaP['reembolsado_at'] : null,
                        'created_at' => (string)($filaP['pago_created_at'] ?? ''),
                        'cita_fecha' => $filaP['cita_fecha'] !== null ? (string)$filaP['cita_fecha'] : null,
                        'cita_atencion_efectiva' => ($filaP['cita_atencion_efectiva_at'] ?? null) !== null ? (string)$filaP['cita_atencion_efectiva_at'] : null,
                        'cita_hora' => $filaP['cita_hora'] !== null ? (string)$filaP['cita_hora'] : null,
                        'cita_estado' => (string)($filaP['cita_estado'] ?? 'confirmada'),
                        'profesional_id' => $pProfId,
                        'profesional_nombre' => $pProfId !== null
                            ? ((string)($filaP['profesional_nombre'] ?? ('Profesional #' . $pProfId)))
                            : 'Sin profesional asignado',
                        'cliente' => $nombreCli,
                        'cliente_email' => (string)($filaP['cliente_email'] ?? ''),
                        'cliente_telefono' => (string)($filaP['cliente_telefono'] ?? ''),
                        'servicios' => [],
                    ];

                    // Evaluación temporal de movimientos en el rango solicitado
                    $fechaCobroDia = substr($fechaCobroStr, 0, 10);
                    $fechaReembolsoDia = $fechaReembolsoStr !== null ? substr($fechaReembolsoStr, 0, 10) : $fechaCobroDia;

                    $aplicaCobroEnRango = true;
                    $aplicaReembolsoEnRango = true;

                    if ($criterioFecha === 'cobro') {
                        $aplicaCobroEnRango = ($fechaCobroDia >= $fechaInicio && $fechaCobroDia <= $fechaFin);
                        $aplicaReembolsoEnRango = ($fechaReembolsoDia >= $fechaInicio && $fechaReembolsoDia <= $fechaFin);
                    }

                    $importeCobroPeriodo = 0.00;
                    $importeReembolsoPeriodo = 0.00;

                    if ($estadoP === 'completado') {
                        $tipoMovimiento = 'COBRO';
                        if ($aplicaCobroEnRango) {
                            $importeCobroPeriodo = $montoP;
                        }
                    } elseif ($estadoP === 'reembolsado') {
                        $tipoMovimiento = 'REEMBOLSO';
                        if ($criterioFecha === 'cobro') {
                            if ($aplicaCobroEnRango) {
                                $importeCobroPeriodo = $montoP;
                            }
                        }
                        if ($aplicaReembolsoEnRango) {
                            $importeReembolsoPeriodo = $montoP;
                        }
                    } else {
                        $tipoMovimiento = strtoupper($estadoP);
                    }

                    $netoPeriodo = round($importeCobroPeriodo - $importeReembolsoPeriodo, 2);

                    $movimientosFinancieros[$pId]['tipo_movimiento'] = $tipoMovimiento;
                    $movimientosFinancieros[$pId]['importe_cobro_periodo'] = $importeCobroPeriodo;
                    $movimientosFinancieros[$pId]['importe_reembolso_periodo'] = $importeReembolsoPeriodo;
                    $movimientosFinancieros[$pId]['neto_periodo'] = $netoPeriodo;

                    if ($estadoP === 'completado') {
                        if ($aplicaCobroEnRango) {
                            $cobrosConfirmadosTotal = round($cobrosConfirmadosTotal + $montoP, 2);
                            if ($esSimulado) {
                                $cobrosConfirmadosSimulados = round($cobrosConfirmadosSimulados + $montoP, 2);
                            } else {
                                $cobrosConfirmadosReales = round($cobrosConfirmadosReales + $montoP, 2);
                            }
                            if ($pProfId !== null) {
                                $pagosPorProf[$pProfId]['cobros_confirmados'] = round(
                                    ($pagosPorProf[$pProfId]['cobros_confirmados'] ?? 0.00) + $montoP,
                                    2
                                );
                            }
                        }
                    } elseif ($estadoP === 'reembolsado') {
                        // En criterio por fecha de cobro contable, el cobro original y el reembolso se computan en sus fechas respectivas
                        if ($criterioFecha === 'cobro') {
                            if ($aplicaCobroEnRango) {
                                $cobrosConfirmadosTotal = round($cobrosConfirmadosTotal + $montoP, 2);
                                if ($esSimulado) {
                                    $cobrosConfirmadosSimulados = round($cobrosConfirmadosSimulados + $montoP, 2);
                                } else {
                                    $cobrosConfirmadosReales = round($cobrosConfirmadosReales + $montoP, 2);
                                }
                                if ($pProfId !== null) {
                                    $pagosPorProf[$pProfId]['cobros_confirmados'] = round(
                                        ($pagosPorProf[$pProfId]['cobros_confirmados'] ?? 0.00) + $montoP,
                                        2
                                    );
                                }
                            }
                        }

                        // El reembolso se computa siempre que su fecha/evento corresponda al rango evaluado
                        if ($aplicaReembolsoEnRango) {
                            $reembolsosTotal = round($reembolsosTotal + $montoP, 2);
                            if ($esSimulado) {
                                $reembolsosSimulados = round($reembolsosSimulados + $montoP, 2);
                            } else {
                                $reembolsosReales = round($reembolsosReales + $montoP, 2);
                            }
                            if ($pProfId !== null) {
                                $pagosPorProf[$pProfId]['reembolsos'] = round(
                                    ($pagosPorProf[$pProfId]['reembolsos'] ?? 0.00) + $montoP,
                                    2
                                );
                            }
                        }
                    }
                }

                $csId = $filaP['citasservicio_id'] ?? null;
                if ($csId !== null) {
                    $servNombre = trim((string)($filaP['servicio_nombre'] ?? ''));
                    if ($servNombre === '') {
                        $servNombre = 'Servicio eliminado';
                    }
                    $servPrecio = isset($filaP['servicio_precio']) && is_numeric($filaP['servicio_precio'])
                        ? round(max(0.0, (float)$filaP['servicio_precio']), 2)
                        : 0.00;
                    $servDur = isset($filaP['servicio_duracion_minutos']) && is_numeric($filaP['servicio_duracion_minutos'])
                        ? max(0, (int)$filaP['servicio_duracion_minutos'])
                        : 0;

                    $movimientosFinancieros[$pId]['servicios'][] = [
                        'citasservicio_id' => (int)$csId,
                        'servicio_id' => $filaP['servicio_id'] !== null ? (int)$filaP['servicio_id'] : null,
                        'nombre' => $servNombre,
                        'precio' => $servPrecio,
                        'duracion_minutos' => $servDur,
                        'es_eliminado_catalogo' => ($filaP['servicio_catalogo_id'] === null),
                    ];
                }
            }
        }

        $netoCobradoTotal = round($cobrosConfirmadosTotal - $reembolsosTotal, 2);
        $netoCobradoReal = round($cobrosConfirmadosReales - $reembolsosReales, 2);
        $netoCobradoSimulado = round($cobrosConfirmadosSimulados - $reembolsosSimulados, 2);

        // 2. Agrupar filas operativas de citas y servicios
        $citasAgrupadas = [];
        foreach ($filas as $fila) {
            $citaId = (int)($fila['cita_id'] ?? 0);
            if ($citaId <= 0) {
                continue;
            }

            if (!isset($citasAgrupadas[$citaId])) {
                $profId = $fila['profesional_id'] !== null && $fila['profesional_id'] !== ''
                    ? (int)$fila['profesional_id']
                    : null;
                $nombreCliente = trim(
                    ((string)($fila['cliente_nombre'] ?? '')) . ' ' . ((string)($fila['cliente_apellido'] ?? ''))
                );
                if ($nombreCliente === '') {
                    $nombreCliente = 'Cliente histórico';
                }

                $estadoCita = strtolower(trim((string)($fila['cita_estado'] ?? 'confirmada')));
                $esCancelada = ($estadoCita === 'cancelada');

                $citasAgrupadas[$citaId] = [
                    'id' => $citaId,
                    'fecha' => (string)($fila['fecha'] ?? ''),
                    'fecha_programada' => (string)($fila['fecha_programada'] ?? $fila['fecha'] ?? ''),
                    'atencion_efectiva_at' => ($fila['atencion_efectiva_at'] ?? null) !== null ? (string)$fila['atencion_efectiva_at'] : null,
                    'hora' => (string)($fila['hora'] ?? ''),
                    'hora_inicio' => $fila['hora_inicio'] !== null ? (string)$fila['hora_inicio'] : null,
                    'hora_fin' => $fila['hora_fin'] !== null ? (string)$fila['hora_fin'] : null,
                    'cita_duracion_total_minutos' => $fila['cita_duracion_total_minutos'] !== null
                        ? (int)$fila['cita_duracion_total_minutos']
                        : null,
                    'usuario_id' => $fila['usuario_id'] !== null ? (int)$fila['usuario_id'] : null,
                    'cliente' => $nombreCliente,
                    'cliente_email' => (string)($fila['cliente_email'] ?? ''),
                    'cliente_telefono' => (string)($fila['cliente_telefono'] ?? ''),
                    'profesional_id' => $profId,
                    'profesional_nombre' => $profId !== null
                        ? ((string)($fila['profesional_nombre'] ?? ('Profesional #' . $profId)))
                        : 'Sin profesional asignado (cita histórica)',
                    'es_historica_sin_profesional' => $profId === null,
                    'cita_estado' => $estadoCita,
                    'es_cancelada' => $esCancelada,
                    'estado_pago_raw' => $fila['estado_pago'] ?? null,
                    'monto_pagado_raw' => $fila['monto_pagado'] ?? null,
                    'servicios' => [],
                ];
            }

            $csId = $fila['citasservicio_id'] ?? null;
            if ($csId !== null) {
                $servId = $fila['servicio_id'] !== null && $fila['servicio_id'] !== ''
                    ? (int)$fila['servicio_id']
                    : null;
                $servNombre = trim((string)($fila['servicio_nombre'] ?? ''));
                if ($servNombre === '') {
                    $servNombre = 'Servicio eliminado';
                }
                $servPrecio = isset($fila['servicio_precio']) && is_numeric($fila['servicio_precio'])
                    ? round(max(0.0, (float)$fila['servicio_precio']), 2)
                    : 0.00;
                $servDuracion = isset($fila['servicio_duracion_minutos']) && is_numeric($fila['servicio_duracion_minutos'])
                    ? max(0, (int)$fila['servicio_duracion_minutos'])
                    : 0;

                $esEliminadoCatalogo = ($servId === null)
                    || (array_key_exists('servicio_catalogo_id', $fila) && $fila['servicio_catalogo_id'] === null);

                $citasAgrupadas[$citaId]['servicios'][] = [
                    'citasservicio_id' => (int)$csId,
                    'servicio_id' => $servId,
                    'nombre' => $servNombre,
                    'precio' => $servPrecio,
                    'duracion_minutos' => $servDuracion,
                    'es_eliminado_catalogo' => $esEliminadoCatalogo,
                ];
            }
        }

        // 3. Calcular totales operacionales por cita, desglose por servicio y acumuladores por profesional
        $reservasDetalle = [];
        $mapaServicios = [];
        $ocupacionPorProf = [];
        $acumuladorHistoricasSinProf = [
            'total_citas' => 0,
            'minutos_ocupados' => 0,
            'importe_reservado' => 0.00,
            'importe_total_citas' => 0.00,
            'ingreso_pagado' => 0.00,
        ];

        $importeReservadoTotal = 0.00;
        $importeBrutoCitasTotal = 0.00;
        $ingresoPagadoLegacyTotal = 0.00;
        $totalServiciosReservados = 0;
        $totalCitasActivas = 0;
        $totalCitasCanceladas = 0;

        foreach ($citasAgrupadas as $cita) {
            $esCancelada = !empty($cita['es_cancelada']);
            if ($esCancelada) {
                $totalCitasCanceladas++;
            } else {
                $totalCitasActivas++;
            }

            $importeTotalCita = 0.00;
            $subtotalServicioFiltrado = 0.00;
            $sumaDuracionServiciosCita = 0;
            $sumaDuracionServicioFiltrado = 0;
            $serviciosTextoPartes = [];

            foreach ($cita['servicios'] as $srv) {
                $precioSrv = (float)$srv['precio'];
                $durSrv = (int)$srv['duracion_minutos'];
                $importeTotalCita += $precioSrv;
                $sumaDuracionServiciosCita += $durSrv;

                $coincideFiltroServicio = ($servicioIdFiltro === null) || ($srv['servicio_id'] === $servicioIdFiltro);
                if ($coincideFiltroServicio) {
                    $subtotalServicioFiltrado += $precioSrv;
                    $sumaDuracionServicioFiltrado += $durSrv;
                    if (!$esCancelada) {
                        $totalServiciosReservados++;
                    }
                }

                $durTexto = $durSrv > 0 ? " ({$durSrv} min)" : '';
                $serviciosTextoPartes[] = sprintf('%s ($%s%s)', $srv['nombre'], number_format($precioSrv, 2, '.', ''), $durTexto);
            }

            $importeTotalCita = round($importeTotalCita, 2);
            $subtotalServicioFiltrado = round($subtotalServicioFiltrado, 2);
            $importeAtribuible = $servicioIdFiltro !== null ? $subtotalServicioFiltrado : $importeTotalCita;

            // Determinar duración de la cita
            $duracionCitaMinutos = 0;
            if ($cita['cita_duracion_total_minutos'] !== null && $cita['cita_duracion_total_minutos'] > 0) {
                $duracionCitaMinutos = (int)$cita['cita_duracion_total_minutos'];
            } elseif ($cita['hora_inicio'] !== null && $cita['hora_fin'] !== null) {
                $mIni = self::horaAMinutos($cita['hora_inicio']);
                $mFin = self::horaAMinutos($cita['hora_fin']);
                if ($mFin > $mIni) {
                    $duracionCitaMinutos = $mFin - $mIni;
                }
            }
            if ($duracionCitaMinutos <= 0 && $sumaDuracionServiciosCita > 0) {
                $duracionCitaMinutos = $sumaDuracionServiciosCita;
            }

            $montoPagadoEvaluar = $cita['monto_pagado_raw'];
            if ($servicioIdFiltro !== null && $montoPagadoEvaluar !== null && is_numeric($montoPagadoEvaluar)) {
                $montoPagadoEvaluar = min((float)$montoPagadoEvaluar, $importeAtribuible);
            }

            $infoPago = $this->evaluarEstadoPagoCita([
                'estado_pago' => $cita['estado_pago_raw'],
                'monto_pagado' => $montoPagadoEvaluar,
            ], $importeAtribuible);

            $ingresoPagadoCita = $infoPago['ingreso_pagado'];

            // Actualizar desglose por servicio (solo para citas activas para métricas operativas)
            if (!$esCancelada) {
                foreach ($cita['servicios'] as $srv) {
                    if ($servicioIdFiltro !== null && $srv['servicio_id'] !== $servicioIdFiltro) {
                        continue;
                    }
                    $claveSrv = $srv['servicio_id'] !== null
                        ? 'id:' . $srv['servicio_id']
                        : 'hist:' . mb_strtolower($srv['nombre'], 'UTF-8');

                    if (!isset($mapaServicios[$claveSrv])) {
                        $mapaServicios[$claveSrv] = [
                            'servicio_id' => $srv['servicio_id'],
                            'servicio_nombre' => $srv['nombre'],
                            'es_eliminado_catalogo' => $srv['es_eliminado_catalogo'],
                            'total_reservas' => 0,
                            'minutos_reservados' => 0,
                            'importe_reservado' => 0.00,
                            'ingreso_pagado' => 0.00,
                        ];
                    }

                    $mapaServicios[$claveSrv]['total_reservas']++;
                    $mapaServicios[$claveSrv]['minutos_reservados'] += (int)$srv['duracion_minutos'];
                    $mapaServicios[$claveSrv]['importe_reservado'] = round(
                        $mapaServicios[$claveSrv]['importe_reservado'] + (float)$srv['precio'],
                        2
                    );
                    if ($infoPago['estado_pago'] === self::ESTADO_PAGO_PAGADO) {
                        $mapaServicios[$claveSrv]['ingreso_pagado'] = round(
                            $mapaServicios[$claveSrv]['ingreso_pagado'] + (float)$srv['precio'],
                            2
                        );
                    }
                }
            }

            // Formatear horario legible
            $hInicioCorta = $cita['hora_inicio'] !== null && trim($cita['hora_inicio']) !== ''
                ? substr(trim($cita['hora_inicio']), 0, 5)
                : substr(trim($cita['hora']), 0, 5);
            $hFinCorta = $cita['hora_fin'] !== null && trim($cita['hora_fin']) !== ''
                ? substr(trim($cita['hora_fin']), 0, 5)
                : null;
            $horarioTexto = $hFinCorta !== null ? "{$hInicioCorta} - {$hFinCorta}" : $hInicioCorta;

            $profId = $cita['profesional_id'];
            if (!$esCancelada) {
                if ($profId !== null) {
                    if (!isset($ocupacionPorProf[$profId])) {
                        $ocupacionPorProf[$profId] = [
                            'profesional_nombre' => $cita['profesional_nombre'],
                            'total_citas' => 0,
                            'minutos_ocupados' => 0,
                            'minutos_servicio_filtrado' => 0,
                            'importe_reservado' => 0.00,
                            'importe_total_citas' => 0.00,
                            'ingreso_pagado' => 0.00,
                        ];
                    }
                    $ocupacionPorProf[$profId]['total_citas']++;
                    $ocupacionPorProf[$profId]['minutos_ocupados'] += $duracionCitaMinutos;
                    $ocupacionPorProf[$profId]['minutos_servicio_filtrado'] += $sumaDuracionServicioFiltrado;
                    $ocupacionPorProf[$profId]['importe_reservado'] = round(
                        $ocupacionPorProf[$profId]['importe_reservado'] + $importeAtribuible,
                        2
                    );
                    $ocupacionPorProf[$profId]['importe_total_citas'] = round(
                        $ocupacionPorProf[$profId]['importe_total_citas'] + $importeTotalCita,
                        2
                    );
                    $ocupacionPorProf[$profId]['ingreso_pagado'] = round(
                        $ocupacionPorProf[$profId]['ingreso_pagado'] + $ingresoPagadoCita,
                        2
                    );
                } else {
                    $acumuladorHistoricasSinProf['total_citas']++;
                    $acumuladorHistoricasSinProf['minutos_ocupados'] += $duracionCitaMinutos;
                    $acumuladorHistoricasSinProf['importe_reservado'] = round(
                        $acumuladorHistoricasSinProf['importe_reservado'] + $importeAtribuible,
                        2
                    );
                    $acumuladorHistoricasSinProf['importe_total_citas'] = round(
                        $acumuladorHistoricasSinProf['importe_total_citas'] + $importeTotalCita,
                        2
                    );
                    $acumuladorHistoricasSinProf['ingreso_pagado'] = round(
                        $acumuladorHistoricasSinProf['ingreso_pagado'] + $ingresoPagadoCita,
                        2
                    );
                }

                $importeReservadoTotal = round($importeReservadoTotal + $importeAtribuible, 2);
                $importeBrutoCitasTotal = round($importeBrutoCitasTotal + $importeTotalCita, 2);
            }

            $ingresoPagadoLegacyTotal = round($ingresoPagadoLegacyTotal + $ingresoPagadoCita, 2);

            $reservasDetalle[] = [
                'id' => $cita['id'],
                'fecha' => $cita['fecha'],
                'fecha_programada' => $cita['fecha_programada'] ?? $cita['fecha'],
                'atencion_efectiva_at' => $cita['atencion_efectiva_at'] ?? null,
                'hora' => $cita['hora'],
                'hora_inicio' => $cita['hora_inicio'],
                'hora_fin' => $cita['hora_fin'],
                'horario' => $horarioTexto,
                'cliente' => $cita['cliente'],
                'cliente_email' => $cita['cliente_email'],
                'cliente_telefono' => $cita['cliente_telefono'],
                'profesional_id' => $profId,
                'profesional_nombre' => $cita['profesional_nombre'],
                'es_historica_sin_profesional' => $cita['es_historica_sin_profesional'],
                'cita_estado' => $cita['cita_estado'],
                'es_cancelada' => $esCancelada,
                'etiqueta_estado_cita' => [
                    'cancelada' => 'Cancelada', 'completada' => 'Atendida', 'no_asistio' => 'No asistió',
                    'en_servicio' => 'En servicio', 'pendiente' => 'Pendiente',
                ][$estadoCita] ?? 'Confirmada',
                'servicios' => $cita['servicios'],
                'servicios_resumen' => implode(', ', $serviciosTextoPartes),
                'duracion_total_minutos' => $duracionCitaMinutos,
                'duracion_servicio_filtrado_minutos' => $sumaDuracionServicioFiltrado,
                'importe_total_cita' => $importeTotalCita,
                'subtotal_servicio_filtrado' => $subtotalServicioFiltrado,
                'importe_reservado' => $importeAtribuible,
                'ingreso_pagado' => $ingresoPagadoCita,
                'estado_pago' => $infoPago['estado_pago'],
                'etiqueta_estado_pago' => $infoPago['etiqueta_estado_pago'],
            ];
        }

        // Si tenemos movimientos financieros duraderos desde pagos, prevalecen con exactitud contable
        // (salvo cuando hay un filtro específico de servicio en el que el cobro total del pago excede los servicios filtrados)
        if (!empty($movimientosFinancieros)) {
            if ($servicioIdFiltro !== null) {
                $ingresoPagadoFinal = $ingresoPagadoLegacyTotal;
            } else {
                $ingresoPagadoFinal = $cobrosConfirmadosTotal;
            }
        } else {
            $ingresoPagadoFinal = $ingresoPagadoLegacyTotal;
            $cobrosConfirmadosTotal = $ingresoPagadoLegacyTotal;
            $netoCobradoTotal = $ingresoPagadoLegacyTotal;
        }

        // 4. Calcular minutos disponibles y porcentaje de ocupación por profesional
        $profesionalesParaOcupacion = [];
        if ($filtroProfesional !== 'sin_profesional') {
            foreach ($profesionalesCatalogo as $profCat) {
                if ($profesionalIdInt !== null && (int)$profCat['id'] !== $profesionalIdInt) {
                    continue;
                }
                $profesionalesParaOcupacion[(int)$profCat['id']] = $profCat;
            }
            foreach ($ocupacionPorProf as $pId => $infoOcup) {
                if (!isset($profesionalesParaOcupacion[$pId])) {
                    $profesionalesParaOcupacion[$pId] = [
                        'id' => $pId,
                        'nombre' => $infoOcup['profesional_nombre'],
                        'activo' => 0,
                    ];
                }
            }
        }

        $minutosDisponiblesMapa = $this->calcularMinutosDisponiblesPorProfesional(
            $fechaInicio,
            $fechaFin,
            array_values($profesionalesParaOcupacion),
            $horarios,
            $descansos,
            $bloqueos
        );

        $resumenProfesionales = [];
        $minutosOcupadosGlobalConProf = 0;
        $minutosDisponiblesGlobalConProf = 0;

        foreach ($profesionalesParaOcupacion as $profId => $profInfo) {
            $datosOcup = $ocupacionPorProf[$profId] ?? [
                'total_citas' => 0,
                'minutos_ocupados' => 0,
                'minutos_servicio_filtrado' => 0,
                'importe_reservado' => 0.00,
                'importe_total_citas' => 0.00,
                'ingreso_pagado' => 0.00,
            ];

            $minOcup = (int)$datosOcup['minutos_ocupados'];
            $minDisp = (int)($minutosDisponiblesMapa[$profId] ?? 0);
            $sinTurno = $minDisp <= 0;
            $porcentaje = !$sinTurno ? round(($minOcup / $minDisp) * 100, 2) : 0.00;
            $activo = (int)($profInfo['activo'] ?? 1) === 1;

            $minutosOcupadosGlobalConProf += $minOcup;
            $minutosDisponiblesGlobalConProf += $minDisp;

            $cobrosProf = $pagosPorProf[$profId]['cobros_confirmados'] ?? (float)$datosOcup['ingreso_pagado'];
            $reembolsosProf = $pagosPorProf[$profId]['reembolsos'] ?? 0.00;
            $netoProf = round($cobrosProf - $reembolsosProf, 2);

            $resumenProfesionales[] = [
                'profesional_id' => $profId,
                'profesional_nombre' => (string)$profInfo['nombre'],
                'activo' => $activo,
                'es_historico_sin_profesional' => false,
                'total_citas' => (int)$datosOcup['total_citas'],
                'minutos_ocupados' => $minOcup,
                'minutos_servicio_filtrado' => (int)$datosOcup['minutos_servicio_filtrado'],
                'minutos_disponibles' => $minDisp,
                'horas_ocupadas' => round($minOcup / 60, 2),
                'horas_disponibles' => round($minDisp / 60, 2),
                'porcentaje_ocupacion' => $porcentaje,
                'sin_turno_disponible' => $sinTurno,
                'etiqueta_ocupacion' => $sinTurno
                    ? '0.00% (Sin turno disponible)'
                    : number_format($porcentaje, 2, '.', '') . '%',
                'importe_reservado' => round((float)$datosOcup['importe_reservado'], 2),
                'importe_total_citas' => round((float)$datosOcup['importe_total_citas'], 2),
                'ingreso_pagado' => round($cobrosProf, 2),
                'reembolsos' => round($reembolsosProf, 2),
                'neto_cobrado' => round($netoProf, 2),
            ];
        }

        if ($acumuladorHistoricasSinProf['total_citas'] > 0 || $filtroProfesional === 'sin_profesional') {
            $minHist = (int)$acumuladorHistoricasSinProf['minutos_ocupados'];
            $resumenProfesionales[] = [
                'profesional_id' => null,
                'profesional_nombre' => 'Sin profesional asignado (citas históricas)',
                'activo' => false,
                'es_historico_sin_profesional' => true,
                'total_citas' => (int)$acumuladorHistoricasSinProf['total_citas'],
                'minutos_ocupados' => $minHist,
                'minutos_servicio_filtrado' => $minHist,
                'minutos_disponibles' => 0,
                'horas_ocupadas' => round($minHist / 60, 2),
                'horas_disponibles' => 0.00,
                'porcentaje_ocupacion' => 0.00,
                'sin_turno_disponible' => true,
                'etiqueta_ocupacion' => 'No aplica (cita histórica)',
                'importe_reservado' => round((float)$acumuladorHistoricasSinProf['importe_reservado'], 2),
                'importe_total_citas' => round((float)$acumuladorHistoricasSinProf['importe_total_citas'], 2),
                'ingreso_pagado' => round((float)$acumuladorHistoricasSinProf['ingreso_pagado'], 2),
                'reembolsos' => 0.00,
                'neto_cobrado' => round((float)$acumuladorHistoricasSinProf['ingreso_pagado'], 2),
            ];
        }

        $resumenServicios = array_values($mapaServicios);
        usort($resumenServicios, static function (array $a, array $b): int {
            $cmpImporte = $b['importe_reservado'] <=> $a['importe_reservado'];
            if ($cmpImporte !== 0) {
                return $cmpImporte;
            }
            return strcmp((string)$a['servicio_nombre'], (string)$b['servicio_nombre']);
        });

        $porcentajeOcupacionGlobal = $minutosDisponiblesGlobalConProf > 0
            ? round(($minutosOcupadosGlobalConProf / $minutosDisponiblesGlobalConProf) * 100, 2)
            : 0.00;

        $totalCitasTotal = count($reservasDetalle);
        $status = ($totalCitasTotal > 0 || !empty($movimientosFinancieros)) ? self::STATUS_OK : self::STATUS_EMPTY;

        return [
            'status' => $status,
            'http_code' => 200,
            'alertas' => [],
            'filtros' => [
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'criterio_fecha' => $criterioFecha,
                'profesional_id' => $filtroProfesional ?? '',
                'servicio_id' => $servicioIdFiltro !== null ? (string)$servicioIdFiltro : '',
                'dias_rango' => $validacion['dias_rango'],
                'tiene_filtro_servicio' => $servicioIdFiltro !== null,
            ],
            'catalogos' => [
                'profesionales' => $profesionalesCatalogo,
                'servicios' => $serviciosCatalogo,
            ],
            'resumen_global' => [
                'total_citas' => $totalCitasActivas,
                'total_citas_activas' => $totalCitasActivas,
                'total_citas_canceladas' => $totalCitasCanceladas,
                'total_citas_con_profesional' => $totalCitasActivas - (int)$acumuladorHistoricasSinProf['total_citas'],
                'total_citas_historicas_sin_profesional' => (int)$acumuladorHistoricasSinProf['total_citas'],
                'total_servicios_reservados' => $totalServiciosReservados,
                'minutos_ocupados_profesionales' => $minutosOcupadosGlobalConProf,
                'minutos_disponibles_profesionales' => $minutosDisponiblesGlobalConProf,
                'horas_ocupadas_profesionales' => round($minutosOcupadosGlobalConProf / 60, 2),
                'horas_disponibles_profesionales' => round($minutosDisponiblesGlobalConProf / 60, 2),
                'porcentaje_ocupacion_global' => $porcentajeOcupacionGlobal,
                'sin_turno_disponible_global' => $minutosDisponiblesGlobalConProf <= 0,
                'importe_reservado_total' => $importeReservadoTotal,
                'importe_bruto_citas_filtradas' => $importeBrutoCitasTotal,
                'ingreso_pagado_total' => $ingresoPagadoFinal,
                'saldo_pendiente_cobro_total' => round(max(0.0, $importeReservadoTotal - $ingresoPagadoFinal), 2),
                'cobros_confirmados_total' => $cobrosConfirmadosTotal,
                'cobros_confirmados_reales' => $cobrosConfirmadosReales,
                'cobros_confirmados_simulados' => $cobrosConfirmadosSimulados,
                'reembolsos_total' => $reembolsosTotal,
                'reembolsos_reales' => $reembolsosReales,
                'reembolsos_simulados' => $reembolsosSimulados,
                'neto_cobrado_total' => $netoCobradoTotal,
                'neto_cobrado_real' => $netoCobradoReal,
                'neto_cobrado_simulado' => $netoCobradoSimulado,
                'nota_pagos' => 'Importe reservado corresponde al valor histórico comprometido en citas activas; cobros confirmados y reembolsos provienen de la tabla de pagos independientemente del estado de la cita ($0.00 mientras el pago esté pendiente).',
            ],
            'resumen_profesionales' => $resumenProfesionales,
            'resumen_servicios' => $resumenServicios,
            'movimientos_financieros' => array_values($movimientosFinancieros),
            'reservas' => $reservasDetalle,
        ];
    }

    /**
     * Construye una respuesta segura con métricas en cero cuando la validación falla o hay error de persistencia.
     *
     * @param string $status
     * @param int $httpCode
     * @param array<string, mixed> $validacion
     * @param array<int, array<string, mixed>> $profesionalesCatalogo
     * @param array<int, array<string, mixed>> $serviciosCatalogo
     * @param array<string, array<int, string>> $alertas
     * @return array<string, mixed>
     */
    private function construirRespuestaVaciaOError(
        string $status,
        int $httpCode,
        array $validacion,
        array $profesionalesCatalogo,
        array $serviciosCatalogo,
        array $alertas
    ): array {
        return [
            'status' => $status,
            'http_code' => $httpCode,
            'alertas' => $alertas,
            'filtros' => [
                'fecha_inicio' => (string)($validacion['fecha_inicio'] ?? ''),
                'fecha_fin' => (string)($validacion['fecha_fin'] ?? ''),
                'criterio_fecha' => (string)($validacion['criterio_fecha'] ?? self::CRITERIO_FECHA_SERVICIO),
                'profesional_id' => (string)($validacion['profesional_filtro'] ?? ''),
                'servicio_id' => ($validacion['servicio_id'] ?? null) !== null ? (string)$validacion['servicio_id'] : '',
                'dias_rango' => (int)($validacion['dias_rango'] ?? 0),
                'tiene_filtro_servicio' => ($validacion['servicio_id'] ?? null) !== null,
            ],
            'catalogos' => [
                'profesionales' => $profesionalesCatalogo,
                'servicios' => $serviciosCatalogo,
            ],
            'resumen_global' => [
                'total_citas' => 0,
                'total_citas_activas' => 0,
                'total_citas_canceladas' => 0,
                'total_citas_con_profesional' => 0,
                'total_citas_historicas_sin_profesional' => 0,
                'total_servicios_reservados' => 0,
                'minutos_ocupados_profesionales' => 0,
                'minutos_disponibles_profesionales' => 0,
                'horas_ocupadas_profesionales' => 0.00,
                'horas_disponibles_profesionales' => 0.00,
                'porcentaje_ocupacion_global' => 0.00,
                'sin_turno_disponible_global' => true,
                'importe_reservado_total' => 0.00,
                'importe_bruto_citas_filtradas' => 0.00,
                'ingreso_pagado_total' => 0.00,
                'saldo_pendiente_cobro_total' => 0.00,
                'cobros_confirmados_total' => 0.00,
                'cobros_confirmados_reales' => 0.00,
                'cobros_confirmados_simulados' => 0.00,
                'reembolsos_total' => 0.00,
                'reembolsos_reales' => 0.00,
                'reembolsos_simulados' => 0.00,
                'neto_cobrado_total' => 0.00,
                'neto_cobrado_real' => 0.00,
                'neto_cobrado_simulado' => 0.00,
                'nota_pagos' => 'Importe reservado corresponde al valor histórico comprometido en citas activas; ingreso pagado refleja únicamente cobros confirmados ($0.00 mientras el pago esté pendiente).',
            ],
            'resumen_profesionales' => [],
            'resumen_servicios' => [],
            'movimientos_financieros' => [],
            'reservas' => [],
        ];
    }

    /**
     * Neutraliza vectores de *CSV Injection / Formula Injection* (`=`, `+`, `-`, `@`, `\t`, `\r`, `\n`)
     * anteponiendo una comilla simple `'`, preservando intactos los valores numéricos no negativos.
     *
     * @param mixed $valor
     * @return string
     */
    public static function sanearCeldaCsv($valor): string
    {
        if ($valor === null) {
            return '';
        }

        if ((is_int($valor) || is_float($valor)) && $valor >= 0) {
            return (string)$valor;
        }

        $texto = (string)$valor;
        if ($texto === '') {
            return '';
        }

        if (preg_match('/^\d+(\.\d+)?$/', $texto)) {
            return $texto;
        }

        $peligrosos = ['=', '+', '-', '@', "\t", "\r", "\n", "\f", "\v"];
        $primerChar = $texto[0];
        $sinEspaciosIniciales = ltrim($texto, " \t\n\r\0\x0B\f");
        $primerCharVisible = $sinEspaciosIniciales !== '' ? $sinEspaciosIniciales[0] : '';

        if (in_array($primerChar, $peligrosos, true) || in_array($primerCharVisible, $peligrosos, true)) {
            return "'" . $texto;
        }

        return $texto;
    }

    /**
     * Genera el contenido CSV seguro del reporte administrativo con codificación UTF-8 (BOM incluido),
     * desglosando Resumen General, Ocupación por Profesional, Desglose por Servicio, Movimientos Financieros
     * y Detalle de Reservas con snapshots históricos inmutables.
     *
     * @param array<string, mixed> $reporte Estructura devuelta por `consultarReporte()`.
     * @return string Contenido CSV listo para descarga.
     */
    public function generarCsv(array $reporte): string
    {
        $fp = fopen('php://temp', 'r+');
        if ($fp === false) {
            return '';
        }

        // BOM UTF-8 para compatibilidad directa con Excel y LibreOffice
        fwrite($fp, "\xEF\xBB\xBF");

        $escribirFila = static function (array $celdas) use ($fp): void {
            $filaSaneada = array_map([self::class, 'sanearCeldaCsv'], $celdas);
            fputcsv($fp, $filaSaneada);
        };

        $filtros = $reporte['filtros'] ?? [];
        $global = $reporte['resumen_global'] ?? [];

        // 1. Resumen General
        $escribirFila(['REPORTE ADMINISTRATIVO DE RESERVAS Y OCUPACION - APPSALON']);
        $escribirFila([
            'Fecha Inicio',
            'Fecha Fin',
            'Criterio Fecha',
            'Filtro Profesional',
            'Filtro Servicio',
            'Total Citas Activas',
            'Total Citas Canceladas',
            'Minutos Ocupados',
            'Minutos Disponibles Turno',
            'Ocupacion Global (%)',
            'Importe Reservado Total (USD)',
            'Ingreso Pagado Total (USD)',
            'Cobros Confirmados Total (USD)',
            'Reembolsos Total (USD)',
            'Neto Cobrado Total (USD)',
            'Cobros Simulados Mock (USD)',
            'Saldo Pendiente de Cobro (USD)',
        ]);
        $escribirFila([
            (string)($filtros['fecha_inicio'] ?? ''),
            (string)($filtros['fecha_fin'] ?? ''),
            (string)($filtros['criterio_fecha'] ?? 'programacion'),
            (string)(($filtros['profesional_id'] ?? '') !== '' ? $filtros['profesional_id'] : 'Todos'),
            (string)(($filtros['servicio_id'] ?? '') !== '' ? $filtros['servicio_id'] : 'Todos'),
            (int)($global['total_citas'] ?? 0),
            (int)($global['total_citas_canceladas'] ?? 0),
            (int)($global['minutos_ocupados_profesionales'] ?? 0),
            (int)($global['minutos_disponibles_profesionales'] ?? 0),
            number_format((float)($global['porcentaje_ocupacion_global'] ?? 0), 2, '.', ''),
            number_format((float)($global['importe_reservado_total'] ?? 0), 2, '.', ''),
            number_format((float)($global['ingreso_pagado_total'] ?? 0), 2, '.', ''),
            number_format((float)($global['cobros_confirmados_total'] ?? 0), 2, '.', ''),
            number_format((float)($global['reembolsos_total'] ?? 0), 2, '.', ''),
            number_format((float)($global['neto_cobrado_total'] ?? 0), 2, '.', ''),
            number_format((float)($global['cobros_confirmados_simulados'] ?? 0), 2, '.', ''),
            number_format((float)($global['saldo_pendiente_cobro_total'] ?? 0), 2, '.', ''),
        ]);
        $escribirFila([]);

        // 2. Ocupación e Importes por Profesional
        $escribirFila(['OCUPACION E IMPORTES POR PROFESIONAL']);
        $escribirFila([
            'Profesional ID',
            'Profesional',
            'Estado Agenda',
            'Total Citas',
            'Minutos Ocupados',
            'Minutos Disponibles Turno',
            'Horas Ocupadas',
            'Horas Disponibles Turno',
            'Ocupacion (%)',
            'Importe Reservado (USD)',
            'Ingreso Pagado (USD)',
            'Reembolsos (USD)',
            'Neto Cobrado (USD)',
        ]);
        foreach ($reporte['resumen_profesionales'] ?? [] as $prof) {
            $escribirFila([
                $prof['profesional_id'] !== null ? (string)$prof['profesional_id'] : 'SIN_PROFESIONAL',
                (string)($prof['profesional_nombre'] ?? ''),
                !empty($prof['es_historico_sin_profesional'])
                    ? 'Historica sin profesional'
                    : (!empty($prof['activo']) ? 'Activo' : 'Inactivo'),
                (int)($prof['total_citas'] ?? 0),
                (int)($prof['minutos_ocupados'] ?? 0),
                (int)($prof['minutos_disponibles'] ?? 0),
                number_format((float)($prof['horas_ocupadas'] ?? 0), 2, '.', ''),
                number_format((float)($prof['horas_disponibles'] ?? 0), 2, '.', ''),
                number_format((float)($prof['porcentaje_ocupacion'] ?? 0), 2, '.', ''),
                number_format((float)($prof['importe_reservado'] ?? 0), 2, '.', ''),
                number_format((float)($prof['ingreso_pagado'] ?? 0), 2, '.', ''),
                number_format((float)($prof['reembolsos'] ?? 0), 2, '.', ''),
                number_format((float)($prof['neto_cobrado'] ?? 0), 2, '.', ''),
            ]);
        }
        $escribirFila([]);

        // 3. Desglose por Servicio (Snapshot Histórico Inmutable)
        $escribirFila(['DESGLOSE POR SERVICIO (DATOS HISTORICOS DE RESERVA)']);
        $escribirFila([
            'Servicio ID',
            'Servicio',
            'Veces Reservado',
            'Minutos Reservados',
            'Importe Reservado (USD)',
            'Ingreso Pagado (USD)',
        ]);
        foreach ($reporte['resumen_servicios'] ?? [] as $srv) {
            $escribirFila([
                (!empty($srv['es_eliminado_catalogo']) || $srv['servicio_id'] === null) ? 'ELIMINADO_CATALOGO' : (string)$srv['servicio_id'],
                (string)($srv['servicio_nombre'] ?? ''),
                (int)($srv['total_reservas'] ?? 0),
                (int)($srv['minutos_reservados'] ?? 0),
                number_format((float)($srv['importe_reservado'] ?? 0), 2, '.', ''),
                number_format((float)($srv['ingreso_pagado'] ?? 0), 2, '.', ''),
            ]);
        }
        $escribirFila([]);

        // 4. Movimientos Financieros (Desacoplados desde la tabla `pagos`)
        if (!empty($reporte['movimientos_financieros'])) {
            $escribirFila(['MOVIMIENTOS FINANCIEROS (HISTORIAL DE COBROS Y REEMBOLSOS)']);
            $escribirFila([
                'Pago ID',
                'Referencia Pago',
                'Tipo Movimiento',
                'Proveedor',
                'Es Simulado',
                'Monto (USD)',
                'Estado Pago',
                'Fecha Cobro',
                'Fecha Reembolso',
                'Importe Cobro Periodo',
                'Importe Reembolso Periodo',
                'Neto Periodo',
                'Cita Referencia ID',
                'Estado Cita',
                'Fecha programada de la cita',
                'Fecha efectiva de atencion',
                'Profesional',
                'Cliente',
                'Servicios Historicos',
            ]);
            foreach ($reporte['movimientos_financieros'] as $mov) {
                $servTexto = [];
                foreach ($mov['servicios'] ?? [] as $sItem) {
                    $servTexto[] = sprintf('%s ($%s)', $sItem['nombre'], number_format((float)$sItem['precio'], 2, '.', ''));
                }
                $escribirFila([
                    (int)($mov['pago_id'] ?? 0),
                    (string)($mov['referencia_pago'] ?? ''),
                    (string)($mov['tipo_movimiento'] ?? strtoupper((string)($mov['estado'] ?? ''))),
                    (string)($mov['proveedor'] ?? ''),
                    !empty($mov['es_simulado']) ? 'SI (Mock)' : 'NO (Real)',
                    number_format((float)($mov['monto'] ?? 0), 2, '.', ''),
                    (string)($mov['estado'] ?? ''),
                    (string)($mov['fecha_cobro'] ?? ''),
                    (string)($mov['fecha_reembolso'] ?? ''),
                    number_format((float)($mov['importe_cobro_periodo'] ?? 0), 2, '.', ''),
                    number_format((float)($mov['importe_reembolso_periodo'] ?? 0), 2, '.', ''),
                    number_format((float)($mov['neto_periodo'] ?? 0), 2, '.', ''),
                    (int)($mov['cita_referencia_id'] ?? 0),
                    (string)($mov['cita_estado'] ?? ''),
                    (string)($mov['cita_fecha'] ?? ''),
                    self::fechaHoraCorta($mov['cita_atencion_efectiva'] ?? null),
                    (string)($mov['profesional_nombre'] ?? ''),
                    (string)($mov['cliente'] ?? ''),
                    implode(', ', $servTexto),
                ]);
            }
            $escribirFila([]);
        }

        // 5. Listado Detallado de Reservas
        $escribirFila(['LISTADO DETALLADO DE RESERVAS']);
        $escribirFila([
            'Cita ID',
            'Fecha programada',
            'Fecha efectiva de atencion',
            'Horario',
            'Cliente',
            'Email Cliente',
            'Profesional',
            'Estado Cita',
            'Servicios (Snapshot Historico)',
            'Duracion Cita (min)',
            'Importe Total Cita (USD)',
            'Importe Reservado Filtrado (USD)',
            'Ingreso Pagado (USD)',
            'Estado de Pago',
        ]);
        foreach ($reporte['reservas'] ?? [] as $res) {
            $escribirFila([
                (int)($res['id'] ?? 0),
                (string)($res['fecha_programada'] ?? $res['fecha'] ?? ''),
                self::fechaHoraCorta($res['atencion_efectiva_at'] ?? null),
                (string)($res['horario'] ?? ''),
                (string)($res['cliente'] ?? ''),
                (string)($res['cliente_email'] ?? ''),
                (string)($res['profesional_nombre'] ?? ''),
                (string)($res['etiqueta_estado_cita'] ?? 'Confirmada'),
                (string)($res['servicios_resumen'] ?? ''),
                (int)($res['duracion_total_minutos'] ?? 0),
                number_format((float)($res['importe_total_cita'] ?? 0), 2, '.', ''),
                number_format((float)($res['importe_reservado'] ?? 0), 2, '.', ''),
                number_format((float)($res['ingreso_pagado'] ?? 0), 2, '.', ''),
                (string)($res['etiqueta_estado_pago'] ?? ''),
            ]);
        }

        rewind($fp);
        $contenido = (string)stream_get_contents($fp);
        fclose($fp);

        return $contenido;
    }

    // ---------------------------------------------------------------- exportación tabular (ronda 5)

    public const BLOQUES_EXPORTACION = ['completo' => 'Reporte completo (varias secciones, solo CSV)', 'reservas' => 'Listado detallado de reservas', 'movimientos' => 'Movimientos financieros'];

    /** «2026-10-12 15:30» de un DATETIME de la base, o cadena vacía. */
    private static function fechaHoraCorta(mixed $v): string { return is_string($v) && $v !== '' ? substr($v, 0, 16) : ''; }

    private static function centavos(mixed $v): int { return (int)round(((float)$v) * 100); }

    /**
     * Bloque tabular plano del reporte para Exportador (CSV o XLSX). Las filas salen de las MISMAS listas que pinta la pantalla
     * (`reservas`, `movimientos_financieros`) y la fila «Total» de totalesBloque(), que la pantalla muestra bajo la tabla.
     * @return array{columnas:array,filas:array<int,array<string,mixed>>,totales:array<string,mixed>,hoja:string}
     */
    public function tablaExportacion(array $reporte, string $bloque): array
    {
        if ($bloque === 'reservas') {
            $columnas = [
                ['clave' => 'id', 'titulo' => 'Cita ID', 'tipo' => 'entero'], ['clave' => 'fecha', 'titulo' => 'Fecha programada', 'tipo' => 'fecha'], ['clave' => 'efectiva', 'titulo' => 'Fecha efectiva de atencion'], ['clave' => 'horario', 'titulo' => 'Horario'],
                ['clave' => 'cliente', 'titulo' => 'Cliente', 'ancho' => 26], ['clave' => 'email', 'titulo' => 'Email Cliente', 'ancho' => 32], ['clave' => 'profesional', 'titulo' => 'Profesional', 'ancho' => 22],
                ['clave' => 'estado', 'titulo' => 'Estado Cita'], ['clave' => 'servicios', 'titulo' => 'Servicios (Snapshot Historico)', 'ancho' => 44], ['clave' => 'duracion', 'titulo' => 'Duracion Cita (min)', 'tipo' => 'entero'],
                ['clave' => 'total_cita', 'titulo' => 'Importe Total Cita (USD)', 'tipo' => 'dinero'], ['clave' => 'reservado', 'titulo' => 'Importe Reservado Filtrado (USD)', 'tipo' => 'dinero'],
                ['clave' => 'pagado', 'titulo' => 'Ingreso Pagado (USD)', 'tipo' => 'dinero'], ['clave' => 'estado_pago', 'titulo' => 'Estado de Pago'],
            ];
            $filas = [];
            foreach ($reporte['reservas'] ?? [] as $r) {
                $filas[] = ['id' => (int)($r['id'] ?? 0), 'fecha' => (string)($r['fecha_programada'] ?? $r['fecha'] ?? ''), 'efectiva' => self::fechaHoraCorta($r['atencion_efectiva_at'] ?? null), 'horario' => (string)($r['horario'] ?? ''), 'cliente' => (string)($r['cliente'] ?? ''),
                    'email' => (string)($r['cliente_email'] ?? ''), 'profesional' => (string)($r['profesional_nombre'] ?? ''), 'estado' => (string)($r['etiqueta_estado_cita'] ?? 'Confirmada'),
                    'servicios' => (string)($r['servicios_resumen'] ?? ''), 'duracion' => (int)($r['duracion_total_minutos'] ?? 0), 'total_cita' => self::centavos($r['importe_total_cita'] ?? 0),
                    'reservado' => self::centavos($r['importe_reservado'] ?? 0), 'pagado' => self::centavos($r['ingreso_pagado'] ?? 0), 'estado_pago' => (string)($r['etiqueta_estado_pago'] ?? '')];
            }
            return ['columnas' => $columnas, 'filas' => $filas, 'totales' => $this->totalesBloque($reporte, 'reservas'), 'hoja' => 'Reservas'];
        }
        if ($bloque === 'movimientos') {
            $columnas = [
                ['clave' => 'pago_id', 'titulo' => 'Pago ID', 'tipo' => 'entero'], ['clave' => 'referencia', 'titulo' => 'Referencia Pago', 'ancho' => 26], ['clave' => 'tipo', 'titulo' => 'Tipo Movimiento'],
                ['clave' => 'proveedor', 'titulo' => 'Proveedor'], ['clave' => 'simulado', 'titulo' => 'Es Simulado'], ['clave' => 'monto', 'titulo' => 'Monto (USD)', 'tipo' => 'dinero'],
                ['clave' => 'estado', 'titulo' => 'Estado Pago'], ['clave' => 'fecha_cobro', 'titulo' => 'Fecha Cobro'], ['clave' => 'fecha_reembolso', 'titulo' => 'Fecha Reembolso'],
                ['clave' => 'cobro_periodo', 'titulo' => 'Importe Cobro Periodo (USD)', 'tipo' => 'dinero'], ['clave' => 'reembolso_periodo', 'titulo' => 'Importe Reembolso Periodo (USD)', 'tipo' => 'dinero'],
                ['clave' => 'neto_periodo', 'titulo' => 'Neto Periodo (USD)', 'tipo' => 'dinero'], ['clave' => 'cita_id', 'titulo' => 'Cita Referencia ID', 'tipo' => 'entero'],
                ['clave' => 'cita_estado', 'titulo' => 'Estado Cita'], ['clave' => 'cita_fecha', 'titulo' => 'Fecha programada de la cita'], ['clave' => 'cita_efectiva', 'titulo' => 'Fecha efectiva de atencion'], ['clave' => 'profesional', 'titulo' => 'Profesional'],
                ['clave' => 'cliente', 'titulo' => 'Cliente'], ['clave' => 'servicios', 'titulo' => 'Servicios Historicos', 'ancho' => 44],
            ];
            $filas = [];
            foreach ($reporte['movimientos_financieros'] ?? [] as $m) {
                $serv = [];
                foreach ($m['servicios'] ?? [] as $si) { $serv[] = sprintf('%s ($%s)', $si['nombre'], number_format((float)$si['precio'], 2, '.', '')); }
                $filas[] = ['pago_id' => (int)($m['pago_id'] ?? 0), 'referencia' => (string)($m['referencia_pago'] ?? ''), 'tipo' => (string)($m['tipo_movimiento'] ?? strtoupper((string)($m['estado'] ?? ''))),
                    'proveedor' => (string)($m['proveedor'] ?? ''), 'simulado' => !empty($m['es_simulado']) ? 'SI (Mock)' : 'NO (Real)', 'monto' => self::centavos($m['monto'] ?? 0),
                    'estado' => (string)($m['estado'] ?? ''), 'fecha_cobro' => (string)($m['fecha_cobro'] ?? ''), 'fecha_reembolso' => (string)($m['fecha_reembolso'] ?? ''),
                    'cobro_periodo' => self::centavos($m['importe_cobro_periodo'] ?? 0), 'reembolso_periodo' => self::centavos($m['importe_reembolso_periodo'] ?? 0), 'neto_periodo' => self::centavos($m['neto_periodo'] ?? 0),
                    'cita_id' => (int)($m['cita_referencia_id'] ?? 0), 'cita_estado' => (string)($m['cita_estado'] ?? ''), 'cita_fecha' => (string)($m['cita_fecha'] ?? ''), 'cita_efectiva' => self::fechaHoraCorta($m['cita_atencion_efectiva'] ?? null),
                    'profesional' => (string)($m['profesional_nombre'] ?? ''), 'cliente' => (string)($m['cliente'] ?? ''), 'servicios' => implode(', ', $serv)];
            }
            return ['columnas' => $columnas, 'filas' => $filas, 'totales' => $this->totalesBloque($reporte, 'movimientos'), 'hoja' => 'Movimientos'];
        }
        throw new \InvalidArgumentException('Bloque de exportación no válido.');
    }

    /**
     * Totales de un bloque (centavos), sumados de las mismas filas que muestra la pantalla; la vista y la fila «Total» del archivo
     * usan esta función. @return array<string,int|string>
     */
    public function totalesBloque(array $reporte, string $bloque): array
    {
        if ($bloque === 'reservas') {
            $n = 0; $total = 0; $res = 0; $pag = 0;
            foreach ($reporte['reservas'] ?? [] as $r) { $n++; $total += self::centavos($r['importe_total_cita'] ?? 0); $res += self::centavos($r['importe_reservado'] ?? 0); $pag += self::centavos($r['ingreso_pagado'] ?? 0); }
            return ['cliente' => $n . ' reservas', 'total_cita' => $total, 'reservado' => $res, 'pagado' => $pag, 'n' => $n];
        }
        $n = 0; $monto = 0; $cobro = 0; $reem = 0; $neto = 0;
        foreach ($reporte['movimientos_financieros'] ?? [] as $m) { $n++; $monto += self::centavos($m['monto'] ?? 0); $cobro += self::centavos($m['importe_cobro_periodo'] ?? 0); $reem += self::centavos($m['importe_reembolso_periodo'] ?? 0); $neto += self::centavos($m['neto_periodo'] ?? 0); }
        return ['cliente' => $n . ' movimientos', 'monto' => $monto, 'cobro_periodo' => $cobro, 'reembolso_periodo' => $reem, 'neto_periodo' => $neto, 'n' => $n];
    }
}

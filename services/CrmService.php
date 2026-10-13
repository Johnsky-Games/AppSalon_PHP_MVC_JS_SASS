<?php
namespace Services;

use Repositories\CrmRepository;

/**
 * Reglas y definiciones del CRM de clientes (solo lectura). No toca superglobales: el controlador entrega los
 * parámetros y las capacidades ya resueltas en servidor (`$acceso`). Todas las fechas "de hoy" se calculan en
 * America/Guayaquil. Las definiciones de métricas están documentadas en docs/CRM_CLIENTES.md y en la propia pantalla.
 *
 * `$acceso` = ['ver'=>bool, 'analitica'=>bool, 'propios'=>bool, 'exportar'=>bool]:
 *  - ver        (clientes.ver): listado y ficha operativa, SIN importes, SIN VIP (que usa gasto).
 *  - analitica  (clientes.analitica): gasto, VIP/RFM, cohortes y gráficos financieros.
 *  - propios    (clientes.ver_propios): vista mínima de clientes atendidos por el profesional.
 */
final class CrmService
{
    public const TIMEZONE = 'America/Guayaquil';
    public const DIAS_ACTIVO_DEFECTO = 90;
    public const DIAS_ACTIVO_MIN = 30;
    public const DIAS_ACTIVO_MAX = 365;
    /** Un cliente es "nuevo" mientras su alta tenga como máximo estos días. */
    public const DIAS_NUEVO = 30;
    /** Ventana de frecuencia y gasto para el RFM, en días. */
    public const VENTANA_RFM_DIAS = 365;
    public const MIN_POBLACION_RFM = 30;
    /** Una cohorte "repite" si vuelve en un día posterior dentro de estos días tras su primera visita. */
    public const VENTANA_RETENCION_DIAS = 90;
    public const MAX_RANGO_DIAS = 1096;
    public const MAX_BUSQUEDA = 80;
    public const POR_PAGINA = [10, 25, 50, 100];
    public const POR_PAGINA_DEFECTO = 25;
    public const EXPORT_MAX_FILAS = 10000;
    public const MAX_EVENTOS_CRONOLOGIA = 150;

    public const SEGMENTOS = [
        'vip' => 'VIP',
        'nuevo' => 'Nuevo',
        'activo' => 'Activo',
        'en_riesgo' => 'En riesgo',
        'inactivo' => 'Inactivo',
    ];
    public const MEMBRESIAS = ['activo' => 'Activa', 'suspendido' => 'Suspendida', 'sin_registro' => 'Sin registro (cuenta legada)'];
    public const ESTADOS_CITA = [
        'confirmada' => 'Confirmada', 'en_servicio' => 'En servicio', 'completada' => 'Completada',
        'no_asistio' => 'No asistió', 'cancelada' => 'Cancelada',
    ];
    private const ORDENES = ['nombre', 'alta', 'ultima', 'visitas', 'visitas_total', 'gasto'];

    private CrmRepository $repo;
    private ?\DateTimeImmutable $ahora;

    public function __construct(CrmRepository $repo, ?\DateTimeImmutable $ahora = null)
    {
        $this->repo = $repo;
        $this->ahora = $ahora;
    }

    // ---------------------------------------------------------------- tiempo y formato

    public function hoy(): \DateTimeImmutable
    {
        $zona = new \DateTimeZone(self::TIMEZONE);
        return ($this->ahora ?? new \DateTimeImmutable('now', $zona))->setTimezone($zona)->setTime(0, 0);
    }

    /** Importe en centavos => "$12.50" / "-$5.00". */
    public static function dinero(int $centavos): string
    {
        return ($centavos < 0 ? '-' : '') . '$' . number_format(abs($centavos) / 100, 2, '.', '');
    }

    /** Porcentaje con un decimal; null si el denominador es cero (sin datos, nunca 0 %). */
    public static function porcentaje(int|float $numerador, int|float $denominador): ?float
    {
        return $denominador > 0 ? round(100 * $numerador / $denominador, 1) : null;
    }

    public static function modo(array $acceso): ?string
    {
        if (!empty($acceso['ver'])) { return 'completo'; }
        if (!empty($acceso['analitica'])) { return 'analitica'; }
        if (!empty($acceso['propios'])) { return 'propios'; }
        return null;
    }

    private static function fechaValida(mixed $valor): ?\DateTimeImmutable
    {
        if (!is_string($valor) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) { return null; }
        $f = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor, new \DateTimeZone(self::TIMEZONE));
        return $f && $f->format('Y-m-d') === $valor ? $f : null;
    }

    // ---------------------------------------------------------------- validación de filtros

    /**
     * Valida y normaliza los filtros del listado, del panel y de la exportación. Nunca lanza por entrada del usuario:
     * devuelve los valores válidos (o su valor por defecto) y un mensaje por cada entrada rechazada.
     *
     * @param array $query     normalmente $_GET
     * @param array $acceso    capacidades del usuario
     * @param array $catalogos ['servicios'=>[['id'=>..]], 'profesionales'=>[['id'=>..]]]
     * @return array{filtros:array<string,mixed>,errores:string[]}
     */
    public function normalizarFiltros(array $query, array $acceso, array $catalogos): array
    {
        $errores = [];
        $hoy = $this->hoy();
        $texto = function (string $clave) use ($query, &$errores): ?string {
            if (!array_key_exists($clave, $query)) { return null; }
            if (!is_string($query[$clave])) { $errores[] = "El parámetro «{$clave}» no es válido."; return null; }
            $v = trim($query[$clave]);
            return $v === '' ? null : $v;
        };
        $fecha = function (string $clave, string $etiqueta) use ($texto, &$errores): ?string {
            $v = $texto($clave);
            if ($v === null) { return null; }
            if (!self::fechaValida($v)) { $errores[] = "{$etiqueta} no es una fecha válida (use AAAA-MM-DD)."; return null; }
            return $v;
        };
        $entero = function (string $clave, string $etiqueta, int $min, int $max) use ($texto, &$errores): ?int {
            $v = $texto($clave);
            if ($v === null) { return null; }
            if (!preg_match('/^\d{1,9}$/', $v) || (int)$v < $min || (int)$v > $max) { $errores[] = "{$etiqueta} debe ser un número entre {$min} y {$max}."; return null; }
            return (int)$v;
        };
        $lista = function (string $clave, string $etiqueta, array $permitidos) use ($texto, &$errores): ?string {
            $v = $texto($clave);
            if ($v === null) { return null; }
            if (!in_array($v, $permitidos, true)) { $errores[] = "{$etiqueta} no es una opción válida."; return null; }
            return $v;
        };

        $q = $texto('q') ?? '';
        $q = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $q));
        if (mb_strlen($q) > self::MAX_BUSQUEDA) { $errores[] = 'La búsqueda admite hasta ' . self::MAX_BUSQUEDA . ' caracteres.'; $q = mb_substr($q, 0, self::MAX_BUSQUEDA); }

        $porDefecto = $hoy->modify('first day of this month')->modify('-11 months');
        $desde = $fecha('desde', 'La fecha inicial del periodo');
        $hasta = $fecha('hasta', 'La fecha final del periodo');
        $desde = $desde ?? $porDefecto->format('Y-m-d');
        $hasta = $hasta ?? $hoy->format('Y-m-d');
        $hoyTexto = $hoy->format('Y-m-d');
        if ($hasta > $hoyTexto) { $errores[] = 'La fecha final del periodo no puede ser posterior a hoy.'; $hasta = $hoyTexto; }
        if ($desde > $hasta) {
            $errores[] = 'La fecha inicial del periodo no puede ser posterior a la final.';
            $desde = min($porDefecto->format('Y-m-d'), $hasta);
        }
        $dias = (int)(new \DateTimeImmutable($desde))->diff(new \DateTimeImmutable($hasta))->days;
        if ($dias > self::MAX_RANGO_DIAS) {
            $errores[] = 'El periodo no puede superar ' . self::MAX_RANGO_DIAS . ' días.';
            $desde = (new \DateTimeImmutable($hasta))->modify('-' . self::MAX_RANGO_DIAS . ' days')->format('Y-m-d');
        }

        $altaDesde = $fecha('alta_desde', 'La fecha de alta inicial');
        $altaHasta = $fecha('alta_hasta', 'La fecha de alta final');
        if ($altaDesde !== null && $altaHasta !== null && $altaDesde > $altaHasta) {
            $errores[] = 'La fecha de alta inicial no puede ser posterior a la final.';
            $altaDesde = $altaHasta = null;
        }

        $idsProfesionales = array_map(static fn($p) => (int)$p['id'], $catalogos['profesionales'] ?? []);
        $idsServicios = array_map(static fn($s) => (int)$s['id'], $catalogos['servicios'] ?? []);
        $profesional = $entero('profesional_id', 'El profesional', 1, 999999999);
        if ($profesional !== null && !in_array($profesional, $idsProfesionales, true)) { $errores[] = 'El profesional seleccionado no existe.'; $profesional = null; }
        $servicio = $entero('servicio_id', 'El servicio', 1, 999999999);
        if ($servicio !== null && !in_array($servicio, $idsServicios, true)) { $errores[] = 'El servicio seleccionado no existe.'; $servicio = null; }

        $segmentos = array_keys(self::SEGMENTOS);
        if (empty($acceso['analitica'])) { $segmentos = array_values(array_diff($segmentos, ['vip'])); }
        $segmento = $lista('segmento', 'El segmento', $segmentos);
        $membresia = $lista('membresia', 'El estado de membresía', array_keys(self::MEMBRESIAS));
        $consentimiento = $lista('consentimiento', 'El filtro de consentimiento comercial', ['si', 'vigente', 'anterior', 'no']);
        $diasActivo = $entero('dias_activo', 'La ventana de clientes activos', self::DIAS_ACTIVO_MIN, self::DIAS_ACTIVO_MAX) ?? self::DIAS_ACTIVO_DEFECTO;

        $ordenesPermitidos = !empty($acceso['analitica']) ? self::ORDENES : array_values(array_diff(self::ORDENES, ['gasto']));
        $orden = $lista('orden', 'El orden', $ordenesPermitidos) ?? 'nombre';
        $dir = $lista('dir', 'La dirección del orden', ['asc', 'desc']) ?? 'asc';
        $porPagina = $entero('por_pagina', 'El tamaño de página', 1, 100);
        if ($porPagina !== null && !in_array($porPagina, self::POR_PAGINA, true)) { $errores[] = 'El tamaño de página debe ser ' . implode(', ', self::POR_PAGINA) . '.'; $porPagina = null; }
        $pagina = $entero('pagina', 'La página', 1, 1000000) ?? 1;

        return ['filtros' => [
            'q' => $q, 'desde' => $desde, 'hasta' => $hasta, 'alta_desde' => $altaDesde, 'alta_hasta' => $altaHasta,
            'profesional_id' => $profesional, 'servicio_id' => $servicio, 'segmento' => $segmento, 'membresia' => $membresia,
            'consentimiento' => $consentimiento, 'dias_activo' => $diasActivo, 'orden' => $orden, 'dir' => $dir,
            'pagina' => $pagina, 'por_pagina' => $porPagina ?? self::POR_PAGINA_DEFECTO,
        ], 'errores' => array_values(array_unique($errores))];
    }

    // ---------------------------------------------------------------- RFM (función pura, verificable)

    /**
     * Segmentación RFM por terciles sobre la población con visitas en la ventana.
     * Para cada dimensión se ordenan los valores (R se invierte: menos días es mejor) y se toman los cortes c1 y c2 en las
     * posiciones ceil(n/3) y ceil(2n/3). Puntaje = 3 si el valor supera c2, 2 si supera c1, 1 en otro caso: los empates
     * reciben siempre el mismo puntaje y sin variación nadie llega a 3. VIP = R 3, F 3 (y M 3 si algún cliente tiene gasto)
     * y última visita dentro de la ventana de clientes activos. Sin datos suficientes no se calcula nada.
     *
     * @param array<int,array{id:int,recencia:int,frecuencia:int,gasto:int}> $poblacion
     */
    public static function calcularRfm(array $poblacion, int $diasActivo, int $minimo = self::MIN_POBLACION_RFM): array
    {
        $n = count($poblacion);
        if ($n < $minimo) {
            return ['disponible' => false, 'poblacion' => $n, 'minimo' => $minimo, 'usa_gasto' => false, 'umbrales' => null, 'vip_ids' => [], 'puntajes' => [],
                'mensaje' => "Aún no hay datos suficientes para segmentar por RFM: hay {$n} clientes con visitas completadas en los últimos " . self::VENTANA_RFM_DIAS . " días y se requieren al menos {$minimo}."];
        }
        $cortes = static function (array $valores): array {
            sort($valores);
            $total = count($valores);
            return [$valores[(int)ceil($total / 3) - 1], $valores[(int)ceil(2 * $total / 3) - 1]];
        };
        $punto = static fn(int $v, array $c): int => $v > $c[1] ? 3 : ($v > $c[0] ? 2 : 1);
        $usaGasto = false;
        foreach ($poblacion as $fila) { if ((int)$fila['gasto'] > 0) { $usaGasto = true; break; } }
        $cr = $cortes(array_map(static fn($f) => -(int)$f['recencia'], $poblacion));
        $cf = $cortes(array_map(static fn($f) => (int)$f['frecuencia'], $poblacion));
        $cm = $cortes(array_map(static fn($f) => (int)$f['gasto'], $poblacion));
        $puntajes = []; $vip = [];
        foreach ($poblacion as $fila) {
            $r = $punto(-(int)$fila['recencia'], $cr); $f = $punto((int)$fila['frecuencia'], $cf); $m = $punto((int)$fila['gasto'], $cm);
            $puntajes[(int)$fila['id']] = [$r, $f, $m];
            if ($r === 3 && $f === 3 && (!$usaGasto || $m === 3) && (int)$fila['recencia'] <= $diasActivo) { $vip[] = (int)$fila['id']; }
        }
        sort($vip);
        return ['disponible' => true, 'poblacion' => $n, 'minimo' => $minimo, 'usa_gasto' => $usaGasto, 'mensaje' => '',
            'umbrales' => [
                'recencia' => ['r3' => -$cr[1], 'r2' => -$cr[0]],  // R=3 si recencia < r3 días; R=2 si < r2 días
                'frecuencia' => ['f3' => $cf[1], 'f2' => $cf[0]],  // F=3 si visitas > f3; F=2 si > f2
                'gasto' => ['m3' => $cm[1], 'm2' => $cm[0]],       // M=3 si gasto neto (centavos) > m3; M=2 si > m2
            ], 'vip_ids' => $vip, 'puntajes' => $puntajes];
    }

    private function rfm(string $hoy, int $diasActivo): array
    {
        return self::calcularRfm($this->repo->poblacionRfm($hoy, self::VENTANA_RFM_DIAS), $diasActivo);
    }

    // ---------------------------------------------------------------- paginación

    /** @return array{total:int,pagina:int,paginas:int,por_pagina:int,offset:int,primero:int,ultimo:int} */
    public static function paginar(int $total, int $pagina, int $porPagina): array
    {
        $porPagina = in_array($porPagina, self::POR_PAGINA, true) ? $porPagina : self::POR_PAGINA_DEFECTO;
        $paginas = max(1, (int)ceil($total / $porPagina));
        $pagina = max(1, min($pagina, $paginas));
        $offset = ($pagina - 1) * $porPagina;
        return ['total' => $total, 'pagina' => $pagina, 'paginas' => $paginas, 'por_pagina' => $porPagina, 'offset' => $offset,
            'primero' => $total === 0 ? 0 : $offset + 1, 'ultimo' => min($total, $offset + $porPagina)];
    }

    /** Contexto SQL del periodo. `hasta` nunca supera hoy: no se cuentan citas futuras. */
    private function contexto(array $f, array $acceso, array $vipIds): array
    {
        $hoy = $this->hoy()->format('Y-m-d');
        return ['hoy' => $hoy, 'desde' => $f['desde'], 'hasta' => min($f['hasta'], $hoy), 'dias_activo' => (int)$f['dias_activo'],
            'dias_nuevo' => self::DIAS_NUEVO, 'vip_ids' => $vipIds, 'analitica' => !empty($acceso['analitica'])];
    }

    /** @return string[] meses AAAA-MM entre dos fechas, ambos incluidos */
    private static function meses(string $desde, string $hasta): array
    {
        $m = (new \DateTimeImmutable($desde))->modify('first day of this month');
        $fin = (new \DateTimeImmutable($hasta))->modify('first day of this month');
        $r = [];
        while ($m <= $fin && count($r) < 80) { $r[] = $m->format('Y-m'); $m = $m->modify('+1 month'); }
        return $r;
    }

    // ---------------------------------------------------------------- panel

    public function catalogos(): array { return $this->repo->catalogos(); }

    /**
     * Datos de la pantalla principal según el modo del usuario.
     * @return array<string,mixed>
     */
    public function panel(array $f, array $acceso, ?int $profesionalId = null): array
    {
        $modo = self::modo($acceso);
        if ($modo === null) { throw new \DomainException('Sin permisos de clientes.'); }
        $hoy = $this->hoy()->format('Y-m-d');
        if ($modo === 'propios') { return $this->panelProfesional($f, $profesionalId, $hoy); }

        $rfm = !empty($acceso['analitica']) ? $this->rfm($hoy, (int)$f['dias_activo']) : null;
        $c = $this->contexto($f, $acceso, $rfm['vip_ids'] ?? []);
        $ind = $this->repo->indicadores($c);
        $citas = $this->repo->citasPorEstado($c);
        $totalCitas = array_sum($citas);
        $conocidas = (int)($citas['completada'] ?? 0) + (int)($citas['cancelada'] ?? 0) + (int)($citas['no_asistio'] ?? 0) + (int)($citas['confirmada'] ?? 0) + (int)($citas['en_servicio'] ?? 0);
        $total = (int)($ind['total'] ?? 0);
        $conVisita = (int)($ind['con_visita_rango'] ?? 0);
        $visitas = (int)($ind['visitas_rango'] ?? 0);
        $fid = $this->repo->fidelidadAgregada();

        $resumen = [
            'clientes_total' => $total,
            'membresias' => ['activo' => (int)($ind['m_activa'] ?? 0), 'suspendido' => (int)($ind['m_suspendida'] ?? 0), 'sin_registro' => (int)($ind['m_sin_registro'] ?? 0)],
            'con_consentimiento' => (int)($ind['con_consentimiento'] ?? 0),
            'con_consentimiento_vigente' => (int)($ind['con_consentimiento_vigente'] ?? 0),
            'con_consentimiento_anterior' => (int)($ind['con_consentimiento_anterior'] ?? 0),
            'nuevos' => (int)($ind['nuevos'] ?? 0),
            'primera_visita_en_rango' => (int)($ind['primera_visita_en_rango'] ?? 0),
            'activos' => (int)($ind['activos'] ?? 0),
            'pct_activos' => self::porcentaje((int)($ind['activos'] ?? 0), $total),
            'con_visita_rango' => $conVisita,
            'visitas_rango' => $visitas,
            'frecuencia_media' => $conVisita > 0 ? round($visitas / $conVisita, 2) : null,
            'repiten_rango' => (int)($ind['repiten_rango'] ?? 0),
            'pct_repiten' => self::porcentaje((int)($ind['repiten_rango'] ?? 0), $conVisita),
            'con_historial' => (int)($ind['con_historial'] ?? 0),
            'recencia_media' => $ind['recencia_media'] !== null ? round((float)$ind['recencia_media'], 1) : null,
            'citas' => ['total' => $totalCitas, 'completadas' => (int)($citas['completada'] ?? 0), 'canceladas' => (int)($citas['cancelada'] ?? 0),
                'no_asistio' => (int)($citas['no_asistio'] ?? 0), 'abiertas' => (int)($citas['confirmada'] ?? 0) + (int)($citas['en_servicio'] ?? 0), 'otros' => max(0, $totalCitas - $conocidas)],
            'pct_cancelacion' => self::porcentaje((int)($citas['cancelada'] ?? 0), $totalCitas),
            'pct_no_asistencia' => self::porcentaje((int)($citas['no_asistio'] ?? 0), $totalCitas),
            'fidelidad' => $fid,
            'hasta_efectivo' => $c['hasta'],
            'gasto' => null,
        ];

        $meses = self::meses($c['desde'], $c['hasta']);
        $altas = $this->repo->altasPorMes($c);
        $visitasMes = $this->repo->visitasPorMes($c);
        $series = [
            'altas' => array_map(static fn($m) => ['etiqueta' => $m, 'valor' => (int)($altas[$m] ?? 0)], $meses),
            'visitas' => array_map(static fn($m) => ['etiqueta' => $m, 'valor' => (int)($visitasMes[$m]['visitas'] ?? 0), 'clientes' => (int)($visitasMes[$m]['clientes'] ?? 0)], $meses),
            'cobros' => null,
        ];
        if (empty($acceso['analitica'])) {
            $resumen['repiten_rango'] = null;
            $resumen['pct_repiten'] = null;
        }
        if (!empty($acceso['analitica'])) {
            $g = $this->repo->gastoAgregado($c);
            $neto = $g['cobros_real'] - $g['dev_real'];
            $resumen['gasto'] = [
                'cobros_real' => $g['cobros_real'], 'devoluciones_real' => $g['dev_real'], 'neto_real' => $neto, 'n_cobros_real' => $g['n_cobros_real'],
                'ticket_por_cobro' => $g['n_cobros_real'] > 0 ? (int)round($g['cobros_real'] / $g['n_cobros_real']) : null,
                'neto_simulado' => $g['cobros_sim'] - $g['dev_sim'], 'clientes_con_gasto' => $g['clientes_con_gasto'],
            ];
            $cobros = $this->repo->cobrosPorMes($c);
            $series['cobros'] = array_map(static fn($m) => ['etiqueta' => $m, 'valor' => (int)($cobros[$m]['cobros'] ?? 0), 'devoluciones' => (int)($cobros[$m]['devoluciones'] ?? 0)], $meses);
        }

        $dist = $this->repo->distribucionSegmentos($c);
        $segmentos = [];
        foreach (self::SEGMENTOS as $clave => $etiqueta) {
            if ($clave === 'vip' && empty($acceso['analitica'])) { continue; }
            $segmentos[] = ['clave' => $clave, 'etiqueta' => $etiqueta, 'valor' => (int)($dist[$clave] ?? 0)];
        }

        // Retención y cohortes pertenecen a la analítica: sin ese permiso ni siquiera se consultan.
        $cohortes = !empty($acceso['analitica']) ? array_reverse($this->repo->cohortes($c, self::VENTANA_RETENCION_DIAS, 12)) : [];
        $cohortes = array_map(function (array $r) use ($hoy): array {
            $finMes = (new \DateTimeImmutable($r['cohorte'] . '-01'))->modify('last day of this month');
            return ['cohorte' => $r['cohorte'], 'clientes' => (int)$r['clientes'], 'repiten' => (int)$r['repiten'],
                'pct' => self::porcentaje((int)$r['repiten'], (int)$r['clientes']),
                'madura' => $finMes->modify('+' . self::VENTANA_RETENCION_DIAS . ' days')->format('Y-m-d') <= $hoy];
        }, $cohortes);

        $listado = null;
        if ($modo === 'completo') {
            $totalFilas = $this->repo->contar($c, $f);
            $pag = self::paginar($totalFilas, (int)$f['pagina'], (int)$f['por_pagina']);
            $listado = $pag + ['filas' => $totalFilas > 0 ? $this->repo->listar($c, $f, $pag['por_pagina'], $pag['offset']) : []];
        }

        return ['modo' => $modo, 'hoy' => $hoy, 'filtros' => $f, 'resumen' => $resumen, 'series' => $series, 'segmentos' => $segmentos,
            'servicios' => $this->repo->serviciosMasUsados($c, 8), 'cohortes' => $cohortes, 'rfm' => $rfm, 'listado' => $listado];
    }

    private function panelProfesional(array $f, ?int $profesionalId, string $hoy): array
    {
        $listado = null;
        if ($profesionalId !== null) {
            $r = $this->repo->clientesDeProfesional($profesionalId, $hoy, (string)$f['q'], (int)$f['por_pagina'], 0);
            $pag = self::paginar($r['total'], (int)$f['pagina'], (int)$f['por_pagina']);
            $filas = $r['total'] > 0 ? $this->repo->clientesDeProfesional($profesionalId, $hoy, (string)$f['q'], $pag['por_pagina'], $pag['offset'])['filas'] : [];
            $listado = $pag + ['filas' => $filas];
        }
        return ['modo' => 'propios', 'hoy' => $hoy, 'filtros' => $f, 'listado' => $listado, 'vinculado' => $profesionalId !== null];
    }

    // ---------------------------------------------------------------- ficha 360°

    /**
     * Ficha de un cliente. Devuelve null si no existe o, para el profesional, si no lo ha atendido (no distingue ambos casos).
     * No incluye respuestas de formularios privados bajo ningún permiso: este módulo no las lee.
     */
    public function ficha(int $id, array $acceso, ?int $profesionalId = null): ?array
    {
        $modo = self::modo($acceso);
        $hoy = $this->hoy()->format('Y-m-d');
        if ($modo === 'propios') {
            if ($profesionalId === null) { return null; }
            $cliente = $this->repo->clienteDeProfesional($profesionalId, $id, $hoy);
            if ($cliente === null) { return null; }
            return ['modo' => 'propios', 'hoy' => $hoy, 'cliente' => $cliente, 'servicios' => $this->repo->serviciosConProfesional($profesionalId, $id, $hoy)];
        }
        if ($modo !== 'completo') { return null; }

        $analitica = !empty($acceso['analitica']);
        $rfm = $analitica ? $this->rfm($hoy, self::DIAS_ACTIVO_DEFECTO) : null;
        $c = ['hoy' => $hoy, 'desde' => $hoy, 'hasta' => $hoy, 'dias_activo' => self::DIAS_ACTIVO_DEFECTO, 'dias_nuevo' => self::DIAS_NUEVO,
            'vip_ids' => $rfm['vip_ids'] ?? [], 'analitica' => false];
        $cliente = $this->repo->cliente($c, $id);
        if ($cliente === null) { return null; }

        $citas = $this->repo->citasDeCliente($id, 100);
        $porEstado = $this->repo->citasPorEstadoDeCliente($id);
        $fid = $this->repo->fidelidadDeCliente($id, 100);
        $espera = $this->repo->listaEsperaDeCliente($id, 50);
        $pagos = []; $gasto = null;
        if ($analitica) {
            $pagos = $this->repo->pagosDeCliente($id, 100);
            $g = $this->repo->gastoDeCliente($id, '2000-01-01', $hoy);
            $gasto = ['cobros_real' => $g['cobros_real'], 'devoluciones_real' => $g['dev_real'], 'neto_real' => $g['cobros_real'] - $g['dev_real'], 'n_cobros_real' => $g['n_cobros_real'],
                'ticket_por_cobro' => $g['n_cobros_real'] > 0 ? (int)round($g['cobros_real'] / $g['n_cobros_real']) : null,
                'neto_simulado' => $g['cobros_sim'] - $g['dev_sim'], 'desde' => '2000-01-01', 'hasta' => $hoy];
        }
        $cronologia = $this->cronologia($cliente, $citas, $pagos, $fid['movimientos'], $espera, $analitica);
        if (!$analitica) {
            // Sin permiso de analítica ni siquiera viajan importes ni estado de pago hacia la vista.
            $citas = array_map(static function (array $ci): array { unset($ci['importe'], $ci['estado_pago']); return $ci; }, $citas);
        }
        $totalCitas = array_sum($porEstado);
        return [
            'modo' => 'completo', 'hoy' => $hoy, 'analitica' => $analitica, 'cliente' => $cliente,
            'citas_por_estado' => $porEstado, 'total_citas' => $totalCitas,
            'preferencias' => $this->repo->preferenciasDeCliente($id, $hoy, 5),
            'fidelidad' => $fid, 'espera' => $espera, 'gasto' => $gasto,
            'cronologia' => $cronologia,
            'citas' => $citas, 'pagos' => $pagos,
            // Evidencia append-only de aceptaciones y retiros, con la versión del texto que la persona vio.
            'consentimiento_historial' => $this->repo->historialConsentimiento($id, 20),
        ];
    }

    /** Eventos de la ficha, más recientes primero, hasta MAX_EVENTOS_CRONOLOGIA. @return array<int,array<string,string>> */
    private function cronologia(array $cliente, array $citas, array $pagos, array $fidelidad, array $espera, bool $analitica): array
    {
        $ev = [];
        if (!empty($cliente['alta'])) {
            $ev[] = ['cuando' => $cliente['alta'], 'tipo' => 'alta', 'titulo' => 'Alta como cliente', 'detalle' => 'Origen: ' . ($cliente['origen'] ?? 'no registrado') . '.'];
        }
        foreach ($citas as $ci) {
            $estado = self::ESTADOS_CITA[$ci['estado']] ?? (string)$ci['estado'];
            $detalle = trim(($ci['servicios'] ?? '') . ($ci['profesional'] ? ' · con ' . $ci['profesional'] : ' · sin profesional asignado'));
            if ($analitica) { $detalle .= ' · importe de servicios ' . self::dinero((int)round(((float)$ci['importe']) * 100)); }
            $ev[] = ['cuando' => $ci['fecha'] . ' ' . $ci['hora'], 'tipo' => 'cita', 'titulo' => 'Cita ' . mb_strtolower($estado), 'detalle' => $detalle];
            if (!empty($ci['cancelado_at'])) {
                $ev[] = ['cuando' => $ci['cancelado_at'], 'tipo' => 'cancelacion', 'titulo' => 'Cancelación registrada', 'detalle' => 'Cita del ' . $ci['fecha'] . ($ci['cancelado_por'] ? ' · por ' . $ci['cancelado_por'] : '') . '.'];
            }
        }
        foreach ($pagos as $pg) {
            if (!in_array($pg['estado'], ['completado', 'reembolsado'], true)) { continue; }
            $monto = self::dinero((int)round(((float)$pg['monto']) * 100));
            $sim = (int)$pg['simulado'] === 1 ? ' (pasarela simulada: no es ingreso real)' : '';
            $ev[] = ['cuando' => $pg['fecha_cobro'], 'tipo' => 'cobro', 'titulo' => 'Cobro ' . $monto . $sim, 'detalle' => 'Referencia ' . $pg['referencia_pago'] . ' · ' . ($pg['metodo_pago'] ?: 'método no registrado') . '.'];
            if ($pg['estado'] === 'reembolsado') {
                $ev[] = ['cuando' => $pg['reembolsado_at'] ?: $pg['fecha_cobro'], 'tipo' => 'devolucion', 'titulo' => 'Devolución ' . $monto . $sim, 'detalle' => 'Referencia ' . $pg['referencia_pago'] . ($pg['reembolsado_at'] ? '.' : ' (sin fecha de reembolso registrada: se usa la del cobro).')];
            }
        }
        foreach ($fidelidad as $m) {
            $puntos = (int)$m['puntos'];
            $ev[] = ['cuando' => $m['creado_en'], 'tipo' => 'fidelidad', 'titulo' => ($puntos >= 0 ? '+' : '') . $puntos . ' puntos · ' . ($m['tipo'] === 'canje' ? 'canje de beneficio' : 'visita completada'),
                'detalle' => $m['tipo'] === 'canje' ? 'Canje ' . $m['canje_estado'] . ($m['atendido_por'] ? ' (atendido por ' . trim($m['atendido_por']) . ')' : '') . '.' : 'Referencia ' . $m['referencia'] . '.'];
        }
        if (!empty($cliente['consentimiento_en'])) {
            $ev[] = ['cuando' => $cliente['consentimiento_en'], 'tipo' => 'consentimiento', 'titulo' => 'Aceptó comunicaciones comerciales por correo', 'detalle' => 'Fecha del consentimiento vigente.'];
        }
        foreach ($espera as $e) {
            $ev[] = ['cuando' => $e['creado_en'], 'tipo' => 'espera', 'titulo' => 'Lista de espera: ' . $e['servicio'], 'detalle' => 'Para el ' . $e['fecha'] . ' · estado ' . $e['estado'] . '.'];
        }
        usort($ev, static fn(array $a, array $b): int => strcmp((string)$b['cuando'], (string)$a['cuando']));
        return array_slice($ev, 0, self::MAX_EVENTOS_CRONOLOGIA);
    }

    // ---------------------------------------------------------------- exportación CSV

    /**
     * Genera el CSV. `$tipo` 'clientes' (operativo, sin datos privados de formularios) o 'comercial' (solo quienes
     * aceptaron comunicaciones, con cuenta confirmada y membresía no suspendida). Cada celda pasa por
     * ReporteService::sanearCeldaCsv (neutralización de fórmulas), sin duplicar esa lógica.
     * @return array{contenido:string,filas:int,truncado:bool,archivo:string,columnas:string[]}
     */
    public function exportar(array $f, array $acceso, string $tipo): array
    {
        if (!in_array($tipo, ['clientes', 'comercial'], true)) { throw new \InvalidArgumentException('Tipo de exportación no válido.'); }
        if (empty($acceso['ver'])) { throw new \DomainException('Sin permisos de clientes.'); }
        $hoy = $this->hoy()->format('Y-m-d');
        $analitica = !empty($acceso['analitica']) && $tipo === 'clientes';
        $rfm = !empty($acceso['analitica']) ? $this->rfm($hoy, (int)$f['dias_activo']) : null;
        $c = $this->contexto($f, $acceso, $rfm['vip_ids'] ?? []);
        $c['analitica'] = $analitica;
        $f['comercial'] = $tipo === 'comercial';
        $f['pagina'] = 1;

        $fp = fopen('php://temp', 'r+');
        if ($fp === false) { throw new \RuntimeException('No se pudo preparar la exportación.'); }
        fwrite($fp, "\xEF\xBB\xBF");
        $escribir = static function (array $celdas) use ($fp): void {
            fputcsv($fp, array_map([ReporteService::class, 'sanearCeldaCsv'], $celdas));
        };
        $dinero = static fn(int $cents): string => number_format($cents / 100, 2, '.', '');
        if ($tipo === 'comercial') {
            $cab = ['ID', 'Nombre', 'Apellido', 'Correo', 'Consentimiento desde'];
            $escribir($cab);
        } else {
            $cab = ['ID', 'Nombre', 'Apellido', 'Correo', 'Teléfono', 'Estado de membresía', 'Origen', 'Fecha de alta', 'Cuenta confirmada', 'Consentimiento comercial', 'Fecha de consentimiento',
                'Versión del consentimiento', 'Consentimiento con versión anterior',
                'Visitas completadas (total)', 'Visitas completadas (periodo y filtros)', 'Primera visita', 'Última visita', 'Días desde la última visita', 'Segmento'];
            if ($analitica) { array_push($cab, 'Cobros reales (USD)', 'Devoluciones reales (USD)', 'Gasto neto real (USD)', 'Neto simulado (USD, no es ingreso)'); }
            $escribir($cab);
        }
        $filas = 0; $truncado = false; $lote = 2500; $offset = 0;
        while (true) {
            $pagina = $this->repo->listar($c, $f, $lote, $offset);
            foreach ($pagina as $r) {
                if ($filas >= self::EXPORT_MAX_FILAS) { $truncado = true; break 2; }
                if ($tipo === 'comercial') {
                    $escribir([(int)$r['id'], $r['nombre'], $r['apellido'], $r['email'], (string)$r['consentimiento_en']]);
                } else {
                    $fila = [(int)$r['id'], $r['nombre'], $r['apellido'], $r['email'], (string)$r['telefono'], self::MEMBRESIAS[$r['membresia']] ?? $r['membresia'], (string)$r['origen'], (string)$r['alta'],
                        (int)$r['confirmado'] === 1 ? 'Sí' : 'No', (int)$r['consentimiento'] === 1 ? 'Sí' : 'No', (string)$r['consentimiento_en'],
                        $r['consentimiento_version'] === null ? '' : (int)$r['consentimiento_version'], (int)$r['consentimiento_anterior'] === 1 ? 'Sí' : 'No',
                        (int)$r['visitas_total'], (int)$r['visitas_rango'], (string)$r['primera_visita'], (string)$r['ultima_visita'], $r['recencia'] === null ? '' : (int)$r['recencia'],
                        self::SEGMENTOS[$r['segmento']] ?? $r['segmento']];
                    if ($analitica) {
                        array_push($fila, $dinero((int)$r['cobros_real']), $dinero((int)$r['dev_real']), $dinero((int)$r['cobros_real'] - (int)$r['dev_real']), $dinero((int)$r['cobros_sim'] - (int)$r['dev_sim']));
                    }
                    $escribir($fila);
                }
                $filas++;
            }
            if (count($pagina) < $lote) { break; }
            $offset += $lote;
        }
        if ($truncado) { $escribir(['AVISO: exportación limitada a ' . self::EXPORT_MAX_FILAS . ' filas; afine los filtros para obtener el resto.']); }
        rewind($fp);
        $contenido = (string)stream_get_contents($fp);
        fclose($fp);
        $archivo = ($tipo === 'comercial' ? 'lista_comercial_' : 'clientes_') . $f['desde'] . '_a_' . min($f['hasta'], $hoy) . '.csv';
        return ['contenido' => $contenido, 'filas' => $filas, 'truncado' => $truncado, 'archivo' => $archivo, 'columnas' => $cab];
    }
}

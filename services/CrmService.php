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
    public const VINCULOS = ['externo' => 'Clientes externos', 'equipo' => 'Equipo con perfil de cliente', 'todos' => 'Todos los perfiles de cliente'];
    public const ESTADOS_CITA = [
        'confirmada' => 'Confirmada', 'en_servicio' => 'En servicio', 'completada' => 'Completada',
        'no_asistio' => 'No asistió', 'cancelada' => 'Cancelada',
    ];
    private const ORDENES = ['nombre', 'alta', 'ultima', 'proxima', 'visitas', 'visitas_total', 'gasto', 'saldo'];
    /** Orden que solo ve quien tiene analítica (importes). */
    private const ORDENES_ANALITICA = ['gasto', 'saldo'];

    // ---- catálogos de los filtros nuevos (ronda 5). Cada clave y cada valor tienen su definición visible en la pantalla.
    /** Cuenta = correo confirmado (usuarios.confirmado). */
    public const CUENTAS = ['confirmada' => 'Confirmada', 'sin_confirmar' => 'Sin confirmar'];
    /** Comunicaciones comerciales = consentimiento a promociones por correo. NO es una suscripción de pago. */
    public const COMUNICACIONES = ['otorgado' => 'Otorgado', 'revocado' => 'Revocado', 'sin_decision' => 'Sin decisión'];
    /** Valores del filtro «consentimiento» (la clave se conserva por compatibilidad con enlaces y pruebas anteriores). */
    public const CONSENTIMIENTOS = [
        'si' => 'Otorgado (cualquier versión del texto)', 'vigente' => 'Otorgado con la versión vigente del texto', 'anterior' => 'Otorgado con una versión anterior del texto',
        'no' => 'No otorgado (revocado o sin decisión)', 'revocado' => 'Revocado (su última decisión fue retirarlo)', 'sin_decision' => 'Sin decisión (nunca decidió)',
    ];
    public const ACTIVIDADES = ['reciente' => 'Reciente', 'inactivo' => 'Inactivo por periodo', 'nunca' => 'Nunca atendido'];
    public const PROXIMAS = ['con' => 'Con próxima cita', 'sin' => 'Sin próxima cita'];
    public const SALDOS = ['con' => 'Con saldo pendiente', 'sin' => 'Sin saldo pendiente'];
    public const ORDENES_ETIQUETAS = [
        'nombre' => 'Apellido y nombre', 'alta' => 'Fecha de alta', 'ultima' => 'Última visita', 'proxima' => 'Próxima cita',
        'visitas' => 'Visitas del periodo', 'visitas_total' => 'Visitas totales', 'gasto' => 'Gasto neto real', 'saldo' => 'Saldo pendiente',
    ];

    /**
     * Indicadores con listado: cada cifra de la pantalla que cuenta CLIENTES se explica con el listado de esos mismos clientes
     * (`/admin/clientes/listado?indicador=…`). `filtros` son los que el listado impone sobre el universo filtrado vigente; `dato` es
     * la ruta de la cifra en el panel (para comprobar que cifra y listado coinciden); `analitica` = solo con ese permiso.
     */
    public const INDICADORES = [
        'totales' => ['etiqueta' => 'Clientes totales', 'dato' => 'clientes_total', 'analitica' => false,
            'explica' => 'Todos los clientes del universo filtrado.'],
        'altas' => ['etiqueta' => 'Altas en el periodo', 'dato' => 'nuevos', 'analitica' => false,
            'explica' => 'Clientes cuya fecha de alta cae dentro del periodo analizado.'],
        'activos' => ['etiqueta' => 'Clientes activos', 'dato' => 'activos', 'analitica' => false,
            'explica' => 'Clientes con al menos una visita completada en la ventana de días de actividad.'],
        'con_visita' => ['etiqueta' => 'Clientes con visita en el periodo', 'dato' => 'con_visita_rango', 'analitica' => false,
            'explica' => 'Clientes con al menos una visita completada dentro del periodo (y del profesional o servicio elegidos).'],
        'repiten' => ['etiqueta' => 'Clientes que repiten en el periodo', 'dato' => 'repiten_rango', 'analitica' => true,
            'explica' => 'Clientes con dos o más visitas completadas dentro del periodo.'],
        'membresia_activo' => ['etiqueta' => 'Membresías activas', 'dato' => 'membresias.activo', 'analitica' => false,
            'explica' => 'Clientes cuya membresía (clientes.estado) está activa.'],
        'membresia_suspendido' => ['etiqueta' => 'Membresías suspendidas', 'dato' => 'membresias.suspendido', 'analitica' => false,
            'explica' => 'Clientes cuya membresía (clientes.estado) está suspendida.'],
        'membresia_sin_registro' => ['etiqueta' => 'Cuentas legadas sin registro de membresía', 'dato' => 'membresias.sin_registro', 'analitica' => false,
            'explica' => 'Cuentas con rol de cliente que no tienen fila de membresía.'],
        'consentimiento_si' => ['etiqueta' => 'Comunicaciones comerciales otorgadas', 'dato' => 'con_consentimiento', 'analitica' => false,
            'explica' => 'Clientes que aceptaron recibir promociones por correo (cualquier versión del texto).'],
        'consentimiento_vigente' => ['etiqueta' => 'Comunicaciones otorgadas con el texto vigente', 'dato' => 'con_consentimiento_vigente', 'analitica' => false,
            'explica' => 'Clientes que aceptaron la versión del texto que hoy rige: los únicos que entran en la lista comercial.'],
        'consentimiento_anterior' => ['etiqueta' => 'Comunicaciones otorgadas con un texto anterior', 'dato' => 'con_consentimiento_anterior', 'analitica' => false,
            'explica' => 'Clientes que aceptaron una versión que ya no rige: no entran en la lista comercial hasta reconfirmar.'],
        'comunicaciones_revocado' => ['etiqueta' => 'Comunicaciones revocadas', 'dato' => 'comunicaciones.revocado', 'analitica' => false,
            'explica' => 'Clientes que retiraron su consentimiento: su última decisión registrada fue un retiro.'],
        'comunicaciones_sin_decision' => ['etiqueta' => 'Comunicaciones sin decisión', 'dato' => 'comunicaciones.sin_decision', 'analitica' => false,
            'explica' => 'Clientes que nunca aceptaron ni retiraron el consentimiento.'],
        'cuenta_sin_confirmar' => ['etiqueta' => 'Cuentas sin confirmar', 'dato' => 'cuenta_sin_confirmar', 'analitica' => false,
            'explica' => 'Clientes que aún no confirmaron su correo.'],
        'con_saldo' => ['etiqueta' => 'Clientes con saldo pendiente', 'dato' => 'con_saldo', 'analitica' => true,
            'explica' => 'Clientes con citas atendidas que todavía tienen importe por cobrar.'],
    ];

    /** Claves de la consulta que describen el universo y su presentación; todas se conservan al paginar, ordenar y exportar. */
    public const CLAVES_CONSULTA = ['q', 'vinculo', 'desde', 'hasta', 'alta_desde', 'alta_hasta', 'profesional_id', 'servicio_id', 'segmento', 'membresia', 'consentimiento', 'cuenta', 'actividad',
        'ultima_desde', 'ultima_hasta', 'proxima', 'proxima_desde', 'proxima_hasta', 'visitas_min', 'visitas_max', 'gasto_min', 'gasto_max', 'saldo', 'dias_activo', 'orden', 'dir', 'por_pagina', 'indicador'];

    /**
     * Query string con los filtros vigentes (los mismos que producen la lista) para enlaces de paginación, orden, exportación y
     * «Ver listado». `$extra` reemplaza claves; un valor null la quita. Los importes (guardados en centavos) vuelven a dólares.
     * @param array<string,mixed> $f filtros normalizados
     * @param array<string,mixed> $extra
     */
    public static function consulta(array $f, array $extra = []): string
    {
        $datos = [];
        foreach (self::CLAVES_CONSULTA as $k) {
            $v = $f[$k] ?? null;
            if ($v === null || $v === '') { continue; }
            if (($k === 'gasto_min' || $k === 'gasto_max') && is_int($v)) { $v = number_format($v / 100, 2, '.', ''); }
            $datos[$k] = $v;
        }
        foreach ($extra as $k => $v) { if ($v === null) { unset($datos[$k]); } else { $datos[$k] = $v; } }
        return http_build_query($datos);
    }

    /** Claves de filtro que impone cada indicador (el resto de indicadores no toca nada más). */
    private const CLAVES_INDICADOR = [
        'altas' => ['alta_desde', 'alta_hasta'], 'activos' => ['actividad'], 'con_visita' => ['visitas_min'], 'repiten' => ['visitas_min'],
        'membresia_activo' => ['membresia'], 'membresia_suspendido' => ['membresia'], 'membresia_sin_registro' => ['membresia'],
        'consentimiento_si' => ['consentimiento'], 'consentimiento_vigente' => ['consentimiento'], 'consentimiento_anterior' => ['consentimiento'],
        'comunicaciones_revocado' => ['consentimiento'], 'comunicaciones_sin_decision' => ['consentimiento'], 'cuenta_sin_confirmar' => ['cuenta'], 'con_saldo' => ['saldo'],
    ];

    /** Definición visible de cada filtro (se muestra en la pantalla y se prueba que no falte ninguna). */
    public const DEFINICIONES = [
        'q' => 'Busca por nombre, apellido, correo o teléfono (hasta 80 caracteres).',
        'vinculo' => 'Por defecto se muestran clientes externos. El propietario y las demás cuentas laborales quedan fuera. Para consultar a personas del equipo que también tienen membresía de cliente o citas, elige «Equipo con perfil de cliente» o «Todos los perfiles de cliente».',
        'cuenta' => 'Cuenta: «Confirmada» = la persona ya confirmó su correo y puede entrar; «Sin confirmar» = creó o recibió la cuenta pero aún no la activa.',
        'membresia' => 'Membresía de cliente: estado de la ficha de cliente (activa o suspendida). No es una suscripción de pago: los contratos comerciales se gestionan por separado en Suscripciones.',
        'consentimiento' => 'Comunicaciones comerciales: consentimiento para recibir promociones por correo. «Otorgado» muestra la versión del texto aceptada y la fecha; «Revocado» = su última decisión fue retirarlo; «Sin decisión» = nunca decidió. Es lo único que en esta pantalla se llama «suscripción»: la suscripción a comunicaciones comerciales.',
        'actividad' => 'Actividad comercial según visitas completadas: «Reciente» = última visita dentro de la ventana de días de actividad; «Inactivo por periodo» = tuvo visitas, pero la última es más antigua que esa ventana; «Nunca atendido» = ninguna visita completada.',
        'segmento' => 'Segmento: cada cliente cae en uno solo de los segmentos de la tabla «Segmentos y reglas». Las reglas están más abajo.',
        'alta' => 'Fecha de alta: cuándo se creó su membresía de cliente.',
        'ultima' => 'Última visita: fecha de su última cita completada (hasta hoy).',
        'proxima' => 'Próxima cita: la primera cita futura (hoy incluido) en estado confirmada o en servicio.',
        'profesional' => 'Atendido por: clientes con al menos una visita completada con esa persona dentro del periodo.',
        'servicio' => 'Servicio recibido: clientes con al menos una visita completada con ese servicio dentro del periodo.',
        'frecuencia' => 'Frecuencia: número de visitas completadas dentro del periodo analizado (con el profesional y servicio elegidos, si los hay).',
        'gasto' => 'Gasto: cobros reales menos devoluciones del periodo, en dólares; excluye la pasarela simulada. Solo lo ve quien tiene permiso de analítica.',
        'saldo' => 'Saldo pendiente: importe de citas ya atendidas que aún no están cobradas (total de sus servicios menos cobros reales confirmados). Solo lo ve quien tiene permiso de analítica.',
        'periodo' => 'Periodo: rango de fechas que usan las visitas del periodo, la frecuencia, el gasto y los gráficos (nunca después de hoy).',
    ];

    private CrmRepository $repo;
    private ?\DateTimeImmutable $ahora;
    private int $maxFilasExportacion = self::EXPORT_MAX_FILAS;

    /** Tope de filas por archivo (por omisión EXPORT_MAX_FILAS). Existe para pruebas de truncado y para ajustarlo sin tocar el código de exportación. */
    public function setMaxFilasExportacion(int $max): void { $this->maxFilasExportacion = max(1, $max); }

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
        $query = \Classes\PeriodoFiltro::aplicar($query, historico: true, ahora: $this->hoy());
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
        $vinculo = $lista('vinculo', 'El vínculo con el negocio', array_keys(self::VINCULOS)) ?? 'externo';
        $consentimiento = $lista('consentimiento', 'El filtro de consentimiento (comunicaciones comerciales)', array_keys(self::CONSENTIMIENTOS));
        $cuenta = $lista('cuenta', 'El estado de la cuenta', array_keys(self::CUENTAS));
        $actividad = $lista('actividad', 'La actividad comercial', array_keys(self::ACTIVIDADES));
        $proxima = $lista('proxima', 'El filtro de próxima cita', array_keys(self::PROXIMAS));
        $diasActivo = $entero('dias_activo', 'La ventana de clientes activos', self::DIAS_ACTIVO_MIN, self::DIAS_ACTIVO_MAX) ?? self::DIAS_ACTIVO_DEFECTO;

        $ultimaDesde = $fecha('ultima_desde', 'La fecha inicial de última visita');
        $ultimaHasta = $fecha('ultima_hasta', 'La fecha final de última visita');
        if ($ultimaDesde !== null && $ultimaHasta !== null && $ultimaDesde > $ultimaHasta) { $errores[] = 'La fecha inicial de última visita no puede ser posterior a la final.'; $ultimaDesde = $ultimaHasta = null; }
        $proximaDesde = $fecha('proxima_desde', 'La fecha inicial de próxima cita');
        $proximaHasta = $fecha('proxima_hasta', 'La fecha final de próxima cita');
        if ($proximaDesde !== null && $proximaHasta !== null && $proximaDesde > $proximaHasta) { $errores[] = 'La fecha inicial de próxima cita no puede ser posterior a la final.'; $proximaDesde = $proximaHasta = null; }
        $visitasMin = $entero('visitas_min', 'Las visitas mínimas', 0, 9999);
        $visitasMax = $entero('visitas_max', 'Las visitas máximas', 0, 9999);
        if ($visitasMin !== null && $visitasMax !== null && $visitasMin > $visitasMax) { $errores[] = 'Las visitas mínimas no pueden superar a las máximas.'; $visitasMin = $visitasMax = null; }

        // Importes (gasto y saldo): solo con permiso de analítica. Sin él se rechazan (422) y NO se aplican ni se ignoran en silencio.
        $dinero = function (string $clave, string $etiqueta) use ($texto, &$errores, $acceso): ?int {
            $v = $texto($clave);
            if ($v === null) { return null; }
            if (empty($acceso['analitica'])) { $errores[] = "{$etiqueta} no está disponible para su rol."; return null; }
            if (!preg_match('/^(\d{1,7})(?:[.,](\d{1,2}))?$/', $v, $m)) { $errores[] = "{$etiqueta} debe ser un importe en dólares, por ejemplo 25 o 25.50."; return null; }
            return (int)$m[1] * 100 + (int)str_pad($m[2] ?? '0', 2, '0');
        };
        $gastoMin = $dinero('gasto_min', 'El gasto mínimo');
        $gastoMax = $dinero('gasto_max', 'El gasto máximo');
        if ($gastoMin !== null && $gastoMax !== null && $gastoMin > $gastoMax) { $errores[] = 'El gasto mínimo no puede superar al máximo.'; $gastoMin = $gastoMax = null; }
        $saldo = $lista('saldo', 'El filtro de saldo pendiente', array_keys(self::SALDOS));
        if ($saldo !== null && empty($acceso['analitica'])) { $errores[] = 'El filtro de saldo pendiente no está disponible para su rol.'; $saldo = null; }

        $ordenesPermitidos = !empty($acceso['analitica']) ? self::ORDENES : array_values(array_diff(self::ORDENES, self::ORDENES_ANALITICA));
        $orden = $lista('orden', 'El orden', $ordenesPermitidos) ?? 'nombre';
        $dir = $lista('dir', 'La dirección del orden', ['asc', 'desc']) ?? 'asc';
        $porPagina = $entero('por_pagina', 'El tamaño de página', 1, 100);
        if ($porPagina !== null && !in_array($porPagina, self::POR_PAGINA, true)) { $errores[] = 'El tamaño de página debe ser ' . implode(', ', self::POR_PAGINA) . '.'; $porPagina = null; }
        $pagina = $entero('pagina', 'La página', 1, 1000000) ?? 1;
        $indicadoresPermitidos = array_keys(array_filter(self::INDICADORES, static fn(array $i): bool => !$i['analitica'] || !empty($acceso['analitica'])));
        $indicador = $lista('indicador', 'El indicador', $indicadoresPermitidos);

        $filtros = [
            'q' => $q, 'vinculo' => $vinculo, 'desde' => $desde, 'hasta' => $hasta, 'alta_desde' => $altaDesde, 'alta_hasta' => $altaHasta,
            'profesional_id' => $profesional, 'servicio_id' => $servicio, 'segmento' => $segmento, 'membresia' => $membresia,
            'consentimiento' => $consentimiento, 'cuenta' => $cuenta, 'actividad' => $actividad,
            'ultima_desde' => $ultimaDesde, 'ultima_hasta' => $ultimaHasta, 'proxima' => $proxima, 'proxima_desde' => $proximaDesde, 'proxima_hasta' => $proximaHasta,
            'visitas_min' => $visitasMin, 'visitas_max' => $visitasMax, 'gasto_min' => $gastoMin, 'gasto_max' => $gastoMax, 'saldo' => $saldo,
            'dias_activo' => $diasActivo, 'orden' => $orden, 'dir' => $dir,
            'pagina' => $pagina, 'por_pagina' => $porPagina ?? self::POR_PAGINA_DEFECTO,
            'indicador' => $indicador,
        ];
        return ['filtros' => self::aplicarIndicador($filtros, $hoy->format('Y-m-d')), 'errores' => array_values(array_unique($errores))];
    }

    /**
     * Impone sobre los filtros los que define el indicador elegido. El servidor es quien decide: la URL no puede pedir un título
     * («Clientes activos») con otro contenido. Los demás filtros (búsqueda, profesional, periodo…) se conservan: el listado explica
     * la cifra del universo filtrado vigente.
     * @param array<string,mixed> $f
     * @return array<string,mixed>
     */
    public static function aplicarIndicador(array $f, string $hoy): array
    {
        $i = (string)($f['indicador'] ?? '');
        $f['previos'] = [];
        if ($i === '' || !isset(self::INDICADORES[$i])) { $f['indicador'] = null; return $f; }
        // Valores que traía la consulta en las claves que el indicador va a imponer: permiten «Volver al panel» sin perderlos.
        foreach (self::CLAVES_INDICADOR[$i] ?? [] as $k) { $f['previos'][$k] = $f[$k] ?? null; }
        switch ($i) {
            case 'altas': $f['alta_desde'] = $f['desde']; $f['alta_hasta'] = min((string)$f['hasta'], $hoy); break;
            case 'activos': $f['actividad'] = 'reciente'; break;
            case 'con_visita': $f['visitas_min'] = max(1, (int)($f['visitas_min'] ?? 1)); break;
            case 'repiten': $f['visitas_min'] = max(2, (int)($f['visitas_min'] ?? 2)); break;
            case 'membresia_activo': $f['membresia'] = 'activo'; break;
            case 'membresia_suspendido': $f['membresia'] = 'suspendido'; break;
            case 'membresia_sin_registro': $f['membresia'] = 'sin_registro'; break;
            case 'consentimiento_si': $f['consentimiento'] = 'si'; break;
            case 'consentimiento_vigente': $f['consentimiento'] = 'vigente'; break;
            case 'consentimiento_anterior': $f['consentimiento'] = 'anterior'; break;
            case 'comunicaciones_revocado': $f['consentimiento'] = 'revocado'; break;
            case 'comunicaciones_sin_decision': $f['consentimiento'] = 'sin_decision'; break;
            case 'cuenta_sin_confirmar': $f['cuenta'] = 'sin_confirmar'; break;
            case 'con_saldo': $f['saldo'] = 'con'; break;
        }
        return $f;
    }

    /** Filtros de la consulta SIN lo que impuso el indicador (para volver al panel con los filtros originales). */
    public static function sinIndicador(array $f): array
    {
        foreach ($f['previos'] ?? [] as $k => $v) { $f[$k] = $v; }
        $f['indicador'] = null;
        return $f;
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

    private function rfm(string $hoy, int $diasActivo, string $vinculo = 'todos'): array
    {
        return self::calcularRfm($this->repo->poblacionRfm($hoy, self::VENTANA_RFM_DIAS, $vinculo), $diasActivo);
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
    public function panel(array $f, array $acceso, ?int $profesionalId = null, bool $incluirListado = true): array
    {
        $modo = self::modo($acceso);
        if ($modo === null) { throw new \DomainException('Sin permisos de clientes.'); }
        $hoy = $this->hoy()->format('Y-m-d');
        if ($modo === 'propios') { return $this->panelProfesional($f, $profesionalId, $hoy); }

        $rfm = !empty($acceso['analitica']) ? $this->rfm($hoy, (int)$f['dias_activo'], $f['vinculo'] ?? 'externo') : null;
        $c = $this->contexto($f, $acceso, $rfm['vip_ids'] ?? []);
        // UN SOLO UNIVERSO: indicadores, citas, gráficos, segmentos, totales y listado parten de los mismos filtros `$f`.
        $ind = $this->repo->indicadores($c, $f);
        $citas = $this->repo->citasPorEstado($c, $f);
        $totalCitas = array_sum($citas);
        $conocidas = (int)($citas['completada'] ?? 0) + (int)($citas['cancelada'] ?? 0) + (int)($citas['no_asistio'] ?? 0) + (int)($citas['confirmada'] ?? 0) + (int)($citas['en_servicio'] ?? 0);
        $total = (int)($ind['total'] ?? 0);
        $conVisita = (int)($ind['con_visita_rango'] ?? 0);
        $visitas = (int)($ind['visitas_rango'] ?? 0);
        $fid = $this->repo->fidelidadAgregada($c, $f);

        $resumen = [
            'clientes_total' => $total,
            'membresias' => ['activo' => (int)($ind['m_activa'] ?? 0), 'suspendido' => (int)($ind['m_suspendida'] ?? 0), 'sin_registro' => (int)($ind['m_sin_registro'] ?? 0)],
            'cuenta_confirmada' => (int)($ind['cuenta_confirmada'] ?? 0),
            'cuenta_sin_confirmar' => (int)($ind['cuenta_sin_confirmar'] ?? 0),
            'comunicaciones' => $this->repo->comunicacionesPorEstado($c, $f),
            'con_saldo' => !empty($acceso['analitica']) ? (int)($ind['con_saldo'] ?? 0) : null,
            'saldo_total' => !empty($acceso['analitica']) ? (int)($ind['saldo_total'] ?? 0) : null,
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
        $altas = $this->repo->altasPorMes($c, $f);
        $visitasMes = $this->repo->visitasPorMes($c, $f);
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
            $g = $this->repo->gastoAgregado($c, $f);
            $neto = $g['cobros_real'] - $g['dev_real'];
            $resumen['gasto'] = [
                'cobros_real' => $g['cobros_real'], 'devoluciones_real' => $g['dev_real'], 'neto_real' => $neto, 'n_cobros_real' => $g['n_cobros_real'],
                'ticket_por_cobro' => $g['n_cobros_real'] > 0 ? (int)round($g['cobros_real'] / $g['n_cobros_real']) : null,
                'neto_simulado' => $g['cobros_sim'] - $g['dev_sim'], 'clientes_con_gasto' => $g['clientes_con_gasto'],
            ];
            $cobros = $this->repo->cobrosPorMes($c, $f);
            $series['cobros'] = array_map(static fn($m) => ['etiqueta' => $m, 'valor' => (int)($cobros[$m]['cobros'] ?? 0), 'devoluciones' => (int)($cobros[$m]['devoluciones'] ?? 0)], $meses);
        }

        $dist = $this->repo->distribucionSegmentos($c, $f);
        $segmentos = [];
        foreach (self::SEGMENTOS as $clave => $etiqueta) {
            if ($clave === 'vip' && empty($acceso['analitica'])) { continue; }
            $segmentos[] = ['clave' => $clave, 'etiqueta' => $etiqueta, 'valor' => (int)($dist[$clave] ?? 0)];
        }

        // Retención y cohortes pertenecen a la analítica: sin ese permiso ni siquiera se consultan.
        $cohortes = !empty($acceso['analitica']) ? array_reverse($this->repo->cohortes($c, self::VENTANA_RETENCION_DIAS, 12, $f)) : [];
        $cohortes = array_map(function (array $r) use ($hoy): array {
            $finMes = (new \DateTimeImmutable($r['cohorte'] . '-01'))->modify('last day of this month');
            return ['cohorte' => $r['cohorte'], 'clientes' => (int)$r['clientes'], 'repiten' => (int)$r['repiten'],
                'pct' => self::porcentaje((int)$r['repiten'], (int)$r['clientes']),
                'madura' => $finMes->modify('+' . self::VENTANA_RETENCION_DIAS . ' days')->format('Y-m-d') <= $hoy];
        }, $cohortes);

        $listado = null;
        if ($modo === 'completo' && $incluirListado) {
            $listado = $this->paginaListado($c, $f);
        }

        return ['modo' => $modo, 'hoy' => $hoy, 'filtros' => $f, 'filtros_activos' => $this->filtrosActivos($f, $this->repo->catalogos(), $acceso), 'resumen' => $resumen, 'series' => $series, 'segmentos' => $segmentos,
            'servicios' => $this->repo->serviciosMasUsados($c, 8, $f), 'cohortes' => $cohortes, 'rfm' => $rfm, 'listado' => $listado];
    }

    /**
     * Una página del listado y los totales del MISMO universo (conteo, visitas, gasto y saldo). La pantalla, el archivo exportado
     * y las pruebas leen estas mismas cifras.
     * @return array<string,mixed>
     */
    private function paginaListado(array $c, array $f): array
    {
        $totales = $this->repo->totales($c, $f);
        $pag = self::paginar($totales['clientes'], (int)$f['pagina'], (int)$f['por_pagina']);
        // Lista comercial con los mismos filtros: cuántos contactos tendría el archivo (solo el texto vigente, cuenta confirmada y membresía no suspendida).
        $comercial = $this->repo->contar($c, $f + ['comercial' => true]);
        return $pag + ['filas' => $totales['clientes'] > 0 ? $this->repo->listar($c, $f, $pag['por_pagina'], $pag['offset']) : [], 'totales' => $totales, 'comercial_total' => $comercial];
    }

    /**
     * Datos de `GET /admin/clientes/listado`: la lista real que explica un indicador, un segmento o cualquier combinación de filtros.
     * Modo completo = clientes.ver (datos personales); modo propios = solo sus clientes; sin ninguno de los dos, no hay listado.
     * @return array<string,mixed>
     */
    public function listado(array $f, array $acceso, ?int $profesionalId = null): array
    {
        $modo = self::modo($acceso);
        if ($modo !== 'completo' && $modo !== 'propios') { throw new \DomainException('Sin permiso para ver listados nominales de clientes.'); }
        $hoy = $this->hoy()->format('Y-m-d');
        if ($modo === 'propios') { return $this->panelProfesional($f, $profesionalId, $hoy) + ['filtros_activos' => $this->filtrosActivos($f, [], $acceso)]; }
        $rfm = !empty($acceso['analitica']) ? $this->rfm($hoy, (int)$f['dias_activo'], $f['vinculo'] ?? 'externo') : null;
        $c = $this->contexto($f, $acceso, $rfm['vip_ids'] ?? []);
        $catalogos = $this->repo->catalogos();
        $ind = $f['indicador'] !== null ? (self::INDICADORES[$f['indicador']] ?? null) : null;
        return ['modo' => 'completo', 'hoy' => $hoy, 'filtros' => $f, 'listado' => $this->paginaListado($c, $f), 'filtros_activos' => $this->filtrosActivos($f, $catalogos, $acceso),
            'indicador' => $ind !== null ? ['clave' => $f['indicador']] + $ind : null, 'hasta_efectivo' => $c['hasta']];
    }

    /**
     * Filtros activos para mostrarlos como chips: etiqueta, valor legible y las claves de consulta que los quitan.
     * El periodo y la ventana de actividad solo aparecen si difieren de sus valores por defecto.
     * @param array<string,array> $catalogos ['servicios'=>[..], 'profesionales'=>[..]]
     * @return array<int,array{clave:string,etiqueta:string,valor:string,quitar:string[]}>
     */
    public function filtrosActivos(array $f, array $catalogos, array $acceso): array
    {
        $chips = [];
        $add = static function (string $clave, string $etiqueta, string $valor, array $quitar) use (&$chips): void { $chips[] = ['clave' => $clave, 'etiqueta' => $etiqueta, 'valor' => $valor, 'quitar' => $quitar]; };
        $hoy = $this->hoy();
        $defDesde = $hoy->modify('first day of this month')->modify('-11 months')->format('Y-m-d');
        $defHasta = $hoy->format('Y-m-d');
        $nombre = static function (array $lista, int $id): string { foreach ($lista as $x) { if ((int)$x['id'] === $id) { return (string)$x['nombre']; } } return '#' . $id; };
        if (($f['indicador'] ?? null) !== null && isset(self::INDICADORES[$f['indicador']])) { $add('indicador', 'Indicador', self::INDICADORES[$f['indicador']]['etiqueta'], ['indicador']); }
        if ((string)($f['q'] ?? '') !== '') { $add('q', 'Búsqueda', (string)$f['q'], ['q']); }
        if (isset($f['vinculo']) && $f['vinculo'] !== 'externo') { $add('vinculo', 'Vínculo', self::VINCULOS[$f['vinculo']] ?? '', ['vinculo']); }
        if (($f['desde'] ?? $defDesde) !== $defDesde || ($f['hasta'] ?? $defHasta) !== $defHasta) { $add('periodo', 'Periodo', ($f['desde'] ?? '') . ' a ' . ($f['hasta'] ?? ''), ['desde', 'hasta']); }
        if ((int)($f['dias_activo'] ?? self::DIAS_ACTIVO_DEFECTO) !== self::DIAS_ACTIVO_DEFECTO) { $add('dias_activo', 'Ventana de actividad', (int)$f['dias_activo'] . ' días', ['dias_activo']); }
        $rango = static fn(?string $d, ?string $h): string => ($d !== null ? 'desde ' . $d : '') . ($d !== null && $h !== null ? ' ' : '') . ($h !== null ? 'hasta ' . $h : '');
        if (!empty($f['alta_desde']) || !empty($f['alta_hasta'])) { $add('alta', 'Alta', $rango($f['alta_desde'] ?? null, $f['alta_hasta'] ?? null), ['alta_desde', 'alta_hasta']); }
        if (!empty($f['profesional_id'])) { $add('profesional_id', 'Atendido por', $nombre($catalogos['profesionales'] ?? [], (int)$f['profesional_id']), ['profesional_id']); }
        if (!empty($f['servicio_id'])) { $add('servicio_id', 'Servicio recibido', $nombre($catalogos['servicios'] ?? [], (int)$f['servicio_id']), ['servicio_id']); }
        if (!empty($f['segmento'])) { $add('segmento', 'Segmento', self::SEGMENTOS[$f['segmento']] ?? '', ['segmento']); }
        if (!empty($f['cuenta'])) { $add('cuenta', 'Cuenta', self::CUENTAS[$f['cuenta']] ?? '', ['cuenta']); }
        if (!empty($f['membresia'])) { $add('membresia', 'Membresía', self::MEMBRESIAS[$f['membresia']] ?? '', ['membresia']); }
        if (!empty($f['consentimiento'])) { $add('consentimiento', 'Comunicaciones comerciales', self::CONSENTIMIENTOS[$f['consentimiento']] ?? '', ['consentimiento']); }
        if (!empty($f['actividad'])) { $add('actividad', 'Actividad comercial', (self::ACTIVIDADES[$f['actividad']] ?? '') . ($f['actividad'] === 'nunca' ? '' : ' (' . (int)($f['dias_activo'] ?? self::DIAS_ACTIVO_DEFECTO) . ' días)'), ['actividad']); }
        if (!empty($f['ultima_desde']) || !empty($f['ultima_hasta'])) { $add('ultima', 'Última visita', $rango($f['ultima_desde'] ?? null, $f['ultima_hasta'] ?? null), ['ultima_desde', 'ultima_hasta']); }
        if (!empty($f['proxima'])) { $add('proxima', 'Próxima cita', self::PROXIMAS[$f['proxima']] ?? '', ['proxima']); }
        if (!empty($f['proxima_desde']) || !empty($f['proxima_hasta'])) { $add('proxima_fecha', 'Fecha de próxima cita', $rango($f['proxima_desde'] ?? null, $f['proxima_hasta'] ?? null), ['proxima_desde', 'proxima_hasta']); }
        if (($f['visitas_min'] ?? null) !== null || ($f['visitas_max'] ?? null) !== null) {
            $add('visitas', 'Visitas en el periodo', (($f['visitas_min'] ?? null) !== null ? 'mín. ' . (int)$f['visitas_min'] : '') . (($f['visitas_min'] ?? null) !== null && ($f['visitas_max'] ?? null) !== null ? ' · ' : '') . (($f['visitas_max'] ?? null) !== null ? 'máx. ' . (int)$f['visitas_max'] : ''), ['visitas_min', 'visitas_max']);
        }
        if (!empty($acceso['analitica'])) {
            if (($f['gasto_min'] ?? null) !== null || ($f['gasto_max'] ?? null) !== null) {
                $add('gasto', 'Gasto neto real', (($f['gasto_min'] ?? null) !== null ? 'mín. ' . self::dinero((int)$f['gasto_min']) : '') . (($f['gasto_min'] ?? null) !== null && ($f['gasto_max'] ?? null) !== null ? ' · ' : '') . (($f['gasto_max'] ?? null) !== null ? 'máx. ' . self::dinero((int)$f['gasto_max']) : ''), ['gasto_min', 'gasto_max']);
            }
            if (!empty($f['saldo'])) { $add('saldo', 'Saldo pendiente', self::SALDOS[$f['saldo']] ?? '', ['saldo']); }
        }
        return $chips;
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

    // ---------------------------------------------------------------- exportación (CSV y XLSX por Classes\Exportacion\Exportador)

    /** Estado de comunicaciones comerciales de una fila del listado, con versión y fecha, en texto para pantalla y archivo. */
    public static function textoComunicaciones(array $r): string
    {
        $estado = (string)($r['comunicaciones'] ?? ((int)($r['consentimiento'] ?? 0) === 1 ? 'otorgado' : 'sin_decision'));
        if ($estado === 'otorgado') {
            return 'Otorgado' . ($r['consentimiento_version'] !== null && $r['consentimiento_version'] !== '' ? ' (texto v' . (int)$r['consentimiento_version'] . ')' : '') . ($r['consentimiento_en'] ? ' el ' . substr((string)$r['consentimiento_en'], 0, 10) : '');
        }
        if ($estado === 'revocado') {
            return 'Revocado' . (isset($r['revocado_version']) && $r['revocado_version'] !== null ? ' (texto v' . (int)$r['revocado_version'] . ')' : '') . (!empty($r['revocado_en']) ? ' el ' . self::fechaLocalDeUtc((string)$r['revocado_en']) : '');
        }
        return 'Sin decisión';
    }

    /** Fecha (AAAA-MM-DD) en America/Guayaquil de un instante guardado en UTC. */
    public static function fechaLocalDeUtc(string $utc): string
    {
        try { return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('Y-m-d'); }
        catch (\Throwable) { return substr($utc, 0, 10); }
    }

    /**
     * Plan de una exportación: columnas tipadas, un GENERADOR de filas (memoria acotada: lotes de 1000), la fila de totales y las
     * opciones para Exportador::generar(). Usa el mismo universo que el listado (repo->listar/totales con los mismos `$f`).
     * `$tipo` 'clientes' (operativo, sin datos privados de formularios) o 'comercial' (solo quienes otorgaron el texto vigente,
     * con cuenta confirmada y membresía no suspendida). Nunca incluye respuestas de formularios.
     *
     * @return array{columnas:array,filas:\Generator,totales:array,base:string,opciones:array,universo:array,tipo:string}
     */
    public function prepararExportacion(array $f, array $acceso, string $tipo): array
    {
        if (!in_array($tipo, ['clientes', 'comercial'], true)) { throw new \InvalidArgumentException('Tipo de exportación no válido.'); }
        if (empty($acceso['ver'])) { throw new \DomainException('Sin permisos de clientes.'); }
        $hoy = $this->hoy()->format('Y-m-d');
        $comercial = $tipo === 'comercial';
        $analitica = !empty($acceso['analitica']) && !$comercial;
        $rfm = !empty($acceso['analitica']) && !$comercial ? $this->rfm($hoy, (int)$f['dias_activo'], $f['vinculo'] ?? 'externo') : null;
        $c = $this->contexto($f, $acceso, $rfm['vip_ids'] ?? []);
        $c['analitica'] = $analitica;
        $f['comercial'] = $comercial;
        $f['pagina'] = 1;
        $universo = $this->repo->totales($c, $f);

        if ($comercial) {
            $columnas = [
                ['clave' => 'id', 'titulo' => 'ID', 'tipo' => 'entero'], ['clave' => 'nombre', 'titulo' => 'Nombre', 'ancho' => 20], ['clave' => 'apellido', 'titulo' => 'Apellido', 'ancho' => 20],
                ['clave' => 'email', 'titulo' => 'Correo', 'ancho' => 34], ['clave' => 'consentimiento_en', 'titulo' => 'Consentimiento desde', 'tipo' => 'fechahora'],
                ['clave' => 'consentimiento_version', 'titulo' => 'Versión del consentimiento', 'tipo' => 'entero'],
            ];
            $totales = ['nombre' => $universo['clientes'] . ' contactos'];
        } else {
            $columnas = [
                ['clave' => 'id', 'titulo' => 'ID', 'tipo' => 'entero'], ['clave' => 'nombre', 'titulo' => 'Nombre', 'ancho' => 20], ['clave' => 'apellido', 'titulo' => 'Apellido', 'ancho' => 20],
                ['clave' => 'email', 'titulo' => 'Correo', 'ancho' => 34], ['clave' => 'telefono', 'titulo' => 'Teléfono'], ['clave' => 'membresia', 'titulo' => 'Estado de membresía'],
                ['clave' => 'origen', 'titulo' => 'Origen'], ['clave' => 'alta', 'titulo' => 'Fecha de alta', 'tipo' => 'fecha'], ['clave' => 'confirmado', 'titulo' => 'Cuenta confirmada'],
                ['clave' => 'consentimiento', 'titulo' => 'Consentimiento comercial'], ['clave' => 'consentimiento_en', 'titulo' => 'Fecha de consentimiento', 'tipo' => 'fechahora'],
                ['clave' => 'consentimiento_version', 'titulo' => 'Versión del consentimiento', 'tipo' => 'entero'], ['clave' => 'consentimiento_anterior', 'titulo' => 'Consentimiento con versión anterior'],
                ['clave' => 'comunicaciones', 'titulo' => 'Comunicaciones comerciales (estado)', 'ancho' => 36], ['clave' => 'revocado_en', 'titulo' => 'Fecha de revocación', 'tipo' => 'fecha'],
                ['clave' => 'visitas_total', 'titulo' => 'Visitas completadas (total)', 'tipo' => 'entero'], ['clave' => 'visitas_rango', 'titulo' => 'Visitas completadas (periodo y filtros)', 'tipo' => 'entero'],
                ['clave' => 'primera_visita', 'titulo' => 'Primera visita', 'tipo' => 'fecha'], ['clave' => 'ultima_visita', 'titulo' => 'Última visita', 'tipo' => 'fecha'],
                ['clave' => 'recencia', 'titulo' => 'Días desde la última visita', 'tipo' => 'entero'], ['clave' => 'proxima_cita', 'titulo' => 'Próxima cita', 'tipo' => 'fecha'],
                ['clave' => 'segmento', 'titulo' => 'Segmento'],
            ];
            if ($analitica) {
                array_push($columnas, ['clave' => 'cobros_real', 'titulo' => 'Cobros reales (USD)', 'tipo' => 'dinero'], ['clave' => 'dev_real', 'titulo' => 'Devoluciones reales (USD)', 'tipo' => 'dinero'],
                    ['clave' => 'gasto_neto', 'titulo' => 'Gasto neto real (USD)', 'tipo' => 'dinero'], ['clave' => 'neto_sim', 'titulo' => 'Neto simulado (USD, no es ingreso)', 'tipo' => 'dinero'],
                    ['clave' => 'saldo', 'titulo' => 'Saldo pendiente (USD)', 'tipo' => 'dinero']);
            }
            $totales = ['nombre' => $universo['clientes'] . ' clientes', 'visitas_rango' => $universo['visitas']];
            if ($analitica) { $totales += ['cobros_real' => $universo['cobros_real'], 'dev_real' => $universo['dev_real'], 'gasto_neto' => $universo['gasto_neto'], 'neto_sim' => $universo['neto_sim'], 'saldo' => $universo['saldo']]; }
        }

        $repo = $this->repo;
        $filas = (static function () use ($repo, $c, $f, $comercial, $analitica): \Generator {
            $lote = 1000; $offset = 0;
            while (true) {
                $pagina = $repo->listar($c, $f, $lote, $offset);
                foreach ($pagina as $r) {
                    $fecha = static fn($v): string => $v === null || $v === '' ? '' : substr((string)$v, 0, 10);
                    if ($comercial) {
                        yield ['id' => (int)$r['id'], 'nombre' => $r['nombre'], 'apellido' => $r['apellido'], 'email' => $r['email'], 'consentimiento_en' => (string)$r['consentimiento_en'],
                            'consentimiento_version' => $r['consentimiento_version'] === null ? '' : (int)$r['consentimiento_version']];
                        continue;
                    }
                    $fila = ['id' => (int)$r['id'], 'nombre' => $r['nombre'], 'apellido' => $r['apellido'], 'email' => $r['email'], 'telefono' => (string)$r['telefono'],
                        'membresia' => self::MEMBRESIAS[$r['membresia']] ?? $r['membresia'], 'origen' => (string)$r['origen'], 'alta' => $fecha($r['alta']),
                        'confirmado' => (int)$r['confirmado'] === 1 ? 'Sí' : 'No', 'consentimiento' => (int)$r['consentimiento'] === 1 ? 'Sí' : 'No',
                        'consentimiento_en' => (string)$r['consentimiento_en'], 'consentimiento_version' => $r['consentimiento_version'] === null ? '' : (int)$r['consentimiento_version'],
                        'consentimiento_anterior' => (int)$r['consentimiento_anterior'] === 1 ? 'Sí' : 'No', 'comunicaciones' => self::textoComunicaciones($r),
                        'revocado_en' => $r['revocado_en'] ? self::fechaLocalDeUtc((string)$r['revocado_en']) : '',
                        'visitas_total' => (int)$r['visitas_total'], 'visitas_rango' => (int)$r['visitas_rango'], 'primera_visita' => $fecha($r['primera_visita']), 'ultima_visita' => $fecha($r['ultima_visita']),
                        'recencia' => $r['recencia'] === null ? '' : (int)$r['recencia'], 'proxima_cita' => $fecha($r['proxima_cita']), 'segmento' => self::SEGMENTOS[$r['segmento']] ?? $r['segmento']];
                    if ($analitica) {
                        $fila += ['cobros_real' => (int)$r['cobros_real'], 'dev_real' => (int)$r['dev_real'], 'gasto_neto' => (int)$r['cobros_real'] - (int)$r['dev_real'],
                            'neto_sim' => (int)$r['cobros_sim'] - (int)$r['dev_sim'], 'saldo' => (int)$r['saldo']];
                    }
                    yield $fila;
                }
                if (count($pagina) < $lote) { break; }
                $offset += $lote;
            }
        })();
        return [
            'tipo' => $tipo, 'columnas' => $columnas, 'filas' => $filas, 'totales' => $totales, 'universo' => $universo,
            'base' => ($comercial ? 'lista_comercial_' : 'clientes_') . $f['desde'] . '_a_' . min($f['hasta'], $hoy),
            'opciones' => ['max_filas' => $this->maxFilasExportacion, 'hoja' => $comercial ? 'Lista comercial' : 'Clientes',
                'aviso_truncado' => 'AVISO: exportación limitada a ' . $this->maxFilasExportacion . ' filas de ' . $universo['clientes'] . ' que tiene este filtro; afine los filtros para obtener el resto.'],
        ];
    }
}

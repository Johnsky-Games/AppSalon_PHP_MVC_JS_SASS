<?php

namespace Controllers;

use Model\ActiveRecord;
use MVC\Router;
use Classes\Exportacion\Exportador;
use Repositories\ExportacionAuditoriaRepository;
use Repositories\ReporteRepository;
use Services\ExportacionAuditoriaService;
use Services\ReporteService;

/**
 * Controlador Administrativo para Reportes de Reservas, Ocupación e Historial Financiero:
 * - `GET /admin/reportes`: Panel administrativo con filtros por rango de fechas (`fecha_inicio`, `fecha_fin`),
 *   criterio de fecha (`criterio_fecha`: servicio vs cobro), profesional (`profesional_id`) y servicio (`servicio_id`).
 * - Distingue explícitamente dimensiones operativas (ocupación sobre agenda) y contables (cobros confirmados,
 *   reembolsos, neto cobrado e importes simulados desacoplados en la tabla `pagos`).
 * - `GET /admin/reportes/exportar`: Exportación CSV segura de los datos filtrados y snapshots históricos inmutables.
 */
class AdminReporteController
{
    protected static ?ReporteService $reporteService = null;

    /**
     * Registro de las últimas cabeceras HTTP preparadas en `exportarCsv()` para inspección en pruebas.
     * @var array<string, string>
     */
    protected static array $ultimosHeadersExportacion = [];

    public static function setReporteService(?ReporteService $service): void
    {
        self::$reporteService = $service;
    }

    /**
     * @return array<string, string>
     */
    public static function getUltimosHeadersExportacion(): array
    {
        return self::$ultimosHeadersExportacion;
    }

    protected static function obtenerReporteService(): ReporteService
    {
        if (self::$reporteService !== null) {
            return self::$reporteService;
        }

        $db = ActiveRecord::getDB();
        return new ReporteService(new ReporteRepository($db));
    }

    /**
     * Los reportes financieros solo necesitan importes y servicios: quien no tiene `clientes.ver` (p. ej. finanzas)
     * no recibe nombre, correo ni teléfono de clientes ni en pantalla ni en el CSV (QA DEF-R2-01).
     */
    protected static function ocultarDatosDeClientesSiCorresponde(array $reporte): array
    {
        if (puede('clientes.ver')) {
            return $reporte;
        }
        foreach (['reservas', 'movimientos_financieros'] as $clave) {
            foreach ($reporte[$clave] ?? [] as $i => $fila) {
                if (!is_array($fila)) { continue; }
                foreach (['cliente', 'cliente_email', 'cliente_telefono', 'cliente_nombre', 'cliente_apellido'] as $campo) {
                    if (array_key_exists($campo, $fila)) { $fila[$campo] = ''; }
                }
                if (array_key_exists('cliente', $fila)) { $fila['cliente'] = 'Cliente reservado'; }
                $reporte[$clave][$i] = $fila;
            }
        }
        return $reporte;
    }

    /**
     * Renderiza el panel administrativo de reportes (`GET /admin/reportes`).
     */
    public static function index(Router $router): void
    {
        iniciar_sesion_segura();
        exigir_permiso('reportes.ver');

        $resultado = self::ocultarDatosDeClientesSiCorresponde(self::obtenerReporteService()->consultarReporte($_GET));
        $httpCode = (int)($resultado['http_code'] ?? 200);
        http_response_code($httpCode);

        $router->render('admin/reportes', [
            'nombre' => $_SESSION['nombre'] ?? '',
            'apellido' => $_SESSION['apellido'] ?? '',
            'alertas' => $resultado['alertas'] ?? [],
            'reporte' => $resultado,
            'filtros' => $resultado['filtros'] ?? [],
            'catalogos' => $resultado['catalogos'] ?? ['profesionales' => [], 'servicios' => []],
            'resumenGlobal' => $resultado['resumen_global'] ?? [],
            'resumenProfesionales' => $resultado['resumen_profesionales'] ?? [],
            'resumenServicios' => $resultado['resumen_servicios'] ?? [],
            'reservas' => $resultado['reservas'] ?? [],
            'movimientosFinancieros' => $resultado['movimientos_financieros'] ?? [],
            'totalesReservas' => self::obtenerReporteService()->totalesBloque($resultado, 'reservas'),
            'totalesMovimientos' => self::obtenerReporteService()->totalesBloque($resultado, 'movimientos'),
        ]);
    }

    /**
     * Exporta el reporte filtrado (`GET /admin/reportes/exportar?formato=csv|xlsx&bloque=completo|reservas|movimientos`).
     *  - csv + completo (por omisión): el CSV de VARIAS secciones de siempre (no es una tabla plana; no se ofrece en Excel).
     *  - csv|xlsx + reservas|movimientos: tabla plana por Classes\Exportacion\Exportador, con fila «Total» y el mismo detalle que la pantalla.
     * Orden: autenticar → autorizar en la DESCARGA (403 + auditoría) → validar (422 + auditoría) → generar → AUDITAR → entregar.
     */
    public static function exportarCsv(Router $router): void
    {
        iniciar_sesion_segura();
        isAuth();
        $auditoria = self::auditoria();
        $actor = ExportacionAuditoriaService::actorDeSesion();
        $formato = Exportador::formatoDe($_GET['formato'] ?? null);
        $bloqueCrudo = $_GET['bloque'] ?? ($formato === 'xlsx' ? 'reservas' : 'completo');
        $bloque = is_string($bloqueCrudo) && isset(ReporteService::BLOQUES_EXPORTACION[$bloqueCrudo]) ? $bloqueCrudo : null;
        $origen = 'reportes.' . ($bloque ?? 'invalido');
        $formatoAud = $formato ?? 'invalido';
        $filtrosAud = static fn(array $r): array => array_intersect_key($r['filtros'] ?? [], array_flip(['fecha_inicio', 'fecha_fin', 'criterio_fecha', 'profesional_id', 'servicio_id']));

        if (!puede('reportes.ver')) {
            self::auditar($auditoria, $origen, $formatoAud, $actor, null, ['resultado' => 'denegada', 'motivo' => 'sin_permiso']);
            exigir_permiso('reportes.ver');   // responde 403 y termina
            return;
        }
        if ($formato === null || $bloque === null || ($formato === 'xlsx' && $bloque === 'completo')) {
            self::auditar($auditoria, $origen, $formatoAud, $actor, null, ['resultado' => 'fallida', 'motivo' => $formato === null ? 'formato_invalido' : ($bloque === null ? 'bloque_invalido' : 'xlsx_no_tabular')]);
            \Classes\ErrorResponse::render(422, ['mensaje' => $formato === null
                ? 'El formato de exportación no es válido: use csv o xlsx.'
                : ($bloque === null ? 'El bloque de exportación no es válido: use completo, reservas o movimientos.'
                    : 'El reporte completo tiene varias secciones y solo se ofrece en CSV; para Excel elija el listado de reservas o los movimientos financieros.')]);
            detener_ejecucion(422);
            return;
        }

        $servicio = self::obtenerReporteService();
        $resultado = self::ocultarDatosDeClientesSiCorresponde($servicio->consultarReporte($_GET));
        $httpCode = (int)($resultado['http_code'] ?? 200);

        if ($resultado['status'] === ReporteService::STATUS_INVALID || $resultado['status'] === ReporteService::STATUS_ERROR) {
            self::auditar($auditoria, $origen, $formato, $actor, $filtrosAud($resultado), ['resultado' => 'fallida', 'motivo' => $resultado['status'] === ReporteService::STATUS_INVALID ? 'filtros_invalidos' : 'error_datos']);
            http_response_code($httpCode);
            $router->render('admin/reportes', [
                'nombre' => $_SESSION['nombre'] ?? '',
                'apellido' => $_SESSION['apellido'] ?? '',
                'alertas' => $resultado['alertas'] ?? [],
                'reporte' => $resultado,
                'filtros' => $resultado['filtros'] ?? [],
                'catalogos' => $resultado['catalogos'] ?? ['profesionales' => [], 'servicios' => []],
                'resumenGlobal' => $resultado['resumen_global'] ?? [],
                'resumenProfesionales' => $resultado['resumen_profesionales'] ?? [],
                'resumenServicios' => $resultado['resumen_servicios'] ?? [],
                'reservas' => $resultado['reservas'] ?? [],
                'movimientosFinancieros' => $resultado['movimientos_financieros'] ?? [],
                'totalesReservas' => [], 'totalesMovimientos' => [],
            ]);
            return;
        }

        $fechaInicio = preg_replace('/[^0-9\-]/', '', (string)($resultado['filtros']['fecha_inicio'] ?? 'inicio'));
        $fechaFin = preg_replace('/[^0-9\-]/', '', (string)($resultado['filtros']['fecha_fin'] ?? 'fin'));
        $base = sprintf('reporte_%s_%s_a_%s', $bloque === 'completo' ? 'reservas' : $bloque, $fechaInicio, $fechaFin);
        if ($bloque === 'completo') {
            // Varias secciones: se conserva tal cual (documentado); solo se envuelve para auditarlo y entregarlo igual que el resto.
            $archivo = Exportador::desdeContenido($servicio->generarCsv($resultado), 'csv', $base, count($resultado['reservas'] ?? []),
                ['Cita ID', 'Fecha', 'Horario', 'Cliente', 'Email Cliente', 'Profesional', 'Estado Cita', 'Servicios (Snapshot Historico)', 'Duracion Cita (min)', 'Importe Total Cita (USD)', 'Importe Reservado Filtrado (USD)', 'Ingreso Pagado (USD)', 'Estado de Pago']);
        } else {
            $tabla = $servicio->tablaExportacion($resultado, $bloque);
            $archivo = Exportador::generar($formato, $base, $tabla['columnas'], $tabla['filas'], $tabla['totales'], ['hoja' => $tabla['hoja']]);
        }
        try {
            $auditoria->registrar($origen, $formato, $actor, $filtrosAud($resultado), $archivo + ['resultado' => 'exitosa']);
        } catch (\Throwable $e) {
            if (is_resource($archivo['flujo'])) { fclose($archivo['flujo']); }
            error_log('[AdminReporteController] exportación NO entregada: falló la auditoría (' . get_class($e) . ')');
            throw new \Classes\ServiceUnavailableException('No se pudo registrar la auditoría de la exportación; por seguridad el archivo no se entregó.', 0, $e);
        }
        self::$ultimosHeadersExportacion = Exportador::entregar($archivo);
        detener_ejecucion(200);
    }

    private static ?ExportacionAuditoriaService $auditoriaServicio = null;

    public static function setAuditoriaService(?ExportacionAuditoriaService $s): void { self::$auditoriaServicio = $s; }

    private static function auditoria(): ExportacionAuditoriaService
    {
        return self::$auditoriaServicio ?? new ExportacionAuditoriaService(new ExportacionAuditoriaRepository(ActiveRecord::getDB()));
    }

    /** Registra un intento denegado o fallido SIN alterar la respuesta 403/422 que ya corresponde. */
    private static function auditar(ExportacionAuditoriaService $a, string $origen, string $formato, array $actor, ?array $filtros, array $resultado): void
    {
        try { $a->registrar($origen, $formato, $actor, $filtros, $resultado); }
        catch (\Throwable $e) { error_log('[AdminReporteController] no se pudo auditar un intento de exportación (' . $resultado['resultado'] . '): ' . get_class($e)); }
    }
}

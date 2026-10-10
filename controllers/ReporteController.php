<?php

namespace Controllers;

use Model\ActiveRecord;
use MVC\Router;
use Repositories\ReporteRepository;
use Services\ReporteService;

/**
 * Controlador Administrativo para Reportes de Reservas y Ocupación (Fase 6B):
 * - `GET /admin/reportes`: Vista con filtros por rango de fechas (`fecha_inicio`, `fecha_fin`),
 *   profesional (`profesional_id`) y servicio (`servicio_id`), métricas de ocupación respecto
 *   a horas disponibles de turno y distinción explícita entre importe reservado e ingreso pagado.
 * - `GET /admin/reportes/exportar`: Exportación CSV segura de los datos filtrados.
 */
class ReporteController
{
    private static ?ReporteService $reporteService = null;

    /**
     * Registro de las últimas cabeceras HTTP preparadas en `exportarCsv()` para inspección en pruebas.
     * @var array<string, string>
     */
    private static array $ultimosHeadersExportacion = [];

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

    private static function obtenerReporteService(): ReporteService
    {
        if (self::$reporteService !== null) {
            return self::$reporteService;
        }

        $db = ActiveRecord::getDB();
        return new ReporteService(new ReporteRepository($db));
    }

    /**
     * Renderiza el panel administrativo de reportes (`GET /admin/reportes`).
     */
    public static function index(Router $router): void
    {
        iniciar_sesion_segura();
        isAdmin();

        $resultado = self::obtenerReporteService()->consultarReporte($_GET);
        $httpCode = (int)($resultado['http_code'] ?? 200);
        http_response_code($httpCode);

        $router->render('reportes/index', [
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
        ]);
    }

    /**
     * Exporta en formato CSV seguro los datos del reporte filtrado (`GET /admin/reportes/exportar`).
     */
    public static function exportarCsv(Router $router): void
    {
        iniciar_sesion_segura();
        isAdmin();

        $servicio = self::obtenerReporteService();
        $resultado = $servicio->consultarReporte($_GET);
        $httpCode = (int)($resultado['http_code'] ?? 200);

        if (
            $resultado['status'] === ReporteService::STATUS_INVALID
            || $resultado['status'] === ReporteService::STATUS_ERROR
        ) {
            http_response_code($httpCode);
            $router->render('reportes/index', [
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
            ]);
            return;
        }

        $fechaInicio = preg_replace('/[^0-9\-]/', '', (string)($resultado['filtros']['fecha_inicio'] ?? 'inicio'));
        $fechaFin = preg_replace('/[^0-9\-]/', '', (string)($resultado['filtros']['fecha_fin'] ?? 'fin'));
        $nombreArchivo = sprintf('reporte_reservas_%s_a_%s.csv', $fechaInicio, $fechaFin);

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $nombreArchivo . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ];
        self::$ultimosHeadersExportacion = $headers;

        http_response_code(200);
        if (!headers_sent()) {
            foreach ($headers as $nombre => $valor) {
                header($nombre . ': ' . $valor);
            }
        }

        echo $servicio->generarCsv($resultado);
        detener_ejecucion(200);
    }
}

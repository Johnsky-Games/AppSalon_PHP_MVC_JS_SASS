<?php

namespace Controllers;

use Model\ActiveRecord;
use MVC\Router;
use Repositories\ReporteRepository;
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
        ]);
    }

    /**
     * Exporta en formato CSV seguro los datos del reporte filtrado (`GET /admin/reportes/exportar`).
     */
    public static function exportarCsv(Router $router): void
    {
        iniciar_sesion_segura();
        exigir_permiso('reportes.ver');

        $servicio = self::obtenerReporteService();
        $resultado = self::ocultarDatosDeClientesSiCorresponde($servicio->consultarReporte($_GET));
        $httpCode = (int)($resultado['http_code'] ?? 200);

        if (
            $resultado['status'] === ReporteService::STATUS_INVALID
            || $resultado['status'] === ReporteService::STATUS_ERROR
        ) {
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

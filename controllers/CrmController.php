<?php
namespace Controllers;

use Classes\ErrorResponse;
use Classes\ServiceUnavailableException;
use Model\ActiveRecord;
use MVC\Router;
use Repositories\CrmRepository;
use Repositories\PersistenceException;
use Services\CrmService;

/**
 * CRM de clientes (solo lectura, todo GET):
 *  - GET /admin/clientes          listado, indicadores, segmentos y gráficos.
 *  - GET /admin/clientes/ficha    ficha 360° de un cliente (?id=N).
 *  - GET /admin/clientes/exportar CSV (clientes.exportar).
 * La autorización se resuelve en servidor en cada petición (puede()/exigir_permiso()); nunca se usa $_SESSION['admin'].
 * Un fallo técnico de datos se traduce en ServiceUnavailableException (503), jamás en un mensaje de "sin permiso".
 */
final class CrmController
{
    private static ?CrmService $service = null;
    /** @var array<string,string> */
    private static array $ultimosHeadersExportacion = [];

    public static function setCrmService(?CrmService $service): void { self::$service = $service; }

    /** @return array<string,string> */
    public static function getUltimosHeadersExportacion(): array { return self::$ultimosHeadersExportacion; }

    private static function service(): CrmService
    {
        return self::$service ?? new CrmService(new CrmRepository(ActiveRecord::getDB()));
    }

    /** Capacidades del usuario autenticado, comprobadas en servidor. @return array{ver:bool,analitica:bool,propios:bool,exportar:bool} */
    private static function acceso(): array
    {
        return [
            'ver' => puede('clientes.ver'),
            'analitica' => puede('clientes.analitica'),
            'propios' => puede('clientes.ver_propios'),
            'exportar' => puede('clientes.exportar'),
        ];
    }

    /** Autentica y exige al menos una capacidad de clientes. Corta con 401/302 (anónimo) o 403 (sin permiso). */
    private static function exigirAcceso(): array
    {
        iniciar_sesion_segura();
        isAuth();
        $acceso = self::acceso();
        if (CrmService::modo($acceso) === null) {
            exigir_permiso('clientes.ver');
            http_response_code(403);
            detener_ejecucion(403);
        }
        return $acceso;
    }

    private static function sinCache(): void
    {
        if (!headers_sent()) { header('Cache-Control: no-store'); }
    }

    /** Convierte fallos técnicos de persistencia en "servicio no disponible" (503). */
    private static function noDisponible(\Throwable $e): ServiceUnavailableException
    {
        error_log('[CrmController] datos no disponibles: ' . get_class($e) . ' code=' . (int)$e->getCode());
        return new ServiceUnavailableException('CRM de clientes no disponible.', 0, $e);
    }

    private static function noEncontrado(): void
    {
        ErrorResponse::render(404, ['mensaje' => 'No encontramos este cliente o no tienes acceso a su ficha.']);
        detener_ejecucion(404);
    }

    public static function index(Router $router): void
    {
        $acceso = self::exigirAcceso();
        self::sinCache();
        try {
            $servicio = self::service();
            $catalogos = $servicio->catalogos();
            $validacion = $servicio->normalizarFiltros($_GET, $acceso, $catalogos);
            $panel = $servicio->panel($validacion['filtros'], $acceso, CrmService::modo($acceso) === 'propios' ? profesional_actual_id() : null);
        } catch (PersistenceException | \mysqli_sql_exception $e) {
            throw self::noDisponible($e);
        }
        if ($validacion['errores']) { http_response_code(422); }
        $router->render('crm/index', [
            'panel' => $panel, 'acceso' => $acceso, 'catalogos' => $catalogos, 'errores' => $validacion['errores'],
            'alertas' => $validacion['errores'] ? ['error' => $validacion['errores']] : [],
        ]);
    }

    public static function ficha(Router $router): void
    {
        $acceso = self::exigirAcceso();
        self::sinCache();
        // Un profesional solo ve clientes que atendió; finanzas (solo analítica) no accede a fichas individuales.
        if (CrmService::modo($acceso) === 'analitica') { exigir_permiso('clientes.ver'); }
        $id = $_GET['id'] ?? null;
        if (!is_string($id) || !preg_match('/^[1-9]\d{0,9}$/', $id)) { self::noEncontrado(); return; }
        try {
            $ficha = self::service()->ficha((int)$id, $acceso, CrmService::modo($acceso) === 'propios' ? profesional_actual_id() : null);
        } catch (PersistenceException | \mysqli_sql_exception $e) {
            throw self::noDisponible($e);
        }
        if ($ficha === null) { self::noEncontrado(); return; }
        $router->render('crm/ficha', ['ficha' => $ficha, 'acceso' => $acceso, 'alertas' => []]);
    }

    public static function exportar(Router $router): void
    {
        iniciar_sesion_segura();
        exigir_permiso('clientes.exportar');
        $acceso = self::acceso();
        if (empty($acceso['ver'])) { exigir_permiso('clientes.ver'); }
        $tipo = $_GET['tipo'] ?? 'clientes';
        try {
            $servicio = self::service();
            $validacion = $servicio->normalizarFiltros($_GET, $acceso, $servicio->catalogos());
            if (!is_string($tipo) || !in_array($tipo, ['clientes', 'comercial'], true)) { $validacion['errores'][] = 'El tipo de exportación no es válido.'; }
            if ($validacion['errores']) {
                ErrorResponse::render(422, ['mensaje' => 'No se puede exportar con estos filtros: ' . implode(' ', $validacion['errores'])]);
                detener_ejecucion(422);
            }
            $resultado = $servicio->exportar($validacion['filtros'], $acceso, $tipo);
        } catch (PersistenceException | \mysqli_sql_exception $e) {
            throw self::noDisponible($e);
        }
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $resultado['archivo']) . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ];
        self::$ultimosHeadersExportacion = $headers;
        http_response_code(200);
        if (!headers_sent()) {
            foreach ($headers as $nombre => $valor) { header($nombre . ': ' . $valor); }
        }
        echo $resultado['contenido'];
        detener_ejecucion(200);
    }
}

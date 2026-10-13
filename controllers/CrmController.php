<?php
namespace Controllers;

use Classes\ErrorResponse;
use Classes\ServiceUnavailableException;
use Model\ActiveRecord;
use MVC\Router;
use Repositories\CrmAuditoriaRepository;
use Repositories\CrmRepository;
use Repositories\PersistenceException;
use Services\AuthorizationService;
use Services\CrmAuditoriaService;
use Services\CrmService;

/**
 * CRM de clientes (solo lectura, todo GET):
 *  - GET /admin/clientes          listado, indicadores, segmentos y gráficos.
 *  - GET /admin/clientes/ficha    ficha 360° de un cliente (?id=N).
 *  - GET /admin/clientes/exportar CSV (clientes.exportar). Toda exportación (exitosa, denegada o fallida) deja una fila en
 *    la auditoría append-only; si no se puede registrar el éxito, el archivo NO se entrega (503).
 *  - GET /admin/clientes/auditoria consulta paginada y de solo lectura de esa auditoría (clientes.exportar).
 *  - GET|POST /admin/clientes/nuevo alta de cliente por el equipo con aviso de posibles homónimos (ver AltaClienteController).
 * La autorización se resuelve en servidor en cada petición (puede()/exigir_permiso()); nunca se usa $_SESSION['admin'].
 * Un fallo técnico de datos se traduce en ServiceUnavailableException (503), jamás en un mensaje de "sin permiso".
 */
final class CrmController
{
    private static ?CrmService $service = null;
    private static ?CrmAuditoriaService $auditoria = null;
    /** @var array<string,string> */
    private static array $ultimosHeadersExportacion = [];

    public static function setCrmService(?CrmService $service): void { self::$service = $service; }
    public static function setAuditoriaService(?CrmAuditoriaService $service): void { self::$auditoria = $service; }

    private static function auditoriaService(): CrmAuditoriaService
    {
        return self::$auditoria ?? new CrmAuditoriaService(new CrmAuditoriaRepository(ActiveRecord::getDB()));
    }

    /** Quién actúa, para la auditoría: id de sesión y rol efectivo del servidor (nunca el que declare el navegador). @return array{id:?int,rol:?string} */
    private static function actorAuditoria(): array
    {
        $id = (int)($_SESSION['id'] ?? 0);
        $rol = null;
        try { $rol = (new AuthorizationService())->contexto($id)['rol'] ?? null; } catch (\Throwable) { /* el rol queda sin dato; el id basta */ }
        return ['id' => $id > 0 ? $id : null, 'rol' => $rol];
    }

    /**
     * Registra un intento denegado o fallido SIN alterar la respuesta que ya corresponde (403/422/503): un fallo al
     * auditar se deja en el registro de errores, no abre ni cierra ninguna puerta.
     */
    private static function auditarIntento(string $tipo, string $resultado, string $motivo, ?array $filtros): void
    {
        try { self::auditoriaService()->registrar($tipo, $resultado, $motivo, self::actorAuditoria(), $filtros); }
        catch (\Throwable $e) { error_log('[CrmController] no se pudo auditar un intento de exportación (' . $resultado . '): ' . get_class($e)); }
    }

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
        // Alta de clientes por el equipo: perfiles.gestionar + clientes.ver (recepción, gerencia, propietario).
        $acceso['alta'] = !empty($acceso['ver']) && puede('perfiles.gestionar');
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
        $alertas = ($_GET['alta'] ?? '') === 'ok' ? ['exito' => ['Cliente creado. Aún no tiene acceso: debe confirmar su correo y definir su contraseña.']] : [];
        $router->render('crm/ficha', ['ficha' => $ficha, 'acceso' => $acceso, 'alertas' => $alertas]);
    }

    public static function exportar(Router $router): void
    {
        iniciar_sesion_segura();
        isAuth(); // anónimo: 302/401 antes de cualquier otra cosa (no hay persona que auditar)
        $tipo = $_GET['tipo'] ?? 'clientes';
        $tipoAud = is_string($tipo) && in_array($tipo, ['clientes', 'comercial'], true) ? $tipo : 'invalido';
        if (!puede('clientes.exportar')) {
            self::auditarIntento($tipoAud, 'denegada', 'sin_permiso', null);
            exigir_permiso('clientes.exportar'); // responde 403 y termina
            return;
        }
        $acceso = self::acceso();
        if (empty($acceso['ver'])) {
            self::auditarIntento($tipoAud, 'denegada', 'sin_permiso_ver', null);
            exigir_permiso('clientes.ver');
            return;
        }
        $filtros = null;
        try {
            $servicio = self::service();
            $validacion = $servicio->normalizarFiltros($_GET, $acceso, $servicio->catalogos());
            $filtros = $validacion['filtros'];
            if ($tipoAud === 'invalido') { $validacion['errores'][] = 'El tipo de exportación no es válido.'; }
            if ($validacion['errores']) {
                self::auditarIntento($tipoAud, 'fallida', 'filtros_invalidos', $filtros);
                ErrorResponse::render(422, ['mensaje' => 'No se puede exportar con estos filtros: ' . implode(' ', $validacion['errores'])]);
                detener_ejecucion(422);
            }
            $resultado = $servicio->exportar($filtros, $acceso, $tipoAud);
        } catch (PersistenceException | \mysqli_sql_exception $e) {
            self::auditarIntento($tipoAud, 'fallida', 'error_datos', $filtros);
            throw self::noDisponible($e);
        }
        // Registro y entrega son atómicos: primero se escribe la auditoría; si falla, el archivo no sale.
        try {
            self::auditoriaService()->registrar($tipoAud, 'exitosa', null, self::actorAuditoria(), $filtros, [
                'columnas' => $resultado['columnas'], 'filas' => $resultado['filas'], 'truncado' => $resultado['truncado'], 'contenido' => $resultado['contenido'],
            ]);
        } catch (\Throwable $e) {
            error_log('[CrmController] exportación NO entregada: falló la auditoría (' . get_class($e) . ' code=' . (int)$e->getCode() . ')');
            throw new ServiceUnavailableException('No se pudo registrar la auditoría de la exportación; por seguridad el archivo no se entregó.', 0, $e);
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

    /** Consulta paginada y de SOLO LECTURA de la auditoría de exportaciones. Requiere clientes.exportar. */
    public static function auditoria(Router $router): void
    {
        iniciar_sesion_segura();
        exigir_permiso('clientes.exportar');
        self::sinCache();
        try {
            $servicio = self::auditoriaService();
            $validacion = $servicio->normalizarConsulta($_GET);
            $resultado = $servicio->consultar($validacion['filtros']);
        } catch (PersistenceException | \mysqli_sql_exception $e) {
            throw self::noDisponible($e);
        }
        if ($validacion['errores']) { http_response_code(422); }
        $router->render('crm/auditoria', [
            'filtros' => $validacion['filtros'], 'pag' => $resultado, 'errores' => $validacion['errores'],
            'alertas' => $validacion['errores'] ? ['error' => $validacion['errores']] : [],
        ]);
    }
}

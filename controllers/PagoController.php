<?php

namespace Controllers;

use Classes\MockPaymentGateway;
use Model\ActiveRecord;
use MVC\Router;
use Repositories\PagoRepository;
use Services\PagoService;

/**
 * Controlador de Pagos (Fase 6C — Base para Pagos):
 * - `POST /api/pagos/iniciar`: Autenticado (`isAuth`) y protegido con CSRF (`validar_csrf`).
 *   Calcula el monto exclusivamente en el servidor e inicializa el intento con el gateway configurado.
 * - `POST /api/pagos/webhook`: Endpoint asíncrono server-to-server para notificaciones del proveedor,
 *   protegido mediante validación de firma HMAC-SHA256 (`X-Mock-Signature`) e idempotencia transaccional.
 * - `GET /pago/resultado`: Vista informativa del estado del pago que consulta el estado real en MySQL,
 *   ignorando cualquier parámetro manipulador en la query string del navegador.
 */
class PagoController
{
    private const ERROR_CONFIGURACION = 'El servicio de pagos no está disponible temporalmente. Intenta nuevamente más tarde.';

    private static function registrarErrorConfiguracion(\Throwable $exception): void
    {
        error_log(sprintf('[AppSalon][Pagos] %s code=%d at %s:%d', get_class($exception), (int)$exception->getCode(), basename($exception->getFile()), $exception->getLine()));
    }

    private static ?PagoService $pagoService = null;

    /**
     * Cuerpo JSON inyectable para pruebas unitarias/integrales cuando no se usa `php://input`.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $rawJsonOverride = null;

    public static function setPagoService(?PagoService $service): void
    {
        self::$pagoService = $service;
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    public static function setRawJsonOverride(?array $payload): void
    {
        self::$rawJsonOverride = $payload;
    }

    /**
     * Resuelve el adaptador del gateway inspeccionando el entorno de ejecución.
     * En producción, prohíbe terminantemente el uso de MockPaymentGateway y secretos por defecto.
     *
     * @throws \RuntimeException
     */
    public static function resolverGateway(): \Interfaces\PaymentGatewayInterface
    {
        $appEnv = strtolower(trim((string)($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'development')));
        $provider = strtolower(trim((string)($_ENV['PAYMENT_GATEWAY_PROVIDER'] ?? getenv('PAYMENT_GATEWAY_PROVIDER') ?: 'mock')));
        $webhookSecret = trim((string)($_ENV['PAYMENT_WEBHOOK_SECRET'] ?? getenv('PAYMENT_WEBHOOK_SECRET') ?: ''));

        $secretosPorDefectoInseguros = [
            '',
            MockPaymentGateway::DEFAULT_WEBHOOK_SECRET,
            'mock_secret_cambiar_en_produccion',
            'mock_secret',
        ];

        if ($appEnv === 'production') {
            if ($provider === 'mock' || $provider === '') {
                throw new \RuntimeException('Configuración de pagos inválida: El simulador MockPaymentGateway está estrictamente prohibido en entorno de producción.');
            }

            if (in_array($webhookSecret, $secretosPorDefectoInseguros, true)) {
                throw new \RuntimeException('Configuración de pagos inválida: El secreto de webhook en producción no puede ser nulo ni utilizar el valor por defecto.');
            }

            throw new \RuntimeException("Configuración de pagos inválida: El proveedor '{$provider}' no se encuentra implementado para producción.");
        }

        return new MockPaymentGateway($webhookSecret !== '' ? $webhookSecret : null);
    }

    public static function obtenerPagoService(): PagoService
    {
        if (self::$pagoService !== null) {
            return self::$pagoService;
        }

        $db = ActiveRecord::getDB();
        $gateway = self::resolverGateway();

        return new PagoService(
            new PagoRepository($db),
            $gateway
        );
    }

    /**
     * Obtiene los datos de entrada combinando `$_POST` y cuerpo JSON si existe.
     *
     * @return array<string, mixed>
     */
    private static function leerDatosEntrada(): array
    {
        if (self::$rawJsonOverride !== null) {
            return array_merge($_POST, self::$rawJsonOverride);
        }

        $datos = is_array($_POST) ? $_POST : [];
        $rawInput = file_get_contents('php://input');
        if (is_string($rawInput) && trim($rawInput) !== '') {
            $decoded = json_decode($rawInput, true);
            if (is_array($decoded)) {
                $datos = array_merge($datos, $decoded);
            }
        }

        return $datos;
    }

    /**
     * Extrae cabeceras HTTP relevantes desde `$_SERVER`.
     *
     * @return array<string, string>
     */
    private static function extraerCabeceras(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $val) {
            if (!is_scalar($val)) {
                continue;
            }
            $strVal = (string)$val;
            if (str_starts_with($key, 'HTTP_')) {
                $headers[$key] = $strVal;
                $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$headerName] = $strVal;
                $headers[strtolower($headerName)] = $strVal;
            }
        }
        return $headers;
    }

    /**
     * Endpoint autenticado `POST /api/pagos/iniciar`.
     */
    public static function iniciar(): void
    {
        iniciar_sesion_segura();
        header('Content-Type: application/json; charset=utf-8');

        $idSesion = $_SESSION['id'] ?? null;
        $esIdValido = is_int($idSesion)
            ? $idSesion >= 1
            : (is_string($idSesion) && ctype_digit(trim($idSesion)) && (int)trim($idSesion) >= 1);

        if (!isset($_SESSION['login']) || $_SESSION['login'] !== true || !$esIdValido) {
            http_response_code(401);
            echo json_encode([
                'resultado' => false,
                'status' => PagoService::STATUS_UNAUTHORIZED,
                'error' => 'No autenticado',
            ]);
            detener_ejecucion(401);
            return;
        }

        // Revocación, cuenta activa y versión de sesión comprobadas antes de cualquier pago.
        isAuth();

        $datos = self::leerDatosEntrada();
        // Si el token CSRF vino en el cuerpo JSON, sincronizarlo en $_POST para validar_csrf()
        if (!isset($_POST['csrf_token']) && isset($datos['csrf_token']) && is_string($datos['csrf_token'])) {
            $_POST['csrf_token'] = $datos['csrf_token'];
        }

        if (!validar_csrf()) {
            http_response_code(403);
            echo json_encode([
                'resultado' => false,
                'status' => PagoService::STATUS_FORBIDDEN,
                'error' => 'Token CSRF inválido o ausente',
            ]);
            detener_ejecucion(403);
            return;
        }

        if (!isset($datos['idempotency_key']) && isset($_SERVER['HTTP_X_IDEMPOTENCY_KEY'])) {
            $datos['idempotency_key'] = (string)$_SERVER['HTTP_X_IDEMPOTENCY_KEY'];
        }

        $usuarioId = (int)$_SESSION['id'];
        $esAdmin = puede('finanzas.gestionar');

        try {
            $service = self::obtenerPagoService();
        } catch (\RuntimeException $e) {
            self::registrarErrorConfiguracion($e);
            http_response_code(500);
            echo json_encode([
                'resultado' => false,
                'status' => PagoService::STATUS_ERROR,
                'codigo_error' => 'PAYMENT_CONFIGURATION_ERROR',
                'error' => self::ERROR_CONFIGURACION,
                'errores' => [self::ERROR_CONFIGURACION],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            detener_ejecucion(500);
            return;
        }

        $resultado = $service->iniciarPago($usuarioId, $datos, $esAdmin);
        $httpCode = (int)($resultado['http_code'] ?? 200);
        unset($resultado['http_code']);

        $respuesta = array_merge([
            'resultado' => $httpCode === 200 && ($resultado['status'] ?? '') === PagoService::STATUS_OK,
        ], $resultado);

        http_response_code($httpCode);
        echo json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($httpCode !== 200) {
            detener_ejecucion($httpCode);
        }
    }

    /**
     * Endpoint asíncrono `POST /api/pagos/webhook`.
     * Valida firma criptográfica del proveedor y procesa la confirmación con idempotencia transaccional.
     */
    public static function webhook(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $payload = self::leerDatosEntrada();
        $headers = self::extraerCabeceras();

        try {
            $service = self::obtenerPagoService();
        } catch (\RuntimeException $e) {
            self::registrarErrorConfiguracion($e);
            http_response_code(500);
            echo json_encode([
                'resultado' => false,
                'status' => PagoService::STATUS_ERROR,
                'codigo_error' => 'PAYMENT_CONFIGURATION_ERROR',
                'error' => self::ERROR_CONFIGURACION,
                'errores' => [self::ERROR_CONFIGURACION],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            detener_ejecucion(500);
            return;
        }

        $resultado = $service->procesarWebhook($payload, $headers);
        $httpCode = (int)($resultado['http_code'] ?? 200);
        unset($resultado['http_code']);

        $respuesta = array_merge([
            'resultado' => $httpCode === 200 && ($resultado['status'] ?? '') === PagoService::STATUS_OK,
        ], $resultado);

        http_response_code($httpCode);
        echo json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($httpCode !== 200) {
            detener_ejecucion($httpCode);
        }
    }

    /**
     * Vista informativa `GET /pago/resultado`.
     * Consulta exclusivamente el estado real almacenado en base de datos, ignorando
     * cualquier parámetro de estado enviado en el query string del navegador.
     */
    public static function resultado(Router $router): void
    {
        iniciar_sesion_segura();
        isAuth();

        $usuarioId = (int)($_SESSION['id'] ?? 0);
        $esAdmin = puede('finanzas.gestionar');

        $quiereJson = es_peticion_json() || (($_GET['format'] ?? '') === 'json');

        try {
            $service = self::obtenerPagoService();
        } catch (\RuntimeException $e) {
            self::registrarErrorConfiguracion($e);
            http_response_code(500);
            if ($quiereJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'resultado' => false,
                    'status' => PagoService::STATUS_ERROR,
                    'codigo_error' => 'PAYMENT_CONFIGURATION_ERROR',
                    'error' => self::ERROR_CONFIGURACION,
                    'errores' => [self::ERROR_CONFIGURACION],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                detener_ejecucion(500);
                return;
            }

            $router->render('pagos/resultado', [
                'nombre' => $_SESSION['nombre'] ?? '',
                'apellido' => $_SESSION['apellido'] ?? '',
                'alertas' => ['error' => [self::ERROR_CONFIGURACION]],
                'resultadoPago' => ['status' => PagoService::STATUS_ERROR, 'errores' => [self::ERROR_CONFIGURACION]],
                'pago' => null,
                'cita' => null,
                'advertenciaSeguridad' => null,
            ]);
            detener_ejecucion(500);
            return;
        }

        $resultado = $service->consultarResultadoPago($_GET, $usuarioId, $esAdmin);
        $httpCode = (int)($resultado['http_code'] ?? 200);

        if ($quiereJson) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($httpCode);
            $copia = $resultado;
            unset($copia['http_code']);
            echo json_encode($copia, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($httpCode !== 200) {
                detener_ejecucion($httpCode);
            }
            return;
        }

        http_response_code($httpCode);
        $alertas = [];
        if (!empty($resultado['errores'])) {
            $alertas['error'] = $resultado['errores'];
        }

        $router->render('pagos/resultado', [
            'nombre' => $_SESSION['nombre'] ?? '',
            'apellido' => $_SESSION['apellido'] ?? '',
            'alertas' => $alertas,
            'resultadoPago' => $resultado,
            'pago' => $resultado['pago'] ?? null,
            'cita' => $resultado['cita'] ?? null,
            'advertenciaSeguridad' => $resultado['advertencia_seguridad'] ?? null,
        ]);
    }

    /**
     * Endpoint administrativo `POST /api/pagos/reembolsar`.
     * Requiere rol de Administrador (`isAdmin()`) y validación CSRF (`validar_csrf()`).
     */
    public static function reembolsar(): void
    {
        iniciar_sesion_segura();
        header('Content-Type: application/json; charset=utf-8');
        exigir_permiso('finanzas.gestionar');

        $datos = self::leerDatosEntrada();
        if (!isset($_POST['csrf_token']) && isset($datos['csrf_token']) && is_string($datos['csrf_token'])) {
            $_POST['csrf_token'] = $datos['csrf_token'];
        }

        if (!validar_csrf()) {
            http_response_code(403);
            echo json_encode([
                'resultado' => false,
                'status' => PagoService::STATUS_FORBIDDEN,
                'error' => 'Token CSRF inválido o ausente',
            ]);
            detener_ejecucion(403);
            return;
        }

        $referenciaRaw = $datos['referencia'] ?? $datos['referencia_pago'] ?? '';
        $referencia = is_scalar($referenciaRaw) ? trim((string)$referenciaRaw) : '';

        $monto = null;
        if (isset($datos['monto']) && is_scalar($datos['monto']) && is_numeric((string)$datos['monto'])) {
            $monto = (float)$datos['monto'];
        }

        try {
            $service = self::obtenerPagoService();
        } catch (\RuntimeException $e) {
            self::registrarErrorConfiguracion($e);
            http_response_code(500);
            echo json_encode([
                'resultado' => false,
                'status' => PagoService::STATUS_ERROR,
                'codigo_error' => 'PAYMENT_CONFIGURATION_ERROR',
                'error' => self::ERROR_CONFIGURACION,
                'errores' => [self::ERROR_CONFIGURACION],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            detener_ejecucion(500);
            return;
        }

        $resultado = $service->reembolsarPago($referencia, $monto);
        $httpCode = (int)($resultado['http_code'] ?? 200);
        unset($resultado['http_code']);

        $respuesta = array_merge([
            'resultado' => $httpCode === 200 && ($resultado['status'] ?? '') === PagoService::STATUS_OK,
        ], $resultado);

        http_response_code($httpCode);
        echo json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($httpCode !== 200) {
            detener_ejecucion($httpCode);
        }
    }
}

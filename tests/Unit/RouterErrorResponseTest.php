<?php

namespace Tests\Unit;

use Classes\ErrorResponse;
use Classes\ServiceUnavailableException;
use MVC\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RouterErrorResponseTest extends TestCase
{
    private array $server;
    private array $session;
    private array $post;
    private array $cookies;
    private string $previousErrorLog;
    private string $errorLog;

    protected function setUp(): void
    {
        iniciar_sesion_segura();
        $this->server = $_SERVER;
        $this->session = $_SESSION;
        $this->post = $_POST;
        $this->cookies = $_COOKIE;
        $this->previousErrorLog = (string)ini_get('error_log');
        $this->errorLog = tempnam(sys_get_temp_dir(), 'appsalon-error-test-');
        ini_set('error_log', $this->errorLog);
        $_SESSION = [];
        $_POST = [];
        $_COOKIE = [];
        $_SERVER['REQUEST_URI'] = '/no-existe?token=un-token-privado';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        unset($_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_X_CSRF_TOKEN']);
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_SESSION = $this->session;
        $_POST = $this->post;
        $_COOKIE = $this->cookies;
        ini_set('error_log', $this->previousErrorLog);
        unlink($this->errorLog);
        http_response_code(200);
    }

    private function dispatch(Router $router): string
    {
        $level = ob_get_level();
        ob_start();
        try {
            $router->comprobarRutas();
        } catch (\AppTerminationException $exception) {
            // Los controladores conservan el contrato de terminación controlada.
        } finally {
            $closingLevel = ob_get_level();
            $body = '';
            while (ob_get_level() > $level) {
                $body = ob_get_clean() . $body;
            }
        }
        $this->assertSame($level + 1, $closingLevel, 'El router debe cerrar sus propios buffers.');
        return $body;
    }

    public function testPaginaDesconocidaTiene404SalidaAccesibleYNoReflejaElEnlacePrivado(): void
    {
        $_SESSION['ui_empresa'] = ['nombre_comercial' => 'Salón <Armonía>', 'contacto' => 'Atención personal'];
        $html = $this->dispatch(new Router());
        $this->assertSame(404, http_response_code());
        $this->assertStringContainsString('<main id="contenido-principal"', $html);
        $this->assertStringContainsString('href="/"', $html);
        $this->assertStringContainsString('Salón &lt;Armonía&gt;', $html);
        $this->assertStringNotContainsString('un-token-privado', $html);
        $this->assertStringNotContainsString('ui-auth-hero', $html);
        $this->assertStringNotContainsString('/workspace/', $html);
    }

    #[DataProvider('unsupportedMethods')]
    public function testMetodosNoAdmitidosNuncaEjecutanUnaMutacion(string $method): void
    {
        $executions = 0;
        $router = new Router();
        $router->get('/conocida', static function () use (&$executions) { ++$executions; });
        $router->post('/conocida', static function () use (&$executions) { ++$executions; });
        $_SERVER['REQUEST_URI'] = '/conocida';
        $_SERVER['REQUEST_METHOD'] = $method;
        $this->dispatch($router);
        $this->assertSame(405, http_response_code());
        $this->assertSame(0, $executions);
    }

    public static function unsupportedMethods(): array
    {
        return [['PUT'], ['DELETE'], ['PATCH'], ['OPTIONS']];
    }

    public function testHeadUsaLaRutaGetYNoDevuelveCuerpo(): void
    {
        $getExecutions = 0;
        $postExecutions = 0;
        $router = new Router();
        $router->get('/conocida', static function () use (&$getExecutions) { ++$getExecutions; echo 'Contenido GET'; });
        $router->post('/conocida', static function () use (&$postExecutions) { ++$postExecutions; });
        $_SERVER['REQUEST_URI'] = '/conocida';
        $_SERVER['REQUEST_METHOD'] = 'HEAD';
        $this->assertSame('', $this->dispatch($router));
        $this->assertSame(1, $getExecutions);
        $this->assertSame(0, $postExecutions);
        $_SERVER['REQUEST_URI'] = '/no-existe';
        $this->assertSame('', $this->dispatch($router));
        $this->assertSame(404, http_response_code());
    }

    #[DataProvider('jsonNegotiation')]
    public function testNegociacionDeJsonEsExplicita(string $uri, string $accept, string $contentType, bool $expected): void
    {
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['HTTP_ACCEPT'] = $accept;
        $_SERVER['CONTENT_TYPE'] = $contentType;
        $this->assertSame($expected, es_peticion_json());
        $body = $this->dispatch(new Router());
        $this->assertSame(404, http_response_code());
        if ($expected) {
            $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            $this->assertFalse($json['resultado']);
            $this->assertIsString($json['error']);
        } else {
            $this->assertStringContainsString('<!DOCTYPE html>', $body);
        }
    }

    public static function jsonNegotiation(): array
    {
        return [
            ['/api/no-existe', 'text/html', '', true],
            ['/api', '*/*', '', true],
            ['/apiary', '*/*', '', false],
            ['/no-existe', 'application/json', '', true],
            ['/no-existe', 'application/problem+json', '', true],
            ['/no-existe', 'application/json;q=0, text/html', '', false],
            ['/no-existe', 'text/html;q=1, application/json;q=0.5', '', false],
            ['/no-existe', 'text/html', 'application/json', false],
        ];
    }

    #[DataProvider('failureFormats')]
    public function testExcepcionDescartaHtmlParcialYNoFiltraDetallesAlClienteNiAlLog(string $uri, string $accept): void
    {
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['HTTP_ACCEPT'] = $accept;
        $router = new Router();
        $router->get($uri, static function () {
            echo 'HTML PARCIAL PRIVADO';
            ob_start();
            echo 'BUFFER INTERNO PRIVADO';
            throw new \RuntimeException('SQL password=SECRETO_DE_EXCEPCION token=TOKEN_PRIVADO');
        });
        $body = $this->dispatch($router);
        $this->assertSame(500, http_response_code());
        $this->assertStringNotContainsString('PARCIAL PRIVADO', $body);
        $this->assertStringNotContainsString('BUFFER INTERNO', $body);
        $this->assertStringNotContainsString('SECRETO_DE_EXCEPCION', $body);
        $this->assertStringNotContainsString('TOKEN_PRIVADO', $body);
        $this->assertStringNotContainsString('Stack trace', $body);
        $log = (string)file_get_contents($this->errorLog);
        $this->assertStringContainsString('RuntimeException', $log);
        $this->assertStringNotContainsString('SECRETO_DE_EXCEPCION', $log);
        $this->assertStringNotContainsString('TOKEN_PRIVADO', $log);
        if (es_peticion_json()) {
            $this->assertFalse(json_decode($body, true, 512, JSON_THROW_ON_ERROR)['resultado']);
        } else {
            $this->assertSame(1, substr_count($body, '<!DOCTYPE html>'));
            $this->assertStringContainsString('Referencia de ayuda:', $body);
        }
    }

    public static function failureFormats(): array
    {
        return [['/fallo', 'text/html'], ['/api/fallo', '*/*']];
    }

    public function testErrorControladoVacioMuestraUnaPaginaYConservaCodigo404(): void
    {
        $router = new Router();
        $router->get('/perfil-inexistente', static function () { http_response_code(404); detener_ejecucion(404); });
        $_SERVER['REQUEST_URI'] = '/perfil-inexistente';
        $body = $this->dispatch($router);
        $this->assertSame(404, http_response_code());
        $this->assertSame(1, substr_count($body, '<!DOCTYPE html>'));
    }

    public function testRespuestaJsonControladaConservaSuContratoSinAgregarHtml(): void
    {
        $router = new Router();
        $router->post('/api/prueba', static function () {
            http_response_code(422);
            echo '{"resultado":false,"status":"invalid","errores":["Revisa la fecha"]}';
            detener_ejecucion(422);
        });
        $_SERVER['REQUEST_URI'] = '/api/prueba';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $body = $this->dispatch($router);
        $this->assertSame(422, http_response_code());
        $this->assertSame(['resultado' => false, 'status' => 'invalid', 'errores' => ['Revisa la fecha']], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testFalloDeDependenciaIndispensableUsa503(): void
    {
        $router = new Router();
        $router->get('/no-disponible', static function () { throw new ServiceUnavailableException('mysql://credenciales-privadas'); });
        $_SERVER['REQUEST_URI'] = '/no-disponible';
        $body = $this->dispatch($router);
        $this->assertSame(503, http_response_code());
        $this->assertStringContainsString('Volvemos en un momento', $body);
        $this->assertStringNotContainsString('credenciales-privadas', $body);
    }

    public function testCsrfHtmlTieneEstado403AccionYJsonMantieneElMensajeExistente(): void
    {
        $router = new Router();
        $router->post('/operacion', static function () { exigir_csrf(); });
        $_SERVER['REQUEST_URI'] = '/operacion';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $html = $this->dispatch($router);
        $this->assertSame(403, http_response_code());
        $this->assertStringContainsString('ui-error-card', $html);
        $this->assertStringContainsString('href="/"', $html);
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $json = json_decode($this->dispatch($router), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['resultado' => false, 'error' => 'Token CSRF inválido o ausente'], $json);
    }

    public function testFormularioLegadoPuedeMostrarErrorHtmlSinCambiarElContratoJsonDeApi(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/eliminar';
        $router = new Router();
        $router->post('/api/eliminar', static function () {
            ErrorResponse::render(500, ['format' => 'html']);
            detener_ejecucion(500);
        });
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $body = $this->dispatch($router);
        $this->assertSame(500, http_response_code());
        $this->assertStringContainsString('ui-error-card', $body);
        $this->assertStringNotContainsString('error de base de datos', $body);
        $json = $this->dispatch(new Router());
        $this->assertSame(404, http_response_code());
        $this->assertSame(false, json_decode($json, true, 512, JSON_THROW_ON_ERROR)['resultado']);
    }

    #[DataProvider('errorHomes')]
    public function testBotonDeErrorVuelveAUnaVistaDelRol(string $role, string $home): void
    {
        $_SESSION = ['login' => true, 'rol' => $role];
        $body = $this->dispatch(new Router());
        $this->assertStringContainsString('href="' . $home . '"', $body);
        $this->assertStringContainsString('Volver al inicio', $body);
    }

    public static function errorHomes(): array
    {
        return [['propietario', '/admin'], ['gerente', '/admin'], ['recepcion', '/admin'],
            ['profesional', '/agenda'], ['finanzas', '/admin/finanzas'], ['cliente', '/cita']];
    }

    public function testVistaRecibeDatosAnidadosSinPermitirCambiarElArchivoDeVista(): void
    {
        $level = ob_get_level();
        ob_start();
        try {
            (new Router())->render('bienestar/index', [
                'view' => 'auth/login',
                'datos' => [
                    'saldo' => 147,
                    'servicios' => [['id' => 91, 'nombre' => 'Servicio seleccionado']],
                    'formularios' => [[
                        'id' => 93, 'titulo' => 'Ficha publicada para el cliente',
                        'preguntas' => json_encode([['id' => 'p0', 'etiqueta' => 'Preferencia de atención',
                            'tipo' => 'texto', 'requerida' => false]], JSON_UNESCAPED_UNICODE),
                    ]],
                ],
                'ajustes' => [], 'mensaje' => '', 'error' => '',
            ]);
            $body = ob_get_contents();
        } finally {
            while (ob_get_level() > $level) ob_end_clean();
        }
        $this->assertStringContainsString('147 <small>puntos</small>', $body);
        $this->assertStringContainsString('Ficha publicada para el cliente', $body);
        $this->assertStringContainsString('name="formulario_id" value="93"', $body);
        $this->assertStringContainsString('Servicio seleccionado', $body);
        $this->assertStringContainsString('Preferencia de atención', $body);
        $this->assertStringNotContainsString('action="/" method="POST"', $body);
    }
}

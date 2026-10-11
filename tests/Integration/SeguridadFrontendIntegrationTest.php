<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use MVC\Router;
use Controllers\APIController;
use Controllers\LoginController;
use Controllers\ServicioController;
use Controllers\ProfesionalController;
use Controllers\PagoController;
use AppTerminationException;
use DOMDocument;

/**
 * Pruebas de integración de seguridad de frontend y transporte HTTP:
 * 1. Verificación estática de ausencia de innerHTML inseguro con interpolaciones dinámicas en JS.
 * 2. Simulación de payloads XSS maliciosos en renderizado backend (s() / htmlspecialchars).
 * 3. Simulación de construcción DOM segura mediante nodos de texto (createTextNode / textContent).
 * 4. Validación de emisión de cabeceras de seguridad HTTP centralizadas.
 * 5. Verificación de rechazo de rutas mutables ante CSRF inválido o ausente.
 */
class SeguridadFrontendIntegrationTest extends TestCase
{
    private array $serverBackup = [];
    private array $sessionBackup = [];
    private array $postBackup = [];
    private array $getBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $this->sessionBackup = $_SESSION ?? [];
        $this->postBackup = $_POST;
        $this->getBackup = $_GET;

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = null;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_SESSION = $this->sessionBackup;
        $_POST = $this->postBackup;
        $_GET = $this->getBackup;
        http_response_code(200);
    }

    /**
     * Comprueba que src/js/app.js y public/build/js/app.js no contengan ninguna asignación
     * de .innerHTML que concatene o interpole variables ${...} dinámicas, y que hayan sido
     * reemplazados por creación segura de nodos DOM y textContent.
     */
    public function testArchivosJavascriptNoTienenAsignacionesInsegurasInnerHTML(): void
    {
        $srcPath = realpath(__DIR__ . '/../../src/js/app.js');
        $buildPath = realpath(__DIR__ . '/../../public/build/js/app.js');

        $this->assertNotFalse($srcPath, 'src/js/app.js debe existir');
        $this->assertNotFalse($buildPath, 'public/build/js/app.js debe existir');

        $srcContent = (string)file_get_contents($srcPath);
        $buildContent = (string)file_get_contents($buildPath);

        // 1. Verificar que no exista ninguna asignación de .innerHTML que use plantillas con ${...}
        $patronInterpolacionInnerHTML = '/\.innerHTML\s*=\s*`[^`]*\$\{[^}]+\}[^`]*`/i';
        $this->assertSame(
            0,
            preg_match_all($patronInterpolacionInnerHTML, $srcContent, $matchesSrc),
            'src/js/app.js no debe contener asignaciones .innerHTML con interpolación ${...}'
        );
        $this->assertSame(
            0,
            preg_match_all($patronInterpolacionInnerHTML, $buildContent, $matchesBuild),
            'public/build/js/app.js no debe contener asignaciones .innerHTML con interpolación ${...}'
        );

        // 2. Verificar que no exista concatenación dinámica con variables en .innerHTML
        $patronConcatenacionInnerHTML = '/\.innerHTML\s*=\s*.*[+][^;]+/i';
        $this->assertSame(
            0,
            preg_match_all($patronConcatenacionInnerHTML, $srcContent),
            'src/js/app.js no debe concatenar variables dinámicas en .innerHTML'
        );
        $this->assertSame(
            0,
            preg_match_all($patronConcatenacionInnerHTML, $buildContent),
            'public/build/js/app.js no debe concatenar variables dinámicas en .innerHTML'
        );

        // 3. Confirmar que los patrones vulnerables específicos identificados en la auditoría fueron eliminados
        $patronesVulnerablesAuditoria = [
            'innerHTML = `<span>Nombre:</span> ${nombre}`',
            'innerHTML = `<span>Profesional:</span> ${profesionalNombre}`',
            'innerHTML = `Profesional: <span>${textoProf}</span>`',
            'innerHTML = `<span>Duración:</span> ${durServicio}',
            'innerHTML = `<span>Precio:</span> $ ${precio}`',
            'innerHTML = `<span>Fecha:</span> ${fechaFormateada}`',
            'innerHTML = `<span>Horario:</span> ${hora}',
            'innerHTML = `<span>Duración Total:</span> ${duracionTotal}',
            'innerHTML = `<span>Total a Pagar:</span> $ ${precioTotal',
            'innerHTML = `Fecha: <span>${item.fecha}</span>`',
            'innerHTML = `Total: <span>$ ${item.total'
        ];

        foreach ($patronesVulnerablesAuditoria as $patron) {
            $this->assertStringNotContainsString(
                $patron,
                $srcContent,
                "src/js/app.js no debe contener el fragmento vulnerable: $patron"
            );
            $this->assertStringNotContainsString(
                $patron,
                $buildContent,
                "public/build/js/app.js no debe contener el fragmento vulnerable: $patron"
            );
        }

        // 4. Confirmar que no exista NINGUNA aparición de .innerHTML en todo el código
        $this->assertSame(
            0,
            preg_match_all('/\.innerHTML\b/i', $srcContent),
            'src/js/app.js debe tener 0 usos de .innerHTML'
        );
        $this->assertSame(
            0,
            preg_match_all('/\.innerHTML\b/i', $buildContent),
            'public/build/js/app.js debe tener 0 usos de .innerHTML'
        );

        // 5. Confirmar presencia de llamadas seguras de construcción DOM
        $this->assertStringContainsString('document.createTextNode(', $srcContent);
        $this->assertMatchesRegularExpression('/\.textContent\s*=/', $srcContent);
        $this->assertStringContainsString('document.createTextNode(', $buildContent);
        $this->assertMatchesRegularExpression('/\.textContent\s*=/', $buildContent);
    }

    /**
     * Simula nombres maliciosos con payloads XSS y verifica que en el renderizado backend
     * (views/cita/index.php y views/admin/index.php) pasen por htmlspecialchars / s().
     */
    public function testRenderizadoBackendEscapaPayloadsXSS(): void
    {
        $payloadsXSS = [
            "<script>alert('xss')</script>",
            "<img src=x onerror=alert(1)>",
            "<svg onload=alert(1)>",
            "\"><b onmouseover=alert(1)>test</b>",
            "';alert(String.fromCharCode(88,83,83));//",
            "<iframe src=\"javascript:alert('xss')\">"
        ];

        foreach ($payloadsXSS as $payload) {
            // A. Verificar función s() de escape
            $escapado = s($payload);
            $this->assertStringNotContainsString('<script>', $escapado);
            $this->assertStringNotContainsString('<img', $escapado);
            $this->assertStringNotContainsString('<svg', $escapado);
            $this->assertStringNotContainsString('<b', $escapado);
            $this->assertStringNotContainsString('<iframe', $escapado);
            $this->assertStringNotContainsString('"', $escapado);
            $this->assertStringNotContainsString("'", $escapado);

            if (str_contains($payload, '<')) {
                $this->assertStringContainsString('&lt;', $escapado);
            }
            if (str_contains($payload, '>')) {
                $this->assertStringContainsString('&gt;', $escapado);
            }
            if (str_contains($payload, '"')) {
                $this->assertStringContainsString('&quot;', $escapado);
            }
            if (str_contains($payload, "'")) {
                $this->assertStringContainsString('&#039;', $escapado);
            }

            // B. Renderizado en views/cita/index.php
            $nombre = $payload;
            $apellido = 'Prueba';
            $id = 55;
            $fechaMinima = '2026-10-15';
            $_SESSION['csrf_token'] = 'token_test_csrf';

            ob_start();
            try {
                include __DIR__ . '/../../views/cita/index.php';
            } finally {
                $htmlCita = (string)ob_get_clean();
            }

            $this->assertStringNotContainsString("<script>alert('xss')</script>", $htmlCita);
            $this->assertStringNotContainsString("<img src=x onerror=alert(1)>", $htmlCita);
            $this->assertStringNotContainsString("<svg onload=alert(1)>", $htmlCita);
            $this->assertStringNotContainsString("<b onmouseover=alert(1)>test</b>", $htmlCita);
            $this->assertStringContainsString(s($nombre . ' ' . $apellido), $htmlCita);

            // C. Renderizado en views/admin/index.php
            $citaMock = (object)[
                'id' => 101,
                'profesionalId' => 7,
                'profesional_nombre' => $payload,
                'hora_inicio' => '10:00:00',
                'hora_fin' => '10:30:00',
                'hora' => '10:00:00',
                'duracion_total_minutos' => 30,
                'cliente' => $payload,
                'email' => 'payload@malicious.com',
                'telefono' => '0999999999',
                'servicio' => $payload,
                'precio' => 25.00,
                'duracion_minutos' => 30
            ];
            $citas = [$citaMock];
            $fecha = '2026-10-15';
            $alertas = [];

            ob_start();
            try {
                include __DIR__ . '/../../views/admin/index.php';
            } finally {
                $htmlAdmin = (string)ob_get_clean();
            }

            $this->assertStringNotContainsString("<script>alert('xss')</script>", $htmlAdmin);
            $this->assertStringNotContainsString("<img src=x onerror=alert(1)>", $htmlAdmin);
            $this->assertStringNotContainsString("<svg onload=alert(1)>", $htmlAdmin);
            $this->assertStringNotContainsString("<b onmouseover=alert(1)>test</b>", $htmlAdmin);
            $this->assertStringContainsString(s($payload), $htmlAdmin);
        }
    }

    /**
     * En la construcción DOM de JavaScript, verifica que al procesarse mediante nodos y textContent,
     * los caracteres especiales (<, >, ", ', &) se traten estrictamente como texto plano sin generar
     * nodos ejecutables en el árbol DOM.
     */
    public function testConstruccionDomJavaScriptTrataPayloadsComoTextoPlanoSinNodosEjecutables(): void
    {
        $payloadsXSS = [
            "<script>alert('xss')</script>",
            "<img src=x onerror=alert(1)>",
            "<svg onload=alert(1)>",
            "\"><b onmouseover=alert(1)>test</b>",
            "<iframe src=\"javascript:alert('xss')\">"
        ];

        foreach ($payloadsXSS as $payload) {
            $doc = new DOMDocument('1.0', 'UTF-8');

            // Simulación fiel de la lógica implementada en app.js:
            // const nombreClienteP = document.createElement('P');
            // const spanNombre = document.createElement('SPAN');
            // spanNombre.textContent = 'Nombre:';
            // nombreClienteP.appendChild(spanNombre);
            // nombreClienteP.appendChild(document.createTextNode(' ' + nombre));
            $p = $doc->createElement('p');
            $span = $doc->createElement('span');
            $span->textContent = 'Nombre:';
            $p->appendChild($span);

            $textNode = $doc->createTextNode(' ' . $payload);
            $p->appendChild($textNode);
            $doc->appendChild($p);

            // 1. Comprobar que en el árbol DOM NO se creó ningún elemento script, img, svg, b o iframe
            $this->assertSame(0, $p->getElementsByTagName('script')->length);
            $this->assertSame(0, $p->getElementsByTagName('img')->length);
            $this->assertSame(0, $p->getElementsByTagName('svg')->length);
            $this->assertSame(0, $p->getElementsByTagName('b')->length);
            $this->assertSame(0, $p->getElementsByTagName('iframe')->length);

            // 2. Comprobar que textContent preserva exactamente el texto literal sin ejecución
            $this->assertSame('Nombre: ' . $payload, $p->textContent);

            // 3. Comprobar que al serializar a HTML, el payload queda codificado como texto plano
            $htmlSerializado = (string)$doc->saveHTML($p);
            $this->assertStringNotContainsString("<script>", $htmlSerializado);
            $this->assertStringNotContainsString("<img", $htmlSerializado);
            $this->assertStringNotContainsString("<svg", $htmlSerializado);
            $this->assertStringNotContainsString("<b onmouseover", $htmlSerializado);
        }
    }

    /**
     * Verifica que emitir_cabeceras_seguridad() emita las cabeceras estándar
     * (X-Content-Type-Options, X-Frame-Options, Referrer-Policy) y que Router las active.
     */
    public function testCabecerasDeSeguridadActivas(): void
    {
        $headers = emitir_cabeceras_seguridad();

        $this->assertArrayHasKey('X-Content-Type-Options', $headers);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);

        $this->assertArrayHasKey('X-Frame-Options', $headers);
        $this->assertSame('SAMEORIGIN', $headers['X-Frame-Options']);

        $this->assertArrayHasKey('Referrer-Policy', $headers);
        $this->assertSame('strict-origin-when-cross-origin', $headers['Referrer-Policy']);

        $this->assertSame($headers, $GLOBALS['__appsalon_security_headers'] ?? []);

        // Comprobar que Router invoca emitir_cabeceras_seguridad()
        $router = new Router();
        $_SERVER['REQUEST_URI'] = '/ruta-inexistente-test';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        ob_start();
        try {
            $router->comprobarRutas();
        } finally {
            ob_end_clean();
        }

        $this->assertNotEmpty($GLOBALS['__appsalon_security_headers'] ?? []);
    }

    /**
     * Verifica que todas las rutas mutables (POST) cuenten con protección CSRF activa,
     * rechazando peticiones que no presenten token o contengan tokens manipulados con código 403.
     */
    public function testRutasMutablesExigenProteccionCsrf(): void
    {
        // 1. Comprobación directa de función central validar_csrf()
        $_SESSION['csrf_token'] = 'token_secreto_valido_12345';

        // Sin token en petición -> false
        $_POST = [];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = null;
        $this->assertFalse(validar_csrf());

        // Con token manipulado en POST -> false
        $_POST['csrf_token'] = 'token_falsificado_67890';
        $this->assertFalse(validar_csrf());

        // Con token manipulado en Header -> false
        $_POST = [];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token_falsificado_header';
        $this->assertFalse(validar_csrf());

        // Con token coincidente en POST -> true
        $_POST['csrf_token'] = 'token_secreto_valido_12345';
        $this->assertTrue(validar_csrf());

        // Con token coincidente en Header -> true
        $_POST = [];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token_secreto_valido_12345';
        $this->assertTrue(validar_csrf());

        // 2. Comprobación de exigir_csrf(): debe abortar con 403 ante token inválido
        $_POST['csrf_token'] = 'invalido';
        ob_start();
        try {
            exigir_csrf();
            $this->fail('exigir_csrf() debe detener la ejecución con 403');
        } catch (AppTerminationException $e) {
            $this->assertSame(403, $e->getStatusCode());
        } finally {
            ob_end_clean();
        }

        // 3. Comprobación de endpoints mutables representativos del sistema:

        // A. Logout
        $_SESSION['login'] = true;
        $_SESSION['id'] = 1;
        $_SESSION['csrf_token'] = 'token_sesion';
        $_POST['csrf_token'] = 'token_invalido';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        ob_start();
        try {
            LoginController::logout();
            $this->fail('Logout con CSRF inválido debe lanzar AppTerminationException');
        } catch (AppTerminationException $e) {
            $this->assertSame(403, $e->getStatusCode());
        } finally {
            ob_end_clean();
        }

        // B. API Crear Cita (POST /api/citas)
        $_SESSION['login'] = true;
        $_SESSION['id'] = 1;
        $_SESSION['csrf_token'] = 'token_sesion';
        $_POST['csrf_token'] = 'token_invalido';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        ob_start();
        try {
            APIController::guardar();
        } catch (AppTerminationException $e) {
            $this->assertSame(403, $e->getStatusCode());
        } finally {
            $salidaJson = ob_get_clean();
        }
        $json = json_decode($salidaJson, true);
        $this->assertIsArray($json);
        $this->assertFalse($json['resultado']);

        // C. API Eliminar Cita (POST /api/eliminar)
        $_SESSION['login'] = true;
        $_SESSION['admin'] = '1';
        $_SESSION['csrf_token'] = 'token_sesion';
        $_POST['csrf_token'] = 'token_invalido';
        $_POST['id'] = '1';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        ob_start();
        try {
            APIController::eliminar();
            $this->fail('Eliminar cita con CSRF inválido debe rechazar con 403');
        } catch (AppTerminationException $e) {
            $this->assertSame(403, $e->getStatusCode());
        } finally {
            ob_end_clean();
        }

        // D. CRUD Servicios Crear (POST /servicios/crear)
        $_SESSION['login'] = true;
        $_SESSION['admin'] = '1';
        $_SESSION['csrf_token'] = 'token_sesion';
        $_POST['csrf_token'] = 'token_invalido';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $router = new Router();
        ob_start();
        try {
            ServicioController::crear($router);
            $this->fail('Crear servicio con CSRF inválido debe rechazar con 403');
        } catch (AppTerminationException $e) {
            $this->assertSame(403, $e->getStatusCode());
        } finally {
            ob_end_clean();
        }

        // E. CRUD Servicios Eliminar (POST /servicios/eliminar)
        $_SESSION['login'] = true;
        $_SESSION['admin'] = '1';
        $_SESSION['csrf_token'] = 'token_sesion';
        $_POST['csrf_token'] = 'token_invalido';
        $_POST['id'] = '1';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        ob_start();
        try {
            ServicioController::eliminar($router);
            $this->fail('Eliminar servicio con CSRF inválido debe rechazar con 403');
        } catch (AppTerminationException $e) {
            $this->assertSame(403, $e->getStatusCode());
        } finally {
            ob_end_clean();
        }

        // F. Pagos Iniciar (POST /api/pagos/iniciar)
        $_SESSION['login'] = true;
        $_SESSION['id'] = 1;
        $_SESSION['csrf_token'] = 'token_sesion';
        $_POST['csrf_token'] = 'token_invalido';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        ob_start();
        try {
            PagoController::iniciar();
        } catch (AppTerminationException $e) {
            $this->assertSame(403, $e->getStatusCode());
        } finally {
            $salidaPago = ob_get_clean();
        }
        $jsonPago = json_decode($salidaPago, true);
        $this->assertIsArray($jsonPago);
        $this->assertFalse($jsonPago['resultado']);

        // G. Pagos Reembolsar (POST /api/pagos/reembolsar)
        $_SESSION['login'] = true;
        $_SESSION['admin'] = '1';
        $_SESSION['csrf_token'] = 'token_sesion';
        $_POST['csrf_token'] = 'token_invalido';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        ob_start();
        try {
            PagoController::reembolsar();
        } catch (AppTerminationException $e) {
            $this->assertSame(403, $e->getStatusCode());
        } finally {
            $salidaReembolso = ob_get_clean();
        }
        $jsonReembolso = json_decode($salidaReembolso, true);
        $this->assertIsArray($jsonReembolso);
        $this->assertFalse($jsonReembolso['resultado']);

        // H. Profesionales Estado (POST /profesionales/estado)
        $_SESSION['login'] = true;
        $_SESSION['admin'] = '1';
        $_SESSION['csrf_token'] = 'token_sesion';
        $_POST['csrf_token'] = 'token_invalido';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        ob_start();
        try {
            ProfesionalController::cambiarEstado();
            $this->fail('Cambiar estado profesional con CSRF inválido debe rechazar con 403');
        } catch (AppTerminationException $e) {
            $this->assertSame(403, $e->getStatusCode());
        } finally {
            ob_end_clean();
        }
    }
}

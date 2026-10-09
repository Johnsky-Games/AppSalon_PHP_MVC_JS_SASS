<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/funciones.php';

class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
        } else {
            $_SESSION = [];
        }
        $_POST = [];
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    public function testAmbosTokensAusentesSonRechazados(): void
    {
        // Ni sesión ni petición tienen token
        $_SESSION['csrf_token'] = null;
        $_POST['csrf_token'] = null;

        $this->assertFalse(validar_csrf(), 'Dos tokens nulos o ausentes deben ser rechazados');
    }

    public function testTokenSesionPresentePeroPeticionVaciaEsRechazado(): void
    {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_POST['csrf_token'] = '';

        $this->assertFalse(validar_csrf(), 'Token recibido vacío debe ser rechazado');
    }

    public function testTokenSesionAusentePeroPeticionConTokenEsRechazado(): void
    {
        $_SESSION['csrf_token'] = '';
        $_POST['csrf_token'] = bin2hex(random_bytes(32));

        $this->assertFalse(validar_csrf(), 'Token de sesión vacío debe ser rechazado');
    }

    public function testTokenRecibidoComoArrayEsRechazado(): void
    {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_POST['csrf_token'] = ['array_malicioso'];

        $this->assertFalse(validar_csrf(), 'Un array en lugar de string escalar debe ser rechazado');
    }

    public function testTokenRecibidoDistintoEsRechazado(): void
    {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_POST['csrf_token'] = bin2hex(random_bytes(32)); // Diferente

        $this->assertFalse(validar_csrf(), 'Tokens distintos deben ser rechazados');
    }

    public function testTokenValidoEnPostEsAceptado(): void
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        $_POST['csrf_token'] = $token;

        $this->assertTrue(validar_csrf(), 'Tokens coincidentes en POST deben ser aceptados');
    }

    public function testTokenValidoEnCabeceraHttpEsAceptado(): void
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;

        $this->assertTrue(validar_csrf(), 'Tokens coincidentes en header HTTP_X_CSRF_TOKEN deben ser aceptados');
    }

    public function testExigirCsrfLanzaExcepcionTerminacion403SiEsInvalido(): void
    {
        $_SESSION['csrf_token'] = 'token_sesion';
        $_POST['csrf_token'] = 'token_invalido';

        $this->expectException(\AppTerminationException::class);
        try {
            ob_start();
            exigir_csrf();
        } finally {
            ob_end_clean();
        }
    }
}

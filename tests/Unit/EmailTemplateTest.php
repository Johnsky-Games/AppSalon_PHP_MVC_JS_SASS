<?php

namespace Tests\Unit;

use Classes\Email;
use Classes\EmailTemplate;
use PHPUnit\Framework\TestCase;

class EmailTemplateTest extends TestCase
{
    private ?string $appUrlOriginal;

    protected function setUp(): void
    {
        parent::setUp();
        Email::setTransport(null);
        Email::limpiarEmailsEnviados();
        $this->appUrlOriginal = $_ENV['APP_URL'] ?? null;
        $_ENV['APP_URL'] = 'https://salon.example/portal';
    }

    protected function tearDown(): void
    {
        Email::setTransport(null);
        Email::limpiarEmailsEnviados();
        if ($this->appUrlOriginal === null) {
            unset($_ENV['APP_URL']);
        } else {
            $_ENV['APP_URL'] = $this->appUrlOriginal;
        }
        parent::tearDown();
    }

    public function testInformacionDelNegocioYReservaSeEscapaYSeConservaEnTextoPlano(): void
    {
        $plantilla = new EmailTemplate([
            'nombre_comercial' => 'Salón & Spa <script>empresa()</script>',
            'contacto' => 'Recepción <b>María</b>',
            'telefono' => '+593 02 123 4567',
            'direccion' => 'Av. Ejemplo 123 & Plaza 4',
            'email_contacto' => 'recepcion@salon.example',
        ]);
        $contenido = $plantilla->render([
            'titulo' => 'Tu visita #42',
            'nombre' => 'Ana <img src=x onerror=alert(1)>',
            'parrafos' => ['Tu reserva está lista.'],
            'detalles' => ['Fecha' => '2026-12-20', 'Horario' => '09:30 - 10:00', 'Profesional' => 'María & José'],
            'servicios' => [['nombre' => 'Corte <b>Premium</b>', 'duracion_minutos' => 30, 'precio' => '25.00']],
            'total' => '$25.00',
            'enlace' => 'https://salon.example/portal/cita',
            'boton' => 'Ver mis citas',
        ]);

        $this->assertStringNotContainsString('<script>', $contenido['html']);
        $this->assertStringNotContainsString('<img src=x', $contenido['html']);
        $this->assertStringContainsString('Salón &amp; Spa &lt;script&gt;empresa()&lt;/script&gt;', $contenido['html']);
        $this->assertStringContainsString('Corte &lt;b&gt;Premium&lt;/b&gt; (30 min)', $contenido['html']);
        $this->assertStringContainsString('mailto:recepcion@salon.example', $contenido['html']);
        foreach (['2026-12-20', '09:30 - 10:00', '$25.00', '+593 02 123 4567', 'Av. Ejemplo 123 & Plaza 4', 'recepcion@salon.example', 'Ver mis citas: https://salon.example/portal/cita'] as $dato) {
            $this->assertStringContainsString($dato, $contenido['texto']);
        }
        $this->assertStringNotContainsString('font-size:', $contenido['texto']);
        $this->assertStringNotContainsString('<table', $contenido['texto']);
    }

    public function testConfiguracionVaciaNoInventaDatosDeContactoNiUsaRemitenteComoNegocio(): void
    {
        $contenido = (new EmailTemplate([]))->render(['nombre' => 'Cliente']);

        $this->assertStringContainsString('App Salón', $contenido['html']);
        $this->assertStringContainsString('App Salón', $contenido['texto']);
        foreach (['cuentas@appsalon.com', 'Teléfono:', 'Dirección:', 'Correo:', 'Contacto:', 'mailto:'] as $inventado) {
            $this->assertStringNotContainsString($inventado, $contenido['html']);
            $this->assertStringNotContainsString($inventado, $contenido['texto']);
        }
    }

    public function testUrlsPeligrosasYCorreosInvalidosNoCreanEnlacesEjecutables(): void
    {
        foreach (['javascript:alert(1)', 'data:text/html,malicioso', 'https://usuario:clave@salon.example', "https://salon.example/'onclick='alert(1)", "https://salon.example/\r\nBcc:evil@example.com"] as $url) {
            $contenido = (new EmailTemplate(['email_contacto' => "malicioso\r\nBcc:evil@example.com"]))->render(['enlace' => $url]);

            $this->assertSame('https://salon.example/portal', EmailTemplate::urlSegura($url));
            $this->assertStringNotContainsString('javascript:', $contenido['html']);
            $this->assertStringNotContainsString('data:text/html', $contenido['html']);
            $this->assertStringNotContainsString('onclick=', $contenido['html']);
            $this->assertStringNotContainsString('mailto:', $contenido['html']);
            $this->assertStringContainsString('https://salon.example/portal', $contenido['texto']);
        }

        $url = 'https://salon.example/recuperar?token=a%2Fb%2B%26c&continuar=cuenta';
        $this->assertSame($url, EmailTemplate::urlSegura($url));
        $contenido = (new EmailTemplate([]))->render(['enlace' => $url]);
        $this->assertStringContainsString('token=a%2Fb%2B%26c&amp;continuar=cuenta', $contenido['html']);
        $this->assertStringContainsString($url, $contenido['texto']);
    }

    public function testConversionDeHtmlParaConsumidoresAnterioresExcluyeCssYPreheader(): void
    {
        $html = '<html><head><style>.email-title{font-size:30px}</style><title>Oculto</title></head><body>'
            . '<div aria-hidden="true">Preheader oculto</div><h1>Tu reserva</h1><p>Fecha: 2026-12-20</p>'
            . '<p>Horario: 10:00<br>Total: $25.00</p><p>Ana &amp; José</p></body></html>';
        $texto = EmailTemplate::textoDesdeHtml($html);

        $this->assertStringNotContainsString('font-size', $texto);
        $this->assertStringNotContainsString('Preheader oculto', $texto);
        $this->assertStringNotContainsString('Oculto', $texto);
        $this->assertStringContainsString("Tu reserva\nFecha: 2026-12-20\nHorario: 10:00\nTotal: $25.00", $texto);
        $this->assertStringContainsString('Ana & José', $texto);
    }

    public function testTextoPlanoExplicitoSeMantieneDuranteElDespachoSustituible(): void
    {
        $capturado = null;
        $resultado = Email::despacharNotificacionCita(
            'confirmacion',
            'cliente@salon.example',
            'Cliente',
            'Tu reserva',
            '<p>Tu reserva #42</p>',
            [],
            static function ($mailer) use (&$capturado): bool {
                $capturado = $mailer;
                return true;
            },
            "Tu reserva #42\nHorario: 10:00\nVer mis citas: https://salon.example/cita"
        );

        $this->assertTrue($resultado['exito']);
        $this->assertSame("Tu reserva #42\nHorario: 10:00\nVer mis citas: https://salon.example/cita", $capturado->AltBody);
        $this->assertSame('UTF-8', $capturado->CharSet);
        Email::limpiarEmailsEnviados();
    }
}

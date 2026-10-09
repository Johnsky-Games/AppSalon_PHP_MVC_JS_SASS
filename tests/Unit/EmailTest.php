<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Classes\Email;

class EmailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Email::setTransport(null);
        Email::limpiarEmailsEnviados();
    }

    protected function tearDown(): void
    {
        Email::setTransport(null);
        Email::limpiarEmailsEnviados();
        parent::tearDown();
    }

    public function testEnviarConfirmacionUsaDestinatarioPropositoYEnlaceCorrectos(): void
    {
        Email::setTransport(function(string $proposito, string $destinatario, string $nombre, string $token, string $enlace): bool {
            return true;
        });

        $email = new Email('Carlos Mendoza', 'carlos@dominio.com', 'tokenConfirm123');
        $resultado = $email->enviarConfirmacion();

        $this->assertTrue($resultado, 'El envío con transporte simulado debe retornar true');
        $this->assertCount(1, Email::$emailsEnviados);

        $enviado = Email::$emailsEnviados[0];
        $this->assertSame('confirmacion', $enviado['proposito']);
        $this->assertSame('carlos@dominio.com', $enviado['destinatario']);
        $this->assertSame('Carlos Mendoza', $enviado['nombre']);
        $this->assertSame('tokenConfirm123', $enviado['token']);
        $this->assertStringContainsString('/confirmar-cuenta?token=tokenConfirm123', $enviado['enlace']);
    }

    public function testEnviarInstruccionesUsaDestinatarioPropositoYEnlaceCorrectos(): void
    {
        Email::setTransport(function(string $proposito, string $destinatario, string $nombre, string $token, string $enlace): bool {
            return true;
        });

        $email = new Email('Laura Rios', 'laura@dominio.com', 'tokenRecup456');
        $resultado = $email->enviarInstrucciones();

        $this->assertTrue($resultado, 'El envío con transporte simulado debe retornar true');
        $this->assertCount(1, Email::$emailsEnviados);

        $enviado = Email::$emailsEnviados[0];
        $this->assertSame('recuperacion', $enviado['proposito']);
        $this->assertSame('laura@dominio.com', $enviado['destinatario']);
        $this->assertSame('Laura Rios', $enviado['nombre']);
        $this->assertSame('tokenRecup456', $enviado['token']);
        $this->assertStringContainsString('/recuperar?token=tokenRecup456', $enviado['enlace']);
    }

    public function testTransporteSimuladoQueFallaRetornaFalse(): void
    {
        Email::setTransport(function(): bool {
            return false;
        });

        $emailConf = new Email('Fallo Confirm', 'fallo1@dominio.com', 'tok1');
        $this->assertFalse($emailConf->enviarConfirmacion(), 'Transporte que falla debe retornar false');

        $emailInst = new Email('Fallo Recup', 'fallo2@dominio.com', 'tok2');
        $this->assertFalse($emailInst->enviarInstrucciones(), 'Transporte que falla debe retornar false');

        $this->assertCount(0, Email::$emailsEnviados, 'No deben registrarse como enviados si el transporte falló');
    }
}

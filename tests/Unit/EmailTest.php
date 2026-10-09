<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Classes\Email;
use PHPMailer\PHPMailer\PHPMailer;

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

    public function testEnviarConfirmacionConstruyeMensajeRealConDestinatarioRemitenteYEnlace(): void
    {
        $capturadoMailer = null;
        Email::setTransport(function(PHPMailer $mailer, string $proposito, array $meta) use (&$capturadoMailer): bool {
            $capturadoMailer = $mailer;
            return true;
        });

        $email = new Email('Carlos Mendoza', 'carlos@dominio.com', 'tokenConfirm123');
        $resultado = $email->enviarConfirmacion();

        $this->assertTrue($resultado, 'El envío con transporte sustituible debe retornar true');
        $this->assertCount(1, Email::$emailsEnviados);
        $this->assertInstanceOf(PHPMailer::class, $capturadoMailer, 'El transporte sustituible debe recibir la instancia real de PHPMailer');

        // Verificar destinatario real configurado en el objeto PHPMailer
        $destinatarios = $capturadoMailer->getToAddresses();
        $this->assertCount(1, $destinatarios);
        $this->assertSame('carlos@dominio.com', $destinatarios[0][0]);
        $this->assertSame('Carlos Mendoza', $destinatarios[0][1]);

        // Verificar remitente configurado en PHPMailer
        $fromEsperado = $_ENV['EMAIL_FROM'] ?? 'cuentas@appsalon.com';
        $fromNameEsperado = $_ENV['EMAIL_FROM_NAME'] ?? 'AppSalon.com';
        $this->assertSame($fromEsperado, $capturadoMailer->From);
        $this->assertSame($fromNameEsperado, $capturadoMailer->FromName);

        // Verificar asunto y enlace real en el cuerpo HTML
        $this->assertSame('Confirma tu cuenta', $capturadoMailer->Subject);
        $this->assertStringContainsString('/confirmar-cuenta?token=tokenConfirm123', $capturadoMailer->Body);
        $this->assertStringContainsString('Carlos Mendoza', $capturadoMailer->Body);
    }

    public function testEnviarInstruccionesConstruyeMensajeRealConDestinatarioRemitenteYEnlace(): void
    {
        $capturadoMailer = null;
        Email::setTransport(function(PHPMailer $mailer, string $proposito, array $meta) use (&$capturadoMailer): bool {
            $capturadoMailer = $mailer;
            return true;
        });

        $email = new Email('Laura Rios', 'laura@dominio.com', 'tokenRecup456');
        $resultado = $email->enviarInstrucciones();

        $this->assertTrue($resultado, 'El envío con transporte sustituible debe retornar true');
        $this->assertCount(1, Email::$emailsEnviados);
        $this->assertInstanceOf(PHPMailer::class, $capturadoMailer, 'El transporte sustituible debe recibir la instancia real de PHPMailer');

        // Verificar destinatario real en PHPMailer
        $destinatarios = $capturadoMailer->getToAddresses();
        $this->assertCount(1, $destinatarios);
        $this->assertSame('laura@dominio.com', $destinatarios[0][0]);
        $this->assertSame('Laura Rios', $destinatarios[0][1]);

        // Verificar remitente configurado en PHPMailer
        $fromEsperado = $_ENV['EMAIL_FROM'] ?? 'cuentas@appsalon.com';
        $fromNameEsperado = $_ENV['EMAIL_FROM_NAME'] ?? 'AppSalon.com';
        $this->assertSame($fromEsperado, $capturadoMailer->From);
        $this->assertSame($fromNameEsperado, $capturadoMailer->FromName);

        // Verificar asunto y enlace de recuperación
        $this->assertSame('Reestablece tu password', $capturadoMailer->Subject);
        $this->assertStringContainsString('/recuperar?token=tokenRecup456', $capturadoMailer->Body);
        $this->assertStringContainsString('Laura Rios', $capturadoMailer->Body);
    }

    public function testTransporteSustituibleQueFallaRetornaFalse(): void
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

<?php

namespace Tests\Unit;

use Classes\Email;
use Model\Usuario;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Regresiones QA R2B-03c/03d (direcciones en errores SMTP) y R3b-19 (byte NUL en contraseñas). */
final class RedaccionYPasswordNulTest extends TestCase
{
    public static function direcciones(): array
    {
        return [
            'simple' => ['550 5.1.1 <ana@example.test>: user unknown'],
            'apóstrofo' => ["failed: o'brien.x@example.test: <o'brien.x@example.test>"],
            'parte local entre comillas' => ['RCPT <"zq@x"@example.test> rejected'],
            'literal IPv4' => ['RCPT <zq@[127.0.0.1]> rejected'],
            'literal IPv6' => ['RCPT <zq@[IPv6:2001:db8::1]> rejected'],
            'mayúsculas y subdominios' => ['Admin <ADMIN@MAIL.Sub-Dom.EXAMPLE.CO.UK> y a+b@x.example.org'],
        ];
    }

    #[DataProvider('direcciones')]
    public function testElSaneadorNoDejaDireccionesDeCorreo(string $mensaje): void
    {
        $r = Email::sanearMensajeError($mensaje);
        $this->assertStringContainsString('[correo]', $r);
        $this->assertDoesNotMatchRegularExpression('/@/', $r, "queda una dirección en: {$r}");
    }

    public function testUnTextoSinDireccionesNoSeAltera(): void
    {
        $this->assertSame('450 4.2.0 try again later', Email::sanearMensajeError('450 4.2.0 try again later'));
    }

    public function testUnaContrasenaConByteNulSeRechazaSinExcepcion(): void
    {
        $u = new Usuario(['password' => "Clave\0Nula123"]);
        $alertas = $u->validarPassword();
        $this->assertNotEmpty($alertas['error'] ?? []);
        $this->assertStringContainsString('no válidos', implode(' ', $alertas['error']));
        $ok = new Usuario(['password' => 'Clave-Valida-123']);
        $this->assertSame([], $ok->validarPassword());
    }
}

<?php
namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Regresión QA DEF-R2-03 (CWE-601): el Referer solo se acepta si es del mismo origen. */
final class DestinoRetornoSeguroTest extends TestCase
{
    private array $servidorOriginal;
    private array $sesionOriginal;

    protected function setUp(): void
    {
        $this->servidorOriginal = $_SERVER;
        $this->sesionOriginal = $_SESSION ?? [];
        $_SERVER['HTTP_HOST'] = 'salon.test:8085';
        $_SESSION = ['login' => true, 'portal' => 'cliente'];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->servidorOriginal;
        $_SESSION = $this->sesionOriginal;
    }

    public static function referers(): array
    {
        return [
            'externo' => ['https://evil.example/phishing', '/cita'],
            'protocolo relativo' => ['//evil.example/x', '/cita'],
            'mismo host distinto puerto' => ['http://salon.test:9999/admin', '/cita'],
            'esquema peligroso' => ['javascript:alert(1)', '/cita'],
            'doble barra en la ruta' => ['http://salon.test:8085//evil.example', '/cita'],
            'barra invertida' => ['http://salon.test:8085/\\evil.example', '/cita'],
            'vacío' => ['', '/cita'],
            'mismo origen' => ['http://salon.test:8085/cita?x=1', '/cita?x=1'],
            'mismo origen otra ruta' => ['http://SALON.test:8085/mis-citas', '/mis-citas'],
        ];
    }

    #[DataProvider('referers')]
    public function testSoloSeAceptaElMismoOrigen(string $referer, string $esperado): void
    {
        $this->assertSame($esperado, destino_retorno_seguro($referer));
    }
}

<?php

namespace Tests\Integration;

use Classes\Email;
use PHPUnit\Framework\TestCase;
use Services\NotificacionCitaService;

/**
 * Ronda 5 — auditoría de enlaces de correos y notificaciones: ningún correo manda a una persona a una pantalla del OTRO portal.
 * El integrador la escribió (no es una revisión independiente). Solo construye el contenido de los correos (no envía nada).
 */
final class R5EnlacesCorreoTest extends TestCase
{
    private const BASE = 'http://localhost:3000';

    protected function setUp(): void
    {
        $_ENV['APP_URL'] = self::BASE; putenv('APP_URL=' . self::BASE);
        $_ENV['EMAIL_HOST'] = '127.0.0.1'; $_ENV['EMAIL_PORT'] = '2599'; $_ENV['EMAIL_USER'] = 't'; $_ENV['EMAIL_PASSWORD'] = 't';
    }

    /** @return string[] URLs absolutas de href en el HTML y URLs en el texto plano */
    private static function enlaces(string $html, string $texto): array
    {
        preg_match_all('/href\s*=\s*[\'"]([^\'"]+)[\'"]/i', $html, $a);
        preg_match_all('~https?://[^\s<>"\']+~i', $texto, $b);
        return array_values(array_unique(array_map('html_entity_decode', array_merge($a[1], $b[0]))));
    }

    private static function correo(string $proposito, string $portal): array
    {
        $e = new Email('Persona Ficticia', 'ficticia@example.test', 'TOKEN-FICTICIO');
        $e->portal = $portal;
        $enlace = match ($proposito) {
            'confirmacion' => self::BASE . '/confirmar-cuenta?token=TOKEN-FICTICIO',
            'recuperacion' => self::BASE . '/recuperar?token=TOKEN-FICTICIO' . ($portal === 'equipo' ? '&portal=equipo' : ''),
            'invitacion' => self::BASE . '/equipo/aceptar?token=TOKEN-FICTICIO',
            'cuenta_existente' => self::BASE . ($portal === 'equipo' ? '/equipo/login' : '/cliente/login'),
        };
        $m = $e->prepararMailer($proposito, $enlace);
        return [$enlace, self::enlaces($m->Body, $m->AltBody)];
    }

    public static function casos(): array
    {
        return [
            'confirmación (cliente)' => ['confirmacion', 'cliente', '/confirmar-cuenta'],
            'recuperación (cliente)' => ['recuperacion', 'cliente', '/recuperar'],
            'recuperación (equipo)' => ['recuperacion', 'equipo', '/recuperar'],
            'invitación (equipo)' => ['invitacion', 'equipo', '/equipo/aceptar'],
            'cuenta existente (cliente)' => ['cuenta_existente', 'cliente', '/cliente/login'],
            'cuenta existente (equipo)' => ['cuenta_existente', 'equipo', '/equipo/login'],
        ];
    }

    /** @dataProvider casos */
    public function test_los_correos_de_cuenta_llevan_al_portal_de_quien_los_recibe(string $proposito, string $portal, string $rutaEsperada): void
    {
        [$enlace, $urls] = self::correo($proposito, $portal);
        $this->assertContains($enlace, $urls, 'el botón/enlace principal debe aparecer en el correo');
        $rutaPrincipal = parse_url($enlace, PHP_URL_PATH);
        $this->assertSame($rutaEsperada, $rutaPrincipal);
        $equipo = $portal === 'equipo' || $proposito === 'invitacion';
        foreach ($urls as $u) {
            if (str_starts_with($u, 'mailto:')) { continue; }
            $this->assertStringStartsWith(self::BASE, $u, "origen distinto del de la aplicación: $u");
            $ruta = (string)parse_url($u, PHP_URL_PATH) ?: '/';
            if ($equipo) {
                $this->assertNotContains($ruta, ['/', '/cita', '/mis-citas', '/mi-bienestar', '/cliente/login', '/cliente/registro'], "correo del EQUIPO con un enlace del portal de clientes: $u");
            } else {
                $this->assertDoesNotMatchRegularExpression('~^/(admin|agenda|caja|equipo|profesionales|servicios)(/|$)~', $ruta, "correo del CLIENTE con un enlace del equipo: $u");
            }
        }
    }

    /** @dataProvider casos */
    public function test_solo_el_enlace_personal_lleva_token_y_ningun_otro(string $proposito, string $portal): void
    {
        [$enlace, $urls] = self::correo($proposito, $portal);
        $conToken = 0;
        foreach ($urls as $u) {
            if (str_contains($u, 'token=')) { $conToken++; $this->assertSame($enlace, $u, 'un enlace distinto del personal lleva token'); }
        }
        $this->assertSame(str_contains($enlace, 'token=') ? 1 : 0, $conToken, 'el enlace con token debe aparecer una sola vez (deduplicado) o no existir');
    }

    public static function tiposNotificacion(): array
    {
        return ['confirmación' => ['confirmacion'], 'cancelación' => ['cancelacion'], 'recordatorio' => ['recordatorio']];
    }

    /** @dataProvider tiposNotificacion */
    public function test_las_notificaciones_de_citas_solo_enlazan_al_portal_del_cliente_y_sin_tokens(string $tipo): void
    {
        $payload = ['cita_id' => 123, 'cliente_nombre' => 'Clienta Ficticia', 'fecha' => '2030-01-08', 'hora_inicio' => '10:00', 'hora_fin' => '10:30', 'duracion_total_minutos' => 30,
            'profesional_nombre' => 'Profesional Ficticia', 'servicios' => [['nombre' => 'Corte', 'precio' => '20.00', 'duracion_minutos' => 30]], 'estado' => $tipo === 'cancelacion' ? 'cancelada' : 'confirmada'];
        $c = (new NotificacionCitaService())->construirContenidoHtml($tipo, $payload);
        $urls = self::enlaces($c['html'], $c['texto']);
        $this->assertNotEmpty($urls);
        foreach ($urls as $u) {
            if (str_starts_with($u, 'mailto:')) { continue; }
            $this->assertStringStartsWith(self::BASE, $u);
            $ruta = (string)parse_url($u, PHP_URL_PATH) ?: '/';
            $this->assertContains($ruta, ['/', '/cita', '/mis-citas'], "enlace inesperado en la notificación: $u");
            $this->assertStringNotContainsString('token=', $u);
        }
    }
}

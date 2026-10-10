<?php

namespace Tests\Unit;

use Classes\Email;
use Model\ActiveRecord;
use Model\NotificacionCita;
use PHPUnit\Framework\TestCase;
use Services\NotificacionCitaService;

class NotificacionCitaServiceTest extends TestCase
{
    private ?string $appUrlOriginal = null;
    private ?string $recordatorioHorasOriginal = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appUrlOriginal = $_ENV['APP_URL'] ?? null;
        $this->recordatorioHorasOriginal = $_ENV['NOTIFICACIONES_RECORDATORIO_HORAS'] ?? null;
        Email::setTransport(null);
        Email::limpiarEmailsEnviados();
    }

    protected function tearDown(): void
    {
        if ($this->appUrlOriginal === null) {
            unset($_ENV['APP_URL']);
        } else {
            $_ENV['APP_URL'] = $this->appUrlOriginal;
        }

        if ($this->recordatorioHorasOriginal === null) {
            unset($_ENV['NOTIFICACIONES_RECORDATORIO_HORAS']);
        } else {
            $_ENV['NOTIFICACIONES_RECORDATORIO_HORAS'] = $this->recordatorioHorasOriginal;
        }

        Email::setTransport(null);
        Email::limpiarEmailsEnviados();
        parent::tearDown();
    }

    public function testEntidadNotificacionCitaEstaDesacopladaDeActiveRecordYDecodificaPayload(): void
    {
        $this->assertFalse(
            is_subclass_of(NotificacionCita::class, ActiveRecord::class),
            'Model\\NotificacionCita no debe heredar de ActiveRecord'
        );

        $entidad = new NotificacionCita([
            'id' => '15',
            'cita_id' => '42',
            'cita_referencia_id' => '42',
            'usuario_id' => '7',
            'tipo' => NotificacionCita::TIPO_CONFIRMACION,
            'clave_idempotencia' => 'cita:42:confirmacion',
            'destinatario_email' => 'cliente@test.local',
            'destinatario_nombre' => 'Cliente Prueba',
            'asunto' => 'Confirmación #42',
            'payload_json' => ['cita_id' => 42, 'total_formateado' => '195.00'],
            'estado' => NotificacionCita::ESTADO_ENVIADO,
            'intentos' => '1',
            'max_intentos' => '3',
        ]);

        $this->assertSame(15, $entidad->id);
        $this->assertSame(42, $entidad->cita_id);
        $this->assertSame(42, $entidad->cita_referencia_id);
        $this->assertSame(7, $entidad->usuario_id);
        $this->assertSame('confirmacion', $entidad->tipo);
        $this->assertSame('cita:42:confirmacion', $entidad->clave_idempotencia);
        $this->assertSame(1, $entidad->intentos);
        $this->assertSame(3, $entidad->max_intentos);

        $payload = $entidad->obtenerPayload();
        $this->assertSame(42, $payload['cita_id']);
        $this->assertSame('195.00', $payload['total_formateado']);
    }

    public function testEmailResolverAppUrlRechazaEsquemasPeligrososYCaracteresDeControlConFallbackSeguro(): void
    {
        $this->assertSame('https://appsalon.ejemplo.com', Email::resolverAppUrl('https://appsalon.ejemplo.com/'));
        $this->assertSame('http://localhost:8080/app', Email::resolverAppUrl('http://localhost:8080/app/'));

        $peligrosas = [
            'javascript:alert(1)',
            'JAVASCRIPT:alert(document.cookie)',
            'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
            'vbscript:msgbox(1)',
            'ftp://archivos.ejemplo.com',
            'http://usuario:secreto@appsalon.com',
            "https://appsalon.com\r\nBcc: atacante@evil.com",
            'https://appsalon.com/"><script>alert(1)</script>',
            "https://appsalon.com/ruta'onclick='alert(1)",
            '   ',
            '/ruta-relativa',
        ];

        foreach ($peligrosas as $urlMaliciosa) {
            $this->assertSame(
                'http://localhost:3000',
                Email::resolverAppUrl($urlMaliciosa),
                "La URL insegura '{$urlMaliciosa}' debe caer al fallback seguro http://localhost:3000"
            );
        }
    }

    public function testEmailResolverRemitenteValidaEmailYLimpiaInyeccionDeCabecerasCrlf(): void
    {
        $remitenteValido = Email::resolverRemitente('notificaciones@appsalon.local', 'AppSalon Oficial');
        $this->assertSame('notificaciones@appsalon.local', $remitenteValido['email']);
        $this->assertSame('AppSalon Oficial', $remitenteValido['nombre']);

        $remitenteInyectado = Email::resolverRemitente(
            "invalido@@correo\r\nBcc: espia@evil.com",
            "AppSalon\r\nSubject: Inyeccion"
        );
        $this->assertSame('cuentas@appsalon.com', $remitenteInyectado['email']);
        $this->assertSame('AppSalon Subject: Inyeccion', $remitenteInyectado['nombre']);
        $this->assertStringNotContainsString("\r", $remitenteInyectado['nombre']);
        $this->assertStringNotContainsString("\n", $remitenteInyectado['nombre']);
    }

    public function testConstruirContenidoHtmlEscapaCaracteresEspecialesEnConfirmacionCancelacionYRecordatorio(): void
    {
        $_ENV['APP_URL'] = 'javascript:alert(document.domain)';
        $service = new NotificacionCitaService();

        $payload = [
            'cita_id' => 99,
            'fecha' => '2026-11-20',
            'hora_inicio' => '11:30',
            'hora_fin' => '13:00',
            'duracion_total_minutos' => 90,
            'cliente_nombre' => 'Carlos <script>alert("xss")</script> Mendoza',
            'profesional_nombre' => 'Valeria "La Mejor" <img src=x onerror=alert(1)>',
            'total_formateado' => '195.00',
            'cancelado_por' => 'admin',
            'servicios' => [
                [
                    'nombre' => 'Corte & Masaje <b>Premium</b>',
                    'precio' => '195.00',
                    'duracion_minutos' => 90,
                ]
            ],
        ];

        foreach ([
            NotificacionCita::TIPO_CONFIRMACION,
            NotificacionCita::TIPO_CANCELACION,
            NotificacionCita::TIPO_RECORDATORIO
        ] as $tipo) {
            $res = $service->construirContenidoHtml($tipo, $payload);

            $this->assertSame('http://localhost:3000/cita', $res['enlace']);
            $this->assertStringNotContainsString('javascript:', $res['html']);
            $this->assertStringNotContainsString('<script>', $res['html']);
            $this->assertStringNotContainsString('<img src=x', $res['html']);
            $this->assertStringContainsString('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $res['html']);
            $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $res['html']);
            $this->assertStringContainsString('Corte &amp; Masaje &lt;b&gt;Premium&lt;/b&gt; (90 min)', $res['html']);
            $this->assertStringContainsString('11:30 - 13:00 (90 min)', $res['html']);
            $this->assertStringContainsString('$195.00', $res['html']);
            $this->assertStringContainsString("href='http://localhost:3000/cita'", $res['html']);
        }
    }

    public function testClavesIdempotenciaVentanaRecordatorioConfigurableYBackoffEnAmericaGuayaquil(): void
    {
        $relojUtc = new \DateTimeImmutable('2026-11-15 10:00:00', new \DateTimeZone('UTC'));
        $service = new NotificacionCitaService(null, null, $relojUtc);

        // En America/Guayaquil (UTC-5) son las 05:00:00 del 2026-11-15
        $this->assertSame('2026-11-15 05:00:00', $service->obtenerAhora()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-15 10:00:00', $service->obtenerAhoraUtc()->format('Y-m-d H:i:s'));

        $this->assertSame('cita:12:confirmacion', NotificacionCitaService::generarClaveConfirmacion(12));
        $this->assertSame('cita:12:cancelacion', NotificacionCitaService::generarClaveCancelacion(12));
        $this->assertSame('cita:12:recordatorio:2026-11-16_11:30', NotificacionCitaService::generarClaveRecordatorio(12, '2026-11-16', '11:30:00'));

        // Ventana por defecto = 24h
        $this->assertSame(24, $service->resolverHorasAnticipacionRecordatorio());

        // Por variable de entorno
        $_ENV['NOTIFICACIONES_RECORDATORIO_HORAS'] = '48';
        $this->assertSame(48, $service->resolverHorasAnticipacionRecordatorio());

        // Por configuración de instancia y argumento explícito
        $service->setRecordatorioHoras(12);
        $this->assertSame(12, $service->resolverHorasAnticipacionRecordatorio());
        $this->assertSame(6, $service->resolverHorasAnticipacionRecordatorio(6));

        // Valores fuera de rango caen a 24
        $this->assertSame(24, $service->resolverHorasAnticipacionRecordatorio(0));
        $this->assertSame(24, $service->resolverHorasAnticipacionRecordatorio(-5));
        $this->assertSame(24, $service->resolverHorasAnticipacionRecordatorio(9999));

        // Backoff en UTC
        $this->assertSame('2026-11-15 10:05:00', $service->calcularProximoIntentoUtc(1, 3));
        $this->assertSame('2026-11-15 10:15:00', $service->calcularProximoIntentoUtc(2, 3));
        $this->assertNull($service->calcularProximoIntentoUtc(3, 3));
    }
}

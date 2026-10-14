<?php

namespace Tests\Integration;

use Classes\Entorno;
use Classes\Secretos;
use Classes\ServiceUnavailableException;
use PHPUnit\Framework\TestCase;
use Services\CorreoSalidaService;

/**
 * CORREOS_ENVIO_INMEDIATO es una variable SOLO de pruebas: únicamente vale cuando APP_ENV es EXACTAMENTE uno de
 * development|dev|local|testing|test (minúsculas, sin espacios). Una única función (Classes\Entorno) sirve también a Secretos.
 * Incluye HTTP real: con APP_ENV=production + CORREOS_ENVIO_INMEDIATO=1 el registro no envía en línea (solo cola).
 */
final class EnvioInmediatoEntornoTest extends TestCase
{
    private const SUF = '@envio.test';
    private array $respaldo = [];
    /** @var resource[] */
    private array $procesos = [];

    protected function setUp(): void
    {
        foreach (['APP_ENV', 'CORREOS_ENVIO_INMEDIATO', 'APP_KEY'] as $k) {
            $this->respaldo[$k] = [array_key_exists($k, $_ENV) ? $_ENV[$k] : null, getenv($k)];
        }
        $this->reiniciarAdvertencia();
    }

    protected function tearDown(): void
    {
        foreach ($this->respaldo as $k => [$env, $get]) {
            if ($env === null) { unset($_ENV[$k]); } else { $_ENV[$k] = $env; }
            if ($get === false) { putenv($k); } else { putenv("$k=$get"); }
        }
        foreach ($this->procesos as $p) { @proc_terminate($p); @proc_close($p); }
        $db = new \mysqli(getenv('DB_HOST'), getenv('DB_USER'), getenv('DB_PASS'), getenv('DB_NAME'), (int)getenv('DB_PORT'));
        $db->query("DELETE FROM correos_salida WHERE destinatario_email LIKE '%" . self::SUF . "'");
        $db->query("DELETE FROM usuarios WHERE email LIKE '%" . self::SUF . "'");
        $db->query("DELETE FROM operaciones_idempotentes WHERE scope='cliente.registro'");
        $db->query('DELETE FROM intentos_login');
    }

    private function entorno(?string $appEnv, ?string $envio): void
    {
        foreach (['APP_ENV' => $appEnv, 'CORREOS_ENVIO_INMEDIATO' => $envio] as $k => $v) {
            if ($v === null) { unset($_ENV[$k]); putenv($k); } else { $_ENV[$k] = $v; putenv("$k=$v"); }
        }
    }

    private function reiniciarAdvertencia(): void
    {
        $r = new \ReflectionProperty(CorreoSalidaService::class, 'advertidoEnvioInmediato');
        $r->setAccessible(true);
        $r->setValue(null, false);
    }

    /** @return array<string,array{0:?string,1:bool}> */
    public static function valoresDeAppEnv(): array
    {
        return [
            'testing' => ['testing', true], 'test' => ['test', true], 'development' => ['development', true], 'dev' => ['dev', true], 'local' => ['local', true],
            'production' => ['production', false], 'Production' => ['Production', false], 'prod' => ['prod', false], 'staging' => ['staging', false],
            'stage' => ['stage', false], 'qa' => ['qa', false], 'live' => ['live', false], 'preprod' => ['preprod', false],
            'vacío' => ['', false], 'no definido' => [null, false],
            'TESTING' => ['TESTING', false], 'testing con espacios' => [' testing ', false], 'Development' => ['Development', false], 'DEV' => ['DEV', false],
            'Local' => ['Local', false], 'Test con espacio' => ['Test ', false], 'testing con salto' => ["testing\n", false], 'test con tab' => ["test\t", false],
        ];
    }

    /** @dataProvider valoresDeAppEnv */
    public function test_el_envio_inmediato_solo_se_habilita_con_el_valor_exacto_de_un_entorno_de_pruebas(?string $appEnv, bool $esperado): void
    {
        $this->entorno($appEnv, '1');
        $this->assertSame($esperado, Entorno::esPruebasODesarrollo());
        $this->assertSame($esperado, CorreoSalidaService::envioInmediatoHabilitado(), 'APP_ENV=' . var_export($appEnv, true));
        // Sin la variable, jamás se envía en línea (ningún entorno).
        $this->entorno($appEnv, '0');
        $this->assertFalse(CorreoSalidaService::envioInmediatoHabilitado());
        $this->entorno($appEnv, null);
        $this->assertFalse(CorreoSalidaService::envioInmediatoHabilitado());
    }

    /** @dataProvider valoresDeAppEnv */
    public function test_la_derivacion_de_clave_de_desarrollo_usa_el_mismo_criterio_estricto(?string $appEnv, bool $esperado): void
    {
        $this->entorno($appEnv, null);
        $_ENV['APP_KEY'] = ''; putenv('APP_KEY=');
        if ($esperado) {
            $this->assertSame(32, strlen(Secretos::clave('prueba-entorno')));
        } else {
            $this->expectException(ServiceUnavailableException::class);
            Secretos::clave('prueba-entorno');
        }
    }

    public function test_un_valor_de_envio_inmediato_ignorado_deja_una_advertencia_una_sola_vez_por_proceso(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'envio_log_');
        $anterior = ini_set('error_log', $log);
        try {
            $this->entorno('production', '1');
            for ($i = 0; $i < 3; $i++) { $this->assertFalse(CorreoSalidaService::envioInmediatoHabilitado()); }
            $this->entorno('staging', 'true');
            $this->assertFalse(CorreoSalidaService::envioInmediatoHabilitado());
        } finally {
            ini_set('error_log', $anterior === false ? '' : $anterior);
        }
        $contenido = (string)file_get_contents($log);
        @unlink($log);
        $this->assertSame(1, substr_count($contenido, 'CORREOS_ENVIO_INMEDIATO ignorado: APP_ENV no es de pruebas/desarrollo'), $contenido);
        // En un entorno de pruebas no hay advertencia.
        $this->reiniciarAdvertencia();
        $log = tempnam(sys_get_temp_dir(), 'envio_log_');
        $anterior = ini_set('error_log', $log);
        $this->entorno('testing', '1');
        $this->assertTrue(CorreoSalidaService::envioInmediatoHabilitado());
        ini_set('error_log', $anterior === false ? '' : $anterior);
        $this->assertStringNotContainsString('ignorado', (string)file_get_contents($log));
        @unlink($log);
    }

    public function test_el_compose_y_el_ejemplo_de_entorno_no_activan_el_envio_inmediato(): void
    {
        $raiz = __DIR__ . '/../..';
        $this->assertStringNotContainsString('CORREOS_ENVIO_INMEDIATO', (string)file_get_contents($raiz . '/docker-compose.yml'));
        foreach (file($raiz . '/includes/.env.example', FILE_IGNORE_NEW_LINES) as $linea) {
            $this->assertDoesNotMatchRegularExpression('/^\s*CORREOS_ENVIO_INMEDIATO\s*=/', $linea, 'la variable solo puede aparecer comentada en el ejemplo');
        }
        $this->assertStringContainsString('CORREOS_ENVIO_INMEDIATO', (string)file_get_contents($raiz . '/includes/.env.example'), 'queda documentada como variable de pruebas');
    }

    // ------------------------------------------------------------------ HTTP real

    private function servidor(int $puerto, array $env): string
    {
        $smtp = $puerto + 2610 - 8111 + 0;   // 8111 → 2610, 8112 → 2611
        $log = sys_get_temp_dir() . "/envio_web_$puerto.log";
        $this->procesos[] = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', '-d', 'error_log=' . $log, '-S', "127.0.0.1:$puerto", '-t', __DIR__ . '/../../public', __DIR__ . '/../fixtures/router_http.php'],
            [1 => ['file', $log . '.out', 'a'], 2 => ['file', $log . '.err', 'a']], $p, __DIR__ . '/../..',
            array_merge(getenv(), ['EMAIL_HOST' => '127.0.0.1', 'EMAIL_PORT' => (string)$smtp, 'EMAIL_USER' => 't', 'EMAIL_PASSWORD' => 't', 'EMAIL_FORCE_SMTP' => '1'], $env));
        for ($i = 0; $i < 60; $i++) {
            $c = @fsockopen('127.0.0.1', $puerto, $en, $es, 0.2);
            if ($c) { fclose($c); return "http://127.0.0.1:$puerto"; }
            usleep(100000);
        }
        $this->fail('El servidor de prueba no arrancó');
    }

    private function smtp(int $puerto, string $buzon): void
    {
        file_put_contents($buzon, '');
        $this->procesos[] = proc_open([PHP_BINARY, __DIR__ . '/../fixtures/cola_smtp.php', (string)$puerto, $buzon], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p);
        for ($i = 0; $i < 60; $i++) {
            $c = @fsockopen('127.0.0.1', $puerto, $en, $es, 0.2);
            if ($c) { fclose($c); return; }
            usleep(100000);
        }
        $this->fail('El SMTP simulado no arrancó');
    }

    private function registrarPorHttp(string $base, string $email): int
    {
        $jar = tempnam(sys_get_temp_dir(), 'envio_jar_');
        $pedir = function (string $metodo, string $ruta, ?array $campos = null) use ($base, $jar): array {
            $ch = curl_init($base . $ruta);
            curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60]);
            if ($campos !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($campos)); }
            $b = (string)curl_exec($ch); $s = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
            return ['s' => $s, 'b' => $b];
        };
        $campo = fn(string $h, string $n): string => preg_match('/name="' . $n . '" value="([^"]*)"/', $h, $m) ? $m[1] : '';
        $pg = $pedir('GET', '/cliente/registro');
        $r = $pedir('POST', '/cliente/registro', ['csrf_token' => $campo($pg['b'], 'csrf_token'), 'idem_key' => $campo($pg['b'], 'idem_key'), 'nombre' => 'En', 'apellido' => 'Vio', 'telefono' => '0991112222', 'email' => $email, 'password' => 'Abcdef123456']);
        @unlink($jar);
        return $r['s'];
    }

    public function test_http_real_en_production_con_la_variable_activada_el_registro_solo_encola(): void
    {
        $buzon = sys_get_temp_dir() . '/envio_buzon_2610.jsonl';
        $this->smtp(2610, $buzon);
        $base = $this->servidor(8111, ['APP_ENV' => 'production', 'APP_KEY' => bin2hex(random_bytes(32)), 'CORREOS_ENVIO_INMEDIATO' => '1', 'PAYMENT_GATEWAY_PROVIDER' => 'mock']);
        $email = 'prod.' . bin2hex(random_bytes(3)) . self::SUF;
        $this->assertSame(302, $this->registrarPorHttp($base, $email));
        $this->assertSame('', trim((string)file_get_contents($buzon)), 'en producción nada sale dentro de la petición aunque CORREOS_ENVIO_INMEDIATO=1');
        $db = new \mysqli(getenv('DB_HOST'), getenv('DB_USER'), getenv('DB_PASS'), getenv('DB_NAME'), (int)getenv('DB_PORT'));
        $fila = $db->query("SELECT estado, intentos FROM correos_salida WHERE destinatario_email='$email'")->fetch_assoc();
        $this->assertSame(['estado' => 'pendiente', 'intentos' => '0'], $fila, 'queda en la cola para el worker');
        $this->assertStringContainsString('CORREOS_ENVIO_INMEDIATO ignorado', (string)@file_get_contents(sys_get_temp_dir() . '/envio_web_8111.log'));
        @unlink($buzon);
    }

    public function test_http_real_en_testing_con_la_variable_activada_si_hay_envio_en_linea(): void
    {
        $buzon = sys_get_temp_dir() . '/envio_buzon_2611.jsonl';
        $this->smtp(2611, $buzon);
        $base = $this->servidor(8112, ['APP_ENV' => 'testing', 'CORREOS_ENVIO_INMEDIATO' => '1']);
        $email = 'test.' . bin2hex(random_bytes(3)) . self::SUF;
        $this->assertSame(302, $this->registrarPorHttp($base, $email));
        $this->assertStringContainsString($email, (string)file_get_contents($buzon), 'en testing el envío en línea sí ocurre');
        $db = new \mysqli(getenv('DB_HOST'), getenv('DB_USER'), getenv('DB_PASS'), getenv('DB_NAME'), (int)getenv('DB_PORT'));
        $this->assertSame('enviado', $db->query("SELECT estado FROM correos_salida WHERE destinatario_email='$email'")->fetch_assoc()['estado']);
        @unlink($buzon);
    }
}

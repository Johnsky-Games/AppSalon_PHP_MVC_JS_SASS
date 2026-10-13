<?php

namespace Tests\Qa;

require_once __DIR__ . '/QaCase.php';

/** G. Estados vacíos y fallos: SMTP caído/colgado, APP_KEY ausente en producción, base sin migrar o caída, claves y cargas hostiles. */
final class FallosTest extends QaCase
{
    private static string $base;
    private static array $dueno;
    private static Navegador $owner;

    public static function setUpBeforeClass(): void
    {
        self::limpiar();
        self::iniciarSmtp(2597);
        self::$base = self::servidor(); // SMTP en 2599: cerrado
        self::$dueno = self::propietario('dueno@example.test');
        self::$owner = new Navegador(self::$base);
        self::$owner->login('equipo', self::$dueno['email'], self::$dueno['password']);
    }

    public static function tearDownAfterClass(): void { self::pararServidor(); self::detenerSmtp(2597); foreach (['prod', 'sinmigrar', 'caida'] as $k) { self::pararServidor($k); } }

    protected function setUp(): void { self::q('TRUNCATE intentos_login'); }

    private function invitacion(string $email, array $extra = []): array
    {
        return $extra + ['nombre' => 'Ana', 'apellido' => 'Gómez', 'email' => $email, 'telefono' => '', 'puesto' => 'Caja', 'rol' => 'recepcion', 'fecha_ingreso' => '', 'idem_key' => Idem::clave()];
    }

    private function csrfDe(Navegador $n, string $ruta = '/admin/equipo'): string
    {
        return Navegador::csrfDe($n->get($ruta)['body']) ?? throw new \RuntimeException('sin csrf');
    }

    public function test_smtp_caido_en_invitacion_deja_evento_reintentable_y_la_ui_no_finge_exito(): void
    {
        $email = self::correo('smtpcaido');
        $csrf = $this->csrfDe(self::$owner);
        $r = self::$owner->post('/admin/equipo/crear', $this->invitacion($email) + ['csrf_token' => $csrf]);
        $this->assertSame(302, $r['status']);
        $pagina = self::$owner->get('/admin/equipo');
        $texto = html_entity_decode(strip_tags($pagina['body']));
        $this->assertStringNotContainsString('Invitación enviada.', $texto, 'la UI afirma envío con el SMTP caído');
        $this->assertTrue(str_contains($texto, 'quedó guardada') || str_contains($texto, 'en cola') || str_contains($texto, 'no pudo enviarse'), 'mensaje no informa del estado real: ' . substr($texto, 0, 600));
        $ev = self::q("SELECT * FROM correos_salida WHERE destinatario_email=?", [$email])[0];
        $this->assertContains($ev['estado'], ['pendiente', 'fallido']);
        $this->assertSame(1, (int)$ev['intentos']);
        $this->assertNotNull($ev['carga_cifrada']);
        $this->assertNull($ev['worker_token']);
        $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM colaborador_invitaciones i JOIN colaboradores c ON c.id=i.colaborador_id WHERE c.email_norm=?', [$email]));
        // El listado marca el correo como pendiente/no enviado, nunca "enviado".
        $this->assertStringNotContainsString('Correo enviado', $pagina['body']);
        // Recuperación: SMTP vuelve; el reintento vence y se entrega EXACTAMENTE un correo.
        $srv = new \Services\CorreoSalidaService(new \Repositories\CorreoSalidaRepository(self::db()));
        self::q("UPDATE correos_salida SET proximo_intento=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE id=?", [$ev['id']]);
        $port = $_ENV['EMAIL_PORT'] ?? null;
        $_ENV['EMAIL_PORT'] = '2597'; putenv('EMAIL_PORT=2597'); $_ENV['EMAIL_HOST'] = '127.0.0.1'; $_ENV['EMAIL_USER'] = 't'; $_ENV['EMAIL_PASSWORD'] = 't'; putenv('EMAIL_FORCE_SMTP=1');
        try { $this->assertSame(1, $srv->procesarPendientes(5)); } finally { putenv('EMAIL_PORT=2599'); if ($port !== null) { $_ENV['EMAIL_PORT'] = $port; } }
        $ev = self::q("SELECT * FROM correos_salida WHERE id=?", [$ev['id']])[0];
        $this->assertSame('enviado', $ev['estado']);
        $this->assertNull($ev['carga_cifrada'], 'la carga con el token debe borrarse tras el envío');
        usleep(200000);
        $this->assertCount(1, array_filter(self::buzon(2597), fn($m) => in_array($email, $m['to'], true)));
        $this->assertSame(0, $srv->procesarPendientes(5), 'un evento enviado no se reenvía');
    }

    public function test_Defecto_listado_del_equipo_se_bloquea_hasta_15_s_con_smtp_colgado(): void
    {
        // Servidor que acepta la conexión pero nunca saluda (caso típico de firewall/blackhole parcial).
        $colgado = stream_socket_server('tcp://127.0.0.1:0');
        $puerto = (int)explode(':', stream_socket_get_name($colgado, false))[1];
        $csrf = $this->csrfDe(self::$owner);
        foreach ([1, 2, 3] as $i) {
            $this->assertSame(302, self::$owner->post('/admin/equipo/crear', $this->invitacion(self::correo('colgado'), ['nombre' => "C$i"]) + ['csrf_token' => $csrf])['status']);
        }
        self::q("UPDATE correos_salida SET proximo_intento=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE estado IN ('pendiente','fallido')");
        $srv = self::servidor(['EMAIL_PORT' => (string)$puerto], 'colgado');
        $n = new Navegador($srv);
        $n->timeout = 25;
        $n->login('equipo', self::$dueno['email'], self::$dueno['password']);
        self::q("UPDATE correos_salida SET proximo_intento=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE estado IN ('pendiente','fallido')");
        $t = microtime(true);
        $r = $n->get('/admin/equipo');
        $dur = microtime(true) - $t;
        self::pararServidor('colgado');
        fclose($colgado);
        $this->assertLessThan(8.0, $dur, sprintf('GET /admin/equipo no respondió en %.1f s (HTTP %d): EquipoController::index envía hasta 3 correos de forma síncrona dentro de una petición de lectura y PHPMailer espera el saludo SMTP hasta Timelimit=300 s (Email fija solo Timeout=5)', $dur, $r['status']));
        $this->assertSame(200, $r['status']);
    }

    public static function camposNoEscalares(): array
    {
        return [
            'registro nombre[]' => ['/cliente/registro', 'nombre'], 'registro email[]' => ['/cliente/registro', 'email'], 'registro password[]' => ['/cliente/registro', 'password'],
            'registro telefono[]' => ['/cliente/registro', 'telefono'], 'registro idem_key[]' => ['/cliente/registro', 'idem_key'],
            'cliente login email[]' => ['/cliente/login', 'email'], 'cliente login password[]' => ['/cliente/login', 'password'],
            'equipo login email[]' => ['/equipo/login', 'email'], 'equipo login password[]' => ['/equipo/login', 'password'],
        ];
    }

    /** @dataProvider camposNoEscalares */
    public function test_publicos_con_campos_arreglo_dan_4xx_sin_500_ni_advertencias(string $ruta, string $campo): void
    {
        $n = new Navegador(self::$base);
        $csrf = $n->csrf($ruta);
        $cuerpo = ['nombre' => 'A', 'apellido' => 'B', 'email' => self::correo('arr'), 'telefono' => '0991112222', 'password' => 'Abcdef123456', 'idem_key' => Idem::clave()];
        $crudo = http_build_query(['csrf_token' => $csrf] + array_diff_key($cuerpo, [$campo => 1])) . '&' . rawurlencode($campo) . '[]=x&' . rawurlencode($campo) . '[k]=y';
        $antes = strlen(self::logServidor());
        $r = $n->post($ruta, $crudo);
        $this->assertContains($r['status'], [200, 401, 422], "$ruta $campo => {$r['status']}");
        $nuevo = substr(self::logServidor(), $antes);
        $this->assertDoesNotMatchRegularExpression('/Warning|Notice|Deprecated|Array to string|TypeError|Fatal/i', $nuevo, "$ruta $campo: $nuevo");
    }

    public static function camposEquipo(): array
    {
        $c = ['nombre', 'apellido', 'email', 'telefono', 'puesto', 'rol', 'fecha_ingreso', 'profesional_id', 'nombre_profesional', 'confirmar_homonimo', 'idem_key'];
        $r = [];
        foreach ($c as $f) { if (in_array($f, ['nombre', 'apellido', 'email'], true)) { continue; } $r["crear {$f}[]"] = ['/admin/equipo/crear', $f]; }
        foreach (['id', 'rol', 'estado'] as $f) { $r["actualizar {$f}[]"] = ['/admin/equipo/actualizar', $f]; }
        foreach (['id', 'idem_key'] as $f) { $r["invitar {$f}[]"] = ['/admin/equipo/invitar', $f]; }
        $r['revocar id[]'] = ['/admin/equipo/revocar', 'id'];
        foreach (['puesto', 'notas_rh', 'telefono', 'fecha_ingreso'] as $f) { $r["ficha {$f}[]"] = ['/admin/equipo/ficha?id=1', $f]; }
        return $r;
    }

    /** @dataProvider camposEquipo */
    public function test_mutaciones_de_equipo_con_campos_arreglo_dan_4xx_o_redireccion_sin_advertencias(string $ruta, string $campo): void
    {
        // Existe al menos una ficha para que ?id=1 sea válido en /ficha.
        if ((int)self::escalar('SELECT COUNT(*) FROM colaboradores WHERE id=1') === 0) { $this->markTestSkipped('sin ficha 1'); }
        $csrf = $this->csrfDe(self::$owner);
        $base = ['nombre' => 'A', 'apellido' => 'B', 'email' => self::correo('arr2'), 'telefono' => '', 'puesto' => '', 'rol' => 'recepcion', 'fecha_ingreso' => '', 'id' => '1', 'estado' => 'activo', 'notas_rh' => '', 'idem_key' => Idem::clave()];
        $crudo = http_build_query(['csrf_token' => $csrf] + array_diff_key($base, [$campo => 1])) . '&' . rawurlencode($campo) . '[]=x&' . rawurlencode($campo) . '[k][z]=y';
        $antes = strlen(self::logServidor());
        $r = self::$owner->post($ruta, $crudo);
        $this->assertContains($r['status'], [302, 403, 404, 422], "$ruta $campo => {$r['status']}");
        if ($r['status'] === 302) { $r2 = self::$owner->get($r['location']); $this->assertContains($r2['status'], [200], "seguir redirección de $ruta $campo => {$r2['status']}"); }
        $nuevo = substr(self::logServidor(), $antes);
        $this->assertDoesNotMatchRegularExpression('/Warning|Notice|Deprecated|Array to string|TypeError|Fatal/i', $nuevo, "$ruta $campo: $nuevo");
    }

    public static function camposConAdvertencia(): array { return [['nombre'], ['apellido'], ['email']]; }

    /** @dataProvider camposConAdvertencia */
    public function test_Defecto_invitar_con_campo_arreglo_emite_advertencia_al_reproducir_el_formulario(string $campo): void
    {
        $csrf = $this->csrfDe(self::$owner);
        $base = ['nombre' => 'A', 'apellido' => 'B', 'email' => self::correo('arr3'), 'telefono' => '', 'puesto' => '', 'rol' => 'recepcion', 'fecha_ingreso' => '', 'idem_key' => Idem::clave()];
        $crudo = http_build_query(['csrf_token' => $csrf] + array_diff_key($base, [$campo => 1])) . '&' . rawurlencode($campo) . '[]=x';
        $antes = strlen(self::logServidor());
        $r = self::$owner->post('/admin/equipo/crear', $crudo);
        $this->assertSame(302, $r['status']);
        $this->assertSame(200, self::$owner->get('/admin/equipo')['status']);
        $this->assertDoesNotMatchRegularExpression('/Warning|Array to string/i', substr(self::logServidor(), $antes), 'EquipoController guarda $_POST completo en equipo_previo y la vista lo imprime con s() sin comprobar el tipo');
    }

    public function test_invitar_con_servicios_no_escalares_o_inexistentes_da_error_controlado(): void
    {
        $csrf = $this->csrfDe(self::$owner);
        $antes = strlen(self::logServidor());
        foreach (['servicios=abc', 'servicios[]=abc', 'servicios[][a]=1', 'servicios[]=999999', 'servicios[]=-1', 'servicios=1,2'] as $sv) {
            $crudo = http_build_query(['csrf_token' => $csrf, 'idem_key' => Idem::clave(), 'nombre' => 'P', 'apellido' => 'Q', 'email' => self::correo('prof'), 'rol' => 'profesional', 'puesto' => '', 'telefono' => '', 'fecha_ingreso' => '']) . '&' . $sv;
            $r = self::$owner->post('/admin/equipo/crear', $crudo);
            $this->assertContains($r['status'], [302], $sv);
            $this->assertContains(self::$owner->get('/admin/equipo')['status'], [200]);
        }
        $this->assertSame(0, (int)self::escalar("SELECT COUNT(*) FROM profesionales WHERE colaborador_id IS NOT NULL AND nombre LIKE 'P Q'"), 'no debe crearse ficha con servicios inválidos');
        $this->assertDoesNotMatchRegularExpression('/Warning|Notice|Array to string|TypeError/i', substr(self::logServidor(), $antes));
    }

    public static function clavesInvalidas(): array
    {
        return [['ausente', null], ['vacía', ''], ['corta', str_repeat('a', 21)], ['larga', str_repeat('a', 65)], ['caracteres ilegales', str_repeat('a', 30) . '!;'], ['con espacios', str_repeat('a', 30) . ' x'], ['unicode', str_repeat('é', 30)]];
    }

    /** @dataProvider clavesInvalidas */
    public function test_idem_key_ausente_o_invalida_nunca_crea_altas(string $nombre, ?string $clave): void
    {
        $antes = [self::escalar('SELECT COUNT(*) FROM usuarios'), self::escalar('SELECT COUNT(*) FROM colaboradores'), self::escalar('SELECT COUNT(*) FROM profesionales')];
        // 1) registro público
        $n = new Navegador(self::$base);
        $csrf = $n->csrf('/cliente/registro');
        $c = ['csrf_token' => $csrf, 'nombre' => 'A', 'apellido' => 'B', 'email' => self::correo('k'), 'telefono' => '0991112222', 'password' => 'Abcdef123456'] + ($clave === null ? [] : ['idem_key' => $clave]);
        $r = $n->post('/cliente/registro', $c);
        $this->assertSame(422, $r['status'], "registro [$nombre]");
        $this->assertStringContainsString('venció', $r['body']);
        // 2) invitación
        $csrf = $this->csrfDe(self::$owner);
        $r = self::$owner->post('/admin/equipo/crear', ['csrf_token' => $csrf, 'nombre' => 'A', 'apellido' => 'B', 'email' => self::correo('k2'), 'rol' => 'recepcion', 'puesto' => '', 'telefono' => '', 'fecha_ingreso' => ''] + ($clave === null ? [] : ['idem_key' => $clave]));
        $this->assertSame(302, $r['status']);
        $this->assertStringContainsString('venció', html_entity_decode(self::$owner->get('/admin/equipo')['body']), "invitar [$nombre]");
        // 3) profesional
        $r = self::$owner->post('/profesionales/crear', ['csrf_token' => $csrf, 'nombre' => 'ProfK', 'activo' => '1'] + ($clave === null ? [] : ['idem_key' => $clave]));
        $this->assertContains($r['status'], [200, 422], "profesional [$nombre]");
        $this->assertSame($antes, [self::escalar('SELECT COUNT(*) FROM usuarios'), self::escalar('SELECT COUNT(*) FROM colaboradores'), self::escalar('SELECT COUNT(*) FROM profesionales')], "[$nombre] creó filas con clave inválida");
        $this->assertSame(0, (int)self::escalar("SELECT COUNT(*) FROM operaciones_idempotentes WHERE clave_hash=?", [hash('sha256', (string)$clave)]));
    }

    public function test_reenvio_con_idem_key_ausente_no_envia_correo(): void
    {
        $email = self::correo('reen0');
        $csrf = $this->csrfDe(self::$owner);
        self::$owner->post('/admin/equipo/crear', $this->invitacion($email) + ['csrf_token' => $csrf]);
        $id = (int)self::escalar('SELECT id FROM colaboradores WHERE email_norm=?', [$email]);
        self::q('UPDATE colaborador_invitaciones SET creada_en=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE colaborador_id=?', [$id]);
        $antes = (int)self::escalar('SELECT COUNT(*) FROM colaborador_invitaciones WHERE colaborador_id=?', [$id]);
        $r = self::$owner->post('/admin/equipo/invitar', ['csrf_token' => $csrf, 'id' => $id]);
        $this->assertSame(302, $r['status']);
        $this->assertSame($antes, (int)self::escalar('SELECT COUNT(*) FROM colaborador_invitaciones WHERE colaborador_id=?', [$id]));
    }

    public function test_misma_idem_key_con_otro_contenido_da_409_y_misma_con_mismo_contenido_repite(): void
    {
        $email = self::correo('idem409');
        $csrf = $this->csrfDe(self::$owner);
        $clave = Idem::clave();
        $a = ['csrf_token' => $csrf, 'idem_key' => $clave, 'nombre' => 'Ana', 'apellido' => 'Gómez', 'email' => $email, 'rol' => 'recepcion', 'puesto' => '', 'telefono' => '', 'fecha_ingreso' => ''];
        self::$owner->post('/admin/equipo/crear', $a);
        $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM colaboradores WHERE email_norm=?', [$email]));
        self::$owner->post('/admin/equipo/crear', $a);
        $this->assertStringContainsString('ya estaba registrada', html_entity_decode(self::$owner->get('/admin/equipo')['body']));
        self::$owner->post('/admin/equipo/crear', ['nombre' => 'Otra', 'email' => self::correo('idem409b')] + $a);
        $this->assertStringContainsString('otros datos', html_entity_decode(self::$owner->get('/admin/equipo')['body']));
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM correos_salida WHERE tipo='invitacion_colaborador' AND destinatario_email=?", [$email]));
    }

    public function test_produccion_sin_app_key_responde_503_y_no_crea_datos(): void
    {
        $srv = self::servidor(['APP_ENV' => 'production', 'APP_KEY' => '', 'PAYMENT_GATEWAY_PROVIDER' => 'mock'], 'prod');
        $n = new Navegador($srv);
        $this->assertSame(200, $n->get('/cliente/registro')['status'], 'la página de registro sí debe mostrarse');
        $csrf = Navegador::csrfDe($n->get('/cliente/registro')['body']);
        $email = self::correo('prod');
        $r = $n->post('/cliente/registro', ['csrf_token' => $csrf, 'idem_key' => Idem::clave(), 'nombre' => 'A', 'apellido' => 'B', 'email' => $email, 'telefono' => '0991112222', 'password' => 'Abcdef123456']);
        $this->assertSame(503, $r['status'], substr(strip_tags($r['body']), 0, 300));
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM usuarios WHERE email=?', [$email]));
        $this->assertStringNotContainsString('APP_KEY', $r['body'], 'la respuesta HTML no debe filtrar el nombre del secreto');
        $this->assertSame('60', $r['headers']['retry-after'][0] ?? null);
        // Instalador: aborta con instrucciones, sin tocar la base.
        $p = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', self::RAIZ . '/scripts/instalar.php', '--comprobar'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::RAIZ,
            array_merge(getenv(), ['DB_NAME' => self::nombreBd(), 'APP_ENV' => 'production', 'APP_KEY' => '']));
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $this->assertSame(1, proc_close($p), $out);
        $this->assertStringContainsString('APP_KEY', $out);
    }

    public function test_produccion_con_app_key_valida_permite_el_alta(): void
    {
        $srv = self::servidor(['APP_ENV' => 'production', 'APP_KEY' => bin2hex(random_bytes(32))], 'prod2');
        $n = new Navegador($srv);
        $csrf = $n->csrf('/cliente/registro');
        $email = self::correo('prod2');
        $r = $n->post('/cliente/registro', ['csrf_token' => $csrf, 'idem_key' => Idem::clave(), 'nombre' => 'A', 'apellido' => 'B', 'email' => $email, 'telefono' => '0991112222', 'password' => 'Abcdef123456']);
        $this->assertSame(302, $r['status'], substr(strip_tags($r['body']), 0, 300));
        self::pararServidor('prod2');
    }

    public function test_Defecto_clave_derivada_de_desarrollo_se_usa_si_APP_ENV_no_es_production(): void
    {
        // Fail-open por configuración: con APP_ENV vacío/"staging"/"Production " (mayúsculas con espacio) el servidor arranca con una clave
        // derivada de DB_NAME y DB_PASS. Cualquiera que conozca esos dos valores puede descifrar las cargas pendientes (tokens de invitación) y
        // calcular las huellas de idempotencia.
        $fallos = [];
        foreach (['', 'staging', 'Production ', 'prod-eu', 'live'] as $entorno) {
            $p = proc_open([PHP_BINARY, '-r', 'require "' . self::RAIZ . '/vendor/autoload.php"; try { echo bin2hex(Classes\Secretos::clave("carga-correo")); } catch (Throwable $e) { echo "ERROR"; }'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::RAIZ, array_merge(getenv(), ['APP_ENV' => $entorno, 'APP_KEY' => '', 'DB_NAME' => 'x_test', 'DB_PASS' => 'p']));
            $salida = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); proc_close($p);
            if ($salida !== 'ERROR') { $fallos[] = "APP_ENV='$entorno' => clave derivada predecible"; }
        }
        $this->assertSame([], $fallos, 'Solo "production"/"prod"/"produccion" exigen APP_KEY; cualquier otro valor degrada a una clave predecible');
    }

    public function test_base_sin_migrar_da_503_coherente_en_html_y_json(): void
    {
        $nombre = 'appsalon_qa_sinmigrar_test';
        self::crearBd($nombre, 9);
        $srv = self::servidor(['DB_NAME' => $nombre], 'sinmigrar');
        // Sesión de cliente forjada contra un esquema sin 010+: autorización imposible => 503, jamás "cuenta inactiva"/403/200.
        $sid = self::forjarSesion(['login' => true, 'id' => 1, 'rol' => 'propietario', 'admin' => '1', 'portal' => 'equipo', 'version_sesion' => 0], 'sinmigrar');
        $h = new Navegador($srv);
        $r = $h->get('/admin', ["Cookie: PHPSESSID=$sid"]);
        $this->assertSame(503, $r['status'], 'HTML: ' . $r['status'] . substr(strip_tags($r['body']), 0, 200));
        $this->assertStringContainsString('text/html', $r['headers']['content-type'][0] ?? '');
        $j = $h->get('/api/mis-citas', ["Cookie: PHPSESSID=$sid", 'Accept: application/json']);
        $this->assertSame(503, $j['status'], 'JSON: ' . $j['body']);
        $this->assertStringContainsString('application/json', $j['headers']['content-type'][0] ?? '');
        $this->assertIsArray(json_decode($j['body'], true));
        $this->assertStringNotContainsString('SQL', $r['body'] . $j['body']);
        $this->assertStringNotContainsString('usuario_accesos', $r['body'] . $j['body']);
        // Login (credenciales válidas) contra esquema sin migrar: 503, no "credenciales incorrectas".
        $db = new \mysqli('127.0.0.1', 'root', 'root', $nombre);
        $db->query("INSERT INTO usuarios(nombre,apellido,email,password,telefono,admin,confirmado) VALUES ('A','B','u@example.test','" . password_hash('Clave-123456', PASSWORD_BCRYPT) . "','0991112222',0,1)");
        $n = new Navegador($srv);
        $r = $n->login('cliente', 'u@example.test', 'Clave-123456');
        $this->assertSame(503, $r['status'], 'login sin migrar => ' . $r['status'] . ' ' . substr(strip_tags($r['body']), 0, 200));
    }

    public function test_base_caida_da_503_coherente_en_html_y_json(): void
    {
        $srv = self::servidor(['DB_PORT' => '1', 'DB_HOST' => '127.0.0.1'], 'caida');
        $n = new Navegador($srv);
        $r = $n->get('/cliente/login');
        $this->assertSame(503, $r['status']);
        $this->assertStringContainsString('text/html', $r['headers']['content-type'][0] ?? '');
        $j = $n->get('/api/mis-citas', ['Accept: application/json']);
        $this->assertSame(503, $j['status']);
        $this->assertStringContainsString('application/json', $j['headers']['content-type'][0] ?? '');
        $this->assertStringNotContainsString('mysqli', $r['body'] . $j['body']);
        $this->assertStringNotContainsString('root', $r['body'] . $j['body']);
    }
}

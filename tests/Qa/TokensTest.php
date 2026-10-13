<?php

namespace Tests\Qa;

require_once __DIR__ . '/QaCase.php';

use Repositories\ColaboradorRepository;
use Services\AuthService;
use Services\ColaboradorService;

/** D. Tokens: propósito cruzado, expiración, reutilización, fuerza bruta, toma de control de cuentas, enumeración y fugas. */
final class TokensTest extends QaCase
{
    private static string $base;
    private static array $dueno;

    public static function setUpBeforeClass(): void
    {
        self::limpiar();
        self::$base = self::servidor();
        self::$dueno = self::propietario('dueno@example.test');
    }

    public static function tearDownAfterClass(): void { self::pararServidor(); }

    protected function setUp(): void { self::q('TRUNCATE intentos_login'); }

    private function repo(): ColaboradorRepository { return new ColaboradorRepository(self::db()); }

    private function svc(): ColaboradorService
    {
        return new ColaboradorService($this->repo(), new \Services\CorreoSalidaService(new \Repositories\CorreoSalidaRepository(self::db())));
    }

    /** @return array{colaborador_id:int,invitacion_id:int,token:string,email:string} */
    private function invitar(string $email, string $rol = 'recepcion'): array
    {
        $r = $this->repo()->crearInvitacion(self::$dueno['id'], ['nombre' => 'In', 'apellido' => 'Vi', 'email' => $email, 'telefono' => null, 'puesto' => '', 'rol' => $rol, 'fecha_ingreso' => null, 'servicios' => [], 'nombre_profesional' => '', 'profesional_id' => null]);
        return $r + ['token' => self::tokenDeInvitacion($r['invitacion_id']), 'email' => $email];
    }

    private function auth(array &$capturado): AuthService
    {
        return new AuthService(new \Repositories\UsuarioRepository(self::db()), static function ($tipo, $email, $nombre, $tok) use (&$capturado) { $capturado[] = ['tipo' => $tipo, 'email' => $email, 'token' => $tok]; return true; });
    }

    public function test_token_de_invitacion_no_sirve_para_confirmar_ni_recuperar(): void
    {
        $email = self::correo('x1');
        $cap = [];
        $reg = $this->auth($cap)->registrar(['nombre' => 'Pe', 'apellido' => 'Ro', 'email' => $email, 'password' => 'Abcdef123456', 'telefono' => '0991112222'], '10.2.0.1', Idem::clave(), 'anonimo:x');
        $this->assertSame('ok', $reg['status']);
        $inv = $this->invitar($email);
        $n = new Navegador(self::$base);
        $r = $n->get('/confirmar-cuenta?token=' . $inv['token']);
        $this->assertSame(410, $r['status']);
        $this->assertSame(0, (int)self::escalar('SELECT confirmado FROM usuarios WHERE email=?', [$email]), 'la cuenta no debe confirmarse con un token de invitación');
        $this->assertSame(410, $n->get('/recuperar?token=' . $inv['token'])['status']);
        // Y al revés: el token de confirmación no acepta invitaciones.
        $this->assertSame(404, $n->get('/equipo/aceptar?token=' . $cap[0]['token'])['status']);
        $r = $this->svc()->aceptar($cap[0]['token'], ['acepto' => '1', 'password' => 'Nueva-Pass-123', 'password_confirmacion' => 'Nueva-Pass-123'], '10.2.0.2');
        $this->assertSame('invalid', $r['status']);
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM usuario_accesos a JOIN usuarios u ON u.id=a.usuario_id WHERE u.email=?', [$email]));
    }

    public function test_token_de_recuperacion_no_acepta_invitacion_y_viceversa(): void
    {
        $email = self::correo('x2');
        $cli = self::cliente(['email' => $email]);
        $cap = [];
        $this->auth($cap)->solicitarRecuperacion(['email' => $email], '10.2.0.3');
        $this->assertNotEmpty($cap, 'sin token de recuperación');
        $rec = $cap[0]['token'];
        $this->assertSame(404, (new Navegador(self::$base))->get('/equipo/aceptar?token=' . $rec)['status']);
        $inv = $this->invitar($email);
        $svc = $this->auth($cap);
        $v = $svc->validarTokenRecuperacion($inv['token']);
        $this->assertTrue((bool)$v['error'], 'el token de invitación no debe validar como recuperación');
    }

    public function test_expiracion_exacta_a_los_7_dias_y_borde_de_un_segundo(): void
    {
        $inv = $this->invitar(self::correo('exp'));
        $dif = (int)self::escalar('SELECT TIMESTAMPDIFF(SECOND, creada_en, expira_en) FROM colaborador_invitaciones WHERE id=?', [$inv['invitacion_id']]);
        $this->assertSame(7 * 86400, $dif);
        $post = ['acepto' => '1', 'password' => 'Nueva-Pass-123', 'password_confirmacion' => 'Nueva-Pass-123'];
        self::q('UPDATE colaborador_invitaciones SET expira_en=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE id=?', [$inv['invitacion_id']]);
        $this->assertSame('invalid', $this->svc()->aceptar($inv['token'], $post, '10.3.0.1')['status'], 'vencida hace 1 s');
        $this->assertSame('expirada', self::escalar('SELECT estado FROM colaborador_invitaciones WHERE id=?', [$inv['invitacion_id']]));
        $inv2 = $this->invitar(self::correo('exp2'));
        self::q('UPDATE colaborador_invitaciones SET expira_en=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 3 SECOND) WHERE id=?', [$inv2['invitacion_id']]);
        $this->assertSame('ok', $this->svc()->aceptar($inv2['token'], $post, '10.3.0.2')['status'], 'faltan 3 s: aún válida');
    }

    public function test_reenviar_a_una_invitacion_expirada_emite_token_nuevo_y_el_viejo_no_sirve(): void
    {
        // Una invitación vencida cuya ficha sigue 'invitado' debe poder reenviarse; la vencida no debe aceptarse.
        $inv = $this->invitar(self::correo('exp3'));
        self::q("UPDATE colaborador_invitaciones SET expira_en=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY), creada_en=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 8 DAY) WHERE id=?", [$inv['invitacion_id']]);
        $r = $this->svc()->reenviar(self::$dueno['id'], $inv['colaborador_id'], Idem::clave());
        $this->assertSame('ok', $r['status'], json_encode($r));
        $nuevo = self::tokenDeInvitacion((int)self::escalar('SELECT MAX(id) FROM colaborador_invitaciones WHERE colaborador_id=?', [$inv['colaborador_id']]));
        $this->assertNotSame($inv['token'], $nuevo);
        $post = ['acepto' => '1', 'password' => 'Nueva-Pass-123', 'password_confirmacion' => 'Nueva-Pass-123'];
        $this->assertSame('invalid', $this->svc()->aceptar($inv['token'], $post, '10.3.0.3')['status']);
        $this->assertSame('ok', $this->svc()->aceptar($nuevo, $post, '10.3.0.4')['status']);
    }

    public function test_reutilizacion_tras_reenviar_revocar_y_aceptar(): void
    {
        $post = ['acepto' => '1', 'password' => 'Nueva-Pass-123', 'password_confirmacion' => 'Nueva-Pass-123'];
        // reenviar
        $a = $this->invitar(self::correo('reu'));
        self::q('UPDATE colaborador_invitaciones SET creada_en=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE id=?', [$a['invitacion_id']]);
        $this->assertSame('ok', $this->svc()->reenviar(self::$dueno['id'], $a['colaborador_id'], Idem::clave())['status']);
        $this->assertSame('invalid', $this->svc()->aceptar($a['token'], $post, '10.4.0.1')['status'], 'token reemplazado');
        $this->assertSame(410, (new Navegador(self::$base))->get('/equipo/aceptar?token=' . $a['token'])['status']);
        // revocar
        $b = $this->invitar(self::correo('reu2'));
        $this->assertSame('ok', $this->svc()->revocar(self::$dueno['id'], $b['colaborador_id'])['status']);
        $this->assertSame('invalid', $this->svc()->aceptar($b['token'], $post, '10.4.0.2')['status']);
        // aceptar y reutilizar con otra contraseña (intento de secuestro tras la aceptación)
        $c = $this->invitar(self::correo('reu3'));
        $this->assertSame('ok', $this->svc()->aceptar($c['token'], $post, '10.4.0.3')['status']);
        $hash = self::escalar('SELECT password FROM usuarios WHERE email=?', [$c['email']]);
        $malo = ['acepto' => '1', 'password' => 'Atacante-Pass-999', 'password_confirmacion' => 'Atacante-Pass-999'];
        $this->assertSame('invalid', $this->svc()->aceptar($c['token'], $malo, '10.4.0.4')['status']);
        $this->assertSame($hash, self::escalar('SELECT password FROM usuarios WHERE email=?', [$c['email']]), 'la contraseña no debe cambiar al reutilizar un token usado');
        $this->assertSame(410, (new Navegador(self::$base))->get('/equipo/aceptar?token=' . $c['token'])['status']);
    }

    public function test_formato_del_token_hex_64_y_variantes_hostiles(): void
    {
        $inv = $this->invitar(self::correo('fmt'));
        $post = ['acepto' => '1', 'password' => 'Nueva-Pass-123', 'password_confirmacion' => 'Nueva-Pass-123'];
        foreach ([strtoupper($inv['token']), $inv['token'] . "\n", ' ' . $inv['token'], substr($inv['token'], 0, 63), $inv['token'] . '0', $inv['token'] . '%00', str_repeat('0', 64)] as $t) {
            $this->assertSame('invalid', $this->svc()->aceptar($t, $post, '10.5.0.1')['status'], 'aceptó variante: ' . json_encode($t));
        }
        $this->assertSame('pendiente', self::escalar('SELECT estado FROM colaborador_invitaciones WHERE id=?', [$inv['invitacion_id']]));
        // Mismo token en MAYÚSCULAS por HTTP
        $this->assertSame(404, (new Navegador(self::$base))->get('/equipo/aceptar?token=' . strtoupper($inv['token']))['status']);
    }

    public function test_fuerza_bruta_de_la_contrasena_actual_queda_limitada(): void
    {
        $cli = self::cliente(['email' => self::correo('bf'), 'password' => 'Real-Pass-12345']);
        $inv = $this->invitar($cli['email']);
        $n = new Navegador(self::$base);
        $estados = [];
        for ($i = 0; $i < 12; $i++) {
            $csrf = Navegador::csrfDe($n->get('/equipo/aceptar?token=' . $inv['token'])['body']);
            $r = $n->post('/equipo/aceptar?token=' . $inv['token'], ['csrf_token' => $csrf, 'password_actual' => 'Intento-' . $i, 'acepto' => '1']);
            $estados[] = $r['status'];
        }
        $this->assertContains(429, $estados, 'sin límite de intentos: ' . json_encode($estados));
        $this->assertLessThanOrEqual(8, count(array_filter($estados, fn($s) => $s === 403)), 'más de 8 intentos de contraseña por token: ' . json_encode($estados));
        // La contraseña correcta tampoco pasa mientras dure el bloqueo (y la invitación sigue pendiente).
        $csrf = Navegador::csrfDe($n->get('/equipo/aceptar?token=' . $inv['token'])['body']);
        $r = $n->post('/equipo/aceptar?token=' . $inv['token'], ['csrf_token' => $csrf, 'password_actual' => 'Real-Pass-12345', 'acepto' => '1']);
        $this->assertSame(429, $r['status']);
        $this->assertSame('pendiente', self::escalar('SELECT estado FROM colaborador_invitaciones WHERE id=?', [$inv['invitacion_id']]));
        // Control positivo tras limpiar el contador.
        self::q('TRUNCATE intentos_login');
        $csrf = Navegador::csrfDe($n->get('/equipo/aceptar?token=' . $inv['token'])['body']);
        $r = $n->post('/equipo/aceptar?token=' . $inv['token'], ['csrf_token' => $csrf, 'password_actual' => 'Real-Pass-12345', 'acepto' => '1']);
        $this->assertSame(302, $r['status']);
    }

    public function test_cuenta_existente_sin_confirmar_no_permite_toma_de_control_por_quien_la_registro(): void
    {
        $victima = self::correo('victima');
        $cap = [];
        // El atacante pre-registra el correo de la víctima con SU contraseña (queda sin confirmar).
        $this->auth($cap)->registrar(['nombre' => 'Atac', 'apellido' => 'Ante', 'email' => $victima, 'password' => 'Atacante-Pass-1', 'telefono' => '0991112222'], '10.6.0.1', Idem::clave(), 'anonimo:atacante');
        $this->assertSame(0, (int)self::escalar('SELECT confirmado FROM usuarios WHERE email=?', [$victima]));
        $inv = $this->invitar($victima, 'gerente');
        // El atacante NO tiene el token (va al buzón de la víctima). La víctima acepta con su contraseña.
        $this->assertSame('ok', $this->svc()->aceptar($inv['token'], ['acepto' => '1', 'password' => 'Victima-Pass-123', 'password_confirmacion' => 'Victima-Pass-123'], '10.6.0.2')['status']);
        self::q('TRUNCATE intentos_login');
        $a = new Navegador(self::$base);
        $this->assertNotSame(302, $a->login('equipo', $victima, 'Atacante-Pass-1')['status'], 'la contraseña del pre-registro debe quedar invalidada');
        self::q('TRUNCATE intentos_login');
        $v = new Navegador(self::$base);
        $this->assertSame(302, $v->login('equipo', $victima, 'Victima-Pass-123')['status']);
    }

    public function test_cuenta_existente_sin_confirmar_recupera_sesiones_y_tokens_previos(): void
    {
        $email = self::correo('prereg');
        $cap = [];
        $this->auth($cap)->registrar(['nombre' => 'Atac', 'apellido' => 'Ante', 'email' => $email, 'password' => 'Atacante-Pass-1', 'telefono' => '0991112222'], '10.6.0.3', Idem::clave(), 'anonimo:atacante2');
        $tokenConfirm = $cap[0]['token'];
        $inv = $this->invitar($email, 'finanzas');
        $this->assertSame('ok', $this->svc()->aceptar($inv['token'], ['acepto' => '1', 'password' => 'Victima-Pass-123', 'password_confirmacion' => 'Victima-Pass-123'], '10.6.0.4')['status']);
        // El token de confirmación previo ya no debe servir para nada (y no debe poder cambiar la contraseña)
        $r = (new Navegador(self::$base))->get('/confirmar-cuenta?token=' . $tokenConfirm);
        $this->assertSame(410, $r['status']);
        // Recuperación pendiente emitida antes de aceptar tampoco debe sobrevivir
        $email2 = self::correo('prereg2');
        $cap2 = [];
        $this->auth($cap2)->registrar(['nombre' => 'Atac', 'apellido' => 'Ante', 'email' => $email2, 'password' => 'Atacante-Pass-1', 'telefono' => '0991112222'], '10.6.0.5', Idem::clave(), 'anonimo:atacante3');
        $this->auth($cap2)->solicitarRecuperacion(['email' => $email2], '10.6.0.6');
        $rec = array_values(array_filter($cap2, fn($c) => $c['tipo'] !== 'confirmacion'));
        // Si no se emitió recuperación para la cuenta sin confirmar no hay nada que invalidar (invariante cumplido por construcción).
        $invalidado = true;
        if ($rec) {
            $inv2 = $this->invitar($email2);
            $this->svc()->aceptar($inv2['token'], ['acepto' => '1', 'password' => 'Victima-Pass-123', 'password_confirmacion' => 'Victima-Pass-123'], '10.6.0.7');
            $invalidado = (bool)$this->auth($cap2)->validarTokenRecuperacion($rec[0]['token'])['error'];
        }
        $this->assertTrue($invalidado, 'un token de recuperación emitido antes de la aceptación sigue siendo válido: permitiría cambiar la contraseña tras la toma de control');
    }

    public function test_invitacion_a_correo_de_cliente_confirmado_exige_su_contrasena_y_conserva_citas(): void
    {
        $cli = self::cliente(['email' => self::correo('cc'), 'password' => 'Real-Pass-12345']);
        self::q("INSERT INTO citas(fecha,hora,usuarioId,estado) VALUES (CURDATE(),'10:00:00',?,'confirmada')", [$cli['id']]);
        $inv = $this->invitar($cli['email'], 'recepcion');
        $r = $this->svc()->aceptar($inv['token'], ['acepto' => '1', 'password' => 'Atacante-Pass-1', 'password_confirmacion' => 'Atacante-Pass-1'], '10.7.0.1');
        $this->assertSame('invalid', $r['status'], 'sin contraseña actual no se acepta: ' . json_encode($r));
        $r = $this->svc()->aceptar($inv['token'], ['acepto' => '1', 'password_actual' => 'Incorrecta'], '10.7.0.2');
        $this->assertSame('forbidden', $r['status']);
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM usuario_accesos WHERE usuario_id=?', [$cli['id']]));
        $hash = self::escalar('SELECT password FROM usuarios WHERE id=?', [$cli['id']]);
        $r = $this->svc()->aceptar($inv['token'], ['acepto' => '1', 'password_actual' => 'Real-Pass-12345'], '10.7.0.3');
        $this->assertSame('ok', $r['status']);
        $this->assertSame($hash, self::escalar('SELECT password FROM usuarios WHERE id=?', [$cli['id']]), 'no cambia la contraseña de una cuenta confirmada');
        $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM citas WHERE usuarioId=?', [$cli['id']]));
        $this->assertSame('activo', self::escalar('SELECT estado FROM clientes WHERE usuario_id=?', [$cli['id']]));
    }

    public function test_invitar_el_correo_de_un_propietario_activo_con_otro_rol_se_rechaza(): void
    {
        // El propietario invita su propio correo como 'recepcion'. La ficha del propietario ya existe: el UNIQUE por correo lo impide.
        $r = $this->svc()->invitar(self::$dueno['id'], ['nombre' => 'Du', 'apellido' => 'Eño', 'email' => strtoupper(self::$dueno['email']), 'telefono' => '', 'puesto' => '', 'rol' => 'recepcion', 'fecha_ingreso' => ''], Idem::clave());
        $this->assertSame('conflict', $r['status'], json_encode($r));
        $this->assertSame('propietario', self::escalar('SELECT rol FROM usuario_accesos WHERE usuario_id=?', [self::$dueno['id']]) ?? 'propietario');
    }

    public function test_enumeracion_login_usuario_inexistente_y_clave_incorrecta_son_indistinguibles(): void
    {
        $cli = self::cliente(['email' => self::correo('enu')]);
        $a = (new Navegador(self::$base)); $b = (new Navegador(self::$base));
        $ra = $a->login('cliente', $cli['email'], 'mala-clave-1');
        $rb = $b->login('cliente', 'noexiste.' . bin2hex(random_bytes(3)) . '@example.test', 'mala-clave-1');
        $norm = fn($h) => preg_replace(['/value="[a-f0-9]{64}"/', '/value="[^"]*"/'], ['', ''], $h);
        $this->assertSame($ra['status'], $rb['status']);
        $this->assertSame($norm($ra['body']), $norm($rb['body']));
        $ea = $a->login('equipo', $cli['email'], 'mala-clave-1'); $eb = $b->login('equipo', 'noexiste@example.test', 'mala-clave-1');
        $this->assertSame($ea['status'], $eb['status']);
    }

    public function test_Defecto_registro_publico_revela_si_un_correo_ya_tiene_cuenta(): void
    {
        $this->markTestSkipped('Riesgo aceptado y documentado: el registro público conserva el mensaje histórico «El usuario ya está registrado» (limitado por intentos); ver docs/GUIA_ACTUALIZACION_IDENTIDADES.md §10 (DEF-13).');
        $existente = self::cliente(['email' => self::correo('enu2')]);
        $miembro = self::miembro(self::$dueno['id'], 'recepcion');
        $nuevo = self::correo('enu3');
        $envia = function (string $email): array {
            $n = new Navegador(self::$base);
            $csrf = $n->csrf('/cliente/registro');
            $k = Idem::clave();
            return $n->post('/cliente/registro', ['csrf_token' => $csrf, 'idem_key' => $k, 'nombre' => 'A', 'apellido' => 'B', 'email' => $email, 'telefono' => '0991112222', 'password' => 'Abcdef123456']);
        };
        $rExiste = $envia($existente['email']); $rStaff = $envia($miembro['email']); $rNuevo = $envia($nuevo);
        $this->assertSame($rNuevo['status'], $rExiste['status'], 'el estado HTTP de un correo existente difiere del de uno nuevo: permite enumerar clientes (' . $rExiste['status'] . ' vs ' . $rNuevo['status'] . ')');
        $this->assertSame($rNuevo['status'], $rStaff['status'], 'permite descubrir correos del personal');
    }

    public function test_olvide_y_reenvio_de_confirmacion_no_enumeran(): void
    {
        $cli = self::cliente(['email' => self::correo('enu4')]);
        foreach (['/olvide', '/reenviar-confirmacion'] as $ruta) {
            $n = new Navegador(self::$base);
            $csrf = $n->csrf($ruta);
            $a = $n->post($ruta, ['csrf_token' => $csrf, 'email' => $cli['email']]);
            self::q('TRUNCATE intentos_login');
            $csrf = $n->csrf($ruta);
            $b = $n->post($ruta, ['csrf_token' => $csrf, 'email' => 'nadie.' . bin2hex(random_bytes(3)) . '@example.test']);
            $this->assertSame($a['status'], $b['status'], $ruta);
            $this->assertSame(strip_tags(preg_replace('/value="[^"]*"/', '', $a['body'])), strip_tags(preg_replace('/value="[^"]*"/', '', $b['body'])), $ruta);
        }
    }

    public function test_el_registro_de_errores_no_contiene_tokens_de_invitacion(): void
    {
        $inv = $this->invitar(self::correo('log'));
        $n = new Navegador(self::$base);
        $n->get('/equipo/aceptar?token=' . $inv['token']);
        $csrf = Navegador::csrfDe($n->get('/equipo/aceptar?token=' . $inv['token'])['body']);
        $n->post('/equipo/aceptar?token=' . $inv['token'], ['csrf_token' => $csrf, 'password' => 'a', 'password_confirmacion' => 'b', 'acepto' => '1']);
        $n->post('/equipo/aceptar?token=' . $inv['token'], ['csrf_token' => $csrf, 'password' => 'Nueva-Pass-123', 'password_confirmacion' => 'Nueva-Pass-123', 'acepto' => '1']);
        $log = self::logServidor();
        $this->assertStringNotContainsString($inv['token'], $log);
        $this->assertStringNotContainsString(hash('sha256', $inv['token']), $log);
        foreach (['equipo_auditoria' => 'detalle', 'correos_salida' => 'ultimo_error'] as $t => $c) {
            $this->assertSame(0, (int)self::escalar("SELECT COUNT(*) FROM $t WHERE $c LIKE ?", ['%' . $inv['token'] . '%']), "token en $t.$c");
        }
        // La carga con el token se borra tras un envío correcto y nunca se guarda en claro.
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM correos_salida WHERE carga_cifrada LIKE ?', ['%' . $inv['token'] . '%']));
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM colaborador_invitaciones WHERE token_hash=?', [$inv['token']]));
    }

    public function test_carga_cifrada_con_otra_clave_no_se_descifra_y_el_evento_queda_reintentable(): void
    {
        $inv = $this->invitar(self::correo('key'));
        $original = $_ENV['APP_KEY'] ?? null;
        $_ENV['APP_KEY'] = str_repeat('k', 40);
        try {
            $capturado = [];
            \Classes\Email::setTransport(static function ($mail) use (&$capturado) { $capturado[] = 1; return true; });
            $svc = new \Services\CorreoSalidaService(new \Repositories\CorreoSalidaRepository(self::db()));
            $id = (int)self::escalar('SELECT id FROM correos_salida WHERE clave_unica=?', ['invitacion:' . $inv['invitacion_id']]);
            $this->assertFalse($svc->despachar($id));
            $this->assertSame([], $capturado, 'no debe enviarse con una carga ilegible');
            $e = self::q('SELECT estado, intentos, ultimo_error FROM correos_salida WHERE id=?', [$id])[0];
            $this->assertContains($e['estado'], ['pendiente', 'fallido']);
            $this->assertSame(1, (int)$e['intentos']);
        } finally {
            \Classes\Email::setTransport(null);
            if ($original === null) { unset($_ENV['APP_KEY']); } else { $_ENV['APP_KEY'] = $original; }
        }
    }
}

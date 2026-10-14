<?php

namespace Tests\Qa;

require_once __DIR__ . '/R3Case.php';

/**
 * Ronda 4: reverificación INDEPENDIENTE de la corrección de datos de contacto en la activación. El titular revisa y corrige nombre, apellido
 * y teléfono que escribió quien registró el correo ANTES de verificarlo; se aplican con la contraseña en el mismo UPDATE atómico. Los datos son
 * declarados, no verificados. HTTP real (8 procesos), SMTP simulado 2593, worker real. Ningún correo sale de la máquina.
 */
final class R4Test extends R3Case
{
    private static array $dueno;
    private const TEL = '0987654321';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$dueno = self::propietario(self::correo('dueno'));
    }

    // ------------------------------------------------------------------ utilidades

    /** Registro público con datos de contacto y contraseña elegidos por quien lo hace. */
    private function registrarCon(string $email, string $nombre, string $apellido, string $tel, string $clave): void
    {
        self::q('TRUNCATE intentos_login');
        $r = self::registrarHttp(new Navegador(self::$base), $email, ['nombre' => $nombre, 'apellido' => $apellido, 'telefono' => $tel, 'password' => $clave]);
        $this->assertSame(302, $r['status'], substr(strip_tags($r['body']), 0, 200));
    }

    /** @return array{0:Navegador,1:array} navegador con la sesión del formulario y el formulario parseado */
    private function form(string $token): array
    {
        [$n, $f] = $this->abrirFormulario($token);
        $this->assertNotNull($f, 'el enlace debe mostrar el formulario');
        return [$n, $f];
    }

    private function post(Navegador $n, array $f, string $clave, array $contacto = [], array $extra = []): array
    {
        self::q('TRUNCATE intentos_login');
        return $n->post($f['accion'], $this->cuerpo($f, $clave, $contacto + $extra));
    }

    private function usuario(string $email): array
    {
        return self::q('SELECT * FROM usuarios WHERE email=?', [$email])[0];
    }

    /** Huella de TODAS las demás cuentas (para demostrar que el endpoint no toca otras filas). */
    private function huellaOtras(int $exceptoId): string
    {
        return md5(json_encode(self::q('SELECT * FROM usuarios WHERE id<>? ORDER BY id', [$exceptoId])) . json_encode(self::q('SELECT * FROM usuario_accesos ORDER BY usuario_id')) . json_encode(self::q('SELECT * FROM clientes WHERE usuario_id<>? ORDER BY usuario_id', [$exceptoId])));
    }

    private function valorInput(string $html, string $nombre): ?string
    {
        $dom = new \DOMDocument(); @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        foreach ($dom->getElementsByTagName('input') as $in) { if ($in->getAttribute('name') === $nombre) { return $in->getAttribute('value'); } }
        return null;
    }

    // ------------------------------------------------------------------ 1. escenario adversarial completo

    public function test_R4_01_el_titular_corrige_los_datos_del_atacante_y_la_fila_entera_es_suya(): void
    {
        $email = self::correo('titular');
        $this->registrarCon($email, 'Mallory', 'Atacante', '0991110000', self::A);
        $this->registrarCon($email, 'Teresa', 'Real', self::TEL, self::B);   // el titular intenta registrarse: respuesta uniforme, no pisa nada
        $u0 = $this->usuario($email);
        $this->assertSame('Mallory', $u0['nombre'], 'precondición: la cuenta sin confirmar conserva lo que escribió quien la registró primero');
        $token = $this->ultimoToken($email);
        // El correo que recibe el titular y el formulario muestran los datos del atacante para que los revise.
        $correo = array_values(array_filter(self::buzonFallo(self::SMTP), fn($m) => in_array($email, $m['to'], true)));
        $this->assertCount(1, $correo);
        [$n, $f] = $this->form($token);
        $this->assertSame('Mallory', $f['campos']['nombre']);
        $this->assertSame('Atacante', $f['campos']['apellido']);
        $this->assertSame('0991110000', $f['campos']['telefono']);
        $html = $n->get('/confirmar-cuenta?token=' . $token)['body'];
        $this->assertMatchesRegularExpression('/Revisa tus datos/u', $html);
        $this->assertMatchesRegularExpression('/no se verifican/u', $html, 'el aviso debe decir que nombre y teléfono no se verifican');
        // El titular corrige TODO y elige su contraseña.
        $r = $this->post($n, $f, self::C, ['nombre' => 'Teresa', 'apellido' => 'Real', 'telefono' => self::TEL]);
        $this->assertSame(200, $r['status'], substr(strip_tags($r['body']), 0, 300));
        $u = $this->usuario($email);
        $this->assertSame(['Teresa', 'Real', self::TEL, '1'], [$u['nombre'], $u['apellido'], $u['telefono'], (string)$u['confirmado']]);
        $this->assertTrue(password_verify(self::C, $u['password']));
        $this->assertFalse(password_verify(self::A, $u['password']));
        $this->assertFalse(password_verify(self::B, $u['password']));
        $this->assertNoEntra($email, self::A, 'A');
        $this->assertNoEntra($email, self::B, 'B');
        $this->assertEntra($email, self::C, 'C');
        // Nada del atacante sobrevive en los datos de la cuenta, de su membresía, de su perfil ni de sus auditorías.
        $filaCompleta = json_encode(self::q('SELECT * FROM usuarios WHERE email=?', [$email])) . json_encode(self::q('SELECT * FROM clientes WHERE usuario_id=?', [$u['id']]))
            . json_encode(self::q('SELECT * FROM perfiles_usuario WHERE usuarioId=?', [$u['id']]));
        foreach (['Mallory', 'Atacante', '0991110000'] as $resto) { $this->assertStringNotContainsString($resto, $filaCompleta, "queda «{$resto}» en la cuenta tras la corrección del titular"); }
        // Todas las páginas que la persona ve ahora usan sus datos.
        $nav = new Navegador(self::$base);
        self::q('TRUNCATE intentos_login');
        $this->assertSame(302, $nav->login('cliente', $email, self::C)['status']);
        $perfil = $nav->get('/perfil')['body'];
        $this->assertStringContainsString('Teresa Real', $perfil);
        $this->assertStringNotContainsString('Mallory', $perfil);
    }

    /** Inventario de dónde queda el nombre/teléfono del atacante tras la corrección del titular (cualquier columna de texto de la base). */
    public function test_R4_02_residuos_del_atacante_en_toda_la_base_tras_la_correccion(): void
    {
        $m = 'Zmal' . bin2hex(random_bytes(3));
        $tel = '09' . random_int(10000000, 99999999);
        $email = self::correo('resid');
        $this->registrarCon($email, $m, 'Atk' . $m, $tel, self::A);
        $token = $this->ultimoToken($email);
        [$n, $f] = $this->form($token);
        $this->post($n, $f, self::C, ['nombre' => 'Real', 'apellido' => 'Titular', 'telefono' => self::TEL]);
        self::entregarCola(self::SMTP);
        $resto = [];
        $cols = self::q("SELECT table_name t, column_name c FROM information_schema.columns WHERE table_schema=DATABASE() AND data_type IN ('varchar','text','longtext','mediumtext','json','char','blob','longblob') AND table_name NOT IN ('migraciones')");
        foreach ($cols as $x) {
            foreach ([$m, $tel] as $buscado) {
                $n = (int)self::escalar('SELECT COUNT(*) FROM `' . $x['t'] . '` WHERE `' . $x['c'] . '` LIKE ?', ['%' . $buscado . '%']);
                if ($n > 0) { $resto[] = "{$x['t']}.{$x['c']} ($n)"; }
            }
        }
        // Se permite (y se informa) lo que es registro histórico del propio envío: el destinatario_nombre del correo de confirmación ya entregado.
        $inesperados = array_values(array_filter($resto, fn($s) => !str_starts_with($s, 'correos_salida.destinatario_nombre')));
        fwrite(STDERR, "\n[R4_02] residuos del atacante en la base: " . json_encode($resto) . "\n");
        $this->assertSame([], $inesperados, 'datos del atacante que sobreviven en: ' . implode(', ', $inesperados));
        // Observación (no fallo): el registro histórico del correo de confirmación ya entregado conserva el nombre al que se saludó. Es un registro del
        // envío (cola de salida), no un dato de la cuenta ni de ninguna pantalla; se informa en el documento de la ronda.
        $this->addToAssertionCount(1);
    }

    // ------------------------------------------------------------------ 2. autorización y asignación masiva

    public function test_R4_03_los_campos_extra_se_ignoran_y_ninguna_otra_cuenta_cambia(): void
    {
        $victima = self::cliente(['email' => self::correo('vic')]);
        $otro = self::miembro(self::$dueno['id'], 'recepcion');
        $email = self::correo('mass');
        $this->registrarCon($email, 'Mass', 'Assign', '0990002222', self::A);
        [$n, $f] = $this->form($this->ultimoToken($email));
        $antes = $this->usuario($email);
        $huella = $this->huellaOtras((int)$antes['id']);
        $extras = ['id' => (string)$victima['id'], 'usuario_id' => (string)$victima['id'], 'email' => $victima['email'], 'admin' => '1', 'rol' => 'propietario', 'confirmado' => '0', 'password_hash' => 'x',
                   'token_hash' => 'x', 'token_tipo' => 'recuperacion', 'token_expira' => '2099-01-01 00:00:00', 'version_sesion' => '99', 'estado' => 'suspendido', 'colaborador_id' => (string)$otro['colaborador_id'],
                   'foto' => 'x', 'email_norm' => $victima['email'], 'nombre_profesional' => 'Hack', 'origen' => 'recepcion', 'token' => $f['campos']['token']];
        $r = $this->post($n, $f, self::C, ['nombre' => 'Ana', 'apellido' => 'Bien', 'telefono' => '0993334444'], $extras);
        $this->assertSame(200, $r['status'], substr(strip_tags($r['body']), 0, 300));
        $d = $this->usuario($email);
        $this->assertSame($email, $d['email'], 'el correo no puede cambiar');
        $this->assertSame('0', (string)$d['admin'], 'no puede volverse administrador');
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM usuario_accesos WHERE usuario_id=?', [$d['id']]), 'no puede obtener acceso laboral');
        $this->assertSame((int)$antes['id'], (int)$d['id']);
        $this->assertSame(['Ana', 'Bien', '0993334444'], [$d['nombre'], $d['apellido'], $d['telefono']]);
        $this->assertNull($d['token_hash'], 'el token inyectado no debe sobrevivir');
        $this->assertSame($huella, $this->huellaOtras((int)$d['id']), 'una asignación masiva alteró OTRAS cuentas');
        $this->assertSame('activo', self::escalar('SELECT estado FROM clientes WHERE usuario_id=?', [$d['id']]), 'el estado de la membresía no se puede fijar desde el formulario');
        $this->assertEntra($email, self::C, 'C');
    }

    public function test_R4_04_un_token_ajeno_solo_afecta_a_su_propia_cuenta_aunque_se_envie_el_id_de_otra_y_con_otra_sesion_iniciada(): void
    {
        $viva = self::cliente(['email' => self::correo('viva')]);                // otra persona con sesión iniciada
        $victima = self::cliente(['email' => self::correo('vict')]);
        $propia = self::correo('propia');
        $this->registrarCon($propia, 'Propia', 'Cuenta', '0995556666', self::A);
        [$n, $f] = $this->form($this->ultimoToken($propia));
        // El navegador del atacante tiene la sesión de OTRA cuenta confirmada.
        self::q('TRUNCATE intentos_login');
        $this->assertSame(302, $n->login('cliente', $viva['email'], $viva['password'])['status']);
        $antesViva = json_encode(self::q('SELECT nombre,apellido,telefono,password,version_sesion FROM usuarios WHERE id=?', [$viva['id']]));
        $antesVic = json_encode(self::q('SELECT nombre,apellido,telefono,password,version_sesion,confirmado FROM usuarios WHERE id=?', [$victima['id']]));
        $r = $this->post($n, $f, self::C, ['nombre' => 'X', 'apellido' => 'Y', 'telefono' => '0991230000'], ['id' => (string)$victima['id'], 'usuario_id' => (string)$viva['id'], 'email' => $victima['email']]);
        $this->assertContains($r['status'], [200, 403, 410, 422], substr(strip_tags($r['body']), 0, 200));
        $this->assertSame($antesViva, json_encode(self::q('SELECT nombre,apellido,telefono,password,version_sesion FROM usuarios WHERE id=?', [$viva['id']])), 'la cuenta de la sesión iniciada fue modificada');
        $this->assertSame($antesVic, json_encode(self::q('SELECT nombre,apellido,telefono,password,version_sesion,confirmado FROM usuarios WHERE id=?', [$victima['id']])), 'otra cuenta fue modificada con campos extra');
        $this->assertNoEntra($victima['email'], self::C, 'C en la víctima');
        // La cuenta cuyo token se usó es la única que puede haber cambiado (se activa si el servidor lo permite con otra sesión abierta).
        $this->assertSame($r['status'] === 200 ? '1' : '0', (string)$this->usuario($propia)['confirmado']);
    }

    public function test_R4_05_sin_csrf_o_con_token_invalido_vencido_usado_o_de_otro_tipo_no_cambia_ni_un_dato(): void
    {
        $email = self::correo('aut');
        $this->registrarCon($email, 'Antes', 'Intacto', '0991112222', self::A);
        $token = $this->ultimoToken($email);
        [$n, $f] = $this->form($token);
        $antes = $this->usuario($email);
        $contacto = ['nombre' => 'Despues', 'apellido' => 'Cambiado', 'telefono' => '0999998888'];
        $otra = new Navegador(self::$base); $csrfAjeno = $otra->csrf('/cliente/login');
        $claveCsrf = 'csrf_token'; $claveToken = array_search($token, $f['campos'], true);
        $casos = [
            'sin CSRF' => fn() => $n->post($f['accion'], array_diff_key($this->cuerpo($f, self::C, $contacto), [$claveCsrf => 1])),
            'CSRF ajeno' => fn() => $n->post($f['accion'], $this->cuerpo($f, self::C, $contacto + [$claveCsrf => $csrfAjeno])),
            'token inventado' => fn() => $n->post($f['accion'], $this->cuerpo($f, self::C, $contacto + [$claveToken => bin2hex(random_bytes(32))])),
            'token vacío' => fn() => $n->post($f['accion'], $this->cuerpo($f, self::C, $contacto + [$claveToken => ''])),
            'token de recuperación' => function () use ($n, $f, $contacto, $claveToken) {
                $c = self::cliente(['email' => self::correo('rt')]); $rec = bin2hex(random_bytes(32));
                self::q("UPDATE usuarios SET token_hash=?, token_tipo='recuperacion', token_expira=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR) WHERE id=?", [hash('sha256', $rec), $c['id']]);
                return $n->post($f['accion'], $this->cuerpo($f, self::C, $contacto + [$claveToken => $rec]));
            },
        ];
        foreach ($casos as $nombre => $fn) {
            self::q('TRUNCATE intentos_login');
            $r = $fn();
            $this->assertLessThan(500, $r['status'], $nombre);
            $d = $this->usuario($email);
            $this->assertSame([$antes['nombre'], $antes['apellido'], $antes['telefono'], $antes['password'], $antes['token_hash'], '0'], [$d['nombre'], $d['apellido'], $d['telefono'], $d['password'], $d['token_hash'], (string)$d['confirmado']], "«{$nombre}» cambió datos");
        }
        // Token vencido.
        self::q('UPDATE usuarios SET token_expira = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE email=?', [$email]);
        $r = $this->post($n, $f, self::C, $contacto);
        $this->assertSame(410, $r['status']);
        $this->assertSame($antes['nombre'], $this->usuario($email)['nombre'], 'token vencido cambió el nombre');
        // Token usado: activar de verdad y reintentar con otros datos.
        self::q('UPDATE usuarios SET token_expira = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR) WHERE email=?', [$email]);
        $ok = $this->post($n, $f, self::C, $contacto);
        $this->assertSame(200, $ok['status']);
        $n2 = new Navegador(self::$base); $csrf2 = $n2->csrf('/olvide');
        self::q('TRUNCATE intentos_login');
        $r = $n2->post('/confirmar-cuenta', ['csrf_token' => $csrf2, 'idem_key' => Idem::clave(), 'token' => $token, 'password' => 'Otra-Clave-9999', 'password2' => 'Otra-Clave-9999', 'nombre' => 'Robado', 'apellido' => 'Dos', 'telefono' => '0990000000']);
        $this->assertSame(410, $r['status'], 'un token usado no debe volver a servir');
        $d = $this->usuario($email);
        $this->assertSame(['Despues', 'Cambiado', '0999998888'], [$d['nombre'], $d['apellido'], $d['telefono']], 'un token usado alteró los datos');
        $this->assertNoEntra($email, 'Otra-Clave-9999', 'del reintento');
    }

    // ------------------------------------------------------------------ 3. concurrencia: una sola fila ganadora

    public function test_R4_06_diez_activaciones_simultaneas_con_datos_y_claves_distintos_dejan_la_fila_entera_de_un_unico_ganador(): void
    {
        for ($ronda = 1; $ronda <= 3; $ronda++) {
            $email = self::correo('con' . $ronda);
            $this->registrarCon($email, 'Atk', 'Atk', '0990000000', self::A);
            $token = $this->ultimoToken($email);
            $c = [];
            for ($i = 0; $i < 10; $i++) {
                [$n, $f] = $this->form($token);
                $c[$i] = ['nav' => $n, 'f' => $f, 'pass' => "Cont-Clave-$i-" . bin2hex(random_bytes(2)), 'nombre' => "Nom$i", 'apellido' => "Ape$i", 'tel' => '09' . str_repeat((string)$i, 8)];
            }
            self::q('TRUNCATE intentos_login');
            $res = $this->rafagaHttp(array_map(fn($x) => ['cookie' => $x['nav']->cookieSesion(), 'ruta' => $x['f']['accion'],
                'body' => $this->cuerpo($x['f'], $x['pass'], ['nombre' => $x['nombre'], 'apellido' => $x['apellido'], 'telefono' => $x['tel']])], $c));
            $estados = array_column($res, 'status');
            $this->assertLessThan(500, max($estados), json_encode($estados));
            $gan = array_keys(array_filter($estados, fn($s) => $s === 200));
            $this->assertCount(1, $gan, "ronda $ronda: exactamente UNA activación debe ganar: " . json_encode($estados));
            $u = $this->usuario($email);
            $w = $c[$gan[0]];
            $this->assertSame([$w['nombre'], $w['apellido'], $w['tel']], [$u['nombre'], $u['apellido'], $u['telefono']], "ronda $ronda: la fila mezcla campos de contendientes distintos");
            $this->assertTrue(password_verify($w['pass'], $u['password']), "ronda $ronda: la contraseña no es la del ganador");
            foreach ($c as $i => $x) { if ($i !== $gan[0]) { $this->assertFalse(password_verify($x['pass'], $u['password']), "ronda $ronda: la contraseña de un perdedor vale"); } }
            $this->assertEntra($email, $w['pass'], 'ganadora');
        }
    }

    // ------------------------------------------------------------------ 4. idempotencia y reintentos

    public function test_R4_07_doble_clic_misma_clave_con_otros_datos_es_409_y_los_422_no_gastan_clave_token_ni_limite(): void
    {
        $email = self::correo('idm');
        $this->registrarCon($email, 'Orig', 'Inal', '0991234567', self::A);
        $token = $this->ultimoToken($email);
        [$n, $f] = $this->form($token);
        $ops = fn() => (int)self::escalar("SELECT COUNT(*) FROM operaciones_idempotentes WHERE scope='cliente.activacion'");
        $opsAntes = $ops();
        $limitesAntes = (int)self::escalar("SELECT COALESCE(SUM(intentos),0) FROM intentos_login WHERE tipo IN ('ip_activate','token_activate')");
        // 30 reintentos con datos inválidos (422) con la MISMA clave de operación del formulario.
        $malos = [['telefono' => '123'], ['nombre' => ''], ['apellido' => str_repeat('a', 61)], ['telefono' => '09abc45678'], ['nombre' => "Ana\0X"], ['nombre' => 'A<b>']];
        for ($i = 0; $i < 30; $i++) {
            $r = $this->post($n, $f, self::C, array_merge(['nombre' => 'Bien', 'apellido' => 'Hecho', 'telefono' => self::TEL], $malos[$i % count($malos)]));
            $this->assertSame(422, $r['status'], "reintento $i: " . json_encode($malos[$i % count($malos)]));
        }
        $this->assertSame(0, (int)$this->usuario($email)['confirmado']);
        $this->assertSame($opsAntes, $ops(), 'los 422 no deben registrar (ni gastar) la clave de operación');
        $this->assertNotNull($this->usuario($email)['token_hash'], 'los 422 no deben consumir el token');
        $this->assertSame($limitesAntes, (int)self::escalar("SELECT COALESCE(SUM(intentos),0) FROM intentos_login WHERE tipo IN ('ip_activate','token_activate')"), 'los 422 no deben contar para los límites de 15 por IP y 5 por token');
        // Con la misma clave de operación, la primera combinación VÁLIDA se acepta...
        $ok = $this->post($n, $f, self::C, ['nombre' => 'Bien', 'apellido' => 'Hecho', 'telefono' => self::TEL]);
        $this->assertSame(200, $ok['status'], substr(strip_tags($ok['body']), 0, 300));
        // ...el doble clic idéntico devuelve lo mismo sin repetir efectos...
        $dc = $this->post($n, $f, self::C, ['nombre' => 'Bien', 'apellido' => 'Hecho', 'telefono' => self::TEL]);
        $this->assertSame(200, $dc['status'], 'doble clic: mismo resultado');
        $h = $this->usuario($email)['password'];
        // ...y la misma clave con OTROS datos (o contraseña) es un conflicto 409 que no cambia nada.
        $c1 = $this->post($n, $f, self::C, ['nombre' => 'Otro', 'apellido' => 'Hecho', 'telefono' => self::TEL]);
        $c2 = $this->post($n, $f, self::C, ['nombre' => 'Bien', 'apellido' => 'Hecho', 'telefono' => '0990000009']);
        $c3 = $this->post($n, $f, 'Otra-Clave-9999-x', ['nombre' => 'Bien', 'apellido' => 'Hecho', 'telefono' => self::TEL]);
        $this->assertSame([409, 409, 409], [$c1['status'], $c2['status'], $c3['status']], 'misma clave con otros datos o contraseña debe ser 409');
        $d = $this->usuario($email);
        $this->assertSame([ 'Bien', 'Hecho', self::TEL], [$d['nombre'], $d['apellido'], $d['telefono']]);
        $this->assertSame($h, $d['password']);
        $this->assertSame($opsAntes + 1, $ops(), 'una sola operación idempotente para esta activación (doble clic y conflictos no crean otra)');
    }

    // ------------------------------------------------------------------ 5. datos hostiles

    public static function datosInvalidos(): array
    {
        return [
            'nombre con NUL' => ['nombre', "Ana\0X"], 'nombre con salto de línea' => ['nombre', "Ana\nBcc: x@y.z"], 'nombre con CR' => ['nombre', "Ana\rX"], 'nombre con tabulador' => ['nombre', "Ana\tX"],
            'nombre con ESC' => ['nombre', "Ana\x1bX"], 'nombre con DEL' => ['nombre', "Ana\x7fX"], 'nombre con <' => ['nombre', 'Ana<script>alert(1)</script>'], 'apellido con >' => ['apellido', 'Perez>'],
            'nombre UTF-8 inválido' => ['nombre', "An\xC3\x28a"], 'apellido UTF-8 inválido' => ['apellido', "\xFF\xFEx"], 'nombre de 61 bytes' => ['nombre', str_repeat('a', 61)],
            'apellido de 31 emojis (124 bytes)' => ['apellido', str_repeat('😀', 31)], 'nombre vacío' => ['nombre', ''], 'apellido vacío' => ['apellido', ''], 'teléfono vacío' => ['telefono', ''],
            'teléfono de 6 dígitos' => ['telefono', '098765'], 'teléfono de 11 dígitos' => ['telefono', '09876543210'], 'teléfono con letras' => ['telefono', '09876abc43'], 'teléfono con +' => ['telefono', '+593987654'],
            'teléfono con espacios' => ['telefono', '0987 65 4321'], 'teléfono con guiones' => ['telefono', '098-765-4321'], 'teléfono con dígitos anchos' => ['telefono', '０９８７６５４３２１'],
            'teléfono con dígitos árabes' => ['telefono', '٠٩٨٧٦٥٤٣٢١'], 'teléfono con NUL' => ['telefono', "09876\0 4321"],
        ];
    }

    /** @dataProvider datosInvalidos */
    public function test_R4_08_datos_invalidos_dan_422_sin_consumir_nada(string $campo, string $valor): void
    {
        $email = self::correo('inv');
        $this->registrarCon($email, 'Orig', 'Inal', '0991234567', self::A);
        [$n, $f] = $this->form($this->ultimoToken($email));
        $antes = $this->usuario($email);
        $buenos = ['nombre' => 'Bien', 'apellido' => 'Hecho', 'telefono' => self::TEL];
        $r = $this->post($n, $f, self::C, [$campo => $valor] + $buenos);
        $this->assertSame(422, $r['status'], "se esperaba 422 para $campo; se obtuvo {$r['status']}");
        $d = $this->usuario($email);
        $this->assertSame([$antes['nombre'], $antes['apellido'], $antes['telefono'], $antes['password'], $antes['token_hash'], '0'], [$d['nombre'], $d['apellido'], $d['telefono'], $d['password'], $d['token_hash'], (string)$d['confirmado']], 'una entrada inválida cambió algo');
        // El 422 vuelve a mostrar el formulario: lo escrito va escapado y sin atributos inyectados; lo almacenado (los datos del atacante) no se vuelve a mostrar.
        $this->assertNotNull($this->formulario(str_replace("\0", '', $r['body']), '/confirmar-cuenta'), 'el 422 debe volver a mostrar el formulario');
        $this->assertStringNotContainsString('<script>alert(1)', $r['body']);
        // Un reintento válido con el mismo formulario sigue funcionando (token y clave intactos).
        $ok = $this->post($n, $f, self::C, $buenos);
        $this->assertSame(200, $ok['status']);
    }

    public static function datosValidos(): array
    {
        return [
            'ASCII de 60 bytes' => [str_repeat('a', 60), 'Perez', '0987654321'],
            'acentos y eñe' => ['Ñandú', 'Pérez Güell', '0987654321'],
            'emoji' => ['Ana 😀', 'López', '0987654321'],
            'árabe RTL' => ['مريم', 'العلي', '0987654321'],
            'comillas y ampersand' => ['O\'Brien & "Hijos"', 'Dos "Apellidos"', '0987654321'],
            'inyección SQL' => ["'; DROP TABLE usuarios;--", "' OR '1'='1", '0987654321'],
            'entidades HTML' => ['&lt;script&gt;alert(1)&lt;/script&gt;', '&amp;&#60;', '0987654321'],
            'esquema javascript' => ['javascript:alert(1)', 'data:text/html;base64,AAAA', '0987654321'],
            'fórmula de hoja de cálculo' => ['=HYPERLINK("http://e.test","x")', '+cmd|calc', '0987654321'],
            'fórmula con arroba y guion' => ['@SUM(A1)', '-2+3', '0987654321'],
            'inyección de atributo' => ['x" autofocus onfocus="alert(1)', "y' onmouseover='alert(1)", '0987654321'],
            'plantillas' => ['{{7*7}}${7*7}', '%s%n%x', '0987654321'],
            'espacios internos' => ['Ana   María', 'De  la  Cruz', '0987654321'],
        ];
    }

    /** @dataProvider datosValidos */
    public function test_R4_09_datos_aceptados_se_guardan_tal_cual_y_se_muestran_escapados_en_perfil_crm_csv_y_correo(string $nombre, string $apellido, string $tel): void
    {
        $email = self::correo('val');
        $this->registrarCon($email, 'Orig', 'Inal', '0991234567', self::A);
        [$n, $f] = $this->form($this->ultimoToken($email));
        $r = $this->post($n, $f, self::C, ['nombre' => $nombre, 'apellido' => $apellido, 'telefono' => $tel]);
        $this->assertSame(200, $r['status'], substr(strip_tags($r['body']), 0, 300));
        $u = $this->usuario($email);
        $this->assertSame([$nombre, $apellido, $tel], [$u['nombre'], $u['apellido'], $u['telefono']], 'los datos deben guardarse exactamente como se enviaron');
        // Perfil de la persona.
        $nav = new Navegador(self::$base);
        self::q('TRUNCATE intentos_login');
        $this->assertSame(302, $nav->login('cliente', $email, self::C)['status']);
        $perfil = $nav->get('/perfil')['body'];
        $this->assertSame($nombre, $this->valorInput($perfil, 'nombre'), 'perfil: el valor del campo no coincide (escape o truncado)');
        $this->assertSame($apellido, $this->valorInput($perfil, 'apellido'));
        $this->sinInyeccion($perfil, 'perfil');
        // CRM del propietario: listado y ficha.
        $dueno = new Navegador(self::$base);
        self::q('TRUNCATE intentos_login');
        $this->assertSame(302, $dueno->login('equipo', self::$dueno['email'], self::$dueno['password'])['status']);
        $lista = $dueno->get('/admin/clientes?q=' . rawurlencode($email))['body'];
        $ficha = $dueno->get('/admin/clientes/ficha?id=' . $u['id'])['body'];
        foreach (['listado CRM' => $lista, 'ficha CRM' => $ficha] as $donde => $html) {
            $this->assertStringContainsString(htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8'), $html, "$donde: el nombre debe aparecer escapado");
            $this->sinInyeccion($html, $donde);
        }
        // CSV: las celdas que empiezan por = + - @ salen neutralizadas con una comilla.
        $csv = $dueno->get('/admin/clientes/exportar?tipo=clientes&q=' . rawurlencode($email));
        $this->assertSame(200, $csv['status']);
        $fh = fopen('php://temp', 'r+'); fwrite($fh, preg_replace('/^\xEF\xBB\xBF/', '', $csv['body'])); rewind($fh);
        $celdas = [];
        while (($fila = fgetcsv($fh, 0, ',', '"', '')) !== false) { foreach ($fila as $c) { $celdas[] = $c; } }
        foreach ($celdas as $c) { $this->assertDoesNotMatchRegularExpression('/^[=+@\-\t\r\f\v]/', $c, "celda del CSV sin neutralizar: " . json_encode($c)); }
        if (preg_match('/^[=+@\-]/', $nombre)) { $this->assertContains("'" . $nombre, $celdas, 'el nombre con fórmula debe salir como texto'); } else { $this->assertContains($nombre, $celdas); }
        // La auditoría de la exportación no guarda nombres ni teléfonos.
        $aud = json_encode(self::q('SELECT * FROM crm_exportaciones_auditoria'));
        $this->assertStringNotContainsString(substr((string)json_encode($nombre), 1, -1), $aud, 'la auditoría contiene el nombre');
        $this->assertStringNotContainsString($tel, $aud);
        // Correo: el saludo del aviso a una cuenta existente lleva el nombre escapado.
        self::q('DELETE FROM correos_salida'); self::vaciarBuzonFallo(self::SMTP);
        $this->registrarCon($email, 'Otro', 'Intento', '0990000001', self::B);
        self::entregarCola(self::SMTP);
        $m = array_values(array_filter(self::buzonFallo(self::SMTP), fn($x) => in_array($email, $x['to'], true)));
        $this->assertCount(1, $m);
        $this->sinInyeccion($m[0]['cuerpo'], 'correo');
        $this->assertDoesNotMatchRegularExpression('/^(Bcc|Cc):/mi', (string)$m[0]['cabeceras'], 'cabecera inyectada por el nombre');
        $this->assertSame(1, preg_match_all('/^To:/mi', (string)$m[0]['cabeceras']), 'una sola cabecera To');
    }

    /** Sin atributos de evento ni script inyectados por los datos (se compara con una página equivalente de datos benignos). */
    private function sinInyeccion(string $html, string $donde): void
    {
        $dom = new \DOMDocument(); @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xp = new \DOMXPath($dom);
        $on = 0;
        foreach ($xp->query('//*') as $el) { foreach ($el->attributes as $a) { if (str_starts_with(strtolower($a->name), 'on') || $a->name === 'autofocus') { $on++; } } }
        $this->assertSame(0, $on, "$donde: aparecieron atributos de evento/autofocus por los datos");
        $this->assertSame(0, $xp->query('//img[@src="x"]')->length);
    }

    /** Espacios, saltos o NUL AL FINAL se recortan (trim) y lo que se guarda es el valor limpio: es una decisión de diseño, no un fallo. */
    public function test_R4_12_los_espacios_saltos_y_nul_finales_se_recortan_y_se_guarda_el_valor_limpio(): void
    {
        $email = self::correo('trim');
        $this->registrarCon($email, 'Orig', 'Inal', '0991234567', self::A);
        [$n, $f] = $this->form($this->ultimoToken($email));
        $r = $this->post($n, $f, self::C, ['nombre' => "  Ana\0", 'apellido' => "Pérez \n", 'telefono' => "0987654321\n"]);
        $this->assertSame(200, $r['status']);
        $u = $this->usuario($email);
        $this->assertSame(['Ana', 'Pérez', '0987654321'], [$u['nombre'], $u['apellido'], $u['telefono']], bin2hex($u['nombre'] . '|' . $u['apellido'] . '|' . $u['telefono']));
    }

    /**
     * El 422 vuelve a escribir en el formulario lo que se envió (para no perder lo tecleado). Un byte NUL no debe aparecer crudo en el HTML:
     * los analizadores HTML lo tratan como error de sintaxis y algunos lo descartan o truncan el atributo. No es explotable por sí solo
     * (lo refleja a quien lo envió), pero es higiene de salida.
     */
    public function test_Defecto_R4_12b_el_422_no_refleja_bytes_de_control_crudos(): void
    {
        $email = self::correo('nul');
        $this->registrarCon($email, 'Orig', 'Inal', '0991234567', self::A);
        [$n, $f] = $this->form($this->ultimoToken($email));
        foreach (['nombre' => "Ana\0X", 'apellido' => "Per\x1bez", 'telefono' => "09876\x00 4321"] as $campo => $valor) {
            $r = $this->post($n, $f, self::C, ['nombre' => 'Bien', 'apellido' => 'Hecho', 'telefono' => self::TEL, $campo => $valor]);
            $this->assertSame(422, $r['status'], $campo);
            $this->assertSame(0, preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $r['body']), "el 422 de «{$campo}» refleja un byte de control crudo en el HTML");
        }
    }

    public static function nombresHostilesDeRegistro(): array
    {
        return [
            'NUL' => ["Ana\0X"], 'CRLF con Bcc' => ["Ana\r\nBcc: victima@ensayo.test"], 'LF' => ["Ana\nBcc: victima@ensayo.test"], 'ESC' => ["Ana\x1bX"],
            'script' => ['<script>alert(1)</script>'], 'atributo' => ['x" autofocus onfocus="alert(1)'], 'RTL override' => ["\u{202E}evil"], 'emoji' => ['😀😀😀'],
            'comilla simple y backtick' => ["x' onfocus='alert(1)`"], 'cierre de textarea/título' => ['</textarea></title><svg onload=alert(1)>'],
        ];
    }

    /**
     * El registro público valida MENOS que la activación (admite < > y caracteres de control), y ahora esos datos se vuelven a mostrar en el
     * formulario del titular y en el correo. Lo que escribió el atacante no puede inyectar HTML, atributos, cabeceras ni destinatarios.
     * @dataProvider nombresHostilesDeRegistro
     */
    public function test_R4_13_nombre_hostil_escrito_por_quien_registro_primero_no_inyecta_en_formulario_ni_correo(string $hostil): void
    {
        $email = self::correo('host');
        self::vaciarBuzonFallo(self::SMTP);
        self::q('TRUNCATE intentos_login');
        $r = self::registrarHttp(new Navegador(self::$base), $email, ['nombre' => $hostil, 'apellido' => $hostil, 'telefono' => self::TEL, 'password' => self::A]);
        $this->assertLessThan(500, $r['status']);
        $existe = self::q('SELECT nombre, apellido FROM usuarios WHERE email=?', [$email]);
        if (!$existe) { $this->addToAssertionCount(1); return; }   // el registro lo rechazó: también es una respuesta segura
        $tok = $this->ultimoToken($email);
        $mails = array_values(array_filter(self::buzonFallo(self::SMTP), fn($m) => in_array($email, $m['to'], true)));
        foreach (self::buzonFallo(self::SMTP) as $m) { $this->assertSame([$email], $m['to'], 'el correo salió con destinatarios añadidos por el nombre: ' . json_encode($m['to'])); }
        foreach ($mails as $m) {
            $this->assertDoesNotMatchRegularExpression('/^(Bcc|Cc):/mi', (string)$m['cabeceras']);
            // Solo la parte HTML se interpreta como marcado (la alternativa de texto plano lleva el nombre tal cual y no se interpreta).
            $this->assertSame(1, preg_match('~<html.*</html>~is', (string)$m['cuerpo'], $h), 'el correo debe traer parte HTML');
            $this->sinInyeccion($h[0], 'correo de confirmación');
            $this->assertStringNotContainsString('<script>alert', $h[0]);
            $this->assertStringNotContainsString('<svg', $h[0]);
        }
        $this->assertNotNull($tok, 'la persona debe poder recibir el enlace');
        $n = new Navegador(self::$base);
        $g = $n->get('/confirmar-cuenta?token=' . $tok);
        $this->assertSame(200, $g['status']);
        $this->sinInyeccion(str_replace("\0", '', $g['body']), 'formulario de activación');
        $this->assertStringNotContainsString('<script>alert', $g['body']);
        $this->assertStringNotContainsString('<svg onload', $g['body']);
        // El titular corrige y entra.
        $f = $this->formulario(str_replace("\0", '', $g['body']), '/confirmar-cuenta?token=' . $tok);   // el NUL crudo (ver 13b) trunca el analizador DOM
        $this->assertNotNull($f);
        $n2 = $n;
        $ok = $this->post($n2, $f, self::C, ['nombre' => 'Titular', 'apellido' => 'Limpio', 'telefono' => self::TEL]);
        $this->assertSame(200, $ok['status']);
        $this->assertSame(['Titular', 'Limpio'], [$this->usuario($email)['nombre'], $this->usuario($email)['apellido']]);
    }

    public static function controlesDeRegistro(): array
    {
        return ['NUL' => ["Ana\0X"], 'ESC' => ["Ana\x1bX"], 'campana BEL' => ["Ana\x07X"], 'DEL' => ["Ana\x7fX"]];
    }

    /**
     * El registro público admite caracteres de control en nombre/apellido (la activación sí los rechaza). Ahora ese texto se PRECARGA en el
     * formulario del titular: no debe llegar crudo al HTML.
     * @dataProvider controlesDeRegistro
     */
    public function test_Defecto_R4_13b_el_formulario_precargado_no_contiene_bytes_de_control_crudos(string $valor): void
    {
        $email = self::correo('ctl');
        self::q('TRUNCATE intentos_login');
        self::registrarHttp(new Navegador(self::$base), $email, ['nombre' => $valor, 'apellido' => 'Z', 'telefono' => self::TEL, 'password' => self::A]);
        if (!self::q('SELECT 1 FROM usuarios WHERE email=?', [$email])) { $this->addToAssertionCount(1); return; }
        $g = (new Navegador(self::$base))->get('/confirmar-cuenta?token=' . $this->ultimoToken($email));
        $this->assertSame(200, $g['status']);
        $this->assertSame(0, preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $g['body']), 'el formulario precargado contiene un byte de control crudo (' . bin2hex($valor) . ')');
    }

    /** Tipos inesperados: arreglos, objetos JSON, cuerpos enormes y cabeceras de contenido raras no producen 500 ni cambian nada. */
    public function test_R4_14_tipos_inesperados_arreglos_json_y_cuerpos_enormes_no_producen_500_ni_cambian_nada(): void
    {
        $email = self::correo('tipos');
        $this->registrarCon($email, 'Orig', 'Inal', '0991234567', self::A);
        [$n, $f] = $this->form($this->ultimoToken($email));
        $antes = $this->usuario($email);
        $base = $this->cuerpo($f, self::C, ['nombre' => 'Bien', 'apellido' => 'Hecho', 'telefono' => self::TEL]);
        $casos = [
            'nombre como arreglo' => http_build_query(array_diff_key($base, ['nombre' => 1])) . '&nombre%5B%5D=x&nombre%5B%5D=y',
            'nombre como mapa' => http_build_query(array_diff_key($base, ['nombre' => 1])) . '&nombre%5Ba%5D=x',
            'telefono como arreglo' => http_build_query(array_diff_key($base, ['telefono' => 1])) . '&telefono%5B%5D=0987654321',
            'apellido como arreglo anidado' => http_build_query(array_diff_key($base, ['apellido' => 1])) . '&apellido%5B0%5D%5B1%5D=x',
            'password como arreglo' => http_build_query(array_diff_key($base, ['password' => 1])) . '&password%5B%5D=Abcdef123456-x',
            'token como arreglo' => http_build_query(array_diff_key($base, ['token' => 1])) . '&token%5B%5D=' . $f['campos']['token'],
            'nombre de 1 MB' => http_build_query(array_merge($base, ['nombre' => str_repeat('a', 1048576)])),
            'nombre con 5 000 emojis' => http_build_query(array_merge($base, ['nombre' => str_repeat('😀', 5000)])),
        ];
        foreach ($casos as $nombre => $cuerpo) {
            self::q('TRUNCATE intentos_login');
            $r = $n->post($f['accion'], $cuerpo, ['Content-Type: application/x-www-form-urlencoded']);
            $this->assertLessThan(500, $r['status'], "«{$nombre}» provocó {$r['status']}");
            $d = $this->usuario($email);
            $this->assertSame([$antes['nombre'], $antes['apellido'], $antes['telefono'], $antes['password'], '0'], [$d['nombre'], $d['apellido'], $d['telefono'], $d['password'], (string)$d['confirmado']], "«{$nombre}» cambió datos");
        }
        // JSON con tipo de contenido JSON: el endpoint solo entiende formularios; nada cambia.
        self::q('TRUNCATE intentos_login');
        $r = $n->post($f['accion'], json_encode($base), ['Content-Type: application/json']);
        $this->assertLessThan(500, $r['status'], 'JSON provocó ' . $r['status']);
        $this->assertSame('0', (string)$this->usuario($email)['confirmado'], 'un cuerpo JSON activó la cuenta');
        // La operación honesta sigue funcionando después de todo el abuso (el token no se gastó).
        $ok = $this->post($n, $f, self::C, ['nombre' => 'Bien', 'apellido' => 'Hecho', 'telefono' => self::TEL]);
        $this->assertSame(200, $ok['status'], substr(strip_tags($ok['body']), 0, 200));
    }

    /** Un token residual en una cuenta YA confirmada (por ejemplo, de un envío anterior) no sirve para cambiar nombre ni teléfono por POST. */
    public function test_R4_15_token_residual_de_cuenta_confirmada_no_cambia_nombre_ni_telefono_por_post(): void
    {
        $c = self::cliente(['email' => self::correo('resid2'), 'nombre' => 'Real', 'apellido' => 'Cuenta', 'telefono' => '0991234567']);
        $tok = bin2hex(random_bytes(32));
        self::q("UPDATE usuarios SET token_hash=?, token_tipo='confirmacion', token_expira=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR) WHERE id=?", [hash('sha256', $tok), $c['id']]);
        $antes = $this->usuario($c['email']);
        $n = new Navegador(self::$base); $csrf = $n->csrf('/olvide');
        self::q('TRUNCATE intentos_login');
        $r = $n->post('/confirmar-cuenta', ['csrf_token' => $csrf, 'idem_key' => Idem::clave(), 'token' => $tok, 'password' => 'Robada-Clave-9999', 'password2' => 'Robada-Clave-9999', 'nombre' => 'Robado', 'apellido' => 'Total', 'telefono' => '0990000000']);
        $this->assertSame(410, $r['status'], 'el token residual de una cuenta confirmada no debe servir');
        $d = $this->usuario($c['email']);
        $this->assertSame([$antes['nombre'], $antes['apellido'], $antes['telefono'], $antes['password'], $antes['version_sesion']], [$d['nombre'], $d['apellido'], $d['telefono'], $d['password'], $d['version_sesion']], 'se modificó una cuenta ya confirmada');
        $this->assertNoEntra($c['email'], 'Robada-Clave-9999', 'clave robada');
        $this->assertEntra($c['email'], $c['password'], 'clave original');
    }

    public function test_R4_10_precarga_get_head_y_privacidad_de_los_datos(): void
    {
        $email = self::correo('priv');
        $nom = 'Zpriv' . bin2hex(random_bytes(3)); $tel = '09' . random_int(10000000, 99999999);
        $this->registrarCon($email, $nom, 'Apellido' . $nom, $tel, self::A);
        $token = $this->ultimoToken($email);
        $url = '/confirmar-cuenta?token=' . $token;
        $n = new Navegador(self::$base);
        // HEAD y GET con token válido: no activan ni consumen; el HEAD no lleva cuerpo ni datos.
        $head = $n->peticion('HEAD', $url);
        $this->assertSame(200, $head['status']); $this->assertSame('', $head['body']);
        $this->assertStringNotContainsString($nom, json_encode($head['headers']));
        $antes = $this->usuario($email);
        $g = $n->get($url);
        $this->assertStringContainsString($nom, $g['body'], 'el GET con token válido precarga los datos');
        $this->assertStringContainsString($tel, $g['body']);
        $this->assertSame(0, (int)$this->usuario($email)['confirmado']);
        $this->assertSame($antes['token_hash'], $this->usuario($email)['token_hash']);
        // Sin token válido los datos NO aparecen: inexistente, vacío, vencido, de recuperación y residual de una cuenta ya confirmada.
        $conf = self::cliente(['email' => self::correo('priv2'), 'nombre' => 'Zconf' . bin2hex(random_bytes(3)), 'apellido' => 'Yconf']);
        $res = bin2hex(random_bytes(32));
        self::q("UPDATE usuarios SET token_hash=?, token_tipo='confirmacion', token_expira=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR) WHERE id=?", [hash('sha256', $res), $conf['id']]);
        $rec = bin2hex(random_bytes(32));
        $c3 = self::cliente(['email' => self::correo('priv3'), 'nombre' => 'Zrec' . bin2hex(random_bytes(3)), 'apellido' => 'Yrec']);
        self::q("UPDATE usuarios SET token_hash=?, token_tipo='recuperacion', token_expira=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR) WHERE id=?", [hash('sha256', $rec), $c3['id']]);
        $venc = bin2hex(random_bytes(32));
        $c4 = self::cliente(['email' => self::correo('priv4'), 'confirmado' => false, 'nombre' => 'Zvenc' . bin2hex(random_bytes(3))]);
        self::q("UPDATE usuarios SET token_hash=?, token_tipo='confirmacion', token_expira=UTC_TIMESTAMP() - INTERVAL 1 HOUR WHERE id=?", [hash('sha256', $venc), $c4['id']]);
        foreach (['inexistente' => bin2hex(random_bytes(32)), 'vacío' => '', 'residual de cuenta confirmada' => $res, 'de recuperación' => $rec, 'vencido' => $venc] as $caso => $t) {
            $r = (new Navegador(self::$base))->get('/confirmar-cuenta?token=' . $t);
            $this->assertSame(410, $r['status'], $caso);
            foreach ([$conf['nombre'] ?? 'Zconf', $c3['nombre'] ?? 'Zrec', $c4['nombre'] ?? 'Zvenc', $nom, $tel] as $dato) { $this->assertStringNotContainsString((string)$dato, $r['body'], "$caso: la respuesta 410 muestra datos de una cuenta"); }
            $this->assertNull($this->formulario($r['body'], '/confirmar-cuenta'));
        }
        // POST con token inválido y datos válidos: 410 sin reflejar lo almacenado; POST con token válido pero contraseña débil: 422 que refleja SOLO lo enviado.
        $f = $this->formulario($g['body'], $url);
        $r = $n->post($f['accion'], $this->cuerpo($f, self::C, [array_search($token, $f['campos'], true) => bin2hex(random_bytes(32)), 'nombre' => 'Nuevo', 'apellido' => 'Dato', 'telefono' => self::TEL]));
        self::q('TRUNCATE intentos_login');
        $this->assertSame(410, $r['status']);
        $this->assertStringNotContainsString($nom, $r['body']);
        $r = $this->post($n, $f, 'abc', ['nombre' => 'Reflejo', 'apellido' => 'Enviado', 'telefono' => self::TEL]);
        $this->assertSame(422, $r['status']);
        $this->assertSame('Reflejo', $this->valorInput($r['body'], 'nombre'), 'el 422 refleja lo ENVIADO');
        $this->assertStringNotContainsString($nom, $r['body'], 'el 422 no debe volver a mostrar lo almacenado');
        $r = $n->post($f['accion'], http_build_query(array_diff_key($this->cuerpo($f, 'abc'), ['nombre' => 1, 'apellido' => 1, 'telefono' => 1])));
        $this->assertSame(422, $r['status'], 'un POST sin los 3 campos es 422');
        $this->assertSame('', (string)$this->valorInput($r['body'], 'nombre'), 'sin datos enviados no debe aparecer lo almacenado');
        $this->assertSame(0, (int)$this->usuario($email)['confirmado']);
    }

    // ------------------------------------------------------------------ 6. nada se presenta como «verificado»

    public function test_R4_11_ninguna_pantalla_correo_exportacion_ni_documento_presenta_nombre_o_telefono_como_verificados(): void
    {
        $sospechosos = [];
        $buscar = function (string $ruta) use (&$sospechosos): void {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($ruta, \FilesystemIterator::SKIP_DOTS)) as $f) {
                if (!in_array($f->getExtension(), ['php', 'md', 'txt'], true) || str_contains($f->getPathname(), 'QA_INDEPENDIENTE')) { continue; }
                foreach (file($f->getPathname()) ?: [] as $n => $linea) {
                    if (preg_match('/verificad[oa]s?|verified/iu', $linea) && preg_match('/nombre|tel[eé]fono|identidad|datos (personales|de contacto)|apellido/iu', $linea)
                        && !preg_match('/no (se )?verific|sin verific|no (est[aá]n |son )?verificad|declarad|ning[uú]n|nunca|sin ser verificad/iu', $linea)) {
                        $sospechosos[] = str_replace(self::RAIZ . '/', '', $f->getPathname()) . ':' . ($n + 1) . ' ' . trim(mb_substr($linea, 0, 160));
                    }
                }
            }
        };
        foreach (['views', 'classes', 'controllers', 'services', 'docs'] as $d) { $buscar(self::RAIZ . '/' . $d); }
        // Coincidencias revisadas a mano y descartadas (no hablan de nombre/teléfono): el correo del equipo «quedará verificado» (se verifica el CORREO,
        // que es lo que prueba el enlace), el comentario sobre la identidad de la SESIÓN y una fila de auditoría técnica sobre bloqueos de reservas.
        $sospechosos = array_values(array_filter($sospechosos, fn($s) => !preg_match('~^(views/equipo/aceptar\.php:19 |controllers/APIController\.php:262 |docs/AUDITORIA\.md:50 )~', $s)));
        $this->assertSame([], $sospechosos, "textos que podrían presentar nombre/teléfono como verificados:\n" . implode("\n", $sospechosos));
        // Pantallas reales: formulario de activación, éxito, perfil y CRM.
        $email = self::correo('verif');
        $this->registrarCon($email, 'Nombre', 'Declarado', self::TEL, self::A);
        [$n, $f] = $this->form($this->ultimoToken($email));
        $formHtml = $n->get('/confirmar-cuenta?token=' . $f['campos']['token'])['body'];
        $ok = $this->post($n, $f, self::C);
        $dueno = new Navegador(self::$base); self::q('TRUNCATE intentos_login'); $dueno->login('equipo', self::$dueno['email'], self::$dueno['password']);
        $id = $this->usuario($email)['id'];
        foreach (['formulario' => $formHtml, 'éxito' => $ok['body'], 'ficha CRM' => $dueno->get('/admin/clientes/ficha?id=' . $id)['body'], 'listado CRM' => $dueno->get('/admin/clientes?q=' . rawurlencode($email))['body']] as $donde => $html) {
            $texto = html_entity_decode(strip_tags($html));
            $this->assertDoesNotMatchRegularExpression('/identidad verificada|(nombre|tel[eé]fono)[^.]{0,40}verificad[oa]s?/iu', preg_replace('/no se verifican|no verificad[oa]s?|sin verificar/iu', '', $texto), "$donde presenta datos como verificados");
        }
    }
}

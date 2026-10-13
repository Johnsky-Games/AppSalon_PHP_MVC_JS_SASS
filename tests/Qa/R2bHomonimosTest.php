<?php

namespace Tests\Qa;

require_once __DIR__ . '/R2bCase.php';

use Services\AltaClienteService;

/**
 * Ronda 2B, ítem 5d (parte 2): aviso de posibles homónimos al dar de alta CLIENTES (`/admin/clientes/nuevo`) y PROFESIONALES
 * (`/profesionales/crear`). Nunca fusiona ni bloquea; el servidor recalcula y el token HMAC no se puede forjar ni reutilizar;
 * PII mínima; doble clic y concurrencia; los duplicados existentes no se modifican (y el diagnóstico solo informa).
 * HTTP real (8150-8199) y procesos PHP concurrentes reales.
 */
final class R2bHomonimosTest extends R2bCase
{
    private static string $base;
    private static array $p = [];
    /** @var array<string,Navegador> */
    private static array $nav = [];
    private static int $sid;

    public static function setUpBeforeClass(): void
    {
        self::limpiar();
        self::$base = self::servidor([], 'r2b_hom');
        $d = self::propietario(self::correo('dueno'));
        self::$p['dueno'] = $d + ['portal' => 'equipo'];
        foreach (['gerente', 'recepcion', 'finanzas', 'profesional'] as $rol) { self::$p[$rol] = self::miembro($d['id'], $rol) + ['portal' => 'equipo']; }
        self::$p['cliente'] = self::cliente(['email' => self::correo('cli-sesion')]) + ['portal' => 'cliente'];
        self::$sid = self::servicio();
    }

    public static function tearDownAfterClass(): void { self::pararServidor('r2b_hom'); }

    protected function setUp(): void { self::q('TRUNCATE intentos_login'); }

    private function nav(string $quien): Navegador
    {
        if (!isset(self::$nav[$quien])) {
            self::q('TRUNCATE intentos_login');
            $n = new Navegador(self::$base);
            $this->assertSame(302, $n->login(self::$p[$quien]['portal'], self::$p[$quien]['email'], self::$p[$quien]['password'])['status'], $quien);
            self::$nav[$quien] = $n;
        }
        return self::$nav[$quien];
    }

    // ------------------------------------------------------------------ clientes

    private function existente(string $nombre, string $apellido, string $tel = '0991110001'): array
    {
        return self::cliente(['email' => self::correo('exist'), 'nombre' => $nombre, 'apellido' => $apellido, 'telefono' => $tel]);
    }

    private static function telUnico(): string { return '09' . random_int(10000000, 99999999); }

    /** Primer paso del alta: abre el formulario y envía los datos (decision=crear). */
    private function enviarAlta(string $quien, array $datos, ?string $clave = null, array $extra = []): array
    {
        $n = $this->nav($quien);
        $pag = $n->get('/admin/clientes/nuevo');
        $this->assertSame(200, $pag['status'], $quien);
        $k = $clave ?? Navegador::campoDe($pag['body'], 'idem_key');
        $r = $n->post('/admin/clientes/nuevo', http_build_query(array_merge(['csrf_token' => Navegador::csrfDe($pag['body']), 'idem_key' => $k, 'decision' => 'crear'], $datos, $extra)));
        return $r + ['clave' => $k];
    }

    private function tokenDe(string $html): ?string { return Navegador::campoDe($html, 'token_confirmacion'); }

    private function usuarios(): int { return (int)self::escalar('SELECT COUNT(*) FROM usuarios'); }

    public static function homonimos(): array
    {
        // existente: José Pérez Gómez / 0991110001
        return [
            'exacto' => ['José', 'Pérez Gómez', null],
            'sin tildes' => ['Jose', 'Perez Gomez', null],
            'mayúsculas' => ['JOSÉ', 'PÉREZ GÓMEZ', null],
            'orden invertido' => ['Pérez Gómez', 'José', null],
            'repartido distinto' => ['José Pérez', 'Gómez', null],
            'una letra de diferencia' => ['Jose', 'Perez Gomes', null],
            'transposición' => ['Josè', 'Pérez Gómze', null],
            'mismo teléfono, otro nombre' => ['Marcos', 'Vinueza', '0991110001'],
            'espacios y signos' => ['  José  ', ' Pérez-Gómez ', null],
        ];
    }

    /** @dataProvider homonimos */
    public function test_el_alta_de_cliente_avisa_con_homonimos_no_crea_nada_y_el_aviso_no_filtra_datos(string $nombre, string $apellido, ?string $tel): void
    {
        $ex = self::cliente(['email' => 'jose.perez.' . bin2hex(random_bytes(3)) . '@gmail-r2b.test', 'nombre' => 'José', 'apellido' => 'Pérez Gómez', 'telefono' => '0991110001']);
        $antes = $this->usuarios();
        $email = self::correo('nuevo-hom');
        $r = $this->enviarAlta('recepcion', ['nombre' => $nombre, 'apellido' => $apellido, 'email' => $email, 'telefono' => $tel ?? self::telUnico()]);
        $this->assertSame(200, $r['status'], substr(strip_tags($r['body']), -400));
        $this->assertSame($antes, $this->usuarios(), 'el alta con homónimos debe esperar la confirmación');
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM usuarios WHERE email=?', [$email]));
        $this->assertNotNull($this->tokenDe($r['body']), 'falta el token de confirmación en el aviso');
        $this->assertStringContainsString('Es una persona distinta', $r['body']);
        // PII mínima: ni el correo completo ni el teléfono completo del otro cliente.
        $this->assertStringNotContainsString($ex['email'], $r['body']);
        if (($tel ?? '') !== '0991110001') { $this->assertStringNotContainsString('0991110001', $r['body'], 'el aviso muestra el teléfono completo del otro cliente'); }
        $this->assertMatchesRegularExpression('/Correo j\*\*\*@g\*\*\*\.test/', strip_tags($r['body']));
        $this->assertStringContainsString('••••0001', $r['body']);
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM altas_homonimos_decisiones WHERE usuario_creado_id IS NOT NULL'), 'un aviso mostrado no deja decisión de «continuó»');
    }

    public function test_sin_coincidencias_el_alta_se_crea_directo_y_nombres_cortos_no_generan_falsos_avisos(): void
    {
        $this->existente('Al', 'Li');
        $r = $this->enviarAlta('recepcion', ['nombre' => 'Al', 'apellido' => 'Lo', 'email' => self::correo('corto'), 'telefono' => self::telUnico()]);
        $this->assertSame(302, $r['status'], 'nombres de menos de 6 letras no deben disparar «parecido»: ' . substr(strip_tags($r['body']), -300));
        $r = $this->enviarAlta('recepcion', ['nombre' => 'Zenobia', 'apellido' => 'Qwertyuiop', 'email' => self::correo('unico'), 'telefono' => self::telUnico()]);
        $this->assertSame(302, $r['status']);
        $this->assertMatchesRegularExpression('~^/admin/clientes/ficha\?id=\d+&alta=ok$~', $r['location']);
    }

    public function test_mismo_primer_nombre_y_apellido_avisa_y_personas_distintas_se_crean_tras_confirmar_sin_tocar_a_nadie(): void
    {
        $ana = $this->existente('Ana', 'Zambrano ' . bin2hex(random_bytes(2)), self::telUnico());
        $apellido = (string)self::escalar('SELECT apellido FROM usuarios WHERE id=?', [$ana['id']]);
        $snap = self::q('SELECT * FROM usuarios WHERE id=?', [$ana['id']]);
        $email = self::correo('anam');
        $datos = ['nombre' => 'Ana María', 'apellido' => $apellido, 'email' => $email, 'telefono' => self::telUnico()];
        $r = $this->enviarAlta('gerente', $datos);
        $this->assertSame(200, $r['status'], '«Ana» / «Ana María» con el mismo apellido debe avisar');
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM usuarios WHERE email=?', [$email]));
        $c = $this->enviarAlta('gerente', $datos, $r['clave'], ['decision' => 'confirmar', 'token_confirmacion' => $this->tokenDe($r['body'])]);
        $this->assertSame(302, $c['status']);
        // Ambas personas existen y la primera no cambió: no hay fusión ni bloqueo.
        $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM usuarios WHERE email=?', [$email]));
        $this->assertSame($snap, self::q('SELECT * FROM usuarios WHERE id=?', [$ana['id']]));
        // Con otros datos bajo la misma clave ya usada: conflicto de clave, nada nuevo.
        $otro = $this->enviarAlta('gerente', ['telefono' => '0991119999'] + $datos, $r['clave'], ['decision' => 'confirmar', 'token_confirmacion' => $this->tokenDe($r['body'])]);
        $this->assertSame(409, $otro['status']);
        $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM usuarios WHERE email=?', [$email]));
    }

    public function test_confirmacion_legitima_crea_una_cuenta_inactiva_sin_correo_sin_consentimiento_y_registra_la_decision(): void
    {
        $ex = $this->existente('Rosario', 'Villacís ' . bin2hex(random_bytes(2)), self::telUnico());
        $apellido = self::escalar('SELECT apellido FROM usuarios WHERE id=?', [$ex['id']]);
        $snapEx = self::q('SELECT * FROM usuarios WHERE id=?', [$ex['id']]);
        $email = self::correo('rosario');
        $tel = self::telUnico();
        $datos = ['nombre' => 'Rosario', 'apellido' => $apellido, 'email' => $email, 'telefono' => $tel];
        $a = $this->enviarAlta('recepcion', $datos);
        $this->assertSame(200, $a['status']);
        $tok = $this->tokenDe($a['body']);
        $correosAntes = (int)self::escalar('SELECT COUNT(*) FROM correos_salida');
        $notifAntes = (int)self::escalar('SELECT COUNT(*) FROM notificaciones_citas');
        $b = $this->enviarAlta('recepcion', $datos, $a['clave'], ['decision' => 'confirmar', 'token_confirmacion' => $tok]);
        $this->assertSame(302, $b['status'], substr(strip_tags($b['body']), -300));
        $u = self::q('SELECT id, confirmado, admin, token_hash, token_tipo, password FROM usuarios WHERE email=?', [$email]);
        $this->assertCount(1, $u);
        $this->assertSame(0, (int)$u[0]['confirmado']);
        $this->assertSame(0, (int)$u[0]['admin']);
        $this->assertNull($u[0]['token_hash']);
        $this->assertSame('activo', self::escalar('SELECT estado FROM clientes WHERE usuario_id=?', [$u[0]['id']]));
        $this->assertSame('recepcion', self::escalar('SELECT origen FROM clientes WHERE usuario_id=?', [$u[0]['id']]));
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM usuario_accesos WHERE usuario_id=?', [$u[0]['id']]), 'sin acceso laboral');
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM perfiles_usuario WHERE usuarioId=? AND marketing_email=1', [$u[0]['id']]), 'sin consentimiento comercial');
        $this->assertSame($correosAntes, (int)self::escalar('SELECT COUNT(*) FROM correos_salida'), 'el alta no debe enviar ni encolar correo');
        $this->assertSame($notifAntes, (int)self::escalar('SELECT COUNT(*) FROM notificaciones_citas'));
        // Nadie conoce su contraseña: ni vacía, ni el teléfono, ni el correo, ni la de otra persona.
        foreach ([$tel, $email, '', 'password', 'Qa-Passw0rd-12345', 'Clave-123456'] as $intento) {
            self::q('TRUNCATE intentos_login');
            $this->assertNotSame(302, (new Navegador(self::$base))->login('cliente', $email, $intento)['status'], 'se pudo entrar con «' . $intento . '»');
        }
        // La decisión «continuó» queda con los ids coincidentes y la cuenta creada; el duplicado existente no se tocó.
        $d = self::q("SELECT * FROM altas_homonimos_decisiones WHERE usuario_creado_id=?", [$u[0]['id']]);
        $this->assertCount(1, $d);
        $this->assertSame('continuo', $d[0]['decision']);
        $this->assertSame('recepcion', $d[0]['actor_rol']);
        $this->assertContains((int)$ex['id'], json_decode($d[0]['coincidencias_ids'], true));
        $this->assertSame(hash('sha256', $a['clave']), $d[0]['clave_hash']);
        $this->assertSame($snapEx, self::q('SELECT * FROM usuarios WHERE id=?', [$ex['id']]), 'el cliente existente fue modificado');
        // Doble clic / reenvío del mismo formulario confirmado: el mismo resultado, sin otra cuenta, sin repetir el aviso ni la decisión.
        $c = $this->enviarAlta('recepcion', $datos, $a['clave'], ['decision' => 'confirmar', 'token_confirmacion' => $tok]);
        $this->assertSame(302, $c['status']);
        $this->assertSame($b['location'], $c['location']);
        $d2 = $this->enviarAlta('recepcion', $datos, $a['clave']);   // el botón «crear» original tras confirmar
        $this->assertSame(302, $d2['status'], 'tras confirmar no debe volver a mostrarse el aviso');
        $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM usuarios WHERE email=?', [$email]));
        $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM altas_homonimos_decisiones WHERE clave_hash=?', [hash('sha256', $a['clave'])]));
    }

    public function test_tokens_forjados_ajenos_viejos_o_reutilizados_no_crean_nada(): void
    {
        $ex = $this->existente('Teodoro', 'Cevallos ' . bin2hex(random_bytes(2)), self::telUnico());
        $apellido = self::escalar('SELECT apellido FROM usuarios WHERE id=?', [$ex['id']]);
        $datos = fn(string $e) => ['nombre' => 'Teodoro', 'apellido' => $apellido, 'email' => $e, 'telefono' => self::telUnico()];
        $email = self::correo('teo');
        $d = $datos($email);
        $a = $this->enviarAlta('gerente', $d);
        $this->assertSame(200, $a['status']);
        $tok = $this->tokenDe($a['body']);
        $antes = $this->usuarios();
        $conf = fn(string $quien, array $dd, string $clave, $token, string $decision = 'confirmar') => $this->enviarAlta($quien, $dd, $clave, ['decision' => $decision, 'token_confirmacion' => $token]);
        // sin token, vacío, basura, mayúsculas, con espacios, de longitud correcta pero falso, arreglo
        foreach ([null, '', 'abc', strtoupper($tok), " $tok", $tok . '0', str_repeat('0', 64), [$tok]] as $malo) {
            $r = $malo === null
                ? $this->enviarAlta('gerente', $d, $a['clave'], ['decision' => 'confirmar'])
                : $conf('gerente', $d, $a['clave'], $malo);
            $this->assertSame(409, $r['status'], 'token ' . json_encode($malo) . ' aceptado o respuesta inesperada ' . $r['status']);
            $this->assertSame($antes, $this->usuarios());
        }
        // decisión distinta de «confirmar» con un token válido
        foreach (['crear', 'continuo', 'CONFIRMAR', 'confirmar ', ''] as $dec) {
            $r = $conf('gerente', $d, $a['clave'], $tok, $dec);
            $this->assertNotSame(302, $r['status'], "decisión «{$dec}» con token válido creó la cuenta");
            $this->assertSame($antes, $this->usuarios());
        }
        // token válido de OTRO operador (recepción usa el del gerente) y con OTRA clave de operación
        $this->assertSame(409, $this->enviarAlta('recepcion', $d, $a['clave'], ['decision' => 'confirmar', 'token_confirmacion' => $tok])['status'], 'token de otro operador');
        $otraClave = Idem::clave();
        $this->assertSame(409, $conf('gerente', $d, $otraClave, $tok)['status'], 'token con otra clave');
        $this->assertSame($antes, $this->usuarios());
        // aparece un homónimo nuevo mientras tanto: el token viejo deja de valer y se muestra el conjunto real
        $nuevoHom = self::cliente(['email' => self::correo('hom-nuevo'), 'nombre' => 'Teodoro', 'apellido' => $apellido, 'telefono' => self::telUnico()]);
        $antes = $this->usuarios();
        $r = $conf('gerente', $d, $a['clave'], $tok);
        $this->assertSame(409, $r['status'], 'el conjunto de coincidencias cambió y el token viejo se aceptó');
        $this->assertSame($antes, $this->usuarios());
        $tokNuevo = $this->tokenDe($r['body']);
        $this->assertNotNull($tokNuevo);
        $this->assertNotSame($tok, $tokNuevo);
        $this->assertStringContainsString('no corresponde a las coincidencias actuales', html_entity_decode(strip_tags($r['body'])));
        // con el token recalculado por el servidor sí se crea; reutilizarlo para otra alta (otro correo y clave) no
        $ok = $conf('gerente', $d, $a['clave'], $tokNuevo);
        $this->assertSame(302, $ok['status'], substr(strip_tags($ok['body']), -300));
        $d2 = $datos(self::correo('teo2'));
        $b = $this->enviarAlta('gerente', $d2);
        $this->assertSame(200, $b['status']);
        $reuso = $conf('gerente', $d2, $b['clave'], $tokNuevo);
        $this->assertSame(409, $reuso['status'], 'un token ya usado autorizó otra alta');
        // «Cancelar» registra la decisión, no crea nada y lleva a la ficha existente
        $antes2 = $this->usuarios();
        $c = $conf('gerente', $d2, $b['clave'], $this->tokenDe($b['body']), 'cancelar');
        $this->assertSame(302, $c['status']);
        $this->assertMatchesRegularExpression('~^/admin/clientes/ficha\?id=\d+$~', $c['location']);
        $this->assertSame($antes2, $this->usuarios());
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM altas_homonimos_decisiones WHERE decision='cancelo' AND clave_hash=?", [hash('sha256', $b['clave'])]));
        // repetir «cancelar» (doble clic) no duplica la decisión
        $conf('gerente', $d2, $b['clave'], $this->tokenDe($b['body']), 'cancelar');
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM altas_homonimos_decisiones WHERE decision='cancelo' AND clave_hash=?", [hash('sha256', $b['clave'])]));
        // «Cancelar» sin ninguna coincidencia: tampoco crea nada
        $e = $this->enviarAlta('gerente', ['nombre' => 'Zzyzx', 'apellido' => 'Qqwertyuiop', 'email' => self::correo('cancelar-solo'), 'telefono' => self::telUnico()], null, ['decision' => 'cancelar']);
        $this->assertSame(302, $e['status']);
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM usuarios WHERE nombre=?', ['Zzyzx']));
        // los homónimos existentes nunca se modificaron ni se borraron
        $this->assertSame(2, (int)self::escalar('SELECT COUNT(*) FROM usuarios WHERE id IN (?,?)', [$ex['id'], $nuevoHom['id']]));
    }

    public function test_correo_duplicado_y_datos_invalidos_no_crean_ni_pasan_por_el_aviso(): void
    {
        $ex = $this->existente('Ximena', 'Ordóñez ' . bin2hex(random_bytes(2)), self::telUnico());
        $antes = $this->usuarios();
        $r = $this->enviarAlta('recepcion', ['nombre' => 'Otra', 'apellido' => 'Persona' . bin2hex(random_bytes(2)), 'email' => strtoupper($ex['email']), 'telefono' => self::telUnico()]);
        $this->assertSame(409, $r['status'], 'correo equivalente existente');
        $this->assertSame($antes, $this->usuarios());
        // El correo del personal también bloquea el alta (la persona ya tiene cuenta).
        $r = $this->enviarAlta('recepcion', ['nombre' => 'Otra', 'apellido' => 'Persona' . bin2hex(random_bytes(2)), 'email' => self::$p['dueno']['email'], 'telefono' => self::telUnico()]);
        $this->assertSame(409, $r['status']);
        foreach ([['nombre' => '', 'apellido' => 'A', 'email' => self::correo('v'), 'telefono' => '0991112222'], ['nombre' => 'A', 'apellido' => '', 'email' => self::correo('v'), 'telefono' => '0991112222'], ['nombre' => 'A', 'apellido' => 'B', 'email' => 'no-es-correo', 'telefono' => '0991112222'],
                 ['nombre' => 'A', 'apellido' => 'B', 'email' => self::correo('v'), 'telefono' => '12ab'], ['nombre' => str_repeat('n', 61), 'apellido' => 'B', 'email' => self::correo('v'), 'telefono' => '0991112222'], ['nombre' => "Con\x01control", 'apellido' => 'B', 'email' => self::correo('v'), 'telefono' => '0991112222']] as $mal) {
            $r = $this->enviarAlta('recepcion', $mal);
            $this->assertSame(422, $r['status'], json_encode($mal));
        }
        $this->assertSame($antes, $this->usuarios());
        $arr = $this->nav('recepcion');
        $pag = $arr->get('/admin/clientes/nuevo');
        $r = $arr->post('/admin/clientes/nuevo', http_build_query(['csrf_token' => Navegador::csrfDe($pag['body']), 'idem_key' => Idem::clave(), 'nombre' => ['x'], 'apellido' => 'B', 'email' => self::correo('arr'), 'telefono' => '0991112222']));
        $this->assertSame(422, $r['status']);
        $this->assertSame($antes, $this->usuarios());
    }

    public function test_permisos_y_csrf_del_alta_no_filtran_nada(): void
    {
        $ex = $this->existente('Wilfrido', 'Sandoval ' . bin2hex(random_bytes(2)), self::telUnico());
        $antes = $this->usuarios();
        foreach (['recepcion', 'gerente', 'dueno'] as $quien) { $this->assertSame(200, $this->nav($quien)->get('/admin/clientes/nuevo')['status'], $quien); }
        foreach (['finanzas', 'profesional', 'cliente'] as $quien) {
            $n = $this->nav($quien);
            $this->assertSame(403, $n->get('/admin/clientes/nuevo')['status'], $quien);
            $r = $n->post('/admin/clientes/nuevo', http_build_query(['csrf_token' => 'x', 'idem_key' => Idem::clave(), 'nombre' => 'Wilfrido', 'apellido' => self::escalar('SELECT apellido FROM usuarios WHERE id=?', [$ex['id']]), 'email' => self::correo('p'), 'telefono' => '0991112222']));
            $this->assertSame(403, $r['status'], $quien);
            $this->assertStringNotContainsString('Wilfrido', $r['body'], "$quien: el 403 filtra datos de clientes");
        }
        $an = new Navegador(self::$base);
        $this->assertSame(302, $an->get('/admin/clientes/nuevo')['status']);
        $this->assertSame(302, $an->post('/admin/clientes/nuevo', http_build_query(['nombre' => 'A']))['status']);
        // CSRF ausente o falso con un operador autorizado.
        $n = $this->nav('recepcion');
        $pag = $n->get('/admin/clientes/nuevo');
        $campos = ['idem_key' => Idem::clave(), 'decision' => 'crear', 'nombre' => 'Carlos', 'apellido' => 'Nuevo' . bin2hex(random_bytes(2)), 'email' => self::correo('csrf'), 'telefono' => self::telUnico()];
        $this->assertSame(403, $n->post('/admin/clientes/nuevo', http_build_query($campos))['status']);
        $this->assertSame(403, $n->post('/admin/clientes/nuevo', http_build_query(['csrf_token' => str_repeat('a', 64)] + $campos))['status']);
        $this->assertSame($antes, $this->usuarios());
        // Sin clave de operación: no se crea (formulario vencido).
        unset($campos['idem_key']);
        $r = $n->post('/admin/clientes/nuevo', http_build_query(['csrf_token' => Navegador::csrfDe($pag['body'])] + $campos));
        $this->assertSame(422, $r['status']);
        $this->assertSame($antes, $this->usuarios());
    }

    public function test_concurrencia_real_mismo_formulario_seis_procesos_y_misma_cuenta_con_claves_distintas(): void
    {
        $ex = $this->existente('Octavio', 'Landázuri ' . bin2hex(random_bytes(2)), self::telUnico());
        $apellido = self::escalar('SELECT apellido FROM usuarios WHERE id=?', [$ex['id']]);
        $actor = (int)self::$p['recepcion']['id'];
        $email = self::correo('conc-hom');
        $post = ['nombre' => 'Octavio', 'apellido' => $apellido, 'email' => $email, 'telefono' => self::telUnico()];
        $clave = Idem::clave();
        $a = $this->enviarAlta('recepcion', $post, $clave);
        $this->assertSame(200, $a['status']);
        $post['decision'] = 'confirmar'; $post['token_confirmacion'] = $this->tokenDe($a['body']);
        // 6 procesos con la MISMA clave y el MISMO token.
        $res = self::concurrente(array_fill(0, 6, ['accion' => 'altaCliente', 'args' => ['actor' => $actor, 'rol' => 'recepcion', 'post' => $post, 'clave' => $clave]]), 1.2);
        foreach ($res as $x) { $this->assertTrue($x['ok'] ?? false, json_encode($x)); $this->assertContains($x['resultado']['status'], ['ok', 'in_progress'], json_encode($x)); }
        $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM usuarios WHERE email=?', [$email]), 'más de una cuenta por doble clic concurrente');
        $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM altas_homonimos_decisiones WHERE clave_hash=? AND decision=\'continuo\'', [hash('sha256', $clave)]));
        // 6 procesos con CLAVES distintas (y su token propio) para el MISMO correo: gana uno; los demás reciben «ya existe».
        $email2 = self::correo('conc-hom2');
        $tel = self::telUnico();
        $t = [];
        for ($i = 0; $i < 6; $i++) {
            $k = Idem::clave();
            $p = ['nombre' => 'Octavio', 'apellido' => $apellido, 'email' => $email2, 'telefono' => $tel, 'decision' => 'confirmar', 'token_confirmacion' => AltaClienteService::tokenConfirmacion($actor, $k, $this->idsCoincidentes($ex['id'], $email))];
            $t[] = ['accion' => 'altaCliente', 'args' => ['actor' => $actor, 'rol' => 'recepcion', 'post' => $p, 'clave' => $k]];
        }
        $res = self::concurrente($t, 1.2);
        foreach ($res as $x) { $this->assertTrue($x['ok'] ?? false, json_encode($x)); }
        $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM usuarios WHERE email=?', [$email2]), 'misma cuenta creada más de una vez: ' . json_encode(array_column($res, 'resultado')));
        $estados = array_count_values(array_map(fn($x) => $x['resultado']['status'], $res));
        $this->assertSame(1, $estados['ok'] ?? 0, json_encode($estados));
    }

    /** Ids que el servidor considera coincidentes (los dos homónimos de este escenario). */
    private function idsCoincidentes(int $exId, string $emailCreado): array
    {
        $ids = [$exId];
        $otro = self::escalar('SELECT id FROM usuarios WHERE email=?', [$emailCreado]);
        if ($otro) { $ids[] = (int)$otro; }
        return $ids;
    }

    // ------------------------------------------------------------------ profesionales

    private function enviarProf(array $datos, ?string $clave = null, array $extra = [], string $quien = 'gerente'): array
    {
        $n = $this->nav($quien);
        $pag = $n->get('/profesionales/crear');
        $this->assertSame(200, $pag['status'], $quien);
        $k = $clave ?? Navegador::campoDe($pag['body'], 'idem_key');
        $r = $n->post('/profesionales/crear', http_build_query(array_merge(['csrf_token' => Navegador::csrfDe($pag['body']), 'idem_key' => $k, 'decision' => 'crear', 'activo' => '1', 'servicios' => [(string)self::$sid]], $datos, $extra)));
        return $r + ['clave' => $k];
    }

    public static function homonimosProf(): array
    {
        return ['exacto' => ['Valeria Mosquera'], 'sin tildes y mayúsculas' => ['VALERIA MOSQUERA'], 'orden invertido' => ['Mosquera Valeria'], 'una letra' => ['Valeria Mosquerra'], 'con espacios' => ['  Valeria   Mosquera  ']];
    }

    /** @dataProvider homonimosProf */
    public function test_el_alta_de_profesional_avisa_con_homonimos_sin_crear_nada_ni_filtrar_correos_o_telefonos(string $nombre): void
    {
        $sufijo = 'ZZ' . substr(bin2hex(random_bytes(3)), 0, 4);
        self::q('INSERT INTO profesionales(nombre,activo) VALUES (?,1)', ['Valeria Mosquera ' . $sufijo]);
        $existente = (int)self::db()->insert_id;
        $snap = self::q('SELECT * FROM profesionales WHERE id=?', [$existente]);
        $antes = (int)self::escalar('SELECT COUNT(*) FROM profesionales');
        $r = $this->enviarProf(['nombre' => $nombre . ' ' . $sufijo]);
        $this->assertSame(200, $r['status'], substr(strip_tags($r['body']), -300));
        $this->assertSame($antes, (int)self::escalar('SELECT COUNT(*) FROM profesionales'), 'se creó la ficha antes de confirmar');
        $this->assertNotNull(Navegador::campoDe($r['body'], 'token_confirmacion'));
        $this->assertStringNotContainsString('@', strip_tags(explode('Posibles profesionales ya registrados', $r['body'])[1] ?? ''), 'el aviso no debe mostrar correos');
        $this->assertSame($snap, self::q('SELECT * FROM profesionales WHERE id=?', [$existente]));
    }

    public function test_profesional_tokens_forjados_confirmacion_cancelacion_y_doble_clic(): void
    {
        $sufijo = 'YY' . substr(bin2hex(random_bytes(3)), 0, 4);
        self::q('INSERT INTO profesionales(nombre,activo) VALUES (?,1)', ["Camila Torres $sufijo"]);
        $existente = (int)self::db()->insert_id;
        $snap = self::q('SELECT * FROM profesionales WHERE id=?', [$existente]);
        $nombre = "Camila Torres $sufijo";
        $cuenta = fn() => (int)self::escalar('SELECT COUNT(*) FROM profesionales WHERE nombre=?', [$nombre]);
        $a = $this->enviarProf(['nombre' => $nombre]);
        $tok = Navegador::campoDe($a['body'], 'token_confirmacion');
        $this->assertSame(200, $a['status']);
        foreach ([null, '', 'abc', str_repeat('0', 64), strtoupper($tok), $tok . 'x'] as $malo) {
            $extra = ['decision' => 'confirmar'];
            if ($malo !== null) { $extra['token_confirmacion'] = $malo; }
            $r = $this->enviarProf(['nombre' => $nombre], $a['clave'], $extra);
            $this->assertSame(409, $r['status'], 'token ' . json_encode($malo));
            $this->assertSame(1, $cuenta());
        }
        // token de otro operador (propietario con el del gerente) y con otra clave
        $this->assertSame(409, $this->enviarProf(['nombre' => $nombre], $a['clave'], ['decision' => 'confirmar', 'token_confirmacion' => $tok], 'dueno')['status'], 'token de otro operador');
        $this->assertSame(409, $this->enviarProf(['nombre' => $nombre], Idem::clave(), ['decision' => 'confirmar', 'token_confirmacion' => $tok])['status'], 'token con otra clave');
        // casilla vieja
        $this->assertNotSame(302, $this->enviarProf(['nombre' => $nombre], $a['clave'], ['confirmar_homonimo' => '1'])['status'], 'la casilla vieja confirma');
        $this->assertSame(1, $cuenta());
        // cambia el conjunto (nuevo homónimo): el token viejo no vale
        self::q('INSERT INTO profesionales(nombre,activo) VALUES (?,0)', [mb_strtolower($nombre)]);
        $r = $this->enviarProf(['nombre' => $nombre], $a['clave'], ['decision' => 'confirmar', 'token_confirmacion' => $tok]);
        $this->assertSame(409, $r['status']);
        $tokNuevo = Navegador::campoDe($r['body'], 'token_confirmacion');
        $this->assertNotSame($tok, $tokNuevo);
        $ok = $this->enviarProf(['nombre' => $nombre], $a['clave'], ['decision' => 'confirmar', 'token_confirmacion' => $tokNuevo]);
        $this->assertSame(302, $ok['status']);
        $total = (int)self::escalar('SELECT COUNT(*) FROM profesionales WHERE LOWER(nombre)=LOWER(?)', [$nombre]);
        // doble clic con la misma clave: no crea otra ficha
        $this->enviarProf(['nombre' => $nombre], $a['clave'], ['decision' => 'confirmar', 'token_confirmacion' => $tokNuevo]);
        $this->assertSame($total, (int)self::escalar('SELECT COUNT(*) FROM profesionales WHERE LOWER(nombre)=LOWER(?)', [$nombre]));
        // un token ya usado no autoriza otra alta
        $b = $this->enviarProf(['nombre' => $nombre . ' otra']);
        $this->assertSame(302, $b['status'], 'nombre distinto (sin coincidencia) debe crearse directo');
        $c = $this->enviarProf(['nombre' => $nombre], null, ['decision' => 'confirmar', 'token_confirmacion' => $tokNuevo]);
        $this->assertSame(409, $c['status']);
        // cancelar: no crea nada y redirige a la ficha existente
        $can = $this->enviarProf(['nombre' => $nombre], null, ['decision' => 'cancelar']);
        $this->assertSame(302, $can['status']);
        $this->assertStringStartsWith('/profesionales/actualizar?id=', (string)$can['location']);
        $this->assertSame($total, (int)self::escalar('SELECT COUNT(*) FROM profesionales WHERE LOWER(nombre)=LOWER(?)', [$nombre]));
        $this->assertSame($snap, self::q('SELECT * FROM profesionales WHERE id=?', [$existente]), 'el homónimo existente fue modificado');
        // auditoría: una decisión «continuó» por ficha creada confirmada (en la misma transacción) y una «canceló»
        $this->assertGreaterThanOrEqual(1, (int)self::escalar("SELECT COUNT(*) FROM profesionales_homonimos_decisiones WHERE decision='continuo'"));
        $this->assertGreaterThanOrEqual(1, (int)self::escalar("SELECT COUNT(*) FROM profesionales_homonimos_decisiones WHERE decision='cancelo'"));
    }

    public function test_alta_de_profesional_permisos_y_csrf(): void
    {
        $antes = (int)self::escalar('SELECT COUNT(*) FROM profesionales');
        foreach (['recepcion', 'finanzas', 'profesional', 'cliente'] as $quien) {
            $n = $this->nav($quien);
            $this->assertSame(403, $n->get('/profesionales/crear')['status'], $quien);
            $this->assertSame(403, $n->post('/profesionales/crear', http_build_query(['csrf_token' => 'x', 'nombre' => 'Intruso', 'activo' => '1', 'servicios' => [(string)self::$sid], 'idem_key' => Idem::clave()]))['status'], $quien);
        }
        $n = $this->nav('gerente');
        $this->assertSame(403, $n->post('/profesionales/crear', http_build_query(['nombre' => 'SinCsrf', 'activo' => '1', 'servicios' => [(string)self::$sid], 'idem_key' => Idem::clave()]))['status']);
        $this->assertSame($antes, (int)self::escalar('SELECT COUNT(*) FROM profesionales'));
    }

    // ------------------------------------------------------------------ el diagnóstico solo informa

    public function test_el_diagnostico_de_duplicados_solo_informa_y_no_modifica_ninguna_tabla(): void
    {
        // Duplicados reales de cuentas (sin el índice único para forzarlo), profesionales y personas clientes.
        self::q('INSERT INTO profesionales(nombre,activo) VALUES (?,1),(?,1)', ['Dup Diag Uno', 'dup  diag uno']);
        $this->existente('Dup', 'Diagnostico', '0991110077');
        $this->existente('Dup', 'Diagnostico', '0991110077');
        $tablas = array_column(self::q("SELECT table_name t FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' AND table_name NOT IN ('intentos_login','migraciones')"), 't');
        $antes = self::huella($tablas);
        foreach ([['--json'], []] as $args) {
            $p = proc_open(array_merge([PHP_BINARY, '-d', 'variables_order=EGPCS', self::RAIZ . '/scripts/diagnosticar_duplicados.php'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::RAIZ, array_merge(getenv(), ['DB_NAME' => self::nombreBd(), 'APP_ENV' => 'testing']));
            $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
            $this->assertSame(0, proc_close($p), $out . $err);
            if ($args) {
                $j = json_decode($out, true);
                $this->assertIsArray($j);
                $this->assertTrue($j['solo_lectura']);
                $this->assertStringContainsString('dup diag uno', strtolower($out));
            }
        }
        $this->assertSame($antes, self::huella($tablas), 'el diagnóstico de duplicados modificó datos');
    }
}

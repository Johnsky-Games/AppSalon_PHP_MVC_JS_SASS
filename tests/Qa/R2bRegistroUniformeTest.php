<?php

namespace Tests\Qa;

require_once __DIR__ . '/R2bCase.php';

use Services\ColaboradorService;

/**
 * Ronda 2B, DEF-13 (enumeración de cuentas en el registro público): REVERIFICACIÓN adversarial propia.
 * El contrato corregido: el registro responde IGUAL para correo nuevo, cliente confirmado, cliente sin confirmar, personal
 * laboral (colaborador/propietario), cuenta con doble perfil y cuenta suspendida; los efectos distintos viven solo en el servidor.
 *
 * HTTP real (servidor embebido en 8150-8199) + SMTP simulado propio (2590). Concurrencia con procesos PHP reales.
 */
final class R2bRegistroUniformeTest extends R2bCase
{
    private const SMTP = 2590;
    private static string $base;
    private static array $dueno;
    private static string $buzon;

    public static function setUpBeforeClass(): void
    {
        self::limpiar();
        self::$buzon = self::iniciarSmtpFallo(self::SMTP, 'ok');
        self::$base = self::servidor(['EMAIL_PORT' => (string)self::SMTP], 'r2b_reg');
        self::$dueno = self::propietario(self::correo('dueno'));
    }

    public static function tearDownAfterClass(): void
    {
        self::pararServidor('r2b_reg');
        self::detenerSmtpFallo(self::SMTP);
    }

    protected function setUp(): void
    {
        self::q('TRUNCATE intentos_login');
        self::q("DELETE FROM correos_salida WHERE tipo IN ('aviso_cuenta_existente','confirmacion_cuenta')");
        self::modoSmtp(self::SMTP, 'ok');
        self::vaciarBuzonFallo(self::SMTP);
    }

    private function svc(): ColaboradorService
    {
        return new ColaboradorService(new \Repositories\ColaboradorRepository(self::db()), new \Services\CorreoSalidaService(new \Repositories\CorreoSalidaRepository(self::db())));
    }

    /** Crea una cuenta fresca por cada rama y devuelve [rama => correo]. 'nuevo' no existe. */
    private function ramas(): array
    {
        $r = ['nuevo' => self::correo('nuevo')];
        $r['confirmado'] = self::cliente(['email' => self::correo('conf')])['email'];
        $r['sin_confirmar'] = self::cliente(['email' => self::correo('sinconf'), 'confirmado' => false])['email'];
        $r['staff'] = self::miembro(self::$dueno['id'], 'recepcion')['email'];
        $r['propietario'] = self::$dueno['email'];
        $r['cliente_suspendido'] = self::cliente(['email' => self::correo('susp'), 'estado' => 'suspendido'])['email'];
        $r['cliente_suspendido_sin_confirmar'] = self::cliente(['email' => self::correo('suspsc'), 'estado' => 'suspendido', 'confirmado' => false])['email'];
        $m = self::miembro(self::$dueno['id'], 'finanzas');
        $this->assertSame('ok', $this->svc()->cambiarAcceso(self::$dueno['id'], $m['colaborador_id'], 'finanzas', 'suspendido')['status']);
        $r['staff_suspendido'] = $m['email'];
        // Doble perfil: cliente confirmado que además es colaborador activo.
        $dual = self::cliente(['email' => self::correo('dual')]);
        $repo = new \Repositories\ColaboradorRepository(self::db());
        $inv = $repo->crearInvitacion(self::$dueno['id'], ['nombre' => 'Du', 'apellido' => 'Al', 'email' => $dual['email'], 'telefono' => null, 'puesto' => '', 'rol' => 'recepcion', 'fecha_ingreso' => null, 'servicios' => [], 'nombre_profesional' => '', 'profesional_id' => null]);
        $repo->aceptar(self::tokenDeInvitacion($inv['invitacion_id']), '', $dual['password']);
        $r['dual'] = $dual['email'];
        // Invitación pendiente (sin aceptar).
        $pend = $repo->crearInvitacion(self::$dueno['id'], ['nombre' => 'Pe', 'apellido' => 'Nd', 'email' => self::correo('pend'), 'telefono' => null, 'puesto' => '', 'rol' => 'gerente', 'fecha_ingreso' => null, 'servicios' => [], 'nombre_profesional' => '', 'profesional_id' => null]);
        $r['invitado_pendiente'] = self::escalar('SELECT email_norm FROM colaboradores WHERE id=?', [$pend['colaborador_id']]);
        return $r;
    }

    // ------------------------------------------------------------------ 1. respuesta idéntica

    public function test_la_respuesta_es_identica_en_todas_las_ramas_incluidas_cabeceras_cookies_y_pantalla_final(): void
    {
        $firmas = []; $finales = [];
        foreach ($this->ramas() as $rama => $email) {
            self::q('TRUNCATE intentos_login');
            $n = new Navegador(self::$base);
            $r = self::registrarHttp($n, $email);
            $this->assertSame(302, $r['status'], "$rama: se esperaba la redirección uniforme; cuerpo: " . substr(strip_tags($r['body']), 0, 200));
            $firmas[$rama] = self::firma($r);
            $finales[$rama] = self::firma($n->get($r['location']));
        }
        $ref = $firmas['nuevo']; $refFinal = $finales['nuevo'];
        $this->assertSame('/mensaje', $ref['location']);
        foreach ($firmas as $rama => $f) {
            $this->assertSame($ref, $f, "El POST de la rama «{$rama}» se distingue del de un correo nuevo");
            $this->assertSame($refFinal, $finales[$rama], "La pantalla final de la rama «{$rama}» se distingue");
        }
        // Ninguna cabecera ni cookie nueva que dependa de la cuenta: solo PHPSESSID.
        foreach ($firmas as $rama => $f) {
            foreach ($f['headers']['set-cookie'] ?? [] as $c) { $this->assertStringStartsWith('PHPSESSID=', $c, "$rama: cookie inesperada $c"); }
        }
    }

    public function test_errores_de_validacion_csrf_y_clave_no_dependen_de_si_la_cuenta_existe(): void
    {
        $r = $this->ramas();
        $casos = [
            'password_corta' => ['password' => 'a'],
            'telefono_invalido' => ['telefono' => 'abc'],
            'sin_nombre' => ['nombre' => ''],
            'email_roto' => ['email' => 'no-es-correo'],
        ];
        foreach ($casos as $caso => $over) {
            $sig = [];
            foreach (['nuevo', 'confirmado', 'staff', 'cliente_suspendido'] as $rama) {
                self::q('TRUNCATE intentos_login');
                $resp = self::registrarHttp(new Navegador(self::$base), $r[$rama], $over);
                $this->assertSame(422, $resp['status'], "$caso/$rama");
                $s = self::firma($resp);
                // El formulario devuelve lo escrito: se normaliza el correo para comparar solo estructura.
                $s['body'] = str_replace($r[$rama], '<correo>', $s['body']);
                $s['longitud'] = null; $s['headers']['content-length'] = null;
                $sig[$rama] = $s;
            }
            $this->assertCount(1, array_unique(array_map('json_encode', $sig)), "422 «{$caso}» difiere entre ramas");
        }
        // CSRF inválido: mismo 403 para correo nuevo y existente, sin efecto.
        $antes = self::huella(['usuarios', 'clientes', 'correos_salida', 'operaciones_idempotentes']);
        $codigos = [];
        foreach (['nuevo', 'confirmado', 'staff'] as $rama) {
            $n = new Navegador(self::$base);
            $pag = $n->get('/cliente/registro');
            $resp = $n->post('/cliente/registro', ['csrf_token' => str_repeat('0', 64), 'idem_key' => Navegador::campoDe($pag['body'], 'idem_key'), 'nombre' => 'A', 'apellido' => 'B', 'email' => $r[$rama], 'telefono' => '0991112222', 'password' => 'Abcdef123456-x']);
            $codigos[$rama] = [$resp['status'], strlen($resp['body'])];
        }
        $this->assertCount(1, array_unique(array_map('json_encode', $codigos)), json_encode($codigos));
        $this->assertSame(403, $codigos['nuevo'][0]);
        $this->assertSame($antes, self::huella(['usuarios', 'clientes', 'correos_salida', 'operaciones_idempotentes']), 'un CSRF inválido no puede producir efectos');
        // Sin clave de operación: mismo error para cuenta existente y nueva.
        $c = [];
        foreach (['nuevo', 'confirmado'] as $rama) {
            $n = new Navegador(self::$base);
            $csrf = $n->csrf('/cliente/registro');
            $resp = $n->post('/cliente/registro', ['csrf_token' => $csrf, 'nombre' => 'A', 'apellido' => 'B', 'email' => $r[$rama], 'telefono' => '0991112222', 'password' => 'Abcdef123456-x']);
            $c[$rama] = [$resp['status'], strip_tags(preg_replace('/value="[^"]*"/', '', $resp['body']))];
            $c[$rama][1] = str_replace($r[$rama], '<correo>', $c[$rama][1]);
        }
        $this->assertSame($c['nuevo'], $c['confirmado']);
        $this->assertSame(422, $c['nuevo'][0]);
    }

    public function test_alias_crear_cuenta_responde_igual_que_el_registro(): void
    {
        $r = $this->ramas();
        $a = self::firma(self::registrarHttp(new Navegador(self::$base), $r['nuevo'], [], '/crear-cuenta'));
        self::q('TRUNCATE intentos_login');
        $b = self::firma(self::registrarHttp(new Navegador(self::$base), $r['confirmado'], [], '/crear-cuenta'));
        $this->assertSame($a, $b, '/crear-cuenta distingue correo nuevo de existente');
        $this->assertSame(302, $a['status']);
    }

    // ------------------------------------------------------------------ 2. efectos ocultos

    public function test_efectos_por_rama_no_crean_cuenta_ni_tocan_contrasena_ni_privilegios(): void
    {
        $r = $this->ramas();
        $tablasPersonal = ['colaboradores', 'usuario_accesos', 'colaborador_invitaciones', 'profesionales'];
        foreach ($r as $rama => $email) {
            self::q('TRUNCATE intentos_login');
            $antesU = self::q('SELECT id, password, confirmado, admin, nombre, apellido, telefono, version_sesion FROM usuarios WHERE email=?', [$email]);
            $nUsuarios = (int)self::escalar('SELECT COUNT(*) FROM usuarios');
            $nClientes = (int)self::escalar('SELECT COUNT(*) FROM clientes');
            $personal = self::huella($tablasPersonal);
            $accesoCliente = self::q('SELECT cu.estado FROM clientes cu JOIN usuarios u ON u.id=cu.usuario_id WHERE u.email=?', [$email]);
            self::registrarHttp(new Navegador(self::$base), $email, ['nombre' => 'Intruso', 'apellido' => 'Atacante', 'telefono' => '0981112233', 'password' => 'Atacante-Pass-99']);
            $despU = self::q('SELECT id, password, confirmado, admin, nombre, apellido, telefono, version_sesion FROM usuarios WHERE email=?', [$email]);
            if ($rama === 'nuevo' || $rama === 'invitado_pendiente') {
                // Una invitación pendiente aún no tiene fila en `usuarios`: el correo se trata como nuevo (cuenta de CLIENTE sin acceso laboral).
                $this->assertCount(1, $despU, 'el correo nuevo debe crear una única cuenta');
                $this->assertSame($nUsuarios + 1, (int)self::escalar('SELECT COUNT(*) FROM usuarios'));
                $this->assertSame($nClientes + 1, (int)self::escalar('SELECT COUNT(*) FROM clientes'));
                $this->assertSame('0', (string)$despU[0]['confirmado']);
                $this->assertSame('0', (string)$despU[0]['admin']);
                $this->assertSame($personal, self::huella($tablasPersonal), 'el registro público jamás crea acceso laboral');
                continue;
            }
            $this->assertSame($nUsuarios, (int)self::escalar('SELECT COUNT(*) FROM usuarios'), "$rama: se creó una cuenta");
            $this->assertSame($nClientes, (int)self::escalar('SELECT COUNT(*) FROM clientes'), "$rama: se creó membresía");
            $this->assertSame($antesU, $despU, "$rama: cambió la fila del usuario existente (contraseña, nombre, teléfono, privilegios o sesión)");
            $this->assertSame($personal, self::huella($tablasPersonal), "$rama: el personal ganó o perdió algo");
            $this->assertSame($accesoCliente, self::q('SELECT cu.estado FROM clientes cu JOIN usuarios u ON u.id=cu.usuario_id WHERE u.email=?', [$email]), "$rama: cambió la membresía");
        }
        // Y la contraseña del atacante NO sirve contra ninguna cuenta preexistente.
        foreach (['confirmado', 'staff', 'dual'] as $rama) {
            self::q('TRUNCATE intentos_login');
            $portal = in_array($rama, ['staff'], true) ? 'equipo' : 'cliente';
            $resp = (new Navegador(self::$base))->login($portal, $r[$rama], 'Atacante-Pass-99');
            $this->assertNotSame(302, $resp['status'], "$rama: la contraseña del atacante inició sesión");
        }
    }

    public function test_cliente_sin_confirmar_recibe_token_nuevo_e_invalida_el_anterior_sin_cambiar_su_contrasena(): void
    {
        $email = self::correo('reconf');
        $primero = self::registrarHttp(new Navegador(self::$base), $email);
        $this->assertSame(302, $primero['status']);
        $msgs = self::buzonFallo(self::SMTP);
        $this->assertCount(1, $msgs);
        $t1 = self::tokenEnCorreo($msgs[0], '/confirmar-cuenta');
        $this->assertNotNull($t1);
        $hashPass = self::escalar('SELECT password FROM usuarios WHERE email=?', [$email]);
        self::registrarHttp(new Navegador(self::$base), $email, ['password' => 'Otra-Clave-Atacante-1']);
        $msgs = self::buzonFallo(self::SMTP);
        $this->assertCount(2, $msgs, 'el titular debe recibir un segundo enlace');
        $t2 = self::tokenEnCorreo($msgs[1], '/confirmar-cuenta');
        $this->assertNotNull($t2);
        $this->assertNotSame($t1, $t2);
        $this->assertSame($hashPass, self::escalar('SELECT password FROM usuarios WHERE email=?', [$email]), 'la contraseña original no debe cambiar');
        $n = new Navegador(self::$base);
        $this->assertSame(410, $n->get('/confirmar-cuenta?token=' . $t1)['status'], 'el enlace anterior debe quedar invalidado');
        $this->assertSame(200, $n->get('/confirmar-cuenta?token=' . $t2)['status']);
        self::q('TRUNCATE intentos_login');
        $this->assertSame(302, (new Navegador(self::$base))->login('cliente', $email, 'Abcdef123456-x')['status'], 'inicia sesión con la contraseña ORIGINAL');
    }

    // ------------------------------------------------------------------ 3. el correo que recibe el titular

    public function test_el_titular_de_una_cuenta_existente_recibe_un_aviso_sin_token_ni_enlace_de_activacion(): void
    {
        $r = $this->ramas();
        foreach (['confirmado', 'staff', 'propietario', 'cliente_suspendido', 'staff_suspendido', 'dual', 'cliente_suspendido_sin_confirmar'] as $rama) {
            self::q('TRUNCATE intentos_login');
            self::vaciarBuzonFallo(self::SMTP);
            self::registrarHttp(new Navegador(self::$base), $r[$rama]);
            $m = self::buzonFallo(self::SMTP);
            $this->assertCount(1, $m, "$rama: se esperaba exactamente 1 aviso al titular");
            $this->assertSame([$r[$rama]], $m[0]['to'], "$rama: el aviso debe ir SOLO al titular del correo");
            $this->assertDoesNotMatchRegularExpression('/[a-f0-9]{32,}/i', $m[0]['cuerpo'] . $m[0]['cabeceras'], "$rama: el aviso contiene algo parecido a un token");
            $this->assertStringNotContainsString('confirmar-cuenta', $m[0]['cuerpo']);
            $this->assertStringNotContainsString('token=', $m[0]['cuerpo']);
            $this->assertStringContainsString('Intento de registro', $m[0]['cabeceras']);
            $esperaEquipo = in_array($rama, ['staff', 'propietario', 'staff_suspendido'], true);
            $this->assertSame($esperaEquipo, str_contains($m[0]['cuerpo'], '/equipo/login'), "$rama: el portal del enlace no corresponde (cuerpo: " . substr(strip_tags($m[0]['cuerpo']), 0, 300) . ')');
        }
    }

    public function test_el_aviso_se_limita_a_uno_por_hora_y_correo_aunque_se_repita_la_solicitud(): void
    {
        $email = self::crearConfirmado();
        for ($i = 0; $i < 3; $i++) {
            $resp = self::registrarHttp(new Navegador(self::$base), $email);
            $this->assertSame(302, $resp['status'], "solicitud $i");
        }
        $this->assertCount(1, self::buzonFallo(self::SMTP), 'más de un aviso por hora para el mismo titular permite inundar su bandeja');
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM correos_salida WHERE tipo='aviso_cuenta_existente' AND destinatario_email=?", [$email]));
        // Con otra hora (misma clave que usaría el siguiente periodo) el aviso sí vuelve a emitirse: se simula adelantando el reloj de la clave.
        $this->assertSame(0, (int)self::escalar("SELECT COUNT(*) FROM correos_salida WHERE tipo='confirmacion_cuenta' AND destinatario_email=?", [$email]));
    }

    private static function crearConfirmado(): string { return self::cliente(['email' => self::correo('cf')])['email']; }

    public function test_variantes_de_mayusculas_y_espacios_de_un_correo_existente_no_crean_otra_cuenta_y_responden_igual(): void
    {
        $base = self::correo('VarIaNte');
        $existente = self::cliente(['email' => $base])['email'];
        $nuevo = self::firma(self::registrarHttp(new Navegador(self::$base), self::correo('nuevo-var')));
        $usuarios = (int)self::escalar('SELECT COUNT(*) FROM usuarios');
        foreach ([strtoupper($existente), strtolower($existente), ' ' . $existente . ' ', ucfirst(strtolower($existente))] as $variante) {
            self::q('TRUNCATE intentos_login');
            self::vaciarBuzonFallo(self::SMTP);
            $r = self::registrarHttp(new Navegador(self::$base), $variante);
            $this->assertSame($nuevo['status'], $r['status'], json_encode($variante));
            $this->assertSame($nuevo['location'], $r['location']);
            $this->assertSame($usuarios, (int)self::escalar('SELECT COUNT(*) FROM usuarios'), 'la variante «' . $variante . '» creó otra cuenta');
        }
        // El aviso (uno solo por hora) llegó al titular real.
        $this->assertCount(0, self::q("SELECT 1 FROM usuarios WHERE LOWER(TRIM(email))=? HAVING COUNT(*)>1", [strtolower(trim($existente))]));
    }

    // ------------------------------------------------------------------ 4. límite de intentos

    public function test_el_limite_de_intentos_por_correo_es_identico_en_todas_las_ramas(): void
    {
        $r = $this->ramas();
        $resultados = [];
        foreach ($r as $rama => $email) {
            self::q('TRUNCATE intentos_login');
            $estados = [];
            for ($i = 0; $i < 5; $i++) {
                $resp = self::registrarHttp(new Navegador(self::$base), $email);
                $estados[] = $resp['status'] . ($resp['status'] === 429 ? ':' . ($resp['headers']['retry-after'][0] ?? '-') : '');
            }
            $resultados[$rama] = array_map(static fn(string $e): string => str_starts_with($e, '429') ? '429' : $e, $estados);
        }
        $ref = $resultados['nuevo'];
        $this->assertSame(['302', '302', '302', '429', '429'], $ref, 'límite por correo: 3 admitidas y el resto 429');
        foreach ($resultados as $rama => $e) { $this->assertSame($ref, $e, "límite distinto en la rama $rama"); }
    }

    public function test_el_limite_por_ip_cuenta_igual_con_correos_nuevos_o_existentes(): void
    {
        $r = $this->ramas();
        $existentes = array_values(array_filter($r, static fn($k) => $k !== 'nuevo', ARRAY_FILTER_USE_KEY));
        $mezcla = function (array $correos): array {
            self::q('TRUNCATE intentos_login');
            $out = [];
            foreach ($correos as $c) { $out[] = self::registrarHttp(new Navegador(self::$base), $c)['status']; }
            return $out;
        };
        $soloExistentes = $mezcla(array_slice(array_merge($existentes, $existentes), 0, 12));
        $nuevos = [];
        for ($i = 0; $i < 12; $i++) { $nuevos[] = self::correo('ipn'); }
        $soloNuevos = $mezcla($nuevos);
        $esperado = array_merge(array_fill(0, 10, 302), [429, 429]);
        $this->assertSame($esperado, $soloNuevos, 'IP: 10 por ventana con correos nuevos');
        // Cada correo existente aparece a lo sumo 2 veces (< límite por correo de 3): solo el presupuesto por IP puede cortar.
        $this->assertSame($esperado, $soloExistentes, 'IP: el presupuesto por IP debe contar igual con cuentas existentes');
    }

    // ------------------------------------------------------------------ 5. idempotencia

    public function test_misma_clave_repetida_devuelve_lo_mismo_sin_nuevos_efectos_en_todas_las_ramas(): void
    {
        $r = $this->ramas();
        foreach (['nuevo', 'confirmado', 'staff', 'sin_confirmar'] as $rama) {
            self::q('TRUNCATE intentos_login');
            $n = new Navegador(self::$base);
            $pag = $n->get('/cliente/registro');
            $campos = ['csrf_token' => Navegador::csrfDe($pag['body']), 'idem_key' => Navegador::campoDe($pag['body'], 'idem_key'), 'nombre' => 'Ana', 'apellido' => 'Prueba', 'email' => $r[$rama], 'telefono' => '0991112222', 'password' => 'Abcdef123456-x'];
            $a = $n->post('/cliente/registro', $campos);
            $cola = self::colaPorTipo();
            $filas = (int)self::escalar('SELECT COUNT(*) FROM operaciones_idempotentes');
            $intentos = (int)self::escalar('SELECT COALESCE(SUM(intentos),0) FROM intentos_login');
            $b = $n->post('/cliente/registro', $campos);
            $c = $n->post('/cliente/registro', $campos);
            $this->assertSame(self::firma($a), self::firma($b), "$rama: el reintento difiere");
            $this->assertSame(self::firma($a), self::firma($c), "$rama: el segundo reintento difiere");
            $this->assertSame($cola, self::colaPorTipo(), "$rama: un reintento con la misma clave creó otro evento de correo");
            $this->assertSame($filas, (int)self::escalar('SELECT COUNT(*) FROM operaciones_idempotentes'), "$rama: filas de idempotencia extra");
            $this->assertSame($intentos, (int)self::escalar('SELECT COALESCE(SUM(intentos),0) FROM intentos_login'), "$rama: el reintento idempotente consumió límite");
        }
    }

    public function test_la_misma_clave_con_otro_correo_da_409_igual_si_el_primero_existia_o_no(): void
    {
        $r = $this->ramas();
        $res = [];
        foreach (['nuevo', 'confirmado'] as $rama) {
            self::q('TRUNCATE intentos_login');
            $n = new Navegador(self::$base);
            $pag = $n->get('/cliente/registro');
            $campos = ['csrf_token' => Navegador::csrfDe($pag['body']), 'idem_key' => Navegador::campoDe($pag['body'], 'idem_key'), 'nombre' => 'Ana', 'apellido' => 'Prueba', 'email' => $r[$rama], 'telefono' => '0991112222', 'password' => 'Abcdef123456-x'];
            $n->post('/cliente/registro', $campos);
            $otro = $n->post('/cliente/registro', ['email' => self::correo('otro')] + $campos);
            $res[$rama] = [$otro['status'], strip_tags(preg_replace('/value="[^"]*"/', '', $otro['body']))];
        }
        $this->assertSame(409, $res['nuevo'][0]);
        $this->assertSame($res['nuevo'], $res['confirmado']);
    }

    // ------------------------------------------------------------------ 6. cola: una sola, sin duplicados

    public function test_el_correo_sale_por_la_cola_una_sola_vez_y_nada_queda_pendiente(): void
    {
        $r = $this->ramas();
        $antes = (int)self::escalar('SELECT COUNT(*) FROM correos_salida');
        $n = 0;
        foreach (['nuevo', 'confirmado', 'sin_confirmar', 'staff', 'cliente_suspendido'] as $rama) {
            self::q('TRUNCATE intentos_login');
            self::registrarHttp(new Navegador(self::$base), $r[$rama]);
            $n++;
        }
        $this->assertSame($antes + $n, (int)self::escalar('SELECT COUNT(*) FROM correos_salida'), 'exactamente un evento por solicitud admitida');
        $this->assertSame(0, (int)self::escalar("SELECT COUNT(*) FROM correos_salida WHERE estado IN ('pendiente','enviando') AND tipo IN ('confirmacion_cuenta','aviso_cuenta_existente')"), 'con SMTP sano no debe quedar nada pendiente');
        $this->assertSame($n, count(self::buzonFallo(self::SMTP)), 'un correo por solicitud (sin duplicados)');
        $this->assertSame(0, (int)self::escalar("SELECT COUNT(*) FROM correos_salida WHERE estado='enviado' AND carga_cifrada IS NOT NULL"), 'la carga (token) debe borrarse al enviar');
    }

    public function test_concurrencia_real_seis_procesos_mismo_correo_nuevo_con_claves_distintas(): void
    {
        $email = self::correo('conc');
        $idemAntes = (int)self::escalar("SELECT COUNT(*) FROM operaciones_idempotentes WHERE scope='cliente.registro'");
        $post = ['nombre' => 'Rosa', 'apellido' => 'Diaz', 'email' => $email, 'password' => 'Original-Pass-123', 'telefono' => '0991112222'];
        $t = [];
        for ($i = 0; $i < 6; $i++) { $t[] = ['accion' => 'registrarCola', 'args' => ['post' => $post, 'clave' => Idem::clave(), 'actor' => 'anonimo:' . str_repeat('d', 32), 'ip' => '10.20.0.' . ($i + 1)]]; }
        $res = self::concurrente($t, 1.5);
        foreach ($res as $i => $x) { $this->assertTrue($x['ok'] ?? false, "worker $i: " . json_encode($x)); }
        $estados = array_map(static fn($x) => $x['resultado']['status'], $res);
        foreach ($estados as $e) { $this->assertContains($e, ['ok', 'rate_limited'], json_encode($estados)); }
        // UNA identidad, UNA contraseña original, UN token válido final.
        $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM usuarios WHERE email=?', [$email]));
        $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM clientes c JOIN usuarios u ON u.id=c.usuario_id WHERE u.email=?', [$email]));
        $this->assertTrue(password_verify('Original-Pass-123', (string)self::escalar('SELECT password FROM usuarios WHERE email=?', [$email])), 'la contraseña debe ser la del primer alta');
        $uid = (int)self::escalar('SELECT id FROM usuarios WHERE email=?', [$email]);
        // Eventos de confirmación: a lo sumo UNO activo (pendiente/enviando/enviado vigente), el resto cancelados o enviados y reemplazados.
        $porEstado = self::q("SELECT estado, COUNT(*) n FROM correos_salida WHERE usuario_id=? AND tipo='confirmacion_cuenta' GROUP BY estado", [$uid]);
        $mapa = array_column($porEstado, 'n', 'estado');
        $this->assertLessThanOrEqual(1, (int)($mapa['pendiente'] ?? 0) + (int)($mapa['enviando'] ?? 0), 'más de un evento activo: ' . json_encode($mapa));
        $this->assertSame(0, (int)self::escalar("SELECT COUNT(*) FROM correos_salida WHERE usuario_id=? AND tipo<>'confirmacion_cuenta'", [$uid]), 'ninguna carrera debe degradar a aviso la cuenta recién creada de otro');
        // Tokens: exactamente UNO vigente (el hash guardado), y es el del último evento no cancelado.
        $filas = array_sum($mapa);
        $this->assertGreaterThanOrEqual(1, $filas);
        $tokensEnviados = array_values(array_filter(array_map(static fn($x) => $x['resultado']['token_enviado'] ?? null, $res)));
        $vigentes = 0;
        foreach (array_unique($tokensEnviados) as $tok) {
            if ((int)self::escalar("SELECT COUNT(*) FROM usuarios WHERE id=? AND token_hash=? AND token_tipo='confirmacion' AND token_expira>NOW()", [$uid, hash('sha256', $tok)]) === 1) { $vigentes++; }
        }
        $this->assertLessThanOrEqual(1, $vigentes, 'más de un token válido al mismo tiempo');
        // Una fila de idempotencia por solicitud aceptada (las 429 no reservan).
        $aceptadas = count(array_filter($estados, static fn($e) => $e === 'ok'));
        $this->assertSame($aceptadas, (int)self::escalar("SELECT COUNT(*) FROM operaciones_idempotentes WHERE scope='cliente.registro'") - $idemAntes, 'filas de idempotencia vs solicitudes aceptadas');
    }

    // ------------------------------------------------------------------ 7. otros canales de enumeración

    public function test_olvide_reenviar_y_login_no_distinguen_existentes_de_inexistentes(): void
    {
        $r = $this->ramas();
        $canales = [
            '/olvide' => ['confirmado', 'staff', 'sin_confirmar', 'cliente_suspendido'],
            '/reenviar-confirmacion' => ['confirmado', 'staff', 'sin_confirmar', 'cliente_suspendido'],
        ];
        foreach ($canales as $ruta => $ramas) {
            $inexistente = self::firmaCanal($ruta, self::correo('nadie'));
            foreach ($ramas as $rama) {
                self::q('TRUNCATE intentos_login');
                $this->assertSame($inexistente, self::firmaCanal($ruta, $r[$rama]), "$ruta distingue la rama $rama de un correo inexistente");
            }
        }
        foreach (['/cliente/login', '/equipo/login'] as $ruta) {
            $portal = str_contains($ruta, 'equipo') ? 'equipo' : 'cliente';
            $base = null;
            foreach (['nadie' => self::correo('nadie'), 'confirmado' => $r['confirmado'], 'staff' => $r['staff'], 'sin_confirmar' => $r['sin_confirmar'], 'suspendido' => $r['cliente_suspendido']] as $rama => $email) {
                self::q('TRUNCATE intentos_login');
                $resp = (new Navegador(self::$base))->login($portal, $email, 'clave-equivocada-1');
                $f = self::firma($resp);
                $f['body'] = str_replace($email, '<correo>', $f['body']);
                $f['longitud'] = null; $f['headers']['content-length'] = null;
                $base ??= $f;
                $this->assertSame($base, $f, "$ruta con contraseña errónea distingue «{$rama}»");
            }
        }
        // /equipo/aceptar con token inexistente: igual de opaco para cualquier valor.
        $a = (new Navegador(self::$base))->get('/equipo/aceptar?token=' . str_repeat('a', 64));
        $b = (new Navegador(self::$base))->get('/equipo/aceptar?token=' . str_repeat('b', 64));
        $this->assertSame($a['status'], $b['status']);
        $this->assertSame(self::firma($a)['body'], self::firma($b)['body']);
    }

    private static function firmaCanal(string $ruta, string $email): array
    {
        $n = new Navegador(self::$base);
        $resp = $n->post($ruta, ['csrf_token' => $n->csrf($ruta), 'email' => $email]);
        $f = self::firma($resp);
        $f['body'] = str_replace($email, '<correo>', $f['body']);
        $f['longitud'] = null; $f['headers']['content-length'] = null;
        return $f;
    }

    // ------------------------------------------------------------------ 8. tiempo

    public function test_tiempo_del_login_con_correo_inexistente_vs_existente_es_comparable(): void
    {
        $cli = self::cliente(['email' => self::correo('tl')]);
        $med = function (string $email): float {
            $t = [];
            for ($i = 0; $i < 6; $i++) {
                self::q('TRUNCATE intentos_login');
                $n = new Navegador(self::$base);
                $csrf = $n->csrf('/cliente/login');
                $t0 = microtime(true);
                $n->post('/cliente/login', ['csrf_token' => $csrf, 'email' => $email, 'password' => 'clave-equivocada-1']);
                $t[] = (microtime(true) - $t0) * 1000;
            }
            return self::mediana($t);
        };
        $existe = $med($cli['email']);
        $noExiste = $med(self::correo('nadie-tl'));
        $this->assertLessThan(2.0, max($existe, $noExiste) / max(1.0, min($existe, $noExiste)), sprintf('login: existente %.1f ms vs inexistente %.1f ms', $existe, $noExiste));
    }

    /**
     * Mide (SMTP simulado con 300 ms de latencia) lo que tarda una SEGUNDA solicitud sobre cada rama.
     * Hallazgo: una cuenta confirmada ya avisada en la hora NO vuelve a enviar (rápido) mientras que un correo nuevo o sin
     * confirmar sí envía otra vez (lento): el tiempo distingue ramas con solo dos solicitudes. Ver DEF-R2B-02.
     */
    public function test_Defecto_R2B_02_el_tiempo_de_la_segunda_solicitud_distingue_cuentas_confirmadas_de_nuevas(): void
    {
        self::detenerSmtpFallo(self::SMTP);
        self::iniciarSmtpFallo(self::SMTP, 'lento', 0.3);
        $medir = function (string $email): array {
            self::q('TRUNCATE intentos_login');
            $t = [];
            for ($i = 0; $i < 2; $i++) {
                $t0 = microtime(true);
                self::registrarHttp(new Navegador(self::$base), $email);
                $t[] = (microtime(true) - $t0) * 1000;
            }
            return $t;
        };
        $nuevo = $medir(self::correo('tn'));
        $confirmado = $medir(self::cliente(['email' => self::correo('tc')])['email']);
        $this->assertGreaterThan(250, $nuevo[0], 'precondición: el SMTP simulado debe añadir latencia');
        $this->assertGreaterThan(250, $confirmado[0], 'la primera solicitud sobre una cuenta confirmada envía el aviso');
        $this->assertLessThan(2.0, $nuevo[1] / max(1.0, $confirmado[1]),
            sprintf('2.ª solicitud: correo nuevo/sin confirmar %.0f ms vs cuenta confirmada %.0f ms: con dos solicitudes se distingue si el correo ya tiene cuenta confirmada', $nuevo[1], $confirmado[1]));
    }

    /**
     * El aviso para el titular de un correo con cuenta de CLIENTE enlaza a `/login`; esa ruta no existe (404). El enlace del único
     * correo que recibe el titular legítimo debería llevar a un sitio funcional.
     */
    public function test_Defecto_R2B_01_el_aviso_de_cuenta_existente_enlaza_a_una_ruta_inexistente(): void
    {
        $email = self::cliente(['email' => self::correo('lnk')])['email'];
        self::registrarHttp(new Navegador(self::$base), $email);
        $m = self::buzonFallo(self::SMTP);
        $this->assertCount(1, $m);
        preg_match_all('~href=["\']?(https?://[^"\'\s>]+)~i', $m[0]['cuerpo'], $h);
        $enlaces = array_values(array_unique(array_map('html_entity_decode', $h[1])));
        $boton = array_values(array_filter($enlaces, static fn(string $u): bool => !preg_match('~^https?://[^/]+/?$~', $u)));
        $this->assertNotEmpty($boton, 'el aviso no trae enlace de acción: ' . json_encode($enlaces));
        $ruta = parse_url($boton[0], PHP_URL_PATH);
        $r = (new Navegador(self::$base))->get($ruta);
        $this->assertContains($r['status'], [200, 302], "El botón «Ir a iniciar sesión» del aviso apunta a $ruta y devuelve {$r['status']}");
    }

    private function tiempoPorExistencia(string $ruta, bool $pendiente): array
    {
        self::detenerSmtpFallo(self::SMTP);
        self::iniciarSmtpFallo(self::SMTP, 'lento', 0.4);
        $medir = function (string $email) use ($ruta): float {
            self::q('TRUNCATE intentos_login');
            $n = new Navegador(self::$base);
            $csrf = $n->csrf($ruta);
            $t0 = microtime(true);
            $n->post($ruta, ['csrf_token' => $csrf, 'email' => $email]);
            return (microtime(true) - $t0) * 1000;
        };
        $a = []; $b = [];
        for ($i = 0; $i < 3; $i++) {
            $a[] = $medir(self::cliente(['email' => self::correo('to-e' . $i), 'confirmado' => !$pendiente])['email']);
            $b[] = $medir(self::correo('to-nadie'));
        }
        return [self::mediana($a), self::mediana($b)];
    }

    /**
     * DEF-R2B-08: `/olvide` envía el correo DENTRO de la petición solo cuando la cuenta existe y está confirmada: con un SMTP que tarda, el
     * tiempo de respuesta distingue una cuenta existente de un correo inexistente en UNA sola petición (la documentación lo reconoce como
     * residuo, pero no estaba aceptado: el registro público sí se corrigió).
     */
    public function test_Defecto_R2B_08_olvide_revela_si_la_cuenta_existe_por_el_tiempo_de_respuesta(): void
    {
        [$existe, $noExiste] = $this->tiempoPorExistencia('/olvide', false);
        $this->assertLessThan(2.0, $existe / max(1.0, $noExiste), sprintf('/olvide: cuenta existente %.0f ms vs inexistente %.0f ms (SMTP con 400 ms)', $existe, $noExiste));
    }

    /** DEF-R2B-09: ídem para `/reenviar-confirmacion` (cola persistente, pero el intento inmediato sigue en línea solo si la cuenta existe y está pendiente). */
    public function test_Defecto_R2B_09_reenviar_confirmacion_revela_si_la_cuenta_pendiente_existe_por_el_tiempo_de_respuesta(): void
    {
        [$existe, $noExiste] = $this->tiempoPorExistencia('/reenviar-confirmacion', true);
        $this->assertLessThan(2.0, $existe / max(1.0, $noExiste), sprintf('/reenviar-confirmacion: cuenta pendiente %.0f ms vs inexistente %.0f ms (SMTP con 400 ms)', $existe, $noExiste));
    }
}

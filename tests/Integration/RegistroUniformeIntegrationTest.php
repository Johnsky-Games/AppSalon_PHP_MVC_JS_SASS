<?php

namespace Tests\Integration;

use Classes\CargaCifrada;
use Classes\Idempotencia;
use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\UsuarioRepository;
use Services\AuthService;

/**
 * DEF-13: el registro público responde IGUAL para correo nuevo, cliente confirmado, cliente sin confirmar, personal laboral
 * (colaborador y propietario), cuenta con doble perfil y cuenta suspendida. La diferencia queda en los efectos del servidor
 * (aviso al titular, token nuevo, alta), que aquí se comprueban en la base y en el buzón del SMTP simulado.
 *
 * HTTP real: servidor embebido de PHP + SMTP simulado propios (puertos 8110-8119 / 2610-2619). Concurrencia: procesos PHP reales.
 */
final class RegistroUniformeIntegrationTest extends TestCase
{
    private const SUF = '@regunif.test';
    private const PASS = 'ClaveSegura123';
    private const PUERTO_SMTP = 2610;

    private static \mysqli $db;
    private static string $base = '';
    /** @var resource|null */
    private static $servidor = null;
    /** @var resource|null */
    private static $smtp = null;
    private static string $buzon = '';
    private static int $seq = 0;

    public static function setUpBeforeClass(): void
    {
        // Estas pruebas verifican la entrega en línea tras el commit (camino CORREOS_ENVIO_INMEDIATO=1); el valor por defecto
        // (solo cola + worker) se prueba en ColaSinEnvioEnLineaIntegrationTest y en la suite Qa.
        $_ENV['CORREOS_ENVIO_INMEDIATO'] = '1'; putenv('CORREOS_ENVIO_INMEDIATO=1');
        self::$db = new \mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', getenv('DB_NAME') ?: 'appsalon_test', (int)(getenv('DB_PORT') ?: 3306));
        self::$db->set_charset('utf8mb4');
        ActiveRecord::setDB(self::$db);
        validar_base_datos_prueba(self::$db);

        self::$buzon = sys_get_temp_dir() . '/regunif_buzon_' . self::PUERTO_SMTP . '.json';
        self::$smtp = proc_open([PHP_BINARY, __DIR__ . '/../Qa/smtp_qa.php', (string)self::PUERTO_SMTP, self::$buzon], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p1);
        self::esperarPuerto(self::PUERTO_SMTP);

        $puerto = 8110;
        $log = sys_get_temp_dir() . '/regunif_web_' . $puerto . '.log';
        $sesiones = sys_get_temp_dir() . '/regunif_sess_' . $puerto;
        @mkdir($sesiones, 0777, true);
        self::$servidor = proc_open(
            [PHP_BINARY, '-d', 'variables_order=EGPCS', '-d', 'error_log=' . $log, '-d', 'session.save_path=' . $sesiones, '-S', "127.0.0.1:$puerto", '-t', __DIR__ . '/../../public', __DIR__ . '/../fixtures/router_http.php'],
            [1 => ['file', $log . '.out', 'a'], 2 => ['file', $log . '.err', 'a']], $p2, __DIR__ . '/../..',
            array_merge(getenv(), self::entornoCorreo(), ['APP_ENV' => 'testing'])
        );
        self::esperarPuerto($puerto);
        self::$base = "http://127.0.0.1:$puerto";
    }

    public static function tearDownAfterClass(): void
    {
        unset($_ENV['CORREOS_ENVIO_INMEDIATO']); putenv('CORREOS_ENVIO_INMEDIATO');
        foreach ([self::$servidor, self::$smtp] as $p) {
            if (is_resource($p)) { @proc_terminate($p); @proc_close($p); }
        }
        self::limpiar();
        @unlink(self::$buzon);
    }

    protected function setUp(): void
    {
        ActiveRecord::setDB(self::$db);
        self::limpiar();
        file_put_contents(self::$buzon, '[]');
    }

    protected function tearDown(): void
    {
        self::$db->query('DROP TRIGGER IF EXISTS test_fail_correo_regunif');
        self::limpiar();
    }

    private static function entornoCorreo(): array
    {
        return ['EMAIL_HOST' => '127.0.0.1', 'EMAIL_PORT' => (string)self::PUERTO_SMTP, 'EMAIL_USER' => 't', 'EMAIL_PASSWORD' => 't', 'EMAIL_FORCE_SMTP' => '1'];
    }

    private static function esperarPuerto(int $puerto): void
    {
        for ($i = 0; $i < 60; $i++) {
            $c = @fsockopen('127.0.0.1', $puerto, $en, $es, 0.2);
            if ($c) { fclose($c); return; }
            usleep(100000);
        }
        throw new \RuntimeException("No arrancó el servicio auxiliar en el puerto $puerto");
    }

    private static function limpiar(): void
    {
        $s = self::SUF;
        self::$db->query("DELETE FROM correos_salida WHERE destinatario_email LIKE '%$s'");
        self::$db->query("DELETE FROM colaboradores WHERE email_norm LIKE '%$s'");
        self::$db->query("DELETE FROM usuarios WHERE email LIKE '%$s'");
        self::$db->query("DELETE FROM operaciones_idempotentes WHERE scope='cliente.registro'");
        self::$db->query("DELETE FROM intentos_login WHERE tipo IN ('ip_register','email_register','ip_login','email_login','ip_recovery','email_recovery','ip_reconfirm','email_reconfirm')");
    }

    // ------------------------------------------------------------------ cuentas de prueba

    private function correo(string $pref): string
    {
        return $pref . '.' . getmypid() . '.' . (++self::$seq) . '.' . bin2hex(random_bytes(2)) . self::SUF;
    }

    /**
     * Estados: nuevo | confirmado | sin_confirmar | colaborador | propietario | dual | suspendido
     * @return array{tipo:string,email:string,id:?int,hash:?string}
     */
    private function cuenta(string $tipo): array
    {
        $email = $this->correo($tipo);
        if ($tipo === 'nuevo') {
            return ['tipo' => $tipo, 'email' => $email, 'id' => null, 'hash' => null];
        }
        $hash = password_hash('Original-Pass-123', PASSWORD_BCRYPT);
        $laboral = in_array($tipo, ['colaborador', 'propietario', 'dual'], true);
        $rol = $tipo === 'propietario' ? 'propietario' : 'recepcion';
        $admin = $tipo === 'propietario' ? 1 : 0;
        $conf = $tipo === 'sin_confirmar' ? 0 : 1;
        $tokenHash = $conf === 0 ? "'" . hash('sha256', 'token-previo-' . $email) . "'" : 'NULL';
        $tokenTipo = $conf === 0 ? "'confirmacion'" : 'NULL';
        $expira = $conf === 0 ? "DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 HOUR)" : 'NULL';
        self::$db->query("INSERT INTO usuarios(nombre,apellido,email,password,telefono,admin,confirmado,token_hash,token_tipo,token_expira)
            VALUES ('Titular','Real','$email','$hash','0991234567',$admin,$conf,$tokenHash,$tokenTipo,$expira)");
        $id = (int)self::$db->insert_id;
        if ($laboral) {
            self::$db->query("INSERT INTO usuario_accesos(usuario_id,rol,activo) VALUES ($id,'$rol',1)");
            self::$db->query("INSERT INTO colaboradores(usuario_id,email_norm,nombre,apellido,rol,estado,aceptado_en,codigo) VALUES ($id,'$email','Titular','Real','$rol','activo',UTC_TIMESTAMP(),CONCAT('T-',$id))");
        }
        if (in_array($tipo, ['confirmado', 'sin_confirmar', 'dual', 'suspendido'], true)) {
            $estado = $tipo === 'suspendido' ? 'suspendido' : 'activo';
            self::$db->query("INSERT INTO clientes(usuario_id,estado,origen,suspendido_motivo) VALUES ($id,'$estado','registro_publico'," . ($tipo === 'suspendido' ? "'Prueba'" : 'NULL') . ")");
        }
        return ['tipo' => $tipo, 'email' => $email, 'id' => $id, 'hash' => $hash];
    }

    private const TIPOS = ['nuevo', 'confirmado', 'sin_confirmar', 'colaborador', 'propietario', 'dual', 'suspendido'];

    // ------------------------------------------------------------------ cliente HTTP

    private function http(): ClienteHttpRegistro
    {
        return new ClienteHttpRegistro(self::$base);
    }

    /**
     * Envía el formulario público de registro como lo haría un navegador (GET con CSRF + clave de operación, POST, y GET del destino).
     * @return array{pagina:array,post:array,destino:?array,clave:string,cliente:ClienteHttpRegistro}
     */
    private function enviarRegistro(string $email, array $cambios = [], ?ClienteHttpRegistro $c = null, ?string $clave = null, string $ruta = '/cliente/registro'): array
    {
        $c ??= $this->http();
        $pagina = $c->get($ruta);
        $csrf = ClienteHttpRegistro::campo($pagina['body'], 'csrf_token');
        $clave ??= ClienteHttpRegistro::campo($pagina['body'], 'idem_key');
        $this->assertNotNull($csrf, 'el formulario debe traer CSRF');
        $this->assertNotNull($clave, 'el formulario debe traer su clave de operación');
        $post = $c->post($ruta, $cambios + ['csrf_token' => $csrf, 'idem_key' => $clave, 'nombre' => 'Nueva', 'apellido' => 'Persona', 'telefono' => '0991112222', 'email' => $email, 'password' => 'Abcdef123456']);
        $destino = $post['location'] !== null ? $c->get($post['location']) : null;
        return ['pagina' => $pagina, 'post' => $post, 'destino' => $destino, 'clave' => $clave, 'cliente' => $c];
    }

    /** Huella comparable de una respuesta: estado, destino, cabeceras (sin Date ni valores de sesión) y cuerpo con tokens normalizados. */
    private static function firma(array $r): array
    {
        $h = $r['headers'];
        unset($h['date']);
        foreach (($h['set-cookie'] ?? []) as $i => $v) {
            $h['set-cookie'][$i] = preg_replace('/=[A-Za-z0-9,-]+;/', '=SESION;', $v, 1);
        }
        ksort($h);
        // Se normaliza el eco del propio correo que escribió quien envía el formulario (no es información de la cuenta).
        $cuerpo = preg_replace(['/[a-f0-9]{64}/', '/[a-f0-9]{32}/', '/[A-Za-z0-9._-]+@regunif\.test/'], ['<HEX64>', '<HEX32>', '<CORREO>'], $r['body']);
        return ['status' => $r['status'], 'location' => $r['location'], 'headers' => $h, 'body' => $cuerpo];
    }

    // ------------------------------------------------------------------ buzón y cola

    /** @return array<int,array{to:array,cuerpo:string}> */
    private static function buzon(): array
    {
        return json_decode((string)@file_get_contents(self::$buzon), true) ?: [];
    }

    private static function mensajesPara(string $email): array
    {
        return array_values(array_filter(self::buzon(), static fn($m) => in_array($email, $m['to'], true)));
    }

    private function fila(string $sql): array
    {
        $r = self::$db->query($sql);
        $this->assertNotFalse($r, self::$db->error . ' :: ' . $sql);
        return $r->fetch_all(MYSQLI_ASSOC);
    }

    private function eventos(string $email, ?string $tipo = null): array
    {
        $e = self::$db->real_escape_string($email);
        return $this->fila("SELECT id, tipo, estado, clave_unica, carga_cifrada FROM correos_salida WHERE destinatario_email='$e'" . ($tipo ? " AND tipo='" . self::$db->real_escape_string($tipo) . "'" : '') . ' ORDER BY id');
    }

    private function usuarios(string $email): array
    {
        $e = self::$db->real_escape_string($email);
        return $this->fila("SELECT id,nombre,apellido,password,confirmado,token_hash,token_tipo,token_expira,admin FROM usuarios WHERE email='$e'");
    }

    // ================================================================== 1. respuesta pública uniforme

    public function test_la_respuesta_publica_es_identica_para_nuevo_confirmado_sin_confirmar_personal_y_suspendido(): void
    {
        $firmas = [];
        foreach (self::TIPOS as $tipo) {
            self::limpiarLimites();
            $cuenta = $this->cuenta($tipo);
            $r = $this->enviarRegistro($cuenta['email']);
            $this->assertSame(302, $r['post']['status'], "$tipo: " . substr($r['post']['body'], 0, 200));
            $this->assertSame('/mensaje', $r['post']['location'], $tipo);
            $this->assertSame(200, $r['destino']['status'], $tipo);
            $firmas[$tipo] = ['post' => self::firma($r['post']), 'destino' => self::firma($r['destino'])];
        }
        $referencia = $firmas['nuevo'];
        foreach (self::TIPOS as $tipo) {
            $this->assertSame($referencia['post'], $firmas[$tipo]['post'], "POST de «{$tipo}» distinguible del de un correo nuevo");
            $this->assertSame($referencia['destino'], $firmas[$tipo]['destino'], "Pantalla final de «{$tipo}» distinguible de la de un correo nuevo");
        }
        $this->assertStringContainsString('Si el correo es válido', $referencia['destino']['body']);
        $this->assertStringNotContainsString('ya está registrado', $referencia['destino']['body']);
    }

    public function test_las_alertas_y_el_cuerpo_de_una_repeticion_con_la_misma_clave_tampoco_distinguen(): void
    {
        $firmas = [];
        foreach (['nuevo', 'confirmado', 'colaborador'] as $tipo) {
            self::limpiarLimites();
            $cuenta = $this->cuenta($tipo);
            $a = $this->enviarRegistro($cuenta['email']);
            // Doble clic / reintento: mismo navegador, misma clave de operación.
            $b = $a['cliente']->post('/cliente/registro', ['csrf_token' => ClienteHttpRegistro::campo($a['cliente']->get('/cliente/registro')['body'], 'csrf_token'), 'idem_key' => $a['clave'], 'nombre' => 'Nueva', 'apellido' => 'Persona', 'telefono' => '0991112222', 'email' => $cuenta['email'], 'password' => 'Abcdef123456']);
            $this->assertSame(302, $b['status'], $tipo);
            $firmas[$tipo] = [self::firma($a['post']), self::firma($b)];
        }
        $this->assertSame($firmas['nuevo'], $firmas['confirmado']);
        $this->assertSame($firmas['nuevo'], $firmas['colaborador']);
    }

    // ================================================================== 2. efectos ocultos distintos

    public function test_los_efectos_en_el_servidor_son_distintos_y_correctos_por_estado(): void
    {
        $cuentas = [];
        foreach (self::TIPOS as $tipo) {
            self::limpiarLimites();
            $cuentas[$tipo] = $this->cuenta($tipo);
            $this->enviarRegistro($cuentas[$tipo]['email'], ['password' => 'Intruso-Pass-999', 'nombre' => 'Intruso']);
        }

        // Correo nuevo: alta + membresía + confirmación (token válido en el correo recibido).
        $nuevo = $cuentas['nuevo']['email'];
        $u = $this->usuarios($nuevo);
        $this->assertCount(1, $u);
        $this->assertSame('0', (string)$u[0]['confirmado']);
        $this->assertSame('0', (string)$u[0]['admin']);
        $this->assertTrue(password_verify('Intruso-Pass-999', $u[0]['password']));
        $this->assertSame(1, (int)self::$db->query("SELECT COUNT(*) n FROM clientes WHERE usuario_id={$u[0]['id']} AND estado='activo'")->fetch_assoc()['n']);
        $ev = $this->eventos($nuevo);
        $this->assertCount(1, $ev);
        $this->assertSame('confirmacion_cuenta', $ev[0]['tipo']);
        $this->assertSame('enviado', $ev[0]['estado'], 'la entrega inmediata tras el commit debió enviar el correo');
        $msgs = self::mensajesPara($nuevo);
        $this->assertCount(1, $msgs);
        $this->assertSame(1, preg_match('~/confirmar-cuenta\?token=([a-f0-9]{64})~', $msgs[0]['cuerpo'], $m), 'el correo de confirmación debe traer el enlace');
        $this->assertSame(hash('sha256', $m[1]), $u[0]['token_hash'], 'el token del correo corresponde al hash guardado');

        // Cliente sin confirmar: misma cuenta, contraseña INTACTA, token nuevo, confirmación encolada y entregada.
        $sc = $cuentas['sin_confirmar'];
        $u = $this->usuarios($sc['email']);
        $this->assertCount(1, $u);
        $this->assertSame($sc['hash'], $u[0]['password'], 'no se debe pisar la contraseña de una cuenta existente');
        $this->assertSame('Titular', $u[0]['nombre']);
        $this->assertNotSame(hash('sha256', 'token-previo-' . $sc['email']), $u[0]['token_hash'], 'debe emitirse un token nuevo');
        $this->assertSame('0', (string)$u[0]['confirmado']);
        $ev = $this->eventos($sc['email']);
        $this->assertCount(1, $ev);
        $this->assertSame('confirmacion_cuenta', $ev[0]['tipo']);
        $mensaje = self::mensajesPara($sc['email']);
        $this->assertCount(1, $mensaje);
        $this->assertSame(1, preg_match('~/confirmar-cuenta\?token=([a-f0-9]{64})~', $mensaje[0]['cuerpo'], $m));
        $this->assertSame(hash('sha256', $m[1]), $u[0]['token_hash']);

        // Cliente confirmado, personal, doble perfil y suspendido: nada se toca; un solo aviso sin token al titular.
        foreach (['confirmado' => 'cliente', 'colaborador' => 'equipo', 'propietario' => 'equipo', 'dual' => 'cliente', 'suspendido' => 'cliente'] as $tipo => $portal) {
            $c = $cuentas[$tipo];
            $u = $this->usuarios($c['email']);
            $this->assertCount(1, $u, $tipo);
            $this->assertSame($c['hash'], $u[0]['password'], "$tipo: contraseña intacta");
            $this->assertSame('Titular', $u[0]['nombre'], "$tipo: datos intactos");
            $this->assertNull($u[0]['token_hash'], "$tipo: no se emite token");
            $this->assertSame('1', (string)$u[0]['confirmado'], $tipo);
            $ev = $this->eventos($c['email']);
            $this->assertCount(1, $ev, "$tipo: exactamente un evento");
            $this->assertSame('aviso_cuenta_existente', $ev[0]['tipo'], $tipo);
            $this->assertSame('enviado', $ev[0]['estado'], $tipo);
            $this->assertSame(1, preg_match('/^aviso_existente:/', $ev[0]['clave_unica']), $tipo);
            $mensajes = self::mensajesPara($c['email']);
            $this->assertCount(1, $mensajes, "$tipo: un correo de aviso en el buzón");
            $this->assertStringNotContainsString('confirmar-cuenta?token', $mensajes[0]['cuerpo'], "$tipo: el aviso no lleva token");
            $esperaEquipo = $portal === 'equipo';
            $this->assertSame($esperaEquipo, str_contains($mensajes[0]['cuerpo'], '/equipo/login'), "$tipo: portal del aviso = $portal");
        }
        // Ni usuarios ni membresías adicionales por los intentos de registro (7 cuentas = 6 preexistentes + 1 alta).
        $this->assertSame(7, (int)self::$db->query("SELECT COUNT(*) n FROM usuarios WHERE email LIKE '%" . self::SUF . "'")->fetch_assoc()['n']);
        // Un cliente suspendido sigue sin poder entrar y el intento no lo reactivó.
        $this->assertSame('suspendido', self::$db->query("SELECT estado FROM clientes WHERE usuario_id={$cuentas['suspendido']['id']}")->fetch_assoc()['estado']);
    }

    // ================================================================== 3. aviso máximo 1/hora y doble clic

    public function test_el_aviso_a_una_cuenta_existente_se_limita_a_uno_por_hora_y_correo(): void
    {
        $c = $this->cuenta('confirmado');
        for ($i = 0; $i < 3; $i++) {
            self::limpiarLimites();
            $this->enviarRegistro($c['email']);   // claves de operación distintas
        }
        $this->assertCount(1, $this->eventos($c['email'], 'aviso_cuenta_existente'), 'tres solicitudes en la misma hora: un solo aviso');
        $this->assertCount(1, self::mensajesPara($c['email']), 'y un solo correo entregado');

        // La «hora siguiente» tiene otra clave de ventana: simulada reescribiendo la clave del evento ya enviado.
        self::$db->query("UPDATE correos_salida SET clave_unica=CONCAT('hora-previa:', id) WHERE destinatario_email='" . self::$db->real_escape_string($c['email']) . "'");
        self::limpiarLimites();
        $this->enviarRegistro($c['email']);
        $this->assertCount(2, $this->eventos($c['email'], 'aviso_cuenta_existente'), 'en otra ventana horaria se permite un nuevo aviso');
    }

    public function test_doble_clic_con_la_misma_clave_no_duplica_efectos_para_ninguna_cuenta(): void
    {
        foreach (['nuevo', 'sin_confirmar', 'confirmado', 'colaborador'] as $tipo) {
            self::limpiarLimites();
            $cuenta = $this->cuenta($tipo);
            $a = $this->enviarRegistro($cuenta['email']);
            $csrf = ClienteHttpRegistro::campo($a['cliente']->get('/cliente/registro')['body'], 'csrf_token');
            for ($i = 0; $i < 2; $i++) {
                $r = $a['cliente']->post('/cliente/registro', ['csrf_token' => $csrf, 'idem_key' => $a['clave'], 'nombre' => 'Nueva', 'apellido' => 'Persona', 'telefono' => '0991112222', 'email' => $cuenta['email'], 'password' => 'Abcdef123456']);
                $this->assertSame(302, $r['status'], "$tipo reintento $i");
            }
            $this->assertCount(1, $this->usuarios($cuenta['email']), $tipo);
            $this->assertCount(1, $this->eventos($cuenta['email']), "$tipo: un único evento tras 3 envíos con la misma clave");
            $this->assertCount(1, self::mensajesPara($cuenta['email']), "$tipo: un único correo");
        }
    }

    public function test_la_misma_clave_con_otro_correo_responde_igual_exista_o_no_la_cuenta(): void
    {
        $respuestas = [];
        foreach (['nuevo', 'confirmado', 'colaborador'] as $tipo) {
            self::limpiarLimites();
            $cuenta = $this->cuenta($tipo);
            $a = $this->enviarRegistro($cuenta['email']);
            $csrf = ClienteHttpRegistro::campo($a['cliente']->get('/cliente/registro')['body'], 'csrf_token');
            $r = $a['cliente']->post('/cliente/registro', ['csrf_token' => $csrf, 'idem_key' => $a['clave'], 'nombre' => 'Nueva', 'apellido' => 'Persona', 'telefono' => '0991112222', 'email' => $this->correo('otro'), 'password' => 'Abcdef123456']);
            $respuestas[$tipo] = self::firma($r);
            $this->assertSame(409, $r['status'], $tipo);
        }
        $this->assertSame($respuestas['nuevo'], $respuestas['confirmado']);
        $this->assertSame($respuestas['nuevo'], $respuestas['colaborador']);
    }

    // ================================================================== 4. límite de intentos y validación

    public function test_el_limite_de_intentos_se_aplica_igual_en_todas_las_ramas(): void
    {
        $secuencias = [];
        foreach (['nuevo', 'confirmado', 'sin_confirmar', 'colaborador', 'suspendido'] as $tipo) {
            self::limpiarLimites();
            $cuenta = $this->cuenta($tipo);
            $estados = [];
            $retry = [];
            for ($i = 0; $i < 5; $i++) {
                $r = $this->enviarRegistro($cuenta['email']);
                $estados[] = $r['post']['status'];
                if ($r['post']['status'] === 429) { $retry[] = $r['post']['headers']['retry-after'][0] ?? null; }
            }
            $secuencias[$tipo] = $estados;
            $this->assertSame([302, 302, 302, 429, 429], $estados, "$tipo: 3 intentos por correo y luego bloqueo");
            $this->assertNotEmpty(array_filter($retry), "$tipo: 429 con Retry-After");
        }
        $this->assertCount(1, array_unique(array_map('json_encode', $secuencias)));
    }

    public function test_los_errores_de_validacion_no_dependen_de_que_la_cuenta_exista(): void
    {
        $casos = [
            'contraseña débil' => ['password' => 'corta'],
            'campos vacíos' => ['nombre' => '', 'apellido' => '', 'telefono' => ''],
            'teléfono inválido' => ['telefono' => 'abc'],
        ];
        foreach ($casos as $nombre => $cambios) {
            $firmas = [];
            foreach (['nuevo', 'confirmado', 'colaborador', 'suspendido'] as $tipo) {
                self::limpiarLimites();
                $cuenta = $this->cuenta($tipo);
                $r = $this->enviarRegistro($cuenta['email'], $cambios);
                $this->assertSame(422, $r['post']['status'], "$nombre/$tipo");
                $cuerpo = str_replace($cuenta['email'], 'EMAIL', $r['post']['body']);
                $firmas[$tipo] = self::firma(['body' => $cuerpo] + $r['post']);
                $this->assertCount(0, $this->eventos($cuenta['email']), "$nombre/$tipo: una solicitud inválida no encola nada");
            }
            $this->assertSame($firmas['nuevo'], $firmas['confirmado'], $nombre);
            $this->assertSame($firmas['nuevo'], $firmas['colaborador'], $nombre);
            $this->assertSame($firmas['nuevo'], $firmas['suspendido'], $nombre);
        }
        // Correo mal formado: detalle propio, igual con independencia de otras cuentas.
        self::limpiarLimites();
        $r = $this->enviarRegistro('no-es-un-correo');
        $this->assertSame(422, $r['post']['status']);
        $this->assertStringContainsString('email válido', $r['post']['body']);
    }

    // ================================================================== 5. otras fugas del mismo flujo

    public function test_login_olvide_y_reenvio_siguen_sin_enumerar_cuentas(): void
    {
        $inexistente = $this->correo('noexiste');
        $cuentas = ['confirmado' => $this->cuenta('confirmado'), 'sin_confirmar' => $this->cuenta('sin_confirmar'), 'colaborador' => $this->cuenta('colaborador'), 'suspendido' => $this->cuenta('suspendido')];

        // /cliente/login y /equipo/login con contraseña errónea.
        foreach (['/cliente/login', '/equipo/login'] as $ruta) {
            self::limpiarLimites();
            $ref = $this->enviarLogin($ruta, $inexistente, 'Mala-clave-1');
            foreach ($cuentas as $tipo => $cuenta) {
                self::limpiarLimites();
                $this->assertSame(self::firma($ref), self::firma($this->enviarLogin($ruta, $cuenta['email'], 'Mala-clave-1')), "$ruta: «{$tipo}» distinguible de un correo inexistente");
            }
        }
        // /olvide y /reenviar-confirmacion.
        foreach (['/olvide', '/reenviar-confirmacion'] as $ruta) {
            self::limpiarLimites();
            $c = $this->http();
            $csrf = ClienteHttpRegistro::campo($c->get($ruta)['body'], 'csrf_token');
            $ref = $c->post($ruta, ['csrf_token' => $csrf, 'email' => $inexistente]);
            foreach ($cuentas as $tipo => $cuenta) {
                self::limpiarLimites();
                $c = $this->http();
                $csrf = ClienteHttpRegistro::campo($c->get($ruta)['body'], 'csrf_token');
                $r = $c->post($ruta, ['csrf_token' => $csrf, 'email' => $cuenta['email']]);
                $this->assertSame(self::firma($ref), self::firma($r), "$ruta: «{$tipo}» distinguible de un correo inexistente");
            }
        }
    }

    public function test_el_tiempo_de_login_no_distingue_un_correo_inexistente_de_uno_existente(): void
    {
        // Hallazgo de la ronda 2: sin bcrypt para correos inexistentes la diferencia era ~60 ms frente a ~5 ms (enumeración por tiempo).
        $cuenta = $this->cuenta('confirmado');
        $servicio = new AuthService(new UsuarioRepository(self::$db));
        $medir = function (string $email) use ($servicio): float {
            $ms = [];
            for ($i = 0; $i < 9; $i++) {
                self::limpiarLimites();
                $t = hrtime(true);
                $servicio->login(['email' => $email, 'password' => 'Mala-clave-1'], '10.9.9.9');
                $ms[] = (hrtime(true) - $t) / 1e6;
            }
            sort($ms);
            return $ms[4];
        };
        $existente = $medir($cuenta['email']);
        $inexistente = $medir($this->correo('noexiste'));
        $this->assertLessThan(25.0, abs($existente - $inexistente), sprintf('medianas: existente %.1f ms, inexistente %.1f ms', $existente, $inexistente));
    }

    private function enviarLogin(string $ruta, string $email, string $password): array
    {
        $c = $this->http();
        $csrf = ClienteHttpRegistro::campo($c->get($ruta)['body'], 'csrf_token');
        return $c->post($ruta, ['csrf_token' => $csrf, 'email' => $email, 'password' => $password]);
    }

    private static function limpiarLimites(): void
    {
        self::$db->query("DELETE FROM intentos_login");
    }

    // ================================================================== 6. atomicidad con la cola

    public function test_si_falla_el_encolado_se_revierte_toda_la_alta_y_la_clave_queda_libre(): void
    {
        $email = $this->correo('atomico');
        self::$db->query("CREATE TRIGGER test_fail_correo_regunif BEFORE INSERT ON correos_salida FOR EACH ROW
            BEGIN IF NEW.destinatario_email LIKE '%" . self::SUF . "' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fallo simulado de cola'; END IF; END");
        $clave = Idempotencia::nuevaClave();
        \Classes\Email::setTransport(static fn(): bool => true);
        $servicio = new AuthService(new UsuarioRepository(self::$db));
        $datos = ['nombre' => 'At', 'apellido' => 'Omico', 'telefono' => '0991234567', 'email' => $email, 'password' => self::PASS];
        $r = $servicio->registrar($datos, null, $clave, 'anonimo:regunif-atomico');
        $this->assertSame(AuthService::STATUS_ERROR, $r['status']);
        $this->assertCount(0, $this->usuarios($email), 'sin evento en la cola no puede quedar la cuenta (misma transacción)');
        $this->assertSame(0, (int)self::$db->query("SELECT COUNT(*) n FROM operaciones_idempotentes WHERE actor='anonimo:regunif-atomico'")->fetch_assoc()['n'], 'la reserva de idempotencia también se revirtió');

        self::$db->query('DROP TRIGGER test_fail_correo_regunif');
        $r = $servicio->registrar($datos, null, $clave, 'anonimo:regunif-atomico');
        $this->assertSame(AuthService::STATUS_OK, $r['status']);
        $this->assertFalse($r['repetida']);
        $this->assertCount(1, $this->usuarios($email));
        $this->assertCount(1, $this->eventos($email));
        \Classes\Email::setTransport(null);
    }

    public function test_con_dispatcher_inyectado_se_usa_tras_el_commit_en_lugar_de_la_cola(): void
    {
        $capturado = [];
        $servicio = new AuthService(new UsuarioRepository(self::$db), static function ($tipo, $email, $nombre, $token) use (&$capturado): bool {
            $capturado[] = compact('tipo', 'email', 'token');
            return true;
        });
        $nuevo = $this->correo('disp');
        $existente = $this->cuenta('confirmado');
        $dato = fn(string $e) => ['nombre' => 'Di', 'apellido' => 'Sp', 'telefono' => '0991234567', 'email' => $e, 'password' => self::PASS];
        $a = $servicio->registrar($dato($nuevo));
        $b = $servicio->registrar($dato($existente['email']));
        $this->assertSame(AuthService::STATUS_OK, $a['status']);
        $this->assertSame(AuthService::STATUS_OK, $b['status']);
        $this->assertSame(['confirmacion', 'aviso_cuenta_existente'], array_column($capturado, 'tipo'));
        $this->assertNotSame('', $capturado[0]['token']);
        $this->assertSame('', $capturado[1]['token']);
        $this->assertCount(0, $this->eventos($nuevo), 'con dispatcher no se usa la cola');
        $this->assertSame($a['repetida'], $b['repetida']);
        $this->assertSame([], $a['alertas']);
        $this->assertSame([], $b['alertas']);
    }

    // ================================================================== 7. concurrencia real (procesos PHP)

    private function lanzar(string $email, string $clave, string $actor, bool $conIp = false): array
    {
        $env = array_merge(getenv(), self::entornoCorreo(), ['APP_ENV' => 'testing', 'DB_NAME' => getenv('DB_NAME') ?: 'appsalon_test']);
        $proceso = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', __DIR__ . '/../fixtures/registro_uniforme_worker.php', $email, $clave, $actor, $conIp ? '1' : '0'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__ . '/../..', $env);
        $this->assertTrue(is_resource($proceso));
        $this->assertSame('READY', trim((string)fgets($pipes[1])), 'el worker debe quedar listo');
        return [$proceso, $pipes];
    }

    private function liberar(array $trabajadores): array
    {
        foreach ($trabajadores as [, $pipes]) { fwrite($pipes[0], "GO\n"); fflush($pipes[0]); fclose($pipes[0]); }
        $res = [];
        foreach ($trabajadores as [$proceso, $pipes]) {
            $linea = fgets($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $this->assertSame(0, proc_close($proceso), 'Worker con error: ' . $err);
            $json = json_decode((string)$linea, true);
            $this->assertIsArray($json, 'Salida de worker: ' . $linea . $err);
            $res[] = $json;
        }
        return $res;
    }

    private function concurrente(int $n, callable $parametros): array
    {
        $ws = [];
        for ($i = 0; $i < $n; $i++) { $ws[] = $this->lanzar(...$parametros($i)); }
        return $this->liberar($ws);
    }

    public function test_seis_procesos_con_la_misma_clave_y_un_correo_existente_dejan_un_solo_aviso(): void
    {
        foreach (['confirmado', 'colaborador'] as $tipo) {
            $c = $this->cuenta($tipo);
            $clave = Idempotencia::nuevaClave();
            file_put_contents(self::$buzon, '[]');
            $res = $this->concurrente(6, fn() => [$c['email'], $clave, 'anonimo:regunif-misma']);
            $this->assertSame(array_fill(0, 6, AuthService::STATUS_OK), array_column($res, 'status'), "$tipo: " . json_encode($res));
            $this->assertCount(1, array_filter(array_column($res, 'repetida'), fn($r) => $r === false), "$tipo: exactamente una ejecutó el aviso");
            $this->assertCount(1, $this->usuarios($c['email']));
            $this->assertCount(1, $this->eventos($c['email']), "$tipo: un evento");
            $this->assertCount(1, self::mensajesPara($c['email']), "$tipo: un correo SMTP");
            $this->assertSame(1, (int)self::$db->query("SELECT COUNT(*) n FROM operaciones_idempotentes WHERE actor='anonimo:regunif-misma'")->fetch_assoc()['n']);
            self::$db->query("DELETE FROM operaciones_idempotentes WHERE actor='anonimo:regunif-misma'");
        }
    }

    public function test_seis_procesos_con_claves_distintas_y_un_correo_confirmado_dejan_un_solo_aviso_por_la_clave_horaria(): void
    {
        $c = $this->cuenta('confirmado');
        $res = $this->concurrente(6, fn($i) => [$c['email'], Idempotencia::nuevaClave(), 'anonimo:regunif-d' . $i]);
        $this->assertSame(array_fill(0, 6, AuthService::STATUS_OK), array_column($res, 'status'), json_encode($res));
        $this->assertCount(1, $this->eventos($c['email'], 'aviso_cuenta_existente'), 'la clave única de la cola impide más de un aviso por hora');
        $this->assertCount(1, self::mensajesPara($c['email']), 'y un solo mensaje SMTP aunque seis solicitudes lo intentaron a la vez');
        $this->assertCount(1, $this->usuarios($c['email']));
    }

    public function test_seis_procesos_con_la_misma_clave_y_un_correo_nuevo_crean_una_sola_cuenta(): void
    {
        $email = $this->correo('conc-nuevo');
        $clave = Idempotencia::nuevaClave();
        $res = $this->concurrente(6, fn() => [$email, $clave, 'anonimo:regunif-n']);
        $this->assertSame(array_fill(0, 6, AuthService::STATUS_OK), array_column($res, 'status'), json_encode($res));
        $this->assertCount(1, array_unique(array_column($res, 'id')), 'todas las respuestas remiten a la misma identidad');
        $this->assertCount(1, array_filter(array_column($res, 'repetida'), fn($r) => $r === false), 'exactamente una ejecutó el alta');
        $this->assertCount(1, $this->usuarios($email));
        $this->assertSame(1, (int)self::$db->query("SELECT COUNT(*) n FROM clientes c JOIN usuarios u ON u.id=c.usuario_id WHERE u.email='$email'")->fetch_assoc()['n']);
        $this->assertCount(1, $this->eventos($email));
        $this->assertCount(1, self::mensajesPara($email));
    }

    public function test_seis_procesos_con_claves_distintas_y_un_correo_nuevo_no_duplican_cuenta_ni_pisan_la_contrasena(): void
    {
        $email = $this->correo('conc-carrera');
        $res = $this->concurrente(6, fn($i) => [$email, Idempotencia::nuevaClave(), 'anonimo:regunif-c' . $i]);
        $this->assertSame(array_fill(0, 6, AuthService::STATUS_OK), array_column($res, 'status'), json_encode($res));
        $u = $this->usuarios($email);
        $this->assertCount(1, $u, 'una sola identidad');
        $this->assertSame(1, (int)self::$db->query("SELECT COUNT(*) n FROM clientes c JOIN usuarios u ON u.id=c.usuario_id WHERE u.email='$email'")->fetch_assoc()['n']);
        $this->assertCount(1, array_filter(array_column($res, 'id'), fn($id) => $id !== null), 'solo una solicitud creó la identidad');
        $this->assertTrue(password_verify(self::PASS, $u[0]['password']), 'la contraseña es la del alta original');
        // Cada solicitud derivó en una confirmación (alta o token reemitido), todas con clave única distinta, sin errores.
        $this->assertGreaterThanOrEqual(1, count($this->eventos($email)));
        $this->assertSame(count($this->eventos($email)), count(array_unique(array_column($this->eventos($email), 'clave_unica'))));
    }

    public function test_el_limite_de_intentos_bajo_concurrencia_admite_lo_mismo_para_una_cuenta_nueva_y_una_existente(): void
    {
        $admitidos = [];
        foreach (['nuevo', 'confirmado'] as $tipo) {
            self::limpiarLimites();
            $cuenta = $this->cuenta($tipo);
            $res = $this->concurrente(6, fn($i) => [$cuenta['email'], Idempotencia::nuevaClave(), 'anonimo:regunif-r' . $tipo . $i, true]);
            $estados = array_count_values(array_column($res, 'status'));
            $admitidos[$tipo] = $estados[AuthService::STATUS_OK] ?? 0;
            $this->assertSame(3, $admitidos[$tipo], "$tipo: el presupuesto por correo (3) se reparte igual: " . json_encode($estados));
            $this->assertSame(3, $estados[AuthService::STATUS_RATE_LIMITED] ?? 0, $tipo);
        }
        $this->assertSame($admitidos['nuevo'], $admitidos['confirmado']);
    }
}

/** Cliente HTTP mínimo con su propio jar de cookies (un navegador por instancia). */
final class ClienteHttpRegistro
{
    private string $jar;

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'regunif_jar_');
    }

    public function __destruct() { @unlink($this->jar); }

    /** @return array{status:int,headers:array<string,array<int,string>>,body:string,location:?string} */
    public function peticion(string $metodo, string $ruta, ?array $campos = null): array
    {
        $ch = curl_init($this->base . $ruta);
        $h = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60,
            CURLOPT_HEADERFUNCTION => static function ($c, $linea) use (&$h) {
                if (str_contains($linea, ':')) { [$k, $v] = explode(':', $linea, 2); $h[strtolower(trim($k))][] = trim($v); }
                return strlen($linea);
            },
        ]);
        if ($campos !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($campos)); }
        $cuerpo = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'headers' => $h, 'body' => (string)$cuerpo, 'location' => $h['location'][0] ?? null];
    }

    public function get(string $ruta): array { return $this->peticion('GET', $ruta); }
    public function post(string $ruta, array $campos): array { return $this->peticion('POST', $ruta, $campos); }

    public static function campo(string $html, string $nombre): ?string
    {
        return preg_match('/name="' . preg_quote($nombre, '/') . '" value="([^"]*)"/', $html, $m) ? html_entity_decode($m[1]) : null;
    }
}

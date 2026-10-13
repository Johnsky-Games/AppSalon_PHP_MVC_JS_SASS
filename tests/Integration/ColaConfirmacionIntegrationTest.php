<?php

namespace Tests\Integration;

use Classes\CargaCifrada;
use Classes\Email;
use Classes\Transaccion;
use Model\ActiveRecord;
use Model\Usuario;
use PHPUnit\Framework\TestCase;
use Repositories\CorreoSalidaRepository;
use Repositories\UsuarioRepository;
use Services\AuthService;
use Services\CorreoSalidaService;

/**
 * Correos de confirmación de cuenta por la cola persistente (outbox) con reintentos.
 *
 * Las garantías de resiliencia y concurrencia se prueban con PROCESOS PHP REALES (workers `procesar_correos.php`,
 * reenvíos simultáneos) y un SMTP simulado en 127.0.0.1 (puertos 2620-2629): nada sale a Internet.
 *   2620 SMTP simulado · 2621 puerto cerrado (SMTP caído)
 */
final class ColaConfirmacionIntegrationTest extends TestCase
{
    private const SUF = '@cola.test';
    private const PUERTO_SMTP = 2620;
    private const PUERTO_CERRADO = 2621;
    private const RAIZ = __DIR__ . '/../..';

    private \mysqli $db;
    /** @var resource|null */
    private $smtp = null;
    private string $buzon = '';
    private string $modo = '';
    private string $logError = '';
    private string $logErrorPrevio = '';
    private array $envPrevio = [];
    private int $contador = 0;

    protected function setUp(): void
    {
        // Estas pruebas verifican la entrega en línea tras el commit (camino CORREOS_ENVIO_INMEDIATO=1); el valor por defecto
        // (solo cola + worker) se prueba en ColaSinEnvioEnLineaIntegrationTest y en la suite Qa.
        $_ENV['CORREOS_ENVIO_INMEDIATO'] = '1'; putenv('CORREOS_ENVIO_INMEDIATO=1');
        $this->db = $this->conectar();
        ActiveRecord::setDB($this->db);
        validar_base_datos_prueba($this->db);
        Email::setTransport(null);
        Email::limpiarEmailsEnviados();
        foreach (['EMAIL_HOST', 'EMAIL_PORT', 'EMAIL_USER', 'EMAIL_PASSWORD', 'EMAIL_PASS'] as $v) {
            $this->envPrevio[$v] = [$_ENV[$v] ?? null, getenv($v)];
        }
        $this->buzon = sys_get_temp_dir() . '/cola_buzon_' . getmypid() . '.jsonl';
        $this->modo = sys_get_temp_dir() . '/cola_modo_' . getmypid() . '.txt';
        $this->logError = sys_get_temp_dir() . '/cola_error_' . getmypid() . '.log';
        @unlink($this->logError);
        $this->logErrorPrevio = (string)ini_get('error_log');
        $this->limpiar();
        $this->smtpEn(self::PUERTO_CERRADO);
    }

    protected function tearDown(): void
    {
        unset($_ENV['CORREOS_ENVIO_INMEDIATO']); putenv('CORREOS_ENVIO_INMEDIATO');
        $this->detenerSmtp();
        foreach ($this->envPrevio as $v => [$e, $g]) {
            if ($e === null) { unset($_ENV[$v]); } else { $_ENV[$v] = $e; }
            if ($g === false) { putenv($v); } else { putenv("{$v}={$g}"); }
        }
        ini_set('error_log', $this->logErrorPrevio);
        Email::setTransport(null);
        if ($this->db->ping()) { $this->limpiar(); }
        @unlink($this->buzon); @unlink($this->modo); @unlink($this->logError);
    }

    // ---------------------------------------------------------------- infraestructura

    private function conectar(): \mysqli
    {
        $db = new \mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', getenv('DB_NAME') ?: 'appsalon_test', (int)(getenv('DB_PORT') ?: 3306));
        $db->set_charset('utf8mb4');
        return $db;
    }

    private function limpiar(): void
    {
        $this->db->query("DELETE FROM correos_salida WHERE destinatario_email LIKE '%" . self::SUF . "'");
        $this->db->query("DELETE FROM usuarios WHERE email LIKE '%" . self::SUF . "'");
        $this->db->query("DELETE FROM intentos_login WHERE tipo IN ('ip_reconfirm','email_reconfirm')");
    }

    /** Apunta el SMTP de PHPMailer (en este proceso y en los hijos) a 127.0.0.1:<puerto>. */
    private function smtpEn(int $puerto): void
    {
        foreach (['EMAIL_HOST' => '127.0.0.1', 'EMAIL_PORT' => (string)$puerto, 'EMAIL_USER' => 'usuario_prueba', 'EMAIL_PASSWORD' => 'clave_smtp_prueba'] as $k => $v) {
            $_ENV[$k] = $v;
            putenv("{$k}={$v}");
        }
    }

    private function levantarSmtp(string $modo = 'ok'): void
    {
        $this->detenerSmtp();
        file_put_contents($this->buzon, '');
        file_put_contents($this->modo, $modo);
        $this->smtp = proc_open([PHP_BINARY, self::RAIZ . '/tests/fixtures/cola_smtp.php', (string)self::PUERTO_SMTP, $this->buzon, $this->modo],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 60; $i++) {
            $c = @fsockopen('127.0.0.1', self::PUERTO_SMTP, $e, $s, 0.2);
            if ($c) { fclose($c); $this->smtpEn(self::PUERTO_SMTP); return; }
            usleep(50000);
        }
        $this->fail('El SMTP simulado no arrancó en 127.0.0.1:' . self::PUERTO_SMTP);
    }

    private function detenerSmtp(): void
    {
        if (is_resource($this->smtp)) { proc_terminate($this->smtp, 9); proc_close($this->smtp); }
        $this->smtp = null;
    }

    private function modoSmtp(string $modo): void
    {
        file_put_contents($this->modo, $modo);
    }

    /** @return list<array<string,mixed>> */
    private function mensajes(): array
    {
        $r = [];
        foreach (is_file($this->buzon) ? file($this->buzon, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $l) {
            $r[] = json_decode($l, true);
        }
        return $r;
    }

    /** @return list<array<string,mixed>> mensajes entregados a un destinatario */
    private function mensajesPara(string $email): array
    {
        return array_values(array_filter($this->mensajes(), static fn($m) => in_array($email, $m['to'], true)));
    }

    private static function tokenDe(array $mensaje): string
    {
        self::assertSame(1, preg_match('/confirmar-cuenta\?token=([0-9a-f]{64})/', $mensaje['cuerpo'], $m), 'el correo no contiene el enlace de confirmación');
        return $m[1];
    }

    private function nuevoEmail(string $prefijo = 'u'): string
    {
        return $prefijo . ++$this->contador . '_' . bin2hex(random_bytes(3)) . self::SUF;
    }

    /** @return array{0:int,1:string} */
    private function crearUsuario(bool $confirmado = false, ?string $email = null): array
    {
        $email ??= $this->nuevoEmail();
        $u = new Usuario(['nombre' => 'Cola', 'apellido' => 'Prueba', 'email' => $email, 'password' => password_hash('ClaveSegura123', PASSWORD_BCRYPT),
            'telefono' => '0991234567', 'confirmado' => $confirmado ? '1' : '0']);
        $id = (new UsuarioRepository($this->db))->createCliente($u);
        return [$id, $email];
    }

    private function auth(): AuthService
    {
        return new AuthService(new UsuarioRepository($this->db));
    }

    private function servicio(): CorreoSalidaService
    {
        return new CorreoSalidaService(new CorreoSalidaRepository($this->db));
    }

    private function reenviar(string $email, string $ip = '198.51.100.20'): array
    {
        return $this->auth()->reenviarConfirmacion(['email' => $email], $ip);
    }

    private function eventosDe(string $email): array
    {
        $s = $this->db->prepare('SELECT * FROM correos_salida WHERE destinatario_email=? ORDER BY id');
        $s->bind_param('s', $email);
        $s->execute();
        $filas = $s->get_result()->fetch_all(MYSQLI_ASSOC);
        $s->close();
        return $filas;
    }

    private function evento(string $email): array
    {
        $e = $this->eventosDe($email);
        $this->assertCount(1, $e, 'se esperaba exactamente un evento');
        return $e[0];
    }

    private function fila(string $sql): array
    {
        return $this->db->query($sql)->fetch_assoc() ?: [];
    }

    private function vencer(?string $email = null): void
    {
        $donde = $email === null ? "destinatario_email LIKE '%" . self::SUF . "'" : "destinatario_email='" . $this->db->real_escape_string($email) . "'";
        $this->db->query("UPDATE correos_salida SET proximo_intento=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE estado='pendiente' AND {$donde}");
    }

    private function tokenDelEvento(array $evento): ?string
    {
        return $evento['carga_cifrada'] === null ? null : (CargaCifrada::descifrar($evento['carga_cifrada'])['token'] ?? null);
    }

    /** Ejecuta scripts/procesar_correos.php como PROCESO real y espera su fin. @return array{0:int,1:string,2:string} */
    private function worker(array $args = []): array
    {
        $p = $this->iniciarWorker($args);
        return $this->terminarWorker($p);
    }

    private function iniciarWorker(array $args = []): array
    {
        $proc = proc_open(array_merge([PHP_BINARY, self::RAIZ . '/scripts/procesar_correos.php'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            self::RAIZ, array_merge(getenv(), ['EMAIL_HOST' => '127.0.0.1', 'EMAIL_PORT' => (string)$_ENV['EMAIL_PORT'], 'EMAIL_USER' => 'usuario_prueba', 'EMAIL_PASSWORD' => 'clave_smtp_prueba']));
        $this->assertIsResource($proc);
        return [$proc, $pipes];
    }

    private function terminarWorker(array $p): array
    {
        [$proc, $pipes] = $p;
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return [proc_close($proc), (string)$out, (string)$err];
    }

    private function esperarEstado(string $email, string $estado, float $segundos = 8.0): void
    {
        $fin = microtime(true) + $segundos;
        do {
            $e = $this->eventosDe($email);
            if ($e && $e[0]['estado'] === $estado) { return; }
            usleep(50000);
        } while (microtime(true) < $fin);
        $this->fail("El evento no llegó al estado {$estado}");
    }

    private function interbloqueos(): int
    {
        return (int)$this->fila("SELECT count c FROM information_schema.INNODB_METRICS WHERE name='lock_deadlocks'")['c'];
    }

    // ---------------------------------------------------------------- contrato y endurecimiento

    public function test_encolar_comparte_la_transaccion_del_alta_y_el_rollback_no_deja_evento(): void
    {
        [$id, $email] = $this->crearUsuario();
        $otra = $this->conectar();
        $svc = $this->servicio();
        $token = bin2hex(random_bytes(32));
        try {
            Transaccion::ejecutar($this->db, function () use ($svc, $id, $email, $otra): void {
                $svc->encolarConfirmacionCuenta($id, $email, 'Cola', bin2hex(random_bytes(32)));
                $this->assertSame(0, (int)$otra->query("SELECT COUNT(*) c FROM correos_salida WHERE destinatario_email='{$email}'")->fetch_assoc()['c'], 'otra conexión no debe ver el evento antes del commit');
                throw new \RuntimeException('el alta falla después de encolar');
            });
            $this->fail('debía propagar la excepción');
        } catch (\RuntimeException $e) {
            $this->assertSame('el alta falla después de encolar', $e->getMessage());
        }
        $this->assertSame([], $this->eventosDe($email), 'el rollback del alta debe retirar también el evento');

        Transaccion::ejecutar($this->db, fn() => $svc->encolarConfirmacionCuenta($id, $email, 'Cola', $token));
        $this->assertSame(1, (int)$otra->query("SELECT COUNT(*) c FROM correos_salida WHERE destinatario_email='{$email}'")->fetch_assoc()['c'], 'tras el commit el evento es visible');
        $otra->close();
    }

    public function test_clave_unica_estable_carga_cifrada_y_usuario_asociado(): void
    {
        [$id, $email] = $this->crearUsuario();
        $svc = $this->servicio();
        $token = bin2hex(random_bytes(32));
        $a = $svc->encolarConfirmacionCuenta($id, $email, 'Cola', $token);
        $b = $svc->encolarConfirmacionCuenta($id, $email, 'Cola', $token);
        $this->assertSame($a, $b, 'el mismo token no genera un segundo evento');
        $c = $svc->encolarConfirmacionCuenta($id, $email, 'Cola', bin2hex(random_bytes(32)));
        $this->assertNotSame($a, $c, 'un token distinto es otra causa');
        $e = $this->eventosDe($email);
        $this->assertCount(2, $e);
        $this->assertSame('confirmacion:' . $id . ':' . substr(hash('sha256', $token), 0, 24), $e[0]['clave_unica']);
        $this->assertSame($id, (int)$e[0]['usuario_id']);
        $volcado = json_encode($e);
        $this->assertStringNotContainsString($token, $volcado, 'el token jamás va en claro en la cola');
        $this->assertStringNotContainsString(base64_encode($token), $volcado);
        $this->assertSame($token, CargaCifrada::descifrar($e[0]['carga_cifrada'])['token']);
        $this->assertStringNotContainsString($token, (string)base64_decode($e[0]['carga_cifrada']), 'la carga va cifrada, no solo codificada');
    }

    public function test_ultimo_error_y_logs_no_contienen_token_ni_credenciales(): void
    {
        ini_set('error_log', $this->logError);
        [$id, $email] = $this->crearUsuario();
        $token = bin2hex(random_bytes(32));
        $_ENV['EMAIL_PASSWORD'] = 'SmtpClaveSecreta99'; putenv('EMAIL_PASSWORD=SmtpClaveSecreta99');
        Email::setTransport(function () use ($token): bool {
            throw new \RuntimeException("535 login SmtpClaveSecreta99 rechazado; enlace /confirmar-cuenta?token={$token} usuario usuario_prueba");
        });
        $svc = $this->servicio();
        $evento = Transaccion::ejecutar($this->db, fn() => $svc->encolarConfirmacionCuenta($id, $email, 'Cola', $token));
        // El token del evento coincide con el vigente para que el fallo sea del transporte y no de vigencia.
        $this->db->query("UPDATE usuarios SET token_hash='" . hash('sha256', $token) . "', token_tipo='confirmacion', token_expira=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE id={$id}");
        $this->assertFalse($svc->despachar($evento));
        $e = $this->evento($email);
        $this->assertSame('pendiente', $e['estado']);
        $this->assertNotSame('', (string)$e['ultimo_error']);
        $registro = json_encode($e) . (string)@file_get_contents($this->logError);
        foreach ([$token, 'SmtpClaveSecreta99', 'usuario_prueba'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $registro, "«{$secreto}» filtrado a la cola o a los logs");
        }
        $this->assertStringContainsString('***', $e['ultimo_error'] . Email::sanearMensajeError('x SmtpClaveSecreta99'));
    }

    // ---------------------------------------------------------------- reenviarConfirmacion por la cola

    public function test_reenvio_encola_y_cancela_los_eventos_de_tokens_anteriores(): void
    {
        [$id, $email] = $this->crearUsuario();
        $r1 = $this->reenviar($email);
        $this->assertSame(AuthService::STATUS_OK, $r1['status']);
        $e1 = $this->evento($email);
        $this->assertSame('pendiente', $e1['estado'], 'SMTP caído: el correo queda en cola');
        $this->assertSame(1, (int)$e1['intentos']);
        $token1 = $this->tokenDelEvento($e1);
        $repo = new UsuarioRepository($this->db);
        $this->assertNotNull($repo->findByValidToken($token1, 'confirmacion'));

        $this->reenviar($email);
        $eventos = $this->eventosDe($email);
        $this->assertCount(2, $eventos);
        $this->assertSame('cancelado', $eventos[0]['estado'], 'el evento del token anterior se cancela');
        $this->assertNull($eventos[0]['carga_cifrada'], 'y su carga se borra');
        $this->assertSame('pendiente', $eventos[1]['estado']);
        $token2 = $this->tokenDelEvento($eventos[1]);
        $this->assertNull($repo->findByValidToken($token1, 'confirmacion'), 'el token nuevo invalida el anterior');
        $this->assertNotNull($repo->findByValidToken($token2, 'confirmacion'));

        // Aunque el SMTP vuelva, solo se envía el enlace vigente.
        $this->levantarSmtp();
        $this->vencer();
        $this->assertSame(1, $this->servicio()->procesarPendientes());
        $m = $this->mensajesPara($email);
        $this->assertCount(1, $m);
        $this->assertSame($token2, self::tokenDe($m[0]));
    }

    public function test_respuesta_uniforme_y_solo_las_cuentas_no_confirmadas_generan_evento(): void
    {
        [, $pendiente] = $this->crearUsuario(false);
        [, $confirmada] = $this->crearUsuario(true);
        $desconocida = $this->nuevoEmail('nadie');
        $respuestas = [];
        foreach ([$pendiente, $confirmada, $desconocida] as $i => $email) {
            $r = $this->reenviar($email, '198.51.100.' . (30 + $i));
            $respuestas[] = [$r['status'], $r['resultado'], $r['alertas']];
        }
        $this->assertSame($respuestas[0], $respuestas[1]);
        $this->assertSame($respuestas[0], $respuestas[2]);
        $this->assertCount(1, $this->eventosDe($pendiente));
        $this->assertSame([], $this->eventosDe($confirmada));
        $this->assertSame([], $this->eventosDe($desconocida));
    }

    public function test_con_dispatcher_inyectado_el_reenvio_sigue_siendo_sincrono_y_sin_cola(): void
    {
        [, $email] = $this->crearUsuario();
        $enviados = [];
        $svc = new AuthService(new UsuarioRepository($this->db), function (string $tipo, string $dest, string $nombre, string $token) use (&$enviados): bool {
            $enviados[] = [$tipo, $dest, $token];
            return true;
        });
        $this->assertSame(AuthService::STATUS_OK, $svc->reenviarConfirmacion(['email' => $email], '198.51.100.40')['status']);
        $this->assertCount(1, $enviados);
        $this->assertSame('confirmacion', $enviados[0][0]);
        $this->assertSame([], $this->eventosDe($email), 'con dispatcher inyectado no se usa la cola');
    }

    public function test_al_despachar_un_enlace_obsoleto_se_cancela_y_nunca_se_envia(): void
    {
        $this->levantarSmtp();
        $repo = new UsuarioRepository($this->db);
        $svc = $this->servicio();

        // (1) Token reemplazado sin pasar por el reenvío (p. ej. otra vía): el evento viejo no se envía.
        [$id, $email] = $this->crearUsuario();
        $usuario = $repo->findByEmail($email);
        $viejo = $repo->issueConfirmationToken($usuario, 24);
        $eViejo = Transaccion::ejecutar($this->db, fn() => $svc->encolarConfirmacionCuenta($id, $email, 'Cola', $viejo));
        $nuevo = $repo->issueConfirmationToken($usuario, 24);
        $this->assertFalse($svc->despachar($eViejo));
        $this->assertSame('cancelado', $this->evento($email)['estado']);
        $this->assertSame([], $this->mensajes(), 'no se envía un enlace inválido');

        // (2) Control: el enlace vigente sí se envía.
        $eNuevo = Transaccion::ejecutar($this->db, fn() => $svc->encolarConfirmacionCuenta($id, $email, 'Cola', $nuevo));
        $this->assertTrue($svc->despachar($eNuevo));
        $this->assertCount(1, $this->mensajesPara($email));

        // (3) Cuenta confirmada por otra vía antes del envío: se cancela.
        [$id2, $email2] = $this->crearUsuario();
        $t = $repo->issueConfirmationToken($repo->findByEmail($email2), 24);
        $e2 = Transaccion::ejecutar($this->db, fn() => $svc->encolarConfirmacionCuenta($id2, $email2, 'Cola', $t));
        $this->db->query("UPDATE usuarios SET confirmado=1, token_hash=NULL, token_tipo=NULL, token_expira=NULL WHERE id={$id2}");
        $this->assertFalse($svc->despachar($e2));
        $this->assertSame('cancelado', $this->evento($email2)['estado']);
        $this->assertSame([], $this->mensajesPara($email2));
    }

    public function test_cancelar_un_evento_en_vuelo_impide_marcarlo_enviado(): void
    {
        [$id, $email] = $this->crearUsuario();
        $repo = new CorreoSalidaRepository($this->db);
        $eid = Transaccion::ejecutar($this->db, fn() => $this->servicio()->encolarConfirmacionCuenta($id, $email, 'Cola', bin2hex(random_bytes(32))));
        $tomado = $repo->tomar($eid);
        $this->assertNotNull($tomado);
        $this->assertSame('enviando', $this->evento($email)['estado']);
        $this->assertSame(1, $repo->cancelarPorUsuario($id, 'confirmacion_cuenta', 'prueba'));
        $this->assertFalse($repo->marcarEnviado($eid, $tomado['token']), 'el worker que perdió el evento no puede marcarlo enviado');
        $this->assertNull($repo->marcarFallido($eid, $tomado['token'], 'x'));
        $e = $this->evento($email);
        $this->assertSame('cancelado', $e['estado']);
        $this->assertNull($e['carga_cifrada']);
        $this->assertNull($repo->tomar($eid), 'un evento cancelado no se vuelve a tomar');
    }

    public function test_aviso_de_cuenta_existente_se_entrega_por_la_cola_sin_token_y_se_deduplica(): void
    {
        $this->levantarSmtp();
        $email = $this->nuevoEmail('aviso');
        $svc = $this->servicio();
        $a = $svc->encolarAvisoCuentaExistente($email, 'Titular', 'cliente', '2026101318');
        $b = $svc->encolarAvisoCuentaExistente(strtoupper($email), 'Titular', 'cliente', '2026101318');
        $this->assertSame($a, $b, 'misma ventana: un solo aviso');
        $this->assertTrue($svc->despacharTrasCommit($a));
        $m = $this->mensajesPara($email);
        $this->assertCount(1, $m);
        $this->assertStringNotContainsString('token=', $m[0]['cuerpo']);
        $this->assertStringContainsString('/login', $m[0]['cuerpo']);
        $this->assertSame('enviado', $this->evento($email)['estado']);
    }

    // ---------------------------------------------------------------- resiliencia con SMTP real simulado

    public function test_a_smtp_caido_no_pierde_el_correo_y_los_reintentos_crecen_segun_el_calendario(): void
    {
        [, $email] = $this->crearUsuario();
        $r = $this->reenviar($email);
        $this->assertSame(AuthService::STATUS_OK, $r['status'], 'el reenvío NO falla con el SMTP caído');
        $e = $this->evento($email);
        $this->assertSame('pendiente', $e['estado']);
        $this->assertNotNull($e['carga_cifrada'], 'el correo no se pierde: la carga sigue en la cola');
        $this->assertSame(1, (int)$e['intentos']);
        $this->assertNotSame('', (string)$e['ultimo_error']);

        // Esperas exactas del SQL: tras el fallo n, LEAST(2^n * 30, 3600) s => 60, 120, 240, 480, 960.
        $esperado = [1 => 60, 2 => 120, 3 => 240, 4 => 480, 5 => 960];
        for ($n = 1; $n <= 5; $n++) {
            if ($n > 1) {
                $this->vencer($email);
                [$codigo, $salida] = $this->worker();
                $this->assertSame(0, $codigo, $salida);
            }
            $e = $this->evento($email);
            $this->assertSame($n, (int)$e['intentos']);
            $delta = (int)$this->fila("SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), proximo_intento) d FROM correos_salida WHERE id={$e['id']}")['d'];
            $this->assertEqualsWithDelta($esperado[$n], $delta, 4, "espera tras el fallo {$n}");
            $this->assertSame('pendiente', $e['estado']);
        }
        // Un evento aún no vencido no se procesa.
        [, $salida] = $this->worker();
        $this->assertStringContainsString('Correos enviados: 0', $salida);
        $this->assertSame(5, (int)$this->evento($email)['intentos']);
    }

    public function test_a_smtp_colgado_el_reenvio_responde_ok_en_tiempo_acotado_y_deja_el_evento_pendiente(): void
    {
        $this->levantarSmtp('colgar');
        [, $email] = $this->crearUsuario();
        $t = microtime(true);
        $r = $this->reenviar($email);
        $dur = microtime(true) - $t;
        $this->assertSame(AuthService::STATUS_OK, $r['status']);
        $this->assertLessThan(20.0, $dur, 'PHPMailer debe rendirse por timeout, no colgar la petición');
        $e = $this->evento($email);
        $this->assertSame('pendiente', $e['estado']);
        $this->assertSame(1, (int)$e['intentos']);
        $this->assertNotNull($e['carga_cifrada']);
    }

    public function test_b_y_f_recuperacion_el_worker_entrega_y_la_cuenta_se_confirma_con_el_enlace_recibido(): void
    {
        [$id, $email] = $this->crearUsuario();
        $this->reenviar($email);
        $this->assertSame('pendiente', $this->evento($email)['estado']);

        $this->levantarSmtp();
        $this->vencer($email);
        [$codigo, $salida] = $this->worker();
        $this->assertSame(0, $codigo, $salida);
        $this->assertStringContainsString('Correos enviados: 1', $salida);
        $e = $this->evento($email);
        $this->assertSame('enviado', $e['estado']);
        $this->assertNull($e['carga_cifrada'], 'la carga con el token se borra tras el envío');
        $this->assertNull($e['ultimo_error']);
        $this->assertNotNull($e['enviado_en']);

        $m = $this->mensajesPara($email);
        $this->assertCount(1, $m);
        $token = self::tokenDe($m[0]);
        $this->assertStringContainsString('Subject: Confirma tu cuenta', $m[0]['cabeceras']);

        // De punta a punta: el enlace del correo capturado confirma la cuenta, una sola vez.
        $r = $this->auth()->confirmarCuenta($token);
        $this->assertSame(AuthService::STATUS_OK, $r['status']);
        $this->assertSame(1, (int)$this->fila("SELECT confirmado c FROM usuarios WHERE id={$id}")['c']);
        $this->assertNotSame(AuthService::STATUS_OK, $this->auth()->confirmarCuenta($token)['status'], 'el enlace es de un solo uso');
        // Con la cuenta confirmada, un nuevo reenvío no genera nada.
        $this->reenviar($email, '198.51.100.51');
        $this->assertCount(1, $this->eventosDe($email));
    }

    public function test_c_agotamiento_deja_fallido_visible_y_el_comando_de_reintento_lo_recupera(): void
    {
        [, $email] = $this->crearUsuario();
        $this->reenviar($email);
        for ($n = 2; $n <= CorreoSalidaRepository::MAX_INTENTOS; $n++) {
            $this->vencer($email);
            $this->assertSame(0, $this->worker()[0]);
        }
        $e = $this->evento($email);
        $this->assertSame('fallido', $e['estado'], 'tras MAX_INTENTOS el evento es terminal');
        $this->assertSame(CorreoSalidaRepository::MAX_INTENTOS, (int)$e['intentos']);
        $this->assertNotNull($e['carga_cifrada'], 'conserva la carga para poder reintentarlo');

        // Visible: --estado lo lista y el exit code avisa; el log de la aplicación también.
        [$codigo, $salida] = $this->worker(['--estado']);
        $this->assertSame(3, $codigo);
        $this->assertStringContainsString("FALLIDO #{$e['id']} confirmacion_cuenta", $salida);

        // Con el SMTP ya disponible, el procesamiento normal NO toca un fallido.
        $this->levantarSmtp();
        $this->vencer($email);
        [, $normal] = $this->worker();
        $this->assertStringContainsString('Correos enviados: 0', $normal);
        $this->assertSame('fallido', $this->evento($email)['estado']);
        $this->assertSame([], $this->mensajes());

        // Reintento manual: seguro (solo fallidos con carga) y entrega.
        [$c2, $o2] = $this->worker(['--reintentar=' . $e['id']]);
        $this->assertSame(0, $c2, $o2);
        $r = $this->evento($email);
        $this->assertSame('pendiente', $r['estado']);
        $this->assertSame(0, (int)$r['intentos']);
        [, $entrega] = $this->worker();
        $this->assertStringContainsString('Correos enviados: 1', $entrega);
        $this->assertSame('enviado', $this->evento($email)['estado']);
        $this->assertCount(1, $this->mensajesPara($email));
        $this->assertSame(0, $this->worker(['--estado'])[0], 'sin fallidos el diagnóstico sale con 0');

        // --reintentar no resucita enviados ni inexistentes.
        $this->assertSame(1, $this->worker(['--reintentar=' . $e['id']])[0]);
        $this->assertSame(1, $this->worker(['--reintentar=999999999'])[0]);
        $this->assertSame(2, $this->worker(['--reintentar=abc'])[0]);
        $this->assertSame(2, $this->worker(['--reintentar=1; DROP TABLE usuarios'])[0]);
        $this->assertSame(0, $this->worker(['--reintentar=todos-fallidos'])[0]);
    }

    public function test_reintentar_todos_los_fallidos_no_reactiva_cancelados_ni_pendientes(): void
    {
        [$id1, $e1] = $this->crearUsuario();
        [$id2, $e2] = $this->crearUsuario();
        [$id3, $e3] = $this->crearUsuario();
        $svc = $this->servicio();
        foreach ([$id1 => $e1, $id2 => $e2, $id3 => $e3] as $id => $email) {
            Transaccion::ejecutar($this->db, fn() => $svc->encolarConfirmacionCuenta($id, $email, 'Cola', bin2hex(random_bytes(32))));
        }
        $this->db->query("UPDATE correos_salida SET estado='fallido', intentos=6 WHERE destinatario_email='{$e1}'");
        $this->db->query("UPDATE correos_salida SET estado='cancelado', carga_cifrada=NULL WHERE destinatario_email='{$e2}'");
        [$codigo, $salida] = $this->worker(['--reintentar=todos-fallidos']);
        $this->assertSame(0, $codigo);
        $this->assertStringContainsString('Eventos reactivados: 1', $salida);
        $this->assertSame('pendiente', $this->evento($e1)['estado']);
        $this->assertSame('cancelado', $this->evento($e2)['estado']);
        $this->assertSame('pendiente', $this->evento($e3)['estado']);
    }

    public function test_d_worker_muerto_en_pleno_envio_otro_worker_toma_el_evento_al_expirar_el_lease(): void
    {
        [, $email] = $this->crearUsuario();
        $this->levantarSmtp('colgar');
        $this->reenviar($email); // el intento inmediato también se rinde por timeout
        $this->modoSmtp('colgar');
        $this->vencer($email);
        $a = $this->iniciarWorker();
        $this->esperarEstado($email, 'enviando');
        proc_terminate($a[0], 9); // muerte súbita del worker en pleno envío (SIGKILL)
        $this->terminarWorker($a);

        $e = $this->evento($email);
        $this->assertSame('enviando', $e['estado']);
        $this->assertNotNull($e['worker_token']);
        $this->assertNotNull($e['carga_cifrada']);

        // Con el lease vigente nadie más lo toca.
        $this->modoSmtp('ok');
        [, $durante] = $this->worker();
        $this->assertStringContainsString('Correos enviados: 0', $durante);
        $this->assertSame([], $this->mensajes());

        // Vencido el lease, otro worker lo retoma y se entrega exactamente un correo.
        $this->db->query("UPDATE correos_salida SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE id={$e['id']}");
        [$codigo, $salida] = $this->worker(['--estado']);
        $this->assertStringContainsString('lease vencido (worker caído): 1', $salida);
        [$c2, $o2] = $this->worker();
        $this->assertSame(0, $c2, $o2);
        $this->assertStringContainsString('Correos enviados: 1', $o2);
        $this->assertSame('enviado', $this->evento($email)['estado']);
        $this->assertCount(1, $this->mensajesPara($email), 'sin envío doble en el caso normal');
    }

    public function test_e_dos_workers_simultaneos_no_envian_el_mismo_evento(): void
    {
        $this->levantarSmtp('lento:200');
        $emails = [];
        $svc = $this->servicio();
        $repoU = new UsuarioRepository($this->db);
        for ($i = 0; $i < 8; $i++) {
            [, $email] = $this->crearUsuario();
            $emails[] = $email;
            $u = $repoU->findByEmail($email);
            $t = $repoU->issueConfirmationToken($u, 24);
            Transaccion::ejecutar($this->db, fn() => $svc->encolarConfirmacionCuenta((int)$u->id, $email, 'Cola', $t));
        }
        $a = $this->iniciarWorker();
        $b = $this->iniciarWorker();
        [$ca, $oa] = $this->terminarWorker($a);
        [$cb, $ob] = $this->terminarWorker($b);
        $this->assertSame(0, $ca, $oa);
        $this->assertSame(0, $cb, $ob);
        preg_match('/Correos enviados: (\d+)/', $oa, $ma);
        preg_match('/Correos enviados: (\d+)/', $ob, $mb);
        $this->assertSame(8, (int)$ma[1] + (int)$mb[1], "ambos workers suman 8 envíos ({$ma[1]} + {$mb[1]})");
        $this->assertGreaterThan(0, (int)$ma[1], 'el worker A debió enviar alguno (concurrencia real)');
        $this->assertGreaterThan(0, (int)$mb[1], 'el worker B debió enviar alguno (concurrencia real)');
        $this->assertCount(8, $this->mensajes());
        foreach ($emails as $email) {
            $this->assertCount(1, $this->mensajesPara($email), 'cada destinatario recibe exactamente un correo');
            $this->assertSame('enviado', $this->evento($email)['estado']);
        }
    }

    public function test_limite_honesto_si_el_servidor_acepta_y_la_conexion_se_corta_hay_reintento_y_duplicado(): void
    {
        [$id, $email] = $this->crearUsuario();
        $this->levantarSmtp('aceptar_y_cortar');
        $this->reenviar($email);
        $e = $this->evento($email);
        $this->assertSame('pendiente', $e['estado'], 'sin confirmación del servidor el evento se considera no entregado');
        $this->assertCount(1, $this->mensajesPara($email), 'pero el servidor sí lo había recibido');
        $this->modoSmtp('ok');
        $this->vencer($email);
        $this->assertSame(0, $this->worker()[0]);
        $this->assertSame('enviado', $this->evento($email)['estado']);
        $m = $this->mensajesPara($email);
        $this->assertCount(2, $m, 'entrega AL MENOS UNA VEZ: el destinatario recibe el correo duplicado (límite documentado)');
        $this->assertSame(self::tokenDe($m[0]), self::tokenDe($m[1]), 'ambas copias llevan el mismo enlace vigente');
    }

    public function test_el_worker_corta_la_tanda_tras_fallos_seguidos_sin_gastar_intentos_del_resto(): void
    {
        $svc = $this->servicio();
        $emails = [];
        $repoU = new UsuarioRepository($this->db);
        for ($i = 0; $i < 6; $i++) {
            [, $email] = $this->crearUsuario();
            $emails[] = $email;
            $u = $repoU->findByEmail($email);
            $t = $repoU->issueConfirmationToken($u, 24);
            Transaccion::ejecutar($this->db, fn() => $svc->encolarConfirmacionCuenta((int)$u->id, $email, 'Cola', $t));
        }
        [$codigo, $salida] = $this->worker();
        $this->assertSame(0, $codigo, $salida);
        $this->assertStringContainsString('Tanda interrumpida tras 3 fallos seguidos', $salida);
        $this->assertStringContainsString('3 evento(s) quedan intactos', $salida);
        $intentos = array_map(fn($e) => (int)$this->evento($e)['intentos'], $emails);
        $this->assertSame([1, 1, 1, 0, 0, 0], $intentos, 'solo se gastan intentos en los eventos realmente probados');
    }

    // ---------------------------------------------------------------- concurrencia de reenvíos (procesos reales)

    /**
     * Lanza $n procesos PHP que llaman a reenviarConfirmacion a la vez (barrera READY->GO).
     * @return list<array{status:string}>
     */
    private function reenviosSimultaneos(string $email, int $n): array
    {
        $this->db->query("DELETE FROM intentos_login WHERE tipo IN ('ip_reconfirm','email_reconfirm')");
        $procesos = [];
        for ($i = 0; $i < $n; $i++) {
            $procesos[$i] = proc_open([PHP_BINARY, self::RAIZ . '/tests/fixtures/cola_reenvio_worker.php', $email, '198.51.100.' . (100 + $i)],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::RAIZ, array_merge(getenv(), ['EMAIL_HOST' => '127.0.0.1', 'EMAIL_PORT' => (string)$_ENV['EMAIL_PORT'], 'EMAIL_USER' => 'u', 'EMAIL_PASSWORD' => 'p']));
            $procesos[$i] = [$procesos[$i], $pipes];
        }
        foreach ($procesos as [, $pipes]) { $this->assertSame('READY', trim((string)fgets($pipes[1]))); }
        foreach ($procesos as [, $pipes]) { fwrite($pipes[0], "GO\n"); fflush($pipes[0]); }
        $r = [];
        foreach ($procesos as [$proc, $pipes]) {
            $salida = trim((string)stream_get_contents($pipes[1]));
            $err = trim((string)stream_get_contents($pipes[2]));
            fclose($pipes[0]); fclose($pipes[1]); fclose($pipes[2]);
            $codigo = proc_close($proc);
            $this->assertSame(0, $codigo, "un proceso de reenvío terminó con error: {$err}");
            $r[] = json_decode($salida, true);
        }
        return $r;
    }

    public function test_reenvios_concurrentes_con_smtp_caido_dejan_un_token_valido_y_un_evento_activo(): void
    {
        [$id, $email] = $this->crearUsuario();
        $repoU = new UsuarioRepository($this->db);
        $interbloqueosAntes = $this->interbloqueos();
        $emitidosTotal = 0;
        for ($ronda = 1; $ronda <= 4; $ronda++) {
            $this->db->query("DELETE FROM correos_salida WHERE destinatario_email='{$email}'");
            $resultados = $this->reenviosSimultaneos($email, 8);
            $estados = array_count_values(array_column($resultados, 'status'));
            $this->assertSame([], array_diff(array_keys($estados), [AuthService::STATUS_OK, AuthService::STATUS_RATE_LIMITED]),
                'ningún reenvío terminó en error (interbloqueo no recuperado, timeout de bloqueo...): ' . json_encode($estados));
            $ok = $estados[AuthService::STATUS_OK] ?? 0;
            $this->assertGreaterThanOrEqual(1, $ok);
            $emitidosTotal += $ok;

            $eventos = $this->eventosDe($email);
            $this->assertCount($ok, $eventos, "ronda {$ronda}: cada reenvío aceptado deja un evento");
            $activos = array_values(array_filter($eventos, static fn($e) => in_array($e['estado'], ['pendiente', 'enviando'], true)));
            $this->assertCount(1, $activos, "ronda {$ronda}: como máximo un evento activo (SMTP caído => exactamente uno)");
            $this->assertCount($ok - 1, array_filter($eventos, static fn($e) => $e['estado'] === 'cancelado'));
            $tokenActivo = $this->tokenDelEvento($activos[0]);
            $vigente = $repoU->findByValidToken((string)$tokenActivo, 'confirmacion');
            $this->assertNotNull($vigente, 'el evento activo lleva el token vigente (el último emitido)');
            $this->assertSame($id, (int)$vigente->id);
            $this->assertSame(hash('sha256', (string)$tokenActivo), $this->fila("SELECT token_hash h FROM usuarios WHERE id={$id}")['h'], 'exactamente un token válido');
            foreach ($eventos as $e) {
                if ($e['estado'] === 'cancelado') { $this->assertNull($e['carga_cifrada']); }
            }
        }
        file_put_contents('/tmp/cola_metricas.txt', "reenvios_caido: emitidos={$emitidosTotal} interbloqueos_innodb=" . ($this->interbloqueos() - $interbloqueosAntes) . "\n", FILE_APPEND);
    }

    public function test_reenvios_concurrentes_con_smtp_arriba_y_workers_nunca_dejan_un_evento_activo_con_token_invalido(): void
    {
        $this->levantarSmtp('lento:60');
        [$id, $email] = $this->crearUsuario();
        $repoU = new UsuarioRepository($this->db);
        $interbloqueosAntes = $this->interbloqueos();
        for ($ronda = 1; $ronda <= 3; $ronda++) {
            // Workers reales procesando la cola MIENTRAS llegan los reenvíos.
            $w = [$this->iniciarWorker(['--limite=50']), $this->iniciarWorker(['--limite=50'])];
            $resultados = $this->reenviosSimultaneos($email, 8);
            foreach ($w as $p) { [$c, $o, $err] = $this->terminarWorker($p); $this->assertSame(0, $c, $o . $err); }
            $estados = array_count_values(array_column($resultados, 'status'));
            $this->assertSame([], array_diff(array_keys($estados), [AuthService::STATUS_OK, AuthService::STATUS_RATE_LIMITED]), json_encode($estados));

            $hash = $this->fila("SELECT token_hash h FROM usuarios WHERE id={$id}")['h'];
            $eventos = $this->eventosDe($email);
            $activos = array_values(array_filter($eventos, static fn($e) => in_array($e['estado'], ['pendiente', 'enviando'], true)));
            $this->assertLessThanOrEqual(1, count($activos), "ronda {$ronda}: como máximo un evento activo");
            foreach ($activos as $a) {
                $this->assertSame($hash, hash('sha256', (string)$this->tokenDelEvento($a)), 'ningún evento activo con token inválido');
            }
            // El worker de cierre entrega lo pendiente: el enlace vigente llega al buzón.
            $this->vencer($email);
            $this->assertSame(0, $this->worker()[0]);
            $tokens = array_map(fn($m) => self::tokenDe($m), $this->mensajesPara($email));
            $this->assertContains(true, array_map(static fn($t) => hash('sha256', $t) === $hash, $tokens), 'el enlace vigente fue entregado');
            $this->assertNotNull($repoU->findByValidToken(array_values(array_filter($tokens, static fn($t) => hash('sha256', $t) === $hash))[0], 'confirmacion'));
            $this->assertSame([], array_filter($this->eventosDe($email), static fn($e) => in_array($e['estado'], ['pendiente', 'enviando'], true)), 'tras el cierre no queda nada activo');
            $this->db->query("DELETE FROM correos_salida WHERE destinatario_email='{$email}'");
            file_put_contents($this->buzon, '');
        }
        file_put_contents('/tmp/cola_metricas.txt', 'reenvios_arriba: interbloqueos_innodb=' . ($this->interbloqueos() - $interbloqueosAntes) . "\n", FILE_APPEND);
    }

    public function test_un_interbloqueo_1213_durante_el_reenvio_se_reintenta_y_el_reenvio_termina_bien(): void
    {
        [$id, $email] = $this->crearUsuario();
        $this->reenviar($email); // evento A pendiente (SMTP caído)
        $a = $this->evento($email);
        $repoU = new UsuarioRepository($this->db);
        $tokenAntes = $this->tokenDelEvento($a);
        $x = $this->conectar();
        for ($i = 0; $i < 40; $i++) { // peso: InnoDB sacrifica la transacción con menos filas modificadas (el reenvío)
            $x->query("INSERT INTO correos_salida(clave_unica,tipo,destinatario_email,destinatario_nombre) VALUES ('cola-peso:" . $id . ":" . $i . "','peso','peso{$i}" . self::SUF . "','P')");
        }
        $interbloqueosAntes = $this->interbloqueos();
        $x->begin_transaction();
        $x->query("UPDATE correos_salida SET ultimo_error='x' WHERE destinatario_email LIKE 'peso%" . self::SUF . "'");
        $x->query("UPDATE correos_salida SET ultimo_error='x' WHERE id={$a['id']}"); // X retiene el evento A

        $p = proc_open([PHP_BINARY, self::RAIZ . '/tests/fixtures/cola_reenvio_worker.php', $email, '198.51.100.77'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            self::RAIZ, array_merge(getenv(), ['EMAIL_HOST' => '127.0.0.1', 'EMAIL_PORT' => (string)self::PUERTO_CERRADO]));
        $this->assertSame('READY', trim((string)fgets($pipes[1])));
        fwrite($pipes[0], "GO\n");
        // El reenvío toma la fila del usuario y queda esperando el evento A que retiene X.
        $fin = microtime(true) + 8;
        do { usleep(50000); $esperando = (int)$this->fila('SELECT COUNT(*) c FROM performance_schema.data_lock_waits')['c']; } while ($esperando === 0 && microtime(true) < $fin);
        $this->assertGreaterThan(0, $esperando, 'el reenvío debía quedar esperando un bloqueo');
        // X pide ahora la fila del usuario: ciclo => interbloqueo.
        $this->assertTrue($x->query("UPDATE usuarios SET apellido=apellido WHERE id={$id}") !== false, 'X no debía ser la víctima: ' . $x->error);
        $x->commit();
        $salida = trim((string)stream_get_contents($pipes[1]));
        $err = trim((string)stream_get_contents($pipes[2]));
        foreach ($pipes as $pp) { fclose($pp); }
        $this->assertSame(0, proc_close($p), $err);
        $x->close();

        $this->assertSame(AuthService::STATUS_OK, json_decode($salida, true)['status'], 'el interbloqueo se recuperó con reintento: ' . $salida);
        $this->assertGreaterThanOrEqual(1, $this->interbloqueos() - $interbloqueosAntes, 'debió producirse un interbloqueo real');
        $eventos = array_values(array_filter($this->eventosDe($email), static fn($e) => $e['tipo'] === 'confirmacion_cuenta'));
        $this->assertCount(2, $eventos);
        $this->assertSame('cancelado', $eventos[0]['estado']);
        $this->assertSame('pendiente', $eventos[1]['estado']);
        $nuevo = (string)$this->tokenDelEvento($eventos[1]);
        $this->assertNotSame($tokenAntes, $nuevo);
        $this->assertNull($repoU->findByValidToken((string)$tokenAntes, 'confirmacion'));
        $this->assertNotNull($repoU->findByValidToken($nuevo, 'confirmacion'));
    }

    // ---------------------------------------------------------------- operación

    public function test_estado_json_no_expone_datos_personales_ni_tokens(): void
    {
        [, $email] = $this->crearUsuario();
        $this->reenviar($email);
        $e = $this->evento($email);
        $token = (string)$this->tokenDelEvento($e);
        $this->db->query("UPDATE correos_salida SET estado='fallido', intentos=6 WHERE id={$e['id']}");
        [$codigo, $salida] = $this->worker(['--estado', '--json']);
        $this->assertSame(3, $codigo);
        $json = json_decode($salida, true);
        $this->assertIsArray($json);
        $this->assertSame(1, $json['por_estado']['fallido']['confirmacion_cuenta']);
        $this->assertSame((int)$e['id'], $json['fallidos'][0]['id']);
        foreach ([$email, 'Cola', $token, 'usuario_prueba', 'clave_smtp_prueba'] as $dato) {
            $this->assertStringNotContainsString($dato, $salida, "--estado no debe revelar «{$dato}»");
        }
        [, $texto] = $this->worker(['--estado']);
        $this->assertStringNotContainsString($email, $texto);
        $this->assertSame(2, $this->worker(['--inexistente'])[0]);
        [$c, $o] = $this->worker(['--ayuda']);
        $this->assertSame(0, $c);
        $this->assertStringContainsString('--reintentar', $o);
    }

    public function test_migracion_019_es_idempotente_y_su_rollback_conserva_los_eventos(): void
    {
        [$id, $email] = $this->crearUsuario();
        $this->reenviar($email);
        $antes = $this->evento($email);
        $migraciones = self::RAIZ . '/database/migrations/';
        try {
            $this->ejecutarSql(file_get_contents($migraciones . '019_correos_cola_usuario_rollback.sql'));
            $this->assertSame(0, (int)$this->fila("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='correos_salida' AND COLUMN_NAME='usuario_id'")['c']);
            $despues = $this->evento($email);
            $this->assertSame($antes['carga_cifrada'], $despues['carga_cifrada'], 'el rollback no pierde eventos pendientes');
            $this->assertSame($antes['estado'], $despues['estado']);
            $this->ejecutarSql(file_get_contents($migraciones . '019_correos_cola_usuario_rollback.sql')); // idempotente
            $this->ejecutarSql(file_get_contents($migraciones . '019_correos_cola_usuario.sql'));
            $this->ejecutarSql(file_get_contents($migraciones . '019_correos_cola_usuario.sql')); // idempotente
            $this->assertSame($id, (int)$this->evento($email)['usuario_id'], 'el retro-relleno recupera el usuario desde la clave única');
        } finally {
            $this->ejecutarSql(file_get_contents($migraciones . '019_correos_cola_usuario.sql'));
        }
    }

    private function ejecutarSql(string $sql): void
    {
        $this->assertTrue($this->db->multi_query($sql), $this->db->error);
        do { if ($r = $this->db->store_result()) { $r->free(); } } while ($this->db->more_results() && $this->db->next_result());
        $this->assertSame('', $this->db->error);
    }

    public function test_el_worker_de_correos_esta_cableado_en_el_supervisor_y_documentado(): void
    {
        $supervisor = (string)file_get_contents(self::RAIZ . '/scripts/notificaciones_worker.php');
        $this->assertStringContainsString('procesar_correos.php', $supervisor);
        $compose = (string)file_get_contents(self::RAIZ . '/docker-compose.yml');
        $this->assertStringContainsString('scripts/notificaciones_worker.php', $compose, 'el servicio worker ejecuta el supervisor que procesa la cola de correos');
        $doc = (string)file_get_contents(self::RAIZ . '/docs/NOTIFICACIONES_WORKER.md');
        foreach (['Cola de correos de cuenta', 'procesar_correos.php --estado', '--reintentar=todos-fallidos', 'docker compose', 'al menos una vez'] as $frase) {
            $this->assertStringContainsString($frase, $doc);
        }
    }
}

<?php

namespace Tests\Qa;

require_once __DIR__ . '/QaCase.php';

/**
 * Base de las pruebas HTTP del módulo «agenda y atención» (ronda 5, agente a5).
 * Arranca su propio servidor embebido en el rango reservado 8130-8139 (QaCase::servidor() compite por 8150-8199), apunta el correo a un
 * puerto SMTP reservado y SIN servidor escuchando (2630: nada sale), y ofrece fixtures de citas con el reloj del negocio (America/Guayaquil).
 */
abstract class R5DevAgendaCase extends QaCase
{
    /** @var array<string,array{proc:mixed,url:string,log:string,sess:string}> */
    private static array $propios = [];

    protected static function servidorA5(string $clave = 'a5', array $env = []): string
    {
        if (isset(self::$propios[$clave])) { return self::$propios[$clave]['url']; }
        $port = 0;
        for ($candidato = 8130; $candidato <= 8139 && $port === 0; $candidato++) {
            $s = @stream_socket_server("tcp://127.0.0.1:$candidato", $en, $es);
            if ($s) { fclose($s); $port = $candidato; }
        }
        if ($port === 0) { throw new \RuntimeException('Sin puertos HTTP libres en 8130-8139'); }
        $log = sys_get_temp_dir() . '/a5_web_' . $port . '.log';
        $sess = sys_get_temp_dir() . '/a5_sess_' . $port;
        foreach ([$log, $log . '.out', $log . '.err'] as $viejo) { @unlink($viejo); }
        foreach (glob($sess . '/sess_*') ?: [] as $viejo) { @unlink($viejo); }
        @mkdir($sess, 0777, true);
        $envp = array_merge(getenv(), ['DB_NAME' => self::nombreBd(), 'APP_ENV' => 'testing', 'EMAIL_HOST' => '127.0.0.1', 'EMAIL_PORT' => '2639', 'EMAIL_USER' => 't', 'EMAIL_PASSWORD' => 't', 'EMAIL_FORCE_SMTP' => '1'], $env);
        foreach ($env as $k => $v) { if ($v === null) { unset($envp[$k]); } }
        $p = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', '-d', 'error_log=' . $log, '-d', 'session.save_path=' . $sess, '-S', "127.0.0.1:$port", '-t', self::RAIZ . '/public', __DIR__ . '/router.php'],
            [1 => ['file', $log . '.out', 'a'], 2 => ['file', $log . '.err', 'a']], $pipes, self::RAIZ, $envp);
        for ($i = 0; $i < 50; $i++) {
            $c = @fsockopen('127.0.0.1', $port, $en, $es, 0.2);
            if ($c) { fclose($c); break; }
            usleep(100000);
        }
        self::$propios[$clave] = ['proc' => $p, 'url' => "http://127.0.0.1:$port", 'log' => $log, 'sess' => $sess];
        register_shutdown_function(static function () use ($clave): void {
            if (isset(self::$propios[$clave])) { @proc_terminate(self::$propios[$clave]['proc']); }
        });
        return "http://127.0.0.1:$port";
    }

    protected static function pararServidorA5(string $clave = 'a5'): void
    {
        if (isset(self::$propios[$clave])) { @proc_terminate(self::$propios[$clave]['proc']); proc_close(self::$propios[$clave]['proc']); unset(self::$propios[$clave]); }
    }

    protected static function logA5(string $clave = 'a5'): string
    {
        $l = self::$propios[$clave]['log'] ?? null;
        return $l && is_file($l) ? (string)file_get_contents($l) : '';
    }

    /** Instante actual en la zona del negocio. */
    protected static function ahoraNegocio(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('America/Guayaquil'));
    }

    protected static function diaNegocio(int $delta = 0): string
    {
        return self::ahoraNegocio()->modify(($delta >= 0 ? '+' : '') . "$delta days")->format('Y-m-d');
    }

    /** Cita con un servicio histórico; devuelve su id. $hora = 'HH:MM[:SS]'. */
    protected static function crearCita(int $clienteId, int $profesionalId, int $servicioId, string $fecha, string $hora, string $estado = 'confirmada', string $precio = '20.00', string $estadoPago = 'pendiente'): int
    {
        $hora = strlen($hora) === 5 ? $hora . ':00' : $hora;
        $fin = date('H:i:s', strtotime("$hora +30 minutes"));
        self::q('INSERT INTO citas(fecha,hora,hora_inicio,hora_fin,duracion_total_minutos,usuarioId,profesionalId,estado,estado_pago) VALUES (?,?,?,?,30,?,?,?,?)', [$fecha, $hora, $hora, $fin, $clienteId, $profesionalId, $estado, $estadoPago]);
        $id = (int)self::db()->insert_id;
        self::q('INSERT INTO citasservicios(citaId,servicioId,nombre_servicio,precio_servicio,duracion_minutos) VALUES (?,?,?,?,30)', [$id, $servicioId, 'Corte histórico', $precio]);
        return $id;
    }

    /** Añade la membresía de cliente a una cuenta de equipo (doble membresía). */
    protected static function conMembresiaCliente(int $usuarioId): void
    {
        self::q("INSERT INTO clientes(usuario_id,estado,origen) VALUES (?, 'activo', 'registro_publico') ON DUPLICATE KEY UPDATE estado='activo'", [$usuarioId]);
    }

    /** Navegador con sesión de equipo. */
    protected static function entrar(string $base, string $portal, array $persona): Navegador
    {
        self::q('TRUNCATE intentos_login');
        $n = new Navegador($base);
        $r = $n->login($portal, $persona['email'], $persona['password']);
        if ($r['status'] !== 302) { throw new \RuntimeException("Login $portal falló: {$r['status']}"); }
        return $n;
    }

    /** Envía POST /agenda/estado con el token vigente de la sesión (como lo hace el formulario). */
    protected static function postEstado(Navegador $n, int $cita, string $estado, array $extra = []): array
    {
        $csrf = $n->csrf('/perfil');
        return $n->post('/agenda/estado', ['csrf_token' => $csrf, 'id' => $cita, 'estado' => $estado] + $extra);
    }

    protected static function eventos(int $cita): int
    {
        return (int)self::escalar("SELECT COUNT(*) FROM agenda_eventos WHERE citaId=? AND tipo='estado'", [$cita]);
    }

    /** Enlaces (href) de un HTML. */
    protected static function enlaces(string $html): array
    {
        preg_match_all('~href="([^"]+)"~', $html, $m);
        return array_values(array_unique(array_map('html_entity_decode', $m[1])));
    }
}

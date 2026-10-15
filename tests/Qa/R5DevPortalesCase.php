<?php

namespace Tests\Qa;

require_once __DIR__ . '/QaCase.php';

/**
 * Base de las pruebas HTTP del módulo «portales, permisos y navegación» (ronda 5, agente n5).
 * Arranca su propio servidor embebido en el rango reservado 8170-8179 (QaCase::servidor() compite por 8150-8199) y ofrece
 * personas (equipo por rol, cliente, doble membresía) creadas por los mismos repositorios que usa producción.
 */
abstract class R5DevPortalesCase extends QaCase
{
    /** @var array<string,array{proc:mixed,url:string,log:string,sess:string}> */
    private static array $propios = [];

    protected static function servidorPropio(string $clave = 'n5', array $env = []): string
    {
        if (isset(self::$propios[$clave])) { return self::$propios[$clave]['url']; }
        $port = 0;
        for ($candidato = 8170; $candidato <= 8179 && $port === 0; $candidato++) {
            $s = @stream_socket_server("tcp://127.0.0.1:$candidato", $en, $es);
            if ($s) { fclose($s); $port = $candidato; }
        }
        if ($port === 0) { throw new \RuntimeException('Sin puertos HTTP libres en 8170-8179'); }
        $log = sys_get_temp_dir() . '/n5_web_' . $port . '.log';
        $sess = sys_get_temp_dir() . '/n5_sess_' . $port;
        foreach ([$log, $log . '.out', $log . '.err'] as $viejo) { @unlink($viejo); }
        foreach (glob($sess . '/sess_*') ?: [] as $viejo) { @unlink($viejo); }
        @mkdir($sess, 0777, true);
        $envp = array_merge(getenv(), ['DB_NAME' => self::nombreBd(), 'APP_ENV' => 'testing', 'EMAIL_HOST' => '127.0.0.1', 'EMAIL_PORT' => '2669', 'EMAIL_USER' => 't', 'EMAIL_PASSWORD' => 't', 'EMAIL_FORCE_SMTP' => '1'], $env);
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

    protected static function pararServidorPropio(string $clave = 'n5'): void
    {
        if (isset(self::$propios[$clave])) { @proc_terminate(self::$propios[$clave]['proc']); proc_close(self::$propios[$clave]['proc']); unset(self::$propios[$clave]); }
    }

    protected static function logPropio(string $clave = 'n5'): string
    {
        $l = self::$propios[$clave]['log'] ?? null;
        return $l && is_file($l) ? (string)file_get_contents($l) : '';
    }

    /** Sesión PHP arbitraria en el almacén del servidor propio (navegador con una sesión manipulada o antigua). */
    protected static function sesionForjada(array $datos, string $clave = 'n5'): string
    {
        $dir = self::$propios[$clave]['sess'] ?? throw new \RuntimeException('Servidor no iniciado');
        $id = bin2hex(random_bytes(16));
        $raw = '';
        foreach ($datos as $k => $v) { $raw .= $k . '|' . serialize($v); }
        file_put_contents($dir . '/sess_' . $id, $raw);
        return $id;
    }

    /** Añade a una cuenta de equipo la membresía de cliente (doble membresía). */
    protected static function conMembresiaCliente(int $usuarioId, string $estado = 'activo'): void
    {
        self::q("INSERT INTO clientes(usuario_id,estado,origen) VALUES (?,?, 'registro_publico') ON DUPLICATE KEY UPDATE estado=VALUES(estado)", [$usuarioId, $estado]);
    }

    /** Quita la membresía de cliente de una cuenta (queda solo laboral). */
    protected static function sinMembresiaCliente(int $usuarioId): void
    {
        self::q('DELETE FROM clientes WHERE usuario_id=?', [$usuarioId]);
    }

    /** Sube la versión de sesión de la cuenta (lo que hace un cambio de rol o de contraseña): las sesiones abiertas dejan de valer. */
    protected static function invalidarSesiones(int $usuarioId): void
    {
        self::q('UPDATE usuarios SET version_sesion=version_sesion+1 WHERE id=?', [$usuarioId]);
    }

    /** Inicia sesión por un portal y devuelve el navegador. */
    protected static function entrar(string $base, string $portal, array $persona): Navegador
    {
        self::q('TRUNCATE intentos_login');
        $n = new Navegador($base);
        $r = $n->login($portal, $persona['email'], $persona['password']);
        if ($r['status'] !== 302) { throw new \RuntimeException("Login $portal de {$persona['email']} falló: {$r['status']}"); }
        return $n;
    }

    /** Todos los href y action locales (rutas) de un HTML, sin fragmentos ni duplicados. @return string[] */
    protected static function enlaces(string $html): array
    {
        preg_match_all('~(?:href|action)="([^"]*)"~i', $html, $m);
        $out = [];
        foreach ($m[1] as $h) {
            $h = html_entity_decode($h, ENT_QUOTES);
            if ($h === '' || $h[0] === '#' || !str_starts_with($h, '/') || str_starts_with($h, '//')) { continue; }
            $ruta = explode('#', $h, 2)[0];
            $out[$ruta] = true;
        }
        return array_keys($out);
    }
}

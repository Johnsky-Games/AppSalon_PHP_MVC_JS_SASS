<?php

namespace Tests\Qa;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Navegador.php';

/**
 * Base de las pruebas del QA independiente. Solo opera sobre bases `appsalon_qa_*` terminadas en `_test`.
 * Ejecutar con: DB_NAME=appsalon_qa_test (ver docs/QA_INDEPENDIENTE_IDENTIDADES.md).
 */
abstract class QaCase extends TestCase
{
    public const RAIZ = __DIR__ . '/../..';
    public const PASS = 'Qa-Passw0rd-12345';

    /** @var \mysqli|null */
    private static $conexion = null;

    protected static function nombreBd(): string
    {
        $n = (string)($_ENV['DB_NAME'] ?? getenv('DB_NAME'));
        if (!preg_match('/^appsalon_qa_[a-z0-9_]*test$/', $n)) {
            throw new \RuntimeException("QA: base de datos no autorizada '{$n}'. Usa DB_NAME=appsalon_qa_test.");
        }
        return $n;
    }

    protected static function db(): \mysqli
    {
        self::nombreBd();
        if (self::$conexion === null || !@self::$conexion->ping()) {
            mysqli_report(MYSQLI_REPORT_OFF);
            self::$conexion = new \mysqli('127.0.0.1', 'root', 'root', self::nombreBd(), 3306);
            self::$conexion->set_charset('utf8mb4');
            \Model\ActiveRecord::setDB(self::$conexion);
        }
        return self::$conexion;
    }

    protected static function q(string $sql, array $params = []): array
    {
        $db = self::db();
        if ($params) {
            $st = $db->prepare($sql);
            if (!$st) { throw new \RuntimeException($db->error . ' :: ' . $sql); }
            $tipos = '';
            foreach ($params as $p) { $tipos .= is_int($p) ? 'i' : 's'; }
            $st->bind_param($tipos, ...$params);
            if (!$st->execute()) { throw new \RuntimeException($st->error . ' :: ' . $sql); }
            $r = $st->get_result();
            $f = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
            $st->close();
            return $f;
        }
        $r = $db->query($sql);
        if ($r === false) { throw new \RuntimeException($db->error . ' :: ' . $sql); }
        return $r === true ? [] : $r->fetch_all(MYSQLI_ASSOC);
    }

    protected static function escalar(string $sql, array $params = []): mixed
    {
        $f = self::q($sql, $params);
        return $f ? array_values($f[0])[0] : null;
    }

    /** Vacía todas las tablas excepto `migraciones` y `equipo_mutex` (semilla). */
    protected static function limpiar(): void
    {
        $db = self::db();
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (self::q("SELECT table_name t FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE'") as $r) {
            if (in_array($r['t'], ['migraciones', 'equipo_mutex'], true)) { continue; }
            $db->query('TRUNCATE TABLE `' . $r['t'] . '`');
        }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
    }

    private static int $seq = 0;

    protected static function correo(string $pref = 'qa'): string
    {
        return $pref . '.' . getmypid() . '.' . (++self::$seq) . '.' . bin2hex(random_bytes(3)) . '@example.test';
    }

    /** Propietario real, provisto por el mismo camino que el instalador (con secreto de instalación). */
    protected static function propietario(?string $email = null): array
    {
        $email ??= self::correo('owner');
        $r = (new \Repositories\ColaboradorRepository(self::db()))->aprovisionarPropietarioInicial($email, 'Due', 'Ño', self::PASS . 'x');
        $id = (int)self::escalar('SELECT id FROM usuarios WHERE email=?', [$email]);
        return ['id' => $id, 'email' => $email, 'password' => self::PASS . 'x', 'estado' => $r['estado']];
    }

    /** Cliente real (identidad + membresía) por el repositorio de producción, confirmado. */
    protected static function cliente(array $o = []): array
    {
        $email = $o['email'] ?? self::correo('cli');
        $u = new \Model\Usuario(['nombre' => $o['nombre'] ?? 'Cli', 'apellido' => $o['apellido'] ?? 'Ente', 'email' => $email, 'password' => password_hash($o['password'] ?? self::PASS, PASSWORD_BCRYPT), 'telefono' => $o['telefono'] ?? '0991234567']);
        $u->confirmado = ($o['confirmado'] ?? true) ? '1' : '0';
        $id = (new \Repositories\UsuarioRepository(self::db()))->createCliente($u, $o['origen'] ?? 'registro_publico');
        if (!empty($o['estado']) && $o['estado'] !== 'activo') {
            self::q("UPDATE clientes SET estado=? WHERE usuario_id=?", [$o['estado'], $id]);
        }
        return ['id' => $id, 'email' => $email, 'password' => $o['password'] ?? self::PASS];
    }

    /**
     * Miembro del equipo activo creado por el flujo real (invitación + aceptación) para garantizar coherencia de filas.
     * @return array{id:int,email:string,password:string,colaborador_id:int,rol:string}
     */
    protected static function miembro(int $propietarioId, string $rol, array $o = []): array
    {
        $email = $o['email'] ?? self::correo($rol);
        $repo = new \Repositories\ColaboradorRepository(self::db());
        $d = ['nombre' => $o['nombre'] ?? 'Mi', 'apellido' => $o['apellido'] ?? 'Embro', 'email' => $email, 'telefono' => null, 'puesto' => $o['puesto'] ?? 'Puesto', 'rol' => $rol,
            'fecha_ingreso' => null, 'servicios' => [], 'nombre_profesional' => ($o['nombre'] ?? 'Mi') . ' Embro ' . bin2hex(random_bytes(2)), 'profesional_id' => null];
        if ($rol === 'profesional') {
            $sid = self::servicio();
            $d['servicios'] = [$sid];
        }
        $r = $repo->crearInvitacion($propietarioId, $d, 0);
        $token = self::tokenDeInvitacion((int)$r['invitacion_id']);
        $pass = $o['password'] ?? self::PASS;
        $repo->aceptar($token, $pass, $o['password_actual'] ?? '');
        return ['id' => (int)self::escalar('SELECT usuario_id FROM colaboradores WHERE id=?', [$r['colaborador_id']]), 'email' => $email, 'password' => $pass,
            'colaborador_id' => $r['colaborador_id'], 'rol' => $rol, 'profesional_id' => $r['profesional_id']];
    }

    /** Recupera el token en claro desde el outbox cifrado (el correo real nunca se envía en estas pruebas). */
    protected static function tokenDeInvitacion(int $invitacionId): string
    {
        $f = self::q('SELECT carga_cifrada FROM correos_salida WHERE clave_unica=?', ['invitacion:' . $invitacionId]);
        $c = \Classes\CargaCifrada::descifrar((string)($f[0]['carga_cifrada'] ?? ''));
        if (!$c) { throw new \RuntimeException('Sin carga para invitacion ' . $invitacionId); }
        return $c['token'];
    }

    protected static function servicio(string $nombre = 'Corte QA', float $precio = 10.00, int $min = 30): int
    {
        $cols = array_column(self::q('SHOW COLUMNS FROM servicios'), 'Field');
        $datos = ['nombre' => $nombre . ' ' . bin2hex(random_bytes(2)), 'precio' => $precio, 'duracion_minutos' => $min];
        $datos = array_intersect_key($datos, array_flip($cols));
        $campos = implode(',', array_keys($datos));
        $marcas = implode(',', array_fill(0, count($datos), '?'));
        self::q("INSERT INTO servicios ($campos) VALUES ($marcas)", array_values($datos));
        return (int)self::db()->insert_id;
    }

    // ------------------------------------------------------------------ procesos con barrera

    /**
     * Lanza N procesos worker que esperan a la misma marca de tiempo y ejecutan la acción a la vez.
     * @param array<int,array{accion:string,args:array}> $trabajos
     * @return array<int,array> resultado JSON de cada worker
     */
    protected static function concurrente(array $trabajos, float $holguraSeg = 1.2): array
    {
        $inicio = microtime(true) + $holguraSeg;
        $procs = [];
        foreach ($trabajos as $i => $t) {
            $cmd = [PHP_BINARY, '-d', 'variables_order=EGPCS', __DIR__ . '/worker.php', $t['accion'], json_encode($t['args']), sprintf('%.6F', $inicio)];
            $pipes = [];
            $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::RAIZ, array_merge(getenv(), ['DB_NAME' => self::nombreBd()]));
            if (!is_resource($p)) { throw new \RuntimeException('No se pudo lanzar worker'); }
            $procs[$i] = [$p, $pipes];
        }
        $res = [];
        foreach ($procs as $i => [$p, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $code = proc_close($p);
            $j = json_decode(trim($out), true);
            $res[$i] = is_array($j) ? $j + ['stderr' => $err, 'exit' => $code] : ['ok' => false, 'raw' => $out, 'stderr' => $err, 'exit' => $code];
        }
        return $res;
    }

    // ------------------------------------------------------------------ servidor HTTP real

    /** @var array<string,array> */
    private static array $servidores = [];

    /** Arranca (una vez por clase) el servidor embebido apuntando a la base QA. Devuelve la URL base. */
    protected static function servidor(array $env = [], string $clave = 'base'): string
    {
        if (isset(self::$servidores[$clave])) { return self::$servidores[$clave]['url']; }
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int)explode(':', stream_socket_get_name($s, false))[1];
        fclose($s);
        $log = sys_get_temp_dir() . '/qa_web_' . $port . '.log';
        $sess = sys_get_temp_dir() . '/qa_sess_' . $port;
        @mkdir($sess, 0777, true);
        $envp = array_merge(getenv(), ['DB_NAME' => self::nombreBd(), 'APP_ENV' => 'testing', 'EMAIL_HOST' => '127.0.0.1', 'EMAIL_PORT' => '2599', 'EMAIL_USER' => 't', 'EMAIL_PASSWORD' => 't', 'EMAIL_FORCE_SMTP' => '1'], $env);
        $p = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', '-d', 'error_log=' . $log, '-d', 'session.save_path=' . $sess, '-S', "127.0.0.1:$port", '-t', self::RAIZ . '/public', __DIR__ . '/router.php'],
            [1 => ['file', $log . '.out', 'a'], 2 => ['file', $log . '.err', 'a']], $pipes, self::RAIZ, $envp);
        for ($i = 0; $i < 50; $i++) {
            $c = @fsockopen('127.0.0.1', $port, $en, $es, 0.2);
            if ($c) { fclose($c); break; }
            usleep(100000);
        }
        self::$servidores[$clave] = ['proc' => $p, 'url' => "http://127.0.0.1:$port", 'log' => $log, 'sess' => $sess];
        register_shutdown_function(static function () use ($clave): void {
            if (isset(self::$servidores[$clave])) { @proc_terminate(self::$servidores[$clave]['proc']); }
        });
        return "http://127.0.0.1:$port";
    }

    protected static function logServidor(string $clave = 'base'): string
    {
        $l = self::$servidores[$clave]['log'] ?? null;
        return $l && is_file($l) ? (string)file_get_contents($l) : '';
    }

    /** Escribe una sesión PHP arbitraria en el almacén del servidor de prueba (simula un navegador que presenta una sesión manipulada). */
    protected static function forjarSesion(array $datos, string $clave = 'base'): string
    {
        $dir = self::$servidores[$clave]['sess'] ?? throw new \RuntimeException('Servidor no iniciado');
        $id = bin2hex(random_bytes(16));
        $raw = '';
        foreach ($datos as $k => $v) { $raw .= $k . '|' . serialize($v); }
        file_put_contents($dir . '/sess_' . $id, $raw);
        return $id;
    }

    protected static function pararServidor(string $clave = 'base'): void
    {
        if (isset(self::$servidores[$clave])) { @proc_terminate(self::$servidores[$clave]['proc']); proc_close(self::$servidores[$clave]['proc']); unset(self::$servidores[$clave]); }
    }

    protected static function http(string $base = null): Navegador
    {
        return new Navegador($base ?? self::servidor());
    }
}

/** Utilidad local de claves de operación válidas. */
final class Idem
{
    public static function clave(): string { return bin2hex(random_bytes(16)); }
}

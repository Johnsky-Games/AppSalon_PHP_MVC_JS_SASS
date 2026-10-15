<?php

namespace Tests\Qa;

require_once __DIR__ . '/R5Case.php';

/**
 * Arnés de la ronda 5b del QA independiente: REGULARIZACIÓN DE CITAS DE DÍAS ANTERIORES (docs/R5_REGULARIZACION.md).
 * Puertos HTTP 8190-8199 (propios), SMTP 2591, BD appsalon_qa_q5_test. Los formularios se leen del detalle de la cita (/agenda/cita?id=N) tal como los ve un navegador.
 */
abstract class R5bCase extends R5Case
{
    public const B_MIN = 8190;
    public const B_MAX = 8199;

    /** @var array<string,array{proc:mixed,url:string,log:string,sess:string}> */
    private static array $srvB = [];

    protected static function servidorB(string $clave, array $env = []): string
    {
        if (isset(self::$srvB[$clave])) { return self::$srvB[$clave]['url']; }
        $port = 0;
        for ($c = self::B_MAX; $c >= self::B_MIN && $port === 0; $c--) {
            $s = @stream_socket_server("tcp://127.0.0.1:$c", $en, $es);
            if ($s) { fclose($s); $port = $c; }
        }
        if ($port === 0) { throw new \RuntimeException('Sin puertos HTTP libres en 8190-8199'); }
        self::iniciarSmtp(2591);
        $log = sys_get_temp_dir() . '/q5b_web_' . $port . '.log';
        $sess = sys_get_temp_dir() . '/q5b_sess_' . $port;
        foreach ([$log, $log . '.out', $log . '.err'] as $v) { @unlink($v); }
        foreach (glob($sess . '/sess_*') ?: [] as $v) { @unlink($v); }
        @mkdir($sess, 0777, true);
        $envp = array_merge(getenv(), ['DB_NAME' => self::nombreBd(), 'APP_ENV' => 'testing', 'EMAIL_HOST' => '127.0.0.1', 'EMAIL_PORT' => '2591', 'EMAIL_USER' => 't', 'EMAIL_PASSWORD' => 't',
            'EMAIL_FORCE_SMTP' => '1', 'PHP_CLI_SERVER_WORKERS' => '8'], $env);
        $p = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', '-d', 'error_log=' . $log, '-d', 'session.save_path=' . $sess, '-S', "127.0.0.1:$port", '-t', self::RAIZ . '/public', __DIR__ . '/router.php'],
            [1 => ['file', $log . '.out', 'a'], 2 => ['file', $log . '.err', 'a']], $pipes, self::RAIZ, $envp);
        for ($i = 0; $i < 60; $i++) { $c = @fsockopen('127.0.0.1', $port, $en, $es, 0.2); if ($c) { fclose($c); break; } usleep(100000); }
        self::$srvB[$clave] = ['proc' => $p, 'url' => "http://127.0.0.1:$port", 'log' => $log, 'sess' => $sess];
        register_shutdown_function(static function () use ($clave): void { if (isset(self::$srvB[$clave])) { @proc_terminate(self::$srvB[$clave]['proc']); } });
        return "http://127.0.0.1:$port";
    }

    protected static function pararServidorB(string $clave): void
    {
        if (isset(self::$srvB[$clave])) { @proc_terminate(self::$srvB[$clave]['proc']); @proc_close(self::$srvB[$clave]['proc']); unset(self::$srvB[$clave]); }
    }

    protected static function logB(string $clave): string
    {
        $l = self::$srvB[$clave]['log'] ?? null;
        return $l && is_file($l) ? (string)file_get_contents($l) : '';
    }

    protected static function sesionB(string $sid, string $clave): string { return (self::$srvB[$clave]['sess'] ?? '') . '/sess_' . $sid; }

    // ------------------------------------------------------------------ escenario

    /** Escenario + horario completo (09-18, todos los días) para los dos profesionales y comisión del 10 % para ProA. */
    protected static function escenarioB(): array
    {
        $e = self::escenario();
        foreach (['proA', 'proB'] as $p) {
            $pid = (int)$e[$p]['profesional_id'];
            self::q('DELETE FROM horarios_profesionales WHERE profesionalId=?', [$pid]);
            for ($d = 1; $d <= 7; $d++) { self::q('INSERT INTO horarios_profesionales(profesionalId,dia_semana,hora_inicio,hora_fin) VALUES (?,?,?,?)', [$pid, $d, '09:00:00', '18:00:00']); }
            self::q('INSERT IGNORE INTO profesionales_servicios(profesionalId,servicioId) VALUES (?,?)', [$pid, $e['svc']]);
        }
        self::q('INSERT INTO comisiones_config(profesional_id,porcentaje_pb,actor_id) VALUES (?,1000,?) ON DUPLICATE KEY UPDATE porcentaje_pb=1000', [$e['proA']['profesional_id'], $e['dueno']['id']]);
        return $e;
    }

    /** Cita de un día anterior (por omisión confirmada, hace 3 días, ProA, $20.00). */
    protected static function citaVieja(array $e, array $o = []): int
    {
        return self::crearCita($o + ['cliente' => $e['cliente']['id'], 'pro' => $e['proA']['profesional_id'], 'fecha' => self::gye('-' . ($o['dias'] ?? 3) . ' days')->format('Y-m-d'), 'hora' => '10:00:00', 'estado' => 'confirmada']);
    }

    protected static function cita(int $id): array { return self::q('SELECT * FROM citas WHERE id=?', [$id])[0]; }
    protected static function evs(int $id): array { return self::q('SELECT * FROM agenda_eventos WHERE citaId=? ORDER BY id', [$id]); }
    protected static function regs(int $id): array { return self::q('SELECT * FROM citas_regularizaciones WHERE cita_id=? ORDER BY id', [$id]); }

    /** Segundos de diferencia entre una fecha-hora de negocio (Guayaquil) y ahora. */
    protected static function difSeg(?string $dt): int
    {
        return $dt === null ? PHP_INT_MAX : (int)abs(time() - strtotime($dt . ' ' . self::ZONA));
    }

    /** Valor datetime-local válido: el día de la cita a las HH:MM. */
    protected static function efectiva(int $cita, string $hm = '11:00'): string { return self::cita($cita)['fecha'] . 'T' . $hm; }

    // ------------------------------------------------------------------ formularios del detalle

    protected function detalle(Navegador $n, int $cita): array
    {
        $r = $n->get("/agenda/cita?id=$cita");
        $this->assertSame(200, $r['status'], "GET /agenda/cita?id=$cita → {$r['status']} " . substr(self::texto($r['body']), 0, 160));
        return $r;
    }

    protected function formDe(Navegador $n, int $cita, string $accion): array
    {
        $re = ['regularizar' => '~/agenda/regularizar$~', 'solicitar' => '~/agenda/regularizar/solicitar$~', 'rechazar' => '~/agenda/regularizar/rechazar$~', 'reprogramar' => '~/agenda/reprogramar$~', 'estado' => '~/agenda/estado$~'][$accion];
        $f = self::formulario($this->detalle($n, $cita)['body'], $re);
        $this->assertNotNull($f, "el detalle de la cita #$cita no ofrece el formulario «{$accion}»");
        return $f;
    }

    /** Envía el formulario con campos retocados; `idem` se renueva por omisión (cada envío legítimo es una operación nueva). */
    protected function enviarB(Navegador $n, array $form, array $cambios = [], array $quitar = [], bool $claveNueva = true): array
    {
        $k = self::campo($form, '/idem/i');
        if ($claveNueva && $k && !array_key_exists($k, $cambios)) { $cambios[$k] = bin2hex(random_bytes(12)); }
        return self::enviar($n, $form, $cambios, $quitar);
    }

    protected function regularizar(Navegador $n, int $cita, array $cambios = [], ?array $form = null): array
    {
        $form ??= $this->formDe($n, $cita, 'regularizar');
        return $this->enviarB($n, $form, $cambios + ['efectiva' => self::efectiva($cita), 'motivo' => 'El servicio sí se realizó y no se registró a tiempo']);
    }

    protected function aceptado(array $r, string $ctx): void
    {
        $this->assertContains($r['status'], [200, 302, 303], "$ctx: debió aceptarse; respuesta {$r['status']}: " . substr(self::texto($r['body']), 0, 240));
    }

    /** Rechazo limpio: código esperado, página completa, sin enlaces cruzados. */
    protected function rechazo(array $r, array $codigos, string $ctx): void
    {
        $this->assertContains($r['status'], $codigos, "$ctx: esperado " . implode('/', $codigos) . ", respuesta {$r['status']}: " . substr(self::texto($r['body']), 0, 200));
        $this->assertLessThan(500, $r['status'], "$ctx: 5xx");
        if (str_contains($r['body'], '<html') || str_contains($r['body'], '<!DOCTYPE')) {
            $this->assertSame([], self::enlacesCruzados($r['body'], 'equipo'), "$ctx: el error enlaza al portal del cliente");
        }
    }

    protected function eq(string $base, array $e, string $rol): Navegador { return self::entrar($base, $e[$rol]); }

    /** Cuerpo POST de un formulario con cambios (para ráfagas). */
    protected function cuerpoB(array $form, array $cambios = []): array
    {
        $k = self::campo($form, '/idem/i');
        if ($k && !array_key_exists($k, $cambios)) { $cambios[$k] = bin2hex(random_bytes(12)); }
        return array_merge($form['campos'], $cambios);
    }
}

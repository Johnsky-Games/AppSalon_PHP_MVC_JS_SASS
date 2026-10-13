<?php

namespace Tests\Qa;

require_once __DIR__ . '/QaCase.php';

/**
 * Utilidades comunes de la reverificación de la ronda 2B (servidores SMTP con fallos, worker real en proceso aparte,
 * registro público por HTTP y firmas de respuesta comparables). No contiene pruebas.
 */
abstract class R2bCase extends QaCase
{
    /** @var array<int,array{proc:mixed,buzon:string}> */
    private static array $smtpFallo = [];

    /** Arranca el SMTP con fallos (puertos 2590-2597). Devuelve la ruta del buzón. */
    protected static function iniciarSmtpFallo(int $port, string $modo = 'ok', float $retardo = 0.0): string
    {
        if ($port < 2590 || $port > 2599) { throw new \RuntimeException("Puerto SMTP fuera del rango reservado al QA: $port"); }
        self::detenerSmtpFallo($port);
        $buzon = sys_get_temp_dir() . "/qa_buzon_fallo_$port.json";
        $p = proc_open([PHP_BINARY, __DIR__ . '/smtp_fallo.php', (string)$port, $modo, $buzon, (string)$retardo], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 50; $i++) { $c = @fsockopen('127.0.0.1', $port, $en, $es, 0.2); if ($c) { fclose($c); break; } usleep(100000); }
        self::$smtpFallo[$port] = ['proc' => $p, 'buzon' => $buzon];
        register_shutdown_function(static function () use ($port): void { self::detenerSmtpFallo($port); });
        return $buzon;
    }

    protected static function modoSmtp(int $port, string $modo): void
    {
        file_put_contents((self::$smtpFallo[$port]['buzon'] ?? throw new \RuntimeException('SMTP no iniciado')) . '.modo', $modo);
    }

    protected static function detenerSmtpFallo(int $port): void
    {
        if (isset(self::$smtpFallo[$port])) {
            @proc_terminate(self::$smtpFallo[$port]['proc']);
            @proc_close(self::$smtpFallo[$port]['proc']);
            unset(self::$smtpFallo[$port]);
        }
    }

    /** @return array<int,array{to:array,cuerpo:string,cabeceras:string}> */
    protected static function buzonFallo(int $port): array
    {
        return json_decode((string)@file_get_contents(self::$smtpFallo[$port]['buzon'] ?? ''), true) ?: [];
    }

    protected static function vaciarBuzonFallo(int $port): void
    {
        file_put_contents(self::$smtpFallo[$port]['buzon'], '[]');
    }

    /**
     * Ejecuta `php scripts/procesar_correos.php …` en un proceso APARTE (el worker real).
     * @return array{exit:int,out:string,err:string,seg:float}
     */
    protected static function worker(array $args = [], array $env = [], float $limiteSeg = 120.0): array
    {
        $t0 = microtime(true);
        $p = proc_open(array_merge([PHP_BINARY, '-d', 'variables_order=EGPCS', self::RAIZ . '/scripts/procesar_correos.php'], $args),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::RAIZ,
            array_merge(getenv(), ['DB_NAME' => self::nombreBd(), 'APP_ENV' => 'testing', 'EMAIL_HOST' => '127.0.0.1', 'EMAIL_USER' => 't', 'EMAIL_PASSWORD' => 't', 'EMAIL_FORCE_SMTP' => '1'], $env));
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $out = $err = '';
        while (true) {
            $out .= (string)stream_get_contents($pipes[1]); $err .= (string)stream_get_contents($pipes[2]);
            $st = proc_get_status($p);
            if (!$st['running']) { break; }
            if (microtime(true) - $t0 > $limiteSeg) { proc_terminate($p, 9); $err .= "\n[QA] worker terminado por exceder $limiteSeg s"; break; }
            usleep(20000);
        }
        $out .= (string)stream_get_contents($pipes[1]); $err .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $codigo = proc_close($p);
        return ['exit' => $codigo, 'out' => $out, 'err' => $err, 'seg' => microtime(true) - $t0];
    }

    /** Hace que un evento esté listo para el worker (sin esperar los segundos de retroceso). */
    protected static function adelantarEventos(): void
    {
        self::q("UPDATE correos_salida SET proximo_intento = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE estado='pendiente'");
    }

    /**
     * Contrato nuevo de la fase 2: NINGÚN correo de cuenta sale dentro de la petición HTTP; solo lo entrega el worker. Este método es
     * ese worker: adelanta los reintentos vencidos, ejecuta `scripts/procesar_correos.php` como proceso y exige código 0.
     * @return array{exit:int,out:string,err:string,seg:float}
     */
    protected static function entregarCola(int $smtpPuerto, array $args = []): array
    {
        self::adelantarEventos();
        $w = self::worker($args, ['EMAIL_PORT' => (string)$smtpPuerto]);
        \PHPUnit\Framework\Assert::assertSame(0, $w['exit'], 'el worker de correos terminó con error: ' . $w['out'] . $w['err']);
        return $w;
    }

    /**
     * Envía el formulario de registro público como lo haría un navegador (CSRF y clave de operación reales de la página).
     * @param array<string,string> $campos sobrescribe/añade campos
     * @return array{status:int,headers:array,body:string,location:?string}
     */
    protected static function registrarHttp(Navegador $n, string $email, array $campos = [], ?string $ruta = null): array
    {
        $ruta ??= '/cliente/registro';
        $pag = $n->get($ruta);
        $csrf = Navegador::csrfDe($pag['body']);
        $k = Navegador::campoDe($pag['body'], 'idem_key');
        $datos = ['csrf_token' => $csrf, 'idem_key' => $k, 'nombre' => 'Ana', 'apellido' => 'Prueba', 'email' => $email, 'telefono' => '0991112222', 'password' => 'Abcdef123456-x'];
        return $n->post($ruta, $campos + $datos);
    }

    /** Firma comparable de una respuesta: todo lo observable salvo lo que es aleatorio por diseño (valores de cookie, fecha, csrf, clave de operación). */
    protected static function firma(array $r): array
    {
        $h = $r['headers'];
        unset($h['date'], $h['x-powered-by']);
        if (isset($h['set-cookie'])) {
            $h['set-cookie'] = array_map(static fn(string $c): string => preg_replace('/=[^;]*/', '=<v>', $c, 1) ?? $c, $h['set-cookie']);
            sort($h['set-cookie']);
        }
        ksort($h);
        $cuerpo = preg_replace(['/value="[a-f0-9]{64}"/', '/value="[a-f0-9]{32}"/'], 'value="<aleatorio>"', $r['body']);
        return ['status' => $r['status'], 'location' => $r['location'], 'headers' => $h, 'body' => $cuerpo, 'longitud' => strlen($cuerpo)];
    }

    protected static function mediana(array $v): float
    {
        sort($v);
        $n = count($v);
        return $n % 2 ? (float)$v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
    }

    /** Cuenta filas de correos_salida por tipo+estado. */
    protected static function colaPorTipo(): array
    {
        $r = [];
        foreach (self::q('SELECT tipo, estado, COUNT(*) n FROM correos_salida GROUP BY tipo, estado') as $f) { $r[$f['tipo'] . '/' . $f['estado']] = (int)$f['n']; }
        ksort($r);
        return $r;
    }

    /** Hash estable del contenido de tablas (detecta cualquier efecto colateral). */
    protected static function huella(array $tablas): string
    {
        $o = [];
        foreach ($tablas as $t) { $o[$t] = self::q("CHECKSUM TABLE `$t`")[0]['Checksum'] . ':' . self::escalar("SELECT COUNT(*) FROM `$t`"); }
        return json_encode($o);
    }

    // ------------------------------------------------------------------ migrador y respaldos (DEF-16)

    /** Ejecuta `php database/migrador.php <args>` contra la base aislada `$bd`. @return array{exit:int,out:string} */
    protected static function migradorCmd(string $bd, array $args): array
    {
        $p = proc_open(array_merge([PHP_BINARY, '-d', 'variables_order=EGPCS', self::RAIZ . '/database/migrador.php'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::RAIZ, array_merge(getenv(), ['DB_NAME' => $bd]));
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        return ['exit' => proc_close($p), 'out' => $out];
    }

    /** Respaldo con manifiesto (scripts/backup_db.php --gzip) de la base `$bd` en `$ruta` (.sql.gz). */
    protected static function respaldar(string $bd, string $ruta): void
    {
        $p = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', self::RAIZ . '/scripts/backup_db.php', "--db=$bd", "--output=$ruta", '--gzip'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::RAIZ, array_merge(getenv(), ['DB_NAME' => $bd]));
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        if (proc_close($p) !== 0) { throw new \RuntimeException('backup_db.php falló: ' . $out); }
    }

    /** Migraciones que `down --simular` declara bloqueadas (con datos de negocio) para la reversión indicada. @return string[] */
    protected static function migracionesBloqueantes(string $bd, ?string $hasta = null): array
    {
        $r = self::migradorCmd($bd, array_filter(['down', '--simular', $hasta ? "--hasta=$hasta" : null]));
        preg_match_all('/^\s{2}(\d{3}_[A-Za-z0-9_]+) \((?:NO )?aceptada\):/m', $r['out'], $m);
        return $m[1];
    }

    /**
     * Procedimiento SOPORTADO de reversión: respaldo verificable + aceptación explícita de cada migración que pierde datos.
     * @return array{exit:int,out:string,respaldo:string,aceptadas:string[]}
     */
    protected static function revertirSeguro(string $bd, ?string $hasta = null): array
    {
        $aceptadas = self::migracionesBloqueantes($bd, $hasta);
        $ruta = sys_get_temp_dir() . '/qa_rev_' . getmypid() . '_' . $bd . '.sql.gz';
        self::respaldar($bd, $ruta);
        $args = ['down'];
        if ($hasta) { $args[] = "--hasta=$hasta"; }
        foreach ($aceptadas as $a) { $args[] = "--aceptar-perdida=$a"; }
        $args[] = "--respaldo=$ruta";
        $r = self::migradorCmd($bd, $args);
        return $r + ['respaldo' => $ruta, 'aceptadas' => $aceptadas];
    }
}

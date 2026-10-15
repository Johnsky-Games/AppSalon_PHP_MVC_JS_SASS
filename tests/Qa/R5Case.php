<?php

namespace Tests\Qa;

require_once __DIR__ . '/QaCase.php';

/**
 * Arnés del QA independiente de la ronda 5 (agenda → atención → finalización → cobro → comprobante y conciliación).
 *
 * INDEPENDENCIA: nada de aquí depende de los nombres internos de campos de los formularios que escriban los demás agentes.
 * Los formularios se LEEN de la pantalla real (como lo hace un navegador) y se envían tal cual; las pruebas solo "retocan" el campo cuyo
 * nombre coincide con una expresión funcional (monto, método, referencia…). Si un formulario no existe, la prueba falla con un mensaje que
 * dice qué falta (p. ej. «GET /caja/cita?id=7 → 404»), que es la razón correcta de un fallo sobre la base sin integrar.
 *
 * Reglas del arnés: puertos HTTP 8150-8159 y SMTP 2590-2599 (reservados a q5), BD `appsalon_qa_q5_test`, sin correo real.
 */
abstract class R5Case extends QaCase
{
    public const PUERTO_MIN = 8150;
    public const PUERTO_MAX = 8159;
    public const SMTP_PUERTO = 2590;
    public const ZONA = 'America/Guayaquil';

    /** Rutas EXCLUSIVAS del portal de clientes (la ruta completa, sin consulta). */
    public const RUTAS_CLIENTE = ['/mis-citas', '/cita', '/mi-bienestar', '/api/mis-citas', '/cliente/login', '/cliente/registro', '/crear-cuenta', '/api/citas'];
    /** Prefijos EXCLUSIVOS del portal del equipo. */
    public const PREFIJOS_EQUIPO = ['/agenda', '/admin', '/caja', '/equipo', '/servicios', '/profesionales'];

    /** @var array<string,array{proc:mixed,url:string,log:string,sess:string}> */
    private static array $srvQ5 = [];
    private static bool $smtpQ5 = false;

    // ====================================================================================================== servidor

    /** Servidor HTTP real (php -S con 8 procesos) en 8150-8159. Una URL por clave y clase. */
    protected static function servidorQ5(string $clave = 'q5', array $env = []): string
    {
        if (isset(self::$srvQ5[$clave])) { return self::$srvQ5[$clave]['url']; }
        $port = 0;
        for ($c = self::PUERTO_MIN; $c <= self::PUERTO_MAX && $port === 0; $c++) {
            $s = @stream_socket_server("tcp://127.0.0.1:$c", $en, $es);
            if ($s) { fclose($s); $port = $c; }
        }
        if ($port === 0) { throw new \RuntimeException('Sin puertos HTTP libres en 8150-8159'); }
        if (!self::$smtpQ5) { self::iniciarSmtp(self::SMTP_PUERTO); self::$smtpQ5 = true; }
        $log = sys_get_temp_dir() . '/q5_web_' . $port . '.log';
        $sess = sys_get_temp_dir() . '/q5_sess_' . $port;
        foreach ([$log, $log . '.out', $log . '.err'] as $v) { @unlink($v); }
        foreach (glob($sess . '/sess_*') ?: [] as $v) { @unlink($v); }
        @mkdir($sess, 0777, true);
        $envp = array_merge(getenv(), ['DB_NAME' => self::nombreBd(), 'APP_ENV' => 'testing', 'EMAIL_HOST' => '127.0.0.1', 'EMAIL_PORT' => (string)self::SMTP_PUERTO, 'EMAIL_USER' => 't',
            'EMAIL_PASSWORD' => 't', 'EMAIL_FORCE_SMTP' => '1', 'PHP_CLI_SERVER_WORKERS' => '8'], $env);
        foreach ($env as $k => $v) { if ($v === null) { unset($envp[$k]); } }
        $p = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', '-d', 'error_log=' . $log, '-d', 'session.save_path=' . $sess, '-S', "127.0.0.1:$port", '-t', self::RAIZ . '/public', __DIR__ . '/router.php'],
            [1 => ['file', $log . '.out', 'a'], 2 => ['file', $log . '.err', 'a']], $pipes, self::RAIZ, $envp);
        for ($i = 0; $i < 60; $i++) {
            $c = @fsockopen('127.0.0.1', $port, $en, $es, 0.2);
            if ($c) { fclose($c); break; }
            usleep(100000);
        }
        self::$srvQ5[$clave] = ['proc' => $p, 'url' => "http://127.0.0.1:$port", 'log' => $log, 'sess' => $sess];
        register_shutdown_function(static function () use ($clave): void {
            if (isset(self::$srvQ5[$clave])) { @proc_terminate(self::$srvQ5[$clave]['proc']); }
        });
        return "http://127.0.0.1:$port";
    }

    protected static function pararServidorQ5(string $clave = 'q5'): void
    {
        if (isset(self::$srvQ5[$clave])) {
            @proc_terminate(self::$srvQ5[$clave]['proc']);
            @proc_close(self::$srvQ5[$clave]['proc']);
            unset(self::$srvQ5[$clave]);
        }
    }

    protected static function logQ5(string $clave = 'q5'): string
    {
        $l = self::$srvQ5[$clave]['log'] ?? null;
        return $l && is_file($l) ? (string)file_get_contents($l) : '';
    }

    /** Sesión PHP del servidor de pruebas: borrarla simula una sesión expirada. */
    protected static function archivoSesionQ5(string $sid, string $clave = 'q5'): string
    {
        return (self::$srvQ5[$clave]['sess'] ?? '') . '/sess_' . $sid;
    }

    // ====================================================================================================== tiempo

    protected static function gye(string $mod = 'now'): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(self::ZONA)))->modify($mod);
    }

    /** [fecha, hora] de un horario que YA comenzó hoy (hace ≥ 40 min; a las 00:xx se ancla a las 00:00). */
    protected static function slotComenzado(int $extraMinutos = 0): array
    {
        $n = self::gye();
        $t = $n->modify('-' . (40 + $extraMinutos) . ' minutes');
        if ($t->format('Y-m-d') !== $n->format('Y-m-d')) { $t = $n->setTime(0, 0); }
        return [$n->format('Y-m-d'), $t->format('H:i:s')];
    }

    /** [fecha, hora] claramente futuro (mañana a las 15:00: fuera de cualquier margen de adelanto razonable). */
    protected static function slotFuturo(int $dias = 1): array
    {
        return [self::gye("+$dias days")->format('Y-m-d'), '15:00:00'];
    }

    /** [fecha, hora] dentro del margen de adelanto (empieza en ~30 min) o null si ese instante cae en el día siguiente. */
    protected static function slotEnMargen(): ?array
    {
        $n = self::gye();
        $t = $n->modify('+30 minutes');
        return $t->format('Y-m-d') === $n->format('Y-m-d') ? [$n->format('Y-m-d'), $t->format('H:i:s')] : null;
    }

    // ====================================================================================================== escenario

    /**
     * Escenario base creado por los caminos reales de producción: propietario, gerente, recepción, finanzas, dos profesionales con cuenta,
     * un servicio de $20.00 y dos clientes. Cada rol entra por /equipo/login.
     *
     * @return array<string,mixed>
     */
    protected static function escenario(): array
    {
        $dueno = self::propietario(self::correo('dueno'));
        $e = ['dueno' => $dueno];
        $e['gerente'] = self::miembro($dueno['id'], 'gerente', ['nombre' => 'Gera']);
        $e['recepcion'] = self::miembro($dueno['id'], 'recepcion', ['nombre' => 'Rece']);
        $e['finanzas'] = self::miembro($dueno['id'], 'finanzas', ['nombre' => 'Fina']);
        $e['proA'] = self::miembro($dueno['id'], 'profesional', ['nombre' => 'ProA']);
        $e['proB'] = self::miembro($dueno['id'], 'profesional', ['nombre' => 'ProB']);
        $e['svc'] = self::servicio('Corte Q5', 20.00, 30);
        $e['cliente'] = self::cliente(['email' => self::correo('cli'), 'nombre' => 'Clienta', 'apellido' => 'Quinta']);
        $e['cliente2'] = self::cliente(['email' => self::correo('cli2'), 'nombre' => 'Otra', 'apellido' => 'Cliente']);
        return $e;
    }

    /**
     * Crea una cita por SQL (la reserva en sí no es el objeto de R5).
     * $o: cliente (id), pro (id profesional), fecha, hora, estado, pago, servicios [[nombre, 'precio'],…] (por omisión un servicio de $20.00), minutos.
     */
    protected static function crearCita(array $o): int
    {
        $fecha = $o['fecha'] ?? self::gye()->format('Y-m-d');
        $hora = $o['hora'] ?? '10:00:00';
        $min = (int)($o['minutos'] ?? 30);
        $fin = date('H:i:s', strtotime("$hora +$min minutes"));
        self::q('INSERT INTO citas(fecha,hora,hora_inicio,hora_fin,duracion_total_minutos,usuarioId,profesionalId,estado,estado_pago) VALUES (?,?,?,?,?,?,?,?,?)',
            [$fecha, $hora, $hora, $fin, $min, (int)$o['cliente'], (int)$o['pro'], $o['estado'] ?? 'confirmada', $o['pago'] ?? 'pendiente']);
        $id = (int)self::db()->insert_id;
        foreach ($o['servicios'] ?? [['Corte Q5', '20.00']] as [$nombre, $precio]) {
            $sid = (int)(self::escalar('SELECT id FROM servicios ORDER BY id LIMIT 1') ?: self::servicio());
            self::q('INSERT INTO citasservicios(citaId,servicioId,nombre_servicio,precio_servicio,duracion_minutos) VALUES (?,?,?,?,?)', [$id, $sid, $nombre, $precio, $min]);
        }
        return $id;
    }

    protected static function totalCita(int $cita): int
    {
        return self::centavos((string)self::escalar('SELECT COALESCE(SUM(precio_servicio),0) FROM citasservicios WHERE citaId=?', [$cita]));
    }

    /** "12.34" → 1234 (sin coma flotante). */
    protected static function centavos(string $decimal): int
    {
        $neg = str_starts_with($decimal, '-');
        $d = ltrim($decimal, '-');
        [$e, $f] = array_pad(explode('.', $d, 2), 2, '0');
        $f = substr(str_pad($f, 2, '0'), 0, 2);
        return ($neg ? -1 : 1) * ((int)$e * 100 + (int)$f);
    }

    protected static function dolares(int $centavos): string
    {
        return sprintf('%s%d.%02d', $centavos < 0 ? '-' : '', intdiv(abs($centavos), 100), abs($centavos) % 100);
    }

    /** Entra por /equipo/login (o /cliente/login) con una persona del escenario. Devuelve el navegador con sesión. */
    protected static function entrar(string $base, array $persona, string $portal = 'equipo'): Navegador
    {
        self::q('TRUNCATE intentos_login');
        $n = new Navegador($base);
        $r = $n->login($portal, $persona['email'], $persona['password']);
        if ($r['status'] !== 302) { throw new \RuntimeException("Login $portal de {$persona['email']} respondió {$r['status']}: " . substr(strip_tags($r['body']), 0, 160)); }
        return $n;
    }

    /** Una persona con membresía de cliente Y rol laboral (doble pertenencia). */
    protected static function personaDoble(int $dueno, string $rol, ?string $estadoCliente = 'activo'): array
    {
        $p = $rol === 'propietario' ? self::propietario(self::correo('dobleP')) : self::miembro($dueno, $rol, ['email' => self::correo('doble' . $rol)]);
        self::q("INSERT INTO clientes(usuario_id,estado,origen) VALUES (?,?, 'recepcion') ON DUPLICATE KEY UPDATE estado=VALUES(estado)", [$p['id'], $estadoCliente]);
        return $p;
    }

    // ====================================================================================================== HTML

    protected static function xp(string $html): \DOMXPath
    {
        $d = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $d->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return new \DOMXPath($d);
    }

    /** Ruta (path) de una URL relativa o absoluta del mismo origen; '' si es externa/ancla/javascript. */
    protected static function rutaDe(string $url): string
    {
        $url = trim(html_entity_decode($url));
        if ($url === '' || $url[0] === '#' || preg_match('~^(javascript|mailto|tel|data):~i', $url)) { return ''; }
        if (preg_match('~^https?://~i', $url)) {
            $p = parse_url($url);
            if (!in_array($p['host'] ?? '', ['127.0.0.1', 'localhost'], true)) { return ''; }
            $url = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
        }
        return $url;
    }

    /** Todos los destinos navegables de una página: a[href], form[action], [formaction], [data-href], [data-url]. @return string[] */
    protected static function destinos(string $html): array
    {
        $x = self::xp($html);
        $r = [];
        foreach (['//a/@href', '//form/@action', '//*/@formaction', '//*/@data-href', '//*/@data-url', '//*/@data-volver'] as $q) {
            foreach ($x->query($q) as $a) {
                $ruta = self::rutaDe($a->nodeValue);
                if ($ruta !== '') { $r[] = $ruta; }
            }
        }
        return array_values(array_unique($r));
    }

    protected static function pathDe(string $ruta): string { return (string)parse_url($ruta, PHP_URL_PATH); }

    /** Destinos de una página que pertenecen al otro portal. @return string[] */
    protected static function enlacesCruzados(string $html, string $portal): array
    {
        $malos = [];
        foreach (self::destinos($html) as $d) {
            $p = self::pathDe($d);
            if ($portal === 'equipo') {
                if (in_array($p, self::RUTAS_CLIENTE, true) || str_starts_with($p, '/cliente/')) { $malos[] = $d; }
            } else {
                foreach (self::PREFIJOS_EQUIPO as $pref) { if ($p === $pref || str_starts_with($p, $pref . '/')) { $malos[] = $d; break; } }
            }
        }
        return $malos;
    }

    /**
     * Formularios de una página tal como los enviaría un navegador.
     * @return array<int,array{action:string,method:string,campos:array<string,string>,selects:array<string,string[]>,botones:array<int,array{texto:string,name:string,value:string,disabled:bool}>,html:string}>
     */
    protected static function formularios(string $html): array
    {
        $x = self::xp($html);
        $r = [];
        foreach ($x->query('//form') as $f) {
            /** @var \DOMElement $f */
            $campos = []; $selects = []; $botones = [];
            foreach ($x->query('.//input|.//select|.//textarea|.//button', $f) as $c) {
                /** @var \DOMElement $c */
                $tag = $c->tagName; $name = $c->getAttribute('name'); $type = strtolower($c->getAttribute('type'));
                if ($tag === 'button' || ($tag === 'input' && in_array($type, ['submit', 'button', 'image'], true))) {
                    if ($type === 'button' && $tag === 'button') { continue; }
                    $botones[] = ['texto' => trim(preg_replace('/\s+/', ' ', $c->textContent ?: $c->getAttribute('value'))), 'name' => $name, 'value' => $c->getAttribute('value'),
                        'disabled' => $c->hasAttribute('disabled') || $c->getAttribute('aria-disabled') === 'true'];
                    continue;
                }
                if ($name === '' || $c->hasAttribute('disabled')) { continue; }
                if ($tag === 'select') {
                    $opts = []; $sel = null;
                    foreach ($x->query('.//option', $c) as $o) {
                        /** @var \DOMElement $o */
                        $v = $o->hasAttribute('value') ? $o->getAttribute('value') : trim($o->textContent);
                        $opts[] = $v;
                        if ($o->hasAttribute('selected')) { $sel = $v; }
                    }
                    $selects[$name] = $opts;
                    $campos[$name] = $sel ?? ($opts[0] ?? '');
                } elseif ($tag === 'textarea') {
                    $campos[$name] = $c->textContent;
                } elseif (in_array($type, ['checkbox', 'radio'], true)) {
                    if ($c->hasAttribute('checked')) { $campos[$name] = $c->hasAttribute('value') ? $c->getAttribute('value') : 'on'; }
                    elseif ($type === 'radio' && !isset($campos[$name])) { $selects[$name][] = $c->getAttribute('value'); }
                } else {
                    $campos[$name] = $c->getAttribute('value');
                }
            }
            $inner = '';
            foreach ($f->childNodes as $ch) { $inner .= $f->ownerDocument->saveHTML($ch); }
            $r[] = ['action' => html_entity_decode($f->getAttribute('action')), 'method' => strtoupper($f->getAttribute('method') ?: 'GET'), 'campos' => $campos, 'selects' => $selects, 'botones' => $botones, 'html' => $inner];
        }
        return $r;
    }

    /** Primer formulario cuya acción (path) coincide con la expresión. */
    protected static function formulario(string $html, string $regexAccion): ?array
    {
        foreach (self::formularios($html) as $f) { if (preg_match($regexAccion, $f['action'])) { return $f; } }
        return null;
    }

    /** Todos los formularios que coinciden. */
    protected static function formulariosDe(string $html, string $regexAccion): array
    {
        return array_values(array_filter(self::formularios($html), static fn($f) => (bool)preg_match($regexAccion, $f['action'])));
    }

    /** Envía un formulario leído de pantalla, con sustituciones; $quitar elimina campos. */
    protected static function enviar(Navegador $n, array $form, array $cambios = [], array $quitar = [], ?array $boton = null): array
    {
        $c = array_merge($form['campos'], $cambios);
        foreach ($quitar as $q) { unset($c[$q]); }
        $b = $boton ?? ($form['botones'][0] ?? null);
        if ($b && ($b['name'] ?? '') !== '' && !array_key_exists($b['name'], $cambios)) { $c[$b['name']] = $b['value']; }
        return $form['method'] === 'GET' ? $n->get($form['action'] . (str_contains($form['action'], '?') ? '&' : '?') . http_build_query($c)) : $n->post($form['action'], $c);
    }

    /** Nombre del primer campo del formulario que coincide con la expresión (sin distinguir mayúsculas). */
    protected static function campo(array $form, string $regex, ?string $excluir = null): ?string
    {
        foreach (array_merge(array_keys($form['campos']), array_keys($form['selects'])) as $k) {
            if (preg_match($regex, $k) && ($excluir === null || !preg_match($excluir, $k))) { return $k; }
        }
        return null;
    }

    protected static function texto(string $html): string
    {
        $h = preg_replace('~<(script|style)\b.*?</\1>~is', ' ', $html);
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string)$h))));
    }

    /** ¿La respuesta es una página HTML completa (con estructura de documento y contenido principal)? */
    protected static function esPaginaCompleta(string $html): bool
    {
        return (bool)preg_match('~<!doctype html~i', $html) && str_contains($html, '</html>') && (bool)preg_match('~<main\b|id="contenido-principal"|class="contenedor-app"~i', $html);
    }


    /** Controles de acción de un fragmento HTML (dentro o fuera de formularios): texto, deshabilitado y si viven en un formulario. @return array<int,array{texto:string,disabled:bool,form:bool,tag:string}> */
    protected static function botones(string $html): array
    {
        $x = self::xp($html);
        $r = [];
        foreach ($x->query("//button|//input[@type='submit']|//a[@role='button' or contains(concat(' ',normalize-space(@class),' '),' boton ')]") as $b) {
            /** @var \DOMElement $b */
            $r[] = ['texto' => trim(preg_replace('/\s+/', ' ', $b->textContent ?: $b->getAttribute('value'))), 'tag' => $b->tagName,
                'disabled' => $b->hasAttribute('disabled') || $b->getAttribute('aria-disabled') === 'true', 'form' => $x->query('ancestor::form', $b)->length > 0];
        }
        return $r;
    }

    /** HTML de la tarjeta/fila de una cita en la agenda (el menor contenedor que la identifica), o null. */
    protected static function tarjeta(string $html, int $cita): ?string
    {
        $x = self::xp($html);
        $mejor = null;
        foreach ($x->query('//article|//li|//tr|//section[contains(@class,"cita")]|//div[contains(@class,"tarjeta") or contains(@class,"card")]') as $n) {
            $h = $n->ownerDocument->saveHTML($n);
            if (preg_match('~(Cita\s*#\s*' . $cita . '\b|name="id"\s+value="' . $cita . '"|value="' . $cita . '"\s+name="id"|[?&;]id=' . $cita . '\b)~', $h) && ($mejor === null || strlen($h) < strlen($mejor))) { $mejor = $h; }
        }
        return $mejor;
    }

    /** [formulario, botón] de la primera acción cuyo texto coincide, dentro de un fragmento de página. @return array{0:array,1:array}|null */
    protected static function accion(string $fragmento, string $regexTexto): ?array
    {
        foreach (self::formularios($fragmento) as $f) {
            foreach ($f['botones'] as $b) { if (preg_match($regexTexto, $b['texto'])) { return [$f, $b]; } }
        }
        return null;
    }

    /** ¿Hay un control (habilitado o no) con ese texto? @return array{texto:string,disabled:bool,form:bool,tag:string}|null */
    protected static function control(string $fragmento, string $regexTexto): ?array
    {
        foreach (self::botones($fragmento) as $b) { if (preg_match($regexTexto, $b['texto'])) { return $b; } }
        return null;
    }

    /** Ruta + consulta del Location de una respuesta, ya normalizada (o ''). */
    protected static function ubicacion(array $r): string
    {
        return self::rutaDe((string)($r['location'] ?? ''));
    }

    /** Valor de un parámetro de la consulta de una ruta. */
    protected static function param(string $ruta, string $nombre): ?string
    {
        parse_str((string)parse_url($ruta, PHP_URL_QUERY), $q);
        return isset($q[$nombre]) && is_string($q[$nombre]) ? $q[$nombre] : null;
    }

    // ====================================================================================================== ráfagas con procesos reales

    /**
     * Ejecuta N peticiones HTTP a la vez desde N PROCESOS PHP independientes con una barrera de tiempo común (cada una con su cookie).
     * @param array<int,array{cookie:?string,metodo:string,ruta:string,body?:array}> $reqs
     * @return array<int,array{status:int,body:string,location:?string,t0:float,t1:float}>
     */
    protected static function rafaga(string $base, array $reqs, float $holgura = 1.0): array
    {
        $inicio = microtime(true) + $holgura;
        $codigo = <<<'PHP'
$a = json_decode($argv[1], true);
while (microtime(true) < $a['inicio']) { usleep(200); }
$ch = curl_init($a['base'] . $a['ruta']);
$h = [];
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 90, CURLOPT_CUSTOMREQUEST => $a['metodo'],
    CURLOPT_HEADERFUNCTION => function ($c, $l) use (&$h) { if (stripos($l, 'location:') === 0) { $h['location'] = trim(substr($l, 9)); } return strlen($l); }]);
if ($a['cookie']) { curl_setopt($ch, CURLOPT_COOKIE, 'PHPSESSID=' . $a['cookie']); }
if (!empty($a['body'])) { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($a['body'])); }
$t0 = microtime(true);
$b = curl_exec($ch);
echo json_encode(['status' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => (string)$b, 'location' => $h['location'] ?? null, 't0' => $t0, 't1' => microtime(true)], JSON_INVALID_UTF8_SUBSTITUTE);
PHP;
        $procs = [];
        foreach ($reqs as $i => $r) {
            $arg = json_encode($r + ['base' => $base, 'inicio' => $inicio, 'body' => $r['body'] ?? []], JSON_INVALID_UTF8_SUBSTITUTE);
            $pipes = [];
            $p = proc_open([PHP_BINARY, '-r', $codigo, $arg], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $procs[$i] = [$p, $pipes];
        }
        $res = [];
        foreach ($procs as $i => [$p, $pipes]) {
            $out = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
            $res[$i] = json_decode($out, true) ?: ['status' => 0, 'body' => $out, 'location' => null, 't0' => 0, 't1' => 0];
        }
        return $res;
    }

    // ====================================================================================================== finanzas (invariantes en SQL)

    /** Fila agregada por cita: total histórico, caja neta y estado financiero. */
    protected static function resumenFinanciero(int $cita): array
    {
        $total = self::totalCita($cita);
        $ing = (int)self::escalar("SELECT COALESCE(SUM(m.monto_centavos),0) FROM caja_movimientos m JOIN pagos p ON p.id=m.pago_id WHERE p.cita_referencia_id=? AND m.tipo='ingreso'", [$cita]);
        $egr = (int)self::escalar("SELECT COALESCE(SUM(m.monto_centavos),0) FROM caja_movimientos m JOIN pagos p ON p.id=m.pago_id WHERE p.cita_referencia_id=? AND m.tipo='egreso'", [$cita]);
        return ['total' => $total, 'ingresos' => $ing, 'egresos' => $egr, 'neto' => $ing - $egr, 'saldo' => $total - ($ing - $egr),
            'estado_pago' => (string)self::escalar('SELECT estado_pago FROM citas WHERE id=?', [$cita]), 'estado' => (string)self::escalar('SELECT estado FROM citas WHERE id=?', [$cita]),
            'pagos' => self::q('SELECT * FROM pagos WHERE cita_referencia_id=? ORDER BY id', [$cita])];
    }

    /**
     * Invariantes de conciliación entre `pagos`, `caja_movimientos`, `comisiones_movimientos` y `citas.estado_pago`.
     * Devuelve la lista de violaciones (vacía = conciliado). Solo usa columnas que existen desde las migraciones 006/013/015; lo que añadan 024/025 se
     * descubre por information_schema (no se asume ningún nombre).
     *
     * @return string[]
     */
    protected static function violacionesFinancieras(): array
    {
        $v = [];
        // 1) Ningún movimiento de caja sin pago, ni al revés para cobros confirmados.
        foreach (self::q("SELECT m.id, m.pago_id FROM caja_movimientos m LEFT JOIN pagos p ON p.id=m.pago_id WHERE m.pago_id IS NOT NULL AND p.id IS NULL") as $r) { $v[] = "caja {$r['id']} apunta a un pago inexistente"; }
        // 2) Un pago NO confirmado (pendiente, por verificar, rechazado…) nunca puede tener ingreso en caja.
        foreach (self::q("SELECT p.id, p.estado FROM pagos p JOIN caja_movimientos m ON m.pago_id=p.id AND m.tipo='ingreso' WHERE p.estado IN ('pendiente','por_verificar','pendiente_verificacion','rechazado','fallido','cancelado','expirado')") as $r) {
            $v[] = "pago {$r['id']} en estado {$r['estado']} tiene ingreso en caja";
        }
        // 3) Pago confirmado manual ⇒ exactamente un ingreso por su importe efectivo.
        foreach (self::q("SELECT p.id, p.monto, (SELECT COALESCE(SUM(monto_centavos),0) FROM caja_movimientos m WHERE m.pago_id=p.id AND m.tipo='ingreso') ing FROM pagos p WHERE p.proveedor='manual' AND p.estado IN ('completado','reembolsado')") as $r) {
            if (self::centavos((string)$r['monto']) !== (int)$r['ing']) { $v[] = "pago {$r['id']} monto {$r['monto']} ≠ ingreso de caja {$r['ing']} centavos"; }
        }
        // 4) Por cita: ni sobrecobro ni saldo negativo; estado financiero coherente con la caja neta.
        foreach (self::q("SELECT DISTINCT p.cita_referencia_id id FROM pagos p WHERE p.cita_referencia_id IS NOT NULL") as $r) {
            $c = (int)$r['id'];
            $f = self::resumenFinanciero($c);
            if ($f['neto'] > $f['total']) { $v[] = "cita $c: caja neta {$f['neto']} supera el total {$f['total']}"; }
            if ($f['neto'] < 0) { $v[] = "cita $c: caja neta negativa {$f['neto']}"; }
            $pendiente = (int)self::escalar("SELECT COUNT(*) FROM pagos WHERE cita_referencia_id=? AND estado IN ('pendiente','por_verificar','pendiente_verificacion')", [$c]) > 0;
            $ok = match (true) {
                $f['neto'] >= $f['total'] && $f['total'] > 0 => ['pagado'],
                $f['neto'] > 0 => ['parcial', 'por_verificar'],
                $f['egresos'] > 0 && $f['neto'] === 0 => ['reembolsado', 'pendiente'],
                $pendiente => ['por_verificar', 'pendiente'],
                default => ['pendiente'],
            };
            if (!in_array($f['estado_pago'], $ok, true)) { $v[] = "cita $c: estado_pago '{$f['estado_pago']}' no corresponde a caja neta {$f['neto']} de total {$f['total']} (esperado " . implode('|', $ok) . ')'; }
        }
        // 5) Comisiones: solo sobre cobros confirmados con ingreso en caja; sin duplicados por pago/tipo.
        foreach (self::q("SELECT c.id, c.pago_id FROM comisiones_movimientos c LEFT JOIN caja_movimientos m ON m.pago_id=c.pago_id AND m.tipo='ingreso' WHERE c.tipo='devengo' AND m.id IS NULL") as $r) {
            $v[] = "comisión {$r['id']} (devengo) sobre pago {$r['pago_id']} sin ingreso confirmado en caja";
        }
        foreach (self::q("SELECT c.id, c.base_centavos, c.porcentaje_pb, c.monto_centavos FROM comisiones_movimientos c WHERE c.tipo='devengo'") as $r) {
            $esperado = intdiv((int)$r['base_centavos'] * (int)$r['porcentaje_pb'], 10000);
            if (abs($esperado - (int)$r['monto_centavos']) > 1) { $v[] = "comisión {$r['id']}: {$r['monto_centavos']} ≠ {$r['base_centavos']}×{$r['porcentaje_pb']}pb"; }
        }
        // 6) Comprobantes (si la tabla existe): numeración única y sin huecos.
        if ((int)self::escalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='comprobantes'") > 0) {
            $cols = array_column(self::q('SHOW COLUMNS FROM comprobantes'), 'Field');
            foreach (['numero', 'secuencial', 'correlativo', 'consecutivo'] as $col) {
                if (in_array($col, $cols, true)) {
                    foreach (self::q("SELECT `$col` n, COUNT(*) c FROM comprobantes GROUP BY `$col` HAVING c>1") as $r) { $v[] = "comprobante $col duplicado: {$r['n']}"; }
                    break;
                }
            }
        }
        return $v;
    }

    // ====================================================================================================== archivos exportados

    /** Lee un XLSX con ZipArchive (valores crudos tal como están en el XML) → filas de celdas ['t','v']. @return array<int,array<int,array{t:string,v:string}>> */
    protected static function xlsxCrudo(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'q5x_');
        file_put_contents($tmp, $bytes);
        $z = new \ZipArchive();
        if ($z->open($tmp) !== true) { @unlink($tmp); throw new \RuntimeException('El archivo no es un ZIP/XLSX válido'); }
        $xml = $z->getFromName('xl/worksheets/sheet1.xml');
        $ss = $z->getFromName('xl/sharedStrings.xml');
        $z->close(); @unlink($tmp);
        if ($xml === false) { throw new \RuntimeException('Sin xl/worksheets/sheet1.xml'); }
        $comp = [];
        if ($ss !== false) { foreach ((new \SimpleXMLElement($ss))->si as $si) { $comp[] = (string)$si->t; } }
        $sx = new \SimpleXMLElement($xml);
        $filas = [];
        foreach ($sx->sheetData->row as $row) {
            $fila = [];
            foreach ($row->c as $c) {
                $t = (string)$c['t'];
                if ($c->f->count()) { $fila[] = ['t' => 'f', 'v' => (string)$c->f]; continue; }
                if ($t === 'inlineStr') { $fila[] = ['t' => 's', 'v' => (string)$c->is->t]; }
                elseif ($t === 's') { $fila[] = ['t' => 's', 'v' => $comp[(int)$c->v] ?? '']; }
                elseif ($t === 'str') { $fila[] = ['t' => 's', 'v' => (string)$c->v]; }
                else { $fila[] = ['t' => 'n', 'v' => (string)$c->v]; }
            }
            $filas[] = $fila;
        }
        return $filas;
    }

    /**
     * Validación INDEPENDIENTE con openpyxl 3.1.5 (otro lector, otro parser): carga el libro y devuelve por celda tipo y valor.
     * data_type 'f' = fórmula (no debe existir nunca). @return array{hojas:string[],filas:array<int,array<int,array{t:string,v:mixed}>>,formulas:int}
     */
    protected static function xlsxOpenpyxl(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'q5o_') . '.xlsx';
        file_put_contents($tmp, $bytes);
        $py = <<<'PY'
import sys, json, openpyxl
wb = openpyxl.load_workbook(sys.argv[1], data_only=False)
ws = wb.worksheets[0]
filas = []; formulas = 0
for row in ws.iter_rows():
    fila = []
    for c in row:
        if c.data_type == 'f': formulas += 1
        v = c.value
        if hasattr(v, 'isoformat'): v = v.isoformat()
        fila.append({'t': c.data_type, 'v': v, 'fmt': c.number_format})
    filas.append(fila)
print(json.dumps({'hojas': wb.sheetnames, 'filas': filas, 'formulas': formulas}, ensure_ascii=False))
PY;
        $p = proc_open(['python3', '-I', '-c', $py, $tmp], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        $code = proc_close($p); @unlink($tmp);
        if ($code !== 0) { throw new \RuntimeException('openpyxl no pudo abrir el archivo: ' . trim($err)); }
        return json_decode($out, true);
    }

    /** Convierte con LibreOffice headless (csv|pdf) y devuelve el contenido del archivo convertido. */
    protected static function libreOffice(string $bytes, string $ext, string $destino = 'csv'): string
    {
        $dir = sys_get_temp_dir() . '/q5lo_' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        file_put_contents("$dir/entrada.$ext", $bytes);
        $perfil = sys_get_temp_dir() . '/q5_lo_perfil';
        $cmd = ['soffice', '--headless', '--norestore', "-env:UserInstallation=file://$perfil", '--convert-to', $destino, '--outdir', "$dir/salida", "$dir/entrada.$ext"];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, array_merge(getenv(), ['HOME' => $dir]));
        stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
        proc_close($p);
        $salida = "$dir/salida/entrada.$destino";
        $r = is_file($salida) ? (string)file_get_contents($salida) : '';
        foreach (glob("$dir/salida/*") ?: [] as $f) { @unlink($f); }
        @rmdir("$dir/salida"); @unlink("$dir/entrada.$ext"); @rmdir($dir);
        if ($r === '') { throw new \RuntimeException('LibreOffice no produjo salida'); }
        return $r;
    }

    /** CSV (con BOM opcional) → filas, usando el lector estándar de PHP. @return array<int,array<int,string>> */
    protected static function csvFilas(string $csv): array
    {
        if (str_starts_with($csv, "\xEF\xBB\xBF")) { $csv = substr($csv, 3); }
        $h = fopen('php://temp', 'w+'); fwrite($h, $csv); rewind($h);
        $f = [];
        while (($r = fgetcsv($h, 0, ',', '"', '')) !== false) { if ($r === [null]) { continue; } $f[] = $r; }
        fclose($h);
        return $f;
    }

    /** Descarga binaria: devuelve cabeceras y cuerpo crudo con cURL (el Navegador del arnés ya devuelve el cuerpo sin tocar). */
    protected static function esZipXlsx(string $b): bool
    {
        return str_starts_with($b, "PK\x03\x04");
    }
}

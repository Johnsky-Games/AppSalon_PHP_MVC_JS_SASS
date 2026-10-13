<?php

namespace Tests\Qa;

require_once __DIR__ . '/QaCase.php';
require_once __DIR__ . '/MatrizHttp.php';

use Services\AuthorizationService;

/**
 * Ronda 2 · Matriz de autorización EXACTA sobre HTTP real (php -S).
 *
 * Cada celda = (ruta × método × modalidad de acceso × variante de formato/cabecera). Para cada celda se exige:
 *   · status exacto; si es 302/303, `Location` exacto y cuerpo vacío; si es JSON, cuerpo exacto; si es HTML, el mensaje esperado;
 *   · cabeceras de seguridad (nosniff / X-Frame-Options / Referrer-Policy) y `Cache-Control: no-store` fuera de las rutas públicas de API;
 *   · ausencia de centinelas de otras personas en cuerpo y cabeceras;
 *   · ausencia de efectos: conteo + CHECKSUM de todas las tablas de negocio (salvo `migraciones`, `equipo_mutex`, `intentos_login`) y buzón SMTP.
 * Los contratos están escritos a mano en `matriz_rutas.php` y en `esperado()` a partir de la lectura del código
 * (isAuth/exigir_permiso/ErrorResponse/Router), no copiados de respuestas observadas. Un fallo nombra la celda exacta.
 */
final class AutorizacionMatrizTest extends QaCase
{
    use MatrizHttp;

    private const M_PERM = 'Tu cuenta no tiene permiso para realizar esta operación. Consulta con la administración del salón si necesitas acceso.';
    private const M_CSRF = 'La validación de esta página venció o no es válida. Vuelve a abrirla antes de enviar la solicitud de nuevo.';
    private const J_PERM = ['resultado' => false, 'error' => 'No tienes permiso para esta operación'];
    private const J_CSRF = ['resultado' => false, 'error' => 'Token CSRF inválido o ausente'];
    private const J_NOAUTH = ['resultado' => false, 'error' => 'No autenticado'];
    private const T_403 = 'Este acceso está protegido';
    private const T_404 = 'No encontramos esta página';
    private const T_405 = 'Esta acción no está disponible';

    /** @var array<int,array> */
    private static array $rutas = [];

    public static function setUpBeforeClass(): void
    {
        self::$rutas = require __DIR__ . '/matriz_rutas.php';
        self::sembrarMatriz();
    }

    public static function tearDownAfterClass(): void
    {
        self::pararServidor();
        self::detenerSmtp(self::SMTP_PUERTO);
    }

    // ================================================================== utilidades de la matriz

    private function resolver(string $p): string
    {
        return strtr($p, ['{cliente}' => self::$P['cliente']['id'], '{ajeno}' => self::$D['ajeno']['id'], '{col_staff}' => self::$D['col_staff'], '{uid_staff}' => self::$P['colab_baja']['id'], '{servicio}' => self::$D['servicio'], '{pro}' => self::$P['profesional']['profesional_id']]);
    }

    private static function base(string $p): string { return explode('?', $p, 2)[0]; }

    private static function etiqueta(array $r): string { return $r['m'] . ' ' . $r['p']; }

    /** Normaliza [status, tipo, extra] → ['s','k','x']. */
    private static function spec(array $a): array { return ['s' => $a[0], 'k' => $a[1], 'x' => $a[2] ?? null]; }

    /** Centinelas que NO pueden aparecer en ninguna respuesta a esta persona (los demás son visibles por diseño para su rol). */
    private static function prohibidos(string $persona, ?string $ruta = null): array
    {
        // Finanzas ve los reportes de reservas, que (hoy) llevan nombre y correo del cliente: eso se trata como DEFECTO en
        // test_Defecto_reportes_*; aquí se excluye de la matriz para no tapar el resto de comprobaciones de privacidad de ese rol.
        if ($persona === 'finanzas' && $ruta !== null && str_starts_with($ruta, '/admin/reportes')) { return ['SALUD', 'STAFF']; }
        $todos = ['PII', 'PAGO', 'SALUD', 'STAFF', 'NEGOCIO'];
        return match ($persona) {
            'propietario' => [],
            'gerente', 'portal_inexistente_cuenta_equipo' => ['STAFF'],
            'recepcion' => ['PAGO', 'SALUD', 'STAFF', 'NEGOCIO'],
            'finanzas' => ['PII', 'SALUD', 'STAFF'],
            default => $todos,
        };
    }

    /** Contrato esperado de una celda, derivado del código (ver cabecera de matriz_rutas.php). */
    private function esperado(array $r, string $persona, array $v = []): array
    {
        $per = self::personas()[$persona];
        $json = str_starts_with($r['p'], '/api/') || !empty($v['json']);
        if ($r['g'] === 'publica') { return self::spec($r['ok']) + ['denegado' => false]; }
        if ($per['cat'] !== 'valida') {
            // isAuth(): sin sesión vigente → JSON 401 (peticiones /api o Accept JSON) o 302 al login del portal de la marca de sesión.
            if ($json) {
                $propio = $r['unauth_json'] ?? null;
                if ($propio !== null && ($per['cat'] === 'anonimo' || ($per['id_valido'] ?? true) === false)) {
                    return ['s' => 401, 'k' => 'jsonx', 'x' => $propio, 'denegado' => true];
                }
                return ['s' => 401, 'k' => 'jsonx', 'x' => self::J_NOAUTH, 'denegado' => true];
            }
            return ['s' => 302, 'k' => 'redir', 'x' => $per['cat'] === 'anonimo' ? '/' : $per['destino'], 'denegado' => true];
        }
        $rol = $per['rol'];
        if ($r['g'] === 'permiso' && !in_array($rol, $r['roles'], true)) {
            if ($json) { return ['s' => 403, 'k' => 'jsonx', 'x' => isset($r['deny_msg'][$rol]) ? ['resultado' => false, 'error' => $r['deny_msg'][$rol]] : self::J_PERM, 'denegado' => true]; }
            return ['s' => 403, 'k' => 'html', 'x' => [$r['deny_msg'][$rol] ?? self::M_PERM, self::T_403], 'denegado' => true];
        }
        $ok = $r['ok_por_rol'][$rol] ?? $r['ok'];
        return self::spec($ok) + ['denegado' => false];
    }

    /** @return string[] discrepancias de la celda (vacío = cumple el contrato) */
    private function comparar(string $celda, array $spec, array $x, string $persona, bool $head = false, ?array $r = null): array
    {
        $f = [];
        $resp = $x['r'];
        $h = $resp['headers'];
        $ct = $h['content-type'][0] ?? '';
        $esperadoTxt = "{$spec['s']} {$spec['k']}" . (is_string($spec['x']) ? " {$spec['x']}" : '');
        $obt = $resp['status'] . ' ' . $ct . ($resp['location'] !== null ? " Location={$resp['location']}" : '') . ' ' . substr(trim(preg_replace('/\s+/', ' ', strip_tags($resp['body']))), 0, 110);
        if ($resp['status'] !== $spec['s']) { $f[] = "status: esperado $esperadoTxt; obtenido $obt"; return array_map(fn ($m) => "[$celda] $m", $f); }
        $loc = $resp['location'];
        $cuerpo = $resp['body'];
        switch ($spec['k']) {
            case 'redir':
                $destino = $spec['x'] === '@portal' ? ((($x['sesion']['portal'] ?? '') === 'equipo') ? '/equipo/login' : '/') : $spec['x'];
                if ($loc !== $destino) { $f[] = "Location: esperado " . var_export($destino, true) . '; obtenido ' . var_export($loc, true); }
                if ($cuerpo !== '') { $f[] = 'una redirección no debe llevar cuerpo (' . strlen($cuerpo) . ' bytes)'; }
                break;
            case 'html':
                if (!str_starts_with(strtolower($ct), 'text/html; charset=utf-8')) { $f[] = "Content-Type html esperado; obtenido '$ct'"; }
                if ($loc !== null) { $f[] = "no debe haber Location (obtenido $loc)"; }
                if (!$head) {
                    foreach ((array)($spec['x'] ?? []) as $t) { if (!str_contains($cuerpo, $t)) { $f[] = "falta el texto «$t»; cuerpo: " . substr(trim(preg_replace('/\s+/', ' ', strip_tags($cuerpo))), 0, 140); } }
                }
                break;
            case 'json': case 'jsonx': case 'json_lista':
                if (!str_starts_with(strtolower($ct), 'application/json; charset=utf-8')) { $f[] = "Content-Type json esperado; obtenido '$ct'"; }
                if ($loc !== null) { $f[] = "no debe haber Location (obtenido $loc)"; }
                if ($head) { break; }
                $j = json_decode($cuerpo, true);
                if (!is_array($j)) { $f[] = 'cuerpo no es JSON: ' . substr($cuerpo, 0, 100); break; }
                if ($spec['k'] === 'jsonx' && $j !== $spec['x']) { $f[] = 'JSON esperado ' . json_encode($spec['x'], JSON_UNESCAPED_UNICODE) . '; obtenido ' . substr(json_encode($j, JSON_UNESCAPED_UNICODE), 0, 300); }
                if ($spec['k'] === 'json') {
                    foreach ((array)$spec['x'] as $k => $val) {
                        if (!array_key_exists($k, $j) || $j[$k] !== $val) { $f[] = "JSON[$k]: esperado " . json_encode($val, JSON_UNESCAPED_UNICODE) . '; obtenido ' . json_encode($j[$k] ?? '(ausente)', JSON_UNESCAPED_UNICODE); }
                    }
                }
                if ($spec['k'] === 'json_lista') {
                    if (!array_is_list($j) || $j === []) { $f[] = 'se esperaba una lista no vacía'; }
                    foreach ($j as $i) { if (array_keys($i) !== ['id', 'nombre', 'precio', 'duracion_minutos']) { $f[] = 'claves del servicio: ' . implode(',', array_keys($i)); break; } }
                }
                break;
            case 'vacio':
                if ($cuerpo !== '') { $f[] = 'se esperaba un cuerpo vacío (' . strlen($cuerpo) . ' bytes)'; }
                if ($loc !== null) { $f[] = "no debe haber Location (obtenido $loc)"; }
                break;
            case 'csv':
                if (!str_starts_with($ct, 'text/csv; charset=UTF-8')) { $f[] = "Content-Type csv esperado; obtenido '$ct'"; }
                if (!str_starts_with($h['content-disposition'][0] ?? '', 'attachment; filename="')) { $f[] = 'falta Content-Disposition: attachment'; }
                if ($loc !== null) { $f[] = "no debe haber Location (obtenido $loc)"; }
                break;
        }
        if ($head && $cuerpo !== '') { $f[] = 'HEAD no debe devolver cuerpo (' . strlen($cuerpo) . ' bytes)'; }
        // Cabeceras de seguridad en TODA respuesta.
        foreach (['x-content-type-options' => 'nosniff', 'x-frame-options' => 'SAMEORIGIN', 'referrer-policy' => 'strict-origin-when-cross-origin'] as $k => $val) {
            if (($h[$k][0] ?? null) !== $val) { $f[] = "cabecera $k esperada '$val'; obtenida " . var_export($h[$k][0] ?? null, true); }
        }
        // Nada autenticado o denegado se cachea (las dos únicas respuestas sin sesión son el catálogo público y el webhook firmado).
        $publicaApi = $r !== null && $r['g'] === 'publica' && str_starts_with($r['p'], '/api/');
        if (!$publicaApi && !str_contains(strtolower($h['cache-control'][0] ?? ''), 'no-store')) { $f[] = "Cache-Control debe contener no-store; obtenido '" . ($h['cache-control'][0] ?? '(ausente)') . "'"; }
        // Privacidad: ningún centinela prohibido en cuerpo ni cabeceras (incluida Location).
        $cab = '';
        foreach ($h as $k => $vs) { $cab .= $k . ': ' . implode(' | ', $vs) . "\n"; }
        $fugas = self::fugas($cuerpo . "\n" . $cab, self::prohibidos($persona, $r !== null ? self::base($r['p']) : null));
        if ($fugas) { $f[] = 'FUGA de datos privados: ' . implode(', ', $fugas); }
        // Efectos: una petición benigna (o denegada) no modifica nada.
        if ($x['tablas']) { $f[] = 'EFECTO en BD: ' . implode(', ', $x['tablas']); }
        if ($x['correo'] !== 0) { $f[] = "EFECTO: {$x['correo']} correo(s) enviados al buzón SMTP"; }
        return array_map(fn ($m) => "[$celda] $m", $f);
    }

    private function afirmar(array $fallos, string $titulo): void
    {
        $this->assertSame([], $fallos, $titulo . ': ' . count($fallos) . " celda(s) incumplen el contrato:\n  " . implode("\n  ", array_slice($fallos, 0, 40)) . (count($fallos) > 40 ? "\n  … (+" . (count($fallos) - 40) . ')' : ''));
    }

    private static function pers(): array { return array_keys(self::personas()); }

    // ================================================================== A. ruta × modalidad (navegación)

    public static function proveedorRutas(): array
    {
        $o = [];
        foreach (require __DIR__ . '/matriz_rutas.php' as $i => $r) { $o[$r['m'] . ' ' . $r['p']] = [$i]; }
        return $o;
    }

    /** @dataProvider proveedorRutas */
    public function test_A_ruta_por_modalidad_contrato_exacto_privacidad_y_ausencia_de_efectos(int $i): void
    {
        $r = self::$rutas[$i];
        if ($r['g'] === 'especial') { $this->assertTrue(true, 'cubierta por test_J'); return; }
        $r['p'] = $this->resolver($r['p']);
        $fallos = [];
        foreach (self::pers() as $persona) {
            $spec = $this->esperado($r, $persona);
            $x = $this->ejecutar($persona, ['m' => $r['m'], 'p' => $r['p'], 'body' => $r['body'] ?? [], 'accept' => 'html', 'limpiar_intentos' => $r['m'] === 'POST']);
            array_push($fallos, ...$this->comparar(self::etiqueta($r) . " | $persona | html", $spec, $x, $persona, false, $r));
        }
        $this->afirmar($fallos, self::etiqueta($r));
    }

    // ================================================================== B. formato: JSON / AJAX / navegación

    public static function formatos(): array
    {
        return [
            'Accept: application/json' => [['Accept: application/json'], true],
            'Accept: application/json + X-Requested-With' => [['Accept: application/json', 'X-Requested-With: XMLHttpRequest'], true],
            'solo X-Requested-With (sin Accept)' => [['X-Requested-With: XMLHttpRequest'], false],
            'Accept: */*' => [['Accept: */*'], false],
            'json q=0.4 frente a html q=0.9' => [['Accept: application/json;q=0.4, text/html;q=0.9'], false],
            'html q=0.4 frente a json' => [['Accept: text/html;q=0.4, application/json'], true],
            'Accept: application/problem+json' => [['Accept: application/problem+json'], true],
        ];
    }

    /** @dataProvider formatos */
    public function test_B_denegaciones_respetan_el_formato_pedido(array $cabeceras, bool $esperaJson): void
    {
        $fallos = [];
        $personas = ['anonimo', 'cliente', 'recepcion', 'finanzas', 'colab_suspendido', 'forjada_cliente_en_portal_equipo', 'sesion_sin_id', 'login_no_booleano'];
        foreach (self::$rutas as $r0) {
            if ($r0['g'] === 'publica' || $r0['g'] === 'especial') { continue; }
            $r = $r0; $r['p'] = $this->resolver($r0['p']);
            foreach ($personas as $persona) {
                $spec = $this->esperado($r, $persona, ['json' => $esperaJson]);
                if (!$spec['denegado']) { continue; }
                $x = $this->ejecutar($persona, ['m' => $r['m'], 'p' => $r['p'], 'body' => [], 'cabeceras' => $cabeceras]);
                array_push($fallos, ...$this->comparar(self::etiqueta($r) . " | $persona | " . implode('; ', $cabeceras), $spec, $x, $persona, false, $r));
            }
        }
        $this->afirmar($fallos, 'formato ' . implode('; ', $cabeceras));
    }

    // ================================================================== C. HEAD

    public function test_C_head_replica_el_status_y_el_location_de_get_sin_cuerpo(): void
    {
        $fallos = [];
        $personas = ['anonimo', 'cliente', 'propietario', 'recepcion', 'colab_suspendido', 'usuario_eliminado'];
        foreach (self::$rutas as $r0) {
            if ($r0['m'] !== 'GET' || $r0['g'] === 'especial') { continue; }
            $r = $r0; $r['p'] = $this->resolver($r0['p']);
            foreach ($personas as $persona) {
                $spec = $this->esperado($r, $persona);
                $x = $this->ejecutar($persona, ['m' => 'HEAD', 'p' => $r['p'], 'accept' => 'html']);
                array_push($fallos, ...$this->comparar(self::etiqueta($r) . " | $persona | HEAD", $spec, $x, $persona, true, $r));
            }
        }
        $this->afirmar($fallos, 'HEAD');
    }

    // ================================================================== D. método incorrecto, OPTIONS y rutas inexistentes

    /** @return array<string,string[]> ruta base => métodos registrados */
    private static function registradas(): array
    {
        $o = [];
        foreach (self::$rutas as $r) { $o[self::base($r['p'])][$r['m']] = $r['m']; }
        return array_map('array_values', $o);
    }

    public function test_D_metodo_no_registrado_responde_405_con_allow_exacto_antes_de_autenticar(): void
    {
        $fallos = [];
        foreach (self::registradas() as $ruta => $metodos) {
            $allow = in_array('GET', $metodos, true) ? (in_array('POST', $metodos, true) ? 'GET, HEAD, POST' : 'GET, HEAD') : 'POST';
            $malos = array_diff(['GET', 'HEAD', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'], $metodos, in_array('GET', $metodos, true) ? ['HEAD'] : []);
            foreach (['anonimo', 'cliente', 'propietario'] as $persona) {
                foreach ($malos as $m) {
                    foreach ([[], ['Accept: application/json']] as $cab) {
                        $json = str_starts_with($ruta, '/api/') || $cab !== [];
                        $x = $this->ejecutar($persona, ['m' => $m, 'p' => $ruta, 'body' => [], 'csrf' => 'valido', 'cabeceras' => $cab]);
                        $celda = "$m $ruta | $persona | " . ($cab ? 'json' : 'html') . ' | método incorrecto';
                        $spec = $json ? ['s' => 405, 'k' => 'jsonx', 'x' => ['resultado' => false, 'error' => self::T_405]] : ['s' => 405, 'k' => 'html', 'x' => self::T_405];
                        array_push($fallos, ...$this->comparar($celda, $spec, $x, $persona, $m === 'HEAD'));
                        if (($x['r']['headers']['allow'][0] ?? null) !== $allow) { $fallos[] = "[$celda] Allow esperado '$allow'; obtenido " . var_export($x['r']['headers']['allow'][0] ?? null, true); }
                    }
                }
            }
        }
        $this->afirmar($fallos, '405');
    }

    public static function rutasInexistentes(): array
    {
        return array_map(fn ($p) => [$p], ['/admin/nada', '/admin/equipo/', '/Admin', '/ADMIN/EQUIPO', '/admin//equipo', '/index.php', '/admin/equipo/ficha/1', '/api', '/api/', '/api/desconocida',
            '/cliente/login/', '/equipo', '/perfil/', '/vendor/autoload.php', '/classes/Email.php', '/includes/app.php', '/.env', '/composer.json', '/admin/../admin', '/%2e%2e/includes/app.php']);
    }

    /** @dataProvider rutasInexistentes */
    public function test_D_ruta_inexistente_responde_404_para_cualquier_persona_sin_filtrar_nada(string $ruta): void
    {
        $fallos = [];
        foreach (['anonimo', 'cliente', 'propietario', 'colab_baja', 'usuario_eliminado'] as $persona) {
            foreach ([[], ['Accept: application/json']] as $cab) {
                $json = str_starts_with($ruta, '/api') || $cab !== [];
                $x = $this->ejecutar($persona, ['m' => 'GET', 'p' => $ruta, 'cabeceras' => $cab]);
                $spec = $json ? ['s' => 404, 'k' => 'jsonx', 'x' => ['resultado' => false, 'error' => self::T_404]] : ['s' => 404, 'k' => 'html', 'x' => self::T_404];
                array_push($fallos, ...$this->comparar("GET $ruta | $persona | " . ($cab ? 'json' : 'html') . ' | inexistente', $spec, $x, $persona));
            }
        }
        $this->afirmar($fallos, "404 $ruta");
    }

    // ================================================================== E. CSRF

    /** Persona autorizada / no autorizada (con sesión vigente) para una ruta POST. */
    private function personasCsrf(array $r): array
    {
        if ($r['g'] === 'permiso') {
            $a = $r['roles'][0];
            $u = null;
            foreach (['cliente', 'profesional', 'finanzas', 'recepcion'] as $c) { if (!in_array($c, $r['roles'], true)) { $u = $c; break; } }
            return [$a, $u];
        }
        return ['cliente', null];
    }

    private function espCsrf(array $r, bool $json): array
    {
        $forma = $r['csrf'] ?? 'exigir';
        if ($forma === 'json_api') { return ['s' => 403, 'k' => 'jsonx', 'x' => $r['csrf_json'] ?? self::J_CSRF, 'denegado' => true]; }
        if ($forma === 'silencioso') {
            return $json ? ['s' => 403, 'k' => 'jsonx', 'x' => ['resultado' => false, 'error' => self::T_403], 'denegado' => true] : ['s' => 403, 'k' => 'html', 'x' => self::T_403, 'denegado' => true];
        }
        return $json ? ['s' => 403, 'k' => 'jsonx', 'x' => self::J_CSRF, 'denegado' => true] : ['s' => 403, 'k' => 'html', 'x' => self::M_CSRF, 'denegado' => true];
    }

    public static function modosCsrf(): array
    {
        return array_combine(['ninguno', 'vacio', 'otro', 'ajeno', 'arreglo'], array_map(fn ($m) => [$m], ['ninguno', 'vacio', 'otro', 'ajeno', 'arreglo']));
    }

    /** @dataProvider modosCsrf */
    public function test_E_post_sin_csrf_valido_se_rechaza_en_cada_ruta_y_modalidad(string $modo): void
    {
        $fallos = [];
        foreach (self::$rutas as $r0) {
            if ($r0['m'] !== 'POST' || !isset($r0['csrf']) || $r0['p'] === '/api/pagos/webhook') { continue; }
            $r = $r0; $r['p'] = $this->resolver($r0['p_csrf'] ?? $r0['p']);
            [$aut, $noAut] = $this->personasCsrf($r);
            foreach ([[], ['Accept: application/json']] as $cab) {
                $json = str_starts_with($r['p'], '/api/') || $cab !== [];
                $csrf = $this->espCsrf($r, $json);
                // Autorizado: siempre CSRF.
                $x = $this->ejecutar($aut, ['m' => 'POST', 'p' => $r['p'], 'body' => [], 'csrf' => $modo, 'cabeceras' => $cab, 'limpiar_intentos' => true]);
                array_push($fallos, ...$this->comparar(self::etiqueta($r) . " | $aut | csrf=$modo | " . ($cab ? 'json' : 'html'), $csrf, $x, $aut, false, $r));
                // Con sesión vigente pero sin permiso: el orden decide (auth antes que CSRF, o CSRF primero).
                if ($noAut !== null) {
                    $spec = ($r['orden'] ?? 'auth') === 'csrf' ? $csrf : $this->esperado($r, $noAut, ['json' => $json]);
                    $x = $this->ejecutar($noAut, ['m' => 'POST', 'p' => $r['p'], 'body' => [], 'csrf' => $modo, 'cabeceras' => $cab]);
                    array_push($fallos, ...$this->comparar(self::etiqueta($r) . " | $noAut | csrf=$modo | " . ($cab ? 'json' : 'html'), $spec, $x, $noAut, false, $r));
                }
                // Anónimo: rutas públicas y «csrf primero» → 403 CSRF; el resto, primero la autenticación.
                $spec = in_array($r['orden'] ?? 'auth', ['csrf', 'solo_csrf'], true) ? $csrf : $this->esperado($r, 'anonimo', ['json' => $json]);
                $x = $this->ejecutar('anonimo', ['m' => 'POST', 'p' => $r['p'], 'body' => [], 'csrf' => $modo, 'cabeceras' => $cab, 'limpiar_intentos' => true]);
                array_push($fallos, ...$this->comparar(self::etiqueta($r) . " | anonimo | csrf=$modo | " . ($cab ? 'json' : 'html'), $spec, $x, 'anonimo', false, $r));
            }
        }
        $this->afirmar($fallos, "CSRF $modo");
    }

    public function test_E_el_token_csrf_tambien_se_acepta_por_cabecera_x_csrf_token_y_no_hay_diferencia_de_autorizacion(): void
    {
        $fallos = [];
        foreach (self::$rutas as $r0) {
            if ($r0['m'] !== 'POST' || !isset($r0['csrf']) || $r0['p'] === '/api/pagos/webhook') { continue; }
            $r = $r0; $r['p'] = $this->resolver($r0['p']);
            [$aut, $noAut] = $this->personasCsrf($r);
            foreach (array_filter([$aut, $noAut]) as $persona) {
                $spec = $this->esperado($r, $persona);
                $x = $this->ejecutar($persona, ['m' => 'POST', 'p' => $r['p'], 'body' => [], 'csrf' => 'header', 'accept' => 'html', 'limpiar_intentos' => true]);
                array_push($fallos, ...$this->comparar(self::etiqueta($r) . " | $persona | csrf por cabecera", $spec, $x, $persona, false, $r));
            }
        }
        $this->afirmar($fallos, 'CSRF por cabecera');
    }

    // ================================================================== F. peticiones con datos VÁLIDOS: denegadas = cero efectos; permitida = efecto exacto y único

    private function rutaPorEtiqueta(string $etiqueta): array
    {
        foreach (self::$rutas as $r) { if (self::etiqueta($r) === $etiqueta) { return $r; } }
        throw new \LogicException("Ruta no declarada en la matriz: $etiqueta");
    }

    /**
     * Escenarios con carga útil válida. 'permitido' = persona que ejecuta el efecto; 'efecto' = filas por tabla (n = variación del
     * conteo, mod = tablas con el mismo conteo pero contenido distinto); 'deniega' = contrato por persona cuando la guardia es
     * de objeto (propiedad de la cita) y no de permiso.
     * @return array<string,array>
     */
    private function escenarios(): array
    {
        $D = self::$D; $P = self::$P;
        $u = bin2hex(random_bytes(3));
        return [
            'equipo_crear' => ['ruta' => 'POST /admin/equipo/crear', 'permitido' => 'propietario', 'body' => ['nombre' => 'Nuevo', 'apellido' => 'Ingreso', 'email' => "m.nuevo.$u@example.test", 'telefono' => '', 'puesto' => 'Caja', 'rol' => 'recepcion', 'fecha_ingreso' => '', 'idem_key' => Idem::clave()],
                'resp' => [302, 'redir', '/admin/equipo'], 'efecto' => ['n' => ['colaboradores' => 1, 'colaborador_invitaciones' => 1, 'correos_salida' => 1, 'equipo_auditoria' => 1, 'operaciones_idempotentes' => 1], 'mod' => []], 'repetible_sin_efecto' => true],
            'equipo_actualizar' => ['ruta' => 'POST /admin/equipo/actualizar', 'permitido' => 'propietario', 'body' => ['id' => (string)$D['victima']['colaborador_id'], 'rol' => 'recepcion', 'estado' => 'suspendido'],
                'resp' => [302, 'redir', '/admin/equipo'], 'efecto' => ['n' => ['equipo_auditoria' => 1], 'mod' => ['colaboradores', 'usuario_accesos', 'usuarios']]],
            'equipo_revocar' => ['ruta' => 'POST /admin/equipo/revocar', 'permitido' => 'propietario', 'body' => ['id' => (string)$D['col_por_revocar']],
                'resp' => [302, 'redir', '/admin/equipo'], 'efecto' => ['n' => ['equipo_auditoria' => 1], 'mod' => ['colaboradores', 'colaborador_invitaciones', 'correos_salida']]], // correos_salida: se cancela el correo pendiente de la invitación
            'equipo_invitar_reenvio' => ['ruta' => 'POST /admin/equipo/invitar', 'permitido' => 'propietario', 'body' => ['id' => (string)$D['col_por_reenviar'], 'idem_key' => Idem::clave()],
                'resp' => [302, 'redir', '/admin/equipo'], 'efecto' => ['n' => ['colaborador_invitaciones' => 1, 'correos_salida' => 1, 'equipo_auditoria' => 1, 'operaciones_idempotentes' => 1], 'mod' => []], 'repetible_sin_efecto' => true],
            'equipo_ficha' => ['ruta' => 'POST /admin/equipo/ficha', 'p' => '/admin/equipo/ficha?id={col_staff}', 'permitido' => 'propietario', 'body' => ['puesto' => 'Puesto nuevo', 'notas_rh' => 'Nota nueva', 'telefono' => '0990001111', 'fecha_ingreso' => ''],
                'resp' => [302, 'redir', '/admin/equipo'], 'efecto' => ['n' => ['equipo_auditoria' => 1], 'mod' => ['colaboradores']]],
            'servicios_crear' => ['ruta' => 'POST /servicios/crear', 'permitido' => 'gerente', 'body' => ['nombre' => 'Servicio Efecto', 'precio' => '12.50', 'duracion_minutos' => '45'],
                'resp' => [302, 'redir', '/servicios'], 'efecto' => ['n' => ['servicios' => 1], 'mod' => []]],
            'servicios_actualizar' => ['ruta' => 'POST /servicios/actualizar', 'p' => '/servicios/actualizar?id={servicio_borrable}', 'permitido' => 'gerente', 'body' => ['nombre' => 'Servicio Renombrado', 'precio' => '9.50', 'duracion_minutos' => '30'],
                'resp' => [302, 'redir', '/servicios'], 'efecto' => ['n' => [], 'mod' => ['servicios']]],
            'servicios_eliminar' => ['ruta' => 'POST /servicios/eliminar', 'permitido' => 'propietario', 'body' => ['id' => (string)$D['servicio_borrable']],
                'resp' => [302, 'redir', '/servicios'], 'efecto' => ['n' => ['servicios' => -1], 'mod' => []]],
            'profesionales_estado' => ['ruta' => 'POST /profesionales/estado', 'permitido' => 'gerente', 'body' => ['id' => (string)$D['pro3']['profesional_id'], 'activo' => '0'],
                'resp' => [302, 'redir', '/profesionales'], 'efecto' => ['n' => [], 'mod' => ['profesionales']]],
            'inventario_crear' => ['ruta' => 'POST /admin/inventario', 'permitido' => 'finanzas', 'body' => ['accion' => 'crear', 'sku' => 'EFE-SKU-' . $u, 'nombre' => 'Producto efecto', 'unidad' => 'unidad', 'minimo' => '1', 'costo' => '2.50'],
                'resp' => [303, 'redir', '/admin/inventario'], 'efecto' => ['n' => ['inventario_productos' => 1], 'mod' => []]],
            'finanzas_registrar' => ['ruta' => 'POST /admin/finanzas', 'permitido' => 'finanzas', 'body' => ['tipo' => 'ingreso', 'metodo' => 'efectivo', 'concepto' => 'Cobro efecto ' . $u, 'monto' => '7.25', 'idempotencia' => 'efe-' . $u . '-' . bin2hex(random_bytes(8))],
                'resp' => [303, 'redir', '/admin/finanzas'], 'efecto' => ['n' => ['caja_movimientos' => 1], 'mod' => []], 'repetible_sin_efecto' => true],
            'ajustes_guardar' => ['ruta' => 'POST /admin/ajustes', 'permitido' => 'gerente', 'body' => ['nombre_comercial' => 'Salón Efecto', 'contacto' => 'Contacto efecto', 'beneficio_fidelidad' => 'Beneficio efecto'],
                'resp' => [200, 'html'], 'efecto' => ['n' => ['negocio_ajustes' => 6], 'mod' => []]],
            'perfil_ajeno_editado_por_recepcion' => ['ruta' => 'POST /perfil', 'p' => '/perfil?id={ajeno}', 'permitido' => 'recepcion', 'body' => ['nombre' => 'Sentinela-Ajena', 'apellido' => 'Zorrilla-Ajeno', 'telefono' => '0991117777', 'contacto_email' => '1', 'recordatorios' => '1'],
                'resp' => [200, 'html'], 'efecto' => ['n' => [], 'mod' => ['perfiles_usuario']], 'sin_deniega_derivado' => true,
                'deniega' => ['profesional' => [403, 'html', self::M_PERM], 'finanzas' => [403, 'html', self::M_PERM], 'cliente' => [403, 'html', self::M_PERM]]],
            'api_cancelar_cita_propia' => ['ruta' => 'POST /api/eliminar', 'permitido' => 'cliente', 'body' => ['id' => (string)$D['cita_cancelable'], 'motivo' => 'Cambio de planes'], 'cabeceras' => ['Accept: application/json'],
                'resp' => [200, 'json', ['resultado' => true, 'status' => 'ok', 'id' => $D['cita_cancelable']]], 'efecto' => ['n' => ['notificaciones_citas' => 1], 'mod' => ['citas']], 'sin_deniega_derivado' => true,
                // La cita pertenece a la clienta: nadie más sin agenda.gestionar puede cancelarla (propietario/gerente/recepción sí, por agenda.gestionar).
                'deniega' => ['profesional' => [403, 'jsonx', ['resultado' => false, 'status' => 'forbidden', 'codigo' => 'no_autorizado', 'error' => 'No tienes autorización para eliminar esta cita']],
                    'finanzas' => [403, 'jsonx', ['resultado' => false, 'status' => 'forbidden', 'codigo' => 'no_autorizado', 'error' => 'No tienes autorización para eliminar esta cita']]]],
            'api_cancelar_cita_ajena_por_cliente' => ['ruta' => 'POST /api/eliminar', 'permitido' => null, 'body' => ['id' => (string)$D['cita_ajena']], 'cabeceras' => ['Accept: application/json'], 'sin_deniega_derivado' => true,
                'deniega' => ['cliente' => [403, 'jsonx', ['resultado' => false, 'status' => 'forbidden', 'codigo' => 'no_autorizado', 'error' => 'No tienes autorización para eliminar esta cita']],
                    'profesional' => [403, 'jsonx', ['resultado' => false, 'status' => 'forbidden', 'codigo' => 'no_autorizado', 'error' => 'No tienes autorización para eliminar esta cita']],
                    'finanzas' => [403, 'jsonx', ['resultado' => false, 'status' => 'forbidden', 'codigo' => 'no_autorizado', 'error' => 'No tienes autorización para eliminar esta cita']]]],
            'agenda_estado' => ['ruta' => 'POST /agenda/estado', 'permitido' => 'profesional', 'body' => ['id' => (string)$D['cita_estado'], 'estado' => 'en_servicio'],
                'resp' => [302, 'redir', '/agenda'], 'efecto' => ['n' => ['agenda_eventos' => 1], 'mod' => ['citas']], 'sin_deniega_derivado' => true,
                // Solo el profesional de la cita (o quien gestiona la agenda) cambia su estado; la clienta propietaria NO.
                'deniega' => ['cliente' => [403, 'html', 'No tienes permiso para modificar esta cita.'], 'finanzas' => [403, 'html', 'No tienes permiso para modificar esta cita.']]],
            'agenda_estado_cita_de_otro_profesional' => ['ruta' => 'POST /agenda/estado', 'permitido' => null, 'body' => ['id' => (string)$D['cita_ajena'], 'estado' => 'en_servicio'], 'sin_deniega_derivado' => true,
                'deniega' => ['profesional' => [403, 'html', 'No tienes permiso para modificar esta cita.'], 'cliente' => [403, 'html', 'No tienes permiso para modificar esta cita.'], 'finanzas' => [403, 'html', 'No tienes permiso para modificar esta cita.']]],
            'agenda_reprogramar_cita_ajena' => ['ruta' => 'POST /agenda/reprogramar', 'permitido' => null, 'body' => ['id' => (string)$D['cita_ajena'], 'fecha' => (new \DateTimeImmutable('+9 days'))->format('Y-m-d'), 'hora' => '11:00'], 'sin_deniega_derivado' => true,
                'deniega' => ['profesional' => [403, 'html', 'No tienes permiso para modificar esta cita.'], 'cliente' => [403, 'html', 'No tienes permiso para modificar esta cita.'], 'finanzas' => [403, 'html', 'No tienes permiso para modificar esta cita.']]],
            'pagos_iniciar_cita_ajena' => ['ruta' => 'POST /api/pagos/iniciar', 'permitido' => null, 'body' => ['cita_id' => (string)$D['cita_ajena'], 'idempotency_key' => 'efe-' . $u . '-1'], 'cabeceras' => ['Accept: application/json'], 'sin_deniega_derivado' => true,
                'deniega' => ['cliente' => [403, 'json', ['resultado' => false, 'status' => 'forbidden']], 'profesional' => [403, 'json', ['resultado' => false, 'status' => 'forbidden']], 'recepcion' => [403, 'json', ['resultado' => false, 'status' => 'forbidden']]]],
            'pagos_reembolsar' => ['ruta' => 'POST /api/pagos/reembolsar', 'permitido' => 'finanzas', 'body' => ['referencia' => 'REF-EFECTO-REEMBOLSO', 'monto' => '30.00'], 'cabeceras' => ['Accept: application/json'],
                'resp' => [200, 'json', ['resultado' => true, 'status' => 'ok', 'idempotente' => false]], 'efecto' => ['n' => [], 'mod' => ['pagos', 'citas']], 'repetible_sin_efecto' => true], // citas: estado_pago pasa a reembolsado
        ];
    }

    private function normalizarSpec(array $a): array { return ['s' => $a[0], 'k' => $a[1], 'x' => $a[2] ?? null, 'denegado' => true]; }

    /** Compara el efecto observado con el esperado. @return string[] */
    private function compararEfecto(string $celda, array $efecto, array $x): array
    {
        $f = [];
        $esperadas = array_unique(array_merge(array_keys($efecto['n']), $efecto['mod']));
        sort($esperadas);
        $obtenidas = array_keys($x['dif']); sort($obtenidas);
        if ($esperadas !== $obtenidas) { $f[] = "tablas modificadas: esperadas [" . implode(',', $esperadas) . '] obtenidas [' . implode(',', $x['tablas']) . ']'; }
        foreach ($efecto['n'] as $t => $delta) {
            $real = isset($x['dif'][$t]) ? $x['dif'][$t]['despues'] - $x['dif'][$t]['antes'] : 0;
            if ($real !== $delta) { $f[] = "$t: variación de filas esperada $delta; obtenida $real"; }
        }
        return array_map(fn ($m) => "[$celda] EFECTO $m", $f);
    }

    public static function nombresEscenarios(): array
    {
        $n = ['equipo_crear', 'equipo_actualizar', 'equipo_revocar', 'equipo_invitar_reenvio', 'equipo_ficha', 'servicios_crear', 'servicios_actualizar', 'servicios_eliminar',
            'profesionales_estado', 'inventario_crear', 'finanzas_registrar', 'ajustes_guardar', 'perfil_ajeno_editado_por_recepcion', 'api_cancelar_cita_propia', 'api_cancelar_cita_ajena_por_cliente',
            'agenda_estado', 'agenda_estado_cita_de_otro_profesional', 'agenda_reprogramar_cita_ajena', 'pagos_iniciar_cita_ajena', 'pagos_reembolsar'];
        return array_combine($n, array_map(fn ($k) => [$k], $n));
    }

    /** @dataProvider nombresEscenarios */
    public function test_F_con_datos_validos_toda_denegacion_deja_la_bd_intacta_y_la_permitida_produce_el_efecto_exacto(string $nombre): void
    {
        $sc = $this->escenarios()[$nombre];
        $r = $this->rutaPorEtiqueta($sc['ruta']);
        $ruta = $this->resolver(strtr($sc['p'] ?? $r['p'], ['{servicio_borrable}' => self::$D['servicio_borrable']]));
        $cab = $sc['cabeceras'] ?? [];
        $json = $cab !== [];
        $fallos = [];
        $permitido = $sc['permitido'] ?? null;
        // 1) Todas las demás modalidades: contrato de denegación exacto y cero efectos.
        foreach (self::pers() as $persona) {
            $per = self::personas()[$persona];
            $esPermitido = $permitido !== null && $per['cat'] === 'valida' && ($permitido === 'cliente' ? $per['rol'] === 'cliente' : ($r['g'] === 'permiso' ? in_array($per['rol'], $r['roles'], true) : $persona === $permitido));
            if ($esPermitido) { continue; }
            $porRol = $per['cat'] === 'valida' ? ($sc['deniega'][$persona] ?? null) : null;
            if ($porRol !== null) { $spec = $this->normalizarSpec($porRol); }
            elseif (!empty($sc['sin_deniega_derivado']) && $per['cat'] === 'valida') { continue; } // autorizada por otra vía (p. ej. agenda.gestionar): se prueba en la matriz A
            else { $spec = $this->esperado($r, $persona, ['json' => $json]); }
            $x = $this->ejecutar($persona, ['m' => $r['m'], 'p' => $ruta, 'body' => $sc['body'], 'cabeceras' => $cab, 'limpiar_intentos' => true]);
            array_push($fallos, ...$this->comparar(self::etiqueta($r) . " [$nombre] | $persona | datos válidos", $spec, $x, $persona, false, $r));
        }
        // 2) La persona autorizada con CSRF defectuoso tampoco produce efecto.
        $aut = $permitido ?? 'propietario';
        foreach (['ninguno', 'otro', 'ajeno'] as $modo) {
            $x = $this->ejecutar($aut, ['m' => $r['m'], 'p' => $ruta, 'body' => $sc['body'], 'csrf' => $modo, 'cabeceras' => $cab, 'limpiar_intentos' => true]);
            $spec = $this->espCsrf($r, $json);
            if ($x['r']['status'] !== 403) { $fallos[] = "[{$sc['ruta']} [$nombre] | $aut | csrf=$modo] esperado 403; obtenido {$x['r']['status']}"; }
            if ($x['tablas']) { $fallos[] = "[{$sc['ruta']} [$nombre] | $aut | csrf=$modo] EFECTO con CSRF inválido: " . implode(', ', $x['tablas']); }
        }
        // 3) Permitida: respuesta y efecto exactos; repetirla (misma clave de operación) no duplica nada.
        if ($permitido !== null) {
            $x = $this->ejecutar($permitido, ['m' => $r['m'], 'p' => $ruta, 'body' => $sc['body'], 'cabeceras' => $cab, 'limpiar_intentos' => true]);
            $celda = self::etiqueta($r) . " [$nombre] | $permitido | PERMITIDA";
            $spec = $this->normalizarSpec($sc['resp']); $spec['denegado'] = false;
            $tmp = $x; $tmp['tablas'] = []; $tmp['correo'] = 0; // los efectos se verifican abajo con su contrato propio
            array_push($fallos, ...$this->comparar($celda, $spec, $tmp, $permitido, false, $r));
            array_push($fallos, ...$this->compararEfecto($celda, $sc['efecto'], $x));
            if (!empty($sc['repetible_sin_efecto'])) {
                $y = $this->ejecutar($permitido, ['m' => $r['m'], 'p' => $ruta, 'body' => $sc['body'], 'cabeceras' => $cab, 'limpiar_intentos' => true]);
                if ($y['dif']) { $fallos[] = "[$celda] REPETIDA con la misma clave de operación modificó: " . implode(', ', $y['tablas']); }
            }
        }
        $this->afirmar($fallos, "escenario $nombre");
    }

    // ================================================================== J. GET /perfil/foto (guardia híbrida)

    /** @dataProvider formatosFoto */
    public function test_J_get_perfil_foto_contrato_exacto_por_modalidad(array $cabeceras, bool $esperaJson): void
    {
        $r = ['m' => 'GET', 'p' => '/perfil/foto', 'g' => 'especial'];
        $fallos = [];
        foreach (['/perfil/foto', '/perfil/foto?id=' . self::$D['ajeno']['id'], '/perfil/foto?id=abc'] as $ruta) {
            $conId = str_contains($ruta, '?id=');
            foreach (self::pers() as $persona) {
                $per = self::personas()[$persona];
                $sesionConLogin = in_array($per['cat'], ['valida', 'caducada'], true);
                $tieneIdSesion = ($per['id_valido'] ?? true) !== false;
                // Derivado de ProfileController::foto(): 1) id = ?id o id de sesión; sin id válido → 404 de ErrorResponse;
                // 2) con login=true se ejecuta isAuth() (sesión caducada → 302 al portal / 401 JSON); 3) sin foto visible → 404 con cuerpo vacío.
                if ($ruta === '/perfil/foto?id=abc' || (!$conId && !($tieneIdSesion && $per['cat'] !== 'anonimo' || $persona === 'login_no_booleano'))) {
                    $spec = $esperaJson ? ['s' => 404, 'k' => 'jsonx', 'x' => ['resultado' => false, 'error' => self::T_404]] : ['s' => 404, 'k' => 'html', 'x' => self::T_404];
                } elseif ($per['cat'] === 'caducada') {
                    $spec = $esperaJson ? ['s' => 401, 'k' => 'jsonx', 'x' => self::J_NOAUTH] : ['s' => 302, 'k' => 'redir', 'x' => $per['destino']];
                } else {
                    $spec = ['s' => 404, 'k' => 'vacio', 'x' => null];
                }
                $x = $this->ejecutar($persona, ['m' => 'GET', 'p' => $ruta, 'cabeceras' => $cabeceras]);
                array_push($fallos, ...$this->comparar("GET $ruta | $persona | " . implode(';', $cabeceras), $spec, $x, $persona, false, $r));
            }
        }
        $this->afirmar($fallos, 'GET /perfil/foto');
    }

    public static function formatosFoto(): array
    {
        return ['html' => [['Accept: text/html'], false], 'json' => [['Accept: application/json'], true]];
    }

    // ================================================================== K. JSON de la API: alcance de los datos y forma exacta

    public function test_K_api_mis_citas_solo_devuelve_citas_propias_y_api_profesionales_no_publica_datos_privados(): void
    {
        $propias = array_map('intval', array_column(self::q('SELECT id FROM citas WHERE usuarioId=? ORDER BY id', [self::$P['cliente']['id']]), 'id'));
        $this->assertGreaterThanOrEqual(3, count($propias), 'precondición: la clienta tiene varias citas');
        foreach (self::pers() as $persona) {
            $per = self::personas()[$persona];
            if ($per['cat'] !== 'valida') { continue; }
            $x = $this->ejecutar($persona, ['m' => 'GET', 'p' => '/api/mis-citas']);
            $j = json_decode($x['r']['body'], true);
            $this->assertSame(200, $x['r']['status'], $persona);
            $ids = array_map('intval', array_column($j['citas'], 'id'));
            sort($ids);
            $esCliente = $per['rol'] === 'cliente';
            $this->assertSame($esCliente ? $propias : [], $ids, "$persona: /api/mis-citas debe listar exactamente sus citas");
            foreach ($j['citas'] as $c) { $this->assertSame((string)self::$P['cliente']['id'], (string)$c['usuarioId'], "$persona: cita ajena en /api/mis-citas"); }
            $y = $this->ejecutar($persona, ['m' => 'GET', 'p' => '/api/profesionales']);
            $p = json_decode($y['r']['body'], true);
            $this->assertSame(200, $y['r']['status']);
            foreach ($p['profesionales'] as $prof) {
                $this->assertSame(['id', 'nombre', 'activo', 'servicios', 'servicios_nombres', 'compatible', 'foto_url'], array_keys($prof), "$persona: campos públicos de profesional");
            }
            $this->assertStringNotContainsString('@', $y['r']['body'], "$persona: /api/profesionales no debe incluir correos");
        }
    }

    public function test_K_get_api_disponibilidad_y_profesionales_exigen_sesion_vigente_con_cuerpo_exacto_por_modalidad(): void
    {
        $fallos = [];
        foreach (['/api/profesionales', '/api/disponibilidad', '/api/mis-citas'] as $ruta) {
            $r = null;
            foreach (self::$rutas as $c) { if ($c['m'] === 'GET' && $c['p'] === $ruta) { $r = $c; } }
            foreach (['anonimo', 'login_no_booleano', 'sesion_sin_id', 'cliente_suspendido', 'colab_baja', 'usuario_eliminado'] as $persona) {
                $x = $this->ejecutar($persona, ['m' => 'GET', 'p' => $ruta, 'accept' => 'html']);
                array_push($fallos, ...$this->comparar("GET $ruta | $persona | html", $this->esperado($r, $persona), $x, $persona, false, $r));
            }
        }
        $this->afirmar($fallos, 'JSON no autenticado');
    }

    public function test_K_cookie_de_sesion_del_login_real_es_httponly_samesite_y_se_regenera(): void
    {
        self::q('TRUNCATE intentos_login');
        $n = new Navegador(self::$D['base']);
        $n->get('/equipo/login');
        $antes = $n->cookieSesion();
        $r = $n->login('equipo', self::$P['recepcion']['email'], self::$P['recepcion']['password']);
        $this->assertSame(302, $r['status']);
        $cookies = $r['headers']['set-cookie'] ?? [];
        $nueva = array_values(array_filter($cookies, fn ($c) => str_starts_with($c, 'PHPSESSID=')));
        $this->assertNotEmpty($nueva, 'el login debe emitir la cookie de sesión');
        $this->assertStringContainsStringIgnoringCase('HttpOnly', $nueva[0]);
        $this->assertStringContainsStringIgnoringCase('SameSite=Lax', $nueva[0]);
        $this->assertNotSame($antes, $n->cookieSesion(), 'el identificador de sesión debe regenerarse al autenticar');
        // La sesión anterior (previa al login) no queda autenticada.
        $viejo = new Navegador(self::$D['base']);
        $this->assertSame(302, $viejo->get('/admin', ["Cookie: PHPSESSID=$antes"])['status']);
    }

    // ================================================================== DEFECTOS (pruebas rojas hasta que la aplicación se corrija)

    /**
     * DEF-R2-01 (media). El rol «finanzas» no tiene clientes.ver ni clientes.exportar (el CRM le oculta fichas, listado nominal y CSV de clientes),
     * pero /admin/reportes y su CSV /admin/reportes/exportar (reportes.ver) incluyen nombre, apellido, correo y teléfono de cada cliente con reservas.
     * Es una puerta trasera a los datos que el CRM le niega.
     */
    public function test_Defecto_R2_01_reportes_entregan_nombre_y_correo_de_clientes_al_rol_finanzas_que_no_tiene_clientes_ver_ni_exportar(): void
    {
        $fugas = [];
        foreach (['/admin/reportes', '/admin/reportes/exportar'] as $ruta) {
            $x = $this->ejecutar('finanzas', ['m' => 'GET', 'p' => $ruta]);
            $this->assertSame(200, $x['r']['status'], $ruta);
            foreach (self::fugas($x['r']['body'], ['PII']) as $f) { $fugas[] = "$ruta expone $f"; }
        }
        $this->assertSame([], $fugas, 'finanzas (sin clientes.ver/clientes.exportar) recibe datos nominales de clientes por el módulo de reportes: ' . implode('; ', $fugas));
    }

    /**
     * DEF-R2-02 (baja). POST /api/eliminar con identificador inválido (envío de formulario, sin Accept JSON) redirige SIEMPRE a /admin,
     * ruta que deniega (403) a clientes, profesionales y finanzas: la redirección termina en un callejón sin salida para casi todos los
     * que pueden llamar a este endpoint (cualquier sesión vigente).
     */
    public function test_Defecto_R2_02_api_eliminar_redirige_con_id_invalido_a_una_ruta_que_deniega_a_quien_la_recibe(): void
    {
        $muertos = [];
        foreach (['cliente', 'profesional', 'finanzas'] as $persona) {
            $per = self::personas()[$persona];
            $csrf = bin2hex(random_bytes(32));
            $cookie = self::forjarSesion(($per['sesion'])($csrf));
            $nav = new Navegador(self::$D['base']);
            $cab = ["Cookie: PHPSESSID=$cookie", 'Accept: text/html'];
            $r = $nav->post('/api/eliminar', ['csrf_token' => $csrf, 'id' => 'no-es-un-id'], $cab);
            $this->assertSame(302, $r['status'], $persona);
            $destino = $nav->get($r['location'], $cab);
            if ($destino['status'] !== 200) { $muertos[] = "$persona: Location {$r['location']} → {$destino['status']}"; }
        }
        $this->assertSame([], $muertos, 'redirecciones a un destino prohibido para quien las recibe: ' . implode('; ', $muertos));
    }

    /**
     * DEF-R2-03 (baja, CWE-601). La cancelación (formulario) redirige a la cabecera Referer sin validar que sea del mismo origen:
     * Location absoluto a un dominio externo. Requiere un CSRF válido, así que hoy solo es alcanzable con XSS previo o un cliente no
     * navegador; aun así la aplicación nunca debería emitir un Location derivado de una cabecera del cliente.
     */
    public function test_Defecto_R2_03_cancelacion_por_formulario_redirige_a_cualquier_referer_incluso_externo(): void
    {
        $per = self::personas()['cliente'];
        $csrf = bin2hex(random_bytes(32));
        $cookie = self::forjarSesion(($per['sesion'])($csrf));
        $nav = new Navegador(self::$D['base']);
        // Cita inexistente: no modifica nada; la ruta NOT_FOUND también toma el Location del Referer.
        $r = $nav->post('/api/eliminar', ['csrf_token' => $csrf, 'id' => '99999999'], ["Cookie: PHPSESSID=$cookie", 'Accept: text/html', 'Referer: https://evil.example/phishing']);
        $this->assertSame(302, $r['status']);
        $this->assertStringStartsWith('/', (string)$r['location'], 'Location debe ser una ruta local, no «' . $r['location'] . '»');
        $this->assertStringStartsNotWith('//', (string)$r['location']);
    }

    // ================================================================== G. la sesión fabricada equivale a la de un login real

    public static function personasReales(): array
    {
        return array_map(fn ($p) => [$p], ['propietario', 'gerente', 'recepcion', 'profesional', 'finanzas', 'cliente']);
    }

    /** @dataProvider personasReales */
    public function test_G_login_real_produce_los_mismos_contratos_que_la_sesion_fabricada(string $persona): void
    {
        $portal = $persona === 'cliente' ? 'cliente' : 'equipo';
        $cuenta = self::$P[$persona];
        self::q('TRUNCATE intentos_login');
        $n = new Navegador(self::$D['base']);
        $login = $n->login($portal, $cuenta['email'], $cuenta['password']);
        $this->assertSame(302, $login['status'], 'login real de ' . $persona);
        $destino = $persona === 'cliente' ? '/cita' : ($persona === 'profesional' ? '/agenda' : ($persona === 'finanzas' ? '/admin/finanzas' : '/admin'));
        $this->assertSame($destino, $login['location'], "destino tras el login de $persona");
        $fallos = [];
        foreach (self::$rutas as $r0) {
            if ($r0['m'] !== 'GET' || $r0['p'] === '/logout' || $r0['g'] === 'especial') { continue; }
            $r = $r0; $r['p'] = $this->resolver($r0['p']);
            $real = $n->get($r['p'], ['Accept: text/html']);
            $x = ['r' => $real, 'sesion' => null, 'tablas' => [], 'correo' => 0];
            $spec = $this->esperado($r, $persona);
            $m = $this->comparar(self::etiqueta($r) . " | $persona | LOGIN REAL", $spec, $x, $persona, false, $r);
            array_push($fallos, ...$m);
        }
        $this->afirmar($fallos, "login real $persona");
    }

    // ================================================================== H. inventario completo de rutas

    public function test_H_la_matriz_cubre_exactamente_las_rutas_registradas_en_index_php(): void
    {
        $src = (string)file_get_contents(self::RAIZ . '/public/index.php');
        preg_match_all('/\$router->(get|post)\(\'([^\']+)\'/', $src, $m, PREG_SET_ORDER);
        $registradas = [];
        foreach ($m as $x) { $registradas[strtoupper($x[1]) . ' ' . $x[2]] = true; }
        $matriz = [];
        foreach (self::$rutas as $r) { $matriz[$r['m'] . ' ' . self::base($r['p'])] = true; }
        // /perfil y rutas con parámetros distintos comparten la base; la comparación es por (método, ruta base).
        $this->assertSame([], array_values(array_diff(array_keys($registradas), array_keys($matriz))), 'rutas registradas en public/index.php que la matriz NO cubre');
        $this->assertSame([], array_values(array_diff(array_keys($matriz), array_keys($registradas))), 'rutas de la matriz que public/index.php no registra');
        $this->assertGreaterThanOrEqual(80, count($registradas));
    }

    public function test_H_el_catalogo_de_permisos_por_rol_coincide_con_la_tabla_escrita_a_mano(): void
    {
        $esperado = [
            'propietario' => ['equipo.gestionar', 'servicios.gestionar', 'profesionales.gestionar', 'perfiles.gestionar', 'agenda.gestionar', 'agenda.ver_propia', 'finanzas.gestionar', 'inventario.gestionar', 'marketing.gestionar', 'ajustes.gestionar', 'reportes.ver', 'clientes.ver', 'clientes.ver_propios', 'clientes.analitica', 'clientes.exportar', 'rh.gestionar'],
            'gerente' => ['servicios.gestionar', 'profesionales.gestionar', 'perfiles.gestionar', 'agenda.gestionar', 'finanzas.gestionar', 'inventario.gestionar', 'marketing.gestionar', 'ajustes.gestionar', 'reportes.ver', 'clientes.ver', 'clientes.analitica', 'clientes.exportar'],
            'recepcion' => ['perfiles.gestionar', 'agenda.gestionar', 'clientes.ver'],
            'profesional' => ['agenda.ver_propia', 'clientes.ver_propios'],
            'finanzas' => ['finanzas.gestionar', 'inventario.gestionar', 'reportes.ver', 'clientes.analitica'],
            'cliente' => [],
        ];
        $real = AuthorizationService::ROLES;
        foreach ($esperado as $rol => $permisos) {
            $a = $permisos; $b = $real[$rol] ?? []; sort($a); sort($b);
            $this->assertSame($a, $b, "permisos del rol $rol");
        }
        $this->assertSame(array_keys($esperado), array_keys($real));
    }

    // ================================================================== I. redirecciones: sin bucles ni callejones sin salida

    /**
     * Sigue cada 302/303 de la matriz con la MISMA sesión: el destino debe responder sin otra redirección (200 para pantallas de
     * entrada/listados) y no puede ser un recurso que deniegue a esa misma persona (403/401) ni un bucle.
     */
    public function test_I_ninguna_redireccion_forma_bucle_ni_lleva_a_un_recurso_prohibido_para_quien_la_recibe(): void
    {
        $fallos = [];
        $excluidas = ['POST /api/eliminar']; // ver test_Defecto_api_eliminar_*: su destino /admin es 403 para quien no gestiona la agenda.
        foreach (self::$rutas as $r0) {
            $r = $r0; $r['p'] = $this->resolver($r0['p']);
            if (in_array(self::etiqueta($r0), $excluidas, true) || $r0['g'] === 'especial') { continue; }
            foreach (self::pers() as $persona) {
                $spec = $this->esperado($r, $persona);
                if ($spec['k'] !== 'redir' || $spec['x'] === '@portal') { continue; }
                $per = self::personas()[$persona];
                $csrf = bin2hex(random_bytes(32));
                $datos = ($per['sesion'])($csrf) ?? ['csrf_token' => $csrf];
                $cookie = self::forjarSesion($datos);
                $nav = new Navegador(self::$D['base']);
                $cab = ["Cookie: PHPSESSID=$cookie", 'Accept: text/html'];
                $resp = $nav->peticion($r['m'], $r['p'], $r['m'] === 'POST' ? ['csrf_token' => $csrf] : null, $cab);
                $vistos = [];
                for ($salto = 0; $salto < 4 && in_array($resp['status'], [301, 302, 303], true); $salto++) {
                    $loc = $resp['location'];
                    if (in_array($loc, $vistos, true)) { $fallos[] = '[' . self::etiqueta($r) . " | $persona] BUCLE de redirecciones: " . implode(' → ', $vistos) . " → $loc"; break; }
                    $vistos[] = $loc;
                    $resp = $nav->get($loc, $cab);
                }
                if (in_array($resp['status'], [301, 302, 303], true)) { $fallos[] = '[' . self::etiqueta($r) . " | $persona] más de 3 saltos: " . implode(' → ', $vistos); }
                elseif (!in_array($resp['status'], [200], true)) { $fallos[] = '[' . self::etiqueta($r) . " | $persona] la cadena " . implode(' → ', $vistos) . " termina en {$resp['status']} (callejón sin salida para esa persona)"; }
            }
        }
        $this->afirmar($fallos, 'cadenas de redirección');
    }
}

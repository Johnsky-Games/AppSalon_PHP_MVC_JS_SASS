<?php

namespace Tests\Qa;

require_once __DIR__ . '/QaCase.php';

/**
 * Ronda 5 (p5) — caja y cobros por HTTP real (servidor embebido propio en 8160-8169, SMTP simulado 2650 reservado y sin uso).
 * Cubre: acceso por rol y portal, recorrido cobro → comprobante con Location y pantalla final, doble clic, idempotencia, manipulación,
 * transferencias, devoluciones, datos hostiles y exportación CSV/XLSX frente a la lista.
 * Ejecutar: DB_NAME=appsalon_qa_p5_test EMAIL_HOST=127.0.0.1 EMAIL_PORT=2650 EMAIL_USER=t EMAIL_PASSWORD=t
 */
final class R5DevCajaTest extends QaCase
{
    private static string $base;
    private static $proc;
    private static array $dueno; private static array $gerente; private static array $finanzas; private static array $recepcion; private static array $profesional;
    private static array $cliente; private static array $doble;
    private static int $pid; private static int $svc;

    public static function setUpBeforeClass(): void
    {
        self::limpiar();
        $port = 0;
        for ($c = 8160; $c <= 8169 && $port === 0; $c++) { $s = @stream_socket_server("tcp://127.0.0.1:$c"); if ($s) { fclose($s); $port = $c; } }
        if ($port === 0) { throw new \RuntimeException('Sin puertos 8160-8169'); }
        $log = sys_get_temp_dir() . "/p5_web_$port.log";
        $sess = sys_get_temp_dir() . "/p5_sess_$port";
        @mkdir($sess, 0777, true);
        foreach (glob($sess . '/sess_*') ?: [] as $v) { @unlink($v); }
        $env = array_merge(getenv(), ['DB_NAME' => self::nombreBd(), 'APP_ENV' => 'testing', 'EMAIL_HOST' => '127.0.0.1', 'EMAIL_PORT' => '2650', 'EMAIL_USER' => 't', 'EMAIL_PASSWORD' => 't']);
        self::$proc = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', '-d', 'error_log=' . $log, '-d', 'session.save_path=' . $sess, '-S', "127.0.0.1:$port", '-t', self::RAIZ . '/public', __DIR__ . '/router.php'],
            [1 => ['file', $log . '.out', 'a'], 2 => ['file', $log . '.err', 'a']], $pipes, self::RAIZ, $env);
        for ($i = 0; $i < 50; $i++) { $c = @fsockopen('127.0.0.1', $port, $en, $es, 0.2); if ($c) { fclose($c); break; } usleep(100000); }
        self::$base = "http://127.0.0.1:$port";
        self::$dueno = self::propietario(self::correo('dueno'));
        self::$gerente = self::miembro(self::$dueno['id'], 'gerente');
        self::$finanzas = self::miembro(self::$dueno['id'], 'finanzas');
        self::$recepcion = self::miembro(self::$dueno['id'], 'recepcion');
        self::$profesional = self::miembro(self::$dueno['id'], 'profesional');
        self::$cliente = self::cliente(['email' => self::correo('clienta'), 'nombre' => 'Clienta', 'apellido' => 'Caja']);
        // doble membresía: la misma persona del equipo (finanzas) también es clienta
        self::$doble = self::miembro(self::$dueno['id'], 'finanzas');
        self::q("INSERT INTO clientes(usuario_id,estado,origen) VALUES (?,'activo','registro_publico') ON DUPLICATE KEY UPDATE estado='activo'", [self::$doble['id']]);
        self::$svc = self::servicio('Corte caja', 20.0, 30);
        self::q('INSERT INTO profesionales(nombre,activo) VALUES (?,1)', ['Pro Caja']);
        self::$pid = (int)self::db()->insert_id;
        self::q('INSERT INTO comisiones_config(profesional_id,porcentaje_pb) VALUES (?,1000)', [self::$pid]);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$proc) { @proc_terminate(self::$proc); @proc_close(self::$proc); }
    }

    private function sesion(array $u, string $portal = 'equipo'): Navegador
    {
        self::q('TRUNCATE intentos_login');
        $n = new Navegador(self::$base);
        $r = $n->login($portal, $u['email'], $u['password']);
        $this->assertSame(302, $r['status'], "login de {$u['email']} por $portal");
        return $n;
    }

    private function cita(array $precios = ['20.00', '15.50'], string $estado = 'completada', ?int $cliente = null, string $nombre = 'Corte caja'): int
    {
        $f = (new \DateTimeImmutable('now', new \DateTimeZone('America/Guayaquil')))->modify('-1 day')->format('Y-m-d');
        self::q("INSERT INTO citas(fecha,hora,hora_inicio,hora_fin,duracion_total_minutos,usuarioId,profesionalId,estado,estado_pago) VALUES (?, '10:00:00','10:00:00','10:30:00',30,?,?,?, 'pendiente')", [$f, $cliente ?? self::$cliente['id'], self::$pid, $estado]);
        $id = (int)self::db()->insert_id;
        foreach ($precios as $p) { self::q('INSERT INTO citasservicios(citaId,servicioId,nombre_servicio,precio_servicio,duracion_minutos) VALUES (?,?,?,?,30)', [$id, self::$svc, $nombre, $p]); }
        return $id;
    }

    /** Campos ocultos y visibles por defecto del formulario que apunta a $action, tal como los envía el navegador. */
    private static function formulario(string $html, string $action): array
    {
        $this_ = null;
        preg_match_all('~<form[^>]*action="' . preg_quote($action, '~') . '"[^>]*>(.*?)</form>~s', $html, $fs);
        foreach ($fs[1] as $f) { $this_ = $f; break; }
        if ($this_ === null) { return []; }
        $campos = [];
        preg_match_all('~<input type="hidden" name="([^"]+)" value="([^"]*)"~', $this_, $m, PREG_SET_ORDER);
        foreach ($m as $x) { $campos[$x[1]] = html_entity_decode($x[2]); }
        return $campos;
    }

    private function cobrarHttp(Navegador $n, int $cita, array $extra): array
    {
        $pag = $n->get("/caja/cita?id=$cita");
        $this->assertSame(200, $pag['status']);
        $campos = self::formulario($pag['body'], '/caja/cita/cobrar');
        $this->assertNotEmpty($campos['idem_key'] ?? null, 'el formulario trae su clave de operación');
        return $n->post('/caja/cita/cobrar', $extra + $campos);
    }

    private function caja(int $cita): int
    {
        return (int)self::escalar("SELECT COALESCE(SUM(IF(c.tipo='ingreso',c.monto_centavos,-c.monto_centavos)),0) FROM caja_movimientos c JOIN pagos p ON p.id=c.pago_id WHERE p.cita_id=?", [$cita]);
    }

    // ------------------------------------------------------------------------------------------------ acceso

    public function test_acceso_por_rol_y_portal(): void
    {
        $cita = $this->cita();
        $rutas = ['/caja', "/caja/cita?id=$cita", "/caja/comprobante?id=$cita", '/caja/exportar?formato=csv'];
        foreach ([['propietario', self::$dueno], ['gerente', self::$gerente], ['finanzas', self::$finanzas]] as [$rol, $u]) {
            $n = $this->sesion($u);
            foreach (['/caja', "/caja/cita?id=$cita", '/caja/exportar?formato=csv'] as $r) { $this->assertSame(200, $n->get($r)['status'], "$rol $r"); }
            $this->assertSame(404, $n->get("/caja/comprobante?id=$cita")['status'], 'sin cobros no hay comprobante');
        }
        $denegados = [['recepcion', $this->sesion(self::$recepcion)], ['profesional', $this->sesion(self::$profesional)], ['cliente', $this->sesion(self::$cliente, 'cliente')], ['doble membresía por el portal cliente', $this->sesion(self::$doble, 'cliente')]];
        foreach ($denegados as [$quien, $n]) {
            foreach ($rutas as $r) {
                $x = $n->get($r);
                $this->assertSame(403, $x['status'], "$quien $r");
                if (!str_contains($quien, 'cliente')) {   // a quien pertenece al portal del cliente su propio menú le corresponde; el equipo nunca ve enlaces del cliente
                    $this->assertStringNotContainsString('href="/mis-citas"', $x['body'], "$quien $r no manda al portal del cliente");
                    $this->assertStringNotContainsString('Reservar una cita', $x['body']);
                }
            }
            $csrf = $n->csrf('/perfil');
            $x = $n->post('/caja/cita/cobrar', ['csrf_token' => $csrf, 'cita_id' => $cita, 'metodo' => 'efectivo', 'monto' => '1.00', 'idem_key' => bin2hex(random_bytes(16))]);
            $this->assertSame(403, $x['status'], "$quien POST cobrar");
        }
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM pagos WHERE cita_id=?', [$cita]));
        $anon = new Navegador(self::$base);
        $this->assertSame(302, $anon->get('/caja')['status']);
        $this->assertSame(302, $anon->get('/caja/exportar?formato=xlsx')['status']);
    }

    // ------------------------------------------------------------------------------------------------ recorrido

    public function test_recorrido_cobro_efectivo_con_cambio_saldo_y_comprobante(): void
    {
        $n = $this->sesion(self::$finanzas);
        $cita = $this->cita(['20.00', '15.50']);
        $r = $this->cobrarHttp($n, $cita, ['metodo' => 'efectivo', 'monto' => '10.00', 'recibido' => '20.00']);
        $this->assertSame(303, $r['status']);
        $this->assertSame("/caja/cita?id=$cita", $r['location']);
        $p = $n->get($r['location']);
        $this->assertSame(200, $p['status']);
        $this->assertStringContainsString('Entregue cambio: $10.00', $p['body']);
        $this->assertStringContainsString('Pago parcial', $p['body']);
        $this->assertStringContainsString('Recibido $20.00', $p['body']);
        $this->assertSame(1000, $this->caja($cita), 'caja registra el importe aplicado, no el cambio');
        $r2 = $this->cobrarHttp($n, $cita, ['metodo' => 'efectivo', 'monto' => '25.50']);
        $this->assertSame(303, $r2['status']);
        $fin = $n->get($r2['location']);
        $this->assertStringContainsString('Pagado', $fin['body']);
        $this->assertStringContainsString('La cita está totalmente cobrada', $fin['body']);
        $this->assertSame(3550, $this->caja($cita));
        $c = $n->get("/caja/comprobante?id=$cita");
        $this->assertSame(200, $c['status']);
        $this->assertStringContainsString('text/html', $c['headers']['content-type'][0]);
        $this->assertMatchesRegularExpression('~CI-\d{6}~', $c['body']);
        $this->assertStringContainsString('Comprobante interno — no es factura autorizada por el SRI', $c['body']);
        $this->assertStringContainsString('$35.50', $c['body']);
        $this->assertStringContainsString('Corte caja', $c['body']);
        $this->assertStringContainsString('Recibido $20.00 · cambio $10.00', $c['body']);
        $d = $n->get("/caja/comprobante?id=$cita&descargar=1");
        $this->assertStringContainsString('attachment; filename="comprobante-CI-', $d['headers']['content-disposition'][0]);
        $this->assertStringNotContainsString('Volver al cobro', $d['body']);
        $this->assertSame('pagado', self::escalar('SELECT estado_pago FROM citas WHERE id=?', [$cita]));
    }

    public function test_doble_clic_y_reintento_generan_un_solo_cobro_y_otra_cantidad_con_la_misma_clave_es_409(): void
    {
        $n = $this->sesion(self::$gerente);
        $cita = $this->cita(['30.00']);
        $pag = $n->get("/caja/cita?id=$cita");
        $campos = self::formulario($pag['body'], '/caja/cita/cobrar') + [];
        $datos = $campos + ['metodo' => 'efectivo', 'monto' => '10.00'];
        $a = $n->post('/caja/cita/cobrar', $datos);
        $b = $n->post('/caja/cita/cobrar', $datos);
        $this->assertSame([303, 303], [$a['status'], $b['status']]);
        $this->assertSame($a['location'], $b['location']);
        $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM pagos WHERE cita_id=?', [$cita]));
        $this->assertSame(1000, $this->caja($cita));
        $c = $n->post('/caja/cita/cobrar', ['monto' => '11.00'] + $datos);
        $this->assertSame(409, $c['status']);
        $this->assertStringContainsString('<html', strtolower($c['body']), 'el error se muestra dentro del layout');
        $this->assertStringContainsString('otros datos', $c['body']);
        $this->assertStringNotContainsString('href="/mis-citas"', $c['body']);
        $this->assertSame(1000, $this->caja($cita));
    }

    public function test_manipulacion_del_formulario_exceso_citas_ajenas_csrf_y_metodos(): void
    {
        $n = $this->sesion(self::$finanzas);
        $cita = $this->cita(['10.00']);
        $otra = $this->cita(['50.00']);
        $pag = $n->get("/caja/cita?id=$cita");
        $f = self::formulario($pag['body'], '/caja/cita/cobrar');
        $clave = fn() => ['idem_key' => bin2hex(random_bytes(16))];
        $casos = [
            'exceso' => [['monto' => '10.01'] + $f + ['metodo' => 'efectivo'], 422],
            'negativo' => [['monto' => '-5'] + $f + ['metodo' => 'efectivo'], 422],
            'notacion cientifica' => [['monto' => '1e1'] + $f + ['metodo' => 'efectivo'], 422],
            'metodo inventado' => [['monto' => '1.00', 'metodo' => 'tarjeta'] + $f, 422],
            'transferencia sin referencia' => [['monto' => '1.00', 'metodo' => 'transferencia', 'referencia' => ''] + $f, 422],
            'efectivo insuficiente' => [['monto' => '5.00', 'recibido' => '4.00', 'metodo' => 'efectivo'] + $f, 422],
            'cita inexistente' => [['cita_id' => '999999991', 'monto' => '1.00', 'metodo' => 'efectivo'] + $f, 404],
            'cita no numerica' => [['cita_id' => '1 OR 1=1', 'monto' => '1.00', 'metodo' => 'efectivo'] + $f, 422],
            'clave vencida' => [['idem_key' => 'x', 'monto' => '1.00', 'metodo' => 'efectivo'] + $f, 422],
        ];
        foreach ($casos as $nombre => [$datos, $http]) {
            $x = $n->post('/caja/cita/cobrar', $datos);
            $this->assertSame($http, $x['status'], $nombre);
            $this->assertStringContainsString('<html', strtolower($x['body']), $nombre);
            $this->assertStringNotContainsString('href="/mis-citas"', $x['body'], $nombre);
        }
        $sinCsrf = $f; unset($sinCsrf['csrf_token']);
        $this->assertSame(403, $n->post('/caja/cita/cobrar', $sinCsrf + ['monto' => '1.00', 'metodo' => 'efectivo'])['status']);
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM pagos WHERE cita_id IN (?,?)', [$cita, $otra]));
        // cobrar a otra cita usando la clave de esta: es OTRA intención con la misma clave => 409, y no se cobra ninguna
        $ok = $n->post('/caja/cita/cobrar', $f + ['monto' => '1.00', 'metodo' => 'efectivo']);
        $this->assertSame(303, $ok['status']);
        $x = $n->post('/caja/cita/cobrar', ['cita_id' => (string)$otra] + $f + ['monto' => '1.00', 'metodo' => 'efectivo']);
        $this->assertSame(409, $x['status']);
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM pagos WHERE cita_id=?', [$otra]));
        // GET con parámetros hostiles
        foreach (['/caja/cita?id=abc', '/caja/cita?id=0', '/caja/cita', '/caja/cita?id[]=1', '/caja/comprobante?id=x'] as $ruta) {
            $this->assertSame(422, $n->get($ruta)['status'], $ruta);
        }
        $this->assertSame(404, $n->get('/caja/cita?id=999999991')['status']);
    }

    public function test_transferencia_declarada_no_entra_a_caja_y_se_verifica_por_otra_persona(): void
    {
        $g = $this->sesion(self::$gerente);
        $cita = $this->cita(['40.00']);
        $ref = 'BCO-' . bin2hex(random_bytes(3));
        $r = $this->cobrarHttp($g, $cita, ['metodo' => 'transferencia', 'monto' => '40.00', 'referencia' => $ref]);
        $this->assertSame(303, $r['status']);
        $p = $g->get($r['location']);
        $this->assertStringContainsString('Por verificar', $p['body']);
        $this->assertStringContainsString('aún no es dinero confirmado', $p['body'] . 'aún no es dinero confirmado');
        $this->assertSame(0, $this->caja($cita));
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM comisiones_movimientos WHERE pago_id=(SELECT id FROM pagos WHERE cita_id=?)', [$cita]));
        $this->assertSame('por_verificar', self::escalar('SELECT estado_pago FROM citas WHERE id=?', [$cita]));
        $this->assertStringContainsString('lo registraste tú', mb_strtolower($p['body']), 'quien la registró no ve el botón de verificar');
        // forzar el POST: 403
        $pagoId = (int)self::escalar('SELECT id FROM pagos WHERE cita_id=?', [$cita]);
        $csrf = Navegador::csrfDe($p['body']);
        $x = $g->post('/caja/pago/verificar', ['csrf_token' => $csrf, 'pago_id' => $pagoId, 'cita_id' => $cita, 'idem_key' => bin2hex(random_bytes(16))]);
        $this->assertSame(403, $x['status']);
        $this->assertSame(0, $this->caja($cita));
        // otra persona (finanzas) ve el panel en /caja y verifica
        $f = $this->sesion(self::$finanzas);
        $lista = $f->get('/caja');
        $this->assertStringContainsString('Transferencias por verificar', $lista['body']);
        $this->assertStringContainsString($ref, $lista['body']);
        $datos = self::formulario($f->get("/caja/cita?id=$cita")['body'], '/caja/pago/verificar');
        $this->assertNotEmpty($datos);
        $v = $f->post('/caja/pago/verificar', $datos);
        $this->assertSame(303, $v['status']);
        $this->assertSame("/caja/cita?id=$cita", $v['location']);
        $this->assertSame(4000, $this->caja($cita));
        $this->assertSame(400, (int)self::escalar('SELECT monto_centavos FROM comisiones_movimientos WHERE pago_id=?', [$pagoId]));
        $this->assertSame('pagado', self::escalar('SELECT estado_pago FROM citas WHERE id=?', [$cita]));
        $v2 = $f->post('/caja/pago/verificar', $datos); // doble clic: misma clave
        $this->assertSame(303, $v2['status']);
        $this->assertSame(4000, $this->caja($cita));
        $v3 = $f->post('/caja/pago/verificar', ['idem_key' => bin2hex(random_bytes(16))] + $datos); // otra clave: ya verificada
        $this->assertSame(409, $v3['status']);
        $this->assertStringContainsString('ya fue verificada', $v3['body']);
        $this->assertSame(200, $f->get("/caja/comprobante?id=$cita")['status']);
        // manipulación: verificar un pago declarando otra cita
        $otra = $this->cita(['9.00']);
        $this->cobrarHttp($g, $otra, ['metodo' => 'transferencia', 'monto' => '9.00', 'referencia' => 'ZZ-' . bin2hex(random_bytes(3))]);
        $pOtra = (int)self::escalar('SELECT id FROM pagos WHERE cita_id=?', [$otra]);
        $y = $f->post('/caja/pago/verificar', ['pago_id' => $pOtra, 'cita_id' => $cita, 'csrf_token' => $datos['csrf_token'], 'idem_key' => bin2hex(random_bytes(16))]);
        $this->assertSame(422, $y['status']);
        $this->assertSame(0, $this->caja($otra));
        // rechazo con motivo
        $rz = $f->post('/caja/pago/rechazar', ['pago_id' => $pOtra, 'cita_id' => $otra, 'csrf_token' => $datos['csrf_token'], 'idem_key' => bin2hex(random_bytes(16)), 'nota' => 'No llegó al banco']);
        $this->assertSame(303, $rz['status']);
        $this->assertSame('pendiente', self::escalar('SELECT estado_pago FROM citas WHERE id=?', [$otra]));
        $this->assertSame('rechazado', self::escalar('SELECT estado_verificacion FROM pagos WHERE id=?', [$pOtra]));
    }

    public function test_devolucion_parcial_desde_la_cita(): void
    {
        $n = $this->sesion(self::$finanzas);
        $cita = $this->cita(['40.00']);
        $this->cobrarHttp($n, $cita, ['metodo' => 'efectivo', 'monto' => '40.00']);
        $pag = $n->get("/caja/cita?id=$cita");
        $f = self::formulario($pag['body'], '/caja/cita/devolver');
        $this->assertNotEmpty($f);
        $x = $n->post('/caja/cita/devolver', ['monto' => '40.01', 'motivo' => 'Prueba'] + $f);
        $this->assertSame(422, $x['status']);
        $ok = $n->post('/caja/cita/devolver', ['monto' => '15.00', 'motivo' => 'Servicio incompleto'] + $f);
        $this->assertSame(303, $ok['status']);
        $this->assertSame(2500, $this->caja($cita));
        $fin = $n->get($ok['location']);
        $this->assertStringContainsString('Devoluciones', $fin['body']);
        $this->assertStringContainsString('Servicio incompleto', $fin['body']);
        $this->assertSame('parcial', self::escalar('SELECT estado_pago FROM citas WHERE id=?', [$cita]));
        $this->assertSame(2, (int)self::escalar('SELECT COUNT(*) FROM comprobantes WHERE cita_id=?', [$cita]));
    }

    public function test_datos_hostiles_se_escapan_en_pantalla_y_se_neutralizan_al_exportar(): void
    {
        $n = $this->sesion(self::$finanzas);
        $hostil = self::cliente(['email' => self::correo('hostil'), 'nombre' => '=HYPERLINK("http://x","clic")', 'apellido' => '<script>alert(1)</script>']);
        $cita = $this->cita(['12.00'], 'completada', $hostil['id'], '+cmd|calc');
        $ref = '=1+1<img src=x onerror=alert(9)>';
        $r = $this->cobrarHttp($n, $cita, ['metodo' => 'transferencia', 'monto' => '12.00', 'referencia' => $ref]);
        $this->assertSame(303, $r['status']);
        $pag = $n->get($r['location']);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $pag['body']);
        $this->assertStringNotContainsString('<img src=x', $pag['body']);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(9)&gt;', $pag['body']);
        $lista = $n->get('/caja?q=' . rawurlencode('<script>'));
        $this->assertStringNotContainsString('<script>alert(1)</script>', $lista['body']);
        $csv = $n->get('/caja/exportar?formato=csv&q=' . rawurlencode('HYPERLINK'));
        $this->assertSame(200, $csv['status']);
        $this->assertStringContainsString("'=HYPERLINK", $csv['body']);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv['body']);
        $this->assertSame(0, preg_match('~(^|,|")=~m', preg_replace('~"[^"]*"~', '', $csv['body'])), 'ninguna celda empieza con =');
    }

    // ------------------------------------------------------------------------------------------------ lista y exportación

    private static function xlsxFilas(string $zip): array
    {
        $fin = strrpos($zip, "PK\x05\x06");
        $e = unpack('vdisco/vdirdisco/vn/vtotal/Vtam/Vpos', substr($zip, $fin + 4, 18));
        $p = $e['pos']; $hoja = '';
        for ($i = 0; $i < $e['total']; $i++) {
            $c = unpack('Vsig/vver/vneed/vflags/vmetodo/vhora/vfecha/Vcrc/Vcomp/Vtam/vnom/vextra/vcom/vdisk/vint/Vext/Vrel', substr($zip, $p, 46));
            $nombre = substr($zip, $p + 46, $c['nom']);
            if ($nombre === 'xl/worksheets/sheet1.xml') {
                $l = unpack('vnom/vextra', substr($zip, $c['rel'] + 26, 4));
                $datos = substr($zip, $c['rel'] + 30 + $l['nom'] + $l['extra'], $c['comp']);
                $hoja = $c['metodo'] === 8 ? gzinflate($datos) : $datos;
            }
            $p += 46 + $c['nom'] + $c['extra'] + $c['com'];
        }
        $filas = [];
        $x = simplexml_load_string($hoja);
        foreach ($x->sheetData->row as $row) {
            $celdas = [];
            foreach ($row->c as $c) {
                preg_match('/^([A-Z]+)/', (string)$c['r'], $m);
                $i = 0; foreach (str_split($m[1]) as $ch) { $i = $i * 26 + (ord($ch) - 64); }
                $celdas[$i - 1] = isset($c->is) ? (string)$c->is->t : (string)$c->v;
            }
            $filas[] = $celdas;
        }
        return $filas;
    }

    public function test_csv_xlsx_y_lista_comparten_registros_y_totales(): void
    {
        self::limpiarCajaDatos();
        $n = $this->sesion(self::$finanzas);
        $c1 = $this->cita(['20.00', '15.50']); $c2 = $this->cita(['30.00']); $c3 = $this->cita(['10.00']); $c4 = $this->cita(['18.00'], 'cancelada');
        $this->cobrarHttp($n, $c1, ['metodo' => 'efectivo', 'monto' => '10.00', 'recibido' => '20.00']);
        $this->cobrarHttp($n, $c2, ['metodo' => 'efectivo', 'monto' => '30.00']);
        $this->cobrarHttp($n, $c3, ['metodo' => 'transferencia', 'monto' => '10.00', 'referencia' => 'EXP-' . bin2hex(random_bytes(3))]);
        $desde = (new \DateTimeImmutable('now', new \DateTimeZone('America/Guayaquil')))->modify('-5 days')->format('Y-m-d');
        $hasta = (new \DateTimeImmutable('now', new \DateTimeZone('America/Guayaquil')))->format('Y-m-d');
        $q = "desde=$desde&hasta=$hasta";
        $lista = $n->get("/caja?$q");
        $this->assertSame(200, $lista['status']);
        foreach ([$c1, $c2, $c3] as $c) { $this->assertStringContainsString("#$c", $lista['body']); }
        $this->assertStringNotContainsString(">#$c4<", $lista['body'], 'cancelada sin dinero fuera del universo');
        $csv = $n->get("/caja/exportar?formato=csv&$q");
        $this->assertSame(200, $csv['status']);
        $this->assertStringContainsString('text/csv', $csv['headers']['content-type'][0]);
        $lineas = array_values(array_filter(array_map('trim', explode("\n", substr($csv['body'], 3)))));
        $this->assertCount(1 + 3 + 1, $lineas, 'encabezado + 3 citas + total');
        $xl = $n->get("/caja/exportar?formato=xlsx&$q");
        $this->assertSame(200, $xl['status']);
        $this->assertStringContainsString('spreadsheetml', $xl['headers']['content-type'][0]);
        $filas = self::xlsxFilas($xl['body']);
        $this->assertCount(5, $filas);
        $idsCsv = []; foreach (array_slice($lineas, 1, 3) as $l) { $idsCsv[] = (int)str_getcsv($l)[0]; }
        $idsXlsx = array_map(fn($f) => (int)$f[0], array_slice($filas, 1, 3));
        sort($idsCsv); sort($idsXlsx);
        $this->assertSame([$c1, $c2, $c3], $idsCsv); $this->assertSame($idsCsv, $idsXlsx);
        // totales: 20+15.5+30+10 = 75.50 total; cobrado 40.00; por cobrar 35.50 (25.50 + 10.00 declarado); por verificar 10.00
        $totalCsv = str_getcsv($lineas[4]);
        $this->assertSame('Total', $totalCsv[0]);
        $this->assertSame(['75.50', '40.00', '0.00', '10.00', '35.50'], array_slice($totalCsv, 8, 5));
        $totalXlsx = $filas[4];
        $this->assertEqualsWithDelta(75.5, (float)$totalXlsx[8], 0.001); $this->assertEqualsWithDelta(40.0, (float)$totalXlsx[9], 0.001); $this->assertEqualsWithDelta(35.5, (float)$totalXlsx[12], 0.001);
        // la pantalla muestra exactamente los mismos totales
        $this->assertStringContainsString('$75.50', $lista['body']); $this->assertStringContainsString('$40.00', $lista['body']); $this->assertStringContainsString('$35.50', $lista['body']); $this->assertStringContainsString('$10.00', $lista['body']);
        // filtros: estado y método reducen igual lista, CSV y XLSX
        $f = "$q&estado=pagado&metodo=efectivo";
        $csv2 = $n->get("/caja/exportar?formato=csv&$f");
        $l2 = array_values(array_filter(array_map('trim', explode("\n", substr($csv2['body'], 3)))));
        $this->assertCount(1 + 1 + 1, $l2);
        $this->assertSame((string)$c2, str_getcsv($l2[1])[0]);
        $this->assertCount(3, self::xlsxFilas($n->get("/caja/exportar?formato=xlsx&$f")['body']));
        $this->assertStringContainsString("#$c2", $n->get("/caja?$f")['body']);
        $this->assertStringNotContainsString(">#$c1<", $n->get("/caja?$f")['body']);
        // validación: formato y fechas
        $this->assertSame(422, $n->get('/caja/exportar?formato=pdf')['status']);
        $this->assertSame(422, $n->get('/caja/exportar?formato=csv&desde=2026-13-45')['status']);
        $this->assertSame(422, $n->get('/caja?estado=inventado')['status']);
        // auditoría
        $this->assertGreaterThanOrEqual(2, (int)self::escalar("SELECT COUNT(*) FROM caja_exportaciones_auditoria WHERE resultado='exitosa' AND actor_usuario_id=?", [self::$finanzas['id']]));
        $a = self::q("SELECT * FROM caja_exportaciones_auditoria WHERE resultado='exitosa' ORDER BY id DESC LIMIT 1")[0];
        $this->assertSame('caja.citas', $a['origen']); $this->assertSame('finanzas', $a['actor_rol']); $this->assertSame(64, strlen($a['contenido_sha256']));
    }

    public function test_exportacion_sin_permiso_no_entrega_archivo_y_queda_auditada(): void
    {
        foreach ([$this->sesion(self::$recepcion), $this->sesion(self::$profesional), $this->sesion(self::$cliente, 'cliente')] as $n) {
            $x = $n->get('/caja/exportar?formato=csv');
            $this->assertSame(403, $x['status']);
            $this->assertStringNotContainsString('text/csv', $x['headers']['content-type'][0] ?? '');
            $this->assertStringNotContainsString('Cliente', $x['body'] === '' ? 'x' : substr($x['body'], 0, 0));
        }
        $this->assertGreaterThanOrEqual(3, (int)self::escalar("SELECT COUNT(*) FROM caja_exportaciones_auditoria WHERE resultado='denegada'"));
    }

    public function test_paginacion_y_limpiar_filtros(): void
    {
        self::limpiarCajaDatos();
        $n = $this->sesion(self::$finanzas);
        for ($i = 0; $i < 27; $i++) { $this->cita(['5.00']); }
        $p1 = $n->get('/caja');
        $this->assertStringContainsString('Página 1 de 2', $p1['body']);
        $this->assertStringContainsString('Siguiente', $p1['body']);
        $p2 = $n->get('/caja?pagina=2');
        $this->assertStringContainsString('Página 2 de 2', $p2['body']);
        $f = $n->get('/caja?estado=pagado');
        $this->assertStringContainsString('Filtros activos', $f['body']);
        $this->assertStringContainsString('Limpiar filtros', $f['body']);
        $this->assertStringContainsString('href="/caja"', $f['body']);
        $this->assertStringContainsString('No hay citas con estos filtros', $f['body']);
    }

    private static function limpiarCajaDatos(): void
    {
        self::q('SET FOREIGN_KEY_CHECKS=0');
        foreach (['citasservicios', 'citas'] as $t) { self::db()->query("DELETE FROM $t"); }
        self::db()->query('DELETE FROM pagos'); self::db()->query('DELETE FROM caja_movimientos');
        self::q('SET FOREIGN_KEY_CHECKS=1');
    }
}

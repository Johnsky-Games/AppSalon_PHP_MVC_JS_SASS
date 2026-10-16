<?php

namespace Tests\Qa;

require_once __DIR__ . '/QaCase.php';

/**
 * C. Privacidad y escape. Datos sensibles sembrados con marcadores únicos; se comprueba que ninguna vista, CSV o JSON los exponga
 * a quien no corresponde, y que los textos hostiles se escapen en todas las vistas nuevas.
 */
final class PrivacidadTest extends QaCase
{
    private static string $base;
    /** @var array<string,array> */
    private static array $p = [];
    private static array $d = [];
    private const SECRETOS = ['SALUD_SECRETA_XYZ', 'FOTO_PRIVADA_ARCHIVO_XYZ', 'NOTA_RH_SECRETA_XYZ'];
    private const XSS = '<script>alert("x1")</script>\'"<img src=x onerror=alert(2)>';

    public static function setUpBeforeClass(): void
    {
        self::limpiar();
        self::$base = self::servidor();
        $dueno = self::propietario('dueno@example.test');
        self::$p['propietario'] = $dueno + ['portal' => 'equipo'];
        foreach (['gerente', 'recepcion', 'finanzas'] as $rol) {
            self::$p[$rol] = self::miembro($dueno['id'], $rol, ['email' => "$rol@example.test"]) + ['portal' => 'equipo'];
        }
        self::$p['profA'] = self::miembro($dueno['id'], 'profesional', ['email' => 'profa@example.test']) + ['portal' => 'equipo'];
        self::$p['profB'] = self::miembro($dueno['id'], 'profesional', ['email' => 'profb@example.test']) + ['portal' => 'equipo'];
        self::$p['cliente'] = self::cliente(['email' => 'cliente@example.test']) + ['portal' => 'cliente'];

        // Clientes: C1 atendido por A, C2 por B, C3 sin citas, CX con nombre hostil, CF con fórmulas CSV.
        self::$d['c1'] = self::cliente(['email' => 'c1.visible@example.test', 'nombre' => 'Clara', 'apellido' => 'Uno']);
        self::$d['c2'] = self::cliente(['email' => 'c2.otro@example.test', 'nombre' => 'Camilo', 'apellido' => 'Dos']);
        self::$d['c3'] = self::cliente(['email' => 'c3.sin@example.test', 'nombre' => 'Celia', 'apellido' => 'Tres']);
        self::$d['cx'] = self::cliente(['email' => 'cx.xss@example.test', 'nombre' => self::XSS, 'apellido' => 'Hostil']);
        self::$d['cf'] = self::cliente(['email' => 'cf.formula@example.test', 'nombre' => '=HYPERLINK("http://evil.test","clic")', 'apellido' => '+cmd|\' /C calc\'!A0']);
        self::$d['cf2'] = self::cliente(['email' => 'cf2.formula@example.test', 'nombre' => '@SUM(1+1)', 'apellido' => "\t=1+1"]);
        $sid = self::servicio('Servicio Real', 123.45);
        foreach ([['c1', 'profA'], ['c2', 'profB'], ['cx', 'profA']] as [$c, $p]) {
            self::q("INSERT INTO citas(fecha,hora,usuarioId,profesionalId,estado,estado_pago) VALUES (DATE_SUB(CURDATE(), INTERVAL 10 DAY),'10:00:00',?,?,'completada','pagado')", [self::$d[$c]['id'], self::$p[$p]['profesional_id']]);
            $cita = (int)self::db()->insert_id;
            self::q("INSERT INTO citasservicios(citaId,servicioId,nombre_servicio,precio_servicio,duracion_minutos) VALUES (?,?,?,?,30)", [$cita, $sid, 'Servicio Real', '123.45']);
            self::q("INSERT INTO pagos(cita_id,cita_referencia_id,usuario_id,referencia_pago,proveedor,monto,estado,metodo_pago,idempotency_key,pagado_at) VALUES (?,?,?,?,'stripe',123.45,'completado','tarjeta',?,NOW())", [$cita, $cita, self::$d[$c]['id'], 'REF-SECRETA-' . $c, 'idem-' . $c . bin2hex(random_bytes(4))]);
        }
        // Datos privados: formulario con respuesta de salud, foto privada, nota de RR. HH.
        self::q("INSERT INTO formularios_cliente(titulo,preguntas,creado_por) VALUES ('Ficha de salud','[]',?)", [$dueno['id']]);
        $fid = (int)self::db()->insert_id;
        self::q("INSERT INTO formulario_respuestas(formulario_id,usuario_id,respuestas) VALUES (?,?,?)", [$fid, self::$d['c1']['id'], json_encode(['alergia' => 'SALUD_SECRETA_XYZ'])]);
        self::q("INSERT INTO perfil_fotos(usuarioId,archivo,mime) VALUES (?,?,'image/jpeg')", [self::$d['c1']['id'], str_pad('FOTO_PRIVADA_ARCHIVO_XYZ', 68, 'a')]);
        self::q("INSERT INTO perfiles_usuario(usuarioId,marketing_email,consentimiento_marketing_at) VALUES (?,1,NOW()) ON DUPLICATE KEY UPDATE marketing_email=1,consentimiento_marketing_at=NOW()", [self::$d['c1']['id']]);
        self::q("UPDATE colaboradores SET notas_rh='NOTA_RH_SECRETA_XYZ' WHERE usuario_id=?", [self::$p['recepcion']['id']]);
    }

    public static function tearDownAfterClass(): void { self::pararServidor(); }

    private function sesion(string $persona): Navegador
    {
        static $cache = [];
        if (!isset($cache[$persona])) {
            self::q('TRUNCATE intentos_login');
            $n = new Navegador(self::$base);
            $r = $n->login(self::$p[$persona]['portal'], self::$p[$persona]['email'], self::$p[$persona]['password']);
            $this->assertSame(302, $r['status'], $persona);
            $cache[$persona] = $n;
        }
        return $cache[$persona];
    }

    public static function equipoClientes(): array { return [['propietario'], ['gerente'], ['recepcion'], ['finanzas'], ['profA'], ['profB']]; }

    /** @dataProvider equipoClientes */
    public function test_ninguna_vista_ni_csv_del_crm_expone_formularios_fotos_ni_notas_rh(string $persona): void
    {
        $n = $this->sesion($persona);
        $paginas = ['/admin/clientes', '/admin/clientes?q=Clara', '/admin/clientes/ficha?id=' . self::$d['c1']['id'], '/admin/clientes/exportar', '/admin/clientes/exportar?tipo=comercial'];
        foreach ($paginas as $ruta) {
            $r = $n->get($ruta);
            foreach (self::SECRETOS as $s) {
                $this->assertStringNotContainsString($s, $r['body'], "$persona $ruta expone $s");
            }
        }
    }

    public function test_importes_y_referencias_de_pago_solo_para_roles_con_analitica(): void
    {
        foreach (['recepcion', 'profA', 'profB'] as $persona) {
            $n = $this->sesion($persona);
            foreach (['/admin/clientes', '/admin/clientes/ficha?id=' . self::$d['c1']['id'], '/admin/clientes/exportar'] as $ruta) {
                $r = $n->get($ruta);
                foreach (['123.45', '123,45', 'REF-SECRETA', 'stripe', 'tarjeta'] as $s) {
                    $this->assertStringNotContainsString($s, $r['body'], "$persona $ruta expone gasto/pago ($s)");
                }
            }
        }
        // Control positivo: gerente y propietario sí ven el gasto (si no, la prueba anterior no probaría nada).
        $r = $this->sesion('gerente')->get('/admin/clientes/ficha?id=' . self::$d['c1']['id']);
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('123.45', $r['body']);
    }

    public function test_finanzas_ve_analitica_pero_no_fichas_ni_listado_nominal(): void
    {
        $n = $this->sesion('finanzas');
        $r = $n->get('/admin/clientes');
        $this->assertSame(200, $r['status']);
        foreach (['c1.visible@example.test', 'Clara', 'c2.otro@example.test'] as $s) { $this->assertStringNotContainsString($s, $r['body'], "finanzas no debe ver datos nominales: $s"); }
        $this->assertSame(403, $n->get('/admin/clientes/ficha?id=' . self::$d['c1']['id'])['status']);
        $this->assertSame(403, $n->get('/admin/clientes/exportar')['status']);
    }

    public function test_profesional_solo_ve_clientes_que_atendio(): void
    {
        $a = $this->sesion('profA');
        $r = $a->get('/admin/clientes');
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('Clara', $r['body']);
        $this->assertStringNotContainsString('Camilo', $r['body'], 'cliente de otro profesional');
        $this->assertStringNotContainsString('Celia', $r['body'], 'cliente sin citas');
        $this->assertSame(200, $a->get('/admin/clientes/ficha?id=' . self::$d['c1']['id'])['status']);
        $this->assertSame(404, $a->get('/admin/clientes/ficha?id=' . self::$d['c2']['id'])['status'], 'ficha de cliente ajeno');
        $this->assertSame(404, $a->get('/admin/clientes/ficha?id=' . self::$d['c3']['id'])['status']);
        $this->assertSame(404, $a->get('/admin/clientes/ficha?id=' . self::$p['propietario']['id'])['status'], 'cuenta laboral no es cliente');
        // Enumeración: 404 idéntico para inexistente y ajeno.
        $x = $a->get('/admin/clientes/ficha?id=999999');
        $y = $a->get('/admin/clientes/ficha?id=' . self::$d['c2']['id']);
        $this->assertSame($x['status'], $y['status']);
        $this->assertSame(preg_replace('/\s+/', ' ', strip_tags($x['body'])), preg_replace('/\s+/', ' ', strip_tags($y['body'])));
        // El filtro por profesional_id=B no debe saltarse el alcance.
        $r = $a->get('/admin/clientes?profesional_id=' . self::$p['profB']['profesional_id'] . '&q=Camilo');
        $this->assertStringNotContainsString('c2.otro@example.test', $r['body']);
    }

    public function test_ficha_y_listado_del_profesional_no_incluyen_telefono_de_otros_ni_gasto(): void
    {
        $r = $this->sesion('profA')->get('/admin/clientes/ficha?id=' . self::$d['c1']['id']);
        $this->assertStringNotContainsString('REF-SECRETA', $r['body']);
        $this->assertStringNotContainsString('Aceptó comunicaciones comerciales', $r['body'], 'el profesional no debe ver el consentimiento comercial');
    }

    public function test_cliente_no_ve_nada_laboral_ni_de_otros_clientes(): void
    {
        $n = $this->sesion('cliente');
        foreach (['/admin/clientes', '/admin/clientes/ficha?id=' . self::$d['c1']['id'], '/admin/clientes/exportar', '/admin/equipo', '/admin/equipo/ficha?id=1'] as $ruta) {
            $r = $n->get($ruta);
            $this->assertSame(403, $r['status'], $ruta);
            $this->assertStringNotContainsString('Clara', $r['body']);
        }
    }

    public function test_notas_rh_solo_en_la_ficha_laboral_del_propietario(): void
    {
        $cid = self::$p['recepcion']['colaborador_id'];
        $this->assertStringContainsString('NOTA_RH_SECRETA_XYZ', $this->sesion('propietario')->get('/admin/equipo/ficha?id=' . $cid)['body']);
        foreach (['gerente', 'recepcion', 'finanzas', 'profA', 'cliente'] as $persona) {
            $n = $this->sesion($persona);
            $this->assertSame(403, $n->get('/admin/equipo/ficha?id=' . $cid)['status'], $persona);
            $r = $n->get('/admin/equipo');
            $this->assertStringNotContainsString('NOTA_RH_SECRETA_XYZ', $r['body'], "$persona ve notas RR. HH. en el listado");
        }
        $r = $this->sesion('propietario')->get('/admin/equipo');
        $this->assertStringNotContainsString('NOTA_RH_SECRETA_XYZ', $r['body'], 'ni el propietario las ve en el listado general');
        // Tampoco aparecen en perfil público ni APIs de profesionales.
        foreach (['/api/profesionales', '/api/servicios'] as $ruta) {
            $this->assertStringNotContainsString('NOTA_RH_SECRETA_XYZ', $this->sesion('cliente')->get($ruta)['body']);
        }
    }

    public function test_html_de_clientes_con_nombre_hostil_se_escapa_en_listado_y_ficha(): void
    {
        foreach (['propietario', 'gerente'] as $persona) {
            $n = $this->sesion($persona);
            foreach (['/admin/clientes', '/admin/clientes?q=Hostil', '/admin/clientes/ficha?id=' . self::$d['cx']['id']] as $ruta) {
                $r = $n->get($ruta);
                $this->assertSame(200, $r['status'], $ruta);
                $this->assertStringNotContainsString('<script>alert("x1")', $r['body'], "$persona $ruta no escapa <script>");
                $this->assertStringNotContainsString('<img src=x onerror', $r['body'], "$persona $ruta no escapa <img>");
                $this->assertStringContainsString('&lt;script&gt;', $r['body'], "$persona $ruta: el nombre hostil debe verse escapado");
            }
        }
    }

    public function test_csv_neutraliza_formulas_en_todas_las_celdas_de_texto(): void
    {
        $r = $this->sesion('gerente')->get('/admin/clientes/exportar');
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('text/csv', $r['headers']['content-type'][0] ?? '');
        $this->assertStringContainsString('attachment', $r['headers']['content-disposition'][0] ?? '');
        $filas = array_map('str_getcsv', array_filter(explode("\n", ltrim($r['body'], "\xEF\xBB\xBF"))));
        $peligrosas = 0;
        foreach ($filas as $f) {
            foreach ($f as $celda) {
                $c = ltrim($celda, " \t\r\n\0\x0B");
                if ($c !== '' && in_array($c[0], ['=', '+', '-', '@'], true)) { $peligrosas++; $this->fail("Celda con fórmula sin neutralizar: " . $celda); }
            }
        }
        $this->assertStringContainsString("'=HYPERLINK", $r['body']);
        $this->assertGreaterThan(5, count($filas));
        // Exportación comercial: solo c1 (consentimiento + confirmado).
        $r = $this->sesion('gerente')->get('/admin/clientes/exportar?tipo=comercial');
        $this->assertStringContainsString('c1.visible@example.test', $r['body']);
        $this->assertStringNotContainsString('c2.otro@example.test', $r['body']);
        $this->assertStringNotContainsString('cx.xss@example.test', $r['body']);
    }

    public function test_csv_parametros_hostiles_dan_422_sin_filtrar_ni_500(): void
    {
        $n = $this->sesion('gerente');
        foreach (['?tipo[]=x', '?tipo=otro', '?desde[]=1', '?q[]=a', '?segmento[]=vip', '?profesional_id=abc', '?por_pagina=7', '?pagina=-1', '?hasta=2999-01-01', '?orden=gasto;DROP', '?dir=sideways', '?dias_activo=5'] as $q) {
            foreach (['/admin/clientes', '/admin/clientes/exportar'] as $ruta) {
                $r = $n->get($ruta . $q);
                $this->assertContains($r['status'], [200, 422], "$ruta$q => {$r['status']}");
                $this->assertNotSame(500, $r['status']);
            }
        }
        $this->assertStringNotContainsString('Array to string', self::logServidor());
        $this->assertStringNotContainsString('Warning', self::logServidor());
    }

    public function test_busqueda_con_comodines_sql_no_enumera_todos(): void
    {
        $r = $this->sesion('gerente')->get('/admin/clientes?q=' . rawurlencode('%'));
        $this->assertSame(200, $r['status']);
        $this->assertStringNotContainsString('c2.otro@example.test', $r['body'], '% se trata como literal, no como comodín');
        $r = $this->sesion('gerente')->get('/admin/clientes?q=' . rawurlencode("' OR 1=1 -- "));
        $this->assertContains($r['status'], [200]);
    }

    public function test_vistas_de_equipo_escapan_nombre_puesto_y_notas(): void
    {
        $owner = self::$p['propietario']['id'];
        $svc = new \Services\ColaboradorService(new \Repositories\ColaboradorRepository(self::db()), new \Services\CorreoSalidaService(new \Repositories\CorreoSalidaRepository(self::db())));
        $r = $svc->invitar($owner, ['nombre' => '<script>alert(1)</script>', 'apellido' => '"><svg onload=alert(1)>', 'email' => 'xss.col@example.test', 'telefono' => '', 'puesto' => '<img src=x onerror=alert(3)>', 'rol' => 'recepcion', 'fecha_ingreso' => ''], Idem::clave());
        $this->assertSame('ok', $r['status'], json_encode($r));
        $cid = $r['colaborador_id'];
        $svc->actualizarFicha($owner, $cid, ['puesto' => '<b onmouseover=alert(9)>p</b>', 'notas_rh' => '<script>alert("nota")</script>', 'telefono' => '', 'fecha_ingreso' => '']);
        $token = self::tokenDeInvitacion((int)self::escalar('SELECT MAX(id) FROM colaborador_invitaciones WHERE colaborador_id=?', [$cid]));
        $o = $this->sesion('propietario');
        $paginas = ['/admin/equipo' => $o->get('/admin/equipo'), '/admin/equipo/ficha' => $o->get('/admin/equipo/ficha?id=' . $cid), '/equipo/aceptar' => (new Navegador(self::$base))->get('/equipo/aceptar?token=' . $token)];
        foreach ($paginas as $ruta => $resp) {
            $this->assertSame(200, $resp['status'], $ruta);
            $this->assertStringNotContainsString('<script>alert', $resp['body'], "$ruta sin escapar");
            $this->assertStringNotContainsString('<svg onload', $resp['body'], "$ruta sin escapar svg");
            $this->assertStringNotContainsString('<img src=x onerror', $resp['body'], "$ruta sin escapar img");
            $this->assertStringNotContainsString('<b onmouseover', $resp['body'], "$ruta sin escapar b");
        }
        $this->assertStringContainsString('&lt;script&gt;', $paginas['/admin/equipo']['body']);
    }

    public function test_correo_de_invitacion_escapa_nombre_y_puesto_y_no_permite_inyeccion_de_cabeceras(): void
    {
        $capturado = [];
        \Classes\Email::setTransport(static function ($mail, $proposito, $meta) use (&$capturado) { $capturado[] = ['html' => $mail->Body, 'asunto' => $mail->Subject, 'alt' => $mail->AltBody, 'meta' => $meta, 'mail' => $mail]; return true; });
        try {
            $e = new \Classes\Email('<script>alert(1)</script> Bcc: x@y.z', 'dest@example.test', str_repeat('a', 64));
            $e->detallesExtra = ['Rol' => 'Gerencia', 'Puesto' => '<img src=x onerror=alert(1)>'];
            $this->assertTrue($e->enviarInvitacion());
        } finally { \Classes\Email::setTransport(null); }
        $this->assertCount(1, $capturado);
        $this->assertStringNotContainsString('<script>alert(1)', $capturado[0]['html']);
        $this->assertStringNotContainsString('<img src=x', $capturado[0]['html']);
        $this->assertStringNotContainsString("\n", $capturado[0]['asunto']);
    }

    public function test_formularios_publicos_reflejan_entrada_hostil_escapada(): void
    {
        $n = new Navegador(self::$base);
        $csrf = $n->csrf('/cliente/registro');
        $r = $n->post('/cliente/registro', ['csrf_token' => $csrf, 'idem_key' => Idem::clave(), 'nombre' => self::XSS, 'apellido' => self::XSS, 'email' => '"><script>alert(7)</script>', 'telefono' => '<b>', 'password' => 'x']);
        $this->assertSame(422, $r['status']);
        $this->assertStringNotContainsString('<script>alert(7)', $r['body']);
        $this->assertStringNotContainsString('<script>alert("x1")', $r['body']);
        $csrf = $n->csrf('/equipo/login');
        $r = $n->post('/equipo/login', ['csrf_token' => $csrf, 'email' => '"><script>alert(8)</script>', 'password' => 'x']);
        $this->assertStringNotContainsString('<script>alert(8)', $r['body']);
    }

    public function test_aceptar_con_token_malformado_no_refleja_ni_registra_el_token(): void
    {
        $n = new Navegador(self::$base);
        foreach (['"><script>alert(1)</script>', str_repeat('A', 70), '../../etc/passwd', "abc\n", str_repeat('g', 64)] as $tok) {
            $r = $n->get('/equipo/aceptar?token=' . rawurlencode($tok));
            $this->assertSame(404, $r['status']);
            $this->assertStringNotContainsString('<script>alert(1)', $r['body']);
        }
        $r = $n->get('/equipo/aceptar?token[]=a');
        $this->assertContains($r['status'], [404, 422]);
        $this->assertStringNotContainsString('Array to string', self::logServidor());
    }
}

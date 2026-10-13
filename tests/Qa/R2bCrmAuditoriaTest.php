<?php

namespace Tests\Qa;

require_once __DIR__ . '/R2bCase.php';

/**
 * Ronda 2B, ítem 5c: auditoría de exportaciones del CRM (`crm_exportaciones_auditoria`): éxito / denegada / fallida, atomicidad
 * (si no se puede registrar, no sale ni un byte), sin PII en la fila, solo anexado, fórmulas CSV, filtros maliciosos y volumen.
 * HTTP real (8150-8199).
 */
final class R2bCrmAuditoriaTest extends R2bCase
{
    private static string $base;
    private static array $p = [];
    /** @var array<string,Navegador> */
    private static array $nav = [];
    private static array $clientes = [];

    public static function setUpBeforeClass(): void
    {
        self::limpiar();
        self::$base = self::servidor([], 'r2b_crm');
        $d = self::propietario(self::correo('dueno'));
        self::$p['dueno'] = $d + ['portal' => 'equipo'];
        foreach (['gerente', 'recepcion', 'finanzas', 'profesional'] as $rol) { self::$p[$rol] = self::miembro($d['id'], $rol) + ['portal' => 'equipo']; }
        self::$p['cliente'] = self::cliente(['email' => self::correo('cli-sesion')]) + ['portal' => 'cliente'];
        // Clientes con datos reconocibles (PII) y nombres hostiles para el CSV.
        self::$clientes['normal'] = self::cliente(['email' => 'privado.uno@pii-r2b.test', 'nombre' => 'Privada', 'apellido' => 'Uno', 'telefono' => '0987654321']);
        foreach (['=HYPERLINK("http://evil/x","clic")', '+1+1', '-2+3', '@SUM(A1:A9)', "\t=cmd", "\r=cmd", "\tsolotab", "\rsolocr", ' =espacio', "=1\n+2"] as $i => $nom) {
            self::$clientes["f$i"] = self::cliente(['email' => "formula$i@pii-r2b.test", 'nombre' => $nom, 'apellido' => 'Apellido' . $i, 'telefono' => '09' . str_pad((string)$i, 8, '1')]);
            // El alta recorta los espacios iniciales: el valor hostil (tabulador/retorno al inicio) se fija por SQL, como lo dejaría un dato heredado o importado.
            self::q('UPDATE usuarios SET nombre=? WHERE id=?', [$nom, self::$clientes["f$i"]['id']]);
        }
    }

    public static function tearDownAfterClass(): void { self::pararServidor('r2b_crm'); }

    protected function setUp(): void { self::q('TRUNCATE intentos_login'); $this->quitarTrigger(); }

    protected function tearDown(): void { $this->quitarTrigger(); }

    private function quitarTrigger(): void { self::db()->query('DROP TRIGGER IF EXISTS trg_qa_audit_falla'); }

    private function nav(string $quien): Navegador
    {
        if (!isset(self::$nav[$quien])) {
            self::q('TRUNCATE intentos_login');
            $n = new Navegador(self::$base);
            $this->assertSame(302, $n->login(self::$p[$quien]['portal'], self::$p[$quien]['email'], self::$p[$quien]['password'])['status'], $quien);
            self::$nav[$quien] = $n;
        }
        return self::$nav[$quien];
    }

    private function filas(): array { return self::q('SELECT * FROM crm_exportaciones_auditoria ORDER BY id'); }

    private function fallarAuditoria(): void
    {
        self::db()->query("CREATE TRIGGER trg_qa_audit_falla BEFORE INSERT ON crm_exportaciones_auditoria FOR EACH ROW SIGNAL SQLSTATE '45001' SET MESSAGE_TEXT='auditoria rota a proposito'");
    }

    /** Todas las celdas de un CSV (sin BOM). @return string[][] */
    private static function csv(string $cuerpo): array
    {
        $cuerpo = preg_replace('/^\xEF\xBB\xBF/', '', $cuerpo);
        $fh = fopen('php://temp', 'r+'); fwrite($fh, $cuerpo); rewind($fh);
        $o = [];
        while (($f = fgetcsv($fh, 0, ',', '"', '')) !== false) { $o[] = $f; }
        fclose($fh);
        return $o;
    }

    // ------------------------------------------------------------------ éxito

    public function test_exportacion_exitosa_deja_una_fila_que_coincide_con_lo_entregado_y_no_contiene_datos_personales(): void
    {
        self::q('TRUNCATE crm_exportaciones_auditoria');
        $r = $this->nav('dueno')->get('/admin/clientes/exportar?tipo=clientes&q=privada&segmento=nuevo');
        $this->assertSame(200, $r['status'], substr($r['body'], 0, 300));
        $f = $this->filas();
        $this->assertCount(1, $f);
        $x = $f[0];
        $this->assertSame('exitosa', $x['resultado']);
        $this->assertSame('clientes', $x['tipo']);
        $this->assertSame('csv', $x['formato']);
        $this->assertSame((int)self::$p['dueno']['id'], (int)$x['actor_usuario_id']);
        $this->assertSame('propietario', $x['actor_rol']);
        $this->assertSame(hash('sha256', $r['body']), $x['contenido_sha256'], 'el hash debe ser el del archivo entregado byte a byte');
        $csv = self::csv($r['body']);
        $this->assertSame(count($csv) - 1, (int)$x['filas'], 'filas auditadas = filas de datos entregadas');
        $this->assertSame($csv[0], json_decode($x['columnas_lista'], true));
        $this->assertSame(count($csv[0]), (int)$x['columnas_n']);
        $this->assertSame(0, (int)$x['truncado']);
        $this->assertSame('informativo', $x['consentimiento_politica']);
        $this->assertNotNull($x['consentimiento_version_id']);
        // Sin PII en NINGUNA columna de la fila: ni correos, ni teléfonos, ni nombres, ni la búsqueda en claro.
        $todo = json_encode($x, JSON_UNESCAPED_UNICODE);
        foreach (['pii-r2b.test', '0987654321', 'Privada', 'privada', 'Apellido0', 'Apellido5', self::$p['dueno']['email'], 'HYPERLINK'] as $pii) {
            $this->assertStringNotContainsStringIgnoringCase($pii, $todo, "la fila de auditoría contiene «{$pii}»");
        }
        $fil = json_decode($x['filtros_json'], true);
        $this->assertTrue($fil['q_presente']);
        $this->assertSame(7, $fil['q_longitud']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $fil['q_hmac']);
        $this->assertArrayNotHasKey('q', $fil);
        // La IP no se guarda en claro (HMAC de 64 hex) y es estable por origen.
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $x['ip_hash']);
        $this->assertStringNotContainsString('127.0.0.1', $todo);
        // Lista comercial: política «solo versión vigente».
        $c = $this->nav('gerente')->get('/admin/clientes/exportar?tipo=comercial');
        $this->assertSame(200, $c['status']);
        $ult = $this->filas()[1];
        $this->assertSame('comercial', $ult['tipo']);
        $this->assertSame('solo_version_vigente', $ult['consentimiento_politica']);
        $this->assertSame('gerente', $ult['actor_rol']);
        $this->assertSame(hash('sha256', $c['body']), $ult['contenido_sha256']);
    }

    public function test_el_rol_registrado_es_el_del_servidor_no_el_que_declare_el_navegador(): void
    {
        self::q('TRUNCATE crm_exportaciones_auditoria');
        $n = $this->nav('recepcion');
        $r = $n->get('/admin/clientes/exportar?tipo=clientes&rol=propietario&actor_rol=propietario', ['X-Actor-Rol: propietario', 'X-Forwarded-For: 10.9.9.9']);
        $this->assertSame(403, $r['status']);
        $f = $this->filas();
        $this->assertCount(1, $f);
        $this->assertSame('recepcion', $f[0]['actor_rol']);
        $this->assertSame('denegada', $f[0]['resultado']);
        $this->assertSame('sin_permiso', $f[0]['motivo']);
        $this->assertNull($f[0]['contenido_sha256']);
        $this->assertNull($f[0]['filas']);
    }

    // ------------------------------------------------------------------ denegada / fallida / anónimo

    public function test_denegadas_por_rol_quedan_registradas_y_los_anonimos_o_sesiones_caducadas_no(): void
    {
        self::q('TRUNCATE crm_exportaciones_auditoria');
        foreach (['recepcion' => 'recepcion', 'finanzas' => 'finanzas', 'profesional' => 'profesional', 'cliente' => 'cliente'] as $quien => $rol) {
            $antes = count($this->filas());
            $r = $this->nav($quien)->get('/admin/clientes/exportar');
            $this->assertSame(403, $r['status'], $quien);
            $this->assertStringNotContainsString('pii-r2b.test', $r['body']);
            $f = $this->filas();
            $this->assertCount($antes + 1, $f, "$quien: un intento denegado debe quedar registrado");
            $this->assertSame($rol, end($f)['actor_rol']);
            $this->assertSame('denegada', end($f)['resultado']);
        }
        $antes = count($this->filas());
        $this->assertSame(302, (new Navegador(self::$base))->get('/admin/clientes/exportar')['status']);
        $this->assertSame(401, (new Navegador(self::$base))->get('/admin/clientes/exportar', ['Accept: application/json'])['status']);
        $this->assertCount($antes, $this->filas(), 'un anónimo no debe dejar filas (vía de inundación)');
    }

    public function test_filtros_invalidos_y_fallos_tecnicos_se_registran_como_fallidos(): void
    {
        self::q('TRUNCATE crm_exportaciones_auditoria');
        $n = $this->nav('dueno');
        foreach (['?tipo=otro', '?tipo[]=x', '?desde=2999-01-01', '?por_pagina=7', '?segmento[]=vip', '?orden=gasto;DROP', '?pagina=-1', '?q[]=a'] as $q) {
            $antes = count($this->filas());
            $r = $n->get('/admin/clientes/exportar' . $q);
            $this->assertSame(422, $r['status'], $q);
            $f = $this->filas();
            $this->assertCount($antes + 1, $f, $q);
            $this->assertSame('fallida', end($f)['resultado'], $q);
            $this->assertSame('filtros_invalidos', end($f)['motivo'], $q);
            $this->assertStringNotContainsString('pii-r2b.test', json_encode(end($f)));
        }
        $tipos = array_column($this->filas(), 'tipo');
        $this->assertContains('invalido', $tipos);
        // Fallo técnico de datos: 503 + fila «fallida / error_datos».
        self::db()->query('RENAME TABLE perfiles_usuario TO perfiles_usuario_qa_tmp');
        try {
            $antes = count($this->filas());
            $r = $n->get('/admin/clientes/exportar?tipo=clientes');
            $this->assertSame(503, $r['status']);
            $this->assertStringNotContainsString('pii-r2b.test', $r['body']);
        } finally {
            self::db()->query('RENAME TABLE perfiles_usuario_qa_tmp TO perfiles_usuario');
        }
        $f = $this->filas();
        $this->assertCount($antes + 1, $f);
        $this->assertSame('fallida', end($f)['resultado']);
        $this->assertSame('error_datos', end($f)['motivo']);
    }

    // ------------------------------------------------------------------ atomicidad

    public function test_si_la_auditoria_falla_no_sale_ni_un_byte_del_archivo(): void
    {
        $this->fallarAuditoria();
        $n = $this->nav('dueno');
        foreach (['clientes', 'comercial'] as $tipo) {
            $r = $n->get('/admin/clientes/exportar?tipo=' . $tipo);
            $this->assertSame(503, $r['status'], $tipo);
            $this->assertStringNotContainsString('pii-r2b.test', $r['body'], 'el archivo salió a pesar de no poder auditarlo');
            $this->assertStringNotContainsString('Privada', $r['body']);
            $this->assertStringNotContainsString('attachment', $r['headers']['content-disposition'][0] ?? '', 'cabecera de descarga con la auditoría rota');
            $this->assertStringNotContainsString('csv', strtolower($r['headers']['content-type'][0] ?? ''));
        }
        // Un intento DENEGADO con la auditoría rota sigue siendo 403 (documentado) y no revela nada.
        $d = $this->nav('recepcion')->get('/admin/clientes/exportar');
        $this->assertSame(403, $d['status']);
        // La exportación de campañas (/admin/crecimiento) usa el mismo registro: con la auditoría rota tampoco entrega el archivo.
        $csrf = Navegador::csrfDe($n->get('/admin/crecimiento')['body']);
        $c = $n->post('/admin/crecimiento', ['csrf_token' => $csrf, 'accion' => 'segmento', 'segmento' => 'todos']);
        $this->assertContains($c['status'], [500, 503], 'la campaña salió sin auditoría: ' . $c['status']);
        $this->assertStringNotContainsString('pii-r2b.test', $c['body']);
        $this->quitarTrigger();
        $this->assertSame(200, $n->get('/admin/clientes/exportar?tipo=clientes')['status'], 'recuperada la auditoría, la exportación vuelve a funcionar');
    }

    // ------------------------------------------------------------------ solo anexado

    public function test_la_base_rechaza_update_y_delete_de_las_cuatro_tablas_y_truncate_es_un_limite_documentado(): void
    {
        $this->nav('dueno')->get('/admin/clientes/exportar?tipo=clientes');
        $db = self::db();
        $tablas = ['crm_exportaciones_auditoria', 'altas_homonimos_decisiones', 'consentimientos_historial', 'consentimiento_versiones', 'profesionales_homonimos_decisiones'];
        foreach ($tablas as $t) {
            if ((int)self::escalar("SELECT COUNT(*) FROM `$t`") === 0 && $t !== 'consentimiento_versiones') { continue; }
            $cols = array_column(self::q("SHOW COLUMNS FROM `$t`"), 'Field');
            $col = $cols[1];
            mysqli_report(MYSQLI_REPORT_OFF);
            $this->assertFalse($db->query("UPDATE `$t` SET `$col`=`$col`"), "UPDATE permitido sobre $t");
            $this->assertSame(1644, $db->errno, "$t: UPDATE sin trigger de inmutabilidad");
            $this->assertFalse($db->query("DELETE FROM `$t` LIMIT 1"), "DELETE permitido sobre $t");
            $this->assertSame(1644, $db->errno, "$t: DELETE sin trigger");
            $this->assertFalse($db->query("REPLACE INTO `$t` SELECT * FROM `$t` LIMIT 1"), "REPLACE permitido sobre $t");
        }
        // Límite documentado: TRUNCATE no activa triggers (la aplicación no lo ejecuta; se mitiga revocando privilegios).
        $db->query('CREATE TABLE qa_trunc_demo LIKE crm_exportaciones_auditoria');
        $this->assertSame([], self::q('SHOW TRIGGERS LIKE "qa_trunc_demo"'));
        $db->query('DROP TABLE qa_trunc_demo');
        $this->assertStringContainsString('TRUNCATE', (string)file_get_contents(self::RAIZ . '/docs/CRM_CLIENTES.md'), 'el límite TRUNCATE debe seguir documentado');
    }

    public function test_el_codigo_de_la_aplicacion_no_tiene_ninguna_sentencia_que_modifique_las_tablas_de_solo_anexado(): void
    {
        $tablas = 'crm_exportaciones_auditoria|altas_homonimos_decisiones|consentimientos_historial|consentimiento_versiones|profesionales_homonimos_decisiones';
        $hallazgos = [];
        foreach (['controllers', 'services', 'repositories', 'classes', 'models', 'scripts', 'includes', 'views', 'public'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::RAIZ . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (!str_ends_with($f->getFilename(), '.php')) { continue; }
                $txt = (string)file_get_contents($f->getPathname());
                if (preg_match_all('/(UPDATE|DELETE\s+FROM|TRUNCATE(?:\s+TABLE)?|REPLACE\s+INTO|DROP\s+TABLE(?:\s+IF\s+EXISTS)?|ALTER\s+TABLE|INSERT\s+IGNORE\s+INTO|ON\s+DUPLICATE\s+KEY\s+UPDATE)\s+`?(' . $tablas . ')\b/i', $txt, $m, PREG_SET_ORDER)) {
                    foreach ($m as $x) { $hallazgos[] = basename($f->getPathname()) . ': ' . trim($x[0]); }
                }
                // Variante: INSERT ... ON DUPLICATE KEY UPDATE sobre estas tablas.
                if (preg_match('/INSERT\s+INTO\s+`?(' . $tablas . ')\b[^;]{0,600}?ON\s+DUPLICATE\s+KEY\s+UPDATE/is', $txt, $x)) { $hallazgos[] = basename($f->getPathname()) . ': ' . substr(trim($x[0]), 0, 80); }
            }
        }
        $this->assertSame([], $hallazgos, 'la aplicación puede modificar tablas de solo anexado: ' . implode('; ', $hallazgos));
    }

    public function test_borrar_al_actor_no_borra_su_rastro_de_exportaciones_pero_la_cascada_de_usuarios_si_borra_el_historial_de_consentimiento_limite_documentado(): void
    {
        self::q('TRUNCATE crm_exportaciones_auditoria');
        $efimero = self::miembro(self::$p['dueno']['id'], 'gerente');
        $n = new Navegador(self::$base);
        $this->assertSame(302, $n->login('equipo', $efimero['email'], $efimero['password'])['status']);
        $this->assertSame(200, $n->get('/admin/clientes/exportar?tipo=clientes')['status']);
        $this->assertSame(1, count($this->filas()));
        self::q('DELETE FROM usuarios WHERE id=?', [$efimero['id']]);
        $f = $this->filas();
        $this->assertCount(1, $f, 'borrar la cuenta del actor eliminó su rastro de auditoría');
        $this->assertSame((int)$efimero['id'], (int)$f[0]['actor_usuario_id']);
        // Documentado: el historial de consentimiento SÍ cae en cascada con el usuario (supresión de datos personales).
        $cli = self::cliente(['email' => self::correo('cascada')]);
        (new \Repositories\ConsentimientoRepository(self::db()))->aplicarDecision($cli['id'], true, null, ['origen' => 'perfil', 'canal' => 'portal_cliente', 'actor_id' => $cli['id']]);
        $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM consentimientos_historial WHERE usuario_id=?', [$cli['id']]));
        self::q('DELETE FROM usuarios WHERE id=?', [$cli['id']]);
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM consentimientos_historial WHERE usuario_id=?', [$cli['id']]));
    }

    // ------------------------------------------------------------------ fórmulas CSV

    public function test_ninguna_celda_exportada_empieza_con_un_caracter_de_formula(): void
    {
        $n = $this->nav('dueno');
        foreach (['clientes', 'comercial'] as $tipo) {
            if ($tipo === 'comercial') {
                // La lista comercial solo trae consentimiento vigente: se concede a los clientes con nombre hostil.
                foreach (self::$clientes as $c) { self::q("INSERT INTO perfiles_usuario(usuarioId,marketing_email,consentimiento_marketing_at,consentimiento_marketing_version_id) VALUES (?,1,NOW(),NULL) ON DUPLICATE KEY UPDATE marketing_email=1, consentimiento_marketing_at=NOW()", [$c['id']]); }
            }
            $r = $n->get('/admin/clientes/exportar?tipo=' . $tipo);
            $this->assertSame(200, $r['status']);
            $malas = [];
            foreach (self::csv($r['body']) as $i => $fila) {
                foreach ($fila as $celda) {
                    if ($celda !== '' && preg_match('/^[=+\-@\t\r]/', $celda) && !preg_match('/^[+\-]?\d+([.,]\d+)?$/', $celda)) { $malas[] = "fila $i: " . json_encode($celda, JSON_UNESCAPED_UNICODE); }
                }
            }
            $this->assertSame([], $malas, "$tipo: celdas que Excel/Calc ejecutarían como fórmula");
            $this->assertStringContainsString("'=HYPERLINK", $r['body'], 'la celda hostil debe aparecer neutralizada con comilla simple');
        }
    }

    /** R2B-07 REVERIFICADO (antes rojo): el CSV de campañas usa `ReporteService::sanearCeldaCsv` (tabulador y retorno iniciales incluidos). */
    public function test_R2B_07_campana_de_clientes_y_fidelidad_neutraliza_tabulador_y_retorno_de_carro_iniciales(): void
    {
        $n = $this->nav('dueno');
        foreach (self::$clientes as $c) { self::q("INSERT INTO perfiles_usuario(usuarioId,marketing_email,consentimiento_marketing_at,consentimiento_marketing_version_id) VALUES (?,1,NOW(),NULL) ON DUPLICATE KEY UPDATE marketing_email=1, consentimiento_marketing_at=NOW()", [$c['id']]); }
        $csrf = Navegador::csrfDe($n->get('/admin/crecimiento')['body']);
        $r = $n->post('/admin/crecimiento', ['csrf_token' => $csrf, 'accion' => 'segmento', 'segmento' => 'todos']);
        $this->assertSame(200, $r['status'], substr($r['body'], 0, 200));
        $malas = [];
        $csvC = self::csv($r['body']);
        $this->assertGreaterThanOrEqual(count(self::$clientes), count($csvC) - 1, 'la campaña no incluyó a los clientes con consentimiento: ' . substr($r['body'], 0, 300));
        foreach ($csvC as $i => $fila) {
            foreach ($fila as $celda) {
                if ($celda !== '' && preg_match('/^[=+\-@\t\r]/', $celda) && !preg_match('/^[+\-]?\d+([.,]\d+)?$/', $celda)) { $malas[] = "fila $i: " . json_encode($celda, JSON_UNESCAPED_UNICODE); }
            }
        }
        $this->assertSame([], $malas, 'la exportación de campañas deja celdas que empiezan por tabulador/retorno/fórmula sin neutralizar (OWASP CSV injection): ' . implode('; ', $malas));
    }

    // ------------------------------------------------------------------ filtros maliciosos

    public function test_filtros_maliciosos_no_inyectan_ni_se_guardan_en_claro_y_las_tablas_siguen_intactas(): void
    {
        self::q('TRUNCATE crm_exportaciones_auditoria');
        $n = $this->nav('dueno');
        $busquedas = ["' OR 1=1 -- ", '%', '_', "'; DROP TABLE usuarios; --", '<script>alert(1)</script>', "ñandú\u{202E}", str_repeat('a', 80), "x\x00y", '${jndi:ldap://x}', '\\', '" onfocus="alert(1)'];
        foreach ($busquedas as $q) {
            $r = $n->get('/admin/clientes/exportar?tipo=clientes&q=' . rawurlencode($q));
            $this->assertContains($r['status'], [200, 422], json_encode($q));
            $this->assertNotSame(500, $r['status']);
        }
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='usuarios'"));
        $json = '';
        foreach ($this->filas() as $f) { $json .= json_encode($f, JSON_UNESCAPED_UNICODE); }
        foreach (['DROP TABLE', 'OR 1=1', '<script>', 'jndi', 'onfocus', 'ñandú'] as $x) { $this->assertStringNotContainsString($x, $json, "texto de búsqueda en claro en la auditoría: $x"); }
        // Búsqueda de 81 caracteres: 422 (límite del CRM) y queda como fallida sin el texto.
        $r = $n->get('/admin/clientes/exportar?q=' . str_repeat('b', 81));
        $this->assertSame(422, $r['status']);
        $this->assertStringNotContainsString(str_repeat('b', 81), json_encode(array_slice($this->filas(), -1)[0]));
    }

    // ------------------------------------------------------------------ vista de consulta

    public function test_vista_de_auditoria_permisos_paginacion_y_escape_de_contenido_hostil(): void
    {
        self::q('TRUNCATE crm_exportaciones_auditoria');
        for ($i = 0; $i < 30; $i++) { self::q("INSERT INTO crm_exportaciones_auditoria(ocurrido_en,actor_usuario_id,actor_rol,formato,tipo,resultado,motivo) VALUES (UTC_TIMESTAMP(),?,?,'csv','clientes','denegada',?)", [self::$p['dueno']['id'], 'rol<script>', 'm<img src=x onerror=alert(1)>']); }
        foreach (['dueno', 'gerente'] as $q) {
            $r = $this->nav($q)->get('/admin/clientes/auditoria');
            $this->assertSame(200, $r['status'], $q);
            $this->assertStringNotContainsString('<script>', $r['body']);
            $this->assertStringNotContainsString('<img src=x', $r['body']);
        }
        foreach (['recepcion', 'finanzas', 'profesional', 'cliente'] as $q) {
            $r = $this->nav($q)->get('/admin/clientes/auditoria');
            $this->assertSame(403, $r['status'], $q);
            $this->assertStringNotContainsString('denegada', strip_tags($r['body']));
        }
        $this->assertSame(302, (new Navegador(self::$base))->get('/admin/clientes/auditoria')['status']);
        $n = $this->nav('dueno');
        $this->assertSame(200, $n->get('/admin/clientes/auditoria?por_pagina=25&pagina=2')['status']);
        $ultima = $n->get('/admin/clientes/auditoria?pagina=999999');
        $this->assertContains($ultima['status'], [200, 422]);
        foreach (['?por_pagina=7', '?pagina=-1', '?pagina=abc', '?resultado=otro', '?tipo[]=x', '?actor_id=abc', '?desde=2020-13-45', '?desde=2030-01-02&hasta=2030-01-01', '?pagina[]=1'] as $q) {
            $r = $n->get('/admin/clientes/auditoria' . $q);
            $this->assertContains($r['status'], [200, 422], $q);
            $this->assertNotSame(500, $r['status'], $q);
        }
        // La vista es de solo lectura: ningún método distinto de GET la modifica.
        $csrf = Navegador::csrfDe($n->get('/admin/clientes/auditoria')['body'] . $n->get('/admin/clientes/nuevo')['body']);
        $this->assertSame(405, $n->post('/admin/clientes/auditoria', ['csrf_token' => $csrf ?? ''])['status']);
        $this->assertSame(30, (int)self::escalar('SELECT COUNT(*) FROM crm_exportaciones_auditoria'));
    }

    /**
     * R2B-06 REVERIFICADO (antes `test_Defecto_R2B_06_…`, rojo). Las denegaciones idénticas (mismo actor, tipo y motivo) dentro de 10 minutos
     * se agrupan en UNA fila; la PRIMERA sí se registra y conserva su evidencia (id, marca de tiempo, IP hash) aunque se repita 60 veces.
     */
    public function test_R2B_06_las_denegaciones_repetidas_del_mismo_actor_se_agrupan_y_la_primera_evidencia_se_conserva(): void
    {
        self::q('TRUNCATE crm_exportaciones_auditoria');
        $n = $this->nav('cliente');
        $n->get('/admin/clientes/exportar?tipo=clientes&i=0');
        $primera = $this->filas();
        $this->assertCount(1, $primera, 'la primera denegación SÍ debe registrarse');
        $this->assertSame('denegada', $primera[0]['resultado']);
        $this->assertSame('sin_permiso', $primera[0]['motivo']);
        $this->assertSame((int)self::$p['cliente']['id'], (int)$primera[0]['actor_usuario_id']);
        for ($i = 1; $i < 60; $i++) { $n->get('/admin/clientes/exportar?tipo=clientes&i=' . $i); }
        $despues = $this->filas();
        $this->assertCount(1, $despues, '60 peticiones denegadas idénticas deben dejar UNA sola fila');
        $this->assertSame($primera[0], $despues[0], 'la primera evidencia no debe alterarse ni reemplazarse');
        // Variantes que NO deben burlar la agrupación para inflar la tabla: parámetros distintos, método HEAD, mayúsculas en tipo, tipo repetido.
        foreach (['/admin/clientes/exportar', '/admin/clientes/exportar?tipo=clientes&q=' . str_repeat('x', 50), '/admin/clientes/exportar?tipo=clientes&desde=2020-01-01&hasta=2020-02-01', '/admin/clientes/exportar?tipo=clientes&tipo=clientes', '/admin/clientes/exportar?TIPO=clientes'] as $u) { $n->get($u); }
        $this->assertCount(1, $this->filas(), 'variaciones de la URL con el mismo actor/tipo/motivo siguen agrupadas');
    }

    public function test_R2B_06_un_actor_distinto_o_un_tipo_distinto_si_registran_su_propia_fila(): void
    {
        self::q('TRUNCATE crm_exportaciones_auditoria');
        foreach (['cliente', 'recepcion', 'finanzas', 'profesional'] as $quien) {
            $n = $this->nav($quien);
            $n->get('/admin/clientes/exportar?tipo=clientes');
            $n->get('/admin/clientes/exportar?tipo=clientes');
        }
        $f = $this->filas();
        $this->assertCount(4, $f, 'una fila por actor distinto (4 actores × 2 peticiones)');
        $this->assertCount(4, array_unique(array_column($f, 'actor_usuario_id')));
        // El mismo actor con otro TIPO de exportación (comercial / no válido) es otra denegación distinta.
        $n = $this->nav('cliente');
        $n->get('/admin/clientes/exportar?tipo=comercial');
        $n->get('/admin/clientes/exportar?tipo=comercial');
        $n->get('/admin/clientes/exportar?tipo=otra-cosa');
        $n->get('/admin/clientes/exportar?tipo[]=x');
        $n->get('/admin/clientes/exportar?tipo=otra-cosa');
        $porTipo = array_column(self::q("SELECT tipo, COUNT(*) n FROM crm_exportaciones_auditoria WHERE actor_usuario_id=? GROUP BY tipo", [self::$p['cliente']['id']]), 'n', 'tipo');
        ksort($porTipo);
        $this->assertSame(['clientes' => 1, 'comercial' => 1, 'invalido' => 1], array_map('intval', $porTipo), 'una fila por (actor, tipo, motivo): ' . json_encode($porTipo));
    }

    public function test_R2B_06_la_ventana_de_agrupacion_es_de_diez_minutos_exactos_y_el_exito_nunca_se_agrupa(): void
    {
        self::q('TRUNCATE crm_exportaciones_auditoria');
        $uid = (int)self::$p['cliente']['id'];
        $insertar = function (int $minAtras) use ($uid): void {
            self::q("INSERT INTO crm_exportaciones_auditoria(actor_usuario_id,actor_rol,formato,tipo,resultado,motivo,ocurrido_en) VALUES (?,?, 'csv','clientes','denegada','sin_permiso', DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND))", [$uid, 'cliente', $minAtras]);
        };
        $n = $this->nav('cliente');
        // Una denegación previa de hace 11 minutos queda FUERA de la ventana: la nueva se registra.
        $insertar(11 * 60);
        $n->get('/admin/clientes/exportar?tipo=clientes');
        $this->assertCount(2, $this->filas(), 'fuera de la ventana (11 min) debe registrarse otra');
        // Con una de hace 9 minutos y 30 s dentro de la ventana, no se añade (la recién creada ya cubre, así que se limpia antes).
        self::q('TRUNCATE crm_exportaciones_auditoria');
        $insertar(9 * 60 + 30);
        $n->get('/admin/clientes/exportar?tipo=clientes');
        $this->assertCount(1, $this->filas(), 'dentro de la ventana (9 min 30 s) no debe añadirse fila');
        // Una denegación previa de OTRO motivo o de otro resultado (fallida) no agrupa a la nueva.
        self::q('TRUNCATE crm_exportaciones_auditoria');
        self::q("INSERT INTO crm_exportaciones_auditoria(actor_usuario_id,actor_rol,formato,tipo,resultado,motivo,ocurrido_en) VALUES (?, 'cliente','csv','clientes','denegada','sin_permiso_ver', UTC_TIMESTAMP())", [$uid]);
        self::q("INSERT INTO crm_exportaciones_auditoria(actor_usuario_id,actor_rol,formato,tipo,resultado,motivo,ocurrido_en) VALUES (?, 'cliente','csv','clientes','fallida','sin_permiso', UTC_TIMESTAMP())", [$uid]);
        $n->get('/admin/clientes/exportar?tipo=clientes');
        $this->assertCount(3, $this->filas(), 'otro motivo u otro resultado no agrupan');
        // Un éxito del propietario NUNCA se agrupa: cada exportación entregada deja su fila.
        self::q('TRUNCATE crm_exportaciones_auditoria');
        $d = $this->nav('dueno');
        for ($i = 0; $i < 5; $i++) { $this->assertSame(200, $d->get('/admin/clientes/exportar?tipo=clientes')['status']); }
        $this->assertSame(5, (int)self::escalar("SELECT COUNT(*) FROM crm_exportaciones_auditoria WHERE resultado='exitosa'"), 'cada exportación entregada debe auditarse');
    }

    /**
     * R2B-06b (RESIDUO, rojo). La comprobación «¿ya hay una denegación reciente?» (`hayDenegadaReciente`) y la inserción NO son atómicas. Con ráfagas SIMULTÁNEAS del mismo actor (sesiones
     * distintas para no serializar por el bloqueo de sesión) puede entrar más de una fila antes de que la primera sea visible. Se mide el
     * tamaño de la ráfaga que se cuela y se acota: aunque no sea exactamente 1, no debe crecer con la repetición (la ráfaga siguiente,
     * ya dentro de la ventana, no añade nada).
     */
    public function test_Defecto_R2B_06b_una_rafaga_simultanea_del_mismo_actor_deja_varias_filas_pese_a_la_agrupacion(): void
    {
        if (!function_exists('curl_multi_init')) { $this->fail('curl_multi no disponible'); }
        $url = self::servidor(['PHP_CLI_SERVER_WORKERS' => '12'], 'r2b_crm_conc');
        self::q('TRUNCATE crm_exportaciones_auditoria');
        $cookies = [];
        for ($i = 0; $i < 16; $i++) {
            self::q('TRUNCATE intentos_login');
            $n = new Navegador($url);
            $this->assertSame(302, $n->login('cliente', self::$p['cliente']['email'], self::$p['cliente']['password'])['status']);
            $cookies[] = $n->cookieSesion();
        }
        $rafaga = function () use ($cookies, $url): void {
            $mh = curl_multi_init(); $hs = [];
            foreach ($cookies as $i => $c) {
                $h = curl_init($url . '/admin/clientes/exportar?tipo=clientes&r=' . $i);
                curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => 'PHPSESSID=' . $c, CURLOPT_TIMEOUT => 30]);
                curl_multi_add_handle($mh, $h); $hs[] = $h;
            }
            do { curl_multi_exec($mh, $act); curl_multi_select($mh, 0.1); } while ($act > 0);
            foreach ($hs as $h) { curl_multi_remove_handle($mh, $h); curl_close($h); }
            curl_multi_close($mh);
        };
        $rafaga();
        $primera = (int)self::escalar("SELECT COUNT(*) FROM crm_exportaciones_auditoria WHERE actor_usuario_id=?", [self::$p['cliente']['id']]);
        $rafaga();
        $segunda = (int)self::escalar("SELECT COUNT(*) FROM crm_exportaciones_auditoria WHERE actor_usuario_id=?", [self::$p['cliente']['id']]);
        $this->assertSame($primera, $segunda, 'la segunda ráfaga, dentro de la ventana, no debe añadir filas');
        $this->assertLessThan(16, $primera, "una ráfaga de 16 peticiones simultáneas dejó $primera filas: la agrupación no resiste la concurrencia");
        $this->assertSame(1, $primera, "ráfaga simultánea de 16 denegaciones idénticas: $primera filas (la comprobación y la inserción no son atómicas)");
    }

    // ------------------------------------------------------------------ volumen

    public function test_volumen_diez_mil_filas_trunca_audita_el_truncado_y_no_agota_el_tiempo(): void
    {
        $db = self::db();
        $db->query('SET SESSION cte_max_recursion_depth=20000');
        $db->query("INSERT INTO usuarios(nombre,apellido,email,password,telefono,admin,confirmado) SELECT CONCAT('Vol',n),'Masivo',CONCAT('vol',n,'@vol-r2b.test'),'x','0991234567',0,1 FROM (WITH RECURSIVE s(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM s WHERE n<10100) SELECT n FROM s) t");
        $db->query("INSERT INTO clientes(usuario_id,estado,origen) SELECT id,'activo','migracion' FROM usuarios WHERE email LIKE '%@vol-r2b.test'");
        try {
            self::q('TRUNCATE crm_exportaciones_auditoria');
            $n = $this->nav('dueno'); $n->timeout = 120;
            $t0 = microtime(true);
            $r = $n->get('/admin/clientes/exportar?tipo=clientes');
            $seg = microtime(true) - $t0;
            $this->assertSame(200, $r['status']);
            $this->assertLessThan(60.0, $seg, 'la exportación masiva tardó demasiado');
            $csv = self::csv($r['body']);
            $f = $this->filas();
            $this->assertCount(1, $f);
            $this->assertSame(10000, (int)$f[0]['filas'], 'se exportan como máximo 10.000 filas de datos');
            $this->assertSame(1, (int)$f[0]['truncado']);
            $this->assertStringContainsString('AVISO: exportación limitada', end($csv)[0], 'falta el aviso de truncado al final del archivo');
            $this->assertSame(hash('sha256', $r['body']), $f[0]['contenido_sha256']);
        } finally {
            $db->query("DELETE FROM usuarios WHERE email LIKE '%@vol-r2b.test'");
        }
    }
}

<?php

namespace Tests\Qa;

require_once __DIR__ . '/R2bCase.php';

use Repositories\ConsentimientoRepository;

/**
 * Ronda 2B, ítem 5d (parte 1): versión del consentimiento comercial. Se guarda la versión MOSTRADA, un consentimiento previo no sube
 * de versión sin reconfirmación, retirar siempre funciona, el equipo no lo toca y el CRM/campañas solo tratan como vigente lo vigente.
 * HTTP real (8150-8199) y procesos concurrentes reales.
 */
final class R2bConsentimientoTest extends R2bCase
{
    private static string $base;
    private static array $dueno;
    private static array $recepcion;

    public static function setUpBeforeClass(): void
    {
        self::limpiar();
        self::$base = self::servidor([], 'r2b_consent');
        self::$dueno = self::propietario(self::correo('dueno'));
        self::$recepcion = self::miembro(self::$dueno['id'], 'recepcion');
    }

    public static function tearDownAfterClass(): void { self::pararServidor('r2b_consent'); }

    protected function setUp(): void { self::q('TRUNCATE intentos_login'); }

    private function repo(): ConsentimientoRepository { return new ConsentimientoRepository(self::db()); }

    /** Publica una versión nueva (texto único) y la devuelve. */
    private function nuevaVersion(?string $desde = null): array
    {
        return $this->repo()->publicar('Acepto recibir promociones (texto de prueba ' . bin2hex(random_bytes(4)) . ')', $desde);
    }

    private function nuevoCliente(string $pref = 'c'): array
    {
        return self::cliente(['email' => self::correo($pref)]);
    }

    private function navDe(array $c, string $portal = 'cliente'): Navegador
    {
        self::q('TRUNCATE intentos_login');
        $n = new Navegador(self::$base);
        $this->assertSame(302, $n->login($portal, $c['email'], $c['password'])['status']);
        return $n;
    }

    /** Envía el formulario de /perfil tal como lo haría el navegador y luego aplica las diferencias (null = quitar el campo). */
    private function guardarPerfil(Navegador $n, array $dif = [], bool $conVersionDeLaPagina = true): array
    {
        $pag = $n->get('/perfil');
        $this->assertSame(200, $pag['status']);
        $csrf = Navegador::csrfDe($pag['body']);
        $base = ['csrf_token' => $csrf, 'nombre' => 'Cli', 'apellido' => 'Ente', 'telefono' => '0991234567', 'contacto_email' => '1', 'recordatorios' => '1'];
        if ($conVersionDeLaPagina) { $base['consentimiento_version'] = Navegador::campoDe($pag['body'], 'consentimiento_version'); }
        foreach ($dif as $k => $v) { if ($v === null) { unset($base[$k]); } else { $base[$k] = $v; } }
        return $n->post('/perfil', $base);
    }

    private function estado(int $uid): array
    {
        return self::q('SELECT marketing_email, consentimiento_marketing_at, consentimiento_marketing_version_id v FROM perfiles_usuario WHERE usuarioId=?', [$uid])[0] ?? ['marketing_email' => 0, 'consentimiento_marketing_at' => null, 'v' => null];
    }

    private function historial(int $uid): array
    {
        return self::q('SELECT valor, version_id, version_informada, origen, canal, actor_id FROM consentimientos_historial WHERE usuario_id=? ORDER BY id', [$uid]);
    }

    // ------------------------------------------------------------------ versión mostrada, no la última

    public function test_se_guarda_la_version_mostrada_aunque_se_haya_publicado_otra_mientras_tanto(): void
    {
        $c = $this->nuevoCliente();
        $n = $this->navDe($c);
        $pag = $n->get('/perfil');
        $mostrada = (int)Navegador::campoDe($pag['body'], 'consentimiento_version');
        $this->assertSame((int)$this->repo()->vigente()['id'], $mostrada);
        $nueva = $this->nuevaVersion();                       // se publica mientras la persona tiene el formulario abierto
        $r = $n->post('/perfil', ['csrf_token' => Navegador::csrfDe($pag['body']), 'nombre' => 'Cli', 'apellido' => 'Ente', 'telefono' => '0991234567', 'contacto_email' => '1', 'recordatorios' => '1', 'marketing_email' => '1', 'consentimiento_version' => (string)$mostrada]);
        $this->assertSame(200, $r['status'], implode('|', array_slice(explode("\n", strip_tags($r['body'])), 0, 3)));
        $this->assertSame($mostrada, (int)$this->estado($c['id'])['v'], 'se guardó una versión distinta de la que la persona vio');
        $h = $this->historial($c['id']);
        $this->assertCount(1, $h);
        $this->assertSame(1, (int)$h[0]['valor']);
        $this->assertSame($mostrada, (int)$h[0]['version_id']);
        $this->assertSame(1, (int)$h[0]['version_informada']);
        $this->assertSame((int)$c['id'], (int)$h[0]['actor_id']);
        $this->assertNotSame((int)$nueva['id'], (int)$h[0]['version_id']);
        // Y ahora el perfil le avisa que es una versión anterior y ofrece reconfirmar.
        $p = $n->get('/perfil');
        $this->assertStringContainsString('versión anterior', $p['body']);
        $this->assertStringContainsString('reconfirmar_consentimiento', $p['body']);
    }

    public function test_un_consentimiento_previo_no_sube_de_version_solo_ni_con_un_formulario_viejo(): void
    {
        $c = $this->nuevoCliente();
        $n = $this->navDe($c);
        $v1 = (int)$this->repo()->vigente()['id'];
        $this->assertSame(200, $this->guardarPerfil($n, ['marketing_email' => '1'])['status']);
        $v2 = $this->nuevaVersion();
        // Guardar el perfil con la casilla ya marcada y el formulario nuevo NO reconfirma.
        $this->assertSame(200, $this->guardarPerfil($n, ['marketing_email' => '1'])['status']);
        $this->assertSame($v1, (int)$this->estado($c['id'])['v'], 'el consentimiento se «mejoró» a la versión nueva sin reconfirmación');
        $this->assertCount(1, $this->historial($c['id']), 'se anexó una fila sin cambio real');
        // Reconfirmar con la versión NUEVA sí actualiza y lo deja anotado.
        $this->assertSame(200, $this->guardarPerfil($n, ['marketing_email' => '1', 'reconfirmar_consentimiento' => '1', 'consentimiento_version' => (string)$v2['id']])['status']);
        $this->assertSame((int)$v2['id'], (int)$this->estado($c['id'])['v']);
        $h = $this->historial($c['id']);
        $this->assertCount(2, $h);
        $this->assertSame((int)$v2['id'], (int)$h[1]['version_id']);
        // Un formulario VIEJO (versión anterior) con «reconfirmar» no hace retroceder ni cambia nada.
        $this->assertSame(200, $this->guardarPerfil($n, ['marketing_email' => '1', 'reconfirmar_consentimiento' => '1', 'consentimiento_version' => (string)$v1])['status']);
        $this->assertSame((int)$v2['id'], (int)$this->estado($c['id'])['v'], 'un formulario viejo hizo retroceder el consentimiento');
        $this->assertCount(2, $this->historial($c['id']));
    }

    public function test_una_version_invalida_al_aceptar_da_422_y_no_guarda_nada_del_perfil(): void
    {
        $c = $this->nuevoCliente();
        $n = $this->navDe($c);
        $otroProposito = (int)(function () { self::q("INSERT INTO consentimiento_versiones(proposito,version,texto,texto_hash,vigente_desde,es_historica,creado_en) VALUES ('otro_proposito',1,'otro',?,UTC_TIMESTAMP(),0,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE id=id", [hash('sha256', 'otro')]); return (int)self::escalar("SELECT id FROM consentimiento_versiones WHERE proposito='otro_proposito' AND version=1"); })();
        $futura = $this->nuevaVersion(gmdate('Y-m-d H:i:s', time() + 7200));
        foreach (['abc' => 'abc', 'cero' => '0', 'negativo' => '-1', 'inexistente' => '99999999', 'decimal' => '1.5', 'arreglo' => ['1'], 'otro propósito' => (string)$otroProposito, 'versión futura' => (string)$futura['id']] as $caso => $v) {
            $r = $this->guardarPerfil($n, ['marketing_email' => '1', 'nombre' => 'Cambiado ' . bin2hex(random_bytes(2)), 'consentimiento_version' => $v]);
            $this->assertSame(422, $r['status'], "«{$caso}»");
            $this->assertSame('Cli', self::escalar('SELECT nombre FROM usuarios WHERE id=?', [$c['id']]), "«{$caso}»: se guardó el nombre pese al error (la transacción no es única)");
            $this->assertSame(0, (int)$this->estado($c['id'])['marketing_email'], "«{$caso}»");
            $this->assertSame([], $this->historial($c['id']));
        }
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM consentimiento_versiones WHERE id=?", [$futura['id']]));
        $this->assertNotSame((int)$futura['id'], (int)$this->repo()->vigente()['id'], 'una versión con vigencia futura no puede ser la vigente');
    }

    public function test_sin_campo_de_version_se_asume_la_vigente_y_se_marca_como_no_informada(): void
    {
        $c = $this->nuevoCliente();
        $n = $this->navDe($c);
        $this->assertSame(200, $this->guardarPerfil($n, ['marketing_email' => '1'], false)['status']);
        $h = $this->historial($c['id']);
        $this->assertCount(1, $h);
        $this->assertSame(0, (int)$h[0]['version_informada']);
        $this->assertSame((int)$this->repo()->vigente()['id'], (int)$h[0]['version_id']);
    }

    // ------------------------------------------------------------------ retirar siempre funciona

    public function test_retirar_el_consentimiento_funciona_siempre_aunque_la_version_enviada_sea_basura(): void
    {
        foreach (['basura' => 'abc', 'inexistente' => '99999999', 'negativa' => '-4', 'arreglo' => ['x'], 'ausente' => null, 'vacia' => ''] as $caso => $v) {
            $c = $this->nuevoCliente();
            $n = $this->navDe($c);
            $this->assertSame(200, $this->guardarPerfil($n, ['marketing_email' => '1'])['status']);
            $this->assertSame(1, (int)$this->estado($c['id'])['marketing_email'], $caso);
            $r = $this->guardarPerfil($n, ['marketing_email' => null, 'consentimiento_version' => $v]);
            $this->assertSame(200, $r['status'], "retirar con versión «{$caso}» falló");
            $e = $this->estado($c['id']);
            $this->assertSame(0, (int)$e['marketing_email'], $caso);
            $this->assertNull($e['consentimiento_marketing_at']);
            $this->assertNull($e['v']);
            $h = $this->historial($c['id']);
            $this->assertCount(2, $h, $caso);
            $this->assertSame(0, (int)$h[1]['valor']);
        }
        // Retirar cuando nunca se aceptó no escribe nada.
        $c = $this->nuevoCliente();
        $n = $this->navDe($c);
        $this->assertSame(200, $this->guardarPerfil($n, ['marketing_email' => null])['status']);
        $this->assertSame([], $this->historial($c['id']));
    }

    // ------------------------------------------------------------------ el equipo no toca el consentimiento

    public function test_el_portal_del_equipo_no_otorga_ni_retira_ni_corrige_el_consentimiento_de_nadie(): void
    {
        $c = $this->nuevoCliente();
        $nc = $this->navDe($c);
        $this->assertSame(200, $this->guardarPerfil($nc, ['marketing_email' => '1'])['status']);
        $antes = [$this->estado($c['id']), $this->historial($c['id'])];
        $eq = $this->navDe(self::$recepcion, 'equipo');
        $pag = $eq->get('/perfil?id=' . $c['id']);
        $this->assertSame(200, $pag['status']);
        foreach ([['marketing_email' => '1'], ['marketing_email' => '0'], ['marketing_email' => null]] as $dif) {
            $campos = ['csrf_token' => Navegador::csrfDe($pag['body']), 'nombre' => 'Editado', 'apellido' => 'PorRecepcion', 'telefono' => '0991234567', 'contacto_email' => '1', 'recordatorios' => '1', 'consentimiento_version' => (string)$this->repo()->vigente()['id']];
            foreach ($dif as $k => $v) { if ($v !== null) { $campos[$k] = $v; } }
            $r = $eq->post('/perfil?id=' . $c['id'], $campos);
            $this->assertContains($r['status'], [200, 422]);
            $this->assertSame($antes, [$this->estado($c['id']), $this->historial($c['id'])], 'el portal del equipo alteró el consentimiento comercial del cliente');
        }
        // Quien aún no consintió: el equipo no puede otorgarlo en su nombre.
        $s = $this->nuevoCliente();
        $pag2 = $eq->get('/perfil?id=' . $s['id']);
        $eq->post('/perfil?id=' . $s['id'], ['csrf_token' => Navegador::csrfDe($pag2['body']), 'nombre' => 'Sin', 'apellido' => 'Consent', 'telefono' => '0991234567', 'contacto_email' => '1', 'recordatorios' => '1', 'marketing_email' => '1', 'consentimiento_version' => (string)$this->repo()->vigente()['id']]);
        $this->assertSame(0, (int)$this->estado($s['id'])['marketing_email'], 'recepción otorgó consentimiento comercial a un cliente');
        $this->assertSame([], $this->historial($s['id']));
        // El propio personal, desde SU portal, tampoco guarda consentimiento en su perfil laboral.
        $eqPag = $eq->get('/perfil');
        $eq->post('/perfil', ['csrf_token' => Navegador::csrfDe($eqPag['body']), 'nombre' => 'Rec', 'apellido' => 'Ep', 'telefono' => '0991234567', 'marketing_email' => '1']);
        $this->assertSame([], $this->historial(self::$recepcion['id']));
    }

    // ------------------------------------------------------------------ Mi bienestar

    public function test_mi_bienestar_aplica_la_misma_semantica_de_version(): void
    {
        $c = $this->nuevoCliente();
        $n = $this->navDe($c);
        $pag = $n->get('/mi-bienestar');
        $this->assertSame(200, $pag['status']);
        $mostrada = (int)Navegador::campoDe($pag['body'], 'consentimiento_version');
        $this->assertSame((int)$this->repo()->vigente()['id'], $mostrada);
        $v2 = $this->nuevaVersion();
        $csrf = Navegador::csrfDe($pag['body']);
        $this->assertSame(200, $n->post('/mi-bienestar', ['csrf_token' => $csrf, 'accion' => 'consentimiento', 'aceptado' => '1', 'consentimiento_version' => (string)$mostrada])['status']);
        $this->assertSame($mostrada, (int)$this->estado($c['id'])['v'], 'Mi bienestar guardó la última y no la versión mostrada');
        $this->assertSame('bienestar', $this->historial($c['id'])[0]['origen']);
        $csrf = Navegador::csrfDe($n->get('/mi-bienestar')['body']);
        $n->post('/mi-bienestar', ['csrf_token' => $csrf, 'accion' => 'consentimiento', 'aceptado' => '1', 'consentimiento_version' => (string)$v2['id']]);
        $this->assertSame($mostrada, (int)$this->estado($c['id'])['v'], 'sin reconfirmación no sube de versión');
        $n->post('/mi-bienestar', ['csrf_token' => $csrf, 'accion' => 'consentimiento', 'aceptado' => '1', 'reconfirmar_consentimiento' => '1', 'consentimiento_version' => (string)$v2['id']]);
        $this->assertSame((int)$v2['id'], (int)$this->estado($c['id'])['v']);
        $n->post('/mi-bienestar', ['csrf_token' => $csrf, 'accion' => 'consentimiento', 'consentimiento_version' => 'basura']);
        $this->assertSame(0, (int)$this->estado($c['id'])['marketing_email'], 'retirar desde Mi bienestar debe funcionar siempre');
    }

    // ------------------------------------------------------------------ CRM y campañas

    public function test_crm_distingue_version_anterior_y_las_listas_comerciales_solo_incluyen_la_vigente(): void
    {
        $vigenteAntes = $this->repo()->vigente();
        $v1 = $vigenteAntes;
        $viejo = $this->nuevoCliente('anterior');   // aceptó v(n)
        $nuevo = $this->nuevoCliente('vigente');    // aceptará v(n+1)
        $sin = $this->nuevoCliente('sin');
        $legado = $this->nuevoCliente('legado');    // consentimiento válido SIN versión (retro-llenado = versión 1 histórica)
        $sinFecha = $this->nuevoCliente('sinfecha'); // marca sin fecha: no es consentimiento válido
        $nv = $this->nuevaVersion();
        $this->repo()->aplicarDecision($viejo['id'], true, (string)$v1['id'], ['origen' => 'perfil', 'canal' => 'portal_cliente', 'actor_id' => $viejo['id']]);
        $this->repo()->aplicarDecision($nuevo['id'], true, (string)$nv['id'], ['origen' => 'perfil', 'canal' => 'portal_cliente', 'actor_id' => $nuevo['id']]);
        self::q("INSERT INTO perfiles_usuario(usuarioId,marketing_email,consentimiento_marketing_at,consentimiento_marketing_version_id) VALUES (?,1,NOW(),NULL)", [$legado['id']]);
        self::q("INSERT INTO perfiles_usuario(usuarioId,marketing_email,consentimiento_marketing_at,consentimiento_marketing_version_id) VALUES (?,1,NULL,NULL)", [$sinFecha['id']]);
        $n = $this->navDe(self::$dueno, 'equipo');
        $correos = fn(string $cuerpo) => array_values(array_filter([$viejo, $nuevo, $sin, $legado, $sinFecha], fn($c) => str_contains($cuerpo, $c['email'])));
        $ids = fn(array $lista) => array_map(fn($c) => $c['email'], $lista);
        $esperado = [
            'vigente' => [$nuevo['email']],
            'anterior' => [$viejo['email'], $legado['email']],
            'si' => [$legado['email'], $nuevo['email'], $viejo['email']],
        ];
        foreach ($esperado as $filtro => $emails) {
            $r = $n->get('/admin/clientes?por_pagina=100&consentimiento=' . $filtro . '');
            $this->assertSame(200, $r['status'], $filtro);
            $encontrados = $ids($correos($r['body']));
            sort($encontrados); $e = $emails; sort($e);
            $this->assertSame($e, $encontrados, "filtro consentimiento=$filtro");
        }
        $no = $n->get('/admin/clientes?por_pagina=100&consentimiento=no');
        $enNo = $ids($correos($no['body'])); sort($enNo);
        $this->assertSame([$sin['email'], $sinFecha['email']], array_values(array_intersect([$sin['email'], $sinFecha['email']], $enNo)), 'sin consentimiento válido deben salir «no»');
        // Ficha: la persona con versión anterior lo dice.
        $f = $n->get('/admin/clientes/ficha?id=' . $viejo['id']);
        $this->assertStringContainsString('ersión anterior', $f['body']);
        // Lista comercial y campañas: solo la vigente, jamás las de versión anterior, las legadas ni las marcas sin fecha.
        $com = $n->get('/admin/clientes/exportar?tipo=comercial');
        $this->assertSame(200, $com['status']);
        $this->assertStringContainsString($nuevo['email'], $com['body']);
        foreach ([$viejo, $legado, $sin, $sinFecha] as $x) { $this->assertStringNotContainsString($x['email'], $com['body'], 'lista comercial con quien no tiene consentimiento vigente: ' . $x['email']); }
        $csrf = Navegador::csrfDe($n->get('/admin/crecimiento')['body']);
        $camp = $n->post('/admin/crecimiento', ['csrf_token' => $csrf, 'accion' => 'segmento', 'segmento' => 'todos']);
        $this->assertSame(200, $camp['status']);
        $this->assertStringContainsString($nuevo['email'], $camp['body']);
        foreach ([$viejo, $legado, $sin, $sinFecha] as $x) { $this->assertStringNotContainsString($x['email'], $camp['body'], 'campaña con quien no tiene consentimiento vigente: ' . $x['email']); }
        // Exportación operativa: informativa, con las columnas de versión.
        $op = $n->get('/admin/clientes/exportar?tipo=clientes');
        $this->assertStringContainsString('Versión del consentimiento', $op['body']);
        // Quitar el consentimiento excluye de inmediato.
        $this->repo()->aplicarDecision($nuevo['id'], false, null, ['origen' => 'perfil', 'canal' => 'portal_cliente', 'actor_id' => $nuevo['id']]);
        $com = $n->get('/admin/clientes/exportar?tipo=comercial');
        $this->assertStringNotContainsString($nuevo['email'], $com['body']);
    }

    // ------------------------------------------------------------------ versiones: publicación e inmutabilidad

    public function test_publicar_versiones_numeracion_vigencia_futura_inmutabilidad_y_textos_hostiles(): void
    {
        $repo = $this->repo();
        $a = $this->nuevaVersion();
        $b = $this->nuevaVersion();
        $this->assertSame((int)$a['version'] + 1, (int)$b['version']);
        $this->assertSame((int)$b['id'], (int)$repo->vigente()['id']);
        foreach (['', '   ', str_repeat('x', 2001)] as $malo) { try { $repo->publicar($malo); $this->fail('texto inválido aceptado'); } catch (\InvalidArgumentException) { $this->assertTrue(true); } }
        try { $repo->publicar($b['texto']); $this->fail('texto idéntico aceptado'); } catch (\InvalidArgumentException) { $this->assertTrue(true); }
        // Observación: una fecha con formato correcto pero imposible llega a MySQL y sale como PersistenceException (no como validación); no se inserta nada.
        $antesV = (int)self::escalar('SELECT COUNT(*) FROM consentimiento_versiones');
        try { $repo->publicar('otro texto distinto ' . bin2hex(random_bytes(3)), '2030-13-45 99:00:00'); $this->fail('fecha inválida aceptada'); } catch (\InvalidArgumentException | \Repositories\PersistenceException) { $this->assertTrue(true); }
        $this->assertSame($antesV, (int)self::escalar('SELECT COUNT(*) FROM consentimiento_versiones'));
        // Vigencia futura: existe pero no es la vigente, y no se puede «aceptar» por id.
        $f = $repo->publicar('Texto futuro ' . bin2hex(random_bytes(3)), gmdate('Y-m-d H:i:s', time() + 3600));
        $this->assertSame((int)$b['id'], (int)$repo->vigente()['id']);
        try { $repo->resolverMostrada((string)$f['id']); $this->fail('versión futura aceptada'); } catch (\InvalidArgumentException) { $this->assertTrue(true); }
        // Inmutabilidad en la base y consentimientos previos intactos.
        $c = $this->nuevoCliente();
        $repo->aplicarDecision($c['id'], true, (string)$a['id'], ['origen' => 'perfil', 'canal' => 'portal_cliente', 'actor_id' => $c['id']]);
        $repo->publicar('Otra más ' . bin2hex(random_bytes(3)));
        $this->assertSame((int)$a['id'], (int)$this->estado($c['id'])['v'], 'publicar una versión nueva tocó un consentimiento previo');
        mysqli_report(MYSQLI_REPORT_OFF);
        $db = self::db();
        $this->assertFalse($db->query("UPDATE consentimiento_versiones SET texto='manipulado' WHERE id=" . (int)$a['id']), 'UPDATE permitido sobre una versión publicada');
        $this->assertSame(1644, $db->errno);
        $this->assertFalse($db->query('DELETE FROM consentimiento_versiones WHERE id=' . (int)$a['id']));
        $this->assertSame(1644, $db->errno);
        // El texto se muestra escapado.
        $hostil = $repo->publicar('<script>alert("x")</script> "><img src=x onerror=alert(2)> ' . bin2hex(random_bytes(2)));
        $cli = self::cliente(['email' => self::correo('xss')]);
        $n = $this->navDe($cli);
        $p = $n->get('/perfil');
        $this->assertStringNotContainsString('<script>alert("x")', $p['body']);
        $this->assertStringNotContainsString('<img src=x onerror', $p['body']);
        $this->assertStringContainsString('&lt;script&gt;', $p['body']);
        $this->assertSame((int)$hostil['id'], (int)Navegador::campoDe($p['body'], 'consentimiento_version'));
    }

    // ------------------------------------------------------------------ concurrencia

    public function test_aceptar_y_retirar_en_paralelo_deja_perfil_e_historial_coherentes(): void
    {
        $c = $this->nuevoCliente();
        $vig = (int)$this->repo()->vigente()['id'];
        $t = [];
        for ($i = 0; $i < 8; $i++) { $t[] = ['accion' => 'consentimiento', 'args' => ['usuario' => $c['id'], 'acepta' => $i % 2 === 0, 'version' => (string)$vig]]; }
        $res = self::concurrente($t, 1.2);
        foreach ($res as $x) { $this->assertTrue($x['ok'] ?? false, json_encode($x)); }
        $h = $this->historial($c['id']);
        $this->assertNotEmpty($h);
        // Solo hay fila cuando hay cambio real: nunca dos valores iguales seguidos.
        for ($i = 1; $i < count($h); $i++) { $this->assertNotSame($h[$i - 1]['valor'], $h[$i]['valor'], 'dos decisiones iguales seguidas en el historial: ' . json_encode($h)); }
        $e = $this->estado($c['id']);
        $this->assertSame((int)end($h)['valor'], (int)$e['marketing_email'], 'el perfil contradice la última fila del historial');
        $this->assertSame((int)end($h)['valor'] === 1 ? $vig : null, $e['v'] === null ? null : (int)$e['v']);
        $this->assertSame((int)end($h)['valor'] === 1, $e['consentimiento_marketing_at'] !== null);
    }
}

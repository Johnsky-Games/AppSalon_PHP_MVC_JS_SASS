<?php

namespace Tests\Qa;

require_once __DIR__ . '/R5Case.php';

/**
 * R5-QA · CRM / segmentación (matriz 5 del usuario). La verdad ("oráculo") se calcula con SQL sobre datos sembrados de forma determinista;
 * los filtros se descubren en el formulario real de /admin/clientes (nombre de control + etiqueta de cada opción), sin asumir nombres internos.
 *
 * K1 filtros del contrato presentes · K2 cada filtro y sus combinaciones = oráculo SQL · K3 lista = conteo/indicadores = exportación (mismo universo) ·
 * K4 alcance por rol · K5 «Limpiar filtros», chips, conteo, paginación · K6 entradas hostiles · K7 «Listado» de segmentos · K8 sin datos inventados de
 * suscripciones de pago · K9 comunicaciones comerciales respetan el consentimiento.
 */
final class R5QaCrmTest extends R5Case
{
    private static string $base;
    private static array $e;
    /** @var array<string,int> etiqueta de combinación ⇒ id de usuario */
    private static array $cli = [];
    private static array $meta = [];

    public static function setUpBeforeClass(): void
    {
        self::limpiar();
        self::$base = self::servidorQ5('crm');
        self::$e = self::escenario();
        $repo = new \Repositories\ConsentimientoRepository(self::db());
        $n = 0;
        foreach (['activo', 'suspendido'] as $estado) {
            foreach ([true, false] as $conf) {
                foreach (['otorgado', 'revocado', 'sin'] as $cons) {
                    $n++;
                    $c = self::cliente(['email' => "crm$n." . bin2hex(random_bytes(3)) . '@example.test', 'nombre' => "Filtro$n", 'apellido' => 'Combo', 'telefono' => sprintf('099%07d', $n), 'confirmado' => $conf, 'estado' => $estado]);
                    if ($cons !== 'sin') { $repo->aplicarDecision($c['id'], true, null, ['origen' => 'perfil', 'canal' => 'portal_cliente', 'actor_id' => $c['id']]); }
                    if ($cons === 'revocado') { $repo->aplicarDecision($c['id'], false, null, ['origen' => 'perfil', 'canal' => 'portal_cliente', 'actor_id' => $c['id']]); }
                    self::$cli["$estado/" . ($conf ? 'conf' : 'sinconf') . "/$cons"] = $c['id'];
                    self::$meta[$c['id']] = ['email' => $c['email'], 'estado' => $estado, 'conf' => $conf, 'cons' => $cons, 'nombre' => "Filtro$n Combo"];
                }
            }
        }
        // Visitas: reciente (hace 10 días, ProA), antigua (hace 200 días) y una cita cancelada (no es visita).
        $reciente = self::$cli['activo/conf/otorgado'];
        $antigua = self::$cli['activo/conf/sin'];
        $cancel = self::$cli['activo/sinconf/sin'];
        self::crearCita(['cliente' => $reciente, 'pro' => self::$e['proA']['profesional_id'], 'fecha' => self::gye('-10 days')->format('Y-m-d'), 'hora' => '10:00:00', 'estado' => 'completada', 'pago' => 'pagado']);
        self::crearCita(['cliente' => $antigua, 'pro' => self::$e['proB']['profesional_id'], 'fecha' => self::gye('-200 days')->format('Y-m-d'), 'hora' => '10:00:00', 'estado' => 'completada']);
        self::crearCita(['cliente' => $cancel, 'pro' => self::$e['proA']['profesional_id'], 'fecha' => self::gye('-5 days')->format('Y-m-d'), 'hora' => '10:00:00', 'estado' => 'cancelada']);
        // Relleno para paginar (todos activos, confirmados, sin decisión).
        for ($i = 1; $i <= 28; $i++) {
            $c = self::cliente(['email' => "relleno$i." . bin2hex(random_bytes(3)) . '@example.test', 'nombre' => sprintf('Relleno%02d', $i), 'apellido' => 'Masa', 'telefono' => sprintf('098%07d', $i)]);
            self::$meta[$c['id']] = ['email' => $c['email'], 'estado' => 'activo', 'conf' => true, 'cons' => 'sin', 'nombre' => sprintf('Relleno%02d Masa', $i)];
        }
    }

    public static function tearDownAfterClass(): void { self::pararServidorQ5('crm'); }

    protected function setUp(): void { self::q('TRUNCATE intentos_login'); }

    // ------------------------------------------------------------------ ayudas

    private function eq(string $rol): Navegador { return self::entrar(self::$base, self::$e[$rol]); }

    /** Controles de filtro de /admin/clientes: lógico ⇒ [campo, [clave lógica ⇒ valor]]. */
    private function controles(Navegador $n): array
    {
        $r = $n->get('/admin/clientes');
        $this->assertSame(200, $r['status'], 'GET /admin/clientes → ' . $r['status']);
        $mapa = [];
        $defs = [
            'membresia' => ['/membres|estado_cliente|estado$/i', ['activo' => '/^(activ)/iu', 'suspendido' => '/^(suspend)/iu']],
            'cuenta' => ['/confirm|cuenta/i', ['sin' => '/^(sin confirm|no confirm|pendiente)/iu', 'conf' => '/^(confirmad)/iu']],
            'consent' => ['/consent|suscrip|comunic|marketing/i', ['otorgado' => '/^(otorg|acept|suscrit)/iu', 'revocado' => '/^(revoc|retir|dio de baja)/iu', 'sin' => '/^(sin decisi|sin respuesta|nunca decid)/iu']],
            'actividad' => ['/activid|visita|recien|inactiv/i', ['reciente' => '/^(recient)/iu', 'inactivo' => '/^(inactiv)/iu', 'nunca' => '/^(nunca)/iu']],
        ];
        foreach (self::formularios($r['body']) as $f) {
            if (!preg_match('~^/admin/clientes/?(\?.*)?$~', $f['action'])) { continue; }
            foreach ($defs as $logico => [$re, $opts]) {
                if (isset($mapa[$logico])) { continue; }
                foreach ($f['etiquetas'] as $campo => $etiquetas) {
                    if (!preg_match($re, $campo)) { continue; }
                    $m = [];
                    foreach ($opts as $clave => $reEt) {
                        foreach ($etiquetas as $valor => $et) {
                            if ($valor === '' ) { continue; }
                            if (preg_match($reEt, $et) && !isset($m[$clave])) { $m[$clave] = $valor; }
                        }
                    }
                    if ($m) { $mapa[$logico] = [$campo, $m, $etiquetas]; break; }
                }
            }
        }
        return $mapa;
    }

    private function requerir(array $mapa, string $logico, array $claves): void
    {
        $this->assertArrayHasKey($logico, $mapa, "el formulario de /admin/clientes no tiene el filtro «{$logico}»");
        foreach ($claves as $k) { $this->assertArrayHasKey($k, $mapa[$logico][1], "al filtro «{$logico}» le falta la opción «{$k}» (opciones: " . json_encode($mapa[$logico][2], JSON_UNESCAPED_UNICODE) . ')'); }
    }

    /** IDs de la tabla principal del listado (la tabla con más fichas enlazadas; ignora tablas de «mejores clientes», etc.). @return int[] */
    private function idsTabla(string $html): array
    {
        $x = self::xp($html);
        $mejor = [];
        foreach ($x->query('//table') as $t) {
            $ids = [];
            foreach ($x->query('.//a[contains(@href,"/admin/clientes/ficha")]', $t) as $a) {
                if (preg_match('~id=(\d+)~', $a->getAttribute('href'), $m)) { $ids[] = (int)$m[1]; }
            }
            if (count($ids) > count($mejor)) { $mejor = $ids; }
        }
        return $mejor;
    }

    /** IDs de clientes de una lista completa (recorre todas las páginas con por_pagina=100). @return int[] */
    private function idsLista(Navegador $n, array $consulta): array
    {
        $ids = [];
        for ($p = 1; $p <= 10; $p++) {
            $r = $n->get('/admin/clientes?' . http_build_query($consulta + ['por_pagina' => 100, 'pagina' => $p]));
            $this->assertSame(200, $r['status'], 'GET /admin/clientes?' . http_build_query($consulta) . ' → ' . $r['status'] . ' ' . substr(self::texto($r['body']), 0, 200));
            $nuevos = array_diff($this->idsTabla($r['body']), $ids);
            if (!$nuevos) { break; }
            $ids = array_merge($ids, $nuevos);
            if (!preg_match('~pagina=' . ($p + 1) . '\b~', $r['body'])) { break; }
        }
        sort($ids);
        return $ids;
    }

    /** Oráculo SQL: ids de los clientes sembrados que cumplen los criterios lógicos. @return int[] */
    private function oraculo(array $c): array
    {
        $ids = [];
        foreach (self::$meta as $id => $m) {
            if (isset($c['membresia']) && $m['estado'] !== $c['membresia']) { continue; }
            if (isset($c['cuenta']) && $m['conf'] !== ($c['cuenta'] === 'conf')) { continue; }
            if (isset($c['consent']) && $m['cons'] !== $c['consent']) { continue; }
            $ids[] = $id;
        }
        // Verifica además que el oráculo coincide con la BASE (no solo con la siembra).
        foreach ($ids as $id) {
            $this->assertSame(self::$meta[$id]['estado'], self::escalar('SELECT estado FROM clientes WHERE usuario_id=?', [$id]));
        }
        if (isset($c['actividad'])) {
            $ult = fn(int $id) => self::escalar("SELECT MAX(fecha) FROM citas WHERE usuarioId=? AND estado='completada'", [$id]);
            $corte = self::gye('-90 days')->format('Y-m-d');
            $ids = array_values(array_filter($ids, function ($id) use ($c, $ult, $corte) {
                $u = $ult($id);
                return match ($c['actividad']) { 'reciente' => $u !== null && $u >= $corte, 'inactivo' => $u !== null && $u < $corte, 'nunca' => $u === null };
            }));
        }
        sort($ids);
        return $ids;
    }

    private function consulta(array $mapa, array $criterios): array
    {
        $q = [];
        foreach ($criterios as $logico => $clave) {
            $this->requerir($mapa, $logico, [$clave]);
            $q[$mapa[$logico][0]] = $mapa[$logico][1][$clave];
        }
        return $q;
    }

    /** Universo sembrado + equipo con membresía (ninguno) ⇒ solo ids de self::$meta se comparan. */
    private function sembrados(array $ids): array
    {
        $r = array_values(array_filter($ids, fn($i) => isset(self::$meta[$i])));
        sort($r);
        return $r;
    }

    // ================================================================== K1 / K2

    /** Req D/K1: el formulario del CRM ofrece los filtros del contrato: membresía activa/suspendida, cuenta confirmada/sin confirmar, consentimiento otorgado/revocado/sin decisión y actividad. */
    public function test_K1_filtros_del_contrato_presentes_con_sus_opciones(): void
    {
        $m = $this->controles($this->eq('dueno'));
        $this->requerir($m, 'membresia', ['activo', 'suspendido']);
        $this->requerir($m, 'cuenta', ['conf', 'sin']);
        $this->requerir($m, 'consent', ['otorgado', 'revocado', 'sin']);
        $this->requerir($m, 'actividad', ['inactivo', 'nunca']);
    }

    /** Req D/K2: CADA filtro por separado devuelve exactamente el oráculo SQL (mismos clientes, ni más ni menos). */
    public function test_K2_cada_filtro_individual_coincide_con_el_oraculo(): void
    {
        $n = $this->eq('dueno');
        $mapa = $this->controles($n);
        $fallos = [];
        foreach (['membresia' => ['activo', 'suspendido'], 'cuenta' => ['conf', 'sin'], 'consent' => ['otorgado', 'revocado', 'sin'], 'actividad' => ['reciente', 'inactivo', 'nunca']] as $logico => $claves) {
            foreach ($claves as $k) {
                if (!isset($mapa[$logico][1][$k])) { $fallos[] = "filtro «$logico = {$k}» no existe en la interfaz"; continue; }
                $obt = $this->sembrados($this->idsLista($n, $this->consulta($mapa, [$logico => $k])));
                $esp = $this->oraculo([$logico => $k]);
                if ($obt !== $esp) { $fallos[] = "$logico=$k: la lista devolvió " . count($obt) . ' clientes, el oráculo SQL ' . count($esp) . ' (sobran: ' . json_encode(array_values(array_diff($obt, $esp))) . ', faltan: ' . json_encode(array_values(array_diff($esp, $obt))) . ')'; }
            }
        }
        $this->sinFallos($fallos);
    }

    /** Req D/K2: TODAS las combinaciones membresía × cuenta × consentimiento (y «cualquiera») coinciden con el oráculo. */
    public function test_K2_todas_las_combinaciones_coinciden_con_el_oraculo(): void
    {
        $n = $this->eq('dueno');
        $mapa = $this->controles($n);
        foreach (['membresia', 'cuenta', 'consent'] as $l) { $this->assertArrayHasKey($l, $mapa, "falta el filtro $l"); }
        $fallos = []; $total = 0;
        foreach ([null, 'activo', 'suspendido'] as $a) {
            foreach ([null, 'conf', 'sin'] as $b) {
                foreach ([null, 'otorgado', 'revocado', 'sin'] as $c) {
                    $crit = array_filter(['membresia' => $a, 'cuenta' => $b, 'consent' => $c]);
                    foreach ($crit as $l => $k) { if (!isset($mapa[$l][1][$k])) { $fallos[] = "opción $l=$k inexistente"; continue 2; } }
                    $total++;
                    $obt = $this->sembrados($this->idsLista($n, $this->consulta($mapa, $crit)));
                    $esp = $this->oraculo($crit);
                    if ($obt !== $esp) { $fallos[] = json_encode($crit) . ': obtenidos ' . count($obt) . ' ≠ oráculo ' . count($esp); }
                }
            }
        }
        $this->assertGreaterThanOrEqual(36, $total, 'no se pudieron ejercer las 36 combinaciones');
        $this->sinFallos($fallos);
    }

    /** Req D/K2: combinar con actividad, búsqueda por nombre/correo/teléfono y fechas de alta. */
    public function test_K2_actividad_busqueda_y_fechas_se_combinan_con_los_filtros(): void
    {
        $n = $this->eq('dueno');
        $mapa = $this->controles($n);
        $this->requerir($mapa, 'membresia', ['activo']);
        $this->requerir($mapa, 'actividad', ['reciente']);
        $obt = $this->sembrados($this->idsLista($n, $this->consulta($mapa, ['membresia' => 'activo', 'actividad' => 'reciente'])));
        $this->assertSame($this->oraculo(['membresia' => 'activo', 'actividad' => 'reciente']), $obt, 'activo + visita reciente');
        $uno = self::$cli['activo/conf/otorgado'];
        $m = self::$meta[$uno];
        foreach (['nombre' => 'Filtro1 ', 'correo' => explode('@', $m['email'])[0], 'telefono' => (string)self::escalar('SELECT telefono FROM usuarios WHERE id=?', [$uno])] as $tipo => $q) {
            $ids = $this->sembrados($this->idsLista($n, ['q' => trim($q)]));
            $this->assertContains($uno, $ids, "búsqueda por $tipo («" . trim($q) . "») no encuentra al cliente");
            if ($tipo !== 'nombre') { $this->assertSame([$uno], $ids, "búsqueda por $tipo debe ser exacta"); }
        }
        // `clientes.creado_en` lo escribe el servidor MySQL (UTC en CI y en los contenedores): entre las 19:00 y las 24:00 de Guayaquil su fecha
        // ya es la del día siguiente. El rango «que cubre todo» debe usar la mayor de las dos fechas (hallazgo: la fecha de alta no es de negocio).
        $hoy = max(self::gye()->format('Y-m-d'), gmdate('Y-m-d'));
        $this->assertSame($this->sembrados(array_keys(self::$meta)), $this->sembrados($this->idsLista($n, ['alta_desde' => '2000-01-01', 'alta_hasta' => $hoy])), 'rango de alta que cubre todo');
        $this->assertSame([], $this->sembrados($this->idsLista($n, ['alta_desde' => '2000-01-01', 'alta_hasta' => '2000-01-31'])), 'rango de alta sin clientes');
    }

    // ================================================================== K3

    /** Valor del conteo que muestra la pantalla («N clientes»/«N resultados»), o null. */
    private function conteoMostrado(string $html): ?int
    {
        $t = self::texto($html);
        if (preg_match('/Sin clientes para mostrar|Ning[uú]n cliente coincide/iu', $t)) { return 0; }
        if (preg_match('/Mostrando\s+\d+\s*[–-]\s*\d+\s+de\s+(\d[\d.,]*)/iu', $t, $m) || preg_match('/Clientes,\s*\d+\s+a\s+\d+\s+de\s+(\d[\d.,]*)/iu', $t, $m)) { return (int)preg_replace('/\D/', '', $m[1]); }
        return null;
    }

    /** Correos de un CSV exportado (columna de correo). @return string[] */
    private function correosCsv(string $csv): array
    {
        $f = self::csvFilas($csv);
        $cab = array_map(fn($x) => mb_strtolower($x), array_shift($f) ?? []);
        $col = null;
        foreach ($cab as $i => $h) { if (preg_match('/correo|e-?mail/u', $h)) { $col = $i; break; } }
        if ($col === null) { return []; }
        $out = [];
        foreach ($f as $fila) { if (isset($fila[$col]) && str_contains($fila[$col], '@')) { $out[] = $fila[$col]; } }
        sort($out);
        return $out;
    }

    /** Req D+F/K3: lista = conteo mostrado = exportación CSV (mismo universo) para filtros individuales y combinados; la exportación hereda los filtros. */
    public function test_K3_lista_conteo_y_exportacion_usan_el_mismo_universo(): void
    {
        $n = $this->eq('dueno');
        $mapa = $this->controles($n);
        $fallos = [];
        foreach ([['membresia' => 'suspendido'], ['cuenta' => 'sin'], ['consent' => 'revocado'], ['membresia' => 'activo', 'cuenta' => 'conf', 'consent' => 'otorgado'], ['consent' => 'sin', 'cuenta' => 'sin']] as $crit) {
            $q = $this->consulta($mapa, $crit);
            $esp = $this->oraculo($crit);
            $lista = $n->get('/admin/clientes?' . http_build_query($q + ['por_pagina' => 100]));
            $conteo = $this->conteoMostrado($lista['body']);
            if ($conteo === null) { $fallos[] = json_encode($crit) . ': la pantalla no muestra el conteo de clientes del filtro'; }
            elseif ($conteo !== count($esp) + $this->extraEquipo($crit)) { $fallos[] = json_encode($crit) . ": el conteo mostrado ($conteo) ≠ oráculo (" . count($esp) . ')'; }
            $x = $n->get('/admin/clientes/exportar?' . http_build_query($q + ['tipo' => 'clientes', 'formato' => 'csv']));
            if ($x['status'] !== 200) { $fallos[] = json_encode($crit) . ": exportar → {$x['status']}"; continue; }
            $correos = array_values(array_filter($this->correosCsv($x['body']), fn($c) => in_array($c, array_column(self::$meta, 'email'), true)));
            $espCorreos = array_map(fn($id) => self::$meta[$id]['email'], $esp);
            sort($espCorreos);
            if ($correos !== $espCorreos) { $fallos[] = json_encode($crit) . ': el CSV tiene ' . count($correos) . ' clientes sembrados y el oráculo ' . count($espCorreos); }
        }
        $this->sinFallos($fallos);
    }

    private function extraEquipo(array $crit): int { return 0; }

    /** Req D/K3: el total del CSV sin filtros = total de clientes de la base (y el de la lista completa). */
    public function test_K3_sin_filtros_el_universo_es_toda_la_base_de_clientes(): void
    {
        $n = $this->eq('dueno');
        $total = (int)self::escalar('SELECT COUNT(*) FROM clientes');
        $ids = $this->idsLista($n, []);
        $this->assertCount($total, $ids, 'la lista completa no coincide con la tabla clientes');
        $x = $n->get('/admin/clientes/exportar?tipo=clientes');
        $this->assertSame(200, $x['status']);
        $this->assertCount($total, $this->correosCsv($x['body']), 'el CSV completo no coincide con la tabla clientes');
    }

    // ================================================================== K4

    /**
     * Req alcance por rol: recepción (clientes.ver) sin analítica ni VIP ni gasto ni exportación; finanzas (clientes.analitica) sin datos personales
     * ni fichas ni exportación; profesional solo SUS clientes atendidos (nunca toda la base); gerente/propietario completos.
     */
    public function test_K4_alcance_por_rol(): void
    {
        $fallos = [];
        $correo = self::$meta[self::$cli['activo/conf/otorgado']]['email'];
        $todos = array_column(self::$meta, 'email');
        $atendidoPorA = self::$cli['activo/conf/otorgado'];
        // Recepción.
        $rec = $this->eq('recepcion');
        $r = $rec->get('/admin/clientes?por_pagina=100');
        if ($r['status'] !== 200) { $fallos[] = "recepción /admin/clientes → {$r['status']}"; }
        else {
            $t = self::texto($r['body']);
            if (preg_match('/\bVIP\b/u', $t) && !preg_match('/no (?:disponible|incluye)/iu', $t)) { $fallos[] = 'recepción ve el segmento VIP (analítica)'; }
            if (preg_match('~<th[^>]*>[^<]*(?:gasto|ingreso|ticket)~iu', $r['body']) || preg_match('/\$\s?\d+\.\d\d/u', $t)) { $fallos[] = 'recepción ve columnas o importes de gasto/ingresos (analítica)'; }
        }
        $v = $rec->get('/admin/clientes?segmento=vip');
        if ($v['status'] === 200 && preg_match('/\bVIP\b.*\d+/u', self::texto($v['body'])) && !str_contains(self::texto($v['body']), 'no es una opción')) { $fallos[] = 'recepción puede forzar segmento=vip'; }
        foreach (['tipo=clientes', 'tipo=comercial', 'tipo=clientes&formato=xlsx', 'formato=csv'] as $q) {
            $x = $rec->get('/admin/clientes/exportar?' . $q);
            if ($x['status'] !== 403) { $fallos[] = "recepción exportar?$q → {$x['status']} (esperado 403)"; }
        }
        // Finanzas (solo analítica).
        $fin = $this->eq('finanzas');
        $r = $fin->get('/admin/clientes?por_pagina=100');
        if ($r['status'] === 200) {
            foreach ([$correo, 'Filtro1', '0990000001'] as $dato) { if (str_contains($r['body'], $dato)) { $fallos[] = "finanzas ve datos personales («{$dato}») en el listado"; } }
        } elseif ($r['status'] !== 403) { $fallos[] = "finanzas /admin/clientes → {$r['status']}"; }
        $f = $fin->get("/admin/clientes/ficha?id=$atendidoPorA");
        if (!in_array($f['status'], [403, 404], true)) { $fallos[] = "finanzas ve la ficha de un cliente → {$f['status']}"; }
        $x = $fin->get('/admin/clientes/exportar?tipo=clientes');
        if ($x['status'] !== 403) { $fallos[] = "finanzas exportar → {$x['status']}"; }
        // Profesional A: solo clientes que atendió.
        $pa = $this->eq('proA');
        $r = $pa->get('/admin/clientes?por_pagina=100');
        if ($r['status'] !== 200) { $fallos[] = "profesional /admin/clientes → {$r['status']}"; }
        else {
            preg_match_all('~/admin/clientes/ficha\?id=(\d+)~', $r['body'], $m);
            $vistos = array_unique(array_map('intval', $m[1]));
            $propios = array_map('intval', array_column(self::q("SELECT DISTINCT usuarioId FROM citas WHERE profesionalId=?", [self::$e['proA']['profesional_id']]), 'usuarioId'));
            $ajenos = array_diff($vistos, $propios);
            if ($ajenos) { $fallos[] = 'el profesional ve clientes que NO atendió: ' . json_encode(array_values($ajenos)); }
            if (count($vistos) >= count(self::$meta)) { $fallos[] = 'el profesional recibe toda la base de clientes'; }
            foreach ($todos as $c) { if ($c !== $correo && str_contains($r['body'], $c) && !in_array($c, array_map(fn($i) => self::$meta[$i]['email'] ?? '', $propios), true)) { $fallos[] = "el profesional ve el correo ajeno $c"; break; } }
        }
        $ajeno = self::$cli['activo/conf/sin'];   // atendido solo por ProB
        $f = $pa->get("/admin/clientes/ficha?id=$ajeno");
        if (!in_array($f['status'], [403, 404], true)) { $fallos[] = "el profesional abre la ficha de un cliente ajeno → {$f['status']}"; }
        elseif (str_contains($f['body'], self::$meta[$ajeno]['email'])) { $fallos[] = 'el rechazo de ficha ajena filtra el correo'; }
        foreach (['tipo=clientes', 'tipo=comercial'] as $q) { $x = $pa->get('/admin/clientes/exportar?' . $q); if ($x['status'] !== 403) { $fallos[] = "profesional exportar?$q → {$x['status']}"; } }
        // Gerente y propietario: completos y exportación permitida.
        foreach (['gerente', 'dueno'] as $rol) {
            $n = $this->eq($rol);
            $x = $n->get('/admin/clientes/exportar?tipo=clientes');
            if ($x['status'] !== 200) { $fallos[] = "$rol exportar → {$x['status']}"; }
        }
        $this->sinFallos($fallos);
    }

    // ================================================================== K5

    /** Req D/K5: con filtros activos se muestran chips/etiquetas, el conteo y «Limpiar filtros» (que lleva a /admin/clientes sin parámetros); sin filtros no hay chips falsos. */
    public function test_K5_filtros_activos_chips_conteo_y_limpiar(): void
    {
        $n = $this->eq('dueno');
        $mapa = $this->controles($n);
        $q = $this->consulta($mapa, ['membresia' => 'suspendido', 'consent' => 'revocado']);
        $r = $n->get('/admin/clientes?' . http_build_query($q));
        $this->assertSame(200, $r['status']);
        $t = self::texto($r['body']);
        $this->assertMatchesRegularExpression('/filtros? activos?/iu', $t, 'no se rotulan los filtros activos');
        foreach (['membresia', 'consent'] as $l) {
            $et = $mapa[$l][2][$mapa[$l][1][$l === 'membresia' ? 'suspendido' : 'revocado']];
            $this->assertStringContainsString($et, $t, "el chip/etiqueta del filtro «{$l}» ($et) no aparece");
        }
        $limpiar = null;
        foreach (self::xp($r['body'])->query('//a') as $a) { if (preg_match('/Limpiar filtros/iu', $a->textContent)) { $limpiar = $a->getAttribute('href'); } }
        $this->assertNotNull($limpiar, 'falta «Limpiar filtros»');
        $this->assertSame('/admin/clientes', self::pathDe(self::rutaDe($limpiar)));
        $this->assertSame([], array_diff(array_keys($this->queryDe($limpiar)), ['por_pagina']), '«Limpiar filtros» conserva filtros: ' . $limpiar);
        $sin = $n->get($limpiar);
        $this->assertSame(200, $sin['status']);
        $linkLimpiarSin = false;
        // El formulario conserva siempre su propio «Limpiar filtros» (restablece también lo tecleado y no aplicado); lo que no debe
        // existir sin filtros aplicados es el bloque de FILTROS ACTIVOS (chips + enlace de limpieza del resumen).
        foreach (self::xp($sin['body'])->query("//a[contains(@class,'crm-activos__limpiar')]") as $a) { $linkLimpiarSin = true; }
        $this->assertFalse($linkLimpiarSin, 'sin filtros aplicados no debe mostrarse el bloque de filtros activos con su enlace de limpieza');
        $this->assertNotNull($this->conteoMostrado($r['body']), 'falta el conteo');
        $this->assertGreaterThan($this->conteoMostrado($r['body']), $this->conteoMostrado($sin['body']), 'limpiar filtros debe ampliar el universo');
    }

    private function queryDe(string $ruta): array { parse_str((string)parse_url(html_entity_decode($ruta), PHP_URL_QUERY), $q); return array_filter($q, fn($v) => $v !== ''); }

    /** Req D/K5: la paginación conserva los filtros, no repite ni pierde registros entre páginas, y una página fuera de rango no rompe. */
    public function test_K5_paginacion_conserva_filtros_y_no_pierde_registros(): void
    {
        $n = $this->eq('dueno');
        $mapa = $this->controles($n);
        $q = $this->consulta($mapa, ['membresia' => 'activo']) + ['por_pagina' => 10];
        $vistos = []; $paginas = 0;
        for ($p = 1; $p <= 12; $p++) {
            $r = $n->get('/admin/clientes?' . http_build_query($q + ['pagina' => $p]));
            $this->assertSame(200, $r['status']);
            if (preg_match('/P[aá]gina\s+(\d+)\s+de\s+(\d+)/iu', self::texto($r['body']), $pp) && (int)$pp[1] !== $p) { break; }
            $ids = array_values(array_unique($this->idsTabla($r['body'])));
            if (!$ids) { break; }
            $this->assertLessThanOrEqual(10, count($ids), "página $p con más de 10 filas");
            $this->assertSame([], array_intersect($vistos, $ids), "página $p repite registros");
            $vistos = array_merge($vistos, $ids);
            $paginas++;
            foreach (self::destinos($r['body']) as $d) {
                if (self::pathDe($d) === '/admin/clientes' && preg_match('/[?&]pagina=/', $d)) {
                    $this->assertStringContainsString($mapa['membresia'][0] . '=', $d, "el enlace de paginación «{$d}» pierde el filtro");
                }
            }
        }
        $esperado = (int)self::escalar("SELECT COUNT(*) FROM clientes WHERE estado='activo'");
        $this->assertCount($esperado, $vistos, 'las páginas no suman el universo filtrado');
        $this->assertGreaterThanOrEqual(3, $paginas);
        $fuera = $n->get('/admin/clientes?' . http_build_query($q + ['pagina' => 9999]));
        $this->assertContains($fuera['status'], [200, 422], 'página fuera de rango: ' . $fuera['status']);
    }

    // ================================================================== K6

    /** Req robustez/K6: valores hostiles o inválidos en filtros y búsqueda ⇒ jamás 5xx ni SQL/errores filtrados; valores inválidos ⇒ 422 con mensaje; la inyección no amplía el universo. */
    public function test_K6_entradas_hostiles_en_filtros_y_busqueda(): void
    {
        $n = $this->eq('dueno');
        $fallos = [];
        $casos = ['membresia' => ["x'; DROP TABLE clientes;--", 'ACTIVO', '', ['a'], '1 OR 1=1', str_repeat('a', 5000)], 'consentimiento' => ["' OR '1'='1", 'zz', ['x' => 'y']], 'profesional_id' => ['-1', 'abc', '1e9', '0'],
            'pagina' => ['0', '-3', 'x', '99999999999999999999'], 'por_pagina' => ['0', '101', 'x', '-1'], 'dir' => ['sideways'], 'orden' => ['nombre; DROP TABLE x', 'gasto'], 'desde' => ['2026-13-45', 'ayer', '0000-00-00'], 'hasta' => ['9999-12-31'],
            'q' => ["' OR 1=1 --", "%", "_", "\\", "\x01\x02", "\xC3\x28", "<script>alert(1)</script>", str_repeat('ñ', 500), "=cmd|' /C calc'!A0", "Ro\xF0\x9F\x98\x80sa"]];
        $antes = (int)self::escalar('SELECT COUNT(*) FROM clientes');
        foreach ($casos as $param => $valores) {
            foreach ($valores as $v) {
                $r = $n->get('/admin/clientes?' . http_build_query([$param => $v]));
                if ($r['status'] >= 500) { $fallos[] = "$param=" . substr(json_encode($v, JSON_INVALID_UTF8_SUBSTITUTE), 0, 40) . " → {$r['status']}"; }
                if (preg_match('/SQLSTATE|mysqli|Stack trace|Warning:|Fatal error/i', $r['body'])) { $fallos[] = "$param=" . substr(json_encode($v, JSON_INVALID_UTF8_SUBSTITUTE), 0, 40) . ' filtra detalles internos'; }
                if ($param === 'q' && is_string($v) && str_contains($v, '<script>') && str_contains($r['body'], '<script>alert(1)</script>')) { $fallos[] = 'q refleja <script> sin escapar'; }
            }
        }
        $inj = $this->sembrados($this->idsLista($n, ['q' => "' OR 1=1 --"]));
        if ($inj) { $fallos[] = 'q con inyección devolvió clientes'; }
        $mal = $n->get('/admin/clientes?membresia=hack');
        if ($mal['status'] !== 422) { $fallos[] = "filtro inválido membresia=hack → {$mal['status']} (esperado 422 con mensaje)"; }
        elseif (!preg_match('/no es una opci|no es v[aá]lid|inv[aá]lid/iu', self::texto($mal['body']))) { $fallos[] = 'el 422 por filtro inválido no explica el motivo'; }
        $this->assertSame($antes, (int)self::escalar('SELECT COUNT(*) FROM clientes'), 'la tabla cambió');
        $this->sinFallos($fallos);
    }

    // ================================================================== K7

    /**
     * Req E/K7 («Listado»): en la tabla de segmentos cada fila trae un enlace que abre una LISTA útil (clientes del segmento, no la misma pantalla sin
     * efecto), con paginación y descarga CSV y Excel que heredan el filtro; el total del segmento coincide con la lista.
     */
    public function test_K7_listado_de_segmentos_abre_una_lista_con_descarga(): void
    {
        $n = $this->eq('dueno');
        $r = $n->get('/admin/clientes/indicadores');
        $this->assertSame(200, $r['status']);
        $x = self::xp($r['body']);
        $filas = [];
        foreach ($x->query('//table//tr[td]') as $tr) {
            $celdas = []; foreach ($x->query('./th|./td', $tr) as $c) { $celdas[] = trim(preg_replace('/\s+/', ' ', $c->textContent)); }
            $a = $x->query('.//a[contains(@href,"segmento=")]', $tr);
            if ($a->length) { $filas[] = ['celdas' => $celdas, 'href' => $a->item(0)->getAttribute('href')]; }
        }
        $this->assertNotEmpty($filas, 'la tabla de segmentos no tiene enlaces de «Listado»');
        $fallos = [];
        foreach ($filas as $f) {
            $ruta = self::rutaDe($f['href']);
            $l = $n->get($ruta);
            if ($l['status'] !== 200) { $fallos[] = "{$f['celdas'][0]}: Listado {$ruta} → {$l['status']}"; continue; }
            $esperadoN = (int)preg_replace('/\D/', '', $f['celdas'][1] ?? '0');
            $enLista = count($this->idsLista($n, $this->queryDe($ruta)));
            $mostrado = $this->conteoMostrado($l['body']);
            if ($enLista !== $esperadoN) { $fallos[] = "{$f['celdas'][0]}: la tabla de segmentos dice $esperadoN y el Listado trae $enLista clientes"; }
            if ($mostrado !== null && $mostrado !== $esperadoN) { $fallos[] = "{$f['celdas'][0]}: la tabla dice $esperadoN y el encabezado del Listado muestra $mostrado"; }
            if ($esperadoN > 0 && !$enLista) { $fallos[] = "{$f['celdas'][0]}: la tabla dice $esperadoN clientes pero el Listado no muestra ninguno"; }
            $formatos = [];
            foreach (self::destinos($l['body']) as $d) { if (self::pathDe($d) === '/admin/clientes/exportar') { $formatos[] = $this->queryDe($d)['formato'] ?? 'csv'; } }
            if (!in_array('csv', $formatos, true) || !in_array('xlsx', $formatos, true)) { $fallos[] = "{$f['celdas'][0]}: el Listado no ofrece descarga CSV y Excel (ofrece: " . json_encode($formatos) . ')'; }
        }
        $this->sinFallos($fallos);
    }

    // ================================================================== K8 / K9

    /** Req «no se inventan suscripciones de pago»: ninguna pantalla del CRM muestra planes/mensualidades/«premium» ni montos recurrentes; «suscripción» solo aparece como comunicaciones comerciales. */
    public function test_K8_no_hay_datos_inventados_de_suscripciones_de_pago(): void
    {
        $n = $this->eq('dueno');
        $fallos = [];
        $id = self::$cli['activo/conf/otorgado'];
        foreach (['/admin/clientes', '/admin/clientes?por_pagina=100', "/admin/clientes/ficha?id=$id", '/admin/clientes/auditoria'] as $ruta) {
            $t = self::texto($n->get($ruta)['body']);
            if (preg_match('/plan(?:es)? de pago|mensualidad|premium|membres[ií]a de pago|\$\s?\d+(?:\.\d\d)?\s?\/\s?mes|renovaci[oó]n autom|pr[oó]ximo cobro|facturaci[oó]n recurrente/iu', $t, $m)) { $fallos[] = "$ruta muestra «{$m[0]}»"; }
            foreach (preg_split('/(?<=[.!?])\s+/u', $t) as $frase) {
                if (preg_match('/suscrip/iu', $frase) && !preg_match('/comunicaci|promocion|consentim|correo|marketing|no (?:es|hay|cobra|existe)/iu', $frase)) { $fallos[] = "$ruta usa «suscripción» sin aclarar que es de comunicaciones comerciales: «" . mb_substr($frase, 0, 120) . '»'; break; }
            }
        }
        $this->assertSame(0, (int)self::escalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE '%suscrip%'"), 'se creó una entidad de suscripción de pago');
        $this->sinFallos($fallos);
    }

    /** Req comunicaciones: la lista/exportación COMERCIAL solo incluye consentimiento otorgado vigente; jamás revocados, sin decisión ni (según política) membresías suspendidas. */
    public function test_K9_lista_comercial_respeta_consentimiento_y_bajas(): void
    {
        $n = $this->eq('dueno');
        $x = $n->get('/admin/clientes/exportar?tipo=comercial');
        $this->assertSame(200, $x['status'], 'exportar comercial → ' . $x['status']);
        $correos = $this->correosCsv($x['body']);
        $conocidos = array_column(self::$meta, 'email');
        $fallos = [];
        foreach ($correos as $c) {
            if (!in_array($c, $conocidos, true)) { continue; }
            $id = array_search($c, array_column(self::$meta, 'email')) !== false ? array_keys(self::$meta)[array_search($c, array_column(self::$meta, 'email'))] : null;
            $m = self::$meta[$id];
            if ($m['cons'] !== 'otorgado') { $fallos[] = "{$m['nombre']} figura en la lista comercial con consentimiento «{$m['cons']}»"; }
            if ($m['estado'] === 'suspendido') { $fallos[] = "{$m['nombre']} (membresía suspendida) figura en la lista comercial"; }
            if (!$m['conf']) { $fallos[] = "{$m['nombre']} (cuenta sin confirmar) figura en la lista comercial"; }
        }
        $esperados = array_filter(self::$meta, fn($m) => $m['cons'] === 'otorgado' && $m['estado'] === 'activo' && $m['conf']);
        foreach ($esperados as $m) { if (!in_array($m['email'], $correos, true)) { $fallos[] = "{$m['nombre']} (consentimiento otorgado, activo) falta en la lista comercial"; } }
        $this->sinFallos($fallos);
    }
}

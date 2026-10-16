<?php

namespace Tests\Qa;

require_once __DIR__ . '/R5bCase.php';

/**
 * R5b-QA · Citas «pendientes de revisión» (decisión 5 del usuario; docs/R5_REGULARIZACION.md §1.5): lista = definición (confirmada o en servicio con fecha < hoy),
 * alcance del profesional, aviso con conteo en la agenda, etiqueta por tarjeta, ninguna escritura al solo mirar y salidas ordinarias (ausencia, finalizar) con su fecha real.
 */
final class R5bQaPendientesTest extends R5bCase
{
    private static string $base;
    private static array $e;
    /** @var array<string,int> */
    private static array $c = [];

    public static function setUpBeforeClass(): void
    {
        self::limpiar();
        self::$base = self::servidorB('pend');
        self::$e = self::escenarioB();
        $e = self::$e;
        $a = $e['proA']['profesional_id']; $b = $e['proB']['profesional_id'];
        self::$c = [
            'ayer' => self::citaVieja($e, ['dias' => 1]),
            'servicio5' => self::citaVieja($e, ['dias' => 5, 'estado' => 'en_servicio']),
            'hoy' => self::crearCita(['cliente' => $e['cliente']['id'], 'pro' => $a, 'fecha' => self::gye()->format('Y-m-d'), 'hora' => '00:30:00']),
            'manana' => self::crearCita(['cliente' => $e['cliente']['id'], 'pro' => $a, 'fecha' => self::gye('+1 day')->format('Y-m-d'), 'hora' => '10:00:00']),
            'completada' => self::citaVieja($e, ['dias' => 2, 'estado' => 'completada']),
            'cancelada' => self::citaVieja($e, ['dias' => 2, 'estado' => 'cancelada']),
            'ausente' => self::citaVieja($e, ['dias' => 2, 'estado' => 'no_asistio']),
            'deB' => self::citaVieja($e, ['dias' => 3, 'pro' => $b]),
            'conSolicitud' => self::citaVieja($e, ['dias' => 4]),
        ];
        self::q("INSERT INTO citas_regularizaciones(cita_id,estado,solicitada_por,solicitada_at,efectiva_propuesta_at,motivo_solicitud) VALUES (?, 'solicitada', ?, ?, ?, 'Solicitud sembrada para la prueba')",
            [self::$c['conSolicitud'], $e['proA']['id'], self::gye()->format('Y-m-d H:i:s'), self::gye('-4 days')->format('Y-m-d') . ' 11:00:00']);
    }

    public static function tearDownAfterClass(): void { self::pararServidorB('pend'); }
    protected function setUp(): void { self::q('TRUNCATE intentos_login'); }

    private function n(string $rol): Navegador { return self::entrar(self::$base, self::$e[$rol]); }

    /** @return int[] ids de cita mostrados en una lista (por «Cita #N») */
    private function idsDe(string $html): array
    {
        preg_match_all('/Cita\s*#\s*(\d+)/u', self::texto($html), $m);
        $ids = array_values(array_unique(array_map('intval', $m[1])));
        sort($ids);
        return $ids;
    }

    private function ids(array $claves): array { $r = array_map(fn($k) => self::$c[$k], $claves); sort($r); return $r; }

    /** Req 5: /agenda/pendientes lista EXACTAMENTE las confirmadas o en servicio con fecha < hoy (no hoy, ni futuras, ni completadas, canceladas o con ausencia), más antigua primero. */
    public function test_P_lista_igual_a_la_definicion(): void
    {
        $r = $this->n('dueno')->get('/agenda/pendientes');
        $this->assertSame(200, $r['status']);
        $esperado = $this->ids(['ayer', 'servicio5', 'deB', 'conSolicitud']);
        $this->assertSame($esperado, $this->idsDe($r['body']), 'la lista de pendientes no coincide con la definición');
        // Oráculo SQL independiente.
        $sql = array_map('intval', array_column(self::q("SELECT id FROM citas WHERE estado IN ('confirmada','en_servicio') AND fecha < ? ORDER BY id", [self::gye()->format('Y-m-d')]), 'id'));
        $this->assertSame($esperado, $sql, 'oráculo SQL ≠ siembra');
        $this->assertMatchesRegularExpression('/4 citas pendientes de revisi/iu', self::texto($r['body']), 'el encabezado debe mostrar el conteo (4)');
        $this->assertMatchesRegularExpression('/m[aá]s antigua primero/iu', self::texto($r['body']));
        $txt = self::texto($r['body']);
        $this->assertLessThan(strpos($txt, 'Cita #' . self::$c['ayer']), strpos($txt, 'Cita #' . self::$c['servicio5']), 'orden: la más antigua primero');
        foreach (['gerente', 'recepcion'] as $rol) { $this->assertSame($esperado, $this->idsDe($this->n($rol)->get('/agenda/pendientes')['body']), "$rol ve todas"); }
    }

    /** Req 4/5: el profesional ve SOLO las suyas (ni las de otro profesional, ni datos ajenos); un profesional sin pendientes ve el estado vacío. */
    public function test_P_alcance_del_profesional(): void
    {
        $r = $this->n('proA')->get('/agenda/pendientes');
        $this->assertSame($this->ids(['ayer', 'servicio5', 'conSolicitud']), $this->idsDe($r['body']), 'ProA ve solo las suyas');
        $r = $this->n('proB')->get('/agenda/pendientes');
        $this->assertSame($this->ids(['deB']), $this->idsDe($r['body']), 'ProB ve solo la suya');
        $this->assertStringNotContainsString('ProA', self::texto($r['body']), 'no se filtran datos del otro profesional');
        $pc = self::miembro(self::$e['dueno']['id'], 'profesional', ['nombre' => 'Sinpend']);
        $r = self::entrar(self::$base, $pc)->get('/agenda/pendientes');
        $this->assertSame(200, $r['status']);
        $this->assertSame([], $this->idsDe($r['body']));
        $this->assertMatchesRegularExpression('/no hay|ninguna|sin citas|al d[ií]a/iu', self::texto($r['body']), 'estado vacío explicado');
    }

    /** Req 5: la agenda avisa con el CONTEO correcto (según el alcance de quien mira) y cada tarjeta vencida lleva la etiqueta «Pendiente de revisión»; las de hoy/futuras no. */
    public function test_P_aviso_con_conteo_y_etiqueta_en_la_agenda(): void
    {
        $desde = self::gye('-6 days')->format('Y-m-d'); $hasta = self::gye('+1 day')->format('Y-m-d');
        foreach (['dueno' => 4, 'recepcion' => 4, 'proA' => 3, 'proB' => 1] as $rol => $n) {
            $r = $this->n($rol)->get("/agenda?desde=$desde&hasta=$hasta");
            $this->assertSame(200, $r['status']);
            $this->assertMatchesRegularExpression("/\\b$n citas? de d[ií]as anteriores/iu", self::texto($r['body']), "$rol: el aviso debe contar $n");
            $this->assertNotEmpty(array_filter(self::destinos($r['body']), fn($d) => self::pathDe($d) === '/agenda/pendientes'), "$rol: sin enlace a pendientes");
        }
        $r = $this->n('dueno')->get("/agenda?desde=$desde&hasta=$hasta");
        $this->assertMatchesRegularExpression('/pendiente de revisi/iu', (string)self::tarjeta($r['body'], self::$c['ayer']), 'la tarjeta vencida debe llevar la etiqueta');
        foreach (['hoy', 'manana'] as $k) { $t = self::tarjeta($r['body'], self::$c[$k]); if ($t !== null) { $this->assertDoesNotMatchRegularExpression('/pendiente de revisi/iu', $t, "la cita «{$k}» no es pendiente"); } }
        foreach (['completada', 'cancelada', 'ausente'] as $k) { $t = self::tarjeta($r['body'], self::$c[$k]); if ($t !== null) { $this->assertDoesNotMatchRegularExpression('/pendiente de revisi/iu', $t, "la cita «{$k}» es histórica"); } }
    }

    private function huella(): array
    {
        $r = [];
        foreach (['citas', 'agenda_eventos', 'citas_regularizaciones', 'pagos', 'caja_movimientos', 'comisiones_movimientos'] as $t) { $r[$t] = (string)self::q("CHECKSUM TABLE `$t`")[0]['Checksum']; }
        return $r;
    }

    /** Req 5: mirar NO escribe — ni agenda, ni pendientes, ni detalle, ni el paso del tiempo marcan las citas solas; las tablas quedan idénticas (checksum). */
    public function test_P_ninguna_escritura_al_solo_mirar(): void
    {
        $antes = $this->huella();
        $desde = self::gye('-8 days')->format('Y-m-d'); $hasta = self::gye('+2 days')->format('Y-m-d');
        foreach (['dueno', 'gerente', 'recepcion', 'proA'] as $rol) {
            $n = $this->n($rol);
            foreach (["/agenda?desde=$desde&hasta=$hasta", '/agenda/pendientes', '/agenda/cita?id=' . self::$c['ayer'], '/agenda/cita?id=' . self::$c['conSolicitud'], '/admin'] as $ruta) { $n->get($ruta); }
        }
        $this->assertSame($antes, $this->huella(), 'consultar escribió en la base');
        foreach (['ayer', 'servicio5', 'deB', 'conSolicitud'] as $k) { $this->assertContains(self::cita(self::$c[$k])['estado'], ['confirmada', 'en_servicio'], "«{$k}» se marcó sola"); }
    }

    /** Req 5: salida «registrar ausencia» desde pendientes: ordinaria, con su fecha real (ausencia_at = ahora), la fecha de la cita intacta y sin regularización. */
    public function test_P_salida_registrar_ausencia_con_fecha_real(): void
    {
        $c = self::citaVieja(self::$e, ['dias' => 6]);
        $orig = self::cita($c)['fecha'];
        $n = $this->n('recepcion');
        $f = $this->formDe($n, $c, 'estado');
        $this->aceptado(self::enviar($n, $f, ['estado' => 'no_asistio']), 'ausencia');
        $d = self::cita($c);
        $this->assertSame('no_asistio', $d['estado']);
        $this->assertSame($orig, $d['fecha']);
        $this->assertLessThan(120, self::difSeg($d['ausencia_at']), 'ausencia_at es la hora real: ' . $d['ausencia_at']);
        $this->assertSame(self::$e['recepcion']['id'], (int)$d['ausencia_por']);
        $this->assertNull($d['atencion_efectiva_at']);
        $this->assertNull($d['regularizada_por']);
        $this->assertNotContains($c, $this->idsDe($n->get('/agenda/pendientes')['body']), 'sale de pendientes');
    }

    /** Req 5: salida «finalizar» de una cita EN SERVICIO vieja: ordinaria, con atencion_fin_at = hora real, sin regularización y sin tocar la fecha original. */
    public function test_P_salida_finalizar_en_servicio_con_hora_real(): void
    {
        $c = self::citaVieja(self::$e, ['dias' => 7, 'estado' => 'en_servicio']);
        $orig = self::cita($c)['fecha'];
        $n = $this->n('dueno');
        $this->aceptado(self::enviar($n, $this->formDe($n, $c, 'estado'), ['estado' => 'completada']), 'finalizar');
        $d = self::cita($c);
        $this->assertSame('completada', $d['estado']);
        $this->assertSame($orig, $d['fecha']);
        $this->assertLessThan(120, self::difSeg($d['atencion_fin_at']), 'atencion_fin_at es la hora real: ' . $d['atencion_fin_at']);
        $this->assertSame(self::$e['dueno']['id'], (int)$d['atencion_fin_por']);
        $this->assertNull($d['regularizada_por']);
        $this->assertNull($d['atencion_efectiva_at']);
    }
}

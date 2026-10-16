<?php

namespace Tests\Qa;

require_once __DIR__ . '/R5bCase.php';

use Repositories\CitaProtegidaException;
use Repositories\CitaRepository;
use Services\CitaService;

/**
 * R5b-QA fase 3 · decisión del usuario: NO se elimina físicamente una cita con pagos, devoluciones, comprobantes, eventos de cobro o regularizaciones (409); /api/eliminar solo cancela.
 * Independiente de las pruebas del integrador: cada tipo de evidencia se prueba AISLADO con una fila insertada a mano (las tablas de evidencia son de solo anexar y la prueba del
 * integrador no puede retirar la demás, por lo que no distingue qué comprobación protege), y se mide la carrera contra cambios de estado (orden de bloqueos).
 */
final class R5bQaFase3Test extends R5bCase
{
    private static string $base;
    private static array $e;

    public static function setUpBeforeClass(): void
    {
        self::limpiar();
        self::$base = self::servidorB('f3');
        self::$e = self::escenarioB();
    }

    public static function tearDownAfterClass(): void { self::pararServidorB('f3'); }
    protected function setUp(): void { self::q('TRUNCATE intentos_login'); }

    private function n(string $rol): Navegador { return self::entrar(self::$base, self::$e[$rol]); }
    private function nueva(int $dias = 3, string $estado = 'confirmada'): int { return self::citaVieja(self::$e, ['dias' => $dias, 'estado' => $estado]); }
    private function existe(int $c): bool { return (int)self::escalar('SELECT COUNT(*) FROM citas WHERE id=?', [$c]) === 1; }

    /** Inserta UNA sola evidencia del tipo indicado para la cita. */
    private function evidencia(string $tipo, int $c): void
    {
        $ahora = self::gye()->format('Y-m-d H:i:s');
        $cli = self::$e['cliente']['id'];
        $otra = $this->nueva(9);   // otra cita, para aislar pagos.cita_id de pagos.cita_referencia_id
        switch ($tipo) {
            case 'pagos.cita_referencia_id':
                self::q("INSERT INTO pagos(cita_id,cita_referencia_id,usuario_id,referencia_pago,proveedor,monto,estado,idempotency_key) VALUES (NULL,?,?,?, 'manual', 10.00, 'completado', ?)", [$c, $cli, 'EV-' . bin2hex(random_bytes(6)), 'ev:' . bin2hex(random_bytes(8))]); break;
            case 'pagos.cita_id':
                self::q("INSERT INTO pagos(cita_id,cita_referencia_id,usuario_id,referencia_pago,proveedor,monto,estado,idempotency_key) VALUES (?,?,?,?, 'manual', 10.00, 'completado', ?)", [$c, $otra, $cli, 'EV-' . bin2hex(random_bytes(6)), 'ev:' . bin2hex(random_bytes(8))]); break;
            case 'comprobantes':
                self::q("INSERT INTO comprobantes(serie,numero,codigo,tipo,cita_id,emitido_en,total_centavos,cobrado_centavos,devuelto_centavos,saldo_centavos,contenido_json,contenido_sha256) VALUES ('EV',?,?, 'cobro',?,?,1000,1000,0,0,'{}',?)", [random_int(1000000, 9000000), 'EV-' . bin2hex(random_bytes(5)), $c, $ahora, str_repeat('a', 64)]); break;
            case 'cobros_eventos':
                self::q("INSERT INTO cobros_eventos(tipo,cita_id,registrado_en) VALUES ('cobro_efectivo',?,?)", [$c, $ahora]); break;
            case 'cobros_devoluciones':
                self::q("INSERT INTO cobros_devoluciones(cita_id,monto_centavos,motivo,registrado_en) VALUES (?,500,'Evidencia aislada',?)", [$c, $ahora]); break;
            case 'citas_regularizaciones':
                self::q("INSERT INTO citas_regularizaciones(cita_id,estado,solicitada_por,solicitada_at,efectiva_propuesta_at,motivo_solicitud) VALUES (?, 'solicitada', ?, ?, ?, 'Evidencia aislada de regularización')", [$c, self::$e['proA']['id'], $ahora, self::cita($c)['fecha'] . ' 11:00:00']); break;
            case 'agenda_eventos':
                self::q("INSERT INTO agenda_eventos(citaId,actorId,tipo,antes_json,despues_json,creado_en) VALUES (?,?,'estado','{}','{}',?)", [$c, self::$e['dueno']['id'], $ahora]); break;
            case 'citas_reasignaciones':
                self::q("INSERT INTO citas_reasignaciones(cita_id,cita_referencia_id,accion,profesional_origen_nombre,actor_id,motivo,snapshot_json) VALUES (?,?,'reasignada','Profesional anterior',?,'Evidencia aislada','{}')", [$c, $c, self::$e['dueno']['id']]); break;
            case 'fidelidad_movimientos':
                self::q("INSERT INTO fidelidad_movimientos(usuario_id,cita_id,puntos,tipo,referencia) VALUES (?,?,10,'devengo',?)", [$cli, $c, 'ev:' . bin2hex(random_bytes(8))]); break;
            default: throw new \InvalidArgumentException($tipo);
        }
    }

    /** @return array<string,array{string}> */
    public static function tiposDeEvidencia(): array
    {
        return array_combine($t = ['pagos.cita_referencia_id', 'pagos.cita_id', 'comprobantes', 'cobros_eventos', 'cobros_devoluciones', 'citas_regularizaciones', 'agenda_eventos', 'citas_reasignaciones', 'fidelidad_movimientos'], array_map(fn($x) => [$x], $t));
    }

    /**
     * Req: CADA tipo de evidencia, AISLADA (la única fila que existe para la cita), impide la eliminación física por la capa de dominio (409 con alternativa auditada) y por el repositorio
     * (CitaProtegidaException); la cita y sus servicios siguen intactos.
     * @dataProvider tiposDeEvidencia
     */
    public function test_E_cada_evidencia_aislada_protege_la_cita(string $tipo): void
    {
        $c = $this->nueva(3);
        $this->evidencia($tipo, $c);
        [$tabla, $columna] = str_starts_with($tipo, 'pagos.') ? explode('.', $tipo) : [$tipo, $tipo === 'agenda_eventos' ? 'citaId' : 'cita_id'];
        $evidenciaAntes = self::q("SELECT * FROM `$tabla` WHERE `$columna`=? ORDER BY id", [$c]);
        $this->assertCount(1, $evidenciaAntes, "[$tipo] debe existir una sola evidencia aislada");
        $r = (new CitaService())->eliminar($c, self::$e['dueno']['id'], true);
        $this->assertSame(409, (int)($r['httpCode'] ?? 0), "[$tipo] CitaService::eliminar → " . json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertMatchesRegularExpression('/cancel|devoluci|ausencia/iu', (string)$r['error'], "[$tipo] el 409 debe ofrecer la alternativa auditada");
        try { (new CitaRepository(self::db()))->eliminarCitaAtomica($c); $this->fail("[$tipo] el repositorio eliminó la cita"); } catch (CitaProtegidaException $e) { $this->assertNotSame('', $e->motivo); }
        $this->assertTrue($this->existe($c), "[$tipo] la cita desapareció");
        $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM citasservicios WHERE citaId=?', [$c]), "[$tipo] se borraron sus servicios");
        $this->assertSame($evidenciaAntes, self::q("SELECT * FROM `$tabla` WHERE `$columna`=? ORDER BY id", [$c]), "[$tipo] la evidencia o su vínculo cambió");
    }

    /** Eliminar espera al profesional sin retener la cita: el orden inverso es el origen del interbloqueo. */
    public function test_E_orden_de_bloqueo_profesional_antes_de_cita(): void
    {
        $c = $this->nueva();
        $profesional = (int)self::cita($c)['profesionalId'];
        $this->assertGreaterThan(0, $profesional);
        $db = new \mysqli(self::cfg('DB_HOST', '127.0.0.1'), self::cfg('DB_USER', 'root'), self::cfg('DB_PASS', ''), self::nombreBd(), (int)self::cfg('DB_PORT', '3306'));
        $p = null;
        try {
            $db->begin_transaction();
            $bloqueo = $db->query("SELECT id FROM profesionales WHERE id=$profesional FOR UPDATE");
            $this->assertNotFalse($bloqueo);
            $this->assertSame(1, $bloqueo->num_rows);
            $codigo = <<<'PHP'
require $argv[1] . '/tests/bootstrap.php';
$db = \Model\ActiveRecord::getDB();
$db->query('SET SESSION innodb_lock_wait_timeout=5');
echo $db->thread_id . "\n"; flush();
try { echo json_encode(['eliminada' => (new \Repositories\CitaRepository($db))->eliminarCitaAtomica((int)$argv[2])]); }
catch (\Throwable $e) { echo json_encode(['error' => $e->getMessage()]); }
PHP;
            $p = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', '-r', $codigo, self::RAIZ, (string)$c],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::RAIZ, array_merge(getenv(), ['DB_NAME' => self::nombreBd()]));
            $this->assertIsResource($p);
            stream_set_timeout($pipes[1], 10);
            $conexion = (int)fgets($pipes[1]);
            $this->assertGreaterThan(0, $conexion);
            $espera = false;
            for ($i = 0; $i < 60 && !$espera; $i++) {
                $espera = (int)self::escalar('SELECT COUNT(*) FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.THREAD_ID=w.REQUESTING_THREAD_ID WHERE t.PROCESSLIST_ID=?', [$conexion]) > 0;
                if (!$espera) { usleep(50000); }
            }
            $diagnostico = self::q('SELECT OBJECT_NAME,INDEX_NAME,LOCK_DATA,LOCK_MODE,LOCK_STATUS FROM performance_schema.data_locks WHERE OBJECT_SCHEMA=DATABASE()');
            // Si se restaura cita→profesional, el hijo ya retiene la cita y NOWAIT falla (3572).
            $citaLibre = $db->query("SELECT id FROM citas WHERE id=$c FOR UPDATE NOWAIT");
            $error = $db->error;
            $this->assertNotFalse($citaLibre, 'la eliminación retuvo la cita mientras esperaba al profesional: ' . $error);
        } finally {
            $db->rollback();
            if (is_resource($p)) {
                $salida = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                proc_close($p);
            }
            $db->close();
        }
        $this->assertTrue($espera, 'el proceso de eliminación debe esperar a ESTE profesional: ' . json_encode([$conexion, $diagnostico, $salida, $stderr]));
        $this->assertSame('', $stderr);
        $this->assertSame(['eliminada' => true], json_decode($salida, true), $salida);
    }

    /** Req: flujos REALES de la aplicación (cobro, devolución parcial, comprobante, regularización) dejan la cita protegida, con 409 por servicio y sin huérfanas. */
    public function test_E_flujos_reales_protegen_la_cita(): void
    {
        $n = $this->n('dueno');
        $reg = $this->nueva(4);
        $this->aceptado($this->regularizar($n, $reg), 'regularizar');
        $cob = $this->nueva(5, 'completada');
        $f = self::formulario($n->get("/caja/cita?id=$cob")['body'], '~/caja/cita/cobrar~');
        $this->assertNotNull($f);
        $this->aceptado(self::enviar($n, $f, [self::campo($f, '/monto|importe|valor|cantidad/i', '/recib|entreg|cambio|total|saldo|pendiente/i') => '20.00', self::campo($f, '/recib|entreg/i') => '20.00', self::campo($f, '/metodo|medio|forma/i') => 'efectivo']), 'cobro');
        $dev = self::formulario($n->get("/caja/cita?id=$cob")['body'], '~devol~i');
        $this->assertNotNull($dev);
        $this->aceptado(self::enviar($n, $dev, [self::campo($dev, '/monto|importe|valor|cantidad/i') => '5.00', self::campo($dev, '/motivo|concepto|razon/i') => 'Devolución real de prueba']), 'devolución');
        foreach (['regularizada' => $reg, 'cobrada y devuelta' => $cob] as $que => $id) {
            $r = (new CitaService())->eliminar($id, self::$e['dueno']['id'], true);
            $this->assertSame(409, (int)$r['httpCode'], "$que: " . json_encode($r, JSON_UNESCAPED_UNICODE));
            $this->assertTrue($this->existe($id));
        }
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM comprobantes x LEFT JOIN citas c ON c.id=x.cita_id WHERE c.id IS NULL'), 'comprobantes huérfanos');
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM pagos x LEFT JOIN citas c ON c.id=x.cita_referencia_id WHERE c.id IS NULL'), 'pagos huérfanos');
    }

    /** Req: /api/eliminar solo CANCELA (nunca borra): cita sin cobros ⇒ cancelada y todo su historial intacto; con cobro/regularización ⇒ sigue ahí. Ningún camino HTTP borra filas de citas. */
    public function test_E_api_eliminar_solo_cancela(): void
    {
        $n = $this->n('dueno');
        $csrf = Navegador::csrfDe($n->get('/perfil')['body']) ?? '';
        $futura = self::crearCita(['cliente' => self::$e['cliente']['id'], 'pro' => self::$e['proA']['profesional_id'], 'fecha' => self::gye('+3 days')->format('Y-m-d'), 'hora' => '10:00:00']);
        $antes = (int)self::escalar('SELECT COUNT(*) FROM citasservicios WHERE citaId=?', [$futura]);
        $r = $n->post('/api/eliminar', ['csrf_token' => $csrf, 'id' => $futura], ['Accept: text/html']);
        $this->assertContains($r['status'], [200, 302, 303], '/api/eliminar sobre una cita futura: ' . $r['status']);
        $this->assertTrue($this->existe($futura), '/api/eliminar BORRÓ la fila de la cita');
        $this->assertSame('cancelada', self::cita($futura)['estado']);
        $this->assertSame($antes, (int)self::escalar('SELECT COUNT(*) FROM citasservicios WHERE citaId=?', [$futura]), 'se borraron sus servicios');
        $reg = $this->nueva(4);
        $this->aceptado($this->regularizar($n, $reg), 'regularizar');
        $con = $this->nueva(5, 'completada');
        $this->evidencia('comprobantes', $con);
        foreach ([$reg, $con] as $id) {
            $r = $n->post('/api/eliminar', ['csrf_token' => $csrf, 'id' => $id], ['Accept: text/html']);
            $this->assertTrue($this->existe($id));
            $this->assertNotSame('cancelada', self::cita($id)['estado']);
        }
        // Total de filas de citas no disminuyó por ninguna ruta HTTP.
        $total = (int)self::escalar('SELECT COUNT(*) FROM citas');
        foreach (['/api/eliminar', '/servicios/eliminar', '/profesionales/descansos/eliminar'] as $ruta) { $n->post($ruta, ['csrf_token' => $csrf, 'id' => $futura]); }
        $this->assertSame($total, (int)self::escalar('SELECT COUNT(*) FROM citas'));
    }

    /** Req (carrera): eliminar una cita sin evidencia mientras otro proceso cambia su estado (agenda: bloquea profesional → cita) no puede interbloquear ni dar 5xx; 15 rondas con procesos reales. */
    public function test_E_eliminar_contra_cambio_de_estado_sin_interbloqueo(): void
    {
        $codigo = <<<'PHP'
$a = json_decode($argv[1], true);
require $a['raiz'] . '/tests/bootstrap.php';
while (microtime(true) < $a['inicio']) { usleep(200); }
try { $ok = (new \Repositories\CitaRepository(\Model\ActiveRecord::getDB()))->eliminarCitaAtomica((int)$a['id']); echo json_encode(['r' => $ok ? 'eliminada' : 'inexistente']); }
catch (\Repositories\CitaProtegidaException $e) { echo json_encode(['r' => 'protegida']); }
catch (\Throwable $e) { echo json_encode(['r' => 'error', 'm' => get_class($e) . ': ' . $e->getMessage() . ' / ' . ($e->getPrevious() ? $e->getPrevious()->getMessage() : '')]); }
PHP;
        $errores = []; $res = ['eliminada' => 0, 'inexistente' => 0, 'protegida' => 0, 'error' => 0];
        for ($i = 1; $i <= 15; $i++) {
            $c = $this->nueva(2 + $i);
            $n = $this->n('recepcion');
            $f = $this->formDe($n, $c, 'estado');
            $cuerpo = $this->cuerpoB($f, ['estado' => 'no_asistio']);
            $inicio = microtime(true) + 1.6;
            $p = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', '-r', $codigo, json_encode(['raiz' => self::RAIZ, 'id' => $c, 'inicio' => $inicio])], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::RAIZ, array_merge(getenv(), ['DB_NAME' => self::nombreBd()]));
            $http = self::rafaga(self::$base, [['cookie' => $n->cookieSesion(), 'metodo' => 'POST', 'ruta' => $f['action'], 'body' => $cuerpo]], 1.6);
            $salida = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); proc_close($p);
            $j = json_decode($salida, true) ?: ['r' => 'error', 'm' => 'sin salida: ' . $salida];
            $res[$j['r']]++;
            if ($j['r'] === 'error') { $errores[] = "ronda $i: " . ($j['m'] ?? ''); }
            if ($http[0]['status'] >= 500) { $errores[] = "ronda $i: HTTP {$http[0]['status']}"; }
        }
        fwrite(STDERR, "\n[E-carrera] " . json_encode($res) . "\n");
        $this->sinFallos($errores, 'eliminar vs cambio de estado:');
        $this->assertStringNotContainsString('Deadlock', self::logB('f3'));
    }
}

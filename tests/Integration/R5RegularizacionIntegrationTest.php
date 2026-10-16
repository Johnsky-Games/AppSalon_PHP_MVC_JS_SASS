<?php
namespace Tests\Integration;

require_once __DIR__ . '/R5CajaFixture.php';

use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\CitaRepository;
use Repositories\OperacionRepository;
use Repositories\ReporteRepository;
use Services\AgendaAtencionService;
use Services\AtencionRechazada;
use Services\CitaService;
use Services\CobroCitaService;
use Services\ComisionService;
use Services\RegularizacionAtencionService;

/**
 * Regularización de la atención de citas de días anteriores (docs/R5_REGULARIZACION.md). Pruebas del integrador (NO son la revisión
 * independiente): permisos, reglas de entrada, auditoría, idempotencia y consistencia financiera con la base real.
 */
final class R5RegularizacionIntegrationTest extends TestCase
{
    private \mysqli $db;
    private int $dueno; private int $gerente; private int $recepcion; private int $finanzas; private int $cliente;
    private int $profUsuario; private int $profOtroUsuario; private int $prof; private int $profOtro;

    protected function setUp(): void
    {
        $this->db = new \mysqli(getenv('DB_HOST') ?: 'appsalon-test-db', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', getenv('DB_NAME') ?: 'appsalon_test', (int)(getenv('DB_PORT') ?: 3306));
        $this->db->set_charset('utf8mb4');
        ActiveRecord::setDB($this->db);
        validar_base_datos_prueba($this->db);
        $this->dueno = R5CajaFixture::usuario($this->db, 'propietario');
        $this->gerente = R5CajaFixture::usuario($this->db, 'gerente');
        $this->recepcion = R5CajaFixture::usuario($this->db, 'recepcion');
        $this->finanzas = R5CajaFixture::usuario($this->db, 'finanzas');
        $this->cliente = R5CajaFixture::usuario($this->db, 'cliente');
        $this->profUsuario = R5CajaFixture::usuario($this->db, 'profesional');
        $this->profOtroUsuario = R5CajaFixture::usuario($this->db, 'profesional');
        $this->prof = R5CajaFixture::profesional($this->db, 1000);
        $this->profOtro = R5CajaFixture::profesional($this->db);
        $this->db->query("UPDATE profesionales SET usuarioId={$this->profUsuario} WHERE id={$this->prof}");
        $this->db->query("UPDATE profesionales SET usuarioId={$this->profOtroUsuario} WHERE id={$this->profOtro}");
    }

    protected function tearDown(): void
    {
        // Hijos primero y solo lo creado por esta prueba (la clave foránea RESTRICT impide borrar citas con regularización).
        $this->db->query("DELETE r FROM citas_regularizaciones r JOIN citas c ON c.id=r.cita_id WHERE c.usuarioId={$this->cliente}");
    }

    // ------------------------------------------------------------------------------------------------ utilidades

    private function ahora(): \DateTimeImmutable { return new \DateTimeImmutable('now', new \DateTimeZone('America/Guayaquil')); }
    private function dia(int $desdeHoy): string { return $this->ahora()->modify(($desdeHoy >= 0 ? '+' : '') . $desdeHoy . ' days')->format('Y-m-d'); }

    private function cita(string $estado = 'confirmada', int $diasAtras = 5, ?int $prof = null, array $precios = ['40.00']): int
    {
        return R5CajaFixture::cita($this->db, $this->cliente, $prof ?? $this->prof, $precios, $estado, $this->dia(-$diasAtras));
    }

    private function servicio(?\DateTimeImmutable $ahora = null): RegularizacionAtencionService
    {
        return new RegularizacionAtencionService(new OperacionRepository($this->db), $ahora);
    }

    private function datos(int $cita, ?string $efectiva = null, string $motivo = 'El servicio se prestó y no se registró en su momento', ?string $clave = null): array
    {
        $efectiva ??= $this->dia(-5) . ' 11:30';
        return ['cita_id' => $cita, 'motivo' => $motivo, 'efectiva' => $efectiva, 'idem_key' => $clave ?? R5CajaFixture::clave()];
    }

    private function intenta(callable $f): ?AtencionRechazada
    {
        try { $f(); } catch (AtencionRechazada $e) { return $e; }
        return null;
    }

    private function fila(int $cita): array { return $this->db->query("SELECT * FROM citas WHERE id=$cita")->fetch_assoc(); }
    private function n(string $sql): int { return (int)R5CajaFixture::escalar($this->db, $sql); }

    private function segundosDesdeAhora(string $datetime): int
    {
        return (int)abs($this->ahora()->getTimestamp() - (new \DateTimeImmutable($datetime, new \DateTimeZone('America/Guayaquil')))->getTimestamp());
    }

    // ------------------------------------------------------------------------------------------------ permisos

    /** @return array<string,array{0:string}> */
    public static function rolesSinPermisoParaRegularizar(): array
    {
        return ['recepcion' => ['recepcion'], 'finanzas' => ['finanzas'], 'profesional' => ['profDuenoCita'], 'cliente' => ['cliente']];
    }

    /** @dataProvider rolesSinPermisoParaRegularizar */
    public function testSoloPropietarioYGerenteRegularizan(string $quien): void
    {
        $actor = ['recepcion' => $this->recepcion, 'finanzas' => $this->finanzas, 'profDuenoCita' => $this->profUsuario, 'cliente' => $this->cliente][$quien];
        $cita = $this->cita();
        $e = $this->intenta(fn() => $this->servicio()->regularizar($actor, $this->datos($cita)));
        $this->assertNotNull($e, $quien);
        $this->assertSame(403, $e->http());
        $this->assertSame('confirmada', $this->fila($cita)['estado'], 'sin permiso no cambia nada');
        $this->assertSame(0, $this->n("SELECT COUNT(*) FROM citas_regularizaciones WHERE cita_id=$cita"));
        $this->assertSame(0, $this->n("SELECT COUNT(*) FROM agenda_eventos WHERE citaId=$cita"));
        $e = $this->intenta(fn() => $this->servicio()->rechazar($actor, ['regularizacion_id' => 1, 'motivo' => 'No corresponde regularizarla', 'idem_key' => R5CajaFixture::clave()]));
        $this->assertSame(403, $e?->http(), 'tampoco rechazan solicitudes');
    }

    public function testPropietarioYGerenteRegularizan(): void
    {
        foreach ([$this->dueno, $this->gerente] as $actor) {
            $cita = $this->cita();
            $r = $this->servicio()->regularizar($actor, $this->datos($cita));
            $this->assertFalse($r['repetida']);
            $this->assertSame('completada', $this->fila($cita)['estado']);
            $this->assertSame($actor, (int)$this->fila($cita)['regularizada_por']);
        }
    }

    public function testElProfesionalSolicitaSoloSusCitasYNuncaAprueba(): void
    {
        $propia = $this->cita('confirmada', 5, $this->prof);
        $ajena = $this->cita('confirmada', 5, $this->profOtro);
        $r = $this->servicio()->solicitar($this->profUsuario, $this->datos($propia));
        $this->assertGreaterThan(0, $r['regularizacion_id']);
        $e = $this->intenta(fn() => $this->servicio()->solicitar($this->profUsuario, $this->datos($ajena)));
        $this->assertSame(404, $e?->http(), 'una cita ajena es indistinguible de una inexistente');
        $this->assertSame(0, $this->n("SELECT COUNT(*) FROM citas_regularizaciones WHERE cita_id=$ajena"));
        // Aprobar/rechazar: no.
        $this->assertSame(403, $this->intenta(fn() => $this->servicio()->regularizar($this->profUsuario, $this->datos($propia)))?->http());
        $this->assertSame(403, $this->intenta(fn() => $this->servicio()->rechazar($this->profUsuario, ['regularizacion_id' => $r['regularizacion_id'], 'motivo' => 'Quiero rechazar la mía', 'idem_key' => R5CajaFixture::clave()]))?->http());
        $this->assertSame('confirmada', $this->fila($propia)['estado']);
        // Quienes no son profesional no solicitan (recepción, gerente, finanzas, cliente).
        foreach ([$this->recepcion, $this->gerente, $this->finanzas, $this->cliente] as $otro) {
            $this->assertSame(403, $this->intenta(fn() => $this->servicio()->solicitar($otro, $this->datos($propia)))?->http());
        }
    }

    // ------------------------------------------------------------------------------------------------ reglas de entrada

    public function testSoloCitasSinResolverDeDiasAnteriores(): void
    {
        $hoy = R5CajaFixture::cita($this->db, $this->cliente, $this->prof, ['40.00'], 'confirmada', $this->dia(0));
        $futura = R5CajaFixture::cita($this->db, $this->cliente, $this->prof, ['40.00'], 'confirmada', $this->dia(3));
        $e = $this->intenta(fn() => $this->servicio()->regularizar($this->gerente, $this->datos($hoy, $this->dia(0) . ' 00:01')));
        $this->assertSame(422, $e?->http(), 'la de hoy usa el flujo ordinario');
        $this->assertStringContainsString('flujo ordinario', $e->getMessage());
        $this->assertSame(422, $this->intenta(fn() => $this->servicio()->regularizar($this->gerente, $this->datos($futura)))?->http());
        foreach (['completada', 'cancelada', 'no_asistio'] as $estado) {
            $c = $this->cita($estado);
            $e = $this->intenta(fn() => $this->servicio()->regularizar($this->gerente, $this->datos($c)));
            $this->assertSame(409, $e?->http(), $estado);
            $this->assertSame($estado, $this->fila($c)['estado']);
        }
        $this->assertSame(404, $this->intenta(fn() => $this->servicio()->regularizar($this->gerente, $this->datos(999999999)))?->http());
        // Una cita «en servicio» de un día anterior también está pendiente de revisión.
        $enServicio = $this->cita('en_servicio');
        $this->servicio()->regularizar($this->gerente, $this->datos($enServicio));
        $this->assertSame('completada', $this->fila($enServicio)['estado']);
    }

    public function testFechaEfectivaNuncaFuturaNiAnteriorAlDiaDeLaCita(): void
    {
        $cita = $this->cita('confirmada', 5);
        $futuro = $this->ahora()->modify('+2 minutes')->format('Y-m-d H:i');
        $ahoraMismo = $this->ahora()->modify('-1 minute')->format('Y-m-d H:i');
        foreach ([$futuro, $this->dia(1) . ' 10:00', $this->dia(-6) . ' 23:59', 'ayer', '2026-13-40 10:00', '', '2026-10-10', "2026-10-10 10:00\0"] as $malo) {
            $e = $this->intenta(fn() => $this->servicio()->regularizar($this->gerente, $this->datos($cita, $malo)));
            $this->assertSame(422, $e?->http(), json_encode($malo));
        }
        $this->assertSame('confirmada', $this->fila($cita)['estado'], 'ningún intento inválido escribe');
        // Límite: el instante actual (hace un minuto) sí vale; también el formato datetime-local con «T».
        $this->servicio()->regularizar($this->gerente, $this->datos($cita, str_replace(' ', 'T', $ahoraMismo)));
        $this->assertSame('completada', $this->fila($cita)['estado']);
    }

    public function testElMotivoEsObligatorioYAcotado(): void
    {
        $cita = $this->cita();
        foreach (['', '   ', 'corto', str_repeat('x', 501), "Motivo con\x07control de sobra"] as $malo) {
            $this->assertSame(422, $this->intenta(fn() => $this->servicio()->regularizar($this->gerente, $this->datos($cita, null, $malo)))?->http(), json_encode($malo));
        }
        $this->assertSame(422, $this->intenta(fn() => $this->servicio()->regularizar($this->gerente, ['cita_id' => $cita, 'efectiva' => $this->dia(-5) . ' 11:30', 'idem_key' => R5CajaFixture::clave()]))?->http());
        $this->assertSame(422, $this->intenta(fn() => $this->servicio()->regularizar($this->gerente, $this->datos($cita, null, 'Motivo válido y suficiente', 'clave-corta')))?->http(), 'clave de formulario inválida');
        $this->assertSame('confirmada', $this->fila($cita)['estado']);
    }

    // ------------------------------------------------------------------------------------------------ resultado y auditoría

    public function testRegularizarGuardaPorSeparadoQuienCuandoYLaFechaEfectivaSinTocarLaOriginal(): void
    {
        $cita = $this->cita('confirmada', 5);
        $antes = $this->fila($cita);
        $efectiva = $this->dia(-4) . ' 15:45'; // el cliente se atendió un día después de lo reservado
        $r = $this->servicio()->regularizar($this->gerente, $this->datos($cita, $efectiva, 'Se atendió al día siguiente y quedó sin registrar'));
        $c = $this->fila($cita);
        $this->assertSame('completada', $c['estado']);
        $this->assertSame([$antes['fecha'], $antes['hora']], [$c['fecha'], $c['hora']], 'la fecha y hora originales no cambian');
        $this->assertSame($efectiva . ':00', $c['atencion_efectiva_at']);
        $this->assertSame($this->gerente, (int)$c['regularizada_por']);
        $this->assertLessThan(120, $this->segundosDesdeAhora($c['regularizada_at']), 'regularizada_at es el momento REAL del registro');
        $this->assertNotSame(substr($c['regularizada_at'], 0, 10), substr($c['atencion_efectiva_at'], 0, 10), 'cuándo ocurrió y cuándo se registró son datos distintos');
        $this->assertNull($c['atencion_inicio_at'], 'no se inventa una hora de inicio');
        $this->assertNull($c['atencion_fin_at']);
        $this->assertNull($c['atencion_inicio_por']);
        $this->assertSame('pendiente', $c['estado_pago'], 'regularizar no marca pagado');
        // Fila de regularización y evento de agenda.
        $reg = $this->db->query("SELECT * FROM citas_regularizaciones WHERE cita_id=$cita")->fetch_all(MYSQLI_ASSOC);
        $this->assertCount(1, $reg);
        $this->assertSame('aprobada', $reg[0]['estado']);
        $this->assertNull($reg[0]['solicitada_por'], 'regularización directa: sin solicitante');
        $this->assertSame($this->gerente, (int)$reg[0]['resuelta_por']);
        $this->assertSame('Se atendió al día siguiente y quedó sin registrar', $reg[0]['motivo_resolucion']);
        $eventos = $this->db->query("SELECT * FROM agenda_eventos WHERE citaId=$cita ORDER BY id")->fetch_all(MYSQLI_ASSOC);
        $this->assertCount(1, $eventos);
        $this->assertSame('regularizar', $eventos[0]['tipo']);
        $this->assertSame($this->gerente, (int)$eventos[0]['actorId']);
        $this->assertLessThan(120, $this->segundosDesdeAhora($eventos[0]['creado_en']));
        $despues = json_decode($eventos[0]['despues_json'], true);
        $this->assertSame('completada', $despues['estado']);
        $this->assertSame($efectiva . ':00', $despues['atencion_efectiva_at']);
        $this->assertSame('confirmada', json_decode($eventos[0]['antes_json'], true)['estado']);
        $this->assertSame($cita, $r['cita_id']);
    }

    public function testMismaClaveEsIdempotenteYOtraPersonaRecibe409(): void
    {
        $cita = $this->cita();
        $clave = R5CajaFixture::clave();
        $d = $this->datos($cita, null, 'El servicio se prestó y no se registró en su momento', $clave);
        $primera = $this->servicio()->regularizar($this->gerente, $d);
        $repetida = $this->servicio()->regularizar($this->gerente, $d);
        $this->assertFalse($primera['repetida']);
        $this->assertTrue($repetida['repetida']);
        $this->assertSame($primera['regularizacion_id'], $repetida['regularizacion_id']);
        $this->assertSame(1, $this->n("SELECT COUNT(*) FROM citas_regularizaciones WHERE cita_id=$cita"));
        $this->assertSame(1, $this->n("SELECT COUNT(*) FROM agenda_eventos WHERE citaId=$cita"));
        // Misma clave con otros datos: 409. Otra persona, otra clave: 409 «ya regularizada por …».
        $this->assertSame(409, $this->intenta(fn() => $this->servicio()->regularizar($this->gerente, ['motivo' => 'Otro motivo distinto de verdad'] + $d))?->http());
        $e = $this->intenta(fn() => $this->servicio()->regularizar($this->dueno, $this->datos($cita)));
        $this->assertSame(409, $e?->http());
        $this->assertStringContainsString('ya fue regularizada por', $e->getMessage());
        $this->assertSame(1, $this->n("SELECT COUNT(*) FROM citas_regularizaciones WHERE cita_id=$cita"));
    }

    // ------------------------------------------------------------------------------------------------ solicitud → decisión

    public function testSolicitudAprobadaConservaSolicitanteYDecisor(): void
    {
        $cita = $this->cita();
        $efectiva = $this->dia(-5) . ' 10:15';
        $sol = $this->servicio()->solicitar($this->profUsuario, $this->datos($cita, $efectiva, 'Atendí a la clienta y olvidé cerrarla'));
        $this->assertSame('confirmada', $this->fila($cita)['estado'], 'solicitar no cambia la cita');
        $e = $this->intenta(fn() => $this->servicio()->solicitar($this->profUsuario, $this->datos($cita)));
        $this->assertSame(409, $e?->http(), 'una sola solicitud abierta por cita');
        $r = $this->servicio()->regularizar($this->dueno, $this->datos($cita, $efectiva, 'Confirmado con la clienta y con el profesional'));
        $this->assertSame($sol['regularizacion_id'], $r['regularizacion_id'], 'la decisión resuelve la solicitud abierta');
        $reg = $this->db->query("SELECT * FROM citas_regularizaciones WHERE cita_id=$cita")->fetch_all(MYSQLI_ASSOC);
        $this->assertCount(1, $reg);
        $this->assertSame('aprobada', $reg[0]['estado']);
        $this->assertSame($this->profUsuario, (int)$reg[0]['solicitada_por']);
        $this->assertSame($this->dueno, (int)$reg[0]['resuelta_por']);
        $this->assertSame('Atendí a la clienta y olvidé cerrarla', $reg[0]['motivo_solicitud']);
        $this->assertSame('Confirmado con la clienta y con el profesional', $reg[0]['motivo_resolucion']);
        $tipos = array_column($this->db->query("SELECT tipo FROM agenda_eventos WHERE citaId=$cita ORDER BY id")->fetch_all(MYSQLI_ASSOC), 'tipo');
        $this->assertSame(['regularizacion_solicitud', 'regularizar'], $tipos);
    }

    public function testRechazoDejaLaCitaPendienteYPermiteSolicitarDeNuevo(): void
    {
        $cita = $this->cita();
        $sol = $this->servicio()->solicitar($this->profUsuario, $this->datos($cita));
        $this->assertSame(422, $this->intenta(fn() => $this->servicio()->rechazar($this->gerente, ['regularizacion_id' => $sol['regularizacion_id'], 'motivo' => 'no', 'idem_key' => R5CajaFixture::clave()]))?->http(), 'el rechazo exige motivo');
        $clave = R5CajaFixture::clave();
        $d = ['regularizacion_id' => $sol['regularizacion_id'], 'motivo' => 'No hay constancia de que se haya atendido', 'idem_key' => $clave];
        $r = $this->servicio()->rechazar($this->gerente, $d);
        $this->assertTrue($this->servicio()->rechazar($this->gerente, $d)['repetida'], 'doble clic: un solo efecto');
        $this->assertSame($sol['regularizacion_id'], $r['regularizacion_id']);
        $fila = $this->db->query("SELECT * FROM citas_regularizaciones WHERE id={$sol['regularizacion_id']}")->fetch_assoc();
        $this->assertSame('rechazada', $fila['estado']);
        $this->assertSame($this->gerente, (int)$fila['resuelta_por']);
        $this->assertSame('confirmada', $this->fila($cita)['estado'], 'sigue pendiente de revisión: no se marca sola');
        $this->assertSame(409, $this->intenta(fn() => $this->servicio()->rechazar($this->dueno, ['idem_key' => R5CajaFixture::clave()] + $d))?->http(), 'ya resuelta');
        // Puede volver a solicitar.
        $this->assertGreaterThan($sol['regularizacion_id'], $this->servicio()->solicitar($this->profUsuario, $this->datos($cita))['regularizacion_id']);
        $this->assertSame(3, $this->n("SELECT COUNT(*) FROM agenda_eventos WHERE citaId=$cita"), 'solicitud + rechazo + nueva solicitud');
    }

    // ------------------------------------------------------------------------------------------------ integridad referencial

    public function testUnaCitaConRegularizacionNoSePuedeEliminarNiQuedaAuditoriaHuerfana(): void
    {
        $cita = $this->cita();
        $this->servicio()->regularizar($this->gerente, $this->datos($cita));
        // Aplicación: 409 con mensaje claro y nada se pierde.
        $r = (new CitaService())->eliminar($cita, $this->dueno, true);
        $this->assertFalse($r['resultado']);
        $this->assertSame(409, $r['httpCode']);
        $this->assertStringContainsString('regularización', $r['error']);
        $this->assertSame(1, $this->n("SELECT COUNT(*) FROM citas WHERE id=$cita"));
        $this->assertSame(1, $this->n("SELECT COUNT(*) FROM citas_regularizaciones WHERE cita_id=$cita"));
        $this->assertSame(1, $this->n("SELECT COUNT(*) FROM agenda_eventos WHERE citaId=$cita"));
        // Base de datos: la clave foránea RESTRICT lo impide aunque otro código lo intente (no es una limpieza de pruebas).
        // La aplicación fija MYSQLI_REPORT_OFF (includes/database.php): aquí se usa explícitamente y se restaura el mismo valor.
        mysqli_report(MYSQLI_REPORT_OFF);
        try {
            $ok = $this->db->query("DELETE FROM citas WHERE id=$cita");
            $errno = $this->db->errno;
        } finally {
            mysqli_report(MYSQLI_REPORT_OFF); // valor del proyecto (includes/database.php): no alterar el modo de las demás pruebas
        }
        $this->assertFalse($ok, 'DELETE directo debe fallar');
        $this->assertSame(1451, $errno, 'violación de clave foránea (hijo existente)');
        $this->assertSame(1, $this->n("SELECT COUNT(*) FROM citas WHERE id=$cita"));
        // Una cita SIN regularización se sigue eliminando como siempre.
        $libre = $this->cita();
        $this->assertTrue((new CitaService())->eliminar($libre, $this->dueno, true)['resultado']);
        // Tras todas estas operaciones de la aplicación no hay regularizaciones sin cita.
        $this->assertSame(0, $this->n('SELECT COUNT(*) FROM citas_regularizaciones r LEFT JOIN citas c ON c.id=r.cita_id WHERE c.id IS NULL'), 'ninguna regularización huérfana');
    }

    // ------------------------------------------------------------------------------------------------ pendientes de revisión

    public function testPendientesDeRevisionSonExactamenteLasSinResolverDeDiasAnteriores(): void
    {
        $vieja = $this->cita('confirmada', 9);
        $viejaEnServicio = $this->cita('en_servicio', 3);
        $ajena = $this->cita('confirmada', 4, $this->profOtro);
        $ruido = [$this->cita('completada', 5), $this->cita('cancelada', 5), $this->cita('no_asistio', 5),
            R5CajaFixture::cita($this->db, $this->cliente, $this->prof, ['40.00'], 'confirmada', $this->dia(0)),
            R5CajaFixture::cita($this->db, $this->cliente, $this->prof, ['40.00'], 'confirmada', $this->dia(2))];
        $repo = new CitaRepository();
        $todas = array_map(fn($f) => (int)$f['id'], $repo->listarPendientesRevision($this->dia(0)));
        foreach ([$vieja, $viejaEnServicio, $ajena] as $id) { $this->assertContains($id, $todas); }
        foreach ($ruido as $id) { $this->assertNotContains($id, $todas); }
        $propias = array_map(fn($f) => (int)$f['id'], $repo->listarPendientesRevision($this->dia(0), $this->profUsuario));
        $this->assertContains($vieja, $propias);
        $this->assertNotContains($ajena, $propias, 'el profesional ve solo las suyas');
        // Leer, listar o armar acciones NO escribe nada.
        $antes = $this->n("SELECT COUNT(*) FROM agenda_eventos WHERE citaId IN ($vieja,$viejaEnServicio,$ajena)");
        (new AgendaAtencionService())->pendientesRevision($this->gerente, ['gestiona' => true, 'propia' => true, 'finanzas' => true, 'regularizar' => true, 'solicitar' => false]);
        $this->assertSame($antes, $this->n("SELECT COUNT(*) FROM agenda_eventos WHERE citaId IN ($vieja,$viejaEnServicio,$ajena)"));
        $this->assertSame('confirmada', $this->fila($vieja)['estado']);
        $this->assertSame('en_servicio', $this->fila($viejaEnServicio)['estado']);
    }

    public function testAccionesPorRolEnUnaCitaPendiente(): void
    {
        $cita = $this->cita('confirmada', 5);
        $f = (new CitaRepository())->listarPendientesRevision($this->dia(0));
        $fila = array_values(array_filter($f, fn($x) => (int)$x['id'] === $cita))[0];
        $ahora = $this->ahora();
        $gerente = AgendaAtencionService::accionesDe($fila, $ahora, ['gestiona' => true, 'propia' => true, 'finanzas' => true, 'regularizar' => true, 'solicitar' => false]);
        $this->assertTrue($gerente['pendiente_revision']);
        $this->assertTrue($gerente['regularizar']);
        $this->assertFalse($gerente['solicitar']);
        $this->assertTrue($gerente['reprogramar'], 'la cita vencida se reprograma');
        $iniciar = array_values(array_filter($gerente['operar'], fn($a) => $a['clave'] === 'iniciar'))[0];
        $this->assertFalse($iniciar['habilitada'], 'no hay inicio ordinario');
        $this->assertStringContainsString('reprográmala', $iniciar['motivo']);
        $recepcion = AgendaAtencionService::accionesDe($fila, $ahora, ['gestiona' => true, 'propia' => true, 'finanzas' => false, 'regularizar' => false, 'solicitar' => false]);
        $this->assertFalse($recepcion['regularizar']);
        $this->assertFalse($recepcion['solicitar']);
        $this->assertTrue($recepcion['reprogramar']);
        $prof = AgendaAtencionService::accionesDe($fila, $ahora, ['gestiona' => false, 'propia' => true, 'finanzas' => false, 'regularizar' => false, 'solicitar' => true]);
        $this->assertTrue($prof['solicitar']);
        $this->assertFalse($prof['regularizar']);
        $this->assertFalse($prof['reprogramar'], 'el profesional no reprograma');
        $cliente = AgendaAtencionService::accionesDe($fila, $ahora, ['gestiona' => false, 'propia' => false, 'finanzas' => false, 'regularizar' => false, 'solicitar' => false]);
        $this->assertFalse($cliente['regularizar'] || $cliente['solicitar'] || $cliente['reprogramar']);
        $conAbierta = AgendaAtencionService::accionesDe(array_merge($fila, ['solicitud_abierta_id' => 7]), $ahora, ['gestiona' => false, 'propia' => true, 'finanzas' => false, 'regularizar' => false, 'solicitar' => true]);
        $this->assertFalse($conAbierta['solicitar'], 'con una solicitud abierta no se ofrece otra');
        // Una cita de hoy no está pendiente de revisión.
        $hoy = ['id' => 1, 'estado' => 'confirmada', 'fecha' => $this->dia(0), 'hora' => '10:00:00', 'hora_inicio' => '10:00:00'];
        $this->assertFalse(AgendaAtencionService::accionesDe($hoy, $ahora, ['gestiona' => true, 'propia' => true, 'regularizar' => true])['pendiente_revision']);
    }

    // ------------------------------------------------------------------------------------------------ inicio ordinario cerrado

    public function testElInicioOrdinarioDeUnaCitaDeUnDiaAnteriorSeRechazaSinEscribir(): void
    {
        $cita = $this->cita('confirmada', 3);
        $r = (new CitaService())->actualizarAgenda($cita, $this->dueno, true, false, 'estado', ['estado' => 'en_servicio']);
        $this->assertFalse($r['resultado']);
        $this->assertSame(422, $r['httpCode']);
        $this->assertStringContainsString('reprográmala', $r['error']);
        $this->assertStringContainsString('regulariza', $r['error']);
        $c = $this->fila($cita);
        $this->assertSame('confirmada', $c['estado']);
        $this->assertNull($c['atencion_inicio_at']);
        $this->assertSame(0, $this->n("SELECT COUNT(*) FROM agenda_eventos WHERE citaId=$cita"));
        // Registrar la ausencia sigue siendo posible: registra SU fecha real, no retrodata.
        $ausente = $this->cita('confirmada', 3);
        $a = (new CitaService())->actualizarAgenda($ausente, $this->gerente, true, false, 'estado', ['estado' => 'no_asistio']);
        $this->assertTrue($a['resultado']);
        $this->assertLessThan(120, $this->segundosDesdeAhora($this->fila($ausente)['ausencia_at']));
    }

    // ------------------------------------------------------------------------------------------------ consistencia financiera

    public function testCobrarTrasRegularizarLlevaLaFechaRealYNoRetrodata(): void
    {
        $cita = $this->cita('confirmada', 6);
        $original = $this->fila($cita)['fecha'];
        $this->servicio()->regularizar($this->gerente, $this->datos($cita, $this->dia(-6) . ' 12:00'));
        $cobro = (new CobroCitaService(new OperacionRepository($this->db)))->cobrar($this->finanzas, ['cita_id' => $cita, 'metodo' => 'efectivo', 'monto' => '40.00', 'recibido' => '40.00', 'idem_key' => R5CajaFixture::clave()]);
        $pago = $this->db->query("SELECT * FROM pagos WHERE id={$cobro['pago_id']}")->fetch_assoc();
        $this->assertLessThan(120, $this->segundosDesdeAhora($pago['pagado_at']), 'pagado_at es la hora real del cobro, no la de la cita ni la efectiva');
        $caja = $this->db->query("SELECT * FROM caja_movimientos WHERE pago_id={$pago['id']}")->fetch_assoc();
        $this->assertLessThan(120, $this->segundosDesdeAhora($caja['registrado_en']));
        $comp = $this->db->query("SELECT * FROM comprobantes WHERE cita_id=$cita AND pago_id={$pago['id']}")->fetch_assoc();
        $this->assertLessThan(120, $this->segundosDesdeAhora($comp['emitido_en']));
        $com = $this->db->query("SELECT * FROM comisiones_movimientos WHERE pago_id={$pago['id']} AND tipo='devengo'")->fetch_assoc();
        $this->assertSame(400, (int)$com['monto_centavos']);
        $this->assertLessThan(120, $this->segundosDesdeAhora($com['registrado_en']), 'la comisión nace cuando se confirma el dinero');
        $c = $this->fila($cita);
        $this->assertSame($original, $c['fecha'], 'la fecha original de la cita sigue intacta tras cobrar');
        $this->assertSame('pagado', $c['estado_pago']);
        $this->assertSame('completada', $c['estado']);
        // Conciliación: pago = caja = comisión base.
        $this->assertSame(4000, $this->n("SELECT SUM(monto_centavos) FROM caja_movimientos WHERE pago_id={$pago['id']} AND tipo='ingreso'"));
    }

    public function testUnaLiquidacionCerradaNoAbsorbeNiCambiaPorUnaRegularizacionPosterior(): void
    {
        $gestor = new ComisionService(new OperacionRepository($this->db));
        // Comisión antigua pendiente (hace 20 días) y su liquidación cerrada hasta ayer.
        $citaVieja = $this->cita('completada', 20);
        $this->db->query("INSERT INTO pagos (cita_id,cita_referencia_id,usuario_id,referencia_pago,proveedor,monto,moneda,estado,metodo_pago,idempotency_key,pagado_at) VALUES ($citaVieja,$citaVieja,{$this->cliente},'MAN-REG-" . bin2hex(random_bytes(4)) . "','manual','10.00','USD','completado','efectivo','caja:reg" . bin2hex(random_bytes(8)) . "','" . $this->dia(-20) . " 10:00:00')");
        $pagoViejo = (int)$this->db->insert_id;
        $this->db->query("INSERT INTO comisiones_movimientos (profesional_id,profesional_nombre,pago_id,pago_referencia,tipo,base_centavos,porcentaje_pb,monto_centavos,registrado_en) VALUES ({$this->prof},'Prof R5',$pagoViejo,'REF-VIEJA','devengo',1000,1000,100,'" . $this->dia(-20) . " 10:00:00')");
        $liq = $gestor->liquidar($this->finanzas, ['profesional_id' => $this->prof, 'hasta' => $this->dia(-1), 'metodo' => 'efectivo', 'comprobante' => 'LIQ-REG-1', 'idempotencia' => bin2hex(random_bytes(12))]);
        $this->assertSame(100, $liq['monto_centavos']);
        $antes = $this->db->query("SELECT * FROM comisiones_liquidaciones WHERE id={$liq['id']}")->fetch_assoc();
        $movLiquidados = $this->n("SELECT COUNT(*) FROM comisiones_movimientos WHERE liquidacion_id={$liq['id']}");

        // Se regulariza una cita cuyo día cae DENTRO del período ya liquidado y se cobra hoy.
        $cita = $this->cita('confirmada', 10);
        $this->servicio()->regularizar($this->gerente, $this->datos($cita, $this->dia(-10) . ' 09:30'));
        $cobro = (new CobroCitaService(new OperacionRepository($this->db)))->cobrar($this->finanzas, ['cita_id' => $cita, 'metodo' => 'efectivo', 'monto' => '40.00', 'recibido' => '40.00', 'idem_key' => R5CajaFixture::clave()]);

        // La liquidación cerrada sigue idéntica y la comisión nueva está pendiente, fuera de ella.
        $despues = $this->db->query("SELECT * FROM comisiones_liquidaciones WHERE id={$liq['id']}")->fetch_assoc();
        $this->assertSame($antes, $despues, 'la liquidación cerrada no cambia');
        $this->assertSame($movLiquidados, $this->n("SELECT COUNT(*) FROM comisiones_movimientos WHERE liquidacion_id={$liq['id']}"));
        $nuevo = $this->db->query("SELECT * FROM comisiones_movimientos WHERE pago_id={$cobro['pago_id']} AND tipo='devengo'")->fetch_assoc();
        $this->assertSame('pendiente', $nuevo['estado']);
        $this->assertNull($nuevo['liquidacion_id']);
        $this->assertLessThan(120, $this->segundosDesdeAhora($nuevo['registrado_en']));
        // Liquidar de nuevo hasta el mismo corte (ayer) no la toma; la siguiente liquidación (hasta hoy) sí, y solo a ella.
        $e = null;
        try { $gestor->liquidar($this->finanzas, ['profesional_id' => $this->prof, 'hasta' => $this->dia(-1), 'metodo' => 'efectivo', 'comprobante' => 'LIQ-REG-2', 'idempotencia' => bin2hex(random_bytes(12))]); }
        catch (\InvalidArgumentException $x) { $e = $x; }
        $this->assertNotNull($e, 'no hay nada que liquidar hasta ayer: la comisión de la cita regularizada nació hoy');
        $liq2 = $gestor->liquidar($this->finanzas, ['profesional_id' => $this->prof, 'hasta' => $this->dia(0), 'metodo' => 'efectivo', 'comprobante' => 'LIQ-REG-3', 'idempotencia' => bin2hex(random_bytes(12))]);
        $this->assertSame(400, $liq2['monto_centavos']);
        $this->assertSame($antes, $this->db->query("SELECT * FROM comisiones_liquidaciones WHERE id={$liq['id']}")->fetch_assoc());
    }

    public function testLosReportesDistinguenFechaDeServicioYFechaDeCobro(): void
    {
        $cita = $this->cita('confirmada', 8);
        $diaOriginal = $this->dia(-8);
        $this->servicio()->regularizar($this->gerente, $this->datos($cita, $this->dia(-8) . ' 13:00'));
        (new CobroCitaService(new OperacionRepository($this->db)))->cobrar($this->finanzas, ['cita_id' => $cita, 'metodo' => 'efectivo', 'monto' => '40.00', 'recibido' => '40.00', 'idem_key' => R5CajaFixture::clave()]);
        $repo = new ReporteRepository();
        $ids = static fn(array $filas): array => array_values(array_unique(array_map(static fn($f) => (int)$f['cita_id'], $filas)));
        $porServicioOriginal = $repo->obtenerFilasReservasEnRango($diaOriginal, $diaOriginal, null, null, 'servicio');
        $this->assertContains($cita, $ids($porServicioOriginal), 'por fecha de servicio figura en su día ORIGINAL');
        $hoy = $this->dia(0);
        $this->assertNotContains($cita, $ids($repo->obtenerFilasReservasEnRango($hoy, $hoy, null, null, 'servicio')), 'regularizar no la mueve al día en que se registró');
        $this->assertContains($cita, $ids($repo->obtenerFilasReservasEnRango($hoy, $hoy, null, null, 'cobro')), 'por fecha de cobro figura el día REAL del cobro');
        $this->assertNotContains($cita, $ids($repo->obtenerFilasReservasEnRango($diaOriginal, $diaOriginal, null, null, 'cobro')));
    }
}

<?php
namespace Tests\Integration;

use Controllers\CrmController;
use Controllers\EquipoController;
use Controllers\ProfesionalController;
use Model\ActiveRecord;
use MVC\Router;
use PHPUnit\Framework\TestCase;
use Repositories\ColaboradorRepository;
use Repositories\CrmRepository;
use Services\CrmService;
use Services\ProfesionalService;
use Services\RecursosHumanosService;

/** Separación de personas y vistas; carga semanal real, atómica y sin pérdida de días ajenos. */
final class GestionClientesRhHorariosIntegrationTest extends TestCase
{
    private \mysqli $db;
    private ?\mysqli $anterior;
    private string $tag;
    private array $usuarios = [];
    private array $colaboradores = [];
    private array $profesionales = [];
    private array $citas = [];
    private ?string $trigger = null;
    private const FULL = ['ver' => true, 'analitica' => true, 'propios' => false, 'exportar' => true];

    protected function setUp(): void
    {
        $this->anterior = ActiveRecord::getDB();
        $this->db = new \mysqli(getenv('DB_HOST'), getenv('DB_USER'), getenv('DB_PASS'), getenv('DB_NAME'), (int)getenv('DB_PORT'));
        $this->db->set_charset('utf8mb4'); validar_base_datos_prueba($this->db); ActiveRecord::setDB($this->db);
        $this->tag = 'gestion' . bin2hex(random_bytes(5));
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $_GET = []; $_POST = []; $_SESSION = []; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/admin/clientes';
        $_SERVER['HTTP_ACCEPT'] = 'text/html'; http_response_code(200);
        CrmController::setCrmService(null); ProfesionalController::setProfesionalService(null);
    }

    protected function tearDown(): void
    {
        if ($this->trigger) { $this->db->query('DROP TRIGGER IF EXISTS ' . $this->trigger); }
        foreach ($this->citas as $id) { $this->db->query('DELETE FROM citas WHERE id=' . $id); }
        foreach ($this->profesionales as $id) { $this->db->query('DELETE FROM profesionales WHERE id=' . $id); }
        foreach ($this->colaboradores as $id) { $this->db->query('DELETE FROM colaboradores WHERE id=' . $id); }
        foreach ($this->usuarios as $id) { $this->db->query('DELETE FROM usuarios WHERE id=' . $id); }
        ActiveRecord::setDB($this->anterior); $this->db->close();
        $_SESSION = []; $_GET = []; $_POST = []; http_response_code(200);
        CrmController::setCrmService(null); ProfesionalController::setProfesionalService(null);
    }

    private function sql(string $sql, string $tipos = '', array $args = []): void
    {
        $s = $this->db->prepare($sql); $this->assertNotFalse($s);
        if ($tipos !== '') { $s->bind_param($tipos, ...$args); }
        $this->assertTrue($s->execute(), $s->error); $s->close();
    }

    private function usuario(string $rol = 'cliente', bool $membresia = true): int
    {
        $email = $this->tag . count($this->usuarios) . '@gestion.test';
        $this->sql('INSERT INTO usuarios(nombre,apellido,email,password,admin,confirmado) VALUES(?,?,?,?,?,1)', 'ssssi',
            ['Persona', $this->tag, $email, password_hash('PruebaGestion123!', PASSWORD_BCRYPT), $rol === 'propietario' ? 1 : 0]);
        $id = (int)$this->db->insert_id; $this->usuarios[] = $id;
        $this->sql('INSERT INTO usuario_accesos(usuario_id,rol,activo) VALUES(?,?,1)', 'is', [$id, $rol]);
        if ($membresia) { $this->sql("INSERT INTO clientes(usuario_id,estado,origen) VALUES(?,'activo','registro_publico')", 'i', [$id]); }
        if ($rol !== 'cliente') { $this->colaborador($rol, $id, $email); }
        return $id;
    }

    private function colaborador(string $rol = 'profesional', ?int $usuario = null, ?string $email = null): int
    {
        $email ??= $this->tag . count($this->colaboradores) . '@rh.test';
        $this->sql("INSERT INTO colaboradores(usuario_id,email_norm,nombre,apellido,puesto,rol,estado,notas_rh) VALUES(?,?,?,?,'Estilista',?,'activo','NOTA PRIVADA RH')", 'issss', [$usuario, $email, 'Colaborador', $this->tag, $rol]);
        return $this->colaboradores[] = (int)$this->db->insert_id;
    }

    private function profesional(): int
    {
        $this->sql('INSERT INTO profesionales(nombre,activo) VALUES(?,1)', 's', ['Agenda ' . $this->tag]);
        return $this->profesionales[] = (int)$this->db->insert_id;
    }

    private function auth(int $id): void
    {
        $_SESSION = ['id' => $id, 'login' => true, 'portal' => 'equipo', 'version_sesion' => 1, 'csrf_token' => 'gestion-csrf'];
    }

    private function pagina(callable $llamada): array
    {
        http_response_code(200); ob_start();
        try { $llamada(); } catch (\AppTerminationException $e) { http_response_code($e->getStatusCode()); }
        catch (\Throwable $e) { ob_end_clean(); throw $e; }
        return ['status' => http_response_code(), 'html' => (string)ob_get_clean()];
    }

    private function crm(array $extra = []): array
    {
        $s = new CrmService(new CrmRepository($this->db));
        $v = $s->normalizarFiltros($extra + ['q' => $this->tag], self::FULL, $s->catalogos());
        $this->assertSame([], $v['errores']);
        return [$s, $v['filtros']];
    }

    public function testElPropietarioConMembresiaAntiguaNoApareceComoClienteExterno(): void
    {
        $cliente = $this->usuario(); $owner = $this->usuario('propietario'); $interno = $this->usuario('profesional'); $soloEquipo = $this->usuario('finanzas', false);
        [$s, $f] = $this->crm();
        $this->assertSame([$cliente], array_map('intval', array_column($s->listado($f, self::FULL)['listado']['filas'], 'id')));
        $this->assertSame(1, $s->panel($f, self::FULL, null, false)['resumen']['clientes_total']);
        $archivo = $s->prepararExportacion($f, self::FULL, 'clientes');
        $this->assertSame([$cliente], array_map('intval', array_column(iterator_to_array($archivo['filas']), 'id')));
        [$s, $f] = $this->crm(['vinculo' => 'equipo']);
        $ids = array_map('intval', array_column($s->listado($f, self::FULL)['listado']['filas'], 'id')); sort($ids);
        $this->assertSame([$owner, $interno], $ids); $this->assertNotContains($soloEquipo, $ids);
        [$s, $f] = $this->crm(['vinculo' => 'todos']);
        $this->assertSame(3, $s->panel($f, self::FULL)['resumen']['clientes_total']);
        $this->assertSame('todos', \Services\CrmAuditoriaService::filtrosParaAuditoria($f)['vinculo']);
        $this->assertSame(3, (int)$this->db->query('SELECT COUNT(*) n FROM clientes WHERE usuario_id IN (' . implode(',', $this->usuarios) . ')')->fetch_assoc()['n'], 'Consultar no elimina membresías.');
    }

    public function testBaseEIndicadoresSonVistasDistintas(): void
    {
        $this->usuario(); $this->auth($this->usuario('propietario', false)); $_GET = ['q' => $this->tag];
        $base = $this->pagina(fn() => CrmController::index(new Router()));
        $reporte = $this->pagina(fn() => CrmController::indicadores(new Router()));
        $this->assertSame(200, $base['status']); $this->assertSame(200, $reporte['status']);
        $this->assertStringContainsString('crm-tabla-clientes--compacta', $base['html']);
        $this->assertStringNotContainsString('id="crm-ind-titulo"', $base['html']);
        $this->assertStringContainsString('id="crm-ind-titulo"', $reporte['html']);
        $this->assertStringNotContainsString('class="crm-tabla-clientes', $reporte['html']);
        $this->assertStringNotContainsString('crm-filtros-panel" open', $base['html']);
    }

    public function testFinanzasVeIndicadoresPeroNoPuedeAbrirElListadoNominal(): void
    {
        $this->usuario(); $this->auth($this->usuario('finanzas', false));
        $reporte = $this->pagina(fn() => CrmController::indicadores(new Router()));
        $this->assertSame(200, $reporte['status']);
        $this->assertStringNotContainsString('@gestion.test', $reporte['html']);
        $this->assertSame(403, $this->pagina(fn() => CrmController::listado(new Router()))['status']);
    }

    public function testDirectorioRhListaTodosLosRolesYProtegeLasNotas(): void
    {
        $this->auth($this->usuario('propietario', false)); $this->usuario('gerente', false); $this->usuario('recepcion', false); $_GET = ['q' => $this->tag];
        $r = $this->pagina(fn() => EquipoController::recursosHumanos(new Router()));
        $this->assertSame(200, $r['status']); $this->assertStringContainsString('/admin/recursos-humanos/ficha?id=', $r['html']);
        $this->assertStringNotContainsString('NOTA PRIVADA RH', $r['html']);
        $this->assertStringContainsString('Recursos Humanos', $r['html']);
        $this->assertSame(3, (new RecursosHumanosService(new ColaboradorRepository($this->db)))->consultar(['q' => $this->tag])['directorio']['total']);
    }

    public function testRhExigeSuPermisoEnElServidor(): void
    {
        foreach (['gerente', 'recepcion', 'finanzas', 'profesional', 'cliente'] as $rol) {
            $this->auth($this->usuario($rol, $rol === 'cliente'));
            if ($rol === 'cliente') { $_SESSION['portal'] = 'cliente'; }
            $this->assertSame(403, $this->pagina(fn() => EquipoController::recursosHumanos(new Router()))['status'], $rol);
        }
    }

    public function testRhPaginaYFiltraSinPerderAlColaborador501(): void
    {
        for ($i = 0; $i < 501; $i++) { $this->colaborador($i === 500 ? 'finanzas' : 'profesional'); }
        $s = new RecursosHumanosService(new ColaboradorRepository($this->db));
        $r = $s->consultar(['q' => $this->tag, 'pagina' => '21']);
        $this->assertSame(501, $r['directorio']['total']); $this->assertCount(1, $r['directorio']['filas']);
        $r = $s->consultar(['q' => $this->tag, 'rol' => 'finanzas']); $this->assertSame(1, $r['directorio']['total']);
        $this->assertNotEmpty($s->consultar(['q' => [], 'rol' => 'invalido', 'estado' => [], 'pagina' => []])['errores']);
    }

    private function grupo(int $id, string $tipo, array $extra = []): array
    {
        return (new ProfesionalService())->aplicarGrupoSemanal($id, $extra + ['dias' => ['2', '3', '4', '5', '6'], 'hora_inicio' => $tipo === 'horario' ? '09:00' : '14:00', 'hora_fin' => $tipo === 'horario' ? '18:00' : '15:00', 'motivo' => 'Almuerzo'], $tipo);
    }

    private function horario(int $id): array
    {
        return ['horarios' => $this->db->query('SELECT dia_semana,hora_inicio,hora_fin FROM horarios_profesionales WHERE profesionalId=' . $id . ' ORDER BY dia_semana,hora_inicio')->fetch_all(MYSQLI_ASSOC),
            'descansos' => $this->db->query('SELECT dia_semana,hora_inicio,hora_fin,motivo FROM descansos_profesionales WHERE profesionalId=' . $id . ' ORDER BY dia_semana,hora_inicio')->fetch_all(MYSQLI_ASSOC)];
    }

    public function testJornadaYDescansoDeMartesASabadoConservanElLunes(): void
    {
        $id = $this->profesional();
        $this->assertSame('ok', $this->grupo($id, 'horario', ['dias' => ['1'], 'hora_inicio' => '08:00', 'hora_fin' => '12:00'])['status']);
        $this->assertSame('ok', $this->grupo($id, 'descanso', ['dias' => ['1'], 'hora_inicio' => '10:00', 'hora_fin' => '10:30'])['status']);
        $this->assertSame('ok', $this->grupo($id, 'horario')['status']);
        $this->assertSame('ok', $this->grupo($id, 'descanso')['status']);
        $this->assertSame('ok', $this->grupo($id, 'descanso')['status']);
        $r = $this->horario($id); $this->assertCount(6, $r['horarios']); $this->assertCount(6, $r['descansos']);
        $this->assertSame('08:00:00', $r['horarios'][0]['hora_inicio']); $this->assertSame('10:00:00', $r['descansos'][0]['hora_inicio']);
        foreach (array_slice($r['horarios'], 1) as $h) { $this->assertSame('09:00:00', $h['hora_inicio']); $this->assertSame('18:00:00', $h['hora_fin']); }
    }

    public function testGrupoInvalidoNoModificaNingunDia(): void
    {
        $id = $this->profesional(); $this->assertSame('ok', $this->grupo($id, 'horario')['status']); $antes = $this->horario($id);
        foreach ([['dias' => []], ['dias' => ['2', '8']], ['dias' => [['2']]], ['hora_inicio' => ['09:00']], ['hora_fin' => '08:00'], ['hora_inicio' => '09:02'], ['motivo' => []]] as $entrada) {
            $this->assertSame('invalid', $this->grupo($id, 'horario', $entrada)['status']); $this->assertSame($antes, $this->horario($id));
        }
    }

    public function testJornadaIncompatibleConDescansoRevierteTodoElGrupo(): void
    {
        $id = $this->profesional(); $this->grupo($id, 'horario'); $this->grupo($id, 'descanso'); $antes = $this->horario($id);
        $this->assertSame('invalid', $this->grupo($id, 'horario', ['hora_fin' => '13:00'])['status']);
        $this->assertSame($antes, $this->horario($id));
    }

    public function testDescansoContraCitaFuturaRechazaLosCincoDias(): void
    {
        $id = $this->profesional(); $this->grupo($id, 'horario'); $usuario = $this->usuario();
        $fecha = (new \DateTimeImmutable('next wednesday', new \DateTimeZone('America/Guayaquil')))->format('Y-m-d');
        $this->sql("INSERT INTO citas(fecha,hora,hora_inicio,hora_fin,duracion_total_minutos,usuarioId,profesionalId,estado) VALUES(?,'14:30:00','14:30:00','15:00:00',30,?,?,'confirmada')", 'sii', [$fecha, $usuario, $id]);
        $this->citas[] = (int)$this->db->insert_id; $antes = $this->horario($id);
        $this->assertSame('invalid', $this->grupo($id, 'descanso')['status']); $this->assertSame($antes, $this->horario($id));
    }

    public function testErrorAlGuardarUnDiaRevierteLasEscriturasPrevias(): void
    {
        $id = $this->profesional(); $this->grupo($id, 'horario'); $antes = $this->horario($id);
        $this->trigger = 'test_grupo_' . substr($this->tag, -10);
        $this->db->query("CREATE TRIGGER {$this->trigger} BEFORE INSERT ON descansos_profesionales FOR EACH ROW BEGIN IF NEW.profesionalId=$id AND NEW.dia_semana=4 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fallo de prueba'; END IF; END");
        $this->assertSame('error', $this->grupo($id, 'descanso')['status']); $this->assertSame($antes, $this->horario($id));
    }

    public function testFormularioDeGrupoExigeCsrfYResponde422ConDiasInvalidos(): void
    {
        $id = $this->profesional(); $this->auth($this->usuario('propietario', false)); $_GET = ['id' => (string)$id];
        $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = ['accion' => 'horario_grupo', 'dias' => ['2']];
        $this->assertSame(403, $this->pagina(fn() => ProfesionalController::horarios(new Router()))['status']);
        $_POST += ['csrf_token' => 'gestion-csrf', 'hora_inicio' => '09:00', 'hora_fin' => '18:00']; $_POST['dias'] = ['8'];
        $r = $this->pagina(fn() => ProfesionalController::horarios(new Router())); $this->assertSame(422, $r['status']);
        $this->assertSame([], $this->horario($id)['horarios']);
    }
}

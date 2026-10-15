<?php
namespace Tests\Integration;

require_once __DIR__ . '/../Support/LectorXlsx.php';

use AppTerminationException;
use Classes\Exportacion\Exportador;
use Controllers\AdminReporteController;
use Controllers\CrecimientoController;
use Model\ActiveRecord;
use MVC\Router;
use PHPUnit\Framework\TestCase;
use Repositories\ConsentimientoRepository;
use Repositories\ExportacionAuditoriaRepository;
use Services\ExportacionAuditoriaService;
use Tests\Support\LectorXlsx;

/** Ronda 5 (F): auditoría general (migración 026), reportes (csv/xlsx por bloque), contactos de crecimiento y su autorización en la descarga. */
final class R5ExportacionesIntegrationTest extends TestCase
{
    private \mysqli $db;
    private string $tag;
    private array $usuarios = [], $citas = [], $servicios = [], $profesionales = [];

    protected function setUp(): void
    {
        $this->db = new \mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', getenv('DB_NAME') ?: 'appsalon_test', (int)(getenv('DB_PORT') ?: 3306));
        $this->db->set_charset('utf8mb4');
        ActiveRecord::setDB($this->db);
        validar_base_datos_prueba($this->db);
        $this->tag = bin2hex(random_bytes(6));
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $_SESSION = []; $_GET = []; $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['HTTP_ACCEPT'] = 'text/html'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        AdminReporteController::setReporteService(null);
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        $this->db->query('DROP TRIGGER IF EXISTS trg_r5_exp_falla'); $this->db->query('DROP TRIGGER IF EXISTS trg_r5_exp_falla2');
        AdminReporteController::setAuditoriaService(null);
        foreach ($this->citas as $id) { $this->db->query('DELETE FROM citasservicios WHERE citaId=' . (int)$id); $this->db->query('DELETE FROM citas WHERE id=' . (int)$id); }
        foreach ($this->profesionales as $id) { $this->db->query('DELETE FROM profesionales WHERE id=' . (int)$id); }
        foreach ($this->servicios as $id) { $this->db->query('DELETE FROM servicios WHERE id=' . (int)$id); }
        foreach ($this->usuarios as $id) { $this->db->query('DELETE FROM usuarios WHERE id=' . (int)$id); }
        $_SESSION = []; $_GET = []; $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        http_response_code(200);
    }

    // ------------------------------------------------------------ fixtures
    private function sql(string $sql, string $types = '', array $params = []): \mysqli_stmt
    {
        $s = $this->db->prepare($sql); $this->assertNotFalse($s, $this->db->error);
        if ($types !== '') { $s->bind_param($types, ...$params); }
        $this->assertTrue($s->execute(), $s->error);
        return $s;
    }
    private function dia(int $o): string { return (new \DateTimeImmutable('now', new \DateTimeZone('America/Guayaquil')))->modify(($o >= 0 ? '+' : '') . $o . ' days')->format('Y-m-d'); }
    private function usuario(string $nombre, string $apellido, array $o = []): int
    {
        $email = substr(strtolower(preg_replace('/[^a-z0-9]/i', '', $nombre . $apellido)) . '.' . bin2hex(random_bytes(2)) . '@r5e' . $this->tag . '.test', -60);
        $this->sql('INSERT INTO usuarios(nombre,apellido,email,password,telefono,admin,confirmado) VALUES(?,?,?,?,?,0,?)', 'sssssi', [$nombre, $apellido, $email, password_hash('Prueba123!', PASSWORD_BCRYPT), '09' . random_int(10000000, 99999999), $o['confirmado'] ?? 1])->close();
        $id = (int)$this->db->insert_id; $this->usuarios[] = $id;
        if (isset($o['rol'])) { $this->sql('INSERT INTO usuario_accesos(usuario_id,rol,activo) VALUES(?,?,1)', 'is', [$id, $o['rol']])->close(); }
        else { $this->sql("INSERT INTO clientes(usuario_id,estado,origen,creado_en) VALUES(?,'activo','registro_publico',?)", 'is', [$id, $this->dia(-100) . ' 09:00:00'])->close(); }
        return $id;
    }
    private function sesion(int $id): void { $_SESSION = ['id' => $id, 'login' => true, 'version_sesion' => 1, 'csrf_token' => 'r5csrf']; }
    private function invocar(callable $fn): array
    {
        http_response_code(200); $termino = null; unset($GLOBALS['__appsalon_ultima_exportacion']);
        ob_start();
        try { $fn(); } catch (AppTerminationException $e) { $termino = $e->getStatusCode(); } catch (\Throwable $t) { ob_end_clean(); throw $t; }
        return ['http' => http_response_code(), 'termino' => $termino, 'cuerpo' => (string)ob_get_clean()];
    }
    private function reportes(array $get): array
    {
        $_GET = $get; $_SERVER['REQUEST_URI'] = '/admin/reportes/exportar';
        return $this->invocar(fn() => AdminReporteController::exportarCsv(new Router()));
    }
    private function auditoria(string $origen): array
    {
        return $this->db->query("SELECT * FROM exportaciones_auditoria_general WHERE origen LIKE '" . $this->db->real_escape_string($origen) . "%' ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    }
    /** Cita completada de un cliente con nombre hostil en el rango, con dos servicios. @return array{cita:int,cliente:int} */
    private function reserva(string $nombreCliente = '=HYPERLINK("http://x","a")'): array
    {
        $cli = $this->usuario('Zeta', 'Cliente ' . $this->tag);
        $this->sql('UPDATE usuarios SET nombre=? WHERE id=?', 'si', [$nombreCliente, $cli])->close();
        $this->sql('INSERT INTO profesionales(nombre,activo) VALUES(?,1)', 's', ['Pro ' . $this->tag])->close(); $prof = $this->profesionales[] = (int)$this->db->insert_id;
        $this->sql('INSERT INTO servicios(nombre,precio,duracion_minutos) VALUES(?,?,30)', 'ss', ['S1 ' . $this->tag, '12.34'])->close(); $s1 = $this->servicios[] = (int)$this->db->insert_id;
        $this->sql('INSERT INTO servicios(nombre,precio,duracion_minutos) VALUES(?,?,30)', 'ss', ['S2 ' . $this->tag, '7.66'])->close(); $s2 = $this->servicios[] = (int)$this->db->insert_id;
        $this->sql("INSERT INTO citas(fecha,hora,hora_inicio,hora_fin,duracion_total_minutos,usuarioId,profesionalId,estado,estado_pago) VALUES(?, '10:00:00','10:00:00','11:00:00',60,?,?,'completada','pendiente')", 'sii', [$this->dia(-1), $cli, $prof])->close();
        $cita = (int)$this->db->insert_id; $this->citas[] = $cita;
        foreach ([[$s1, '12.34'], [$s2, '7.66']] as [$sid, $pr]) { $this->sql("INSERT INTO citasservicios(citaId,servicioId,nombre_servicio,precio_servicio,duracion_minutos) VALUES(?,?,?,?,30)", 'iiss', [$cita, $sid, '=Servicio ' . $sid, $pr])->close(); }
        return ['cita' => $cita, 'cliente' => $cli, 'prof' => $prof];
    }
    private function rango(): array { return ['fecha_inicio' => $this->dia(-3), 'fecha_fin' => $this->dia(0), 'profesional_id' => ''] ; }

    // ------------------------------------------------------------ migración 026 y servicio
    public function testLaTablaEsDeSoloAnexado(): void
    {
        $svc = new ExportacionAuditoriaService(new ExportacionAuditoriaRepository($this->db));
        $id = $svc->registrar('prueba.append', 'csv', ['id' => null, 'rol' => null], null, ['resultado' => 'exitosa', 'filas' => 1]);
        $this->assertGreaterThan(0, $id);
        $this->assertFalse(@$this->db->query("UPDATE exportaciones_auditoria_general SET filas=9 WHERE id=$id"));
        $this->assertStringContainsString('solo anexado', $this->db->error);
        $this->assertFalse(@$this->db->query("DELETE FROM exportaciones_auditoria_general WHERE id=$id"));
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) n FROM exportaciones_auditoria_general WHERE id=$id")->fetch_assoc()['n']);
    }

    public function testRollbackDe026EsSeguroYElGuardianCoincide(): void
    {
        $bd = 'appsalon_c5_mig026_test';
        $root = new \mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', '', (int)(getenv('DB_PORT') ?: 3306));
        $root->query("DROP DATABASE IF EXISTS $bd"); $root->query("CREATE DATABASE $bd CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); $root->select_db($bd);
        $dir = __DIR__ . '/../../database/migrations/';
        $aplicar = function () use ($root, $dir): void {
            $this->assertTrue($root->multi_query((string)file_get_contents($dir . '026_exportaciones_auditoria_general.sql')), $root->error);
            while ($root->more_results() && $root->next_result()) { /* consumir */ }
        };
        try {
            $aplicar(); $aplicar();   // idempotente
            $guardian = require $dir . '026_exportaciones_auditoria_general.guard.php';
            $this->assertSame([], $guardian($root));
            $root->query("INSERT INTO exportaciones_auditoria_general(ocurrido_en,origen,formato,resultado) VALUES(UTC_TIMESTAMP(),'x','csv','exitosa')");
            $perdidas = $guardian($root);
            $this->assertCount(1, $perdidas); $this->assertSame('exportaciones_auditoria_general', $perdidas[0]['tabla']);
            // El rollback SQL ejecutado a mano también se niega (error 1242) y NO borra nada.
            mysqli_report(MYSQLI_REPORT_OFF);
            $ok = @$root->multi_query((string)file_get_contents($dir . '026_exportaciones_auditoria_general_rollback.sql'));
            if ($ok) { while ($root->more_results() && @$root->next_result()) { /* consumir */ } }
            $this->assertSame(1242, $root->errno, 'la guarda escalar debe abortar el rollback con datos: ' . $root->error);
            $this->assertSame(1, (int)$root->query('SELECT COUNT(*) n FROM exportaciones_auditoria_general')->fetch_assoc()['n']);
        } finally {
            $root->query("DROP DATABASE IF EXISTS $bd");
        }
        // Sin datos el rollback sí se ejecuta
        $root->query("CREATE DATABASE $bd CHARACTER SET utf8mb4"); $root->select_db($bd);
        try {
            $aplicar();
            $root->multi_query((string)file_get_contents($dir . '026_exportaciones_auditoria_general_rollback.sql'));
            while ($root->more_results() && $root->next_result()) { /* consumir */ }
            $this->assertSame(0, $root->query("SHOW TABLES LIKE 'exportaciones_auditoria_general'")->num_rows);
        } finally {
            $root->query("DROP DATABASE IF EXISTS $bd"); $root->close();
        }
    }

    public function testServicioGuardaMetadatosSinDatosPersonalesNiTokens(): void
    {
        $svc = new ExportacionAuditoriaService(new ExportacionAuditoriaRepository($this->db));
        $origen = 'prueba.' . substr($this->tag, 0, 6);
        $svc->registrar($origen, 'xlsx', ['id' => 7, 'rol' => 'gerente'], ['q' => 'maria@correo.com', 'cliente' => 'María Pérez', 'desde' => '2026-01-01', 'csrf_token' => 'SECRETO', 'api_key' => 'SECRETO2', 'estado' => ['x'], 'n' => 5, 'ok' => true],
            ['resultado' => 'exitosa', 'filas' => 12, 'columnas' => ['A', 'B'], 'truncado' => true, 'bytes' => 999, 'sha256' => str_repeat('a', 64)]);
        $f = $this->auditoria($origen)[0];
        $json = (string)$f['filtros_json'];
        foreach (['maria', 'María', 'SECRETO', 'csrf', 'api_key'] as $malo) { $this->assertStringNotContainsStringIgnoringCase($malo, $json . json_encode($f)); }
        $filtros = json_decode($json, true);
        $claves = array_keys($filtros); sort($claves);   // MySQL normaliza el orden de las claves de una columna JSON
        $this->assertSame(['cliente_hmac', 'cliente_longitud', 'cliente_presente', 'desde', 'n', 'ok', 'q_hmac', 'q_longitud', 'q_presente'], $claves);
        $this->assertSame(16, $filtros['q_longitud']);
        $this->assertSame(['xlsx', 'exitosa', 12, 2, 1, 999], [$f['formato'], $f['resultado'], (int)$f['filas'], (int)$f['columnas_n'], (int)$f['truncado'], (int)$f['bytes_entregados']]);
        $this->assertSame(['id' => '7', 'rol' => 'gerente'], ['id' => $f['actor_usuario_id'], 'rol' => $f['actor_rol']]);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $f['ip_hash']);
    }

    public function testDenegacionesRepetidasSeAgrupanOrigenYResultadoInvalidosSeRechazan(): void
    {
        $svc = new ExportacionAuditoriaService(new ExportacionAuditoriaRepository($this->db));
        $origen = 'prueba.den' . substr($this->tag, 0, 5);
        for ($i = 0; $i < 5; $i++) { $svc->registrar($origen, 'csv', ['id' => 99, 'rol' => 'recepcion'], null, ['resultado' => 'denegada', 'motivo' => 'sin_permiso']); }
        $this->assertCount(1, $this->auditoria($origen), 'misma denegación = una fila en la ventana');
        $svc->registrar($origen, 'pdf', ['id' => 100, 'rol' => 'recepcion'], null, ['resultado' => 'fallida', 'motivo' => 'formato_invalido']);
        $this->assertSame('invalido', $this->auditoria($origen)[1]['formato']);
        foreach (['Mal Origen', '', str_repeat('a', 41), "x'; DROP"] as $mal) {
            try { $svc->registrar($mal, 'csv', ['id' => 1, 'rol' => null], null, ['resultado' => 'exitosa']); $this->fail("origen «$mal»"); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        $this->expectException(\InvalidArgumentException::class);
        $svc->registrar('prueba.x', 'csv', ['id' => 1, 'rol' => null], null, ['resultado' => 'quizas']);
    }

    // ------------------------------------------------------------ reportes
    public function testReportesXlsxYCsvDeReservasCoincidenConLaPantallaYSeAuditan(): void
    {
        $r = $this->reserva();
        $dueno = $this->usuario('Jefe', 'Dueno ' . $this->tag, ['rol' => 'propietario']); $this->sesion($dueno);
        $get = $this->rango() + ['bloque' => 'reservas'];
        $x = $this->reportes($get + ['formato' => 'xlsx']); $c = $this->reportes($get + ['formato' => 'csv']);
        $this->assertSame(200, $x['termino']); $this->assertSame(200, $c['termino']);
        $this->assertStringStartsWith('PK', $x['cuerpo']);
        $filasX = LectorXlsx::filas($x['cuerpo']); $cab = array_shift($filasX);
        $totalX = array_pop($filasX);
        $propias = array_values(array_filter($filasX, fn($f) => (string)$f[0] === (string)$r['cita']));
        $this->assertCount(1, $propias);
        $fila = array_combine($cab, $propias[0]);
        $this->assertStringStartsWith('=HYPERLINK("http://x","a")', $fila['Cliente'], 'XLSX conserva el texto como cadena (nunca fórmula)');
        $this->assertSame('20.00', $fila['Importe Total Cita (USD)']);
        $this->assertStringNotContainsString('<f>', LectorXlsx::partes($x['cuerpo'])['xl/worksheets/sheet1.xml']);
        // CSV: mismas filas, neutralizado
        $cuerpo = $c['cuerpo']; $this->assertStringStartsWith("\xEF\xBB\xBF", $cuerpo);
        $this->assertStringContainsString("\"'=HYPERLINK", $cuerpo);
        $this->assertStringContainsString('Total', $cuerpo);
        // totales del archivo = totales de la pantalla (misma función)
        $svc = new \Services\ReporteService(new \Repositories\ReporteRepository($this->db));
        $rep = $svc->consultarReporte($this->rango());
        $t = $svc->totalesBloque($rep, 'reservas');
        $this->assertSame($t['n'] . ' reservas', $totalX[array_search('Cliente', $cab, true)]);
        $this->assertSame(number_format($t['reservado'] / 100, 2, '.', ''), $totalX[array_search('Importe Reservado Filtrado (USD)', $cab, true)]);
        $this->assertSame(count($rep['reservas']), count($filasX));
        // auditoría
        $a = array_values(array_filter($this->auditoria('reportes.reservas'), fn($f) => (string)$f['actor_usuario_id'] === (string)$dueno));
        $this->assertSame(['xlsx', 'csv'], array_column($a, 'formato'));
        $this->assertSame(hash('sha256', $x['cuerpo']), $a[0]['contenido_sha256']);
        $this->assertSame(hash('sha256', $c['cuerpo']), $a[1]['contenido_sha256']);
        $this->assertSame((string)count($rep['reservas']), $a[0]['filas']);
        $this->assertSame((string)$dueno, $a[0]['actor_usuario_id']); $this->assertSame('propietario', $a[0]['actor_rol']);
        $this->assertSame($cab, json_decode($a[0]['columnas_lista'], true));
        $this->assertStringContainsString('"fecha_inicio"', (string)$a[0]['filtros_json']);
        $this->assertStringNotContainsString('Cliente ' . $this->tag, json_encode($a));
    }

    public function testReporteCompletoSigueSiendoCsvMultiseccionYNoSeOfreceEnExcel(): void
    {
        $this->reserva(); $this->sesion($this->usuario('Jefe', 'Dueno ' . $this->tag, ['rol' => 'propietario']));
        $c = $this->reportes($this->rango());   // sin formato ni bloque: comportamiento de siempre
        $this->assertSame(200, $c['termino']);
        $this->assertStringContainsString('REPORTE ADMINISTRATIVO DE RESERVAS Y OCUPACION - APPSALON', $c['cuerpo']);
        $this->assertStringContainsString('LISTADO DETALLADO DE RESERVAS', $c['cuerpo']);
        $this->assertSame('text/csv; charset=UTF-8', $GLOBALS['__appsalon_ultima_exportacion']['cabeceras']['Content-Type']);
        $x = $this->reportes($this->rango() + ['formato' => 'xlsx', 'bloque' => 'completo']);
        $this->assertSame(422, $x['http']); $this->assertStringContainsString('varias secciones', $x['cuerpo']); $this->assertArrayNotHasKey('__appsalon_ultima_exportacion', $GLOBALS);
        $mov = $this->reportes($this->rango() + ['formato' => 'xlsx', 'bloque' => 'movimientos']);
        $this->assertSame(200, $mov['termino']); $this->assertStringStartsWith('PK', $mov['cuerpo']);
    }

    public function testReportesFormatoYBloqueInvalidosSon422Auditados(): void
    {
        $dueno = $this->usuario('Jefe', 'Dueno ' . $this->tag, ['rol' => 'propietario']); $this->sesion($dueno);
        foreach ([['formato' => 'pdf'], ['formato' => ['csv']], ['formato' => 'csv', 'bloque' => 'todo'], ['formato' => 'csv', 'bloque' => "x'--"]] as $extra) {
            $r = $this->reportes($this->rango() + $extra);
            $this->assertSame(422, $r['http'], json_encode($extra)); $this->assertStringNotContainsString("x'--", $r['cuerpo']);
        }
        $a = array_values(array_filter($this->auditoria('reportes.'), fn($f) => (string)$f['actor_usuario_id'] === (string)$dueno));
        $this->assertCount(4, $a);   // las fallidas no se agrupan: cada intento queda anotado
        foreach ($a as $f) { $this->assertSame('fallida', $f['resultado']); }
    }

    public function testReportesDescargaDirectaSinPermisoEs403ConAuditoriaYSinArchivo(): void
    {
        $this->reserva();
        $recep = $this->usuario('Rosa', 'Recep ' . $this->tag, ['rol' => 'recepcion']); $this->sesion($recep);
        $r = $this->reportes($this->rango() + ['formato' => 'xlsx', 'bloque' => 'reservas']);
        $this->assertSame(403, $r['http']); $this->assertStringNotContainsString('PK', substr($r['cuerpo'], 0, 2)); $this->assertArrayNotHasKey('__appsalon_ultima_exportacion', $GLOBALS);
        $a = array_values(array_filter($this->auditoria('reportes.'), fn($f) => (string)$f['actor_usuario_id'] === (string)$recep));
        $this->assertCount(1, $a); $this->assertSame('denegada', $a[0]['resultado']); $this->assertSame('sin_permiso', $a[0]['motivo']); $this->assertSame('recepcion', $a[0]['actor_rol']);
        // anónimo: no hay persona que auditar
        $_SESSION = []; $antes = count($this->auditoria('reportes.'));
        try { $this->reportes($this->rango()); } catch (\Throwable) { /* redirección */ }
        $this->assertSame($antes, count($this->auditoria('reportes.')));
    }

    public function testFinanzasNoRecibeDatosPersonalesDeClientesEnElArchivo(): void
    {
        $r = $this->reserva('Persona Reservada');
        $fin = $this->usuario('Fina', 'Finanzas ' . $this->tag, ['rol' => 'finanzas']); $this->sesion($fin);
        $x = $this->reportes($this->rango() + ['formato' => 'xlsx', 'bloque' => 'reservas']);
        $this->assertSame(200, $x['termino']);
        $hoja = LectorXlsx::partes($x['cuerpo'])['xl/worksheets/sheet1.xml'];
        $this->assertStringNotContainsString('Persona Reservada', $hoja); $this->assertStringNotContainsString('@r5e', $hoja); $this->assertStringContainsString('Cliente reservado', $hoja);
        $c = $this->reportes($this->rango());
        $this->assertStringNotContainsString('@r5e' . $this->tag, $c['cuerpo']);
    }

    public function testSiFallaLaAuditoriaElReporteNoSale(): void
    {
        $this->reserva(); $this->sesion($this->usuario('Jefe', 'Dueno ' . $this->tag, ['rol' => 'propietario']));
        $this->db->query("CREATE TRIGGER trg_r5_exp_falla BEFORE INSERT ON exportaciones_auditoria_general FOR EACH ROW SIGNAL SQLSTATE '45001' SET MESSAGE_TEXT='rota'");
        foreach ([['formato' => 'xlsx', 'bloque' => 'reservas'], ['formato' => 'csv']] as $extra) {
            try { $this->reportes($this->rango() + $extra); $this->fail('debió responder 503'); }
            catch (\Classes\ServiceUnavailableException) { $this->addToAssertionCount(1); }
            $this->assertArrayNotHasKey('__appsalon_ultima_exportacion', $GLOBALS);
        }
    }

    // ------------------------------------------------------------ crecimiento
    private function crecimiento(array $post): array
    {
        $_SERVER['REQUEST_METHOD'] = 'POST'; $_SERVER['REQUEST_URI'] = '/admin/crecimiento';
        $_POST = $post + ['csrf_token' => 'r5csrf', 'accion' => 'segmento'];
        return $this->invocar(fn() => CrecimientoController::index(new Router()));
    }
    private function audCampana(int $actor): array
    {
        return $this->db->query('SELECT * FROM crm_exportaciones_auditoria WHERE tipo=\'campana\' AND actor_usuario_id=' . $actor . ' ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    }

    public function testContactosDeCrecimientoEnCsvYXlsxConAuditoriaPreviaYAutorizacionEnLaDescarga(): void
    {
        $uno = $this->usuario('Acepta', 'Uno ' . $this->tag); $dos = $this->usuario('=Acepta', 'Dos ' . $this->tag); $no = $this->usuario('Nunca', 'Tres ' . $this->tag);
        (new ConsentimientoRepository($this->db))->aplicarDecision($uno, true, null, ['origen' => 'perfil', 'canal' => 'portal_cliente']);
        (new ConsentimientoRepository($this->db))->aplicarDecision($dos, true, null, ['origen' => 'perfil', 'canal' => 'portal_cliente']);
        $ger = $this->usuario('Ger', 'Ente ' . $this->tag, ['rol' => 'gerente']); $this->sesion($ger);
        $csv = $this->crecimiento(['formato' => 'csv', 'segmento' => 'todos']); $xls = $this->crecimiento(['formato' => 'xlsx', 'segmento' => 'todos']);
        $this->assertSame(200, $csv['http']);
        $ids = function (array $filas): array { return array_values(array_filter(array_column($filas, 0), fn($i) => in_array((int)$i, $this->usuarios, true))); };
        $filasX = LectorXlsx::filas($xls['cuerpo']); array_shift($filasX);
        $this->assertEqualsCanonicalizing([(string)$uno, (string)$dos], $ids($filasX));
        $this->assertNotContains((string)$no, $ids($filasX));
        $this->assertStringContainsString("'=Acepta", $csv['cuerpo']);
        $a = $this->audCampana($ger);
        $this->assertSame(['csv', 'xlsx'], array_column($a, 'formato'));
        $this->assertSame(hash('sha256', $csv['cuerpo']), $a[0]['contenido_sha256']); $this->assertSame(hash('sha256', $xls['cuerpo']), $a[1]['contenido_sha256']);
        $this->assertSame('solo_version_vigente', $a[0]['consentimiento_politica']);
        // formato inválido: 422, auditado, sin archivo
        $mal = $this->crecimiento(['formato' => 'xls', 'segmento' => 'todos']);
        $this->assertSame(422, $mal['http']); $this->assertStringNotContainsString("\xEF\xBB\xBF", $mal['cuerpo']);
        $todas = $this->audCampana($ger);
        $this->assertSame('formato_invalido', end($todas)['motivo']);
        // sin permiso: 403 + anotado, aunque conozca el formulario
        $rec = $this->usuario('Rosa', 'Recep ' . $this->tag, ['rol' => 'recepcion']); $this->sesion($rec);
        $r = $this->crecimiento(['formato' => 'xlsx', 'segmento' => 'todos']);
        $this->assertSame(403, $r['http']); $this->assertStringNotContainsString('PK', substr($r['cuerpo'], 0, 2));
        $d = $this->audCampana($rec);
        $this->assertCount(1, $d); $this->assertSame('denegada', $d[0]['resultado']); $this->assertSame('xlsx', $d[0]['formato']);
    }

    public function testSiFallaLaAuditoriaLosContactosNoSalen(): void
    {
        $uno = $this->usuario('Acepta', 'Uno ' . $this->tag);
        (new ConsentimientoRepository($this->db))->aplicarDecision($uno, true, null, ['origen' => 'perfil', 'canal' => 'portal_cliente']);
        $this->sesion($this->usuario('Ger', 'Ente ' . $this->tag, ['rol' => 'gerente']));
        $this->db->query("CREATE TRIGGER trg_r5_exp_falla BEFORE INSERT ON crm_exportaciones_auditoria FOR EACH ROW SIGNAL SQLSTATE '45001' SET MESSAGE_TEXT='rota'");
        try { $this->crecimiento(['formato' => 'xlsx', 'segmento' => 'todos']); $this->fail('debió responder 503'); }
        catch (\Classes\ServiceUnavailableException) { $this->addToAssertionCount(1); }
        $this->db->query('DROP TRIGGER IF EXISTS trg_r5_exp_falla'); $this->db->query('DROP TRIGGER IF EXISTS trg_r5_exp_falla2');
        $this->assertArrayNotHasKey('__appsalon_ultima_exportacion', $GLOBALS);
    }
}

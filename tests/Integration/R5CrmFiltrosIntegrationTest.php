<?php
namespace Tests\Integration;

require_once __DIR__ . '/../Support/LectorXlsx.php';

use AppTerminationException;
use Classes\Exportacion\Exportador;
use Controllers\CrmController;
use Model\ActiveRecord;
use MVC\Router;
use PHPUnit\Framework\TestCase;
use Repositories\ConsentimientoRepository;
use Repositories\CrmRepository;
use Services\CrmService;
use Tests\Support\LectorXlsx;

/**
 * Ronda 5 (D, E, F) sobre MySQL real: filtros del CRM, UN SOLO universo (lista = conteo = indicadores = segmentos = totales = archivo),
 * «Listado» por indicador, descargas CSV/XLSX con los mismos registros y totales, autorización en la descarga y auditoría.
 * Cada prueba crea sus datos con una etiqueta única y los borra.
 */
final class R5CrmFiltrosIntegrationTest extends TestCase
{
    private \mysqli $db;
    private string $tag;
    private array $usuarios = [], $citas = [], $servicios = [], $profesionales = [], $pagos = [];
    private const FULL = ['ver' => true, 'analitica' => true, 'propios' => false, 'exportar' => true];
    private const RECEPCION = ['ver' => true, 'analitica' => false, 'propios' => false, 'exportar' => false];
    private const SOLO_ANALITICA = ['ver' => false, 'analitica' => true, 'propios' => false, 'exportar' => false];

    protected function setUp(): void
    {
        $this->db = new \mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', getenv('DB_NAME') ?: 'appsalon_test', (int)(getenv('DB_PORT') ?: 3306));
        $this->db->set_charset('utf8mb4');
        ActiveRecord::setDB($this->db);
        validar_base_datos_prueba($this->db);
        $this->tag = bin2hex(random_bytes(8));
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $_SESSION = []; $_GET = []; $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['HTTP_ACCEPT'] = 'text/html'; $_SERVER['REQUEST_URI'] = '/admin/clientes';
        CrmController::setCrmService(null);
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        CrmController::setCrmService(null);
        $this->db->query('DROP TRIGGER IF EXISTS trg_r5_audit_falla');
        if ($this->db->ping()) {
            foreach ($this->pagos as $id) { $this->db->query('DELETE FROM pagos WHERE id=' . (int)$id); }
            foreach ($this->citas as $id) { $this->db->query('DELETE FROM citasservicios WHERE citaId=' . (int)$id); $this->db->query('DELETE FROM citas WHERE id=' . (int)$id); }
            foreach ($this->profesionales as $id) { $this->db->query('DELETE FROM profesionales WHERE id=' . (int)$id); }
            foreach ($this->servicios as $id) { $this->db->query('DELETE FROM servicios WHERE id=' . (int)$id); }
            foreach ($this->usuarios as $id) { $this->db->query('DELETE FROM usuarios WHERE id=' . (int)$id); }
        }
        $_SESSION = []; $_GET = []; $_POST = [];
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
    private function dia(int $o): string { return (new \DateTimeImmutable('now', new \DateTimeZone('America/Guayaquil')))->setTime(0, 0)->modify(($o >= 0 ? '+' : '') . $o . ' days')->format('Y-m-d'); }

    private function usuario(string $nombre, string $apellido, array $o = []): int
    {
        $email = substr(strtolower(preg_replace('/[^a-z0-9]/i', '', $nombre . $apellido)) . '.' . bin2hex(random_bytes(2)) . '@r5' . $this->tag . '.test', -60);
        $tel = $o['telefono'] ?? ('09' . str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT));
        $this->sql('INSERT INTO usuarios(nombre,apellido,email,password,telefono,admin,confirmado) VALUES(?,?,?,?,?,?,?)', 'sssssii',
            [$nombre, $apellido, $email, password_hash('PruebaSegura123!', PASSWORD_BCRYPT), $tel, $o['admin'] ?? 0, $o['confirmado'] ?? 1])->close();
        $id = (int)$this->db->insert_id; $this->usuarios[] = $id;
        if (isset($o['rol'])) { $this->sql('INSERT INTO usuario_accesos(usuario_id,rol,activo) VALUES(?,?,1)', 'is', [$id, $o['rol']])->close(); }
        if (array_key_exists('membresia', $o) ? $o['membresia'] !== null : !isset($o['rol'])) {
            $this->sql('INSERT INTO clientes(usuario_id,estado,origen,creado_en) VALUES(?,?,?,?)', 'isss', [$id, $o['membresia'] ?? 'activo', 'registro_publico', $o['alta'] ?? ($this->dia(-400) . ' 09:00:00')])->close();
        }
        return $id;
    }
    private function decidir(int $uid, bool $acepta): void
    {
        (new ConsentimientoRepository($this->db))->aplicarDecision($uid, $acepta, null, ['origen' => 'perfil', 'canal' => 'portal_cliente']);
    }
    private function servicio(string $precio = '20.00'): int
    {
        $this->sql('INSERT INTO servicios(nombre,precio,duracion_minutos) VALUES(?,?,30)', 'ss', ['Srv ' . $this->tag, $precio])->close();
        return $this->servicios[] = (int)$this->db->insert_id;
    }
    private function profesional(string $n): int
    {
        $this->sql('INSERT INTO profesionales(nombre,activo) VALUES(?,1)', 's', [$n . ' ' . $this->tag])->close();
        return $this->profesionales[] = (int)$this->db->insert_id;
    }
    private function cita(int $u, string $fecha, string $estado, ?int $prof, int $srv, string $precio = '20.00', string $estadoPago = 'pendiente'): int
    {
        $this->sql('INSERT INTO citas(fecha,hora,usuarioId,profesionalId,estado,estado_pago) VALUES(?,?,?,?,?,?)', 'ssiiss', [$fecha, '10:00:00', $u, $prof, $estado, $estadoPago])->close();
        $id = (int)$this->db->insert_id; $this->citas[] = $id;
        $this->sql("INSERT INTO citasservicios(citaId,servicioId,nombre_servicio,precio_servicio,duracion_minutos) VALUES(?,?,'Snap',?,30)", 'iis', [$id, $srv, $precio])->close();
        return $id;
    }
    private function pago(int $cita, int $u, string $monto, string $estado = 'completado', string $proveedor = 'manual'): void
    {
        $ref = 'R5-' . $this->tag . '-' . bin2hex(random_bytes(4));
        $this->sql("INSERT INTO pagos(cita_id,cita_referencia_id,usuario_id,referencia_pago,proveedor,monto,moneda,estado,metodo_pago,idempotency_key,pagado_at) VALUES(?,?,?,?,?,?,'USD',?,'efectivo',?,?)",
            'iiissssss', [$cita, $cita, $u, $ref, $proveedor, $monto, $estado, 'k-' . $ref, $this->dia(-3) . ' 10:00:00'])->close();
        $this->pagos[] = (int)$this->db->insert_id;
    }

    /**
     * Población determinista (todos con apellido «… {tag}»). Devuelve ids por clave:
     *  a  confirmada · activa · consentimiento OTORGADO · 2 visitas en el periodo con P1 (última hace 5 d) · próxima cita en 3 d · cobró 5.00 de 20.00 (saldo 15.00)
     *  b  SIN CONFIRMAR · SUSPENDIDA · sin decisión · nunca atendida
     *  c  confirmada · activa · REVOCADO · 1 visita hace 200 d con P2 (inactiva) · pagada completa 30.00
     *  d  confirmada · cuenta legada SIN registro · sin decisión · 1 visita hace 20 d con P1 · sin saldo
     */
    private function poblacion(): array
    {
        $p1 = $this->profesional('P1'); $p2 = $this->profesional('P2'); $srv = $this->servicio();
        $a = $this->usuario('Ana', 'Alfa ' . $this->tag); $b = $this->usuario('Beto', 'Beta ' . $this->tag, ['confirmado' => 0, 'membresia' => 'suspendido', 'alta' => $this->dia(-10) . ' 09:00:00']);
        $c = $this->usuario('Cata', 'Gama ' . $this->tag); $d = $this->usuario('Dani', 'Delta ' . $this->tag, ['membresia' => null]);
        $this->decidir($a, true);
        $this->decidir($c, true); $this->decidir($c, false);
        $this->cita($a, $this->dia(-5), 'completada', $p1, $srv, '20.00', 'pagado'); 
        $citaSaldo = $this->cita($a, $this->dia(-40), 'completada', $p1, $srv, '20.00', 'pendiente'); $this->pago($citaSaldo, $a, '5.00');
        $this->cita($a, $this->dia(3), 'confirmada', $p1, $srv);
        $cc = $this->cita($c, $this->dia(-200), 'completada', $p2, $srv, '30.00', 'pagado'); $this->pago($cc, $c, '30.00');
        $this->cita($d, $this->dia(-20), 'completada', $p1, $srv, '20.00', 'pagado');
        return compact('a', 'b', 'c', 'd', 'p1', 'p2', 'srv');
    }

    private function servicioCrm(): CrmService { return new CrmService(new CrmRepository($this->db)); }
    private function filtros(array $get, array $acceso = self::FULL): array
    {
        $s = $this->servicioCrm(); $v = $s->normalizarFiltros(array_merge(['q' => $this->tag, 'por_pagina' => '100'], $get), $acceso, $s->catalogos());
        $this->assertSame([], $v['errores'], implode(' | ', $v['errores']));
        return $v['filtros'];
    }
    /** @return int[] ids de la población que cumplen los filtros, ordenados */
    private function ids(array $get, array $acceso = self::FULL): array
    {
        $d = $this->servicioCrm()->listado($this->filtros($get, $acceso), $acceso);
        $ids = array_map(static fn($r) => (int)$r['id'], $d['listado']['filas']); sort($ids); return $ids;
    }
    private function esperados(array $p, string ...$claves): array { $r = array_map(static fn($k) => $p[$k], $claves); sort($r); return $r; }

    // ------------------------------------------------------------ D: cada filtro
    public function testCuentaMembresiaYComunicaciones(): void
    {
        $p = $this->poblacion();
        $this->assertSame($this->esperados($p, 'b'), $this->ids(['cuenta' => 'sin_confirmar']));
        $this->assertSame($this->esperados($p, 'a', 'c', 'd'), $this->ids(['cuenta' => 'confirmada']));
        $this->assertSame($this->esperados($p, 'b'), $this->ids(['membresia' => 'suspendido']));
        $this->assertSame($this->esperados($p, 'a', 'c'), $this->ids(['membresia' => 'activo']));
        $this->assertSame($this->esperados($p, 'd'), $this->ids(['membresia' => 'sin_registro']));
        $this->assertSame($this->esperados($p, 'a'), $this->ids(['consentimiento' => 'si']));
        $this->assertSame($this->esperados($p, 'a'), $this->ids(['consentimiento' => 'vigente']));
        $this->assertSame([], $this->ids(['consentimiento' => 'anterior']));
        $this->assertSame($this->esperados($p, 'b', 'c', 'd'), $this->ids(['consentimiento' => 'no']));
        $this->assertSame($this->esperados($p, 'c'), $this->ids(['consentimiento' => 'revocado']), 'revocado = su última decisión fue retirarlo');
        $this->assertSame($this->esperados($p, 'b', 'd'), $this->ids(['consentimiento' => 'sin_decision']), 'sin decisión = nunca decidió');
        // revocado + sin decisión + otorgado = todos, sin solaparse
        $this->assertSame($this->esperados($p, 'a', 'b', 'c', 'd'), $this->ids([]));
    }

    public function testQuienRevocaYVuelveAAceptarEstaOtorgadoNoRevocado(): void
    {
        $p = $this->poblacion();
        $this->decidir($p['c'], true);
        $this->assertSame($this->esperados($p, 'a', 'c'), $this->ids(['consentimiento' => 'si']));
        $this->assertSame([], $this->ids(['consentimiento' => 'revocado']));
    }

    public function testTextoAnteriorDistingueVersionesDeConsentimiento(): void
    {
        $p = $this->poblacion();
        $v2 = (new ConsentimientoRepository($this->db))->publicar('Texto nuevo ' . $this->tag);
        try {
            $this->assertSame([], $this->ids(['consentimiento' => 'vigente']), 'tras publicar un texto nuevo nadie tiene el vigente');
            $this->assertSame($this->esperados($p, 'a'), $this->ids(['consentimiento' => 'anterior']));
            $this->assertSame($this->esperados($p, 'a'), $this->ids(['consentimiento' => 'si']));
        } finally {
            $this->db->query('SET @x=1'); // las versiones son inmutables (trigger): la fila queda como versión posterior; las pruebas no dependen de ella
        }
        $this->assertGreaterThan(1, (int)$v2['version']);
    }

    public function testActividadComercialRecienteInactivoYNunca(): void
    {
        $p = $this->poblacion();
        $this->assertSame($this->esperados($p, 'a', 'd'), $this->ids(['actividad' => 'reciente', 'dias_activo' => '90']));
        $this->assertSame($this->esperados($p, 'c'), $this->ids(['actividad' => 'inactivo', 'dias_activo' => '90']));
        $this->assertSame($this->esperados($p, 'b'), $this->ids(['actividad' => 'nunca']));
    }

    public function testLimiteExactoDeLaVentanaDeActividad(): void
    {
        $p = $this->poblacion();
        // d tiene su última visita hace exactamente 20 días: con ventana de 30 es reciente; el límite 20 no existe (mín. 30), se prueba con a (5 d) y c (200 d).
        $this->assertSame($this->esperados($p, 'a', 'd'), $this->ids(['actividad' => 'reciente', 'dias_activo' => '30']));
        $this->assertSame($this->esperados($p, 'c'), $this->ids(['actividad' => 'inactivo', 'dias_activo' => '199']), 'c (hace 200 d) es inactiva con ventana de 199');
        $this->assertSame([], $this->ids(['actividad' => 'inactivo', 'dias_activo' => '200']), 'límite exacto: con ventana de 200 días, hace 200 días todavía es reciente');
        $this->assertSame([], $this->ids(['actividad' => 'inactivo', 'dias_activo' => '365']), 'a 365 días c (200 d) sigue reciente');
    }

    public function testFechasDeUltimaVisitaYProximaCita(): void
    {
        $p = $this->poblacion();
        $this->assertSame($this->esperados($p, 'a'), $this->ids(['ultima_desde' => $this->dia(-5), 'ultima_hasta' => $this->dia(-5)]), 'límites inclusivos');
        $this->assertSame($this->esperados($p, 'a', 'd'), $this->ids(['ultima_desde' => $this->dia(-30)]));
        $this->assertSame($this->esperados($p, 'c'), $this->ids(['ultima_hasta' => $this->dia(-100)]));
        $this->assertSame($this->esperados($p, 'a'), $this->ids(['proxima' => 'con']));
        $this->assertSame($this->esperados($p, 'b', 'c', 'd'), $this->ids(['proxima' => 'sin']));
        $this->assertSame($this->esperados($p, 'a'), $this->ids(['proxima_desde' => $this->dia(3), 'proxima_hasta' => $this->dia(3)]));
        $this->assertSame([], $this->ids(['proxima_desde' => $this->dia(4)]));
    }

    public function testFrecuenciaEnElPeriodoProfesionalYServicio(): void
    {
        $p = $this->poblacion();
        $this->assertSame($this->esperados($p, 'a'), $this->ids(['visitas_min' => '2']));
        $this->assertSame($this->esperados($p, 'a', 'c', 'd'), $this->ids(['visitas_min' => '1']));
        $this->assertSame($this->esperados($p, 'b'), $this->ids(['visitas_max' => '0']), 'máximo 0 = sin visitas en el periodo');
        $this->assertSame($this->esperados($p, 'a', 'd'), $this->ids(['profesional_id' => (string)$p['p1']]));
        $this->assertSame($this->esperados($p, 'c'), $this->ids(['profesional_id' => (string)$p['p2']]));
        $this->assertSame($this->esperados($p, 'a'), $this->ids(['profesional_id' => (string)$p['p1'], 'visitas_min' => '2']), 'frecuencia y profesional se combinan');
        // el periodo acota la frecuencia: c (hace 200 d) no cuenta en los últimos 30 días
        $this->assertSame($this->esperados($p, 'a', 'd'), $this->ids(['desde' => $this->dia(-30), 'hasta' => $this->dia(0), 'visitas_min' => '1']));
    }

    public function testGastoYSaldoSoloConAnaliticaYConSuDefinicion(): void
    {
        $p = $this->poblacion();
        $this->assertSame($this->esperados($p, 'c'), $this->ids(['gasto_min' => '30']), 'c pagó 30.00');
        $this->assertSame($this->esperados($p, 'c'), $this->ids(['gasto_min' => '30,00']), 'se acepta coma decimal');
        $this->assertSame($this->esperados($p, 'a'), $this->ids(['gasto_min' => '5', 'gasto_max' => '5.00']), 'límites inclusivos en centavos');
        $this->assertSame($this->esperados($p, 'b', 'd'), $this->ids(['gasto_max' => '0']));
        $this->assertSame($this->esperados($p, 'a'), $this->ids(['saldo' => 'con']), 'a debe 15.00: 20.00 menos 5.00 cobrados');
        $this->assertSame($this->esperados($p, 'b', 'c', 'd'), $this->ids(['saldo' => 'sin']));
        $f = $this->filtros([]);
        $c = ['hoy' => $this->dia(0), 'desde' => $f['desde'], 'hasta' => $this->dia(0), 'dias_activo' => 90, 'dias_nuevo' => 30, 'vip_ids' => [], 'analitica' => true];
        $fila = array_column((new CrmRepository($this->db))->listar($c, $f + [], 50, 0), null, 'id');
        $this->assertSame(1500, (int)$fila[$p['a']]['saldo']);
        $this->assertSame(1, (int)$fila[$p['a']]['citas_saldo']);
        $this->assertSame(0, (int)$fila[$p['c']]['saldo']);
    }

    public function testElSaldoNoSeDescuentaConLaPasarelaSimulada(): void
    {
        $p = $this->poblacion();
        $srv = $this->servicio();
        $cita = $this->cita($p['d'], $this->dia(-2), 'completada', $p['p1'], $srv, '40.00', 'pendiente');
        $this->pago($cita, $p['d'], '40.00', 'completado', 'mock');
        $this->assertSame($this->esperados($p, 'a', 'd'), $this->ids(['saldo' => 'con']), 'un pago simulado no es dinero cobrado');
    }

    public function testCombinacionesYBordes(): void
    {
        $p = $this->poblacion();
        $this->assertSame($this->esperados($p, 'a'), $this->ids(['cuenta' => 'confirmada', 'membresia' => 'activo', 'consentimiento' => 'si', 'actividad' => 'reciente', 'proxima' => 'con', 'saldo' => 'con', 'visitas_min' => '2']));
        $this->assertSame([], $this->ids(['cuenta' => 'sin_confirmar', 'consentimiento' => 'si']), 'combinaciones vacías no fallan');
        $this->assertSame($this->esperados($p, 'a'), $this->ids(['q' => 'alfa ' . $this->tag]), 'la búsqueda por apellido sigue funcionando junto a los filtros');
        // la búsqueda con comodines SQL no amplía el resultado
        $this->assertSame([], $this->ids(['q' => '%']), '«%» se trata como texto, no como comodín');
        $this->assertSame([], $this->ids(['q' => "x' OR '1'='1"]));
    }

    // ------------------------------------------------------------ validación (422, sin reflejar)
    public function testValoresFueraDeCatalogoYEntradasHostilesSeRechazan(): void
    {
        $s = $this->servicioCrm(); $cat = $s->catalogos();
        $malos = ['cuenta' => 'x', 'actividad' => "1' OR '1'='1", 'proxima' => '<script>', 'saldo' => 'quizas', 'consentimiento' => 'x', 'indicador' => '../../etc/passwd',
            'visitas_min' => '-1', 'visitas_max' => '1e3', 'gasto_min' => '1; DROP TABLE usuarios', 'ultima_desde' => '2026-13-45', 'proxima_hasta' => 'mañana', 'orden' => 'u.password'];
        foreach ($malos as $k => $v) {
            $r = $s->normalizarFiltros([$k => $v], self::FULL, $cat);
            $this->assertNotSame([], $r['errores'], "$k=$v debe rechazarse");
            foreach ($r['errores'] as $e) { $this->assertStringNotContainsString((string)$v, $e, 'el mensaje no refleja la entrada'); }
            if ($k !== 'orden') { $this->assertNull($r['filtros'][$k] ?? null, "$k vuelve al valor seguro"); } else { $this->assertSame('nombre', $r['filtros']['orden']); }
        }
        foreach (['cuenta', 'actividad', 'saldo', 'visitas_min', 'gasto_max', 'indicador', 'proxima'] as $k) {
            $r = $s->normalizarFiltros([$k => ['a' => 'b']], self::FULL, $cat);
            $this->assertNotSame([], $r['errores'], "$k como arreglo");
        }
        $r = $s->normalizarFiltros(['visitas_min' => '5', 'visitas_max' => '2', 'ultima_desde' => '2026-02-01', 'ultima_hasta' => '2026-01-01', 'gasto_min' => '9', 'gasto_max' => '1'], self::FULL, $cat);
        $this->assertCount(3, $r['errores']);
    }

    public function testSinAnaliticaGastoYSaldoSeRechazanYNoSeAplican(): void
    {
        $s = $this->servicioCrm();
        foreach (['gasto_min' => '10', 'gasto_max' => '10', 'saldo' => 'con', 'orden' => 'saldo', 'indicador' => 'con_saldo'] as $k => $v) {
            $r = $s->normalizarFiltros([$k => $v], self::RECEPCION, $s->catalogos());
            $this->assertNotSame([], $r['errores'], "$k sin analítica");
            $this->assertNull($r['filtros']['gasto_min']); $this->assertNull($r['filtros']['saldo']);
        }
    }

    // ------------------------------------------------------------ un solo universo
    public function testListaConteoTotalesIndicadoresSegmentosYArchivoSalenDelMismoUniverso(): void
    {
        $p = $this->poblacion();
        $casos = [[], ['cuenta' => 'confirmada'], ['consentimiento' => 'no'], ['actividad' => 'reciente'], ['profesional_id' => (string)$p['p1']], ['visitas_min' => '1', 'saldo' => 'sin'],
            ['membresia' => 'activo', 'proxima' => 'sin'], ['gasto_min' => '1'], ['segmento' => 'inactivo'], ['cuenta' => 'sin_confirmar', 'consentimiento' => 'si']];
        $repo = new CrmRepository($this->db); $svc = $this->servicioCrm();
        foreach ($casos as $get) {
            $f = $this->filtros($get);
            $panel = $svc->panel($f, self::FULL);
            $n = $panel['listado']['total'];
            $this->assertSame($n, $panel['resumen']['clientes_total'], 'indicador «clientes totales» = total del listado ' . json_encode($get));
            $this->assertSame($n, $panel['listado']['totales']['clientes']);
            $this->assertSame($n, count($panel['listado']['filas']));   // por_pagina 100
            if ($f['segmento'] === null) { $this->assertSame($n, array_sum(array_column($panel['segmentos'], 'valor')), 'segmentos suman el universo ' . json_encode($get)); }
            else { $fila = array_column($panel['segmentos'], 'valor', 'clave'); $this->assertSame($n, $fila[$f['segmento']]); }
            $this->assertSame($n, array_sum($panel['resumen']['membresias']), 'membresías suman el universo');
            // La distribución de comunicaciones ignora su propio filtro (cada cifra es lo que mostraría el listado al elegirla).
            if ($f['consentimiento'] === null) { $this->assertSame($n, array_sum($panel['resumen']['comunicaciones']), 'comunicaciones suman el universo'); }
            else { $this->assertGreaterThanOrEqual($n, array_sum($panel['resumen']['comunicaciones'])); }
            $this->assertSame($n, $panel['resumen']['cuenta_confirmada'] + $panel['resumen']['cuenta_sin_confirmar']);
            $this->assertSame(array_sum(array_column($panel['listado']['filas'], 'visitas_rango')), $panel['listado']['totales']['visitas']);
            $this->assertSame($panel['listado']['totales']['visitas'], $panel['resumen']['visitas_rango']);
            // archivo
            $plan = $svc->prepararExportacion($f, self::FULL, 'clientes');
            $r = Exportador::generar('csv', 'x', $plan['columnas'], $plan['filas'], $plan['totales'], $plan['opciones']);
            $this->assertSame($n, $r['filas'], 'filas del archivo = total en pantalla ' . json_encode($get));
            fclose($r['flujo']);
        }
    }

    public function testCadaIndicadorConListadoCoincideConSuCifra(): void
    {
        $p = $this->poblacion();
        $svc = $this->servicioCrm();
        $f = $this->filtros([]);
        $panel = $svc->panel($f, self::FULL);
        foreach (CrmService::INDICADORES as $clave => $def) {
            $valor = $panel['resumen'];
            foreach (explode('.', $def['dato']) as $k) { $valor = $valor[$k]; }
            $fi = $this->filtros(['indicador' => $clave]);
            $d = $svc->listado($fi, self::FULL);
            $this->assertSame((int)$valor, $d['listado']['total'], "la cifra «{$def['etiqueta']}» ($clave) debe coincidir con su listado");
            $this->assertCount(min(100, (int)$valor), $d['listado']['filas']);
            $this->assertSame($clave, $d['indicador']['clave']);
        }
        $this->assertGreaterThan(0, $panel['resumen']['activos']);
    }

    public function testElIndicadorLoDecideElServidorNoLaUrl(): void
    {
        $p = $this->poblacion();
        $f = $this->filtros(['indicador' => 'activos', 'actividad' => 'nunca']);   // la URL intenta contradecir el indicador
        $this->assertSame('reciente', $f['actividad']);
        $this->assertSame('nunca', CrmService::sinIndicador($f)['actividad'], 'volver al panel recupera lo que traía la consulta');
    }

    public function testFiltrosActivosYConsultaConservanTodoAlPaginarOrdenarYExportar(): void
    {
        $p = $this->poblacion();
        $f = $this->filtros(['cuenta' => 'confirmada', 'gasto_min' => '1.50', 'visitas_min' => '0', 'ultima_desde' => $this->dia(-30), 'orden' => 'saldo', 'dir' => 'desc']);
        $q = CrmService::consulta($f, ['pagina' => 2]);
        parse_str($q, $get);
        $this->assertSame('1.50', $get['gasto_min']); $this->assertSame('0', $get['visitas_min']); $this->assertSame('saldo', $get['orden']); $this->assertSame('2', $get['pagina']);
        $f2 = $this->filtros($get);
        foreach (['cuenta', 'gasto_min', 'visitas_min', 'ultima_desde', 'orden', 'dir'] as $k) { $this->assertSame($f[$k], $f2[$k], $k); }
        $chips = $this->servicioCrm()->filtrosActivos($f, $this->servicioCrm()->catalogos(), self::FULL);
        $claves = array_column($chips, 'clave');
        foreach (['cuenta', 'gasto', 'visitas', 'ultima', 'q'] as $k) { $this->assertContains($k, $claves); }
        $this->assertSame([], $this->servicioCrm()->filtrosActivos($this->filtros([]), [], self::FULL) === [] ? [] : array_diff(array_column($this->servicioCrm()->filtrosActivos($this->filtros([]), [], self::FULL), 'clave'), ['q']), 'sin filtros no hay chips salvo la etiqueta de pruebas');
        // sin analítica no se anuncian chips de dinero
        $sin = $this->servicioCrm()->filtrosActivos($f, [], self::RECEPCION);
        $this->assertNotContains('gasto', array_column($sin, 'clave'));
    }

    public function testToda_definicion_esta_documentada_en_pantalla(): void
    {
        foreach (['cuenta', 'membresia', 'consentimiento', 'actividad', 'segmento', 'alta', 'ultima', 'proxima', 'profesional', 'servicio', 'frecuencia', 'gasto', 'saldo', 'periodo', 'q'] as $k) {
            $this->assertArrayHasKey($k, CrmService::DEFINICIONES, "falta la definición visible de «{$k}»");
            $this->assertNotSame('', CrmService::DEFINICIONES[$k]);
        }
        $this->assertStringContainsStringIgnoringCase('no es una suscripción de pago', CrmService::DEFINICIONES['membresia']);
    }

    // ------------------------------------------------------------ alcance por rol
    public function testAlcancePorRol(): void
    {
        $p = $this->poblacion();
        $svc = $this->servicioCrm();
        try { $svc->listado($this->filtros([], self::FULL), self::SOLO_ANALITICA); $this->fail('analítica sin clientes.ver no recibe listados nominales'); } catch (\DomainException) { $this->addToAssertionCount(1); }
        $panel = $svc->panel($this->filtros([], self::SOLO_ANALITICA), self::SOLO_ANALITICA);
        $this->assertNull($panel['listado'], 'solo analítica: agregados, sin filas');
        // recepción: sin importes ni saldo en las filas
        $d = $svc->listado($this->filtros([], self::RECEPCION), self::RECEPCION);
        foreach ($d['listado']['filas'] as $fila) { $this->assertArrayNotHasKey('cobros_real', $fila); $this->assertArrayNotHasKey('saldo', $fila); }
        $this->assertNull($d['listado']['totales']['gasto_neto']);
        // profesional: solo sus clientes (a y d con P1; nunca c)
        $acceso = ['ver' => false, 'analitica' => false, 'propios' => true, 'exportar' => false];
        $v = $svc->normalizarFiltros(['q' => $this->tag], $acceso, $svc->catalogos());
        $d = $svc->listado($v['filtros'], $acceso, $p['p1']);
        $this->assertSame($this->esperados($p, 'a', 'd'), (function () use ($d) { $i = array_map(fn($r) => (int)$r['id'], $d['listado']['filas']); sort($i); return $i; })());
    }

    // ------------------------------------------------------------ controlador: listado y exportación
    private function sesion(int $id): void { $_SESSION = ['id' => $id, 'login' => true, 'version_sesion' => 1, 'csrf_token' => 'r5']; }
    private function propietario(): int { return $this->usuario('Jefa', 'Dueña ' . $this->tag, ['rol' => 'propietario', 'membresia' => null]); }
    private function invocar(callable $fn): array
    {
        http_response_code(200); $termino = null; unset($GLOBALS['__appsalon_ultima_exportacion']);
        ob_start();
        try { $fn(); } catch (AppTerminationException $e) { $termino = $e->getStatusCode(); } catch (\Throwable $t) { ob_end_clean(); throw $t; }
        return ['http' => http_response_code(), 'termino' => $termino, 'cuerpo' => (string)ob_get_clean()];
    }
    private function pedir(string $metodo, array $get): array
    {
        $_GET = $get; $_SERVER['REQUEST_URI'] = '/admin/clientes/' . $metodo;
        return $this->invocar(fn() => CrmController::$metodo(new Router()));
    }
    private function auditoria(int $actor): array
    {
        $r = $this->db->query('SELECT * FROM crm_exportaciones_auditoria WHERE actor_usuario_id=' . $actor . ' ORDER BY id');
        return $r->fetch_all(MYSQLI_ASSOC);
    }
    /** @return array{cab:string[],filas:array<int,array<string,string>>,total:?array<string,string>,aviso:?string} */
    private function leerCsv(string $cuerpo): array
    {
        $fp = fopen('php://memory', 'r+'); fwrite($fp, substr($cuerpo, 3)); rewind($fp);
        $cab = fgetcsv($fp, 0, ',', '"', ''); $filas = []; $total = null; $aviso = null;
        while (($f = fgetcsv($fp, 0, ',', '"', '')) !== false) {
            if ($f === [null]) { continue; }
            if ($f[0] === 'Total') { $total = array_combine($cab, $f); continue; }
            if (str_starts_with($f[0], 'AVISO:')) { $aviso = $f[0]; continue; }
            $filas[] = array_combine($cab, $f);
        }
        return ['cab' => $cab, 'filas' => $filas, 'total' => $total, 'aviso' => $aviso];
    }
    private function leerXlsx(string $cuerpo): array
    {
        $r = LectorXlsx::filas($cuerpo); $cab = array_shift($r); $filas = []; $total = null; $aviso = null;
        foreach ($r as $f) {
            if ($f[0] === 'Total') { $total = array_combine($cab, $f); continue; }
            if (str_starts_with($f[0], 'AVISO:')) { $aviso = $f[0]; continue; }
            $filas[] = array_combine($cab, $f);
        }
        return ['cab' => $cab, 'filas' => $filas, 'total' => $total, 'aviso' => $aviso];
    }

    public function testListadoCsvYXlsxTienenLosMismosRegistrosQueLaPantallaYLosMismosTotales(): void
    {
        $p = $this->poblacion(); $this->sesion($this->propietario());
        foreach ([[], ['cuenta' => 'confirmada', 'actividad' => 'reciente'], ['indicador' => 'consentimiento_si'], ['saldo' => 'sin', 'orden' => 'ultima', 'dir' => 'desc']] as $filtro) {
            $base = ['q' => $this->tag] + $filtro;
            $pant = $this->pedir('listado', $base);
            $this->assertSame(200, $pant['http'], json_encode($filtro));
            $d = $this->servicioCrm()->listado($this->filtros($filtro), self::FULL);
            $esperados = array_map(fn($r) => (string)$r['id'], $this->servicioCrm()->listado($this->filtros($filtro), self::FULL)['listado']['filas']);
            sort($esperados);
            $csv = $this->pedir('exportar', $base + ['tipo' => 'clientes', 'formato' => 'csv']);
            $xls = $this->pedir('exportar', $base + ['tipo' => 'clientes', 'formato' => 'xlsx']);
            $this->assertSame(200, $csv['termino']); $this->assertSame(200, $xls['termino']);
            $c = $this->leerCsv($csv['cuerpo']); $x = $this->leerXlsx($xls['cuerpo']);
            $idc = array_column($c['filas'], 'ID'); sort($idc); $idx = array_column($x['filas'], 'ID'); sort($idx);
            $this->assertSame($esperados, $idc, 'CSV = lista ' . json_encode($filtro));
            $this->assertSame($esperados, $idx, 'XLSX = lista ' . json_encode($filtro));
            $this->assertSame($c['cab'], $x['cab']);
            $t = $d['listado']['totales'];
            $this->assertSame($t['clientes'] . ' clientes', $c['total']['Nombre']);
            $this->assertSame((string)$t['visitas'], $c['total']['Visitas completadas (periodo y filtros)']);
            $this->assertSame((string)$t['visitas'], $x['total']['Visitas completadas (periodo y filtros)']);
            $this->assertSame(Exportador::FORMATOS['xlsx'], $GLOBALS['__appsalon_ultima_exportacion']['cabeceras']['Content-Type']);
            $this->assertEqualsWithDelta($t['gasto_neto'] / 100, (float)$c['total']['Gasto neto real (USD)'], 0.001);
            $this->assertEqualsWithDelta($t['gasto_neto'] / 100, (float)$x['total']['Gasto neto real (USD)'], 0.001);
            $this->assertEqualsWithDelta($t['saldo'] / 100, (float)$c['total']['Saldo pendiente (USD)'], 0.001);
            // la suma de las filas del archivo = la fila Total
            $this->assertEqualsWithDelta(array_sum(array_map('floatval', array_column($c['filas'], 'Gasto neto real (USD)'))), (float)$c['total']['Gasto neto real (USD)'], 0.001);
            // la página muestra el mismo total
            $this->assertStringContainsString('<strong>' . $t['clientes'] . '</strong> clientes', $pant['cuerpo']);
        }
    }

    public function testListadoPantallaMuestraChipsConteoLimpiarYEnlacesQueConservanFiltros(): void
    {
        $p = $this->poblacion(); $this->sesion($this->propietario());
        $r = $this->pedir('listado', ['q' => $this->tag, 'cuenta' => 'confirmada', 'indicador' => 'activos', 'por_pagina' => '10', 'orden' => 'ultima', 'dir' => 'desc']);
        $this->assertSame(200, $r['http']);
        foreach (['crm-chips', 'Quitar el filtro Cuenta: Confirmada', 'Limpiar filtros', 'id="crm-conteo"', 'Exportar clientes (Excel XLSX)', 'formato=xlsx', 'aria-sort="descending"', 'Clientes activos'] as $texto) {
            $this->assertStringContainsString($texto, $r['cuerpo'], $texto);
        }
        $this->assertMatchesRegularExpression('~/admin/clientes/exportar\?[^"]*cuenta=confirmada[^"]*~', $r['cuerpo'], 'la exportación conserva los filtros');
        $this->assertMatchesRegularExpression('~/admin/clientes/exportar\?[^"]*indicador=activos~', $r['cuerpo']);
    }

    public function testListadoVacioYErrorDeFiltros(): void
    {
        $p = $this->poblacion(); $this->sesion($this->propietario());
        $v = $this->pedir('listado', ['q' => $this->tag, 'cuenta' => 'sin_confirmar', 'consentimiento' => 'si']);
        $this->assertSame(200, $v['http']);
        $this->assertStringContainsString('Ningún cliente cumple estos filtros', $v['cuerpo']);
        $e = $this->pedir('listado', ['q' => $this->tag, 'actividad' => "x'--", 'saldo' => ['1']]);
        $this->assertSame(422, $e['http']);
        $this->assertStringNotContainsString("x'--", $e['cuerpo'], 'no se refleja la entrada rechazada');
        $this->assertStringContainsString('La actividad comercial no es una opción válida', $e['cuerpo']);
    }

    public function testListadoYDescargaDeNegadoSinPermisoQuedanAuditados(): void
    {
        $p = $this->poblacion();
        $recep = $this->usuario('Rosa', 'Recep ' . $this->tag, ['rol' => 'recepcion', 'membresia' => null]);
        $this->sesion($recep);
        $this->assertSame(200, $this->pedir('listado', ['q' => $this->tag])['http'], 'recepción ve el listado (clientes.ver)');
        $r = $this->pedir('exportar', ['q' => $this->tag, 'tipo' => 'clientes', 'formato' => 'xlsx']);
        $this->assertSame(403, $r['http']); $this->assertStringNotContainsString('PK', substr($r['cuerpo'], 0, 2));
        $a = $this->auditoria($recep);
        $this->assertCount(1, $a); $this->assertSame('denegada', $a[0]['resultado']); $this->assertSame('xlsx', $a[0]['formato']); $this->assertSame('recepcion', $a[0]['actor_rol']);
        $fin = $this->usuario('Fina', 'Finanzas ' . $this->tag, ['rol' => 'finanzas', 'membresia' => null]);
        $this->sesion($fin);
        $this->assertSame(403, $this->pedir('listado', ['q' => $this->tag])['http'], 'finanzas (solo analítica) no recibe listados nominales');
        $this->assertSame(403, $this->pedir('exportar', ['tipo' => 'clientes', 'formato' => 'csv'])['http']);
        $pro = $this->usuario('Pro', 'Fesional ' . $this->tag, ['rol' => 'profesional', 'membresia' => null]);
        $this->sesion($pro);
        $this->assertSame(403, $this->pedir('exportar', ['tipo' => 'comercial', 'formato' => 'xlsx'])['http']);
    }

    public function testFormatoInvalidoEs422AuditadoYNoEntregaNada(): void
    {
        $p = $this->poblacion(); $dueno = $this->propietario(); $this->sesion($dueno);
        foreach (['xls', 'pdf', "csv'--", 'CSV ', '../x'] as $mal) {
            $r = $this->pedir('exportar', ['q' => $this->tag, 'tipo' => 'clientes', 'formato' => $mal]);
            $this->assertSame(422, $r['http'], $mal);
            $this->assertStringNotContainsString("\xEF\xBB\xBF", $r['cuerpo']);
            $this->assertStringNotContainsString("csv'--", $r['cuerpo']);
        }
        $this->assertSame(422, $this->pedir('exportar', ['tipo' => 'clientes', 'formato' => ['csv']])['http']);
        $a = $this->auditoria($dueno);
        $this->assertCount(6, $a);
        foreach ($a as $fila) { $this->assertSame('fallida', $fila['resultado']); $this->assertSame('invalido', $fila['formato']); $this->assertSame('formato_invalido', $fila['motivo']); $this->assertNull($fila['contenido_sha256']); }
    }

    public function testExportacionExitosaSeAuditaConHashFilasYFormatoAntesDeEntregar(): void
    {
        $p = $this->poblacion(); $dueno = $this->propietario(); $this->sesion($dueno);
        foreach (['csv', 'xlsx'] as $formato) {
            $r = $this->pedir('exportar', ['q' => $this->tag, 'tipo' => 'clientes', 'formato' => $formato, 'cuenta' => 'confirmada']);
            $this->assertSame(200, $r['termino']);
        }
        $a = $this->auditoria($dueno);
        $this->assertCount(2, $a);
        $this->assertSame(['csv', 'xlsx'], array_column($a, 'formato'));
        foreach ($a as $fila) {
            $this->assertSame('exitosa', $fila['resultado']); $this->assertSame(3, (int)$fila['filas']); $this->assertSame('propietario', $fila['actor_rol']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $fila['contenido_sha256']);
            $this->assertStringNotContainsString($this->tag, (string)$fila['filtros_json'], 'la búsqueda no se guarda en claro');
            $f = json_decode($fila['filtros_json'], true); $this->assertSame('confirmada', $f['cuenta']);
        }
    }

    public function testSiFallaLaAuditoriaNoSaleElArchivoEnNingunFormato(): void
    {
        $p = $this->poblacion(); $this->sesion($this->propietario());
        $this->db->query("CREATE TRIGGER trg_r5_audit_falla BEFORE INSERT ON crm_exportaciones_auditoria FOR EACH ROW SIGNAL SQLSTATE '45001' SET MESSAGE_TEXT='auditoria rota'");
        foreach (['csv', 'xlsx'] as $formato) {
            try { $r = $this->pedir('exportar', ['q' => $this->tag, 'tipo' => 'clientes', 'formato' => $formato]); $this->fail('debió fallar con 503'); }
            catch (\Classes\ServiceUnavailableException) { $this->addToAssertionCount(1); }
            $this->assertArrayNotHasKey('__appsalon_ultima_exportacion', $GLOBALS, 'no se entregó nada');
        }
    }

    public function testDatosHostilesYFormulasEnNombreCorreoYNotas(): void
    {
        $p = $this->poblacion(); $this->sesion($this->propietario());
        $peligrosos = ['=1+1', '+SUM(A1)', '-2+3', '@HYPERLINK("http://x")', "\t=cmd", "\r=cmd", '  =1+1', "=\"x\"\nsegunda línea"];
        foreach ($peligrosos as $i => $nom) {
            $id = $this->usuario('Hostil' . $i, 'Z ' . $this->tag);
            $this->sql('UPDATE usuarios SET nombre=?,telefono=?,email=? WHERE id=?', 'sssi', [$nom, '+593' . $i, '=cmd' . $i . '.' . $this->tag . '@x.test', $id])->close();   // correo hostil heredado/importado
        }
        $base = ['q' => 'Z ' . $this->tag, 'tipo' => 'clientes', 'por_pagina' => '100'];
        $csv = $this->leerCsv($this->pedir('exportar', $base + ['formato' => 'csv'])['cuerpo']);
        $xls = $this->leerXlsx($this->pedir('exportar', $base + ['formato' => 'xlsx'])['cuerpo']);
        $this->assertCount(count($peligrosos), $csv['filas']);
        foreach ($csv['filas'] as $f) { foreach ($f as $col => $celda) { $this->assertDoesNotMatchRegularExpression('/^\s*[=+\-@]|^[\t\r]/', $celda, "CSV «{$col}»: {$celda}"); } }
        $rawXlsx = LectorXlsx::partes($this->pedir('exportar', $base + ['formato' => 'xlsx'])['cuerpo'])['xl/worksheets/sheet1.xml'];
        $this->assertStringNotContainsString('<f>', $rawXlsx, 'ninguna celda del XLSX es una fórmula');
        $this->assertSame(count($peligrosos), count($xls['filas']));
        // en XLSX el texto se conserva TAL CUAL pero como cadena (no se evalúa)
        $this->assertContains('=1+1', array_column($xls['filas'], 'Nombre'));
    }

    public function testExportacionDeMilesDeFilasConMemoriaAcotadaYTruncadoVisible(): void
    {
        $this->sesion($this->propietario());
        $srv = $this->servicio();
        $n = 4000;
        $hash = password_hash('x', PASSWORD_BCRYPT);
        $this->db->begin_transaction();
        $st = $this->db->prepare('INSERT INTO usuarios(nombre,apellido,email,password,telefono,admin,confirmado) VALUES(?,?,?,?,?,0,1)');
        for ($i = 0; $i < $n; $i++) { $nom = 'Masivo' . $i; $ap = 'Vol ' . $this->tag; $em = "m$i.{$this->tag}@vol.test"; $tel = '0999' . str_pad((string)$i, 6, '0', STR_PAD_LEFT); $st->bind_param('sssss', $nom, $ap, $em, $hash, $tel); $st->execute(); $this->usuarios[] = (int)$this->db->insert_id; }
        $st->close();
        $ins = $this->db->prepare('INSERT INTO clientes(usuario_id,estado,origen,creado_en) VALUES(?,?,?,?)');
        $est = 'activo'; $ori = 'registro_publico'; $alta = $this->dia(-100) . ' 09:00:00';
        foreach (array_slice($this->usuarios, -$n) as $uid) { $ins->bind_param('isss', $uid, $est, $ori, $alta); $ins->execute(); }
        $ins->close(); $this->db->commit();

        $svc = $this->servicioCrm();
        $f = $this->filtros(['q' => 'Vol ' . $this->tag]);
        $plan = $svc->prepararExportacion($f, self::FULL, 'clientes');
        gc_collect_cycles(); $antes = memory_get_usage(true); 
        $r = Exportador::generar('xlsx', 'masivo', $plan['columnas'], $plan['filas'], $plan['totales'], $plan['opciones']);
        $pico = memory_get_peak_usage(true) - $antes;
        $this->assertSame($n, $r['filas']); $this->assertFalse($r['truncado']);
        $this->assertLessThan(24 * 1048576, max(0, $pico), 'memoria acotada: el XLSX de ' . $n . ' filas no se arma en memoria');
        $filas = LectorXlsx::filas(stream_get_contents($r['flujo']));
        $this->assertCount($n + 2, $filas, 'encabezado + filas + Total');
        fclose($r['flujo']);

        // Truncado: con un tope menor el archivo se marca como parcial de tres formas y no declara totales
        $svc->setMaxFilasExportacion(1000);
        foreach (['csv', 'xlsx'] as $formato) {
            $plan = $svc->prepararExportacion($f, self::FULL, 'clientes');
            $r = Exportador::generar($formato, $plan['base'], $plan['columnas'], $plan['filas'], $plan['totales'], $plan['opciones']);
            $this->assertTrue($r['truncado']); $this->assertSame(1000, $r['filas']); $this->assertStringContainsString('_parcial.' . $formato, $r['archivo']);
            $cuerpo = stream_get_contents($r['flujo']); fclose($r['flujo']);
            $leido = $formato === 'csv' ? $this->leerCsv($cuerpo) : $this->leerXlsx($cuerpo);
            $this->assertNull($leido['total'], 'un archivo parcial no declara totales');
            $this->assertStringContainsString('AVISO', (string)$leido['aviso']); $this->assertStringContainsString('4000', (string)$leido['aviso']);
        }
    }
}

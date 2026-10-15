<?php
namespace Tests\Integration;

use AppTerminationException;
use Classes\ServiceUnavailableException;
use Controllers\CrmController;
use Model\ActiveRecord;
use MVC\Router;
use PHPUnit\Framework\TestCase;
use Repositories\CrmRepository;
use Services\CrmService;

/**
 * CRM de clientes sobre MySQL real. Cada prueba crea sus propios datos (marcados con una etiqueta única) y los borra.
 * Las conciliaciones comparan contra cálculos independientes escritos en PHP sobre filas crudas, no contra la
 * misma consulta que usa la implementación.
 */
final class CrmIntegrationTest extends TestCase
{
    private \mysqli $db;
    private string $tag;
    private string $zona = 'America/Guayaquil';
    private array $usuarios = [];
    private array $citas = [];
    private array $servicios = [];
    private array $profesionales = [];
    private array $formularios = [];
    private array $pagos = [];

    private const FULL = ['ver' => true, 'analitica' => true, 'propios' => false, 'exportar' => true];
    private const RECEPCION = ['ver' => true, 'analitica' => false, 'propios' => false, 'exportar' => false];

    protected function setUp(): void
    {
        $this->db = new \mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', getenv('DB_NAME') ?: 'appsalon_test', (int)(getenv('DB_PORT') ?: 3306));
        $this->db->set_charset('utf8mb4');
        ActiveRecord::setDB($this->db);
        validar_base_datos_prueba($this->db);
        $this->tag = bin2hex(random_bytes(8));
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $_SESSION = []; $_GET = []; $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/admin/clientes';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        CrmController::setCrmService(null);
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        CrmController::setCrmService(null);
        if ($this->db->ping()) {
            foreach ($this->pagos as $id) { $this->db->query('DELETE FROM pagos WHERE id=' . (int)$id); }
            foreach ($this->citas as $id) { $this->db->query('DELETE FROM citasservicios WHERE citaId=' . (int)$id); $this->db->query('DELETE FROM citas WHERE id=' . (int)$id); }
            foreach ($this->formularios as $id) { $this->db->query('DELETE FROM formularios_cliente WHERE id=' . (int)$id); }
            foreach ($this->profesionales as $id) { $this->db->query('DELETE FROM profesionales WHERE id=' . (int)$id); }
            foreach ($this->servicios as $id) { $this->db->query('DELETE FROM servicios WHERE id=' . (int)$id); }
            foreach ($this->usuarios as $id) { $this->db->query('DELETE FROM usuarios WHERE id=' . (int)$id); }
        }
        $_SESSION = []; $_GET = []; $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        http_response_code(200);
    }

    // ---------------------------------------------------------------- fixtures

    private function sql(string $sql, string $types = '', array $params = []): \mysqli_stmt
    {
        $s = $this->db->prepare($sql);
        $this->assertNotFalse($s, $this->db->error);
        if ($types !== '') { $s->bind_param($types, ...$params); }
        $this->assertTrue($s->execute(), $s->error);
        return $s;
    }

    private function filas(string $sql): array
    {
        $r = $this->db->query($sql);
        $this->assertNotFalse($r, $this->db->error);
        return $r->fetch_all(MYSQLI_ASSOC);
    }

    private function hoy(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone($this->zona)))->setTime(0, 0);
    }

    private function dia(int $offset): string { return $this->hoy()->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d'); }

    /** @param array{email?:string,telefono?:string,rol?:string,admin?:int,confirmado?:int,membresia?:string|null,alta?:string,origen?:string,consentimiento?:?string,suspendido_motivo?:string} $o */
    private function usuario(string $nombre, string $apellido, array $o = []): int
    {
        $email = $o['email'] ?? (strtolower(preg_replace('/[^a-z0-9]/i', '', $nombre . $apellido)) . '.' . bin2hex(random_bytes(2)) . '@crm' . $this->tag . '.test');
        $email = substr($email, -60);
        $tel = $o['telefono'] ?? ('09' . str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT));
        $hash = password_hash('PruebaSegura123!', PASSWORD_BCRYPT);
        $admin = $o['admin'] ?? 0; $conf = $o['confirmado'] ?? 1;
        $this->sql('INSERT INTO usuarios(nombre,apellido,email,password,telefono,admin,confirmado) VALUES(?,?,?,?,?,?,?)', 'sssssii', [$nombre, $apellido, $email, $hash, $tel, $admin, $conf])->close();
        $id = (int)$this->db->insert_id;
        $this->usuarios[] = $id;
        if (isset($o['rol'])) { $this->sql('INSERT INTO usuario_accesos(usuario_id,rol,activo) VALUES(?,?,1)', 'is', [$id, $o['rol']])->close(); }
        if (array_key_exists('membresia', $o) ? $o['membresia'] !== null : !isset($o['rol'])) {
            $estado = $o['membresia'] ?? 'activo';
            $alta = $o['alta'] ?? ($this->dia(-400) . ' 09:00:00');
            $this->sql('INSERT INTO clientes(usuario_id,estado,origen,suspendido_motivo,creado_en) VALUES(?,?,?,?,?)', 'issss', [$id, $estado, $o['origen'] ?? 'registro_publico', $o['suspendido_motivo'] ?? null, $alta])->close();
        }
        if (array_key_exists('consentimiento', $o) && $o['consentimiento'] !== null) {
            $this->sql('INSERT INTO perfiles_usuario(usuarioId,marketing_email,consentimiento_marketing_at) VALUES(?,1,?)', 'is', [$id, $o['consentimiento']])->close();
        }
        return $id;
    }

    private function servicio(string $nombre, string $precio = '20.00'): int
    {
        $this->sql('INSERT INTO servicios(nombre,precio,duracion_minutos) VALUES(?,?,30)', 'ss', [$nombre . ' ' . $this->tag, $precio])->close();
        return $this->servicios[] = (int)$this->db->insert_id;
    }

    private function profesional(string $nombre, ?int $usuarioId = null): int
    {
        $this->sql('INSERT INTO profesionales(nombre,activo,usuarioId) VALUES(?,1,?)', 'si', [$nombre . ' ' . $this->tag, $usuarioId])->close();
        return $this->profesionales[] = (int)$this->db->insert_id;
    }

    /** @param array<int,array{0:int,1:string}> $servicios pares [servicioId, precio histórico] */
    private function cita(int $usuario, string $fecha, string $estado, ?int $profesional = null, array $servicios = [], string $hora = '10:00:00'): int
    {
        $this->sql('INSERT INTO citas(fecha,hora,usuarioId,profesionalId,estado) VALUES(?,?,?,?,?)', 'ssiis', [$fecha, $hora, $usuario, $profesional, $estado])->close();
        $id = (int)$this->db->insert_id;
        $this->citas[] = $id;
        foreach ($servicios as [$sid, $precio]) {
            $this->sql("INSERT INTO citasservicios(citaId,servicioId,nombre_servicio,precio_servicio,duracion_minutos) VALUES(?,?,'Snapshot',?,30)", 'iis', [$id, $sid, $precio])->close();
        }
        return $id;
    }

    private function pago(?int $cita, int $usuario, string $monto, string $proveedor, string $estado, string $pagadoAt, ?string $reembolsadoAt = null): int
    {
        $ref = 'CRM-' . $this->tag . '-' . bin2hex(random_bytes(5));
        $this->sql("INSERT INTO pagos(cita_id,cita_referencia_id,usuario_id,referencia_pago,proveedor,monto,moneda,estado,metodo_pago,idempotency_key,pagado_at,reembolsado_at) VALUES(?,?,?,?,?,?,'USD',?,'efectivo',?,?,?)",
            'iiisssssss', [$cita, $cita ?? 0, $usuario, $ref, $proveedor, $monto, $estado, 'k-' . $ref, $pagadoAt, $reembolsadoAt])->close();
        return $this->pagos[] = (int)$this->db->insert_id;
    }

    private function panel(array $get = [], array $acceso = self::FULL, ?int $profesionalId = null): array
    {
        $svc = new CrmService(new CrmRepository($this->db));
        $v = $svc->normalizarFiltros($get, $acceso, $svc->catalogos());
        $this->assertSame([], $v['errores'], implode(' | ', $v['errores']));
        return $svc->panel($v['filtros'], $acceso, $profesionalId);
    }

    /** Filas del listado propias de esta prueba (búsqueda por la etiqueta), indexadas por id. */
    private function listado(array $extra = [], array $acceso = self::FULL): array
    {
        $p = $this->panel(array_merge(['q' => $this->tag, 'por_pagina' => '100'], $extra), $acceso);
        $this->assertNotNull($p['listado']);
        return array_column($p['listado']['filas'], null, 'id');
    }

    private function sesion(int $id): void
    {
        $_SESSION = ['id' => $id, 'login' => true, 'version_sesion' => 1, 'csrf_token' => 'crm-csrf'];
    }

    /** @return array{http:int,termino:?int,cuerpo:string} */
    private function invocar(callable $fn): array
    {
        http_response_code(200);
        $termino = null;
        ob_start();
        try { $fn(); }
        catch (AppTerminationException $e) { $termino = $e->getStatusCode(); }
        catch (\Throwable $t) { ob_end_clean(); throw $t; }
        $cuerpo = (string)ob_get_clean();
        return ['http' => http_response_code(), 'termino' => $termino, 'cuerpo' => $cuerpo];
    }

    private function index(array $get): array { $_GET = $get; $_SERVER['REQUEST_URI'] = '/admin/clientes'; return $this->invocar(fn() => CrmController::index(new Router())); }
    private function ficha(int $id): array { $_GET = ['id' => (string)$id]; $_SERVER['REQUEST_URI'] = '/admin/clientes/ficha'; return $this->invocar(fn() => CrmController::ficha(new Router())); }
    private function exportar(array $get): array { $_GET = $get; $_SERVER['REQUEST_URI'] = '/admin/clientes/exportar'; return $this->invocar(fn() => CrmController::exportar(new Router())); }

    /** @return array<int,array<string,string>> filas del CSV indexadas por la columna ID */
    private function csv(string $cuerpo): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $cuerpo);
        $fp = fopen('php://memory', 'r+');
        fwrite($fp, substr($cuerpo, 3)); rewind($fp);
        $cab = fgetcsv($fp);
        $out = [];
        while (($f = fgetcsv($fp)) !== false) { if ($f === [null]) { continue; } if ($f[0] === 'Total' || str_starts_with((string)$f[0], 'AVISO:')) { continue; } /* totales y aviso de truncado del Exportador (ronda 5): se prueban aparte */ $fila = array_combine($cab, $f); $out[] = $fila; }
        fclose($fp);
        return $out;
    }

    // ---------------------------------------------------------------- definiciones: cliente

    public function testClienteSoloLaboralNoCuentaYPersonaConAmbosPerfilesSi(): void
    {
        $cliente = $this->usuario('Clara', 'Normal ' . $this->tag);
        $soloLaboral = $this->usuario('Rosa', 'Recepcion ' . $this->tag, ['rol' => 'recepcion', 'membresia' => null]);
        $ambos = $this->usuario('Pablo', 'Profesional ' . $this->tag, ['rol' => 'profesional', 'membresia' => 'activo']);
        $propietarioLegado = $this->usuario('Olga', 'Legado ' . $this->tag, ['admin' => 1, 'membresia' => null]);       // admin=1 sin acceso: rol efectivo propietario
        $legadoCliente = $this->usuario('Lucas', 'Legado ' . $this->tag, ['membresia' => null]);    // sin fila en clientes ni acceso: cliente legado
        $laboralConCitas = $this->usuario('Gina', 'ConCitas ' . $this->tag, ['rol' => 'gerente', 'membresia' => null]);
        $this->cita($laboralConCitas, $this->dia(-3), 'completada');

        $filas = $this->listado();
        $this->assertArrayHasKey($cliente, $filas);
        $this->assertArrayNotHasKey($soloLaboral, $filas, 'Una cuenta solo laboral sin membresía ni citas no es cliente.');
        $this->assertArrayNotHasKey($propietarioLegado, $filas);
        $this->assertArrayHasKey($ambos, $filas, 'Rol laboral + membresía de cliente sí es cliente.');
        $this->assertSame('profesional', $filas[$ambos]['rol_efectivo']);
        $this->assertSame('activo', $filas[$ambos]['membresia']);
        $this->assertArrayHasKey($legadoCliente, $filas);
        $this->assertSame('sin_registro', $filas[$legadoCliente]['membresia']);
        $this->assertNull($filas[$legadoCliente]['alta']);
        $this->assertArrayHasKey($laboralConCitas, $filas, 'Quien tiene citas es cliente aunque su rol sea laboral.');
        $this->assertCount(4, $filas);

        // El total global concilia con una clasificación independiente hecha en PHP sobre filas crudas.
        $ref = $this->referencia($this->dia(-30), $this->dia(0), 90);
        $p = $this->panel(['desde' => $this->dia(-30), 'hasta' => $this->dia(0)]);
        $this->assertSame($ref['total'], $p['resumen']['clientes_total']);
        $this->assertSame($ref['membresia_sin_registro'], $p['resumen']['membresias']['sin_registro']);
    }

    public function testHomonimosNoSeFusionanYSusVisitasNoSeMezclan(): void
    {
        $a = $this->usuario('Ana', 'Pérez ' . $this->tag, ['email' => 'ana1.' . $this->tag . '@crm' . $this->tag . '.test']);
        $b = $this->usuario('Ana', 'Pérez ' . $this->tag, ['email' => 'ana2.' . $this->tag . '@crm' . $this->tag . '.test']);
        $this->cita($a, $this->dia(-10), 'completada');
        $this->cita($a, $this->dia(-5), 'completada');
        $this->cita($b, $this->dia(-8), 'completada');
        $filas = $this->listado();
        $this->assertCount(2, $filas);
        $this->assertSame(2, (int)$filas[$a]['visitas_total']);
        $this->assertSame(1, (int)$filas[$b]['visitas_total']);
        $this->assertNotSame($filas[$a]['email'], $filas[$b]['email']);
        $this->assertSame($filas[$a]['nombre'], $filas[$b]['nombre']);
    }

    // ---------------------------------------------------------------- visitas, cero actividad, estados

    public function testCeroActividadNoInventaVisitasNiGasto(): void
    {
        $id = $this->usuario('Zoe', 'Quieta ' . $this->tag, ['alta' => $this->dia(-200) . ' 08:00:00']);
        $fila = $this->listado()[$id];
        $this->assertSame(0, (int)$fila['visitas_total']);
        $this->assertSame(0, (int)$fila['visitas_rango']);
        $this->assertNull($fila['ultima_visita']);
        $this->assertNull($fila['recencia']);
        $this->assertSame(0, (int)$fila['cobros_real'] + (int)$fila['dev_real'] + (int)$fila['cobros_sim'] + (int)$fila['dev_sim']);
        $this->assertSame('inactivo', $fila['segmento'], 'Sin ninguna visita y alta antigua: inactivo, nunca activo ni VIP.');
        $f = (new CrmService(new CrmRepository($this->db)))->ficha($id, self::FULL);
        $this->assertSame([], $f['citas_por_estado']);
        $this->assertSame(0, $f['gasto']['neto_real']);
        $this->assertNull($f['gasto']['ticket_por_cobro'], 'Sin cobros no hay ticket.');
        $this->assertSame(0, $f['fidelidad']['puntos']);
    }

    public function testSoloCompletadasSonVisitasYCanceladasNoAsistidasYFuturasNoCuentan(): void
    {
        $id = $this->usuario('Vera', 'Estados ' . $this->tag);
        $this->cita($id, $this->dia(-20), 'completada');
        $this->cita($id, $this->dia(-15), 'cancelada');
        $this->cita($id, $this->dia(-12), 'no_asistio');
        $this->cita($id, $this->dia(-1), 'confirmada');
        $this->cita($id, $this->dia(-2), 'en_servicio');
        $this->cita($id, $this->dia(7), 'confirmada');
        $this->cita($id, $this->dia(9), 'completada'); // dato incoherente a futuro: jamás puede contar como visita
        $fila = $this->listado()[$id];
        $this->assertSame(1, (int)$fila['visitas_total']);
        $this->assertSame(1, (int)$fila['visitas_rango']);
        $this->assertSame($this->dia(-20), $fila['ultima_visita']);
        $this->assertSame(20, (int)$fila['recencia']);
        $real = (int)$this->filas("SELECT COUNT(*) n FROM citas WHERE usuarioId=$id AND estado='completada' AND fecha<='" . $this->dia(0) . "'")[0]['n'];
        $this->assertSame($real, (int)$fila['visitas_total']);
        $ficha = (new CrmService(new CrmRepository($this->db)))->ficha($id, self::FULL);
        $this->assertEquals(['completada' => 2, 'cancelada' => 1, 'no_asistio' => 1, 'confirmada' => 2, 'en_servicio' => 1], $ficha['citas_por_estado']);
        $this->assertSame(7, $ficha['total_citas']);
    }

    public function testSegmentosSeAsignanSegunReglasYFronteras(): void
    {
        $vieja = $this->dia(-500) . ' 09:00:00';
        $nuevo = $this->usuario('Nora', 'Nuevo ' . $this->tag, ['alta' => $this->dia(0) . ' 10:00:00']);
        $nuevoConVisita = $this->usuario('Nina', 'NuevoVisita ' . $this->tag, ['alta' => $this->dia(-10) . ' 10:00:00']);
        $this->cita($nuevoConVisita, $this->dia(-5), 'completada');
        $borde30 = $this->usuario('Beto', 'Borde30 ' . $this->tag, ['alta' => $this->dia(-30) . ' 10:00:00']);
        $borde31 = $this->usuario('Bea', 'Borde31 ' . $this->tag, ['alta' => $this->dia(-31) . ' 10:00:00']);
        $activo90 = $this->usuario('Aldo', 'Activo90 ' . $this->tag, ['alta' => $vieja]);
        $this->cita($activo90, $this->dia(-90), 'completada');
        $riesgo91 = $this->usuario('Rita', 'Riesgo91 ' . $this->tag, ['alta' => $vieja]);
        $this->cita($riesgo91, $this->dia(-91), 'completada');
        $riesgo180 = $this->usuario('Remo', 'Riesgo180 ' . $this->tag, ['alta' => $vieja]);
        $this->cita($riesgo180, $this->dia(-180), 'completada');
        $inactivo181 = $this->usuario('Iris', 'Inactivo181 ' . $this->tag, ['alta' => $vieja]);
        $this->cita($inactivo181, $this->dia(-181), 'completada');
        $nuncaVino = $this->usuario('Nico', 'NuncaVino ' . $this->tag, ['alta' => $vieja]);
        $soloCancelada = $this->usuario('Cata', 'SoloCancelada ' . $this->tag, ['alta' => $vieja]);
        $this->cita($soloCancelada, $this->dia(-3), 'cancelada');

        $esperado = [$nuevo => 'nuevo', $nuevoConVisita => 'nuevo', $borde30 => 'nuevo', $borde31 => 'inactivo', $activo90 => 'activo', $riesgo91 => 'en_riesgo',
            $riesgo180 => 'en_riesgo', $inactivo181 => 'inactivo', $nuncaVino => 'inactivo', $soloCancelada => 'inactivo'];
        $filas = $this->listado();
        foreach ($esperado as $id => $segmento) { $this->assertSame($segmento, $filas[$id]['segmento'], "Cliente $id"); }

        // El filtro por segmento devuelve exactamente a los clasificados así.
        foreach (['nuevo', 'activo', 'en_riesgo', 'inactivo'] as $seg) {
            $ids = array_keys($this->listado(['segmento' => $seg]));
            $ids = array_values(array_intersect($ids, array_keys($esperado)));
            $this->assertEqualsCanonicalizing(array_keys(array_filter($esperado, fn($s) => $s === $seg)), $ids, "Segmento $seg");
        }
        // La ventana es parametrizable: con 60 días el cliente de hace 90 pasa a «en riesgo» (60 < 90 <= 120).
        $this->assertSame('en_riesgo', $this->listado(['dias_activo' => '60'])[$activo90]['segmento']);
    }

    // ---------------------------------------------------------------- dinero

    public function testDevolucionEnOtroMesSeRestaEnElPeriodoDeLaDevolucion(): void
    {
        $cli = $this->usuario('Dora', 'Devuelve ' . $this->tag);
        $cita = $this->cita($cli, $this->dia(-46), 'completada');
        $this->pago($cita, $cli, '50.00', 'manual', 'reembolsado', $this->dia(-45) . ' 10:00:00', $this->dia(-2) . ' 16:30:00');
        $en = fn(string $d, string $h) => $this->listado(['desde' => $d, 'hasta' => $h])[$cli];

        $soloCobro = $en($this->dia(-60), $this->dia(-30));
        $this->assertSame([5000, 0], [(int)$soloCobro['cobros_real'], (int)$soloCobro['dev_real']]);
        $soloDevolucion = $en($this->dia(-10), $this->dia(0));
        $this->assertSame([0, 5000], [(int)$soloDevolucion['cobros_real'], (int)$soloDevolucion['dev_real']]);
        $this->assertSame(-5000, (int)$soloDevolucion['cobros_real'] - (int)$soloDevolucion['dev_real'], 'La devolución resta en su propio periodo.');
        $ambos = $en($this->dia(-60), $this->dia(0));
        $this->assertSame(0, (int)$ambos['cobros_real'] - (int)$ambos['dev_real']);
        $this->assertSame(5000, (int)$ambos['cobros_real']);
        // Entre ambas fechas no hay movimiento.
        $medio = $en($this->dia(-30), $this->dia(-5));
        $this->assertSame([0, 0], [(int)$medio['cobros_real'], (int)$medio['dev_real']]);
        // La ficha (histórico) también concilia, y muestra el ticket por cobro.
        $ficha = (new CrmService(new CrmRepository($this->db)))->ficha($cli, self::FULL);
        $this->assertSame([5000, 5000, 0, 1, 5000], [$ficha['gasto']['cobros_real'], $ficha['gasto']['devoluciones_real'], $ficha['gasto']['neto_real'], $ficha['gasto']['n_cobros_real'], $ficha['gasto']['ticket_por_cobro']]);
    }

    public function testPagoMockSeSeparaYNoSeSumaAIngresosReales(): void
    {
        $cli = $this->usuario('Mara', 'Mock ' . $this->tag);
        $c1 = $this->cita($cli, $this->dia(-5), 'completada');
        $c2 = $this->cita($cli, $this->dia(-4), 'completada');
        $c3 = $this->cita($cli, $this->dia(-3), 'completada');
        $this->pago($c1, $cli, '100.00', 'mock', 'completado', $this->dia(-5) . ' 11:00:00');
        $this->pago($c2, $cli, '30.00', 'manual', 'completado', $this->dia(-4) . ' 11:00:00');
        $this->pago($c3, $cli, '20.00', 'mock', 'reembolsado', $this->dia(-3) . ' 11:00:00', $this->dia(-2) . ' 09:00:00');
        $this->pago(null, $cli, '999.00', 'manual', 'pendiente', $this->dia(-1) . ' 11:00:00'); // pendiente: no es cobro
        $fila = $this->listado(['desde' => $this->dia(-30), 'hasta' => $this->dia(0)])[$cli];
        $this->assertSame([3000, 0, 1], [(int)$fila['cobros_real'], (int)$fila['dev_real'], (int)$fila['n_cobros_real']]);
        $this->assertSame([12000, 2000], [(int)$fila['cobros_sim'], (int)$fila['dev_sim']]);

        // Conciliación global con un cálculo independiente (incluye cualquier otro dato que exista en la base).
        $ref = $this->referencia($this->dia(-30), $this->dia(0), 90);
        $gasto = $this->panel(['desde' => $this->dia(-30), 'hasta' => $this->dia(0)])['resumen']['gasto'];
        $this->assertSame($ref['cobros_real'], $gasto['cobros_real']);
        $this->assertSame($ref['dev_real'], $gasto['devoluciones_real']);
        $this->assertSame($ref['cobros_real'] - $ref['dev_real'], $gasto['neto_real']);
        $this->assertSame($ref['sim_neto'], $gasto['neto_simulado']);
        $this->assertSame($ref['n_cobros_real'], $gasto['n_cobros_real']);
    }

    public function testKpisGlobalesConcilianConCalculoIndependiente(): void
    {
        $a = $this->usuario('Ada', 'Kpi ' . $this->tag, ['alta' => $this->dia(-8) . ' 10:00:00']);
        $b = $this->usuario('Beni', 'Kpi ' . $this->tag, ['alta' => $this->dia(-300) . ' 10:00:00']);
        $c = $this->usuario('Cleo', 'Kpi ' . $this->tag, ['alta' => $this->dia(-300) . ' 10:00:00']);
        foreach ([-40, -30, -20] as $d) { $this->cita($a, $this->dia($d), 'completada'); }
        $this->cita($b, $this->dia(-100), 'completada');
        $this->cita($b, $this->dia(-9), 'cancelada');
        $this->cita($c, $this->dia(-7), 'no_asistio');
        $this->cita($c, $this->dia(-6), 'completada');
        $desde = $this->dia(-45); $hasta = $this->dia(0);
        $ref = $this->referencia($desde, $hasta, 60);
        $r = $this->panel(['desde' => $desde, 'hasta' => $hasta, 'dias_activo' => '60'])['resumen'];
        $this->assertSame($ref['total'], $r['clientes_total']);
        $this->assertSame($ref['nuevos'], $r['nuevos']);
        $this->assertSame($ref['activos'], $r['activos']);
        $this->assertSame($ref['visitas'], $r['visitas_rango']);
        $this->assertSame($ref['con_visita'], $r['con_visita_rango']);
        $this->assertSame($ref['repiten'], $r['repiten_rango']);
        $this->assertSame($ref['citas_total'], $r['citas']['total']);
        $this->assertSame($ref['canceladas'], $r['citas']['canceladas']);
        $this->assertSame($ref['no_asistio'], $r['citas']['no_asistio']);
        $this->assertSame($ref['primera_visita'], $r['primera_visita_en_rango']);
        $this->assertSame(round(100 * $ref['canceladas'] / $ref['citas_total'], 1), $r['pct_cancelacion']);
        $this->assertSame(round(100 * $ref['no_asistio'] / $ref['citas_total'], 1), $r['pct_no_asistencia']);
        // Cancelada y no asistió son estados distintos y ninguna es visita.
        $this->assertGreaterThanOrEqual(1, $r['citas']['canceladas']);
        $this->assertGreaterThanOrEqual(1, $r['citas']['no_asistio']);
    }

    public function testCohortesNoSeInflanConCitasFuturasNiContarOtraVisitaDelMismoDia(): void
    {
        $repite = $this->usuario('Rex', 'Repite ' . $this->tag);
        $this->cita($repite, $this->dia(-150), 'completada');
        $this->cita($repite, $this->dia(-120), 'completada'); // +30 días: repite
        $noRepite = $this->usuario('Nelo', 'NoRepite ' . $this->tag);
        $this->cita($noRepite, $this->dia(-150), 'completada');
        $this->cita($noRepite, $this->dia(-150), 'completada', null, [], '15:00:00'); // mismo día: no es otra visita en un día posterior
        $this->cita($noRepite, $this->dia(-10), 'completada'); // fuera de los 90 días de la ventana
        $futura = $this->usuario('Fiona', 'Futura ' . $this->tag);
        $this->cita($futura, $this->dia(-100), 'completada');
        $this->cita($futura, $this->dia(8), 'completada'); // futura: se ignora
        $this->cita($futura, $this->dia(9), 'confirmada');
        $desde = $this->dia(-300); $hasta = $this->dia(0);
        $ref = $this->cohortesReferencia($desde, $hasta);
        $p = $this->panel(['desde' => $desde, 'hasta' => $hasta]);
        $obtenido = [];
        foreach ($p['cohortes'] as $co) { $obtenido[$co['cohorte']] = [$co['clientes'], $co['repiten']]; }
        // La implementación recorta a las 12 cohortes más recientes; se compara contra la misma ventana del cálculo independiente.
        $esperado = array_slice($ref, -12, null, true);
        $this->assertSame($esperado, $obtenido);
        $this->assertNotEmpty($obtenido);
        // Casos puntuales contra consulta cruda: Rex repite, los otros dos no.
        $rep = $this->cohortesReferencia($this->dia(-150), $this->dia(-150), [$repite, $noRepite, $futura]);
        $this->assertSame(2, array_sum(array_column($rep, 0)), 'Rex y Nelo tienen su primera visita ese día.');
        $this->assertSame(1, array_sum(array_column($rep, 1)), 'Solo Rex vuelve en un día posterior dentro de 90 días.');
    }

    // ---------------------------------------------------------------- listado, filtros, paginación

    public function testPaginacionDevuelveTotalPaginasYFilasSinSolapar(): void
    {
        $ids = [];
        for ($i = 1; $i <= 23; $i++) { $ids[] = $this->usuario('Pag' . str_pad((string)$i, 2, '0', STR_PAD_LEFT), 'Lista ' . $this->tag); }
        $cuenta = (int)$this->filas("SELECT COUNT(*) n FROM usuarios WHERE apellido LIKE 'Lista " . $this->tag . "%'")[0]['n'];
        $this->assertSame(23, $cuenta);
        $vistos = [];
        foreach ([1 => 10, 2 => 10, 3 => 3] as $pagina => $esperadas) {
            $p = $this->panel(['q' => $this->tag, 'por_pagina' => '10', 'pagina' => (string)$pagina, 'orden' => 'nombre'])['listado'];
            $this->assertSame(23, $p['total']);
            $this->assertSame(3, $p['paginas']);
            $this->assertSame($pagina, $p['pagina']);
            $this->assertCount($esperadas, $p['filas']);
            foreach ($p['filas'] as $f) { $this->assertNotContains((int)$f['id'], $vistos, 'Sin filas repetidas entre páginas.'); $vistos[] = (int)$f['id']; }
        }
        $this->assertEqualsCanonicalizing($ids, $vistos);
        $fuera = $this->panel(['q' => $this->tag, 'por_pagina' => '10', 'pagina' => '99'])['listado'];
        $this->assertSame(3, $fuera['pagina'], 'Una página inexistente se recorta a la última válida.');
        $this->assertCount(3, $fuera['filas']);
        $desc = $this->panel(['q' => $this->tag, 'por_pagina' => '10', 'orden' => 'nombre', 'dir' => 'desc'])['listado']['filas'];
        $this->assertSame('Pag23', $desc[0]['nombre']);
    }

    public function testFiltrosCombinadosYBusquedaPorTextoYTelefono(): void
    {
        $srvA = $this->servicio('Corte'); $srvB = $this->servicio('Tinte');
        $prof1 = $this->profesional('Sofía'); $prof2 = $this->profesional('Mario');
        $desde = $this->dia(-60); $hasta = $this->dia(0);
        $tel = '0987' . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        // Cumple todo: alta reciente, atendida por prof1 con srvA, consentimiento, activo.
        $ok = $this->usuario('Elena', 'Filtro ' . $this->tag, ['alta' => $this->dia(-20) . ' 09:00:00', 'consentimiento' => $this->dia(-19) . ' 09:00:00', 'telefono' => $tel]);
        $this->cita($ok, $this->dia(-10), 'completada', $prof1, [[$srvA, '20.00']]);
        // Mismo profesional pero otro servicio.
        $otroServicio = $this->usuario('Emilio', 'Filtro ' . $this->tag, ['alta' => $this->dia(-20) . ' 09:00:00', 'consentimiento' => $this->dia(-19) . ' 09:00:00']);
        $this->cita($otroServicio, $this->dia(-10), 'completada', $prof1, [[$srvB, '30.00']]);
        // Mismo servicio, otro profesional.
        $otroProf = $this->usuario('Enzo', 'Filtro ' . $this->tag, ['alta' => $this->dia(-20) . ' 09:00:00', 'consentimiento' => $this->dia(-19) . ' 09:00:00']);
        $this->cita($otroProf, $this->dia(-10), 'completada', $prof2, [[$srvA, '20.00']]);
        // Cumple servicio y profesional pero sin consentimiento.
        $sinConsent = $this->usuario('Edna', 'Filtro ' . $this->tag, ['alta' => $this->dia(-20) . ' 09:00:00']);
        $this->cita($sinConsent, $this->dia(-10), 'completada', $prof1, [[$srvA, '20.00']]);
        // Cumple todo, pero la visita fue cancelada (no cuenta) y su alta es antigua.
        $cancelada = $this->usuario('Erik', 'Filtro ' . $this->tag, ['alta' => $this->dia(-200) . ' 09:00:00', 'consentimiento' => $this->dia(-19) . ' 09:00:00']);
        $this->cita($cancelada, $this->dia(-10), 'cancelada', $prof1, [[$srvA, '20.00']]);
        // Cumple todo, pero la visita completada es anterior al periodo.
        $fueraPeriodo = $this->usuario('Ema', 'Filtro ' . $this->tag, ['alta' => $this->dia(-20) . ' 09:00:00', 'consentimiento' => $this->dia(-19) . ' 09:00:00']);
        $this->cita($fueraPeriodo, $this->dia(-100), 'completada', $prof1, [[$srvA, '20.00']]);

        $base = ['desde' => $desde, 'hasta' => $hasta];
        $ids = fn(array $f) => array_keys($this->listado($base + $f));
        $this->assertEqualsCanonicalizing([$ok, $sinConsent, $otroProf, $otroServicio, $cancelada, $fueraPeriodo], $ids([]));
        $this->assertEqualsCanonicalizing([$ok, $sinConsent], $ids(['profesional_id' => (string)$prof1, 'servicio_id' => (string)$srvA]));
        $this->assertSame([$ok], $ids(['profesional_id' => (string)$prof1, 'servicio_id' => (string)$srvA, 'consentimiento' => 'si']));
        $this->assertEqualsCanonicalizing([$sinConsent], $ids(['profesional_id' => (string)$prof1, 'servicio_id' => (string)$srvA, 'consentimiento' => 'no']));
        $this->assertSame([$ok], $ids(['profesional_id' => (string)$prof1, 'servicio_id' => (string)$srvA, 'consentimiento' => 'si', 'alta_desde' => $this->dia(-30), 'alta_hasta' => $this->dia(-10), 'segmento' => 'nuevo', 'membresia' => 'activo']));
        $this->assertSame([], $ids(['profesional_id' => (string)$prof1, 'servicio_id' => (string)$srvA, 'consentimiento' => 'si', 'alta_desde' => $this->dia(-5)]), 'El rango de alta excluye a todos.');
        // Con filtro de profesional/servicio, «visitas del periodo» cuenta solo las visitas que lo cumplen.
        $this->assertSame(1, (int)$this->listado($base + ['profesional_id' => (string)$prof1])[$ok]['visitas_rango']);
        // Búsqueda: nombre completo, correo y teléfono (con separadores).
        $this->assertSame([$ok], array_keys($this->listado(['q' => 'Elena Filtro ' . $this->tag])));
        $this->assertSame([$ok], array_keys($this->listado(['q' => substr($tel, 0, 4) . '-' . substr($tel, 4, 3) . ' ' . substr($tel, 7)])), 'Teléfono con separadores.');
        $this->assertSame([$otroProf], array_keys($this->listado(['q' => 'Enzo Filtro ' . $this->tag])));
        $this->assertSame([], array_keys($this->listado(['q' => 'Filtro %_ ' . $this->tag])), 'Los comodines LIKE del usuario se escapan.');
    }

    public function testEstadoDeMembresiaYConsentimientoSeFiltranPorSuFuenteReal(): void
    {
        $activo = $this->usuario('Maru', 'Mem ' . $this->tag);
        $susp = $this->usuario('Sabi', 'Mem ' . $this->tag, ['membresia' => 'suspendido', 'suspendido_motivo' => 'Prueba']);
        $legado = $this->usuario('Lena', 'Mem ' . $this->tag, ['membresia' => null]);
        $this->assertSame([$activo], array_keys($this->listado(['membresia' => 'activo'])));
        $this->assertSame([$susp], array_keys($this->listado(['membresia' => 'suspendido'])));
        $this->assertSame([$legado], array_keys($this->listado(['membresia' => 'sin_registro'])));
    }

    // ---------------------------------------------------------------- ficha 360°

    public function testFichaTieneCronologiaPreferenciasFidelidadConsentimientoYEspera(): void
    {
        $srv = $this->servicio('Masaje'); $prof = $this->profesional('Lía');
        $cli = $this->usuario('Fran', 'Ficha ' . $this->tag, ['alta' => $this->dia(-90) . ' 09:00:00', 'consentimiento' => $this->dia(-80) . ' 12:00:00', 'origen' => 'recepcion']);
        $c1 = $this->cita($cli, $this->dia(-30), 'completada', $prof, [[$srv, '40.00']]);
        $c2 = $this->cita($cli, $this->dia(-20), 'completada', $prof, [[$srv, '40.00']]);
        $this->cita($cli, $this->dia(-10), 'cancelada', $prof, [[$srv, '40.00']]);
        $this->sql("UPDATE citas SET cancelado_por='cliente', cancelado_at=? WHERE usuarioId=? AND estado='cancelada'", 'si', [$this->dia(-12) . ' 08:00:00', $cli])->close();
        $this->pago($c1, $cli, '40.00', 'manual', 'reembolsado', $this->dia(-30) . ' 12:00:00', $this->dia(-28) . ' 12:00:00');
        $this->pago($c2, $cli, '40.00', 'mock', 'completado', $this->dia(-20) . ' 12:00:00');
        $this->sql("INSERT INTO fidelidad_movimientos(usuario_id,puntos,tipo,referencia) VALUES(?,10,'cita',?)", 'is', [$cli, 'cita:crm' . $this->tag . 'a'])->close();
        $this->sql("INSERT INTO fidelidad_movimientos(usuario_id,puntos,tipo,referencia,beneficio_snapshot) VALUES(?,-100,'canje',?,'Cortesía')", 'is', [$cli, 'canje:crm' . $this->tag])->close();
        $this->sql("INSERT INTO fidelidad_movimientos(usuario_id,puntos,tipo,referencia) VALUES(?,100,'cita',?)", 'is', [$cli, 'cita:crm' . $this->tag . 'b'])->close();
        $this->sql("INSERT INTO lista_espera(usuario_id,servicio_id,fecha) VALUES(?,?,?)", 'iis', [$cli, $srv, $this->dia(14)])->close();

        $svc = new CrmService(new CrmRepository($this->db));
        $f = $svc->ficha($cli, self::FULL);
        $this->assertSame('Fran', $f['cliente']['nombre']);
        $this->assertSame(2, (int)$f['cliente']['visitas_total']);
        $this->assertSame(10, $f['fidelidad']['puntos']);
        $this->assertSame([0, 1], [$f['fidelidad']['canjes_entregados'], $f['fidelidad']['canjes_pendientes']]);
        $this->assertSame('Masaje ' . $this->tag, $f['preferencias']['servicios'][0]['nombre']);
        $this->assertSame(2, (int)$f['preferencias']['servicios'][0]['veces']);
        $this->assertSame('Lía ' . $this->tag, $f['preferencias']['profesionales'][0]['nombre']);
        $this->assertSame(1, (int)$f['cliente']['consentimiento']);

        $tipos = array_count_values(array_column($f['cronologia'], 'tipo'));
        $this->assertEquals(['alta' => 1, 'cita' => 3, 'cancelacion' => 1, 'cobro' => 2, 'devolucion' => 1, 'fidelidad' => 3, 'consentimiento' => 1, 'espera' => 1], $tipos);
        $cuando = array_column($f['cronologia'], 'cuando');
        $ordenado = $cuando; rsort($ordenado);
        $this->assertSame($ordenado, $cuando, 'Cronología de lo más reciente a lo más antiguo.');
        $this->assertStringContainsString('pasarela simulada', implode(' ', array_column($f['cronologia'], 'titulo')));
        $this->assertSame(0, $f['gasto']['neto_real']);
        $this->assertSame(4000, $f['gasto']['neto_simulado']);

        // Sin analítica (recepción): ni cobros, ni devoluciones, ni importes, ni pagos.
        $r = $svc->ficha($cli, self::RECEPCION);
        $this->assertNull($r['gasto']);
        $this->assertSame([], $r['pagos']);
        $tr = array_count_values(array_column($r['cronologia'], 'tipo'));
        $this->assertArrayNotHasKey('cobro', $tr);
        $this->assertArrayNotHasKey('devolucion', $tr);
        foreach ($r['citas'] as $ci) { $this->assertArrayNotHasKey('importe', $ci); $this->assertArrayNotHasKey('estado_pago', $ci); }
        $this->assertStringNotContainsString('importe de servicios', implode(' ', array_column($r['cronologia'], 'detalle')));
        $this->assertNull($svc->ficha(PHP_INT_MAX >> 33, self::FULL), 'Una cuenta inexistente no tiene ficha.');
    }

    // ---------------------------------------------------------------- permisos por URL

    public function testAnonimoNoEntraPorUrlDirecta(): void
    {
        $_SESSION = [];
        foreach ([fn() => $this->index([]), fn() => $this->ficha(1), fn() => $this->exportar([])] as $llamada) {
            $r = $llamada();
            $this->assertSame(302, $r['termino'], 'Un anónimo es redirigido al inicio de sesión.');
            $this->assertStringNotContainsString('Clientes', $r['cuerpo']);
        }
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        foreach ([fn() => $this->index([]), fn() => $this->ficha(1), fn() => $this->exportar([])] as $llamada) {
            $r = $llamada();
            $this->assertSame(401, $r['http'], 'Con Accept JSON el anónimo recibe 401.');
        }
    }

    public function testClienteComunRecibe403EnTodasLasRutas(): void
    {
        $visible = $this->usuario('Victima', 'Privada ' . $this->tag);
        $cuenta = $this->usuario('Carla', 'Cliente ' . $this->tag, ['rol' => 'cliente', 'membresia' => 'activo']);
        $this->sesion($cuenta);
        foreach ([fn() => $this->index(['q' => $this->tag]), fn() => $this->ficha($visible), fn() => $this->exportar(['tipo' => 'clientes'])] as $llamada) {
            $r = $llamada();
            $this->assertSame(403, $r['http']);
            $this->assertStringNotContainsString('Victima', $r['cuerpo']);
            $this->assertStringNotContainsString('Privada', $r['cuerpo']);
        }
        // Sesión heredada con admin=1 en $_SESSION no concede nada: manda el rol del servidor.
        $_SESSION['admin'] = '1';
        $this->assertSame(403, $this->index([])['http']);
    }

    public function testRecepcionVeListadoYFichaSinGastoNiVipNiCohortesNiExportar(): void
    {
        $srv = $this->servicio('Facial');
        $cli = $this->usuario('Tomás', 'Operativo ' . $this->tag);
        $cita = $this->cita($cli, $this->dia(-4), 'completada', null, [[$srv, '77.77']]);
        $this->pago($cita, $cli, '77.77', 'manual', 'completado', $this->dia(-4) . ' 10:00:00');
        $this->sesion($this->usuario('Rosa', 'Recep ' . $this->tag, ['rol' => 'recepcion', 'membresia' => null]));

        $r = $this->index(['q' => $this->tag]);
        $this->assertSame(200, $r['http']);
        $this->assertStringContainsString('Tomás', $r['cuerpo']);
        $this->assertStringContainsString('Cómo se calcula', $r['cuerpo']);
        foreach (['Gasto neto real', 'Dinero del periodo', '77.77', 'Ticket promedio', 'Retención por cohorte', 'Cobros reales por mes', 'Repetición en el periodo'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $r['cuerpo'], "Recepción no debe ver: $prohibido");
        }
        $this->assertStringNotContainsString('VIP', preg_replace('/<select.*?<\/select>/s', '', $r['cuerpo']) ?? '', 'Recepción no ve el segmento VIP.');
        $this->assertSame(422, $this->index(['segmento' => 'vip'])['http'], 'El filtro VIP se rechaza sin analítica.');
        $this->assertSame(422, $this->index(['orden' => 'gasto'])['http']);

        $f = $this->ficha($cli);
        $this->assertSame(200, $f['http']);
        $this->assertStringContainsString('Tomás', $f['cuerpo']);
        $this->assertStringNotContainsString('Gasto observado', $f['cuerpo']);
        $this->assertStringNotContainsString('77.77', $f['cuerpo']);
        $this->assertStringNotContainsString('Importe de servicios', $f['cuerpo']);

        $this->assertSame(403, $this->exportar(['tipo' => 'clientes'])['http'], 'Recepción no exporta.');
    }

    public function testProfesionalSoloVeSusClientesConVistaMinima(): void
    {
        $srv = $this->servicio('Manicure');
        $cuentaProf = $this->usuario('Paula', 'Prof ' . $this->tag, ['rol' => 'profesional', 'membresia' => null]);
        $prof = $this->profesional('Paula', $cuentaProf);
        $otro = $this->profesional('Otro');
        $suyo = $this->usuario('Sara', 'Suyo ' . $this->tag, ['consentimiento' => $this->dia(-30) . ' 10:00:00']);
        $this->cita($suyo, $this->dia(-9), 'completada', $prof, [[$srv, '18.00']]);
        $this->cita($suyo, $this->dia(-2), 'completada', $prof, [[$srv, '18.00']]);
        $ajeno = $this->usuario('Ajeno', 'Cliente ' . $this->tag);
        $this->cita($ajeno, $this->dia(-9), 'completada', $otro, [[$srv, '18.00']]);
        $soloCancelada = $this->usuario('Cancela', 'Cliente ' . $this->tag);
        $this->cita($soloCancelada, $this->dia(-9), 'cancelada', $prof, [[$srv, '18.00']]);
        $futura = $this->usuario('Futura', 'Cliente ' . $this->tag);
        $this->cita($futura, $this->dia(5), 'confirmada', $prof, [[$srv, '18.00']]);
        $this->sesion($cuentaProf);

        $r = $this->index(['q' => $this->tag]);
        $this->assertSame(200, $r['http']);
        $this->assertStringContainsString('Sara', $r['cuerpo']);
        foreach (['Ajeno', 'Cancela', 'Futura'] as $no) { $this->assertStringNotContainsString($no, $r['cuerpo'], "$no no es cliente atendido por este profesional."); }
        foreach (['Gasto', 'Consentimiento', 'Segmento', 'Indicadores', 'Ticket'] as $no) { $this->assertStringNotContainsString($no, $r['cuerpo']); }

        $ok = $this->ficha($suyo);
        $this->assertSame(200, $ok['http']);
        $this->assertStringContainsString('Manicure ' . $this->tag, $ok['cuerpo']);
        $this->assertStringContainsString('Sara', $ok['cuerpo']);
        foreach (['Gasto observado', 'Consentimiento comercial', 'Fidelidad', 'Cronología'] as $no) { $this->assertStringNotContainsString($no, $ok['cuerpo']); }
        foreach ([$ajeno, $soloCancelada, $futura, PHP_INT_MAX >> 33] as $id) {
            $this->assertSame(404, $this->ficha($id)['http'], 'Cliente ajeno o inexistente: no se distingue.');
        }
        $this->assertSame(403, $this->exportar([])['http']);
    }

    public function testProfesionalSinFichaVinculadaVeEstadoVacioUtil(): void
    {
        $cuenta = $this->usuario('Sinficha', 'Prof ' . $this->tag, ['rol' => 'profesional', 'membresia' => null]);
        $this->sesion($cuenta);
        $r = $this->index([]);
        $this->assertSame(200, $r['http']);
        $this->assertStringContainsString('no está vinculada a una ficha profesional', $r['cuerpo']);
    }

    public function testFinanzasVeAgregadosPeroNoPersonasNiFichas(): void
    {
        $cli = $this->usuario('Identificable', 'Reservado ' . $this->tag);
        $this->cita($cli, $this->dia(-3), 'completada');
        $this->sesion($this->usuario('Fina', 'Finanzas ' . $this->tag, ['rol' => 'finanzas', 'membresia' => null]));
        $r = $this->index(['q' => $this->tag]);
        $this->assertSame(200, $r['http']);
        $this->assertStringContainsString('Gasto neto real', $r['cuerpo']);
        $this->assertStringContainsString('Retención por cohorte', $r['cuerpo']);
        $this->assertStringNotContainsString('Identificable', $r['cuerpo'], 'Sin clientes.ver no hay listado de personas.');
        $this->assertStringNotContainsString('Listado de clientes', $r['cuerpo']);
        $this->assertSame(403, $this->ficha($cli)['http']);
        $this->assertSame(403, $this->exportar([])['http']);
    }

    public function testPropietarioYGerenteVenTodoYSoloEllosExportan(): void
    {
        $cli = $this->usuario('Visible', 'Completa ' . $this->tag);
        $cita = $this->cita($cli, $this->dia(-2), 'completada');
        $this->pago($cita, $cli, '12.34', 'manual', 'completado', $this->dia(-2) . ' 10:00:00');
        foreach (['propietario', 'gerente'] as $rol) {
            $this->sesion($this->usuario('Jefe', ucfirst($rol) . ' ' . $this->tag, ['rol' => $rol, 'membresia' => null]));
            $r = $this->index(['q' => $this->tag]);
            $this->assertSame(200, $r['http'], $rol);
            $this->assertStringContainsString('Visible', $r['cuerpo']);
            $this->assertStringContainsString('$12.34', $r['cuerpo']);
            $this->assertStringContainsString('Exportar lista comercial', $r['cuerpo']);
            $this->assertSame(200, $this->ficha($cli)['http']);
            $this->assertStringContainsString('Gasto observado', $this->ficha($cli)['cuerpo']);
            $e = $this->exportar(['q' => $this->tag]);
            $this->assertSame(200, $e['http']);
            $this->assertSame(200, $e['termino']);
        }
    }

    public function testFalloTecnicoDeDatosEsServicioNoDisponibleNoSinPermiso(): void
    {
        $this->sesion($this->usuario('Dueña', 'Propietaria ' . $this->tag, ['rol' => 'propietario', 'membresia' => null]));
        $cerrada = new \mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', getenv('DB_NAME') ?: 'appsalon_test', (int)(getenv('DB_PORT') ?: 3306));
        $cerrada->close();
        CrmController::setCrmService(new CrmService(new CrmRepository($cerrada)));
        $registroPrevio = ini_set('error_log', '/dev/null'); // el controlador registra el diagnóstico sanitizado; no ensuciar la salida
        try {
        foreach ([fn() => $this->index([]), fn() => $this->ficha(1), fn() => $this->exportar([])] as $llamada) {
            try {
                $llamada();
                $this->fail('Debe lanzar ServiceUnavailableException.');
            } catch (ServiceUnavailableException $e) {
                $this->assertStringNotContainsString('permiso', mb_strtolower($e->getMessage()));
            }
        }
        } finally { ini_set('error_log', (string)$registroPrevio); }
    }

    public function testEsquemaInexistenteTambienEsFalloTecnico(): void
    {
        $repo = new CrmRepository($this->db);
        $this->expectException(\Repositories\PersistenceException::class);
        // Consulta preparada inválida: provoca error de preparación, jamás un resultado vacío silencioso.
        $m = new \ReflectionMethod($repo, 'filas');
        $m->setAccessible(true);
        $m->invoke($repo, 'SELECT * FROM tabla_que_no_existe_crm');
    }

    // ---------------------------------------------------------------- escape y exportación

    public function testNombresConHtmlSeEscapanEnListadoYFicha(): void
    {
        $xss = '<script>alert(1)</script>';
        $cli = $this->usuario($xss, 'Xss ' . $this->tag);
        $this->sesion($this->usuario('Dueño', 'Xss ' . $this->tag, ['rol' => 'propietario', 'membresia' => null]));
        foreach ([$this->index(['q' => $this->tag]), $this->ficha($cli)] as $r) {
            $this->assertSame(200, $r['http']);
            $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $r['cuerpo']);
            $this->assertStringNotContainsString('<script>alert(1)', $r['cuerpo']);
        }
    }

    public function testCsvNeutralizaFormulasYNoIncluyeFormularios(): void
    {
        $peligrosos = ['=2+2', '+cmd', '-1+1', '@SUM(1)'];
        $ids = [];
        foreach ($peligrosos as $i => $nombre) {
            $ids[] = $this->usuario($nombre, 'Csv' . $i . ' ' . $this->tag, ['telefono' => '+59399' . str_pad((string)$i, 4, '0', STR_PAD_LEFT)]);
        }
        $cli = $ids[0];
        $this->sql("INSERT INTO formularios_cliente(titulo,preguntas,creado_por) VALUES('Salud privada','[]',?)", 'i', [$cli])->close();
        $form = (int)$this->db->insert_id; $this->formularios[] = $form;
        $this->sql('INSERT INTO formulario_respuestas(formulario_id,usuario_id,respuestas) VALUES(?,?,?)', 'iis', [$form, $cli, json_encode(['p0' => 'RESPUESTA-DE-SALUD-CONFIDENCIAL'])])->close();
        $this->cita($cli, $this->dia(-3), 'completada');
        $this->sesion($this->usuario('Dueña', 'Csv ' . $this->tag, ['rol' => 'propietario', 'membresia' => null]));

        foreach (['clientes', 'comercial'] as $tipo) {
            $e = $this->exportar(['tipo' => $tipo, 'q' => $this->tag]);
            $this->assertSame(200, $e['http']);
            $this->assertStringNotContainsString('RESPUESTA-DE-SALUD-CONFIDENCIAL', $e['cuerpo']);
            $this->assertStringNotContainsString('Salud privada', $e['cuerpo']);
            $this->assertSame('text/csv; charset=UTF-8', CrmController::getUltimosHeadersExportacion()['Content-Type']);
            $this->assertStringContainsString('attachment', CrmController::getUltimosHeadersExportacion()['Content-Disposition']);
            $this->assertStringContainsString('no-store', CrmController::getUltimosHeadersExportacion()['Cache-Control']);
        }
        $e = $this->exportar(['tipo' => 'clientes', 'q' => $this->tag]);
        $filas = $this->csv($e['cuerpo']);
        $this->assertCount(4, $filas);
        $nombres = array_column($filas, 'Nombre');
        foreach ($peligrosos as $p) { $this->assertContains("'" . $p, $nombres, "$p debe quedar neutralizado con una comilla simple."); }
        $this->assertContains("'+593990000", array_column($filas, 'Teléfono'));
        foreach ($filas as $fila) {
            foreach ($fila as $col => $celda) {
                $this->assertDoesNotMatchRegularExpression('/^[=+\-@\t\r]/', (string)$celda, "Celda de «{$col}» sin neutralizar: {$celda}");
            }
        }
        $this->assertSame('Sí', $filas[0]['Cuenta confirmada']);
        $this->assertArrayHasKey('Gasto neto real (USD)', $filas[0]);
    }

    public function testListaComercialRespetaConsentimientoConfirmacionYSuspension(): void
    {
        $acepta = $this->usuario('Acepta', 'Com ' . $this->tag, ['consentimiento' => $this->dia(-10) . ' 10:00:00']);
        $sinConsent = $this->usuario('Nunca', 'Com ' . $this->tag);
        $retirado = $this->usuario('Retira', 'Com ' . $this->tag);
        $this->sql('INSERT INTO perfiles_usuario(usuarioId,marketing_email,consentimiento_marketing_at) VALUES(?,0,NULL)', 'i', [$retirado])->close();
        $sinConfirmar = $this->usuario('SinConf', 'Com ' . $this->tag, ['consentimiento' => $this->dia(-10) . ' 10:00:00', 'confirmado' => 0]);
        $suspendido = $this->usuario('Suspe', 'Com ' . $this->tag, ['consentimiento' => $this->dia(-10) . ' 10:00:00', 'membresia' => 'suspendido']);
        $flagSinFecha = $this->usuario('FlagSolo', 'Com ' . $this->tag);
        $this->sql('INSERT INTO perfiles_usuario(usuarioId,marketing_email,consentimiento_marketing_at) VALUES(?,1,NULL)', 'i', [$flagSinFecha])->close();
        $this->sesion($this->usuario('Dueña', 'Com ' . $this->tag, ['rol' => 'gerente', 'membresia' => null]));
        $e = $this->exportar(['tipo' => 'comercial', 'q' => $this->tag]);
        $this->assertSame(200, $e['http']);
        $filas = $this->csv($e['cuerpo']);
        $this->assertSame([(string)$acepta], array_column($filas, 'ID'));
        $this->assertSame(['ID', 'Nombre', 'Apellido', 'Correo', 'Consentimiento desde', 'Versión del consentimiento'], array_keys($filas[0]));
        // La exportación operativa sí lista a todos los clientes que coinciden, con su indicador de consentimiento.
        $todos = $this->csv($this->exportar(['tipo' => 'clientes', 'q' => $this->tag])['cuerpo']);
        $porId = array_column($todos, null, 'ID');
        $this->assertSame('Sí', $porId[(string)$acepta]['Consentimiento comercial']);
        $this->assertSame('No', $porId[(string)$sinConsent]['Consentimiento comercial']);
        $this->assertSame('No', $porId[(string)$retirado]['Consentimiento comercial']);
        $this->assertSame('No', $porId[(string)$flagSinFecha]['Consentimiento comercial'], 'Sin fecha de consentimiento no hay consentimiento válido.');
        // Parámetros inválidos de exportación se rechazan sin generar archivo.
        $malo = $this->exportar(['tipo' => 'todo']);
        $this->assertSame(422, $malo['http']);
        $this->assertStringNotContainsString("\xEF\xBB\xBF", $malo['cuerpo']);
        $this->assertSame(422, $this->exportar(['desde' => '2026-13-01'])['http']);
    }

    // ---------------------------------------------------------------- referencias independientes

    /**
     * Cálculo independiente de las métricas globales: lee filas crudas y decide en PHP, sin reutilizar el SQL del módulo.
     * @return array<string,int>
     */
    private function referencia(string $desde, string $hasta, int $diasActivo): array
    {
        $hoy = $this->dia(0);
        $usuarios = array_column($this->filas('SELECT id,admin FROM usuarios'), 'admin', 'id');
        $roles = array_column($this->filas('SELECT usuario_id,rol FROM usuario_accesos'), 'rol', 'usuario_id');
        $membresias = array_column($this->filas('SELECT usuario_id,creado_en FROM clientes'), 'creado_en', 'usuario_id');
        $citas = array_filter($this->filas('SELECT id,usuarioId,fecha,estado FROM citas'), fn($c) => isset($usuarios[$c['usuarioId']]));
        $conCitas = array_flip(array_column($citas, 'usuarioId'));
        $esCliente = [];
        foreach ($usuarios as $id => $admin) {
            $rol = $roles[$id] ?? ((int)$admin === 1 ? 'propietario' : 'cliente');
            if (isset($membresias[$id]) || $rol === 'cliente' || isset($conCitas[$id])) { $esCliente[$id] = true; }
        }
        $r = ['total' => count($esCliente), 'membresia_sin_registro' => 0, 'nuevos' => 0, 'activos' => 0, 'visitas' => 0, 'con_visita' => 0, 'repiten' => 0, 'citas_total' => 0,
            'canceladas' => 0, 'no_asistio' => 0, 'primera_visita' => 0, 'cobros_real' => 0, 'dev_real' => 0, 'sim_neto' => 0, 'n_cobros_real' => 0];
        foreach ($esCliente as $id => $_) {
            if (!isset($membresias[$id])) { $r['membresia_sin_registro']++; }
            elseif (substr($membresias[$id], 0, 10) >= $desde && substr($membresias[$id], 0, 10) <= $hasta) { $r['nuevos']++; }
        }
        $completadas = [];
        foreach ($citas as $c) {
            if (!isset($esCliente[$c['usuarioId']])) { continue; }
            if ($c['fecha'] >= $desde && $c['fecha'] <= min($hasta, $hoy)) {
                $r['citas_total']++;
                if ($c['estado'] === 'cancelada') { $r['canceladas']++; }
                if ($c['estado'] === 'no_asistio') { $r['no_asistio']++; }
            }
            if ($c['estado'] === 'completada' && $c['fecha'] <= $hoy) { $completadas[$c['usuarioId']][] = $c['fecha']; }
        }
        foreach ($completadas as $uid => $fechas) {
            $ultima = max($fechas); $primera = min($fechas);
            if ((new \DateTimeImmutable($hoy))->diff(new \DateTimeImmutable($ultima))->days <= $diasActivo) { $r['activos']++; }
            $enRango = array_filter($fechas, fn($f) => $f >= $desde && $f <= min($hasta, $hoy));
            if ($enRango) { $r['con_visita']++; $r['visitas'] += count($enRango); if (count($enRango) >= 2) { $r['repiten']++; } }
            if ($primera >= $desde && $primera <= $hasta) { $r['primera_visita']++; }
        }
        $citaUsuario = array_column($citas, 'usuarioId', 'id');
        foreach ($this->filas('SELECT cita_id,cita_referencia_id,usuario_id,proveedor,monto,estado,pagado_at,created_at,reembolsado_at FROM pagos') as $p) {
            $uid = $p['usuario_id'] ?? ($citaUsuario[$p['cita_id'] ?? $p['cita_referencia_id']] ?? null);
            if ($uid === null || !isset($esCliente[(int)$uid])) { continue; }
            $mock = trim((string)$p['proveedor']) === '' || trim((string)$p['proveedor']) === 'mock';
            $cents = (int)round(((float)$p['monto']) * 100);
            $fCobro = substr((string)($p['pagado_at'] ?? $p['created_at']), 0, 10);
            $fDev = substr((string)($p['reembolsado_at'] ?? $p['pagado_at'] ?? $p['created_at']), 0, 10);
            $cobra = in_array($p['estado'], ['completado', 'reembolsado'], true) && $fCobro >= $desde && $fCobro <= $hasta;
            $devuelve = $p['estado'] === 'reembolsado' && $fDev >= $desde && $fDev <= $hasta;
            if ($mock) { $r['sim_neto'] += ($cobra ? $cents : 0) - ($devuelve ? $cents : 0); continue; }
            if ($cobra) { $r['cobros_real'] += $cents; $r['n_cobros_real']++; }
            if ($devuelve) { $r['dev_real'] += $cents; }
        }
        return $r;
    }

    /**
     * Cohortes por mes de primera visita completada (hasta hoy) calculadas en PHP.
     * @param int[]|null $soloUsuarios restringe a estos clientes
     * @return array<string,array{0:int,1:int}> cohorte => [clientes, repiten en <= 90 días]
     */
    private function cohortesReferencia(string $desde, string $hasta, ?array $soloUsuarios = null): array
    {
        $hoy = $this->dia(0);
        $porUsuario = [];
        foreach ($this->filas("SELECT usuarioId,fecha FROM citas WHERE estado='completada'") as $c) {
            if ($c['fecha'] > $hoy) { continue; }
            if ($soloUsuarios !== null && !in_array((int)$c['usuarioId'], $soloUsuarios, true)) { continue; }
            $porUsuario[$c['usuarioId']][] = $c['fecha'];
        }
        $existentes = array_flip(array_column($this->filas('SELECT id FROM usuarios'), 'id'));
        $res = [];
        foreach ($porUsuario as $uid => $fechas) {
            if (!isset($existentes[$uid])) { continue; }
            $primera = min($fechas);
            if ($primera < $desde || $primera > $hasta) { continue; }
            $limite = min((new \DateTimeImmutable($primera))->modify('+90 days')->format('Y-m-d'), $hoy);
            $repite = count(array_filter($fechas, fn($f) => $f > $primera && $f <= $limite)) > 0 ? 1 : 0;
            $mes = substr($primera, 0, 7);
            $res[$mes] ??= [0, 0];
            $res[$mes][0]++; $res[$mes][1] += $repite;
        }
        ksort($res);
        return $res;
    }
}

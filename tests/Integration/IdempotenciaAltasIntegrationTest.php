<?php

namespace Tests\Integration;

use AppTerminationException;
use Classes\ConflictoIdempotenciaException;
use Classes\Email;
use Classes\Idempotencia;
use Controllers\LoginController;
use Model\ActiveRecord;
use Model\Usuario;
use MVC\Router;
use PHPUnit\Framework\TestCase;
use Repositories\ProfesionalRepository;
use Repositories\ServicioRepository;
use Repositories\UsuarioRepository;
use Services\AuthService;
use Services\ProfesionalService;

/**
 * Altas sin duplicados: doble clic, reintentos, solicitudes concurrentes (procesos reales),
 * respuesta perdida tras el commit, claves reutilizadas y restricciones SQL verificadas directamente.
 */
final class IdempotenciaAltasIntegrationTest extends TestCase
{
    private \mysqli $db;
    private int $correos = 0;
    private int $servicioId = 0;
    private const SUFIJO = '@idempotencia.test';

    protected function setUp(): void
    {
        $this->db = $this->conexion();
        ActiveRecord::setDB($this->db);
        validar_base_datos_prueba($this->db);
        $this->limpiar();
        $this->db->query("INSERT INTO servicios(nombre,precio,duracion_minutos) VALUES ('Servicio Idempotencia',10.00,30)");
        $this->servicioId = (int)$this->db->insert_id;
        $this->correos = 0;
        Idempotencia::$observadorTrasReserva = null;
        Email::setTransport(function (): bool { return true; });
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $_SESSION = []; $_POST = []; $_GET = [];
        $_SERVER['REMOTE_ADDR'] = '198.51.100.88';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        LoginController::setAuthService(null);
        Usuario::limpiarAlertas();
        http_response_code(200);
        unset($GLOBALS['__appsalon_redireccion']);
    }

    protected function tearDown(): void
    {
        Idempotencia::$observadorTrasReserva = null;
        if ($this->db->ping()) {
            $this->db->query("DROP TRIGGER IF EXISTS test_fail_prof_idem");
            $this->limpiar();
        }
        ActiveRecord::setDB($this->db);
        LoginController::setAuthService(null);
        Email::setTransport(null);
        $_SESSION = []; $_POST = []; $_GET = [];
        http_response_code(200);
    }

    private function conexion(): \mysqli
    {
        $db = new \mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', getenv('DB_NAME') ?: 'appsalon_test', (int)(getenv('DB_PORT') ?: 3306));
        $db->set_charset('utf8mb4');
        return $db;
    }

    private function limpiar(): void
    {
        $this->db->query("DELETE FROM usuarios WHERE email LIKE '%" . self::SUFIJO . "'");
        $this->db->query("DELETE FROM profesionales WHERE nombre LIKE 'Idem %'");
        $this->db->query("DELETE FROM servicios WHERE nombre='Servicio Idempotencia'");
        $this->db->query("DELETE FROM operaciones_idempotentes WHERE scope IN ('cliente.registro','profesional.crear') AND actor LIKE '%idem%'");
        $this->db->query("DELETE FROM operaciones_idempotentes WHERE actor LIKE 'anonimo:%' OR actor LIKE 'equipo:9%'");
        $this->db->query("DELETE FROM intentos_login");
    }

    private function auth(?callable $dispatcher = null): AuthService
    {
        return new AuthService(new UsuarioRepository($this->db), $dispatcher ?? function () { $this->correos++; return true; });
    }

    private function datosCliente(string $email, string $nombre = 'Ana'): array
    {
        return ['nombre' => $nombre, 'apellido' => 'Repetida', 'telefono' => '0991234567', 'email' => $email, 'password' => 'ClaveSegura123'];
    }

    private function emailUnico(string $prefijo = 'cli'): string
    {
        return $prefijo . '-' . bin2hex(random_bytes(4)) . self::SUFIJO;
    }

    private function contar(string $sql): int
    {
        return (int)$this->db->query($sql)->fetch_assoc()['n'];
    }

    // ---------------------------------------------------------------- Registro de clientes

    public function testDobleClicSecuencialConLaMismaClaveCreaUnSoloClienteYUnSoloCorreo(): void
    {
        $email = $this->emailUnico();
        $clave = Idempotencia::nuevaClave();
        $r1 = $this->auth()->registrar($this->datosCliente($email), null, $clave, 'anonimo:idem-a');
        $r2 = $this->auth()->registrar($this->datosCliente($email), null, $clave, 'anonimo:idem-a');
        $r3 = $this->auth()->registrar($this->datosCliente($email), null, $clave, 'anonimo:idem-a');

        $this->assertSame(AuthService::STATUS_OK, $r1['status']);
        $this->assertFalse($r1['repetida']);
        foreach ([$r2, $r3] as $repetida) {
            $this->assertSame(AuthService::STATUS_OK, $repetida['status']);
            $this->assertTrue($repetida['repetida']);
            $this->assertSame($r1['id'], $repetida['id'], 'Se recupera el mismo identificador');
        }
        $this->assertSame(1, $this->correos, 'Un solo correo de confirmación');
        $this->assertSame(1, $this->contar("SELECT COUNT(*) n FROM usuarios WHERE email='{$email}'"));
        $this->assertSame(1, $this->contar("SELECT COUNT(*) n FROM clientes c JOIN usuarios u ON u.id=c.usuario_id WHERE u.email='{$email}'"));
        $this->assertSame(1, $this->contar("SELECT COUNT(*) n FROM operaciones_idempotentes WHERE actor='anonimo:idem-a'"));
    }

    public function testLosReintentosNoConsumenElLimiteDeIntentosDeRegistro(): void
    {
        $email = $this->emailUnico();
        $clave = Idempotencia::nuevaClave();
        $this->auth()->registrar($this->datosCliente($email), '198.51.100.99', $clave, 'anonimo:idem-b');
        for ($i = 0; $i < 6; $i++) {
            $r = $this->auth()->registrar($this->datosCliente($email), '198.51.100.99', $clave, 'anonimo:idem-b');
            $this->assertSame(AuthService::STATUS_OK, $r['status'], 'Reintento #' . ($i + 1) . ' no debe limitarse');
        }
        $intentos = $this->contar("SELECT COALESCE(SUM(intentos),0) n FROM intentos_login WHERE tipo='email_register'");
        $this->assertLessThanOrEqual(1, $intentos);
    }

    public function testMismaClaveConDatosDistintosSeRechazaComoConflictoSinModificarElAltaOriginal(): void
    {
        $email = $this->emailUnico();
        $clave = Idempotencia::nuevaClave();
        $original = $this->auth()->registrar($this->datosCliente($email, 'Ana'), null, $clave, 'anonimo:idem-c');
        $this->assertSame(AuthService::STATUS_OK, $original['status']);

        $otro = $this->auth()->registrar($this->datosCliente($email, 'Beatriz'), null, $clave, 'anonimo:idem-c');
        $this->assertSame(AuthService::STATUS_CONFLICT_CLAVE, $otro['status']);
        $otroCorreo = $this->auth()->registrar($this->datosCliente($this->emailUnico('otro')), null, $clave, 'anonimo:idem-c');
        $this->assertSame(AuthService::STATUS_CONFLICT_CLAVE, $otroCorreo['status']);

        $this->assertSame('Ana', $this->db->query("SELECT nombre FROM usuarios WHERE email='{$email}'")->fetch_assoc()['nombre']);
        $this->assertSame(1, $this->correos);
        $this->assertSame(1, $this->contar("SELECT COUNT(*) n FROM usuarios WHERE email LIKE '%" . self::SUFIJO . "'"));
    }

    public function testLaMismaClaveDeOtroActorNoRecuperaNiVeElResultadoAjeno(): void
    {
        $clave = Idempotencia::nuevaClave();
        $emailA = $this->emailUnico('a');
        $emailB = $this->emailUnico('b');
        $a = $this->auth()->registrar($this->datosCliente($emailA), null, $clave, 'anonimo:idem-actor1');
        $b = $this->auth()->registrar($this->datosCliente($emailB), null, $clave, 'anonimo:idem-actor2');
        $this->assertSame(AuthService::STATUS_OK, $b['status']);
        $this->assertFalse($b['repetida'], 'Otro actor no recibe el resultado del primero');
        $this->assertNotSame($a['id'], $b['id']);
        $this->assertSame(2, $this->correos);
    }

    public function testDosClavesDistintasParaElMismoCorreoSoloCreanUnaIdentidad(): void
    {
        $email = $this->emailUnico();
        $r1 = $this->auth()->registrar($this->datosCliente($email), null, Idempotencia::nuevaClave(), 'anonimo:idem-d');
        $r2 = $this->auth()->registrar($this->datosCliente($email), null, Idempotencia::nuevaClave(), 'anonimo:idem-d');
        $this->assertSame(AuthService::STATUS_OK, $r1['status']);
        // Contrato deliberadamente cambiado (DEF-13): antes STATUS_CONFLICT + «El usuario ya está registrado» (enumeración).
        // Ahora la respuesta es la misma que la de un correo nuevo, sin alertas ni identidad nueva.
        $this->assertSame(AuthService::STATUS_OK, $r2['status']);
        $this->assertSame([], $r2['alertas'], 'Sin mensajes que revelen la existencia de la cuenta');
        $this->assertNull($r2['id'], 'La segunda solicitud no creó identidad');
        $this->assertSame(1, $this->contar("SELECT COUNT(*) n FROM usuarios WHERE email='{$email}'"));
        // La cuenta seguía sin confirmar: la segunda solicitud emite un token nuevo y reenvía la confirmación (2 envíos en total).
        $this->assertSame(2, $this->correos);
    }

    public function testElUniqueSqlImpideCorreosEquivalentesAunSaltandoseLaAplicacion(): void
    {
        $email = $this->emailUnico('Mixto');
        $r = $this->auth()->registrar($this->datosCliente($email), null, Idempotencia::nuevaClave(), 'anonimo:idem-e');
        $this->assertSame(AuthService::STATUS_OK, $r['status']);
        $variantes = [strtoupper($email), ' ' . $email, $email . ' ', '  ' . strtolower($email) . '  '];
        foreach ($variantes as $variante) {
            $stmt = $this->db->prepare("INSERT INTO usuarios(nombre,apellido,email,password,telefono,admin,confirmado) VALUES ('X','Y',?,'h','0991234567',0,0)");
            $stmt->bind_param('s', $variante);
            $ok = $stmt->execute();
            $errno = $stmt->errno;
            $stmt->close();
            $this->assertFalse($ok, 'El correo equivalente ' . json_encode($variante) . ' debió rechazarse por UNIQUE');
            $this->assertSame(1062, $errno);
        }
        $this->assertSame(1, $this->contar("SELECT COUNT(*) n FROM usuarios WHERE LOWER(TRIM(email))='" . strtolower($email) . "'"));
    }

    public function testHomonimosConCorreosDistintosSeCreanAmbosYElMismoNombreNoEsClaveUnica(): void
    {
        $r1 = $this->auth()->registrar($this->datosCliente($this->emailUnico('h1'), 'María'), null, Idempotencia::nuevaClave(), 'anonimo:idem-f');
        $r2 = $this->auth()->registrar($this->datosCliente($this->emailUnico('h2'), 'María'), null, Idempotencia::nuevaClave(), 'anonimo:idem-f');
        $this->assertSame(AuthService::STATUS_OK, $r1['status']);
        $this->assertSame(AuthService::STATUS_OK, $r2['status']);
        $this->assertNotSame($r1['id'], $r2['id']);
    }

    public function testTelefonoCompartidoNoBloqueaAltas(): void
    {
        $a = $this->auth()->registrar($this->datosCliente($this->emailUnico('t1')), null, Idempotencia::nuevaClave(), 'anonimo:idem-g');
        $b = $this->auth()->registrar($this->datosCliente($this->emailUnico('t2')), null, Idempotencia::nuevaClave(), 'anonimo:idem-g');
        $this->assertSame(AuthService::STATUS_OK, $a['status']);
        $this->assertSame(AuthService::STATUS_OK, $b['status'], 'Familias pueden compartir teléfono');
    }

    public function testFalloSmtpDejaElAltaCompletaYElReintentoNoVuelveAEnviar(): void
    {
        $email = $this->emailUnico();
        $clave = Idempotencia::nuevaClave();
        $intentosEnvio = 0;
        $falla = function () use (&$intentosEnvio): bool { $intentosEnvio++; return false; };
        $r1 = $this->auth($falla)->registrar($this->datosCliente($email), null, $clave, 'anonimo:idem-h');
        $this->assertSame(AuthService::STATUS_OK, $r1['status'], 'El fallo SMTP no deshace ni duplica el alta');
        $r2 = $this->auth($falla)->registrar($this->datosCliente($email), null, $clave, 'anonimo:idem-h');
        $this->assertTrue($r2['repetida']);
        $this->assertSame(1, $intentosEnvio, 'El reintento recupera el resultado; el reenvío es una acción explícita aparte');
        $this->assertSame(1, $this->contar("SELECT COUNT(*) n FROM usuarios WHERE email='{$email}'"));
    }

    public function testClaveVencidaDejaDeRepresentarUnaOperacionVigente(): void
    {
        $clave = Idempotencia::nuevaClave();
        $r1 = $this->auth()->registrar($this->datosCliente($this->emailUnico('v1')), null, $clave, 'anonimo:idem-i');
        $this->assertSame(AuthService::STATUS_OK, $r1['status']);
        $this->db->query("UPDATE operaciones_idempotentes SET expira_en = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) WHERE actor='anonimo:idem-i'");
        $r2 = $this->auth()->registrar($this->datosCliente($this->emailUnico('v2')), null, $clave, 'anonimo:idem-i');
        $this->assertSame(AuthService::STATUS_OK, $r2['status']);
        $this->assertFalse($r2['repetida']);
        $this->assertSame(1, $this->contar("SELECT COUNT(*) n FROM operaciones_idempotentes WHERE actor='anonimo:idem-i'"));
    }

    public function testElSqlRestringeLaClaveDeOperacionDuplicada(): void
    {
        $sql = "INSERT INTO operaciones_idempotentes(scope,actor,clave_hash,huella,expira_en) VALUES ('prueba','anonimo:idem-sql',?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY))";
        $hash = hash('sha256', 'k'); $huella = hash('sha256', 'h');
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ss', $hash, $huella);
        $this->assertTrue($stmt->execute());
        $this->assertFalse($stmt->execute(), 'La misma operación no puede reservarse dos veces');
        $this->assertSame(1062, $stmt->errno);
        $stmt->close();
        $this->db->query("DELETE FROM operaciones_idempotentes WHERE scope='prueba'");
    }

    public function testNoSeGuardanLaClaveNiLaContrasenaEnClaroEnLaTablaDeIdempotencia(): void
    {
        $clave = Idempotencia::nuevaClave();
        $r = $this->auth()->registrar($this->datosCliente($this->emailUnico()), null, $clave, 'anonimo:idem-j');
        $this->assertSame(AuthService::STATUS_OK, $r['status']);
        $fila = $this->db->query("SELECT * FROM operaciones_idempotentes WHERE actor='anonimo:idem-j'")->fetch_assoc();
        $volcado = json_encode($fila);
        $this->assertStringNotContainsString($clave, $volcado);
        $this->assertStringNotContainsString('ClaveSegura123', $volcado);
        $this->assertNotSame(hash('sha256', 'ClaveSegura123'), $fila['huella'], 'La huella es un HMAC con secreto, no un hash público');
    }

    // ---------------------------------------------------------------- Controlador HTTP

    private function postCrearCuenta(array $post): array
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION['csrf_token'] = 'csrf-idem';
        $_POST = ['csrf_token' => 'csrf-idem'] + $post;
        LoginController::setAuthService($this->auth());
        $status = null;
        ob_start();
        try { LoginController::crear(new Router()); }
        catch (AppTerminationException $e) { $status = $e->getStatusCode(); }
        $salida = (string)ob_get_clean();
        return ['status' => $status, 'http' => http_response_code(), 'salida' => $salida, 'destino' => $GLOBALS['__appsalon_redireccion'] ?? null];
    }

    public function testControladorDobleEnvioRedirigeAlMismoDestinoUnaSolaVez(): void
    {
        $email = $this->emailUnico();
        $post = $this->datosCliente($email) + ['idem_key' => Idempotencia::nuevaClave()];
        $a = $this->postCrearCuenta($post);
        unset($GLOBALS['__appsalon_redireccion']);
        $b = $this->postCrearCuenta($post);
        $this->assertSame(302, $a['status']);
        $this->assertSame('/mensaje', $a['destino']);
        $this->assertSame(302, $b['status']);
        $this->assertSame('/mensaje', $b['destino']);
        $this->assertSame(1, $this->contar("SELECT COUNT(*) n FROM usuarios WHERE email='{$email}'"));
        $this->assertSame(1, $this->correos);
    }

    public function testControladorSinClaveDeOperacionNoCreaNada(): void
    {
        $email = $this->emailUnico();
        $r = $this->postCrearCuenta($this->datosCliente($email));
        $this->assertSame(422, $r['http']);
        $this->assertStringContainsString('venció', $r['salida']);
        $this->assertSame(0, $this->contar("SELECT COUNT(*) n FROM usuarios WHERE email='{$email}'"));
    }

    public function testControladorConCsrfInvalidoNoCreaNiReservaClave(): void
    {
        $email = $this->emailUnico();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION['csrf_token'] = 'csrf-idem';
        $_POST = ['csrf_token' => 'otro'] + $this->datosCliente($email) + ['idem_key' => Idempotencia::nuevaClave()];
        $status = null;
        ob_start();
        try { LoginController::crear(new Router()); } catch (AppTerminationException $e) { $status = $e->getStatusCode(); }
        ob_end_clean();
        $this->assertSame(403, $status);
        $this->assertSame(0, $this->contar("SELECT COUNT(*) n FROM usuarios WHERE email='{$email}'"));
        $this->assertSame(0, $this->contar("SELECT COUNT(*) n FROM operaciones_idempotentes WHERE actor LIKE 'anonimo:%' AND creado_en > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)"));
    }

    // ---------------------------------------------------------------- Profesionales

    private function profService(): ProfesionalService
    {
        return new ProfesionalService(new ProfesionalRepository($this->db), new ServicioRepository($this->db));
    }

    private function datosProf(string $nombre): array
    {
        return ['nombre' => $nombre, 'activo' => '1', 'servicios' => [(string)$this->servicioId]];
    }

    public function testDobleClicEnCrearProfesionalGeneraUnSoloProfesional(): void
    {
        $clave = Idempotencia::nuevaClave();
        $r1 = $this->profService()->crear($this->datosProf('Idem Lucía Torres'), $clave, 'equipo:9001');
        $r2 = $this->profService()->crear($this->datosProf('Idem Lucía Torres'), $clave, 'equipo:9001');
        $this->assertSame(ProfesionalService::STATUS_OK, $r1['status']);
        $this->assertFalse($r1['repetida']);
        $this->assertTrue($r2['repetida']);
        $this->assertSame($r1['id'], $r2['id']);
        $this->assertSame(1, $this->contar("SELECT COUNT(*) n FROM profesionales WHERE nombre='Idem Lucía Torres'"));
        $this->assertSame(1, $this->contar("SELECT COUNT(*) n FROM profesionales_servicios WHERE profesionalId={$r1['id']}"));
    }

    public function testProfesionalMismaClaveContenidoDistintoEsConflictoYOtroActorNoVeElResultado(): void
    {
        $clave = Idempotencia::nuevaClave();
        $r1 = $this->profService()->crear($this->datosProf('Idem Original'), $clave, 'equipo:9001');
        $conflicto = $this->profService()->crear($this->datosProf('Idem Modificado'), $clave, 'equipo:9001');
        $this->assertSame(ProfesionalService::STATUS_CONFLICT_CLAVE, $conflicto['status']);
        $ajeno = $this->profService()->crear($this->datosProf('Idem Original'), $clave, 'equipo:9002');
        $this->assertSame(ProfesionalService::STATUS_OK, $ajeno['status']);
        $this->assertFalse($ajeno['repetida'], 'Otro usuario no recupera el alta del primero');
        $this->assertNotSame($r1['id'], $ajeno['id']);
    }

    public function testRollbackDelProfesionalNoDejaFilaIdempotenteDeExitoYElReintentoConLaMismaClaveFunciona(): void
    {
        $this->db->query("CREATE TRIGGER test_fail_prof_idem BEFORE INSERT ON profesionales_servicios FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fallo simulado'");
        $clave = Idempotencia::nuevaClave();
        $fallo = $this->profService()->crear($this->datosProf('Idem Reintento'), $clave, 'equipo:9003');
        $this->assertSame(ProfesionalService::STATUS_ERROR, $fallo['status']);
        $this->assertSame(0, $this->contar("SELECT COUNT(*) n FROM profesionales WHERE nombre='Idem Reintento'"), 'Sin profesional parcial');
        $this->assertSame(0, $this->contar("SELECT COUNT(*) n FROM operaciones_idempotentes WHERE actor='equipo:9003'"), 'Sin fila de éxito de un alta revertida');

        $this->db->query("DROP TRIGGER test_fail_prof_idem");
        $ok = $this->profService()->crear($this->datosProf('Idem Reintento'), $clave, 'equipo:9003');
        $this->assertSame(ProfesionalService::STATUS_OK, $ok['status']);
        $this->assertFalse($ok['repetida']);
        $this->assertSame(1, $this->contar("SELECT COUNT(*) n FROM profesionales WHERE nombre='Idem Reintento'"));
    }

    public function testProfesionalesHomonimosLegadosSinCuentaSePermitenConClavesDistintas(): void
    {
        $a = $this->profService()->crear($this->datosProf('Idem Homónimo'), Idempotencia::nuevaClave(), 'equipo:9004');
        $b = $this->profService()->crear($this->datosProf('Idem Homónimo'), Idempotencia::nuevaClave(), 'equipo:9004');
        $this->assertSame(ProfesionalService::STATUS_OK, $a['status']);
        $this->assertSame(ProfesionalService::STATUS_OK, $b['status']);
        $this->assertNotSame($a['id'], $b['id']);
        $this->assertSame(2, $this->contar("SELECT COUNT(*) n FROM profesionales WHERE nombre='Idem Homónimo' AND usuarioId IS NULL"));
    }

    // ---------------------------------------------------------------- Procesos reales concurrentes

    /** @return array{0:resource,1:array} */
    private function lanzar(string $flujo, string $clave, string $dato, int $demoraMs = 0, string $actor = 'anonimo:fixture', int $servicio = 0): array
    {
        $pipes = [];
        $proceso = proc_open([PHP_BINARY, __DIR__ . '/../fixtures/concurrent_alta_worker.php', $flujo, $clave, $dato, (string)$demoraMs, $actor, (string)$servicio],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proceso);
        stream_set_timeout($pipes[1], 15);
        $linea = fgets($pipes[1]);
        if ($linea !== "READY\n") {
            $this->fail('Worker no quedó listo antes de la barrera. Salida: ' . json_encode($linea));
        }
        return [$proceso, $pipes];
    }

    private function liberar(array $trabajadores): array
    {
        foreach ($trabajadores as [, $pipes]) { fwrite($pipes[0], "GO\n"); fflush($pipes[0]); fclose($pipes[0]); }
        $resultados = [];
        foreach ($trabajadores as [$proceso, $pipes]) {
            $linea = fgets($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $codigo = proc_close($proceso);
            $this->assertSame(0, $codigo, 'Worker terminó con error: ' . $err);
            $json = json_decode((string)$linea, true);
            $this->assertIsArray($json, 'Salida de worker: ' . $linea . $err);
            $resultados[] = $json;
        }
        return $resultados;
    }

    public function testDosProcesosConcurrentesConLaMismaClaveCreanUnSoloCliente(): void
    {
        $email = $this->emailUnico('conc');
        $clave = Idempotencia::nuevaClave();
        // El que tome la reserva la retiene 600 ms: el otro debe esperar en el índice único y recuperar el resultado.
        $res = $this->liberar([$this->lanzar('cliente', $clave, $email, 600, 'anonimo:idem-k'), $this->lanzar('cliente', $clave, $email, 600, 'anonimo:idem-k')]);
        $this->assertSame([AuthService::STATUS_OK, AuthService::STATUS_OK], array_column($res, 'status'));
        $this->assertSame($res[0]['id'], $res[1]['id'], 'Ambas solicitudes devuelven la misma identidad');
        $this->assertSame(1, $res[0]['correos'] + $res[1]['correos'], 'Un único correo entre los dos procesos');
        $this->assertSame(1, count(array_filter(array_column($res, 'repetida'), fn($r) => $r === false)), 'Exactamente una ejecutó el alta');
        $this->assertSame(1, $this->contar("SELECT COUNT(*) n FROM usuarios WHERE email='{$email}'"));
        $this->assertSame(1, $this->contar("SELECT COUNT(*) n FROM clientes c JOIN usuarios u ON u.id=c.usuario_id WHERE u.email='{$email}'"));
        $this->assertSame(1, $this->contar("SELECT COUNT(*) n FROM operaciones_idempotentes WHERE actor='anonimo:idem-k'"));
    }

    public function testDosProcesosConClavesDistintasParaElMismoCorreoDejanUnaSolaIdentidad(): void
    {
        $email = $this->emailUnico('carrera');
        $res = $this->liberar([
            $this->lanzar('cliente', Idempotencia::nuevaClave(), $email, 400, 'anonimo:idem-l1'),
            $this->lanzar('cliente', Idempotencia::nuevaClave(), $email, 400, 'anonimo:idem-l2'),
        ]);
        // Contrato deliberadamente cambiado (DEF-13): ya no hay «conflicto» público. Ambas respuestas son OK; una crea la
        // identidad y la perdedora de la carrera (UNIQUE) repite su transacción y trata el correo como cuenta sin confirmar.
        $this->assertSame([AuthService::STATUS_OK, AuthService::STATUS_OK], array_column($res, 'status'), json_encode($res));
        $this->assertSame(1, count(array_filter(array_column($res, 'id'), fn($id) => $id !== null)), 'Solo una solicitud crea la identidad: ' . json_encode($res));
        $this->assertSame(1, $this->contar("SELECT COUNT(*) n FROM usuarios WHERE email='{$email}'"));
        $this->assertSame(1, $this->contar("SELECT COUNT(*) n FROM clientes c JOIN usuarios u ON u.id=c.usuario_id WHERE u.email='{$email}'"));
        $this->assertSame(2, $res[0]['correos'] + $res[1]['correos'], 'Una confirmación del alta y otra del token reemitido; ninguna cuenta extra');
    }

    public function testDosProcesosConcurrentesConLaMismaClaveCreanUnSoloProfesional(): void
    {
        $nombre = 'Idem Concurrente ' . bin2hex(random_bytes(3));
        $clave = Idempotencia::nuevaClave();
        $res = $this->liberar([
            $this->lanzar('profesional', $clave, $nombre, 600, 'equipo:9100', $this->servicioId),
            $this->lanzar('profesional', $clave, $nombre, 600, 'equipo:9100', $this->servicioId),
        ]);
        $this->assertSame([ProfesionalService::STATUS_OK, ProfesionalService::STATUS_OK], array_column($res, 'status'));
        $this->assertSame($res[0]['id'], $res[1]['id']);
        $this->assertSame(1, $this->contar("SELECT COUNT(*) n FROM profesionales WHERE nombre='{$nombre}'"));
        $this->assertSame(1, $this->contar("SELECT COUNT(*) n FROM profesionales_servicios WHERE profesionalId={$res[0]['id']}"));
    }

    public function testTimeoutTrasElCommitYReintentoEnOtroProcesoRecuperaElResultadoOriginal(): void
    {
        $email = $this->emailUnico('perdida');
        $clave = Idempotencia::nuevaClave();
        // Proceso 1: el alta se confirma pero su respuesta "se pierde" (la prueba la descarta).
        $primero = $this->liberar([$this->lanzar('cliente', $clave, $email, 0, 'anonimo:idem-m')]);
        $this->assertFalse($primero[0]['repetida']);
        // Proceso 2 (reinicio): reintenta con la MISMA clave.
        $segundo = $this->liberar([$this->lanzar('cliente', $clave, $email, 0, 'anonimo:idem-m')]);
        $this->assertTrue($segundo[0]['repetida']);
        $this->assertSame($primero[0]['id'], $segundo[0]['id']);
        $this->assertSame(0, $segundo[0]['correos'], 'El reintento no genera otro correo');
        $this->assertSame(1, $this->contar("SELECT COUNT(*) n FROM usuarios WHERE email='{$email}'"));
    }
}

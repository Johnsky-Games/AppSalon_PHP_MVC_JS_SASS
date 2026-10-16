<?php

namespace Tests\Integration;

use Classes\Email;
use Model\ActiveRecord;
use Model\Usuario;
use PHPUnit\Framework\TestCase;
use Repositories\CorreoSalidaRepository;
use Repositories\UsuarioRepository;
use Services\AuthService;
use Services\CorreoSalidaService;

/**
 * Valor por defecto de los correos de cuenta (QA R2B-02/08/09): NO se envían dentro de la petición HTTP, solo se
 * encolan y los entrega el worker. Así el tiempo de respuesta no delata si una cuenta existe. Un transporte en memoria
 * cuenta los envíos reales: antes del worker deben ser cero; después, exactamente los esperados.
 */
final class ColaSinEnvioEnLineaIntegrationTest extends TestCase
{
    private const SUF = '@sinlinea.test';
    private static string $base;
    private \mysqli $db;
    private ?\mysqli $previa = null;
    private array $envioInmediatoPrevio;
    /** @var list<array{0:string,1:string}> */
    private array $enviados = [];

    /** El worker procesa TODA la cola: su base debe ser propia para no enviar residuos de otras clases. */
    public static function setUpBeforeClass(): void
    {
        self::$base = 'appsalon_sinlinea_' . bin2hex(random_bytes(6)) . '_test';
        $db = self::conectar('');
        try {
            if (!$db->query('CREATE DATABASE `' . self::$base . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')) {
                throw new \RuntimeException($db->error);
            }
            $db->select_db(self::$base);
            validar_base_datos_prueba($db);
            if (!$db->multi_query((string)file_get_contents(__DIR__ . '/../../database/schema_base.sql'))) {
                throw new \RuntimeException($db->error);
            }
            do { if ($r = $db->store_result()) { $r->free(); } } while ($db->more_results() && $db->next_result());
            if ($db->errno) { throw new \RuntimeException($db->error); }
            $p = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', __DIR__ . '/../../database/migrador.php', 'up'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), ['DB_NAME' => self::$base]));
            if (!is_resource($p)) { throw new \RuntimeException('No se pudo iniciar el migrador de la cola aislada.'); }
            $salida = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            if (proc_close($p) !== 0) { throw new \RuntimeException($salida); }
        } catch (\Throwable $e) {
            $db->query('DROP DATABASE IF EXISTS `' . self::$base . '`');
            throw $e;
        } finally { $db->close(); }
    }

    public static function tearDownAfterClass(): void
    {
        $db = self::conectar('');
        try { $db->query('DROP DATABASE IF EXISTS `' . self::$base . '`'); }
        finally { $db->close(); }
    }

    private static function conectar(string $base): \mysqli
    {
        $db = new \mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', $base, (int)(getenv('DB_PORT') ?: 3306));
        $db->set_charset('utf8mb4');
        return $db;
    }

    protected function setUp(): void
    {
        $this->envioInmediatoPrevio = [$_ENV['CORREOS_ENVIO_INMEDIATO'] ?? null, getenv('CORREOS_ENVIO_INMEDIATO')];
        unset($_ENV['CORREOS_ENVIO_INMEDIATO']); putenv('CORREOS_ENVIO_INMEDIATO');
        $this->previa = ActiveRecord::getDB();
        $this->db = self::conectar(self::$base);
        ActiveRecord::setDB($this->db);
        validar_base_datos_prueba($this->db);
        $this->limpiar();
        $this->enviados = [];
        Email::limpiarEmailsEnviados();
        Email::setTransport(function ($mail, string $proposito, array $datos): bool {
            $this->enviados[] = [$proposito, (string)$datos['destinatario']];
            return true;
        });
    }

    protected function tearDown(): void
    {
        Email::setTransport(null);
        try { $this->limpiar(); }
        finally {
            ActiveRecord::setDB($this->previa);
            $this->db->close();
            [$env, $proceso] = $this->envioInmediatoPrevio;
            if ($env === null) { unset($_ENV['CORREOS_ENVIO_INMEDIATO']); } else { $_ENV['CORREOS_ENVIO_INMEDIATO'] = $env; }
            putenv($proceso === false ? 'CORREOS_ENVIO_INMEDIATO' : 'CORREOS_ENVIO_INMEDIATO=' . $proceso);
        }
    }

    private function limpiar(): void
    {
        $this->db->query("DELETE FROM correos_salida WHERE destinatario_email LIKE '%" . self::SUF . "'");
        $this->db->query("DELETE FROM usuarios WHERE email LIKE '%" . self::SUF . "'");
        $this->db->query("DELETE FROM intentos_login WHERE tipo LIKE '%recovery%' OR tipo LIKE '%reconfirm%' OR tipo LIKE '%register%'");
    }

    private function usuario(string $alias, bool $confirmado): int
    {
        $email = $alias . self::SUF;
        $hash = password_hash('Clave-Original-123', PASSWORD_BCRYPT);
        $c = $confirmado ? 1 : 0;
        $this->db->query("INSERT INTO usuarios(nombre,apellido,email,password,telefono,admin,confirmado) VALUES ('Ana','Prueba','{$email}','{$hash}','0991112233',0,{$c})");
        $id = (int)$this->db->insert_id;
        $this->db->query("INSERT IGNORE INTO clientes(usuario_id,estado,origen) VALUES ({$id},'activo','registro_publico')");
        return $id;
    }

    private function eventos(string $tipo): array
    {
        $r = $this->db->query("SELECT id, estado, usuario_id, carga_cifrada IS NOT NULL AS con_carga FROM correos_salida WHERE tipo='{$tipo}' AND destinatario_email LIKE '%" . self::SUF . "' ORDER BY id");
        return $r->fetch_all(MYSQLI_ASSOC);
    }

    private function worker(): array
    {
        return (new CorreoSalidaService(new CorreoSalidaRepository($this->db)))->procesarLote(50);
    }

    public function testElValorPorDefectoNoActivaElEnvioEnLinea(): void
    {
        $this->assertFalse(CorreoSalidaService::envioInmediatoHabilitado());
        $_ENV['CORREOS_ENVIO_INMEDIATO'] = '1';
        try { $this->assertTrue(CorreoSalidaService::envioInmediatoHabilitado()); } finally { unset($_ENV['CORREOS_ENVIO_INMEDIATO']); }
    }

    public function testElRegistroNuevoSoloEncolaYElWorkerEntregaUnaVez(): void
    {
        $svc = new AuthService();
        $r = $svc->registrar(['nombre' => 'Luz', 'apellido' => 'Nueva', 'telefono' => '0991234567', 'email' => 'nuevo' . self::SUF, 'password' => 'Clave-Segura-12345', 'password2' => 'Clave-Segura-12345'], '203.0.113.9');
        $this->assertTrue($r['resultado'], json_encode($r['alertas']));
        $this->assertSame([], $this->enviados, 'la petición no debe enviar nada: solo encolar');
        $ev = $this->eventos('confirmacion_cuenta');
        $this->assertCount(1, $ev);
        $this->assertSame('pendiente', $ev[0]['estado']);
        $this->assertSame(1, (int)$this->worker()['enviados']);
        $this->assertSame([['confirmacion', 'nuevo' . self::SUF]], $this->enviados);
        $this->assertSame(0, (int)$this->worker()['intentados'], 'el segundo pase del worker no reenvía');
    }

    public function testElAvisoDeCuentaExistenteTampocoSeEnviaEnLinea(): void
    {
        $this->usuario('existente', true);
        $r = (new AuthService())->registrar(['nombre' => 'Otro', 'apellido' => 'Intento', 'telefono' => '0991234567', 'email' => 'existente' . self::SUF, 'password' => 'Clave-Segura-12345', 'password2' => 'Clave-Segura-12345'], '203.0.113.10');
        $this->assertTrue($r['resultado']);
        $this->assertSame([], $this->enviados);
        $this->assertCount(1, $this->eventos('aviso_cuenta_existente'));
        $this->worker();
        $this->assertSame([['cuenta_existente', 'existente' . self::SUF]], $this->enviados);
    }

    public function testElReenvioDeConfirmacionSoloEncola(): void
    {
        $this->usuario('pendiente', false);
        $r = (new AuthService())->reenviarConfirmacion(['email' => 'pendiente' . self::SUF], '203.0.113.11');
        $this->assertTrue($r['resultado']);
        $this->assertSame([], $this->enviados);
        $this->assertCount(1, $this->eventos('confirmacion_cuenta'));
        $this->worker();
        $this->assertCount(1, $this->enviados);
        $this->assertSame('confirmacion', $this->enviados[0][0]);
    }

    public function testLaRecuperacionVaPorLaColaYUnSegundoPedidoInvalidaElPrimerEnlace(): void
    {
        $id = $this->usuario('recupera', true);
        $svc = new AuthService();
        $this->assertTrue($svc->solicitarRecuperacion(['email' => 'recupera' . self::SUF], '203.0.113.12')['resultado']);
        $this->assertSame([], $this->enviados, 'la recuperación tampoco se envía en la petición');
        $primero = $this->eventos('recuperacion_cuenta');
        $this->assertCount(1, $primero);
        $this->assertSame($id, (int)$primero[0]['usuario_id']);
        $this->assertTrue($svc->solicitarRecuperacion(['email' => 'recupera' . self::SUF], '203.0.113.13')['resultado']);
        $todos = $this->eventos('recuperacion_cuenta');
        $this->assertCount(2, $todos);
        $this->assertSame('cancelado', $todos[0]['estado'], 'el evento del enlace anterior se cancela en la misma transacción');
        $this->assertSame(0, (int)$todos[0]['con_carga'], 'y su carga cifrada se borra');
        $this->assertSame('pendiente', $todos[1]['estado']);
        $this->worker();
        $this->assertSame([['recuperacion', 'recupera' . self::SUF]], $this->enviados, 'solo sale el enlace vigente');
    }

    public function testUnaCuentaInexistenteONoConfirmadaNoGeneraEventoDeRecuperacion(): void
    {
        $this->usuario('sinconfirmar', false);
        $svc = new AuthService();
        $svc->solicitarRecuperacion(['email' => 'nadie' . self::SUF], '203.0.113.14');
        $svc->solicitarRecuperacion(['email' => 'sinconfirmar' . self::SUF], '203.0.113.15');
        $this->assertSame([], $this->eventos('recuperacion_cuenta'));
    }

    public function testUnEnlaceDeRecuperacionYaUsadoNoSeEnviaJamas(): void
    {
        $this->usuario('usado', true);
        $svc = new AuthService();
        $svc->solicitarRecuperacion(['email' => 'usado' . self::SUF], '203.0.113.16');
        // Simula que el token ya se consumió/expiró antes de que el worker lo despache.
        $this->db->query("UPDATE usuarios SET token_hash=NULL, token_tipo=NULL, token_expira=NULL WHERE email='usado" . self::SUF . "'");
        $r = $this->worker();
        $this->assertSame(1, $r['cancelados']);
        $this->assertSame([], $this->enviados);
        $this->assertSame('cancelado', $this->eventos('recuperacion_cuenta')[0]['estado']);
    }
}

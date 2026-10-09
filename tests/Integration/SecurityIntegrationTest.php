<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Model\ActiveRecord;
use Model\Usuario;
use Model\Servicio;
use Model\Cita;
use Model\CitaServicio;
use Classes\RateLimiter;
use mysqli;

class SecurityIntegrationTest extends TestCase
{
    private static ?mysqli $db = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('DB_HOST') ?: 'appsalon-test-db';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: 'root';
        $name = getenv('DB_NAME') ?: 'appsalon_test';
        $port = (int)(getenv('DB_PORT') ?: 3306);

        self::$db = new mysqli($host, $user, $pass, $name, $port);
        self::$db->set_charset('utf8mb4');
        ActiveRecord::setDB(self::$db);
    }

    protected function setUp(): void
    {
        // Limpiar datos entre pruebas
        self::$db->query("DELETE FROM citasservicios");
        self::$db->query("DELETE FROM citas");
        self::$db->query("DELETE FROM usuarios");
        self::$db->query("DELETE FROM servicios");
        self::$db->query("DELETE FROM intentos_login");

        $_SESSION = [];
        $_POST = [];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = null;
    }

    public function testEntradasMaliciosasSeProcesanComoDatosEnConsultasPreparadas(): void
    {
        // Insertar un usuario legítimo
        $usuario = new Usuario();
        $usuario->sincronizarRegistro([
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'email' => 'ana@correo.com',
            'password' => 'password123',
            'telefono' => '1122334455'
        ]);
        $usuario->hashPassword();
        $usuario->guardar();

        // Intento de inyección SQL en búsqueda por email
        $payloadSQLi = "ana@correo.com' OR '1'='1";
        $encontrado = Usuario::where('email', $payloadSQLi);

        $this->assertNull($encontrado, 'La consulta preparada debe buscar el literal y no ejecutar el OR 1=1');

        $legitimo = Usuario::where('email', 'ana@correo.com');
        $this->assertNotNull($legitimo);
        $this->assertSame('Ana', $legitimo->nombre);
    }

    public function testRegistroEnBaseDeDatosForzaPrivilegiosEnCero(): void
    {
        $usuario = new Usuario();
        $usuario->sincronizarRegistro([
            'nombre' => 'Hacker',
            'apellido' => 'Test',
            'email' => 'hacker@correo.com',
            'password' => 'secreto123',
            'telefono' => '9988776655',
            'admin' => '1',
            'confirmado' => '1',
            'id' => '1234'
        ]);
        $usuario->hashPassword();
        $resultado = $usuario->guardar();

        $this->assertTrue((bool)$resultado['resultado']);

        // Consultar directamente la base de datos
        $guardado = Usuario::find($resultado['id']);
        $this->assertSame('0', (string)$guardado->admin, 'Admin en BD debe ser 0');
        $this->assertSame('0', (string)$guardado->confirmado, 'Confirmado en BD debe ser 0');
    }

    public function testTokenExpiradoOReutilizadoSeRechaza(): void
    {
        $usuario = new Usuario();
        $usuario->sincronizarRegistro([
            'nombre' => 'Carlos',
            'apellido' => 'Perez',
            'email' => 'carlos@correo.com',
            'password' => 'clave123',
            'telefono' => '5544332211'
        ]);
        $tokenRaw = $usuario->generarTokenSeguro('confirmacion', 24);
        $usuario->guardar();

        // 1. Token válido se encuentra
        $encontrado = Usuario::buscarPorTokenSeguro($tokenRaw, 'confirmacion');
        $this->assertNotNull($encontrado);

        // 2. Consumo atómico del token
        $consumido = $encontrado->consumirToken();
        $this->assertTrue($consumido);
        $this->assertSame('1', (string)$encontrado->confirmado);

        // 3. Intento de reutilización debe fallar (token ya consumido)
        $reintento = Usuario::buscarPorTokenSeguro($tokenRaw, 'confirmacion');
        $this->assertNull($reintento, 'Un token ya consumido no debe ser encontrado');

        // 4. Token expirado en el pasado
        $usuarioExpirado = new Usuario();
        $usuarioExpirado->sincronizarRegistro([
            'nombre' => 'Expirado',
            'apellido' => 'Test',
            'email' => 'expirado@correo.com',
            'password' => 'clave123',
            'telefono' => '5544332212'
        ]);
        $tokenExp = $usuarioExpirado->generarTokenSeguro('confirmacion', -1); // Expiró hace 1 hora
        $usuarioExpirado->guardar();

        $busquedaExpirado = Usuario::buscarPorTokenSeguro($tokenExp, 'confirmacion');
        $this->assertNull($busquedaExpirado, 'Un token con fecha de expiración pasada debe ser rechazado');
    }

    public function testFallaAlGuardarServiciosRevierteCitaCompletaEnTransaccion(): void
    {
        // 1. Crear usuario cliente
        $cliente = new Usuario();
        $cliente->sincronizarRegistro([
            'nombre' => 'Cliente',
            'apellido' => 'Test',
            'email' => 'cliente@correo.com',
            'password' => 'password',
            'telefono' => '1234567890'
        ]);
        $resCliente = $cliente->guardar();
        $clienteId = $resCliente['id'];

        // 2. Iniciar transacción
        self::$db->begin_transaction();

        $citaId = null;
        try {
            // A. Insertar cita base exitosamente
            $cita = new Cita([
                'fecha' => '2026-10-15',
                'hora' => '11:00',
                'usuarioId' => $clienteId
            ]);
            $resCita = $cita->guardar();
            $citaId = $resCita['id'];
            $this->assertNotNull($citaId);

            // B. Provocar fallo forzado en la inserción de servicios (después de insertar la cita)
            throw new \Exception("Fallo simulado en persistencia de citasservicios");

            // Si llegara aquí, se haría commit
            self::$db->commit();
        } catch (\Throwable $e) {
            // C. Ejecutar rollback tras la falla
            self::$db->rollback();
        }

        // D. Demostrar reversión: verificar que la cita NO existe en la base de datos
        $citaEnBD = Cita::find($citaId);
        $this->assertNull($citaEnBD, 'La transacción debió revertir la cita base por completo tras el fallo');

        $serviciosEnBD = self::$db->query("SELECT * FROM citasservicios WHERE citaId = {$citaId}");
        $this->assertSame(0, $serviciosEnBD->num_rows, 'No deben quedar registros huérfanos en citasservicios');
    }

    public function testRateLimiterRegistraYBloqueaTrasMaximosIntentos(): void
    {
        $ip = '192.168.1.100';
        $email = 'victima@correo.com';

        $this->assertFalse(RateLimiter::estaBloqueado(self::$db, RateLimiter::TIPO_EMAIL_LOGIN, $email));

        // Registrar 5 intentos fallidos
        for ($i = 1; $i <= 5; $i++) {
            RateLimiter::registrarIntentoFallido(self::$db, RateLimiter::TIPO_EMAIL_LOGIN, $email, 5);
        }

        // Debe estar bloqueado
        $this->assertTrue(RateLimiter::estaBloqueado(self::$db, RateLimiter::TIPO_EMAIL_LOGIN, $email));

        // Limpiar intentos (ej. tras login correcto)
        RateLimiter::limpiarIntentos(self::$db, RateLimiter::TIPO_EMAIL_LOGIN, $email);
        $this->assertFalse(RateLimiter::estaBloqueado(self::$db, RateLimiter::TIPO_EMAIL_LOGIN, $email));
    }
}

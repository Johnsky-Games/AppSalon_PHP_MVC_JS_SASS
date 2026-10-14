<?php

namespace Tests\Unit;

use Model\Usuario;
use PHPUnit\Framework\TestCase;
use Repositories\PersistenceException;
use Repositories\UsuarioRepository;
use Services\AuthService;

class InMemoryUsuarioRepositoryStub extends UsuarioRepository
{
    /** @var array<int, Usuario> */
    public array $usuarios = [];
    public bool $forzarFalloSql = false;
    public bool $forzarFalloEmisionToken = false;
    private int $autoIncrement = 1;

    public function __construct()
    {
        parent::__construct(null);
    }

    private function checkFallo(): void
    {
        if ($this->forzarFalloSql) {
            throw new PersistenceException("SQLSTATE[HY000]: Error interno simulado en tabla usuarios");
        }
    }

    public function findById(int $id): ?Usuario
    {
        $this->checkFallo();
        return isset($this->usuarios[$id]) ? clone $this->usuarios[$id] : null;
    }

    public function findByEmail(string $email): ?Usuario
    {
        $this->checkFallo();
        $emailTrimmed = trim($email);
        foreach ($this->usuarios as $u) {
            if ($u->email === $emailTrimmed) {
                return clone $u;
            }
        }
        return null;
    }

    public function existsByEmail(string $email): bool
    {
        $this->checkFallo();
        return $this->findByEmail($email) !== null;
    }

    public function create(Usuario $usuario): int
    {
        $this->checkFallo();
        $id = $this->autoIncrement++;
        $usuario->id = (string)$id;
        $this->usuarios[$id] = clone $usuario;
        return $id;
    }

    public function createCliente(Usuario $usuario, string $origen = 'registro_publico'): int
    {
        return $this->create($usuario);
    }

    /** Sin base de datos: el doble ejecuta el trabajo directamente (el registro público ahora corre dentro de una transacción). */
    public function enTransaccion(callable $trabajo): mixed
    {
        return $trabajo();
    }

    public function findByValidToken(string $tokenRaw, string $tipo): ?Usuario
    {
        $this->checkFallo();
        $hash = hash('sha256', trim($tokenRaw));
        foreach ($this->usuarios as $u) {
            if (
                $u->token_hash === $hash &&
                $u->token_tipo === $tipo &&
                $u->token_expira !== null &&
                strtotime($u->token_expira . ' UTC') >= time()
            ) {
                return clone $u;
            }
        }
        return null;
    }

    /** Doble en memoria de la activación atómica: consume el token y reemplaza la contraseña provisional. */
    public function activateAccountByToken(string $tokenRaw, string $nuevoPasswordHash): ?array
    {
        $this->checkFallo();
        $hash = hash('sha256', trim($tokenRaw));
        foreach ($this->usuarios as $id => $u) {
            if (
                $u->token_hash === $hash &&
                $u->token_tipo === 'confirmacion' &&
                (string)$u->confirmado === '0' &&
                $u->token_expira !== null &&
                strtotime($u->token_expira . ' UTC') >= time()
            ) {
                $u->password = $nuevoPasswordHash;
                $u->confirmado = '1';
                $u->token = null;
                $u->token_hash = null;
                $u->token_tipo = null;
                $u->token_expira = null;
                $this->usuarios[$id] = $u;
                return ['id' => $id, 'email' => (string)$u->email];
            }
        }
        return null;
    }

    public function resetPasswordByToken(string $tokenRaw, string $nuevoPasswordHash): bool
    {
        $this->checkFallo();
        $hash = hash('sha256', trim($tokenRaw));
        foreach ($this->usuarios as $id => $u) {
            if (
                $u->token_hash === $hash &&
                $u->token_tipo === 'recuperacion' &&
                $u->token_expira !== null &&
                strtotime($u->token_expira . ' UTC') >= time()
            ) {
                $u->password = $nuevoPasswordHash;
                $u->token = null;
                $u->token_hash = null;
                $u->token_tipo = null;
                $u->token_expira = null;
                $this->usuarios[$id] = $u;
                return true;
            }
        }
        return false;
    }

    public function issueRecoveryToken(Usuario $usuario, int $horasExpiracion = 2): ?string
    {
        $this->checkFallo();
        if ($this->forzarFalloEmisionToken) {
            return null;
        }
        $id = (int)$usuario->id;
        if (!isset($this->usuarios[$id]) || (string)$this->usuarios[$id]->confirmado !== '1') {
            return null;
        }
        $tokenPlano = $usuario->generarTokenSeguro('recuperacion', $horasExpiracion);
        $this->usuarios[$id]->token = null;
        $this->usuarios[$id]->token_hash = $usuario->token_hash;
        $this->usuarios[$id]->token_tipo = $usuario->token_tipo;
        $this->usuarios[$id]->token_expira = $usuario->token_expira;
        return $tokenPlano;
    }

    public function issueConfirmationToken(Usuario $usuario, int $horasExpiracion = 24): ?string
    {
        $this->checkFallo();
        if ($this->forzarFalloEmisionToken) {
            return null;
        }
        $id = (int)$usuario->id;
        if (!isset($this->usuarios[$id]) || (string)$this->usuarios[$id]->confirmado !== '0') {
            return null;
        }
        $tokenPlano = $usuario->generarTokenSeguro('confirmacion', $horasExpiracion);
        $this->usuarios[$id]->token = null;
        $this->usuarios[$id]->token_hash = $usuario->token_hash;
        $this->usuarios[$id]->token_tipo = $usuario->token_tipo;
        $this->usuarios[$id]->token_expira = $usuario->token_expira;
        return $tokenPlano;
    }
}

class AuthServiceTest extends TestCase
{
    public function testRegistrarIgnoraSuperglobalesYBloqueaMassAssignmentEnviandoCorreoTrasPersistir(): void
    {
        $repo = new InMemoryUsuarioRepositoryStub();
        $correosEnviados = [];
        $service = new AuthService($repo, function (string $tipo, string $email, string $nombre, string $tokenPlano) use (&$correosEnviados): bool {
            $correosEnviados[] = compact('tipo', 'email', 'nombre', 'tokenPlano');
            return true;
        });

        // Contaminar superglobales para demostrar que AuthService no las lee
        $_POST = [
            'nombre' => 'NombrePostIgnorado',
            'email' => 'post_ignorado@correo.com',
            'admin' => '1'
        ];

        $resultado = $service->registrar([
            'id' => '777',
            'nombre' => 'Laura',
            'apellido' => 'Mendez',
            'telefono' => '5544332211',
            'email' => 'laura@correo.com',
            'password' => 'secreto123',
            'admin' => '1',
            'confirmado' => '1',
            'token' => 'token_falso',
            'token_hash' => 'hash_falso'
        ]);

        $this->assertSame(AuthService::STATUS_OK, $resultado['status']);
        $this->assertTrue($resultado['resultado']);
        $this->assertSame(1, $resultado['id']);

        $guardado = $repo->findById(1);
        $this->assertNotNull($guardado);
        $this->assertSame('Laura', $guardado->nombre);
        $this->assertSame('laura@correo.com', $guardado->email);
        $this->assertSame('0', $guardado->admin, 'El campo admin debe forzarse a 0');
        $this->assertSame('0', $guardado->confirmado, 'El campo confirmado debe forzarse a 0');
        $this->assertTrue(password_verify('secreto123', $guardado->password), 'La contraseña debe almacenarse con hash bcrypt');
        $this->assertNull($guardado->token, 'El token en texto plano nunca debe almacenarse');
        $this->assertSame('confirmacion', $guardado->token_tipo);

        // El correo se envió exactamente una vez con el token plano cuyo SHA-256 coincide con token_hash
        $this->assertCount(1, $correosEnviados);
        $this->assertSame('confirmacion', $correosEnviados[0]['tipo']);
        $this->assertSame('laura@correo.com', $correosEnviados[0]['email']);
        $this->assertSame(hash('sha256', $correosEnviados[0]['tokenPlano']), $guardado->token_hash);
    }

    public function testRegistrarNoEnviaCorreoNiFiltraSqlCuandoFallaPersistencia(): void
    {
        $repo = new InMemoryUsuarioRepositoryStub();
        $repo->forzarFalloSql = true;

        $correosEnviados = 0;
        $service = new AuthService($repo, function () use (&$correosEnviados): bool {
            $correosEnviados++;
            return true;
        });

        $resultado = $service->registrar([
            'nombre' => 'Carlos',
            'apellido' => 'Ruiz',
            'telefono' => '1122334455',
            'email' => 'carlos@correo.com',
            'password' => 'secreto123'
        ]);

        $this->assertSame(AuthService::STATUS_ERROR, $resultado['status']);
        $this->assertFalse($resultado['resultado']);
        $this->assertSame(0, $correosEnviados, 'Jamás debe enviarse correo de confirmación si falla la persistencia');
        $this->assertStringNotContainsString('SQLSTATE', json_encode($resultado['alertas']));
    }

    public function testLaActivacionReintentaInterbloqueosTransitoriosYLaPerdedoraVeEnlaceNoValido(): void
    {
        // Doble que simula N interbloqueos de InnoDB (1213) antes de ver el token ya consumido por la ganadora.
        $repo = new class extends InMemoryUsuarioRepositoryStub {
            public int $llamadas = 0;
            public int $interbloqueos = 0;
            public string $mensaje = 'Deadlock found when trying to get lock; try restarting transaction';
            public function activateAccountByToken(string $tokenRaw, string $nuevoPasswordHash): ?array
            {
                $this->llamadas++;
                if ($this->llamadas <= $this->interbloqueos) { throw new \Repositories\PersistenceException($this->mensaje); }
                return null;   // la ganadora ya consumió el token
            }
        };
        $service = new AuthService($repo, static fn() => true);
        $datos = ['token' => 'abc', 'password' => 'ClaveDefinitiva9', 'password2' => 'ClaveDefinitiva9'];

        $repo->interbloqueos = 2;
        $r = $service->activarCuenta($datos);
        $this->assertSame(AuthService::STATUS_INVALID, $r['status'], 'tras reintentar, la perdedora ve «enlace no válido o ya utilizado», no un error técnico');
        $this->assertSame(3, $repo->llamadas);

        $repo->llamadas = 0; $repo->interbloqueos = 99;
        $this->assertSame(AuthService::STATUS_ERROR, $service->activarCuenta($datos)['status'], 'un interbloqueo persistente sí es error técnico');
        $this->assertSame(5, $repo->llamadas, 'máximo 5 intentos');

        $repo->llamadas = 0; $repo->interbloqueos = 99; $repo->mensaje = 'Duplicate entry for key PRIMARY';
        $this->assertSame(AuthService::STATUS_ERROR, $service->activarCuenta($datos)['status']);
        $this->assertSame(1, $repo->llamadas, 'un error que no es de concurrencia no se reintenta');
    }

    public function testConfirmarCuentaYRestablecerPasswordDistinguenEstadosYErroresSql(): void
    {
        $repo = new InMemoryUsuarioRepositoryStub();
        $tokenConfirmacion = '';
        $service = new AuthService($repo, function (string $tipo, string $email, string $nombre, string $tokenPlano) use (&$tokenConfirmacion): bool {
            $tokenConfirmacion = $tokenPlano;
            return true;
        });

        $service->registrar([
            'nombre' => 'Sofia',
            'apellido' => 'Castro',
            'telefono' => '9988776655',
            'email' => 'sofia@correo.com',
            'password' => 'inicial123'
        ]);

        // 1. Token vacío o inexistente -> STATUS_INVALID (la activación exige elegir contraseña: reemplaza la provisional)
        $activar = static fn(string $t) => $service->activarCuenta(['token' => $t, 'password' => 'ClaveDefinitiva9', 'password2' => 'ClaveDefinitiva9']);
        $invalido = $activar('token_inexistente');
        $this->assertSame(AuthService::STATUS_INVALID, $invalido['status']);

        // 2. Fallo SQL durante confirmación -> STATUS_ERROR sin detalles SQL
        $repo->forzarFalloSql = true;
        $errorSql = $activar($tokenConfirmacion);
        $this->assertSame(AuthService::STATUS_ERROR, $errorSql['status']);
        $this->assertStringNotContainsString('SQLSTATE', json_encode($errorSql['alertas']));
        $repo->forzarFalloSql = false;

        // 3. Consumo exitoso de un solo uso -> STATUS_OK y segundo intento rechazado
        $ok = $activar($tokenConfirmacion);
        $this->assertSame(AuthService::STATUS_OK, $ok['status']);
        $this->assertTrue(password_verify('ClaveDefinitiva9', $repo->findByEmail('sofia@correo.com')->password), 'la contraseña elegida al activar reemplaza a la provisional');
        $this->assertFalse(password_verify('inicial123', $repo->findByEmail('sofia@correo.com')->password));
        $reuso = $activar($tokenConfirmacion);
        $this->assertSame(AuthService::STATUS_INVALID, $reuso['status']);

        // 4. Emitir token de recuperación y validar/restablecer contraseña
        $u = $repo->findByEmail('sofia@correo.com');
        $tokenRec = $repo->issueRecoveryToken($u, 2);
        $this->assertNotNull($tokenRec);

        $valOk = $service->validarTokenRecuperacion($tokenRec);
        $this->assertSame(AuthService::STATUS_OK, $valOk['status']);
        $this->assertFalse($valOk['error']);

        // Contraseña demasiado corta -> STATUS_INVALID sin consumir el token
        $passCorta = $service->restablecerPassword($tokenRec, ['password' => '123']);
        $this->assertSame(AuthService::STATUS_INVALID, $passCorta['status']);
        $this->assertFalse($passCorta['error']);

        // Contraseña no escalar -> STATUS_INVALID_TYPE
        $passArray = $service->restablecerPassword($tokenRec, ['password' => ['array']]);
        $this->assertSame(AuthService::STATUS_INVALID_TYPE, $passArray['status']);

        // Restablecimiento válido -> consume el token y actualiza el hash
        $resetOk = $service->restablecerPassword($tokenRec, ['password' => 'nuevaClaveSegura99']);
        $this->assertSame(AuthService::STATUS_OK, $resetOk['status']);
        $actualizado = $repo->findByEmail('sofia@correo.com');
        $this->assertTrue(password_verify('nuevaClaveSegura99', $actualizado->password));
        $this->assertNull($actualizado->token_hash);

        // Segundo intento con el mismo token de recuperación -> rechazado
        $resetReuso = $service->restablecerPassword($tokenRec, ['password' => 'otraClave123']);
        $this->assertSame(AuthService::STATUS_INVALID, $resetReuso['status']);
        $this->assertTrue($resetReuso['error']);
    }
}

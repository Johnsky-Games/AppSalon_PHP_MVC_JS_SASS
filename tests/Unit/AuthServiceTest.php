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

    public function findByValidToken(string $tokenRaw, string $tipo): ?Usuario
    {
        $this->checkFallo();
        $hash = hash('sha256', trim($tokenRaw));
        foreach ($this->usuarios as $u) {
            if (
                $u->token_hash === $hash &&
                $u->token_tipo === $tipo &&
                $u->token_expira !== null &&
                strtotime($u->token_expira) >= time()
            ) {
                return clone $u;
            }
        }
        return null;
    }

    public function confirmAccountByToken(string $tokenRaw): bool
    {
        $this->checkFallo();
        $hash = hash('sha256', trim($tokenRaw));
        foreach ($this->usuarios as $id => $u) {
            if (
                $u->token_hash === $hash &&
                $u->token_tipo === 'confirmacion' &&
                $u->token_expira !== null &&
                strtotime($u->token_expira) >= time()
            ) {
                $u->confirmado = '1';
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

    public function resetPasswordByToken(string $tokenRaw, string $nuevoPasswordHash): bool
    {
        $this->checkFallo();
        $hash = hash('sha256', trim($tokenRaw));
        foreach ($this->usuarios as $id => $u) {
            if (
                $u->token_hash === $hash &&
                $u->token_tipo === 'recuperacion' &&
                $u->token_expira !== null &&
                strtotime($u->token_expira) >= time()
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

        // 1. Token vacío o inexistente -> STATUS_INVALID
        $invalido = $service->confirmarCuenta('token_inexistente');
        $this->assertSame(AuthService::STATUS_INVALID, $invalido['status']);

        // 2. Fallo SQL durante confirmación -> STATUS_ERROR sin detalles SQL
        $repo->forzarFalloSql = true;
        $errorSql = $service->confirmarCuenta($tokenConfirmacion);
        $this->assertSame(AuthService::STATUS_ERROR, $errorSql['status']);
        $this->assertStringNotContainsString('SQLSTATE', json_encode($errorSql['alertas']));
        $repo->forzarFalloSql = false;

        // 3. Consumo exitoso de un solo uso -> STATUS_OK y segundo intento rechazado
        $ok = $service->confirmarCuenta($tokenConfirmacion);
        $this->assertSame(AuthService::STATUS_OK, $ok['status']);
        $reuso = $service->confirmarCuenta($tokenConfirmacion);
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

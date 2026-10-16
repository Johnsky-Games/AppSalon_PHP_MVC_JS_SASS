<?php

namespace Tests\Unit;

use Model\ActiveRecord;
use Model\Usuario;
use PHPUnit\Framework\TestCase;

class UsuarioSeguridadTest extends TestCase
{
    public function testEntidadUsuarioEstaDesacopladaDeActiveRecord(): void
    {
        $this->assertFalse(
            is_subclass_of(Usuario::class, ActiveRecord::class),
            'Model\\Usuario debe estar desacoplada de ActiveRecord en la Fase 2C'
        );
        $this->assertTrue(
            class_exists(ActiveRecord::class),
            'Model\\ActiveRecord debe conservarse disponible'
        );
    }

    public function testRegistroBloqueaAsignacionMasivaDePrivilegios(): void
    {
        $usuario = new Usuario();

        // Intento malicioso de inyectar admin=1, confirmado=1, id falso y campos de token
        $payloadMalicioso = [
            'nombre' => 'Juan',
            'apellido' => 'Perez',
            'email' => 'juan@correo.com',
            'password' => '123456',
            'telefono' => '1234567890',
            'admin' => '1',
            'confirmado' => '1',
            'id' => '999',
            'token' => 'token_forzado',
            'token_hash' => 'hash_forzado',
            'token_tipo' => 'recuperacion',
            'token_expira' => '2099-01-01 00:00:00'
        ];

        $usuario->sincronizarRegistro($payloadMalicioso);

        $this->assertSame('0', $usuario->admin, 'El rol de admin debe ser estrictamente 0');
        $this->assertSame('0', $usuario->confirmado, 'La confirmación debe ser estrictamente 0');
        $this->assertNull($usuario->id, 'El ID no puede ser asignado desde el payload de registro');
        $this->assertSame('', $usuario->token, 'El token no puede ser inyectado por el usuario');
        $this->assertNull($usuario->token_hash, 'El token_hash no puede ser inyectado por el usuario');
        $this->assertNull($usuario->token_tipo, 'El token_tipo no puede ser inyectado por el usuario');
        $this->assertNull($usuario->token_expira, 'El token_expira no puede ser inyectado por el usuario');
        $this->assertSame('Juan', $usuario->nombre);
        $this->assertSame('juan@correo.com', $usuario->email);

        // También el alias sincronizar() debe aplicar la misma protección estricta
        $usuarioAlias = new Usuario();
        $usuarioAlias->sincronizar($payloadMalicioso);
        $this->assertSame('0', $usuarioAlias->admin);
        $this->assertSame('0', $usuarioAlias->confirmado);
        $this->assertNull($usuarioAlias->id);
        $this->assertNull($usuarioAlias->token_hash);
    }

    public function testGeneracionDeTokenCriptograficoConHashYExpiracion(): void
    {
        $usuario = new Usuario();
        $tokenRaw = $usuario->generarTokenSeguro('confirmacion', 24);

        $this->assertSame(64, strlen($tokenRaw), 'El token raw debe tener 64 caracteres hexadecimales (256 bits)');
        $this->assertSame(hash('sha256', $tokenRaw), $usuario->token_hash, 'El token_hash debe ser el hash SHA-256 del raw');
        $this->assertSame('confirmacion', $usuario->token_tipo);
        $this->assertNotNull($usuario->token_expira);

        // La fecha de expiración debe ser en el futuro (~24 horas)
        $tiempoExpira = strtotime($usuario->token_expira);
        $this->assertGreaterThan(time() + 80000, $tiempoExpira);
    }

    public function testDiferenciacionDePropositoDeTokens(): void
    {
        $usuario = new Usuario();
        $tokenRecuperacion = $usuario->generarTokenSeguro('recuperacion', 2);

        $this->assertSame('recuperacion', $usuario->token_tipo);
        $tiempoExpira = strtotime($usuario->token_expira);
        // Expiración a 2 horas (~7200 segundos)
        $this->assertLessThanOrEqual(time() + 7205, $tiempoExpira);
        $this->assertGreaterThan(time() + 7100, $tiempoExpira);
    }
}

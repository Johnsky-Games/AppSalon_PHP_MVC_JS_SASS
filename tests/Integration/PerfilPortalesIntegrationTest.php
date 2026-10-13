<?php

namespace Tests\Integration;

use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\ProfileRepository;
use Services\ProfileService;

/** El perfil de cliente y el perfil laboral no se filtran entre portales ni alteran consentimientos ajenos. */
final class PerfilPortalesIntegrationTest extends TestCase
{
    private \mysqli $db;
    private int $id;

    protected function setUp(): void
    {
        $this->db = new \mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', getenv('DB_NAME') ?: 'appsalon_test', (int)(getenv('DB_PORT') ?: 3306));
        $this->db->set_charset('utf8mb4');
        ActiveRecord::setDB($this->db);
        validar_base_datos_prueba($this->db);
        $email = 'perfil-portal-' . bin2hex(random_bytes(4)) . '@portales.test';
        $this->db->query("INSERT INTO usuarios(nombre,apellido,email,password,telefono,admin,confirmado) VALUES ('Dual','Persona','$email','h','0991234567',0,1)");
        $this->id = (int)$this->db->insert_id;
        // Cliente con consentimiento comercial otorgado y recordatorios activos.
        $this->db->query("INSERT INTO perfiles_usuario(usuarioId,contacto_email,marketing_email,recordatorios,foto_publica,consentimiento_marketing_at) VALUES ({$this->id},1,1,1,0,NOW())");
        // Además es profesional activa (ficha vinculada).
        $this->db->query("INSERT INTO profesionales(nombre,activo,usuarioId) VALUES ('Perfil Portal Dual',1,{$this->id})");
    }

    protected function tearDown(): void
    {
        $this->db->query("DELETE FROM profesionales WHERE usuarioId={$this->id}");
        $this->db->query("DELETE FROM usuarios WHERE id={$this->id}");
        $this->db->close();
    }

    private function servicio(): ProfileService
    {
        return new ProfileService(new ProfileRepository($this->db));
    }

    private function perfil(): array
    {
        return $this->db->query("SELECT contacto_email,marketing_email,recordatorios,foto_publica,consentimiento_marketing_at FROM perfiles_usuario WHERE usuarioId={$this->id}")->fetch_assoc();
    }

    public function testGuardarDesdeElPortalDelEquipoNoAlteraComunicacionesNiConsentimientoComercial(): void
    {
        $antes = $this->perfil();
        // El formulario del equipo no incluye casillas de comunicación: no deben interpretarse como «retirar consentimiento».
        $this->servicio()->guardar($this->id, ['nombre' => 'Nuevo', 'apellido' => 'Nombre', 'telefono' => '0997654321', 'foto_publica' => '1'], true, 'equipo');
        $despues = $this->perfil();
        $this->assertSame($antes['marketing_email'], $despues['marketing_email']);
        $this->assertSame($antes['recordatorios'], $despues['recordatorios']);
        $this->assertSame($antes['contacto_email'], $despues['contacto_email']);
        $this->assertSame($antes['consentimiento_marketing_at'], $despues['consentimiento_marketing_at']);
        $this->assertSame('1', $despues['foto_publica'], 'La publicación del retrato sí es una decisión del perfil laboral');
        $this->assertSame('Nuevo', $this->db->query("SELECT nombre FROM usuarios WHERE id={$this->id}")->fetch_assoc()['nombre']);
    }

    public function testGuardarDesdeElPortalDeClientesNoPublicaElRetratoProfesional(): void
    {
        $this->servicio()->guardar($this->id, ['nombre' => 'Dual', 'apellido' => 'Persona', 'telefono' => '0991234567', 'contacto_email' => '1', 'recordatorios' => '1', 'foto_publica' => '1'], true, 'cliente');
        $this->assertSame('0', $this->perfil()['foto_publica'], 'Publicar el retrato es del perfil laboral');
    }

    public function testElClientePuedeRetirarSuConsentimientoDesdeSuPortal(): void
    {
        $this->servicio()->guardar($this->id, ['nombre' => 'Dual', 'apellido' => 'Persona', 'telefono' => '0991234567', 'contacto_email' => '1'], true, 'cliente');
        $perfil = $this->perfil();
        $this->assertSame('0', $perfil['marketing_email']);
        $this->assertNull($perfil['consentimiento_marketing_at']);
        $this->assertSame('0', $perfil['recordatorios']);
    }

    public function testElPersonalNoPuedeOtorgarConsentimientoPromocionalPorElCliente(): void
    {
        $this->db->query("UPDATE perfiles_usuario SET marketing_email=0, consentimiento_marketing_at=NULL WHERE usuarioId={$this->id}");
        $this->expectException(\InvalidArgumentException::class);
        $this->servicio()->guardar($this->id, ['nombre' => 'Dual', 'apellido' => 'Persona', 'telefono' => '0991234567', 'marketing_email' => '1'], false, null);
    }
}

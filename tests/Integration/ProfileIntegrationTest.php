<?php
namespace Tests\Integration;

use Model\ActiveRecord;
use PHPUnit\Framework\TestCase;
use Repositories\ProfileRepository;
use Services\ProfileService;

final class ProfileIntegrationTest extends TestCase
{
    private \mysqli $db;
    private ?\mysqli $previousDb;
    private int $id;
    private ProfileService $service;
    protected function setUp(): void
    {
        // Cada caso posee su conexión: otro módulo puede cerrar la suya al terminar.
        $this->previousDb = ActiveRecord::getDB();
        $this->db = new \mysqli(getenv('DB_HOST') ?: 'appsalon-test-db', getenv('DB_USER') ?: 'root',
            getenv('DB_PASS') ?: 'root', getenv('DB_NAME') ?: 'appsalon_test', (int)(getenv('DB_PORT') ?: 3306));
        $this->db->set_charset('utf8mb4');
        validar_base_datos_prueba($this->db);
        ActiveRecord::setDB($this->db);
        $email = 'perfil-' . bin2hex(random_bytes(6)) . '@test.local';
        $stmt = $this->db->prepare("INSERT INTO usuarios(nombre,apellido,email,password,telefono,admin,confirmado) VALUES ('Perfil','Prueba',?,'hash','0991234567','0','1')");
        $stmt->bind_param('s',$email); $stmt->execute(); $stmt->close();
        $this->id = (int)$this->db->insert_id;
        $this->service = new ProfileService(new ProfileRepository($this->db));
    }
    protected function tearDown(): void
    {
        $stmt = $this->db->prepare('DELETE FROM usuarios WHERE id=?'); $stmt->bind_param('i',$this->id); $stmt->execute(); $stmt->close();
        ActiveRecord::setDB($this->previousDb);
        $this->db->close();
    }
    private function datos(): array { return ['nombre'=>'Ana','apellido'=>'Mora','telefono'=>'0991234567','marketing_email'=>'1','recordatorios'=>'0','contacto_email'=>'1']; }
    public function testPreferenciasConsentimientoYAllowlistPersistenSinCambiarCuenta(): void
    {
        $this->service->guardar($this->id,$this->datos()+['admin'=>'1','email'=>'atacante@test.local','password'=>'nuevo']);
        $perfil = $this->service->obtener($this->id);
        self::assertSame('Ana',$perfil['nombre']); self::assertSame(1,(int)$perfil['marketing_email']); self::assertSame(0,(int)$perfil['recordatorios']);
        $row = $this->db->query('SELECT u.admin,u.password,p.consentimiento_marketing_at FROM usuarios u JOIN perfiles_usuario p ON p.usuarioId=u.id WHERE u.id=' . $this->id)->fetch_assoc();
        self::assertSame('0',$row['admin']); self::assertSame('hash',$row['password']); self::assertNotNull($row['consentimiento_marketing_at']);
        $datos = $this->datos(); $datos['marketing_email']='0'; $this->service->guardar($this->id,$datos);
        $row = $this->db->query('SELECT consentimiento_marketing_at FROM perfiles_usuario WHERE usuarioId=' . $this->id)->fetch_assoc(); self::assertNull($row['consentimiento_marketing_at']);
    }
    public function testGestorNoPuedeOtorgarConsentimientoEnNombreDelCliente(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->guardar($this->id,$this->datos(),false);
    }
    public function testClienteNoPuedePublicarFotoNiInyectarPreferencia(): void
    {
        $datos=$this->datos(); $datos['foto_publica']='1';
        try { $this->service->guardar($this->id,$datos); self::fail('Publicación de cliente aceptada'); } catch (\InvalidArgumentException $e) { self::assertStringContainsString('privada',$e->getMessage()); }
        $datos=$this->datos(); $datos['recordatorios']=['1'];
        $this->expectException(\InvalidArgumentException::class); $this->service->guardar($this->id,$datos);
    }
    public function testFotografiaPrivadaImpideAccesoCruzado(): void
    {
        $perfil=$this->service->obtener($this->id);
        self::assertTrue($this->service->fotoVisible($perfil,$this->id));
        self::assertFalse($this->service->fotoVisible($perfil,$this->id+1));
        self::assertFalse($this->service->fotoVisible($perfil,null));
        self::assertTrue($this->service->fotoVisible($perfil,$this->id+1,true));
        $perfil['foto_publica']=1; self::assertFalse($this->service->fotoVisible($perfil,null));
        $perfil['es_profesional']=1; self::assertTrue($this->service->fotoVisible($perfil,null));
        $perfil['archivo']='../../includes/.env'; self::assertNull($this->service->rutaFoto($perfil));
    }
    public function testSeRechazanSvgYArchivosFalsosSinConfiarEnNombre(): void
    {
        $temp=tempnam(sys_get_temp_dir(),'perfil'); file_put_contents($temp,'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        try { ProfileService::validarImagen($temp); self::fail('SVG aceptado'); } catch (\InvalidArgumentException $e) { self::assertStringContainsString('JPEG',$e->getMessage()); } finally { unlink($temp); }
        $temp=tempnam(sys_get_temp_dir(),'perfil'); file_put_contents($temp,'<?php echo "payload";');
        try { ProfileService::validarImagen($temp); self::fail('PHP aceptado'); } catch (\InvalidArgumentException $e) { self::assertStringContainsString('JPEG',$e->getMessage()); } finally { unlink($temp); }
    }
    public function testImagenValidaYPixelBomb(): void
    {
        $temp=tempnam(sys_get_temp_dir(),'perfil'); $image=imagecreatetruecolor(2,2); imagepng($image,$temp); imagedestroy($image);
        try { self::assertSame([2,2],ProfileService::validarImagen($temp)); } finally { unlink($temp); }
        $temp=tempnam(sys_get_temp_dir(),'perfil'); $image=imagecreatetruecolor(4097,1); imagepng($image,$temp); imagedestroy($image);
        try { ProfileService::validarImagen($temp); self::fail('Pixel bomb aceptado'); } catch (\InvalidArgumentException $e) { self::assertStringContainsString('4096',$e->getMessage()); } finally { unlink($temp); }
    }
    public function testCatalogoExponeSoloRetratosProfesionalesConsentidos(): void
    {
        $this->db->query("INSERT INTO profesionales(nombre,activo,usuarioId) VALUES ('Retrato autorizado',1," . $this->id . ')');
        $prof=(int)$this->db->insert_id;
        try {
            $repo=new ProfileRepository($this->db); $repo->guardarFoto($this->id,str_repeat('a',64) . '.jpg');
            self::assertArrayNotHasKey($prof,$repo->fotosProfesionalesPublicas());
            $datos=$this->datos(); $datos['foto_publica']='1'; $this->service->guardar($this->id,$datos);
            self::assertSame('/perfil/foto?id=' . $this->id,$repo->fotosProfesionalesPublicas()[$prof]);
            $this->db->query('UPDATE profesionales SET activo=0 WHERE id=' . $prof);
            self::assertArrayNotHasKey($prof,$repo->fotosProfesionalesPublicas());
        } finally { $this->db->query('DELETE FROM profesionales WHERE id=' . $prof); }
    }

    public function testArchivoLocalNoPuedeSuplantarUploadHttp(): void
    {
        $temp=tempnam(sys_get_temp_dir(),'perfil'); file_put_contents($temp,'x');
        try { $this->service->subirFoto($this->id,['tmp_name'=>$temp,'error'=>UPLOAD_ERR_OK]); self::fail('Archivo local aceptado'); } catch (\InvalidArgumentException $e) { self::assertStringContainsString('recibió',$e->getMessage()); } finally { unlink($temp); }
    }
}

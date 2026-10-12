<?php
namespace Tests\Integration;
use PHPUnit\Framework\TestCase;
use Repositories\CrecimientoRepository;
use Services\CrecimientoService;
class CrecimientoIntegrationTest extends TestCase
{
    private \mysqli $db;
    private array $usuarios=[];
    private array $formularios=[];
    protected function setUp(): void
    {
        $this->db=new \mysqli(getenv('DB_HOST')?:'appsalon-test-db',getenv('DB_USER')?:'root',getenv('DB_PASS')?:'root',getenv('DB_NAME')?:'appsalon_test',(int)(getenv('DB_PORT')?:3306));
        $this->db->set_charset('utf8mb4');validar_base_datos_prueba($this->db);
        for($i=0;$i<2;$i++) {
            $email='growth-'.bin2hex(random_bytes(6)).'@test.local';$pw=password_hash('PruebaSegura123!',PASSWORD_BCRYPT);
            $s=$this->db->prepare("INSERT INTO usuarios(nombre,apellido,email,password,confirmado,admin) VALUES('Cliente','Prueba',?,?,1,0)");$s->bind_param('ss',$email,$pw);$this->assertTrue($s->execute());$this->usuarios[]=(int)$s->insert_id;$s->close();
        }
    }
    protected function tearDown(): void
    {
        foreach($this->formularios as $id){$this->db->query('DELETE FROM formularios_cliente WHERE id='.(int)$id);}
        foreach($this->usuarios as $id){$this->db->query('DELETE FROM citas WHERE usuarioId='.(int)$id);$this->db->query('DELETE FROM usuarios WHERE id='.(int)$id);}
        $this->db->close();
    }
    private function repo(): CrecimientoRepository {return new CrecimientoRepository($this->db);}
    private function cita(int $usuario,string $estado): int
    {
        $s=$this->db->prepare("INSERT INTO citas(fecha,hora,usuarioId,estado) VALUES('2026-01-12','11:00:00',?,?)");$s->bind_param('is',$usuario,$estado);$this->assertTrue($s->execute());$id=(int)$s->insert_id;$s->close();return $id;
    }
    public function testPuntosUnicamenteCompletadasYSinDuplicar(): void
    {
        $id=$this->usuarios[0];$this->cita($id,'completada');$this->cita($id,'cancelada');$this->cita($id,'confirmada');$r=$this->repo();
        $r->sincronizarPuntos($id);$r->sincronizarPuntos($id);$data=$r->cliente($id);
        $this->assertSame(10,$data['saldo']);$this->assertCount(1,$data['movimientos']);$this->assertSame(0,$r->cliente($this->usuarios[1])['saldo']);
    }
    public function testCanjeIdempotenteYSaldoNoNegativo(): void
    {
        $id=$this->usuarios[0];for($i=0;$i<10;$i++){$this->cita($id,'completada');}$r=$this->repo();$r->sincronizarPuntos($id);$clave=str_repeat('a',32);
        $r->canjear($id,$clave);$r->canjear($id,$clave);$this->assertSame(0,$r->cliente($id)['saldo']);
        $n=$this->db->query("SELECT COUNT(*) n FROM fidelidad_movimientos WHERE usuario_id=$id AND tipo='canje'")->fetch_assoc();$this->assertSame(1,(int)$n['n']);
        $this->expectException(\InvalidArgumentException::class);$r->canjear($id,str_repeat('b',32));
    }
    public function testOptOutEnPerfilExcluyeExportacionMarketing(): void
    {
        $id=$this->usuarios[0];$r=$this->repo();$r->consentimiento($id,true);$ids=array_map('intval',array_column($r->segmento('todos'),'id'));$this->assertContains($id,$ids);
        $perfil=$this->db->query("SELECT marketing_email,consentimiento_marketing_at FROM perfiles_usuario WHERE usuarioId=$id")->fetch_assoc();$this->assertNotNull($perfil['consentimiento_marketing_at']);
        $r->consentimiento($id,false);$this->assertNotContains($id,array_map('intval',array_column($r->segmento('todos'),'id')));
        $this->assertNull($this->db->query("SELECT consentimiento_marketing_at FROM perfiles_usuario WHERE usuarioId=$id")->fetch_assoc()['consentimiento_marketing_at']);
    }
    public function testRespuestasPrivadasSeparadasPorPropietarioYArchivoConservaHistoria(): void
    {
        $r=$this->repo();$s=new CrecimientoService($r);$preguntas=$s->validarPreguntas([['etiqueta'=>'Preferencia','tipo'=>'texto','requerida'=>true]]);$r->crearFormulario($this->usuarios[0],'Ficha crecimiento prueba',$preguntas);
        $id=(int)$this->db->insert_id;$this->formularios[]=$id;
        $s->accionCliente($this->usuarios[0],['accion'=>'respuesta','formulario_id'=>(string)$id,'respuestas'=>['p0'=>'Silencio']]);
        $s->accionCliente($this->usuarios[1],['accion'=>'respuesta','formulario_id'=>(string)$id,'respuestas'=>['p0'=>'Conversación']]);
        $filas=$r->respuestasPrivadas($id);$this->assertCount(2,$filas);
        foreach($this->usuarios as $i=>$uid){$form=array_values(array_filter($r->cliente($uid)['formularios'],fn($f)=>(int)$f['id']===$id))[0];$this->assertSame(['p0'=>$i===0?'Silencio':'Conversación'],json_decode($form['respuestas'],true));}
        $r->archivarFormulario($id);$this->assertNull($r->formulario($id));$this->assertCount(2,$r->respuestasPrivadas($id));
        $this->expectException(\InvalidArgumentException::class);$s->accionCliente($this->usuarios[0],['accion'=>'respuesta','formulario_id'=>(string)$id,'respuestas'=>['p0'=>'Nuevo']]);
    }
}

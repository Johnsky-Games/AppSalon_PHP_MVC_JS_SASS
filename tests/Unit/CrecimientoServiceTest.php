<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
use Repositories\CrecimientoRepository;
use Services\CrecimientoService;
class CrecimientoServiceTest extends TestCase
{
    private function service(): CrecimientoService { return new CrecimientoService($this->createMock(CrecimientoRepository::class)); }
    public function testRechazaTiposActivosYListasSinOpciones(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->validarPreguntas([['etiqueta'=>'Datos','tipo'=>'html']]);
    }
    public function testNormalizaPreguntasYSoloAceptaOpcionesPublicadas(): void
    {
        $s=$this->service();$p=$s->validarPreguntas([['etiqueta'=>' Preferencia ','tipo'=>'select','opciones'=>['Silencio','Conversación'],'requerida'=>true]]);
        $this->assertSame('Preferencia',$p[0]['etiqueta']);$this->assertSame('p0',$p[0]['id']);
        $this->assertSame(['p0'=>'Silencio'],$s->validarRespuestas($p,['p0'=>'Silencio']));
        $this->expectException(\InvalidArgumentException::class);$s->validarRespuestas($p,['p0'=>'Inyectado']);
    }
    public function testConsentimientoObligatorioNoSeAceptaPorOmisión(): void
    {
        $s=$this->service();$p=$s->validarPreguntas([['etiqueta'=>'Aceptación informada','tipo'=>'checkbox','requerida'=>true]]);
        $this->expectException(\InvalidArgumentException::class);$s->validarRespuestas($p,[]);
    }
    public function testCasillaOpcionalYTextoEscapablePermanecenDatosNoHtml(): void
    {
        $s=$this->service();$p=$s->validarPreguntas([['etiqueta'=>'Mensaje','tipo'=>'texto'],['etiqueta'=>'Acepto','tipo'=>'checkbox']]);
        $this->assertSame(['p0'=>'<script>alert(1)</script>','p1'=>false],$s->validarRespuestas($p,['p0'=>'<script>alert(1)</script>']));
    }
    public function testEsperaRechazaFechaImposibleAntesDePersistir(): void
    {
        $r=$this->createMock(CrecimientoRepository::class);$r->expects($this->never())->method('agregarEspera');$s=new CrecimientoService($r);
        $this->expectException(\InvalidArgumentException::class);$s->accionCliente(1,['accion'=>'espera','servicio_id'=>'1','fecha'=>'2030-02-30']);
    }
    public function testCanjeRechazaClaveManipuladaAntesDePersistir(): void
    {
        $r=$this->createMock(CrecimientoRepository::class);$r->expects($this->never())->method('canjear');
        $this->expectException(\InvalidArgumentException::class);(new CrecimientoService($r))->accionCliente(1,['accion'=>'canje','clave'=>'../otro']);
    }
    public function testSegmentoDesconocidoNoAmpliaAudiencia(): void
    {
        $r=$this->createMock(CrecimientoRepository::class);$r->expects($this->never())->method('segmento');
        $this->expectException(\InvalidArgumentException::class);(new CrecimientoService($r))->segmento('sin_consentimiento');
    }
    public function testPublicacionEditorOmitiendoFilasVacias(): void
    {
        $r=$this->createMock(CrecimientoRepository::class);$r->expects($this->once())->method('crearFormulario')->with(7,'Ficha', [['id'=>'p0','etiqueta'=>'Ambiente','tipo'=>'select','requerida'=>false,'opciones'=>['Calma','Conversación']]]);
        $s=new CrecimientoService($r);$this->assertSame('Formulario publicado.',$s->accionAdmin(7,['accion'=>'formulario','titulo'=>'Ficha','preguntas'=>[['etiqueta'=>'Ambiente','tipo'=>'select','opciones'=>'Calma, Conversación'],['etiqueta'=>'','tipo'=>'texto']]]));
    }
    public function testRespuestasRechazanPreguntasDesconocidas(): void
    {
        $s=$this->service();$p=$s->validarPreguntas([['etiqueta'=>'Preferencia','tipo'=>'texto']]);
        $this->expectException(\InvalidArgumentException::class);$s->validarRespuestas($p,['p0'=>'Silencio','otro'=>'No publicada']);
    }
    public function testEditorNoConvierteArraysEnEtiquetas(): void
    {
        $r=$this->createMock(CrecimientoRepository::class);$r->expects($this->never())->method('crearFormulario');
        $this->expectException(\InvalidArgumentException::class);(new CrecimientoService($r))->accionAdmin(1,['accion'=>'formulario','titulo'=>'Ficha','preguntas'=>[['etiqueta'=>['no texto']]]]);
    }
}

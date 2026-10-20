<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
use Services\{ErpTesoreriaService,ErpException};

/** Lectura estricta del extracto en texto y de los importes con signo (centavos enteros, sin flotantes). */
final class ErpTesoreriaExtractoTest extends TestCase
{
    public function testImportesConSignoSoloDecimalesExactos():void
    {
        foreach(['100.00'=>10000,'-30.5'=>-3050,'0.01'=>1,'-1234567.89'=>-123456789,'7'=>700,' 12.30 '=>1230] as $in=>$out)self::assertSame($out,ErpTesoreriaService::centavos($in),$in);
        foreach(['','1,5','12.345','-.5','.5','1e3','+5','--1','1 000.00','abc','9999999999.00'] as $mal)self::assertNull(ErpTesoreriaService::centavos($mal),$mal);
    }
    public function testLeeConEncabezadoBomYFinesDeLineaMixtos():void
    {
        $t="\xEF\xBB\xBFfecha;descripcion;referencia;monto\r\n2026-03-03;Depósito;REF1;100.00\n\n2026-03-11;Retiro;;-30.00\r2026-03-12;Sin referencia;5.25";
        $l=ErpTesoreriaService::leerExtracto($t);
        self::assertSame([['fecha'=>'2026-03-03','descripcion'=>'Depósito','referencia'=>'REF1','monto'=>10000],['fecha'=>'2026-03-11','descripcion'=>'Retiro','referencia'=>null,'monto'=>-3000],['fecha'=>'2026-03-12','descripcion'=>'Sin referencia','referencia'=>null,'monto'=>525]],$l);
    }
    public function testUnaFechaMalEscritaNoSeTomaComoEncabezado():void
    {
        $e=$this->falla("26-03-03;Depósito;REF1;100.00");self::assertStringContainsString('Línea 1',$e);
        $e=$this->falla("2026-03-03;Ok;;1.00\n2026-13-40;Mala;;2.00");self::assertStringContainsString('Línea 2: fecha no válida',$e);
    }
    public function testRechazaLoQueNoSePuedeInterpretarConElNumeroDeLinea():void
    {
        self::assertStringContainsString('Línea 1: monto no válido',$this->falla("2026-03-03;Cero;;0.00"));
        self::assertStringContainsString('Línea 2: monto no válido',$this->falla("2026-03-03;Ok;;1.00\n2026-03-04;Coma;;1,50"));
        self::assertStringContainsString('Línea 1: se esperan',$this->falla("2026-03-03;solo dos"));
        self::assertStringContainsString('Línea 1: descripción',$this->falla("2026-03-03;;;1.00"));
        self::assertStringContainsString('Línea 1: descripción',$this->falla("2026-03-03;".str_repeat('x',161).";;1.00"));
        self::assertStringContainsString('no tiene líneas',$this->falla("fecha;descripcion;referencia;monto\n"));
        self::assertStringContainsString('UTF-8',$this->falla("2026-03-03;Dep\xE9sito;;1.00"));
        self::assertStringContainsString('KB',$this->falla(str_repeat('a',ErpTesoreriaService::MAX_BYTES+1)));
    }
    public function testTopeDeLineas():void
    {
        $ok=implode("\n",array_map(fn($i)=>'2026-03-03;Mov '.$i.';;1.00',range(1,ErpTesoreriaService::MAX_LINEAS)));
        self::assertCount(ErpTesoreriaService::MAX_LINEAS,ErpTesoreriaService::leerExtracto($ok));
        self::assertStringContainsString('hasta '.ErpTesoreriaService::MAX_LINEAS,$this->falla($ok."\n2026-03-03;Una más;;1.00"));
    }
    private function falla(string $t):string {try{ErpTesoreriaService::leerExtracto($t);}catch(ErpException $e){return $e->getMessage();}self::fail('Se esperaba un rechazo');}
}

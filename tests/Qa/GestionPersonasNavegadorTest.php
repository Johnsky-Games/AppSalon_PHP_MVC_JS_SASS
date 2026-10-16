<?php
namespace Tests\Qa;

require_once __DIR__ . '/QaCase.php';

/** Recorre las vistas nuevas y guarda grupos de días desde RH, agenda y un navegador sin JavaScript. */
final class GestionPersonasNavegadorTest extends QaCase
{
    public function testDirectoriosYConfiguracionSemanalEnNavegadorReal(): void
    {
        $chrome = getenv('CHROME_BIN') ?: '';
        if (!is_executable($chrome)) {
            if (getenv('QA_EXIGIR_NAVEGADOR') === '1') { $this->fail('Falta el navegador obligatorio CHROME_BIN.'); }
            $this->markTestSkipped('CHROME_BIN no disponible.');
        }
        self::limpiar();
        $owner = self::propietario(); $cliente = self::cliente(['nombre' => 'Cliente', 'apellido' => 'Externo']);
        // Reproduce una membresía histórica del administrador sin borrar su identidad ni sus datos.
        self::q("INSERT INTO clientes(usuario_id,estado,origen) VALUES(?,'activo','migracion')", [$owner['id']]);
        $pro = self::miembro($owner['id'], 'profesional', ['nombre' => 'Profesional', 'apellido' => 'Agenda']);
        $cfg = ['base' => self::servidor([], 'gestion-personas'), 'owner' => $owner, 'cliente' => $cliente, 'profesional' => $pro, 'capturas' => getenv('GESTION_CAPTURAS') ?: null];
        try {
            $p = proc_open(['node', self::RAIZ . '/tests/Qa/gestion_personas_navegador.cjs'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::RAIZ,
                array_merge(getenv(), ['QA_CONFIG' => json_encode($cfg), 'CHROME_BIN' => $chrome]));
            $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            $codigo = proc_close($p); $r = json_decode($out, true);
            $this->assertSame(0, $codigo, substr($err . $out, 0, 2000));
            $this->assertIsArray($r); $this->assertSame([], $r['errores'] ?? ['Sin resultados']);
            $this->assertGreaterThanOrEqual(18, count($r['responsive']));
            foreach ($r['responsive'] as $pantalla) { $this->assertFalse($pantalla['desborda'], json_encode($pantalla)); }
            $this->assertSame([], $r['consola']);
            $this->assertSame([1,2,3,4,5,6,7], array_map('intval', array_column(self::q('SELECT dia_semana FROM horarios_profesionales WHERE profesionalId=? ORDER BY dia_semana', [$pro['profesional_id']]), 'dia_semana')));
            $this->assertSame(6, (int)self::escalar('SELECT COUNT(*) FROM descansos_profesionales WHERE profesionalId=?', [$pro['profesional_id']]));
            $this->assertSame('11:00:00', self::escalar('SELECT hora_inicio FROM horarios_profesionales WHERE profesionalId=? AND dia_semana=7', [$pro['profesional_id']]));
        } finally { self::pararServidor('gestion-personas'); }
    }
}

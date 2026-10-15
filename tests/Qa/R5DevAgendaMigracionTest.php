<?php

namespace Tests\Qa;

require_once __DIR__ . '/QaCase.php';

/**
 * Ronda 5 · agente a5 — migración 023 `atencion_citas` sobre una base aislada que parte de la 022 y tiene datos reales:
 * retrocarga prudente (sin inventar actores), idempotencia, trigger de estados terminales y rollback que NO pierde datos (resguardo
 * persistente que la reaplicación restaura).
 */
final class R5DevAgendaMigracionTest extends QaCase
{
    private const BD = 'appsalon_qa_a5mig_test';
    private \mysqli $db;
    private array $c = [];

    protected function setUp(): void
    {
        self::crearBd(self::BD, 22);
        $this->db = new \mysqli(self::cfg('DB_HOST', '127.0.0.1'), self::cfg('DB_USER', 'root'), self::cfg('DB_PASS', ''), self::BD, (int)self::cfg('DB_PORT', '3306'));
        mysqli_report(MYSQLI_REPORT_OFF);
        $this->db->set_charset('utf8mb4');
        $this->db->query("INSERT INTO usuarios(nombre,apellido,email,password,confirmado) VALUES ('Cli','Ente','c@m.test','h',1),('Act','Or','a@m.test','h',1),('Otro','Actor','o@m.test','h',1)");
        $this->db->query("INSERT INTO profesionales(nombre,activo) VALUES ('P',1)");
        // 1 completada con rastro · 2 no_asistio con rastro · 3 en_servicio SIN rastro · 4 completada SIN rastro · 5 confirmada
        $this->c['completada'] = $this->cita('completada');
        $this->c['ausente'] = $this->cita('no_asistio');
        $this->c['en_servicio_sin'] = $this->cita('en_servicio');
        $this->c['completada_sin'] = $this->cita('completada');
        $this->c['confirmada'] = $this->cita('confirmada');
        $this->evento($this->c['completada'], 2, 'confirmada', 'en_servicio', '2030-01-07 10:00:00');
        $this->evento($this->c['completada'], 3, 'en_servicio', 'completada', '2030-01-07 10:45:00');
        $this->evento($this->c['ausente'], 2, 'confirmada', 'no_asistio', '2030-01-07 11:20:00');
    }

    protected function tearDown(): void
    {
        $this->db->close();
        self::borrarBd(self::BD);
    }

    private function cita(string $estado): int
    {
        // Se inserta con el estado ya final: la 023 aún no existe y no hay trigger.
        $this->db->query("INSERT INTO citas(fecha,hora,hora_inicio,hora_fin,duracion_total_minutos,usuarioId,profesionalId,estado,estado_pago) VALUES ('2030-01-07','10:00:00','10:00:00','10:30:00',30,1,1,'$estado','pendiente')");
        return $this->db->insert_id;
    }

    private function evento(int $cita, int $actor, string $antes, string $despues, string $cuando): void
    {
        $this->db->query("INSERT INTO agenda_eventos(citaId,actorId,tipo,antes_json,despues_json,creado_en) VALUES ($cita,$actor,'estado','" . json_encode(['estado' => $antes]) . "','" . json_encode(['estado' => $despues]) . "','$cuando')");
    }

    private function migrador(array $args): array
    {
        $p = proc_open(array_merge([PHP_BINARY, '-d', 'variables_order=EGPCS', self::RAIZ . '/database/migrador.php'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::RAIZ, array_merge(getenv(), ['DB_NAME' => self::BD]));
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        return ['exit' => proc_close($p), 'out' => $out];
    }

    private function fila(int $id): array
    {
        return $this->db->query("SELECT estado, atencion_inicio_at, atencion_inicio_por, atencion_fin_at, atencion_fin_por, ausencia_at, ausencia_por FROM citas WHERE id=$id")->fetch_assoc();
    }

    private function esquema(): string
    {
        return (string)$this->db->query("SELECT GROUP_CONCAT(column_name ORDER BY ordinal_position) c FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='citas'")->fetch_assoc()['c'];
    }

    private function triggers(): string
    {
        return (string)$this->db->query("SELECT GROUP_CONCAT(trigger_name) t FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND event_object_table='citas'")->fetch_assoc()['t'];
    }

    public function test_aplicar_retrocarga_sin_inventar_actores_y_activa_el_trigger(): void
    {
        $r = $this->migrador(['up']);
        $this->assertSame(0, $r['exit'], $r['out']);
        $this->assertStringContainsString('023_atencion_citas aplicada exitosamente', $r['out']);
        $f = $this->fila($this->c['completada']);
        $this->assertSame(['2030-01-07 10:00:00', '2', '2030-01-07 10:45:00', '3'], [$f['atencion_inicio_at'], $f['atencion_inicio_por'], $f['atencion_fin_at'], $f['atencion_fin_por']]);
        $this->assertNull($f['ausencia_at']);
        $a = $this->fila($this->c['ausente']);
        $this->assertSame(['2030-01-07 11:20:00', '2'], [$a['ausencia_at'], $a['ausencia_por']]);
        $this->assertNull($a['atencion_inicio_at']);
        foreach (['en_servicio_sin', 'completada_sin', 'confirmada'] as $k) {
            $x = $this->fila($this->c[$k]);
            foreach (['atencion_inicio_at', 'atencion_inicio_por', 'atencion_fin_at', 'atencion_fin_por', 'ausencia_at', 'ausencia_por'] as $col) {
                $this->assertNull($x[$col], "$k.$col: sin evento no se inventa nada");
            }
        }
        $this->assertSame('trg_citas_estado_terminal', $this->triggers());
        $this->assertFalse($this->db->query("UPDATE citas SET estado='confirmada' WHERE id={$this->c['completada']}"));
        $this->assertSame('completada', $this->fila($this->c['completada'])['estado']);
        // La retrocarga no toca el estado de ninguna cita.
        $this->assertSame('en_servicio', $this->fila($this->c['en_servicio_sin'])['estado']);
    }

    public function test_el_sql_es_idempotente_si_se_vuelve_a_ejecutar(): void
    {
        $this->assertSame(0, $this->migrador(['up'])['exit']);
        $antes = [$this->esquema(), $this->fila($this->c['completada'])];
        self::ejecutarSql($this->db, (string)file_get_contents(self::RAIZ . '/database/migrations/023_atencion_citas.sql'));
        $this->assertSame($antes, [$this->esquema(), $this->fila($this->c['completada'])]);
        $this->assertSame('trg_citas_estado_terminal', $this->triggers());
    }

    public function test_el_rollback_no_pierde_datos_y_reaplicar_los_restaura(): void
    {
        $this->assertSame(0, $this->migrador(['up'])['exit']);
        // Datos nuevos escritos ya con la 023 (no reconstruibles desde agenda_eventos).
        $this->db->query("UPDATE citas SET atencion_inicio_at='2031-02-02 08:00:00', atencion_inicio_por=3 WHERE id={$this->c['en_servicio_sin']}");
        $antes = [];
        foreach ($this->c as $k => $id) { $antes[$k] = $this->fila($id); }
        $eventosAntes = (int)$this->db->query('SELECT COUNT(*) n FROM agenda_eventos')->fetch_assoc()['n'];

        $sim = $this->migrador(['down', '--hasta=023_atencion_citas', '--simular']);
        $this->assertSame(0, $sim['exit'], $sim['out']);
        $this->assertStringContainsString('no destruye datos de negocio', $sim['out']);

        $r = $this->migrador(['down', '--hasta=023_atencion_citas']);
        $this->assertSame(0, $r['exit'], 'el rollback seguro no exige aceptar pérdida: ' . $r['out']);
        $this->assertStringNotContainsString('atencion_inicio_at', $this->esquema());
        $this->assertSame('', $this->triggers());
        $this->assertSame((int)count(array_filter($antes, fn($f) => $f['atencion_inicio_at'] || $f['atencion_fin_at'] || $f['ausencia_at'])), (int)$this->db->query('SELECT COUNT(*) n FROM citas_atencion_resguardo_023')->fetch_assoc()['n'], 'toda cita con trazabilidad quedó resguardada');
        $this->assertSame($eventosAntes, (int)$this->db->query('SELECT COUNT(*) n FROM agenda_eventos')->fetch_assoc()['n'], 'agenda_eventos intacta');
        $this->assertSame('completada', $this->db->query("SELECT estado FROM citas WHERE id={$this->c['completada']}")->fetch_assoc()['estado'], 'el estado de la cita no se toca');

        $up = $this->migrador(['up']);
        $this->assertSame(0, $up['exit'], $up['out']);
        foreach ($this->c as $k => $id) { $this->assertSame($antes[$k], $this->fila($id), "$k restaurada idéntica"); }
        $this->assertSame(0, (int)$this->db->query('SELECT COUNT(*) n FROM citas_atencion_resguardo_023 WHERE restaurado_en IS NULL')->fetch_assoc()['n']);
        $this->assertSame('trg_citas_estado_terminal', $this->triggers());
    }

    public function test_un_rollback_repetido_no_falla_ni_destruye_el_resguardo(): void
    {
        $this->assertSame(0, $this->migrador(['up'])['exit']);
        $this->assertSame(0, $this->migrador(['down', '--hasta=023_atencion_citas'])['exit']);
        $n = (int)$this->db->query('SELECT COUNT(*) n FROM citas_atencion_resguardo_023')->fetch_assoc()['n'];
        $this->assertGreaterThan(0, $n);
        self::ejecutarSql($this->db, (string)file_get_contents(self::RAIZ . '/database/migrations/023_atencion_citas_rollback.sql'));
        $this->assertSame($n, (int)$this->db->query('SELECT COUNT(*) n FROM citas_atencion_resguardo_023')->fetch_assoc()['n']);
    }
}

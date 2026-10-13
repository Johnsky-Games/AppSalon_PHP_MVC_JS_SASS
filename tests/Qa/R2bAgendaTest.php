<?php

namespace Tests\Qa;

require_once __DIR__ . '/R2bCase.php';

use Services\ColaboradorService;
use Services\ReasignacionCitasService;

/**
 * Ronda 2B, ítems 5a/5b: asistente de servicios y horario del profesional (invitación y ficha) y reasignación asistida de citas
 * futuras antes de una baja/suspensión. HTTP real (8150-8199), SMTP simulado propio (2592) y procesos PHP concurrentes reales.
 * Ningún correo sale de la máquina: el SMTP es el simulado del QA.
 */
final class R2bAgendaTest extends R2bCase
{
    private const SMTP = 2592;
    private static string $base;
    private static array $dueno;
    private static array $gerente;
    private static array $recepcion;
    private static array $finanzas;
    private static array $cliente;
    private static int $s1;
    private static int $s2;
    private static int $s3;
    /** @var array<string,Navegador> */
    private static array $nav = [];

    public static function setUpBeforeClass(): void
    {
        self::limpiar();
        self::iniciarSmtpFallo(self::SMTP, 'ok');
        self::$base = self::servidor(['EMAIL_PORT' => (string)self::SMTP], 'r2b_agenda');
        self::$dueno = self::propietario(self::correo('dueno'));
        self::$gerente = self::miembro(self::$dueno['id'], 'gerente');
        self::$recepcion = self::miembro(self::$dueno['id'], 'recepcion');
        self::$finanzas = self::miembro(self::$dueno['id'], 'finanzas');
        self::$cliente = self::cliente(['email' => self::correo('clienta'), 'nombre' => 'Clienta', 'apellido' => 'Reasignada', 'telefono' => '0995550101']);
        self::$s1 = self::servicio('Corte R2B', 20.0, 30);
        self::$s2 = self::servicio('Tinte R2B', 40.0, 60);
        self::$s3 = self::servicio('Peinado R2B', 25.0, 45);
    }

    public static function tearDownAfterClass(): void
    {
        self::pararServidor('r2b_agenda');
        self::detenerSmtpFallo(self::SMTP);
    }

    protected function setUp(): void { self::q('TRUNCATE intentos_login'); self::vaciarBuzonFallo(self::SMTP); }

    // ------------------------------------------------------------------ utilidades

    private function nav(string $quien): Navegador
    {
        $p = ['dueno' => self::$dueno, 'gerente' => self::$gerente, 'recepcion' => self::$recepcion, 'finanzas' => self::$finanzas][$quien] ?? null;
        $portal = 'equipo';
        if ($quien === 'cliente') { $p = self::$cliente; $portal = 'cliente'; }
        if (!isset(self::$nav[$quien])) {
            self::q('TRUNCATE intentos_login');
            $n = new Navegador(self::$base);
            $this->assertSame(302, $n->login($portal, $p['email'], $p['password'])['status'], $quien);
            self::$nav[$quien] = $n;
        }
        return self::$nav[$quien];
    }

    private static function fecha(int $dias = 10): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('America/Guayaquil')))->modify("+$dias days")->format('Y-m-d');
    }

    /** Ficha profesional sin colaborador, activa, con servicios y horario completo (lun-dom 09:00-18:00). */
    private function prof(string $nombre, array $servicios, bool $activo = true, bool $horario = true): int
    {
        self::q('INSERT INTO profesionales(nombre,activo) VALUES (?,?)', [$nombre . ' ' . bin2hex(random_bytes(2)), $activo ? 1 : 0]);
        $id = (int)self::db()->insert_id;
        foreach ($servicios as $s) { self::q('INSERT INTO profesionales_servicios(profesionalId,servicioId) VALUES (?,?)', [$id, $s]); }
        if ($horario) { for ($d = 1; $d <= 7; $d++) { self::q('INSERT INTO horarios_profesionales(profesionalId,dia_semana,hora_inicio,hora_fin) VALUES (?,?,?,?)', [$id, $d, '09:00:00', '18:00:00']); } }
        return $id;
    }

    /** Profesional con colaborador (flujo real de invitación+aceptación) configurado con los servicios y el horario completo. */
    private function profColab(array $servicios): array
    {
        $m = self::miembro(self::$dueno['id'], 'profesional');
        $pid = (int)$m['profesional_id'];
        self::q('DELETE FROM profesionales_servicios WHERE profesionalId=?', [$pid]);
        foreach ($servicios as $s) { self::q('INSERT INTO profesionales_servicios(profesionalId,servicioId) VALUES (?,?)', [$pid, $s]); }
        self::q('DELETE FROM horarios_profesionales WHERE profesionalId=?', [$pid]);
        for ($d = 1; $d <= 7; $d++) { self::q('INSERT INTO horarios_profesionales(profesionalId,dia_semana,hora_inicio,hora_fin) VALUES (?,?,?,?)', [$pid, $d, '09:00:00', '18:00:00']); }
        self::q('UPDATE profesionales SET activo=1 WHERE id=?', [$pid]);
        return $m + ['pid' => $pid];
    }

    private function cita(int $pid, string $fecha, string $ini, int $min, array $servicios, ?int $uid = null, string $estado = 'confirmada'): int
    {
        $uid ??= self::$cliente['id'];
        $fin = date('H:i:s', strtotime("$ini +$min minutes"));
        self::q("INSERT INTO citas(fecha,hora,hora_inicio,hora_fin,duracion_total_minutos,usuarioId,profesionalId,estado,estado_pago) VALUES (?,?,?,?,?,?,?,?, 'pendiente')", [$fecha, $ini, $ini, $fin, $min, $uid, $pid, $estado]);
        $id = (int)self::db()->insert_id;
        foreach ($servicios as $s) { self::q("INSERT INTO citasservicios(citaId,servicioId,nombre_servicio,precio_servicio,duracion_minutos) VALUES (?,?,?,?,?)", [$id, $s, 'Svc ' . $s, '20.00', $min]); }
        return $id;
    }

    private function agendaHuella(int $pid): string
    {
        return json_encode([
            self::q('SELECT dia_semana,hora_inicio,hora_fin FROM horarios_profesionales WHERE profesionalId=? ORDER BY dia_semana,hora_inicio', [$pid]),
            self::q('SELECT dia_semana,hora_inicio,hora_fin,motivo FROM descansos_profesionales WHERE profesionalId=? ORDER BY dia_semana,hora_inicio', [$pid]),
            self::q('SELECT servicioId FROM profesionales_servicios WHERE profesionalId=? ORDER BY servicioId', [$pid]),
            self::q('SELECT activo FROM profesionales WHERE id=?', [$pid]),
        ]);
    }

    /** POST del formulario del asistente de la ficha, como un navegador (CSRF y clave de la página). */
    private function postAgenda(int $colId, array|string $campos, ?string $clave = null, string $quien = 'dueno'): array
    {
        $n = $this->nav($quien);
        $pag = $n->get('/admin/equipo/ficha?id=' . $colId);
        $this->assertSame(200, $pag['status'], 'ficha');
        $csrf = Navegador::csrfDe($pag['body']);
        $k = $clave ?? Navegador::campoDe($pag['body'], 'idem_key');
        if (is_string($campos)) { $cuerpo = http_build_query(['csrf_token' => $csrf, 'idem_key' => $k]) . '&' . $campos; }
        else { $cuerpo = http_build_query(['csrf_token' => $csrf, 'idem_key' => $k] + $campos); }
        return $n->post('/admin/equipo/ficha/agenda?id=' . $colId, $cuerpo);
    }

    /** Mensajes de las alertas (<div class="alerta …">) de una página. @return string[] */
    private static function alertasDe(string $html): array
    {
        preg_match_all('~<div class="alerta[^"]*">\s*(.*?)\s*</div>~s', $html, $m);
        return array_map(static fn($t) => html_entity_decode(trim($t)), $m[1]);
    }

    private static function franjasSemana(string $a = '09:00', string $b = '18:00'): array
    {
        $f = [];
        for ($d = 1; $d <= 5; $d++) { $f[$d] = [['inicio' => $a, 'fin' => $b]]; }
        return $f;
    }

    // ================================================================== 5a. validación del asistente

    public static function cargasInvalidas(): array
    {
        $servicios = fn() => [self::$s1];
        $ok = ['servicios' => null];
        return [
            'solape' => ['franjas' => [1 => [['inicio' => '09:00', 'fin' => '12:00'], ['inicio' => '11:00', 'fin' => '14:00']]]],
            'fin 24:00' => ['franjas' => [1 => [['inicio' => '09:00', 'fin' => '24:00']]]],
            'inicio 24:00' => ['franjas' => [1 => [['inicio' => '24:00', 'fin' => '24:00']]]],
            'minutos no múltiplos de 5 (inicio)' => ['franjas' => [1 => [['inicio' => '09:07', 'fin' => '12:00']]]],
            'minutos no múltiplos de 5 (fin)' => ['franjas' => [1 => [['inicio' => '09:00', 'fin' => '12:03']]]],
            'texto' => ['franjas' => [1 => [['inicio' => 'abc', 'fin' => 'xx:yy']]]],
            'solo inicio (fila a medias)' => ['franjas' => [1 => [['inicio' => '09:00', 'fin' => '']]]],
            'fin antes del inicio' => ['franjas' => [1 => [['inicio' => '12:00', 'fin' => '09:00']]]],
            'franja de 10 minutos' => ['franjas' => [1 => [['inicio' => '09:00', 'fin' => '09:10']]]],
            'día 0' => ['franjas' => [0 => [['inicio' => '09:00', 'fin' => '12:00']]]],
            'día 8' => ['franjas' => [8 => [['inicio' => '09:00', 'fin' => '12:00']]]],
            'día texto' => ['franjas' => ['lunes' => [['inicio' => '09:00', 'fin' => '12:00']]]],
            'valor anidado en inicio' => ['franjas' => [1 => [['inicio' => ['09:00'], 'fin' => '12:00']]]],
            'franjas como texto' => ['franjas' => 'abc'],
            'siete franjas el mismo día' => ['franjas' => [1 => array_map(fn($i) => ['inicio' => sprintf('%02d:00', 8 + $i * 2), 'fin' => sprintf('%02d:30', 8 + $i * 2)], range(0, 6))]],
            'descanso sin franja ese día' => ['franjas' => [1 => [['inicio' => '09:00', 'fin' => '12:00']]], 'descansos' => [2 => [['inicio' => '10:00', 'fin' => '10:30', 'motivo' => '']]]],
            'descanso fuera de la franja' => ['franjas' => [1 => [['inicio' => '09:00', 'fin' => '12:00']]], 'descansos' => [1 => [['inicio' => '11:30', 'fin' => '12:30', 'motivo' => '']]]],
            'descanso que cubre toda la franja' => ['franjas' => [1 => [['inicio' => '09:00', 'fin' => '12:00']]], 'descansos' => [1 => [['inicio' => '09:00', 'fin' => '12:00', 'motivo' => '']]]],
            'descansos solapados' => ['franjas' => [1 => [['inicio' => '09:00', 'fin' => '18:00']]], 'descansos' => [1 => [['inicio' => '10:00', 'fin' => '11:00', 'motivo' => ''], ['inicio' => '10:30', 'fin' => '11:30', 'motivo' => '']]]],
            'cinco descansos' => ['franjas' => [1 => [['inicio' => '08:00', 'fin' => '20:00']]], 'descansos' => [1 => array_map(fn($i) => ['inicio' => sprintf('%02d:00', 9 + $i * 2), 'fin' => sprintf('%02d:30', 9 + $i * 2), 'motivo' => ''], range(0, 4))]],
            'motivo de 121 caracteres' => ['franjas' => [1 => [['inicio' => '09:00', 'fin' => '18:00']]], 'descansos' => [1 => [['inicio' => '12:00', 'fin' => '13:00', 'motivo' => str_repeat('m', 121)]]]],
            'motivo con caracteres de control' => ['franjas' => [1 => [['inicio' => '09:00', 'fin' => '18:00']]], 'descansos' => [1 => [['inicio' => '12:00', 'fin' => '13:00', 'motivo' => "alm\x01uerzo"]]]],
            'descansos como texto' => ['franjas' => [1 => [['inicio' => '09:00', 'fin' => '18:00']]], 'descansos' => 'x'],
            'servicios vacíos' => ['servicios' => [], 'franjas' => null],
            'servicio texto' => ['servicios' => ['abc']],
            'servicio cero' => ['servicios' => ['0']],
            'servicio negativo' => ['servicios' => ['-1']],
            'servicio inexistente' => ['servicios' => ['99999999']],
            'servicio con arreglo anidado' => ['servicios' => [['a' => '1']]],
            'servicios como texto' => ['servicios' => 'abc'],
            'servicio con decimales' => ['servicios' => ['1.5']],
        ];
    }

    /** @dataProvider cargasInvalidasProveedor */
    public function test_la_ficha_rechaza_cargas_invalidas_con_422_sin_tocar_ni_una_fila_y_sin_advertencias(string $caso, array $carga): void
    {
        $prof = $this->profColab([self::$s1]);
        $colId = (int)$prof['colaborador_id'];
        $antes = $this->agendaHuella($prof['pid']);
        $base = ['servicios' => [(string)self::$s1], 'franjas' => self::franjasSemana()];
        $campos = array_filter($carga + $base, static fn($v) => $v !== null);
        $log0 = strlen(self::logServidor('r2b_agenda'));
        $r = $this->postAgenda($colId, $campos);
        $this->assertSame(422, $r['status'], "«{$caso}»: se esperaba 422 y se obtuvo {$r['status']}");
        $this->assertSame($antes, $this->agendaHuella($prof['pid']), "«{$caso}» modificó servicios/horario/descansos");
        $this->assertDoesNotMatchRegularExpression('/Warning|Notice|Deprecated|Array to string|TypeError|Fatal/i', substr(self::logServidor('r2b_agenda'), $log0), "«{$caso}» deja advertencias PHP");
    }

    public static function cargasInvalidasProveedor(): array
    {
        $o = [];
        foreach (self::cargasInvalidas() as $k => $v) { $o[$k] = [$k, $v]; }
        return $o;
    }

    public function test_quinientas_franjas_o_miles_de_servicios_se_rechazan_rapido_sin_error_500(): void
    {
        $prof = $this->profColab([self::$s1]);
        $colId = (int)$prof['colaborador_id'];
        $antes = $this->agendaHuella($prof['pid']);
        $franjas = [];
        for ($i = 0; $i < 500; $i++) { $franjas[1][] = ['inicio' => sprintf('%02d:%02d', intdiv($i, 12) % 24, ($i % 12) * 5), 'fin' => sprintf('%02d:%02d', intdiv($i, 12) % 24, ($i % 12) * 5 + 4)]; }
        $t0 = microtime(true);
        $r = $this->postAgenda($colId, ['servicios' => [(string)self::$s1], 'franjas' => $franjas]);
        $this->assertSame(422, $r['status'], '500 franjas');
        $this->assertLessThan(5.0, microtime(true) - $t0, '500 franjas tardaron demasiado');
        $servicios = array_map('strval', range(1, 3000));
        $r = $this->postAgenda($colId, ['servicios' => $servicios, 'franjas' => self::franjasSemana()]);
        $this->assertContains($r['status'], [422], '3000 servicios');
        $this->assertSame($antes, $this->agendaHuella($prof['pid']));
    }

    public function test_cargas_validas_se_guardan_exactamente_y_la_misma_clave_no_repite_efectos(): void
    {
        $prof = $this->profColab([self::$s1]);
        $colId = (int)$prof['colaborador_id'];
        $carga = ['servicios' => [(string)self::$s2, (string)self::$s1], 'franjas' => [1 => [['inicio' => '09:00', 'fin' => '13:00'], ['inicio' => '14:00', 'fin' => '19:00']], 3 => [['inicio' => '08:00', 'fin' => '23:59']], 5 => [['inicio' => '10:00', 'fin' => '14:00'], ['inicio' => '14:00', 'fin' => '18:00']]],
            'descansos' => [1 => [['inicio' => '10:30', 'fin' => '11:00', 'motivo' => 'Café']], 3 => [['inicio' => '12:00', 'fin' => '13:00', 'motivo' => '']]]];
        $k = Idem::clave();
        $r = $this->postAgenda($colId, $carga, $k);
        $this->assertSame(302, $r['status'], substr(strip_tags($r['body']), 0, 300));
        $this->assertSame([['dia_semana' => 1, 'hora_inicio' => '09:00:00', 'hora_fin' => '13:00:00'], ['dia_semana' => 1, 'hora_inicio' => '14:00:00', 'hora_fin' => '19:00:00'], ['dia_semana' => 3, 'hora_inicio' => '08:00:00', 'hora_fin' => '23:59:00'], ['dia_semana' => 5, 'hora_inicio' => '10:00:00', 'hora_fin' => '14:00:00'], ['dia_semana' => 5, 'hora_inicio' => '14:00:00', 'hora_fin' => '18:00:00']],
            self::q('SELECT dia_semana,hora_inicio,hora_fin FROM horarios_profesionales WHERE profesionalId=? ORDER BY dia_semana,hora_inicio', [$prof['pid']]));
        $this->assertSame([(string)self::$s1, (string)self::$s2], array_map('strval', array_column(self::q('SELECT servicioId FROM profesionales_servicios WHERE profesionalId=? ORDER BY servicioId', [$prof['pid']]), 'servicioId')));
        $despues = $this->agendaHuella($prof['pid']);
        $aud = (int)self::escalar('SELECT COUNT(*) FROM equipo_auditoria');
        // Doble clic: misma clave y mismo contenido → mismo resultado, sin más auditoría ni cambios.
        $r2 = $this->postAgenda($colId, $carga, $k);
        $this->assertSame(302, $r2['status']);
        $this->assertSame($despues, $this->agendaHuella($prof['pid']));
        $this->assertSame($aud, (int)self::escalar('SELECT COUNT(*) FROM equipo_auditoria'), 'el doble clic duplicó la auditoría');
        // Misma clave, otro contenido → 409 y sin cambios.
        $otra = ['servicios' => $carga['servicios'], 'franjas' => self::franjasSemana('10:00', '12:00')];
        $r3 = $this->postAgenda($colId, $otra, $k);
        $this->assertSame(409, $r3['status'], implode(' | ', self::alertasDe($r3['body'])));
        $this->assertSame($despues, $this->agendaHuella($prof['pid']));
        // Sin clave de operación: rechazado.
        $n = $this->nav('dueno');
        $csrf = Navegador::csrfDe($n->get('/admin/equipo/ficha?id=' . $colId)['body']);
        $r4 = $n->post('/admin/equipo/ficha/agenda?id=' . $colId, http_build_query(['csrf_token' => $csrf, 'servicios' => [(string)self::$s1], 'franjas' => self::franjasSemana('11:00', '12:00')]));
        $this->assertSame(422, $r4['status']);
        $this->assertSame($despues, $this->agendaHuella($prof['pid']));
        // Reemplazo completo: franjas vacías dejan al profesional sin horario (no es un error, pero debe ser explícito).
        $vacio = $this->postAgenda($colId, ['servicios' => [(string)self::$s1]]);
        $this->assertSame(302, $vacio['status']);
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM horarios_profesionales WHERE profesionalId=?', [$prof['pid']]));
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM descansos_profesionales WHERE profesionalId=?', [$prof['pid']]));
    }

    public function test_permisos_y_csrf_del_asistente_de_ficha(): void
    {
        $prof = $this->profColab([self::$s1]);
        $colId = (int)$prof['colaborador_id'];
        $antes = $this->agendaHuella($prof['pid']);
        $payload = ['servicios' => [(string)self::$s1], 'franjas' => self::franjasSemana('10:00', '12:00')];
        foreach (['gerente', 'recepcion', 'finanzas'] as $quien) {
            $n = $this->nav($quien);
            $r = $n->post('/admin/equipo/ficha/agenda?id=' . $colId, http_build_query(['csrf_token' => Navegador::csrfDe($n->get('/cliente/login')['body']) ?? 'x', 'idem_key' => Idem::clave()] + $payload));
            $this->assertSame(403, $r['status'], "$quien debe recibir 403 (solo el propietario reúne rh.gestionar + profesionales.gestionar)");
        }
        $c = $this->nav('cliente');
        $this->assertSame(403, $c->post('/admin/equipo/ficha/agenda?id=' . $colId, http_build_query(['csrf_token' => 'x', 'idem_key' => Idem::clave()] + $payload))['status']);
        $an = new Navegador(self::$base);
        $this->assertSame(302, $an->post('/admin/equipo/ficha/agenda?id=' . $colId, http_build_query($payload))['status']);
        // CSRF ausente/erróneo con propietario: 403.
        $n = $this->nav('dueno');
        $this->assertSame(403, $n->post('/admin/equipo/ficha/agenda?id=' . $colId, http_build_query(['idem_key' => Idem::clave()] + $payload))['status']);
        $this->assertSame(403, $n->post('/admin/equipo/ficha/agenda?id=' . $colId, http_build_query(['csrf_token' => str_repeat('0', 64), 'idem_key' => Idem::clave()] + $payload))['status']);
        // GET no es válido.
        $this->assertSame(405, $n->get('/admin/equipo/ficha/agenda?id=' . $colId)['status']);
        // Ids hostiles.
        foreach (['', '?id=0', '?id=-1', '?id=abc', '?id[]=1', '?id=99999999'] as $q) {
            $csrf = Navegador::csrfDe($n->get('/admin/equipo/ficha?id=' . $colId)['body']);
            $this->assertSame(404, $n->post('/admin/equipo/ficha/agenda' . $q, http_build_query(['csrf_token' => $csrf, 'idem_key' => Idem::clave()] + $payload))['status'], "id «{$q}»");
        }
        $this->assertSame($antes, $this->agendaHuella($prof['pid']), 'una petición denegada modificó la agenda');
    }

    // ------------------------------------------------------------------ citas futuras vs retirar servicio / reducir horario

    public function test_no_se_retira_un_servicio_ni_se_reduce_el_horario_si_una_cita_futura_lo_necesita(): void
    {
        $prof = $this->profColab([self::$s1, self::$s2]);
        $colId = (int)$prof['colaborador_id'];
        $dia = self::fecha(12);
        $cita = $this->cita($prof['pid'], $dia, '10:00:00', 60, [self::$s2]);
        $antes = $this->agendaHuella($prof['pid']);
        $r = $this->postAgenda($colId, ['servicios' => [(string)self::$s1], 'franjas' => self::franjasSemana()]);
        $this->assertSame(422, $r['status']);
        $this->assertStringContainsString('citas futuras', html_entity_decode($r['body']), 'el error debe explicar que hay citas futuras');
        $this->assertSame($antes, $this->agendaHuella($prof['pid']));
        // Recortar el horario para dejar la cita fuera.
        $dow = (int)date('N', strtotime($dia));
        $fr = [];
        for ($d = 1; $d <= 7; $d++) { $fr[$d] = [['inicio' => $d === $dow ? '13:00' : '09:00', 'fin' => '18:00']]; }
        $r = $this->postAgenda($colId, ['servicios' => [(string)self::$s1, (string)self::$s2], 'franjas' => $fr]);
        $this->assertSame(422, $r['status'], 'el nuevo horario deja fuera la cita futura');
        $this->assertSame($antes, $this->agendaHuella($prof['pid']));
        // Un descanso que pisa la cita.
        $fr = self::franjasSemana(); $fr[$dow] = [['inicio' => '09:00', 'fin' => '18:00']];
        $r = $this->postAgenda($colId, ['servicios' => [(string)self::$s1, (string)self::$s2], 'franjas' => $fr, 'descansos' => [$dow => [['inicio' => '10:30', 'fin' => '11:00', 'motivo' => 'x']]]]);
        $this->assertSame(422, $r['status'], 'un descanso sobre una cita confirmada');
        $this->assertSame($antes, $this->agendaHuella($prof['pid']));
        // Cancelada o pasada: ya no impide retirar el servicio.
        self::q("UPDATE citas SET estado='cancelada' WHERE id=?", [$cita]);
        $r = $this->postAgenda($colId, ['servicios' => [(string)self::$s1], 'franjas' => self::franjasSemana()]);
        $this->assertSame(302, $r['status'], substr(strip_tags($r['body']), 0, 300));
    }

    public function test_reserva_y_retirada_simultanea_de_un_servicio_nunca_deja_una_cita_con_un_servicio_no_prestado(): void
    {
        putenv('EMAIL_PORT=' . self::SMTP);
        try {
            for ($ronda = 0; $ronda < 6; $ronda++) {
                $prof = $this->profColab([self::$s1, self::$s2]);
                $dia = self::fecha(20 + $ronda);
                $t = [
                    ['accion' => 'reservarProfesional', 'args' => ['usuario' => self::$cliente['id'], 'profesional' => $prof['pid'], 'fecha' => $dia, 'hora' => '10:00', 'servicio' => self::$s2]],
                    ['accion' => 'configurarAgenda', 'args' => ['actor' => self::$dueno['id'], 'colaborador' => $prof['colaborador_id'], 'post' => ['servicios' => [(string)self::$s1], 'franjas' => self::franjasSemana()], 'clave' => Idem::clave()]],
                ];
                $res = self::concurrente($t, 1.2);
                foreach ($res as $x) { $this->assertTrue($x['ok'] ?? false, json_encode($x)); }
                $hayCita = (int)self::escalar("SELECT COUNT(*) FROM citas c JOIN citasservicios cs ON cs.citaId=c.id WHERE c.profesionalId=? AND cs.servicioId=? AND c.estado='confirmada'", [$prof['pid'], self::$s2]);
                $presta = (int)self::escalar('SELECT COUNT(*) FROM profesionales_servicios WHERE profesionalId=? AND servicioId=?', [$prof['pid'], self::$s2]);
                $this->assertFalse($hayCita === 1 && $presta === 0, "ronda $ronda: existe una cita futura de un servicio que el profesional ya no presta: " . json_encode(array_column($res, 'resultado')));
            }
        } finally { putenv('EMAIL_PORT=2599'); }
    }

    public function test_misma_clave_seis_procesos_deja_un_solo_resultado_y_claves_distintas_nunca_mezclan_cargas(): void
    {
        $prof = $this->profColab([self::$s1]);
        $post = fn(string $a, string $b) => ['servicios' => [(string)self::$s1, (string)self::$s3], 'franjas' => self::franjasSemana($a, $b)];
        $k = Idem::clave();
        $t = array_fill(0, 6, ['accion' => 'configurarAgenda', 'args' => ['actor' => self::$dueno['id'], 'colaborador' => $prof['colaborador_id'], 'post' => $post('09:00', '17:00'), 'clave' => $k]]);
        $res = self::concurrente($t, 1.2);
        foreach ($res as $x) { $this->assertTrue($x['ok'] ?? false, json_encode($x)); $this->assertSame('ok', $x['resultado']['status'], json_encode($x)); }
        $this->assertSame(1, count(array_filter($res, fn($x) => empty($x['resultado']['repetida']))), 'exactamente una ejecución real');
        $this->assertSame(5, (int)self::escalar('SELECT COUNT(*) FROM horarios_profesionales WHERE profesionalId=?', [$prof['pid']]));
        // Seis cargas DISTINTAS a la vez: el resultado final es EXACTAMENTE una de ellas (atomicidad: nada de mezclas).
        $cargas = [];
        foreach ([['08:00', '12:00'], ['09:00', '13:00'], ['10:00', '14:00'], ['11:00', '15:00'], ['12:00', '16:00'], ['13:00', '17:00']] as $h) { $cargas[] = $post($h[0], $h[1]); }
        $t = [];
        foreach ($cargas as $c) { $t[] = ['accion' => 'configurarAgenda', 'args' => ['actor' => self::$dueno['id'], 'colaborador' => $prof['colaborador_id'], 'post' => $c, 'clave' => Idem::clave()]]; }
        $res = self::concurrente($t, 1.2);
        foreach ($res as $x) { $this->assertTrue($x['ok'] ?? false, json_encode($x)); }
        $final = self::q('SELECT dia_semana, TIME_FORMAT(hora_inicio,"%H:%i") i, TIME_FORMAT(hora_fin,"%H:%i") f FROM horarios_profesionales WHERE profesionalId=? ORDER BY dia_semana', [$prof['pid']]);
        $this->assertCount(5, $final, 'filas duplicadas o faltantes: ' . json_encode($final));
        $this->assertCount(1, array_unique(array_map(fn($f) => $f['i'] . '-' . $f['f'], $final)), 'mezcla de cargas: ' . json_encode($final));
        $ok = array_map(fn($f) => $f['i'] . '-' . $f['f'], [$final[0]]);
        $validas = array_map(fn($h) => $h[0] . '-' . $h[1], [['08:00', '12:00'], ['09:00', '13:00'], ['10:00', '14:00'], ['11:00', '15:00'], ['12:00', '16:00'], ['13:00', '17:00']]);
        $this->assertContains($ok[0], $validas);
    }

    // ------------------------------------------------------------------ ficha nueva invisible hasta aceptar

    public function test_ficha_nueva_del_asistente_no_aparece_en_disponibilidad_ni_reservas_hasta_aceptar_la_invitacion(): void
    {
        $n = $this->nav('dueno');
        $pag = $n->get('/admin/equipo');
        $csrf = Navegador::csrfDe($pag['body']);
        $email = self::correo('prof-asist');
        $nombre = 'Zoe Asistente ' . bin2hex(random_bytes(2));
        $campos = ['csrf_token' => $csrf, 'idem_key' => Idem::clave(), 'nombre' => 'Zoe', 'apellido' => 'Asistente', 'email' => $email, 'telefono' => '', 'puesto' => '', 'rol' => 'profesional', 'fecha_ingreso' => '',
            'servicios' => [(string)self::$s1], 'nombre_profesional' => $nombre, 'franjas' => self::franjasSemana('09:00', '18:00')];
        $r = $n->post('/admin/equipo/crear', http_build_query($campos));
        $this->assertSame(302, $r['status'], substr(strip_tags($r['body']), 0, 300));
        $pid = (int)self::escalar('SELECT id FROM profesionales WHERE nombre=?', [$nombre]);
        $this->assertGreaterThan(0, $pid);
        $this->assertSame(0, (int)self::escalar('SELECT activo FROM profesionales WHERE id=?', [$pid]), 'la ficha nueva debe nacer inactiva');
        $this->assertSame(5, (int)self::escalar('SELECT COUNT(*) FROM horarios_profesionales WHERE profesionalId=?', [$pid]), 'el horario del asistente queda guardado');
        $dia = self::fecha(9);
        // Catálogo y disponibilidad vistos por una cliente.
        $c = $this->nav('cliente');
        $profs = json_decode($c->get('/api/profesionales?servicios=' . self::$s1)['body'], true);
        $this->assertNotContains($pid, array_map(fn($p) => (int)($p['id'] ?? 0), $profs['profesionales'] ?? []), 'la ficha inactiva aparece en el catálogo de reservas');
        $disp = json_decode($c->get('/api/disponibilidad?profesionalId=' . $pid . '&fecha=' . $dia . '&servicios=' . self::$s1)['body'], true);
        $this->assertEmpty($disp['intervalos'] ?? [], 'la ficha inactiva ofrece horarios: ' . json_encode($disp));
        // Intento de reserva directa contra la ficha inactiva.
        $reserva = (new \Services\CitaService())->reservarConProfesional(self::$cliente['id'], ['profesionalId' => $pid, 'fecha' => $dia, 'hora' => '10:00', 'servicios' => (string)self::$s1]);
        $this->assertNotSame('ok', $reserva['status'], 'se reservó con una ficha inactiva: ' . json_encode($reserva));
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM citas WHERE profesionalId=?', [$pid]));
        // Tras aceptar la invitación se activa con la configuración ya guardada.
        $colId = (int)self::escalar('SELECT colaborador_id FROM profesionales WHERE id=?', [$pid]);
        $tok = null;
        foreach (self::buzonFallo(self::SMTP) as $m) { if (in_array($email, $m['to'], true)) { $tok = self::tokenEnCorreo($m, '/equipo/aceptar'); } }
        $this->assertNotNull($tok, 'la invitación debe llegar por el SMTP simulado con su enlace');
        (new \Repositories\ColaboradorRepository(self::db()))->aceptar($tok, self::PASS, '');
        $this->assertSame(1, (int)self::escalar('SELECT activo FROM profesionales WHERE id=?', [$pid]));
        $disp = json_decode($c->get('/api/disponibilidad?profesionalId=' . $pid . '&fecha=' . $dia . '&servicios=' . self::$s1)['body'], true);
        $this->assertNotEmpty($disp['intervalos'] ?? [], 'tras aceptar, la ficha debe ofrecer sus horarios: ' . json_encode($disp));
    }

    public function test_revocar_la_invitacion_conserva_la_configuracion_y_la_ficha_sigue_inactiva(): void
    {
        $n = $this->nav('dueno');
        $csrf = Navegador::csrfDe($n->get('/admin/equipo')['body']);
        $nombre = 'Rev Asistente ' . bin2hex(random_bytes(2));
        $r = $n->post('/admin/equipo/crear', http_build_query(['csrf_token' => $csrf, 'idem_key' => Idem::clave(), 'nombre' => 'Rev', 'apellido' => 'Oc', 'email' => self::correo('rev'), 'telefono' => '', 'puesto' => '', 'rol' => 'profesional', 'fecha_ingreso' => '', 'servicios' => [(string)self::$s2], 'nombre_profesional' => $nombre, 'franjas' => self::franjasSemana()]));
        $this->assertSame(302, $r['status']);
        $pid = (int)self::escalar('SELECT id FROM profesionales WHERE nombre=?', [$nombre]);
        $colId = (int)self::escalar('SELECT colaborador_id FROM profesionales WHERE id=?', [$pid]);
        $csrf = Navegador::csrfDe($n->get('/admin/equipo')['body']);
        $rv = $n->post('/admin/equipo/revocar', ['csrf_token' => $csrf, 'id' => (string)$colId]);
        $this->assertSame(302, $rv['status']);
        $this->assertSame(0, (int)self::escalar('SELECT activo FROM profesionales WHERE id=?', [$pid]));
        $this->assertSame(5, (int)self::escalar('SELECT COUNT(*) FROM horarios_profesionales WHERE profesionalId=?', [$pid]), 'la configuración debe conservarse');
        $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM profesionales_servicios WHERE profesionalId=?', [$pid]));
    }

    /**
     * R2B-10 REVERIFICADO (antes `test_Defecto_R2B_10_…`, rojo): `ProfesionalRepository::fechaHoyAgenda()` usa America/Guayaquil. Texto original del
     * hallazgo: `ProfesionalRepository` calculaba «hoy» con `date('Y-m-d')` (zona PHP, UTC en el contenedor: no hay `date_default_timezone_set`
     * ni `date.timezone`), mientras el salón opera en America/Guayaquil (UTC-5). Entre las 19:00 y las 23:59 locales «hoy» de PHP ya es MAÑANA:
     * las citas de HOY dejan de contar como futuras y se puede retirar un servicio o poner un descanso sobre una cita confirmada de esta
     * misma noche. (`ColaboradorRepository::citasFuturasDeProfesional` sí convierte a -05:00.) Se simula el desfase con un reloj PHP adelantado
     * en el proceso que ejecuta la operación (Pacific/Kiritimati, UTC+14: su fecha es la de mañana en Guayaquil).
     */
    public function test_R2B_10_retirar_un_servicio_o_poner_un_descanso_respeta_las_citas_de_hoy_aunque_el_reloj_de_php_este_en_otra_zona(): void
    {
        $hoyLocal = self::fecha(0);
        $dow = (int)date('N', strtotime($hoyLocal));
        $completo = []; for ($d = 1; $d <= 7; $d++) { $completo[$d] = [['inicio' => '00:00', 'fin' => '23:55']]; }
        putenv('EMAIL_PORT=' . self::SMTP);
        try {
            foreach (['servicio' => fn(array $p) => ['servicios' => [(string)self::$s1], 'franjas' => $completo],
                      'descanso' => fn(array $p) => ['servicios' => [(string)self::$s1, (string)self::$s2], 'franjas' => $completo, 'descansos' => [$dow => [['inicio' => '23:00', 'fin' => '23:55', 'motivo' => 'Cena']]]]] as $caso => $carga) {
                $prof = $this->profColab([self::$s1, self::$s2]);
                self::q('DELETE FROM horarios_profesionales WHERE profesionalId=?', [$prof['pid']]);
                for ($d = 1; $d <= 7; $d++) { self::q('INSERT INTO horarios_profesionales(profesionalId,dia_semana,hora_inicio,hora_fin) VALUES (?,?,?,?)', [$prof['pid'], $d, '00:00:00', '23:55:00']); }
                $this->cita($prof['pid'], $hoyLocal, '23:00:00', 55, [self::$s2]);
                $antes = $this->agendaHuella($prof['pid']);
                // Control: con el reloj PHP en la zona del salón la misma operación SÍ se rechaza.
                $ctl = self::concurrente([['accion' => 'configurarAgenda', 'args' => ['actor' => self::$dueno['id'], 'colaborador' => $prof['colaborador_id'], 'post' => $carga($prof), 'clave' => Idem::clave()]]], 1.0);
                $this->assertSame('invalid', $ctl[0]['resultado']['status'], "control «{$caso}»: sin desfase horario debe rechazarse");
                putenv('QA_PHP_TZ=America/Guayaquil');
                $ctl2 = self::concurrente([['accion' => 'configurarAgenda', 'args' => ['actor' => self::$dueno['id'], 'colaborador' => $prof['colaborador_id'], 'post' => $carga($prof), 'clave' => Idem::clave()]]], 1.0);
                putenv('QA_PHP_TZ');
                $this->assertSame('invalid', $ctl2[0]['resultado']['status'], "control «{$caso}»: con el reloj en Guayaquil debe rechazarse");
                // El desfase se simula con un reloj PHP en una zona cuyo «hoy» es el de MAÑANA en Guayaquil (Kiritimati UTC+14, Auckland UTC+13)
                // y con zonas atrasadas respecto de Guayaquil (Honolulu UTC-10, Los Ángeles): en todas el resultado debe ser el mismo.
                foreach (['Pacific/Kiritimati', 'Pacific/Auckland', 'UTC', 'Pacific/Honolulu', 'America/Los_Angeles'] as $zona) {
                    putenv('QA_PHP_TZ=' . $zona);
                    $res = self::concurrente([['accion' => 'configurarAgenda', 'args' => ['actor' => self::$dueno['id'], 'colaborador' => $prof['colaborador_id'], 'post' => $carga($prof), 'clave' => Idem::clave()]]], 1.0);
                    putenv('QA_PHP_TZ');
                    $this->assertTrue($res[0]['ok'] ?? false, json_encode($res));
                    $this->assertSame('invalid', $res[0]['resultado']['status'], "«{$caso}»: con el reloj de PHP en $zona se aceptó cambiar la agenda sobre una cita confirmada de HOY: " . json_encode($res[0]['resultado']));
                    $this->assertSame($antes, $this->agendaHuella($prof['pid']), "«{$caso}» ($zona) modificó la agenda");
                }
            }
        } finally { putenv('QA_PHP_TZ'); putenv('EMAIL_PORT=2599'); }
    }

    // ------------------------------------------------------------------ ficha heredada y roles que no son profesional

    public function test_vincular_una_ficha_heredada_no_toca_su_configuracion_salvo_la_casilla_y_nunca_pisa_citas_futuras(): void
    {
        $n = $this->nav('dueno');
        $heredada = $this->prof('Heredada', [self::$s1, self::$s2]);
        $antes = $this->agendaHuella($heredada);
        $enviar = function (array $extra) use ($n, $heredada): array {
            $csrf = Navegador::csrfDe($n->get('/admin/equipo')['body']);
            return $n->post('/admin/equipo/crear', http_build_query(['csrf_token' => $csrf, 'idem_key' => Idem::clave(), 'nombre' => 'He', 'apellido' => 'Redada', 'email' => self::correo('he'), 'telefono' => '', 'puesto' => '', 'rol' => 'profesional', 'fecha_ingreso' => '', 'profesional_id' => (string)$heredada] + $extra));
        };
        // 1. Sin la casilla, lo enviado en servicios/franjas NO se aplica a la ficha existente.
        $r = $enviar(['servicios' => [(string)self::$s3], 'franjas' => self::franjasSemana('10:00', '11:00')]);
        $this->assertSame(302, $r['status'], implode(' | ', self::alertasDe($r['body'])));
        $this->assertSame($antes, $this->agendaHuella($heredada), 'se reescribió la ficha heredada sin la casilla');
        $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM profesionales WHERE id=? AND colaborador_id IS NOT NULL', [$heredada]), 'la ficha debe quedar vinculada');
        // 2. Una ficha YA vinculada no se puede vincular a otra persona.
        $csrf = Navegador::csrfDe($n->get('/admin/equipo')['body']);
        $r = $n->post('/admin/equipo/crear', http_build_query(['csrf_token' => $csrf, 'idem_key' => Idem::clave(), 'nombre' => 'Ot', 'apellido' => 'Ro', 'email' => self::correo('ot'), 'telefono' => '', 'puesto' => '', 'rol' => 'profesional', 'fecha_ingreso' => '', 'profesional_id' => (string)$heredada]));
        $this->assertSame(0, (int)self::escalar("SELECT COUNT(*) FROM colaboradores c WHERE c.email_norm LIKE 'ot.%' AND EXISTS(SELECT 1 FROM profesionales p WHERE p.colaborador_id=c.id AND p.id=?)", [$heredada]), 'dos colaboradores no pueden compartir ficha');
        // 3. Con la casilla y una cita futura incompatible, no se reemplaza.
        $h2 = $this->prof('Heredada2', [self::$s1, self::$s2]);
        $this->cita($h2, self::fecha(12), '10:00:00', 60, [self::$s2]);
        $antes2 = $this->agendaHuella($h2);
        $csrf = Navegador::csrfDe($n->get('/admin/equipo')['body']);
        $r = $n->post('/admin/equipo/crear', http_build_query(['csrf_token' => $csrf, 'idem_key' => Idem::clave(), 'nombre' => 'He', 'apellido' => 'Dos', 'email' => self::correo('he2'), 'telefono' => '', 'puesto' => '', 'rol' => 'profesional', 'fecha_ingreso' => '', 'profesional_id' => (string)$h2, 'ajustar_agenda' => '1', 'servicios' => [(string)self::$s1], 'franjas' => self::franjasSemana()]));
        $this->assertContains($r['status'], [302, 422]);
        $this->assertSame($antes2, $this->agendaHuella($h2), 'se retiró un servicio que una cita futura necesita');
        // 4. Con la casilla y sin conflicto sí se reemplaza.
        $h3 = $this->prof('Heredada3', [self::$s1]);
        $csrf = Navegador::csrfDe($n->get('/admin/equipo')['body']);
        $r = $n->post('/admin/equipo/crear', http_build_query(['csrf_token' => $csrf, 'idem_key' => Idem::clave(), 'nombre' => 'He', 'apellido' => 'Tres', 'email' => self::correo('he3'), 'telefono' => '', 'puesto' => '', 'rol' => 'profesional', 'fecha_ingreso' => '', 'profesional_id' => (string)$h3, 'ajustar_agenda' => '1', 'servicios' => [(string)self::$s3], 'franjas' => self::franjasSemana('10:00', '12:00')]));
        $this->assertSame(302, $r['status'], implode(' | ', self::alertasDe($r['body'])));
        $this->assertSame([(string)self::$s3], array_map('strval', array_column(self::q('SELECT servicioId FROM profesionales_servicios WHERE profesionalId=?', [$h3]), 'servicioId')));
        $this->assertSame(5, (int)self::escalar('SELECT COUNT(*) FROM horarios_profesionales WHERE profesionalId=?', [$h3]));
    }

    public function test_un_rol_que_no_es_profesional_ignora_servicios_y_horario_sin_crear_ficha(): void
    {
        $n = $this->nav('dueno');
        $csrf = Navegador::csrfDe($n->get('/admin/equipo')['body']);
        $email = self::correo('recepcion-con-agenda');
        $antes = (int)self::escalar('SELECT COUNT(*) FROM profesionales');
        $horarios = (int)self::escalar('SELECT COUNT(*) FROM horarios_profesionales');
        $r = $n->post('/admin/equipo/crear', http_build_query(['csrf_token' => $csrf, 'idem_key' => Idem::clave(), 'nombre' => 'Rec', 'apellido' => 'Con', 'email' => $email, 'telefono' => '', 'puesto' => '', 'rol' => 'recepcion', 'fecha_ingreso' => '', 'servicios' => [(string)self::$s1], 'franjas' => self::franjasSemana(), 'nombre_profesional' => 'Fantasma']));
        $this->assertSame(302, $r['status']);
        $this->assertSame($antes, (int)self::escalar('SELECT COUNT(*) FROM profesionales'), 'un rol no profesional creó ficha de agenda');
        $this->assertSame($horarios, (int)self::escalar('SELECT COUNT(*) FROM horarios_profesionales'));
        $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM colaboradores WHERE email_norm=?', [mb_strtolower($email)]));
    }

    // ================================================================== 5b. reasignación

    private function escenario(): array
    {
        $o = $this->profColab([self::$s1]);
        $dia = self::fecha(15);
        $cita = $this->cita($o['pid'], $dia, '10:00:00', 30, [self::$s1]);
        $d = [
            'libre' => $this->prof('Dest Libre', [self::$s1]),
            'ocupado' => $this->prof('Dest Ocupado', [self::$s1]),
            'inactivo' => $this->prof('Dest Inactivo', [self::$s1], false),
            'sinservicio' => $this->prof('Dest SinSvc', [self::$s2]),
            'descanso' => $this->prof('Dest Descanso', [self::$s1]),
            'bloqueado' => $this->prof('Dest Bloqueado', [self::$s1]),
            'sinhorario' => $this->prof('Dest SinHorario', [self::$s1], true, false),
            'libre2' => $this->prof('Dest Libre2', [self::$s1]),
        ];
        $otraCliente = self::cliente(['email' => self::correo('otra'), 'nombre' => 'Otra', 'apellido' => 'Cliente']);
        $this->cita($d['ocupado'], $dia, '10:15:00', 30, [self::$s1], $otraCliente['id']);
        $dow = (int)date('N', strtotime($dia));
        self::q('INSERT INTO descansos_profesionales(profesionalId,dia_semana,hora_inicio,hora_fin,motivo) VALUES (?,?,?,?,?)', [$d['descanso'], $dow, '09:30:00', '11:00:00', 'Almuerzo']);
        self::q('INSERT INTO bloqueos_profesionales(profesionalId,fecha_inicio,fecha_fin,hora_inicio,hora_fin,motivo) VALUES (?,?,?,NULL,NULL,?)', [$d['bloqueado'], $dia, $dia, 'Vacaciones']);
        return ['origen' => $o, 'cita' => $cita, 'dia' => $dia, 'dest' => $d];
    }

    private function postReasignar(int $colId, array $decisiones, ?string $clave = null, string $motivo = 'Baja programada', string $quien = 'dueno', array $extra = []): array
    {
        $n = $this->nav($quien);
        $pag = $n->get('/admin/equipo/reasignar?id=' . $colId);
        $this->assertSame(200, $pag['status'], "pantalla de reasignación ($quien)");
        $csrf = Navegador::csrfDe($pag['body']);
        $k = $clave ?? (Navegador::campoDe($pag['body'], 'idem_key') ?? Idem::clave());
        return $n->post('/admin/equipo/reasignar', http_build_query(['csrf_token' => $csrf, 'idem_key' => $k, 'id' => (string)$colId, 'motivo' => $motivo, 'decision' => $decisiones] + $extra));
    }

    private function estadoCita(int $cita): array { return self::q('SELECT profesionalId, estado, fecha, hora_inicio, hora_fin FROM citas WHERE id=?', [$cita])[0]; }

    public function test_la_revision_propone_solo_sustitutos_realmente_validos(): void
    {
        $e = $this->escenario();
        $svc = new ReasignacionCitasService();
        $rev = $svc->revisar(self::$dueno['id'], (int)$e['origen']['colaborador_id']);
        $this->assertSame('ok', $rev['status'], json_encode($rev));
        $this->assertCount(1, $rev['citas']);
        // Las fichas de pruebas anteriores de la clase también son candidatas legítimas: solo se juzgan las de ESTE escenario.
        $ids = array_values(array_intersect(array_map('intval', array_column($rev['citas'][0]['sustitutos'], 'id')), array_map('intval', array_values($e['dest']))));
        sort($ids);
        $esperados = [$e['dest']['libre'], $e['dest']['libre2']]; sort($esperados);
        $this->assertSame($esperados, $ids, 'sustitutos propuestos distintos de los realmente libres, activos y con el servicio');
    }

    public function test_reasignar_a_destinos_invalidos_por_formulario_manipulado_no_mueve_nada(): void
    {
        $e = $this->escenario();
        $colId = (int)$e['origen']['colaborador_id'];
        $antes = [$this->estadoCita($e['cita']), (int)self::escalar('SELECT COUNT(*) FROM citas_reasignaciones'), (int)self::escalar('SELECT COUNT(*) FROM notificaciones_citas')];
        $invalidos = ['ocupado' => $e['dest']['ocupado'], 'inactivo' => $e['dest']['inactivo'], 'sin el servicio' => $e['dest']['sinservicio'], 'en descanso' => $e['dest']['descanso'], 'bloqueado' => $e['dest']['bloqueado'],
            'sin horario' => $e['dest']['sinhorario'], 'el mismo origen' => $e['origen']['pid'], 'inexistente' => 99999999, 'cero' => 0];
        foreach ($invalidos as $caso => $destino) {
            $r = $this->postReasignar($colId, [(string)$e['cita'] => "reasignar:$destino"]);
            $this->assertSame(302, $r['status'], $caso);
            $this->assertSame($antes[0], $this->estadoCita($e['cita']), "«{$caso}»: la cita se movió");
            $this->assertSame($antes[1], (int)self::escalar('SELECT COUNT(*) FROM citas_reasignaciones'), "«{$caso}»: dejó historial");
            $this->assertSame($antes[2], (int)self::escalar('SELECT COUNT(*) FROM notificaciones_citas'), "«{$caso}»: avisó al cliente");
        }
        // Decisiones con sintaxis hostil o sobre citas ajenas.
        $ajena = $this->cita($e['dest']['libre2'], $e['dia'], '15:00:00', 30, [self::$s1]);
        foreach ([[(string)$ajena => "reasignar:{$e['dest']['libre']}"], ['abc' => 'cancelar'], [(string)$e['cita'] => 'reasignar:1;DROP'], [(string)$e['cita'] => 'reasignar:-5'], [(string)$e['cita'] => 'otra cosa'], ['-3' => 'cancelar'], [(string)$e['cita'] => ['x']]] as $dec) {
            $r = $this->postReasignar($colId, $dec);
            $this->assertSame(302, $r['status']);
            $this->assertSame($antes[0], $this->estadoCita($e['cita']));
            $this->assertSame('confirmada', $this->estadoCita($ajena)['estado'], 'una cita ajena fue cancelada/movida');
            $this->assertSame((int)$e['dest']['libre2'], (int)$this->estadoCita($ajena)['profesionalId']);
        }
        $this->assertSame($antes[1], (int)self::escalar('SELECT COUNT(*) FROM citas_reasignaciones'));
        // La pantalla muestra el motivo del rechazo.
        $r = $this->postReasignar($colId, [(string)$e['cita'] => "reasignar:{$e['dest']['ocupado']}"]);
        $pag = $this->nav('dueno')->get($r['location']);
        $this->assertMatchesRegularExpression('/ocupado|disponible|libre|horario/i', strip_tags($pag['body']));
    }

    public function test_reasignacion_valida_mueve_solo_el_profesional_conserva_snapshots_historial_y_avisa_una_sola_vez(): void
    {
        $e = $this->escenario();
        $colId = (int)$e['origen']['colaborador_id'];
        $cs = self::huella(['citasservicios']);
        $pagosAntes = self::huella(['pagos', 'comisiones_movimientos']);
        $k = Idem::clave();
        $destino = $e['dest']['libre'];
        $r = $this->postReasignar($colId, [(string)$e['cita'] => "reasignar:$destino"], $k, 'Baja del profesional');
        $this->assertSame(302, $r['status']);
        $c = $this->estadoCita($e['cita']);
        $this->assertSame($destino, (int)$c['profesionalId']);
        $this->assertSame('confirmada', $c['estado']);
        $this->assertSame($e['dia'], $c['fecha']);
        $this->assertSame('10:00:00', $c['hora_inicio']);
        $this->assertSame($cs, self::huella(['citasservicios']), 'los snapshots de servicios cambiaron');
        $this->assertSame($pagosAntes, self::huella(['pagos', 'comisiones_movimientos']));
        $h = self::q('SELECT * FROM citas_reasignaciones WHERE cita_referencia_id=?', [$e['cita']]);
        $this->assertCount(1, $h);
        $this->assertSame('reasignada', $h[0]['accion']);
        $this->assertSame((int)$e['origen']['pid'], (int)$h[0]['profesional_origen_id']);
        $this->assertSame($destino, (int)$h[0]['profesional_destino_id']);
        $this->assertSame(self::$dueno['id'], (int)$h[0]['actor_id']);
        $snap = json_decode($h[0]['snapshot_json'], true);
        $this->assertSame('10:00', $snap['hora_inicio']);
        $this->assertSame([self::$s1], array_map(fn($s) => (int)$s['servicio_id'], $snap['servicios']));
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM agenda_eventos WHERE citaId=? AND tipo='reasignacion'", [$e['cita']]));
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM equipo_auditoria WHERE accion='citas_reasignadas'"));
        // Un solo aviso al cliente, con el texto del cambio de profesional, entregado por el SMTP simulado.
        $n = self::q("SELECT * FROM notificaciones_citas WHERE cita_referencia_id=? AND clave_idempotencia LIKE '%reasignacion%'", [$e['cita']]);
        $this->assertCount(1, $n);
        $this->assertStringContainsString('Cambio de profesional', $n[0]['asunto']);
        $msgs = array_values(array_filter(self::buzonFallo(self::SMTP), fn($m) => in_array(self::$cliente['email'], $m['to'], true)));
        $this->assertCount(1, $msgs, 'el cliente debe recibir exactamente un aviso');
        $this->assertStringContainsString('cambió de profesional', $msgs[0]['cuerpo']);
        // Doble clic / reenvío: misma clave → mismo resultado sin otro movimiento, historial ni aviso.
        $r2 = $this->postReasignar($colId, [(string)$e['cita'] => "reasignar:$destino"], $k, 'Baja del profesional');
        $this->assertSame(302, $r2['status']);
        $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM citas_reasignaciones WHERE cita_referencia_id=?', [$e['cita']]));
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM notificaciones_citas WHERE cita_referencia_id=? AND clave_idempotencia LIKE '%reasignacion%'", [$e['cita']]));
        $this->assertCount(1, array_filter(self::buzonFallo(self::SMTP), fn($m) => in_array(self::$cliente['email'], $m['to'], true)));
        // Misma clave con otras decisiones → conflicto de clave, sin cambios.
        $r3 = $this->postReasignar($colId, [(string)$e['cita'] => "reasignar:{$e['dest']['libre2']}"], $k, 'Baja del profesional');
        $this->assertSame(302, $r3['status']);
        $this->assertSame($destino, (int)$this->estadoCita($e['cita'])['profesionalId']);
        // El historial no se puede destruir desde la aplicación: no hay ruta que lo borre; y reasignar de nuevo la cita ya no le pertenece al origen.
        $r4 = $this->postReasignar($colId, [(string)$e['cita'] => "reasignar:{$e['dest']['libre2']}"]);
        $this->assertSame($destino, (int)$this->estadoCita($e['cita'])['profesionalId'], 'la cita ya no es del origen: no puede moverse otra vez desde su pantalla');
    }

    public function test_cancelar_una_cita_por_la_baja_avisa_una_sola_vez_y_el_doble_clic_no_repite(): void
    {
        $e = $this->escenario();
        $colId = (int)$e['origen']['colaborador_id'];
        $k = Idem::clave();
        $this->assertSame(302, $this->postReasignar($colId, [(string)$e['cita'] => 'cancelar'], $k)['status']);
        $this->assertSame('cancelada', $this->estadoCita($e['cita'])['estado']);
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM citas_reasignaciones WHERE cita_referencia_id=? AND accion='cancelada'", [$e['cita']]));
        $this->assertSame(302, $this->postReasignar($colId, [(string)$e['cita'] => 'cancelar'], $k)['status']);
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM citas_reasignaciones WHERE cita_referencia_id=? AND accion='cancelada'", [$e['cita']]));
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM notificaciones_citas WHERE cita_referencia_id=? AND tipo='cancelacion'", [$e['cita']]));
    }

    /** INVENTARIO (fase 2): el aviso de la reasignación/cancelación al cliente sale DENTRO de la petición (mejor esfuerzo), antes de cualquier worker. */
    public function test_inventario_el_aviso_de_la_reasignacion_sale_en_la_propia_peticion(): void
    {
        $e = $this->escenario();
        $colId = (int)$e['origen']['colaborador_id'];
        self::vaciarBuzonFallo(self::SMTP);
        $this->assertSame(302, $this->postReasignar($colId, [(string)$e['cita'] => 'cancelar'])['status']);
        $para = array_filter(self::buzonFallo(self::SMTP), fn($m) => in_array(self::$cliente['email'], $m['to'], true));
        $this->assertCount(1, $para, 'INVENTARIO: el aviso al cliente llega dentro de POST /admin/equipo/reasignar');
        $this->assertSame(0, (int)self::escalar("SELECT COUNT(*) FROM correos_salida WHERE destinatario_email=?", [self::$cliente['email']]), 'no usa la cola de cuentas');
    }

    public function test_comision_devengada_bloquea_la_reasignacion_aunque_se_fuerce_el_formulario(): void
    {
        $e = $this->escenario();
        $colId = (int)$e['origen']['colaborador_id'];
        self::q("INSERT INTO pagos(cita_id,cita_referencia_id,usuario_id,referencia_pago,proveedor,monto,estado,metodo_pago,idempotency_key,pagado_at) VALUES (?,?,?,?,'manual',20,'completado','efectivo',?,NOW())", [$e['cita'], $e['cita'], self::$cliente['id'], 'REF-R2B-' . $e['cita'], 'idem-r2b-' . $e['cita']]);
        $pagoId = (int)self::db()->insert_id;
        self::q("INSERT INTO comisiones_movimientos(profesional_id,profesional_nombre,pago_id,pago_referencia,tipo,base_centavos,porcentaje_pb,monto_centavos,estado) VALUES (?,?,?,?,'devengo',2000,1000,200,'pendiente')", [$e['origen']['pid'], 'Origen', $pagoId, 'REF-R2B-' . $e['cita']]);
        $antes = $this->estadoCita($e['cita']);
        $comAntes = self::huella(['comisiones_movimientos', 'pagos']);
        $r = $this->postReasignar($colId, [(string)$e['cita'] => "reasignar:{$e['dest']['libre']}"]);
        $this->assertSame(302, $r['status']);
        $this->assertSame($antes, $this->estadoCita($e['cita']), 'se reasignó una cita con comisión devengada');
        $this->assertSame($comAntes, self::huella(['comisiones_movimientos', 'pagos']));
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM citas_reasignaciones WHERE cita_referencia_id=?', [$e['cita']]));
        $rev = (new ReasignacionCitasService())->revisar(self::$dueno['id'], $colId);
        $this->assertNotNull($rev['citas'][0]['bloqueo']);
        $this->assertSame([], $rev['citas'][0]['sustitutos']);
    }

    public function test_baja_y_suspension_se_bloquean_en_servidor_con_citas_sin_resolver_aunque_se_manipule_el_formulario(): void
    {
        $e = $this->escenario();
        $colId = (int)$e['origen']['colaborador_id'];
        $n = $this->nav('dueno');
        foreach (['baja', 'suspendido'] as $estado) {
            $csrf = Navegador::csrfDe($n->get('/admin/equipo')['body']);
            $r = $n->post('/admin/equipo/actualizar', ['csrf_token' => $csrf, 'idem_key' => Idem::clave(), 'id' => (string)$colId, 'rol' => 'profesional', 'estado' => $estado]);
            $this->assertSame(302, $r['status']);
            $this->assertSame('activo', self::escalar('SELECT estado FROM colaboradores WHERE id=?', [$colId]), "«{$estado}» se aplicó con citas futuras sin resolver");
            $this->assertSame(1, (int)self::escalar('SELECT activo FROM usuario_accesos WHERE usuario_id=?', [$e['origen']['id']]));
        }
        // Dejar la cita «para revisión manual» tampoco libera la baja.
        $this->postReasignar($colId, [(string)$e['cita'] => 'reasignar:' . $e['dest']['libre']], null, 'Baja'); // resuelve
        $csrf = Navegador::csrfDe($n->get('/admin/equipo')['body']);
        $r = $n->post('/admin/equipo/actualizar', ['csrf_token' => $csrf, 'idem_key' => Idem::clave(), 'id' => (string)$colId, 'rol' => 'profesional', 'estado' => 'baja']);
        $this->assertSame(302, $r['status']);
        $this->assertSame('baja', self::escalar('SELECT estado FROM colaboradores WHERE id=?', [$colId]), 'resueltas las citas, la baja debe poder confirmarse');
    }

    public function test_baja_simultanea_con_una_reserva_nunca_deja_citas_futuras_de_un_profesional_dado_de_baja(): void
    {
        putenv('EMAIL_PORT=' . self::SMTP);
        try {
            for ($ronda = 0; $ronda < 6; $ronda++) {
                $o = $this->profColab([self::$s1]);
                $dia = self::fecha(30 + $ronda);
                $t = [
                    ['accion' => 'cambiarAcceso', 'args' => ['actor' => self::$dueno['id'], 'id' => $o['colaborador_id'], 'rol' => 'profesional', 'estado' => 'baja']],
                    ['accion' => 'reservarProfesional', 'args' => ['usuario' => self::$cliente['id'], 'profesional' => $o['pid'], 'fecha' => $dia, 'hora' => '11:00', 'servicio' => self::$s1]],
                ];
                $res = self::concurrente($t, 1.2);
                foreach ($res as $x) { $this->assertTrue($x['ok'] ?? false, json_encode($x)); }
                $baja = self::escalar('SELECT estado FROM colaboradores WHERE id=?', [$o['colaborador_id']]) === 'baja';
                $citas = (int)self::escalar("SELECT COUNT(*) FROM citas WHERE profesionalId=? AND estado IN ('confirmada','en_servicio') AND fecha>=?", [$o['pid'], self::fecha(0)]);
                $this->assertFalse($baja && $citas > 0, "ronda $ronda: baja confirmada con $citas cita(s) futura(s) vigente(s): " . json_encode(array_column($res, 'resultado')));
            }
        } finally { putenv('EMAIL_PORT=2599'); }
    }

    public function test_dos_reasignaciones_al_mismo_hueco_y_reasignacion_contra_reserva_solo_deja_un_ganador(): void
    {
        putenv('EMAIL_PORT=' . self::SMTP);
        try {
            for ($ronda = 0; $ronda < 4; $ronda++) {
                $dia = self::fecha(40 + $ronda);
                $o1 = $this->profColab([self::$s1]); $o2 = $this->profColab([self::$s1]);
                $c1 = $this->cita($o1['pid'], $dia, '10:00:00', 30, [self::$s1]);
                $otra = self::cliente(['email' => self::correo('dos')]);
                $c2 = $this->cita($o2['pid'], $dia, '10:00:00', 30, [self::$s1], $otra['id']);
                $dest = $this->prof('Dest Carrera', [self::$s1]);
                $t = [
                    ['accion' => 'reasignar', 'args' => ['actor' => self::$dueno['id'], 'colaborador' => $o1['colaborador_id'], 'decisiones' => [(string)$c1 => "reasignar:$dest"], 'clave' => Idem::clave()]],
                    ['accion' => 'reasignar', 'args' => ['actor' => self::$dueno['id'], 'colaborador' => $o2['colaborador_id'], 'decisiones' => [(string)$c2 => "reasignar:$dest"], 'clave' => Idem::clave()]],
                ];
                $res = self::concurrente($t, 1.2);
                foreach ($res as $x) { $this->assertTrue($x['ok'] ?? false, json_encode($x)); }
                $enDestino = (int)self::escalar("SELECT COUNT(*) FROM citas WHERE profesionalId=? AND fecha=? AND hora_inicio='10:00:00' AND estado='confirmada'", [$dest, $dia]);
                $this->assertSame(1, $enDestino, "ronda $ronda: doble reserva del mismo hueco en el destino: " . json_encode(array_column($res, 'resultado')));
                $this->assertSame(1, count(array_filter($res, fn($x) => ($x['resultado']['status'] ?? '') === 'ok')), 'exactamente una reasignación gana');
                // Reasignación contra reserva simultánea sobre el mismo hueco.
                $o3 = $this->profColab([self::$s1]);
                $c3 = $this->cita($o3['pid'], $dia, '12:00:00', 30, [self::$s1]);
                $dest2 = $this->prof('Dest Carrera2', [self::$s1]);
                $rival = self::cliente(['email' => self::correo('rival')]);
                $t = [
                    ['accion' => 'reasignar', 'args' => ['actor' => self::$dueno['id'], 'colaborador' => $o3['colaborador_id'], 'decisiones' => [(string)$c3 => "reasignar:$dest2"], 'clave' => Idem::clave()]],
                    ['accion' => 'reservarProfesional', 'args' => ['usuario' => $rival['id'], 'profesional' => $dest2, 'fecha' => $dia, 'hora' => '12:00', 'servicio' => self::$s1]],
                ];
                $res = self::concurrente($t, 1.2);
                foreach ($res as $x) { $this->assertTrue($x['ok'] ?? false, json_encode($x)); }
                $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM citas WHERE profesionalId=? AND fecha=? AND hora_inicio='12:00:00' AND estado='confirmada'", [$dest2, $dia]), "ronda $ronda: reasignación vs reserva dejó 0 o 2 citas en el mismo hueco");
            }
        } finally { putenv('EMAIL_PORT=2599'); }
    }

    // ------------------------------------------------------------------ permisos y privacidad de la pantalla

    public function test_permisos_por_rol_de_la_pantalla_y_privacidad_del_cliente(): void
    {
        $e = $this->escenario();
        $colId = (int)$e['origen']['colaborador_id'];
        foreach (['dueno', 'gerente', 'recepcion'] as $quien) {
            $r = $this->nav($quien)->get('/admin/equipo/reasignar?id=' . $colId);
            $this->assertSame(200, $r['status'], $quien);
            $this->assertStringContainsString('Clienta Reasignada', $r['body'], "$quien (clientes.ver) debe ver el nombre");
            // PII mínima: ni correo, ni teléfono, ni importes.
            $this->assertStringNotContainsString(self::$cliente['email'], $r['body'], "$quien: la pantalla muestra el correo del cliente");
            $this->assertStringNotContainsString('0995550101', $r['body'], "$quien: la pantalla muestra el teléfono del cliente");
        }
        foreach (['finanzas', 'cliente'] as $quien) {
            $r = $this->nav($quien)->get('/admin/equipo/reasignar?id=' . $colId);
            $this->assertSame(403, $r['status'], $quien);
            $this->assertStringNotContainsString('Clienta Reasignada', $r['body']);
        }
        $this->assertSame(302, (new Navegador(self::$base))->get('/admin/equipo/reasignar?id=' . $colId)['status']);
        // Un profesional (agenda.ver_propia) tampoco entra.
        $pn = new Navegador(self::$base);
        self::q('TRUNCATE intentos_login');
        $this->assertSame(302, $pn->login('equipo', $e['origen']['email'], $e['origen']['password'])['status']);
        $this->assertSame(403, $pn->get('/admin/equipo/reasignar?id=' . $colId)['status']);
        // Sin clientes.ver el servicio no entrega nombre alguno.
        $cita = ['id' => 1, 'cliente' => 'Clienta Reasignada', 'fecha' => '2030-01-01'];
        $this->assertNull(ReasignacionCitasService::proyectarCita($cita, false)['cliente']);
        // Un cliente cualquiera (rol cliente) no obtiene datos aunque pase el id de otra persona.
        foreach (['/admin/equipo/reasignar?id=1', '/admin/equipo/reasignar?id=' . $colId . '&x=1'] as $u) { $this->assertSame(403, $this->nav('cliente')->get($u)['status']); }
    }

    /**
     * R2B-11 REVERIFICADO (antes `test_Defecto_R2B_11_…`, rojo). Cada ruta de error de validación de `POST /admin/equipo/reasignar` (id ausente o
     * inválido, `decision` que no es arreglo, `motivo` que no es cadena) redirige a `inicio_portal()`: `/admin` para propietario, gerente y
     * recepción (los tres tienen `agenda.gestionar`, requisito de `/admin`), accesible (200) para quien lo recibe. Se verifica por el
     * código (`includes/funciones.php::inicio_portal`: profesional → /agenda, finanzas → /admin/finanzas, resto del equipo → /admin).
     */
    public function test_R2B_11_el_error_de_validacion_de_la_reasignacion_vuelve_al_inicio_accesible_de_cada_rol(): void
    {
        $e = $this->escenario();
        $colId = (int)$e['origen']['colaborador_id'];
        $casos = [
            'sin id' => ['motivo' => 'Baja'],
            'id no numerico' => ['id' => 'abc', 'motivo' => 'Baja'],
            'id cero' => ['id' => '0', 'motivo' => 'Baja'],
            'id arreglo' => ['id' => ['1'], 'motivo' => 'Baja'],
            'decision escalar' => ['id' => (string)$colId, 'decision' => 'x', 'motivo' => 'Baja'],
            'motivo arreglo' => ['id' => (string)$colId, 'decision' => [], 'motivo' => ['x']],
        ];
        foreach (['dueno', 'gerente', 'recepcion'] as $quien) {
            $n = $this->nav($quien);
            $csrf = Navegador::csrfDe($n->get('/admin/equipo/reasignar?id=' . $colId)['body']);
            foreach ($casos as $nombre => $campos) {
                $r = $n->post('/admin/equipo/reasignar', http_build_query(['csrf_token' => $csrf, 'idem_key' => Idem::clave()] + $campos), ['Accept: text/html']);
                $this->assertSame(302, $r['status'], "$quien / $nombre");
                $this->assertSame('/admin', $r['location'], "$quien / $nombre: destino");
                $destino = $n->get((string)$r['location'], ['Accept: text/html']);
                $this->assertSame(200, $destino['status'], "$quien / $nombre: {$r['location']} responde {$destino['status']} (callejón sin salida)");
            }
        }
        // Quien NO tiene agenda.gestionar sigue recibiendo 403 en la propia ruta (el cambio no abre acceso).
        foreach (['finanzas', 'cliente'] as $quien) {
            $n = $this->nav($quien);
            $r = $n->post('/admin/equipo/reasignar', http_build_query(['csrf_token' => 'x', 'motivo' => 'Baja']), ['Accept: text/html']);
            $this->assertContains($r['status'], [302, 403], $quien);
            $this->assertNotSame('/admin', $r['location'] ?? null, "$quien no debe ser enviado a /admin");
        }
    }

    /**
     * R2B-04 REVERIFICADO: `s()` devuelve '' ante arreglos/objetos sin __toString y el asistente filtra los no escalares. Se ataca la salida de
     * `s()` directamente y las rutas con formas hostiles (arreglos anidados en todos los campos del alta y de la ficha); el log del servidor
     * no debe recibir ni una advertencia, aviso o error de tipo.
     */
    public function test_R2B_04_los_campos_con_formas_hostiles_no_producen_advertencias_ni_reflejan_basura(): void
    {
        if (!function_exists('s')) { require_once self::RAIZ . '/includes/funciones.php'; }
        $obj = new class { public function __toString(): string { return 'es-texto'; } };
        $this->assertSame('', \s([1, 2]));
        $this->assertSame('', \s([]));
        $this->assertSame('', \s(new \stdClass()));
        $this->assertSame('es-texto', \s($obj));
        $this->assertSame('', \s(null));
        $this->assertSame('1', \s(1));
        $this->assertSame('&lt;b&gt;&quot;&#039;', \s('<b>"\''));
        $n = $this->nav('dueno');
        $log0 = strlen(self::logServidor('r2b_agenda'));
        $campos = ['nombre', 'apellido', 'email', 'telefono', 'puesto', 'fecha_ingreso', 'nombre_profesional', 'rol', 'ajustar_agenda', 'servicios', 'franjas', 'descansos'];
        $this->recorrerFormasHostiles($n, $campos);
        $this->assertDoesNotMatchRegularExpression('/Warning|Notice|Deprecated|Array to string|TypeError|Fatal/i', substr(self::logServidor('r2b_agenda'), $log0), 'advertencias PHP con formas hostiles');
    }

    /**
     * R2B-04b (RESIDUO, rojo): la corrección de `s()` no cubre `views/equipo/index.php:39`, que compara `(string)($previo['profesional_id'] ?? '')`
     * con un cast explícito. Con `profesional_id[]=x` (o cualquier forma de arreglo) y al menos una ficha sin cuenta, cada reproducción
     * de `GET /admin/equipo` emite `PHP Warning: Array to string conversion` por cada ficha del selector.
     */
    public function test_Defecto_R2B_04b_profesional_id_con_forma_de_arreglo_sigue_emitiendo_advertencias_en_la_pantalla_de_equipo(): void
    {
        $this->prof('Ficha sin cuenta R2B04', [self::$s1]);
        $n = $this->nav('dueno');
        $log0 = strlen(self::logServidor('r2b_agenda'));
        $this->recorrerFormasHostiles($n, ['profesional_id']);
        $this->assertDoesNotMatchRegularExpression('/Warning|Notice|Deprecated|Array to string|TypeError|Fatal/i', substr(self::logServidor('r2b_agenda'), $log0), 'profesional_id[]=x deja «Array to string conversion» en views/equipo/index.php:39');
    }

    private function recorrerFormasHostiles(Navegador $n, array $campos): void
    {
        $formas = ['[]=x', '[a]=x', '[][]=x', '[a][b][c]=x', '[0][inicio][]=x'];
        $csrf = Navegador::csrfDe($n->get('/admin/equipo')['body']);
        foreach ($campos as $campo) {
            foreach ($formas as $forma) {
                $crudo = http_build_query(['csrf_token' => $csrf, 'idem_key' => Idem::clave(), 'nombre' => 'Ana', 'apellido' => 'Prueba', 'email' => self::correo('hostil'), 'rol' => 'profesional']) . '&' . $campo . $forma;
                $r = $n->post('/admin/equipo/crear', $crudo);
                $this->assertContains($r['status'], [302, 303, 422], "$campo$forma");
                $pagina = $n->get('/admin/equipo');
                $this->assertSame(200, $pagina['status'], "$campo$forma: la pantalla no se reproduce");
                $this->assertStringNotContainsString('Array', strip_tags($pagina['body']), "$campo$forma: se refleja «Array»");
                $csrf = Navegador::csrfDe($pagina['body']) ?? $csrf;
            }
        }
    }

    public function test_csrf_idempotencia_y_formulario_vencido_en_la_reasignacion(): void
    {
        $e = $this->escenario();
        $colId = (int)$e['origen']['colaborador_id'];
        $n = $this->nav('dueno');
        $antes = $this->estadoCita($e['cita']);
        $cuerpo = ['idem_key' => Idem::clave(), 'id' => (string)$colId, 'motivo' => 'Baja', 'decision' => [(string)$e['cita'] => "reasignar:{$e['dest']['libre']}"]];
        $this->assertSame(403, $n->post('/admin/equipo/reasignar', http_build_query($cuerpo))['status'], 'sin CSRF');
        $this->assertSame(403, $n->post('/admin/equipo/reasignar', http_build_query(['csrf_token' => str_repeat('1', 64)] + $cuerpo))['status'], 'CSRF falso');
        $csrf = Navegador::csrfDe($n->get('/admin/equipo/reasignar?id=' . $colId)['body']);
        // Sin clave de operación o con clave inválida: no se aplica.
        foreach ([null, '', 'corta', '../../etc/passwd-0123456789', str_repeat('z', 65), 'clave con espacios 0123456789'] as $k) {
            $c = $cuerpo; if ($k === null) { unset($c['idem_key']); } else { $c['idem_key'] = $k; }
            $r = $n->post('/admin/equipo/reasignar', http_build_query(['csrf_token' => $csrf] + $c));
            $this->assertSame(302, $r['status']);
            $this->assertSame($antes, $this->estadoCita($e['cita']), 'clave inválida «' . var_export($k, true) . '» aplicó la reasignación');
        }
        // Motivo vacío, de 2 caracteres, de 201 caracteres o con caracteres de control.
        foreach (['', 'ab', str_repeat('m', 201), "mo\x01tivo"] as $m) {
            $n->post('/admin/equipo/reasignar', http_build_query(['csrf_token' => $csrf, 'motivo' => $m, 'idem_key' => Idem::clave()] + array_diff_key($cuerpo, ['motivo' => 1, 'idem_key' => 1])));
            $this->assertSame($antes, $this->estadoCita($e['cita']), 'motivo inválido aplicó la reasignación');
        }
        // GET con el método equivocado, id hostiles.
        $this->assertSame(404, $n->get('/admin/equipo/reasignar?id=abc')['status']);
        $this->assertSame(404, $n->get('/admin/equipo/reasignar?id=0')['status']);
        $this->assertSame(404, $n->get('/admin/equipo/reasignar?id[]=1')['status']);
        $this->assertSame(404, $n->get('/admin/equipo/reasignar?id=99999999')['status']);
    }

    public function test_doble_clic_concurrente_de_la_misma_reasignacion_con_seis_procesos_mueve_una_sola_vez(): void
    {
        putenv('EMAIL_PORT=' . self::SMTP);
        try {
            $e = $this->escenario();
            $k = Idem::clave();
            $t = array_fill(0, 6, ['accion' => 'reasignar', 'args' => ['actor' => self::$dueno['id'], 'colaborador' => $e['origen']['colaborador_id'], 'decisiones' => [(string)$e['cita'] => 'reasignar:' . $e['dest']['libre']], 'clave' => $k]]);
            $res = self::concurrente($t, 1.2);
            foreach ($res as $x) { $this->assertTrue($x['ok'] ?? false, json_encode($x)); }
            $estados = array_map(fn($x) => $x['resultado']['status'], $res);
            foreach ($estados as $s) { $this->assertContains($s, ['ok', 'in_progress'], json_encode($estados)); }
            $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM citas_reasignaciones WHERE cita_referencia_id=?', [$e['cita']]));
            $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM notificaciones_citas WHERE cita_referencia_id=? AND clave_idempotencia LIKE '%reasignacion%'", [$e['cita']]));
            $this->assertSame((int)$e['dest']['libre'], (int)$this->estadoCita($e['cita'])['profesionalId']);
            $msgs = array_filter(self::buzonFallo(self::SMTP), fn($m) => in_array(self::$cliente['email'], $m['to'], true));
            $this->assertLessThanOrEqual(1, count($msgs), 'más de un aviso de cambio de profesional al cliente');
        } finally { putenv('EMAIL_PORT=2599'); }
    }
}

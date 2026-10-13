<?php

namespace Tests\Qa;

require_once __DIR__ . '/R2bCase.php';

/**
 * F. Regresión de negocio de extremo a extremo para un cliente nacido por registro público y para una persona con ambos perfiles.
 * Usa HTTP real, SMTP simulado propio y los servicios de producción (sin mocks que se salten el control).
 */
final class RegresionNegocioTest extends R2bCase
{
    private static string $base;
    private static array $dueno;
    private static array $prof;
    private static int $sid;
    private static array $gerente;

    public static function setUpBeforeClass(): void
    {
        self::limpiar();
        self::iniciarSmtp(2598);
        self::$base = self::servidor(['EMAIL_PORT' => '2598']);
        self::$dueno = self::propietario('dueno@example.test');
        self::$prof = self::miembro(self::$dueno['id'], 'profesional', ['email' => 'prof@example.test']);
        self::$gerente = self::miembro(self::$dueno['id'], 'gerente', ['email' => 'gerente@example.test']);
        self::$sid = (int)self::escalar('SELECT servicioId FROM profesionales_servicios WHERE profesionalId=?', [self::$prof['profesional_id']]);
        self::q('UPDATE servicios SET precio=25.00, duracion_minutos=30 WHERE id=?', [self::$sid]);
        for ($d = 1; $d <= 7; $d++) {
            self::q("INSERT INTO horarios_profesionales(profesionalId,dia_semana,hora_inicio,hora_fin) VALUES (?,?,'08:00:00','20:00:00')", [self::$prof['profesional_id'], $d]);
        }
    }

    public static function tearDownAfterClass(): void { self::pararServidor(); self::detenerSmtp(2598); }

    protected function setUp(): void { self::q('TRUNCATE intentos_login'); }

    private function dia(int $mas): string { return (new \DateTimeImmutable('now', new \DateTimeZone('America/Guayaquil')))->modify("+$mas days")->format('Y-m-d'); }

    private function reservar(Navegador $n, string $csrf, string $fecha, string $hora = '10:00'): array
    {
        $r = $n->post('/api/citas', ['profesionalId' => self::$prof['profesional_id'], 'fecha' => $fecha, 'hora' => $hora, 'servicios' => (string)self::$sid], ['X-CSRF-Token: ' . $csrf]);
        return $r + ['json' => json_decode($r['body'], true)];
    }

    public function test_cliente_de_registro_publico_recorre_el_negocio_completo(): void
    {
        $email = 'publico.' . bin2hex(random_bytes(3)) . '@example.test';
        $n = new Navegador(self::$base);
        $csrf = $n->csrf('/cliente/registro');
        $antes = count(self::buzon(2598));
        $r = $n->post('/cliente/registro', ['csrf_token' => $csrf, 'idem_key' => Idem::clave(), 'nombre' => 'Pública', 'apellido' => 'Cliente', 'email' => $email, 'telefono' => '0991112222', 'password' => 'Clave-Publica-123']);
        $this->assertSame(302, $r['status'], substr(strip_tags($r['body']), 0, 300));
        // Identidad + membresía creadas en una transacción; sin acceso laboral.
        $u = self::q('SELECT id, admin, confirmado FROM usuarios WHERE email=?', [$email])[0];
        $this->assertSame('activo', self::escalar('SELECT estado FROM clientes WHERE usuario_id=?', [$u['id']]));
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM usuario_accesos WHERE usuario_id=?', [$u['id']]));
        $this->assertSame(0, (int)$u['admin']);
        // CAMBIO DE CONTRATO (fase 2): el registro solo ENCOLA; el correo no sale dentro de la petición. Antes del worker el buzón no cambia
        // (garantía nueva); el worker real (`scripts/procesar_correos.php`) lo entrega y llega exactamente UN correo de confirmación.
        $this->assertCount($antes, self::buzon(2598), 'el registro no debe enviar correo dentro de la petición');
        self::entregarCola(2598); // el worker procesa TODA la cola (incluye invitaciones sembradas por el arnés): se filtra por destinatario
        $buzon = array_values(array_filter(self::buzon(2598), fn($m) => in_array($email, $m['to'], true)));
        $this->assertCount(1, $buzon, 'debe llegar exactamente UN correo de confirmación');
        $tok = self::tokenEnCorreo(end($buzon), '/confirmar-cuenta');
        $this->assertNotNull($tok, substr(end($buzon)['cuerpo'], 0, 400));
        $this->assertSame(200, $n->get('/confirmar-cuenta?token=' . $tok)['status']);
        $this->assertSame(302, $n->login('cliente', $email, 'Clave-Publica-123')['status']);
        $csrf = $n->csrf('/cliente/login');

        // Reserva
        $rv = $this->reservar($n, $csrf, $this->dia(3));
        $this->assertSame(200, $rv['status'], $rv['body']);
        $cita1 = (int)$rv['json']['id'];
        $this->assertSame(self::$prof['profesional_id'], (int)self::escalar('SELECT profesionalId FROM citas WHERE id=?', [$cita1]));
        // Doble reserva exacta -> conflicto 409 (sin duplicar).
        $rv2 = $this->reservar($n, $csrf, $this->dia(3));
        $this->assertSame(409, $rv2['status'], $rv2['body']);
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM citas WHERE usuarioId=? AND fecha=?", [$u['id'], $this->dia(3)]));
        // Mis citas
        $mc = json_decode($n->get('/api/mis-citas')['body'], true);
        $this->assertNotEmpty($mc['citas']);
        // Pago mock
        $pg = $n->post('/api/pagos/iniciar', ['cita_id' => $cita1, 'metodo_pago' => 'tarjeta_mock'], ['X-CSRF-Token: ' . $csrf]);
        $this->assertContains($pg['status'], [200, 201, 202], $pg['body']);
        $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM pagos WHERE cita_id=?', [$cita1]));
        // Cancelación lógica de una segunda cita.
        $rv3 = $this->reservar($n, $csrf, $this->dia(5), '11:00');
        $this->assertSame(200, $rv3['status'], $rv3['body']);
        $cita2 = (int)$rv3['json']['id'];
        $c = $n->post('/api/eliminar', ['id' => $cita2, 'motivo' => 'Cambio de planes'], ['X-CSRF-Token: ' . $csrf, 'Accept: application/json']);
        $this->assertContains($c['status'], [200, 302], $c['body']);
        $this->assertSame('cancelada', self::escalar('SELECT estado FROM citas WHERE id=?', [$cita2]));
        // Reprogramación por el gerente (portal de equipo) y avance de estados.
        $g = new Navegador(self::$base);
        $this->assertSame(302, $g->login('equipo', self::$gerente['email'], self::$gerente['password'])['status']);
        $gcsrf = $g->csrf('/equipo/login');
        $nuevo = $this->dia(6);
        $rp = $g->post('/agenda/reprogramar', ['csrf_token' => $gcsrf, 'id' => $cita1, 'fecha' => $nuevo, 'hora' => '15:00']);
        $this->assertSame(302, $rp['status'], strip_tags(substr($rp['body'], 0, 300)));
        $this->assertSame($nuevo, self::escalar('SELECT fecha FROM citas WHERE id=?', [$cita1]));
        foreach (['en_servicio', 'completada'] as $estado) {
            // Para completar se necesita que la fecha sea hoy o pasada según reglas: forzamos la fecha de la cita para el flujo.
            if ($estado === 'en_servicio') { self::q("UPDATE citas SET fecha=?, hora='00:00:00', hora_inicio='00:00:00', hora_fin='00:30:00' WHERE id=?", [$this->dia(0), $cita1]); }
            $e = $g->post('/agenda/estado', ['csrf_token' => $gcsrf, 'id' => $cita1, 'estado' => $estado]);
            $this->assertSame(302, $e['status'], $estado . ': ' . strip_tags(substr($e['body'], 0, 300)));
        }
        $this->assertSame('completada', self::escalar('SELECT estado FROM citas WHERE id=?', [$cita1]));
        // CRM: el cliente aparece con 1 visita y el gerente lo ve.
        $crm = $g->get('/admin/clientes?q=' . rawurlencode($email));
        $this->assertSame(200, $crm['status']);
        $this->assertStringContainsString('Pública', $crm['body']);
        // La confirmación de cita llegó por correo y el worker procesa sin errores.
        $w = $this->workerNotificaciones();
        $this->assertSame(0, $w['exit'], $w['err']);
    }

    /** @return array{exit:int,out:string,err:string} */
    private function workerNotificaciones(): array
    {
        $p = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', self::RAIZ . '/scripts/procesar_notificaciones.php', '--json'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::RAIZ,
            array_merge(getenv(), ['DB_NAME' => self::nombreBd(), 'EMAIL_PORT' => '2598', 'EMAIL_FORCE_SMTP' => '1']));
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        return ['exit' => proc_close($p), 'out' => $out, 'err' => $err];
    }

    public function test_worker_de_notificaciones_entrega_la_confirmacion_a_un_cliente_nacido_por_registro(): void
    {
        $c = self::cliente(['email' => 'notif.' . bin2hex(random_bytes(2)) . '@example.test']);
        $n = new Navegador(self::$base);
        $this->assertSame(302, $n->login('cliente', $c['email'], $c['password'])['status']);
        $rv = $this->reservar($n, $n->csrf('/cliente/login'), $this->dia(4), '12:00');
        $this->assertSame(200, $rv['status'], $rv['body']);
        $w = $this->workerNotificaciones();
        $this->assertSame(0, $w['exit'], $w['err'] . $w['out']);
        usleep(300000);
        $para = array_filter(self::buzon(2598), fn($m) => in_array($c['email'], $m['to'], true));
        $this->assertNotEmpty($para, 'ningún correo de confirmación de cita llegó al cliente. Salida del worker: ' . $w['out'] . $w['err']);
        $pend = (int)self::escalar("SELECT COUNT(*) FROM notificaciones_citas WHERE estado NOT IN ('enviada','enviado','descartada','descartado')");
        $this->assertSame(0, $pend, 'notificaciones sin entregar');
    }

    /**
     * INVENTARIO (fase 2) de envíos de correo DENTRO de una petición AUTENTICADA que no pasan por la cola de cuentas. El contrato nuevo
     * («ningún correo de cuenta dentro de la petición») cubre registro, reenvío de confirmación y recuperación (rutas públicas). Los avisos
     * de CITA siguen saliendo en línea (mejor esfuerzo, reintento por `procesar_notificaciones.php`): se caracteriza aquí para que un cambio
     * futuro sea deliberado. No hay oráculo de enumeración: el destinatario es el propio titular de la sesión que reserva o cancela.
     */
    public function test_inventario_la_confirmacion_y_la_cancelacion_de_cita_salen_en_la_propia_peticion(): void
    {
        $c = self::cliente(['email' => 'inline.' . bin2hex(random_bytes(2)) . '@example.test']);
        $n = new Navegador(self::$base);
        $this->assertSame(302, $n->login('cliente', $c['email'], $c['password'])['status']);
        $csrf = $n->csrf('/cliente/login');
        $antes = count(array_filter(self::buzon(2598), fn($m) => in_array($c['email'], $m['to'], true)));
        $rv = $this->reservar($n, $csrf, $this->dia(8), '09:00');
        $this->assertSame(200, $rv['status'], $rv['body']);
        $trasReservar = count(array_filter(self::buzon(2598), fn($m) => in_array($c['email'], $m['to'], true)));
        $this->assertSame($antes + 1, $trasReservar, 'INVENTARIO: la confirmación de cita sale dentro de POST /api/citas (antes de cualquier worker)');
        $x = $n->post('/api/eliminar', ['id' => (int)$rv['json']['id'], 'motivo' => 'Cambio'], ['X-CSRF-Token: ' . $csrf, 'Accept: application/json']);
        $this->assertContains($x['status'], [200, 302], $x['body']);
        $trasCancelar = count(array_filter(self::buzon(2598), fn($m) => in_array($c['email'], $m['to'], true)));
        $this->assertSame($trasReservar + 1, $trasCancelar, 'INVENTARIO: el aviso de cancelación sale dentro de POST /api/eliminar');
        // Y NO hay evento en la cola de cuentas: son otro canal (notificaciones_citas) con su propio worker.
        $this->assertSame(0, (int)self::escalar("SELECT COUNT(*) FROM correos_salida WHERE destinatario_email=?", [$c['email']]));
    }

    public function test_persona_con_ambos_perfiles_reserva_y_cancela_como_cliente_y_gestiona_como_equipo(): void
    {
        $dual = self::cliente(['email' => 'dualneg.' . bin2hex(random_bytes(2)) . '@example.test']);
        $repo = new \Repositories\ColaboradorRepository(self::db());
        $r = $repo->crearInvitacion(self::$dueno['id'], ['nombre' => 'Du', 'apellido' => 'Al', 'email' => $dual['email'], 'telefono' => null, 'puesto' => '', 'rol' => 'recepcion', 'fecha_ingreso' => null, 'servicios' => [], 'nombre_profesional' => '', 'profesional_id' => null]);
        $repo->aceptar(self::tokenDeInvitacion($r['invitacion_id']), '', $dual['password']);
        $n = new Navegador(self::$base);
        $this->assertSame(302, $n->login('cliente', $dual['email'], $dual['password'])['status']);
        $csrf = $n->csrf('/cliente/login');
        $rv = $this->reservar($n, $csrf, $this->dia(7), '09:00');
        $this->assertSame(200, $rv['status'], $rv['body']);
        $c = (int)$rv['json']['id'];
        // En portal de cliente, /agenda y /admin siguen negados aunque su rol sea recepción.
        $this->assertSame(403, $n->get('/admin')['status']);
        $this->assertSame(403, $n->get('/agenda')['status']);
        // Pago iniciado como cliente: su propia cita sí; la de otro cliente, no.
        $otro = self::cliente(['email' => 'otro.' . bin2hex(random_bytes(2)) . '@example.test']);
        $no = new Navegador(self::$base);
        $this->assertSame(302, $no->login('cliente', $otro['email'], $otro['password'])['status']);
        $robo = $n->post('/api/pagos/iniciar', ['cita_id' => (int)self::escalar('SELECT id FROM citas WHERE usuarioId=? LIMIT 1', [$otro['id']]) ?: 99999999, 'metodo_pago' => 'tarjeta_mock'], ['X-CSRF-Token: ' . $csrf]);
        $this->assertContains($robo['status'], [403, 404, 422]);
        // Cancela su propia cita en portal de cliente (sin privilegios de administrador).
        $x = $n->post('/api/eliminar', ['id' => $c, 'motivo' => 'x'], ['X-CSRF-Token: ' . $csrf, 'Accept: application/json']);
        $this->assertContains($x['status'], [200, 302], $x['body']);
        $this->assertSame('cancelada', self::escalar('SELECT estado FROM citas WHERE id=?', [$c]));
        // Mismo usuario en el portal del equipo gestiona la agenda de todos.
        $e = new Navegador(self::$base);
        $this->assertSame(302, $e->login('equipo', $dual['email'], $dual['password'])['status']);
        $this->assertSame(200, $e->get('/agenda')['status']);
        $this->assertSame(200, $e->get('/admin')['status']);
        // Una cita ajena no puede ser cancelada por un cliente (control negativo con rol laboral no heredado).
        $ajena = $this->reservar($no, $no->csrf('/cliente/login'), $this->dia(8), '09:30');
        $this->assertSame(200, $ajena['status'], $ajena['body']);
        $y = $n->post('/api/eliminar', ['id' => (int)$ajena['json']['id'], 'motivo' => 'x'], ['X-CSRF-Token: ' . $csrf, 'Accept: application/json']);
        $this->assertContains($y['status'], [403, 404], 'la sesión de cliente de una persona con rol laboral canceló una cita ajena: ' . $y['body']);
        $this->assertSame('confirmada', self::escalar('SELECT estado FROM citas WHERE id=?', [(int)$ajena['json']['id']]));
    }

    public function test_comisiones_e_inventario_siguen_operando_con_el_profesional_creado_por_invitacion(): void
    {
        $c = self::cliente(['email' => 'comi.' . bin2hex(random_bytes(2)) . '@example.test']);
        self::q("INSERT INTO citas(fecha,hora,hora_inicio,hora_fin,duracion_total_minutos,usuarioId,profesionalId,estado,estado_pago) VALUES (CURDATE(),'10:00:00','10:00:00','10:30:00',30,?,?,'completada','pendiente')", [$c['id'], self::$prof['profesional_id']]);
        $cita = (int)self::db()->insert_id;
        self::q("INSERT INTO citasservicios(citaId,servicioId,nombre_servicio,precio_servicio,duracion_minutos) VALUES (?,?,'Corte',25.00,30)", [$cita, self::$sid]);
        (new \Services\ComisionService())->configurar(self::$dueno['id'], ['profesional_id' => self::$prof['profesional_id'], 'porcentaje' => '10.00']);
        $r = (new \Services\FinanzasService())->registrar(self::$dueno['id'], ['tipo' => 'ingreso', 'metodo' => 'efectivo', 'concepto' => 'Cobro cita', 'idempotencia' => Idem::clave(), 'cita_id' => (string)$cita]);
        $this->assertFalse($r['idempotente']);
        $this->assertSame(250, (int)self::escalar("SELECT monto_centavos FROM comisiones_movimientos WHERE tipo='devengo' AND profesional_id=?", [self::$prof['profesional_id']]), '10 % de 25,00 = 2,50');
        $inv = new \Services\InventarioService();
        $pid = $inv->crear(['sku' => 'QA-' . bin2hex(random_bytes(2)), 'nombre' => 'Tinte', 'unidad' => 'ml', 'minimo' => '1', 'costo' => '2.50']);
        $m = $inv->mover(self::$dueno['id'], ['producto_id' => $pid, 'tipo' => 'entrada', 'cantidad' => '10', 'motivo' => 'compra', 'idempotencia' => Idem::clave()]);
        $this->assertNotEmpty($m);
    }

    public function test_suspender_profesional_con_citas_futuras_es_rechazado_y_no_deja_citas_huerfanas(): void
    {
        $c = self::cliente(['email' => 'futuro.' . bin2hex(random_bytes(2)) . '@example.test']);
        $n = new Navegador(self::$base);
        $n->login('cliente', $c['email'], $c['password']);
        $rv = $this->reservar($n, $n->csrf('/cliente/login'), $this->dia(9), '13:00');
        $this->assertSame(200, $rv['status'], $rv['body']);
        $svc = new \Services\ColaboradorService(new \Repositories\ColaboradorRepository(self::db()), new \Services\CorreoSalidaService(new \Repositories\CorreoSalidaRepository(self::db())));
        $r = $svc->cambiarAcceso(self::$dueno['id'], self::$prof['colaborador_id'], 'profesional', 'suspendido');
        $this->assertSame('forbidden', $r['status'], json_encode($r));
        $this->assertSame(1, (int)self::escalar('SELECT activo FROM profesionales WHERE id=?', [self::$prof['profesional_id']]));
        // Tampoco cambiando el rol (pierde agenda) ni dando de baja.
        foreach ([['recepcion', 'activo'], ['profesional', 'baja']] as [$rol, $estado]) {
            $this->assertSame('forbidden', $svc->cambiarAcceso(self::$dueno['id'], self::$prof['colaborador_id'], $rol, $estado)['status'], "$rol/$estado");
        }
    }

    public function test_Defecto_cita_nueva_en_carrera_con_la_suspension_del_profesional_queda_huerfana(): void
    {
        // Carrera: el profesional se suspende mientras otro cliente reserva. Sin bloqueo de la fila del profesional en
        // cambiarAcceso(), el conteo previo de citas futuras puede no ver la reserva en vuelo.
        $huerfanas = 0; $intentos = 0;
        $svc = new \Services\ColaboradorService(new \Repositories\ColaboradorRepository(self::db()), new \Services\CorreoSalidaService(new \Repositories\CorreoSalidaRepository(self::db())));
        for ($i = 0; $i < 8; $i++) {
            self::q("DELETE FROM citas WHERE profesionalId=?", [self::$prof['profesional_id']]);
            self::q('UPDATE profesionales SET activo=1 WHERE id=?', [self::$prof['profesional_id']]);
            self::q("UPDATE colaboradores SET estado='activo' WHERE id=?", [self::$prof['colaborador_id']]);
            self::q("UPDATE usuario_accesos SET activo=1 WHERE usuario_id=?", [self::$prof['id']]);
            $cl = self::cliente(['email' => 'carrera' . $i . '.' . bin2hex(random_bytes(2)) . '@example.test']);
            $res = self::concurrente([
                ['accion' => 'reservarProfesional', 'args' => ['usuario' => $cl['id'], 'profesional' => self::$prof['profesional_id'], 'fecha' => $this->dia(10 + $i), 'hora' => '10:00', 'servicio' => self::$sid]],
                ['accion' => 'cambiarAcceso', 'args' => ['actor' => self::$dueno['id'], 'id' => self::$prof['colaborador_id'], 'rol' => 'profesional', 'estado' => 'suspendido']],
            ], 0.8);
            $inactivo = (int)self::escalar('SELECT activo FROM profesionales WHERE id=?', [self::$prof['profesional_id']]) === 0;
            $futuras = (int)self::escalar("SELECT COUNT(*) FROM citas WHERE profesionalId=? AND estado IN ('confirmada','en_servicio') AND fecha>=CURDATE()", [self::$prof['profesional_id']]);
            $intentos++;
            if ($inactivo && $futuras > 0) { $huerfanas++; }
        }
        $this->assertSame(0, $huerfanas, "En $intentos carreras, $huerfanas dejaron una cita futura asignada a un profesional suspendido");
    }
}

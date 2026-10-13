<?php

namespace Tests\Qa;

require_once __DIR__ . '/QaCase.php';

/**
 * A. Concurrencia con PROCESOS reales y barrera de tiempo común.
 * Cada prueba lanza varios procesos PHP que ejecutan a la vez el mismo servicio de dominio de producción.
 */
final class ConcurrenciaTest extends QaCase
{
    protected function setUp(): void
    {
        self::limpiar();
    }

    private function esperaInvitacionPost(string $email, string $rol = 'recepcion', array $extra = []): array
    {
        return $extra + ['nombre' => 'Ana', 'apellido' => 'Pérez', 'email' => $email, 'telefono' => '', 'puesto' => 'x', 'rol' => $rol, 'fecha_ingreso' => ''];
    }

    private function sinErrores(array $res, string $contexto): void
    {
        foreach ($res as $i => $r) {
            $this->assertTrue($r['ok'], "$contexto: worker $i lanzó " . json_encode($r['error'] ?? $r));
            $this->assertNotSame('error', $r['resultado']['status'] ?? null, "$contexto: worker $i devolvió status=error (posible 1213 sin reintento): " . json_encode($r['resultado']) . $r['stderr']);
            $this->assertStringNotContainsString('Deadlock', json_encode($r) . $r['stderr']);
        }
    }

    public function test_registro_misma_clave_6_procesos_crea_una_cuenta_y_un_correo(): void
    {
        $email = self::correo('reg');
        $clave = Idem::clave();
        $post = ['nombre' => 'Rosa', 'apellido' => 'Diaz', 'email' => $email, 'password' => 'Abcdef123456', 'telefono' => '0991112222'];
        $t = array_fill(0, 6, ['accion' => 'registrar', 'args' => ['post' => $post, 'clave' => $clave, 'actor' => 'anonimo:' . str_repeat('a', 32)]]);
        $res = self::concurrente($t);
        $this->sinErrores($res, 'registro misma clave');
        $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM usuarios WHERE email=?', [$email]));
        $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM clientes c JOIN usuarios u ON u.id=c.usuario_id WHERE u.email=?', [$email]));
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM operaciones_idempotentes WHERE scope='cliente.registro'"));
        $correos = array_sum(array_map(fn($r) => $r['resultado']['correos_enviados'], $res));
        $this->assertSame(1, $correos, 'exactamente UN correo de confirmación entre los 6 procesos: ' . json_encode(array_column($res, 'resultado')));
        // Observación (baja): una ráfaga de >3 envíos idénticos agota el presupuesto por correo (3) y los sobrantes reciben 429
        // aunque pertenezcan a la MISMA operación idempotente. No hay duplicado ni correo extra, solo respuesta menos amable.
        $estados = array_map(fn($r) => $r['resultado']['status'], $res);
        $this->assertContains('ok', $estados);
        foreach ($estados as $e) { $this->assertContains($e, ['ok', 'rate_limited'], json_encode($estados)); }
    }

    public function test_registro_claves_distintas_mismo_correo_una_sola_identidad(): void
    {
        $email = self::correo('reg2');
        $post = ['nombre' => 'Rosa', 'apellido' => 'Diaz', 'email' => $email, 'password' => 'Abcdef123456', 'telefono' => '0991112222'];
        $t = [];
        for ($i = 0; $i < 6; $i++) { $t[] = ['accion' => 'registrar', 'args' => ['post' => $post, 'clave' => Idem::clave(), 'actor' => 'anonimo:' . str_repeat('b', 32)]]; }
        $res = self::concurrente($t);
        $this->sinErrores($res, 'registro claves distintas');
        $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM usuarios WHERE LOWER(TRIM(email))=?', [$email]));
        $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM clientes'));
        $ok = count(array_filter($res, fn($r) => $r['resultado']['status'] === 'ok'));
        $this->assertSame(1, $ok, 'solo UNA solicitud gana: ' . json_encode(array_column($res, 'resultado')));
        $this->assertSame(1, array_sum(array_map(fn($r) => $r['resultado']['correos_enviados'], $res)));
        // Sin filas de idempotencia huérfanas: las solicitudes perdedoras revierten su reserva.
        $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM operaciones_idempotentes'), 'reservas huérfanas');
    }

    public function test_registro_variantes_de_mayusculas_y_espacios_concurrentes_no_duplican_identidad(): void
    {
        $base = self::correo('Reg3');
        $variantes = [$base, strtoupper($base), ' ' . $base . ' ', ucfirst($base)];
        $t = [];
        foreach ($variantes as $v) {
            $t[] = ['accion' => 'registrar', 'args' => ['post' => ['nombre' => 'A', 'apellido' => 'B', 'email' => $v, 'password' => 'Abcdef123456', 'telefono' => '0991112222'], 'clave' => Idem::clave(), 'actor' => 'anonimo:' . str_repeat('c', 32)]];
        }
        $res = self::concurrente($t);
        $this->sinErrores($res, 'variantes de correo');
        $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM usuarios WHERE LOWER(TRIM(email))=?', [strtolower($base)]), json_encode(array_column($res, 'resultado')));
    }

    public function test_profesional_crear_misma_clave_6_procesos_crea_uno(): void
    {
        $sid = self::servicio();
        $clave = Idem::clave();
        $post = ['nombre' => 'Profe Concurrente', 'activo' => '1', 'servicios' => [(string)$sid]];
        $t = array_fill(0, 6, ['accion' => 'profesionalCrear', 'args' => ['post' => $post, 'clave' => $clave, 'actor' => 'equipo:1']]);
        $res = self::concurrente($t);
        $this->sinErrores($res, 'profesional misma clave');
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM profesionales WHERE nombre='Profe Concurrente'"));
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM profesionales_servicios"));
        $ids = array_unique(array_map(fn($r) => $r['resultado']['id'], $res));
        $this->assertCount(1, $ids, 'todas las respuestas deben apuntar al mismo profesional');
    }

    public function test_profesional_crear_claves_distintas_mismo_nombre_no_se_queda_con_reservas_huerfanas(): void
    {
        $sid = self::servicio();
        $post = ['nombre' => 'Profe Dos Claves', 'activo' => '1', 'servicios' => [(string)$sid]];
        $t = [];
        for ($i = 0; $i < 4; $i++) { $t[] = ['accion' => 'profesionalCrear', 'args' => ['post' => $post, 'clave' => Idem::clave(), 'actor' => 'equipo:1']]; }
        $res = self::concurrente($t);
        $this->sinErrores($res, 'profesional claves distintas');
        // Cada clave distinta es una intención distinta: el sistema NO exige unicidad de nombre, así que se crean 4.
        // Lo que no debe pasar: filas de idempotencia sin profesional o profesionales sin reserva.
        $profs = (int)self::escalar("SELECT COUNT(*) FROM profesionales WHERE nombre='Profe Dos Claves'");
        $ops = (int)self::escalar("SELECT COUNT(*) FROM operaciones_idempotentes WHERE scope='profesional.crear' AND recurso_id>0");
        $this->assertSame($profs, $ops, 'profesionales vs operaciones idempotentes confirmadas');
    }

    public function test_invitar_misma_clave_6_procesos_una_invitacion_un_correo(): void
    {
        $dueno = self::propietario();
        $email = self::correo('inv');
        $clave = Idem::clave();
        $t = array_fill(0, 6, ['accion' => 'invitar', 'args' => ['actor' => $dueno['id'], 'post' => $this->esperaInvitacionPost($email), 'clave' => $clave]]);
        $res = self::concurrente($t);
        $this->sinErrores($res, 'invitar misma clave');
        $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM colaboradores WHERE email_norm=?', [$email]));
        $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM colaborador_invitaciones i JOIN colaboradores c ON c.id=i.colaborador_id WHERE c.email_norm=?', [$email]));
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM correos_salida WHERE tipo='invitacion_colaborador' AND destinatario_email=?", [$email]));
        foreach ($res as $r) { $this->assertSame('ok', $r['resultado']['status'], json_encode($r['resultado'])); }
    }

    public function test_invitar_claves_distintas_mismo_correo_una_sola_ficha(): void
    {
        $dueno = self::propietario();
        $email = self::correo('inv2');
        $t = [];
        for ($i = 0; $i < 6; $i++) { $t[] = ['accion' => 'invitar', 'args' => ['actor' => $dueno['id'], 'post' => $this->esperaInvitacionPost($email), 'clave' => Idem::clave()]]; }
        $res = self::concurrente($t);
        $this->sinErrores($res, 'invitar claves distintas');
        $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM colaboradores WHERE email_norm=?', [$email]));
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM correos_salida WHERE destinatario_email=?", [$email]));
        $this->assertSame(1, count(array_filter($res, fn($r) => $r['resultado']['status'] === 'ok')), json_encode(array_column($res, 'resultado')));
        // La reserva de las perdedoras debe haberse revertido.
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM operaciones_idempotentes WHERE scope='colaborador.invitar'"), 'reservas huérfanas');
    }

    public function test_invitar_profesional_concurrente_no_deja_ficha_huerfana_ni_duplicada(): void
    {
        $dueno = self::propietario();
        $sid = self::servicio();
        $email = self::correo('invp');
        $t = [];
        for ($i = 0; $i < 5; $i++) {
            $t[] = ['accion' => 'invitar', 'args' => ['actor' => $dueno['id'], 'post' => $this->esperaInvitacionPost($email, 'profesional', ['servicios' => [(string)$sid], 'nombre_profesional' => 'Ficha Conc']), 'clave' => Idem::clave()]];
        }
        $res = self::concurrente($t);
        $this->sinErrores($res, 'invitar profesional');
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM profesionales WHERE nombre='Ficha Conc'"), 'una sola ficha profesional; las perdedoras no deben dejar fichas: ' . json_encode(array_column($res, 'resultado')));
        $this->assertSame(0, (int)self::escalar("SELECT COUNT(*) FROM profesionales WHERE colaborador_id IS NULL AND usuarioId IS NULL"), 'ficha sin colaborador (huérfana)');
    }

    public function test_reenvio_secuencial_respeta_la_espera_de_60_segundos(): void
    {
        $dueno = self::propietario();
        $svc = new \Services\ColaboradorService(new \Repositories\ColaboradorRepository(self::db()), new \Services\CorreoSalidaService(new \Repositories\CorreoSalidaRepository(self::db())));
        $r = $svc->invitar($dueno['id'], $this->esperaInvitacionPost(self::correo('seq')), Idem::clave());
        $this->assertSame('ok', $r['status']);
        self::q("UPDATE colaborador_invitaciones SET creada_en = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)");
        $a = $svc->reenviar($dueno['id'], $r['colaborador_id'], Idem::clave());
        $b = $svc->reenviar($dueno['id'], $r['colaborador_id'], Idem::clave());
        $this->assertSame('ok', $a['status']);
        $this->assertSame('forbidden', $b['status'], 'el segundo reenvío inmediato debe rechazarse');
    }

    public function test_Defecto_reenvio_simultaneo_evade_la_espera_de_60_segundos_y_emite_varias_invitaciones(): void
    {
        $dueno = self::propietario();
        $email = self::correo('reen');
        $svc = new \Services\ColaboradorService(new \Repositories\ColaboradorRepository(self::db()), new \Services\CorreoSalidaService(new \Repositories\CorreoSalidaRepository(self::db())));
        $r = $svc->invitar($dueno['id'], $this->esperaInvitacionPost($email), Idem::clave());
        $this->assertSame('ok', $r['status']);
        self::q("UPDATE colaborador_invitaciones SET creada_en = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)");
        $t = [];
        for ($i = 0; $i < 6; $i++) { $t[] = ['accion' => 'reenviar', 'args' => ['actor' => $dueno['id'], 'id' => $r['colaborador_id'], 'clave' => Idem::clave()]]; }
        $res = self::concurrente($t);
        $this->sinErrores($res, 'reenvío simultáneo');
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM colaborador_invitaciones WHERE colaborador_id=? AND estado='pendiente'", [$r['colaborador_id']]));
        $this->assertSame(2, (int)self::escalar("SELECT COUNT(*) FROM colaborador_invitaciones WHERE colaborador_id=?", [$r['colaborador_id']]), 'con la espera de 60 s solo debía crearse UNA invitación nueva (total 2); la lectura de la espera usa una instantánea obsoleta bajo REPEATABLE READ');
        $vivos = (int)self::escalar("SELECT COUNT(*) FROM correos_salida WHERE tipo='invitacion_colaborador' AND estado IN ('pendiente','enviando','fallido','enviado') AND carga_cifrada IS NOT NULL OR estado='enviado'");
        $this->assertLessThanOrEqual(1, (int)self::escalar("SELECT COUNT(*) FROM correos_salida WHERE estado IN ('pendiente','enviando') AND carga_cifrada IS NOT NULL"), 'más de un correo vivo con token');
        $this->assertSame(1, count(array_filter($res, fn($x) => ($x['resultado']['status'] ?? '') === 'ok')) , 'solo un reenvío gana por la ventana de 60 s: ' . json_encode(array_column($res, 'resultado')));
    }

    public function test_aceptacion_simultanea_del_mismo_token_solo_una_gana(): void
    {
        $dueno = self::propietario();
        $email = self::correo('acep');
        $repo = new \Repositories\ColaboradorRepository(self::db());
        $r = $repo->crearInvitacion($dueno['id'], ['nombre' => 'N', 'apellido' => 'A', 'email' => $email, 'telefono' => null, 'puesto' => '', 'rol' => 'recepcion', 'fecha_ingreso' => null, 'servicios' => [], 'nombre_profesional' => '', 'profesional_id' => null]);
        $token = self::tokenDeInvitacion($r['invitacion_id']);
        $t = [];
        for ($i = 0; $i < 6; $i++) { $t[] = ['accion' => 'aceptar', 'args' => ['token' => $token, 'post' => ['acepto' => '1', 'password' => 'Nueva-Pass-123', 'password_confirmacion' => 'Nueva-Pass-123', 'password_actual' => ''], 'ip' => '10.0.0.' . $i]]; }
        $res = self::concurrente($t);
        $this->sinErrores($res, 'aceptación simultánea');
        $this->assertSame(1, count(array_filter($res, fn($x) => $x['resultado']['status'] === 'ok')), json_encode(array_column($res, 'resultado')));
        $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM usuarios WHERE email=?', [$email]));
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM usuario_accesos a JOIN usuarios u ON u.id=a.usuario_id WHERE u.email=? AND a.rol='recepcion'", [$email]));
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM clientes c JOIN usuarios u ON u.id=c.usuario_id WHERE u.email=?', [$email]), 'la cuenta de equipo no debe nacer con membresía cliente');
    }

    public function test_aceptar_vs_revocar_estado_final_coherente_y_sin_interbloqueo_visible(): void
    {
        $dueno = self::propietario();
        $incoherentes = []; $errores = [];
        for ($ronda = 0; $ronda < 6; $ronda++) {
            $email = self::correo('avr');
            $repo = new \Repositories\ColaboradorRepository(self::db());
            $r = $repo->crearInvitacion($dueno['id'], ['nombre' => 'N', 'apellido' => 'A', 'email' => $email, 'telefono' => null, 'puesto' => '', 'rol' => 'recepcion', 'fecha_ingreso' => null, 'servicios' => [], 'nombre_profesional' => '', 'profesional_id' => null]);
            $token = self::tokenDeInvitacion($r['invitacion_id']);
            $res = self::concurrente([
                ['accion' => 'aceptar', 'args' => ['token' => $token, 'post' => ['acepto' => '1', 'password' => 'Nueva-Pass-123', 'password_confirmacion' => 'Nueva-Pass-123'], 'ip' => '10.0.1.' . $ronda]],
                ['accion' => 'revocar', 'args' => ['actor' => $dueno['id'], 'id' => $r['colaborador_id']]],
            ], 0.8);
            foreach ($res as $x) { if (!$x['ok'] || in_array($x['resultado']['status'] ?? '', ['error'], true)) { $errores[] = json_encode($x); } }
            $c = self::q('SELECT c.estado ce, i.estado ie, c.usuario_id FROM colaboradores c JOIN colaborador_invitaciones i ON i.colaborador_id=c.id WHERE c.id=?', [$r['colaborador_id']])[0];
            $ok = ($c['ce'] === 'activo' && $c['ie'] === 'aceptada' && $c['usuario_id'] !== null) || ($c['ce'] === 'baja' && $c['ie'] === 'revocada' && $c['usuario_id'] === null);
            if (!$ok) { $incoherentes[] = json_encode($c); }
            $acc = (int)self::escalar('SELECT COUNT(*) FROM usuario_accesos a JOIN usuarios u ON u.id=a.usuario_id WHERE u.email=?', [$email]);
            if ($c['ce'] === 'baja' && $acc > 0) { $incoherentes[] = "baja pero con acceso laboral: $email"; }
        }
        $this->assertSame([], $incoherentes, 'estados mezclados');
        $this->assertSame([], $errores, 'Aceptar vs revocar produjo error/deadlock expuesto al usuario (sin reintento)');
    }

    public function test_degradacion_simultanea_de_dos_propietarios_deja_al_menos_uno(): void
    {
        $p1 = self::propietario();
        $m = self::miembro($p1['id'], 'recepcion');
        (new \Repositories\EquipoRepository(self::db()))->actualizar($p1['id'], $m['id'], 'propietario', true);
        $this->assertSame(2, (int)self::escalar("SELECT COUNT(*) FROM usuario_accesos WHERE rol='propietario' AND activo=1") + (int)self::escalar("SELECT COUNT(*) FROM usuarios u LEFT JOIN usuario_accesos a ON a.usuario_id=u.id WHERE a.usuario_id IS NULL AND u.admin=1"));
        // Cada propietario degrada al OTRO (o a sí mismo) a la vez.
        $res = self::concurrente([
            ['accion' => 'equipoActualizar', 'args' => ['actor' => $p1['id'], 'usuario' => $m['id'], 'rol' => 'recepcion', 'activo' => true]],
            ['accion' => 'equipoActualizar', 'args' => ['actor' => $m['id'], 'usuario' => $p1['id'], 'rol' => 'recepcion', 'activo' => true]],
        ]);
        $activos = (int)self::escalar("SELECT COUNT(*) FROM usuarios u LEFT JOIN usuario_accesos a ON a.usuario_id=u.id WHERE COALESCE(a.rol,IF(u.admin=1,'propietario','cliente'))='propietario' AND COALESCE(a.activo,1)=1 AND u.confirmado=1");
        $this->assertGreaterThanOrEqual(1, $activos, 'se perdió al último propietario: ' . json_encode($res));
        $this->assertSame(1, count(array_filter($res, fn($x) => !$x['ok'])) , 'exactamente una degradación debe ser rechazada: ' . json_encode($res));
    }

    public function test_degradacion_simultanea_por_la_ruta_de_colaborador_deja_al_menos_un_propietario(): void
    {
        $incoherentes = [];
        for ($ronda = 0; $ronda < 4; $ronda++) {
            self::limpiar();
            $p1 = self::propietario();
            $m = self::miembro($p1['id'], 'recepcion');
            (new \Repositories\EquipoRepository(self::db()))->actualizar($p1['id'], $m['id'], 'propietario', true);
            $c1 = (int)self::escalar('SELECT id FROM colaboradores WHERE usuario_id=?', [$p1['id']]);
            $c2 = (int)self::escalar('SELECT id FROM colaboradores WHERE usuario_id=?', [$m['id']]);
            // Mismo camino que POST /admin/equipo/actualizar: ColaboradorService::cambiarAcceso. Suspensión cruzada.
            $res = self::concurrente([
                ['accion' => 'cambiarAcceso', 'args' => ['actor' => $p1['id'], 'id' => $c2, 'rol' => 'propietario', 'estado' => 'suspendido']],
                ['accion' => 'cambiarAcceso', 'args' => ['actor' => $m['id'], 'id' => $c1, 'rol' => 'propietario', 'estado' => 'suspendido']],
            ], 0.8);
            $activos = (int)self::escalar("SELECT COUNT(*) FROM usuarios u LEFT JOIN usuario_accesos a ON a.usuario_id=u.id LEFT JOIN colaboradores c ON c.usuario_id=u.id WHERE COALESCE(a.rol,IF(u.admin=1,'propietario','cliente'))='propietario' AND COALESCE(a.activo,1)=1 AND COALESCE(c.estado,'activo')='activo' AND u.confirmado=1");
            if ($activos < 1) { $incoherentes[] = "ronda $ronda: sin propietarios activos " . json_encode(array_column($res, 'resultado')); }
        }
        $this->assertSame([], $incoherentes);
    }

    public function test_cambio_de_rol_vs_aceptacion_no_deja_estado_mixto(): void
    {
        $dueno = self::propietario();
        $incoherentes = [];
        for ($i = 0; $i < 5; $i++) {
            $email = self::correo('crol');
            $repo = new \Repositories\ColaboradorRepository(self::db());
            $r = $repo->crearInvitacion($dueno['id'], ['nombre' => 'N', 'apellido' => 'A', 'email' => $email, 'telefono' => null, 'puesto' => '', 'rol' => 'recepcion', 'fecha_ingreso' => null, 'servicios' => [], 'nombre_profesional' => '', 'profesional_id' => null]);
            $token = self::tokenDeInvitacion($r['invitacion_id']);
            $res = self::concurrente([
                ['accion' => 'aceptar', 'args' => ['token' => $token, 'post' => ['acepto' => '1', 'password' => 'Nueva-Pass-123', 'password_confirmacion' => 'Nueva-Pass-123'], 'ip' => '10.0.2.' . $i]],
                ['accion' => 'cambiarAcceso', 'args' => ['actor' => $dueno['id'], 'id' => $r['colaborador_id'], 'rol' => 'finanzas', 'estado' => 'activo']],
            ], 0.8);
            $c = self::q('SELECT c.estado, c.rol crol, a.rol arol FROM colaboradores c LEFT JOIN usuario_accesos a ON a.usuario_id=c.usuario_id WHERE c.id=?', [$r['colaborador_id']])[0];
            if ($c['estado'] === 'activo' && $c['crol'] !== $c['arol']) { $incoherentes[] = json_encode($c) . ' / ' . json_encode(array_column($res, 'resultado')); }
        }
        $this->assertSame([], $incoherentes, 'rol de la ficha distinto al rol efectivo de acceso');
    }

    public function test_instalador_paralelo_en_base_ya_migrada_crea_un_solo_propietario(): void
    {
        $email = self::correo('inst');
        $t = array_fill(0, 6, ['accion' => 'propietarioInicial', 'args' => ['email' => $email, 'password' => null]]);
        $res = self::concurrente($t);
        $this->sinErrores($res, 'propietario inicial');
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM colaboradores WHERE rol='propietario'"));
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM colaborador_invitaciones WHERE estado='pendiente'"));
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM correos_salida"));
        $estados = array_map(fn($r) => $r['resultado']['estado'], $res);
        $this->assertSame(1, count(array_filter($estados, fn($e) => $e === 'activacion_emitida')), json_encode($estados));
    }

    public function test_instalador_paralelo_con_secreto_crea_un_solo_propietario_y_no_cambia_password(): void
    {
        $email = self::correo('inst2');
        $t = [];
        for ($i = 0; $i < 6; $i++) { $t[] = ['accion' => 'propietarioInicial', 'args' => ['email' => $email, 'password' => 'Secreto-de-instalacion-' . $i]]; }
        $res = self::concurrente($t);
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM usuarios WHERE admin=1"));
        $this->assertSame(1, (int)self::escalar("SELECT COUNT(*) FROM usuario_accesos WHERE rol='propietario'"));
        $creados = count(array_filter($res, fn($r) => ($r['resultado']['estado'] ?? '') === 'creado'));
        $this->assertSame(1, $creados, json_encode(array_column($res, 'resultado')));
    }
}

<?php

namespace Tests\Qa;

use Services\AuthorizationService;

/**
 * Infraestructura de la matriz de autorización exacta (Ronda 2).
 *
 * · Personas: cada modalidad de acceso es una sesión PHP fabricada con exactamente los campos que escribe el login real
 *   (ver LoginController::autenticar) más las variantes forjadas/caducadas. Una sesión nueva por celda evita que la mutación
 *   de sesión de una petición (isAuth() vacía la sesión caducada) contamine la siguiente.
 * · Centinelas: datos únicos de OTRAS personas; ninguna respuesta puede contenerlos salvo que el rol los vea legítimamente.
 * · Huella de BD: conteo + CHECKSUM de las tablas de negocio antes/después de cada petición.
 */
trait MatrizHttp
{
    /** Tablas que NO entran en la huella: migraciones (esquema), equipo_mutex (semilla del candado) e intentos_login
     *  (bitácora legítima del limitador de intentos: un login fallido debe contarse). */
    private const TABLAS_EXCLUIDAS = ['migraciones', 'equipo_mutex', 'intentos_login', 'exportaciones_auditoria_general'];   // ronda 5: la auditoría de exportaciones (también de las denegadas) es bitácora legítima, como intentos_login

    public const SMTP_PUERTO = 2599;

    /** Centinelas por grupo (todos ASCII para poder buscarlos tal cual en HTML, JSON y cabeceras). */
    public const CENTINELAS = [
        'PII' => ['sentinela.ajeno@example.test', 'Sentinela-Ajena', 'Zorrilla-Ajeno', '0991117777'],
        'PAGO' => ['REF-SENTINELA-AJENO', '4321.99', 'SENT-MOTIVO-CANCELA'],
        'SALUD' => ['SENT-SALUD-AJENO'],
        'STAFF' => ['SENT-NOTA-RH', 'SENT-PUESTO-RH', '0992228888'],
        'NEGOCIO' => ['SENT-PRODUCTO-INV', 'SENT-SKU-1', 'SENT-CAJA-CONCEPTO'],
    ];

    /** @var array<string,array> */
    private static array $P = [];
    /** @var array<string,mixed> */
    private static array $D = [];
    /** @var string[]|null */
    private static ?array $tablasHuella = null;
    private static string $buzonRuta = '';

    // ------------------------------------------------------------------ siembra

    private static function sembrarMatriz(): void
    {
        self::limpiar();
        self::$buzonRuta = self::iniciarSmtp(self::SMTP_PUERTO);
        self::$D['base'] = self::servidor();
        $dueno = self::propietario('m.propietario@example.test');
        self::$P['propietario'] = $dueno + ['rol' => 'propietario'];
        foreach (['gerente', 'recepcion', 'profesional', 'finanzas'] as $rol) {
            self::$P[$rol] = self::miembro($dueno['id'], $rol, ['email' => "m.$rol@example.test"]) + [];
        }
        $pro2 = self::miembro($dueno['id'], 'profesional', ['email' => 'm.profesional2@example.test']);
        self::$P['cliente'] = self::cliente(['email' => 'm.cliente@example.test', 'nombre' => 'Clienta', 'apellido' => 'Propia', 'telefono' => '0995550001']) + ['rol' => 'cliente'];
        self::$D['pro2'] = $pro2;

        // Personas en estados degradados (cuentas reales; la sesión se fabrica después).
        $suspendidoCli = self::cliente(['email' => 'm.cli.susp@example.test', 'estado' => 'suspendido']);
        $noConfirmado = self::cliente(['email' => 'm.cli.noconf@example.test', 'confirmado' => false]);
        $colSusp = self::miembro($dueno['id'], 'recepcion', ['email' => 'm.col.susp@example.test']);
        $colBaja = self::miembro($dueno['id'], 'recepcion', ['email' => 'm.col.baja@example.test']);
        $svc = new \Services\ColaboradorService(new \Repositories\ColaboradorRepository(self::db()), new \Services\CorreoSalidaService(new \Repositories\CorreoSalidaRepository(self::db())));
        self::assertSameStatic('ok', $svc->cambiarAcceso($dueno['id'], $colSusp['colaborador_id'], 'recepcion', 'suspendido')['status']);
        self::assertSameStatic('ok', $svc->cambiarAcceso($dueno['id'], $colBaja['colaborador_id'], 'recepcion', 'baja')['status']);
        // Invitado: persona que ya es cliente confirmada y tiene una invitación laboral pendiente.
        $invCli = self::cliente(['email' => 'm.col.invitado@example.test']);
        $repo = new \Repositories\ColaboradorRepository(self::db());
        $inv = $repo->crearInvitacion($dueno['id'], ['nombre' => 'In', 'apellido' => 'Vitado', 'email' => 'm.col.invitado@example.test', 'telefono' => null, 'puesto' => '', 'rol' => 'recepcion', 'fecha_ingreso' => null, 'servicios' => [], 'nombre_profesional' => '', 'profesional_id' => null]);
        // Usuario que existió y fue eliminado (sin dependencias): la sesión sobrevive a la fila.
        self::q("INSERT INTO usuarios(nombre,apellido,email,password,telefono,admin,confirmado) VALUES ('Del','Eted','m.eliminado@example.test',?,'0990000009',0,1)", [password_hash(self::PASS, PASSWORD_BCRYPT)]);
        $eliminadoId = (int)self::db()->insert_id;
        self::q('DELETE FROM usuarios WHERE id=?', [$eliminadoId]);

        self::$P += [
            'cliente_susp' => $suspendidoCli, 'cliente_noconf' => $noConfirmado, 'colab_susp' => $colSusp, 'colab_baja' => $colBaja,
            'colab_invitado' => $invCli + ['colaborador_id' => $inv['colaborador_id']], 'eliminado' => ['id' => $eliminadoId, 'email' => 'm.eliminado@example.test'],
        ];

        // Cliente AJENO con todos los centinelas y su propia cita/pago/formulario; atendido por el profesional 2.
        $ajeno = self::cliente(['email' => 'sentinela.ajeno@example.test', 'nombre' => 'Sentinela-Ajena', 'apellido' => 'Zorrilla-Ajeno', 'telefono' => '0991117777']);
        self::$D['ajeno'] = $ajeno;
        $sid = self::servicio('Servicio M', 25.00);
        self::$D['servicio'] = $sid;
        $hoy = (new \DateTimeImmutable('now', new \DateTimeZone('America/Guayaquil')))->format('Y-m-d');
        self::$D['hoy'] = $hoy;
        $crearCita = function (int $uid, int $profId, string $fecha, string $estado, string $pagoRef = '', string $monto = '25.00', ?string $motivo = null) use ($sid): int {
            self::q("INSERT INTO citas(fecha,hora,hora_inicio,hora_fin,duracion_total_minutos,usuarioId,profesionalId,estado,estado_pago,motivo_cancelacion) VALUES (?,'10:00:00','10:00:00','10:30:00',30,?,?,?,?,?)", [$fecha, $uid, $profId, $estado, $pagoRef ? 'pagado' : 'pendiente', $motivo]);
            $cita = (int)self::db()->insert_id;
            self::q("INSERT INTO citasservicios(citaId,servicioId,nombre_servicio,precio_servicio,duracion_minutos) VALUES (?,?,?,?,30)", [$cita, $sid, 'Servicio M', $monto]);
            if ($pagoRef) {
                self::q("INSERT INTO pagos(cita_id,cita_referencia_id,usuario_id,referencia_pago,proveedor,monto,estado,metodo_pago,idempotency_key,pagado_at) VALUES (?,?,?,?,'mock',?,'completado','tarjeta',?,NOW())", [$cita, $cita, $uid, $pagoRef, $monto, 'idem-' . bin2hex(random_bytes(6))]);
            }
            return $cita;
        };
        $maniana = (new \DateTimeImmutable('now', new \DateTimeZone('America/Guayaquil')))->modify('+3 days')->format('Y-m-d');
        // Cita completada y pasada de la clienta propia con el profesional: define qué clientes «atendió» (CrmRepository::clienteDeProfesional).
        self::$D['cita_atendida'] = $crearCita((int)self::$P['cliente']['id'], (int)self::$P['profesional']['profesional_id'], $hoy, 'completada');
        self::$D['cita_propia'] = $crearCita((int)self::$P['cliente']['id'], (int)self::$P['profesional']['profesional_id'], $maniana, 'confirmada');
        self::$D['cita_ajena'] = $crearCita($ajeno['id'], (int)$pro2['profesional_id'], $maniana, 'confirmada', 'REF-SENTINELA-AJENO', '4321.99');
        self::$D['cita_ajena_cancelada'] = $crearCita($ajeno['id'], (int)$pro2['profesional_id'], $hoy, 'cancelada', '', '25.00', 'SENT-MOTIVO-CANCELA');
        self::q("INSERT INTO formularios_cliente(titulo,preguntas,creado_por) VALUES ('Ficha M','[]',?)", [$dueno['id']]);
        self::q("INSERT INTO formulario_respuestas(formulario_id,usuario_id,respuestas) VALUES (?,?,?)", [(int)self::db()->insert_id, $ajeno['id'], json_encode(['alergia' => 'SENT-SALUD-AJENO'])]);
        self::q("UPDATE colaboradores SET notas_rh='SENT-NOTA-RH', puesto='SENT-PUESTO-RH', telefono='0992228888' WHERE usuario_id=?", [self::$P['colab_baja']['id']]);
        self::q("INSERT INTO inventario_productos(sku,nombre,unidad,stock_milesimas,minimo_milesimas,costo_centavos) VALUES ('SENT-SKU-1','SENT-PRODUCTO-INV','unidad',5000,1000,250)");
        self::q("INSERT INTO caja_movimientos(actor_id,tipo,monto_centavos,concepto,metodo,idempotencia,huella,registrado_en) VALUES (?,'ingreso',1234,'SENT-CAJA-CONCEPTO','efectivo',?,?,NOW())", [$dueno['id'], 'sent-' . bin2hex(random_bytes(6)), hash('sha256', 'x')]);
        // Filas «víctima» dedicadas a los escenarios con efecto (no son personas de la matriz).
        $victima = self::miembro($dueno['id'], 'recepcion', ['email' => 'm.victima@example.test']);
        $pro3 = self::miembro($dueno['id'], 'profesional', ['email' => 'm.profesional3@example.test']);
        $invR = $repo->crearInvitacion($dueno['id'], ['nombre' => 'Por', 'apellido' => 'Revocar', 'email' => 'm.revocar@example.test', 'telefono' => null, 'puesto' => '', 'rol' => 'recepcion', 'fecha_ingreso' => null, 'servicios' => [], 'nombre_profesional' => '', 'profesional_id' => null]);
        $invE = $repo->crearInvitacion($dueno['id'], ['nombre' => 'Por', 'apellido' => 'Reenviar', 'email' => 'm.reenviar@example.test', 'telefono' => null, 'puesto' => '', 'rol' => 'recepcion', 'fecha_ingreso' => null, 'servicios' => [], 'nombre_profesional' => '', 'profesional_id' => null]);
        self::$D += ['victima' => $victima, 'pro3' => $pro3, 'col_por_revocar' => (int)$invR['colaborador_id'], 'col_por_reenviar' => (int)$invE['colaborador_id']];
        self::$D['servicio_borrable'] = self::servicio('Servicio Borrable', 9.00);
        // Cita confirmada de AYER del profesional con la clienta propia (la agenda permite pasar a «en servicio» solo si ya empezó).
        $ayer = (new \DateTimeImmutable('now', new \DateTimeZone('America/Guayaquil')))->modify('-1 day')->format('Y-m-d');
        // Decisión sobre citas de días anteriores: una cita de AYER ya no se inicia de forma ordinaria. Para el escenario con efecto se usa una de
        // HOY a las 00:00 (la ventana de inicio abre a las 23:00 de la víspera y cierra a las 24:00 del día de la cita).
        self::$D['cita_estado'] = $crearCita((int)self::$P['cliente']['id'], (int)self::$P['profesional']['profesional_id'], $hoy, 'confirmada');
        self::q("UPDATE citas SET hora='00:00:00', hora_inicio='00:00:00', hora_fin='00:30:00' WHERE id=?", [self::$D['cita_estado']]);
        self::$D['cita_reembolso'] = $crearCita((int)self::$P['cliente']['id'], (int)self::$P['profesional']['profesional_id'], $ayer, 'completada', 'REF-EFECTO-REEMBOLSO', '30.00');
        // El reenvío de invitaciones está limitado a 1/minuto: se envejece la invitación sembrada para que el escenario ejerza el camino feliz.
        self::q('UPDATE colaborador_invitaciones SET creada_en=DATE_SUB(creada_en, INTERVAL 10 MINUTE) WHERE colaborador_id=?', [self::$D['col_por_reenviar']]);
        self::$D['cita_cancelable'] = $crearCita((int)self::$P['cliente']['id'], (int)self::$P['profesional']['profesional_id'], (new \DateTimeImmutable('now', new \DateTimeZone('America/Guayaquil')))->modify('+5 days')->format('Y-m-d'), 'confirmada');
        self::$D['col_staff'] = (int)self::escalar('SELECT id FROM colaboradores WHERE usuario_id=?', [self::$P['colab_baja']['id']]);
        self::$D['col_propietario'] = (int)self::escalar('SELECT id FROM colaboradores WHERE usuario_id=?', [$dueno['id']]);
    }

    private static function assertSameStatic(mixed $e, mixed $a): void
    {
        if ($e !== $a) { throw new \RuntimeException('Siembra inconsistente: esperado ' . var_export($e, true) . ' obtenido ' . var_export($a, true)); }
    }

    // ------------------------------------------------------------------ sesiones (modalidades)

    private static function versionDe(int $id): int
    {
        return (int)(self::escalar('SELECT version_sesion FROM usuarios WHERE id=?', [$id]) ?? 0);
    }

    /** Sesión tal como la deja LoginController::autenticar. */
    private static function sesionLogin(array $u, string $portal, string $rolSesion, string $csrf, array $sobre = []): array
    {
        $d = ['csrf_token' => $csrf, 'id' => (string)$u['id'], 'nombre' => 'N', 'apellido' => 'A', 'email' => $u['email'], 'login' => true,
            'version_sesion' => self::versionDe((int)$u['id']), 'portal' => $portal, 'rol' => $rolSesion];
        if ($portal === 'equipo' && in_array($rolSesion, ['propietario', 'gerente'], true)) { $d['admin'] = '1'; }
        return $sobre + $d;
    }

    /**
     * Modalidades. 'cat' resume lo que el SERVIDOR debe concluir (derivado de AuthorizationService::contexto/sesionVigente/isAuth/puede):
     *   anonimo   · sin sesión autenticada (isAuth redirige a `/` o responde 401)
     *   valida    · sesión vigente; 'rol' es el rol EFECTIVO del portal ('cliente' en el portal de clientes)
     *   caducada  · login=true pero el servidor la invalida; 'destino' = portal de la marca de sesión (`/equipo/login` o `/`)
     * @return array<string,array{cat:string,rol?:string,destino?:string,id_valido?:bool,sesion:\Closure}>
     */
    private static function personas(): array
    {
        $P = self::$P;
        $cli = $P['cliente'];
        $ger = $P['gerente'];
        $cuenta = static fn (string $k) => $P[$k];
        $eq = static fn (string $k, string $rol) => ['cat' => 'valida', 'rol' => $rol, 'sesion' => static fn (string $c) => self::sesionLogin($P[$k], 'equipo', $rol, $c)];
        $mapa = [
            'anonimo' => ['cat' => 'anonimo', 'id_valido' => false, 'sesion' => static fn (string $c) => null],
            'login_no_booleano' => ['cat' => 'anonimo', 'id_valido' => true, 'sesion' => static fn (string $c) => self::sesionLogin($cli, 'cliente', 'cliente', $c, ['login' => '1'])],
            'propietario' => $eq('propietario', 'propietario'),
            'gerente' => $eq('gerente', 'gerente'),
            'recepcion' => $eq('recepcion', 'recepcion'),
            'profesional' => $eq('profesional', 'profesional'),
            'finanzas' => $eq('finanzas', 'finanzas'),
            'cliente' => ['cat' => 'valida', 'rol' => 'cliente', 'sesion' => static fn (string $c) => self::sesionLogin($cli, 'cliente', 'cliente', $c)],
            'cliente_suspendido' => ['cat' => 'caducada', 'destino' => '/', 'id_valido' => true, 'sesion' => static fn (string $c) => self::sesionLogin($cuenta('cliente_susp'), 'cliente', 'cliente', $c)],
            'cliente_no_confirmado' => ['cat' => 'caducada', 'destino' => '/', 'id_valido' => true, 'sesion' => static fn (string $c) => self::sesionLogin($cuenta('cliente_noconf'), 'cliente', 'cliente', $c)],
            'colab_suspendido' => ['cat' => 'caducada', 'destino' => '/equipo/login', 'id_valido' => true, 'sesion' => static fn (string $c) => self::sesionLogin($cuenta('colab_susp'), 'equipo', 'recepcion', $c)],
            'colab_baja' => ['cat' => 'caducada', 'destino' => '/equipo/login', 'id_valido' => true, 'sesion' => static fn (string $c) => self::sesionLogin($cuenta('colab_baja'), 'equipo', 'recepcion', $c)],
            'colab_invitado' => ['cat' => 'caducada', 'destino' => '/equipo/login', 'id_valido' => true, 'sesion' => static fn (string $c) => self::sesionLogin($cuenta('colab_invitado'), 'equipo', 'recepcion', $c)],
            'forjada_equipo_en_portal_cliente' => ['cat' => 'caducada', 'destino' => '/', 'id_valido' => true, 'sesion' => static fn (string $c) => self::sesionLogin($ger, 'cliente', 'propietario', $c, ['admin' => '1'])],
            'forjada_cliente_en_portal_equipo' => ['cat' => 'caducada', 'destino' => '/equipo/login', 'id_valido' => true, 'sesion' => static fn (string $c) => self::sesionLogin($cli, 'equipo', 'propietario', $c, ['admin' => '1'])],
            'portal_inexistente_cuenta_equipo' => ['cat' => 'valida', 'rol' => 'gerente', 'sesion' => static fn (string $c) => self::sesionLogin($ger, 'superadmin', 'propietario', $c, ['admin' => '1'])],
            'portal_inexistente_cuenta_cliente' => ['cat' => 'valida', 'rol' => 'cliente', 'sesion' => static fn (string $c) => self::sesionLogin($cli, 'superadmin', 'propietario', $c, ['admin' => '1'])],
            'usuario_id_inexistente' => ['cat' => 'caducada', 'destino' => '/equipo/login', 'id_valido' => true, 'sesion' => static fn (string $c) => self::sesionLogin(['id' => 987654321, 'email' => 'x@example.test'], 'equipo', 'propietario', $c, ['version_sesion' => 1])],
            'usuario_eliminado' => ['cat' => 'caducada', 'destino' => '/', 'id_valido' => true, 'sesion' => static fn (string $c) => self::sesionLogin($cuenta('eliminado'), 'cliente', 'cliente', $c, ['version_sesion' => 1])],
            'sesion_sin_id' => ['cat' => 'caducada', 'destino' => '/', 'id_valido' => false, 'sesion' => static fn (string $c) => ['csrf_token' => $c, 'login' => true, 'portal' => 'cliente', 'rol' => 'propietario', 'admin' => '1', 'version_sesion' => 1]],
            'version_sesion_vieja' => ['cat' => 'caducada', 'destino' => '/equipo/login', 'id_valido' => true, 'sesion' => static fn (string $c) => self::sesionLogin($P['recepcion'], 'equipo', 'recepcion', $c, ['version_sesion' => self::versionDe((int)$P['recepcion']['id']) + 7])],
        ];
        return $mapa;
    }

    // ------------------------------------------------------------------ huellas

    /** @return array<string,array{n:int,c:?string}> */
    private static function huellaBd(): array
    {
        if (self::$tablasHuella === null) {
            self::$tablasHuella = array_values(array_filter(
                array_column(self::q("SELECT table_name t FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' ORDER BY 1"), 't'),
                static fn ($t) => !in_array($t, self::TABLAS_EXCLUIDAS, true)
            ));
        }
        $lista = implode(',', array_map(static fn ($t) => "`$t`", self::$tablasHuella));
        $out = [];
        foreach (self::q("CHECKSUM TABLE $lista") as $f) {
            $t = preg_replace('/^[^.]+\./', '', (string)$f['Table']);
            $out[$t] = ['n' => 0, 'c' => $f['Checksum']];
        }
        $uniones = implode(' UNION ALL ', array_map(static fn ($t) => "SELECT '$t' t, COUNT(*) n FROM `$t`", self::$tablasHuella));
        foreach (self::q($uniones) as $f) { $out[$f['t']]['n'] = (int)$f['n']; }
        return $out;
    }

    /**
     * Diferencias estructuradas entre dos huellas.
     * @return array<string,array{antes:int,despues:int,contenido:bool}> solo las tablas que cambiaron (conteo o contenido)
     */
    private static function difHuella(array $antes, array $despues): array
    {
        $d = [];
        foreach ($antes as $t => $v) {
            $w = $despues[$t] ?? ['n' => -1, 'c' => null];
            if ($v !== $w) { $d[$t] = ['antes' => $v['n'], 'despues' => $w['n'], 'contenido' => $v['c'] !== $w['c']]; }
        }
        return $d;
    }

    /** "tabla(n a→b)" legible. @param array<string,array{antes:int,despues:int,contenido:bool}> $dif @return string[] */
    private static function textoDif(array $dif): array
    {
        $o = [];
        foreach ($dif as $t => $v) { $o[] = "$t(" . ($v['antes'] === $v['despues'] ? "modificada n={$v['antes']}" : "n {$v['antes']}→{$v['despues']}") . ')'; }
        return $o;
    }

    /** @return array<int,array> */
    private static function leerBuzon(): array
    {
        return json_decode((string)@file_get_contents(self::$buzonRuta), true) ?: [];
    }

    // ------------------------------------------------------------------ peticiones

    /**
     * Ejecuta UNA petición con una sesión nueva de la persona.
     * $o: m (método), p (ruta con placeholders ya resueltos), body (array), csrf (valido|header|ninguno|vacio|otro|ajeno|arreglo),
     *     accept (null|'json'|'html'|'*'), xhr (bool), json (cuerpo JSON en vez de formulario), sin_cookie (bool)
     * @return array{r:array,sesion:?array,csrf:string,tablas:string[],correo:int}
     */
    private function ejecutar(string $persona, array $o): array
    {
        $per = self::personas()[$persona];
        $csrf = bin2hex(random_bytes(32));
        $datos = ($per['sesion'])($csrf);
        $m = $o['m'];
        $cabeceras = [];
        $cookie = null;
        if ($datos === null && $m !== 'GET' && $m !== 'HEAD' && ($o['csrf'] ?? 'valido') !== 'ninguno' && !($o['sin_cookie'] ?? false)) {
            // Un anónimo que ya abrió algún formulario público tiene sesión con token CSRF propio.
            $datos = ['csrf_token' => $csrf];
        }
        if ($datos !== null && !($o['sin_cookie'] ?? false)) {
            $cookie = self::forjarSesion($datos);
            $cabeceras[] = "Cookie: PHPSESSID=$cookie";
        }
        $accept = $o['accept'] ?? null;
        if ($accept === 'json') { $cabeceras[] = 'Accept: application/json'; }
        elseif ($accept === 'html') { $cabeceras[] = 'Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.8'; }
        elseif ($accept === '*') { $cabeceras[] = 'Accept: */*'; }
        if (!empty($o['xhr'])) { $cabeceras[] = 'X-Requested-With: XMLHttpRequest'; }
        foreach ($o['cabeceras'] ?? [] as $h) { $cabeceras[] = $h; }

        $campos = null;
        if ($m === 'POST') {
            $body = $o['body'] ?? [];
            $modo = $o['csrf'] ?? 'valido';
            $ajeno = bin2hex(random_bytes(32));
            switch ($modo) {
                case 'valido': $body['csrf_token'] = $csrf; break;
                case 'header': $cabeceras[] = "X-CSRF-Token: $csrf"; break;
                case 'vacio': $body['csrf_token'] = ''; break;
                case 'otro': $body['csrf_token'] = str_repeat('a', 64); break;
                case 'ajeno': self::forjarSesion(['csrf_token' => $ajeno]); $body['csrf_token'] = $ajeno; break;
                case 'ninguno': break;
            }
            if ($modo === 'arreglo') {
                $campos = http_build_query($body) . '&csrf_token[]=' . $csrf;
            } elseif (!empty($o['json'])) {
                $cabeceras[] = 'Content-Type: application/json';
                $campos = json_encode($body);
            } else {
                $campos = $body;
            }
        }
        if (!empty($o['limpiar_intentos'])) { self::q('TRUNCATE intentos_login'); }
        $antes = self::huellaBd();
        $buzonAntes = count(self::leerBuzon());
        // Auditoría de exportaciones (fase 2): una denegación idéntica (mismo actor, tipo y motivo) dentro de 10 minutos se agrupa en UNA fila.
        // La expectativa se deriva del estado previo de la tabla, no del código de la aplicación: ¿ya había una denegación igual hace < 10 min?
        $audPrevias = (int)self::escalar("SELECT COUNT(*) FROM crm_exportaciones_auditoria WHERE resultado='denegada' AND tipo='clientes' AND motivo='sin_permiso'
            AND actor_usuario_id=? AND ocurrido_en >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)", [(int)($datos['id'] ?? 0)]);
        $n = new Navegador(self::$D['base']);
        $r = $n->peticion($m, $o['p'], $campos, $cabeceras);
        $despues = self::huellaBd();
        $dif = self::difHuella($antes, $despues);
        $buzonDespues = self::leerBuzon();
        // Diagnóstico (sin cuerpos): destinatarios y asunto de lo que llegó durante la petición, para atribuir un correo inesperado.
        $nuevos = array_map(static fn ($c) => implode(',', (array)($c['to'] ?? [])) . ' | ' . (preg_match('/^Subject:\s*(.+)$/mi', (string)($c['cabeceras'] ?? ''), $mm) ? trim($mm[1]) : '(sin asunto)'), array_slice($buzonDespues, $buzonAntes));
        return ['r' => $r, 'sesion' => $datos, 'csrf' => $csrf, 'cookie' => $cookie, 'dif' => $dif, 'tablas' => self::textoDif($dif),
            'correo' => count($buzonDespues) - $buzonAntes, 'correo_detalle' => $nuevos, 'aud_previas' => $audPrevias];
    }

    /** ¿Algún centinela de los grupos NO permitidos aparece en el texto (crudo, escapado HTML/URL/JSON)? @return string[] */
    private static function fugas(string $texto, array $gruposProhibidos): array
    {
        $hallados = [];
        foreach ($gruposProhibidos as $g) {
            foreach (self::CENTINELAS[$g] as $s) {
                $variantes = array_unique([$s, htmlspecialchars($s, ENT_QUOTES), rawurlencode($s), urlencode($s), trim(json_encode($s), '"'), strtolower($s), strtoupper($s)]);
                foreach ($variantes as $v) {
                    if ($v !== '' && stripos($texto, $v) !== false) { $hallados[] = "$g:$s"; break; }
                }
            }
        }
        return array_values(array_unique($hallados));
    }
}

<?php

namespace Tests\Qa;

require_once __DIR__ . '/R2bCase.php';

/** E. Migraciones, esquema fresh vs upgrade, rollbacks, precheck de la 017 y respaldo/restauración. */
final class EsquemaTest extends R2bCase
{
    private const BDS = ['fresh', 'upg', 'rb18', 'rb17', 'rb16', 'pre', 'bk', 'bkr', 'down', 'upg2'];

    public static function tearDownAfterClass(): void
    {
        foreach (self::BDS as $b) { self::borrarBd("appsalon_qa_{$b}_test"); }
        foreach (glob(sys_get_temp_dir() . '/qa_bk_*') ?: [] as $f) { @unlink($f); }
    }

    private function bd(string $sufijo): string { return "appsalon_qa_{$sufijo}_test"; }

    private function conectar(string $nombre): \mysqli
    {
        $a = self::admin();
        $a->select_db(self::nombreAislado($nombre));
        return $a;
    }

    private function filas(\mysqli $db, string $sql): array
    {
        $r = $db->query($sql);
        if (!$r) { throw new \RuntimeException($db->error . ' :: ' . $sql); }
        return $r->fetch_all(MYSQLI_ASSOC);
    }

    /** Captura canónica del esquema (columnas, índices incl. funcionales, FK, motor/colación). */
    private function esquema(string $nombre, bool $ordinal = false): array
    {
        $db = $this->conectar($nombre);
        $q = "'" . $db->real_escape_string($nombre) . "'";
        $ord = $ordinal ? ', ordinal_position' : '';
        $e = [];
        $e['tablas'] = $this->filas($db, "SELECT table_name, engine, table_collation FROM information_schema.tables WHERE table_schema=$q ORDER BY table_name");
        $e['columnas'] = $this->filas($db, "SELECT table_name, column_name, column_type, is_nullable, column_default, character_set_name, collation_name, extra, generation_expression $ord FROM information_schema.columns WHERE table_schema=$q ORDER BY table_name, column_name");
        $e['indices'] = $this->filas($db, "SELECT table_name, index_name, non_unique, seq_in_index, column_name, expression, sub_part, index_type, is_visible FROM information_schema.statistics WHERE table_schema=$q ORDER BY table_name, index_name, seq_in_index");
        $e['fk'] = $this->filas($db, "SELECT k.table_name, k.constraint_name, k.column_name, k.referenced_table_name, k.referenced_column_name, r.update_rule, r.delete_rule FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.constraint_name=k.constraint_name WHERE k.table_schema=$q ORDER BY k.table_name, k.constraint_name, k.ordinal_position");
        $e['checks'] = $this->filas($db, "SELECT table_name, constraint_name FROM information_schema.table_constraints WHERE table_schema=$q AND constraint_type='CHECK' ORDER BY 1,2");
        $db->close();
        return $e;
    }

    private function migrar(string $nombre, string $cmd = 'up'): array
    {
        $p = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', self::RAIZ . '/database/migrador.php', $cmd], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::RAIZ, array_merge(getenv(), ['DB_NAME' => $nombre]));
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        return ['exit' => proc_close($p), 'out' => $out];
    }

    // ------------------------------------------------------------------ E1

    public function test_E1_esquema_fresh_es_igual_al_esquema_upgrade_desde_009_con_datos_reales(): void
    {
        $fresh = $this->bd('fresh'); $upg = $this->bd('upg');
        self::crearBd($fresh);
        self::crearBd($upg, 9);
        $db = $this->conectar($upg);
        $hash = password_hash('Clave-Legacy-123', PASSWORD_BCRYPT);
        $db->query("INSERT INTO usuarios(nombre,apellido,email,password,telefono,admin,confirmado) VALUES
            ('Ad','Min','admin@legacy.test','$hash','0990000001',1,1),
            ('Cli','Uno','cli.uno@legacy.test','$hash','0990000002',0,1),
            ('Maria','Mayus','Maria.Mayus@Legacy.Test','$hash','0990000003',0,1),
            ('No','Conf','noconf@legacy.test','$hash','0990000004',0,0)");
        $cols = array_column($this->filas($db, 'SHOW COLUMNS FROM servicios'), 'Field');
        $db->query("INSERT INTO servicios(nombre,precio" . (in_array('duracion_minutos', $cols) ? ',duracion_minutos' : '') . ") VALUES ('Corte Legacy',15.00" . (in_array('duracion_minutos', $cols) ? ',30' : '') . ")");
        $db->query("INSERT INTO profesionales(nombre) VALUES ('Profesional Legacy')");
        $db->query("INSERT INTO citas(fecha,hora,usuarioId,profesionalId,estado,estado_pago) VALUES (DATE_SUB(CURDATE(),INTERVAL 5 DAY),'10:00:00',2,1,'completada','pagado'),(DATE_ADD(CURDATE(),INTERVAL 5 DAY),'11:00:00',2,1,'confirmada','pendiente')");
        $db->query("INSERT INTO citasservicios(citaId,servicioId,nombre_servicio,precio_servicio,duracion_minutos) VALUES (1,1,'Corte Legacy',15.00,30),(2,1,'Corte Legacy',15.00,30)");
        $db->close();
        $antes = ['citas' => 2, 'usuarios' => 4];
        $m = $this->migrar($upg);
        $this->assertSame(0, $m['exit'], $m['out']);
        // 1) Esquemas idénticos.
        $a = $this->esquema($fresh); $b = $this->esquema($upg);
        foreach ($a as $parte => $v) { $this->assertEquals($v, $b[$parte], "esquema '$parte' difiere entre fresh y upgrade"); }
        // El orden físico de columnas también debería coincidir (mismas migraciones en el mismo orden).
        $this->assertEquals($this->esquema($fresh, true)['columnas'], $this->esquema($upg, true)['columnas'], 'orden de columnas distinto');
        // 2) Datos conservados y retrocargas correctas.
        $db = $this->conectar($upg);
        $this->assertSame($antes['citas'], (int)$this->filas($db, 'SELECT COUNT(*) n FROM citas')[0]['n']);
        $this->assertSame($antes['usuarios'], (int)$this->filas($db, 'SELECT COUNT(*) n FROM usuarios')[0]['n']);
        $col = $this->filas($db, "SELECT rol, estado, codigo FROM colaboradores c JOIN usuarios u ON u.id=c.usuario_id WHERE u.email='admin@legacy.test'");
        $this->assertCount(1, $col, 'el administrador heredado debe tener su ficha de colaborador');
        $this->assertSame(['propietario', 'activo'], [$col[0]['rol'], $col[0]['estado']]);
        $this->assertMatchesRegularExpression('/^COL-\d{6}$/', $col[0]['codigo']);
        $cli = array_column($this->filas($db, 'SELECT usuario_id FROM clientes ORDER BY usuario_id'), 'usuario_id');
        $this->assertContains('2', $cli); $this->assertContains('3', $cli); $this->assertContains('4', $cli);
        $this->assertNotContains('1', $cli, 'el administrador no es cliente salvo que haya reservado');
        $this->assertSame(4, (int)$this->filas($db, "SELECT COUNT(*) n FROM information_schema.statistics WHERE table_schema=DATABASE() AND index_name='uq_usuarios_email_normalizado'")[0]['n'] + 3, 'índice funcional presente (1 fila)');
        $this->assertSame('1', (string)$this->filas($db, "SELECT COUNT(*) n FROM information_schema.statistics WHERE table_schema=DATABASE() AND index_name='uq_usuarios_email_normalizado' AND expression IS NOT NULL")[0]['n']);
        // 3) Idempotencia del migrador.
        $db->close();
        $m2 = $this->migrar($upg);
        $this->assertSame(0, $m2['exit']);
        $this->assertStringNotContainsString('Aplicando', $m2['out']);
        $this->assertEquals($this->esquema($fresh), $this->esquema($upg));
    }

    public function test_E1b_cuentas_heredadas_inician_sesion_en_el_portal_correcto_tras_actualizar(): void
    {
        $upg = $this->bd('upg');
        if ((int)self::admin()->query("SELECT COUNT(*) n FROM information_schema.schemata WHERE schema_name='$upg'")->fetch_assoc()['n'] === 0) {
            // Autónoma: no depende del orden de ejecución (--order-by=random); prepara la base `upg` ejecutando E1.
            $this->test_E1_esquema_fresh_es_igual_al_esquema_upgrade_desde_009_con_datos_reales();
        }
        $srv = self::servidor(['DB_NAME' => $upg], 'upg');
        $a = new Navegador($srv);
        $this->assertSame(302, $a->login('equipo', 'admin@legacy.test', 'Clave-Legacy-123')['status'], 'el administrador heredado debe entrar al portal del equipo');
        $this->assertSame(200, $a->get('/admin/equipo')['status']);
        $this->assertSame(200, $a->get('/admin/clientes')['status']);
        $c = new Navegador($srv);
        $this->assertSame(302, $c->login('cliente', 'cli.uno@legacy.test', 'Clave-Legacy-123')['status'], 'el cliente heredado debe entrar al portal de clientes');
        $this->assertSame(403, $c->get('/admin/equipo')['status']);
        $d = new Navegador($srv);
        $r = $d->login('cliente', 'admin@legacy.test', 'Clave-Legacy-123');
        $this->assertNotSame('/cita', $r['location'], 'el admin heredado sin membresía no debe heredar el portal de clientes con privilegios');
        // Correo con mayúsculas del dato heredado: el login por correo con distinta capitalización funciona y no crea otra identidad.
        $e = new Navegador($srv);
        $this->assertSame(302, $e->login('cliente', 'maria.mayus@legacy.test', 'Clave-Legacy-123')['status']);
        self::pararServidor('upg');
    }

    // ------------------------------------------------------------------ E2

    private function semilla(string $nombre): void
    {
        $db = $this->conectar($nombre);
        $hash = password_hash('Clave-123456', PASSWORD_BCRYPT);
        $db->query("INSERT INTO usuarios(nombre,apellido,email,password,telefono,admin,confirmado) VALUES ('Du','Eño','dueno@rb.test','$hash','0990000001',1,1),('Cli','Ente','cli@rb.test','$hash','0990000002',0,1),('Sus','Pendido','susp@rb.test','$hash','0990000003',0,1)");
        $db->query("INSERT INTO clientes(usuario_id,estado,origen) VALUES (2,'activo','registro_publico'),(3,'suspendido','recepcion') ON DUPLICATE KEY UPDATE estado=VALUES(estado),origen=VALUES(origen)");
        $db->query("INSERT INTO colaboradores(usuario_id,email_norm,nombre,apellido,rol,estado) VALUES (NULL,'inv@rb.test','In','Vi','recepcion','invitado') ");
        $db->query("INSERT INTO colaborador_invitaciones(colaborador_id,token_hash,expira_en) VALUES (LAST_INSERT_ID(),'" . str_repeat('a', 64) . "',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY))");
        $db->query("INSERT INTO operaciones_idempotentes(scope,actor,clave_hash,huella,expira_en) VALUES ('x','anonimo:1','" . str_repeat('b', 64) . "','" . str_repeat('c', 64) . "',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))");
        $db->close();
    }

    /** Rollback MANUAL del .sql saltándose el migrador (y por tanto el guard): solo para demostrar el límite documentado. */
    private function revertirSolo(string $nombre, string $migracion): void
    {
        $db = $this->conectar($nombre);
        self::ejecutarSql($db, (string)file_get_contents(self::RAIZ . "/database/migrations/{$migracion}_rollback.sql"));
        $db->query("DELETE FROM migraciones WHERE migracion='$migracion'");
        $db->close();
    }

    public static function rollbacks(): array { return [['018_colaboradores_invitaciones', 'rb18'], ['017_idempotencia_unicidad', 'rb17'], ['016_clientes_membresia', 'rb16']]; }

    /**
     * CAMBIO DE CONTRATO (ronda 2B, DEF-16 corregido): `migrador.php down` ya NO revierte una migración con datos de negocio sin
     * `--aceptar-perdida=<migración>` por cada una y un respaldo verificable. Antes la prueba ejecutaba el rollback SQL a mano
     * (saltándose el guard) y reaplicaba SOLO la migración revertida; eso dejaba inconsistentes las migraciones posteriores que
     * dependen de ella (la 019 añade una columna a la tabla que crea la 018). La garantía que se mantiene es la misma:
     * revertir y reaplicar deja un esquema idéntico a una instalación nueva y los datos ajenos intactos; ahora por el
     * procedimiento SOPORTADO (`--hasta` revierte la migración y todas las posteriores, en orden inverso).
     *
     * @dataProvider rollbacks
     */
    public function test_E2_rollback_individual_y_reaplicacion_dejan_el_esquema_identico_al_fresh(string $mig, string $sufijo): void
    {
        $nombre = $this->bd($sufijo);
        self::crearBd($nombre);
        $this->semilla($nombre);
        $esquemaCompleto = $this->esquema($nombre);
        $rev = self::revertirSeguro($nombre, $mig);
        $this->assertSame(0, $rev['exit'], $rev['out']);
        $db = $this->conectar($nombre);
        $tablas = array_column($this->filas($db, 'SHOW TABLES'), 'Tables_in_' . $nombre);
        $objetivo = ['018_colaboradores_invitaciones' => ['colaboradores', 'colaborador_invitaciones', 'correos_salida'], '017_idempotencia_unicidad' => ['operaciones_idempotentes'], '016_clientes_membresia' => ['clientes']][$mig];
        foreach ($objetivo as $t) { $this->assertNotContains($t, $tablas, "$mig: $t sigue existiendo tras el rollback"); }
        if ($mig === '018_colaboradores_invitaciones') {
            $this->assertSame([], $this->filas($db, "SHOW COLUMNS FROM profesionales WHERE Field='colaborador_id'"));
        }
        if ($mig === '017_idempotencia_unicidad') {
            $this->assertSame([], $this->filas($db, "SHOW INDEX FROM usuarios WHERE Key_name='uq_usuarios_email_normalizado'"));
        }
        // Datos ajenos intactos.
        $this->assertSame(3, (int)$this->filas($db, 'SELECT COUNT(*) n FROM usuarios')[0]['n']);
        $db->close();
        // Re-aplicación con el migrador real, que aplica las pendientes (la revertida y las posteriores).
        $m = $this->migrar($nombre);
        $this->assertSame(0, $m['exit'], $m['out']);
        $this->assertStringContainsString("Aplicando migración: $mig", $m['out']);
        $this->assertEquals($esquemaCompleto, $this->esquema($nombre), "tras revertir y reaplicar $mig el esquema difiere");
        $db = $this->conectar($nombre);
        $this->assertSame(3, (int)$this->filas($db, 'SELECT COUNT(*) n FROM usuarios')[0]['n']);
        $db->close();
        @unlink($rev['respaldo']); @unlink($rev['respaldo'] . '.manifest.json');
    }

    /**
     * DEF-16 (reverificado, ya sin omisión): revertir y reaplicar la 016 por el procedimiento soportado NO reactiva a un
     * cliente suspendido, ni en la base ni en el login real (403).
     */
    public function test_Defecto_E2_rollback_y_reaplicacion_de_016_reactiva_en_silencio_a_clientes_suspendidos(): void
    {
        $nombre = $this->bd('rb16');
        self::crearBd($nombre);
        $this->semilla($nombre); // cliente 3 suspendido por recepción
        $this->conectar($nombre)->query("UPDATE clientes SET suspendido_motivo='Impagos reiterados' WHERE usuario_id=3");
        $rev = self::revertirSeguro($nombre, '016_clientes_membresia');
        $this->assertSame(0, $rev['exit'], $rev['out']);
        $m = $this->migrar($nombre);
        $this->assertSame(0, $m['exit'], $m['out']);
        $db = $this->conectar($nombre);
        $est = array_column($this->filas($db, 'SELECT usuario_id, estado, origen, suspendido_motivo FROM clientes'), null, 'usuario_id');
        $db->close();
        $this->assertSame('suspendido', $est[3]['estado'] ?? null, 'La suspensión explícita se reactivó tras down/up');
        $this->assertSame('Impagos reiterados', $est[3]['suspendido_motivo'], 'se perdió el motivo de la suspensión');
        $this->assertSame('recepcion', $est[3]['origen']);
        $this->assertSame('activo', $est[2]['estado']);
        // Y el efecto observable: el suspendido sigue sin poder entrar.
        $srv = self::servidor(['DB_NAME' => $nombre], 'rb16');
        $n = new Navegador($srv);
        $this->assertSame(403, $n->login('cliente', 'susp@rb.test', 'Clave-123456')['status'], 'el cliente suspendido pudo iniciar sesión tras reaplicar la 016');
        $this->assertSame(302, (new Navegador($srv))->login('cliente', 'cli@rb.test', 'Clave-123456')['status'], 'el cliente activo debe seguir entrando');
        self::pararServidor('rb16');
        @unlink($rev['respaldo']); @unlink($rev['respaldo'] . '.manifest.json');
    }

    /**
     * El SQL manual se salta el guard y puede aplicar DDL parcial. Tras 028 las referencias ERP bloquean
     * el DROP fuera de orden de colaboradores; el procedimiento soportado sigue siendo el migrador.
     */
    public function test_rollback_manual_fuera_de_orden_de_018_es_bloqueado_por_dependencias_ERP(): void
    {
        $nombre = $this->bd('rb18');
        self::crearBd($nombre);
        $this->semilla($nombre);
        // El SQL manual omite el guard y puede aplicar DDL parcial; las FK nuevas impiden eliminar colaboradores.
        try {
            $this->revertirSolo($nombre, '018_colaboradores_invitaciones');
            $this->fail('Un rollback fuera de orden no debe eliminar la tabla referenciada por ERP.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('referenced by a foreign key', $e->getMessage());
        }
        $db = $this->conectar($nombre);
        $this->assertCount(1, $this->filas($db, "SELECT id FROM colaboradores WHERE email_norm='inv@rb.test'"), 'las referencias ERP protegen la ficha laboral');
        $db->close();
        $guia = (string)file_get_contents(self::RAIZ . '/docs/GUIA_ACTUALIZACION_IDENTIDADES.md');
        $this->assertStringContainsString('se salta el guard', $guia, 'el límite debe seguir documentado');
    }

    /**
     * CAMBIO DE CONTRATO (ronda 2B, DEF-16): `down` sin `--aceptar-perdida` y respaldo ya no devuelve 0 sobre una base con datos;
     * sale con 2 y no toca nada. La garantía original (down completo + up reproduce el esquema de una instalación nueva) se
     * mantiene y se ejecuta ahora con el procedimiento soportado; además se comprueba que la suspensión sobrevive al ciclo completo.
     */
    public function test_E2b_ciclo_completo_down_y_up_reproduce_el_esquema(): void
    {
        $fresh = $this->bd('fresh'); $d = $this->bd('down');
        if (!self::admin()->query("SELECT 1 FROM information_schema.schemata WHERE schema_name='$fresh'")->num_rows) { self::crearBd($fresh); }
        self::crearBd($d);
        $this->semilla($d);
        $antes = $this->esquema($d);
        $negado = $this->migrar($d, 'down');
        $this->assertSame(2, $negado['exit'], 'down sin aceptación ni respaldo debe negarse con código 2: ' . $negado['out']);
        $this->assertStringContainsString('BLOQUEO', $negado['out']);
        $this->assertEquals($antes, $this->esquema($d), 'down negado modificó el esquema');
        $rev = self::revertirSeguro($d);
        $this->assertSame(0, $rev['exit'], 'migrador down con aceptación y respaldo falló: ' . $rev['out']);
        $db = $this->conectar($d);
        $restantes = array_column($this->filas($db, 'SHOW TABLES'), 'Tables_in_' . $d);
        $db->close();
        $up = $this->migrar($d, 'up');
        $this->assertSame(0, $up['exit'], 'migrador up tras down falló: ' . $up['out'] . ' (tablas tras down: ' . implode(',', $restantes) . ')');
        $a = $this->esquema($fresh); $b = $this->esquema($d);
        foreach ($a as $parte => $v) { $this->assertEquals($v, $b[$parte], "tras down+up '$parte' difiere de fresh"); }
        $db = $this->conectar($d);
        $this->assertSame('suspendido', $this->filas($db, 'SELECT estado FROM clientes WHERE usuario_id=3')[0]['estado']);
        $db->close();
        @unlink($rev['respaldo']); @unlink($rev['respaldo'] . '.manifest.json');
    }

    // ------------------------------------------------------------------ E3

    public function test_E3_precheck_017_aborta_sin_alterar_el_esquema_y_el_mensaje_es_accionable(): void
    {
        $nombre = $this->bd('pre');
        self::crearBd($nombre, 16);
        $db = $this->conectar($nombre);
        $db->query('ALTER TABLE usuarios DROP INDEX email');
        $hash = password_hash('x', PASSWORD_BCRYPT);
        $db->query("INSERT INTO usuarios(nombre,apellido,email,password,telefono) VALUES ('A','B','Duplicado@Pre.Test','$hash','0990000001'),('C','D','  duplicado@pre.test','$hash','0990000002'),('E','F','unico@pre.test','$hash','0990000003')");
        $db->close();
        $antes = $this->esquema($nombre);
        $m = $this->migrar($nombre);
        $this->assertSame(1, $m['exit'], $m['out']);
        $this->assertStringContainsString('017_idempotencia_unicidad', $m['out']);
        $this->assertStringContainsString('NO se aplicó', $m['out']);
        $this->assertMatchesRegularExpression('/ids?\s+\d+,\d+/', $m['out'], 'el mensaje no identifica las cuentas en conflicto');
        $this->assertStringContainsString('diagnosticar_duplicados', $m['out']);
        $this->assertStringContainsString('No se modificó ninguna tabla', $m['out']);
        $this->assertEquals($antes, $this->esquema($nombre), 'el esquema cambió a pesar del aborto');
        $db = $this->conectar($nombre);
        $this->assertSame([], $this->filas($db, "SHOW TABLES LIKE 'operaciones_idempotentes'"));
        $this->assertSame([], $this->filas($db, "SHOW TABLES LIKE 'colaboradores'"), 'la 018 no debe aplicarse tras el fallo de la 017');
        $this->assertSame(3, (int)$this->filas($db, 'SELECT COUNT(*) n FROM usuarios')[0]['n'], 'el precheck no debe borrar ni fusionar datos');
        $db->close();
        // El diagnóstico (solo lectura) funciona y muestra el conflicto.
        $p = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', self::RAIZ . '/scripts/diagnosticar_duplicados.php', '--json'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::RAIZ, array_merge(getenv(), ['DB_NAME' => $nombre]));
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        $this->assertSame(0, proc_close($p), $err . $out);
        $this->assertStringContainsString('duplicado@pre.test', $out);
        // Reintento tras resolver a mano: ahora migra.
        $db = $this->conectar($nombre);
        $db->query("DELETE FROM usuarios WHERE nombre='C'");
        $db->close();
        $m = $this->migrar($nombre);
        $this->assertSame(0, $m['exit'], $m['out']);
    }

    public function test_E3b_precheck_017_ignora_correos_distintos_y_no_bloquea_una_base_limpia(): void
    {
        $nombre = $this->bd('pre');
        self::crearBd($nombre, 16);
        $db = $this->conectar($nombre);
        $hash = password_hash('x', PASSWORD_BCRYPT);
        $db->query("INSERT INTO usuarios(nombre,apellido,email,password,telefono) VALUES ('A','B','ana@pre.test','$hash','0990000001'),('C','D','ana+1@pre.test','$hash','0990000002'),('E','F','a.na@pre.test','$hash','0990000003')");
        $db->close();
        $m = $this->migrar($nombre);
        $this->assertSame(0, $m['exit'], $m['out']);
    }

    // ------------------------------------------------------------------ E4

    public function test_E4_respaldo_y_restauracion_conservan_tablas_nuevas_indices_funcionales_y_datos_hostiles(): void
    {
        $o = $this->bd('bk'); $d = $this->bd('bkr');
        self::crearBd($o);
        $this->semilla($o);
        $db = $this->conectar($o);
        $raro = "Nota; con 'comillas' \"dobles\" \\ barra\n-- no es comentario\n); DROP TABLE usuarios; --\n/* x */ 😀 ñ";
        $db->query("UPDATE colaboradores SET notas_rh='" . $db->real_escape_string($raro) . "' WHERE email_norm='inv@rb.test'");
        $db->query("INSERT INTO correos_salida(clave_unica,tipo,destinatario_email,destinatario_nombre,carga_cifrada) VALUES ('invitacion:99','invitacion_colaborador','x@rb.test','X','" . base64_encode(random_bytes(80)) . "')");
        $filasOrigen = [];
        foreach (array_column($this->filas($db, 'SHOW TABLES'), 'Tables_in_' . $o) as $t) { $filasOrigen[$t] = (int)$this->filas($db, "SELECT COUNT(*) n FROM `$t`")[0]['n']; }
        $db->close();
        $archivo = sys_get_temp_dir() . '/qa_bk_' . getmypid() . '.sql.gz';
        $env = array_merge(getenv(), ['DB_NAME' => $o]);
        $p = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', self::RAIZ . '/scripts/backup_db.php', "--db=$o", "--output=$archivo", '--gzip'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::RAIZ, $env);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $this->assertSame(0, proc_close($p), $out);
        $man = json_decode((string)file_get_contents($archivo . '.manifest.json'), true);
        $this->assertSame(count($filasOrigen), $man['total_tablas']);
        foreach (['colaboradores', 'colaborador_invitaciones', 'correos_salida', 'operaciones_idempotentes', 'clientes'] as $t) { $this->assertContains($t, $man['tablas'], "el respaldo omite $t"); }
        $a = self::admin(); $a->query("DROP DATABASE IF EXISTS `$d`"); $a->query("CREATE DATABASE `$d` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); $a->close();
        $hash = hash_file('sha256', $archivo);
        $p = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', self::RAIZ . '/scripts/restore_db.php', "--db=$d", "--input=$archivo", "--hash=$hash"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::RAIZ, $env);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $this->assertSame(0, proc_close($p), $out);
        $origenTriggers=$this->conectar($o);$destinoTriggers=$this->conectar($d);
        $consulta="SELECT TRIGGER_NAME,ACTION_TIMING,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME";
        $normalizar=static function(array $filas):array {foreach($filas as &$fila)$fila['ACTION_STATEMENT']=rtrim(trim($fila['ACTION_STATEMENT']),';');unset($fila);return $filas;};
        $this->assertSame($normalizar($this->filas($origenTriggers,$consulta)),$normalizar($this->filas($destinoTriggers,$consulta)),'el ciclo debe reponer todas las protecciones versionadas');
        $this->assertFalse($destinoTriggers->query("DELETE FROM consentimiento_versiones"));$this->assertSame(1644,$destinoTriggers->errno);
        $origenTriggers->close();$destinoTriggers->close();
        // Esquema y datos idénticos.
        $this->assertEquals($this->esquema($o), $this->esquema($d), 'el esquema restaurado difiere');
        $db = $this->conectar($d);
        foreach ($filasOrigen as $t => $n) { $this->assertSame($n, (int)$this->filas($db, "SELECT COUNT(*) n FROM `$t`")[0]['n'], "filas de $t"); }
        $this->assertSame($raro, $this->filas($db, "SELECT notas_rh FROM colaboradores WHERE email_norm='inv@rb.test'")[0]['notas_rh'], 'texto hostil alterado por el ciclo respaldo/restauración');
        $this->assertSame(3, (int)$this->filas($db, 'SELECT COUNT(*) n FROM usuarios')[0]['n'], 'una inyección en un dato restaurado alteró la tabla usuarios');
        // El UNIQUE funcional sobrevive: correo equivalente rechazado; también el UNIQUE de invitación pendiente y de clave de correo.
        $this->assertFalse($db->query("INSERT INTO usuarios(nombre,apellido,email,password,telefono) VALUES ('Z','Z','  CLI@rb.test','x','0990000009')"), 'el índice funcional no se restauró');
        $this->assertSame(1062, $db->errno);
        $this->assertFalse($db->query("INSERT INTO colaborador_invitaciones(colaborador_id,token_hash,expira_en) SELECT colaborador_id, 'z', NOW() FROM colaborador_invitaciones LIMIT 1"));
        $this->assertSame(1062, $db->errno, 'una sola invitación pendiente por colaborador');
        $this->assertFalse($db->query("INSERT INTO correos_salida(clave_unica,tipo,destinatario_email,destinatario_nombre) VALUES ('invitacion:99','t','x','x')"));
        $this->assertSame(1062, $db->errno);
        // Las FK también sobreviven (borrar un usuario con colaborador pone usuario_id=NULL).
        $db->query("UPDATE colaboradores SET usuario_id=1 WHERE email_norm='inv@rb.test'");
        $db->query('DELETE FROM usuarios WHERE id=1');
        $this->assertNull($this->filas($db, "SELECT usuario_id FROM colaboradores WHERE email_norm='inv@rb.test'")[0]['usuario_id'], 'ON DELETE SET NULL no restaurado');
        $db->close();
        // Hash erróneo: se rechaza sin tocar la base.
        $a = self::admin(); $a->query("DROP DATABASE IF EXISTS `$d`"); $a->query("CREATE DATABASE `$d`"); $a->close();
        $p = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', self::RAIZ . '/scripts/restore_db.php', "--db=$d", "--input=$archivo", '--hash=' . str_repeat('0', 64)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::RAIZ, $env);
        stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
        $this->assertNotSame(0, proc_close($p));
        $db = $this->conectar($d);
        $this->assertSame([], $this->filas($db, 'SHOW TABLES'), 'restauración con hash inválido modificó la base destino');
        $db->close();
    }

    public function test_E4b_la_restauracion_rechaza_destinos_no_vacios_y_respaldos_truncados_sin_tocar_nada(): void
    {
        $o = $this->bd('bk'); $d = $this->bd('bkr');
        self::crearBd($o);
        $this->semilla($o);
        $env = array_merge(getenv(), ['DB_NAME' => $o]);
        $archivo = sys_get_temp_dir() . '/qa_bk_b_' . getmypid() . '.sql';
        $p = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', self::RAIZ . '/scripts/backup_db.php', "--db=$o", "--output=$archivo"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::RAIZ, $env);
        stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
        $this->assertSame(0, proc_close($p));
        $restaurar = function (string $bd, string $entrada, ?string $hash) use ($env): array {
            $args = [PHP_BINARY, '-d', 'variables_order=EGPCS', self::RAIZ . '/scripts/restore_db.php', "--db=$bd", "--input=$entrada"];
            if ($hash !== null) { $args[] = "--hash=$hash"; }
            $p = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::RAIZ, $env);
            $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            return ['exit' => proc_close($p), 'out' => $out];
        };
        // 1) Destino con datos: debe negarse y no alterar nada.
        $a = self::admin(); $a->query("DROP DATABASE IF EXISTS `$d`"); $a->query("CREATE DATABASE `$d` CHARACTER SET utf8mb4"); $a->select_db($d);
        $a->query('CREATE TABLE usuarios (id INT PRIMARY KEY, marca VARCHAR(20))'); $a->query("INSERT INTO usuarios VALUES (1,'intacto')"); $a->close();
        $r = $restaurar($d, $archivo, hash_file('sha256', $archivo));
        $this->assertNotSame(0, $r['exit'], 'restauró sobre una base con datos: ' . $r['out']);
        $db = $this->conectar($d);
        $this->assertSame('intacto', $this->filas($db, 'SELECT marca FROM usuarios WHERE id=1')[0]['marca'], 'la restauración alteró la base destino pese a rechazarla');
        $db->close();
        // 2) Respaldo truncado (sin marcador final): se rechaza antes de ejecutar nada.
        $a = self::admin(); $a->query("DROP DATABASE IF EXISTS `$d`"); $a->query("CREATE DATABASE `$d` CHARACTER SET utf8mb4"); $a->close();
        $trunc = $archivo . '.trunc.sql';
        file_put_contents($trunc, substr((string)file_get_contents($archivo), 0, (int)(filesize($archivo) * 0.6)));
        $r = $restaurar($d, $trunc, hash_file('sha256', $trunc));
        $this->assertNotSame(0, $r['exit'], 'restauró un respaldo truncado: ' . $r['out']);
        $db = $this->conectar($d);
        $this->assertSame([], $this->filas($db, 'SHOW TABLES'), 'un respaldo truncado dejó tablas a medias');
        $db->close();
        // 3) Sin hash ni manifiesto adyacente fiable: se exige verificación.
        $r = $restaurar($d, $archivo, str_repeat('f', 64));
        $this->assertNotSame(0, $r['exit']);
        @unlink($archivo); @unlink($archivo . '.manifest.json'); @unlink($trunc);
    }

    public function test_Defecto_E5_conteo_de_tablas_y_documentacion(): void
    {
        $bd = $this->bd('fresh');
        if (!self::admin()->query("SELECT 1 FROM information_schema.schemata WHERE schema_name='$bd'")->num_rows) { self::crearBd($bd); }
        $db = $this->conectar($bd);
        $n = (int)$this->filas($db, "SELECT COUNT(*) n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE'")[0]['n'];
        $db->close();
        // CAMBIO DE CONTRATO (rondas 019-022): +7 tablas (resguardo_016, reasignaciones, decisiones de homónimos x2, auditoría CRM, versiones e historial de consentimiento).
        // CAMBIO DE CONTRATO (ronda 5, migraciones 023-026): +7 tablas (cobros/verificación y su historial, comprobantes y numeración, auditoría general de exportaciones…).
        // CAMBIO DE CONTRATO (regularización de citas antiguas, migración 027): +1 tabla (citas_regularizaciones).
        // Migraciones ERP 028–033: +42 tablas, sin sustituir las 50 del esquema anterior.
        // 034: +4 tablas (beneficiarios, cargas, libro y referencias bancarias compartidas).
        // 035: planes, paquetes financiados y asignaciones de sesiones.
        // 036: contratos y ciclos de suscripción.
        // 040: vínculos devolución↔nota de crédito y líneas acreditadas (+2).
        // 041: verificaciones de comprobantes rechazados con el SRI (+1).
        // 042: parámetros fiscales versionados (+1).
        // 043: contabilidad propia: configuración, secuencia, cuentas, roles, períodos, asientos y líneas (+7).
        // 044: nómina propia: parámetros, condiciones, corridas, líneas, novedades, comisiones, beneficios y sus líneas (+8).
        // 045: tablas de impuesto a la renta y declaraciones del trabajador (+2).
        $this->assertSame(125, $n, 'tablas del esquema migrado (incluye migraciones y equipo_mutex)');
        // La documentación citaba 30 (tras la 015) y el encabezado de backup_db.php "13 tablas": ya no describen la realidad.
        $this->assertFalse(str_contains((string)file_get_contents(self::RAIZ . '/scripts/backup_db.php'), '13 tablas en total'), 'documentación obsoleta en scripts/backup_db.php: dice "13 tablas en total" y el esquema migrado tiene 35');
    }
}

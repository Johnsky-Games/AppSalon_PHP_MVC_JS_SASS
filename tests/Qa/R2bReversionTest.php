<?php

namespace Tests\Qa;

require_once __DIR__ . '/R2bCase.php';

/**
 * Ronda 2B, DEF-16 (rollback/reaplicación pierde datos): ataque adversarial al procedimiento COMPLETO de reversión segura.
 * `database/migrador.php down` debe NEGARSE (código 2, sin tocar nada) si destruiría datos de negocio, salvo `--aceptar-perdida=<migración>`
 * por cada una Y `--respaldo=<.sql.gz>` verificable (de esta base, esquema actual, <24 h, con al menos las filas actuales).
 * Todas las bases son aisladas `appsalon_qa_r2b*_test`.
 */
final class R2bReversionTest extends R2bCase
{
    private const BDS = ['r2brev', 'r2brev2', 'r2brevfresh', 'r2brevotra'];
    /** @var string[] */
    private array $temporales = [];

    public static function tearDownAfterClass(): void
    {
        foreach (self::BDS as $b) { self::borrarBd("appsalon_qa_{$b}_test"); }
    }

    protected function tearDown(): void
    {
        foreach ($this->temporales as $f) { @unlink($f); @unlink($f . '.manifest.json'); }
        $this->temporales = [];
    }

    private function bd(string $s): string { return "appsalon_qa_{$s}_test"; }

    private function conectar(string $nombre): \mysqli
    {
        $a = self::admin();
        $a->select_db(self::nombreAislado($nombre));
        return $a;
    }

    private function filas(string $nombre, string $sql): array
    {
        $db = $this->conectar($nombre);
        $r = $db->query($sql);
        if (!$r) { $e = $db->error; $db->close(); throw new \RuntimeException($e . ' :: ' . $sql); }
        $f = $r->fetch_all(MYSQLI_ASSOC);
        $db->close();
        return $f;
    }

    private function sql(string $nombre, string $sql): void
    {
        $db = $this->conectar($nombre);
        if (!$db->query($sql)) { $e = $db->error; $db->close(); throw new \RuntimeException($e . ' :: ' . $sql); }
        $db->close();
    }

    /** Base completa con datos que bloquean 016, 017 y 018 (y una cita/pago para el resto). */
    private function preparar(string $s): string
    {
        $nombre = $this->bd($s);
        self::crearBd($nombre);
        $hash = password_hash('Clave-123456', PASSWORD_BCRYPT);
        $db = $this->conectar($nombre);
        $db->query("INSERT INTO usuarios(nombre,apellido,email,password,telefono,admin,confirmado) VALUES ('Du','Eño','dueno@rv.test','$hash','0990000001',1,1),('Cli','Ente','cli@rv.test','$hash','0990000002',0,1),('Sus','Pendido','susp@rv.test','$hash','0990000003',0,1)");
        $db->query("INSERT INTO clientes(usuario_id,estado,origen,suspendido_motivo) VALUES (2,'activo','registro_publico',NULL),(3,'suspendido','recepcion','Impagos') ON DUPLICATE KEY UPDATE estado=VALUES(estado),origen=VALUES(origen),suspendido_motivo=VALUES(suspendido_motivo)");
        $db->query("INSERT INTO colaboradores(usuario_id,email_norm,nombre,apellido,rol,estado) VALUES (NULL,'inv@rv.test','In','Vi','recepcion','invitado')");
        $db->query("INSERT INTO colaborador_invitaciones(colaborador_id,token_hash,expira_en) VALUES (LAST_INSERT_ID(),'" . str_repeat('a', 64) . "',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY))");
        $db->query("INSERT INTO operaciones_idempotentes(scope,actor,clave_hash,huella,expira_en) VALUES ('x','anonimo:1','" . str_repeat('b', 64) . "','" . str_repeat('c', 64) . "',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))");
        $db->close();
        // Ronda 5: las migraciones 023-026 son posteriores a la 022. Estas pruebas tratan la reversión de la 022, así que se deja la base en
        // la 022 revirtiendo primero las nuevas (sin datos de negocio, su reversión es segura y completa).
        $r = $this->down($nombre, ['--hasta=023_atencion_citas']);
        if ($r['exit'] !== 0) { throw new \RuntimeException('No se pudo dejar la base en la migración 022: ' . $r['out']); }
        return $nombre;
    }

    /** Huella total (esquema + contenido + control de migraciones) para probar que «no se tocó nada». */
    private function huellaTotal(string $nombre): string
    {
        $db = $this->conectar($nombre);
        $o = [];
        foreach ($db->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetch_all() as [$t]) {
            $o[$t] = [$db->query("CHECKSUM TABLE `$t`")->fetch_assoc()['Checksum'], $db->query("SELECT COUNT(*) n FROM `$t`")->fetch_assoc()['n']];
            $cols = $db->query("SELECT GROUP_CONCAT(column_name,column_type ORDER BY ordinal_position) c FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='$t'")->fetch_assoc()['c'];
            $o[$t][] = md5((string)$cols);
        }
        $o['__triggers'] = $db->query('SELECT GROUP_CONCAT(trigger_name ORDER BY trigger_name) t FROM information_schema.triggers WHERE trigger_schema=DATABASE()')->fetch_assoc()['t'];
        $db->close();
        ksort($o);
        return md5(json_encode($o));
    }

    private function ruta(string $nombre): string
    {
        $r = sys_get_temp_dir() . '/qa_r2brev_' . getmypid() . '_' . $nombre . '_' . bin2hex(random_bytes(2)) . '.sql.gz';
        $this->temporales[] = $r;
        return $r;
    }

    private function respaldo(string $bd): string { $r = $this->ruta($bd); self::respaldar($bd, $r); return $r; }

    private function manifiesto(string $respaldo, ?callable $cambio = null): array
    {
        $m = json_decode((string)file_get_contents($respaldo . '.manifest.json'), true);
        if ($cambio) { $m = $cambio($m); file_put_contents($respaldo . '.manifest.json', json_encode($m)); }
        return $m;
    }

    private function down(string $bd, array $args): array { return self::migradorCmd($bd, array_merge(['down'], $args)); }

    private function aceptarTodo(array $nombres): array { return array_map(static fn(string $n): string => "--aceptar-perdida=$n", $nombres); }

    // ------------------------------------------------------------------ negativa sin tocar nada

    public function test_down_sin_opciones_se_niega_con_codigo_2_y_no_cambia_ni_un_byte(): void
    {
        $bd = $this->preparar('r2brev');
        $antes = $this->huellaTotal($bd);
        $r = $this->down($bd, []);
        $this->assertSame(2, $r['exit'], $r['out']);
        $this->assertStringContainsString('[BLOQUEO]', $r['out']);
        foreach (['016_clientes_membresia', '017_idempotencia_unicidad', '018_colaboradores_invitaciones'] as $m) { $this->assertStringContainsString($m, $r['out'], "no menciona $m"); }
        $this->assertSame($antes, $this->huellaTotal($bd), 'down negado modificó la base');
        // --simular no modifica, no ejecuta y devuelve 2 mientras falte algo.
        $s = $this->down($bd, ['--simular']);
        $this->assertSame(2, $s['exit']);
        $this->assertStringContainsString('SIMULACIÓN', $s['out']);
        $this->assertSame($antes, $this->huellaTotal($bd));
    }

    public function test_aceptacion_parcial_o_sin_respaldo_o_con_aceptacion_de_una_migracion_ajena_se_niega_sin_tocar_nada(): void
    {
        $bd = $this->preparar('r2brev');
        $antes = $this->huellaTotal($bd);
        $respaldo = $this->respaldo($bd);
        $bloq = self::migracionesBloqueantes($bd);
        $this->assertEqualsCanonicalizing(['016_clientes_membresia', '017_idempotencia_unicidad', '018_colaboradores_invitaciones'], $bloq);
        // Aceptar solo algunas (con respaldo bueno): se niega y nombra las que faltan.
        $r = $this->down($bd, ['--aceptar-perdida=018_colaboradores_invitaciones', '--aceptar-perdida=017_idempotencia_unicidad', "--respaldo=$respaldo"]);
        $this->assertSame(2, $r['exit'], $r['out']);
        $this->assertStringContainsString('--aceptar-perdida=016_clientes_membresia', $r['out']);
        $this->assertSame($antes, $this->huellaTotal($bd));
        // Aceptar todas SIN respaldo.
        $r = $this->down($bd, $this->aceptarTodo($bloq));
        $this->assertSame(2, $r['exit'], $r['out']);
        $this->assertStringContainsString('Falta --respaldo', $r['out']);
        $this->assertSame($antes, $this->huellaTotal($bd));
        // Aceptar una migración que no existe / mal escrita / de otra: error de uso (1) sin tocar nada.
        foreach (['--aceptar-perdida=999_inexistente', '--aceptar-perdida=016', '--aceptar-perdida=../../etc/passwd', '--aceptar-perdida=', '--aceptar-perdida=016_clientes_membresia;DROP', '--sorpresa'] as $malo) {
            $r = $this->down($bd, array_merge($this->aceptarTodo($bloq), ["--respaldo=$respaldo", $malo]));
            $this->assertSame(1, $r['exit'], "$malo => " . $r['out']);
            $this->assertSame($antes, $this->huellaTotal($bd), "$malo modificó la base");
        }
        // Todo correcto pero el respaldo es una ruta inexistente / un directorio / vacío.
        foreach (['/no/existe.sql.gz', sys_get_temp_dir(), ''] as $ruta) {
            $r = $this->down($bd, array_merge($this->aceptarTodo($bloq), ["--respaldo=$ruta"]));
            $this->assertContains($r['exit'], [1, 2], "respaldo «{$ruta}»: " . $r['out']);
            $this->assertNotSame(0, $r['exit']);
            $this->assertSame($antes, $this->huellaTotal($bd));
        }
    }

    // ------------------------------------------------------------------ respaldo falsificado / alterado / viejo / ajeno

    public function test_respaldos_invalidos_se_rechazan_antes_de_tocar_nada(): void
    {
        $bd = $this->preparar('r2brev');
        $otra = $this->preparar('r2brev2');
        $antes = $this->huellaTotal($bd);
        $bloq = self::migracionesBloqueantes($bd);
        $casos = [];

        $a = $this->respaldo($bd); unlink($a . '.manifest.json');
        $casos['sin manifiesto'] = [$a, 'Falta el manifiesto'];

        $a = $this->respaldo($bd); file_put_contents($a . '.manifest.json', '{no es json');
        $casos['manifiesto no JSON'] = [$a, 'no es JSON válido'];

        $a = $this->respaldo($bd); file_put_contents($a, "\0", FILE_APPEND);
        $casos['archivo alterado (bytes añadidos)'] = [$a, 'SHA-256'];

        $a = $this->respaldo($bd); file_put_contents($a, substr((string)file_get_contents($a), 0, 40));
        $casos['archivo truncado'] = [$a, 'SHA-256'];

        $a = $this->respaldo($bd); $this->manifiesto($a, function (array $m): array { $m['sha256'] = str_repeat('0', 64); return $m; });
        $casos['sha256 del manifiesto falso'] = [$a, 'SHA-256'];

        $a = $this->respaldo($bd); $this->manifiesto($a, function (array $m): array { $m['sha256_sql'] = str_repeat('1', 64); return $m; });
        $casos['sha256_sql falso'] = [$a, 'SQL descomprimido'];

        $a = $this->respaldo($bd); $this->manifiesto($a, function (array $m): array { $m['fecha'] = date('c', time() - 25 * 3600); return $m; });
        $casos['respaldo de hace 25 h'] = [$a, 'más de 24 horas'];

        $a = $this->respaldo($bd); $this->manifiesto($a, function (array $m): array { $m['fecha'] = date('c', time() + 3 * 3600); return $m; });
        $casos['fecha futura'] = [$a, 'fecha futura'];

        $a = $this->respaldo($bd); $this->manifiesto($a, function (array $m): array { $m['fecha'] = 'ayer'; return $m; });
        $casos['fecha ilegible'] = [$a, 'fecha válida'];

        $a = $this->respaldo($bd); $this->manifiesto($a, function (array $m): array { $m['schema_version'] = '017_idempotencia_unicidad'; return $m; });
        $casos['esquema anterior'] = [$a, 'respaldo es anterior'];

        // Respaldo auténtico pero de OTRA base (misma estructura, otro nombre).
        $a = $this->respaldo($otra);
        $casos['respaldo de otra base'] = [$a, 'otra base de datos'];

        // Respaldo legítimo hecho ANTES de que entraran más filas en las tablas en riesgo.
        $a = $this->respaldo($bd);
        $casos['respaldo con menos filas que las actuales'] = [$a, 'anterior a datos actuales'];
        $this->sql($bd, "INSERT INTO usuarios(nombre,apellido,email,password,telefono,admin,confirmado) VALUES ('Nu','Evo','nuevo@rv.test','x','0990000004',0,1)");
        $this->sql($bd, "INSERT INTO clientes(usuario_id,estado,origen) SELECT id,'activo','registro_publico' FROM usuarios WHERE email='nuevo@rv.test'");
        $antes = $this->huellaTotal($bd);

        foreach ($casos as $nombre => [$ruta, $esperado]) {
            $r = $this->down($bd, array_merge($this->aceptarTodo($bloq), ["--respaldo=$ruta"]));
            $this->assertSame(2, $r['exit'], "«{$nombre}» fue aceptado o dio otro código: " . $r['out']);
            $this->assertStringContainsString($esperado, $r['out'], "«{$nombre}»: motivo inesperado");
            $this->assertSame($antes, $this->huellaTotal($bd), "«{$nombre}» modificó la base");
        }
        // Con un respaldo FRESCO y bueno sí se permite (control positivo).
        $bueno = $this->respaldo($bd);
        $sim = $this->down($bd, array_merge($this->aceptarTodo($bloq), ["--respaldo=$bueno", '--simular']));
        $this->assertSame(0, $sim['exit'], $sim['out']);
        $this->assertSame($antes, $this->huellaTotal($bd), '--simular modificó la base');
    }

    /**
     * Límite DOCUMENTADO (GUIA §7.4 límite c): el manifiesto no está firmado. Quien reconstruye a mano un manifiesto coherente con un
     * «respaldo» vacío supera la verificación. Se confirma que el límite es real (la protección es contra errores, no contra un
     * operador malintencionado) y que sigue documentado.
     */
    public function test_Limite_documentado_un_manifiesto_falsificado_coherente_supera_la_verificacion(): void
    {
        $bd = $this->preparar('r2brev');
        $bloq = self::migracionesBloqueantes($bd);
        $falso = $this->ruta($bd);
        $sql = "-- no es un respaldo\n";
        file_put_contents($falso, gzencode($sql));
        $filas = [];
        foreach ($this->filas($bd, 'SHOW TABLES') as $f) { $t = array_values($f)[0]; $filas[$t] = (int)$this->filas($bd, "SELECT COUNT(*) n FROM `$t`")[0]['n'] + 5; }
        $ultima = $this->filas($bd, 'SELECT migracion FROM migraciones ORDER BY id DESC LIMIT 1')[0]['migracion'];
        file_put_contents($falso . '.manifest.json', json_encode(['database' => $bd, 'fecha' => date('c'), 'sha256' => hash_file('sha256', $falso), 'sha256_sql' => hash('sha256', $sql), 'comprimido_gzip' => true, 'schema_version' => $ultima, 'filas_por_tabla' => $filas]));
        $sim = $this->down($bd, array_merge($this->aceptarTodo($bloq), ["--respaldo=$falso", '--simular']));
        $this->assertSame(0, $sim['exit'], 'con un manifiesto reconstruido se esperaba pasar la verificación (límite documentado): ' . $sim['out']);
        $this->assertStringContainsString('no está firmado', (string)file_get_contents(self::RAIZ . '/docs/GUIA_ACTUALIZACION_IDENTIDADES.md'), 'el límite debe seguir documentado');
    }

    // ------------------------------------------------------------------ --hasta

    public function test_hasta_limita_la_reversion_y_valida_sus_argumentos(): void
    {
        $bd = $this->preparar('r2brev');
        $antes = $this->huellaTotal($bd);
        // --hasta inexistente o mal formado: error de uso, nada cambia.
        foreach (['--hasta=999_nada', '--hasta=018', '--hasta=', '--hasta=../x', '--hasta=018_colaboradores_invitaciones;DROP'] as $h) {
            $r = $this->down($bd, [$h]);
            $this->assertSame(1, $r['exit'], "$h => " . $r['out']);
            $this->assertSame($antes, $this->huellaTotal($bd));
        }
        // Con --hasta=018 el plan SOLO incluye 018 y posteriores: 016 y 017 no bloquean; aceptar 016 es un error de uso.
        $r = $this->down($bd, ['--hasta=018_colaboradores_invitaciones', '--simular']);
        $this->assertSame(2, $r['exit'], $r['out']);
        $this->assertStringContainsString('018_colaboradores_invitaciones', $r['out']);
        $this->assertStringNotContainsString('016_clientes_membresia (', $r['out'], '--hasta=018 no debe analizar la 016');
        $r = $this->down($bd, ['--hasta=018_colaboradores_invitaciones', '--aceptar-perdida=016_clientes_membresia', '--aceptar-perdida=018_colaboradores_invitaciones', '--respaldo=' . $this->respaldo($bd)]);
        $this->assertSame(1, $r['exit'], 'aceptar una migración fuera de --hasta debe ser un error de uso: ' . $r['out']);
        $this->assertSame($antes, $this->huellaTotal($bd));
        // Reversión parcial correcta: 016 y 017 siguen aplicadas e intactas; 018 y posteriores revertidas.
        $respaldo = $this->respaldo($bd);
        $r = $this->down($bd, ['--hasta=018_colaboradores_invitaciones', '--aceptar-perdida=018_colaboradores_invitaciones', "--respaldo=$respaldo"]);
        $this->assertSame(0, $r['exit'], $r['out']);
        $reg = array_column($this->filas($bd, 'SELECT migracion FROM migraciones'), 'migracion');
        $this->assertContains('017_idempotencia_unicidad', $reg);
        $this->assertContains('016_clientes_membresia', $reg);
        foreach (['018_colaboradores_invitaciones', '019_correos_cola_usuario', '021_citas_reasignaciones', '022_crm_auditoria_consentimiento_homonimos'] as $m) { $this->assertNotContains($m, $reg, "$m sigue registrada"); }
        $this->assertSame(2, (int)$this->filas($bd, 'SELECT COUNT(*) n FROM clientes')[0]['n'], 'las membresías (016) no debían tocarse');
        $this->assertSame(1, (int)$this->filas($bd, 'SELECT COUNT(*) n FROM operaciones_idempotentes')[0]['n'], 'la idempotencia (017) no debía tocarse');
        // Reaplicar: vuelve a la instalación completa sin pérdida adicional.
        $up = self::migradorCmd($bd, ['up']);
        $this->assertSame(0, $up['exit'], $up['out']);
        $esperadas = count(array_filter(glob(self::RAIZ . '/database/migrations/*.sql'), static fn($f) => !str_ends_with($f, '_rollback.sql')));
        $this->assertSame($esperadas, (int)$this->filas($bd, 'SELECT COUNT(*) n FROM migraciones')[0]['n']);
    }

    // ------------------------------------------------------------------ reintento tras fallo a mitad

    public function test_reintento_tras_un_fallo_a_mitad_no_deja_la_base_incoherente_y_se_completa_al_corregirlo(): void
    {
        $bd = $this->preparar('r2brev');
        // Una auditoría de reasignaciones con filas + un «respaldo_021» ya existente: el rollback de la 021 falla A PROPÓSITO a mitad.
        $this->sql($bd, "INSERT INTO usuarios(nombre,apellido,email,password,telefono) VALUES ('Pr','Of','prof@rv.test','x','0990000009')");
        $this->sql($bd, "INSERT INTO profesionales(nombre,activo) VALUES ('Prof Rv',1)");
        $this->sql($bd, "INSERT INTO citas(fecha,hora,usuarioId,profesionalId,estado) VALUES (CURDATE(),'10:00:00',2,1,'confirmada')");
        $this->sql($bd, "INSERT INTO citas_reasignaciones(cita_id,cita_referencia_id,accion,profesional_origen_id,profesional_origen_nombre,actor_id,motivo,snapshot_json,creado_en) VALUES (1,1,'reasignada',1,'Prof Rv',1,'prueba','{}',UTC_TIMESTAMP())");
        $this->sql($bd, 'CREATE TABLE citas_reasignaciones_respaldo_021 (x INT) ENGINE=InnoDB');
        $bloq = self::migracionesBloqueantes($bd, '016_clientes_membresia');
        // La 021 NO bloquea: su rollback es seguro por diseño (con filas renombra la tabla a *_respaldo_021 en lugar de borrarla).
        $this->assertNotContains('021_citas_reasignaciones', $bloq);
        $respaldo = $this->respaldo($bd);
        $r = $this->down($bd, array_merge(['--hasta=016_clientes_membresia'], $this->aceptarTodo($bloq), ["--respaldo=$respaldo"]));
        $this->assertSame(1, $r['exit'], 'el rollback de 021 debe fallar a propósito y salir con 1: ' . $r['out']);
        $this->assertStringContainsString('citas_reasignaciones_respaldo_021', $r['out'], 'el fallo debe ser el de la 021, no un error de uso');
        $reg = array_column($this->filas($bd, 'SELECT migracion FROM migraciones'), 'migracion');
        $this->assertNotContains('022_crm_auditoria_consentimiento_homonimos', $reg, 'la 022 se revirtió antes del fallo');
        $this->assertContains('021_citas_reasignaciones', $reg, 'la 021 sigue registrada: no se perdió su registro');
        $this->assertSame(1, (int)$this->filas($bd, 'SELECT COUNT(*) n FROM citas_reasignaciones')[0]['n'], 'los datos de la 021 deben seguir intactos');
        // Corregir la causa (archivar el respaldo previo) y reintentar: la reversión continúa donde quedó y NO exige repetir lo ya revertido.
        $this->sql($bd, 'DROP TABLE citas_reasignaciones_respaldo_021');
        $respaldo2 = $this->respaldo($bd);
        $bloq2 = self::migracionesBloqueantes($bd, '016_clientes_membresia');
        $this->assertNotContains('022_crm_auditoria_consentimiento_homonimos', $bloq2);
        $r = $this->down($bd, array_merge(['--hasta=016_clientes_membresia'], $this->aceptarTodo($bloq2), ["--respaldo=$respaldo2"]));
        $this->assertSame(0, $r['exit'], $r['out']);
        $this->assertSame(1, (int)$this->filas($bd, 'SELECT COUNT(*) n FROM citas_reasignaciones_respaldo_021')[0]['n'], 'las filas de reasignaciones se conservan en el respaldo_021');
        $up = self::migradorCmd($bd, ['up']);
        $this->assertSame(0, $up['exit'], 'reaplicar tras el reintento: ' . $up['out']);
    }

    // ------------------------------------------------------------------ guard propio de la 022

    public function test_guard_de_022_bloquea_con_auditoria_y_aunque_se_acepte_el_sql_del_rollback_se_niega_a_mitad_sin_perder_nada(): void
    {
        $bd = $this->preparar('r2brev');
        $this->sql($bd, "INSERT INTO crm_exportaciones_auditoria(actor_usuario_id,actor_rol,formato,tipo,resultado,ocurrido_en) VALUES (1,'propietario','csv','clientes','exitosa',UTC_TIMESTAMP())");
        $bloq = self::migracionesBloqueantes($bd, '022_crm_auditoria_consentimiento_homonimos');
        $this->assertSame(['022_crm_auditoria_consentimiento_homonimos'], $bloq);
        $sim = $this->down($bd, ['--hasta=022_crm_auditoria_consentimiento_homonimos', '--simular']);
        $this->assertSame(2, $sim['exit'], $sim['out']);
        $this->assertStringContainsString('auditoría de exportaciones', $sim['out']);
        $respaldo = $this->respaldo($bd);
        $antes = $this->huellaTotal($bd);
        $r = $this->down($bd, ['--hasta=022_crm_auditoria_consentimiento_homonimos', '--aceptar-perdida=022_crm_auditoria_consentimiento_homonimos', "--respaldo=$respaldo"]);
        // Aceptar la pérdida NO basta: el propio rollback SQL se niega (1242) mientras la auditoría tenga filas. Debe fallar sin cambiar nada.
        $this->assertSame(1, $r['exit'], 'se esperaba que el rollback SQL de la 022 se negara: ' . $r['out']);
        $this->assertSame($antes, $this->huellaTotal($bd), 'el rollback fallido de la 022 modificó la base');
        $this->assertSame(1, (int)$this->filas($bd, 'SELECT COUNT(*) n FROM crm_exportaciones_auditoria')[0]['n']);
    }

    /**
     * DEF-R2B-05 (Baja, operación): tras cumplir TODO lo que el migrador pide (aceptar la pérdida de la 022 + respaldo verificado) el rollback
     * SQL de la 022 se niega por diseño con auditoría presente, pero el migrador solo muestra «Subquery returns more than 1 row»
     * (error 1242 del truco de la guarda) sin decir que hay que archivar y vaciar las tablas a mano. El flag --aceptar-perdida=022 sugiere
     * que es posible revertir y no lo es.
     */
    public function test_R2B_05_la_negativa_del_rollback_de_022_explica_el_remedio_y_el_remedio_funciona(): void
    {
        $bd = $this->preparar('r2brev');
        $this->sql($bd, "INSERT INTO crm_exportaciones_auditoria(actor_usuario_id,actor_rol,formato,tipo,resultado,ocurrido_en) VALUES (1,'propietario','csv','clientes','exitosa',UTC_TIMESTAMP())");
        $respaldo = $this->respaldo($bd);
        $antes = $this->huellaTotal($bd);
        $args = ['--hasta=022_crm_auditoria_consentimiento_homonimos', '--aceptar-perdida=022_crm_auditoria_consentimiento_homonimos', "--respaldo=$respaldo"];
        $r = $this->down($bd, $args);
        $this->assertSame(1, $r['exit']);
        // El operador cumplió TODO lo que el migrador pidió y aun así el propio rollback SQL se niega a propósito: el mensaje debe decir cómo continuar.
        $this->assertStringContainsString('[BLOQUEO]', $r['out'], $r['out']);
        $this->assertMatchesRegularExpression('/A PROP[ÓO]SITO/u', $r['out']);
        $this->assertMatchesRegularExpression('/archiv|vac[ií]a/i', $r['out'], 'el migrador no explica cómo continuar: ' . $r['out']);
        $this->assertStringContainsString('No se modificó nada', $r['out']);
        $this->assertSame($antes, $this->huellaTotal($bd), 'el rollback negado modificó la base');
        // Y lo que el mensaje pide FUNCIONA: archivar (el respaldo ya contiene la auditoría) y vaciar con un usuario administrador; después la reversión se completa.
        $this->sql($bd, 'TRUNCATE TABLE crm_exportaciones_auditoria');
        $r2 = $this->down($bd, $args);
        $this->assertSame(0, $r2['exit'], 'tras vaciar la tabla la reversión debía completarse: ' . $r2['out']);
        $this->assertSame(0, (int)$this->filas($bd, "SELECT COUNT(*) n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='crm_exportaciones_auditoria'")[0]['n'], 'la tabla debía desaparecer con la reversión');
    }

    /** Con la auditoría VACÍA no hay pérdida que aceptar: la reversión de 022 se completa sin pista de bloqueo (la pista solo aparece ante la negativa deliberada). */
    public function test_R2B_05_sin_datos_en_riesgo_la_reversion_de_022_se_completa_sin_pista_de_bloqueo(): void
    {
        $bd = $this->preparar('r2brev');
        $r = $this->down($bd, ['--hasta=022_crm_auditoria_consentimiento_homonimos']);
        $this->assertSame(0, $r['exit'], $r['out']);
        $this->assertStringNotContainsString('[BLOQUEO] La reversión', $r['out']);
        $this->assertStringNotContainsString('A PROPÓSITO', $r['out']);
    }

    // ------------------------------------------------------------------ 016: suspensiones y ciclos repetidos

    public function test_suspension_sobrevive_a_dos_ciclos_down_up_con_cambios_intermedios_y_el_login_sigue_denegado(): void
    {
        $bd = $this->preparar('r2brev');
        for ($ciclo = 1; $ciclo <= 2; $ciclo++) {
            $membresias = (int)$this->filas($bd, 'SELECT COUNT(*) n FROM clientes')[0]['n'];
            $rev = self::revertirSeguro($bd, '016_clientes_membresia');
            $this->assertSame(0, $rev['exit'], "ciclo $ciclo: " . $rev['out']);
            $this->temporales[] = $rev['respaldo'];
            $res = $this->filas($bd, 'SELECT usuario_id, estado, suspendido_motivo, restaurado_en FROM clientes_resguardo_016 ORDER BY usuario_id');
            $this->assertCount($membresias, $res, 'el resguardo debe conservar TODAS las membresías');
            $this->assertSame('suspendido', array_column($res, 'estado', 'usuario_id')[3]);
            if ($ciclo === 1) {
                // Mientras la 016 está revertida alguien se registra y otra cuenta se elimina (la tabla clientes no existe).
                $this->sql($bd, "INSERT INTO usuarios(nombre,apellido,email,password,telefono,admin,confirmado) VALUES ('Nu','Evo','nuevo@rv.test','x','0990000004',0,1)");
            }
            $up = self::migradorCmd($bd, ['up']);
            $this->assertSame(0, $up['exit'], "ciclo $ciclo up: " . $up['out']);
            $est = array_column($this->filas($bd, 'SELECT usuario_id, estado, suspendido_motivo FROM clientes'), null, 'usuario_id');
            $this->assertSame('suspendido', $est[3]['estado'], "ciclo $ciclo: suspensión perdida");
            $this->assertSame('Impagos', $est[3]['suspendido_motivo']);
            $this->assertSame('activo', $est[2]['estado']);
            $this->assertArrayHasKey(4, $est, 'la cuenta creada con la 016 revertida debe recibir su membresía al reaplicar');
            $this->assertSame(0, (int)$this->filas($bd, 'SELECT COUNT(*) n FROM clientes_resguardo_016 WHERE restaurado_en IS NULL')[0]['n'], 'filas del resguardo sin marcar como restauradas');
        }
        $srv = self::servidor(['DB_NAME' => $bd], 'r2brev');
        $this->assertSame(403, (new Navegador($srv))->login('cliente', 'susp@rv.test', 'Clave-123456')['status'], 'el suspendido pudo entrar tras reaplicar');
        self::pararServidor('r2brev');
    }

    public function test_usuario_eliminado_mientras_la_016_estaba_revertida_no_rompe_la_reaplicacion(): void
    {
        $bd = $this->preparar('r2brev');
        $rev = self::revertirSeguro($bd, '016_clientes_membresia');
        $this->assertSame(0, $rev['exit'], $rev['out']);
        $this->temporales[] = $rev['respaldo'];
        $this->sql($bd, 'DELETE FROM usuarios WHERE id=3'); // supresión de datos personales con la tabla clientes ausente
        $up = self::migradorCmd($bd, ['up']);
        $this->assertSame(0, $up['exit'], 'la reaplicación falló por una fila de resguardo huérfana: ' . $up['out']);
        $this->assertSame(0, (int)$this->filas($bd, 'SELECT COUNT(*) n FROM clientes WHERE usuario_id=3')[0]['n']);
    }

    public function test_esquema_tras_down_completo_y_up_es_identico_a_una_instalacion_nueva_y_conserva_triggers(): void
    {
        $bd = $this->preparar('r2brev');
        $fresh = $this->bd('r2brevfresh');
        self::crearBd($fresh);
        $rev = self::revertirSeguro($bd);
        $this->assertSame(0, $rev['exit'], $rev['out']);
        $this->temporales[] = $rev['respaldo'];
        $up = self::migradorCmd($bd, ['up']);
        $this->assertSame(0, $up['exit'], $up['out']);
        $q = fn(string $b, string $sql) => $this->filas($b, $sql);
        $esquema = fn(string $b) => [
            $q($b, "SELECT table_name, column_name, column_type, is_nullable, column_default, extra FROM information_schema.columns WHERE table_schema=DATABASE() ORDER BY table_name, column_name"),
            $q($b, "SELECT table_name, index_name, non_unique, seq_in_index, column_name, expression FROM information_schema.statistics WHERE table_schema=DATABASE() ORDER BY table_name, index_name, seq_in_index"),
            $q($b, "SELECT trigger_name, event_object_table, action_timing, event_manipulation FROM information_schema.triggers WHERE trigger_schema=DATABASE() ORDER BY trigger_name"),
            $q($b, "SELECT constraint_name, table_name, referenced_table_name FROM information_schema.referential_constraints WHERE constraint_schema=DATABASE() ORDER BY 1,2"),
        ];
        $this->assertEquals($esquema($fresh), $esquema($bd), 'esquema (columnas, índices, triggers y FK) distinto al de una instalación nueva');
        // Los triggers de solo-anexado vuelven a rechazar UPDATE/DELETE tras el ciclo.
        $db = $this->conectar($bd);
        $db->query("INSERT INTO crm_exportaciones_auditoria(actor_usuario_id,actor_rol,formato,tipo,resultado,ocurrido_en) VALUES (1,'propietario','csv','clientes','exitosa',UTC_TIMESTAMP())");
        $this->assertFalse($db->query("UPDATE crm_exportaciones_auditoria SET resultado='fallida'"));
        $this->assertSame(1644, $db->errno, 'trigger de solo anexado ausente tras down/up');
        $this->assertFalse($db->query('DELETE FROM crm_exportaciones_auditoria'));
        $this->assertSame(1644, $db->errno);
        $db->close();
    }
}

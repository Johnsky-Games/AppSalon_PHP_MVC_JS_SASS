<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * DEF-16: el ciclo rollback/reaplicación de la 016 ya no pierde suspensiones y `migrador down` se niega a destruir
 * datos de negocio sin aceptación explícita por migración y un respaldo verificable. Todo contra bases AISLADAS creadas
 * por la prueba (`appsalon_identidad_rev*_test`) con el migrador real, el script de respaldo real y HTTP real (puerto 8111).
 */
final class ReversionSeguraIntegrationTest extends TestCase
{
    private const BDS = ['appsalon_identidad_rev_test', 'appsalon_identidad_rev2_test', 'appsalon_identidad_rev3_test', 'appsalon_identidad_revfresh_test', 'appsalon_identidad_rev022_test'];
    private const HASH_CLAVE = 'Cliente-Pass-12345';
    private const MIGRACIONES = ['016_clientes_membresia', '017_idempotencia_unicidad', '018_colaboradores_invitaciones'];
    private static string $raiz;
    /** @var string[] */
    private static array $temporales = [];
    /** @var resource|null */
    private static $servidor = null;

    public static function setUpBeforeClass(): void
    {
        self::$raiz = realpath(__DIR__ . '/../..');
        self::crearBd('appsalon_identidad_revfresh_test');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$servidor)) { @proc_terminate(self::$servidor); @proc_close(self::$servidor); }
        foreach (self::BDS as $bd) { self::admin()->query("DROP DATABASE IF EXISTS `$bd`"); }
        foreach (self::$temporales as $f) { @unlink($f); @unlink($f . '.manifest.json'); }
    }

    // ------------------------------------------------------------------ infraestructura

    private static function cfg(string $k, string $d): string
    {
        $v = $_ENV[$k] ?? getenv($k);
        return is_string($v) && $v !== '' ? $v : $d;
    }

    private static function admin(?string $bd = null): \mysqli
    {
        mysqli_report(MYSQLI_REPORT_OFF);
        $c = new \mysqli(self::cfg('DB_HOST', '127.0.0.1'), self::cfg('DB_USER', 'root'), self::cfg('DB_PASS', 'root'), $bd ?? '', (int)self::cfg('DB_PORT', '3306'));
        $c->set_charset('utf8mb4');
        return $c;
    }

    private static function nombre(string $bd): string
    {
        if (!in_array($bd, self::BDS, true)) { throw new \RuntimeException("Base no permitida: $bd"); }
        return $bd;
    }

    private static function entorno(string $bd): array
    {
        return array_merge(getenv(), ['DB_NAME' => $bd, 'APP_ENV' => 'testing']);
    }

    /** @return array{exit:int,out:string} */
    private static function ejecutar(array $cmd, string $bd): array
    {
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::$raiz, self::entorno($bd));
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        return ['exit' => proc_close($p), 'out' => $out];
    }

    private static function migrar(string $bd, string ...$args): array
    {
        return self::ejecutar(array_merge([PHP_BINARY, '-d', 'variables_order=EGPCS', self::$raiz . '/database/migrador.php'], $args), $bd);
    }

    private static function crearBd(string $bd): void
    {
        self::nombre($bd);
        $a = self::admin();
        $a->query("DROP DATABASE IF EXISTS `$bd`");
        $a->query("CREATE DATABASE `$bd` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $a->select_db($bd);
        $a->multi_query((string)file_get_contents(self::$raiz . '/database/schema_base.sql'));
        do { if ($r = $a->store_result()) { $r->free(); } } while ($a->more_results() && $a->next_result());
        $a->close();
        $m = self::migrar($bd, 'up');
        if ($m['exit'] !== 0) { throw new \RuntimeException("migrador up falló en $bd: " . $m['out']); }
    }

    private function respaldo(string $bd, bool $gzip = true): string
    {
        $ruta = sys_get_temp_dir() . '/revseg_' . getmypid() . '_' . bin2hex(random_bytes(3)) . ($gzip ? '.sql.gz' : '.sql');
        self::$temporales[] = $ruta;
        $r = self::ejecutar([PHP_BINARY, '-d', 'variables_order=EGPCS', self::$raiz . '/scripts/backup_db.php', "--db=$bd", "--output=$ruta"], $bd);
        $this->assertSame(0, $r['exit'], $r['out']);
        $this->assertFileExists($ruta . '.manifest.json');
        return $ruta;
    }

    private function consulta(string $bd, string $sql): array
    {
        $c = self::admin(self::nombre($bd));
        $r = $c->query($sql);
        $this->assertNotFalse($r, $c->error . ' :: ' . $sql);
        $filas = $r === true ? [] : $r->fetch_all(MYSQLI_ASSOC);
        $c->close();
        return $filas;
    }

    private function sql(string $bd, string $sql): void
    {
        $c = self::admin(self::nombre($bd));
        $this->assertTrue($c->multi_query($sql), $c->error);
        do { if ($r = $c->store_result()) { $r->free(); } } while ($c->more_results() && $c->next_result());
        $this->assertSame(0, $c->errno, $c->error);
        $c->close();
    }

    /** Estado observable completo: tablas, filas por tabla, migraciones registradas y contenido de las tablas de la 016-018. */
    private function estado(string $bd): array
    {
        $tablas = array_column($this->consulta($bd, "SELECT table_name t FROM information_schema.tables WHERE table_schema='$bd' AND table_type='BASE TABLE' ORDER BY 1"), 't');
        $filas = [];
        foreach ($tablas as $t) { $filas[$t] = (int)$this->consulta($bd, "SELECT COUNT(*) n FROM `$t`")[0]['n']; }
        return [
            'tablas' => $tablas, 'filas' => $filas,
            'migraciones' => array_column($this->consulta($bd, 'SELECT migracion FROM migraciones ORDER BY id'), 'migracion'),
            'clientes' => in_array('clientes', $tablas, true) ? $this->consulta($bd, 'SELECT usuario_id,estado,suspendido_motivo,origen,creado_en FROM clientes ORDER BY usuario_id') : null,
        ];
    }

    private function esquema(string $bd): array
    {
        return [
            $this->consulta($bd, "SELECT table_name, engine, table_collation FROM information_schema.tables WHERE table_schema='$bd' ORDER BY table_name"),
            $this->consulta($bd, "SELECT table_name, column_name, column_type, is_nullable, column_default, extra FROM information_schema.columns WHERE table_schema='$bd' ORDER BY table_name, column_name"),
            $this->consulta($bd, "SELECT table_name, index_name, non_unique, seq_in_index, column_name, expression FROM information_schema.statistics WHERE table_schema='$bd' ORDER BY table_name, index_name, seq_in_index"),
        ];
    }

    /** Datos de negocio de las migraciones 016-018 y un cliente suspendido con motivo, origen y fecha de alta conocidos. */
    private function sembrar(string $bd): void
    {
        $hash = password_hash(self::HASH_CLAVE, PASSWORD_BCRYPT);
        $this->sql($bd, "DELETE FROM clientes; DELETE FROM usuarios;
            INSERT INTO usuarios(id,nombre,apellido,email,password,telefono,admin,confirmado) VALUES
              (1,'Du','Eño','dueno@rev.test','$hash','0990000001',1,1),
              (2,'Cli','Activo','activo@rev.test','$hash','0990000002',0,1),
              (3,'Cli','Suspendido','suspendido@rev.test','$hash','0990000003',0,1);
            INSERT INTO usuario_accesos(usuario_id,rol,activo) VALUES (1,'propietario',1);
            INSERT INTO clientes(usuario_id,estado,origen,suspendido_motivo,creado_en) VALUES
              (2,'activo','registro_publico',NULL,'2025-03-01 10:00:00'),
              (3,'suspendido','recepcion','Pagos pendientes','2025-02-02 03:04:05');
            INSERT INTO colaboradores(usuario_id,email_norm,nombre,apellido,rol,estado,aceptado_en,codigo) VALUES (1,'dueno@rev.test','Du','Eño','propietario','activo',UTC_TIMESTAMP(),'COL-000001');
            INSERT INTO colaboradores(usuario_id,email_norm,nombre,apellido,rol,estado) VALUES (NULL,'inv@rev.test','In','Vi','recepcion','invitado');
            INSERT INTO colaborador_invitaciones(colaborador_id,token_hash,expira_en) VALUES (LAST_INSERT_ID(),'" . str_repeat('a', 64) . "',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY));
            INSERT INTO operaciones_idempotentes(scope,actor,clave_hash,huella,expira_en) VALUES ('x','anonimo:1','" . str_repeat('b', 64) . "','" . str_repeat('c', 64) . "',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY));
            INSERT INTO correos_salida(clave_unica,tipo,destinatario_email,destinatario_nombre,carga_cifrada) VALUES ('t:1','aviso_cuenta_existente','x@rev.test','X','cifrado');");
    }

    private function bdConDatos(string $bd): void
    {
        self::crearBd($bd);
        $this->sembrar($bd);
    }

    private function aceptarTodo(): array
    {
        return array_map(static fn($m) => "--aceptar-perdida=$m", self::MIGRACIONES);
    }

    // ================================================================== 1. bloqueo sin banderas

    public function test_down_sin_banderas_se_niega_con_codigo_2_y_deja_la_base_intacta(): void
    {
        $bd = 'appsalon_identidad_rev_test';
        $this->bdConDatos($bd);
        $antes = $this->estado($bd);
        $r = self::migrar($bd, 'down');
        $this->assertSame(2, $r['exit'], $r['out']);
        $this->assertStringContainsString('[BLOQUEO]', $r['out']);
        foreach (self::MIGRACIONES as $m) { $this->assertStringContainsString($m, $r['out']); }
        $this->assertStringContainsString('2 membresías de cliente (1 suspendidas)', $r['out']);
        $this->assertStringContainsString('--respaldo=', $r['out']);
        $this->assertStringContainsString('No se modificó ninguna tabla', $r['out']);
        $this->assertSame($antes, $this->estado($bd), 'down bloqueado no debe tocar ni una tabla ni la tabla de control');
    }

    public function test_la_022_solo_bloquea_con_datos_de_negocio_y_el_retrollenado_no_cuenta(): void
    {
        $bd = 'appsalon_identidad_rev022_test';
        self::crearBd($bd);
        // Solo el retro-llenado de la migración (versión 1 histórica): revertir no destruye nada de negocio.
        $r = self::migrar($bd, 'down', '--simular');
        $this->assertStringNotContainsString('022_crm_auditoria_consentimiento_homonimos', $r['out'], $r['out']);
        // Una versión publicada por el equipo es dato de negocio: se bloquea y el rollback SQL también se niega.
        $this->sql($bd, "INSERT INTO consentimiento_versiones (proposito, version, texto, texto_hash, vigente_desde, es_historica, creado_en)
            VALUES ('marketing_email', 2, 'Texto nuevo', '" . str_repeat('d', 64) . "', UTC_TIMESTAMP(), 0, UTC_TIMESTAMP())");
        $antes = $this->estado($bd);
        $r = self::migrar($bd, 'down');
        $this->assertSame(2, $r['exit'], $r['out']);
        $this->assertStringContainsString('022_crm_auditoria_consentimiento_homonimos', $r['out']);
        $this->assertStringContainsString('versiones de consentimiento publicadas', $r['out']);
        $this->assertSame($antes, $this->estado($bd));
    }

    public function test_simular_informa_sin_modificar_y_las_opciones_invalidas_se_rechazan(): void
    {
        $bd = 'appsalon_identidad_rev_test';
        $this->bdConDatos($bd);
        $antes = $this->estado($bd);
        $r = self::migrar($bd, 'down', '--simular');
        $this->assertSame(2, $r['exit']);
        $this->assertStringContainsString('[SIMULACIÓN]', $r['out']);
        $r = self::migrar($bd, 'down', '--aceptar-perdida=999_no_existe');
        $this->assertSame(1, $r['exit']);
        $this->assertStringContainsString('no corresponde a una migración registrada', $r['out']);
        $r = self::migrar($bd, 'down', '--forzar');
        $this->assertSame(1, $r['exit']);
        $this->assertStringContainsString('Opción no reconocida', $r['out']);
        $this->assertSame($antes, $this->estado($bd));
    }

    // ================================================================== 2. respaldo inválido o ausente

    public function test_aceptar_la_perdida_sin_respaldo_o_con_respaldo_inservible_sigue_bloqueado(): void
    {
        $bd = 'appsalon_identidad_rev_test';
        $this->bdConDatos($bd);
        $antes = $this->estado($bd);
        $bueno = $this->respaldo($bd);

        // Sin --respaldo.
        $r = self::migrar($bd, 'down', ...$this->aceptarTodo());
        $this->assertSame(2, $r['exit'], $r['out']);
        $this->assertStringContainsString('Falta --respaldo', $r['out']);

        // Ruta inexistente.
        $r = self::migrar($bd, 'down', ...array_merge($this->aceptarTodo(), ['--respaldo=/tmp/no-existe-' . bin2hex(random_bytes(3)) . '.sql.gz']));
        $this->assertSame(2, $r['exit']);
        $this->assertStringContainsString('No existe el archivo de respaldo', $r['out']);

        // Sin manifiesto.
        $sinManifiesto = $this->copiar($bueno, false);
        $r = self::migrar($bd, 'down', ...array_merge($this->aceptarTodo(), ["--respaldo=$sinManifiesto"]));
        $this->assertSame(2, $r['exit']);
        $this->assertStringContainsString('Falta el manifiesto', $r['out']);

        // Archivo alterado tras generar el manifiesto.
        $alterado = $this->copiar($bueno);
        file_put_contents($alterado, 'X', FILE_APPEND);
        $r = self::migrar($bd, 'down', ...array_merge($this->aceptarTodo(), ["--respaldo=$alterado"]));
        $this->assertSame(2, $r['exit']);
        $this->assertStringContainsString('SHA-256 del respaldo no coincide', $r['out']);

        // Manifiesto de otra base, antiguo y de un esquema anterior.
        foreach ([
            ['database', 'appsalon_otra_test', 'otra base de datos'],
            ['fecha', gmdate('c', time() - 3 * 86400), 'más de 24 horas'],
            ['schema_version', '015_comisiones_liquidaciones', 'es anterior'],
        ] as [$campo, $valor, $texto]) {
            $copia = $this->copiar($bueno);
            $m = json_decode((string)file_get_contents($copia . '.manifest.json'), true);
            $m[$campo] = $valor;
            file_put_contents($copia . '.manifest.json', json_encode($m));
            $r = self::migrar($bd, 'down', ...array_merge($this->aceptarTodo(), ["--respaldo=$copia"]));
            $this->assertSame(2, $r['exit'], "$campo: " . $r['out']);
            $this->assertStringContainsString($texto, $r['out'], $campo);
        }

        // Aceptación parcial: falta una migración con pérdida.
        $r = self::migrar($bd, 'down', '--aceptar-perdida=016_clientes_membresia', '--aceptar-perdida=018_colaboradores_invitaciones', "--respaldo=$bueno");
        $this->assertSame(2, $r['exit']);
        $this->assertStringContainsString('--aceptar-perdida=017_idempotencia_unicidad', $r['out']);

        // Un respaldo anterior a datos actuales (se agrega un cliente después del respaldo).
        $this->sql($bd, "INSERT INTO usuarios(id,nombre,apellido,email,password,telefono,admin,confirmado) VALUES (9,'Nu','Evo','nuevo@rev.test','x','0990000009',0,1); INSERT INTO clientes(usuario_id) VALUES (9);");
        $r = self::migrar($bd, 'down', ...array_merge($this->aceptarTodo(), ["--respaldo=$bueno"]));
        $this->assertSame(2, $r['exit']);
        $this->assertStringContainsString('anterior a datos actuales', $r['out']);

        $this->sql($bd, 'DELETE FROM clientes WHERE usuario_id=9; DELETE FROM usuarios WHERE id=9;');
        $this->assertSame($antes, $this->estado($bd), 'ningún intento bloqueado modificó la base');
    }

    private function copiar(string $origen, bool $conManifiesto = true): string
    {
        $dest = sys_get_temp_dir() . '/revseg_copia_' . bin2hex(random_bytes(4)) . (str_ends_with($origen, '.gz') ? '.sql.gz' : '.sql');
        copy($origen, $dest);
        self::$temporales[] = $dest;
        if ($conManifiesto) { copy($origen . '.manifest.json', $dest . '.manifest.json'); }
        return $dest;
    }

    // ================================================================== 3. procedimiento completo

    public function test_procedimiento_completo_down_con_aceptacion_y_respaldo_luego_up_conserva_la_suspension(): void
    {
        $bd = 'appsalon_identidad_rev_test';
        $this->bdConDatos($bd);
        $originales = $this->consulta($bd, 'SELECT usuario_id,estado,suspendido_motivo,origen,creado_en FROM clientes ORDER BY usuario_id');
        $this->assertSame('suspendido', $originales[1]['estado']);

        $respaldo = $this->respaldo($bd);
        $down = self::migrar($bd, 'down', ...array_merge(['--hasta=016_clientes_membresia'], $this->aceptarTodo(), ["--respaldo=$respaldo"]));
        $this->assertSame(0, $down['exit'], $down['out']);
        $this->assertStringContainsString('Pérdida aceptada de forma explícita con respaldo verificado', $down['out']);

        $tras = $this->estado($bd);
        $this->assertNotContains('clientes', $tras['tablas'], 'la 016 quedó revertida');
        $this->assertContains('clientes_resguardo_016', $tras['tablas'], 'el resguardo persiste al revertir');
        $this->assertSame(2, $tras['filas']['clientes_resguardo_016']);
        $this->assertSame(15, count($tras['migraciones']), '--hasta=016 revierte solo 016-018 y deja 001-015');
        $this->assertSame(1, $tras['filas']['usuario_accesos'], 'las migraciones anteriores no se tocan');

        $up = self::migrar($bd, 'up');
        $this->assertSame(0, $up['exit'], $up['out']);
        $this->assertSame($originales, $this->consulta($bd, 'SELECT usuario_id,estado,suspendido_motivo,origen,creado_en FROM clientes ORDER BY usuario_id'),
            'estado, motivo, origen y fecha de alta idénticos tras revertir y reaplicar');
        $this->assertSame(2, (int)$this->consulta($bd, 'SELECT COUNT(*) n FROM clientes_resguardo_016 WHERE restaurado_en IS NOT NULL')[0]['n'], 'las filas restauradas quedan marcadas');
        $this->assertEquals($this->esquema('appsalon_identidad_revfresh_test'), $this->esquema($bd), 'tras el ciclo el esquema es idéntico al de una instalación nueva');

        // Purga manual documentada del resguardo ya restaurado.
        $this->sql($bd, 'DELETE FROM clientes_resguardo_016 WHERE restaurado_en IS NOT NULL');
        $this->assertSame(0, (int)$this->consulta($bd, 'SELECT COUNT(*) n FROM clientes_resguardo_016')[0]['n']);
    }

    public function test_el_cliente_suspendido_sigue_sin_poder_entrar_tras_el_ciclo_completo(): void
    {
        $bd = 'appsalon_identidad_rev_test';
        $this->bdConDatos($bd);
        $respaldo = $this->respaldo($bd);
        $this->assertSame(0, self::migrar($bd, 'down', ...array_merge(['--hasta=016_clientes_membresia'], $this->aceptarTodo(), ["--respaldo=$respaldo"]))['exit']);
        $this->assertSame(0, self::migrar($bd, 'up')['exit']);

        $base = $this->servidor($bd);
        $suspendido = $this->http($base)->login('suspendido@rev.test');
        $this->assertSame(403, $suspendido['status'], substr(strip_tags($suspendido['cuerpo']), 0, 300));
        $this->assertStringContainsString('suspendida', $suspendido['cuerpo']);
        $this->assertNotSame('/cita', $suspendido['location']);
        $this->assertSame('suspendido', $this->consulta($bd, 'SELECT estado FROM clientes WHERE usuario_id=3')[0]['estado'], 'el intento de acceso no reactivó nada');
        // Control: el cliente activo sí entra.
        $activo = $this->http($base)->login('activo@rev.test');
        $this->assertSame(302, $activo['status']);
        $this->assertSame('/cita', $activo['location']);
    }

    public function test_down_completo_exige_aceptar_tambien_las_migraciones_antiguas_con_datos_y_el_ciclo_restaura_las_suspensiones(): void
    {
        $bd = 'appsalon_identidad_rev2_test';
        $this->bdConDatos($bd);
        $respaldo = $this->respaldo($bd);
        $antes = $this->estado($bd);
        // Con solo las tres migraciones recientes aceptadas, el `down` completo sigue bloqueado por la 010 (usuario_accesos).
        $r = self::migrar($bd, 'down', ...array_merge($this->aceptarTodo(), ["--respaldo=$respaldo"]));
        $this->assertSame(2, $r['exit'], $r['out']);
        $this->assertStringContainsString('--aceptar-perdida=010_equipo_permisos', $r['out']);
        $this->assertSame($antes, $this->estado($bd));
        $r = self::migrar($bd, 'down', ...array_merge($this->aceptarTodo(), ['--aceptar-perdida=010_equipo_permisos', "--respaldo=$respaldo"]));
        $this->assertSame(0, $r['exit'], $r['out']);
        $this->assertSame([], $this->estado($bd)['migraciones']);
        $this->assertSame(0, self::migrar($bd, 'up')['exit']);
        $est = array_column($this->consulta($bd, 'SELECT usuario_id, estado FROM clientes'), 'estado', 'usuario_id');
        $this->assertSame('suspendido', $est[3] ?? null);
        $this->assertEquals($this->esquema('appsalon_identidad_revfresh_test'), $this->esquema($bd));
    }

    public function test_el_rollback_de_la_016_ejecutado_a_mano_tambien_preserva_las_suspensiones(): void
    {
        $bd = 'appsalon_identidad_rev2_test';
        $this->bdConDatos($bd);
        $this->sql($bd, (string)file_get_contents(self::$raiz . '/database/migrations/016_clientes_membresia_rollback.sql') . "\nDELETE FROM migraciones WHERE migracion='016_clientes_membresia';");
        $this->assertSame(2, (int)$this->consulta($bd, 'SELECT COUNT(*) n FROM clientes_resguardo_016')[0]['n']);
        $up = self::migrar($bd, 'up');
        $this->assertSame(0, $up['exit'], $up['out']);
        $est = array_column($this->consulta($bd, 'SELECT usuario_id, estado FROM clientes'), 'estado', 'usuario_id');
        $this->assertSame('suspendido', $est[3] ?? null, 'la suspensión explícita sobrevive al ciclo down/up');
        $this->assertSame('activo', $est[2] ?? null);
    }

    public function test_una_suspension_hecha_con_la_016_revertida_no_se_reactiva_al_reaplicar(): void
    {
        // Variante: la retrocarga puede marcar suspendido a quien el resguardo tenía activo; nunca al revés.
        $bd = 'appsalon_identidad_rev2_test';
        $this->bdConDatos($bd);
        $this->sql($bd, (string)file_get_contents(self::$raiz . '/database/migrations/016_clientes_membresia_rollback.sql') . "\nDELETE FROM migraciones WHERE migracion='016_clientes_membresia';");
        // Con la 016 revertida el cliente 2 (activo en el resguardo) queda desactivado en su acceso (modelo previo a la 016).
        $this->sql($bd, "INSERT INTO usuario_accesos(usuario_id,rol,activo) VALUES (2,'cliente',0)");
        $this->assertSame(0, self::migrar($bd, 'up')['exit']);
        $est = array_column($this->consulta($bd, 'SELECT usuario_id, estado FROM clientes'), 'estado', 'usuario_id');
        $this->assertSame('suspendido', $est[2], 'acceso desactivado => suspendido, aunque el resguardo dijera activo');
        $this->assertSame('suspendido', $est[3]);
    }

    // ================================================================== 4. no estorba cuando no hay datos y cubre otras migraciones

    public function test_down_sobre_una_base_sin_datos_de_negocio_sigue_funcionando_y_el_ciclo_reproduce_el_esquema(): void
    {
        $bd = 'appsalon_identidad_rev3_test';
        self::crearBd($bd);
        $this->sql($bd, 'DELETE FROM correos_salida; DELETE FROM colaborador_invitaciones; DELETE FROM colaboradores; DELETE FROM usuario_accesos; DELETE FROM equipo_auditoria; DELETE FROM clientes; DELETE FROM usuarios;');
        $r = self::migrar($bd, 'down', '--simular');
        $this->assertSame(0, $r['exit'], $r['out']);
        $this->assertStringContainsString('no destruye datos de negocio', $r['out']);
        $down = self::migrar($bd, 'down');
        $this->assertSame(0, $down['exit'], $down['out']);
        $up = self::migrar($bd, 'up');
        $this->assertSame(0, $up['exit'], $up['out']);
        $this->assertEquals($this->esquema('appsalon_identidad_revfresh_test'), $this->esquema($bd));
    }

    public function test_el_guard_generico_bloquea_la_reversion_de_otras_migraciones_con_datos(): void
    {
        $bd = 'appsalon_identidad_rev3_test';
        self::crearBd($bd);
        $this->sql($bd, 'DELETE FROM correos_salida; DELETE FROM colaborador_invitaciones; DELETE FROM colaboradores; DELETE FROM usuario_accesos; DELETE FROM equipo_auditoria; DELETE FROM clientes; DELETE FROM usuarios;');
        $this->sql($bd, "INSERT INTO inventario_productos(sku,nombre) VALUES ('SKU-1','Shampoo')");
        $antes = $this->estado($bd);
        $r = self::migrar($bd, 'down');
        $this->assertSame(2, $r['exit'], $r['out']);
        $this->assertStringContainsString('013_finanzas_inventario', $r['out']);
        $this->assertStringContainsString('inventario_productos', $r['out']);
        $this->assertSame($antes, $this->estado($bd));
        // Una columna con valores (cancelaciones de la 007) también se detecta.
        $this->sql($bd, 'DELETE FROM inventario_productos');
        $this->sql($bd, "INSERT INTO usuarios(id,nombre,apellido,email,password,telefono,admin,confirmado) VALUES (1,'A','B','a@rev.test','x','0990000001',0,1)");
        $this->sql($bd, "INSERT INTO citas(fecha,hora,usuarioId,estado,estado_pago) VALUES (CURDATE(),'10:00:00',1,'cancelada','pendiente')");
        $r = self::migrar($bd, 'down', '--simular');
        $this->assertSame(2, $r['exit'], $r['out']);
        $this->assertStringContainsString('007_citas_estado_cancelacion', $r['out']);
        $this->assertStringContainsString('citas.estado', $r['out']);
    }

    // ================================================================== HTTP

    private function servidor(string $bd): string
    {
        if (is_resource(self::$servidor)) { @proc_terminate(self::$servidor); @proc_close(self::$servidor); self::$servidor = null; }
        $puerto = 8111;
        $log = sys_get_temp_dir() . '/revseg_web_' . $puerto . '.log';
        $sesiones = sys_get_temp_dir() . '/revseg_sess_' . $puerto;
        @mkdir($sesiones, 0777, true);
        self::$servidor = proc_open(
            [PHP_BINARY, '-d', 'variables_order=EGPCS', '-d', 'error_log=' . $log, '-d', 'session.save_path=' . $sesiones, '-S', "127.0.0.1:$puerto", '-t', self::$raiz . '/public', self::$raiz . '/tests/fixtures/router_http.php'],
            [1 => ['file', $log . '.out', 'a'], 2 => ['file', $log . '.err', 'a']], $pipes, self::$raiz, self::entorno($bd)
        );
        for ($i = 0; $i < 60; $i++) {
            $c = @fsockopen('127.0.0.1', $puerto, $en, $es, 0.2);
            if ($c) { fclose($c); return "http://127.0.0.1:$puerto"; }
            usleep(100000);
        }
        throw new \RuntimeException('El servidor de prueba no arrancó');
    }

    private function http(string $base): object
    {
        return new class($base) {
            private string $jar;
            public function __construct(private string $base) { $this->jar = tempnam(sys_get_temp_dir(), 'revseg_jar_'); }
            public function __destruct() { @unlink($this->jar); }
            private function pedir(string $metodo, string $ruta, ?array $campos = null): array
            {
                $h = [];
                $ch = curl_init($this->base . $ruta);
                curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60,
                    CURLOPT_HEADERFUNCTION => static function ($c, $l) use (&$h) { if (str_contains($l, ':')) { [$k, $v] = explode(':', $l, 2); $h[strtolower(trim($k))] = trim($v); } return strlen($l); }]);
                if ($campos !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($campos)); }
                $cuerpo = (string)curl_exec($ch);
                $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);
                return ['status' => $status, 'cuerpo' => $cuerpo, 'location' => $h['location'] ?? null];
            }
            public function login(string $email): array
            {
                $pagina = $this->pedir('GET', '/cliente/login');
                preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $pagina['cuerpo'], $m);
                return $this->pedir('POST', '/cliente/login', ['csrf_token' => $m[1] ?? '', 'email' => $email, 'password' => ReversionSeguraIntegrationTest::CLAVE]);
            }
        };
    }

    public const CLAVE = self::HASH_CLAVE;
}

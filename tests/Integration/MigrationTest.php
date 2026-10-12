<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use mysqli;

class MigrationTest extends TestCase
{
    private static ?mysqli $db = null;
    private static string $host;
    private static string $user;
    private static string $pass;
    private static int $port;

    public static function setUpBeforeClass(): void
    {
        self::$host = getenv('DB_HOST') ?: 'appsalon-test-db';
        self::$user = getenv('DB_USER') ?: 'root';
        self::$pass = getenv('DB_PASS') ?: 'root';
        self::$port = (int)(getenv('DB_PORT') ?: 3306);

        self::$db = new mysqli(self::$host, self::$user, self::$pass, '', self::$port);
        self::$db->set_charset('utf8mb4');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db) {
            self::$db->query("DROP DATABASE IF EXISTS appsalon_fresh_test");
            self::$db->query("DROP DATABASE IF EXISTS appsalon_upgrade_test");
            self::$db->query("DROP DATABASE IF EXISTS appsalon_upgrade_data_test");
            self::$db->query("DROP DATABASE IF EXISTS appsalon_fresh_migrador_test");
            self::$db->close();
        }
    }

    private function ejecutarMigrador(string $comando, string $dbName): array
    {
        $cmd = "php " . escapeshellarg(__DIR__ . '/../../database/migrador.php') . " " . escapeshellarg($comando);
        $descriptorSpec = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"]
        ];

        $env = array_merge($_ENV, [
            'DB_HOST' => self::$host,
            'DB_USER' => self::$user,
            'DB_PASS' => self::$pass,
            'DB_NAME' => $dbName,
            'DB_PORT' => (string)self::$port
        ]);

        $process = proc_open($cmd, $descriptorSpec, $pipes, null, $env);
        $this->assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'exitCode' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr
        ];
    }

    private function revertirPosteriores(mysqli $db, int $version): void
    {
        $archivos = glob(__DIR__ . '/../../database/migrations/*_rollback.sql');
        rsort($archivos);
        foreach ($archivos as $archivo) {
            if ((int)substr(basename($archivo), 0, 3) <= $version) { continue; }
            $nombre = basename($archivo, '_rollback.sql');
            $registro = $db->prepare('SELECT id FROM migraciones WHERE migracion=?');
            $registro->bind_param('s', $nombre); $registro->execute();
            $aplicada = $registro->get_result()->num_rows > 0; $registro->close();
            if (!$aplicada) { continue; }
            $this->assertTrue($db->multi_query((string)file_get_contents($archivo)), "Rollback {$nombre}");
            do {
                if ($resultado = $db->store_result()) { $resultado->free(); }
                if (!$db->more_results()) { break; }
                $this->assertTrue($db->next_result(), "Every rollback statement must succeed: {$nombre}");
            } while (true);
            $registro = $db->prepare('DELETE FROM migraciones WHERE migracion=?');
            $registro->bind_param('s', $nombre); $this->assertTrue($registro->execute()); $registro->close();
        }
    }

    public function testMigracionesPlataformaReaplicablesYPreservanUsuarioLegado(): void
    {
        $nombreDb = 'appsalon_plataforma_migraciones_test';
        self::$db->query("DROP DATABASE IF EXISTS {$nombreDb}");
        self::$db->query("CREATE DATABASE {$nombreDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $db = new mysqli(self::$host, self::$user, self::$pass, $nombreDb, self::$port);
        try {
            $db->multi_query((string)file_get_contents(__DIR__ . '/../../database/schema_base.sql'));
            do {
                if ($resultado = $db->store_result()) { $resultado->free(); }
                if (!$db->more_results()) { break; }
                $this->assertTrue($db->next_result());
            } while (true);
            $hash = password_hash('LegacySeguro123!', PASSWORD_BCRYPT);
            $stmt = $db->prepare("INSERT INTO usuarios(nombre,apellido,email,password,telefono,admin,confirmado) VALUES ('Legacy','Dueño','legacy@plataforma.test',?,'0991234567',1,1)");
            $stmt->bind_param('s', $hash); $this->assertTrue($stmt->execute()); $id = (int)$db->insert_id; $stmt->close();
            $esperado = $db->query("SELECT nombre,apellido,email,password,telefono,admin,confirmado FROM usuarios WHERE id={$id}")->fetch_assoc();
            foreach (['up', 'up'] as $comando) {
                $res = $this->ejecutarMigrador($comando, $nombreDb);
                $this->assertSame(0, $res['exitCode'], $res['stdout'] . $res['stderr']);
            }
            $tablas = array_column($db->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetch_all(MYSQLI_NUM), 0);
            foreach (['usuario_accesos','perfil_fotos','agenda_eventos','caja_movimientos','inventario_productos','formularios_cliente'] as $tabla) { $this->assertContains($tabla, $tablas); }
            $this->revertirPosteriores($db, 9);
            $this->assertSame(9, (int)$db->query('SELECT COUNT(*) total FROM migraciones')->fetch_assoc()['total']);
            $this->assertSame($esperado, $db->query("SELECT nombre,apellido,email,password,telefono,admin,confirmado FROM usuarios WHERE id={$id}")->fetch_assoc());
            $res = $this->ejecutarMigrador('up', $nombreDb);
            $this->assertSame(0, $res['exitCode'], $res['stdout'] . $res['stderr']);
            $this->assertSame($esperado, $db->query("SELECT nombre,apellido,email,password,telefono,admin,confirmado FROM usuarios WHERE id={$id}")->fetch_assoc());
            $this->assertSame('propietario', $db->query("SELECT rol FROM usuario_accesos WHERE usuario_id={$id}")->fetch_assoc()['rol']);
        } finally { $db->close(); self::$db->query("DROP DATABASE IF EXISTS {$nombreDb}"); }
    }

    public function testActualizacionDesdeInstalacionPreviaPreservaDatosYGeneraMismoEsquemaQueFresh(): void
    {
        // 1. Preparar base con instalación previa y esquema de cc4e133 (001 ya aplicada)
        self::$db->query("DROP DATABASE IF EXISTS appsalon_upgrade_data_test");
        self::$db->query("CREATE DATABASE appsalon_upgrade_data_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $upgradeDb = new mysqli(self::$host, self::$user, self::$pass, 'appsalon_upgrade_data_test', self::$port);

        $schemaBase = file_get_contents(__DIR__ . '/../../database/schema_base.sql');
        $upgradeDb->multi_query($schemaBase);
        while ($upgradeDb->more_results() && $upgradeDb->next_result()) { if ($res = $upgradeDb->store_result()) $res->free(); }

        $sql001 = file_get_contents(__DIR__ . '/../../database/migrations/001_security_hardening.sql');
        $upgradeDb->multi_query($sql001);
        while ($upgradeDb->more_results() && $upgradeDb->next_result()) { if ($res = $upgradeDb->store_result()) $res->free(); }

        // Registrar 001 como aplicada en migraciones
        $upgradeDb->query("INSERT INTO migraciones (migracion, aplicada_en) VALUES ('001_security_hardening', NOW())");

        // 2. Insertar datos representativos en la instalación existente
        $passwordHash = password_hash('cliente_seguro_123', PASSWORD_BCRYPT);
        $upgradeDb->query(
            "INSERT INTO usuarios (nombre, apellido, email, password, telefono, admin, confirmado, token, token_hash, token_tipo, token_expira) 
             VALUES ('Carlos', 'Gomez', 'carlos@cliente.com', '{$passwordHash}', '5511223344', 0, 1, '', NULL, NULL, NULL)"
        );
        $clienteId = $upgradeDb->insert_id;

        $upgradeDb->query(
            "INSERT INTO usuarios (nombre, apellido, email, password, telefono, admin, confirmado, token, token_hash, token_tipo, token_expira) 
             VALUES ('Admin', 'Salon', 'admin@appsalon.com', '{$passwordHash}', '5599887766', 1, 1, '', NULL, NULL, NULL)"
        );
        $adminId = $upgradeDb->insert_id;

        $upgradeDb->query("INSERT INTO servicios (nombre, precio) VALUES ('Corte de Cabello Premium', 150.00)");
        $servicio1Id = $upgradeDb->insert_id;
        $upgradeDb->query("INSERT INTO servicios (nombre, precio) VALUES ('Tratamiento Capilar', 250.00)");
        $servicio2Id = $upgradeDb->insert_id;

        $upgradeDb->query("INSERT INTO citas (fecha, hora, usuarioId) VALUES ('2026-11-15', '11:00', {$clienteId})");
        $citaId = $upgradeDb->insert_id;

        $upgradeDb->query("INSERT INTO citasservicios (citaId, servicioId) VALUES ({$citaId}, {$servicio1Id})");
        $upgradeDb->query("INSERT INTO citasservicios (citaId, servicioId) VALUES ({$citaId}, {$servicio2Id})");

        // 3. Ejecutar migrador en la instalación existente a través de subproceso
        $resUpgrade = $this->ejecutarMigrador('up', 'appsalon_upgrade_data_test');
        $this->assertSame(0, $resUpgrade['exitCode'], 'El migrador debe terminar con código 0 en actualización');
        $this->assertStringContainsString('[-] Migración ya aplicada: 001_security_hardening', $resUpgrade['stdout']);
        $this->assertStringContainsString('[+] Aplicando migración: 002_innodb_and_rate_limit_window', $resUpgrade['stdout']);
        $this->assertStringContainsString('[OK] Migración 002_innodb_and_rate_limit_window aplicada exitosamente.', $resUpgrade['stdout']);
        $this->assertStringContainsString('[+] Aplicando migración: 003_profesionales_duracion_y_horarios', $resUpgrade['stdout']);
        $this->assertStringContainsString('[OK] Migración 003_profesionales_duracion_y_horarios aplicada exitosamente.', $resUpgrade['stdout']);
        $this->assertStringContainsString('[+] Aplicando migración: 004_reservas_profesional_ocupacion_historico', $resUpgrade['stdout']);
        $this->assertStringContainsString('[OK] Migración 004_reservas_profesional_ocupacion_historico aplicada exitosamente.', $resUpgrade['stdout']);
        $this->assertStringContainsString('[+] Aplicando migración: 005_notificaciones_citas', $resUpgrade['stdout']);
        $this->assertStringContainsString('[OK] Migración 005_notificaciones_citas aplicada exitosamente.', $resUpgrade['stdout']);
        $this->assertStringContainsString('[+] Aplicando migración: 006_base_pagos', $resUpgrade['stdout']);
        $this->assertStringContainsString('[OK] Migración 006_base_pagos aplicada exitosamente.', $resUpgrade['stdout']);

        // 4. Demostrar preservación íntegra de los datos existentes completos
        $resUsuario = $upgradeDb->query("SELECT * FROM usuarios WHERE id = {$clienteId}")->fetch_assoc();
        $this->assertSame('Carlos', $resUsuario['nombre']);
        $this->assertSame('Gomez', $resUsuario['apellido']);
        $this->assertSame('carlos@cliente.com', $resUsuario['email']);
        $this->assertSame('5511223344', $resUsuario['telefono']);
        $this->assertSame('0', (string)$resUsuario['admin']);
        $this->assertSame('1', (string)$resUsuario['confirmado']);
        $this->assertSame($passwordHash, $resUsuario['password']);

        $resAdmin = $upgradeDb->query("SELECT * FROM usuarios WHERE id = {$adminId}")->fetch_assoc();
        $this->assertSame('Admin', $resAdmin['nombre']);
        $this->assertSame('Salon', $resAdmin['apellido']);
        $this->assertSame('admin@appsalon.com', $resAdmin['email']);
        $this->assertSame('5599887766', $resAdmin['telefono']);
        $this->assertSame('1', (string)$resAdmin['admin']);
        $this->assertSame('1', (string)$resAdmin['confirmado']);
        $this->assertSame($passwordHash, $resAdmin['password']);

        $resServicios = $upgradeDb->query("SELECT COUNT(*) AS total FROM servicios")->fetch_assoc();
        $this->assertSame(2, (int)$resServicios['total']);

        $resS1 = $upgradeDb->query("SELECT * FROM servicios WHERE id = {$servicio1Id}")->fetch_assoc();
        $this->assertSame('Corte de Cabello Premium', $resS1['nombre']);
        $this->assertEquals(150.00, (float)$resS1['precio']);
        $this->assertSame(30, (int)$resS1['duracion_minutos']);

        $resS2 = $upgradeDb->query("SELECT * FROM servicios WHERE id = {$servicio2Id}")->fetch_assoc();
        $this->assertSame('Tratamiento Capilar', $resS2['nombre']);
        $this->assertEquals(250.00, (float)$resS2['precio']);
        $this->assertSame(30, (int)$resS2['duracion_minutos']);

        $resCitas = $upgradeDb->query("SELECT * FROM citas WHERE id = {$citaId}")->fetch_assoc();
        $this->assertSame('2026-11-15', $resCitas['fecha']);
        $this->assertSame('11:00:00', $resCitas['hora']);
        $this->assertSame((string)$clienteId, (string)$resCitas['usuarioId']);
        $this->assertNull($resCitas['profesionalId'], 'Las citas históricas no deben recibir asignaciones inventadas de profesional');
        $this->assertSame('pendiente', $resCitas['estado_pago']);
        $this->assertNull($resCitas['hora_inicio']);
        $this->assertNull($resCitas['hora_fin']);
        $this->assertNull($resCitas['duracion_total_minutos']);

        $resCS = $upgradeDb->query("SELECT servicioId, nombre_servicio, precio_servicio, duracion_minutos FROM citasservicios WHERE citaId = {$citaId} ORDER BY servicioId ASC");
        $csRows = [];
        while ($row = $resCS->fetch_assoc()) {
            $csRows[] = (int)$row['servicioId'];
            $this->assertNull($row['nombre_servicio']);
            $this->assertNull($row['precio_servicio']);
            $this->assertNull($row['duracion_minutos']);
        }
        $this->assertEquals([(int)$servicio1Id, (int)$servicio2Id], $csRows);

        // 5. Preparar y ejecutar instalación desde cero ejecutada también a través del migrador
        self::$db->query("DROP DATABASE IF EXISTS appsalon_fresh_migrador_test");
        self::$db->query("CREATE DATABASE appsalon_fresh_migrador_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $freshDb = new mysqli(self::$host, self::$user, self::$pass, 'appsalon_fresh_migrador_test', self::$port);

        $freshDb->multi_query($schemaBase);
        while ($freshDb->more_results() && $freshDb->next_result()) { if ($res = $freshDb->store_result()) $res->free(); }

        $resFresh = $this->ejecutarMigrador('up', 'appsalon_fresh_migrador_test');
        $this->assertSame(0, $resFresh['exitCode'], 'El migrador debe terminar con código 0 en fresh');
        $this->assertStringContainsString('[+] Aplicando migración: 001_security_hardening', $resFresh['stdout']);
        $this->assertStringContainsString('[+] Aplicando migración: 002_innodb_and_rate_limit_window', $resFresh['stdout']);
        $this->assertStringContainsString('[+] Aplicando migración: 003_profesionales_duracion_y_horarios', $resFresh['stdout']);
        $this->assertStringContainsString('[+] Aplicando migración: 004_reservas_profesional_ocupacion_historico', $resFresh['stdout']);
        $this->assertStringContainsString('[+] Aplicando migración: 005_notificaciones_citas', $resFresh['stdout']);
        $this->assertStringContainsString('[+] Aplicando migración: 006_base_pagos', $resFresh['stdout']);

        // 6. Comparar esquemas exactos entre ambas instalaciones: columnas, tipos, nulabilidad, defaults, índices y motores
        // Compare every migrated table, including newly added platform modules.
        $tablas = array_column($freshDb->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetch_all(MYSQLI_NUM), 0);
        $tablasUpgrade = array_column($upgradeDb->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetch_all(MYSQLI_NUM), 0);
        sort($tablas);
        sort($tablasUpgrade);
        $this->assertSame($tablas, $tablasUpgrade, 'Fresh and upgrade must contain exactly the same complete table set');
        $this->assertContains('usuarios', $tablas);
        $this->assertContains('pagos', $tablas);
        $migracionesEsperadas = array_map(static fn(string $file): string => basename($file, '.sql'), array_filter(
            glob(__DIR__ . '/../../database/migrations/[0-9][0-9][0-9]_*.sql'),
            static fn(string $file): bool => !str_ends_with($file, '_rollback.sql')
        ));
        sort($migracionesEsperadas);
        foreach ([$freshDb, $upgradeDb] as $conexion) {
            $aplicadas = array_column($conexion->query('SELECT migracion FROM migraciones ORDER BY migracion')->fetch_all(MYSQLI_NUM), 0);
            $this->assertSame($migracionesEsperadas, $aplicadas, 'Every migration file must be applied and recorded exactly once');
        }
        foreach ($tablas as $tabla) {
            $colsUpgrade = [];
            $resColsU = $upgradeDb->query("SHOW FULL COLUMNS FROM {$tabla}");
            while ($row = $resColsU->fetch_assoc()) {
                $colsUpgrade[$row['Field']] = [
                    'type' => $row['Type'],
                    'null' => $row['Null'],
                    'default' => $row['Default'],
                    'extra' => $row['Extra']
                ];
            }

            $colsFresh = [];
            $resColsF = $freshDb->query("SHOW FULL COLUMNS FROM {$tabla}");
            while ($row = $resColsF->fetch_assoc()) {
                $colsFresh[$row['Field']] = [
                    'type' => $row['Type'],
                    'null' => $row['Null'],
                    'default' => $row['Default'],
                    'extra' => $row['Extra']
                ];
            }

            $this->assertEquals($colsFresh, $colsUpgrade, "Las columnas, tipos, nulabilidad y defaults de la tabla {$tabla} deben coincidir 100% entre fresh y upgrade");

            // Comparar índices, claves y unicidad
            $idxUpgrade = [];
            $resIdxU = $upgradeDb->query("SHOW INDEX FROM {$tabla}");
            while ($row = $resIdxU->fetch_assoc()) {
                $idxUpgrade[$row['Key_name']][$row['Seq_in_index']] = [
                    'column' => $row['Column_name'],
                    'non_unique' => (int)$row['Non_unique']
                ];
            }

            $idxFresh = [];
            $resIdxF = $freshDb->query("SHOW INDEX FROM {$tabla}");
            while ($row = $resIdxF->fetch_assoc()) {
                $idxFresh[$row['Key_name']][$row['Seq_in_index']] = [
                    'column' => $row['Column_name'],
                    'non_unique' => (int)$row['Non_unique']
                ];
            }

            $this->assertEquals($idxFresh, $idxUpgrade, "Los índices y restricciones de unicidad de la tabla {$tabla} deben coincidir 100% entre fresh y upgrade");

            // Validar motor InnoDB
            $motorU = $upgradeDb->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'appsalon_upgrade_data_test' AND TABLE_NAME = '{$tabla}'")->fetch_assoc();
            $this->assertSame('InnoDB', $motorU['ENGINE'], "Motor en upgrade para {$tabla} debe ser InnoDB");

            $motorF = $freshDb->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'appsalon_fresh_migrador_test' AND TABLE_NAME = '{$tabla}'")->fetch_assoc();
            $this->assertSame('InnoDB', $motorF['ENGINE'], "Motor en fresh para {$tabla} debe ser InnoDB");
        }

        // Compare referential actions too: columns/index parity alone misses CASCADE/SET NULL regressions.
        $foreignKeys = static function (mysqli $conexion, string $schema): array {
            $stmt = $conexion->prepare("SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION, k.COLUMN_NAME,
                k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE
                FROM information_schema.KEY_COLUMN_USAGE k
                JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
                WHERE k.CONSTRAINT_SCHEMA = ? ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION");
            $stmt->bind_param('s', $schema);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            return $rows;
        };
        $this->assertSame($foreignKeys($freshDb, 'appsalon_fresh_migrador_test'), $foreignKeys($upgradeDb, 'appsalon_upgrade_data_test'));

        $upgradeDb->close();
        $freshDb->close();
    }

    public function testMigradorFallaConEstadoParcialCuandoInsercionEnHistorialFalla(): void
    {
        $dbName = getenv('DB_NAME') ?: 'appsalon_test';
        $dbTest = new mysqli(self::$host, self::$user, self::$pass, $dbName, self::$port);

        $tempMigrationFile = __DIR__ . '/../../database/migrations/998_falla_historial_test.sql';
        file_put_contents($tempMigrationFile, "CREATE TABLE IF NOT EXISTS tabla_falla_historial_test (id INT PRIMARY KEY) ENGINE=InnoDB;\n");

        // Crear trigger en migraciones para forzar error exclusivo en la inserción de esta migración
        $dbTest->query("DROP TRIGGER IF EXISTS test_fail_migraciones_trigger");
        $dbTest->query(
            "CREATE TRIGGER test_fail_migraciones_trigger BEFORE INSERT ON migraciones
             FOR EACH ROW BEGIN
                 IF NEW.migracion = '998_falla_historial_test' THEN
                     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Simulated history table insert failure';
                 END IF;
             END"
        );

        try {
            $res = $this->ejecutarMigrador('up', $dbName);

            // Debe terminar con código 1
            $this->assertSame(1, $res['exitCode'], 'El migrador debe terminar con código 1 ante fallo en tabla de historial');
            $this->assertStringContainsString('[ERROR] Fallo crítico al registrar la migración 998_falla_historial_test en la tabla \'migraciones\'', $res['stdout']);
            $this->assertStringContainsString('[ESTADO PARCIAL]', $res['stdout']);
            $this->assertStringNotContainsString('[OK] Migración 998_falla_historial_test aplicada exitosamente', $res['stdout']);

            // Verificar estado parcial: la tabla DDL fue creada, pero migraciones no contiene el registro
            $checkTable = $dbTest->query("SHOW TABLES LIKE 'tabla_falla_historial_test'");
            $this->assertSame(1, $checkTable->num_rows, 'La tabla física debe existir demostrando estado parcial');

            $checkHistorial = $dbTest->query("SELECT id FROM migraciones WHERE migracion = '998_falla_historial_test'");
            $this->assertSame(0, $checkHistorial->num_rows, 'El registro de historial no debe constar como completado');
        } finally {
            $dbTest->query("DROP TRIGGER IF EXISTS test_fail_migraciones_trigger");
            $dbTest->query("DROP TABLE IF EXISTS tabla_falla_historial_test");
            $dbTest->query("DELETE FROM migraciones WHERE migracion = '998_falla_historial_test'");
            $dbTest->close();
            if (file_exists($tempMigrationFile)) {
                unlink($tempMigrationFile);
            }
        }
    }

    public function testMigradorFallaConCodigoDistintoDeCeroAnteErrorSqlIntermedio(): void
    {
        $tempMigrationFile = __DIR__ . '/../../database/migrations/999_falla_intermedia_test.sql';
        
        // Crear migración con error de sintaxis en la segunda sentencia
        $sqlConError = "CREATE TABLE IF NOT EXISTS tabla_temp_test (id INT PRIMARY KEY) ENGINE=InnoDB;\n"
                     . "SENTENCIA SQL TOTALMENTE INVALIDA ERROR;\n";
        file_put_contents($tempMigrationFile, $sqlConError);

        $dbName = getenv('DB_NAME') ?: 'appsalon_test';

        try {
            $res = $this->ejecutarMigrador('up', $dbName);

            // Debe salir con código 1 (distinto de cero)
            $this->assertSame(1, $res['exitCode'], "El migrador debe terminar con código de salida 1 ante error SQL intermedio");
            $this->assertStringContainsString("Fallo intermedio en la migración 999_falla_intermedia_test", $res['stdout']);

            // Comprobar que NO se registró en la tabla migraciones
            $dbTest = new mysqli(self::$host, self::$user, self::$pass, $dbName, self::$port);
            $check = $dbTest->query("SELECT id FROM migraciones WHERE migracion = '999_falla_intermedia_test'");
            $this->assertSame(0, $check->num_rows, "La migración fallida NO debe haberse registrado en la tabla migraciones");
            
            // Limpiar tabla temporal creada por la primera sentencia
            $dbTest->query("DROP TABLE IF EXISTS tabla_temp_test");
            $dbTest->close();
        } finally {
            if (file_exists($tempMigrationFile)) {
                unlink($tempMigrationFile);
            }
        }
    }

    public function testMigracion005NotificacionesCitasUpIdempotenciaSetNullYRollback(): void
    {
        self::$db->query("DROP DATABASE IF EXISTS appsalon_mig005_test");
        self::$db->query("CREATE DATABASE appsalon_mig005_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $db005 = new mysqli(self::$host, self::$user, self::$pass, 'appsalon_mig005_test', self::$port);

        try {
            $schemaBase = file_get_contents(__DIR__ . '/../../database/schema_base.sql');
            $db005->multi_query($schemaBase);
            while ($db005->more_results() && $db005->next_result()) {
                if ($res = $db005->store_result()) {
                    $res->free();
                }
            }

            // 1. Aplicar todas las migraciones incluyendo 005
            $resUp1 = $this->ejecutarMigrador('up', 'appsalon_mig005_test');
            $this->assertSame(0, $resUp1['exitCode']);
            $this->assertStringContainsString('[+] Aplicando migración: 005_notificaciones_citas', $resUp1['stdout']);
            $this->assertStringContainsString('[OK] Migración 005_notificaciones_citas aplicada exitosamente.', $resUp1['stdout']);

            // 2. Segunda ejecución debe ser idempotente
            $resUp2 = $this->ejecutarMigrador('up', 'appsalon_mig005_test');
            $this->assertSame(0, $resUp2['exitCode']);
            $this->assertStringContainsString('[-] Migración ya aplicada: 005_notificaciones_citas', $resUp2['stdout']);

            // 3. Verificar ON DELETE SET NULL en cita_id conservando cita_referencia_id y restricción UNIQUE en clave_idempotencia
            $hash = password_hash('clave123', PASSWORD_BCRYPT);
            $db005->query("INSERT INTO usuarios (nombre, apellido, email, password, telefono, admin, confirmado) VALUES ('Ana', 'Paz', 'ana@test.local', '{$hash}', '0991112223', 0, 1)");
            $uid = (int)$db005->insert_id;

            $db005->query("INSERT INTO citas (fecha, hora, usuarioId) VALUES ('2026-12-10', '10:00:00', {$uid})");
            $cid = (int)$db005->insert_id;

            $db005->query(
                "INSERT INTO notificaciones_citas
                    (cita_id, cita_referencia_id, usuario_id, tipo, clave_idempotencia, destinatario_email, destinatario_nombre, asunto, estado, intentos, max_intentos)
                 VALUES
                    ({$cid}, {$cid}, {$uid}, 'confirmacion', 'cita:{$cid}:confirmacion', 'ana@test.local', 'Ana Paz', 'Confirmación', 'enviado', 1, 3)"
            );
            $nid = (int)$db005->insert_id;
            $this->assertGreaterThan(0, $nid);

            // Intento de duplicar clave_idempotencia debe fallar por UNIQUE KEY
            try {
                $okDup = @$db005->query(
                    "INSERT INTO notificaciones_citas
                        (cita_id, cita_referencia_id, usuario_id, tipo, clave_idempotencia, destinatario_email, destinatario_nombre, asunto, estado, intentos, max_intentos)
                     VALUES
                        ({$cid}, {$cid}, {$uid}, 'confirmacion', 'cita:{$cid}:confirmacion', 'ana@test.local', 'Ana Paz', 'Confirmación dup', 'pendiente', 0, 3)"
                );
            } catch (\mysqli_sql_exception $e) {
                $okDup = false;
            }
            $this->assertFalse($okDup, 'La restricción uq_notificaciones_clave_idempotencia debe impedir duplicados');

            // Eliminar la cita en `citas`: la fila en `notificaciones_citas` debe permanecer con cita_id = NULL y cita_referencia_id = $cid
            $db005->query("DELETE FROM citas WHERE id = {$cid}");
            $filaNotif = $db005->query("SELECT cita_id, cita_referencia_id, clave_idempotencia, estado FROM notificaciones_citas WHERE id = {$nid}")->fetch_assoc();
            $this->assertNotNull($filaNotif, 'El registro de notificación debe sobrevivir al borrado de la cita gracias a ON DELETE SET NULL');
            $this->assertNull($filaNotif['cita_id']);
            $this->assertSame($cid, (int)$filaNotif['cita_referencia_id']);
            $this->assertSame("cita:{$cid}:confirmacion", $filaNotif['clave_idempotencia']);

            // 4. Rollback de 005_notificaciones_citas
            $this->revertirPosteriores($db005, 5);
            $rollbackSql = file_get_contents(__DIR__ . '/../../database/migrations/005_notificaciones_citas_rollback.sql');
            $db005->multi_query($rollbackSql);
            while ($db005->more_results() && $db005->next_result()) {
                if ($res = $db005->store_result()) {
                    $res->free();
                }
            }

            $checkTabla = $db005->query("SHOW TABLES LIKE 'notificaciones_citas'");
            $this->assertSame(0, $checkTabla->num_rows, 'El rollback de 005 debe eliminar la tabla notificaciones_citas');

            // 5. Reaplicar 005 tras rollback debe funcionar limpiamente
            $resReup = $this->ejecutarMigrador('up', 'appsalon_mig005_test');
            $this->assertSame(0, $resReup['exitCode']);
            $this->assertStringContainsString('[+] Aplicando migración: 005_notificaciones_citas', $resReup['stdout']);
        } finally {
            $db005->close();
            self::$db->query("DROP DATABASE IF EXISTS appsalon_mig005_test");
        }
    }

    public function testMigracion006BasePagosUpIdempotenciaSetNullYRollback(): void
    {
        self::$db->query("DROP DATABASE IF EXISTS appsalon_mig006_test");
        self::$db->query("CREATE DATABASE appsalon_mig006_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $db006 = new mysqli(self::$host, self::$user, self::$pass, 'appsalon_mig006_test', self::$port);

        try {
            $schemaBase = file_get_contents(__DIR__ . '/../../database/schema_base.sql');
            $db006->multi_query($schemaBase);
            while ($db006->more_results() && $db006->next_result()) {
                if ($res = $db006->store_result()) {
                    $res->free();
                }
            }

            // 1. Aplicar todas las migraciones incluyendo 006_base_pagos
            $resUp1 = $this->ejecutarMigrador('up', 'appsalon_mig006_test');
            $this->assertSame(0, $resUp1['exitCode']);
            $this->assertStringContainsString('[+] Aplicando migración: 006_base_pagos', $resUp1['stdout']);
            $this->assertStringContainsString('[OK] Migración 006_base_pagos aplicada exitosamente.', $resUp1['stdout']);

            // 2. Segunda ejecución debe ser idempotente
            $resUp2 = $this->ejecutarMigrador('up', 'appsalon_mig006_test');
            $this->assertSame(0, $resUp2['exitCode']);
            $this->assertStringContainsString('[-] Migración ya aplicada: 006_base_pagos', $resUp2['stdout']);

            // 3. Verificar restricciones UNIQUE (uq_pagos_referencia, uq_pagos_transaccion_externa, uq_pagos_idempotency_key)
            //    y ON DELETE SET NULL en cita_id conservando cita_referencia_id
            $hash = password_hash('clave123', PASSWORD_BCRYPT);
            $db006->query("INSERT INTO usuarios (nombre, apellido, email, password, telefono, admin, confirmado) VALUES ('Luis', 'Vega', 'luis@test.local', '{$hash}', '0991112223', 0, 1)");
            $uid = (int)$db006->insert_id;

            $db006->query("INSERT INTO citas (fecha, hora, usuarioId) VALUES ('2026-12-15', '11:00:00', {$uid})");
            $cid = (int)$db006->insert_id;

            $filaCita = $db006->query("SELECT estado_pago FROM citas WHERE id = {$cid}")->fetch_assoc();
            $this->assertSame('pendiente', $filaCita['estado_pago']);

            $db006->query(
                "INSERT INTO pagos
                    (cita_id, cita_referencia_id, usuario_id, referencia_pago, proveedor, transaccion_externa_id, monto, moneda, estado, metodo_pago, idempotency_key)
                 VALUES
                    ({$cid}, {$cid}, {$uid}, 'PAY-20261215-ABCDEF01', 'mock', 'MOCK-TX-001', 125.00, 'USD', 'completado', 'tarjeta_mock', 'pago:cita:{$cid}:intento')"
            );
            $pid = (int)$db006->insert_id;
            $this->assertGreaterThan(0, $pid);

            // Duplicado en referencia_pago debe fallar por uq_pagos_referencia
            try {
                $okDupRef = @$db006->query(
                    "INSERT INTO pagos
                        (cita_id, cita_referencia_id, usuario_id, referencia_pago, proveedor, transaccion_externa_id, monto, moneda, estado, idempotency_key)
                     VALUES
                        ({$cid}, {$cid}, {$uid}, 'PAY-20261215-ABCDEF01', 'mock', 'MOCK-TX-002', 125.00, 'USD', 'pendiente', 'pago:cita:{$cid}:intento2')"
                );
            } catch (\mysqli_sql_exception $e) {
                $okDupRef = false;
            }
            $this->assertFalse($okDupRef, 'uq_pagos_referencia debe impedir referencias duplicadas');

            // Duplicado en idempotency_key debe fallar por uq_pagos_idempotency_key
            try {
                $okDupKey = @$db006->query(
                    "INSERT INTO pagos
                        (cita_id, cita_referencia_id, usuario_id, referencia_pago, proveedor, transaccion_externa_id, monto, moneda, estado, idempotency_key)
                     VALUES
                        ({$cid}, {$cid}, {$uid}, 'PAY-20261215-ABCDEF02', 'mock', 'MOCK-TX-003', 125.00, 'USD', 'pendiente', 'pago:cita:{$cid}:intento')"
                );
            } catch (\mysqli_sql_exception $e) {
                $okDupKey = false;
            }
            $this->assertFalse($okDupKey, 'uq_pagos_idempotency_key debe impedir claves de idempotencia duplicadas');

            // Eliminar la cita en `citas`: la fila en `pagos` debe permanecer con cita_id = NULL y cita_referencia_id = $cid
            $db006->query("DELETE FROM citas WHERE id = {$cid}");
            $filaPago = $db006->query("SELECT cita_id, cita_referencia_id, referencia_pago, estado FROM pagos WHERE id = {$pid}")->fetch_assoc();
            $this->assertNotNull($filaPago, 'El registro de pago debe sobrevivir al borrado de la cita gracias a ON DELETE SET NULL');
            $this->assertNull($filaPago['cita_id']);
            $this->assertSame($cid, (int)$filaPago['cita_referencia_id']);
            $this->assertSame('PAY-20261215-ABCDEF01', $filaPago['referencia_pago']);

            // 4. Rollback de 006_base_pagos
            $this->revertirPosteriores($db006, 6);
            $rollbackSql = file_get_contents(__DIR__ . '/../../database/migrations/006_base_pagos_rollback.sql');
            $db006->multi_query($rollbackSql);
            while ($db006->more_results() && $db006->next_result()) {
                if ($res = $db006->store_result()) {
                    $res->free();
                }
            }

            $checkTabla = $db006->query("SHOW TABLES LIKE 'pagos'");
            $this->assertSame(0, $checkTabla->num_rows, 'El rollback de 006 debe eliminar la tabla pagos');

            $checkCol = $db006->query("SHOW COLUMNS FROM citas LIKE 'estado_pago'");
            $this->assertSame(0, $checkCol->num_rows, 'El rollback de 006 debe eliminar la columna estado_pago de citas');

            // 5. Reaplicar 006 tras rollback debe funcionar limpiamente
            $resReup = $this->ejecutarMigrador('up', 'appsalon_mig006_test');
            $this->assertSame(0, $resReup['exitCode']);
            $this->assertStringContainsString('[+] Aplicando migración: 006_base_pagos', $resReup['stdout']);
        } finally {
            $db006->close();
            self::$db->query("DROP DATABASE IF EXISTS appsalon_mig006_test");
        }
    }

    /**
     * Verifica que la migración 007 (`007_citas_estado_cancelacion`) se aplique,
     * permita el uso de los nuevos campos de estado y trazabilidad, y revierta limpiamente.
     */
    public function testMigracion007CitasEstadoCancelacionUpYRollback(): void
    {
        self::$db->query("DROP DATABASE IF EXISTS appsalon_mig007_test");
        self::$db->query("CREATE DATABASE appsalon_mig007_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $db007 = new mysqli(self::$host, self::$user, self::$pass, 'appsalon_mig007_test', self::$port);
        $db007->set_charset('utf8mb4');

        try {
            $schemaBase = file_get_contents(__DIR__ . '/../../database/schema_base.sql');
            $db007->multi_query($schemaBase);
            while ($db007->more_results() && $db007->next_result()) {
                if ($res = $db007->store_result()) {
                    $res->free();
                }
            }

            // Aplicar todas las migraciones hasta 007
            $resUp = $this->ejecutarMigrador('up', 'appsalon_mig007_test');
            $this->assertSame(0, $resUp['exitCode']);
            $this->assertStringContainsString('[+] Aplicando migración: 007_citas_estado_cancelacion', $resUp['stdout']);

            // Verificar columnas de citas
            $checkEstado = $db007->query("SHOW COLUMNS FROM citas LIKE 'estado'")->fetch_assoc();
            $this->assertNotNull($checkEstado, 'Columna estado debe existir en citas tras 007');

            $checkCanceladoPor = $db007->query("SHOW COLUMNS FROM citas LIKE 'cancelado_por'")->fetch_assoc();
            $this->assertNotNull($checkCanceladoPor, 'Columna cancelado_por debe existir en citas tras 007');

            $checkCanceladoAt = $db007->query("SHOW COLUMNS FROM citas LIKE 'cancelado_at'")->fetch_assoc();
            $this->assertNotNull($checkCanceladoAt, 'Columna cancelado_at debe existir en citas tras 007');

            $checkMotivo = $db007->query("SHOW COLUMNS FROM citas LIKE 'motivo_cancelacion'")->fetch_assoc();
            $this->assertNotNull($checkMotivo, 'Columna motivo_cancelacion debe existir en citas tras 007');

            // Rollback de 007
            $this->revertirPosteriores($db007, 7);
            $rollbackSql = file_get_contents(__DIR__ . '/../../database/migrations/007_citas_estado_cancelacion_rollback.sql');
            $db007->multi_query($rollbackSql);
            while ($db007->more_results() && $db007->next_result()) {
                if ($res = $db007->store_result()) {
                    $res->free();
                }
            }

            $checkCol = $db007->query("SHOW COLUMNS FROM citas LIKE 'estado'");
            $this->assertSame(0, $checkCol->num_rows, 'Rollback de 007 debe eliminar la columna estado');
        } finally {
            $db007->close();
            self::$db->query("DROP DATABASE IF EXISTS appsalon_mig007_test");
        }
    }

    /**
     * Verifica que la migración 008 (`008_notificaciones_outbox_lease`) se aplique,
     * incorpore worker_token y lease_expires_at, y revierta limpiamente.
     */
    public function testMigracion008NotificacionesOutboxLeaseUpYRollback(): void
    {
        self::$db->query("DROP DATABASE IF EXISTS appsalon_mig008_test");
        self::$db->query("CREATE DATABASE appsalon_mig008_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $db008 = new mysqli(self::$host, self::$user, self::$pass, 'appsalon_mig008_test', self::$port);
        $db008->set_charset('utf8mb4');

        try {
            $schemaBase = file_get_contents(__DIR__ . '/../../database/schema_base.sql');
            $db008->multi_query($schemaBase);
            while ($db008->more_results() && $db008->next_result()) {
                if ($res = $db008->store_result()) {
                    $res->free();
                }
            }

            // Aplicar todas las migraciones hasta 008
            $resUp = $this->ejecutarMigrador('up', 'appsalon_mig008_test');
            $this->assertSame(0, $resUp['exitCode']);
            $this->assertStringContainsString('[+] Aplicando migración: 008_notificaciones_outbox_lease', $resUp['stdout']);

            $checkToken = $db008->query("SHOW COLUMNS FROM notificaciones_citas LIKE 'worker_token'")->fetch_assoc();
            $this->assertNotNull($checkToken, 'Columna worker_token debe existir tras 008');

            $checkLease = $db008->query("SHOW COLUMNS FROM notificaciones_citas LIKE 'lease_expires_at'")->fetch_assoc();
            $this->assertNotNull($checkLease, 'Columna lease_expires_at debe existir tras 008');

            // Rollback de 008
            $this->revertirPosteriores($db008, 8);
            $rollbackSql = file_get_contents(__DIR__ . '/../../database/migrations/008_notificaciones_outbox_lease_rollback.sql');
            $db008->multi_query($rollbackSql);
            while ($db008->more_results() && $db008->next_result()) {
                if ($res = $db008->store_result()) {
                    $res->free();
                }
            }

            $checkCol = $db008->query("SHOW COLUMNS FROM notificaciones_citas LIKE 'worker_token'");
            $this->assertSame(0, $checkCol->num_rows, 'Rollback de 008 debe eliminar worker_token');
        } finally {
            $db008->close();
            self::$db->query("DROP DATABASE IF EXISTS appsalon_mig008_test");
        }
    }
}

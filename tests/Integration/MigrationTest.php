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

        // 4. Demostrar preservación íntegra de los datos existentes
        $resUsuario = $upgradeDb->query("SELECT * FROM usuarios WHERE id = {$clienteId}")->fetch_assoc();
        $this->assertSame('Carlos', $resUsuario['nombre']);
        $this->assertSame('carlos@cliente.com', $resUsuario['email']);
        $this->assertSame('1', (string)$resUsuario['confirmado']);

        $resServicios = $upgradeDb->query("SELECT COUNT(*) AS total FROM servicios")->fetch_assoc();
        $this->assertSame(2, (int)$resServicios['total']);

        $resCitas = $upgradeDb->query("SELECT * FROM citas WHERE id = {$citaId}")->fetch_assoc();
        $this->assertSame('2026-11-15', $resCitas['fecha']);
        $this->assertSame('11:00:00', $resCitas['hora']);
        $this->assertSame((string)$clienteId, (string)$resCitas['usuarioId']);

        $resCS = $upgradeDb->query("SELECT COUNT(*) AS total FROM citasservicios WHERE citaId = {$citaId}")->fetch_assoc();
        $this->assertSame(2, (int)$resCS['total']);

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

        // 6. Comparar esquemas exactos entre ambas instalaciones
        $tablas = ['usuarios', 'servicios', 'citas', 'citasservicios', 'intentos_login', 'migraciones'];
        foreach ($tablas as $tabla) {
            $colsUpgrade = [];
            $resColsU = $upgradeDb->query("SHOW COLUMNS FROM {$tabla}");
            while ($row = $resColsU->fetch_assoc()) {
                $colsUpgrade[$row['Field']] = $row['Type'];
            }

            $colsFresh = [];
            $resColsF = $freshDb->query("SHOW COLUMNS FROM {$tabla}");
            while ($row = $resColsF->fetch_assoc()) {
                $colsFresh[$row['Field']] = $row['Type'];
            }

            $this->assertEquals($colsFresh, $colsUpgrade, "Las columnas de la tabla {$tabla} deben coincidir 100% entre fresh y upgrade");

            // Validar motor InnoDB
            $motorU = $upgradeDb->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'appsalon_upgrade_data_test' AND TABLE_NAME = '{$tabla}'")->fetch_assoc();
            $this->assertSame('InnoDB', $motorU['ENGINE'], "Motor en upgrade para {$tabla} debe ser InnoDB");

            $motorF = $freshDb->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'appsalon_fresh_migrador_test' AND TABLE_NAME = '{$tabla}'")->fetch_assoc();
            $this->assertSame('InnoDB', $motorF['ENGINE'], "Motor en fresh para {$tabla} debe ser InnoDB");
        }

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
}

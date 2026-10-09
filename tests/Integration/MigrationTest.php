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
            self::$db->close();
        }
    }

    public function testInstalacionDesdeCeroYActualizacionIncrementalGeneranMismoEsquema(): void
    {
        // 1. Crear base para instalación desde cero
        self::$db->query("DROP DATABASE IF EXISTS appsalon_fresh_test");
        self::$db->query("CREATE DATABASE appsalon_fresh_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $freshDb = new mysqli(self::$host, self::$user, self::$pass, 'appsalon_fresh_test', self::$port);

        // 2. Crear base para actualización incremental desde cc4e133
        self::$db->query("DROP DATABASE IF EXISTS appsalon_upgrade_test");
        self::$db->query("CREATE DATABASE appsalon_upgrade_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $upgradeDb = new mysqli(self::$host, self::$user, self::$pass, 'appsalon_upgrade_test', self::$port);

        // Cargar esquema base en ambas
        $schemaBase = file_get_contents(__DIR__ . '/../../database/schema_base.sql');
        $freshDb->multi_query($schemaBase);
        while ($freshDb->more_results() && $freshDb->next_result()) { if ($res = $freshDb->store_result()) $res->free(); }

        $upgradeDb->multi_query($schemaBase);
        while ($upgradeDb->more_results() && $upgradeDb->next_result()) { if ($res = $upgradeDb->store_result()) $res->free(); }

        // En upgradeDb: aplicar primero 001
        $sql001 = file_get_contents(__DIR__ . '/../../database/migrations/001_security_hardening.sql');
        $upgradeDb->multi_query($sql001);
        while ($upgradeDb->more_results() && $upgradeDb->next_result()) { if ($res = $upgradeDb->store_result()) $res->free(); }

        // Luego aplicar incremental 002 en upgradeDb
        $sql002 = file_get_contents(__DIR__ . '/../../database/migrations/002_innodb_and_rate_limit_window.sql');
        $upgradeDb->multi_query($sql002);
        while ($upgradeDb->more_results() && $upgradeDb->next_result()) { if ($res = $upgradeDb->store_result()) $res->free(); }

        // En freshDb: aplicar 001 y luego 002 secuencialmente
        $freshDb->multi_query($sql001);
        while ($freshDb->more_results() && $freshDb->next_result()) { if ($res = $freshDb->store_result()) $res->free(); }

        $freshDb->multi_query($sql002);
        while ($freshDb->more_results() && $freshDb->next_result()) { if ($res = $freshDb->store_result()) $res->free(); }

        // 3. Comparar tablas, columnas y motores
        $tablas = ['usuarios', 'servicios', 'citas', 'citasservicios', 'intentos_login'];
        foreach ($tablas as $tabla) {
            // Comparar columnas
            $resFresh = $freshDb->query("SHOW COLUMNS FROM {$tabla}");
            $colsFresh = [];
            while ($row = $resFresh->fetch_assoc()) {
                $colsFresh[$row['Field']] = $row['Type'];
            }

            $resUpgrade = $upgradeDb->query("SHOW COLUMNS FROM {$tabla}");
            $colsUpgrade = [];
            while ($row = $resUpgrade->fetch_assoc()) {
                $colsUpgrade[$row['Field']] = $row['Type'];
            }

            $this->assertEquals($colsFresh, $colsUpgrade, "Las columnas de la tabla {$tabla} deben ser idénticas entre fresh y upgrade");

            // Comparar motor InnoDB
            $resEngineFresh = $freshDb->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'appsalon_fresh_test' AND TABLE_NAME = '{$tabla}'");
            $filaEFresh = $resEngineFresh->fetch_assoc();
            $this->assertSame('InnoDB', $filaEFresh['ENGINE'], "La tabla {$tabla} en fresh debe ser InnoDB");

            $resEngineUpgrade = $upgradeDb->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'appsalon_upgrade_test' AND TABLE_NAME = '{$tabla}'");
            $filaEUpgrade = $resEngineUpgrade->fetch_assoc();
            $this->assertSame('InnoDB', $filaEUpgrade['ENGINE'], "La tabla {$tabla} en upgrade debe ser InnoDB");
        }

        // Verificar columna primera_peticion en intentos_login
        $this->assertArrayHasKey('primera_peticion', $colsFresh);
        $this->assertArrayHasKey('primera_peticion', $colsUpgrade);

        $freshDb->close();
        $upgradeDb->close();
    }

    public function testMigradorFallaConCodigoDistintoDeCeroAnteErrorSqlIntermedio(): void
    {
        $tempMigrationFile = __DIR__ . '/../../database/migrations/999_falla_intermedia_test.sql';
        
        // Crear migración con error de sintaxis en la segunda sentencia
        $sqlConError = "CREATE TABLE IF NOT EXISTS tabla_temp_test (id INT PRIMARY KEY) ENGINE=InnoDB;\n"
                     . "SENTENCIA SQL TOTALMENTE INVALIDA ERROR;\n";
        file_put_contents($tempMigrationFile, $sqlConError);

        try {
            $cmd = "php " . escapeshellarg(__DIR__ . '/../../database/migrador.php') . " up";
            $descriptorSpec = [
                0 => ["pipe", "r"],
                1 => ["pipe", "w"],
                2 => ["pipe", "w"]
            ];

            $env = array_merge($_ENV, [
                'DB_HOST' => self::$host,
                'DB_USER' => self::$user,
                'DB_PASS' => self::$pass,
                'DB_NAME' => getenv('DB_NAME') ?: 'appsalon_test',
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

            // Debe salir con código 1 (distinto de cero)
            $this->assertSame(1, $exitCode, "El migrador debe terminar con código de salida 1 ante error SQL intermedio");
            $this->assertStringContainsString("Fallo intermedio en la migración 999_falla_intermedia_test", $stdout);

            // Comprobar que NO se registró en la tabla migraciones
            $dbTest = new mysqli(self::$host, self::$user, self::$pass, getenv('DB_NAME') ?: 'appsalon_test', self::$port);
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

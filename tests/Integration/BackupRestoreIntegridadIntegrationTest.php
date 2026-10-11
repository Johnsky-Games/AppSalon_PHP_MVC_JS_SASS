<?php

declare(strict_types=1);

namespace Tests\Integration;

use mysqli;
use PHPUnit\Framework\TestCase;

class BackupRestoreIntegridadIntegrationTest extends TestCase
{
    private static mysqli $db;
    private static string $host;
    private static string $user;
    private static string $pass;
    private static int $port;
    private static string $mainDbName;

    public static function setUpBeforeClass(): void
    {
        self::$host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1';
        self::$user = $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'root';
        self::$pass = $_ENV['DB_PASS'] ?? (getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'root');
        self::$port = (int)($_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 3306);
        self::$mainDbName = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'appsalon_test';

        self::$db = new mysqli(self::$host, self::$user, self::$pass, self::$mainDbName, self::$port);
        self::$db->set_charset('utf8mb4');
        validar_base_datos_prueba(self::$db);
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$db) && self::$db instanceof mysqli && @self::$db->ping()) {
            self::$db->close();
        }
    }

    /**
     * Ejecuta un script CLI de la aplicacion bajo el entorno de pruebas aislado.
     *
     * @param list<string> $args
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function ejecutarScriptCli(string $scriptRelativo, array $args, string $dbName): array
    {
        $scriptPath = realpath(__DIR__ . '/../../' . ltrim($scriptRelativo, '/\\'));
        $partes = [escapeshellarg(PHP_BINARY), escapeshellarg((string)$scriptPath)];
        foreach ($args as $arg) {
            $partes[] = escapeshellarg($arg);
        }
        $cmd = implode(' ', $partes);

        $env = array_merge($_ENV, [
            'APP_ENV' => 'testing',
            'DB_HOST' => self::$host,
            'DB_USER' => self::$user,
            'DB_PASS' => self::$pass,
            'DB_NAME' => $dbName,
            'DB_PORT' => (string)self::$port,
            'PATH' => getenv('PATH') ?: '',
            'SystemRoot' => getenv('SystemRoot') ?: '',
        ]);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes, realpath(__DIR__ . '/../../'), $env);
        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'exitCode' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    /**
     * Escenario 1: Archivo truncado tras una sentencia completa: detectado y abortado sin corromper la base existente.
     */
    public function testArchivoTruncadoTrasSentenciaCompletaEsDetectadoYAbortadoSinCorromperBaseExistente(): void
    {
        $dstDb = 'appsalon_int_trunc_test';
        $tmpDir = sys_get_temp_dir();
        $archivoTruncado = $tmpDir . DIRECTORY_SEPARATOR . 'backup_trunc_' . uniqid('', true) . '.sql';

        self::$db->query("DROP DATABASE IF EXISTS `{$dstDb}`");
        self::$db->query("CREATE DATABASE `{$dstDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $dbDst = new mysqli(self::$host, self::$user, self::$pass, $dstDb, self::$port);
        $dbDst->set_charset('utf8mb4');
        $dbDst->query("CREATE TABLE `registro_seguridad` (id INT AUTO_INCREMENT PRIMARY KEY, testigo VARCHAR(100))");
        $dbDst->query("INSERT INTO `registro_seguridad` (testigo) VALUES ('DATOS_ORIGINALES_PRESERVADOS')");
        $dbDst->close();

        // Contenido con encabezado oficial pero truncado tras una sentencia valida completa (sin el marcador de cierre)
        $sqlTruncado = implode("\n", [
            "-- ============================================================================",
            "-- AppSalon SQL Backup (Snapshot Consistente InnoDB)",
            "-- Base de datos origen: `test_db`",
            "-- ============================================================================",
            "SET NAMES utf8mb4;",
            "CREATE TABLE `nueva_tabla_no_debe_existir` (id INT PRIMARY KEY, valor VARCHAR(50));",
            "INSERT INTO `nueva_tabla_no_debe_existir` (id, valor) VALUES (1, 'no_crear');",
            "-- Archivo cortado abruptamente aqui antes del final oficial",
        ]);
        file_put_contents($archivoTruncado, $sqlTruncado);

        try {
            $resultado = $this->ejecutarScriptCli(
                'scripts/restore_db.php',
                ['--db=' . $dstDb, '--input=' . $archivoTruncado],
                $dstDb
            );

            $this->assertSame(1, $resultado['exitCode'], 'restore_db.php debe fallar ante un archivo truncado');
            $this->assertStringContainsString('truncado o incompleto', $resultado['stderr']);

            // Verificar que la base destino esta 100% intacta
            $dbVerif = new mysqli(self::$host, self::$user, self::$pass, $dstDb, self::$port);
            $res = $dbVerif->query("SELECT testigo FROM `registro_seguridad` WHERE id = 1");
            $this->assertNotNull($res);
            $fila = $res->fetch_assoc();
            $this->assertSame('DATOS_ORIGINALES_PRESERVADOS', $fila['testigo'] ?? null);

            $resTablaNueva = $dbVerif->query("SHOW TABLES LIKE 'nueva_tabla_no_debe_existir'");
            $this->assertSame(0, $resTablaNueva->num_rows, 'La nueva tabla no debio crearse en la base destino');
            $dbVerif->close();
        } finally {
            self::$db->query("DROP DATABASE IF EXISTS `{$dstDb}`");
            if (is_file($archivoTruncado)) {
                @unlink($archivoTruncado);
            }
        }
    }

    /**
     * Escenario 2: Archivo alterado (hash invalido): rechazado antes de ejecutar DDL.
     */
    public function testArchivoAlteradoConHashInvalidoEsRechazadoAntesDeEjecutarDDL(): void
    {
        $dstDb = 'appsalon_int_hash_test';
        $tmpDir = sys_get_temp_dir();
        $archivoAlterado = $tmpDir . DIRECTORY_SEPARATOR . 'backup_alterado_' . uniqid('', true) . '.sql';

        self::$db->query("DROP DATABASE IF EXISTS `{$dstDb}`");
        self::$db->query("CREATE DATABASE `{$dstDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $dbDst = new mysqli(self::$host, self::$user, self::$pass, $dstDb, self::$port);
        $dbDst->set_charset('utf8mb4');
        $dbDst->query("CREATE TABLE `datos_originales` (id INT PRIMARY KEY, marca VARCHAR(50))");
        $dbDst->query("INSERT INTO `datos_originales` VALUES (1, 'INTACTOS_POR_HASH')");
        $dbDst->close();

        $sqlValido = implode("\n", [
            "-- AppSalon SQL Backup",
            "SET NAMES utf8mb4;",
            "CREATE TABLE `tabla_intrusiva` (id INT PRIMARY KEY);",
            "-- Fin del respaldo AppSalon",
        ]);
        file_put_contents($archivoAlterado, $sqlValido);

        // Crear manifiesto con hash diferente (alterado)
        $manifiestoFile = $archivoAlterado . '.manifest.json';
        $manifiestoData = [
            'version' => '1.0',
            'sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', // hash falso
        ];
        file_put_contents($manifiestoFile, json_encode($manifiestoData));

        try {
            // Caso A: Deteccion por manifiesto adyacente
            $resManifest = $this->ejecutarScriptCli(
                'scripts/restore_db.php',
                ['--db=' . $dstDb, '--input=' . $archivoAlterado],
                $dstDb
            );
            $this->assertSame(1, $resManifest['exitCode']);
            $this->assertStringContainsString('Verificación de integridad fallida', $resManifest['stderr']);

            // Caso B: Deteccion por parametro explicito --hash
            $resParam = $this->ejecutarScriptCli(
                'scripts/restore_db.php',
                ['--db=' . $dstDb, '--input=' . $archivoAlterado, '--hash=0000000000000000000000000000000000000000000000000000000000000000'],
                $dstDb
            );
            $this->assertSame(1, $resParam['exitCode']);
            $this->assertStringContainsString('Verificación de integridad fallida', $resParam['stderr']);

            // Verificar que la base destino jamas sufrio DDL
            $dbVerif = new mysqli(self::$host, self::$user, self::$pass, $dstDb, self::$port);
            $res = $dbVerif->query("SELECT marca FROM `datos_originales` WHERE id = 1");
            $this->assertSame('INTACTOS_POR_HASH', $res->fetch_assoc()['marca'] ?? null);

            $resIntrusa = $dbVerif->query("SHOW TABLES LIKE 'tabla_intrusiva'");
            $this->assertSame(0, $resIntrusa->num_rows);
            $dbVerif->close();
        } finally {
            self::$db->query("DROP DATABASE IF EXISTS `{$dstDb}`");
            if (is_file($archivoAlterado)) {
                @unlink($archivoAlterado);
            }
            if (is_file($manifiestoFile)) {
                @unlink($manifiestoFile);
            }
        }
    }

    /**
     * Escenario 3: Error a mitad de restauracion: preservacion del destino existente.
     */
    public function testErrorAMitadDeRestauracionPreservaIntactaLaBaseDestinoExistente(): void
    {
        $dstDb = 'appsalon_int_mid_test';
        $tmpDir = sys_get_temp_dir();
        $archivoFalloMedio = $tmpDir . DIRECTORY_SEPARATOR . 'backup_mid_err_' . uniqid('', true) . '.sql';

        self::$db->query("DROP DATABASE IF EXISTS `{$dstDb}`");
        self::$db->query("CREATE DATABASE `{$dstDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $dbDst = new mysqli(self::$host, self::$user, self::$pass, $dstDb, self::$port);
        $dbDst->set_charset('utf8mb4');
        $dbDst->query("CREATE TABLE `datos_criticos` (id INT PRIMARY KEY, valor VARCHAR(100))");
        $dbDst->query("INSERT INTO `datos_criticos` VALUES (1, 'VALOR_CRITICO_NO_TOCAR')");
        $dbDst->close();

        // Sentencias donde las primeras son validas pero a mitad hay un error de ejecucion SQL
        $sqlConFalloEnMedio = implode("\n", [
            "-- ============================================================================",
            "-- AppSalon SQL Backup",
            "-- ============================================================================",
            "SET NAMES utf8mb4;",
            "CREATE TABLE `tabla_staging_inicial` (id INT PRIMARY KEY, test VARCHAR(20));",
            "INSERT INTO `tabla_staging_inicial` VALUES (1, 'ok');",
            "INSERT INTO `tabla_inexistente_que_produce_fallo` VALUES (999, 'error');",
            "CREATE TABLE `tabla_posterior` (id INT PRIMARY KEY);",
            "-- Fin del respaldo AppSalon",
        ]);
        file_put_contents($archivoFalloMedio, $sqlConFalloEnMedio);

        // Crear manifiesto valido para que pase la verificacion de hash y alcance la fase de ejecucion
        $manifiestoFile = $archivoFalloMedio . '.manifest.json';
        $manifiestoData = [
            'version' => '1.0',
            'sha256' => hash_file('sha256', $archivoFalloMedio),
        ];
        file_put_contents($manifiestoFile, json_encode($manifiestoData));

        try {
            $resultado = $this->ejecutarScriptCli(
                'scripts/restore_db.php',
                ['--db=' . $dstDb, '--input=' . $archivoFalloMedio, '--safe-restore'],
                $dstDb
            );

            $this->assertSame(1, $resultado['exitCode'], 'El script debe salir con codigo 1 ante un fallo de sentencia a mitad de ejecucion');
            $this->assertStringContainsString('Error en sentencia', $resultado['stderr']);

            // Verificar que la base destino se preservo intacta gracias a safe-restore (staging aislado)
            $dbVerif = new mysqli(self::$host, self::$user, self::$pass, $dstDb, self::$port);
            $res = $dbVerif->query("SELECT valor FROM `datos_criticos` WHERE id = 1");
            $this->assertNotNull($res);
            $this->assertSame('VALOR_CRITICO_NO_TOCAR', $res->fetch_assoc()['valor'] ?? null);

            $resStaging = $dbVerif->query("SHOW TABLES LIKE 'tabla_staging_inicial'");
            $this->assertSame(0, $resStaging->num_rows, 'Ninguna tabla parcial debio impactar en la base destino');
            $dbVerif->close();
        } finally {
            self::$db->query("DROP DATABASE IF EXISTS `{$dstDb}`");
            if (is_file($archivoFalloMedio)) {
                @unlink($archivoFalloMedio);
            }
            if (is_file($manifiestoFile)) {
                @unlink($manifiestoFile);
            }
        }
    }

    /**
     * Escenario 4: Roundtrip exitoso de todas las tablas migradas con comprobacion de integridad.
     */
    public function testRoundtripExitosoDeTodasLasTablasMigradasConComprobacionDeIntegridad(): void
    {
        $srcDb = 'appsalon_int_roundtrip_src_test';
        $dstDb = 'appsalon_int_roundtrip_dst_test';
        $tmpDir = sys_get_temp_dir();
        $archivoGz = $tmpDir . DIRECTORY_SEPARATOR . 'backup_roundtrip_' . uniqid('', true) . '.sql.gz';
        $manifiestoGz = $archivoGz . '.manifest.json';

        self::$db->query("DROP DATABASE IF EXISTS `{$srcDb}`");
        self::$db->query("DROP DATABASE IF EXISTS `{$dstDb}`");
        self::$db->query("CREATE DATABASE `{$srcDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $dbSrc = new mysqli(self::$host, self::$user, self::$pass, $srcDb, self::$port);
        $dbSrc->set_charset('utf8mb4');

        try {
            // Aplicar schema_base y migraciones incrementales completas
            $schemaBase = (string)file_get_contents(__DIR__ . '/../../database/schema_base.sql');
            $dbSrc->multi_query($schemaBase);
            while ($dbSrc->more_results() && $dbSrc->next_result()) {
                if ($res = $dbSrc->store_result()) {
                    $res->free();
                }
            }

            $resMig = $this->ejecutarScriptCli('database/migrador.php', ['up'], $srcDb);
            $this->assertSame(0, $resMig['exitCode'], 'Migrador debe culminar con 0: ' . $resMig['stderr']);

            // Poblar registros en las tablas del esquema
            $hashClave = password_hash('Pass123!', PASSWORD_BCRYPT);
            $dbSrc->query("INSERT INTO usuarios (nombre, apellido, email, password, telefono, admin, confirmado) VALUES ('Admin', 'Audit', 'admin@audit.test', '{$hashClave}', '0991112222', 1, 1)");
            $adminId = (int)$dbSrc->insert_id;

            $dbSrc->query("INSERT INTO servicios (nombre, precio, duracion_minutos) VALUES ('Corte Especial — Con Signos & Amp;', 25.00, 45)");
            $srvId = (int)$dbSrc->insert_id;

            $dbSrc->query("INSERT INTO profesionales (nombre, activo) VALUES ('Estilista Master', 1)");
            $profId = (int)$dbSrc->insert_id;

            $dbSrc->query("INSERT INTO profesionales_servicios (profesionalId, servicioId) VALUES ({$profId}, {$srvId})");
            $dbSrc->query("INSERT INTO horarios_profesionales (profesionalId, dia_semana, hora_inicio, hora_fin) VALUES ({$profId}, 2, '09:00:00', '18:00:00')");
            $dbSrc->query("INSERT INTO descansos_profesionales (profesionalId, dia_semana, hora_inicio, hora_fin, motivo) VALUES ({$profId}, 2, '13:00:00', '14:00:00', 'Almuerzo')");
            $dbSrc->query("INSERT INTO bloqueos_profesionales (profesionalId, fecha_inicio, fecha_fin, hora_inicio, hora_fin, motivo) VALUES ({$profId}, '2026-12-01', '2026-12-01', '15:00:00', '16:00:00', 'Inventario')");

            $dbSrc->query("INSERT INTO citas (fecha, hora, hora_inicio, hora_fin, duracion_total_minutos, usuarioId, profesionalId, estado, estado_pago) VALUES ('2026-12-01', '10:00:00', '10:00:00', '10:45:00', 45, {$adminId}, {$profId}, 'confirmada', 'pagado')");
            $citaId = (int)$dbSrc->insert_id;

            $dbSrc->query("INSERT INTO citasservicios (citaId, servicioId, nombre_servicio, precio_servicio, duracion_minutos) VALUES ({$citaId}, {$srvId}, 'Corte Especial — Con Signos & Amp;', 25.00, 45)");

            $dbSrc->query("INSERT INTO notificaciones_citas (cita_id, cita_referencia_id, usuario_id, tipo, clave_idempotencia, destinatario_email, destinatario_nombre, asunto, estado, intentos, max_intentos) VALUES ({$citaId}, {$citaId}, {$adminId}, 'confirmacion', 'cita:{$citaId}:confirmacion', 'admin@audit.test', 'Admin Audit', 'Confirmación', 'enviado', 1, 3)");

            $dbSrc->query("INSERT INTO pagos (cita_id, cita_referencia_id, usuario_id, referencia_pago, proveedor, transaccion_externa_id, monto, moneda, estado, metodo_pago, idempotency_key) VALUES ({$citaId}, {$citaId}, {$adminId}, 'PAY-ROUNDTRIP-01', 'mock', 'MOCK-TX-01', 25.00, 'USD', 'completado', 'simulado', 'idem-rt-01')");

            $dbSrc->query("INSERT INTO intentos_login (tipo, identificador, primera_peticion, ultima_peticion, intentos, bloqueado_hasta) VALUES ('ip', '127.0.0.1', NOW(), NOW(), 1, NULL)");

            // 1. Ejecutar respaldo
            $resBackup = $this->ejecutarScriptCli(
                'scripts/backup_db.php',
                ['--db=' . $srcDb, '--output=' . $archivoGz, '--gzip'],
                $srcDb
            );
            $this->assertSame(0, $resBackup['exitCode'], 'backup_db.php debe culminar con 0: ' . $resBackup['stderr']);
            $this->assertFileExists($archivoGz);
            $this->assertFileExists($manifiestoGz);

            // Validar estructura y coherencia del manifiesto
            $manContent = (string)file_get_contents($manifiestoGz);
            $manData = json_decode($manContent, true);
            $this->assertIsArray($manData);
            $this->assertSame('America/Guayaquil', $manData['timezone']);
            $this->assertSame(13, $manData['total_tablas']);
            $this->assertSame(hash_file('sha256', $archivoGz), $manData['sha256']);
            $this->assertCount(13, $manData['tablas']);
            $this->assertCount(13, $manData['filas_por_tabla']);

            // 2. Restaurar en base limpia
            $resRestore = $this->ejecutarScriptCli(
                'scripts/restore_db.php',
                ['--db=' . $dstDb, '--input=' . $archivoGz, '--create-db'],
                $dstDb
            );
            $this->assertSame(0, $resRestore['exitCode'], 'restore_db.php debe culminar con 0: ' . $resRestore['stderr']);
            $this->assertStringContainsString('[OK] Restauración completada:', $resRestore['stdout']);

            // 3. Comprobar identidad de todas las tablas y conteo de filas
            $dbDst = new mysqli(self::$host, self::$user, self::$pass, $dstDb, self::$port);
            $dbDst->set_charset('utf8mb4');

            $tablasEsperadas = [
                'bloqueos_profesionales',
                'citas',
                'citasservicios',
                'descansos_profesionales',
                'horarios_profesionales',
                'intentos_login',
                'migraciones',
                'notificaciones_citas',
                'pagos',
                'profesionales',
                'profesionales_servicios',
                'servicios',
                'usuarios',
            ];

            foreach ($tablasEsperadas as $tabla) {
                $countSrc = (int)$dbSrc->query("SELECT COUNT(*) AS c FROM `{$tabla}`")->fetch_assoc()['c'];
                $countDst = (int)$dbDst->query("SELECT COUNT(*) AS c FROM `{$tabla}`")->fetch_assoc()['c'];
                $this->assertSame($countSrc, $countDst, "Conteo de filas en `{$tabla}` debe ser identico entre origen y destino");
            }

            // Comprobar registro con acentos y caracteres especiales
            $srvDst = $dbDst->query("SELECT nombre, precio FROM servicios WHERE id = {$srvId}")->fetch_assoc();
            $this->assertSame('Corte Especial — Con Signos & Amp;', $srvDst['nombre']);

            $dbDst->close();
        } finally {
            $dbSrc->close();
            self::$db->query("DROP DATABASE IF EXISTS `{$srcDb}`");
            self::$db->query("DROP DATABASE IF EXISTS `{$dstDb}`");
            if (is_file($archivoGz)) {
                @unlink($archivoGz);
            }
            if (is_file($manifiestoGz)) {
                @unlink($manifiestoGz);
            }
        }
    }
}

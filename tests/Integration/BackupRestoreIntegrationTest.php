<?php

declare(strict_types=1);

namespace Tests\Integration;

use Controllers\PagoController;
use Model\ActiveRecord;
use mysqli;
use PHPUnit\Framework\TestCase;

class BackupRestoreIntegrationTest extends TestCase
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

    /**
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

    public function testRespaldoYRestauracionCompletaEnBaseLimpiaPreservaTablasFilasRelacionesYCompresionGzip(): void
    {
        $srcDb = 'appsalon_backup_src_test';
        $dstPlainDb = 'appsalon_backup_dst_plain_test';
        $dstGzipDb = 'appsalon_backup_dst_gzip_test';

        $tmpDir = sys_get_temp_dir();
        $archivoSql = $tmpDir . DIRECTORY_SEPARATOR . 'appsalon_test_backup_' . uniqid('', true) . '.sql';
        $archivoGz = $tmpDir . DIRECTORY_SEPARATOR . 'appsalon_test_backup_' . uniqid('', true) . '.sql.gz';

        self::$db->query("DROP DATABASE IF EXISTS {$srcDb}");
        self::$db->query("DROP DATABASE IF EXISTS {$dstPlainDb}");
        self::$db->query("DROP DATABASE IF EXISTS {$dstGzipDb}");
        self::$db->query("CREATE DATABASE {$srcDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $dbSrc = new mysqli(self::$host, self::$user, self::$pass, $srcDb, self::$port);
        $dbSrc->set_charset('utf8mb4');

        try {
            // 1. Aplicar schema_base.sql y las 6 migraciones incrementales (001 a 006)
            $schemaBase = (string)file_get_contents(__DIR__ . '/../../database/schema_base.sql');
            $dbSrc->multi_query($schemaBase);
            while ($dbSrc->more_results() && $dbSrc->next_result()) {
                if ($res = $dbSrc->store_result()) {
                    $res->free();
                }
            }

            $resMig = $this->ejecutarScriptCli('database/migrador.php', ['up'], $srcDb);
            $this->assertSame(0, $resMig['exitCode'], 'El migrador debe aplicar 001..006 sin errores: ' . $resMig['stderr']);

            // 2. Poblar datos representativos en todas las tablas (incluyendo caracteres especiales, punto y coma, comillas y comentarios SQL)
            $hash = password_hash('ClaveSegura123!', PASSWORD_BCRYPT);
            $dbSrc->query(
                "INSERT INTO usuarios (nombre, apellido, email, password, telefono, admin, confirmado)
                 VALUES
                 ('Admin', 'Principal', 'admin@backup.test', '{$hash}', '0990001111', 1, 1),
                 ('Elena', 'Muñoz', 'elena@backup.test', '{$hash}', '0992223333', 0, 1)"
            );
            $clienteId = (int)$dbSrc->insert_id + 1;

            $nombreServicioComplejo = $dbSrc->real_escape_string("Corte & Diseño 'Premium' — 100%; -- nota");
            $dbSrc->query(
                "INSERT INTO servicios (nombre, precio, duracion_minutos)
                 VALUES
                 ('{$nombreServicioComplejo}', 45.50, 60),
                 ('Hidratación Capilar Profunda', 35.00, 30)"
            );
            $servicio1Id = (int)$dbSrc->insert_id;
            $servicio2Id = $servicio1Id + 1;

            $dbSrc->query("INSERT INTO profesionales (nombre, activo) VALUES ('Camila Paredes', 1)");
            $profId = (int)$dbSrc->insert_id;

            $dbSrc->query(
                "INSERT INTO profesionales_servicios (profesionalId, servicioId)
                 VALUES ({$profId}, {$servicio1Id}), ({$profId}, {$servicio2Id})"
            );
            $dbSrc->query(
                "INSERT INTO horarios_profesionales (profesionalId, dia_semana, hora_inicio, hora_fin)
                 VALUES ({$profId}, 1, '09:00:00', '18:00:00')"
            );
            $dbSrc->query(
                "INSERT INTO descansos_profesionales (profesionalId, dia_semana, hora_inicio, hora_fin, motivo)
                 VALUES ({$profId}, 1, '13:00:00', '14:00:00', 'Almuerzo')"
            );
            $dbSrc->query(
                "INSERT INTO bloqueos_profesionales (profesionalId, fecha_inicio, fecha_fin, hora_inicio, hora_fin, motivo)
                 VALUES ({$profId}, '2026-11-16', '2026-11-16', '16:00:00', '17:00:00', 'Capacitación')"
            );

            // Cita 1: permanece activa
            $dbSrc->query(
                "INSERT INTO citas (fecha, hora, hora_inicio, hora_fin, duracion_total_minutos, usuarioId, profesionalId, estado_pago)
                 VALUES ('2026-11-16', '10:00:00', '10:00:00', '11:30:00', 90, {$clienteId}, {$profId}, 'pagado')"
            );
            $citaActivaId = (int)$dbSrc->insert_id;

            $dbSrc->query(
                "INSERT INTO citasservicios (citaId, servicioId, nombre_servicio, precio_servicio, duracion_minutos)
                 VALUES
                 ({$citaActivaId}, {$servicio1Id}, '{$nombreServicioComplejo}', 45.50, 60),
                 ({$citaActivaId}, {$servicio2Id}, 'Hidratación Capilar Profunda', 35.00, 30)"
            );

            $dbSrc->query(
                "INSERT INTO notificaciones_citas
                    (cita_id, cita_referencia_id, usuario_id, tipo, clave_idempotencia, destinatario_email, destinatario_nombre, asunto, payload_json, estado, intentos, max_intentos, enviado_at)
                 VALUES
                    ({$citaActivaId}, {$citaActivaId}, {$clienteId}, 'confirmacion', 'cita:{$citaActivaId}:confirmacion', 'elena@backup.test', 'Elena Muñoz', 'Confirmación de Cita', '{\"total\":80.5}', 'enviado', 1, 3, '2026-11-10 15:00:00')"
            );

            $dbSrc->query(
                "INSERT INTO pagos
                    (cita_id, cita_referencia_id, usuario_id, referencia_pago, proveedor, transaccion_externa_id, monto, moneda, estado, metodo_pago, idempotency_key, payload_proveedor, pagado_at)
                 VALUES
                    ({$citaActivaId}, {$citaActivaId}, {$clienteId}, 'PAY-20261116-AABBCCDD11223344', 'mock', 'MOCK-TX-BACKUP-01', 80.50, 'USD', 'completado', 'simulado', 'idem-backup-01', '{\"modo\":\"aprobado\"}', '2026-11-10 15:05:00')"
            );

            // Cita 2: creada y luego eliminada para verificar preservación de ON DELETE SET NULL (cita_id = NULL, cita_referencia_id intacto)
            $dbSrc->query(
                "INSERT INTO citas (fecha, hora, hora_inicio, hora_fin, duracion_total_minutos, usuarioId, profesionalId, estado_pago)
                 VALUES ('2026-11-17', '11:00:00', '11:00:00', '12:00:00', 60, {$clienteId}, {$profId}, 'reembolsado')"
            );
            $citaBorradaId = (int)$dbSrc->insert_id;

            $dbSrc->query(
                "INSERT INTO notificaciones_citas
                    (cita_id, cita_referencia_id, usuario_id, tipo, clave_idempotencia, destinatario_email, destinatario_nombre, asunto, estado, intentos, max_intentos)
                 VALUES
                    ({$citaBorradaId}, {$citaBorradaId}, {$clienteId}, 'cancelacion', 'cita:{$citaBorradaId}:cancelacion', 'elena@backup.test', 'Elena Muñoz', 'Cancelación', 'enviado', 1, 3)"
            );
            $dbSrc->query(
                "INSERT INTO pagos
                    (cita_id, cita_referencia_id, usuario_id, referencia_pago, proveedor, transaccion_externa_id, monto, moneda, estado, metodo_pago, idempotency_key)
                 VALUES
                    ({$citaBorradaId}, {$citaBorradaId}, {$clienteId}, 'PAY-20261117-FFEEDDCC99887766', 'mock', 'MOCK-TX-BACKUP-02', 45.50, 'USD', 'reembolsado', 'simulado', 'idem-backup-02')"
            );
            $dbSrc->query("DELETE FROM citas WHERE id = {$citaBorradaId}");

            // 3. Ejecutar backup en texto plano (.sql) y comprimido (.sql.gz)
            $resBackupSql = $this->ejecutarScriptCli(
                'scripts/backup_db.php',
                ['--db=' . $srcDb, '--output=' . $archivoSql],
                $srcDb
            );
            $this->assertSame(0, $resBackupSql['exitCode'], 'backup_db.php (.sql) debe terminar con código 0: ' . $resBackupSql['stderr']);
            $this->assertStringContainsString('[OK] Respaldo completado:', $resBackupSql['stdout']);
            $this->assertFileExists($archivoSql);

            $resBackupGz = $this->ejecutarScriptCli(
                'scripts/backup_db.php',
                ['--db=' . $srcDb, '--output=' . $archivoGz, '--gzip'],
                $srcDb
            );
            $this->assertSame(0, $resBackupGz['exitCode'], 'backup_db.php (.sql.gz) debe terminar con código 0: ' . $resBackupGz['stderr']);
            $this->assertFileExists($archivoGz);

            // 4. Restaurar ambos respaldos en bases de datos limpias independientes
            $resRestorePlain = $this->ejecutarScriptCli(
                'scripts/restore_db.php',
                ['--db=' . $dstPlainDb, '--input=' . $archivoSql, '--create-db'],
                $dstPlainDb
            );
            $this->assertSame(0, $resRestorePlain['exitCode'], 'restore_db.php (.sql) debe terminar con código 0: ' . $resRestorePlain['stderr']);
            $this->assertStringContainsString('[OK] Restauración completada:', $resRestorePlain['stdout']);

            $resRestoreGz = $this->ejecutarScriptCli(
                'scripts/restore_db.php',
                ['--db=' . $dstGzipDb, '--input=' . $archivoGz, '--create-db'],
                $dstGzipDb
            );
            $this->assertSame(0, $resRestoreGz['exitCode'], 'restore_db.php (.sql.gz) debe terminar con código 0: ' . $resRestoreGz['stderr']);

            // 5. Verificar integridad total de tablas, conteos de filas, datos complejos y relaciones en ambas bases restauradas
            $tablasEsperadas = array_column($dbSrc->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetch_all(MYSQLI_NUM), 0);
            sort($tablasEsperadas);
            $this->assertGreaterThanOrEqual(13, count($tablasEsperadas), 'The complete legacy schema must remain present');

            foreach ([$dstPlainDb, $dstGzipDb] as $targetDbName) {
                $dbDst = new mysqli(self::$host, self::$user, self::$pass, $targetDbName, self::$port);
                $dbDst->set_charset('utf8mb4');

                $resT = $dbDst->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
                $tablasRestauradas = [];
                while ($rowT = $resT->fetch_row()) {
                    $tablasRestauradas[] = (string)$rowT[0];
                }
                sort($tablasRestauradas);
                $this->assertSame($tablasEsperadas, $tablasRestauradas, "Todas las tablas del esquema deben existir en {$targetDbName}");

                foreach ($tablasEsperadas as $tabla) {
                    $countSrc = (int)$dbSrc->query("SELECT COUNT(*) AS c FROM `{$tabla}`")->fetch_assoc()['c'];
                    $countDst = (int)$dbDst->query("SELECT COUNT(*) AS c FROM `{$tabla}`")->fetch_assoc()['c'];
                    $this->assertSame($countSrc, $countDst, "El número de filas en la tabla `{$tabla}` debe coincidir exactamente en {$targetDbName}");
                }

                // Verificar servicio con caracteres especiales (; ' -- &)
                $filaSrv = $dbDst->query("SELECT nombre, precio, duracion_minutos FROM servicios WHERE id = {$servicio1Id}")->fetch_assoc();
                $this->assertNotNull($filaSrv);
                $this->assertSame("Corte & Diseño 'Premium' — 100%; -- nota", $filaSrv['nombre']);
                $this->assertSame('45.50', $filaSrv['precio']);
                $this->assertSame(60, (int)$filaSrv['duracion_minutos']);

                // Verificar preservación de pago activo y pago con cita eliminada (cita_id = NULL, cita_referencia_id intacto)
                $pagoActivo = $dbDst->query("SELECT cita_id, cita_referencia_id, monto, estado, payload_proveedor FROM pagos WHERE referencia_pago = 'PAY-20261116-AABBCCDD11223344'")->fetch_assoc();
                $this->assertNotNull($pagoActivo);
                $this->assertSame($citaActivaId, (int)$pagoActivo['cita_id']);
                $this->assertSame('80.50', $pagoActivo['monto']);
                $this->assertSame('completado', $pagoActivo['estado']);

                $pagoHistorico = $dbDst->query("SELECT cita_id, cita_referencia_id, estado FROM pagos WHERE referencia_pago = 'PAY-20261117-FFEEDDCC99887766'")->fetch_assoc();
                $this->assertNotNull($pagoHistorico);
                $this->assertNull($pagoHistorico['cita_id'], 'cita_id NULL por ON DELETE SET NULL debe preservarse tras backup/restore');
                $this->assertSame($citaBorradaId, (int)$pagoHistorico['cita_referencia_id']);
                $this->assertSame('reembolsado', $pagoHistorico['estado']);

                $dbDst->close();
            }

            // 6. Verificar fallo controlado de restore_db.php ante archivo inexistente
            $resInexistente = $this->ejecutarScriptCli(
                'scripts/restore_db.php',
                ['--db=' . $dstPlainDb, '--input=' . $tmpDir . '/no_existe_respaldo.sql'],
                $dstPlainDb
            );
            $this->assertSame(1, $resInexistente['exitCode']);
        } finally {
            $dbSrc->close();
            self::$db->query("DROP DATABASE IF EXISTS {$srcDb}");
            self::$db->query("DROP DATABASE IF EXISTS {$dstPlainDb}");
            self::$db->query("DROP DATABASE IF EXISTS {$dstGzipDb}");
            if (is_file($archivoSql)) {
                @unlink($archivoSql);
            }
            if (is_file($archivoGz)) {
                @unlink($archivoGz);
            }
        }
    }

    protected function tearDown(): void
    {
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['HTTP_ACCEPT'] = '';
        $_SERVER['CONTENT_TYPE'] = '';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = [];
        $_GET = [];
        $_SESSION = [];
        PagoController::setPagoService(null);
        PagoController::setRawJsonOverride(null);
        parent::tearDown();
    }

    public function testAuditoriaIntegralDeCabecerasSeguridadRutasPermisosEIndices(): void
    {
        ActiveRecord::setDB(self::$db);

        // 1. Verificar emisión consistente de cabeceras de seguridad
        $headersSeguridad = emitir_cabeceras_seguridad();
        $this->assertSame('nosniff', $headersSeguridad['X-Content-Type-Options'] ?? null);
        $this->assertSame('SAMEORIGIN', $headersSeguridad['X-Frame-Options'] ?? null);
        $this->assertSame('strict-origin-when-cross-origin', $headersSeguridad['Referrer-Policy'] ?? null);

        // 2. Verificar protección de POST /api/pagos/reembolsar (bloquea cliente no admin con 403 y exige CSRF a admin)
        try {
            $_SESSION = [
                'id' => 999,
                'nombre' => 'Cliente',
                'apellido' => 'Prueba',
                'login' => true,
                'csrf_token' => 'token_csrf_cierre_tecnico',
            ];
            \Tests\ensureActiveIdentity(999, false);
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['REQUEST_URI'] = '/api/pagos/reembolsar';
            $_SERVER['HTTP_ACCEPT'] = 'application/json';
            $_POST = [
                'csrf_token' => 'token_csrf_cierre_tecnico',
                'referencia' => 'PAY-20261116-INEXISTENTE',
            ];

            ob_start();
            try {
                PagoController::reembolsar();
                $this->fail('PagoController::reembolsar debe rechazar con 403 a usuarios no administradores');
            } catch (\AppTerminationException $e) {
                $this->assertSame(403, $e->getStatusCode());
            } finally {
                $outCliente = (string)ob_get_clean();
            }
            $jsonCliente = json_decode($outCliente, true);
            $this->assertIsArray($jsonCliente);
            $this->assertFalse($jsonCliente['resultado']);

            // Admin sin CSRF válido -> 403
            $_SESSION['admin'] = '1';
            \Tests\ensureActiveIdentity(999, true);
            $_POST['csrf_token'] = 'token_invalido';
            ob_start();
            try {
                PagoController::reembolsar();
                $this->fail('PagoController::reembolsar debe exigir token CSRF válido');
            } catch (\AppTerminationException $e) {
                $this->assertSame(403, $e->getStatusCode());
            } finally {
                $outSinCsrf = (string)ob_get_clean();
            }
            $jsonSinCsrf = json_decode($outSinCsrf, true);
            $this->assertIsArray($jsonSinCsrf);
            $this->assertFalse($jsonSinCsrf['resultado']);
        } finally {
            $_SERVER['REQUEST_URI'] = '/';
            $_SERVER['HTTP_ACCEPT'] = '';
            $_SERVER['CONTENT_TYPE'] = '';
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_POST = [];
            $_GET = [];
            $_SESSION = [];
        }

        // 3. Verificar en INFORMATION_SCHEMA los índices y claves foráneas esenciales de las migraciones 001 a 006
        $indicesRequeridos = [
            'usuarios' => ['idx_token_hash', 'idx_token_expira'],
            'intentos_login' => ['uk_tipo_identificador', 'idx_bloqueado_hasta'],
            'profesionales' => ['idx_profesionales_activo'],
            'profesionales_servicios' => ['uk_profesional_servicio'],
            'horarios_profesionales' => ['idx_horarios_prof_dia'],
            'descansos_profesionales' => ['idx_descansos_prof_dia'],
            'bloqueos_profesionales' => ['idx_bloqueos_prof_fechas'],
            'citas' => ['idx_citas_prof_fecha_intervalo', 'idx_citas_estado_pago'],
            'notificaciones_citas' => ['uq_notificaciones_clave_idempotencia', 'idx_notificaciones_estado_proximo'],
            'pagos' => ['uq_pagos_referencia', 'uq_pagos_transaccion_externa', 'uq_pagos_idempotency_key'],
        ];

        foreach ($indicesRequeridos as $tabla => $nombresIndices) {
            $resIdx = self::$db->query("SHOW INDEX FROM `{$tabla}`");
            $this->assertNotFalse($resIdx, "Debe poder consultarse SHOW INDEX en `{$tabla}`");
            $encontrados = [];
            while ($filaIdx = $resIdx->fetch_assoc()) {
                $encontrados[] = (string)$filaIdx['Key_name'];
            }
            foreach ($nombresIndices as $idxEsperado) {
                $this->assertContains(
                    $idxEsperado,
                    $encontrados,
                    "La tabla `{$tabla}` debe contar con el índice `{$idxEsperado}`"
                );
            }
        }
    }
}

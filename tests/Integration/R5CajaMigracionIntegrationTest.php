<?php
namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/** Migraciones 024 (cobros_verificacion) y 025 (comprobantes): desde cero, sobre datos previos, rollback con guardián e idempotencia. */
final class R5CajaMigracionIntegrationTest extends TestCase
{
    private const BD = 'appsalon_p5mig_test';
    private \mysqli $admin;
    private \mysqli $db;

    protected function setUp(): void
    {
        $this->admin = new \mysqli(getenv('DB_HOST') ?: 'appsalon-test-db', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', '', (int)(getenv('DB_PORT') ?: 3306));
        $this->admin->query('DROP DATABASE IF EXISTS ' . self::BD);
        $this->admin->query('CREATE DATABASE ' . self::BD . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->admin->select_db(self::BD);
        $this->db = $this->admin;
        $this->sql((string)file_get_contents(__DIR__ . '/../../database/schema_base.sql'));
        $this->db->query("CREATE TABLE migraciones (id INT AUTO_INCREMENT PRIMARY KEY, migracion VARCHAR(255) NOT NULL UNIQUE, aplicada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
        $archivos = array_filter(glob(__DIR__ . '/../../database/migrations/*.sql'), fn($f) => !str_ends_with($f, '_rollback.sql'));
        sort($archivos);
        foreach ($archivos as $f) {
            $n = basename($f, '.sql');
            if ((int)substr($n, 0, 3) > 22) { continue; }
            $this->sql((string)file_get_contents($f));
            $this->db->query("INSERT INTO migraciones(migracion) VALUES ('$n')");
        }
    }

    protected function tearDown(): void
    {
        $this->admin->query('DROP DATABASE IF EXISTS ' . self::BD);
        $this->admin->close();
    }

    private function sql(string $sql): void
    {
        if (!$this->db->multi_query($sql)) { $this->fail('SQL: ' . $this->db->error); }
        do { if ($r = $this->db->store_result()) { $r->free(); } } while ($this->db->more_results() && $this->db->next_result());
        $this->assertSame(0, $this->db->errno, $this->db->error);
    }

    /** @return array{0:int,1:string} */
    private function migrador(string ...$args): array
    {
        $p = proc_open(array_merge([PHP_BINARY, '-d', 'variables_order=EGPCS', __DIR__ . '/../../database/migrador.php'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
            array_merge(getenv(), ['DB_NAME' => self::BD, 'DB_HOST' => getenv('DB_HOST') ?: 'appsalon-test-db']));
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        return [proc_close($p), $out];
    }

    private function uno(string $sql): mixed { $r = $this->db->query($sql)->fetch_row(); return $r ? $r[0] : null; }

    private function datosPrevios(): void
    {
        $this->db->query("INSERT INTO usuarios (nombre,apellido,email,password,telefono,admin,confirmado) VALUES ('Due','Ño','due@x.test','h','099',1,1),('Cli','Ente','cli@x.test','h','099',0,1)");
        $this->db->query("INSERT INTO citas (fecha,hora,usuarioId,estado) VALUES ('2030-01-02','10:00:00',2,'completada'),('2030-01-03','10:00:00',2,'completada'),('2030-01-04','10:00:00',2,'completada')");
        $this->db->query("INSERT INTO pagos (cita_id,cita_referencia_id,usuario_id,referencia_pago,proveedor,monto,estado,metodo_pago,idempotency_key,payload_proveedor,pagado_at) VALUES
            (1,1,2,'MAN-A','manual',20.00,'completado','efectivo','manual:a','{\"registro_manual\":true,\"actor_id\":1}',NOW()),
            (2,2,2,'MAN-B','manual',15.00,'reembolsado','transferencia','manual:b','{\"registro_manual\":true,\"actor_id\":1}',NOW()),
            (3,3,2,'SIM-C','mock',9.00,'completado','tarjeta_mock','sim:c',NULL,NOW())");
        $this->db->query("INSERT INTO caja_movimientos (actor_id,tipo,monto_centavos,concepto,metodo,pago_id,idempotencia,huella) VALUES
            (1,'ingreso',2000,'Cobro A','efectivo',1,'i1',REPEAT('a',64)),(1,'ingreso',1500,'Cobro B','transferencia',2,'i2',REPEAT('b',64)),(1,'egreso',1500,'Devolución B por error','transferencia',2,'i3',REPEAT('c',64))");
    }

    public function testDesdeCeroYReaplicarEsIdempotente(): void
    {
        [$c, $out] = $this->migrador('up');
        $this->assertSame(0, $c, $out);
        foreach (['cobros_devoluciones', 'cobros_eventos', 'caja_exportaciones_auditoria', 'comprobantes', 'comprobantes_secuencia'] as $t) {
            $this->assertSame(1, (int)$this->uno("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='$t'"), $t);
        }
        $this->assertSame('CI', $this->uno('SELECT serie FROM comprobantes_secuencia'));
        // reaplicar el SQL a mano (idempotente) no falla ni duplica
        $this->sql((string)file_get_contents(__DIR__ . '/../../database/migrations/024_cobros_verificacion.sql'));
        $this->sql((string)file_get_contents(__DIR__ . '/../../database/migrations/025_comprobantes.sql'));
        $this->assertSame(1, (int)$this->uno('SELECT COUNT(*) FROM comprobantes_secuencia'));
    }

    public function testRetrocargaSinInventarDatosYCompatibilidadConPagosPrevios(): void
    {
        $this->datosPrevios();
        [$c, $out] = $this->migrador('up');
        $this->assertSame(0, $c, $out);
        $p = $this->db->query('SELECT referencia_pago,estado_verificacion,registrado_por,verificado_por,verificado_en,monto_recibido_centavos,cambio_centavos FROM pagos ORDER BY id')->fetch_all(MYSQLI_ASSOC);
        $this->assertSame(['confirmado', '1', null, null, null, null], [$p[0]['estado_verificacion'], $p[0]['registrado_por'], $p[0]['verificado_por'], $p[0]['verificado_en'], $p[0]['monto_recibido_centavos'], $p[0]['cambio_centavos']], 'manual completado = confirmado, sin verificador ni efectivo inventados');
        $this->assertSame('confirmado', $p[1]['estado_verificacion'], 'manual reembolsado también');
        $this->assertNull($p[2]['estado_verificacion'], 'la pasarela simulada no se toca'); $this->assertNull($p[2]['registrado_por']);
        $d = $this->db->query('SELECT pago_id,monto_centavos,motivo,actor_id,origen FROM cobros_devoluciones')->fetch_all(MYSQLI_ASSOC);
        $this->assertCount(1, $d);
        $this->assertSame(['2', '1500', 'Devolución B por error', '1', 'migracion'], array_values($d[0]));
        $this->assertSame(3, (int)$this->uno('SELECT COUNT(*) FROM caja_movimientos'), 'la caja previa queda intacta');
        $this->assertSame(0, (int)$this->uno('SELECT COUNT(*) FROM caja_movimientos WHERE devolucion_id<>0'));
        // el esquema nuevo ya permite dos devoluciones parciales por pago y sigue prohibiendo dos ingresos
        $this->db->query("INSERT INTO caja_movimientos (actor_id,tipo,monto_centavos,concepto,metodo,pago_id,devolucion_id,idempotencia,huella) VALUES (1,'egreso',100,'p1','efectivo',1,1,'e1',REPEAT('d',64)),(1,'egreso',100,'p2','efectivo',1,2,'e2',REPEAT('e',64))");
        $this->assertSame(0, $this->db->errno);
        @$this->db->query("INSERT INTO caja_movimientos (actor_id,tipo,monto_centavos,concepto,metodo,pago_id,idempotencia,huella) VALUES (1,'ingreso',2000,'dup','efectivo',1,'dup1',REPEAT('f',64))");
        $this->assertSame(1062, $this->db->errno, 'un ingreso por pago');
        // los consumidores existentes de pagos (reportes/CRM) siguen leyendo estado/monto/proveedor sin cambios
        $this->assertSame('20.00', $this->uno("SELECT monto FROM pagos WHERE referencia_pago='MAN-A'"));
    }

    public function testRollbackSinDatosNuevosConservaLosPagosYSeRetoma(): void
    {
        $this->datosPrevios();
        $this->assertSame(0, $this->migrador('up')[0]);
        [$c, $out] = $this->migrador('down', '--hasta=024_cobros_verificacion');
        $this->assertSame(0, $c, $out);
        $this->assertSame(0, (int)$this->uno("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pagos' AND column_name='estado_verificacion'"));
        $this->assertSame(3, (int)$this->uno('SELECT COUNT(*) FROM pagos'));
        $this->assertSame(3, (int)$this->uno('SELECT COUNT(*) FROM caja_movimientos'));
        @$this->db->query("INSERT INTO caja_movimientos (actor_id,tipo,monto_centavos,concepto,metodo,pago_id,idempotencia,huella) VALUES (1,'ingreso',1,'dup','efectivo',1,'dup2',REPEAT('9',64))");
        $this->assertSame(1062, $this->db->errno, 'se restauró uq_caja_pago');
        $this->assertSame(0, $this->migrador('up')[0]);
        $this->assertSame(1, (int)$this->uno("SELECT COUNT(*) FROM cobros_devoluciones WHERE origen='migracion'"), 'la retrocarga se repite sin duplicar');
    }

    public function testRollbackSeNiegaConDatosDeNegocio(): void
    {
        $this->datosPrevios();
        $this->assertSame(0, $this->migrador('up')[0]);
        $this->db->query("INSERT INTO pagos (cita_id,cita_referencia_id,usuario_id,referencia_pago,proveedor,monto,estado,metodo_pago,idempotency_key,referencia_transferencia,referencia_unica,estado_verificacion,registrado_por) VALUES (3,3,2,'MAN-T','manual',5.00,'pendiente','transferencia','caja:t','REF123','REF123','pendiente_verificacion',1)");
        [$c, $out] = $this->migrador('down', '--hasta=024_cobros_verificacion');
        $this->assertNotSame(0, $c, $out);
        $this->assertSame(1, (int)$this->uno("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pagos' AND column_name='estado_verificacion'"), 'no se tocó nada');
        $this->assertSame('REF123', $this->uno("SELECT referencia_transferencia FROM pagos WHERE referencia_pago='MAN-T'"));
        // y la 025 con un comprobante emitido tampoco se revierte
        $this->db->query("INSERT INTO comprobantes_secuencia (serie,ultimo) VALUES ('ZZ',0) ON DUPLICATE KEY UPDATE ultimo=ultimo");
        $this->db->query("UPDATE comprobantes_secuencia SET ultimo=1 WHERE serie='CI'");
        $this->db->query("INSERT INTO comprobantes (serie,numero,codigo,tipo,cita_id,emitido_en,total_centavos,cobrado_centavos,devuelto_centavos,saldo_centavos,contenido_json,contenido_sha256) VALUES ('CI',1,'CI-000001','cobro',1,NOW(),1,1,0,0,'{}',REPEAT('0',64))");
        [$c2, $out2] = $this->migrador('down', '--hasta=025_comprobantes');
        $this->assertNotSame(0, $c2, $out2);
        $this->assertSame(1, (int)$this->uno('SELECT COUNT(*) FROM comprobantes'));
    }

    public function testComprobantesYAuditoriaSonSoloAnexado(): void
    {
        $this->assertSame(0, $this->migrador('up')[0]);
        $this->db->query("INSERT INTO comprobantes (serie,numero,codigo,tipo,cita_id,emitido_en,total_centavos,cobrado_centavos,devuelto_centavos,saldo_centavos,contenido_json,contenido_sha256) VALUES ('CI',1,'CI-000001','cobro',1,NOW(),1,1,0,0,'{}',REPEAT('0',64))");
        $this->assertSame(0, $this->db->errno, $this->db->error);
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            foreach (["UPDATE comprobantes SET total_centavos=2", 'DELETE FROM comprobantes', 'UPDATE comprobantes_secuencia SET ultimo=ultimo+2', 'UPDATE comprobantes_secuencia SET ultimo=0', 'DELETE FROM comprobantes_secuencia'] as $sql) {
                try { $this->db->query($sql); $this->fail("Debía rechazar: $sql"); } catch (\mysqli_sql_exception $e) { $this->assertSame(1644, $e->getCode(), $sql); }
            }
            $this->db->query("INSERT INTO comprobantes (serie,numero,codigo,tipo,cita_id,emitido_en,total_centavos,cobrado_centavos,devuelto_centavos,saldo_centavos,contenido_json,contenido_sha256) VALUES ('CI',2,'CI-000001','cobro',1,NOW(),1,1,0,0,'{}',REPEAT('0',64))");
            $this->fail('código duplicado');
        } catch (\mysqli_sql_exception $e) { $this->assertSame(1062, $e->getCode()); }
        finally { mysqli_report(MYSQLI_REPORT_OFF); }
        $this->db->query('UPDATE comprobantes_secuencia SET ultimo=ultimo+1');
        $this->assertSame(0, $this->db->errno, 'avanzar de uno en uno sí se permite');
    }
}

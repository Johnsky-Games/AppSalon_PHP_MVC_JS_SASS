<?php
namespace Tests\Integration;

/** Datos de prueba reales para caja y cobros (ronda 5). Solo se usa desde pruebas sobre bases `*_test`. */
final class R5CajaFixture
{
    private static int $n = 0;

    public static function usuario(\mysqli $db, string $rol = 'cliente'): int
    {
        $email = 'r5caja-' . getmypid() . '-' . (++self::$n) . '-' . bin2hex(random_bytes(4)) . '@example.test';
        $hash = password_hash('FixtureSeguro123!', PASSWORD_BCRYPT);
        $admin = $rol === 'propietario' ? 1 : 0;
        $st = $db->prepare("INSERT INTO usuarios (nombre,apellido,email,password,telefono,admin,confirmado) VALUES (?,?,?,?,'0991234567',?,1)");
        $nombre = ucfirst($rol); $apellido = 'R5 ' . self::$n;
        $st->bind_param('ssssi', $nombre, $apellido, $email, $hash, $admin); $st->execute(); $st->close();
        $id = (int)$db->insert_id;
        if ($rol !== 'cliente') {
            $st = $db->prepare('INSERT INTO usuario_accesos (usuario_id,rol,activo) VALUES (?,?,1)');
            $st->bind_param('is', $id, $rol); $st->execute(); $st->close();
        }
        return $id;
    }

    public static function correo(\mysqli $db, int $id): string
    {
        return (string)$db->query("SELECT email FROM usuarios WHERE id=$id")->fetch_row()[0];
    }

    public static function profesional(\mysqli $db, ?int $porcentaje = null): int
    {
        $nombre = 'Profesional R5 ' . bin2hex(random_bytes(3));
        $db->query("INSERT INTO profesionales (nombre,activo) VALUES ('$nombre',1)");
        $id = (int)$db->insert_id;
        if ($porcentaje !== null) {
            $db->query("INSERT INTO comisiones_config (profesional_id,porcentaje_pb) VALUES ($id,$porcentaje)");
        }
        return $id;
    }

    /** @param list<string> $precios precios históricos como texto decimal («15.25») */
    public static function cita(\mysqli $db, int $cliente, ?int $profesional, array $precios = ['20.00'], string $estado = 'confirmada', string $fecha = '2030-01-02'): int
    {
        $prof = $profesional ?? 'NULL';
        $db->query("INSERT INTO citas (fecha,hora,usuarioId,profesionalId,estado) VALUES ('$fecha','10:00:00',$cliente,$prof,'$estado')");
        $cita = (int)$db->insert_id;
        $db->query("INSERT INTO servicios (nombre,precio,duracion_minutos) VALUES ('Catálogo actual R5',999.00,30)");
        $servicio = (int)$db->insert_id;
        foreach ($precios as $i => $precio) {
            $db->query("INSERT INTO citasservicios (citaId,servicioId,nombre_servicio,precio_servicio,duracion_minutos) VALUES ($cita,$servicio,'Servicio histórico " . ($i + 1) . "',$precio,30)");
        }
        return $cita;
    }

    public static function clave(): string { return bin2hex(random_bytes(16)); }

    public static function escalar(\mysqli $db, string $sql): mixed
    {
        $r = $db->query($sql)->fetch_row();
        return $r ? $r[0] : null;
    }
}

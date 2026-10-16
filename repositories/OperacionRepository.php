<?php
namespace Repositories;
use Model\ActiveRecord;
use mysqli;
/** Consultas parametrizadas de los módulos de caja e inventario. */
class OperacionRepository
{
    public mysqli $db;
    public function __construct(?mysqli $db = null) { $this->db = $db ?? ActiveRecord::getDB(); }
    public function consulta(string $sql, string $tipos = '', array $valores = []): array
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) { throw new PersistenceException('No se pudo preparar la operación.', $this->db->errno); }
        try {
            if ($tipos !== '') { $stmt->bind_param($tipos, ...$valores); }
            if (!$stmt->execute()) { throw new PersistenceException('No se pudo ejecutar la operación.', $stmt->errno); }
            $resultado = $stmt->get_result();
            return $resultado ? $resultado->fetch_all(MYSQLI_ASSOC) : [];
        } finally { $stmt->close(); }
    }
    /**
     * Solo closures de base de datos: ninguna llamada externa ni despacho de correo.
     * InnoDB puede elegir esta operación como víctima; revierte completamente y
     * reintenta solo deadlocks (1213) y esperas agotadas (1205), como máximo 3 veces.
     */
    public function transaccion(callable $operacion): mixed
    {
        for ($intento = 1; $intento <= 3; $intento++) {
            try {
                if (!$this->db->begin_transaction()) {
                    throw new PersistenceException('No se pudo iniciar la operación.', $this->db->errno);
                }
                $resultado = $operacion();
                if (!$this->db->commit()) {
                    throw new PersistenceException('No se pudo confirmar la operación.', $this->db->errno);
                }
                return $resultado;
            } catch (\Throwable $e) {
                try { $this->db->rollback(); }
                catch (\Throwable $rollbackError) { throw $e; }
                $falloSql = $e instanceof PersistenceException || $e instanceof \mysqli_sql_exception;
                if (!$falloSql || !in_array((int)$e->getCode(), [1213, 1205], true) || $intento === 3) {
                    throw $e;
                }
                // Jitter acotado (10–60 ms): evita que ambas víctimas compitan otra vez juntas.
                usleep(random_int(10000, 30000) * $intento);
            }
        }
        throw new PersistenceException('No se pudo completar la operación.');
    }
    public static function ahora(): string { return (new \DateTimeImmutable('now',new \DateTimeZone('America/Guayaquil')))->format('Y-m-d H:i:s'); }
    public function caja(string $inicio, string $fin, int $pagina = 1): array
    {
        $resumen = $this->consulta("SELECT COALESCE(SUM(IF(tipo='ingreso',monto_centavos,0)),0) ingresos, COALESCE(SUM(IF(tipo='egreso',monto_centavos,0)),0) egresos, COUNT(*) total FROM caja_movimientos WHERE registrado_en >= ? AND registrado_en < DATE_ADD(?, INTERVAL 1 DAY)", 'ss', [$inicio,$fin])[0];
        $saldo = $this->consulta("SELECT COALESCE(SUM(IF(tipo='ingreso',monto_centavos,-monto_centavos)),0) saldo FROM caja_movimientos")[0]['saldo'];
        $filas = $this->consulta('SELECT c.*, p.referencia_pago FROM caja_movimientos c LEFT JOIN pagos p ON p.id=c.pago_id WHERE c.registrado_en >= ? AND c.registrado_en < DATE_ADD(?, INTERVAL 1 DAY) ORDER BY c.id DESC LIMIT 30 OFFSET ?', 'ssi', [$inicio,$fin, max(0,$pagina-1)*30]);
        return compact('resumen','saldo','filas');
    }
    public function productos(int $pagina = 1): array
    {
        return $this->consulta('SELECT * FROM inventario_productos ORDER BY activo DESC, nombre LIMIT 30 OFFSET ?', 'i', [max(0,$pagina-1)*30]);
    }
    public function movimientosInventario(): array
    {
        return $this->consulta('SELECT m.*, p.nombre FROM inventario_movimientos m JOIN inventario_productos p ON p.id=m.producto_id ORDER BY m.id DESC LIMIT 30');
    }
}

<?php

namespace Classes;

use WeakMap;

/**
 * Transacciones anidables sobre una misma conexión mysqli.
 * El primer nivel abre una transacción real; los niveles internos usan SAVEPOINT, de modo que un
 * repositorio que ya protege su propia escritura puede ejecutarse dentro de una operación mayor
 * (p. ej. reserva de idempotencia + alta de identidad + invitación) sin confirmar parcialmente.
 */
final class Transaccion
{
    /** @var WeakMap<\mysqli,int>|null */
    private static ?WeakMap $niveles = null;

    /**
     * @template T
     * @param callable(\mysqli):T $trabajo
     * @return T
     */
    public static function ejecutar(\mysqli $db, callable $trabajo): mixed
    {
        self::$niveles ??= new WeakMap();
        $nivel = self::$niveles[$db] ?? 0;
        if ($nivel === 0) {
            if (!$db->begin_transaction()) {
                throw new \RuntimeException('No se pudo iniciar la transacción.');
            }
        } else {
            $db->query("SAVEPOINT sp_{$nivel}");
        }
        self::$niveles[$db] = $nivel + 1;
        try {
            $resultado = $trabajo($db);
            if ($nivel === 0) {
                if (!$db->commit()) {
                    throw new \RuntimeException('No se pudo confirmar la transacción.');
                }
            } else {
                $db->query("RELEASE SAVEPOINT sp_{$nivel}");
            }
            return $resultado;
        } catch (\Throwable $e) {
            try {
                if ($nivel === 0) {
                    $db->rollback();
                } else {
                    $db->query("ROLLBACK TO SAVEPOINT sp_{$nivel}");
                }
            } catch (\Throwable) {
                // La conexión puede haberse perdido; el motor revierte la transacción abierta.
            }
            throw $e;
        } finally {
            self::$niveles[$db] = $nivel;
        }
    }

    public static function nivel(\mysqli $db): int
    {
        return self::$niveles[$db] ?? 0;
    }
}

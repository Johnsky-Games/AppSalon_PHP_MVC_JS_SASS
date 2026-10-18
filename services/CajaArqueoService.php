<?php
namespace Services;

use Repositories\OperacionRepository;

/** Vincula el libro general con el arqueo sin volver a registrar dinero.
 * El llamador conserva la transacción y el bloqueo hasta confirmar el movimiento.
 * Los flujos anteriores al PDV siguen admitiendo movimientos sin sesión abierta.
 */
final class CajaArqueoService
{
    public function __construct(private OperacionRepository $repo) {}

    public function preparar(int $actor, string $tipo, int $monto, string $metodo): ?int
    {
        $sesion = $this->repo->consulta("SELECT id FROM erp_caja_sesiones WHERE actor_id=? AND estado='abierta' FOR UPDATE", 'i', [$actor])[0] ?? null;
        if (!$sesion) { return null; }
        $id = (int)$sesion['id'];
        if ($tipo === 'egreso' && $metodo === 'efectivo' && $monto > $this->esperado($id)) {
            throw new \InvalidArgumentException('No hay suficiente efectivo esperado en esta caja.');
        }
        return $id;
    }

    public function vincular(int $movimiento, ?int $sesion): void
    {
        if ($sesion !== null) {
            $this->repo->consulta('INSERT INTO erp_caja_movimientos(caja_id,sesion_id) VALUES(?,?)', 'ii', [$movimiento, $sesion]);
        }
    }

    public function esperado(int $sesion): int
    {
        $s = $this->repo->consulta('SELECT fondo_centavos FROM erp_caja_sesiones WHERE id=?', 'i', [$sesion])[0] ?? null;
        if (!$s) { throw new \InvalidArgumentException('Caja no encontrada.'); }
        // Lectura actual con bloqueo: tras esperar el candado de sesión, una
        // instantánea REPEATABLE READ previa no debe omitir el retiro que ganó.
        $movimientos = $this->repo->consulta("SELECT c.tipo,c.monto_centavos FROM erp_caja_movimientos e JOIN caja_movimientos c ON c.id=e.caja_id WHERE e.sesion_id=? AND c.metodo='efectivo' FOR SHARE", 'i', [$sesion]);
        $saldo = (int)$s['fondo_centavos'];
        foreach ($movimientos as $m) {
            $saldo += ($m['tipo'] === 'ingreso' ? 1 : -1) * (int)$m['monto_centavos'];
        }
        return $saldo;
    }
}

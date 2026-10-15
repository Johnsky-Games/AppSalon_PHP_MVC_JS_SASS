<?php
namespace Repositories;

/** Persistencia de `comprobantes` (solo anexado) y de su contador correlativo (`comprobantes_secuencia`). */
class ComprobanteRepository
{
    public function __construct(private OperacionRepository $repo) {}

    /**
     * Siguiente número de la serie. Bloquea la fila del contador hasta el fin de la transacción: dos emisiones simultáneas se
     * serializan; si la transacción se revierte, el avance se revierte con ella (sin huecos). El disparador de la 025 impide
     * saltar o retroceder números.
     */
    public function siguienteNumero(string $serie): int
    {
        $fila = $this->repo->consulta('SELECT ultimo FROM comprobantes_secuencia WHERE serie=? FOR UPDATE', 's', [$serie])[0] ?? null;
        if (!$fila) { throw new PersistenceException('La serie de comprobantes no está configurada.'); }
        $n = (int)$fila['ultimo'] + 1;
        $this->repo->consulta('UPDATE comprobantes_secuencia SET ultimo=? WHERE serie=?', 'is', [$n, $serie]);
        return $n;
    }

    /** @param array<string,mixed> $c */
    public function insertar(array $c): int
    {
        $this->repo->consulta(
            'INSERT INTO comprobantes (serie,numero,codigo,tipo,cita_id,pago_id,devolucion_id,emitido_por,emitido_en,total_centavos,cobrado_centavos,devuelto_centavos,saldo_centavos,contenido_json,contenido_sha256) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            'sissiiiisiiiiss',
            [$c['serie'], $c['numero'], $c['codigo'], $c['tipo'], $c['cita_id'], $c['pago_id'], $c['devolucion_id'], $c['emitido_por'], $c['emitido_en'], $c['total'], $c['cobrado'], $c['devuelto'], $c['saldo'], $c['json'], $c['sha256']]
        );
        return (int)$this->repo->db->insert_id;
    }

    /** @return list<array<string,mixed>> */
    public function deCita(int $citaId): array
    {
        return $this->repo->consulta('SELECT id,codigo,tipo,emitido_en,total_centavos,cobrado_centavos,devuelto_centavos,saldo_centavos,pago_id,devolucion_id FROM comprobantes WHERE cita_id=? ORDER BY id DESC', 'i', [$citaId]);
    }

    /** @return array<string,mixed>|null */
    public function porId(int $id): ?array
    {
        return $this->repo->consulta('SELECT * FROM comprobantes WHERE id=?', 'i', [$id])[0] ?? null;
    }
}

<?php
namespace Repositories;

use Services\CobroCalculo;

/**
 * Universo de citas de la vista operativa de caja (`GET /caja`) y de su exportación: UNA sola definición de filtros y de
 * agregados, usada por la lista paginada, los indicadores y el archivo. Los importes se calculan en SQL con la MISMA regla que
 * `CobroCitaService::resumen` (cobrado = pagos completados o reembolsados; devuelto por pago = max(filas de devolución, total si
 * el pago está reembolsado) acotado al monto; pendiente = transferencias por verificar).
 */
class CajaRepository
{
    public const POR_PAGINA = 25;
    public const ESTADOS = CobroCalculo::ESTADOS;
    public const METODOS = CobroCalculo::METODOS;
    private const COBRABLES = "('confirmada','en_servicio','completada')";

    public function __construct(private OperacionRepository $repo) {}

    /**
     * Normaliza los filtros de la URL. Devuelve [filtros, errores]; un valor inválido se descarta y se informa (nunca se refleja).
     * @return array{0:array<string,mixed>,1:list<string>}
     */
    public static function filtros(array $entrada, ?\DateTimeImmutable $hoy = null): array
    {
        $hoy ??= new \DateTimeImmutable('now', new \DateTimeZone('America/Guayaquil'));
        $errores = [];
        $f = ['desde' => $hoy->modify('-30 days')->format('Y-m-d'), 'hasta' => $hoy->format('Y-m-d'), 'estado' => '', 'metodo' => '', 'profesional' => 0, 'q' => '', 'pagina' => 1];
        foreach (['desde', 'hasta'] as $k) {
            if (!array_key_exists($k, $entrada) || $entrada[$k] === '') { continue; }
            $d = is_string($entrada[$k]) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $entrada[$k]) : false;
            if ($d && $d->format('Y-m-d') === $entrada[$k]) { $f[$k] = $entrada[$k]; } else { $errores[] = 'La fecha «' . ($k === 'desde' ? 'desde' : 'hasta') . '» no es válida (use AAAA-MM-DD).'; }
        }
        if ($f['desde'] > $f['hasta']) { $errores[] = 'La fecha inicial no puede ser posterior a la final.'; $f['desde'] = $f['hasta']; }
        if ((new \DateTimeImmutable($f['desde']))->diff(new \DateTimeImmutable($f['hasta']))->days > 366) {
            $errores[] = 'El período no puede superar 366 días.'; $f['desde'] = (new \DateTimeImmutable($f['hasta']))->modify('-366 days')->format('Y-m-d');
        }
        if (isset($entrada['estado']) && $entrada['estado'] !== '') {
            if (is_string($entrada['estado']) && in_array($entrada['estado'], self::ESTADOS, true)) { $f['estado'] = $entrada['estado']; } else { $errores[] = 'El estado de pago indicado no existe.'; }
        }
        if (isset($entrada['metodo']) && $entrada['metodo'] !== '') {
            if (is_string($entrada['metodo']) && in_array($entrada['metodo'], self::METODOS, true)) { $f['metodo'] = $entrada['metodo']; } else { $errores[] = 'El método de pago indicado no existe.'; }
        }
        if (isset($entrada['profesional']) && $entrada['profesional'] !== '') {
            if (is_string($entrada['profesional']) && preg_match('/^[1-9]\d{0,9}$/D', $entrada['profesional'])) { $f['profesional'] = (int)$entrada['profesional']; } else { $errores[] = 'El profesional indicado no es válido.'; }
        }
        if (isset($entrada['q']) && $entrada['q'] !== '') {
            $q = is_string($entrada['q']) ? trim($entrada['q']) : '';
            if ($q !== '' && mb_strlen($q) <= 80 && mb_check_encoding($q, 'UTF-8') && !preg_match('/[\x00-\x1f\x7f]/', $q)) { $f['q'] = $q; } else { $errores[] = 'La búsqueda de cliente no es válida (hasta 80 caracteres, sin símbolos de control).'; }
        }
        if (isset($entrada['pagina']) && $entrada['pagina'] !== '') {
            $f['pagina'] = is_string($entrada['pagina']) && preg_match('/^[1-9]\d{0,4}$/D', $entrada['pagina']) ? (int)$entrada['pagina'] : 1;
        }
        return [$f, $errores];
    }

    /** SELECT interno con los agregados por cita y el estado derivado. @return array{0:string,1:string,2:array} sql, tipos, parámetros (filtros de fecha/profesional/cliente) */
    private function base(array $f): array
    {
        $dinero = 'CAST(ROUND(p.monto*100) AS SIGNED)';
        $sql = "SELECT c.id,c.fecha,c.hora,c.hora_inicio,c.estado cita_estado,c.usuarioId cliente_id,c.profesionalId profesional_id,
            TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,''))) cliente,COALESCE(pr.nombre,'') profesional,
            COALESCE(t.total,0) total,COALESCE(g.cobrado,0) cobrado,COALESCE(g.devuelto,0) devuelto,COALESCE(g.pendiente,0) pendiente,COALESCE(g.metodos,'') metodos,
            (c.estado IN " . self::COBRABLES . ") cobrable
          FROM citas c
          LEFT JOIN usuarios u ON u.id=c.usuarioId
          LEFT JOIN profesionales pr ON pr.id=c.profesionalId
          LEFT JOIN (SELECT cs.citaId,SUM(CAST(ROUND(COALESCE(cs.precio_servicio,s.precio)*100) AS SIGNED)) total FROM citasservicios cs LEFT JOIN servicios s ON s.id=cs.servicioId GROUP BY cs.citaId) t ON t.citaId=c.id
          LEFT JOIN (SELECT x.cita,SUM(x.cobrado) cobrado,SUM(x.devuelto) devuelto,SUM(x.pendiente) pendiente,GROUP_CONCAT(DISTINCT x.metodo ORDER BY x.metodo SEPARATOR ',') metodos FROM (
              SELECT COALESCE(p.cita_id,p.cita_referencia_id) cita,
                IF(p.estado IN ('completado','reembolsado'),{$dinero},0) cobrado,
                IF(p.estado IN ('completado','reembolsado'),LEAST({$dinero},GREATEST(COALESCE(d.dev,0),IF(p.estado='reembolsado',{$dinero},0))),0) devuelto,
                IF(p.estado='pendiente' AND p.estado_verificacion='pendiente_verificacion',{$dinero},0) pendiente,
                IF(p.estado IN ('completado','reembolsado') OR (p.estado='pendiente' AND p.estado_verificacion='pendiente_verificacion'),COALESCE(p.metodo_pago,''),NULL) metodo
              FROM pagos p LEFT JOIN (SELECT pago_id,SUM(monto_centavos) dev FROM cobros_devoluciones GROUP BY pago_id) d ON d.pago_id=p.id
            ) x GROUP BY x.cita) g ON g.cita=c.id
          WHERE c.fecha>=? AND c.fecha<=?";
        $tipos = 'ss'; $params = [$f['desde'], $f['hasta']];
        if ($f['profesional'] > 0) { $sql .= ' AND c.profesionalId=?'; $tipos .= 'i'; $params[] = $f['profesional']; }
        if ($f['q'] !== '') {
            $like = '%' . addcslashes($f['q'], '\\%_') . '%';
            $sql .= " AND (CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,'')) LIKE ? OR u.email LIKE ? OR u.telefono LIKE ?)";
            $tipos .= 'sss'; array_push($params, $like, $like, $like);
        }
        if ($f['metodo'] !== '') {
            $sql .= " AND FIND_IN_SET(?,COALESCE(g.metodos,''))>0";
            $tipos .= 's'; $params[] = $f['metodo'];
        }
        return [$sql, $tipos, $params];
    }

    /** Envuelve la base con el estado derivado, el saldo por cobrar y el anticipo sin resolver; aplica el filtro de estado y la pertenencia al universo. */
    private function universo(array $f): array
    {
        [$base, $tipos, $params] = $this->base($f);
        $sql = "SELECT b.*,
            (CASE WHEN b.total>0 AND b.cobrado-b.devuelto>=b.total THEN 'pagado' WHEN b.pendiente>0 THEN 'por_verificar' WHEN b.cobrado>0 AND b.cobrado-b.devuelto<=0 THEN 'reembolsado' WHEN b.cobrado-b.devuelto>0 THEN 'parcial' ELSE 'pendiente' END) estado_pago,
            IF(b.cobrable=1,GREATEST(0,b.total-b.cobrado+b.devuelto),0) por_cobrar,
            IF(b.cobrable=1,0,GREATEST(0,b.cobrado-b.devuelto)) anticipo_sin_resolver
          FROM ({$base}) b
          WHERE (b.cobrable=1 OR b.cobrado-b.devuelto>0 OR b.pendiente>0)";
        $envuelto = "SELECT * FROM ({$sql}) q WHERE 1=1";
        if ($f['estado'] !== '') { $envuelto .= ' AND q.estado_pago=?'; $tipos .= 's'; $params[] = $f['estado']; }
        return [$envuelto, $tipos, $params];
    }

    /** @return list<array<string,mixed>> filas de la página solicitada (cada importe en centavos enteros) */
    public function pagina(array $f): array
    {
        [$sql, $tipos, $params] = $this->universo($f);
        $sql .= ' ORDER BY q.fecha DESC,q.hora DESC,q.id DESC LIMIT ' . self::POR_PAGINA . ' OFFSET ' . (max(1, (int)$f['pagina']) - 1) * self::POR_PAGINA;
        return array_map([$this, 'fila'], $this->repo->consulta($sql, $tipos, $params));
    }

    /** Todas las filas del universo filtrado, por bloques (para exportar). @return \Generator<int,array<string,mixed>> */
    public function todas(array $f, int $bloque = 500): \Generator
    {
        [$sql, $tipos, $params] = $this->universo($f);
        $sql .= ' ORDER BY q.fecha DESC,q.hora DESC,q.id DESC LIMIT ' . $bloque . ' OFFSET ';
        for ($desde = 0; ; $desde += $bloque) {
            $filas = $this->repo->consulta($sql . $desde, $tipos, $params);
            foreach ($filas as $fila) { yield $this->fila($fila); }
            if (count($filas) < $bloque) { return; }
        }
    }

    /** Indicadores del universo COMPLETO filtrado (no solo la página). @return array<string,int> */
    public function totales(array $f): array
    {
        [$sql, $tipos, $params] = $this->universo($f);
        $r = $this->repo->consulta("SELECT COUNT(*) citas,COALESCE(SUM(z.total),0) total,COALESCE(SUM(z.cobrado),0) cobrado,COALESCE(SUM(z.devuelto),0) devuelto,COALESCE(SUM(z.pendiente),0) pendiente,COALESCE(SUM(z.por_cobrar),0) por_cobrar,COALESCE(SUM(z.anticipo_sin_resolver),0) anticipo_sin_resolver FROM ({$sql}) z", $tipos, $params)[0];
        return array_map('intval', $r);
    }

    /** @return list<array{id:int,nombre:string}> */
    public function profesionales(): array
    {
        return array_map(static fn(array $p): array => ['id' => (int)$p['id'], 'nombre' => (string)$p['nombre']], $this->repo->consulta('SELECT id,nombre FROM profesionales ORDER BY nombre'));
    }

    /** @param array<string,mixed> $f */
    private function fila(array $f): array
    {
        foreach (['id', 'cliente_id', 'profesional_id', 'total', 'cobrado', 'devuelto', 'pendiente', 'por_cobrar', 'anticipo_sin_resolver'] as $k) { $f[$k] = $f[$k] === null ? null : (int)$f[$k]; }
        $f['cobrable'] = (int)$f['cobrable'] === 1;
        $f['hora'] = substr((string)($f['hora_inicio'] ?: $f['hora']), 0, 5);
        $f['metodos'] = $f['metodos'] === '' ? [] : explode(',', (string)$f['metodos']);
        unset($f['hora_inicio']);
        return $f;
    }
}

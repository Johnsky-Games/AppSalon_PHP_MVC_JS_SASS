<?php
namespace Repositories;

use Model\ActiveRecord;
use mysqli;

/**
 * Lecturas del CRM de clientes. Solo ejecuta SELECT preparados: las entradas llegan validadas por
 * CrmService (fechas en formato AAAA-MM-DD, enteros, listas blancas). Los importes viajan en centavos enteros.
 *
 * Definiciones de dominio que este repositorio materializa en SQL (documentadas en docs/CRM_CLIENTES.md):
 *  - Cliente: fila en `clientes`, o cuenta legada sin fila cuyo rol efectivo sea cliente (misma regla que
 *    AuthorizationService::contexto()), o cuenta con alguna cita. Una cuenta solo laboral sin ninguna de las tres no cuenta.
 *  - Visita: cita en estado `completada` con fecha <= hoy (hoy se calcula en America/Guayaquil y llega por parámetro).
 *  - Cobro real: pago `completado`/`reembolsado` cuyo proveedor no es `mock`, en su fecha de cobro
 *    (COALESCE(pagado_at, created_at)); devolución: pago `reembolsado` en su fecha de reembolso
 *    (COALESCE(reembolsado_at, pagado_at, created_at)). Mismo criterio que ReporteService.
 */
final class CrmRepository
{
    /** Condición SQL de pertenencia al universo de clientes (alias u, a, cl definidos por el FROM). */
    public const CLIENTE_SQL = "(cl.usuario_id IS NOT NULL OR COALESCE(a.rol,IF(u.admin=1,'propietario','cliente'))='cliente' OR EXISTS(SELECT 1 FROM citas cx WHERE cx.usuarioId=u.id))";

    /** Ordenamientos permitidos: clave pública => expresión SQL. */
    private const ORDENES = [
        'nombre' => 'u.apellido %d, u.nombre %d',
        'alta' => 'cl.creado_en %d',
        'ultima' => 'v.ultima %d',
        'visitas' => 'COALESCE(vr.n,0) %d',
        'visitas_total' => 'COALESCE(v.visitas_total,0) %d',
        'gasto' => '(COALESCE(g.cobros_real,0)-COALESCE(g.dev_real,0)) %d',
    ];

    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $conexion = $db ?? ActiveRecord::getDB();
        if (!$conexion instanceof mysqli) { throw new PersistenceException('Sin conexión a la base de datos.'); }
        $this->db = $conexion;
    }

    // ---------------------------------------------------------------- utilidades

    /** @return array<int,array<string,mixed>> */
    private function filas(string $sql, string $types = '', array $params = []): array
    {
        try {
            if (strlen($types) !== count($params)) { throw new PersistenceException('Parámetros de consulta inconsistentes.'); }
            $stmt = $this->db->prepare($sql);
            if (!$stmt) { throw new PersistenceException('No se pudo preparar la consulta de clientes.'); }
            if ($types !== '') { $stmt->bind_param($types, ...$params); }
            if (!$stmt->execute()) { $errno = (int)$stmt->errno; $stmt->close(); throw new PersistenceException('No se pudo ejecutar la consulta de clientes (errno ' . $errno . ').'); }
            $res = $stmt->get_result();
            $filas = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
            $stmt->close();
            return $filas;
        } catch (PersistenceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new PersistenceException('No se pudo completar la consulta de clientes.', 0, $e);
        }
    }

    /** @return array{0:string,1:string,2:array} */
    private static function f(string $sql, string $types = '', array $params = []): array { return [$sql, $types, $params]; }

    /** Concatena fragmentos conservando el orden textual de los marcadores. @return array{0:string,1:string,2:array} */
    private static function unir(array ...$fragmentos): array
    {
        $sql = ''; $types = ''; $params = [];
        foreach ($fragmentos as [$s, $t, $p]) { $sql .= $s; $types .= $t; array_push($params, ...$p); }
        return [$sql, $types, $params];
    }

    private static function sigDia(string $fecha): string
    {
        return (new \DateTimeImmutable($fecha))->modify('+1 day')->format('Y-m-d');
    }

    // ---------------------------------------------------------------- catálogos

    /** @return array{servicios:array,profesionales:array} */
    public function catalogos(): array
    {
        return [
            'servicios' => $this->filas('SELECT id,nombre FROM servicios ORDER BY nombre'),
            'profesionales' => $this->filas('SELECT id,nombre,activo FROM profesionales ORDER BY activo DESC,nombre'),
        ];
    }

    // ---------------------------------------------------------------- fragmentos compartidos

    /**
     * Subconsulta de eventos de pago (cobros y devoluciones) con su propia fecha de evento.
     * Columnas: usuario, tipo ('C' cobro | 'R' devolución), mock (1 pasarela simulada), c (centavos), fecha_evento.
     * Un cobro posterior reembolsado cuenta como cobro en su fecha y como devolución en la fecha del reembolso.
     */
    private function eventosPagos(string $desde, string $hasta, ?int $usuario = null): array
    {
        $ini = $desde . ' 00:00:00';
        $fin = self::sigDia($hasta) . ' 00:00:00';
        $mock = "IF(COALESCE(NULLIF(TRIM(pg.proveedor),''),'mock')='mock',1,0)";
        $cobro = 'COALESCE(pg.pagado_at,pg.created_at)';
        $devol = 'COALESCE(pg.reembolsado_at,pg.pagado_at,pg.created_at)';
        $u = $usuario !== null ? ' AND COALESCE(pg.usuario_id,ci.usuarioId)=?' : '';
        $tu = $usuario !== null ? 'i' : '';
        $pu = $usuario !== null ? [$usuario] : [];
        $join = ' FROM pagos pg LEFT JOIN citas ci ON ci.id=COALESCE(pg.cita_id,pg.cita_referencia_id)';
        return self::unir(
            self::f("SELECT COALESCE(pg.usuario_id,ci.usuarioId) usuario,'C' tipo,{$mock} mock,CAST(ROUND(pg.monto*100) AS SIGNED) c,{$cobro} fecha_evento{$join}"
                . " WHERE pg.estado IN ('completado','reembolsado') AND {$cobro}>=? AND {$cobro}<?{$u}", 'ss' . $tu, array_merge([$ini, $fin], $pu)),
            self::f(" UNION ALL SELECT COALESCE(pg.usuario_id,ci.usuarioId),'R',{$mock},CAST(ROUND(pg.monto*100) AS SIGNED),{$devol}{$join}"
                . " WHERE pg.estado='reembolsado' AND {$devol}>=? AND {$devol}<?{$u}", 'ss' . $tu, array_merge([$ini, $fin], $pu))
        );
    }

    /** Subconsulta por usuario: cobros_real, dev_real, cobros_sim, dev_sim, n_cobros_real (centavos / conteo). */
    private function gastoPorUsuario(string $desde, string $hasta, ?int $usuario = null): array
    {
        return self::unir(
            self::f("SELECT t.usuario,"
                . "SUM(IF(t.tipo='C' AND t.mock=0,t.c,0)) cobros_real,SUM(IF(t.tipo='R' AND t.mock=0,t.c,0)) dev_real,"
                . "SUM(IF(t.tipo='C' AND t.mock=1,t.c,0)) cobros_sim,SUM(IF(t.tipo='R' AND t.mock=1,t.c,0)) dev_sim,"
                . "SUM(IF(t.tipo='C' AND t.mock=0,1,0)) n_cobros_real FROM ("),
            $this->eventosPagos($desde, $hasta, $usuario),
            self::f(") t WHERE t.usuario IS NOT NULL GROUP BY t.usuario")
        );
    }

    /**
     * Expresión CASE de segmento mutuamente excluyente. Orden de evaluación: vip, nuevo, activo, en_riesgo, inactivo.
     * @param array $c contexto: hoy, dias_activo, dias_nuevo, vip_ids
     */
    private function segmentoSql(array $c): array
    {
        $sql = 'CASE'; $t = ''; $p = [];
        if (!empty($c['vip_ids'])) { $sql .= " WHEN FIND_IN_SET(u.id,?) THEN 'vip'"; $t .= 's'; $p[] = implode(',', array_map('intval', $c['vip_ids'])); }
        $sql .= " WHEN cl.creado_en IS NOT NULL AND DATEDIFF(?,DATE(cl.creado_en))<=? THEN 'nuevo'";
        $t .= 'si'; array_push($p, $c['hoy'], (int)$c['dias_nuevo']);
        $sql .= " WHEN v.ultima IS NOT NULL AND DATEDIFF(?,v.ultima)<=? THEN 'activo'";
        $t .= 'si'; array_push($p, $c['hoy'], (int)$c['dias_activo']);
        $sql .= " WHEN v.ultima IS NOT NULL AND DATEDIFF(?,v.ultima)<=? THEN 'en_riesgo'";
        $t .= 'si'; array_push($p, $c['hoy'], 2 * (int)$c['dias_activo']);
        $sql .= " ELSE 'inactivo' END";
        return self::f($sql, $t, $p);
    }

    /** FROM con las uniones necesarias. $need: v (visitas históricas), vr (visitas del rango/filtro), g (gasto del rango). */
    private function desde(array $c, array $f, array $need): array
    {
        $partes = [self::f(' FROM usuarios u LEFT JOIN usuario_accesos a ON a.usuario_id=u.id LEFT JOIN clientes cl ON cl.usuario_id=u.id LEFT JOIN perfiles_usuario pf ON pf.usuarioId=u.id')];
        if (!empty($need['v'])) {
            $partes[] = self::f(" LEFT JOIN (SELECT usuarioId,COUNT(*) visitas_total,MIN(fecha) primera,MAX(fecha) ultima FROM citas WHERE estado='completada' AND fecha<=? GROUP BY usuarioId) v ON v.usuarioId=u.id", 's', [$c['hoy']]);
        }
        if (!empty($need['vr'])) {
            // Sin unión con servicios ci.id es único: COUNT(*) evita el DISTINCT (que obliga a ordenar).
            $cuenta = !empty($f['servicio_id']) ? 'COUNT(DISTINCT ci.id)' : 'COUNT(*)';
            $sql = " LEFT JOIN (SELECT ci.usuarioId,{$cuenta} n FROM citas ci"; $t = ''; $p = [];
            if (!empty($f['servicio_id'])) { $sql .= ' INNER JOIN citasservicios cs ON cs.citaId=ci.id AND cs.servicioId=?'; $t .= 'i'; $p[] = (int)$f['servicio_id']; }
            $sql .= " WHERE ci.estado='completada' AND ci.fecha BETWEEN ? AND ?"; $t .= 'ss'; array_push($p, $c['desde'], $c['hasta']);
            if (!empty($f['profesional_id'])) { $sql .= ' AND ci.profesionalId=?'; $t .= 'i'; $p[] = (int)$f['profesional_id']; }
            $sql .= ' GROUP BY ci.usuarioId) vr ON vr.usuarioId=u.id';
            $partes[] = self::f($sql, $t, $p);
        }
        if (!empty($need['g'])) {
            $partes[] = self::f(' LEFT JOIN (');
            $partes[] = $this->gastoPorUsuario($c['desde'], $c['hasta']);
            $partes[] = self::f(') g ON g.usuario=u.id');
        }
        return self::unir(...$partes);
    }

    /** WHERE con el universo de clientes y los filtros del listado. */
    private function donde(array $c, array $f): array
    {
        $sql = ' WHERE ' . self::CLIENTE_SQL; $t = ''; $p = [];
        if (!empty($f['id'])) { $sql .= ' AND u.id=?'; $t .= 'i'; $p[] = (int)$f['id']; }
        $q = (string)($f['q'] ?? '');
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $cond = "u.nombre LIKE ? OR u.apellido LIKE ? OR CONCAT(u.nombre,' ',u.apellido) LIKE ? OR u.email LIKE ? OR u.telefono LIKE ?";
            $t .= 'sssss'; array_push($p, $like, $like, $like, $like, $like);
            $digitos = preg_replace('/\D+/', '', $q) ?? '';
            if (strlen($digitos) >= 3 && $digitos !== $q) { $cond .= ' OR u.telefono LIKE ?'; $t .= 's'; $p[] = '%' . $digitos . '%'; }
            $sql .= " AND ({$cond})";
        }
        if (!empty($f['alta_desde'])) { $sql .= ' AND cl.creado_en>=?'; $t .= 's'; $p[] = $f['alta_desde'] . ' 00:00:00'; }
        if (!empty($f['alta_hasta'])) { $sql .= ' AND cl.creado_en<?'; $t .= 's'; $p[] = self::sigDia($f['alta_hasta']) . ' 00:00:00'; }
        if (!empty($f['profesional_id']) || !empty($f['servicio_id'])) { $sql .= ' AND COALESCE(vr.n,0)>0'; }
        $membresia = (string)($f['membresia'] ?? '');
        if ($membresia === 'sin_registro') { $sql .= ' AND cl.usuario_id IS NULL'; }
        elseif ($membresia === 'activo' || $membresia === 'suspendido') { $sql .= ' AND cl.estado=?'; $t .= 's'; $p[] = $membresia; }
        $acepta = 'COALESCE(pf.marketing_email,0)=1 AND pf.consentimiento_marketing_at IS NOT NULL';
        if (($f['consentimiento'] ?? '') === 'si') { $sql .= " AND ({$acepta})"; }
        elseif (($f['consentimiento'] ?? '') === 'no') { $sql .= " AND NOT ({$acepta})"; }
        if (!empty($f['comercial'])) { $sql .= " AND ({$acepta}) AND u.confirmado=1 AND (cl.estado IS NULL OR cl.estado<>'suspendido')"; }
        $partes = [self::f($sql, $t, $p)];
        if (!empty($f['segmento'])) {
            $partes[] = self::f(' AND (');
            $partes[] = $this->segmentoSql($c);
            $partes[] = self::f(')=?', 's', [(string)$f['segmento']]);
        }
        return self::unir(...$partes);
    }

    // ---------------------------------------------------------------- listado

    public function contar(array $c, array $f): int
    {
        $need = ['v' => !empty($f['segmento']), 'vr' => !empty($f['profesional_id']) || !empty($f['servicio_id']), 'g' => false];
        [$sql, $t, $p] = self::unir(self::f('SELECT COUNT(*) n'), $this->desde($c, $f, $need), $this->donde($c, $f));
        return (int)($this->filas($sql, $t, $p)[0]['n'] ?? 0);
    }

    /**
     * Filas del listado de una página. Con $c['analitica'] añade importes (centavos) del rango; sin él no los consulta.
     * @return array<int,array<string,mixed>>
     */
    public function listar(array $c, array $f, int $limite, int $offset): array
    {
        $limite = max(1, min(5000, $limite)); $offset = max(0, $offset);
        $analitica = !empty($c['analitica']);
        $columnas = "SELECT u.id,u.nombre,u.apellido,u.email,u.telefono,u.confirmado,COALESCE(a.rol,IF(u.admin=1,'propietario','cliente')) rol_efectivo,"
            . "COALESCE(cl.estado,'sin_registro') membresia,cl.origen,cl.suspendido_motivo,cl.creado_en alta,"
            . "IF(COALESCE(pf.marketing_email,0)=1 AND pf.consentimiento_marketing_at IS NOT NULL,1,0) consentimiento,pf.consentimiento_marketing_at consentimiento_en,"
            . "COALESCE(v.visitas_total,0) visitas_total,v.primera primera_visita,v.ultima ultima_visita,IF(v.ultima IS NULL,NULL,DATEDIFF(?,v.ultima)) recencia,COALESCE(vr.n,0) visitas_rango,";
        $gasto = $analitica
            ? ",COALESCE(g.cobros_real,0) cobros_real,COALESCE(g.dev_real,0) dev_real,COALESCE(g.cobros_sim,0) cobros_sim,COALESCE(g.dev_sim,0) dev_sim,COALESCE(g.n_cobros_real,0) n_cobros_real"
            : '';
        $dir = strtoupper((string)($f['dir'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        $orden = self::ORDENES[$f['orden'] ?? 'nombre'] ?? self::ORDENES['nombre'];
        if (!$analitica && ($f['orden'] ?? '') === 'gasto') { $orden = self::ORDENES['nombre']; }
        $orden = str_replace('%d', $dir, $orden);
        [$sql, $t, $p] = self::unir(
            self::f($columnas, 's', [$c['hoy']]),
            $this->segmentoSql($c),
            self::f(' segmento' . $gasto),
            $this->desde($c, $f, ['v' => true, 'vr' => true, 'g' => $analitica]),
            $this->donde($c, $f),
            self::f(" ORDER BY {$orden}, u.id ASC LIMIT ? OFFSET ?", 'ii', [$limite, $offset])
        );
        return $this->filas($sql, $t, $p);
    }

    // ---------------------------------------------------------------- indicadores agregados

    /** Totales de la base de clientes y del rango. Ignora filtros de texto: describe a todos los clientes. @return array<string,mixed> */
    public function indicadores(array $c): array
    {
        $f = ['profesional_id' => null, 'servicio_id' => null];
        [$sql, $t, $p] = self::unir(
            self::f('SELECT COUNT(*) total,COALESCE(SUM(cl.estado=\'activo\'),0) m_activa,COALESCE(SUM(cl.estado=\'suspendido\'),0) m_suspendida,COALESCE(SUM(cl.usuario_id IS NULL),0) m_sin_registro,'
                . 'COALESCE(SUM(COALESCE(pf.marketing_email,0)=1 AND pf.consentimiento_marketing_at IS NOT NULL),0) con_consentimiento,'
                . 'COALESCE(SUM(cl.creado_en>=? AND cl.creado_en<?),0) nuevos,'
                . 'COALESCE(SUM(v.primera BETWEEN ? AND ?),0) primera_visita_en_rango,'
                . 'COALESCE(SUM(v.ultima IS NOT NULL AND DATEDIFF(?,v.ultima)<=?),0) activos,'
                . 'COALESCE(SUM(vr.n>0),0) con_visita_rango,COALESCE(SUM(vr.n>=2),0) repiten_rango,COALESCE(SUM(vr.n),0) visitas_rango,'
                . 'COALESCE(SUM(v.ultima IS NOT NULL),0) con_historial,AVG(IF(v.ultima IS NULL,NULL,DATEDIFF(?,v.ultima))) recencia_media',
                'sssssis', [$c['desde'] . ' 00:00:00', self::sigDia($c['hasta']) . ' 00:00:00', $c['desde'], $c['hasta'], $c['hoy'], (int)$c['dias_activo'], $c['hoy']]),
            $this->desde($c, $f, ['v' => true, 'vr' => true, 'g' => false]),
            $this->donde($c, $f)
        );
        return $this->filas($sql, $t, $p)[0] ?? [];
    }

    /** Citas del rango (hasta hoy) por estado, de clientes. @return array<string,int> */
    public function citasPorEstado(array $c): array
    {
        $filas = $this->filas('SELECT ci.estado,COUNT(*) n FROM citas ci INNER JOIN usuarios u ON u.id=ci.usuarioId WHERE ci.fecha BETWEEN ? AND ? GROUP BY ci.estado', 'ss', [$c['desde'], $c['hasta']]);
        return array_column(array_map(static fn(array $r): array => ['estado' => (string)$r['estado'], 'n' => (int)$r['n']], $filas), 'n', 'estado');
    }

    /** @return array<string,int> mes (AAAA-MM) => altas */
    public function altasPorMes(array $c): array
    {
        $filas = $this->filas("SELECT DATE_FORMAT(creado_en,'%Y-%m') mes,COUNT(*) n FROM clientes WHERE creado_en>=? AND creado_en<? GROUP BY mes ORDER BY mes", 'ss', [$c['desde'] . ' 00:00:00', self::sigDia($c['hasta']) . ' 00:00:00']);
        return array_column($filas, 'n', 'mes');
    }

    /** @return array<string,array{visitas:int,clientes:int}> */
    public function visitasPorMes(array $c): array
    {
        $filas = $this->filas("SELECT DATE_FORMAT(ci.fecha,'%Y-%m') mes,COUNT(*) visitas,COUNT(DISTINCT ci.usuarioId) clientes FROM citas ci INNER JOIN usuarios u ON u.id=ci.usuarioId WHERE ci.estado='completada' AND ci.fecha BETWEEN ? AND ? GROUP BY mes ORDER BY mes", 'ss', [$c['desde'], $c['hasta']]);
        $r = [];
        foreach ($filas as $fila) { $r[$fila['mes']] = ['visitas' => (int)$fila['visitas'], 'clientes' => (int)$fila['clientes']]; }
        return $r;
    }

    /** Cobros y devoluciones REALES por mes de su propio evento (centavos). @return array<string,array{cobros:int,devoluciones:int}> */
    public function cobrosPorMes(array $c): array
    {
        [$sql, $t, $p] = self::unir(
            self::f("SELECT DATE_FORMAT(t.fecha_evento,'%Y-%m') mes,SUM(IF(t.tipo='C',t.c,0)) cobros,SUM(IF(t.tipo='R',t.c,0)) devoluciones FROM ("),
            $this->eventosPagos($c['desde'], $c['hasta']),
            self::f(') t INNER JOIN usuarios u ON u.id=t.usuario LEFT JOIN usuario_accesos a ON a.usuario_id=u.id LEFT JOIN clientes cl ON cl.usuario_id=u.id WHERE t.mock=0 AND ' . self::CLIENTE_SQL . ' GROUP BY mes ORDER BY mes')
        );
        $r = [];
        foreach ($this->filas($sql, $t, $p) as $fila) { $r[$fila['mes']] = ['cobros' => (int)$fila['cobros'], 'devoluciones' => (int)$fila['devoluciones']]; }
        return $r;
    }

    /** Totales de gasto del rango de todos los clientes (centavos). @return array<string,int> */
    public function gastoAgregado(array $c): array
    {
        [$sql, $t, $p] = self::unir(
            self::f('SELECT COALESCE(SUM(g.cobros_real),0) cobros_real,COALESCE(SUM(g.dev_real),0) dev_real,COALESCE(SUM(g.cobros_sim),0) cobros_sim,COALESCE(SUM(g.dev_sim),0) dev_sim,COALESCE(SUM(g.n_cobros_real),0) n_cobros_real,COALESCE(SUM(g.cobros_real>0 OR g.dev_real>0),0) clientes_con_gasto FROM ('),
            $this->gastoPorUsuario($c['desde'], $c['hasta']),
            self::f(') g INNER JOIN usuarios u ON u.id=g.usuario LEFT JOIN usuario_accesos a ON a.usuario_id=u.id LEFT JOIN clientes cl ON cl.usuario_id=u.id WHERE ' . self::CLIENTE_SQL)
        );
        $fila = $this->filas($sql, $t, $p)[0] ?? [];
        return array_map('intval', $fila + ['cobros_real' => 0, 'dev_real' => 0, 'cobros_sim' => 0, 'dev_sim' => 0, 'n_cobros_real' => 0, 'clientes_con_gasto' => 0]);
    }

    /** Distribución de clientes por segmento a la fecha de hoy. @return array<string,int> */
    public function distribucionSegmentos(array $c): array
    {
        [$sql, $t, $p] = self::unir(
            self::f('SELECT '), $this->segmentoSql($c), self::f(' segmento,COUNT(*) n'),
            $this->desde($c, [], ['v' => true]), $this->donde($c, []), self::f(' GROUP BY segmento')
        );
        return array_map('intval', array_column($this->filas($sql, $t, $p), 'n', 'segmento'));
    }

    /** Prestaciones completadas por servicio en el rango (máx. $limite). */
    public function serviciosMasUsados(array $c, int $limite = 8): array
    {
        $limite = max(1, min(50, $limite));
        return $this->filas("SELECT cs.servicioId servicio_id,COALESCE(MAX(s.nombre),MAX(cs.nombre_servicio),'Servicio eliminado') nombre,COUNT(*) prestaciones,COUNT(DISTINCT ci.usuarioId) clientes "
            . "FROM citasservicios cs INNER JOIN citas ci ON ci.id=cs.citaId INNER JOIN usuarios u ON u.id=ci.usuarioId LEFT JOIN servicios s ON s.id=cs.servicioId "
            . "WHERE ci.estado='completada' AND ci.fecha BETWEEN ? AND ? GROUP BY cs.servicioId ORDER BY prestaciones DESC,nombre ASC LIMIT ?", 'ssi', [$c['desde'], $c['hasta'], $limite]);
    }

    /**
     * Cohortes por mes de PRIMERA visita completada (<= hoy): clientes y cuántos tuvieron otra visita completada
     * en un día posterior dentro de $ventana días (sin contar nada posterior a hoy).
     */
    public function cohortes(array $c, int $ventana, int $maxMeses = 12): array
    {
        $ventana = max(1, $ventana); $maxMeses = max(1, min(60, $maxMeses));
        return $this->filas("SELECT DATE_FORMAT(x.primera,'%Y-%m') cohorte,COUNT(*) clientes,"
            . "COALESCE(SUM(EXISTS(SELECT 1 FROM citas c2 WHERE c2.usuarioId=x.usuarioId AND c2.estado='completada' AND c2.fecha>x.primera AND c2.fecha<=LEAST(DATE_ADD(x.primera,INTERVAL ? DAY),CAST(? AS DATE)))),0) repiten "
            . "FROM (SELECT usuarioId,MIN(fecha) primera FROM citas WHERE estado='completada' AND fecha<=? GROUP BY usuarioId HAVING MIN(fecha) BETWEEN ? AND ?) x "
            . "INNER JOIN usuarios u ON u.id=x.usuarioId GROUP BY cohorte ORDER BY cohorte DESC LIMIT ?",
            'issssi', [$ventana, $c['hoy'], $c['hoy'], $c['desde'], $c['hasta'], $maxMeses]);
    }

    /** @return array{puntos:int,canjes_pendientes:int,canjes_entregados:int,clientes_con_puntos:int} */
    public function fidelidadAgregada(): array
    {
        $r = $this->filas("SELECT COALESCE(SUM(fm.puntos),0) puntos,COALESCE(SUM(fm.tipo='canje' AND fm.canje_estado='pendiente'),0) pendientes,COALESCE(SUM(fm.tipo='canje' AND fm.canje_estado='entregado'),0) entregados "
            . "FROM fidelidad_movimientos fm INNER JOIN usuarios u ON u.id=fm.usuario_id")[0] ?? [];
        $con = $this->filas('SELECT COUNT(*) n FROM (SELECT usuario_id FROM fidelidad_movimientos GROUP BY usuario_id HAVING SUM(puntos)>0) x')[0]['n'] ?? 0;
        return ['puntos' => (int)($r['puntos'] ?? 0), 'canjes_pendientes' => (int)($r['pendientes'] ?? 0), 'canjes_entregados' => (int)($r['entregados'] ?? 0), 'clientes_con_puntos' => (int)$con];
    }

    /**
     * Población RFM: clientes con >= 1 visita completada en la ventana. Recencia (días), frecuencia (visitas en la ventana)
     * y gasto neto real (centavos) en la ventana. @return array<int,array{id:int,recencia:int,frecuencia:int,gasto:int}>
     */
    public function poblacionRfm(string $hoy, int $ventanaDias): array
    {
        $ini = (new \DateTimeImmutable($hoy))->modify('-' . max(1, $ventanaDias) . ' days')->format('Y-m-d');
        [$sql, $t, $p] = self::unir(
            self::f("SELECT x.usuarioId id,DATEDIFF(?,x.ultima) recencia,x.f frecuencia,CAST(COALESCE(g.cobros_real,0)-COALESCE(g.dev_real,0) AS SIGNED) gasto "
                . "FROM (SELECT usuarioId,MAX(fecha) ultima,COUNT(*) f FROM citas WHERE estado='completada' AND fecha BETWEEN ? AND ? GROUP BY usuarioId) x "
                . "INNER JOIN usuarios u ON u.id=x.usuarioId LEFT JOIN usuario_accesos a ON a.usuario_id=u.id LEFT JOIN clientes cl ON cl.usuario_id=u.id LEFT JOIN (", 'sss', [$hoy, $ini, $hoy]),
            $this->gastoPorUsuario($ini, $hoy),
            self::f(') g ON g.usuario=x.usuarioId WHERE ' . self::CLIENTE_SQL)
        );
        return array_map(static fn(array $r): array => ['id' => (int)$r['id'], 'recencia' => (int)$r['recencia'], 'frecuencia' => (int)$r['frecuencia'], 'gasto' => (int)$r['gasto']], $this->filas($sql, $t, $p));
    }

    // ---------------------------------------------------------------- ficha 360°

    /** Datos base de un cliente con las mismas métricas del listado (null si no es cliente). */
    public function cliente(array $c, int $id): ?array
    {
        $f = ['id' => $id, 'profesional_id' => null, 'servicio_id' => null, 'orden' => 'nombre', 'dir' => 'ASC'];
        return $this->listar($c, $f, 1, 0)[0] ?? null;
    }

    /** Citas del cliente con servicios e importe histórico (snapshot). @return array<int,array<string,mixed>> */
    public function citasDeCliente(int $id, int $limite = 100): array
    {
        $limite = max(1, min(300, $limite));
        return $this->filas("SELECT ci.id,ci.fecha,ci.hora,ci.estado,ci.estado_pago,ci.cancelado_por,ci.cancelado_at,p.nombre profesional,"
            . "GROUP_CONCAT(COALESCE(cs.nombre_servicio,s.nombre,'Servicio eliminado') ORDER BY cs.id SEPARATOR ' · ') servicios,COALESCE(SUM(cs.precio_servicio),0) importe "
            . "FROM citas ci LEFT JOIN profesionales p ON p.id=ci.profesionalId LEFT JOIN citasservicios cs ON cs.citaId=ci.id LEFT JOIN servicios s ON s.id=cs.servicioId "
            . "WHERE ci.usuarioId=? GROUP BY ci.id,ci.fecha,ci.hora,ci.estado,ci.estado_pago,ci.cancelado_por,ci.cancelado_at,p.nombre ORDER BY ci.fecha DESC,ci.hora DESC,ci.id DESC LIMIT ?", 'ii', [$id, $limite]);
    }

    /** @return array<string,int> estado => cantidad (todas las fechas) */
    public function citasPorEstadoDeCliente(int $id): array
    {
        return array_map('intval', array_column($this->filas('SELECT estado,COUNT(*) n FROM citas WHERE usuarioId=? GROUP BY estado', 'i', [$id]), 'n', 'estado'));
    }

    /** Pagos atribuibles al cliente (cobros y reembolsos), más recientes primero. @return array<int,array<string,mixed>> */
    public function pagosDeCliente(int $id, int $limite = 100): array
    {
        $limite = max(1, min(300, $limite));
        return $this->filas("SELECT pg.id,pg.referencia_pago,pg.proveedor,pg.monto,pg.moneda,pg.estado,pg.metodo_pago,COALESCE(pg.pagado_at,pg.created_at) fecha_cobro,pg.reembolsado_at,COALESCE(pg.cita_id,pg.cita_referencia_id) cita_id,"
            . "IF(COALESCE(NULLIF(TRIM(pg.proveedor),''),'mock')='mock',1,0) simulado FROM pagos pg LEFT JOIN citas ci ON ci.id=COALESCE(pg.cita_id,pg.cita_referencia_id) "
            . "WHERE pg.usuario_id=? OR (pg.usuario_id IS NULL AND ci.usuarioId=?) ORDER BY COALESCE(pg.pagado_at,pg.created_at) DESC,pg.id DESC LIMIT ?", 'iii', [$id, $id, $limite]);
    }

    /** Totales históricos de gasto del cliente (centavos) entre dos fechas. @return array<string,int> */
    public function gastoDeCliente(int $id, string $desde, string $hasta): array
    {
        [$sql, $t, $p] = $this->gastoPorUsuario($desde, $hasta, $id);
        $fila = $this->filas($sql, $t, $p)[0] ?? [];
        return array_map('intval', $fila + ['cobros_real' => 0, 'dev_real' => 0, 'cobros_sim' => 0, 'dev_sim' => 0, 'n_cobros_real' => 0]);
    }

    /** @return array{puntos:int,canjes_pendientes:int,canjes_entregados:int,movimientos:array} */
    public function fidelidadDeCliente(int $id, int $limite = 100): array
    {
        $limite = max(1, min(300, $limite));
        $r = $this->filas("SELECT COALESCE(SUM(puntos),0) puntos,COALESCE(SUM(tipo='canje' AND canje_estado='pendiente'),0) pendientes,COALESCE(SUM(tipo='canje' AND canje_estado='entregado'),0) entregados FROM fidelidad_movimientos WHERE usuario_id=?", 'i', [$id])[0] ?? [];
        $mov = $this->filas("SELECT fm.id,fm.puntos,fm.tipo,fm.referencia,fm.cita_id,fm.beneficio_snapshot,fm.canje_estado,fm.atendido_en,fm.creado_en,CONCAT(ua.nombre,' ',ua.apellido) atendido_por "
            . "FROM fidelidad_movimientos fm LEFT JOIN usuarios ua ON ua.id=fm.atendido_por WHERE fm.usuario_id=? ORDER BY fm.creado_en DESC,fm.id DESC LIMIT ?", 'ii', [$id, $limite]);
        return ['puntos' => (int)($r['puntos'] ?? 0), 'canjes_pendientes' => (int)($r['pendientes'] ?? 0), 'canjes_entregados' => (int)($r['entregados'] ?? 0), 'movimientos' => $mov];
    }

    /** @return array<int,array<string,mixed>> */
    public function listaEsperaDeCliente(int $id, int $limite = 50): array
    {
        $limite = max(1, min(200, $limite));
        return $this->filas('SELECT l.id,l.fecha,l.estado,l.creado_en,s.nombre servicio,p.nombre profesional FROM lista_espera l INNER JOIN servicios s ON s.id=l.servicio_id LEFT JOIN profesionales p ON p.id=l.profesional_id WHERE l.usuario_id=? ORDER BY l.fecha DESC,l.id DESC LIMIT ?', 'ii', [$id, $limite]);
    }

    /** Servicios y profesionales realmente usados en visitas completadas (preferencias observadas). */
    public function preferenciasDeCliente(int $id, string $hoy, int $limite = 5): array
    {
        $limite = max(1, min(20, $limite));
        $servicios = $this->filas("SELECT COALESCE(MAX(s.nombre),MAX(cs.nombre_servicio),'Servicio eliminado') nombre,COUNT(*) veces,MAX(ci.fecha) ultima FROM citasservicios cs INNER JOIN citas ci ON ci.id=cs.citaId LEFT JOIN servicios s ON s.id=cs.servicioId WHERE ci.usuarioId=? AND ci.estado='completada' AND ci.fecha<=? GROUP BY cs.servicioId ORDER BY veces DESC,ultima DESC LIMIT ?", 'isi', [$id, $hoy, $limite]);
        $profesionales = $this->filas("SELECT p.nombre,COUNT(*) veces,MAX(ci.fecha) ultima FROM citas ci INNER JOIN profesionales p ON p.id=ci.profesionalId WHERE ci.usuarioId=? AND ci.estado='completada' AND ci.fecha<=? GROUP BY p.id,p.nombre ORDER BY veces DESC,ultima DESC LIMIT ?", 'isi', [$id, $hoy, $limite]);
        return ['servicios' => $servicios, 'profesionales' => $profesionales];
    }

    // ---------------------------------------------------------------- vista mínima del profesional

    /** Clientes con >= 1 visita completada con ese profesional (<= hoy). @return array{total:int,filas:array} */
    public function clientesDeProfesional(int $profesionalId, string $hoy, string $q, int $limite, int $offset): array
    {
        $limite = max(1, min(100, $limite)); $offset = max(0, $offset);
        $filtro = ''; $t = 'is'; $p = [$profesionalId, $hoy];
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $filtro = " WHERE (u.nombre LIKE ? OR u.apellido LIKE ? OR CONCAT(u.nombre,' ',u.apellido) LIKE ? OR u.email LIKE ? OR u.telefono LIKE ?)";
            $t .= 'sssss'; array_push($p, $like, $like, $like, $like, $like);
        }
        $base = " FROM (SELECT usuarioId,COUNT(*) visitas,MAX(fecha) ultima FROM citas WHERE profesionalId=? AND estado='completada' AND fecha<=? GROUP BY usuarioId) x INNER JOIN usuarios u ON u.id=x.usuarioId" . $filtro;
        $total = (int)($this->filas('SELECT COUNT(*) n' . $base, $t, $p)[0]['n'] ?? 0);
        $filas = $this->filas('SELECT u.id,u.nombre,u.apellido,u.email,u.telefono,x.visitas,x.ultima' . $base . ' ORDER BY u.apellido ASC,u.nombre ASC,u.id ASC LIMIT ? OFFSET ?', $t . 'ii', array_merge($p, [$limite, $offset]));
        return ['total' => $total, 'filas' => $filas];
    }

    /** Contacto operativo del cliente solo si ese profesional lo atendió; null en caso contrario. */
    public function clienteDeProfesional(int $profesionalId, int $clienteId, string $hoy): ?array
    {
        return $this->filas("SELECT u.id,u.nombre,u.apellido,u.email,u.telefono FROM usuarios u WHERE u.id=? AND EXISTS(SELECT 1 FROM citas ci WHERE ci.usuarioId=u.id AND ci.profesionalId=? AND ci.estado='completada' AND ci.fecha<=?)", 'iis', [$clienteId, $profesionalId, $hoy])[0] ?? null;
    }

    /** Servicios realizados por ese profesional a ese cliente. @return array<int,array<string,mixed>> */
    public function serviciosConProfesional(int $profesionalId, int $clienteId, string $hoy): array
    {
        return $this->filas("SELECT COALESCE(MAX(s.nombre),MAX(cs.nombre_servicio),'Servicio eliminado') nombre,COUNT(*) veces,MAX(ci.fecha) ultima FROM citasservicios cs INNER JOIN citas ci ON ci.id=cs.citaId LEFT JOIN servicios s ON s.id=cs.servicioId WHERE ci.usuarioId=? AND ci.profesionalId=? AND ci.estado='completada' AND ci.fecha<=? GROUP BY cs.servicioId ORDER BY veces DESC,ultima DESC", 'iis', [$clienteId, $profesionalId, $hoy]);
    }
}

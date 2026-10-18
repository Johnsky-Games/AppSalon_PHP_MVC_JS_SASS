<?php
namespace Repositories;

use Model\ActiveRecord;
use mysqli;

/**
 * Lecturas del CRM de clientes. Solo ejecuta SELECT preparados: las entradas llegan validadas por
 * CrmService (fechas en formato AAAA-MM-DD, enteros, listas blancas). Los importes viajan en centavos enteros.
 *
 * UN SOLO UNIVERSO (ronda 5): la lista, el conteo, los indicadores, la distribución por segmentos, los gráficos, los totales y
 * la exportación parten de la MISMA construcción: desde() + donde() con los mismos filtros `$f` (ver universo()). Las consultas
 * agregadas se unen al conjunto de ids de ese universo (universoIds()) en vez de reconstruir sus propias condiciones; así un
 * filtro nuevo se añade en un único sitio y nunca puede divergir entre pantalla, indicador y archivo.
 *
 * Definiciones de dominio que este repositorio materializa en SQL (documentadas en docs/CRM_CLIENTES.md y docs/R5_CRM_FILTROS.md):
 *  - Cliente: fila en `clientes`, o cuenta legada sin fila cuyo rol efectivo sea cliente (misma regla que
 *    AuthorizationService::contexto()), o cuenta con alguna cita. Una cuenta solo laboral sin ninguna de las tres no cuenta.
 *  - Visita: cita en estado `completada` con fecha <= hoy (hoy se calcula en America/Guayaquil y llega por parámetro).
 *  - Cobro real: pago `completado`/`reembolsado` cuyo proveedor no es `mock`, en su fecha de cobro
 *    (COALESCE(pagado_at, created_at)); devolución: pago `reembolsado` en su fecha de reembolso
 *    (COALESCE(reembolsado_at, pagado_at, created_at)). Mismo criterio que ReporteService.
 *  - Comunicaciones comerciales: otorgado (perfil con marketing_email=1 y fecha), revocado (no otorgado y la ÚLTIMA decisión del
 *    historial es un retiro) o sin decisión (nunca decidió). No es una suscripción de pago: no existe tal entidad.
 *  - Saldo pendiente: citas atendidas (`completada`) cuyo estado de pago aún no es pagado/reembolsado; importe = total de sus
 *    servicios (precio histórico) menos los cobros reales confirmados de esa cita, nunca negativo.
 */
final class CrmRepository
{
    /** Condición SQL de pertenencia al universo de clientes (alias u, a, cl definidos por el FROM). */
    public const CLIENTE_SQL = "(cl.usuario_id IS NOT NULL OR COALESCE(a.rol,IF(u.admin=1,'propietario','cliente'))='cliente' OR EXISTS(SELECT 1 FROM citas cx WHERE cx.usuarioId=u.id))";
    /** Estados de pago de una cita atendida que dejan saldo por cobrar. */
    public const ESTADOS_PAGO_CON_SALDO = ['pendiente', 'parcial', 'por_verificar'];

    /** Ordenamientos permitidos: clave pública => expresión SQL. */
    private const ORDENES = [
        'nombre' => 'u.apellido %d, u.nombre %d',
        'alta' => 'cl.creado_en %d',
        'ultima' => 'v.ultima %d',
        'proxima' => 'ISNULL(px.proxima), px.proxima %d',
        'visitas' => 'COALESCE(vr.n,0) %d',
        'visitas_total' => 'COALESCE(v.visitas_total,0) %d',
        'gasto' => '(COALESCE(g.cobros_real,0)-COALESCE(g.dev_real,0)) %d',
        'saldo' => 'COALESCE(sd.saldo,0) %d',
    ];

    private mysqli $db;
    /** @var array{vigente:int,v1:int}|null */
    private ?array $versiones = null;

    /** @param string $propositoConsentimiento propósito cuyas versiones rigen «consentimiento vigente» (marketing_email salvo en pruebas) */
    public function __construct(?mysqli $db = null, private string $propositoConsentimiento = ConsentimientoRepository::MARKETING)
    {
        $conexion = $db ?? ActiveRecord::getDB();
        if (!$conexion instanceof mysqli) { throw new PersistenceException('Sin conexión a la base de datos.'); }
        $this->db = $conexion;
    }

    /**
     * Ids de la versión de consentimiento vigente y de la versión 1 (histórica) del propósito. Se resuelven una vez por instancia
     * y se incrustan como enteros (nunca texto del usuario) en el SQL. Un consentimiento sin versión registrada cuenta como la 1.
     * @return array{vigente:int,v1:int}
     */
    public function versionesConsentimiento(): array
    {
        if ($this->versiones === null) {
            $repo = new ConsentimientoRepository($this->db, $this->propositoConsentimiento);
            $this->versiones = ['vigente' => (int)$repo->vigente()['id'], 'v1' => (int)($repo->porNumero(1)['id'] ?? 0)];
        }
        return $this->versiones;
    }

    /** Fragmentos SQL del estado de consentimiento (alias pf = perfiles_usuario). @return array{acepta:string,efectiva:string,vigente:string,anterior:string} */
    private function sqlConsentimiento(): array
    {
        $v = $this->versionesConsentimiento();
        $acepta = 'COALESCE(pf.marketing_email,0)=1 AND pf.consentimiento_marketing_at IS NOT NULL';
        $efectiva = 'COALESCE(pf.consentimiento_marketing_version_id,' . $v['v1'] . ')';
        return ['acepta' => $acepta, 'efectiva' => $efectiva,
            'vigente' => "({$acepta} AND {$efectiva}=" . $v['vigente'] . ')',
            'anterior' => "({$acepta} AND {$efectiva}<>" . $v['vigente'] . ')'];
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
            // Los eventos parciales tienen importe y fecha propios. El fallback legado solo aporta la parte
            // de un reembolso total que NO esté ya representada en el libro, evitando contarlo dos veces.
            self::f(" UNION ALL SELECT COALESCE(pg.usuario_id,ci.usuarioId),'R',{$mock},GREATEST(0,CAST(ROUND(pg.monto*100) AS SIGNED)-COALESCE((SELECT SUM(d0.monto_centavos) FROM cobros_devoluciones d0 WHERE d0.pago_id=pg.id),0)),{$devol}{$join}"
                . " WHERE pg.estado='reembolsado' AND {$devol}>=? AND {$devol}<?{$u}", 'ss' . $tu, array_merge([$ini, $fin], $pu)),
            self::f(" UNION ALL SELECT COALESCE(pg.usuario_id,ci.usuarioId),'R',{$mock},d.monto_centavos,d.registrado_en{$join} JOIN cobros_devoluciones d ON d.pago_id=pg.id"
                . " WHERE d.registrado_en>=? AND d.registrado_en<?{$u}", 'ss' . $tu, array_merge([$ini, $fin], $pu))
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

    /** Saldo pendiente por usuario (centavos) de citas atendidas hasta hoy. Columnas: usuarioId, saldo, citas_saldo. */
    private function saldoPorUsuario(string $hoy): array
    {
        $estados = "'" . implode("','", self::ESTADOS_PAGO_CON_SALDO) . "'";   // constantes del código, nunca entrada del usuario
        return self::f(
            'SELECT ci.usuarioId,CAST(SUM(GREATEST(0,COALESCE(ev.total,tt.total)-COALESCE(pp.pagado,0))) AS SIGNED) saldo,COUNT(*) citas_saldo FROM citas ci '
            . 'INNER JOIN (SELECT citaId,SUM(ROUND(precio_servicio*100)) total FROM citasservicios GROUP BY citaId) tt ON tt.citaId=ci.id '
            . "LEFT JOIN (SELECT v.cita_id,v.total_centavos-COALESCE(d.credito,0) total FROM erp_ventas v LEFT JOIN(SELECT venta_id,SUM(monto_centavos) credito FROM erp_devoluciones GROUP BY venta_id)d ON d.venta_id=v.id WHERE v.estado<>'anulada')ev ON ev.cita_id=ci.id "
            . "LEFT JOIN (SELECT COALESCE(pg.cita_id,pg.cita_referencia_id) cid,SUM(GREATEST(0,ROUND(pg.monto*100)-COALESCE((SELECT SUM(d.monto_centavos) FROM cobros_devoluciones d WHERE d.pago_id=pg.id),0))) pagado FROM pagos pg WHERE pg.estado='completado' AND COALESCE(NULLIF(TRIM(pg.proveedor),''),'mock')<>'mock' GROUP BY cid) pp ON pp.cid=ci.id "
            . "WHERE ci.estado='completada' AND ci.fecha<=? AND ci.estado_pago IN ({$estados}) GROUP BY ci.usuarioId",
            's', [$hoy]
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

    // ---------------------------------------------------------------- el universo único

    /**
     * Uniones que exigen los filtros activos (más las que pida quien consulta). Claves: v (visitas históricas), vr (visitas del
     * periodo y filtros), g (gasto del periodo), px (próxima cita), sd (saldo pendiente), rv (último retiro de consentimiento).
     * @return array{v:bool,vr:bool,g:bool,px:bool,sd:bool,rv:bool}
     */
    public static function necesidades(array $f, array $extra = []): array
    {
        $n = [
            'v' => !empty($f['segmento']) || !empty($f['actividad']) || !empty($f['ultima_desde']) || !empty($f['ultima_hasta']),
            'vr' => !empty($f['profesional_id']) || !empty($f['servicio_id']) || ($f['visitas_min'] ?? null) !== null || ($f['visitas_max'] ?? null) !== null,
            'g' => ($f['gasto_min'] ?? null) !== null || ($f['gasto_max'] ?? null) !== null,
            'px' => !empty($f['proxima']) || !empty($f['proxima_desde']) || !empty($f['proxima_hasta']),
            'sd' => !empty($f['saldo']),
            'rv' => in_array((string)($f['consentimiento'] ?? ''), ['revocado', 'sin_decision'], true),
        ];
        foreach ($extra as $k => $v) { $n[$k] = !empty($n[$k]) || !empty($v); }
        return $n;
    }

    /** FROM con las uniones necesarias. $need: v, vr, g, px, sd, rv (ver necesidades()). */
    private function desde(array $c, array $f, array $need): array
    {
        $partes = [self::f(' FROM usuarios u LEFT JOIN usuario_accesos a ON a.usuario_id=u.id LEFT JOIN clientes cl ON cl.usuario_id=u.id LEFT JOIN perfiles_usuario pf ON pf.usuarioId=u.id LEFT JOIN consentimiento_versiones cvv ON cvv.id=COALESCE(pf.consentimiento_marketing_version_id,' . (int)$this->versionesConsentimiento()['v1'] . ')')];
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
        if (!empty($need['px'])) {
            $partes[] = self::f(" LEFT JOIN (SELECT usuarioId,MIN(fecha) proxima FROM citas WHERE estado IN ('confirmada','en_servicio') AND fecha>=? GROUP BY usuarioId) px ON px.usuarioId=u.id", 's', [$c['hoy']]);
        }
        if (!empty($need['sd'])) {
            $partes[] = self::f(' LEFT JOIN (');
            $partes[] = $this->saldoPorUsuario($c['hoy']);
            $partes[] = self::f(') sd ON sd.usuarioId=u.id');
        }
        if (!empty($need['rv'])) {
            $partes[] = self::f(' LEFT JOIN (SELECT h.usuario_id FROM consentimientos_historial h INNER JOIN (SELECT usuario_id,MAX(id) mid FROM consentimientos_historial WHERE proposito=? GROUP BY usuario_id) ul ON ul.mid=h.id WHERE h.valor=0) rv ON rv.usuario_id=u.id', 's', [$this->propositoConsentimiento]);
        }
        return self::unir(...$partes);
    }

    /** WHERE con el universo de clientes y los filtros del listado. */
    private function donde(array $c, array $f): array
    {
        $sql = ' WHERE ' . self::CLIENTE_SQL; $t = ''; $p = [];
        // La pertenencia a clientes no elimina el perfil laboral: la base abre con clientes externos.
        // El mismo filtro alcanza indicadores, listados y exportaciones, sin borrar membresías ni historial.
        $vinculo = $f['vinculo'] ?? 'todos';
        if ($vinculo === 'externo' || $vinculo === 'equipo') {
            $sql .= " AND COALESCE(a.rol,IF(u.admin=1,'propietario','cliente'))" . ($vinculo === 'externo' ? "='cliente'" : "<>'cliente'");
        }
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
        // Cuenta: correo confirmado o no (usuarios.confirmado).
        $cuenta = (string)($f['cuenta'] ?? '');
        if ($cuenta === 'confirmada') { $sql .= ' AND u.confirmado=1'; }
        elseif ($cuenta === 'sin_confirmar') { $sql .= ' AND u.confirmado=0'; }
        // Membresía de cliente (clientes.estado). No es una suscripción de pago.
        $membresia = (string)($f['membresia'] ?? '');
        if ($membresia === 'sin_registro') { $sql .= ' AND cl.usuario_id IS NULL'; }
        elseif ($membresia === 'activo' || $membresia === 'suspendido') { $sql .= ' AND cl.estado=?'; $t .= 's'; $p[] = $membresia; }
        $con = $this->sqlConsentimiento();
        $acepta = $con['acepta'];
        // si = otorgado (cualquier versión) · vigente = otorgado con el texto en vigor · anterior = otorgado con una versión que ya no rige
        // · no = no otorgado (revocado o sin decisión) · revocado = su última decisión fue un retiro · sin_decision = nunca decidió.
        $consentimiento = (string)($f['consentimiento'] ?? '');
        if ($consentimiento === 'si') { $sql .= " AND ({$acepta})"; }
        elseif ($consentimiento === 'vigente') { $sql .= " AND {$con['vigente']}"; }
        elseif ($consentimiento === 'anterior') { $sql .= " AND {$con['anterior']}"; }
        elseif ($consentimiento === 'no') { $sql .= " AND NOT ({$acepta})"; }
        elseif ($consentimiento === 'revocado') { $sql .= " AND NOT ({$acepta}) AND rv.usuario_id IS NOT NULL"; }
        elseif ($consentimiento === 'sin_decision') { $sql .= " AND NOT ({$acepta}) AND rv.usuario_id IS NULL"; }
        // Política de campañas: la lista comercial solo incluye consentimientos de la versión VIGENTE del texto.
        if (!empty($f['comercial'])) { $sql .= " AND {$con['vigente']} AND u.confirmado=1 AND (cl.estado IS NULL OR cl.estado<>'suspendido')"; }
        // Actividad comercial por visitas completadas (hasta hoy): reciente / inactivo por periodo / nunca atendido.
        $actividad = (string)($f['actividad'] ?? '');
        if ($actividad === 'reciente') { $sql .= ' AND v.ultima IS NOT NULL AND DATEDIFF(?,v.ultima)<=?'; $t .= 'si'; array_push($p, $c['hoy'], (int)$c['dias_activo']); }
        elseif ($actividad === 'inactivo') { $sql .= ' AND v.ultima IS NOT NULL AND DATEDIFF(?,v.ultima)>?'; $t .= 'si'; array_push($p, $c['hoy'], (int)$c['dias_activo']); }
        elseif ($actividad === 'nunca') { $sql .= ' AND v.ultima IS NULL'; }
        if (!empty($f['ultima_desde'])) { $sql .= ' AND v.ultima>=?'; $t .= 's'; $p[] = $f['ultima_desde']; }
        if (!empty($f['ultima_hasta'])) { $sql .= ' AND v.ultima<=?'; $t .= 's'; $p[] = $f['ultima_hasta']; }
        // Próxima cita: la primera cita futura (hoy incluido) en estado confirmada o en servicio.
        $proxima = (string)($f['proxima'] ?? '');
        if ($proxima === 'con') { $sql .= ' AND px.proxima IS NOT NULL'; }
        elseif ($proxima === 'sin') { $sql .= ' AND px.proxima IS NULL'; }
        if (!empty($f['proxima_desde'])) { $sql .= ' AND px.proxima>=?'; $t .= 's'; $p[] = $f['proxima_desde']; }
        if (!empty($f['proxima_hasta'])) { $sql .= ' AND px.proxima<=?'; $t .= 's'; $p[] = $f['proxima_hasta']; }
        // Frecuencia: visitas completadas en el periodo (con el profesional y servicio elegidos, si los hay).
        if (($f['visitas_min'] ?? null) !== null) { $sql .= ' AND COALESCE(vr.n,0)>=?'; $t .= 'i'; $p[] = (int)$f['visitas_min']; }
        if (($f['visitas_max'] ?? null) !== null) { $sql .= ' AND COALESCE(vr.n,0)<=?'; $t .= 'i'; $p[] = (int)$f['visitas_max']; }
        // Gasto neto real del periodo (centavos).
        if (($f['gasto_min'] ?? null) !== null) { $sql .= ' AND (COALESCE(g.cobros_real,0)-COALESCE(g.dev_real,0))>=?'; $t .= 'i'; $p[] = (int)$f['gasto_min']; }
        if (($f['gasto_max'] ?? null) !== null) { $sql .= ' AND (COALESCE(g.cobros_real,0)-COALESCE(g.dev_real,0))<=?'; $t .= 'i'; $p[] = (int)$f['gasto_max']; }
        // Saldo pendiente de citas atendidas.
        $saldo = (string)($f['saldo'] ?? '');
        if ($saldo === 'con') { $sql .= ' AND COALESCE(sd.saldo,0)>0'; }
        elseif ($saldo === 'sin') { $sql .= ' AND COALESCE(sd.saldo,0)=0'; }
        $partes = [self::f($sql, $t, $p)];
        if (!empty($f['segmento'])) {
            $partes[] = self::f(' AND (');
            $partes[] = $this->segmentoSql($c);
            $partes[] = self::f(')=?', 's', [(string)$f['segmento']]);
        }
        return self::unir(...$partes);
    }

    /**
     * El universo de un conjunto de filtros: FROM + WHERE (sin SELECT). Lo usan listar(), contar(), totales(), indicadores(),
     * universoIds() y todos los agregados. `$extra` pide uniones adicionales (v, vr, g, px, sd, rv) además de las de los filtros.
     * @return array{0:string,1:string,2:array}
     */
    private function universo(array $c, array $f, array $extra = []): array
    {
        return self::unir($this->desde($c, $f, self::necesidades($f, $extra)), $this->donde($c, $f));
    }

    /** `SELECT u.id` del universo: los agregados se unen a esto con `INNER JOIN (…) uni ON uni.id = …`. @return array{0:string,1:string,2:array} */
    private function universoIds(array $c, array $f): array
    {
        return self::unir(self::f('SELECT u.id'), $this->universo($c, $f));
    }

    // ---------------------------------------------------------------- listado

    public function contar(array $c, array $f): int
    {
        [$sql, $t, $p] = self::unir(self::f('SELECT COUNT(*) n'), $this->universo($c, $f));
        return (int)($this->filas($sql, $t, $p)[0]['n'] ?? 0);
    }

    /**
     * Totales del universo (los mismos que ve la persona en pantalla y que cierran el archivo exportado):
     * clientes, visitas del periodo y, con $c['analitica'], gasto neto real y saldo pendiente (centavos).
     * @return array{clientes:int,visitas:int,cobros_real:?int,dev_real:?int,neto_sim:?int,gasto_neto:?int,saldo:?int}
     */
    public function totales(array $c, array $f): array
    {
        $analitica = !empty($c['analitica']);
        $select = 'SELECT COUNT(*) n,COALESCE(SUM(COALESCE(vr.n,0)),0) visitas'
            . ($analitica ? ',COALESCE(SUM(COALESCE(g.cobros_real,0)),0) cobros,COALESCE(SUM(COALESCE(g.dev_real,0)),0) dev,COALESCE(SUM(COALESCE(g.cobros_sim,0)-COALESCE(g.dev_sim,0)),0) neto_sim,COALESCE(SUM(COALESCE(sd.saldo,0)),0) saldo' : '');
        [$sql, $t, $p] = self::unir(self::f($select), $this->universo($c, $f, ['vr' => true, 'g' => $analitica, 'sd' => $analitica]));
        $r = $this->filas($sql, $t, $p)[0] ?? [];
        return ['clientes' => (int)($r['n'] ?? 0), 'visitas' => (int)($r['visitas'] ?? 0),
            'cobros_real' => $analitica ? (int)($r['cobros'] ?? 0) : null, 'dev_real' => $analitica ? (int)($r['dev'] ?? 0) : null,
            'neto_sim' => $analitica ? (int)($r['neto_sim'] ?? 0) : null,
            'gasto_neto' => $analitica ? (int)($r['cobros'] ?? 0) - (int)($r['dev'] ?? 0) : null, 'saldo' => $analitica ? (int)($r['saldo'] ?? 0) : null];
    }

    /**
     * Filas del listado de una página. Con $c['analitica'] añade importes (centavos) del rango y saldo; sin él no los consulta.
     * @return array<int,array<string,mixed>>
     */
    public function listar(array $c, array $f, int $limite, int $offset): array
    {
        $limite = max(1, min(5000, $limite)); $offset = max(0, $offset);
        $analitica = !empty($c['analitica']);
        $con = $this->sqlConsentimiento();
        $columnas = "SELECT u.id,u.nombre,u.apellido,u.email,u.telefono,u.confirmado,COALESCE(a.rol,IF(u.admin=1,'propietario','cliente')) rol_efectivo,"
            . "COALESCE(cl.estado,'sin_registro') membresia,cl.origen,cl.suspendido_motivo,cl.creado_en alta,"
            . "IF(COALESCE(pf.marketing_email,0)=1 AND pf.consentimiento_marketing_at IS NOT NULL,1,0) consentimiento,pf.consentimiento_marketing_at consentimiento_en,"
            . "IF(COALESCE(pf.marketing_email,0)=1 AND pf.consentimiento_marketing_at IS NOT NULL,cvv.version,NULL) consentimiento_version,"
            . "IF(COALESCE(pf.marketing_email,0)=1 AND pf.consentimiento_marketing_at IS NOT NULL,cvv.es_historica,NULL) consentimiento_version_historica,"
            . "IF(" . $con['anterior'] . ",1,0) consentimiento_anterior,"
            . "IF(" . $con['acepta'] . ",'otorgado',IF(rv.usuario_id IS NOT NULL,'revocado','sin_decision')) comunicaciones,rv.revocado_en,rv.revocado_version,"
            . "px.proxima proxima_cita,"
            . "COALESCE(v.visitas_total,0) visitas_total,v.primera primera_visita,v.ultima ultima_visita,IF(v.ultima IS NULL,NULL,DATEDIFF(?,v.ultima)) recencia,COALESCE(vr.n,0) visitas_rango,";
        $gasto = $analitica
            ? ",COALESCE(g.cobros_real,0) cobros_real,COALESCE(g.dev_real,0) dev_real,COALESCE(g.cobros_sim,0) cobros_sim,COALESCE(g.dev_sim,0) dev_sim,COALESCE(g.n_cobros_real,0) n_cobros_real,COALESCE(sd.saldo,0) saldo,COALESCE(sd.citas_saldo,0) citas_saldo"
            : '';
        $dir = strtoupper((string)($f['dir'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        $ordenClave = (string)($f['orden'] ?? 'nombre');
        if (!$analitica && in_array($ordenClave, ['gasto', 'saldo'], true)) { $ordenClave = 'nombre'; }
        $orden = str_replace('%d', $dir, self::ORDENES[$ordenClave] ?? self::ORDENES['nombre']);
        // La unión del último retiro (rv) en el listado trae fecha y versión; el filtro por consentimiento usa la misma unión mínima.
        $partes = [
            self::f($columnas, 's', [$c['hoy']]),
            $this->segmentoSql($c),
            self::f(' segmento' . $gasto),
            $this->desdeListado($c, $f, ['v' => true, 'vr' => true, 'g' => $analitica, 'px' => true, 'sd' => $analitica]),
            $this->donde($c, $f),
            self::f(" ORDER BY {$orden}, u.id ASC LIMIT ? OFFSET ?", 'ii', [$limite, $offset]),
        ];
        [$sql, $t, $p] = self::unir(...$partes);
        return $this->filas($sql, $t, $p);
    }

    /** FROM del listado: el del universo más la unión de retiro completa (con fecha y versión). */
    private function desdeListado(array $c, array $f, array $extra): array
    {
        $need = self::necesidades($f, $extra);
        $need['rv'] = false;   // la unión simple de rv se sustituye por la completa de abajo (mismo alias)
        return self::unir(
            $this->desde($c, $f, $need),
            self::f(' LEFT JOIN (SELECT h.usuario_id,h.ocurrido_en revocado_en,cv2.version revocado_version FROM consentimientos_historial h INNER JOIN (SELECT usuario_id,MAX(id) mid FROM consentimientos_historial WHERE proposito=? GROUP BY usuario_id) ul ON ul.mid=h.id AND h.valor=0 LEFT JOIN consentimiento_versiones cv2 ON cv2.id=h.version_id) rv ON rv.usuario_id=u.id', 's', [$this->propositoConsentimiento])
        );
    }

    // ---------------------------------------------------------------- indicadores agregados (mismo universo)

    /** Totales de la base de clientes y del periodo, DEL UNIVERSO FILTRADO. @return array<string,mixed> */
    public function indicadores(array $c, array $f = []): array
    {
        [$sql, $t, $p] = self::unir(
            self::f('SELECT COUNT(*) total,COALESCE(SUM(cl.estado=\'activo\'),0) m_activa,COALESCE(SUM(cl.estado=\'suspendido\'),0) m_suspendida,COALESCE(SUM(cl.usuario_id IS NULL),0) m_sin_registro,'
                . 'COALESCE(SUM(COALESCE(pf.marketing_email,0)=1 AND pf.consentimiento_marketing_at IS NOT NULL),0) con_consentimiento,'
                . 'COALESCE(SUM(' . $this->sqlConsentimiento()['vigente'] . '),0) con_consentimiento_vigente,COALESCE(SUM(' . $this->sqlConsentimiento()['anterior'] . '),0) con_consentimiento_anterior,'
                . 'COALESCE(SUM(cl.creado_en>=? AND cl.creado_en<?),0) nuevos,'
                . 'COALESCE(SUM(v.primera BETWEEN ? AND ?),0) primera_visita_en_rango,'
                . 'COALESCE(SUM(v.ultima IS NOT NULL AND DATEDIFF(?,v.ultima)<=?),0) activos,'
                . 'COALESCE(SUM(vr.n>0),0) con_visita_rango,COALESCE(SUM(vr.n>=2),0) repiten_rango,COALESCE(SUM(vr.n),0) visitas_rango,'
                . 'COALESCE(SUM(v.ultima IS NOT NULL),0) con_historial,AVG(IF(v.ultima IS NULL,NULL,DATEDIFF(?,v.ultima))) recencia_media,'
                . 'COALESCE(SUM(u.confirmado=1),0) cuenta_confirmada,COALESCE(SUM(u.confirmado=0),0) cuenta_sin_confirmar'
                . (!empty($c['analitica']) ? ',COALESCE(SUM(COALESCE(sd.saldo,0)>0),0) con_saldo,COALESCE(SUM(sd.saldo),0) saldo_total' : ''),
                'sssssis', [$c['desde'] . ' 00:00:00', self::sigDia($c['hasta']) . ' 00:00:00', $c['desde'], $c['hasta'], $c['hoy'], (int)$c['dias_activo'], $c['hoy']]),
            $this->universo($c, $f, ['v' => true, 'vr' => true, 'sd' => !empty($c['analitica'])])
        );
        return $this->filas($sql, $t, $p)[0] ?? [];
    }

    /**
     * Cuántos clientes del universo (sin el filtro de comunicaciones) están en cada estado: otorgado / revocado / sin decisión.
     * Un clic en cada cifra abre el mismo listado. @return array{otorgado:int,revocado:int,sin_decision:int}
     */
    public function comunicacionesPorEstado(array $c, array $f): array
    {
        $f['consentimiento'] = null;
        [$sql, $t, $p] = self::unir(
            self::f('SELECT COALESCE(SUM(' . $this->sqlConsentimiento()['acepta'] . '),0) otorgado,COALESCE(SUM(NOT (' . $this->sqlConsentimiento()['acepta'] . ') AND rv.usuario_id IS NOT NULL),0) revocado,'
                . 'COALESCE(SUM(NOT (' . $this->sqlConsentimiento()['acepta'] . ') AND rv.usuario_id IS NULL),0) sin_decision'),
            $this->universo($c, $f, ['rv' => true])
        );
        $r = $this->filas($sql, $t, $p)[0] ?? [];
        return ['otorgado' => (int)($r['otorgado'] ?? 0), 'revocado' => (int)($r['revocado'] ?? 0), 'sin_decision' => (int)($r['sin_decision'] ?? 0)];
    }

    /** Citas del periodo (hasta hoy) por estado, de los clientes del universo. @return array<string,int> */
    public function citasPorEstado(array $c, array $f = []): array
    {
        [$sql, $t, $p] = self::unir(
            self::f('SELECT ci.estado,COUNT(*) n FROM citas ci INNER JOIN ('),
            $this->universoIds($c, $f),
            self::f(') uni ON uni.id=ci.usuarioId WHERE ci.fecha BETWEEN ? AND ? GROUP BY ci.estado', 'ss', [$c['desde'], $c['hasta']])
        );
        $filas = $this->filas($sql, $t, $p);
        return array_column(array_map(static fn(array $r): array => ['estado' => (string)$r['estado'], 'n' => (int)$r['n']], $filas), 'n', 'estado');
    }

    /** @return array<string,int> mes (AAAA-MM) => altas */
    public function altasPorMes(array $c, array $f = []): array
    {
        [$sql, $t, $p] = self::unir(
            self::f("SELECT DATE_FORMAT(cl.creado_en,'%Y-%m') mes,COUNT(*) n FROM clientes cl INNER JOIN ("),
            $this->universoIds($c, $f),
            self::f(') uni ON uni.id=cl.usuario_id WHERE cl.creado_en>=? AND cl.creado_en<? GROUP BY mes ORDER BY mes', 'ss', [$c['desde'] . ' 00:00:00', self::sigDia($c['hasta']) . ' 00:00:00'])
        );
        return array_column($this->filas($sql, $t, $p), 'n', 'mes');
    }

    /** @return array<string,array{visitas:int,clientes:int}> */
    public function visitasPorMes(array $c, array $f = []): array
    {
        [$sql, $t, $p] = self::unir(
            self::f("SELECT DATE_FORMAT(ci.fecha,'%Y-%m') mes,COUNT(*) visitas,COUNT(DISTINCT ci.usuarioId) clientes FROM citas ci INNER JOIN ("),
            $this->universoIds($c, $f),
            self::f(") uni ON uni.id=ci.usuarioId WHERE ci.estado='completada' AND ci.fecha BETWEEN ? AND ? GROUP BY mes ORDER BY mes", 'ss', [$c['desde'], $c['hasta']])
        );
        $r = [];
        foreach ($this->filas($sql, $t, $p) as $fila) { $r[$fila['mes']] = ['visitas' => (int)$fila['visitas'], 'clientes' => (int)$fila['clientes']]; }
        return $r;
    }

    /** Cobros y devoluciones REALES por mes de su propio evento (centavos), de los clientes del universo. @return array<string,array{cobros:int,devoluciones:int}> */
    public function cobrosPorMes(array $c, array $f = []): array
    {
        [$sql, $t, $p] = self::unir(
            self::f("SELECT DATE_FORMAT(t.fecha_evento,'%Y-%m') mes,SUM(IF(t.tipo='C',t.c,0)) cobros,SUM(IF(t.tipo='R',t.c,0)) devoluciones FROM ("),
            $this->eventosPagos($c['desde'], $c['hasta']),
            self::f(') t INNER JOIN ('),
            $this->universoIds($c, $f),
            self::f(') uni ON uni.id=t.usuario WHERE t.mock=0 GROUP BY mes ORDER BY mes')
        );
        $r = [];
        foreach ($this->filas($sql, $t, $p) as $fila) { $r[$fila['mes']] = ['cobros' => (int)$fila['cobros'], 'devoluciones' => (int)$fila['devoluciones']]; }
        return $r;
    }

    /** Totales de gasto del periodo de los clientes del universo (centavos). @return array<string,int> */
    public function gastoAgregado(array $c, array $f = []): array
    {
        [$sql, $t, $p] = self::unir(
            self::f('SELECT COALESCE(SUM(g.cobros_real),0) cobros_real,COALESCE(SUM(g.dev_real),0) dev_real,COALESCE(SUM(g.cobros_sim),0) cobros_sim,COALESCE(SUM(g.dev_sim),0) dev_sim,COALESCE(SUM(g.n_cobros_real),0) n_cobros_real,COALESCE(SUM(g.cobros_real>0 OR g.dev_real>0),0) clientes_con_gasto FROM ('),
            $this->gastoPorUsuario($c['desde'], $c['hasta']),
            self::f(') g INNER JOIN ('),
            $this->universoIds($c, $f),
            self::f(') uni ON uni.id=g.usuario')
        );
        $fila = $this->filas($sql, $t, $p)[0] ?? [];
        return array_map('intval', $fila + ['cobros_real' => 0, 'dev_real' => 0, 'cobros_sim' => 0, 'dev_sim' => 0, 'n_cobros_real' => 0, 'clientes_con_gasto' => 0]);
    }

    /**
     * Distribución de clientes por segmento a la fecha de hoy, dentro del universo SIN el filtro de segmento (cada cifra es lo que
     * mostraría el listado al elegir ese segmento con los demás filtros). @return array<string,int>
     */
    public function distribucionSegmentos(array $c, array $f = []): array
    {
        $f['segmento'] = null;
        [$sql, $t, $p] = self::unir(
            self::f('SELECT '), $this->segmentoSql($c), self::f(' segmento,COUNT(*) n'),
            $this->universo($c, $f, ['v' => true]), self::f(' GROUP BY segmento')
        );
        return array_map('intval', array_column($this->filas($sql, $t, $p), 'n', 'segmento'));
    }

    /** Prestaciones completadas por servicio en el periodo (máx. $limite) de los clientes del universo. */
    public function serviciosMasUsados(array $c, int $limite = 8, array $f = []): array
    {
        $limite = max(1, min(50, $limite));
        [$sql, $t, $p] = self::unir(
            self::f("SELECT cs.servicioId servicio_id,COALESCE(MAX(s.nombre),MAX(cs.nombre_servicio),'Servicio eliminado') nombre,COUNT(*) prestaciones,COUNT(DISTINCT ci.usuarioId) clientes "
                . "FROM citasservicios cs INNER JOIN citas ci ON ci.id=cs.citaId INNER JOIN ("),
            $this->universoIds($c, $f),
            self::f(") uni ON uni.id=ci.usuarioId LEFT JOIN servicios s ON s.id=cs.servicioId WHERE ci.estado='completada' AND ci.fecha BETWEEN ? AND ? GROUP BY cs.servicioId ORDER BY prestaciones DESC,nombre ASC LIMIT ?", 'ssi', [$c['desde'], $c['hasta'], $limite])
        );
        return $this->filas($sql, $t, $p);
    }

    /**
     * Cohortes por mes de PRIMERA visita completada (<= hoy): clientes y cuántos tuvieron otra visita completada
     * en un día posterior dentro de $ventana días (sin contar nada posterior a hoy). Solo clientes del universo.
     */
    public function cohortes(array $c, int $ventana, int $maxMeses = 12, array $f = []): array
    {
        $ventana = max(1, $ventana); $maxMeses = max(1, min(60, $maxMeses));
        [$sql, $t, $p] = self::unir(
            self::f("SELECT DATE_FORMAT(x.primera,'%Y-%m') cohorte,COUNT(*) clientes,"
                . "COALESCE(SUM(EXISTS(SELECT 1 FROM citas c2 WHERE c2.usuarioId=x.usuarioId AND c2.estado='completada' AND c2.fecha>x.primera AND c2.fecha<=LEAST(DATE_ADD(x.primera,INTERVAL ? DAY),CAST(? AS DATE)))),0) repiten "
                . "FROM (SELECT usuarioId,MIN(fecha) primera FROM citas WHERE estado='completada' AND fecha<=? GROUP BY usuarioId HAVING MIN(fecha) BETWEEN ? AND ?) x "
                . "INNER JOIN (", 'issss', [$ventana, $c['hoy'], $c['hoy'], $c['desde'], $c['hasta']]),
            $this->universoIds($c, $f),
            self::f(') uni ON uni.id=x.usuarioId GROUP BY cohorte ORDER BY cohorte DESC LIMIT ?', 'i', [$maxMeses])
        );
        return $this->filas($sql, $t, $p);
    }

    /** @return array{puntos:int,canjes_pendientes:int,canjes_entregados:int,clientes_con_puntos:int} */
    public function fidelidadAgregada(array $c = [], array $f = []): array
    {
        if (!$c) {
            $r = $this->filas("SELECT COALESCE(SUM(fm.puntos),0) puntos,COALESCE(SUM(fm.tipo='canje' AND fm.canje_estado='pendiente'),0) pendientes,COALESCE(SUM(fm.tipo='canje' AND fm.canje_estado='entregado'),0) entregados "
                . "FROM fidelidad_movimientos fm INNER JOIN usuarios u ON u.id=fm.usuario_id")[0] ?? [];
            $con = $this->filas('SELECT COUNT(*) n FROM (SELECT usuario_id FROM fidelidad_movimientos GROUP BY usuario_id HAVING SUM(puntos)>0) x')[0]['n'] ?? 0;
        } else {
            [$sql, $t, $p] = self::unir(
                self::f("SELECT COALESCE(SUM(fm.puntos),0) puntos,COALESCE(SUM(fm.tipo='canje' AND fm.canje_estado='pendiente'),0) pendientes,COALESCE(SUM(fm.tipo='canje' AND fm.canje_estado='entregado'),0) entregados FROM fidelidad_movimientos fm INNER JOIN ("),
                $this->universoIds($c, $f), self::f(') uni ON uni.id=fm.usuario_id')
            );
            $r = $this->filas($sql, $t, $p)[0] ?? [];
            [$sql, $t, $p] = self::unir(
                self::f('SELECT COUNT(*) n FROM (SELECT fm.usuario_id FROM fidelidad_movimientos fm INNER JOIN ('),
                $this->universoIds($c, $f), self::f(') uni ON uni.id=fm.usuario_id GROUP BY fm.usuario_id HAVING SUM(fm.puntos)>0) x')
            );
            $con = $this->filas($sql, $t, $p)[0]['n'] ?? 0;
        }
        return ['puntos' => (int)($r['puntos'] ?? 0), 'canjes_pendientes' => (int)($r['pendientes'] ?? 0), 'canjes_entregados' => (int)($r['entregados'] ?? 0), 'clientes_con_puntos' => (int)$con];
    }


    /**
     * Población RFM: clientes con >= 1 visita completada en la ventana. Recencia (días), frecuencia (visitas en la ventana)
     * y gasto neto real (centavos) en la ventana. @return array<int,array{id:int,recencia:int,frecuencia:int,gasto:int}>
     */
    public function poblacionRfm(string $hoy, int $ventanaDias, string $vinculo = 'todos'): array
    {
        $ini = (new \DateTimeImmutable($hoy))->modify('-' . max(1, $ventanaDias) . ' days')->format('Y-m-d');
        [$sql, $t, $p] = self::unir(
            self::f("SELECT x.usuarioId id,DATEDIFF(?,x.ultima) recencia,x.f frecuencia,CAST(COALESCE(g.cobros_real,0)-COALESCE(g.dev_real,0) AS SIGNED) gasto "
                . "FROM (SELECT usuarioId,MAX(fecha) ultima,COUNT(*) f FROM citas WHERE estado='completada' AND fecha BETWEEN ? AND ? GROUP BY usuarioId) x "
                . "INNER JOIN usuarios u ON u.id=x.usuarioId LEFT JOIN usuario_accesos a ON a.usuario_id=u.id LEFT JOIN clientes cl ON cl.usuario_id=u.id LEFT JOIN (", 'sss', [$hoy, $ini, $hoy]),
            $this->gastoPorUsuario($ini, $hoy),
            self::f(') g ON g.usuario=x.usuarioId WHERE ' . self::CLIENTE_SQL . ($vinculo === 'externo' ? " AND COALESCE(a.rol,IF(u.admin=1,'propietario','cliente'))='cliente'" : ($vinculo === 'equipo' ? " AND COALESCE(a.rol,IF(u.admin=1,'propietario','cliente'))<>'cliente'" : '')))
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

    /** Historial de decisiones de consentimiento de la persona (sin IP ni huellas). @return array<int,array<string,mixed>> */
    public function historialConsentimiento(int $id, int $limite = 20): array
    {
        return (new ConsentimientoRepository($this->db, $this->propositoConsentimiento))->historial($id, $limite);
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

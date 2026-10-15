<?php
namespace Controllers;

use Classes\Exportacion\Exportador;
use MVC\Router;
use Repositories\CajaRepository;
use Repositories\OperacionRepository;
use Services\AuditoriaExportacion;
use Services\AuditoriaExportacionCaja;
use Services\CobroCalculo;
use Services\CobroCitaService;
use Services\CobroRechazado;
use Services\ComprobanteService;

/**
 * Caja operativa: lista de citas por cobrar/pagadas, cobro desde la cita, verificación de transferencias, devoluciones, comprobante
 * interno y exportación CSV/XLSX. Permiso único: `finanzas.gestionar` (propietario, gerente, finanzas). Recepción y profesional NO.
 *
 * Navegación: SOLO destino_portal() / campos_retorno() / destino_volver() / responder_error_operacion(); sin `Referer`.
 * Un fallo (403/404/409/422/500) se muestra dentro del layout con «Volver» al mismo portal; nunca al del cliente.
 */
final class CajaController
{
    /** Sustituible por la auditoría general de la migración 026 (ver Services\AuditoriaExportacion). */
    private static ?AuditoriaExportacion $auditoria = null;

    public static function setAuditoria(?AuditoriaExportacion $a): void { self::$auditoria = $a; }

    private static function auditoria(): AuditoriaExportacion
    {
        return self::$auditoria ??= new AuditoriaExportacionCaja();
    }

    // ------------------------------------------------------------------------------------------------ acceso

    /** @param ?string $accion si se indica y falta el permiso, se audita como exportación denegada */
    private static function exigir(): void
    {
        iniciar_sesion_segura();
        isAuth();
        if (!puede('finanzas.gestionar')) {
            responder_error_operacion(403, 'Tu cuenta no tiene permiso para operar la caja. Consulta con la administración del salón si necesitas acceso.');
            detener_ejecucion(403);
        }
    }

    private static function actor(): int { return (int)($_SESSION['id'] ?? 0); }

    private static function flash(string $tipo, string $mensaje): void
    {
        $_SESSION['caja_flash'][$tipo][] = $mensaje;
    }

    private static function tomarFlash(): array
    {
        $f = is_array($_SESSION['caja_flash'] ?? null) ? $_SESSION['caja_flash'] : [];
        unset($_SESSION['caja_flash']);
        return $f;
    }

    /** Falla de una operación: página dentro del layout con «Volver» al mismo portal. */
    private static function fallo(int $http, string $mensaje, string $porDefecto = 'caja', array $params = []): void
    {
        responder_error_operacion($http, $mensaje, $porDefecto, $params);
        detener_ejecucion($http);
    }

    private static function falloInterno(\Throwable $e, string $porDefecto, array $params = []): void
    {
        error_log('[Caja] ' . get_class($e) . ' code=' . (int)$e->getCode() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
        self::fallo(500, 'No se pudo completar la operación y no se registró ningún movimiento. Revisa el estado de la cita antes de repetirla.', $porDefecto, $params);
    }

    private static function idGet(): ?int
    {
        $id = $_GET['id'] ?? null;
        return is_string($id) && preg_match('/^[1-9]\d{0,9}$/D', $id) ? (int)$id : null;
    }

    /** Período y filtros de la lista que viajan en los formularios y enlaces para «mantener al volver». */
    private static function contexto(array $fuente): array
    {
        $c = [];
        foreach (['desde', 'hasta'] as $k) {
            $v = $fuente[$k] ?? null;
            if (is_string($v) && ($d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v)) && $d->format('Y-m-d') === $v) { $c[$k] = $v; }
        }
        return $c;
    }

    // ------------------------------------------------------------------------------------------------ lista

    public static function index(Router $router): void
    {
        self::exigir();
        [$filtros, $errores] = CajaRepository::filtros($_GET);
        $repo = new CajaRepository(new OperacionRepository());
        $alertas = self::tomarFlash();
        if ($errores) {
            http_response_code(422);
            foreach ($errores as $e) { $alertas['error'][] = $e; }
        }
        try {
            $totales = $repo->totales($filtros);
            $filas = $repo->pagina($filtros);
            $porVerificar = (new OperacionRepository())->consulta(
                "SELECT p.id pago_id,COALESCE(p.cita_id,p.cita_referencia_id) cita_id,p.monto,p.referencia_transferencia,p.created_at,TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,''))) cliente,TRIM(CONCAT(COALESCE(r.nombre,''),' ',COALESCE(r.apellido,''))) registrado_por_nombre,p.registrado_por"
                . " FROM pagos p LEFT JOIN citas c ON c.id=COALESCE(p.cita_id,p.cita_referencia_id) LEFT JOIN usuarios u ON u.id=c.usuarioId LEFT JOIN usuarios r ON r.id=p.registrado_por"
                . " WHERE p.proveedor='manual' AND p.estado='pendiente' AND p.estado_verificacion='pendiente_verificacion' ORDER BY p.id LIMIT 50"
            );
            $profesionales = $repo->profesionales();
        } catch (\AppTerminationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            self::falloInterno($e, 'caja');
            return;
        }
        $router->render('caja/index', [
            'filtros' => $filtros, 'totales' => $totales, 'filas' => $filas, 'porVerificar' => $porVerificar, 'profesionales' => $profesionales, 'alertas' => $alertas,
            'paginas' => max(1, (int)ceil($totales['citas'] / CajaRepository::POR_PAGINA)), 'actorId' => self::actor(),
        ]);
    }

    // ------------------------------------------------------------------------------------------------ cita

    public static function cita(Router $router): void
    {
        self::exigir();
        $id = self::idGet();
        if ($id === null) { self::fallo(422, 'Indica una cita válida para cobrar.'); return; }
        $ctx = self::contexto($_GET);
        try {
            $svc = new CobroCitaService();
            $detalle = $svc->detalle($id);
            if ($detalle === null) { self::fallo(404, 'La cita #' . $id . ' no existe.', 'caja', $ctx); return; }
            $comprobantes = (new ComprobanteService())->deCita($id);
            $eventos = (new OperacionRepository())->consulta("SELECT e.tipo,e.actor_rol,e.registrado_en,TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,''))) actor FROM cobros_eventos e LEFT JOIN usuarios u ON u.id=e.actor_id WHERE e.cita_id=? ORDER BY e.id DESC LIMIT 30", 'i', [$id]);
        } catch (\AppTerminationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            self::falloInterno($e, 'caja', $ctx);
            return;
        }
        $router->render('caja/cita', ['d' => $detalle, 'comprobantes' => $comprobantes, 'eventos' => $eventos, 'ctx' => $ctx, 'alertas' => self::tomarFlash(), 'actorId' => self::actor(),
            'esPropietario' => rol_actual() === 'propietario']);
    }

    // ------------------------------------------------------------------------------------------------ mutaciones

    /** Ejecuta una mutación financiera, traduce los fallos y vuelve al lugar de origen. */
    private static function mutar(callable $operacion, string $volverPorDefecto, array $params, callable $mensajeExito): void
    {
        self::exigir();
        exigir_csrf();
        try {
            $r = $operacion();
        } catch (CobroRechazado $e) {
            self::fallo($e->http(), $e->getMessage(), $volverPorDefecto, $params);
            return;
        } catch (\AppTerminationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            self::falloInterno($e, $volverPorDefecto, $params);
            return;
        }
        self::flash('exito', $mensajeExito($r));
        $dest = destino_volver($volverPorDefecto, null, $params + (isset($r['cita_id']) ? ['id' => $r['cita_id']] : []));
        http_response_code(303);
        redirigir($dest, 303);
    }

    public static function cobrar(): void
    {
        $citaId = is_string($_POST['cita_id'] ?? null) && preg_match('/^[1-9]\d{0,9}$/D', $_POST['cita_id']) ? (int)$_POST['cita_id'] : 0;
        $params = ['id' => $citaId];
        self::mutar(function () {
            return (new CobroCitaService())->cobrar(self::actor(), [
                'cita_id' => $_POST['cita_id'] ?? null, 'metodo' => $_POST['metodo'] ?? null, 'monto' => $_POST['monto'] ?? null,
                'recibido' => $_POST['recibido'] ?? null, 'referencia' => $_POST['referencia'] ?? null, 'idem_key' => $_POST['idem_key'] ?? null,
            ]);
        }, 'caja_cita', $params, static function (array $r): string {
            $m = $r['repetida'] ? 'Este cobro ya estaba registrado (no se duplicó). ' : '';
            if ($r['metodo'] === 'efectivo') {
                $m .= 'Efectivo registrado: $' . CobroCalculo::formato($r['monto_centavos']) . ' aplicados a la cita.';
                if (($r['cambio_centavos'] ?? 0) > 0) { $m .= ' Entregue cambio: $' . CobroCalculo::formato($r['cambio_centavos']) . ' (el cambio no es ingreso).'; }
                return $m;
            }
            return $m . 'Transferencia de $' . CobroCalculo::formato($r['monto_centavos']) . ' declarada. Queda por verificar: aún no es dinero confirmado ni entra a caja.';
        });
    }

    public static function verificar(): void { self::resolver(true); }
    public static function rechazar(): void { self::resolver(false); }

    private static function resolver(bool $confirmar): void
    {
        $pago = $_POST['pago_id'] ?? null;
        $cita = is_string($_POST['cita_id'] ?? null) && preg_match('/^[1-9]\d{0,9}$/D', $_POST['cita_id']) ? (int)$_POST['cita_id'] : null;
        self::mutar(function () use ($confirmar, $pago, $cita) {
            $svc = new CobroCitaService();
            $datos = ['pago_id' => $pago, 'cita_id' => $cita, 'nota' => $_POST['nota'] ?? null, 'idem_key' => $_POST['idem_key'] ?? null];
            return $confirmar ? $svc->verificar(self::actor(), $datos) : $svc->rechazar(self::actor(), $datos);
        }, $cita ? 'caja_cita' : 'caja', $cita ? ['id' => $cita] : [], static function (array $r) use ($confirmar): string {
            $m = $r['repetida'] ? 'Esta decisión ya estaba registrada (no se duplicó). ' : '';
            return $m . ($confirmar ? 'Transferencia verificada: el dinero ya consta en caja y la cita quedó en «' . str_replace('_', ' ', $r['estado_pago']) . '».' : 'Transferencia rechazada: no entra a caja ni genera comisión.');
        });
    }

    public static function devolver(): void
    {
        $cita = is_string($_POST['cita_id'] ?? null) && preg_match('/^[1-9]\d{0,9}$/D', $_POST['cita_id']) ? (int)$_POST['cita_id'] : null;
        self::mutar(function () use ($cita) {
            return (new CobroCitaService())->devolver(self::actor(), ['pago_id' => $_POST['pago_id'] ?? null, 'cita_id' => $cita, 'monto' => $_POST['monto'] ?? null,
                'motivo' => $_POST['motivo'] ?? null, 'idem_key' => $_POST['idem_key'] ?? null]);
        }, $cita ? 'caja_cita' : 'caja', $cita ? ['id' => $cita] : [], static fn(array $r): string => ($r['repetida'] ? 'Esta devolución ya estaba registrada (no se duplicó). ' : '') . 'Devolución de $' . CobroCalculo::formato($r['monto_centavos']) . ' registrada. Entregue el dinero a la persona: el sistema solo lo anota en caja.');
    }

    // ------------------------------------------------------------------------------------------------ comprobante

    public static function comprobante(Router $router): void
    {
        self::exigir();
        $id = self::idGet();
        if ($id === null) { self::fallo(422, 'Indica la cita cuyo comprobante quieres ver.'); return; }
        $ctx = self::contexto($_GET);
        try {
            $svc = new ComprobanteService();
            $lista = $svc->deCita($id);
            $cita = (new OperacionRepository())->consulta('SELECT id FROM citas WHERE id=?', 'i', [$id]);
            if (!$cita && !$lista) { self::fallo(404, 'La cita #' . $id . ' no existe.', 'caja', $ctx); return; }
            if (!$lista) { self::fallo(404, 'La cita #' . $id . ' aún no tiene cobros confirmados, por eso todavía no hay comprobante. Una transferencia declarada genera comprobante cuando se verifica.', 'caja_cita', ['id' => $id] + $ctx); return; }
            $elegido = $lista[0]['id'];
            if (isset($_GET['comprobante'])) {
                $n = is_string($_GET['comprobante']) && preg_match('/^[1-9]\d{0,18}$/D', $_GET['comprobante']) ? (int)$_GET['comprobante'] : 0;
                if (!in_array($n, array_map('intval', array_column($lista, 'id')), true)) { self::fallo(404, 'Ese comprobante no pertenece a la cita #' . $id . '.', 'caja_cita', ['id' => $id] + $ctx); return; }
                $elegido = $n;
            }
            $c = $svc->obtener((int)$elegido);
        } catch (\AppTerminationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            self::falloInterno($e, 'caja', $ctx);
            return;
        }
        if (!$c || !$c['integro']) { self::fallo(500, 'El comprobante no superó la comprobación de integridad. Avisa a la administración.', 'caja_cita', ['id' => $id] + $ctx); return; }
        $descargar = ($_GET['descargar'] ?? '') === '1';
        $volver = $descargar ? '' : destino_portal('caja_cita', ['id' => $id] + $ctx);
        ob_start();
        include __DIR__ . '/../views/caja/comprobante.php';
        $html = (string)ob_get_clean();
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            if ($descargar) { header('Content-Disposition: attachment; filename="comprobante-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$c['codigo']) . '.html"'); }
        }
        echo $html;
    }

    // ------------------------------------------------------------------------------------------------ exportación

    public static function exportar(): void
    {
        iniciar_sesion_segura();
        isAuth();
        $actor = self::actor();
        $rol = rol_actual();
        $formato = Exportador::formatoDe($_GET['formato'] ?? null);
        [$filtros, $errores] = CajaRepository::filtros($_GET);
        $auditar = static function (array $e) use ($actor, $rol, $formato, $filtros): void {
            self::auditoria()->registrar($e + ['origen' => 'caja.citas', 'actor_id' => $actor ?: null, 'actor_rol' => $rol, 'formato' => $formato ?? 'invalido', 'filtros' => $filtros]);
        };
        // 1) autorizar en servidor (se audita el intento denegado)
        if (!puede('finanzas.gestionar')) {
            try { $auditar(['resultado' => 'denegada', 'motivo' => 'sin_permiso']); } catch (\Throwable $e) { error_log('[Caja] auditoría de exportación no disponible'); }
            self::fallo(403, 'Tu cuenta no tiene permiso para exportar la caja.');
            return;
        }
        if ($formato === null) { self::fallo(422, 'Formato de exportación no válido: usa csv o xlsx.', 'caja'); return; }
        if ($errores) { self::fallo(422, 'No se pudo exportar: ' . implode(' ', $errores), 'caja'); return; }
        $columnas = [
            ['clave' => 'cita', 'titulo' => 'Cita', 'tipo' => 'entero'], ['clave' => 'fecha', 'titulo' => 'Fecha', 'tipo' => 'fecha'], ['clave' => 'hora', 'titulo' => 'Hora', 'tipo' => 'texto'],
            ['clave' => 'cliente', 'titulo' => 'Cliente', 'tipo' => 'texto'], ['clave' => 'profesional', 'titulo' => 'Profesional', 'tipo' => 'texto'],
            ['clave' => 'atencion', 'titulo' => 'Atención', 'tipo' => 'texto'], ['clave' => 'estado_pago', 'titulo' => 'Estado de pago', 'tipo' => 'texto'], ['clave' => 'metodos', 'titulo' => 'Métodos', 'tipo' => 'texto'],
            ['clave' => 'total', 'titulo' => 'Total', 'tipo' => 'dinero'], ['clave' => 'cobrado', 'titulo' => 'Cobrado confirmado', 'tipo' => 'dinero'], ['clave' => 'devuelto', 'titulo' => 'Devuelto', 'tipo' => 'dinero'],
            ['clave' => 'pendiente', 'titulo' => 'Por verificar', 'tipo' => 'dinero'], ['clave' => 'por_cobrar', 'titulo' => 'Por cobrar', 'tipo' => 'dinero'], ['clave' => 'anticipo', 'titulo' => 'Anticipo sin resolver', 'tipo' => 'dinero'],
        ];
        $db = (new OperacionRepository())->db;
        $repo = new CajaRepository(new OperacionRepository($db));
        try {
            // 2) generar: totales y filas salen de la MISMA instantánea consistente del mismo universo filtrado
            $db->query('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            try {
                $totales = $repo->totales($filtros);
                $filas = (static function () use ($repo, $filtros): \Generator {
                    foreach ($repo->todas($filtros) as $f) {
                        yield ['cita' => $f['id'], 'fecha' => $f['fecha'], 'hora' => $f['hora'], 'cliente' => $f['cliente'], 'profesional' => $f['profesional'], 'atencion' => str_replace('_', ' ', $f['cita_estado']),
                            'estado_pago' => str_replace('_', ' ', $f['estado_pago']), 'metodos' => implode(', ', $f['metodos']), 'total' => $f['total'], 'cobrado' => $f['cobrado'], 'devuelto' => $f['devuelto'],
                            'pendiente' => $f['pendiente'], 'por_cobrar' => $f['por_cobrar'], 'anticipo' => $f['anticipo_sin_resolver']];
                    }
                })();
                $r = Exportador::generar($formato, 'caja-citas-' . $filtros['desde'] . '_a_' . $filtros['hasta'], $columnas, $filas,
                    ['total' => $totales['total'], 'cobrado' => $totales['cobrado'], 'devuelto' => $totales['devuelto'], 'pendiente' => $totales['pendiente'], 'por_cobrar' => $totales['por_cobrar'], 'anticipo' => $totales['anticipo_sin_resolver']],
                    ['hoja' => 'Caja']);
            } finally {
                $db->query('COMMIT');
            }
            // 3) auditar: si no se puede dejar constancia, el archivo NO se entrega
            $auditar(['resultado' => 'exitosa', 'filas' => $r['filas'], 'columnas' => count($r['columnas']), 'truncado' => $r['truncado'], 'bytes' => $r['bytes'], 'sha256' => $r['sha256']]);
        } catch (\Throwable $e) {
            error_log('[Caja] exportación fallida: ' . get_class($e));
            try { $auditar(['resultado' => 'fallida', 'motivo' => 'error_interno']); } catch (\Throwable) { /* ya se informa abajo */ }
            self::fallo(500, 'No se pudo generar la exportación y no se entregó ningún archivo. Intenta de nuevo; si persiste, avisa a la administración.', 'caja');
            return;
        }
        // 4) entregar
        Exportador::entregar($r);
        detener_ejecucion(200);
    }
}

<?php
namespace Services;

use Classes\Exportacion\Exportador;
use Repositories\ExportacionAuditoriaRepository;

/**
 * Auditoría de las exportaciones tabulares que no son del CRM (reportes, caja, comprobantes…). Contrato para quien exporta:
 *
 *   1. autorizar en servidor; si no hay permiso:
 *        $aud->registrar('caja.pagos', $formatoPedido, ExportacionAuditoriaService::actorDeSesion(), null, ['resultado' => 'denegada', 'motivo' => 'sin_permiso']);  → 403
 *   2. $r = Exportador::generar(...);
 *   3. $aud->registrar('caja.pagos', $r['formato'], $actor, $filtrosNormalizados, $r + ['resultado' => 'exitosa']);   // si lanza, NO se entrega el archivo (503)
 *   4. Exportador::entregar($r);
 *
 * Qué guarda: origen, actor (id y rol del servidor), formato, resultado, motivo, filtros normalizados SIN datos personales,
 * filas, columnas, truncado, bytes, SHA-256 y huella de la IP. Qué NO guarda: ni una celda exportada, ni el texto de búsqueda
 * en claro, ni tokens. Los intentos denegados repetidos del mismo actor se agrupan en una fila por ventana.
 */
final class ExportacionAuditoriaService
{
    public const RESULTADOS = ['exitosa', 'denegada', 'fallida'];
    public const VENTANA_AGRUPAR_DENEGADAS_MIN = 10;
    /** Filtros cuyo valor es texto libre del usuario y puede contener datos personales: solo se guarda longitud y huella. */
    private const FILTROS_PERSONALES = ['q', 'busqueda', 'buscar', 'cliente', 'cliente_nombre', 'nombre', 'apellido', 'email', 'correo', 'telefono', 'celular', 'direccion', 'referencia'];
    /** Un filtro con alguno de estos fragmentos en su nombre no se guarda NUNCA (ni su huella). */
    private const FILTROS_SECRETOS = '/token|csrf|pass|secret|clave|key|auth|cookie|session|sesion|firma|otp/i';

    public function __construct(private ExportacionAuditoriaRepository $repo) {}

    /** Quién actúa: id de sesión y rol efectivo resueltos en servidor (nunca los que declare el navegador). @return array{id:?int,rol:?string} */
    public static function actorDeSesion(): array
    {
        $id = (int)($_SESSION['id'] ?? 0);
        $rol = null;
        try { $rol = (new AuthorizationService())->contexto($id)['rol'] ?? null; } catch (\Throwable) { /* el rol queda sin dato; el id basta */ }
        return ['id' => $id > 0 ? $id : null, 'rol' => $rol];
    }

    /**
     * Filtros seguros para guardar: solo escalares (enteros, booleanos, cadenas cortas); el texto libre que puede ser personal
     * se sustituye por <clave>_presente / _longitud / _hmac; las claves que parecen secretos se descartan.
     * @param array<string,mixed> $filtros
     * @return array<string,mixed>
     */
    public static function filtrosSeguros(array $filtros): array
    {
        $out = [];
        foreach ($filtros as $k => $v) {
            $k = (string)$k;
            if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/i', $k) || preg_match(self::FILTROS_SECRETOS, $k)) { continue; }
            $k = strtolower($k);
            if (in_array($k, self::FILTROS_PERSONALES, true)) {
                $t = is_scalar($v) ? (string)$v : '';
                $out[$k . '_presente'] = $t !== '';
                $out[$k . '_longitud'] = mb_strlen($t);
                $out[$k . '_hmac'] = $t !== '' ? substr(AuditoriaHuella::texto($k . '|' . mb_strtolower($t)), 0, 32) : null;
                continue;
            }
            if (is_bool($v) || is_int($v) || $v === null) { $out[$k] = $v; }
            elseif (is_float($v)) { $out[$k] = round($v, 4); }
            elseif (is_string($v)) { $out[$k] = mb_substr(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v) ?? '', 0, 80); }
        }
        ksort($out);
        return $out;
    }

    /**
     * Registra un intento de exportación. Lanza PersistenceException si no se pudo escribir: el llamador decide
     * (en un éxito, NO se entrega el archivo; en un intento denegado/fallido la respuesta 403/422 no cambia).
     *
     * @param string $origen   «modulo.bloque» en minúsculas (a-z 0-9 . _ -), máx. 40 (p. ej. reportes.reservas, caja.pagos)
     * @param string $formato  csv | xlsx (otro valor se guarda como «invalido»)
     * @param array{id:?int,rol:?string} $actor
     * @param array<string,mixed>|null $filtros filtros normalizados de la petición (se filtran con filtrosSeguros)
     * @param array{resultado:string,motivo?:?string,filas?:?int,columnas?:?array,truncado?:?bool,bytes?:?int,sha256?:?string} $resultado
     *        Se acepta tal cual el arreglo de Exportador::generar() más 'resultado' (y 'motivo').
     * @return int id de la fila (0 si una denegación idéntica reciente ya estaba registrada)
     */
    public function registrar(string $origen, string $formato, array $actor, ?array $filtros, array $resultado): int
    {
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,39}$/', $origen)) { throw new \InvalidArgumentException('Origen de exportación no válido.'); }
        $res = (string)($resultado['resultado'] ?? '');
        if (!in_array($res, self::RESULTADOS, true)) { throw new \InvalidArgumentException('Resultado de auditoría no válido.'); }
        $formato = isset(Exportador::FORMATOS[$formato]) ? $formato : 'invalido';
        $motivo = isset($resultado['motivo']) && $resultado['motivo'] !== null ? substr((string)preg_replace('/[^a-z0-9_.-]/i', '_', (string)$resultado['motivo']), 0, 60) : null;
        if ($res === 'denegada') {
            $actorId = isset($actor['id']) ? (int)$actor['id'] : null;
            $candado = 'exp_aud_den:' . substr(hash('sha256', ($actorId ?? 'anon') . '|' . $origen . '|' . ($motivo ?? '')), 0, 32);
            $obtenido = $this->repo->tomarCandado($candado);
            try {
                if ($obtenido && $this->repo->hayDenegadaReciente($actorId, $origen, $motivo, self::VENTANA_AGRUPAR_DENEGADAS_MIN)) { return 0; }
                return $this->registrarFila($origen, $formato, $actor, $filtros, $res, $motivo, $resultado);
            } finally {
                if ($obtenido) { $this->repo->soltarCandado($candado); }
            }
        }
        return $this->registrarFila($origen, $formato, $actor, $filtros, $res, $motivo, $resultado);
    }

    private function registrarFila(string $origen, string $formato, array $actor, ?array $filtros, string $res, ?string $motivo, array $r): int
    {
        $seguros = $filtros !== null ? self::filtrosSeguros($filtros) : null;
        $columnas = isset($r['columnas']) && is_array($r['columnas']) ? array_values(array_map('strval', $r['columnas'])) : null;
        return $this->repo->registrar([
            'origen' => $origen, 'actor_usuario_id' => $actor['id'] ?? null, 'actor_rol' => isset($actor['rol']) ? substr((string)$actor['rol'], 0, 20) : null,
            'formato' => $formato, 'resultado' => $res, 'motivo' => $motivo,
            'filtros_json' => $seguros !== null ? json_encode($seguros, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : null,
            'filtros_hash' => $filtros !== null ? AuditoriaHuella::texto('filtros|' . json_encode(self::canonico($filtros), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)) : null,
            'filas' => isset($r['filas']) ? max(0, (int)$r['filas']) : null,
            'columnas_n' => $columnas !== null ? count($columnas) : null,
            'columnas_lista' => $columnas !== null ? json_encode($columnas, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
            'truncado' => !empty($r['truncado']) ? 1 : 0,
            'bytes_entregados' => isset($r['bytes']) ? max(0, (int)$r['bytes']) : null,
            'contenido_sha256' => isset($r['sha256']) && is_string($r['sha256']) && preg_match('/^[a-f0-9]{64}$/', $r['sha256']) ? $r['sha256'] : null,
            'ip_hash' => AuditoriaHuella::ip(AuditoriaHuella::ipDeSolicitud()),
        ]);
    }

    private static function canonico(array $filtros): array
    {
        $f = array_filter($filtros, static fn($v) => $v === null || is_scalar($v));
        ksort($f);
        return $f;
    }
}

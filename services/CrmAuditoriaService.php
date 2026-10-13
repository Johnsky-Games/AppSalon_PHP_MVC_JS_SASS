<?php
namespace Services;

use Repositories\ConsentimientoRepository;
use Repositories\CrmAuditoriaRepository;

/**
 * Auditoría de exportaciones del CRM: arma la fila (sin datos personales ni contenido exportado) y la consulta.
 *
 * Qué guarda: quién (id y rol efectivo), cuándo (UTC, lo fija la base), formato y tipo, filtros normalizados, cuántas filas y
 * columnas y cuáles, la versión de consentimiento vigente y la política aplicada, huella de la IP truncada y SHA-256 del archivo.
 * Qué NO guarda: ni una sola celda exportada, ni el texto de búsqueda (solo su longitud y un HMAC), ni correos o teléfonos.
 */
final class CrmAuditoriaService
{
    /** Minutos durante los cuales las denegaciones idénticas del mismo actor se agrupan en una fila. */
    public const VENTANA_AGRUPAR_DENEGADAS_MIN = 10;
    public const RESULTADOS = ['exitosa' => 'Exitosa', 'denegada' => 'Denegada', 'fallida' => 'Fallida'];
    public const TIPOS = ['clientes' => 'Clientes (operativa)', 'comercial' => 'Lista comercial', 'campana' => 'Campaña (Clientes y Fidelidad)', 'invalido' => 'Tipo no válido'];
    public const POR_PAGINA = [25, 50, 100];
    public const TIMEZONE = 'America/Guayaquil';
    /** Filtros de la exportación que no son datos personales y se guardan tal cual. */
    private const FILTROS_LITERALES = ['desde', 'hasta', 'alta_desde', 'alta_hasta', 'profesional_id', 'servicio_id', 'segmento', 'membresia', 'consentimiento', 'dias_activo', 'orden', 'dir'];

    public function __construct(private CrmAuditoriaRepository $repo, private ?ConsentimientoRepository $consentimientos = null) {}

    /**
     * Filtros normalizados SIN datos personales: lo escrito en la búsqueda `q` se sustituye por su longitud y un HMAC.
     * @param array<string,mixed> $filtros salida de CrmService::normalizarFiltros()['filtros']
     * @return array<string,mixed>
     */
    public static function filtrosParaAuditoria(array $filtros): array
    {
        $out = [];
        foreach (self::FILTROS_LITERALES as $k) {
            $v = $filtros[$k] ?? null;
            $out[$k] = is_int($v) || is_string($v) ? $v : null;
        }
        $q = is_string($filtros['q'] ?? null) ? $filtros['q'] : '';
        $out['q_presente'] = $q !== '';
        $out['q_longitud'] = mb_strlen($q);
        $out['q_hmac'] = $q !== '' ? substr(AuditoriaHuella::texto('q|' . mb_strtolower($q)), 0, 32) : null;
        ksort($out);
        return $out;
    }

    /**
     * Registra un intento de exportación. Lanza PersistenceException si no se pudo escribir: el llamador decide
     * (en un éxito, NO se entrega el archivo).
     *
     * @param array{id:?int,rol:?string} $actor
     * @param array{columnas:string[],filas:int,truncado:bool,contenido:string}|null $exportacion solo en éxito
     */
    public function registrar(string $tipo, string $resultado, ?string $motivo, array $actor, ?array $filtros, ?array $exportacion = null): int
    {
        if (!isset(self::RESULTADOS[$resultado])) { throw new \InvalidArgumentException('Resultado de auditoría no válido.'); }
        if (!isset(self::TIPOS[$tipo])) { $tipo = 'invalido'; }
        // Un mismo actor que repite la misma denegación dentro de la ventana deja UNA sola fila: la auditoría es de solo
        // anexado y no debe poder llenarse con intentos repetidos (la primera denegación sí queda registrada).
        if ($resultado === 'denegada' && $this->repo->hayDenegadaReciente(isset($actor['id']) ? (int)$actor['id'] : null, $tipo, $motivo, self::VENTANA_AGRUPAR_DENEGADAS_MIN)) {
            return 0;
        }
        $fil = $filtros !== null ? self::filtrosParaAuditoria($filtros) : null;
        $versionId = null; $politica = null;
        if ($resultado === 'exitosa') {
            // Versión de consentimiento VIGENTE en el momento de exportar y política aplicada a esta exportación.
            $versionId = (int)($this->consentimientos ?? new ConsentimientoRepository())->vigente()['id'];
            $politica = in_array($tipo, ['comercial', 'campana'], true) ? 'solo_version_vigente' : 'informativo';
        }
        $columnas = $exportacion['columnas'] ?? null;
        return $this->repo->registrar([
            'actor_usuario_id' => $actor['id'] ?? null, 'actor_rol' => $actor['rol'] ?? null,
            'formato' => 'csv', 'tipo' => $tipo, 'resultado' => $resultado, 'motivo' => $motivo,
            'filtros_json' => $fil !== null ? json_encode($fil, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : null,
            'filtros_hash' => $filtros !== null ? AuditoriaHuella::texto('filtros|' . json_encode(self::canonico($filtros), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) : null,
            'filas' => $exportacion['filas'] ?? null,
            'columnas_n' => $columnas !== null ? count($columnas) : null,
            'columnas_lista' => $columnas !== null ? json_encode(array_values($columnas), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
            'truncado' => !empty($exportacion['truncado']) ? 1 : 0,
            'consentimiento_version_id' => $versionId, 'consentimiento_politica' => $politica,
            'contenido_sha256' => isset($exportacion['contenido']) ? hash('sha256', $exportacion['contenido']) : null,
            'ip_hash' => AuditoriaHuella::ip(AuditoriaHuella::ipDeSolicitud()),
        ]);
    }

    private static function canonico(array $filtros): array
    {
        $f = array_filter($filtros, static fn($v) => $v === null || is_scalar($v));
        ksort($f);
        return $f;
    }

    // ---------------------------------------------------------------- consulta (solo lectura)

    /**
     * Valida los filtros de la vista de consulta. Nunca lanza por entrada del usuario.
     * @return array{filtros:array<string,mixed>,errores:string[]}
     */
    public function normalizarConsulta(array $q): array
    {
        $errores = [];
        $texto = static function (string $k) use ($q, &$errores): ?string {
            if (!array_key_exists($k, $q)) { return null; }
            if (!is_string($q[$k])) { $errores[] = "El parámetro «{$k}» no es válido."; return null; }
            $v = trim($q[$k]);
            return $v === '' ? null : $v;
        };
        $resultado = $texto('resultado');
        if ($resultado !== null && !isset(self::RESULTADOS[$resultado])) { $errores[] = 'El resultado no es una opción válida.'; $resultado = null; }
        $tipo = $texto('tipo');
        if ($tipo !== null && !isset(self::TIPOS[$tipo])) { $errores[] = 'El tipo de exportación no es una opción válida.'; $tipo = null; }
        $actor = $texto('actor_id');
        if ($actor !== null && !preg_match('/^[1-9]\d{0,9}$/', $actor)) { $errores[] = 'El identificador de usuario debe ser un número.'; $actor = null; }
        $zona = new \DateTimeZone(self::TIMEZONE);
        $fecha = static function (?string $v) use ($zona): ?\DateTimeImmutable {
            if ($v === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { return null; }
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v, $zona);
            return $d && $d->format('Y-m-d') === $v ? $d : null;
        };
        $desdeTxt = $texto('desde'); $hastaTxt = $texto('hasta');
        $desde = $fecha($desdeTxt); $hasta = $fecha($hastaTxt);
        if ($desdeTxt !== null && $desde === null) { $errores[] = 'La fecha inicial no es válida (use AAAA-MM-DD).'; }
        if ($hastaTxt !== null && $hasta === null) { $errores[] = 'La fecha final no es válida (use AAAA-MM-DD).'; }
        if ($desde && $hasta && $desde > $hasta) { $errores[] = 'La fecha inicial no puede ser posterior a la final.'; $desde = $hasta = null; }
        $utc = new \DateTimeZone('UTC');
        $pp = $texto('por_pagina');
        $porPagina = 25;
        if ($pp !== null) {
            if (ctype_digit($pp) && in_array((int)$pp, self::POR_PAGINA, true)) { $porPagina = (int)$pp; }
            else { $errores[] = 'El tamaño de página debe ser ' . implode(', ', self::POR_PAGINA) . '.'; }
        }
        $pg = $texto('pagina');
        $pagina = 1;
        if ($pg !== null) {
            if (ctype_digit($pg) && (int)$pg >= 1 && (int)$pg <= 1000000) { $pagina = (int)$pg; }
            else { $errores[] = 'La página debe ser un número positivo.'; }
        }
        return ['filtros' => [
            'resultado' => $resultado, 'tipo' => $tipo, 'actor_id' => $actor !== null ? (int)$actor : null,
            'desde' => $desde?->format('Y-m-d'), 'hasta' => $hasta?->format('Y-m-d'),
            // El día civil de Guayaquil se traduce al intervalo UTC equivalente [inicio, inicio del día siguiente).
            'desde_utc' => $desde?->setTimezone($utc)->format('Y-m-d H:i:s'),
            'hasta_utc' => $hasta?->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'),
            'por_pagina' => $porPagina, 'pagina' => $pagina,
        ], 'errores' => array_values(array_unique($errores))];
    }

    /** @return array{filas:array,total:int,pagina:int,paginas:int,por_pagina:int,primero:int,ultimo:int} */
    public function consultar(array $filtros): array
    {
        $total = $this->repo->contar($filtros);
        $paginas = max(1, (int)ceil($total / $filtros['por_pagina']));
        $pagina = min(max(1, (int)$filtros['pagina']), $paginas);
        $offset = ($pagina - 1) * $filtros['por_pagina'];
        $filas = $this->repo->listar($filtros, $filtros['por_pagina'], $offset);
        foreach ($filas as &$fila) {
            $fila['filtros'] = is_string($fila['filtros_json']) ? (json_decode($fila['filtros_json'], true) ?: []) : [];
            $fila['columnas'] = is_string($fila['columnas_lista']) ? (json_decode($fila['columnas_lista'], true) ?: []) : [];
        }
        unset($fila);
        return ['filas' => $filas, 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas, 'por_pagina' => $filtros['por_pagina'],
            'primero' => $total === 0 ? 0 : $offset + 1, 'ultimo' => min($total, $offset + $filtros['por_pagina'])];
    }
}

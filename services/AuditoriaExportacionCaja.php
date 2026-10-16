<?php
namespace Services;

use Repositories\OperacionRepository;

/**
 * Auditoría provisional de exportaciones de caja: solo anexado (`caja_exportaciones_auditoria`, migración 024).
 * No guarda contenido exportado, correos, teléfonos, nombres ni tokens. El texto de búsqueda de cliente se reduce a su longitud y a un
 * HMAC; la fecha se guarda en UTC. Misma forma que la tabla general de la 026 para poder sustituirla sin cambiar el controlador.
 */
final class AuditoriaExportacionCaja implements AuditoriaExportacion
{
    public function __construct(private ?OperacionRepository $repo = null)
    {
        $this->repo ??= new OperacionRepository();
    }

    public function registrar(array $e): void
    {
        $filtros = $e['filtros'] ?? [];
        if (isset($filtros['q']) && $filtros['q'] !== '') {
            $filtros['q_longitud'] = mb_strlen((string)$filtros['q']);
            $filtros['q_hmac'] = substr(hash_hmac('sha256', mb_strtolower((string)$filtros['q']), \Classes\Secretos::clave('auditoria-exportaciones')), 0, 16);
        }
        unset($filtros['q']);
        ksort($filtros);
        $json = json_encode($filtros, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $resultado = in_array($e['resultado'], ['exitosa', 'denegada', 'fallida'], true) ? $e['resultado'] : 'fallida';
        $this->repo->consulta(
            'INSERT INTO caja_exportaciones_auditoria (ocurrido_en,origen,actor_usuario_id,actor_rol,formato,resultado,motivo,filtros_json,filtros_hash,filas,columnas_n,truncado,bytes_entregados,contenido_sha256) VALUES (UTC_TIMESTAMP(),?,?,?,?,?,?,?,?,?,?,?,?,?)',
            'sissssssiiiis',
            [$e['origen'], $e['actor_id'] ?? null, $e['actor_rol'] ?? null, mb_substr((string)$e['formato'], 0, 10), $resultado, $e['motivo'] ?? null, $json, hash_hmac('sha256', $json, \Classes\Secretos::clave('auditoria-exportaciones')),
                $e['filas'] ?? null, $e['columnas'] ?? null, !empty($e['truncado']) ? 1 : 0, $e['bytes'] ?? null, $e['sha256'] ?? null]
        );
    }
}

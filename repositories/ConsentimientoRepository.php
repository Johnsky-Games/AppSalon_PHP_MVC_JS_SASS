<?php
namespace Repositories;

use Classes\Transaccion;
use Model\ActiveRecord;
use mysqli;

/**
 * Versionado del consentimiento comercial («fuente única» sigue siendo perfiles_usuario.marketing_email +
 * consentimiento_marketing_at; aquí se añade QUÉ TEXTO se aceptó y el historial append-only de decisiones).
 *
 * Reglas:
 *  - Una decisión guarda la versión del texto que la persona VIO (la que viajó en el formulario), no «la última».
 *  - Un consentimiento previo nunca se actualiza solo a una versión nueva: exige una reconfirmación explícita.
 *  - Retirar el consentimiento nunca falla por la versión: ante cualquier problema se registra con la vigente.
 *  - perfiles_usuario.consentimiento_marketing_version_id NULL con consentimiento válido = «versión 1, histórica».
 *
 * Todas las escrituras de decisión usan la conexión del llamador y son atómicas (Classes\Transaccion).
 */
final class ConsentimientoRepository
{
    public const MARKETING = 'marketing_email';
    public const TEXTO_VERSION_1 = 'Acepto recibir promociones por correo (opcional)';

    private mysqli $db;

    public function __construct(?mysqli $db = null, private string $proposito = self::MARKETING)
    {
        $conexion = $db ?? ActiveRecord::getDB();
        if (!$conexion instanceof mysqli) { throw new PersistenceException('Sin conexión a la base de datos.'); }
        $this->db = $conexion;
    }

    public function proposito(): string { return $this->proposito; }

    /** @return array<int,array<string,mixed>> */
    private function filas(string $sql, string $types = '', array $params = []): array
    {
        try {
            $stmt = $this->db->prepare($sql);
            if (!$stmt) { throw new PersistenceException('No se pudo preparar la consulta de consentimiento.'); }
            if ($types !== '') { $stmt->bind_param($types, ...$params); }
            if (!$stmt->execute()) { $errno = (int)$stmt->errno; $stmt->close(); throw new PersistenceException('No se pudo ejecutar la consulta de consentimiento (errno ' . $errno . ').'); }
            $res = $stmt->get_result();
            $filas = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
            $stmt->close();
            return $filas;
        } catch (PersistenceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new PersistenceException('Fallo en la capa de persistencia de consentimiento.', (int)$e->getCode(), $e);
        }
    }

    private function ejecutar(string $sql, string $types = '', array $params = []): int
    {
        try {
            $stmt = $this->db->prepare($sql);
            if (!$stmt) { throw new PersistenceException('No se pudo preparar la escritura de consentimiento.'); }
            if ($types !== '') { $stmt->bind_param($types, ...$params); }
            if (!$stmt->execute()) { $errno = (int)$stmt->errno; $stmt->close(); throw new PersistenceException('No se pudo escribir el consentimiento (errno ' . $errno . ').'); }
            $n = (int)$stmt->affected_rows;
            $stmt->close();
            return $n;
        } catch (PersistenceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new PersistenceException('Fallo en la capa de persistencia de consentimiento.', (int)$e->getCode(), $e);
        }
    }

    private const COLUMNAS = 'id,proposito,version,texto,texto_hash,vigente_desde,es_historica,creado_en';

    /** Versión vigente ahora: la mayor ya en vigor (vigente_desde NULL = histórica). @throws PersistenceException si no hay ninguna */
    public function vigente(): array
    {
        $f = $this->filas('SELECT ' . self::COLUMNAS . ' FROM consentimiento_versiones WHERE proposito=? AND (vigente_desde IS NULL OR vigente_desde<=UTC_TIMESTAMP()) ORDER BY version DESC LIMIT 1', 's', [$this->proposito]);
        if (!$f) { throw new PersistenceException('No hay versión de consentimiento vigente para «' . $this->proposito . '». ¿Falta aplicar la migración 022?'); }
        return $f[0];
    }

    public function porId(int $id): ?array
    {
        return $this->filas('SELECT ' . self::COLUMNAS . ' FROM consentimiento_versiones WHERE proposito=? AND id=?', 'si', [$this->proposito, $id])[0] ?? null;
    }

    public function porNumero(int $version): ?array
    {
        return $this->filas('SELECT ' . self::COLUMNAS . ' FROM consentimiento_versiones WHERE proposito=? AND version=?', 'si', [$this->proposito, $version])[0] ?? null;
    }

    /** @return array<int,array<string,mixed>> todas las versiones del propósito, de la más reciente a la más antigua */
    public function versiones(): array
    {
        return $this->filas('SELECT ' . self::COLUMNAS . ' FROM consentimiento_versiones WHERE proposito=? ORDER BY version DESC', 's', [$this->proposito]);
    }

    /**
     * Publica una versión nueva (las existentes son inmutables). `$vigenteDesdeUtc` 'Y-m-d H:i:s' en UTC o null = ahora.
     * Los consentimientos previos NO se tocan: quedan asociados a su versión original.
     */
    public function publicar(string $texto, ?string $vigenteDesdeUtc = null): array
    {
        $texto = trim($texto);
        if ($texto === '' || mb_strlen($texto) > 2000) { throw new \InvalidArgumentException('El texto de consentimiento debe tener entre 1 y 2000 caracteres.'); }
        if ($vigenteDesdeUtc !== null && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $vigenteDesdeUtc)) { throw new \InvalidArgumentException('La vigencia debe tener formato AAAA-MM-DD HH:MM:SS (UTC).'); }
        $hash = hash('sha256', $texto);
        return Transaccion::ejecutar($this->db, function () use ($texto, $hash, $vigenteDesdeUtc): array {
            $max = (int)($this->filas('SELECT COALESCE(MAX(version),0) m FROM consentimiento_versiones WHERE proposito=? FOR UPDATE', 's', [$this->proposito])[0]['m'] ?? 0);
            if ($max > 0 && ($this->filas('SELECT 1 FROM consentimiento_versiones WHERE proposito=? AND version=? AND texto_hash=?', 'sis', [$this->proposito, $max, $hash]))) {
                throw new \InvalidArgumentException('El texto es idéntico al de la última versión publicada.');
            }
            $this->ejecutar('INSERT INTO consentimiento_versiones(proposito,version,texto,texto_hash,vigente_desde,es_historica,creado_en) VALUES(?,?,?,?,COALESCE(?,UTC_TIMESTAMP()),0,UTC_TIMESTAMP())',
                'siss' . 's', [$this->proposito, $max + 1, $texto, $hash, $vigenteDesdeUtc]);
            return $this->porNumero($max + 1) ?? throw new PersistenceException('No se pudo leer la versión publicada.');
        });
    }

    /**
     * Versión que la persona vio al decidir. `$enviada` es el id que viajó en el formulario.
     * @return array{0:array,1:bool} [fila de versión, informada] — informada=false si no se envió y se asumió la vigente.
     * @throws \InvalidArgumentException si se envió algo que no es una versión ya en vigor de este propósito
     */
    public function resolverMostrada(mixed $enviada): array
    {
        if ($enviada === null || $enviada === '') { return [$this->vigente(), false]; }
        $id = is_string($enviada) || is_int($enviada) ? filter_var($enviada, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $fila = $id === false ? null : $this->porId((int)$id);
        if ($fila === null || ($fila['vigente_desde'] !== null && strtotime($fila['vigente_desde'] . ' UTC') > time())) {
            throw new \InvalidArgumentException('La versión del texto de consentimiento no es válida. Recarga la página e inténtalo de nuevo.');
        }
        return [$fila, true];
    }

    /**
     * Aplica la decisión de una persona sobre el consentimiento comercial: actualiza la fuente única y, si hay un cambio
     * real, anexa al historial. Atómico y serializado por persona. Devuelve el tipo de cambio efectuado.
     *
     * @param array{origen:string,canal:string,actor_id?:?int,ip_hash?:?string,reconfirma?:bool} $meta
     * @return string 'acepto' | 'reconfirmo' | 'retiro' | 'sin_cambio'
     * @throws \InvalidArgumentException versión enviada inválida al aceptar
     */
    public function aplicarDecision(int $usuarioId, bool $acepta, mixed $versionEnviada, array $meta): string
    {
        return Transaccion::ejecutar($this->db, function () use ($usuarioId, $acepta, $versionEnviada, $meta): string {
            if (!$this->filas('SELECT id FROM usuarios WHERE id=? FOR UPDATE', 'i', [$usuarioId])) { throw new PersistenceException('El perfil ya no existe.'); }
            $prev = $this->filas('SELECT marketing_email,consentimiento_marketing_at at_,consentimiento_marketing_version_id v FROM perfiles_usuario WHERE usuarioId=? FOR UPDATE', 'i', [$usuarioId])[0] ?? null;
            $valido = $prev !== null && (int)$prev['marketing_email'] === 1 && $prev['at_'] !== null;
            $v1 = $this->porNumero(1);
            $versionPrevia = $valido ? ($prev['v'] !== null ? (int)$prev['v'] : (int)($v1['id'] ?? 0)) : null;

            if ($acepta) {
                [$ver, $informada] = $this->resolverMostrada($versionEnviada);
                $cambio = 'acepto';
                if ($valido) {
                    $previa = $this->porId((int)$versionPrevia);
                    $esMasNueva = $previa !== null && (int)$ver['version'] > (int)$previa['version'];
                    // Un consentimiento vigente jamás se «mejora» solo: sin reconfirmación explícita (o con un formulario viejo) no cambia nada.
                    if ($versionPrevia === (int)$ver['id'] || !$esMasNueva || empty($meta['reconfirma'])) { return 'sin_cambio'; }
                    $cambio = 'reconfirmo';
                }
                $this->ejecutar('INSERT INTO perfiles_usuario(usuarioId,marketing_email,consentimiento_marketing_at,consentimiento_marketing_version_id) VALUES(?,1,NOW(),?) '
                    . 'ON DUPLICATE KEY UPDATE marketing_email=1,consentimiento_marketing_at=NOW(),consentimiento_marketing_version_id=VALUES(consentimiento_marketing_version_id)', 'ii', [$usuarioId, (int)$ver['id']]);
                $this->anexar($usuarioId, 1, (int)$ver['id'], $informada, $meta);
                return $cambio;
            }

            if ($prev === null || ((int)$prev['marketing_email'] === 0 && $prev['at_'] === null && $prev['v'] === null)) { return 'sin_cambio'; }
            // Retirar nunca falla por la versión: si lo enviado no sirve, se registra con la vigente.
            try { [$ver, $informada] = $this->resolverMostrada($versionEnviada); }
            catch (\InvalidArgumentException) { $ver = $this->vigente(); $informada = false; }
            $this->ejecutar('UPDATE perfiles_usuario SET marketing_email=0,consentimiento_marketing_at=NULL,consentimiento_marketing_version_id=NULL WHERE usuarioId=?', 'i', [$usuarioId]);
            if ($valido) { $this->anexar($usuarioId, 0, (int)$ver['id'], $informada, $meta); return 'retiro'; }
            return 'sin_cambio';
        });
    }

    private function anexar(int $usuarioId, int $valor, int $versionId, bool $informada, array $meta): void
    {
        $actor = isset($meta['actor_id']) ? (int)$meta['actor_id'] : null;
        $this->ejecutar('INSERT INTO consentimientos_historial(usuario_id,proposito,valor,version_id,version_informada,origen,canal,actor_id,ip_hash,ocurrido_en,registrado_en) VALUES(?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())',
            'isiiissis', [$usuarioId, $this->proposito, $valor, $versionId, (int)$informada, (string)($meta['origen'] ?? 'perfil'), (string)($meta['canal'] ?? 'portal_cliente'), $actor, $meta['ip_hash'] ?? null]);
    }

    /** Historial de decisiones de una persona (más reciente primero). Sin IP ni huellas. @return array<int,array<string,mixed>> */
    public function historial(int $usuarioId, int $limite = 50): array
    {
        $limite = max(1, min(200, $limite));
        return $this->filas('SELECT h.id,h.valor,h.version_id,v.version,v.es_historica,h.version_informada,h.origen,h.canal,h.ocurrido_en,h.registrado_en FROM consentimientos_historial h '
            . 'INNER JOIN consentimiento_versiones v ON v.id=h.version_id WHERE h.usuario_id=? AND h.proposito=? ORDER BY h.id DESC LIMIT ' . $limite, 'is', [$usuarioId, $this->proposito]);
    }
}

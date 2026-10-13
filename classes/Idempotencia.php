<?php

namespace Classes;

use mysqli;

/**
 * Idempotencia persistente de altas.
 *
 * La reserva de la clave y la escritura de negocio comparten UNA transacción de MySQL:
 *  - si el alta se confirma, existe la fila de idempotencia con su resultado;
 *  - si se revierte (validación, UNIQUE de negocio, fallo intermedio), no queda rastro de éxito;
 *  - una solicitud concurrente con la misma clave se bloquea en el índice único hasta que la primera
 *    confirme o revierta, y después recupera el resultado original o ejecuta su propio alta.
 * Esto cubre dobles clics, reintentos tras perder la respuesta, otras pestañas y procesos distintos.
 *
 * La clave de operación es distinta del token CSRF: el CSRF autentica el origen del formulario, la clave
 * identifica UNA intención de alta y viaja en el formulario hasta que se completa.
 */
final class Idempotencia
{
    public const TTL_DIAS = 30;

    /**
     * Punto de observación para pruebas de concurrencia: se invoca con la reserva ya tomada y antes de
     * ejecutar el alta, de modo que una prueba puede mantener el bloqueo y comprobar la espera de otra solicitud.
     * @var callable|null
     */
    public static $observadorTrasReserva = null;
    private const ESPERA_BLOQUEO_SEGUNDOS = 15;
    private const REINTENTOS_INTERBLOQUEO = 3;

    public static function nuevaClave(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function claveValida(mixed $clave): bool
    {
        return is_string($clave) && preg_match('/^[A-Za-z0-9_-]{22,64}$/', $clave) === 1;
    }

    /** HMAC del contenido canónico: no revela ni permite adivinar contraseñas u otros datos sensibles. */
    public static function huella(array $contenido): string
    {
        self::ordenar($contenido);
        return hash_hmac('sha256', json_encode($contenido, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), Secretos::clave('idempotencia'));
    }

    private static function ordenar(array &$datos): void
    {
        ksort($datos);
        foreach ($datos as &$valor) {
            if (is_array($valor)) {
                self::ordenar($valor);
            }
        }
    }

    /**
     * @param string $scope  Operación (p. ej. 'cliente.registro', 'profesional.crear').
     * @param string $actor  Quién opera: 'usuario:ID' o 'anonimo:HASH'. Separa resultados entre personas y portales.
     * @param string $clave  Clave del formulario (se guarda solo su SHA-256).
     * @param array  $contenido Datos significativos del alta para detectar reutilización con contenido distinto.
     * @param callable(mysqli):array $alta Escritura de negocio. Debe devolver ['recurso_tipo'=>..,'recurso_id'=>..,'datos'=>[]]
     *                                      y lanzar excepción para revertir todo.
     * @return array{repetida:bool,recurso_tipo:string,recurso_id:int,datos:array}
     * @throws ConflictoIdempotenciaException misma clave con contenido distinto
     * @throws OperacionEnCursoException      otra solicitud con la misma clave no terminó a tiempo
     */
    public static function ejecutar(mysqli $db, string $scope, string $actor, string $clave, array $contenido, callable $alta): array
    {
        if (!self::claveValida($clave)) {
            throw new \InvalidArgumentException('Clave de operación no válida.');
        }
        $claveHash = hash('sha256', $clave);
        $huella = self::huella($contenido);
        $espera = self::establecerEspera($db);
        try {
            for ($intento = 1; ; $intento++) {
                try {
                    return Transaccion::ejecutar($db, static fn(mysqli $db) => self::reservarYEjecutar($db, $scope, $actor, $claveHash, $huella, $alta));
                } catch (\Throwable $e) {
                    $errno = self::errno($e);
                    if ($errno === 1213 && $intento < self::REINTENTOS_INTERBLOQUEO) {
                        usleep(random_int(20000, 80000) * $intento);
                        continue;
                    }
                    if ($errno === 1205 || $errno === 1213) {
                        throw new OperacionEnCursoException('La solicitud anterior sigue procesándose.', 0, $e);
                    }
                    throw $e;
                }
            }
        } finally {
            if ($espera !== null) {
                @$db->query('SET SESSION innodb_lock_wait_timeout = ' . $espera);
            }
        }
    }

    /**
     * Consulta de solo lectura previa al trabajo costoso o limitado (p. ej. límite de intentos):
     * un reintento legítimo de una operación ya confirmada no debe consumir presupuesto ni repetir efectos.
     *
     * @return array{repetida:bool,recurso_tipo:string,recurso_id:int,datos:array}|null
     * @throws ConflictoIdempotenciaException misma clave con contenido distinto
     */
    public static function buscar(mysqli $db, string $scope, string $actor, string $clave, array $contenido): ?array
    {
        if (!self::claveValida($clave)) {
            return null;
        }
        $claveHash = hash('sha256', $clave);
        $stmt = $db->prepare('SELECT huella, recurso_tipo, recurso_id, resultado, expira_en FROM operaciones_idempotentes WHERE scope = ? AND actor = ? AND clave_hash = ?');
        $stmt->bind_param('sss', $scope, $actor, $claveHash);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$fila || strtotime($fila['expira_en'] . ' UTC') < time()) {
            return null;
        }
        if (!hash_equals($fila['huella'], self::huella($contenido))) {
            throw new ConflictoIdempotenciaException('La clave ya se usó con otro contenido.');
        }
        $datos = json_decode((string)$fila['resultado'], true);
        return ['repetida' => true, 'recurso_tipo' => $fila['recurso_tipo'], 'recurso_id' => (int)$fila['recurso_id'], 'datos' => is_array($datos) ? $datos : []];
    }

    private static function reservarYEjecutar(mysqli $db, string $scope, string $actor, string $claveHash, string $huella, callable $alta): array
    {
        $errno = self::reservar($db, $scope, $actor, $claveHash, $huella);
        if ($errno === 1062) {
            $fila = self::leerExistente($db, $scope, $actor, $claveHash);
            if ($fila !== null && strtotime($fila['expira_en'] . ' UTC') < time()) {
                // Retención vencida: la clave deja de representar una operación vigente.
                $borrar = $db->prepare('DELETE FROM operaciones_idempotentes WHERE id = ?');
                $borrar->bind_param('i', $fila['id']);
                $borrar->execute();
                $borrar->close();
                $fila = null;
            }
            if ($fila === null) {
                $errno = self::reservar($db, $scope, $actor, $claveHash, $huella);
                if ($errno !== 0) {
                    throw new \RuntimeException('No se pudo reservar la operación.', $errno);
                }
            } else {
                if (!hash_equals($fila['huella'], $huella)) {
                    throw new ConflictoIdempotenciaException('La clave ya se usó con otro contenido.');
                }
                $datos = json_decode((string)$fila['resultado'], true);
                return ['repetida' => true, 'recurso_tipo' => $fila['recurso_tipo'], 'recurso_id' => (int)$fila['recurso_id'], 'datos' => is_array($datos) ? $datos : []];
            }
        } elseif ($errno !== 0) {
            throw new \RuntimeException('No se pudo reservar la operación.', $errno);
        }
        $reservaId = (int)$db->insert_id;
        if (self::$observadorTrasReserva !== null) {
            (self::$observadorTrasReserva)($scope);
        }

        $r = $alta($db);
        $tipo = (string)($r['recurso_tipo'] ?? '');
        $id = (int)($r['recurso_id'] ?? 0);
        $datos = is_array($r['datos'] ?? null) ? $r['datos'] : [];
        $json = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $stmt = $db->prepare('UPDATE operaciones_idempotentes SET recurso_tipo = ?, recurso_id = ?, resultado = ? WHERE id = ?');
        $stmt->bind_param('sisi', $tipo, $id, $json, $reservaId);
        $stmt->execute();
        $stmt->close();
        return ['repetida' => false, 'recurso_tipo' => $tipo, 'recurso_id' => $id, 'datos' => $datos];
    }

    /** @return int 0 si se reservó; errno de MySQL si no (1062 = ya existe). */
    private static function reservar(mysqli $db, string $scope, string $actor, string $claveHash, string $huella): int
    {
        $dias = self::TTL_DIAS;
        $stmt = $db->prepare("INSERT INTO operaciones_idempotentes (scope, actor, clave_hash, huella, expira_en) VALUES (?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL {$dias} DAY))");
        $stmt->bind_param('ssss', $scope, $actor, $claveHash, $huella);
        try {
            $ok = $stmt->execute();
            $errno = $ok ? 0 : (int)$stmt->errno;
        } catch (\mysqli_sql_exception $e) {
            $errno = (int)$e->getCode();
        } finally {
            $stmt->close();
        }
        return $errno;
    }

    private static function leerExistente(mysqli $db, string $scope, string $actor, string $claveHash): ?array
    {
        // Lectura con bloqueo: ve la última versión confirmada aunque la transacción tenga una instantánea previa.
        $stmt = $db->prepare('SELECT id, huella, recurso_tipo, recurso_id, resultado, expira_en FROM operaciones_idempotentes WHERE scope = ? AND actor = ? AND clave_hash = ? FOR SHARE');
        $stmt->bind_param('sss', $scope, $actor, $claveHash);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $fila ?: null;
    }

    private static function errno(\Throwable $e): int
    {
        if ($e instanceof \mysqli_sql_exception) {
            return (int)$e->getCode();
        }
        $previo = $e->getPrevious();
        return $previo instanceof \mysqli_sql_exception ? (int)$previo->getCode() : (int)$e->getCode();
    }

    private static function establecerEspera(mysqli $db): ?int
    {
        $res = @$db->query('SELECT @@session.innodb_lock_wait_timeout AS t');
        $actual = $res ? (int)($res->fetch_assoc()['t'] ?? 0) : null;
        @$db->query('SET SESSION innodb_lock_wait_timeout = ' . self::ESPERA_BLOQUEO_SEGUNDOS);
        return $actual;
    }

    /** Actor para flujos públicos: ligado a la sesión del navegador, sin guardar el identificador de sesión. */
    public static function actorAnonimo(): string
    {
        $sid = session_status() === PHP_SESSION_ACTIVE ? session_id() : '';
        return 'anonimo:' . substr(hash_hmac('sha256', $sid, Secretos::clave('actor-anonimo')), 0, 32);
    }

    public static function actorUsuario(int $usuarioId, string $portal): string
    {
        return $portal . ':' . $usuarioId;
    }
}

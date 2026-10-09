<?php

namespace Classes;

use mysqli;

/**
 * Gestor de limitación de tasa (Rate Limiting) atómico y basado en base de datos.
 * Aplica ventana fija de 15 minutos (900s) y duración de bloqueo para mitigar fuerza bruta.
 * Serializa conteo y evaluación atómicamente en MySQL bajo InnoDB.
 */
class RateLimiter
{
    public const VENTANA_SEGUNDOS = 900; // 15 minutos de ventana
    public const DURACION_BLOQUEO = 900; // 15 minutos de bloqueo
    public const MAX_INTENTOS_EMAIL = 5;
    public const MAX_INTENTOS_IP = 15;

    public const TIPO_IP_LOGIN = 'ip_login';
    public const TIPO_EMAIL_LOGIN = 'email_login';
    public const TIPO_IP_RECOVERY = 'ip_recovery';
    public const TIPO_EMAIL_RECOVERY = 'email_recovery';
    public const TIPO_IP_RECONFIRM = 'ip_reconfirm';
    public const TIPO_EMAIL_RECONFIRM = 'email_reconfirm';

    /**
     * Obtiene la dirección IP del cliente sanitizada.
     */
    public static function obtenerIP(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return '127.0.0.1';
        }
        return substr($ip, 0, 45);
    }

    /**
     * Comprueba si un identificador está bloqueado y retorna los segundos restantes.
     */
    public static function obtenerSegundosBloqueo(mysqli $db, string $tipo, string $identificador): int
    {
        try {
            $stmt = @$db->prepare("SELECT TIMESTAMPDIFF(SECOND, NOW(), bloqueado_hasta) AS restante 
                                  FROM intentos_login 
                                  WHERE tipo = ? AND identificador = ? 
                                  LIMIT 1");
            if (!$stmt) {
                return self::DURACION_BLOQUEO;
            }

            $stmt->bind_param('ss', $tipo, $identificador);
            if (!$stmt->execute()) {
                $stmt->close();
                return self::DURACION_BLOQUEO;
            }

            $res = $stmt->get_result();
            $fila = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            if ($fila && !is_null($fila['restante']) && (int)$fila['restante'] > 0) {
                return (int)$fila['restante'];
            }

            return 0;
        } catch (\Throwable $e) {
            // Postura fail-secure ante cualquier error o excepción de base de datos
            return self::DURACION_BLOQUEO;
        }
    }

    /**
     * Comprueba si el vector está actualmente bloqueado.
     */
    public static function estaBloqueado(mysqli $db, string $tipo, string $identificador): bool
    {
        return self::obtenerSegundosBloqueo($db, $tipo, $identificador) > 0;
    }

    /**
     * Registra un intento de forma atómica en una sola operación MySQL.
     * Garantiza serialización atómica ante peticiones concurrentes mediante bloqueo de fila InnoDB.
     * Evalúa el umbral exacto usando `intentos >= ?` tras el incremento en la misma sentencia.
     * Retorna array con ['bloqueado' => bool, 'intentos' => int, 'segundos_restantes' => int].
     */
    public static function registrarIntentoFallido(mysqli $db, string $tipo, string $identificador, int $maxIntentos): array
    {
        $ventana = self::VENTANA_SEGUNDOS;
        $bloqueo = self::DURACION_BLOQUEO;

        try {
            $query = "INSERT INTO intentos_login (identificador, tipo, intentos, bloqueado_hasta, primera_peticion, ultimo_intento)
                      VALUES (?, ?, 1, IF(1 >= ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NULL), NOW(), NOW())
                      ON DUPLICATE KEY UPDATE
                          intentos = IF(bloqueado_hasta > NOW(), intentos,
                                       IF(primera_peticion < DATE_SUB(NOW(), INTERVAL ? SECOND), 1, intentos + 1)),
                          bloqueado_hasta = IF(bloqueado_hasta > NOW(), bloqueado_hasta,
                                              IF(primera_peticion >= DATE_SUB(NOW(), INTERVAL ? SECOND) AND intentos >= ?,
                                                 DATE_ADD(NOW(), INTERVAL ? SECOND),
                                                 NULL)),
                          primera_peticion = IF(bloqueado_hasta > NOW(), primera_peticion,
                                               IF(primera_peticion < DATE_SUB(NOW(), INTERVAL ? SECOND), NOW(), primera_peticion)),
                          ultimo_intento = NOW()";

            $stmt = @$db->prepare($query);
            if (!$stmt) {
                return ['bloqueado' => true, 'intentos' => $maxIntentos, 'segundos_restantes' => self::DURACION_BLOQUEO];
            }

            $stmt->bind_param('ssiiiiiii', 
                $identificador, $tipo,
                $maxIntentos, $bloqueo,
                $ventana, 
                $ventana, $maxIntentos, $bloqueo,
                $ventana
            );

            if (!$stmt->execute()) {
                $stmt->close();
                return ['bloqueado' => true, 'intentos' => $maxIntentos, 'segundos_restantes' => self::DURACION_BLOQUEO];
            }
            $stmt->close();

            return self::consultarEstado($db, $tipo, $identificador);
        } catch (\Throwable $e) {
            // Postura fail-secure ante cualquier excepción de SQL
            return ['bloqueado' => true, 'intentos' => $maxIntentos, 'segundos_restantes' => self::DURACION_BLOQUEO];
        }
    }

    /**
     * Consulta el estado de bloqueo e intentos actual del identificador.
     */
    public static function consultarEstado(mysqli $db, string $tipo, string $identificador): array
    {
        try {
            $stmt = @$db->prepare("SELECT intentos, 
                                         TIMESTAMPDIFF(SECOND, NOW(), bloqueado_hasta) AS segundos_restantes 
                                  FROM intentos_login 
                                  WHERE tipo = ? AND identificador = ? 
                                  LIMIT 1");
            if (!$stmt) {
                return ['bloqueado' => true, 'intentos' => self::MAX_INTENTOS_EMAIL, 'segundos_restantes' => self::DURACION_BLOQUEO];
            }

            $stmt->bind_param('ss', $tipo, $identificador);
            if (!$stmt->execute()) {
                $stmt->close();
                return ['bloqueado' => true, 'intentos' => self::MAX_INTENTOS_EMAIL, 'segundos_restantes' => self::DURACION_BLOQUEO];
            }

            $res = $stmt->get_result();
            $fila = $res ? $res->fetch_assoc() : null;
            $stmt->close();

            if (!$fila) {
                return ['bloqueado' => false, 'intentos' => 0, 'segundos_restantes' => 0];
            }

            $segundosRestantes = (int)($fila['segundos_restantes'] ?? 0);
            $bloqueado = $segundosRestantes > 0;

            return [
                'bloqueado' => $bloqueado,
                'intentos' => (int)$fila['intentos'],
                'segundos_restantes' => max(0, $segundosRestantes)
            ];
        } catch (\Throwable $e) {
            return ['bloqueado' => true, 'intentos' => self::MAX_INTENTOS_EMAIL, 'segundos_restantes' => self::DURACION_BLOQUEO];
        }
    }

    /**
     * Limpia los intentos fallidos tras una operación exitosa (ej. login correcto).
     */
    public static function limpiarIntentos(mysqli $db, string $tipo, string $identificador): void
    {
        $stmt = $db->prepare("DELETE FROM intentos_login WHERE tipo = ? AND identificador = ?");
        if ($stmt) {
            $stmt->bind_param('ss', $tipo, $identificador);
            $stmt->execute();
            $stmt->close();
        }
    }
}

<?php

namespace Classes;

use mysqli;

/**
 * Gestor de limitación de tasa (Rate Limiting) atómico y basado en base de datos.
 * Aplica ventana deslizante de 15 minutos para prevenir fuerza bruta
 * en inicios de sesión y solicitudes de recuperación de contraseña.
 */
class RateLimiter
{
    public const VENTANA_SEGUNDOS = 900; // 15 minutos
    public const MAX_INTENTOS_EMAIL = 5;
    public const MAX_INTENTOS_IP = 15;

    public const TIPO_IP_LOGIN = 'ip_login';
    public const TIPO_EMAIL_LOGIN = 'email_login';
    public const TIPO_IP_RECOVERY = 'ip_recovery';
    public const TIPO_EMAIL_RECOVERY = 'email_recovery';

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
     * Comprueba si un identificador (IP o email) está actualmente bloqueado.
     */
    public static function estaBloqueado(mysqli $db, string $tipo, string $identificador): bool
    {
        $stmt = $db->prepare("SELECT bloqueado_hasta, intentos, ultimo_intento 
                              FROM intentos_login 
                              WHERE tipo = ? AND identificador = ? 
                              LIMIT 1");
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('ss', $tipo, $identificador);
        $stmt->execute();
        $res = $stmt->get_result();
        $registro = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$registro) {
            return false;
        }

        // Si tiene bloqueo activo vigente
        if (!empty($registro['bloqueado_hasta'])) {
            $tiempoBloqueo = strtotime($registro['bloqueado_hasta']);
            if ($tiempoBloqueo !== false && $tiempoBloqueo > time()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Registra un intento fallido de forma atómica.
     * Si la ventana expiró, reinicia el contador. De lo contrario, incrementa y bloquea si excede el máximo.
     */
    public static function registrarIntentoFallido(mysqli $db, string $tipo, string $identificador, int $maxIntentos): void
    {
        $query = "INSERT INTO intentos_login (identificador, tipo, intentos, bloqueado_hasta, ultimo_intento)
                  VALUES (?, ?, 1, NULL, NOW())
                  ON DUPLICATE KEY UPDATE
                      intentos = IF(ultimo_intento < DATE_SUB(NOW(), INTERVAL ? SECOND), 1, intentos + 1),
                      bloqueado_hasta = IF(intentos >= ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NULL),
                      ultimo_intento = NOW()";

        $stmt = $db->prepare($query);
        if (!$stmt) {
            return;
        }

        $ventana = self::VENTANA_SEGUNDOS;
        $stmt->bind_param('ssiii', $identificador, $tipo, $ventana, $maxIntentos, $ventana);
        $stmt->execute();
        $stmt->close();
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

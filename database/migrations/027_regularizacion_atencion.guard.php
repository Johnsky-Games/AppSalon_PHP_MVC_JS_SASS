<?php

/**
 * Guard del rollback de la 027. Nunca modifica datos. Coincide con la guarda del propio rollback SQL: las solicitudes y decisiones
 * de regularización, y las citas regularizadas (quién y cuándo), son evidencia de negocio.
 *
 * @return callable(\mysqli): array<int,array{mensaje:string,tabla:?string}>
 */
return static function (\mysqli $db): array {
    $falta = [];
    $r = $db->query("SHOW TABLES LIKE 'citas_regularizaciones'");
    if ($r && $r->num_rows > 0) {
        $n = (int)($db->query('SELECT COUNT(*) n FROM citas_regularizaciones')->fetch_assoc()['n'] ?? 0);
        if ($n > 0) { $falta[] = ['mensaje' => "{$n} solicitudes o decisiones de regularización se eliminan.", 'tabla' => 'citas_regularizaciones']; }
    }
    $c = $db->query("SHOW COLUMNS FROM citas LIKE 'regularizada_at'");
    if ($c && $c->num_rows > 0) {
        $n = (int)($db->query('SELECT COUNT(*) n FROM citas WHERE regularizada_at IS NOT NULL OR atencion_efectiva_at IS NOT NULL OR regularizada_por IS NOT NULL')->fetch_assoc()['n'] ?? 0);
        if ($n > 0) { $falta[] = ['mensaje' => "{$n} citas pierden quién y cuándo se regularizó su atención.", 'tabla' => 'citas']; }
    }
    return $falta;
};

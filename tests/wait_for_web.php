<?php

/**
 * Script de espera activa para disponibilidad del servidor web de pruebas
 */

$rawUrl = getenv('APP_URL') ?: ($_ENV['APP_URL'] ?? 'http://appsalon-web:3000');
$url = rtrim($rawUrl, '/') . '/';
$maxAttempts = 30;

for ($i = 1; $i <= $maxAttempts; $i++) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200 || $code === 302) {
        echo " -> Servidor web listo tras {$i}s (HTTP {$code}).\n";
        exit(0);
    }
    sleep(1);
}

fwrite(STDERR, "[ERROR] Tiempo de espera agotado para {$url}\n");
exit(1);

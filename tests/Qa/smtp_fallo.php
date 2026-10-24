<?php
/**
 * SMTP con FALLOS controlados para el QA de la ronda 2B (cola de correos). Propio del QA; solo escucha en 127.0.0.1.
 *
 * Uso: php tests/Qa/smtp_fallo.php <puerto> <modo> <ruta_buzon.json> [retardo_segundos]
 *
 * Modos (el modo se puede cambiar en caliente escribiendo la palabra en el archivo `<buzon>.modo`):
 *   ok            acepta y guarda el mensaje (como smtp_qa.php).
 *   cuelga        acepta la conexión y no responde nunca (el cliente debe agotar su tiempo de espera).
 *   rcpt4xx       responde 451 al RCPT (fallo temporal).
 *   rcpt5xx       responde 550 al RCPT incluyendo la dirección del destinatario en el texto (riesgo de PII en ultimo_error).
 *   rcptmulti     como rcpt5xx pero el texto repite el destinatario (también en mayúsculas) y añade OTRAS direcciones (postmaster, una con parte local
 *                 entre comillas y el remitente), como hacen algunos servidores reales.
 *   data5xx       acepta hasta DATA y responde 554 tras el cuerpo.
 *   acepta_y_corta  guarda el mensaje pero corta la conexión SIN responder 250 (el remitente cree que falló: duplicado posible).
 *   lento         responde bien pero espera `retardo` segundos antes del 250 final del DATA.
 */
[, $port, $modoInicial, $buzon] = $argv + [null, 2590, 'ok', '/tmp/qa_buzon_fallo.json'];
$retardo = (float)($argv[4] ?? 0);
$srv = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
if (!$srv) { fwrite(STDERR, "No se pudo abrir $port: $errstr\n"); exit(1); }
file_put_contents($buzon, '[]');
file_put_contents($buzon . '.modo', $modoInicial);
// Los sondeos de puerto no hablan SMTP: contar saludos detecta intentos reales sin medir la velocidad de la CPU.
$saludos = 0;
file_put_contents($buzon . '.saludos', '0');
// Conexiones TCP aceptadas (incluye las que luego se cuelgan o cortan antes de hablar SMTP): una petición que no debe enviar correo no puede abrir ninguna.
$conexiones = 0;
file_put_contents($buzon . '.conexiones', '0');
$colgadas = [];
while ($c = @stream_socket_accept($srv, -1)) {
    file_put_contents($buzon . '.conexiones', (string)++$conexiones, LOCK_EX);
    $modo = trim((string)@file_get_contents($buzon . '.modo')) ?: 'ok';
    if ($modo === 'cuelga') { $colgadas[] = $c; continue; }
    stream_set_timeout($c, 30);
    fwrite($c, "220 qa-fallo ready\r\n");
    $data = false; $raw = ''; $msg = ['from' => '', 'to' => []];
    while (!feof($c)) {
        $l = fgets($c);
        if ($l === false) { break; }
        if ($data) {
            if (rtrim($l, "\r\n") === '.') {
                $data = false;
                if ($modo === 'data5xx') { fwrite($c, "554 5.3.0 transaccion rechazada\r\n"); $raw = ''; continue; }
                [$cab, $cuerpo] = array_pad(preg_split("/\r?\n\r?\n/", $raw, 2), 2, '');
                $dec = $cuerpo;
                if (preg_match('/Content-Transfer-Encoding:\s*base64/i', $cab)) { $dec = base64_decode(preg_replace('/\s+/', '', $cuerpo)) ?: $cuerpo; }
                elseif (preg_match('/Content-Transfer-Encoding:\s*quoted-printable/i', $cab)) { $dec = quoted_printable_decode($cuerpo); }
                if (preg_match('/boundary="?([^"\r\n;]+)"?/i', $cab, $b)) {
                    $dec = '';
                    foreach (explode('--' . $b[1], $cuerpo) as $parte) {
                        [$pc, $pb] = array_pad(preg_split("/\r?\n\r?\n/", $parte, 2), 2, '');
                        if (preg_match('/base64/i', $pc)) { $dec .= base64_decode(preg_replace('/\s+/', '', $pb)); }
                        elseif (preg_match('/quoted-printable/i', $pc)) { $dec .= quoted_printable_decode($pb); }
                        else { $dec .= $pb; }
                    }
                }
                $mb = json_decode((string)file_get_contents($buzon), true) ?: [];
                $mb[] = ['from' => $msg['from'], 'to' => $msg['to'], 'cabeceras' => $cab, 'cuerpo' => $dec, 'ts' => microtime(true)];
                file_put_contents($buzon, json_encode($mb, JSON_UNESCAPED_UNICODE));
                if ($modo === 'acepta_y_corta') { break; }
                if ($modo === 'lento' && $retardo > 0) { usleep((int)($retardo * 1e6)); }
                fwrite($c, "250 2.0.0 queued\r\n");
                $raw = '';
            } else {
                $raw .= (str_starts_with($l, '..') ? substr($l, 1) : $l);
            }
            continue;
        }
        $t = trim($l); $u = strtoupper($t);
        if (str_starts_with($u, 'EHLO') || str_starts_with($u, 'HELO')) {
            file_put_contents($buzon . '.saludos', (string)++$saludos, LOCK_EX);
            fwrite($c, "250-qa\r\n250-AUTH LOGIN PLAIN\r\n250 OK\r\n");
        }
        elseif (str_starts_with($u, 'AUTH LOGIN')) { fwrite($c, "334 VXNlcm5hbWU6\r\n"); fgets($c); fwrite($c, "334 UGFzc3dvcmQ6\r\n"); fgets($c); fwrite($c, "235 ok\r\n"); }
        elseif (str_starts_with($u, 'AUTH PLAIN')) { fwrite($c, "235 ok\r\n"); }
        elseif (str_starts_with($u, 'MAIL FROM:')) { $msg['from'] = trim(substr($t, 10), '<> '); fwrite($c, "250 ok\r\n"); }
        elseif (str_starts_with($u, 'RCPT TO:')) {
            $dir = trim(substr($t, 8), '<> ');
            if ($modo === 'rcpt4xx') { fwrite($c, "451 4.3.0 intente mas tarde\r\n"); continue; }
            if ($modo === 'rcptmulti') { fwrite($c, "550-5.1.1 <{$dir}> Recipient address rejected: User unknown (" . strtoupper($dir) . ")\r\n550-5.1.1 Contact postmaster@mail.example.test or \"Soporte Local\"@mail.example.test, Admin <admin@example.test>\r\n550 5.1.1 forwarded to {$dir}; sender <cuentas@appsalon.com>\r\n"); continue; }
            if ($modo === 'rcpt5xx') { fwrite($c, "550 5.1.1 <{$dir}> Recipient address rejected: User unknown in virtual mailbox table\r\n"); continue; }
            $msg['to'][] = $dir; fwrite($c, "250 ok\r\n");
        }
        elseif ($u === 'DATA') { $data = true; $raw = ''; fwrite($c, "354 go\r\n"); }
        elseif ($u === 'QUIT') { fwrite($c, "221 bye\r\n"); break; }
        elseif ($u === 'RSET') { $msg = ['from' => '', 'to' => []]; fwrite($c, "250 ok\r\n"); }
        else { fwrite($c, "250 ok\r\n"); }
    }
    @fclose($c);
}

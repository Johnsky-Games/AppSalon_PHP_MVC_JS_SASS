<?php
/**
 * SMTP simulado del QA (propio, no comparte puerto ni buzón con el del equipo de desarrollo).
 * Uso: php tests/Qa/smtp_qa.php <puerto> <ruta_buzon.json>
 * Cada mensaje se guarda con el cuerpo ya decodificado (base64 / quoted-printable) para poder extraer enlaces.
 */
[, $port, $buzon] = $argv + [null, 2598, '/tmp/qa_buzon.json'];
$srv = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
if (!$srv) { fwrite(STDERR, "No se pudo abrir $port: $errstr\n"); exit(1); }
file_put_contents($buzon, '[]');
while ($c = @stream_socket_accept($srv, -1)) {
    fwrite($c, "220 qa-smtp ready\r\n");
    $data = false; $raw = ''; $msg = ['from' => '', 'to' => []];
    while (!feof($c)) {
        $l = fgets($c);
        if ($l === false) { break; }
        if ($data) {
            if (rtrim($l, "\r\n") === '.') {
                $data = false;
                [$cab, $cuerpo] = array_pad(preg_split("/\r?\n\r?\n/", $raw, 2), 2, '');
                $dec = $cuerpo;
                if (preg_match('/Content-Transfer-Encoding:\s*base64/i', $cab)) { $dec = base64_decode(preg_replace('/\s+/', '', $cuerpo)) ?: $cuerpo; }
                elseif (preg_match('/Content-Transfer-Encoding:\s*quoted-printable/i', $cab)) { $dec = quoted_printable_decode($cuerpo); }
                // Multipart: decodificar cada parte.
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
                $mb[] = ['from' => $msg['from'], 'to' => $msg['to'], 'cabeceras' => $cab, 'cuerpo' => $dec, 'ts' => date('c')];
                file_put_contents($buzon, json_encode($mb, JSON_UNESCAPED_UNICODE));
                fwrite($c, "250 2.0.0 queued\r\n");
                $raw = '';
            } else {
                $raw .= (str_starts_with($l, '..') ? substr($l, 1) : $l);
            }
            continue;
        }
        $t = trim($l); $u = strtoupper($t);
        if (str_starts_with($u, 'EHLO') || str_starts_with($u, 'HELO')) { fwrite($c, "250-qa\r\n250-AUTH LOGIN PLAIN\r\n250 OK\r\n"); }
        elseif (str_starts_with($u, 'AUTH LOGIN')) { fwrite($c, "334 VXNlcm5hbWU6\r\n"); fgets($c); fwrite($c, "334 UGFzc3dvcmQ6\r\n"); fgets($c); fwrite($c, "235 ok\r\n"); }
        elseif (str_starts_with($u, 'AUTH PLAIN')) { fwrite($c, "235 ok\r\n"); }
        elseif (str_starts_with($u, 'MAIL FROM:')) { $msg['from'] = trim(substr($t, 10), '<> '); fwrite($c, "250 ok\r\n"); }
        elseif (str_starts_with($u, 'RCPT TO:')) { $msg['to'][] = trim(substr($t, 8), '<> '); fwrite($c, "250 ok\r\n"); }
        elseif ($u === 'DATA') { $data = true; $raw = ''; fwrite($c, "354 go\r\n"); }
        elseif ($u === 'QUIT') { fwrite($c, "221 bye\r\n"); break; }
        elseif ($u === 'RSET') { $msg = ['from' => '', 'to' => []]; fwrite($c, "250 ok\r\n"); }
        else { fwrite($c, "250 ok\r\n"); }
    }
    fclose($c);
}

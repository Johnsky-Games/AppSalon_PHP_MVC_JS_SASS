<?php
/**
 * SMTP simulado de la cola de correos (nada sale a Internet: escucha solo en 127.0.0.1).
 * Uso: php tests/fixtures/cola_smtp.php <puerto> <buzon.jsonl> [<archivo_modo>]
 *
 * Cada mensaje aceptado se añade al buzón como una línea JSON {to:[], from, cuerpo, ts} con el cuerpo ya
 * decodificado (base64 / quoted-printable / multipart), de modo que una prueba puede extraer el enlace.
 *
 * El comportamiento se cambia EN CALIENTE escribiendo en <archivo_modo> (se lee en cada conexión):
 *   ok                 acepta normalmente (por defecto)
 *   rechazar           responde 451 a RCPT TO (fallo temporal del servidor)
 *   colgar             acepta la conexión y no saluda hasta que el cliente cierre (simula SMTP colgado / worker muerto en pleno envío)
 *   lento:<ms>         espera <ms> antes de confirmar el mensaje (para solapar varios workers)
 *   aceptar_y_cortar   guarda el mensaje pero corta la conexión SIN confirmarlo (el cliente no sabe que se entregó:
 *                      caso límite de la entrega «al menos una vez», puede producir un duplicado al reintentar)
 */
[, $puerto, $buzon, $archivoModo] = $argv + [null, 2620, '/tmp/cola_buzon.jsonl', null];
$srv = stream_socket_server("tcp://127.0.0.1:{$puerto}", $errno, $errstr);
if (!$srv) { fwrite(STDERR, "No se pudo abrir {$puerto}: {$errstr}\n"); exit(1); }
if (!is_file($buzon)) { file_put_contents($buzon, ''); }
$modo = static fn(): string => $archivoModo && is_file($archivoModo) ? trim((string)file_get_contents($archivoModo)) : 'ok';

while ($c = @stream_socket_accept($srv, -1)) {
    $m = $modo();
    if ($m === 'colgar') {
        while (!feof($c) && fgets($c) !== false) { /* ignora todo */ }
        fclose($c);
        continue;
    }
    fwrite($c, "220 cola-smtp listo\r\n");
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
                if (preg_match('/boundary="?([^"\r\n;]+)"?/i', $cab, $b)) {
                    $dec = '';
                    foreach (explode('--' . $b[1], $cuerpo) as $parte) {
                        [$pc, $pb] = array_pad(preg_split("/\r?\n\r?\n/", $parte, 2), 2, '');
                        if (preg_match('/base64/i', $pc)) { $dec .= base64_decode(preg_replace('/\s+/', '', $pb)); }
                        elseif (preg_match('/quoted-printable/i', $pc)) { $dec .= quoted_printable_decode($pb); }
                        else { $dec .= $pb; }
                    }
                }
                file_put_contents($buzon, json_encode(['from' => $msg['from'], 'to' => $msg['to'], 'cabeceras' => $cab, 'cuerpo' => $dec, 'ts' => microtime(true)], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
                $raw = '';
                if (str_starts_with($m, 'lento:')) { usleep(((int)substr($m, 6)) * 1000); }
                if ($m === 'aceptar_y_cortar') { break; }
                fwrite($c, "250 2.0.0 queued\r\n");
            } else {
                $raw .= (str_starts_with($l, '..') ? substr($l, 1) : $l);
            }
            continue;
        }
        $t = trim($l); $u = strtoupper($t);
        if (str_starts_with($u, 'EHLO') || str_starts_with($u, 'HELO')) { fwrite($c, "250-cola\r\n250-AUTH LOGIN PLAIN\r\n250 OK\r\n"); }
        elseif (str_starts_with($u, 'AUTH LOGIN')) { fwrite($c, "334 VXNlcm5hbWU6\r\n"); fgets($c); fwrite($c, "334 UGFzc3dvcmQ6\r\n"); fgets($c); fwrite($c, "235 ok\r\n"); }
        elseif (str_starts_with($u, 'AUTH PLAIN')) { fwrite($c, "235 ok\r\n"); }
        elseif (str_starts_with($u, 'MAIL FROM:')) { $msg['from'] = trim(substr($t, 10), '<> '); fwrite($c, "250 ok\r\n"); }
        elseif (str_starts_with($u, 'RCPT TO:')) {
            if ($m === 'rechazar') { fwrite($c, "451 4.3.0 servidor de prueba: intenta mas tarde\r\n"); continue; }
            $msg['to'][] = trim(substr($t, 8), '<> '); fwrite($c, "250 ok\r\n");
        }
        elseif ($u === 'DATA') { $data = true; $raw = ''; fwrite($c, "354 go\r\n"); }
        elseif ($u === 'QUIT') { fwrite($c, "221 bye\r\n"); break; }
        elseif ($u === 'RSET') { $msg = ['from' => '', 'to' => []]; fwrite($c, "250 ok\r\n"); }
        else { fwrite($c, "250 ok\r\n"); }
    }
    fclose($c);
}

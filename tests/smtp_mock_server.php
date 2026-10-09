<?php

/**
 * Servidor SMTP simulado ultraligero para pruebas funcionales de AppSalon.
 * Escucha en el puerto 2525 y guarda los correos entrantes en tests/mailbox.json.
 */

$port = 2525;
$socket = stream_socket_server("tcp://0.0.0.0:{$port}", $errno, $errstr);

if (!$socket) {
    fwrite(STDERR, "Error iniciando servidor SMTP mock: {$errstr} ({$errno})\n");
    exit(1);
}

echo "Servidor SMTP mock escuchando en 0.0.0.0:{$port}...\n";
$mailboxFile = __DIR__ . '/mailbox.json';
file_put_contents($mailboxFile, json_encode([]));

while ($conn = @stream_socket_accept($socket, -1)) {
    fwrite($conn, "220 appsalon-mock-smtp ESMTP Service Ready\r\n");
    $dataMode = false;
    $emailBody = '';
    $currentEmail = ['to' => '', 'from' => '', 'body' => '', 'timestamp' => date('Y-m-d H:i:s')];

    while (!feof($conn)) {
        $line = fgets($conn);
        if ($line === false) {
            break;
        }

        if ($dataMode) {
            if (rtrim($line, "\r\n") === '.') {
                $dataMode = false;
                $currentEmail['body'] = $emailBody;
                $emails = json_decode(file_get_contents($mailboxFile), true) ?: [];
                $emails[] = $currentEmail;
                file_put_contents($mailboxFile, json_encode($emails, JSON_PRETTY_PRINT));
                fwrite($conn, "250 2.0.0 OK: message queued\r\n");
            } else {
                $emailBody .= $line;
            }
            continue;
        }

        $lineTrim = trim($line);
        $upper = strtoupper($lineTrim);

        if (str_starts_with($upper, 'EHLO') || str_starts_with($upper, 'HELO')) {
            fwrite($conn, "250-appsalon-mock-smtp\r\n250-AUTH LOGIN PLAIN\r\n250 OK\r\n");
        } elseif (str_starts_with($upper, 'AUTH LOGIN')) {
            fwrite($conn, "334 VXNlcm5hbWU6\r\n");
            $user = fgets($conn);
            fwrite($conn, "334 UGFzc3dvcmQ6\r\n");
            $pass = fgets($conn);
            fwrite($conn, "235 2.7.0 Authentication successful\r\n");
        } elseif (str_starts_with($upper, 'MAIL FROM:')) {
            $currentEmail['from'] = trim(substr($lineTrim, 10), '<> ');
            fwrite($conn, "250 2.1.0 Ok\r\n");
        } elseif (str_starts_with($upper, 'RCPT TO:')) {
            $currentEmail['to'] = trim(substr($lineTrim, 8), '<> ');
            fwrite($conn, "250 2.1.5 Ok\r\n");
        } elseif (str_starts_with($upper, 'DATA')) {
            $dataMode = true;
            $emailBody = '';
            fwrite($conn, "354 Start mail input; end with <CRLF>.<CRLF>\r\n");
        } elseif (str_starts_with($upper, 'QUIT')) {
            fwrite($conn, "221 2.0.0 Bye\r\n");
            break;
        } elseif (str_starts_with($upper, 'RSET')) {
            $dataMode = false;
            $emailBody = '';
            fwrite($conn, "250 2.0.0 OK Reset\r\n");
        } elseif (str_starts_with($upper, 'NOOP')) {
            fwrite($conn, "250 2.0.0 OK\r\n");
        } else {
            fwrite($conn, "250 OK\r\n");
        }
    }
    fclose($conn);
}

<?php
/** Supervisor CLI: cada ejecución abre conexión nueva y procesa la outbox existente. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$intervalo = filter_var(getenv('NOTIFICACIONES_INTERVALO_SEGUNDOS') ?: 60, FILTER_VALIDATE_INT, ['options'=>['min_range'=>10,'max_range'=>3600]]);
if ($intervalo === false) { fwrite(STDERR, "Intervalo inválido.\n"); exit(1); }
$detener = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () use (&$detener) { $detener=true; });
    pcntl_signal(SIGINT, function () use (&$detener) { $detener=true; });
}
while (!$detener) {
    $proceso = proc_open([PHP_BINARY, __DIR__.'/procesar_notificaciones.php', '--json'], [0=>['file','/dev/null','r'],1=>STDOUT,2=>STDERR], $pipes);
    if (!is_resource($proceso)) { fwrite(STDERR,"No se pudo iniciar procesador.\n"); exit(1); }
    $codigo = proc_close($proceso);
    if ($codigo !== 0) fwrite(STDERR, "Procesador terminó con código {$codigo}; reintentará según backoff persistido.\n");
    // Cola de correos transaccionales (confirmaciones de cuenta, avisos e invitaciones): reintentos con retroceso
    // exponencial, corte de tanda con SMTP caído y retención de claves de idempotencia.
    $correos = proc_open([PHP_BINARY, __DIR__.'/procesar_correos.php'], [0=>['file','/dev/null','r'],1=>STDOUT,2=>STDERR], $pipes2);
    if (is_resource($correos)) { $c2 = proc_close($correos); if ($c2 !== 0) fwrite(STDERR, "Procesador de correos terminó con código {$c2}.\n"); }
    for ($i=0; $i<$intervalo && !$detener; $i++) sleep(1);
}

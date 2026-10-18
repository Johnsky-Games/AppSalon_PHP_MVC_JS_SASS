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
// La cola de correos de cuenta (confirmaciones, recuperaciones, avisos e invitaciones) se procesa con más frecuencia que
// los recordatorios: el envío NO ocurre en la petición HTTP (para no delatar si una cuenta existe por el tiempo de respuesta).
$intervaloCorreos = filter_var(getenv('CORREOS_INTERVALO_SEGUNDOS') ?: 5, FILTER_VALIDATE_INT, ['options'=>['min_range'=>2,'max_range'=>600]]);
if ($intervaloCorreos === false) { fwrite(STDERR, "CORREOS_INTERVALO_SEGUNDOS inválido.\n"); exit(1); }
$ultimaNotificacion = 0;
while (!$detener) {
    if (time() - $ultimaNotificacion >= $intervalo) {
        $ultimaNotificacion = time();
        $proceso = proc_open([PHP_BINARY, __DIR__.'/procesar_notificaciones.php', '--json'], [0=>['file','/dev/null','r'],1=>STDOUT,2=>STDERR], $pipes);
        if (!is_resource($proceso)) { fwrite(STDERR,"No se pudo iniciar procesador.\n"); exit(1); }
        $codigo = proc_close($proceso);
        $erp=proc_open([PHP_BINARY,__DIR__.'/procesar_erp.php'],[0=>['file','/dev/null','r'],1=>STDOUT,2=>STDERR],$erpPipes);
        if(is_resource($erp))proc_close($erp);
        if ($codigo !== 0) fwrite(STDERR, "Procesador terminó con código {$codigo}; reintentará según backoff persistido.\n");
    }
    // Cola de correos transaccionales: reintentos con retroceso exponencial, corte de tanda con SMTP caído y retención
    // de claves de idempotencia.
    $correos = proc_open([PHP_BINARY, __DIR__.'/procesar_correos.php'], [0=>['file','/dev/null','r'],1=>STDOUT,2=>STDERR], $pipes2);
    if (is_resource($correos)) { $c2 = proc_close($correos); if ($c2 !== 0) fwrite(STDERR, "Procesador de correos terminó con código {$c2}.\n"); }
    for ($i=0; $i<$intervaloCorreos && !$detener; $i++) sleep(1);
}

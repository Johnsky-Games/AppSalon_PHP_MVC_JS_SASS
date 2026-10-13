<?php
/**
 * Worker y herramienta operativa de la cola de correos transaccionales (tabla `correos_salida`):
 * confirmación de cuenta, aviso de cuenta existente e invitaciones de colaboradores.
 *
 *   php scripts/procesar_correos.php [--limite=20]        Procesa los eventos listos (modo worker, por defecto).
 *   php scripts/procesar_correos.php --estado [--json]    Diagnóstico: conteos por estado/tipo, antigüedad, fallidos.
 *                                                         Sin datos personales (ni correos, ni nombres, ni tokens).
 *   php scripts/procesar_correos.php --reintentar=<id>    Reactiva UN evento `fallido` (vuelve a `pendiente`, 0 intentos).
 *   php scripts/procesar_correos.php --reintentar=todos-fallidos
 *   php scripts/procesar_correos.php --ayuda
 *
 * Códigos de salida:  0 correcto · 1 error de ejecución/base de datos (o --reintentar=<id> sin efecto)
 *                     · 2 uso incorrecto · 3 solo con --estado: hay eventos `fallido` que requieren atención.
 *
 * Cada evento se toma con arrendamiento exclusivo (worker_token + lease), de modo que varios procesos simultáneos
 * no envían el mismo evento a la vez; si un proceso muere en pleno envío, otro lo retoma al vencer el lease
 * (garantía: al menos una vez, ver docs/NOTIFICACIONES_WORKER.md).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/app.php';

use Repositories\CorreoSalidaRepository;
use Services\CorreoSalidaService;

const FALLOS_SEGUIDOS_PARA_CORTAR = 3;

$conocidas = ['limite', 'estado', 'json', 'reintentar', 'ayuda', 'help'];
$opciones = getopt('', ['limite::', 'estado', 'json', 'reintentar:', 'ayuda', 'help']);
foreach (array_slice($argv, 1) as $arg) {
    if (!preg_match('/^--([a-z]+)(=.*)?$/', $arg, $m) || !in_array($m[1], $conocidas, true)) {
        fwrite(STDERR, "Argumento no reconocido: " . preg_replace('/[^\x20-\x7E]/', '?', mb_substr($arg, 0, 60)) . "\nUsa --ayuda.\n");
        exit(2);
    }
    if ($m[1] === 'reintentar' && (!isset($m[2]) || $m[2] === '=')) {
        // Sin valor, getopt lo ignoraría y se ejecutaría una tanda normal: es un uso incorrecto (QA R2B).
        fwrite(STDERR, "--reintentar exige un valor: --reintentar=<id> o --reintentar=todos-fallidos.\n");
        exit(2);
    }
}
if (isset($opciones['ayuda']) || isset($opciones['help'])) {
    $doc = (string)file_get_contents(__FILE__);
    preg_match('#/\*\*(.*?)\*/#s', $doc, $m);
    echo trim(preg_replace('/^\s*\* ?/m', '', $m[1] ?? '')) . "\n";
    exit(0);
}

$db = Model\ActiveRecord::getDB();
if (!$db instanceof mysqli) { fwrite(STDERR, "Sin conexión a la base de datos.\n"); exit(1); }
$json = isset($opciones['json']);

try {
    $repo = new CorreoSalidaRepository($db);

    if (isset($opciones['estado'])) {
        $r = $repo->resumen();
        if ($json) {
            echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        } else {
            echo "Cola de correos (tabla correos_salida): {$r['total']} evento(s)\n";
            foreach ($r['por_estado'] as $estado => $tipos) {
                foreach ($tipos as $tipo => $n) { echo sprintf("  %-10s %-26s %d\n", $estado, $tipo, $n); }
            }
            echo 'Activo más antiguo (pendiente/enviando): ' . ($r['pendiente_mas_antiguo_segundos'] === null ? 'ninguno' : $r['pendiente_mas_antiguo_segundos'] . ' s') . "\n";
            echo "Envíos con lease vencido (worker caído): {$r['enviando_vencidos']}\n";
            foreach ($r['fallidos'] as $f) {
                echo sprintf("  FALLIDO #%d %s intentos=%d edad=%ds reintentable=%s error=%s\n", $f['id'], $f['tipo'], $f['intentos'], $f['edad_segundos'], $f['reintentable'] ? 'sí' : 'no', $f['ultimo_error']);
            }
        }
        $fallidos = array_sum($r['por_estado']['fallido'] ?? []);
        if ($fallidos > 0 && !$json) { echo "ATENCIÓN: {$fallidos} evento(s) fallido(s). Reactivar: php scripts/procesar_correos.php --reintentar=<id|todos-fallidos>\n"; }
        exit($fallidos > 0 ? 3 : 0);
    }

    if (isset($opciones['reintentar'])) {
        $objetivo = (string)$opciones['reintentar'];
        if ($objetivo === 'todos-fallidos') {
            $n = $repo->reintentar(null);
            echo "Eventos reactivados: {$n}\n";
        } elseif (preg_match('/^[1-9][0-9]{0,18}$/', $objetivo) === 1) {
            $n = $repo->reintentar((int)$objetivo);
            echo "Eventos reactivados: {$n}\n";
            if ($n === 0) {
                fwrite(STDERR, "El evento #{$objetivo} no existe, no está en estado fallido o ya no conserva su carga (envíalo de nuevo desde la aplicación).\n");
                exit(1);
            }
        } else {
            fwrite(STDERR, "--reintentar espera un id numérico o 'todos-fallidos'.\n");
            exit(2);
        }
        if ($n > 0) { echo "Se enviarán en la próxima ejecución del worker (o ejecuta ahora: php scripts/procesar_correos.php).\n"; }
        exit(0);
    }

    $limite = max(1, min(100, (int)($opciones['limite'] ?? 20)));
    $r = (new CorreoSalidaService($repo))->procesarLote($limite, FALLOS_SEGUIDOS_PARA_CORTAR);
    echo "Correos enviados: {$r['enviados']}\n";
    echo "Fallos de envío (se reintentarán con retroceso): {$r['fallidos']}; cancelados por obsoletos: {$r['cancelados']}\n";
    if ($r['cortado']) {
        echo "Tanda interrumpida tras " . FALLOS_SEGUIDOS_PARA_CORTAR . " fallos seguidos (¿SMTP caído?); {$r['omitidos']} evento(s) quedan intactos para la próxima ejecución.\n";
    }
    $fallidos = array_sum($repo->resumen(1)['por_estado']['fallido'] ?? []);
    if ($fallidos > 0) {
        fwrite(STDERR, "ATENCIÓN: {$fallidos} correo(s) en estado fallido (agotaron sus reintentos). Diagnóstico: php scripts/procesar_correos.php --estado\n");
    }

    // Retención: las claves de idempotencia vencidas ya no representan operaciones vigentes (30 días).
    $db->query('DELETE FROM operaciones_idempotentes WHERE expira_en < UTC_TIMESTAMP() LIMIT 1000');
    echo 'Claves de idempotencia vencidas eliminadas: ' . max(0, (int)$db->affected_rows) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Error en la cola de correos: ' . Classes\Email::sanearMensajeError($e->getMessage()) . "\n");
    exit(1);
}

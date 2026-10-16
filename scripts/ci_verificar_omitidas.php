<?php
/**
 * Verifica que ninguna prueba se omita en silencio en CI.
 * Uso: php scripts/ci_verificar_omitidas.php <junit.xml> <permitidas.txt>
 * Cada línea de «permitidas» es `Clase::metodo | motivo` (las líneas vacías o con `#` se ignoran).
 * Sale con código 1 si hay una omitida no permitida o si el XML no tiene pruebas.
 */
if ($argc < 3) { fwrite(STDERR, "Uso: php scripts/ci_verificar_omitidas.php <junit.xml> <permitidas.txt>\n"); exit(2); }
$xml = @simplexml_load_file($argv[1]);
if ($xml === false) { fwrite(STDERR, "No se pudo leer {$argv[1]}\n"); exit(2); }
$permitidas = [];
foreach (file($argv[2], FILE_IGNORE_NEW_LINES) ?: [] as $linea) {
    $linea = trim($linea);
    if ($linea === '' || $linea[0] === '#') { continue; }
    [$id, $motivo] = array_pad(array_map('trim', explode('|', $linea, 2)), 2, '');
    $permitidas[$id] = $motivo;
}
$total = 0; $omitidas = [];
foreach ($xml->xpath('//testcase') as $caso) {
    $total++;
    if (isset($caso->skipped)) {
        $id = ((string)$caso['class'] !== '' ? (string)$caso['class'] : (string)$caso['classname']) . '::' . preg_replace('/ with data set.*$/', '', (string)$caso['name']);
        $omitidas[$id] = trim((string)$caso->skipped) ?: '(sin mensaje)';
    }
}
if ($total === 0) { fwrite(STDERR, "El informe JUnit no contiene pruebas: la suite no se ejecutó.\n"); exit(1); }
$mal = 0;
printf("Pruebas en el informe: %d; omitidas: %d\n", $total, count($omitidas));
foreach ($omitidas as $id => $mensaje) {
    $ok = isset($permitidas[$id]);
    printf("  [%s] %s — %s\n", $ok ? 'PERMITIDA' : 'NO PERMITIDA', $id, $ok ? $permitidas[$id] : $mensaje);
    if (!$ok) { $mal++; }
}
foreach (array_diff_key($permitidas, $omitidas) as $id => $_) { printf("  [AVISO] La omisión permitida «%s» ya no ocurre; retírala de la lista.\n", $id); }
exit($mal > 0 ? 1 : 0);

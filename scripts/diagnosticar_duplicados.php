<?php
/**
 * Diagnóstico de SOLO LECTURA de posibles duplicados (cuentas, profesionales, personas clientes).
 *
 *   php scripts/diagnosticar_duplicados.php [--json]
 *
 * No borra, no fusiona, no desactiva ni modifica nada: la sesión se abre en modo READ ONLY.
 * Presenta candidatos con evidencia (historial, relaciones, cercanía temporal entre altas) para que el
 * propietario decida con respaldo previo. Una coincidencia de nombre NUNCA es prueba de duplicado:
 * existen homónimos legítimos. Las altas casi simultáneas con las mismas relaciones y sin historial
 * se marcan como "probable doble envío" solo como pista de revisión.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/app.php';

use Model\ActiveRecord;

$db = ActiveRecord::getDB();
if (!$db instanceof mysqli) { fwrite(STDERR, "Sin conexión a la base de datos.\n"); exit(1); }
$db->query('SET SESSION TRANSACTION READ ONLY');
$json = in_array('--json', $argv, true);
$informe = ['generado_en' => gmdate('c'), 'solo_lectura' => true, 'secciones' => []];

function filas(mysqli $db, string $sql): array
{
    $res = $db->query($sql);
    if (!$res) { fwrite(STDERR, "Consulta de diagnóstico fallida: {$db->error}\n"); exit(1); }
    return $res->fetch_all(MYSQLI_ASSOC);
}

// 1) Cuentas con el mismo correo normalizado (el UNIQUE de la migración 017 lo impide; aquí se verifica el estado real).
$informe['secciones']['cuentas_correo_equivalente'] = filas($db,
    "SELECT LOWER(TRIM(email)) correo_normalizado, GROUP_CONCAT(id ORDER BY id) usuario_ids, COUNT(*) total
     FROM usuarios GROUP BY LOWER(TRIM(email)) HAVING COUNT(*) > 1");

// 2) Profesionales con el mismo nombre normalizado: candidatos, con evidencia por ficha.
$grupos = filas($db,
    "SELECT LOWER(TRIM(REGEXP_REPLACE(nombre, '[[:space:]]+', ' '))) nombre_normalizado, GROUP_CONCAT(id ORDER BY id) ids, COUNT(*) total
     FROM profesionales GROUP BY nombre_normalizado HAVING COUNT(*) > 1");
$candidatos = [];
foreach ($grupos as $grupo) {
    $ids = array_map('intval', explode(',', $grupo['ids']));
    $fichas = [];
    foreach ($ids as $id) {
        $f = filas($db, "SELECT p.id, p.nombre, p.activo, p.usuarioId, p.creado_en,
            (SELECT COUNT(*) FROM citas c WHERE c.profesionalId = p.id) citas_total,
            (SELECT COUNT(*) FROM citas c WHERE c.profesionalId = p.id AND c.estado = 'confirmada' AND c.fecha >= CURRENT_DATE) citas_futuras_confirmadas,
            (SELECT COUNT(*) FROM profesionales_servicios ps WHERE ps.profesionalId = p.id) servicios,
            (SELECT GROUP_CONCAT(ps.servicioId ORDER BY ps.servicioId) FROM profesionales_servicios ps WHERE ps.profesionalId = p.id) servicios_ids,
            (SELECT COUNT(*) FROM horarios_profesionales h WHERE h.profesionalId = p.id) horarios,
            (SELECT COUNT(*) FROM comisiones_movimientos m WHERE m.profesional_id = p.id) movimientos_comision,
            (SELECT COUNT(*) FROM perfil_fotos pf WHERE pf.usuarioId = p.usuarioId) fotos
            FROM profesionales p WHERE p.id = $id");
        $fichas[] = $f[0];
    }
    $pistas = [];
    for ($i = 1; $i < count($fichas); $i++) {
        $a = $fichas[$i - 1]; $b = $fichas[$i];
        $delta = abs(strtotime((string)$b['creado_en']) - strtotime((string)$a['creado_en']));
        $sinHistorial = (int)$a['citas_total'] === 0 && (int)$b['citas_total'] === 0 && (int)$a['movimientos_comision'] === 0 && (int)$b['movimientos_comision'] === 0;
        if ($delta <= 10 && $sinHistorial && $a['servicios_ids'] === $b['servicios_ids'] && $a['usuarioId'] === null && $b['usuarioId'] === null) {
            $pistas[] = "Fichas {$a['id']} y {$b['id']}: creadas con {$delta} s de diferencia, mismos servicios y sin historial → PROBABLE DOBLE ENVÍO (revisar).";
        }
    }
    $candidatos[] = [
        'nombre_normalizado' => $grupo['nombre_normalizado'],
        'fichas' => $fichas,
        'pistas' => $pistas,
        'recomendacion' => 'Revisión manual. Conserva la ficha con historial o cuenta vinculada; desactiva (no borres) la otra. '
            . 'Si ambas tienen citas futuras, reasigna con la agenda abierta para evitar solapamientos antes de consolidar.',
    ];
}
$informe['secciones']['profesionales_nombre_repetido'] = $candidatos;

// 3) Personas clientes que podrían repetirse (mismo nombre, apellido y teléfono con correos distintos).
$informe['secciones']['posibles_personas_repetidas'] = filas($db,
    "SELECT LOWER(TRIM(nombre)) nombre, LOWER(TRIM(apellido)) apellido, telefono, GROUP_CONCAT(id ORDER BY id) usuario_ids, COUNT(*) total
     FROM usuarios WHERE telefono IS NOT NULL AND telefono <> ''
     GROUP BY LOWER(TRIM(nombre)), LOWER(TRIM(apellido)), telefono HAVING COUNT(*) > 1");

// 4) Colaboradores (si la migración de RR. HH. ya existe).
$existeColab = filas($db, "SHOW TABLES LIKE 'colaboradores'");
if ($existeColab) {
    $informe['secciones']['colaboradores_correo_repetido'] = filas($db,
        "SELECT email_norm, GROUP_CONCAT(id ORDER BY id) colaborador_ids, COUNT(*) total FROM colaboradores GROUP BY email_norm HAVING COUNT(*) > 1");
}

if ($json) { echo json_encode($informe, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n"; exit(0); }

echo "DIAGNÓSTICO DE DUPLICADOS (solo lectura, " . $informe['generado_en'] . ")\n";
echo str_repeat('=', 64), "\n";
foreach ($informe['secciones'] as $nombre => $datos) {
    echo "\n[", $nombre, "] ", count($datos), " grupo(s)\n";
    foreach ($datos as $grupo) {
        if (isset($grupo['fichas'])) {
            echo "  • «{$grupo['nombre_normalizado']}»\n";
            foreach ($grupo['fichas'] as $f) {
                echo sprintf("      #%d activo=%s cuenta=%s alta=%s citas=%d (futuras confirmadas=%d) servicios=%d horarios=%d comisiones=%d\n",
                    $f['id'], $f['activo'], $f['usuarioId'] ?? '—', $f['creado_en'], $f['citas_total'], $f['citas_futuras_confirmadas'], $f['servicios'], $f['horarios'], $f['movimientos_comision']);
            }
            foreach ($grupo['pistas'] as $pista) { echo "      ! $pista\n"; }
            echo "      → {$grupo['recomendacion']}\n";
        } else {
            echo '  • ', json_encode($grupo, JSON_UNESCAPED_UNICODE), "\n";
        }
    }
}
echo "\nNada fue modificado. Ninguna coincidencia es prueba de duplicado por sí sola.\n";

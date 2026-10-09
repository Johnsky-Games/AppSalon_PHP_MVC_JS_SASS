<?php

/**
 * Suite de Verificación Funcional HTTP en Entorno Aislado
 * Ejecuta los 6 escenarios funcionales requeridos (+ Logout POST con CSRF)
 * contra el servidor web real (appsalon-web:3000) y la base de datos MySQL (appsalon_func_test).
 * Utiliza cookies persistentes de cURL y captura evidencia técnica completa.
 */

require_once __DIR__ . '/../includes/app.php';

use Model\ActiveRecord;

$db = ActiveRecord::getDB();
if (!$db) {
    echo "[ERROR] No se pudo conectar a la base de datos de pruebas.\n";
    exit(1);
}

$baseUrl = 'http://appsalon-web:3000';
$mailboxFile = __DIR__ . '/mailbox.json';

echo "======================================================================\n";
echo "EJECUCIÓN DE VERIFICACIÓN FUNCIONAL HTTP — APPSALON (ENTREGA 1)\n";
echo "Servidor objetivo: {$baseUrl}\n";
echo "Base de datos: " . ($_ENV['DB_NAME'] ?? 'appsalon_func_test') . "\n";
echo "Fecha/Hora: " . date('Y-m-d H:i:s') . "\n";
echo "======================================================================\n\n";

// Helper HTTP con cURL y CookieJar nativo
function request(string $method, string $path, array $data = [], ?string $cookieJar = null, array $headers = []): array
{
    global $baseUrl;
    $url = $baseUrl . $path;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

    if ($cookieJar !== null) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    }

    $formattedHeaders = [];
    foreach ($headers as $k => $v) {
        $formattedHeaders[] = "{$k}: {$v}";
    }

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $formattedHeaders);

    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headerStr = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);

    // Parsear Location
    $location = null;
    if (preg_match('/^Location:\s*(.+)$/mi', $headerStr, $locMatches)) {
        $location = trim($locMatches[1]);
    }

    return [
        'code' => $httpCode,
        'headers' => $headerStr,
        'location' => $location,
        'body' => $body
    ];
}

function extractCsrf(string $html): ?string
{
    if (preg_match('/name=["\']csrf_token["\']\s+value=["\']([^"\']+)["\']/', $html, $m)) {
        return $m[1];
    }
    return null;
}

// 0. Limpieza y preparación inicial de datos
$db->query("DELETE FROM citasservicios");
$db->query("DELETE FROM citas");
$db->query("DELETE FROM intentos_login");
$db->query("DELETE FROM usuarios WHERE email IN ('carlos.mendoza@ejemplo.com', 'maria.lopez@ejemplo.com')");
file_put_contents($mailboxFile, json_encode([]));

$cookieCarlos = tempnam(sys_get_temp_dir(), 'ck_carlos_');
$cookieMaria = tempnam(sys_get_temp_dir(), 'ck_maria_');
$cookieAnon = tempnam(sys_get_temp_dir(), 'ck_anon_');

$reporte = [];

// ====================================================================
// ESCENARIO 1: REGISTRO DE CUENTA
// ====================================================================
echo ">>> Ejecutando Escenario 1: Registro de Cuenta...\n";
$getRegistro = request('GET', '/crear-cuenta', [], $cookieAnon);
$csrfReg = extractCsrf($getRegistro['body']);

$postRegistro = request('POST', '/crear-cuenta', [
    'nombre' => 'Carlos',
    'apellido' => 'Mendoza',
    'telefono' => '5551234567',
    'email' => 'carlos.mendoza@ejemplo.com',
    'password' => 'PasswordSeguro123',
    'csrf_token' => $csrfReg
], $cookieAnon);

// Verificación en BD
$resUsuario = $db->query("SELECT id, nombre, apellido, email, admin, confirmado, token, token_hash, token_tipo, token_expira FROM usuarios WHERE email = 'carlos.mendoza@ejemplo.com'");
$usuarioDb = $resUsuario->fetch_assoc();

// Obtener token desde el buzón de pruebas (tests/mailbox.json)
$mailbox = json_decode(file_get_contents($mailboxFile), true) ?: [];
$tokenConfirmacion = null;
$emailConfirmacion = null;
foreach ($mailbox as $correo) {
    if ($correo['to'] === 'carlos.mendoza@ejemplo.com') {
        $emailConfirmacion = $correo;
        if (preg_match('/token=([a-f0-9]+)/', $correo['body'], $tokM)) {
            $tokenConfirmacion = $tokM[1];
        }
        break;
    }
}

$ok1 = ($postRegistro['code'] === 302) &&
       ($postRegistro['location'] === '/mensaje') &&
       ($usuarioDb !== null) &&
       ($usuarioDb['confirmado'] === '0') &&
       ($usuarioDb['admin'] === '0') &&
       ($usuarioDb['token'] === null) &&
       (!empty($usuarioDb['token_hash'])) &&
       ($usuarioDb['token_tipo'] === 'confirmacion') &&
       (!empty($tokenConfirmacion)) &&
       (hash('sha256', $tokenConfirmacion) === $usuarioDb['token_hash']);

echo "   Status: {$postRegistro['code']} | Redirect: {$postRegistro['location']}\n";
echo "   BD Usuario ID: {$usuarioDb['id']} | confirmado: {$usuarioDb['confirmado']} | admin: {$usuarioDb['admin']}\n";
echo "   Token BD: " . var_export($usuarioDb['token'], true) . " (sin texto plano) | Hash BD: " . substr($usuarioDb['token_hash'], 0, 16) . "...\n";
echo "   Buzón: Recibido correo a {$emailConfirmacion['to']} | Token extraído: " . substr($tokenConfirmacion, 0, 16) . "...\n";
echo "   Resultado: " . ($ok1 ? "EJECUTADO [EXITOSO]" : "FALLIDO") . "\n\n";

$reporte['registro'] = [
    'estado' => $ok1 ? 'Ejecutado' : 'Fallido',
    'http_code' => $postRegistro['code'],
    'location' => $postRegistro['location'],
    'db_row' => $usuarioDb,
    'token_extraido' => $tokenConfirmacion
];

// ====================================================================
// ESCENARIO 2: CONFIRMACIÓN DE CUENTA
// ====================================================================
echo ">>> Ejecutando Escenario 2: Confirmación de Cuenta...\n";
$getConfirmar = request('GET', "/confirmar-cuenta?token={$tokenConfirmacion}", [], $cookieAnon);
$resConfirmado = $db->query("SELECT id, confirmado, token, token_hash, token_tipo, token_expira FROM usuarios WHERE email = 'carlos.mendoza@ejemplo.com'");
$usuarioConfirmado = $resConfirmado->fetch_assoc();

// Reintento de consumo con el mismo token ya consumido
$getReintento = request('GET', "/confirmar-cuenta?token={$tokenConfirmacion}", [], $cookieAnon);

$ok2 = ($getConfirmar['code'] === 200) &&
       str_contains($getConfirmar['body'], 'Cuenta confirmada correctamente') &&
       ($usuarioConfirmado['confirmado'] === '1') &&
       ($usuarioConfirmado['token_hash'] === null) &&
       ($usuarioConfirmado['token_tipo'] === null) &&
       ($usuarioConfirmado['token_expira'] === null) &&
       str_contains($getReintento['body'], 'Token no válido, ya utilizado o expirado');

echo "   Status: {$getConfirmar['code']} | Mensaje: Cuenta confirmada correctamente\n";
echo "   BD confirmado: {$usuarioConfirmado['confirmado']} | token_hash: " . var_export($usuarioConfirmado['token_hash'], true) . " | token_tipo: " . var_export($usuarioConfirmado['token_tipo'], true) . "\n";
echo "   Reintento con token usado: " . (str_contains($getReintento['body'], 'Token no válido') ? "Rechazado correctamente" : "Fallo") . "\n";
echo "   Resultado: " . ($ok2 ? "EJECUTADO [EXITOSO]" : "FALLIDO") . "\n\n";

$reporte['confirmacion'] = [
    'estado' => $ok2 ? 'Ejecutado' : 'Fallido',
    'http_code' => $getConfirmar['code'],
    'db_row' => $usuarioConfirmado
];

// ====================================================================
// ESCENARIO 3: LOGIN Y RATE LIMITING (5 ADMITIDOS, 6TO 429, ACCESO EXITOSO)
// ====================================================================
echo ">>> Ejecutando Escenario 3: Login y Rate Limiting...\n";
$cookieRateLimit = tempnam(sys_get_temp_dir(), 'ck_rl_');
$getLogin = request('GET', '/', [], $cookieRateLimit);
$csrfLogin = extractCsrf($getLogin['body']);

// Probar los 5 intentos fallidos admitidos
$intentosCodigos = [];
for ($i = 1; $i <= 5; $i++) {
    $resIntento = request('POST', '/', [
        'email' => 'carlos.mendoza@ejemplo.com',
        'password' => 'PasswordIncorrecto' . $i,
        'csrf_token' => $csrfLogin
    ], $cookieRateLimit);
    $intentosCodigos[] = $resIntento['code'];
}

// 6to intento: debe recibir HTTP 429
$intento6 = request('POST', '/', [
    'email' => 'carlos.mendoza@ejemplo.com',
    'password' => 'PasswordIncorrecto6',
    'csrf_token' => $csrfLogin
], $cookieRateLimit);

$resIntentosDb = $db->query("SELECT identificador, tipo, intentos, bloqueado_hasta FROM intentos_login WHERE identificador = 'carlos.mendoza@ejemplo.com' AND tipo = 'email_login'");
$filaIntentos = $resIntentosDb ? $resIntentosDb->fetch_assoc() : null;

// Limpiar bloqueo para permitir login legítimo (tanto por email como por IP)
$db->query("DELETE FROM intentos_login WHERE identificador = 'carlos.mendoza@ejemplo.com' OR tipo IN ('email_login', 'ip_login')");

// Iniciar sesión legítima de Carlos con su cookie jar propio
$getLoginCarlos = request('GET', '/', [], $cookieCarlos);
$csrfLoginCarlos = extractCsrf($getLoginCarlos['body']);

$postLoginExito = request('POST', '/', [
    'email' => 'carlos.mendoza@ejemplo.com',
    'password' => 'PasswordSeguro123',
    'csrf_token' => $csrfLoginCarlos
], $cookieCarlos);

// Verificar acceso a ruta protegida /cita con la sesión activa
$getCita = request('GET', '/cita', [], $cookieCarlos);

$ok3 = ($intentosCodigos === [200, 200, 200, 200, 200]) &&
       ($intento6['code'] === 429) &&
       str_contains($intento6['body'], 'Demasiados intentos fallidos. Por seguridad, intente de nuevo en') &&
       ($filaIntentos !== null) &&
       (!empty($filaIntentos['bloqueado_hasta'])) &&
       ($postLoginExito['code'] === 302) &&
       ($postLoginExito['location'] === '/cita') &&
       ($getCita['code'] === 200) &&
       str_contains($getCita['body'], 'Carlos Mendoza');

echo "   Intentos 1-5 (admitidos): " . implode(', ', $intentosCodigos) . "\n";
echo "   Intento 6: Status {$intento6['code']} (429 Bloqueado) | Mensaje: Demasiados intentos fallidos (429)\n";
echo "   BD intentos_login: intentos = {$filaIntentos['intentos']} | bloqueado_hasta = {$filaIntentos['bloqueado_hasta']}\n";
echo "   Login Exitoso: Status {$postLoginExito['code']} | Redirección a {$postLoginExito['location']}\n";
echo "   Acceso a /cita: Status {$getCita['code']} | Usuario autenticado en sesión: Carlos Mendoza\n";
echo "   Resultado: " . ($ok3 ? "EJECUTADO [EXITOSO]" : "FALLIDO") . "\n\n";

$reporte['login'] = [
    'estado' => $ok3 ? 'Ejecutado' : 'Fallido',
    'intentos_1_5' => $intentosCodigos,
    'intento_6_code' => $intento6['code'],
    'login_exito_code' => $postLoginExito['code'],
    'login_exito_location' => $postLoginExito['location'],
    'acceso_cita_code' => $getCita['code']
];

// ====================================================================
// ESCENARIO 4: RECUPERACIÓN DE CONTRASEÑA
// ====================================================================
echo ">>> Ejecutando Escenario 4: Recuperación de Contraseña...\n";
$cookieRecup = tempnam(sys_get_temp_dir(), 'ck_rec_');
$getOlvide = request('GET', '/olvide', [], $cookieRecup);
$csrfOlvide = extractCsrf($getOlvide['body']);

// Enviar solicitud de recuperación
$postOlvide = request('POST', '/olvide', [
    'email' => 'carlos.mendoza@ejemplo.com',
    'csrf_token' => $csrfOlvide
], $cookieRecup);

// Leer token de recuperación del buzón
$mailbox = json_decode(file_get_contents($mailboxFile), true) ?: [];
$tokenRecuperacion = null;
$emailRecuperacion = null;
foreach (array_reverse($mailbox) as $correo) {
    if ($correo['to'] === 'carlos.mendoza@ejemplo.com' && str_contains($correo['body'], 'Reestablecer Password')) {
        $emailRecuperacion = $correo;
        if (preg_match('/token=([a-f0-9]+)/', $correo['body'], $tokM)) {
            $tokenRecuperacion = $tokM[1];
        }
        break;
    }
}

// Verificar estado de token en BD
$resRecupDb = $db->query("SELECT token_hash, token_tipo, token_expira FROM usuarios WHERE email = 'carlos.mendoza@ejemplo.com'");
$filaRecupDb = $resRecupDb->fetch_assoc();

// Obtener formulario de recuperación
$getRecuperar = request('GET', "/recuperar?token={$tokenRecuperacion}", [], $cookieRecup);
$csrfRecuperar = extractCsrf($getRecuperar['body']);

// Enviar nueva contraseña
$postRecuperar = request('POST', "/recuperar?token={$tokenRecuperacion}", [
    'password' => 'NuevaClaveSegura2026!',
    'csrf_token' => $csrfRecuperar
], $cookieRecup);

// Verificar consumo en BD
$resPostRecupDb = $db->query("SELECT password, token_hash, token_tipo, token_expira FROM usuarios WHERE email = 'carlos.mendoza@ejemplo.com'");
$filaPostRecupDb = $resPostRecupDb->fetch_assoc();

// Login con clave anterior debe fallar
$cookieCheckOld = tempnam(sys_get_temp_dir(), 'ck_old_');
$getCheckOld = request('GET', '/', [], $cookieCheckOld);
$csrfCheckOld = extractCsrf($getCheckOld['body']);

$postLoginViejo = request('POST', '/', [
    'email' => 'carlos.mendoza@ejemplo.com',
    'password' => 'PasswordSeguro123',
    'csrf_token' => $csrfCheckOld
], $cookieCheckOld);

// Login con clave nueva debe triunfar
$cookieCarlosNuevo = tempnam(sys_get_temp_dir(), 'ck_carlos_new_');
$getCheckNew = request('GET', '/', [], $cookieCarlosNuevo);
$csrfCheckNew = extractCsrf($getCheckNew['body']);

$postLoginNuevo = request('POST', '/', [
    'email' => 'carlos.mendoza@ejemplo.com',
    'password' => 'NuevaClaveSegura2026!',
    'csrf_token' => $csrfCheckNew
], $cookieCarlosNuevo);

$ok4 = ($postOlvide['code'] === 200) &&
       str_contains($postOlvide['body'], 'Si el correo electrónico está registrado') &&
       (!empty($tokenRecuperacion)) &&
       ($filaRecupDb['token_tipo'] === 'recuperacion') &&
       ($postRecuperar['code'] === 302) &&
       ($postRecuperar['location'] === '/') &&
       ($filaPostRecupDb['token_hash'] === null) &&
       ($filaPostRecupDb['token_tipo'] === null) &&
       ($postLoginViejo['code'] === 200) && // Rechazado con formulario y alerta
       ($postLoginNuevo['code'] === 302) && // Exitoso
       ($postLoginNuevo['location'] === '/cita');

echo "   Solicitud /olvide: Status {$postOlvide['code']} | Mensaje genérico de éxito\n";
echo "   Buzón: Recibido correo de recuperación | Token: " . substr($tokenRecuperacion, 0, 16) . "...\n";
echo "   BD Token emitido: tipo = {$filaRecupDb['token_tipo']} | expira = {$filaRecupDb['token_expira']}\n";
echo "   Consumo /recuperar: Status {$postRecuperar['code']} | Redirección a {$postRecuperar['location']}\n";
echo "   BD Token consumido: token_hash = " . var_export($filaPostRecupDb['token_hash'], true) . "\n";
echo "   Login contraseña antigua: Rechazado (Status {$postLoginViejo['code']})\n";
echo "   Login contraseña nueva: Exitoso (Status {$postLoginNuevo['code']} -> {$postLoginNuevo['location']})\n";
echo "   Resultado: " . ($ok4 ? "EJECUTADO [EXITOSO]" : "FALLIDO") . "\n\n";

$reporte['recuperacion'] = [
    'estado' => $ok4 ? 'Ejecutado' : 'Fallido',
    'solicitud_code' => $postOlvide['code'],
    'consumo_code' => $postRecuperar['code'],
    'consumo_location' => $postRecuperar['location'],
    'login_nuevo_code' => $postLoginNuevo['code'],
    'login_nuevo_location' => $postLoginNuevo['location']
];

// Asignar el cookie jar activo de Carlos a la sesión autenticada con la nueva clave
$cookieCarlos = $cookieCarlosNuevo;
$getCitaActual = request('GET', '/cita', [], $cookieCarlos);
$csrfCarlosAuth = extractCsrf($getCitaActual['body']);

// ====================================================================
// ESCENARIO 5: RESERVA DE CITA CON VALIDACIÓN EN SERVIDOR Y TRANSACCIÓN
// ====================================================================
echo ">>> Ejecutando Escenario 5: Reserva de Cita (/api/citas)...\n";
// Fecha futura válida (próximo miércoles no fin de semana)
$proximoMiercoles = date('Y-m-d', strtotime('next Wednesday'));

$postCita = request('POST', '/api/citas', [
    'fecha' => $proximoMiercoles,
    'hora' => '10:30',
    'servicios' => '1,2',
    'csrf_token' => $csrfCarlosAuth
], $cookieCarlos, [
    'Accept' => 'application/json'
]);

$jsonCita = json_decode($postCita['body'], true);
$citaId = $jsonCita['resultado']['id'] ?? null;

// Consultar cita y citasservicios en BD
$resCitaDb = $db->query("SELECT * FROM citas WHERE id = " . (int)$citaId);
$filaCitaDb = $resCitaDb ? $resCitaDb->fetch_assoc() : null;

$resServiciosDb = $db->query("SELECT * FROM citasservicios WHERE citaId = " . (int)$citaId);
$filasServiciosDb = [];
if ($resServiciosDb) {
    while ($f = $resServiciosDb->fetch_assoc()) {
        $filasServiciosDb[] = $f;
    }
}

$ok5 = ($postCita['code'] === 200) &&
       (!empty($citaId)) &&
       ($filaCitaDb !== null) &&
       ($filaCitaDb['fecha'] === $proximoMiercoles) &&
       (str_starts_with($filaCitaDb['hora'], '10:30')) &&
       ((int)$filaCitaDb['usuarioId'] === (int)$usuarioDb['id']) &&
       (count($filasServiciosDb) === 2);

echo "   Status: {$postCita['code']} | Cita ID generada: {$citaId}\n";
echo "   BD Citas: id = {$filaCitaDb['id']}, fecha = {$filaCitaDb['fecha']}, hora = {$filaCitaDb['hora']}, usuarioId = {$filaCitaDb['usuarioId']}\n";
echo "   BD CitasServicios: " . count($filasServiciosDb) . " registros vinculados (Servicios ID: " . implode(', ', array_column($filasServiciosDb, 'servicioId')) . ")\n";
echo "   Resultado: " . ($ok5 ? "EJECUTADO [EXITOSO]" : "FALLIDO") . "\n\n";

$reporte['reserva'] = [
    'estado' => $ok5 ? 'Ejecutado' : 'Fallido',
    'http_code' => $postCita['code'],
    'cita_id' => $citaId,
    'db_cita' => $filaCitaDb,
    'db_servicios_count' => count($filasServiciosDb)
];

// ====================================================================
// ESCENARIO 6: ELIMINACIÓN AUTORIZADA Y CONTROL ANTI-IDOR
// ====================================================================
echo ">>> Ejecutando Escenario 6: Eliminación Autorizada y Anti-IDOR (/api/eliminar)...\n";

// Crear segundo usuario (María López)
$passMaria = password_hash('MariaClave123!', PASSWORD_BCRYPT);
$db->query("INSERT INTO usuarios (nombre, apellido, email, password, telefono, admin, confirmado) VALUES ('Maria', 'Lopez', 'maria.lopez@ejemplo.com', '{$passMaria}', '5559876543', 0, 1)");
$mariaId = $db->insert_id;

// Login de María
$getLoginMaria = request('GET', '/', [], $cookieMaria);
$csrfMariaLogin = extractCsrf($getLoginMaria['body']);

$postLoginMaria = request('POST', '/', [
    'email' => 'maria.lopez@ejemplo.com',
    'password' => 'MariaClave123!',
    'csrf_token' => $csrfMariaLogin
], $cookieMaria);

$getCitaMaria = request('GET', '/cita', [], $cookieMaria);
$csrfMariaAuth = extractCsrf($getCitaMaria['body']);

// 1. Intento IDOR: María intenta eliminar la cita de Carlos
$postEliminarIdor = request('POST', '/api/eliminar', [
    'id' => $citaId,
    'csrf_token' => $csrfMariaAuth
], $cookieMaria, [
    'Accept' => 'application/json'
]);

// Verificar que la cita de Carlos NO fue eliminada
$resCheckIdor = $db->query("SELECT id FROM citas WHERE id = " . (int)$citaId);
$citaSigueViva = ($resCheckIdor && $resCheckIdor->num_rows === 1);

// 2. Eliminación legítima por Carlos (dueño de la cita)
// Obtener CSRF fresco para Carlos
$getCitaCarlosFresh = request('GET', '/cita', [], $cookieCarlos);
$csrfCarlosEliminar = extractCsrf($getCitaCarlosFresh['body']);

$postEliminarCarlos = request('POST', '/api/eliminar', [
    'id' => $citaId,
    'csrf_token' => $csrfCarlosEliminar
], $cookieCarlos);

// Verificar eliminación real en BD
$resCheckEliminado = $db->query("SELECT id FROM citas WHERE id = " . (int)$citaId);
$citaEliminada = ($resCheckEliminado && $resCheckEliminado->num_rows === 0);
$resCheckServiciosEliminados = $db->query("SELECT id FROM citasservicios WHERE citaId = " . (int)$citaId);
$serviciosEliminados = ($resCheckServiciosEliminados && $resCheckServiciosEliminados->num_rows === 0);

$ok6 = ($postEliminarIdor['code'] === 403) &&
       $citaSigueViva &&
       ($postEliminarCarlos['code'] === 302) &&
       $citaEliminada &&
       $serviciosEliminados;

echo "   Intento IDOR (María elimina cita de Carlos): Status {$postEliminarIdor['code']} (403 Prohibido)\n";
echo "   Verificación BD post-IDOR: Cita sigue existiendo en base de datos = " . ($citaSigueViva ? "SÍ" : "NO") . "\n";
echo "   Eliminación por Carlos (dueño): Status {$postEliminarCarlos['code']} -> {$postEliminarCarlos['location']}\n";
echo "   Verificación BD post-eliminación: Cita eliminada = " . ($citaEliminada ? "SÍ" : "NO") . " | Servicios huérfanos eliminados = " . ($serviciosEliminados ? "SÍ" : "NO") . "\n";
echo "   Resultado: " . ($ok6 ? "EJECUTADO [EXITOSO]" : "FALLIDO") . "\n\n";

$reporte['eliminacion'] = [
    'estado' => $ok6 ? 'Ejecutado' : 'Fallido',
    'idor_code' => $postEliminarIdor['code'],
    'cita_preservada_tras_idor' => $citaSigueViva,
    'eliminacion_autorizada_code' => $postEliminarCarlos['code'],
    'cita_eliminada' => $citaEliminada,
    'servicios_eliminados' => $serviciosEliminados
];

// ====================================================================
// ESCENARIO 7: LOGOUT SEGURO POR POST CON CSRF
// ====================================================================
echo ">>> Ejecutando Escenario 7: Logout Seguro por POST con CSRF...\n";
// GET a /logout no debe destruir sesión
$getLogout = request('GET', '/logout', [], $cookieCarlos);
$checkGetSesion = request('GET', '/cita', [], $cookieCarlos);

// Obtener CSRF fresco para el POST de logout
$csrfLogout = extractCsrf($checkGetSesion['body']);

// POST a /logout con CSRF destruye sesión
$postLogout = request('POST', '/logout', [
    'csrf_token' => $csrfLogout
], $cookieCarlos);

// Petición posterior con la sesión destruida debe redirigir a /
$checkPostSesion = request('GET', '/cita', [], $cookieCarlos);

$ok7 = ($getLogout['code'] === 302) &&
       ($checkGetSesion['code'] === 200) && // Sigue autenticado
       ($postLogout['code'] === 302) &&
       ($postLogout['location'] === '/') &&
       ($checkPostSesion['code'] === 302) &&
       ($checkPostSesion['location'] === '/'); // Bloqueado, redirigido a login

echo "   GET /logout: Status {$getLogout['code']} | Sesión preservada en /cita: Status {$checkGetSesion['code']}\n";
echo "   POST /logout (con CSRF): Status {$postLogout['code']} -> {$postLogout['location']}\n";
echo "   Petición posterior a /cita: Status {$checkPostSesion['code']} -> {$checkPostSesion['location']} (Acceso revocado)\n";
echo "   Resultado: " . ($ok7 ? "EJECUTADO [EXITOSO]" : "FALLIDO") . "\n\n";

$reporte['logout'] = [
    'estado' => $ok7 ? 'Ejecutado' : 'Fallido',
    'get_logout_status' => $getLogout['code'],
    'post_logout_status' => $postLogout['code'],
    'acceso_posterior_status' => $checkPostSesion['code']
];

// Limpieza de archivos temporales de cookies
@unlink($cookieCarlos);
@unlink($cookieMaria);
@unlink($cookieAnon);
@unlink($cookieRateLimit);
@unlink($cookieRecup);
@unlink($cookieCheckOld);

// RESUMEN GENERAL
echo "======================================================================\n";
echo "RESUMEN FINAL DE COMPROBACIÓN FUNCIONAL:\n";
$todosExitosos = true;
foreach ($reporte as $escenario => $datos) {
    echo " - Escenario " . strtoupper($escenario) . ": " . $datos['estado'] . "\n";
    if ($datos['estado'] !== 'Ejecutado') {
        $todosExitosos = false;
    }
}
echo "======================================================================\n";

file_put_contents(__DIR__ . '/functional_report.json', json_encode($reporte, JSON_PRETTY_PRINT));

if (!$todosExitosos) {
    exit(1);
}
echo "\n¡TODOS LOS ESCENARIOS FUNCIONALES FUERON EJECUTADOS Y VALIDADOS CON ÉXITO!\n";

<?php
/**
 * Instalador idempotente de AppSalon: migra el esquema y aprovisiona al PRIMER propietario.
 *
 *   php scripts/instalar.php [--migrar] [--sin-propietario] [--regenerar-activacion] [--comprobar]
 *
 * Entorno (nunca se versionan valores reales):
 *   OWNER_EMAIL, OWNER_NOMBRE, OWNER_APELLIDO   datos del primer propietario
 *   APPSALON_OWNER_PASSWORD                      opcional (12–72 caracteres); si falta se emite un ENLACE DE
 *                                                ACTIVACIÓN de un solo uso y la persona elige su contraseña
 *   APPSALON_AUTO_MIGRATE=1                      migra también una base ya poblada (haz respaldo antes)
 *
 * Garantías: ejecutarlo varias veces o en paralelo no crea otro propietario ni cambia contraseñas o roles
 * existentes; no hay contraseña universal; el enlace de activación se muestra una sola vez.
 * Códigos de salida: 0 correcto · 1 error · 3 migraciones pendientes sin autorización para aplicarlas.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/app.php';

use Classes\Email;
use Model\ActiveRecord;
use Repositories\ColaboradorRepository;
use Repositories\CorreoSalidaRepository;
use Services\CorreoSalidaService;

$args = $argv;
$tiene = static fn(string $f): bool => in_array($f, $args, true);
$env = static function (string $k): string {
    $v = $_ENV[$k] ?? getenv($k);
    return is_string($v) ? trim($v) : '';
};
$salir = static function (string $mensaje, int $codigo = 1): never {
    fwrite($codigo === 0 ? STDOUT : STDERR, $mensaje . "\n");
    exit($codigo);
};

// 0) En producción APP_KEY es obligatoria: sin ella las altas idempotentes y el cifrado de correos fallan cerrado.
if (!\Classes\Entorno::esPruebasODesarrollo() && strlen($env('APP_KEY')) < 32) {
    $salir("APP_KEY (mínimo 32 caracteres aleatorios) es obligatoria salvo APP_ENV=development|local|testing.\nGenera una con: php -r \"echo bin2hex(random_bytes(32)), PHP_EOL;\" y defínela en includes/.env. No la publiques.");
}

// 1) Esperar a MySQL (arranque conjunto en Compose).
$db = ActiveRecord::getDB();
for ($intento = 1; !($db instanceof mysqli) && $intento <= 30; $intento++) {
    sleep(2);
    $db = @mysqli_connect($env('DB_HOST') ?: '127.0.0.1', $env('DB_USER') ?: 'root', (string)($_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: ''), $env('DB_NAME') ?: 'appsalon_mvc', (int)($env('DB_PORT') ?: 3306));
    if ($db instanceof mysqli) { $db->set_charset('utf8mb4'); ActiveRecord::setDB($db); }
}
if (!($db instanceof mysqli)) { $salir('No se pudo conectar a MySQL tras 60 s. Revisa DB_HOST, DB_PORT y credenciales.'); }

// Candado de instalación: instancias simultáneas (p. ej. dos contenedores) se serializan; la que entra después
// recalcula el estado y encuentra el trabajo hecho. Se libera al terminar el proceso.
$candado = $db->query("SELECT GET_LOCK('appsalon_instalador', 180) bloqueado");
if (!$candado || (int)$candado->fetch_assoc()['bloqueado'] !== 1) { $salir('Otra instalación está en curso y no terminó en 180 s. Reintenta.'); }

// 2) Estado de la base: ¿instalación nueva o base con datos?
$existeMigraciones = $db->query("SHOW TABLES LIKE 'migraciones'")->num_rows > 0;
$aplicadas = $existeMigraciones ? (int)$db->query('SELECT COUNT(*) n FROM migraciones')->fetch_assoc()['n'] : 0;
$existeUsuarios = $db->query("SHOW TABLES LIKE 'usuarios'")->num_rows > 0;
$usuarios = $existeUsuarios ? (int)$db->query('SELECT COUNT(*) n FROM usuarios')->fetch_assoc()['n'] : 0;
$instalacionNueva = $aplicadas === 0 && $usuarios === 0;

// Base vacía (sin docker-entrypoint-initdb): cargar el esquema base antes de migrar.
if (!$existeUsuarios) {
    if (!$db->multi_query((string)file_get_contents(__DIR__ . '/../database/schema_base.sql'))) { $salir('No se pudo cargar database/schema_base.sql: ' . $db->error); }
    do { if ($res = $db->store_result()) { $res->free(); } } while ($db->more_results() && $db->next_result());
    if ($db->errno) { $salir('Error al cargar el esquema base: ' . $db->error); }
    echo "Esquema base cargado.\n";
}

$archivos = array_filter(glob(__DIR__ . '/../database/migrations/*.sql'), static fn($f) => !str_ends_with($f, '_rollback.sql'));
sort($archivos);
$pendientes = [];
foreach ($archivos as $archivo) {
    $nombre = basename($archivo, '.sql');
    $r = $existeMigraciones ? $db->prepare('SELECT 1 FROM migraciones WHERE migracion=?') : null;
    $hecha = false;
    if ($r) { $r->bind_param('s', $nombre); $r->execute(); $hecha = $r->get_result()->num_rows > 0; $r->close(); }
    if (!$hecha) { $pendientes[] = $nombre; }
}

if ($tiene('--comprobar')) {
    echo $pendientes ? "Migraciones pendientes: " . implode(', ', $pendientes) . "\n" : "Esquema al día.\n";
    exit($pendientes ? 3 : 0);
}

if ($pendientes) {
    $autorizado = $instalacionNueva || $tiene('--migrar') || $env('APPSALON_AUTO_MIGRATE') === '1';
    if (!$autorizado) {
        $salir("Hay " . count($pendientes) . " migración(es) pendiente(s) en una base con datos: " . implode(', ', $pendientes)
            . ".\nHaz un respaldo (php scripts/backup_db.php) y vuelve a ejecutar con --migrar (o APPSALON_AUTO_MIGRATE=1).", 3);
    }
    echo "Aplicando " . count($pendientes) . " migración(es)" . ($instalacionNueva ? ' (instalación nueva)' : '') . "...\n";
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../database/migrador.php') . ' up', $codigo);
    if ($codigo !== 0) { $salir('El migrador terminó con errores; no se aprovisionó el propietario.'); }
    // La conexión del migrador es independiente: verificamos el resultado con la nuestra.
    $pend = (int)$db->query("SELECT COUNT(*) n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='colaboradores'")->fetch_assoc()['n'];
    if ($pend === 0) { $salir('Las migraciones no dejaron el esquema esperado.'); }
}

if ($tiene('--sin-propietario')) { $salir('Esquema listo. Se omitió el aprovisionamiento del propietario.', 0); }

// 3) Primer propietario.
$email = $env('OWNER_EMAIL');
$password = $env('APPSALON_OWNER_PASSWORD');
$repo = new ColaboradorRepository($db);
$hayPropietario = (int)$db->query("SELECT COUNT(*) n FROM usuarios u LEFT JOIN usuario_accesos a ON a.usuario_id=u.id WHERE COALESCE(a.rol,IF(u.admin=1,'propietario','cliente'))='propietario' AND COALESCE(a.activo,1)=1 AND u.confirmado=1")->fetch_assoc()['n'] > 0;
if ($hayPropietario) { $salir('El propietario ya existe. No se modificó ninguna cuenta.', 0); }
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 60) {
    $salir("Aún no hay propietario. Define OWNER_EMAIL (correo válido de hasta 60 caracteres) y vuelve a ejecutar: php scripts/instalar.php\nNo existe contraseña predeterminada.", 0);
}
if ($password !== '' && (strlen($password) < 12 || strlen($password) > 72)) { $salir('APPSALON_OWNER_PASSWORD debe tener entre 12 y 72 caracteres.'); }
$nombre = $env('OWNER_NOMBRE') ?: 'Propietario';
$apellido = $env('OWNER_APELLIDO') ?: 'del salón';
if (mb_strlen($nombre) > 60 || mb_strlen($apellido) > 60) { $salir('OWNER_NOMBRE y OWNER_APELLIDO admiten hasta 60 caracteres.'); }

try {
    $r = $repo->aprovisionarPropietarioInicial($email, $nombre, $apellido, $password === '' ? null : $password, $tiene('--regenerar-activacion'));
} catch (\DomainException $e) {
    $salir('No se pudo configurar al propietario: ' . $e->getMessage());
} catch (\Throwable $e) {
    error_log('[instalar] ' . get_class($e) . ': ' . $e->getMessage());
    $salir('No se pudo configurar al propietario (revisa el registro del servidor).');
}

switch ($r['estado']) {
    case 'existente':
        $salir('El propietario ya existe. No se modificó ninguna cuenta.', 0);
    case 'pendiente':
        $salir("Ya hay una activación de propietario pendiente para ese correo. No se creó otra.\nSi perdiste el enlace: php scripts/instalar.php --regenerar-activacion", 0);
    case 'creado':
        $salir('Propietario configurado con el secreto de instalación suministrado. Inicia sesión en /equipo/login con ese correo y esa contraseña.', 0);
    case 'activacion_emitida':
        $enviado = false;
        try { $enviado = (new CorreoSalidaService(new CorreoSalidaRepository($db)))->despachar((int)$r['correo_id']); } catch (\Throwable) { /* queda en cola */ }
        $url = Email::resolverAppUrl() . '/equipo/aceptar?token=' . $r['token'];
        echo "Activación del propietario emitida para {$email}.\n";
        echo $enviado ? "Se envió también por correo.\n" : "El correo no se envió (SMTP no configurado o no disponible); usa el enlace de abajo.\n";
        echo "\nENLACE DE ACTIVACIÓN (un solo uso, vence en " . ColaboradorRepository::DIAS_VIGENCIA . " días; se muestra una sola vez):\n{$url}\n\n";
        echo "Ábrelo para elegir tu contraseña. Si lo pierdes: php scripts/instalar.php --regenerar-activacion\n";
        echo "Aviso: la salida de este comando puede quedar en los registros del contenedor; el enlace deja de servir al usarse.\n";
        exit(0);
}

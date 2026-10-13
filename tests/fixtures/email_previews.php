<?php

declare(strict_types=1);

/**
 * Vistas previas de correo con datos ficticios explícitos, sin envíos ni consultas/escrituras de BD.
 * Uso: php tests/fixtures/email_previews.php /ruta/a/artefactos/email-previews
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../../vendor/autoload.php';

use Classes\Email;
use Classes\EmailTemplate;
use Model\ActiveRecord;
use Repositories\CitaRepository;
use Repositories\NotificacionCitaRepository;
use Services\NotificacionCitaService;

$directorio = $argv[1] ?? (getenv('E2E_ARTIFACT_DIR') ? rtrim((string)getenv('E2E_ARTIFACT_DIR'), '/\\') . '/email-previews' : '');
if ($directorio === '') {
    fwrite(STDERR, "Indica un directorio temporal de artefactos para las vistas previas.\n");
    exit(1);
}
if (!is_dir($directorio) && !mkdir($directorio, 0777, true) && !is_dir($directorio)) {
    throw new RuntimeException('No se pudo crear el directorio de artefactos de correo.');
}
$directorio = (string)realpath($directorio);

// Configuración inyectada; nunca se lee la identidad ni los contactos de un negocio real.
ActiveRecord::setDB(null);
$_ENV['APP_URL'] = 'https://salon.example';
$ajustes = [
    'nombre_comercial' => 'Salón de ejemplo · QA',
    'contacto' => 'Recepción de ejemplo para pruebas',
    'telefono' => '+593 000 0000000',
    'direccion' => 'Dirección ficticia para la vista previa de pruebas',
    'email_contacto' => 'recepcion@example.test',
];
$plantilla = new EmailTemplate($ajustes);
$cliente = 'María Fernanda Ejemplo';
$correo = 'maria@example.test';
$token = str_repeat('abcdef1234567890', 6) . '+/;&';
$email = new Email($cliente, $correo, $token);
$manifest = [];

$guardar = static function (string $tipo, string $html, string $texto, string $boton, string $enlace, array $datosEsperados) use ($directorio, &$manifest): void {
    file_put_contents($directorio . '/' . $tipo . '.html', $html);
    file_put_contents($directorio . '/' . $tipo . '.txt', $texto);
    $manifest[] = [
        'tipo' => $tipo,
        'html' => $tipo . '.html',
        'texto' => $tipo . '.txt',
        'boton' => $boton,
        'enlace' => $enlace,
        'datos_esperados' => $datosEsperados,
    ];
};

foreach (['confirmacion' => 'Confirmar mi cuenta', 'recuperacion' => 'Restablecer mi contraseña', 'invitacion' => 'Establecer mi contraseña'] as $tipo => $boton) {
    $ruta = $tipo === 'confirmacion' ? '/confirmar-cuenta' : '/recuperar';
    $enlace = 'https://salon.example' . $ruta . '?token=' . rawurlencode($token);
    $mailer = $email->prepararMailer($tipo, $enlace, $plantilla);
    $guardar($tipo, $mailer->Body, $mailer->AltBody, $boton, $enlace, [$ajustes['nombre_comercial'], $cliente, $correo, $ajustes['contacto'], $ajustes['telefono'], $ajustes['direccion'], $ajustes['email_contacto']]);
}

// Mocks del backing store: construirContenidoHtml no debe usar ningún repositorio para renderizar.
$repoNotificaciones = (new ReflectionClass(NotificacionCitaRepository::class))->newInstanceWithoutConstructor();
$repoCitas = (new ReflectionClass(CitaRepository::class))->newInstanceWithoutConstructor();
$notificaciones = new NotificacionCitaService($repoNotificaciones, $repoCitas);
$payload = [
    'cita_id' => 42,
    'cliente_nombre' => $cliente,
    'fecha' => '2026-12-20',
    'hora_inicio' => '09:30',
    'hora_fin' => '11:00',
    'duracion_total_minutos' => 90,
    'profesional_nombre' => 'Profesional de ejemplo',
    'total_formateado' => '55.00',
    'cancelado_por' => 'cliente',
    'servicios' => [
        ['nombre' => 'Corte de cabello (ejemplo)', 'precio' => '25.00', 'duracion_minutos' => 30],
        ['nombre' => 'Tratamiento capilar (ejemplo)', 'precio' => '30.00', 'duracion_minutos' => 60],
    ],
];
foreach (['confirmacion', 'cancelacion', 'recordatorio'] as $tipo) {
    $contenido = $notificaciones->construirContenidoHtml($tipo, $payload, null, $plantilla);
    $boton = $tipo === 'cancelacion' ? 'Agendar una nueva cita' : 'Ver mis citas';
    $guardar('cita-' . $tipo, $contenido['html'], $contenido['texto'], $boton, $contenido['enlace'], [
        $ajustes['nombre_comercial'], $cliente, '#42', '2026-12-20', '09:30 - 11:00 (90 min)',
        'Profesional de ejemplo', 'Corte de cabello (ejemplo) (30 min)', 'Tratamiento capilar (ejemplo) (60 min)', '$55.00', $ajustes['email_contacto'],
    ]);
}

$nombreLargo = str_repeat('MaríaFernanda', 12);
$profesionalLargo = str_repeat('ProfesionalDeEjemplo', 12);
$servicioLargo = str_repeat('TratamientoDeEjemplo', 12);
$enlaceLargo = 'https://salon.example/recuperar?token=' . str_repeat('abcdef0123456789', 45);
$botonLargo = 'Consultar y gestionar todas mis próximas reservas';
$stress = (new EmailTemplate(array_merge($ajustes, [
    'nombre_comercial' => 'Empresa ficticia de ejemplo para probar la lectura de nombres comerciales largos',
    'direccion' => str_repeat('DirecciónFicticiaParaPruebas', 12),
])))->render([
    'titulo' => 'Confirmación de Reserva #1234567890',
    'nombre' => $nombreLargo,
    'parrafos' => ['Esta vista previa utiliza únicamente datos ficticios para probar los límites de la plantilla.'],
    'detalles' => ['Profesional' => $profesionalLargo, 'Horario' => '10:00 - 11:30 (90 min)'],
    'servicios' => [['nombre' => $servicioLargo, 'precio' => '123.45', 'duracion_minutos' => 90]],
    'enlace' => $enlaceLargo,
    'boton' => $botonLargo,
    'total' => '$123.45',
]);
$guardar('stress', $stress['html'], $stress['texto'], $botonLargo, $enlaceLargo, [$nombreLargo, $profesionalLargo, $servicioLargo, '$123.45']);

file_put_contents($directorio . '/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo 'Vistas previas ficticias generadas: ' . count($manifest) . " (sin envío ni BD).\n";

<?php

namespace Classes;

use PHPMailer\PHPMailer\PHPMailer;

class Email
{
    public $nombre;
    public $email;
    public $token;
    /** Detalles adicionales visibles en el correo (p. ej. rol, portal). Nunca secretos. */
    public array $detallesExtra = [];
    /** Portal al que pertenece la cuenta: 'cliente' o 'equipo'. Cambia textos y enlaces del correo. */
    public string $portal = 'cliente';
    /** Motivo saneado (sin tokens ni credenciales) del último envío fallido; null si no hubo fallo. */
    public ?string $errorEnvio = null;

    /**
     * Transporte simulado para pruebas automatizadas (evita envíos reales).
     * @var callable|null
     */
    protected static $transport = null;

    /**
     * Registro de correos despachados en memoria durante pruebas.
     */
    public static array $emailsEnviados = [];

    public function __construct($nombre, $email, $token)
    {
        $this->nombre = $nombre;
        $this->email = $email;
        $this->token = $token;
    }

    /**
     * Permite inyectar un transporte simulado para pruebas unitarias e integrales.
     */
    public static function setTransport(?callable $transport): void
    {
        self::$transport = $transport;
    }

    /**
     * Obtiene el transporte sustituible activo (si fue configurado).
     */
    public static function getTransport(): ?callable
    {
        return self::$transport;
    }

    /**
     * Limpia el registro de correos simulados en memoria.
     */
    public static function limpiarEmailsEnviados(): void
    {
        self::$emailsEnviados = [];
    }

    /**
     * Valida y sanea `APP_URL` permitiendo exclusivamente esquemas `http`/`https` y un host válido,
     * rechazando vectores como `javascript:`, `data:`, saltos de línea o comillas, con fallback seguro
     * a `http://localhost:3000`.
     */
    public static function resolverAppUrl(?string $appUrlOverride = null): string
    {
        $fallback = 'http://localhost:3000';
        $candidato = $appUrlOverride;

        if ($candidato === null) {
            if (isset($_ENV['APP_URL']) && is_string($_ENV['APP_URL'])) {
                $candidato = $_ENV['APP_URL'];
            } else {
                $envVal = getenv('APP_URL');
                $candidato = is_string($envVal) && $envVal !== '' ? $envVal : $fallback;
            }
        }

        $limpio = trim($candidato);
        if ($limpio === '' || preg_match('/[\r\n\x00-\x20<>"\'`]/', $limpio) === 1) {
            return $fallback;
        }

        if (filter_var($limpio, FILTER_VALIDATE_URL) === false) {
            return $fallback;
        }

        $partes = parse_url($limpio);
        if (!is_array($partes)) {
            return $fallback;
        }

        $esquema = strtolower((string)($partes['scheme'] ?? ''));
        $host = trim((string)($partes['host'] ?? ''));
        if (!in_array($esquema, ['http', 'https'], true) || $host === '') {
            return $fallback;
        }

        if (!empty($partes['user']) || !empty($partes['pass'])) {
            return $fallback;
        }

        $puerto = isset($partes['port']) ? ':' . (int)$partes['port'] : '';
        $ruta = isset($partes['path']) ? rtrim((string)$partes['path'], '/') : '';

        return "{$esquema}://{$host}{$puerto}{$ruta}";
    }

    /**
     * Valida y sanea la dirección y el nombre del remitente (`MAIL_FROM_ADDRESS` / `EMAIL_FROM`,
     * `MAIL_FROM_NAME` / `EMAIL_FROM_NAME`) eliminando saltos de línea y caracteres de control.
     *
     * @return array{email: string, nombre: string}
     */
    public static function resolverRemitente(
        ?string $fromEmailOverride = null,
        ?string $fromNameOverride = null,
        string $fallbackEmail = 'cuentas@appsalon.com',
        string $fallbackName = 'AppSalon.com'
    ): array {
        $candidatoEmail = $fromEmailOverride;
        if ($candidatoEmail === null) {
            $candidatoEmail = $_ENV['MAIL_FROM_ADDRESS']
                ?? ($_ENV['EMAIL_FROM']
                ?? (getenv('MAIL_FROM_ADDRESS') ?: (getenv('EMAIL_FROM') ?: $fallbackEmail)));
        }

        $emailLimpio = preg_replace('/[\r\n\x00-\x1F\x7F]+/', '', trim((string)$candidatoEmail));
        if ($emailLimpio === '' || filter_var($emailLimpio, FILTER_VALIDATE_EMAIL) === false) {
            $emailLimpio = $fallbackEmail;
        }

        $candidatoNombre = $fromNameOverride;
        if ($candidatoNombre === null) {
            $candidatoNombre = $_ENV['MAIL_FROM_NAME']
                ?? ($_ENV['EMAIL_FROM_NAME']
                ?? (getenv('MAIL_FROM_NAME') ?: (getenv('EMAIL_FROM_NAME') ?: $fallbackName)));
        }

        $nombreLimpio = preg_replace('/[\r\n\x00-\x1F\x7F]+/', ' ', trim((string)$candidatoNombre));
        $nombreLimpio = trim((string)$nombreLimpio, " \t\"'");
        if ($nombreLimpio === '') {
            $nombreLimpio = $fallbackName;
        }

        return [
            'email' => $emailLimpio,
            'nombre' => mb_substr($nombreLimpio, 0, 120, 'UTF-8')
        ];
    }

    /**
     * Contraseña SMTP. La plantilla de entorno y Docker Compose usan `EMAIL_PASS`; el nombre histórico del
     * código es `EMAIL_PASSWORD`. Se aceptan ambos (EMAIL_PASSWORD tiene prioridad) para que una instalación
     * hecha según la plantilla autentique el envío.
     */
    public static function resolverPasswordSmtp(): string
    {
        foreach (['EMAIL_PASSWORD', 'EMAIL_PASS'] as $nombre) {
            $valor = $_ENV[$nombre] ?? getenv($nombre);
            if (is_string($valor) && $valor !== '') {
                return $valor;
            }
        }
        return '';
    }

    /**
     * Sanea texto para cabeceras de correo (asunto, nombres) eliminando saltos de línea y caracteres de control.
     */
    public static function sanearTextoCabecera(string $valor, int $maxLongitud = 180): string
    {
        $limpio = preg_replace('/[\r\n\x00-\x1F\x7F]+/', ' ', trim($valor));
        return mb_substr(trim((string)$limpio), 0, $maxLongitud, 'UTF-8');
    }

    /**
     * Sanea un mensaje de error de transporte para registro seguro en base de datos y logs: sin saltos de línea,
     * sin secuencias que parezcan tokens (32+ caracteres hexadecimales/base64url), sin `token=...` de enlaces y sin
     * el usuario ni la contraseña SMTP configurados, que un servidor o una traza podrían repetir.
     */
    public static function sanearMensajeError(string $mensaje): string
    {
        $limpio = preg_replace('/[\r\n\x00-\x1F\x7F]+/', ' ', trim($mensaje));
        $texto = trim((string)$limpio);
        foreach ([self::resolverPasswordSmtp(), (string)($_ENV['EMAIL_USER'] ?? getenv('EMAIL_USER') ?: '')] as $secreto) {
            if (strlen($secreto) >= 4) {
                $texto = str_replace([$secreto, base64_encode($secreto)], '***', $texto);
            }
        }
        $texto = preg_replace('/(token|password|pass|pwd|secret)=[^\s&"\']+/i', '$1=***', $texto) ?? $texto;
        $texto = preg_replace('/\b[A-Za-z0-9_\-]{32,}\b/', '[redactado]', $texto) ?? $texto;
        // El servidor SMTP repite la dirección del destinatario en sus rechazos: es dato personal y no debe quedar en la cola ni en logs.
        // Parte local entre comillas («"a@b"@dominio») y, después, cualquier «algo@dominio» (nombre de host o literal de IP entre corchetes).
        $texto = preg_replace('/"(?:[^"\\\\]|\\\\.)*"@(?:\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9.\-]+)/u', '[correo]', $texto) ?? $texto;
        $texto = preg_replace('/[^\s<>(),;:"\[\]\\\\]+@(?:\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9.\-]+)/u', '[correo]', $texto) ?? $texto;
        if ($texto === '') {
            $texto = 'Error desconocido en transporte de correo.';
        }
        return mb_substr($texto, 0, 250, 'UTF-8');
    }

    /**
     * Construye y configura la instancia completa de PHPMailer con remitente, destinatario,
     * asunto y cuerpo HTML para el propósito especificado.
     * Permite inspección y validación del mensaje real antes o durante el despacho.
     * La plantilla opcional permite vistas previas con ajustes ficticios sin usar la BD.
     */
    public function prepararMailer(string $proposito, string $enlace, ?EmailTemplate $plantilla = null): PHPMailer
    {
        $email = new PHPMailer(true);
        $email->isSMTP();
        $email->Host = (string)($_ENV['EMAIL_HOST'] ?? (getenv('EMAIL_HOST') ?: 'localhost'));
        $email->SMTPAuth = true;
        $email->Port = (int)($_ENV['EMAIL_PORT'] ?? (getenv('EMAIL_PORT') ?: 25));
        $email->Username = (string)($_ENV['EMAIL_USER'] ?? (getenv('EMAIL_USER') ?: ''));
        $email->Password = self::resolverPasswordSmtp();
        $email->Timeout = 5;
        // El saludo del servidor se espera hasta SMTP::$Timelimit (300 s por defecto): se acota para no colgar peticiones.
        $email->getSMTPInstance()->Timelimit = 10;

        $remitente = self::resolverRemitente();
        $email->setFrom($remitente['email'], $remitente['nombre']);

        // Destinatario legítimo: correo y nombre del usuario
        $email->addAddress($this->email, self::sanearTextoCabecera((string)$this->nombre, 120));
        $email->isHTML(true);
        $email->CharSet = 'UTF-8';

        $mensajes = [
            'confirmacion' => [
                'asunto' => 'Confirma tu cuenta',
                'categoria' => 'BIENVENIDO A TU SALÓN',
                'titulo' => 'Tu próxima visita empieza aquí',
                'preheader' => 'Confirma tu correo para activar tu cuenta y gestionar tus reservas.',
                'parrafos' => ['Gracias por crear tu cuenta. Abre el enlace y elige tu contraseña definitiva para activarla: la que escribiste al registrarte era provisional y se descarta.'],
                'boton' => 'Elegir mi contraseña y activar',
                'nota' => 'Este enlace es personal y de un solo uso. Si no creaste esta cuenta, puedes ignorar este mensaje.',
            ],
            'recuperacion' => [
                'asunto' => 'Reestablece tu password',
                'categoria' => 'ACCESO SEGURO',
                'titulo' => 'Recupera el acceso a tu cuenta',
                'preheader' => 'Crea una nueva contraseña de forma segura con este enlace personal.',
                'parrafos' => ['Recibimos una solicitud para restablecer tu contraseña. Abre el siguiente enlace y elige una nueva para volver a ingresar.'],
                'boton' => 'Restablecer mi contraseña',
                'nota' => 'Si no solicitaste este cambio, ignora el mensaje: tu contraseña seguirá siendo la misma. Por seguridad, no compartas este enlace.',
            ],
            'invitacion' => [
                'asunto' => 'Tu invitación al equipo',
                'categoria' => 'BIENVENIDO AL EQUIPO',
                'titulo' => 'Tu espacio de trabajo está listo',
                'preheader' => 'Acepta tu invitación para activar tu acceso al equipo.',
                'parrafos' => ['La administración del salón te ha invitado a formar parte de su equipo. Acepta la invitación para activar tu acceso laboral y consultar las herramientas disponibles para tu rol. Hasta que la aceptes no tendrás permisos de equipo.'],
                'boton' => 'Aceptar mi invitación',
                'nota' => 'Este enlace es personal, de un solo uso y vence en 7 días. Si no esperabas esta invitación, ignora este mensaje o contacta con la administración del salón. Tu cuenta de cliente, si la tienes, no cambia.',
            ],
            'cuenta_existente' => [
                'asunto' => 'Intento de registro con tu correo',
                'categoria' => 'AVISO DE SEGURIDAD',
                'titulo' => 'Ya tienes una cuenta con este correo',
                'preheader' => 'Alguien intentó crear una cuenta con tu correo; la tuya ya existe.',
                'parrafos' => ['Alguien intentó crear una cuenta con este correo, pero ya tienes una. Si fuiste tú, ingresa con tu contraseña o usa «Olvidé mi contraseña». Tu cuenta no cambió.'],
                'boton' => 'Ir a iniciar sesión',
                'nota' => 'Si no fuiste tú, no tienes que hacer nada: nadie obtuvo acceso a tu cuenta. Este aviso se envía como máximo una vez por hora.',
            ],
        ];
        if (!isset($mensajes[$proposito])) {
            throw new \InvalidArgumentException('Propósito de correo no válido.');
        }
        $mensaje = $mensajes[$proposito];
        $email->Subject = $mensaje['asunto'];
        $mensaje['nombre'] = (string)$this->nombre;
        $mensaje['detalles'] = ['Tu cuenta' => (string)$this->email] + $this->detallesExtra;
        $mensaje['enlace'] = $enlace;
        $mensaje['aviso'] = 'Recibiste este correo porque se solicitó una acción relacionada con tu cuenta.';
        $contenido = ($plantilla ?? new EmailTemplate())->render($mensaje);
        $email->Body = $contenido['html'];
        $email->AltBody = $contenido['texto'];

        return $email;
    }

    /**
     * Construye y configura una instancia de PHPMailer para notificaciones transaccionales de citas
     * (confirmación, cancelación, recordatorio).
     */
    public static function prepararMailerNotificacionCita(
        string $destinatarioEmail,
        string $destinatarioNombre,
        string $asunto,
        string $contenidoHtml,
        ?string $contenidoTexto = null
    ): PHPMailer {
        $email = new PHPMailer(true);
        $email->isSMTP();
        $email->Host = (string)($_ENV['EMAIL_HOST'] ?? (getenv('EMAIL_HOST') ?: 'localhost'));
        $email->SMTPAuth = true;
        $email->Port = (int)($_ENV['EMAIL_PORT'] ?? (getenv('EMAIL_PORT') ?: 25));
        $email->Username = (string)($_ENV['EMAIL_USER'] ?? (getenv('EMAIL_USER') ?: ''));
        $email->Password = self::resolverPasswordSmtp();
        $email->Timeout = 5;
        $email->getSMTPInstance()->Timelimit = 10;

        $remitente = self::resolverRemitente();
        $email->setFrom($remitente['email'], $remitente['nombre']);

        $emailDestinoLimpio = preg_replace('/[\r\n\x00-\x1F\x7F]+/', '', trim($destinatarioEmail));
        $nombreDestinoLimpio = self::sanearTextoCabecera($destinatarioNombre, 120);
        $asuntoLimpio = self::sanearTextoCabecera($asunto, 180);

        $email->addAddress($emailDestinoLimpio, $nombreDestinoLimpio);
        $email->isHTML(true);
        $email->CharSet = 'UTF-8';
        $email->Subject = $asuntoLimpio;
        $email->Body = $contenidoHtml;
        $email->AltBody = $contenidoTexto ?? EmailTemplate::textoDesdeHtml($contenidoHtml);

        return $email;
    }

    /**
     * Determina si existe un servidor SMTP explícitamente configurado en el entorno.
     * Durante la ejecución de PHPUnit sin transporte simulado ni `EMAIL_FORCE_SMTP=1`,
     * captura en memoria para evitar conexiones TCP salientes innecesarias.
     */
    private static function tieneHostSmtpConfigurado(): bool
    {
        if (
            defined('PHPUNIT_COMPOSER_INSTALL')
            && ($_ENV['EMAIL_FORCE_SMTP'] ?? (getenv('EMAIL_FORCE_SMTP') ?: '')) !== '1'
        ) {
            return false;
        }
        $hostEnv = $_ENV['EMAIL_HOST'] ?? null;
        if (is_string($hostEnv) && trim($hostEnv) !== '') {
            return true;
        }
        $hostGetenv = getenv('EMAIL_HOST');
        return is_string($hostGetenv) && trim($hostGetenv) !== '';
    }

    /**
     * Despacha una notificación transaccional de cita empleando el transporte inyectado,
     * el servidor SMTP configurado o captura en memoria en entornos de prueba sin SMTP.
     *
     * @param string $tipo `'confirmacion'`, `'cancelacion'` o `'recordatorio'`
     * @param string $destinatarioEmail
     * @param string $destinatarioNombre
     * @param string $asunto
     * @param string $contenidoHtml
     * @param array<string, mixed> $meta
     * @param callable|null $transportePersonalizado
     * @param string|null $contenidoTexto Versión legible equivalente al contenido HTML.
     * @return array{exito: bool, error: ?string, mailer: ?PHPMailer}
     */
    public static function despacharNotificacionCita(
        string $tipo,
        string $destinatarioEmail,
        string $destinatarioNombre,
        string $asunto,
        string $contenidoHtml,
        array $meta = [],
        ?callable $transportePersonalizado = null,
        ?string $contenidoTexto = null
    ): array {
        $proposito = 'cita_' . $tipo;

        try {
            $mail = self::prepararMailerNotificacionCita(
                $destinatarioEmail,
                $destinatarioNombre,
                $asunto,
                $contenidoHtml,
                $contenidoTexto
            );

            $transporteActivo = $transportePersonalizado ?? self::$transport;
            $metaCompleta = array_merge($meta, [
                'tipo' => $tipo,
                'proposito' => $proposito,
                'destinatario' => $destinatarioEmail,
                'nombre' => $destinatarioNombre,
                'asunto' => $asunto,
            ]);

            if ($transporteActivo !== null) {
                $exito = (bool)call_user_func($transporteActivo, $mail, $proposito, $metaCompleta);
                if ($exito) {
                    self::$emailsEnviados[] = [
                        'proposito' => $proposito,
                        'tipo' => $tipo,
                        'destinatario' => $destinatarioEmail,
                        'nombre' => $destinatarioNombre,
                        'asunto' => $mail->Subject,
                        'enlace' => (string)($meta['enlace'] ?? ''),
                        'cita_id' => $meta['cita_id'] ?? null,
                        'clave_idempotencia' => $meta['clave_idempotencia'] ?? null,
                        'mailer' => $mail
                    ];
                    return ['exito' => true, 'error' => null, 'mailer' => $mail];
                }

                return [
                    'exito' => false,
                    'error' => 'El transporte de correo rechazó el envío de la notificación.',
                    'mailer' => $mail
                ];
            }

            if (!self::tieneHostSmtpConfigurado()) {
                // En pruebas automatizadas sin EMAIL_HOST ni transporte explícito, capturar en memoria
                // evitando conexiones externas o bloqueos en puerto 25.
                self::$emailsEnviados[] = [
                    'proposito' => $proposito,
                    'tipo' => $tipo,
                    'destinatario' => $destinatarioEmail,
                    'nombre' => $destinatarioNombre,
                    'asunto' => $mail->Subject,
                    'enlace' => (string)($meta['enlace'] ?? ''),
                    'cita_id' => $meta['cita_id'] ?? null,
                    'clave_idempotencia' => $meta['clave_idempotencia'] ?? null,
                    'mailer' => $mail
                ];
                return ['exito' => true, 'error' => null, 'mailer' => $mail];
            }

            $enviado = $mail->send();
            if ($enviado) {
                self::$emailsEnviados[] = [
                    'proposito' => $proposito,
                    'tipo' => $tipo,
                    'destinatario' => $destinatarioEmail,
                    'nombre' => $destinatarioNombre,
                    'asunto' => $mail->Subject,
                    'enlace' => (string)($meta['enlace'] ?? ''),
                    'cita_id' => $meta['cita_id'] ?? null,
                    'clave_idempotencia' => $meta['clave_idempotencia'] ?? null,
                    'mailer' => $mail
                ];
                return ['exito' => true, 'error' => null, 'mailer' => $mail];
            }

            return [
                'exito' => false,
                'error' => self::sanearMensajeError($mail->ErrorInfo ?: 'Fallo al despachar correo SMTP.'),
                'mailer' => $mail
            ];
        } catch (\Throwable $e) {
            $errSaneado = self::sanearMensajeError($e->getMessage());
            error_log("Email::despacharNotificacionCita fallo ($proposito): " . $errSaneado);
            return [
                'exito' => false,
                'error' => $errSaneado,
                'mailer' => null
            ];
        }
    }

    /**
     * Despacha el mensaje transaccional construyendo la instancia real de PHPMailer
     * y delegando al transporte sustituible (en pruebas) o al método send() de PHPMailer.
     */
    protected function despachar(string $proposito, string $enlace): bool
    {
        $this->errorEnvio = null;
        try {
            // Construir el mensaje real mediante PHPMailer sin omitir su configuración
            $mail = $this->prepararMailer($proposito, $enlace);

            if (self::$transport !== null) {
                // El transporte sustituible recibe la instancia construida de PHPMailer
                $exito = (bool)call_user_func(self::$transport, $mail, $proposito, [
                    'destinatario' => $this->email,
                    'nombre' => $this->nombre,
                    'token' => $this->token,
                    'enlace' => $enlace
                ]);

                if ($exito) {
                    self::$emailsEnviados[] = [
                        'proposito' => $proposito,
                        'destinatario' => $this->email,
                        'nombre' => $this->nombre,
                        'token' => $this->token,
                        'enlace' => $enlace,
                        'mailer' => $mail
                    ];
                } else {
                    $this->errorEnvio = 'El transporte de correo rechazó el envío.';
                }
                return $exito;
            }

            $enviado = $mail->send();
            if (!$enviado) {
                $this->errorEnvio = self::sanearMensajeError($mail->ErrorInfo ?: 'El servidor de correo no aceptó el mensaje.');
            }
            return $enviado;
        } catch (\Throwable $e) {
            // Registro operativo sin exponer tokens ni credenciales
            $this->errorEnvio = self::sanearMensajeError($e->getMessage());
            error_log("Email::despachar fallo al despachar correo ($proposito): " . $this->errorEnvio);
            return false;
        }
    }

    /**
     * Envía el correo transaccional de confirmación de cuenta.
     * @return bool True si el correo fue despachado exitosamente, false en caso de fallo.
     */
    public function enviarConfirmacion(): bool
    {
        $appUrl = self::resolverAppUrl();
        $enlace = "{$appUrl}/confirmar-cuenta?token=" . rawurlencode((string)$this->token);
        $this->detallesExtra += ['Acceso' => 'Portal de clientes'];
        return $this->despachar('confirmacion', $enlace);
    }

    /**
     * Envía el correo transaccional con instrucciones para reestablecer contraseña.
     * @return bool True si el correo fue despachado exitosamente, false en caso de fallo.
     */
    public function enviarInstrucciones(): bool
    {
        $appUrl = self::resolverAppUrl();
        $enlace = "{$appUrl}/recuperar?token=" . rawurlencode((string)$this->token) . ($this->portal === 'equipo' ? '&portal=equipo' : '');
        $this->detallesExtra += ['Acceso' => $this->portal === 'equipo' ? 'Portal del equipo' : 'Portal de clientes'];
        return $this->despachar('recuperacion', $enlace);
    }

    /**
     * Invitación formal al equipo: el enlace lleva a la pantalla de aceptación del portal del equipo,
     * con un token de propósito exclusivo que no sirve para recuperar contraseñas.
     */
    public function enviarInvitacion(): bool
    {
        $appUrl = self::resolverAppUrl();
        $enlace = "{$appUrl}/equipo/aceptar?token=" . rawurlencode((string)$this->token);
        $this->detallesExtra += ['Acceso' => 'Portal del equipo'];
        return $this->despachar('invitacion', $enlace);
    }

    /**
     * Aviso sin token para quien intentó registrarse con un correo que ya tiene cuenta. No confirma nada al
     * solicitante (la respuesta pública es uniforme); solo informa al titular real del correo.
     */
    public function enviarAvisoCuentaExistente(): bool
    {
        $appUrl = self::resolverAppUrl();
        $esEquipo = $this->portal === 'equipo';
        $enlace = $appUrl . ($esEquipo ? '/equipo/login' : '/cliente/login');
        $this->detallesExtra += ['Acceso' => $esEquipo ? 'Portal del equipo' : 'Portal de clientes'];
        return $this->despachar('cuenta_existente', $enlace);
    }
}

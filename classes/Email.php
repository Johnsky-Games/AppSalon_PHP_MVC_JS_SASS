<?php

namespace Classes;

use PHPMailer\PHPMailer\PHPMailer;

class Email
{
    public $nombre;
    public $email;
    public $token;

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
     * Limpia el registro de correos simulados en memoria.
     */
    public static function limpiarEmailsEnviados(): void
    {
        self::$emailsEnviados = [];
    }

    /**
     * Envía el correo transaccional de confirmación de cuenta.
     * @return bool True si el correo fue despachado exitosamente, false en caso de fallo.
     */
    public function enviarConfirmacion(): bool
    {
        $appUrl = $_ENV['APP_URL'] ?? 'http://localhost:3000';
        $enlace = "{$appUrl}/confirmar-cuenta?token={$this->token}";

        // Si existe transporte simulado configurado para pruebas
        if (self::$transport !== null) {
            $exito = (bool)call_user_func(self::$transport, 'confirmacion', $this->email, $this->nombre, $this->token, $enlace);
            if ($exito) {
                self::$emailsEnviados[] = [
                    'proposito' => 'confirmacion',
                    'destinatario' => $this->email,
                    'nombre' => $this->nombre,
                    'token' => $this->token,
                    'enlace' => $enlace
                ];
            }
            return $exito;
        }

        try {
            $email = new PHPMailer(true);
            $email->isSMTP();
            $email->Host = $_ENV['EMAIL_HOST'] ?? 'localhost';
            $email->SMTPAuth = true;
            $email->Port = (int)($_ENV['EMAIL_PORT'] ?? 25);
            $email->Username = $_ENV['EMAIL_USER'] ?? '';
            $email->Password = $_ENV['EMAIL_PASSWORD'] ?? '';

            $fromEmail = $_ENV['EMAIL_FROM'] ?? 'cuentas@appsalon.com';
            $fromName = $_ENV['EMAIL_FROM_NAME'] ?? 'AppSalon.com';
            $email->setFrom($fromEmail, $fromName);

            // Destinatario legítimo: correo y nombre del usuario
            $email->addAddress($this->email, $this->nombre);
            $email->Subject = 'Confirma tu cuenta';

            $email->isHTML(true);
            $email->CharSet = 'UTF-8';

            $contenido = '<html>';
            $contenido .= '<p><strong> Hola ' . htmlspecialchars($this->nombre, ENT_QUOTES, 'UTF-8') . ", </strong> confirma tu cuenta de App Salon haciendo click en el siguiente enlace.</p>";
            $contenido .= "<p>Presiona aquí: <a href='" . htmlspecialchars($enlace, ENT_QUOTES, 'UTF-8') . "'>Confirmar Cuenta</a></p>";
            $contenido .= '<p>Si tu no solicitaste esta cuenta, puedes ignorar este mensaje.</p>';
            $contenido .= '</html>';

            $email->Body = $contenido;

            return $email->send();
        } catch (\Throwable $e) {
            // Registro operativo sin exponer tokens ni credenciales
            error_log("Email::enviarConfirmacion fallo al despachar correo a usuario: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Envía el correo transaccional con instrucciones para reestablecer contraseña.
     * @return bool True si el correo fue despachado exitosamente, false en caso de fallo.
     */
    public function enviarInstrucciones(): bool
    {
        $appUrl = $_ENV['APP_URL'] ?? 'http://localhost:3000';
        $enlace = "{$appUrl}/recuperar?token={$this->token}";

        // Si existe transporte simulado configurado para pruebas
        if (self::$transport !== null) {
            $exito = (bool)call_user_func(self::$transport, 'recuperacion', $this->email, $this->nombre, $this->token, $enlace);
            if ($exito) {
                self::$emailsEnviados[] = [
                    'proposito' => 'recuperacion',
                    'destinatario' => $this->email,
                    'nombre' => $this->nombre,
                    'token' => $this->token,
                    'enlace' => $enlace
                ];
            }
            return $exito;
        }

        try {
            $email = new PHPMailer(true);
            $email->isSMTP();
            $email->Host = $_ENV['EMAIL_HOST'] ?? 'localhost';
            $email->SMTPAuth = true;
            $email->Port = (int)($_ENV['EMAIL_PORT'] ?? 25);
            $email->Username = $_ENV['EMAIL_USER'] ?? '';
            $email->Password = $_ENV['EMAIL_PASSWORD'] ?? '';

            $fromEmail = $_ENV['EMAIL_FROM'] ?? 'cuentas@appsalon.com';
            $fromName = $_ENV['EMAIL_FROM_NAME'] ?? 'AppSalon.com';
            $email->setFrom($fromEmail, $fromName);

            // Destinatario legítimo: correo y nombre del usuario
            $email->addAddress($this->email, $this->nombre);
            $email->Subject = 'Reestablece tu password';

            $email->isHTML(true);
            $email->CharSet = 'UTF-8';

            $contenido = '<html>';
            $contenido .= '<p><strong> Hola ' . htmlspecialchars($this->nombre, ENT_QUOTES, 'UTF-8') . ", </strong> Has solicitado reestablecer tu password. Sigue el siguiente enlace para hacerlo.</p>";
            $contenido .= "<p>Presiona aquí: <a href='" . htmlspecialchars($enlace, ENT_QUOTES, 'UTF-8') . "'>Reestablecer Password</a></p>";
            $contenido .= '<p>Si tu no solicitaste esta cuenta, puedes ignorar este mensaje.</p>';
            $contenido .= '</html>';

            $email->Body = $contenido;

            return $email->send();
        } catch (\Throwable $e) {
            // Registro operativo sin exponer tokens ni credenciales
            error_log("Email::enviarInstrucciones fallo al despachar correo a usuario: " . $e->getMessage());
            return false;
        }
    }
}
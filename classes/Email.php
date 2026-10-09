<?php

namespace Classes;

use PHPMailer\PHPMailer\PHPMailer;

class Email
{
    public $nombre;
    public $email;
    public $token;
    public function __construct($nombre, $email, $token)
    {
        $this->nombre = $nombre;
        $this->email = $email;
        $this->token = $token;
    }

    public function enviarConfirmacion()
    {
        //Crear el objeto de email
        $email = new PHPMailer();
        $email->isSMTP();
        $email->Host = $_ENV['EMAIL_HOST'] ?? 'localhost';
        $email->SMTPAuth = true;
        $email->Port = (int)($_ENV['EMAIL_PORT'] ?? 25);
        $email->Username = $_ENV['EMAIL_USER'] ?? '';
        $email->Password = $_ENV['EMAIL_PASSWORD'] ?? '';

        $email->setFrom('cuentas@appsalon.com');
        $email->addAddress('cuentas@appsalon.com', 'AppSalon.com');
        $email->Subject = 'Confirma tu cuenta';

        //Set html

        $email->isHTML(true);
        $email->CharSet = 'UTF-8';

        $appUrl = $_ENV['APP_URL'] ?? 'http://localhost:3000';
        $contenido = '<html>';
        $contenido .= '<p><strong> Hola ' . $this->email . ", </strong> confirma tu cuenta de App Salon haciendo click en el siguiente enlace.</p>";
        $contenido .= "<p>Presiona aquí: <a href='" . $appUrl . "/confirmar-cuenta?token=" . $this->token . "'>Confirmar Cuenta</a></p>";
        $contenido .= '<p>Si tu no solicitaste esta cuenta, puedes ignorar este mensaje.</p>';
        $contenido .= '</html>';

        $email->Body = $contenido;

        //Enviar el email

        $email->send();
    }

    public function enviarInstrucciones()
    {
        //Crear el objeto de email
        $email = new PHPMailer();
        $email->isSMTP();
        $email->Host = $_ENV['EMAIL_HOST'] ?? 'localhost';
        $email->SMTPAuth = true;
        $email->Port = (int)($_ENV['EMAIL_PORT'] ?? 25);
        $email->Username = $_ENV['EMAIL_USER'] ?? '';
        $email->Password = $_ENV['EMAIL_PASSWORD'] ?? '';

        $email->setFrom('cuentas@appsalon.com');
        $email->addAddress('cuentas@appsalon.com', 'AppSalon.com');
        $email->Subject = 'Reestablece tu password';

        //Set html

        $email->isHTML(true);
        $email->CharSet = 'UTF-8';

        $appUrl = $_ENV['APP_URL'] ?? 'http://localhost:3000';
        $contenido = '<html>';
        $contenido .= '<p><strong> Hola ' . $this->nombre . ", </strong> Has solicitado reestablecer tu password. Sigue el siguiente enlace para hacerlo.</p>";
        $contenido .= "<p>Presiona aquí: <a href='" . $appUrl . "/recuperar?token=" . $this->token . "'>Reestablecer Password</a></p>";
        $contenido .= '<p>Si tu no solicitaste esta cuenta, puedes ignorar este mensaje.</p>';
        $contenido .= '</html>';

        $email->Body = $contenido;

        //Enviar el email

        $email->send();
    }
}
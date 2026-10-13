<?php
namespace Controllers;

use Classes\ErrorResponse;
use Classes\RateLimiter;
use Model\ActiveRecord;
use MVC\Router;
use Repositories\ColaboradorRepository;
use Repositories\CorreoSalidaRepository;
use Services\ColaboradorService;
use Services\CorreoSalidaService;

/**
 * Aceptación de invitaciones al equipo (pantalla pública con token de propósito exclusivo).
 * GET/HEAD nunca consumen el token; solo el POST con aceptación explícita lo consume, una única vez.
 */
final class InvitacionController
{
    private static function servicio(): ColaboradorService
    {
        $db = ActiveRecord::getDB();
        return new ColaboradorService(new ColaboradorRepository($db), new CorreoSalidaService(new CorreoSalidaRepository($db)));
    }

    public static function aceptar(Router $router): void
    {
        iniciar_sesion_segura();
        $token = is_string($_GET['token'] ?? null) ? trim($_GET['token']) : '';
        $servicio = self::servicio();
        $alertas = [];
        $metodo = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        if ($metodo === 'POST') {
            exigir_csrf();
            $r = $servicio->aceptar($token, $_POST, RateLimiter::obtenerIP());
            if ($r['status'] === ColaboradorService::OK) {
                $_SESSION['aviso_equipo'] = 'Invitación aceptada. Ya puedes ingresar al equipo con tu cuenta.';
                redirigir('/equipo/login?invitacion=aceptada');
                return;
            }
            $alertas = ['error' => $r['errores'] ?? ['No se pudo aceptar la invitación.']];
            http_response_code(match ($r['status']) {
                ColaboradorService::RATE_LIMITED => 429,
                ColaboradorService::FORBIDDEN => 403,
                ColaboradorService::CONFLICT => 409,
                ColaboradorService::ERROR => 500,
                default => 422,
            });
            if ($r['status'] === ColaboradorService::RATE_LIMITED) { header('Retry-After: ' . (int)($r['segundos'] ?? 60)); }
        }

        $vista = $servicio->vistaInvitacion($token);
        if ($vista['estado'] !== 'valida' && $metodo !== 'POST') {
            http_response_code($vista['estado'] === 'invalida' ? 404 : 410);
        }
        $router->render('equipo/aceptar', ['vista' => $vista, 'token' => $token, 'alertas' => $alertas]);
    }
}

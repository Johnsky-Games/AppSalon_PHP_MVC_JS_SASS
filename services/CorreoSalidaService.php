<?php
namespace Services;

use Classes\Email;
use Repositories\CorreoSalidaRepository;

/**
 * Despacho de la cola de correos. El envío ocurre DESPUÉS de confirmar la transacción de negocio;
 * un fallo SMTP deja el evento reintentable y nunca revierte ni duplica el alta.
 *
 * Límite honesto: registrar un único evento impide dobles correos por doble clic o reintento, pero no
 * puede garantizar entrega SMTP "exactamente una vez" si el servidor aceptó el mensaje y la conexión
 * se cortó antes de confirmarlo (en ese caso se reintenta y puede llegar duplicado).
 */
final class CorreoSalidaService
{
    public function __construct(private CorreoSalidaRepository $repo) {}

    /** Intenta enviar un evento; devuelve true si quedó enviado. */
    public function despachar(int $id): bool
    {
        $evento = $this->repo->tomar($id);
        if ($evento === null) { return false; }
        $carga = $evento['carga'];
        if ($carga === null || !isset($carga['token'])) {
            $this->repo->marcarFallido($id, $evento['token'], 'Carga ilegible: reenvía la invitación.');
            return false;
        }
        try {
            $correo = new Email($evento['nombre'], $evento['email'], (string)$carga['token']);
            $correo->detallesExtra = array_filter(['Rol' => $carga['rol_etiqueta'] ?? null, 'Puesto' => $carga['puesto'] ?? null], static fn($v) => is_string($v) && $v !== '');
            $enviado = match ($evento['tipo']) {
                'invitacion_colaborador' => $correo->enviarInvitacion(),
                default => false,
            };
        } catch (\Throwable $e) {
            $enviado = false;
        }
        if ($enviado) {
            $this->repo->marcarEnviado($id, $evento['token']);
            return true;
        }
        $this->repo->marcarFallido($id, $evento['token'], 'El servidor de correo no aceptó el mensaje.');
        return false;
    }

    /** @return int cantidad enviada */
    public function procesarPendientes(int $limite = 20): int
    {
        $enviados = 0;
        foreach ($this->repo->listos($limite) as $id) {
            if ($this->despachar($id)) { $enviados++; }
        }
        return $enviados;
    }
}

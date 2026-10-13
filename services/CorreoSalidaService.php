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

    /**
     * CONTRATO (consumido por AuthService::registrar y reenviarConfirmacion). Se invoca DENTRO de la transacción
     * del alta/emisión del token: o se confirman ambos o ninguno. La clave única impide dos eventos por causa.
     * @return int id del evento de la cola
     */
    public function encolarConfirmacionCuenta(int $usuarioId, string $email, string $nombre, string $tokenPlano): int
    {
        return $this->repo->encolar('confirmacion:' . $usuarioId . ':' . substr(hash('sha256', $tokenPlano), 0, 24),
            'confirmacion_cuenta', $email, $nombre, ['token' => $tokenPlano]);
    }

    /**
     * CONTRATO. Aviso sin token para la respuesta pública uniforme del registro cuando el correo YA tiene cuenta
     * (cliente confirmado o personal). `$portal` es 'cliente'|'equipo'. `$ventana` agrupa los avisos para que
     * un atacante no pueda inundar al titular (p. ej. gmdate('YmdH') = un aviso por hora y correo).
     */
    public function encolarAvisoCuentaExistente(string $email, string $nombre, string $portal, string $ventana): int
    {
        return $this->repo->encolar('aviso_existente:' . substr(hash('sha256', mb_strtolower(trim($email)) . '|' . $ventana), 0, 40),
            'aviso_cuenta_existente', $email, $nombre, ['portal' => $portal === 'equipo' ? 'equipo' : 'cliente']);
    }

    /** CONTRATO. Intento inmediato posterior al commit; nunca lanza (un fallo deja el evento reintentable). */
    public function despacharTrasCommit(int $eventoId): bool
    {
        try { return $this->despachar($eventoId); } catch (\Throwable $e) { return false; }
    }

    /** Intenta enviar un evento; devuelve true si quedó enviado. */
    public function despachar(int $id): bool
    {
        $evento = $this->repo->tomar($id);
        if ($evento === null) { return false; }
        $carga = $evento['carga'];
        if ($carga === null || (!isset($carga['token']) && $evento['tipo'] !== 'aviso_cuenta_existente')) {
            $this->repo->marcarFallido($id, $evento['token'], 'Carga ilegible: reenvía la invitación.');
            return false;
        }
        try {
            $correo = new Email($evento['nombre'], $evento['email'], (string)($carga['token'] ?? ''));
            $correo->portal = ($carga['portal'] ?? 'cliente') === 'equipo' ? 'equipo' : 'cliente';
            $correo->detallesExtra = array_filter(['Rol' => $carga['rol_etiqueta'] ?? null, 'Puesto' => $carga['puesto'] ?? null], static fn($v) => is_string($v) && $v !== '');
            $enviado = match ($evento['tipo']) {
                'invitacion_colaborador' => $correo->enviarInvitacion(),
                'confirmacion_cuenta' => $correo->enviarConfirmacion(),
                'aviso_cuenta_existente' => $correo->enviarAvisoCuentaExistente(),
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

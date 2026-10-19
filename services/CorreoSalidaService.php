<?php
namespace Services;

use Classes\Email;
use Classes\Entorno;
use Repositories\CorreoSalidaRepository;
use Repositories\PersistenceException;
use Repositories\UsuarioRepository;

/**
 * Despacho de la cola de correos. El envío ocurre DESPUÉS de confirmar la transacción de negocio;
 * un fallo SMTP deja el evento reintentable y nunca revierte ni duplica el alta.
 *
 * Garantía: ENTREGA AL MENOS UNA VEZ. Registrar un único evento impide dobles correos por doble clic o reintento,
 * pero no puede garantizar entrega SMTP "exactamente una vez": si el servidor aceptó el mensaje y la conexión se
 * cortó antes de confirmarlo (o el worker murió entre el envío y el UPDATE a `enviado`), el evento se reintenta y
 * el destinatario puede recibir el correo duplicado. El arrendamiento (lease con worker_token) evita que dos
 * workers envíen el mismo evento a la vez en condiciones normales.
 *
 * Seguridad: el token viaja solo en `carga_cifrada` (y se borra al terminar); ni la cola ni los logs lo guardan en claro.
 * `ultimo_error` pasa siempre por Email::sanearMensajeError.
 */
final class CorreoSalidaService
{
    public const TIPO_CONFIRMACION = 'confirmacion_cuenta';
    public const TIPO_AVISO_EXISTENTE = 'aviso_cuenta_existente';
    public const TIPO_RECUPERACION = 'recuperacion_cuenta';

    private static bool $advertidoEnvioInmediato = false;
    private const R_ENVIADO = 'enviado';
    private const R_FALLO = 'fallo';
    private const R_CANCELADO = 'cancelado';
    private const R_OCUPADO = 'ocupado';

    public function __construct(private CorreoSalidaRepository $repo) {}

    /**
     * CONTRATO (consumido por AuthService::registrar y reenviarConfirmacion). Se invoca DENTRO de la transacción
     * del alta/emisión del token (con la MISMA conexión mysqli que el repositorio de la cola): o se confirman ambos
     * o ninguno. La clave única (usuario + huella del token) impide dos eventos para el mismo token.
     * El evento queda asociado al usuario para poder cancelar los enlaces que un reenvío invalide.
     * @return int id del evento de la cola
     */
    public function encolarConfirmacionCuenta(int $usuarioId, string $email, string $nombre, string $tokenPlano): int
    {
        return $this->repo->encolar('confirmacion:' . $usuarioId . ':' . substr(hash('sha256', $tokenPlano), 0, 24),
            self::TIPO_CONFIRMACION, $email, $nombre, ['token' => $tokenPlano], $usuarioId);
    }

    /**
     * Cancela los eventos de confirmación aún no entregados de un usuario, salvo el vigente `$excepto`, para que nunca se
     * envíe un enlace que un reenvío ya invalidó. Se invoca en la misma transacción que la emisión del token nuevo.
     * @return int eventos cancelados
     */
    public function cancelarConfirmacionesAnteriores(int $usuarioId, int $excepto): int
    {
        return $this->repo->cancelarPorUsuario($usuarioId, self::TIPO_CONFIRMACION, 'Reemplazado por un enlace de confirmación más reciente.', $excepto);
    }

    /**
     * CONTRATO. Aviso sin token para la respuesta pública uniforme del registro cuando el correo YA tiene cuenta
     * (cliente confirmado o personal). `$portal` es 'cliente'|'equipo'. `$ventana` agrupa los avisos para que
     * un atacante no pueda inundar al titular (p. ej. gmdate('YmdH') = un aviso por hora y correo).
     */
    public function encolarAvisoCuentaExistente(string $email, string $nombre, string $portal, string $ventana): int
    {
        return $this->repo->encolar('aviso_existente:' . substr(hash('sha256', mb_strtolower(trim($email)) . '|' . $ventana), 0, 40),
            self::TIPO_AVISO_EXISTENTE, $email, $nombre, ['portal' => $portal === 'equipo' ? 'equipo' : 'cliente']);
    }

    /**
     * Recuperación de contraseña: token propio, mismo tratamiento que la confirmación (misma transacción, cancelación de
     * enlaces anteriores y comprobación de vigencia al despachar). `$portal` = 'cliente'|'equipo'.
     */
    public function encolarRecuperacionCuenta(int $usuarioId, string $email, string $nombre, string $tokenPlano, string $portal): int
    {
        return $this->repo->encolar('recuperacion:' . $usuarioId . ':' . substr(hash('sha256', $tokenPlano), 0, 24),
            self::TIPO_RECUPERACION, $email, $nombre, ['token' => $tokenPlano, 'portal' => $portal === 'equipo' ? 'equipo' : 'cliente'], $usuarioId);
    }

    public function cancelarRecuperacionesAnteriores(int $usuarioId, int $excepto): int
    {
        return $this->repo->cancelarPorUsuario($usuarioId, self::TIPO_RECUPERACION, 'Reemplazado por un enlace de recuperación más reciente.', $excepto);
    }

    /**
     * ¿Se intenta el envío en la propia petición HTTP? Por defecto NO (QA R2B-02/08/09): enviar en línea haría que el
     * tiempo de respuesta delate si la cuenta existe (con SMTP lento, 0,4 s frente a 0,01 s). Los correos de cuenta salen
     * siempre por la cola y el worker (intervalo CORREOS_INTERVALO_SEGUNDOS, 5 s por defecto).
     *
     * CORREOS_ENVIO_INMEDIATO=1 es SOLO una variable de pruebas: únicamente se respeta si APP_ENV es explícitamente de
     * desarrollo o pruebas (valor EXACTO development|dev|local|testing|test, en minúsculas y sin espacios; ver Classes\Entorno). En
     * production, staging, vacío o cualquier valor desconocido se ignora aunque valga 1, para que una variable accidental
     * no reabra el canal de enumeración por tiempo; se deja una advertencia en el registro, una vez por proceso.
     */
    public static function envioInmediatoHabilitado(): bool
    {
        $v = $_ENV['CORREOS_ENVIO_INMEDIATO'] ?? getenv('CORREOS_ENVIO_INMEDIATO');
        if (!is_string($v) || !in_array(strtolower(trim($v)), ['1', 'true', 'si', 'sí', 'on'], true)) {
            return false;
        }
        if (!Entorno::esPruebasODesarrollo()) {
            if (!self::$advertidoEnvioInmediato) {
                self::$advertidoEnvioInmediato = true;
                error_log('[correos] CORREOS_ENVIO_INMEDIATO ignorado: APP_ENV no es de pruebas/desarrollo');
            }
            return false;
        }
        return true;
    }

    /** CONTRATO. Intento inmediato posterior al commit (solo si está habilitado); nunca lanza (un fallo deja el evento reintentable). */
    public function despacharTrasCommit(int $eventoId): bool
    {
        if (!self::envioInmediatoHabilitado()) { return false; }
        try { return $this->despachar($eventoId); } catch (\Throwable $e) {
            error_log('[correos] despacho inmediato del evento #' . $eventoId . ' fallido: ' . Email::sanearMensajeError($e->getMessage()));
            return false;
        }
    }

    /** Intenta enviar un evento; devuelve true si quedó enviado. */
    public function despachar(int $id): bool
    {
        return $this->despacharDetallado($id) === self::R_ENVIADO;
    }

    private function despacharDetallado(int $id): string
    {
        $evento = $this->repo->tomar($id);
        if ($evento === null) { return self::R_OCUPADO; }
        $carga = $evento['carga'];
        if ($carga === null || (!isset($carga['token']) && !in_array($evento['tipo'],[self::TIPO_AVISO_EXISTENTE,ErpFiscalCorreoService::TIPO],true))) {
            // Sin carga legible (p. ej. APP_KEY cambió) no hay nada que reintentar: terminal y visible; el remedio es reenviar.
            $this->repo->marcarFallidoDefinitivo($id, $evento['token'], $evento['tipo']===ErpFiscalCorreoService::TIPO?'Adjuntos fiscales ilegibles: revisa APP_KEY y el respaldo de la entrega.':'Carga ilegible: hay que emitir un enlace nuevo (reenviar la confirmación o la invitación).');
            error_log("[correos] evento #{$id} ({$evento['tipo']}) con carga ilegible: fallido definitivo");
            return self::R_FALLO;
        }
        if (in_array($evento['tipo'], [self::TIPO_CONFIRMACION, self::TIPO_RECUPERACION], true) && $evento['usuario_id'] !== null) {
            $vigencia = $this->vigenciaConfirmacion($evento['usuario_id'], (string)$carga['token'], $evento['tipo'] === self::TIPO_RECUPERACION ? 'recuperacion' : 'confirmacion');
            if ($vigencia === false) {
                // El enlace ya no sirve (reemplazado por un reenvío, vencido o cuenta ya confirmada): no se envía jamás.
                $this->repo->marcarCancelado($id, $evento['token'], 'Enlace ya no vigente (reemplazado, vencido o cuenta confirmada).');
                return self::R_CANCELADO;
            }
            if ($vigencia === null) {
                $this->registrarFallo($id, $evento, 'No se pudo verificar la vigencia del enlace.');
                return self::R_FALLO;
            }
        }
        $detalle = null;
        if($evento['tipo']===ErpFiscalCorreoService::TIPO){
            try {
                $mail=(new ErpFiscalCorreoService(new \Repositories\ErpRepository($this->repo->conexion())))->preparar($carga,$evento['email'],$evento['nombre']);
            } catch(ErpException $e) {
                $this->repo->marcarFallidoDefinitivo($id,$evento['token'],'La evidencia de entrega fiscal no es verificable. Revisa el comprobante y sus adjuntos.');
                return self::R_FALLO;
            } catch(\Throwable $e) {
                $this->registrarFallo($id,$evento,'No se pudo preparar la entrega fiscal.');return self::R_FALLO;
            }
            $resultado=Email::despacharFiscal($mail);
            if($resultado['exito']){
                if(!$this->repo->marcarEnviado($id,$evento['token']))error_log('[correos] entrega fiscal #'.$id.': SMTP aceptado sin poder confirmar el lease');
                return self::R_ENVIADO;
            }
            $this->registrarFallo($id,$evento,$resultado['error']);return self::R_FALLO;
        }
        try {
            $correo = new Email($evento['nombre'], $evento['email'], (string)($carga['token'] ?? ''));
            $correo->portal = ($carga['portal'] ?? 'cliente') === 'equipo' ? 'equipo' : 'cliente';
            $correo->detallesExtra = array_filter(['Rol' => $carga['rol_etiqueta'] ?? null, 'Puesto' => $carga['puesto'] ?? null], static fn($v) => is_string($v) && $v !== '');
            $enviado = match ($evento['tipo']) {
                'invitacion_colaborador' => $correo->enviarInvitacion(),
                self::TIPO_CONFIRMACION => $correo->enviarConfirmacion(),
                self::TIPO_RECUPERACION => $correo->enviarInstrucciones(),
                self::TIPO_AVISO_EXISTENTE => $correo->enviarAvisoCuentaExistente(),
                default => false,
            };
            $detalle = $correo->errorEnvio;
        } catch (\Throwable $e) {
            $enviado = false;
            $detalle = Email::sanearMensajeError($e->getMessage());
        }
        if ($enviado) {
            if (!$this->repo->marcarEnviado($id, $evento['token'])) {
                // El correo salió, pero el arrendamiento ya no era nuestro (otro worker lo tomó o se canceló): posible duplicado documentado.
                error_log("[correos] evento #{$id}: enviado pero el arrendamiento se perdió antes de registrarlo");
            }
            return self::R_ENVIADO;
        }
        $this->registrarFallo($id, $evento, $detalle ?: 'El servidor de correo no aceptó el mensaje.');
        return self::R_FALLO;
    }

    private function registrarFallo(int $id, array $evento, string $motivo): void
    {
        $estado = $this->repo->marcarFallido($id, $evento['token'], $motivo);
        if ($estado === 'fallido') {
            // Terminal y visible: aparece en `procesar_correos.php --estado` y se reactiva con `--reintentar`.
            error_log("[correos] evento #{$id} ({$evento['tipo']}) FALLIDO tras " . CorreoSalidaRepository::MAX_INTENTOS . " intentos; reactivar con: php scripts/procesar_correos.php --reintentar={$id}");
        }
    }

    /**
     * ¿Sigue siendo utilizable el enlace de confirmación? true = vigente; false = obsoleto (cuenta ya confirmada,
     * token reemplazado por un reenvío, vencido o usuario inexistente); null si no se pudo comprobar (se reintenta).
     * Es una defensa en profundidad: el reenvío ya cancela los eventos anteriores, y esta comprobación cubre la
     * confirmación de la cuenta por otra vía y la ventana entre el envío y la cancelación.
     */
    private function vigenciaConfirmacion(int $usuarioId, string $tokenPlano, string $tipoToken = 'confirmacion'): ?bool
    {
        try {
            $usuario = (new UsuarioRepository($this->repo->conexion()))->findByValidToken($tokenPlano, $tipoToken);
        } catch (\Throwable) {
            return null;
        }
        if ($usuario === null || (int)$usuario->id !== $usuarioId) { return false; }
        // La confirmación deja de servir si la cuenta ya está confirmada; la recuperación solo existe para cuentas confirmadas.
        return $tipoToken === 'recuperacion' ? (string)$usuario->confirmado === '1' : (string)$usuario->confirmado !== '1';
    }

    /** @return int cantidad enviada */
    public function procesarPendientes(int $limite = 20): int
    {
        return $this->procesarLote($limite)['enviados'];
    }

    /**
     * Procesa los eventos listos. Con `$cortarTrasFallosSeguidos` > 0 interrumpe la tanda tras ese número de fallos
     * de envío consecutivos: con el SMTP caído no tiene sentido gastar un intento (ni 5 s de espera) por cada evento
     * de la cola; los demás quedan intactos para la siguiente ejecución.
     * @return array{intentados:int,enviados:int,fallidos:int,cancelados:int,omitidos:int,cortado:bool}
     */
    public function procesarLote(int $limite = 20, int $cortarTrasFallosSeguidos = 0): array
    {
        $ids = $this->repo->listos($limite);
        $r = ['intentados' => 0, 'enviados' => 0, 'fallidos' => 0, 'cancelados' => 0, 'omitidos' => 0, 'cortado' => false];
        $seguidos = 0;
        foreach ($ids as $i => $id) {
            if ($cortarTrasFallosSeguidos > 0 && $seguidos >= $cortarTrasFallosSeguidos) {
                $r['cortado'] = true;
                $r['omitidos'] = count($ids) - $i;
                break;
            }
            $resultado = $this->despacharDetallado($id);
            if ($resultado === self::R_OCUPADO) { continue; }
            $r['intentados']++;
            if ($resultado === self::R_ENVIADO) { $r['enviados']++; $seguidos = 0; }
            elseif ($resultado === self::R_CANCELADO) { $r['cancelados']++; }
            else { $r['fallidos']++; $seguidos++; }
        }
        return $r;
    }
}

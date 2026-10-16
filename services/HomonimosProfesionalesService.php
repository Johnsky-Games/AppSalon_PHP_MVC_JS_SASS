<?php
namespace Services;

use Classes\Secretos;
use Repositories\ProfesionalHomonimosRepository;

/**
 * Aviso de posibles homónimos al dar de alta un PROFESIONAL (ficha de agenda), con las mismas garantías que el alta de
 * clientes (AltaClienteService) y reutilizando sus reglas de comparación (HomonimosService):
 *  - nunca fusiona ni bloquea: dos personas distintas pueden llamarse igual;
 *  - con coincidencias la ficha NO se crea hasta que el operador confirma «Es una persona distinta»; el servidor RECALCULA las
 *    coincidencias al recibir la confirmación y solo la acepta con el token HMAC (ligado al operador, a la clave de operación
 *    del formulario y al conjunto exacto de ids), de modo que no se puede forjar ni reutilizar con otro conjunto;
 *  - la decisión queda en profesionales_homonimos_decisiones (solo anexado): «continuó» en la MISMA transacción del alta;
 *  - el aviso solo muestra nombre, estado de la ficha y si está vinculada a una cuenta (nunca correos ni teléfonos).
 * Una ficha de agenda solo tiene nombre: se compara el nombre completo (apellido vacío) y no hay criterio de teléfono.
 */
final class HomonimosProfesionalesService
{
    public const LIBRE = 'libre';
    public const REQUIERE_CONFIRMACION = 'requiere_confirmacion';
    public const CONFIRMADA = 'confirmada';
    public const CANCELADO = 'cancelado';
    public const MAX_COINCIDENCIAS = 25;
    public const MOSTRAR = 5;
    public const AMBITO_PROFESIONAL = 'profesional_nuevo';
    public const AMBITO_ASISTENTE = 'ficha_asistente';

    public function __construct(private ProfesionalHomonimosRepository $repo) {}

    /**
     * @return array<int,array{id:int,nombre:string,activo:bool,vinculada:bool,criterios:string[]}> ordenadas por relevancia y luego por id
     */
    public function buscar(string $nombre): array
    {
        $nuevo = ['nombre' => $nombre, 'apellido' => ''];
        $hallados = [];
        foreach ($this->repo->candidatos() as $c) {
            $criterios = HomonimosService::criterios($nuevo, ['nombre' => $c['nombre'], 'apellido' => '']);
            if ($criterios) { $hallados[] = $c + ['criterios' => $criterios]; }
        }
        usort($hallados, static function (array $a, array $b): int {
            $ra = in_array(HomonimosService::NOMBRE_EXACTO, $a['criterios'], true) ? 0 : 1;
            $rb = in_array(HomonimosService::NOMBRE_EXACTO, $b['criterios'], true) ? 0 : 1;
            return [$ra, $a['id']] <=> [$rb, $b['id']];
        });
        return array_slice($hallados, 0, self::MAX_COINCIDENCIAS);
    }

    /** Token de confirmación: HMAC ligado al operador, a la clave de operación del formulario y al conjunto exacto de ids. */
    public static function token(int $actorId, string $clave, array $ids): string
    {
        $ids = array_values(array_unique(array_map('intval', $ids))); sort($ids);
        return hash_hmac('sha256', 'homonimos-profesional|' . $actorId . '|' . $clave . '|' . implode(',', $ids), Secretos::clave('homonimos-confirmacion'));
    }

    /**
     * Decide qué hacer con un alta de profesional según sus posibles homónimos.
     *
     * @param string $decision 'crear' (primer envío), 'confirmar' o 'cancelar'
     * @return array{estado:string,coincidencias?:array,token?:string,confirmacion_invalida?:bool,ids?:int[],decision?:array}
     *   LIBRE: no hay coincidencias. REQUIERE_CONFIRMACION: mostrar aviso. CANCELADO: no se crea nada (ya auditado).
     *   CONFIRMADA: crear y, en la misma transacción, registrar 'decision' con ProfesionalHomonimosRepository::registrarDecision().
     */
    public function evaluar(int $actorId, ?string $actorRol, string $nombre, string $clave, string $decision, ?string $tokenEnviado, ?string $ipHash, string $ambito): array
    {
        $coincidencias = $this->buscar($nombre);
        if (!$coincidencias) {
            return ['estado' => $decision === 'cancelar' ? self::CANCELADO : self::LIBRE, 'ids' => []];
        }
        $ids = array_map(static fn(array $c): int => $c['id'], $coincidencias);
        $criterios = [];
        foreach ($coincidencias as $c) { $criterios = array_merge($criterios, $c['criterios']); }
        $criterios = array_values(array_unique($criterios));
        $esperado = self::token($actorId, $clave, $ids);
        $base = ['actor_usuario_id' => $actorId, 'actor_rol' => $actorRol, 'ambito' => $ambito, 'coincidencias_ids' => $ids, 'criterios' => $criterios,
            'clave_hash' => hash('sha256', $clave), 'ip_hash' => $ipHash];
        if ($decision === 'cancelar') {
            $this->repo->registrarDecision($base + ['decision' => 'cancelo', 'token_hash' => null, 'profesional_creado_id' => null]);
            return ['estado' => self::CANCELADO, 'ids' => $ids];
        }
        if ($decision === 'confirmar' && is_string($tokenEnviado) && $tokenEnviado !== '' && hash_equals($esperado, $tokenEnviado)) {
            return ['estado' => self::CONFIRMADA, 'ids' => $ids, 'decision' => $base + ['decision' => 'continuo', 'token_hash' => hash('sha256', $tokenEnviado)]];
        }
        return ['estado' => self::REQUIERE_CONFIRMACION, 'coincidencias' => $coincidencias, 'token' => $esperado, 'confirmacion_invalida' => $decision === 'confirmar'];
    }

    /** Anexa la decisión «continuó» con la ficha creada. Llamar dentro de la transacción del alta. */
    public function registrarContinuo(array $decision, int $profesionalId): void
    {
        $this->repo->registrarDecision($decision + ['profesional_creado_id' => $profesionalId]);
    }
}

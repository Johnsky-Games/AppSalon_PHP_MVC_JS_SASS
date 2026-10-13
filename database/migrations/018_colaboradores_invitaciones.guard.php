<?php

/**
 * Guard del rollback de la 018. Nunca modifica datos.
 * Revertir elimina colaboradores (ficha laboral, rol, estado), invitaciones, la cola de correos y el vínculo
 * profesional-colaborador: son datos de negocio sin copia en otra tabla.
 *
 * @return callable(\mysqli): array<int,array{mensaje:string,tabla:?string}>
 */
return static function (\mysqli $db): array {
    $tabla = static function (string $nombre) use ($db): bool {
        $r = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($nombre) . "'");
        return (bool)$r && $r->num_rows > 0;
    };
    $perdidas = [];
    if ($tabla('colaboradores')) {
        $f = $db->query("SELECT COUNT(*) n, COALESCE(SUM(estado='activo'),0) a FROM colaboradores")->fetch_assoc();
        if ((int)$f['n'] > 0) {
            $perdidas[] = ['mensaje' => "{$f['n']} colaboradores ({$f['a']} activos): fichas laborales, roles y estados se eliminan.", 'tabla' => 'colaboradores'];
        }
    }
    if ($tabla('colaborador_invitaciones')) {
        $f = $db->query("SELECT COUNT(*) n, COALESCE(SUM(estado='pendiente'),0) p FROM colaborador_invitaciones")->fetch_assoc();
        if ((int)$f['n'] > 0) {
            $perdidas[] = ['mensaje' => "{$f['n']} invitaciones ({$f['p']} pendientes) se eliminan.", 'tabla' => 'colaborador_invitaciones'];
        }
    }
    if ($tabla('correos_salida')) {
        $f = $db->query("SELECT COUNT(*) n, COALESCE(SUM(estado IN ('pendiente','enviando','fallido')),0) p FROM correos_salida")->fetch_assoc();
        if ((int)$f['n'] > 0) {
            $perdidas[] = ['mensaje' => "{$f['n']} eventos de la cola de correos ({$f['p']} sin entregar: confirmaciones, avisos e invitaciones) se eliminan.", 'tabla' => 'correos_salida'];
        }
    }
    $col = $db->query("SHOW COLUMNS FROM profesionales LIKE 'colaborador_id'");
    if ($col && $col->num_rows > 0) {
        $n = (int)$db->query('SELECT COUNT(*) n FROM profesionales WHERE colaborador_id IS NOT NULL')->fetch_assoc()['n'];
        if ($n > 0) {
            $perdidas[] = ['mensaje' => "{$n} profesionales pierden el vínculo con su colaborador (profesionales.colaborador_id).", 'tabla' => null];
        }
    }
    return $perdidas;
};

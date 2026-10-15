<?php

/**
 * Guard del rollback de la 023. Se ejecuta ANTES de revertir (`migrador.php down`) y nunca modifica datos.
 *
 * El rollback copia quién y cuándo inició, finalizó o registró la ausencia de cada cita a `citas_atencion_resguardo_023`
 * (tabla persistente que NO elimina) y la 023 lo restaura al reaplicarse; `citas.estado` y `agenda_eventos` no se tocan.
 * Por eso la reversión no pierde datos de negocio: lista vacía. Lo único que ocurre mientras esté revertida es que la
 * aplicación deja de mostrar esa trazabilidad (y se pierde el trigger que impide reabrir citas históricas).
 *
 * @return callable(\mysqli): array<int,array{mensaje:string,tabla:?string}>
 */
return static function (\mysqli $db): array {
    return [];
};

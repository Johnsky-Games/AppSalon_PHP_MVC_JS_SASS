<?php
/**
 * Destinos centrales por portal y permiso (ronda 5).
 *
 * Toda ruta a la que una pantalla, un formulario, una redirección posterior a guardar/cancelar/fallar o una página de error
 * "devuelve" a la persona se obtiene de AQUÍ, nunca de una cadena escrita a mano ni del `Referer`. Una clave desconocida, un
 * parámetro fuera de su tipo o un destino que el rol/portal actual no puede usar degradan al inicio del portal de la sesión.
 *
 *  · destino_portal($clave, $params)      → ruta absoluta validada ('' si la clave/portal/permiso no es aplicable)
 *  · campos_retorno($clave, $params)      → <input type="hidden"> que viajan con un formulario (clave + parámetros tipados)
 *  · destino_volver($porDefecto, $fuente) → destino al que regresar tras procesar un envío, reconstruido y revalidado
 *  · destino_error_operacion($porDefecto) → destino del botón «Volver» de una página de error de operación
 */

/**
 * Catálogo: clave => [portal, ruta, permisos (cualquiera basta; [] = sin permiso adicional), parámetros permitidos {nombre => tipo}].
 * Tipos: fecha | id | entero | texto | enum:a,b,c
 *
 * @return array<string,array{0:string,1:string,2:string[],3:array<string,string>}>
 */
function catalogo_destinos(): array
{
    $rango = ['desde' => 'fecha', 'hasta' => 'fecha'];
    return [
        // ----- Portal del equipo
        'agenda'            => ['equipo', '/agenda', ['agenda.gestionar', 'agenda.ver_propia'], $rango],
        'agenda_cita'       => ['equipo', '/agenda/cita', ['agenda.gestionar', 'agenda.ver_propia'], ['id' => 'id'] + $rango],
        'caja'              => ['equipo', '/caja', ['finanzas.gestionar'], $rango + ['estado' => 'enum:pendiente,parcial,por_verificar,pagado,reembolsado', 'profesional' => 'id', 'metodo' => 'enum:efectivo,transferencia', 'q' => 'texto', 'pagina' => 'entero']],
        'caja_cita'         => ['equipo', '/caja/cita', ['finanzas.gestionar'], ['id' => 'id'] + $rango],
        'caja_comprobante'  => ['equipo', '/caja/comprobante', ['finanzas.gestionar'], ['id' => 'id']],
        'finanzas'          => ['equipo', '/admin/finanzas', ['finanzas.gestionar'], ['inicio' => 'fecha', 'fin' => 'fecha', 'pagina' => 'entero']],
        'clientes'          => ['equipo', '/admin/clientes', ['clientes.ver', 'clientes.analitica', 'clientes.ver_propios'], [
            'q' => 'texto', 'pagina' => 'entero', 'desde' => 'fecha', 'hasta' => 'fecha', 'membresia' => 'texto', 'confirmado' => 'texto', 'consentimiento' => 'texto',
            'actividad' => 'texto', 'segmento' => 'texto', 'profesional' => 'id', 'orden' => 'texto', 'dir' => 'enum:asc,desc']],
        'inicio_equipo'     => ['equipo', '/admin', [], []],
        // ----- Portal de clientes
        'cliente_reservar'  => ['cliente', '/cita', [], []],
        'cliente_citas'     => ['cliente', '/mis-citas', [], []],
    ];
}

/** Portal efectivo de la sesión actual ('cliente' | 'equipo'); sin sesión, 'cliente'. */
function portal_destinos(): string
{
    return (($_SESSION['login'] ?? false) === true) ? portal_sesion() : 'cliente';
}

/** Valor normalizado según su tipo, o null si no es válido. */
function destino_valor_tipado(string $tipo, mixed $valor): ?string
{
    if (!is_string($valor) && !is_int($valor)) { return null; }
    $v = (string)$valor;
    if ($tipo === 'fecha') {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d && $d->format('Y-m-d') === $v ? $v : null;
    }
    if ($tipo === 'id') { return preg_match('/^[1-9]\d{0,9}$/', $v) ? $v : null; }
    if ($tipo === 'entero') { return preg_match('/^[1-9]\d{0,4}$/', $v) ? $v : null; }
    if ($tipo === 'texto') { return $v !== '' && mb_strlen($v) <= 80 && mb_check_encoding($v, 'UTF-8') && !preg_match('/[\x00-\x1f\x7f]/', $v) ? $v : null; }
    if (str_starts_with($tipo, 'enum:')) { return in_array($v, explode(',', substr($tipo, 5)), true) ? $v : null; }
    return null;
}

/**
 * Ruta validada para una clave del catálogo, o '' si no existe, no corresponde al portal actual o el rol no tiene permiso.
 * Los parámetros desconocidos o inválidos se descartan en silencio (nunca se reflejan).
 */
function destino_portal(string $clave, array $params = []): string
{
    $catalogo = catalogo_destinos();
    if (!isset($catalogo[$clave])) { return ''; }
    [$portal, $ruta, $permisos, $permitidos] = $catalogo[$clave];
    if ($portal !== portal_destinos()) { return ''; }
    if ($portal === 'equipo' && $permisos) {
        $ok = false;
        foreach ($permisos as $p) { if (puede($p)) { $ok = true; break; } }
        if (!$ok) { return ''; }
    }
    $q = [];
    foreach ($permitidos as $nombre => $tipo) {
        if (!array_key_exists($nombre, $params)) { continue; }
        $v = destino_valor_tipado($tipo, $params[$nombre]);
        if ($v !== null) { $q[$nombre] = $v; }
    }
    return $ruta . ($q ? '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986) : '');
}

/** Campos ocultos que acompañan a un formulario para volver al mismo sitio: `volver` + `r_<parámetro>`. */
function campos_retorno(string $clave, array $params = []): string
{
    $catalogo = catalogo_destinos();
    if (!isset($catalogo[$clave])) { return ''; }
    $html = '<input type="hidden" name="volver" value="' . s($clave) . '">';
    foreach ($catalogo[$clave][3] as $nombre => $tipo) {
        if (!array_key_exists($nombre, $params)) { continue; }
        $v = destino_valor_tipado($tipo, $params[$nombre]);
        if ($v !== null) { $html .= '<input type="hidden" name="r_' . s($nombre) . '" value="' . s($v) . '">'; }
    }
    return $html;
}

/**
 * Destino al que regresar tras un envío. Lee `volver` y `r_*` de $fuente (por omisión $_POST, luego $_GET), los revalida contra el
 * catálogo, el portal y los permisos ACTUALES; si algo no encaja usa $porDefecto y, por último, el inicio del portal.
 * No usa el `Referer` ni acepta URL completas.
 */
function destino_volver(string $porDefecto = '', ?array $fuente = null, array $paramsPorDefecto = []): string
{
    $fuente ??= (!empty($_POST) ? $_POST : $_GET);
    $clave = $fuente['volver'] ?? null;
    if (is_string($clave)) {
        $catalogo = catalogo_destinos();
        if (isset($catalogo[$clave])) {
            $params = [];
            foreach (array_keys($catalogo[$clave][3]) as $nombre) {
                if (isset($fuente['r_' . $nombre])) { $params[$nombre] = $fuente['r_' . $nombre]; }
            }
            $ruta = destino_portal($clave, $params);
            if ($ruta !== '') { return $ruta; }
        }
    }
    if ($porDefecto !== '') {
        $ruta = destino_portal($porDefecto, $paramsPorDefecto);
        if ($ruta !== '') { return $ruta; }
    }
    return inicio_portal();
}

/** Etiqueta legible del destino de «Volver» para una clave (coherente con el portal). */
function etiqueta_destino(string $clave): string
{
    return match ($clave) {
        'agenda' => 'Volver a la agenda', 'agenda_cita' => 'Volver a la atención', 'caja' => 'Volver a caja', 'caja_cita' => 'Volver al cobro',
        'finanzas' => 'Volver a finanzas', 'clientes' => 'Volver a clientes', 'cliente_citas' => 'Volver a mis citas', 'cliente_reservar' => 'Volver a reservar',
        default => 'Volver al inicio',
    };
}

/**
 * Página de error de una OPERACIÓN (cambio de estado, cobro, exportación…) dentro del layout normal, con «Volver» coherente con
 * el portal. Registra el estado HTTP; el llamador termina con detener_ejecucion($codigo).
 */
function responder_error_operacion(int $codigo, string $mensaje, string $porDefecto = '', array $paramsPorDefecto = []): void
{
    $volver = destino_volver($porDefecto, null, $paramsPorDefecto);
    \Classes\ErrorResponse::render($codigo, ['mensaje' => $mensaje, 'volver' => $volver, 'volver_etiqueta' => etiqueta_destino($_POST['volver'] ?? $_GET['volver'] ?? $porDefecto)]);
}

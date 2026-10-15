<?php
/**
 * Destinos centrales por portal y permiso (ronda 5).
 *
 * Toda ruta a la que una pantalla, un formulario, una redirección posterior a guardar/cancelar/fallar, una página de error, la
 * navegación o la «intención» previa a un inicio de sesión "devuelve" a la persona se obtiene de AQUÍ, nunca de una cadena escrita
 * a mano ni del `Referer`. Una clave desconocida, un parámetro fuera de su tipo o un destino que el portal/rol actual no puede usar
 * degradan al inicio del portal de la sesión.
 *
 *  · destino_portal($clave, $params)       → ruta absoluta validada ('' si la clave/portal/permiso no es aplicable)
 *  · campos_retorno($clave, $params)       → <input type="hidden"> que viajan con un formulario (clave + parámetros tipados)
 *  · destino_volver($porDefecto, $fuente)  → destino al que regresar tras procesar un envío, reconstruido y revalidado
 *  · destino_validar_ruta($ruta)           → ruta canónica si una cadena corresponde a una entrada del catálogo usable ahora; si no ''
 *  · responder_error_operacion(...)        → página de error de una operación dentro del layout, con «Volver» del mismo portal
 *  · destino_intencion_actual() / destino_tras_login() → conservar la pantalla pedida antes de iniciar sesión (solo del catálogo)
 *  · navegacion_portal($portal)            → grupos de enlaces del menú lateral según permisos del servidor
 *
 * Portales del catálogo: 'equipo' | 'cliente' | 'ambos' (pantallas personales comunes, p. ej. /perfil; exigen sesión) | 'publico'.
 */

/**
 * Catálogo: clave => [portal, ruta, permisos (cualquiera basta; [] = sin permiso adicional), parámetros permitidos {nombre => tipo}].
 * La ruta '@inicio' se resuelve con inicio_portal() (cada rol tiene su propio inicio: un profesional no puede abrir /admin).
 * Tipos: fecha | id | entero | texto | enum:a,b,c
 *
 * @return array<string,array{0:string,1:string,2:string[],3:array<string,string>}>
 */
function catalogo_destinos(): array
{
    $rango = ['desde' => 'fecha', 'hasta' => 'fecha'];
    $verClientes = ['clientes.ver', 'clientes.analitica', 'clientes.ver_propios'];
    return [
        // ----- Portal del equipo
        'agenda'            => ['equipo', '/agenda', ['agenda.gestionar', 'agenda.ver_propia'], $rango],
        'agenda_cita'       => ['equipo', '/agenda/cita', ['agenda.gestionar', 'agenda.ver_propia'], ['id' => 'id'] + $rango],
        'admin_citas'       => ['equipo', '/admin', ['agenda.gestionar'], ['fecha' => 'fecha']],
        'caja'              => ['equipo', '/caja', ['finanzas.gestionar'], $rango + ['estado' => 'enum:pendiente,parcial,por_verificar,pagado,reembolsado', 'profesional' => 'id', 'metodo' => 'enum:efectivo,transferencia', 'q' => 'texto', 'pagina' => 'entero']],
        'caja_cita'         => ['equipo', '/caja/cita', ['finanzas.gestionar'], ['id' => 'id'] + $rango],
        'caja_comprobante'  => ['equipo', '/caja/comprobante', ['finanzas.gestionar'], ['id' => 'id']],
        'finanzas'          => ['equipo', '/admin/finanzas', ['finanzas.gestionar'], ['inicio' => 'fecha', 'fin' => 'fecha', 'pagina' => 'entero']],
        'clientes'          => ['equipo', '/admin/clientes', $verClientes, [
            'q' => 'texto', 'pagina' => 'entero', 'desde' => 'fecha', 'hasta' => 'fecha', 'membresia' => 'texto', 'confirmado' => 'texto', 'consentimiento' => 'texto',
            'actividad' => 'texto', 'segmento' => 'texto', 'profesional' => 'id', 'orden' => 'texto', 'dir' => 'enum:asc,desc']],
        'clientes_ficha'    => ['equipo', '/admin/clientes/ficha', $verClientes, ['id' => 'id']],
        'clientes_nuevo'    => ['equipo', '/admin/clientes/nuevo', ['clientes.ver'], []],
        'servicios'         => ['equipo', '/servicios', ['servicios.gestionar'], []],
        'servicio_nuevo'    => ['equipo', '/servicios/crear', ['servicios.gestionar'], []],
        'profesionales'     => ['equipo', '/profesionales', ['profesionales.gestionar'], []],
        'profesional_horarios' => ['equipo', '/profesionales/horarios', ['profesionales.gestionar'], ['id' => 'id']],
        'equipo'            => ['equipo', '/admin/equipo', ['equipo.gestionar'], []],
        'equipo_ficha'      => ['equipo', '/admin/equipo/ficha', ['rh.gestionar'], ['id' => 'id']],
        'equipo_reasignar'  => ['equipo', '/admin/equipo/reasignar', ['agenda.gestionar'], ['id' => 'id']],
        'inventario'        => ['equipo', '/admin/inventario', ['inventario.gestionar'], ['pagina' => 'entero']],
        'reportes'          => ['equipo', '/admin/reportes', ['reportes.ver'], []],
        'crecimiento'       => ['equipo', '/admin/crecimiento', ['marketing.gestionar'], []],
        'ajustes'           => ['equipo', '/admin/ajustes', ['ajustes.gestionar'], []],
        'inicio_equipo'     => ['equipo', '@inicio', [], []],
        // ----- Portal de clientes
        'cliente_reservar'  => ['cliente', '/cita', [], []],
        'cliente_citas'     => ['cliente', '/mis-citas', [], []],
        'cliente_bienestar' => ['cliente', '/mi-bienestar', [], []],
        // ----- Pantallas personales comunes (exigen sesión de cualquiera de los dos portales)
        'perfil'            => ['ambos', '/perfil', [], []],
        // ----- Accesos públicos (el inicio de sesión de cada portal)
        'login_cliente'     => ['publico', '/', [], []],
        'login_equipo'      => ['publico', '/equipo/login', [], []],
    ];
}

/** Portal efectivo de la sesión actual ('cliente' | 'equipo'); sin sesión, 'cliente'. */
function portal_destinos(): string
{
    return (($_SESSION['login'] ?? false) === true) ? portal_sesion() : 'cliente';
}

/** Valor normalizado según su tipo, o null si no es válido (arreglos, objetos y cualquier otro tipo no escalar se descartan). */
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

/** ¿Algún permiso de la lista concede acceso? ([] = sin permiso adicional). Se evalúa en el servidor, nunca con datos del navegador. */
function destino_permiso_ok(array $permisos, ?callable $puede = null): bool
{
    if (!$permisos) { return true; }
    $puede ??= 'puede';
    foreach ($permisos as $p) { if ($puede($p)) { return true; } }
    return false;
}

/**
 * Núcleo de destino_portal() con el criterio de permiso inyectable (el menú lo evalúa una sola vez por petición).
 */
function destino_resolver(string $clave, array $params, ?callable $puede = null, ?string $portalActual = null): string
{
    $catalogo = catalogo_destinos();
    if (!isset($catalogo[$clave])) { return ''; }
    [$portal, $ruta, $permisos, $permitidos] = $catalogo[$clave];
    $portalActual ??= portal_destinos();
    $logueado = ($_SESSION['login'] ?? false) === true;
    if ($portal === 'ambos') {
        if (!$logueado) { return ''; }
    } elseif ($portal !== 'publico' && $portal !== $portalActual) {
        return '';
    }
    if ($portal === 'equipo' && !destino_permiso_ok($permisos, $puede)) { return ''; }
    if ($ruta === '@inicio') { return $logueado ? inicio_portal() : ''; }
    $q = [];
    foreach ($permitidos as $nombre => $tipo) {
        if (!array_key_exists($nombre, $params)) { continue; }
        $v = destino_valor_tipado($tipo, $params[$nombre]);
        if ($v !== null) { $q[$nombre] = $v; }
    }
    return $ruta . ($q ? '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986) : '');
}

/**
 * Ruta validada para una clave del catálogo, o '' si no existe, no corresponde al portal actual o el rol no tiene permiso.
 * Los parámetros desconocidos o inválidos se descartan en silencio (nunca se reflejan).
 */
function destino_portal(string $clave, array $params = []): string
{
    return destino_resolver($clave, $params);
}

/** Como destino_portal(), pero degrada al inicio del portal de la sesión en lugar de devolver ''. */
function destino_o_inicio(string $clave, array $params = []): string
{
    $ruta = destino_portal($clave, $params);
    return $ruta !== '' ? $ruta : inicio_portal();
}

/**
 * Una cadena cualquiera (p. ej. la opción `volver` de una página de error) → ruta canónica solo si coincide con una entrada del
 * catálogo que el portal y los permisos ACTUALES permiten; los parámetros se re-tipan y los desconocidos se descartan.
 * Rechaza URL completas, `//host`, `\`, bytes de control, rutas relativas y cadenas largas.
 */
function destino_validar_ruta(string $ruta): string
{
    if ($ruta === '' || strlen($ruta) > 1024 || $ruta[0] !== '/' || str_starts_with($ruta, '//') || preg_match('/[\x00-\x1f\x7f\\\\]/', $ruta)) { return ''; }
    $p = parse_url($ruta);
    if ($p === false || isset($p['host']) || isset($p['scheme']) || !isset($p['path'])) { return ''; }
    $consulta = [];
    if (isset($p['query'])) { parse_str($p['query'], $consulta); }
    $inicio = (($_SESSION['login'] ?? false) === true) ? inicio_portal() : '';
    foreach (catalogo_destinos() as $clave => $def) {
        $rutaDef = $def[1] === '@inicio' ? $inicio : $def[1];
        if ($rutaDef === '' || $rutaDef !== $p['path']) { continue; }
        $resuelta = destino_portal($clave, $consulta);
        if ($resuelta !== '') { return $resuelta; }
    }
    return '';
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
 * Igual que destino_volver() pero devuelve también la clave del catálogo que se aplicó ('' si se degradó al inicio del portal).
 *
 * @return array{0:string,1:string} [ruta, clave]
 */
function destino_volver_con_clave(string $porDefecto = '', ?array $fuente = null, array $paramsPorDefecto = []): array
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
            if ($ruta !== '') { return [$ruta, $clave]; }
        }
    }
    if ($porDefecto !== '') {
        $ruta = destino_portal($porDefecto, $paramsPorDefecto);
        if ($ruta !== '') { return [$ruta, $porDefecto]; }
    }
    return [inicio_portal(), ''];
}

/**
 * Destino al que regresar tras un envío. Lee `volver` y `r_*` de $fuente (por omisión $_POST, luego $_GET), los revalida contra el
 * catálogo, el portal y los permisos ACTUALES; si algo no encaja usa $porDefecto y, por último, el inicio del portal.
 * No usa el `Referer` ni acepta URL completas.
 */
function destino_volver(string $porDefecto = '', ?array $fuente = null, array $paramsPorDefecto = []): string
{
    return destino_volver_con_clave($porDefecto, $fuente, $paramsPorDefecto)[0];
}

/** Etiqueta legible del destino de «Volver» para una clave (coherente con el portal). */
function etiqueta_destino(string $clave): string
{
    return match ($clave) {
        'agenda' => 'Volver a la agenda', 'agenda_cita' => 'Volver a la atención', 'admin_citas' => 'Volver a las citas del día',
        'caja' => 'Volver a caja', 'caja_cita' => 'Volver al cobro', 'caja_comprobante' => 'Volver al comprobante',
        'finanzas' => 'Volver a finanzas', 'clientes' => 'Volver a clientes', 'clientes_ficha' => 'Volver a la ficha del cliente',
        'servicios' => 'Volver a servicios', 'profesionales' => 'Volver a profesionales', 'profesional_horarios' => 'Volver a los horarios',
        'equipo' => 'Volver al equipo', 'equipo_ficha' => 'Volver a la ficha laboral', 'inventario' => 'Volver al inventario',
        'reportes' => 'Volver a reportes', 'crecimiento' => 'Volver a crecimiento', 'ajustes' => 'Volver a ajustes',
        'cliente_citas' => 'Volver a mis citas', 'cliente_reservar' => 'Volver a reservar', 'cliente_bienestar' => 'Volver a mi bienestar',
        'perfil' => 'Volver a mi perfil',
        default => 'Volver al inicio',
    };
}

/**
 * Página de error de una OPERACIÓN (cambio de estado, cobro, exportación…) dentro del layout normal, con «Volver» coherente con
 * el portal. Registra el estado HTTP; el llamador termina con detener_ejecucion($codigo).
 */
function responder_error_operacion(int $codigo, string $mensaje, string $porDefecto = '', array $paramsPorDefecto = []): void
{
    [$volver, $clave] = destino_volver_con_clave($porDefecto, null, $paramsPorDefecto);
    \Classes\ErrorResponse::render($codigo, ['mensaje' => $mensaje, 'volver' => $volver, 'volver_etiqueta' => etiqueta_destino($clave)]);
}

// ---------------------------------------------------------------------------------------------------------------------------
// Intención previa al inicio de sesión: la pantalla del catálogo que se pidió (GET) antes de que la sesión expirara o cambiara.
// ---------------------------------------------------------------------------------------------------------------------------

/**
 * Entrada del catálogo (clave + parámetros tipados) que corresponde a la petición GET actual, o null. No consulta la sesión: se
 * usa justo cuando ya no hay una válida. Solo páginas HTML (nunca /api ni envíos POST) y nunca el inicio de sesión mismo.
 *
 * @return array{clave:string,params:array<string,string>}|null
 */
function destino_intencion_actual(): ?array
{
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') { return null; }
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    if ($uri === '' || strlen($uri) > 2048 || preg_match('/[\x00-\x1f\x7f\\\\]/', $uri) || str_starts_with($uri, '//')) { return null; }
    $p = parse_url($uri);
    if ($p === false || !isset($p['path']) || isset($p['host'])) { return null; }
    $consulta = [];
    if (isset($p['query'])) { parse_str($p['query'], $consulta); }
    foreach (catalogo_destinos() as $clave => [$portal, $ruta, , $permitidos]) {
        if ($portal === 'publico' || $ruta === '@inicio' || $ruta !== $p['path']) { continue; }
        $params = [];
        foreach ($permitidos as $nombre => $tipo) {
            if (!array_key_exists($nombre, $consulta)) { continue; }
            $v = destino_valor_tipado($tipo, $consulta[$nombre]);
            if ($v !== null) { $params[$nombre] = $v; }
        }
        return ['clave' => $clave, 'params' => $params];
    }
    return null;
}

/**
 * Destino tras abrir sesión en `$portal`: la intención guardada solo si es de ese portal (o común) y los permisos de la sesión
 * recién abierta la permiten; en cualquier otro caso, el inicio del portal. Debe llamarse con la sesión ya construida.
 */
function destino_tras_login(?array $intencion, string $portal): string
{
    if (is_array($intencion) && is_string($intencion['clave'] ?? null) && is_array($intencion['params'] ?? null)) {
        $def = catalogo_destinos()[$intencion['clave']] ?? null;
        if ($def !== null && ($def[0] === 'ambos' || $def[0] === $portal)) {
            $ruta = destino_portal($intencion['clave'], $intencion['params']);
            if ($ruta !== '') { return $ruta; }
        }
    }
    return inicio_portal();
}

// ---------------------------------------------------------------------------------------------------------------------------
// Navegación lateral por portal
// ---------------------------------------------------------------------------------------------------------------------------

/**
 * ¿La ruta está registrada en el enrutador de esta petición? Sin enrutador conocido (pruebas unitarias, renderizado de errores)
 * se asume que sí. Solo se usa para enlaces de módulos que pueden no estar desplegados todavía (p. ej. /caja).
 */
function destino_ruta_registrada(string $ruta): bool
{
    $router = $GLOBALS['__appsalon_router'] ?? null;
    if (!is_object($router) || !property_exists($router, 'getRoutes')) { return true; }
    return isset($router->getRoutes[strtok($ruta, '?')]);
}

/**
 * Grupos de enlaces del menú lateral del portal indicado, según los permisos verificados en el servidor.
 * Cada enlace: [href, texto, icono, clave del catálogo]. Un grupo sin enlaces no se devuelve.
 *
 * @return array<int,array{titulo:string,enlaces:array<int,array{0:string,1:string,2:string,3:string}>}>
 */
function navegacion_portal(string $portal): array
{
    if ($portal === 'cliente') {
        return [
            ['titulo' => 'Tus citas', 'enlaces' => [['/cita', 'Reservar Cita', 'calendar', 'cliente_reservar'], ['/mis-citas', 'Mis Citas', 'check', 'cliente_citas']]],
            ['titulo' => 'Tu cuenta', 'enlaces' => [['/perfil', 'Mi Perfil', 'users', 'perfil'], ['/mi-bienestar', 'Mi Bienestar', 'sparkles', 'cliente_bienestar']]],
        ];
    }
    // Los permisos se leen una sola vez (puede() consulta la base en cada llamada).
    $permisos = null;
    $puede = static function (string $permiso) use (&$permisos): bool {
        if ($permisos === null) {
            $permisos = [];
            foreach (\Services\AuthorizationService::PERMISOS as $p) { if (puede($p)) { $permisos[$p] = true; } }
        }
        return isset($permisos[$permiso]);
    };
    $enlace = static function (string $clave, string $texto, string $icono, bool $opcional = false) use ($puede): ?array {
        $ruta = destino_resolver($clave, [], $puede, 'equipo');
        if ($ruta === '' || ($opcional && !destino_ruta_registrada($ruta))) { return null; }
        return [$ruta, $texto, $icono, $clave];
    };
    $clientes = null;
    foreach ([['clientes.ver', 'Clientes', 'users'], ['clientes.analitica', 'Analítica de clientes', 'chart'], ['clientes.ver_propios', 'Mis clientes', 'users']] as [$permiso, $texto, $icono]) {
        if ($puede($permiso)) { $clientes = $enlace('clientes', $texto, $icono); break; }
    }
    $grupos = [
        ['titulo' => 'Tu día', 'enlaces' => [
            $enlace('admin_citas', 'Citas del día', 'calendar'), $enlace('agenda', 'Agenda', 'calendar'), $enlace('caja', 'Caja', 'chart', true)]],
        ['titulo' => 'Servicios y equipo', 'enlaces' => [
            $enlace('servicios', 'Servicios', 'sparkles'), $enlace('servicio_nuevo', 'Nuevo Servicio', 'plus'),
            $enlace('profesionales', 'Profesionales', 'users'), $enlace('equipo', 'Equipo y Permisos', 'users')]],
        ['titulo' => 'Gestión comercial', 'enlaces' => [
            $enlace('finanzas', 'Finanzas', 'chart'), $enlace('inventario', 'Inventario', 'check'), $enlace('reportes', 'Reportes', 'chart'),
            $enlace('crecimiento', 'Crecimiento y fidelidad', 'sparkles'), $clientes]],
        ['titulo' => 'Ajustes del negocio', 'enlaces' => [$enlace('ajustes', 'Ajustes del negocio', 'check')]],
        ['titulo' => 'Mi cuenta', 'enlaces' => [['/perfil', 'Mi cuenta', 'users', 'perfil']]],
    ];
    foreach ($grupos as $i => $g) {
        $grupos[$i]['enlaces'] = array_values(array_filter($g['enlaces']));
        if (!$grupos[$i]['enlaces']) { unset($grupos[$i]); }
    }
    return array_values($grupos);
}

/**
 * Href de la sección actual del menú: la coincidencia más específica (el prefijo más largo) para que no se marquen dos secciones.
 *
 * @param array<int,array{titulo:string,enlaces:array<int,array{0:string,1:string,2:string,3:string}>}> $grupos
 */
function navegacion_enlace_activo(array $grupos, string $rutaActual): string
{
    $ruta = rtrim(parse_url($rutaActual, PHP_URL_PATH) ?: '/', '/') ?: '/';
    $activo = '';
    foreach ($grupos as $grupo) {
        foreach ($grupo['enlaces'] as $enlace) {
            $href = strtok($enlace[0], '?');
            $coincide = $ruta === $href || ($href !== '/admin' && str_starts_with($ruta, $href . '/'));
            if ($coincide && strlen($href) > strlen($activo)) { $activo = $enlace[0]; }
        }
    }
    return $activo;
}

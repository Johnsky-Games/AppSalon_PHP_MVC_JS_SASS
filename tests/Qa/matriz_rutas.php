<?php
/**
 * Matriz declarativa de rutas de AppSalon para el QA de autorización (Ronda 2).
 *
 * Fuente: `public/index.php` y `includes/rutas/*.php` (TODAS las rutas registradas; una prueba compara este archivo con las llamadas `$router->get/post`)
 * y los controladores/`funciones.php`. Los contratos se DERIVARON LEYENDO el código (no copiando respuestas observadas).
 *
 * Campos de cada ruta:
 *  m           método registrado (un GET registrado atiende también HEAD: Router::comprobarRutas)
 *  p           ruta; marcadores: {cliente} {ajeno} {col_staff} {uid_staff} (se resuelven en la prueba)
 *  g           guardia: publica | sesion (isAuth) | permiso (exigir_permiso = isAuth + puede)
 *  roles       roles de equipo que superan la guardia (derivados de AuthorizationService::ROLES, escritos a mano)
 *  orden       POST: auth (autenticación/permiso antes que CSRF) | csrf (CSRF antes que isAuth) | solo_csrf (pública)
 *  csrf        forma del 403 por CSRF: exigir | silencioso | json_api
 *  unauth_json JSON propio del controlador cuando NO hay sesión autenticada (login!==true o id inválido)
 *  p_csrf      ruta alternativa (con id válido) para probar el CSRF: sin id el controlador descarta la petición antes de mirar el token
 *  ok          contrato para una persona que supera la guardia con una petición benigna (sin datos de negocio válidos → sin efectos)
 *  ok_por_rol  excepciones del contrato «ok» por rol de equipo
 *  deny_msg    mensaje 403 distinto del estándar por rol
 *
 * Especificación de respuesta: [status, tipo, extra] con tipo html|json|csv|redir; extra = texto(s) que debe contener (html),
 * subconjunto de claves exactas (json) o Location exacto (redir).
 */
return [
    // ------------------------------------------------------------- públicas (portales de clientes y equipo)
    ['m' => 'GET',  'p' => '/',                      'g' => 'publica', 'ok' => [200, 'html']],
    ['m' => 'POST', 'p' => '/',                      'g' => 'publica', 'orden' => 'solo_csrf', 'csrf' => 'exigir', 'ok' => [422, 'html']],
    ['m' => 'GET',  'p' => '/cliente/login',         'g' => 'publica', 'ok' => [200, 'html']],
    ['m' => 'POST', 'p' => '/cliente/login',         'g' => 'publica', 'orden' => 'solo_csrf', 'csrf' => 'exigir', 'ok' => [422, 'html']],
    ['m' => 'GET',  'p' => '/cliente/registro',      'g' => 'publica', 'ok' => [200, 'html']],
    ['m' => 'POST', 'p' => '/cliente/registro',      'g' => 'publica', 'orden' => 'solo_csrf', 'csrf' => 'exigir', 'ok' => [422, 'html']],
    ['m' => 'GET',  'p' => '/crear-cuenta',          'g' => 'publica', 'ok' => [200, 'html']],
    ['m' => 'POST', 'p' => '/crear-cuenta',          'g' => 'publica', 'orden' => 'solo_csrf', 'csrf' => 'exigir', 'ok' => [422, 'html']],
    ['m' => 'GET',  'p' => '/equipo/login',          'g' => 'publica', 'ok' => [200, 'html']],
    ['m' => 'POST', 'p' => '/equipo/login',          'g' => 'publica', 'orden' => 'solo_csrf', 'csrf' => 'exigir', 'ok' => [422, 'html']],
    ['m' => 'GET',  'p' => '/equipo/aceptar',        'g' => 'publica', 'ok' => [404, 'html']],
    ['m' => 'POST', 'p' => '/equipo/aceptar',        'g' => 'publica', 'orden' => 'solo_csrf', 'csrf' => 'exigir', 'ok' => [422, 'html']],
    ['m' => 'GET',  'p' => '/logout',                'g' => 'publica', 'ok' => [302, 'redir', '/']],
    ['m' => 'POST', 'p' => '/logout',                'g' => 'publica', 'orden' => 'solo_csrf', 'csrf' => 'exigir', 'ok' => [302, 'redir', '@portal']],
    ['m' => 'GET',  'p' => '/olvide',                'g' => 'publica', 'ok' => [200, 'html']],
    ['m' => 'POST', 'p' => '/olvide',                'g' => 'publica', 'orden' => 'solo_csrf', 'csrf' => 'exigir', 'ok' => [422, 'html']],
    ['m' => 'GET',  'p' => '/recuperar',             'g' => 'publica', 'ok' => [410, 'html']],
    ['m' => 'POST', 'p' => '/recuperar',             'g' => 'publica', 'orden' => 'solo_csrf', 'csrf' => 'exigir', 'ok' => [422, 'html']],
    ['m' => 'GET',  'p' => '/confirmar-cuenta',      'g' => 'publica', 'ok' => [410, 'html']],
    // Ronda 3: la activación es un POST (CSRF obligatorio; sin token en el cuerpo es el 410 genérico). El GET solo valida el enlace.
    ['m' => 'POST', 'p' => '/confirmar-cuenta',      'g' => 'publica', 'orden' => 'solo_csrf', 'csrf' => 'exigir', 'ok' => [410, 'html']],
    ['m' => 'GET',  'p' => '/mensaje',               'g' => 'publica', 'ok' => [200, 'html']],
    ['m' => 'GET',  'p' => '/reenviar-confirmacion', 'g' => 'publica', 'ok' => [200, 'html']],
    ['m' => 'POST', 'p' => '/reenviar-confirmacion', 'g' => 'publica', 'orden' => 'solo_csrf', 'csrf' => 'exigir', 'ok' => [422, 'html']],
    ['m' => 'GET',  'p' => '/api/servicios',         'g' => 'publica', 'ok' => [200, 'json_lista']],
    ['m' => 'POST', 'p' => '/api/pagos/webhook',     'g' => 'publica', 'orden' => 'ninguno', 'ok' => [401, 'json', ['resultado' => false, 'status' => 'unauthorized', 'codigo_error' => 'INVALID_WEBHOOK_SIGNATURE', 'errores' => ['Firma de notificación webhook inválida o ausente.']]]],

    // ------------------------------------------------------------- cualquier sesión vigente (isAuth)
    ['m' => 'GET',  'p' => '/cita',                  'g' => 'sesion', 'ok' => [200, 'html']],
    ['m' => 'GET',  'p' => '/mis-citas',             'g' => 'sesion', 'ok' => [200, 'html']],
    ['m' => 'GET',  'p' => '/perfil',                'g' => 'sesion', 'ok' => [200, 'html']],
    ['m' => 'POST', 'p' => '/perfil',                'g' => 'sesion', 'orden' => 'auth', 'csrf' => 'exigir', 'ok' => [422, 'html']],
    ['m' => 'POST', 'p' => '/perfil/foto',           'g' => 'sesion', 'orden' => 'auth', 'csrf' => 'exigir', 'ok' => [422, 'html', 'Hay información por revisar']],
    ['m' => 'GET',  'p' => '/mi-bienestar',          'g' => 'sesion', 'ok' => [200, 'html']],
    ['m' => 'POST', 'p' => '/mi-bienestar',          'g' => 'sesion', 'orden' => 'auth', 'csrf' => 'silencioso', 'ok' => [422, 'html']],
    ['m' => 'GET',  'p' => '/pago/resultado',        'g' => 'sesion', 'ok' => [422, 'html']],
    ['m' => 'POST', 'p' => '/agenda/estado',         'g' => 'sesion', 'orden' => 'auth', 'csrf' => 'exigir', 'ok' => [422, 'html', 'No se pudo actualizar la cita']],
    ['m' => 'POST', 'p' => '/agenda/reprogramar',    'g' => 'sesion', 'orden' => 'auth', 'csrf' => 'exigir', 'ok' => [422, 'html', 'No se pudo actualizar la cita']],
    // Regularización de la atención de citas de días anteriores (decisión del usuario, docs/R5_REGULARIZACION.md): permiso antes que CSRF.
    ['m' => 'POST', 'p' => '/agenda/regularizar',          'g' => 'permiso', 'roles' => ['propietario', 'gerente'], 'orden' => 'auth', 'csrf' => 'exigir', 'ok' => [422, 'html']],
    ['m' => 'POST', 'p' => '/agenda/regularizar/rechazar', 'g' => 'permiso', 'roles' => ['propietario', 'gerente'], 'orden' => 'auth', 'csrf' => 'exigir', 'ok' => [422, 'html']],
    ['m' => 'POST', 'p' => '/agenda/regularizar/solicitar', 'g' => 'permiso', 'roles' => ['propietario', 'profesional'], 'orden' => 'auth', 'csrf' => 'exigir', 'ok' => [422, 'html']],
    ['m' => 'GET',  'p' => '/api/profesionales',     'g' => 'sesion', 'ok' => [200, 'json', ['status' => 'ok', 'codigo' => 'profesionales_listados', 'resultado' => true]],
        'unauth_json' => ['resultado' => false, 'status' => 'unauthorized', 'codigo' => 'no_autenticado', 'profesionales' => [], 'profesionales_compatibles' => [], 'error' => 'No autenticado']],
    ['m' => 'GET',  'p' => '/api/disponibilidad',    'g' => 'sesion', 'ok' => [422, 'json', ['status' => 'invalid', 'codigo' => 'solicitud_invalida', 'resultado' => false, 'disponible' => false, 'intervalos' => []]],
        'unauth_json' => ['resultado' => false, 'disponible' => false, 'status' => 'unauthorized', 'codigo' => 'no_autenticado', 'intervalos' => [], 'error' => 'No autenticado']],
    ['m' => 'GET',  'p' => '/api/mis-citas',         'g' => 'sesion', 'ok' => [200, 'json', ['status' => 'ok', 'codigo' => 'citas_cliente_listadas', 'resultado' => true]],
        'unauth_json' => ['resultado' => false, 'status' => 'unauthorized', 'codigo' => 'no_autenticado', 'citas' => [], 'error' => 'No autenticado']],
    ['m' => 'POST', 'p' => '/api/citas',             'g' => 'sesion', 'orden' => 'auth', 'csrf' => 'json_api', 'ok' => [422, 'json', ['resultado' => false, 'status' => 'invalid', 'codigo' => 'solicitud_invalida']],
        'unauth_json' => ['resultado' => false, 'error' => 'No autenticado']],
    // CAMBIO DE CONTRATO (ronda 2, DEF-R2-02 corregido): un id inválido ya no redirige a /admin (403 para casi todos) sino al inicio del portal
    // de la sesión (destino_retorno_seguro → inicio_portal): '@inicio' = /admin (propietario, gerente, recepción), /agenda (profesional),
    // /admin/finanzas (finanzas), /cita (cliente).
    ['m' => 'POST', 'p' => '/api/eliminar',          'g' => 'sesion', 'orden' => 'csrf', 'csrf' => 'exigir', 'ok' => [302, 'redir', '@inicio'], 'ok_por_rol' => ['cliente' => [302, 'redir', '/mis-citas']]],
    ['m' => 'POST', 'p' => '/api/pagos/iniciar',     'g' => 'sesion', 'orden' => 'auth', 'csrf' => 'json_api', 'csrf_json' => ['resultado' => false, 'status' => 'forbidden', 'error' => 'Token CSRF inválido o ausente'], 'ok' => [422, 'json', ['resultado' => false, 'status' => 'invalid', 'errores' => ['El identificador de la cita es obligatorio y debe ser un entero positivo.']]],
        'unauth_json' => ['resultado' => false, 'status' => 'unauthorized', 'error' => 'No autenticado']],

    // ------------------------------------------------------------- por permiso (exigir_permiso)
    ['m' => 'GET',  'p' => '/admin',                 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'recepcion'], 'ok' => [200, 'html']],
    ['m' => 'GET',  'p' => '/agenda',                'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'recepcion', 'profesional'], 'ok' => [200, 'html']],
    ['m' => 'GET',  'p' => '/agenda/pendientes',     'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'recepcion', 'profesional'], 'ok' => [200, 'html']],
    ['m' => 'GET',  'p' => '/servicios',             'g' => 'permiso', 'roles' => ['propietario', 'gerente'], 'ok' => [200, 'html']],
    ['m' => 'GET',  'p' => '/servicios/crear',       'g' => 'permiso', 'roles' => ['propietario', 'gerente'], 'ok' => [200, 'html']],
    ['m' => 'POST', 'p' => '/servicios/crear',       'g' => 'permiso', 'roles' => ['propietario', 'gerente'], 'orden' => 'auth', 'csrf' => 'exigir', 'ok' => [200, 'html']],
    ['m' => 'GET',  'p' => '/servicios/actualizar',  'g' => 'permiso', 'roles' => ['propietario', 'gerente'], 'ok' => [302, 'redir', '/servicios']],
    ['m' => 'POST', 'p' => '/servicios/actualizar',  'p_csrf' => '/servicios/actualizar?id={servicio}', 'g' => 'permiso', 'roles' => ['propietario', 'gerente'], 'orden' => 'auth', 'csrf' => 'exigir', 'ok' => [302, 'redir', '/servicios']],
    ['m' => 'POST', 'p' => '/servicios/eliminar',    'g' => 'permiso', 'roles' => ['propietario', 'gerente'], 'orden' => 'auth', 'csrf' => 'exigir', 'ok' => [302, 'redir', '/servicios']],
    ['m' => 'GET',  'p' => '/profesionales',         'g' => 'permiso', 'roles' => ['propietario', 'gerente'], 'ok' => [200, 'html']],
    ['m' => 'GET',  'p' => '/profesionales/crear',   'g' => 'permiso', 'roles' => ['propietario'], 'ok' => [200, 'html']],
    ['m' => 'POST', 'p' => '/profesionales/crear',   'g' => 'permiso', 'roles' => ['propietario'], 'orden' => 'auth', 'csrf' => 'exigir', 'ok' => [422, 'html']],
    ['m' => 'GET',  'p' => '/profesionales/actualizar', 'g' => 'permiso', 'roles' => ['propietario', 'gerente'], 'ok' => [302, 'redir', '/profesionales']],
    ['m' => 'POST', 'p' => '/profesionales/actualizar', 'p_csrf' => '/profesionales/actualizar?id={pro}', 'g' => 'permiso', 'roles' => ['propietario', 'gerente'], 'orden' => 'auth', 'csrf' => 'exigir', 'ok' => [302, 'redir', '/profesionales']],
    ['m' => 'POST', 'p' => '/profesionales/estado', 'g' => 'permiso', 'roles' => ['propietario', 'gerente'], 'orden' => 'auth', 'csrf' => 'exigir', 'ok' => [422, 'html']],
    ['m' => 'GET',  'p' => '/profesionales/horarios', 'g' => 'permiso', 'roles' => ['propietario', 'gerente'], 'ok' => [302, 'redir', '/profesionales']],
    ['m' => 'POST', 'p' => '/profesionales/horarios', 'p_csrf' => '/profesionales/horarios?id={pro}', 'g' => 'permiso', 'roles' => ['propietario', 'gerente'], 'orden' => 'auth', 'csrf' => 'exigir', 'ok' => [302, 'redir', '/profesionales']],
    ['m' => 'POST', 'p' => '/profesionales/descansos/crear', 'p_csrf' => '/profesionales/descansos/crear?id={pro}', 'g' => 'permiso', 'roles' => ['propietario', 'gerente'], 'orden' => 'auth', 'csrf' => 'exigir', 'ok' => [302, 'redir', '/profesionales']],
    ['m' => 'POST', 'p' => '/profesionales/descansos/eliminar', 'p_csrf' => '/profesionales/descansos/eliminar?id={pro}', 'g' => 'permiso', 'roles' => ['propietario', 'gerente'], 'orden' => 'auth', 'csrf' => 'exigir', 'ok' => [302, 'redir', '/profesionales']],
    ['m' => 'POST', 'p' => '/profesionales/bloqueos/crear', 'p_csrf' => '/profesionales/bloqueos/crear?id={pro}', 'g' => 'permiso', 'roles' => ['propietario', 'gerente'], 'orden' => 'auth', 'csrf' => 'exigir', 'ok' => [302, 'redir', '/profesionales']],
    ['m' => 'POST', 'p' => '/profesionales/bloqueos/eliminar', 'p_csrf' => '/profesionales/bloqueos/eliminar?id={pro}', 'g' => 'permiso', 'roles' => ['propietario', 'gerente'], 'orden' => 'auth', 'csrf' => 'exigir', 'ok' => [302, 'redir', '/profesionales']],
    ['m' => 'GET',  'p' => '/admin/reportes',        'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'finanzas'], 'ok' => [200, 'html']],
    ['m' => 'GET',  'p' => '/admin/reportes/exportar', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'finanzas'], 'ok' => [200, 'csv']],
    ['m' => 'GET',  'p' => '/admin/equipo',          'g' => 'permiso', 'roles' => ['propietario'], 'ok' => [200, 'html']],
    ['m' => 'GET',  'p' => '/admin/recursos-humanos', 'g' => 'permiso', 'roles' => ['propietario'], 'ok' => [200, 'html']],
    ['m' => 'GET',  'p' => '/admin/recursos-humanos/ficha?id={col_staff}', 'g' => 'permiso', 'roles' => ['propietario'], 'ok' => [200, 'html']],
    ['m' => 'POST', 'p' => '/admin/recursos-humanos/ficha', 'p_csrf' => '/admin/recursos-humanos/ficha?id={col_staff}', 'g' => 'permiso', 'roles' => ['propietario'], 'orden' => 'auth', 'csrf' => 'exigir', 'ok' => [404, 'html', 'No encontramos esta página']],
    ['m' => 'POST', 'p' => '/admin/recursos-humanos/ficha/agenda', 'p_csrf' => '/admin/recursos-humanos/ficha/agenda?id={col_staff}', 'g' => 'permiso', 'roles' => ['propietario'], 'orden' => 'auth', 'csrf' => 'exigir', 'ok' => [404, 'html', 'No encontramos esta página']],
    ['m' => 'POST', 'p' => '/admin/equipo/crear',    'g' => 'permiso', 'roles' => ['propietario'], 'orden' => 'auth', 'csrf' => 'exigir', 'ok' => [302, 'redir', '/admin/equipo']],
    ['m' => 'POST', 'p' => '/admin/equipo/actualizar', 'g' => 'permiso', 'roles' => ['propietario'], 'orden' => 'auth', 'csrf' => 'exigir', 'ok' => [302, 'redir', '/admin/equipo']],
    ['m' => 'POST', 'p' => '/admin/equipo/invitar',  'g' => 'permiso', 'roles' => ['propietario'], 'orden' => 'auth', 'csrf' => 'exigir', 'ok' => [302, 'redir', '/admin/equipo']],
    ['m' => 'POST', 'p' => '/admin/equipo/revocar',  'g' => 'permiso', 'roles' => ['propietario'], 'orden' => 'auth', 'csrf' => 'exigir', 'ok' => [302, 'redir', '/admin/equipo']],
    ['m' => 'GET',  'p' => '/admin/equipo/ficha?id={col_staff}', 'g' => 'permiso', 'roles' => ['propietario'], 'ok' => [200, 'html']],
    ['m' => 'GET',  'p' => '/admin/equipo/ficha?id=987654', 'g' => 'permiso', 'roles' => ['propietario'], 'ok' => [404, 'html', 'No encontramos esta página']],
    ['m' => 'POST', 'p' => '/admin/equipo/ficha',    'p_csrf' => '/admin/equipo/ficha?id={col_staff}', 'g' => 'permiso', 'roles' => ['propietario'], 'orden' => 'auth', 'csrf' => 'exigir', 'ok' => [404, 'html', 'No encontramos esta página']],
    // Ronda 2B: asistente de agenda de la ficha (rh.gestionar + profesionales.gestionar: hoy solo el propietario reúne ambos).
    ['m' => 'POST', 'p' => '/admin/equipo/ficha/agenda', 'p_csrf' => '/admin/equipo/ficha/agenda?id={col_staff}', 'g' => 'permiso', 'roles' => ['propietario'], 'orden' => 'auth', 'csrf' => 'exigir', 'ok' => [404, 'html', 'No encontramos esta página']],
    // Reasignación asistida de citas (agenda.gestionar). Sin id → 404; con id de un colaborador SIN ficha profesional → 404; con un profesional → 200.
    ['m' => 'GET',  'p' => '/admin/equipo/reasignar', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'recepcion'], 'ok' => [404, 'html', 'No encontramos esta página']],
    ['m' => 'GET',  'p' => '/admin/equipo/reasignar?id={col_staff}', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'recepcion'], 'ok' => [404, 'html', 'No encontramos esta página']],
    ['m' => 'GET',  'p' => '/admin/equipo/reasignar?id={col_prof}', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'recepcion'], 'ok' => [200, 'html']],
    ['m' => 'POST', 'p' => '/admin/equipo/reasignar', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'recepcion'], 'orden' => 'auth', 'csrf' => 'exigir', 'ok' => [302, 'redir', '@inicio']], // fase 2 (R2B-11): el error de validación vuelve al inicio del portal del actor (inicio_portal()): /admin para propietario, gerente y recepción
    ['m' => 'GET',  'p' => '/admin/finanzas',        'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'finanzas'], 'ok' => [200, 'html']],
    ['m' => 'POST', 'p' => '/admin/finanzas',        'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'finanzas'], 'orden' => 'auth', 'csrf' => 'exigir', 'ok' => [303, 'redir', '/admin/finanzas']],
    ['m' => 'GET',  'p' => '/admin/inventario',      'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'finanzas'], 'ok' => [200, 'html']],
    ['m' => 'POST', 'p' => '/admin/inventario',      'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'finanzas'], 'orden' => 'auth', 'csrf' => 'exigir', 'ok' => [303, 'redir', '/admin/inventario']],
    ['m' => 'GET',  'p' => '/admin/crecimiento',     'g' => 'permiso', 'roles' => ['propietario', 'gerente'], 'ok' => [200, 'html']],
    ['m' => 'POST', 'p' => '/admin/crecimiento',     'g' => 'permiso', 'roles' => ['propietario', 'gerente'], 'orden' => 'auth', 'csrf' => 'silencioso', 'ok' => [422, 'html']],
    ['m' => 'GET',  'p' => '/admin/ajustes',         'g' => 'permiso', 'roles' => ['propietario', 'gerente'], 'ok' => [200, 'html']],
    ['m' => 'POST', 'p' => '/admin/ajustes',         'g' => 'permiso', 'roles' => ['propietario', 'gerente'], 'orden' => 'auth', 'csrf' => 'silencioso', 'ok' => [422, 'html']],
    ['m' => 'GET',  'p' => '/admin/clientes',        'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'recepcion', 'profesional', 'finanzas'], 'ok' => [200, 'html']],
    ['m' => 'GET',  'p' => '/admin/clientes/indicadores', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'recepcion', 'finanzas'], 'ok' => [200, 'html']],
    ['m' => 'GET',  'p' => '/admin/clientes/ficha?id={cliente}', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'recepcion', 'profesional'], 'ok' => [200, 'html']],
    ['m' => 'GET',  'p' => '/admin/clientes/ficha?id={ajeno}', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'recepcion', 'profesional'], 'ok' => [200, 'html'],
        'ok_por_rol' => ['profesional' => [404, 'html', 'No encontramos este cliente o no tienes acceso a su ficha.']]],
    ['m' => 'GET',  'p' => '/admin/clientes/ficha?id=987654', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'recepcion', 'profesional'], 'ok' => [404, 'html', 'No encontramos este cliente o no tienes acceso a su ficha.']],
    // CAMBIO DE CONTRATO (ronda 2, CRM 5c): toda exportación pedida por una sesión autenticada deja EXACTAMENTE una fila en
    // crm_exportaciones_auditoria (éxito o denegación 403); un anónimo o una sesión caducada no deja ninguna. Ver 'efecto_auditoria' en comparar().
    ['m' => 'GET',  'p' => '/admin/clientes/exportar', 'g' => 'permiso', 'roles' => ['propietario', 'gerente'], 'ok' => [200, 'csv'], 'efecto_auditoria' => true],
    ['m' => 'GET',  'p' => '/admin/clientes/auditoria', 'g' => 'permiso', 'roles' => ['propietario', 'gerente'], 'ok' => [200, 'html']],
    ['m' => 'GET',  'p' => '/admin/clientes/nuevo',  'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'recepcion'], 'ok' => [200, 'html']],
    ['m' => 'POST', 'p' => '/admin/clientes/nuevo',  'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'recepcion'], 'orden' => 'auth', 'csrf' => 'exigir', 'ok' => [422, 'html']],
    ['m' => 'POST', 'p' => '/api/pagos/reembolsar',  'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'finanzas'], 'orden' => 'auth', 'csrf' => 'json_api', 'csrf_json' => ['resultado' => false, 'status' => 'forbidden', 'error' => 'Token CSRF inválido o ausente'],
        'ok' => [422, 'json', ['resultado' => false, 'status' => 'invalid', 'errores' => ['La referencia de pago es obligatoria para solicitar un reembolso.']]]],
    // GET /perfil/foto: guardia híbrida (404 sin id; isAuth solo si hay login=true; 404 con cuerpo vacío si no hay foto): contrato en test_J.
    ['m' => 'GET',  'p' => '/perfil/foto',           'g' => 'especial'],
    // Perfil ajeno: perfiles.gestionar (propietario, gerente, recepción) para clientes; los datos de PERSONAL solo para rh.gestionar (propietario).
    ['m' => 'GET',  'p' => '/perfil?id={ajeno}',     'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'recepcion'], 'ok' => [200, 'html']],
    ['m' => 'GET',  'p' => '/perfil?id={uid_staff}', 'g' => 'permiso', 'roles' => ['propietario'], 'ok' => [200, 'html'],
        'deny_msg' => ['gerente' => 'Los datos del personal los administra quien gestiona Recursos Humanos.', 'recepcion' => 'Los datos del personal los administra quien gestiona Recursos Humanos.']],
    // ERP: contratos explícitos de alcance; los servicios vuelven a comprobar cada acción concreta.
    ['m' => 'GET', 'p' => '/pdv', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'recepcion', 'finanzas'], 'ok' => [200, 'html']],
    ['m' => 'GET', 'p' => '/ventas', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'recepcion', 'finanzas'], 'ok' => [200, 'html']],
    ['m' => 'GET', 'p' => '/gestion/contactos', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'recepcion', 'finanzas'], 'ok' => [200, 'html']],
    ['m' => 'GET', 'p' => '/gestion/aprobaciones', 'centinelas_visibles' => ['recepcion' => ['NEGOCIO:SENT-PRODUCTO-INV']], 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'recepcion', 'finanzas'], 'ok' => [200, 'html']],
    ['m' => 'GET', 'p' => '/gestion/seguimiento', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'recepcion', 'finanzas'], 'ok' => [200, 'html']],
    ['m' => 'GET', 'p' => '/ventas/detalle', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'recepcion', 'finanzas'], 'ok' => [404, 'html']],
    ['m' => 'GET', 'p' => '/ventas/recibo', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'recepcion', 'finanzas'], 'ok' => [404, 'html']],
    ['m' => 'GET', 'p' => '/ventas/cotizacion', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'recepcion', 'finanzas'], 'ok' => [404, 'html']],
    ['m' => 'GET', 'p' => '/gestion/catalogo', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'finanzas'], 'ok' => [200, 'html']],
    ['m' => 'GET', 'p' => '/gestion/integraciones', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'finanzas'], 'ok' => [200, 'html']],
    ['m' => 'GET', 'p' => '/gestion/compras', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'finanzas'], 'ok' => [200, 'html']],
    ['m' => 'GET', 'p' => '/gestion/obligaciones', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'finanzas'], 'ok' => [200, 'html']],
    ['m' => 'GET', 'p' => '/gestion/comisiones', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'finanzas'], 'ok' => [200, 'html']],
    ['m' => 'GET', 'p' => '/gestion/recursos', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'finanzas'], 'ok' => [200, 'html']],
    ['m' => 'GET', 'p' => '/gestion/indicadores', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'finanzas'], 'ok' => [200, 'html']],
    ['m' => 'GET', 'p' => '/gestion/prepagos/comprobante', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'recepcion', 'finanzas'], 'ok' => [404, 'html']],
    ['m' => 'GET', 'p' => '/gestion/prepagos', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'recepcion', 'finanzas'], 'ok' => [200, 'html']],
    ['m' => 'GET', 'p' => '/gestion/caja', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'recepcion', 'finanzas'], 'ok' => [200, 'html']],
    ['m' => 'GET', 'p' => '/gestion/configuracion', 'g' => 'permiso', 'roles' => ['propietario'], 'ok' => [200, 'html']],
    ['m' => 'GET', 'p' => '/gestion/promociones', 'g' => 'permiso', 'roles' => ['propietario', 'gerente'], 'ok' => [200, 'html']],
    ['m' => 'GET', 'p' => '/gestion/personal', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'recepcion', 'profesional', 'finanzas'], 'ok' => [200, 'html']],
    ['m' => 'GET', 'p' => '/gestion/documentos?entidad=venta&id=99999999', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'recepcion', 'profesional', 'finanzas'], 'ok' => [404, 'html']],
    ['m' => 'GET', 'p' => '/gestion/documentos/descargar', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'recepcion', 'profesional', 'finanzas'], 'ok' => [404, 'html']],
    ['m' => 'GET', 'p' => '/gestion/exportar?formato=csv', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'finanzas'], 'ok' => [200, 'csv']],
    ['m' => 'POST', 'p' => '/gestion/accion', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'recepcion', 'profesional', 'finanzas'], 'orden' => 'auth', 'csrf' => 'exigir', 'ok' => [422, 'html']],
    ['m' => 'POST', 'p' => '/admin/equipo/vincular', 'g' => 'permiso', 'roles' => ['propietario'], 'orden' => 'auth', 'csrf' => 'exigir', 'ok' => [303, 'redir', '/admin/recursos-humanos']],
    ['m' => 'GET', 'p' => '/admin/clientes/listado', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'recepcion', 'profesional'], 'ok' => [200, 'html']],
    ['m' => 'GET', 'p' => '/agenda/cita', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'recepcion', 'profesional'], 'ok' => [404, 'html']],
    ['m' => 'GET', 'p' => '/caja', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'finanzas'], 'ok' => [200, 'html'], 'deny_msg' => ['cliente'=>'Tu cuenta no tiene permiso para operar la caja. Consulta con la administración del salón si necesitas acceso.', 'profesional'=>'Tu cuenta no tiene permiso para operar la caja. Consulta con la administración del salón si necesitas acceso.', 'recepcion'=>'Tu cuenta no tiene permiso para operar la caja. Consulta con la administración del salón si necesitas acceso.']],
    ['m' => 'GET', 'p' => '/caja/cita', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'finanzas'], 'ok' => [422, 'html'], 'deny_msg' => ['cliente'=>'Tu cuenta no tiene permiso para operar la caja. Consulta con la administración del salón si necesitas acceso.', 'profesional'=>'Tu cuenta no tiene permiso para operar la caja. Consulta con la administración del salón si necesitas acceso.', 'recepcion'=>'Tu cuenta no tiene permiso para operar la caja. Consulta con la administración del salón si necesitas acceso.']],
    ['m' => 'GET', 'p' => '/caja/comprobante', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'finanzas'], 'ok' => [422, 'html'], 'deny_msg' => ['cliente'=>'Tu cuenta no tiene permiso para operar la caja. Consulta con la administración del salón si necesitas acceso.', 'profesional'=>'Tu cuenta no tiene permiso para operar la caja. Consulta con la administración del salón si necesitas acceso.', 'recepcion'=>'Tu cuenta no tiene permiso para operar la caja. Consulta con la administración del salón si necesitas acceso.']],
    ['m' => 'GET', 'p' => '/caja/exportar?formato=csv', 'efecto_caja_auditoria' => true, 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'finanzas'], 'ok' => [200, 'csv'], 'deny_msg' => ['cliente'=>'Tu cuenta no tiene permiso para exportar la caja.', 'profesional'=>'Tu cuenta no tiene permiso para exportar la caja.', 'recepcion'=>'Tu cuenta no tiene permiso para exportar la caja.']],
    ['m' => 'POST', 'p' => '/caja/cita/cobrar', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'finanzas'], 'orden' => 'auth', 'csrf' => 'exigir', 'ok' => [422, 'html'], 'deny_msg' => ['cliente'=>'Tu cuenta no tiene permiso para operar la caja. Consulta con la administración del salón si necesitas acceso.', 'profesional'=>'Tu cuenta no tiene permiso para operar la caja. Consulta con la administración del salón si necesitas acceso.', 'recepcion'=>'Tu cuenta no tiene permiso para operar la caja. Consulta con la administración del salón si necesitas acceso.']],
    ['m' => 'POST', 'p' => '/caja/cita/devolver', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'finanzas'], 'orden' => 'auth', 'csrf' => 'exigir', 'ok' => [422, 'html'], 'deny_msg' => ['cliente'=>'Tu cuenta no tiene permiso para operar la caja. Consulta con la administración del salón si necesitas acceso.', 'profesional'=>'Tu cuenta no tiene permiso para operar la caja. Consulta con la administración del salón si necesitas acceso.', 'recepcion'=>'Tu cuenta no tiene permiso para operar la caja. Consulta con la administración del salón si necesitas acceso.']],
    ['m' => 'POST', 'p' => '/caja/pago/verificar', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'finanzas'], 'orden' => 'auth', 'csrf' => 'exigir', 'ok' => [422, 'html'], 'deny_msg' => ['cliente'=>'Tu cuenta no tiene permiso para operar la caja. Consulta con la administración del salón si necesitas acceso.', 'profesional'=>'Tu cuenta no tiene permiso para operar la caja. Consulta con la administración del salón si necesitas acceso.', 'recepcion'=>'Tu cuenta no tiene permiso para operar la caja. Consulta con la administración del salón si necesitas acceso.']],
    ['m' => 'POST', 'p' => '/caja/pago/rechazar', 'g' => 'permiso', 'roles' => ['propietario', 'gerente', 'finanzas'], 'orden' => 'auth', 'csrf' => 'exigir', 'ok' => [422, 'html'], 'deny_msg' => ['cliente'=>'Tu cuenta no tiene permiso para operar la caja. Consulta con la administración del salón si necesitas acceso.', 'profesional'=>'Tu cuenta no tiene permiso para operar la caja. Consulta con la administración del salón si necesitas acceso.', 'recepcion'=>'Tu cuenta no tiene permiso para operar la caja. Consulta con la administración del salón si necesitas acceso.']],
];

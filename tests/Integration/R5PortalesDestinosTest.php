<?php

namespace Tests\Integration;

use Classes\ErrorResponse;
use MVC\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ronda 5 · n5 — catálogo central de destinos (includes/destinos.php) y ErrorResponse con «Volver»:
 * tipado de parámetros, clave desconocida, portal incorrecto, permiso faltante, inyección y parámetros duplicados/arreglos.
 */
final class R5PortalesDestinosTest extends TestCase
{
    private \mysqli $db;
    private array $respaldo = [];
    private static int $seq = 0;

    protected function setUp(): void
    {
        $this->db = \Model\ActiveRecord::getDB();
        validar_base_datos_prueba($this->db);
        iniciar_sesion_segura();   // puede() abre la sesión la primera vez y vaciaría $_SESSION si no estuviera abierta
        $this->respaldo = [$_SESSION ?? [], $_POST, $_GET, $_SERVER];
        $_SESSION = []; $_POST = []; $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        unset($_SERVER['HTTP_ACCEPT']);
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        $this->limpiarUsuarios();
        [$_SESSION, $_POST, $_GET, $_SERVER] = $this->respaldo;
        http_response_code(200);
    }

    /** Sesión de un portal con el rol dado, respaldada por filas reales (los permisos se leen del servidor). */
    private function sesion(string $portal, string $rol, string $colaborador = 'activo', ?string $membresia = null): int
    {
        $n = (++self::$seq) . getmypid();
        $this->db->query("INSERT INTO usuarios(nombre,apellido,email,password,telefono,admin,confirmado) VALUES ('P','Q','r5n{$n}@destinos.test','x','0991234567'," . ($rol === 'propietario' ? 1 : 0) . ',1)');
        $id = (int)$this->db->insert_id;
        $this->db->query("INSERT INTO usuario_accesos(usuario_id,rol,activo) VALUES ({$id},'{$rol}',1)");
        if ($rol !== 'cliente') {
            $this->db->query("INSERT INTO colaboradores(usuario_id,email_norm,nombre,apellido,rol,estado) VALUES ({$id},'r5n{$n}@destinos.test','P','Q','{$rol}','{$colaborador}')");
        }
        if ($membresia !== null) { $this->db->query("INSERT INTO clientes(usuario_id,estado,origen) VALUES ({$id},'{$membresia}','registro_publico')"); }
        $ver = (int)$this->db->query("SELECT version_sesion FROM usuarios WHERE id={$id}")->fetch_row()[0];
        $_SESSION = ['login' => true, 'id' => $id, 'portal' => $portal, 'rol' => $portal === 'equipo' ? $rol : 'cliente', 'version_sesion' => $ver];
        return $id;
    }

    protected function limpiarUsuarios(): void
    {
        $this->db->query("DELETE FROM usuarios WHERE email LIKE 'r5n%@destinos.test'");
    }

    public function testPorTipoDeParametro(): void
    {
        $this->sesion('equipo', 'propietario');
        $this->assertSame('/agenda?desde=2030-01-02&hasta=2030-01-09', destino_portal('agenda', ['desde' => '2030-01-02', 'hasta' => '2030-01-09']));
        $this->assertSame('/agenda?desde=2030-01-02', destino_portal('agenda', ['desde' => '2030-01-02', 'hasta' => '2030-02-31']), 'fecha imposible descartada');
        $this->assertSame('/agenda', destino_portal('agenda', ['desde' => '02/01/2030', 'hasta' => '2030-1-2', 'otro' => 'x']), 'formato y parámetros ajenos descartados');
        $this->assertSame('/agenda/cita?id=15', destino_portal('agenda_cita', ['id' => 15]));
        foreach (['0', '-1', '1.5', '1e3', 'abc', '', ' 7', '99999999999', '007'] as $malo) {
            $this->assertSame('/agenda/cita', destino_portal('agenda_cita', ['id' => $malo]), "id «{$malo}»");
        }
        $this->assertSame('/admin/finanzas?pagina=3', destino_portal('finanzas', ['pagina' => '3']));
        $this->assertSame('/admin/finanzas', destino_portal('finanzas', ['pagina' => '0']));
        $this->assertSame('/caja?estado=pagado&metodo=efectivo', destino_portal('caja', ['estado' => 'pagado', 'metodo' => 'efectivo']));
        $this->assertSame('/caja', destino_portal('caja', ['estado' => 'hackeado', 'metodo' => 'bitcoin']), 'enum fuera de lista');
        $this->assertSame('/admin/clientes?q=Mar%C3%ADa%20P%C3%A9rez', destino_portal('clientes', ['q' => 'María Pérez']));
        $this->assertSame('/admin/clientes', destino_portal('clientes', ['q' => "a\r\nSet-Cookie: x=1"]), 'CRLF en texto');
        $this->assertSame('/admin/clientes', destino_portal('clientes', ['q' => "a\0b"]), 'NUL');
        $this->assertSame('/admin/clientes', destino_portal('clientes', ['q' => str_repeat('a', 81)]), 'demasiado largo');
        $this->assertSame('/admin/clientes', destino_portal('clientes', ['q' => "\xC3\x28"]), 'UTF-8 inválido');
        $this->assertSame('/admin/clientes?q=%2F%2Fevil.example', destino_portal('clientes', ['q' => '//evil.example']), 'un valor //evil viaja codificado como DATO, nunca como destino');
    }

    public function testArreglosYObjetosNoSeReflejan(): void
    {
        $this->sesion('equipo', 'propietario');
        $this->assertSame('/agenda', destino_portal('agenda', ['desde' => ['2030-01-01'], 'hasta' => new \stdClass()]));
        $this->assertSame('/admin/clientes', destino_portal('clientes', ['q' => ['a', 'b'], 'pagina' => [1]]));
        $this->assertSame('', campos_retorno('desconocida'));
        $html = campos_retorno('agenda', ['desde' => '2030-01-01"><script>', 'hasta' => '2030-01-02']);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('name="r_hasta" value="2030-01-02"', $html);
    }

    public function testClaveDesconocidaYPortalIncorrecto(): void
    {
        $this->sesion('equipo', 'propietario');
        foreach (['', 'nope', '../..', '//evil.example', 'http://evil.example', 'javascript:alert(1)', "agenda\r\n", 'AGENDA', 'agenda '] as $clave) {
            $this->assertSame('', destino_portal($clave), "clave «{$clave}»");
        }
        $this->assertSame('', destino_portal('cliente_citas'), 'el equipo no obtiene destinos del cliente');
        $this->assertSame('', destino_portal('cliente_reservar'));
        $this->assertSame('', destino_portal('cliente_bienestar'));
        $this->sesion('cliente', 'cliente');
        foreach (['agenda', 'caja', 'finanzas', 'clientes', 'servicios', 'equipo', 'inicio_equipo', 'admin_citas', 'ajustes'] as $clave) {
            $this->assertSame('', destino_portal($clave), "el cliente no obtiene $clave");
        }
        $this->assertSame('/mis-citas', destino_portal('cliente_citas'));
        $this->assertSame('/perfil', destino_portal('perfil'));
        $this->assertSame('/', destino_portal('login_cliente'));
        $this->limpiarUsuarios();
    }

    public function testDobleMembresiaElDestinoDependeDelPortalDeLaSesionNoDeLaPersona(): void
    {
        $this->sesion('equipo', 'propietario', 'activo', 'activo');
        $this->assertSame('', destino_portal('cliente_citas'), 'propietario con membresía de cliente, en el portal del equipo');
        $this->assertSame('/agenda', destino_portal('agenda'));
        $_SESSION['portal'] = 'cliente'; $_SESSION['rol'] = 'cliente';
        $this->assertSame('/mis-citas', destino_portal('cliente_citas'));
        $this->assertSame('', destino_portal('agenda'), 'la misma persona en el portal del cliente no obtiene destinos laborales');
        $this->assertSame('/cita', inicio_portal());
        $this->limpiarUsuarios();
    }

    #[DataProvider('permisos')]
    public function testPermisoFaltante(string $rol, string $clave, bool $permitido): void
    {
        $this->sesion('equipo', $rol);
        $this->assertSame($permitido, destino_portal($clave) !== '', "$rol / $clave");
        $this->limpiarUsuarios();
    }

    public static function permisos(): array
    {
        $m = [
            'propietario' => ['agenda', 'admin_citas', 'caja', 'finanzas', 'clientes', 'servicios', 'profesionales', 'equipo', 'equipo_ficha', 'inventario', 'reportes', 'crecimiento', 'ajustes', 'clientes_nuevo'],
            'gerente' => ['agenda', 'admin_citas', 'caja', 'finanzas', 'clientes', 'servicios', 'profesionales', 'inventario', 'reportes', 'crecimiento', 'ajustes', 'clientes_nuevo'],
            'recepcion' => ['agenda', 'admin_citas', 'clientes', 'clientes_nuevo'],
            'profesional' => ['agenda', 'clientes'],
            'finanzas' => ['caja', 'finanzas', 'inventario', 'reportes', 'clientes'],
        ];
        $todas = ['agenda', 'admin_citas', 'caja', 'finanzas', 'clientes', 'servicios', 'profesionales', 'equipo', 'equipo_ficha', 'inventario', 'reportes', 'crecimiento', 'ajustes', 'clientes_nuevo'];
        $o = [];
        foreach ($m as $rol => $ok) { foreach ($todas as $clave) { $o["$rol/$clave"] = [$rol, $clave, in_array($clave, $ok, true)]; } }
        return $o;
    }

    public function testInicioEquipoSeResuelvePorRolYNuncaApuntaAUnaPantallaProhibida(): void
    {
        foreach (['propietario' => '/admin', 'gerente' => '/admin', 'recepcion' => '/admin', 'profesional' => '/agenda', 'finanzas' => '/admin/finanzas'] as $rol => $inicio) {
            $this->sesion('equipo', $rol);
            $this->assertSame($inicio, destino_portal('inicio_equipo'), $rol);
            $this->assertSame($inicio, inicio_portal());
            $this->assertSame($inicio, destino_volver(), $rol);
        }
        $this->limpiarUsuarios();
    }

    public function testDestinoVolverIgnoraEntradasHostiles(): void
    {
        $this->sesion('equipo', 'recepcion');
        $hostiles = [
            ['volver' => '//evil.example'], ['volver' => 'http://evil.example/'], ['volver' => 'javascript:alert(1)'], ['volver' => "agenda\r\nLocation: http://evil"],
            ['volver' => '../..'], ['volver' => ['agenda']], ['volver' => 'cliente_citas'], ['volver' => 'finanzas'], ['volver' => null],
            ['volver' => 'agenda', 'r_desde' => ['x'], 'r_hasta' => '//evil'],
        ];
        foreach ($hostiles as $i => $fuente) {
            $ruta = destino_volver('agenda', $fuente);
            $this->assertStringNotContainsString('evil', $ruta, "caso $i");
            $this->assertStringNotContainsString("\n", $ruta);
            $this->assertContains(explode('?', $ruta)[0], ['/agenda', '/admin'], "caso $i: $ruta");
        }
        $this->assertSame('/agenda?desde=2030-01-01&hasta=2030-01-05', destino_volver('', ['volver' => 'agenda', 'r_desde' => '2030-01-01', 'r_hasta' => '2030-01-05', 'r_x' => '1']));
        // Parámetros duplicados en la consulta: el segundo valor lo decide PHP; en todo caso queda tipado.
        parse_str('volver=agenda&r_desde=2030-01-01&r_desde=evil', $dup);
        $this->assertSame('/agenda', destino_volver('', $dup));
        $this->limpiarUsuarios();
    }

    public function testValidarRutaAceptaSoloEntradasDelCatalogo(): void
    {
        $this->sesion('equipo', 'gerente');
        $this->assertSame('/agenda?desde=2030-01-01', destino_validar_ruta('/agenda?desde=2030-01-01&x=1'));
        $this->assertSame('/admin', destino_validar_ruta('/admin'));
        foreach (['', 'agenda', '//evil.example/x', 'http://evil.example/agenda', '/\\evil', "/agenda\r\nX: y", '/mis-citas', '/cita', '/api/mis-citas', '/no-existe', '/agenda/../mis-citas', '/admin/equipo', 'javascript:alert(1)', '/%2f%2fevil', str_repeat('/a', 600)] as $ruta) {
            $this->assertSame('', destino_validar_ruta($ruta), json_encode($ruta));
        }
        $this->limpiarUsuarios();
    }

    public function testEtiquetaYResponderErrorOperacion(): void
    {
        $this->sesion('equipo', 'gerente');
        $_POST = ['volver' => ['agenda']];     // arreglo hostil: no debe provocar TypeError
        $this->assertSame('Volver al inicio', etiqueta_destino(destino_volver_con_clave('', null)[1]));
        $_POST = ['volver' => 'agenda', 'r_desde' => '2030-01-01', 'r_hasta' => '2030-01-02'];
        $salida = $this->capturar(static function () { responder_error_operacion(422, 'La atención todavía no ha comenzado.', 'agenda'); });
        $this->assertSame(422, http_response_code());
        $this->assertStringContainsString('href="/agenda?desde=2030-01-01&amp;hasta=2030-01-02"', $salida);
        $this->assertStringContainsString('Volver a la agenda', $salida);
        $this->assertStringContainsString('La atención todavía no ha comenzado.', $salida);
        $this->assertStringNotContainsString('href="/mis-citas"', $salida);
        $this->assertStringContainsString('class="ui-sidebar"', $salida, 'dentro del layout del portal (menú incluido)');
        $this->limpiarUsuarios();
    }

    #[DataProvider('codigos')]
    public function testErroresDeOperacionPorCodigoYPortal(int $codigo): void
    {
        foreach ([['equipo', 'gerente', '/agenda', '/admin'], ['cliente', 'cliente', '/mis-citas', '/cita']] as [$portal, $rol, $defecto, $inicio]) {
            $this->sesion($portal, $rol);
            $clave = $portal === 'equipo' ? 'agenda' : 'cliente_citas';
            $salida = $this->capturar(static function () use ($codigo, $clave) { responder_error_operacion($codigo, 'Detalle <b>del</b> error', $clave); });
            $this->assertSame($codigo, http_response_code());
            $this->assertStringContainsString('href="' . $defecto . '"', $salida, "$codigo $portal");
            $this->assertStringContainsString('Detalle &lt;b&gt;del&lt;/b&gt; error', $salida, 'escapado');
            $this->assertStringNotContainsString('<b>del</b>', $salida);
            $opuestos = $portal === 'equipo' ? ['href="/mis-citas"', 'href="/cita"'] : ['href="/agenda"', 'href="/admin"', 'href="/admin/finanzas"'];
            foreach ($opuestos as $o) { $this->assertStringNotContainsString($o, $salida, "$codigo $portal no ofrece $o"); }
        }
        $this->limpiarUsuarios();
    }

    public static function codigos(): array { return [[403], [404], [409], [422], [429], [500], [503]]; }

    public function testErrorResponseValidaVolverContraElCatalogo(): void
    {
        $this->sesion('equipo', 'recepcion');
        foreach (['http://evil.example/', '//evil.example', '/mis-citas', '/finanzas-no-existe', "/agenda\r\nX: y", 'javascript:alert(1)', '/admin/finanzas'] as $malo) {
            $salida = $this->capturar(static function () use ($malo) { ErrorResponse::render(404, ['volver' => $malo, 'volver_etiqueta' => 'Ir <ahora>']); });
            $this->assertStringNotContainsString('data-error-volver', $salida, json_encode($malo));
            $this->assertStringNotContainsString('evil.example', $salida);
            $this->assertStringContainsString('href="/admin"', $salida, 'siempre queda el inicio del portal');
        }
        $salida = $this->capturar(static function () { ErrorResponse::render(404, ['volver' => '/agenda?desde=2030-01-01', 'volver_etiqueta' => 'Ir <ahora>']); });
        $this->assertStringContainsString('data-error-volver>Ir &lt;ahora&gt;</a>', $salida);
        $salida = $this->capturar(static function () { ErrorResponse::render(404, ['volver' => '/agenda']); });
        $this->assertStringContainsString('data-error-volver>Volver</a>', $salida, 'etiqueta por omisión');
        $this->limpiarUsuarios();
    }

    public function testElInicioDelErrorSeCalculaPorElPortalNoPorElRolGuardado(): void
    {
        // Sesión de equipo SIN `rol` en la sesión (como una abierta antes de que el login lo escribiera): antes caía en /perfil.
        $id = $this->sesion('equipo', 'profesional');
        unset($_SESSION['rol']);
        $salida = $this->capturar(static function () { ErrorResponse::render(403, []); });
        $this->assertStringContainsString('<a class="boton" href="/agenda">Volver al inicio</a>', $salida);
        $this->assertStringNotContainsString('href="/perfil">Volver al inicio', $salida);
        // Sesión 401 del equipo: el acceso es el del equipo.
        $salida = $this->capturar(static function () { ErrorResponse::render(401, []); });
        $this->assertStringContainsString('href="/equipo/login">Iniciar sesión', $salida);
        $this->limpiarUsuarios();
        $this->assertGreaterThan(0, $id);
    }

    public function testIntencionPrevioAlLoginSoloDelCatalogoYSoloGet(): void
    {
        $_SERVER['REQUEST_URI'] = '/agenda?desde=2030-01-01&hasta=2030-01-04&x=%0d%0a&desde[]=1';
        $int = destino_intencion_actual();
        $this->assertSame('agenda', $int['clave']);
        $this->assertStringNotContainsString('x', implode(',', array_keys($int['params'])));
        $_SERVER['REQUEST_URI'] = '/agenda?desde=2030-01-01&hasta=2030-01-04';
        $this->assertSame(['clave' => 'agenda', 'params' => ['desde' => '2030-01-01', 'hasta' => '2030-01-04']], destino_intencion_actual());
        foreach (['/', '/equipo/login', '/api/mis-citas', '//evil.example/agenda', '/no-existe', '/cliente/login', "/agenda\r\n"] as $uri) {
            $_SERVER['REQUEST_URI'] = $uri;
            $this->assertNull(destino_intencion_actual(), $uri);
        }
        $_SERVER['REQUEST_URI'] = '/agenda'; $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->assertNull(destino_intencion_actual(), 'un envío POST nunca se repite tras el login');
    }

    private function capturar(callable $f): string
    {
        $nivel = ob_get_level();
        ob_start();
        try { $f(); $s = (string)ob_get_contents(); } finally { while (ob_get_level() > $nivel) { ob_end_clean(); } }
        return $s;
    }
}

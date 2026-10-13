<?php
namespace Tests\Integration;

use AppTerminationException;
use Classes\Idempotencia;
use Controllers\AltaClienteController;
use Controllers\CrmController;
use Model\ActiveRecord;
use MVC\Router;
use PHPUnit\Framework\TestCase;
use Repositories\AltaClienteRepository;
use Repositories\UsuarioRepository;
use Services\AltaClienteService;
use Services\HomonimosService;

/**
 * Alta de clientes por el equipo con AVISO de posibles homónimos, sobre MySQL real.
 * Garantías: nunca se fusiona ni se bloquea; el operador confirma («Es una persona distinta») o cancela; el servidor recalcula
 * las coincidencias y el token de confirmación debe corresponder al conjunto exacto; la decisión queda auditada (append-only)
 * en la misma transacción del alta; el doble clic no crea dos ni repite el aviso; el aviso solo muestra lo mínimo.
 *
 * Todas las personas de la prueba llevan una etiqueta única en el apellido (letras fuera de [0-9a-f]) para que ningún dato
 * sobrante de otras pruebas pueda coincidir por azar. La tabla de decisiones no se puede limpiar (es de solo anexado):
 * cada prueba consulta únicamente por su propio operador, cuyo id MySQL no reutiliza.
 */
final class AltaHomonimosIntegrationTest extends TestCase
{
    private \mysqli $db;
    private string $tag;
    private array $usuarios = [];
    private int $contador = 0;

    protected function setUp(): void
    {
        $this->db = new \mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', getenv('DB_NAME') ?: 'appsalon_test', (int)(getenv('DB_PORT') ?: 3306));
        $this->db->set_charset('utf8mb4');
        ActiveRecord::setDB($this->db);
        validar_base_datos_prueba($this->db);
        $this->tag = 'zq' . bin2hex(random_bytes(3));
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $_SESSION = []; $_GET = []; $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/admin/clientes/nuevo';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.20';
        AltaClienteController::setService(null);
        unset($GLOBALS['__appsalon_redireccion']);
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        AltaClienteController::setService(null);
        if ($this->db->ping()) {
            $this->db->query("DROP TRIGGER IF EXISTS test_fail_decision_{$this->tag}");
            foreach ($this->operadores as $id) { $this->db->query("DELETE FROM operaciones_idempotentes WHERE scope='cliente.alta_recepcion' AND actor='equipo:" . (int)$id . "'"); }
            foreach ($this->citas as $id) { $this->db->query('DELETE FROM citas WHERE id=' . (int)$id); }
            foreach ($this->usuarios as $id) { $this->db->query('DELETE FROM usuarios WHERE id=' . (int)$id); }
            $this->db->query("DELETE FROM usuarios WHERE email LIKE '%.{$this->tag}@alta-hom.test'");
        }
        $_SESSION = []; $_GET = []; $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        http_response_code(200);
    }

    // ---------------------------------------------------------------- fixtures

    private function sql(string $sql, string $types = '', array $params = []): \mysqli_stmt
    {
        $s = $this->db->prepare($sql);
        $this->assertNotFalse($s, $this->db->error);
        if ($types !== '') { $s->bind_param($types, ...$params); }
        $this->assertTrue($s->execute(), $s->error);
        return $s;
    }

    private function filas(string $sql, string $types = '', array $params = []): array
    {
        $s = $this->sql($sql, $types, $params);
        $r = $s->get_result()->fetch_all(MYSQLI_ASSOC);
        $s->close();
        return $r;
    }

    private function correo(string $base = 'p'): string
    {
        return $base . (++$this->contador) . '.' . $this->tag . '@alta-hom.test';
    }

    private function telefono(): string
    {
        return '09' . str_pad((string)random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT);
    }

    /** Persona ya existente (cliente con membresía). */
    private function existente(string $nombre, string $apellido, array $o = []): int
    {
        $hash = password_hash('PruebaSegura123!', PASSWORD_BCRYPT);
        $email = $o['email'] ?? $this->correo('prev');
        $this->sql('INSERT INTO usuarios(nombre,apellido,email,password,telefono,admin,confirmado) VALUES(?,?,?,?,?,0,1)', 'sssss', [$nombre, $apellido, $email, $hash, $o['telefono'] ?? $this->telefono()])->close();
        $id = (int)$this->db->insert_id;
        $this->usuarios[] = $id;
        if (isset($o['rol'])) { $this->sql('INSERT INTO usuario_accesos(usuario_id,rol,activo) VALUES(?,?,1)', 'is', [$id, $o['rol']])->close(); }
        if (!isset($o['rol']) || ($o['membresia'] ?? false)) { $this->sql("INSERT INTO clientes(usuario_id,estado,origen) VALUES(?,'activo','registro_publico')", 'i', [$id])->close(); }
        if (!empty($o['visita'])) {
            $this->sql("INSERT INTO citas(fecha,hora,usuarioId,estado) VALUES(?,'10:00:00',?,'completada')", 'si', [$o['visita'], $id])->close();
            $this->citas[] = (int)$this->db->insert_id;
        }
        return $id;
    }

    private array $citas = [];
    private array $operadores = [];

    private function operador(string $rol = 'recepcion'): int
    {
        $hash = password_hash('PruebaSegura123!', PASSWORD_BCRYPT);
        $this->sql('INSERT INTO usuarios(nombre,apellido,email,password,telefono,admin,confirmado) VALUES(?,?,?,?,?,0,1)', 'sssss', ['Operador', ucfirst($rol) . ' ' . $this->tag, $this->correo('op'), $hash, $this->telefono()])->close();
        $id = (int)$this->db->insert_id;
        $this->usuarios[] = $id;
        $this->operadores[] = $id;
        $this->sql('INSERT INTO usuario_accesos(usuario_id,rol,activo) VALUES(?,?,1)', 'is', [$id, $rol])->close();
        if ($rol === 'cliente') { $this->sql("INSERT INTO clientes(usuario_id,estado,origen) VALUES(?,'activo','registro_publico')", 'i', [$id])->close(); }
        return $id;
    }

    private function servicio(): AltaClienteService
    {
        return new AltaClienteService(new UsuarioRepository($this->db), new AltaClienteRepository($this->db));
    }

    private function datos(string $nombre, string $apellido, ?string $email = null, ?string $telefono = null, array $extra = []): array
    {
        return $extra + ['nombre' => $nombre, 'apellido' => $apellido, 'email' => $email ?? $this->correo('nuevo'), 'telefono' => $telefono ?? $this->telefono()];
    }

    private function decisiones(int $operador): array
    {
        return $this->filas('SELECT * FROM altas_homonimos_decisiones WHERE actor_usuario_id=? ORDER BY id', 'i', [$operador]);
    }

    private function contarUsuarios(string $email): int
    {
        return (int)$this->filas('SELECT COUNT(*) n FROM usuarios WHERE LOWER(TRIM(email))=?', 's', [mb_strtolower(trim($email))])[0]['n'];
    }

    /** Primer envío (sin confirmar) y, si hay aviso, devuelve el resultado para confirmar con su token. */
    private function intentar(int $operador, array $datos, string $clave, array $extra = []): array
    {
        return $this->servicio()->registrar($operador, 'recepcion', $datos + $extra, $clave);
    }

    // ================================================================ reglas puras

    public function testNormalizacionDistanciaYMascarasSonDeterministas(): void
    {
        $this->assertSame('jose maria perez nunez', HomonimosService::normalizar("  JOSÉ   María  PÉREZ-Núñez "));
        $this->assertSame('o brien', HomonimosService::normalizar("O'Brien"));
        $this->assertSame(0, HomonimosService::distancia('ana', 'ana'));
        $this->assertSame(1, HomonimosService::distancia('john', 'jhon'), 'Una transposición cuenta 1.');
        $this->assertSame(1, HomonimosService::distancia('perez', 'peres'));
        $this->assertSame('m***@g***.com', HomonimosService::enmascararCorreo('maria.perez@gmail.com'));
        $this->assertSame('a***@d***.ec', HomonimosService::enmascararCorreo('a@dominio.ec'));
        $this->assertSame('***', HomonimosService::enmascararCorreo('sinarroba'));
        $this->assertSame('••••4567', HomonimosService::telefonoParcial('0991234567'));
        $this->assertSame('—', HomonimosService::telefonoParcial(''));
        $this->assertTrue(HomonimosService::mismoTelefono('0991234567', '991234567'));
        $this->assertFalse(HomonimosService::mismoTelefono('0991234567', '0991234568'));
        $this->assertFalse(HomonimosService::mismoTelefono('', ''));
        $base = ['nombre' => 'Ana', 'apellido' => 'López', 'telefono' => null];
        $this->assertSame(['nombre_exacto'], HomonimosService::criterios($base, ['nombre' => 'ANA', 'apellido' => 'lopez', 'telefono' => null]));
        $this->assertSame(['nombre_exacto'], HomonimosService::criterios($base, ['nombre' => 'López', 'apellido' => 'Ana', 'telefono' => null]), 'Nombre y apellido intercambiados.');
        $this->assertSame([], HomonimosService::criterios($base, ['nombre' => 'Ana', 'apellido' => 'Pérez', 'telefono' => null]), 'Mismo nombre, otro apellido: no es homónimo.');
        $this->assertSame([], HomonimosService::criterios(['nombre' => 'Ana', 'apellido' => 'Li'], ['nombre' => 'Ana', 'apellido' => 'Lo']), 'Nombres cortos no se consideran «parecidos» por una letra.');
        $this->assertSame(['nombre_parecido'], HomonimosService::criterios(['nombre' => 'Jhon', 'apellido' => 'Smithson'], ['nombre' => 'John', 'apellido' => 'Smithson']));
        $this->assertSame(['nombre_parecido'], HomonimosService::criterios(['nombre' => 'Ana', 'apellido' => 'Pérez'], ['nombre' => 'Ana María', 'apellido' => 'Pérez']));
    }

    // ================================================================ detección

    public function testHomonimoExactoPideConfirmacionNoCreaNadaYNoDejaDecision(): void
    {
        $previo = $this->existente('Marisol', 'Quispe ' . $this->tag, ['visita' => '2026-09-01']);
        $op = $this->operador();
        $correo = $this->correo('nuevo');
        $r = $this->intentar($op, $this->datos('Marisol', 'Quispe ' . $this->tag, $correo), Idempotencia::nuevaClave());
        $this->assertSame(AltaClienteService::REQUIERE_CONFIRMACION, $r['status']);
        $this->assertSame([$previo], array_column($r['coincidencias'], 'id'));
        $this->assertSame([HomonimosService::NOMBRE_EXACTO], $r['coincidencias'][0]['criterios']);
        $this->assertSame('2026-09-01', $r['coincidencias'][0]['ultima_visita']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $r['token']);
        $this->assertFalse($r['confirmacion_invalida']);
        $this->assertSame(0, $this->contarUsuarios($correo), 'Mientras el operador no confirme, la cuenta no se crea.');
        $this->assertSame([], $this->decisiones($op), 'Un aviso mostrado no es una decisión.');
    }

    public function testTildesMayusculasEspaciosDoblesYOrdenInvertidoSeConsideranElMismoNombre(): void
    {
        $previo = $this->existente('José María', 'Núñez ' . $this->tag);
        $op = $this->operador();
        foreach ([['JOSE  MARIA', 'NUNEZ  ' . strtoupper($this->tag)], ['jose maria', 'nunez ' . $this->tag], ['  José   María ', ' Núñez ' . $this->tag . ' '], ['Núñez ' . $this->tag, 'José María']] as [$n, $a]) {
            $r = $this->intentar($op, $this->datos($n, $a), Idempotencia::nuevaClave());
            $this->assertSame(AltaClienteService::REQUIERE_CONFIRMACION, $r['status'], "{$n} {$a}");
            $this->assertContains($previo, array_column($r['coincidencias'], 'id'));
        }
    }

    public function testNombresParecidosYPrimerNombreCoincidenteAvisanSinBloquear(): void
    {
        $john = $this->existente('John', 'Smithson ' . $this->tag);
        $ana = $this->existente('Ana', 'Pérez ' . $this->tag);
        $op = $this->operador();
        $r = $this->intentar($op, $this->datos('Jhon', 'Smithson ' . $this->tag), Idempotencia::nuevaClave());
        $this->assertSame(AltaClienteService::REQUIERE_CONFIRMACION, $r['status']);
        $this->assertSame([$john], array_column($r['coincidencias'], 'id'));
        $this->assertSame([HomonimosService::NOMBRE_PARECIDO], $r['coincidencias'][0]['criterios']);
        $r = $this->intentar($op, $this->datos('Ana María', 'Pérez ' . $this->tag), Idempotencia::nuevaClave());
        $this->assertSame([$ana], array_column($r['coincidencias'], 'id'));
        // Un apellido distinto con el mismo nombre de pila NO es coincidencia.
        $r = $this->intentar($op, $this->datos('Ana', 'Zamora ' . $this->tag), Idempotencia::nuevaClave());
        $this->assertSame(AltaClienteService::OK, $r['status']);
    }

    public function testTelefonoCoincidenteAvisaAunConOtroNombre(): void
    {
        $tel = $this->telefono();
        $titular = $this->existente('Rosa', 'Mendieta ' . $this->tag, ['telefono' => $tel]);
        $op = $this->operador();
        $r = $this->intentar($op, $this->datos('Pablo', 'Distinto ' . $this->tag, null, $tel), Idempotencia::nuevaClave());
        $this->assertSame(AltaClienteService::REQUIERE_CONFIRMACION, $r['status']);
        $this->assertSame([$titular], array_column($r['coincidencias'], 'id'));
        $this->assertSame([HomonimosService::TELEFONO], $r['coincidencias'][0]['criterios']);
        $this->assertSame('••••' . substr($tel, -4), $r['coincidencias'][0]['telefono']);
    }

    public function testSinCoincidenciasCreaElClienteConOrigenRecepcionYSinDecision(): void
    {
        $op = $this->operador();
        $correo = $this->correo('nuevo');
        $r = $this->intentar($op, $this->datos('Original', 'Irrepetible ' . $this->tag, $correo), Idempotencia::nuevaClave());
        $this->assertSame(AltaClienteService::OK, $r['status']);
        $this->assertFalse($r['repetida']);
        $u = $this->filas('SELECT u.*,c.estado,c.origen FROM usuarios u JOIN clientes c ON c.usuario_id=u.id WHERE u.id=?', 'i', [$r['id']])[0];
        $this->assertSame([$correo, 'recepcion', 'activo', 0, 0], [$u['email'], $u['origen'], $u['estado'], (int)$u['confirmado'], (int)$u['admin']]);
        $this->assertSame(60, strlen($u['password']), 'Hay una contraseña aleatoria que nadie conoce (no es vacía).');
        $this->assertNull($u['token_hash'], 'No se emite ningún enlace ni se envía correo desde el alta.');
        $this->assertSame([], $this->decisiones($op));
        $this->assertSame([], $this->filas('SELECT 1 FROM perfiles_usuario WHERE usuarioId=? AND marketing_email=1', 'i', [$r['id']]), 'El equipo no otorga consentimiento comercial en nombre de nadie.');
        $this->usuarios[] = (int)$r['id'];
    }

    public function testDosPersonasDistintasConElMismoNombreSePermitenTrasConfirmarYNoSeFusionan(): void
    {
        $previo = $this->existente('Carlos', 'Gomez ' . $this->tag, ['visita' => '2026-08-15']);
        $op = $this->operador();
        $clave = Idempotencia::nuevaClave();
        $correo = $this->correo('segundo');
        $datos = $this->datos('Carlos', 'Gomez ' . $this->tag, $correo);
        $aviso = $this->intentar($op, $datos, $clave);
        $this->assertSame(AltaClienteService::REQUIERE_CONFIRMACION, $aviso['status']);
        $r = $this->intentar($op, $datos, $clave, ['decision' => 'confirmar', 'token_confirmacion' => $aviso['token']]);
        $this->assertSame(AltaClienteService::OK, $r['status']);
        $this->assertTrue($r['con_aviso']);
        $nuevo = (int)$r['id'];
        $this->assertNotSame($previo, $nuevo);
        // Las dos personas existen por separado, con sus correos; no se tocó nada de la existente.
        $this->assertSame(2, (int)$this->filas('SELECT COUNT(*) n FROM usuarios WHERE apellido=?', 's', ['Gomez ' . $this->tag])[0]['n']);
        $this->assertSame(1, (int)$this->filas('SELECT COUNT(*) n FROM citas WHERE usuarioId=?', 'i', [$previo])[0]['n']);
        $this->assertSame(0, (int)$this->filas('SELECT COUNT(*) n FROM citas WHERE usuarioId=?', 'i', [$nuevo])[0]['n']);
        // La decisión quedó auditada con los ids coincidentes y la cuenta creada.
        $d = $this->decisiones($op);
        $this->assertCount(1, $d);
        $this->assertSame('continuo', $d[0]['decision']);
        $this->assertSame([$previo], json_decode($d[0]['coincidencias_ids'], true));
        $this->assertSame(1, (int)$d[0]['n_coincidencias']);
        $this->assertSame($nuevo, (int)$d[0]['usuario_creado_id']);
        $this->assertSame('nombre_exacto', $d[0]['criterios']);
        $this->assertSame('recepcion', $d[0]['actor_rol']);
        $this->assertSame(hash('sha256', $clave), $d[0]['clave_hash']);
        $this->assertSame(hash('sha256', $aviso['token']), $d[0]['token_hash'], 'Se guarda la huella del token aceptado, no el token.');
        $this->usuarios[] = $nuevo;
        // Un tercer homónimo vuelve a avisar con AMBAS coincidencias (el aviso se recalcula, no se recuerda).
        $otro = $this->intentar($op, $this->datos('Carlos', 'Gomez ' . $this->tag), Idempotencia::nuevaClave());
        $this->assertEqualsCanonicalizing([$previo, $nuevo], array_column($otro['coincidencias'], 'id'));
    }

    public function testCancelarRegistraLaDecisionYJamasCreaUnaCuenta(): void
    {
        $previo = $this->existente('Elena', 'Ruiz ' . $this->tag);
        $op = $this->operador();
        $clave = Idempotencia::nuevaClave();
        $correo = $this->correo('cancelado');
        $datos = $this->datos('Elena', 'Ruiz ' . $this->tag, $correo);
        $aviso = $this->intentar($op, $datos, $clave);
        $r = $this->intentar($op, $datos, $clave, ['decision' => 'cancelar']);
        $this->assertSame(AltaClienteService::CANCELADO, $r['status']);
        $this->assertSame($previo, $r['id_existente']);
        $this->assertSame(0, $this->contarUsuarios($correo));
        $d = $this->decisiones($op);
        $this->assertCount(1, $d);
        $this->assertSame(['cancelo', [$previo], null], [$d[0]['decision'], json_decode($d[0]['coincidencias_ids'], true), $d[0]['usuario_creado_id']]);
        // Un doble clic en «Cancelar» no duplica la decisión.
        $this->assertSame(AltaClienteService::CANCELADO, $this->intentar($op, $datos, $clave, ['decision' => 'cancelar'])['status']);
        $this->assertCount(1, $this->decisiones($op));
        // «Cancelar» sin coincidencias vigentes tampoco crea nada.
        $limpio = $this->datos('Soloyo', 'Unico ' . $this->tag, $this->correo('cancelado2'));
        $this->assertSame(AltaClienteService::CANCELADO, $this->intentar($op, $limpio, Idempotencia::nuevaClave(), ['decision' => 'cancelar'])['status']);
        $this->assertSame(0, $this->contarUsuarios($limpio['email']));
        $this->assertNotEmpty($aviso['token']);
    }

    // ================================================================ la confirmación no se puede forjar

    public function testElServidorRecalculaYExigeUnTokenQueCorrespondaAlConjuntoDeCoincidencias(): void
    {
        $previo = $this->existente('Lucia', 'Vera ' . $this->tag);
        $op = $this->operador(); $otroOp = $this->operador('gerente');
        $clave = Idempotencia::nuevaClave(); $otraClave = Idempotencia::nuevaClave();
        $datos = $this->datos('Lucia', 'Vera ' . $this->tag, $correo = $this->correo('forjado'));
        $valido = $this->intentar($op, $datos, $clave)['token'];
        $forjados = [
            'sin token' => [],
            'token vacío' => ['token_confirmacion' => ''],
            'token inventado' => ['token_confirmacion' => str_repeat('a', 64)],
            'token de otro operador' => ['token_confirmacion' => AltaClienteService::tokenConfirmacion($otroOp, $clave, [$previo])],
            'token de otra clave de formulario' => ['token_confirmacion' => AltaClienteService::tokenConfirmacion($op, $otraClave, [$previo])],
            'token de otro conjunto de ids' => ['token_confirmacion' => AltaClienteService::tokenConfirmacion($op, $clave, [$previo, $previo + 999])],
            'token de conjunto vacío' => ['token_confirmacion' => AltaClienteService::tokenConfirmacion($op, $clave, [])],
            'token como arreglo' => ['token_confirmacion' => [$valido]],
        ];
        foreach ($forjados as $caso => $extra) {
            $r = $this->intentar($op, $datos, $clave, ['decision' => 'confirmar'] + $extra);
            $this->assertSame(AltaClienteService::REQUIERE_CONFIRMACION, $r['status'], $caso);
            $this->assertTrue($r['confirmacion_invalida'], $caso);
            $this->assertSame(0, $this->contarUsuarios($correo), "Con «{$caso}» no se crea nada.");
        }
        // El token válido sin la decisión explícita tampoco crea (basta con enviarlo; hay que pulsar «Es una persona distinta»).
        foreach (['crear', 'otra', ''] as $decision) {
            $r = $this->intentar($op, $datos, $clave, ['decision' => $decision, 'token_confirmacion' => $valido]);
            $this->assertSame(AltaClienteService::REQUIERE_CONFIRMACION, $r['status']);
            $this->assertFalse($r['confirmacion_invalida']);
        }
        $this->assertSame(0, $this->contarUsuarios($correo));
        $this->assertSame([], $this->decisiones($op), 'Ningún intento forjado deja una decisión «continuó».');
        $this->assertSame(AltaClienteService::OK, $this->intentar($op, $datos, $clave, ['decision' => 'confirmar', 'token_confirmacion' => $valido])['status']);
        $this->assertSame(1, $this->contarUsuarios($correo));
    }

    public function testUnTokenViejoNoSirveCuandoApareceUnNuevoHomonimoYElNuevoAvisoSi(): void
    {
        $a = $this->existente('Teresa', 'Salas ' . $this->tag);
        $op = $this->operador();
        $clave = Idempotencia::nuevaClave();
        $datos = $this->datos('Teresa', 'Salas ' . $this->tag, $correo = $this->correo('carrera'));
        $viejo = $this->intentar($op, $datos, $clave)['token'];
        $b = $this->existente('Teresa', 'Salas ' . $this->tag); // otra recepcionista dio de alta a otra Teresa mientras tanto
        $r = $this->intentar($op, $datos, $clave, ['decision' => 'confirmar', 'token_confirmacion' => $viejo]);
        $this->assertSame(AltaClienteService::REQUIERE_CONFIRMACION, $r['status']);
        $this->assertTrue($r['confirmacion_invalida']);
        $this->assertEqualsCanonicalizing([$a, $b], array_column($r['coincidencias'], 'id'), 'El servidor muestra el conjunto REAL actual.');
        $this->assertSame(0, $this->contarUsuarios($correo));
        $ok = $this->intentar($op, $datos, $clave, ['decision' => 'confirmar', 'token_confirmacion' => $r['token']]);
        $this->assertSame(AltaClienteService::OK, $ok['status']);
        $this->assertEqualsCanonicalizing([$a, $b], json_decode($this->decisiones($op)[0]['coincidencias_ids'], true));
        $this->usuarios[] = (int)$ok['id'];
    }

    public function testElCorreoDuplicadoSigueSiendoLaUnicaRestriccionRealYNingunaConfirmacionLaSalta(): void
    {
        $correo = $this->correo('dup');
        $existente = $this->existente('Dora', 'Iglesias ' . $this->tag, ['email' => $correo]);
        $op = $this->operador();
        foreach ([$correo, strtoupper($correo), '  ' . $correo . ' '] as $variante) {
            $datos = $this->datos('Otra', 'Persona ' . $this->tag, $variante);
            $r = $this->intentar($op, $datos, Idempotencia::nuevaClave());
            $this->assertSame(AltaClienteService::CONFLICT_EMAIL, $r['status'], $variante);
            // Ni con homónimo confirmado ni sin él.
            $r = $this->intentar($op, $datos, $clave = Idempotencia::nuevaClave(), ['decision' => 'confirmar', 'token_confirmacion' => AltaClienteService::tokenConfirmacion($op, $clave, [$existente])]);
            $this->assertSame(AltaClienteService::CONFLICT_EMAIL, $r['status']);
        }
        $this->assertSame(1, $this->contarUsuarios($correo));
    }

    public function testDatosInvalidosSeRechazanSinAvisoNiCreacion(): void
    {
        $op = $this->operador();
        $malos = [
            ['nombre' => '', 'apellido' => 'X', 'email' => $this->correo(), 'telefono' => '0991234567'],
            ['nombre' => 'X', 'apellido' => str_repeat('a', 61), 'email' => $this->correo(), 'telefono' => '0991234567'],
            ['nombre' => 'X', 'apellido' => 'Y', 'email' => 'no-es-correo', 'telefono' => '0991234567'],
            ['nombre' => 'X', 'apellido' => 'Y', 'email' => $this->correo(), 'telefono' => '12ab'],
            ['nombre' => ['x'], 'apellido' => 'Y', 'email' => $this->correo(), 'telefono' => '0991234567'],
            ['nombre' => "Con\x00control", 'apellido' => 'Y', 'email' => $this->correo(), 'telefono' => '0991234567'],
        ];
        foreach ($malos as $i => $d) {
            $r = $this->intentar($op, $d, Idempotencia::nuevaClave());
            $this->assertSame(AltaClienteService::INVALID, $r['status'], "caso {$i}");
            $this->assertNotEmpty($r['errores']);
        }
        $this->assertSame(AltaClienteService::INVALID, $this->intentar($op, $this->datos('Falta', 'Clave ' . $this->tag), 'clave-invalida')['status']);
    }

    // ================================================================ idempotencia y atomicidad

    public function testDobleEnvioConLaMismaClaveNoCreaDosNiVuelveAMostrarElAviso(): void
    {
        $this->existente('Nora', 'Paredes ' . $this->tag);
        $op = $this->operador();
        $clave = Idempotencia::nuevaClave();
        $correo = $this->correo('doble');
        $datos = $this->datos('Nora', 'Paredes ' . $this->tag, $correo);
        $token = $this->intentar($op, $datos, $clave)['token'];
        $confirmar = ['decision' => 'confirmar', 'token_confirmacion' => $token];
        $primero = $this->intentar($op, $datos, $clave, $confirmar);
        $segundo = $this->intentar($op, $datos, $clave, $confirmar);
        $tercero = $this->intentar($op, $datos, $clave); // incluso un reenvío SIN confirmación (otra pestaña con el formulario viejo)
        $this->assertSame(AltaClienteService::OK, $primero['status']);
        $this->assertFalse($primero['repetida']);
        foreach ([$segundo, $tercero] as $repetido) {
            $this->assertSame(AltaClienteService::OK, $repetido['status'], 'Tras confirmar no se vuelve a mostrar el aviso.');
            $this->assertTrue($repetido['repetida']);
            $this->assertSame($primero['id'], $repetido['id']);
        }
        $this->assertSame(1, $this->contarUsuarios($correo));
        $this->assertCount(1, $this->decisiones($op), 'Una sola decisión por alta.');
        $this->usuarios[] = (int)$primero['id'];
        // La misma clave con OTROS datos es un conflicto, no una segunda alta.
        $r = $this->intentar($op, $this->datos('Otra', 'Cosa ' . $this->tag, $this->correo('otro')), $clave);
        $this->assertSame(AltaClienteService::CONFLICT_KEY, $r['status']);
    }

    public function testLaDecisionYElAltaSonAtomicas(): void
    {
        $this->existente('Irma', 'Bravo ' . $this->tag);
        $op = $this->operador();
        $clave = Idempotencia::nuevaClave();
        $correo = $this->correo('atomica');
        $datos = $this->datos('Irma', 'Bravo ' . $this->tag, $correo);
        $token = $this->intentar($op, $datos, $clave)['token'];
        $this->db->query("CREATE TRIGGER test_fail_decision_{$this->tag} BEFORE INSERT ON altas_homonimos_decisiones FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fallo simulado de auditoría'");
        $prev = ini_set('error_log', '/dev/null');
        try {
            $r = $this->intentar($op, $datos, $clave, ['decision' => 'confirmar', 'token_confirmacion' => $token]);
        } finally {
            ini_set('error_log', $prev === false ? '' : $prev);
            $this->db->query("DROP TRIGGER IF EXISTS test_fail_decision_{$this->tag}");
        }
        $this->assertSame(AltaClienteService::ERROR, $r['status']);
        $this->assertSame(0, $this->contarUsuarios($correo), 'Sin decisión auditada no hay cuenta.');
        $this->assertSame([], $this->decisiones($op));
        $this->assertSame(0, (int)$this->filas("SELECT COUNT(*) n FROM operaciones_idempotentes WHERE scope='cliente.alta_recepcion' AND actor=?", 's', ['equipo:' . $op])[0]['n'], 'Ni rastro de éxito en la idempotencia.');
        // Reparada la causa, el mismo formulario puede completarse.
        $ok = $this->intentar($op, $datos, $clave, ['decision' => 'confirmar', 'token_confirmacion' => $token]);
        $this->assertSame(AltaClienteService::OK, $ok['status']);
        $this->assertCount(1, $this->decisiones($op));
        $this->usuarios[] = (int)$ok['id'];
    }

    // ================================================================ controlador: permisos, CSRF, privacidad y flujo HTML

    private function sesion(int $id): void
    {
        $_SESSION = ['id' => $id, 'login' => true, 'version_sesion' => 1, 'csrf_token' => 'alta-csrf', 'nombre' => 'Operador', 'apellido' => 'Prueba'];
    }

    /** @return array{http:int,termino:?int,cuerpo:string,redireccion:?string} */
    private function invocar(string $metodo, array $post = []): array
    {
        $_SERVER['REQUEST_METHOD'] = $metodo;
        $_SERVER['REQUEST_URI'] = '/admin/clientes/nuevo';
        $_POST = $post;
        unset($GLOBALS['__appsalon_redireccion']);
        http_response_code(200);
        $termino = null;
        ob_start();
        try { AltaClienteController::nuevo(new Router()); }
        catch (AppTerminationException $e) { $termino = $e->getStatusCode(); }
        catch (\Throwable $t) { ob_end_clean(); throw $t; }
        $cuerpo = (string)ob_get_clean();
        return ['http' => http_response_code(), 'termino' => $termino, 'cuerpo' => $cuerpo, 'redireccion' => $GLOBALS['__appsalon_redireccion'] ?? null];
    }

    private function inputOculto(string $html, string $nombre): string
    {
        $this->assertMatchesRegularExpression('/name="' . preg_quote($nombre, '/') . '" value="([^"]*)"/', $html, "Falta el campo oculto {$nombre}");
        preg_match('/name="' . preg_quote($nombre, '/') . '" value="([^"]*)"/', $html, $m);
        return html_entity_decode($m[1]);
    }

    public function testFlujoCompletoPorElControladorConAvisoConfirmacionYRedireccion(): void
    {
        $previo = $this->existente('Gloria', 'Altamirano ' . $this->tag, ['email' => 'gloria.completa.' . $this->tag . '@privado-hom.test', 'telefono' => '0987651234', 'visita' => '2026-07-20']);
        $this->sesion($this->operador());
        $form = $this->invocar('GET');
        $this->assertSame(200, $form['http']);
        $clave = $this->inputOculto($form['cuerpo'], 'idem_key');
        $this->assertTrue(Idempotencia::claveValida($clave));
        $this->assertStringNotContainsString('token_confirmacion', $form['cuerpo']);

        $correo = $this->correo('flujo');
        $post = ['csrf_token' => 'alta-csrf', 'idem_key' => $clave, 'nombre' => 'Gloria', 'apellido' => 'Altamirano ' . $this->tag, 'email' => $correo, 'telefono' => '0991112222', 'decision' => 'crear'];
        $aviso = $this->invocar('POST', $post);
        $this->assertSame(200, $aviso['http']);
        $this->assertNull($aviso['redireccion']);
        $this->assertStringContainsString('Posibles personas ya registradas', $aviso['cuerpo']);
        $this->assertStringContainsString('Es una persona distinta: crear cliente', $aviso['cuerpo']);
        $this->assertStringContainsString('Cancelar y usar la ficha existente', $aviso['cuerpo']);
        $this->assertStringContainsString('/admin/clientes/ficha?id=' . $previo, $aviso['cuerpo']);
        $this->assertSame($clave, $this->inputOculto($aviso['cuerpo'], 'idem_key'), 'La clave de operación se conserva entre el aviso y la confirmación.');
        // Privacidad del aviso: lo mínimo y enmascarado; nunca el correo ni el teléfono completos.
        $this->assertStringContainsString('g***@p***.test', $aviso['cuerpo']);
        $this->assertStringContainsString('••••1234', $aviso['cuerpo']);
        $this->assertStringContainsString('2026-07-20', $aviso['cuerpo']);
        foreach (['gloria.completa', 'privado-hom.test', '0987651234', '987651234'] as $prohibido) { $this->assertStringNotContainsString($prohibido, $aviso['cuerpo']); }
        $this->assertSame(0, $this->contarUsuarios($correo));
        // Intro desde un campo de texto no puede confirmar: el primer botón del formulario solo vuelve a comprobar.
        $this->assertLessThan(strpos($aviso['cuerpo'], 'value="confirmar"'), strpos($aviso['cuerpo'], 'value="crear"'));

        $token = $this->inputOculto($aviso['cuerpo'], 'token_confirmacion');
        $conf = $this->invocar('POST', ['decision' => 'confirmar', 'token_confirmacion' => $token] + $post);
        $this->assertSame(302, $conf['termino']);
        $nuevo = (int)$this->filas('SELECT id FROM usuarios WHERE email=?', 's', [$correo])[0]['id'];
        $this->usuarios[] = $nuevo;
        $this->assertSame('/admin/clientes/ficha?id=' . $nuevo . '&alta=ok', $conf['redireccion']);
        // Doble clic: el segundo envío redirige a la misma ficha, sin aviso y sin otra cuenta.
        $repetido = $this->invocar('POST', ['decision' => 'confirmar', 'token_confirmacion' => $token] + $post);
        $this->assertSame($conf['redireccion'], $repetido['redireccion']);
        $this->assertSame(1, $this->contarUsuarios($correo));
        // La ficha confirma el alta.
        $_GET = ['id' => (string)$nuevo, 'alta' => 'ok']; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/admin/clientes/ficha';
        ob_start(); try { CrmController::ficha(new Router()); } catch (AppTerminationException) {} $ficha = (string)ob_get_clean();
        $this->assertStringContainsString('Cliente creado', $ficha);
    }

    public function testConfirmarConElTokenDeOtraSesionDevuelve409YNoCrea(): void
    {
        $this->existente('Hilda', 'Cuesta ' . $this->tag);
        $this->sesion($this->operador());
        $clave = Idempotencia::nuevaClave();
        $correo = $this->correo('forja');
        $post = ['csrf_token' => 'alta-csrf', 'idem_key' => $clave, 'nombre' => 'Hilda', 'apellido' => 'Cuesta ' . $this->tag, 'email' => $correo, 'telefono' => '0991112222', 'decision' => 'confirmar', 'token_confirmacion' => str_repeat('b', 64)];
        $r = $this->invocar('POST', $post);
        $this->assertSame(409, $r['http']);
        $this->assertNull($r['redireccion']);
        $this->assertStringContainsString('La confirmación no corresponde', $r['cuerpo']);
        $this->assertSame(0, $this->contarUsuarios($correo));
    }

    public function testSinCsrfNoSePuedeCrearNada(): void
    {
        $this->sesion($this->operador());
        $correo = $this->correo('sincsrf');
        $post = ['idem_key' => Idempotencia::nuevaClave(), 'nombre' => 'Sin', 'apellido' => 'Csrf ' . $this->tag, 'email' => $correo, 'telefono' => '0991112222', 'decision' => 'crear'];
        $this->assertSame(403, $this->invocar('POST', $post)['http']);
        $this->assertSame(403, $this->invocar('POST', ['csrf_token' => 'otro'] + $post)['http']);
        $this->assertSame(0, $this->contarUsuarios($correo));
    }

    public function testElAvisoSoloLoVeQuienPuedeDarDeAltaYNoFiltraDatosAOtrosRoles(): void
    {
        $previo = $this->existente('Julia', 'Secreta ' . $this->tag, ['email' => 'julia.secreta.' . $this->tag . '@privado-hom.test', 'telefono' => '0976543210']);
        $post = ['csrf_token' => 'alta-csrf', 'idem_key' => Idempotencia::nuevaClave(), 'nombre' => 'Julia', 'apellido' => 'Secreta ' . $this->tag, 'email' => $this->correo('rol'), 'telefono' => '0991112222', 'decision' => 'crear'];
        // Quien puede: recepción, gerencia, propietario.
        foreach (['recepcion', 'gerente', 'propietario'] as $rol) {
            $this->sesion($this->operador($rol));
            $r = $this->invocar('POST', $post);
            $this->assertSame(200, $r['http'], $rol);
            $this->assertStringContainsString('Julia Secreta', $r['cuerpo'], $rol);
            $this->assertStringNotContainsString('julia.secreta', $r['cuerpo'], "{$rol}: el correo va enmascarado");
            $this->assertStringNotContainsString('0976543210', $r['cuerpo']);
        }
        // Quien no puede: ni el formulario ni el aviso, ni ninguna pista de las coincidencias.
        foreach (['finanzas', 'profesional', 'cliente'] as $rol) {
            $this->sesion($this->operador($rol));
            foreach (['GET', 'POST'] as $metodo) {
                $r = $this->invocar($metodo, $post);
                $this->assertSame(403, $r['http'], "{$rol} {$metodo}");
                foreach (['Julia', 'Secreta', 'julia', 'privado-hom', '3210', (string)$previo, 'Posibles personas'] as $pista) { $this->assertStringNotContainsString($pista, $r['cuerpo'], "{$rol} {$metodo} filtra «{$pista}»"); }
            }
        }
        $_SESSION = [];
        $this->assertSame(302, $this->invocar('POST', $post)['termino']);
        $this->assertSame(0, (int)$this->filas('SELECT COUNT(*) n FROM usuarios WHERE email LIKE ?', 's', ['rol%.' . $this->tag . '@alta-hom.test'])[0]['n']);
    }

    // ================================================================ concurrencia con procesos reales

    /** @return array{0:resource,1:array} */
    private function lanzar(int $operador, string $clave, array $d, string $decision, string $token, int $demoraMs = 0): array
    {
        $pipes = [];
        $proceso = proc_open([PHP_BINARY, __DIR__ . '/../fixtures/concurrent_alta_homonimos_worker.php', (string)$operador, $clave, $d['email'], $d['nombre'], $d['apellido'], $d['telefono'], $decision, $token, (string)$demoraMs],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proceso);
        stream_set_timeout($pipes[1], 20);
        $linea = fgets($pipes[1]);
        $this->assertSame("READY\n", $linea, 'Worker no quedó listo: ' . json_encode($linea));
        return [$proceso, $pipes];
    }

    private function liberar(array $trabajadores): array
    {
        foreach ($trabajadores as [, $pipes]) { fwrite($pipes[0], "GO\n"); fflush($pipes[0]); fclose($pipes[0]); }
        $out = [];
        foreach ($trabajadores as [$proceso, $pipes]) {
            $linea = fgets($pipes[1]); $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $this->assertSame(0, proc_close($proceso), 'Worker terminó con error: ' . $err);
            $json = json_decode((string)$linea, true);
            $this->assertIsArray($json, 'Salida de worker: ' . $linea . $err);
            $out[] = $json;
        }
        return $out;
    }

    public function testDosProcesosRealesConLaMismaClaveYConfirmacionCreanUnaSolaCuentaYUnaSolaDecision(): void
    {
        $previo = $this->existente('Vanesa', 'Concurrente ' . $this->tag);
        $op = $this->operador();
        $clave = Idempotencia::nuevaClave();
        $d = $this->datos('Vanesa', 'Concurrente ' . $this->tag);
        $token = AltaClienteService::tokenConfirmacion($op, $clave, [$previo]);
        $res = $this->liberar([$this->lanzar($op, $clave, $d, 'confirmar', $token, 600), $this->lanzar($op, $clave, $d, 'confirmar', $token, 600)]);
        $this->assertSame(['ok', 'ok'], array_column($res, 'status'), json_encode($res));
        $this->assertSame($res[0]['id'], $res[1]['id'], 'Ambos procesos devuelven la misma cuenta.');
        $this->assertSame(1, count(array_filter(array_column($res, 'repetida'), fn($r) => $r === false)), 'Exactamente uno ejecutó el alta.');
        $this->assertSame(1, $this->contarUsuarios($d['email']));
        $this->assertCount(1, $this->decisiones($op), 'Una sola decisión auditada.');
        $this->usuarios[] = (int)$res[0]['id'];
    }

    public function testDosProcesosRealesConDistintaClaveYElMismoCorreoSoloCreanUnaCuenta(): void
    {
        $op = $this->operador();
        $d = $this->datos('Unico', 'Correo ' . $this->tag);
        $res = $this->liberar([$this->lanzar($op, Idempotencia::nuevaClave(), $d, 'crear', '-', 500), $this->lanzar($op, Idempotencia::nuevaClave(), $d, 'crear', '-', 500)]);
        $estados = array_column($res, 'status');
        sort($estados);
        $this->assertSame(['conflict_email', 'ok'], $estados, json_encode($res));
        $this->assertSame(1, $this->contarUsuarios($d['email']), 'La restricción única del correo es la que impide el duplicado real.');
        $this->usuarios[] = (int)array_values(array_filter($res, fn($r) => $r['status'] === 'ok'))[0]['id'];
    }

    public function testDosProcesosRealesNoConfirmadosSoloMuestranElAvisoSinCrearNada(): void
    {
        $previo = $this->existente('Silvia', 'Aviso ' . $this->tag);
        $op = $this->operador();
        $d = $this->datos('Silvia', 'Aviso ' . $this->tag);
        $res = $this->liberar([$this->lanzar($op, Idempotencia::nuevaClave(), $d, 'crear', '-'), $this->lanzar($op, Idempotencia::nuevaClave(), $d, 'crear', '-')]);
        $this->assertSame(['requiere_confirmacion', 'requiere_confirmacion'], array_column($res, 'status'));
        $this->assertSame([[$previo], [$previo]], array_column($res, 'ids'));
        $this->assertSame(0, $this->contarUsuarios($d['email']));
    }
}

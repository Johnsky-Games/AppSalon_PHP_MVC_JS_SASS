<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Instalación nueva y bases existentes: el primer propietario se aprovisiona una sola vez, sin contraseña
 * universal, sin INSERT manual y sin tocar cuentas existentes. Cada caso usa una base propia terminada en _test.
 */
final class InstaladorPropietarioIntegrationTest extends TestCase
{
    private \mysqli $admin;
    private string $db = '';
    private const EMAIL = 'primer.propietario@instalador.test';

    protected function setUp(): void
    {
        $this->admin = new \mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', '', (int)(getenv('DB_PORT') ?: 3306));
        $this->db = 'appsalon_instalador_' . bin2hex(random_bytes(3)) . '_test';
        $this->admin->query("CREATE DATABASE {$this->db} CHARACTER SET utf8mb4");
    }

    protected function tearDown(): void
    {
        $this->admin->query("DROP DATABASE IF EXISTS {$this->db}");
        $this->admin->close();
    }

    private function conn(): \mysqli
    {
        $c = new \mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', $this->db, (int)(getenv('DB_PORT') ?: 3306));
        $c->set_charset('utf8mb4');
        return $c;
    }

    private function n(\mysqli $c, string $sql): int
    {
        return (int)$c->query($sql)->fetch_assoc()['n'];
    }

    /** @return array{codigo:int,salida:string} */
    private function instalar(array $args = [], array $env = []): array
    {
        $entorno = array_merge(getenv(), $_ENV, [
            'DB_NAME' => $this->db, 'OWNER_EMAIL' => self::EMAIL, 'OWNER_NOMBRE' => 'Marta', 'OWNER_APELLIDO' => 'Salón',
            'APP_URL' => 'http://localhost:3000', 'EMAIL_HOST' => '127.0.0.1', 'EMAIL_PORT' => '1', 'APPSALON_OWNER_PASSWORD' => '',
        ], $env);
        $p = proc_open(array_merge([PHP_BINARY, __DIR__ . '/../../scripts/instalar.php'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $entorno);
        $salida = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return ['codigo' => proc_close($p), 'salida' => $salida];
    }

    private function esquemaBaseConDatosHeredados(): void
    {
        $c = $this->conn();
        $c->multi_query((string)file_get_contents(__DIR__ . '/../../database/schema_base.sql'));
        do { if ($r = $c->store_result()) { $r->free(); } } while ($c->more_results() && $c->next_result());
        $hash = password_hash('ClaveHeredada-123', PASSWORD_BCRYPT);
        $c->query("INSERT INTO usuarios(nombre,apellido,email,password,telefono,admin,confirmado) VALUES ('Ana','Cliente','cliente@instalador.test','$hash','0991234567',0,1),('Luis','Admin','admin@instalador.test','$hash','0991234567',1,1)");
        $c->close();
    }

    public function testInstalacionNuevaMigraEmiteUnaActivacionDeUnSoloUsoYNoCreaCuentaNiContrasena(): void
    {
        $r = $this->instalar();
        $this->assertSame(0, $r['codigo'], $r['salida']);
        $this->assertStringContainsString('ENLACE DE ACTIVACIÓN', $r['salida']);
        $this->assertMatchesRegularExpression('~/equipo/aceptar\?token=([a-f0-9]{64})~', $r['salida']);
        preg_match('~token=([a-f0-9]{64})~', $r['salida'], $m);
        $c = $this->conn();
        try {
            $this->assertSame(0, $this->n($c, 'SELECT COUNT(*) n FROM usuarios'), 'No se crea ninguna cuenta antes de la activación');
            $col = $c->query("SELECT rol,estado,usuario_id FROM colaboradores")->fetch_assoc();
            $this->assertSame(['rol' => 'propietario', 'estado' => 'invitado', 'usuario_id' => null], $col);
            $this->assertSame(hash('sha256', $m[1]), $c->query("SELECT token_hash FROM colaborador_invitaciones WHERE estado='pendiente'")->fetch_assoc()['token_hash']);
            $this->assertSame(0, $this->n($c, "SELECT COUNT(*) n FROM information_schema.columns WHERE table_schema=DATABASE() AND column_name LIKE '%password%' AND table_name='correos_salida'"));
            $this->assertSame(18, $this->n($c, 'SELECT COUNT(*) n FROM migraciones') >= 18 ? 18 : 0, 'Migraciones aplicadas');
        } finally { $c->close(); }
        // El texto de la salida jamás contiene una contraseña.
        $this->assertStringNotContainsString('password', strtolower($r['salida']) === '' ? '' : str_replace('contraseña', '', strtolower($r['salida'])));
    }

    public function testRepetirLaInstalacionNoCreaOtroPropietarioNiOtraActivacion(): void
    {
        $primera = $this->instalar();
        $this->assertSame(0, $primera['codigo'], $primera['salida']);
        foreach ([1, 2, 3] as $i) {
            $r = $this->instalar();
            $this->assertSame(0, $r['codigo'], $r['salida']);
            $this->assertStringContainsString('activación de propietario pendiente', $r['salida']);
            $this->assertStringNotContainsString('token=', $r['salida'], 'El enlace se muestra una sola vez');
        }
        $c = $this->conn();
        try {
            $this->assertSame(1, $this->n($c, 'SELECT COUNT(*) n FROM colaboradores'));
            $this->assertSame(1, $this->n($c, 'SELECT COUNT(*) n FROM colaborador_invitaciones'));
            $this->assertSame(1, $this->n($c, 'SELECT COUNT(*) n FROM correos_salida'));
        } finally { $c->close(); }
    }

    public function testDosInstaladoresSimultaneosDejanUnSoloPropietario(): void
    {
        $this->assertSame(0, $this->instalar(['--sin-propietario'])['codigo']);
        $entorno = array_merge(getenv(), $_ENV, ['DB_NAME' => $this->db, 'OWNER_EMAIL' => self::EMAIL, 'APP_URL' => 'http://localhost:3000', 'EMAIL_HOST' => '127.0.0.1', 'EMAIL_PORT' => '1', 'APPSALON_OWNER_PASSWORD' => '']);
        $procesos = [];
        foreach ([1, 2, 3, 4] as $i) {
            $procesos[$i] = proc_open([PHP_BINARY, __DIR__ . '/../../scripts/instalar.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], null, $entorno);
        }
        $enlaces = 0;
        foreach ($procesos as $i => $p) {
            $salida = stream_get_contents($pipes[$i][1]) . stream_get_contents($pipes[$i][2]);
            fclose($pipes[$i][1]); fclose($pipes[$i][2]);
            $this->assertSame(0, proc_close($p), $salida);
            $enlaces += substr_count($salida, 'ENLACE DE ACTIVACIÓN');
        }
        $this->assertSame(1, $enlaces, 'Solo un instalador emite el enlace');
        $c = $this->conn();
        try {
            $this->assertSame(1, $this->n($c, "SELECT COUNT(*) n FROM colaboradores WHERE rol='propietario'"));
            $this->assertSame(1, $this->n($c, 'SELECT COUNT(*) n FROM colaborador_invitaciones'));
        } finally { $c->close(); }
    }

    public function testRegenerarActivacionInvalidaElEnlaceAnterior(): void
    {
        $a = $this->instalar();
        preg_match('~token=([a-f0-9]{64})~', $a['salida'], $viejo);
        $b = $this->instalar(['--regenerar-activacion']);
        $this->assertSame(0, $b['codigo'], $b['salida']);
        preg_match('~token=([a-f0-9]{64})~', $b['salida'], $nuevo);
        $this->assertNotSame($viejo[1], $nuevo[1]);
        $c = $this->conn();
        try {
            $this->assertSame(1, $this->n($c, "SELECT COUNT(*) n FROM colaborador_invitaciones WHERE estado='pendiente'"));
            $this->assertSame(1, $this->n($c, "SELECT COUNT(*) n FROM colaborador_invitaciones WHERE estado='revocada'"));
            $this->assertSame(hash('sha256', $nuevo[1]), $c->query("SELECT token_hash FROM colaborador_invitaciones WHERE estado='pendiente'")->fetch_assoc()['token_hash']);
        } finally { $c->close(); }
    }

    public function testConSecretoDeInstalacionCreaElPropietarioActivoYNoLoModificaDespues(): void
    {
        $r = $this->instalar([], ['APPSALON_OWNER_PASSWORD' => 'Secreto-De-Instalacion-1']);
        $this->assertSame(0, $r['codigo'], $r['salida']);
        $this->assertStringNotContainsString('Secreto-De-Instalacion-1', $r['salida'], 'La contraseña jamás se imprime');
        $c = $this->conn();
        try {
            $u = $c->query("SELECT id,admin,confirmado,password,version_sesion FROM usuarios WHERE email='" . self::EMAIL . "'")->fetch_assoc();
            $this->assertSame('1', $u['admin']);
            $this->assertTrue(password_verify('Secreto-De-Instalacion-1', $u['password']));
            $this->assertSame(1, $this->n($c, "SELECT COUNT(*) n FROM usuario_accesos WHERE usuario_id={$u['id']} AND rol='propietario' AND activo=1"));
            $this->assertSame(0, $this->n($c, "SELECT COUNT(*) n FROM clientes WHERE usuario_id={$u['id']}"), 'El propietario no se registra como cliente');
            // Otra ejecución (incluso con otra contraseña/correo) no altera la cuenta ni crea otro propietario.
            $r2 = $this->instalar([], ['APPSALON_OWNER_PASSWORD' => 'Otro-Secreto-Distinto-2', 'OWNER_EMAIL' => 'otro@instalador.test']);
            $this->assertSame(0, $r2['codigo'], $r2['salida']);
            $this->assertStringContainsString('El propietario ya existe', $r2['salida']);
            $despues = $c->query("SELECT password,version_sesion FROM usuarios WHERE id={$u['id']}")->fetch_assoc();
            $this->assertSame($u['password'], $despues['password']);
            $this->assertSame($u['version_sesion'], $despues['version_sesion']);
            $this->assertSame(1, $this->n($c, 'SELECT COUNT(*) n FROM usuarios'));
        } finally { $c->close(); }
    }

    public function testBaseExistenteConPendientesNoSeMigraSolaYConservaTodo(): void
    {
        $this->esquemaBaseConDatosHeredados();
        $r = $this->instalar();
        $this->assertSame(3, $r['codigo'], $r['salida']);
        $this->assertStringContainsString('--migrar', $r['salida']);
        $c = $this->conn();
        try {
            $this->assertSame(0, $c->query("SHOW TABLES LIKE 'migraciones'")->num_rows, 'No se alteró el esquema');
        } finally { $c->close(); }
    }

    public function testMigrarBaseExistenteConservaAdminHeredadoClientesYContrasenasSinCrearOtroPropietario(): void
    {
        $this->esquemaBaseConDatosHeredados();
        $c = $this->conn();
        $antes = $c->query('SELECT id,password,admin,confirmado FROM usuarios ORDER BY id')->fetch_all(MYSQLI_ASSOC);
        $r = $this->instalar(['--migrar']);
        $this->assertSame(0, $r['codigo'], $r['salida']);
        $this->assertStringContainsString('El propietario ya existe', $r['salida'], 'El admin heredado cuenta como propietario');
        try {
            $this->assertSame($antes, $c->query('SELECT id,password,admin,confirmado FROM usuarios ORDER BY id')->fetch_all(MYSQLI_ASSOC));
            $this->assertSame(1, $this->n($c, "SELECT COUNT(*) n FROM colaboradores WHERE rol='propietario' AND estado='activo'"));
            $this->assertSame(1, $this->n($c, "SELECT COUNT(*) n FROM clientes WHERE origen='migracion'"), 'Solo el cliente real recibe membresía; el admin heredado no');
            $this->assertSame(0, $this->n($c, 'SELECT COUNT(*) n FROM colaborador_invitaciones'));
        } finally { $c->close(); }
    }

    public function testCorreoDeUnClienteExistenteVinculaLaIdentidadSinDuplicarNiTocarSuContrasena(): void
    {
        $this->esquemaBaseConDatosHeredados();
        $c = $this->conn();
        $c->query("UPDATE usuarios SET admin=0 WHERE email='admin@instalador.test'");
        $c->query("UPDATE usuarios SET email='primer.propietario@instalador.test' WHERE email='cliente@instalador.test'");
        $hash = $c->query("SELECT password FROM usuarios WHERE email='primer.propietario@instalador.test'")->fetch_assoc()['password'];
        $r = $this->instalar(['--migrar']);
        $this->assertSame(0, $r['codigo'], $r['salida']);
        $this->assertStringContainsString('ENLACE DE ACTIVACIÓN', $r['salida']);
        try {
            $this->assertSame(1, $this->n($c, "SELECT COUNT(*) n FROM usuarios WHERE email='primer.propietario@instalador.test'"));
            $this->assertSame($hash, $c->query("SELECT password FROM usuarios WHERE email='primer.propietario@instalador.test'")->fetch_assoc()['password']);
            $col = $c->query("SELECT estado,usuario_id FROM colaboradores WHERE rol='propietario' AND estado='invitado'")->fetch_assoc();
            $this->assertNotNull($col['usuario_id'], 'Se vincula la identidad existente');
            $this->assertSame(0, $this->n($c, "SELECT COUNT(*) n FROM usuario_accesos WHERE rol='propietario'"), 'Sin privilegios hasta aceptar');
        } finally { $c->close(); }
    }

    public function testSinOwnerEmailNoInventaPropietarioNiContrasena(): void
    {
        $r = $this->instalar([], ['OWNER_EMAIL' => '']);
        $this->assertSame(0, $r['codigo'], $r['salida']);
        $this->assertStringContainsString('Define OWNER_EMAIL', $r['salida']);
        $c = $this->conn();
        try {
            $this->assertSame(0, $this->n($c, 'SELECT COUNT(*) n FROM usuarios'));
            $this->assertSame(0, $this->n($c, 'SELECT COUNT(*) n FROM colaboradores'));
        } finally { $c->close(); }
    }

    public function testSecretoDeInstalacionDebil(): void
    {
        $r = $this->instalar([], ['APPSALON_OWNER_PASSWORD' => 'corta']);
        $this->assertSame(1, $r['codigo']);
        $this->assertStringContainsString('12 y 72', $r['salida']);
    }
}

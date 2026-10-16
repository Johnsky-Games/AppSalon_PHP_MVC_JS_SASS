<?php

namespace Tests\Qa;

require_once __DIR__ . '/QaCase.php';

/** A/D. Instalador y primer propietario: paralelismo, un solo uso del enlace, regeneración y correcciones de correo. */
final class InstaladorTest extends QaCase
{
    private const BD = 'appsalon_qa_inst_test';

    public static function tearDownAfterClass(): void { self::borrarBd(self::BD); self::borrarBd('appsalon_qa_inst2_test'); }

    /** @return array{exit:int,out:string} */
    private function instalar(array $entorno, array $args = [], string $bd = self::BD, string $script = 'instalar.php'): array
    {
        $p = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', self::RAIZ . '/scripts/' . $script, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::RAIZ,
            array_merge(getenv(), ['DB_NAME' => $bd, 'EMAIL_PORT' => '2599', 'OWNER_NOMBRE' => 'Due', 'OWNER_APELLIDO' => 'Ño'], $entorno));
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        return ['exit' => proc_close($p), 'out' => $out];
    }

    /** @return array<int,array{exit:int,out:string}> */
    private function instalarEnParalelo(int $n, array $entorno, array $args = [], string $bd = self::BD): array
    {
        $procs = [];
        for ($i = 0; $i < $n; $i++) {
            $pipes = [];
            $p = proc_open([PHP_BINARY, '-d', 'variables_order=EGPCS', self::RAIZ . '/scripts/instalar.php', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::RAIZ,
                array_merge(getenv(), ['DB_NAME' => $bd, 'EMAIL_PORT' => '2599', 'OWNER_NOMBRE' => 'Due', 'OWNER_APELLIDO' => 'Ño'], $entorno));
            $procs[] = [$p, $pipes];
        }
        $res = [];
        foreach ($procs as [$p, $pipes]) { $res[] = ['out' => stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]), 'exit' => proc_close($p)]; }
        return $res;
    }

    private function vacia(string $bd = self::BD): void
    {
        $a = self::admin();
        self::nombreAislado($bd);
        $a->query("DROP DATABASE IF EXISTS `$bd`");
        $a->query("CREATE DATABASE `$bd` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $a->close();
    }

    private function conn(string $bd = self::BD): \mysqli
    {
        $a = self::admin(); $a->select_db($bd); return $a;
    }

    private function enlaces(array $res): array
    {
        $t = [];
        foreach ($res as $r) { if (preg_match('~/equipo/aceptar\?token=([a-f0-9]{64})~', $r['out'], $m)) { $t[] = $m[1]; } }
        return $t;
    }

    public function test_instalador_paralelo_sobre_base_vacia_deja_un_propietario_y_un_enlace(): void
    {
        $this->vacia();
        $email = 'dueno.' . bin2hex(random_bytes(2)) . '@example.test';
        $res = $this->instalarEnParalelo(4, ['OWNER_EMAIL' => $email]);
        $detalle = json_encode(array_map(fn($r) => [$r['exit'], substr($r['out'], -200)], $res), JSON_UNESCAPED_UNICODE);
        $db = $this->conn();
        $migs = (int)$db->query('SELECT COUNT(*) n FROM migraciones')->fetch_assoc()['n'];
        // CAMBIO DE CONTRATO (rondas 019-022): hay más migraciones; se cuentan los archivos reales en lugar de fijar un número.
        $esperadas = count(array_filter(glob(self::RAIZ . '/database/migrations/*.sql'), static fn($f) => !str_ends_with($f, '_rollback.sql')));
        $this->assertSame($esperadas, $migs, "migraciones aplicadas tras la instalación paralela: $detalle");
        $this->assertCount(1, $this->enlaces($res), "debe mostrarse UN solo enlace de activación: $detalle");
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) n FROM colaboradores WHERE rol='propietario'")->fetch_assoc()['n']);
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) n FROM colaborador_invitaciones WHERE estado='pendiente'")->fetch_assoc()['n']);
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) n FROM correos_salida")->fetch_assoc()['n']);
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) n FROM usuarios")->fetch_assoc()['n'], 'no existe cuenta ni contraseña hasta que el propietario acepta');
        $db->close();
    }

    public function test_Defecto_instalador_paralelo_sobre_base_vacia_hace_fallar_a_las_instancias_perdedoras(): void
    {
        $this->vacia();
        $res = $this->instalarEnParalelo(4, ['OWNER_EMAIL' => 'dueno.' . bin2hex(random_bytes(2)) . '@example.test']);
        $salidas = array_map(fn($r) => $r['exit'], $res);
        $this->assertSame([0, 0, 0, 0], $salidas, 'El instalador documenta ser seguro "en paralelo", pero cargar el esquema base y migrar no tienen candado: ' . json_encode(array_map(fn($r) => substr($r['out'], strpos($r['out'], '[ERROR]') ?: 0, 160), $res), JSON_UNESCAPED_UNICODE));
    }

    public function test_instaladores_en_paralelo_dejan_la_base_completa_o_recuperable_con_un_reintento(): void
    {
        $dejadas = [];
        for ($ronda = 0; $ronda < 6; $ronda++) {
            $this->vacia();
            $this->instalarEnParalelo(2, ['OWNER_EMAIL' => 'dueno.r' . $ronda . '@example.test']);
            $db = $this->conn();
            $migs = (int)$db->query('SELECT COUNT(*) n FROM migraciones')->fetch_assoc()['n'];
            $db->close();
            if ($migs < 18) {
                // ¿Un reintento simple (lo que haría el operador o el contenedor al reiniciar) se recupera?
                $r = $this->instalar(['OWNER_EMAIL' => 'dueno.r' . $ronda . '@example.test']);
                $dejadas[] = "ronda $ronda: migraciones=$migs; reintento exit={$r['exit']} " . substr(strip_tags($r['out']), strpos($r['out'], '[ERROR]') ?: 0, 140);
            }
        }
        $this->assertSame([], $dejadas, 'tras instalar en paralelo la base quedó incompleta o irrecuperable con un reintento');
    }

    public function test_enlace_de_activacion_se_usa_una_vez_y_el_propietario_entra_al_equipo(): void
    {
        $this->vacia();
        $email = 'dueno.' . bin2hex(random_bytes(2)) . '@example.test';
        $r = $this->instalar(['OWNER_EMAIL' => $email]);
        $this->assertSame(0, $r['exit'], $r['out']);
        $this->assertStringContainsString('se muestra una sola vez', $r['out']);
        $token = $this->enlaces([$r])[0] ?? null;
        $this->assertNotNull($token, $r['out']);
        // Segunda ejecución: no vuelve a mostrar enlace ni emite otro.
        $r2 = $this->instalar(['OWNER_EMAIL' => $email]);
        $this->assertSame(0, $r2['exit']);
        $this->assertSame([], $this->enlaces([$r2]), 'la segunda ejecución volvió a imprimir un enlace');
        $this->assertStringContainsString('pendiente', $r2['out']);
        // Aceptación HTTP real contra esa base.
        $srv = self::servidor(['DB_NAME' => self::BD], 'inst');
        $n = new Navegador($srv);
        $csrf = Navegador::csrfDe($n->get('/equipo/aceptar?token=' . $token)['body']);
        $this->assertNotNull($csrf);
        $ok = $n->post('/equipo/aceptar?token=' . $token, ['csrf_token' => $csrf, 'password' => 'Propietario-Pass-123', 'password_confirmacion' => 'Propietario-Pass-123', 'acepto' => '1']);
        $this->assertSame(302, $ok['status'], strip_tags(substr($ok['body'], 0, 300)));
        $repetido = $n->post('/equipo/aceptar?token=' . $token, ['csrf_token' => $csrf, 'password' => 'Otra-Pass-123456', 'password_confirmacion' => 'Otra-Pass-123456', 'acepto' => '1']);
        $this->assertSame(422, $repetido['status'], 'el enlace de un solo uso aceptó una segunda vez');
        $db = $this->conn();
        $db->query('TRUNCATE intentos_login');
        $db->close();
        $p = new Navegador($srv);
        $this->assertSame(302, $p->login('equipo', $email, 'Propietario-Pass-123')['status']);
        $this->assertSame(200, $p->get('/admin/equipo')['status']);
        $this->assertSame(200, $p->get('/admin/ajustes')['status']);
        // Tras activarse, el instalador no cambia nada ni crea otro propietario.
        $r3 = $this->instalar(['OWNER_EMAIL' => 'otro.' . $email]);
        $this->assertStringContainsString('El propietario ya existe', $r3['out']);
        $db = $this->conn();
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) n FROM colaboradores WHERE rol='propietario'")->fetch_assoc()['n']);
        $db->close();
        self::pararServidor('inst');
    }

    public function test_Defecto_regenerar_activacion_con_otro_correo_emite_el_enlace_para_el_correo_equivocado(): void
    {
        $this->vacia();
        $mal = 'duenio.typo.' . bin2hex(random_bytes(2)) . '@example.test';
        $bien = 'dueno.ok.' . bin2hex(random_bytes(2)) . '@example.test';
        $r = $this->instalar(['OWNER_EMAIL' => $mal]);
        $this->assertSame(0, $r['exit'], $r['out']);
        // El operador corrige el correo y regenera la activación.
        $r2 = $this->instalar(['OWNER_EMAIL' => $bien], ['--regenerar-activacion']);
        $this->assertSame(0, $r2['exit'], $r2['out']);
        $token = $this->enlaces([$r2])[0] ?? null;
        $this->assertNotNull($token, $r2['out']);
        // El enlace nuevo llega al correo CORRECTO; al aceptarlo debe crearse la cuenta con ese correo.
        $db = $this->conn();
        $dest = $db->query("SELECT destinatario_email FROM correos_salida ORDER BY id DESC LIMIT 1")->fetch_assoc()['destinatario_email'];
        $this->assertSame(strtolower($bien), $dest);
        $svc = new \Services\ColaboradorService(new \Repositories\ColaboradorRepository($db), new \Services\CorreoSalidaService(new \Repositories\CorreoSalidaRepository($db)));
        \Model\ActiveRecord::setDB($db);
        $a = $svc->aceptar($token, ['acepto' => '1', 'password' => 'Propietario-Pass-123', 'password_confirmacion' => 'Propietario-Pass-123'], '10.8.0.1');
        $this->assertSame('ok', $a['status'], json_encode($a));
        $emailCuenta = $db->query("SELECT email FROM usuarios ORDER BY id DESC LIMIT 1")->fetch_assoc()['email'];
        $this->assertSame(strtolower($bien), $emailCuenta, 'La cuenta del propietario nació con el correo equivocado (colaboradores.email_norm no se actualiza al regenerar): el propietario no podrá iniciar sesión con el correo correcto');
        $db->close();
    }

    public function test_regenerar_activacion_invalida_el_enlace_anterior(): void
    {
        $this->vacia();
        $email = 'dueno.' . bin2hex(random_bytes(2)) . '@example.test';
        $a = $this->enlaces([$this->instalar(['OWNER_EMAIL' => $email])])[0];
        $b = $this->enlaces([$this->instalar(['OWNER_EMAIL' => $email], ['--regenerar-activacion'])])[0];
        $this->assertNotSame($a, $b);
        $db = $this->conn();
        \Model\ActiveRecord::setDB($db);
        $svc = new \Services\ColaboradorService(new \Repositories\ColaboradorRepository($db), new \Services\CorreoSalidaService(new \Repositories\CorreoSalidaRepository($db)));
        $post = ['acepto' => '1', 'password' => 'Propietario-Pass-123', 'password_confirmacion' => 'Propietario-Pass-123'];
        $this->assertSame('invalid', $svc->aceptar($a, $post, '10.8.0.2')['status'], 'el enlace anterior sigue sirviendo tras regenerar');
        $this->assertSame('ok', $svc->aceptar($b, $post, '10.8.0.3')['status']);
        $db->close();
    }

    public function test_secreto_de_instalacion_debil_o_correo_invalido_se_rechazan_sin_tocar_la_base(): void
    {
        $this->vacia();
        $r = $this->instalar(['OWNER_EMAIL' => 'a@example.test', 'APPSALON_OWNER_PASSWORD' => 'corta']);
        $this->assertNotSame(0, $r['exit']);
        $r = $this->instalar(['OWNER_EMAIL' => 'no-es-correo']);
        $this->assertStringContainsString('OWNER_EMAIL', $r['out']);
        $r = $this->instalar(['OWNER_EMAIL' => ''], [], self::BD);
        $this->assertStringContainsString('No existe contraseña predeterminada', $r['out']);
        $db = $this->conn();
        $this->assertSame(0, (int)$db->query('SELECT COUNT(*) n FROM usuarios')->fetch_assoc()['n']);
        $this->assertSame(0, (int)$db->query('SELECT COUNT(*) n FROM colaboradores')->fetch_assoc()['n']);
        $db->close();
        // El envoltorio exige correo y secreto largo.
        $r = $this->instalar([], ['--email=x@example.test'], self::BD, 'crear_propietario.php');
        $this->assertSame(1, $r['exit']);
    }

    public function test_instalador_con_secreto_en_paralelo_no_pisa_la_contrasena_de_una_cuenta_existente(): void
    {
        $this->vacia();
        // Base migrada con una cuenta cliente que comparte el correo; contraseña distinta a la del secreto.
        $r = $this->instalar(['OWNER_EMAIL' => 'zz@example.test'], ['--sin-propietario']);
        $this->assertSame(0, $r['exit'], $r['out']);
        $db = $this->conn();
        $hash = password_hash('Clave-Original-123', PASSWORD_BCRYPT);
        $db->query("INSERT INTO usuarios(nombre,apellido,email,password,telefono,confirmado) VALUES ('Ya','Existe','existe@example.test','$hash','0990000001',1)");
        $db->close();
        $res = $this->instalarEnParalelo(4, ['OWNER_EMAIL' => 'existe@example.test', 'APPSALON_OWNER_PASSWORD' => 'Otro-Secreto-De-Instalacion-1']);
        $db = $this->conn();
        $this->assertSame($hash, $db->query("SELECT password FROM usuarios WHERE email='existe@example.test'")->fetch_assoc()['password'], 'el instalador cambió la contraseña de una cuenta existente');
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) n FROM usuario_accesos WHERE rol='propietario'")->fetch_assoc()['n'], 'elevó una cuenta existente sin demostrar su contraseña: ' . json_encode(array_column($res, 'out')));
        $db->close();
    }

    public function test_instalador_con_secreto_en_paralelo_crea_un_unico_propietario(): void
    {
        $this->vacia();
        $this->instalar(['OWNER_EMAIL' => 'zz@example.test'], ['--sin-propietario']);
        $email = 'secreto.' . bin2hex(random_bytes(2)) . '@example.test';
        $res = $this->instalarEnParalelo(5, ['OWNER_EMAIL' => $email, 'APPSALON_OWNER_PASSWORD' => 'Secreto-De-Instalacion-12']);
        foreach ($res as $r) { $this->assertSame(0, $r['exit'], $r['out']); }
        $db = $this->conn();
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) n FROM usuarios WHERE admin=1")->fetch_assoc()['n']);
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) n FROM usuario_accesos WHERE rol='propietario'")->fetch_assoc()['n']);
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) n FROM colaboradores WHERE rol='propietario'")->fetch_assoc()['n']);
        $this->assertSame(1, count(array_filter($res, fn($r) => str_contains($r['out'], 'Propietario configurado'))), json_encode(array_column($res, 'out')));
        $db->close();
    }
}

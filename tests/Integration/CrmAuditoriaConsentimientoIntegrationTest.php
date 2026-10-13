<?php
namespace Tests\Integration;

use AppTerminationException;
use Classes\ServiceUnavailableException;
use Controllers\CrmController;
use Model\ActiveRecord;
use MVC\Router;
use PHPUnit\Framework\TestCase;
use Repositories\ConsentimientoRepository;
use Repositories\CrecimientoRepository;
use Repositories\CrmAuditoriaRepository;
use Repositories\CrmRepository;
use Repositories\ProfileRepository;
use Services\CrmAuditoriaService;
use Services\CrmService;
use Services\ProfileService;

/**
 * Migración 022 sobre MySQL real: auditoría append-only de exportaciones del CRM y versionado del consentimiento comercial.
 *
 * Las pruebas de versiones usan un PROPÓSITO propio por prueba (`mk_<etiqueta>`): las versiones son inmutables y no se pueden
 * borrar (ese es el punto), así que publicar una versión 2 de `marketing_email` cambiaría el estado de toda la suite.
 * La auditoría tampoco se puede limpiar: cada prueba filtra por sus propios usuarios (ids que MySQL no reutiliza).
 */
final class CrmAuditoriaConsentimientoIntegrationTest extends TestCase
{
    private \mysqli $db;
    private string $tag;
    private string $proposito;
    private array $usuarios = [];
    private array $bases = [];
    private ?string $remoto = null;

    private const FILAS_TABLAS_SOLO_ANEXADO = [
        'crm_exportaciones_auditoria', 'consentimientos_historial', 'consentimiento_versiones', 'altas_homonimos_decisiones',
    ];

    protected function setUp(): void
    {
        $this->db = $this->conexion();
        ActiveRecord::setDB($this->db);
        validar_base_datos_prueba($this->db);
        // Letras fuera de [0-9a-f]: la etiqueta jamás puede aparecer por azar dentro de una huella hexadecimal.
        $this->tag = 'zq' . bin2hex(random_bytes(3));
        $this->proposito = 'mk_' . substr(bin2hex(random_bytes(4)), 0, 8);
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $_SESSION = []; $_GET = []; $_POST = [];
        $this->remoto = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.77';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/admin/clientes/exportar';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        CrmController::setCrmService(null);
        CrmController::setAuditoriaService(null);
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        CrmController::setCrmService(null);
        CrmController::setAuditoriaService(null);
        if ($this->db->ping()) {
            foreach ($this->usuarios as $id) { $this->db->query('DELETE FROM usuarios WHERE id=' . (int)$id); }
        }
        foreach ($this->bases as $nombre) { @$this->db->query('DROP DATABASE IF EXISTS `' . $nombre . '`'); }
        $_SESSION = []; $_GET = []; $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        if ($this->remoto === null) { unset($_SERVER['REMOTE_ADDR']); } else { $_SERVER['REMOTE_ADDR'] = $this->remoto; }
        http_response_code(200);
    }

    // ---------------------------------------------------------------- utilidades

    private function conexion(?string $bd = null): \mysqli
    {
        $db = new \mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: 'root', $bd ?? (getenv('DB_NAME') ?: 'appsalon_test'), (int)(getenv('DB_PORT') ?: 3306));
        $db->set_charset('utf8mb4');
        return $db;
    }

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

    private function dia(int $offset): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('America/Guayaquil')))->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');
    }

    /** @param array{email?:string,telefono?:string,rol?:string,membresia?:?string,confirmado?:int,consentimiento?:?string,version_id?:?int} $o */
    private function usuario(string $nombre, string $apellido, array $o = []): int
    {
        $email = $o['email'] ?? (strtolower(preg_replace('/[^a-z0-9]/i', '', $nombre)) . '.' . bin2hex(random_bytes(3)) . '.' . $this->tag . '@crm-aud.test');
        $tel = $o['telefono'] ?? ('09' . str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT));
        $hash = password_hash('PruebaSegura123!', PASSWORD_BCRYPT);
        $conf = $o['confirmado'] ?? 1;
        $this->sql('INSERT INTO usuarios(nombre,apellido,email,password,telefono,admin,confirmado) VALUES(?,?,?,?,?,0,?)', 'sssssi', [$nombre, $apellido, $email, $hash, $tel, $conf])->close();
        $id = (int)$this->db->insert_id;
        $this->usuarios[] = $id;
        if (isset($o['rol'])) { $this->sql('INSERT INTO usuario_accesos(usuario_id,rol,activo) VALUES(?,?,1)', 'is', [$id, $o['rol']])->close(); }
        if (array_key_exists('membresia', $o) ? $o['membresia'] !== null : !isset($o['rol'])) {
            $this->sql("INSERT INTO clientes(usuario_id,estado,origen,creado_en) VALUES(?,?,'registro_publico',?)", 'iss', [$id, $o['membresia'] ?? 'activo', $this->dia(-400) . ' 09:00:00'])->close();
        }
        if (!empty($o['consentimiento'])) {
            $this->sql('INSERT INTO perfiles_usuario(usuarioId,marketing_email,consentimiento_marketing_at,consentimiento_marketing_version_id) VALUES(?,1,?,?)', 'isi', [$id, $o['consentimiento'], $o['version_id'] ?? null])->close();
        }
        return $id;
    }

    private function sesion(int $id): void
    {
        $_SESSION = ['id' => $id, 'login' => true, 'version_sesion' => 1, 'csrf_token' => 'aud-csrf'];
    }

    /** @return array{http:int,termino:?int,cuerpo:string} */
    private function invocar(callable $fn): array
    {
        http_response_code(200);
        $termino = null;
        ob_start();
        try { $fn(); }
        catch (AppTerminationException $e) { $termino = $e->getStatusCode(); }
        catch (\Throwable $t) { ob_end_clean(); throw $t; }
        $cuerpo = (string)ob_get_clean();
        return ['http' => http_response_code(), 'termino' => $termino, 'cuerpo' => $cuerpo];
    }

    private function exportar(array $get): array
    {
        $_GET = $get; $_SERVER['REQUEST_URI'] = '/admin/clientes/exportar';
        return $this->invocar(fn() => CrmController::exportar(new Router()));
    }

    private function auditoria(array $get = []): array
    {
        $_GET = $get; $_SERVER['REQUEST_URI'] = '/admin/clientes/auditoria';
        return $this->invocar(fn() => CrmController::auditoria(new Router()));
    }

    /** @return array<int,array<string,string>> */
    private function csv(string $cuerpo): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $cuerpo);
        $fp = fopen('php://memory', 'r+');
        fwrite($fp, substr($cuerpo, 3)); rewind($fp);
        $cab = fgetcsv($fp);
        $out = [];
        while (($f = fgetcsv($fp)) !== false) { if ($f === [null]) { continue; } $out[] = array_combine($cab, $f); }
        fclose($fp);
        return $out;
    }

    private function cabecera(string $cuerpo): array
    {
        $fp = fopen('php://memory', 'r+');
        fwrite($fp, substr($cuerpo, 3)); rewind($fp);
        $cab = fgetcsv($fp);
        fclose($fp);
        return $cab;
    }

    /** @return array<int,array<string,mixed>> filas de auditoría de ese actor, de la más antigua a la más reciente */
    private function filasAuditoria(int $actor): array
    {
        return $this->filas('SELECT * FROM crm_exportaciones_auditoria WHERE actor_usuario_id=? ORDER BY id', 'i', [$actor]);
    }

    private function propietario(): int
    {
        return $this->usuario('Dueña', 'Auditora', ['rol' => 'propietario', 'membresia' => null]);
    }

    private function consentimientos(): ConsentimientoRepository
    {
        $repo = new ConsentimientoRepository($this->db, $this->proposito);
        if ($repo->versiones() === []) { $repo->publicar('Texto de prueba v1 ' . $this->tag); }
        return $repo;
    }

    // ================================================================ A. AUDITORÍA DE EXPORTACIONES

    public function testExportacionExitosaRegistraFilaQueCoincideConLoExportado(): void
    {
        foreach (['Ana', 'Beto', 'Carla'] as $i => $n) {
            $this->usuario($n, 'Export ' . $this->tag, ['consentimiento' => $i === 0 ? $this->dia(-5) . ' 10:00:00' : null]);
        }
        $actor = $this->propietario();
        $this->sesion($actor);
        $r = $this->exportar(['tipo' => 'clientes', 'q' => $this->tag, 'dias_activo' => '120']);
        $this->assertSame(200, $r['http']);
        $filas = $this->filasAuditoria($actor);
        $this->assertCount(1, $filas, 'Una exportación = una fila de auditoría.');
        $a = $filas[0];

        $csv = $this->csv($r['cuerpo']);
        $cab = $this->cabecera($r['cuerpo']);
        $this->assertSame('exitosa', $a['resultado']);
        $this->assertNull($a['motivo']);
        $this->assertSame('csv', $a['formato']);
        $this->assertSame('clientes', $a['tipo']);
        $this->assertSame('propietario', $a['actor_rol']);
        $this->assertSame(3, count($csv));
        $this->assertSame(3, (int)$a['filas'], 'La cantidad de filas auditada es la del archivo entregado.');
        $this->assertSame(count($cab), (int)$a['columnas_n']);
        $this->assertSame($cab, json_decode($a['columnas_lista'], true), 'Las columnas auditadas son las del encabezado real.');
        $this->assertSame(hash('sha256', $r['cuerpo']), $a['contenido_sha256'], 'La huella permite comprobar el archivo sin guardarlo.');
        $this->assertSame(0, (int)$a['truncado']);
        $this->assertSame('informativo', $a['consentimiento_politica']);
        $vigente = (new ConsentimientoRepository($this->db))->vigente();
        $this->assertSame((int)$vigente['id'], (int)$a['consentimiento_version_id']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string)$a['ip_hash']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string)$a['filtros_hash']);
        // Cuándo: UTC del servidor, dentro del último minuto.
        $this->assertLessThan(60, abs(strtotime((string)$this->filas('SELECT UTC_TIMESTAMP() u')[0]['u'] . ' UTC') - strtotime($a['ocurrido_en'] . ' UTC')));
        $f = json_decode($a['filtros_json'], true);
        $this->assertTrue($f['q_presente']);
        $this->assertSame(mb_strlen($this->tag), $f['q_longitud']);
        $this->assertSame(120, (int)$f['dias_activo']);
        $this->assertSame('nombre', $f['orden']);
    }

    public function testLaAuditoriaNoGuardaContenidoExportadoNiDatosPersonales(): void
    {
        $correo = 'secreto.' . $this->tag . '@privado-aud.test';
        $this->usuario('Nombre' . $this->tag, 'Apellido' . $this->tag, ['email' => $correo, 'telefono' => '0987654321', 'consentimiento' => $this->dia(-5) . ' 10:00:00']);
        $actor = $this->propietario();
        $this->sesion($actor);
        foreach (['clientes', 'comercial'] as $tipo) {
            $this->assertSame(200, $this->exportar(['tipo' => $tipo, 'q' => $this->tag])['http']);
        }
        $filas = $this->filasAuditoria($actor);
        $this->assertCount(2, $filas);
        foreach ($filas as $a) {
            $todo = json_encode($a, JSON_UNESCAPED_UNICODE);
            foreach (['Nombre' . $this->tag, 'Apellido' . $this->tag, $correo, 'privado-aud.test', '0987654321', $this->tag, '203.0.113'] as $dato) {
                $this->assertStringNotContainsString($dato, $todo, "La auditoría no debe contener «{$dato}».");
            }
            $this->assertStringNotContainsString('Consentimiento desde', (string)$a['filtros_json']);
        }
        // La búsqueda queda reducida a presencia, longitud y HMAC; el mismo texto da el mismo HMAC (se puede correlacionar, no leer).
        $f = json_decode($filas[0]['filtros_json'], true);
        $this->assertSame(32, strlen($f['q_hmac']));
        $this->assertSame($f['q_hmac'], json_decode($filas[1]['filtros_json'], true)['q_hmac']);
    }

    public function testListaComercialAuditaLaPoliticaDeVersionVigente(): void
    {
        $this->usuario('Acepta', 'Comercial ' . $this->tag, ['consentimiento' => $this->dia(-5) . ' 10:00:00']);
        $actor = $this->propietario();
        $this->sesion($actor);
        $this->assertSame(200, $this->exportar(['tipo' => 'comercial', 'q' => $this->tag])['http']);
        $a = $this->filasAuditoria($actor)[0];
        $this->assertSame('comercial', $a['tipo']);
        $this->assertSame('solo_version_vigente', $a['consentimiento_politica']);
        $this->assertSame(['ID', 'Nombre', 'Apellido', 'Correo', 'Consentimiento desde'], json_decode($a['columnas_lista'], true));
    }

    public function testExportacionSinPermisoDa403YRegistraElIntento(): void
    {
        $this->usuario('Victima', 'Protegida ' . $this->tag);
        $roles = [
            'recepcion' => ['rol' => 'recepcion', 'membresia' => null],
            'finanzas' => ['rol' => 'finanzas', 'membresia' => null],
            'profesional' => ['rol' => 'profesional', 'membresia' => null],
            'cliente' => ['rol' => 'cliente', 'membresia' => 'activo'],
        ];
        foreach ($roles as $rol => $opciones) {
            $id = $this->usuario('Intruso', ucfirst($rol) . ' ' . $this->tag, $opciones);
            $this->sesion($id);
            $r = $this->exportar(['tipo' => 'clientes', 'q' => $this->tag]);
            $this->assertSame(403, $r['http'], $rol);
            $this->assertStringNotContainsString('Protegida', $r['cuerpo']);
            $this->assertStringNotContainsString("\xEF\xBB\xBF", $r['cuerpo'], 'Sin permiso no sale ni un byte de CSV.');
            $filas = $this->filasAuditoria($id);
            $this->assertCount(1, $filas, "El intento denegado de {$rol} queda registrado.");
            $this->assertSame('denegada', $filas[0]['resultado']);
            $this->assertSame('sin_permiso', $filas[0]['motivo']);
            $this->assertSame($rol, $filas[0]['actor_rol']);
            $this->assertNull($filas[0]['filas']);
            $this->assertNull($filas[0]['contenido_sha256']);
        }
    }

    public function testAnonimoNoDejaFilaYRecibeRedireccionOCuatrocientosUno(): void
    {
        $antes = (int)$this->filas('SELECT COUNT(*) n FROM crm_exportaciones_auditoria')[0]['n'];
        $_SESSION = [];
        $this->assertSame(302, $this->exportar([])['termino']);
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $this->assertSame(401, $this->exportar([])['http']);
        $this->assertSame($antes, (int)$this->filas('SELECT COUNT(*) n FROM crm_exportaciones_auditoria')[0]['n'], 'Sin persona identificada no hay nada que auditar (y no se puede inundar la tabla).');
    }

    public function testFiltrosInvalidosDan422YRegistranLaExportacionFallida(): void
    {
        $actor = $this->propietario();
        $this->sesion($actor);
        $r = $this->exportar(['tipo' => 'todo']);
        $this->assertSame(422, $r['http']);
        $r = $this->exportar(['tipo' => 'clientes', 'desde' => '2024-13-45']);
        $this->assertSame(422, $r['http']);
        $r = $this->exportar(['tipo' => ['x']]);
        $this->assertSame(422, $r['http']);
        $filas = $this->filasAuditoria($actor);
        $this->assertCount(3, $filas);
        foreach ($filas as $a) {
            $this->assertSame('fallida', $a['resultado']);
            $this->assertSame('filtros_invalidos', $a['motivo']);
            $this->assertNull($a['contenido_sha256']);
        }
        $this->assertSame(['invalido', 'clientes', 'invalido'], array_column($filas, 'tipo'));
    }

    public function testFalloTecnicoDeDatosRegistraFallidaYResponde503(): void
    {
        $actor = $this->propietario();
        $this->sesion($actor);
        $cerrada = $this->conexion();
        $cerrada->close();
        CrmController::setCrmService(new CrmService(new CrmRepository($cerrada)));
        $prev = ini_set('error_log', '/dev/null');
        try {
            $this->expectException(ServiceUnavailableException::class);
            try { $this->exportar(['tipo' => 'clientes']); }
            finally {
                $filas = $this->filasAuditoria($actor);
                $this->assertCount(1, $filas);
                $this->assertSame('fallida', $filas[0]['resultado']);
                $this->assertSame('error_datos', $filas[0]['motivo']);
            }
        } finally { ini_set('error_log', $prev === false ? '' : $prev); }
    }

    public function testSiFallaElRegistroNoSeEntregaElArchivo(): void
    {
        $this->usuario('Cliente', 'Atomico ' . $this->tag);
        $actor = $this->propietario();
        $this->sesion($actor);
        $muerta = $this->conexion();
        $muerta->close(); // la exportación se genera con la BD viva; solo la escritura de auditoría falla
        CrmController::setAuditoriaService(new CrmAuditoriaService(new CrmAuditoriaRepository($muerta)));
        $cabecerasAntes = CrmController::getUltimosHeadersExportacion();
        $prev = ini_set('error_log', '/dev/null');
        http_response_code(200);
        ob_start();
        $excepcion = null;
        try { $_GET = ['tipo' => 'clientes', 'q' => $this->tag]; CrmController::exportar(new Router()); }
        catch (\Throwable $t) { $excepcion = $t; }
        $salida = (string)ob_get_clean();
        ini_set('error_log', $prev === false ? '' : $prev);
        $this->assertInstanceOf(ServiceUnavailableException::class, $excepcion, 'Sin auditoría no hay exportación: 503.');
        $this->assertSame('', $salida, 'Ni un byte del archivo puede salir si la auditoría no se escribió.');
        $this->assertSame($cabecerasAntes, CrmController::getUltimosHeadersExportacion(), 'Tampoco se emiten cabeceras de descarga.');
        $this->assertSame([], $this->filasAuditoria($actor));
        // Y recíprocamente: con la auditoría sana, el mismo pedido sí entrega y registra.
        CrmController::setAuditoriaService(null);
        $this->assertSame(200, $this->exportar(['tipo' => 'clientes', 'q' => $this->tag])['http']);
        $this->assertCount(1, $this->filasAuditoria($actor));
    }

    public function testLasTablasDeRegistroSonDeSoloAnexadoEnLaBaseDeDatos(): void
    {
        $actor = $this->propietario();
        $id = (new CrmAuditoriaRepository($this->db))->registrar(['actor_usuario_id' => $actor, 'actor_rol' => 'propietario', 'tipo' => 'clientes', 'resultado' => 'exitosa']);
        $this->assertGreaterThan(0, $id);
        $this->consentimientos();
        $this->sql("INSERT INTO altas_homonimos_decisiones(ocurrido_en,actor_usuario_id,decision,coincidencias_ids,n_coincidencias,criterios,clave_hash) VALUES(UTC_TIMESTAMP(),?,'cancelo','[1]',1,'nombre_exacto',?)", 'is', [$actor, hash('sha256', $this->tag)])->close();
        $this->sql("INSERT INTO consentimientos_historial(usuario_id,proposito,valor,version_id,origen,canal,registrado_en) SELECT ?,?,1,id,'perfil','portal_cliente',UTC_TIMESTAMP() FROM consentimiento_versiones WHERE proposito=? LIMIT 1", 'iss', [$actor, $this->proposito, $this->proposito])->close();
        $operaciones = [
            'UPDATE crm_exportaciones_auditoria SET filas=999 WHERE actor_usuario_id=' . $actor,
            'DELETE FROM crm_exportaciones_auditoria WHERE actor_usuario_id=' . $actor,
            "UPDATE consentimientos_historial SET valor=0 WHERE usuario_id={$actor}",
            "DELETE FROM consentimientos_historial WHERE usuario_id={$actor}",
            "UPDATE consentimiento_versiones SET texto='alterado' WHERE proposito='{$this->proposito}'",
            "DELETE FROM consentimiento_versiones WHERE proposito='{$this->proposito}'",
            "UPDATE altas_homonimos_decisiones SET decision='continuo' WHERE actor_usuario_id={$actor}",
            "DELETE FROM altas_homonimos_decisiones WHERE actor_usuario_id={$actor}",
        ];
        foreach ($operaciones as $op) {
            // La aplicación corre con mysqli_report(OFF): el rechazo se lee de errno/error, no de una excepción.
            try { $ok = $this->db->query($op); }
            catch (\mysqli_sql_exception) { $ok = false; }
            $this->assertFalse($ok, "Debió rechazarse: {$op}");
            $this->assertSame(1644, $this->db->errno, $op . ' → ' . $this->db->error);
            $this->assertStringContainsString('solo anexado', $this->db->error);
        }
        // Nada cambió.
        $this->assertSame(1, (int)$this->filas('SELECT COUNT(*) n FROM crm_exportaciones_auditoria WHERE actor_usuario_id=?', 'i', [$actor])[0]['n']);
        $this->assertNotSame(999, (int)$this->filas('SELECT filas FROM crm_exportaciones_auditoria WHERE actor_usuario_id=?', 'i', [$actor])[0]['filas']);
    }

    public function testNingunCodigoDeLaAplicacionEditaOBorraLaAuditoria(): void
    {
        $raiz = dirname(__DIR__, 2);
        $patron = '/\b(UPDATE|DELETE\s+FROM|TRUNCATE(\s+TABLE)?|DROP\s+TABLE|REPLACE\s+INTO|INSERT\s+IGNORE\s+INTO)\s+`?(' . implode('|', self::FILAS_TABLAS_SOLO_ANEXADO) . ')\b/i';
        $hallados = [];
        foreach (['controllers', 'services', 'repositories', 'classes', 'models', 'views', 'scripts', 'includes', 'public'] as $dir) {
            if (!is_dir("{$raiz}/{$dir}")) { continue; }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$raiz}/{$dir}", \FilesystemIterator::SKIP_DOTS)) as $archivo) {
                if ($archivo->getExtension() !== 'php') { continue; }
                if (preg_match($patron, (string)file_get_contents($archivo->getPathname()), $m)) { $hallados[] = str_replace($raiz . '/', '', $archivo->getPathname()) . ' → ' . $m[0]; }
            }
        }
        $this->assertSame([], $hallados, 'El código de la aplicación solo puede anexar y consultar estas tablas.');
        $rutas = (string)file_get_contents($raiz . '/public/index.php');
        $this->assertStringContainsString("get('/admin/clientes/auditoria'", $rutas);
        $this->assertStringNotContainsString("post('/admin/clientes/auditoria'", $rutas, 'La consulta de auditoría no tiene rutas de escritura.');
    }

    public function testLaVistaDeAuditoriaEsPaginadaDeSoloLecturaYSoloParaQuienExporta(): void
    {
        $repo = new CrmAuditoriaRepository($this->db);
        $actor = $this->usuario('<b>Operador</b>', 'Visible ' . $this->tag, ['rol' => 'gerente', 'membresia' => null]);
        for ($i = 0; $i < 30; $i++) {
            $repo->registrar(['actor_usuario_id' => $actor, 'actor_rol' => 'gerente', 'tipo' => $i % 2 ? 'comercial' : 'clientes', 'resultado' => $i % 5 === 0 ? 'denegada' : 'exitosa',
                'motivo' => $i % 5 === 0 ? 'sin_permiso' : null, 'filas' => $i, 'columnas_n' => 3, 'columnas_lista' => json_encode(['ID', 'Nombre', 'Correo']),
                'filtros_json' => json_encode(['q_presente' => true, 'q_longitud' => 4, 'desde' => '2026-01-01'])]);
        }
        // Quien exporta (propietario y gerente) consulta; el resto recibe 403 sin ver una sola fila.
        foreach (['propietario', 'gerente'] as $rol) {
            $this->sesion($this->usuario('Jefe', ucfirst($rol) . ' ' . $this->tag, ['rol' => $rol, 'membresia' => null]));
            $r = $this->auditoria(['actor_id' => (string)$actor, 'por_pagina' => '25']);
            $this->assertSame(200, $r['http'], $rol);
            $this->assertStringContainsString('Mostrando 1–25 de 30 registros · Página 1 de 2', $r['cuerpo']);
            $this->assertStringContainsString('&lt;b&gt;Operador&lt;/b&gt;', $r['cuerpo'], 'El nombre del actor se escapa.');
            $this->assertStringNotContainsString('<b>Operador</b>', $r['cuerpo']);
            $this->assertStringContainsString('sin permiso', $r['cuerpo']);
        }
        $p2 = $this->auditoria(['actor_id' => (string)$actor, 'por_pagina' => '25', 'pagina' => '2']);
        $this->assertStringContainsString('Mostrando 26–30 de 30 registros', $p2['cuerpo']);
        $solo = $this->auditoria(['actor_id' => (string)$actor, 'resultado' => 'denegada']);
        $this->assertStringContainsString('de 6 registros', $solo['cuerpo']);
        $this->assertStringNotContainsString('Exitosa</span>', $solo['cuerpo']);
        $this->assertSame(422, $this->auditoria(['resultado' => 'inventado'])['http']);
        $this->assertSame(422, $this->auditoria(['por_pagina' => '7'])['http']);
        $this->assertSame(422, $this->auditoria(['desde' => 'ayer'])['http']);
        foreach (['recepcion', 'finanzas', 'profesional'] as $rol) {
            $this->sesion($this->usuario('Otro', ucfirst($rol) . ' ' . $this->tag, ['rol' => $rol, 'membresia' => null]));
            $r = $this->auditoria(['actor_id' => (string)$actor]);
            $this->assertSame(403, $r['http'], $rol);
            $this->assertStringNotContainsString('Operador', $r['cuerpo']);
        }
        $this->sesion($this->usuario('Cliente', 'Comun ' . $this->tag, ['rol' => 'cliente', 'membresia' => 'activo']));
        $this->assertSame(403, $this->auditoria([])['http']);
        $_SESSION = [];
        $this->assertSame(302, $this->auditoria([])['termino']);
    }

    public function testCsvNeutralizaTabuladorRetornoDeCarroYOtrosDisparadoresDeFormula(): void
    {
        $nombres = ["\t=cmd|' /C calc'!A0", "\r+1+1", "=HYPERLINK(\"http://x\")", "-2+3", "@SUM(A1)", "  =1+1", "\n=2+2"];
        foreach ($nombres as $i => $n) { $this->usuario($n, 'Formula' . $i . ' ' . $this->tag); }
        $this->sesion($this->propietario());
        $r = $this->exportar(['tipo' => 'clientes', 'q' => $this->tag]);
        $this->assertSame(200, $r['http']);
        $filas = $this->csv($r['cuerpo']);
        $this->assertCount(count($nombres), $filas);
        foreach ($filas as $fila) {
            foreach ($fila as $col => $celda) {
                $visible = ltrim((string)$celda, " \t\r\n\0\x0B");
                $this->assertFalse($visible !== '' && in_array($visible[0], ['=', '+', '-', '@'], true), "«{$col}» queda interpretable como fórmula: " . json_encode($celda));
                $this->assertFalse((string)$celda !== '' && in_array($celda[0], ["\t", "\r", "\n"], true), "«{$col}» empieza con carácter de control: " . json_encode($celda));
            }
        }
        $this->assertContains("'\t=cmd|' /C calc'!A0", array_column($filas, 'Nombre'));
        $this->assertContains("'\r+1+1", array_column($filas, 'Nombre'));
    }

    // ================================================================ B. VERSIÓN DEL CONSENTIMIENTO

    private function perfilService(): ProfileService
    {
        return new ProfileService(new ProfileRepository($this->db, $this->consentimientos()));
    }

    private function datosPerfil(array $extra = []): array
    {
        return $extra + ['nombre' => 'Persona', 'apellido' => 'Consent', 'telefono' => '0991112233', 'contacto_email' => '1', 'recordatorios' => '1'];
    }

    private function estadoConsentimiento(int $id): array
    {
        return $this->filas('SELECT marketing_email,consentimiento_marketing_at at_,consentimiento_marketing_version_id v FROM perfiles_usuario WHERE usuarioId=?', 'i', [$id])[0] ?? [];
    }

    private function historial(int $id): array
    {
        return $this->filas('SELECT h.valor,h.version_id,v.version,h.version_informada,h.origen,h.canal,h.actor_id,h.ip_hash FROM consentimientos_historial h JOIN consentimiento_versiones v ON v.id=h.version_id WHERE h.usuario_id=? ORDER BY h.id', 'i', [$id]);
    }

    public function testAceptarGuardaLaVersionQueLaPersonaVioNoLaUltima(): void
    {
        $repo = $this->consentimientos();
        $v1 = $repo->vigente();
        $usuario = $this->usuario('Persona', 'Consent ' . $this->tag);
        $servicio = $this->perfilService();
        // La persona abrió el formulario con la versión 1; mientras tanto se publica la 2.
        $v2 = $repo->publicar('Texto nuevo v2 ' . $this->tag);
        $this->assertSame((int)$v2['id'], (int)$repo->vigente()['id']);
        $servicio->guardar($usuario, $this->datosPerfil(['marketing_email' => '1', 'consentimiento_version' => (string)$v1['id']]), true, 'cliente', ['actor_id' => $usuario, 'ip_hash' => str_repeat('a', 64)]);
        $estado = $this->estadoConsentimiento($usuario);
        $this->assertSame((int)$v1['id'], (int)$estado['v'], 'Se guarda la versión mostrada, no la última.');
        $h = $this->historial($usuario);
        $this->assertCount(1, $h);
        $this->assertSame([1, 1, 1], [(int)$h[0]['valor'], (int)$h[0]['version'], (int)$h[0]['version_informada']]);
        $this->assertSame('perfil', $h[0]['origen']);
        $this->assertSame('portal_cliente', $h[0]['canal']);
        $this->assertSame($usuario, (int)$h[0]['actor_id']);
        $this->assertSame(str_repeat('a', 64), $h[0]['ip_hash']);
        // Otra persona que sí vio la 2.
        $otra = $this->usuario('Otra', 'Persona ' . $this->tag);
        $servicio->guardar($otra, $this->datosPerfil(['marketing_email' => '1', 'consentimiento_version' => (string)$v2['id']]));
        $this->assertSame((int)$v2['id'], (int)$this->estadoConsentimiento($otra)['v']);
        $this->assertSame(2, (int)$this->historial($otra)[0]['version']);
    }

    public function testUnaVersionNuevaNoAlteraLosConsentimientosPreviosYExigeReconfirmacion(): void
    {
        $repo = $this->consentimientos();
        $v1 = $repo->vigente();
        $usuario = $this->usuario('Previa', 'Consent ' . $this->tag);
        $servicio = $this->perfilService();
        $servicio->guardar($usuario, $this->datosPerfil(['marketing_email' => '1', 'consentimiento_version' => (string)$v1['id']]));
        $fecha = $this->estadoConsentimiento($usuario)['at_'];
        $v2 = $repo->publicar('Texto nuevo v2 ' . $this->tag);

        // Guardar el perfil sin reconfirmar (aunque el formulario ya muestre la v2) NO mejora el consentimiento por su cuenta.
        $servicio->guardar($usuario, $this->datosPerfil(['apellido' => 'Cambiado', 'marketing_email' => '1', 'consentimiento_version' => (string)$v2['id']]));
        $estado = $this->estadoConsentimiento($usuario);
        $this->assertSame((int)$v1['id'], (int)$estado['v']);
        $this->assertSame($fecha, $estado['at_'], 'La fecha original se conserva.');
        $this->assertCount(1, $this->historial($usuario));
        $vista = $servicio->consentimientoVista($servicio->obtener($usuario));
        $this->assertTrue($vista['anterior']);
        $this->assertSame((int)$v2['id'], (int)$vista['vigente']['id']);
        $this->assertSame(1, (int)$vista['aceptada']['version']);

        // Con reconfirmación expresa sí pasa a la 2, y el historial conserva ambas decisiones.
        $servicio->guardar($usuario, $this->datosPerfil(['marketing_email' => '1', 'consentimiento_version' => (string)$v2['id'], 'reconfirmar_consentimiento' => '1']));
        $this->assertSame((int)$v2['id'], (int)$this->estadoConsentimiento($usuario)['v']);
        $this->assertSame([1, 2], array_map('intval', array_column($this->historial($usuario), 'version')));
        $this->assertFalse($servicio->consentimientoVista($servicio->obtener($usuario))['anterior']);

        // Reconfirmar con un formulario viejo (versión 1 mostrada) tampoco hace retroceder nada.
        $servicio->guardar($usuario, $this->datosPerfil(['marketing_email' => '1', 'consentimiento_version' => (string)$v1['id'], 'reconfirmar_consentimiento' => '1']));
        $this->assertSame((int)$v2['id'], (int)$this->estadoConsentimiento($usuario)['v']);
        $this->assertCount(2, $this->historial($usuario));
    }

    public function testRetirarElConsentimientoSiempreFuncionaYQuedaEnElHistorial(): void
    {
        $repo = $this->consentimientos();
        $v1 = $repo->vigente();
        $usuario = $this->usuario('Retira', 'Consent ' . $this->tag);
        $servicio = $this->perfilService();
        $servicio->guardar($usuario, $this->datosPerfil(['marketing_email' => '1', 'consentimiento_version' => (string)$v1['id']]));
        $repo->publicar('Texto nuevo v2 ' . $this->tag);
        // Versión basura, inexistente o ausente: el opt-out NUNCA falla.
        foreach (['basura', '999999999', '-4', ''] as $mala) {
            $servicio->guardar($usuario, $this->datosPerfil(['marketing_email' => '1', 'consentimiento_version' => (string)$v1['id']]));
            $servicio->guardar($usuario, $this->datosPerfil(['consentimiento_version' => $mala]));
            $estado = $this->estadoConsentimiento($usuario);
            $this->assertSame(0, (int)$estado['marketing_email'], "Opt-out con versión «{$mala}».");
            $this->assertNull($estado['at_']);
            $this->assertNull($estado['v']);
        }
        $h = $this->historial($usuario);
        $ultimo = end($h);
        $this->assertSame(0, (int)$ultimo['valor']);
        $this->assertSame([2, 0], [(int)$ultimo['version'], (int)$ultimo['version_informada']], 'Un opt-out con versión inservible se registra con la vigente y queda marcado como asumido.');
        // Retirar de nuevo, sin consentimiento previo, no inventa historial.
        $antes = count($h);
        $servicio->guardar($usuario, $this->datosPerfil([]));
        $this->assertCount($antes, $this->historial($usuario));
    }

    public function testAceptarConVersionInvalidaSeRechazaYNoGuardaNadaDelPerfil(): void
    {
        $this->consentimientos();
        $usuario = $this->usuario('Invalida', 'Original ' . $this->tag);
        $servicio = $this->perfilService();
        foreach (['0', 'abc', '987654321', '1.5'] as $mala) {
            try {
                $servicio->guardar($usuario, $this->datosPerfil(['apellido' => 'Nuevo', 'marketing_email' => '1', 'consentimiento_version' => $mala]));
                $this->fail("Debió rechazar la versión «{$mala}».");
            } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('versión', $e->getMessage()); }
        }
        $this->assertSame('Original ' . $this->tag, $this->filas('SELECT apellido FROM usuarios WHERE id=?', 'i', [$usuario])[0]['apellido'], 'La validación falla antes de confirmar: el perfil queda intacto.');
        $this->assertSame([], $this->estadoConsentimiento($usuario));
        $this->assertSame([], $this->historial($usuario));
        // Un texto de OTRO propósito tampoco sirve como «versión vista».
        $ajena = (int)(new ConsentimientoRepository($this->db))->vigente()['id'];
        $this->expectException(\InvalidArgumentException::class);
        $servicio->guardar($usuario, $this->datosPerfil(['marketing_email' => '1', 'consentimiento_version' => (string)$ajena]));
    }

    public function testSinVersionEnElFormularioSeAsumeLaVigenteYSeMarcaComoNoInformada(): void
    {
        $repo = $this->consentimientos();
        $usuario = $this->usuario('Sinversion', 'Consent ' . $this->tag);
        $this->perfilService()->guardar($usuario, $this->datosPerfil(['marketing_email' => '1']));
        $h = $this->historial($usuario);
        $this->assertSame((int)$repo->vigente()['id'], (int)$h[0]['version_id']);
        $this->assertSame(0, (int)$h[0]['version_informada'], 'Queda constancia de que el servidor asumió la versión.');
    }

    public function testElPortalDelEquipoNuncaTocaElConsentimiento(): void
    {
        $repo = $this->consentimientos();
        $titular = $this->usuario('Titular', 'Consent ' . $this->tag);
        $servicio = $this->perfilService();
        $servicio->guardar($titular, $this->datosPerfil(['marketing_email' => '1', 'consentimiento_version' => (string)$repo->vigente()['id']]));
        $antes = [$this->estadoConsentimiento($titular), $this->historial($titular)];
        // Recepción edita el perfil de la persona desde el portal del equipo: aunque envíe marketing_email=0 o =1, nada cambia.
        foreach (['0', '1'] as $valor) {
            $servicio->guardar($titular, $this->datosPerfil(['apellido' => 'Editado', 'marketing_email' => $valor]), false, 'equipo');
        }
        $this->assertSame($antes, [$this->estadoConsentimiento($titular), $this->historial($titular)]);
        $this->assertSame('Editado', $this->filas('SELECT apellido FROM usuarios WHERE id=?', 'i', [$titular])[0]['apellido'], 'Los datos de contacto sí se guardan.');
        // Y quien nunca aceptó no queda aceptando por una edición del equipo.
        $otro = $this->usuario('Nunca', 'Acepto ' . $this->tag);
        $servicio->guardar($otro, $this->datosPerfil(['marketing_email' => '1']), false, 'equipo');
        $this->assertSame([], $this->historial($otro));
        $this->assertSame(0, (int)($this->estadoConsentimiento($otro)['marketing_email'] ?? 0));
    }

    public function testBienestarGuardaLaVersionMostradaYSegmentoDeCampanasSoloIncluyeLaVigente(): void
    {
        $repo = $this->consentimientos();
        $v1 = $repo->vigente();
        $crecimiento = new CrecimientoRepository($this->db, $this->proposito);
        $a = $this->usuario('Antigua', 'Camp ' . $this->tag);
        $b = $this->usuario('Reciente', 'Camp ' . $this->tag);
        $crecimiento->consentimiento($a, true, (string)$v1['id']);
        $v2 = $repo->publicar('Texto nuevo v2 ' . $this->tag);
        $crecimiento->consentimiento($b, true, (string)$v2['id']);
        $this->assertSame('bienestar', $this->filas('SELECT origen FROM consentimientos_historial WHERE usuario_id=?', 'i', [$a])[0]['origen']);
        $ids = array_column($crecimiento->segmento('todos'), 'id');
        $this->assertNotContains($a, array_map('intval', $ids), 'Política: un consentimiento de versión anterior no entra en campañas.');
        $this->assertContains($b, array_map('intval', $ids));
        // Reconfirmando expresamente, la persona antigua vuelve a entrar.
        $crecimiento->consentimiento($a, true, (string)$v2['id'], ['reconfirma' => true]);
        $this->assertContains($a, array_map('intval', array_column($crecimiento->segmento('todos'), 'id')));
        // Retirar saca a la persona de inmediato y deja el historial (aceptó, reconfirmó, retiró).
        $crecimiento->consentimiento($a, false);
        $this->assertNotContains($a, array_map('intval', array_column($crecimiento->segmento('todos'), 'id')));
        $this->assertSame([1, 1, 0], array_map('intval', array_column($this->historial($a), 'valor')));
    }

    public function testElCrmDistingueElConsentimientoConVersionAnteriorEnListadoFiltrosYExportaciones(): void
    {
        $repo = $this->consentimientos();
        $v1 = $repo->vigente();
        $antiguo = $this->usuario('Antiguo', 'Vers ' . $this->tag, ['consentimiento' => $this->dia(-30) . ' 10:00:00', 'version_id' => (int)$v1['id']]);
        $sinVersion = $this->usuario('Historico', 'Vers ' . $this->tag, ['consentimiento' => $this->dia(-40) . ' 10:00:00']); // versión NULL = «v1 histórica»
        $v2 = $repo->publicar('Texto nuevo v2 ' . $this->tag);
        $vigente = $this->usuario('Vigente', 'Vers ' . $this->tag, ['consentimiento' => $this->dia(-2) . ' 10:00:00', 'version_id' => (int)$v2['id']]);
        $ninguno = $this->usuario('Ninguno', 'Vers ' . $this->tag);

        $crm = new CrmService(new CrmRepository($this->db, $this->proposito));
        CrmController::setCrmService($crm);
        CrmController::setAuditoriaService(new CrmAuditoriaService(new CrmAuditoriaRepository($this->db), $repo));
        $acceso = ['ver' => true, 'analitica' => true, 'propios' => false, 'exportar' => true];
        $listar = function (array $extra = []) use ($crm, $acceso): array {
            $v = $crm->normalizarFiltros(array_merge(['q' => $this->tag, 'por_pagina' => '100'], $extra), $acceso, $crm->catalogos());
            $this->assertSame([], $v['errores'], implode('|', $v['errores']));
            $p = $crm->panel($v['filtros'], $acceso);
            return [array_column($p['listado']['filas'], null, 'id'), $p];
        };
        [$todos, $panel] = $listar();
        $this->assertSame([1, 1, 0, 0], [(int)$todos[$antiguo]['consentimiento_anterior'], (int)$todos[$sinVersion]['consentimiento_anterior'], (int)$todos[$vigente]['consentimiento_anterior'], (int)$todos[$ninguno]['consentimiento_anterior']]);
        $this->assertSame([1, 1, 2, null], [$todos[$antiguo]['consentimiento_version'] === null ? null : (int)$todos[$antiguo]['consentimiento_version'], (int)$todos[$sinVersion]['consentimiento_version'], (int)$todos[$vigente]['consentimiento_version'], $todos[$ninguno]['consentimiento_version']]);
        $this->assertEqualsCanonicalizing([$antiguo, $sinVersion], array_keys($listar(['consentimiento' => 'anterior'])[0]));
        $this->assertEqualsCanonicalizing([$vigente], array_keys($listar(['consentimiento' => 'vigente'])[0]));
        $this->assertEqualsCanonicalizing([$antiguo, $sinVersion, $vigente], array_keys($listar(['consentimiento' => 'si'])[0]));
        $this->assertEqualsCanonicalizing([$ninguno], array_keys($listar(['consentimiento' => 'no'])[0]));
        $this->assertGreaterThanOrEqual(2, $panel['resumen']['con_consentimiento_anterior']);
        $this->assertGreaterThanOrEqual(1, $panel['resumen']['con_consentimiento_vigente']);

        // Exportaciones: la lista comercial (campañas) solo trae la versión vigente; la operativa distingue.
        $actor = $this->propietario();
        $this->sesion($actor);
        $comercial = $this->csv($this->exportar(['tipo' => 'comercial', 'q' => $this->tag])['cuerpo']);
        $this->assertSame([(string)$vigente], array_column($comercial, 'ID'));
        $operativa = array_column($this->csv($this->exportar(['tipo' => 'clientes', 'q' => $this->tag])['cuerpo']), null, 'ID');
        $this->assertSame('Sí', $operativa[(string)$antiguo]['Consentimiento con versión anterior']);
        $this->assertSame('Sí', $operativa[(string)$sinVersion]['Consentimiento con versión anterior']);
        $this->assertSame('No', $operativa[(string)$vigente]['Consentimiento con versión anterior']);
        $this->assertSame('2', $operativa[(string)$vigente]['Versión del consentimiento']);
        $this->assertSame('', $operativa[(string)$ninguno]['Versión del consentimiento']);
        // La auditoría registra la versión vigente aplicada.
        $filas = $this->filasAuditoria($actor);
        $this->assertCount(2, $filas);
        foreach ($filas as $f) { $this->assertSame((int)$v2['id'], (int)$f['consentimiento_version_id']); }
        $this->assertSame('solo_version_vigente', $filas[0]['consentimiento_politica']);
    }

    public function testLaFichaMuestraVersionHistorialYBadgeDeVersionAnterior(): void
    {
        $repo = $this->consentimientos();
        $usuario = $this->usuario('Ficha', 'Consent ' . $this->tag);
        $this->perfilService()->guardar($usuario, $this->datosPerfil(['marketing_email' => '1', 'consentimiento_version' => (string)$repo->vigente()['id']]));
        $repo->publicar('Texto nuevo v2 ' . $this->tag);
        CrmController::setCrmService(new CrmService(new CrmRepository($this->db, $this->proposito)));
        $this->sesion($this->propietario());
        $_GET = ['id' => (string)$usuario]; $_SERVER['REQUEST_URI'] = '/admin/clientes/ficha';
        $r = $this->invocar(fn() => CrmController::ficha(new Router()));
        $this->assertSame(200, $r['http']);
        $this->assertStringContainsString('Consentimiento con versión anterior', $r['cuerpo']);
        $this->assertStringContainsString('Historial de decisiones', $r['cuerpo']);
        $this->assertStringContainsString('Aceptó · versión 1', $r['cuerpo']);
    }

    public function testPerfilYBienestarPintanElTextoVigenteConSuVersionOculta(): void
    {
        $perfil = ['id' => 1, 'nombre' => 'A', 'apellido' => 'B', 'telefono' => '0991112233', 'email' => 'a@b.test', 'archivo' => null, 'contacto_email' => 1, 'recordatorios' => 1, 'marketing_email' => 1, 'foto_publica' => 0, 'es_profesional' => 0];
        $vigente = ['id' => 77, 'version' => 3, 'texto' => 'Texto <vigente> & "claro"'];
        $render = function (array $consent) use ($perfil): string {
            $alertas = []; $portal = 'cliente'; $propio = true; $consentimiento = $consent;
            ob_start();
            include dirname(__DIR__, 2) . '/views/perfil/index.php';
            return (string)ob_get_clean();
        };
        $_SESSION = ['id' => 1, 'login' => true, 'csrf_token' => 'x', 'nombre' => 'A', 'apellido' => 'B'];
        $html = $render(['vigente' => $vigente, 'aceptada' => null, 'anterior' => false]);
        $this->assertStringContainsString('name="consentimiento_version" value="77"', $html);
        $this->assertStringContainsString('Texto &lt;vigente&gt; &amp; &quot;claro&quot;', $html, 'El texto se escapa.');
        $this->assertStringNotContainsString('reconfirmar_consentimiento', $html);
        $html = $render(['vigente' => $vigente, 'aceptada' => ['id' => 5, 'version' => 1], 'anterior' => true]);
        $this->assertStringContainsString('name="reconfirmar_consentimiento"', $html);
        $this->assertStringContainsString('versión 1', $html);
        $this->assertStringContainsString('versión 3', $html);
    }

    // ================================================================ MIGRACIÓN 022 (BD propia)

    private function ejecutarMigrador(string $comando, string $bd): array
    {
        $cmd = 'php ' . escapeshellarg(dirname(__DIR__, 2) . '/database/migrador.php') . ' ' . escapeshellarg($comando);
        $env = array_merge($_ENV, ['DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1', 'DB_USER' => getenv('DB_USER') ?: 'root', 'DB_PASS' => getenv('DB_PASS') ?: 'root', 'DB_NAME' => $bd, 'DB_PORT' => (string)(getenv('DB_PORT') ?: 3306)]);
        $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $this->assertIsResource($p);
        $salida = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) { fclose($pipe); }
        return ['codigo' => proc_close($p), 'salida' => $salida];
    }

    /** Ejecuta un script de varias sentencias y devuelve el errno de la primera que falle (0 = todo bien). */
    private function multi(\mysqli $db, string $sql): int
    {
        try {
            if (!$db->multi_query($sql)) { return $db->errno ?: 1; }
            do { if ($r = $db->store_result()) { $r->free(); } if ($db->errno) { return $db->errno; } } while ($db->more_results() && $db->next_result());
        } catch (\mysqli_sql_exception $e) { return (int)$e->getCode() ?: 1; }
        return $db->errno;
    }

    public function testMigracion022RetroLlenaALaVersion1SinInventarFechasYElRollbackProtegeLaEvidencia(): void
    {
        $bd = 'appsalon_crm_mig022_test';
        $this->bases[] = $bd;
        $this->db->query("DROP DATABASE IF EXISTS `{$bd}`");
        $this->db->query("CREATE DATABASE `{$bd}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $m = $this->conexion($bd);
        $this->assertSame(0, $this->multi($m, (string)file_get_contents(dirname(__DIR__, 2) . '/database/schema_base.sql')));
        $res = $this->ejecutarMigrador('up', $bd);
        $this->assertSame(0, $res['codigo'], $res['salida']);
        $this->assertStringContainsString('022_crm_auditoria_consentimiento_homonimos aplicada', $res['salida']);

        // Se vuelve al estado ANTERIOR a 022 (rollback con tablas vacías = permitido) para sembrar consentimientos «heredados».
        $rollback = (string)file_get_contents(dirname(__DIR__, 2) . '/database/migrations/022_crm_auditoria_consentimiento_homonimos_rollback.sql');
        $this->assertSame(0, $this->multi($m, $rollback), 'Con las tablas vacías la reversión se permite.');
        $m->query("DELETE FROM migraciones WHERE migracion='022_crm_auditoria_consentimiento_homonimos'");
        $this->assertSame(0, $m->query("SHOW TABLES LIKE 'consentimiento_versiones'")->num_rows);
        $this->assertSame(0, $m->query("SHOW COLUMNS FROM perfiles_usuario LIKE 'consentimiento_marketing_version_id'")->num_rows);
        $hash = password_hash('x', PASSWORD_BCRYPT);
        $ids = [];
        foreach (['acepto' => [1, '2025-03-04 05:06:07'], 'sinfecha' => [1, null], 'nunca' => [0, null], 'retiro' => [0, null]] as $k => [$flag, $fecha]) {
            $m->query("INSERT INTO usuarios(nombre,apellido,email,password,telefono,confirmado) VALUES ('L','{$k}','{$k}@mig.test','{$hash}','0991112233',1)");
            $ids[$k] = (int)$m->insert_id;
            $f = $fecha === null ? 'NULL' : "'{$fecha}'";
            if ($k !== 'nunca') { $m->query("INSERT INTO perfiles_usuario(usuarioId,marketing_email,consentimiento_marketing_at) VALUES ({$ids[$k]},{$flag},{$f})"); }
        }
        $res = $this->ejecutarMigrador('up', $bd);
        $this->assertSame(0, $res['codigo'], $res['salida']);

        $v1 = $m->query("SELECT id,version,es_historica,vigente_desde,texto FROM consentimiento_versiones WHERE proposito='marketing_email'")->fetch_all(MYSQLI_ASSOC);
        $this->assertCount(1, $v1);
        $this->assertSame([1, 1], [(int)$v1[0]['version'], (int)$v1[0]['es_historica']]);
        $this->assertNull($v1[0]['vigente_desde'], 'No se inventa desde cuándo regía el texto histórico.');
        $p = array_column($m->query('SELECT usuarioId,marketing_email,consentimiento_marketing_at at_,consentimiento_marketing_version_id v FROM perfiles_usuario')->fetch_all(MYSQLI_ASSOC), null, 'usuarioId');
        $this->assertSame((int)$v1[0]['id'], (int)$p[$ids['acepto']]['v']);
        $this->assertSame('2025-03-04 05:06:07', $p[$ids['acepto']]['at_'], 'La fecha original no se toca.');
        $this->assertNull($p[$ids['sinfecha']]['v'], 'Una marca sin fecha no es consentimiento válido: no se le atribuye versión.');
        $this->assertNull($p[$ids['retiro']]['v']);
        $h = $m->query('SELECT usuario_id,valor,version_id,version_informada,origen,canal,ocurrido_en,actor_id,ip_hash FROM consentimientos_historial')->fetch_all(MYSQLI_ASSOC);
        $this->assertCount(1, $h, 'Solo quien tenía un consentimiento válido recibe fila de historial.');
        $this->assertSame([$ids['acepto'], 1, (int)$v1[0]['id'], 0, 'migracion', 'migracion', '2025-03-04 05:06:07', null, null],
            [(int)$h[0]['usuario_id'], (int)$h[0]['valor'], (int)$h[0]['version_id'], (int)$h[0]['version_informada'], $h[0]['origen'], $h[0]['canal'], $h[0]['ocurrido_en'], $h[0]['actor_id'], $h[0]['ip_hash']]);
        // Segunda pasada del SQL (reaplicación manual) no duplica nada.
        $this->assertSame(0, $this->multi($m, (string)file_get_contents(dirname(__DIR__, 2) . '/database/migrations/022_crm_auditoria_consentimiento_homonimos.sql')), 'El script es reaplicable.');
        $this->assertSame(1, (int)$m->query('SELECT COUNT(*) n FROM consentimientos_historial')->fetch_assoc()['n']);
        $this->assertSame(1, (int)$m->query("SELECT COUNT(*) n FROM consentimiento_versiones")->fetch_assoc()['n']);

        // Rollback: con solo el retro-llenado reproducible se permite… pero no si hay evidencia nueva.
        $m->query("INSERT INTO crm_exportaciones_auditoria(ocurrido_en,tipo,resultado) VALUES (UTC_TIMESTAMP(),'clientes','exitosa')");
        $this->assertSame(1242, $this->multi($m, $rollback), 'El rollback debe negarse (subconsulta de más de una fila) con auditoría presente.');
        $m->close(); $m = $this->conexion($bd);
        foreach (self::FILAS_TABLAS_SOLO_ANEXADO as $t) { $this->assertSame(1, $m->query("SHOW TABLES LIKE '{$t}'")->num_rows, "{$t} debe seguir existiendo tras un rollback rechazado."); }
        $this->assertSame(1, (int)$m->query('SELECT COUNT(*) n FROM crm_exportaciones_auditoria')->fetch_assoc()['n']);
        $this->assertSame(1, $m->query("SHOW COLUMNS FROM perfiles_usuario LIKE 'consentimiento_marketing_version_id'")->num_rows);
        $this->assertSame(1, (int)$m->query('SELECT COUNT(*) n FROM consentimientos_historial')->fetch_assoc()['n']);
        $m->close();
    }

    public function testMigracion022ExigeLaCapacidadDeCrearTriggersAntesDeAlterarNada(): void
    {
        $validador = require dirname(__DIR__, 2) . '/database/migrations/022_crm_auditoria_consentimiento_homonimos.precheck.php';
        $this->assertIsCallable($validador);
        $this->assertSame([], $validador($this->db), 'Con privilegios suficientes la pre-validación no encuentra problemas.');
        $this->assertSame(0, $this->db->query("SHOW TABLES LIKE '\\_sonda022'")->num_rows, 'La sonda no deja rastro.');
    }
}

<?php
namespace Repositories;

use Classes\Transaccion;
use Model\ActiveRecord;
use Model\Profesional;
use mysqli;
use Services\AuthorizationService;

/**
 * Persistencia de colaboradores (ficha laboral), invitaciones y su efecto en acceso y ficha profesional.
 * Toda escritura usa transacciones anidables para componerse con la reserva de idempotencia.
 */
final class ColaboradorRepository
{
    public const ROLES_INVITABLES = ['gerente', 'recepcion', 'profesional', 'finanzas'];
    public const DIAS_VIGENCIA = 7;
    public const ESPERA_REENVIO_SEGUNDOS = 60;

    /** Reconciliación explícita con una persona laboral existente; conserva toda la configuración operativa. */
    public function vincularProfesionalExistente(int $actor, int $colaborador, int $profesional): void
    {
        Transaccion::ejecutar($this->db, function () use ($actor,$colaborador,$profesional): void {
            $this->exigirPropietario($actor);
            $c=$this->una('SELECT * FROM colaboradores WHERE id=? FOR UPDATE','i',[$colaborador]);
            if (!$c || $c['rol']!=='profesional' || $c['estado']==='baja') {
                throw new \DomainException('Selecciona un colaborador vigente con rol Profesional.');
            }
            $p=$this->una('SELECT * FROM profesionales WHERE id=? FOR UPDATE','i',[$profesional]);
            if (!$p) throw new \DomainException('La ficha profesional no existe.');
            if ((int)($p['colaborador_id']??0)===$colaborador) return;
            if ($p['colaborador_id']!==null || ($p['usuarioId']!==null && (int)$p['usuarioId']!==(int)($c['usuario_id']??0))) {
                throw new \DomainException('La ficha pertenece a otra persona. No se cambió ningún vínculo.');
            }
            if ($this->una('SELECT id FROM profesionales WHERE colaborador_id=? FOR UPDATE','i',[$colaborador])) {
                throw new \DomainException('El colaborador ya tiene una ficha profesional. Revisa sus historiales antes de vincular.');
            }
            $activo=$c['estado']==='activo' ? (int)$p['activo'] : 0;
            $usuario=$c['estado']==='invitado' ? null : $c['usuario_id'];
            $this->sql('UPDATE profesionales SET colaborador_id=?,usuarioId=?,activo=? WHERE id=?','iiii',[$colaborador,$usuario,$activo,$profesional])->close();
            $this->auditar($actor,$c['usuario_id'],'profesional_vinculado',"colaborador:$colaborador;profesional:$profesional");
        });
    }

    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $conexion = $db ?? ActiveRecord::getDB();
        if (!$conexion instanceof mysqli) { throw new PersistenceException('Sin conexión'); }
        $this->db = $conexion;
    }

    private function sql(string $sql, string $tipos = '', array $args = []): \mysqli_stmt
    {
        $s = $this->db->prepare($sql);
        if (!$s) { throw new PersistenceException('No fue posible preparar la operación de colaboradores'); }
        if ($tipos !== '') { $s->bind_param($tipos, ...$args); }
        try {
            $ok = $s->execute();
            $errno = (int)$s->errno;
        } catch (\mysqli_sql_exception $e) {
            $ok = false;
            $errno = (int)$e->getCode();
        }
        if (!$ok) {
            $s->close();
            if ($errno === 1062) { throw new DuplicateEntryException('Registro duplicado', $errno); }
            // El errno viaja en el código para que Idempotencia reintente interbloqueos (1213) y espere bloqueos (1205).
            throw new PersistenceException('No fue posible guardar la operación de colaboradores', $errno);
        }
        return $s;
    }

    private function una(string $sql, string $tipos = '', array $args = []): ?array
    {
        $s = $this->sql($sql, $tipos, $args);
        $f = $s->get_result()->fetch_assoc();
        $s->close();
        return $f ?: null;
    }

    private function todas(string $sql, string $tipos = '', array $args = []): array
    {
        $s = $this->sql($sql, $tipos, $args);
        $f = $s->get_result()->fetch_all(MYSQLI_ASSOC);
        $s->close();
        return $f;
    }

    private function auditar(?int $actor, ?int $usuarioId, string $accion, string $detalle): void
    {
        $this->sql('INSERT INTO equipo_auditoria(actor_id,usuario_id,accion,detalle) VALUES(?,?,?,?)', 'iiss', [$actor, $usuarioId, $accion, mb_substr($detalle, 0, 255, 'UTF-8')])->close();
    }

    /** Solo un propietario activo gestiona colaboradores; se verifica dentro de la transacción. */
    private function exigirPropietario(int $actor): void
    {
        $c = (new AuthorizationService($this->db))->contexto($actor);
        if (!$c || $c['rol'] !== 'propietario' || (int)$c['activo'] !== 1) {
            throw new \DomainException('Solo el propietario puede gestionar al equipo');
        }
    }

    // ------------------------------------------------------------------ Lectura

    public function listar(): array
    {
        return $this->todas("SELECT c.id, c.codigo, c.usuario_id, c.email_norm email, c.nombre, c.apellido, c.telefono, c.puesto, c.rol, c.estado, c.fecha_ingreso,
                c.creado_en, c.aceptado_en, p.id profesional_id, p.nombre profesional,
                (SELECT i.expira_en FROM colaborador_invitaciones i WHERE i.colaborador_id=c.id AND i.estado='pendiente' LIMIT 1) invitacion_expira,
                (SELECT i.creada_en FROM colaborador_invitaciones i WHERE i.colaborador_id=c.id ORDER BY i.id DESC LIMIT 1) ultima_invitacion,
                (SELECT o.estado FROM correos_salida o WHERE o.clave_unica=CONCAT('invitacion:',(SELECT MAX(i.id) FROM colaborador_invitaciones i WHERE i.colaborador_id=c.id)) LIMIT 1) correo_estado
            FROM colaboradores c LEFT JOIN profesionales p ON p.colaborador_id=c.id
            ORDER BY FIELD(c.estado,'invitado','activo','suspendido','baja'), c.nombre, c.id LIMIT 500");
    }

    public function obtener(int $id): ?array
    {
        return $this->una("SELECT c.*, c.email_norm email, p.id profesional_id, p.nombre profesional FROM colaboradores c LEFT JOIN profesionales p ON p.colaborador_id=c.id WHERE c.id=?", 'i', [$id]);
    }

    /** Directorio de RR. HH. paginado; sin el límite de 500 ni datos de invitación del panel de accesos. */
    public function directorio(string $busqueda, ?string $rol, ?string $estado, int $pagina): array
    {
        $where = ' WHERE 1=1'; $tipos = ''; $args = [];
        if ($busqueda !== '') {
            $where .= " AND (CONCAT(c.nombre,' ',c.apellido) LIKE ? OR c.email_norm LIKE ? OR c.codigo LIKE ? OR c.puesto LIKE ?)";
            $like = '%' . addcslashes($busqueda, '%_\\') . '%';
            $tipos .= 'ssss'; array_push($args, $like, $like, $like, $like);
        }
        if ($rol !== null) { $where .= ' AND c.rol=?'; $tipos .= 's'; $args[] = $rol; }
        if ($estado !== null) { $where .= ' AND c.estado=?'; $tipos .= 's'; $args[] = $estado; }
        $total = (int)$this->una('SELECT COUNT(*) total FROM colaboradores c' . $where, $tipos, $args)['total'];
        $paginas = max(1, (int)ceil($total / 25)); $pagina = max(1, min($pagina, $paginas));
        $filas = $this->todas('SELECT c.id,c.codigo,c.nombre,c.apellido,c.email_norm email,c.telefono,c.puesto,c.rol,c.estado,c.fecha_ingreso,p.id profesional_id '
            . 'FROM colaboradores c LEFT JOIN profesionales p ON p.colaborador_id=c.id' . $where . ' ORDER BY c.apellido,c.nombre,c.id LIMIT 25 OFFSET ?',
            $tipos . 'i', [...$args, ($pagina - 1) * 25]);
        return ['filas' => $filas, 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas];
    }

    /** Fichas profesionales heredadas sin colaborador: candidatas a vinculación explícita. */
    public function profesionalesSinCuenta(): array
    {
        return $this->todas('SELECT id, nombre, activo FROM profesionales WHERE colaborador_id IS NULL ORDER BY nombre, id LIMIT 300');
    }

    /** Reinvitar la misma identidad revocada conserva su extensión profesional. */
    public function fichaReinvitable(string $email): ?int
    {
        $r = $this->una("SELECT p.id FROM profesionales p JOIN colaboradores c ON c.id=p.colaborador_id WHERE c.email_norm=? AND c.estado='baja' AND c.aceptado_en IS NULL", 's', [\Classes\Identidad::normalizarEmail($email)]);
        return $r ? (int)$r['id'] : null;
    }

    public function correo(string $clave): ?array
    {
        return (new CorreoSalidaRepository($this->db))->estadoPorClave($clave);
    }

    // ------------------------------------------------------------------ Invitar

    /**
     * Alta de colaborador pendiente de aceptación. NO crea credenciales ni concede acceso laboral.
     * Si el correo ya pertenece a una cuenta (p. ej. un cliente), se asocia sin duplicar identidad
     * ni alterar su experiencia de cliente.
     *
     * @param array{nombre:string,apellido:string,email:string,telefono:?string,puesto:string,rol:string,fecha_ingreso:?string,servicios:int[],nombre_profesional:string,profesional_id:?int} $d
     * @return array{colaborador_id:int,codigo:string,invitacion_id:int,correo_id:int,profesional_id:?int}
     * @throws DuplicateEntryException si el correo ya tiene ficha laboral
     * @throws \DomainException ante reglas de negocio
     */
    public function crearInvitacion(int $actor, array $d, int $profesionalHeredado = 0): array
    {
        return Transaccion::ejecutar($this->db, function () use ($actor, $d, $profesionalHeredado): array {
            $this->exigirPropietario($actor);
            if (!in_array($d['rol'], self::ROLES_INVITABLES, true)) { throw new \DomainException('Rol no válido para una invitación'); }
            $emailNorm = \Classes\Identidad::normalizarEmail($d['email']);
            $usuario = $this->una('SELECT id FROM usuarios WHERE LOWER(TRIM(email))=? LIMIT 1', 's', [$emailNorm]);
            $usuarioId = $usuario ? (int)$usuario['id'] : null;

            try {
                $this->sql("INSERT INTO colaboradores(usuario_id,email_norm,nombre,apellido,telefono,puesto,rol,estado,fecha_ingreso,creado_por) VALUES (?,?,?,?,?,?,?,'invitado',?,?)",
                    'isssssssi', [$usuarioId, $emailNorm, $d['nombre'], $d['apellido'], $d['telefono'], $d['puesto'], $d['rol'], $d['fecha_ingreso'], $actor])->close();
                $colaboradorId = (int)$this->db->insert_id;
                $codigo = sprintf('COL-%06d', $colaboradorId);
                $this->sql('UPDATE colaboradores SET codigo=? WHERE id=?', 'si', [$codigo, $colaboradorId])->close();
            } catch (DuplicateEntryException $duplicado) {
                // Solo se reutiliza una ficha cuya invitación fue revocada SIN haberse aceptado nunca (un correo = una ficha).
                // Se consulta después del conflicto para no tomar bloqueos de hueco que provoquen interbloqueos.
                $previo = $this->una('SELECT id, codigo, estado, aceptado_en FROM colaboradores WHERE email_norm=? FOR UPDATE', 's', [$emailNorm]);
                if (!$previo || $previo['estado'] !== 'baja' || $previo['aceptado_en'] !== null) { throw $duplicado; }
                $colaboradorId = (int)$previo['id'];
                $codigo = (string)$previo['codigo'];
                $this->sql("UPDATE colaboradores SET usuario_id=?, nombre=?, apellido=?, telefono=?, puesto=?, rol=?, estado='invitado', fecha_ingreso=?, baja_en=NULL, creado_por=? WHERE id=?",
                    'issssssii', [$usuarioId, $d['nombre'], $d['apellido'], $d['telefono'], $d['puesto'], $d['rol'], $d['fecha_ingreso'], $actor, $colaboradorId])->close();
            }

            $profesionalId = null;
            if ($d['rol'] === 'profesional') {
                $profesionalId = $this->vincularFicha($colaboradorId, $d, $profesionalHeredado);
            }

            $invitacion = $this->emitirInvitacion($colaboradorId, $actor);
            $correoId = $this->encolarInvitacion($invitacion, $d['email'], mb_substr(trim($d['nombre'] . ' ' . $d['apellido']), 0, 120, 'UTF-8'), $d['rol'], $d['puesto']);
            $this->auditar($actor, $usuarioId, 'colaborador_invitado', "$codigo:{$d['rol']}");
            return ['colaborador_id' => $colaboradorId, 'codigo' => $codigo, 'invitacion_id' => $invitacion['id'], 'correo_id' => $correoId, 'profesional_id' => $profesionalId];
        });
    }

    private function vincularFicha(int $colaboradorId, array $d, int $profesionalHeredado): int
    {
        $existente = $this->una('SELECT id FROM profesionales WHERE colaborador_id=? FOR UPDATE', 'i', [$colaboradorId]);
        if ($existente) {
            $id = (int)$existente['id'];
            if ($profesionalHeredado > 0 && $profesionalHeredado !== $id) {
                throw new \DomainException('La persona ya tiene una ficha profesional. Conserva su vínculo e historial.', 422);
            }
            if (!empty($d['agenda']['aplicar'])) {
                (new ProfesionalRepository($this->db))->reemplazarConfiguracionAgenda($id, $d['servicios'], $d['agenda']['franjas'], $d['agenda']['descansos']);
            }
            return $id;
        }
        if ($profesionalHeredado > 0) {
            // Invitación asistida a una ficha heredada: conserva su historial; no crea otra ficha ni cuenta.
            $ficha = $this->una('SELECT id, usuarioId, colaborador_id FROM profesionales WHERE id=? FOR UPDATE', 'i', [$profesionalHeredado]);
            $persona = $this->una('SELECT usuario_id FROM colaboradores WHERE id=?', 'i', [$colaboradorId]);
            if (!$ficha || $ficha['colaborador_id'] !== null || ($ficha['usuarioId'] !== null && (int)$ficha['usuarioId'] !== (int)($persona['usuario_id'] ?? 0))) {
                throw new \DomainException('La ficha profesional no existe o ya está vinculada a una cuenta');
            }
            $this->sql('UPDATE profesionales SET colaborador_id=?,activo=0 WHERE id=?', 'ii', [$colaboradorId, $profesionalHeredado])->close();
            // Solo se reescribe su configuración si el operador lo pidió expresamente (casilla del asistente).
            $agenda = $d['agenda'] ?? null;
            if ($agenda && !empty($agenda['aplicar'])) {
                (new ProfesionalRepository($this->db))->reemplazarConfiguracionAgenda($profesionalHeredado, $d['servicios'], $agenda['franjas'], $agenda['descansos']);
            }
            return $profesionalHeredado;
        }
        $prof = new Profesional(['nombre' => $d['nombre_profesional'], 'activo' => '0']);
        $repo = new ProfesionalRepository($this->db);
        $id = $repo->createWithServicios($prof, []);
        $this->sql('UPDATE profesionales SET colaborador_id=?,activo=0 WHERE id=?', 'ii', [$colaboradorId, $id])->close();
        // La ficha nace INACTIVA: servicios y horario definidos en el asistente quedan guardados (y validados: servicios
        // existentes, sin solapes) pero no son reservables, porque disponibilidad y reserva exigen activo=1, hasta que
        // la persona acepte la invitación.
        $agenda = $d['agenda'] ?? ['franjas' => [], 'descansos' => []];
        $repo->reemplazarConfiguracionAgenda($id, $d['servicios'], $agenda['franjas'], $agenda['descansos']);
        // Decisión del operador ante posibles homónimos: se anexa en la misma transacción que la ficha y la invitación.
        if (!empty($d['homonimo'])) { $d['homonimo']['servicio']->registrarContinuo($d['homonimo']['decision'], $id); }
        return $id;
    }

    /** @return array{id:int,token:string} */
    private function emitirInvitacion(int $colaboradorId, ?int $actor): array
    {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $dias = self::DIAS_VIGENCIA;
        $this->sql("INSERT INTO colaborador_invitaciones(colaborador_id,token_hash,expira_en,creada_por,creada_en) VALUES (?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL {$dias} DAY),?,UTC_TIMESTAMP())",
            'isi', [$colaboradorId, $hash, $actor])->close();
        return ['id' => (int)$this->db->insert_id, 'token' => $token];
    }

    private function encolarInvitacion(array $invitacion, string $email, string $nombre, string $rol, string $puesto): int
    {
        $etiquetas = ['gerente' => 'Gerencia', 'recepcion' => 'Recepción', 'profesional' => 'Profesional', 'finanzas' => 'Finanzas', 'propietario' => 'Propietario'];
        return (new CorreoSalidaRepository($this->db))->encolar('invitacion:' . $invitacion['id'], 'invitacion_colaborador', $email, $nombre,
            ['token' => $invitacion['token'], 'rol_etiqueta' => $etiquetas[$rol] ?? $rol, 'puesto' => $puesto]);
    }

    /**
     * Reenvío explícito: revoca la invitación pendiente (su enlace deja de servir), cancela su evento y
     * emite una nueva. Limitado a una por minuto por colaborador.
     */
    public function reenviarInvitacion(int $actor, int $colaboradorId): array
    {
        return Transaccion::ejecutar($this->db, function () use ($actor, $colaboradorId): array {
            // Bloqueo primero: las lecturas posteriores deben ver lo que confirmó el reenvío anterior
            // (una lectura consistente previa congelaría una instantánea y evadiría la espera mínima).
            $c = $this->una('SELECT * FROM colaboradores WHERE id=? FOR UPDATE', 'i', [$colaboradorId]);
            $this->exigirPropietario($actor);
            if (!$c || $c['estado'] !== 'invitado') { throw new \DomainException('Solo se puede reenviar a quien aún no aceptó su invitación'); }
            $ultima = $this->una("SELECT id, TIMESTAMPDIFF(SECOND, creada_en, UTC_TIMESTAMP()) segundos FROM colaborador_invitaciones WHERE colaborador_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE", 'i', [$colaboradorId]);
            if ($ultima && (int)$ultima['segundos'] < self::ESPERA_REENVIO_SEGUNDOS) {
                throw new \DomainException('Acabas de enviar una invitación. Espera un minuto antes de reenviarla.');
            }
            foreach ($this->todas("SELECT id FROM colaborador_invitaciones WHERE colaborador_id=? AND estado='pendiente' FOR UPDATE", 'i', [$colaboradorId]) as $previa) {
                $this->sql("UPDATE colaborador_invitaciones SET estado='revocada', revocada_en=UTC_TIMESTAMP() WHERE id=?", 'i', [(int)$previa['id']])->close();
                (new CorreoSalidaRepository($this->db))->cancelar('invitacion:' . (int)$previa['id']);
            }
            $inv = $this->emitirInvitacion($colaboradorId, $actor);
            $nombre = mb_substr(trim($c['nombre'] . ' ' . $c['apellido']), 0, 120, 'UTF-8');
            $correoId = $this->encolarInvitacion($inv, $c['email_norm'], $nombre, $c['rol'], (string)$c['puesto']);
            $this->auditar($actor, $c['usuario_id'] !== null ? (int)$c['usuario_id'] : null, 'invitacion_reenviada', (string)$c['codigo']);
            return ['colaborador_id' => $colaboradorId, 'invitacion_id' => $inv['id'], 'correo_id' => $correoId];
        });
    }

    /** Revoca una invitación pendiente y cierra la ficha como baja (sin acceso alguno). */
    public function revocarInvitacion(int $actor, int $colaboradorId): void
    {
        Transaccion::ejecutar($this->db, function () use ($actor, $colaboradorId): void {
            $this->exigirPropietario($actor);
            $c = $this->una('SELECT * FROM colaboradores WHERE id=? FOR UPDATE', 'i', [$colaboradorId]);
            if (!$c || $c['estado'] !== 'invitado') { throw new \DomainException('Solo se puede revocar una invitación pendiente'); }
            foreach ($this->todas("SELECT id FROM colaborador_invitaciones WHERE colaborador_id=? AND estado='pendiente' FOR UPDATE", 'i', [$colaboradorId]) as $previa) {
                $this->sql("UPDATE colaborador_invitaciones SET estado='revocada', revocada_en=UTC_TIMESTAMP() WHERE id=?", 'i', [(int)$previa['id']])->close();
                (new CorreoSalidaRepository($this->db))->cancelar('invitacion:' . (int)$previa['id']);
            }
            $this->sql("UPDATE colaboradores SET estado='baja', baja_en=UTC_TIMESTAMP() WHERE id=?", 'i', [$colaboradorId])->close();
            // La revocación conserva la identidad laboral y el historial de su ficha.
            if ($this->citasFuturasDeProfesional($colaboradorId) > 0) { throw new \DomainException('Resuelve las citas futuras antes de revocar esta invitación.'); }
            $this->sql('UPDATE profesionales SET activo=0 WHERE colaborador_id=?', 'i', [$colaboradorId])->close();
            $this->auditar($actor, $c['usuario_id'] !== null ? (int)$c['usuario_id'] : null, 'invitacion_revocada', (string)$c['codigo']);
        });
    }

    // ------------------------------------------------------------------ Primer propietario (instalación)

    /**
     * Aprovisiona al PRIMER propietario de forma idempotente y concurrente-segura. Nunca cambia contraseña
     * ni rol de una cuenta existente por su cuenta y nunca crea un segundo propietario: si ya hay uno activo
     * (o una activación pendiente) no hace nada.
     *
     * Con $password crea la cuenta activa (secreto de instalación aportado por quien opera el servidor,
     * 12–72 caracteres; una cuenta existente solo se eleva si coincide su contraseña). Sin $password emite una
     * invitación de propietario de un solo uso: la persona define su propia contraseña al aceptarla.
     *
     * @return array{estado:string,token?:string,correo_id?:int,usuario_id?:int}
     *         estado: existente | pendiente | creado | activacion_emitida
     */
    public function aprovisionarPropietarioInicial(string $email, string $nombre, string $apellido, ?string $password, bool $regenerar = false): array
    {
        $emailNorm = \Classes\Identidad::normalizarEmail($email);
        return Transaccion::ejecutar($this->db, function () use ($email, $emailNorm, $nombre, $apellido, $password, $regenerar): array {
            $this->sql('SELECT id FROM equipo_mutex WHERE id=1 FOR UPDATE')->close();
            $activos = $this->una("SELECT COUNT(*) total FROM usuarios u LEFT JOIN usuario_accesos a ON a.usuario_id=u.id
                WHERE COALESCE(a.rol,IF(u.admin=1,'propietario','cliente'))='propietario' AND COALESCE(a.activo,1)=1 AND u.confirmado=1");
            if ((int)$activos['total'] > 0) { return ['estado' => 'existente']; }

            $pendiente = $this->una("SELECT c.id, c.usuario_id FROM colaboradores c WHERE c.rol='propietario' AND c.estado='invitado' LIMIT 1 FOR UPDATE");
            if ($pendiente && !$regenerar) { return ['estado' => 'pendiente']; }
            if ($pendiente) {
                foreach ($this->todas("SELECT id FROM colaborador_invitaciones WHERE colaborador_id=? AND estado='pendiente' FOR UPDATE", 'i', [(int)$pendiente['id']]) as $previa) {
                    $this->sql("UPDATE colaborador_invitaciones SET estado='revocada', revocada_en=UTC_TIMESTAMP() WHERE id=?", 'i', [(int)$previa['id']])->close();
                    (new CorreoSalidaRepository($this->db))->cancelar('invitacion:' . (int)$previa['id']);
                }
                // El operador pudo corregir el correo: la ficha y la cuenta futura deben coincidir con el enlace.
                $existente = $this->una('SELECT id FROM usuarios WHERE LOWER(TRIM(email))=? LIMIT 1', 's', [$emailNorm]);
                $this->sql('UPDATE colaboradores SET email_norm=?, nombre=?, apellido=?, usuario_id=? WHERE id=?', 'sssii',
                    [$emailNorm, $nombre, $apellido, $existente ? (int)$existente['id'] : null, (int)$pendiente['id']])->close();
                $inv = $this->emitirInvitacion((int)$pendiente['id'], null);
                $correoId = $this->encolarInvitacion($inv, $emailNorm, mb_substr(trim($nombre . ' ' . $apellido), 0, 120, 'UTF-8'), 'propietario', 'Propietario');
                $this->auditar(null, null, 'bootstrap_activacion_regenerada', 'propietario');
                return ['estado' => 'activacion_emitida', 'token' => $inv['token'], 'correo_id' => $correoId];
            }

            $usuario = $this->una('SELECT id, password FROM usuarios WHERE LOWER(TRIM(email))=? LIMIT 1 FOR UPDATE', 's', [$emailNorm]);
            if ($password !== null) {
                if ($usuario) {
                    if (!password_verify($password, (string)$usuario['password'])) { throw new \DomainException('La contraseña no coincide con la cuenta existente'); }
                    $usuarioId = (int)$usuario['id'];
                } else {
                    $this->sql("INSERT INTO usuarios(nombre,apellido,email,password,telefono,admin,confirmado) VALUES (?,?,?,?,NULL,1,1)", 'ssss',
                        [$nombre, $apellido, $emailNorm, password_hash($password, PASSWORD_BCRYPT)])->close();
                    $usuarioId = (int)$this->db->insert_id;
                }
                $this->sql("INSERT INTO usuario_accesos(usuario_id,rol,activo) VALUES(?,'propietario',1) ON DUPLICATE KEY UPDATE rol='propietario',activo=1", 'i', [$usuarioId])->close();
                $this->sql('UPDATE usuarios SET admin=1, confirmado=1, version_sesion=version_sesion+1 WHERE id=?', 'i', [$usuarioId])->close();
                $this->sql("INSERT INTO colaboradores(usuario_id,email_norm,nombre,apellido,rol,estado,aceptado_en,fecha_ingreso)
                    VALUES (?,?,?,?,'propietario','activo',UTC_TIMESTAMP(),DATE(CONVERT_TZ(UTC_TIMESTAMP(),'+00:00','-05:00')))
                    ON DUPLICATE KEY UPDATE rol='propietario', estado='activo'", 'isss', [$usuarioId, $emailNorm, $nombre, $apellido])->close();
                $this->sql("UPDATE colaboradores SET codigo=CONCAT('COL-',LPAD(id,6,'0')) WHERE usuario_id=? AND codigo IS NULL", 'i', [$usuarioId])->close();
                $this->auditar(null, $usuarioId, 'bootstrap_propietario', 'Provisionado por el instalador con secreto de instalación');
                return ['estado' => 'creado', 'usuario_id' => $usuarioId];
            }

            $this->sql("INSERT INTO colaboradores(usuario_id,email_norm,nombre,apellido,telefono,puesto,rol,estado) VALUES (?,?,?,?,NULL,'Propietario','propietario','invitado')",
                'isss', [$usuario ? (int)$usuario['id'] : null, $emailNorm, $nombre, $apellido])->close();
            $colaboradorId = (int)$this->db->insert_id;
            $this->sql('UPDATE colaboradores SET codigo=? WHERE id=?', 'si', [sprintf('COL-%06d', $colaboradorId), $colaboradorId])->close();
            $inv = $this->emitirInvitacion($colaboradorId, null);
            $correoId = $this->encolarInvitacion($inv, $emailNorm, mb_substr(trim($nombre . ' ' . $apellido), 0, 120, 'UTF-8'), 'propietario', 'Propietario');
            $this->auditar(null, $usuario ? (int)$usuario['id'] : null, 'bootstrap_activacion', 'propietario');
            return ['estado' => 'activacion_emitida', 'token' => $inv['token'], 'correo_id' => $correoId];
        });
    }

    // ------------------------------------------------------------------ Aceptación

    /**
     * Estado de un enlace de invitación sin consumirlo (HEAD/GET no activan nada).
     * @return array{estado:string,colaborador?:array,identidad?:string}
     */
    public function vistaInvitacion(string $token): array
    {
        $hash = hash('sha256', $token);
        $this->sql("UPDATE colaborador_invitaciones SET estado='expirada' WHERE token_hash=? AND estado='pendiente' AND expira_en<UTC_TIMESTAMP()", 's', [$hash])->close();
        $f = $this->una("SELECT i.estado inv_estado, c.id, c.codigo, c.nombre, c.apellido, c.rol, c.puesto, c.estado, c.email_norm, c.usuario_id
            FROM colaborador_invitaciones i JOIN colaboradores c ON c.id=i.colaborador_id WHERE i.token_hash=?", 's', [$hash]);
        if (!$f) { return ['estado' => 'invalida']; }
        if ($f['inv_estado'] !== 'pendiente') {
            return ['estado' => $f['inv_estado'] === 'aceptada' ? 'usada' : $f['inv_estado'], 'colaborador' => $f];
        }
        if ($f['estado'] !== 'invitado') { return ['estado' => 'revocada', 'colaborador' => $f]; }
        $u = $this->una('SELECT id, confirmado FROM usuarios WHERE LOWER(TRIM(email))=? LIMIT 1', 's', [$f['email_norm']]);
        $identidad = !$u ? 'nueva' : ((int)$u['confirmado'] === 1 ? 'existente_confirmada' : 'existente_sin_confirmar');
        return ['estado' => 'valida', 'colaborador' => $f, 'identidad' => $identidad];
    }

    /**
     * Consume la invitación UNA sola vez y activa el acceso laboral de forma atómica.
     * Cuenta existente confirmada: exige su contraseña actual (no se cambia). Cuenta existente sin
     * confirmar: el correo recibido prueba el control del buzón, así que se fija la contraseña elegida,
     * se confirma y se invalidan sesiones previas. Cuenta inexistente: se crea sin membresía de cliente.
     *
     * @return array{usuario_id:int,rol:string,colaborador_id:int}
     * @throws \DomainException con código 'invitacion' (enlace no válido) o 'credenciales' (contraseña actual incorrecta)
     */
    public function aceptar(string $token, string $passwordNueva, string $passwordActual): array
    {
        $hash = hash('sha256', $token);
        return Transaccion::ejecutar($this->db, function () use ($hash, $passwordNueva, $passwordActual): array {
            $s = $this->sql("UPDATE colaborador_invitaciones SET estado='aceptada', aceptada_en=UTC_TIMESTAMP() WHERE token_hash=? AND estado='pendiente' AND expira_en>=UTC_TIMESTAMP()", 's', [$hash]);
            $consumida = $s->affected_rows === 1;
            $s->close();
            if (!$consumida) { throw new \DomainException('La invitación no es válida, ya se usó o venció', 1); }

            $c = $this->una('SELECT c.* FROM colaboradores c JOIN colaborador_invitaciones i ON i.colaborador_id=c.id WHERE i.token_hash=? FOR UPDATE', 's', [$hash]);
            if (!$c || $c['estado'] !== 'invitado') { throw new \DomainException('La invitación no es válida, ya se usó o venció', 1); }
            $u = $this->una('SELECT id, nombre, apellido, password, confirmado FROM usuarios WHERE LOWER(TRIM(email))=? LIMIT 1 FOR UPDATE', 's', [$c['email_norm']]);

            $admin = in_array($c['rol'], ['propietario', 'gerente'], true) ? 1 : 0;
            if ($u && (int)$u['confirmado'] === 1) {
                if (!password_verify($passwordActual, (string)$u['password'])) { throw new \DomainException('Contraseña actual incorrecta', 2); }
                $usuarioId = (int)$u['id'];
            } elseif ($u) {
                $hashPwd = password_hash($passwordNueva, PASSWORD_BCRYPT);
                $usuarioId = (int)$u['id'];
                $this->sql("UPDATE usuarios SET password=?, confirmado=1, token=NULL, token_hash=NULL, token_tipo=NULL, token_expira=NULL WHERE id=?", 'si', [$hashPwd, $usuarioId])->close();
            } else {
                $hashPwd = password_hash($passwordNueva, PASSWORD_BCRYPT);
                $this->sql("INSERT INTO usuarios(nombre,apellido,email,password,telefono,admin,confirmado) VALUES (?,?,?,?,?,0,1)", 'sssss',
                    [$c['nombre'], $c['apellido'], $c['email_norm'], $hashPwd, (string)($c['telefono'] ?? '')])->close();
                $usuarioId = (int)$this->db->insert_id;
            }
            // Una cuenta ya enlazada a otro colaborador viola UNIQUE → DuplicateEntryException (nunca doble ficha).
            $this->sql("INSERT INTO usuario_accesos(usuario_id,rol,activo) VALUES(?,?,1) ON DUPLICATE KEY UPDATE rol=VALUES(rol),activo=1", 'is', [$usuarioId, $c['rol']])->close();
            $this->sql('UPDATE usuarios SET admin=?, version_sesion=version_sesion+1 WHERE id=?', 'ii', [$admin, $usuarioId])->close();
            $this->sql("UPDATE colaboradores SET usuario_id=?, estado='activo', aceptado_en=UTC_TIMESTAMP(),
                fecha_ingreso=COALESCE(fecha_ingreso, DATE(CONVERT_TZ(UTC_TIMESTAMP(),'+00:00','-05:00'))) WHERE id=?", 'ii', [$usuarioId, (int)$c['id']])->close();
            if ($c['rol'] === 'profesional') {
                $this->sql('UPDATE profesionales SET usuarioId=?, activo=1 WHERE colaborador_id=?', 'ii', [$usuarioId, (int)$c['id']])->close();
            }
            $this->auditar(null, $usuarioId, 'invitacion_aceptada', (string)$c['codigo'] . ':' . $c['rol']);
            return ['usuario_id' => $usuarioId, 'rol' => $c['rol'], 'colaborador_id' => (int)$c['id']];
        });
    }

    // ------------------------------------------------------------------ Ficha laboral y acceso

    public function actualizarFicha(int $actor, int $colaboradorId, array $d): void
    {
        Transaccion::ejecutar($this->db, function () use ($actor, $colaboradorId, $d): void {
            $c = (new AuthorizationService($this->db))->contexto($actor);
            if (!$c || (int)$c['activo'] !== 1 || !in_array('rh.gestionar', AuthorizationService::ROLES[$c['rol']] ?? [], true)) {
                throw new \DomainException('No tienes permiso para editar fichas laborales');
            }
            $existe = $this->una('SELECT id, codigo, usuario_id FROM colaboradores WHERE id=? FOR UPDATE', 'i', [$colaboradorId]);
            if (!$existe) { throw new \DomainException('Colaborador no encontrado'); }
            $this->sql('UPDATE colaboradores SET puesto=?, fecha_ingreso=?, notas_rh=?, telefono=? WHERE id=?', 'ssssi',
                [$d['puesto'], $d['fecha_ingreso'], $d['notas_rh'], $d['telefono'], $colaboradorId])->close();
            $this->auditar($actor, $existe['usuario_id'] !== null ? (int)$existe['usuario_id'] : null, 'ficha_actualizada', (string)$existe['codigo']);
        });
    }

    /**
     * Guarda servicios, horario semanal y descansos de la ficha profesional de un colaborador (asistente de la ficha).
     * Atómico: si algo falla (servicio inexistente, choque con citas ya tomadas) no cambia nada.
     * No activa ni desactiva la ficha: una persona invitada sigue siendo no reservable hasta aceptar.
     *
     * @param int[]  $servicios
     * @return array{profesional_id:int,estado:string}
     * @throws \DomainException permiso insuficiente o regla de negocio
     */
    public function configurarAgendaProfesional(int $actor, int $colaboradorId, array $servicios, array $franjas, array $descansos): array
    {
        return Transaccion::ejecutar($this->db, function () use ($actor, $colaboradorId, $servicios, $franjas, $descansos): array {
            $c = (new AuthorizationService($this->db))->contexto($actor);
            $permisos = $c && (int)$c['activo'] === 1 ? (AuthorizationService::ROLES[$c['rol']] ?? []) : [];
            if (!in_array('rh.gestionar', $permisos, true) || !in_array('profesionales.gestionar', $permisos, true)) {
                throw new \DomainException('No tienes permiso para configurar servicios y horarios del profesional.');
            }
            $col = $this->una('SELECT id, codigo, rol, estado, usuario_id FROM colaboradores WHERE id=? FOR UPDATE', 'i', [$colaboradorId]);
            if (!$col) { throw new \DomainException('Colaborador no encontrado.'); }
            if ($col['rol'] !== 'profesional') { throw new \DomainException('Solo las personas con rol Profesional tienen servicios y horario.', 422); }
            if ($col['estado'] === 'baja') { throw new \DomainException('La persona está dada de baja; no se puede modificar su agenda.', 422); }
            $ficha = $this->una('SELECT id FROM profesionales WHERE colaborador_id=? FOR UPDATE', 'i', [$colaboradorId]);
            if (!$ficha) { throw new \DomainException('Esta persona no tiene ficha profesional vinculada.', 422); }
            $pid = (int)$ficha['id'];
            (new ProfesionalRepository($this->db))->reemplazarConfiguracionAgenda($pid, $servicios, $franjas, $descansos);
            $this->auditar($actor, $col['usuario_id'] !== null ? (int)$col['usuario_id'] : null, 'agenda_profesional',
                (string)$col['codigo'] . ': ' . count($servicios) . ' serv., ' . count($franjas) . ' franjas, ' . count($descansos) . ' descansos');
            return ['profesional_id' => $pid, 'estado' => (string)$col['estado']];
        });
    }

    /** Citas futuras que seguirían asignadas a la ficha profesional de este colaborador. */
    public function citasFuturasDeProfesional(int $colaboradorId): int
    {
        // Se bloquea primero la ficha: una reserva concurrente (que bloquea la misma fila) o termina antes y
        // se cuenta, o espera y encuentra la ficha ya suspendida. Sin esto una cita nacería huérfana.
        $this->sql('SELECT id FROM profesionales WHERE colaborador_id=? FOR UPDATE', 'i', [$colaboradorId])->close();
        $f = $this->una("SELECT COUNT(*) n FROM citas c JOIN profesionales p ON p.id=c.profesionalId
            WHERE p.colaborador_id=? AND c.estado IN ('confirmada','en_servicio') AND c.fecha >= DATE(CONVERT_TZ(UTC_TIMESTAMP(),'+00:00','-05:00')) FOR SHARE", 'i', [$colaboradorId]);
        return (int)($f['n'] ?? 0);
    }

    /**
     * Cambia rol y estado laboral de un colaborador ACEPTADO, revocando sus sesiones.
     * Suspender o dar de baja a un profesional con citas futuras exige resolverlas antes: se muestra el
     * impacto y no se orfanan reservas. La baja conserva todo el historial (nada se borra).
     * @param string $estado activo|suspendido|baja
     */
    public function cambiarAcceso(int $actor, int $colaboradorId, string $rol, string $estado): void
    {
        if (!in_array($estado, ['activo', 'suspendido', 'baja'], true)) { throw new \InvalidArgumentException('Estado laboral no válido'); }
        if (!isset(AuthorizationService::ROLES[$rol]) || $rol === 'cliente') { throw new \InvalidArgumentException('Rol no válido'); }
        Transaccion::ejecutar($this->db, function () use ($actor, $colaboradorId, $rol, $estado): void {
            $this->sql('SELECT id FROM equipo_mutex WHERE id=1 FOR UPDATE')->close();
            $this->exigirPropietario($actor);
            $c = $this->una('SELECT * FROM colaboradores WHERE id=? FOR UPDATE', 'i', [$colaboradorId]);
            if (!$c) { throw new \DomainException('Colaborador no encontrado'); }
            if ($c['estado'] === 'invitado' || $c['usuario_id'] === null) { throw new \DomainException('Aún no aceptó su invitación: reenvíala o revócala'); }
            if ($c['estado'] === 'baja' && $estado !== 'activo') { throw new \DomainException('El colaborador ya está dado de baja'); }
            $pierdeAgenda = ($c['rol'] === 'profesional') && ($estado !== 'activo' || $rol !== 'profesional');
            if ($pierdeAgenda) {
                $pendientes = $this->citasFuturasDeProfesional($colaboradorId);
                if ($pendientes > 0) {
                    throw new \DomainException("Tiene {$pendientes} cita(s) futura(s) asignada(s). Reprográmalas o reasígnalas desde la agenda antes de suspender o cambiar este acceso.");
                }
            }
            (new EquipoRepository($this->db))->actualizar($actor, (int)$c['usuario_id'], $rol, $estado === 'activo', null, $estado);
            if ($pierdeAgenda) {
                $this->sql('UPDATE profesionales SET activo=0 WHERE colaborador_id=?', 'i', [$colaboradorId])->close();
            }
            if ($estado === 'activo' && $rol === 'profesional' && ($c['estado'] !== 'activo' || $c['rol'] !== 'profesional')) {
                $this->sql('UPDATE profesionales SET activo=1 WHERE colaborador_id=?', 'i', [$colaboradorId])->close();
            }
        });
    }
}

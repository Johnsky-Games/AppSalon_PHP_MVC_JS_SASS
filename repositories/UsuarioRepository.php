<?php

namespace Repositories;

use Model\ActiveRecord;
use Model\Usuario;
use mysqli;

/**
 * Repositorio para consultas y persistencia de usuarios (`usuarios`).
 * Utiliza exclusivamente sentencias preparadas y manejo explícito de errores SQL.
 */
class UsuarioRepository
{
    private ?mysqli $db;
    private bool $dbExplicitlyProvided = false;

    public function __construct(?mysqli $db = null)
    {
        if (func_num_args() > 0) {
            $this->db = $db;
            $this->dbExplicitlyProvided = true;
        } else {
            $this->db = null;
        }
    }

    /**
     * Obtiene la conexión activa a MySQL o lanza PersistenceException si no está disponible.
     */
    public function getDb(): mysqli
    {
        return $this->resolveDb();
    }

    /**
     * Resuelve la conexión activa a MySQL o lanza PersistenceException si no está disponible.
     */
    private function resolveDb(): mysqli
    {
        $db = $this->dbExplicitlyProvided ? $this->db : ($this->db ?? ActiveRecord::getDB());
        if (!$db instanceof mysqli) {
            throw new PersistenceException('No hay conexión activa a la base de datos.');
        }
        return $db;
    }

    /**
     * Busca un usuario por su identificador primario.
     *
     * @param int $id Identificador entero positivo.
     * @return Usuario|null Entidad Usuario si existe, o null si no existe.
     * @throws PersistenceException Si ocurre un fallo SQL.
     */
    public function findById(int $id): ?Usuario
    {
        if ($id <= 0) {
            return null;
        }

        $db = $this->resolveDb();

        try {
            $sql = "SELECT id, nombre, apellido, email, password, telefono, admin, confirmado, token, token_hash, token_tipo, token_expira
                    FROM usuarios
                    WHERE id = ?
                    LIMIT 1";
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException("Error al preparar búsqueda de usuario por ID: " . $db->error);
            }

            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar búsqueda de usuario por ID: " . $err);
            }

            $resultado = $stmt->get_result();
            if ($resultado === false) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al obtener resultado de usuario por ID: " . $err);
            }

            $fila = $resultado->fetch_assoc();
            $resultado->free();
            $stmt->close();

            if (!$fila) {
                return null;
            }

            return new Usuario($fila);
        } catch (PersistenceException $e) {
            error_log("[UsuarioRepository::findById] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[UsuarioRepository::findById] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al buscar usuario por ID.", 0, $e);
        }
    }

    /**
     * Busca un usuario por su dirección de correo electrónico.
     *
     * @param string $email Correo electrónico a consultar.
     * @return Usuario|null Entidad Usuario si existe, o null si no existe.
     * @throws PersistenceException Si ocurre un fallo SQL.
     */
    public function findByEmail(string $email): ?Usuario
    {
        $emailTrimmed = trim($email);
        if ($emailTrimmed === '') {
            return null;
        }

        $db = $this->resolveDb();

        try {
            $sql = "SELECT id, nombre, apellido, email, password, telefono, admin, confirmado, token, token_hash, token_tipo, token_expira
                    FROM usuarios
                    WHERE email = ?
                    LIMIT 1";
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException("Error al preparar búsqueda de usuario por email: " . $db->error);
            }

            $stmt->bind_param('s', $emailTrimmed);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar búsqueda de usuario por email: " . $err);
            }

            $resultado = $stmt->get_result();
            if ($resultado === false) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al obtener resultado de usuario por email: " . $err);
            }

            $fila = $resultado->fetch_assoc();
            $resultado->free();
            $stmt->close();

            if (!$fila) {
                return null;
            }

            return new Usuario($fila);
        } catch (PersistenceException $e) {
            error_log("[UsuarioRepository::findByEmail] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[UsuarioRepository::findByEmail] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al buscar usuario por email.", 0, $e);
        }
    }

    /**
     * Verifica si ya existe un usuario registrado con el correo electrónico indicado.
     * Distingue explícitamente un fallo SQL (lanza PersistenceException) de la no existencia (false).
     *
     * @param string $email Correo electrónico a verificar.
     * @return bool True si existe al menos un registro con ese email, false en caso contrario.
     * @throws PersistenceException Si ocurre un fallo SQL.
     */
    public function existsByEmail(string $email): bool
    {
        $emailTrimmed = trim($email);
        if ($emailTrimmed === '') {
            return false;
        }

        $db = $this->resolveDb();

        try {
            $stmt = $db->prepare("SELECT id FROM usuarios WHERE email = ? LIMIT 1");
            if (!$stmt) {
                throw new PersistenceException("Error al preparar verificación de email: " . $db->error);
            }

            $stmt->bind_param('s', $emailTrimmed);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar verificación de email: " . $err);
            }

            $resultado = $stmt->get_result();
            if ($resultado === false) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al obtener resultado de verificación de email: " . $err);
            }

            $existe = $resultado->num_rows > 0;
            $resultado->free();
            $stmt->close();

            return $existe;
        } catch (PersistenceException $e) {
            error_log("[UsuarioRepository::existsByEmail] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[UsuarioRepository::existsByEmail] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al verificar existencia de usuario.", 0, $e);
        }
    }

    /**
     * Inserta un nuevo usuario en la base de datos mediante sentencia preparada.
     * Nunca asigna un `id` externo; retorna el identificador autogenerado por MySQL.
     * No crea membresías: úsalo para identidades del equipo (ver EquipoRepository).
     *
     * @param Usuario $usuario Entidad Usuario a persistir.
     * @return int Identificador primario autogenerado.
     * @throws DuplicateEntryException Si el correo normalizado ya existe (restricción UNIQUE).
     * @throws PersistenceException Si ocurre un fallo SQL o la inserción no afecta exactamente 1 fila.
     */
    public function create(Usuario $usuario): int
    {
        $db = $this->resolveDb();

        try {
            return $this->insertarUsuario($db, $usuario);
        } catch (DuplicateEntryException $e) {
            throw $e;
        } catch (PersistenceException $e) {
            error_log("[UsuarioRepository::create] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[UsuarioRepository::create] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al crear usuario.", 0, $e);
        }
    }

    /**
     * Alta de cliente: identidad y membresía cliente en una sola transacción (anidable dentro de otra
     * operación atómica, p. ej. la reserva de idempotencia). Si algo falla no queda cuenta huérfana.
     *
     * @param string $origen 'registro_publico' | 'migracion' | 'recepcion'
     * @throws DuplicateEntryException Si el correo normalizado ya existe.
     * @throws PersistenceException Ante cualquier otro fallo de persistencia.
     */
    public function createCliente(Usuario $usuario, string $origen = 'registro_publico'): int
    {
        $db = $this->resolveDb();

        try {
            return \Classes\Transaccion::ejecutar($db, function (mysqli $db) use ($usuario, $origen): int {
                $id = $this->insertarUsuario($db, $usuario);
                $this->insertarMembresiaCliente($db, $id, $origen);
                return $id;
            });
        } catch (DuplicateEntryException $e) {
            throw $e;
        } catch (PersistenceException $e) {
            error_log("[UsuarioRepository::createCliente] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[UsuarioRepository::createCliente] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al crear cliente.", 0, $e);
        }
    }

    /**
     * Ejecuta `$trabajo` dentro de una transacción (anidable) sobre la conexión del repositorio.
     * Lo usa el registro público para que alta/aviso y evento de correo en cola se confirmen juntos.
     *
     * @template T
     * @param callable():T $trabajo
     * @return T
     */
    public function enTransaccion(callable $trabajo): mixed
    {
        return \Classes\Transaccion::ejecutar($this->resolveDb(), static fn(mysqli $db): mixed => $trabajo());
    }

    private function insertarUsuario(mysqli $db, Usuario $usuario): int
    {
        $sql = "INSERT INTO usuarios (nombre, apellido, email, password, telefono, admin, confirmado, token, token_hash, token_tipo, token_expira)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            throw new PersistenceException("Error al preparar inserción de usuario: " . $db->error);
        }

        $nombre = (string)($usuario->nombre ?? '');
        $apellido = (string)($usuario->apellido ?? '');
        $email = (string)($usuario->email ?? '');
        $password = (string)($usuario->password ?? '');
        $telefono = (string)($usuario->telefono ?? '');
        $admin = isset($usuario->admin) && $usuario->admin !== '' ? (string)$usuario->admin : '0';
        $confirmado = isset($usuario->confirmado) && $usuario->confirmado !== '' ? (string)$usuario->confirmado : '0';
        $token = $usuario->token !== null && $usuario->token !== '' ? (string)$usuario->token : null;
        $tokenHash = $usuario->token_hash !== null && $usuario->token_hash !== '' ? (string)$usuario->token_hash : null;
        $tokenTipo = $usuario->token_tipo !== null && $usuario->token_tipo !== '' ? (string)$usuario->token_tipo : null;
        $tokenExpira = $usuario->token_expira !== null && $usuario->token_expira !== '' ? (string)$usuario->token_expira : null;

        $stmt->bind_param('sssssssssss', $nombre, $apellido, $email, $password, $telefono, $admin, $confirmado, $token, $tokenHash, $tokenTipo, $tokenExpira);

        try {
            $ok = $stmt->execute();
            $errno = $ok ? 0 : (int)$stmt->errno;
            $err = $ok ? '' : $stmt->error;
        } catch (\mysqli_sql_exception $e) {
            $ok = false;
            $errno = (int)$e->getCode();
            $err = $e->getMessage();
        }
        if (!$ok) {
            $stmt->close();
            if ($errno === 1062) {
                throw new DuplicateEntryException("Correo ya registrado (restricción UNIQUE).");
            }
            throw new PersistenceException("Error al ejecutar inserción de usuario: " . $err);
        }

        $afectadas = $stmt->affected_rows;
        $insertId = (int)$db->insert_id;
        $stmt->close();

        if ($afectadas !== 1 || $insertId <= 0) {
            throw new PersistenceException("La inserción de usuario no afectó ninguna fila.");
        }

        $usuario->id = $insertId;
        return $insertId;
    }

    /**
     * Inserta la membresía cliente de una identidad dentro de la transacción del llamador.
     */
    private function insertarMembresiaCliente(mysqli $db, int $usuarioId, string $origen): void
    {
        if (!in_array($origen, ['registro_publico', 'migracion', 'recepcion'], true)) {
            throw new PersistenceException("Origen de cliente no válido.");
        }
        $stmt = $db->prepare("INSERT INTO clientes (usuario_id, estado, origen) VALUES (?, 'activo', ?)");
        if (!$stmt) {
            throw new PersistenceException("Error al preparar la membresía de cliente: " . $db->error);
        }
        $stmt->bind_param('is', $usuarioId, $origen);
        if (!$stmt->execute()) {
            $err = $stmt->error;
            $stmt->close();
            throw new PersistenceException("Error al crear la membresía de cliente: " . $err);
        }
        $stmt->close();
    }

    /**
     * Busca un usuario por token en texto plano validando su hash SHA-256, propósito (`token_tipo`)
     * y vigencia temporal (`token_expira >= UTC_TIMESTAMP()`).
     *
     * @param string $tokenRaw Token en texto plano recibido del usuario.
     * @param string $tipo Propósito esperado ('confirmacion' o 'recuperacion').
     * @return Usuario|null Usuario si el token existe, coincide en propósito y sigue vigente; null en caso contrario.
     * @throws PersistenceException Si ocurre un fallo SQL.
     */
    public function findByValidToken(string $tokenRaw, string $tipo): ?Usuario
    {
        $tokenTrimmed = trim($tokenRaw);
        if ($tokenTrimmed === '' || !in_array($tipo, ['confirmacion', 'recuperacion'], true)) {
            return null;
        }

        $db = $this->resolveDb();
        $hash = hash('sha256', $tokenTrimmed);

        try {
            $sql = "SELECT id, nombre, apellido, email, password, telefono, admin, confirmado, token, token_hash, token_tipo, token_expira
                    FROM usuarios
                    WHERE token_hash = ? AND token_tipo = ? AND token_expira >= UTC_TIMESTAMP()
                    LIMIT 1";
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException("Error al preparar búsqueda por token: " . $db->error);
            }

            $stmt->bind_param('ss', $hash, $tipo);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar búsqueda por token: " . $err);
            }

            $resultado = $stmt->get_result();
            if ($resultado === false) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al obtener resultado por token: " . $err);
            }

            $fila = $resultado->fetch_assoc();
            $resultado->free();
            $stmt->close();

            if (!$fila) {
                return null;
            }

            return new Usuario($fila);
        } catch (PersistenceException $e) {
            error_log("[UsuarioRepository::findByValidToken] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[UsuarioRepository::findByValidToken] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al validar token.", 0, $e);
        }
    }

    /**
     * ACTIVACIÓN de una cuenta (única vía de confirmación): en UNA sola sentencia UPDATE atómica consume el token de
     * confirmación, reemplaza los datos de contacto por los revisados por el titular Y reemplaza la contraseña por la elegida por quien demostró controlar el buzón. La contraseña del
     * registro es provisional y se descarta aquí. Condiciones (todas en el WHERE): hash del token, propósito 'confirmacion',
     * vigencia y cuenta aún sin confirmar; solo `affected_rows === 1` cuenta como éxito, de modo que el perdedor de dos
     * activaciones simultáneas no cambia la contraseña del ganador y un token residual nunca toca una cuenta confirmada.
     * También sube `version_sesion` (invalida sesiones previas) y deja sin token vigente a la cuenta (la columna es única:
     * cualquier token de recuperación o confirmación anterior desaparece). Dentro de la misma transacción del llamador
     * reinicia los contadores de intentos de esa cuenta (login, recuperación, reenvío).
     *
     * @param string $tokenRaw Token en texto plano.
     * @param string $nuevoPasswordHash Hash bcrypt de la contraseña elegida.
     * @param array{nombre:string,apellido:string,telefono:string} $contacto Datos revisados por el titular: reemplazan, en la MISMA
     *        sentencia, a los que escribió quien registró el correo antes de verificarlo (ya validados por el llamador). Declarados, no verificados.
     * @return array{id:int,email:string}|null Cuenta activada, o null si el token no era válido, venció, ya se usó o la cuenta ya estaba confirmada.
     * @throws PersistenceException Si ocurre un fallo SQL.
     */
    public function activateAccountByToken(string $tokenRaw, string $nuevoPasswordHash, array $contacto): ?array
    {
        $tokenTrimmed = trim($tokenRaw);
        if ($tokenTrimmed === '' || $nuevoPasswordHash === '') {
            return null;
        }

        $db = $this->resolveDb();
        $hash = hash('sha256', $tokenTrimmed);
        $tipo = 'confirmacion';

        try {
            // `id = LAST_INSERT_ID(id)` deja disponible el id de la fila actualizada sin una lectura posterior.
            $sql = "UPDATE usuarios
                    SET password = ?, nombre = ?, apellido = ?, telefono = ?, confirmado = '1', version_sesion = version_sesion + 1, id = LAST_INSERT_ID(id),
                        token = NULL, token_hash = NULL, token_tipo = NULL, token_expira = NULL
                    WHERE token_hash = ? AND token_tipo = ? AND token_expira >= UTC_TIMESTAMP() AND confirmado = '0'
                    LIMIT 1";
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException("Error al preparar activación por token: " . $db->error);
            }
            $nombre = (string)($contacto['nombre'] ?? '');
            $apellido = (string)($contacto['apellido'] ?? '');
            $telefono = (string)($contacto['telefono'] ?? '');
            $stmt->bind_param('ssssss', $nuevoPasswordHash, $nombre, $apellido, $telefono, $hash, $tipo);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar activación por token: " . $err);
            }
            $afectadas = $stmt->affected_rows;
            $stmt->close();
            if ($afectadas !== 1) {
                return null;
            }

            $id = (int)$db->insert_id;
            $sel = $db->prepare('SELECT email FROM usuarios WHERE id = ?');
            if (!$sel) {
                throw new PersistenceException("Error al preparar lectura tras la activación: " . $db->error);
            }
            $sel->bind_param('i', $id);
            $sel->execute();
            $fila = $sel->get_result()->fetch_assoc();
            $sel->close();
            $email = (string)($fila['email'] ?? '');

            // Contadores de la cuenta recién activada (no los de IP, que son presupuesto compartido).
            $del = $db->prepare("DELETE FROM intentos_login WHERE tipo IN ('email_login','email_recovery','email_reconfirm') AND LOWER(identificador) = LOWER(?)");
            if ($del) {
                $del->bind_param('s', $email);
                $del->execute();
                $del->close();
            }
            return ['id' => $id, 'email' => $email];
        } catch (PersistenceException $e) {
            error_log("[UsuarioRepository::activateAccountByToken] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[UsuarioRepository::activateAccountByToken] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al activar cuenta.", 0, $e);
        }
    }

    /**
     * Consume atómicamente un token de recuperación válido y vigente, actualizando únicamente `password`
     * y limpiando las columnas de token en una única sentencia UPDATE con verificación de `affected_rows === 1`.
     *
     * @param string $tokenRaw Token de recuperación en texto plano.
     * @param string $nuevoPasswordHash Hash bcrypt de la nueva contraseña.
     * @return bool True si el token era válido y la contraseña se actualizó; false si el token era inválido/expirado/usado.
     * @throws PersistenceException Si ocurre un fallo SQL.
     */
    public function resetPasswordByToken(string $tokenRaw, string $nuevoPasswordHash): bool
    {
        $tokenTrimmed = trim($tokenRaw);
        if ($tokenTrimmed === '' || $nuevoPasswordHash === '') {
            return false;
        }

        $db = $this->resolveDb();
        $hash = hash('sha256', $tokenTrimmed);
        $tipo = 'recuperacion';

        try {
            $sql = "UPDATE usuarios
                    SET password = ?, version_sesion = version_sesion + 1, token = NULL, token_hash = NULL, token_tipo = NULL, token_expira = NULL
                    WHERE token_hash = ? AND token_tipo = ? AND token_expira >= UTC_TIMESTAMP() AND confirmado = '1'
                    LIMIT 1";
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException("Error al preparar restablecimiento de password por token: " . $db->error);
            }

            $stmt->bind_param('sss', $nuevoPasswordHash, $hash, $tipo);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar restablecimiento de password por token: " . $err);
            }

            $afectadas = $stmt->affected_rows;
            $stmt->close();

            return $afectadas === 1;
        } catch (PersistenceException $e) {
            error_log("[UsuarioRepository::resetPasswordByToken] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            error_log("[UsuarioRepository::resetPasswordByToken] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al restablecer contraseña.", 0, $e);
        }
    }

    /**
     * Emite y persiste atómicamente un nuevo token de recuperación para un usuario confirmado (`confirmado = '1'`).
     * Actualiza exclusivamente las columnas de token sin sobrescribir `password`, `admin`, `confirmado` ni otros campos.
     *
     * @param Usuario $usuario Entidad Usuario con id válido.
     * @param int $horasExpiracion Horas de validez del token (por defecto 2 horas).
     * @return string|null Token en texto plano si se persistió (`affected_rows === 1`), o null si la condición de estado no se cumplió.
     * @throws PersistenceException Si ocurre un fallo SQL.
     */
    public function issueRecoveryToken(Usuario $usuario, int $horasExpiracion = 2): ?string
    {
        $id = filter_var($usuario->id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            return null;
        }

        $db = $this->resolveDb();

        $prevToken = $usuario->token;
        $prevHash = $usuario->token_hash;
        $prevTipo = $usuario->token_tipo;
        $prevExpira = $usuario->token_expira;

        $tokenPlano = $usuario->generarTokenSeguro('recuperacion', $horasExpiracion);

        try {
            $sql = "UPDATE usuarios
                    SET token = NULL, token_hash = ?, token_tipo = ?, token_expira = ?
                    WHERE id = ? AND confirmado = '1'
                    LIMIT 1";
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException("Error al preparar emisión de token de recuperación: " . $db->error);
            }

            $hash = (string)$usuario->token_hash;
            $tipo = (string)$usuario->token_tipo;
            $expira = (string)$usuario->token_expira;

            $stmt->bind_param('sssi', $hash, $tipo, $expira, $id);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar emisión de token de recuperación: " . $err);
            }

            $afectadas = $stmt->affected_rows;
            $stmt->close();

            if ($afectadas !== 1) {
                $usuario->token = $prevToken;
                $usuario->token_hash = $prevHash;
                $usuario->token_tipo = $prevTipo;
                $usuario->token_expira = $prevExpira;
                return null;
            }

            return $tokenPlano;
        } catch (PersistenceException $e) {
            $usuario->token = $prevToken;
            $usuario->token_hash = $prevHash;
            $usuario->token_tipo = $prevTipo;
            $usuario->token_expira = $prevExpira;
            error_log("[UsuarioRepository::issueRecoveryToken] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            $usuario->token = $prevToken;
            $usuario->token_hash = $prevHash;
            $usuario->token_tipo = $prevTipo;
            $usuario->token_expira = $prevExpira;
            error_log("[UsuarioRepository::issueRecoveryToken] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al emitir token de recuperación.", 0, $e);
        }
    }

    /**
     * Emite y persiste atómicamente un nuevo token de confirmación para un usuario aún no confirmado (`confirmado = '0'`).
     * Actualiza exclusivamente las columnas de token sin sobrescribir `confirmado = '1'` si la cuenta se confirmó concurrentemente,
     * ni alterar `password` o `admin`.
     *
     * @param Usuario $usuario Entidad Usuario con id válido.
     * @param int $horasExpiracion Horas de validez del token (por defecto 24 horas).
     * @return string|null Token en texto plano si se persistió (`affected_rows === 1`), o null si la cuenta ya fue confirmada o no existe.
     * @throws PersistenceException Si ocurre un fallo SQL.
     */
    public function issueConfirmationToken(Usuario $usuario, int $horasExpiracion = 24): ?string
    {
        $id = filter_var($usuario->id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            return null;
        }

        $db = $this->resolveDb();

        $prevToken = $usuario->token;
        $prevHash = $usuario->token_hash;
        $prevTipo = $usuario->token_tipo;
        $prevExpira = $usuario->token_expira;

        $tokenPlano = $usuario->generarTokenSeguro('confirmacion', $horasExpiracion);

        try {
            $sql = "UPDATE usuarios
                    SET token = NULL, token_hash = ?, token_tipo = ?, token_expira = ?
                    WHERE id = ? AND confirmado = '0'
                    LIMIT 1";
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new PersistenceException("Error al preparar emisión de token de confirmación: " . $db->error);
            }

            $hash = (string)$usuario->token_hash;
            $tipo = (string)$usuario->token_tipo;
            $expira = (string)$usuario->token_expira;

            $stmt->bind_param('sssi', $hash, $tipo, $expira, $id);
            if (!$stmt->execute()) {
                $err = $stmt->error;
                $stmt->close();
                throw new PersistenceException("Error al ejecutar emisión de token de confirmación: " . $err);
            }

            $afectadas = $stmt->affected_rows;
            $stmt->close();

            if ($afectadas !== 1) {
                $usuario->token = $prevToken;
                $usuario->token_hash = $prevHash;
                $usuario->token_tipo = $prevTipo;
                $usuario->token_expira = $prevExpira;
                return null;
            }

            return $tokenPlano;
        } catch (PersistenceException $e) {
            $usuario->token = $prevToken;
            $usuario->token_hash = $prevHash;
            $usuario->token_tipo = $prevTipo;
            $usuario->token_expira = $prevExpira;
            error_log("[UsuarioRepository::issueConfirmationToken] " . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            $usuario->token = $prevToken;
            $usuario->token_hash = $prevHash;
            $usuario->token_tipo = $prevTipo;
            $usuario->token_expira = $prevExpira;
            error_log("[UsuarioRepository::issueConfirmationToken] Excepción inesperada: " . $e->getMessage());
            throw new PersistenceException("Fallo en la capa de persistencia al emitir token de confirmación.", 0, $e);
        }
    }
}

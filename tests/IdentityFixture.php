<?php
namespace Tests;

/** Explicit test fixture. Never loaded by application routes or used as an authorization bypass. */
function ensureActiveIdentity(int $id, bool $admin = false): void
{
    $db = \Model\ActiveRecord::getDB();
    if (!$db instanceof \mysqli) { throw new \RuntimeException('A real isolated test database is required for the session fixture.'); }
    \validar_base_datos_prueba($db);
    $hash = password_hash('FixtureSeguro123!', PASSWORD_BCRYPT);
    $email = 'identity-' . $id . '@fixture.test';
    $flag = (int)$admin;
    $stmt = $db->prepare("INSERT INTO usuarios(id,nombre,apellido,email,password,telefono,admin,confirmado) VALUES (?,'Identity','Fixture',?,?,'0991234567',?,1) ON DUPLICATE KEY UPDATE admin=VALUES(admin),confirmado=1");
    $stmt->bind_param('issi', $id, $email, $hash, $flag); $stmt->execute(); $stmt->close();
    $rol = $admin ? 'propietario' : 'cliente';
    $stmt = $db->prepare('INSERT INTO usuario_accesos(usuario_id,rol,activo) VALUES (?,?,1) ON DUPLICATE KEY UPDATE rol=VALUES(rol),activo=1');
    $stmt->bind_param('is', $id, $rol); $stmt->execute(); $stmt->close();
    $_SESSION['version_sesion'] = (int)$db->query("SELECT version_sesion FROM usuarios WHERE id={$id}")->fetch_assoc()['version_sesion'];
}

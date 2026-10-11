<?php

declare(strict_types=1);

// Fixtures exclusivamente para la base efímera creada por run_browser_e2e.sh.
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' || getenv('DB_NAME') !== 'appsalon_e2e_test') {
    fwrite(STDERR, "Fixtures permitidas únicamente en appsalon_e2e_test y APP_ENV=testing.\n");
    exit(1);
}

require __DIR__ . '/../includes/app.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if (!$db instanceof mysqli) {
    throw new RuntimeException('No se pudo conectar a la base de fixtures E2E.');
}

$password = password_hash('Password123!', PASSWORD_BCRYPT);
$db->begin_transaction();
try {
    $db->query("INSERT INTO servicios (id, nombre, precio) VALUES
        (1, 'Corte de Cabello Hombre', 80.00),
        (2, 'Corte de Cabello Mujer', 120.00),
        (3, 'Corte de Barba', 60.00)");
    $stmt = $db->prepare('INSERT INTO usuarios (id, nombre, apellido, email, password, telefono, admin, confirmado)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1)');
    foreach ([
        [1, 'Carlos', 'Mendoza', 'carlos@correo.com', '1234567890', 0],
        [2, 'Admin', 'Salon', 'admin@appsalon.com', '0987654321', 1],
    ] as [$id, $nombre, $apellido, $email, $telefono, $admin]) {
        $stmt->bind_param('isssssi', $id, $nombre, $apellido, $email, $password, $telefono, $admin);
        $stmt->execute();
    }
    $stmt->close();
    $db->commit();
    echo "Fixtures E2E creadas en base aislada.\n";
} catch (Throwable $error) {
    $db->rollback();
    throw $error;
}

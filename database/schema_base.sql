-- Esquema base inicial de AppSalon

CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(60) NOT NULL,
    apellido VARCHAR(60) NOT NULL,
    email VARCHAR(60) NOT NULL UNIQUE,
    password VARCHAR(60) NOT NULL,
    telefono VARCHAR(10) NULL,
    admin TINYINT(1) DEFAULT 0,
    confirmado TINYINT(1) DEFAULT 0,
    token VARCHAR(64) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS servicios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(60) NOT NULL,
    precio DECIMAL(6,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS citas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fecha DATE NOT NULL,
    hora TIME NOT NULL,
    usuarioId INT NOT NULL,
    INDEX idx_usuario (usuarioId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS citasservicios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    citaId INT NOT NULL,
    servicioId INT NOT NULL,
    INDEX idx_cita (citaId),
    INDEX idx_servicio (servicioId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

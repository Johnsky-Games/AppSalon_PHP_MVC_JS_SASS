CREATE TABLE IF NOT EXISTS negocio_ajustes (
 clave VARCHAR(60) PRIMARY KEY, valor VARCHAR(500) NOT NULL, actualizado_por INT NULL,
 actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS lista_espera (
 id INT AUTO_INCREMENT PRIMARY KEY, usuario_id INT NOT NULL, servicio_id INT NOT NULL, profesional_id INT NULL,
 fecha DATE NOT NULL, estado VARCHAR(20) NOT NULL DEFAULT 'pendiente', creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_espera_cliente (usuario_id, servicio_id, fecha), INDEX idx_espera_fecha (estado, fecha),
 FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
 FOREIGN KEY (servicio_id) REFERENCES servicios(id) ON DELETE CASCADE,
 FOREIGN KEY (profesional_id) REFERENCES profesionales(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS fidelidad_movimientos (
 id INT AUTO_INCREMENT PRIMARY KEY, usuario_id INT NOT NULL, cita_id INT NULL, puntos INT NOT NULL,
 tipo VARCHAR(20) NOT NULL, beneficio_snapshot VARCHAR(300) NULL, canje_estado VARCHAR(20) NOT NULL DEFAULT 'pendiente', atendido_por INT NULL, atendido_en DATETIME NULL, referencia VARCHAR(100) NOT NULL UNIQUE, creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
 FOREIGN KEY (cita_id) REFERENCES citas(id) ON DELETE SET NULL, INDEX idx_fidelidad_cliente (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS formularios_cliente (
 id INT AUTO_INCREMENT PRIMARY KEY, titulo VARCHAR(120) NOT NULL, preguntas JSON NOT NULL, activo TINYINT NOT NULL DEFAULT 1,
 creado_por INT NOT NULL, creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS formulario_respuestas (
 id INT AUTO_INCREMENT PRIMARY KEY, formulario_id INT NOT NULL, usuario_id INT NOT NULL, respuestas JSON NOT NULL,
 actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_respuesta_cliente (formulario_id, usuario_id),
 FOREIGN KEY (formulario_id) REFERENCES formularios_cliente(id) ON DELETE CASCADE,
 FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

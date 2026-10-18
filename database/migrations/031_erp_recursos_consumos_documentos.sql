CREATE TABLE IF NOT EXISTS erp_recursos (
 id INT AUTO_INCREMENT PRIMARY KEY,
 nombre VARCHAR(120) NOT NULL,
 tipo VARCHAR(40) NOT NULL,
 activo TINYINT NOT NULL DEFAULT 1,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 INDEX idx_erp_recurso_tipo(tipo,activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS erp_servicio_recursos (
 id INT AUTO_INCREMENT PRIMARY KEY,
 servicio_id INT NOT NULL,
 tipo VARCHAR(40) NOT NULL,
 cantidad INT NOT NULL,
 desde DATE NOT NULL,
 UNIQUE KEY uq_erp_servicio_tipo_fecha(servicio_id,tipo,desde),
 CONSTRAINT fk_erp_req_servicio FOREIGN KEY(servicio_id) REFERENCES servicios(id),
 CONSTRAINT ck_erp_req_qty CHECK(cantidad BETWEEN 0 AND 20)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS erp_recurso_reservas (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 cita_id INT NOT NULL,
 recurso_id INT NOT NULL,
 fecha DATE NOT NULL,
 inicio TIME NOT NULL,
 fin TIME NOT NULL,
 UNIQUE KEY uq_erp_recurso_cita(cita_id,recurso_id),
 INDEX idx_erp_recurso_fecha(recurso_id,fecha,inicio,fin),
 CONSTRAINT fk_erp_reserva_cita FOREIGN KEY(cita_id) REFERENCES citas(id) ON DELETE CASCADE,
 CONSTRAINT fk_erp_reserva_recurso FOREIGN KEY(recurso_id) REFERENCES erp_recursos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS erp_mantenimientos (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 recurso_id INT NOT NULL,
 desde DATE NOT NULL,
 hasta DATE NOT NULL,
 motivo VARCHAR(300) NOT NULL,
 estado ENUM('programado','completado') NOT NULL DEFAULT 'programado',
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 completado_en DATETIME NULL,
 CONSTRAINT fk_erp_mant_recurso FOREIGN KEY(recurso_id) REFERENCES erp_recursos(id),
 CONSTRAINT ck_erp_mant_rango CHECK(hasta>=desde)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS erp_activos (
 id INT AUTO_INCREMENT PRIMARY KEY,
 recurso_id INT NULL,
 nombre VARCHAR(120) NOT NULL,
 codigo VARCHAR(60) NOT NULL UNIQUE,
 referencia_compra VARCHAR(180) NOT NULL,
 costo_centavos BIGINT NOT NULL,
 adquirido_en DATE NOT NULL,
 responsable_id INT NULL,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 CONSTRAINT fk_erp_activo_recurso FOREIGN KEY(recurso_id) REFERENCES erp_recursos(id),
 CONSTRAINT fk_erp_activo_responsable FOREIGN KEY(responsable_id) REFERENCES colaboradores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS erp_recetas (
 servicio_id INT NOT NULL,
 producto_id INT NOT NULL,
 cantidad_milesimas BIGINT NOT NULL,
 actualizado_por INT NOT NULL,
 PRIMARY KEY(servicio_id,producto_id),
 CONSTRAINT fk_erp_receta_servicio FOREIGN KEY(servicio_id) REFERENCES servicios(id),
 CONSTRAINT fk_erp_receta_producto FOREIGN KEY(producto_id) REFERENCES inventario_productos(id),
 CONSTRAINT ck_erp_receta_cantidad CHECK(cantidad_milesimas>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS erp_consumos (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 cita_id INT NOT NULL UNIQUE,
 actor_id INT NOT NULL,
 motivo VARCHAR(300) NOT NULL,
 creado_en DATETIME NOT NULL,
 CONSTRAINT fk_erp_consumo_cita FOREIGN KEY(cita_id) REFERENCES citas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS erp_documentos (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 entidad ENUM('colaborador','venta','compra','activo') NOT NULL,
 entidad_id BIGINT NOT NULL,
 titulo VARCHAR(180) NOT NULL,
 nombre_archivo VARCHAR(120) NOT NULL,
 mime VARCHAR(80) NOT NULL,
 bytes INT NOT NULL,
 contenido_cifrado LONGTEXT NOT NULL,
 contenido_sha256 CHAR(64) NOT NULL,
 visibilidad ENUM('privado','titular') NOT NULL DEFAULT 'privado',
 reemplaza_id BIGINT NULL,
 vence_en DATE NULL,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 INDEX idx_erp_documento_objeto(entidad,entidad_id,id),
 CONSTRAINT fk_erp_documento_version FOREIGN KEY(reemplaza_id) REFERENCES erp_documentos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DROP TRIGGER IF EXISTS trg_erp_documento_no_update;
CREATE TRIGGER trg_erp_documento_no_update BEFORE UPDATE ON erp_documentos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El documento conserva sus versiones';
DROP TRIGGER IF EXISTS trg_erp_documento_no_delete;
CREATE TRIGGER trg_erp_documento_no_delete BEFORE DELETE ON erp_documentos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El documento conserva su evidencia';

CREATE TABLE IF NOT EXISTS erp_compras (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 proveedor_id INT NOT NULL,
 estado ENUM('borrador','ordenada','cerrada','anulada') NOT NULL DEFAULT 'borrador',
 total_centavos BIGINT NOT NULL DEFAULT 0,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 INDEX idx_erp_compras_fecha(creado_en,id),
 CONSTRAINT fk_erp_compra_proveedor FOREIGN KEY(proveedor_id) REFERENCES erp_contactos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS erp_compra_lineas (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 compra_id BIGINT NOT NULL,
 producto_id INT NOT NULL,
 cantidad_milesimas BIGINT NOT NULL,
 recibido_milesimas BIGINT NOT NULL DEFAULT 0,
 devuelto_milesimas BIGINT NOT NULL DEFAULT 0,
 costo_centavos BIGINT NOT NULL,
 impuesto_pb INT NOT NULL,
 total_centavos BIGINT NOT NULL,
 CONSTRAINT fk_erp_compra_linea FOREIGN KEY(compra_id) REFERENCES erp_compras(id),
 CONSTRAINT fk_erp_compra_producto FOREIGN KEY(producto_id) REFERENCES inventario_productos(id),
 CONSTRAINT ck_erp_compra_qty CHECK(cantidad_milesimas>0 AND recibido_milesimas>=0 AND recibido_milesimas<=cantidad_milesimas AND devuelto_milesimas>=0 AND devuelto_milesimas<=recibido_milesimas)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS erp_obligaciones (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 compra_id BIGINT NULL,
 contacto_id INT NULL,
 colaborador_id INT NULL,
 origen VARCHAR(40) NOT NULL,
 referencia VARCHAR(120) NOT NULL,
 monto_centavos BIGINT NOT NULL,
 pagado_centavos BIGINT NOT NULL DEFAULT 0,
 ajuste_centavos BIGINT NOT NULL DEFAULT 0,
 vence_en DATE NOT NULL,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 UNIQUE KEY uq_erp_obligacion_doc(contacto_id,origen,referencia),
 INDEX idx_erp_obligacion_vencimiento(vence_en,id),
 CONSTRAINT fk_erp_obligacion_compra FOREIGN KEY(compra_id) REFERENCES erp_compras(id),
 CONSTRAINT fk_erp_obligacion_contacto FOREIGN KEY(contacto_id) REFERENCES erp_contactos(id),
 CONSTRAINT fk_erp_obligacion_colaborador FOREIGN KEY(colaborador_id) REFERENCES colaboradores(id),
 CONSTRAINT ck_erp_obligacion_monto CHECK(monto_centavos>0 AND pagado_centavos>=0 AND ajuste_centavos>=0 AND ajuste_centavos<=monto_centavos)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS erp_obligacion_pagos (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 obligacion_id BIGINT NOT NULL,
 caja_id BIGINT NOT NULL UNIQUE,
 monto_centavos BIGINT NOT NULL,
 referencia VARCHAR(120) NOT NULL,
 creado_en DATETIME NOT NULL,
 CONSTRAINT fk_erp_obligacion_pago FOREIGN KEY(obligacion_id) REFERENCES erp_obligaciones(id),
 CONSTRAINT fk_erp_obligacion_caja FOREIGN KEY(caja_id) REFERENCES caja_movimientos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS erp_proveedor_creditos (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 obligacion_id BIGINT NOT NULL,
 referencia VARCHAR(120) NOT NULL,
 monto_centavos BIGINT NOT NULL,
 motivo VARCHAR(300) NOT NULL,
 actor_id INT NOT NULL,
 creado_en DATETIME NOT NULL,
 UNIQUE KEY uq_erp_proveedor_credito(obligacion_id,referencia),
 CONSTRAINT fk_erp_proveedor_credito FOREIGN KEY(obligacion_id) REFERENCES erp_obligaciones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS erp_proveedor_reembolsos (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 obligacion_id BIGINT NOT NULL,
 caja_id BIGINT NOT NULL UNIQUE,
 monto_centavos BIGINT NOT NULL,
 referencia VARCHAR(120) NOT NULL,
 creado_en DATETIME NOT NULL,
 CONSTRAINT fk_erp_proveedor_reembolso FOREIGN KEY(obligacion_id) REFERENCES erp_obligaciones(id),
 CONSTRAINT fk_erp_proveedor_reembolso_caja FOREIGN KEY(caja_id) REFERENCES caja_movimientos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS erp_contratos (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 colaborador_id INT NOT NULL,
 tipo ENUM('empleado','prestador') NOT NULL,
 inicio DATE NOT NULL,
 fin DATE NULL,
 base_centavos BIGINT NOT NULL,
 observacion VARCHAR(500) NOT NULL,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 CONSTRAINT fk_erp_contrato_colaborador FOREIGN KEY(colaborador_id) REFERENCES colaboradores(id),
 CONSTRAINT ck_erp_contrato_fechas CHECK(fin IS NULL OR fin>=inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS erp_asistencia (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 colaborador_id INT NOT NULL,
 entrada DATETIME NOT NULL,
 salida DATETIME NOT NULL,
 descanso_minutos INT NOT NULL DEFAULT 0,
 anulado_en DATETIME NULL,
 motivo VARCHAR(300) NOT NULL,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 INDEX idx_erp_asistencia_colaborador(colaborador_id,entrada),
 CONSTRAINT fk_erp_asistencia_colaborador FOREIGN KEY(colaborador_id) REFERENCES colaboradores(id),
 CONSTRAINT ck_erp_asistencia CHECK(salida>entrada AND descanso_minutos>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS erp_jornadas (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 colaborador_id INT NOT NULL,
 dia_semana INT NOT NULL,
 inicio TIME NOT NULL,
 fin TIME NOT NULL,
 actualizado_por INT NOT NULL,
 UNIQUE KEY uq_erp_jornada_dia(colaborador_id,dia_semana),
 CONSTRAINT fk_erp_jornada_persona FOREIGN KEY(colaborador_id) REFERENCES colaboradores(id),
 CONSTRAINT ck_erp_jornada CHECK(dia_semana BETWEEN 1 AND 7 AND fin>inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS erp_ausencias (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 colaborador_id INT NOT NULL,
 desde DATE NOT NULL,
 hasta DATE NOT NULL,
 motivo VARCHAR(300) NOT NULL,
 estado ENUM('solicitada','aprobada','rechazada','anulada') NOT NULL DEFAULT 'solicitada',
 solicitante_id INT NOT NULL,
 resuelto_por INT NULL,
 bloqueo_id INT NULL,
 creado_en DATETIME NOT NULL,
 resuelto_en DATETIME NULL,
 CONSTRAINT fk_erp_ausencia_colaborador FOREIGN KEY(colaborador_id) REFERENCES colaboradores(id),
 CONSTRAINT ck_erp_ausencia_fechas CHECK(hasta>=desde)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS erp_solicitudes (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 tipo ENUM('descuento','gasto','ajuste_stock') NOT NULL,
 datos_json JSON NOT NULL,
 motivo VARCHAR(300) NOT NULL,
 estado ENUM('solicitada','aprobada','rechazada') NOT NULL DEFAULT 'solicitada',
 solicitante_id INT NOT NULL,
 resuelto_por INT NULL,
 nota VARCHAR(300) NULL,
 creado_en DATETIME NOT NULL,
 resuelto_en DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_cotizaciones (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 venta_id BIGINT NOT NULL,
 version_venta INT NOT NULL,
 vence_en DATE NOT NULL,
 estado ENUM('vigente','aceptada','reemplazada') NOT NULL DEFAULT 'vigente',
 contenido_json LONGTEXT NOT NULL,
 contenido_sha256 CHAR(64) NOT NULL,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 aceptado_en DATETIME NULL,
 INDEX idx_erp_cotizacion_venta(venta_id,id),
 CONSTRAINT fk_erp_cotizacion_venta FOREIGN KEY(venta_id) REFERENCES erp_ventas(id),
 CONSTRAINT ck_erp_cotizacion_json CHECK(JSON_VALID(contenido_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

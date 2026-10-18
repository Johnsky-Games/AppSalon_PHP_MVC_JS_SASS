-- Desglose tributario pactado al reservar. No se retrocarga usando el catálogo de hoy.
CREATE TABLE IF NOT EXISTS erp_cita_tarifas (
 cita_servicio_id INT PRIMARY KEY,
 precio_centavos BIGINT NOT NULL,
 impuesto_pb INT NOT NULL,
 impuesto_centavos BIGINT NOT NULL,
 total_centavos BIGINT NOT NULL,
 CONSTRAINT fk_erp_tarifa_cita FOREIGN KEY(cita_servicio_id) REFERENCES citasservicios(id) ON DELETE CASCADE,
 CONSTRAINT ck_erp_tarifa_importes CHECK(precio_centavos>=0 AND impuesto_centavos>=0 AND total_centavos=precio_centavos+impuesto_centavos AND impuesto_pb BETWEEN 0 AND 10000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

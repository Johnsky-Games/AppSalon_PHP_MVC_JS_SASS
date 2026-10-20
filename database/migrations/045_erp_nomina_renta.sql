-- Retención del impuesto a la renta de relación de dependencia. Aditiva sobre 044. Sin cifras legales de fábrica: la tabla progresiva de cada año y el tope de gastos
-- personales los registra una persona autorizada con su fuente oficial; sin tabla vigente, la retención sigue siendo una novedad manual (comportamiento de la 044).
CREATE TABLE IF NOT EXISTS erp_nomina_ir_tablas (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 anio SMALLINT NOT NULL,
 vigente_desde DATE NOT NULL,
 estado ENUM('vigente','derogado') NOT NULL DEFAULT 'vigente',
 tramos_json JSON NULL,
 tope_gastos_centavos BIGINT NULL,
 fuente VARCHAR(200) NOT NULL,
 fuente_url VARCHAR(300) NULL,
 consultado_en DATE NOT NULL,
 nota VARCHAR(300) NULL,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 UNIQUE KEY uq_nomina_ir_tabla (anio,vigente_desde),
 CONSTRAINT ck_nomina_ir_tabla CHECK(anio BETWEEN 2000 AND 2100 AND ((estado='derogado' AND tramos_json IS NULL AND tope_gastos_centavos IS NULL) OR (estado='vigente' AND tramos_json IS NOT NULL)) AND (tope_gastos_centavos IS NULL OR tope_gastos_centavos>=0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DROP TRIGGER IF EXISTS trg_nomina_ir_tabla_no_update;
CREATE TRIGGER trg_nomina_ir_tabla_no_update BEFORE UPDATE ON erp_nomina_ir_tablas FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Registra una versión nueva de la tabla de impuesto a la renta';
DROP TRIGGER IF EXISTS trg_nomina_ir_tabla_no_delete;
CREATE TRIGGER trg_nomina_ir_tabla_no_delete BEFORE DELETE ON erp_nomina_ir_tablas FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Conserve el historial de tablas de impuesto a la renta';

-- Declaración anual del trabajador para la proyección (gastos, deducciones, rebaja y lo percibido/retenido en otros empleadores). La última del año rige; las anteriores se conservan.
CREATE TABLE IF NOT EXISTS erp_nomina_ir_declaraciones (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 colaborador_id INT NOT NULL,
 anio SMALLINT NOT NULL,
 gastos_centavos BIGINT NOT NULL DEFAULT 0,
 otras_deducciones_centavos BIGINT NOT NULL DEFAULT 0,
 rebaja_centavos BIGINT NOT NULL DEFAULT 0,
 otros_ingresos_centavos BIGINT NOT NULL DEFAULT 0,
 retenido_otros_centavos BIGINT NOT NULL DEFAULT 0,
 fuente VARCHAR(200) NOT NULL,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 INDEX idx_nomina_ir_decl (colaborador_id,anio,id),
 CONSTRAINT fk_nomina_ir_decl_colaborador FOREIGN KEY (colaborador_id) REFERENCES colaboradores(id),
 CONSTRAINT ck_nomina_ir_decl CHECK(anio BETWEEN 2000 AND 2100 AND gastos_centavos BETWEEN 0 AND 99999999999 AND otras_deducciones_centavos BETWEEN 0 AND 99999999999 AND rebaja_centavos BETWEEN 0 AND 99999999999 AND otros_ingresos_centavos BETWEEN 0 AND 99999999999 AND retenido_otros_centavos BETWEEN 0 AND 99999999999)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DROP TRIGGER IF EXISTS trg_nomina_ir_decl_no_update;
CREATE TRIGGER trg_nomina_ir_decl_no_update BEFORE UPDATE ON erp_nomina_ir_declaraciones FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Registra una declaración nueva; las anteriores se conservan';
DROP TRIGGER IF EXISTS trg_nomina_ir_decl_no_delete;
CREATE TRIGGER trg_nomina_ir_decl_no_delete BEFORE DELETE ON erp_nomina_ir_declaraciones FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Conserve el historial de declaraciones de impuesto a la renta';

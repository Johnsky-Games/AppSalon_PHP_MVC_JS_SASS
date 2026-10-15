-- 025: comprobantes internos de cobro y de devolución (ronda 5). NO son factura electrónica ni autorizada por el SRI.
--
--  · comprobantes_secuencia: contador correlativo por serie. Se incrementa DENTRO de la transacción que emite el comprobante
--    (con la fila bloqueada FOR UPDATE): si la transacción se revierte, el incremento también, de modo que no quedan huecos
--    ni duplicados aunque dos cobros ocurran a la vez. Un disparador impide saltarse números, retroceder o borrar la fila.
--  · comprobantes: SOLO ANEXADO (disparadores BEFORE UPDATE/DELETE). Guarda la instantánea completa del cobro (cliente,
--    profesional, servicios con precios históricos, pagos, devoluciones y totales) y su SHA-256: lo que se imprime es lo que se
--    emitió, aunque luego cambie el catálogo, el nombre del cliente o el estado de la cita.
-- Idempotente. Compatible con MySQL 8.0.13+.

CREATE TABLE IF NOT EXISTS comprobantes_secuencia (
 serie VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 ultimo BIGINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO comprobantes_secuencia (serie, ultimo) VALUES ('CI', 0);

DROP TRIGGER IF EXISTS trg_comp_sec_correlativo;
CREATE TRIGGER trg_comp_sec_correlativo BEFORE UPDATE ON comprobantes_secuencia FOR EACH ROW
 IF NEW.ultimo <> OLD.ultimo + 1 OR NEW.serie <> OLD.serie THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'comprobantes_secuencia solo avanza de uno en uno'; END IF;
DROP TRIGGER IF EXISTS trg_comp_sec_no_delete;
CREATE TRIGGER trg_comp_sec_no_delete BEFORE DELETE ON comprobantes_secuencia FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'comprobantes_secuencia no se elimina';

CREATE TABLE IF NOT EXISTS comprobantes (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 serie VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 numero BIGINT NOT NULL,
 codigo VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'identificador legible y único, p. ej. CI-000123',
 tipo VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'cobro | devolucion',
 cita_id INT NOT NULL,
 pago_id INT NULL,
 devolucion_id BIGINT NULL,
 emitido_por INT NULL,
 emitido_en DATETIME NOT NULL COMMENT 'America/Guayaquil',
 total_centavos BIGINT NOT NULL,
 cobrado_centavos BIGINT NOT NULL,
 devuelto_centavos BIGINT NOT NULL,
 saldo_centavos BIGINT NOT NULL,
 contenido_json JSON NOT NULL,
 contenido_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 UNIQUE KEY uq_comprobantes_codigo (codigo),
 UNIQUE KEY uq_comprobantes_serie_numero (serie, numero),
 INDEX idx_comprobantes_cita (cita_id, id),
 INDEX idx_comprobantes_pago (pago_id),
 CONSTRAINT ck_comprobantes_tipo CHECK (tipo IN ('cobro','devolucion'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_comprobantes_no_update;
CREATE TRIGGER trg_comprobantes_no_update BEFORE UPDATE ON comprobantes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'comprobantes es de solo anexado';
DROP TRIGGER IF EXISTS trg_comprobantes_no_delete;
CREATE TRIGGER trg_comprobantes_no_delete BEFORE DELETE ON comprobantes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'comprobantes es de solo anexado';

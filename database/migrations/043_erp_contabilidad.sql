-- Contabilidad propia: partida doble inmutable, períodos con cierre, roles de cuenta y un plan base editable.
-- Un asiento nunca se edita ni se borra: se corrige con un reverso (otro asiento). Cada hecho económico tiene una identidad permanente (origen único).
CREATE TABLE IF NOT EXISTS erp_cont_config (
 id TINYINT PRIMARY KEY,
 inicio_contable DATE NULL,
 moneda CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'USD',
 actualizado_por INT NULL,
 actualizado_en DATETIME NULL,
 CONSTRAINT ck_cont_config CHECK(id=1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO erp_cont_config(id) VALUES(1);

CREATE TABLE IF NOT EXISTS erp_cont_secuencia (
 id TINYINT PRIMARY KEY,
 ultimo BIGINT NOT NULL DEFAULT 0,
 CONSTRAINT ck_cont_secuencia CHECK(id=1 AND ultimo>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO erp_cont_secuencia(id,ultimo) VALUES(1,0);

CREATE TABLE IF NOT EXISTS erp_cont_cuentas (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 codigo VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 nombre VARCHAR(120) NOT NULL,
 tipo ENUM('activo','pasivo','patrimonio','ingreso','costo','gasto') NOT NULL,
 padre_id BIGINT NULL,
 imputable TINYINT NOT NULL DEFAULT 1,
 activa TINYINT NOT NULL DEFAULT 1,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 INDEX idx_cont_cuenta_padre (padre_id),
 CONSTRAINT fk_cont_cuenta_padre FOREIGN KEY (padre_id) REFERENCES erp_cont_cuentas(id),
 CONSTRAINT ck_cont_cuenta CHECK(imputable IN(0,1) AND activa IN(0,1) AND codigo REGEXP '^[0-9]+(\\.[0-9]+)*$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_cont_roles (
 rol VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 cuenta_id BIGINT NOT NULL,
 actualizado_por INT NOT NULL,
 actualizado_en DATETIME NOT NULL,
 CONSTRAINT fk_cont_rol_cuenta FOREIGN KEY (cuenta_id) REFERENCES erp_cont_cuentas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_cont_periodos (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 anio SMALLINT NOT NULL,
 mes TINYINT NOT NULL,
 estado ENUM('abierto','cerrado') NOT NULL DEFAULT 'abierto',
 cerrado_por INT NULL,
 cerrado_en DATETIME NULL,
 UNIQUE KEY uq_cont_periodo (anio,mes),
 CONSTRAINT ck_cont_periodo CHECK(mes BETWEEN 1 AND 12 AND anio BETWEEN 2000 AND 2100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_cont_asientos (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 numero BIGINT NOT NULL UNIQUE,
 fecha DATE NOT NULL,
 periodo_id BIGINT NOT NULL,
 tipo ENUM('apertura','automatico','manual','reverso','cierre') NOT NULL,
 origen VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 reversa_de_id BIGINT NULL UNIQUE,
 huella CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 concepto VARCHAR(240) NOT NULL,
 total_centavos BIGINT NOT NULL,
 n_lineas SMALLINT NOT NULL,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 INDEX idx_cont_asiento_fecha (fecha,id),
 INDEX idx_cont_asiento_periodo (periodo_id,id),
 CONSTRAINT fk_cont_asiento_periodo FOREIGN KEY (periodo_id) REFERENCES erp_cont_periodos(id),
 CONSTRAINT fk_cont_asiento_reversa FOREIGN KEY (reversa_de_id) REFERENCES erp_cont_asientos(id),
 CONSTRAINT ck_cont_asiento CHECK(total_centavos>0 AND total_centavos<=99999999999 AND n_lineas BETWEEN 2 AND 200 AND ((tipo='reverso') = (reversa_de_id IS NOT NULL)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_cont_lineas (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 asiento_id BIGINT NOT NULL,
 nro SMALLINT NOT NULL,
 cuenta_id BIGINT NOT NULL,
 debe_centavos BIGINT NOT NULL DEFAULT 0,
 haber_centavos BIGINT NOT NULL DEFAULT 0,
 contacto_id INT NULL,
 referencia VARCHAR(80) NULL,
 UNIQUE KEY uq_cont_linea (asiento_id,nro),
 INDEX idx_cont_linea_cuenta (cuenta_id,asiento_id),
 INDEX idx_cont_linea_tercero (contacto_id,cuenta_id),
 CONSTRAINT fk_cont_linea_asiento FOREIGN KEY (asiento_id) REFERENCES erp_cont_asientos(id),
 CONSTRAINT fk_cont_linea_cuenta FOREIGN KEY (cuenta_id) REFERENCES erp_cont_cuentas(id),
 CONSTRAINT fk_cont_linea_contacto FOREIGN KEY (contacto_id) REFERENCES erp_contactos(id),
 CONSTRAINT ck_cont_linea CHECK((debe_centavos>0 AND haber_centavos=0 AND debe_centavos<=99999999999) OR (haber_centavos>0 AND debe_centavos=0 AND haber_centavos<=99999999999))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Protecciones: el libro es de solo anexar, un asiento solo existe completo y balanceado, y solo se escribe en períodos abiertos.
DROP TRIGGER IF EXISTS trg_cont_asiento_periodo;
CREATE TRIGGER trg_cont_asiento_periodo BEFORE INSERT ON erp_cont_asientos FOR EACH ROW
 IF COALESCE((SELECT estado FROM erp_cont_periodos WHERE id=NEW.periodo_id),'inexistente')<>'abierto' OR COALESCE((SELECT anio*100+mes FROM erp_cont_periodos WHERE id=NEW.periodo_id),0)<>YEAR(NEW.fecha)*100+MONTH(NEW.fecha) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El asiento exige un período abierto que contenga su fecha'; END IF;
DROP TRIGGER IF EXISTS trg_cont_asiento_no_update;
CREATE TRIGGER trg_cont_asiento_no_update BEFORE UPDATE ON erp_cont_asientos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Los asientos contables no se editan: corrige con un reverso';
DROP TRIGGER IF EXISTS trg_cont_asiento_no_delete;
CREATE TRIGGER trg_cont_asiento_no_delete BEFORE DELETE ON erp_cont_asientos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Los asientos contables no se borran: corrige con un reverso';
DROP TRIGGER IF EXISTS trg_cont_linea_ctrl;
CREATE TRIGGER trg_cont_linea_ctrl BEFORE INSERT ON erp_cont_lineas FOR EACH ROW
 IF (SELECT COUNT(*) FROM erp_cont_lineas WHERE asiento_id=NEW.asiento_id)>=COALESCE((SELECT n_lineas FROM erp_cont_asientos WHERE id=NEW.asiento_id),0) OR COALESCE((SELECT imputable*activa FROM erp_cont_cuentas WHERE id=NEW.cuenta_id),0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Línea contable fuera del asiento o en una cuenta no imputable o inactiva'; END IF;
DROP TRIGGER IF EXISTS trg_cont_linea_balance;
CREATE TRIGGER trg_cont_linea_balance AFTER INSERT ON erp_cont_lineas FOR EACH ROW
 IF (SELECT COUNT(*) FROM erp_cont_lineas WHERE asiento_id=NEW.asiento_id)=(SELECT n_lineas FROM erp_cont_asientos WHERE id=NEW.asiento_id) AND ((SELECT SUM(debe_centavos) FROM erp_cont_lineas WHERE asiento_id=NEW.asiento_id)<>(SELECT total_centavos FROM erp_cont_asientos WHERE id=NEW.asiento_id) OR (SELECT SUM(haber_centavos) FROM erp_cont_lineas WHERE asiento_id=NEW.asiento_id)<>(SELECT total_centavos FROM erp_cont_asientos WHERE id=NEW.asiento_id)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El asiento no está balanceado: debe y haber deben sumar su total'; END IF;
DROP TRIGGER IF EXISTS trg_cont_linea_no_update;
CREATE TRIGGER trg_cont_linea_no_update BEFORE UPDATE ON erp_cont_lineas FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Las líneas contables no se editan: corrige con un reverso';
DROP TRIGGER IF EXISTS trg_cont_linea_no_delete;
CREATE TRIGGER trg_cont_linea_no_delete BEFORE DELETE ON erp_cont_lineas FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Las líneas contables no se borran: corrige con un reverso';
DROP TRIGGER IF EXISTS trg_cont_cuenta_fija;
CREATE TRIGGER trg_cont_cuenta_fija BEFORE UPDATE ON erp_cont_cuentas FOR EACH ROW
 IF (NEW.id<>OLD.id OR NEW.codigo<>OLD.codigo OR NEW.tipo<>OLD.tipo OR NOT(NEW.padre_id<=>OLD.padre_id) OR NEW.imputable<>OLD.imputable) AND EXISTS(SELECT 1 FROM erp_cont_lineas WHERE cuenta_id=OLD.id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Una cuenta con movimientos conserva su código, tipo y jerarquía'; END IF;
DROP TRIGGER IF EXISTS trg_cont_cuenta_no_delete;
CREATE TRIGGER trg_cont_cuenta_no_delete BEFORE DELETE ON erp_cont_cuentas FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Las cuentas contables se desactivan, no se borran';
DROP TRIGGER IF EXISTS trg_cont_periodo_fijo;
CREATE TRIGGER trg_cont_periodo_fijo BEFORE UPDATE ON erp_cont_periodos FOR EACH ROW
 IF NEW.id<>OLD.id OR NEW.anio<>OLD.anio OR NEW.mes<>OLD.mes THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El período conserva su identidad'; END IF;
DROP TRIGGER IF EXISTS trg_cont_periodo_no_delete;
CREATE TRIGGER trg_cont_periodo_no_delete BEFORE DELETE ON erp_cont_periodos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Los períodos contables no se borran';

-- Plan base editable (política operativa de ensayo; el contador debe validarlo y adaptarlo). Las cuentas con movimientos no cambian de código ni tipo.
INSERT IGNORE INTO erp_cont_cuentas(codigo,nombre,tipo,imputable,creado_por,creado_en) VALUES
 ('1','ACTIVO','activo',0,0,NOW()),('1.1','Activo corriente','activo',0,0,NOW()),('1.1.01','Caja general','activo',1,0,NOW()),('1.1.02','Bancos','activo',1,0,NOW()),
 ('1.1.03','Cuentas por cobrar clientes','activo',1,0,NOW()),('1.1.04','Inventario de productos','activo',1,0,NOW()),('1.1.05','IVA pagado en compras (crédito tributario)','activo',1,0,NOW()),
 ('1.2','Activo no corriente','activo',0,0,NOW()),('1.2.01','Equipos y activos fijos','activo',1,0,NOW()),
 ('2','PASIVO','pasivo',0,0,NOW()),('2.1','Pasivo corriente','pasivo',0,0,NOW()),('2.1.01','Proveedores','pasivo',1,0,NOW()),('2.1.02','Recepciones por facturar','pasivo',1,0,NOW()),
 ('2.1.03','IVA cobrado en ventas por pagar','pasivo',1,0,NOW()),('2.1.04','Anticipos de clientes (prepagos)','pasivo',1,0,NOW()),('2.1.05','Comisiones por pagar','pasivo',1,0,NOW()),
 ('2.1.06','Sueldos y beneficios por pagar','pasivo',1,0,NOW()),('2.1.07','Aportes al IESS por pagar','pasivo',1,0,NOW()),
 ('3','PATRIMONIO','patrimonio',0,0,NOW()),('3.1.01','Capital','patrimonio',1,0,NOW()),('3.1.02','Resultados acumulados','patrimonio',1,0,NOW()),('3.1.03','Resultado del ejercicio','patrimonio',1,0,NOW()),
 ('4','INGRESOS','ingreso',0,0,NOW()),('4.1.01','Ingresos por servicios','ingreso',1,0,NOW()),('4.1.02','Ingresos por venta de productos','ingreso',1,0,NOW()),('4.1.03','Devoluciones y descuentos en ventas','ingreso',1,0,NOW()),
 ('5','COSTOS','costo',0,0,NOW()),('5.1.01','Costo de ventas de productos','costo',1,0,NOW()),('5.1.02','Consumo de insumos en servicios','costo',1,0,NOW()),
 ('6','GASTOS','gasto',0,0,NOW()),('6.1.01','Comisiones de colaboradores','gasto',1,0,NOW()),('6.1.02','Sueldos y salarios','gasto',1,0,NOW()),('6.1.03','Aportes patronales','gasto',1,0,NOW()),
 ('6.1.04','Beneficios sociales','gasto',1,0,NOW()),('6.1.05','Otros gastos operativos','gasto',1,0,NOW()),('6.1.06','Diferencias de caja','gasto',1,0,NOW());
UPDATE erp_cont_cuentas h JOIN erp_cont_cuentas p ON p.codigo=CASE
  WHEN h.codigo LIKE '1.1.%' THEN '1.1' WHEN h.codigo LIKE '1.2.%' THEN '1.2' WHEN h.codigo LIKE '2.1.%' THEN '2.1' WHEN h.codigo LIKE '3.1.%' THEN '3'
  WHEN h.codigo LIKE '4.1.%' THEN '4' WHEN h.codigo LIKE '5.1.%' THEN '5' WHEN h.codigo LIKE '6.1.%' THEN '6' WHEN h.codigo IN('1.1','1.2') THEN '1' WHEN h.codigo='2.1' THEN '2' END
 SET h.padre_id=p.id WHERE h.padre_id IS NULL AND h.creado_por=0;
INSERT IGNORE INTO erp_cont_roles(rol,cuenta_id,actualizado_por,actualizado_en)
 SELECT r.rol,c.id,0,NOW() FROM (SELECT 'caja' rol,'1.1.01' codigo UNION ALL SELECT 'banco','1.1.02' UNION ALL SELECT 'cxc','1.1.03' UNION ALL SELECT 'inventario','1.1.04' UNION ALL SELECT 'iva_compras','1.1.05'
  UNION ALL SELECT 'activos_fijos','1.2.01' UNION ALL SELECT 'proveedores','2.1.01' UNION ALL SELECT 'recepciones_por_facturar','2.1.02' UNION ALL SELECT 'iva_ventas','2.1.03'
  UNION ALL SELECT 'anticipos_clientes','2.1.04' UNION ALL SELECT 'comisiones_por_pagar','2.1.05' UNION ALL SELECT 'sueldos_por_pagar','2.1.06' UNION ALL SELECT 'iess_por_pagar','2.1.07'
  UNION ALL SELECT 'resultado_ejercicio','3.1.03' UNION ALL SELECT 'resultados_acumulados','3.1.02' UNION ALL SELECT 'ingreso_servicios','4.1.01' UNION ALL SELECT 'ingreso_productos','4.1.02'
  UNION ALL SELECT 'devoluciones_ventas','4.1.03' UNION ALL SELECT 'costo_ventas','5.1.01' UNION ALL SELECT 'consumo_insumos','5.1.02' UNION ALL SELECT 'gasto_comisiones','6.1.01'
  UNION ALL SELECT 'gasto_sueldos','6.1.02' UNION ALL SELECT 'gasto_aportes','6.1.03' UNION ALL SELECT 'gasto_beneficios','6.1.04' UNION ALL SELECT 'gasto_operativo','6.1.05' UNION ALL SELECT 'diferencias_caja','6.1.06') r
 JOIN erp_cont_cuentas c ON c.codigo=r.codigo;

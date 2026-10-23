-- 064: presupuestos v1. Un presupuesto es un año + un escenario («base», «optimista»…); cada presupuesto tiene versiones numeradas que pasan por
-- borrador (o revisión, si parte de una aprobada) -> aprobada -> reemplazada. Una versión aprobada o reemplazada NO se edita ni se borra: se conserva.
-- Un presupuesto NO genera ventas, obligaciones, cobros ni asientos: solo guarda cifras que la persona escribió o cargó; empieza vacío.
-- Importes en centavos. Ventas: base sin impuestos + impuesto (con impuestos = base + impuesto). Gastos: sin impuesto (las cuentas de gasto ya son netas de IVA).
-- Una línea AUSENTE significa «sin presupuesto»; una línea con 0 significa «presupuesto cero». Son casos distintos y los comparativos no los confunden.
-- Las comisiones son una partida propia (tipo 'comisiones', cuenta del rol contable gasto_comisiones): esa cuenta no se admite como 'gasto', para no contarla dos veces.
CREATE TABLE IF NOT EXISTS erp_presupuestos (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 anio SMALLINT NOT NULL,
 escenario VARCHAR(40) NOT NULL,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 UNIQUE KEY uq_presupuesto (anio,escenario),
 CONSTRAINT ck_presupuesto CHECK(anio BETWEEN 2000 AND 2100 AND CHAR_LENGTH(TRIM(escenario))>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS erp_presupuesto_versiones (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 presupuesto_id BIGINT NOT NULL,
 numero INT NOT NULL,
 estado ENUM('borrador','revision','aprobada','reemplazada') NOT NULL DEFAULT 'borrador',
 origen_version_id BIGINT NULL,
 motivo VARCHAR(200) NULL,
 abierta_ref BIGINT GENERATED ALWAYS AS (IF(estado IN('borrador','revision'),presupuesto_id,NULL)) STORED,
 vigente_ref BIGINT GENERATED ALWAYS AS (IF(estado='aprobada',presupuesto_id,NULL)) STORED,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 aprobado_por INT NULL,
 aprobado_en DATETIME NULL,
 reemplazado_en DATETIME NULL,
 UNIQUE KEY uq_presupuesto_version (presupuesto_id,numero),
 UNIQUE KEY uq_presupuesto_abierta (abierta_ref),
 UNIQUE KEY uq_presupuesto_vigente (vigente_ref),
 CONSTRAINT fk_presup_version FOREIGN KEY (presupuesto_id) REFERENCES erp_presupuestos(id),
 CONSTRAINT fk_presup_origen FOREIGN KEY (origen_version_id) REFERENCES erp_presupuesto_versiones(id),
 CONSTRAINT ck_presup_version CHECK(
   (estado IN('aprobada','reemplazada')) = (aprobado_en IS NOT NULL AND aprobado_por IS NOT NULL)
   AND (estado<>'borrador' OR origen_version_id IS NULL)
   AND (estado<>'revision' OR (origen_version_id IS NOT NULL AND motivo IS NOT NULL))
   AND (estado='reemplazada') = (reemplazado_en IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- tipo: servicio/producto (ventas, referencia = servicio o producto), gasto (cuenta contable de gasto o costo) y comisiones (sin referencia).
-- objetivo_id es el identificador del destino (servicio, producto, cuenta; 0 para comisiones) y da la unicidad por versión + mes + tipo + destino.
CREATE TABLE IF NOT EXISTS erp_presupuesto_lineas (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 version_id BIGINT NOT NULL,
 mes TINYINT NOT NULL,
 tipo ENUM('servicio','producto','gasto','comisiones') NOT NULL,
 servicio_id INT NULL,
 producto_id INT NULL,
 cuenta_id BIGINT NULL,
 objetivo_id BIGINT GENERATED ALWAYS AS (COALESCE(servicio_id,producto_id,cuenta_id,0)) STORED,
 base_centavos BIGINT NOT NULL,
 impuesto_centavos BIGINT NOT NULL DEFAULT 0,
 UNIQUE KEY uq_presup_linea (version_id,mes,tipo,objetivo_id),
 INDEX idx_presup_linea_destino (tipo,objetivo_id),
 CONSTRAINT fk_presup_linea_version FOREIGN KEY (version_id) REFERENCES erp_presupuesto_versiones(id),
 CONSTRAINT fk_presup_linea_servicio FOREIGN KEY (servicio_id) REFERENCES servicios(id),
 CONSTRAINT fk_presup_linea_producto FOREIGN KEY (producto_id) REFERENCES inventario_productos(id),
 CONSTRAINT fk_presup_linea_cuenta FOREIGN KEY (cuenta_id) REFERENCES erp_cont_cuentas(id),
 CONSTRAINT ck_presup_linea CHECK(mes BETWEEN 1 AND 12 AND base_centavos BETWEEN 0 AND 999999999 AND impuesto_centavos BETWEEN 0 AND 999999999
   AND ((tipo='servicio' AND servicio_id IS NOT NULL AND producto_id IS NULL AND cuenta_id IS NULL)
     OR (tipo='producto' AND producto_id IS NOT NULL AND servicio_id IS NULL AND cuenta_id IS NULL)
     OR (tipo='gasto' AND cuenta_id IS NOT NULL AND servicio_id IS NULL AND producto_id IS NULL AND impuesto_centavos=0)
     OR (tipo='comisiones' AND cuenta_id IS NULL AND servicio_id IS NULL AND producto_id IS NULL AND impuesto_centavos=0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Historial de valores (solo de anexar): quién creó, cambió o quitó cada cifra, con el valor anterior y el nuevo.
CREATE TABLE IF NOT EXISTS erp_presupuesto_cambios (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 version_id BIGINT NOT NULL,
 mes TINYINT NOT NULL,
 tipo ENUM('servicio','producto','gasto','comisiones') NOT NULL,
 objetivo_id BIGINT NOT NULL,
 accion ENUM('crear','modificar','quitar') NOT NULL,
 base_antes_centavos BIGINT NULL,
 impuesto_antes_centavos BIGINT NULL,
 base_despues_centavos BIGINT NULL,
 impuesto_despues_centavos BIGINT NULL,
 origen ENUM('manual','carga','revision') NOT NULL,
 carga_id BIGINT NULL,
 actor_id INT NOT NULL,
 creado_en DATETIME NOT NULL,
 INDEX idx_presup_cambio_version (version_id,id),
 CONSTRAINT fk_presup_cambio_version FOREIGN KEY (version_id) REFERENCES erp_presupuesto_versiones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cargas por plantilla CSV: primero se guarda la VISTA PREVIA ya validada (estado 'previa'); solo se aplica al confirmarla, y se aplica exactamente
-- lo que se vio (filas_json), no el archivo otra vez.
CREATE TABLE IF NOT EXISTS erp_presupuesto_cargas (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 version_id BIGINT NOT NULL,
 estado ENUM('previa','confirmada','descartada') NOT NULL DEFAULT 'previa',
 huella CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 n_filas SMALLINT NOT NULL,
 filas_json JSON NOT NULL,
 creado_por INT NOT NULL,
 creado_en DATETIME NOT NULL,
 resuelto_por INT NULL,
 resuelto_en DATETIME NULL,
 INDEX idx_presup_carga_version (version_id,id),
 CONSTRAINT fk_presup_carga_version FOREIGN KEY (version_id) REFERENCES erp_presupuesto_versiones(id),
 CONSTRAINT ck_presup_carga CHECK(n_filas BETWEEN 1 AND 5000 AND (estado='previa') = (resuelto_en IS NULL AND resuelto_por IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TRIGGER IF EXISTS trg_presup_no_update;
DROP TRIGGER IF EXISTS trg_presup_no_delete;
DROP TRIGGER IF EXISTS trg_presup_version_update;
DROP TRIGGER IF EXISTS trg_presup_version_no_delete;
DROP TRIGGER IF EXISTS trg_presup_linea_insert;
DROP TRIGGER IF EXISTS trg_presup_linea_update;
DROP TRIGGER IF EXISTS trg_presup_linea_delete;
DROP TRIGGER IF EXISTS trg_presup_cambio_no_update;
DROP TRIGGER IF EXISTS trg_presup_cambio_no_delete;
DROP TRIGGER IF EXISTS trg_presup_carga_update;
DROP TRIGGER IF EXISTS trg_presup_carga_no_delete;
CREATE TRIGGER trg_presup_no_update BEFORE UPDATE ON erp_presupuestos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Un presupuesto no se edita, se crea una version nueva';
CREATE TRIGGER trg_presup_no_delete BEFORE DELETE ON erp_presupuestos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Un presupuesto se conserva';
-- Solo avanzan los estados (borrador|revision -> aprobada -> reemplazada) y nada de lo demás cambia; al aprobar se fija quién y cuándo.
CREATE TRIGGER trg_presup_version_update BEFORE UPDATE ON erp_presupuesto_versiones FOR EACH ROW IF NEW.presupuesto_id<>OLD.presupuesto_id OR NEW.numero<>OLD.numero OR NOT (NEW.origen_version_id<=>OLD.origen_version_id) OR NOT (NEW.motivo<=>OLD.motivo) OR NEW.creado_por<>OLD.creado_por OR NEW.creado_en<>OLD.creado_en
  OR NOT ((OLD.estado IN('borrador','revision') AND NEW.estado='aprobada') OR (OLD.estado='aprobada' AND NEW.estado='reemplazada'))
  OR (OLD.estado='aprobada' AND (NEW.aprobado_por<>OLD.aprobado_por OR NEW.aprobado_en<>OLD.aprobado_en)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La version solo avanza de estado y se conserva'; END IF;
CREATE TRIGGER trg_presup_version_no_delete BEFORE DELETE ON erp_presupuesto_versiones FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Las versiones de un presupuesto se conservan';
-- Las líneas solo se tocan mientras la versión esté en borrador o revisión; una cuenta de gasto o costo, y nunca la de comisiones. Que la cuenta esté imputable y activa lo exige el servicio al escribir o cargar una cifra, NO el trigger: una cuenta archivada después no impide copiar la versión aprobada en una revisión. Triggers de una sola sentencia (IF ... END IF): el lector de restauración (ProteccionesRestauradas) solo repone esa forma.
CREATE TRIGGER trg_presup_linea_insert BEFORE INSERT ON erp_presupuesto_lineas FOR EACH ROW IF (SELECT estado FROM erp_presupuesto_versiones WHERE id=NEW.version_id) NOT IN('borrador','revision')
  OR (NEW.tipo='gasto' AND ((SELECT tipo FROM erp_cont_cuentas WHERE id=NEW.cuenta_id) NOT IN('gasto','costo')
      OR NEW.cuenta_id=(SELECT cuenta_id FROM erp_cont_roles WHERE rol='gasto_comisiones'))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Linea no admitida: version cerrada, o cuenta que no es de gasto o costo (las comisiones van en su partida)'; END IF;
CREATE TRIGGER trg_presup_linea_update BEFORE UPDATE ON erp_presupuesto_lineas FOR EACH ROW IF NEW.version_id<>OLD.version_id OR NEW.mes<>OLD.mes OR NEW.tipo<>OLD.tipo OR NEW.objetivo_id<>OLD.objetivo_id
  OR (SELECT estado FROM erp_presupuesto_versiones WHERE id=OLD.version_id) NOT IN('borrador','revision') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Una version aprobada no se edita'; END IF;
CREATE TRIGGER trg_presup_linea_delete BEFORE DELETE ON erp_presupuesto_lineas FOR EACH ROW IF (SELECT estado FROM erp_presupuesto_versiones WHERE id=OLD.version_id) NOT IN('borrador','revision') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Una version aprobada no se edita'; END IF;
CREATE TRIGGER trg_presup_cambio_no_update BEFORE UPDATE ON erp_presupuesto_cambios FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El historial del presupuesto no se edita';
CREATE TRIGGER trg_presup_cambio_no_delete BEFORE DELETE ON erp_presupuesto_cambios FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El historial del presupuesto se conserva';
CREATE TRIGGER trg_presup_carga_update BEFORE UPDATE ON erp_presupuesto_cargas FOR EACH ROW IF OLD.estado<>'previa' OR NEW.estado='previa' OR NEW.version_id<>OLD.version_id OR NEW.huella<>OLD.huella OR NEW.n_filas<>OLD.n_filas OR NEW.creado_por<>OLD.creado_por OR NEW.creado_en<>OLD.creado_en OR CAST(NEW.filas_json AS CHAR)<>CAST(OLD.filas_json AS CHAR) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La carga solo se confirma o se descarta'; END IF;
CREATE TRIGGER trg_presup_carga_no_delete BEFORE DELETE ON erp_presupuesto_cargas FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Las cargas se conservan';

-- La dimensión de fecha debe cubrir también los meses presupuestados, que suelen ser FUTUROS: sin esto, un presupuesto de diciembre con ventas hasta octubre
-- dejaría diciembre fuera del calendario. Se añade el 1 de enero y el 31 de diciembre de cada año con presupuesto a las fuentes del rango (062 sigue
-- siendo la definición original; esta la redefine y el rollback de 064 la devuelve).
CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_dim_fecha AS
WITH fuentes AS (
  SELECT fecha_aplicacion AS fecha FROM bi_aplicaciones_saldo
  UNION ALL SELECT fecha_venta_original AS fecha FROM bi_aplicaciones_saldo
  UNION ALL SELECT fecha_programada AS fecha FROM bi_atenciones
  UNION ALL SELECT fecha_servicio_realizado AS fecha FROM bi_atenciones
  UNION ALL SELECT DATE(atencion_inicio_at) AS fecha FROM bi_atenciones
  UNION ALL SELECT DATE(atencion_fin_at) AS fecha FROM bi_atenciones
  UNION ALL SELECT DATE(ausencia_at) AS fecha FROM bi_atenciones
  UNION ALL SELECT fecha AS fecha FROM bi_cxc_movimientos
  UNION ALL SELECT fecha_movimiento_devolucion AS fecha FROM bi_devoluciones_dinero
  UNION ALL SELECT fecha_venta_original AS fecha FROM bi_devoluciones_dinero
  UNION ALL SELECT fecha_cobro_original AS fecha FROM bi_devoluciones_dinero
  UNION ALL SELECT fecha_devolucion AS fecha FROM bi_devoluciones_venta
  UNION ALL SELECT fecha_venta_original AS fecha FROM bi_devoluciones_venta
  UNION ALL SELECT fecha_movimiento AS fecha FROM bi_flujo_dinero
  UNION ALL SELECT fecha_venta_original AS fecha FROM bi_flujo_dinero
  UNION ALL SELECT fecha_cobro_original AS fecha FROM bi_flujo_dinero
  UNION ALL SELECT fecha AS fecha FROM bi_inventario_movimientos
  UNION ALL SELECT fecha AS fecha FROM bi_libro_lineas
  UNION ALL SELECT fecha_nota AS fecha FROM bi_notas_credito
  UNION ALL SELECT fecha AS fecha FROM bi_rh_asistencia
  UNION ALL SELECT DATE(entrada) AS fecha FROM bi_rh_asistencia
  UNION ALL SELECT DATE(salida) AS fecha FROM bi_rh_asistencia
  UNION ALL SELECT desde AS fecha FROM bi_rh_ausencias
  UNION ALL SELECT hasta AS fecha FROM bi_rh_ausencias
  UNION ALL SELECT fecha AS fecha FROM bi_rh_comisiones_liquidaciones
  UNION ALL SELECT fecha AS fecha FROM bi_rh_comisiones_movimientos
  UNION ALL SELECT fecha_venta AS fecha FROM bi_venta_lineas
  UNION ALL SELECT MAKEDATE(anio, 1) AS fecha FROM erp_presupuestos
  UNION ALL SELECT DATE_SUB(DATE_ADD(MAKEDATE(anio, 1), INTERVAL 1 YEAR), INTERVAL 1 DAY) AS fecha FROM erp_presupuestos
),
rango AS (
  SELECT LEAST(COALESCE(MIN(fecha), DATE(UTC_TIMESTAMP() - INTERVAL 5 HOUR)), DATE(UTC_TIMESTAMP() - INTERVAL 5 HOUR)) AS desde,
         GREATEST(COALESCE(MAX(fecha), DATE(UTC_TIMESTAMP() - INTERVAL 5 HOUR)), DATE(UTC_TIMESTAMP() - INTERVAL 5 HOUR)) AS hasta FROM fuentes
),
digitos AS (
  SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
  UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9
),
dias AS (
  SELECT DATE_ADD(r.desde, INTERVAL d0.n + 10 * d1.n + 100 * d2.n + 1000 * d3.n + 10000 * d4.n DAY) AS fecha
  FROM rango r
  JOIN digitos d0 JOIN digitos d1 JOIN digitos d2 JOIN digitos d3 JOIN digitos d4
  WHERE d0.n + 10 * d1.n + 100 * d2.n + 1000 * d3.n + 10000 * d4.n <= DATEDIFF(r.hasta, r.desde)
)
SELECT fecha, YEAR(fecha) AS anio, QUARTER(fecha) AS trimestre, MONTH(fecha) AS mes, YEAR(fecha) * 100 + MONTH(fecha) AS anio_mes,
 ELT(MONTH(fecha), 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre') AS nombre_mes,
 DAY(fecha) AS dia_del_mes, WEEKDAY(fecha) + 1 AS dia_semana,
 ELT(WEEKDAY(fecha) + 1, 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo') AS nombre_dia,
 WEEKDAY(fecha) >= 5 AS es_fin_de_semana,
 YEARWEEK(fecha, 3) DIV 100 AS anio_iso, WEEK(fecha, 3) AS semana_iso
FROM dias;

-- ---------------------------------------------------------------------------------------------------------------------------------------------
-- Vistas BI (solo lectura, sin datos personales y SIN columnas de fecha: el año y el mes van como números y anio_mes (AAAAMM, como bi_dim_fecha.anio_mes) enlaza con la dimensión de fecha).
-- Grupo comercial (como bi_libro_lineas): las cifras de gasto son totales por cuenta, no por persona.
-- ---------------------------------------------------------------------------------------------------------------------------------------------

-- LO REAL por año, mes y destino. UNA sola definición, usada por el comparativo de la pantalla y por BI.
--  · Ventas = venta devengada: líneas de ventas confirmadas, por mes de confirmación, NETAS de descuentos (el descuento ya está fuera del total de la línea;
--    descuentos_centavos es solo informativo). Las devoluciones restan en el mes en que se registran (erp_devoluciones.creado_en, fecha propia).
--    La base/impuesto de una devolución se reparte con la proporción de su línea (redondeo al centavo por devolución).
--  · Gastos = gasto devengado: neto deudor del libro (asientos, por su fecha) en cuentas de tipo gasto o costo; el reverso de un asiento ya resta.
--    El asiento de cierre del ejercicio (tipo 'cierre') y sus reversos se excluyen: trasladan el resultado, no son gasto del período.
--    La cuenta del rol gasto_comisiones va como tipo 'comisiones' (objetivo 0) y NO como 'gasto': el total de gastos la cuenta una sola vez.
--  · tipo 'sin_clasificar': líneas de venta sin servicio ni producto; existe para que el real de ventas cuadre con el total.
CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_presupuesto_real_mes AS
WITH mov AS (
  SELECT YEAR(v.confirmado_en) AS anio, MONTH(v.confirmado_en) AS mes,
   CASE WHEN l.servicio_id IS NOT NULL THEN 'servicio' WHEN l.producto_id IS NOT NULL THEN 'producto' ELSE 'sin_clasificar' END AS tipo,
   COALESCE(l.servicio_id, l.producto_id, 0) AS objetivo_id,
   (l.total_centavos - l.impuesto_centavos) AS ventas_base, l.impuesto_centavos AS ventas_impuesto, l.descuento_centavos AS descuentos, 0 AS devol_base, 0 AS devol_impuesto
  FROM erp_venta_lineas l JOIN erp_ventas v ON v.id = l.venta_id WHERE v.estado = 'confirmada'
  UNION ALL
  SELECT YEAR(d.creado_en), MONTH(d.creado_en),
   CASE WHEN l.servicio_id IS NOT NULL THEN 'servicio' WHEN l.producto_id IS NOT NULL THEN 'producto' ELSE 'sin_clasificar' END,
   COALESCE(l.servicio_id, l.producto_id, 0), 0, 0, 0,
   d.monto_centavos - IF(l.total_centavos > 0, (d.monto_centavos * l.impuesto_centavos * 2 + l.total_centavos) DIV (2 * l.total_centavos), 0),
   IF(l.total_centavos > 0, (d.monto_centavos * l.impuesto_centavos * 2 + l.total_centavos) DIV (2 * l.total_centavos), 0)
  FROM erp_devoluciones d JOIN erp_venta_lineas l ON l.id = d.linea_id JOIN erp_ventas v ON v.id = l.venta_id WHERE v.estado = 'confirmada'
), ventas AS (
  SELECT anio, mes, tipo, objetivo_id, SUM(ventas_base) AS ventas_base, SUM(ventas_impuesto) AS ventas_impuesto, SUM(descuentos) AS descuentos,
         SUM(devol_base) AS devol_base, SUM(devol_impuesto) AS devol_impuesto
  FROM mov GROUP BY anio, mes, tipo, objetivo_id
), gastos AS (
  SELECT YEAR(a.fecha) AS anio, MONTH(a.fecha) AS mes, IF(c.id = rc.cuenta_id, 'comisiones', 'gasto') AS tipo, IF(c.id = rc.cuenta_id, 0, c.id) AS objetivo_id,
         SUM(li.debe_centavos - li.haber_centavos) AS neto
  FROM erp_cont_lineas li JOIN erp_cont_asientos a ON a.id = li.asiento_id JOIN erp_cont_cuentas c ON c.id = li.cuenta_id
  LEFT JOIN erp_cont_roles rc ON rc.rol = 'gasto_comisiones'
  WHERE c.tipo IN ('gasto', 'costo') AND a.tipo <> 'cierre'
    AND NOT EXISTS (SELECT 1 FROM erp_cont_asientos o WHERE o.id = a.reversa_de_id AND o.tipo = 'cierre')
  GROUP BY YEAR(a.fecha), MONTH(a.fecha), IF(c.id = rc.cuenta_id, 'comisiones', 'gasto'), IF(c.id = rc.cuenta_id, 0, c.id)
)
SELECT anio, mes, (anio * 100 + mes) AS anio_mes, tipo, objetivo_id,
 ventas_base AS ventas_base_centavos, ventas_impuesto AS ventas_impuesto_centavos, descuentos AS descuentos_informativo_centavos,
 devol_base AS devoluciones_base_centavos, devol_impuesto AS devoluciones_impuesto_centavos,
 (ventas_base - devol_base) AS real_base_centavos, (ventas_impuesto - devol_impuesto) AS real_impuesto_centavos,
 (ventas_base - devol_base + ventas_impuesto - devol_impuesto) AS real_total_centavos
FROM ventas
UNION ALL
SELECT anio, mes, (anio * 100 + mes), tipo, objetivo_id, 0, 0, 0, 0, 0, neto, 0, neto
FROM gastos;

-- Líneas presupuestadas de las versiones aprobadas y reemplazadas (los borradores y revisiones en curso no salen a BI). es_vigente = la aprobada actual.
CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_presupuesto_lineas AS
SELECT DISTINCT l.id AS linea_id, p.id AS presupuesto_id, p.anio, p.escenario, v.id AS version_id, v.numero AS version_numero, v.estado AS version_estado,
 (v.estado = 'aprobada') AS es_vigente, l.mes, (p.anio * 100 + l.mes) AS anio_mes, l.tipo, l.servicio_id, l.producto_id, l.cuenta_id,
 c.codigo AS cuenta_codigo, c.nombre AS cuenta_nombre, l.base_centavos, l.impuesto_centavos, (l.base_centavos + l.impuesto_centavos) AS total_centavos
FROM erp_presupuesto_lineas l JOIN erp_presupuesto_versiones v ON v.id = l.version_id JOIN erp_presupuestos p ON p.id = v.presupuesto_id
LEFT JOIN erp_cont_cuentas c ON c.id = l.cuenta_id
WHERE v.estado IN ('aprobada', 'reemplazada');

-- Presupuesto vigente frente a lo real, por mes y destino. Hay fila para cada línea presupuestada y para cada destino con real en ese año (aunque no tenga
-- presupuesto: estado 'sin_presupuesto', con las columnas de presupuesto y desviación en NULL). La desviación es real − presupuesto; el porcentaje, sobre el
-- presupuesto, con 2 decimales, y NULL cuando el presupuesto es 0 o no existe (estado 'presupuesto_cero' / 'sin_presupuesto'): nunca se inventa un porcentaje.
-- En ventas, desviación positiva = vendiste más de lo presupuestado; en gastos, = gastaste más (columna sentido).
CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_presupuesto_vs_real AS
WITH vigente AS (
  SELECT p.id AS presupuesto_id, p.anio, p.escenario, v.id AS version_id, v.numero AS version_numero
  FROM erp_presupuestos p JOIN erp_presupuesto_versiones v ON v.presupuesto_id = p.id AND v.estado = 'aprobada'
), claves AS (
  SELECT g.presupuesto_id, l.mes, l.tipo, l.objetivo_id FROM vigente g JOIN erp_presupuesto_lineas l ON l.version_id = g.version_id
  UNION
  SELECT g.presupuesto_id, r.mes, r.tipo, r.objetivo_id FROM vigente g JOIN bi_presupuesto_real_mes r ON r.anio = g.anio
)
SELECT DISTINCT k.presupuesto_id, g.anio, g.escenario, g.version_id, g.version_numero, k.mes, (g.anio * 100 + k.mes) AS anio_mes, k.tipo, k.objetivo_id,
 IF(k.tipo = 'servicio', k.objetivo_id, NULL) AS servicio_id, IF(k.tipo = 'producto', k.objetivo_id, NULL) AS producto_id, IF(k.tipo = 'gasto', k.objetivo_id, NULL) AS cuenta_id,
 c.codigo AS cuenta_codigo, c.nombre AS cuenta_nombre, IF(k.tipo IN ('gasto', 'comisiones'), 'gasto', 'ingreso') AS sentido,
 CASE WHEN l.id IS NULL THEN 'sin_presupuesto' WHEN l.base_centavos + l.impuesto_centavos = 0 THEN 'presupuesto_cero' ELSE 'con_presupuesto' END AS estado_presupuesto,
 l.base_centavos AS presupuesto_base_centavos, l.impuesto_centavos AS presupuesto_impuesto_centavos, (l.base_centavos + l.impuesto_centavos) AS presupuesto_total_centavos,
 COALESCE(r.real_base_centavos, 0) AS real_base_centavos, COALESCE(r.real_impuesto_centavos, 0) AS real_impuesto_centavos, COALESCE(r.real_total_centavos, 0) AS real_total_centavos,
 (COALESCE(r.real_base_centavos, 0) - l.base_centavos) AS desviacion_base_centavos,
 (COALESCE(r.real_total_centavos, 0) - (l.base_centavos + l.impuesto_centavos)) AS desviacion_total_centavos,
 IF(l.base_centavos > 0, ROUND((COALESCE(r.real_base_centavos, 0) - l.base_centavos) * 100 / l.base_centavos, 2), NULL) AS desviacion_base_pct,
 IF(l.base_centavos + l.impuesto_centavos > 0, ROUND((COALESCE(r.real_total_centavos, 0) - (l.base_centavos + l.impuesto_centavos)) * 100 / (l.base_centavos + l.impuesto_centavos), 2), NULL) AS desviacion_total_pct
FROM claves k
JOIN vigente g ON g.presupuesto_id = k.presupuesto_id
LEFT JOIN erp_presupuesto_lineas l ON l.version_id = g.version_id AND l.mes = k.mes AND l.tipo = k.tipo AND l.objetivo_id = k.objetivo_id
LEFT JOIN bi_presupuesto_real_mes r ON r.anio = g.anio AND r.mes = k.mes AND r.tipo = k.tipo AND r.objetivo_id = k.objetivo_id
LEFT JOIN erp_cont_cuentas c ON c.id = IF(k.tipo = 'gasto', k.objetivo_id, NULL);

-- Consolidado anual por destino. El presupuesto suma los meses que lo tienen; la desviación compara SOLO esos meses (real_*_meses_presupuestados), para no
-- mezclar un año completo de real con un presupuesto parcial. meses_con_presupuesto dice cuántos meses lo tienen.
CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_presupuesto_anual AS
SELECT presupuesto_id, anio, escenario, version_id, version_numero, tipo, objetivo_id, servicio_id, producto_id, cuenta_id, cuenta_codigo, cuenta_nombre, sentido,
 COUNT(presupuesto_base_centavos) AS meses_con_presupuesto,
 SUM(presupuesto_base_centavos) AS presupuesto_base_centavos, SUM(presupuesto_impuesto_centavos) AS presupuesto_impuesto_centavos, SUM(presupuesto_total_centavos) AS presupuesto_total_centavos,
 SUM(real_base_centavos) AS real_base_centavos, SUM(real_total_centavos) AS real_total_centavos,
 SUM(IF(estado_presupuesto = 'sin_presupuesto', 0, real_base_centavos)) AS real_base_meses_presupuestados_centavos,
 SUM(IF(estado_presupuesto = 'sin_presupuesto', 0, real_total_centavos)) AS real_total_meses_presupuestados_centavos,
 SUM(IF(estado_presupuesto = 'sin_presupuesto', 0, real_base_centavos)) - SUM(presupuesto_base_centavos) AS desviacion_base_centavos,
 SUM(IF(estado_presupuesto = 'sin_presupuesto', 0, real_total_centavos)) - SUM(presupuesto_total_centavos) AS desviacion_total_centavos,
 IF(SUM(presupuesto_base_centavos) > 0, ROUND((SUM(IF(estado_presupuesto = 'sin_presupuesto', 0, real_base_centavos)) - SUM(presupuesto_base_centavos)) * 100 / SUM(presupuesto_base_centavos), 2), NULL) AS desviacion_base_pct,
 IF(SUM(presupuesto_total_centavos) > 0, ROUND((SUM(IF(estado_presupuesto = 'sin_presupuesto', 0, real_total_centavos)) - SUM(presupuesto_total_centavos)) * 100 / SUM(presupuesto_total_centavos), 2), NULL) AS desviacion_total_pct
FROM bi_presupuesto_vs_real
GROUP BY presupuesto_id, anio, escenario, version_id, version_numero, tipo, objetivo_id, servicio_id, producto_id, cuenta_id, cuenta_codigo, cuenta_nombre, sentido;

-- Cobros de caja por mes (flujo de dinero, NO venta devengada): cobros − devoluciones de cobro, con impuestos. No incluye ventas pagadas con saldo prepago
-- (no pasan por caja). Sin presupuesto propio en esta versión: sirve para contrastar con las ventas presupuestadas, no para medir desviación.
CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_presupuesto_cobros_mes AS
SELECT anio, mes, (anio * 100 + mes) AS anio_mes,
 SUM(IF(tipo_flujo = 'cobro', importe_centavos, 0)) AS cobros_centavos, SUM(IF(tipo_flujo = 'devolucion_de_cobro', importe_centavos, 0)) AS devoluciones_de_cobro_centavos,
 SUM(IF(tipo_flujo = 'cobro', importe_centavos, 0)) - SUM(IF(tipo_flujo = 'devolucion_de_cobro', importe_centavos, 0)) AS cobros_netos_centavos
FROM (SELECT YEAR(fecha_movimiento) AS anio, MONTH(fecha_movimiento) AS mes, tipo_flujo, importe_centavos
      FROM bi_flujo_dinero WHERE tipo_flujo IN ('cobro', 'devolucion_de_cobro')) AS m
GROUP BY anio, mes;

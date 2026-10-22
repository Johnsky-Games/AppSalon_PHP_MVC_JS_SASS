-- Modelo de lectura para BI, sexto corte: dimensiones (fecha, producto, servicio, profesional y colaborador).
-- Definiciones, relaciones con los hechos y roles de fecha en docs/BI_DIMENSIONES.md. Mismas reglas que 057-061: solo lectura (ninguna vista
-- admite UPDATE ni DELETE; la prueba lo verifica), SQL SECURITY DEFINER y sin datos personales: ninguna dimensión lleva nombres de personas,
-- documentos ni contactos. La dimensión de colaborador es laboral (prefijo bi_rh_, mismo grupo de acceso que 061).

-- Grano: una fila por día, desde la fecha más antigua de cualquier hecho (o el día de hoy en Ecuador, UTC-5 sin cambio de hora, si es anterior)
-- hasta la más reciente (o hoy, lo que sea posterior): el rango siempre incluye hoy; hasta 10.000 días. Es UNA sola dimensión: los roles (fecha de la cita, de la venta, del asiento, del pago...) son relaciones
-- de los hechos hacia esta misma tabla (una activa y las demás con USERELATIONSHIP), no copias.
CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_dim_fecha AS
WITH fuentes AS (
  SELECT fecha FROM citas
  UNION ALL SELECT DATE(confirmado_en) FROM erp_ventas WHERE confirmado_en IS NOT NULL
  UNION ALL SELECT fecha FROM erp_cont_asientos
  UNION ALL SELECT DATE(pagado_at) FROM pagos WHERE pagado_at IS NOT NULL
  UNION ALL SELECT DATE(registrado_en) FROM inventario_movimientos
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
  SELECT DATE_ADD(r.desde, INTERVAL d0.n + 10 * d1.n + 100 * d2.n + 1000 * d3.n DAY) AS fecha
  FROM rango r
  JOIN digitos d0 JOIN digitos d1 JOIN digitos d2 JOIN digitos d3
  WHERE d0.n + 10 * d1.n + 100 * d2.n + 1000 * d3.n <= DATEDIFF(r.hasta, r.desde)
)
SELECT fecha, YEAR(fecha) AS anio, QUARTER(fecha) AS trimestre, MONTH(fecha) AS mes, YEAR(fecha) * 100 + MONTH(fecha) AS anio_mes,
 ELT(MONTH(fecha), 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre') AS nombre_mes,
 DAY(fecha) AS dia_del_mes, WEEKDAY(fecha) + 1 AS dia_semana,
 ELT(WEEKDAY(fecha) + 1, 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo') AS nombre_dia,
 WEEKDAY(fecha) >= 5 AS es_fin_de_semana,
 YEARWEEK(fecha, 3) DIV 100 AS anio_iso, WEEK(fecha, 3) AS semana_iso
FROM dias;

-- Grano: una fila por producto de inventario. Atributos del catálogo ACTUAL: los hechos guardan su propio precio e importe históricos
-- (bi_venta_lineas), así que cambiar el precio de catálogo no reescribe lo vendido.
CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_dim_producto AS
SELECT DISTINCT p.id AS producto_id, p.sku, p.nombre, p.unidad, p.activo,
 c.id IS NOT NULL AS en_catalogo_de_venta, COALESCE(c.activo, 0) AS catalogo_activo, c.tipo AS tipo_catalogo,
 c.precio_centavos AS precio_catalogo_centavos, c.impuesto_pb
FROM inventario_productos p
LEFT JOIN erp_catalogo c ON c.producto_id = p.id;

-- Grano: una fila por servicio. Mismo criterio: precio y duración ACTUALES; lo reservado conserva los suyos (bi_atencion_servicios).
CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_dim_servicio AS
SELECT DISTINCT s.id AS servicio_id, s.nombre, ROUND(s.precio * 100) AS precio_actual_centavos, s.duracion_minutos,
 c.id IS NOT NULL AS en_catalogo_de_venta, COALESCE(c.activo, 0) AS catalogo_activo, c.tipo AS tipo_catalogo, c.impuesto_pb
FROM servicios s
LEFT JOIN erp_catalogo c ON c.servicio_id = s.id;

-- Grano: una fila por profesional de la agenda. SIN nombre: es un dato personal; las etiquetas legibles las pone el propietario en el modelo de
-- Power BI (tabla propia) o se muestra el identificador. No lleva colaborador_id: el puente hacia los datos laborales vive solo en bi_rh_dim_colaborador.
CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_dim_profesional AS
SELECT DISTINCT p.id AS profesional_id, p.activo, p.colaborador_id IS NOT NULL AS tiene_ficha_laboral
FROM profesionales p;

-- Grano: una fila por colaborador (un colaborador tiene como máximo un profesional: uq_profesional_colaborador). Datos LABORALES identificables por colaborador_id (no anónimos): mismo grupo de acceso que bi_rh_*.
-- Sin nombre, documento, contacto, sueldo ni notas.
CREATE OR REPLACE SQL SECURITY DEFINER VIEW bi_rh_dim_colaborador AS
SELECT DISTINCT c.id AS colaborador_id, c.rol, c.estado, c.fecha_ingreso, DATE(c.baja_en) AS fecha_baja,
 p.id AS profesional_id
FROM colaboradores c
LEFT JOIN profesionales p ON p.colaborador_id = c.id;

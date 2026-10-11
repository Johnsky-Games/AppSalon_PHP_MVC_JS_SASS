# Sistema de Migraciones de Base de Datos — AppSalon

Este directorio contiene las migraciones versionadas y scripts de soporte para la evolución controlada del esquema de datos en AppSalon.

---

## 1. Estructura de Archivos

- `database/schema_base.sql`: Esquema base inicial del proyecto (tablas `usuarios`, `servicios`, `citas`, `citasservicios`).
- `database/migrador.php`: Script CLI para inspeccionar (`status`), aplicar (`up`) y revertir (`down`) migraciones.
- `database/migrations/`:
  - `001_security_hardening.sql` / `001_security_hardening_rollback.sql`: Tabla `migraciones`, columnas de tokens criptográficos (`token_hash`, `token_tipo`, `token_expira` e índices `idx_token_hash`, `idx_token_expira` en `usuarios`) y tabla `intentos_login` (`uk_tipo_identificador`, `idx_bloqueado_hasta`).
  - `002_innodb_and_rate_limit_window.sql` / `002_innodb_and_rate_limit_window_rollback.sql`: Conversión de todas las tablas a motor `InnoDB` y adición de `primera_peticion` a `intentos_login`.
  - `003_profesionales_duracion_y_horarios.sql` / `003_profesionales_duracion_y_horarios_rollback.sql`: Columna `duracion_minutos` en `servicios`, y creación de tablas `profesionales` (`idx_profesionales_activo`), `profesionales_servicios` (`uk_profesional_servicio`, `fk_prof_serv_profesional`, `fk_prof_serv_servicio`), `horarios_profesionales` (`idx_horarios_prof_dia`, `fk_horarios_profesional`), `descansos_profesionales` (`idx_descansos_prof_dia`, `fk_descansos_profesional`) y `bloqueos_profesionales` (`idx_bloqueos_prof_fechas`, `fk_bloqueos_profesional`).
  - `004_reservas_profesional_ocupacion_historico.sql` / `004_reservas_profesional_ocupacion_historico_rollback.sql`: Columnas `hora_inicio`, `hora_fin`, `duracion_total_minutos`, `profesionalId` en `citas` (`idx_citas_prof_fecha_intervalo`, `fk_citas_profesional` `ON DELETE RESTRICT`) y columnas de snapshot histórico (`nombre_servicio`, `precio_servicio`, `duracion_minutos`) en `citasservicios`.
  - `005_notificaciones_citas.sql` / `005_notificaciones_citas_rollback.sql`: Tabla `notificaciones_citas` con `uq_notificaciones_clave_idempotencia`, `idx_notificaciones_estado_proximo`, `idx_notificaciones_cita_ref_tipo`, `fk_notificaciones_cita` (`ON DELETE SET NULL`) y `fk_notificaciones_usuario` (`ON DELETE SET NULL`).
  - `006_base_pagos.sql` / `006_base_pagos_rollback.sql`: Columna `estado_pago` e índice `idx_citas_estado_pago` en `citas`, y tabla `pagos` con `uq_pagos_referencia`, `uq_pagos_transaccion_externa`, `uq_pagos_idempotency_key`, `idx_pagos_cita_ref_estado`, `fk_pagos_cita` (`ON DELETE SET NULL`) y `fk_pagos_usuario` (`ON DELETE SET NULL`).
  - `007_citas_estado_cancelacion.sql` / `007_citas_estado_cancelacion_rollback.sql`: Columnas `estado` (`confirmada`, `cancelada`), `cancelado_por`, `cancelado_at`, `motivo_cancelacion` en `citas`, con índices `idx_citas_estado` e `idx_citas_prof_fecha_estado` para cancelación lógica trazable y optimización de agenda.
  - `008_notificaciones_outbox_lease.sql` / `008_notificaciones_outbox_lease_rollback.sql`: Columnas `worker_token` y `lease_expires_at` en `notificaciones_citas` con índice `idx_notificaciones_lease` para soporte de arrendamientos de worker y recuperación resiliente de outbox.

---

## 2. Uso del Migrador

```bash
# Consultar estado de migraciones
php database/migrador.php status

# Aplicar migraciones pendientes
php database/migrador.php up

# Revertir migraciones registradas (alcance: procesa todas las migraciones con rollback en orden inverso)
php database/migrador.php down
```

> **Alcance del comando `down`:** Actualmente `php database/migrador.php down` procesa en orden cronológico inverso (`rsort`) **todas** las migraciones registradas en la tabla `migraciones` que cuenten con su respectivo archivo `*_rollback.sql`, revirtiendo el esquema completo hacia el estado base, no únicamente la última migración aplicada. Si en producción o desarrollo se requiere revertir de forma granular una sola migración intermedia, ejecute manualmente el archivo SQL de rollback específico (`database/migrations/<archivo>_rollback.sql`) y actualice la tabla de control `migraciones`.

---

## 3. Protocolo de Recuperación ante Fallos Parciales

En motores MySQL / MariaDB, las sentencias DDL (`ALTER TABLE`, `CREATE TABLE`) provocan un commit implícito inmediato y no pueden englobarse en transacciones atómicas estándar.

Si `database/migrador.php` detecta un fallo intermedio:
1. El ejecutor detiene el proceso con código de salida `1` (`exit(1)`).
2. La migración **NO** se registra en la tabla `migraciones`.
3. Para recuperar el entorno:
   - Revise el mensaje de error arrojado por el migrador (e.g. columna duplicada o tabla inexistente).
   - Inspeccione la tabla afectada con `DESCRIBE {tabla}` o `SHOW CREATE TABLE {tabla}`.
   - Si una sentencia previa del archivo ya se aplicó (por ejemplo, adición de una columna), ejecute manualmente el script de reversión correspondiente (`*_rollback.sql`) o revierta la alteración específica.
   - Corrija la causa del error en el archivo `.sql`.
   - Vuelva a ejecutar `php database/migrador.php up`.

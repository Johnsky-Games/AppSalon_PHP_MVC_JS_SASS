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

  - `009` … `015`: reembolsos con fecha, equipo/permisos (`usuario_accesos`, `version_sesion`, `equipo_mutex`), perfiles y fotos, agenda operativa, finanzas e inventario, fidelidad/formularios y comisiones (ver `docs/`).
  - `016_clientes_membresia.sql` / `_rollback.sql` / **`016_….guard.php`**: tabla `clientes` (membresía de cliente: `estado`, `origen`) con retrocarga que conserva suspensiones y no eleva privilegios; `clientes_resguardo_016` conserva las membresías al revertir y las restaura al reaplicar.
  - `017_idempotencia_unicidad.sql` / `_rollback.sql` / **`017_….precheck.php`**: tabla `operaciones_idempotentes` (clave SHA-256, huella HMAC, actor, expiración) y `UNIQUE` funcional `uq_usuarios_email_normalizado` sobre `LOWER(TRIM(email))`. Requiere MySQL ≥ 8.0.13. El archivo `.precheck.php` se ejecuta **antes** de alterar el esquema y aborta con el detalle si existen correos equivalentes (no borra ni fusiona).
  - `018_colaboradores_invitaciones.sql` / `_rollback.sql`: `colaboradores` (ficha laboral, estado, rol), `colaborador_invitaciones` (una pendiente por colaborador), `correos_salida` (outbox con carga cifrada y clave única por causa) y `profesionales.colaborador_id`. Retrocarga cada acceso laboral existente como colaborador (roles y suspensiones intactos). Los rollbacks de la 017 y la 018 llevan `.guard.php`.
  - `018_colaboradores_invitaciones.sql` / `_rollback.sql`: `colaboradores` (ficha laboral, estado, rol), `colaborador_invitaciones` (una pendiente por colaborador), `correos_salida` (outbox con carga cifrada y clave única por causa) y `profesionales.colaborador_id`. Retrocarga cada acceso laboral existente como colaborador (roles y suspensiones intactos).
  - `021_citas_reasignaciones.sql` / `_rollback.sql`: tabla `citas_reasignaciones` (auditoría de reasignación/cancelación de citas por baja de un profesional). Incluye también `profesionales_homonimos_decisiones` (auditoría de solo anexado del aviso de homónimos de profesionales). El rollback **conserva** los datos: con filas renombra cada tabla a `*_respaldo_021`.

---

## 2. Uso del Migrador

> Una migración puede acompañarse de `NNN_nombre.precheck.php`: devuelve una función `fn(mysqli): string[]`; si la lista no está vacía el migrador **no aplica nada** y sale con código 1.
> Para instalar o actualizar con el primer propietario en un solo paso usa `php scripts/instalar.php` (ver `docs/GUIA_ACTUALIZACION_IDENTIDADES.md`).

```bash
# Consultar estado de migraciones
php database/migrador.php status

# Aplicar migraciones pendientes
php database/migrador.php up

# Revertir migraciones registradas (alcance: procesa todas las migraciones con rollback en orden inverso).
# Se NIEGA (código 2, sin tocar nada) si la reversión destruiría datos de negocio. Ver "Reversión segura" abajo.
php database/migrador.php down
```

> **Alcance del comando `down`:** Actualmente `php database/migrador.php down` procesa en orden cronológico inverso (`rsort`) **todas** las migraciones registradas en la tabla `migraciones` que cuenten con su respectivo archivo `*_rollback.sql`, revirtiendo el esquema completo hacia el estado base, no únicamente la última migración aplicada. Si en producción o desarrollo se requiere revertir de forma granular una sola migración intermedia, ejecute manualmente el archivo SQL de rollback específico (`database/migrations/<archivo>_rollback.sql`) y actualice la tabla de control `migraciones`.

### Reversión segura (`down`)

`down` ejecuta primero un análisis **sin efectos** de los datos que se perderían y se niega a continuar (código de salida `2`) salvo que se indique, migración por migración,
`--aceptar-perdida=<migracion>` **y** un respaldo verificable `--respaldo=<archivo.sql.gz>` (de `scripts/backup_db.php`, con su manifiesto SHA-256, de esta base, del esquema actual, de las últimas 24 horas).

```bash
php database/migrador.php down --simular                                  # informa; no modifica nada
php database/migrador.php down --hasta=016_clientes_membresia \
  --aceptar-perdida=016_clientes_membresia --aceptar-perdida=017_idempotencia_unicidad \
  --aceptar-perdida=018_colaboradores_invitaciones --respaldo=storage/respaldos/antes.sql.gz
```

* `--hasta=NNN_nombre`: revierte solo esa migración y las posteriores.
* Detección de pérdida: un `NNN_nombre.guard.php` (`fn(mysqli): array` de `['mensaje'=>..., 'tabla'=>...]`; vacío = seguro) tiene prioridad; sin él se analiza el SQL de
  `NNN_nombre_rollback.sql` (`DROP TABLE` / `DROP COLUMN`) contra los datos reales. Datos efímeros declarados en `database/migrador_reversion.php` no bloquean.
* 016: el rollback copia las membresías a `clientes_resguardo_016` (persistente) y la 016 las restaura al reaplicar; las suspensiones no se reactivan.
* Guía completa, comandos para Windows/Docker y Linux e inventario de rollbacks: `docs/GUIA_ACTUALIZACION_IDENTIDADES.md` §7.

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

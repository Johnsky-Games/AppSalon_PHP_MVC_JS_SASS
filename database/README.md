# Sistema de Migraciones de Base de Datos — AppSalon

Este directorio contiene las migraciones versionadas y scripts de soporte para la evolución controlada del esquema de datos en AppSalon.

---

## 1. Estructura de Archivos

- `database/schema_base.sql`: Esquema base inicial del proyecto (tablas `usuarios`, `servicios`, `citas`, `citasservicios`).
- `database/migrador.php`: Script CLI para inspeccionar (`status`), aplicar (`up`) y revertir (`down`) migraciones.
- `database/migrations/`:
  - `001_security_hardening.sql`: Migración base de columnas de tokens criptográficos (`token_hash`, `token_tipo`, `token_expira`) y tabla `intentos_login`.
  - `001_security_hardening_rollback.sql`: Reversión de la migración 001.
  - `002_innodb_and_rate_limit_window.sql`: Migración incremental que convierte tablas a motor InnoDB y añade `primera_peticion` a `intentos_login`.
  - `002_innodb_and_rate_limit_window_rollback.sql`: Reversión de la migración 002.

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

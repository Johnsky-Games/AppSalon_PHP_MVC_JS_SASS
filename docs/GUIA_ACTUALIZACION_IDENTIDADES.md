# Guía: actualizar a identidades (clientes / equipo) en Windows (Git Bash) y Docker Compose

Todos los comandos son copiables y **no dependen de otra conversación**. No se borran volúmenes ni se reemplaza `includes/.env`.

## 0. Antes de empezar

* Esta entrega añade las migraciones **016** (clientes), **017** (idempotencia + UNIQUE de correo normalizado) y **018** (colaboradores, invitaciones, correos de salida).
  Requiere **MySQL 8.0.13 o superior** (índices funcionales); la imagen `mysql:8.0` de Compose lo cumple. MariaDB no se soporta para la 017.
* **El síntoma «cuenta de acceso inactiva» se produce cuando el código nuevo corre sobre una base sin migrar.** Con esta versión ese caso responde
  `503` con un mensaje de mantenimiento y deja en el registro del servidor la pista `ESQUEMA DESACTUALIZADO` (nunca más «contacta al administrador»).
* Conserva tu `includes/.env`. Solo **añade** las variables nuevas (ver §3). No copies `includes/.env.example` encima.

## 1. Comprobar el estado real (solo lectura)

```bash
# Git Bash en la carpeta del proyecto
git fetch origin --prune
git status --short --branch
docker compose --env-file includes/.env -p appsalon-validacion ps -a
docker compose --env-file includes/.env -p appsalon-validacion exec web php database/migrador.php status
docker compose --env-file includes/.env -p appsalon-validacion exec web php scripts/diagnosticar_duplicados.php
```

Si `web` no arranca por el puerto de MySQL (`bind ... 33060 denegado`), define `DB_EXTERNAL_PORT=33061` en `includes/.env`. No es un problema de roles ni de contraseña.

## 2. Respaldo previo (obligatorio en una base con datos)

```bash
mkdir -p storage/respaldos
docker compose --env-file includes/.env -p appsalon-validacion exec web \
  php scripts/backup_db.php --output=storage/respaldos/antes-identidades.sql.gz --gzip
sha256sum storage/respaldos/antes-identidades.sql.gz   # guarda este hash
```

## 3. Variables nuevas en `includes/.env`

```ini
APP_KEY="<cadena aleatoria de 64 caracteres hexadecimales>"   # php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
OWNER_EMAIL="tu-correo@dominio.com"                             # solo si aún no existe un propietario
OWNER_NOMBRE="Tu nombre"
OWNER_APELLIDO="Tu apellido"
# APPSALON_OWNER_PASSWORD  → opcional; si lo omites recibes un enlace de activación de un solo uso.
EMAIL_PASS="<contraseña SMTP>"      # ahora también se reconoce EMAIL_PASSWORD
```

`APP_KEY` es **obligatoria** con `APP_ENV=production` (el instalador se detiene sin ella). No la publiques ni la subas al repositorio.

## 4. Actualizar el código y migrar

```bash
git switch claude/blissful-hopper-f5k7a0          # o la rama que recibas
docker compose --env-file includes/.env -p appsalon-validacion build web
# Aplica migraciones sobre la base existente (con tu respaldo ya hecho):
docker compose --env-file includes/.env -p appsalon-validacion run --rm instalador php scripts/instalar.php --migrar
docker compose --env-file includes/.env -p appsalon-validacion up -d db web
```

Qué hace el instalador sobre una base **poblada**: aplica 016–018 (con la pre-validación de la 017: si hay correos equivalentes **se detiene sin tocar nada** y lista los ids),
convierte cada acceso laboral existente en ficha de colaborador conservando roles y suspensiones, crea la membresía de cliente de quienes ya reservaron o tienen rol cliente,
y comprueba si hay un propietario activo. **No cambia contraseñas ni roles ni reactiva cuentas suspendidas.**
Un administrador heredado (columna `admin=1`) cuenta como propietario: no se emite activación.

En una instalación **nueva** (volumen vacío) basta `docker compose up -d`: el servicio `instalador` migra y aprovisiona al propietario antes de arrancar `web`.

## 3b. Primer acceso del propietario (sin INSERT manual ni contraseña publicada)

Con `OWNER_EMAIL` definido y sin propietario, el instalador imprime **una vez** un enlace como `http://localhost:8085/equipo/aceptar?token=…`
(también lo envía por correo si hay SMTP). Ábrelo, elige tu contraseña y entra por **`/equipo/login`**.
Si lo pierdes: `docker compose … run --rm instalador php scripts/instalar.php --regenerar-activacion` (invalida el anterior).

## 5. Verificación posterior

```bash
docker compose --env-file includes/.env -p appsalon-validacion exec web php database/migrador.php status   # 018 aplicada
docker compose --env-file includes/.env -p appsalon-validacion exec web php scripts/instalar.php --comprobar # "Esquema al día."
docker compose --env-file includes/.env -p appsalon-validacion exec web php scripts/diagnosticar_duplicados.php
```

Prueba manual mínima: registrar una cuenta en `/cliente/registro` → confirmar desde el correo → entrar en `/cliente/login` → reservar (sin intervención del administrador);
entrar como propietario por `/equipo/login` → *Equipo y permisos* → invitar a una persona de prueba **con un correo tuyo** (no a personas reales).

## 6. Duplicados que ya existan

`php scripts/diagnosticar_duplicados.php [--json]` lista candidatos con evidencia (citas, servicios, comisiones, cercanía de la fecha de alta). **No borra ni fusiona nada.**
Criterio recomendado: conserva la ficha con historial o cuenta vinculada y **desactiva** (no borres) la otra; antes revisa citas futuras para no crear solapamientos.

## 7. Revertir

* Datos: restaura el respaldo en una base nueva (`php scripts/restore_db.php --db=<nueva> --input=<archivo> --hash=<sha256>`), verifica y recién entonces apunta `DB_NAME` a ella.
* Esquema: `php database/migrador.php down` revierte **todas** las migraciones; no lo ejecutes sobre tu base. Para deshacer solo estas tres, ejecuta manualmente
  `018_…_rollback.sql`, `017_…_rollback.sql` y `016_…_rollback.sql` (en ese orden) y borra sus filas de `migraciones`. Hazlo solo en una copia.

## 8. Compilar estilos (Windows, ruta con espacios y `&`)

```bash
MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W):/app" -w /app composer:2 composer install --no-interaction
npm.cmd ci
npm.cmd run build
```

`public/build` está versionado: si Git se queja al cambiar de rama, respáldalo con `git stash push -m "respaldo compilacion" -- public/build/` y **no** lo apliques en la rama nueva.

## 9. Worker de correos

Las invitaciones se intentan enviar al guardarse (espera máxima ≈10 s si el SMTP no responde). Si el envío falla, el evento queda en la cola `correos_salida`; **ver la pantalla del equipo no envía correos**.
Para reintentos automáticos con retroceso exponencial y para depurar las claves de idempotencia vencidas (30 días), el worker de notificaciones ya ejecuta `scripts/procesar_correos.php` en cada ciclo:

```bash
docker compose --env-file includes/.env -p appsalon-validacion --profile worker up -d notificaciones
# o, manualmente / por cron:
docker compose --env-file includes/.env -p appsalon-validacion exec web php scripts/procesar_correos.php
```
Sin worker, usa el botón **Reenviar invitación** (genera un enlace nuevo y lo envía de inmediato).

## 10. Avisos conocidos

* `php database/migrador.php down` o aplicar `016_clientes_membresia_rollback.sql` **elimina** la tabla `clientes` y, al reaplicar, las suspensiones de clientes explícitas se pierden (solo se rederiva `usuario_accesos.activo`). No lo uses en producción; para volver atrás restaura el respaldo.
* El registro público responde «El usuario ya está registrado» para un correo existente (comportamiento histórico, limitado por intentos por IP/correo). Eso permite saber si un correo —incluido uno del personal— tiene cuenta. Es un riesgo aceptado y documentado; endurecerlo implicaría un flujo de «te enviamos un correo» distinto.

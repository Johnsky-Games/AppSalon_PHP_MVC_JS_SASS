# Identificar tu entorno local real antes de actualizar (ronda 5)

> **Estado honesto.** Esta guía la preparó una sesión en la nube que **no puede ver tu equipo**: no sabe cuál de tus dos entornos (`appsalon-db` y `appsalon-db-stack`) atiende `http://localhost:8085`, qué versión del código ejecuta ni qué cubre tu respaldo. Los nombres **no** prueban nada: se identifica por configuración real (puertos, montajes, variables, red, datos). Todos los comandos de las secciones 1 a 4 son de **solo lectura**. Ninguno usa `reset --hard`, `down -v` ni borra volúmenes; ninguno imprime contraseñas.

Convención: PowerShell (Windows). En Git Bash es igual salvo comillas y `curl`. Sustituye `<WEB>` y `<DB>` por los nombres reales que obtengas.

## 1. Qué contenedor atiende el puerto 8085

```powershell
docker ps -a --format "table {{.Names}}`t{{.Image}}`t{{.Status}}`t{{.Ports}}"
docker ps --filter "publish=8085" --format "{{.Names}}"          # el contenedor WEB que publica 8085
docker compose ls                                                # proyectos Compose y su carpeta de configuración
docker volume ls
docker network ls
```

Con `<WEB>` ya identificado (el que publica 8085):

```powershell
# Carpeta del proyecto que ese contenedor monta (bind mount = tu copia local de trabajo) y proyecto Compose
docker inspect <WEB> --format "{{json .Mounts}}"
docker inspect <WEB> --format "{{index .Config.Labels \"com.docker.compose.project\"}} | {{index .Config.Labels \"com.docker.compose.project.working_dir\"}}"
# Variables que importan (se filtran DB_PASS, APP_KEY y cualquier secreto: NO las pegues)
docker inspect <WEB> --format "{{range .Config.Env}}{{println .}}{{end}}" | Select-String "^(APP_ENV|APP_URL|DB_HOST|DB_PORT|DB_NAME|APPSALON_AUTO_MIGRATE)="
# Redes a las que pertenece y qué contenedores comparten esa red
docker inspect <WEB> --format "{{json .NetworkSettings.Networks}}" | Select-String '"(NetworkID|IPAddress)"' -NotMatch
docker network inspect <RED_DE_WEB> --format "{{range .Containers}}{{.Name}} {{end}}"
```

`DB_HOST` suele ser `db` (nombre del servicio); resuelve **dentro de la red Compose** al contenedor de base de datos de **ese mismo proyecto**. Los nombres `appsalon-db` y `appsalon-db-stack` solo dicen cuál es el `container_name`; lo que manda es **qué base de datos ve el web**. El contenedor de BD de ese proyecto aparece en el último comando. Con él:

```powershell
docker inspect <DB> --format "{{json .Mounts}}"                  # volumen de datos real (nombre y ruta)
docker inspect <DB> --format "{{range .Config.Env}}{{println .}}{{end}}" | Select-String "^MYSQL_DATABASE="
docker inspect <DB> --format "{{json .NetworkSettings.Ports}}"   # puerto externo (por defecto 127.0.0.1:33060)
```

### Prueba de que `<DB>` es la base que usa la pantalla de 8085 (solo lectura)

La contraseña se te pide en pantalla (no la escribas en el comando):

```powershell
docker exec -it <DB> mysql -uroot -p -e "SHOW DATABASES; USE <NOMBRE_BD_DEL_PASO_ANTERIOR>; SELECT COUNT(*) AS usuarios FROM usuarios; SELECT COUNT(*) AS citas, MAX(fecha) AS ultima_fecha FROM citas; SELECT migracion, aplicada_en FROM migraciones ORDER BY id DESC LIMIT 3;"
```

Compara con lo que **ves** en `http://localhost:8085` (por ejemplo, la última cita en la agenda). Si el conteo y la fecha no coinciden con lo que ves, **el otro** contenedor de BD es el activo: repite la sección con el otro par. Si ambos coinciden en parte (dos copias de la misma base), anótalo y no toques ninguno hasta decidir cuál es la fuente de verdad.

Anota en tu informe: nombre del contenedor web, del de BD, proyecto Compose, carpeta de trabajo, volumen, nombre de la base, y si hay un segundo entorno activo o detenido.

## 2. Qué versión del código ejecuta y si hay cambios locales

Desde la carpeta de trabajo que obtuviste en `project.working_dir`:

```powershell
cd "<working_dir>"
git rev-parse --show-toplevel
git branch --show-current
git rev-parse HEAD                                # compara con el SHA entregado
git status --short --branch                       # cambios sin confirmar y ramas adelantadas/atrasadas
git stash list                                    # stashes (no se tocan)
git fetch origin --prune
git rev-list --left-right --count HEAD...origin/claude/blissful-hopper-f5k7a0    # "adelantados  atrasados"
git cat-file -t d8cf704 2>$null; git branch -a --contains d8cf704 2>$null         # ¿existe tu commit de documentación local?
git log --oneline --decorate -5
```

Si `adelantados` > 0, si `d8cf704` existe, o si hay cambios sin confirmar en archivos que esta rama también toca, **no actualices todavía**: respalda (sección 4) y consulta antes de integrar.

## 3. Estado de migraciones de la base real

```powershell
docker exec <WEB> php database/migrador.php status
docker exec <WEB> php scripts/instalar.php --comprobar
```

`PENDIENTE` = migración que existe en el código montado y aún no está aplicada a **esa** base. Con el código actual de esta rama esperas ver aplicadas hasta la 022; esta ronda añade 023 a 026 (se aplican solo cuando tú lo decides, con respaldo previo).

## 4. Verifica el respaldo existente y genera uno nuevo antes de tocar la base

El respaldo `C:\Users\jonat\AppSalon-Respaldos\respaldo_20261014_221156` es **anterior** a todo lo trabajado después de esa fecha y hora; no se asume que cubra cambios posteriores.

```powershell
cd "C:\Users\jonat\AppSalon-Respaldos\respaldo_20261014_221156"
Get-ChildItem -Recurse | Select-Object FullName, Length, LastWriteTime | Format-Table -AutoSize
Get-FileHash -Algorithm SHA256 -Path (Get-ChildItem -Recurse -File).FullName | Format-Table Hash, Path -AutoSize
Get-Content *.manifest.json -ErrorAction SilentlyContinue | Select-Object -First 40     # si lo generó scripts/backup_db.php
```

Comprueba que existe: el volcado de la base (`.sql` o `.sql.gz`), una copia de `includes/.env` (en un lugar que no se publique), los cambios locales (`.patch`) y el SHA. **Restáuralo solo en una base nueva y aparte** (nunca sobre la real) para saber si sirve:

```powershell
# En el proyecto, con el contenedor <WEB>; <NUEVA_BD> debe llamarse distinto de la real y de cualquier otra
docker exec <WEB> php scripts/restore_db.php --db=<NUEVA_BD> --input=/ruta/dentro/del/contenedor/archivo.sql.gz --hash=<SHA256_DEL_ARCHIVO> --create-db
docker exec -it <DB> mysql -uroot -p -e "USE <NUEVA_BD>; SELECT COUNT(*) FROM usuarios; SELECT COUNT(*) FROM citas;"
```

Compara esos conteos con los de la base real (sección 1). Diferencias esperables: lo creado **después** del respaldo.

**Respaldo nuevo (obligatorio antes de aplicar migraciones o cambiar de versión):**

```powershell
$TS = Get-Date -Format "yyyyMMdd-HHmmss"
New-Item -ItemType Directory -Force "storage\respaldos" | Out-Null
git rev-parse HEAD                    | Out-File "storage\respaldos\antes-ronda5-$TS.commit.txt"
git status --short --branch           | Out-File "storage\respaldos\antes-ronda5-$TS.status.txt"
git diff                              | Out-File "storage\respaldos\cambios-locales-$TS.patch"
git diff --cached                     | Out-File "storage\respaldos\cambios-staged-$TS.patch"
Copy-Item includes\.env "storage\respaldos\env-$TS.bak"        # dentro de storage\ (ignorada por git)
docker exec <WEB> php scripts/backup_db.php --gzip --output=storage/respaldos/antes-ronda5-$TS.sql.gz
Get-FileHash -Algorithm SHA256 "storage\respaldos\antes-ronda5-$TS.sql.gz"                # anota el hash
Get-ChildItem storage\respaldos | Sort-Object LastWriteTime | Select-Object -Last 8
```

Copia también `storage\respaldos\antes-ronda5-$TS.*` a `C:\Users\jonat\AppSalon-Respaldos\` (fuera del repositorio). Con ese respaldo hecho y verificado, sigue `docs/GUIA_ACTUALIZACION_RONDA5.md`.

## 5. Lo que no se puede afirmar desde la nube

* Qué entorno es el «bueno» ni cuál sirve el puerto 8085 en tu equipo (lo determinas con la sección 1).
* Que tu copia local contenga o no el commit `d8cf704` (este clon en la nube no lo tiene).
* Que el respaldo `respaldo_20261014_221156` sea restaurable (se comprueba restaurándolo en una base nueva, sección 4).

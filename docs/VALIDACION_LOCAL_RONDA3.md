# Validación de la instalación local (ronda 3 y 4) — comandos exactos para TU equipo

> **Estado honesto.** Quien preparó esta guía trabaja en una máquina virtual en la nube (Linux, sin Docker en ejecución, sin tu `includes/.env`, sin tus volúmenes ni tus buzones).
> **No pudo acceder a tu equipo, por lo que tu instalación local NO está validada.** Esta guía te da, en orden, los comandos para validarla tú y una tabla para devolver los resultados.
> Lo que sí se ejecutó en la nube (suites automáticas, CI y un ensayo del procedimiento) está en `docs/ENTREGA_RONDA3.md` y al final de esta guía; **no sustituye** lo que compruebes tú.

Reglas que cumplen todos los comandos: no se usa `git reset --hard`, ni `docker compose down -v`, ni se borra ningún volumen; `includes/.env` y las contraseñas no se imprimen ni se reemplazan; los correos van **solo a buzones tuyos**.

Convenciones (Git Bash en Windows; en Linux/macOS son iguales). En PowerShell, cambia `export X=…` por `$X="…"` y usa `curl.exe`.

```bash
cd "RUTA\A\TU\PROYECTO"            # la carpeta que contiene docker-compose.yml y includes/.env
export RAMA=claude/blissful-hopper-f5k7a0
export PROYECTO=appsalon-validacion      # ajusta tras el paso 1 si tu proyecto Compose se llama distinto
export COMPOSE="docker compose --env-file includes/.env -p $PROYECTO"
export WEB=http://localhost:8085         # WEB_EXTERNAL_PORT de tu .env (por defecto 8085)
```

## 1. Inspección (solo lectura; pega la salida en tu informe)

```bash
pwd && git rev-parse --show-toplevel
git branch --show-current && git rev-parse HEAD
git status --short --branch               # cambios locales sin confirmar y ramas adelantadas/atrasadas
git stash list                            # stashes existentes (no se tocan)
git remote -v
git fetch origin --prune
git rev-list --left-right --count HEAD...origin/$RAMA      # "ahead  behind"
git log --oneline --decorate -3 origin/$RAMA               # debe mostrar el SHA a validar
docker compose ls                                           # nombre real del proyecto Compose
docker ps -a --format "table {{.Names}}\t{{.Image}}\t{{.Status}}\t{{.Ports}}"
docker volume ls
ls -la includes/.env storage 2>&1 | head -5                # solo comprueba que existen; NO los muestres
$COMPOSE ps -a
$COMPOSE exec web php database/migrador.php status          # qué migraciones están APLICADAS / PENDIENTES
```

Anota: rama actual, SHA, si hay cambios locales, nombre de proyecto Compose (`docker compose ls`) y qué migraciones aparecen **PENDIENTE**.
Si `ahead` es mayor que 0 (commits locales no publicados) o hay cambios sin confirmar en archivos que esta rama también cambia, **detente** y consúltalo: no resuelvas conflictos a ciegas.

## 2. Respaldo antes de actualizar (obligatorio)

```bash
mkdir -p storage/respaldos                        # storage/ está en .gitignore: nunca se publica
TS=$(date +%Y%m%d-%H%M%S)
git rev-parse HEAD                      > storage/respaldos/antes-ronda3-$TS.commit.txt
git status --short --branch             > storage/respaldos/antes-ronda3-$TS.status.txt
git diff                                > storage/respaldos/cambios-locales-$TS.patch
git diff --cached                       > storage/respaldos/cambios-staged-$TS.patch
git ls-files --others --exclude-standard -z | xargs -0 -r tar -czf storage/respaldos/sin-seguimiento-$TS.tgz
cp -p includes/.env storage/respaldos/env-$TS.bak     # DENTRO de storage/ (ignorada). No lo copies junto a includes/.env: ese patrón no lo ignora git.
MSYS_NO_PATHCONV=1 $COMPOSE exec web php scripts/backup_db.php --gzip --output=storage/respaldos/antes-ronda3-$TS.sql.gz
sha256sum storage/respaldos/antes-ronda3-$TS.sql.gz   # anota el hash (PowerShell: Get-FileHash -Algorithm SHA256 <archivo>)
ls -l storage/respaldos | tail -8
```

Comprobación: existen el `.sql.gz` y su `.manifest.json`. Para restaurar en una base **nueva** (nunca sobre la actual): `php scripts/restore_db.php --db=<nueva> --input=<archivo> --hash=<sha256>`.

## 3. Actualizar el código (sin reset ni borrados)

```bash
git switch $RAMA                              # si falla por cambios locales, DETENTE (tienes el respaldo del paso 2)
git merge --ff-only origin/$RAMA              # solo avance rápido; si no es posible, no fuerza nada
git rev-parse HEAD                            # debe coincidir con el SHA a validar
git status --short --branch
```

Dependencias y frontend: **no cambiaron** `composer.json`, `composer.lock`, `package.json` ni `Dockerfile` desde la entrega anterior, y `public/build/*` viene ya compilado en el repositorio.
Compilar de nuevo es opcional: `npm ci && npm run build` (en Windows con ruta con espacios o `&`, ver `GUIA_ACTUALIZACION_IDENTIDADES.md` §8) y `git status` no debería mostrar cambios en `public/build`.
Si tu `vendor/` no existe o falla el arranque por clases faltantes: `composer install --no-dev --prefer-dist` (en el host o con `$COMPOSE run --rm web composer install …` si tu imagen lo trae).

## 4. Migraciones (comprueba antes de ejecutar)

La **ronda 3 y la 4 no añaden migraciones**. Pero si tu instalación venía de la entrega de la ronda 1, te faltarán las de la **ronda 2**: 019, 021 y 022.

```bash
$COMPOSE exec web php database/migrador.php status
```

* Todo `[APLICADA]` hasta `022_crm_auditoria_consentimiento_homonimos` → **no ejecutes nada**.
* Aparecen `[PENDIENTE]` 019/021/022 → con el respaldo del paso 2 ya hecho:

```bash
$COMPOSE run --rm instalador php scripts/instalar.php --migrar
$COMPOSE exec web php database/migrador.php status
$COMPOSE exec web php scripts/instalar.php --comprobar      # debe decir «Esquema al día.»
```

La 022 crea *triggers*: el usuario de la base necesita `TRIGGER`; si no, el *precheck* se detiene con un mensaje claro y no deja nada a medias. Si alguna migración falla, **no** sigas: pega el mensaje y restaura desde el respaldo en una base nueva si hace falta.

## 5. Levantar y comprobar web, MySQL y worker

```bash
docker volume ls                                          # compara con el paso 1: deben ser los mismos volúmenes
$COMPOSE build web
$COMPOSE up -d db web notificaciones                      # recrea contenedores; los volúmenes (datos) se conservan
$COMPOSE ps
$COMPOSE exec db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqladmin ping -h127.0.0.1 -uroot'   # mysqld is alive
curl -sI $WEB/ | head -1                                  # HTTP/1.1 200 OK
curl -sI $WEB/cliente/login | head -1
curl -sI $WEB/equipo/login | head -1
$COMPOSE logs --tail 40 web
$COMPOSE logs --tail 40 notificaciones                    # debe mostrar ciclos del worker, sin errores repetidos
$COMPOSE exec web php scripts/procesar_correos.php --estado   # sin eventos «fallido» (código 0)
```

Variables de entorno a revisar (sin imprimir contraseñas): `grep -E '^(APP_ENV|APP_KEY|CORREOS_ENVIO_INMEDIATO|EMAIL_HOST|EMAIL_PORT)=' includes/.env | sed 's/APP_KEY=.*/APP_KEY=<definida>/'`.
Debe ser `APP_ENV=production` exacto en producción (en una instalación local de prueba puede ser `development`), `APP_KEY` definida y `CORREOS_ENVIO_INMEDIATO` ausente o `0`.

## 6. Comprobaciones funcionales (con TUS cuentas y buzones)

Define alias de tu propio correo (si tu proveedor lo permite, `tu+ra3a@dominio.com`, `tu+ra3b@…`): `CLIENTE_A`, `CLIENTE_B`, `EQUIPO_RECEPCION`, `EQUIPO_PROFESIONAL`. **No uses buzones de otras personas.**
Contraseña de la base para consultas (no se imprime): `DB_NAME=$(grep -E '^DB_NAME=' includes/.env | cut -d= -f2- | tr -d '"'); DB_PASS=$(grep -E '^DB_PASS=' includes/.env | cut -d= -f2- | tr -d '"')`.
Consulta rápida: `sql(){ $COMPOSE exec -T -e MYSQL_PWD="$DB_PASS" db mysql -uroot "$DB_NAME" -e "$1"; }`.

| # | Comprobación | Pasos | Resultado esperado |
|---|---|---|---|
| 6.1 | **Registro y correo** | `$WEB/cliente/registro` con `CLIENTE_A`; luego espera ≤ 10 s | Pantalla «Revisa tu correo»; llega 1 correo «Confirma tu cuenta» (la contraseña del formulario es provisional). `sql "SELECT estado,tipo FROM correos_salida ORDER BY id DESC LIMIT 3"` → `enviado` |
| 6.2 | **Un escáner no activa** | `curl -sI "<enlace>"` y `curl -s -o /dev/null -w "%{http_code}\n" "<enlace>"` | 200 y `sql "SELECT confirmado FROM usuarios WHERE email='…'"` sigue en `0` |
| 6.3 | **Activación por POST** | Abre el enlace; elige una contraseña **distinta** a la del registro (y, desde la ronda 4, revisa/corrige nombre, apellido y teléfono); envía | «Tu cuenta quedó activada»; `confirmado=1`; no inicia sesión sola |
| 6.4 | **Login de cliente** | `$WEB/cliente/login` con la contraseña del registro, luego con la elegida | La del registro: rechazada. La elegida: entra a `/cita` |
| 6.5 | **Enlace usado** | Abre otra vez el mismo enlace | Pantalla genérica «no válido o ya utilizado», sin formulario |
| 6.6 | **Pre-registro ajeno (ataque)** | Registra `CLIENTE_B` con contraseña `A…`; vuelve a registrarlo con `B…`; activa desde el buzón con `C…` | Solo `C…` entra; `A…` y `B…` no |
| 6.7 | **Login de equipo** | `$WEB/equipo/login` con el propietario; intenta entrar con `CLIENTE_A` | Propietario entra a `/admin`; el cliente es rechazado en el portal de equipo (y viceversa) |
| 6.8 | **Invitación** | `/admin/equipo` → invitar a `EQUIPO_RECEPCION` (rol Recepción); abre el enlace de `/equipo/aceptar` (`curl -sI` no debe consumirlo); acepta con contraseña | Correo recibido; el GET solo muestra la ficha; tras aceptar entra por `/equipo/login` |
| 6.9 | **Permisos** | Con Recepción: abre `/admin/finanzas` y `/admin/equipo` (y cualquier ruta que su rol no tenga en la matriz de permisos de `GUIA_INTERFAZ.md`/`AuthorizationService::ROLES`) | 403 en las que su rol no permite; `/admin` (agenda) sí |
| 6.10 | **Profesional** | Propietario invita a `EQUIPO_PROFESIONAL` con rol Profesional, eligiendo servicios, franjas semanales y descansos | La ficha nace **inactiva** (no sale en `/cita`) hasta que acepte; después aparece con su horario |
| 6.11 | **Reserva** | Como `CLIENTE_A`: `/cita` → servicio → profesional → horario | Cita creada; correo de confirmación; visible en `/admin?fecha=…` |
| 6.12 | **Reasignación** | Crea un 2.º profesional con el mismo servicio. Con el propietario: `$WEB/admin/equipo/reasignar?id=<id del colaborador 1>` | Lista la cita futura, propone al sustituto disponible; al reasignar: `sql "SELECT accion,profesional_origen,profesional_destino FROM citas_reasignaciones"` → 1 fila; el cliente recibe 1 correo «cambió de profesional»; la baja solo se confirma sin citas sin resolver |
| 6.13 | **CRM** | `/admin/clientes` (listado), «Exportar», luego `/admin/clientes/auditoria` | La exportación genera 1 fila de auditoría (sin datos personales); un rol sin permiso recibe 403 |
| 6.14 | **Worker detenido** | `$COMPOSE stop notificaciones`; registra otro alias; `…procesar_correos.php --estado`; `$COMPOSE start notificaciones` | Con el worker parado el correo queda `pendiente`; al arrancar llega solo |
| 6.15 | **Entorno** | `grep CORREOS_ENVIO_INMEDIATO includes/.env` | Ausente o `0`; con `APP_ENV=production` se ignora aunque valga 1 |

## 7. Qué devolver (plantilla)

| Paso | Comprobado (cómo y salida) | Fallido (síntoma, log, SHA) | Pendiente / no ejecutado (motivo) |
|---|---|---|---|
| 1 Inspección | | | |
| 2 Respaldo (archivos + hash) | | | |
| 3 Actualización (SHA final) | | | |
| 4 Migraciones | | | |
| 5 Web / MySQL / worker | | | |
| 6.1 … 6.15 | | | |

Si algo falla: **no repitas comandos destructivos**. Guarda `$COMPOSE logs --tail 200 web notificaciones`, el paso exacto y el SHA, y pégalos. Para volver atrás: los respaldos del paso 2 (código: `git switch <rama anterior>` con el SHA anotado; datos: restauración en una base nueva).

## 8. Ensayo realizado en la nube (NO es tu instalación)

`__ENSAYO__`

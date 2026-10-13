# Guía: actualizar a la ronda 2 (Windows + Docker Compose, también Linux/macOS)

Complementa `docs/GUIA_ACTUALIZACION_IDENTIDADES.md` (ronda 1: migraciones 016–018). Esta guía cubre las migraciones **019, 021 y 022**,
el worker de correo, el registro con respuesta uniforme, la reversión segura, el asistente del profesional, la reasignación de citas y las novedades del CRM.
Todos los comandos son copiables. **No se borran volúmenes, no se reemplaza `includes/.env`, no se envía correo a personas reales.**

> Convención: `COMPOSE` = `docker compose --env-file includes/.env -p appsalon-validacion`. Cámbialo por tu nombre de proyecto si usas otro.
> En **Git Bash** antepón `MSYS_NO_PATHCONV=1` a los comandos que reciben rutas (`--output=storage/...`). En **PowerShell** usa
> `$COMPOSE = "docker compose --env-file includes/.env -p appsalon-validacion"` y `Invoke-Expression "$COMPOSE exec web php ..."`, o escribe el comando completo.

## 0. Qué cambia (resumen operativo)

| Migración | Qué hace | ¿Pierde datos al revertir? |
|---|---|---|
| **019** `correos_cola_usuario` | Columna `usuario_id` e índice en la cola `correos_salida` (cancelar enlaces obsoletos por usuario). | No (solo retira columna e índice). |
| **021** `citas_reasignaciones` | Historial de reasignaciones y decisiones sobre homónimos de profesionales (solo anexado). | Renombra a `*_respaldo_021` si hay filas; nunca las borra. |
| **022** `crm_auditoria_consentimiento_homonimos` | Auditoría de exportaciones CRM, versiones e historial de consentimiento, decisiones de homónimos de clientes (solo anexado, con *triggers*). | `down` se niega mientras haya datos de negocio. |

Requisitos: MySQL **8.0.13+** (índices funcionales de la 017). La 022 crea *triggers*: el usuario de la aplicación necesita `TRIGGER` (con *binary log* activo, además
`log_bin_trust_function_creators=1` o `SUPER`). Si no los tiene, el *precheck* detiene la migración con un mensaje claro y no deja nada a medias.

El servicio `notificaciones` (worker de recordatorios **y de la cola de correos**) ahora arranca con `docker compose up`; antes dependía de `--profile worker`.

## 1. Estado real antes de tocar nada (solo lectura)

```bash
git fetch origin --prune
git status --short --branch
$COMPOSE ps -a
$COMPOSE exec web php database/migrador.php status
$COMPOSE exec web php scripts/diagnosticar_duplicados.php      # informa; NO borra ni fusiona
```

## 2. Respaldo previo (obligatorio en una base con datos)

```bash
mkdir -p storage/respaldos
MSYS_NO_PATHCONV=1 $COMPOSE exec web php scripts/backup_db.php --gzip --output=storage/respaldos/antes-ronda2.sql.gz
sha256sum storage/respaldos/antes-ronda2.sql.gz       # anota el hash (PowerShell: Get-FileHash -Algorithm SHA256 storage\respaldos\antes-ronda2.sql.gz)
```

## 3. Variables de `includes/.env`

Conserva tu archivo. Comprueba que existan (solo **añade** las que falten):

```ini
CORREOS_INTERVALO_SEGUNDOS=5                 # pasada del worker de correo (2-600)
CORREOS_ENVIO_INMEDIATO=0                   # 0 = solo cola + worker (recomendado)
APP_KEY="<64 caracteres hexadecimales>"      # php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"  — OBLIGATORIA fuera de desarrollo
EMAIL_HOST=...  EMAIL_PORT=...  EMAIL_USER=...  EMAIL_PASS=...    # SMTP real solo en producción; en pruebas usa un buzón simulado
```

Sin `APP_KEY` la cola de correos y la idempotencia responden **503** con una pista en el registro del servidor (no hay clave «de respaldo» en producción).

## 4. Actualizar y migrar

```bash
git switch claude/blissful-hopper-f5k7a0
$COMPOSE build web
$COMPOSE run --rm instalador php scripts/instalar.php --migrar      # aplica 019, 021 y 022 sobre la base existente
$COMPOSE up -d db web notificaciones                               # el worker de correos y recordatorios queda activo
$COMPOSE exec web php database/migrador.php status                 # 022 aplicada
$COMPOSE exec web php scripts/instalar.php --comprobar             # "Esquema al día."
```

Si compilas estilos en tu equipo: `npm ci && npm run build` (Windows con ruta con espacios o `&`: ver §8 de la guía de identidades).
`public/build/*` está versionado; si lo tocas, súbelo junto con el código.

## 5. Cola de correos de cuenta (confirmaciones y avisos)

Los correos de confirmación de cuenta, de recuperación de contraseña y los avisos de «ya tienes una cuenta» pasan por la cola persistente `correos_salida`:
se escriben en la misma transacción que el alta y los entrega el **worker** (`CORREOS_INTERVALO_SEGUNDOS`, 5 s por defecto), **no la petición HTTP**: así el tiempo de respuesta no delata si una cuenta existe. Si el SMTP falla, reintenta con espera creciente (60 s, 120 s, 240 s, 480 s, 960 s; 6 intentos ≈ 31 min). Con el worker detenido los correos no salen. `CORREOS_ENVIO_INMEDIATO=1` reactiva el intento en línea (solo pruebas).
**Garantía: «al menos una vez».** Si el servidor SMTP acepta el mensaje y la conexión se corta antes de la confirmación, se reintenta y el cliente puede recibir un duplicado.
No se promete entrega «exactamente una vez».

```bash
$COMPOSE exec web php scripts/procesar_correos.php --estado          # conteos por estado/tipo, sin datos personales (código 3 si hay fallidos)
$COMPOSE exec web php scripts/procesar_correos.php                  # procesar ahora
$COMPOSE exec web php scripts/procesar_correos.php --reintentar=todos-fallidos
$COMPOSE logs -f notificaciones                                     # ver el worker
```

SMTP caído no impide registrarse ni pedir un reenvío: el evento queda `pendiente` y se entrega cuando el SMTP vuelve. Tras 24 h el enlace vence y el evento se cancela solo.

## 6. Registro público con respuesta uniforme

`/cliente/registro` responde **lo mismo** para un correo nuevo, uno ya registrado (cliente confirmado o sin confirmar) y uno del personal: «Si el correo es válido, te enviamos instrucciones…».
Lo que cambia para el operador:

* El titular real de un correo ya registrado recibe un aviso (máximo uno por hora y correo). Ya no se ve «El usuario ya está registrado».
* Si quien registra el correo es otra persona, no obtiene ninguna señal de que la cuenta exista. Límite de intentos: 3 por correo y 10 por IP cada 15 minutos, igual en todas las ramas.
* Residuo conocido: un registro sobre un correo ajeno sin confirmar deja una cuenta sin confirmar con la contraseña de quien la creó; el titular la activa al confirmar. Es inherente a cualquier alta con confirmación por correo.

## 7. Revertir con seguridad (procedimiento probado)

`php database/migrador.php down` **se niega** (código 2, sin tocar nada) a revertir lo que destruiría datos de negocio. Para aceptarlo hay que indicar, migración por migración,
`--aceptar-perdida=<migración>` **y** `--respaldo=<archivo.sql.gz>` generado por `scripts/backup_db.php` (manifiesto SHA-256 de esta base, esquema actual, ≤ 24 h).
`--hasta=<migración>` limita la reversión; `--simular` solo informa.

Ejemplo: volver a reaplicar 019–022 en un entorno de **pruebas** sin perder nada:

```bash
$COMPOSE exec web php database/migrador.php down --hasta=019_correos_cola_usuario --simular       # lee qué bloquearía
MSYS_NO_PATHCONV=1 $COMPOSE exec web php scripts/backup_db.php --gzip --output=storage/respaldos/antes-de-revertir.sql.gz
# Solo las migraciones que el --simular marque como NO aceptadas:
MSYS_NO_PATHCONV=1 $COMPOSE exec web php database/migrador.php down --hasta=019_correos_cola_usuario \
  --aceptar-perdida=<migración> ... --respaldo=storage/respaldos/antes-de-revertir.sql.gz
$COMPOSE exec web php database/migrador.php up
```

* **016:** el *rollback* copia las membresías a `clientes_resguardo_016` (no se elimina) y el `up` restaura estados: **una suspensión nunca se reactiva sola**.
* **022:** mientras existan auditorías de exportación, decisiones de homónimos, decisiones de consentimiento de personas o versiones publicadas distintas de la 1, el propio *rollback* SQL se niega (error 1242).
  Archiva las tablas (`mysqldump`) y vacíalas con un usuario administrador: los *triggers* de «solo anexado» no impiden `TRUNCATE`/`DROP`, es una decisión humana y respaldada.
* Ejecutar a mano un `*_rollback.sql` **se salta** el guardián del migrador; no lo hagas en producción. Restaurar el respaldo en una base nueva siempre es la vía preferente.

## 8. Novedades que conviene probar a mano (con correos tuyos)

1. **Asistente del profesional** (`/admin/equipo` → invitar con rol *Profesional*): elige servicios, franjas semanales y descansos. La ficha nace inactiva y no aparece en reservas hasta que la persona acepte.
2. **Baja o suspensión con citas futuras** (`/admin/equipo/reasignar?id=<colaborador>`): reasignar a un sustituto realmente disponible, cancelar por el flujo normal o dejar en revisión manual. La baja se confirma solo cuando no quedan citas sin resolver; el historial queda en `citas_reasignaciones`.
3. **CRM** (`/admin/clientes`): exportar un CSV y revisar `/admin/clientes/auditoria`; alta de cliente por el equipo en `/admin/clientes/nuevo` (aviso de posibles homónimos: nunca fusiona ni bloquea); consentimiento con versión visible en la ficha.
4. **Alta de profesional** (`/profesionales/crear`): aviso de homónimos con confirmación «Es una persona distinta».

## 9. Ejecutar las pruebas en tu equipo

```bash
composer install && npm ci && npm run build
# Suite por defecto (base de pruebas propia: el nombre debe terminar en _test; NUNCA la de producción)
DB_NAME=appsalon_test php -d variables_order=EGPCS vendor/bin/phpunit --do-not-cache-result --order-by=random --colors=never
# QA independiente (≈8 min; exige DB_NAME con la forma appsalon_qa_*_test y Chromium; arranca sus propios php -S y SMTP simulado)
export DB_NAME=appsalon_qa_test EMAIL_HOST=127.0.0.1 EMAIL_PORT=2599 EMAIL_USER=t EMAIL_PASSWORD=t CHROME_BIN=/ruta/a/chrome QA_EXIGIR_NAVEGADOR=1
php -d variables_order=EGPCS vendor/bin/phpunit --do-not-cache-result --colors=never --log-junit /tmp/qa-junit.xml tests/Qa
php scripts/ci_verificar_omitidas.php /tmp/qa-junit.xml .github/qa_omitidas_permitidas.txt
```

No ejecutes la suite por defecto y la de QA a la vez en la misma máquina (comparten nombres de bases auxiliares y puertos SMTP). En CI corren en pasos distintos y bases distintas.

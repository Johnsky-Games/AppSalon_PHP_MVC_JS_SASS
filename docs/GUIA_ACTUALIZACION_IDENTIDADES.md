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

## 7. Revertir (procedimiento seguro y probado)

**Datos primero:** para volver a una versión anterior de la aplicación restaura el respaldo en una base nueva
(`php scripts/restore_db.php --db=<nueva> --input=<archivo> --hash=<sha256>`), verifica y recién entonces apunta `DB_NAME` a ella.
El rollback de esquema sirve para **reaplicar** migraciones (p. ej. corregir una migración en un entorno de prueba), no para ejecutar código anterior a la 016.

`php database/migrador.php down` **se niega** (código de salida 2, sin tocar nada) a revertir una migración cuya reversión destruya datos de negocio.
Para aceptar una pérdida debes indicar, migración por migración, `--aceptar-perdida=<migracion>` **y** un respaldo verificable (`--respaldo=<archivo.sql.gz>`):
generado por `scripts/backup_db.php` con su manifiesto SHA-256, de **esta** base, del esquema actual (última migración aplicada), de las últimas 24 horas y con al menos las filas actuales de las tablas en riesgo.
`--hasta=<migracion>` limita la reversión a esa migración y las posteriores (sin él, `down` revierte **todas**). `--simular` solo informa.

### 7.1 Revertir y reaplicar solo 016–018 sin perder suspensiones (Linux / macOS, host con PHP)

```bash
mkdir -p storage/respaldos
php scripts/backup_db.php --gzip --output=storage/respaldos/antes-de-revertir.sql.gz          # 1. respaldo (con manifiesto)
php database/migrador.php down --hasta=016_clientes_membresia --simular                         # 2. qué se perdería; no modifica nada
php database/migrador.php down --hasta=016_clientes_membresia \
  --aceptar-perdida=016_clientes_membresia --aceptar-perdida=017_idempotencia_unicidad \
  --aceptar-perdida=018_colaboradores_invitaciones \
  --respaldo=storage/respaldos/antes-de-revertir.sql.gz                                          # 3. revertir (sin estas opciones sale con código 2)
php database/migrador.php up                                                                     # 4. reaplicar: las suspensiones se restauran
```

### 7.2 Lo mismo en Windows (Git Bash) con Docker Compose

```bash
mkdir -p storage/respaldos
MSYS_NO_PATHCONV=1 docker compose --env-file includes/.env -p appsalon-validacion exec web \
  php scripts/backup_db.php --gzip --output=storage/respaldos/antes-de-revertir.sql.gz
MSYS_NO_PATHCONV=1 docker compose --env-file includes/.env -p appsalon-validacion exec web \
  php database/migrador.php down --hasta=016_clientes_membresia --simular
MSYS_NO_PATHCONV=1 docker compose --env-file includes/.env -p appsalon-validacion exec web \
  php database/migrador.php down --hasta=016_clientes_membresia \
  --aceptar-perdida=016_clientes_membresia --aceptar-perdida=017_idempotencia_unicidad --aceptar-perdida=018_colaboradores_invitaciones \
  --respaldo=storage/respaldos/antes-de-revertir.sql.gz
MSYS_NO_PATHCONV=1 docker compose --env-file includes/.env -p appsalon-validacion exec web php database/migrador.php up
```

La lista exacta de `--aceptar-perdida` depende de los datos: `--simular` muestra qué migraciones bloquean y por qué (una base sin datos de negocio revierte sin ellas).
Un `down` sin `--hasta` incluye además las migraciones 001–015; bloqueará también por sus datos (p. ej. `010_equipo_permisos` por `usuario_accesos`) y debes aceptarlas igual.

### 7.3 Qué garantiza la 016 al revertir

* `016_clientes_membresia_rollback.sql` copia **todas** las filas de `clientes` (estado, motivo de suspensión, origen, fecha de alta) a `clientes_resguardo_016` y **no elimina** esa tabla.
* Al reaplicar la 016, las membresías no derivables se recuperan, origen y fecha de alta se restauran y **una suspensión explícita nunca se reactiva sola** (queda suspendida si el resguardo o la retrocarga la marcan así).
  Las filas restauradas se marcan con `restaurado_en`.
* Purga manual cuando el operador decida (solo filas ya restauradas): `DELETE FROM clientes_resguardo_016 WHERE restaurado_en IS NOT NULL;` (ejecútalo con `mysql` en el contenedor `db`). Para borrar el resguardo completo: `DROP TABLE clientes_resguardo_016;` (la 016 la recrea vacía).
* Mientras la 016 esté revertida, **ningún código lee ni aplica las suspensiones**: no sirvas tráfico real en ese intervalo.
* Una instalación que ya tenía la 016 aplicada antes de este cambio no tiene la tabla de resguardo hasta el primer rollback: el propio rollback la crea.

### 7.4 Inventario de rollbacks y decisión tomada

| Migraciones | Qué elimina el rollback | Decisión |
|---|---|---|
| 001, 002, 008 | contadores de intentos, columnas de token, `primera_peticion`, arrendamientos de worker | Datos efímeros: no bloquean. |
| 003–007, 009–015 | tablas y columnas de negocio (profesionales, citas, pagos, notificaciones, accesos, perfiles, agenda, finanzas, inventario, fidelidad, comisiones) | Guard **genérico**: analiza el SQL del rollback (`DROP TABLE`, `DROP COLUMN`) contra los datos reales y bloquea si hay filas o valores. `equipo_mutex` y `usuarios.version_sesion` se tratan como efímeros. |
| 016 | tabla `clientes` | Guard explícito + **resguardo persistente** (§7.3). |
| 017 | `operaciones_idempotentes` y el UNIQUE de correo normalizado | Guard explícito (claves vigentes). Pierde la garantía de no duplicar altas reintentadas mientras esté revertida. |
| 018 | `colaboradores`, `colaborador_invitaciones`, `correos_salida`, `profesionales.colaborador_id` | Guard explícito. No hay copia: exige aceptación + respaldo. |

Límites reales: (a) ejecutar a mano un `*_rollback.sql` se salta el guard (las cabeceras de 016–018 lo advierten); el procedimiento soportado es siempre el migrador.
(b) El guard genérico detecta `DROP TABLE`/`DROP COLUMN` con datos; no entiende efectos semánticos de un rollback futuro con `UPDATE`/`DELETE` (ninguno de 001–018 los usa salvo borrar su fila de control). Una migración nueva que lo necesite debe aportar su `NNN_nombre.guard.php`.
(c) El manifiesto del respaldo no está firmado: protege de errores, no de un operador que falsifique el archivo.
(d) Código de salida: `0` ok, `1` error u opción inválida, `2` bloqueado por pérdida de datos (nada se modificó).

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

## 10. Riesgos conocidos y estado

* **DEF-13 (enumeración de cuentas en el registro público): corregido, pendiente de reverificación por QA independiente.**
  `POST /cliente/registro` (y `/crear-cuenta`) responde igual —misma redirección `302` a `/mensaje`, mismo cuerpo, cabeceras y alertas— para un correo nuevo, un cliente
  confirmado o sin confirmar, personal laboral (colaborador/propietario), una cuenta con doble perfil y una cuenta suspendida. Solo cambian los efectos en el servidor:
  correo nuevo → alta + token + evento de confirmación; cliente sin confirmar → token nuevo y confirmación (sin crear otra cuenta ni tocar su contraseña);
  confirmado/personal/suspendida → no se modifica nada y se encola un **aviso de cuenta existente** al titular (como máximo 1 por hora y correo; `portal` = `equipo` si la cuenta es solo laboral).
  Los errores de validación del formulario siguen siendo `422` con su detalle; el límite de intentos (3 por correo y 10 por IP cada 15 min) cuenta igual en todas las ramas.
  El correo sale por la cola `correos_salida`: se encola en la misma transacción que el alta y lo **entrega el worker de §9** (cada 5 s por defecto), no la petición HTTP; el worker **debe estar activo** (arranca con `docker compose up`).
  Hallazgo adicional corregido: `AuthService::login` no ejecutaba bcrypt para correos inexistentes (≈5 ms frente a ≈65 ms con cuenta), lo que permitía enumerar por tiempo; ahora ambos ≈65 ms.
  Canal de tiempo (QA R2B-02/08/09, corregido en la ronda 2B): ni el registro, ni `/olvide`, ni `/reenviar-confirmacion` envían correo dentro de la petición (salvo `CORREOS_ENVIO_INMEDIATO=1`, que reintroduce ese canal y solo es para pruebas);
  la recuperación de contraseña también usa la cola (`recuperacion_cuenta`, el enlace anterior se cancela al pedir uno nuevo). Residuo honesto: `password_hash` se paga en todas las ramas, pero no hay garantía de tiempo constante exacto; la diferencia restante la mide la suite Qa (umbral de cociente 2 con SMTP lento).
  Contrapartida: el correo llega al ritmo del worker (≤ `CORREOS_INTERVALO_SEGUNDOS`, 5 s por defecto) y, con el worker detenido, no llega.
  (3) quien pre-registra el correo de otra persona deja una cuenta sin confirmar con **su** contraseña; la víctima, al confirmar, activa esa cuenta (riesgo inherente al registro con confirmación por correo; el registro ya no revela que la cuenta existe).
* **DEF-16 (ciclo rollback/reaplicación de la 016 pierde suspensiones): corregido, pendiente de reverificación por QA independiente.** Ver §7: `down` bloquea sin aceptación y respaldo verificable, y la 016 conserva las suspensiones en `clientes_resguardo_016`.

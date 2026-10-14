# Guía: actualizar a la ronda 3 (activación segura de cuentas) — Windows, Docker y Linux

Complementa `docs/GUIA_ACTUALIZACION_RONDA2.md`. Esta ronda **no añade migraciones** (siguen vigentes la 016–022); cambia comportamiento. Todos los comandos son copiables.
No borra datos, no reemplaza `includes/.env` y no envía correo a personas reales (para probar usa un buzón tuyo o el SMTP simulado).

> Convención: `COMPOSE` = `docker compose --env-file includes/.env -p appsalon-validacion` (ajusta el proyecto). En Git Bash antepón `MSYS_NO_PATHCONV=1` a comandos con rutas.

## 1. Qué corrige y qué cambia para las personas

| Hallazgo | Antes | Ahora |
|---|---|---|
| **Toma de cuenta por registro previo** | Alguien registraba el correo de otra persona con su contraseña A; el titular activaba la cuenta y A entraba. | La contraseña del formulario de registro es **provisional**. Quien abre el enlace del buzón **elige la contraseña definitiva**, que reemplaza a la provisional en la misma sentencia atómica que consume el token. A jamás inicia sesión. |
| **Activación por GET** | `GET /confirmar-cuenta?token=…` activaba la cuenta (un escáner de enlaces del correo la consumía). | `GET`/`HEAD` solo **validan**; muestran el formulario. La activación es `POST /confirmar-cuenta` con CSRF, límite de intentos e idempotencia. |
| **Envío inmediato** | `CORREOS_ENVIO_INMEDIATO=1` reabría el canal de enumeración por tiempo en cualquier entorno. | Solo surte efecto con `APP_ENV` **exactamente** `development`, `dev`, `local`, `testing` o `test` (minúsculas, sin espacios). En `production`, `staging`, vacío o desconocido se ignora y se registra una advertencia. |

Efectos visibles:

* El correo de confirmación dice «elige tu contraseña definitiva; la del registro era provisional y se descarta». El botón es «Elegir mi contraseña y activar».
* **Enlaces de confirmación enviados antes de la actualización** (válidos 24 h) siguen funcionando, pero abren el formulario nuevo: la persona elige su contraseña (la del registro se descarta). Las cuentas **ya confirmadas** no cambian.
* La activación **no inicia sesión sola**: tras activar, la persona entra por `/cliente/login` con la contraseña que acaba de elegir.
* Las cuentas creadas por recepción (sin confirmar, contraseña aleatoria) se activan por el mismo formulario: la persona elige la suya.
* Sesiones antiguas sin marca de versión (anteriores a la migración 010) de cuentas cuya versión ya subió se cierran una vez (volver a iniciar sesión). No afecta a sesiones iniciadas con este código.
* **Revisión de datos personales en la activación (ronda 4).** El formulario de activación muestra, además de las dos contraseñas, **nombre, apellido y teléfono** precargados con lo que ya hay en la cuenta, editables y obligatorios (≤ 60 bytes los nombres; teléfono de 7 a 10 dígitos), con el aviso «Estos datos los escribió quien registró el correo antes de que se verificara. Revísalos y corrígelos. Tu correo queda verificado al activar; tu nombre y teléfono no se verifican.». Lo que la persona envíe reemplaza esos datos **en la misma operación atómica** que cambia la contraseña y consume el enlace: se aplican todos o ninguno. Los datos de nombre y teléfono son **declarados, no verificados** (solo el correo queda verificado); ninguna pantalla, correo, ficha de CRM o exportación los presenta como identidad verificada. Un formulario antiguo en caché (sin esos tres campos) recibe 422 con el formulario de nuevo, sin consumir el enlace. Límite conocido: un escáner de enlaces del proveedor de correo que haga `GET` verá esos datos (son los del propio registro y van al mismo buzón); `HEAD` no los muestra.
* Límites de activación: 15 intentos por IP y 5 por huella de token cada 15 minutos; los errores de contraseña (débil, no coincide) **no** cuentan. Varias personas detrás de una misma NAT que activan en ráfaga podrían topar con el límite por IP.

## 2. Actualizar

```bash
git fetch origin --prune
git switch claude/blissful-hopper-f5k7a0 && git pull --ff-only
$COMPOSE build web
$COMPOSE up -d db web notificaciones         # sin migraciones nuevas
$COMPOSE exec web php database/migrador.php status
$COMPOSE exec web php scripts/instalar.php --comprobar     # «Esquema al día.»
```

Revisa `includes/.env` (no lo reemplaces):

* **Quita `CORREOS_ENVIO_INMEDIATO`** si estaba definida; en producción se ignora igualmente. No debe aparecer en `docker-compose.yml` ni en tu `.env` de producción.
* **`APP_ENV` debe ser exactamente `production`** en producción (minúsculas). Un valor como `Production` o `prod` no activa ningún atajo de desarrollo (exige `APP_KEY`), pero conviene normalizarlo.
* `APP_KEY` sigue siendo obligatoria fuera de desarrollo; el worker (`notificaciones`) debe estar activo (los correos de cuenta salen por la cola).

## 3. Verificación manual (con un correo tuyo)

1. Abre `/cliente/registro` y registra **tu** correo con una contraseña cualquiera (será provisional).
2. Espera el correo (≤ 5 s tras el worker) y copia el enlace `…/confirmar-cuenta?token=…`.
3. Simula un escáner: `curl -I "<enlace>"` y `curl -s -o /dev/null -w "%{http_code}\n" "<enlace>"` → **200** y la cuenta sigue sin confirmar (`SELECT confirmado FROM usuarios WHERE email='…'` → `0`).
4. Abre el enlace en el navegador: aparece el formulario. Elige una contraseña **distinta** de la del registro y envía.
5. Intenta entrar en `/cliente/login` con la contraseña del registro → **rechazada**. Con la elegida → entra.
6. Repite el enlace → pantalla genérica «no válido o ya utilizado» (410), sin formulario.
7. **Datos personales:** al abrir el formulario (paso 4) comprueba que nombre, apellido y teléfono aparecen precargados con lo que escribiste al registrarte y que el aviso «Estos datos los escribió quien registró el correo…» está visible. Cámbialos (p. ej. otro teléfono), activa y verifica en la base: `SELECT nombre,apellido,telefono FROM usuarios WHERE email='tu@correo'` → los **nuevos**. Prueba también enviar un teléfono con letras o un nombre vacío → vuelve el formulario con el error y el enlace sigue valiendo.

PowerShell: `curl.exe -I "<enlace>"` (usa `curl.exe`, no el alias `curl`). Consulta SQL: `$COMPOSE exec db mysql -uroot -p<clave> <base> -e "SELECT confirmado FROM usuarios WHERE email='tu@correo'"`.

## 4. Revertir

No hay cambios de esquema. Para volver a la versión anterior basta con el código previo, **pero** esa versión reintroduce el defecto (GET activa y conserva la contraseña de quien pre-registró). Si hay que revertir, cierra el registro público o deja el worker detenido hasta restablecer esta versión, y revisa las cuentas pendientes (`SELECT id,email FROM usuarios WHERE confirmado=0`) antes de reactivar. Restaurar un respaldo sigue el procedimiento de `GUIA_ACTUALIZACION_RONDA2.md` §7.

## 5. Pruebas

Las mismas suites de `GUIA_ACTUALIZACION_RONDA2.md` §9. Nuevas en esta ronda: `tests/Integration/ActivacionCuentaIntegrationTest.php`, `tests/Integration/EnvioInmediatoEntornoTest.php` y, del QA independiente, `tests/Qa/R3Test.php`.

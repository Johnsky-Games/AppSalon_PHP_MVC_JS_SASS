# Entrega ronda 4 — revisión de datos personales en la activación y validación de la instalación local

Rama `claude/blissful-hopper-f5k7a0` (sin merge a `main`, sin despliegue). Parte de `eea5012`. Resultados de §3 del código `__SHA_CODIGO__`; el SHA final se informa en el mensaje de entrega.

## 1. Estado de la validación local — leer primero

**La instalación local del usuario NO está validada, y no se declara validada.** El trabajo se hizo en una máquina virtual en la nube (Linux, sin daemon Docker, sin `includes/.env` del usuario, sin sus volúmenes ni buzones). No hubo acceso a su equipo. Se entregan:

* `docs/VALIDACION_LOCAL_RONDA3.md`: comandos exactos (Git Bash/PowerShell + Docker Compose) para inspeccionar, **respaldar antes de actualizar**, actualizar sin `reset --hard` ni `down -v`, comprobar migraciones, levantar web/MySQL/worker y recorrer 15 comprobaciones funcionales con cuentas y buzones del propio usuario, más una plantilla para devolver los resultados.
* Un **ensayo en la nube** del mismo procedimiento (adaptado a ejecución nativa) hecho por un QA independiente; vale como prueba de que los pasos son coherentes, **no** como validación del equipo del usuario.

## 2. Cambio de código: el titular revisa sus datos personales al activar

* **Causa raíz.** Quien pre-registra un correo (un tercero o recepción) fija nombre, apellido y teléfono **antes** de verificar el correo. La activación ya reemplazaba la contraseña (ronda 3), pero esos tres datos seguían siendo del pre-registrante.
* **Cambio.**
  * El formulario de activación (`GET /confirmar-cuenta`) precarga nombre, apellido y teléfono (editables, obligatorios) con el aviso: «Estos datos los escribió quien registró el correo antes de que se verificara. Revísalos y corrígelos. Tu correo queda verificado al activar; tu nombre y teléfono no se verifican.»
  * `POST /confirmar-cuenta` exige los tres campos y los aplica **en la misma sentencia atómica** que la contraseña y el consumo del token (`UsuarioRepository::activateAccountByToken`): o cambia todo o no cambia nada; nunca toca una cuenta ya confirmada ni otra cuenta.
  * Validación (`Usuario::validarDatosContacto`): obligatorios, ≤ 60 bytes, teléfono 7–10 dígitos, sin bytes de control, sin `<`/`>` y UTF-8 válido. Un error de validación responde 422 conservando lo escrito (nunca la contraseña) **sin** consumir el token, la clave de operación ni el límite de intentos.
  * La clave de operación (idempotencia) incluye los datos: mismo envío = mismo resultado; misma clave con otros datos o contraseña = 409.
  * Ningún dato personal se presenta como comprobado por el sistema: solo el **correo** queda verificado. No se añadieron insignias en CRM ni perfil. (El encabezado CSV «Cuenta confirmada» del CRM se conserva: describe el estado de la cuenta, no una identidad.)
* **Hallazgos del QA independiente en esta ronda y su corrección.**
  | Hallazgo | Causa | Corrección |
  |---|---|---|
  | R4-1 bytes de control (NUL, ESC, BEL, DEL) devueltos crudos en el formulario precargado y en el 422 | El registro público admitía controles que la activación sí rechazaba | `Usuario::validarNuevaCuenta` los rechaza también (422 en el registro) y `LoginController` limpia controles y UTF-8 inválido al repintar el formulario (R4-12b) |
* **Sin migración nueva.**
* **Pruebas de autorización, concurrencia e idempotencia** (en `ActivacionCuentaIntegrationTest` y, de forma independiente, `tests/Qa/R4Test.php`): token ajeno / inválido / vencido / usado / de recuperación / cuenta ya confirmada; sin CSRF o con CSRF ajeno; sesión iniciada de otro cliente; asignación masiva (`id`, `email`, `admin`, `rol`, `confirmado`… ignorados); 10 activaciones simultáneas con datos y claves distintos → exactamente una gana y la fila entera es suya; doble clic = una aplicación; misma clave con otros datos = 409; 30 reintentos con 422 no gastan clave, token ni límites; datos hostiles (XSS, SQL, NUL, controles, unicode, 61 bytes, arreglos, JSON, 1 MB) → 422 o guardados y mostrados escapados en formulario, perfil, CRM, CSV (fórmulas neutralizadas), auditoría y correo.

## 3. Resultados (suites separadas, sin sumar)

* **PHPUnit por defecto**, local (PHP 8.3.6, MySQL 8.0.46, orden aleatorio): `__PHPUNIT__`
* **QA independiente (`tests/Qa`)**, local: `__QA__`
* **CI del mismo código:** `__CI__`
* **Pruebas omitidas:** `__OMITIDAS__`
* **E2E de navegador:** el paso «Recorrido E2E aislado» del CI (Apache real); en local no se repitió (sin Docker). El QA hizo además un recorrido con Chromium sobre `php -S` en la VM (registro → correo → formulario precargado → corregir y activar → login).

## 4. Comprobado / fallido / pendiente

**Comprobado (en la nube, no en el equipo del usuario)**
* Las suites anteriores (PHPUnit, QA, CI con PHP 8.2, Apache y E2E).
* Ensayo del procedimiento de actualización en la VM: respaldo con hash y restauración en base nueva; cambios locales sin confirmar respetados (un solape hizo que `merge --ff-only` se negara sin tocar `HEAD`; se resolvió sin `reset --hard`); `.env` con hash idéntico antes y después; `migrador status` tras actualizar mostró PENDIENTE 019/021/022 y `instalar.php --migrar` + `--comprobar` los aplicó («Esquema al día.»); datos sembrados intactos (huella por tabla) y cliente suspendido sigue suspendido; recorrido 6.1–6.15 con HTTP real.

**Fallido (y corregido)**
* En el ensayo, varios comandos de la guía no funcionaban tal como estaban escritos (columnas de `citas_reasignaciones`, id del colaborador, motivo y baja como segundo formulario, casilla de aceptación, aviso de homónimos, `ls storage` en clon limpio, camino cuando `--ff-only` se niega): corregidos en `VALIDACION_LOCAL_RONDA3.md`.
* El CI del commit `5f24fe7` falló en el paso de QA por R4-12b (bytes de control al repintar el 422): corregido en el commit siguiente.

**Pendiente / no ejecutable aquí**
* **Toda la validación sobre el equipo real del usuario** (pasos 1–6 de `VALIDACION_LOCAL_RONDA3.md`).
* `docker compose build/up/exec/stop/start`, volúmenes y `.env` reales; SMTP real y buzones del usuario.
* PHP 8.2 y Apache fuera del CI.
* En el ensayo, la reserva se hizo por API (el clic en `/cita` no se ejecutó).

## 5. Riesgos que quedan
* Un escáner de enlaces del proveedor de correo que haga `GET` ve nombre/apellido/teléfono precargados (son los del propio registro; `HEAD` no los muestra). Documentado.
* Nombre y teléfono siguen siendo datos **declarados**; el titular puede dejarlos como están.
* La activación rechaza `<`/`>` en nombre y apellido; el registro público los admite (se escapan al pintar). Un pre-registrante pudo guardarlos y el titular deberá corregirlos para activar.
* `correos_salida.destinatario_nombre` conserva el nombre tecleado por quien pre-registró en el correo ya entregado (único residuo; no aparece en perfil, CRM ni exportaciones).
* Cuentas activadas antes de esta ronda no se revisan retroactivamente.
* Todos los de `ENTREGA_RONDA3.md` §4.

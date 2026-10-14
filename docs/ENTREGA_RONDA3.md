# Entrega ronda 3 — activación segura de cuentas

Rama `claude/blissful-hopper-f5k7a0` (sin merge a `main`, sin despliegue). Parte de `2240371` (dos CI verificados).
El SHA final se informa en el mensaje de entrega; un commit de documentación no puede citar su propio SHA. Los resultados de §3 son del código de `9983a1a`.
Guía de actualización (Windows/Docker/Linux): `docs/GUIA_ACTUALIZACION_RONDA3.md`.

## 1. Hallazgos, causa raíz y corrección

### Prioridad 1 — Toma de cuenta por registro previo (Alta)
* **Causa raíz.** `AuthService::altaOAvisoDeRegistro()` conservaba, en una cuenta sin confirmar, la contraseña de quien la pre-registró (A) y solo reemitía el token cuando el titular volvía a registrarse (con B). `UsuarioRepository::confirmAccountByToken()` activaba la cuenta **sin tocar la contraseña**. Resultado: el atacante que había registrado el correo ajeno con A iniciaba sesión después de que el titular activara.
* **Reproducción previa (HTTP real, SMTP simulado, worker real).** Atacante registra con A → titular registra con B (respuesta uniforme) → el worker entrega un enlace al titular → el titular abre el enlace → la cuenta queda confirmada y el login con **A** responde `302 /cita` (acceso del atacante). Evidencia literal antes de la corrección: `[5] login con A (la del atacante): 302 -> /cita`. El QA independiente la reprodujo por su cuenta (`R3_01`, `R3_02`) antes de ver la corrección.
* **Diseño.** Quien demuestra control del buzón establece la contraseña definitiva:
  * La contraseña del formulario de registro es **provisional** (el formulario, el correo y las pantallas lo dicen).
  * La activación (`AuthService::activarCuenta`) **siempre** exige elegir una contraseña nueva (política del registro + confirmación) y `UsuarioRepository::activateAccountByToken` la aplica en **un único `UPDATE` atómico**: `SET password=?, confirmado='1', version_sesion=version_sesion+1, token…=NULL WHERE token_hash=? AND token_tipo='confirmacion' AND token_expira>=UTC_TIMESTAMP() AND confirmado='0'` con `affected_rows === 1`.
  * Una petición pública que envía otra contraseña **no** sustituye la provisional (el re-registro del titular sigue sin tocarla).
  * Se conservan: respuesta uniforme del registro, idempotencia (`Idempotencia::ejecutar`, ámbito `cliente.activacion`, también para el doble clic), tokens con propósito (confirmación ≠ recuperación ≠ invitación), expiración de 24 h y consumo atómico (el perdedor de dos activaciones simultáneas recibe «enlace no válido o ya utilizado» y no cambia la contraseña del ganador).
  * Al activar: se borran los contadores de intentos de la cuenta, se cancelan en cola las confirmaciones y recuperaciones pendientes, sube `version_sesion` (invalida sesiones previas), se regenera el id de sesión del navegador y **no hay auto-login**.
  * Cuentas ya verificadas: el registro público sobre ellas no cambia nada (solo aviso al titular); un token residual de confirmación sobre una cuenta confirmada no cambia su contraseña ni la reactiva (`confirmado='0'` en el `WHERE`).
  * Cuentas de recepción (contraseña aleatoria, sin confirmar): la persona elige la suya en el mismo formulario.
* **Defensa en profundidad.** `isAuth()` ya no «adopta» una sesión guardada sin versión cuando la cuenta pasó de sin confirmar a confirmada (`version_sesion_vigente`); `resetPasswordByToken` exige `confirmado='1'`.

### Prioridad 2 — Confirmación mediante GET (Alta)
* **Causa raíz.** `GET /confirmar-cuenta?token=` activaba la cuenta y consumía el token: un escáner de enlaces del correo (Outlook SafeLinks, Googlebot, prefetch) activaba cuentas sin intervención humana.
* **Corrección.** `GET` muestra el formulario y `HEAD` solo valida, sin consumir; ambos con `Cache-Control: no-store` y `Referrer-Policy: no-referrer`, token escapado, y pantalla genérica 410 sin formulario para tokens inválidos/vencidos/usados. La activación es `POST /confirmar-cuenta` con CSRF obligatorio, token en el cuerpo (no en la URL), límite de intentos (15 por IP y 5 por huella de token cada 15 min; los errores de contraseña **no** cuentan) y mensajes sin enumeración. El enlace del correo conserva la ruta.

### Prioridad 3 — Envío inmediato (Media)
* **Causa raíz.** `CorreoSalidaService::envioInmediatoHabilitado()` solo miraba `CORREOS_ENVIO_INMEDIATO=1`: una variable accidental en producción reabría el canal de enumeración por tiempo.
* **Corrección.** `Classes\Entorno::esPruebasODesarrollo()` (criterio **estricto**: valor exacto, en minúsculas y sin espacios, de `development|dev|local|testing|test`) gobierna el envío inmediato, la derivación de clave de `Classes\Secretos` y el instalador. En `production`, `staging`, vacío, `qa`, `Production`, ` testing ` y cualquier otro valor se ignora y queda una advertencia en el log. La variable se quitó de `docker-compose.yml` y de `.env.example` (solo documentada).

### Hallazgos adicionales de esta ronda (QA independiente)
| Hallazgo | Causa | Estado |
|---|---|---|
| R3-5 sesión previa «adoptada» | `confirmAccountByToken` no subía `version_sesion` | Corregido (sube la versión; `isAuth` no adopta sesiones sin versión de cuentas que la cambiaron) |
| R2B-03c | Redacción de direcciones entre comillas / literal IP en `ultimo_error` | Corregido y reverificado |
| R2B-13 | Diagrama §5.1 de `NOTIFICACIONES_WORKER.md` | Corregido y reverificado |
| R2B-03d | Literal `[IPv6:…]` no se redactaba | Corregido (`tests/Unit/RedaccionYPasswordNulTest.php`) |
| Interbloqueo en activaciones simultáneas | Un CI previo (run 38511682212) falló una vez: con dos activaciones simultáneas InnoDB abortó a la perdedora con 1213 y vio un error técnico en lugar de «enlace no válido o ya utilizado» (la contraseña ganadora nunca estuvo en riesgo) | Corregido: `AuthService::activarCuenta` reintenta hasta 5 veces los fallos 1213/1205 (prueba unitaria `testLaActivacionReintentaInterbloqueos…`) |
| R3b-19 | Contraseña con byte NUL → `ValueError` de `password_hash()` → 500 en registro, activación y recuperación | Corregido: 422 (`Usuario::validarPassword`, `ColaboradorService`) |

## 2. Documentación corregida
El riesgo «inherente / residuo honesto» del pre-registro ya no figura como tal en `GUIA_ACTUALIZACION_IDENTIDADES.md` §10, `GUIA_ACTUALIZACION_RONDA2.md` §6, `ENTREGA_RONDA2.md` §5 ni en la matriz (R2 y fila R13 nueva de `MATRIZ_ACEPTACION_NEGOCIO.md`).

## 3. Resultados (suites separadas, sin sumar)
* **PHPUnit por defecto** (`tests/Unit` + `tests/Integration`), local (PHP 8.3.6, MySQL 8.0.46, orden aleatorio): `OK (756 tests, 10725 assertions)` (semilla 1791952572). En el CI (PHP 8.2, orden aleatorio) el paso pasó.
* **QA independiente** (`tests/Qa`), local: `OK (636 tests, 6870 assertions)`, 937 s, `QA_EXIGIR_NAVEGADOR=1`; en el CI el paso pasó.
* **CI del mismo código:** [run 38512598534](https://github.com/Johnsky-Games/AppSalon_PHP_MVC_JS_SASS/actions/runs/38512598534) sobre `9983a1a`: **success** (migraciones, instalador del propietario, PHPUnit, QA, imagen Docker, E2E en Apache, respaldo/restauración con SHA-256).
* **Pruebas omitidas:** 0 en `tests/Qa` (`ci_verificar_omitidas.php`: «Pruebas en el informe: 636; omitidas: 0») y 0 en la suite por defecto; la lista permitida `.github/qa_omitidas_permitidas.txt` sigue vacía.
* **E2E de navegador:** el paso «Recorrido E2E aislado» del CI (Apache real + MySQL + SMTP simulado) forma parte del mismo run. En local no se repitió (no hay Docker).

Historial del CI de esta ronda: un run falló por el interbloqueo descrito arriba (corregido) y el siguiente, sobre el código final, pasó; no se relajó ninguna prueba.

Pruebas nuevas o reescritas: `ActivacionCuentaIntegrationTest` (23), `EnvioInmediatoEntornoTest` (≈56 casos), `RedaccionYPasswordNulTest`, y del QA `R3Test`, `R3bTest`, más la adaptación (con justificación escrita, sin relajar) de las pruebas Qa que activaban por GET o iniciaban sesión con la contraseña del registro. Mutaciones sobre copia: 9 del agente de identidad y 11 del QA (Q01–Q11), todas detectadas.

## 4. Riesgos restantes (sin ocultar)
* **Quien controla el buzón controla la cuenta.** Es el supuesto básico de todo registro con confirmación por correo; la corrección impide que un tercero conserve una credencial, no que un buzón comprometido active la cuenta.
* **Datos de perfil del pre-registro.** Quien pre-registra un correo ajeno fija nombre, apellido y teléfono de la cuenta; siguen siendo los suyos tras la activación del titular (no concede acceso; la persona puede editarlos en su perfil).
* **Límite por IP compartido.** Detrás de una misma NAT, una ráfaga de activaciones puede topar con el límite de 15 por IP.
* **Sin tiempo constante exacto** en registro/login (se mide un cociente < 2 con SMTP lento, no igualdad).
* **Advertencia de `CORREOS_ENVIO_INMEDIATO` por petición**, no por proceso (ruido de log, no riesgo).
* **Sesiones antiguas sin versión** de cuentas cuya versión ya subió se cierran una vez.
* **Entrega de correo «al menos una vez»** y worker obligatorio (ver `ENTREGA_RONDA2.md` §5).
* **No verificado en local:** PHP 8.2, Apache/Docker (solo el CI), SMTP real, IPv6/proxy en el límite de intentos.
* `docs/GUIA_ENTREGA_1.md` (fila 2 de su tabla) conserva un registro histórico del contrato anterior («GET confirma la cuenta»); no es la guía vigente.

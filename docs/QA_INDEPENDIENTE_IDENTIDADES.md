# QA independiente: identidades, portales, colaboradores, CRM, instalador y respaldo

Revisión adversarial e independiente del commit `4e542af` (rama `claude/blissful-hopper-f5k7a0`, «instalador idempotente…»).
Todas las pruebas son nuevas y viven en `tests/Qa/`; no se modificó código de la aplicación ni pruebas existentes.
Fecha de ejecución: 2026-10-13. **Ronda 2 (matriz de autorización exacta): ver la sección «Ronda 2» al final del documento.** **Ronda 2B (reverificación de las correcciones de la ronda 2): ver «Ronda 2B – Reverificación» al final.** Entorno: PHP 8.3.6 (el objetivo es 8.2; ver «No cubierto»), MySQL 8.0.46, PHPUnit 10.5.66, Chromium 1194 con puppeteer-core.

Convenciones: **DEFECTO** = el ataque rompió una garantía y existe una prueba roja `test_Defecto_*`; **PASA** = el ataque se ejecutó y el sistema resistió;
**OBSERVACIÓN** = comportamiento discutible sin prueba roja; **NO PROBADO** = no se alcanzó. Hechos comprobados y suposiciones se separan expresamente.

## 1. Cómo reproducir

```bash
# Base propia (nunca appsalon_test). El nombre debe terminar en _test; el arnés exige el prefijo appsalon_qa_.
export DB_HOST=127.0.0.1 DB_PORT=3306 DB_USER=<usuario> DB_PASS=<contraseña-local> DB_NAME=appsalon_qa_test APP_ENV=testing \
       APP_URL=http://localhost:3000 APP_TIMEZONE=America/Guayaquil PAYMENT_WEBHOOK_SECRET=ci_test_webhook_secret_hmac_2026 \
       EMAIL_HOST=127.0.0.1 EMAIL_PORT=2599 EMAIL_USER=t EMAIL_PASSWORD=t
export MYSQL_PWD="$DB_PASS"        # evita la contraseña en la línea de comandos
mysql -h"$DB_HOST" -u"$DB_USER" -e "DROP DATABASE IF EXISTS appsalon_qa_test; CREATE DATABASE appsalon_qa_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -h"$DB_HOST" -u"$DB_USER" appsalon_qa_test < database/schema_base.sql
php database/migrador.php up
cp -r <repo-principal>/vendor ./vendor && ln -s <repo-principal>/node_modules node_modules     # no se ejecuta composer install

# Toda la suite QA (sin editar phpunit.xml; se pasa la ruta):
php -d variables_order=EGPCS vendor/bin/phpunit --do-not-cache-result --colors=never tests/Qa
# Un archivo o una prueba:
php -d variables_order=EGPCS vendor/bin/phpunit --do-not-cache-result --colors=never --filter '<nombre>' tests/Qa/<Archivo>.php
```

**Navegador (ronda 2).** `FrontendTest` busca Chromium en `CHROME_BIN` (si está definido es el único válido) y, solo si no lo está, en el respaldo local
`/opt/pw-browsers/chromium-1194/chrome-linux/chrome`; `frontend_qa.cjs` recibe el mismo ejecutable. Con `QA_EXIGIR_NAVEGADOR=1` (CI), la ausencia de Chromium o de
`node_modules/puppeteer-core` **hace fallar** las 7 pruebas del navegador en lugar de omitirlas. Sin esa variable se omiten con el motivo escrito.

El arnés (`tests/Qa/QaCase.php`) aborta si `DB_NAME` no cumple `appsalon_qa_*_test`; las pruebas destructivas/de esquema crean y borran sus propias bases
`appsalon_qa_<x>_test`. Los servidores HTTP son `php -S` reales con router propio (`tests/Qa/router.php`), el SMTP simulado es propio (`tests/Qa/smtp_qa.php`, puertos 2596-2598)
y la concurrencia usa procesos PHP reales con una barrera de tiempo común (`tests/Qa/worker.php` + `proc_open`).

## 2. Resultado de la ejecución completa

Salida literal de `php -d variables_order=EGPCS vendor/bin/phpunit --do-not-cache-result --colors=never tests/Qa` (ejecución final, `DB_NAME=appsalon_qa_test`):

```
Time: 03:42.862, Memory: 16.00 MB
Tests: 203, Assertions: 1516, Failures: 22, Skipped: 1.
```

- **203 pruebas** en 12 archivos (`AtaquesVarios` 20, `AutorizacionHttp` 37, `CabecerasYDisponibilidad` 6, `Concurrencia` 17, `Correo` 6, `Esquema` 12, `Fallos` 47, `Frontend` 7, `Instalador` 9, `Privacidad` 20, `RegresionNegocio` 6, `Tokens` 16) + arnés (`QaCase`, `Navegador`, `worker.php`, `smtp_qa.php`, `router.php`, `frontend_qa.cjs`).
- **22 fallos, todos intencionados**: 20 métodos `test_Defecto_*` (uno con 3 casos de datos). **0 fallos entre las pruebas que no son `Defecto_*`** (180 pasan).
- **1 omitida** (`TokensTest::test_cuenta_existente_sin_confirmar_recupera_sesiones_y_tokens_previos`): el sistema no emite recuperación para una cuenta sin confirmar, de modo que no hay token que reutilizar (comportamiento válido).
- Estabilidad: la suite completa se ejecutó 5 veces. La 1.ª mostró 5 errores y 4 fallos que NO eran defectos de la aplicación sino contaminación entre clases del arnés (una conexión cerrada que `ActiveRecord` conservaba tras `InstaladorTest`); se corrigió en `QaCase::db()`. En la 3.ª solo hubo 21 rojas porque este mismo informe contenía el texto que buscaba la comprobación documental de DEF-17 (ahora excluye este archivo). La 2.ª, la 4.ª y la 5.ª (tras parametrizar las credenciales por entorno) dieron exactamente las mismas 22 rojas y 0 fallos fuera de `Defecto_*`.

> **Actualización posterior a las correcciones:** los 18 defectos fueron atendidos (16 corregidos, `DEF-13` y `DEF-16` como riesgo aceptado y omitidos con justificación). La re-ejecución de la suite completa dio `Tests: 203, Assertions: 1521, Skipped: 3` sin fallos. La salida literal de arriba corresponde a la primera pasada, previa a las correcciones.

Las pruebas rojas `Defecto_*` y el defecto que demuestran:

| Prueba | Defecto |
|---|---|
| `AtaquesVariosTest::…cambiar_el_acceso_de_un_profesional_desvincula_su_ficha_de_la_cuenta`, `…suspender_y_reactivar_a_un_profesional…` | DEF-01 |
| `RegresionNegocioTest::…cita_nueva_en_carrera_con_la_suspension…` | DEF-02 |
| `AutorizacionHttpTest::…cliente_suspendido_con_rol_laboral_conserva_sesion…`, `AtaquesVariosTest::…cliente_suspendido_con_rol_laboral_puede_reservar…` | DEF-03 |
| `AtaquesVariosTest::…colaborador_suspendido_con_membresia_de_cliente_activa…` | DEF-04 |
| `FallosTest::…listado_del_equipo_se_bloquea…con_smtp_colgado` | DEF-05 |
| `ConcurrenciaTest::…reenvio_simultaneo_evade_la_espera…` | DEF-06 |
| `AtaquesVariosTest::…no_se_puede_reinvitar…` | DEF-07 |
| `AtaquesVariosTest::…ficha_profesional_heredada_queda_inutilizable…` | DEF-08 |
| `FallosTest::…clave_derivada_de_desarrollo…` | DEF-09 |
| `InstaladorTest::…regenerar_activacion_con_otro_correo…` | DEF-10 |
| `InstaladorTest::…instalador_paralelo_sobre_base_vacia…` | DEF-11 |
| `FallosTest::…invitar_con_campo_arreglo_emite_advertencia…` (3 casos) | DEF-12 |
| `TokensTest::…registro_publico_revela_si_un_correo_ya_tiene_cuenta` | DEF-13 |
| `AtaquesVariosTest::…recepcion_puede_editar_el_perfil_del_propietario…` | DEF-14 |
| `AtaquesVariosTest::…nombre_y_apellido_de_60_caracteres…` | DEF-15 |
| `EsquemaTest::…E2_rollback_y_reaplicacion_de_016…` | DEF-16 |
| `CorreoTest::…el_outbox_de_correos_no_tiene_proceso_programado…` | DEF-17 |
| `EsquemaTest::…E5_conteo_de_tablas_y_documentacion` | DEF-18 |

**Resumen de severidad (18 defectos):** 1 Alta (DEF-01); 4 Media (DEF-02, DEF-03, DEF-04, DEF-05); 5 Media-Baja (DEF-06, DEF-07, DEF-09, DEF-10, DEF-14); 8 Baja (DEF-08, DEF-11, DEF-12, DEF-13, DEF-15, DEF-16, DEF-17, DEF-18).
Ningún ataque permitió acceder a una ruta laboral sin el permiso correspondiente, ver datos privados (formularios, fotos, notas de RR. HH., importes) fuera de su alcance ni tomar una cuenta ajena. Los defectos de autorización encontrados son DEF-03, DEF-04 (estado de sesión de personas con ambos perfiles) y DEF-14 (un rol inferior edita el perfil del propietario).

## 3. Defectos priorizados

Severidad: **Alta** = pérdida funcional/de integridad reproducible con una acción normal; **Media** = quiebra una garantía declarada o permite abuso acotado; **Baja** = borde, endurecimiento o documentación.
«Prueba roja» es el método PHPUnit; «Reproducir» usa el comando de la sección 1 con `--filter`.

### DEF-01 (Alta) Guardar o cambiar el acceso de un profesional lo desvincula de su cuenta
- **Pasos:** 1) invitar a un profesional y que acepte; `profesionales.usuarioId` queda con su cuenta. 2) El propietario pulsa «Guardar acceso» en `/admin/equipo` sin cambiar nada (rol `profesional`, estado `activo`), o lo suspende y lo reactiva. 3) `profesionales.usuarioId` pasa a `NULL` y no vuelve.
- **Efecto comprobado:** `AuthorizationService::contexto().profesional_id = NULL`; `CitaRepository::listarAgenda()` filtra por `p.usuarioId`, de modo que su `/agenda` queda vacía; `mutarAgenda()` le niega «en servicio/completada» (compara `prof.usuarioId`); «Mis clientes» del CRM (`profesional_actual_id()`) queda sin vincular. La ficha reactivada sigue `activo=1` en el catálogo público (se pueden reservar citas con una persona que ya no puede atenderlas en la app).
- **Causa probable:** `repositories/EquipoRepository.php:65` ejecuta `UPDATE profesionales SET usuarioId=NULL WHERE usuarioId=?` de forma incondicional y solo re-vincula si recibe `$profesionalId`; `ColaboradorRepository::cambiarAcceso()` (`repositories/ColaboradorRepository.php:393-418`) llama a `EquipoRepository::actualizar(..., null, $estado)`.
- **Prueba roja:** `AtaquesVariosTest::test_Defecto_cambiar_el_acceso_de_un_profesional_desvincula_su_ficha_de_la_cuenta` y `::test_Defecto_suspender_y_reactivar_a_un_profesional_lo_deja_activo_en_el_catalogo_pero_sin_cuenta`.
- **Reproducir:** `--filter 'desvincula_su_ficha|suspender_y_reactivar' tests/Qa/AtaquesVariosTest.php`.

### DEF-02 (Media) Carrera suspensión de profesional vs. reserva deja una cita futura asignada a una ficha suspendida
- **Pasos:** reservar con el profesional y, a la vez, suspenderlo (`cambiarAcceso(... 'suspendido')`). En 8 de 8 carreras con barrera la cita se confirmó y la ficha quedó `activo=0` con colaborador `suspendido`.
- **Causa probable:** `ColaboradorRepository.php:380-410`: `citasFuturasDeProfesional()` es una lectura no bloqueante (instantánea) y el `UPDATE profesionales SET activo=0` llega después sin haber bloqueado la fila del profesional; la reserva (`CitaRepository::crearReservaConProfesionalAtomica`) comprueba `activo` bajo su propio bloqueo y gana por unos milisegundos. Falta `SELECT … FROM profesionales WHERE colaborador_id=? FOR UPDATE` antes de contar.
- **Prueba roja:** `RegresionNegocioTest::test_Defecto_cita_nueva_en_carrera_con_la_suspension_del_profesional_queda_huerfana`. (Secuencialmente la protección funciona: `…es_rechazado_y_no_deja_citas_huerfanas` PASA.)

### DEF-03 (Media) Cliente con membresía suspendida y rol laboral conserva la sesión de cliente y puede reservar
- **Pasos:** persona con ambos perfiles abre sesión en `/cliente/login`; se suspende su fila `clientes`. Un login nuevo da 403 con el mensaje de suspensión, pero la sesión abierta sigue en 200 y `POST /api/citas` crea la cita.
- **Causa probable:** `services/AuthorizationService.php:43-45`: el término `NOT (rol='cliente' AND clientes.estado='suspendido')` solo desactiva a quien tiene rol `cliente`; `isAuth()` no distingue el portal.
- **Pruebas rojas:** `AutorizacionHttpTest::test_Defecto_cliente_suspendido_con_rol_laboral_conserva_sesion_de_cliente_activa`, `AtaquesVariosTest::test_Defecto_cliente_suspendido_con_rol_laboral_puede_reservar_por_la_api_con_sesion_de_cliente`.

### DEF-04 (Media) Colaborador suspendido con membresía de cliente activa pierde también el portal de clientes (y el login «entra» y expulsa)
- **Pasos:** persona con ambos perfiles; se suspende solo su vínculo laboral. `POST /cliente/login` responde 302 a `/cita`, pero la petición siguiente cierra la sesión («Tu sesión terminó o cambió tu acceso»).
- **Causa probable:** misma consulta: `activo` incorpora el estado laboral (`$laboralVigente`, línea 43) y `isAuth()` (`includes/funciones.php`) lo aplica a toda sesión; contradice «cada membresía tiene su propio estado». El login de cliente tampoco rechaza el caso (`LoginController::autenticar`).
- **Prueba roja:** `AtaquesVariosTest::test_Defecto_colaborador_suspendido_con_membresia_de_cliente_activa_pierde_tambien_el_portal_de_clientes`.

### DEF-05 (Media) Un SMTP que acepta la conexión y no saluda bloquea la petición 300 s; `GET /admin/equipo` envía correos de forma síncrona
- **Comprobado (medición manual, no automatizada por su duración):** un único `Email::enviarInvitacion()` contra un socket que acepta la conexión y calla tardó **300,1 s** y devolvió `false`. `classes/Email.php:204,277` fija `Timeout = 5`, pero PHPMailer espera el saludo con `stream_select(..., $this->Timelimit)` (`vendor/phpmailer/phpmailer/src/SMTP.php:1269`) y `Timelimit` vale 300 por defecto. La prueba roja automatizada usa un tope de cliente de 25 s.
- **Alcance:** `controllers/EquipoController.php:31` llama a `procesarPendientes(3)` dentro del `GET /admin/equipo` (hasta 3 envíos sincrónicos); también el envío tras confirmar (`ColaboradorService::enviarTrasConfirmar`). Con SMTP lento, la pantalla del equipo (y un worker de PHP) queda colgada minutos.
- **Prueba roja:** `FallosTest::test_Defecto_listado_del_equipo_se_bloquea_hasta_15_s_con_smtp_colgado` (cliente HTTP con tope de 25 s: no responde).
- **Mitigación sugerida (no aplicada):** fijar `$email->Timelimit` (≈10 s) y no enviar dentro de peticiones de lectura.

### DEF-06 (Media-Baja) El reenvío concurrente evade la espera de 60 s y emite varias invitaciones y correos
- **Pasos:** 6 procesos `reenviar` simultáneos con claves distintas sobre una invitación de hace 5 min. Resultado: 6 invitaciones nuevas (7 en total), 5 eventos de correo vivos con token; secuencialmente el segundo reenvío sí se rechaza (control `…reenvio_secuencial_respeta_la_espera_de_60_segundos` PASA).
- **Causa probable:** `ColaboradorRepository.php:189-195`: `exigirPropietario()` hace una lectura consistente (instantánea REPEATABLE READ) *antes* de esperar el bloqueo `FOR UPDATE` del colaborador; `$ultima` se lee después con otra lectura no bloqueante de esa misma instantánea y no ve la invitación que acaba de confirmar el proceso anterior.
- **Prueba roja:** `ConcurrenciaTest::test_Defecto_reenvio_simultaneo_evade_la_espera_de_60_segundos_y_emite_varias_invitaciones`.

### DEF-07 (Media-Baja) Tras revocar una invitación no se puede volver a invitar el mismo correo
- **Pasos:** invitar → revocar → invitar de nuevo (p. ej. para corregir el rol). Respuesta: «Ya existe un colaborador con este correo».
- **Causa probable:** `revocarInvitacion()` (`ColaboradorRepository.php:209-225`) deja la ficha en `baja` con `usuario_id NULL`; `uq_colaborador_email` bloquea el correo; `cambiarAcceso()` rechaza fichas sin `usuario_id` («Aún no aceptó…») y `reenviarInvitacion()` exige `estado='invitado'`. Ninguna acción de la UI recupera el registro.
- **Prueba roja:** `AtaquesVariosTest::test_Defecto_no_se_puede_reinvitar_el_mismo_correo_tras_revocar_la_invitacion`.

### DEF-08 (Baja) Una ficha profesional heredada queda inutilizable tras revocar su invitación
- `revocarInvitacion()` no libera `profesionales.colaborador_id`; `profesionalesSinCuenta()` exige `colaborador_id IS NULL`, así que la ficha desaparece del selector y no puede asociarse a otra persona. Prueba roja: `AtaquesVariosTest::test_Defecto_ficha_profesional_heredada_queda_inutilizable_tras_revocar_su_invitacion`.

### DEF-09 (Media-Baja) Con `APP_ENV` distinto de `production|prod|produccion` se usa una clave derivada de `DB_NAME`/`DB_PASS`
- Comprobado con `APP_ENV=''`, `staging`, `Production ` (espacio final), `prod-eu`, `live`: `Secretos::clave()` no falla y deriva la clave de `hash('sha256','appsalon-dev|'.DB_NAME.'|'.DB_PASS)` (`classes/Secretos.php:20`). Quien conozca esos dos valores descifra las cargas pendientes del outbox (tokens de invitación) y calcula las huellas de idempotencia. `PagoController` sí usa `trim()` en la misma comprobación; aquí no.
- Prueba roja: `FallosTest::test_Defecto_clave_derivada_de_desarrollo_se_usa_si_APP_ENV_no_es_production`. (Con `APP_ENV=production` y sin clave el comportamiento es correcto: 503 y el instalador aborta — PASA.)

### DEF-10 (Media-Baja) `instalar.php --regenerar-activacion` con otro correo crea la cuenta con el correo anterior
- **Pasos:** primera ejecución con un correo mal escrito; el operador corrige `OWNER_EMAIL` y usa `--regenerar-activacion`. El enlace y el correo salen hacia el correo nuevo, pero `colaboradores.email_norm` conserva el viejo y `aceptar()` crea `usuarios.email` con el viejo: el propietario no podrá entrar con su correo.
- **Causa probable:** rama `$pendiente` de `aprovisionarPropietarioInicial()` (`ColaboradorRepository.php:249-256`) reusa la fila y no actualiza `email_norm`/nombre.
- **Prueba roja:** `InstaladorTest::test_Defecto_regenerar_activacion_con_otro_correo_emite_el_enlace_para_el_correo_equivocado`.

### DEF-11 (Baja) Instalador en paralelo sobre una base vacía: las instancias perdedoras fallan
- 4 `php scripts/instalar.php` simultáneos: 1 sale 0 y 3 salen 1 con «Duplicate column name 'token_hash'»; el estado final es correcto (18 migraciones, un propietario, un enlace) y 6 rondas de 2 instancias no dejaron la base a medias. El README del script afirma seguridad «en paralelo»: la carga de `schema_base.sql` y el migrador (`scripts/instalar.php:60-89`) no tienen candado.
- Prueba roja: `InstaladorTest::test_Defecto_instalador_paralelo_sobre_base_vacia_hace_fallar_a_las_instancias_perdedoras`.

### DEF-12 (Baja) Campos arreglo en nombre/apellido/correo de `/admin/equipo/crear` generan advertencias PHP
- `controllers/EquipoController.php:76` guarda `$_POST` completo en `$_SESSION['equipo_previo']`; `views/equipo/index.php` lo imprime con `s()` (`includes/funciones.php:18`, `(string)$array`): `PHP Warning: Array to string conversion` en cada reproducción. No hay 500 ni fuga visible.
- Observación asociada: `telefono[]`, `puesto[]` y `fecha_ingreso[]` se aceptan en silencio como vacíos (la invitación se crea) en lugar de rechazarse con error de validación; los errores de las mutaciones de equipo responden 302+flash, no 422.
- Prueba roja: `FallosTest::test_Defecto_invitar_con_campo_arreglo_emite_advertencia_al_reproducir_el_formulario` (3 casos: nombre, apellido, email).

### DEF-13 (Baja) El registro público revela si un correo ya tiene cuenta (incluido el del personal)
- `services/AuthService.php:215,253`: «El usuario ya está registrado» (422) frente a 302 para un correo nuevo. El rate limit por correo/IP lo acota, pero ahora los correos del personal también son enumerables. Login, `/olvide` y `/reenviar-confirmacion` NO enumeran (PASA).
- Prueba roja: `TokensTest::test_Defecto_registro_publico_revela_si_un_correo_ya_tiene_cuenta`.

### DEF-14 (Media-Baja) Recepción (y gerencia) puede reescribir el perfil del propietario
- `POST /perfil?id=<propietario>` con sesión de recepción cambia nombre, apellido y teléfono de la cuenta del propietario (y `POST /perfil/foto?id=` puede sustituir su foto): `controllers/ProfileController.php:18` solo exige `perfiles.gestionar` y no limita el destino a perfiles de cliente.
- Prueba roja: `AtaquesVariosTest::test_Defecto_recepcion_puede_editar_el_perfil_del_propietario_y_de_otro_personal_por_perfil_id`.

### DEF-15 (Baja) Nombre y apellido de 60 caracteres (válidos en el formulario) impiden crear la invitación
- «nombre apellido» tiene 121 caracteres y `correos_salida.destinatario_nombre` es `VARCHAR(120)` (`018_colaboradores_invitaciones.sql:64`); con rol profesional también la ficha (`nombre_profesional` por defecto, `VARCHAR(120)`). El usuario ve el error genérico «No se pudo guardar la invitación».
- Prueba roja: `AtaquesVariosTest::test_Defecto_nombre_y_apellido_de_60_caracteres_validos_para_el_formulario_hacen_fallar_la_invitacion`.

### DEF-16 (Baja) El ciclo rollback/reaplicación de la migración 016 reactiva clientes suspendidos
- La retrocarga solo deriva `suspendido` de `usuario_accesos.activo`; una suspensión explícita de `clientes` se pierde (cliente 3 vuelve a `activo`). El rollback elimina la tabla por definición, pero la reaplicación reactiva en silencio. Prueba roja: `EsquemaTest::test_Defecto_E2_rollback_y_reaplicacion_de_016_reactiva_en_silencio_a_clientes_suspendidos`.

### DEF-17 (Baja, operativo) El outbox de correos no tiene proceso programado ni documentación
- `scripts/procesar_correos.php` no aparece en `docker-compose.yml` (solo `notificaciones_worker.php`), ni en cron, ni en `docs/`, `database/README.md` o las guías: un correo fallido solo se reintenta si alguien abre `GET /admin/equipo` (y ahí se une a DEF-05). Prueba roja: `CorreoTest::test_Defecto_el_outbox_de_correos_no_tiene_proceso_programado_ni_documentacion_operativa`.

### DEF-18 (Baja, documentación) Recuentos de tablas obsoletos
- El esquema migrado tiene **35** tablas (incluye `migraciones` y `equipo_mutex`; 30 hasta la 015, +5 en 016-018). `scripts/backup_db.php:13` sigue diciendo «13 tablas en total» y `docs/ENTREGA_PLATAFORMA_INTEGRAL.md`, `docs/QA_PLATAFORMA_INTEGRAL.md`, `docs/RESPONSIVIDAD_CORREOS_ERRORES.md` citan «30 tablas». Ninguna prueba fija el número (no hay ruptura funcional). Prueba roja: `EsquemaTest::test_Defecto_E5_conteo_de_tablas_y_documentacion`.

## 4. Observaciones (sin prueba roja)

- **Ráfaga idéntica de registro:** 6 envíos concurrentes con la misma clave dan 1 cuenta, 1 correo y, a los sobrantes, `rate_limited` (límite 3 por correo): correcto pero menos amable que devolver el resultado original (`ConcurrenciaTest::test_registro_misma_clave_6_procesos…` lo tolera).
- **Registro con SMTP caído:** la confirmación de registro no usa el outbox; no hay reintento automático y `/mensaje` dice «Revisa tu correo». Se mitiga con el enlace «Reenviar correo» (probado: funciona). Comportamiento heredado, no regresión.
- **`GET /equipo/aceptar` con una invitación vencida escribe** (`vistaInvitacion()` marca `expirada`); no consume ni activa nada.
- **Retención:** `operaciones_idempotentes` (TTL 30 d) y `correos_salida` no tienen purga; crecen con el uso real.
- **Modelo de amenaza de «aceptación con cuenta existente»:** defendible. Cuenta confirmada: exige su contraseña actual (limitada a 8 intentos por token y 20 por IP; con la contraseña correcta no se cambia). Cuenta sin confirmar: el correo recibido prueba el control del buzón, se fija la contraseña elegida, se confirma y se anulan token de confirmación/recuperación previos; quien pre-registró el correo con su contraseña no obtiene acceso (probado). Riesgos residuales aceptados: el propietario puede invitar a un correo equivocado y esa persona obtendría el rol; los datos de perfil (nombre/teléfono) puestos por quien pre-registró se conservan; aceptar incrementa `version_sesion` y cierra la sesión de cliente abierta de esa persona.
- **Cifrado de cargas:** cambiar `APP_KEY` deja las cargas pendientes ilegibles; el evento queda `pendiente`/`fallido` con «Carga ilegible» sin enviarse (probado) — hay que reenviar la invitación.

## 5. Tabla de ataques ejecutados

Las pruebas están en `tests/Qa/`. «Cmd» es el archivo a ejecutar con el comando de la sección 1.

### A. Concurrencia y duplicados (procesos reales con barrera) — `ConcurrenciaTest`, `InstaladorTest`, `RegresionNegocioTest`

| # | Ataque | Resultado | Evidencia |
|---|---|---|---|
| A1 | Registro público, misma clave, 6 procesos | PASA | 1 `usuarios`, 1 `clientes`, 1 fila `operaciones_idempotentes`, exactamente 1 correo; los sobrantes reciben 429 (observación) · `ConcurrenciaTest::test_registro_misma_clave_6_procesos…` |
| A2 | Registro, 6 claves distintas, mismo correo | PASA | 1 identidad, 1 `ok`, 1 correo, sin reservas idempotentes huérfanas |
| A3 | Registro con variantes `Mayús`, ` espacios `, 4 procesos | PASA | 1 identidad por `LOWER(TRIM(email))` |
| A4 | `profesional.crear` misma clave (6) y claves distintas (4) | PASA | 1 profesional y 1 relación de servicio; profesionales = operaciones confirmadas |
| A5 | Invitar misma clave, 6 procesos | PASA | 1 colaborador, 1 invitación, 1 `correos_salida` |
| A6 | Invitar con 6 claves distintas, mismo correo | PASA | 1 ficha, 1 evento, 1 reserva idempotente (las perdedoras revierten) |
| A7 | Invitar profesional concurrente (5) | PASA | 1 ficha profesional, 0 fichas sin colaborador |
| A8 | Reenvío simultáneo (6) con claves distintas | **DEFECTO** DEF-06 | 7 invitaciones, 5 eventos vivos · `test_Defecto_reenvio_simultaneo…`; el control secuencial PASA |
| A9 | Aceptación simultánea del mismo token (6) | PASA | 1 `ok`, 1 usuario, 1 acceso, sin membresía de cliente |
| A10 | Aceptar vs. revocar (6 rondas en la suite; 25 manuales), reenviar vs. aceptar y reenviar vs. revocar (25 manuales) | PASA | estado final siempre coherente; 0 `status=error`/1213 expuestos (no prueba la ausencia) |
| A11 | Cambio de rol vs. aceptación (5 rondas) | PASA | rol de la ficha = rol efectivo |
| A12 | Degradación/suspensión cruzada de dos propietarios (`EquipoRepository` y `cambiarAcceso`, 4 rondas) | PASA | siempre ≥ 1 propietario activo; una operación rechazada |
| A13 | Instalador en paralelo, base migrada, modo enlace (6) y modo secreto (6 y 5) | PASA | 1 colaborador propietario, 1 invitación, 1 correo; contraseña de una cuenta existente intacta; una sola salida «configurado» |
| A14 | Instalador en paralelo, base **vacía** (4; y 6 rondas de 2) | **DEFECTO** DEF-11 (estado final correcto) | 3 de 4 salen 1 con «Duplicate column»; en 6 rondas de 2 la base quedó siempre completa |
| A15 | Deadlock 1213 sin reintento | NO PROBADO (no reproducido) | hipótesis 1 |
| A16 | `operaciones_idempotentes` huérfanas y correos duplicados | PASA (salvo DEF-06) | A2, A5, A6 |
| A17 | Suspender profesional con citas futuras: secuencial / en carrera con una reserva | PASA / **DEFECTO** DEF-02 | `…es_rechazado_y_no_deja_citas_huerfanas` / 8 de 8 carreras |

### B. Autorización por acceso directo — `AutorizacionHttpTest`, `AtaquesVariosTest`, `CabecerasYDisponibilidadTest`

| # | Ataque | Resultado | Evidencia |
|---|---|---|---|
| B1 | Matriz 5 roles × 12 rutas GET (equipo, clientes, exportar, profesionales, finanzas, inventario, crecimiento, ajustes, reportes, admin, servicios, agenda) con sesión real | PASA | 403/200 exactos según `AuthorizationService::ROLES` |
| B2 | Cliente y anónimo en todas las rutas laborales (y fichas); `/api/mis-citas` anónimo | PASA | 403 / 302→login / 401 JSON incluso con `Accept: text/html` |
| B3 | Persona con ambos perfiles: portal cliente (rutas laborales y fichas CRM) y portal equipo | PASA | 403 en cliente; 200/403 según rol en equipo |
| B4 | Sesión forjada: cuenta cliente con `rol=propietario`,`admin=1`,`portal=equipo`; cuenta gerente con `portal=cliente`; sin `portal` y con `rol` falso | PASA | la fuente de verdad es el servidor |
| B5 | `version_sesion` vieja; colaborador suspendido con sesión abierta; baja; invitado pendiente; cuenta borrada con sesión | PASA | la sesión expira; el login se rechaza con el mensaje de suspensión (403) |
| B6 | Cliente suspendido «puro»: login y sesión abierta | PASA | 403 y sesión cerrada |
| B7 | Cliente suspendido **con rol laboral**: sesión abierta y reserva por API | **DEFECTO** DEF-03 | `test_Defecto_cliente_suspendido_con_rol_laboral…` (2 pruebas) |
| B8 | Colaborador suspendido con membresía de cliente activa | **DEFECTO** DEF-04 | login 302 y sesión invalidada |
| B9 | Gerente, recepción, finanzas y profesional con CSRF válido: `actualizar` (autoascenso a propietario), `crear`, `invitar`, `revocar`, `ficha`, `profesionales/crear` | PASA | 403 y huella de tablas intacta; el dual-propietario en portal cliente también recibe 403 |
| B10 | CSRF ausente/vacío/erróneo/arreglo y `idem_key` usado como CSRF en 15 mutaciones (más registro y aceptar) | PASA | 403, 0 cambios, la sesión sigue viva |
| B11 | `GET/HEAD /equipo/aceptar` no consume el token; un POST sin CSRF tampoco | PASA | la invitación sigue `pendiente` |
| B12 | Mass assignment en `/cliente/registro` (`admin`,`confirmado`,`rol`,`id`,`origen`,`estado`…) | PASA | `admin=0`, `confirmado=0`, sin acceso laboral, membresía `registro_publico` |
| B13 | Fijación de sesión, cookie `HttpOnly`/`SameSite=Lax`, id previo e id tras `logout` | PASA | el id cambia al iniciar sesión y deja de servir al salir |
| B14 | Cabeceras (`nosniff`, `SAMEORIGIN`, `Referrer-Policy strict-origin…`), `Cache-Control: no-store` en páginas laborales y con token, 405 en métodos no permitidos, CRM solo lectura | PASA | `CabecerasYDisponibilidadTest` |
| B15 | `/perfil?id=<propietario>` con sesión de recepción | **DEFECTO** DEF-14 | nombre y teléfono del propietario reescritos |

### C. Privacidad y escape — `PrivacidadTest`, `TokensTest`, `CorreoTest`

| # | Ataque | Resultado | Evidencia |
|---|---|---|---|
| C1 | Marcadores de formulario de salud, foto privada y `notas_rh` buscados en índice, ficha, CSV operativo y CSV comercial para 6 personas | PASA | 0 apariciones |
| C2 | Importes, referencias de pago y proveedor para recepción y profesionales | PASA | ausentes; control positivo en gerente |
| C3 | Finanzas (solo analítica): sin datos nominales, sin ficha, sin exportación | PASA | 403 / ausentes |
| C4 | Profesional: solo clientes que atendió; ficha ajena y cuenta laboral → 404 idéntico al inexistente; el filtro `profesional_id` no amplía el alcance | PASA | |
| C5 | `notas_rh` solo en la ficha laboral del propietario | PASA | ni el listado general las muestra |
| C6 | Escape de `<script>`, `<img onerror>`, `<svg onload>` y comillas en CRM (lista/ficha), equipo (lista/ficha), aceptar, encabezados de ambos portales, formularios públicos y HTML del correo | PASA | ninguna etiqueta cruda |
| C7 | Inyección de fórmulas en CSV (`=`, `+`, `@`, tabulador+`=`) | PASA | celdas con `'` previo |
| C8 | Parámetros hostiles del CRM (arreglos, listas, fechas, `%`, `' OR 1=1`) | PASA | 200/422, sin 500 ni advertencias |
| C9 | Enumeración: login (ambos portales), `/olvide`, `/reenviar-confirmacion` | PASA | respuestas indistinguibles |
| C10 | Enumeración: registro público | **DEFECTO** DEF-13 | 422 vs 302 |
| C11 | Tokens en el registro de errores del servidor, en auditoría, en `correos_salida` y en la base (solo hash) | PASA | |

### D. Tokens — `TokensTest`, `InstaladorTest`, `CorreoTest`

| # | Ataque | Resultado | Evidencia |
|---|---|---|---|
| D1 | Propósito cruzado: invitación ↔ confirmación ↔ recuperación | PASA | 410/404; la cuenta sin confirmar sigue sin confirmar |
| D2 | Expiración: 7 días exactos; vencida hace 1 s rechazada; con 3 s restantes aceptada | PASA | |
| D3 | Reutilización tras reenviar, revocar, aceptar (y cambio de contraseña con token usado) | PASA | contraseña intacta |
| D4 | Formato: mayúsculas, `\n`, espacios, 63/65 caracteres, `%00` | PASA | solo `[a-f0-9]{64}` exacto |
| D5 | Fuerza bruta de la contraseña actual | PASA | 429 tras ≤ 8 intentos; la correcta tampoco pasa durante el bloqueo |
| D6 | Toma de control: pre-registro del correo ajeno (sin confirmar) + invitación | PASA | la contraseña del atacante queda invalidada |
| D7 | Cuenta confirmada exige contraseña actual; no cambia el hash; conserva citas | PASA | |
| D8 | Invitar el correo de un propietario activo con otro rol | PASA | `conflict` (UNIQUE) |
| D9 | El enlace del correo usa `APP_URL`, no `Host`/`X-Forwarded-Host`; un solo token; sin contraseñas | PASA | |
| D10 | `--regenerar-activacion` invalida el enlace anterior | PASA | |
| D11 | `--regenerar-activacion` con otro correo | **DEFECTO** DEF-10 | cuenta con el correo anterior |
| D12 | `APP_KEY` distinta: carga ilegible, no se envía, queda reintentable | PASA | |

### E. Migraciones y esquema — `EsquemaTest`

| # | Ataque | Resultado | Evidencia |
|---|---|---|---|
| E1 | Esquema *fresh* (schema_base + 001-018) vs *upgrade* (base poblada en 009 → `migrador up`) | PASA | tablas, columnas (incluido su orden), índices (incluidos el funcional `uq_usuarios_email_normalizado` y el de invitación pendiente), FK, motor y colación idénticos; un segundo `up` no aplica nada |
| E1b | Retrocargas con datos reales: admin heredado → propietario/colaborador `COL-xxxxxx`; clientes 2-4 con membresía; el admin sin citas no es cliente | PASA | más login real del admin heredado (equipo), del cliente heredado (cliente) y de un correo con mayúsculas |
| E2 | Rollback individual de 018, 017 y 016 y reaplicación con el migrador | PASA | esquema idéntico al completo |
| E2b | `migrador down` completo + `up` | PASA | esquema idéntico a *fresh* |
| E2c | Rollback+up de 016 conserva suspensiones | **DEFECTO** DEF-16 | |
| E3 | Precheck 017 con correos duplicados normalizados (base en 016 sin índice) | PASA | exit 1; nombra los ids; remite a `diagnosticar_duplicados.php`; esquema y datos sin cambios; 018 no aplicada; tras resolver, migra |
| E3b | Correos que parecen duplicados pero no lo son (`+1`, puntos) | PASA | migra |
| E4 | Respaldo/restauración con `--gzip` y hash: 35 tablas, índice funcional, UNIQUE de invitación y de evento, FK `SET NULL`, texto con `;`, comillas, `--`, saltos de línea, `); DROP TABLE` y emoji | PASA | datos y esquema idénticos; un hash erróneo no toca la base |
| E4b | Restaurar sobre una base con datos, con un respaldo truncado (sin marcador final) y con un hash erróneo | PASA | exit ≠ 0 en los tres casos; la base destino queda intacta / vacía |
| E5 | Conteo «30 tablas» de la documentación | **DEFECTO** DEF-18 (documental) | 35 reales |

### F. Regresión de negocio — `RegresionNegocioTest`

| # | Ataque | Resultado | Evidencia |
|---|---|---|---|
| F1 | Cliente nacido por registro público (HTTP + SMTP simulado): correo único → confirmar → login → reservar → doble reserva (409) → mis-citas → pago mock → cancelar → reprogramar → en servicio → completada → aparece en el CRM | PASA | |
| F2 | El worker de notificaciones entrega la confirmación de cita | PASA | `procesar_notificaciones.php --json` exit 0 y correo recibido |
| F3 | Persona con ambos perfiles: reserva, cancela lo propio, no cancela lo ajeno, no paga lo ajeno; `/admin` y `/agenda` dan 403 en portal cliente y 200 en portal equipo | PASA | |
| F4 | Comisión (10 % de 25,00 = 2,50) e inventario con un profesional creado por invitación | PASA | |

### G. Fallos y estados vacíos — `FallosTest`, `CorreoTest`, `CabecerasYDisponibilidadTest`

| # | Ataque | Resultado | Evidencia |
|---|---|---|---|
| G1 | SMTP caído en invitación | PASA | la UI dice «guardada/en cola»; evento `pendiente` con 1 intento y carga; al volver el SMTP se envía 1 correo y se borra la carga |
| G2 | SMTP que acepta la conexión y no saluda | **DEFECTO** DEF-05 | 300,1 s por envío |
| G3 | `APP_ENV=production` sin `APP_KEY` | PASA | alta → 503 con `Retry-After: 60` y sin filas; el instalador sale 1 |
| G4 | `APP_ENV` distinto de production sin `APP_KEY` | **DEFECTO** DEF-09 | |
| G5 | Base sin migrar (esquema 009) con sesión forjada y con login válido | PASA | 503 HTML y 503 JSON, sin SQL ni nombres de tabla |
| G6 | Base caída (puerto cerrado) | PASA | 503 HTML y JSON sin detalles |
| G7 | `idem_key` ausente, vacía, corta, de 65, con símbolos, con espacios o unicode en registro, invitación y profesional | PASA | 422 / aviso «venció»; 0 filas |
| G8 | Campos arreglo en registro, login de ambos portales y 18 campos de equipo | PASA | sin 500 ni advertencias |
| G9 | Arreglo en `nombre`/`apellido`/`email` de la invitación | **DEFECTO** DEF-12 | advertencia al reproducir el formulario |
| G10 | `servicios` no escalares o inexistentes | PASA | error controlado, sin fichas |
| G11 | Misma clave con otro contenido → 409; mismo contenido → «ya registrada» | PASA | |
| G12 | `AuthorizationService` con conexión cerrada o tabla ausente | PASA | `ServiceUnavailableException`, pista «ESQUEMA DESACTUALIZADO» en el log, sin SQL |
| G13 | Nombre y apellido de 60 caracteres cada uno | **DEFECTO** DEF-15 | |
| G14 | Identificadores hostiles (`id[]`, `1abc`, `-1`, `0`, `1e3`, `%00`, 20 dígitos) en `/admin/equipo/ficha`, `/admin/clientes/ficha`, `/perfil`, `/perfil/foto` | PASA | 404/422 sin advertencias |
| G15 | Homónimo de profesional en la invitación | PASA | exige `confirmar_homonimo`; sin él no se crea ficha |

### H. Frontend (Chromium 1194) — `FrontendTest` + `frontend_qa.cjs`

| # | Ataque | Resultado | Evidencia |
|---|---|---|---|
| H1 | 7 rutas (`/cliente/login`, `/cliente/registro`, `/equipo/login`, `/equipo/aceptar`, `/admin/equipo`, `/admin/equipo/ficha`, `/admin/clientes`) × 320/390/768/1440 px | PASA | 28 mediciones sin desbordamiento horizontal |
| H2 | Errores de consola, `pageerror`, recursos 4xx/5xx | PASA | 0 |
| H3 | Guardián `form[data-una-vez]`: doble clic + Enter | PASA | 1 sola petición, 1 colaborador, 1 correo |
| H4 | Estado durante el envío y clics repetidos | PASA | `aria-busy`, `aria-disabled` y mensaje `role=status`; 3 clics → 1 envío |
| H5 | Teclado: orden de Tab (correo antes que contraseña), foco visible en todos los controles, Enter envía | PASA | |
| H6 | Sin JavaScript: formularios de invitación y de aceptación utilizables; el bloque de profesional queda visible | PASA | invitación creada |
| H7 | El selector de rol oculta/muestra el bloque de profesional | PASA | |

## 6. Hipótesis no comprobadas

1. **Deadlock sin reintento dentro del alta idempotente:** `Idempotencia::ejecutar()` solo reintenta un 1213 reconocido por `errno()`; con `MYSQLI_REPORT_OFF` los errores de las escrituras internas llegan como `PersistenceException` sin código y no se reintentan. No se logró provocar un 1213 en 75 carreras aceptar/revocar/reenviar, ni en la creación de invitaciones; queda como riesgo teórico (el orden de bloqueo de `aceptar()` —invitación y luego colaborador— es el inverso del de `revocarInvitacion()`).
2. **Invitar con un propietario degradado en paralelo:** `crearInvitacion()` comprueba `exigirPropietario()` con una lectura de instantánea y no toma `equipo_mutex`; un propietario degradado a la vez podría emitir una invitación. Ventana de milisegundos, sin prueba.
3. **PHP 8.2:** solo se dispuso de PHP 8.3.6. Una búsqueda de construcciones exclusivas de 8.3/8.4 (`json_validate`, `mb_str_pad`, constantes tipadas, `#[Override]`, `array_find`…) no halló coincidencias, pero no se ejecutó nada en 8.2.
4. **Unicode en correos:** `Identidad::normalizarEmail` (`mb_strtolower`) frente a `LOWER()` de MySQL con otras colaciones; `FILTER_VALIDATE_EMAIL` rechaza no-ASCII, por lo que no se pudo alcanzar.
5. **MySQL < 8.0.13 / MariaDB:** el precheck de la 017 lo detecta por código, no se probó con ese motor.
6. **Entrega SMTP «exactamente una vez»:** el propio código documenta que no puede garantizarse si el servidor acepta y la conexión se corta; no se simuló esa interrupción.

## 7. No cubierto

- Apache/Docker (`Dockerfile`, `docker-compose`, `tests/run_browser_e2e.sh`), scripts de PowerShell, el pipeline de GitHub Actions y el build de Gulp/Sass (se usó el `public/build` versionado).
- Navegadores distintos de Chromium; lectores de pantalla (solo foco, orden de tabulación y atributos ARIA del guardián); 320/390/768/1440 px sin desbordamiento sí se midió.
- Carga y volumen (rendimiento de `contexto()` —una consulta por llamada, varias por petición—, CRM con >10 000 clientes, respaldo en memoria de bases grandes).
- Fuerza bruta de login a escala, temporización de comparación de tokens (se comparan por hash SHA-256 con índice único) y correos reales.
- Concurrencia por encima de 6 procesos y bajo estrés de I/O; relojes desajustados entre PHP y MySQL (todo se compara en UTC de MySQL).
- No se ejecutó la suite del equipo (comparte nombres de bases con su propio trabajo); solo la de `tests/Qa`.

## 8. Notas de higiene del arnés

- `QaCase::limpiar()` trunca todas las tablas de la base QA salvo `migraciones` y `equipo_mutex` (semilla). `ColaboradorRepository::aprovisionarPropietarioInicial()` se usa como camino real para crear propietarios.
- Los correos nunca salen de la máquina: SMTP simulado propio o puerto cerrado (2599).
- `executionOrder="depends,defects"` del `phpunit.xml` reordena las pruebas rojas primero; las clases se aíslan con `limpiar()` y servidores propios.

---

# Ronda 2 – Matriz de autorización exacta

Fase de refuerzo de `AutorizacionHttpTest` (QA independiente, sin tocar código de la aplicación). Requisito: para cada ruta y modalidad se comprueba el
contrato exacto (status, `Location` exacto si hay redirección, cuerpo/código de error exacto si es JSON), la ausencia de datos privados y la ausencia de
efectos; no se acepta «cualquier redirección» ni «distinto de 200».

## R2.1 Qué es una «celda» y cómo falla

Celda = ruta × método × modalidad de acceso × variante (formato, CSRF, HEAD…). Cada celda exige a la vez:

| Comprobación | Regla |
|---|---|
| Status | igual al contrato derivado del código (no una lista de valores admitidos) |
| Redirección | 302/303 con `Location` **idéntico** y **cuerpo vacío**; el destino se sigue con la misma sesión: sin bucle y sin terminar en 401/403/otra redirección (excepto `POST /api/eliminar`, ver DEF-R2-02) |
| JSON | `Content-Type: application/json; charset=utf-8` y cuerpo exacto (`{"resultado":false,"error":"…"}` y variantes propias de cada API) |
| HTML | `Content-Type: text/html; charset=utf-8` y el mensaje esperado (permiso, CSRF, 404, 405…) |
| Cabeceras | `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin` en toda respuesta; `Cache-Control` con `no-store` en todo lo que no sea el catálogo público/webhook |
| Privacidad | ningún centinela de otras personas (correo, nombre, teléfono, referencia/importe de pago, motivo de cancelación, respuesta de salud, nota/puesto/teléfono de RR. HH., producto/SKU/concepto de caja) en cuerpo **ni cabeceras** (crudo, escapado HTML, URL o JSON) salvo que el rol lo vea por diseño |
| Efectos | `CHECKSUM TABLE` + conteo de las 32 tablas de negocio antes/después y conteo del buzón SMTP: igual. Excluidas, y por qué: `migraciones` (esquema), `equipo_mutex` (semilla del candado), `intentos_login` (bitácora legítima del limitador: un login fallido DEBE contarse) |

Un fallo imprime `[GET /admin/equipo | recepcion | html] status: esperado 403 html …; obtenido 200 …` (ruta, método, modalidad, variante, esperado vs. obtenido).
Cada modalidad usa una **sesión PHP nueva** (cookie fabricada con los mismos campos que escribe `LoginController`, token CSRF conocido) para que la
mutación de sesión de una petición no contamine la siguiente; `test_G_*` demuestra con **logins reales** de los 6 perfiles que la sesión fabricada equivale a la real.

## R2.2 Modalidades (19)

| Modalidad | Qué es | Contrato derivado del código |
|---|---|---|
| `anonimo` | sin cookie | HTML 302 → `/`; `/api/*` o `Accept: application/json` → 401 JSON |
| `login_no_booleano` | `login='1'` (no `true`) | igual que anónimo (isAuth exige `=== true`); APIs con su JSON propio |
| `propietario` `gerente` `recepcion` `profesional` `finanzas` | login real del portal equipo | 200 si el rol tiene el permiso; si no, 403 con el mensaje de permiso |
| `cliente` | portal de clientes, rol efectivo `cliente` | 200 en rutas de sesión; 403 en todo lo laboral |
| `cliente_suspendido` | membresía `suspendido` con sesión abierta | 302 → `/` (sesión vaciada) |
| `cliente_no_confirmado` | `confirmado=0` | 302 → `/` |
| `colab_suspendido`, `colab_baja`, `colab_invitado` | acceso laboral revocado / invitación sin aceptar | 302 → `/equipo/login` |
| `forjada_equipo_en_portal_cliente` | cuenta gerente + `portal=cliente`, `rol=propietario`, `admin=1` | 302 → `/` (cuenta solo laboral sin membresía de cliente) |
| `forjada_cliente_en_portal_equipo` | cuenta cliente + `portal=equipo`, `rol=propietario`, `admin=1` | 302 → `/equipo/login` |
| `portal_inexistente_cuenta_equipo` / `_cliente` | `portal=superadmin` | se deriva del rol real del servidor: se comporta exactamente como gerente / como cliente (sin elevación) |
| `usuario_id_inexistente`, `usuario_eliminado` | id que no existe / fila borrada tras iniciar sesión | 302 → portal de la marca de sesión (`/equipo/login` o `/`); JSON 401 |
| `sesion_sin_id` | `login=true` sin `id` | APIs: JSON 401 propio; resto: 302 → `/` |
| `version_sesion_vieja` | `version_sesion` distinta de la del servidor | 302 → `/equipo/login` |

Formatos: `Accept: application/json`, JSON + `X-Requested-With`, solo `X-Requested-With` (**no** cambia el formato: sigue HTML/302), `Accept: */*`, JSON con q menor que HTML, HTML con q menor que JSON, `application/problem+json`.

## R2.3 Rutas (82 pares método+ruta únicos = las 83 llamadas `$router->get/post` de `public/index.php`; 87 filas porque algunas se prueban con varios ids)

La prueba `test_H_la_matriz_cubre_exactamente_las_rutas_registradas_en_index_php` compara la matriz con el código fuente y falla si se añade/quita una ruta sin actualizar `tests/Qa/matriz_rutas.php`.
Guardias: 24 filas públicas, 16 de sesión (`isAuth`), 46 por permiso (`exigir_permiso`), 1 especial (`GET /perfil/foto`, contrato propio en `test_J`).
Los roles que superan cada guardia están escritos a mano en la matriz (no se importan de `AuthorizationService::ROLES`); `test_H_el_catalogo_de_permisos…` los compara con la constante.

## R2.4 Mapa de cobertura

| Prueba | Qué recorre | Celdas |
|---|---|---|
| `test_A_*` (86 datasets, uno por ruta) | todas las rutas × 19 modalidades, navegación HTML; contrato exacto + privacidad + cero efectos | 1634 |
| `test_B_*` (7 datasets) | rutas protegidas × 8 modalidades × 7 formatos de petición, solo celdas de denegación | 3031 |
| `test_C_*` | `HEAD` de todas las rutas GET × 6 modalidades: mismo status/Location, sin cuerpo | 276 |
| `test_D_metodo_no_registrado…` | 59 rutas × métodos no registrados (GET/HEAD/POST/PUT/DELETE/PATCH/OPTIONS) × 3 modalidades × HTML/JSON: 405 + `Allow` exacto antes de autenticar | 1734 |
| `test_D_ruta_inexistente…` (20 datasets) | rutas inexistentes, con barra final, mayúsculas, `//`, `..` sin normalizar, `/.env`, `/vendor/…`: 404 sin filtrar nada | 200 |
| `test_E_post_sin_csrf…` (5 datasets: ninguno, vacío, otro, de otra sesión, arreglo) | 39 rutas POST con CSRF × (autorizado, autenticado sin permiso, anónimo) × HTML/JSON: 403 por CSRF o por permiso/autenticación según el **orden real** de la ruta, cero efectos | 990 |
| `test_E_el_token_csrf_tambien_se_acepta_por_cabecera…` | `X-CSRF-Token` válido equivale al campo del formulario | 60 |
| `test_F_*` (20 escenarios) | con **datos válidos**: todas las demás modalidades → denegación exacta y cero efectos; CSRF defectuoso → cero efectos; la persona autorizada → respuesta y **efecto exacto** (tablas y variación de filas); repetir con la misma clave → cero efectos | ver tabla R2.5 |
| `test_G_*` (6 datasets) | login real de los 6 perfiles vs. contrato de la matriz en todas las rutas GET | 6 × ~45 |
| `test_I_*` | cada 302/303 de la matriz seguido con la misma sesión: sin bucle, sin 401/403 | todas las redirecciones |
| `test_J_*` (2) | `GET /perfil/foto` (guardia híbrida) × 19 modalidades × 3 ids × HTML/JSON | 114 |
| `test_K_*` | `/api/mis-citas` solo devuelve citas propias, `/api/profesionales` solo campos públicos, JSON 401 propio de cada API, cookie HttpOnly/SameSite y regeneración del id | — |
| `AutorizacionHttpTest` (37) | casos de la ronda 1 con aserciones laxas sustituidas por exactas (ver R2.7) | — |

### R2.5 Escenarios con efecto (`test_F_*`)

| Escenario | Quién lo ejecuta | Efecto exacto verificado |
|---|---|---|
| `equipo_crear` | propietario | +1 `colaboradores`, +1 `colaborador_invitaciones`, +1 `correos_salida`, +1 `equipo_auditoria`, +1 `operaciones_idempotentes`; repetir con la misma `idem_key` → nada |
| `equipo_actualizar` (suspender) | propietario | +1 `equipo_auditoria`; modifica `colaboradores`, `usuario_accesos`, `usuarios` (`version_sesion`) |
| `equipo_revocar` | propietario | +1 `equipo_auditoria`; modifica `colaboradores`, `colaborador_invitaciones`, `correos_salida` (cancela el correo pendiente) |
| `equipo_invitar_reenvio` | propietario | +1 invitación, +1 `correos_salida`, +1 auditoría, +1 idempotencia; repetir → nada |
| `equipo_ficha` | propietario | +1 auditoría; modifica `colaboradores` |
| `servicios_crear` / `_actualizar` / `_eliminar` | gerente / gerente / propietario | +1 / modifica / −1 `servicios`, y nada más |
| `profesionales_estado` | gerente | solo `profesionales` |
| `inventario_crear` | finanzas | +1 `inventario_productos` |
| `finanzas_registrar` | finanzas | +1 `caja_movimientos`; repetir con la misma clave → nada |
| `ajustes_guardar` | gerente | +6 `negocio_ajustes` |
| `perfil_ajeno_editado_por_recepcion` | recepción | modifica solo `perfiles_usuario`; profesional, finanzas y cliente → 403 sin efecto |
| `api_cancelar_cita_propia` | clienta propietaria | modifica `citas`, +1 `notificaciones_citas`; profesional y finanzas → 403 JSON `no_autorizado` sin efecto |
| `api_cancelar_cita_ajena_por_cliente` | nadie | cliente, profesional, finanzas → 403 JSON exacto, cero efectos |
| `agenda_estado` | profesional dueño de la cita | +1 `agenda_eventos`, modifica `citas`; la clienta y finanzas → 403 |
| `agenda_estado_cita_de_otro_profesional`, `agenda_reprogramar_cita_ajena` | nadie | profesional ajeno, cliente, finanzas → 403 «No tienes permiso para modificar esta cita.» sin efecto |
| `pagos_iniciar_cita_ajena` | nadie | cliente, profesional, recepción → 403 `forbidden`, sin pago nuevo |
| `pagos_reembolsar` | finanzas | modifica `pagos` y `citas` (`estado_pago`); recepción, profesional, cliente → 403; repetir → idempotente sin cambios |

## R2.6 NO cubierto (con motivo)

- **Rutas públicas con efecto legítimo** (`POST /cliente/registro`, `/olvide`, `/reenviar-confirmacion`, `/recuperar`, `/equipo/aceptar`, logins): la matriz solo prueba su rechazo sin datos válidos (422/403, cero efectos). Sus caminos felices y límites están en `TokensTest`, `ConcurrenciaTest`, `RegresionNegocioTest`; replicarlos aquí duplicaría fixtures de correo/tokens.
- **Caminos felices de `POST /api/citas`, `/profesionales/horarios|descansos|bloqueos`, `/perfil/foto` (subida), `/admin/crecimiento` y `/mi-bienestar`**: requieren disponibilidad/horarios, un binario JPEG o formularios dinámicos; en la matriz solo se prueban sus denegaciones y el rechazo de entrada vacía (cero efectos). Cubiertos funcionalmente por las pruebas del equipo y `RegresionNegocioTest`/`PrivacidadTest`.
- **`POST /api/pagos/webhook`**: es público por diseño (autenticación por firma HMAC, no por sesión). Se prueba que sin firma responde 401 JSON idéntico para todas las modalidades y sin efectos; la firma válida y el replay están en `tests/Integration`.
- **`GET /perfil/foto` con foto real**: el arnés no escribe archivos de imagen; la visibilidad de fotos está en `PrivacidadTest`.
- **`GET /confirmar-cuenta` y `GET /recuperar` con token válido**: consumen el token (efecto legítimo); cubiertos en `TokensTest`.
- **Reproducción en producción** (Apache/nginx): se usa `php -S`. Las diferencias previsibles (normalización de `..` y `//` por el servidor, `Cache-Control` por `session.cache_limiter`) no se midieron fuera de `php -S`.
- **IPv6/proxy/`X-Forwarded-*`**: `RateLimiter::obtenerIP` no interviene en autorización y no se manipuló.
- **Concurrencia de la matriz**: las celdas se ejecutan en serie (el servidor embebido es monohilo); la concurrencia está en `ConcurrenciaTest`.

## R2.7 Aserciones laxas sustituidas en `AutorizacionHttpTest`

| Antes | Ahora |
|---|---|
| `[200]` vs `[403]` con excepción «exportar puede devolver 200 a cualquiera» | 200 solo para los roles de la tabla literal; 403 con el mensaje de permiso; sin `Location` |
| anónimo: `Location ∈ {/, /equipo/login}`, estado `∈ {302,401}` | `302` + `Location === '/'` + cuerpo vacío; `/api/mis-citas` → 401 con el JSON completo y `Content-Type` exacto |
| sesión forjada de cuenta cliente en portal equipo: `∈ {302,403}` | `302` + `Location === '/equipo/login'` + cuerpo vacío en las 12 rutas |
| sesión forjada de cuenta gerente en portal cliente: `∈ {302,401,403}` | `302` + `Location === '/'` + cuerpo vacío |
| colaborador suspendido: `∈ {302,403}` y `≠ 200` | `302` + `/equipo/login`, y la pantalla de login muestra «Tu sesión terminó» |
| baja: login `∈ {403,422}`; como cliente `≠ 302` | equipo → 403 «Tu acceso al equipo está suspendido»; cliente → 422 «Esta cuenta pertenece al equipo del salón», sin `Location` |
| cliente suspendido: `≠ 200` | `302` + `/` |
| `/perfil`, `/perfil/foto`, `/api/mis-citas` anónimos: `∈ {302,401,404,422}` | `/perfil` → 302 `/`; `/perfil/foto` → 404 de la página estándar; `/api/mis-citas` → 401 con `No autenticado`; `/api/pagos/iniciar` sin sesión → 401 propio antes de mirar el CSRF |
| `HEAD /equipo/aceptar` `∈ {200}` | `200` con cuerpo vacío |

## R2.8 Hallazgos nuevos de la ronda 2

Pruebas rojas (en `AutorizacionMatrizTest`):

### DEF-R2-01 (Media) Reportes entregan datos nominales de clientes al rol «finanzas»
- El rol finanzas no tiene `clientes.ver` ni `clientes.exportar` (el CRM le niega fichas, listado nominal y CSV; `PrivacidadTest::test_finanzas_ve_analitica_pero_no_fichas_ni_listado_nominal` lo exige), pero tiene `reportes.ver`, y `/admin/reportes` y el CSV `/admin/reportes/exportar` incluyen nombre, apellido y correo de cada cliente con reservas (`ReporteRepository.php:400-403, 547-552`; columnas «Cliente»/«Email Cliente» del CSV en `ReporteService.php:1516-1533`).
- **Reproducción:** login `finanzas` → `GET /admin/reportes/exportar` → 200 `text/csv` con `sentinela.ajeno@example.test` (el cliente sembrado). La exportación nominal de clientes queda accesible a un rol que no tiene `clientes.exportar`.
- Prueba roja: `AutorizacionMatrizTest::test_Defecto_R2_01_reportes_entregan_nombre_y_correo_de_clientes_al_rol_finanzas_que_no_tiene_clientes_ver_ni_exportar`. (La matriz A excluye esa única combinación —finanzas × `/admin/reportes*`— de la regla de privacidad para no ocultar el resto de comprobaciones de ese rol.)

### DEF-R2-02 (Baja) `POST /api/eliminar` con id inválido redirige a una ruta que deniega a quien la recibe
- `APIController::cancelar()` (formulario sin `Accept: application/json`): identificador inválido → `Location: /admin` para **cualquier** sesión vigente. `/admin` exige `agenda.gestionar`: cliente, profesional y finanzas reciben 403. El mismo fallo ocurre en la rama «cita no encontrada» cuando no hay `Referer` (`/admin`).
- **Reproducción:** sesión de cliente con CSRF válido → `POST /api/eliminar` `id=no-es-un-id` → `302 Location: /admin` → `GET /admin` → `403`.
- Prueba roja: `test_Defecto_R2_02_api_eliminar_redirige_con_id_invalido_a_una_ruta_que_deniega_a_quien_la_recibe` (`test_I_*` excluye esta única celda para no duplicar el rojo).

### DEF-R2-03 (Baja, CWE-601) La cancelación por formulario redirige a la cabecera `Referer` sin validar el origen
- `APIController.php:452` (`header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/admin'))`) y `:505` (`$destino = $_SERVER['HTTP_REFERER'] ?? …`). Requiere un CSRF válido, por lo que hoy solo es alcanzable desde un cliente no navegador o tras XSS; aun así la aplicación nunca debería emitir un `Location` derivado de una cabecera del cliente.
- **Reproducción:** `POST /api/eliminar` (`id=99999999`, CSRF válido, `Referer: https://evil.example/phishing`) → `302 Location: https://evil.example/phishing`.
- Prueba roja: `test_Defecto_R2_03_cancelacion_por_formulario_redirige_a_cualquier_referer_incluso_externo`.

Observaciones (sin prueba roja; se afirma su valor actual, estable y coherente con el código):
1. **El anónimo que abre una pantalla del equipo (`/admin…`) va a `/` (acceso de clientes), no a `/equipo/login`**: sin sesión no se conoce el portal de destino; solo una sesión caducada recuerda su portal (`portal_aviso`). `/` enlaza el acceso del equipo.
2. **`X-Requested-With: XMLHttpRequest` sin `Accept` no cambia el formato**: `ErrorResponse::wantsJson()` solo mira `Accept` y el prefijo `/api`; un `fetch` sin `Accept: application/json` a una ruta HTML recibe 302/HTML. (Solo `APIController::solicitaRespuestaJsonExplicita()` usa la cabecera, y únicamente para la cancelación.)
3. **Un `portal` desconocido en la sesión se resuelve por el rol real del servidor** (`portal_sesion()`): no concede nada que la cuenta no tenga, pero es una vía de compatibilidad que conviene retirar cuando no queden sesiones antiguas.
4. **Existencia de citas enumerable**: `POST /api/eliminar` responde 403 para una cita ajena y 404 para una inexistente (JSON); la numeración es secuencial, así que revela qué ids existen. Impacto bajo (no revela datos).
5. **Orden validación/CSRF**: `POST /servicios/actualizar`, `/profesionales/actualizar|horarios|descansos|bloqueos` y `/admin/equipo/ficha` descartan la petición (302/404) por id ausente o inexistente **antes** de mirar el CSRF; con id válido el CSRF se exige antes de mutar (probado). Sin efectos, pero conviene exigir CSRF primero por defensa en profundidad.
6. **`GET /perfil/foto` sin foto responde 404 con cuerpo vacío** (`Content-Type: text/html`), a diferencia del resto de 404 de la aplicación.
7. **`/api/servicios` es público** (catálogo con precios); es coherente con la reserva pero no exige sesión a diferencia del resto de `/api/*`.
8. El mensaje de login de colaborador de **baja** dice «suspendido».

## R2.9 Sensibilidad de las pruebas (mutaciones sobre una copia, código real intacto)

Se aplicó cada mutación a una copia del árbol (`/tmp/qa_mut`), se ejecutó el filtro indicado y se comprobó que la prueba falla, con la discrepancia exacta; la aplicación original no se modificó.

| # | Mutación | Resultado |
|---|---|---|
| M1 | quitar `exigir_permiso` de `POST /servicios/eliminar` | ROJO (`[POST /servicios/eliminar \| anonimo] Location: esperado '/'; obtenido '/servicios'`) |
| M2 | destino de la redirección anónima `/` → `/cliente/login` | ROJO (`GET /admin \| anonimo`) |
| M3 | `Location` de `POST /admin/equipo/crear`: `/admin/equipo` → `/admin` | ROJO |
| M4 | quitar `exigir_csrf` de `POST /admin/inventario` | ROJO (esperado 403, obtenido 303) |
| M5 | `exigir_permiso` escribe en BD al denegar | ROJO (`EFECTO en BD: negocio_ajustes(n 0→1)`) |
| M6 | comentario HTML con el correo del cliente ajeno en todas las vistas | ROJO (`FUGA … PII:sentinela.ajeno@example.test` para profesional, finanzas…) |
| M7 | `cancelarCita` sin comprobar propiedad (la primera ocurrencia de la condición estaba en `CitaService::eliminar()`, método heredado que ningún controlador invoca —el endpoint siempre llama a `cancelarCita()`—, y la mutación **sobrevivió** por ser código inalcanzable por HTTP; se repitió sobre `cancelarCita()`) | ROJO (profesional y finanzas cancelan la cita de la clienta) |
| M8 | quitar `X-Frame-Options` | ROJO |
| M9 | `/servicios/actualizar` sin id redirige a sí misma | ROJO (`BUCLE de redirecciones`) |
| M10 | 405 sin `Allow` | ROJO |
| M11 | `/api/mis-citas` sin filtrar por usuario | ROJO |
| M12 | `mutarAgenda`: «profesional propio» sin comprobar la ficha | ROJO |
| M13 | `iniciarPago` sin comprobar propiedad de la cita | ROJO (recepción y profesional inician el pago de la cita ajena) |
| M14 | `reembolsar` exige `agenda.gestionar` en lugar de `finanzas.gestionar` | ROJO (recepción reembolsa; finanzas queda denegada) |
| M16 | `sesionVigente` ignora el estado laboral | ROJO (suspendido y baja entran a `/admin`) |
| M17 | cuerpo JSON 401 con punto final | ROJO |
| M18 | `portal_sesion()` devuelve siempre `equipo` | ROJO |
| M19 | cookie de sesión sin `HttpOnly` | ROJO |

## R2.10 Resultado de la ejecución completa (ronda 2)

Entorno: PHP 8.3.6, MySQL 8.0, PHPUnit 10.5.66, `DB_NAME=appsalon_qa_test`, SMTP simulado propio en 2599 (la clase arranca y detiene su propio buzón).

```
php -d variables_order=EGPCS vendor/bin/phpunit --do-not-cache-result --colors=never tests/Qa
Tests: 362, Assertions: 1803, Failures: 3, Skipped: 2.     (tiempo: 7 min 41 s)
```

- **Fallos (3, intencionados, pruebas rojas nuevas):** `test_Defecto_R2_01…`, `test_Defecto_R2_02…`, `test_Defecto_R2_03…` (ver R2.8). Todas las pruebas `test_Defecto_*` de la ronda 1 pasan con el código actual.
- **Omitidas (2), con motivo documentado y estable** (sin variable de entorno que las active):
  1. `TokensTest::test_Defecto_registro_publico_revela_si_un_correo_ya_tiene_cuenta` — riesgo aceptado y documentado (DEF-13; `docs/GUIA_ACTUALIZACION_IDENTIDADES.md` §10).
  2. `EsquemaTest::test_Defecto_E2_rollback_y_reaplicacion_de_016_reactiva_en_silencio_a_clientes_suspendidos` — el rollback de la 016 elimina la tabla por definición (DEF-16; guía §10).
- Omisiones por entorno **eliminadas** en esta fase: `FrontendTest` (Chromium/puppeteer: ahora falla con `QA_EXIGIR_NAVEGADOR=1`, y respeta `CHROME_BIN`), `EsquemaTest::test_E1b…` (dependía del orden: ahora prepara su propia base), `FallosTest` «sin ficha 1» (ahora es una precondición que falla) y `TokensTest` «No se emitió recuperación…» (ahora es una aserción de invariante).
- Orden aleatorio (`--order-by=random`) de las dos clases de autorización: `AutorizacionMatrizTest` 159 pruebas, solo fallan las 3 rojas.
- Duración: la matriz añade ≈ 4 min (≈ 8 500 peticiones HTTP reales, cada una con huella de 32 tablas antes y después). Para iterar: `--filter 'test_A_.*POST /servicios/eliminar'` ejecuta una sola ruta.

### Archivos de la fase

`tests/Qa/matriz_rutas.php` (matriz declarativa), `tests/Qa/MatrizHttp.php` (siembra, modalidades, huellas, centinelas), `tests/Qa/AutorizacionMatrizTest.php` (A–K y defectos),
`tests/Qa/AutorizacionHttpTest.php` (aserciones exactas), `tests/Qa/Navegador.php` (`HEAD` sin cuerpo, `CURLOPT_PATH_AS_IS`), `tests/Qa/FrontendTest.php` y `frontend_qa.cjs` (`CHROME_BIN`, `QA_EXIGIR_NAVEGADOR`), y retoques para eliminar omisiones en `EsquemaTest`, `FallosTest`, `TokensTest`.

---

# Ronda 2B – Reverificación

Reverificación independiente («qa2») del código integrado (rama `wt/qa2`, base `461dbf8`; la fase 2, sobre `19b719a`, está en R2B.10) frente a las correcciones de la ronda 2. Se actuó solo sobre `tests/Qa/**`, `.github/qa_omitidas_permitidas.txt` y este documento: **no se modificó ningún archivo de la aplicación**. Criterio de redacción: «verificado» significa que lo ejecutó una prueba o un comando cuyo resultado literal se cita; lo que no se ejecutó figura como hipótesis o como no cubierto (§R2B.9).

Entorno: PHP 8.3.6, MySQL 8.0, PHPUnit 10.5.66, base `appsalon_qa_ronda2_test` (el arnés exige `appsalon_qa_*_test`), HTTP real con `php -S` (puertos 8150-8199), SMTP simulado propio (2590-2599; ningún correo real), procesos PHP concurrentes reales con barrera, Chromium 1194 con `puppeteer-core`.

## R2B.1 Estado de los defectos anteriores

| Defecto | Estado tras la reverificación | Evidencia |
|---|---|---|
| DEF-13 (registro revela si el correo tiene cuenta) | **Estado inicial (fase 1): corregido en lo funcional.** En la fase 2 también se cerraron los canales de temporización (ver R2B.10). Respuesta, cabeceras, cookies, pantalla final y efectos visibles idénticos en 5 ramas (nuevo, sin confirmar, confirmado, personal, propietario/suspendido). Quedan **canales de temporización** (DEF-R2B-02, -08, -09) y un enlace roto en el aviso (DEF-R2B-01). | `TokensTest::test_Defecto_registro_publico_revela…` (reescrita, ya no se omite) y `R2bRegistroUniformeTest` (20 pruebas) |
| DEF-16 (rollback/reaplicación de la 016 reactiva suspendidos) | **Corregido por la vía soportada** (`migrador down` con `--aceptar-perdida` y respaldo verificado): la suspensión, su motivo y su origen sobreviven a dos ciclos `down`/`up` con cambios intermedios, y el login sigue denegado. El rollback SQL **manual** de la 018 sigue destruyendo datos (límite documentado en una prueba). Hallazgo nuevo: DEF-R2B-05. | `EsquemaTest::test_Defecto_E2_…` (ya no se omite), `R2bReversionTest` (11 pruebas) |
| DEF-R2-01 (reportes con PII al rol finanzas) | Corregido: la prueba roja de la ronda 2 pasa y la matriz exige que ningún centinela de PII llegue a finanzas. | `AutorizacionMatrizTest` |
| DEF-R2-02 (`/api/eliminar` con id inválido redirige a un 403) | Corregido para cliente, profesional, finanzas y equipo; el destino es el inicio del portal y es accesible (200). La exclusión de la celda en `test_I_*` se retiró. | `R2bRedireccionTest::test_sin_referer_…`, `test_I_*` |
| DEF-R2-03 (open redirect por `Referer`, CWE-601) | Corregido: 31 variantes hostiles (userinfo, `javascript:`, `data:`, `//`, `\`, CRLF codificado, puertos, mayúsculas, IPv6, `Host`/`X-Forwarded-Host` manipulados) terminan siempre en una ruta local de un solo `/`. | `R2bRedireccionTest` (31 pruebas) |
| DEF-12 (campos arreglo → advertencias PHP) | **Estado inicial (fase 1): REGRESIÓN.** En la fase 2 queda corregida salvo `profesional_id` (R2B-04b). Corregido en la ronda 1 (`ddade89`), reaparece en el código integrado: ver DEF-R2B-04. | `FallosTest` (8 casos rojos) |

## R2B.2 Hallazgos nuevos de la fase 1 (estado inicial; el estado vigente de cada uno está en R2B.10)

Todos los fallos de la ejecución completa son los de esta lista. Cada prueba contiene la reproducción mínima.

### DEF-R2B-01 (Baja) El aviso «ya tienes una cuenta» enlaza a `/login`, que no existe
- `classes/Email.php` genera el botón «Ir a iniciar sesión» con `/login`; esa ruta no está en `public/index.php` y responde **404**. Es el único correo que recibe el titular legítimo de la cuenta.
- Repro: `POST /cliente/registro` con el correo de una cuenta confirmada → el buzón recibe el aviso → `GET <enlace del botón>` → 404.
- Prueba: `R2bRegistroUniformeTest::test_Defecto_R2B_01_el_aviso_de_cuenta_existente_enlaza_a_una_ruta_inexistente`.

### DEF-R2B-02 (Baja, canal lateral) La segunda solicitud de registro distingue una cuenta confirmada de un correo nuevo por el tiempo
- Con un SMTP simulado de 300 ms: la 2.ª solicitud sobre un correo nuevo o sin confirmar tardó **433 ms** y sobre una cuenta confirmada ya avisada en la hora **98 ms** (cociente 4,4; umbral de la prueba < 2). La primera solicitud sí está equilibrada; el aviso del titular se limita a uno por hora y esa rama deja de enviar.
- Prueba: `R2bRegistroUniformeTest::test_Defecto_R2B_02_el_tiempo_de_la_segunda_solicitud_distingue_cuentas_confirmadas_de_nuevas`.

### DEF-R2B-03 (Baja, privacidad) `correos_salida.ultimo_error` guarda la dirección del destinatario
- Un servidor SMTP que rechaza el destinatario repite la dirección en su respuesta (`The following recipients failed: <dirección>: …`); `CorreoSalidaService`/`Email::sanearMensajeError` no la elimina y queda en `ultimo_error` (verificado). La prueba también comprueba lo que imprime `scripts/procesar_correos.php --estado` (que `docs/NOTIFICACIONES_WORKER.md` §5.4 declara «sin datos personales») cuando el evento llega a `fallido`.
- Prueba: `R2bColaCorreosTest::test_Defecto_R2B_03_el_error_guardado_y_el_diagnostico_estado_contienen_el_correo_del_destinatario` (la primera aserción, sobre `ultimo_error`, es la que falla; la de `--estado` queda sin ejecutar hasta corregir la primera).

### DEF-R2B-04 (Baja, regresión de DEF-12) Campos arreglo vuelven a emitir `PHP Warning: Array to string conversion`
- `views/equipo/index.php:15` conserva los arreglos (`is_array($v) ? $v : (string)$v`) y las líneas 29-41 hacen `s($previo['nombre'])` → `includes/funciones.php:18`. Además `views/equipo/_agenda_asistente.php:15` (`array_map('strval', (array)$ag['seleccion'])`) falla con `servicios[][a]=1`.
- Repro: sesión de propietario, `POST /admin/equipo/crear` con `nombre[]=x` (o `telefono[]`, `puesto[]`, `fecha_ingreso[]`, `nombre_profesional[]`, `servicios[][a]=1`) → 302 → `GET /admin/equipo` → advertencia en el log del servidor. No hay 500 ni fuga visible.
- Pruebas (ya existentes, vuelven a estar rojas): `FallosTest::test_Defecto_invitar_con_campo_arreglo_emite_advertencia_al_reproducir_el_formulario` (3), `test_mutaciones_de_equipo_con_campos_arreglo_dan_4xx_o_redireccion_sin_advertencias` (4), `test_invitar_con_servicios_no_escalares_o_inexistentes_da_error_controlado` (1). Estaban rojas ya en la primera ejecución sobre `461dbf8` (sin cambios míos en `FallosTest`).

### DEF-R2B-05 (Baja, operación) Aceptar la pérdida de la 022 no permite revertir y el mensaje no explica el remedio
- Con una fila en `crm_exportaciones_auditoria`, `migrador down --hasta=022… --aceptar-perdida=022… --respaldo=<válido>` imprime el bloqueo, el aviso «Pérdida aceptada…» y luego `[ERROR] … Subquery returns more than 1 row` (error 1242 de la guarda SQL de la 022). El rollback se niega por diseño (la auditoría es de solo anexado), **sin modificar nada** (verificado con la huella de toda la base), pero el flag `--aceptar-perdida` sugiere que es posible y el mensaje no dice que hay que archivar y vaciar las tablas a mano.
- Prueba: `R2bReversionTest::test_Defecto_R2B_05_aceptar_la_perdida_de_022_no_permite_revertir_y_el_mensaje_no_lo_dice` (la parte de «no cambia nada» pasa en `…_el_rollback_de_022_se_niega_sin_modificar_nada`).

### DEF-R2B-06 (Baja/Media) Un cliente registrado puede inundar la auditoría de exportaciones (solo anexado)
- Toda petición denegada de `GET /admin/clientes/exportar` añade una fila permanente (triggers 1644 impiden borrarla). El alta pública es abierta, así que cualquiera con una cuenta de cliente puede crecer la tabla sin límite. La documentación descarta auditar al anónimo «por ser vía de inundación»; un cliente es un anónimo con un paso más.
- Repro: login de cliente, 60 × `GET /admin/clientes/exportar?tipo=clientes&i=N` → 60 filas nuevas (esperado < 60).
- Prueba: `R2bCrmAuditoriaTest::test_Defecto_R2B_06_…`.

### DEF-R2B-07 (Baja) La exportación de campañas y fidelidad no neutraliza tabulador ni retorno de carro iniciales
- `CrecimientoController::csv` (línea 49) antepone `'` solo si tras los espacios en blanco iniciales sigue `= + @ -` (`/^[\s]*[=+@\-]/u`). Una celda que empieza por tabulador o retorno de carro **sin** otro disparador (`\tsolotab`, `\rsolocr`) sale sin neutralizar, y OWASP recomienda neutralizar también esos dos caracteres iniciales. El CSV de clientes del CRM sí los neutraliza (la prueba lo comprueba con una celda `=HYPERLINK…`).
- Repro: cliente con nombre `\tsolotab` / `\rsolocr` → exportación de campañas → celdas sin comilla inicial (filas 8 y 9 del CSV de la prueba).
- Prueba: `R2bCrmAuditoriaTest::test_Defecto_R2B_07_campana_de_clientes_y_fidelidad_no_neutraliza_tabulador_y_retorno_de_carro_iniciales`.

### DEF-R2B-08 (Baja, canal lateral) `/olvide` revela si la cuenta existe por el tiempo de respuesta
- Con un SMTP simulado de 400 ms: cuenta existente **460 ms** frente a inexistente **12 ms** (cociente 39). El envío se hace dentro de la petición solo cuando la cuenta existe y está confirmada. El registro público sí se corrigió; `/olvide` y `/reenviar-confirmacion` quedaron con el patrón anterior (la documentación lo reconoce como residuo).
- Prueba: `R2bRegistroUniformeTest::test_Defecto_R2B_08_olvide_revela_si_la_cuenta_existe_por_el_tiempo_de_respuesta`.

### DEF-R2B-09 (Baja, canal lateral) `/reenviar-confirmacion` revela si existe una cuenta pendiente por el tiempo
- Cuenta pendiente **468 ms** frente a inexistente **12 ms** (SMTP de 400 ms): aunque el evento va a la cola persistente, el primer intento se hace en línea solo si la cuenta existe y está pendiente.
- Prueba: `R2bRegistroUniformeTest::test_Defecto_R2B_09_reenviar_confirmacion_revela_si_la_cuenta_pendiente_existe_por_el_tiempo_de_respuesta`.

### DEF-R2B-10 (Media, dependiente de la zona horaria del proceso PHP) Retirar un servicio o poner un descanso ignora las citas de HOY por la noche
- `ProfesionalRepository` calcula «hoy» con `date('Y-m-d')` (líneas 776, 926, 965, 1135 del archivo leído). No hay `date_default_timezone_set` ni `date.timezone` en el código, en el `Dockerfile` ni en `docker/` (verificado con `grep`), y en este entorno `date_default_timezone_get()` devuelve `UTC`. El salón opera en `America/Guayaquil` (UTC-5): entre las 19:00 y las 23:59 locales «hoy» de PHP ya es mañana y una cita confirmada de esta misma noche no cuenta como futura, de modo que se puede retirar el servicio o crear un descanso sobre ella. `ColaboradorRepository::citasFuturasDeProfesional` sí convierte a -05:00 (por eso la baja sí lo detecta).
- Reproducción **simulada** (no se alteró el reloj del sistema): el proceso que ejecuta la operación corre con `date_default_timezone_set('Pacific/Kiritimati')` (`QA_PHP_TZ`, solo en `tests/Qa/worker.php`), cuya fecha es la de mañana en Guayaquil. Controles que pasan: sin desfase y con el reloj en Guayaquil la operación **sí** se rechaza (422). Con el desfase se acepta (`status ok`).
- Hipótesis no comprobada: que el despliegue real use la zona UTC de PHP (es el valor por defecto de la imagen `php:8.2-apache` sin `date.timezone`).
- Prueba: `R2bAgendaTest::test_Defecto_R2B_10_…`.

### DEF-R2B-11 (Baja) Error de validación de la reasignación deja a gerencia y recepción en un 403
- `EquipoController::reasignarAplicar` (id ausente/no válido, `decision` no arreglo o `motivo` no cadena) redirige a `/admin/equipo`, que exige `equipo.gestionar` (solo propietario). Gerencia y recepción —que tienen `agenda.gestionar` y sí ven la pantalla de reasignación— reciben un 403. La verificación general de cadenas de redirección (`test_I_*`) lo detectó en la matriz.
- Repro: login de gerente, abrir `/admin/equipo/reasignar?id=<profesional>`, `POST /admin/equipo/reasignar` con CSRF válido y sin `id` → `302 /admin/equipo` → `403`.
- Prueba: `R2bAgendaTest::test_Defecto_R2B_11_el_error_de_validacion_de_la_reasignacion_deja_a_gerencia_y_recepcion_en_un_403`. `test_I_*` excluye únicamente las tres celdas (gerente, recepción, `portal_inexistente_cuenta_equipo`) de `POST /admin/equipo/reasignar` para que un solo defecto no oculte el resto; la exclusión está comentada en el código y el defecto sigue rojo en su prueba.

## R2B.3 Observaciones (sin prueba roja; comportamiento actual medido)

1. **Cola de correos: «al menos una vez», no «exactamente una».** Un worker matado a mitad del envío puede duplicar el mensaje, siempre con el mismo enlace vigente (`test_worker_matado_a_mitad_del_envio…`). Con ocho reenvíos simultáneos queda un único token válido, pero llegan a entregarse hasta 8 correos y solo uno sirve (última ejecución: 7 entregados, **6 con enlaces ya invalidados**) (el titular los recibe y falla con 410; cuatro ejecuciones de la prueba entregaron 6, 8, 7 y 7 correos con un único enlace válido en cada una; la ventana está acotada por el número de reenvíos simultáneos, y la prueba lo exige: `< 8`). La documentación (§5.7) ya reconoce la ventana y no promete entrega única (`test_los_documentos_no_prometen_entrega_exactamente_una_vez`). Sobrepromesas de redacción, sin consecuencia funcional: el titular «no pierde el correo» (un evento llega a `fallido` tras unos 31 minutos acumulados de reintentos y requiere `--reintentar`), y los comentarios «nunca se duplica» (`ReasignacionCitasService`) y «backoff sin duplicados» (`NotificacionCitaService`).
2. **SMTP colgado**: el alta pública espera el tiempo de espera del cliente SMTP: medido **10,11 s** con un servidor que acepta la conexión y no responde (`cuelga`) frente a 0,17 s (cerrado), 0,10 s (RCPT 4xx), 0,09 s (RCPT 5xx) y 0,14 s (DATA 5xx). El resultado para el usuario es el mismo en todos los casos y el evento queda `pendiente`.
3. `procesar_correos.php --reintentar` **sin valor** no falla: ejecuta una tanda normal del worker. Con valores hostiles (`1;DROP`, vacío, negativo, no numérico) sale con código 2 sin tocar la base.
4. El teléfono que teclea un operador en el aviso de homónimos se vuelve a mostrar en el formulario (dato que el propio operador acaba de escribir; sin fuga cruzada).

## R2B.4 Expectativas modificadas por cambios de contrato deliberados

Ninguna aserción se relajó para ocultar un fallo. Cada cambio refleja un contrato nuevo y deliberado de la integración, justificado también en un comentario del propio archivo:

| Prueba | Antes | Ahora | Justificación |
|---|---|---|---|
| `TokensTest::test_Defecto_registro_publico_revela_si_un_correo_ya_tiene_cuenta` | omitida (riesgo aceptado) | activa: la firma de la respuesta (estado, cabeceras, cookies, cuerpo normalizado) debe ser idéntica para nuevo / existe / sin confirmar / suspendido / personal / propietario | DEF-13 se corrigió con registro uniforme (`AuthService::registrar`, aviso 1/h, límite 3 por correo y 10 por IP cada 15 min) |
| `EsquemaTest::test_Defecto_E2_…` | omitida (el rollback elimina la tabla) | activa: se revierte con `migrador down --aceptar-perdida --respaldo`; la suspensión, el motivo y el origen se conservan y el login sigue denegado tras `up` | DEF-16: la reversión segura exige respaldo verificado y deja `clientes_resguardo_016` |
| `EsquemaTest::test_E2_*`, `test_E2b_*` | rollback SQL directo | `revertirSeguro()` (migrador) y, para el ciclo completo, el migrador **rechaza** (código 2) sin aceptación y la reversión total con aceptación deja un esquema igual al de una instalación nueva | la vía soportada es el migrador; el rollback manual de la 018 queda documentado en `test_Limite_documentado_el_rollback_manual_de_018…` |
| `EsquemaTest::test_Defecto_E5_conteo_de_tablas_y_documentacion` | 35 tablas | 42 (comparado con una base `fresh`) | las migraciones 018-022 añaden tablas |
| `CorreoTest` (registro con SMTP caído) | el registro informaba el fallo de envío | respuesta uniforme y exactamente **un** evento `confirmacion_cuenta` `pendiente` con `intentos = 1`; el reenvío cancela el evento anterior | el envío pasa a la cola persistente (outbox) |
| `ConcurrenciaTest::test_registro_claves_distintas_mismo_correo_una_sola_identidad` | un solo `ok` y el resto conflicto | tres `ok` (límite de 3 por correo), 1 identidad y 1 membresía, contraseña original intacta, 1 token válido, ≤ 1 evento activo, filas de idempotencia = aceptadas | no hay conflicto distinguible por diseño (DEF-13); el invariante pasa a ser «una identidad» |
| `AtaquesVariosTest::test_homonimo_de_profesional_exige_confirmacion_explicita` (+ nueva `…token_forjado_reutilizado_u_otra_clave_no_crea_nada`) | alta directa | el alta con homónimo exige `decision=confirmar` + token HMAC ligado a actor, clave e ids | contrato del aviso de homónimos |
| `InstaladorTest` | número fijo de migraciones | número de archivos `.sql` que no son de reversión | evita fijar el contador |
| `matriz_rutas.php` / `AutorizacionMatrizTest` | `/api/eliminar` esperaba `/admin`; 82 rutas | `@inicio` (inicio del portal); nuevas rutas `POST /admin/equipo/ficha/agenda`, `GET\|POST /admin/equipo/reasignar` (404 y 200 según id), `GET /admin/clientes/auditoria`, `GET\|POST /admin/clientes/nuevo`; una excepción acotada: una exportación (200 o 403) debe dejar **exactamente una** fila de auditoría | DEF-R2-02 y contratos nuevos |
| `QaCase::limpiar()` | vaciaba todas las tablas | vacía y vuelve a sembrar la versión 1 de `consentimiento_versiones` | la 022 la siembra; sin ella el consentimiento no funciona (arnés, no aplicación) |

## R2B.5 Cobertura nueva (qué se atacó)

| Área | Archivo (pruebas) | Qué se verificó |
|---|---|---|
| Registro uniforme | `R2bRegistroUniformeTest` (20) | igualdad de respuesta en 5 ramas; errores de validación/CSRF/clave independientes de la cuenta; alias `/crear-cuenta`; sin efectos sobre contraseña ni privilegios; aviso sin token ni enlace de activación; 1 aviso por hora y correo; mayúsculas y espacios; límites por correo y por IP; misma clave repetida / misma clave con otro correo (409 igual); concurrencia con 6 procesos reales; temporización de login / olvide / reenvío |
| Cola de correos | `R2bColaCorreosTest` (23) | SMTP cerrado, colgado, RCPT 4xx/5xx, DATA 5xx, aceptar y cortar, lento; worker real `scripts/procesar_correos.php`; backoff exacto 60/120/240/480/960 s; agotamiento a `fallido`; `--estado` sin PII (salvo DEF-R2B-03); `--reintentar`; 8 reenvíos concurrentes; lease vencido / vigente; worker matado; 2 workers simultáneos; corte tras 3 fallos; evento vencido a 24 h; token nunca en claro en cola, logs ni errores; argumentos hostiles |
| Reversión | `R2bReversionTest` (11) | bases aisladas; respaldos inválidos rechazados antes de tocar nada; aceptación parcial / sin respaldo / de una migración ajena; manifiesto (sha256, base, fecha < 24 h, versión, filas); guardas 016/017/018/022; dos ciclos con cambios intermedios |
| Agenda y reasignación | `R2bAgendaTest` (53) | asistente (invitación y ficha) con cargas inválidas → 422 sin tocar filas; ficha nueva inactiva; ficha heredada intacta salvo casilla; retirar servicios / descansos con citas; reasignación con destinos inválidos por formulario manipulado; comisión devengada; baja y suspensión bloqueadas en servidor; baja simultánea con reserva; dos reasignaciones al mismo hueco; seis procesos con doble clic; permisos por rol y PII mínima |
| CRM | `R2bCrmAuditoriaTest` (14), `R2bConsentimientoTest` (10), `R2bHomonimosTest` (24) | auditoría antes de la salida y sin bytes si falla; append-only; truncado de 10 100 filas; neutralización CSV; versión del consentimiento mostrada vs vigente; homónimos de clientes y profesionales (token forjado, reutilizado, otra clave, otro operador); `diagnosticar_duplicados` solo informa |
| Redirecciones | `R2bRedireccionTest` (31) | ver DEF-R2-02/03 |
| Frontend | `R2bFrontendTest` (5) + `frontend_r2b.cjs` | Chromium de 320 a 1440 px: sin desbordes ni errores de consola, contrato JS del asistente, foco, reducción de movimiento, objetivos táctiles ≥ 44 px, comportamiento sin JavaScript |

## R2B.6 Sensibilidad de las pruebas (mutaciones sobre una COPIA, `/tmp/qa2_mut`; el árbol real queda intacto)

Cada mutación se aplicó a la copia con la base `appsalon_qa_r2bm_test`, se ejecutó la prueba indicada y se comprobó que falla.

| # | Mutación (archivo) | Resultado | Prueba que la detecta |
|---|---|---|---|
| M01 | el registro de un correo con cuenta responde distinto (conflicto 422) (`services/AuthService.php`) | ROJO | `R2bRegistroUniformeTest::test_la_respuesta_es_identica_en_todas_las_ramas_incluidas_cabeceras_cookies_y_pantalla_final` |
| M02 | sin ventana horaria: un aviso por solicitud (inundación del titular) (`services/AuthService.php`) | ROJO | `R2bRegistroUniformeTest::test_el_aviso_se_limita_a_uno_por_hora_y_correo_aunque_se_repita_la_solicitud` |
| M03 | el reenvío no cancela los eventos de enlaces reemplazados (`services/AuthService.php`) | ROJO | `R2bColaCorreosTest::test_reenviar_sucesivo_cancela_el_evento_anterior_y_jamas_envia_un_enlace_reemplazado` |
| M04 | el worker no comprueba la vigencia del enlace (`services/CorreoSalidaService.php`) | ROJO | `R2bColaCorreosTest::test_cuenta_confirmada_por_otra_via_cancela_el_evento_pendiente_sin_enviar` |
| M05 | retroceso constante de 60 s en lugar de exponencial (`repositories/CorreoSalidaRepository.php`) | ROJO | `R2bColaCorreosTest::test_backoff_exacto_agotamiento_fallido_estado_sin_pii_y_reintentar_manual` |
| M06 | el migrador no verifica el respaldo (`database/migrador_reversion.php`) | ROJO | `R2bReversionTest::test_respaldos_invalidos_se_rechazan_antes_de_tocar_nada` |
| M07 | el migrador no exige aceptar cada migración (`database/migrador.php`) | ROJO | `R2bReversionTest::test_aceptacion_parcial_o_sin_respaldo_o_con_aceptacion_de_una_migracion_ajena_se_niega_sin_tocar_nada` |
| M08 | el rollback de la 016 no resguarda las suspensiones (`database/migrations/016_clientes_membresia_rollback.sql`) | ROJO | `R2bReversionTest::test_suspension_sobrevive_a_dos_ciclos_down_up_con_cambios_intermedios_y_el_login_sigue_denegado` |
| M09 | la reasignación no revalida el hueco del destino (`repositories/ReasignacionCitasRepository.php`) | ROJO | `R2bAgendaTest::test_reasignar_a_destinos_invalidos_por_formulario_manipulado_no_mueve_nada` |
| M10 | la reasignación ignora la comisión devengada (`repositories/ReasignacionCitasRepository.php`) | ROJO | `R2bAgendaTest::test_comision_devengada_bloquea_la_reasignacion_aunque_se_fuerce_el_formulario` |
| M11 | la baja no comprueba citas futuras (`repositories/ColaboradorRepository.php`) | ROJO | `R2bAgendaTest::test_baja_y_suspension_se_bloquean_en_servidor_con_citas_sin_resolver_aunque_se_manipule_el_formulario` |
| M12 | el asistente admite franjas solapadas (`services/AgendaSemanalValidador.php`) | ROJO | `R2bAgendaTest::test_la_ficha_rechaza_cargas_invalidas_con_422_sin_tocar_ni_una_fila_y_sin_advertencias` |
| M13 | la exportación se entrega aunque no se pueda auditar (`controllers/CrmController.php`) | ROJO | `R2bCrmAuditoriaTest::test_si_la_auditoria_falla_no_sale_ni_un_byte_del_archivo` |
| M14 | el consentimiento guarda la última versión y no la mostrada (`repositories/ConsentimientoRepository.php`) | ROJO | `R2bConsentimientoTest::test_se_guarda_la_version_mostrada_aunque_se_haya_publicado_otra_mientras_tanto` |
| M15 | el consentimiento sube de versión sin reconfirmación (`repositories/ConsentimientoRepository.php`) | ROJO | `R2bConsentimientoTest::test_un_consentimiento_previo_no_sube_de_version_solo_ni_con_un_formulario_viejo` |
| M16 | el token de homónimos no está ligado al operador (`services/AltaClienteService.php`) | ROJO | `R2bHomonimosTest::test_tokens_forjados_ajenos_viejos_o_reutilizados_no_crean_nada` |
| M17 | destino_retorno_seguro acepta cualquier origen (`includes/funciones.php`) | ROJO | `R2bRedireccionTest::test_la_cancelacion_por_formulario_nunca_redirige_fuera_del_origen (con juegos de datos)` |
| M18 | la ficha nueva del asistente nace activa (`repositories/ColaboradorRepository.php`) | ROJO | `R2bAgendaTest::test_ficha_nueva_del_asistente_no_aparece_en_disponibilidad_ni_reservas_hasta_aceptar_la_invitacion` |
| M19 | el token de homónimos de profesional no está ligado a la clave (`services/HomonimosProfesionalesService.php`) | ROJO | `R2bHomonimosTest::test_profesional_tokens_forjados_confirmacion_cancelacion_y_doble_clic` |
| M20 | la lista comercial incluye consentimientos de versión anterior (`repositories/CrecimientoRepository.php`) | ROJO | `R2bConsentimientoTest::test_crm_distingue_version_anterior_y_las_listas_comerciales_solo_incluyen_la_vigente` |
| M21 | una denegación de exportación no se audita (`controllers/CrmController.php`) | ROJO | `R2bCrmAuditoriaTest::test_denegadas_por_rol_quedan_registradas_y_los_anonimos_o_sesiones_caducadas_no` |
| M22 | el SMTP caído hace fallar el alta (el despacho propaga la excepción) (`services/CorreoSalidaService.php`, `catch (\Throwable)` → `catch (\LogicException)` en `despacharTrasCommit`) | **SOBREVIVIÓ (equivalente)** | `despachar()` captura internamente cualquier fallo de envío y devuelve `false`, por lo que ese `catch` externo solo protege errores inesperados de base de datos; para el caso de SMTP caído la mutación no cambia el comportamiento. Se sustituyó por M23, que sí lo cambia. |
| M23 | un fallo de SMTP no se registra (el evento queda «enviando» hasta que venza el arrendamiento) (`services/CorreoSalidaService.php`) | ROJO | `R2bColaCorreosTest::test_el_alta_con_smtp_roto_responde_igual_no_pierde_el_correo_y_el_worker_real_lo_entrega` (los 5 casos) |

M22 se descartó por equivalente y se sustituyó por M23: todas las mutaciones no equivalentes (22 de 22) fueron detectadas. Cada una se aplicó sobre la copia con la base `appsalon_qa_r2bm_test`; el árbol real quedó intacto (`git status` limpio de archivos de aplicación).

## R2B.7 Resultado de la ejecución completa (fase 1, sobre `461dbf8`)

Comando (desde la raíz del árbol, con `DB_NAME=appsalon_qa_ronda2_test`, SMTP simulado propio y `QA_EXIGIR_NAVEGADOR=1`):

```
php -d variables_order=EGPCS vendor/bin/phpunit --do-not-cache-result --colors=never --log-junit <junit.xml> tests/Qa
Tests: 564, Assertions: 4492, Failures: 18.        (DURACION_SEG=794, es decir 13 min 14 s)
```

- **Omitidas: 0** (PHPUnit no imprime «Skipped»; el informe JUnit lo confirma: `php scripts/ci_verificar_omitidas.php <junit.xml> .github/qa_omitidas_permitidas.txt` → `Pruebas en el informe: 564; omitidas: 0`, código 0).
- **Los 18 fallos son las pruebas rojas de los defectos de §R2B.2**, ninguno es de arnés: DEF-R2B-01 (1), -02 (1), -03 (1), -04 (8 casos de `FallosTest`), -05 (1), -06 (1), -07 (1), -08 (1), -09 (1), -10 (1) y -11 (1).
- Una primera ejecución completa (563 pruebas, 19 fallos) mostró además dos fallos que no eran de estas pruebas rojas: (a) `test_I_*` por `POST /admin/equipo/reasignar` de gerencia y recepción, que dio lugar a DEF-R2B-11 y a su prueba propia; (b) **una celda aislada** de la matriz (`POST /olvide | profesional | html`) que midió «1 correo(s) enviados al buzón SMTP» en una petición sin cuerpo que no puede enviar ninguno. **No se pudo reproducir**: pasó tres veces sola, pasó la clase `AutorizacionMatrizTest` completa (167 pruebas, 4 min 2 s) y la segunda ejecución completa. No se identificó la causa (hipótesis sin comprobar: correo de otra prueba que llegó al mismo buzón del puerto 2599 durante la ventana de medida). Se añadió al mensaje de fallo el destinatario y el asunto de cualquier correo inesperado para atribuirlo si vuelve a ocurrir.
- Tiempos por clase (segundos, ejecución final): `AutorizacionMatrizTest` 261, `R2bAgendaTest` 60, `ConcurrenciaTest` 52, `R2bReversionTest` 46, `EsquemaTest` 43, `R2bColaCorreosTest` 40, `InstaladorTest` 38, `R2bRegistroUniformeTest` 32; el resto, menos de 15 s cada una. Los tiempos de `FrontendTest` y `R2bFrontendTest` no figuran por clase (la medición ocurre en la preparación de la clase) pero están incluidos en los 794 s.
- Regresión de los desarrolladores (suites por defecto `tests/Unit` + `tests/Integration`, base aparte `appsalon_qa_r2bdev_test`): `OK (667 tests, 10140 assertions)`, 3 min 58 s (`DURACION_SEG=238`).
- E2E de navegador del CI (sin Docker, ver §R2B.9): 8 etapas, todas con código 0.
- Las pruebas de DEF-R2B-02/-08/-09 son pruebas de temporización con cociente (umbral 2,0) sobre un SMTP simulado de 300-400 ms, no dependen de la velocidad absoluta de la máquina; los cocientes observados fueron 4,4, 39 y 40.

## R2B.8 Omitidas

**Lista exacta de pruebas omitidas: ninguna.** `.github/qa_omitidas_permitidas.txt` queda con la lista definitiva **vacía** (solo comentarios que explican por qué), de modo que cualquier omisión futura hace fallar el CI. Las dos omisiones de la ronda 2 se eliminaron (ambas pruebas ejecutan ahora la corrección). Las pruebas que dependen de Chromium (`FrontendTest`, `R2bFrontendTest`) solo se omiten en una máquina sin navegador **y sin** `QA_EXIGIR_NAVEGADOR=1`; el CI fija esa variable, por lo que allí fallan en lugar de omitirse. No se usa ninguna variable de entorno para activar o desactivar una prueba.

## R2B.9 No cubierto, con motivo

- **Docker/Apache**: el entorno no tiene Docker. Las 8 etapas E2E del CI (`tests/browser_e2e_test.js`, `assert_browser_e2e.php`, `error_routes_http_checks.js`, `error_visual_test.js`, `ui_navigation_test.js`, `double_submit_e2e.js`, `responsive_views_test.js` y las vistas previas de correo) se ejecutaron sin Docker con un guion propio fuera del repositorio (`php -S` + SMTP simulado sobre una base `appsalon_e2e_test`); resultado literal: `E2E_TOTAL_RC=0` con las 8 etapas en `RC=0`. No se verificó `.htaccess`, `mod_rewrite` ni las cabeceras de Apache.
- **SMTP real o remoto**: prohibido; todo el correo es simulado (fallos incluidos). No se probaron TLS, autenticación ni límites de un proveedor.
- **Zona horaria real**: el desfase nocturno de DEF-R2B-10 se simula con un reloj PHP adelantado en el proceso de la operación; no se alteró el reloj del sistema ni se probó el horario de verano (Ecuador no lo usa).
- **Rate limit con IPv6 o detrás de proxy**: solo IPv4 directo (127.0.0.1).
- **Navegadores**: solo Chromium 1194; no Firefox ni WebKit ni dispositivos reales.
- **Escala**: el CRM se probó con 10 100 clientes; la cola, con decenas de eventos.
- **Intermitencia no explicada** de una celda de la matriz (`POST /olvide | profesional | html`, un correo inesperado) observada una vez y no reproducida: ver §R2B.7.

## R2B.10 Fase 2 – Reverificación de las correcciones R2B

Se integró `claude/blissful-hopper-f5k7a0` (cabeza `19b719a`, avance rápido sobre `87b5c2a`) en `wt/qa2` y se reverificó **cada** corrección con las pruebas existentes y con ataques nuevos. De nuevo no se modificó ningún archivo de la aplicación (solo `tests/Qa/**`, `.github/qa_omitidas_permitidas.txt` y este documento). Las secciones R2B.1–R2B.9 describen el estado **inicial** (sobre `461dbf8`) y se conservan como registro; el estado vigente es el de esta sección.

**Cambio de diseño verificado.** Registro, `/reenviar-confirmacion` y `/olvide` ya no envían correo dentro de la petición HTTP: solo encolan en `correos_salida`, y los entrega el worker (`scripts/procesar_correos.php`; el supervisor `scripts/notificaciones_worker.php` lo ejecuta cada `CORREOS_INTERVALO_SEGUNDOS`, 5 s por omisión). La recuperación de contraseña pasó a la cola con el tipo `recuperacion_cuenta`. `CORREOS_ENVIO_INMEDIATO=1` reactiva el envío en línea y es el único interruptor.

### R2B.10.1 Estado de cada hallazgo

| Hallazgo | Estado tras la fase 2 | Evidencia (todas ejecutadas) |
|---|---|---|
| R2B-01 enlace del aviso a `/login` (404) | **Corregido y reverificado.** El botón va a `/cliente/login` (cuentas de cliente, también «doble perfil») o `/equipo/login` (personal); las URL propias del correo, en HTML y texto, responden 200/302 en 6 ramas. | `R2bRegistroUniformeTest::test_R2B_01_…` |
| R2B-02 tiempo de la 2.ª solicitud de registro | **Corregido y reverificado.** Con SMTP de 300 ms y sin la variable, las 2 solicitudes de 4 ramas (nuevo, confirmado, sin confirmar, personal) tardan < 250 ms, con cociente máximo < 3 y buzón vacío; el worker paga la latencia (≥ 1 s por 4 correos). | `test_R2B_02_…` |
| R2B-08 `/olvide` por tiempo | **Corregido y reverificado** (existente y inexistente: razón < 2, < 250 ms; buzón vacío; 3 eventos `recuperacion_cuenta` para 3 cuentas confirmadas y ninguno para inexistentes). | `test_R2B_08_…` |
| R2B-09 `/reenviar-confirmacion` por tiempo | **Corregido y reverificado** (mismos criterios). | `test_R2B_09_…` |
| R2B-03 PII en `ultimo_error` / `--estado` | **Corregido para direcciones corrientes; ABIERTO para direcciones con caracteres especiales (R2B-03b).** Ver §R2B.10.2. | `R2bColaCorreosTest::test_R2B_03_…` (verde: 4 direcciones corrientes buscadas en todas las columnas de `correos_salida`, `--estado` en texto y JSON, la salida de error del worker, el log del servidor web y el motivo de un evento cancelado) y `test_Defecto_R2B_03b_…` (rojo) |
| R2B-04 advertencias por campos arreglo | **Corregido para todos los campos salvo `profesional_id` (R2B-04b, ABIERTO).** `s()` devuelve `''` ante arreglos y objetos sin `__toString` y el asistente filtra no escalares (12 campos × 5 formas hostiles, sin una advertencia). | `R2bAgendaTest::test_R2B_04_…` (verde), `test_Defecto_R2B_04b_…` (rojo), `FallosTest` (8 casos, ahora verdes) |
| R2B-05 pista cuando el rollback se niega (1242) | **Corregido y reverificado.** Imprime `[BLOQUEO] … A PROPÓSITO … Archiva las tablas … y vacíalas`, `No se modificó nada` (huella total idéntica) y **el remedio funciona**: tras `TRUNCATE` la misma orden completa la reversión. Sin datos en riesgo no aparece la pista. `--reintentar` sin valor ahora sale con código 2. | `R2bReversionTest::test_R2B_05_…` (2) y `R2bColaCorreosTest` (línea de comandos) |
| R2B-06 inundación de la auditoría | **Corregido en lo esencial; ABIERTO el residuo por concurrencia (R2B-06b).** 60 denegaciones idénticas = 1 fila; la primera evidencia no cambia; otro actor, otro tipo o la ventana vencida (11 min) sí registran; 9 min 30 s no; éxitos nunca se agrupan (5 de 5). | `R2bCrmAuditoriaTest::test_R2B_06_…` (3) y `test_Defecto_R2B_06b_…` (rojo) |
| R2B-07 CSV de campañas | **Corregido y reverificado** (`ReporteService::sanearCeldaCsv`: `\t`, `\r`, `\n`, `=`, `+`, `-`, `@` iniciales, con o sin espacios previos). Observación sin comprobar: `\f` (salto de página) inicial no se neutraliza. | `test_R2B_07_…` |
| R2B-10 «hoy» en la zona de PHP | **Corregido y reverificado** con la misma simulación (`QA_PHP_TZ`), ahora con 5 zonas (Kiritimati +14, Auckland +13, UTC, Honolulu -10, Los Ángeles) × 2 operaciones: la cita de hoy bloquea el cambio en todas. | `R2bAgendaTest::test_R2B_10_…` |
| R2B-11 destino del error de reasignación | **Corregido y reverificado.** 6 formas de error × propietario, gerente y recepción → `302 /admin` y `GET /admin` = 200. Se verificó por el código que `inicio_portal()` da `/admin` para esos tres roles (`/agenda` profesional, `/admin/finanzas` finanzas, `/cita` cliente) y que `/admin` exige `agenda.gestionar`, que los tres tienen. | `test_R2B_11_…` y la matriz (`POST /admin/equipo/reasignar` = `@inicio`) |

### R2B.10.2 Hallazgos nuevos y residuos (pruebas rojas)

**R2B-03b (Baja, privacidad).** `Email::sanearMensajeError` oculta direcciones con la expresión `[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}`. Una parte local con otros caracteres que la aplicación admite en el registro deja el comienzo de la dirección en `ultimo_error` y en `--estado`/`--estado --json`: p. ej. `pii'ap.1.2.abc@example.test` queda como `pii'[correo]`. Se reprodujo con los **14** caracteres probados (`' & = ! # $ * / ? ^ \` { | } ~`). Reproducción mínima: registrar `o'brien.x@example.test` con el SMTP en `rcpt5xx` (repite la dirección), ejecutar el worker una vez y leer `correos_salida.ultimo_error` → `…failed: o'[correo]: <o'[correo]>…`. Prueba: `R2bColaCorreosTest::test_Defecto_R2B_03b_…`.

**R2B-04b (Baja).** `views/equipo/index.php:39` compara `(string)($previo['profesional_id'] ?? '')` con un cast explícito (no pasa por `s()`): con `profesional_id[]=x` y al menos una ficha sin cuenta, cada `GET /admin/equipo` posterior emite `PHP Warning: Array to string conversion` por ficha. Reproducción: `POST /admin/equipo/crear` con `…&profesional_id[]=x` y luego `GET /admin/equipo`. Prueba: `R2bAgendaTest::test_Defecto_R2B_04b_…`.

**R2B-06b (Baja).** `CrmAuditoriaService::registrar` consulta `hayDenegadaReciente` y después inserta, sin atomicidad. Una ráfaga de 16 peticiones **simultáneas** del mismo actor (16 sesiones distintas para no serializar por el bloqueo de sesión; servidor con 12 procesos) dejó **5 y 7 filas** en dos ejecuciones (esperado: 1); la segunda ráfaga, dentro de la ventana, no añadió nada. El daño queda acotado a la ráfaga por actor y ventana de 10 min, pero un atacante con varias cuentas de cliente (el alta es abierta) o más concurrencia sigue creciendo la tabla de solo anexado. Observación asociada: las filas `fallida` (filtros inválidos) no se agrupan, aunque solo las provoca personal con `clientes.exportar`. Prueba: `R2bCrmAuditoriaTest::test_Defecto_R2B_06b_…`.

**R2B-12 (Baja, documentación frente a comportamiento).** `docs/NOTIFICACIONES_WORKER.md` afirma que las invitaciones de colaboradores «no se envían de forma síncrona» y «No se envían dentro de la petición HTTP». Es falso para ellas: `ColaboradorService` las despacha justo después del commit (alta y reenvío). Medido: con un SMTP de 400 ms, `POST /admin/equipo/crear` tardó 497 ms y el correo ya estaba en el buzón antes de cualquier worker. No abre un canal de enumeración (ruta del propietario; él elige el destinatario). Además, el apartado 5.7 sigue diciendo que el tiempo de respuesta con el SMTP caído está acotado por «el intento inmediato» de PHPMailer, lo que dejó de ser cierto para las rutas públicas. Prueba: `R2bColaRecuperacionTest::test_Defecto_R2B_12_…`.

### R2B.10.3 Inventario de envíos de correo dentro de una petición

Recorrido de todo el código (`grep` de `new Email`, `Email::`, `->despachar(`, `despacharTrasCommit`, `enviar*`) y comprobación dinámica con SMTP simulado:

| Lugar | ¿En línea? | Público / autenticado | Comprobado |
|---|---|---|---|
| `POST /cliente/registro`, `/crear-cuenta` | No (solo encola) | Público | Sí (12 pasos, SMTP de 400 ms) |
| `POST /olvide` | No (solo encola, tipo `recuperacion_cuenta`) | Público | Sí |
| `POST /reenviar-confirmacion` | No (solo encola) | Público | Sí |
| `POST /admin/equipo/crear` y reenvío de invitación (`ColaboradorService::despachar`) | **Sí** | Propietario | Sí (R2B-12; también `CorreoTest`) |
| `POST /api/citas` (confirmación de cita; `CitaService` → `NotificacionCitaService::registrarYDespachar`) | **Sí** | Cliente autenticado (su propio correo) | Sí: `RegresionNegocioTest::test_inventario_…` |
| `POST /api/eliminar` y cancelación por el equipo (aviso de cancelación) | **Sí** | Cliente / equipo | Sí |
| Reasignación de citas (`ReasignacionCitasService::despachar`, aviso de cambio de profesional) | **Sí** (mejor esfuerzo) | Equipo con `agenda.gestionar` | Sí: `R2bAgendaTest` (el aviso llega antes del worker) |
| `scripts/instalar.php` (invitación inicial) | Sí (CLI) | Instalación | Por lectura |
| `POST /api/pagos/webhook` | No envía correo | Público firmado | Por lectura |
| `AuthService` con despachador inyectado (pruebas unitarias) | Sí | Solo pruebas | Por lectura |

Solo las rutas públicas podían servir de oráculo de existencia de cuentas; ninguna envía ya en línea. Los envíos en línea que persisten van a destinatarios que el propio actor controla o que son el titular de la sesión.

### R2B.10.4 Intentos de romper las correcciones (resultado)

| Ataque | Resultado |
|---|---|
| Envío en línea por otra ruta pública | No encontrado (inventario anterior). |
| Valores del interruptor `CORREOS_ENVIO_INMEDIATO` (`0 false no off '' 2 yes enabled 1.0 01 -1 null`) | Todos desactivados; solo `1/true/si/sí/on` (mayúsculas y espacios incluidos) activan (control positivo). |
| 8 `/olvide` simultáneos (procesos reales) | 1 token válido, a lo sumo 1 evento pendiente, **1 correo entregado, el vigente**, 0 obsoletos. |
| Dos o tres `/olvide` antes del worker | Eventos `cancelado, cancelado, pendiente` con carga borrada; solo sale el último enlace. |
| Enlace ya entregado y nueva solicitud | El enlace viejo responde 410; el nuevo 200. |
| Token vencido, borrado, de otro propósito o cuenta sin confirmar antes del envío | Evento `cancelado` sin enviar (4 variantes). |
| Confirmación cuyo token pasó a ser de recuperación | No se envía. |
| Envío **en vuelo** (SMTP de 1,5 s) cuando llega otra solicitud | Ventana residual conocida: puede salir además el enlace viejo (inválido, 410); el vigente también sale, una sola vez; a lo sumo 2 correos. Coherente con «al menos una vez». |
| Falla de la base al encolar (INSERT) o al cancelar (UPDATE) | Transacción completa revertida: el token vigente no cambia, no queda evento huérfano, no se filtra el detalle SQL (la respuesta es el 500 genérico preexistente). |
| Supervisor `notificaciones_worker.php` | Entrega la recuperación con `CORREOS_INTERVALO_SEGUNDOS=2` en menos de 10 s, atiende un correo nuevo con el bucle vivo, sale con código 1 ante `1`, `601`, `abc`, `-3`, `2.5` y termina con SIGTERM. |
| Denegaciones: 60 repetidas, variaciones de URL, otro actor / tipo, borde de 10 min | 1 fila; ver R2B.10.1. Ráfaga simultánea: R2B-06b. |
| Comprobación de que el token no está en claro | Ni en `correos_salida` (ninguna columna con forma de token), ni en la clave única (`recuperacion:<id>:<24 hex>`). |

### R2B.10.5 Expectativas modificadas por cambios de contrato deliberados (fase 2)

Ninguna se relajó para ocultar un fallo; cada una está justificada en un comentario del archivo.

| Prueba | Antes | Ahora | Justificación |
|---|---|---|---|
| `AutorizacionMatrizTest` / `matriz_rutas.php`: `POST /admin/equipo/reasignar` | `Location: /admin/equipo` | `@inicio` (`/admin` para propietario, gerente y recepción) | R2B-11 corregido; verificado por el código que `inicio_portal()` devuelve `/admin` para esos roles y que es accesible; la exclusión de `test_I_*` se retiró. |
| Matriz (A, B, C, E): `GET /admin/clientes/exportar` | «exactamente 1 fila nueva de auditoría por denegación» | 1 fila si no había otra denegación igual (mismo actor, tipo `clientes`, motivo `sin_permiso`) en los 10 min previos; 0 si la había. La expectativa se calcula del estado previo de la tabla, no del código de la aplicación. Éxito 200 = siempre 1. | R2B-06: agrupación de denegaciones idénticas; las pruebas explícitas están en `R2bCrmAuditoriaTest::test_R2B_06_…` (3 pruebas verdes; la ráfaga simultánea es la roja R2B-06b). |
| `ConcurrenciaTest` registro con claves distintas | ≤ 1 evento activo, `token_enviado` capturado en proceso | tras la carrera hay 1 evento por solicitud admitida y buzón vacío; tras el worker real: 0 activos, estados `{enviado:1, cancelado:n-1}`, **1 correo con el enlace vigente** | el correo ya no sale en la petición; el invariante se endurece (antes se entregaban enlaces muertos). |
| `CorreoTest` registro con SMTP caído | evento `pendiente` con 1 intento tras la petición | `pendiente` con **0 intentos** y sin error; el worker hace el 1.er intento fallido; el reenvío tampoco envía y cancela el evento anterior | la petición no intenta el envío. |
| `RegresionNegocioTest` registro público | correo en el buzón tras la petición | buzón sin cambios tras la petición; llega **1** correo (filtrado por destinatario) tras el worker | idem. |
| `R2bColaCorreosTest` alta / reenvío con SMTP roto, backoff | 1 intento contado en la petición; petición < 30 s; el SMTP colgado retrasaba 10,11 s | 0 intentos y petición < 2 s en los 5 modos (incluido `cuelga`); el 1.er intento es del worker y programa 60 s | idem. |
| `R2bColaCorreosTest` 8 reenvíos concurrentes | `obsoletos < 8` (se llegaron a entregar 6 enlaces muertos) | `obsoletos == 0`, 1 correo válido, 0 eventos activos | el worker comprueba la vigencia al despachar. |
| `R2bRegistroUniformeTest` (sin confirmar, aviso, 1/h, cola, concurrencia) | correo en el buzón tras la petición | buzón vacío tras la petición y correo tras `entregarCola()` (worker real) | idem. |
| `test_Defecto_R2B_01/02/03/05/06/07/08/09/10/11` | rojas | renombradas `test_R2B_NN_…` (verdes), ampliadas | el defecto quedó cerrado; ver R2B.10.1. |
| `RegresionNegocioTest::worker()` → `workerNotificaciones()` | — | — | renombrado por colisión con `R2bCase::worker()`. |

### R2B.10.6 Sensibilidad de las pruebas de la fase 2 (mutaciones sobre una COPIA; árbol real intacto)

| # | Mutación (archivo) | Resultado | Prueba que la detecta |
|---|---|---|---|
| N01 | la recuperación no comprueba la vigencia del token al despachar (`services/CorreoSalidaService.php`) | ROJO | `R2bColaRecuperacionTest::test_el_token_de_recuperacion_vencido_o_la_cuenta_modificada_cancelan_el_evento_sin_enviar` |
| N02 | una nueva recuperación no cancela los eventos anteriores (`services/AuthService.php`) | ROJO | `R2bColaRecuperacionTest::test_dos_olvide_antes_del_worker_cancelan_el_primero_y_solo_sale_el_enlace_vigente` |
| N03 | el envío inmediato queda siempre activo (`services/CorreoSalidaService.php`) | ROJO | `R2bColaRecuperacionTest::test_ninguna_ruta_publica_envia_correo_dentro_de_la_peticion_con_un_smtp_lento_y_sin_la_variable_de_envio_inmediato` |
| N04 | la agrupación de denegaciones de la auditoría nunca agrupa (`repositories/CrmAuditoriaRepository.php`) | ROJO | `R2bCrmAuditoriaTest::test_R2B_06_las_denegaciones_repetidas_del_mismo_actor_se_agrupan_y_la_primera_evidencia_se_conserva` |
| N05 | el asistente de agenda vuelve a usar la zona horaria de PHP (`repositories/ProfesionalRepository.php`) | ROJO | `R2bAgendaTest::test_R2B_10_retirar_un_servicio_o_poner_un_descanso_respeta_las_citas_de_hoy_aunque_el_reloj_de_php_este_en_otra_zona` |
| N06 | el error de validación de la reasignación vuelve a /admin/equipo (`controllers/EquipoController.php`) | ROJO | `R2bAgendaTest::test_R2B_11_el_error_de_validacion_de_la_reasignacion_vuelve_al_inicio_accesible_de_cada_rol` |
| N07 | el CSV de campañas vuelve al saneador anterior (sin tabulador ni retorno) (`controllers/CrecimientoController.php`) | ROJO | `R2bCrmAuditoriaTest::test_R2B_07_campana_de_clientes_y_fidelidad_neutraliza_tabulador_y_retorno_de_carro_iniciales` |
| N08 | el saneador de errores deja de ocultar direcciones de correo (`classes/Email.php`) | ROJO | `R2bColaCorreosTest::test_R2B_03_la_direccion_del_destinatario_no_se_guarda_en_errores_ni_se_imprime` |
| N09 | s() vuelve a convertir arreglos a cadena (`includes/funciones.php`) | ROJO | `R2bAgendaTest::test_R2B_04_los_campos_con_formas_hostiles_no_producen_advertencias_ni_reflejan_basura` |
| N10 | el migrador no imprime la pista cuando el rollback se niega (1242) (`database/migrador.php`) | ROJO | `R2bReversionTest::test_R2B_05_la_negativa_del_rollback_de_022_explica_el_remedio_y_el_remedio_funciona` |
| N11 | el aviso de cuenta existente vuelve a enlazar a /login (`classes/Email.php`) | ROJO | `R2bRegistroUniformeTest::test_R2B_01_los_enlaces_del_aviso_de_cuenta_existente_funcionan_en_todas_las_ramas` |
| N12 | /olvide vuelve a enviar en línea (`services/AuthService.php`) | ROJO | `R2bRegistroUniformeTest::test_R2B_08_olvide_no_revela_si_la_cuenta_existe_por_el_tiempo_de_respuesta` |
| N13 | despacharTrasCommit envía siempre en línea (`services/CorreoSalidaService.php`) | ROJO | `R2bRegistroUniformeTest::test_R2B_02_el_tiempo_de_la_solicitud_no_distingue_cuentas_confirmadas_de_nuevas` |
| N14 | el asistente deja pasar selecciones no escalares (`views/equipo/_agenda_asistente.php`) | ROJO | `FallosTest::test_invitar_con_servicios_no_escalares_o_inexistentes_da_error_controlado` |
| N15 | la comprobación de vigencia trata una recuperación como cuenta no confirmada (`services/CorreoSalidaService.php`) | ROJO | `R2bColaRecuperacionTest::test_el_token_de_recuperacion_vencido_o_la_cuenta_modificada_cancelan_el_evento_sin_enviar` |

Las 15 mutaciones fueron detectadas (0 supervivientes). Se aplicaron sobre la copia `/tmp/qa2_mut` con la base `appsalon_qa_r2bm_test`; el árbol real no se tocó.

### R2B.10.7 Resultado de la ejecución completa (fase 2)

Comando (raíz del árbol, `DB_NAME=appsalon_qa_ronda2_test`, SMTP simulado propio, `QA_EXIGIR_NAVEGADOR=1`):

```
php -d variables_order=EGPCS vendor/bin/phpunit --do-not-cache-result --colors=never --log-junit <junit.xml> tests/Qa
Tests: 588, Assertions: 5225, Failures: 4.        (DURACION_SEG=741, es decir 12 min 21 s)
```

- **Omitidas: 0** (`php scripts/ci_verificar_omitidas.php <junit.xml> .github/qa_omitidas_permitidas.txt` → `Pruebas en el informe: 588; omitidas: 0`, código 0). La lista permitida sigue vacía.
- **Los 4 fallos son las pruebas rojas de los residuos abiertos**: R2B-03b (1), R2B-04b (1), R2B-06b (1) y R2B-12 (1). Ninguno es de arnés. La ejecución completa anterior de esta fase (586 pruebas, 5211 aserciones) tuvo los 3 primeros, y la inicial de la fase 1 (564 pruebas) 18 fallos.
- Una intermitencia de la fase 1 (`POST /olvide | profesional | html`, un correo inesperado en la matriz) **no volvió a aparecer** en las 4 ejecuciones completas de esta fase y la anterior; la causa sigue sin identificarse (ahora, con la cola sin envío en línea, la petición ya no puede enviar nada).
- Tiempos por clase (s): `AutorizacionMatrizTest` 210, `R2bAgendaTest` 71, `ConcurrenciaTest` 47, `R2bReversionTest` 44, `R2bColaCorreosTest` 43, `EsquemaTest` 34.
- Regresión de los desarrolladores (`tests/Unit` + `tests/Integration`, base aparte `appsalon_qa_r2bdev_test`, código ya integrado): `OK (674 tests, 10172 assertions)`, `DURACION_SEG=227`.
- Clases nuevas o muy modificadas en esta fase: `R2bColaRecuperacionTest` (15 pruebas, 1 roja por R2B-12), `R2bRegistroUniformeTest`, `R2bColaCorreosTest`, `R2bCrmAuditoriaTest` (17), `R2bAgendaTest` (57), `R2bReversionTest` (12), `CorreoTest`, `ConcurrenciaTest`, `RegresionNegocioTest`, `AutorizacionMatrizTest`/`matriz_rutas.php`/`MatrizHttp.php`, `worker.php` (acción `olvideCola`).

### R2B.10.8 No cubierto en la fase 2

- `\f` (salto de página) inicial en el CSV: no se pudo comprobar con una hoja de cálculo real si se interpreta como inicio de fórmula (hipótesis).
- Con **varias cuentas de cliente** (el registro es abierto) la auditoría de solo anexado sigue pudiendo crecer: no se midió a escala.
- Sigue sin cubrirse Docker/Apache, SMTP real, IPv6/proxy, navegadores distintos de Chromium y horario real (el desfase horario se simula).
- El supervisor se probó en una máquina con `pcntl`; no se probó su comportamiento ante una base caída durante horas.

## R2B.11 Fase 3 – Residuos, documentación y portabilidad del CI

Se integró `f118591` (avance rápido sobre `82f8fcc`). Solo se tocó `tests/Qa/**`, `.github/qa_omitidas_permitidas.txt` y este documento.

### R2B.11.1 Estado final de CADA hallazgo R2B

| Hallazgo | Estado final | Prueba |
|---|---|---|
| R2B-01 enlace del aviso | **Corregido y reverificado** | `R2bRegistroUniformeTest::test_R2B_01_…` |
| R2B-02 tiempo 2.ª solicitud | **Corregido y reverificado** | `test_R2B_02_…` |
| R2B-03 PII en `ultimo_error`/`--estado` (direcciones corrientes) | **Corregido y reverificado** | `R2bColaCorreosTest::test_R2B_03_…` |
| R2B-03b parte local con caracteres especiales | **Corregido y reverificado** (14 caracteres + comillas y llaves; salida del saneador comprobada con 30 formas) | `test_R2B_03b_…` |
| **R2B-03c** (nuevo) dirección completa en `ultimo_error` y `--estado` | **ABIERTO (Baja)**: el saneador exige un dominio con punto y TLD alfabético y excluye las comillas de la parte local, así que las direcciones que la aplicación SÍ admite como `"zq@x"@example.test` (parte local entre comillas con `@`) y `zq@[127.0.0.1]` (literal de IP) quedan **completas** en `correos_salida.ultimo_error` y en `--estado`/`--estado --json`. Reproducción mínima: registrar `"zq@x"@example.test` con el SMTP en `rcptmulti`/`rcpt5xx`, ejecutar el worker y leer `ultimo_error`. De las 14 formas probadas, la aplicación admitió 8: las otras 6 (plano, mayúsculas, `+`, subdominios, guion, apóstrofo y llaves) se redactan por completo; rechaza al registrar el espacio entre comillas, el unicode (parte local y dominio), la parte local de 64 caracteres, el dominio muy largo y `@localhost`. | `test_Defecto_R2B_03c_…` |
| R2B-04 / R2B-04b arreglos | **Corregidos y reverificados** | `R2bAgendaTest::test_R2B_04_…`, `test_R2B_04b_…` y la batería `test_R2B_04c_…` (24 claves × 7 formas, incluidas anidación de 40 niveles, contra 7 rutas POST y 3 pantallas GET: cero advertencias, cero «Array» reflejado, cero 5xx) |
| R2B-05 pista del migrador | **Corregido y reverificado** | `R2bReversionTest::test_R2B_05_…` |
| R2B-06 / R2B-06b agrupación de denegaciones | **Corregidos y reverificados**: ráfagas de 16 y 32 peticiones simultáneas del mismo actor (3 rondas cada una) dejan **exactamente 1 fila**; una ráfaga de 36 peticiones de 6 actores mezclados deja **1 fila por actor**; 16 éxitos simultáneos del propietario dejan 16 filas; ningún candado queda colgado (`performance_schema.metadata_locks`), ni tras una excepción dentro de la sección crítica; con el candado retenido por otra conexión la auditoría espera ≈ 3 s y registra igualmente (falla abierta, nunca omite la fila; el candado es por actor, un actor no frena a otro). | `R2bCrmAuditoriaTest::test_R2B_06_…` (3) y `test_R2B_06b_…` (5) |
| R2B-07 CSV | **Corregido y reverificado**, incluidos `\f` y `\v` iniciales (nuevas celdas hostiles) | `test_R2B_07_…` |
| R2B-08, R2B-09 | **Corregidos y reverificados** | `test_R2B_08_…`, `test_R2B_09_…` |
| R2B-10 zona horaria | **Corregido y reverificado** | `R2bAgendaTest::test_R2B_10_…` |
| R2B-11 error de reasignación | **Corregido y reverificado** | `test_R2B_11_…` |
| R2B-12 texto de las invitaciones | **Corregido y reverificado** (se corrigió el texto; el comportamiento no cambió): el documento limita la afirmación a las rutas públicas y declara la excepción de invitaciones y avisos de cita/reasignación; la medición coincide (invitación en la petición con un SMTP de 400 ms; con el SMTP caído queda `pendiente` con 1 intento y el worker la entrega). | `R2bColaRecuperacionTest::test_R2B_12_…` |
| **R2B-13** (nuevo) diagrama de §5.1 obsoleto | **ABIERTO (Baja, documentación)**: el diagrama de flujo de `docs/NOTIFICACIONES_WORKER.md` §5.1 aún dice `despacharTrasCommit(): intento inmediato (PHPMailer…)` para `registrar / reenviarConfirmacion` y `scripts/procesar_correos.php (cada NOTIFICACIONES_INTERVALO_SEGUNDOS, 60 s por defecto)`. Medido: sin `CORREOS_ENVIO_INMEDIATO=1` no hay intento inmediato (0 intentos y buzón vacío tras la petición) y el supervisor atiende la cola cada `CORREOS_INTERVALO_SEGUNDOS` (5 s por defecto, comprobado con 2 s). | `test_Defecto_R2B_13_…` |

Revisión del resto de `NOTIFICACIONES_WORKER.md` (§5.x) contra lo medido: §5 (introducción), §5.3 (el intento nº 1 es la primera pasada del worker en rutas públicas y el inmediato en invitaciones; backoff 60/120/240/480/960 s), §5.7 (rutas públicas no esperan al SMTP; invitaciones hasta ≈ 10 s con SMTP colgado), y la afirmación de §5.1 «un reenvío concurrente … como máximo un evento activo» (8 reenvíos simultáneos: ≤ 1 pendiente, comprobado ahora con una aserción propia) coinciden con las mediciones. Único desajuste: R2B-13.

### R2B.11.2 CI: los 41 fallos del run 38481255271 (SHA f118591, PHP 8.2, 588 pruebas)

Obtenidos con `get_job_logs` (1833 líneas del job; sección «There were 41 failures»). **Causa de 40 de los 41: portabilidad del arnés (a).** `R2bCase::worker()` esperaba al proceso con `proc_get_status()` hasta que `running` fuera falso y después llamaba a `proc_close()`. PHP solo informa el `exitcode` una vez, en la primera `proc_get_status` posterior a la terminación; en PHP 8.2 `proc_close()` devuelve entonces `-1` (en 8.3 devuelve el código real, por eso en local todo pasaba). Todas las aserciones `assertSame(0, $w['exit'])` fallaban con «Failed asserting that -1 is identical to 0» (y `-1` frente a `2` en la prueba de argumentos hostiles). No es un defecto de la aplicación ni de tiempo.

| # | Prueba | Causa |
|---|---|---|
| 1 | `ConcurrenciaTest::test_registro_claves_distintas_mismo_correo_una_sola_identidad` | (a) exit -1 en `entregarCola` |
| 2 | `CorreoTest::test_registro_con_smtp_caido_informa_la_via_de_recuperacion_y_no_pierde_la_cuenta` | (a) |
| 3–7 | `R2bColaCorreosTest::test_el_alta_con_smtp_roto_…` (cerrado, cuelga, rcpt4xx, rcpt5xx, data5xx) | (a) |
| 8–12 | `R2bColaCorreosTest::test_el_reenvio_con_smtp_roto_…` (5 modos) | (a) |
| 13 | `R2bColaCorreosTest::test_backoff_exacto_agotamiento_…` | (a) |
| 14 | `…::test_el_worker_corta_la_tanda_tras_tres_fallos_seguidos_…` | (a) |
| 15 | `…::test_reenvios_concurrentes_ocho_procesos_…` | (a) |
| 16 | `…::test_reenviar_sucesivo_cancela_el_evento_anterior_…` | (a) |
| 17 | `…::test_cuenta_confirmada_por_otra_via_cancela_el_evento_pendiente_…` | (a) |
| 18 | `…::test_evento_vencido_24h_se_cancela_y_no_se_envia` | (a) |
| 19 | `…::test_lease_vencido_lo_retoma_otro_worker_…` | (a) |
| 20 | `…::test_worker_matado_a_mitad_del_envio_…` | (a); además usaba `posix_kill` (extensión posix): ahora `proc_terminate($p, 9)` |
| 21 | `…::test_la_linea_de_comandos_del_worker_resiste_argumentos_hostiles` | (a) `-1` frente a `2` |
| 22–32 | `R2bColaRecuperacionTest` (11 pruebas: ruta pública sin envío, olvide encola, personal, dos olvide, enlace entregado, token vencido, confirmación frente a recuperación, ocho olvide, envío en vuelo, falla de la cola, SMTP roto) | (a) |
| 33 | `R2bColaRecuperacionTest::test_Defecto_R2B_12_…` | (d) consecuencia del cambio de contrato: la precondición exigía que el documento siguiera afirmando lo antiguo; reescrita (arriba) |
| 34–40 | `R2bRegistroUniformeTest` (sin confirmar, aviso titular, 1/h, cola una vez, concurrencia, R2B_02, R2B_01) | (a) |
| 41 | `RegresionNegocioTest::test_cliente_de_registro_publico_recorre_el_negocio_completo` | (a) |

Sin fallos de las categorías (b) defectos de la aplicación visibles solo en 8.2, ni (c) umbrales de tiempo: las pruebas de temporización (R2B-02/08/09, supervisor, 3 s del candado, ráfagas) **pasaron** en el runner compartido. Cambios: `R2bCase::worker()` guarda el `exitcode` cuando `running` es falso y lo usa si `proc_close()` devuelve `-1`; `posix_kill` sustituido por `proc_terminate`. Se revisaron todos los `proc_close`/`proc_get_status` de `tests/Qa`: los demás llaman a `proc_close` sin `proc_get_status` previo (correctos en 8.2) o leen `$st['exitcode']` directamente (supervisor). Se buscó sin resultado en `tests/Qa` cualquier función o sintaxis exclusiva de PHP 8.3+ y el uso de extensiones no declaradas en el workflow.

### R2B.11.3 Sensibilidad (mutaciones sobre una copia)

| # | Mutación (archivo) | Resultado | Prueba que la detecta |
|---|---|---|---|
| P01 | `profesional_id` vuelve al cast sin comprobar escalar (`views/equipo/index.php`) | ROJO | `R2bAgendaTest::test_R2B_04b_…` (y `test_R2B_04c_…`) |
| P02 | la auditoría no toma el candado (`services/CrmAuditoriaService.php`) | ROJO | `R2bCrmAuditoriaTest::test_R2B_06b_una_rafaga_simultanea_del_mismo_actor_deja_una_sola_fila` |
| P03 | el candado no se libera tras una excepción (`CrmAuditoriaService`) | ROJO | `R2bCrmAuditoriaTest::test_R2B_06b_un_fallo_dentro_de_la_seccion_critica_libera_el_candado` |
| P04 | el nombre del candado ignora al actor (`CrmAuditoriaService`) | **SOBREVIVIÓ (equivalente)** | la agrupación la decide `hayDenegadaReciente` (por actor); un candado global solo serializa más, no cambia el resultado |
| P05 | el saneador vuelve a la clase de caracteres anterior (`classes/Email.php`) | ROJO | `R2bColaCorreosTest::test_R2B_03b_…` |
| P06 | el CSV deja pasar `\f`/`\v` iniciales (`services/ReporteService.php`) | ROJO | `R2bCrmAuditoriaTest::test_R2B_07_…` |

### R2B.11.4 Resultado de lo ejecutado (clases afectadas)

```
php -d variables_order=EGPCS vendor/bin/phpunit --do-not-cache-result --colors=never --filter '/R2bColaCorreosTest|R2bColaRecuperacionTest|R2bCrmAuditoriaTest|R2bAgendaTest|CorreoTest|ConcurrenciaTest|RegresionNegocioTest|R2bRegistroUniformeTest|R2bReversionTest|FallosTest/' tests/Qa
Tests: 229, Assertions: 3667, Failures: 2.     (DURACION_SEG=349)
```

Los 2 fallos son las pruebas rojas de los dos hallazgos abiertos: R2B-03c y R2B-13. Omitidas: 0 (`ci_verificar_omitidas.php`: «Pruebas en el informe: 229; omitidas: 0», código 0); la lista permitida sigue vacía. No se ejecutó PHP 8.2 localmente (solo 8.3.6): la corrección del `exitcode` se razona a partir del comportamiento documentado de `proc_get_status`/`proc_close`.

## Ronda 3 – Fase A (reproducción independiente de tres hallazgos del usuario)

Base: `2240371` (integrada en `wt/qa2`). Clase nueva `tests/Qa/R3Test.php` (SMTP simulado 2593, servidor HTTP real con 8 procesos, worker real `scripts/procesar_correos.php`; el arnés `QaCase::servidor()` admite ahora un valor `null` para quitar una variable del entorno). Las pruebas `test_Defecto_R3_*` están **rojas contra el código actual** y quedarán verdes con la corrección; las `test_R3_*` son controles que ya pasan. No se modificó código de la aplicación.

**Activación genérica (válida antes y después de la corrección).** Se abre el enlace con GET; si la respuesta contiene un `<form>` con campos de contraseña, se parsean sus `input` (nombre, valor, CSRF, clave de operación y token ocultos), se rellenan los campos de contraseña con una contraseña nueva C y se envía por POST a su `action`; si no hay formulario (diseño antiguo, el GET activa) se continúa con lo que haya. Las aserciones comparan después qué contraseñas inician sesión (`/cliente/login`, `/`, `/equipo/login` y `/login`, que no existe).

Ejecución: `Tests: 17, Assertions: 133, Failures: 11.` (6 controles verdes, 11 rojas).

### R3-1 Toma de cuenta por registro previo (Alta, CWE-287/640)
Reproducción mínima, ejecutada contra HTTP real: (1) el atacante hace `POST /cliente/registro` con el correo del titular y la contraseña A; (2) el titular se registra con B (respuesta uniforme, no pisa A); (3) el worker entrega el enlace al buzón del titular, que lo abre con GET; (4) **la cuenta queda confirmada con la contraseña A del atacante**.

```
test_Defecto_R3_01  la contraseña A del atacante, DESPUÉS de que el titular activó su cuenta (Atacante-Clave-111) no debe iniciar sesión por ningún acceso:
                    {"/cliente/login":true,"/":true,"/equipo/login":false,"/login":false}
```
Antes de activar, A no entra (la cuenta no está confirmada); después de que el titular abre su enlace, A entra por `/cliente/login` y por `/`. El mismo resultado tras re-registros y reenvíos intercalados (`test_Defecto_R3_02`: `{"/cliente/login":true,"/":true,…}`).

| Prueba | Salida literal en el código actual |
|---|---|
| `R3_03` cuenta de recepción (sin confirmar, contraseña aleatoria) | `la contraseña C elegida por quien activa … debe iniciar sesión en /cliente/login: {"/cliente/login":false,"/":false,"/equipo/login":false,"/login":false}` (la cuenta se activa por GET con una contraseña que nadie conoce; defecto de usabilidad, no toma de cuenta: A sí queda bloqueada) |
| `R3_04` 6 activaciones simultáneas | `cada uno de los 6 contendientes debe recibir su formulario de activación — Failed asserting that actual size 0 matches expected size 6.` |
| `R3_05` sesión previa | `una sesión previa guardada (sin versión) se «adopta» al quedar confirmada la cuenta: da acceso con la identidad del usuario sin conocer ninguna contraseña — Failed asserting that 200 is not identical to 200.` |
| `R3_06` doble clic | `DISEÑO NUEVO: el enlace debe mostrar el formulario de activación — Failed asserting that null is not null.` |

Detalles de R3-05 (verificados): `confirmAccountByToken` no incrementa `version_sesion` (a diferencia de `resetPasswordByToken`) y `isAuth()` «adopta» una sesión sin `version_sesion` al quedar la cuenta confirmada. Una sesión forjada o capturada para un usuario sin confirmar es inofensiva mientras la cuenta no está confirmada (el servidor la vacía al verla), **pero si se conserva sin usar, sobrevive a la activación y da acceso** (y la que lleva la versión actual también). Es un escenario de laboratorio (requiere tener una sesión para esa identidad); se reporta porque el contrato pedido exige invalidar las sesiones previas al activar.

Controles verdes (el contrato ya se cumple): `R3_07` el reenvío invalida el enlace viejo (410, sin formulario); `R3_08` registrar sobre una cuenta confirmada no cambia la contraseña, no confirma ni deja token; `R3_09` token vencido no activa (410); `R3_13` tokens cruzados de confirmación y recuperación no sirven (ni consumen el token); `R3_14` un token de confirmación residual sobre una cuenta ya confirmada y **suspendida** no cambia su contraseña ni la reactiva; `R3_15` token inexistente, vencido o de otro tipo responden idéntico y no consumen el token real.

### R3-2 Confirmación por GET (Alta)
```
test_Defecto_R3_10  un GET/HEAD/prefetch activó la cuenta (confirmado=1) — Failed asserting that 1 is identical to 0.
```
Reproducción: tras el registro, `GET /confirmar-cuenta?token=<token del buzón>` con cualquier cliente (curl, Googlebot, Outlook SafeLinks, `Purpose: prefetch`, `Sec-Purpose`) confirma la cuenta y consume el token. `HEAD` no la activa (el controlador lo trata aparte), pero el GET sí. Las pruebas `R3_11` (POST con CSRF: sin CSRF, vacío, de otra sesión, inventado, método GET/PUT, sin sesión, token en la URL, token distinto, contraseña vacía/corta/discordante: ningún efecto) y `R3_12` (≥ 1 respuesta 429 en 40 POST con tokens inventados) fallan hoy en su precondición: `DISEÑO NUEVO: el enlace debe mostrar un formulario de activación (POST) — Failed asserting that null is not null.` (no existe endpoint POST de activación ni límite de intentos).

### R3-3 `CORREOS_ENVIO_INMEDIATO=1` sin comprobar el entorno (Media)
```
test_Defecto_R3_20  con APP_ENV fuera de desarrollo/pruebas CORREOS_ENVIO_INMEDIATO=1 NO debe enviar dentro de la petición (SMTP de 400 ms):
APP_ENV=«production» registro: 1 correo(s) en la petición y 569 ms
APP_ENV=«production» olvide: 1 correo(s) en la petición y 468 ms
APP_ENV=«production» reenviar: 1 correo(s) en la petición y 471 ms
… igual para «Production», « production », «PRODUCTION», «prod», «produccion», «staging», «stage», «qa», «live», «preprod», vacío y no definido
```
Servidores `php -S` reales con cada `APP_ENV` (en producción con `APP_KEY` de 40 caracteres), `CORREOS_ENVIO_INMEDIATO=1` y un SMTP de 400 ms: **los 13 valores envían en línea** y la petición paga la latencia del SMTP, reabriendo el canal de enumeración de R2B-02/08/09 si una instalación deja la variable activa por error. Control positivo (la prueba detecta el canal): con `testing`, `test`, `development`, `dev` y `local` se espera envío en línea (≈ 470–600 ms).

**Decisión sobre mayúsculas (criterio de seguridad, «ante la duda, NO habilitar»).** Solo el valor **exacto en minúsculas y sin espacios** de `development`, `dev`, `local`, `testing`, `test` habilita el envío en línea; `TESTING`, ` testing `, `Development`, `DEV`, `Local`, `Test ` o `testing\n` no. `Secretos::clave()` es más permisiva (recorta y pasa a minúsculas) y existe un riesgo de deriva entre ambos criterios; si el líder decide igualarlos debe hacerlo de forma explícita y revisar `test_Defecto_R3_21`, que hoy falla con 21 líneas «APP_ENV=«TESTING» registro envió en línea…».

### Otros caminos que activan o confirman una cuenta (revisión por lectura)
- `UsuarioRepository::confirmAccountByToken` (GET) es el único camino que confirma una cuenta de cliente por correo. Fija `confirmado=1` sin elegir contraseña, sin comprobar CSRF y sin incrementar `version_sesion`.
- `ColaboradorRepository::aceptar` (invitación del equipo): si el correo ya tiene una cuenta **sin confirmar** (p. ej. pre-registrada por un atacante), la invitación **sustituye la contraseña** por la que elige el invitado y la confirma, e incrementa `version_sesion`: es seguro y es el patrón que la corrección debe imitar. Si la cuenta ya está confirmada exige la contraseña actual. La vista previa (`vistaInvitacion`, GET) no consume el token.
- `ColaboradorRepository` (arranque del propietario con `password`): solo con el secreto de instalación y, si la cuenta existe, exige que la contraseña coincida; `scripts/instalar.php` entrega un enlace de activación de invitación (mismo patrón seguro).
- `UsuarioRepository::resetPasswordByToken` (recuperación) no exige `confirmado=1` en el `WHERE` (la emisión sí lo exige); no es explotable hoy porque el token solo se emite para cuentas confirmadas, pero convendría añadir la condición como defensa en profundidad.
- No se encontró ningún otro `UPDATE usuarios SET confirmado=1` ni cambio de correo en `PerfilService`/`ProfileService` (no hay edición de `email` en usuarios).

# QA independiente: identidades, portales, colaboradores, CRM, instalador y respaldo

Revisión adversarial e independiente del commit `4e542af` (rama `claude/blissful-hopper-f5k7a0`, «instalador idempotente…»).
Todas las pruebas son nuevas y viven en `tests/Qa/`; no se modificó código de la aplicación ni pruebas existentes.
Fecha de ejecución: 2026-10-13. Entorno: PHP 8.3.6 (el objetivo es 8.2; ver «No cubierto»), MySQL 8.0.46, PHPUnit 10.5.66, Chromium 1194 con puppeteer-core.

Convenciones: **DEFECTO** = el ataque rompió una garantía y existe una prueba roja `test_Defecto_*`; **PASA** = el ataque se ejecutó y el sistema resistió;
**OBSERVACIÓN** = comportamiento discutible sin prueba roja; **NO PROBADO** = no se alcanzó. Hechos comprobados y suposiciones se separan expresamente.

## 1. Cómo reproducir

```bash
# Base propia (nunca appsalon_test). El nombre debe terminar en _test; el arnés exige el prefijo appsalon_qa_.
export DB_HOST=127.0.0.1 DB_PORT=3306 DB_USER=root DB_PASS=root DB_NAME=appsalon_qa_test APP_ENV=testing \
       APP_URL=http://localhost:3000 APP_TIMEZONE=America/Guayaquil PAYMENT_WEBHOOK_SECRET=ci_test_webhook_secret_hmac_2026 \
       EMAIL_HOST=127.0.0.1 EMAIL_PORT=2599 EMAIL_USER=t EMAIL_PASSWORD=t
mysql -h127.0.0.1 -uroot -proot -e "DROP DATABASE IF EXISTS appsalon_qa_test; CREATE DATABASE appsalon_qa_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -h127.0.0.1 -uroot -proot appsalon_qa_test < database/schema_base.sql
php database/migrador.php up
cp -r <repo-principal>/vendor ./vendor && ln -s <repo-principal>/node_modules node_modules     # no se ejecuta composer install

# Toda la suite QA (sin editar phpunit.xml; se pasa la ruta):
php -d variables_order=EGPCS vendor/bin/phpunit --do-not-cache-result --colors=never tests/Qa
# Un archivo o una prueba:
php -d variables_order=EGPCS vendor/bin/phpunit --do-not-cache-result --colors=never --filter '<nombre>' tests/Qa/<Archivo>.php
```

El arnés (`tests/Qa/QaCase.php`) aborta si `DB_NAME` no cumple `appsalon_qa_*_test`; las pruebas destructivas/de esquema crean y borran sus propias bases
`appsalon_qa_<x>_test`. Los servidores HTTP son `php -S` reales con router propio (`tests/Qa/router.php`), el SMTP simulado es propio (`tests/Qa/smtp_qa.php`, puertos 2596-2598)
y la concurrencia usa procesos PHP reales con una barrera de tiempo común (`tests/Qa/worker.php` + `proc_open`).

## 2. Resultado de la ejecución completa

@@RESULTADO@@

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

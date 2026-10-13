# Arquitectura de identidades: clientes, equipo y profesionales

Decisión de arquitectura (ADR) de la fase «identidades, clientes y equipo». Describe lo **implementado y probado**; los límites
pendientes están en [`ENTREGA_IDENTIDADES.md`](ENTREGA_IDENTIDADES.md).

## 1. Decisión

**Identidad de autenticación compartida, membresías separadas, sesión con portal explícito.**

| Concepto | Dónde vive | Qué representa |
|---|---|---|
| Identidad / credenciales | `usuarios` | Correo, hash de contraseña, verificación y `version_sesion`. Una persona = una cuenta. |
| Cliente | `clientes` (migración 016) | Membresía de cliente: estado `activo`/`suspendido`, origen, fecha de alta. Se crea **en la misma transacción** que la cuenta en el registro público. |
| Colaborador | `colaboradores` (018) + `usuario_accesos` | Ficha laboral con estado `invitado`/`activo`/`suspendido`/`baja`, rol, puesto, fecha de ingreso y notas de RR. HH. `usuario_accesos` sigue siendo el acceso de ejecución (rol + activo). |
| Invitación | `colaborador_invitaciones` (018) | Token de propósito exclusivo (SHA-256, 7 días, un solo uso). Una pendiente por colaborador (índice único funcional). |
| Profesional | `profesionales` (+ `colaborador_id`, `usuarioId`) | Capacidad operativa: agenda, servicios, horarios. Una ficha por colaborador; las fichas heredadas sin cuenta conservan `NULL`. |
| Correo saliente | `correos_salida` (018) | Outbox transaccional con carga cifrada (XSalsa20-Poly1305) y clave única por causa. |
| Operación idempotente | `operaciones_idempotentes` (017) | Reserva persistente de claves de alta (ver §3). |

**Por qué no dos bases ni dos tablas de credenciales.** Duplicar contraseñas obliga a sincronizarlas y rompe el caso «empleada que
también es clienta». Con una identidad y membresías separadas, los datos y servicios están separados (dominio) y la sesión decide
qué contexto está activo (experiencia).

**Política de doble perfil.** Una persona puede ser cliente y colaboradora. Entra por el portal que elija; la sesión guarda
`$_SESSION['portal']` (`cliente` | `equipo`) y el servidor la exige: en el portal de clientes `puede()` siempre devuelve `false`, `rol_actual()`
devuelve `cliente` y no se hereda `admin`. Una cuenta **solo laboral** no entra por el portal de clientes (se la orienta al del equipo)
y una cuenta de cliente no entra al portal del equipo. Un registro público **nunca** produce acceso laboral.

## 2. Portales y rutas

| Portal | Entradas | Notas |
|---|---|---|
| Clientes | `/cliente/login`, `/cliente/registro` (`/` y `/crear-cuenta` siguen funcionando) | Registro público → verificación por correo → reserva. |
| Equipo | `/equipo/login`, `/equipo/aceptar?token=…` | **Sin registro público.** El acceso nace de una invitación aceptada. |
| Administración | `/admin/*` dentro del portal del equipo | Permisos por rol, comprobados en el servidor en cada petición. |

Compatibilidad conservada: `/`, `/crear-cuenta`, `/confirmar-cuenta`, `/recuperar`, `/olvide`, callbacks de pago. El cierre de sesión
vuelve al acceso del portal correspondiente; los enlaces de recuperación indican el portal.

Perfil y ajustes por contexto: **Mi cuenta** (`/perfil`) es personal; **Ajustes del negocio** (`/admin/ajustes`) es organizacional y tiene su
propia sección en la navegación. Desde el portal del equipo `ProfileService::guardar(..., 'equipo')` **no modifica** comunicaciones ni
consentimiento comercial de nadie; desde el portal de clientes no se publica el retrato profesional.

## 3. Contrato de idempotencia (altas)

Objetivo: dos clics, reintentos, pestañas, procesos distintos o una respuesta perdida tras el *commit* **no** crean otra persona, otra
invitación ni otro correo.

Tres capas:

1. **Interfaz** (`src/js/ui.js`, atributo `data-una-vez`): un solo envío por formulario; `aria-busy`, `aria-disabled` (sin `disabled`, que
   descartaría el valor del botón), mensaje en región `role=status`, liberación al restaurar desde caché o tras 20 s sin respuesta.
2. **Servidor** (`Classes\Idempotencia`): la clave se genera al *mostrar* el formulario (`idem_key`, 128 bits) y se reutiliza al reenviar.
   La reserva en `operaciones_idempotentes` ocurre **dentro de la misma transacción** que el alta:
   * mismo `(scope, actor, clave)` y **mismo contenido** → resultado original, sin repetir efectos (ni correo);
   * mismo `(scope, actor, clave)` y contenido distinto → conflicto (`409`), el alta original no se toca;
   * otro actor con la misma clave → operación independiente (no ve el resultado ajeno);
   * alta revertida → no queda fila de éxito; la misma clave sirve para reintentar;
   * dos solicitudes simultáneas → la segunda espera en el índice único (hasta 15 s) y recupera el resultado o ejecuta el suyo;
     `1213` (interbloqueo) se reintenta; `1205` responde «en proceso».
   * La clave se guarda como SHA-256; la huella del contenido es **HMAC-SHA256** con clave derivada de `APP_KEY` (HKDF por propósito), por lo
     que la tabla no permite adivinar contraseñas. No se reutiliza el token CSRF (control distinto).
   * Actores: `usuario:ID` / `equipo:ID` (autenticados) o `anonimo:HMAC(sesión)` (registro público). Retención: 30 días.
3. **MySQL**: restricciones que representan la identidad de negocio aunque se salte la aplicación:
   `UNIQUE LOWER(TRIM(email))` en `usuarios`, `UNIQUE(email_norm)` y `UNIQUE(usuario_id)` en `colaboradores`,
   `UNIQUE(colaborador_id)` en `profesionales`, `UNIQUE(clave_unica)` en `correos_salida` y una sola invitación pendiente por colaborador.

**Qué es duplicado y qué no.** Correo normalizado = trim + minúsculas (no se eliminan puntos ni `+`). Nombre/apellido **no** son claves únicas
(homónimos); el teléfono tampoco (familias). Para profesionales sin cuenta la única protección es la idempotencia, más un **aviso de homónimo**
en el asistente (requiere confirmar «es otra persona»). Cliente y colaborador del mismo correo son **membresías** de una misma identidad.

**Duplicados existentes.** `php scripts/diagnosticar_duplicados.php [--json]` (sesión `READ ONLY`) lista candidatos con evidencia (citas, servicios,
comisiones, cercanía temporal entre altas). Nunca borra ni fusiona; propone desactivar —no borrar— tras revisar citas futuras. La migración 017
incluye `017_idempotencia_unicidad.precheck.php`: si ya hay correos equivalentes, **aborta antes de alterar nada** con el detalle.

**Límite honesto del outbox.** Un único evento por causa impide dobles correos por doble clic o reintento; no garantiza entrega SMTP «exactamente
una vez» si el servidor acepta el mensaje y la conexión se corta antes de confirmarlo (se reintentaría).

## 4. Ciclo de vida del colaborador

```
(invitar) ──► invitado ──aceptar──► activo ──suspender──► suspendido ──reactivar──► activo
                │ revocar/expirar          │                       │
                ▼                          └────────baja───────────┴──► baja (conserva historial)
              baja
```

* **Invitar** (propietario): crea ficha + invitación + evento de correo en una transacción. Si el correo ya es cliente, se **vincula** la misma
  cuenta (no se duplican credenciales) y su experiencia de cliente no cambia. Si el rol es profesional: crea la ficha operativa (inactiva hasta
  aceptar) o **vincula una ficha heredada** conservando reservas e historial. No se inventan correos.
* **Aceptar**: el token se consume atómicamente. Cuenta nueva → contraseña propia. Cuenta existente **confirmada** → exige su contraseña actual
  (no se cambia). Cuenta existente **sin confirmar** → el token prueba el control del buzón: se fija la contraseña elegida, se confirma y se
  invalidan sesiones previas. GET/HEAD nunca consumen.
* **Reenviar**: acción explícita, limitada a 1/minuto, revoca el enlace anterior y cancela su evento. **Revocar**: cierra la ficha como baja.
* **Suspender/baja/cambiar rol**: revoca sesiones (`version_sesion`), protege al último propietario (candado `equipo_mutex`) y, si es profesional con
  citas futuras, **bloquea** hasta resolverlas (se informa cuántas). La baja desactiva su ficha en la agenda; no se borra nada.

## 5. Matriz de roles y permisos

| Permiso | Propietario | Gerente | Recepción | Profesional | Finanzas | Cliente |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| equipo.gestionar (invitar/roles/estados) | ✔ | | | | | |
| rh.gestionar (ficha laboral) | ✔ | | | | | |
| servicios / profesionales / ajustes | ✔ | ✔ | | | | |
| perfiles.gestionar (clientes) | ✔ | ✔ | ✔ | | | |
| agenda.gestionar | ✔ | ✔ | ✔ | | | |
| agenda.ver_propia | ✔ | | | ✔ | | |
| finanzas / inventario | ✔ | ✔ | | | ✔ | |
| reportes.ver | ✔ | ✔ | | | ✔ | |
| clientes.ver (operativo, sin gasto) | ✔ | ✔ | ✔ | | | |
| clientes.ver_propios (solo quienes atendió) | ✔ | | | ✔ | | |
| clientes.analitica (gasto, RFM, cohortes) | ✔ | ✔ | | | ✔ | |
| clientes.exportar (CSV) | ✔ | ✔ | | | | |
| Reservas y perfil propios | — | — | — | — | — | ✔ |

Ocultar un menú no es seguridad: cada controlador exige el permiso y el portal en el servidor; la prueba de acceso directo cubre las rutas nuevas.

## 6. Primer propietario

`scripts/instalar.php` (servicio `instalador` de Compose): idempotente, concurrente-seguro, **sin contraseña publicada**.
Base vacía → carga el esquema base y migra; base con datos → **no** migra sin `--migrar`/`APPSALON_AUTO_MIGRATE=1` (respaldo primero).
Con `OWNER_EMAIL` y sin contraseña emite un **enlace de activación de un solo uso** (se muestra una vez; 7 días; también por correo si hay SMTP);
con `APPSALON_OWNER_PASSWORD` (12–72) crea la cuenta activa. Si ya hay un propietario activo no hace nada; nunca cambia contraseña ni rol de una
cuenta existente. En producción exige `APP_KEY`.

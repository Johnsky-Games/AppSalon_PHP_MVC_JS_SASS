# Guía de Instalación, Configuración y Verificación — Entrega 1

Esta guía detalla los procedimientos estandarizados de despliegue, configuración de variables de entorno, ejecución de pruebas automatizadas y comprobación funcional HTTP para la **Entrega 1: Seguridad y Consistencia** de AppSalon.

---

## 1. Configuración de Entorno (`includes/.env`)

La aplicación carga de manera centralizada las variables de entorno desde la ruta:
```
includes/.env
```
Este archivo es leído en [includes/app.php](file:///includes/app.php) mediante `Dotenv::createImmutable(__DIR__)`. El archivo `.env` en la raíz del proyecto no es consultado por el bootstrap del sistema.

### Plantilla de Configuración (`includes/.env.example`)
Copie la plantilla de referencia a `includes/.env`:
```bash
cp includes/.env.example includes/.env
```

Contenido representativo de `includes/.env`:
```env
# Conexión a Base de Datos MySQL
DB_HOST=127.0.0.1
DB_PORT=3306
DB_USER=root
DB_PASS=root
DB_NAME=appsalon_mvc

# Configuración SMTP Transaccional (Mailtrap, Mailpit o servidor local)
EMAIL_HOST=sandbox.smtp.mailtrap.io
EMAIL_PORT=2525
EMAIL_USER=tu_usuario_mailtrap
EMAIL_PASSWORD=tu_password_mailtrap
EMAIL_FROM=cuentas@appsalon.com
EMAIL_FROM_NAME=AppSalon.com

# URL Base del Sistema
APP_URL=http://localhost:3000
```

> [!IMPORTANT]
> Los tokens criptográficos de confirmación y recuperación **nunca se registran en los logs del servidor (`error_log`)**. Deben consultarse y verificarse directamente en el buzón de pruebas (bandeja de entrada SMTP o Mailtrap).

---

## 2. Rutas de Instalación y Migración de Base de Datos

Existen dos procedimientos claramente diferenciados para inicializar la base de datos:

### Ruta A: Instalación Nueva (Fresh)
Use esta ruta únicamente cuando vaya a inicializar una base de datos vacía desde cero.

1. **Crear la base de datos en MySQL:**
   ```sql
   CREATE DATABASE appsalon_mvc CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
2. **Importar el esquema base inicial:**
   ```bash
   mysql -u root -p appsalon_mvc < database/schema_base.sql
   ```
3. **Aplicar las migraciones incrementales versionadas:**
   ```bash
   php database/migrador.php up
   ```
4. *(Opcional)* Sembrar servicios de catálogo inicial:
   ```sql
   INSERT INTO servicios (nombre, precio) VALUES 
   ('Corte de Cabello Hombre', 80.00),
   ('Corte de Cabello Mujer', 120.00),
   ('Corte de Barba', 60.00);
   ```

### Ruta B: Actualización de Instalación Existente (Upgrade)
Use esta ruta en bases de datos que ya contienen usuarios, citas y servicios registrados.

1. **Realizar respaldo preventivo de la base de datos:**
   ```bash
   mysqldump -u root -p appsalon_mvc > backup_previo.sql
   ```
2. **NO importar `database/schema_base.sql`:**
   > [!CAUTION]
   > No ejecute `database/schema_base.sql` sobre una instalación existente. El script base contiene sentencias DDL iniciales que redefinirían la estructura y provocarían la pérdida de datos de usuarios y citas productivas.
3. **Ejecutar directamente las migraciones incrementales:**
   ```bash
   php database/migrador.php up
   ```
   El migrador detectará las migraciones pendientes (`001_security_hardening.sql`, `002_innodb_and_rate_limit_window.sql`), convirtiendo tablas a InnoDB, añadiendo columnas de tokens criptográficos (`token_hash`, `token_tipo`, `token_expira`) y configurando la tabla `intentos_login` con `primera_peticion`, preservando el 100% de la información histórica.

---

## 3. Alcance del Comando `down` en el Migrador

En [database/migrador.php](file:///database/migrador.php), el comando:
```bash
php database/migrador.php down
```
> [!WARNING]
> Procesa en orden inverso (`rsort`) **todas las migraciones registradas** que tengan script de reversión (`*_rollback.sql`), revirtiendo el esquema completo hasta el estado previo a la primera migración aplicada. No revierte únicamente la última migración. Para revertir una migración específica, ejecute de forma aislada su archivo `database/migrations/<archivo>_rollback.sql` y ajuste la tabla `migraciones`.

---

## 4. Entorno de Pruebas Automatizadas con Docker

Para ejecutar la suite de pruebas unitarias e integrales en un entorno aislado, se requiere una red Docker compartida, un contenedor de MySQL 8 y la imagen de PHP con extensiones `mysqli` y `pdo_mysql`. El comando PHPUnit aislado no puede preparar ni conectar estos componentes por sí mismo.

### Comandos de Ejecución Completa:
```bash
# 1. Crear red de puente de Docker
docker network create appsalon-net

# 2. Iniciar contenedor de base de datos MySQL 8
docker run -d --name appsalon-test-db --network appsalon-net -p 3307:3306 \
  -e MYSQL_ROOT_PASSWORD=root \
  -e MYSQL_DATABASE=appsalon_test \
  mysql:8.0

# 3. Construir la imagen de prueba de PHP 8.2
docker build -t appsalon-php-test -f Dockerfile.test .

# 4. Ejecutar la suite completa de PHPUnit (52 pruebas, 360 aserciones)
docker run --rm --network appsalon-net -v "${PWD}:/app" -w /app \
  appsalon-php-test ./vendor/bin/phpunit --testdox
```

---

## 5. Puesta en Marcha del Servidor Local

El servidor de desarrollo debe apuntar estrictamente al directorio `public` como raíz de documentos:

### Opción 1: PHP CLI Local (si PHP está instalado en host)
```bash
php -S localhost:3000 -t public
```

### Opción 2: Vía Contenedor Docker (Recomendada)
```bash
docker run -d --name appsalon-web --network appsalon-net -p 3000:3000 \
  -v "${PWD}:/app" -w /app \
  appsalon-php-test php -S 0.0.0.0:3000 -t public
```

El servidor quedará disponible en: `http://localhost:3000`

---

## 6. Comportamiento y Reglas de Rate Limiting en Login

El sistema de limitación de tasa implementa una regla atómica por **ventana temporal fija** (no móvil ni deslizante) de 15 minutos (900 segundos):

1. **Mecánica de la Ventana Fija:**
   - La ventana se computa a partir de la marca temporal `primera_peticion` almacenada en la tabla `intentos_login`.
   - Si una solicitud llega dentro de los 900 segundos posteriores a `primera_peticion`, el contador `intentos` se incrementa atómicamente.
   - Si transcurren más de 900 segundos, la ventana expira y el contador se reinicia automáticamente a 1 en la siguiente solicitud.
2. **Umbral y Bloqueo en Login (Estado Inicial con Contadores Vacíos):**
   - **Intentos erróneos 1 al 5:** Admitidos por el rate limiter. Se verifica la contraseña con bcrypt y se retorna HTTP 200 con alerta `"El Password es Incorrecto o tu cuenta no ha sido confirmada"`.
   - **Sexto intento erróneo:** Rechazado inmediatamente con **HTTP 429 Too Many Requests** antes de ejecutar la verificación costosa de bcrypt.
   - **Mensaje al usuario:** `"Demasiados intentos fallidos. Por seguridad, intente de nuevo en X minutos."`
   - **Encabezado `Retry-After` dinámico:** Contiene los **segundos restantes** calculados en base a `TIMESTAMPDIFF(SECOND, NOW(), bloqueado_hasta)` hasta la expiración del bloqueo. Por lo tanto, el valor decrece en cada consulta y **no siempre será exactamente 900 segundos**.
   - Se fija `bloqueado_hasta = NOW() + 15 min` en la tabla `intentos_login`.
3. **Alcance de la Protección por Dirección IP:**
   - El límite por IP (`ip_login`, máximo 15 intentos) restringe el número de intentos fallidos que pueden provenir de una misma dirección de red o proxy de salida.
   - **Alcance real:** Mitiga eficazmente ataques de fuerza bruta concentrados originados desde un **mismo origen**.
   - **Limitación:** **No constituye ni demuestra protección general contra ataques distribuidos** (DDoS o redes de bots distribuidas entre múltiples direcciones IP distintas), los cuales requieren mecanismos perimetrales adicionales (e.g. WAF, Cloudflare, reputación de IP o captchas adaptativos).
4. **Consideración para Pruebas Manuales:**
   - Tras recibir el bloqueo en el sexto intento, la cuenta permanece bloqueada durante los segundos restantes de la ventana.
   - Si se requiere probar el inicio de sesión exitoso o la recuperación con esa misma cuenta inmediatamente después, debe limpiarse el contador en la base de datos de pruebas:
     ```sql
     DELETE FROM intentos_login WHERE identificador = 'tu_correo@ejemplo.com' OR tipo IN ('email_login', 'ip_login');
     ```

---

## 7. Comprobación y Verificación del Sistema

### A. Verificación Funcional HTTP (Automatizada — Estado: `Ejecutado`)
Se ejecutó de extremo a extremo contra el servidor HTTP real en `http://appsalon-web:3000` y la base de datos aislada `appsalon_func_test`, utilizando cookies de sesión nativas de cURL y receptor SMTP mock para la captura segura de tokens. La suite exige validación obligatoria de `SELECT DATABASE()` antes de cualquier operación destructiva.

| # | Escenario Funcional | Estado | Petición HTTP / Método | Código y Redirección | Evidencia Técnica en Base de Datos / Buzón |
|---|---|:---:|---|---|---|
| **1** | **Registro de Cuenta** | **Ejecutado** | `POST /crear-cuenta` | HTTP `302` $\rightarrow$ `/mensaje` | Usuario creado con `confirmado=0`, `admin=0`, `token=NULL` (sin texto plano), hash criptográfico presente en BD. Correo recibido en buzón; hash SHA-256 del token recibido coincide con BD. |
| **2** | **Confirmación de Cuenta** | **Ejecutado** | `GET /confirmar-cuenta?token={tok}` | HTTP `200` (Mensaje: "Cuenta confirmada correctamente") | `confirmado=1`, `token_hash=NULL`, `token_tipo=NULL` (consumo atómico verificado). Reintento con el mismo token consumido rechazado como inválido. |
| **3** | **Login y Rate Limiting** | **Ejecutado** | `POST /` (5 erróneos + 1 bloqueo + 1 válido) | Intentos 1-5: `200`<br>Intento 6: `429`<br>Login válido: `302` $\rightarrow$ `/cita` | `intentos_login` registra `intentos=5`, `bloqueado_hasta` futuro con alerta 429. Login exitoso genera cookie de sesión regenerada y permite acceso a `/cita` (`200 OK`, "Carlos Mendoza"). |
| **4** | **Recuperación de Contraseña** | **Ejecutado** | `POST /olvide`<br>`POST /recuperar?token={tok}` | Solicitud: `200`<br>Consumo: `302` $\rightarrow$ `/` | Buzón recibe correo de recuperación con hash coincidente en BD. Token consumido de forma atómica (`token_hash=NULL`). Login con clave anterior rechazado (`200`); login con clave nueva exitoso (`302`). |
| **5** | **Reserva de Cita (API)** | **Ejecutado** | `POST /api/citas` (con sesión de cliente y CSRF) | HTTP `200` JSON (`resultado.resultado: true`) | Cita registrada en tabla `citas` con `usuarioId` forzado de sesión, fecha futura válida y hora `10:30:00`. Tabla `citasservicios` vincula los 2 servicios seleccionados en transacción. |
| **6** | **Eliminación y Anti-IDOR** | **Ejecutado** | `POST /api/eliminar` (IDOR vs Propietario) | Intento IDOR: `403 Forbidden`<br>Eliminación dueño: `302` $\rightarrow$ `/cita` | Intento de eliminación por un segundo cliente rechazado con 403 (la cita se mantiene intacta). Eliminación por su dueño borra la cita y servicios asociados en una transacción atómica. |
| **7** | **Logout Seguro por POST con CSRF** | **Ejecutado** | `GET /logout` vs `POST /logout` | `GET`: `302` (sesión preservada)<br>`POST`: `302` $\rightarrow$ `/` (sesión destruida) | Petición posterior a `/cita` rechazada con `302` hacia `/` al destruirse la sesión. |

### B. Comprobación Funcional en Navegador con JavaScript (UI Cliente — Estado: `Pendiente`)
La interacción visual en navegador web que involucra la ejecución de scripts cliente en JavaScript ([src/js/app.js](file:///src/js/app.js)) queda explícitamente marcada como **`Pendiente`** de ejecución manual por el revisor o mediante harness automatizado de navegador (e.g. Playwright / Puppeteer):
- Navegación interactiva por pasos/pestañas de reserva (Paso 1: Servicios, Paso 2: Información de Cita, Paso 3: Resumen).
- Invocación de `fetch` en el cliente para obtener `/api/servicios` y renderizar tarjetas de servicios en el DOM con selector de clase `.seleccionado`.
- Datepicker interactivo con deshabilitación de sábados/domingos y fechas pasadas en el cliente.
- Despliegue dinámico de alertas flotantes en el DOM sin recargar la página.

---

## 8. Scripts de Reproducción Automatizada desde Cero

Para reproducir la instalación, compilación de assets, migraciones, pruebas unitarias e integrales en PHPUnit (52 pruebas, 360 aserciones) y la suite funcional HTTP de 7 escenarios desde un entorno Docker completamente limpio, se disponen scripts dedicados por plataforma:

- **En entornos Windows (PowerShell) — Ejecutado y Validado Dinámicamente:**
  ```powershell
  .\scripts\reproducir_entrega1.ps1
  ```
  *Nota de auditoría:* Este script fue ejecutado, verificado dinámicamente y validado en el entorno de desarrollo Windows con Docker Desktop.

- **En entornos Linux / macOS (Bash) — Revisado Estáticamente:**
  ```bash
  chmod +x scripts/reproducir_entrega1.sh
  ./scripts/reproducir_entrega1.sh
  ```
  *Nota de auditoría:* Este script fue sometido a revisión estática de código para verificar paridad lógica y de comandos con la versión de PowerShell, quedando sujeto a validación dinámica en sistemas Unix/Linux.

---

### Verificación de Resiliencia, Aislamiento y Manejo de Errores de Archivo

El script [tests/verificar_resiliencia_scripts.ps1](file:///tests/verificar_resiliencia_scripts.ps1) ejecuta una batería integral de pruebas sobre los mecanismos de respaldo, restauración y aislamiento de recursos:

```powershell
.\tests\verificar_resiliencia_scripts.ps1
```

#### Escenarios Cubiertos por el Verificador:
1. **Fallo al crear respaldo inicial:** Comprueba que ante un error de copia de `includes/.env`, la ejecución se detiene de inmediato con salida no cero (`exit 1`), cancelando la preparación antes de alterar cualquier archivo y preservando el `.env` original intacto.
2. **Fallo controlado en preparación con script real:** Ejecuta `scripts/reproducir_entrega1.ps1 -SimularFalloPreparacion`, verificando que ante un fallo en la fase de bases de datos, el script aborta con código no cero, limpia sus recursos propios, restaura `includes/.env` byte a byte con SHA-256 idéntico y preserva contenedores y redes ajenas en ejecución.
3. **Configuración previa con credenciales distintas y recursos ajenos:** Demuestra que con un `includes/.env` previo apuntando a servidores ficticios/producción, la reproducción real se ejecuta en aislamiento absoluto sobre contenedores y bases propias (`_test`, `_func_test`), finaliza con código 0, restaura el `.env` previo byte a byte y preserva los recursos ajenos activos.
4. **Fallo en restauración y preservación estricta de respaldos:** Ejecuta `scripts/reproducir_entrega1.ps1 -SimularFalloPreparacion -SimularFalloRestauracion`, validando que si la restauración de `.env` falla, el script devuelve salida no cero, **preserva intacto el nuevo archivo de respaldo `.env.bak.<RUN_ID>` en disco** para recuperación manual, respeta sin alterar ni eliminar ningún respaldo preexistente (`.env.bak.*`) y mantiene vivos los recursos ajenos.

#### Garantía de Restauración ante Errores Terminantes en Limpieza Docker:
El bloque `finally` de `tests/verificar_resiliencia_scripts.ps1` implementa una arquitectura de seguridad con `try/finally` interno:
- La función `Cleanup-HarnessResources` trata y aísla cada contenedor y red en bloques `try/catch` per-recurso (un fallo en un recurso no impide intentar limpiar los demás).
- Si la limpieza Docker arroja un error terminante (simulable mediante `-SimularFalloLimpiezaHarness` o `APPSALON_HARNESS_SIMULAR_FALLO=limpieza`), el bloque `finally` interno garantiza la restauración y comprobación SHA-256 de `includes/.env` de forma incondicional.
- Ante fallo en la limpieza, el recurso fallido se registra explícitamente (`[ERROR EN LIMPIEZA DOCKER]`), el código de salida devuelto es no cero (1) y los respaldos preexistentes `.env.bak.*` permanecen intactos en disco.


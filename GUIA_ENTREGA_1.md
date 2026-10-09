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

# 4. Ejecutar la suite completa de PHPUnit (49 pruebas, 322 aserciones)
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

El sistema de limitación de tasa implementa una regla atómica por ventana fija de 15 minutos (900 segundos):

1. **Estado Inicial (Contadores Vacíos):**
   - Intentos erróneos 1 al 5: Admitidos por el rate limiter. Retornan HTTP 200 con alerta `"El Password es Incorrecto o tu cuenta no ha sido confirmada"`.
2. **Sexto Intento Erróneo:**
   - Rechazado inmediatamente con **HTTP 429 Too Many Requests**.
   - Mensaje al usuario: `"Demasiados intentos fallidos. Por seguridad, intente de nuevo en 15 minutos."`
   - Encabezado HTTP: `Retry-After: 900`.
   - Se fija `bloqueado_hasta = NOW() + 15 min` en la tabla `intentos_login`.
3. **Consideración para Pruebas Manuales:**
   - Tras recibir el bloqueo en el sexto intento, la cuenta permanecerá bloqueada durante 15 minutos.
   - Si se requiere probar el inicio de sesión exitoso o la recuperación con esa misma cuenta inmediatamente después, debe limpiarse el contador en la base de datos:
     ```sql
     DELETE FROM intentos_login WHERE identificador = 'tu_correo@ejemplo.com';
     ```

---

## 7. Evidencia de Comprobación Funcional HTTP (6 Escenarios)

La verificación funcional se ejecutó de extremo a extremo contra el servidor HTTP real en `http://appsalon-web:3000` y la base de datos aislada `appsalon_func_test`, utilizando cookies de sesión nativas y un buzón SMTP de pruebas para la captura segura de tokens.

| # | Escenario Funcional | Estado | Petición HTTP / Método | Código y Redirección | Evidencia en Base de Datos / Buzón |
|---|---|:---:|---|---|---|
| **1** | **Registro de Cuenta** | **Ejecutado** | `POST /crear-cuenta` | HTTP `302` $\rightarrow$ `/mensaje` | Usuario creado con `confirmado=0`, `admin=0`, `token=NULL` (sin texto plano), `token_hash` presente. Correo recibido en buzón y token extraído. |
| **2** | **Confirmación de Cuenta** | **Ejecutado** | `GET /confirmar-cuenta?token={tok}` | HTTP `200` (Mensaje: "Cuenta confirmada correctamente") | `confirmado=1`, `token_hash=NULL`, `token_tipo=NULL`. Reintento con el mismo token rechazado como inválido. |
| **3** | **Login y Rate Limiting** | **Ejecutado** | `POST /` (5 erróneos + 1 bloqueo + 1 válido) | Intentos 1-5: `200`<br>Intento 6: `429`<br>Login válido: `302` $\rightarrow$ `/cita` | `intentos_login` registra `intentos=5`, `bloqueado_hasta` futuro. Login exitoso genera cookie de sesión y permite acceso a `/cita` (`200 OK`). |
| **4** | **Recuperación de Contraseña** | **Ejecutado** | `POST /olvide`<br>`POST /recuperar?token={tok}` | Solicitud: `200`<br>Consumo: `302` $\rightarrow$ `/` | Buzón recibe correo de recuperación. Token consumido de forma atómica (`token_hash=NULL`). Login con clave anterior rechazado (`200`); login con clave nueva exitoso (`302`). |
| **5** | **Reserva de Cita (API)** | **Ejecutado** | `POST /api/citas` (con sesión de cliente y CSRF) | HTTP `200` JSON (`resultado.resultado: true`) | Cita registrada en tabla `citas` con `usuarioId` forzado de sesión, fecha futura válida y hora `10:30:00`. Tabla `citasservicios` registra los 2 servicios seleccionados. |
| **6** | **Eliminación Autorizada y Anti-IDOR** | **Ejecutado** | `POST /api/eliminar` (IDOR vs Propietario) | Intento IDOR: `403 Forbidden`<br>Eliminación dueño: `302` $\rightarrow$ `/cita` | Intento de eliminación por un segundo cliente rechazado con 403 (la cita se mantiene intacta). Eliminación por su dueño borra la cita y servicios asociados en una transacción atómica. |
| **7** | **Logout Seguro por POST con CSRF** | **Ejecutado** | `GET /logout` vs `POST /logout` | `GET`: `302` (sesión preservada)<br>`POST`: `302` $\rightarrow$ `/` (sesión destruida) | Petición posterior a `/cita` rechazada con `302` hacia `/` al no existir sesión activa. |

> **Script de Reproducción Automatizada:**
> Los 7 escenarios pueden reproducirse en cualquier momento ejecutando:
> ```bash
> docker run --rm --network appsalon-net -v "${PWD}:/app" -w /app appsalon-php-test php tests/functional_test_suite.php
> ```

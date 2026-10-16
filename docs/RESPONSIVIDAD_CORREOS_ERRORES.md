# Responsividad, correos y páginas de error

Base: `feature/plataforma-integral`, commit `c87863a3d72a140e295603c5eb079c02a0057f39`.
Rama publicada: `feature/responsividad-correos-errores`.

## Cambios

- El sidebar agrupa los enlaces por función y permisos. En escritorio conserva la altura de la pantalla y permite desplazar todos los enlaces; también funciona con nombres comerciales largos y pantallas de poca altura. En móvil/tableta utiliza un menú accesible con teclado, Escape, retorno del foco y navegación disponible sin JavaScript.
- Las vistas de administración, agenda, reservas, servicios, profesionales, equipo, reportes, caja, inventario, crecimiento, bienestar, perfil y autenticación reorganizan su contenido. Las tablas tienen scroll local, etiquetas accesibles y columnas legibles para fechas, importes y SKU. Los modales permiten llegar a sus últimas acciones.
- Confirmación de cuenta, recuperación, invitación de colaboradores, confirmación de cita, cancelación y recordatorio utilizan una plantilla común responsive con resumen, acción principal, enlace alternativo, preheader y versión de texto plano. No dependen de imágenes externas ni JavaScript.
- Las páginas 400, 401, 403, 404, 405, 409, 410, 419, 422, 429, 500 y 503 comparten diseño, instrucciones y acciones para continuar. También se diseñaron los estados de cuenta, enlaces vencidos y resultados de pagos.
- Las rutas inexistentes conservan HTTP 404; los métodos no admitidos devuelven 405 y `Allow`. Las respuestas API conservan JSON y los fallos no exponen detalles internos. `HEAD` comprueba la confirmación sin consumir el token. Los errores del arranque funcionan sin consultar la base de datos.

## Información real del negocio

En `/admin/ajustes`, completar el nombre comercial, contacto de recepción y los campos opcionales correo de contacto, teléfono y dirección. Los correos utilizan únicamente esos datos configurados. Los campos vacíos no se inventan ni se muestran como información real. Estos ajustes utilizan `negocio_ajustes`, ya existente; no requieren una migración nueva.

Se conserva la configuración de transporte SMTP existente. La validación no envió correos a personas ni efectuó cobros reales. No se añadió un envío nuevo de reprogramación: ese flujo no existe en esta versión.

## Validación ejecutada

| Comprobación | Resultado |
| --- | --- |
| PHPUnit, orden aleatorio, semilla 1791863465 | 323 pruebas, 3617 aserciones; sin fallos ni advertencias |
| Compilación de assets | `npm run build` correcto |
| Sintaxis PHP | 165 archivos, cero errores |
| Errores del cliente JS | 3 escenarios: red, HTTP 500 y JSON incompatible |
| Vistas con backend activo | 489 verificaciones; cero desbordes y errores JS |
| Navegación | 7 tamaños, roles, teclado, Escape, cambios de breakpoint y ausencia de JavaScript |
| Páginas de error | 72 renders: 12 estados en 6 anchos |
| Correos | 28 renders: 6 tipos y datos largos en 4 anchos |
| Transporte HTTP | 404, 405/Allow, HEAD, tokens 410, JSON, CSRF 403 y API 401 |

Entorno local aislado: PHP 8.3.6, MariaDB 10.11.14, servidor PHP y Chrome Headless Shell 155.0.8059.39. Se aplicaron las migraciones 001–015 sólo en bases efímeras de pruebas. Las comprobaciones de perfil ahora poseen su conexión y no dependen del orden de otros módulos.

Las pruebas visuales incluyen 320, 360, 390, 768, 1024 y 1440 px, paisaje, texto al 200 %, contenido largo, pasos de reserva, teclado y diálogos. Se revisaron capturas del sidebar, administración, tablas, correos y 404. El runner Docker/Apache de CI incorpora las nuevas pruebas, y su ejecución en PHP 8.2/MySQL 8 terminó correctamente en GitHub Actions.

## Aplicar el paquete descargable

El paquete contiene un parche y los archivos modificados, no el repositorio completo. Antes de aplicarlo, revisar `git status` y guardar cualquier edición propia. No sobrescribir `includes/.env` ni restaurar el esquema base sobre una instalación existente.

En una copia situada en el commit base indicado, desde la raíz del proyecto:

```bash
git apply --check /ruta/al/paquete/AppSalon_responsividad_correos_errores.patch
git apply /ruta/al/paquete/AppSalon_responsividad_correos_errores.patch
npm ci
npm run build
docker compose --env-file includes/.env -p appsalon-validacion up -d --build web
```

En Git Bash de Windows, utilizar `npm.cmd` cuando el entorno lo requiera. Si `git apply --check` falla, revisar los cambios locales y la versión de partida; no forzar la aplicación ni borrar archivos propios. Los nuevos archivos PHP usan el autoload PSR-4 existente.

La rama está publicada en GitHub. La validación de CI comprueba PHP 8.2, MySQL 8, Apache, SMTP simulado, navegador y respaldo/restauración.

## CI aprobado

Código validado: `af470d2ee9a95704fce87000f70e4212a4186214`.
Ejecución: https://github.com/Johnsky-Games/AppSalon_PHP_MVC_JS_SASS/actions/runs/38349231242

PHP 8.2 + MySQL 8 + Apache: 323 pruebas y 3617 aserciones (semilla 1791867695), recorridos de negocio y SMTP simulado, 474 verificaciones responsive, 72 renders de errores, 28 de correos y navegación en 7 tamaños con 4 roles. Respaldo de 30 tablas y restauración con validación SHA-256 aprobados. Las 489 comprobaciones locales incluyen además una vista de pago válido con una fixture opcional.

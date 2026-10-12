# Plataforma integral: instalación y alcance

Rama: `feature/plataforma-integral`. Esta entrega conserva cuentas, citas y servicios existentes mediante migraciones incrementales 010–015.

## Funciones disponibles

- Propietario, gerente, recepción, profesional, finanzas y cliente: permisos comprobados contra la base y sesiones revocables. Invitación de colaboradores por correo y vínculo con profesional.
- Perfil, consentimiento comercial, recordatorios y fotografía. Fotos de clientes privadas; foto pública de profesional sólo con consentimiento.
- Agenda por profesional, estados de atención, historial completo y reprogramación transaccional conservando precio contratado y cobros.
- Caja, cobros manuales verificados, devolución total, inventario con stock exacto y movimientos idempotentes.
- Comisiones por profesional con porcentaje histórico, liquidaciones declaradas y ajustes ante devoluciones posteriores.
- Espera, fidelidad por citas completadas, canjes y entrega trazable, formularios privados, segmentación con consentimiento y ajustes de identidad del negocio.
- Paneles con datos reales, tablas adaptables, modales nativos, avisos Toastify y confirmaciones SweetAlert2. Movimiento reducido y navegación por teclado.
- Worker periódico opcional para la outbox de notificaciones. Rechaza recordatorios desactivados y horarios anteriores tras reprogramación.

## Actualizar la instalación de Windows

Antes de cambiar de rama, revisar `git status` y preservar cualquier edición propia. No borrar volúmenes ni importar de nuevo el esquema sobre una base existente.

```bash
git fetch origin
# Crear esta rama local sólo si todavía no existe:
git switch -c plataforma-integral --track origin/feature/plataforma-integral
npm.cmd ci
npm.cmd run build
docker compose --env-file includes/.env -p appsalon-validacion up -d --build
```

Regenerar el autoload mediante la imagen oficial de Composer (no viene dentro de la imagen PHP):

```bash
MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd):/app" -w /app composer:2 composer install --no-interaction
docker compose --env-file includes/.env -p appsalon-validacion exec -T web php database/migrador.php up
docker compose --env-file includes/.env -p appsalon-validacion exec -T web php database/migrador.php status
```

URL: la indicada por `APP_URL`, habitualmente http://localhost:8085. Mantener el `DB_PASS` actual: cambiar `.env` no cambia la contraseña de un volumen MySQL existente. La plantilla `includes/.env.example` es para instalaciones nuevas; no sustituye credenciales existentes.

Los administradores legados se reconocen como propietarios. No existe contraseña predeterminada. Para crear el primer propietario cuando no haya ninguno, el CLI exige una contraseña propia por variable `APPSALON_OWNER_PASSWORD` y admite `--email`, `--nombre` y `--apellido`. No publicar esa variable ni la salida sensible. Las cuentas del equipo se gestionan en `/admin/equipo`.

## Notificaciones continuas

Con SMTP configurado y las migraciones aplicadas:

```bash
docker compose --env-file includes/.env -p appsalon-validacion --profile worker up -d notificaciones
```

No activar el worker sin revisar la configuración de correo. Las notificaciones no garantizan entrega al destinatario: se registran intentos, reintentos y fallos. Un envío SMTP ya iniciado no puede retirarse después de reprogramar.

## Alcance comercial y técnico

Los cobros manuales representan recepción declarada por el personal. Las liquidaciones registran un abono declarado; no ejecutan transferencias bancarias ni generan nómina o facturación fiscal. La pasarela `mock` sigue siendo una simulación. Integrar cobros externos requiere elegir proveedor, cuenta comercial, credenciales, moneda y verificación en su entorno de prueba.

No se habilitan automáticamente campañas masivas, WhatsApp, SMS, multisucursal, nómina, impuestos o expedientes clínicos. La espera no asigna reservas automáticamente. Los ajustes de identidad del negocio no sustituyen los horarios definidos por profesional.

Docker Compose conserva un entorno local de desarrollo con montaje de código y acceso administrativo a MySQL. Para exposición pública hacen falta despliegue con TLS, usuario DB de privilegios limitados, secretos administrados, almacenamiento persistente privado, respaldo externo y política de retención. No confundir esta validación con una certificación de producción.

`migrador.php down` revierte **todas** las migraciones aplicadas; no utilizarlo para regresar una única versión ni contra una base con datos que se necesiten conservar.

## Evidencia

La compilación nativa ya no depende de comandos `.bin` de Gulp: funciona mediante Node y evita el problema de rutas Windows con `&`. Auditoría NPM del lockfile actualizado: cero vulnerabilidades reportadas. Suite preliminar PHP8.3/MariaDB: 263 pruebas, 3241 aserciones en orden aleatorio. La evidencia definitiva de PHP8.2/MySQL8 y navegador se adjunta al commit validado en GitHub Actions.

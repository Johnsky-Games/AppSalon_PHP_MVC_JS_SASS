# Interfaz moderna de App Salón

La rama `feature/interfaz-moderna` parte del cierre técnico `50a015a` y conserva las rutas, formularios, permisos, CSRF y reglas de negocio existentes.

## Experiencia

- Acceso con fotografía editorial existente y formularios con control de visibilidad de contraseña.
- Escritorio con navegación lateral; navegación visible y flexible en móvil, sin necesitar JavaScript para abrirla.
- Paleta clara crema, verde petróleo, navy y coral; botones, tarjetas, estados y foco de teclado consistentes.
- Reserva en tres pasos, selección accesible de servicios, subtotal estimado y duración, vista del profesional elegido y horarios disponibles.
- Agenda administrativa con métricas calculadas de las citas mostradas; importe de servicios separado de cobros.
- Catálogo y equipo con búsqueda local y estados vacíos.
- Gráficas de importes, reservas y ocupación basadas en el reporte filtrado; cifras y tablas siguen disponibles. El neto negativo conserva su signo. La vista gráfica puede ocultarse/restaurarse.
- Avatares SVG ilustrados de cliente y profesional. Son imágenes genéricas de apoyo; no se implementó almacenamiento ni carga de fotografías personales.
- SweetAlert2 y Toastify se distribuyen localmente con versiones fijadas en el lockfile y licencias incluidas. Las notificaciones usan texto literal y una región accesible; las alertas de formulario permanecen visibles.
- Transiciones breves, hover y estados de selección; `prefers-reduced-motion` reduce animaciones y desplazamiento.

## Actualizar la copia local

Desde Git Bash en la carpeta principal del proyecto:

```bash
git fetch origin
git switch -c validar-interfaz --track origin/feature/interfaz-moderna
npm.cmd ci
npm.cmd run build
docker start appsalon-db appsalon-web
```

Si `validar-interfaz` ya existe, use `git switch validar-interfaz` y `git pull --ff-only`. Conserve los cambios locales antes de cambiar de rama. La actualización visual no añade migraciones; la base debe tener aplicadas las migraciones existentes 001 a 009.

Con el contenedor apuntando a esta carpeta, abra la URL local que ya utiliza, normalmente `http://localhost:3000`, y recargue con Ctrl+F5. Mantenga su `includes/.env`, credenciales y datos. Si faltan clases PHP y no tiene Composer instalado en Windows, regenere el autoload desde la carpeta del proyecto:

```bash
MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W):/app" -w /app composer:2 composer install --no-interaction
docker restart appsalon-web
```

## Validación

CI ejecuta build limpio, linter PHP, nueve migraciones, E2E sobre Apache/MySQL/SMTP mock, PHPUnit en orden aleatorio y backup/restore. El E2E conserva las comprobaciones de negocio y añade:

- UI-01: buscador con estado vacío y restauración del catálogo, control para mostrar/ocultar gráficas y geometría real sin overflow del documento en login, servicios, reserva y reportes a 320/390/768/1440px; 16 capturas PNG y avatar cargado.
- UI-02: mostrar/ocultar contraseña sin enviar, salto al contenido mediante Tab/Enter, movimiento reducido y notificación de texto seguro anunciada.

Además, `node tests/ui_client_failure_test.js` comprueba tres fallos de carga del catálogo (red, HTTP 500 y JSON incompatible), con finalización del estado de carga y mensaje para reintentar.

Las capturas quedan en el artefacto `apache-browser-e2e`, carpeta `screens`. Estas verificaciones cubren las pantallas y tamaños indicados; no equivalen a certificar todos los dispositivos o todas las tecnologías de asistencia.

Código validado `740d41ff73ed42a2717fd5540b8f544fbe45c015`: [CI #38251198100 aprobada](https://github.com/Johnsky-Games/AppSalon_PHP_MVC_JS_SASS/actions/runs/38251198100), 218 pruebas / 2727 aserciones, 21 comprobaciones E2E y 3 escenarios de fallo de catálogo. El cierre está registrado en PROGRESS.md. Los pagos continúan siendo simulados hasta implementar un proveedor comercial.

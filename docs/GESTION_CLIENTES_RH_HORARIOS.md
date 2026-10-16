# Clientes, Recursos Humanos y configuración semanal

## Comportamiento entregado

| Vista | Dirección | Uso |
|---|---|---|
| Base de clientes | `/admin/clientes` | Búsqueda, tabla compacta y ficha. Sin tarjetas de indicadores ni gráficos. |
| Indicadores de clientes | `/admin/clientes/indicadores` | Indicadores, segmentos y gráficos. Los cuatro indicadores principales quedan visibles; los complementarios y sus definiciones se despliegan. No carga ni muestra la tabla nominal. |
| Recursos Humanos | `/admin/recursos-humanos` | Directorio paginado de colaboradores de todos los roles y estados; búsqueda por nombre, código, correo o puesto. |
| Ficha laboral | `/admin/recursos-humanos/ficha?id=N` | Contacto, puesto, ingreso, notas internas y configuración de servicios/agenda cuando corresponde. |
| Accesos e invitaciones | `/admin/equipo` | Invitaciones, roles, suspensión y baja. Se conserva el flujo existente. |
| Horarios | `/profesionales/horarios?id=N` | Aplicar una jornada o pausa a varios días y conservar los ajustes individuales. |

## Cuenta administradora y clientes

El CRM anterior incluía una cuenta laboral si tenía una membresía en `clientes` **o cualquier cita**. Por eso una cuenta administradora podía aparecer si conservaba esa relación histórica. El código por sí solo no determina cuál de esas relaciones existe en la computadora del usuario.

La base y los indicadores abren ahora con `vinculo=externo`: solo personas cuyo rol efectivo es cliente, incluida la compatibilidad con cuentas antiguas. El propietario y los demás roles laborales quedan fuera de ese conjunto aunque conserven una membresía histórica. El filtro explícito ofrece además:

- `equipo`: personas con rol laboral que también tienen membresía de cliente o citas;
- `todos`: ambos conjuntos.

El filtro se valida en servidor, se conserva al cambiar de vista, ordenar, paginar y descargar, se registra en la auditoría de exportaciones y también delimita la población RFM. Una cuenta exclusivamente laboral sin membresía ni citas sigue excluida incluso con `todos`. No se borran cuentas, membresías, citas ni pagos. La autorización de los dos portales conserva sus reglas; este cambio organiza la consulta del CRM.

Los profesionales mantienen su vista restringida a personas que realmente atendieron. Finanzas conserva solo agregados, sin listas nominales, fichas ni exportaciones.

## Recursos Humanos

El directorio y la ficha exigen `rh.gestionar` en el servidor, como las fichas laborales anteriores. Actualmente ese permiso corresponde al propietario. Gerencia, recepción, profesionales, finanzas y clientes no obtienen permisos nuevos. La tabla del directorio no carga notas de RR. HH.; estas se consultan en la ficha autorizada. El directorio pagina de 25 en 25 sin el límite de 500 del panel anterior de accesos.

Las rutas anteriores de fichas laborales siguen funcionando. Los enlaces del directorio nuevo y los retornos después de guardar permanecen en Recursos Humanos.

## Jornadas y descansos por grupos de días

Se puede seleccionar lunes–viernes, martes–sábado, toda la semana o marcar días individuales. Ejemplos: jornada 09:00–18:00 y pausa 14:00–15:00.

- En **Horarios**, «Guardar jornada» reemplaza las franjas de los días elegidos; conserva los demás días y los descansos existentes compatibles. «Guardar descanso» añade esa pausa en los días elegidos; repetir exactamente la misma pausa no la duplica.
- En la **invitación y ficha laboral**, «Preparar jornada/descanso» completa los días en el formulario. La vista anuncia los días e intervalo elegidos; el botón final guarda la configuración. «Revisar y ajustar día por día» mantiene las excepciones, las jornadas partidas y los descansos individuales.
- La captura individual y los bloqueos por fecha siguen disponibles. Los formularios de grupo de Horarios funcionan sin JavaScript marcando directamente los días.

El servidor valida días 1–7, intervalos, pasos de cinco minutos y las reglas existentes de jornadas/pausas. Una pausa debe quedar dentro de una franja y no ocuparla completa. La operación bloquea al profesional antes de leer su configuración, valida todos los días y guarda el conjunto dentro de una transacción. Un solapamiento con una cita futura o un fallo al guardar un solo día revierte el grupo completo. Las asignaciones de servicios se conservan. Los endpoints exigen permiso y CSRF.

## Actualización y validación

No se modifica el esquema: no hay migraciones adicionales a la 027. No se ejecutan cambios sobre la instalación ni los datos del usuario desde esta entrega.

Pruebas relevantes:

- `tests/Integration/GestionClientesRhHorariosIntegrationTest.php`: administrador con membresía histórica, filtros y archivo coherentes, vistas separadas, permisos, directorio de más de 500 colaboradores, preservación de días, duplicados, citas futuras, rollback por fallo y CSRF.
- `tests/Qa/GestionPersonasNavegadorTest.php` y su recorrido CJS: interacción real desde RH y Horarios, guardado sin JavaScript, consola y seis pantallas a 320, 390 y 1366 píxeles. En CI el navegador es obligatorio.
- Las comprobaciones anteriores de doble perfil se ejecutan con `vinculo=todos`; las del directorio operativo verifican que no incluye el panel de indicadores. La comprobación del recorrido indicador → listado → descarga comienza en la nueva ruta de indicadores.

Validación local con PHP 8.3.6 y MySQL 8.0.46, sobre bases sintéticas aisladas:

| Comprobación | Resultado |
|---|---|
| Suite completa de pruebas unitarias e integración, orden aleatorio y semilla `1792160001` (revisión `0a51feb`) | 1.137 pruebas y 17.012 aserciones, sin fallos. |
| QA HTTP de rutas nuevas, inventario de rutas, CSRF, menús por rol, separación de portales y recorrido indicador → listado → descarga (revisión `fb4c8ed`) | 38 pruebas y 3.338 aserciones, sin fallos. |
| `npm run build` | Compilación correcta. |
| `node tests/ui_client_failure_test.js` | Tres escenarios aprobados. |
| Sintaxis PHP/JavaScript y `git diff --check` | Sin errores en los archivos modificados. |

La restricción de sockets del entorno local impide arrancar Chromium; esa ejecución no cuenta como una validación de navegador. El recorrido se exige sin omisiones en CI, junto con el QA general y la validación en PHP 8.2. Esas comprobaciones siguen pendientes.

Seguimiento de la entrega: [PR #2](https://github.com/Johnsky-Games/AppSalon_PHP_MVC_JS_SASS/pull/2), rama `feature/clientes-rh-horarios`. Los resultados de CI y la revisión visual se registran en la descripción del PR.

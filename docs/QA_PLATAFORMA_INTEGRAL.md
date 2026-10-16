# QA y registro de riesgos de la plataforma integral

Fecha: 2026-10-12. Estado: validación integrada aprobada para el código indicado, con límites comerciales documentados.

## Entorno y evidencia

Se preparó un runtime local PHP8.3.6/MariaDB10.11 para pruebas preliminares. Docker y Chrome no están disponibles localmente. La validación definitiva PHP 8.2/MySQL 8/Apache y Chrome se ejecutó y aprobó en [GitHub Actions](https://github.com/Johnsky-Games/AppSalon_PHP_MVC_JS_SASS/actions/runs/38318865612). Los resultados históricos de 218 pruebas pertenecen a la base anterior y no certifican los módulos nuevos.

Se ampliaron pruebas existentes para comparar el conjunto completo de tablas entre instalación fresca y actualización; validar historial exacto de migraciones; contrastar columnas, índices, motores y reglas FK; y verificar que manifiesto y restauración incluyan todas las tablas, manteniendo los controles históricos de datos.

## Matriz de verificación

| Área | Contraprueba obligatoria | Evidencia requerida |
|---|---|---|
| Autorización | Cliente llama ruta admin; recepción intenta roles; profesional usa ID ajeno | HTTP403 y ausencia de escritura/divulgación |
| Revocación | Cambiar rol/activo con sesión previa abierta | Siguiente petición sensible rechazada |
| Propietario | Dos degradaciones simultáneas de propietarios | Al menos un propietario activo permanece |
| Registro | Email inválido, exceso longitudes, bcrypt límite y ráfaga | Validación útil; sin alta/correo excesivo |
| Foto | SVG, MIME falso, bytes/píxeles excesivos, ruta maliciosa, usuario ajeno | Rechazo antes de publicación; decode/reencode; privada por defecto |
| Agenda | Cancelación exacto inicio; estado inválido; dos reservas/reprogramaciones mismo hueco | Política única; una ocupación; rollback conserva original |
| Cobros | Cita cancelada inicia pago; idempotencia repetida; callback tardío | Sin gateway nuevo; trazabilidad y conciliación |
| Inventario | Salida supera stock; salidas simultáneas; misma clave distinto contenido | Saldo no negativo, conflicto y movimiento único |
| Caja | Registrar efectivo; repetir; filtrar período; usuario no autorizado | Balance reconciliable y auditoría |
| Consentimiento | Opt-in seguido opt-out desde perfil | Fuente única; marketing excluye inmediatamente |
| Formularios | Pregunta inexistente, respuesta fuera rango, HTML, ID ajeno | Validación servidor y protección de respuestas |
| Fidelidad | Repetir devengo; cita cancelada; saldo insuficiente | Libro trazable; sin duplicado ni saldo negativo |
| Migraciones | Base antigua poblada frente a fresh | Datos preservados; mismo esquema completo |
| Respaldo | Tablas nuevas y caracteres especiales; error de restauración | Round-trip íntegro; destino original conservado ante fallo |
| UI | 320/375/768/1280px, teclado, motion reducido y error red | Sin overflow; foco visible; mensajes seguros |

## Riesgos de integración identificados

1. Consentimiento duplicado entre perfiles y marketing: debe existir una única fuente de verdad o sincronización atómica. Dos tablas independientes permiten campañas tras retirada.
2. Estados nuevos de cita: toda consulta de ocupación debe incluir estados que bloquean agenda. Mantener únicamente `estado='confirmada'` permite solapamientos tras check-in.
3. Roles cacheados: migrar `isAdmin` sin adaptar controles por recurso puede conceder acceso excesivo o impedir funciones de empleados autorizados.
4. Tests con mocks anteriores pueden omitir estado de cita o versión de sesión. Ajustar fixtures con estados explícitos; no omitir controles de producción para satisfacerlos.
5. El nombre `down` no implica reversión de la última migración: el migrador actual revierte todas; no usar en la instalación validada.
6. Un volumen nuevo no hereda cuentas/servicios de otro proyecto Compose; una contraseña nueva en `.env` no cambia la contraseña de un volumen MySQL ya inicializado.

## Puerta de entrega

Sólo marcar lista cuando: código revisado y SHA fijado; migraciones y respaldo/restauración pasan; PHPUnit y Node pasan; E2E real pasa; controles negativos por rol y recurso pasan; dependencias revisadas; limitaciones comerciales documentadas. Cada módulo sin evidencia mantiene estado pendiente. No usar un porcentaje global para ocultar integraciones ausentes.

## Evidencia preliminar ejecutada

- `MigrationTest`: 8 pruebas, 412 aserciones, código 0 (PHP8.3/MariaDB10.11), incluyendo migraciones001..015.
- Filtro `ReportesFinancierosIntegrationTest|Fase6BReportesIntegrationTest|BackupRestoreIntegrationTest|BackupRestoreIntegridadIntegrationTest`: 22 pruebas, 370 aserciones, código 0 (PHP8.3/MariaDB10.11).
- `SeguridadFrontendIntegrationTest`: 5 pruebas, 229 aserciones, código 0 en el mismo runtime.
- `node --check tests/platform_e2e_checks.js` y `node --check tests/browser_e2e_test.js`: pasan.
- `bash -n tests/run_browser_e2e.sh`: pasa.
- E2E añadido: módulos administrativos en cuatro anchos, modales con persistenciaSQL, uploadHTTP reencodificado, privacidad/IDOR, matriz de recepción y revocación. Los recorridos se ejecutaron y aprobaron en la validación definitiva.

## Resultado definitivo

Código `b1605d216940b76bd2c779f637d5084f78614450`: [CI aprobado](https://github.com/Johnsky-Games/AppSalon_PHP_MVC_JS_SASS/actions/runs/38318865612). 283 pruebas PHP / 3371 aserciones, 27 comprobaciones Chrome, 3 escenarios Node y respaldo/restauración de30 tablas. Se validaron también el menú móvil y los formularios con CSRF, incluyendo contenido literal escapado y acceso privado.

Los riesgos de consentimiento duplicado, estados de ocupación, privilegios cacheados y fixtures ficticios se corrigieron. Las APIs sensibles revalidan sesión/rol/version y las fotos privadas no se divulgan a otros clientes. No se afirma una prueba de deadlock financiero inducido entre dos procesos: el retry se probó mediante rollback real y errores controlados; el último propietario sí tiene prueba concurrente entre dos procesos.

El saldo de movimientos no es un arqueo físico; pagos externos, facturación fiscal, nómina y despliegue público siguen fuera del alcance operativo de esta entrega.

# Guía: actualizar a la ronda 5 (agenda → atención → cobro → comprobante) — Windows, Docker y Linux

Complementa `GUIA_ACTUALIZACION_RONDA3.md` y `IDENTIFICAR_ENTORNO_LOCAL.md`. Tras la integración del [PR #1](https://github.com/Johnsky-Games/AppSalon_PHP_MVC_JS_SASS/pull/1), la rama de actualización es `main`; ver [INTEGRACION_MAIN_R5B.md](INTEGRACION_MAIN_R5B.md) para el alcance acumulado y las migraciones desde versiones anteriores. **Esta guía no se ha ejecutado en tu equipo**: se probó el procedimiento de migración, respaldo y restauración en la nube, no en tu instalación. Haz primero `IDENTIFICAR_ENTORNO_LOCAL.md` (qué entorno atiende `localhost:8085`, rama, SHA, cambios locales, respaldo).

No se borran volúmenes, no se reemplaza `includes/.env`, no se envían correos reales. Convención: `COMPOSE` = `docker compose --env-file includes/.env -p <tu-proyecto>`.

## 1. Qué cambia

| Tema | Antes | Ahora |
|---|---|---|
| «Iniciar atención» (fallo A) | Se ofrecía en toda cita confirmada; en una futura el servidor respondía 422 con una página sin diseño | El botón se habilita desde 60 min antes de la hora; antes, aparece deshabilitado con el motivo; los errores salen en el layout del equipo |
| Volver / mezcla de portales (fallo B) | El error enlazaba a `/mis-citas` (pantalla del cliente) | Destinos centralizados por portal; el equipo nunca llega a pantallas del cliente y viceversa |
| Cobro (fallo C) | Había que teclear el ID de la cita en Finanzas | Desde la agenda: «Finalizar y cobrar», «Cobrar saldo», «Ver pagos», «Ver comprobante»; caja en `/caja` |
| Clientes (fallos D/E) | Sin filtros de estado/consentimiento; «Listado» sin lista | Filtros combinables con chips y «Limpiar filtros»; «Listado» abre la lista real con descarga |
| Exportaciones (fallo F) | Solo CSV | CSV **y** Excel (XLSX real) en CRM, caja y reportes, auditadas |

Migraciones nuevas: **027** regularización de la atención (columnas `citas.atencion_efectiva_at/regularizada_por/regularizada_at` y tabla `citas_regularizaciones`; reversión segura que se niega si hay regularizaciones), **023** atención de citas (quién y cuándo inició/finalizó), **024** cobros y verificación, **025** comprobantes internos (correlativos), **026** auditoría general de exportaciones. Todas con reversión segura (se niegan si perderían datos de negocio).

## 2. Actualizar (con respaldo previo)

```bash
git fetch origin --prune
git switch main && git pull --ff-only origin main                 # resolver los cambios locales antes de continuar
composer install --prefer-dist --no-interaction                   # dependencias y autoload; alternativa Docker en guía de identidades §8
$COMPOSE build web
$COMPOSE exec web php scripts/backup_db.php --gzip --output=storage/respaldos/antes-ronda5.sql.gz
$COMPOSE run --rm instalador php scripts/instalar.php --migrar       # aplica todas las pendientes hasta 027 sobre la base existente
$COMPOSE up -d db web notificaciones
$COMPOSE exec web php database/migrador.php status                   # 027 aplicada
$COMPOSE exec web php scripts/instalar.php --comprobar               # «Esquema al día.»
```

`public/build/*` está versionado; no hace falta compilar el frontend para usar la aplicación.

## 3. Verificación manual (con datos de prueba, no con clientes reales)

1. Entra por `/equipo/login` como propietario. En **Agenda**, una cita de hoy ya comenzada muestra «Iniciar atención»; una de mañana lo muestra deshabilitado con «Disponible desde las HH:MM». Pulsa «Iniciar atención»: vuelves a la agenda con el mismo período y la cita queda «En servicio».
2. «Finalizar y cobrar»: llegas al cobro **de esa cita** (cliente, servicios, total, saldo). Cobra en efectivo con cambio; se genera el comprobante. Comprueba que dice «no es factura autorizada por el SRI».
3. Registra una transferencia: queda **por verificar**; no suma a caja. Con **otra** cuenta con permiso de finanzas, verifícala (la misma persona que la registró no puede, salvo el propietario).
4. Con una cita confirmada de un día anterior: «Iniciar atención» aparece deshabilitado con el motivo; en **Agenda → Revisar pendientes** (`/agenda/pendientes`) puedes reprogramarla o, como propietario/gerente, «Regularizar atención» en su detalle (motivo y fecha/hora efectiva no futura). Como profesional, solo puedes «Solicitar regularización» de tus citas. Comprueba en el historial de la cita quién regularizó y cuándo, y que la fecha de la cita no cambió.
5. Con la misma persona en el portal de clientes (`/cliente/login`), abre `/caja`: 403 con «Volver» a su portal. Con sesión de equipo, abre `/mis-citas`: te lleva al inicio del equipo con un aviso.
6. Clientes: filtra por membresía suspendida y por consentimiento; usa «Limpiar filtros»; abre un «Listado»; descarga CSV y XLSX y ábrelos en Excel.

## 4. Variables y requisitos

Sin variables nuevas. El XLSX se escribe sin `ext-zip`. Solo el CI y los QA instalan `openpyxl` y LibreOffice para **validar** los archivos; la aplicación no los necesita.

## 5. Revertir

Con respaldo (§2) y sin datos nuevos de cobros: `migrador.php down --hasta=023_atencion_citas --simular`. Los cobros, comprobantes y auditorías creados desde esta versión **no se pierden en silencio**: el migrador se niega y pide `--aceptar-perdida` y un respaldo reciente. Restaurar el respaldo en una base nueva es la vía preferente (`GUIA_ACTUALIZACION_RONDA2.md` §7).

## 6. Pruebas

`vendor/bin/phpunit` (suite por defecto) y `tests/Qa` (QA independiente, con Chromium, openpyxl y LibreOffice). Ver `docs/R5_QA_INDEPENDIENTE.md`.

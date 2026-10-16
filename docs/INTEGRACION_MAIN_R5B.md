# Integración en main — plataforma y cierre R5b

Fecha: 16 de octubre de 2026 (UTC). Registro de integración: [PR #1](https://github.com/Johnsky-Games/AppSalon_PHP_MVC_JS_SASS/pull/1). El PR conserva la punta exacta revisada, el SHA del merge, su fecha y las comprobaciones posteriores.

## 1. Alcance y trazabilidad

| Referencia | Revisión |
|---|---|
| Destino comprobado antes del merge | `main` en `bc1529bde644e1187be98332e3db912b80773cab` |
| Rama de origen | `claude/blissful-hopper-f5k7a0` |
| Código y pruebas validados | `39c4fc5921bbdb450ce1df12416fe134268547f6` |
| Cierre documental anterior a esta integración | `f6c97947765f90466c9ce29c42a4bb91cf6a1d74` |
| Método | Merge con dos padres; se conserva el historial de desarrollo |

`main` es antecesor de la rama: no hay cambios exclusivos del destino que reconciliar. La comparación previa a esta documentación comprende 236 commits y 563 archivos. Se integra el trabajo acumulado de plataforma, identidades y rondas 2–5b; no sólo el último ajuste de eliminación.

Incluye portales y permisos, activación segura de cuentas, correo en cola, agenda y regularización de atención, CRM, caja y comprobantes internos, inventario y comisiones, exportaciones CSV/XLSX y las pruebas correspondientes. La eliminación física de citas con evidencia financiera o de auditoría se rechaza con 409 dentro de la transacción; el orden de bloqueo es profesional → cita.

Los cambios posteriores a `39c4fc5` sólo afectan a `docs/`. Antes del merge se contrasta esa identidad de código, pruebas, dependencias, compilados y workflow; no se atribuye un CI nuevo a un SHA documental. El workflow del repositorio ejecuta comprobaciones y no contiene un despliegue.

## 2. Evidencia previa al merge

[CI run 79, éxito completo sobre `39c4fc5`](https://github.com/Johnsky-Games/AppSalon_PHP_MVC_JS_SASS/actions/runs/38840115357).

| Validación | Resultado |
|---|---|
| PHPUnit en CI, semilla `1792117420` | 1125 pruebas; éxito |
| QA en CI con Chromium y lectores XLSX independientes | 958 pruebas; 0 omitidas; éxito |
| Frontend, sintaxis PHP, migraciones e instalador | Éxito |
| Docker, E2E en Apache con SMTP simulado, respaldo/restauración SHA-256 | Éxito |
| PHPUnit local, semillas `1792111571` y `1792127001` | 1125 pruebas por ejecución; éxito |
| QA local | 958 pruebas; 36 omitidas por restricción del navegador local, cubiertas en CI |
| Mutaciones del cierre | 30 de 30 detectadas por fallos de aserción |
| Integridad en una base nueva migrada | 11 consultas a cero; 33 FK contrastadas |

Resultados detallados y límites: [entrega](ENTREGA_RONDA5.md), [QA](R5_QA_INDEPENDIENTE.md), [validación](evidencia_r5/validacion.json), [mutaciones](evidencia_r5/mutaciones.json) e [integridad](evidencia_r5/integridad.json). Las pruebas no se suman entre suites o repeticiones. El estado del CI de la revisión integrada se consulta en el PR y en [Actions de main](https://github.com/Johnsky-Games/AppSalon_PHP_MVC_JS_SASS/actions?query=branch%3Amain); no se presenta aquí como terminado antes de ejecutarse.

## 3. Actualizar una instalación después del merge

El merge cambia el repositorio. La instalación del usuario, sus contenedores y sus datos no se han actualizado ni validado en esta tarea.

La versión anterior de `main` sólo versionaba las migraciones 001–002. El destino integrado incluye todas las migraciones pendientes hasta **027**; no existe una migración 020. No basta aplicar únicamente las 023–027 si se parte de aquel `main`.

1. Identificar la instalación, rama, cambios locales y nombre real del proyecto Compose mediante [IDENTIFICAR_ENTORNO_LOCAL.md](IDENTIFICAR_ENTORNO_LOCAL.md).
2. Respaldar la base y conservar el manifiesto SHA-256 antes de migrar. Preservar `includes/.env`, `APP_KEY`, credenciales, archivos subidos y volúmenes.
3. Actualizar a `main` con avance rápido y regenerar dependencias/autoload. En Windows, la alternativa con la imagen oficial de Composer está en [la guía de identidades, §8](GUIA_ACTUALIZACION_IDENTIDADES.md).
4. Revisar los requisitos de las [rondas 1](GUIA_ACTUALIZACION_IDENTIDADES.md) y [2](GUIA_ACTUALIZACION_RONDA2.md), incluidos MySQL 8.0.13+, permisos de triggers, `APP_KEY` y worker. Aplicar **todas** las pendientes mediante `scripts/instalar.php --migrar`; comprobar el estado hasta 027 y `--comprobar`.
5. Seguir [GUIA_ACTUALIZACION_RONDA5.md](GUIA_ACTUALIZACION_RONDA5.md) para las comprobaciones funcionales con datos de prueba y revisar activación de cuentas, correo y portales según las guías previas.

La reversión de Git no revierte el esquema. Si hay datos nuevos, los guardianes pueden impedir un `down`; usar la simulación del migrador y el procedimiento documentado de restauración en una base nueva. El respaldo/restauración fue probado con datos de CI, no con la base del usuario.

## 4. Límites y decisiones pendientes

Se mantienen los límites de cobertura de [R5_REGULARIZACION.md §7.3](R5_REGULARIZACION.md): las carreras regularizar contra cobrar/rechazar no discriminan las mutaciones ensayadas y el escáner de arquitectura sólo reconoce SQL literal en PHP.

Siguen pendientes de negocio la política de anonimización de usuarios y la baja de servicios del catálogo. No hay integración fiscal electrónica ni conciliación bancaria automática. Estas decisiones no se dan por resueltas al integrar la rama.

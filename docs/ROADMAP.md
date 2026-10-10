# Hoja de Ruta (Roadmap) y Ciclo de Entregas — AppSalon

## 1. Metodología y Organización de Agentes

Cada fase del proyecto se ejecuta bajo la coordinación de tres roles con responsabilidades separadas:

- **Coordinador (`e9788218-5667-449d-8c01-3245505116ca`):** Mantiene el contexto global (`docs/CONTEXTO_PROYECTO.md`, `docs/ROADMAP.md`, `docs/AUDITORIA.md`, `PROGRESS.md`), controla el alcance de cada fase, coordina la comunicación directa entre Desarrollador y Auditor, y verifica que ninguna entrega avance sin aprobación formal de auditoría.
- **Desarrollador (`subagente desarrollador`):** Trabaja en `C:\Users\jonat\.gemini\antigravity\worktrees\AppSalon_PHP_MVC_JS_SASS\comprehensive_project_audit_roadmap`. Implementa funcionalidades, ejecuta pruebas unitarias, de integración, concurrencia y navegador en Docker aislado, corrige hallazgos y publica ramas `feature/*`.
- **Auditor (`subagente 1b62e9dd-6cdf-4566-bb28-b9c138feff52`):** Trabaja en un checkout separado (`C:\Users\jonat\.gemini\antigravity\worktrees\AppSalon_PHP_MVC_JS_SASS\auditing_fase_4b_reservas`). Inspecciona un SHA específico sin modificar el código del desarrollador, ejecuta las suites de pruebas en su propio entorno Docker aislado y emite veredicto (`APROBADO`, `CORRECCIONES NECESARIAS` o `BLOQUEADO`) en `docs/AUDITORIA.md`.

### Ciclo Obligatorio por Entrega
`implementar → probar → commit → auditoría del SHA → corregir → volver a probar y auditar → cerrar fase`

> **Regla de control de bloqueos:** Si tras tres rondas no se resuelve un mismo problema, se detiene el ciclo y se informa del bloqueo con evidencia técnica y una propuesta concreta.

---

## 2. Estado de Entregas Completadas y En Curso

| Fase | Rama | SHA de Referencia | Estado |
| :--- | :--- | :--- | :---: |
| **Entrega 1** | `feature/seguridad-y-consistencia-inicial` | `bc1529bde644e1187be98332e3db912b80773cab` | **Aprobada y en `main`** |
| **Fase 2A** | `feature/fase-2-servicios-repositorios` | `4a450301304be25a4829d89303ad52edfd40028f` | **Aprobada** |
| **Fase 2B** | `feature/fase-2b-citas-repositorios` | `b0295c1378be0d7bf816ffd32e090a8e9f114292` | **Aprobada** |
| **Fase 2C** | `feature/fase-2c-usuarios-autenticacion` | `fe16c67211d5583e859fd6ec4eb8ba55b7c03983` | **Aprobada** |
| **Fase 3A** | `feature/fase-3a-profesionales-horarios` | `8ad121ea1b02c6ff51e0eb3891207e50bb383218` | **Aprobada** |
| **Fase 4A** | `feature/fase-4a-disponibilidad` | `939d84dbb9dcec67bc83421eaa5cbdc90389034a` | **Aprobada** |
| **Fase 4B** | `feature/fase-4b-reservas-concurrencia` | `83e2a24b235a72c2865453ec13ea055d96419700` | **Aprobada (`APROBADO`)** |
| **Fase 5** | `feature/fase-5-interfaz-reservas` | — | **En Curso** |
| **Fase 6A** | `feature/fase-6a-notificaciones` *(prevista)* | — | **Pendiente** |
| **Fase 6B** | `feature/fase-6b-reportes` *(prevista)* | — | **Pendiente** |
| **Fase 6C** | `feature/fase-6c-base-pagos` *(prevista)* | — | **Pendiente** |
| **Cierre Técnico** | `feature/cierre-tecnico` *(prevista)* | — | **Pendiente** |

---

## 3. Alcance Detallado de Fases

### Fase 4B — Cerrada con Veredicto `APROBADO` (`83e2a24b235a72c2865453ec13ea055d96419700`)
- Verificada en checkout separado (`auditing_fase_4b_reservas`) con `PHPUnit 10.5.66` (`137 tests, 1399 assertions`, exit `0`) y suite E2E en navegador (`RUN_ID: b9819badc55b4a2588a4db444a3f4b1c`, 16 comprobaciones `[OK]`, exit `0`). Ver detalle en `docs/AUDITORIA.md`.

### Fase 5 — Interfaz de Reservas y Panel Administrativo
- Selección de servicios y profesionales compatibles en el flujo de reservas.
- Calendario y horarios disponibles obtenidos dinámicamente del backend (`GET /api/disponibilidad`).
- Resumen completo con profesional, hora de inicio, hora de fin, duración total y precio total.
- Actualización reactiva al cambiar servicios, profesional o fecha, invalidando selecciones obsoletas.
- Estados visuales de carga, error y disponibilidad vacía.
- Gestión de conflictos HTTP `409` y prevención de envíos duplicados.
- Consulta y cancelación autorizada de citas por parte del cliente.
- Panel administrativo con profesional asignado, intervalo horario (`hora_inicio` - `hora_fin`) y datos históricos de servicios, identificando citas antiguas sin inventar información.
- Diseño móvil accesible, navegación por teclado y etiquetas asociadas.
- Coherencia de fechas y horarios en `America/Guayaquil`.
- Documentación y pruebas de la política del endpoint clásico para evitar reservas sin profesional inadvertidas en el nuevo flujo.

### Fase 6A — Notificaciones
- Correos transaccionales de confirmación y cancelación de citas.
- Recordatorios configurables mediante tarea programada documentada.
- Envíos idempotentes y reintentos seguros sin duplicados.
- Aislamiento de fallos SMTP para que un error de correo nunca revierta una reserva ya confirmada en base de datos.
- Configuración segura de enlaces y remitentes; pruebas con transporte sustituible o buzón de pruebas sin envíos reales a clientes.

### Fase 6B — Reportes Administrativos
- Reportes de reservas por rango de fechas, profesional y servicio.
- Métricas de ocupación respecto de la jornada disponible del profesional.
- Importes calculados a partir de los datos históricos capturados al reservar.
- Distinción explícita entre importe reservado e ingreso efectivamente pagado.
- Filtros, estados vacíos, exportación CSV segura (prevención de CSV injection), permisos administrativos y pruebas de cálculos.

### Fase 6C — Base para Pagos
- Estados de pago e interfaz sustituible de proveedor de pagos (*Payment Gateway Interface*).
- Proveedor simulado (*mock/sandbox*) para pruebas automatizadas.
- Cálculo de importes exclusivamente en servidor y generación de referencias únicas.
- Idempotencia y validación de notificaciones/webhooks del proveedor.
- Prohibición de almacenar datos de tarjetas o confirmar pagos únicamente por redirección del navegador.
- Sin activación de cobros reales ni selección comercial definitiva; registro de decisiones de negocio pendientes sobre anticipos, cancelaciones y devoluciones.

### Cierre Técnico
- Auditoría integral de rutas, permisos, seguridad y manejo de errores.
- Revisión de dependencias (`composer.json`, `package.json`) con cambios justificados y registros limpios de secretos.
- Revisión de consultas SQL e índices.
- Flujo de build y pruebas automatizadas en GitHub Actions (`.github/workflows/`).
- Instalación y ejecución Docker reproducibles, `includes/.env.example` documentado, respaldo y restauración probados en entorno aislado, guía de migración/operación y verificación final desde entorno limpio.

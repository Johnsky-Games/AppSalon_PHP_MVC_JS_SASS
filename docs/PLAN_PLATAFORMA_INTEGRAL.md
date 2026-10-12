# Plan y criterios de entrega de la plataforma integral

Fecha: 2026-10-12. Rama de trabajo: `feature/plataforma-integral`. Base: `61c8adc78a035a38b3d2f87531306f7849872f45`.

Esta entrega amplía la aplicación existente. No equivale a certificación de producción ni a integración de cobros reales. La aceptación depende de evidencia ejecutada sobre el SHA publicado, además de revisión del código. Se conserva la instalación del usuario y se prueba sobre bases aisladas.

## Equipo y responsabilidades

| Área | Responsabilidad | Frontera |
|---|---|---|
| Dirección y arquitectura | Priorizar por riesgo, coordinar contratos, integrar y publicar | No sustituye decisiones comerciales del propietario |
| Identidad y seguridad | Roles, colaboradores, revocación, primer propietario, validaciones | Permiso y alcance comprobados en servidor |
| Perfiles y privacidad | Datos personales, preferencias, foto y acceso a medios | Fotos de clientes privadas por defecto |
| Agenda y operación | Estados, historial y reprogramación | Intervalos y políticas calculados en servidor |
| Finanzas e inventario | Caja, registro de cobros, stock y conciliación | Simulación distinguida de dinero real |
| Marketing y datos | Consentimiento, espera, fidelidad, formularios y métricas | No activar campañas sin consentimiento |
| QA y riesgos | Contrapruebas, migraciones, restauración, matriz y evidencia | Un test no ejecutado se registra pendiente |

## Orden de integración

1. Integridad: bloquear pago nuevo de cita cancelada; eliminar dependencias críticas; mantener tests previos.
2. Identidad: autorización en cada ruta sensible, revocación y protección del último propietario.
3. Perfiles y agenda: acceso propio a datos, fotos seguras, estados y reprogramaciones transaccionales.
4. Operación comercial: stock, caja, ajustes y consentimientos con fuentes únicas y trazabilidad.
5. Experiencia y datos: formularios, paneles, filtros, accesibilidad, modales y notificaciones.
6. Validación: migraciones completas, restauración, suite PHPUnit, Node, navegador y revisión final del SHA.

## Matriz mínima de permisos

| Acción | Propietario | Gerente | Recepción | Profesional | Finanzas | Cliente |
|---|---|---|---|---|---|---|
| Asignar roles / desactivar cuentas | Sí | No | No | No | No | No |
| Gestionar agenda global | Sí | Sí | Sí | No | No | No |
| Consultar agenda propia | Sí | Sí | Sí | Sólo vinculada | No | Sólo citas propias |
| Caja, reembolsos e inventario | Sí | Sí | No | No | Sí | No |
| Informes globales | Sí | Sí | No | No | Sí | No |
| Ajustes y marketing | Sí | Sí | No | No | No | No |
| Cambiar perfil / preferencias | Propio | Propio | Propio | Propio | Propio | Propio |
| Acceder a foto privada de cliente | Propia o permiso explícito | Permiso explícito | Permiso explícito | No por defecto | No | Propia |

Esta matriz es una especificación de aceptación; debe contrastarse con la implementación de cada ruta. Ocultar un botón no constituye autorización. La sesión no debe conservar un privilegio revocado.

## Criterios de producto y negocio

- Una cuenta profesional vinculada sólo ve su propia agenda, incluso al manipular IDs.
- El último propietario activo no puede perder su rol ni desactivarse, incluyendo dos cambios concurrentes.
- Una cita ya iniciada no ofrece cancelación cuando el servidor la rechazaría. Las canceladas permanecen en historial.
- Una reprogramación fallida conserva intervalo, servicios, precio, cobros e historial anteriores.
- Un cambio de catálogo no modifica el precio contratado de una cita previa.
- El cobro nuevo de cita cancelada se rechaza antes de invocar pasarela. Un callback tardío previo se registra para conciliación.
- Cada movimiento de stock es trazable; dos salidas simultáneas no producen saldo negativo. Reintentar una operación idempotente no la duplica.
- El cierre de caja distingue saldo calculado y saldo declarado. Las métricas presentan unidades, rango temporal y fuente.
- Los puntos no se conceden por una reserva sin prestación finalizada; no se duplican al repetir la operación.
- La espera no promete disponibilidad ni reserva automática sin una asignación transaccional verificada.
- Un formulario valida esquema y respuestas en servidor; no permite HTML ejecutable ni leer respuestas de otro cliente.
- Un consentimiento retirado rige todos los canales de marketing a partir de la siguiente operación; recordatorios transaccionales son independientes.

## Límites y decisiones que requieren definición comercial

Proveedor de pagos, moneda/impuestos y requisitos fiscales, reembolsos parciales, comisiones, plazos de cancelación, campañas reales y sucursales necesitan políticas explícitas. No deben declararse implementados mediante botones o datos simulados. MFA administrativa, recuperación ante desastre y evaluación de carga/penetración requieren evidencias específicas antes de una exposición pública.

## Instalación preservada

La validación del usuario usa Compose `appsalon-validacion`, URL `http://localhost:8085`, base `appsalon_mvc` y puerto externo MySQL `33061`. No borrar volúmenes ni importar `schema_base.sql` encima de una base poblada. Antes de actualizar: respaldo comprobado, migraciones `up`, verificación de esquema y prueba funcional. `database/migrador.php down` revierte **todas** las migraciones registradas; no es una reversión de la última.

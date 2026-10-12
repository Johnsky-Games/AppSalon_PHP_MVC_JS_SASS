# Crecimiento, fichas privadas y preferencias del negocio

## Acceso
- `/mi-bienestar`: sólo usuario autenticado; cada petición usa el ID de sesión, nunca un ID enviado por el cliente. Puntos, lista de espera, fichas y consentimiento comercial.
- `/admin/crecimiento`: permiso `marketing.gestionar`. Consultar respuestas exige además `perfiles.gestionar`; las fichas no se incluyen en segmentos comerciales.
- `/admin/ajustes`: permiso `ajustes.gestionar`. Identidad/contacto y beneficio informativo; los horarios y políticas de reserva siguen sus módulos operativos existentes.
- Toda modificación y exportación de contactos exige CSRF. Todas las salidas HTML se escapan; exportación neutraliza fórmulas CSV.

## Fidelidad
Cada cita con estado `completada` da 10 puntos. Actualizar puntos vuelve a consultar citas reales y crea un movimiento único por cita. El canje cuesta 100 puntos y usa clave de idempotencia por cliente. El bloqueo de la fila del cliente serializa canjes y evita saldo negativo. Recepción registra entrega una sola vez, con actor y fecha. El beneficio es manual, configurable y no representa un descuento de cobro automático. Cambiar el texto futuro no altera movimientos ya registrados.

Los puntos reconocen asistencia, no son dinero ni una devolución: un reembolso financiero posterior no revierte los puntos de una visita efectivamente completada.

## Lista de espera
Fecha estricta de próximos 180 días, servicio existente, profesional activo que ofrezca ese servicio. Una solicitud por cliente/servicio/día evita duplicados. Recepción gestiona contacto/cierre; no se bloquean horas ni se crea una cita automáticamente. Cancelación cliente limitada al propietario.

## Formularios
Editor de seis preguntas (servicio acepta hasta doce), tipos texto/lista/aceptación y opciones controladas. Cada formulario publicado es inmutable; archivar conserva respuestas. Una respuesta actualizable por formulario y cliente. Los datos son privados: no solicitar información médica innecesaria ni reutilizar fichas para marketing.

## Consentimiento y campañas
Fuente única `perfiles_usuario.marketing_email` más `consentimiento_marketing_at`, por defecto sin consentimiento. Retirar consentimiento limpia fecha y excluye inmediatamente exportaciones futuras. Exportación limitada a 1.000 usuarios confirmados opt-in, todos o sin visita completada en 90 días. No existe envío automático en este módulo; una exportación es una instantánea y debe revalidarse antes de enviar.

## Métricas y límites
Repetición: clientes con al menos dos citas completadas en ventana de 90 días / clientes con al menos una. Ausencias: estado `no_asistio` / todas las citas del período, incluidas cancelaciones. Cohortes: primera visita completada, repetición histórica, hasta 12 meses mostrados. No hay estimaciones ni modelos predictivos. Listados limitados a 100 y formularios a 30, visibles en pantalla; se requiere futura paginación para negocios que superen estos volúmenes.

## Validación
Pruebas unitarias rechazan tipos de formulario activos, fechas inválidas, canjes manipulados y segmentos sin consentimiento. Integración MySQL verifica puntos sólo completadas, idempotencia de canje, saldo no negativo, opt-out con la fuente única del perfil y aislamiento de respuestas por usuario. Resultado de ejecución pendiente de CI: no se considera aprobado por mera existencia de pruebas.

# Personal y agenda

La persona se registra una sola vez en `colaboradores` (Recursos Humanos). Su cuenta de acceso es opcional hasta aceptar la invitación. El rol Profesional añade una extensión operativa en `profesionales`: servicios, horarios, descansos y bloqueos pertenecen a ese ID y se consultan desde ambas vistas.

## Alta y configuración

- El propietario invita desde Recursos Humanos → Accesos e invitaciones. “Invitar profesional”, en Profesionales, usa exactamente el mismo servicio y transacción: colaborador, ficha profesional, configuración, invitación y correo en cola. Exige nombre, apellido, correo y servicios. El reenvío del mismo formulario no duplica registros.
- Una persona de recepción, gerencia o finanzas aparece en RH; esos roles no necesitan una agenda profesional. Al cambiar un colaborador aceptado al rol Profesional se reutiliza su ficha previa o se crea una única extensión. Deben configurarse servicios y horarios antes de que ofrezca turnos.
- Las fichas nuevas y las antiguas vinculadas por invitación no ofrecen nuevas reservas hasta aceptar. Se conservan las citas existentes, sus identificadores y toda la configuración anterior salvo que se solicite reemplazarla expresamente.
- La ficha laboral permite configurar la misma agenda que aparece en Profesionales. RH conserva sus permisos privados; gerencia mantiene la gestión operativa, pero el propietario registra e invita al personal.

## Estados e historial

Suspender, dar de baja o retirar el rol Profesional desactiva su agenda y cierra sus sesiones. Si hay citas futuras deben resolverse primero. Volver al rol o reactivar conserva el ID profesional anterior y restaura el acceso a su agenda. Una activación desde Profesionales no puede eludir un estado laboral incompatible. Las consultas de disponibilidad y las escrituras de reservas/reasignaciones verifican también el estado laboral, incluso si quedó un flag operativo incoherente de versiones anteriores.

Una ficha dada de baja o de una persona que dejó el rol Profesional es de consulta desde ambas vistas: no se pueden cambiar sus servicios, horarios, descansos ni bloqueos por otra pantalla. Una suspensión permite preparar la configuración, manteniendo la agenda sin nuevas reservas hasta reactivarla.

Revocar una invitación conserva su ficha ligada a la persona y su configuración. Puede reinvitarse el mismo correo si nunca fue aceptada, conservando ambos IDs. No se libera silenciosamente la ficha para asociarla a otra persona.

## Fichas antiguas pendientes

RH muestra, en un apartado desplegable, las fichas sin vínculo laboral. “Vincular ficha laboral” abre una invitación con ese ID preseleccionado. La selección es explícita: nunca se decide que dos personas son la misma por su nombre. Si ya existe una cuenta enlazada a la ficha, el correo de la invitación debe corresponder a esa cuenta. Un vínculo ocupado por otra persona se rechaza y toda la transacción se revierte.

Esta corrección no borra ni fusiona datos al actualizar el código y no requiere migración de esquema. Los registros antiguos sin información suficiente siguen visibles para su revisión; no se inventan correos, apellidos ni credenciales.

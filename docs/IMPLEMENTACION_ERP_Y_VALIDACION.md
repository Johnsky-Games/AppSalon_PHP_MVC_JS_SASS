# Plataforma operativa ERP de AppSalon

Esta entrega desarrolla el núcleo operativo del plan maestro del 17 de octubre de 2026. El plan sigue siendo la referencia de alcance; no todos sus componentes equivalen a funciones terminadas. La facturación fiscal, contabilidad localizada y nómina regulada requieren elegir proveedores. El usuario seleccionó mantenerlos por definir.

## Procesos incorporados

| Área | Recorrido disponible | Registro compartido |
|---|---|---|
| RH y profesionales | Conciliación explícita de fichas antiguas con colaboradores; conserva agenda e historial | Colaboradores, usuarios y profesionales existentes |
| Consulta | Atajos de período en agenda, caja, finanzas, reportes y CRM; agenda paginada | Fechas concretas resueltas por servidor |
| Venta | Mostrador o desde cita; productos y servicios pactados; descuentos autorizados; confirmación con versión | Una venta por cita; no duplica anticipos existentes |
| Cotización | Instantánea, condiciones y vencimiento; aceptación que confirma la misma venta | Cambiar conceptos exige una nueva cotización |
| Cobro | Efectivo, importe recibido y cambio; transferencia pendiente de verificación; abonos | Tabla `pagos` y caja histórica, sin un segundo libro de dinero |
| Caja | Apertura personal, movimientos, arqueo, cierre y diferencia motivada | Transferencias excluidas del efectivo físico |
| Inventario | Reservar al confirmar; descontar al entregar; devolución física explícita | Productos y movimientos existentes; stock reservado protegido también en ajustes manuales |
| Compras | Orden, autorización, recepción parcial, costo promedio, factura, obligación y abonos | Recibir, facturar y pagar son actos independientes |
| Proveedor | Devolución de mercadería, nota de crédito y reintegro recibido | Un crédito no inventa una entrada de dinero |
| RR. HH. | Contratos, finalización, jornadas por grupos de días, asistencia y anulación motivada | Conservación de evidencia en eventos |
| Ausencias | Solicitud propia, aprobación/rechazo, anulación y bloqueo de agenda | Exige resolver citas afectadas antes de aprobar |
| Comisiones | Regla por colaborador y vigencia; devengo por cobro y entrega/atención; ajustes por devoluciones | Compensa devengos históricos importados; liquidar crea obligación o novedad de nómina, no pago automático |
| Aprobaciones | Descuento, gasto o ajuste de inventario; decisión motivada | Gasto aprobado crea cuenta por pagar, no egreso |
| Promociones | Cupones porcentuales, vigencia, mínimo, cupo y límite por comprador; pausa/reactivación | Reserva de cupo, validación al confirmar y reversión al anular |
| CRM | Contactos sin login, vínculo explícito con cliente registrado, oportunidades, próximas acciones y reclamos | No fusiona personas automáticamente por nombre o correo |
| Recursos | Cabinas/equipos por tipo, requisitos por servicio, mantenimiento y activos | Reserva física dentro de la transacción de cita; intervalos sin solapamiento |
| Insumos | Receta por servicio y consumo una vez por atención | Salida física sin generar cobro; respeta reservas comerciales |
| Documentos | Subida, versiones, vencimiento, descarga y acceso de titular autorizado | Cifrado con clave derivada de `APP_KEY`; documentos privados de RH protegidos en servidor |
| BI | Ventas, créditos, caja por día, saldos actuales y stock disponible | Agregados por fuente; exportación CSV/Excel auditada |
| Integraciones | Contrato de adaptador, outbox, referencia estable, lease, consulta de conciliación y reintento | Sin proveedor, permanece `sin_configurar`; nunca simula autorización fiscal |

Las tarifas de servicios configuradas en el ERP actualizan el precio final mostrado al reservar. Las reservas nuevas conservan el desglose de su tarifa; las antiguas conservan su importe y no reciben un impuesto inventado retrospectivamente. El editor anterior permite nombre/duración y remite los cambios de tarifas gestionadas al PDV.

## Garantías y límites

- Dinero en centavos y cantidades en milésimas; validación en servidor y transacciones.
- Idempotencia por operación, actor y contenido; autorización comprobada de nuevo dentro de la escritura.
- Eventos y recibos internos de solo anexado; recibos con instantánea y SHA-256.
- No se convierte la base histórica en ventas nuevas ni se mueve dinero por migrar.
- Los recibos internos y cotizaciones no son facturas electrónicas autorizadas.
- Una caja corresponde al operador; aún no modela terminales físicos, múltiples sedes o almacenes.
- El recurso se reserva para todo el intervalo de la visita; no hay asignación por etapas ni varios operadores simultáneos.
- Las pantallas con consultas limitadas indican su alcance; ventas y agenda tienen paginación. No todos los directorios tienen todavía paginación/exportación completa.
- La regla de comisión de esta entrega es por colaborador y vigencia; no ofrece una matriz por categoría/producto ni calcula prestaciones legales.
- El CRM anterior y sus puntos/beneficios siguen disponibles. Paquetes prepagados, suscripciones, tarjetas regalo, propinas, saldos a favor y canjes convertidos en descuentos requieren un libro económico y reglas de reversión adicionales; esta entrega no los presenta como terminados.
- Los cupones no se acumulan con descuentos manuales. No hay todavía segmentación de promoción por canal o categoría ni promoción automática por prioridad.
- No se incorporan WMS avanzado, MRP, reclutamiento/evaluación de talento ni contabilidad de doble partida propia. Son alcances condicionales del plan maestro.

La integración real debe definir emisor/país, impuestos y documentos admitidos, proveedor, cuentas contables, reglas de nómina y conciliación. El contrato `IntegracionErpInterface` exige consulta por referencia y confirmación verificable. La cola incluye un sobre versionado con evento, entidad, fecha y datos. No se deben marcar eventos como enviados manualmente para aparentar integración.

## Verificación de continuidad del 18 de octubre

El CI del commit `f2086ce` aprobó el recorrido real de navegador, la navegación por rol y la suite principal (1172 pruebas, 18394 aserciones). La QA independiente encontró defectos de formularios y contratos anteriores a la ampliación; ese resultado no se considera una validación completa del ERP.

Esta revisión corrige el HTML de los formularios de CRM, la reproducción de entradas no escalares en equipo y los retornos de agenda. Refuerza la separación entre portales incluso para un propietario con membresía de cliente: una sesión del portal cliente no puede ejecutar acciones del ERP ni abrir expedientes laborales. Las rutas empresariales sin sesión llevan al acceso del equipo.

Los contratos de QA incluyen ahora todos los archivos de rutas registrados, los permisos explícitos por rol y el esquema de 92 tablas. Las pruebas de acciones de agenda recorren los enlaces reales de paginación, conservando el formulario de cada página. La auditoría de exportaciones de caja exige exactamente un registro por intento autenticado; no se excluyen otros movimientos. Recepción solo ve el nombre del producto necesario para solicitar un ajuste, sin extender esa excepción al SKU, costos o movimientos.

Validación local dirigida: los 13 casos de CRM finalizaron sin fallos; las dos pruebas de formas hostiles de RH aprobaron 523 aserciones; las siete pruebas de períodos, consistencia y portales aprobaron 26 aserciones. Se aprobaron 26 casos de agenda con 375 aserciones, incluido el regreso desde una cita a la segunda página con su filtro de estado; y los seis casos de login real con 18 aserciones. El ciclo de respaldo/restauración con manifiesto SHA-256 recuperó las 92 tablas de la base de pruebas. La matriz completa de autorización se amplió a 211 casos. Los resultados definitivos de la regresión completa deben consultarse en el CI del PR #4.


## Actualización Docker de un entorno con datos

Realizar después de revisar la rama y sus comprobaciones. Estos comandos no actualizan el equipo Windows desde esta sesión. Ejecutar en el repositorio local, conservando `includes/.env`, `APP_KEY` y los volúmenes existentes.

```bash
git fetch origin
git switch feature/erp-salon-integrado
git pull --ff-only

docker compose --env-file includes/.env -p appsalon-validacion stop web notificaciones
docker compose --env-file includes/.env -p appsalon-validacion up -d db
docker compose --env-file includes/.env -p appsalon-validacion build web instalador notificaciones

docker compose --env-file includes/.env -p appsalon-validacion run --rm --no-deps -T instalador php scripts/backup_db.php --gzip
docker compose --env-file includes/.env -p appsalon-validacion run --rm --no-deps -T instalador php scripts/instalar.php --migrar
docker compose --env-file includes/.env -p appsalon-validacion run --rm --no-deps -T instalador php scripts/instalar.php --comprobar

docker compose --env-file includes/.env -p appsalon-validacion up -d web notificaciones
docker compose --env-file includes/.env -p appsalon-validacion ps -a
docker compose --env-file includes/.env -p appsalon-validacion logs --no-color --tail 80 notificaciones
```

Comprobar cada salida antes de ejecutar el comando siguiente; detenerse si falla respaldo, migración o comprobación. El bind del proyecto conserva el respaldo en `storage/backups`; copiarlo a un destino seguro y verificar su manifiesto. Conservar `APP_KEY` por separado: cambiarla impide descifrar documentos y otras cargas cifradas. Nunca publicar respaldos o claves en Git.

No ejecutar `down -v`, no eliminar `appsalon-validacion_appsalon_db_data` y no recrear cuentas para actualizar. La sesión `instalador` finalizada con código cero es normal. El nombre del proyecto y la red deben coincidir con la configuración que ya utiliza el entorno.

## Prueba de aceptación del piloto

1. Vincular una ficha profesional antigua con el colaborador correcto y comprobar su agenda/historial.
2. Configurar empresa, artículos, impuestos, recursos y reglas de comisión con datos de ensayo.
3. Abrir caja; registrar una cita y abrir su venta; añadir producto; confirmar, cobrar y entregar. Comprobar que el anticipo anterior no se repite.
4. Registrar transferencia; verificar con otro usuario autorizado; comprobar que no aumenta el efectivo físico.
5. Devolver parcialmente un producto; comprobar crédito, reintegro, stock y ajuste de comisión. Revisar el recibo emitido antes del cambio.
6. Crear compra, recibir parcialmente, facturar y abonar. Registrar una nota de crédito sin inventar un reintegro.
7. Solicitar ausencia propia; comprobar que otra persona no puede leer el expediente privado y que la aprobación protege las citas existentes.
8. Reservar dos profesionales que necesitan la misma cabina; el segundo intervalo incompatible debe rechazarse sin guardar media reserva.
9. Registrar consumo de receta dos veces; la salida física debe existir una sola vez.
10. Descargar documento privado con un usuario ajeno; denegación. Descargar la versión autorizada con su titular; contenido original.
11. Cotizar, cambiar un concepto e intentar aceptar la versión antigua; exigir una cotización nueva.
12. Conciliar indicadores y exportaciones con sus fuentes y fechas. Verificar que las integraciones aún no configuradas siguen pendientes.

Los resultados automatizados y el SHA validado se registrarán en el PR. Un CI anterior de `main` no acredita esta ampliación. La aprobación de un piloto real y la selección de proveedores siguen pendientes.

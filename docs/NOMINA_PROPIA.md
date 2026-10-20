# Nómina propia (migración 044)

AppSalon calcula su propia nómina para empleados con relación laboral. Esta etapa es de **ensayo**: **no trae cifras legales cargadas**. Sueldo básico, aportes al IESS, fondos de reserva, recargos y jornada los registra una persona autorizada, con su fuente oficial, enlace y fecha de consulta, y **sin ellos la nómina no se calcula**. Valida el resultado con el contador y con asesoría laboral antes de pagar sueldos reales.

## Fuentes consultadas y su fiabilidad (19 de octubre de 2026)
Desde el entorno de desarrollo no se pudo abrir `sri.gob.ec`, `iess.gob.ec` ni los portales del Ministerio del Trabajo (política de red); lo siguiente proviene de resultados de búsqueda y fuentes secundarias, **sin sustituir la lectura de la norma**:
* **Salario básico unificado 2026:** USD 482,00 según el Acuerdo Ministerial MDT-2025-195 (15 de diciembre de 2025), citado por prensa y por un servicio jurídico de pago.
* **Aportes al IESS (sector privado, relación de dependencia):** 9,45 % personal y 11,15 % patronal según varias guías secundarias; una de ellas suma IECE y SECAP. Ninguna es la tabla oficial. Por eso son parámetros.
* **Fondos de reserva:** 8,33 % tras un año de servicio (conocimiento general; no confirmado en esta consulta).
* **Recargos y jornada:** 25 % nocturno (19h00 a 06h00), suplementarias 50 % (hasta las 24h00) o 100 % (24h00 a 06h00), extraordinarias 100 %, jornada de 40 horas semanales: la mayoría de fuentes coincide, una discrepa (art. 55 del Código del Trabajo). Parámetros.
* **Décimo tercero:** doceava parte de lo devengado entre el 1 de diciembre y el 30 de noviembre, hasta el 24 de diciembre. **Décimo cuarto:** un SBU, hasta el 15 de agosto (Sierra y Amazonía) o el 15 de marzo (Costa y Galápagos) según prensa.
* **Impuesto a la renta:** tabla 2026 de la Resolución NAC-DGERCGC25-00000043 (fracción básica USD 12.208) según fuentes secundarias. **No se calcula automáticamente**: la retención se registra como novedad calculada por el contador.

## Qué calcula
Por colaborador con relación `empleado` vigente en el mes, con **`nomina-v1`** (la fórmula queda en la instantánea de cada línea):
* **Sueldo:** base del contrato × días / 30 (mes completo = 30 días; mes parcial = días calendario del contrato dentro del mes). Si el colaborador cambia de contrato dentro del mes, hay **una sola línea**: cada tramo se prorratea con el mes comercial de 30 días (el día 31 no suma), los días se acumulan con tope de 30, las horas extra se valoran con la base del último contrato y los contratos solapados se rechazan (409).
* **Horas:** valor de la hora = base / horas del mes (parámetro); suplementarias y extraordinarias = hora × (1 + recargo); nocturnas = solo el recargo. Entran como novedades aprobadas.
* **Bonos, descuentos y retención de renta:** novedades aprobadas.
* **Comisiones:** las liquidadas **por nómina** y aún no incluidas en otra corrida. Las liquidadas por pago directo **nunca** entran (un trigger lo impide) y una comisión no puede estar en dos corridas activas (índice único sobre el vínculo activo).
* **Aporte personal y patronal al IESS:** sobre la remuneración gravada (sueldo, horas, bonos que aportan y comisiones).
* **Décimo tercero:** 1/12 de lo devengado. **Décimo cuarto:** SBU × días / 360. **Fondos de reserva:** porcentaje de lo devengado desde la antigüedad configurada.
* Cada beneficio se **mensualiza** (va al neto) o se **acumula** (provisión pagadera en su fecha); los fondos acumulados se pagan junto con el IESS.
* **Neto** = ingresos − aporte personal − retención − descuentos. Si queda en cero o negativo, el cálculo se bloquea con el colaborador afectado.

## Ciclo y controles
`calculada → revisada → aprobada` (anulable antes de pagar). Calcular y registrar novedades requiere `nomina.calcular`; revisar, aprobar, resolver novedades, liquidar beneficios y anular requieren `nomina.aprobar`; parámetros y condiciones, `nomina.configurar`. Quien prepara no aprueba su propio trabajo, **salvo el propietario** (negocio de una sola persona), y todo queda en la auditoría. Hoy solo el propietario tiene `nomina.aprobar`, y finanzas puede ver y preparar. Cada colaborador ve **solo su propio recibo**, y solo de una corrida aprobada (`nomina.recibo_propio`).

Al aprobar, en una sola transacción: se consumen las novedades, se crea **una obligación por colaborador** (neto), **una al IESS** (personal + patronal + fondos acumulados) y **una al SRI** (retenciones), y se emite el hecho contable `nomina_aprobada`. Las obligaciones se pagan con el flujo existente de Obligaciones; reintentar la aprobación no duplica nada (prueba con dos procesos reales).

**Anular** una corrida aprobada solo es posible si **ninguna** de sus obligaciones tiene pagos y no se liquidaron beneficios de ella: cancela las obligaciones, devuelve las novedades y las comisiones, y revierte el asiento. Pasado ese punto, el camino es una **novedad de ajuste trazable** (con la línea que corrige) en un mes abierto: la corrida cerrada nunca se edita ni se borra (triggers).

**Beneficios acumulados:** "Beneficios acumulados" muestra lo provisionado y aún no liquidado; liquidar crea la obligación al colaborador y marca las líneas para que no se liquiden dos veces.

## Contabilidad
El asiento de la nómina aprobada (`nomina_aprobada`): gasto de sueldos, comisiones, beneficios y aportes patronales contra sueldos por pagar, IESS por pagar, retención por pagar, beneficios por pagar y descuentos por liquidar. Se fecha en el fin del mes que devenga cuando ese mes ya terminó; si no, el día de la aprobación. Pagar cada obligación cancela el pasivo que corresponde a su origen. La comisión pagada por nómina se contabiliza **aquí**, no como comisión de pago directo (no se cuenta dos veces). Anular una corrida contabilizada genera el reverso.

## Límites conocidos (pendiente concreto)
* **Impuesto a la renta** de relación de dependencia: sin cálculo automático (proyección anual, deducciones). Se registra como novedad.
* **Finiquito y liquidación de haberes** (vacaciones no gozadas, décimos proporcionales, desahucio, indemnizaciones, bonificaciones), **utilidades**, **vacaciones** y **licencias remuneradas** (el módulo de ausencias no distingue las no remuneradas: se descuentan con novedades).
* **Jornada y horas:** las horas extra se registran manualmente; no se derivan de la asistencia.
* **Archivos y avisos al IESS/Ministerio del Trabajo/SRI** (planillas, SUT, formularios 107/103): sin generar.
* **Décimo cuarto de menores de edad o jornada parcial, aprendices y regímenes especiales:** no modelados.
* Los parámetros son por emisor (no por convenio sectorial) y las condiciones se registran por colaborador.

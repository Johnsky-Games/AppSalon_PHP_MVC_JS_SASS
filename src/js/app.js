let paso = 1;
const pasoInicial = 1;
const pasoFinal = 3;

const cita = {
    id: '',
    nombre: '',
    profesionalId: '',
    profesionalNombre: '',
    fecha: '',
    hora: '',
    hora_fin: '',
    duracion_total_minutos: 0,
    servicios: []
};

let catalogoProfesionales = [];
let intervalosDisponiblesActuales = [];
let disponibilidadRequestId = 0;
let enviandoReserva = false;

document.addEventListener('DOMContentLoaded', function () {
    iniciarApp();
});

function iniciarApp() {
    mostrarSeccion();
    tabs();
    botonesPaginador();
    paginaSiguiente();
    paginaAnterior();

    idCliente();
    nombreCliente();

    seleccionarProfesional();
    seleccionarFecha();
    sleccionarHora();

    consultarAPI();
    cargarMisCitas();

    mostrarResumen();
}

function mostrarSeccion() {
    const seccionAnterior = document.querySelector('.mostrar');
    if (seccionAnterior) {
        seccionAnterior.classList.remove('mostrar');
    }

    const seccion = document.querySelector(`#paso-${paso}`);
    if (seccion) {
        seccion.classList.add('mostrar');
    }

    const tabAnterior = document.querySelector('.actual');
    if (tabAnterior) {
        tabAnterior.classList.remove('actual');
    }

    const tab = document.querySelector(`[data-paso="${paso}"]`);
    if (tab) {
        tab.classList.add('actual');
    }
}

function tabs() {
    const botones = document.querySelectorAll('.tabs button');

    botones.forEach(boton => {
        boton.addEventListener('click', function (e) {
            paso = parseInt(e.currentTarget.dataset.paso, 10);
            mostrarSeccion();
            botonesPaginador();
        });
    });
}

function botonesPaginador() {
    const paginaAnterior = document.querySelector('#anterior');
    const paginaSiguiente = document.querySelector('#siguiente');

    if (!paginaAnterior || !paginaSiguiente) return;

    if (paso === 1) {
        paginaAnterior.classList.add('ocultar');
        paginaSiguiente.classList.remove('ocultar');
    } else if (paso === 3) {
        paginaAnterior.classList.remove('ocultar');
        paginaSiguiente.classList.add('ocultar');
        mostrarResumen();
    } else {
        paginaAnterior.classList.remove('ocultar');
        paginaSiguiente.classList.remove('ocultar');
    }

    mostrarSeccion();
}

function paginaAnterior() {
    const btnAnterior = document.querySelector('#anterior');
    if (!btnAnterior) return;
    btnAnterior.addEventListener('click', function () {
        if (paso <= pasoInicial) return;
        paso--;
        botonesPaginador();
    });
}

function paginaSiguiente() {
    const btnSiguiente = document.querySelector('#siguiente');
    if (!btnSiguiente) return;
    btnSiguiente.addEventListener('click', function () {
        if (paso >= pasoFinal) return;
        paso++;
        botonesPaginador();
    });
}

async function consultarAPI() {
    try {
        const [resServicios, resProfesionales] = await Promise.all([
            fetch(`${location.origin}/api/servicios`, { headers: { 'Accept': 'application/json' } }),
            fetch(`${location.origin}/api/profesionales`, { headers: { 'Accept': 'application/json' } })
        ]);

        if (resServicios.ok) {
            const servicios = await resServicios.json();
            if (Array.isArray(servicios)) {
                mostrarServicios(servicios);
            }
        }

        if (resProfesionales.ok) {
            const dataProf = await resProfesionales.json();
            if (dataProf && Array.isArray(dataProf.profesionales)) {
                catalogoProfesionales = dataProf.profesionales;
            }
        }

        actualizarSelectorProfesionales();
    } catch (error) {
        console.log(error);
    }
}

async function cargarProfesionalesCompatiblesRemoto() {
    try {
        const ids = cita.servicios.map(s => s.id).join(',');
        const query = ids ? `?servicios=${encodeURIComponent(ids)}` : '';
        const res = await fetch(`${location.origin}/api/profesionales${query}`, {
            headers: { 'Accept': 'application/json' }
        });
        if (!res.ok) return;
        const data = await res.json();
        if (data && Array.isArray(data.profesionales)) {
            catalogoProfesionales = data.profesionales;
            actualizarSelectorProfesionales(false);
        }
    } catch (e) {
        // Ignorar fallo silencioso de refresco secundario; actualizarSelectorProfesionales usa catálogo en memoria
    }
}

function mostrarServicios(servicios) {
    const contenedor = document.querySelector('#servicios');
    if (!contenedor) return;
    contenedor.innerHTML = '';

    servicios.forEach(servicio => {
        const { id, nombre, precio } = servicio;
        const duracion = parseInt(servicio.duracion_minutos, 10) || 30;

        const nombreServicio = document.createElement('P');
        nombreServicio.classList.add('nombre-servicio');
        nombreServicio.textContent = nombre;

        const duracionServicio = document.createElement('P');
        duracionServicio.classList.add('duracion-servicio');
        duracionServicio.textContent = `${duracion} min`;

        const precioServicio = document.createElement('P');
        precioServicio.classList.add('precio-servicio');
        precioServicio.textContent = `$ ${precio}`;

        const servicioDiv = document.createElement('DIV');
        servicioDiv.classList.add('servicio');
        servicioDiv.dataset.idServicio = id;
        servicioDiv.dataset.duracionMinutos = String(duracion);
        servicioDiv.setAttribute('role', 'button');
        servicioDiv.setAttribute('tabindex', '0');
        servicioDiv.setAttribute('aria-pressed', 'false');

        servicioDiv.onclick = function () {
            seleccionarServicio(servicio);
        };
        servicioDiv.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                seleccionarServicio(servicio);
            }
        });

        servicioDiv.appendChild(nombreServicio);
        servicioDiv.appendChild(duracionServicio);
        servicioDiv.appendChild(precioServicio);

        contenedor.appendChild(servicioDiv);
    });
}

function calcularDuracionTotalServicios() {
    return cita.servicios.reduce((acc, s) => {
        const d = parseInt(s.duracion_minutos, 10) || 30;
        return acc + d;
    }, 0);
}

function calcularPrecioTotalServicios() {
    return cita.servicios.reduce((acc, s) => {
        const p = parseFloat(s.precio) || 0;
        return acc + p;
    }, 0);
}

function invalidarHorarioSeleccionado() {
    cita.hora = '';
    cita.hora_fin = '';
    cita.duracion_total_minutos = calcularDuracionTotalServicios();

    const inputHora = document.querySelector('#hora');
    if (inputHora) {
        inputHora.value = '';
    }

    const botonesIntervalo = document.querySelectorAll('#contenedor-horarios-disponibles .intervalo-btn');
    botonesIntervalo.forEach(btn => {
        btn.classList.remove('seleccionado');
        btn.setAttribute('aria-pressed', 'false');
    });
}

function actualizarEstadoDisponibilidadUI(estado, mensaje) {
    const elEstado = document.querySelector('#estado-disponibilidad');
    if (!elEstado) return;

    elEstado.dataset.estado = estado;
    elEstado.className = `estado-disponibilidad estado-${estado}`;
    elEstado.textContent = mensaje;
}

function limpiarContenedorHorarios() {
    const contenedor = document.querySelector('#contenedor-horarios-disponibles');
    if (contenedor) {
        contenedor.innerHTML = '';
    }
    intervalosDisponiblesActuales = [];
}

function seleccionarServicio(servicio) {
    const { id } = servicio;
    const { servicios } = cita;
    const divServicio = document.querySelector(`[data-id-servicio="${id}"]`);

    if (servicios.some(agregado => String(agregado.id) === String(id))) {
        cita.servicios = servicios.filter(agregado => String(agregado.id) !== String(id));
        if (divServicio) {
            divServicio.classList.remove('seleccionado');
            divServicio.setAttribute('aria-pressed', 'false');
        }
    } else {
        cita.servicios = [...servicios, servicio];
        if (divServicio) {
            divServicio.classList.add('seleccionado');
            divServicio.setAttribute('aria-pressed', 'true');
        }
    }

    // Al cambiar servicios, invalidar inmediatamente cualquier horario previo y recalcular profesionales compatibles
    invalidarHorarioSeleccionado();
    actualizarSelectorProfesionales(true);
}

function obtenerProfesionalesCompatiblesEnMemoria() {
    const idsSeleccionados = cita.servicios.map(s => parseInt(s.id, 10)).filter(n => !isNaN(n));
    if (idsSeleccionados.length === 0) {
        return [];
    }

    return catalogoProfesionales.filter(prof => {
        if (Number(prof.activo) !== 1) return false;
        const serviciosProf = Array.isArray(prof.servicios)
            ? prof.servicios.map(id => parseInt(id, 10))
            : [];
        return idsSeleccionados.every(idReq => serviciosProf.includes(idReq));
    });
}

function actualizarSelectorProfesionales(dispararConsultaDisponibilidad = true) {
    const selectProf = document.querySelector('#profesional');
    const estadoProf = document.querySelector('#estado-profesionales');
    if (!selectProf) return;

    const profPrevioId = cita.profesionalId ? String(cita.profesionalId) : '';

    if (cita.servicios.length === 0) {
        disponibilidadRequestId++;
        selectProf.innerHTML = '<option value="">-- Selecciona primero al menos un servicio --</option>';
        selectProf.disabled = true;
        cita.profesionalId = '';
        cita.profesionalNombre = '';
        if (estadoProf) {
            estadoProf.textContent = '';
            estadoProf.classList.add('ocultar');
        }
        limpiarContenedorHorarios();
        actualizarEstadoDisponibilidadUI(
            'idle',
            'Selecciona servicios, profesional y fecha para consultar los horarios disponibles.'
        );
        return;
    }

    const compatibles = obtenerProfesionalesCompatiblesEnMemoria();
    if (compatibles.length === 0) {
        disponibilidadRequestId++;
        selectProf.innerHTML = '<option value="">-- Sin profesionales compatibles --</option>';
        selectProf.disabled = true;
        cita.profesionalId = '';
        cita.profesionalNombre = '';
        const msgIncomp = 'Ningún profesional activo realiza simultáneamente todos los servicios seleccionados.';
        if (estadoProf) {
            estadoProf.textContent = msgIncomp;
            estadoProf.classList.remove('ocultar');
        }
        limpiarContenedorHorarios();
        actualizarEstadoDisponibilidadUI('empty', msgIncomp);
        return;
    }

    if (estadoProf) {
        estadoProf.textContent = '';
        estadoProf.classList.add('ocultar');
    }

    selectProf.disabled = false;
    selectProf.innerHTML = '<option value="">-- Selecciona un profesional --</option>';
    compatibles.forEach(prof => {
        const option = document.createElement('OPTION');
        option.value = String(prof.id);
        option.textContent = prof.nombre;
        selectProf.appendChild(option);
    });

    const profSigueCompatible = profPrevioId !== '' && compatibles.some(p => String(p.id) === profPrevioId);
    if (profSigueCompatible) {
        const profObj = compatibles.find(p => String(p.id) === profPrevioId);
        selectProf.value = profPrevioId;
        cita.profesionalId = String(profObj.id);
        cita.profesionalNombre = profObj.nombre;
        if (dispararConsultaDisponibilidad && cita.fecha) {
            consultarDisponibilidad();
        }
    } else {
        disponibilidadRequestId++;
        cita.profesionalId = '';
        cita.profesionalNombre = '';
        selectProf.value = '';
        limpiarContenedorHorarios();
        if (profPrevioId !== '') {
            actualizarEstadoDisponibilidadUI(
                'idle',
                'El profesional previamente elegido no realiza todos los servicios actuales. Selecciona un profesional compatible.'
            );
        } else {
            actualizarEstadoDisponibilidadUI(
                'idle',
                'Selecciona un profesional compatible y una fecha para consultar los horarios disponibles.'
            );
        }
    }
}

function seleccionarProfesional() {
    const selectProf = document.querySelector('#profesional');
    if (!selectProf) return;

    selectProf.addEventListener('change', function (e) {
        invalidarHorarioSeleccionado();
        const valor = String(e.target.value || '').trim();
        if (!valor) {
            disponibilidadRequestId++;
            cita.profesionalId = '';
            cita.profesionalNombre = '';
            limpiarContenedorHorarios();
            actualizarEstadoDisponibilidadUI(
                'idle',
                'Selecciona un profesional compatible y una fecha para consultar los horarios disponibles.'
            );
            return;
        }

        const opcion = e.target.options[e.target.selectedIndex];
        cita.profesionalId = valor;
        cita.profesionalNombre = opcion ? opcion.textContent.trim() : '';

        if (cita.fecha && cita.servicios.length > 0) {
            consultarDisponibilidad();
        } else {
            limpiarContenedorHorarios();
            actualizarEstadoDisponibilidadUI(
                'idle',
                'Selecciona una fecha válida para consultar los horarios disponibles.'
            );
        }
    });
}

function idCliente() {
    const inputId = document.querySelector('#id');
    cita.id = inputId ? inputId.value : '';
}

function nombreCliente() {
    const inputNombre = document.querySelector('#nombre');
    cita.nombre = inputNombre ? inputNombre.value : '';
}

function obtenerFechaHoyGuayaquil() {
    try {
        const partes = new Intl.DateTimeFormat('en-CA', {
            timeZone: 'America/Guayaquil',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit'
        }).formatToParts(new Date());
        const y = partes.find(p => p.type === 'year')?.value;
        const m = partes.find(p => p.type === 'month')?.value;
        const d = partes.find(p => p.type === 'day')?.value;
        if (y && m && d) {
            return `${y}-${m}-${d}`;
        }
    } catch (e) {
        // Fallback si Intl no soporta timeZone
    }
    const now = new Date();
    const yyyy = now.getFullYear();
    const mm = String(now.getMonth() + 1).padStart(2, '0');
    const dd = String(now.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
}

function esFechaCalendarioValida(fechaStr) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(fechaStr)) {
        return false;
    }
    const [y, m, d] = fechaStr.split('-').map(Number);
    const dt = new Date(Date.UTC(y, m - 1, d));
    return dt.getUTCFullYear() === y && (dt.getUTCMonth() + 1) === m && dt.getUTCDate() === d;
}

function obtenerDiaSemanaIsoGuayaquil(fechaStr) {
    const [y, m, d] = fechaStr.split('-').map(Number);
    const utcDay = new Date(Date.UTC(y, m - 1, d, 17, 0, 0)).getUTCDay();
    return utcDay === 0 ? 7 : utcDay;
}

function formatearFechaEspanolGuayaquil(fechaStr) {
    const partes = String(fechaStr).split('-').map(Number);
    if (partes.length !== 3 || partes.some(isNaN)) {
        return fechaStr;
    }
    const [year, month, day] = partes;
    const fechaUtcMediodia = new Date(Date.UTC(year, month - 1, day, 17, 0, 0));
    const opciones = {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        timeZone: 'America/Guayaquil'
    };
    return fechaUtcMediodia.toLocaleDateString('es-MX', opciones);
}

function seleccionarFecha() {
    const fechaInput = document.querySelector('#fecha');
    if (!fechaInput) return;

    fechaInput.addEventListener('input', function (e) {
        invalidarHorarioSeleccionado();
        const valor = String(e.target.value || '').trim();

        if (!valor || !esFechaCalendarioValida(valor)) {
            disponibilidadRequestId++;
            cita.fecha = '';
            limpiarContenedorHorarios();
            actualizarEstadoDisponibilidadUI('idle', 'Selecciona una fecha válida para consultar los horarios disponibles.');
            return;
        }

        const hoyGuayaquil = obtenerFechaHoyGuayaquil();
        if (valor <= hoyGuayaquil) {
            disponibilidadRequestId++;
            e.target.value = '';
            cita.fecha = '';
            limpiarContenedorHorarios();
            mostrarAlerta('No se pueden agendar citas para el mismo día ni fechas pasadas', 'error', '.formulario');
            actualizarEstadoDisponibilidadUI('error', 'La fecha seleccionada debe ser posterior al día de hoy.');
            return;
        }

        const diaIso = obtenerDiaSemanaIsoGuayaquil(valor);
        if ([6, 7].includes(diaIso) && !cita.profesionalId) {
            disponibilidadRequestId++;
            e.target.value = '';
            cita.fecha = '';
            limpiarContenedorHorarios();
            mostrarAlerta('No se puede agendar cita en fin de semana', 'error', '.formulario');
            actualizarEstadoDisponibilidadUI('empty', 'No hay horarios disponibles en fin de semana.');
            return;
        }

        cita.fecha = valor;

        if (cita.profesionalId && cita.servicios.length > 0) {
            consultarDisponibilidad();
        } else {
            limpiarContenedorHorarios();
            actualizarEstadoDisponibilidadUI(
                'idle',
                'Selecciona servicios y un profesional compatible para ver los horarios disponibles en esta fecha.'
            );
        }
    });
}

async function consultarDisponibilidad() {
    if (cita.servicios.length === 0 || !cita.profesionalId || !cita.fecha) {
        limpiarContenedorHorarios();
        actualizarEstadoDisponibilidadUI(
            'idle',
            'Selecciona servicios, profesional y fecha para consultar los horarios disponibles.'
        );
        return;
    }

    const requestId = ++disponibilidadRequestId;
    const idsServicios = cita.servicios.map(s => s.id).join(',');

    limpiarContenedorHorarios();
    actualizarEstadoDisponibilidadUI('loading', 'Consultando horarios disponibles...');

    try {
        const url = `${location.origin}/api/disponibilidad?profesionalId=${encodeURIComponent(cita.profesionalId)}&fecha=${encodeURIComponent(cita.fecha)}&servicios=${encodeURIComponent(idsServicios)}`;
        const respuesta = await fetch(url, {
            headers: { 'Accept': 'application/json' }
        });
        const data = await respuesta.json();

        // Ignorar respuestas obsoletas si el usuario cambió servicios, profesional o fecha mientras cargaba
        if (requestId !== disponibilidadRequestId) {
            return;
        }

        if (!respuesta.ok || data.resultado === false) {
            const msgError = data.error || 'No fue posible consultar la disponibilidad para la selección actual.';
            actualizarEstadoDisponibilidadUI('error', msgError);
            mostrarAlerta(msgError, 'error', '.formulario');
            return;
        }

        if (data.status === 'empty' || !Array.isArray(data.intervalos) || data.intervalos.length === 0) {
            const diaIso = obtenerDiaSemanaIsoGuayaquil(cita.fecha);
            const msgVacio = [6, 7].includes(diaIso)
                ? 'No hay horarios disponibles en fin de semana para el profesional seleccionado.'
                : 'No hay horarios disponibles para la fecha y servicios seleccionados con este profesional.';
            actualizarEstadoDisponibilidadUI('empty', msgVacio);
            return;
        }

        intervalosDisponiblesActuales = data.intervalos;
        cita.duracion_total_minutos = parseInt(data.duracion_total_minutos, 10) || calcularDuracionTotalServicios();

        actualizarEstadoDisponibilidadUI(
            'ok',
            `Horarios disponibles (${cita.duracion_total_minutos} min de duración total):`
        );

        renderizarBotonesIntervalos(data.intervalos);
    } catch (error) {
        if (requestId !== disponibilidadRequestId) {
            return;
        }
        actualizarEstadoDisponibilidadUI('error', 'Error de conexión al consultar los horarios disponibles.');
    }
}

function renderizarBotonesIntervalos(intervalos) {
    const contenedor = document.querySelector('#contenedor-horarios-disponibles');
    if (!contenedor) return;
    contenedor.innerHTML = '';

    intervalos.forEach(intervalo => {
        const btn = document.createElement('BUTTON');
        btn.type = 'button';
        btn.classList.add('intervalo-btn');
        btn.dataset.inicio = intervalo.inicio;
        btn.dataset.fin = intervalo.fin;
        btn.dataset.duracion = String(intervalo.duracion_minutos || cita.duracion_total_minutos);
        btn.setAttribute('aria-pressed', 'false');
        btn.textContent = `${intervalo.inicio} - ${intervalo.fin}`;

        btn.addEventListener('click', function () {
            seleccionarIntervaloHorario(intervalo, btn);
        });

        contenedor.appendChild(btn);
    });
}

function seleccionarIntervaloHorario(intervalo, botonElemento) {
    const todos = document.querySelectorAll('#contenedor-horarios-disponibles .intervalo-btn');
    todos.forEach(b => {
        b.classList.remove('seleccionado');
        b.setAttribute('aria-pressed', 'false');
    });

    if (botonElemento) {
        botonElemento.classList.add('seleccionado');
        botonElemento.setAttribute('aria-pressed', 'true');
    }

    cita.hora = intervalo.inicio;
    cita.hora_fin = intervalo.fin;
    cita.duracion_total_minutos = parseInt(intervalo.duracion_minutos, 10) || calcularDuracionTotalServicios();

    const inputHora = document.querySelector('#hora');
    if (inputHora) {
        inputHora.value = intervalo.inicio;
    }
}

function sleccionarHora() {
    const inputHora = document.querySelector('#hora');
    if (!inputHora) return;

    inputHora.addEventListener('input', function (e) {
        const horaCita = String(e.target.value || '').trim();
        if (!horaCita) {
            invalidarHorarioSeleccionado();
            return;
        }

        if (intervalosDisponiblesActuales.length > 0) {
            const coincidencia = intervalosDisponiblesActuales.find(i => i.inicio === horaCita);
            if (!coincidencia) {
                e.target.value = '';
                invalidarHorarioSeleccionado();
                mostrarAlerta('Horario no válido o no disponible para el profesional seleccionado (10:00 a 18:00 horas)', 'error', '.formulario');
                return;
            }
            const btnMatch = document.querySelector(`#contenedor-horarios-disponibles .intervalo-btn[data-inicio="${horaCita}"]`);
            seleccionarIntervaloHorario(coincidencia, btnMatch);
            return;
        }

        const partes = horaCita.split(':');
        const hora = parseInt(partes[0], 10);
        const minutos = partes[1] ? parseInt(partes[1], 10) : 0;
        if (isNaN(hora) || hora < 10 || hora > 18 || (hora === 18 && minutos > 0)) {
            e.target.value = '';
            invalidarHorarioSeleccionado();
            mostrarAlerta('Horario no válido (atención de 10:00 a 18:00 horas)', 'error', '.formulario');
        } else {
            cita.hora = horaCita;
            const dur = calcularDuracionTotalServicios();
            const inicioMin = (hora * 60) + minutos;
            const finMin = inicioMin + dur;
            cita.duracion_total_minutos = dur;
            cita.hora_fin = dur > 0
                ? `${String(Math.floor(finMin / 60)).padStart(2, '0')}:${String(finMin % 60).padStart(2, '0')}`
                : '';
        }
    });
}

function mostrarAlerta(mensaje, tipo, elemento, desaparece = true) {
    const contenedorRef = document.querySelector(elemento);
    if (!contenedorRef) return;

    const alertaPrevia = contenedorRef.querySelector('.alerta');
    if (alertaPrevia) {
        alertaPrevia.remove();
    }

    const alerta = document.createElement('DIV');
    alerta.textContent = mensaje;
    alerta.classList.add('alerta');
    alerta.classList.add(tipo);

    contenedorRef.appendChild(alerta);
    if (desaparece) {
        setTimeout(() => {
            alerta.remove();
        }, 3000);
    }
}

function mostrarResumen() {
    const resumen = document.querySelector('.contenido-resumen');
    if (!resumen) return;

    while (resumen.firstChild) {
        resumen.removeChild(resumen.firstChild);
    }

    if (
        cita.servicios.length === 0 ||
        !cita.profesionalId ||
        !cita.fecha ||
        !cita.hora ||
        !cita.hora_fin
    ) {
        mostrarAlerta(
            'Faltan datos de servicios, profesional, fecha u horario disponible',
            'error',
            '.contenido-resumen',
            false
        );
        return;
    }

    const { nombre, profesionalNombre, fecha, hora, hora_fin, servicios } = cita;
    const duracionTotal = cita.duracion_total_minutos || calcularDuracionTotalServicios();
    const precioTotal = calcularPrecioTotalServicios();

    const headingServicios = document.createElement('H3');
    headingServicios.textContent = 'Resumen de Servicios';
    resumen.appendChild(headingServicios);

    servicios.forEach(servicio => {
        const { nombre: nombreSrv, precio } = servicio;
        const durServicio = parseInt(servicio.duracion_minutos, 10) || 30;

        const contenedorServicio = document.createElement('DIV');
        contenedorServicio.classList.add('contenedor-servicio');

        const textoServicio = document.createElement('P');
        textoServicio.textContent = nombreSrv;

        const duracionP = document.createElement('P');
        duracionP.innerHTML = `<span>Duración:</span> ${durServicio} min`;

        const precioServicio = document.createElement('P');
        precioServicio.innerHTML = `<span>Precio:</span> $ ${precio}`;

        contenedorServicio.appendChild(textoServicio);
        contenedorServicio.appendChild(duracionP);
        contenedorServicio.appendChild(precioServicio);

        resumen.appendChild(contenedorServicio);
    });

    const headingCita = document.createElement('H3');
    headingCita.textContent = 'Resumen de Cita';
    resumen.appendChild(headingCita);

    const nombreClienteP = document.createElement('P');
    nombreClienteP.innerHTML = `<span>Nombre:</span> ${nombre}`;

    const profesionalCitaP = document.createElement('P');
    profesionalCitaP.classList.add('resumen-profesional');
    profesionalCitaP.innerHTML = `<span>Profesional:</span> ${profesionalNombre}`;

    const fechaFormateada = formatearFechaEspanolGuayaquil(fecha);
    const fechaCitaP = document.createElement('P');
    fechaCitaP.classList.add('resumen-fecha');
    fechaCitaP.innerHTML = `<span>Fecha:</span> ${fechaFormateada}`;

    const horarioCitaP = document.createElement('P');
    horarioCitaP.classList.add('resumen-horario');
    horarioCitaP.innerHTML = `<span>Horario:</span> ${hora} - ${hora_fin} Horas`;

    const duracionTotalP = document.createElement('P');
    duracionTotalP.classList.add('resumen-duracion-total');
    duracionTotalP.innerHTML = `<span>Duración Total:</span> ${duracionTotal} min`;

    const precioTotalP = document.createElement('P');
    precioTotalP.classList.add('resumen-precio-total');
    precioTotalP.innerHTML = `<span>Total a Pagar:</span> $ ${precioTotal.toFixed(2)}`;

    const botonReservar = document.createElement('BUTTON');
    botonReservar.id = 'btn-reservar-cita';
    botonReservar.type = 'button';
    botonReservar.classList.add('boton');
    botonReservar.textContent = 'Reservar Cita';
    botonReservar.onclick = reservarCita;

    resumen.appendChild(nombreClienteP);
    resumen.appendChild(profesionalCitaP);
    resumen.appendChild(fechaCitaP);
    resumen.appendChild(horarioCitaP);
    resumen.appendChild(duracionTotalP);
    resumen.appendChild(precioTotalP);
    resumen.appendChild(botonReservar);
}

async function reservarCita() {
    if (enviandoReserva) {
        return;
    }

    const { fecha, hora, profesionalId, servicios, id } = cita;
    if (!profesionalId || !fecha || !hora || servicios.length === 0) {
        return;
    }

    enviandoReserva = true;
    const botonReservar = document.querySelector('#btn-reservar-cita') || document.querySelector('.contenido-resumen button.boton');
    if (botonReservar) {
        botonReservar.disabled = true;
        botonReservar.setAttribute('aria-busy', 'true');
        botonReservar.textContent = 'Reservando...';
    }

    const idServicios = servicios.map(servicio => servicio.id);
    const csrfInput = document.querySelector('#csrf_token');
    const csrfToken = csrfInput ? csrfInput.value : '';

    const datos = new FormData();
    datos.append('modo_reserva', 'profesional');
    datos.append('profesionalId', String(profesionalId));
    datos.append('usuarioId', id);
    datos.append('fecha', fecha);
    datos.append('hora', hora);
    datos.append('servicios', idServicios);
    datos.append('csrf_token', csrfToken);

    try {
        const url = `${location.origin}/api/citas`;
        const respuesta = await fetch(url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: datos
        });
        const resultado = await respuesta.json();

        if (respuesta.ok && resultado.resultado) {
            await cargarMisCitas();
            Swal.fire({
                icon: "success",
                title: "Cita Creada",
                text: "Tu cita fue creada correctamente",
                button: 'OK'
            }).then(() => {
                setTimeout(() => {
                    window.location.reload();
                }, 100);
            });
            return;
        }

        if (respuesta.status === 409 || resultado.status === 'conflict') {
            const msgConflicto = resultado.error || 'El horario seleccionado ya no está disponible. Por favor elige otro horario.';
            invalidarHorarioSeleccionado();

            if (resultado.codigo === 'profesional_inactivo') {
                await cargarProfesionalesCompatiblesRemoto();
            } else {
                await consultarDisponibilidad();
            }

            paso = 2;
            botonesPaginador();
            mostrarAlerta(msgConflicto, 'error', '.formulario', false);

            await Swal.fire({
                icon: "warning",
                title: "Horario no disponible",
                text: msgConflicto
            });
            return;
        }

        Swal.fire({
            icon: "error",
            title: "Error",
            text: resultado.error || "Hubo un error al guardar la cita!"
        });
    } catch (error) {
        Swal.fire({
            icon: "error",
            title: "Error",
            text: "Hubo un error al guardar la cita!"
        });
    } finally {
        enviandoReserva = false;
        if (botonReservar) {
            botonReservar.disabled = false;
            botonReservar.removeAttribute('aria-busy');
            botonReservar.textContent = 'Reservar Cita';
        }
    }
}

async function cargarMisCitas() {
    const estadoEl = document.querySelector('#estado-mis-citas');
    const listadoEl = document.querySelector('#listado-mis-citas');
    if (!estadoEl || !listadoEl) return;

    try {
        const res = await fetch(`${location.origin}/api/mis-citas`, {
            headers: { 'Accept': 'application/json' }
        });
        if (!res.ok) {
            estadoEl.textContent = 'No fue posible cargar tus citas registradas.';
            estadoEl.classList.remove('ocultar');
            return;
        }

        const data = await res.json();
        const citasCliente = Array.isArray(data.citas) ? data.citas : [];
        listadoEl.innerHTML = '';

        if (citasCliente.length === 0) {
            estadoEl.textContent = 'No tienes citas registradas actualmente.';
            estadoEl.classList.remove('ocultar');
            return;
        }

        estadoEl.textContent = '';
        estadoEl.classList.add('ocultar');

        citasCliente.forEach(item => {
            const li = document.createElement('LI');
            li.classList.add('mi-cita-item');
            li.dataset.citaId = String(item.id);

            const tieneProf = item.profesionalId !== null && item.profesionalId !== undefined && item.profesionalId !== '';
            const textoProf = tieneProf
                ? (item.profesional_nombre || `Profesional #${item.profesionalId}`)
                : 'Sin profesional asignado (cita histórica)';
            const textoHorario = (tieneProf && item.hora_inicio && item.hora_fin)
                ? `${item.hora_inicio} - ${item.hora_fin}${item.duracion_total_minutos ? ` (${item.duracion_total_minutos} min)` : ''}`
                : `${item.hora}`;

            const pFecha = document.createElement('P');
            pFecha.innerHTML = `Fecha: <span>${item.fecha}</span>`;

            const pProf = document.createElement('P');
            pProf.classList.add('mi-cita-profesional');
            pProf.innerHTML = `Profesional: <span>${textoProf}</span>`;

            const pHorario = document.createElement('P');
            pHorario.classList.add('mi-cita-horario');
            pHorario.innerHTML = `${tieneProf && item.hora_inicio && item.hora_fin ? 'Horario' : 'Hora'}: <span>${textoHorario}</span>`;

            li.appendChild(pFecha);
            li.appendChild(pProf);
            li.appendChild(pHorario);

            const hServicios = document.createElement('H3');
            hServicios.textContent = 'Servicios';
            li.appendChild(hServicios);

            if (Array.isArray(item.servicios)) {
                item.servicios.forEach(srv => {
                    const pSrv = document.createElement('P');
                    pSrv.classList.add('servicio');
                    const durTxt = srv.duracion_minutos ? ` (${srv.duracion_minutos} min)` : '';
                    pSrv.textContent = `${srv.nombre} ${srv.precio}${durTxt}`;
                    li.appendChild(pSrv);
                });
            }

            const pTotal = document.createElement('P');
            pTotal.classList.add('total');
            pTotal.innerHTML = `Total: <span>$ ${item.total_formateado || item.total}</span>`;
            li.appendChild(pTotal);

            if (item.puede_cancelar) {
                const btnCancelar = document.createElement('BUTTON');
                btnCancelar.type = 'button';
                btnCancelar.classList.add('boton-eliminar', 'btn-cancelar-cita');
                btnCancelar.dataset.citaId = String(item.id);
                btnCancelar.textContent = 'Cancelar Cita';
                btnCancelar.addEventListener('click', function () {
                    cancelarCitaCliente(item.id, btnCancelar);
                });
                li.appendChild(btnCancelar);
            }

            listadoEl.appendChild(li);
        });
    } catch (error) {
        estadoEl.textContent = 'No fue posible consultar tus citas en este momento.';
        estadoEl.classList.remove('ocultar');
    }
}

async function cancelarCitaCliente(citaId, botonElemento) {
    if (!citaId) return;
    if (botonElemento) {
        botonElemento.disabled = true;
        botonElemento.textContent = 'Cancelando...';
    }

    const csrfInput = document.querySelector('#csrf_token');
    const csrfToken = csrfInput ? csrfInput.value : '';

    const datos = new FormData();
    datos.append('id', String(citaId));
    datos.append('csrf_token', csrfToken);

    try {
        const res = await fetch(`${location.origin}/api/eliminar`, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: datos
        });
        const payload = await res.json();

        if (res.ok && payload.resultado) {
            await cargarMisCitas();
            if (cita.profesionalId && cita.fecha && cita.servicios.length > 0) {
                await consultarDisponibilidad();
            }
            return;
        }

        mostrarAlerta(payload.error || 'No fue posible cancelar la cita.', 'error', '#mis-citas');
    } catch (error) {
        mostrarAlerta('Error de conexión al cancelar la cita.', 'error', '#mis-citas');
    } finally {
        if (botonElemento) {
            botonElemento.disabled = false;
            botonElemento.textContent = 'Cancelar Cita';
        }
    }
}
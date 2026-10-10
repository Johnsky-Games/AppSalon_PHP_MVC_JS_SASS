/**
 * Suite E2E en Navegador Real Headless (Chrome/Edge) para AppSalon — Entrega 1 y Fase 2A
 * Verifica con JavaScript habilitado:
 * 1. Flujo Administrativo CRUD de Servicios (/servicios, /servicios/crear, /servicios/actualizar, /servicios/eliminar).
 * 2. Carga de /api/servicios reflejando los cambios del catálogo en la vista de reserva (/cita), selección y deselección visual (.seleccionado).
 * 3. Navegación entre pasos 1, 2 y 3 (#paso-1, #paso-2, #paso-3) y conservación de estado en memoria/DOM.
 * 4. Validación interactiva de fecha (bloqueo fines de semana) y hora (bloqueo fuera de 10:00 a 18:00).
 * 5. Resumen, envío real de cita vía fetch POST con CSRF, respuesta JSON {resultado: true}, alerta SweetAlert2.
 * 6. Ausencia total de errores de consola y solicitudes de red fallidas.
 */

const path = require('path');
const fs = require('fs');

// Cargar puppeteer-core (prioriza PUPPETEER_CORE_PATH o instalación local en node_modules)
const defaultCandidates = [
    process.env.PUPPETEER_CORE_PATH,
    path.resolve(__dirname, '../node_modules/puppeteer-core'),
    path.resolve(__dirname, 'node_modules/puppeteer-core')
].filter(Boolean);

let puppeteer = null;
for (const cand of defaultCandidates) {
    if (fs.existsSync(cand)) {
        try {
            puppeteer = require(cand);
            break;
        } catch (e) {}
    }
}
if (!puppeteer) {
    try {
        puppeteer = require('puppeteer-core');
    } catch (e) {
        throw new Error('No se pudo encontrar puppeteer-core. Ejecuta "npm install --save-dev puppeteer-core" o define la variable de entorno PUPPETEER_CORE_PATH.');
    }
}

const BASE_URL = process.env.APP_URL || 'http://localhost:3000';
const defaultChromePaths = [
    process.env.CHROME_BIN,
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
    '/usr/bin/google-chrome',
    '/usr/bin/chromium-browser'
].filter(Boolean);

const CHROME_PATH = process.env.CHROME_BIN || defaultChromePaths.find(p => fs.existsSync(p)) || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';

/**
 * Calcula fechas futuras dinámicas para la prueba:
 * - Un sábado futuro para verificar el rechazo de fines de semana.
 * - Un día laborable futuro (lunes a viernes) para la reserva válida y su persistencia.
 * Si se definen TEST_REJECT_WEEKEND_DATE y TEST_VALID_BOOKING_DATE en el entorno, se usan esas fechas compartidas.
 */
function calcularFechasPrueba() {
    let weekendDate = process.env.TEST_REJECT_WEEKEND_DATE;
    let validDate = process.env.TEST_VALID_BOOKING_DATE;

    if (!weekendDate || !validDate) {
        const today = new Date();
        for (let i = 1; i <= 14; i++) {
            const d = new Date(today.getFullYear(), today.getMonth(), today.getDate() + i);
            const yyyy = d.getFullYear();
            const mm = String(d.getMonth() + 1).padStart(2, '0');
            const dd = String(d.getDate()).padStart(2, '0');
            const formatted = `${yyyy}-${mm}-${dd}`;
            const utcDay = new Date(formatted).getUTCDay();

            if (utcDay === 6 && !weekendDate) { // Sábado
                weekendDate = formatted;
            }
            if (utcDay >= 1 && utcDay <= 5 && !validDate) { // Lunes a Viernes
                validDate = formatted;
            }
        }
    }

    return { weekendDate, validDate };
}

async function runBrowserTests() {
    const { weekendDate, validDate } = calcularFechasPrueba();

    console.log('======================================================================');
    console.log('SUITE DE PRUEBAS EN NAVEGADOR WEB (JAVASCRIPT HABILITADO) — APPSALON');
    console.log('URL Base:', BASE_URL);
    console.log('Binario Navegador:', CHROME_PATH);
    console.log('Fechas dinámicas calculadas:');
    console.log(' - Sábado (rechazo FDS):', weekendDate);
    console.log(' - Día laborable (reserva válida):', validDate);
    console.log('======================================================================\n');

    const consoleErrors = [];
    const failedRequests = [];
    const testResults = [];

    function recordTest(id, name, success, details) {
        testResults.push({ id, name, success, details });
        const tag = success ? '[OK]' : '[FALLO]';
        console.log(`${tag} ${id}: ${name}`);
        if (details) {
            console.log(`     Detalles: ${details}`);
        }
    }

    const browser = await puppeteer.launch({
        executablePath: CHROME_PATH,
        headless: 'new',
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage',
            '--window-size=1280,800'
        ]
    });

    const page = await browser.newPage();
    await page.setViewport({ width: 1280, height: 800 });

    // Monitorización estricta de consola y red
    page.on('console', msg => {
        if (msg.type() === 'error') {
            const locUrl = (msg.location() && msg.location().url) ? msg.location().url : '';
            const text = msg.text() || '';
            if (locUrl.includes('favicon.ico') || text.includes('favicon.ico')) return;
            consoleErrors.push(`[Console Error] ${text} @ ${locUrl || 'inline'}`);
        }
    });

    page.on('requestfailed', request => {
        if (request.url().endsWith('favicon.ico')) return;
        failedRequests.push(`[Network Failed] ${request.method()} ${request.url()} - ${request.failure() ? request.failure().errorText : 'Failed'}`);
    });

    try {
        // ------------------------------------------------------------------
        // BLOQUE ADMINISTRATIVO (FASE 2A): CRUD de Catálogo de Servicios
        // ------------------------------------------------------------------
        console.log('>>> [FASE 2A - ADMIN] Autenticación de Administrador y CRUD de Servicios...');
        await page.goto(`${BASE_URL}/`, { waitUntil: 'networkidle0' });

        await page.waitForSelector('input[name="email"]', { timeout: 5000 });
        await page.type('input[name="email"]', 'admin@appsalon.com');
        await page.type('input[name="password"]', 'Password123!');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('input[type="submit"]')
        ]);

        if (!page.url().includes('/admin')) {
            throw new Error(`Se esperaba redirección de admin a /admin, obtenido: ${page.url()}`);
        }

        // 1. Navegar al listado de servicios (/servicios)
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('a[href="/servicios"]')
        ]);

        const itemsIniciales = await page.$$('ul.servicios li');
        if (itemsIniciales.length !== 3) {
            throw new Error(`Se esperaban 3 servicios iniciales en /servicios, encontrados: ${itemsIniciales.length}`);
        }
        recordTest('ADMIN-01', 'Acceso de administrador a /servicios y listado inicial del catálogo', true,
            `3 servicios listados en /servicios.`);

        // 2. Crear servicio (/servicios/crear): probar rechazo de entrada inválida y creación válida
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('a[href="/servicios/crear"]')
        ]);

        // 2.a Envío inválido (nombre vacío y precio negativo)
        await page.type('#precio', '-25');
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('form.formulario input[type="submit"]')
        ]);

        const alertasErrorCrear = await page.$$eval('.alerta.error', els => els.map(e => e.textContent.trim()));
        if (alertasErrorCrear.length < 2) {
            throw new Error(`Se esperaban alertas de validación ante entrada inválida en /servicios/crear, obtenidas: ${JSON.stringify(alertasErrorCrear)}`);
        }

        // 2.b Envío válido ("Masaje Capilar Relax", "95.50")
        await page.$eval('#nombre', el => el.value = '');
        await page.$eval('#precio', el => el.value = '');
        await page.type('#nombre', 'Masaje Capilar Relax');
        await page.type('#precio', '95.50');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('form.formulario input[type="submit"]')
        ]);

        if (!page.url().endsWith('/servicios')) {
            throw new Error(`Tras crear servicio válido debía redirigir a /servicios, URL actual: ${page.url()}`);
        }

        const textoListadoTrasCrear = await page.$eval('ul.servicios', el => el.textContent);
        if (!textoListadoTrasCrear.includes('Masaje Capilar Relax') || !textoListadoTrasCrear.includes('95.50')) {
            throw new Error('El servicio creado "Masaje Capilar Relax" ($95.50) no aparece en /servicios.');
        }
        recordTest('ADMIN-02', 'Validación de entradas inválidas y creación de nuevo servicio en /servicios/crear', true,
            `Entradas inválidas rechazadas con alertas (${alertasErrorCrear.length}); 'Masaje Capilar Relax' ($95.50) creado.`);

        // 3. Actualizar servicio (/servicios/actualizar)
        const hrefActualizar = await page.evaluate(() => {
            const items = Array.from(document.querySelectorAll('ul.servicios li'));
            const target = items.find(li => li.textContent.includes('Masaje Capilar Relax'));
            const link = target ? target.querySelector('a[href*="/servicios/actualizar"]') : null;
            return link ? link.getAttribute('href') : null;
        });

        if (!hrefActualizar) {
            throw new Error('No se encontró enlace de actualización para "Masaje Capilar Relax".');
        }

        await page.goto(`${BASE_URL}${hrefActualizar}`, { waitUntil: 'networkidle0' });
        await page.$eval('#nombre', el => el.value = '');
        await page.$eval('#precio', el => el.value = '');
        await page.type('#nombre', 'Masaje Capilar Premium');
        await page.type('#precio', '115.00');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('form.formulario input[type="submit"]')
        ]);

        const textoListadoTrasActualizar = await page.$eval('ul.servicios', el => el.textContent);
        if (!textoListadoTrasActualizar.includes('Masaje Capilar Premium') || !textoListadoTrasActualizar.includes('115.00')) {
            throw new Error('El servicio actualizado "Masaje Capilar Premium" ($115.00) no se reflejó en /servicios.');
        }
        recordTest('ADMIN-03', 'Actualización de servicio existente en /servicios/actualizar', true,
            `'Masaje Capilar Relax' actualizado a 'Masaje Capilar Premium' ($115.00).`);

        // 4. Crear servicio temporal y eliminarlo (/servicios/eliminar)
        await page.goto(`${BASE_URL}/servicios/crear`, { waitUntil: 'networkidle0' });
        await page.type('#nombre', 'Servicio Temporal Borrar');
        await page.type('#precio', '45.00');
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('form.formulario input[type="submit"]')
        ]);

        const existeTemporalAntes = await page.$eval('ul.servicios', el => el.textContent.includes('Servicio Temporal Borrar'));
        if (!existeTemporalAntes) {
            throw new Error('No se encontró "Servicio Temporal Borrar" antes de probar eliminación.');
        }

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.evaluate(() => {
                const items = Array.from(document.querySelectorAll('ul.servicios li'));
                const target = items.find(li => li.textContent.includes('Servicio Temporal Borrar'));
                const form = target ? target.querySelector('form[action="/servicios/eliminar"]') : null;
                if (!form) throw new Error('Formulario eliminar no encontrado');
                form.submit();
            })
        ]);

        const existeTemporalDespues = await page.$eval('ul.servicios', el => el.textContent.includes('Servicio Temporal Borrar'));
        if (existeTemporalDespues) {
            throw new Error('El servicio "Servicio Temporal Borrar" sigue apareciendo tras ejecutar /servicios/eliminar.');
        }
        recordTest('ADMIN-04', 'Eliminación de servicio con CSRF en /servicios/eliminar', true,
            `'Servicio Temporal Borrar' eliminado; catálogo conserva 4 servicios activos.`);

        // Cerrar sesión de administrador mediante POST /logout
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('.barra form[action="/logout"] button, .barra form[action="/logout"] input[type="submit"]')
        ]);

        // ------------------------------------------------------------------
        // PASO PREVIO CLIENTE: Autenticación en Navegador
        // ------------------------------------------------------------------
        console.log('\n>>> [PRE-REQUISITO] Autenticación de cliente en interfaz de Login...');
        await page.goto(`${BASE_URL}/`, { waitUntil: 'networkidle0' });

        await page.waitForSelector('input[name="email"]', { timeout: 5000 });
        await page.type('input[name="email"]', 'carlos@correo.com');
        await page.type('input[name="password"]', 'Password123!');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('input[type="submit"]')
        ]);

        const currentUrl = page.url();
        const isLoggedIn = currentUrl.includes('/cita');
        if (!isLoggedIn) {
            throw new Error(`Fallo en login interactivo: URL actual es ${currentUrl}, se esperaba /cita`);
        }
        recordTest('PRE-01', 'Login interactivo de cliente y redirección autorizada a /cita', true, `URL: ${currentUrl}`);

        // ------------------------------------------------------------------
        // RECORRIDO 1: Carga de Servicios, Selección y Deselección Visual
        // ------------------------------------------------------------------
        console.log('\n>>> [RECORRIDO 1] Carga de servicios desde /api/servicios (incluyendo cambios CRUD), selección y deselección visual...');

        await page.waitForSelector('#servicios .servicio', { timeout: 5000 });
        const serviciosCards = await page.$$('#servicios .servicio');
        const cantidadServicios = serviciosCards.length;

        if (cantidadServicios !== 4) {
            throw new Error(`Se esperaban exactamente 4 servicios en el catálogo de reserva tras el CRUD administrativo, encontrados: ${cantidadServicios}`);
        }

        const nombresEnReserva = await page.$$eval('#servicios .servicio .nombre-servicio', els => els.map(e => e.textContent.trim()));
        if (!nombresEnReserva.includes('Masaje Capilar Premium') || nombresEnReserva.includes('Servicio Temporal Borrar')) {
            throw new Error(`El catálogo en /cita no refleja el estado actualizado por el CRUD: ${JSON.stringify(nombresEnReserva)}`);
        }

        // Obtener datos del primer servicio y del servicio creado/actualizado en el CRUD
        const primerServicio = serviciosCards[0];
        const servicioId1 = await primerServicio.evaluate(el => el.dataset.idServicio);
        const servicioNombre1 = await primerServicio.evaluate(el => el.querySelector('.nombre-servicio')?.textContent);

        // 1.1 Clic para seleccionar el servicio 1
        await primerServicio.click();
        const tieneClaseSeleccionado1 = await primerServicio.evaluate(el => el.classList.contains('seleccionado'));
        if (!tieneClaseSeleccionado1) {
            throw new Error(`El servicio ${servicioNombre1} (ID ${servicioId1}) no recibió la clase .seleccionado al hacer clic.`);
        }

        // 1.2 Clic para deseleccionar el servicio 1
        await primerServicio.click();
        const mantieneClaseTrasDeseleccion = await primerServicio.evaluate(el => el.classList.contains('seleccionado'));
        if (mantieneClaseTrasDeseleccion) {
            throw new Error(`El servicio ${servicioNombre1} no removió la clase .seleccionado tras un segundo clic.`);
        }

        // 1.3 Volver a seleccionar servicio 1 y seleccionar también el cuarto servicio ("Masaje Capilar Premium")
        await primerServicio.click();
        const cuartoServicio = serviciosCards[3];
        const servicioId4 = await cuartoServicio.evaluate(el => el.dataset.idServicio);
        await cuartoServicio.click();

        const s1Seleccionado = await primerServicio.evaluate(el => el.classList.contains('seleccionado'));
        const s4Seleccionado = await cuartoServicio.evaluate(el => el.classList.contains('seleccionado'));

        if (!s1Seleccionado || !s4Seleccionado) {
            throw new Error('Fallo al seleccionar múltiples servicios en el DOM.');
        }

        recordTest('REC-01', 'Carga asíncrona de /api/servicios reflejando CRUD y alternancia de .seleccionado', true,
            `${cantidadServicios} servicios renderizados (incluye 'Masaje Capilar Premium'); IDs seleccionados: [${servicioId1}, ${servicioId4}]`);

        // ------------------------------------------------------------------
        // RECORRIDO 2: Navegación entre los Tres Pasos y Conservación de Estado
        // ------------------------------------------------------------------
        console.log('\n>>> [RECORRIDO 2] Navegación entre pasos 1, 2 y 3 con conservación de datos...');

        const paso1Visible = await page.$eval('#paso-1', el => el.classList.contains('mostrar'));
        const tab1Activo = await page.$eval('.tabs button[data-paso="1"]', el => el.classList.contains('actual'));
        const btnAnteriorOculto = await page.$eval('#anterior', el => el.classList.contains('ocultar'));

        if (!paso1Visible || !tab1Activo || !btnAnteriorOculto) {
            throw new Error('Estado inicial del paginador/tabs en Paso 1 inconsistente.');
        }

        await page.click('#siguiente');
        await page.waitForFunction(() => document.querySelector('#paso-2.mostrar') !== null);

        const paso2Visible = await page.$eval('#paso-2', el => el.classList.contains('mostrar'));
        const tab2Activo = await page.$eval('.tabs button[data-paso="2"]', el => el.classList.contains('actual'));
        if (!paso2Visible || !tab2Activo) {
            throw new Error('Fallo al navegar a Paso 2 con botón #siguiente.');
        }

        const nombrePrellenado = await page.$eval('#nombre', el => el.value);
        if (!nombrePrellenado || !nombrePrellenado.toLowerCase().includes('carlos')) {
            throw new Error(`Nombre de cliente no prellenado en Paso 2: '${nombrePrellenado}'`);
        }

        await page.click('#anterior');
        await page.waitForFunction(() => document.querySelector('#paso-1.mostrar') !== null);

        const s1SigueSeleccionado = await primerServicio.evaluate(el => el.classList.contains('seleccionado'));
        const s4SigueSeleccionado = await cuartoServicio.evaluate(el => el.classList.contains('seleccionado'));

        if (!s1SigueSeleccionado || !s4SigueSeleccionado) {
            throw new Error('Los servicios seleccionados perdieron la clase .seleccionado al retroceder a Paso 1.');
        }

        await page.click('.tabs button[data-paso="2"]');
        await page.waitForFunction(() => document.querySelector('#paso-2.mostrar') !== null);

        recordTest('REC-02', 'Navegación fluida por paginador y tabs con preservación de estado en cliente', true,
            `Paso 1 -> Paso 2 -> Paso 1 -> Paso 2 comprobado; nombre prellenado: '${nombrePrellenado}'; selección preservada.`);

        // ------------------------------------------------------------------
        // RECORRIDO 3: Validación Interactiva de Fecha y Horario en Cliente
        // ------------------------------------------------------------------
        console.log('\n>>> [RECORRIDO 3] Validación interactiva de fecha y horario en cliente...');

        await page.evaluate((sabado) => {
            const fechaInput = document.querySelector('#fecha');
            fechaInput.value = sabado;
            fechaInput.dispatchEvent(new Event('input', { bubbles: true }));
        }, weekendDate);

        await page.waitForSelector('.formulario .alerta.error', { timeout: 3000 });
        const textoAlertaFDS = await page.$eval('.formulario .alerta.error', el => el.textContent);
        const fechaLimpiadaFDS = await page.$eval('#fecha', el => el.value);

        if (!textoAlertaFDS.includes('fin de semana')) {
            throw new Error(`Texto de alerta inesperado ante fin de semana: '${textoAlertaFDS}'`);
        }
        if (fechaLimpiadaFDS !== '') {
            throw new Error(`El campo de fecha no se limpió ante fin de semana, valor: '${fechaLimpiadaFDS}'`);
        }

        await page.evaluate(() => {
            const horaInput = document.querySelector('#hora');
            horaInput.value = '08:30';
            horaInput.dispatchEvent(new Event('input', { bubbles: true }));
        });

        await page.waitForSelector('.formulario .alerta.error', { timeout: 3000 });
        const textoAlertaHora = await page.$eval('.formulario .alerta.error', el => el.textContent);
        const horaLimpiada = await page.$eval('#hora', el => el.value);

        if (!textoAlertaHora.includes('10:00 a 18:00')) {
            throw new Error(`Texto de alerta inesperado ante hora fuera de rango: '${textoAlertaHora}'`);
        }
        if (horaLimpiada !== '') {
            throw new Error(`El campo de hora no se limpió ante horario inválido, valor: '${horaLimpiada}'`);
        }

        await page.evaluate((laborable) => {
            const fechaInput = document.querySelector('#fecha');
            fechaInput.value = laborable;
            fechaInput.dispatchEvent(new Event('input', { bubbles: true }));

            const horaInput = document.querySelector('#hora');
            horaInput.value = '11:30';
            horaInput.dispatchEvent(new Event('input', { bubbles: true }));
        }, validDate);

        const fechaFinalValida = await page.$eval('#fecha', el => el.value);
        const horaFinalValida = await page.$eval('#hora', el => el.value);

        if (fechaFinalValida !== validDate || horaFinalValida !== '11:30') {
            throw new Error(`Valores válidos no retenidos en inputs: fecha='${fechaFinalValida}' (esperada='${validDate}'), hora='${horaFinalValida}'`);
        }

        recordTest('REC-03', 'Validación interactiva de restricciones en fecha (no FDS) y horario (10:00-18:00)', true,
            `Fines de semana rechazados con alerta (sábado probado: ${weekendDate}); horas no comerciales rechazadas; fecha válida '${validDate}' y hora '11:30' aceptadas.`);

        // ------------------------------------------------------------------
        // RECORRIDO 4: Resumen, Envío Real vía Fetch y Alerta SweetAlert2
        // ------------------------------------------------------------------
        console.log('\n>>> [RECORRIDO 4] Resumen de reserva, envío real vía fetch POST y SweetAlert2...');

        await page.click('#siguiente');
        await page.waitForFunction(() => document.querySelector('#paso-3.mostrar') !== null);

        const headingServicios = await page.$eval('.contenido-resumen h3', el => el.textContent);
        if (!headingServicios.includes('Resumen de Servicios')) {
            throw new Error(`Encabezado de servicios en resumen no encontrado: '${headingServicios}'`);
        }

        const serviciosEnResumen = await page.$$('.contenido-resumen .contenedor-servicio');
        if (serviciosEnResumen.length !== 2) {
            throw new Error(`Se esperaban 2 servicios en el resumen, encontrados: ${serviciosEnResumen.length}`);
        }

        const botonReservar = await page.$('.contenido-resumen button.boton');
        if (!botonReservar) {
            throw new Error('Botón "Reservar Cita" no encontrado en el DOM del resumen.');
        }

        const [apiResponse] = await Promise.all([
            page.waitForResponse(response => response.url().includes('/api/citas') && response.request().method() === 'POST'),
            botonReservar.click()
        ]);

        const apiStatus = apiResponse.status();
        const apiJson = await apiResponse.json();

        if (apiStatus !== 200 || !apiJson.resultado) {
            throw new Error(`Respuesta de API de reserva no exitosa: HTTP ${apiStatus}, cuerpo: ${JSON.stringify(apiJson)}`);
        }

        const citaIdCreada = apiJson.id || (apiJson.resultado && apiJson.resultado.id);

        await page.waitForSelector('.swal2-popup', { timeout: 5000 });
        const swalTitle = await page.$eval('.swal2-title', el => el.textContent);
        const swalIcon = await page.$eval('.swal2-icon.swal2-success', el => el !== null);

        if (!swalTitle.includes('Cita Creada') || !swalIcon) {
            throw new Error(`Modal SweetAlert2 inesperado: título='${swalTitle}', iconoExito=${swalIcon}`);
        }

        recordTest('REC-04', 'Renderizado de resumen, envío asíncrono con CSRF, respuesta 200 JSON y alerta SweetAlert2', true,
            `Resumen validado; POST /api/citas exitoso (id: ${citaIdCreada}); SweetAlert2 ('${swalTitle}') desplegado en pantalla.`);

        // Crear una segunda cita temporal para verificar su visualización y eliminación desde /admin (/api/eliminar)
        const csrfTokenCliente = await page.$eval('#csrf_token', el => el.value);
        const tempCitaRes = await page.evaluate(async ({ fecha, servicioId, csrf }) => {
            const fd = new FormData();
            fd.append('fecha', fecha);
            fd.append('hora', '15:00');
            fd.append('servicios', String(servicioId));
            fd.append('csrf_token', csrf);
            const r = await fetch('/api/citas', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf },
                body: fd
            });
            return { status: r.status, body: await r.json() };
        }, { fecha: validDate, servicioId: servicioId1, csrf: csrfTokenCliente });

        const citaTemporalId = tempCitaRes.body && tempCitaRes.body.resultado ? tempCitaRes.body.resultado.id : null;
        if (tempCitaRes.status !== 200 || !citaTemporalId) {
            throw new Error(`No se pudo crear la cita temporal para prueba de eliminación en /admin: ${JSON.stringify(tempCitaRes)}`);
        }

        // Cerrar sesión de cliente y autenticar como Administrador para verificar /admin y /api/eliminar
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.evaluate(() => {
                const logoutForm = document.querySelector('.barra form[action="/logout"]');
                if (!logoutForm) throw new Error('Formulario /logout no encontrado');
                logoutForm.submit();
            })
        ]);

        console.log('\n>>> [FASE 2B - ADMIN CITAS] Consulta de citas por fecha en /admin y eliminación en /api/eliminar...');
        await page.waitForSelector('input[name="email"]', { timeout: 5000 });
        await page.type('input[name="email"]', 'admin@appsalon.com');
        await page.type('input[name="password"]', 'Password123!');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('input[type="submit"]')
        ]);

        if (!page.url().includes('/admin')) {
            throw new Error(`Se esperaba redirección a /admin tras login de administrador, obtenido: ${page.url()}`);
        }

        // Filtrar citas por fecha usando el selector #fecha (activa buscador.js -> ?fecha=YYYY-MM-DD)
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.evaluate((fechaBusqueda) => {
                const inputFecha = document.querySelector('#fecha');
                inputFecha.value = fechaBusqueda;
                inputFecha.dispatchEvent(new Event('input', { bubbles: true }));
            }, validDate)
        ]);

        const fechaEnInputAdmin = await page.$eval('#fecha', el => el.value);
        if (!decodeURIComponent(page.url()).includes(validDate) || fechaEnInputAdmin !== validDate) {
            throw new Error(`El buscador de /admin no aplicó la fecha ${validDate}, URL actual: ${page.url()}, input: ${fechaEnInputAdmin}`);
        }

        const textoCitasAdminAntes = await page.$eval('.citas-admin', el => el.textContent);
        if (
            !textoCitasAdminAntes.includes('Carlos Mendoza') ||
            !textoCitasAdminAntes.includes('Masaje Capilar Premium 115.00') ||
            !textoCitasAdminAntes.includes('$ 195')
        ) {
            throw new Error(`La vista /admin?fecha=${validDate} no mostró los detalles esperados de la cita reservada: ${textoCitasAdminAntes}`);
        }

        // Eliminar la cita temporal desde /admin mediante su formulario POST /api/eliminar
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.evaluate((idBorrar) => {
                const inputId = document.querySelector(`form[action="/api/eliminar"] input[name="id"][value="${idBorrar}"]`);
                if (!inputId || !inputId.form) {
                    throw new Error(`No se encontró formulario de eliminación para la cita ID ${idBorrar} en /admin`);
                }
                inputId.form.submit();
            }, citaTemporalId)
        ]);

        const existeCitaTemporalTrasBorrar = await page.$(`form[action="/api/eliminar"] input[name="id"][value="${citaTemporalId}"]`);
        const sigueCitaPrincipal = await page.$(`form[action="/api/eliminar"] input[name="id"][value="${citaIdCreada}"]`);
        if (existeCitaTemporalTrasBorrar !== null || sigueCitaPrincipal === null) {
            throw new Error(`Estado inesperado tras eliminar cita temporal ${citaTemporalId}: temporalSigue=${existeCitaTemporalTrasBorrar !== null}, principalSigue=${sigueCitaPrincipal !== null}`);
        }

        recordTest('ADMIN-05', 'Consulta administrativa por fecha en /admin y eliminación de cita con CSRF en /api/eliminar', true,
            `Citas consultadas en /admin?fecha=${validDate}; cita temporal ID ${citaTemporalId} eliminada; cita principal ID ${citaIdCreada} ($195) conservada.`);

        // ------------------------------------------------------------------
        // BLOQUE FASE 2C (AUTH-01): Registro, Login no confirmado, Reenvío y Olvidé
        // ------------------------------------------------------------------
        console.log('\n>>> [FASE 2C - AUTH] Ciclo en navegador de logout, registro (/crear-cuenta), rechazo sin confirmar, /reenviar-confirmacion y /olvide...');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.evaluate(() => {
                const logoutForm = document.querySelector('.barra form[action="/logout"]');
                if (!logoutForm) throw new Error('Formulario /logout no encontrado en /admin');
                logoutForm.submit();
            })
        ]);

        await page.goto(`${BASE_URL}/crear-cuenta`, { waitUntil: 'networkidle0' });
        await page.type('#nombre', 'Elena');
        await page.type('#apellido', 'Rojas');
        await page.type('#telefono', '3157654321');
        await page.type('#email', 'elena.rojas@correo.com');
        await page.type('#password', 'ClaveSegura2026');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('form.formulario input[type="submit"]')
        ]);

        if (!page.url().includes('/mensaje')) {
            throw new Error(`El registro en /crear-cuenta debía redirigir a /mensaje, obtenido: ${page.url()}`);
        }

        // Intentar iniciar sesión antes de confirmar la cuenta -> debe mostrar alerta de error unificada
        await page.goto(`${BASE_URL}/`, { waitUntil: 'networkidle0' });
        await page.type('#email', 'elena.rojas@correo.com');
        await page.type('#password', 'ClaveSegura2026');
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('form.formulario input[type="submit"]')
        ]);

        const alertaNoConfirmado = await page.$$eval('.alerta.error', els => els.map(e => e.textContent.trim()).join(' | '));
        if (!alertaNoConfirmado.includes('Credenciales incorrectas o la cuenta no ha sido verificada')) {
            throw new Error(`Se esperaba alerta unificada al intentar login sin confirmar, obtenido: ${alertaNoConfirmado}`);
        }

        // Solicitar reenvío de confirmación (/reenviar-confirmacion)
        await page.goto(`${BASE_URL}/reenviar-confirmacion`, { waitUntil: 'networkidle0' });
        await page.type('#email', 'elena.rojas@correo.com');
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('form.formulario input[type="submit"]')
        ]);

        const alertaReenvio = await page.$$eval('.alerta.exito', els => els.map(e => e.textContent.trim()).join(' | '));
        if (!alertaReenvio.includes('Si la cuenta existe y está pendiente de confirmación')) {
            throw new Error(`Se esperaba mensaje genérico en /reenviar-confirmacion, obtenido: ${alertaReenvio}`);
        }

        // Solicitar recuperación de contraseña (/olvide)
        await page.goto(`${BASE_URL}/olvide`, { waitUntil: 'networkidle0' });
        await page.type('input[name="email"]', 'carlos@correo.com');
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('form.formulario input[type="submit"]')
        ]);

        const alertaOlvide = await page.$$eval('.alerta.exito', els => els.map(e => e.textContent.trim()).join(' | '));
        if (!alertaOlvide.includes('Si el correo electrónico está registrado')) {
            throw new Error(`Se esperaba mensaje genérico en /olvide, obtenido: ${alertaOlvide}`);
        }

        recordTest('AUTH-01', 'Ciclo de autenticación y ciclo de vida de cuenta (/logout, /crear-cuenta, login no confirmado, /reenviar-confirmacion, /olvide)', true,
            `Registro redirigió a /mensaje; login sin confirmar rechazado con mensaje unificado; /reenviar-confirmacion y /olvide respondieron sin enumeración.`);

        // ------------------------------------------------------------------
        // RECORRIDO 5: Ausencia de Errores de Consola y Fallos de Red
        // ------------------------------------------------------------------
        console.log('\n>>> [RECORRIDO 5] Verificación de errores de consola y peticiones fallidas...');

        const hayErroresConsola = consoleErrors.length > 0;
        const hayPeticionesFallidas = failedRequests.length > 0;

        if (hayErroresConsola) {
            console.error('Errores de consola detectados:', consoleErrors);
        }
        if (hayPeticionesFallidas) {
            console.error('Peticiones fallidas detectadas:', failedRequests);
        }

        const limpiaDeErrores = !hayErroresConsola && !hayPeticionesFallidas;
        recordTest('REC-05', 'Ausencia estricta de errores de consola y peticiones de red fallidas', limpiaDeErrores,
            `Errores consola: ${consoleErrors.length}, Solicitudes fallidas: ${failedRequests.length}`);

        if (!limpiaDeErrores) {
            throw new Error('Se detectaron errores en consola o solicitudes de red fallidas durante la ejecución interactiva.');
        }

        const report = {
            timestamp: new Date().toISOString(),
            navegador: 'Chrome Headless (Puppeteer-Core)',
            citaCreadaId: citaIdCreada,
            citaTemporalEliminadaId: citaTemporalId,
            fechaReservaEsperada: validDate,
            fechaRechazoFinDeSemana: weekendDate,
            testResults,
            consoleErrors,
            failedRequests
        };

        fs.writeFileSync(
            path.resolve(__dirname, 'browser_test_report.json'),
            JSON.stringify(report, null, 2),
            'utf-8'
        );

        console.log('\n======================================================================');
        console.log('TODAS LAS PRUEBAS EN NAVEGADOR WEB FUERON SUPERADAS SATISFACTORIAMENTE');
        console.log('======================================================================\n');

    } finally {
        await browser.close();
    }
}

runBrowserTests()
    .then(() => process.exit(0))
    .catch(err => {
        console.error('\n[ERROR EN PRUEBAS DE NAVEGADOR]:', err.message);
        process.exit(1);
    });

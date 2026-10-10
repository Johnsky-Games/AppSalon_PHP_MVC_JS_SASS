/**
 * Suite E2E en Navegador Real Headless (Chrome/Edge) para AppSalon — Entrega 1
 * Verifica con JavaScript habilitado:
 * 1. Carga de /api/servicios, renderizado en DOM, selección y deselección visual (.seleccionado).
 * 2. Navegación entre pasos 1, 2 y 3 (#paso-1, #paso-2, #paso-3) y conservación de estado en memoria/DOM.
 * 3. Validación interactiva de fecha (bloqueo fines de semana) y hora (bloqueo fuera de 10:00 a 18:00).
 * 4. Resumen, envío real de cita vía fetch POST con CSRF, respuesta JSON {resultado: true}, alerta SweetAlert2.
 * 5. Ausencia total de errores de consola y solicitudes de red fallidas.
 */

const path = require('path');
const fs = require('fs');

// Cargar puppeteer-core de forma robusta
const defaultCandidates = [
    process.env.PUPPETEER_CORE_PATH,
    path.resolve(__dirname, '../node_modules/puppeteer-core'),
    path.resolve(__dirname, 'node_modules/puppeteer-core'),
    'C:/Users/jonat/.gemini/antigravity/brain/e9788218-5667-449d-8c01-3245505116ca/scratch/browser_env/node_modules/puppeteer-core'
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
        throw new Error('No se pudo encontrar puppeteer-core. Instálalo o define PUPPETEER_CORE_PATH.');
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

const CHROME_PATH = defaultChromePaths.find(p => fs.existsSync(p)) || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';

async function runBrowserTests() {
    console.log('======================================================================');
    console.log('SUITE DE PRUEBAS EN NAVEGADOR WEB (JAVASCRIPT HABILITADO) — APPSALON');
    console.log('URL Base:', BASE_URL);
    console.log('Binario Navegador:', CHROME_PATH);
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
            if (locUrl.includes('favicon.ico') || text.includes('favicon.ico')) return; // Solicitud por defecto del navegador ante falta de favicon
            consoleErrors.push(`[Console Error] ${text} @ ${locUrl || 'inline'}`);
        }
    });

    page.on('requestfailed', request => {
        // Ignorar favicon opcional si no existe
        if (request.url().endsWith('favicon.ico')) return;
        failedRequests.push(`[Network Failed] ${request.method()} ${request.url()} - ${request.failure() ? request.failure().errorText : 'Failed'}`);
    });

    try {
        // ------------------------------------------------------------------
        // PASO PREVIO: Autenticación en Navegador
        // ------------------------------------------------------------------
        console.log('>>> [PRE-REQUISITO] Autenticación en interfaz de Login...');
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
        recordTest('PRE-01', 'Login interactivo y redirección autorizada a /cita', true, `URL: ${currentUrl}`);

        // ------------------------------------------------------------------
        // RECORRIDO 1: Carga de Servicios, Selección y Deselección Visual
        // ------------------------------------------------------------------
        console.log('\n>>> [RECORRIDO 1] Carga de servicios, selección y deselección visual (.seleccionado)...');

        // Esperar a que la llamada asíncrona a /api/servicios se complete y renderice
        await page.waitForSelector('#servicios .servicio', { timeout: 5000 });
        const serviciosCards = await page.$$('#servicios .servicio');
        const cantidadServicios = serviciosCards.length;

        if (cantidadServicios < 2) {
            throw new Error(`Se esperaban al menos 2 servicios en el catálogo, encontrados: ${cantidadServicios}`);
        }

        // Obtener datos del primer servicio
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

        // 1.3 Volver a seleccionar servicio 1 y seleccionar también el servicio 3
        await primerServicio.click();
        const tercerServicio = serviciosCards[2] || serviciosCards[1];
        const servicioId3 = await tercerServicio.evaluate(el => el.dataset.idServicio);
        await tercerServicio.click();

        const s1Seleccionado = await primerServicio.evaluate(el => el.classList.contains('seleccionado'));
        const s3Seleccionado = await tercerServicio.evaluate(el => el.classList.contains('seleccionado'));

        if (!s1Seleccionado || !s3Seleccionado) {
            throw new Error('Fallo al seleccionar múltiples servicios en el DOM.');
        }

        recordTest('REC-01', 'Carga asíncrona de servicios y alternancia de clase .seleccionado', true, 
            `${cantidadServicios} servicios renderizados; selección y deselección verificadas; IDs seleccionados: [${servicioId1}, ${servicioId3}]`);

        // ------------------------------------------------------------------
        // RECORRIDO 2: Navegación entre los Tres Pasos y Conservación de Estado
        // ------------------------------------------------------------------
        console.log('\n>>> [RECORRIDO 2] Navegación entre pasos 1, 2 y 3 con conservación de datos...');

        // 2.1 Verificar que estamos en Paso 1 inicialmente
        const paso1Visible = await page.$eval('#paso-1', el => el.classList.contains('mostrar'));
        const tab1Activo = await page.$eval('.tabs button[data-paso="1"]', el => el.classList.contains('actual'));
        const btnAnteriorOculto = await page.$eval('#anterior', el => el.classList.contains('ocultar'));

        if (!paso1Visible || !tab1Activo || !btnAnteriorOculto) {
            throw new Error('Estado inicial del paginador/tabs en Paso 1 inconsistente.');
        }

        // 2.2 Avanzar a Paso 2 con botón Siguiente
        await page.click('#siguiente');
        await page.waitForFunction(() => document.querySelector('#paso-2.mostrar') !== null);

        const paso2Visible = await page.$eval('#paso-2', el => el.classList.contains('mostrar'));
        const tab2Activo = await page.$eval('.tabs button[data-paso="2"]', el => el.classList.contains('actual'));
        if (!paso2Visible || !tab2Activo) {
            throw new Error('Fallo al navegar a Paso 2 con botón #siguiente.');
        }

        // Verificar prellenado de nombre del cliente desde sesión
        const nombrePrellenado = await page.$eval('#nombre', el => el.value);
        if (!nombrePrellenado || !nombrePrellenado.toLowerCase().includes('carlos')) {
            throw new Error(`Nombre de cliente no prellenado en Paso 2: '${nombrePrellenado}'`);
        }

        // 2.3 Retroceder a Paso 1 con botón Anterior y verificar conservación de servicios seleccionados
        await page.click('#anterior');
        await page.waitForFunction(() => document.querySelector('#paso-1.mostrar') !== null);

        const s1SigueSeleccionado = await primerServicio.evaluate(el => el.classList.contains('seleccionado'));
        const s3SigueSeleccionado = await tercerServicio.evaluate(el => el.classList.contains('seleccionado'));

        if (!s1SigueSeleccionado || !s3SigueSeleccionado) {
            throw new Error('Los servicios seleccionados perdieron la clase .seleccionado al retroceder a Paso 1.');
        }

        // 2.4 Navegar a Paso 2 haciendo clic directo en Tab "Información Citas"
        await page.click('.tabs button[data-paso="2"]');
        await page.waitForFunction(() => document.querySelector('#paso-2.mostrar') !== null);

        recordTest('REC-02', 'Navegación fluida por paginador y tabs con preservación de estado en cliente', true,
            `Paso 1 -> Paso 2 -> Paso 1 -> Paso 2 comprobado; nombre prellenado: '${nombrePrellenado}'; selección preservada.`);

        // ------------------------------------------------------------------
        // RECORRIDO 3: Validación Interactiva de Fecha y Horario en Cliente
        // ------------------------------------------------------------------
        console.log('\n>>> [RECORRIDO 3] Validación interactiva de fecha y horario en cliente...');

        // 3.1 Probar fecha de fin de semana (Sábado: 2026-10-17)
        await page.evaluate(() => {
            const fechaInput = document.querySelector('#fecha');
            fechaInput.value = '2026-10-17'; // Sábado
            fechaInput.dispatchEvent(new Event('input', { bubbles: true }));
        });

        // Esperar a que aparezca la alerta de fin de semana
        await page.waitForSelector('.formulario .alerta.error', { timeout: 3000 });
        const textoAlertaFDS = await page.$eval('.formulario .alerta.error', el => el.textContent);
        const fechaLimpiadaFDS = await page.$eval('#fecha', el => el.value);

        if (!textoAlertaFDS.includes('fin de semana')) {
            throw new Error(`Texto de alerta inesperado ante fin de semana: '${textoAlertaFDS}'`);
        }
        if (fechaLimpiadaFDS !== '') {
            throw new Error(`El campo de fecha no se limpió ante fin de semana, valor: '${fechaLimpiadaFDS}'`);
        }

        // 3.2 Probar horario fuera de rango comercial (ej. 08:30 antes de 10:00)
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

        // 3.3 Asignar fecha y hora válidas (Martes futuro: 2026-10-20, Hora: 11:30)
        await page.evaluate(() => {
            const fechaInput = document.querySelector('#fecha');
            fechaInput.value = '2026-10-20'; // Martes futuro
            fechaInput.dispatchEvent(new Event('input', { bubbles: true }));

            const horaInput = document.querySelector('#hora');
            horaInput.value = '11:30';
            horaInput.dispatchEvent(new Event('input', { bubbles: true }));
        });

        const fechaFinalValida = await page.$eval('#fecha', el => el.value);
        const horaFinalValida = await page.$eval('#hora', el => el.value);

        if (fechaFinalValida !== '2026-10-20' || horaFinalValida !== '11:30') {
            throw new Error(`Valores válidos no retenidos en inputs: fecha='${fechaFinalValida}', hora='${horaFinalValida}'`);
        }

        recordTest('REC-03', 'Validación interactiva de restricciones en fecha (no FDS) y horario (10:00-18:00)', true,
            `Fines de semana rechazados con alerta; horas no comerciales rechazadas; fecha válida '2026-10-20' y hora '11:30' aceptadas.`);

        // ------------------------------------------------------------------
        // RECORRIDO 4: Resumen, Envío Real vía Fetch y Alerta SweetAlert2
        // ------------------------------------------------------------------
        console.log('\n>>> [RECORRIDO 4] Resumen de reserva, envío real vía fetch POST y SweetAlert2...');

        // Avanzar a Paso 3
        await page.click('#siguiente');
        await page.waitForFunction(() => document.querySelector('#paso-3.mostrar') !== null);

        // Verificar que el resumen renderizó encabezados y datos formateados
        const headingServicios = await page.$eval('.contenido-resumen h3', el => el.textContent);
        if (!headingServicios.includes('Resumen de Servicios')) {
            throw new Error(`Encabezado de servicios en resumen no encontrado: '${headingServicios}'`);
        }

        const serviciosEnResumen = await page.$$('.contenido-resumen .contenedor-servicio');
        if (serviciosEnResumen.length !== 2) {
            throw new Error(`Se esperaban 2 servicios en el resumen, encontrados: ${serviciosEnResumen.length}`);
        }

        const fechaEnResumen = await page.evaluate(() => {
            const ps = Array.from(document.querySelectorAll('.contenido-resumen p'));
            const pFecha = ps.find(p => p.textContent.includes('Fecha:'));
            return pFecha ? pFecha.textContent : '';
        });

        const horaEnResumen = await page.evaluate(() => {
            const ps = Array.from(document.querySelectorAll('.contenido-resumen p'));
            const pHora = ps.find(p => p.textContent.includes('Hora:'));
            return pHora ? pHora.textContent : '';
        });

        if (!fechaEnResumen || !horaEnResumen.includes('11:30')) {
            throw new Error(`Datos de cita no reflejados correctamente en resumen: fecha='${fechaEnResumen}', hora='${horaEnResumen}'`);
        }

        // Localizar el botón de Reservar Cita en el resumen
        const botonReservar = await page.$('.contenido-resumen button.boton');
        if (!botonReservar) {
            throw new Error('Botón "Reservar Cita" no encontrado en el DOM del resumen.');
        }

        // Interceptar y esperar la respuesta de /api/citas tras el clic
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

        // Esperar el modal interactivo de SweetAlert2 en el DOM
        await page.waitForSelector('.swal2-popup', { timeout: 5000 });
        const swalTitle = await page.$eval('.swal2-title', el => el.textContent);
        const swalIcon = await page.$eval('.swal2-icon.swal2-success', el => el !== null);

        if (!swalTitle.includes('Cita Creada') || !swalIcon) {
            throw new Error(`Modal SweetAlert2 inesperado: título='${swalTitle}', iconoExito=${swalIcon}`);
        }

        recordTest('REC-04', 'Renderizado de resumen, envío asíncrono con CSRF, respuesta 200 JSON y alerta SweetAlert2', true,
            `Resumen validado; POST /api/citas exitoso (id: ${citaIdCreada}); SweetAlert2 ('${swalTitle}') desplegado en pantalla.`);

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

        // Exportar reporte consolidado para trazabilidad
        const report = {
            timestamp: new Date().toISOString(),
            navegador: 'Chrome Headless (Puppeteer-Core)',
            citaCreadaId: citaIdCreada,
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

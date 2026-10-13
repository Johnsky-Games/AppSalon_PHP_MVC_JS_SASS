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
const crypto = require('crypto');
const { execFileSync } = require('child_process');

function consultarHorariosEnMySql(diaSemana) {
    const dbContainer = process.env.TEST_DB_CONTAINER;
    const dbName = process.env.TEST_DB_NAME || 'appsalon_browser_test';
    if (!dbContainer) {
        if (process.env.TEST_REQUIRE_DB_ASSERTIONS === '1') {
            throw new Error('TEST_DB_CONTAINER es obligatorio para las aserciones de persistencia.');
        }
        return null;
    }
    const sql = `SELECT CONCAT(hora_inicio, '-', hora_fin) FROM horarios_profesionales WHERE profesionalId = 1 AND dia_semana = ${Number(diaSemana)} ORDER BY hora_inicio ASC;`;
    const out = execFileSync('docker', [
        'exec', dbContainer,
        'mysql', '-uroot', '-proot', '--default-character-set=utf8mb4', '-N', '-e', sql, dbName
    ], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] });
    return out.trim().split(/\r?\n/).filter(Boolean);
}

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
    const bookingDay = new Date(`${validDate}T00:00:00Z`).getUTCDay();
    if (!Number.isInteger(bookingDay) || bookingDay < 1 || bookingDay > 5) {
        throw new Error(`La fecha de reserva de pruebas debe ser un día laborable válido: ${validDate}`);
    }
    // The split-shift regression must never remove capacity needed by the
    // subsequent availability/booking checks, regardless of today's weekday.
    const splitDay = bookingDay === 2 ? 3 : 2;
    const weekdays = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

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

    const responsiveResults = [];
    const screenshotDir = path.join(
        process.env.TEST_BROWSER_REPORT_PATH ? path.dirname(path.resolve(process.env.TEST_BROWSER_REPORT_PATH)) : path.resolve(__dirname, '../storage/e2e'),
        'screens'
    );
    fs.mkdirSync(screenshotDir, { recursive: true });

    // Check the rendered document rather than stylesheet breakpoints. Tables may
    // scroll inside their own wrapper, but the whole document must fit the screen.
    async function verifyResponsivePage(name) {
        for (const width of [320, 390, 768, 1440]) {
            await page.setViewport({ width, height: 900 });
            await page.evaluate(async () => {
                await document.fonts.ready;
                await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
            });
            const geometry = await page.evaluate(() => ({
                viewport: document.documentElement.clientWidth,
                document: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth),
                overflowing: [...document.querySelectorAll('main *')].filter(el => {
                    const r = el.getBoundingClientRect();
                    return r.width > 0 && r.right > innerWidth + 1 && getComputedStyle(el).position !== 'fixed';
                }).slice(0, 6).map(el => `${el.tagName}.${el.className}`)
            }));
            const screenshot = path.join(screenshotDir, `${name}-${width}.png`);
            await page.screenshot({ path: screenshot, fullPage: false });
            if (geometry.document > geometry.viewport + 1) {
                throw new Error(`Overflow horizontal en ${name} (${width}px): ${JSON.stringify(geometry)}. Captura: ${screenshot}`);
            }
            responsiveResults.push({ page: name, width, screenshot: path.basename(screenshot), ...geometry });
        }
        await page.setViewport({ width: 1280, height: 800 });
    }

    async function verifyAccessibleLogin() {
        await page.waitForSelector('.ui-password-toggle', { timeout: 5000 });
        const before = page.url();
        await page.type('#password', 'UiTest123!');
        await page.click('.ui-password-toggle');
        const shown = await page.$eval('#password', input => ({ type: input.type, value: input.value }));
        const pressed = await page.$eval('.ui-password-toggle', button => button.getAttribute('aria-pressed'));
        if (shown.type !== 'text' || shown.value !== 'UiTest123!' || pressed !== 'true' || page.url() !== before) {
            throw new Error('Mostrar contraseña debe revelar el mismo valor y actualizar aria-pressed sin enviar el formulario.');
        }
        await page.click('.ui-password-toggle');
        const hidden = await page.$eval('#password', input => input.type);
        if (hidden !== 'password' || await page.$eval('.ui-password-toggle', button => button.getAttribute('aria-pressed')) !== 'false') {
            throw new Error('Ocultar contraseña no restauró el campo y el estado accesible.');
        }
        await page.$eval('#password', input => input.value = '');
        await page.goto(`${BASE_URL}/`, { waitUntil: 'networkidle0' });
        await page.keyboard.press('Tab');
        const skipFocused = await page.evaluate(() => document.activeElement?.matches('a[href="#contenido-principal"]'));
        if (!skipFocused) throw new Error('El primer Tab debe enfocar el enlace Saltar al contenido.');
        await page.keyboard.press('Enter');
        if (!await page.evaluate(() => document.activeElement?.id === 'contenido-principal')) {
            throw new Error('El enlace de salto no trasladó el foco al contenido principal.');
        }
        await page.emulateMediaFeatures([{ name: 'prefers-reduced-motion', value: 'reduce' }]);
        await page.goto(`${BASE_URL}/`, { waitUntil: 'networkidle0' });
        await page.waitForSelector('.ui-password-toggle');
        const reduced = await page.$eval('.ui-password-toggle', el => {
            const style = getComputedStyle(el);
            const seconds = value => Math.max(...value.split(',').map(v => v.trim().endsWith('ms') ? parseFloat(v) / 1000 : parseFloat(v)));
            return matchMedia('(prefers-reduced-motion: reduce)').matches && seconds(style.transitionDuration) <= 0.01 && seconds(style.animationDuration) <= 0.01;
        });
        if (!reduced) throw new Error('El botón conserva movimiento al solicitar prefers-reduced-motion.');
        const notification = '<strong>Prueba segura de notificación</strong>';
        await page.evaluate(text => window.AppSalonUI.notify(text), notification);
        await page.waitForSelector('.toastify');
        const notice = await page.evaluate(() => ({
            text: document.querySelector('.toastify')?.textContent,
            markup: document.querySelector('.toastify strong'),
            announced: document.querySelector('#ui-notifications')?.textContent,
            live: document.querySelector('#ui-notifications')?.getAttribute('aria-live')
        }));
        if (!notice.text?.includes(notification) || notice.markup || notice.announced !== notification || !['polite', 'assertive'].includes(notice.live)) {
            throw new Error('La notificación debe mostrar texto literal y anunciarse mediante la región aria-live.');
        }
        await page.emulateMediaFeatures([]);
        await page.goto(`${BASE_URL}/`, { waitUntil: 'networkidle0' });
        recordTest('UI-02', 'Controles accesibles: contraseña, salto por teclado, movimiento reducido y notificación segura', true,
            'Mostrar/ocultar conserva el valor; primer Tab y Enter enfocan main; reduced-motion limita animaciones; Toastify no interpreta HTML y anuncia texto.');
    }

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
        console.log('>>> [FASE 2A - ADMIN] Autenticación de Administrador (acceso del equipo) y CRUD de Servicios...');
        await page.goto(`${BASE_URL}/equipo/login`, { waitUntil: 'networkidle0' });
        await verifyResponsivePage('login');
        await verifyAccessibleLogin();
        // Las comprobaciones de accesibilidad recorren el acceso de clientes; el administrador entra por el del equipo.
        await page.goto(`${BASE_URL}/equipo/login`, { waitUntil: 'networkidle0' });

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

        await verifyResponsivePage('servicios-admin');
        const serviceCountBeforeFilter = await page.$$eval('#catalogo-servicios [data-filter-item]', items => items.length);
        await page.type('#buscar-servicios', 'Servicio sin coincidencias ui-e2e');
        const filteredServices = await page.evaluate(() => ({
            visible: [...document.querySelectorAll('#catalogo-servicios [data-filter-item]')].filter(item => !item.hidden).length,
            emptyShown: document.querySelector('[data-filter-empty="#catalogo-servicios"]')?.hidden === false
        }));
        if (filteredServices.visible !== 0 || !filteredServices.emptyShown) {
            throw new Error(`El buscador no mostró el estado vacío sin coincidencias: ${JSON.stringify(filteredServices)}`);
        }
        await page.$eval('#buscar-servicios', input => {
            input.value = '';
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
        const restoredServices = await page.evaluate(() => ({
            visible: [...document.querySelectorAll('#catalogo-servicios [data-filter-item]')].filter(item => !item.hidden).length,
            emptyHidden: document.querySelector('[data-filter-empty="#catalogo-servicios"]')?.hidden === true
        }));
        if (restoredServices.visible !== serviceCountBeforeFilter || !restoredServices.emptyHidden) {
            throw new Error(`Limpiar el buscador no restauró el catálogo completo: ${JSON.stringify(restoredServices)}`);
        }

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

        // 2.a Comprobar validación en formulario HTML (min="1" step="1") y en backend (nombre vacío, precio negativo y duración 0)
        await page.$eval('#duracion_minutos', el => el.value = '0');
        const duracionValidaEnForm = await page.$eval('#duracion_minutos', el => el.checkValidity());
        if (duracionValidaEnForm !== false) {
            throw new Error('El campo #duracion_minutos en el formulario debía rechazar 0 mediante validación HTML (min="1").');
        }

        await page.$eval('form.formulario', form => form.noValidate = true);
        await page.type('#precio', '-25');
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('form.formulario input[type="submit"]')
        ]);

        const alertasErrorCrear = await page.$$eval('.alerta.error', els => els.map(e => e.textContent.trim()));
        if (alertasErrorCrear.length < 3) {
            throw new Error(`Se esperaban al menos 3 alertas de validación ante entrada inválida en /servicios/crear, obtenidas: ${JSON.stringify(alertasErrorCrear)}`);
        }

        // 2.b Envío válido ("Masaje Capilar Relax", "95.50", duración "45" min)
        await page.$eval('#nombre', el => el.value = '');
        await page.$eval('#precio', el => el.value = '');
        await page.$eval('#duracion_minutos', el => el.value = '');
        await page.type('#nombre', 'Masaje Capilar Relax');
        await page.type('#precio', '95.50');
        await page.type('#duracion_minutos', '45');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('form.formulario input[type="submit"]')
        ]);

        if (!page.url().endsWith('/servicios')) {
            throw new Error(`Tras crear servicio válido debía redirigir a /servicios, URL actual: ${page.url()}`);
        }

        const textoListadoTrasCrear = await page.$eval('ul.servicios', el => el.textContent);
        if (!textoListadoTrasCrear.includes('Masaje Capilar Relax') || !textoListadoTrasCrear.includes('95.50') || !textoListadoTrasCrear.includes('45 min')) {
            throw new Error('El servicio creado "Masaje Capilar Relax" ($95.50, 45 min) no aparece en /servicios.');
        }
        recordTest('ADMIN-02', 'Validación de entradas inválidas y creación de nuevo servicio con duración en /servicios/crear', true,
            `Entradas inválidas rechazadas con alertas (${alertasErrorCrear.length}); 'Masaje Capilar Relax' ($95.50, 45 min) creado.`);

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
        await page.$eval('#duracion_minutos', el => el.value = '');
        await page.type('#nombre', 'Masaje Capilar Premium');
        await page.type('#precio', '115.00');
        await page.type('#duracion_minutos', '60');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('form.formulario input[type="submit"]')
        ]);

        const textoListadoTrasActualizar = await page.$eval('ul.servicios', el => el.textContent);
        if (!textoListadoTrasActualizar.includes('Masaje Capilar Premium') || !textoListadoTrasActualizar.includes('115.00') || !textoListadoTrasActualizar.includes('60 min')) {
            throw new Error('El servicio actualizado "Masaje Capilar Premium" ($115.00, 60 min) no se reflejó en /servicios.');
        }
        recordTest('ADMIN-03', 'Actualización de servicio existente y su duración en /servicios/actualizar', true,
            `'Masaje Capilar Relax' actualizado a 'Masaje Capilar Premium' ($115.00, 60 min).`);

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

        // 5. Administración de Profesionales y Configuración de Horarios (Fase 3A)
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('a[href="/profesionales"]')
        ]);

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('a[href="/profesionales/crear"]')
        ]);

        await page.type('#nombre', 'Sofía Andrade');
        await page.evaluate(() => {
            const checks = document.querySelectorAll('input[name="servicios[]"]');
            if (checks.length >= 2) {
                checks[0].checked = true;
                checks[1].checked = true;
            }
        });

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('form.formulario input[type="submit"]')
        ]);

        if (!page.url().endsWith('/profesionales')) {
            throw new Error(`Tras crear profesional debía redirigir a /profesionales, URL actual: ${page.url()}`);
        }

        const textoProfesionales = await page.$eval('ul.profesionales-lista', el => el.textContent);
        if (!textoProfesionales.includes('Sofía Andrade') || !textoProfesionales.includes('Activo')) {
            throw new Error('La profesional creada "Sofía Andrade" no aparece activa en /profesionales.');
        }

        // Ir a Horarios y Agenda de Sofía Andrade
        const hrefHorarios = await page.$eval('ul.profesionales-lista a[href*="/profesionales/horarios"]', el => el.getAttribute('href'));
        await page.goto(`${BASE_URL}${hrefHorarios}`, { waitUntil: 'networkidle0' });

        // Probar rechazo de horario invertido en el día de la reserva (18:00 a 09:00)
        await page.evaluate(day => {
            document.querySelector(`#horario_activo_${day}`).checked = true;
            document.querySelector(`#horario_inicio_${day}`).value = '18:00';
            document.querySelector(`#horario_fin_${day}`).value = '09:00';
        }, bookingDay);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('#form-horarios-semanales input[type="submit"]')
        ]);
        const alertaHorarioInvertido = await page.$eval('.alerta.error', el => el.textContent.trim());
        if (!alertaHorarioInvertido.includes('estrictamente anterior')) {
            throw new Error(`Se esperaba alerta de horario invertido, obtenido: ${alertaHorarioInvertido}`);
        }

        // Configurar horario válido en el día de la reserva (09:00 a 18:00)
        await page.evaluate(day => {
            document.querySelector(`#horario_activo_${day}`).checked = true;
            document.querySelector(`#horario_inicio_${day}`).value = '09:00';
            document.querySelector(`#horario_fin_${day}`).value = '18:00';
        }, bookingDay);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('#form-horarios-semanales input[type="submit"]')
        ]);

        // Añadir descanso en el día de la reserva (13:00 a 14:00)
        await page.select('#descanso_dia_semana', String(bookingDay));
        await page.evaluate(() => {
            document.querySelector('#descanso_hora_inicio').value = '13:00';
            document.querySelector('#descanso_hora_fin').value = '14:00';
            document.querySelector('#descanso_motivo').value = 'Almuerzo';
        });
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('#form-crear-descanso input[type="submit"]')
        ]);

        // Añadir bloqueo por intervalo en fecha futura válida
        await page.evaluate((fechaBlk) => {
            document.querySelector('#bloqueo_fecha_inicio').value = fechaBlk;
            document.querySelector('#bloqueo_fecha_fin').value = fechaBlk;
            document.querySelector('#bloqueo_hora_inicio').value = '15:00';
            document.querySelector('#bloqueo_hora_fin').value = '16:30';
            document.querySelector('#bloqueo_motivo').value = 'Capacitación';
        }, validDate);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('#form-crear-bloqueo input[type="submit"]')
        ]);

        const textoDescansos = await page.$eval('ul.descansos-lista', el => el.textContent);
        const textoBloqueos = await page.$eval('ul.bloqueos-lista', el => el.textContent);
        if (!textoDescansos.includes('13:00 - 14:00') || !textoBloqueos.includes('15:00 - 16:30')) {
            throw new Error('El descanso o bloqueo configurado no se reflejó en la vista de agenda del profesional.');
        }

        // Volver a /profesionales y probar desactivación y reactivación con CSRF
        await page.goto(`${BASE_URL}/profesionales`, { waitUntil: 'networkidle0' });
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('ul.profesionales-lista form[action="/profesionales/estado"] input[type="submit"]')
        ]);
        const estadoTrasDesactivar = await page.$eval('ul.profesionales-lista', el => el.textContent);
        if (!estadoTrasDesactivar.includes('Inactivo')) {
            throw new Error('El profesional no cambió a estado Inactivo tras enviar /profesionales/estado.');
        }

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('ul.profesionales-lista form[action="/profesionales/estado"] input[type="submit"]')
        ]);
        const estadoTrasReactivar = await page.$eval('ul.profesionales-lista', el => el.textContent);
        if (!estadoTrasReactivar.includes('Activo')) {
            throw new Error('El profesional no volvió a estado Activo tras reactivar en /profesionales/estado.');
        }

        recordTest('PROF-01', 'Gestión de profesionales, servicios asociados, horarios, descansos, bloqueos y desactivación/reactivación', true,
            `'Sofía Andrade' creada con servicios, horario ${weekdays[bookingDay]} 09:00-18:00, descanso 13:00-14:00, bloqueo ${validDate} 15:00-16:30 y ciclo Inactivo/Activo verificado.`);

        // ------------------------------------------------------------------
        // PROF-02: Múltiples franjas por día, guardado sin cambios y retirada explícita
        // ------------------------------------------------------------------
        await page.goto(`${BASE_URL}${hrefHorarios}`, { waitUntil: 'networkidle0' });

        // 1. Configurar dos franjas el mismo día en un día distinto al de la reserva (08:00-12:00 y 14:00-18:00)
        await page.evaluate(day => {
            document.querySelector(`#horario_activo_${day}`).checked = true;
            document.querySelector(`#horario_inicio_${day}`).value = '08:00';
            document.querySelector(`#horario_fin_${day}`).value = '12:00';
        }, splitDay);
        await page.click(`.btn-agregar-franja[data-dia="${splitDay}"]`);
        await page.evaluate(day => {
            document.querySelector(`#horario_activo_${day}_1`).checked = true;
            document.querySelector(`#horario_inicio_${day}_1`).value = '14:00';
            document.querySelector(`#horario_fin_${day}_1`).value = '18:00';
        }, splitDay);

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('#form-horarios-semanales input[type="submit"]')
        ]);

        // 2. Cargar vista con las dos franjas del día de prueba independiente y comprobar que ambas están renderizadas
        const franjasCargadasDiaIndependiente = await page.$$eval(
            `.horario-dia-franjas[data-dia-contenedor="${splitDay}"] .horario-franja-fila`,
            filas => filas.map(f => ({
                activo: f.querySelector('.horario-activo-check').checked,
                inicio: f.querySelector('.horario-inicio-input').value,
                fin: f.querySelector('.horario-fin-input').value
            }))
        );
        if (
            franjasCargadasDiaIndependiente.length !== 2 ||
            !franjasCargadasDiaIndependiente[0].activo || franjasCargadasDiaIndependiente[0].inicio !== '08:00' || franjasCargadasDiaIndependiente[0].fin !== '12:00' ||
            !franjasCargadasDiaIndependiente[1].activo || franjasCargadasDiaIndependiente[1].inicio !== '14:00' || franjasCargadasDiaIndependiente[1].fin !== '18:00'
        ) {
            throw new Error(`La vista no conservó las 2 franjas del día de prueba independiente al cargar: ${JSON.stringify(franjasCargadasDiaIndependiente)}`);
        }

        // 3. Guardar SIN CAMBIOS y verificar que ambas franjas se preservan en MySQL y en el DOM
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('#form-horarios-semanales input[type="submit"]')
        ]);

        const mysqlDiaIndependienteTrasGuardarSinCambios = consultarHorariosEnMySql(splitDay);
        if (mysqlDiaIndependienteTrasGuardarSinCambios !== null) {
            if (
                mysqlDiaIndependienteTrasGuardarSinCambios.length !== 2 ||
                mysqlDiaIndependienteTrasGuardarSinCambios[0] !== '08:00:00-12:00:00' ||
                mysqlDiaIndependienteTrasGuardarSinCambios[1] !== '14:00:00-18:00:00'
            ) {
                throw new Error(`En MySQL no se preservaron ambas franjas tras guardar sin cambios: ${JSON.stringify(mysqlDiaIndependienteTrasGuardarSinCambios)}`);
            }
        }

        const franjasTrasGuardarSinCambios = await page.$$eval(
            `.horario-dia-franjas[data-dia-contenedor="${splitDay}"] .horario-franja-fila`,
            filas => filas.map(f => `${f.querySelector('.horario-inicio-input').value}-${f.querySelector('.horario-fin-input').value}`)
        );
        if (
            franjasTrasGuardarSinCambios.length !== 2 ||
            franjasTrasGuardarSinCambios[0] !== '08:00-12:00' ||
            franjasTrasGuardarSinCambios[1] !== '14:00-18:00'
        ) {
            throw new Error(`El DOM no preservó ambas franjas tras guardar sin cambios: ${JSON.stringify(franjasTrasGuardarSinCambios)}`);
        }

        // 4. Retirada explícita de la primera franja del día de prueba independiente (08:00-12:00) y comprobar que elimina únicamente la franja elegida
        await page.click(`.horario-dia-franjas[data-dia-contenedor="${splitDay}"] .horario-franja-fila[data-franja-index="0"] .btn-retirar-franja`);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('#form-horarios-semanales input[type="submit"]')
        ]);

        const mysqlDiaIndependienteTrasRetirar = consultarHorariosEnMySql(splitDay);
        if (mysqlDiaIndependienteTrasRetirar !== null) {
            if (
                mysqlDiaIndependienteTrasRetirar.length !== 1 ||
                mysqlDiaIndependienteTrasRetirar[0] !== '14:00:00-18:00:00'
            ) {
                throw new Error(`En MySQL la retirada explícita no dejó únicamente la franja 14:00-18:00: ${JSON.stringify(mysqlDiaIndependienteTrasRetirar)}`);
            }
        }

        const franjasTrasRetirar = await page.$$eval(
            `.horario-dia-franjas[data-dia-contenedor="${splitDay}"] .horario-franja-fila`,
            filas => filas.map(f => ({
                activo: f.querySelector('.horario-activo-check').checked,
                rango: `${f.querySelector('.horario-inicio-input').value}-${f.querySelector('.horario-fin-input').value}`
            }))
        );
        if (
            franjasTrasRetirar.length !== 1 ||
            !franjasTrasRetirar[0].activo ||
            franjasTrasRetirar[0].rango !== '14:00-18:00'
        ) {
            throw new Error(`Tras retirar la primera franja del día de prueba independiente, no quedó únicamente 14:00-18:00 activa: ${JSON.stringify(franjasTrasRetirar)}`);
        }

        recordTest('PROF-02', 'Carga de múltiples franjas del mismo día, guardado sin cambios verificado en MySQL y retirada explícita de franja individual', true,
            `${weekdays[splitDay]} 08:00-12:00 y 14:00-18:00 preservados en MySQL al guardar sin cambios; retirada explícita eliminó solo 08:00-12:00 conservando 14:00-18:00, sin modificar ${weekdays[bookingDay]} reservado para disponibilidad y citas.`);

        // Crear segunda profesional activa ("Valeria Castro") habilitada para Servicio 1 y "Masaje Capilar Premium" (Servicio 4)
        // con horario 09:00-18:00 en el día de reserva para probar filtrado de compatibilidad y reserva completa desde la UI en Fase 5
        await page.goto(`${BASE_URL}/profesionales/crear`, { waitUntil: 'networkidle0' });
        await page.type('#nombre', 'Valeria Castro');
        await page.evaluate(() => {
            const labels = Array.from(document.querySelectorAll('.servicios-asignables label, form.formulario label'));
            const checks = Array.from(document.querySelectorAll('input[name="servicios[]"]'));
            if (checks.length >= 1) {
                checks[0].checked = true; // Corte de Cabello Hombre (30 min)
            }
            const checkPremium = checks.find(c => {
                const parentText = (c.closest('.campo-check, label, div')?.textContent || '');
                return parentText.includes('Masaje Capilar Premium');
            }) || checks[checks.length - 1];
            if (checkPremium) {
                checkPremium.checked = true; // Masaje Capilar Premium (60 min)
            }
        });
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('form.formulario input[type="submit"]')
        ]);

        const hrefHorariosValeria = await page.evaluate(() => {
            const items = Array.from(document.querySelectorAll('ul.profesionales-lista li'));
            const target = items.find(li => li.textContent.includes('Valeria Castro'));
            const link = target ? target.querySelector('a[href*="/profesionales/horarios"]') : null;
            return link ? link.getAttribute('href') : null;
        });
        if (!hrefHorariosValeria) {
            throw new Error('No se encontró enlace de horarios para la profesional Valeria Castro.');
        }

        await page.goto(`${BASE_URL}${hrefHorariosValeria}`, { waitUntil: 'networkidle0' });
        await page.evaluate(day => {
            document.querySelector(`#horario_activo_${day}`).checked = true;
            document.querySelector(`#horario_inicio_${day}`).value = '09:00';
            document.querySelector(`#horario_fin_${day}`).value = '18:00';
        }, bookingDay);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('#form-horarios-semanales input[type="submit"]')
        ]);

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
        await page.waitForSelector('.ui-avatar');
        const avatarLoaded = await page.$eval('.ui-avatar', img => img.complete && img.naturalWidth > 0);
        if (!avatarLoaded) throw new Error('El avatar de cliente no se cargó correctamente.');
        await verifyResponsivePage('cita-cliente');

        // ------------------------------------------------------------------
        // FASE 4A - DISPONIBILIDAD: Endpoint autenticado GET /api/disponibilidad
        // ------------------------------------------------------------------
        console.log('\n>>> [FASE 4A - DISPONIBILIDAD] Consulta autenticada en GET /api/disponibilidad...');
        const dispChecks = await page.evaluate(async (fechaValida, fechaSabado) => {
            const rOk = await fetch(`/api/disponibilidad?profesionalId=1&fecha=${encodeURIComponent(fechaValida)}&servicios=1,2&duracion_minutos=5`, {
                headers: { 'Accept': 'application/json' }
            });
            const bodyOk = await rOk.json();

            const rEmpty = await fetch(`/api/disponibilidad?profesionalId=1&fecha=${encodeURIComponent(fechaSabado)}&servicios=1`, {
                headers: { 'Accept': 'application/json' }
            });
            const bodyEmpty = await rEmpty.json();

            return {
                okStatus: rOk.status,
                okBody: bodyOk,
                emptyStatus: rEmpty.status,
                emptyBody: bodyEmpty
            };
        }, validDate, weekendDate);

        const activeCookies = await page.cookies();
        const cookieHeader = activeCookies.map(c => `${c.name}=${c.value}`).join('; ');
        const rIncomp = await fetch(`${BASE_URL}/api/disponibilidad?profesionalId=1&fecha=${encodeURIComponent(validDate)}&servicios=1,3`, {
            headers: {
                'Accept': 'application/json',
                'Cookie': cookieHeader
            }
        });
        const incompStatus = rIncomp.status;
        const incompBody = await rIncomp.json();

        const paresDispOk = Array.isArray(dispChecks.okBody.intervalos)
            ? dispChecks.okBody.intervalos.map(i => `${i.inicio}-${i.fin}`)
            : [];
        if (
            dispChecks.okStatus !== 200 ||
            dispChecks.okBody.status !== 'ok' ||
            dispChecks.okBody.duracion_total_minutos !== 60 ||
            !paresDispOk.includes('12:00-13:00') ||
            !paresDispOk.includes('14:00-15:00') ||
            !paresDispOk.includes('17:00-18:00') ||
            paresDispOk.some(p => p.startsWith('12:30-') || p.startsWith('14:30-') || p.startsWith('15:00-'))
        ) {
            throw new Error(`Respuesta inesperada en GET /api/disponibilidad (caso válido): ${JSON.stringify(dispChecks.okBody)}`);
        }

        if (
            incompStatus !== 422 ||
            incompBody.status !== 'incompatible_services' ||
            incompBody.codigo !== 'servicios_incompatibles'
        ) {
            throw new Error(`Respuesta inesperada en GET /api/disponibilidad (servicios incompatibles): ${JSON.stringify(incompBody)}`);
        }

        if (
            dispChecks.emptyStatus !== 200 ||
            dispChecks.emptyBody.status !== 'empty' ||
            dispChecks.emptyBody.codigo !== 'disponibilidad_vacia' ||
            dispChecks.emptyBody.disponible !== false ||
            (Array.isArray(dispChecks.emptyBody.intervalos) && dispChecks.emptyBody.intervalos.length !== 0)
        ) {
            throw new Error(`Respuesta inesperada en GET /api/disponibilidad (disponibilidad vacía): ${JSON.stringify(dispChecks.emptyBody)}`);
        }

        recordTest('DISP-01', 'Consulta autenticada en GET /api/disponibilidad (intervalos [inicio, fin), servicios incompatibles y disponibilidad vacía)', true,
            `Duración catálogo 60 min respetada (${paresDispOk.length} intervalos en ${validDate}); incompatibilidad 422 y disponibilidad vacía 200 verificadas.`);

        // ------------------------------------------------------------------
        // RECORRIDO 1: Carga de Servicios, Duración, Accesibilidad y Selección Visual
        // ------------------------------------------------------------------
        console.log('\n>>> [RECORRIDO 1] Carga de servicios desde /api/servicios (con duración y accesibilidad), selección y deselección...');

        await page.waitForSelector('#servicios .servicio', { timeout: 5000 });
        const serviciosCards = await page.$$('#servicios .servicio');
        const cantidadServicios = serviciosCards.length;

        if (cantidadServicios !== 4) {
            throw new Error(`Se esperaban exactamente 4 servicios en el catálogo de reserva tras el CRUD administrativo, encontrados: ${cantidadServicios}`);
        }

        const detallesTarjetas = await page.$$eval('#servicios .servicio', els => els.map(e => ({
            nombre: e.querySelector('.nombre-servicio')?.textContent.trim(),
            duracion: e.querySelector('.duracion-servicio')?.textContent.trim(),
            role: e.getAttribute('role'),
            tabindex: e.getAttribute('tabindex')
        })));
        const tarjetaPremium = detallesTarjetas.find(t => t.nombre === 'Masaje Capilar Premium');
        if (!tarjetaPremium || tarjetaPremium.duracion !== '60 min' || tarjetaPremium.role !== 'button' || tarjetaPremium.tabindex !== '0') {
            throw new Error(`El catálogo en /cita no muestra duración y atributos accesibles esperados: ${JSON.stringify(detallesTarjetas)}`);
        }

        const primerServicio = serviciosCards[0];
        const servicioId1 = await primerServicio.evaluate(el => el.dataset.idServicio);
        const servicioNombre1 = await primerServicio.evaluate(el => el.querySelector('.nombre-servicio')?.textContent);

        // 1.1 Clic para seleccionar el servicio 1
        await primerServicio.click();
        const tieneClaseSeleccionado1 = await primerServicio.evaluate(el => el.classList.contains('seleccionado') && el.getAttribute('aria-pressed') === 'true');
        if (!tieneClaseSeleccionado1) {
            throw new Error(`El servicio ${servicioNombre1} (ID ${servicioId1}) no recibió .seleccionado y aria-pressed="true".`);
        }

        // 1.2 Clic para deseleccionar el servicio 1
        await primerServicio.click();
        const mantieneClaseTrasDeseleccion = await primerServicio.evaluate(el => el.classList.contains('seleccionado'));
        if (mantieneClaseTrasDeseleccion) {
            throw new Error(`El servicio ${servicioNombre1} no removió la clase .seleccionado tras un segundo clic.`);
        }

        // 1.3 Volver a seleccionar servicio 1 y seleccionar también el cuarto servicio ("Masaje Capilar Premium", 60 min)
        await primerServicio.click();
        const cuartoServicio = serviciosCards[3];
        const servicioId4 = await cuartoServicio.evaluate(el => el.dataset.idServicio);
        await cuartoServicio.click();

        const s1Seleccionado = await primerServicio.evaluate(el => el.classList.contains('seleccionado'));
        const s4Seleccionado = await cuartoServicio.evaluate(el => el.classList.contains('seleccionado'));

        if (!s1Seleccionado || !s4Seleccionado) {
            throw new Error('Fallo al seleccionar múltiples servicios en el DOM.');
        }

        recordTest('REC-01', 'Carga asíncrona de /api/servicios reflejando CRUD, duración de servicios y alternancia accesible de .seleccionado', true,
            `${cantidadServicios} servicios renderizados con duración (incluye 'Masaje Capilar Premium' 60 min); IDs seleccionados: [${servicioId1}, ${servicioId4}]`);

        // ------------------------------------------------------------------
        // RECORRIDO 2: Navegación entre Pasos, Profesionales Compatibles y Conservación de Estado
        // ------------------------------------------------------------------
        console.log('\n>>> [RECORRIDO 2] Navegación entre pasos 1, 2 y 3, filtrado de profesionales compatibles y conservación de datos...');

        const paso1Visible = await page.$eval('#paso-1', el => el.classList.contains('mostrar'));
        const tab1Activo = await page.$eval('.tabs button[data-paso="1"]', el => el.classList.contains('actual'));
        const btnAnteriorOculto = await page.$eval('#anterior', el => el.classList.contains('ocultar'));

        if (!paso1Visible || !tab1Activo || !btnAnteriorOculto) {
            throw new Error('Estado inicial del paginador/tabs en Paso 1 inconsistente.');
        }

        await page.click('#siguiente');
        await page.waitForFunction(() => document.querySelector('#paso-2.mostrar') !== null);

        const nombrePrellenado = await page.$eval('#nombre', el => el.value);
        if (!nombrePrellenado || !nombrePrellenado.toLowerCase().includes('carlos')) {
            throw new Error(`Nombre de cliente no prellenado en Paso 2: '${nombrePrellenado}'`);
        }

        // Verificar que con [Servicio 1, Masaje Capilar Premium] solo aparece "Valeria Castro" como compatible (Sofía Andrade no realiza Masaje Capilar Premium)
        const opcionesProfCompatibles = await page.$$eval('#profesional option', opts => opts.map(o => o.textContent.trim()).filter(t => !t.startsWith('--')));
        if (!opcionesProfCompatibles.includes('Valeria Castro') || opcionesProfCompatibles.includes('Sofía Andrade')) {
            throw new Error(`El selector #profesional no filtró correctamente por compatibilidad de servicios: ${JSON.stringify(opcionesProfCompatibles)}`);
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

        recordTest('REC-02', 'Navegación fluida por paginador y tabs, filtrado de profesionales compatibles y preservación de estado', true,
            `Paso 1 -> Paso 2 -> Paso 1 -> Paso 2 comprobado; profesional compatible filtrado ('Valeria Castro'); selección preservada.`);

        // ------------------------------------------------------------------
        // RECORRIDO 3: Selección de Profesional, Disponibilidad Dinámica, Estado Vacío e Invalidación Reactiva
        // ------------------------------------------------------------------
        console.log('\n>>> [RECORRIDO 3] Selección de profesional, carga dinámica de horarios, disponibilidad vacía e invalidación reactiva...');

        // 3.1 Sin profesional seleccionado, intentar sábado rechaza fin de semana
        await page.evaluate((sabado) => {
            const fechaInput = document.querySelector('#fecha');
            fechaInput.value = sabado;
            fechaInput.dispatchEvent(new Event('input', { bubbles: true }));
        }, weekendDate);

        await page.waitForSelector('.formulario .alerta.error', { timeout: 3000 });
        const textoAlertaFDS = await page.$eval('.formulario .alerta.error', el => el.textContent);
        if (!textoAlertaFDS.includes('fin de semana')) {
            throw new Error(`Texto de alerta inesperado ante fin de semana: '${textoAlertaFDS}'`);
        }

        // 3.2 Seleccionar profesional compatible ("Valeria Castro", id="2")
        await page.select('#profesional', '2');

        // 3.3 Seleccionar sábado con profesional seleccionado -> consulta GET /api/disponibilidad y muestra estado vacío (empty)
        await Promise.all([
            page.waitForResponse(res => res.url().includes('/api/disponibilidad') && res.url().includes(weekendDate)),
            page.evaluate((sabado) => {
                const fechaInput = document.querySelector('#fecha');
                fechaInput.value = sabado;
                fechaInput.dispatchEvent(new Event('input', { bubbles: true }));
            }, weekendDate)
        ]);
        await page.waitForFunction(() => document.querySelector('#estado-disponibilidad')?.dataset.estado === 'empty', { timeout: 5000 });
        const botonesEnSabado = await page.$$('#contenedor-horarios-disponibles .intervalo-btn');
        if (botonesEnSabado.length !== 0) {
            throw new Error(`No debían renderizarse intervalos en sábado sin turnos, encontrados: ${botonesEnSabado.length}`);
        }

        // 3.4 Seleccionar fecha laborable válida calculada -> carga dinámica de intervalos de 90 min desde GET /api/disponibilidad
        await Promise.all([
            page.waitForResponse(res => res.url().includes('/api/disponibilidad') && res.url().includes(validDate)),
            page.evaluate((laborable) => {
                const fechaInput = document.querySelector('#fecha');
                fechaInput.value = laborable;
                fechaInput.dispatchEvent(new Event('input', { bubbles: true }));
            }, validDate)
        ]);
        await page.waitForSelector('#contenedor-horarios-disponibles .intervalo-btn[data-inicio="11:30"]', { timeout: 5000 });

        // Seleccionar primero 10:00 - 11:30 y comprobar que al cambiar servicios en Paso 1 se invalida reactivamente
        await page.click('#contenedor-horarios-disponibles .intervalo-btn[data-inicio="10:00"]');
        const horaAntesDeInvalidar = await page.$eval('#hora', el => el.value);
        if (horaAntesDeInvalidar !== '10:00') {
            throw new Error(`El botón de intervalo 10:00 no sincronizó #hora, valor actual: '${horaAntesDeInvalidar}'`);
        }

        // Ir a Paso 1 y agregar Servicio 3 ("Corte de Barba", que ningún profesional realiza junto con 1 y 4) -> invalida profesional y horario
        await page.click('.tabs button[data-paso="1"]');
        const tercerServicio = serviciosCards[2];
        await tercerServicio.click();
        await page.click('.tabs button[data-paso="2"]');

        const estadoIncompUI = await page.$eval('#estado-profesionales', el => el.textContent.trim());
        const horaTrasInvalidar = await page.$eval('#hora', el => el.value);
        if (!estadoIncompUI.includes('Ningún profesional activo') || horaTrasInvalidar !== '') {
            throw new Error(`Fallo en invalidación reactiva al elegir combinación sin profesional compatible: estado='${estadoIncompUI}', hora='${horaTrasInvalidar}'`);
        }

        // Quitar Servicio 3 en Paso 1, volver a Paso 2, re-seleccionar a Valeria Castro y elegir el intervalo 11:30 - 13:00
        await page.click('.tabs button[data-paso="1"]');
        await tercerServicio.click();
        await page.click('.tabs button[data-paso="2"]');

        await Promise.all([
            page.waitForResponse(res => res.url().includes('/api/disponibilidad') && res.url().includes('profesionalId=2')),
            page.select('#profesional', '2')
        ]);
        await page.waitForSelector('#contenedor-horarios-disponibles .intervalo-btn[data-inicio="11:30"]', { timeout: 5000 });
        await page.click('#contenedor-horarios-disponibles .intervalo-btn[data-inicio="11:30"]');

        const fechaFinalValida = await page.$eval('#fecha', el => el.value);
        const horaFinalValida = await page.$eval('#hora', el => el.value);

        if (fechaFinalValida !== validDate || horaFinalValida !== '11:30') {
            throw new Error(`Valores válidos no retenidos: fecha='${fechaFinalValida}' (esperada='${validDate}'), hora='${horaFinalValida}'`);
        }

        recordTest('REC-03', 'Carga dinámica de intervalos desde GET /api/disponibilidad, estado vacío en sábado e invalidación reactiva al cambiar servicios', true,
            `Estado vacío en sábado (${weekendDate}) verificado; invalidación reactiva de horario/profesional comprobada; intervalo '11:30 - 13:00' (90 min) seleccionado en '${validDate}'.`);

        // ------------------------------------------------------------------
        // FASE 4B / FASE 5 - RESERVA CON PROFESIONAL, CONFLICTO 409 EN UI Y MIS CITAS (RES-01)
        // ------------------------------------------------------------------
        console.log('\n>>> [FASE 4B / FASE 5] Reserva con profesional, manejo de conflicto 409, descuento de ocupación y cancelación por el cliente...');
        const csrfTokenCliente = await page.$eval('#csrf_token', el => el.value);
        const tempCitaRes = await page.evaluate(async ({ fecha, csrf }) => {
            const fd = new FormData();
            fd.append('modo_reserva', 'profesional');
            fd.append('profesionalId', '1');
            fd.append('fecha', fecha);
            fd.append('hora', '10:00');
            fd.append('hora_fin', '10:05');
            fd.append('duracion_total_minutos', '5');
            fd.append('servicios', '1,2');
            fd.append('csrf_token', csrf);
            const r = await fetch('/api/citas', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: fd
            });
            const bodyReserve = await r.json();

            const rDispOcupada = await fetch(`/api/disponibilidad?profesionalId=1&fecha=${encodeURIComponent(fecha)}&servicios=1,2`, {
                headers: { 'Accept': 'application/json' }
            });
            const dispOcupada = await rDispOcupada.json();

            return {
                status: r.status,
                body: bodyReserve,
                dispStatus: rDispOcupada.status,
                dispBody: dispOcupada
            };
        }, { fecha: validDate, csrf: csrfTokenCliente });

        const citaTemporalId = tempCitaRes.body && tempCitaRes.body.resultado ? tempCitaRes.body.resultado.id : null;
        if (
            tempCitaRes.status !== 200 ||
            !citaTemporalId ||
            tempCitaRes.body.profesionalId !== 1 ||
            tempCitaRes.body.hora_inicio !== '10:00' ||
            tempCitaRes.body.hora_fin !== '11:00' ||
            tempCitaRes.body.duracion_total_minutos !== 60
        ) {
            throw new Error(`Fallo al crear reserva con profesional en POST /api/citas: ${JSON.stringify(tempCitaRes)}`);
        }

        const paresTrasReserva = Array.isArray(tempCitaRes.dispBody.intervalos)
            ? tempCitaRes.dispBody.intervalos.map(i => `${i.inicio}-${i.fin}`)
            : [];
        if (
            tempCitaRes.dispStatus !== 200 ||
            paresTrasReserva.includes('10:00-11:00') ||
            paresTrasReserva.includes('10:15-11:15') ||
            !paresTrasReserva.includes('11:00-12:00')
        ) {
            throw new Error(`GET /api/disponibilidad no descontó [10:00, 11:00) o no conservó contiguo [11:00, 12:00): ${JSON.stringify(paresTrasReserva)}`);
        }

        // Intentar reserva solapada en 10:30 sobre el mismo profesional -> debe responder HTTP 409 conflicto_ocupacion
        const cookiesCliente = await page.cookies();
        const cookieHeaderCliente = cookiesCliente.map(c => `${c.name}=${c.value}`).join('; ');
        const paramsConflicto = new URLSearchParams({
            modo_reserva: 'profesional',
            profesionalId: '1',
            fecha: validDate,
            hora: '10:30',
            servicios: '1,2',
            csrf_token: csrfTokenCliente
        });
        const rConflicto = await fetch(`${BASE_URL}/api/citas`, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': csrfTokenCliente,
                'Cookie': cookieHeaderCliente
            },
            body: paramsConflicto.toString()
        });
        const conflictoStatus = rConflicto.status;
        const conflictoBody = await rConflicto.json();
        if (
            conflictoStatus !== 409 ||
            conflictoBody.resultado !== false ||
            conflictoBody.codigo !== 'conflicto_ocupacion'
        ) {
            throw new Error(`Se esperaba HTTP 409 conflicto_ocupacion al solapar reserva en 10:30, obtenido (${conflictoStatus}): ${JSON.stringify(conflictoBody)}`);
        }

        // Crear una segunda cita temporal del cliente (14:00-15:00 en Sofía Andrade) y cancelarla desde la sección #mis-citas de la UI
        const citaCancelarClienteId = await page.evaluate(async ({ fecha, csrf }) => {
            const fd = new FormData();
            fd.append('modo_reserva', 'profesional');
            fd.append('profesionalId', '1');
            fd.append('fecha', fecha);
            fd.append('hora', '14:00');
            fd.append('servicios', '1,2');
            fd.append('csrf_token', csrf);
            const r = await fetch('/api/citas', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: fd
            });
            const j = await r.json();
            await cargarMisCitas();
            return j.id;
        }, { fecha: validDate, csrf: csrfTokenCliente });

        await page.waitForSelector(`#listado-mis-citas .btn-cancelar-cita[data-cita-id="${citaCancelarClienteId}"]`, { timeout: 5000 });
        await Promise.all([
            page.waitForResponse(res => res.url().includes('/api/eliminar') && res.request().method() === 'POST'),
            page.click(`#listado-mis-citas .btn-cancelar-cita[data-cita-id="${citaCancelarClienteId}"]`)
        ]);
        await page.waitForFunction(
            (idBorrado) => {
                const item = document.querySelector(`#listado-mis-citas li[data-cita-id="${idBorrado}"]`);
                return item && /cancelada/i.test(item.textContent) && !item.querySelector('.btn-cancelar-cita');
            },
            { timeout: 5000 },
            citaCancelarClienteId
        );

        recordTest('RES-01', 'Reserva con profesional en POST /api/citas, descuento de ocupación, rechazo 409 por solapamiento y cancelación desde #mis-citas en UI', true,
            `Cita ID ${citaTemporalId} [10:00, 11:00) creada y descontada; solape 10:30 rechazado con 409; cita ${citaCancelarClienteId} [14:00, 15:00) visualizada y cancelada por el cliente en #mis-citas.`);

        // ------------------------------------------------------------------
        // RECORRIDO 4: Resumen Completo (Profesional, Inicio, Fin, Duración y Total), Envío Real vía Fetch y SweetAlert2
        // ------------------------------------------------------------------
        console.log('\n>>> [RECORRIDO 4] Resumen completo de reserva con profesional, envío real vía fetch POST y SweetAlert2...');

        await page.click('#siguiente');
        await page.waitForFunction(() => document.querySelector('#paso-3.mostrar') !== null);

        const textoResumenCompleto = await page.$eval('.contenido-resumen', el => el.textContent);
        if (
            !textoResumenCompleto.includes('Resumen de Servicios') ||
            !textoResumenCompleto.includes('Valeria Castro') ||
            !textoResumenCompleto.includes('11:30 - 13:00') ||
            !textoResumenCompleto.includes('90 min') ||
            !textoResumenCompleto.includes('195.00')
        ) {
            throw new Error(`El resumen en Paso 3 no contiene profesional, intervalo, duración total y precio total esperados: '${textoResumenCompleto}'`);
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

        if (
            apiStatus !== 200 ||
            !apiJson.resultado ||
            apiJson.profesionalId !== 2 ||
            apiJson.hora_inicio !== '11:30' ||
            apiJson.hora_fin !== '13:00' ||
            apiJson.duracion_total_minutos !== 90
        ) {
            throw new Error(`Respuesta de API de reserva desde UI no exitosa o incompleta: HTTP ${apiStatus}, cuerpo: ${JSON.stringify(apiJson)}`);
        }

        const citaIdCreada = apiJson.id || (apiJson.resultado && apiJson.resultado.id);

        await page.waitForSelector('.swal2-popup', { timeout: 5000 });
        const swalTitle = await page.$eval('.swal2-title', el => el.textContent);
        const swalIcon = await page.$eval('.swal2-icon.swal2-success', el => el !== null);

        if (!swalTitle.includes('Cita Creada') || !swalIcon) {
            throw new Error(`Modal SweetAlert2 inesperado: título='${swalTitle}', iconoExito=${swalIcon}`);
        }

        recordTest('REC-04', 'Renderizado de resumen con profesional, intervalo [11:30, 13:00), duración 90 min y total $195.00, envío con CSRF y SweetAlert2', true,
            `Resumen completo validado; POST /api/citas exitoso (id: ${citaIdCreada}, profesionalId: 2, 11:30-13:00, 90 min); SweetAlert2 ('${swalTitle}') desplegado.`);

        // Cerrar sesión de cliente y autenticar como Administrador para verificar /admin y /api/eliminar
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.evaluate(() => {
                const logoutForm = document.querySelector('.barra form[action="/logout"]');
                if (!logoutForm) throw new Error('Formulario /logout no encontrado');
                logoutForm.submit();
            })
        ]);

        console.log('\n>>> [FASE 2B / FASE 5 - ADMIN CITAS] Consulta de citas por fecha en /admin (profesional, intervalo y snapshot histórico) y eliminación en /api/eliminar...');
        // Tras cerrar la sesión de cliente el administrador entra por el portal del equipo.
        await page.goto(`${BASE_URL}/equipo/login`, { waitUntil: 'networkidle0' });
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
            !textoCitasAdminAntes.includes('Valeria Castro') ||
            !textoCitasAdminAntes.includes('11:30 - 13:00 (90 min)') ||
            !textoCitasAdminAntes.includes('Sofía Andrade') ||
            !textoCitasAdminAntes.includes('10:00 - 11:00 (60 min)') ||
            !textoCitasAdminAntes.includes('Masaje Capilar Premium 115.00 (60 min)') ||
            !textoCitasAdminAntes.includes('$ 195')
        ) {
            throw new Error(`La vista /admin?fecha=${validDate} no mostró profesional, intervalo, duración y snapshot esperados: ${textoCitasAdminAntes}`);
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

        // Verificar que tras eliminar la cita con profesional en /api/eliminar, el intervalo 10:00-11:00 se libera en GET /api/disponibilidad
        const dispTrasEliminar = await page.evaluate(async (fecha) => {
            const r = await fetch(`/api/disponibilidad?profesionalId=1&fecha=${encodeURIComponent(fecha)}&servicios=1,2`, {
                headers: { 'Accept': 'application/json' }
            });
            return { status: r.status, body: await r.json() };
        }, validDate);
        const paresTrasEliminar = Array.isArray(dispTrasEliminar.body.intervalos)
            ? dispTrasEliminar.body.intervalos.map(i => `${i.inicio}-${i.fin}`)
            : [];
        if (dispTrasEliminar.status !== 200 || !paresTrasEliminar.includes('10:00-11:00')) {
            throw new Error(`La disponibilidad de 10:00-11:00 no se liberó tras eliminar la cita ${citaTemporalId}: ${JSON.stringify(paresTrasEliminar)}`);
        }

        recordTest('ADMIN-05', 'Consulta administrativa por fecha en /admin (profesional, intervalo y snapshot), eliminación en /api/eliminar y liberación de ocupación', true,
            `Citas consultadas en /admin?fecha=${validDate} con profesional e intervalo; cita temporal ID ${citaTemporalId} eliminada liberando 10:00-11:00; cita principal ID ${citaIdCreada} ($195, Valeria Castro, 11:30-13:00) conservada.`);

        // ------------------------------------------------------------------
        // BLOQUE FASE 6B (REP-01): Reportes Administrativos (/admin/reportes y /admin/reportes/exportar)
        // ------------------------------------------------------------------
        console.log('\n>>> [FASE 6B - REPORTES] Consulta de reportes administrativos, ocupación, distinción de importes y exportación CSV...');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('a[href="/admin/reportes"]')
        ]);

        if (!page.url().includes('/admin/reportes')) {
            throw new Error(`Se esperaba navegación a /admin/reportes, URL actual: ${page.url()}`);
        }

        await page.click('[data-chart-switch="graficas-periodo"]');
        if (!await page.$eval('#graficas-periodo', node => node.hidden)) throw new Error('El control de gráficas no ocultó el panel.');
        await page.click('[data-chart-switch="graficas-periodo"]');
        if (!await page.$eval('#graficas-periodo', node => !node.hidden)) throw new Error('El control de gráficas no restauró el panel.');
        await verifyResponsivePage('reportes-admin');
        recordTest('UI-01', 'Diseño responsivo sin desbordamiento horizontal en cuatro pantallas', true,
            'Login, servicios, cita y reportes probados a 320, 390, 768 y 1440px; 16 capturas PNG, avatar local cargado y buscador con estado vacío y restauración del catálogo.');

        // 1. Filtrar rango exacto [validDate, validDate] sin filtro de profesional ni servicio
        await page.evaluate((fechaRango) => {
            document.querySelector('#fecha_inicio').value = fechaRango;
            document.querySelector('#fecha_fin').value = fechaRango;
            document.querySelector('#profesional_id').value = '';
            document.querySelector('#servicio_id').value = '';
        }, validDate);

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('#btn-filtrar-reportes')
        ]);

        const kpiResumen = await page.evaluate(() => ({
            totalCitas: document.querySelector('#kpi-total-citas')?.textContent.trim(),
            ocupacionGlobal: document.querySelector('#kpi-ocupacion-global')?.textContent.trim(),
            importeReservado: document.querySelector('#kpi-importe-reservado')?.textContent.trim(),
            ingresoPagado: document.querySelector('#kpi-ingreso-pagado')?.textContent.trim(),
            textoProfValeria: document.querySelector('#tabla-ocupacion-profesionales tr[data-profesional-id="2"]')?.textContent.replace(/\s+/g, ' ').trim() || '',
            textoProfSofia: document.querySelector('#tabla-ocupacion-profesionales tr[data-profesional-id="1"]')?.textContent.replace(/\s+/g, ' ').trim() || '',
            textoServicios: document.querySelector('#tabla-desglose-servicios')?.textContent.replace(/\s+/g, ' ').trim() || ''
        }));

        if (
            kpiResumen.totalCitas !== '1' ||
            kpiResumen.importeReservado !== '$195.00' ||
            kpiResumen.ingresoPagado !== '$0.00' ||
            !kpiResumen.textoProfValeria.includes('90 min / 540 min') ||
            !kpiResumen.textoProfValeria.includes('16.67%') ||
            !kpiResumen.textoProfValeria.includes('$195.00') ||
            !kpiResumen.textoProfSofia.includes('0 min / 390 min') ||
            !kpiResumen.textoServicios.includes('Masaje Capilar Premium') ||
            !kpiResumen.textoServicios.includes('$115.00')
        ) {
            throw new Error(`Métricas inesperadas en /admin/reportes para ${validDate}: ${JSON.stringify(kpiResumen)}`);
        }

        // 2. Filtrar por profesional Valeria Castro (id=2) y servicio Masaje Capilar Premium (id=4)
        await page.select('#profesional_id', '2');
        await page.select('#servicio_id', '4');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('#btn-filtrar-reportes')
        ]);

        const kpiFiltradoSrv = await page.evaluate(() => ({
            importeReservado: document.querySelector('#kpi-importe-reservado')?.textContent.trim(),
            ingresoPagado: document.querySelector('#kpi-ingreso-pagado')?.textContent.trim(),
            exportHref: document.querySelector('#btn-exportar-csv')?.getAttribute('href')
        }));

        if (
            kpiFiltradoSrv.importeReservado !== '$115.00' ||
            kpiFiltradoSrv.ingresoPagado !== '$0.00' ||
            !kpiFiltradoSrv.exportHref
        ) {
            throw new Error(`El filtro por profesional=2 y servicio=4 en /admin/reportes no mostró subtotal histórico $115.00 vs $0.00 pagado: ${JSON.stringify(kpiFiltradoSrv)}`);
        }

        // 3. Verificar descarga/respuesta de /admin/reportes/exportar
        const csvResp = await page.evaluate(async (href) => {
            const r = await fetch(href);
            return {
                status: r.status,
                contentType: r.headers.get('content-type') || '',
                contentDisposition: r.headers.get('content-disposition') || '',
                body: await r.text()
            };
        }, kpiFiltradoSrv.exportHref);

        if (
            csvResp.status !== 200 ||
            !csvResp.contentType.toLowerCase().includes('text/csv') ||
            !csvResp.contentDisposition.includes('reporte_reservas_') ||
            !csvResp.body.includes('Valeria Castro') ||
            !csvResp.body.includes('Masaje Capilar Premium') ||
            !csvResp.body.includes('115.00')
        ) {
            throw new Error(`Respuesta inválida en /admin/reportes/exportar: ${JSON.stringify(csvResp)}`);
        }

        recordTest('REP-01', 'Reportes administrativos (/admin/reportes y /admin/reportes/exportar): ocupación sobre horas disponibles, importe reservado histórico vs ingreso pagado y exportación CSV', true,
            `Valeria Castro 16.67% (90/540 min), Sofía Andrade 0.00% (0/390 min); reservado $195.00 vs pagado $0.00; filtro servicio #4 ($115.00) y exportación CSV verificados.`);

        // ------------------------------------------------------------------
        // BLOQUE FASE 6C (PAG-01): Base para Pagos (Mock Gateway, Prohibición Tarjetas/Redirect, Webhook Idempotente y Reportes)
        // ------------------------------------------------------------------
        console.log('\n>>> [FASE 6C - PAGOS] Flujo de pago con MockPaymentGateway, rechazo de datos de tarjeta y redirect falso, webhook firmado idempotente y actualización en /admin/reportes...');

        const cookiesAdminPagos = await page.cookies();
        const cookieHeaderAdminPagos = cookiesAdminPagos.map(c => `${c.name}=${c.value}`).join('; ');
        const csrfTokenAdminPagos = await page.$eval('.barra form[action="/logout"] input[name="csrf_token"]', el => el.value);

        // 1. Verificar prohibición de enviar datos sensibles de tarjeta (HTTP 422)
        const rTarjetaProhibida = await fetch(`${BASE_URL}/api/pagos/iniciar`, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'Cookie': cookieHeaderAdminPagos
            },
            body: JSON.stringify({
                csrf_token: csrfTokenAdminPagos,
                cita_id: citaIdCreada,
                numero_tarjeta: '4111222233334444',
                cvv: '123',
                fecha_expiracion: '12/29'
            })
        });
        if (rTarjetaProhibida.status !== 422) {
            throw new Error(`Se esperaba HTTP 422 al enviar datos sensibles de tarjeta a /api/pagos/iniciar, obtenido: ${rTarjetaProhibida.status}`);
        }

        // 2. Iniciar pago enviando monto manipulado ($1.00) -> servidor debe calcular $195.00
        const rIniciarPago = await fetch(`${BASE_URL}/api/pagos/iniciar`, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'Cookie': cookieHeaderAdminPagos
            },
            body: JSON.stringify({
                csrf_token: csrfTokenAdminPagos,
                cita_id: citaIdCreada,
                monto: '1.00',
                metodo_pago: 'tarjeta_mock'
            })
        });
        const bodyIniciarPago = await rIniciarPago.json();
        if (
            rIniciarPago.status !== 200 ||
            !bodyIniciarPago.resultado ||
            bodyIniciarPago.monto_cliente_ignorado !== true ||
            Number(bodyIniciarPago.monto_calculado_servidor) !== 195 ||
            !bodyIniciarPago.pago ||
            bodyIniciarPago.pago.estado !== 'pendiente'
        ) {
            throw new Error(`Respuesta inesperada en POST /api/pagos/iniciar: HTTP ${rIniciarPago.status}, body=${JSON.stringify(bodyIniciarPago)}`);
        }
        const referenciaPagoE2E = bodyIniciarPago.pago.referencia_pago;

        // 3. Visitar GET /pago/resultado con parámetros manipulados (?status=completado&estado=pagado) -> debe permanecer en 'pendiente'
        await page.goto(`${BASE_URL}/pago/resultado?referencia=${encodeURIComponent(referenciaPagoE2E)}&status=completado&estado=pagado&aprobado=1`, { waitUntil: 'networkidle0' });
        const estadoTrasRedirect = await page.evaluate(() => ({
            estadoAttr: document.querySelector('#pago-estado-real')?.getAttribute('data-estado') || '',
            advertencia: document.querySelector('#pago-advertencia-redirect')?.textContent.trim() || ''
        }));
        if (estadoTrasRedirect.estadoAttr !== 'pendiente'
            || !estadoTrasRedirect.advertencia.includes('no confirma el pago')
            || !estadoTrasRedirect.advertencia.includes('estado validado por el proveedor')) {
            throw new Error(`GET /pago/resultado no bloqueó confirmación por redirección del navegador: ${JSON.stringify(estadoTrasRedirect)}`);
        }

        // 4. Enviar webhook firmado con HMAC-SHA256 (y repetirlo para verificar idempotencia)
        const webhookSecret = process.env.PAYMENT_WEBHOOK_SECRET || 'appsalon_mock_webhook_secret_2026';
        const firmaWebhook = crypto
            .createHmac('sha256', webhookSecret)
            .update(`${referenciaPagoE2E}|payment.succeeded|completado|195.00`)
            .digest('hex');

        const payloadWebhookE2E = {
            referencia_pago: referenciaPagoE2E,
            evento: 'payment.succeeded',
            estado: 'completado',
            monto: 195.00
        };

        const rWebhook1 = await fetch(`${BASE_URL}/api/pagos/webhook`, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Mock-Signature': firmaWebhook
            },
            body: JSON.stringify(payloadWebhookE2E)
        });
        const bodyWh1 = await rWebhook1.json();

        const rWebhook2 = await fetch(`${BASE_URL}/api/pagos/webhook`, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Mock-Signature': firmaWebhook
            },
            body: JSON.stringify(payloadWebhookE2E)
        });
        const bodyWh2 = await rWebhook2.json();

        if (
            rWebhook1.status !== 200 ||
            !bodyWh1.resultado ||
            bodyWh1.idempotente !== false ||
            bodyWh1.pago?.estado !== 'completado' ||
            rWebhook2.status !== 200 ||
            bodyWh2.idempotente !== true ||
            bodyWh2.ya_procesado !== true
        ) {
            throw new Error(`Fallo en confirmación o idempotencia de POST /api/pagos/webhook: wh1=${JSON.stringify(bodyWh1)}, wh2=${JSON.stringify(bodyWh2)}`);
        }

        // 5. Verificar en navegador GET /pago/resultado y actualización de ingreso_pagado_total en /admin/reportes
        await page.goto(`${BASE_URL}/pago/resultado?referencia=${encodeURIComponent(referenciaPagoE2E)}`, { waitUntil: 'networkidle0' });
        const estadoConfirmadoDom = await page.evaluate(() => ({
            estadoAttr: document.querySelector('#pago-estado-real')?.getAttribute('data-estado') || '',
            montoTexto: document.querySelector('#pago-monto-servidor')?.textContent.trim() || ''
        }));
        if (estadoConfirmadoDom.estadoAttr !== 'completado' || !estadoConfirmadoDom.montoTexto.includes('$195.00')) {
            throw new Error(`GET /pago/resultado no mostró estado completado tras webhook: ${JSON.stringify(estadoConfirmadoDom)}`);
        }

        await page.goto(`${BASE_URL}/admin/reportes?fecha_inicio=${encodeURIComponent(validDate)}&fecha_fin=${encodeURIComponent(validDate)}`, { waitUntil: 'networkidle0' });
        const kpiTrasPago = await page.evaluate(() => ({
            importeReservado: document.querySelector('#kpi-importe-reservado')?.textContent.trim() || '',
            ingresoPagado: document.querySelector('#kpi-ingreso-pagado')?.textContent.trim() || '',
            textoDetalle: document.querySelector('#listado-reservas-reporte')?.textContent.replace(/\s+/g, ' ').trim() || ''
        }));
        if (
            kpiTrasPago.importeReservado !== '$195.00' ||
            kpiTrasPago.ingresoPagado !== '$195.00' ||
            !kpiTrasPago.textoDetalle.includes('Pagado')
        ) {
            throw new Error(`/admin/reportes no reflejó el ingreso efectivamente pagado ($195.00) tras confirmar el pago: ${JSON.stringify(kpiTrasPago)}`);
        }

        recordTest('PAG-01', 'Flujo de pago con MockPaymentGateway (/api/pagos/iniciar, /pago/resultado, /api/pagos/webhook) y reflejo de ingreso pagado en /admin/reportes', true,
            `Referencia ${referenciaPagoE2E} calculada en servidor ($195.00 ignorando $1.00 cliente); rechazo 422 de tarjeta y bloqueo de redirect verificados; webhook HMAC idempotente y /admin/reportes actualizado a $195.00 pagado.`);

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
        // BLOQUE FASE 6A (NOTIF-01): Verificación de correos de confirmación y cancelación en buzón SMTP mock
        // ------------------------------------------------------------------
        console.log('\n>>> [FASE 6A - NOTIFICACIONES] Verificación de correos de confirmación y cancelación de citas en buzón SMTP mock...');

        const mailboxPath = process.env.TEST_MAILBOX_PATH || path.resolve(__dirname, 'mailbox.json');
        // El worker del outbox puede despachar después de terminar la petición HTTP.
        const deadline = Date.now() + 10000;
        while (Date.now() < deadline) {
            if (fs.existsSync(mailboxPath)) {
                try {
                    const messages = JSON.parse(fs.readFileSync(mailboxPath, 'utf-8'));
                    if (Array.isArray(messages) && messages.length >= 5) {
                        const bodies = messages.filter(m => String(m.to || '').includes('carlos@correo.com'))
                            .map(m => String(m.body || '').replace(/=\r?\n/g, '')
                                .replace(/=([0-9A-Fa-f]{2})/g, (_, hex) => String.fromCharCode(parseInt(hex, 16))));
                        if (bodies.some(b => b.includes(`#${citaIdCreada}`) && b.includes('Valeria Castro') && b.includes('11:30 - 13:00'))
                            && bodies.some(b => b.includes('Cancelada') && (b.includes(`#${citaTemporalId}`) || b.includes('14:00 - 15:00') || b.includes('10:00 - 11:00')))) break;
                    }
                } catch (error) { /* SMTP puede estar reemplazando el archivo. */ }
            }
            await new Promise(resolve => setTimeout(resolve, 200));
        }
        if (!fs.existsSync(mailboxPath)) {
            throw new Error(`No se encontró el archivo de buzón SMTP mock en ${mailboxPath}`);
        }
        const correosCapturados = JSON.parse(fs.readFileSync(mailboxPath, 'utf-8'));
        if (!Array.isArray(correosCapturados) || correosCapturados.length < 5) {
            throw new Error(`Se esperaban al menos 5 correos en el buzón SMTP mock (confirmaciones, cancelaciones y auth), encontrados: ${Array.isArray(correosCapturados) ? correosCapturados.length : 0}`);
        }

        const correosCarlos = correosCapturados.filter(m => String(m.to || '').includes('carlos@correo.com'));
        const cuerposDecodificados = correosCarlos.map(m => {
            const raw = String(m.body || '');
            // Soportar quoted-printable básico (=XX y saltos suaves =\r\n)
            return raw.replace(/=\r?\n/g, '').replace(/=([0-9A-Fa-f]{2})/g, (_, hex) => String.fromCharCode(parseInt(hex, 16)));
        });

        const tieneConfirmacionPrincipal = cuerposDecodificados.some(b =>
            b.includes(`#${citaIdCreada}`) && b.includes('Valeria Castro') && b.includes('11:30 - 13:00')
        );
        const tieneCancelacion = cuerposDecodificados.some(b =>
            b.includes('Cancelada') && (b.includes(`#${citaTemporalId}`) || b.includes('14:00 - 15:00') || b.includes('10:00 - 11:00'))
        );

        if (!tieneConfirmacionPrincipal || !tieneCancelacion) {
            throw new Error(`Faltan correos transaccionales de confirmación o cancelación en mailbox.json (confirmacionPrincipal=${tieneConfirmacionPrincipal}, cancelacion=${tieneCancelacion})`);
        }

        recordTest('NOTIF-01', 'Despacho SMTP de correos transaccionales de confirmación y cancelación de citas en buzón de pruebas', true,
            `${correosCapturados.length} correos capturados en buzón SMTP mock; confirmación de cita #${citaIdCreada} (Valeria Castro, 11:30 - 13:00) y cancelaciones verificadas.`);

        await require('./platform_e2e_checks')({ browser, baseUrl: BASE_URL, recordTest, screenshotDir });

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
            responsiveResults,
            consoleErrors,
            failedRequests
        };

        fs.writeFileSync(
            process.env.TEST_BROWSER_REPORT_PATH || path.resolve(__dirname, 'browser_test_report.json'),
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

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
const { execFileSync } = require('child_process');

function consultarHorariosEnMySql(diaSemana) {
    const dbContainer = process.env.TEST_DB_CONTAINER;
    const dbName = process.env.TEST_DB_NAME || 'appsalon_browser_test';
    if (!dbContainer) return null;
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

        // Probar rechazo de horario invertido en Lunes (18:00 a 09:00)
        await page.evaluate(() => {
            document.querySelector('#horario_activo_1').checked = true;
            document.querySelector('#horario_inicio_1').value = '18:00';
            document.querySelector('#horario_fin_1').value = '09:00';
        });
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('#form-horarios-semanales input[type="submit"]')
        ]);
        const alertaHorarioInvertido = await page.$eval('.alerta.error', el => el.textContent.trim());
        if (!alertaHorarioInvertido.includes('estrictamente anterior')) {
            throw new Error(`Se esperaba alerta de horario invertido, obtenido: ${alertaHorarioInvertido}`);
        }

        // Configurar horario válido en Lunes (09:00 a 18:00)
        await page.evaluate(() => {
            document.querySelector('#horario_activo_1').checked = true;
            document.querySelector('#horario_inicio_1').value = '09:00';
            document.querySelector('#horario_fin_1').value = '18:00';
        });
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('#form-horarios-semanales input[type="submit"]')
        ]);

        // Añadir descanso en Lunes (13:00 a 14:00)
        await page.select('#descanso_dia_semana', '1');
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
            `'Sofía Andrade' creada con servicios, horario Lunes 09:00-18:00, descanso 13:00-14:00, bloqueo ${validDate} 15:00-16:30 y ciclo Inactivo/Activo verificado.`);

        // ------------------------------------------------------------------
        // PROF-02: Múltiples franjas por día, guardado sin cambios y retirada explícita
        // ------------------------------------------------------------------
        await page.goto(`${BASE_URL}${hrefHorarios}`, { waitUntil: 'networkidle0' });

        // 1. Configurar dos franjas el mismo día en Martes (dia=2: 08:00-12:00 y 14:00-18:00)
        await page.evaluate(() => {
            document.querySelector('#horario_activo_2').checked = true;
            document.querySelector('#horario_inicio_2').value = '08:00';
            document.querySelector('#horario_fin_2').value = '12:00';
        });
        await page.click('.btn-agregar-franja[data-dia="2"]');
        await page.evaluate(() => {
            document.querySelector('#horario_activo_2_1').checked = true;
            document.querySelector('#horario_inicio_2_1').value = '14:00';
            document.querySelector('#horario_fin_2_1').value = '18:00';
        });

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('#form-horarios-semanales input[type="submit"]')
        ]);

        // 2. Cargar vista con las dos franjas del Martes y comprobar que ambas están renderizadas
        const franjasCargadasMartes = await page.$$eval(
            '.horario-dia-franjas[data-dia-contenedor="2"] .horario-franja-fila',
            filas => filas.map(f => ({
                activo: f.querySelector('.horario-activo-check').checked,
                inicio: f.querySelector('.horario-inicio-input').value,
                fin: f.querySelector('.horario-fin-input').value
            }))
        );
        if (
            franjasCargadasMartes.length !== 2 ||
            !franjasCargadasMartes[0].activo || franjasCargadasMartes[0].inicio !== '08:00' || franjasCargadasMartes[0].fin !== '12:00' ||
            !franjasCargadasMartes[1].activo || franjasCargadasMartes[1].inicio !== '14:00' || franjasCargadasMartes[1].fin !== '18:00'
        ) {
            throw new Error(`La vista no conservó las 2 franjas del Martes al cargar: ${JSON.stringify(franjasCargadasMartes)}`);
        }

        // 3. Guardar SIN CAMBIOS y verificar que ambas franjas se preservan en MySQL y en el DOM
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('#form-horarios-semanales input[type="submit"]')
        ]);

        const mysqlMartesTrasGuardarSinCambios = consultarHorariosEnMySql(2);
        if (mysqlMartesTrasGuardarSinCambios !== null) {
            if (
                mysqlMartesTrasGuardarSinCambios.length !== 2 ||
                mysqlMartesTrasGuardarSinCambios[0] !== '08:00:00-12:00:00' ||
                mysqlMartesTrasGuardarSinCambios[1] !== '14:00:00-18:00:00'
            ) {
                throw new Error(`En MySQL no se preservaron ambas franjas tras guardar sin cambios: ${JSON.stringify(mysqlMartesTrasGuardarSinCambios)}`);
            }
        }

        const franjasTrasGuardarSinCambios = await page.$$eval(
            '.horario-dia-franjas[data-dia-contenedor="2"] .horario-franja-fila',
            filas => filas.map(f => `${f.querySelector('.horario-inicio-input').value}-${f.querySelector('.horario-fin-input').value}`)
        );
        if (
            franjasTrasGuardarSinCambios.length !== 2 ||
            franjasTrasGuardarSinCambios[0] !== '08:00-12:00' ||
            franjasTrasGuardarSinCambios[1] !== '14:00-18:00'
        ) {
            throw new Error(`El DOM no preservó ambas franjas tras guardar sin cambios: ${JSON.stringify(franjasTrasGuardarSinCambios)}`);
        }

        // 4. Retirada explícita de la primera franja del Martes (08:00-12:00) y comprobar que elimina únicamente la franja elegida
        await page.click('.horario-dia-franjas[data-dia-contenedor="2"] .horario-franja-fila[data-franja-index="0"] .btn-retirar-franja');
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle0' }),
            page.click('#form-horarios-semanales input[type="submit"]')
        ]);

        const mysqlMartesTrasRetirar = consultarHorariosEnMySql(2);
        if (mysqlMartesTrasRetirar !== null) {
            if (
                mysqlMartesTrasRetirar.length !== 1 ||
                mysqlMartesTrasRetirar[0] !== '14:00:00-18:00:00'
            ) {
                throw new Error(`En MySQL la retirada explícita no dejó únicamente la franja 14:00-18:00: ${JSON.stringify(mysqlMartesTrasRetirar)}`);
            }
        }

        const franjasTrasRetirar = await page.$$eval(
            '.horario-dia-franjas[data-dia-contenedor="2"] .horario-franja-fila',
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
            throw new Error(`Tras retirar la primera franja del Martes, no quedó únicamente 14:00-18:00 activa: ${JSON.stringify(franjasTrasRetirar)}`);
        }

        recordTest('PROF-02', 'Carga de múltiples franjas del mismo día, guardado sin cambios verificado en MySQL y retirada explícita de franja individual', true,
            'Martes 08:00-12:00 y 14:00-18:00 preservados en MySQL al guardar sin cambios; retirada explícita eliminó solo 08:00-12:00 conservando 14:00-18:00.');

        // Crear segunda profesional activa ("Valeria Castro") habilitada para Servicio 1 y "Masaje Capilar Premium" (Servicio 4)
        // con horario Lunes 09:00-18:00 para probar filtrado de compatibilidad y reserva completa desde la UI en Fase 5
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
        await page.evaluate(() => {
            document.querySelector('#horario_activo_1').checked = true;
            document.querySelector('#horario_inicio_1').value = '09:00';
            document.querySelector('#horario_fin_1').value = '18:00';
        });
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

        // 3.4 Seleccionar fecha laborable válida (Lunes) -> carga dinámica de intervalos de 90 min desde GET /api/disponibilidad
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
            (idBorrado) => !document.querySelector(`#listado-mis-citas li[data-cita-id="${idBorrado}"]`),
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

/**
 * Layout regression against an isolated, populated AppSalon test database.
 * No records are submitted: only logins and temporary DOM stress are performed.
 * APP_URL / CHROME_BIN select the test server and installed browser.
 * TEST_ADMIN_EMAIL, TEST_CLIENT_EMAIL, TEST_PASSWORD override E2E credentials.
 * TEST_PROFESSIONAL_ID / TEST_SERVICE_ID select existing fixture records.
 */
const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer-core');

const baseUrl = process.env.APP_URL || 'http://127.0.0.1:3000';
const chrome = process.env.CHROME_BIN || ['/usr/bin/chromium', '/usr/bin/google-chrome', '/usr/bin/chromium-browser'].find(file => fs.existsSync(file));
if (!chrome) throw new Error('Define CHROME_BIN con la ruta de Chrome o Chromium.');
const password = process.env.TEST_PASSWORD || 'Password123!';
const professionalId = process.env.TEST_PROFESSIONAL_ID || '1';
const serviceId = process.env.TEST_SERVICE_ID || '1';
const widths = [320, 360, 390, 768, 1024, 1440];
const outputDir = path.resolve(process.env.TEST_RESPONSIVE_OUTPUT_DIR || 'storage/e2e/responsive');
fs.mkdirSync(outputDir, { recursive: true });
const results = [];
const failures = [];
const scriptErrors = [];

async function settle(page) {
    // Chrome completo puede suspender requestAnimationFrame en pestañas de fondo.
    await page.bringToFront();
    await page.evaluate(async () => {
        await Promise.race([
            (async () => {
                await document.fonts.ready;
                await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
            })(),
            new Promise((_, reject) => setTimeout(() => reject(new Error('La vista no alcanzó un layout estable en 5 segundos.')), 5000))
        ]);
    });
}

async function geometry(page, label) {
    await settle(page);
    const result = await page.evaluate(() => {
        const content = document.querySelector('main') || document.body;
        const localScroll = '.ui-table-scroll, .tabla-contenedor, .ops-table-wrap, .growth-table-wrap, .crm-grafico__lienzo, .crm-tabla';
        const issues = [];
        const describe = el => `${el.tagName.toLowerCase()}${el.id ? '#' + el.id : '.' + String(el.className).replace(/\s+/g, '.')}`;
        for (const el of [content, ...content.querySelectorAll('*')]) {
            const rect = el.getBoundingClientRect();
            const style = getComputedStyle(el);
            if (!rect.width || !rect.height || style.visibility === 'hidden' || el.closest('.ui-visually-hidden, [aria-hidden="true"]')) continue;
            // Wide tables may exceed the viewport only inside their own scroll
            // region. All other controls and text must remain in the viewport.
            let withinTable = false;
            for (let parent = el.parentElement; parent && parent !== content; parent = parent.parentElement) {
                if (parent.matches(localScroll) && /auto|scroll/.test(getComputedStyle(parent).overflowX)) {
                    withinTable = true;
                    break;
                }
            }
            if (!withinTable && (rect.right > innerWidth + 1 || rect.left < -1)) {
                issues.push({ element: describe(el), left: Math.round(rect.left), right: Math.round(rect.right) });
            }
            // Hiding overflow on a card/body can conceal a regression; reject
            // clipped content rather than accepting a small document width.
            if (!withinTable && !el.matches('input, select, textarea, img, svg, .ui-chart-track, .ui-chart-bar') &&
                /hidden|clip/.test(style.overflowX) && el.scrollWidth > el.clientWidth + 1) {
                issues.push({ element: describe(el), clipped: el.scrollWidth - el.clientWidth });
            }
        }
        return {
            viewport: document.documentElement.clientWidth,
            document: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth),
            issues: issues.slice(0, 12),
            hiddenMain: /hidden|clip/.test(getComputedStyle(content).overflowX)
        };
    });
    if (result.document > result.viewport + 1 || result.issues.length || result.hiddenMain) {
        throw new Error(`${label}: contenido desbordado u oculto ${JSON.stringify(result)}`);
    }
    results.push({ label, width: result.viewport, status: 'passed' });
}

async function verifyTableKeyboard(page) {
    const wrappers = await page.$$('.ui-table-scroll, .tabla-contenedor, .ops-table-wrap, .growth-table-wrap');
    for (const wrapper of wrappers) {
        const info = await wrapper.evaluate(el => ({
            visible: el.getBoundingClientRect().height > 0,
            overflow: el.scrollWidth > el.clientWidth + 1,
            labelled: el.getAttribute('role') === 'region' && !!(el.getAttribute('aria-label') || el.getAttribute('aria-labelledby')),
            focusable: el.tabIndex === 0
        }));
        if (!info.visible || !info.overflow) continue;
        if (!info.labelled || !info.focusable) throw new Error('Tabla con scroll sin región etiquetada accesible por teclado.');
        await wrapper.evaluate(el => { el.scrollLeft = 0; el.focus({ preventScroll: true }); });
        await page.keyboard.press('ArrowRight');
        await page.keyboard.press('ArrowRight');
        await page.evaluate(() => new Promise(resolve => setTimeout(resolve, 150)));
        const moved = await wrapper.evaluate(el => ({ left: el.scrollLeft, pageLeft: document.documentElement.scrollLeft || document.body.scrollLeft }));
        if (moved.left <= 0 || moved.pageLeft !== 0) throw new Error(`El teclado no desplaza solo la tabla: ${JSON.stringify(moved)}`);
        await wrapper.evaluate(el => { el.scrollLeft = 0; el.blur(); });
    }
}

async function stressText(page) {
    await page.evaluate(() => {
        const longName = 'NombreDelProfesionalYCliente_' + 'Apellido'.repeat(13);
        document.querySelectorAll('.agenda-detalle h2, .ui-person-card p span, .ui-profile-card > h2, .ui-grid > .ui-card > h2, .growth-list li > span, .nombre-servicio').forEach(el => el.textContent = longName);
        document.querySelectorAll('.ui-profile-card > p:not(.ui-profile-help), .ui-grid > .ui-card > p:first-of-type').forEach(el => {
            el.textContent = 'contacto.' + 'nombre'.repeat(9) + '@' + 'negocio'.repeat(11) + '.example.com';
        });
        document.querySelectorAll('.ui-stat strong, .kpi-valor, .growth-number').forEach(el => {
            el.textContent = '$ 99.999.999,99';
        });
        document.querySelectorAll('#pago-referencia, .ops-table td small').forEach(el => el.textContent = 'MAN_' + 'abcdef0123456789'.repeat(4));
        document.querySelectorAll('select').forEach(el => {
            if (el.options.length) el.options[el.selectedIndex >= 0 ? el.selectedIndex : 0].textContent = longName;
        });
        document.querySelectorAll('td').forEach(el => {
            if (!el.querySelector('form, button, a, input, select, progress') && /[a-záéíóú]/i.test(el.textContent) && !/^\s*[0-9#$]+/.test(el.textContent)) {
                el.appendChild(document.createTextNode(' Referencia_' + 'texto'.repeat(14)));
            }
        });
        document.querySelectorAll('details').forEach(el => el.open = true);
    });
}

async function verifyDialogs(page, pageName) {
    const dialogs = await page.$$eval('dialog[id]', elements => elements.map(el => el.id));
    for (const id of dialogs) {
        await page.setViewport({ width: 320, height: 480 });
        const trigger = await page.$(`[data-modal-open="${id}"]`) || (id === 'foto-modal' ? await page.$('#abrir-foto') : null);
        if (!trigger) throw new Error(`No existe control para abrir el diálogo ${id}.`);
        await trigger.click();
        await page.waitForFunction(dialogId => document.getElementById(dialogId)?.open, {}, id);
        await geometry(page, `${pageName}/dialog-${id}`);
        const box = await page.$eval(`#${id}`, el => {
            const r = el.getBoundingClientRect();
            const heading = document.getElementById(el.getAttribute('aria-labelledby'));
            return { top: r.top, bottom: r.bottom, labelled: !!heading?.textContent.trim() || !!el.getAttribute('aria-label'), height: innerHeight };
        });
        if (box.top < -1 || box.bottom > box.height + 1 || !box.labelled) throw new Error(`Diálogo fuera de pantalla o sin nombre: ${id} ${JSON.stringify(box)}`);
        const submit = await page.$(`#${id} [type="submit"], #${id} .formulario > .boton:last-of-type`);
        if (submit) {
            await submit.evaluate(el => el.focus());
            await settle(page);
            const visible = await submit.evaluate(el => {
                const rect = el.getBoundingClientRect();
                const dialog = el.closest('dialog').getBoundingClientRect();
                return rect.top >= dialog.top - 1 && rect.bottom <= dialog.bottom + 1;
            });
            if (!visible) throw new Error(`La última acción del diálogo ${id} no es alcanzable por foco/scroll.`);
        }
        await page.keyboard.press('Escape');
        if (await page.$eval(`#${id}`, el => el.open)) throw new Error(`Escape no cierra el diálogo ${id}.`);
    }
}

async function inspect(page, route) {
    const [name, url, required, allowedStatus = [200]] = route;
    console.log(`[INICIO] ${name}: ${url}`);
    try {
        await page.setViewport({ width: 1440, height: 900 });
        const response = await page.goto(new URL(url, baseUrl).href, { waitUntil: 'networkidle2' });
        if (!allowedStatus.includes(response.status())) throw new Error(`HTTP inesperado ${response.status()} en ${url}`);
        if (!(await page.$(required))) throw new Error(`Falta ${required}; URL final ${page.url()} (revisa fixtures y permisos).`);
        await page.evaluate(() => document.querySelectorAll('details').forEach(el => el.open = true));
        for (const width of widths) {
            await page.setViewport({ width, height: 900 });
            await geometry(page, name);
            if (width === 320) await verifyTableKeyboard(page);
            if (width === 320 || width === 1440) {
                await page.evaluate(() => window.scrollTo({ top: 0, left: 0, behavior: 'instant' }));
                await settle(page);
                await page.screenshot({ path: path.join(outputDir, `${name}-${width}.png`), fullPage: false });
            }
        }
        await stressText(page);
        for (const width of widths) {
            await page.setViewport({ width, height: 900 });
            await geometry(page, `${name}/long-text`);
        }
        await page.setViewport({ width: 800, height: 480 });
        await geometry(page, `${name}/landscape`);
        for (const width of [390, 1440]) {
            await page.setViewport({ width, height: 900 });
            await page.evaluate(() => document.documentElement.style.fontSize = '125%');
            await geometry(page, `${name}/text-200-percent`);
        }
        await page.evaluate(() => document.documentElement.style.fontSize = '');
        if (await page.$('#formulario-cita')) {
            await page.setViewport({ width: 390, height: 900 });
            const service = await page.$('#servicios .servicio');
            if (!service) throw new Error('La vista de reserva requiere un servicio de fixtures para verificar todos los pasos.');
            await service.click();
            for (const step of [2, 3]) {
                await page.click(`.tabs button[data-paso="${step}"]`);
                for (const width of widths) {
                    await page.setViewport({ width, height: 900 });
                    await geometry(page, `${name}/booking-step-${step}`);
                }
            }
        }
        if (name === 'horarios-profesional') {
            await page.setViewport({ width: 320, height: 900 });
            await page.click('.btn-agregar-franja');
            await geometry(page, `${name}/added-shift`);
        }
        await verifyDialogs(page, name);
        console.log(`[OK] ${name}: 6 anchos, textos largos, paisaje, tamaño de texto 200% y diálogos.`);
    } catch (error) {
        failures.push({ name, url, message: error.message });
        await page.screenshot({ path: path.join(outputDir, `${name}-failure.png`), fullPage: false }).catch(() => {});
        console.error(`[FALLO] ${name}: ${error.message}`);
    }
}

async function login(page, email, destination) {
    // El destino laboral implica el portal del equipo; /cita es el portal de clientes.
    await page.goto(new URL(destination === '/cita' ? '/cliente/login' : '/equipo/login', baseUrl).href, { waitUntil: 'networkidle2' });
    await page.type('#email', email);
    await page.type('#password', password);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle2' }),
        page.click('input[type="submit"], button[type="submit"]')
    ]);
    if (new URL(page.url()).pathname !== destination) throw new Error(`No se pudo iniciar sesión ${email}. Revisa las credenciales de fixtures.`);
}

(async () => {
    const browser = await puppeteer.launch({ executablePath: chrome, headless: true, protocolTimeout: 30000, args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'] });
    try {
        const publicContext = await browser.createBrowserContext();
        const publicPage = await publicContext.newPage();
        publicPage.on('pageerror', error => scriptErrors.push(error.message));
        for (const route of [
            ['login', '/', '#email'],
            ['crear-cuenta', '/cliente/registro', '#nombre'],
            ['login-cliente', '/cliente/login', '#email'],
            ['login-equipo', '/equipo/login', '#email'],
            ['aceptar-invitacion-invalida', '/equipo/aceptar?token=invalido', 'main', [404]],
            ['olvide-password', '/olvide', '#email'],
            ['reenviar-confirmacion', '/reenviar-confirmacion', '#email'],
            ['mensaje-cuenta', '/mensaje', 'main'],
            ['confirmacion-invalida', '/confirmar-cuenta?token=invalido', 'main', [410]],
            ['recuperacion-invalida', '/recuperar?token=invalido', 'main', [410]],
            ['pagina-404', '/pagina-responsive-inexistente', 'main', [404]]
        ]) await inspect(publicPage, route);
        await publicContext.close();

        const adminContext = await browser.createBrowserContext();
        const adminPage = await adminContext.newPage();
        adminPage.on('pageerror', error => scriptErrors.push(error.message));
        await login(adminPage, process.env.TEST_ADMIN_EMAIL || 'admin@appsalon.com', '/admin');
        for (const route of [
            ['admin', '/admin', '.ui-dashboard-stats'],
            ['servicios', '/servicios', '#catalogo-servicios'],
            ['equipo-colaboradores', '/admin/equipo', '#equipo-invitar-titulo'],
            ['clientes-crm', '/admin/clientes', 'main'],
            ['clientes-indicadores', '/admin/clientes/indicadores', '#crm-ind-titulo'],
            ['recursos-humanos', '/admin/recursos-humanos', '#rh-directorio'],
            ['crear-servicio', '/servicios/crear', '#nombre'],
            ['actualizar-servicio', `/servicios/actualizar?id=${serviceId}`, '#nombre'],
            ['profesionales', '/profesionales', '#equipo-profesionales'],
            ['crear-profesional', '/profesionales/crear', '#nombre'],
            ['actualizar-profesional', `/profesionales/actualizar?id=${professionalId}`, '#nombre'],
            ['horarios-profesional', `/profesionales/horarios?id=${professionalId}`, '#form-horarios-semanales'],
            ['agenda-admin', '/agenda', '.agenda-integral'],
            ['reportes-admin', '/admin/reportes', '#form-filtros-reportes'],
            ['finanzas', '/admin/finanzas', '.ops-table-wrap'],
            ['inventario', '/admin/inventario', '.ops-table-wrap'],
            ['crecimiento', '/admin/crecimiento', '.growth-card'],
            ['equipo-permisos', '/admin/equipo', '.ui-permissions-table'],
            ['ajustes', '/admin/ajustes', '#negocio'],
            ['perfil-admin', '/perfil', '.ui-profile-card']
        ]) await inspect(adminPage, route);
        await adminContext.close();

        const clientContext = await browser.createBrowserContext();
        const clientPage = await clientContext.newPage();
        clientPage.on('pageerror', error => scriptErrors.push(error.message));
        await login(clientPage, process.env.TEST_CLIENT_EMAIL || 'carlos@correo.com', '/cita');
        for (const route of [
            ['cita-cliente', '/cita', '#servicios'],
            ['visitas-cliente', '/mis-citas', '.agenda-integral'],
            ['bienestar-cliente', '/mi-bienestar', '.growth-card'],
            ['perfil-cliente', '/perfil', '.ui-profile-card'],
            ['pago-sin-referencia', '/pago/resultado', 'main', [422]],
            ['pago-no-encontrado', '/pago/resultado?referencia=invalida-responsive', 'main', [404]]
        ]) await inspect(clientPage, route);
        if (process.env.TEST_PAYMENT_REFERENCE) await inspect(clientPage, ['pago-cliente', `/pago/resultado?referencia=${encodeURIComponent(process.env.TEST_PAYMENT_REFERENCE)}`, '#pago-estado-real']);
        await clientContext.close();
    } finally {
        await browser.close();
        const report = { checkedAt: new Date().toISOString(), baseUrl, widths, results, failures, scriptErrors };
        fs.writeFileSync(path.join(outputDir, 'responsive-report.json'), JSON.stringify(report, null, 2));
        console.log(`Verificaciones: ${results.length}. Fallos de layout: ${failures.length}. Errores JS: ${scriptErrors.length}.`);
        if (failures.length || scriptErrors.length) process.exitCode = 1;
    }
})().catch(error => { console.error(error); process.exitCode = 1; });

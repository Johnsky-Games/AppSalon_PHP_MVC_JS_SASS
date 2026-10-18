'use strict';
/**
 * E2E de altas sin duplicados en un navegador real (Chromium + Apache/PHP + MySQL aislado).
 *
 * Variables: APP_URL (obligatoria), CHROME_BIN, TEST_DB_NAME (debe terminar en _test),
 * TEST_DB_CONTAINER (docker exec) o MYSQL_HOST/MYSQL_USER/MYSQL_PASS para el cliente mysql local,
 * E2E_OWNER_EMAIL / E2E_OWNER_PASSWORD (propietario aprovisionado por el instalador de pruebas).
 *
 * Cada flujo se prueba con: dos clics rápidos, Enter repetido y dos peticiones HTTP simultáneas
 * con la misma clave que se saltan el bloqueo del botón. Después se cuentan filas directamente en MySQL.
 */
const { execFileSync } = require('child_process');
const puppeteer = require('puppeteer-core');

const baseUrl = process.env.APP_URL || 'http://127.0.0.1:8085';
const dbName = process.env.TEST_DB_NAME || '';
if (!dbName.endsWith('_test')) throw new Error('Se exige una base aislada cuyo nombre termine en _test.');

function query(sql) {
    const args = ['-N', '--default-character-set=utf8mb4', '-e', sql, dbName];
    if (process.env.TEST_DB_CONTAINER) {
        return execFileSync('docker', ['exec', process.env.TEST_DB_CONTAINER, 'mysql', '-uroot', '-proot', ...args], { encoding: 'utf8' }).trim();
    }
    const host = process.env.MYSQL_HOST || '127.0.0.1';
    return execFileSync('mysql', ['--no-defaults', '-h' + host, '-u' + (process.env.MYSQL_USER || 'root'), '-p' + (process.env.MYSQL_PASS || 'root'), ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
}
const contar = sql => Number(query(sql));
// El límite de intentos por IP es otro control; cada ejecución parte de un presupuesto limpio en la base aislada.
query('DELETE FROM intentos_login');
const resultados = [];
function comprobar(nombre, condicion, detalle = '') {
    resultados.push({ nombre, ok: !!condicion, detalle });
    console.log(`${condicion ? 'OK  ' : 'FALLO'} ${nombre}${detalle ? ' — ' + detalle : ''}`);
    if (!condicion) process.exitCode = 1;
}

async function nuevaPagina(browser) {
    const context = await browser.createBrowserContext();
    const page = await context.newPage();
    page.consoleErrors = [];
    page.on('pageerror', e => page.consoleErrors.push(e.message));
    page.setDefaultTimeout(15000);
    return { context, page };
}

async function rellenar(page, valores) {
    for (const [selector, valor] of Object.entries(valores)) {
        await page.$eval(selector, el => { el.value = ''; });
        await page.type(selector, valor);
    }
}

/** Dos clics casi simultáneos sobre el botón (sin esperar a que cargue nada). */
async function dobleClic(page, selector) {
    await page.$eval(selector, boton => { boton.click(); boton.click(); });
}

async function esperarDestino(page, ruta) {
    await page.waitForFunction(r => location.pathname === r, {}, ruta);
}

async function registroCliente(browser, etiqueta) {
    const { context, page } = await nuevaPagina(browser);
    try {
        const email = `e2e-${etiqueta}-${Date.now()}@duplicados.test`;
        await page.goto(`${baseUrl}/crear-cuenta`, { waitUntil: 'networkidle0' });
        await rellenar(page, { '#nombre': 'Duplicado', '#apellido': etiqueta, '#telefono': '0991234567', '#email': email, '#password': 'ClaveSegura123' });
        return { page, context, email };
    } catch (e) { await context.close(); throw e; }
}

(async () => {
    const browser = await puppeteer.launch({
        executablePath: process.env.CHROME_BIN || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
        headless: 'new',
        args: ['--no-sandbox', '--disable-gpu'],
    });
    try {
        // ---- 1. Registro de cliente: dos clics rápidos -------------------------------------------
        {
            const { page, context, email } = await registroCliente(browser, 'doble-clic');
            await dobleClic(page, 'form[data-una-vez] input[type="submit"]');
            await esperarDestino(page, '/mensaje');
            comprobar('Cliente: doble clic → un solo usuario', contar(`SELECT COUNT(*) FROM usuarios WHERE email='${email}'`) === 1);
            comprobar('Cliente: doble clic → una sola membresía', contar(`SELECT COUNT(*) FROM clientes c JOIN usuarios u ON u.id=c.usuario_id WHERE u.email='${email}'`) === 1);
            comprobar('Cliente: sin errores de consola', page.consoleErrors.length === 0, page.consoleErrors.join('|'));
            await context.close();
        }

        // ---- 2. Registro de cliente: Enter repetido -----------------------------------------------
        {
            const { page, context, email } = await registroCliente(browser, 'enter');
            await page.focus('#password');
            await Promise.all([page.keyboard.press('Enter'), page.keyboard.press('Enter'), page.keyboard.press('Enter')]);
            await esperarDestino(page, '/mensaje');
            comprobar('Cliente: Enter repetido → un solo usuario', contar(`SELECT COUNT(*) FROM usuarios WHERE email='${email}'`) === 1);
            await context.close();
        }

        // ---- 3. Registro: dos peticiones HTTP simultáneas con la misma clave (sin pasar por el botón) -
        {
            const { page, context, email } = await registroCliente(browser, 'http-doble');
            const estados = await page.evaluate(async () => {
                const form = document.querySelector('form[data-una-vez]');
                const datos = () => new URLSearchParams(new FormData(form));
                const enviar = () => fetch(form.action, { method: 'POST', body: datos(), redirect: 'manual', credentials: 'same-origin' }).then(r => r.type + ':' + r.status);
                return Promise.all([enviar(), enviar(), enviar()]);
            });
            comprobar('Cliente: 3 POST simultáneos misma clave → una identidad', contar(`SELECT COUNT(*) FROM usuarios WHERE email='${email}'`) === 1, estados.join(','));
            comprobar('Cliente: 3 POST simultáneos → una operación de idempotencia', contar(`SELECT COUNT(*) FROM operaciones_idempotentes WHERE scope='cliente.registro' AND recurso_id=(SELECT id FROM usuarios WHERE email='${email}')`) === 1);
            await context.close();
        }

        // ---- 4. Botón: estado accesible, una sola entrega y liberación (sin enviar al servidor) -----------------
        {
            const { page, context } = await registroCliente(browser, 'estado');
            await page.evaluate(() => {
                window.__envios = 0;
                // Escucha en burbuja del documento: solo recibe los envíos que el guardián dejó pasar.
                document.addEventListener('submit', e => { window.__envios++; e.preventDefault(); });
            });
            const boton = 'form[data-una-vez] input[type="submit"]';
            await page.$eval(boton, b => b.click());
            await page.$eval(boton, b => b.click());
            await page.focus('#password');
            await Promise.all([page.keyboard.press('Enter'), page.keyboard.press('Enter')]);
            const ocupado = await page.$eval('form[data-una-vez]', f => ({
                busy: f.getAttribute('aria-busy'),
                estado: f.querySelector('.ui-form-status').textContent,
                boton: f.querySelector('input[type="submit"]').getAttribute('aria-disabled') + '|' + f.querySelector('input[type="submit"]').value,
                envios: window.__envios,
            }));
            comprobar('UI: aria-busy, aria-disabled y mensaje de estado durante el envío', ocupado.busy === 'true' && /Guardando/.test(ocupado.estado) && ocupado.boton.startsWith('true|Guardando'), JSON.stringify(ocupado));
            comprobar('UI: clic, clic, Enter y Enter → un solo envío', ocupado.envios === 1, `envios=${ocupado.envios}`);
            // Volver desde la caché del navegador libera el formulario sin recargar.
            await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
            const liberado = await page.$eval('form[data-una-vez]', f => ({ busy: f.getAttribute('aria-busy'), boton: f.querySelector('input[type="submit"]').value, desactivado: f.querySelector('input[type="submit"]').getAttribute('aria-disabled') }));
            comprobar('UI: se libera al restaurar la página y recupera su etiqueta', liberado.busy === null && liberado.boton === 'Crear Cuenta' && liberado.desactivado === null, JSON.stringify(liberado));
            await page.$eval(boton, b => b.click());
            comprobar('UI: tras liberarse admite un nuevo envío deliberado', (await page.evaluate(() => window.__envios)) === 2);
            await context.close();
        }

        // ---- 5. Profesional: doble clic con propietario ------------------------------------------------
        const ownerEmail = process.env.E2E_OWNER_EMAIL;
        const ownerPassword = process.env.E2E_OWNER_PASSWORD;
        if (ownerEmail && ownerPassword) {
            const { page, context } = await nuevaPagina(browser);
            await page.goto(`${baseUrl}/equipo/login`, { waitUntil: 'networkidle0' });
            await page.type('input[name="email"]', ownerEmail);
            await page.type('input[name="password"]', ownerPassword);
            await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('input[type="submit"]')]);
            const nombre = `E2E Profesional ${Date.now()}`;
            await page.goto(`${baseUrl}/profesionales/crear`, { waitUntil: 'networkidle0' });
            await rellenar(page, { '#nombre': 'Invitada', '#apellido': 'Profesional', '#nombre-agenda': nombre, '#email': `prof-${Date.now()}@duplicados.test` });
            await page.$eval('input[name="servicios[]"]', el => { el.checked = true; });
            await dobleClic(page, 'form[data-una-vez] input[type="submit"]');
            await esperarDestino(page, '/profesionales');
            comprobar('Profesional: doble clic → una ficha vinculada a RH', contar(`SELECT COUNT(*) FROM profesionales p JOIN colaboradores c ON c.id=p.colaborador_id WHERE p.nombre='${nombre}' AND c.estado='invitado' AND p.activo=0`) === 1);

            const nombre2 = `E2E Profesional HTTP ${Date.now()}`;
            await page.goto(`${baseUrl}/profesionales/crear`, { waitUntil: 'networkidle0' });
            await rellenar(page, { '#nombre': 'Invitada', '#apellido': 'Profesional', '#nombre-agenda': nombre2, '#email': `prof-http-${Date.now()}@duplicados.test` });
            await page.$eval('input[name="servicios[]"]', el => { el.checked = true; });
            await page.evaluate(async () => {
                const form = document.querySelector('form[data-una-vez]');
                const enviar = () => fetch(form.action, { method: 'POST', body: new URLSearchParams(new FormData(form)), redirect: 'manual', credentials: 'same-origin' });
                await Promise.all([enviar(), enviar(), enviar(), enviar()]);
            });
            comprobar('Profesional: 4 POST simultáneos misma clave → un solo registro', contar(`SELECT COUNT(*) FROM profesionales WHERE nombre='${nombre2}'`) === 1);
            comprobar('Profesional: sin errores de consola', page.consoleErrors.length === 0, page.consoleErrors.join('|'));

            // ---- 6. Colaborador: invitación con doble clic y peticiones simultáneas ----------------------------
            const correo = `e2e-colab-${Date.now()}@duplicados.test`;
            await page.goto(`${baseUrl}/admin/equipo`, { waitUntil: 'networkidle0' });
            await rellenar(page, { '#eq-nombre': 'Invitada', '#eq-apellido': 'Doble', '#eq-email': correo, '#eq-puesto': 'Mostrador' });
            await page.select('#eq-rol', 'recepcion');
            await dobleClic(page, 'form[action="/admin/equipo/crear"] button[type="submit"]');
            await esperarDestino(page, '/admin/equipo');
            await new Promise(r => setTimeout(r, 500));
            comprobar('Colaborador: doble clic → una ficha', contar(`SELECT COUNT(*) FROM colaboradores WHERE email_norm='${correo}'`) === 1);
            comprobar('Colaborador: doble clic → una invitación pendiente', contar(`SELECT COUNT(*) FROM colaborador_invitaciones i JOIN colaboradores c ON c.id=i.colaborador_id WHERE c.email_norm='${correo}' AND i.estado='pendiente'`) === 1);
            comprobar('Colaborador: doble clic → un solo evento de correo', contar(`SELECT COUNT(*) FROM correos_salida WHERE destinatario_email='${correo}'`) === 1);
            comprobar('Colaborador: sin cuenta ni acceso hasta aceptar', contar(`SELECT COUNT(*) FROM usuarios WHERE email='${correo}'`) === 0);

            const correo2 = `e2e-colab-http-${Date.now()}@duplicados.test`;
            await page.goto(`${baseUrl}/admin/equipo`, { waitUntil: 'networkidle0' });
            await rellenar(page, { '#eq-nombre': 'Invitada', '#eq-apellido': 'Simultanea', '#eq-email': correo2 });
            await page.evaluate(async () => {
                const form = document.querySelector('form[action="/admin/equipo/crear"]');
                const enviar = () => fetch(form.action, { method: 'POST', body: new URLSearchParams(new FormData(form)), redirect: 'manual', credentials: 'same-origin' });
                await Promise.all([enviar(), enviar(), enviar(), enviar()]);
            });
            comprobar('Colaborador: 4 POST simultáneos misma clave → una ficha, una invitación, un correo',
                contar(`SELECT COUNT(*) FROM colaboradores WHERE email_norm='${correo2}'`) === 1
                && contar(`SELECT COUNT(*) FROM colaborador_invitaciones i JOIN colaboradores c ON c.id=i.colaborador_id WHERE c.email_norm='${correo2}'`) === 1
                && contar(`SELECT COUNT(*) FROM correos_salida WHERE destinatario_email='${correo2}'`) === 1);
            await context.close();
        } else {
            console.log('OMITIDO flujo de profesional: faltan E2E_OWNER_EMAIL / E2E_OWNER_PASSWORD');
        }
    } finally {
        await browser.close();
    }
    const fallos = resultados.filter(r => !r.ok).length;
    console.log(`\nResumen: ${resultados.length - fallos}/${resultados.length} comprobaciones correctas`);
    process.exit(fallos ? 1 : 0);
})().catch(error => { console.error(error); process.exit(1); });

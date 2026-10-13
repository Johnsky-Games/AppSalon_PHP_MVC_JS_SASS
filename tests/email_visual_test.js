/**
 * Verifica los seis correos transaccionales y un caso de estrés sin enviar mensajes reales.
 * Primero: php tests/fixtures/email_previews.php /tmp/email-previews
 * Después: CHROME_BIN=/ruta/chrome node tests/email_visual_test.js /tmp/email-previews
 */
const assert = require('assert/strict');
const fs = require('fs');
const path = require('path');
const { pathToFileURL } = require('url');

const puppeteer = process.env.PUPPETEER_CORE_PATH ? require(process.env.PUPPETEER_CORE_PATH) : require('puppeteer-core');
const directory = path.resolve(process.argv[2] || process.env.TEST_EMAIL_PREVIEW_DIR || path.join(process.env.E2E_ARTIFACT_DIR || path.join(__dirname, '../storage/e2e'), 'email-previews'));
const chrome = process.env.CHROME_BIN || ['/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser', '/opt/google/chrome/chrome'].find(file => fs.existsSync(file));
const widths = [320, 375, 768, 1280];

async function main() {
    assert.ok(chrome, 'Configura CHROME_BIN o instala Chrome/Chromium para la validación visual.');
    const manifest = JSON.parse(fs.readFileSync(path.join(directory, 'manifest.json'), 'utf8'));
    assert.deepEqual(manifest.map(item => item.tipo).sort(), ['confirmacion', 'recuperacion', 'invitacion', 'cita-confirmacion', 'cita-cancelacion', 'cita-recordatorio', 'stress'].sort());

    const report = [];
    let browser;
    try {
        browser = await puppeteer.launch({ executablePath: chrome, headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage'] });
        const page = await browser.newPage();
        // Las plantillas no deben requerir recursos externos para leerse.
        const externalRequests = [];
        page.on('request', request => {
            if (/^https?:/i.test(request.url())) externalRequests.push(request.url());
        });

        for (const width of widths) {
            await page.setViewport({ width, height: 900, deviceScaleFactor: 1 });
            for (const preview of manifest) {
                const label = `${preview.tipo} a ${width}px`;
                const plain = fs.readFileSync(path.join(directory, preview.texto), 'utf8');
                await page.goto(pathToFileURL(path.join(directory, preview.html)).href, { waitUntil: 'load' });
                const metrics = await page.evaluate(() => {
                    const cta = document.querySelector('.email-button a');
                    const box = cta.getBoundingClientRect();
                    return {
                        viewport: innerWidth,
                        scroll: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth),
                        lang: document.documentElement.lang,
                        title: document.querySelector('h1')?.textContent,
                        content: document.body.innerText,
                        tableCount: document.querySelectorAll('table').length,
                        nonPresentationTables: document.querySelectorAll('table:not([role="presentation"])').length,
                        scripts: document.querySelectorAll('script,iframe').length,
                        preheaderHidden: getComputedStyle(document.querySelector('[aria-hidden="true"]')).display === 'none',
                        cta: { label: cta.textContent, href: cta.getAttribute('href'), width: box.width, height: box.height, left: box.left, right: box.right },
                        unsafeLinks: [...document.querySelectorAll('a[href]')].filter(link => !/^(https?:|mailto:)/i.test(link.getAttribute('href'))).map(link => link.getAttribute('href')),
                    };
                });
                const { content, ...summary } = metrics;
                report.push({ tipo: preview.tipo, width, ...summary });
                assert.ok(metrics.scroll <= width, `${label}: desbordamiento horizontal (${metrics.scroll}px).`);
                assert.ok(metrics.cta.left >= 0 && metrics.cta.right <= width, `${label}: botón fuera de la pantalla.`);
                assert.ok(metrics.cta.height >= 44, `${label}: botón sin altura táctil suficiente.`);
                assert.equal(metrics.cta.label, preview.boton, `${label}: acción incorrecta.`);
                assert.equal(metrics.cta.href, preview.enlace, `${label}: URL/token alterado.`);
                assert.equal(metrics.lang, 'es', `${label}: falta idioma accesible.`);
                assert.ok(metrics.title, `${label}: falta título visible.`);
                assert.ok(metrics.tableCount > 0, `${label}: falta layout compatible con email.`);
                assert.equal(metrics.nonPresentationTables, 0, `${label}: layout anuncia tablas de datos a tecnologías de asistencia.`);
                assert.equal(metrics.scripts, 0, `${label}: código activo inesperado.`);
                assert.ok(metrics.preheaderHidden, `${label}: preheader interfiere con el mensaje visible.`);
                assert.deepEqual(metrics.unsafeLinks, [], `${label}: URL con esquema inseguro.`);
                assert.ok(plain.includes(preview.enlace), `${label}: falta enlace alternativo en texto plano.`);
                assert.ok(plain.includes(preview.boton), `${label}: falta acción en texto plano.`);
                assert.ok(!/<(?:html|table|style)\b|font-size:|mso-/i.test(plain), `${label}: texto plano contiene markup/CSS.`);
                for (const datum of preview.datos_esperados) {
                    assert.ok(content.includes(datum), `${label}: falta información visible: ${datum}.`);
                    assert.ok(plain.includes(datum), `${label}: falta información equivalente en texto plano: ${datum}.`);
                }
                if (width === 375 || width === 1280) {
                    await page.screenshot({ path: path.join(directory, `${preview.tipo}-${width}.png`), fullPage: true });
                }
            }
        }
        assert.deepEqual(externalRequests, [], 'Los correos necesitan recursos externos para leerse.');
        console.log(JSON.stringify({ success: true, renders: report.length, widths, horizontalOverflow: 0, unsafeLinks: 0, minimumButtonHeight: Math.min(...report.map(item => item.cta.height)) }));
    } finally {
        fs.writeFileSync(path.join(directory, 'email_visual_report.json'), JSON.stringify(report, null, 2));
        if (browser) await browser.close();
    }
}

main().catch(error => { console.error(error.message); process.exitCode = 1; });

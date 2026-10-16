/** Renderiza las páginas reales de error sin BD ni operaciones de negocio. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const { execFileSync } = require('node:child_process');
const puppeteer = require('puppeteer-core');
const root = path.resolve(__dirname, '..');
const statuses = [400, 401, 403, 404, 405, 409, 410, 419, 422, 429, 500, 503];
const widths = [320, 360, 390, 768, 1024, 1440];
const output = path.resolve(process.env.TEST_ERROR_OUTPUT_DIR || 'storage/e2e/errors');
fs.mkdirSync(output, { recursive: true });
const fixture = `<?php
require getenv('ERROR_ROOT').'/includes/funciones.php';
require getenv('ERROR_ROOT').'/classes/ErrorResponse.php';
$_SERVER['REQUEST_URI']='/no-existe';
$_SERVER['REQUEST_METHOD']='GET';
$_SERVER['HTTP_ACCEPT']='text/html';
$_SESSION=['login'=>true,'rol'=>'cliente','ui_empresa'=>[
 'nombre_comercial'=>'Salón de ejemplo para bienestar y cuidado personal',
 'contacto'=>'recepcion@salon.example']];
\\Classes\\ErrorResponse::render((int)getenv('ERROR_STATUS'));
`;
const pages = new Map(statuses.map(status => [status, execFileSync(process.env.PHP_BIN || 'php', ['-n'], {
    input: fixture, encoding: 'utf8', env: { ...process.env, ERROR_ROOT: root, ERROR_STATUS: String(status) }
})]));
const server = http.createServer((request, response) => {
    const url = new URL(request.url, 'http://localhost');
    if (url.pathname === '/build/css/app.css') {
        response.setHeader('Content-Type', 'text/css');
        response.end(fs.readFileSync(path.join(root, 'public/build/css/app.css')));
    } else if (url.pathname === '/build/img/avatar-professional.svg') {
        response.setHeader('Content-Type', 'image/svg+xml');
        response.end(fs.readFileSync(path.join(root, 'public/build/img/avatar-professional.svg')));
    } else {
        response.setHeader('Content-Type', 'text/html; charset=utf-8');
        response.end(pages.get(Number(url.searchParams.get('status'))) || pages.get(404));
    }
});
(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const base = `http://127.0.0.1:${server.address().port}`;
    const report = [];
    let browser;
    try {
        const executablePath = process.env.CHROME_BIN || ['/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser'].find(file => fs.existsSync(file));
        browser = await puppeteer.launch({ executablePath, headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage'] });
        const page = await browser.newPage();
        for (const status of statuses) for (const width of widths) {
            await page.setViewport({ width, height: 800 });
            await page.goto(`${base}/?status=${status}`, { waitUntil: 'load' });
            const geometry = await page.evaluate(() => ({
                width: innerWidth,
                scroll: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth),
                title: document.querySelector('h1')?.textContent.trim(),
                link: document.querySelector('.ui-state-actions a')?.getAttribute('href'),
                buttonHeight: document.querySelector('.ui-state-actions a')?.getBoundingClientRect().height,
                body: document.body.innerText,
                language: document.documentElement.lang,
                focusTarget: !!document.querySelector('#contenido-principal[tabindex="-1"]'),
                robots: document.querySelector('meta[name="robots"]')?.content
            }));
            assert.ok(geometry.scroll <= width + 1, `${status} a ${width}px: desborde ${geometry.scroll}`);
            assert.ok(geometry.title, 'Cada estado ofrece una explicación visible');
            assert.ok(geometry.buttonHeight >= 44, 'La acción principal debe poder tocarse');
            assert.equal(geometry.link, status === 401 ? '/' : '/cita');
            assert.equal(geometry.language, 'es');
            assert.ok(geometry.focusTarget);
            assert.match(geometry.robots, /noindex/);
            assert.doesNotMatch(geometry.body, /(?:Fatal error|Stack trace|SQLSTATE|DB_PASS|\/workspace\/)/i);
            const { body, ...metrics } = geometry;
            report.push({ status, ...metrics });
            if (width === 320 || width === 1440) await page.screenshot({ path: path.join(output, `${status}-${width}.png`), fullPage: true });
        }
        console.log(`ERROR_VISUAL: ${report.length} renders, ${statuses.length} estados y ${widths.length} anchos aprobados.`);
    } finally {
        fs.writeFileSync(path.join(output, 'error-visual-report.json'), JSON.stringify(report, null, 2));
        await browser?.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => { console.error(error); process.exitCode = 1; });

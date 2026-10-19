/**
 * Navegación real en Chromium, sin base de datos ni mutaciones externas.
 * PHP_BIN y CHROME_BIN permiten usar los mismos runtimes que la suite E2E.
 * Ejecutar: PHP_BIN=php CHROME_BIN=/ruta/chromium node tests/ui_navigation_test.js
 */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const { execFileSync } = require('node:child_process');
const sass = require('sass');
const puppeteer = require(process.env.PUPPETEER_CORE_PATH || 'puppeteer-core');
const root = path.resolve(__dirname, '..');
const screenshotDir = process.env.UI_SCREENSHOT_DIR;
if (screenshotDir) fs.mkdirSync(screenshotDir, { recursive: true });
const css = sass.compile(path.join(root, 'src/scss/app.scss'), { style: 'expanded', loadPaths: [path.join(root, 'src/scss')] }).css;
const javascript = fs.readFileSync(path.join(root, 'src/js/ui.js'));
const companyName = 'Estudio de bienestar, belleza y cuidado personal de nuestra comunidad';
const ownerRoutes = [
    '/admin', '/agenda', '/caja', '/servicios', '/servicios/crear', '/profesionales',
    '/admin/recursos-humanos', '/admin/equipo', '/gestion/personal', '/pdv', '/gestion/caja', '/gestion/prepagos', '/gestion/paquetes', '/gestion/suscripciones',
    '/gestion/catalogo', '/gestion/compras', '/gestion/obligaciones', '/gestion/aprobaciones',
    '/gestion/comisiones', '/gestion/promociones', '/gestion/seguimiento', '/admin/finanzas', '/admin/inventario',
    '/gestion/recursos', '/gestion/indicadores', '/admin/reportes', '/admin/crecimiento',
    '/admin/clientes', '/admin/clientes/indicadores', '/admin/ajustes', '/gestion/configuracion', '/gestion/integraciones', '/perfil'
];
const fixture = `<?php
namespace Repositories {
    class CrecimientoRepository {
        public function ajustes(): array { return ['nombre_comercial' => getenv('NAV_COMPANY'), 'contacto' => 'contacto@empresa.test']; }
    }
}
namespace {
    require getenv('NAV_ROOT') . '/services/AuthorizationService.php';
    $_SESSION = ['id' => 1, 'admin' => getenv('NAV_ROLE') === 'propietario'];
    $_SERVER['REQUEST_URI'] = getenv('NAV_ROUTE');
    function s($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
    function rol_actual(): string { return getenv('NAV_ROLE'); }
    function portal_sesion(): string { return getenv('NAV_ROLE') === 'cliente' ? 'cliente' : 'equipo'; }
    function inicio_portal(): string { return portal_sesion() === 'cliente' ? '/cita' : (['profesional' => '/agenda', 'finanzas' => '/admin/finanzas'][getenv('NAV_ROLE')] ?? '/admin'); }
    function puede(string $permission): bool { return in_array($permission, \\Services\\AuthorizationService::ROLES[rol_actual()] ?? [], true); }
    require getenv('NAV_ROOT') . '/includes/destinos.php'; // catálogo real de destinos: el menú del equipo sale de navegacion_portal()
    $contenido = '<header class="ui-page-header"><h1 class="nombre-pagina">Tu jornada</h1><p>Revisa la agenda y organiza la atención.</p></header><section class="ui-card"><h2>Próximos pasos</h2><a href="#contenido-principal" id="fixture-main-link">Continuar en el contenido</a></section>';
    require getenv('NAV_ROOT') . '/views/layout.php';
}`;
const server = http.createServer((request, response) => {
    const url = new URL(request.url, 'http://localhost');
    if (url.pathname === '/build/css/app.css') {
        response.writeHead(200, { 'Content-Type': 'text/css' }); response.end(css); return;
    }
    if (url.pathname === '/build/js/ui.js') {
        response.writeHead(200, { 'Content-Type': 'text/javascript' }); response.end(javascript); return;
    }
    if (url.pathname.startsWith('/build/')) { response.writeHead(200); response.end(''); return; }
    try {
        const html = execFileSync(process.env.PHP_BIN || 'php', ['-n'], { input: fixture, encoding: 'utf8', env: {
            ...process.env, NAV_ROOT: root, NAV_ROLE: url.searchParams.get('role') || 'propietario', NAV_ROUTE: url.pathname, NAV_COMPANY: companyName
        } });
        response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }); response.end(html);
    } catch (error) { response.writeHead(500); response.end(error.message); }
});

(async () => {
    let browser;
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const baseUrl = `http://127.0.0.1:${server.address().port}`;
    try {
        const executablePath = process.env.CHROME_BIN || ['/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome'].find(file => fs.existsSync(file));
        browser = await puppeteer.launch({ executablePath, headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage'] });
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        for (const [width, height] of [[320, 568], [390, 844], [768, 600], [1024, 768], [1280, 900], [1280, 240], [1920, 1080]]) {
            await page.setViewport({ width, height });
            await page.goto(`${baseUrl}/admin/ajustes`, { waitUntil: 'load' });
            if (screenshotDir) await page.screenshot({ path: path.join(screenshotDir, `navigation-${width}-${height}.png`), fullPage: true });
            assert.equal(await page.$eval('.ui-brand > span:last-child', node => node.firstChild.textContent), companyName);
            assert.deepEqual(await page.$$eval('.ui-nav-link', links => links.map(link => link.getAttribute('href'))), ownerRoutes, 'El propietario dispone de sus accesos, incluidos Recursos Humanos e indicadores de clientes');
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, `Sin desbordamiento horizontal a ${width}×${height}`);
            if (width <= 1024) {
                assert.equal(await page.$eval('#ui-navigation-panel', node => node.hidden), true, 'Panel móvil inicialmente cerrado');
                await page.focus('[data-nav-toggle]');
                await page.keyboard.press('Enter');
                assert.equal(await page.$eval('[data-nav-toggle]', node => node.getAttribute('aria-expanded')), 'true');
                assert.equal(await page.evaluate(() => document.activeElement.getAttribute('href')), '/admin/ajustes', 'Abrir lleva el foco al enlace activo');
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, 'El menú expandido cabe en la pantalla');
                await page.keyboard.press('Escape');
                assert.equal(await page.$eval('#ui-navigation-panel', node => node.hidden), true);
                assert.equal(await page.evaluate(() => document.activeElement.hasAttribute('data-nav-toggle')), true, 'Escape devuelve foco al botón');
            } else {
                const bounds = await page.evaluate(() => {
                    const sidebar = document.querySelector('.ui-sidebar').getBoundingClientRect();
                    const header = document.querySelector('.ui-sidebar-header').getBoundingClientRect();
                    const scroll = document.querySelector('.ui-sidebar-scroll').getBoundingClientRect();
                    const footer = document.querySelector('.ui-sidebar-footer').getBoundingClientRect();
                    return { height: sidebar.height, headerHeight: header.height, scrollHeight: scroll.height, scrollBottom: scroll.bottom, footerTop: footer.top, footerBottom: footer.bottom };
                });
                assert.ok(Math.abs(bounds.height - height) <= 1, 'Sidebar cubre exactamente la altura disponible');
                assert.ok(bounds.scrollBottom <= bounds.footerTop && bounds.footerBottom <= height, `Enlaces y pie no se superponen a ${width}×${height}: ${JSON.stringify(bounds)}`);
                await page.focus('.ui-nav-link[href="/admin/ajustes"]');
                assert.equal(await page.$eval('.ui-nav-link[href="/admin/ajustes"]', node => {
                    const link = node.getBoundingClientRect(); const panel = node.closest('.ui-sidebar-scroll').getBoundingClientRect();
                    return link.top >= panel.top - 1 && link.bottom <= panel.bottom + 1;
                }), true, 'El último enlace es alcanzable con teclado incluso en pantalla baja');
            }
        }
        for (const [route, active] of [['/servicios/crear', '/servicios/crear'], ['/servicios/crear/', '/servicios/crear'], ['/servicios/actualizar', '/servicios'], ['/profesionales/horarios', '/profesionales'], ['/admin/finanzas', '/admin/finanzas'], ['/admin/recursos-humanos', '/admin/recursos-humanos'], ['/admin/recursos-humanos/ficha', '/admin/recursos-humanos'], ['/admin/clientes/indicadores', '/admin/clientes/indicadores']]) {
            await page.goto(baseUrl + route, { waitUntil: 'load' });
            assert.deepEqual(await page.$$eval('[aria-current="page"]', links => links.map(link => link.getAttribute('href'))), [active], `Una sola sección activa en ${route}`);
        }
        for (const [role, routes, home] of [
            ['cliente', ['/cita', '/mis-citas', '/perfil', '/mi-bienestar'], '/cita'],
            ['profesional', ['/agenda', '/gestion/personal', '/admin/clientes', '/perfil'], '/agenda'],
            ['recepcion', ['/admin', '/agenda', '/gestion/personal', '/pdv', '/gestion/caja', '/gestion/prepagos', '/gestion/paquetes', '/gestion/suscripciones', '/gestion/aprobaciones', '/gestion/seguimiento', '/admin/clientes', '/admin/clientes/indicadores', '/perfil'], '/admin'],
            ['finanzas', ['/caja', '/gestion/personal', '/pdv', '/gestion/caja', '/gestion/prepagos', '/gestion/paquetes', '/gestion/suscripciones', '/gestion/catalogo', '/gestion/compras', '/gestion/obligaciones', '/gestion/aprobaciones', '/gestion/comisiones', '/gestion/seguimiento', '/admin/finanzas', '/admin/inventario', '/gestion/recursos', '/gestion/indicadores', '/admin/reportes', '/admin/clientes/indicadores', '/gestion/integraciones', '/perfil'], '/admin/finanzas']
        ]) {
            await page.goto(`${baseUrl}/perfil?role=${role}`, { waitUntil: 'load' });
            assert.deepEqual(await page.$$eval('.ui-nav-link', links => links.map(link => link.getAttribute('href'))), routes, `El rol ${role} ve sus accesos permitidos`);
            assert.equal(await page.$eval('.ui-brand', node => node.getAttribute('href')), home, 'La marca conduce a una ruta accesible');
        }
        await page.setViewport({ width: 768, height: 600 });
        await page.goto(`${baseUrl}/admin`, { waitUntil: 'load' });
        await page.click('[data-nav-toggle]');
        await page.setViewport({ width: 1280, height: 900 });
        await page.waitForFunction(() => !document.getElementById('ui-navigation-panel').hidden);
        await page.focus('.ui-nav-link[href="/admin/ajustes"]');
        await page.setViewport({ width: 768, height: 600 });
        await page.waitForFunction(() => document.getElementById('ui-navigation-panel').hidden);
        assert.equal(await page.evaluate(() => document.activeElement.hasAttribute('data-nav-toggle')), true, 'Cambio a móvil no pierde el foco');
        await page.click('[data-nav-toggle]');
        await page.click('#fixture-main-link');
        assert.equal(await page.$eval('#ui-navigation-panel', node => node.hidden), true, 'Click en contenido cierra el menú');
        await page.setJavaScriptEnabled(false);
        await page.setViewport({ width: 320, height: 568 });
        await page.goto(`${baseUrl}/admin`, { waitUntil: 'load' });
        assert.equal(await page.$eval('#ui-navigation-panel', node => node.hidden), false, 'Sin JavaScript se conserva la navegación');
        assert.equal(await page.$$eval('.ui-nav-link', links => links.every(link => getComputedStyle(link).display !== 'none')), true);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true);
        assert.deepEqual(errors, [], 'La navegación no genera errores de JavaScript');
        console.log('UI_NAVIGATION: 7 tamaños, rutas activas, 4 roles + portal de clientes, teclado, breakpoints y navegación sin JavaScript aprobados.');
    } finally {
        await browser?.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => { console.error(error); process.exitCode = 1; });

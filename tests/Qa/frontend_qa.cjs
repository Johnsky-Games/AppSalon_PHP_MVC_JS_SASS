// QA independiente de frontend: Chromium real + puppeteer-core. Lee la configuración de QA_CONFIG (JSON) y escribe un JSON de resultados.
// Uso: QA_CONFIG='{"base":"http://127.0.0.1:PORT","owner":{"email":"..","password":".."},"token":"..","colId":1}' node tests/Qa/frontend_qa.cjs
const puppeteer = require('puppeteer-core');
const cfg = JSON.parse(process.env.QA_CONFIG);
const CHROME = process.env.CHROME_BIN || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
if (!require('fs').existsSync(CHROME)) { console.log(JSON.stringify({ error: 'Chromium no encontrado en ' + CHROME + ' (define CHROME_BIN)' })); process.exit(0); }
const ANCHOS = [320, 390, 768, 1440];
const out = { desbordamiento: [], consola: [], guardian: {}, teclado: {}, sinJs: {}, capturas: [] };

(async () => {
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox', '--disable-setuid-sandbox'] });
  try {
    const ctx = browser.defaultBrowserContext();
    const page = await browser.newPage();
    const eventos = [];
    page.on('console', m => { if (m.type() === 'error') eventos.push({ tipo: 'console', texto: m.text(), url: page.url() }); });
    page.on('pageerror', e => eventos.push({ tipo: 'pageerror', texto: String(e.message), url: page.url() }));
    page.on('requestfailed', r => eventos.push({ tipo: 'requestfailed', texto: r.url() + ' ' + (r.failure() || {}).errorText, url: page.url() }));
    page.on('response', r => { if (r.status() >= 400 && !/favicon/.test(r.url()) && r.request().resourceType() !== 'document') eventos.push({ tipo: 'http' + r.status(), texto: r.url(), url: page.url() }); });

    const medir = async (ruta, etiqueta) => {
      for (const w of ANCHOS) {
        await page.setViewport({ width: w, height: 900, deviceScaleFactor: 1 });
        const resp = await page.goto(cfg.base + ruta, { waitUntil: 'networkidle0' });
        const r = await page.evaluate(() => {
          const ancho = document.documentElement.clientWidth;
          const sobresale = [...document.querySelectorAll('body *')].filter(e => {
            const b = e.getBoundingClientRect();
            if (b.width === 0 || b.height === 0) return false;
            // Se ignoran descendientes de contenedores con scroll horizontal propio.
            for (let p = e.parentElement; p && p !== document.body; p = p.parentElement) {
              const s = getComputedStyle(p);
              if (['auto', 'scroll'].includes(s.overflowX)) return false;
            }
            return b.right > ancho + 1 || b.left < -1;
          }).slice(0, 4).map(e => e.tagName.toLowerCase() + (e.className ? '.' + String(e.className).split(' ')[0] : '') + '#' + (e.id || ''));
          return { scrollWidth: document.documentElement.scrollWidth, clientWidth: ancho, sobresale, h1: (document.querySelector('h1') || {}).textContent };
        });
        const malo = r.scrollWidth > r.clientWidth + 1 || r.sobresale.length > 0;
        out.desbordamiento.push({ ruta: etiqueta, ancho: w, status: resp.status(), ...r, malo });
      }
    };

    // Públicas
    await medir('/cliente/login', '/cliente/login');
    await medir('/cliente/registro', '/cliente/registro');
    await medir('/equipo/login', '/equipo/login');
    await medir('/equipo/aceptar?token=' + cfg.token, '/equipo/aceptar');

    // Teclado en /equipo/login
    await page.setViewport({ width: 1024, height: 800 });
    await page.goto(cfg.base + '/equipo/login', { waitUntil: 'networkidle0' });
    const secuencia = [];
    for (let i = 0; i < 12; i++) {
      await page.keyboard.press('Tab');
      secuencia.push(await page.evaluate(() => {
        const a = document.activeElement; if (!a || a === document.body) return null;
        const s = getComputedStyle(a); const b = a.getBoundingClientRect();
        return { tag: a.tagName.toLowerCase(), id: a.id, name: a.getAttribute('name'), texto: (a.value || a.textContent || '').trim().slice(0, 30),
          contorno: s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) > 0, sombra: s.boxShadow !== 'none', visible: b.width > 0 && b.height > 0 };
      }));
    }
    out.teclado.secuencia = secuencia.filter(Boolean);
    const campos = out.teclado.secuencia.filter(s => ['email', 'password'].includes(s.id) || s.name === 'email');
    out.teclado.camposAlcanzables = campos.length >= 2;
    out.teclado.enfoqueVisible = out.teclado.secuencia.filter(s => ['input', 'a', 'button'].includes(s.tag)).every(s => s.contorno || s.sombra);
    // Enter en el campo contraseña envía el formulario (usabilidad con teclado).
    await page.type('#email', cfg.owner.email);
    await page.type('#password', cfg.owner.password);
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.keyboard.press('Enter')]);
    out.teclado.loginConEnter = new URL(page.url()).pathname;

    // Autenticadas (propietario)
    const crearToken = await page.evaluate(() => (document.querySelector('input[name=csrf_token]') || {}).value);
    await medir('/admin/equipo', '/admin/equipo');
    await medir('/admin/equipo/ficha?id=' + cfg.colId, '/admin/equipo/ficha');
    await medir('/admin/clientes', '/admin/clientes');

    // Guardián de envío único en /admin/equipo
    await page.setViewport({ width: 1280, height: 900 });
    await page.goto(cfg.base + '/admin/equipo', { waitUntil: 'networkidle0' });
    const posts = [];
    page.on('request', r => { if (r.method() === 'POST') posts.push(r.url()); });
    await page.type('#eq-nombre', 'Doble');
    await page.type('#eq-apellido', 'Clic');
    await page.type('#eq-email', cfg.emailDobleClic);
    await page.select('#eq-rol', 'recepcion');
    const boton = await page.$('form[action="/admin/equipo/crear"] button[type=submit]');
    // Dos clics reales muy seguidos + Enter inmediato.
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), (async () => {
      await boton.click({ clickCount: 2, delay: 5 });
      await page.keyboard.press('Enter').catch(() => {});
    })()]);
    out.guardian.postsAdminEquipoCrear = posts.filter(u => u.endsWith('/admin/equipo/crear')).length;
    out.guardian.mensaje = (await page.evaluate(() => (document.querySelector('.alerta, [role=alert], .ui-alert') || {}).textContent || '')).trim().slice(0, 200);

    // Estado del botón durante el envío: se observa en el mismo turno del evento (sin navegar) con un listener posterior que cancela el envío.
    await page.goto(cfg.base + '/admin/equipo', { waitUntil: 'networkidle0' });
    await page.type('#eq-nombre', 'Lento'); await page.type('#eq-apellido', 'Red'); await page.type('#eq-email', cfg.emailLento);
    out.guardian.estadoDurante = await page.evaluate(async () => {
      const f = document.querySelector('form[action="/admin/equipo/crear"]'); const b = f.querySelector('button[type=submit]');
      let sub = 0; f.addEventListener('submit', e => { sub++; e.preventDefault(); });
      b.click(); await new Promise(r => setTimeout(r, 50));
      const antes = { ariaBusy: f.getAttribute('aria-busy'), ariaDisabled: b.getAttribute('aria-disabled'), clase: b.className, estado: (f.querySelector('.ui-form-status') || {}).textContent || '', etiqueta: b.textContent };
      b.click(); b.click(); await new Promise(r => setTimeout(r, 50));
      return { ...antes, submitsTrasTresClics: sub };
    });

    // Selector de rol: el bloque de profesional se oculta con JS y se muestra al elegir profesional.
    await page.goto(cfg.base + '/admin/equipo', { waitUntil: 'networkidle0' });
    out.guardian.bloqueProfesionalOcultoConJs = await page.evaluate(() => { const b = document.querySelector('[data-solo-rol]'); const s = getComputedStyle(b); return s.display === 'none' || b.hidden; });
    await page.select('#eq-rol', 'profesional');
    out.guardian.bloqueProfesionalVisibleAlElegir = await page.evaluate(() => { const b = document.querySelector('[data-solo-rol]'); return getComputedStyle(b).display !== 'none' && !b.hidden; });

    // Sin JavaScript: el formulario de invitación debe seguir siendo utilizable.
    const sj = await browser.newPage();
    await sj.setJavaScriptEnabled(false);
    await sj.setViewport({ width: 390, height: 900 });
    await sj.goto(cfg.base + '/admin/equipo', { waitUntil: 'networkidle0' });
    out.sinJs.bloqueProfesionalVisible = await sj.evaluate(() => { const b = document.querySelector('[data-solo-rol]'); return getComputedStyle(b).display !== 'none' && !b.hidden; });
    out.sinJs.campoRolVisible = await sj.evaluate(() => getComputedStyle(document.querySelector('#eq-rol')).display !== 'none');
    await sj.type('#eq-nombre', 'Sin'); await sj.type('#eq-apellido', 'Js'); await sj.type('#eq-email', cfg.emailSinJs);
    await sj.select('#eq-rol', 'recepcion');
    await Promise.all([sj.waitForNavigation({ waitUntil: 'load' }), sj.click('form[action="/admin/equipo/crear"] button[type=submit]')]);
    out.sinJs.urlTrasEnviar = new URL(sj.url()).pathname;
    out.sinJs.mensaje = await sj.evaluate(() => (document.body.innerText.match(/Invitación[^\n]{0,120}/) || [''])[0]);
    // Sin JS la página de aceptación también funciona.
    await sj.goto(cfg.base + '/equipo/aceptar?token=' + cfg.token, { waitUntil: 'load' });
    out.sinJs.aceptarCamposVisibles = await sj.evaluate(() => ['password', 'password_confirmacion', 'acepto'].every(n => { const e = document.querySelector('[name=' + n + ']'); return e && getComputedStyle(e).display !== 'none'; }));
    await sj.close();

    // Captura de pantalla de referencia (320 px) del formulario de invitación.
    out.eventosConsola = eventos;
    await page.setViewport({ width: 320, height: 1200 });
    await page.goto(cfg.base + '/admin/equipo', { waitUntil: 'networkidle0' });
    await page.screenshot({ path: cfg.captura || '/tmp/qa_equipo_320.png', fullPage: false });
    out.capturas.push(cfg.captura || '/tmp/qa_equipo_320.png');
  } catch (e) {
    out.error = String(e && e.stack || e);
  } finally {
    await browser.close();
    process.stdout.write(JSON.stringify(out));
  }
})();

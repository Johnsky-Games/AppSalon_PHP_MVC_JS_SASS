// QA independiente (ronda 5b): regularización de citas de días anteriores en NAVEGADOR real (Chromium + puppeteer-core). QA_CONFIG (JSON) → JSON en stdout.
// Cada paso es independiente: un fallo se registra en out.pasos[nombre] y el recorrido continúa.
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const cfg = JSON.parse(process.env.QA_CONFIG);
const CHROME = process.env.CHROME_BIN || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
if (!fs.existsSync(CHROME)) { console.log(JSON.stringify({ error: 'Chromium no encontrado en ' + CHROME })); process.exit(0); }
const out = { pasos: {}, consola: [] };
const esperar = ms => new Promise(r => setTimeout(r, ms));
async function paso(nombre, fn) {
  if (process.env.QA_SOLO && !new RegExp(process.env.QA_SOLO).test(nombre)) { return; }
  try { out.pasos[nombre] = Object.assign({ ok: true }, await fn() || {}); }
  catch (e) { out.pasos[nombre] = { ok: false, error: String(e && e.message || e).slice(0, 500) }; }
}

(async () => {
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox', '--disable-setuid-sandbox'] });
  try {
    const page = await browser.newPage();
    await page.setViewport({ width: 1280, height: 900 });
    page.on('console', m => { if (m.type() === 'error') out.consola.push({ texto: m.text(), url: page.url() }); });
    page.on('pageerror', e => out.consola.push({ texto: String(e.message), url: page.url() }));
    const url = () => new URL(page.url());
    const ir = async ruta => (await page.goto(cfg.base + ruta, { waitUntil: 'networkidle0' })).status();
    const login = async (email, pass) => {
      await ir('/equipo/login'); await page.type('#email', email); await page.type('#password', pass);
      await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.keyboard.press('Enter')]);
    };
    const cerrar = async () => { await page.evaluate(() => { const f = document.querySelector('form[action="/logout"]'); if (f) f.submit(); }); await page.waitForNavigation({ waitUntil: 'networkidle0' }).catch(() => {}); };

    // Estado del botón tras el primer clic + clics/envíos extra en el MISMO turno síncrono; los POST se cuentan en la red (page.on('request')).
    const probarEnvio = async (selForm, rutaPost, preparar) => {
      let posts = 0;
      const contar = req => { if (req.method() === 'POST' && rutaPost.test(req.url())) posts++; };
      page.on('request', contar);
      try {
        if (!(await page.$(selForm))) throw new Error('sin formulario ' + selForm);
        if (preparar) await preparar();
        const nav = page.waitForNavigation({ waitUntil: 'networkidle0', timeout: 20000 }).catch(() => {});
        await page.evaluate(() => { try { sessionStorage.removeItem('__r5b'); } catch (e) {} }).catch(() => {});
        const r = await page.evaluate(sel => {
          const f = document.querySelector(sel);
          const b = f.querySelector('button[type=submit],input[type=submit],button:not([type])');
          const leer = () => ({ disabled: b.disabled, busy: b.getAttribute('aria-busy'), texto: (b.textContent || b.value).trim(), formBusy: f.getAttribute('aria-busy') });
          b.click(); const t0 = leer(); b.click(); b.click();
          if (f.requestSubmit) { try { f.requestSubmit(); } catch (e) {} }
          const t1 = leer();
          try { sessionStorage.setItem('__r5b', JSON.stringify({ t0, t1 })); } catch (e) {}
          return { t0, t1 };
        }, selForm).catch(e => ({ error: String(e.message).slice(0, 150) }));
        await nav; await esperar(400);
        if (r && r.error) { const g = await page.evaluate(() => { try { return JSON.parse(sessionStorage.getItem('__r5b') || 'null'); } catch (e) { return null; } }).catch(() => null); if (g) return Object.assign({ posts }, g); }
        return Object.assign({ posts }, r);
      } finally { page.off('request', contar); }
    };
    const enProceso = e => !!e && !e.error && [e.t0, e.t1].some(t => t && (t.disabled || t.busy === 'true' || t.formBusy === 'true' || /procesando|enviando|guardando|registrando|un momento/i.test(t.texto)));
    const rellenar = async (sel, efectiva, motivo) => {
      await page.$eval(`${sel} [name=efectiva]`, (e, v) => { e.value = v; e.dispatchEvent(new Event('input', { bubbles: true })); e.dispatchEvent(new Event('change', { bubbles: true })); }, efectiva);
      await page.$eval(`${sel} [name=motivo]`, (e, v) => { e.value = v; e.dispatchEvent(new Event('input', { bubbles: true })); }, motivo);
    };

    await login(cfg.dueno.email, cfg.dueno.password);

    // ---- modal / panel de «Regularizar»: foco, trampa, Escape y retorno al disparador
    await paso('M_modal_regularizar', async () => {
      await ir(`/agenda/cita?id=${cfg.citaModal}`);
      const trig = await page.evaluateHandle(() => [...document.querySelectorAll('button,a,summary')].find(b => /^Regularizar/i.test((b.textContent || '').replace(/\s+/g, ' ').trim())) || null);
      if (!trig.asElement()) return { sinDisparador: true, botones: await page.$$eval('button,summary,a.boton', b => b.map(x => x.textContent.replace(/\s+/g, ' ').trim()).slice(0, 15)) };
      await page.evaluate(el => el.setAttribute('data-q5b', '1'), trig.asElement());
      await trig.asElement().focus(); await page.keyboard.press('Enter'); await esperar(600);
      const d = await page.evaluate(() => {
        const dlg = document.querySelector('dialog[open], [role=dialog], [role=alertdialog], [aria-modal=true], .swal2-popup');
        if (!dlg) return null;
        return { dentro: dlg.contains(document.activeElement), nombre: !!(dlg.getAttribute('aria-labelledby') || dlg.getAttribute('aria-label') || dlg.querySelector('h1,h2,h3')), modal: dlg.getAttribute('aria-modal') === 'true' || dlg.tagName === 'DIALOG', campos: [...dlg.querySelectorAll('[name]')].map(x => x.name) };
      });
      if (!d) return { sinModal: true, formVisible: await page.evaluate(() => { const f = document.querySelector('form[action$="/agenda/regularizar"]'); return !!f && f.getBoundingClientRect().height > 0; }) };
      let atrapa = true;
      for (let i = 0; i < 12; i++) { await page.keyboard.press('Tab'); atrapa = atrapa && await page.evaluate(() => { const dlg = document.querySelector('dialog[open], [role=dialog], [aria-modal=true], .swal2-popup'); if (!dlg) return false; return dlg.contains(document.activeElement) || (dlg.tagName === 'DIALOG' && dlg.matches(':modal') && document.activeElement === document.body); }); }
      await page.keyboard.press('Escape'); await esperar(500);
      const p = await page.evaluate(() => ({ abierto: !!document.querySelector('dialog[open], [role=dialog]:not([hidden]), [aria-modal=true]'), retorno: !!(document.activeElement && document.activeElement.hasAttribute('data-q5b')) }));
      return Object.assign(d, { atrapaFoco: atrapa, cierraConEscape: !p.abierto, devuelveFoco: p.retorno });
    });

    // ---- envío de «Regularizar»: estado de proceso y UN solo POST
    await paso('N_regularizar_un_solo_post', async () => {
      await ir(`/agenda/cita?id=${cfg.citaEnvio}`);
      const sel = 'form[action$="/agenda/regularizar"]';
      const r = await probarEnvio(sel, /\/agenda\/regularizar$/, async () => {
        await page.evaluate(() => { const d = document.querySelector('details:has(form[action$="/agenda/regularizar"])'); if (d) d.open = true; });
        await rellenar(sel, cfg.efectivaEnvio, 'Regularización hecha desde el navegador de prueba');
      });
      return { posts: r.posts, enProceso: enProceso(r), estado: r, destino: url().pathname + url().search, texto: await page.evaluate(() => document.body.innerText.replace(/\s+/g, ' ').slice(0, 400)) };
    });

    // ---- mensaje concreto y persistente ante una efectiva futura (validación del servidor)
    await paso('N_mensaje_efectiva_invalida', async () => {
      await ir(`/agenda/cita?id=${cfg.citaInvalida}`);
      const sel = 'form[action$="/agenda/regularizar"]';
      await page.evaluate(() => { const d = document.querySelector('details:has(form[action$="/agenda/regularizar"])'); if (d) d.open = true; document.querySelectorAll('form[action$="/agenda/regularizar"] [name=efectiva]').forEach(e => { e.removeAttribute('max'); e.removeAttribute('min'); }); });
      await rellenar(sel, cfg.efectivaFutura, 'Intento con fecha efectiva futura');
      await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }).catch(() => {}), page.$eval(`${sel} button[type=submit]`, b => b.click()).catch(() => {})]);
      const t = await page.evaluate(() => (document.querySelector('main,#contenido-principal') || document.body).innerText.replace(/\s+/g, ' ').slice(0, 600));
      await esperar(5500);
      const t2 = await page.evaluate(() => (document.querySelector('main,#contenido-principal') || document.body).innerText.replace(/\s+/g, ' ').slice(0, 600));
      const volver = await page.$$eval('a[href]', a => a.map(x => x.getAttribute('href')).filter(h => /^\/agenda/.test(h)));
      return { url: url().pathname, texto: t, persiste: t2 === t, volver };
    });

    // ---- pendientes y detalle sin desbordes 320–1440 y sin errores de consola
    await paso('R_sin_desbordes', async () => {
      const malos = [];
      for (const ruta of ['/agenda/pendientes', `/agenda/cita?id=${cfg.citaModal}`, `/agenda?desde=${cfg.desde}&hasta=${cfg.hasta}`]) {
        for (const w of [320, 390, 768, 1440]) {
          await page.setViewport({ width: w, height: 900 });
          const st = await ir(ruta);
          const m = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth }));
          if (st !== 200) { malos.push(`${ruta} @${w}: HTTP ${st}`); break; }
          if (m.sw > m.cw + 1) malos.push(`${ruta} @${w}: scroll ${m.sw} > ${m.cw}`);
        }
      }
      await page.setViewport({ width: 1280, height: 900 });
      return { malos };
    });

    await paso('K_teclado_pendientes', async () => {
      await ir('/agenda/pendientes');
      const sin = [];
      for (let i = 0; i < 30; i++) {
        await page.keyboard.press('Tab');
        const e = await page.evaluate(() => { const a = document.activeElement; if (!a || a === document.body) return null; const s = getComputedStyle(a); const f = x => [x.outlineStyle + x.outlineWidth, x.boxShadow, x.borderColor, x.backgroundColor].join('|'); const con = f(s); a.blur(); const sinf = f(getComputedStyle(a)); a.focus();
          return { tag: a.tagName.toLowerCase(), nombre: (a.textContent || a.value || '').replace(/\s+/g, ' ').trim().slice(0, 30), ok: (s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) > 0) || s.boxShadow !== 'none' || con !== sinf }; });
        if (e && !e.ok && ['a', 'button', 'input', 'select', 'summary', 'textarea'].includes(e.tag)) sin.push(e.tag + ':' + e.nombre);
      }
      return { sinIndicador: sin };
    });

    // ---- profesional: solicita desde el navegador; no ve «Regularizar»
    await cerrar();
    await paso('S_profesional_solicita', async () => {
      await login(cfg.pro.email, cfg.pro.password);
      await ir(`/agenda/cita?id=${cfg.citaSolicitud}`);
      const tieneReg = await page.$('form[action$="/agenda/regularizar"]');
      const sel = 'form[action$="/agenda/regularizar/solicitar"]';
      if (!(await page.$(sel))) throw new Error('el profesional no ve el formulario de solicitud');
      await page.evaluate(() => { const d = document.querySelector('details:has(form[action$="/agenda/regularizar/solicitar"])'); if (d) d.open = true; });
      await rellenar(sel, cfg.efectivaSolicitud, 'Atendí a la clienta y no alcancé a registrarlo');
      await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.$eval(`${sel} button[type=submit]`, b => b.click())]);
      const txt = await page.evaluate(() => document.body.innerText.replace(/\s+/g, ' '));
      return { veRegularizar: !!tieneReg, destino: url().pathname + url().search, aviso: /solicitud enviada|solicitud/i.test(txt) };
    });
  } catch (e) {
    out.error = String(e && e.stack || e);
  } finally {
    await browser.close().catch(() => {});
  }
  console.log(JSON.stringify(out));
})();

// QA independiente (ronda 2B) del frontend de las pantallas nuevas: Chromium real + puppeteer-core. QA_CONFIG (JSON) → JSON en stdout.
const puppeteer = require('puppeteer-core');
const cfg = JSON.parse(process.env.QA_CONFIG);
const CHROME = process.env.CHROME_BIN || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
if (!require('fs').existsSync(CHROME)) { console.log(JSON.stringify({ error: 'Chromium no encontrado en ' + CHROME })); process.exit(0); }
const ANCHOS = [320, 360, 390, 768, 1024, 1440];
const out = { medidas: [], eventos: [], objetivos: [], asistente: {}, teclado: [], movimiento: [], sinJs: {}, clienteSinSesion: {} };

(async () => {
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox', '--disable-setuid-sandbox'] });
  try {
    const page = await browser.newPage();
    const eventos = out.eventos;
    const vigilar = p => {
      p.on('console', m => { if (m.type() === 'error') eventos.push({ tipo: 'console', texto: m.text(), url: p.url() }); });
      p.on('pageerror', e => eventos.push({ tipo: 'pageerror', texto: String(e.message), url: p.url() }));
      p.on('requestfailed', r => eventos.push({ tipo: 'requestfailed', texto: r.url() + ' ' + (r.failure() || {}).errorText, url: p.url() }));
      p.on('response', r => { if (r.status() >= 400 && !/favicon/.test(r.url()) && r.request().resourceType() !== 'document') eventos.push({ tipo: 'http' + r.status(), texto: r.url(), url: p.url() }); });
    };
    vigilar(page);

    const medidaActual = async (p, etiqueta, ancho, status) => {
      const r = await p.evaluate(() => {
        const ancho = document.documentElement.clientWidth;
        const sobresale = [...document.querySelectorAll('body *')].filter(e => {
          const b = e.getBoundingClientRect();
          if (b.width === 0 || b.height === 0) return false;
          for (let q = e.parentElement; q && q !== document.body; q = q.parentElement) { if (['auto', 'scroll'].includes(getComputedStyle(q).overflowX)) return false; }
          return b.right > ancho + 1 || b.left < -1;
        }).slice(0, 4).map(e => e.tagName.toLowerCase() + (e.className ? '.' + String(e.className).split(' ')[0] : '') + '#' + (e.id || ''));
        return { scrollWidth: document.documentElement.scrollWidth, clientWidth: ancho, sobresale, h1: (document.querySelector('h1') || {}).textContent || '' };
      });
      out.medidas.push({ ruta: etiqueta, ancho, status, ...r, malo: r.scrollWidth > r.clientWidth + 1 || r.sobresale.length > 0 });
    };
    // Objetivos táctiles: controles interactivos visibles menores de 44 px de alto (sin los ocultos a propósito para lectores de pantalla).
    const objetivos = async (p, etiqueta, ancho) => {
      const peq = await p.evaluate(() => [...document.querySelectorAll('button, a.boton, input[type=submit], select, input:not([type=hidden]):not([type=checkbox]):not([type=radio]), summary')].filter(e => {
        const b = e.getBoundingClientRect(); const s = getComputedStyle(e);
        if (b.width === 0 || b.height === 0 || s.visibility === 'hidden' || s.display === 'none') return false;
        if (e.getAttribute('aria-hidden') === 'true' || e.tabIndex === -1 && e.classList.contains('crm-sr')) return false;
        if (e.closest('[hidden]') || e.disabled) return false;
        return b.height < 44 - 0.5;
      }).slice(0, 5).map(e => e.tagName.toLowerCase() + '.' + String(e.className).split(' ')[0] + ':' + Math.round(e.getBoundingClientRect().height)));
      if (peq.length) { out.objetivos.push({ ruta: etiqueta, ancho, controles: peq }); }
    };
    const recorrer = async (ruta, etiqueta, antes) => {
      for (const w of ANCHOS) {
        await page.setViewport({ width: w, height: 900, deviceScaleFactor: 1 });
        const resp = await page.goto(cfg.base + ruta, { waitUntil: 'networkidle0' });
        if (antes) { await antes(w); }
        await medidaActual(page, etiqueta, w, resp.status());
        if (w === 390 || w === 1440) { await objetivos(page, etiqueta, w); }
      }
    };

    // --- login del propietario por la interfaz
    await page.setViewport({ width: 1024, height: 800 });
    await page.goto(cfg.base + '/equipo/login', { waitUntil: 'networkidle0' });
    await page.type('#email', cfg.owner.email); await page.type('#password', cfg.owner.password);
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.keyboard.press('Enter')]);

    await recorrer('/admin/equipo/reasignar?id=' + cfg.colProf, '/admin/equipo/reasignar');
    await recorrer('/admin/equipo/ficha?id=' + cfg.colProf, '/admin/equipo/ficha (asistente)');
    await recorrer('/admin/clientes/auditoria', '/admin/clientes/auditoria');
    await recorrer('/admin/clientes/auditoria?por_pagina=100&resultado=denegada', '/admin/clientes/auditoria (filtrada)');
    await recorrer('/admin/clientes/nuevo', '/admin/clientes/nuevo');
    await recorrer('/profesionales/crear', '/profesionales/crear');
    await recorrer('/admin/equipo', '/admin/equipo (rol profesional)', async () => { await page.select('#eq-rol', 'profesional').catch(() => {}); });

    // --- aviso de homónimos de CLIENTES (flujo real de la interfaz)
    for (const w of ANCHOS) {
      await page.setViewport({ width: w, height: 900 });
      await page.goto(cfg.base + '/admin/clientes/nuevo', { waitUntil: 'networkidle0' });
      await page.type('#alta-nombre', cfg.homonimo.nombre); await page.type('#alta-apellido', cfg.homonimo.apellido);
      await page.type('#alta-email', 'nuevo.' + w + '.' + Date.now() + '@example.test'); await page.type('#alta-telefono', '0991' + String(Math.floor(Math.random() * 900000 + 100000)));
      await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('button[value=crear]:not([aria-hidden])')]);
      const aviso = await page.$('.crm-homonimos');
      out.clienteSinSesion['aviso@' + w] = !!aviso;
      await medidaActual(page, '/admin/clientes/nuevo (aviso de homónimos)', w, 200);
      if (w === 390) { await objetivos(page, '/admin/clientes/nuevo (aviso)', w); }
    }
    // --- aviso de homónimos de PROFESIONALES
    for (const w of [320, 768, 1440]) {
      await page.setViewport({ width: w, height: 900 });
      await page.goto(cfg.base + '/profesionales/crear', { waitUntil: 'networkidle0' });
      await page.type('#nombre', cfg.profHomonimo);
      await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('input[type=submit].boton')]);
      out.clienteSinSesion['avisoProf@' + w] = !!(await page.$('.crm-homonimos'));
      await medidaActual(page, '/profesionales/crear (aviso de homónimos)', w, 200);
    }

    // --- asistente: añadir turnos/descansos con JS, copiar el lunes y guardar (contrato JS ↔ servidor)
    await page.setViewport({ width: 1280, height: 1000 });
    await page.goto(cfg.base + '/admin/equipo/ficha?id=' + cfg.colProf, { waitUntil: 'networkidle0' });
    await page.click('[data-agenda-asistente] > details.gestion-detalle > summary');
    const setHora = async (sel, v) => { await page.$eval(sel, (e, val) => { e.value = val; e.dispatchEvent(new Event('input', { bubbles: true })); e.dispatchEvent(new Event('change', { bubbles: true })); }, v); };
    // Lunes: dos turnos y un descanso con motivo
    await setHora('[data-ag-dia="1"] [data-ag-lista="franja"] [data-ag-fila]:first-child input[name$="[inicio]"]', '09:00');
    await setHora('[data-ag-dia="1"] [data-ag-lista="franja"] [data-ag-fila]:first-child input[name$="[fin]"]', '13:00');
    await page.click('[data-ag-dia="1"] [data-ag-agregar="franja"]');
    const filasLunes = await page.$$('[data-ag-dia="1"] [data-ag-lista="franja"] [data-ag-fila]');
    out.asistente.filasLunesTrasAgregar = filasLunes.length;
    await setHora('[data-ag-dia="1"] [data-ag-lista="franja"] [data-ag-fila]:nth-child(2) input[name$="[inicio]"]', '14:00');
    await setHora('[data-ag-dia="1"] [data-ag-lista="franja"] [data-ag-fila]:nth-child(2) input[name$="[fin]"]', '18:00');
    await page.evaluate(() => { document.querySelector('[data-ag-dia="1"] details.ag-descansos').open = true; });
    await setHora('[data-ag-dia="1"] [data-ag-lista="descanso"] [data-ag-fila]:first-child input[name$="[inicio]"]', '12:00');
    await setHora('[data-ag-dia="1"] [data-ag-lista="descanso"] [data-ag-fila]:first-child input[name$="[fin]"]', '12:30');
    await page.$eval('[data-ag-dia="1"] [data-ag-lista="descanso"] [data-ag-fila]:first-child input[name$="[motivo]"]', e => { e.value = 'Café <b>x</b>'; });
    await page.click('[data-ag-copiar-lunes]');
    out.asistente.martesTrasCopiar = await page.$$eval('[data-ag-dia="2"] [data-ag-lista="franja"] [data-ag-fila]', f => f.map(x => x.querySelector('input[name$="[inicio]"]').value + '-' + x.querySelector('input[name$="[fin]"]').value));
    out.asistente.nombresMartes = await page.$$eval('[data-ag-dia="2"] input', i => i.map(x => x.name));
    // Quitar el segundo turno del viernes y volver a añadir uno
    await page.click('[data-ag-dia="5"] [data-ag-lista="franja"] [data-ag-fila]:nth-child(2) [data-ag-quitar]');
    out.asistente.filasViernesTrasQuitar = (await page.$$('[data-ag-dia="5"] [data-ag-lista="franja"] [data-ag-fila]')).length;
    // Enviar con el botón real
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('form[action^="/admin/equipo/ficha/agenda"] button[type=submit]')]);
    out.asistente.urlTrasGuardar = new URL(page.url()).pathname + new URL(page.url()).search;
    out.asistente.mensaje = ((await page.$eval('.alerta', e => e.textContent).catch(() => '')) || '').trim();
    out.asistente.martesRecargado = await page.$$eval('[data-ag-dia="2"] [data-ag-lista="franja"] [data-ag-fila]', f => f.map(x => x.querySelector('input[name$="[inicio]"]').value + '-' + x.querySelector('input[name$="[fin]"]').value));
    out.asistente.motivoRecargado = await page.$eval('[data-ag-dia="1"] [data-ag-lista="descanso"] [data-ag-fila]:first-child input[name$="[motivo]"]', e => e.value).catch(() => null);

    // --- teclado: orden y foco visible en las pantallas nuevas
    for (const ruta of ['/admin/equipo/reasignar?id=' + cfg.colProf, '/admin/clientes/auditoria', '/admin/clientes/nuevo']) {
      await page.setViewport({ width: 1024, height: 800 });
      await page.goto(cfg.base + ruta, { waitUntil: 'networkidle0' });
      const seq = [];
      for (let i = 0; i < 40; i++) {
        await page.keyboard.press('Tab');
        const e = await page.evaluate(() => { const a = document.activeElement; if (!a || a === document.body) return null; const s = getComputedStyle(a); const b = a.getBoundingClientRect();
          return { tag: a.tagName.toLowerCase(), id: a.id, name: a.getAttribute('name'), contorno: s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) > 0, sombra: s.boxShadow !== 'none', visible: b.width > 0 && b.height > 0 }; });
        if (e) { seq.push(e); }
      }
      out.teclado.push({ ruta, total: seq.length, sinIndicador: seq.filter(s => ['input', 'a', 'button', 'select', 'summary'].includes(s.tag) && !s.contorno && !s.sombra).map(s => s.tag + '#' + s.id + '[' + s.name + ']') });
    }

    // --- movimiento reducido: sin animaciones en curso
    await page.emulateMediaFeatures([{ name: 'prefers-reduced-motion', value: 'reduce' }]);
    for (const ruta of ['/admin/equipo/reasignar?id=' + cfg.colProf, '/admin/clientes/auditoria', '/admin/clientes/nuevo', '/admin/equipo/ficha?id=' + cfg.colProf]) {
      await page.goto(cfg.base + ruta, { waitUntil: 'networkidle0' });
      await new Promise(r => setTimeout(r, 400));
      const n = await page.evaluate(() => document.getAnimations().filter(a => a.playState === 'running' && a.effect && a.effect.getTiming().iterations === Infinity).length);
      out.movimiento.push({ ruta, animacionesInfinitas: n });
    }
    await page.emulateMediaFeatures([]);

    // --- sin JavaScript: el alta de cliente con aviso y la reasignación siguen siendo utilizables
    const sj = await browser.newPage(); vigilar(sj);
    await sj.setJavaScriptEnabled(false);
    await sj.setViewport({ width: 390, height: 900 });
    await sj.goto(cfg.base + '/equipo/login', { waitUntil: 'load' });
    await sj.type('#email', cfg.owner.email); await sj.type('#password', cfg.owner.password);
    await Promise.all([sj.waitForNavigation({ waitUntil: 'load' }), sj.click('form button[type=submit], form input[type=submit]')]);
    await sj.goto(cfg.base + '/admin/clientes/nuevo', { waitUntil: 'load' });
    await sj.type('#alta-nombre', cfg.homonimo.nombre); await sj.type('#alta-apellido', cfg.homonimo.apellido); await sj.type('#alta-email', 'sinjs.' + Date.now() + '@example.test'); await sj.type('#alta-telefono', '0994448899');
    await Promise.all([sj.waitForNavigation({ waitUntil: 'load' }), sj.click('button[value=crear]:not([aria-hidden])')]);
    out.sinJs.avisoVisible = !!(await sj.$('.crm-homonimos'));
    out.sinJs.botonConfirmar = !!(await sj.$('button[value=confirmar]'));
    await sj.goto(cfg.base + '/admin/equipo/reasignar?id=' + cfg.colProf, { waitUntil: 'load' });
    out.sinJs.selectoresDecision = (await sj.$$('select[name^="decision["]')).length;
    out.sinJs.formularioAgenda = await (async () => { await sj.goto(cfg.base + '/admin/equipo/ficha?id=' + cfg.colProf, { waitUntil: 'load' }); return (await sj.$$('[data-ag-dia] input[type=time]')).length; })();
    await sj.close();

    // --- el cliente ve su consentimiento versionado y el aviso de versión anterior sin desbordes
    const cp = await browser.newPage(); vigilar(cp);
    await cp.setViewport({ width: 320, height: 900 });
    await cp.goto(cfg.base + '/cliente/login', { waitUntil: 'networkidle0' });
    await cp.type('#email', cfg.cliente.email); await cp.type('#password', cfg.cliente.password);
    await Promise.all([cp.waitForNavigation({ waitUntil: 'networkidle0' }), cp.keyboard.press('Enter')]);
    for (const w of ANCHOS) {
      await cp.setViewport({ width: w, height: 900 });
      const r = await cp.goto(cfg.base + '/perfil', { waitUntil: 'networkidle0' });
      await medidaActual(cp, '/perfil (consentimiento anterior)', w, r.status());
    }
    out.clienteSinSesion.avisoVersionAnterior = !!(await cp.$('#consentimiento-anterior'));
    out.clienteSinSesion.reconfirmar = !!(await cp.$('input[name=reconfirmar_consentimiento]'));
    for (const w of [320, 768]) { await cp.setViewport({ width: w, height: 900 }); const r = await cp.goto(cfg.base + '/mi-bienestar', { waitUntil: 'networkidle0' }); await medidaActual(cp, '/mi-bienestar (consentimiento)', w, r.status()); }
    await cp.close();
  } catch (e) {
    out.error = String(e && e.stack || e);
  } finally {
    await browser.close();
    process.stdout.write(JSON.stringify(out));
  }
})();

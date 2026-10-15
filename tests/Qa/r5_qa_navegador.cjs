// QA independiente (ronda 5): recorrido de NAVEGADOR real (Chromium + puppeteer-core) del flujo agenda → atención → cobro → comprobante,
// de la separación de portales y de la accesibilidad/UX mínima. QA_CONFIG (JSON) → JSON en stdout. Cada paso es independiente:
// un fallo se registra en out.pasos[nombre] = { ok:false, error } y el recorrido continúa (así una pantalla ausente no oculta las demás).
const puppeteer = require('puppeteer-core');
const fs = require('fs');
const os = require('os');
const path = require('path');
const cfg = JSON.parse(process.env.QA_CONFIG);
const CHROME = process.env.CHROME_BIN || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
if (!fs.existsSync(CHROME)) { console.log(JSON.stringify({ error: 'Chromium no encontrado en ' + CHROME })); process.exit(0); }
const out = { pasos: {}, consola: [] };
const esperar = ms => new Promise(r => setTimeout(r, ms));

async function paso(nombre, fn) {
  try { const r = await fn(); out.pasos[nombre] = Object.assign({ ok: true }, r || {}); }
  catch (e) { out.pasos[nombre] = { ok: false, error: String(e && e.message || e).slice(0, 600) }; }
}

// ---- utilidades de DOM ejecutadas en la página
const FN = {
  tarjeta: id => {
    const re = new RegExp('Cita\\s*#\\s*' + id + '\\b');
    let mejor = null;
    for (const n of document.querySelectorAll('article,li,tr,[class*=tarjeta],[class*=card]')) {
      const t = n.textContent || '';
      const ok = re.test(t) || n.querySelector('input[name=id][value="' + id + '"]') || [...n.querySelectorAll('a[href]')].some(a => new RegExp('[?&]id=' + id + '\\b').test(a.getAttribute('href')));
      if (ok && (!mejor || n.innerHTML.length < mejor.innerHTML.length)) mejor = n;
    }
    return mejor;
  },
  control: (raiz, re) => [...(raiz || document).querySelectorAll('button,a,input[type=submit]')].find(b => new RegExp(re, 'i').test((b.textContent || b.value || '').replace(/\s+/g, ' ').trim())) || null,
};

(async () => {
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox', '--disable-setuid-sandbox'] });
  const dl = fs.mkdtempSync(path.join(os.tmpdir(), 'q5dl_'));
  try {
    const page = await browser.newPage();
    await page.setViewport({ width: 1280, height: 900 });
    page.on('console', m => { if (m.type() === 'error') out.consola.push({ tipo: 'console', texto: m.text(), url: page.url() }); });
    page.on('pageerror', e => out.consola.push({ tipo: 'pageerror', texto: String(e.message), url: page.url() }));
    const cdp = await page.createCDPSession();
    await cdp.send('Page.setDownloadBehavior', { behavior: 'allow', downloadPath: dl });
    const url = () => new URL(page.url());
    const ir = async ruta => { const r = await page.goto(cfg.base + ruta, { waitUntil: 'networkidle0' }); return r.status(); };
    const clic = async (handle) => { const [ , ] = await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0', timeout: 15000 }), handle.click()]); };
    const accion = async (idCita, re) => {
      const card = await page.evaluateHandle(FN.tarjeta, String(idCita));
      if (!card.asElement()) throw new Error('la agenda no muestra la cita #' + idCita);
      const btn = await page.evaluateHandle(FN.control, card, re);
      if (!btn.asElement()) throw new Error('la tarjeta #' + idCita + ' no tiene un control «' + re + '»');
      return { card, btn: btn.asElement() };
    };
    const login = async (email, pass, portal) => {
      await ir(portal === 'cliente' ? '/cliente/login' : '/equipo/login');
      await page.type('#email', email); await page.type('#password', pass);
      await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.keyboard.press('Enter')]);
    };
    const cerrarSesion = async () => { await page.evaluate(() => { const f = document.querySelector('form[action="/logout"]'); if (f) f.submit(); }); await page.waitForNavigation({ waitUntil: 'networkidle0' }).catch(() => {}); };
    const periodo = `desde=${cfg.desde}&hasta=${cfg.hasta}`;

    await login(cfg.owner.email, cfg.owner.password, 'equipo');
    out.pasos.login = { ok: url().pathname !== '/equipo/login', url: url().pathname };

    // ===================================================================== Flujo A: iniciar atención con un clic real
    await paso('A_iniciar_cita_comenzada', async () => {
      await ir(`/agenda?${periodo}`);
      const { btn } = await accion(cfg.citaComenzada, 'Iniciar atenci');
      const antes = url().pathname;
      await clic(btn);
      const u = url();
      const h1 = await page.$eval('h1', e => e.textContent.trim()).catch(() => '');
      const texto = await page.evaluate(() => document.body.innerText);
      const desde = await page.$eval('input[name=desde]', e => e.value).catch(() => null);
      const hasta = await page.$eval('input[name=hasta]', e => e.value).catch(() => null);
      const card = await page.evaluateHandle(FN.tarjeta, String(cfg.citaComenzada));
      const finalizar = card.asElement() ? !!(await page.evaluateHandle(FN.control, card, 'Finalizar atenci')).asElement() : false;
      return { antes, destino: u.pathname + u.search, h1, enServicio: /en[ _]servicio/i.test(texto), desde, hasta, ofreceFinalizar: finalizar, enlacesMisCitas: await page.$$eval('a[href="/mis-citas"],a[href^="/mis-citas?"]', a => a.length) };
    });

    await paso('A_cita_futura_deshabilitada', async () => {
      await ir(`/agenda?${periodo}`);
      const card = await page.evaluateHandle(FN.tarjeta, String(cfg.citaFutura));
      if (!card.asElement()) throw new Error('la agenda no muestra la cita futura');
      const info = await page.evaluate((c) => {
        const b = [...c.querySelectorAll('button,a,input[type=submit]')].find(x => /Iniciar atenci/i.test(x.textContent || x.value));
        return { hay: !!b, disabled: !!(b && (b.disabled || b.getAttribute('aria-disabled') === 'true')), motivo: /Disponible desde(?: las)? \d{1,2}:\d{2}/i.test(c.innerText), texto: c.innerText.replace(/\s+/g, ' ').slice(0, 300) };
      }, card);
      let urlTrasClic = null;
      if (info.hay) {
        const btn = (await page.evaluateHandle(FN.control, card, 'Iniciar atenci')).asElement();
        const antes = page.url();
        await btn.click().catch(() => {});
        await esperar(700);
        urlTrasClic = page.url() === antes ? 'sin cambio' : page.url();
      }
      return Object.assign(info, { urlTrasClic });
    });

    await paso('A_post_forjado_error_dentro_del_layout', async () => {
      await ir(`/agenda?${periodo}`);
      // Reenvía el formulario real de «Iniciar» de una cita comenzada pero apuntando a la futura (lo que haría un cliente HTTP manipulado).
      const donante = await accion(cfg.citaComenzadaDos, 'Iniciar atenci');
      await page.evaluate((card, objetivo) => {
        const f = card.querySelector('form'); f.querySelector('input[name=id]').value = String(objetivo); f.submit();
      }, donante.card, cfg.citaFutura);
      await page.waitForNavigation({ waitUntil: 'networkidle0' });
      const info = await page.evaluate(() => ({
        h1: (document.querySelector('h1') || {}).textContent || '', texto: document.body.innerText.replace(/\s+/g, ' ').slice(0, 400), tieneLayout: !!document.querySelector('main,#contenido-principal,.contenedor-app'),
        enlaces: [...document.querySelectorAll('a[href]')].map(a => ({ href: a.getAttribute('href'), texto: a.textContent.replace(/\s+/g, ' ').trim() })),
      }));
      const volver = info.enlaces.find(a => /^\/agenda(\?|$)/.test(a.href) && /volver/i.test(a.texto));
      let tras = null;
      if (volver) { await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.evaluate(h => document.querySelector('a[href="' + h + '"]').click(), volver.href)]); tras = url().pathname + url().search; }
      return { h1: info.h1, texto: info.texto, tieneLayout: info.tieneLayout, hrefs: info.enlaces.map(a => a.href), volverHref: volver ? volver.href : null, trasVolver: tras };
    });

    await paso('C_finalizar_atencion', async () => {
      await ir(`/agenda?${periodo}`);
      const { btn } = await accion(cfg.citaEnServicio, '^Finalizar atenci');
      await clic(btn);
      const texto = await page.evaluate(() => document.body.innerText);
      return { destino: url().pathname + url().search, completada: /completada/i.test(texto), dicePagado: /pagad[oa]\b/i.test(texto) && !/pendiente/i.test(texto) };
    });

    await paso('C_finalizar_y_cobrar', async () => {
      await ir(`/agenda?${periodo}`);
      const { btn } = await accion(cfg.citaCobrar, 'Finalizar y cobrar');
      await clic(btn);
      const u = url();
      const info = await page.evaluate(() => ({ h1: (document.querySelector('h1') || {}).textContent || '', texto: document.body.innerText.replace(/\s+/g, ' '), formularios: [...document.forms].map(f => f.getAttribute('action')) }));
      return { destino: u.pathname + u.search, id: u.searchParams.get('id'), h1: info.h1, muestraCliente: /Clienta/.test(info.texto), muestraTotal: /20\.00/.test(info.texto), formularios: info.formularios };
    });

    // ===================================================================== Cobro en pantalla: estado de proceso, doble envío y mensajes
    await paso('N_cobro_exceso_mensaje_concreto_y_persistente', async () => {
      await ir(`/caja/cita?id=${cfg.citaCobro}`);
      const form = await page.$('form[action*="/caja/cita/cobrar"]');
      if (!form) throw new Error('GET /caja/cita?id=' + cfg.citaCobro + ' no tiene formulario de cobro');
      const campos = await page.evaluate(() => { const f = document.querySelector('form[action*="/caja/cita/cobrar"]'); return [...f.elements].filter(e => e.name).map(e => ({ n: e.name, t: e.type, v: e.value })); });
      const monto = campos.find(c => /monto|importe|valor|cantidad/i.test(c.n) && !/recib|cambio|total|saldo/i.test(c.n));
      const metodo = campos.find(c => /metodo|medio|forma/i.test(c.n));
      if (!monto) throw new Error('sin campo de monto: ' + JSON.stringify(campos.map(c => c.n)));
      await page.$eval('form[action*="/caja/cita/cobrar"] [name="' + monto.n + '"]', e => { e.value = '999.00'; e.dispatchEvent(new Event('input', { bubbles: true })); });
      if (metodo) { await page.select('form[action*="/caja/cita/cobrar"] [name="' + metodo.n + '"]', ...(await page.$$eval('form[action*="/caja/cita/cobrar"] [name="' + metodo.n + '"] option', o => o.filter(x => /efectivo/i.test(x.value + x.textContent)).map(x => x.value))).slice(0, 1)).catch(() => {}); }
      const rec = campos.find(c => /recib|entreg/i.test(c.n));
      if (rec) { await page.$eval('form[action*="/caja/cita/cobrar"] [name="' + rec.n + '"]', e => { e.value = '999.00'; }); }
      const enviar = await page.$('form[action*="/caja/cita/cobrar"] button[type=submit], form[action*="/caja/cita/cobrar"] input[type=submit]');
      await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }).catch(() => {}), enviar.click()]);
      await esperar(300);
      const info = await page.evaluate(() => {
        const a = [...document.querySelectorAll('.alerta,[role=alert],[role=status],.alert,.mensaje-error,.ui-alert')].map(x => x.innerText.trim()).filter(Boolean);
        return { alertas: a, texto: document.body.innerText.replace(/\s+/g, ' ').slice(0, 500), status: document.title };
      });
      await esperar(5500); // un toast desaparece; el mensaje crítico debe seguir en la página
      const despues = await page.evaluate(() => [...document.querySelectorAll('.alerta,[role=alert],[role=status],.alert,.mensaje-error,.ui-alert')].map(x => x.innerText.trim()).filter(Boolean));
      return { alertasInmediatas: info.alertas, alertasTras5s: despues, texto: info.texto };
    });

    await paso('N_cobro_boton_en_proceso_y_un_solo_post', async () => {
      await ir(`/caja/cita?id=${cfg.citaCobro2}`);
      let posts = 0; const vistos = [];
      await page.setRequestInterception(true);
      const manejador = async req => {
        if (req.method() === 'POST' && /\/caja\/cita\/cobrar/.test(req.url())) { posts++; vistos.push(req.url()); await esperar(1500); }
        req.continue().catch(() => {});
      };
      page.on('request', manejador);
      try {
        const sel = 'form[action*="/caja/cita/cobrar"]';
        if (!(await page.$(sel))) throw new Error('sin formulario de cobro');
        const metodoN = await page.$eval(sel, f => { const m = [...f.elements].find(e => /metodo|medio|forma/i.test(e.name || '')); return m ? m.name : null; });
        if (metodoN) { const val = await page.$$eval(`${sel} [name="${metodoN}"] option`, o => (o.find(x => /efectivo/i.test(x.value + x.textContent)) || {}).value); if (val) await page.select(`${sel} [name="${metodoN}"]`, val); }
        const rec = await page.$eval(sel, f => { const m = [...f.elements].find(e => /recib|entreg/i.test(e.name || '')); return m ? m.name : null; });
        if (rec) await page.$eval(`${sel} [name="${rec}"]`, e => { e.value = '20.00'; });
        const monto = await page.$eval(sel, f => { const m = [...f.elements].find(e => /monto|importe|valor|cantidad/i.test(e.name || '') && !/recib|cambio|total|saldo/i.test(e.name || '')); return m ? m.name : null; });
        if (monto) await page.$eval(`${sel} [name="${monto}"]`, e => { e.value = '20.00'; });
        const b = await page.$(`${sel} button[type=submit], ${sel} input[type=submit]`);
        const nav = page.waitForNavigation({ waitUntil: 'networkidle0', timeout: 20000 }).catch(() => {});
        await b.click();
        await esperar(250);
        const estado = await page.evaluate(sel => { const b = document.querySelector(sel + ' button[type=submit], ' + sel + ' input[type=submit]'); return b ? { disabled: b.disabled, busy: b.getAttribute('aria-busy'), texto: (b.textContent || b.value).trim(), clase: b.className } : null; }, sel).catch(e => ({ error: String(e.message).slice(0, 120), disabled: false, busy: null, texto: '' }));
        await page.evaluate(sel => { const b = document.querySelector(sel + ' button[type=submit], ' + sel + ' input[type=submit]'); if (b) { b.click(); b.click(); } const f = document.querySelector(sel); if (f && f.requestSubmit) { try { f.requestSubmit(); } catch (e) {} } }, sel).catch(() => {});
        await nav; await esperar(300);
        return { posts, estadoDuranteEnvio: estado };
      } finally { page.off('request', manejador); await page.setRequestInterception(false); }
    });

    await paso('N_agenda_iniciar_boton_en_proceso', async () => {
      await ir(`/agenda?${periodo}`);
      let posts = 0;
      await page.setRequestInterception(true);
      const manejador = async req => { if (req.method() === 'POST' && /\/agenda\/estado/.test(req.url())) { posts++; await esperar(1200); } req.continue().catch(() => {}); };
      page.on('request', manejador);
      try {
        const { btn, card } = await accion(cfg.citaComenzadaTres, 'Iniciar atenci');
        const nav = page.waitForNavigation({ waitUntil: 'networkidle0', timeout: 20000 }).catch(() => {});
        await btn.click();
        await esperar(200);
        const estado = await page.evaluate(c => { const b = [...c.querySelectorAll('button,input[type=submit]')].find(x => /Iniciar|Proces|Enviando|Guardando/i.test(x.textContent || x.value)); return b ? { disabled: b.disabled, busy: b.getAttribute('aria-busy'), texto: (b.textContent || b.value).trim() } : null; }, card).catch(e => ({ error: String(e.message).slice(0, 120), disabled: false, busy: null, texto: '' }));
        await page.evaluate(c => { const b = c.querySelector('button,input[type=submit]'); if (b) { b.click(); b.click(); } }, card).catch(() => {});
        await nav;
        return { posts, estadoDuranteEnvio: estado };
      } finally { page.off('request', manejador); await page.setRequestInterception(false); }
    });

    // ===================================================================== Modales: foco, teclado, Escape y retorno al disparador
    const probarModal = async (ruta, reTrigger, etiqueta) => {
      await ir(ruta);
      const trig = await page.evaluateHandle(FN.control, null, reTrigger);
      if (!trig.asElement()) return { sinDisparador: true };
      await page.evaluate(el => { el.setAttribute('data-q5-disparador', '1'); }, trig.asElement());
      await trig.asElement().focus();
      await page.keyboard.press('Enter');
      await esperar(600);
      const d = await page.evaluate(() => {
        const dlg = document.querySelector('dialog[open], [role=dialog], [role=alertdialog], .swal2-popup, .modal.show, [aria-modal=true]');
        if (!dlg) return null;
        const a = document.activeElement;
        return { dentro: !!(a && dlg.contains(a)), nombre: !!(dlg.getAttribute('aria-labelledby') || dlg.getAttribute('aria-label') || dlg.querySelector('h1,h2,h3,.swal2-title')), modal: dlg.getAttribute('aria-modal') === 'true' || dlg.tagName === 'DIALOG' || dlg.classList.contains('swal2-popup'), rol: dlg.getAttribute('role') || dlg.tagName.toLowerCase(), texto: dlg.innerText.replace(/\s+/g, ' ').slice(0, 200) };
      });
      if (!d) { return { sinModal: true, url: page.url() }; }
      let atrapa = true;
      for (let i = 0; i < 12; i++) { await page.keyboard.press('Tab'); atrapa = atrapa && await page.evaluate(() => { const dlg = document.querySelector('dialog[open], [role=dialog], [role=alertdialog], .swal2-popup, [aria-modal=true]'); return !!dlg && dlg.contains(document.activeElement); }); }
      await page.keyboard.press('Escape');
      await esperar(500);
      const post = await page.evaluate(() => ({ abierto: !!document.querySelector('dialog[open], [role=dialog]:not([hidden]), [role=alertdialog], .swal2-popup, [aria-modal=true]'), retorno: !!(document.activeElement && document.activeElement.hasAttribute('data-q5-disparador')) }));
      return Object.assign(d, { atrapaFoco: atrapa, cierraConEscape: !post.abierto, devuelveFoco: post.retorno, etiqueta });
    };
    await paso('M_modal_registrar_ausencia', async () => probarModal(`/agenda?${periodo}`, 'Registrar ausencia', 'ausencia'));
    await paso('M_modal_listado_o_descarga_crm', async () => probarModal('/admin/clientes', 'Ver clientes|Listado|Descargar', 'crm'));
    await paso('M_modal_devolucion_o_rechazo', async () => probarModal(`/caja/cita?id=${cfg.citaConPago}`, 'Devol|Reembols|Rechazar', 'caja'));

    // ===================================================================== Descargas reales (CSV y Excel) con clic
    const descargar = async (ruta, reLink) => {
      await ir(ruta);
      const antes = new Set(fs.readdirSync(dl));
      const link = await page.evaluateHandle(re => [...document.querySelectorAll('a[href],button')].find(a => new RegExp(re, 'i').test((a.textContent || '').replace(/\s+/g, ' ')) && /exportar|descargar|formato=/i.test((a.getAttribute('href') || '') + (a.textContent || ''))) || null, reLink);
      if (!link.asElement()) return { sinEnlace: true };
      await link.asElement().click();
      for (let i = 0; i < 40; i++) { await esperar(250); const n = fs.readdirSync(dl).filter(f => !antes.has(f) && !f.endsWith('.crdownload')); if (n.length) { const f = path.join(dl, n[0]); const b = fs.readFileSync(f); return { archivo: n[0], bytes: b.length, esZip: b.slice(0, 2).toString() === 'PK', bom: b.slice(0, 3).equals(Buffer.from([0xEF, 0xBB, 0xBF])) }; } }
      return { sinArchivo: true };
    };
    await paso('D_descarga_xlsx_crm', async () => descargar('/admin/clientes', 'Excel|XLSX'));
    await paso('D_descarga_csv_crm', async () => descargar('/admin/clientes', 'CSV'));
    await paso('D_descarga_xlsx_caja', async () => descargar('/caja', 'Excel|XLSX'));

    // ===================================================================== Comprobante en pantalla e impresión
    await paso('P_comprobante_impresion', async () => {
      await ir(`/caja/cita?id=${cfg.citaConPago}`);
      const href = await page.evaluate(() => { const a = [...document.querySelectorAll('a[href]')].find(x => /\/caja\/comprobante/.test(x.getAttribute('href'))); return a ? a.getAttribute('href') : null; });
      if (!href) throw new Error('la pantalla de cobro de una cita pagada no enlaza al comprobante');
      const st = await ir(href);
      const pantalla = await page.evaluate(() => document.body.innerText.replace(/\s+/g, ' '));
      await page.emulateMediaType('print');
      const impresion = await page.evaluate(() => ({ barraLateral: (() => { const s = document.querySelector('.ui-sidebar,aside,nav'); return s ? getComputedStyle(s).display : 'ausente'; })(), leyenda: /Comprobante interno\s*[—–-]\s*no es factura autorizada por el SRI/i.test(document.body.innerText), botones: [...document.querySelectorAll('button,.boton')].filter(b => getComputedStyle(b).display !== 'none' && b.getBoundingClientRect().height > 0).length }));
      const pdf = await page.pdf({ format: 'A4', printBackground: true });
      await page.emulateMediaType('screen');
      return { status: st, leyendaPantalla: /Comprobante interno\s*[—–-]\s*no es factura autorizada por el SRI/i.test(pantalla), impresion, pdfBytes: pdf.length, pdfValido: pdf.slice(0, 4).toString() === '%PDF' };
    });

    // ===================================================================== Teclado: foco visible y activación con Enter
    await paso('K_teclado_agenda_y_caja', async () => {
      const res = [];
      for (const ruta of [`/agenda?${periodo}`, `/caja/cita?id=${cfg.citaCobro}`, '/admin/clientes']) {
        await ir(ruta);
        const seq = [];
        for (let i = 0; i < 40; i++) {
          await page.keyboard.press('Tab');
          const e = await page.evaluate(() => { const a = document.activeElement; if (!a || a === document.body) return null; const s = getComputedStyle(a); const b = a.getBoundingClientRect();
            return { tag: a.tagName.toLowerCase(), id: a.id, nombre: (a.textContent || a.value || a.name || '').replace(/\s+/g, ' ').trim().slice(0, 30), contorno: s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) > 0, sombra: s.boxShadow !== 'none', visible: b.width > 0 && b.height > 0 }; });
          if (e) seq.push(e);
        }
        res.push({ ruta, total: seq.length, sinIndicador: seq.filter(s => ['input', 'a', 'button', 'select', 'summary', 'textarea'].includes(s.tag) && !s.contorno && !s.sombra).map(s => s.tag + ':' + s.nombre) });
      }
      return { pantallas: res };
    });

    // ===================================================================== Responsive y consola
    await paso('R_sin_desbordes_ni_errores', async () => {
      const malos = [];
      const rutas = [`/agenda?${periodo}`, `/agenda/cita?id=${cfg.citaConPago}`, '/caja', `/caja/cita?id=${cfg.citaConPago}`, '/admin/clientes?membresia=activo', '/admin/clientes'];
      for (const ruta of rutas) {
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

    // ===================================================================== Portales con navegador: el cliente no ve el equipo y viceversa
    await paso('S_sesion_de_equipo_en_mis_citas', async () => {
      await ir('/mis-citas');
      const u = url();
      return { destino: u.pathname, reservar: /Reservar una cita/i.test(await page.evaluate(() => document.body.innerText)), aviso: await page.$$eval('.alerta,[role=alert],[role=status]', a => a.map(x => x.innerText.trim()).filter(Boolean)) };
    });
    await cerrarSesion();
    await paso('S_cliente_en_agenda', async () => {
      await login(cfg.cliente.email, cfg.cliente.password, 'cliente');
      const st = await ir(`/agenda?${periodo}`);
      const info = await page.evaluate(() => ({ hrefs: [...document.querySelectorAll('a[href]')].map(a => a.getAttribute('href')), texto: document.body.innerText.replace(/\s+/g, ' ').slice(0, 300) }));
      const volver = info.hrefs.filter(h => /^\/(agenda|admin|caja)/.test(h));
      return { status: st, volverAEquipo: volver, tieneCita: info.hrefs.includes('/cita'), texto: info.texto };
    });
  } catch (e) {
    out.error = String(e && e.stack || e);
  } finally {
    await browser.close().catch(() => {});
    fs.rmSync(dl, { recursive: true, force: true });
  }
  console.log(JSON.stringify(out));
})();

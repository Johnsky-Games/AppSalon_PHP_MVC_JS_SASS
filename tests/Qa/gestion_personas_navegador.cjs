const puppeteer = require('puppeteer-core');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const cfg = JSON.parse(process.env.QA_CONFIG);

(async () => {
  const browser = await puppeteer.launch({executablePath: process.env.CHROME_BIN, headless: true, args: ['--no-sandbox', '--disable-setuid-sandbox']});
  const out = {errores: [], consola: [], responsive: []};
  try {
    const page = await browser.newPage();
    page.on('pageerror', e => out.consola.push(e.message));
    page.on('console', m => { if (m.type() === 'error') out.consola.push(m.text()); });
    const ir = async ruta => {
      const r = await page.goto(cfg.base + ruta, {waitUntil: 'networkidle0'});
      assert.equal(r.status(), 200, ruta);
    };
    const enviar = async selector => Promise.all([page.waitForNavigation({waitUntil: 'networkidle0'}), page.click(selector)]);
    const horas = async (grupo, inicio, fin) => page.evaluate((selector, a, b) => {
      const raiz = document.querySelector(selector);
      raiz.querySelector('[data-grupo-inicio]').value = a;
      raiz.querySelector('[data-grupo-fin]').value = b;
    }, grupo, inicio, fin);
    await page.setViewport({width: 1366, height: 950});
    await ir('/equipo/login'); await page.type('#email', cfg.owner.email); await page.type('#password', cfg.owner.password);
    await enviar('form input[type=submit], form button[type=submit]');
    await ir('/admin/clientes');
    assert.equal(await page.$('main #crm-ind-titulo'), null, 'La base incluye el panel de indicadores');
    assert.ok(await page.$('.crm-tabla-clientes--compacta'));
    const tabla = await page.$eval('.crm-tabla-clientes tbody', e => e.textContent);
    assert.ok(!tabla.includes(cfg.owner.email), 'El propietario aparece entre clientes externos');
    assert.ok(tabla.includes(cfg.cliente.email));
    await ir('/admin/clientes/indicadores'); assert.ok(await page.$('#crm-ind-titulo')); assert.equal(await page.$('.crm-tabla-clientes'), null);
    await ir('/admin/recursos-humanos'); assert.ok((await page.$eval('.rh-tabla', e => e.textContent)).includes(cfg.owner.email));
    await ir('/admin/recursos-humanos/ficha?id=' + cfg.profesional.colaborador_id);
    const selector = tipo => `[data-agenda-asistente] [data-grupo-dias][data-tipo="${tipo}"]`;
    await page.click(selector('horario') + ' [data-dias-preset="2,3,4,5,6"]');
    await horas(selector('horario'), '09:00', '18:00'); await page.click(selector('horario') + ' [data-ag-aplicar-grupo]');
    await page.click(selector('descanso') + ' [data-dias-preset="2,3,4,5,6"]');
    await horas(selector('descanso'), '14:00', '15:00'); await page.click(selector('descanso') + ' [data-ag-aplicar-grupo]');
    await enviar('form[action*="/ficha/agenda"] button[type=submit]');
    assert.equal(await page.$eval('input[name="franjas[6][0][inicio]"]', e => e.value), '09:00');
    assert.equal(await page.$eval('input[name="descansos[6][0][fin]"]', e => e.value), '15:00');
    await ir('/profesionales/horarios?id=' + cfg.profesional.profesional_id);
    await enviar('[data-grupo-dias][data-tipo="horario"] button[type=submit]');
    await enviar('[data-grupo-dias][data-tipo="descanso"] button[type=submit]');
    await page.setJavaScriptEnabled(false);
    await ir('/profesionales/horarios?id=' + cfg.profesional.profesional_id);
    for (const d of [1,2,3,4,5]) await page.click('#jornada-dia-' + d);
    await page.click('#jornada-dia-7'); await horas('[data-grupo-dias][data-tipo="horario"]', '11:00', '16:00');
    await enviar('[data-grupo-dias][data-tipo="horario"] button[type=submit]');
    assert.equal(await page.$eval('#horario_inicio_7', e => e.value), '11:00');
    await page.setJavaScriptEnabled(true);
    const rutas = ['/admin/clientes', '/admin/clientes/indicadores', '/admin/recursos-humanos', '/admin/recursos-humanos/ficha?id=' + cfg.profesional.colaborador_id,
      '/profesionales/horarios?id=' + cfg.profesional.profesional_id, '/admin/equipo'];
    for (const ancho of [320,390,1366]) {
      await page.setViewport({width: ancho, height: 950});
      for (const ruta of rutas) {
        await ir(ruta);
        const medida = await page.evaluate(() => ({scroll: document.documentElement.scrollWidth, ancho: document.documentElement.clientWidth}));
        out.responsive.push({ruta, ancho, ...medida, desborda: medida.scroll > medida.ancho + 1});
        if (cfg.capturas && ['/admin/clientes','/admin/clientes/indicadores','/admin/recursos-humanos'].includes(ruta) && ancho !== 320) {
          fs.mkdirSync(cfg.capturas, {recursive: true});
          await page.screenshot({path: path.join(cfg.capturas, ruta.replaceAll('/', '_') + '_' + ancho + '.png'), fullPage: true});
        }
      }
    }
  } catch (e) { out.errores.push(e.stack); }
  finally { await browser.close(); }
  console.log(JSON.stringify(out));
})().catch(e => { console.error(e.stack); process.exitCode = 1; });

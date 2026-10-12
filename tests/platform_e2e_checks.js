'use strict';
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');

module.exports = async function verifyPlatform({ browser, baseUrl, recordTest, screenshotDir }) {
    const db = process.env.TEST_DB_CONTAINER;
    const name = process.env.TEST_DB_NAME;
    if (!db || !name || !name.endsWith('_test')) throw new Error('Platform E2E requires an explicitly isolated test database.');
    const query = sql => execFileSync('docker', ['exec', db, 'mysql', '-uroot', '-proot', '--default-character-set=utf8mb4', '-N', '-e', sql, name], { encoding: 'utf8', stdio: ['ignore','pipe','pipe'] }).trim();
    query("INSERT INTO usuarios(id,nombre,apellido,email,password,telefono,admin,confirmado) SELECT 1001,'Recepcion','E2E','recepcion@platform.test',password,'0991234567',0,1 FROM usuarios WHERE email='admin@appsalon.com' ON DUPLICATE KEY UPDATE confirmado=1; INSERT INTO usuario_accesos(usuario_id,rol,activo) VALUES(1001,'recepcion',1) ON DUPLICATE KEY UPDATE rol='recepcion',activo=1; INSERT INTO usuarios(id,nombre,apellido,email,password,telefono,admin,confirmado) SELECT 1003,'Cliente','Ajeno','ajeno@platform.test',password,'0991234567',0,1 FROM usuarios WHERE email='admin@appsalon.com' ON DUPLICATE KEY UPDATE confirmado=1; INSERT INTO usuario_accesos(usuario_id,rol,activo) VALUES(1003,'cliente',1) ON DUPLICATE KEY UPDATE rol='cliente',activo=1;");
    const contexts = [];
    const errors = [];
    async function login(email) {
        const context = await browser.createBrowserContext(); contexts.push(context);
        const page = await context.newPage();
        page.on('pageerror', error => errors.push(error.message));
        page.on('requestfailed', request => errors.push(`${request.url()}: ${request.failure()?.errorText}`));
        await page.goto(`${baseUrl}/`, { waitUntil: 'networkidle0' });
        await page.type('input[name="email"]', email);
        await page.type('input[name="password"]', 'Password123!');
        await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }),page.click('input[type="submit"]')]);
        if (new URL(page.url()).pathname === '/') throw new Error(`Platform E2E login failed for ${email}`);
        return page;
    }
    async function fill(page, selector, values) {
        await page.$eval(selector, (form, data) => {
            for (const [key,value] of Object.entries(data)) {
                const input = form.elements.namedItem(key);
                if (!input) throw new Error(`Missing form field ${key}`);
                if (input.type === 'checkbox') input.checked = value === '1'; else input.value = value;
                input.dispatchEvent(new Event('input',{bubbles:true})); input.dispatchEvent(new Event('change',{bubbles:true}));
            }
        }, values);
    }
    async function submit(page, selector) {
        await Promise.all([page.waitForNavigation({ waitUntil:'networkidle0' }),page.click(`${selector} button[type="submit"], ${selector} button:not([type])`)]);
    }
    async function responsive(page, label) {
        for (const width of [320,390,768,1280]) {
            await page.setViewport({width,height:900});
            const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth+1);
            if (overflow) throw new Error(`Platform horizontal overflow: ${label} at ${width}px`);
            await page.screenshot({path:path.join(screenshotDir, `platform-${label}-${width}.png`)});
        }
        await page.setViewport({width:1280,height:900});
    }
    try {
        const owner = await login('admin@appsalon.com');
        for (const [route,label] of [['/admin/equipo','team'],['/admin/inventario','stock'],['/admin/finanzas','cash'],['/admin/crecimiento','growth'],['/admin/ajustes','settings']]) {
            const response = await owner.goto(baseUrl+route,{waitUntil:'networkidle0'});
            if (response.status() !== 200) throw new Error(`Owner route ${route}: HTTP${response.status()}`);
            await responsive(owner,label);
        }
        recordTest('PLAT-01','Módulos administrativos reales y responsive',true,'Equipo, inventario, caja, crecimiento y ajustes: HTTP200, cuatro anchos por módulo.');

        await owner.goto(`${baseUrl}/admin/inventario`,{waitUntil:'networkidle0'});
        await owner.click('[data-modal-open="producto-nuevo"]');
        await owner.waitForFunction(() => document.querySelector('#producto-nuevo').open);
        const sku = `E2E_${crypto.randomBytes(8).toString('hex')}`;
        await fill(owner,'#producto-nuevo form',{sku,nombre:'Producto prueba E2E',unidad:'unidad',minimo:'1',costo:'2.50'});
        await submit(owner,'#producto-nuevo form');
        const product = query(`SELECT id FROM inventario_productos WHERE sku='${sku}'`);
        if (!/^\d+$/.test(product)) throw new Error('Inventory product did not persist.');
        await owner.click('[data-modal-open="stock-movimiento"]');
        await owner.waitForFunction(() => document.querySelector('#stock-movimiento').open);
        await fill(owner,'#stock-movimiento form',{producto_id:product,tipo:'entrada',cantidad:'2.500',motivo:'Compra verificada E2E'});
        await submit(owner,'#stock-movimiento form');
        if (query(`SELECT stock_milesimas FROM inventario_productos WHERE id=${product}`) !== '2500') throw new Error('Stock entry must persist exact milliunits.');
        await owner.click('[data-modal-open="stock-movimiento"]');
        await owner.waitForFunction(() => document.querySelector('#stock-movimiento').open);
        await fill(owner,'#stock-movimiento form',{producto_id:product,tipo:'salida',cantidad:'3',motivo:'No puede superar existencias'});
        await submit(owner,'#stock-movimiento form');
        if (query(`SELECT stock_milesimas FROM inventario_productos WHERE id=${product}`) !== '2500' || !await owner.$('.alerta.error')) throw new Error('Overdrawn stock must reject without changing balance and show an error.');
        recordTest('PLAT-02','Modales de inventario, precisión y rechazo de stock negativo',true,'Producto y entrada 2.500 guardados en MySQL; salida3 rechazada y saldo2.500 intacto.');

        await owner.goto(`${baseUrl}/admin/finanzas`,{waitUntil:'networkidle0'});
        await owner.click('[data-modal-open="movimiento-caja"]');
        await owner.waitForFunction(() => document.querySelector('#movimiento-caja').open);
        const concept = `Caja E2E ${crypto.randomBytes(8).toString('hex')}`;
        await fill(owner,'#movimiento-caja form',{tipo:'ingreso',metodo:'efectivo',concepto:concept,monto:'12.34'});
        await submit(owner,'#movimiento-caja form');
        if (query(`SELECT monto_centavos FROM caja_movimientos WHERE concepto='${concept}'`) !== '1234') throw new Error('Cash entry must persist exact cents.');
        recordTest('PLAT-03','Registro manual de caja desde modal',true,'Recepción declarada de12.34 persistida como1234centavos; ninguna transferencia bancaria ejecutada.');

        const client = await login('carlos@correo.com');
        let response = await client.goto(`${baseUrl}/perfil`,{waitUntil:'networkidle0'});
        if (response.status() !== 200) throw new Error('Client profile unavailable.');
        await responsive(client,'profile');
        const profileId = query("SELECT id FROM usuarios WHERE email='carlos@correo.com'");
        await fill(client,'.ui-profile-card form.formulario',{marketing_email:'1'});
        await submit(client,'.ui-profile-card form.formulario');
        if (query(`SELECT marketing_email FROM perfiles_usuario WHERE usuarioId=${profileId}`) !== '1') throw new Error('Opt-in did not persist.');
        await fill(client,'.ui-profile-card form.formulario',{marketing_email:'0'});
        await submit(client,'.ui-profile-card form.formulario');
        if (query(`SELECT marketing_email FROM perfiles_usuario WHERE usuarioId=${profileId}`) !== '0') throw new Error('Opt-out did not persist.');
        await client.click('#abrir-foto');
        await client.waitForFunction(() => document.querySelector('#foto-modal').open);
        const png = await client.evaluate(() => { const c=document.createElement('canvas');c.width=2;c.height=2;c.getContext('2d').fillRect(0,0,2,2);return c.toDataURL('image/png').split(',')[1]; });
        const photoFile=path.join(screenshotDir,'profile-e2e.png'); fs.writeFileSync(photoFile,Buffer.from(png,'base64'));
        const input = await client.$('#foto-archivo'); await input.uploadFile(photoFile);
        await submit(client,'#foto-modal form');
        response = await client.goto(`${baseUrl}/perfil/foto?id=${profileId}`);
        const bytes = await response.buffer();
        if (response.status() !== 200 || !response.headers()['content-type'].startsWith('image/jpeg') || bytes[0] !== 255 || bytes[1] !== 216) throw new Error('Uploaded PNG must be decoded and reencoded as JPEG.');
        const other = await login('ajeno@platform.test');
        // Chrome can turn an empty 404 document into ERR_HTTP_RESPONSE_CODE_FAILURE.
        // Fetch checks the real authenticated HTTP denial without navigating to an error page.
        const deniedPhoto = await other.evaluate(async url => {
            const response = await fetch(url, { credentials: 'same-origin' });
            return { status: response.status, size: (await response.arrayBuffer()).byteLength };
        }, `${baseUrl}/perfil/foto?id=${profileId}`);
        if (deniedPhoto.status !== 404 || deniedPhoto.size !== 0) throw new Error('Another client must not receive a private photo or its content.');
        response = await other.goto(`${baseUrl}/perfil?id=${profileId}`);
        if (response.status() !== 403) throw new Error('Another client must not read private profile.');
        response = await other.goto(`${baseUrl}/admin/equipo`);
        if (response.status() !== 403) throw new Error('Client must not access team management.');
        recordTest('PLAT-04','Perfil, consentimiento, upload real y privacidad entre clientes',true,'Opt-in/out en fuente única; PNG HTTP reencodificado JPEG; fotoajena404/perfilajeno403/equipo403.');

        await owner.goto(`${baseUrl}/admin/ajustes`,{waitUntil:'networkidle0'});
        const businessName=`Salón E2E ${crypto.randomBytes(6).toString('hex')}`;
        await fill(owner,'.growth-card form',{nombre_comercial:businessName,contacto:'Recepción E2E',beneficio_fidelidad:'Beneficio de prueba en recepción'});
        await submit(owner,'.growth-card form');
        if (query("SELECT valor FROM negocio_ajustes WHERE clave='nombre_comercial'") !== businessName) throw new Error('Business settings POST must persist with the rendered CSRF field.');
        await owner.goto(`${baseUrl}/admin/crecimiento`,{waitUntil:'networkidle0'});
        await owner.click('[data-modal-open="formulario-modal"]');
        await owner.waitForFunction(() => document.querySelector('#formulario-modal').open);
        const formTitle=`Ficha E2E ${crypto.randomBytes(6).toString('hex')}`;
        await fill(owner,'#formulario-modal form',{titulo:formTitle,'preguntas[0][etiqueta]':'Preferencia de atención','preguntas[0][tipo]':'texto','preguntas[0][requerida]':'1'});
        await submit(owner,'#formulario-modal form');
        const formId=query(`SELECT id FROM formularios_cliente WHERE titulo='${formTitle}'`);
        if (!/^\d+$/.test(formId)) throw new Error('Published form must persist from the modal.');
        await client.goto(`${baseUrl}/mi-bienestar`,{waitUntil:'networkidle0'});
        await responsive(client,'wellness');
        const answer='<img src=x onerror="window.__platformXss=true">Preferencia literal';
        const answerForm=`form:has(input[name="formulario_id"][value="${formId}"])`;
        await client.$eval(answerForm,form => form.closest('details').open=true);
        await fill(client,answerForm,{'respuestas[p0]':answer});
        await submit(client,answerForm);
        const stored=query(`SELECT JSON_UNQUOTE(JSON_EXTRACT(respuestas,'$.p0')) FROM formulario_respuestas WHERE formulario_id=${formId} AND usuario_id=${profileId}`);
        if (stored !== answer) throw new Error('Client answer POST must preserve literal content in private SQL storage.');
        if (await client.evaluate(() => window.__platformXss === true || !!document.querySelector('img[src="x"]'))) throw new Error('Client form response was interpreted as HTML.');
        await owner.goto(`${baseUrl}/admin/crecimiento?formulario_id=${formId}`,{waitUntil:'networkidle0'});
        if (!await owner.evaluate(value => document.body.textContent.includes(value) && window.__platformXss !== true && !document.querySelector('img[src="x"]'),answer)) throw new Error('Authorized response rendering must remain escaped.');
        await other.goto(`${baseUrl}/mi-bienestar`,{waitUntil:'networkidle0'});
        if (await other.evaluate(value => document.body.textContent.includes(value),answer)) throw new Error('Another client must not see the saved private answer.');
        recordTest('PLAT-06','Ajustes y formularios: modales, POST con CSRF, persistencia y escaping',true,'Nombre comercial guardado; ficha publicada; cliente responde; respuesta literal SQL y admin escapada, otro cliente no accede.');

        const reception = await login('recepcion@platform.test');
        response = await reception.goto(`${baseUrl}/agenda`,{waitUntil:'networkidle0'});
        if (response.status() !== 200) throw new Error('Reception agenda must be permitted.');
        for (const route of ['/admin/equipo','/admin/finanzas','/admin/inventario','/admin/crecimiento','/admin/ajustes']) {
            response = await reception.goto(baseUrl+route);
            if (response.status() !== 403) throw new Error(`Reception forbidden route ${route} returned ${response.status()}`);
        }
        await owner.goto(`${baseUrl}/admin/equipo`,{waitUntil:'networkidle0'});
        const selector='form[action="/admin/equipo/actualizar"]:has(input[name="id"][value="1001"])';
        await fill(owner,selector,{activo:'0'});
        await submit(owner,selector);
        response = await reception.goto(`${baseUrl}/agenda`,{waitUntil:'networkidle0'});
        if (new URL(reception.url()).pathname !== '/') throw new Error('Revoked reception session must lose access on next request.');
        if (errors.length) throw new Error(`Platform page/runtime/network failures: ${errors.join('; ')}`);
        recordTest('PLAT-05','Matriz negativa y revocación en siguiente petición',true,'Recepción agenda200; equipo/caja/stock/crecimiento/ajustes403; propietario desactiva cuenta y sesión previa pierde acceso.');
    } finally { for (const context of contexts) await context.close(); }
};

// Comprueba el estado que ve el cliente cuando falla la carga inicial del catálogo.
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../src/js/app.js'), 'utf8');
async function checkFailure(fetch) {
    const attributes = { 'aria-busy': 'true' };
    const catalog = { textContent: 'Cargando el catálogo de servicios…', setAttribute(key, value) { attributes[key] = value; } };
    const context = vm.createContext({
        fetch, location: { origin: 'http://appsalon.test' },
        document: { addEventListener() {}, querySelector(selector) { return selector === '#servicios' ? catalog : null; } }
    });
    vm.runInContext(source, context);
    await vm.runInContext('consultarAPI()', context);
    assert.equal(attributes['aria-busy'], 'false', 'Un fallo no debe dejar el catálogo cargando indefinidamente');
    assert.match(catalog.textContent, /No se pudo cargar.*Recarga/, 'El cliente necesita un mensaje y una forma de reintentar');
}
(async () => {
    await checkFailure(async () => { throw new Error('Network unavailable'); });
    await checkFailure(async () => ({ ok: false, status: 500 }));
    await checkFailure(async () => ({ ok: true, json: async () => ({ unexpected: true }) }));
    console.log('UI_CLIENT_FAILURE: 3 escenarios aprobados (red, HTTP 500 y JSON incompatible)');
})().catch(error => { console.error(error); process.exitCode = 1; });

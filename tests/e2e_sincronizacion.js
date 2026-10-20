'use strict';
/**
 * Sincronización y diagnóstico del recorrido de navegador. Sustituye pausas fijas y esperas «a ver si ya terminó» por condiciones
 * observables de la propia interfaz, y deja evidencia útil (sin datos sensibles) cuando una espera falla.
 */
const { execFileSync } = require('child_process');

/** Registro de la actividad /api/* de la página: método, ruta sin parámetros sensibles, estado y duración. Nunca guarda cabeceras, cookies ni cuerpos. */
function registrarRed(page, limite = 60) {
    const eventos = [];
    const inicio = Date.now();
    const limpia = url => {
        try {
            const u = new URL(url);
            const q = [...u.searchParams.entries()].filter(([k]) => !/csrf|token|password|clave|secret/i.test(k)).map(([k, v]) => `${k}=${v}`).join('&');
            return u.pathname + (q ? `?${q}` : '');
        } catch { return '(url no válida)'; }
    };
    const pendientes = new Map();
    page.on('request', req => {
        if (!req.url().includes('/api/')) return;
        const e = { t: Date.now() - inicio, m: req.method(), ruta: limpia(req.url()), estado: 'en vuelo' };
        pendientes.set(req, e); eventos.push(e); if (eventos.length > limite) eventos.shift();
    });
    page.on('response', res => {
        const e = pendientes.get(res.request()); if (e) { e.estado = res.status(); e.ms = Date.now() - inicio - e.t; pendientes.delete(res.request()); }
    });
    page.on('requestfailed', req => {
        const e = pendientes.get(req); if (e) { e.estado = `falló: ${req.failure()?.errorText || 'sin detalle'}`; pendientes.delete(req); }
    });
    return { eventos, enVuelo: () => [...pendientes.values()] };
}

/** Espera a que la consulta de disponibilidad haya terminado y la interfaz esté estable: estado final, lista sin «busy» y alto del documento sin cambios entre fotogramas. */
async function esperarDisponibilidadEstable(page, { timeout = 15000 } = {}) {
    await page.waitForFunction(() => {
        const estado = document.querySelector('#estado-disponibilidad')?.dataset.estado;
        const lista = document.querySelector('#contenedor-horarios-disponibles');
        return ['ok', 'empty', 'error', 'idle'].includes(estado) && lista && lista.getAttribute('aria-busy') !== 'true';
    }, { timeout });
    await esperarLayoutEstable(page, { timeout });
}

/** Espera a que la geometría deje de moverse: el alto del documento y la posición del elemento coinciden en tres fotogramas seguidos. */
async function esperarLayoutEstable(page, { selector = '#siguiente', timeout = 10000 } = {}) {
    await page.waitForFunction(sel => {
        const estado = window.__e2eLayout ||= { previo: '', iguales: 0 };
        const el = document.querySelector(sel);
        const r = el ? el.getBoundingClientRect() : { x: 0, y: 0 };
        const huella = [document.documentElement.scrollHeight, Math.round(scrollY), Math.round(r.x), Math.round(r.y)].join('|');
        estado.iguales = huella === estado.previo ? estado.iguales + 1 : 0;
        estado.previo = huella;
        return estado.iguales >= 3;
    }, { polling: 'raf', timeout }, selector);
    await page.evaluate(() => { delete window.__e2eLayout; });
}

/** Pulsa un elemento solo cuando la página está estable, y comprueba que lo que recibirá el clic es el propio elemento. */
async function clicEstable(page, selector, opciones = {}) {
    await esperarLayoutEstable(page, { selector, ...opciones });
    await page.$eval(selector, el => el.scrollIntoView({ block: 'center' }));
    await esperarLayoutEstable(page, { selector, ...opciones });
    const receptor = await page.$eval(selector, el => {
        const r = el.getBoundingClientRect();
        const e = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
        return e === el || el.contains(e) ? null : `${e?.tagName || 'nada'}#${e?.id || ''}.${e?.className || ''}`;
    });
    if (receptor) throw new Error(`El clic sobre ${selector} lo recibiría otro elemento (${receptor}).`);
    await page.click(selector);
}

/** Estado de la interfaz y de la red relevante para un fallo de la reserva. No incluye cookies, tokens, valores de formularios ni HTML. */
async function diagnosticoReserva(page, red) {
    const ui = await page.evaluate(() => {
        const sig = document.querySelector('#siguiente');
        const r = sig ? sig.getBoundingClientRect() : null;
        const bajo = r ? document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2) : null;
        return {
            ruta: location.pathname,
            pasos: [...document.querySelectorAll('[id^="paso-"]')].map(el => ({ id: el.id, mostrar: el.classList.contains('mostrar') })),
            disponibilidad: document.querySelector('#estado-disponibilidad')?.dataset.estado,
            listaOcupada: document.querySelector('#contenedor-horarios-disponibles')?.getAttribute('aria-busy') === 'true',
            intervalos: document.querySelectorAll('#contenedor-horarios-disponibles .intervalo-btn').length,
            intervalosMarcados: document.querySelectorAll('#contenedor-horarios-disponibles .intervalo-btn[aria-pressed="true"]').length,
            hayHoraElegida: Boolean(document.querySelector('#hora')?.value),
            siguiente: r ? { x: Math.round(r.x), y: Math.round(r.y), visible: !document.querySelector('#siguiente').classList.contains('ocultar') } : null,
            bajoSiguiente: bajo ? `${bajo.tagName}#${bajo.id || ''}.${String(bajo.className).slice(0, 60)}` : null,
            geometria: { altoDocumento: document.documentElement.scrollHeight, scrollY: Math.round(scrollY), viewport: [innerWidth, innerHeight] },
            alertas: [...document.querySelectorAll('.alerta')].map(el => el.textContent.slice(0, 120))
        };
    }).catch(e => ({ error: `no se pudo leer la interfaz: ${e.message}` }));
    return { ui, red: red ? { ultimas: red.eventos.slice(-15), enVuelo: red.enVuelo() } : null };
}

/**
 * Abre Chrome distinguiendo un fallo de ARRANQUE (el navegador no llegó a existir) de uno de prueba: deja una línea con etiqueta propia y la versión del binario,
 * y reintenta el arranque una vez con un perfil nuevo. El reintento nunca repite ninguna prueba.
 */
async function abrirNavegador(puppeteer, opciones, intentos = 2) {
    let ultimo;
    for (let i = 1; i <= intentos; i++) {
        try {
            const browser = await puppeteer.launch(opciones);
            browser.__intentosArranque = i;
            return browser;
        } catch (error) {
            ultimo = error;
            let version = 'desconocida';
            try { version = execFileSync(opciones.executablePath, ['--version'], { encoding: 'utf8', timeout: 5000, stdio: ['ignore', 'pipe', 'ignore'] }).trim(); } catch { /* binario no ejecutable */ }
            console.error(`E2E_FALLO_ARRANQUE_CHROME intento ${i}/${intentos}: ${error.message.split('\n')[0]} | binario=${opciones.executablePath} | versión=${version}`);
        }
    }
    const error = new Error(`E2E_FALLO_ARRANQUE_CHROME: Chrome no arrancó tras ${intentos} intentos (${ultimo.message.split('\n')[0]}). No se ejecutó ninguna prueba.`);
    error.arranque = true;
    throw error;
}

module.exports = { registrarRed, esperarDisponibilidadEstable, esperarLayoutEstable, clicEstable, diagnosticoReserva, abrirNavegador };

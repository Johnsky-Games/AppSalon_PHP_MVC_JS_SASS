'use strict';

const assert = require('node:assert/strict');

// Solo consultas y solicitudes rechazadas antes de mutar datos; usa el servidor E2E existente.
const baseUrl = (process.env.APP_URL || '').replace(/\/$/, '');
if (!baseUrl) throw new Error('APP_URL es obligatorio para verificar el transporte HTTP real.');

async function request(path, options = {}) {
    const response = await fetch(`${baseUrl}${path}`, {
        redirect: 'manual',
        signal: AbortSignal.timeout(20000),
        ...options,
        headers: { Accept: 'text/html', ...options.headers },
    });
    const body = await response.text();
    assert.equal(response.headers.get('x-content-type-options'), 'nosniff');
    assert.doesNotMatch(body, /(?:Fatal error|Stack trace|SQLSTATE|DB_PASS|mysqli_connect_error)/i);
    return { response, body };
}

(async () => {
    const missing = await request('/pagina-que-no-existe-responsividad');
    assert.equal(missing.response.status, 404);
    assert.match(missing.response.headers.get('content-type'), /^text\/html; charset=utf-8/i);
    assert.match(missing.body, /<main id="contenido-principal"/);
    assert.match(missing.body, /No encontramos esta página/);
    assert.doesNotMatch(missing.body, /ui-auth-hero/);
    assert.equal(missing.response.headers.get('cache-control'), 'no-store');

    for (const [path, method, expectedAllow] of [
        ['/servicios/crear', 'PUT', 'GET, HEAD, POST'],
        ['/servicios/eliminar', 'GET', 'POST'],
        ['/api/citas', 'DELETE', 'POST'],
    ]) {
        const result = await request(path, { method });
        assert.equal(result.response.status, 405, `${method} ${path}`);
        assert.equal(result.response.headers.get('allow'), expectedAllow);
        if (path.startsWith('/api/')) {
            assert.match(result.response.headers.get('content-type'), /^application\/json/);
            assert.equal(JSON.parse(result.body).resultado, false);
        }
    }

    for (const [accept, expectedJson] of [
        ['application/json', true],
        ['application/problem+json', true],
        ['text/html, application/json;q=0.5', false],
        ['application/json;q=0, text/html', false],
    ]) {
        const result = await request('/pagina-que-no-existe-responsividad', { headers: { Accept: accept } });
        assert.equal(result.response.status, 404);
        assert.match(result.response.headers.get('vary') || '', /Accept/i);
        if (expectedJson) {
            assert.match(result.response.headers.get('content-type'), /^application\/json/);
            assert.equal(JSON.parse(result.body).resultado, false);
        } else {
            assert.match(result.response.headers.get('content-type'), /^text\/html/);
            assert.match(result.body, /ui-error-card/);
        }
    }
    const api = await request('/api/ruta-que-no-existe', { headers: { Accept: 'text/html' } });
    assert.equal(api.response.status, 404);
    assert.match(api.response.headers.get('content-type'), /^application\/json/);
    assert.deepEqual(Object.keys(JSON.parse(api.body)).sort(), ['error', 'resultado']);

    for (const [path, status] of [
        ['/pagina-que-no-existe-responsividad', 404],
        ['/confirmar-cuenta?token=enlace-invalido-de-prueba', 410],
        ['/recuperar?token=enlace-invalido-de-prueba', 410],
    ]) {
        const head = await request(path, { method: 'HEAD' });
        assert.equal(head.response.status, status, `HEAD ${path}`);
        assert.equal(head.body, '');
        const get = await request(path);
        assert.equal(get.response.status, status, `GET ${path}`);
        if (status === 410) assert.match(get.body, /Solicitar (?:otro|un enlace nuevo)/);
    }

    const csrfHtml = await request('/crear-cuenta', { method: 'POST', body: '' });
    assert.equal(csrfHtml.response.status, 403);
    assert.match(csrfHtml.body, /ui-error-card/);
    const csrfJson = await request('/crear-cuenta', { method: 'POST', body: '', headers: { Accept: 'application/json' } });
    assert.equal(csrfJson.response.status, 403);
    assert.match(csrfJson.response.headers.get('content-type'), /^application\/json/);
    assert.deepEqual(JSON.parse(csrfJson.body), { resultado: false, error: 'Token CSRF inválido o ausente' });

    const unauthenticated = await request('/api/citas', { method: 'POST', body: '' });
    assert.equal(unauthenticated.response.status, 401);
    assert.match(unauthenticated.response.headers.get('content-type'), /^application\/json/);
    assert.equal(JSON.parse(unauthenticated.body).resultado, false);
    console.log('ERROR_HTTP_CHECKS_OK: 404, 405/Allow, HEAD, tokens 410, negociación JSON, CSRF 403 y API 401.');
})().catch(error => {
    console.error(error.stack || error.message);
    process.exitCode = 1;
});

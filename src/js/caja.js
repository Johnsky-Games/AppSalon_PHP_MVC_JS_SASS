// Caja: muestra solo los campos del método elegido y calcula el cambio del efectivo (mejora progresiva: sin JS ambos campos siguen visibles).
document.querySelectorAll('form[data-caja-cobro]').forEach(form => {
    const aCentavos = valor => {
        const t = String(valor || '').trim();
        if (!/^\d{1,7}(\.\d{1,2})?$/.test(t)) return null;
        const [e, d = ''] = t.split('.');
        return Number(e) * 100 + Number(d.padEnd(2, '0'));
    };
    const dinero = c => '$' + (Math.floor(c / 100)).toLocaleString('en-US') + '.' + String(c % 100).padStart(2, '0');
    const monto = form.querySelector('[name="monto"]');
    const recibido = form.querySelector('[name="recibido"]');
    const cambio = form.querySelector('[data-cambio]');
    const campos = form.querySelectorAll('[data-metodo-campo]');
    const metodo = () => (form.querySelector('[name="metodo"]:checked') || {}).value || 'efectivo';
    const actualizar = () => {
        campos.forEach(c => {
            const activo = c.dataset.metodoCampo === metodo();
            c.hidden = !activo;
            c.querySelectorAll('input').forEach(i => { i.disabled = !activo; });
        });
        const m = aCentavos(monto && monto.value);
        const r = recibido && recibido.value.trim() !== '' ? aCentavos(recibido.value) : m;
        if (cambio) cambio.textContent = m !== null && r !== null && r >= m ? dinero(r - m) : (r !== null && m !== null ? 'falta ' + dinero(m - r) : '$0.00');
        const ref = form.querySelector('[name="referencia"]');
        if (ref) ref.required = metodo() === 'transferencia';
    };
    form.addEventListener('input', actualizar);
    form.addEventListener('change', actualizar);
    actualizar();
});

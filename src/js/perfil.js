(() => {
    const dialog = document.getElementById('foto-modal');
    const open = document.getElementById('abrir-foto');
    const close = document.getElementById('cerrar-foto');
    if (!dialog || !open || !close) return;
    open.addEventListener('click', () => dialog.showModal());
    close.addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', event => {
        const bounds = dialog.getBoundingClientRect();
        if (event.target === dialog && (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom)) dialog.close();
    });
    dialog.addEventListener('close', () => open.focus());
    const input = document.getElementById('foto-archivo');
    input?.addEventListener('change', () => {
        const file = input.files?.[0];
        input.setCustomValidity(file && file.size > 5242880 ? 'La imagen debe pesar como máximo 5 MB.' : '');
        input.reportValidity();
    });
})();

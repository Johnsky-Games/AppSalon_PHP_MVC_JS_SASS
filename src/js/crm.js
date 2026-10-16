// CRM de clientes: mejora progresiva. Todo funciona sin este archivo (los filtros y las descargas son enlaces y un formulario GET).
// Aquí solo se evita el doble clic en una descarga y se avisa, con texto, de que el archivo se está preparando.
(() => {
    'use strict';
    const estado = document.getElementById('crm-estado-descarga');
    const aviso = (texto) => { if (estado) { estado.textContent = texto; } };

    document.querySelectorAll('[data-crm-descarga]').forEach((enlace) => {
        enlace.addEventListener('click', (evento) => {
            if (enlace.getAttribute('aria-disabled') === 'true') { evento.preventDefault(); return; }
            enlace.setAttribute('aria-disabled', 'true');
            enlace.classList.add('is-ocupado');
            aviso('Preparando el archivo… la descarga empezará en unos segundos.');
            window.setTimeout(() => {
                enlace.removeAttribute('aria-disabled');
                enlace.classList.remove('is-ocupado');
                aviso('Si la descarga no empezó, vuelve a pulsar el botón. Si el servidor rechazó la exportación verás el motivo en una página nueva.');
            }, 6000);
        });
    });

    const formulario = document.getElementById('crm-form-filtros');
    if (formulario) {
        formulario.addEventListener('submit', () => {
            const boton = formulario.querySelector('[data-crm-enviar]');
            if (boton) { boton.setAttribute('aria-disabled', 'true'); boton.textContent = 'Aplicando…'; }
            formulario.setAttribute('aria-busy', 'true');
        });
    }
})();

(() => {
    'use strict';
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let lastNotice = '';
    let noticeTime = 0;
    function notify(message, type = 'success') {
        const text = String(message || '').trim();
        if (!text || (lastNotice === text && Date.now() - noticeTime < 2500)) return;
        lastNotice = text;
        noticeTime = Date.now();
        const region = document.querySelector('#ui-notifications');
        if (region) region.textContent = text;
        if (typeof window.Toastify === 'function') {
            const node = document.createElement('span');
            node.textContent = text;
            window.Toastify({ node, duration: 4500, close: true, gravity: 'bottom', position: 'right', stopOnFocus: true,
                className: 'ui-toast ui-toast--' + (type === 'error' ? 'error' : 'success'),
                ariaLive: 'off', style: { background: type === 'error' ? '#9a3434' : '#155c53' }
            }).showToast();
        }
    }
    window.AppSalonUI = Object.freeze({ notify });
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-modal-open]').forEach(button => {
            button.addEventListener('click', () => {
                const modal = document.getElementById(button.dataset.modalOpen);
                if (modal instanceof HTMLDialogElement && !modal.open) modal.showModal();
            });
        });
        document.querySelectorAll('[data-modal-close]').forEach(button => {
            button.addEventListener('click', () => button.closest('dialog')?.close());
        });
        document.querySelectorAll('dialog').forEach(modal => {
            modal.addEventListener('click', event => {
                if (event.target !== modal) return;
                const rect = modal.getBoundingClientRect();
                if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) modal.close();
            });
        });
        document.querySelectorAll('input[type="password"]').forEach(input => {
            const holder = document.createElement('div');
            holder.className = 'ui-password';
            input.parentNode.insertBefore(holder, input);
            holder.appendChild(input);
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'ui-password-toggle';
            button.textContent = 'Mostrar';
            button.setAttribute('aria-label', 'Mostrar contraseña');
            button.setAttribute('aria-pressed', 'false');
            button.addEventListener('click', () => {
                const show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                button.textContent = show ? 'Ocultar' : 'Mostrar';
                button.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
                button.setAttribute('aria-pressed', String(show));
            });
            holder.appendChild(button);
        });
        document.querySelectorAll('[data-list-filter]').forEach(input => {
            const list = document.querySelector(input.dataset.listFilter);
            if (!list) return;
            const output = document.createElement('p');
            output.className = 'ui-search-status';
            output.setAttribute('role', 'status');
            input.parentNode.appendChild(output);
            input.addEventListener('input', () => {
                const term = input.value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('es');
                const items = [...list.querySelectorAll('[data-filter-item]')];
                let count = 0;
                items.forEach(item => {
                    const text = item.textContent.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('es');
                    item.hidden = !text.includes(term);
                    if (!item.hidden) count++;
                });
                output.textContent = term ? `${count} resultado${count === 1 ? '' : 's'}` : '';
                document.querySelectorAll('[data-filter-empty]').forEach(empty => {
                    if (empty.dataset.filterEmpty === input.dataset.listFilter) empty.hidden = count !== 0 || !term;
                });
            });
        });
        const notices = document.querySelectorAll('.alerta');
        if (notices.length) notify([...notices].map(n => n.textContent.trim()).join(' · '), [...notices].some(n => n.classList.contains('error')) ? 'error' : 'success');
        if (window.Swal) window.Swal = window.Swal.mixin({ confirmButtonColor: '#155c53', cancelButtonColor: '#647570', buttonsStyling: true, backdrop: 'rgba(20, 40, 37, .4)', showClass: { popup: reducedMotion ? '' : 'ui-dialog-enter' }, hideClass: { popup: '' } });
        document.querySelectorAll('[data-chart-switch]').forEach(button => {
            button.addEventListener('click', () => {
                const chart = document.getElementById(button.dataset.chartSwitch);
                if (!chart) return;
                const visible = chart.hidden;
                chart.hidden = !visible;
                button.setAttribute('aria-expanded', String(visible));
            });
        });
    });
})();

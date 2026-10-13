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
        const navigation = document.getElementById('ui-main-navigation');
        const navigationPanel = document.getElementById('ui-navigation-panel');
        const navigationToggle = document.querySelector('[data-nav-toggle]');
        if (navigation && navigationPanel && navigationToggle) {
            const compactNavigation = window.matchMedia('(max-width: 1024px)');
            const toggleLabel = navigationToggle.querySelector('[data-nav-toggle-label]');
            const sidebar = navigationToggle.closest('.ui-sidebar');
            let menuOpen = false;
            const setNavigation = (open, focusFirst = false) => {
                menuOpen = compactNavigation.matches && open;
                const expanded = !compactNavigation.matches || menuOpen;
                // Devolver el foco antes de ocultar evita dejarlo dentro de una sección invisible.
                if (!expanded && navigationPanel.contains(document.activeElement)) navigationToggle.focus();
                navigationPanel.hidden = !expanded;
                navigation.classList.toggle('is-open', expanded);
                navigationToggle.setAttribute('aria-expanded', String(expanded));
                if (toggleLabel) toggleLabel.textContent = menuOpen ? 'Cerrar' : 'Menú';
                navigationToggle.setAttribute('aria-label', menuOpen ? 'Cerrar menú de navegación' : 'Abrir menú de navegación');
                if (menuOpen && focusFirst) {
                    const firstLink = navigation.querySelector('[aria-current="page"]') || navigation.querySelector('a[href]');
                    firstLink?.focus();
                }
            };
            document.body.classList.add('ui-navigation-ready');
            setNavigation(false);
            navigationToggle.addEventListener('click', () => {
                setNavigation(!menuOpen, !menuOpen);
            });
            document.addEventListener('keydown', event => {
                if (event.key === 'Escape' && menuOpen && !document.querySelector('dialog[open], .swal2-container')) {
                    event.preventDefault();
                    setNavigation(false);
                    navigationToggle.focus();
                }
            });
            navigation.addEventListener('click', event => {
                if (event.target.closest('a[href]') && menuOpen) setNavigation(false);
            });
            document.addEventListener('click', event => {
                if (menuOpen && sidebar && !sidebar.contains(event.target)) setNavigation(false);
            });
            // Al pasar de escritorio a móvil la navegación vuelve a un estado claro y consistente.
            const resetNavigation = () => setNavigation(false);
            if (typeof compactNavigation.addEventListener === 'function') compactNavigation.addEventListener('change', resetNavigation);
            else compactNavigation.addListener(resetNavigation);
        }
        // Altas (cliente, colaborador, profesional, invitación): un único envío por formulario.
        // La protección real es la clave de operación del servidor; esto evita la segunda petición y
        // comunica el estado. No usa `disabled` (conservaría los datos del botón) y se libera sola si
        // la red falla, para no dejar al usuario bloqueado.
        document.querySelectorAll('form[data-una-vez]').forEach(form => {
            if (form.dataset.unaVezListo) return;
            form.dataset.unaVezListo = '1';
            const estado = document.createElement('p');
            estado.className = 'ui-form-status';
            estado.setAttribute('role', 'status');
            estado.setAttribute('aria-live', 'polite');
            form.appendChild(estado);
            const botones = () => [...form.querySelectorAll('input[type="submit"], button[type="submit"], button:not([type])')];
            let enviando = false;
            let temporizador = 0;
            const liberar = () => {
                clearTimeout(temporizador);
                if (!enviando) return;
                enviando = false;
                form.removeAttribute('aria-busy');
                botones().forEach(boton => {
                    boton.removeAttribute('aria-disabled');
                    boton.classList.remove('is-busy');
                    if (boton.dataset.etiquetaOriginal !== undefined) {
                        if (boton.tagName === 'INPUT') boton.value = boton.dataset.etiquetaOriginal;
                        else boton.textContent = boton.dataset.etiquetaOriginal;
                        delete boton.dataset.etiquetaOriginal;
                    }
                });
                estado.textContent = '';
            };
            form.addEventListener('submit', event => {
                if (enviando) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    return;
                }
                enviando = true;
                form.setAttribute('aria-busy', 'true');
                botones().forEach(boton => {
                    boton.setAttribute('aria-disabled', 'true');
                    boton.classList.add('is-busy');
                    boton.dataset.etiquetaOriginal = boton.tagName === 'INPUT' ? boton.value : boton.textContent;
                    if (boton.tagName === 'INPUT') boton.value = 'Guardando…';
                    else boton.textContent = 'Guardando…';
                });
                estado.textContent = 'Guardando, un momento…';
                temporizador = setTimeout(() => {
                    liberar();
                    estado.textContent = 'No recibimos respuesta. Revisa tu conexión y vuelve a intentarlo: no se duplicará el registro.';
                }, 20000);
            }, true);
            form.addEventListener('click', event => {
                if (enviando && event.target.closest('input[type="submit"], button[type="submit"], button:not([type])')) event.preventDefault();
            }, true);
            window.addEventListener('pageshow', event => { if (event.persisted) liberar(); });
        });
        // Campos que solo aplican a un rol (p. ej. ficha profesional). Sin JavaScript permanecen visibles y rotulados.
        document.querySelectorAll('[data-rol-form]').forEach(form => {
            const select = form.querySelector('[data-rol-select]');
            if (!select) return;
            const aplicar = () => form.querySelectorAll('[data-solo-rol]').forEach(bloque => {
                const activo = bloque.dataset.soloRol === select.value;
                bloque.hidden = !activo;
                bloque.querySelectorAll('input, select, textarea').forEach(campo => { campo.disabled = !activo; });
            });
            select.addEventListener('change', aplicar);
            aplicar();
        });
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

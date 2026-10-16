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
        document.querySelectorAll('[data-grupo-dias]').forEach(grupo => {
            grupo.addEventListener('click', event => {
                const preset = event.target.closest('[data-dias-preset]');
                if (!preset) return;
                const dias = preset.dataset.diasPreset.split(',');
                grupo.querySelectorAll('[data-grupo-dia]').forEach(campo => { campo.checked = dias.includes(campo.value); });
            });
        });
        // Asistente de agenda del profesional: añadir/quitar turnos y descansos y copiar el lunes.
        // Sin JavaScript el formulario sigue siendo válido con las filas que ya trae renderizadas.
        document.querySelectorAll('[data-agenda-asistente]').forEach(asistente => {
            const nombreDia = dia => dia.querySelector('legend')?.textContent.trim() || '';
            const nuevaFila = (tipo, dia) => {
                const plantilla = asistente.querySelector(`template[data-ag-plantilla="${tipo}"]`);
                const lista = dia.querySelector(`[data-ag-lista="${tipo}"]`);
                if (!plantilla || !lista) return null;
                const indice = parseInt(lista.dataset.agSiguiente || '0', 10);
                lista.dataset.agSiguiente = String(indice + 1);
                const prefijo = tipo === 'franja' ? 'franjas' : 'descansos';
                const nodo = plantilla.content.firstElementChild.cloneNode(true);
                nodo.querySelectorAll('[name]').forEach(campo => { campo.name = campo.name.replace(`${prefijo}[0][0]`, `${prefijo}[${dia.dataset.agDia}][${indice}]`); });
                nodo.querySelectorAll('[aria-label]').forEach(campo => campo.setAttribute('aria-label', campo.getAttribute('aria-label').replace('__DIA__', nombreDia(dia))));
                lista.appendChild(nodo);
                return nodo;
            };
            const valores = (dia, tipo) => [...dia.querySelectorAll(`[data-ag-lista="${tipo}"] [data-ag-fila]`)]
                .map(fila => [...fila.querySelectorAll('input')].map(campo => campo.value))
                .filter(fila => fila[0] !== '' || fila[1] !== '');
            const llenar = (dia, tipo, filas) => {
                const lista = dia.querySelector(`[data-ag-lista="${tipo}"]`);
                if (!lista) return;
                lista.querySelectorAll('[data-ag-fila]').forEach(fila => fila.remove());
                (filas.length ? filas : [[]]).forEach(valoresFila => {
                    const fila = nuevaFila(tipo, dia);
                    if (fila) fila.querySelectorAll('input').forEach((campo, i) => { campo.value = valoresFila[i] ?? ''; });
                });
                if (tipo === 'descanso' && filas.length) dia.querySelector('details')?.setAttribute('open', '');
            };
            asistente.addEventListener('click', event => {
                const aplicar = event.target.closest('[data-ag-aplicar-grupo]');
                if (aplicar) {
                    const grupo = aplicar.closest('[data-grupo-dias]');
                    const elegidos = [...grupo.querySelectorAll('[data-grupo-dia]:checked')];
                    const inicio = grupo.querySelector('[data-grupo-inicio]');
                    const fin = grupo.querySelector('[data-grupo-fin]');
                    const estado = grupo.querySelector('[data-grupo-resultado]');
                    if (!elegidos.length) { estado.textContent = 'Selecciona al menos un día.'; return; }
                    if (!inicio.value || !fin.value || inicio.value >= fin.value || !inicio.checkValidity() || !fin.checkValidity()) {
                        estado.textContent = 'Indica un intervalo válido en pasos de cinco minutos.'; return;
                    }
                    const tipo = grupo.dataset.tipo === 'horario' ? 'franja' : 'descanso';
                    const motivo = grupo.querySelector('[data-grupo-motivo]')?.value || '';
                    const resultado = [];
                    elegidos.forEach(campo => {
                        const dia = asistente.querySelector(`[data-ag-dia="${campo.value}"]`);
                        if (!dia) return;
                        let filas = tipo === 'franja' ? [] : valores(dia, tipo).filter(fila => fila[0] !== inicio.value || fila[1] !== fin.value);
                        filas.push(tipo === 'franja' ? [inicio.value, fin.value] : [inicio.value, fin.value, motivo]);
                        llenar(dia, tipo, filas);
                        resultado.push(nombreDia(dia));
                    });
                    estado.textContent = `${tipo === 'franja' ? 'Jornada' : 'Descanso'} preparado de ${inicio.value} a ${fin.value}: ${resultado.join(', ')}. Guarda la configuración para aplicarlo.`;
                    return;
                }
                const agregar = event.target.closest('[data-ag-agregar]');
                if (agregar) {
                    const fila = nuevaFila(agregar.dataset.agAgregar, agregar.closest('[data-ag-dia]'));
                    fila?.querySelector('input')?.focus();
                    return;
                }
                const quitar = event.target.closest('[data-ag-quitar]');
                if (quitar) {
                    const fila = quitar.closest('[data-ag-fila]');
                    const filas = fila.parentElement.querySelectorAll('[data-ag-fila]');
                    // La última fila de una lista se vacía en vez de eliminarse: siempre queda un punto de captura.
                    if (filas.length === 1) fila.querySelectorAll('input').forEach(campo => { campo.value = ''; });
                    else fila.remove();
                    return;
                }
                if (event.target.closest('[data-ag-copiar-lunes]')) {
                    const lunes = asistente.querySelector('[data-ag-dia="1"]');
                    if (!lunes) return;
                    const franjas = valores(lunes, 'franja');
                    const descansos = valores(lunes, 'descanso');
                    ['2', '3', '4', '5'].forEach(numero => {
                        const dia = asistente.querySelector(`[data-ag-dia="${numero}"]`);
                        if (!dia) return;
                        llenar(dia, 'franja', franjas);
                        llenar(dia, 'descanso', descansos);
                    });
                }
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

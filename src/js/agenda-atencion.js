/*
 * Agenda de atención: envío único y confirmación accesible de las acciones de una cita.
 *
 * Sin JavaScript los formularios siguen funcionando (el servidor valida todo y es idempotente); esto añade:
 *  - estado «procesando» y prevención de doble envío (una sola petición por página a la vez: evita también acciones contradictorias);
 *  - confirmación con SweetAlert2 para «Registrar ausencia» (foco inicial en «Volver», Escape cierra, texto concreto);
 *  - aviso en una región de estado (role="status") para lectores de pantalla.
 */
(() => {
    'use strict';
    let enviando = false;
    const iniciar = () => {
        const region = document.querySelector('[data-atencion-estado]');
        const anunciar = texto => { if (region) region.textContent = texto; };
        const formularios = [...document.querySelectorAll('form[data-atencion-form]')];
        const botonesDe = form => [...form.querySelectorAll('button[type="submit"]')];
        let temporizador = 0;

        const liberar = () => {
            clearTimeout(temporizador);
            enviando = false;
            formularios.forEach(form => {
                form.removeAttribute('aria-busy');
                delete form.dataset.confirmado;
                botonesDe(form).forEach(boton => {
                    boton.removeAttribute('aria-disabled');
                    boton.classList.remove('is-busy');
                    if (boton.dataset.etiquetaOriginal !== undefined) {
                        boton.textContent = boton.dataset.etiquetaOriginal;
                        delete boton.dataset.etiquetaOriginal;
                    }
                });
            });
        };

        const marcarProcesando = form => {
            enviando = true;
            form.setAttribute('aria-busy', 'true');
            botonesDe(form).forEach(boton => {
                boton.setAttribute('aria-disabled', 'true');
                boton.classList.add('is-busy');
                boton.dataset.etiquetaOriginal = boton.textContent;
                boton.textContent = boton.dataset.procesando || 'Procesando…';
            });
            // Las demás acciones de la página quedan inertes mientras esta se procesa.
            formularios.filter(otro => otro !== form).forEach(otro => botonesDe(otro).forEach(boton => boton.setAttribute('aria-disabled', 'true')));
            anunciar((botonesDe(form)[0]?.dataset.procesando || 'Procesando…') + ' un momento.');
            // Si la red no responde, se libera para no dejar a la persona bloqueada; el servidor no duplica la acción.
            temporizador = setTimeout(() => {
                liberar();
                anunciar('No recibimos respuesta. Revisa tu conexión y vuelve a intentarlo: la acción no se duplicará.');
            }, 20000);
        };

        formularios.forEach(form => {
            form.addEventListener('submit', evento => {
                if (enviando) { evento.preventDefault(); evento.stopImmediatePropagation(); return; }
                if (form.dataset.confirmarTitulo && form.dataset.confirmado !== '1') {
                    evento.preventDefault();
                    const continuar = () => { form.dataset.confirmado = '1'; form.requestSubmit(botonesDe(form)[0]); };
                    if (window.Swal) {
                        window.Swal.fire({
                            icon: 'warning',
                            title: form.dataset.confirmarTitulo,
                            text: form.dataset.confirmarTexto || '',
                            showCancelButton: true,
                            confirmButtonText: form.dataset.confirmarBoton || 'Confirmar',
                            cancelButtonText: 'Volver',
                            focusCancel: true,
                            reverseButtons: true,
                            returnFocus: true,
                        }).then(resultado => { if (resultado.isConfirmed) continuar(); });
                    } else if (window.confirm(form.dataset.confirmarTitulo + '\n' + (form.dataset.confirmarTexto || ''))) {
                        continuar();
                    }
                    return;
                }
                marcarProcesando(form);
            }, true);
            form.addEventListener('click', evento => {
                if (enviando && evento.target.closest('button')) evento.preventDefault();
            }, true);
        });
        window.addEventListener('pageshow', evento => { if (evento.persisted) liberar(); });
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar);
    else iniciar();
})();

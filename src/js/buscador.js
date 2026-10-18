document.addEventListener('DOMContentLoaded', function () {
    iniciarApp();
});

function iniciarApp() {
    buscarPorFecha();
}

function buscarPorFecha() {
    const fechaInput = document.querySelector('#fecha');
    if (!fechaInput) return;
    fechaInput.addEventListener('input', function (e) {
        const fechaSeleccionada = e.target.value;
        window.location = `?${new URLSearchParams({fecha: fechaSeleccionada})}`;
    });
}
<div class="booking-hero ui-page-header"><div><p class="ui-eyebrow">TU MOMENTO, A TU RITMO</p><h1 class="nombre-pagina">Crear Nueva Cita</h1></div><img class="ui-avatar" src="/build/img/avatar-client.svg" alt="Avatar ilustrado de cliente" width="64" height="64"></div>
<p class="descripcion-pagina">Elige tus servicios, profesional y horario disponible</p>
<?php
include_once __DIR__ . '/../templates/barra.php';
$fechaMinimaInput = isset($fechaMinima) && is_string($fechaMinima) && $fechaMinima !== ''
    ? $fechaMinima
    : (new \DateTimeImmutable('tomorrow', new \DateTimeZone('America/Guayaquil')))->format('Y-m-d');
?>
<div id="app">
    <nav class="tabs" aria-label="Pasos para crear tu cita">
        <button class="actual" type="button" data-paso="1"><span class="step-number" aria-hidden="true">01</span> Servicios</button>
        <button type="button" data-paso="2"><span class="step-number" aria-hidden="true">02</span> Información Citas</button>
        <button type="button" data-paso="3"><span class="step-number" aria-hidden="true">03</span> Resumen</button>
    </nav>
    <div class="booking-selection ui-card" id="booking-selection" aria-live="polite">Selecciona tus servicios para comenzar.</div>
    <div id="paso-1" class="seccion">
        <h2>Servicios</h2>
        <p class="text-center">Elige tus servicios a continuación</p>
        <div id="servicios" class="listado-servicios" role="group" aria-label="Catálogo de servicios disponibles" aria-busy="true"><p class="estado-disponibilidad estado-loading">Cargando el catálogo de servicios…</p></div>
    </div>
    <div id="paso-2" class="seccion">
        <h2>Tus datos y cita</h2>
        <p class="text-center">Selecciona profesional compatible, fecha y horario disponible</p>
        <form class="formulario" id="formulario-cita">
            <div class="campo">
                <label for="nombre">Nombre</label>
                <input type="text" id="nombre" placeholder="Tu Nombre"
                    value="<?php echo s($nombre . ' ' . $apellido); ?>" disabled>
            </div>

            <div class="campo">
                <label for="profesional">Profesional</label>
                <select id="profesional" name="profesionalId" aria-describedby="estado-profesionales">
                    <option value="">-- Selecciona primero al menos un servicio --</option>
                </select>
            </div>
            <aside class="professional-preview ui-card" id="professional-preview" hidden><img src="/build/img/avatar-professional.svg" alt="Avatar ilustrado de profesional" width="48" height="48"><div><small>Te atenderá</small><strong id="professional-preview-name"></strong></div></aside>
            <p id="estado-profesionales" class="estado-profesionales ocultar" aria-live="polite"></p>

            <div class="campo">
                <label for="fecha">Fecha</label>
                <input type="date" id="fecha" min="<?php echo s($fechaMinimaInput); ?>">
            </div>

            <div class="campo campo-horarios">
                <label for="hora">Horario</label>
                <div class="horarios-contenedor">
                    <div id="estado-disponibilidad" class="estado-disponibilidad" data-estado="idle" aria-live="polite">
                        Selecciona servicios, profesional y fecha para consultar los horarios disponibles.
                    </div>
                    <div id="contenedor-horarios-disponibles" class="intervalos-grid" role="group" aria-label="Horarios disponibles del profesional" aria-live="polite"></div>
                    <input type="time" id="hora" class="input-hora-sincronizado" aria-label="Hora de inicio seleccionada">
                </div>
            </div>
            <input type="hidden" id="id" value="<?php echo s((string)$id); ?>">
            <input type="hidden" id="csrf_token" value="<?php echo s(csrf_token()); ?>">
        </form>
    </div>
    <div id="paso-3" class="seccion contenido-resumen" aria-live="polite">
        <h2>Resumen</h2>
        <p class="text-center">Verifica que la información sea correcta</p>
    </div>

    <div class="paginacion">
        <button id="anterior" type="button" class="boton">&laquo; Anterior</button>
        <button id="siguiente" type="button" class="boton">Siguiente &raquo;</button>
    </div>

    <section id="mis-citas" class="mis-citas-seccion" aria-labelledby="heading-mis-citas">
        <div class="mis-citas-encabezado">
            <h2 id="heading-mis-citas">Mis Citas</h2>
            <p class="text-center">Consulta tus reservas activas y cancela cuando lo necesites</p>
        </div>
        <div id="estado-mis-citas" class="estado-disponibilidad" aria-live="polite">Cargando tus citas...</div>
        <ul id="listado-mis-citas" class="citas listado-mis-citas"></ul>
    </section>
</div>

<?php
$script = "
<script src='/build/js/app.js'></script>
";
?>

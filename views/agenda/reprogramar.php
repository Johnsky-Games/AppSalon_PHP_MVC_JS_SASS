<button type="button" class="boton" data-modal-open="agenda-reprogramar-<?php echo (int)$cita['id']; ?>">Reprogramar visita</button>
<dialog id="agenda-reprogramar-<?php echo (int)$cita['id']; ?>" class="agenda-reprogramar" aria-labelledby="agenda-titulo-<?php echo (int)$cita['id']; ?>">
    <h2 id="agenda-titulo-<?php echo (int)$cita['id']; ?>">Reprogramar tu visita</h2>
    <button type="button" data-modal-close aria-label="Cerrar">Cerrar</button>
    <form method="POST" action="/agenda/reprogramar" class="formulario">
        <input type="hidden" name="csrf_token" value="<?php echo s(csrf_token()); ?>">
        <input type="hidden" name="id" value="<?php echo (int)$cita['id']; ?>">
        <?php echo campos_retorno($retornoClave ?? 'cliente_citas', $retornoParams ?? []); ?>
        <label>Nueva fecha <input type="date" name="fecha" required min="<?php echo (new \DateTimeImmutable('tomorrow', new \DateTimeZone('America/Guayaquil')))->format('Y-m-d'); ?>"></label>
        <label>Nueva hora <input type="time" name="hora" required></label>
        <p class="agenda-ayuda">Conservamos tus servicios, precio y profesional. Confirmamos disponibilidad antes de aplicar el cambio.</p>
        <button type="submit" class="boton">Confirmar cambio</button>
    </form>
</dialog>

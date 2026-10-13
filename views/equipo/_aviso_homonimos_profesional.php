<?php
/**
 * Aviso de posibles homónimos al dar de alta un profesional (misma UX que el alta de clientes del CRM).
 * Variables: $homonimos = ['coincidencias' => [...], 'token' => string]. Debe incluirse DENTRO del formulario de alta.
 * Solo muestra nombre, estado y si la ficha ya está vinculada a una cuenta: nunca correos ni teléfonos.
 */
$coincidencias = $homonimos['coincidencias'] ?? [];
$etCriterio = [\Services\HomonimosService::NOMBRE_EXACTO => 'Mismo nombre', \Services\HomonimosService::NOMBRE_PARECIDO => 'Nombre muy parecido', \Services\HomonimosService::TELEFONO => 'Mismo teléfono'];
$mostrar = \Services\HomonimosProfesionalesService::MOSTRAR;
?>
<?php /* Primer botón del formulario = el que dispara la tecla Intro: nunca debe confirmar; solo vuelve a mostrar el aviso. */ ?>
<button type="submit" name="decision" value="crear" class="crm-sr" tabindex="-1" aria-hidden="true">Volver a comprobar</button>
<section class="crm-homonimos" role="alert" aria-labelledby="hp-aviso-titulo">
    <h3 id="hp-aviso-titulo">Posibles profesionales ya registrados</h3>
    <p>Encontramos <?= count($coincidencias) ?> <?= count($coincidencias) === 1 ? 'ficha que podría ser la misma persona' : 'fichas que podrían ser la misma persona' ?>. Puede ser un homónimo legítimo; confirma solo si estás seguro de que es alguien distinto. Nada se fusiona ni se bloquea automáticamente.</p>
    <ul class="crm-homonimos__lista">
        <?php foreach (array_slice($coincidencias, 0, $mostrar) as $c): ?>
        <li class="crm-homonimo">
            <strong><?= s($c['nombre']) ?></strong>
            <span class="crm-homonimo__dato"><?= $c['activo'] ? 'Activa en la agenda' : 'Inactiva' ?></span>
            <span class="crm-homonimo__dato"><?= $c['vinculada'] ? 'Vinculada a una cuenta del equipo' : 'Ficha sin cuenta' ?></span>
            <span class="crm-homonimo__motivos"><?php foreach ($c['criterios'] as $k): ?><span class="crm-etiqueta crm-etiqueta--aviso"><?= s($etCriterio[$k] ?? $k) ?></span> <?php endforeach; ?></span>
            <?php if (!empty($puedeVerFicha)) { ?><a href="/profesionales/actualizar?id=<?= (int)$c['id'] ?>" class="crm-homonimo__ver">Ver ficha de <?= s($c['nombre']) ?></a><?php } ?>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php if (count($coincidencias) > $mostrar): ?><p class="crm-nota">Y <?= count($coincidencias) - $mostrar ?> coincidencia(s) más en el listado de profesionales.</p><?php endif; ?>
    <input type="hidden" name="token_confirmacion" value="<?= s($homonimos['token']) ?>">
    <div class="crm-homonimos__acciones">
        <button class="boton" type="submit" name="decision" value="confirmar">Es una persona distinta: crear ficha</button>
        <button class="boton ui-button-quiet" type="submit" name="decision" value="cancelar" formnovalidate>Cancelar y usar la ficha existente</button>
    </div>
</section>

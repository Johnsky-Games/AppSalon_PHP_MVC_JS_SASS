<section class="ui-card">
<h2>Agenda de consulta</h2>
<p>Esta persona está dada de baja o ya no tiene rol Profesional. Conservamos servicios, horarios y bloqueos para consultar su historial. Su estado laboral se gestiona desde Recursos Humanos.</p>
<h3>Servicios</h3><p><?= s(implode(', ', $profesional->serviciosNombres) ?: 'Sin servicios configurados') ?></p>
<h3>Horario semanal</h3>
<ul><?php foreach ($horarios as $h): ?><li><?= s($diasSemana[(int)$h->dia_semana] ?? '') ?>: <?= s($h->hora_inicio) ?>–<?= s($h->hora_fin) ?></li><?php endforeach; ?></ul>
<?php if (!$horarios): ?><p>Sin horarios configurados.</p><?php endif; ?>
<h3>Descansos semanales</h3>
<ul><?php foreach ($descansos as $d): ?><li><?= s($diasSemana[(int)$d->dia_semana] ?? '') ?>: <?= s($d->hora_inicio) ?>–<?= s($d->hora_fin) ?> · <?= s($d->motivo ?? '') ?></li><?php endforeach; ?></ul>
<?php if (!$descansos): ?><p>Sin descansos configurados.</p><?php endif; ?>
<h3>Bloqueos por fecha</h3>
<ul><?php foreach ($bloqueos as $b): ?><li><?= s($b->fecha_inicio) ?>–<?= s($b->fecha_fin) ?> · <?= s($b->hora_inicio ?? '') ?>–<?= s($b->hora_fin ?? '') ?> · <?= s($b->motivo ?? '') ?></li><?php endforeach; ?></ul>
<?php if (!$bloqueos): ?><p>Sin bloqueos configurados.</p><?php endif; ?>
</section>

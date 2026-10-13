<?php
use Services\CrmService;

$modo = $ficha['modo'];
$cli = $ficha['cliente'];
$nombreCompleto = trim($cli['nombre'] . ' ' . $cli['apellido']);
$entero = static fn($v): string => (string)(int)$v;
$fechaCorta = static fn($d): string => $d ? substr((string)$d, 0, 10) : '—';
$fechaHora = static fn($d): string => $d ? substr((string)$d, 0, 16) : '—';
?>
<header class="ui-page-header">
    <div>
        <p class="ui-eyebrow"><?= $modo === 'propios' ? 'Cliente atendido por ti' : 'Ficha 360° del cliente' ?></p>
        <h1 class="nombre-pagina"><?= s($nombreCompleto) ?></h1>
        <p class="descripcion-pagina"><?= $modo === 'propios' ? 'Contacto operativo y servicios que has realizado a esta persona.' : 'Historia, preferencias observadas, fidelidad y consentimiento en un solo lugar.' ?></p>
    </div>
    <a class="boton ui-button-quiet" href="/admin/clientes">Volver a clientes</a>
</header>
<?php include __DIR__ . '/../templates/barra.php'; include __DIR__ . '/../templates/alertas.php'; ?>

<?php if ($modo === 'propios'): ?>
<section class="crm-card" aria-labelledby="f-contacto"><h2 id="f-contacto">Contacto operativo</h2>
    <dl class="crm-dl"><dt>Correo</dt><dd><?= s($cli['email']) ?></dd><dt>Teléfono</dt><dd><?= s((string)$cli['telefono'] ?: 'No registrado') ?></dd></dl>
</section>
<section class="crm-card" aria-labelledby="f-servicios"><h2 id="f-servicios">Servicios que has realizado a esta persona</h2>
    <?php if (!$ficha['servicios']): ?><p class="crm-vacio">Sin servicios completados contigo.</p><?php else: ?>
    <div class="crm-tabla" tabindex="0" role="region" aria-label="Servicios realizados; desplaza horizontalmente si no caben las columnas">
        <table><caption class="crm-sr">Servicios realizados por ti a este cliente</caption><thead><tr><th scope="col">Servicio</th><th scope="col">Veces</th><th scope="col">Última vez</th></tr></thead><tbody>
        <?php foreach ($ficha['servicios'] as $sv): ?><tr><th scope="row"><?= s($sv['nombre']) ?></th><td class="ui-table-number"><?= $entero($sv['veces']) ?></td><td class="ui-table-date"><?= s($fechaCorta($sv['ultima'])) ?></td></tr><?php endforeach; ?>
        </tbody></table>
    </div><?php endif; ?>
</section>
<p class="crm-nota">Esta vista mínima no incluye gasto, consentimiento comercial ni formularios del cliente.</p>

<?php else:
$analitica = !empty($ficha['analitica']);
$estados = $ficha['citas_por_estado'];
$gasto = $ficha['gasto'];
$fid = $ficha['fidelidad'];
?>
<div class="crm-ficha-grid">
    <section class="crm-card" aria-labelledby="f-datos">
        <h2 id="f-datos">Datos y membresía</h2>
        <dl class="crm-dl">
            <dt>Correo</dt><dd><?= s($cli['email']) ?><?= (int)$cli['confirmado'] === 1 ? '' : ' (sin confirmar)' ?></dd>
            <dt>Teléfono</dt><dd><?= s((string)$cli['telefono'] ?: 'No registrado') ?></dd>
            <dt>Membresía</dt><dd><?= s(CrmService::MEMBRESIAS[$cli['membresia']] ?? $cli['membresia']) ?><?php if ($cli['membresia'] === 'suspendido' && $cli['suspendido_motivo']): ?> · motivo: <?= s($cli['suspendido_motivo']) ?><?php endif; ?></dd>
            <dt>Alta</dt><dd><?= $cli['alta'] ? s($fechaHora($cli['alta'])) . ' · origen: ' . s((string)$cli['origen']) : 'Sin fecha de alta (cuenta legada sin membresía registrada)' ?></dd>
            <?php if ($cli['rol_efectivo'] !== 'cliente'): ?><dt>Perfil laboral</dt><dd>Esta persona también tiene acceso laboral con el rol «<?= s($cli['rol_efectivo']) ?>».</dd><?php endif; ?>
            <dt>Segmento</dt><dd><span class="crm-etiqueta crm-seg--<?= s($cli['segmento']) ?>"><?= s(CrmService::SEGMENTOS[$cli['segmento']] ?? $cli['segmento']) ?></span></dd>
        </dl>
    </section>

    <section class="crm-card" aria-labelledby="f-visitas">
        <h2 id="f-visitas">Visitas y asistencia</h2>
        <dl class="crm-dl">
            <dt>Visitas completadas</dt><dd><?= $entero($cli['visitas_total']) ?></dd>
            <dt>Primera visita</dt><dd><?= s($fechaCorta($cli['primera_visita'])) ?></dd>
            <dt>Última visita</dt><dd><?= $cli['ultima_visita'] === null ? 'Aún sin visitas completadas' : s($cli['ultima_visita']) . ' (hace ' . $entero($cli['recencia']) . ' días)' ?></dd>
            <dt>Citas por estado</dt><dd>
                <?php if (!$estados): ?>Sin citas.<?php else: ?>
                <ul class="crm-lista-simple"><?php foreach ($estados as $est => $n): ?><li><?= s(CrmService::ESTADOS_CITA[$est] ?? $est) ?>: <strong><?= $entero($n) ?></strong></li><?php endforeach; ?></ul>
                <small>Total de citas: <?= $entero($ficha['total_citas']) ?>. Solo «completada» cuenta como visita; cancelada y no asistió son estados distintos.</small>
                <?php endif; ?></dd>
        </dl>
    </section>

    <section class="crm-card" aria-labelledby="f-pref">
        <h2 id="f-pref">Preferencias observadas</h2>
        <p class="crm-nota">Solo lo que el cliente realmente usó en visitas completadas; no se infiere nada más.</p>
        <?php $pref = $ficha['preferencias']; if (!$pref['servicios'] && !$pref['profesionales']): ?><p class="crm-vacio">Todavía no hay visitas completadas.</p><?php else: ?>
        <h3 class="crm-subtitulo">Servicios más frecuentes</h3>
        <ul class="crm-lista-simple"><?php foreach ($pref['servicios'] as $p): ?><li><?= s($p['nombre']) ?>: <strong><?= $entero($p['veces']) ?></strong> <?= (int)$p['veces'] === 1 ? 'vez' : 'veces' ?> · última <?= s($fechaCorta($p['ultima'])) ?></li><?php endforeach; ?></ul>
        <h3 class="crm-subtitulo">Profesionales más frecuentes</h3>
        <?php if (!$pref['profesionales']): ?><p class="crm-vacio">Sin profesional asignado en sus visitas.</p><?php else: ?>
        <ul class="crm-lista-simple"><?php foreach ($pref['profesionales'] as $p): ?><li><?= s($p['nombre']) ?>: <strong><?= $entero($p['veces']) ?></strong> <?= (int)$p['veces'] === 1 ? 'vez' : 'veces' ?> · última <?= s($fechaCorta($p['ultima'])) ?></li><?php endforeach; ?></ul><?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="crm-card" aria-labelledby="f-fid">
        <h2 id="f-fid">Fidelidad</h2>
        <dl class="crm-dl">
            <dt>Puntos vigentes</dt><dd><strong><?= $entero($fid['puntos']) ?></strong> (movimientos registrados, acumulados menos canjes)</dd>
            <dt>Canjes</dt><dd><?= $entero($fid['canjes_pendientes']) ?> pendientes · <?= $entero($fid['canjes_entregados']) ?> entregados</dd>
        </dl>
        <?php if ($fid['movimientos']): ?>
        <details class="crm-datos"><summary>Movimientos de fidelidad (<?= count($fid['movimientos']) ?>, más recientes primero)</summary>
            <div class="crm-tabla" tabindex="0" role="region" aria-label="Movimientos de fidelidad; desplaza horizontalmente si no caben las columnas">
                <table><caption class="crm-sr">Movimientos de fidelidad trazables</caption><thead><tr><th scope="col">Fecha</th><th scope="col">Puntos</th><th scope="col">Tipo</th><th scope="col">Referencia</th><th scope="col">Estado del canje</th></tr></thead><tbody>
                <?php foreach ($fid['movimientos'] as $m): ?><tr><td class="ui-table-date"><?= s($fechaHora($m['creado_en'])) ?></td><td class="ui-table-number"><?= ((int)$m['puntos'] > 0 ? '+' : '') . $entero($m['puntos']) ?></td><td><?= s($m['tipo']) ?></td><td><?= s($m['referencia']) ?><?php if ($m['beneficio_snapshot']): ?><small><?= s($m['beneficio_snapshot']) ?></small><?php endif; ?></td><td><?php if ($m['tipo'] === 'canje'): ?><?= s($m['canje_estado']) ?><?php if ($m['atendido_en']): ?><small>entregado <?= s($fechaHora($m['atendido_en'])) ?><?= $m['atendido_por'] ? ' por ' . s(trim($m['atendido_por'])) : '' ?></small><?php endif; ?><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?>
                </tbody></table>
            </div>
        </details>
        <?php else: ?><p class="crm-vacio">Sin movimientos de fidelidad registrados.</p><?php endif; ?>
    </section>

    <section class="crm-card" aria-labelledby="f-cons">
        <h2 id="f-cons">Consentimiento comercial</h2>
        <dl class="crm-dl">
            <dt>Estado</dt><dd><strong><?= (int)$cli['consentimiento'] === 1 ? 'Aceptó recibir promociones por correo' : 'No ha aceptado' ?></strong></dd>
            <dt>Fecha</dt><dd><?= $cli['consentimiento_en'] ? s($fechaHora($cli['consentimiento_en'])) : 'Sin consentimiento vigente' ?></dd>
            <dt>Versión del texto</dt><dd>No registrada: el esquema actual guarda solo la aceptación y su fecha.</dd>
        </dl>
    </section>

    <section class="crm-card" aria-labelledby="f-espera">
        <h2 id="f-espera">Lista de espera</h2>
        <?php if (!$ficha['espera']): ?><p class="crm-vacio">Sin solicitudes en lista de espera.</p><?php else: ?>
        <ul class="crm-lista-simple"><?php foreach ($ficha['espera'] as $e): ?><li><?= s($e['servicio']) ?> · <?= s($e['fecha']) ?> · <?= s($e['profesional'] ?? 'cualquier profesional') ?> · <strong><?= s($e['estado']) ?></strong></li><?php endforeach; ?></ul><?php endif; ?>
    </section>

    <?php if ($analitica && is_array($gasto)): ?>
    <section class="crm-card" aria-labelledby="f-gasto">
        <h2 id="f-gasto">Gasto observado</h2>
        <p class="crm-nota">Histórico hasta hoy (<?= s($ficha['hoy']) ?>). Cobros reales menos devoluciones, cada uno en su fecha. La pasarela simulada se muestra aparte y no es ingreso.</p>
        <dl class="crm-dl">
            <dt>Cobros reales</dt><dd><?= s(CrmService::dinero($gasto['cobros_real'])) ?> (<?= $entero($gasto['n_cobros_real']) ?> cobros)</dd>
            <dt>Devoluciones</dt><dd><?= s(CrmService::dinero($gasto['devoluciones_real'])) ?></dd>
            <dt>Gasto neto real</dt><dd><strong><?= s(CrmService::dinero($gasto['neto_real'])) ?></strong></dd>
            <dt>Ticket por cobro</dt><dd><?= $gasto['ticket_por_cobro'] === null ? 'Sin cobros' : s(CrmService::dinero($gasto['ticket_por_cobro'])) ?> (cobros brutos ÷ número de cobros)</dd>
            <dt>Neto de pasarela simulada</dt><dd><?= s(CrmService::dinero($gasto['neto_simulado'])) ?> (no es ingreso real)</dd>
        </dl>
    </section>
    <?php endif; ?>
</div>

<section class="crm-card" aria-labelledby="f-citas">
    <h2 id="f-citas">Citas</h2>
    <?php if (!$ficha['citas']): ?><p class="crm-vacio">Este cliente aún no tiene citas.</p><?php else: ?>
    <div class="crm-tabla" tabindex="0" role="region" aria-label="Citas del cliente; desplaza horizontalmente para ver todas las columnas">
        <table><caption class="crm-sr">Últimas <?= count($ficha['citas']) ?> citas</caption><thead><tr><th scope="col">Fecha y hora</th><th scope="col">Estado</th><th scope="col">Servicios</th><th scope="col">Profesional</th><?php if ($analitica): ?><th scope="col">Importe de servicios</th><th scope="col">Pago</th><?php endif; ?></tr></thead><tbody>
        <?php foreach ($ficha['citas'] as $ci): ?>
            <tr><td class="ui-table-date"><?= s($ci['fecha']) ?> <?= s(substr((string)$ci['hora'], 0, 5)) ?></td><td><?= s(CrmService::ESTADOS_CITA[$ci['estado']] ?? $ci['estado']) ?></td><td><?= s((string)$ci['servicios']) ?></td><td><?= s($ci['profesional'] ?? 'Sin asignar') ?></td><?php if ($analitica): ?><td class="ui-table-number"><?= s(CrmService::dinero((int)round(((float)$ci['importe']) * 100))) ?></td><td><?= s($ci['estado_pago']) ?></td><?php endif; ?></tr>
        <?php endforeach; ?>
        </tbody></table>
    </div>
    <p class="crm-nota">Se muestran hasta 100 citas, las más recientes primero.</p>
    <?php endif; ?>
</section>

<section class="crm-card" aria-labelledby="f-crono">
    <h2 id="f-crono">Cronología</h2>
    <p class="crm-nota">Altas, citas con su estado<?= $analitica ? ', cobros y devoluciones' : '' ?>, puntos y canjes, consentimiento y lista de espera, de lo más reciente a lo más antiguo (máximo <?= (int)CrmService::MAX_EVENTOS_CRONOLOGIA ?> eventos).</p>
    <?php if (!$ficha['cronologia']): ?><p class="crm-vacio">Sin eventos registrados.</p><?php else: ?>
    <ol class="crm-cronologia">
        <?php foreach ($ficha['cronologia'] as $ev): ?>
        <li class="crm-evento crm-evento--<?= s($ev['tipo']) ?>"><time datetime="<?= s(str_replace(' ', 'T', substr((string)$ev['cuando'], 0, 19))) ?>"><?= s($fechaHora($ev['cuando'])) ?></time><strong><?= s($ev['titulo']) ?></strong><span><?= s($ev['detalle']) ?></span></li>
        <?php endforeach; ?>
    </ol><?php endif; ?>
</section>

<p class="crm-nota">Privacidad: las respuestas de formularios y fichas privadas (incluida información de salud) no se muestran en este módulo.<?= $analitica ? '' : ' El gasto y los segmentos que dependen de él requieren permiso de analítica.' ?></p>
<?php endif; ?>

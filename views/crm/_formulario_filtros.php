<?php
/**
 * Formulario de filtros del CRM (GET, sin JavaScript). Lo comparten /admin/clientes y /admin/clientes/listado: un solo conjunto de
 * campos para un solo universo. Espera $f, $catalogos, $acceso, $rutaBase, $panelHoy (AAAA-MM-DD) y $modo ('completo'|'analitica').
 */
use Services\CrmService;

$analitica = !empty($acceso['analitica']);
$completo = $modo === 'completo';
$sel = static fn($a, $b): string => (string)$a === (string)$b ? ' selected' : '';
$centavosATexto = static fn($c): string => $c === null ? '' : number_format((int)$c / 100, 2, '.', '');
?>
<form class="crm-filtros crm-filtros--agrupados" action="<?= s($rutaBase) ?>
<?php $periodoContexto="clientes"; $periodoHistorico=true; include __DIR__."/../templates/_periodo_filtro.php"; ?>
" method="GET"<?= $completo ? ' role="search"' : '' ?> id="crm-form-filtros">
    <?php if (!empty($f['indicador'])): ?><input type="hidden" name="indicador" value="<?= s($f['indicador']) ?>"><?php endif; ?>
    <?php if ($completo): ?>
    <fieldset class="crm-grupo crm-ancho">
        <legend>Buscar</legend>
        <div class="campo"><label for="crm-q">Nombre, correo o teléfono</label><input id="crm-q" name="q" type="search" maxlength="80" value="<?= s($f['q']) ?>" autocomplete="off"></div>
    </fieldset>
    <?php endif; ?>

    <div class="campo"><label for="crm-vinculo">Vínculo con el negocio</label><select id="crm-vinculo" name="vinculo"><?php foreach (CrmService::VINCULOS as $k => $et): ?><option value="<?= s($k) ?>"<?= $sel($f['vinculo'] ?? 'externo', $k) ?>><?= s($et) ?></option><?php endforeach; ?></select></div>
    <fieldset class="crm-grupo">
        <legend>Periodo y actividad</legend>
        <div class="campo"><label for="crm-desde">Periodo desde</label><input id="crm-desde" name="desde" type="date" value="<?= s($f['desde']) ?>" max="<?= s($panelHoy) ?>"></div>
        <div class="campo"><label for="crm-hasta">Periodo hasta</label><input id="crm-hasta" name="hasta" type="date" value="<?= s($f['hasta']) ?>" max="<?= s($panelHoy) ?>"></div>
        <div class="campo"><label for="crm-dias">Días para «activo» (30–365)</label><input id="crm-dias" name="dias_activo" type="number" inputmode="numeric" min="<?= (int)CrmService::DIAS_ACTIVO_MIN ?>" max="<?= (int)CrmService::DIAS_ACTIVO_MAX ?>" value="<?= (int)$f['dias_activo'] ?>"></div>
        <?php if ($completo): ?>
        <div class="campo"><label for="crm-act">Actividad comercial</label><select id="crm-act" name="actividad"><option value="">Todas</option><?php foreach (CrmService::ACTIVIDADES as $k => $et): ?><option value="<?= s($k) ?>"<?= $sel($f['actividad'], $k) ?>><?= s($et) ?></option><?php endforeach; ?></select></div>
        <div class="campo"><label for="crm-seg">Segmento</label><select id="crm-seg" name="segmento"><option value="">Todos</option><?php foreach (CrmService::SEGMENTOS as $k => $et): if ($k === 'vip' && !$analitica) { continue; } ?><option value="<?= s($k) ?>"<?= $sel($f['segmento'], $k) ?>><?= s($et) ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
    </fieldset>

    <?php if ($completo): ?>
    <fieldset class="crm-grupo">
        <legend>Cuenta, membresía y comunicaciones</legend>
        <div class="campo"><label for="crm-cuenta">Cuenta</label><select id="crm-cuenta" name="cuenta"><option value="">Todas</option><?php foreach (CrmService::CUENTAS as $k => $et): ?><option value="<?= s($k) ?>"<?= $sel($f['cuenta'], $k) ?>><?= s($et) ?></option><?php endforeach; ?></select></div>
        <div class="campo"><label for="crm-mem">Membresía de cliente</label><select id="crm-mem" name="membresia"><option value="">Todas</option><?php foreach (CrmService::MEMBRESIAS as $k => $et): ?><option value="<?= s($k) ?>"<?= $sel($f['membresia'], $k) ?>><?= s($et) ?></option><?php endforeach; ?></select></div>
        <div class="campo"><label for="crm-con">Suscripción a comunicaciones comerciales</label><select id="crm-con" name="consentimiento"><option value="">Todas</option><?php foreach (CrmService::CONSENTIMIENTOS as $k => $et): ?><option value="<?= s($k) ?>"<?= $sel($f['consentimiento'], $k) ?>><?= s($et) ?></option><?php endforeach; ?></select></div>
    </fieldset>

    <fieldset class="crm-grupo">
        <legend>Fechas</legend>
        <div class="campo"><label for="crm-alta-desde">Alta desde</label><input id="crm-alta-desde" name="alta_desde" type="date" value="<?= s((string)$f['alta_desde']) ?>"></div>
        <div class="campo"><label for="crm-alta-hasta">Alta hasta</label><input id="crm-alta-hasta" name="alta_hasta" type="date" value="<?= s((string)$f['alta_hasta']) ?>"></div>
        <div class="campo"><label for="crm-ult-desde">Última visita desde</label><input id="crm-ult-desde" name="ultima_desde" type="date" value="<?= s((string)$f['ultima_desde']) ?>"></div>
        <div class="campo"><label for="crm-ult-hasta">Última visita hasta</label><input id="crm-ult-hasta" name="ultima_hasta" type="date" value="<?= s((string)$f['ultima_hasta']) ?>"></div>
        <div class="campo"><label for="crm-prox">Próxima cita</label><select id="crm-prox" name="proxima"><option value="">Indistinto</option><?php foreach (CrmService::PROXIMAS as $k => $et): ?><option value="<?= s($k) ?>"<?= $sel($f['proxima'], $k) ?>><?= s($et) ?></option><?php endforeach; ?></select></div>
        <div class="campo"><label for="crm-prox-desde">Próxima cita desde</label><input id="crm-prox-desde" name="proxima_desde" type="date" value="<?= s((string)$f['proxima_desde']) ?>"></div>
        <div class="campo"><label for="crm-prox-hasta">Próxima cita hasta</label><input id="crm-prox-hasta" name="proxima_hasta" type="date" value="<?= s((string)$f['proxima_hasta']) ?>"></div>
    </fieldset>

    <fieldset class="crm-grupo">
        <legend>Atención y frecuencia</legend>
        <div class="campo"><label for="crm-prof">Atendido por</label><select id="crm-prof" name="profesional_id"><option value="">Cualquier profesional</option><?php foreach ($catalogos['profesionales'] as $p): ?><option value="<?= (int)$p['id'] ?>"<?= $sel($f['profesional_id'], $p['id']) ?>><?= s($p['nombre'] . ((int)$p['activo'] === 1 ? '' : ' (inactivo)')) ?></option><?php endforeach; ?></select></div>
        <div class="campo"><label for="crm-serv">Servicio recibido</label><select id="crm-serv" name="servicio_id"><option value="">Cualquier servicio</option><?php foreach ($catalogos['servicios'] as $sv): ?><option value="<?= (int)$sv['id'] ?>"<?= $sel($f['servicio_id'], $sv['id']) ?>><?= s($sv['nombre']) ?></option><?php endforeach; ?></select></div>
        <div class="campo"><label for="crm-vmin">Visitas en el periodo, mínimo</label><input id="crm-vmin" name="visitas_min" type="number" inputmode="numeric" min="0" max="9999" value="<?= s((string)($f['visitas_min'] ?? '')) ?>"></div>
        <div class="campo"><label for="crm-vmax">Visitas en el periodo, máximo</label><input id="crm-vmax" name="visitas_max" type="number" inputmode="numeric" min="0" max="9999" value="<?= s((string)($f['visitas_max'] ?? '')) ?>"></div>
    </fieldset>

    <?php if ($analitica): ?>
    <fieldset class="crm-grupo">
        <legend>Dinero (solo analítica)</legend>
        <div class="campo"><label for="crm-gmin">Gasto neto real mínimo (USD)</label><input id="crm-gmin" name="gasto_min" type="text" inputmode="decimal" maxlength="10" pattern="[0-9]{1,7}([.,][0-9]{1,2})?" placeholder="0.00" value="<?= s($centavosATexto($f['gasto_min'] ?? null)) ?>"></div>
        <div class="campo"><label for="crm-gmax">Gasto neto real máximo (USD)</label><input id="crm-gmax" name="gasto_max" type="text" inputmode="decimal" maxlength="10" pattern="[0-9]{1,7}([.,][0-9]{1,2})?" placeholder="0.00" value="<?= s($centavosATexto($f['gasto_max'] ?? null)) ?>"></div>
        <div class="campo"><label for="crm-saldo">Saldo pendiente</label><select id="crm-saldo" name="saldo"><option value="">Indistinto</option><?php foreach (CrmService::SALDOS as $k => $et): ?><option value="<?= s($k) ?>"<?= $sel($f['saldo'], $k) ?>><?= s($et) ?></option><?php endforeach; ?></select></div>
    </fieldset>
    <?php endif; ?>

    <fieldset class="crm-grupo">
        <legend>Orden y tamaño de página</legend>
        <div class="campo"><label for="crm-orden">Ordenar por</label><select id="crm-orden" name="orden"><?php foreach (CrmService::ORDENES_ETIQUETAS as $k => $et): if (!$analitica && in_array($k, ['gasto', 'saldo'], true)) { continue; } ?><option value="<?= s($k) ?>"<?= $sel($f['orden'], $k) ?>><?= s($et) ?></option><?php endforeach; ?></select></div>
        <div class="campo"><label for="crm-dir">Dirección</label><select id="crm-dir" name="dir"><option value="asc"<?= $sel($f['dir'], 'asc') ?>>Ascendente</option><option value="desc"<?= $sel($f['dir'], 'desc') ?>>Descendente</option></select></div>
        <div class="campo"><label for="crm-pp2">Por página</label><select id="crm-pp2" name="por_pagina"><?php foreach (CrmService::POR_PAGINA as $n): ?><option value="<?= (int)$n ?>"<?= $sel($f['por_pagina'], $n) ?>><?= (int)$n ?></option><?php endforeach; ?></select></div>
    </fieldset>
    <?php endif; ?>

    <div class="crm-filtros__acciones crm-ancho"><button class="boton" type="submit" data-crm-enviar>Aplicar filtros</button><a class="boton ui-button-quiet" href="<?= s($rutaBase) ?>">Limpiar filtros</a></div>
</form>

<details class="crm-definiciones-filtros">
    <summary>Qué significa cada filtro</summary>
    <dl class="crm-dl">
        <?php foreach (CrmService::DEFINICIONES as $clave => $texto): if (!$analitica && in_array($clave, ['gasto', 'saldo'], true)) { continue; } if (!$completo && !in_array($clave, ['periodo'], true)) { continue; } ?>
        <dt><?= s(['vinculo' => 'Vínculo con el negocio', 'q' => 'Búsqueda', 'cuenta' => 'Cuenta', 'membresia' => 'Membresía de cliente', 'consentimiento' => 'Suscripción a comunicaciones comerciales', 'actividad' => 'Actividad comercial', 'segmento' => 'Segmento', 'alta' => 'Alta', 'ultima' => 'Última visita', 'proxima' => 'Próxima cita', 'profesional' => 'Atendido por', 'servicio' => 'Servicio recibido', 'frecuencia' => 'Frecuencia', 'gasto' => 'Gasto', 'saldo' => 'Saldo pendiente', 'periodo' => 'Periodo'][$clave] ?? $clave) ?></dt>
        <dd><?= s($texto) ?></dd>
        <?php endforeach; ?>
    </dl>
</details>

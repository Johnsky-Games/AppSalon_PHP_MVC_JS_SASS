<?php
use Services\AltaClienteService;
use Services\HomonimosService;

/** @var array $previo @var ?array $resultado @var string $clave */
$aviso = $resultado && ($resultado['status'] ?? '') === AltaClienteService::REQUIERE_CONFIRMACION;
$coincidencias = $aviso ? $resultado['coincidencias'] : [];
$etCriterio = [HomonimosService::NOMBRE_EXACTO => 'Mismo nombre', HomonimosService::NOMBRE_PARECIDO => 'Nombre muy parecido', HomonimosService::TELEFONO => 'Mismo teléfono'];
$mostrar = AltaClienteService::MOSTRAR;
?>
<header class="ui-page-header">
    <div>
        <p class="ui-eyebrow">Relación con clientes</p>
        <h1 class="nombre-pagina">Nuevo cliente</h1>
        <p class="descripcion-pagina">Registra a una persona que aún no tiene cuenta. Antes de guardar se avisa si ya existe alguien parecido; nunca se fusiona ni se bloquea nada automáticamente.</p>
    </div>
    <a class="boton ui-button-quiet" href="/admin/clientes">Volver a clientes</a>
</header>
<?php include __DIR__ . '/../templates/barra.php'; include __DIR__ . '/../templates/alertas.php'; ?>

<form class="crm-card crm-alta" method="POST" action="/admin/clientes/nuevo" novalidate aria-labelledby="alta-titulo">
    <h2 id="alta-titulo">Datos de la persona</h2>
    <input type="hidden" name="csrf_token" value="<?= s(csrf_token()) ?>">
    <input type="hidden" name="idem_key" value="<?= s($clave) ?>">
    <div class="crm-filtros">
        <div class="campo"><label for="alta-nombre">Nombre</label><input id="alta-nombre" name="nombre" required maxlength="60" autocomplete="off" value="<?= s($previo['nombre']) ?>"></div>
        <div class="campo"><label for="alta-apellido">Apellido</label><input id="alta-apellido" name="apellido" required maxlength="60" autocomplete="off" value="<?= s($previo['apellido']) ?>"></div>
        <div class="campo"><label for="alta-email">Correo electrónico</label><input id="alta-email" name="email" type="email" required maxlength="60" autocomplete="off" value="<?= s($previo['email']) ?>"></div>
        <div class="campo"><label for="alta-telefono">Teléfono (7 a 10 dígitos)</label><input id="alta-telefono" name="telefono" inputmode="numeric" pattern="[0-9]{7,10}" required maxlength="10" autocomplete="off" value="<?= s($previo['telefono']) ?>"></div>
    </div>

    <?php if ($aviso): ?>
    <?php /* Primer botón del formulario = el que dispara la tecla Intro: nunca debe confirmar; solo vuelve a mostrar el aviso. */ ?>
    <button type="submit" name="decision" value="crear" class="crm-sr" tabindex="-1" aria-hidden="true">Volver a comprobar</button>
    <section class="crm-homonimos" role="alert" aria-labelledby="alta-aviso-titulo">
        <h3 id="alta-aviso-titulo">Posibles personas ya registradas</h3>
        <p>Encontramos <?= count($coincidencias) ?> <?= count($coincidencias) === 1 ? 'cliente que podría ser la misma persona' : 'clientes que podrían ser la misma persona' ?>. Puede ser un homónimo legítimo; confirma solo si estás seguro de que es alguien distinto.</p>
        <ul class="crm-homonimos__lista">
            <?php foreach (array_slice($coincidencias, 0, $mostrar) as $c): ?>
            <li class="crm-homonimo">
                <strong><?= s($c['nombre']) ?></strong>
                <span class="crm-homonimo__dato">Correo <?= s($c['correo']) ?></span>
                <span class="crm-homonimo__dato">Teléfono <?= s($c['telefono']) ?></span>
                <span class="crm-homonimo__dato">Última visita: <?= $c['ultima_visita'] ? s($c['ultima_visita']) : 'sin visitas' ?></span>
                <span class="crm-homonimo__motivos"><?php foreach ($c['criterios'] as $k): ?><span class="crm-etiqueta crm-etiqueta--aviso"><?= s($etCriterio[$k] ?? $k) ?></span> <?php endforeach; ?></span>
                <a href="/admin/clientes/ficha?id=<?= (int)$c['id'] ?>" class="crm-homonimo__ver">Ver ficha de <?= s($c['nombre']) ?></a>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php if (count($coincidencias) > $mostrar): ?><p class="crm-nota">Y <?= count($coincidencias) - $mostrar ?> coincidencia(s) más. Búscalas en el listado de clientes.</p><?php endif; ?>
        <input type="hidden" name="token_confirmacion" value="<?= s($resultado['token']) ?>">
        <div class="crm-homonimos__acciones">
            <button class="boton" type="submit" name="decision" value="confirmar">Es una persona distinta: crear cliente</button>
            <button class="boton ui-button-quiet" type="submit" name="decision" value="cancelar" formnovalidate>Cancelar y usar la ficha existente</button>
        </div>
    </section>
    <?php else: ?>
    <div class="crm-filtros__acciones"><button class="boton" type="submit" name="decision" value="crear">Guardar cliente</button><a class="boton ui-button-quiet" href="/admin/clientes">Cancelar</a></div>
    <p class="crm-nota">La persona no recibe correos desde aquí ni queda con consentimiento comercial: eso solo lo decide ella en su perfil. Para entrar, deberá confirmar su correo y definir su contraseña.</p>
    <?php endif; ?>
</form>

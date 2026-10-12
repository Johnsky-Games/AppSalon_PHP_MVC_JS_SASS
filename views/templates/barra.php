<?php $uiNombreSesion = trim((string)($nombre ?? $_SESSION['nombre'] ?? '') . ' ' . (string)($apellido ?? $_SESSION['apellido'] ?? '')); ?>
<div class="barra ui-account-bar">
    <div class="ui-account-person">
        <img class="ui-avatar" src="/build/img/<?php echo !empty($_SESSION['admin']) ? 'avatar-professional.svg' : 'avatar-client.svg'; ?>" alt="" width="44" height="44">
        <div><span class="ui-eyebrow"><?php echo !empty($_SESSION['admin']) ? 'ADMINISTRACIÓN' : 'MI ESPACIO'; ?></span><p>Hola: <strong><?php echo s($uiNombreSesion); ?></strong></p></div>
    </div>
    <form action="/logout" method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <button type="submit" class="boton ui-button-quiet"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M10 4H4v16h6m4-12 4 4-4 4m-6-4h14"/></svg>Cerrar Sesión</button>
    </form>
</div>

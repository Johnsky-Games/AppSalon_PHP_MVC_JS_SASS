<?php
$uiNombreSesion = trim((string)($nombre ?? $_SESSION['nombre'] ?? '') . ' ' . (string)($apellido ?? $_SESSION['apellido'] ?? ''));
$uiFotoSesion = '/build/img/' . (!empty($_SESSION['admin']) ? 'avatar-professional.svg' : 'avatar-client.svg');
try {
    $uiPerfilSesion = (new \Repositories\ProfileRepository(\Model\ActiveRecord::getDB()))->obtener((int)($_SESSION['id'] ?? 0));
    if (!empty($uiPerfilSesion['archivo'])) $uiFotoSesion = '/perfil/foto?id=' . (int)$_SESSION['id'];
} catch (\Throwable $e) { /* El avatar permanece disponible ante un fallo de consulta. */ }
?>
<div class="barra ui-account-bar">
    <div class="ui-account-person">
        <a href="/perfil" aria-label="Abrir mi perfil"><img class="ui-avatar" src="<?php echo s($uiFotoSesion); ?>" alt="" width="44" height="44"></a>
        <div><span class="ui-eyebrow"><?php echo !empty($_SESSION['admin']) ? 'ADMINISTRACIÓN' : 'MI ESPACIO'; ?></span><p>Hola: <strong><?php echo s($uiNombreSesion); ?></strong></p></div>
    </div>
    <form action="/logout" method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <button type="submit" class="boton ui-button-quiet"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M10 4H4v16h6m4-12 4 4-4 4m-6-4h14"/></svg>Cerrar Sesión</button>
    </form>
</div>

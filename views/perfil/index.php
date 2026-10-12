<?php include __DIR__ . '/../templates/barra.php'; ?>
<header class="ui-page-header"><div><span class="ui-eyebrow">TU IDENTIDAD Y TUS PREFERENCIAS</span><h1 class="nombre-pagina">Mi perfil</h1><p>Actualiza tus datos y decide cómo deseas mantenerte en contacto.</p></div></header>
<?php include __DIR__ . '/../templates/alertas.php'; ?>
<?php if ($perfil) { $perfilUrl = '/perfil?id=' . (int)$perfil['id']; ?>
<div class="ui-profile-grid">
    <section class="ui-profile-card" aria-labelledby="perfil-identidad">
        <img class="ui-profile-photo" src="<?php echo !empty($perfil['archivo']) ? '/perfil/foto?id=' . (int)$perfil['id'] : '/build/img/avatar-client.svg'; ?>" alt="Foto de perfil" width="160" height="160">
        <h2 id="perfil-identidad"><?php echo s($perfil['nombre'] . ' ' . $perfil['apellido']); ?></h2>
        <p><?php echo s($perfil['email']); ?></p>
        <p class="ui-profile-help"><?php echo $perfil['es_profesional'] && $perfil['foto_publica'] ? 'Tu retrato profesional es público.' : 'Tu foto es privada y sólo puede verla personal autorizado.'; ?></p>
        <button type="button" class="boton" id="abrir-foto">Cambiar foto</button>
        <?php if (!empty($perfil['archivo'])) { ?>
        <form action="/perfil/foto?id=<?php echo (int)$perfil['id']; ?>" method="POST"><input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"><input type="hidden" name="accion" value="eliminar"><button type="submit" class="boton ui-button-quiet">Eliminar foto</button></form>
        <?php } ?>
        <a href="/olvide" class="ui-profile-security">Cambiar contraseña mediante enlace seguro</a>
    </section>
    <section class="ui-profile-card" aria-labelledby="perfil-datos">
        <h2 id="perfil-datos">Datos y comunicaciones</h2>
        <form class="formulario" method="POST" action="<?php echo s($perfilUrl); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <div class="campo"><label for="perfil-nombre">Nombre</label><input id="perfil-nombre" name="nombre" required maxlength="60" autocomplete="given-name" value="<?php echo s($perfil['nombre']); ?>"></div>
            <div class="campo"><label for="perfil-apellido">Apellido</label><input id="perfil-apellido" name="apellido" required maxlength="60" autocomplete="family-name" value="<?php echo s($perfil['apellido']); ?>"></div>
            <div class="campo"><label for="perfil-telefono">Teléfono</label><input id="perfil-telefono" name="telefono" required inputmode="numeric" pattern="[0-9]{7,10}" maxlength="10" autocomplete="tel" value="<?php echo s($perfil['telefono']); ?>"></div>
            <fieldset class="ui-profile-preferences"><legend>Preferencias de comunicación</legend>
                <?php foreach (['contacto_email'=>'Recibir comunicaciones de atención por correo','recordatorios'=>'Recibir recordatorios de mis citas','marketing_email'=>'Acepto recibir promociones por correo (opcional)'] as $key=>$label) { ?>
                <label><input type="checkbox" name="<?php echo s($key); ?>" value="1" <?php echo $perfil[$key] ? 'checked' : ''; ?>><span><?php echo s($label); ?></span></label>
                <?php } ?>
                <?php if ($perfil['es_profesional']) { ?><label><input type="checkbox" name="foto_publica" value="1" <?php echo $perfil['foto_publica'] ? 'checked' : ''; ?>><span>Publicar mi retrato como profesional del salón</span></label><?php } ?>
            </fieldset>
            <p class="ui-profile-help">Los enlaces de acceso y seguridad de la cuenta seguirán disponibles aunque desactives comunicaciones opcionales. Puedes retirar tu consentimiento promocional cuando quieras.</p>
            <button type="submit" class="boton">Guardar preferencias</button>
        </form>
    </section>
</div>
<dialog class="ui-profile-dialog" id="foto-modal" aria-labelledby="foto-titulo">
    <button type="button" id="cerrar-foto" class="ui-profile-close" aria-label="Cerrar">×</button>
    <h2 id="foto-titulo">Tu nueva foto</h2><p>JPEG, PNG o WebP. Máximo 5 MB y 4096 píxeles por lado y 12 megapíxeles. Se eliminarán los metadatos de la imagen.</p>
    <form action="/perfil/foto?id=<?php echo (int)$perfil['id']; ?>" method="POST" enctype="multipart/form-data" class="formulario">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <div class="campo"><label for="foto-archivo">Selecciona una imagen</label><input id="foto-archivo" type="file" name="foto" accept="image/jpeg,image/png,image/webp" required></div>
        <button type="submit" class="boton">Guardar foto</button>
    </form>
</dialog>
<?php $script = '<script src="/build/js/perfil.js"></script>'; } ?>

<?php
/**
 * Marcado compartido del menú lateral. Variables: $uiGrupos (navegacion_portal()), $uiEnlaceActivo (navegacion_enlace_activo()),
 * $uiNavEtiqueta (nombre accesible del menú). Los enlaces ya vienen filtrados por portal y permisos del servidor.
 */
$uiIconos = [
    'calendar' => '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M16 3v4M8 3v4M3 11h18m-13 4h2m4 0h2"/>',
    'sparkles' => '<path d="m12 3 2.7 6.3L21 12l-6.3 2.7L12 21l-2.7-6.3L3 12l6.3-2.7Z"/>',
    'plus' => '<path d="M12 5v14M5 12h14"/><circle cx="12" cy="12" r="10"/>',
    'users' => '<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3m3-16a3 3 0 0 1 0 6m3 10v-3a6 6 0 0 0-3-5"/>',
    'chart' => '<path d="M4 3v18h17M8 16v-5m5 5V7m5 9V4"/>',
    'check' => '<rect x="3" y="3" width="18" height="18" rx="4"/><path d="m7 12 3 3 7-7"/>',
];
?>
            <nav class="ui-main-nav <?php echo s($uiNavClase); ?>" id="ui-main-navigation" aria-label="<?php echo s($uiNavEtiqueta); ?>">
                <?php foreach ($uiGrupos as $uiGrupoIndice => $uiGrupo) { ?>
                <section class="ui-nav-group" aria-labelledby="ui-nav-group-<?php echo (int)$uiGrupoIndice; ?>">
                <h2 class="ui-nav-group-title" id="ui-nav-group-<?php echo (int)$uiGrupoIndice; ?>"><?php echo s($uiGrupo['titulo']); ?></h2>
                <ul class="ui-nav-list">
                <?php foreach ($uiGrupo['enlaces'] as [$uiHref, $uiTexto, $uiIcono]) {
                    $uiActiva = $uiEnlaceActivo === $uiHref;
                ?>
                <li><a href="<?php echo s($uiHref); ?>" class="ui-nav-link<?php echo $uiActiva ? ' is-active' : ''; ?>"<?php echo $uiActiva ? ' aria-current="page"' : ''; ?>>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?php echo $uiIconos[$uiIcono] ?? $uiIconos['check']; ?></svg>
                    <span><?php echo s($uiTexto); ?></span>
                </a></li>
                <?php } ?>
                </ul>
                </section>
                <?php } ?>
            </nav>

<?php
// Iconos locales de estado, sin recursos externos ni texto adicional para lectores de pantalla.
$uiEstadoTrazos = match ($uiEstadoIcono ?? 'info') {
    'success' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
    'mail' => '<rect x="3" y="5" width="18" height="14" rx="3"/><path d="m3 7 9 6 9-6"/>',
    'lock' => '<rect x="4" y="10" width="16" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/>',
    'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    'error' => '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6m0-6-6 6"/>',
    default => '<circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10v1"/>',
};
?>
<span class="ui-state-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><?php echo $uiEstadoTrazos; ?></svg></span>

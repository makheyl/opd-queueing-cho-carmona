<?php
// Small shared inline-SVG icon set used across the standardized UI.
// Purely presentational: no ids, no behavior, safe to drop anywhere.
if (!function_exists('opd_icon')) {
    function opd_icon($name, $class = 'icon') {
        $paths = [
            'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.2 2"/>',
            'trash'    => '<path d="M4 7h16"/><path d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/><path d="M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13"/><path d="M10 11v6"/><path d="M14 11v6"/>',
            'bell'     => '<path d="M6 9a6 6 0 1 1 12 0c0 3.4.8 5 1.6 6H4.4C5.2 14 6 12.4 6 9Z"/><path d="M9.5 18a2.5 2.5 0 0 0 5 0"/>',
            'printer'  => '<path d="M7 8V4a1 1 0 0 1 1-1h8a1 1 0 0 1 1 1v4"/><rect x="5" y="8" width="14" height="8" rx="1.5"/><path d="M7 16v4a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1v-4"/>',
            'download' => '<path d="M12 4v11"/><path d="M8 11l4 4 4-4"/><path d="M5 19h14"/>',
            'check'    => '<path d="M5 12.5l4.5 4.5L19 7"/>',
            'x-circle' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5l5 5M14.5 9.5l-5 5"/>',
        ];
        if (!isset($paths[$name])) return '';
        return '<svg class="' . htmlspecialchars($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" '
             . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[$name] . '</svg>';
    }
}

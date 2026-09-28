<?php
// Standardized console header with the top menu. Before including, a page sets:
//   $page_subtitle  small line under the title
//   $nav_active     menu item to highlight: 'front_desk', 'doctor', 'reports' or 'printer'
//                   (optional; e.g. test_database.php highlights none)
//   $staff_name     optional: shown on the right as "Name · change"
//   $change_url     optional: where "change" goes (back to that screen's name card)
// display.php is the waiting-area board and deliberately not in the menu.
require_once __DIR__ . '/icons.php';
$opd_nav_items = [
    'front_desk' => ['index.php', 'Front Desk'],
    'doctor' => ['doctor.php', 'Doctor'],
    'reports' => ['admin.php', 'Reports'],
    'printer' => ['printer_setup.php', 'Printer'],
];
$opd_nav_active = $nav_active ?? '';
$opd_staff_name = $staff_name ?? '';
$opd_change_url = $change_url ?? '';
?>
<div class="app-header">
    <div class="brand">
        <img src="CHO.png" alt="CHO Logo" class="logo-img">
        <div class="brand-text">
            <span class="title">OPD Queueing</span>
            <span class="subtitle"><?= htmlspecialchars($page_subtitle ?? '') ?></span>
        </div>
    </div>
    <nav class="app-nav no-print" aria-label="Screens">
        <?php foreach ($opd_nav_items as $opd_nav_key => $opd_nav_item): ?>
        <a href="<?= $opd_nav_item[0] ?>"<?= $opd_nav_key === $opd_nav_active ? ' class="active" aria-current="page"' : '' ?>><?= $opd_nav_item[1] ?></a>
        <?php endforeach; ?>
    </nav>
    <?php if ($opd_staff_name !== ''): ?>
    <div class="staff-id">
        <?= htmlspecialchars($opd_staff_name) ?>
        <?php if ($opd_change_url !== ''): ?>&middot; <a href="<?= htmlspecialchars($opd_change_url) ?>">change</a><?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="header-clock">
        <?= opd_icon('clock') ?>
        <span class="clock-text">
            <span data-live-date>&nbsp;</span>
            <span class="clock-sep">&middot;</span>
            <span data-live-time>&nbsp;</span>
        </span>
    </div>
</div>

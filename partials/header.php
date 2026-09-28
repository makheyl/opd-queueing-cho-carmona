<?php
// Standardized console header. Set $page_subtitle before including this file.
require_once __DIR__ . '/icons.php';
?>
<div class="app-header">
    <div class="brand">
        <img src="CHO.png" alt="CHO Logo" class="logo-img">
        <div class="brand-text">
            <span class="title">OPD Queueing</span>
            <span class="subtitle"><?= htmlspecialchars($page_subtitle ?? '') ?></span>
        </div>
    </div>
    <div class="header-clock">
        <?= opd_icon('clock') ?>
        <span class="clock-text">
            <span data-live-date>&nbsp;</span>
            <span class="clock-sep">&middot;</span>
            <span data-live-time>&nbsp;</span>
        </span>
    </div>
</div>

<?php
/*
 * Test script to verify database setup
 * Run this after executing database_setup.sql
 *
 * Dual-mode: unchanged plain-text output when run on the CLI (as before),
 * and the standardized page shell with the exact same check results when
 * opened in a browser.
 */

require 'config.php';
require_once 'partials/icons.php';

$is_web = (php_sapi_name() !== 'cli');

function db_check_line($text, $ok, $is_web) {
    if ($is_web) {
        $cls = $ok ? 'check-ok' : 'check-bad';
        $icon = $ok ? 'check' : 'x-circle';
        echo '<li class="check-item ' . $cls . '">' . opd_icon($icon) . '<span>' . htmlspecialchars($text) . '</span></li>' . "\n";
    } else {
        echo ($ok ? "\xe2\x9c\x93 " : "\xe2\x9c\x97 ") . $text . "\n";
    }
}

if ($is_web) {
    $page_subtitle = 'Database Check';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>OPD Queueing — Database Check</title>
<link rel="stylesheet" href="assets/theme.css">
</head>
<body>
    <?php require 'partials/header.php'; ?>
    <div class="page page-narrow">
        <h1 class="page-title">Database Setup Check</h1>
        <ul class="check-list">
    <?php
} else {
    echo "Testing database setup...\n\n";
}

// Test 1: Check if tables exist
$tables = ['doctor_appointments_log', 'daily_statistics', 'historical_data', 'settings'];
$all_tables_exist = true;

foreach ($tables as $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    if ($result && $result->num_rows > 0) {
        db_check_line("Table '$table' exists", true, $is_web);
    } else {
        db_check_line("Table '$table' does not exist", false, $is_web);
        $all_tables_exist = false;
    }
}

if ($is_web) { echo '</ul>'; }

if (!$all_tables_exist) {
    if ($is_web) {
        echo '<div class="check-summary bad">' . opd_icon('x-circle') . ' Some tables are missing. Please run database_setup.sql first.</div>';
        echo '</div>'; // .page
        require 'partials/footer.php';
        echo '</body></html>';
    } else {
        echo "\n\xe2\x9d\x8c Some tables are missing. Please run database_setup.sql first.\n";
    }
    exit(1);
}

if ($is_web) {
    echo '<div class="check-summary ok">' . opd_icon('check') . ' All tables exist!</div>';
} else {
    echo "\n\xe2\x9c\x93 All tables exist!\n\n";
}

// Test 2: Check table structure
if ($is_web) {
    echo '<h2 class="section-title" style="margin-top:28px;">Table Structure</h2>';
    echo '<ul class="check-list">';
} else {
    echo "Testing table structure...\n";
}

// Test doctor_appointments_log
$result = $conn->query("DESCRIBE doctor_appointments_log");
if ($result) {
    db_check_line("doctor_appointments_log table structure is correct", true, $is_web);
} else {
    db_check_line("Error checking doctor_appointments_log structure: " . $conn->error, false, $is_web);
}

// Test daily_statistics
$result = $conn->query("DESCRIBE daily_statistics");
if ($result) {
    db_check_line("daily_statistics table structure is correct", true, $is_web);
} else {
    db_check_line("Error checking daily_statistics structure: " . $conn->error, false, $is_web);
}

// Test historical_data
$result = $conn->query("DESCRIBE historical_data");
if ($result) {
    db_check_line("historical_data table structure is correct", true, $is_web);
} else {
    db_check_line("Error checking historical_data structure: " . $conn->error, false, $is_web);
}

// Test settings: the ticket printer keys. Their values are edited on
// printer_setup.php, so only check that each key exists.
$ticket_keys = ['ticket_printing_enabled', 'ticket_header', 'ticket_subheader', 'ticket_footer',
    'ticket_show_logo', 'ticket_show_waiting', 'ticket_width_mm'];
$stmt = $conn->prepare("SELECT `key` FROM settings");
$stmt->execute();
$present_keys = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'key');
$stmt->close();
$settings_ok = true;
foreach ($ticket_keys as $key) {
    $ok = in_array($key, $present_keys, true);
    db_check_line($ok ? "Setting '$key' exists" : "Setting '$key' is missing", $ok, $is_web);
    if (!$ok) $settings_ok = false;
}

if (!$settings_ok) {
    if ($is_web) {
        echo '</ul>';
        echo '<div class="check-summary bad">' . opd_icon('x-circle') . ' Some ticket settings are missing. Run database_setup.sql again (it is safe to re-run).</div>';
        echo '</div>'; // .page
        require 'partials/footer.php';
        echo '</body></html>';
    } else {
        echo "\n\xe2\x9d\x8c Some ticket settings are missing. Run database_setup.sql again (it is safe to re-run).\n";
    }
    exit(1);
}

if ($is_web) {
    echo '</ul>';
    echo '<div class="check-summary ok">' . opd_icon('check') . ' Database setup is complete and working!</div>';
    echo '<div class="check-next-steps"><strong>You can now:</strong>';
    echo '<ul class="check-list" style="margin-top:10px;">';
    echo '<li class="check-item">1. Use the doctor panel (doctor.php)</li>';
    echo '<li class="check-item">2. View admin reports (admin.php)</li>';
    echo '<li class="check-item">3. Schedule the daily reset (daily_reset.php)</li>';
    echo '<li class="check-item">4. Set up the ticket printer (printer_setup.php)</li>';
    echo '</ul></div>';
    echo '</div>'; // .page
    require 'partials/footer.php';
    echo '</body></html>';
} else {
    echo "\n\xe2\x9c\x93 Database setup is complete and working!\n";
    echo "\nYou can now:\n";
    echo "1. Use the doctor panel (doctor.php)\n";
    echo "2. View admin reports (admin.php)\n";
    echo "3. Schedule the daily reset (daily_reset.php)\n";
    echo "4. Set up the ticket printer (printer_setup.php)\n";
}

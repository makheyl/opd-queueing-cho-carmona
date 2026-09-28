<?php
require 'config.php';
require 'opd_functions.php';
header('Content-Type: application/json');

$waiting = $conn->query("SELECT COUNT(*) as cnt FROM queue WHERE status='waiting'");
$serving = $conn->query("SELECT COUNT(*) as cnt FROM queue WHERE status='serving'");
$pending = $conn->query("SELECT COUNT(*) as cnt FROM queue WHERE status='pending'");
$max_updated = $conn->query("SELECT MAX(GREATEST(IFNULL(created_at,0), IFNULL(served_at,0))) as last_update FROM queue");

// Added so the screens also refresh when a doctor cancels a patient, when the
// front desk confirms a patient's arrival, and at midnight (an idle display
// board still resets for the new day).
$extra = db_fetch_one($conn,
    "SELECT (SELECT COUNT(*) FROM queue WHERE status = 'cancelled') AS cancelled,
            (SELECT MAX(confirmed_at) FROM queue) AS last_confirmed,
            CURDATE() AS today"
);

echo json_encode([
    'waiting' => $waiting->fetch_assoc()['cnt'],
    'serving' => $serving->fetch_assoc()['cnt'],
    'pending' => $pending->fetch_assoc()['cnt'],
    'last_update' => $max_updated->fetch_assoc()['last_update'],
    'cancelled' => $extra['cancelled'],
    'last_confirmed' => $extra['last_confirmed'],
    'today' => $extra['today'],
]);

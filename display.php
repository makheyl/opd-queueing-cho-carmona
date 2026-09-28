<?php
require 'config.php';
require 'opd_functions.php';
$waiting = $conn->query("SELECT * FROM queue WHERE status='waiting'
    ORDER BY
        (priority='yes') DESC,
        (priority='completed') DESC,
        CASE WHEN priority='completed' AND confirmed_at IS NOT NULL THEN confirmed_at ELSE created_at END ASC,
        id ASC");
$serving = $conn->query("SELECT * FROM queue WHERE status='serving' ORDER BY created_at ASC");
$pending = $conn->query("SELECT * FROM queue WHERE status='pending' ORDER BY queue_number ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Queue Display</title>
<link rel="stylesheet" href="assets/display.css?v=<?= filemtime(__DIR__ . '/assets/display.css') ?>">
<script src="assets/board.js?v=<?= filemtime(__DIR__ . '/assets/board.js') ?>"></script>
<script>QueueBoard.start({ refreshUrl: 'display.php' });</script>
</head>
<body>
    <?php require 'partials/kiosk_header.php'; ?>
    <div class="kiosk-body">
        <div class="kiosk-stats">
            <div class="kiosk-stat">
                <span class="label">Total Waiting</span>
                <span class="value"><?php $result = $conn->query("SELECT COUNT(*) as cnt FROM queue WHERE status='waiting'"); $row = $result->fetch_assoc(); echo $row['cnt']; ?></span>
            </div>
            <div class="kiosk-stat">
                <span class="label">Currently Serving</span>
                <span class="value"><?php $result = $conn->query("SELECT COUNT(*) as cnt FROM queue WHERE status='serving'"); $row = $result->fetch_assoc(); echo $row['cnt']; ?></span>
            </div>
            <div class="kiosk-stat">
                <span class="label">Served Today</span>
                <span class="value"><?php $result = $conn->query("SELECT COUNT(*) as cnt FROM queue WHERE status='served' AND DATE(served_at) = CURDATE()"); $row = $result->fetch_assoc(); echo $row['cnt']; ?></span>
            </div>
        </div>
        <div class="kiosk-columns">
            <div class="kiosk-col waiting-col">
                <h2>Waiting Queue</h2>
                <div class="kiosk-cards">
                    <?php
                    $waiting->data_seek(0);
                    $shown_priority = false;
                    $shown_completed = false;
                    $shown_next = false;
                    while($row = $waiting->fetch_assoc()):
                        if ($row['priority'] === 'yes' || $row['priority'] === 'infectious') {
                            if ($shown_priority) continue;
                            $shown_priority = true;
                        } elseif ($row['priority'] === 'completed') {
                            if ($shown_completed) continue;
                            $shown_completed = true;
                        } elseif (!$shown_next) {
                            $shown_next = true;
                        } else {
                            continue;
                        }
                    ?>
                    <div class="kiosk-card<?php if ($row['priority'] === 'yes' || $row['priority'] === 'infectious') echo ' priority'; if ($row['priority'] === 'completed') echo ' completed'; ?>">
                        <span class="queue-number"><?= htmlspecialchars($row['queue_number']) ?></span>
                        <?php if (!empty($row['table_number'])): ?>
                        <div class="info">
                            <span class="table-num"><span class="table-label">Table</span> <?= htmlspecialchars($row['table_number']) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($row['priority'] === 'yes' || $row['priority'] === 'infectious'): ?>
                            <span class="badge badge-priority">PRIORITY</span>
                        <?php elseif ($row['priority'] === 'completed'): ?>
                            <span class="badge badge-completed">COMPLETED REQS</span>
                        <?php endif; ?>
                    </div>
                    <?php endwhile; ?>
                </div>
            </div>
            <div class="kiosk-col serving-col">
                <h2>Currently Serving</h2>
                <div class="kiosk-cards serving-cards" style="--tables: <?= OPD_TABLE_COUNT ?>;">
                    <?php
                    // One column per consultation table (OPD_TABLE_COUNT in opd_functions.php)
                    $table_patients = array_fill_keys(range(1, OPD_TABLE_COUNT), []);

                    $serving->data_seek(0);
                    while($row = $serving->fetch_assoc()) {
                        $table_num = (int) ($row['table_number'] ?? 0);
                        if (isset($table_patients[$table_num])) $table_patients[$table_num][] = $row;
                        else $table_patients[1][] = $row; // Default to table 1 if no table number
                    }
                    ?>

                    <?php foreach ($table_patients as $table_num => $patients): ?>
                    <div class="table-column">
                        <div class="table-header">Table <?= $table_num ?></div>
                        <?php foreach($patients as $row): ?>
                        <!-- id: board.js flashes this card while its number is announced -->
                        <div class="kiosk-card serving-card<?php if ($row['priority'] === 'yes' || $row['priority'] === 'infectious') echo ' priority'; if ($row['priority'] === 'completed') echo ' completed'; ?>" id="serving-<?= (int) $row['queue_number'] ?>">
                            <span class="queue-number"><?= htmlspecialchars($row['queue_number']) ?></span>
                            <?php if ($row['priority'] === 'yes' || $row['priority'] === 'infectious'): ?>
                                <span class="badge badge-priority">PRIORITY</span>
                            <?php elseif ($row['priority'] === 'completed'): ?>
                                <span class="badge badge-completed">COMPLETED</span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="kiosk-col pending-col">
                <h2>Pending Patients</h2>
                <div class="kiosk-cards">
                    <?php if ($pending && $pending->num_rows > 0): while($row = $pending->fetch_assoc()): ?>
                    <div class="kiosk-card pending">
                        <span class="queue-number"><?= htmlspecialchars($row['queue_number']) ?></span>
                        <span class="badge badge-pending">PENDING</span>
                        <?php if (!empty($row['table_number'])): ?>
                        <div class="info">
                            <span class="table-num"><span class="table-label">Table</span> <?= htmlspecialchars($row['table_number']) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endwhile; else: ?>
                    <div class="kiosk-empty">No pending patients.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php require 'partials/kiosk_footer.php'; ?>
</body>
</html>

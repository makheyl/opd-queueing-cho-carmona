<?php
require 'config.php';
require 'opd_functions.php';
session_start();

// Doctor: pick your table, then serve patients one at a time. While nobody is
// being served, the next patient of each lane is one big button; while
// someone is, SERVED is the main button and the rest are smaller.

// Function to update daily statistics
function updateDailyStatistics($conn, $doctor_name, $table_number, $action) {
    $today = date('Y-m-d');
    $doctor_name_esc = $conn->real_escape_string($doctor_name);
    $table_number_esc = (int)$table_number;

    // Check if record exists for today
    $check_sql = "SELECT id FROM daily_statistics WHERE date = '$today' AND table_number = $table_number_esc AND doctor_name = '$doctor_name_esc'";
    $result = $conn->query($check_sql);

    if ($result && $result->num_rows > 0) {
        // Update existing record
        $served_inc = ($action === 'served' ? 1 : 0);
        $pending_inc = ($action === 'pending' ? 1 : 0);
        $cancelled_inc = ($action === 'cancelled' ? 1 : 0);
        // Set total_patients equal to patients_served (count unique patients as served only)
        $update_sql = "UPDATE daily_statistics SET
            patients_served = patients_served + $served_inc,
            patients_pending = patients_pending + $pending_inc,
            patients_cancelled = patients_cancelled + $cancelled_inc,
            total_patients = patients_served + $served_inc,
            last_updated = NOW()
            WHERE date = '$today' AND table_number = $table_number_esc AND doctor_name = '$doctor_name_esc'";
        $conn->query($update_sql);
    } else {
        // Insert new record (total_patients equals number served)
        $served_val = ($action === 'served' ? 1 : 0);
        $pending_val = ($action === 'pending' ? 1 : 0);
        $cancelled_val = ($action === 'cancelled' ? 1 : 0);
        $insert_sql = "INSERT INTO daily_statistics (date, table_number, doctor_name, patients_served, patients_pending, patients_cancelled, total_patients)
            VALUES ('$today', $table_number_esc, '$doctor_name_esc', $served_val, $pending_val, $cancelled_val, $served_val)";
        $conn->query($insert_sql);
    }
}

// Function to get daily statistics for a specific table/doctor
function getDailyStatistics($conn, $doctor_name = null, $table_number = null) {
    $today = date('Y-m-d');
    $sql = "SELECT * FROM daily_statistics WHERE date = '$today'";

    if ($doctor_name) {
        $doctor_name_esc = $conn->real_escape_string($doctor_name);
        $sql .= " AND doctor_name = '$doctor_name_esc'";
    }

    if ($table_number) {
        $table_number_esc = (int)$table_number;
        $sql .= " AND table_number = $table_number_esc";
    }

    $sql .= " ORDER BY table_number ASC, doctor_name ASC";

    return $conn->query($sql);
}

// Doctor picks a number to serve
if (isset($_POST['serve'])) {
    $id = (int)$_POST['serve_id'];
    $doctor_name = $conn->real_escape_string($_POST['doctor_name']);
    $table_number = (int)$_POST['table_number'];
    $conn->query("UPDATE queue SET status='serving', doctor_name='$doctor_name', table_number=$table_number WHERE id=$id");
    // Fetch queue_number and table_number for notification
    $result = $conn->query("SELECT queue_number, table_number FROM queue WHERE id=$id LIMIT 1");
    if ($result && $row = $result->fetch_assoc()) {
        $queue_number = $row['queue_number'];
        $table_number_notify = $row['table_number'];
        // The display boards announce it (see announce_call()); this panel shows a toast.
        announce_call($queue_number, $table_number_notify);
        $_SESSION['doctor_flash'] = "Called #$queue_number to Table $table_number_notify";
    }
    header("Location: " . $_SERVER['PHP_SELF'] . "?doctor_name=" . urlencode($doctor_name) . "&table_number=" . urlencode($table_number));
    exit();
}

// Mark as served
if (isset($_POST['done'])) {
    $id = (int)$_POST['done_id'];
    $doctor_name = $conn->real_escape_string($_POST['doctor_name']);
    $table_number = isset($_POST['table_number']) ? (int)$_POST['table_number'] : '';
    $conn->query("UPDATE queue SET status='served', served_at=NOW() WHERE id=$id");
    // After updating queue status to 'served'
    $queue_number = 0;
    $result = $conn->query("SELECT queue_number FROM queue WHERE id=$id");
    if ($result && $row = $result->fetch_assoc()) {
        $queue_number = $row['queue_number'];
    }
    // Log to doctor_appointments_log
    $conn->query("INSERT INTO doctor_appointments_log (doctor_name, table_number, patient_queue_number, action) VALUES ('$doctor_name', $table_number, $queue_number, 'served')");
    // Update daily statistics
    updateDailyStatistics($conn, $doctor_name, $table_number, 'served');
    header("Location: " . $_SERVER['PHP_SELF'] . "?doctor_name=" . urlencode($doctor_name) . "&table_number=" . urlencode($table_number));
    exit();
}

// Mark as pending
if (isset($_POST['pending'])) {
    $id = (int)$_POST['pending_id'];
    $doctor_name = $conn->real_escape_string($_POST['doctor_name']);
    $table_number = isset($_POST['table_number']) ? $conn->real_escape_string($_POST['table_number']) : '';
    $sql = "UPDATE queue SET status='pending', doctor_name='$doctor_name', table_number='$table_number' WHERE id=$id";
    if (!$conn->query($sql)) {
        error_log('SQL Error: ' . $conn->error);
        echo '<div style=\'color:red;\'>SQL Error: ' . $conn->error . '</div>';
    }
    // Log to doctor_appointments_log
    $queue_number = 0;
    $result = $conn->query("SELECT queue_number FROM queue WHERE id=$id");
    if ($result && $row = $result->fetch_assoc()) {
        $queue_number = $row['queue_number'];
    }
    $conn->query("INSERT INTO doctor_appointments_log (doctor_name, table_number, patient_queue_number, action) VALUES ('$doctor_name', $table_number, $queue_number, 'pending')");
    // Update daily statistics
    updateDailyStatistics($conn, $doctor_name, $table_number, 'pending');
    header("Location: " . $_SERVER['PHP_SELF'] . "?doctor_name=" . urlencode($doctor_name) . "&table_number=" . urlencode($table_number));
    exit();
}

// Add handler for cancel action
if (isset($_POST['cancel'])) {
    $id = (int)$_POST['cancel_id'];
    $doctor_name = $conn->real_escape_string($_POST['doctor_name']);
    $table_number = isset($_POST['table_number']) ? (int)$_POST['table_number'] : '';
    $conn->query("UPDATE queue SET status='cancelled' WHERE id=$id");
    // Log to doctor_appointments_log
    $queue_number = 0;
    $result = $conn->query("SELECT queue_number FROM queue WHERE id=$id");
    if ($result && $row = $result->fetch_assoc()) {
        $queue_number = $row['queue_number'];
    }
    $conn->query("INSERT INTO doctor_appointments_log (doctor_name, table_number, patient_queue_number, action) VALUES ('$doctor_name', $table_number, $queue_number, 'cancelled')");
    // Update daily statistics
    updateDailyStatistics($conn, $doctor_name, $table_number, 'cancelled');
    header("Location: " . $_SERVER['PHP_SELF'] . "?doctor_name=" . urlencode($doctor_name) . "&table_number=" . urlencode($table_number));
    exit();
}

// Transfer a currently serving patient to another table (back to waiting)
if (isset($_POST['transfer'])) {
    $id = (int)$_POST['transfer_id'];
    $doctor_name = $conn->real_escape_string($_POST['doctor_name']);
    $new_table_number = isset($_POST['new_table_number']) ? (int)$_POST['new_table_number'] : 0;
    if ($new_table_number > 0) {
        // Move the patient back to waiting for the new table and clear doctor assignment
        $conn->query("UPDATE queue SET status='waiting', doctor_name='', table_number={$new_table_number} WHERE id={$id}");
        // Fetch queue number for logging
        $queue_number = 0;
        $result = $conn->query("SELECT queue_number FROM queue WHERE id={$id}");
        if ($result && $row = $result->fetch_assoc()) {
            $queue_number = $row['queue_number'];
        }
        // Log transfer with destination table number
        $conn->query("INSERT INTO doctor_appointments_log (doctor_name, table_number, patient_queue_number, action) VALUES ('{$doctor_name}', {$new_table_number}, {$queue_number}, 'transferred')");
    }
    // Preserve current doctor's view
    $table_number_param = isset($_POST['table_number']) ? $_POST['table_number'] : '';
    header("Location: " . $_SERVER['PHP_SELF'] . "?doctor_name=" . urlencode($doctor_name) . "&table_number=" . urlencode($table_number_param));
    exit();
}

// Add handler for notify action
if (isset($_POST['notify'])) {
    $id = (int)$_POST['notify_id'];
    $queue_number = '';
    $table_number = '';
    $result = $conn->query("SELECT queue_number, table_number FROM queue WHERE id=$id LIMIT 1");
    if ($result && $row = $result->fetch_assoc()) {
        $queue_number = $row['queue_number'];
        $table_number = $row['table_number'];
    }
    // The display boards announce it again (see announce_call()); this panel shows a toast.
    if ($queue_number !== '') {
        announce_call($queue_number, $table_number);
        $_SESSION['doctor_flash'] = "Called #$queue_number again to Table $table_number";
    }
    // Log to doctor_appointments_log
    $doctor_name = isset($_POST['doctor_name']) ? $_POST['doctor_name'] : '';
    if ($doctor_name && $table_number) {
        $conn->query("INSERT INTO doctor_appointments_log (doctor_name, table_number, patient_queue_number, action) VALUES ('$doctor_name', $table_number, $queue_number, 'notified')");
    }
    // Redirect to avoid resubmission on refresh
    $doctor_name = isset($_POST['doctor_name']) ? $_POST['doctor_name'] : '';
    $table_number_param = isset($_POST['table_number']) ? $_POST['table_number'] : '';
    header("Location: " . $_SERVER['PHP_SELF'] . "?doctor_name=" . urlencode($doctor_name) . "&table_number=" . urlencode($table_number_param));
    exit();
}

// Get doctor name and table number from GET or POST, or fallback to session
$doctor_name = '';
$table_number = '';
if (isset($_GET['doctor_name'])) {
    $doctor_name = $_GET['doctor_name'];
    $table_number = isset($_GET['table_number']) ? $_GET['table_number'] : '';
    $_SESSION['doctor_name'] = $doctor_name;
    $_SESSION['table_number'] = $table_number;
} elseif (isset($_POST['doctor_name'])) {
    $doctor_name = $_POST['doctor_name'];
    $table_number = isset($_POST['table_number']) ? $_POST['table_number'] : '';
    $_SESSION['doctor_name'] = $doctor_name;
    $_SESSION['table_number'] = $table_number;
} else {
    if (isset($_SESSION['doctor_name'])) {
        $doctor_name = $_SESSION['doctor_name'];
    }
    if (isset($_SESSION['table_number'])) {
        $table_number = $_SESSION['table_number'];
    }
}

// Always re-query after any POST action and before rendering
$doctor_name_esc = $conn->real_escape_string($doctor_name);
$table_number_esc = $conn->real_escape_string($table_number);

// Get waiting numbers (priority first) - exclude came back patients
if (!empty($doctor_name) && !empty($table_number)) {
    $waiting = $conn->query("SELECT * FROM queue WHERE status='waiting' AND priority != 'came_back' AND (table_number='' OR table_number IS NULL OR table_number='$table_number_esc')
        ORDER BY
            (priority='yes') DESC,
            (priority='completed') DESC,
            CASE WHEN priority='completed' AND confirmed_at IS NOT NULL THEN confirmed_at ELSE created_at END ASC,
            id ASC");
} else {
    $waiting = $conn->query("SELECT * FROM queue WHERE status='waiting' AND priority != 'came_back' AND (table_number='' OR table_number IS NULL)
        ORDER BY
            (priority='yes') DESC,
            (priority='completed') DESC,
            CASE WHEN priority='completed' AND confirmed_at IS NOT NULL THEN confirmed_at ELSE created_at END ASC,
            id ASC");
}

// Get serving numbers for this doctor
$serving = $conn->query("SELECT * FROM queue WHERE status='serving' AND doctor_name='" . $doctor_name_esc . "' ORDER BY created_at ASC");

// Get pending numbers for this doctor/table only
$pending = $conn->query("SELECT * FROM queue WHERE status='pending' AND table_number='$table_number_esc' ORDER BY queue_number ASC");

// Get came back numbers for this doctor/table only
$came_back = $conn->query("SELECT * FROM queue WHERE status='waiting' AND priority='came_back' AND table_number='$table_number_esc' ORDER BY created_at ASC");

// After any POST action, re-query the $came_back result set before rendering
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Re-query came_back after any update
    $came_back = $conn->query("SELECT * FROM queue WHERE status='waiting' AND priority='came_back' AND table_number='$table_number_esc' ORDER BY created_at ASC");
}

// ---------- render ----------

$show_name_card = !$doctor_name || !$table_number || isset($_GET['change']);
$flash = $_SESSION['doctor_flash'] ?? '';
unset($_SESSION['doctor_flash']);

if ($show_name_card) {
    $recent_names = get_recent_doctor_names($conn);
} else {
    // The next patient of each lane, picked exactly as before: walking the
    // waiting list in its order, the first 'yes', the first 'infectious', the
    // first 'completed', and the first of everyone else (regular or follow-up).
    $lanes = ['next' => null, 'yes' => null, 'infectious' => null, 'completed' => null];
    $lane_counts = ['next' => 0, 'yes' => 0, 'infectious' => 0, 'completed' => 0];
    while ($row = $waiting->fetch_assoc()) {
        if ($row['priority'] === 'yes') {
            $lane = 'yes';
        } elseif ($row['priority'] === 'infectious') {
            $lane = 'infectious';
        } elseif ($row['priority'] === 'completed') {
            $lane = 'completed';
        } else {
            $lane = 'next';
        }
        $lane_counts[$lane]++;
        if ($lanes[$lane] === null) {
            $lanes[$lane] = $row;
        }
    }
    $lane_buttons = [
        'next' => ['Next regular', 'N', ''],
        'yes' => ['Next priority', 'P', 'lane-priority'],
        'infectious' => ['Next infectious', 'I', 'lane-infectious'],
        'completed' => ['Next completed', 'C', 'lane-completed'],
    ];
    $anyone_waiting = array_sum($lane_counts) > 0;

    $serving_list = $serving->fetch_all(MYSQLI_ASSOC);
    $current = $serving_list[0] ?? null;
    $also_serving = array_slice($serving_list, 1);
    $pending_list = $pending->fetch_all(MYSQLI_ASSOC);
    $came_back_list = $came_back->fetch_all(MYSQLI_ASSOC);

    // Get daily statistics for all tables
    $daily_stats = getDailyStatistics($conn);
    $table_served_counts = [];
    if ($daily_stats) {
        while ($row = $daily_stats->fetch_assoc()) {
            $table_served_counts[$row['table_number']] = $row['patients_served'];
        }
    }

    // Get current doctor's statistics
    $current_doctor_stats = getDailyStatistics($conn, $doctor_name, $table_number);
    $current_served = 0;
    $current_cancelled = 0;
    if ($current_doctor_stats && $current_doctor_stats->num_rows > 0) {
        $stats = $current_doctor_stats->fetch_assoc();
        $current_served = $stats['patients_served'];
        $current_cancelled = $stats['patients_cancelled'];
    }
}

// Hidden fields every action form carries, so the handlers can redirect back here.
function identity_fields($doctor_name, $table_number) {
    return '<input type="hidden" name="doctor_name" value="' . htmlspecialchars($doctor_name) . '">'
        . '<input type="hidden" name="table_number" value="' . htmlspecialchars($table_number) . '">';
}

// SERVED plus the smaller actions for one patient being served. Keyboard
// letters only on the main (hero) patient, so no two buttons share a letter.
function render_serving_actions($row, $doctor_name, $table_number, $with_keys) {
    $id = (int) $row['id'];
    $ids = identity_fields($doctor_name, $table_number);
    $key = function ($letter) use ($with_keys) { return $with_keys ? ' data-key="' . $letter . '"' : ''; };
    $kbd = function ($letter) use ($with_keys) { return $with_keys ? '<kbd>' . $letter . '</kbd>' : ''; };
    ?>
    <div class="hero-actions">
        <form method="post">
            <input type="hidden" name="done_id" value="<?= $id ?>"><?= $ids ?>
            <button type="submit" name="done" class="btn btn-big"<?= $key('S') ?>><span>SERVED<?= $kbd('S') ?></span><small>Done with this patient</small></button>
        </form>
    </div>
    <div class="hero-more">
        <form method="post">
            <input type="hidden" name="notify_id" value="<?= $id ?>"><?= $ids ?>
            <button type="submit" name="notify" class="btn btn-outline btn-sm"<?= $key('R') ?>><?= opd_icon('bell') ?> Call again<?= $kbd('R') ?></button>
        </form>
        <form method="post">
            <input type="hidden" name="pending_id" value="<?= $id ?>"><?= $ids ?>
            <button type="submit" name="pending" class="btn btn-outline btn-sm" title="Send the patient back to complete their requirements"<?= $key('P') ?>>Pending<?= $kbd('P') ?></button>
        </form>
        <button type="button" class="btn btn-outline btn-sm" data-open-dialog="transferDialog-<?= $id ?>"<?= $key('T') ?>>Transfer<?= $kbd('T') ?></button>
        <button type="button" class="btn btn-outline btn-sm" data-open-dialog="cancelDialog-<?= $id ?>"<?= $key('C') ?>><?= opd_icon('x-circle') ?> Cancel<?= $kbd('C') ?></button>
    </div>
    <?php
}

// Transfer (pick a table) and Cancel (asks first) pop-ups for one patient.
function render_serving_dialogs($row, $doctor_name, $table_number) {
    $id = (int) $row['id'];
    $number = htmlspecialchars($row['queue_number']);
    ?>
    <div id="transferDialog-<?= $id ?>" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="transferTitle-<?= $id ?>">
        <div class="modal-box dialog-box">
            <h3 id="transferTitle-<?= $id ?>">Transfer #<?= $number ?></h3>
            <p>The patient goes back to the waiting list for the table you pick.</p>
            <form method="post">
                <input type="hidden" name="transfer_id" value="<?= $id ?>">
                <input type="hidden" name="transfer" value="1">
                <?= identity_fields($doctor_name, $table_number) ?>
                <div class="table-picker">
                    <?php for ($t = 1; $t <= OPD_TABLE_COUNT; $t++): ?>
                    <button type="submit" name="new_table_number" value="<?= $t ?>" class="btn btn-outline"><?= (string) $t === (string) $table_number ? "Table $t (yours)" : "Table $t" ?></button>
                    <?php endfor; ?>
                </div>
            </form>
            <div class="dialog-actions" style="margin-top:14px;">
                <button type="button" class="btn btn-subtle" data-close-dialog data-autofocus>Don't transfer</button>
            </div>
        </div>
    </div>
    <div id="cancelDialog-<?= $id ?>" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="cancelTitle-<?= $id ?>">
        <div class="modal-box dialog-box">
            <h3 id="cancelTitle-<?= $id ?>">Cancel #<?= $number ?>?</h3>
            <p>Use this when the patient left or won't be seen. The front desk can put them back in line later.</p>
            <form method="post" class="dialog-actions">
                <input type="hidden" name="cancel_id" value="<?= $id ?>">
                <?= identity_fields($doctor_name, $table_number) ?>
                <button type="button" class="btn btn-outline" data-close-dialog>Keep</button>
                <button type="submit" name="cancel" class="btn btn-danger">Yes, cancel</button>
            </form>
        </div>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>OPD Queueing — Doctor</title>
<link rel="stylesheet" href="assets/theme.css?v=<?= filemtime(__DIR__ . '/assets/theme.css') ?>">
</head>
<body>
    <?php
    $page_subtitle = 'Doctor';
    $nav_active = 'doctor';
    $staff_name = $show_name_card ? '' : $doctor_name . ' · Table ' . $table_number;
    $change_url = 'doctor.php?change=1';
    require 'partials/header.php';
    ?>
    <div class="page">
        <?php if ($show_name_card): ?>
        <div class="name-card">
            <h2>Who's at the table?</h2>
            <p>Type or pick your name, then tap your table.</p>
            <form method="get" class="stacked" id="doctorForm">
                <input class="field" type="text" name="doctor_name" id="doctor_name" list="recentNames" value="<?= htmlspecialchars($doctor_name) ?>" placeholder="Your name" autocomplete="off" required autofocus>
                <div class="dialog-label">Your table</div>
                <div class="table-picker">
                    <?php for ($t = 1; $t <= OPD_TABLE_COUNT; $t++): ?>
                    <button type="submit" name="table_number" value="<?= $t ?>" class="btn btn-outline<?= (string) $t === (string) $table_number ? ' is-current' : '' ?>">Table <?= $t ?></button>
                    <?php endfor; ?>
                </div>
            </form>
            <datalist id="recentNames">
                <?php foreach ($recent_names as $name): ?>
                <option value="<?= htmlspecialchars($name) ?>">
                <?php endforeach; ?>
            </datalist>
        </div>
        <?php else: ?>

        <div class="work-area">
            <section class="hero-card <?= $current ? lane_class($current['priority']) : '' ?>" aria-label="Now serving">
                <div class="hero-label">Now Serving — Table <?= htmlspecialchars($table_number) ?></div>
                <?php if ($current): ?>
                    <div class="hero-number"><?= htmlspecialchars($current['queue_number']) ?></div>
                    <?= lane_badge($current['priority']) ?>
                    <?php if (!empty($current['doctor_name']) || !empty($current['table_number'])): ?>
                    <div class="hero-meta"><?= htmlspecialchars($current['doctor_name']) ?><?= ($current['doctor_name'] && $current['table_number']) ? ' · Table ' : '' ?><?= htmlspecialchars($current['table_number']) ?></div>
                    <?php endif; ?>
                    <?php render_serving_actions($current, $doctor_name, $table_number, true); ?>
                <?php else: ?>
                    <div class="hero-empty"><?= $anyone_waiting ? 'Choose who to serve next.' : 'No one is waiting for your table.' ?></div>
                    <?php if ($anyone_waiting): ?>
                    <div class="lane-actions">
                        <?php foreach ($lane_buttons as $lane => [$label, $letter, $class]): if (!$lanes[$lane]) continue; $row = $lanes[$lane]; ?>
                        <form method="post">
                            <input type="hidden" name="serve" value="1">
                            <?= identity_fields($doctor_name, $table_number) ?>
                            <button type="submit" name="serve_id" value="<?= (int) $row['id'] ?>" class="btn btn-big <?= $class ?>" data-key="<?= $letter ?>">
                                <span><?= strtoupper($label) ?> #<?= htmlspecialchars($row['queue_number']) ?><kbd><?= $letter ?></kbd></span>
                                <small><?= $row['priority'] === 'follow_up' ? 'Follow-up · ' : '' ?><?= $lane_counts[$lane] ?> waiting</small>
                            </button>
                        </form>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <button type="button" class="btn btn-big" disabled><span>NEXT PATIENT</span><small>No one waiting</small></button>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <aside>
                <?php if ($also_serving): ?>
                <section class="side-panel" aria-label="Also serving">
                    <h3>Also with you <span class="count"><?= count($also_serving) ?></span></h3>
                    <div class="chip-list">
                        <?php foreach ($also_serving as $row): ?>
                        <button type="button" class="chip <?= lane_class($row['priority']) ?>" data-open-dialog="servingDialog-<?= (int) $row['id'] ?>"><?= htmlspecialchars($row['queue_number']) ?><small>Table <?= htmlspecialchars($row['table_number']) ?></small></button>
                        <?php endforeach; ?>
                    </div>
                    <p class="hint">Tap a number for Served, Call again, Pending, Transfer or Cancel.</p>
                </section>
                <?php endif; ?>

                <?php if ($current && $anyone_waiting): ?>
                <section class="side-panel" aria-label="Next up">
                    <h3>Next up</h3>
                    <div class="btn-row">
                        <?php foreach ($lane_buttons as $lane => [$label, $letter, $class]): if (!$lanes[$lane]) continue; $row = $lanes[$lane]; ?>
                        <form method="post">
                            <input type="hidden" name="serve" value="1">
                            <?= identity_fields($doctor_name, $table_number) ?>
                            <button type="submit" name="serve_id" value="<?= (int) $row['id'] ?>" class="btn btn-outline btn-sm <?= $class ?>"><?= htmlspecialchars(ucfirst(str_replace('Next ', '', $label))) ?> #<?= htmlspecialchars($row['queue_number']) ?></button>
                        </form>
                        <?php endforeach; ?>
                    </div>
                    <p class="hint">Finish with #<?= htmlspecialchars($current['queue_number']) ?> first, unless you need to serve two at once.</p>
                </section>
                <?php endif; ?>

                <section class="side-panel" aria-label="Came back">
                    <h3>Came back · Table <?= htmlspecialchars($table_number) ?> <span class="count"><?= count($came_back_list) ?></span></h3>
                    <?php if (!$came_back_list): ?>
                    <div class="empty-note">No one came back.</div>
                    <?php else: ?>
                    <div class="chip-list">
                        <?php foreach ($came_back_list as $row): ?>
                        <form method="post">
                            <input type="hidden" name="serve" value="1">
                            <?= identity_fields($doctor_name, $table_number) ?>
                            <button type="submit" name="serve_id" value="<?= (int) $row['id'] ?>" class="chip cameback"><?= htmlspecialchars($row['queue_number']) ?><small>Serve</small></button>
                        </form>
                        <?php endforeach; ?>
                    </div>
                    <p class="hint">Tap a number to serve that patient now.</p>
                    <?php endif; ?>
                </section>

                <section class="side-panel" aria-label="Pending">
                    <h3>Pending · Table <?= htmlspecialchars($table_number) ?> <span class="count"><?= count($pending_list) ?></span></h3>
                    <?php if (!$pending_list): ?>
                    <div class="empty-note">No one sent back for requirements.</div>
                    <?php else: ?>
                    <div class="chip-list">
                        <?php foreach ($pending_list as $row): ?>
                        <span class="chip pending"><?= htmlspecialchars($row['queue_number']) ?><small><?= htmlspecialchars($row['doctor_name']) ?></small></span>
                        <?php endforeach; ?>
                    </div>
                    <p class="hint">Getting their requirements. The front desk confirms them when they return.</p>
                    <?php endif; ?>
                </section>

                <section class="side-panel" aria-label="Today">
                    <div class="today-line">
                        You today: <strong><?= (int) $current_served ?></strong> served · <strong><?= (int) $current_cancelled ?></strong> cancelled
                        <?php if ($table_served_counts): ?>
                        <br>Served per table:
                        <?php foreach ($table_served_counts as $tnum => $cnt): ?>
                        <span class="badge badge-completed">T<?= htmlspecialchars($tnum) ?>: <?= (int) $cnt ?></span>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>
            </aside>
        </div>

        <?php
        foreach ($serving_list as $row) {
            render_serving_dialogs($row, $doctor_name, $table_number);
        }
        foreach ($also_serving as $row): ?>
        <div id="servingDialog-<?= (int) $row['id'] ?>" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="servingTitle-<?= (int) $row['id'] ?>">
            <div class="modal-box dialog-box dialog-wide">
                <h3 id="servingTitle-<?= (int) $row['id'] ?>">Also with you</h3>
                <div class="dialog-number"><?= htmlspecialchars($row['queue_number']) ?></div>
                <div class="dialog-badge"><?= lane_badge($row['priority']) ?></div>
                <p><?= htmlspecialchars($row['doctor_name']) ?><?= ($row['doctor_name'] && $row['table_number']) ? ' · Table ' : '' ?><?= htmlspecialchars($row['table_number']) ?></p>
                <div class="hero-card" style="min-height:0;padding:14px;">
                    <?php render_serving_actions($row, $doctor_name, $table_number, false); ?>
                </div>
                <div class="dialog-actions" style="margin-top:14px;">
                    <button type="button" class="btn btn-subtle" data-close-dialog data-autofocus>Close</button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <?php endif; ?>
    </div>
    <?php require 'partials/footer.php'; ?>

    <script src="assets/app.js?v=<?= filemtime(__DIR__ . '/assets/app.js') ?>"></script>
    <script>
    <?php if ($show_name_card): ?>
    // Enter in the name box would submit with the first table button; go to
    // the table buttons instead, so the doctor picks the table on purpose.
    document.getElementById('doctor_name').addEventListener('keydown', function(e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        if (!this.value.trim()) return;
        const btn = document.querySelector('#doctorForm .table-picker .is-current') || document.querySelector('#doctorForm .table-picker .btn');
        if (btn) btn.focus();
    });
    <?php else: ?>
    QueueUI.startPolling({ interval: 1000 });
    <?php if ($flash !== ''): ?>
    QueueUI.toast(<?= json_encode($flash) ?>, ' The display board announces it.', '', 5000);
    <?php endif; ?>

    // Focus the most likely next action after every reload, so Enter and the
    // keyboard letters work without a mouse click first.
    (function() {
        const target = document.querySelector('button[name=done]')
            || document.querySelector('.lane-actions button[name=serve_id]');
        if (target) target.focus();
    })();
    <?php endif; ?>
    </script>
</body>
</html>

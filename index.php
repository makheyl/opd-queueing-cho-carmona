<?php
session_start();
require 'config.php';
require 'opd_functions.php';

// Front Desk: print queue numbers (ticket station PC only), look after the
// waiting list, and handle the patients who need the desk: back with their
// requirements, cancelled by a doctor, or came back.

// Waiting numbers shown before "+N more".
const WAITING_VISIBLE_LIMIT = 30;

// Names typed on this PC's name card, newest first. Kept in a cookie (per PC)
// rather than a table: the front desk name is only shown in the header and
// nothing is logged under it, so there is no database record to suggest from.
const DESK_NAMES_COOKIE = 'opd_desk_names';

// ---------- small local helpers (index.php only) ----------

function recent_desk_names() {
    $names = json_decode($_COOKIE[DESK_NAMES_COOKIE] ?? '[]', true);
    return is_array($names) ? array_values(array_filter($names, 'is_string')) : [];
}

function remember_desk_name($name) {
    $names = array_filter(recent_desk_names(), function ($n) use ($name) {
        return strcasecmp($n, $name) !== 0;
    });
    array_unshift($names, $name);
    setcookie(DESK_NAMES_COOKIE, json_encode(array_slice($names, 0, 8)), [
        'expires' => time() + 365 * 24 * 60 * 60,
        'path' => app_base_path(),
        'samesite' => 'Lax',
    ]);
}

// Short lane word under a chip's number ('' for a regular patient).
function lane_short($priority) {
    $map = [
        'yes' => 'Priority',
        'infectious' => 'Infectious',
        'completed' => 'Reqs done',
        'came_back' => 'Came back',
        'follow_up' => 'Follow-up',
    ];
    return $map[$priority] ?? '';
}

// The data a chip hands to its pop-up.
function chip_data($row, $db_now) {
    return 'data-id="' . (int) $row['id'] . '"'
        . ' data-number="' . (int) $row['queue_number'] . '"'
        . ' data-priority="' . htmlspecialchars($row['priority']) . '"'
        . ' data-table="' . (int) $row['table_number'] . '"'
        . ' data-doctor="' . htmlspecialchars($row['doctor_name'] ?? '') . '"'
        . ' data-mins="' . (int) minutes_between($row['created_at'], $db_now) . '"'
        . ' data-today="' . (substr((string) $row['created_at'], 0, 10) === substr($db_now, 0, 10) ? '1' : '0') . '"';
}

// ---------- front desk name: asked once per shift, shown in the header ----------

if (isset($_GET['desk_name'])) {
    $new_name = trim($_GET['desk_name']);
    $_SESSION['desk_name'] = $new_name;
    if ($new_name !== '') {
        remember_desk_name($new_name);
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}
$desk_name = $_SESSION['desk_name'] ?? '';

// Only the browser marked as the ticket station on printer_setup.php (the PC
// the XP-80 is plugged into) may issue and print numbers: a click anywhere
// else would send the ticket to that PC's own printer.
$is_ticket_station = is_ticket_station();

// ---------- ticket printing (AJAX, flat {error: ''} JSON like the field updates below) ----------
// JSON rather than POST-Redirect-GET so the browser can print the ticket
// (printTicket() below) right after the number is issued, then reload.
// This replaces the old typed "Add to Queue" form: with printing OFF it still
// issues the number, and staff write it on a slip.

if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && isset($_POST['issue_ticket'])) {
    header('Content-Type: application/json');
    if (!$is_ticket_station) {
        echo json_encode(['error' => 'This PC is not set up as the ticket station (see Printer).']);
        exit();
    }
    $priority = (string) ($_POST['priority'] ?? 'no');
    if (!in_array($priority, OPD_ISSUE_PRIORITIES, true)) {
        echo json_encode(['error' => 'Unknown lane.']);
        exit();
    }
    $ticket = issue_next_number($conn, $priority);
    if (!$ticket) {
        echo json_encode(['error' => 'Could not issue a number. Please try again.']);
        exit();
    }
    echo json_encode([
        'error' => '',
        'id' => (int) $ticket['id'],
        'queue_number' => (int) $ticket['queue_number'],
        'priority' => $ticket['priority'],
        'print' => get_setting($conn, 'ticket_printing_enabled', '1') === '1',
    ]);
    exit();
}

if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && isset($_POST['reprint_ticket'])) {
    header('Content-Type: application/json');
    if (!$is_ticket_station) {
        echo json_encode(['error' => 'This PC is not set up as the ticket station (see Printer).']);
        exit();
    }
    if (get_setting($conn, 'ticket_printing_enabled', '1') !== '1') {
        echo json_encode(['error' => 'Ticket printing is OFF (see Printer).']);
        exit();
    }
    $ticket = find_today_ticket($conn, (int) ($_POST['id'] ?? 0));
    if (!$ticket) {
        echo json_encode(['error' => 'Only tickets issued today can be reprinted.']);
        exit();
    }
    echo json_encode(['error' => '', 'id' => (int) $ticket['id'], 'queue_number' => (int) $ticket['queue_number']]);
    exit();
}

// ---------- existing actions (unchanged; the pop-ups below post to these) ----------

// Doctor picks a number to serve (this section can be implemented on a separate doctor page if needed)
if (isset($_POST['serve'])) {
    $id = (int)$_POST['serve_id'];
    $doctor_name = $conn->real_escape_string($_POST['doctor_name']);
    $table_number = (int)$_POST['table_number'];
    $conn->query("UPDATE queue SET status='serving', doctor_name='$doctor_name', table_number=$table_number WHERE id=$id");
}

// Mark as served
if (isset($_POST['done'])) {
    $id = (int)$_POST['done_id'];
    $conn->query("UPDATE queue SET status='served', served_at=NOW() WHERE id=$id");
}

// Confirm pending patient arrival
if (isset($_POST['confirm_pending'])) {
    $id = (int)$_POST['confirm_id'];
    // Fetch doctor_name and table_number from the pending patient
    $result = $conn->query("SELECT doctor_name, table_number FROM queue WHERE id=$id LIMIT 1");
    $doctor_name = '';
    $table_number = '';
    if ($result && $row = $result->fetch_assoc()) {
        $doctor_name = $conn->real_escape_string($row['doctor_name']);
        $table_number = $conn->real_escape_string($row['table_number']);
    }
    // Set confirmed_at to track first confirmation time for fairness
    $conn->query("UPDATE queue SET status='waiting', priority='completed', doctor_name='$doctor_name', table_number='$table_number', confirmed_at=IF(confirmed_at IS NULL, NOW(), confirmed_at) WHERE id=$id");
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

if (isset($_POST['requeue_cancelled'])) {
    $id = (int)$_POST['confirm_cancel_id'];
    $conn->query("UPDATE queue SET status='waiting', priority='came_back' WHERE id=$id");
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}
if (isset($_POST['remove_cancelled'])) {
    $id = (int)$_POST['confirm_cancel_id'];
    $conn->query("DELETE FROM queue WHERE id=$id");
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Add PHP handler for updating priority
if (isset($_POST['update_priority_id']) && isset($_POST['update_priority_value'])) {
    $id = (int)$_POST['update_priority_id'];
    $new_priority = $_POST['update_priority_value'];
    if (in_array($new_priority, ['no', 'yes', 'infectious', 'follow_up'])) {
        $conn->query("UPDATE queue SET priority='" . $conn->real_escape_string($new_priority) . "' WHERE id=$id");
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}
// Add PHP handler for updating queue number
if (isset($_POST['update_queue_id']) && isset($_POST['update_queue_number'])) {
    $id = (int)$_POST['update_queue_id'];
    $new_number = (int)$_POST['update_queue_number'];
    $error = '';
    if ($new_number > 0) {
        $dup_check = $conn->query("SELECT id FROM queue WHERE queue_number = $new_number AND id != $id LIMIT 1");
        if ($dup_check && $dup_check->num_rows > 0) {
            $error = 'duplicate';
        } else {
            $conn->query("UPDATE queue SET queue_number=$new_number WHERE id=$id");
        }
    } else {
        $error = 'invalid';
    }
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $error]);
        exit();
    } else {
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}
// Add PHP handler for updating table number
if (isset($_POST['update_table_id']) && isset($_POST['update_table_number'])) {
    $id = (int)$_POST['update_table_id'];
    $new_table = (int)$_POST['update_table_number'];
    $error = '';
    if ($new_table >= 0) {
        $conn->query("UPDATE queue SET table_number=$new_table WHERE id=$id");
    } else {
        $error = 'invalid';
    }
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $error]);
        exit();
    } else {
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Add PHP handler for removing from waiting queue
if (isset($_POST['remove_waiting'])) {
    $id = (int)$_POST['remove_waiting_id'];
    $conn->query("DELETE FROM queue WHERE id=$id");
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// ---------- render ----------

$show_name_card = $desk_name === '' || isset($_GET['change']);

if ($show_name_card) {
    $recent_names = recent_desk_names();
} else {
    // Get waiting numbers (priority first)
    $waiting = $conn->query("SELECT * FROM queue WHERE status='waiting' ORDER BY (priority='yes') DESC, (priority='completed') DESC, created_at ASC");
    // Get serving numbers
    $serving = $conn->query("SELECT * FROM queue WHERE status='serving' ORDER BY created_at ASC");
    // Get pending numbers
    $pending = $conn->query("SELECT * FROM queue WHERE status='pending' ORDER BY queue_number ASC");
    $cancelled = $conn->query("SELECT * FROM queue WHERE status='cancelled' ORDER BY queue_number ASC");
    // Get came back numbers
    $came_back = $conn->query("SELECT * FROM queue WHERE status='waiting' AND priority='came_back' ORDER BY created_at ASC");
    $served_today = $conn->query("SELECT COUNT(*) as cnt FROM queue WHERE status='served' AND DATE(served_at) = CURDATE()")->fetch_assoc()['cnt'];

    $db_now = db_now($conn);

    // Came-back patients are listed under "Needs you", not twice.
    $waiting_list = array_values(array_filter($waiting->fetch_all(MYSQLI_ASSOC), function ($row) {
        return $row['priority'] !== 'came_back';
    }));
    $pending_list = $pending->fetch_all(MYSQLI_ASSOC);
    $cancelled_list = $cancelled->fetch_all(MYSQLI_ASSOC);
    $came_back_list = $came_back->fetch_all(MYSQLI_ASSOC);
    $needs_count = count($pending_list) + count($cancelled_list) + count($came_back_list);

    // Serving, grouped per table (a number outside 1..OPD_TABLE_COUNT is listed on its own).
    $serving_list = $serving->fetch_all(MYSQLI_ASSOC);
    $serving_by_table = array_fill_keys(range(1, OPD_TABLE_COUNT), []);
    $serving_elsewhere = [];
    foreach ($serving_list as $row) {
        $table = (int) $row['table_number'];
        if (isset($serving_by_table[$table])) {
            $serving_by_table[$table][] = $row;
        } else {
            $serving_elsewhere[] = $row;
        }
    }

    $ticket_printing_enabled = get_setting($conn, 'ticket_printing_enabled', '1') === '1';
    $can_reprint = $is_ticket_station && $ticket_printing_enabled;
    if ($is_ticket_station) {
        $next_number = next_queue_number($conn);
        $last_issued = get_last_issued($conn);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>OPD Queueing — Front Desk</title>
<link rel="stylesheet" href="assets/theme.css?v=<?= filemtime(__DIR__ . '/assets/theme.css') ?>">
</head>
<body>
    <?php
    $page_subtitle = 'Front Desk';
    $nav_active = 'front_desk';
    $staff_name = $show_name_card ? '' : $desk_name;
    $change_url = 'index.php?change=1';
    require 'partials/header.php';
    ?>
    <div class="page">
        <?php if ($show_name_card): ?>
        <div class="name-card">
            <h2>Who's at the front desk?</h2>
            <p>Type or pick your name. It stays at the top of the screen for your shift.</p>
            <form method="get">
                <input class="field" type="text" name="desk_name" list="recentNames" value="<?= htmlspecialchars($desk_name) ?>" placeholder="Your name" autocomplete="off" required autofocus>
                <button type="submit" class="btn">Start</button>
            </form>
            <datalist id="recentNames">
                <?php foreach ($recent_names as $name): ?>
                <option value="<?= htmlspecialchars($name) ?>">
                <?php endforeach; ?>
            </datalist>
        </div>
        <?php else: ?>

        <?php if ($is_ticket_station): ?>
        <div class="ticket-bar no-print">
            <button type="button" id="issueTicketBtn" class="btn btn-ticket issue-btn" data-priority="no" data-key="T"><?= $ticket_printing_enabled ? 'PRINT NEXT NUMBER' : 'ISSUE NEXT NUMBER (printing off)' ?><kbd>T</kbd></button>
            <div class="lane-buttons">
                <button type="button" class="btn btn-outline btn-sm issue-btn lane-priority" data-priority="yes" data-key="P">Priority<kbd>P</kbd></button>
                <button type="button" class="btn btn-outline btn-sm issue-btn lane-infectious" data-priority="infectious" data-key="I">Infectious<kbd>I</kbd></button>
                <button type="button" class="btn btn-outline btn-sm issue-btn lane-followup" data-priority="follow_up" data-key="F">Follow-up<kbd>F</kbd></button>
            </div>
            <span class="next-hint">Next number: <strong><?= (int) $next_number ?></strong></span>
            <?php if ($can_reprint && $last_issued): ?>
            <button type="button" class="btn btn-outline btn-sm reprint-btn" data-id="<?= (int) $last_issued['id'] ?>">Reprint #<?= (int) $last_issued['queue_number'] ?></button>
            <?php endif; ?>
        </div>
        <div id="ticketError" class="alert alert-error no-print" hidden></div>
        <?php else: ?>
        <p class="station-note no-print">Queue numbers are printed at the ticket station PC. <a href="printer_setup.php">Printer setup</a></p>
        <?php endif; ?>

        <div class="work-area">
            <section class="side-panel" aria-label="Waiting">
                <h3>Waiting <span class="count"><?= count($waiting_list) ?></span></h3>
                <?php if (empty($waiting_list)): ?>
                <div class="empty-note">No one waiting.</div>
                <?php else: ?>
                <div class="chip-list big" id="waitingChips">
                    <?php foreach ($waiting_list as $i => $row):
                        $note = implode(' · ', array_filter([lane_short($row['priority']), !empty($row['table_number']) ? 'T' . (int) $row['table_number'] : '']));
                        if ($note === '') $note = (int) minutes_between($row['created_at'], $db_now) . ' min';
                    ?>
                    <button type="button" class="chip <?= lane_class($row['priority']) ?><?= $i >= WAITING_VISIBLE_LIMIT ? ' extra' : '' ?>" data-waiting-chip <?= chip_data($row, $db_now) ?>><?= (int) $row['queue_number'] ?><small><?= htmlspecialchars($note) ?></small></button>
                    <?php endforeach; ?>
                </div>
                <?php if (count($waiting_list) > WAITING_VISIBLE_LIMIT): ?>
                <button type="button" class="link-button" id="waitingMoreBtn">+<?= count($waiting_list) - WAITING_VISIBLE_LIMIT ?> more</button>
                <?php endif; ?>
                <p class="hint">In waiting order. Tap a number to change its lane or table, fix the number<?= $can_reprint ? ', reprint its ticket' : '' ?> or remove it.</p>
                <?php endif; ?>
            </section>

            <aside>
                <section class="side-panel" aria-label="Needs you">
                    <h3>Needs you <span class="count"><?= $needs_count ?></span></h3>
                    <?php if ($needs_count === 0): ?>
                    <div class="empty-note">Nothing needs the front desk right now.</div>
                    <?php endif; ?>

                    <?php if ($pending_list): ?>
                    <div class="needs-group">
                        <h4>Back with requirements? <span class="count"><?= count($pending_list) ?></span></h4>
                        <div class="chip-list">
                            <?php foreach ($pending_list as $row): ?>
                            <button type="button" class="chip pending" data-pending-chip <?= chip_data($row, $db_now) ?>><?= (int) $row['queue_number'] ?><small><?= !empty($row['table_number']) ? 'T' . (int) $row['table_number'] : 'pending' ?></small></button>
                            <?php endforeach; ?>
                        </div>
                        <p class="hint">Sent back by a doctor. Tap the number when the patient returns.</p>
                    </div>
                    <?php endif; ?>

                    <?php if ($cancelled_list): ?>
                    <div class="needs-group">
                        <h4>Cancelled <span class="count"><?= count($cancelled_list) ?></span></h4>
                        <div class="chip-list">
                            <?php foreach ($cancelled_list as $row): ?>
                            <button type="button" class="chip cancelled" data-cancelled-chip <?= chip_data($row, $db_now) ?>><?= (int) $row['queue_number'] ?><small><?= !empty($row['table_number']) ? 'T' . (int) $row['table_number'] : 'cancelled' ?></small></button>
                            <?php endforeach; ?>
                        </div>
                        <p class="hint">Tap to put the patient back in line, or remove the number.</p>
                    </div>
                    <?php endif; ?>

                    <?php if ($came_back_list): ?>
                    <div class="needs-group">
                        <h4>Came back <span class="count"><?= count($came_back_list) ?></span></h4>
                        <div class="chip-list">
                            <?php foreach ($came_back_list as $row): ?>
                            <button type="button" class="chip cameback" data-waiting-chip <?= chip_data($row, $db_now) ?>><?= (int) $row['queue_number'] ?><small><?= !empty($row['table_number']) ? 'T' . (int) $row['table_number'] : 'any table' ?></small></button>
                            <?php endforeach; ?>
                        </div>
                        <p class="hint">Back in line for their doctor's table.</p>
                    </div>
                    <?php endif; ?>
                </section>

                <section class="side-panel" aria-label="Now serving">
                    <h3>Now serving <span class="count"><?= count($serving_list) ?></span></h3>
                    <div class="serving-list">
                        <?php foreach ($serving_by_table as $table => $rows): ?>
                        <div class="serving-row">
                            <span class="serving-table">Table <?= $table ?></span>
                            <?php if (!$rows): ?>
                            <span class="serving-idle">—</span>
                            <?php endif; ?>
                            <?php foreach ($rows as $row): ?>
                            <button type="button" class="chip <?= lane_class($row['priority']) ?>" data-serving-chip <?= chip_data($row, $db_now) ?>><?= (int) $row['queue_number'] ?><small><?= htmlspecialchars($row['doctor_name'] ?? '') ?></small></button>
                            <?php endforeach; ?>
                        </div>
                        <?php endforeach; ?>
                        <?php if ($serving_elsewhere): ?>
                        <div class="serving-row">
                            <span class="serving-table">No table</span>
                            <?php foreach ($serving_elsewhere as $row): ?>
                            <button type="button" class="chip <?= lane_class($row['priority']) ?>" data-serving-chip <?= chip_data($row, $db_now) ?>><?= (int) $row['queue_number'] ?><small><?= htmlspecialchars($row['doctor_name'] ?? '') ?></small></button>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <p class="today-line">Served today: <strong><?= (int) $served_today ?></strong></p>
                </section>
            </aside>
        </div>

        <!-- Waiting (and came-back) number: lane, table, number fix, reprint, remove -->
        <div id="waitingDialog" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="wdTitle">
            <div class="modal-box dialog-box dialog-wide">
                <h3 id="wdTitle">Waiting patient</h3>
                <div class="dialog-number" id="wdNumber"></div>
                <div class="dialog-badge" id="wdBadge"></div>
                <p id="wdMeta"></p>
                <div class="dialog-section">
                    <div class="dialog-label">Lane</div>
                    <div class="choice-row" id="wdLanes">
                        <?php foreach (OPD_ISSUE_PRIORITIES as $lane): ?>
                        <button type="button" class="btn btn-outline btn-sm" data-value="<?= $lane ?>"><?= htmlspecialchars(lane_label($lane)) ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="dialog-section">
                    <div class="dialog-label">Table</div>
                    <div class="choice-row" id="wdTables">
                        <button type="button" class="btn btn-outline btn-sm" data-value="0">Any table</button>
                        <?php for ($t = 1; $t <= OPD_TABLE_COUNT; $t++): ?>
                        <button type="button" class="btn btn-outline btn-sm" data-value="<?= $t ?>">Table <?= $t ?></button>
                        <?php endfor; ?>
                    </div>
                </div>
                <div class="dialog-section">
                    <div class="dialog-label"><label for="wdNumberInput">Correct the number</label></div>
                    <form class="inline-row" id="wdNumberForm">
                        <input class="field" type="number" id="wdNumberInput" min="1" autocomplete="off">
                        <button type="submit" class="btn btn-outline btn-sm">Save number</button>
                    </form>
                </div>
                <div id="wdError" class="alert alert-error" hidden></div>
                <div class="dialog-actions">
                    <button type="button" class="btn btn-outline" data-close-dialog data-autofocus>Close</button>
                    <?php if ($can_reprint): ?>
                    <button type="button" class="btn reprint-btn" id="wdReprint">Reprint ticket</button>
                    <?php endif; ?>
                </div>
                <button type="button" class="link-button danger" id="wdRemoveBtn">Remove from the queue…</button>
                <form method="post" class="confirm-step" id="wdRemoveStep" hidden>
                    <p id="wdRemoveText"></p>
                    <input type="hidden" name="remove_waiting_id" id="wdRemoveId">
                    <div class="dialog-actions">
                        <button type="button" class="btn btn-outline" id="wdRemoveKeep">Keep it</button>
                        <button type="submit" name="remove_waiting" class="btn btn-danger">Yes, remove</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Back with requirements: Confirm Arrival -->
        <div id="pendingDialog" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="pdTitle">
            <div class="modal-box dialog-box">
                <h3 id="pdTitle">Back with the requirements?</h3>
                <div class="dialog-number" id="pdNumber"></div>
                <p id="pdMeta"></p>
                <form method="post" class="dialog-actions">
                    <input type="hidden" name="confirm_id" id="pdId">
                    <button type="button" class="btn btn-outline" data-close-dialog>Not yet</button>
                    <button type="submit" name="confirm_pending" class="btn btn-warning">Confirm Arrival</button>
                </form>
            </div>
        </div>

        <!-- Cancelled: Requeue, or Remove (asks first) -->
        <div id="cancelledDialog" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="cdTitle">
            <div class="modal-box dialog-box">
                <h3 id="cdTitle">Cancelled patient</h3>
                <div class="dialog-number" id="cdNumber"></div>
                <p id="cdMeta"></p>
                <form method="post" id="cdForm">
                    <input type="hidden" name="confirm_cancel_id" id="cdId">
                    <div class="dialog-actions">
                        <button type="button" class="btn btn-outline" data-close-dialog>Close</button>
                        <button type="submit" name="requeue_cancelled" class="btn" id="cdRequeue">Requeue</button>
                    </div>
                    <button type="button" class="link-button danger" id="cdRemoveBtn">Remove the number…</button>
                    <div class="confirm-step" id="cdRemoveStep" hidden>
                        <p id="cdRemoveText"></p>
                        <div class="dialog-actions">
                            <button type="button" class="btn btn-outline" id="cdRemoveKeep">Keep it</button>
                            <button type="submit" name="remove_cancelled" class="btn btn-danger" id="cdRemove">Yes, remove</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Being served: Mark as Served -->
        <div id="servingDialog" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="sdTitle">
            <div class="modal-box dialog-box">
                <h3 id="sdTitle">Being served</h3>
                <div class="dialog-number" id="sdNumber"></div>
                <p id="sdMeta"></p>
                <form method="post" class="dialog-actions">
                    <input type="hidden" name="done_id" id="sdId">
                    <button type="button" class="btn btn-outline" data-close-dialog>Close</button>
                    <button type="submit" name="done" class="btn">Mark as Served</button>
                </form>
            </div>
        </div>

        <?php endif; ?>
    </div>
    <?php require 'partials/footer.php'; ?>

    <script src="assets/app.js?v=<?= filemtime(__DIR__ . '/assets/app.js') ?>"></script>
    <?php if (!$show_name_card): ?>
    <script>
    // Set while a ticket is being issued/printed (see printTicket() below).
    // Issuing a number is itself a queue change, so the poller must not reload
    // the page mid-print; printTicket() reloads when it's done.
    let ticketPrintBusy = false;
    QueueUI.startPolling({ interval: 3000, holdWhile: function() { return ticketPrintBusy; } });

    const LANE_LABELS = <?= json_encode(OPD_LANE_LABELS) ?>;
    const LANE_CLASSES = <?= json_encode(array_combine(array_keys(OPD_LANE_LABELS), array_map('lane_class', array_keys(OPD_LANE_LABELS)))) ?>;

    function byId(id) { return document.getElementById(id); }

    function laneBadge(priority) {
        const cls = LANE_CLASSES[priority];
        if (!cls) return '';
        const span = document.createElement('span');
        span.className = 'badge badge-' + cls;
        span.textContent = (LANE_LABELS[priority] || '').toUpperCase();
        return span.outerHTML;
    }

    function tableText(table) {
        return parseInt(table, 10) > 0 ? 'Table ' + table : 'Any table';
    }

    // POST to this page. xhr: true for the handlers that answer with {error} JSON.
    function post(fields, xhr) {
        const body = new URLSearchParams();
        Object.keys(fields).forEach(function(k) { body.set(k, fields[k]); });
        return fetch(location.pathname, {
            method: 'POST',
            body: body,
            headers: xhr ? { 'X-Requested-With': 'XMLHttpRequest' } : {},
        });
    }

    // --- Ticket printing (XP-80 on the ticket station PC) ---
    // A print button issues the number server-side (issue_ticket), then
    // printTicket() loads ticket_print.php into an off-screen iframe and prints
    // it. The ticket station's browser runs with --kiosk-printing, so print()
    // goes straight to the XP-80 with no dialog (see printer_setup.php).
    (function() {
        const issueBtns = Array.prototype.slice.call(document.querySelectorAll('.issue-btn'));
        const errorBox = byId('ticketError');
        const printingOn = <?= json_encode($ticket_printing_enabled) ?>;

        function printTicket(id, reprint, number) {
            // Off-screen rather than display:none: a display:none iframe prints as a blank page.
            let frame = byId('ticketPrintFrame');
            if (!frame) {
                frame = document.createElement('iframe');
                frame.id = 'ticketPrintFrame';
                frame.setAttribute('aria-hidden', 'true');
                frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:80mm;height:200mm;border:0;';
                document.body.appendChild(frame);
            }
            let finished = false;
            function done() {
                if (finished) return;
                finished = true;
                location.reload();
            }
            frame.onload = function() {
                const doc = frame.contentDocument;
                // A new iframe's initial empty document can fire its own load
                // event: wait for ticket_print.php itself.
                if (doc && doc.URL === 'about:blank') return;
                // ticket_print.php marks a stale/unknown ticket non-printable.
                if (!doc || !doc.body || doc.body.dataset.printable !== '1') {
                    QueueUI.toastAfterReload('Ticket #' + number + ' could not be printed', ' Only tickets issued today can be printed.', 'warning', 10000);
                    done();
                    return;
                }
                frame.contentWindow.addEventListener('afterprint', function() { setTimeout(done, 300); });
                frame.contentWindow.focus();
                // With --kiosk-printing, print() hands the ticket straight to the
                // XP-80 and returns at once. If it blocked for a while, the
                // browser showed its print window instead: explain after the reload.
                const started = Date.now();
                frame.contentWindow.print();
                if (Date.now() - started > 1500) {
                    QueueUI.toastAfterReload('Print window appeared',
                        ' To print tickets instantly with no window, open the Front Desk from the "OPD Queue – Front Desk" desktop shortcut (see Printer).',
                        'warning', 15000);
                }
                setTimeout(done, 2000);
            };
            // Safety net: never leave the screen stuck if the ticket page fails to load.
            setTimeout(done, 10000);
            frame.src = 'ticket_print.php?id=' + encodeURIComponent(id) + (reprint ? '&reprint=1' : '') + '&t=' + Date.now();
        }

        function showTicketError(msg) {
            ticketPrintBusy = false;
            QueueUI.closeDialog(document.querySelector('.modal-overlay.open'));
            if (errorBox) {
                errorBox.textContent = msg;
                errorBox.hidden = false;
            }
            issueBtns.forEach(function(b) {
                b.disabled = false;
                if (b.dataset.label) b.innerHTML = b.dataset.label;
            });
        }

        issueBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                // One click = one number: a double-click or a held-down key
                // lands here again while busy and is ignored.
                if (ticketPrintBusy) return;
                ticketPrintBusy = true;
                issueBtns.forEach(function(b) { b.disabled = true; });
                btn.dataset.label = btn.innerHTML;
                btn.textContent = printingOn ? 'Printing…' : 'Issuing…';
                if (errorBox) errorBox.hidden = true;
                const lane = btn.dataset.priority === 'no' ? '' : ' ' + LANE_LABELS[btn.dataset.priority] + ' lane';
                post({ issue_ticket: '1', priority: btn.dataset.priority }, true)
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res.error) {
                            showTicketError(res.error);
                        } else if (res.print) {
                            QueueUI.toastAfterReload('Ticket #' + res.queue_number + ' is printing', lane, '', 5000);
                            printTicket(res.id, false, res.queue_number);
                        } else {
                            QueueUI.toastAfterReload('Number ' + res.queue_number + ' issued' + (lane ? ' (' + lane.trim() + ')' : ''),
                                ' Ticket printing is OFF, so write the number on a slip for the patient.', 'warning', 15000);
                            location.reload();
                        }
                    })
                    .catch(function() {
                        showTicketError('Network error. The number may or may not have been issued, so check the waiting list before trying again.');
                    });
            });
        });

        // Reprint #N (ticket bar) and Reprint ticket (waiting number pop-up).
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.reprint-btn');
            if (!btn || ticketPrintBusy) return;
            ticketPrintBusy = true;
            btn.disabled = true;
            if (errorBox) errorBox.hidden = true;
            post({ reprint_ticket: '1', id: btn.dataset.id }, true)
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.error) {
                        btn.disabled = false;
                        showTicketError(res.error);
                    } else {
                        QueueUI.toastAfterReload('Ticket #' + res.queue_number + ' reprinted', '', '', 5000);
                        printTicket(res.id, true, res.queue_number);
                    }
                })
                .catch(function() {
                    btn.disabled = false;
                    showTicketError('Network error. Please try the reprint again.');
                });
        });
    })();

    // --- Waiting numbers: "+N more", and the pop-up behind each number ---
    (function() {
        const chips = byId('waitingChips');
        const moreBtn = byId('waitingMoreBtn');
        if (chips && moreBtn) {
            const moreLabel = moreBtn.textContent;
            moreBtn.addEventListener('click', function() {
                const expanded = chips.classList.toggle('show-all');
                moreBtn.textContent = expanded ? 'Show fewer' : moreLabel;
            });
        }

        const errorBox = byId('wdError');
        const numberInput = byId('wdNumberInput');
        const reprintBtn = byId('wdReprint');
        let current = null;

        function showError(msg) {
            errorBox.textContent = msg;
            errorBox.hidden = false;
        }

        function open(chip) {
            current = {
                id: chip.dataset.id,
                number: chip.dataset.number,
                priority: chip.dataset.priority,
                table: chip.dataset.table,
            };
            byId('wdTitle').textContent = current.priority === 'came_back' ? 'Came back' : 'Waiting patient';
            byId('wdNumber').textContent = current.number;
            byId('wdBadge').innerHTML = laneBadge(current.priority);
            byId('wdMeta').textContent = 'Waiting ' + chip.dataset.mins + ' min · ' + tableText(current.table)
                + (chip.dataset.doctor ? ' · ' + chip.dataset.doctor : '');
            document.querySelectorAll('#wdLanes .btn').forEach(function(b) {
                b.classList.toggle('is-current', b.dataset.value === current.priority);
            });
            document.querySelectorAll('#wdTables .btn').forEach(function(b) {
                b.classList.toggle('is-current', parseInt(b.dataset.value, 10) === (parseInt(current.table, 10) || 0));
            });
            numberInput.value = current.number;
            errorBox.hidden = true;
            byId('wdRemoveStep').hidden = true;
            byId('wdRemoveBtn').hidden = false;
            byId('wdRemoveId').value = current.id;
            byId('wdRemoveText').textContent = 'Remove #' + current.number + ' from the queue? This can\'t be undone.';
            if (reprintBtn) {
                reprintBtn.dataset.id = current.id;
                reprintBtn.disabled = false;
                reprintBtn.hidden = chip.dataset.today !== '1'; // only today's tickets print
            }
            QueueUI.openDialog('waitingDialog');
        }
        document.querySelectorAll('[data-waiting-chip]').forEach(function(chip) {
            chip.addEventListener('click', function() { open(chip); });
        });

        // Lane: the existing priority update (answers with a redirect, not JSON).
        document.querySelectorAll('#wdLanes .btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                if (!current || btn.dataset.value === current.priority) return;
                btn.disabled = true;
                post({ update_priority_id: current.id, update_priority_value: btn.dataset.value }, false)
                    .then(function(r) {
                        if (!r.ok) throw new Error('HTTP ' + r.status);
                        QueueUI.toastAfterReload('#' + current.number + ' moved to ' + LANE_LABELS[btn.dataset.value]);
                        location.reload();
                    })
                    .catch(function() {
                        btn.disabled = false;
                        showError('Could not change the lane. Please try again.');
                    });
            });
        });

        // Table: the existing table update ({error} JSON).
        document.querySelectorAll('#wdTables .btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                if (!current || parseInt(btn.dataset.value, 10) === (parseInt(current.table, 10) || 0)) return;
                btn.disabled = true;
                post({ update_table_id: current.id, update_table_number: btn.dataset.value }, true)
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res && res.error === '') {
                            QueueUI.toastAfterReload('#' + current.number + ' set to ' + tableText(btn.dataset.value).toLowerCase());
                            location.reload();
                        } else {
                            btn.disabled = false;
                            showError('Could not change the table.');
                        }
                    })
                    .catch(function() {
                        btn.disabled = false;
                        showError('Could not change the table. Please try again.');
                    });
            });
        });

        // Correct the number: the existing queue-number update ({error} JSON).
        byId('wdNumberForm').addEventListener('submit', function(e) {
            e.preventDefault();
            if (!current) return;
            const newNumber = numberInput.value.trim();
            if (newNumber === current.number) return;
            post({ update_queue_id: current.id, update_queue_number: newNumber }, true)
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res && res.error === '') {
                        QueueUI.toastAfterReload('#' + current.number + ' is now #' + newNumber);
                        numberInput.blur();
                        location.reload();
                    } else if (res && res.error === 'duplicate') {
                        showError('Number ' + newNumber + ' has already been used (today or on an earlier day), so it can\'t be used again.');
                    } else {
                        showError('Enter a number above 0.');
                    }
                })
                .catch(function() { showError('Could not save the number. Please try again.'); });
        });

        // Remove: asks first.
        byId('wdRemoveBtn').addEventListener('click', function() {
            byId('wdRemoveStep').hidden = false;
            byId('wdRemoveBtn').hidden = true;
            byId('wdRemoveKeep').focus();
        });
        byId('wdRemoveKeep').addEventListener('click', function() {
            byId('wdRemoveStep').hidden = true;
            byId('wdRemoveBtn').hidden = false;
        });
        byId('wdRemoveStep').addEventListener('submit', function() {
            QueueUI.toastAfterReload('#' + current.number + ' removed from the queue');
        });
    })();

    // --- Needs you: back with requirements, cancelled; and Now serving ---
    (function() {
        function meta(chip) {
            return [chip.dataset.doctor, parseInt(chip.dataset.table, 10) > 0 ? 'Table ' + chip.dataset.table : '']
                .filter(Boolean).join(' · ');
        }

        document.querySelectorAll('[data-pending-chip]').forEach(function(chip) {
            chip.addEventListener('click', function() {
                byId('pdNumber').textContent = chip.dataset.number;
                byId('pdId').value = chip.dataset.id;
                byId('pdMeta').textContent = 'Sent back by ' + (meta(chip) || 'a doctor')
                    + '. Once confirmed, the patient goes back in line in the Completed requirements lane.';
                QueueUI.openDialog('pendingDialog');
            });
        });
        byId('pendingDialog').querySelector('form').addEventListener('submit', function() {
            QueueUI.toastAfterReload('#' + byId('pdNumber').textContent + ' is back in line', ' Completed requirements lane');
        });

        let cancelledNumber = '';
        let cancelledAction = '';
        document.querySelectorAll('[data-cancelled-chip]').forEach(function(chip) {
            chip.addEventListener('click', function() {
                cancelledNumber = chip.dataset.number;
                byId('cdNumber').textContent = chip.dataset.number;
                byId('cdId').value = chip.dataset.id;
                byId('cdMeta').textContent = 'Cancelled' + (meta(chip) ? ' by ' + meta(chip) : '')
                    + '. Requeue puts the patient back in line as "Came back".';
                byId('cdRemoveText').textContent = 'Remove #' + chip.dataset.number + ' for good? This can\'t be undone.';
                byId('cdRemoveStep').hidden = true;
                byId('cdRemoveBtn').hidden = false;
                QueueUI.openDialog('cancelledDialog');
            });
        });
        byId('cdRemoveBtn').addEventListener('click', function() {
            byId('cdRemoveStep').hidden = false;
            byId('cdRemoveBtn').hidden = true;
            byId('cdRemoveKeep').focus();
        });
        byId('cdRemoveKeep').addEventListener('click', function() {
            byId('cdRemoveStep').hidden = true;
            byId('cdRemoveBtn').hidden = false;
        });
        byId('cdRequeue').addEventListener('click', function() { cancelledAction = 'requeue'; });
        byId('cdRemove').addEventListener('click', function() { cancelledAction = 'remove'; });
        byId('cdForm').addEventListener('submit', function() {
            if (cancelledAction === 'remove') {
                QueueUI.toastAfterReload('#' + cancelledNumber + ' removed');
            } else {
                QueueUI.toastAfterReload('#' + cancelledNumber + ' is back in line', ' Came back lane');
            }
        });

        document.querySelectorAll('[data-serving-chip]').forEach(function(chip) {
            chip.addEventListener('click', function() {
                byId('sdNumber').textContent = chip.dataset.number;
                byId('sdId').value = chip.dataset.id;
                byId('sdMeta').textContent = meta(chip) || 'No table';
                QueueUI.openDialog('servingDialog');
            });
        });
        // The existing "done" handler answers with the page itself, not a
        // redirect, so it's posted in the background: a later reload of a
        // POST result would make the browser ask to resubmit the form.
        byId('servingDialog').querySelector('form').addEventListener('submit', function(e) {
            e.preventDefault();
            const number = byId('sdNumber').textContent;
            const btn = this.querySelector('button[type=submit]');
            btn.disabled = true;
            post({ done: '1', done_id: byId('sdId').value }, false)
                .then(function(r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    QueueUI.toastAfterReload('#' + number + ' marked as served');
                    location.replace(location.pathname);
                })
                .catch(function() {
                    btn.disabled = false;
                    QueueUI.toast('Could not mark #' + number + ' as served', ' Please try again.', 'warning');
                });
        });
    })();

    // Focus the main button after every reload, so the keyboard letters and
    // Enter work without a mouse click first.
    (function() {
        const target = byId('issueTicketBtn');
        if (target) target.focus();
    })();
    </script>
    <?php endif; ?>
</body>
</html>

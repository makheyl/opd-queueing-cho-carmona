<?php
// opd_functions.php
//
// Shared logic for the OPD Queueing System: settings, issuing ticket numbers,
// the display board's call list, and small helpers the staff screens share.
// Same idea as the Lab app's queue_functions.php, fitted to OPD's own rules
// (priority lanes, consultation tables, no service_date column).
//
// Everything here uses prepared statements. The older page code still builds
// SQL by hand; leave that alone unless you are already changing it.
//
// Dates and times come from MySQL (CURDATE() / NOW()), never PHP's date():
// config.php sets no PHP timezone and php.ini's is not the clinic's, so mixing
// the two would put "today" in the wrong place.
//
// Every function takes $conn (the mysqli connection from config.php) first.
// No closing PHP tag on purpose: nothing may be output from this file.

// Consultation tables. The display board's serving columns and the doctor's
// table buttons are both built from this; change it here if a table is added.
const OPD_TABLE_COUNT = 4;

// Lanes a new ticket can be printed in (the same values the old Priority
// dropdown offered). 'completed' and 'came_back' are only ever set by staff
// actions later (Confirm Arrival, Requeue), never at the ticket station.
const OPD_ISSUE_PRIORITIES = ['no', 'yes', 'infectious', 'follow_up'];

// Plain-language name for every queue.priority value.
const OPD_LANE_LABELS = [
    'no' => 'Regular',
    'yes' => 'Priority',
    'infectious' => 'Infectious',
    'completed' => 'Completed requirements',
    'came_back' => 'Came back',
    'follow_up' => 'Follow-up',
];

// Browser cookie that marks the PC the XP-80 is plugged into. Only that PC
// shows the print buttons. Named apart from the Lab's lab_ticket_station.
const OPD_TICKET_STATION_COOKIE = 'opd_ticket_station';

// How many calls notify.json keeps for the display boards.
const ANNOUNCE_KEEP = 20;

// ---------- small database helpers ----------

/**
 * Runs a prepared SELECT and returns every row as an associative array.
 * $types/$params follow mysqli bind_param (e.g. 'si', [$name, $id]).
 */
function db_fetch_all($conn, $sql, $types = '', $params = []) {
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** First row of a prepared SELECT, or null. */
function db_fetch_one($conn, $sql, $types = '', $params = []) {
    $rows = db_fetch_all($conn, $sql, $types, $params);
    return $rows ? $rows[0] : null;
}

/** MySQL's current time ('Y-m-d H:i:s'). Use this instead of PHP's date(). */
function db_now($conn) {
    return db_fetch_one($conn, "SELECT NOW() AS now_time")['now_time'];
}

/**
 * Whole minutes from $from to $to, both MySQL datetimes, or null when $from
 * is empty. Both are read as UTC so PHP's timezone (and its daylight-saving
 * jumps) can't shift the result.
 */
function minutes_between($from, $to) {
    if (!$from || !$to) return null;
    $utc = new DateTimeZone('UTC');
    $secs = (new DateTime($to, $utc))->getTimestamp() - (new DateTime($from, $utc))->getTimestamp();
    return max(0, intdiv($secs, 60));
}

/** A MySQL datetime as "Mon, Sep 28, 2026 · 9:05 AM", with no timezone shift. */
function format_ticket_datetime($datetime) {
    return (new DateTime($datetime, new DateTimeZone('UTC')))->format('D, M j, Y · g:i A');
}

// ---------- settings ----------

/**
 * Reads one settings.value by key, or $default if the key is missing. Also
 * returns $default if the settings table doesn't exist yet (database_setup.sql
 * not run), so the screens keep working until it is.
 */
function get_setting($conn, $key, $default = null) {
    try {
        $stmt = $conn->prepare("SELECT `value` FROM settings WHERE `key` = ?");
        if (!$stmt) return $default;
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        return $default;
    }
    return $row ? $row['value'] : $default;
}

/**
 * Creates or overwrites one settings row. Used by printer_setup.php, the only
 * screen that edits settings.
 */
function set_setting($conn, $key, $value) {
    $stmt = $conn->prepare(
        "INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)"
    );
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
    $stmt->close();
}

function settings_table_exists($conn) {
    return db_fetch_one($conn, "SHOW TABLES LIKE 'settings'") !== null;
}

// ---------- ticket numbers ----------

/**
 * The number PRINT NEXT NUMBER would issue right now:
 *   today's highest queue_number + 1 (so the first ticket of the day is 1),
 *   then + 1 again while that number is still active (waiting / serving /
 *   pending) on ANY date — the same rows the old manual Add form refused to
 *   duplicate. OPD has no service_date column and no unique key on the
 *   number, so this check is the only thing keeping active numbers unique.
 *
 * Shown as the "Next number: N" hint. issue_next_number() calls it under a
 * lock; without the lock, two callers can get the same answer.
 */
function next_queue_number($conn) {
    $row = db_fetch_one($conn,
        "SELECT COALESCE(MAX(queue_number), 0) + 1 AS next_number FROM queue
         WHERE created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY"
    );
    $number = (int) $row['next_number'];

    $stmt = $conn->prepare(
        "SELECT 1 FROM queue WHERE queue_number = ? AND status IN ('waiting', 'serving', 'pending') LIMIT 1"
    );
    $stmt->bind_param('i', $number); // bound by reference: each execute() sees the new $number
    while (true) {
        $stmt->execute();
        $taken = $stmt->get_result()->num_rows > 0;
        if (!$taken) break;
        $number++;
    }
    $stmt->close();
    return $number;
}

/**
 * Issues the next queue number as a new 'waiting' ticket in the given lane —
 * what the ticket station's print buttons call instead of a typed number. The
 * number lives in the database, not in the printer, so a printed ticket always
 * matches what display.php shows and announces.
 *
 * Race safety: with no unique key to lean on, compute + insert run under a
 * MySQL named lock, so two clicks at the same instant get different numbers
 * (test_issue_number.php races 10 processes against this). A caller that
 * can't get the lock within 5 s gets null and nothing is inserted.
 *
 * Deliberately NOT logged to doctor_appointments_log: admin.php builds its
 * reports from that table, and extra rows would change the numbers.
 *
 * Returns the inserted row, or null for an unknown lane or a lock timeout.
 */
function issue_next_number($conn, $priority) {
    if (!in_array($priority, OPD_ISSUE_PRIORITIES, true)) {
        return null;
    }
    $lock = db_fetch_one($conn, "SELECT GET_LOCK('opd_issue_number', 5) AS got");
    if ((string) $lock['got'] !== '1') {
        return null;
    }
    try {
        $number = next_queue_number($conn);
        $stmt = $conn->prepare(
            "INSERT INTO queue (queue_number, priority, status, created_at) VALUES (?, ?, 'waiting', NOW())"
        );
        $stmt->bind_param('is', $number, $priority);
        $stmt->execute();
        $id = $conn->insert_id;
        $stmt->close();
    } finally {
        db_fetch_one($conn, "SELECT RELEASE_LOCK('opd_issue_number') AS released");
    }
    return find_queue_row($conn, $id);
}

/** Today's most recently issued ticket, for "Reprint #N". Null before the first one. */
function get_last_issued($conn) {
    return db_fetch_one($conn,
        "SELECT * FROM queue WHERE created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY
         ORDER BY id DESC LIMIT 1"
    );
}

function find_queue_row($conn, $id) {
    return db_fetch_one($conn, "SELECT * FROM queue WHERE id = ?", 'i', [(int) $id]);
}

/** The queue row with this id if it was issued today, else null. Only today's tickets print. */
function find_today_ticket($conn, $id) {
    return db_fetch_one($conn,
        "SELECT * FROM queue WHERE id = ? AND created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY",
        'i', [(int) $id]
    );
}

/**
 * Everyone currently waiting, in every lane. Printed on the ticket as a plain
 * total: OPD's lanes mean a lower number isn't necessarily called first, so
 * the ticket never claims "N ahead of you".
 */
function count_waiting($conn) {
    return (int) db_fetch_one($conn, "SELECT COUNT(*) AS cnt FROM queue WHERE status = 'waiting'")['cnt'];
}

// ---------- ticket station ----------

/** This app's folder as a URL path, e.g. /PHPOPDQueueing/ — the ticket station cookie's scope. */
function app_base_path() {
    return rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'])), '/') . '/';
}

/** True in the browser marked as the ticket station on printer_setup.php. */
function is_ticket_station() {
    return ($_COOKIE[OPD_TICKET_STATION_COOKIE] ?? '') === '1';
}

// ---------- voice announcements ----------

/**
 * Queues a voice call for the display boards ("Queue number 12, queue number
 * 12, please proceed to table 2"). notify.json keeps the last ANNOUNCE_KEEP
 * calls as a list instead of one slot, so calls made seconds apart are all
 * announced, in order, and every open board hears every call: boards remember
 * the newest id they've spoken and nothing ever deletes entries (which is why
 * clear_notify.php is no longer used). Locked, so two calls in the same
 * instant can't overwrite each other.
 */
function announce_call($queue_number, $table_number) {
    $fp = fopen(__DIR__ . '/notify.json', 'c+');
    if (!$fp) {
        error_log('announce_call: could not open notify.json');
        return false;
    }
    flock($fp, LOCK_EX);
    $data = json_decode(stream_get_contents($fp), true);
    $calls = (is_array($data) && isset($data['calls']) && is_array($data['calls'])) ? $data['calls'] : [];

    // Ids must strictly increase: boards speak every id newer than the last one they spoke.
    $id = microtime(true);
    $last = end($calls);
    if ($last && isset($last['id']) && (float) $last['id'] >= $id) {
        $id = (float) $last['id'] + 0.001;
    }
    $calls[] = [
        'id' => $id,
        'queue_number' => (int) $queue_number,
        'table_number' => (int) $table_number,
    ];
    $calls = array_slice($calls, -ANNOUNCE_KEEP);

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode(['calls' => $calls]));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

// ---------- staff names ----------

/**
 * Doctor names used in the last 30 days, most recent first: suggestions for
 * the doctor's name card, so doctors pick their name instead of retyping it
 * (and the reports get fewer spelling variants).
 */
function get_recent_doctor_names($conn, $limit = 20) {
    $rows = db_fetch_all($conn,
        "SELECT doctor_name FROM doctor_appointments_log
         WHERE doctor_name <> '' AND log_time >= NOW() - INTERVAL 30 DAY
         GROUP BY doctor_name ORDER BY MAX(log_time) DESC LIMIT ?",
        'i', [(int) $limit]
    );
    return array_column($rows, 'doctor_name');
}

// ---------- lanes ----------

function lane_label($priority) {
    return OPD_LANE_LABELS[$priority] ?? OPD_LANE_LABELS['no'];
}

/** CSS modifier used for chips and cards (theme.css), '' for a regular patient. */
function lane_class($priority) {
    $map = [
        'yes' => 'priority',
        'infectious' => 'infectious',
        'completed' => 'completed',
        'came_back' => 'cameback',
        'follow_up' => 'followup',
    ];
    return $map[$priority] ?? '';
}

/** A badge for the patient's lane, or '' for a regular patient. */
function lane_badge($priority) {
    $class = lane_class($priority);
    if ($class === '') return '';
    return '<span class="badge badge-' . $class . '">' . htmlspecialchars(strtoupper(lane_label($priority))) . '</span>';
}

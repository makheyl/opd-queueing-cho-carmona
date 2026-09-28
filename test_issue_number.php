<?php
/*
 * CLI test for the ticket numbers the Front Desk print buttons hand out
 * (issue_next_number() in opd_functions.php), plus the call list the display
 * boards read (announce_call()).
 *
 * Run it ONLY against a throwaway copy of the database: it inserts and deletes
 * queue rows. It refuses to start unless the queue table is empty, and removes
 * every row it created when it's done. notify.json is put back as it was.
 *
 *   C:\xampp\php\php.exe test_issue_number.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Run this from the command line.\n");
}

require 'config.php';
require 'opd_functions.php';

$pass_count = 0;
$fail_count = 0;

function check($label, $condition) {
    global $pass_count, $fail_count;
    if ($condition) {
        echo "✓ $label\n";
        $pass_count++;
    } else {
        echo "✗ $label\n";
        $fail_count++;
    }
}

echo "Testing ticket numbers against database '$db'...\n\n";

$existing = (int) db_fetch_one($conn, "SELECT COUNT(*) AS cnt FROM queue")['cnt'];
if ($existing > 0) {
    echo "✗ The queue table in '$db' has $existing row(s). This test only runs against an empty,\n";
    echo "  throwaway copy of the database (see README_DATABASE_SETUP.md). Nothing was changed.\n";
    exit(1);
}

$created_ids = [];

// A row as if created $days_ago days ago (0 = today), for the "earlier day" cases.
function insert_row($conn, $number, $status, $priority, $days_ago) {
    global $created_ids;
    $stmt = $conn->prepare(
        "INSERT INTO queue (queue_number, status, priority, created_at) VALUES (?, ?, ?, NOW() - INTERVAL ? DAY)"
    );
    $stmt->bind_param('issi', $number, $status, $priority, $days_ago);
    $stmt->execute();
    $created_ids[] = $conn->insert_id;
    $stmt->close();
    return $conn->insert_id;
}

function issue($conn, $priority) {
    global $created_ids;
    $row = issue_next_number($conn, $priority);
    if ($row) $created_ids[] = (int) $row['id'];
    return $row;
}

function clean($conn) {
    global $created_ids;
    if (empty($created_ids)) return;
    $ids = array_map('intval', $created_ids);
    $stmt = $conn->prepare("DELETE FROM queue WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $stmt->close();
    $created_ids = [];
}

function row_count($conn) {
    return (int) db_fetch_one($conn, "SELECT COUNT(*) AS cnt FROM queue")['cnt'];
}

$notify_path = __DIR__ . '/notify.json';
$notify_backup = file_exists($notify_path) ? file_get_contents($notify_path) : null;

try {
    // --- 1. Consecutive numbers, lane and row shape ---
    check('an empty day starts at 1', next_queue_number($conn) === 1);
    $first = issue($conn, 'no');
    $second = issue($conn, 'yes');
    $third = issue($conn, 'follow_up');
    check('first ticket is #1', $first && (int) $first['queue_number'] === 1);
    check('second ticket is #2 (consecutive)', $second && (int) $second['queue_number'] === 2);
    check('third ticket is #3 (consecutive)', $third && (int) $third['queue_number'] === 3);
    check("new tickets are 'waiting'", $first && $first['status'] === 'waiting' && $second['status'] === 'waiting');
    check('the lane is stored as given', $first['priority'] === 'no' && $second['priority'] === 'yes' && $third['priority'] === 'follow_up');
    check('no doctor/table is set on a new ticket', $first['doctor_name'] === null && $first['table_number'] === null);
    check('the next-number hint matches what would be issued', next_queue_number($conn) === 4);

    // --- 2. The lane whitelist rejects junk ---
    $before = row_count($conn);
    $junk = [issue($conn, 'vip'), issue($conn, "no' OR '1'='1"), issue($conn, ''), issue($conn, 'completed'), issue($conn, 'came_back')];
    check('junk and staff-only lanes are rejected (null returned)', $junk === [null, null, null, null, null]);
    check('nothing was inserted for a rejected lane', row_count($conn) === $before);

    // --- 3. Restarts at 1 on a new day ---
    clean($conn);
    for ($n = 1; $n <= 40; $n++) {
        insert_row($conn, $n, 'served', 'no', 1);
    }
    check("yesterday's numbers don't carry over: next is 1", next_queue_number($conn) === 1);
    $today_first = issue($conn, 'no');
    check("today's first ticket is #1", $today_first && (int) $today_first['queue_number'] === 1);

    // --- 4. Skips numbers still active from an earlier day ---
    clean($conn);
    insert_row($conn, 1, 'serving', 'no', 1);
    insert_row($conn, 2, 'pending', 'no', 2);
    insert_row($conn, 3, 'served', 'no', 1);
    insert_row($conn, 4, 'waiting', 'yes', 1);
    insert_row($conn, 6, 'cancelled', 'no', 1);
    $a = issue($conn, 'no');
    check('skips #1 (serving) and #2 (pending) from earlier days, issues #3 (served)', $a && (int) $a['queue_number'] === 3);
    $b = issue($conn, 'no');
    check('skips #4 (still waiting from yesterday), issues #5', $b && (int) $b['queue_number'] === 5);
    $c = issue($conn, 'no');
    check('a cancelled number from an earlier day does not block: issues #6', $c && (int) $c['queue_number'] === 6);

    // --- 5. Only today's tickets are printable ---
    $old_id = insert_row($conn, 90, 'waiting', 'no', 1);
    check("find_today_ticket() finds today's ticket", find_today_ticket($conn, $c['id']) !== null);
    check("find_today_ticket() refuses yesterday's ticket", find_today_ticket($conn, $old_id) === null);
    check('get_last_issued() returns the newest of today', ($last = get_last_issued($conn)) && (int) $last['id'] === (int) $c['id']);

    // --- 6. 10 parallel issuers get 10 distinct, gap-free numbers ---
    // Real OS-level concurrency: each worker is a separate php.exe process with
    // its own MySQL connection, all started before any is waited on.
    clean($conn);
    $base = next_queue_number($conn);
    $config_path = addslashes(__DIR__ . '/config.php');
    $functions_path = addslashes(__DIR__ . '/opd_functions.php');
    $descriptorspec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $procs = [];
    $pipes_by_worker = [];
    for ($i = 0; $i < 10; $i++) {
        $worker_code = "require '$config_path'; require '$functions_path'; "
            . "\$r = issue_next_number(\$conn, 'no'); echo \$r ? \$r['id'] . ':' . \$r['queue_number'] : 'NULL';";
        $procs[$i] = proc_open([PHP_BINARY, '-r', $worker_code], $descriptorspec, $pipes);
        $pipes_by_worker[$i] = $pipes;
    }
    $numbers = [];
    for ($i = 0; $i < 10; $i++) {
        $out = trim(stream_get_contents($pipes_by_worker[$i][1]));
        $err = trim(stream_get_contents($pipes_by_worker[$i][2]));
        fclose($pipes_by_worker[$i][1]);
        fclose($pipes_by_worker[$i][2]);
        proc_close($procs[$i]);
        if ($err !== '') echo "  (worker $i stderr: $err)\n";
        if (preg_match('/(\d+):(\d+)$/', $out, $m)) {
            $created_ids[] = (int) $m[1];
            $numbers[] = (int) $m[2];
        } else {
            echo "  (worker $i output: $out)\n";
        }
    }
    check('all 10 parallel issuers got a number', count($numbers) === 10);
    check('the 10 numbers are all different', count(array_unique($numbers)) === 10);
    sort($numbers);
    check('the 10 numbers have no gaps', $numbers === range($base, $base + 9));

    // --- 7. announce_call(): ordered, capped list every board can read ---
    file_put_contents($notify_path, '{}');
    for ($n = 1; $n <= 25; $n++) {
        announce_call($n, ($n % OPD_TABLE_COUNT) + 1);
    }
    $data = json_decode(file_get_contents($notify_path), true);
    $calls = $data['calls'] ?? [];
    check('notify.json keeps the last ' . ANNOUNCE_KEEP . ' calls', count($calls) === ANNOUNCE_KEEP);
    $ids = array_column($calls, 'id');
    $increasing = true;
    for ($i = 1; $i < count($ids); $i++) {
        if (!($ids[$i] > $ids[$i - 1])) $increasing = false;
    }
    check('call ids strictly increase', $increasing);
    check('the newest call is last, with its table', end($calls)['queue_number'] === 25 && end($calls)['table_number'] === 2);
    file_put_contents($notify_path, 'not json');
    announce_call(7, 3);
    $data = json_decode(file_get_contents($notify_path), true);
    check('a damaged notify.json is replaced by a fresh list', isset($data['calls']) && count($data['calls']) === 1);
} finally {
    clean($conn);
    if ($notify_backup !== null) {
        file_put_contents($notify_path, $notify_backup);
    }
    echo "\n(test rows removed, notify.json restored)\n";
}

echo "\n$pass_count passed, $fail_count failed.\n";
exit($fail_count > 0 ? 1 : 0);

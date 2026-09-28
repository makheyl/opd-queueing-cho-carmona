<?php
require 'config.php';

// Inputs: base date and period
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$period = isset($_GET['period']) ? $_GET['period'] : 'day'; // 'day' | 'week' | 'month'

// Compute start and end dates (inclusive) based on period
$base = DateTime::createFromFormat('Y-m-d', $date) ?: new DateTime();
$startDate = clone $base;
$endDate = clone $base;
if ($period === 'week') {
    // ISO week: Monday to Sunday
    $weekday = (int)$base->format('N'); // 1 (Mon) - 7 (Sun)
    $startDate->modify('-' . ($weekday - 1) . ' days');
    $endDate = (clone $startDate)->modify('+6 days');
} elseif ($period === 'month') {
    $startDate->modify('first day of this month');
    $endDate->modify('last day of this month');
}
$startDateStr = $startDate->format('Y-m-d');
$endDateStr = $endDate->format('Y-m-d');

// Human-friendly label for the range, e.g. "Today · Monday, Sep 28, 2026"
$today = date('Y-m-d'); // same clock as the default $date above
$is_today_base = $base->format('Y-m-d') === $today;
if ($period === 'day') {
    $periodLabel = ($startDateStr === $today ? 'Today · ' : '') . $startDate->format('l, M j, Y');
} elseif ($period === 'week') {
    $periodLabel = ($is_today_base ? 'This week · ' : 'Week of ') . $startDate->format('M j') . ' – ' . $endDate->format('M j, Y');
} else {
    $periodLabel = ($is_today_base ? 'This month · ' : '') . $base->format('F Y');
}
$periodLabel = htmlspecialchars($periodLabel);

// Plain words for doctor_appointments_log.action in the detailed log. Codes
// not listed here are shown as they are.
const ACTION_LABELS = [
    'served' => 'Served',
    'pending' => 'Sent back for requirements',
    'cancelled' => 'Cancelled',
    'notified' => 'Called again',
    'transferred' => 'Transferred',
];

function action_label($action) {
    return ACTION_LABELS[$action] ?? $action;
}

// Width of a volume bar, as a percentage of the largest value.
function bar_width($value, $max) {
    return $max > 0 ? max(1, round((int) $value / $max * 100)) : 0;
}

// Fetch logs for the selected range, sorted by table_number then time
$stmt = $conn->prepare("SELECT doctor_name, table_number, patient_queue_number, action, log_time FROM doctor_appointments_log WHERE DATE(log_time) BETWEEN ? AND ? ORDER BY table_number ASC, log_time DESC");
$stmt->bind_param('ss', $startDateStr, $endDateStr);
$stmt->execute();
$result = $stmt->get_result();

$logs = [];
$total = 0;
while ($row = $result->fetch_assoc()) {
    $logs[] = $row;
    $total++;
}

// Fetch daily statistics for the selected range
$daily_stats_sql = "SELECT * FROM daily_statistics WHERE date BETWEEN ? AND ? ORDER BY date ASC, table_number ASC, doctor_name ASC";
$daily_stmt = $conn->prepare($daily_stats_sql);
$daily_stmt->bind_param('ss', $startDateStr, $endDateStr);
$daily_stmt->execute();
$daily_result = $daily_stmt->get_result();

$daily_stats = [];
while ($row = $daily_result->fetch_assoc()) {
    $daily_stats[] = $row;
}

// Fallback: if no rows in daily_statistics for the selected range, compute live from logs
if (count($daily_stats) === 0) {
    $fallback_sql = "SELECT DATE(log_time) AS date, table_number,
                            SUM(CASE WHEN action = 'served' THEN 1 ELSE 0 END) AS patients_served,
                            SUM(CASE WHEN action = 'pending' THEN 1 ELSE 0 END) AS patients_pending,
                            SUM(CASE WHEN action = 'cancelled' THEN 1 ELSE 0 END) AS patients_cancelled,
                            SUM(CASE WHEN action = 'served' THEN 1 ELSE 0 END) AS total_patients
                     FROM doctor_appointments_log
                     WHERE DATE(log_time) BETWEEN ? AND ?
                     GROUP BY DATE(log_time), table_number
                     ORDER BY date ASC, table_number ASC";
    $fb_stmt = $conn->prepare($fallback_sql);
    $fb_stmt->bind_param('ss', $startDateStr, $endDateStr);
    $fb_stmt->execute();
    $fb_res = $fb_stmt->get_result();
    while ($row = $fb_res->fetch_assoc()) {
        $daily_stats[] = $row;
    }
}

// Helper: normalize doctor names to reduce duplicates from typos/titles/punctuation
function normalizeDoctorName($name) {
    $name = strtolower(trim($name ?? ''));
    if ($name === '') return '';
    // Replace common title variants with a canonical token 'doc'
    $name = preg_replace('/\b(dr\.?|doc\.?|doctor)\b/', 'doc', $name);
    // Remove punctuation and dashes
    $name = preg_replace('/[\.,\-_\/]+/', ' ', $name);
    // Collapse multiple spaces
    $name = preg_replace('/\s+/', ' ', $name);
    // Trim again
    $name = trim($name);
    return $name;
}

// Build grouped daily statistics by date + table_number (ignore name differences)
$daily_stats_by_table = [];
foreach ($daily_stats as $row) {
    $key = $row['date'] . '|' . $row['table_number'];
    if (!isset($daily_stats_by_table[$key])) {
        $daily_stats_by_table[$key] = [
            'date' => $row['date'],
            'table_number' => $row['table_number'],
            'patients_served' => 0,
            'patients_pending' => 0,
            'patients_cancelled' => 0,
        ];
    }
    $daily_stats_by_table[$key]['patients_served'] += (int)$row['patients_served'];
    $daily_stats_by_table[$key]['patients_pending'] += (int)$row['patients_pending'];
    $daily_stats_by_table[$key]['patients_cancelled'] += (int)$row['patients_cancelled'];
}
// Sort grouped stats by date then table
usort($daily_stats_by_table, function($a, $b) {
    if ($a['date'] === $b['date']) {
        return (int)$a['table_number'] <=> (int)$b['table_number'];
    }
    return strcmp($a['date'], $b['date']);
});

// Handle CSV export (use daily_statistics over the selected range)
if (isset($_POST['export_csv'])) {
    $export_date = $_POST['export_date'] ?? $date;
    $export_period = $_POST['export_period'] ?? $period;

    $export_base = DateTime::createFromFormat('Y-m-d', $export_date) ?: new DateTime();
    $export_start = clone $export_base;
    $export_end = clone $export_base;
    if ($export_period === 'week') {
        $weekday = (int)$export_base->format('N');
        $export_start->modify('-' . ($weekday - 1) . ' days');
        $export_end = (clone $export_start)->modify('+6 days');
    } elseif ($export_period === 'month') {
        $export_start->modify('first day of this month');
        $export_end->modify('last day of this month');
    }
    $export_start_str = $export_start->format('Y-m-d');
    $export_end_str = $export_end->format('Y-m-d');

    $export_sql = "SELECT date, table_number, doctor_name, patients_served, patients_pending, patients_cancelled, total_patients
                   FROM daily_statistics
                   WHERE date BETWEEN ? AND ?
                   ORDER BY date ASC, table_number ASC, doctor_name ASC";
    $export_stmt = $conn->prepare($export_sql);
    $export_stmt->bind_param('ss', $export_start_str, $export_end_str);
    $export_stmt->execute();
    $export_result = $export_stmt->get_result();

    // Buffer results to detect empty dataset
    $export_rows = [];
    while ($row = $export_result->fetch_assoc()) {
        $export_rows[] = $row;
    }

    // If no pre-aggregated stats, compute live from logs for the export range
    if (count($export_rows) === 0) {
        $fb_sql = "SELECT DATE(log_time) AS date, table_number,
                          NULL AS doctor_name,
                          SUM(CASE WHEN action = 'served' THEN 1 ELSE 0 END) AS patients_served,
                          SUM(CASE WHEN action = 'pending' THEN 1 ELSE 0 END) AS patients_pending,
                          SUM(CASE WHEN action = 'cancelled' THEN 1 ELSE 0 END) AS patients_cancelled,
                          SUM(CASE WHEN action = 'served' THEN 1 ELSE 0 END) AS total_patients
                   FROM doctor_appointments_log
                   WHERE DATE(log_time) BETWEEN ? AND ?
                   GROUP BY DATE(log_time), table_number
                   ORDER BY date ASC, table_number ASC";
        $fb_stmt = $conn->prepare($fb_sql);
        $fb_stmt->bind_param('ss', $export_start_str, $export_end_str);
        $fb_stmt->execute();
        $fb_res = $fb_stmt->get_result();
        while ($row = $fb_res->fetch_assoc()) {
            $export_rows[] = $row;
        }
    }

    // Set headers for CSV download
    header('Content-Type: text/csv');
    $filename_suffix = $export_period === 'day' ? $export_start_str : ($export_period === 'week' ? ($export_start_str . '_to_' . $export_end_str) : $export_base->format('Y_m'));
    header('Content-Disposition: attachment; filename="report_' . $export_period . '_' . $filename_suffix . '.csv"');

    $output = fopen('php://output', 'w');
    // CSV header
    fputcsv($output, ['Date', 'Table Number', 'Doctor Name', 'Patients Served', 'Patients Pending', 'Patients Cancelled', 'Total Patients']);
    // Rows
    foreach ($export_rows as $row) {
        fputcsv($output, [
            $row['date'],
            $row['table_number'],
            $row['doctor_name'] ?? '',
            $row['patients_served'],
            $row['patients_pending'],
            $row['patients_cancelled'],
            $row['total_patients']
        ]);
    }
    fclose($output);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>OPD Queueing — Reports</title>
<link rel="stylesheet" href="assets/theme.css?v=<?= filemtime(__DIR__ . '/assets/theme.css') ?>">
</head>
<body>
    <?php $page_subtitle = 'Reports'; $nav_active = 'reports'; require 'partials/header.php'; ?>
    <div class="page page-wide">
        <div class="report-toolbar no-print">
            <div class="period-picker">
                <a class="btn btn-sm <?= $period === 'day' && $startDateStr === $today ? '' : 'btn-outline' ?>" href="?period=day&amp;date=<?= $today ?>">Today</a>
                <a class="btn btn-sm <?= $period === 'week' && $is_today_base ? '' : 'btn-outline' ?>" href="?period=week&amp;date=<?= $today ?>">This Week</a>
                <a class="btn btn-sm <?= $period === 'month' && $is_today_base ? '' : 'btn-outline' ?>" href="?period=month&amp;date=<?= $today ?>">This Month</a>
                <form method="get" id="otherDayForm">
                    <input type="hidden" name="period" value="day">
                    <label for="otherDay">Other day</label>
                    <input class="field" type="date" id="otherDay" name="date" value="<?= htmlspecialchars($base->format('Y-m-d')) ?>" max="<?= $today ?>">
                </form>
                <?php if (!$is_today_base): ?>
                <span class="other-periods">
                    See the whole
                    <a href="?period=week&amp;date=<?= htmlspecialchars($base->format('Y-m-d')) ?>">week</a> or
                    <a href="?period=month&amp;date=<?= htmlspecialchars($base->format('Y-m-d')) ?>">month</a>
                </span>
                <?php endif; ?>
            </div>
            <div class="report-actions">
                <form method="post">
                    <input type="hidden" name="export_date" value="<?= htmlspecialchars($date) ?>">
                    <input type="hidden" name="export_period" value="<?= htmlspecialchars($period) ?>">
                    <button type="submit" name="export_csv" class="btn btn-sm"><?= opd_icon('download') ?> Export CSV</button>
                </form>
                <button type="button" class="btn btn-outline btn-sm" onclick="window.print()"><?= opd_icon('printer') ?> Print</button>
            </div>
        </div>

        <h1 class="reports-title"><?= $periodLabel ?></h1>

        <!-- Daily Statistics Section -->
        <div class="stats-section">
            <?php if (count($daily_stats) > 0): ?>
                <div class="stats-grid" style="margin-top:0;">
                    <?php
                    $total_served = 0;
                    $total_pending = 0;
                    $total_cancelled = 0;
                    $total_patients = 0;

                    foreach ($daily_stats as $stat) {
                        $total_served += $stat['patients_served'];
                        $total_pending += $stat['patients_pending'];
                        $total_cancelled += $stat['patients_cancelled'];
                        // For reporting purposes: count each patient once per session
                        // Treat 'Total Patients' as equal to number served
                        $total_patients += $stat['patients_served'];
                    }
                    $max_served = max(array_map(function ($s) { return (int) $s['patients_served']; }, $daily_stats_by_table));
                    ?>
                    <div class="mini-stat">
                        <h4>Served</h4>
                        <div class="value"><?= $total_served ?></div>
                    </div>
                    <div class="mini-stat">
                        <h4>Sent back for requirements</h4>
                        <div class="value"><?= $total_pending ?></div>
                    </div>
                    <div class="mini-stat">
                        <h4>Cancelled</h4>
                        <div class="value"><?= $total_cancelled ?></div>
                    </div>
                    <div class="mini-stat">
                        <h4>Total patients (served)</h4>
                        <div class="value"><?= $total_patients ?></div>
                    </div>
                </div>

                <table class="data-table" style="margin-top: 20px;">
                    <tr>
                        <th>Date</th>
                        <th>Table</th>
                        <th>Patients Served</th>
                        <th>Patients Pending</th>
                        <th>Patients Cancelled</th>
                        <th>Total (Served)</th>
                    </tr>
                    <?php foreach ($daily_stats_by_table as $stat): ?>
                        <tr>
                            <td><?= htmlspecialchars($stat['date']) ?></td>
                            <td><?= htmlspecialchars($stat['table_number']) ?></td>
                            <td><div class="bar-row"><span><?= htmlspecialchars($stat['patients_served']) ?></span><div class="bar-track"><div class="bar" style="width:<?= bar_width($stat['patients_served'], $max_served) ?>%;"></div></div></div></td>
                            <td><?= htmlspecialchars($stat['patients_pending']) ?></td>
                            <td><?= htmlspecialchars($stat['patients_cancelled']) ?></td>
                            <td><?= htmlspecialchars($stat['patients_served']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php else: ?>
                <p style="text-align:center; color:var(--text-faint);">No patients recorded for this period.</p>
            <?php endif; ?>
        </div>

        <div class="no-print" style="text-align:center; margin-top:22px;">
            <button class="btn btn-outline" type="button" id="logToggle" onclick="toggleDetails()">Show detailed log</button>
        </div>
        <div id="detailed-report" style="display:none; margin-top:12px;">
        <table class="data-table">
            <tr>
                <th>Table</th>
                <th>Time</th>
                <th>Doctor</th>
                <th>Patient Queue #</th>
                <th>Action</th>
            </tr>
            <?php if (count($logs) > 0): ?>
                <?php foreach ($logs as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['table_number']) ?></td>
                        <td><?= htmlspecialchars($row['log_time']) ?></td>
                        <td><?= htmlspecialchars($row['doctor_name']) ?></td>
                        <td><?= htmlspecialchars($row['patient_queue_number']) ?></td>
                        <td><?= htmlspecialchars(action_label($row['action'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="5">Total Appointments: <?= $total ?></td>
                </tr>
            <?php else: ?>
                <tr><td colspan="5" class="no-logs">Nothing logged for this period.</td></tr>
            <?php endif; ?>
        </table>
        </div>
        <?php if (count($logs) > 0): ?>
        <?php
            // Build totals per table with a list of unique normalized doctor names
            $table_totals_details = [];
            foreach ($logs as $row) {
                $tableNumber = $row['table_number'];
                $doctorNormalized = normalizeDoctorName($row['doctor_name']);
                if (!isset($table_totals_details[$tableNumber])) {
                    $table_totals_details[$tableNumber] = [
                        'count' => 0,
                        'doctors' => []
                    ];
                }
                $table_totals_details[$tableNumber]['count']++;
                if ($doctorNormalized !== '' && !in_array($doctorNormalized, $table_totals_details[$tableNumber]['doctors'], true)) {
                    $table_totals_details[$tableNumber]['doctors'][] = $doctorNormalized;
                }
            }
            ksort($table_totals_details, SORT_NATURAL);
            $max_table_count = max(array_column($table_totals_details, 'count'));
        ?>
        <div class="stats-section">
            <h3>Total Appointments per Table (doctor names shown)</h3>
            <table class="data-table">
                <tr>
                    <th>Table</th>
                    <th>Doctor Name(s)</th>
                    <th>Total Appointments</th>
                </tr>
                <?php foreach ($table_totals_details as $tableNumber => $info): ?>
                <tr>
                    <td><?= htmlspecialchars($tableNumber) ?></td>
                    <td><?= htmlspecialchars(implode(', ', $info['doctors'])) ?></td>
                    <td><div class="bar-row"><span><?= $info['count'] ?></span><div class="bar-track"><div class="bar" style="width:<?= bar_width($info['count'], $max_table_count) ?>%;"></div></div></div></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <p class="hint no-print" style="text-align:center;color:var(--text-faint);font-size:0.8rem;margin:0;">Counts every logged action at the table: served, sent back, cancelled, called again and transferred.</p>
        </div>
        <?php endif; ?>
    </div>
    <?php require 'partials/footer.php'; ?>

    <script>
    function toggleDetails() {
        var el = document.getElementById('detailed-report');
        var open = el.style.display === 'none' || el.style.display === '';
        el.style.display = open ? 'block' : 'none';
        document.getElementById('logToggle').textContent = open ? 'Hide detailed log' : 'Show detailed log';
    }

    // "Other day" applies as soon as a date is picked: no Filter button.
    document.getElementById('otherDay').addEventListener('change', function() {
        if (this.value) this.form.submit();
    });
    </script>
</body>
</html>

<?php
// ticket_print.php
//
// Renders ONE OPD queue ticket sized for the XP-80's 80 mm roll (72 mm
// printable by default, settings.ticket_width_mm). index.php and
// printer_setup.php load this page into an off-screen iframe and call print()
// on it. The ticket PC's browser is started with --kiosk-printing, so the job
// goes straight to the XP-80 with no dialog, and the Windows driver cuts after
// each document. See OPERATIONS.md "Ticket printer".
//
// Read-only on purpose: numbers are issued by index.php's issue_ticket
// handler, so reloading or previewing this page never changes the queue.
//
//   ?id=N              today's ticket with queue.id = N
//   ?id=N&reprint=1    the same ticket, marked REPRINT
//   ?test=1            sample ticket (000) for printer_setup.php
require 'config.php';
require 'opd_functions.php';
header('Cache-Control: no-store');

$is_test = isset($_GET['test']);
$is_reprint = isset($_GET['reprint']);

$header = trim((string) get_setting($conn, 'ticket_header', 'CITY HEALTH OFFICE'));
$subheader = trim((string) get_setting($conn, 'ticket_subheader', 'OUTPATIENT DEPARTMENT'));
$footer = trim((string) get_setting($conn, 'ticket_footer', ''));
$show_logo = get_setting($conn, 'ticket_show_logo', '1') === '1';
$show_waiting = get_setting($conn, 'ticket_show_waiting', '1') === '1';
$width_mm = max(48, min(80, (int) get_setting($conn, 'ticket_width_mm', 72)));

// Only today's tickets are printable. An old or unknown id renders a
// non-printable page (data-printable="0"), which the print helper checks
// before calling print(), so it never wastes paper on an error message.
$printable = false;
$number_text = '';
$lane_text = '';
$issued_text = '';
$waiting_text = null;

if ($is_test) {
    $printable = true;
    $number_text = '000';
    $issued_text = format_ticket_datetime(db_now($conn));
    $waiting_text = $show_waiting ? 'Patients waiting now: 3' : null;
} else {
    $ticket = find_today_ticket($conn, (int) ($_GET['id'] ?? 0));
    if ($ticket) {
        $printable = true;
        $number_text = (string) $ticket['queue_number'];
        // Regular tickets carry no lane line; every other lane is printed.
        $lane_text = $ticket['priority'] !== 'no' ? strtoupper(lane_label($ticket['priority'])) : '';
        $issued_text = format_ticket_datetime($ticket['created_at']);
        // A plain total, not "ahead of you": lanes can be called out of number order.
        if ($show_waiting && $ticket['status'] === 'waiting') {
            $waiting_text = 'Patients waiting now: ' . count_waiting($conn);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= $printable ? 'Ticket ' . htmlspecialchars($number_text) : 'Ticket not available' ?></title>
<style>
    /* Zero page margin: nothing is clipped at the roll edges, and the browser
       has no margin area left to draw its date/URL header and footer into. */
    @page { margin: 0; }
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; background: #fff; color: #000; }
    body {
        width: <?= $width_mm ?>mm;
        padding: 3mm 2mm 4mm;
        font-family: Arial, Helvetica, sans-serif;
        text-align: center;
    }
    /* Thermal paper prints black only: the color logo is grayscaled so the
       driver's dithering stays clean. */
    .logo { display: block; width: 18mm; height: 18mm; margin: 0 auto 1.5mm; object-fit: contain; filter: grayscale(1) contrast(1.3); }
    .header { font-size: 13pt; font-weight: 800; line-height: 1.15; }
    .subheader { font-size: 11pt; font-weight: 700; margin-top: 0.5mm; }
    .rule { border: 0; border-top: 1px dashed #000; margin: 2.5mm 0; }
    .label { font-size: 9pt; font-weight: 700; letter-spacing: 0.15em; }
    .number { font-family: 'Arial Black', Arial, sans-serif; font-size: 64pt; font-weight: 900; line-height: 1; margin: 1mm 0 2mm; }
    .lane { display: inline-block; border: 0.6mm solid #000; padding: 0.8mm 3mm; margin: 0 0 2mm; font-size: 13pt; font-weight: 900; letter-spacing: 0.08em; }
    .meta { font-size: 9pt; line-height: 1.4; }
    .footer { font-size: 9pt; line-height: 1.35; }
    .mark { font-size: 9pt; font-weight: 800; letter-spacing: 0.1em; margin-top: 2.5mm; }
    .unavailable { font-size: 10pt; padding: 6mm 0; }
</style>
</head>
<body data-printable="<?= $printable ? '1' : '0' ?>">
<?php if (!$printable): ?>
    <div class="unavailable">Ticket not available.</div>
<?php else: ?>
    <?php if ($show_logo): ?>
    <img class="logo" src="CHO.png" alt="">
    <?php endif; ?>
    <?php if ($header !== ''): ?>
    <div class="header"><?= htmlspecialchars($header) ?></div>
    <?php endif; ?>
    <?php if ($subheader !== ''): ?>
    <div class="subheader"><?= htmlspecialchars($subheader) ?></div>
    <?php endif; ?>
    <hr class="rule">
    <div class="label">QUEUE NUMBER</div>
    <div class="number"><?= htmlspecialchars($number_text) ?></div>
    <?php if ($lane_text !== ''): ?>
    <div class="lane"><?= htmlspecialchars($lane_text) ?></div>
    <?php endif; ?>
    <div class="meta"><?= htmlspecialchars($issued_text) ?></div>
    <?php if ($waiting_text !== null): ?>
    <div class="meta"><?= htmlspecialchars($waiting_text) ?></div>
    <?php endif; ?>
    <?php if ($footer !== ''): ?>
    <hr class="rule">
    <div class="footer"><?= nl2br(htmlspecialchars($footer)) ?></div>
    <?php endif; ?>
    <?php if ($is_test): ?>
    <div class="mark">TEST PRINT – NOT A QUEUE NUMBER</div>
    <?php elseif ($is_reprint): ?>
    <div class="mark">*** REPRINT ***</div>
    <?php endif; ?>
<?php endif; ?>
</body>
</html>

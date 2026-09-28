# OPD Queueing System — Operations Guide

Day-to-day procedures for the front desk, the doctors, and whoever is on duty
when something goes wrong. For tables, setup and file layout see
`README_DATABASE_SETUP.md`.

## Screens at a glance

Every staff screen has the same green top menu: **Front Desk · Doctor ·
Reports · Printer**. Each screen does one job, and the next thing to press is
the biggest button. The letter on a button (`T`, `P`, `I`, `F`, `N`, `C`, `S`,
`R`) is its keyboard shortcut. Every pop-up closes with Escape.

| Screen | File | Who | What it's for |
|---|---|---|---|
| Front Desk | `index.php` | Front desk staff | Print queue numbers (ticket PC only), fix a waiting patient's lane/table/number, confirm patients back with requirements, requeue cancelled patients |
| Doctor | `doctor.php` | Doctors | Pick your table, serve the next patient of a lane, Served / Call again / Pending / Transfer / Cancel |
| Reports | `admin.php` | Supervisor | Today / This Week / This Month numbers, detailed log, CSV export, print |
| Printer | `printer_setup.php` | Whoever sets up the ticket PC | Ticket station, ticket text, Test Print, setup checklist, display board shortcut |
| Display board | `display.php` | Waiting-area TV | Read-only; shows the queue and calls numbers out loud |

**Front Desk in short:** press **PRINT NEXT NUMBER** (`T`) for a regular
patient, or the smaller **Priority** (`P`), **Infectious** (`I`) or
**Follow-up** (`F`) button. The lane is printed on the ticket. Tap any number
under **Waiting** to change its lane or table, correct the number, reprint the
ticket, or remove it. Under **Needs you**:

- **Back with requirements?** Tap the number when the patient returns with
  their requirements, then **Confirm Arrival**. They go back in line in the
  Completed requirements lane.
- **Cancelled**: tap, then **Requeue** (they go back in line as "Came back")
  or **Remove the number** (asks first).
- **Came back**: already back in line for their doctor's table.

**Doctor in short:** pick your name and tap your table (once per shift). When
nobody is with you, each lane that has someone waiting shows one big button,
such as **NEXT REGULAR #45** (`N`), **NEXT PRIORITY #12** (`P`), **NEXT
INFECTIOUS** (`I`) or **NEXT COMPLETED #30** (`C`). One tap calls that patient
and the display board announces it. While a patient is with you, **SERVED**
(`S`) is the big button. The smaller ones are **Call again** (`R`),
**Pending** (`P`, patient goes to get requirements), **Transfer** (`T`, pick
another table) and **Cancel** (`C`, asks first). **Came back** patients for
your table are served with one tap from the side panel.

Screens refresh themselves when the queue changes, but never while you are
typing or have a pop-up open. They wait until you're done.

## Daily opening procedure

1. Confirm XAMPP's Apache and MySQL are running (XAMPP Control Panel).
2. On the waiting-area TV PC, open the display board from the **"OPD Queue –
   Display Board"** shortcut (see Printer → "Display Board PC"). It runs full
   screen and speaks without anyone tapping it. Leave it open all day; it
   updates itself and never needs a reload.
3. On the front desk PC, switch on the XP-80 and check the paper (see "Ticket
   printer" below). Open the Front Desk from the **"OPD Queue – Front Desk"**
   desktop shortcut, not a normal browser window. Pick your name on the
   "Who's at the front desk?" card.
4. Optional: press **Test Print** on the Printer screen to check the printer
   before the first patient.
5. Each doctor opens **Doctor**, picks their name and taps their table.

Numbers don't need resetting. PRINT NEXT NUMBER issues today's highest number
+ 1, so the first ticket of the day is number 1. A number that is still
active from an earlier day (waiting, being served, or pending) is skipped
until that patient is finished, so no two active patients ever share a number.
If yesterday's patients were never marked as served, clear them first: tap
them under **Now serving** on the Front Desk → **Mark as Served**.

## Ticket printer (XP-80)

Queue numbers are no longer typed. The front desk clicks **PRINT NEXT NUMBER**
(or a lane button). The server issues the next number and the XP-80 prints
it. The number on the ticket is always the number the display board shows and
the voice calls.

**How it's wired.** The OPD's XP-80 is plugged by USB into the **front desk
PC**, not into the XAMPP server. That PC's browser prints the ticket: the
"OPD Queue – Front Desk" shortcut starts Edge with `--kiosk-printing`, which
prints straight to the printer with no dialog. Only the browser marked as the
ticket station on the Printer screen shows the print buttons. Other PCs can't
issue numbers, so nobody can print a ticket on the wrong printer by accident.
This is a separate printer from the Laboratory's; the two apps don't share
any setting or cookie.

### One-time setup

Follow the checklist on the **Printer** screen (`printer_setup.php`, in the
top menu). It shows the exact shortcut for this server. In short:

1. Install the Xprinter 80 mm driver and name the printer `XP-80C OPD`. Set
   the paper size to the 80 mm roll (e.g. `80(72.1) x 3276 mm`, not A4/297 mm)
   and the cutter to cut after each document.
2. Make it the Windows default printer, with **"Let Windows manage my default
   printer" turned OFF**.
3. Create the "OPD Queue – Front Desk" shortcut:
   `msedge.exe --kiosk-printing --user-data-dir="C:\OPDQueueEdge" --app=http://<server>/PHPOPDQueueing/index.php`
   (the Printer screen also shows a Chrome version, for a PC that has Chrome
   instead of Edge; the XAMPP server PC has Edge only).
4. Open the shortcut, click **Printer**, **Use this PC as the ticket station**,
   then **Test Print**. A ticket should print and cut with no dialog.

If the Lab's XP-80 ever ends up on the same PC, see the "If the Lab's XP-80 is
on the same PC" section on the Printer screen. That setup relies on each
browser profile remembering its last printer, and must be checked with Test
Print on the real printers.

### Loading paper

Use an 80 mm thermal roll. Open the cover and drop the roll in with the coated
side facing the print head. To find the coated side, scratch the paper with a
fingernail: the coated side turns dark. Pull a few centimeters out and close
the cover until it clicks.

### When a ticket doesn't print

The number is issued as soon as the button is clicked, whether or not paper
comes out. **Don't click it again.** Fix the printer, then use **Reprint #N**
in the ticket bar (or tap the number under Waiting → **Reprint ticket**).

1. Check the paper, the cover (fully closed?), the power and the USB cable.
2. Open the Windows print queue: Settings → Printers & scanners → XP-80C OPD →
   Open print queue. If jobs are stuck, cancel them *before* fixing the
   printer. Otherwise every stuck ticket prints at once when the printer comes
   back. Then reprint only what's needed.
3. If a "Print window appeared" message pops up, the Front Desk wasn't opened
   from the shortcut. Close every window of that browser profile and reopen it
   from the shortcut.
4. If the ticket came out of a different printer, Windows changed the default
   printer. Set XP-80C OPD as the default again and check that "Let Windows
   manage my default printer" is OFF.
5. If the right edge is cut off, lower **Print width** on the Printer screen
   (e.g. to 70).

### If the printer is down for a while (printing OFF)

On the Printer screen, untick **Ticket printing is ON** and click Save. The
big button changes to **ISSUE NEXT NUMBER (printing off)**. Numbers are still
issued in order and shown on screen; a message reminds you to write each one on
a slip for the patient. Turn printing back on when the printer is fixed.

### Reprinting

**Reprint #N** in the ticket bar reprints the most recent ticket. To reprint
any other ticket from today (for example, a patient lost theirs), tap its
number under **Waiting** and choose **Reprint ticket**. Reprinted tickets say
REPRINT. Only today's tickets can be reprinted.

## Correcting a waiting patient

Tap the number under **Waiting** on the Front Desk:

- **Lane**: Regular, Priority, Infectious or Follow-up.
- **Table**: Any table, or a specific table.
- **Correct the number**: for a ticket with the wrong number. A number that
  has ever been used before (any day) is refused, so pick an unused one.
- **Remove from the queue**: asks first, cannot be undone.

## Session behavior

The Front Desk and Doctor screens are meant to stay open for a whole shift. If
the browser session is ever dropped, nothing is lost. The screen shows the
name card again; pick your name (and table) and everything picks up where it
was. To hand over mid-shift, click **change** next to the name in the
top-right corner.

## Voice announcements and the display board

Only the display board speaks. The Doctor screen shows a small "Called #12 to
Table 2" message instead. The sentence is "Queue number 12, queue number 12,
please proceed to table 2".

Every call goes into a short list (`notify.json`, the last 20 calls). Each
display board speaks every new call once, in order, with a short pause between
calls, so numbers called seconds apart are all announced and several boards
can run at once. `clear_notify.php` is legacy and no longer used.

If the board looks stuck or silent:

1. **Screen asleep, or browser frozen?** A hard refresh (Ctrl+F5) is always
   safe. The board is read-only and rebuilds everything from the database.
2. **Apache/MySQL still running** on the server? If not, every screen is stuck.
3. **Numbers update but nothing is spoken:**
   - Check the speakers, the volume, and that the browser tab isn't muted.
   - Browsers keep sound off on a page nobody has touched yet. Opened from the
     "OPD Queue – Display Board" shortcut
     (`--kiosk --autoplay-policy=no-user-gesture-required`), the board is exempt
     and speaks from the first call. Opened any other way, it switches sound on
     silently the moment anyone touches the screen or presses a key. No message
     is shown, so the fix is to tap the screen once, or use the shortcut so no
     tap is ever needed. After a tap it announces the last few calls it missed.
   - Several calls at once take a few seconds to get through; they are never
     cut off.
4. **Last resort:** reload the tab. No queue state is lost.

## Access

No screen has a login; access control is the clinic's local network. The
ticket station mark is a browser cookie, not a security control: it only
decides which PC shows the print buttons.

## Files not to open casually

- `clear_queue.php` deletes **every row in the queue table**, every day, every
  status, as soon as the page is opened. It is meant for Task Scheduler only.
  Don't open it in a browser.
- `test_issue_number.php` is for a throwaway copy of the database only (it
  refuses to run if the queue has any rows).

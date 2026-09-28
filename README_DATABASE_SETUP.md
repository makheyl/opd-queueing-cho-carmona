# OPD Queueing System - Database Setup & Daily Reset

## Overview
This system now includes comprehensive doctor appointment tracking with automatic daily statistics reset and historical data archiving.

## Database Tables

### 1. `doctor_appointments_log`
Tracks all doctor actions (serve, pending, cancel, notify) with timestamps.

```sql
CREATE TABLE IF NOT EXISTS `doctor_appointments_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `doctor_name` varchar(100) NOT NULL,
  `table_number` int(11) NOT NULL,
  `patient_queue_number` int(11) NOT NULL,
  `action` enum('served','pending','cancelled','notified') NOT NULL DEFAULT 'served',
  `log_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_doctor_name` (`doctor_name`),
  KEY `idx_table_number` (`table_number`),
  KEY `idx_log_time` (`log_time`),
  KEY `idx_date` (DATE(`log_time`))
);
```

### 2. `daily_statistics`
Tracks daily counts per doctor/table combination. Automatically resets each day.

```sql
CREATE TABLE IF NOT EXISTS `daily_statistics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `table_number` int(11) NOT NULL,
  `doctor_name` varchar(100) NOT NULL,
  `patients_served` int(11) DEFAULT 0,
  `patients_pending` int(11) DEFAULT 0,
  `patients_cancelled` int(11) DEFAULT 0,
  `total_patients` int(11) DEFAULT 0,
  `last_updated` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_date_table_doctor` (`date`, `table_number`, `doctor_name`)
);
```

### 3. `historical_data`
Stores archived daily statistics for historical reporting and CSV export.

```sql
CREATE TABLE IF NOT EXISTS `historical_data` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `table_number` int(11) NOT NULL,
  `doctor_name` varchar(100) NOT NULL,
  `patients_served` int(11) DEFAULT 0,
  `patients_pending` int(11) DEFAULT 0,
  `patients_cancelled` int(11) DEFAULT 0,
  `total_patients` int(11) DEFAULT 0,
  `exported_to_csv` tinyint(1) DEFAULT 0,
  `export_date` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_date_table_doctor` (`date`, `table_number`, `doctor_name`)
);
```

### 4. `settings`
Key/value settings for the ticket printer. They are edited on the **Printer** screen (`printer_setup.php`), not by hand.

```sql
CREATE TABLE IF NOT EXISTS `settings` (
  `key` varchar(50) NOT NULL,
  `value` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`key`)
);
```

| Key | Default | What it does |
|---|---|---|
| `ticket_printing_enabled` | `1` | `0` = printing OFF: numbers are still issued, staff write them on a slip |
| `ticket_header` | `CITY HEALTH OFFICE` | First line of the ticket |
| `ticket_subheader` | `OUTPATIENT DEPARTMENT` | Second line |
| `ticket_footer` | "Please keep this ticket…" | Text under the number (may be blank) |
| `ticket_show_logo` | `1` | Print the CHO logo |
| `ticket_show_waiting` | `1` | Print "Patients waiting now: N" (a plain total, not "ahead of you") |
| `ticket_width_mm` | `72` | Printable width of the 80 mm roll (48–80) |

The seed rows use `ON DUPLICATE KEY UPDATE key = key`, so re-running the script adds missing keys and keeps values already changed on the Printer screen.

## Setup Instructions

### 1. Run Database Setup
Execute the SQL commands in `database_setup.sql` to create the required tables:

```bash
mysql -u root -p opdqueue < database_setup.sql
```

**One-time step after the ticket printer update.** Existing installations need the new `settings` table. Run the script once on the server (it is safe to re-run: existing tables and edited settings are left alone):

```
C:\xampp\mysql\bin\mysql.exe -u root opdqueue < C:\xampp\htdocs\PHPOPDQueueing\database_setup.sql
```

Then open `test_database.php` (or run `C:\xampp\php\php.exe test_database.php`). Every line should have a check mark, including the `settings` table and its seven `ticket_*` keys. `config.php` does **not** create this table by itself: until the script is run, tickets print with the default text and the Printer screen shows a warning instead of saving.

### 2. Schedule Daily Reset
Set up the daily reset script to run automatically at midnight:

#### Windows Task Scheduler:
1. Open Task Scheduler
2. Create Basic Task
3. Name: "OPD Daily Reset"
4. Trigger: Daily at 12:00:00 AM
5. Action: Start a program
   - Program: `C:\xampp\php\php.exe`
   - Arguments: `C:\xampp\htdocs\PHPOPDQueueing\daily_reset.php`

#### Linux Cron:
```bash
# Add to crontab (crontab -e)
0 0 * * * /usr/bin/php /path/to/PHPOPDQueueing/daily_reset.php
```

## Features

### 1. Real-time Statistics
- **Doctor Panel**: Shows current day's statistics (served, pending, cancelled)
- **Table Badges**: Displays served counts for all tables
- **Auto-refresh**: Updates automatically when data changes

### 2. Daily Reset System
- **Automatic Archiving**: Moves daily statistics to historical_data table
- **CSV Export**: Automatically exports daily reports to CSV files
- **Data Cleanup**: Removes old queue and log data
- **Zero Downtime**: Resets happen at midnight without affecting operations

### 3. Admin Reporting
- **Daily Statistics**: View comprehensive daily statistics
- **Historical Data**: Access past records and trends
- **CSV Export**: Download reports in CSV format
- **Print Support**: Print-friendly reports

### 4. Database Monitoring
- **Performance Indexes**: Optimized queries with proper indexing
- **Data Integrity**: Foreign key constraints and validation
- **Backup Ready**: Structured for easy backup and restore

## File Structure

```
PHPOPDQueueing/
├── config.php                 # Database configuration
├── database_setup.sql         # Database table creation (incl. settings)
├── opd_functions.php          # Shared logic: settings, ticket numbers, voice call list
├── daily_reset.php            # Daily reset script
├── index.php                  # Front Desk: print numbers, waiting list, "Needs you"
├── doctor.php                 # Doctor: pick a table, serve patients by lane
├── admin.php                  # Reports: Today / Week / Month, CSV export, print
├── printer_setup.php          # Printer: ticket station, ticket settings, Test Print
├── ticket_print.php           # The 80 mm ticket page (read-only, printed by the browser)
├── display.php                # Waiting-area display board (speaks the calls)
├── display_backgrounded.php   # Alternative display board layout (same voice)
├── queue_status.php           # Small JSON the screens poll to know when to refresh
├── notify.json                # Runtime file: last 20 voice calls (not committed)
├── clear_notify.php           # Legacy, no longer used (see below)
├── test_database.php          # Checks the tables and settings
├── test_issue_number.php      # Ticket number tests (throwaway database only)
├── assets/
│   ├── theme.css              # Staff screens
│   ├── display.css            # Display board
│   ├── app.js                 # Staff screens: refresh, pop-ups, shortcuts, toasts
│   ├── board.js               # Display board: voice calls and in-place refresh
│   └── clock.js               # Live clock in the headers
├── partials/                  # Shared header (with the top menu), footer, icons
├── exports/                   # CSV export directory
│   └── daily_report_YYYY-MM-DD.csv
├── OPERATIONS.md              # Day-to-day guide: screens, printer, voice
└── README_DATABASE_SETUP.md   # This file
```

### Voice calls and `notify.json`
When a doctor presses **Serve** or **Call again**, `announce_call()` (in `opd_functions.php`) appends the call to `notify.json`, which keeps the last 20 calls. Every open display board reads the list and speaks each new call once, in order. Nothing deletes entries, so several boards can run at once.

`clear_notify.php` is **legacy**. It emptied `notify.json` after one board had spoken, which meant only one board heard each call and fast calls cut each other off. No screen calls it anymore. The file is kept in the folder, unused; don't link to it.

### Testing changes
Never test against the live `opdqueue` database. Make a throwaway copy of the folder (e.g. `PHPOPDQueueing_uitest`) and a schema-only database, point the copy's `config.php` at it, and delete both afterwards:

```
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE opdqueue_uitest"
C:\xampp\mysql\bin\mysqldump.exe -u root --no-data opdqueue | C:\xampp\mysql\bin\mysql.exe -u root opdqueue_uitest
C:\xampp\mysql\bin\mysql.exe -u root opdqueue_uitest < database_setup.sql
C:\xampp\php\php.exe test_issue_number.php
```

`test_issue_number.php` refuses to run unless the `queue` table is empty.

## Usage

### For Doctors:
1. Open **Doctor** in the top menu (`doctor.php`)
2. Type or pick your name, then tap your table
3. Tap the next patient of the lane you want to serve
4. Press **SERVED** when done, or Call again / Pending / Transfer / Cancel
5. Statistics update automatically

### For Administrators:
1. Open **Reports** in the top menu (`admin.php`)
2. Choose Today, This Week, This Month, or another day
3. Export data to CSV
4. Print reports
5. Monitor daily trends

See `OPERATIONS.md` for the Front Desk, the ticket printer and the display board.

### Daily Reset Process:
1. Runs automatically at midnight
2. Archives current day's data
3. Exports to CSV file
4. Clears daily statistics
5. Cleans up old data

## Monitoring Queries

### Current Day Statistics:
```sql
SELECT * FROM daily_statistics 
WHERE date = CURDATE() 
ORDER BY table_number, doctor_name;
```

### Historical Data:
```sql
SELECT * FROM historical_data 
WHERE date = '2024-01-15' 
ORDER BY table_number, doctor_name;
```

### Doctor Performance:
```sql
SELECT doctor_name, 
       SUM(patients_served) as total_served,
       SUM(patients_pending) as total_pending,
       SUM(patients_cancelled) as total_cancelled
FROM historical_data 
WHERE date BETWEEN '2024-01-01' AND '2024-01-31'
GROUP BY doctor_name;
```

## Troubleshooting

### Common Issues:

1. **Tables not created**: Run `database_setup.sql` manually
2. **Daily reset not working**: Check file permissions and PHP path
3. **Statistics not updating**: Verify database connection in `config.php`
4. **CSV export failing**: Ensure `exports/` directory exists and is writable

### Log Files:
- Check PHP error logs for script issues
- Monitor database logs for connection problems
- Review task scheduler/cron logs for scheduling issues

## Support

For technical support or questions about the database setup, please refer to the system documentation or contact the development team. 
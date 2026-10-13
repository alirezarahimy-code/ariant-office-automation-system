<?php
/**
 * ابزار تشخیص «چرا رکورد SOURCE_TABLE حذف نمی‌شود» — نسخهٔ ۱.۳۳
 *
 * اجرا:
 *   الف) از مرورگر:  index.php?page=food-ticket&food_api=diag-source_row-delete
 *   ب) از CLI:       php tools/diag_source_row_delete.php
 *
 * چه چیزی را نشان می‌دهد:
 *   ۱) ستون‌های واقعی جدول SOURCE_TABLE و اینکه «کلید اصلی» شناسایی شده است یا نه
 *   ۲) ساختار ستون‌های حذف در food_ticket_events (source_deleted …)
 *   ۳) شبیه‌سازی (Dry-run) حذف برای ردیف‌های امروز: چه شرط‌هایی ساخته می‌شود و هر شرط چند ردیف تطبیق می‌دهد
 *      (هیچ ردیفی در این مرحله حذف نمی‌شود)
 *   ۴) گزارش آخرین ردیف‌هایی که حذف‌شان ناموفق بوده (del_fail)
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (!function_exists('food_ticket_config')) {
    echo json_encode(['ok' => false, 'error' => 'food_ticket functions not loaded'], JSON_UNESCAPED_UNICODE);
    exit;
}

$out = ['ok' => true, 'checks' => []];
$dryRun = (bool) (int) ($_GET['delete'] ?? 0); // delete=1 → حذف واقعی سه ردیف نمونه

try {
    $config = food_ticket_config(true);
    $out['checks']['cut_source_rows'] = (int) ($config['cut_source_rows'] ?? 1);

    // ── ۱) وضعیت ستون‌های حذف در جدول رویدادها ──
    $out['checks']['events_source_delete_supported'] = function_exists('food_ticket_source_delete_supported')
        ? food_ticket_source_delete_supported() : null;
    try {
        $rows = db()->query('SELECT id, punch_date, source_uid, event_type, print_status, source_deleted, source_delete_note
            FROM food_ticket_events ORDER BY id DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
        $out['checks']['recent_events'] = $rows;
    } catch (Throwable $e) {
        $out['checks']['recent_events_error'] = $e->getMessage();
    }
    try {
        $stmt = db()->query('SELECT COUNT(*) AS total,
                SUM(source_deleted = 1) AS deleted,
                SUM(source_deleted = 0) AS not_deleted
            FROM food_ticket_events WHERE punch_date >= DATE_SUB(CURDATE(), INTERVAL 3 DAY)');
        $out['checks']['delete_totals_3days'] = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $out['checks']['delete_totals_error'] = $e->getMessage();
    }

    // ── ۲) ساختار جدول SOURCE_TABLE و تشخیص کلید اصلی ──
    $attendancePath = (string) ($config['attendance_path'] ?? '');
    if ($attendancePath === '') {
        throw new RuntimeException('مسیر فایل منبع تردد تنظیم نشده است.');
    }
    $attendancePassword = function_exists('food_ticket_attendance_password')
        ? food_ticket_attendance_password($config)
        : (function_exists('food_ticket_attendance_factory_password') ? food_ticket_attendance_factory_password() : '');
    $sourceDb = food_ticket_odbc($attendancePath, $attendancePassword);
    try {
        $rows = food_ticket_access_rows($sourceDb, food_ticket_source_table(), 5);
        $out['checks']['source_sample_count'] = count($rows);
        if ($rows) {
            $sample = $rows[0];
            $out['checks']['source_columns'] = array_values((array) ($sample['columns'] ?? []));
            $out['checks']['source_column_keys'] = array_keys((array) ($sample['row'] ?? []));
            $out['checks']['source_has_types'] = !empty($sample['types']);
            $out['checks']['source_types'] = $sample['types'] ?? null;
            $pk = food_ticket_source_primary_key($sample);
            $out['checks']['source_primary_key'] = $pk;
            $out['checks']['source_first_row'] = $sample['row'] ?? null;

            // پیش‌نمایش شرط‌های حذف + شمارش تطبیق (Dry-run)
            $preview = [];
            foreach (food_ticket_source_delete_candidates($sample) as [$label, $where]) {
                $entry = ['label' => $label, 'where' => $where, 'matched' => null, 'error' => null];
                try {
                    if (food_ticket_is_ps_access($sourceDb)) {
                        // در مسیر PowerShell، فقط حذف اجرا می‌شود؛ برای شمارش از همان DELETE با شناسهٔ نامعتبر استفاده نمی‌کنیم.
                        $entry['matched'] = 'در مسیر PowerShell شمارش فقط هنگام حذف گزارش می‌شود';
                    } else {
                        $res = @odbc_exec($sourceDb, 'SELECT COUNT(*) FROM ' . food_ticket_ident(food_ticket_source_table()) . ' WHERE ' . $where);
                        if ($res === false) {
                            $entry['error'] = (string) @odbc_errormsg($sourceDb);
                        } else {
                            @odbc_fetch_row($res);
                            $entry['matched'] = (int) @odbc_result($res, 1);
                        }
                    }
                } catch (Throwable $e) {
                    $entry['error'] = $e->getMessage();
                }
                $preview[] = $entry;
            }
            $out['checks']['candidates_preview'] = $preview;

            if ($dryRun) {
                $del = [];
                $ok = food_ticket_delete_source($sourceDb, $sample, $config, $del);
                $out['checks']['dry_run_real_delete'] = ['ok' => $ok, 'result' => $del];
            }
        } else {
            $out['checks']['source_sample_count'] = 0;
            $out['checks']['hint'] = 'امروز ردیفی در SOURCE_TABLE نیست؛ برای تست واقعی یک تردد ثبت کنید یا limit ابزار را بالا ببرید.';
        }
    } finally {
        if (function_exists('food_ticket_access_close')) {
            food_ticket_access_close($sourceDb);
        }
    }

    // ── ۳) ردیف‌هایی که حذف‌شان ناموفق بوده ──
    try {
        $stmt = db()->query('SELECT id, punch_date, punch_time, source_uid, event_type, source_delete_note, updated_at
            FROM food_ticket_events WHERE source_deleted = 0 AND source_delete_note IS NOT NULL
            ORDER BY id DESC LIMIT 10');
        $out['checks']['failed_deletes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $out['checks']['failed_deletes_error'] = $e->getMessage();
    }
} catch (Throwable $e) {
    $out['ok'] = false;
    $out['error'] = $e->getMessage();
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

<?php
declare(strict_types=1);

/**
 * لایهٔ پایدار صف چاپ و قوانین وعده — مستقل از UI.
 * Worker و food-ticket.php از این توابع استفاده می‌کنند.
 */

function food_ticket_setting(string $key, mixed $default = null): mixed
{
    try {
        // جدول settings سامانه: ستون‌های `key` و `value` (نه setting_key/setting_value)
        $q = db()->prepare('SELECT `value` FROM settings WHERE `key` = ? LIMIT 1');
        $q->execute(['food_ticket.' . $key]);
        $v = $q->fetchColumn();
        if ($v === false || $v === null) {
            return $default;
        }
        $decoded = json_decode((string) $v, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $v;
    } catch (Throwable) {
        return $default;
    }
}

function food_ticket_set_setting(string $key, mixed $value): void
{
    $stored = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
    db()->prepare(
        'INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
    )->execute(['food_ticket.' . $key, $stored]);
}

/** وعده‌های غذا — قابل تنظیم از Settings */
function food_ticket_meal_windows(): array
{
    $defaults = [
        'breakfast' => ['label' => 'صبحانه', 'start' => '06:00', 'end' => '10:00', 'enabled' => true],
        'lunch' => ['label' => 'ناهار', 'start' => '11:00', 'end' => '15:30', 'enabled' => true],
        'dinner' => ['label' => 'شام', 'start' => '17:00', 'end' => '21:30', 'enabled' => false],
    ];
    $saved = food_ticket_setting('meal_windows', null);
    if (!is_array($saved)) {
        return $defaults;
    }
    return array_replace_recursive($defaults, $saved);
}

function food_ticket_active_meal(?string $timeHms = null): ?array
{
    $timeHms = $timeHms ?: date('H:i:s');
    $t = substr($timeHms, 0, 5);
    foreach (food_ticket_meal_windows() as $code => $win) {
        if (empty($win['enabled'])) {
            continue;
        }
        $start = substr((string) ($win['start'] ?? '00:00'), 0, 5);
        $end = substr((string) ($win['end'] ?? '23:59'), 0, 5);
        if ($t >= $start && $t <= $end) {
            return ['code' => $code, 'label' => (string) ($win['label'] ?? $code)] + $win;
        }
    }
    return null;
}

/** قالب فیش قابل ذخیره */
function food_ticket_template_default(): array
{
    return [
        'paper_width_mm' => 80,
        'font_family' => 'B Nazanin',
        'font_size' => 14,
        'font_bold_title' => true,
        'alignment' => 'center',
        'rtl' => true,
        'line_spacing' => 1.15,
        'margin_mm' => 3,
        'show_separator' => false,
        'show_logo' => false,
        'cut_paper' => true,
        'company' => '',
        'title' => '',
        'footer' => '',
        'printer_profile' => 'thermal_80mm',
        'fields' => [
            ['key' => 'full_name', 'label' => 'نام و نام خانوادگی', 'enabled' => true, 'order' => 10],
            ['key' => 'food_type', 'label' => 'نوع غذا', 'enabled' => true, 'order' => 20],
            ['key' => 'food_date', 'label' => 'تاریخ غذا', 'enabled' => true, 'order' => 30],
            ['key' => 'attendance_time', 'label' => 'ساعت تردد', 'enabled' => true, 'order' => 40],
        ],
        'retry_count' => 5,
        'retry_delay_seconds' => 30,
    ];
}

function food_ticket_template(): array
{
    $path = APP_ROOT . '/storage/food_ticket_template.json';
    if (is_file($path)) {
        $j = json_decode((string) file_get_contents($path), true);
        if (is_array($j)) {
            return array_replace_recursive(food_ticket_template_default(), $j);
        }
    }
    $saved = food_ticket_setting('ticket_template', null);
    if (is_array($saved)) {
        return array_replace_recursive(food_ticket_template_default(), $saved);
    }
    return food_ticket_template_default();
}

function food_ticket_save_template(array $template): void
{
    $merged = array_replace_recursive(food_ticket_template_default(), $template);
    $dir = APP_ROOT . '/storage';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    file_put_contents(
        $dir . '/food_ticket_template.json',
        json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );
    food_ticket_set_setting('ticket_template', $merged);
}

function food_ticket_render_from_template(array $event, ?array $template = null): string
{
    $tpl = $template ?: food_ticket_template();
    $event['food_date'] = (string) ($event['food_date'] ?? $event['punch_date'] ?? '');
    $event['attendance_time'] = (string) ($event['attendance_time'] ?? $event['punch_time'] ?? '');
    $fields = $tpl['fields'] ?? [];
    usort($fields, static fn ($a, $b) => ((int) ($a['order'] ?? 0)) <=> ((int) ($b['order'] ?? 0)));
    $sep = !empty($tpl['show_separator']) ? '------------------------------' : '';
    $lines = [];
    if (trim((string) ($tpl['company'] ?? '')) !== '') {
        $lines[] = (string) $tpl['company'];
    }
    if (trim((string) ($tpl['title'] ?? '')) !== '') {
        $lines[] = (string) $tpl['title'];
    }
    if ($sep && $lines) {
        $lines[] = $sep;
    }
    $meal = food_ticket_active_meal($event['punch_time'] ?? null);
    $event['meal_label'] = $meal['label'] ?? '';
    foreach ($fields as $f) {
        if (empty($f['enabled'])) {
            continue;
        }
        $key = (string) ($f['key'] ?? '');
        $label = (string) ($f['label'] ?? $key);
        $val = (string) ($event[$key] ?? '');
        if ($val === '') {
            continue;
        }
        if ($key === 'food_date' && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $val, $m) && (int) $m[1] >= 1700 && function_exists('gregorian_to_jalali')) {
            [$jy, $jm, $jd] = gregorian_to_jalali((int) $m[1], (int) $m[2], (int) $m[3]);
            $val = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
        }
        $lines[] = $label . ': ' . $val;
    }
    if ($sep && $lines) {
        $lines[] = $sep;
    }
    if (trim((string) ($tpl['footer'] ?? '')) !== '') {
        $lines[] = (string) $tpl['footer'];
    }
    return implode("\n", $lines) . "\n";
}

function food_ticket_print_log(
    ?int $eventId,
    ?string $jobId,
    ?string $sourceKey,
    ?string $ticketKey,
    ?string $printer,
    string $status,
    int $retry,
    ?string $message
): void {
    try {
        if (!db_table_exists('food_ticket_print_log')) {
            error_log(sprintf(
                '[food-ticket] %s job=%s event=%s status=%s retry=%d msg=%s',
                date('c'),
                $jobId,
                $eventId,
                $status,
                $retry,
                $message
            ));
            return;
        }
        db()->prepare(
            'INSERT INTO food_ticket_print_log (event_id, print_job_id, source_key, ticket_key, printer, status, retry_count, message) VALUES (?,?,?,?,?,?,?,?)'
        )->execute([
            $eventId,
            $jobId,
            $sourceKey,
            $ticketKey,
            $printer,
            $status,
            $retry,
            $message !== null ? mb_substr($message, 0, 500) : null,
        ]);
    } catch (Throwable $e) {
        error_log('food_ticket_print_log: ' . $e->getMessage());
    }
}

function food_ticket_new_job_id(string $ticketKey): string
{
    return substr(hash('sha256', $ticketKey . '|' . microtime(true)), 0, 32);
}

/**
 * قرار دادن فیش در صف — بدون چاپ فوری (مستقل از UI).
 */
function food_ticket_enqueue_print(int $eventId, array $config): void
{
    $q = db()->prepare('SELECT * FROM food_ticket_events WHERE id = ? LIMIT 1');
    $q->execute([$eventId]);
    $event = $q->fetch();
    if (!$event) {
        return;
    }
    $status = (string) ($event['print_status'] ?? '');
    if (in_array($status, ['printed', 'pending', 'printing'], true)) {
        return; // idempotent
    }
    $ticketKey = (string) ($event['ticket_key'] ?? ('evt:' . $eventId));
    $jobId = (string) ($event['print_job_id'] ?? '');
    if ($jobId === '') {
        $jobId = food_ticket_new_job_id($ticketKey);
    }
    $printer = function_exists('food_ticket_printer_label') ? food_ticket_printer_label($config) : trim((string) ($config['printer_host'] ?? ''));
    if ($printer === '') {
        $printer = 'چاپگر پیش‌فرض';
    }
    try {
        db()->prepare(
            'UPDATE food_ticket_events SET print_status = "pending", print_job_id = ?, printer_name = ?, last_error = NULL WHERE id = ? AND print_status <> "printed"'
        )->execute([$jobId, $printer, $eventId]);
    } catch (Throwable) {
        // ستون‌های 1.19 هنوز migrate نشده
        db()->prepare('UPDATE food_ticket_events SET print_status = "pending", last_error = NULL WHERE id = ?')->execute([$eventId]);
    }
    food_ticket_print_log($eventId, $jobId, $event['source_key'] ?? null, $ticketKey, $printer, 'pending', 0, 'enqueued');
}

function food_ticket_can_delete_source_after_print(string $printStatus): bool
{
    return $printStatus === 'printed';
}

/**
 * پردازش صف چاپ — جدا از دریافت تردد.
 */
function food_ticket_process_print_queue(?array $config = null, int $limit = 30): array
{
    $config = $config ?: food_ticket_config(true);
    $tpl = food_ticket_template();
    $maxRetry = max(1, (int) ($tpl['retry_count'] ?? 5));
    $delay = max(5, (int) ($tpl['retry_delay_seconds'] ?? 30));
    $summary = ['claimed' => 0, 'printed' => 0, 'failed' => 0, 'skipped' => 0];

    $claimable = '(print_status IN ("pending", "print_error", "failed")
        OR (print_status = "printing" AND updated_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)))';
    try {
        $sql = 'SELECT * FROM food_ticket_events
            WHERE ' . $claimable . '
              AND (next_retry_at IS NULL OR next_retry_at <= NOW())
            ORDER BY id ASC LIMIT ' . (int) $limit;
        $rows = db()->query($sql)->fetchAll();
    } catch (Throwable $e) {
        // بدون next_retry_at
        $rows = db()->query(
            'SELECT * FROM food_ticket_events WHERE ' . $claimable . ' ORDER BY id ASC LIMIT ' . (int) $limit
        )->fetchAll();
    }

    foreach ($rows as $event) {
        $id = (int) $event['id'];
        $retry = max((int) ($event['retry_count'] ?? 0), (int) ($event['print_attempts'] ?? 0));
        $jobId = (string) ($event['print_job_id'] ?? food_ticket_new_job_id((string) ($event['ticket_key'] ?? $id)));
        // ۱.۳۷.۶ — مقصد واقعی چاپ باید *پیش از* هر لاگ/محافظ تعیین شود.
        // پیش از این، در شاخهٔ «فیش تحویل‌شده دوباره چاپ نشود» متغیر $printer هنوز تعریف نشده بود
        // (هشدار Undefined variable) و اگر ردیف قبلی مقدار داده بود، نام چاپگرِ ردیف قبل در لاگ ثبت می‌شد.
        // مقصد از تنظیمات همین لحظه خوانده می‌شود (نه مقدار قدیمیِ ثبت‌شده روی رویداد).
        $printer = function_exists('food_ticket_printer_label') ? food_ticket_printer_label($config) : (string) ($event['printer_name'] ?? $config['printer_host'] ?? '');
        // فیشی که تحویل داده شده دوباره چاپ نمی‌شود؛ خطای چاپ آن «چاپ‌شده» تلقی می‌شود.
        if (food_ticket_delivery_supported() && (string) ($event['delivery_status'] ?? 'pending') === 'delivered'
            && (string) ($event['print_status'] ?? '') !== 'printed') {
            try {
                db()->prepare('UPDATE food_ticket_events SET print_status = "printed", next_retry_at = NULL, last_error = NULL WHERE id = ?')->execute([$id]);
                food_ticket_print_log($id, $jobId, $event['source_key'] ?? null, $event['ticket_key'] ?? null, $printer, 'skipped', $retry, 'ticket already delivered; reprint skipped');
                if (function_exists('system_log')) {
                    system_log('warning', 'food_print', 'فیش تحویل‌شده دوباره چاپ نشد', ['event' => $id, 'ticket' => $event['ticket_key'] ?? '']);
                }
            } catch (Throwable $e) {
                error_log('[food-print] delivered guard: ' . $e->getMessage());
            }
            $summary['skipped']++;
            continue;
        }
        if ($retry >= $maxRetry) {
            if ((string) ($event['print_status'] ?? '') === 'printing') {
                $message = 'Worker stopped before completing the previous print attempt; retry limit reached.';
                try {
                    db()->prepare('UPDATE food_ticket_events SET print_status = "failed", next_retry_at = NULL, last_error = ?, processed_at = NOW() WHERE id = ?')->execute([$message, $id]);
                } catch (Throwable) {
                    db()->prepare('UPDATE food_ticket_events SET print_status = "print_error", last_error = ?, processed_at = NOW() WHERE id = ?')->execute([$message, $id]);
                }
                food_ticket_print_log($id, $jobId, $event['source_key'] ?? null, $event['ticket_key'] ?? null, $printer, 'failed', $retry, $message);
                $summary['failed']++;
            } else {
                $summary['skipped']++;
            }
            continue;
        }
        $claimed = false;
        try {
            $claim = db()->prepare('UPDATE food_ticket_events
                SET print_status = "printing", print_job_id = COALESCE(print_job_id, ?),
                    retry_count = COALESCE(retry_count, 0) + 1, print_attempts = print_attempts + 1, next_retry_at = NULL
                WHERE id = ? AND ' . $claimable);
            $claim->execute([$jobId, $id]);
            $claimed = $claim->rowCount() === 1;
        } catch (Throwable) {
            $claim = db()->prepare('UPDATE food_ticket_events
                SET print_status = "printing", print_attempts = print_attempts + 1
                WHERE id = ? AND ' . $claimable);
            $claim->execute([$id]);
            $claimed = $claim->rowCount() === 1;
        }
        if (!$claimed) {
            $summary['skipped']++;
            continue;
        }
        $summary['claimed']++;
        food_ticket_print_log($id, $jobId, $event['source_key'] ?? null, $event['ticket_key'] ?? null, $printer, 'printing', $retry, null);

        try {
            // متن از قالب
            // اگر نوع غذا به‌خاطر encoding خراب شده، یک‌بار از سفارش زنده بخوان
            $ft = (string) ($event['food_type'] ?? '');
            if ($ft === '' || preg_match('/^[\s\?؟]+$/u', $ft)) {
                try {
                    $cfg = $config ?: food_ticket_config(true);
                    $nat = food_ticket_digits((string) ($event['national_code'] ?? ''));
                    $pdate = (string) ($event['punch_date'] ?? date('Y-m-d'));
                    if (!function_exists('food_order_mode') || !function_exists('food_order_internal_map') || food_order_mode() !== 'INTERNAL-DB') {
                        throw new RuntimeException('منبع سفارش داخلی در دسترس نیست؛ Access سفارش مجاز نیست.');
                    }
                    if ($nat !== '') {
                        $omap = food_order_internal_map($pdate);
                        $reFood = $omap[$nat] ?? $omap[ltrim($nat, '0') ?: '0'] ?? '';
                        if (is_string($reFood) && $reFood !== '' && !preg_match('/^[\s\?؟]+$/u', $reFood)) {
                            $event['food_type'] = $reFood;
                            db()->prepare('UPDATE food_ticket_events SET food_type = ? WHERE id = ?')->execute([$reFood, $id]);
                        }
                    }
                } catch (Throwable $ignoreFood) {
                    error_log('[food-print] food re-lookup: ' . $ignoreFood->getMessage());
                    throw new RuntimeException('بازخوانی سفارش داخلی برای فیش ناموفق بود؛ چاپ فیش خالی انجام نمی‌شود.', 0, $ignoreFood);
                }
            }
            $text = food_ticket_render_from_template($event, $tpl);
            food_ticket_send_raw_payload($text, $config, $tpl, $event);
            db()->prepare(
                'UPDATE food_ticket_events SET print_status = "printed", event_type = IF(event_type = "guest", "guest", "printed"), processed_at = NOW(), last_error = NULL WHERE id = ?'
            )->execute([$id]);
            $summary['printed']++;
            if (function_exists('activity_log')) {
                try {
                    activity_log([
                        'action_code' => 'food_ticket_print_success',
                        'module' => 'food',
                        'target_type' => 'food_ticket_event',
                        'target_id' => $id,
                        'target_label' => (string) ($event['ticket_key'] ?? ''),
                        'meta' => ['printer' => $printer, 'job' => $jobId, 'mode' => (string) ($config['printer_mode'] ?? ''), 'try' => $retry + 1],
                    ]);
                } catch (Throwable) {
                }
            }
            food_ticket_print_log($id, $jobId, $event['source_key'] ?? null, $event['ticket_key'] ?? null, $printer, 'printed', $retry, 'ok' . (function_exists('food_ticket_tpl_log_suffix') ? food_ticket_tpl_log_suffix() : ''));
        } catch (Throwable $ex) {
            $next = date('Y-m-d H:i:s', time() + $delay);
            try {
                db()->prepare(
                    'UPDATE food_ticket_events SET print_status = "failed", next_retry_at = ?, last_error = ?, processed_at = NOW() WHERE id = ?'
                )->execute([$next, $ex->getMessage(), $id]);
            } catch (Throwable) {
                db()->prepare(
                    'UPDATE food_ticket_events SET print_status = "print_error", last_error = ?, processed_at = NOW() WHERE id = ?'
                )->execute([$ex->getMessage(), $id]);
            }
            $summary['failed']++;
            food_ticket_print_log($id, $jobId, $event['source_key'] ?? null, $event['ticket_key'] ?? null, $printer, 'failed', $retry + 1, $ex->getMessage());
            // علت شکست چاپ در لاگ worker (stderr) و app.log هم ثبت شود تا فقط به ستون last_error دیتابیس وابسته نباشیم
            error_log('[food-print] FAILED event=' . $id . ' printer=' . $printer . ' try=' . ($retry + 1) . ' ' . get_class($ex) . ': ' . $ex->getMessage() . ' @' . basename($ex->getFile()) . ':' . $ex->getLine());
            if (function_exists('system_log')) {
                try {
                    system_log('error', 'food_print', 'چاپ فیش ناموفق بود', ['event' => $id, 'printer' => $printer, 'error' => $ex->getMessage(), 'where' => basename($ex->getFile()) . ':' . $ex->getLine()]);
                    activity_log([
                        'action_code' => 'food_ticket_print_failed',
                        'module' => 'food',
                        'target_type' => 'food_ticket_event',
                        'target_id' => $id,
                        'target_label' => (string) ($event['ticket_key'] ?? ''),
                        'meta' => ['printer' => $printer, 'job' => $jobId, 'mode' => (string) ($config['printer_mode'] ?? ''), 'try' => $retry + 1, 'error' => $ex->getMessage()],
                    ]);
                } catch (Throwable) {
                }
            }
            // یک رکورد نباید worker را بکشد
        }
    }
    return $summary;
}

/** ارقام لاتین → فارسی (برای نمایش روی فیش) */
function food_ticket_fa_digits(string $value): string
{
    return strtr($value, [
        '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
        '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
    ]);
}

/**
 * سطرهای فیش برای چاپ گرافیکی: نوع غذا جدا (درشت)، بقیهٔ فیلدها به‌صورت برچسب/مقدار.
 * تاریخ میلادی به شمسی تبدیل می‌شود و ارقام فارسی نمایش داده می‌شوند.
 *
 * @return array{food:string,food_label:string,rows:list<array{l:string,v:string}>}
 */
function food_ticket_graphic_rows(array $event, array $tpl): array
{
    $event['food_date'] = (string) ($event['food_date'] ?? $event['punch_date'] ?? '');
    $event['attendance_time'] = (string) ($event['attendance_time'] ?? $event['punch_time'] ?? '');
    $fields = $tpl['fields'] ?? [];
    usort($fields, static fn ($a, $b) => ((int) ($a['order'] ?? 0)) <=> ((int) ($b['order'] ?? 0)));
    $meal = food_ticket_active_meal($event['punch_time'] ?? null);
    $event['meal_label'] = $meal['label'] ?? '';

    $food = '';
    $foodLabel = 'نوع غذا';
    $rows = [];
    foreach ($fields as $f) {
        if (empty($f['enabled'])) {
            continue;
        }
        $key = (string) ($f['key'] ?? '');
        $label = (string) ($f['label'] ?? $key);
        $val = trim((string) ($event[$key] ?? ''));
        if ($val === '') {
            continue;
        }
        if ($key === 'food_type') {
            $food = $val;
            $foodLabel = $label;
            continue;
        }
        if (in_array($key, ['punch_date', 'food_date'], true) && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $val, $m) && function_exists('gregorian_to_jalali')) {
            [$jy, $jm, $jd] = gregorian_to_jalali((int) $m[1], (int) $m[2], (int) $m[3]);
            $val = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
        } elseif (in_array($key, ['punch_time', 'attendance_time'], true) && preg_match('/^(\d{1,2}):(\d{2})/', $val, $m)) {
            $val = sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }
        $rows[] = ['l' => $label, 'v' => food_ticket_fa_digits($val)];
    }
    return ['food' => $food, 'food_label' => $foodLabel, 'rows' => $rows];
}

function food_ticket_template_logo_base64(array $template): string
{
    if (empty($template['show_logo'])) {
        return '';
    }
    $logo = trim((string) setting('food_ticket_brand_logo', (string) setting('app_logo', (string) cfg('app.logo', ''))));
    if ($logo === '') {
        return '';
    }

    $path = $logo;
    if (preg_match('#^https?://#i', $logo)) {
        $urlPath = (string) (parse_url($logo, PHP_URL_PATH) ?? '');
        $mainPath = (string) (parse_url(food_ticket_main_url(), PHP_URL_PATH) ?? '');
        $baseDir = rtrim(str_replace('\\', '/', dirname($mainPath)), '/.');
        if ($baseDir !== '' && str_starts_with($urlPath, $baseDir . '/')) {
            $urlPath = substr($urlPath, strlen($baseDir) + 1);
        }
        $path = ltrim($urlPath, '/');
    }
    if (!preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $path)) {
        $path = APP_ROOT . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($path, '/\\'));
    }
    $root = realpath(APP_ROOT);
    $file = realpath($path);
    if (!$root || !$file || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file) || filesize($file) > 1048576) {
        return '';
    }
    $image = @getimagesize($file);
    if (!is_array($image) || !in_array((string) ($image['mime'] ?? ''), ['image/png', 'image/jpeg', 'image/bmp', 'image/gif'], true)) {
        return '';
    }
    $bytes = @file_get_contents($file);
    return is_string($bytes) ? base64_encode($bytes) : '';
}

/**
 * چاپ گرافیکی فیش روی صف چاپگر ویندوز (GDI+): فونت فارسی، راست‌به‌چپ، کادر و چیدمان مرتب.
 * متن ساده با فونت پیش‌فرض چاپگر حرف‌های فارسی را از هم جدا و چپ‌به‌راست چاپ می‌کند.
 * در صورت هر مشکلی false برمی‌گرداند تا چاپ متنی قدیمی به‌عنوان جایگزین اجرا شود.
 */
function food_ticket_send_windows_graphic(array $event, string $printerName, ?array $tpl = null): bool
{
    if ($printerName === '' || strncasecmp(PHP_OS, 'WIN', 3) !== 0) {
        return false;
    }
    if (preg_match('/pdf|xps|onenote|fax/i', $printerName)) {
        return false; // چاپگرهای مجازی با مسیر قدیمی کار می‌کنند
    }
    $tpl = $tpl ?: food_ticket_template();
    $layout = food_ticket_graphic_rows($event, $tpl);
    $paperMm = (int) ($tpl['paper_width_mm'] ?? 80);
    if ($paperMm < 40 || $paperMm > 120) {
        $paperMm = 80;
    }
    $fonts = array_values(array_unique(array_filter([
        trim((string) ($tpl['font_family'] ?? '')),
        'Vazirmatn', 'Vazir', 'B Nazanin', 'B Titr', 'Tahoma',
    ])));
    $payload = base64_encode((string) json_encode([
        'printer' => $printerName,
        'paper_mm' => $paperMm,
        'fonts' => $fonts,
        'company' => food_ticket_fa_digits((string) ($tpl['company'] ?? '')),
        'title' => food_ticket_fa_digits((string) ($tpl['title'] ?? '')),
        'footer' => food_ticket_fa_digits((string) ($tpl['footer'] ?? '')),
        'logo' => food_ticket_template_logo_base64($tpl),
        'food' => $layout['food'],
        'food_label' => $layout['food_label'],
        'rows' => $layout['rows'],
        'separator' => !empty($tpl['show_separator']),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $ps = <<<'PS'
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing
$data = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('__FT_PAYLOAD__')) | ConvertFrom-Json
$script:logo = $null
if ([string]$data.logo -ne '') {
  $logoBytes = [Convert]::FromBase64String([string]$data.logo)
  $logoStream = New-Object System.IO.MemoryStream
  $logoStream.Write($logoBytes, 0, $logoBytes.Length)
  $logoStream.Position = 0
  $script:logo = [System.Drawing.Image]::FromStream($logoStream)
}

$installed = @((New-Object System.Drawing.Text.InstalledFontCollection).Families | ForEach-Object { $_.Name })
$fontName = 'Tahoma'
foreach ($c in @($data.fonts)) { if ($installed -contains [string]$c) { $fontName = [string]$c; break } }

$paperMm = [double]$data.paper_mm
$k = $paperMm / 80.0
$W = $paperMm - 10.0          # عرض قابل چاپ (میلی‌متر)
$pad = 2.5
$inner = $W - 2 * $pad

function New-Sf([string]$align) {
  $sf = New-Object System.Drawing.StringFormat
  $sf.FormatFlags = [System.Drawing.StringFormatFlags]::DirectionRightToLeft
  $sf.Alignment = [System.Drawing.StringAlignment]::$align
  $sf.LineAlignment = [System.Drawing.StringAlignment]::Near
  return $sf
}
function New-Font([double]$pt, [bool]$bold) {
  $style = [System.Drawing.FontStyle]::Regular
  if ($bold) { $style = [System.Drawing.FontStyle]::Bold }
  return New-Object System.Drawing.Font($fontName, [single]($pt * $script:k), $style, [System.Drawing.GraphicsUnit]::Point)
}

$fCompany = New-Font 15 $true
$fTitle   = New-Font 11 $false
$fLabel   = New-Font 9 $false
$fValue   = New-Font 11 $true
$fFoodLbl = New-Font 9 $false
$fFood    = New-Font 17 $true
$fFooter  = New-Font 10 $false
$sfC = New-Sf 'Center'
$sfR = New-Sf 'Near'   # در حالت راست‌به‌چپ یعنی سمت راست
$sfL = New-Sf 'Far'    # سمت چپ
$black = [System.Drawing.Brushes]::Black
$gray = New-Object System.Drawing.SolidBrush ([System.Drawing.Color]::FromArgb(85, 85, 85))
$penBorder = New-Object System.Drawing.Pen ([System.Drawing.Color]::Black), 0.45
$penBox = New-Object System.Drawing.Pen ([System.Drawing.Color]::Black), 0.7
$penDash = New-Object System.Drawing.Pen ([System.Drawing.Color]::FromArgb(110, 110, 110)), 0.25
$penDash.DashStyle = [System.Drawing.Drawing2D.DashStyle]::Dash

function Measure-H($g, [string]$t, $font, $sf, [double]$w) {
  $size = $g.MeasureString($t, $font, (New-Object System.Drawing.SizeF([single]$w, [single]1000)), $sf)
  return [double]$size.Height
}
function Draw-Text($g, [string]$t, $font, $brush, $sf, [double]$x, [double]$y, [double]$w) {
  $h = Measure-H $g $t $font $sf $w
  $g.DrawString($t, $font, $brush, (New-Object System.Drawing.RectangleF([single]$x, [single]$y, [single]$w, [single]($h + 1))), $sf)
  return $h
}
function Draw-Dash($g, [double]$y) {
  $g.DrawLine($penDash, [single]$pad, [single]$y, [single]($script:W - $pad), [single]$y)
}

function Render($g) {
  $g.PageUnit = [System.Drawing.GraphicsUnit]::Millimeter
  $g.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::AntiAliasGridFit
  $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
  $y = 3.0
  if ($script:logo) {
    $logoW = [Math]::Min($inner * 0.34, [double]$script:logo.Width)
    $logoH = $logoW * [double]$script:logo.Height / [double]$script:logo.Width
    $logoX = $pad + ($inner - $logoW) / 2
    $g.DrawImage($script:logo, (New-Object System.Drawing.RectangleF([single]$logoX, [single]$y, [single]$logoW, [single]$logoH)))
    $y += $logoH + 1.0
  }
  if ([string]$data.company -ne '') { $y += (Draw-Text $g ([string]$data.company) $fCompany $black $sfC $pad $y $inner) + 1.0 }
  if ([string]$data.title -ne '')   { $y += (Draw-Text $g ([string]$data.title) $fTitle $black $sfC $pad $y $inner) + 1.5 }
  Draw-Dash $g ($y + 0.5); $y += 3.0

  if ([string]$data.food -ne '') {
    $y += (Draw-Text $g ([string]$data.food_label) $fFoodLbl $gray $sfC $pad $y $inner) + 0.5
    $fh = Measure-H $g ([string]$data.food) $fFood $sfC ($inner - 4)
    $boxH = $fh + 4
    $g.DrawRectangle($penBox, [single]$pad, [single]$y, [single]$inner, [single]$boxH)
    [void](Draw-Text $g ([string]$data.food) $fFood $black $sfC ($pad + 2) ($y + 2) ($inner - 4))
    $y += $boxH + 3.0
  }

  $labelW = $inner * 0.36
  $valueW = $inner - $labelW - 1.0
  foreach ($r in @($data.rows)) {
    $label = [string]$r.l; $value = [string]$r.v
    $h1 = Measure-H $g $label $fLabel $sfR $labelW
    $h2 = Measure-H $g $value $fValue $sfL $valueW
    [void](Draw-Text $g $label $fLabel $gray $sfR ($pad + $valueW + 1.0) ($y + 0.6) $labelW)
    [void](Draw-Text $g $value $fValue $black $sfL $pad $y $valueW)
    $y += [Math]::Max($h1, $h2) + 1.6
  }

  if ($data.separator) { Draw-Dash $g ($y + 0.5); $y += 3.0 }
  if ([string]$data.footer -ne '') { $y += (Draw-Text $g ([string]$data.footer) $fFooter $black $sfC $pad $y $inner) + 2.0 }
  $y += 2.0
  $g.DrawRectangle($penBorder, [single]0.4, [single]0.4, [single]($W - 0.8), [single]($y - 0.8))
  return $y + 6.0   # فاصله برای برش کاغذ
}

# اندازه‌گیری ارتفاع روی بیت‌مپ موقت
$bmp = New-Object System.Drawing.Bitmap 4, 4
$mg = [System.Drawing.Graphics]::FromImage($bmp)
$totalMm = [double](Render $mg)
$mg.Dispose(); $bmp.Dispose()

$pd = New-Object System.Drawing.Printing.PrintDocument
$pd.PrinterSettings.PrinterName = [string]$data.printer
if (-not $pd.PrinterSettings.IsValid) { [Console]::Error.WriteLine('PRINTER_NOT_FOUND: ' + [string]$data.printer); exit 3 }
$pd.DocumentName = 'FoodTicket'
$pd.PrintController = New-Object System.Drawing.Printing.StandardPrintController
$pd.DefaultPageSettings.Margins = New-Object System.Drawing.Printing.Margins 0, 0, 0, 0
try {
  $wH = [int][Math]::Ceiling($paperMm / 25.4 * 100)
  $hH = [int][Math]::Ceiling($totalMm / 25.4 * 100)
  $pd.DefaultPageSettings.PaperSize = New-Object System.Drawing.Printing.PaperSize 'FoodTicket', $wH, $hH
} catch { }
$pd.add_PrintPage({ param($sender, $e)
  $e.Graphics.PageUnit = [System.Drawing.GraphicsUnit]::Millimeter
  [void](Render $e.Graphics)
  $e.HasMorePages = $false
})
$pd.Print()
$pd.Dispose()
if ($script:logo) { $script:logo.Dispose(); $logoStream.Dispose() }
@{ ok = $true; font = $fontName; height_mm = $totalMm } | ConvertTo-Json -Compress
exit 0
PS;
    $ps = str_replace('__FT_PAYLOAD__', $payload, $ps);

    $spoolDir = APP_ROOT . '/storage/food_ticket_spool';
    if (!is_dir($spoolDir)) {
        @mkdir($spoolDir, 0750, true);
    }
    $tmp = $spoolDir . DIRECTORY_SEPARATOR . 'ft_gfx_' . bin2hex(random_bytes(4)) . '.ps1';
    // BOM لازم است تا PowerShell 5 فایل UTF-8 را درست بخواند
    if (@file_put_contents($tmp, "\xEF\xBB\xBF" . $ps, LOCK_EX) === false) {
        error_log('[food-ticket] graphic print: script could not be written');
        return false;
    }
    try {
        $output = [];
        $code = 0;
        @exec('powershell -NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -File ' . escapeshellarg($tmp) . ' 2>&1', $output, $code);
        if ($code !== 0) {
            error_log('[food-ticket] graphic print failed (fallback to text): ' . implode(' ', $output));
            return false;
        }
        return true;
    } finally {
        @unlink($tmp);
    }
}

function food_ticket_send_raw_payload(string $text, array $config, array $tpl, ?array $event = null): void
{
    $mode = strtolower(trim((string) ($config['printer_mode'] ?? 'tcp_raw')));
    $host = trim((string) ($config['printer_host'] ?? ''));
    $port = max(1, (int) ($config['printer_port'] ?? 9100));
    $share = trim((string) ($config['printer_share'] ?? ''));
    $cut = !empty($tpl['cut_paper']);

    // پروفایل چاپگر حرارتی ۸۰ میلی‌متری: ESC/POS init + cut
    $payload = "\x1b\x40"; // init
    $payload .= "\x1b\x61\x01"; // center
    $payload .= $text;
    if ($cut) {
        $payload .= "\n\n\x1d\x56\x00";
    }

    $isWindows = in_array($mode, ['windows', 'windows_queue', 'windows_share', 'share'], true) || ($share !== '' && $mode !== 'tcp_raw');
    if ($isWindows) {
        $winPrinter = $share !== '' ? $share : ($host ?: 'چاپگر پیش‌فرض');
        // مسیر ۱ (صف ویندوز): قالب فعال → مدل چاپ → GDI+ → درایور چاپگر ویندوز (اندازهٔ کاغذ قالب به درایور داده می‌شود).
        // چاپگرهای مجازی (PDF/XPS/OneNote/Fax) فقط با مسیر متنی قدیمی تست می‌شوند.
        if ($event !== null && function_exists('food_ticket_tpl_print_windows') && !preg_match('/pdf|xps|onenote|fax/i', $winPrinter)) {
            food_ticket_tpl_print_windows($event, $winPrinter);
            return;
        }
        if ($event !== null && !function_exists('food_ticket_tpl_print_windows') && food_ticket_send_windows_graphic($event, $winPrinter, $tpl)) {
            return; // ماژول قالب بارگذاری نشده: چاپ گرافیکی قدیمی
        }
        if (function_exists('food_ticket_send_windows')) {
            food_ticket_send_windows($text, $winPrinter);
            return;
        }
        throw new RuntimeException('چاپ ویندوز در این محیط در دسترس نیست.');
    }

    if ($host === '') {
        throw new RuntimeException('آدرس چاپگر تنظیم نشده است.');
    }

    // چاپ شبکه (TCP 9100): فیش به‌صورت تصویر ESC/POS raster فرستاده می‌شود، نه متن UTF-8 خام.
    // چاپگر حرارتی UTF-8 و اتصال حروف فارسی را نمی‌فهمد و متن خام را به‌صورت کاراکتر درهم چاپ می‌کرد.
    // مسیر ۲ (شبکه): قالب فعال → مدل چاپ → رسم GD → ESC/POS raster → TCP/IP. از همان مدل مسیر ویندوز استفاده می‌کند.
    if ($event !== null && function_exists('food_ticket_tpl_print_network')) {
        food_ticket_tpl_print_network($event, $host, $port);
        return;
    }
    if (!function_exists('food_ticket_np_send_event')) {
        throw new RuntimeException('ماژول چاپ شبکه (food-ticket-netprint.php) بارگذاری نشد.');
    }
    if ($event !== null) {
        food_ticket_np_send_event($event, $tpl, $host, $port);
    } else {
        food_ticket_np_send_text($text, $host, $port);
    }
}

/**
 * ── وضعیت تحویل غذا (مستقل از وضعیت چاپ) ─────────────────────────────────────
 * چاپ موفق ≠ تحویل قطعی. تحویل با تأیید اپراتور ثبت می‌شود و فیش تحویل‌شده
 * دیگر هرگز دوباره چاپ نمی‌شود (حتی اگر چاپش خطا خورده باشد).
 */
/**
 * آیا جدول موردنظر این ستون را دارد؟
 *
 * چرا این تابع لازم است؟ (درس ۱.۳۷.۶)
 *   • db_table_columns() در bootstrap یک «نقشه» برمی‌گرداند: ['col' => true] (کلید = نام ستون).
 *     اگر با array_map('strtolower', …) + in_array روی همان نتیجه جست‌وجو شود، در «مقدارها»
 *     (که همه true هستند) می‌گردد و همیشه false می‌دهد ⇒ سامانه بی‌جهت می‌گفت
 *     «ستون ساخته نشده؛ Migration را اجرا کنید»، هر درخواست یک ALTER ناموفق می‌زد و
 *     وضعیت «حذف ردیف منبع» روی فیش‌ها هم هرگز ثبت نمی‌شد.
 *   • db_table_columns() نتیجه را در طول همان درخواست کش می‌کند؛ پس بلافاصله بعد از یک ALTER
 *     موفق هم باید «زنده» پرسید. این تابع مسیر زنده را اول امتحان می‌کند.
 *
 * @param string $table  نام جدول (بدون بک‌تیک)
 * @param string $column نام ستون
 */
function food_ticket_table_has_column(string $table, string $column): bool
{
    $wanted = strtolower(trim($column));
    if ($wanted === '') {
        return false;
    }
    // ۱) پرس‌وجوی زنده و بدون کش (بعد از ALTER هم درست جواب می‌دهد)
    try {
        if (function_exists('db')) {
            $stmt = db()->prepare('SELECT column_name FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1');
            $stmt->execute([$table, $column]);
            if ($stmt->fetchColumn() !== false) {
                return true;
            }
            // ستون در همین دیتابیس نیست → پاسخ قطعی «نه» (کشِ قدیمی‌تر معتبر نیست)
            return false;
        }
    } catch (Throwable) {
        // موتور دیگر (SQLite در آزمون‌های آفلاین) یا دسترسی نداشت → مسیر دوم
    }
    // ۲) مسیر دوم: db_table_columns با هر دو شکل (نقشهٔ کلید=نام، یا فهرست سادهٔ نام‌ها)
    if (!function_exists('db_table_columns')) {
        return false;
    }
    try {
        $columns = db_table_columns($table);
    } catch (Throwable) {
        return false;
    }
    foreach ((array) $columns as $key => $value) {
        $name = is_string($value) && $value !== '' ? $value : (is_string($key) ? $key : '');
        if ($name !== '' && strtolower(trim($name)) === $wanted) {
            return true;
        }
    }
    return false;
}

function food_ticket_delivery_supported(?bool $force = null): bool
{
    static $supported = null;
    if (func_num_args() > 0) {
        // فراخوانی با آرگومان = تعیین دستیِ نتیجه؛ آرگومان null = پاک‌کردن کش (محاسبهٔ مجدد زنده)
        $supported = $force;
    }
    if ($supported !== null) {
        return $supported;
    }
    $supported = food_ticket_table_has_column('food_ticket_events', 'delivery_status');
    return $supported;
}

/** ستون‌های تحویل را (در صورت نبود) می‌سازد — همان Migration دستی، محافظت‌شده. */
function food_ticket_events_ensure_delivery(): bool
{
    if (food_ticket_delivery_supported()) {
        return true;
    }
    try {
        db()->exec('ALTER TABLE food_ticket_events
            ADD COLUMN delivery_status ENUM("pending","delivered") NOT NULL DEFAULT "pending" AFTER print_status');
    } catch (Throwable $e) {
        error_log('[food-delivery] add delivery_status: ' . $e->getMessage());
    }
    try {
        db()->exec('ALTER TABLE food_ticket_events ADD COLUMN delivered_at DATETIME NULL AFTER delivery_status');
    } catch (Throwable $e) {
        error_log('[food-delivery] add delivered_at: ' . $e->getMessage());
    }
    try {
        db()->exec('ALTER TABLE food_ticket_events ADD COLUMN delivered_by INT UNSIGNED NULL AFTER delivered_at');
    } catch (Throwable $e) {
        error_log('[food-delivery] add delivered_by: ' . $e->getMessage());
    }
    $ready = food_ticket_table_has_column('food_ticket_events', 'delivery_status');
    if ($ready) {
        food_ticket_delivery_supported(true);
    }
    return $ready;
}

/**
 * ── وضعیت واقعی حذف ردیف SOURCE_TABLE (به‌ازای هر فیش) ─────────────────────────────
 * پیش از این هیچ‌جا ثبت نمی‌شد که ردیف منبع واقعاً از SOURCE_TABLE پاک شده است یا نه؛
 * در نتیجه وقتی تطبیق شرط حذف ۰ ردیف بود، سامانه «موفق» گزارش می‌کرد و
 * ردیف‌ها در SOURCE_TABLE انبار می‌شدند. این سه ستون، حقیقت را نگه می‌دارند.
 */
function food_ticket_source_delete_supported(?bool $force = null): bool
{
    static $supported = null;
    if (func_num_args() > 0) {
        // فراخوانی با آرگومان = تعیین دستیِ نتیجه؛ آرگومان null = پاک‌کردن کش (محاسبهٔ مجدد زنده)
        $supported = $force;
    }
    if ($supported !== null) {
        return $supported;
    }
    $supported = food_ticket_table_has_column('food_ticket_events', 'source_deleted');
    return $supported;
}

/** ستون‌های وضعیت حذف منبع را (در صورت نبود) می‌سازد — محافظت‌شده مثل ستون‌های تحویل. */
function food_ticket_events_ensure_source_delete(): bool
{
    if (food_ticket_source_delete_supported()) {
        return true;
    }
    try {
        db()->exec('ALTER TABLE food_ticket_events
            ADD COLUMN source_deleted TINYINT(1) NOT NULL DEFAULT 0 AFTER delivered_by');
    } catch (Throwable $e) {
        error_log('[food-source-delete] add source_deleted: ' . $e->getMessage());
    }
    try {
        db()->exec('ALTER TABLE food_ticket_events ADD COLUMN source_deleted_at DATETIME NULL AFTER source_deleted');
    } catch (Throwable $e) {
        error_log('[food-source-delete] add source_deleted_at: ' . $e->getMessage());
    }
    try {
        db()->exec('ALTER TABLE food_ticket_events ADD COLUMN source_delete_note VARCHAR(255) NULL AFTER source_deleted_at');
    } catch (Throwable $e) {
        error_log('[food-source-delete] add source_delete_note: ' . $e->getMessage());
    }
    try {
        db()->exec('CREATE INDEX idx_food_events_src_deleted ON food_ticket_events (source_deleted, punch_date)');
    } catch (Throwable) {
        // ایندکس ممکن است از قبل باشد
    }
    $ready = food_ticket_table_has_column('food_ticket_events', 'source_deleted');
    if ($ready) {
        food_ticket_source_delete_supported(true);
    }
    return $ready;
}

/** یک فیش را «تحویل‌شده» می‌کند (idempotent) و Audit می‌زند. */
function food_ticket_deliver_event(int $eventId, array $actor = [], string $reason = ''): bool
{
    if ($eventId <= 0) {
        throw new RuntimeException('شناسهٔ فیش معتبر نیست.');
    }
    if (!food_ticket_events_ensure_delivery()) {
        throw new RuntimeException('ستون وضعیت تحویل (delivery_status) در جدول food_ticket_events ساخته نشد. '
            . 'علت: کاربر دیتابیس اجازهٔ ALTER ندارد یا اجرای Migration ناموفق بوده است. '
            . 'راه‌حل: فایل upgrade-1.32-food-group-representative-absence.sql را با کاربر دارای دسترسی روی دیتابیس اجرا کنید.');
    }
    $q = db()->prepare('SELECT id, ticket_key, print_status, delivery_status FROM food_ticket_events WHERE id = ?');
    $q->execute([$eventId]);
    $event = $q->fetch() ?: null;
    if ($event === null) {
        throw new RuntimeException('فیش پیدا نشد.');
    }
    $already = (string) ($event['delivery_status'] ?? 'pending') === 'delivered';
    if (!$already) {
        db()->prepare('UPDATE food_ticket_events SET delivery_status = "delivered", delivered_at = NOW(), delivered_by = ? WHERE id = ?')
            ->execute([(int) ($actor['id'] ?? 0) ?: null, $eventId]);
    }
    if (function_exists('activity_log')) {
        try {
            activity_log([
                'action_code' => 'food_ticket_delivered',
                'module' => 'food',
                'target_type' => 'food_ticket_event',
                'target_id' => $eventId,
                'target_label' => (string) ($event['ticket_key'] ?? ''),
                'user_id' => $actor['id'] ?? null,
                'meta' => ['old' => $already ? 'delivered' : 'pending', 'new' => 'delivered', 'print_status' => (string) ($event['print_status'] ?? ''), 'reason' => $reason],
            ]);
        } catch (Throwable) {
        }
    }
    return !$already;
}

/** برگرداندن تحویل به حالت در انتظار (فقط با مجوز سطح بالاتر — Audit می‌شود). */
function food_ticket_undeliver_event(int $eventId, array $actor = [], string $reason = ''): bool
{
    if ($eventId <= 0 || !food_ticket_events_ensure_delivery()) {
        throw new RuntimeException('فیش یا ستون تحویل پیدا نشد.');
    }
    $q = db()->prepare('SELECT id, ticket_key, delivery_status FROM food_ticket_events WHERE id = ?');
    $q->execute([$eventId]);
    $event = $q->fetch() ?: null;
    if ($event === null) {
        throw new RuntimeException('فیش پیدا نشد.');
    }
    if ((string) $event['delivery_status'] !== 'delivered') {
        return false;
    }
    db()->prepare('UPDATE food_ticket_events SET delivery_status = "pending", delivered_at = NULL, delivered_by = NULL WHERE id = ?')->execute([$eventId]);
    if (function_exists('activity_log')) {
        try {
            activity_log([
                'action_code' => 'food_ticket_delivery_revoked',
                'module' => 'food',
                'target_type' => 'food_ticket_event',
                'target_id' => $eventId,
                'target_label' => (string) ($event['ticket_key'] ?? ''),
                'user_id' => $actor['id'] ?? null,
                'meta' => ['old' => 'delivered', 'new' => 'pending', 'reason' => $reason],
            ]);
        } catch (Throwable) {
        }
    }
    return true;
}

/** تعدادهای وضعیت چاپ/تحویل برای پنل پایش. */
function food_ticket_status_counts(string $date = ''): array
{
    $out = ['print' => ['pending' => 0, 'printing' => 0, 'printed' => 0, 'failed' => 0, 'not_printed' => 0, 'held_absent' => 0], 'delivery' => ['pending' => 0, 'delivered' => 0]];
    $where = '';
    $args = [];
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $where = ' WHERE punch_date = ?';
        $args[] = $date;
    }
    $hasDelivery = food_ticket_delivery_supported();
    $deliverySelect = $hasDelivery ? ', delivery_status' : '';
    try {
        $q = db()->prepare('SELECT print_status' . $deliverySelect . ' FROM food_ticket_events' . $where);
        $q->execute($args);
        foreach ($q->fetchAll() as $row) {
            $ps = (string) ($row['print_status'] ?? '');
            if (isset($out['print'][$ps])) {
                $out['print'][$ps]++;
            }
            if ($hasDelivery) {
                $ds = (string) ($row['delivery_status'] ?? 'pending');
                if (isset($out['delivery'][$ds])) {
                    $out['delivery'][$ds]++;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[food-delivery] counts failed: ' . $e->getMessage());
    }
    return $out;
}

/** تست واحد منطق (بدون چاپگر فیزیکی) */
function food_ticket_self_test_logic(): array
{
    $results = [];
    $results['parse_date_yyyymmdd'] = food_ticket_parse_date(20260501) === '2026-05-01';
    $results['parse_date_str'] = food_ticket_parse_date('20260501') === '2026-05-01';
    $results['parse_time_six'] = food_ticket_parse_time(130523) === '13:05:23';
    $results['parse_time_leading'] = food_ticket_parse_time(83000) === '08:30:00';
    $tpl = food_ticket_template();
    $text = food_ticket_render_from_template([
        'full_name' => 'علی تست',
        'national_code' => '001',
        'personnel_code' => '100',
        'food_type' => 'چلو',
        'punch_date' => '2026-05-01',
        'punch_time' => '13:05:23',
    ], $tpl);
    $results['template_has_name'] = str_contains($text, 'علی تست');
    $results['meal_windows_defined'] = count(food_ticket_meal_windows()) >= 3;
    return $results;
}

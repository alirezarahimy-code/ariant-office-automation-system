<?php
declare(strict_types=1);

if (!function_exists('food_ticket_source_table') && is_file(__DIR__ . '/food-ticket-env.php')) {
    require_once __DIR__ . '/food-ticket-env.php';
}

/**
 * موتور پایش دقیق — مستقل از UI
 * مسیر: SOURCE_TABLE (Access) → قوانین → food_ticket_events → Print Queue → Printer
 */

/**
 * کلید یکسان برای مقایسه کد پرسنلی
 * L_UID از Access: Number → 377 | متن: 0377 / 00377 / ۳۷۷
 */
function food_ticket_norm_personnel(string $code): string
{
    return food_ticket_code($code);
}

/**
 * فهرست کاربران با کد نرمال‌شده (نیم‌فاصله/فاصله/صفر ابتدایی/ارقام فارسی حذف یا یکسان می‌شود).
 * کش کوتاه‌مدت؛ هنگام «پیدا نشدن» حداکثر هر ۵ ثانیه یک بار تازه می‌شود تا کارمند تازه‌ثبت‌شده بلافاصله شناخته شود.
 */
function food_ticket_user_index(bool $refresh = false): array
{
    static $index = null;
    static $builtAt = 0;
    if ($refresh || $index === null || (time() - $builtAt) > 30) {
        $index = ['emp' => [], 'user' => [], 'nat' => [], 'id' => [], 'count' => 0, 'with_code' => 0];
        $rows = db()->query('SELECT id, full_name, employee_number, national_code, username, is_active FROM users')->fetchAll();
        foreach ($rows as $row) {
            $index['count']++;
            $index['id'][(int) ($row['id'] ?? 0)] = $row;
            foreach (['emp' => 'employee_number', 'user' => 'username', 'nat' => 'national_code'] as $bucket => $field) {
                $code = food_ticket_norm_personnel((string) ($row[$field] ?? ''));
                if ($code === '' || $code === '0' || $code === '-1') {
                    continue;
                }
                if ($bucket === 'emp') {
                    $index['with_code']++;
                }
                if (!isset($index[$bucket][$code])) {
                    $index[$bucket][$code] = $row;
                }
            }
        }
        $builtAt = time();
    }
    return $index;
}

/**
 * پیدا کردن کاربر سامانه از روی L_UID / کد پرسنلی
 * اولویت: employee_number (کد پرسنلی فرم کارمندان) → username → national_code
 * 377 ≡ 0377 ≡ 00377 ≡ ۳۷۷ ≡ 377.0 ≡ «377» با نیم‌فاصلهٔ نامرئی
 */
function food_ticket_find_user_strict(string $personnelCode): ?array
{
    $norm = food_ticket_norm_personnel($personnelCode);
    if ($norm === '' || $norm === '0' || $norm === '-1') {
        return null;
    }
    $lookup = static function (array $index) use ($norm): ?array {
        // کد دستگاه فقط کد پرسنلی (employee_number) یا نام کاربری است؛ کد ملی هرگز کد دستگاه نیست.
        return $index['emp'][$norm] ?? $index['user'][$norm] ?? null;
    };
    try {
        $found = $lookup(food_ticket_user_index());
        if ($found !== null) {
            return $found;
        }
        // پیدا نشد: یک بار فهرست را تازه کن (کارمند ممکن است همین حالا ثبت شده باشد)
        static $lastRefresh = 0;
        if ((time() - $lastRefresh) >= 5) {
            $lastRefresh = time();
            return $lookup(food_ticket_user_index(true));
        }
    } catch (Throwable $e) {
        error_log('[food-engine] user lookup failed: ' . $e->getMessage());
    }
    return null;
}

/**
 * حذف ردیف SOURCE_TABLE همراه با شمارش و ثبت نتیجهٔ واقعی روی خود فیش.
 * خروجی true = ردیف واقعاً حذف شد (یا حذف عمداً خاموش است و خطایی نباید شمرده شود)؛
 * false = حذف ناموفق — ردیف در SOURCE_TABLE می‌ماند و در پیام خلاصه با del_fail گزارش می‌شود.
 * در اجرای گروهی، $relatedEventIds شناسهٔ فیش‌های اعضا است تا نتیجه روی همهٔ رویدادهای مرتبط ثبت شود.
 */
function food_ticket_engine_delete_source($sourceDb, array $item, array $config, array &$summary, int $eventId = 0, array $relatedEventIds = []): bool
{
    $eventIds = array_values(array_unique(array_filter(
        array_map('intval', array_merge($eventId > 0 ? [$eventId] : [], $relatedEventIds)),
        static fn (int $id): bool => $id > 0
    )));
    $del = [];
    $ok = food_ticket_delete_source($sourceDb, $item, $config, $del);
    if ($ok) {
        $summary['source_deleted'] = (int) ($summary['source_deleted'] ?? 0) + 1;
        if (!$eventIds) {
            $found = food_ticket_existing_source(food_ticket_source_key($item));
            $foundId = $found ? (int) ($found['id'] ?? 0) : 0;
            if ($foundId > 0) {
                $eventIds[] = $foundId;
            }
        }
        if (function_exists('food_ticket_mark_source_delete')) {
            foreach ($eventIds as $id) {
                food_ticket_mark_source_delete((int) $id, true, (string) ($del['strategy'] ?? ''));
            }
        }
        return true;
    }
    if (!empty($del['skipped'])) {
        $summary['source_delete_skipped'] = (int) ($summary['source_delete_skipped'] ?? 0) + 1;
        return true; // انتخاب اپراتور (cut_source_rows خاموش)؛ خطا شمرده نمی‌شود
    }
    $summary['source_delete_failed'] = (int) ($summary['source_delete_failed'] ?? 0) + 1;
    $detail = (string) ($del['reason'] ?? '');
    if ($detail !== '') {
        $summary['source_delete_detail'] = mb_substr($detail, 0, 400);
    }
    if (function_exists('food_ticket_mark_source_delete')) {
        foreach ($eventIds as $id) {
            food_ticket_mark_source_delete((int) $id, false, $detail);
        }
    }
    return false;
}

function food_ticket_build_order_map($connection, string $table, string $foodDate): array
{
    if (!function_exists('food_order_mode') || !function_exists('food_order_internal_map')) {
        throw new RuntimeException('ماژول سفارش داخلی در Worker بارگذاری نشده است؛ Access سفارش خوانده نمی‌شود.');
    }
    if (food_order_mode() !== 'INTERNAL-DB') {
        throw new RuntimeException('Worker فقط به منبع سفارش داخلی مجاز است.');
    }
    return food_order_internal_map($foodDate);
}

function food_ticket_order_map_lazy($connection, string $table, string $foodDate): array
{
    // Compatibility signature retained for the existing engine; the connection/table are never used.
    if (!function_exists('food_order_mode') || !function_exists('food_order_internal_map')) {
        throw new RuntimeException('ماژول سفارش داخلی در Worker بارگذاری نشده است؛ Access سفارش خوانده نمی‌شود.');
    }
    if (food_order_mode() !== 'INTERNAL-DB') {
        throw new RuntimeException('Worker فقط به منبع سفارش داخلی مجاز است.');
    }
    return food_order_internal_map($foodDate);
}

function food_ticket_card_map_ensure(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        db()->exec('CREATE TABLE IF NOT EXISTS food_ticket_card_map (
            card_key VARCHAR(120) NOT NULL PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            personnel_code VARCHAR(100) NULL,
            hits INT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_food_card_map_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $ok = true;
    } catch (Throwable $e) {
        error_log('[food-engine] card map table unavailable: ' . $e->getMessage());
        $ok = false;
    }
    return $ok;
}

/** @return array<string,int> card_key => user_id (کش ۳۰ ثانیه‌ای) */
function food_ticket_card_map_load(bool $refresh = false): array
{
    static $map = null;
    static $loadedAt = 0;
    if (!food_ticket_card_map_ensure()) {
        return [];
    }
    if ($refresh || $map === null || (time() - $loadedAt) > 30) {
        $map = [];
        try {
            foreach (db()->query('SELECT card_key, user_id FROM food_ticket_card_map')->fetchAll() as $row) {
                $map[(string) $row['card_key']] = (int) $row['user_id'];
            }
        } catch (Throwable $e) {
            error_log('[food-engine] card map load failed: ' . $e->getMessage());
        }
        $loadedAt = time();
    }
    return $map;
}

function food_ticket_card_map_lookup(string $card): ?array
{
    $key = food_ticket_card_key($card);
    if ($key === '' || $key === '0') {
        return null;
    }
    $userId = food_ticket_card_map_load()[$key] ?? 0;
    if ($userId <= 0) {
        return null;
    }
    $user = food_ticket_user_index()['id'][$userId] ?? null;
    if ($user === null) {
        $user = food_ticket_user_index(true)['id'][$userId] ?? null;
    }
    return $user;
}

function food_ticket_card_map_learn(string $card, array $user): void
{
    $key = food_ticket_card_key($card);
    $userId = (int) ($user['id'] ?? 0);
    if ($key === '' || $key === '0' || $userId <= 0) {
        return;
    }
    if ((food_ticket_card_map_load()[$key] ?? 0) === $userId) {
        return; // قبلاً همین نگاشت ثبت شده؛ نوشتن تکراری در هر تردد لازم نیست
    }
    try {
        db()->prepare('INSERT INTO food_ticket_card_map (card_key, user_id, personnel_code, hits) VALUES (?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), personnel_code = VALUES(personnel_code), hits = hits + 1')
            ->execute([$key, $userId, food_ticket_code($user['employee_number'] ?? '')]);
        food_ticket_card_map_load(true);
    } catch (Throwable $e) {
        error_log('[food-engine] card map learn failed: ' . $e->getMessage());
    }
}

/**
 * صف چاپ را فقط با یک پردازنده در هر لحظه تخلیه می‌کند.
 * Worker، چاپ فوری داخل پایش و دکمه‌های پنل هر کدام می‌توانستند هم‌زمان صف را بخوانند و یک فیش را دو بار چاپ کنند.
 * اگر پردازندهٔ دیگری مشغول باشد، بدون انتظار برمی‌گردد (چرخهٔ بعد ادامه می‌دهد).
 *
 * @return array<string,mixed>
 */
function food_ticket_process_print_queue_locked(array $config, mixed ...$args): array
{
    if (!function_exists('food_ticket_process_print_queue')) {
        return ['printed' => 0, 'failed' => 0];
    }
    $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__);
    $path = $root . '/storage/food-print-queue.lock';
    if (!is_dir(dirname($path))) {
        @mkdir(dirname($path), 0750, true);
    }
    $handle = @fopen($path, 'c');
    if (!$handle) {
        return food_ticket_process_print_queue($config, ...$args);
    }
    if (!@flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return ['printed' => 0, 'failed' => 0, 'busy' => true];
    }
    try {
        return food_ticket_process_print_queue($config, ...$args);
    } finally {
        @flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * یک تردد فیزیکی (یک بار کشیدن کارت) گاهی چند ردیف در SOURCE_TABLE می‌سازد (مثلاً یک ردیف با L_UID و یک ردیف فقط با C_Card،
 * یا ردیف تکراری با ستون‌های کمی متفاوت). source_key از کل ردیف ساخته می‌شود، پس هر ردیف یک رویداد جدا می‌شد:
 * پایش دو رکورد نشان می‌داد و برای کارت مهمان (ticket_key = guest:source_key) دو فیش چاپ می‌شد.
 *
 * این تابع رویدادِ هم‌ارزِ ثبت‌شده در چند ثانیهٔ اطراف همین تردد را پیدا می‌کند (یا null).
 * فقط رویدادهایی که واقعاً تردد را «رسیدگی» کرده‌اند (فیش/مهمان/تکراری/خطای چاپ) به‌حساب می‌آیند؛
 * رویداد «ناشناس/بدون غذا/غیرفعال» ردیف دوم (که شاید اطلاعات بهتری دارد) را مسدود نمی‌کند.
 */
function food_ticket_find_twin_event(string $date, ?string $time, ?array $user, string $uid, string $card, bool $isGuest, int $windowSec = 4): ?array
{
    if ($time === null || $time === '') {
        return null;
    }
    try {
        $q = db()->prepare(
            'SELECT id, user_id, source_uid, source_card, event_type, print_status, punch_time FROM food_ticket_events '
            . 'WHERE punch_date = ? AND punch_time IS NOT NULL '
            . 'AND ABS(TIME_TO_SEC(punch_time) - TIME_TO_SEC(?)) <= ? '
            . 'AND event_type IN ("printed","guest","repeat","guest_limit","print_error") '
            . 'ORDER BY id DESC LIMIT 30'
        );
        $q->execute([$date, $time, $windowSec]);
        $rows = $q->fetchAll();
    } catch (Throwable $e) {
        error_log('[food-engine] twin lookup failed: ' . $e->getMessage());
        return null;
    }
    $ids = [];
    foreach ([$uid, $card] as $v) {
        if ($v !== '' && $v !== '0' && $v !== '-1') {
            $ids[] = $v;
        }
    }
    foreach ($rows as $row) {
        if ($user && !empty($row['user_id']) && (int) $row['user_id'] === (int) ($user['id'] ?? 0)) {
            return $row;
        }
        foreach ($ids as $a) {
            foreach ([(string) ($row['source_uid'] ?? ''), (string) ($row['source_card'] ?? '')] as $b) {
                if ($b !== '' && food_ticket_card_equals($a, $b)) {
                    return $row;
                }
            }
        }
        // مهمان: دو ردیفِ هم‌ثانیهٔ یک کشیدن کارت ممکن است شناسهٔ متفاوتی بنویسند (یکی UID، یکی شمارهٔ کارت).
        if ($isGuest && (string) ($row['event_type'] ?? '') === 'guest'
            && abs(strtotime('1970-01-01 ' . (string) $row['punch_time']) - strtotime('1970-01-01 ' . $time)) <= 2) {
            return $row;
        }
    }
    return null;
}

/**
 * ردیف‌های SOURCE_TABLE را بر اساس زمان مرتب می‌کند و در یک ثانیهٔ مشترک ردیفِ دارای L_UID را جلوتر می‌گذارد
 * تا ردیفِ فقط-کارت بعد از آن، با نگاشت کارت↔پرسنل، همان کاربر شناخته شود (نه ناشناس/مهمان).
 *
 * @param list<array<string,mixed>> $items
 * @return list<array<string,mixed>>
 */
function food_ticket_sort_punches(array $items): array
{
    $keyed = [];
    foreach ($items as $i => $item) {
        $d = (string) (food_ticket_parse_date($item['date_raw'] ?? null) ?? '');
        $t = (string) (food_ticket_parse_time($item['time_raw'] ?? null) ?? '');
        $uid = food_ticket_code($item['uid'] ?? '');
        $hasUid = ($uid !== '' && $uid !== '0' && $uid !== '-1') ? 0 : 1;
        $keyed[] = [$d . ' ' . $t, $hasUid, $i, $item];
    }
    usort($keyed, static function (array $a, array $b): int {
        return [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]];
    });
    return array_map(static fn (array $k): array => $k[3], $keyed);
}

/**
 * تصمیم برای یک ردیف source_row
 * @return array{event_type:string,print:bool,delete:bool,payload:array,food?:string}
 */
/** حالت سقف فیش مهمان: «requested» (جمع درخواست مهمان معاونت‌ها در روز جاری) یا «variable» (تعداد نفرات داخل ساختمان). */
function food_guest_cap_mode(): string
{
    $mode = function_exists('setting') ? (string) (setting('food_guest_cap_mode', 'requested') ?? 'requested') : 'requested';
    return $mode === 'variable' ? 'variable' : 'requested';
}

/** نرمال‌سازی ساعت (HH:MM یا HH:MM:SS) به HH:MM:SS؛ اگر معتبر نبود پیش‌فرض. */
function food_guest_cap_clock($raw, string $default): string
{
    $raw = (string) ($raw ?? '');
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $raw)) {
        return $default;
    }
    return strlen($raw) === 5 ? $raw . ':00' : $raw;
}

/** ساعت شروع محاسبهٔ سقف متغیر (پیش‌فرض ۱۱:۰۰). */
function food_guest_cap_start(): string
{
    $raw = function_exists('setting') ? setting('food_guest_cap_start', '11:00') : '11:00';
    return food_guest_cap_clock($raw, '11:00:00');
}

/** ساعت صفر شدن سقف متغیر (پیش‌فرض ۱۵:۰۰). */
function food_guest_cap_end(): string
{
    $raw = function_exists('setting') ? setting('food_guest_cap_end', '15:00') : '15:00';
    return food_guest_cap_clock($raw, '15:00:00');
}

/** ساعت قطع سقف متغیر (HH:MM:SS). خروج مراجعین قبل از این ساعت (تا ۱۲:۰۰:۵۹) از سقف کم می‌شود؛ بعد از آن نه. */
function food_guest_cap_cutoff(): string
{
    $raw = function_exists('setting') ? setting('food_guest_cap_cutoff', '12:01') : '12:01';
    return food_guest_cap_clock($raw, '12:01:00');
}

/**
 * سقف متغیر = (تعداد کسانی که از اول روز وارد شده‌اند تا آن لحظه) − (تعداد کسانی که قبل از ساعت قطع خارج شده‌اند).
 * خروج بعد از ساعت قطع سقف را کم نمی‌کند، چون آن افراد فیش گرفته و غذا خورده‌اند.
 * ورودی و خروج‌های قبل از ۱۱ هم در محاسبه‌اند؛ کسانی که قبل از ۱۱ آمده و هنوز داخل‌اند، ساعت ۱۱ جزو سقف‌اند.
 */
function food_guest_variable_cap_from_counts(int $entries, int $earlyExits): int
{
    return max(0, $entries - $earlyExits);
}

/** سقف متغیر در یک ساعت مشخص؛ بیرون از بازهٔ شروع تا پایان (تنظیمی) صفر است. */
function food_guest_variable_cap_at(string $time, int $entries, int $earlyExits): int
{
    $start = food_guest_cap_start();
    $end = food_guest_cap_end();
    if ($end <= $start) {
        $start = '11:00:00';
        $end = '15:00:00';
    }
    if ($time < $start || $time >= $end) {
        return 0;
    }
    return food_guest_variable_cap_from_counts($entries, $earlyExits);
}

/**
 * سقف متغیر در لحظهٔ رویداد از جدول تردد. null یعنی تردد در دسترس نیست و سقف ثابت استفاده می‌شود.
 */
function food_ticket_guest_variable_cap(?string $date, ?string $time): ?int
{
    if ($date === null || $date === '' || $time === null || $time === '') {
        return null;
    }
    $cutoff = food_guest_cap_cutoff();
    try {
        $query = db()->prepare(
            'SELECT (SELECT COUNT(*) FROM traffic_visits WHERE visit_date = ? AND entry_time IS NOT NULL AND entry_time <= ?) AS entries,'
            . ' (SELECT COUNT(*) FROM traffic_visits WHERE visit_date = ? AND exit_time IS NOT NULL AND exit_time < ? AND exit_time <= ?) AS early_exits'
        );
        $query->execute([$date, $time, $date, $cutoff, $time]);
        $row = $query->fetch(PDO::FETCH_ASSOC) ?: ['entries' => 0, 'early_exits' => 0];
        return food_guest_variable_cap_at($time, (int) $row['entries'], (int) $row['early_exits']);
    } catch (Throwable $exception) {
        return null;
    }
}

/**
 * آیا فیش مهمانِ جدید مجاز نیست؟
 * سقف کل = تعداد داخل ساختمان در همان لحظه (اگر تردد در دسترس باشد)، وگرنه سقف ثابت قدیمی.
 * فیش‌های قبلاً صادرشده برگردانده نمی‌شوند؛ فقط صدور جدید بسته می‌شود.
 */
function food_ticket_guest_cap_reached(int $issuedToday, ?int $insideNow, int $fallbackMax, int $cardIssued, int $cardLimit): bool
{
    $capActive = $insideNow !== null || $fallbackMax > 0;
    $totalCap = $insideNow ?? $fallbackMax;
    if ($capActive && $issuedToday >= $totalCap) {
        return true;
    }
    return $cardLimit > 0 && $cardIssued >= $cardLimit;
}

function food_ticket_decide_punch(array $item, array $config, array &$orderMaps): array
{
    // C_Date=20261002 ، C_Time=143940 ، L_UID=Number (377/0377/00377)
    $date = food_ticket_parse_date($item['date_raw'] ?? null);
    $time = food_ticket_parse_time($item['time_raw'] ?? null);
    $uid = food_ticket_code($item['uid'] ?? '');
    $card = food_ticket_code($item['card'] ?? '');
    if ($card === '0') {
        $card = trim((string) ($item['card'] ?? ''));
    }
    $sourceKey = food_ticket_source_key($item);
    $payload = json_encode($item['row'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $base = [
        'source_key' => $sourceKey,
        'source_uid' => $uid,
        'source_card' => $card,
        'punch_date' => $date,
        'punch_time' => $time,
        'personnel_code' => ($uid !== '' && $uid !== '0' && $uid !== '-1') ? $uid : $card,
        'source_payload' => $payload,
    ];

    if ($date === null) {
        return [
            'event_type' => 'config_error',
            'print' => false,
            'delete' => false, // تاریخ نامعتبر را حذف نکن تا بعداً بررسی شود
            'payload' => $base + ['last_error' => 'تاریخ تردد از SOURCE_TABLE قابل تشخیص نیست'],
        ];
    }

    // امروز/دیروز به وقت تهران (C_Date مثل 20261002)
    try {
        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')))->format('Y-m-d');
    } catch (Throwable) {
        $today = date('Y-m-d');
    }
    $minDate = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
    if ($date < $minDate || $date > $today) {
        return [
            'event_type' => 'skipped_old',
            'print' => false,
            'delete' => (bool) (int) ($config['cut_source_rows'] ?? 1),
            'payload' => $base + ['event_type' => 'config_error', 'last_error' => 'تردد خارج از بازه: ' . $date . ' (امروز=' . $today . ')'],
        ];
    }

    // قبلاً پردازش شده؟
    $existing = food_ticket_existing_source($sourceKey);
    if ($existing) {
        $st = (string) ($existing['print_status'] ?? '');
        if (food_ticket_can_delete_source_after_print($st)) {
            return ['event_type' => 'duplicate', 'print' => false, 'delete' => true, 'payload' => $base, 'existing_id' => (int) $existing['id']];
        }
        if (in_array($st, ['pending', 'printing'], true)) {
            return ['event_type' => 'duplicate', 'print' => false, 'delete' => true, 'payload' => $base, 'existing_id' => (int) $existing['id']];
        }
        if (in_array($st, ['print_error', 'failed'], true)) {
            $retryLimit = max(1, (int) (food_ticket_template()['retry_count'] ?? 5));
            $attempts = max((int) ($existing['retry_count'] ?? 0), (int) ($existing['print_attempts'] ?? 0));
            if ($attempts >= $retryLimit) {
                return ['event_type' => 'retry_exhausted', 'print' => false, 'delete' => true, 'payload' => $base, 'existing_id' => (int) $existing['id']];
            }
            return ['event_type' => 'retry', 'print' => true, 'delete' => false, 'payload' => $base, 'existing_id' => (int) $existing['id']];
        }
        // رویداد قبلاً در پایگاه داده ثبت شده است؛ منبع را می‌توان با اطمینان پاک کرد.
        return ['event_type' => (string) ($existing['event_type'] ?? 'duplicate'), 'print' => false, 'delete' => true, 'payload' => $base];
    }

    // قانون تشخیص:
    // 1) اگر L_UID (شماره کاربری) در لیست کارکنان باشد → پرسنل (حتی اگر C_Card هم پر باشد؛ کارت RFID پرسنل)
    // 2) مهمان فقط وقتی: شماره کارت در فرم کارت مهمان تعریف شده و تردد به‌عنوان پرسنل شناخته نشده باشد
    $user = null;
    if ($uid !== '' && $uid !== '0' && $uid !== '-1') {
        $user = food_ticket_find_user_strict($uid);
        if ($user && $card !== '' && $card !== '0' && !food_ticket_card_equals($card, $uid)) {
            food_ticket_card_map_learn($card, $user); // کارت این پرسنل را به‌خاطر بسپار
        }
    }
    // ردیف فقط-کارت (L_UID خالی/۰/نامنطبق): اگر این کارت قبلاً با L_UID یک پرسنل دیده شده، همان پرسنل است، نه مهمان.
    if (!$user && $card !== '' && $card !== '0') {
        $mapped = food_ticket_card_map_lookup($card);
        if ($mapped) {
            $user = $mapped;
            $mappedCode = food_ticket_code($mapped['employee_number'] ?? '');
            if ($mappedCode !== '') {
                $base['personnel_code'] = $mappedCode;
            }
        }
    }

    $guestCard = null;
    if (!$user) {
        // فقط از روی C_Card؛ مهمان = کارتی که صرفاً در فرم کارت مهمان ثبت شده
        if ($card !== '') {
            $guestCard = food_ticket_guest_card($card, $config);
        }
        // اگر دستگاه کارت را فقط در L_UID گذاشته و آن UID پرسنل نیست
        if ($guestCard === null && $uid !== '' && $uid !== '0' && $uid !== '-1') {
            $guestCard = food_ticket_guest_card($uid, $config);
        }
    }

    if (($base['personnel_code'] ?? '') === '' || ($base['personnel_code'] ?? '') === '0') {
        $base['personnel_code'] = ($uid !== '' && $uid !== '0' && $uid !== '-1') ? $uid : $card;
    }

    // همان تردد فیزیکی که قبلاً (با ردیف دیگری از SOURCE_TABLE) ثبت/چاپ شده؟ → رکورد و فیش تکراری ساخته نشود.
    $twin = food_ticket_find_twin_event($date, $time, $user, $uid, $card, (!$user && $guestCard !== null));
    if ($twin !== null) {
        return ['event_type' => 'duplicate', 'print' => false, 'delete' => true, 'payload' => $base, 'existing_id' => (int) $twin['id'], 'twin' => true];
    }

    // مهمان
    if (!$user && $guestCard !== null) {
        $guestName = trim((string) ($guestCard['guest_name'] ?? '')) ?: 'مهمان';
        $guestCountQ = db()->prepare('SELECT COUNT(*) FROM food_ticket_events WHERE punch_date = ? AND event_type = "guest" AND print_status IN ("pending","printing","printed")');
        $guestCountQ->execute([$date]);
        $guestCount = (int) $guestCountQ->fetchColumn();
        $cardCountQ = db()->prepare('SELECT COUNT(*) FROM food_ticket_events WHERE punch_date = ? AND source_card = ? AND event_type = "guest" AND print_status IN ("pending","printing","printed")');
        $cardCountQ->execute([$date, $card]);
        $cardCount = (int) $cardCountQ->fetchColumn();
        $cardLimit = (int) ($guestCard['daily_limit'] ?? 0);
        $maxGuest = (int) ($config['max_guest_daily'] ?? 0);
                // حالت «متغیر»: سقف از تعداد داخل ساختمان؛ حالت «درخواست مهمان»: سقف = جمع مهمان‌های درخواستی مدیران برای همان روز.
        $guestMode = food_guest_cap_mode();
        $variableCap = null;
        if ($guestMode === 'variable') {
            $variableCap = food_ticket_guest_variable_cap($date, $time);
        } elseif ($guestMode === 'requested' && function_exists('food_guest_requested_total')) {
            $variableCap = food_guest_requested_total((string) $date);
        }
        if (food_ticket_guest_cap_reached($guestCount, $variableCap, $maxGuest, $cardCount, $cardLimit)) {
            return [
                'event_type' => 'guest_limit',
                'print' => false,
                'delete' => true,
                'payload' => $base + ['full_name' => $guestName, 'food_type' => 'مهمان', 'event_type' => 'guest_limit'],
            ];
        }
        return [
            'event_type' => 'guest',
            'print' => true,
            'delete' => true,
            'food' => 'مهمان',
            'payload' => $base + [
                'full_name' => $guestName,
                'food_type' => 'مهمان',
                'ticket_key' => 'guest:' . $sourceKey,
                'event_type' => 'guest',
                'print_status' => 'pending',
            ],
        ];
    }

    if (!$user) {
        return [
            'event_type' => 'unknown',
            'print' => false,
            'delete' => true,
            'payload' => $base + [
                'event_type' => 'unknown',
                'full_name' => 'کاربر ناشناس',
                'last_error' => 'L_UID=' . ($uid !== '' ? $uid : '(خالی)') . ' | C_Card=' . ($card !== '' ? $card : '(خالی)')
                    . ' با هیچ کد پرسنلی/نام کاربری/کد ملی در جدول کاربران برابر نبود (کاربران دارای کد پرسنلی: '
                    . (int) (food_ticket_user_index()['with_code'] ?? 0) . ')',
            ],
        ];
    }

    return food_ticket_decide_for_user($user, $date, $base, $config, $orderMaps);
}

/**
 * تصمیم برای یک کاربر مشخص (فعال بودن ← کد ملی ← سفارش امروز ← فیش قبلی ← چاپ).
 * هم مسیر تردد عادی (decide_punch) و هم کارت گروهی از همین تابع استفاده می‌کنند تا منطق «سفارش» و
 * «یک فیش در روز برای هر نفر» فقط یک نسخه داشته باشد.
 *
 * @param array<string,mixed> $user ردیف users (id, full_name, national_code, is_active, ...)
 * @param array<string,mixed> $base فیلدهای مشترک رویداد (source_key, punch_date, ...)
 * @return array{event_type:string,print:bool,delete:bool,payload:array,food?:string}
 */
function food_ticket_decide_for_user(array $user, string $date, array $base, array $config, array &$orderMaps): array
{
    if (!(int) ($user['is_active'] ?? 0)) {
        return [
            'event_type' => 'inactive',
            'print' => false,
            'delete' => true,
            'payload' => $base + [
                'user_id' => $user['id'],
                'national_code' => $user['national_code'] ?? '',
                'full_name' => $user['full_name'] ?? '',
                'event_type' => 'inactive',
            ],
        ];
    }

    // محدودهٔ ساعت وعده (نهار/صبحانه/شام) عمداً بررسی نمی‌شود: ساعت مجاز ثبت تردد در خود سامانهٔ حضور و غیاب تنظیم می‌شود
    // و هر ردیفی که به SOURCE_TABLE برسد معتبر است. جلوگیری از چاپ مجدد با کلید «یک فیش در روز برای هر نفر» انجام می‌شود.

    $national = food_ticket_digits($user['national_code'] ?? '');
    if ($national === '') {
        return [
            'event_type' => 'no_food',
            'print' => false,
            'delete' => true,
            'payload' => $base + [
                'user_id' => $user['id'],
                'full_name' => $user['full_name'] ?? '',
                'event_type' => 'no_food',
                'last_error' => 'کد ملی کارمند خالی است',
            ],
        ];
    }

    if (!isset($orderMaps[$date])) {
        $orderMaps[$date] = food_ticket_order_map_lazy(null, 'food_orders', $date);
    }
    $conflictCodes = $GLOBALS['__food_order_conflicts'][$date] ?? [];
    if (in_array($national, $conflictCodes, true) || in_array(ltrim($national, '0') ?: '0', $conflictCodes, true)) {
        return [
            'event_type' => 'config_error',
            'print' => false,
            'delete' => true,
            'payload' => $base + [
                'user_id' => $user['id'],
                'national_code' => $national,
                'full_name' => $user['full_name'] ?? '',
                'event_type' => 'config_error',
                'last_error' => 'کد ملی این کارمند در سفارش‌های فعال این روز تکراری است؛ فیش چاپ نشد. سفارش‌ها را بررسی کنید.',
            ],
        ];
    }
    $food = $orderMaps[$date][$national]
        ?? $orderMaps[$date][ltrim($national, '0') ?: '0']
        ?? null;

    if ($food === null || $food === '') {
        return [
            'event_type' => 'no_food',
            'print' => false,
            'delete' => true,
            'payload' => $base + [
                'user_id' => $user['id'],
                'national_code' => $national,
                'full_name' => $user['full_name'] ?? '',
                'event_type' => 'no_food',
            ],
        ];
    }

    // یک فیش در روز برای هر نفر
    $ticketKey = 'food:' . $date . ':' . $national;
    $existingTicket = food_ticket_existing_ticket($ticketKey);
    if ($existingTicket) {
        return [
            'event_type' => 'repeat',
            'print' => false,
            'delete' => true,
            'payload' => $base + [
                'user_id' => $user['id'],
                'national_code' => $national,
                'full_name' => $user['full_name'] ?? '',
                'food_type' => (function_exists('food_ticket_utf8') ? food_ticket_utf8((string) $food) : (string) $food),
                'event_type' => 'repeat',
            ],
        ];
    }

    return [
        'event_type' => 'print',
        'print' => true,
        'delete' => true,
        'food' => $food,
        'payload' => $base + [
            'user_id' => $user['id'],
            'national_code' => $national,
            'full_name' => $user['full_name'] ?? '',
            'food_type' => (function_exists('food_ticket_utf8') ? food_ticket_utf8((string) $food) : (string) $food),
            'ticket_key' => $ticketKey,
            'event_type' => 'printed',
            'print_status' => 'pending',
        ],
    ];
}

/**
 * ردیف‌های امروزِ SOURCE_TABLE را (قدیمی‌ترین اول) برمی‌گرداند؛ ردیف‌های روزهای قبل دست‌نخورده می‌مانند.
 * ردیف‌های قدیمی نباید «سهمیهٔ TOP N» را اشغال کنند، وگرنه تردد امروز هرگز خوانده نمی‌شود.
 *
 * @param array{raw:int,old:int,filter:string} $stats
 */
function food_ticket_read_source_rows_today($sourceDb, int $limit, string $todayYmd, array &$stats): array
{
    static $goodMode = null;
    static $lastFallbackAt = 0;
    // امروز و دیروز (تردد نیمه‌شب): ردیف‌های قدیمی‌تر دست‌نخورده می‌مانند.
    $yesterdayYmd = (DateTimeImmutable::createFromFormat('Ymd', $todayYmd) ?: new DateTimeImmutable('today'))
        ->modify('-1 day')->format('Ymd');
    $allowedDays = [$todayYmd, $yesterdayYmd];
    // ستون C_Date در فایل منبع تردد متنی است (فیلتر عددی «Data type mismatch» می‌دهد)، پس متنی اول امتحان می‌شود.
    $filters = [
        'text' => "(C_Date IN ('" . $todayYmd . "','" . $yesterdayYmd . "'))",
        'num' => '(C_Date IN (' . $todayYmd . ',' . $yesterdayYmd . '))',
    ];
    $modes = array_keys($filters);
    if ($goodMode !== null && isset($filters[$goodMode])) {
        $modes = array_values(array_unique(array_merge([$goodMode], $modes)));
    }
    $isToday = static function (array $it) use ($allowedDays): bool {
        $d = food_ticket_parse_date($it['date_raw'] ?? null);
        return $d === null || in_array(str_replace('-', '', $d), $allowedDays, true); // تاریخ نامعتبر عبور می‌کند تا ثبت و بررسی شود
    };

    foreach ($modes as $mode) {
        try {
            $rows = food_ticket_access_rows($sourceDb, food_ticket_source_table(), $limit, 'C_Date ASC, C_Time ASC', $filters[$mode], true);
        } catch (Throwable $e) {
            if ($goodMode === $mode) {
                $goodMode = null;
            }
            error_log('[food-engine] source_row filter(' . $mode . ') failed: ' . $e->getMessage());
            continue;
        }
        $goodMode = $mode;
        $stats['filter'] = $mode;
        $stats['raw'] = count($rows);
        $today = array_values(array_filter($rows, $isToday));
        if ($today) {
            $stats['old'] = count($rows) - count($today);
            return $today;
        }
        break; // فیلتر معتبر است ولی ردیفی نداد؛ برای تاریخ شمسی مسیر ۲ را هم امتحان کن.
    }

    // فیلتر امروز معتبر بود و ردیفی نداد: خواندن دوم (برای تاریخ‌های با قالب دیگر) فقط هر ۳۰ ثانیه، نه در هر چرخهٔ ۲ ثانیه‌ای.
    if ($goodMode !== null) {
        if ((time() - $lastFallbackAt) < 30) {
            return [];
        }
        $lastFallbackAt = time();
    }
    try {
        $all = food_ticket_access_rows($sourceDb, food_ticket_source_table(), $limit, 'C_Date DESC, C_Time DESC', null, true);
    } catch (Throwable $e) {
        error_log('[food-engine] source_row ordered read failed, using unordered read: ' . $e->getMessage());
        $all = food_ticket_access_rows($sourceDb, food_ticket_source_table(), $limit);
    }
    $stats['raw'] = count($all);
    $today = array_values(array_filter($all, $isToday));
    $stats['old'] = count($all) - count($today);
    $stats['filter'] = $stats['filter'] === 'none' ? 'php' : $stats['filter'] . '+php';
    return array_reverse($today);
}

function food_ticket_process_batch_v2(?int $requestedLimit = null): array
{
    $config = food_ticket_config(true);
    $summary = [
        'processed' => 0,
        'queued' => 0,
        'printed' => 0,
        'errors' => 0,
        'skipped' => 0,
        'unknown' => 0,
        'no_food' => 0,
        'guest' => 0,
        'repeat' => 0,
        'message' => '',
    ];
    $lockPath = APP_ROOT . '/storage/food-ticket.lock';
    if (!is_dir(dirname($lockPath))) {
        @mkdir(dirname($lockPath), 0750, true);
    }
    $lock = @fopen($lockPath, 'c');
    if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        $summary['message'] = 'پردازشگر دیگری در حال اجراست.';
        return $summary;
    }

    $sourceDb = null;
    // Kept as a null compatibility argument for legacy decision hooks; no order DB connection exists.
    try {
        if (!(int) ($config['enabled'] ?? 0)) {
            $summary['message'] = 'پردازش چاپ فیش غیرفعال است.';
            return $summary;
        }

        $attendancePath = trim((string) ($config['attendance_path'] ?? ''));
        if (!function_exists('food_order_mode') || !function_exists('food_order_internal_map') || food_order_mode() !== 'INTERNAL-DB') {
            throw new RuntimeException('منبع سفارش داخلی آماده نیست؛ Worker حق اتصال به Access سفارش را ندارد.');
        }
        if ($attendancePath === '') {
            $summary['message'] = 'مسیر منبع تردد TENTER تنظیم نشده است.';
            $summary['errors'] = 1;
            return $summary;
        }

        $sourceDb = food_ticket_odbc($attendancePath, function_exists('food_ticket_attendance_password') ? food_ticket_attendance_password($config) : food_ticket_attendance_factory_password());
        // سفارش‌ها فقط از food_orders می‌آیند؛ هیچ Closure یا اتصال ODBC سفارش ساخته نمی‌شود.

        $limit = max(1, min(500, $requestedLimit ?: (int) ($config['max_batch'] ?? 100)));
        $todayYmd = date('Ymd');

        $readStats = ['raw' => 0, 'old' => 0, 'filter' => 'none'];
        $items = food_ticket_read_source_rows_today($sourceDb, $limit, $todayYmd, $readStats);
        $summary['source_raw'] = $readStats['raw'];
        $summary['source_old'] = $readStats['old'];
        $items = food_ticket_sort_punches($items);

        $orderMaps = [];
        foreach ($items as $item) {
            try {
                // گروه غذا: نماینده با L_UID شناسایی می‌شود (کارت فقط برای گروه‌های قدیمی).
                // اعضای واجد شرایط پیش از تشخیص کاربر/مهمان پردازش می‌شوند و ردیف SOURCE_TABLE خودشان مصرف نمی‌شود.
                if (function_exists('food_ticket_group_try_process')) {
                    try {
                        $groupResult = food_ticket_group_try_process($item, $config, $orderMaps, $summary);
                    } catch (Throwable $groupEx) {
                        // خطای ماژول گروه، پردازش عادی بقیهٔ ردیف‌ها را متوقف نمی‌کند و ردیف دست‌نخورده می‌ماند.
                        $summary['errors']++;
                        $summary['last_error'] = 'خطای پردازش گروه: ' . $groupEx->getMessage();
                        error_log('[food-groups] hook failed: ' . $groupEx->getMessage());
                        continue;
                    }
                    if ($groupResult !== null) {
                        $groupEventIds = is_array($groupResult['event_ids'] ?? null) ? $groupResult['event_ids'] : [];
                        if (!empty($groupResult['delete'])) {
                            if (!food_ticket_engine_delete_source($sourceDb, $item, $config, $summary, 0, $groupEventIds)) {
                                $summary['errors']++;
                                $summary['last_error'] = 'حذف ردیف گروه از SOURCE_TABLE ناموفق بود (uid=' . ($item['uid'] ?? '') . ')';
                            }
                        } elseif ($groupEventIds && function_exists('food_ticket_mark_source_delete')) {
                            $note = (string) ($groupResult['delete_note'] ?? 'حذف منبع تا رفع خطای پردازش گروه به تعویق افتاد.');
                            foreach ($groupEventIds as $groupEventId) {
                                food_ticket_mark_source_delete((int) $groupEventId, false, $note);
                            }
                        }
                        continue;
                    }
                }
                $decision = food_ticket_decide_punch($item, $config, $orderMaps);
                $type = $decision['event_type'];
                $payload = $decision['payload'];

                if ($type === 'duplicate') {
                    $summary['skipped']++;
                    if (!empty($decision['delete']) && !food_ticket_engine_delete_source($sourceDb, $item, $config, $summary, (int) ($decision['existing_id'] ?? 0))) {
                        $summary['errors']++;
                        $summary['last_error'] = 'حذف رکورد تکراری از SOURCE_TABLE ناموفق بود (uid=' . ($item['uid'] ?? '') . ')';
                    }
                    continue;
                }

                if ($type === 'retry' && !empty($decision['existing_id'])) {
                    $eventId = (int) $decision['existing_id'];
                    food_ticket_enqueue_print($eventId, $config);
                    if (!food_ticket_engine_delete_source($sourceDb, $item, $config, $summary, $eventId)) {
                        $summary['errors']++;
                        $summary['last_error'] = 'حذف رکورد ثبت‌شده از SOURCE_TABLE ناموفق بود (uid=' . ($item['uid'] ?? '') . ')';
                    }
                    $summary['queued']++;
                    $summary['processed']++;
                    continue;
                }

                if ($type === 'retry_exhausted') {
                    $summary['skipped']++;
                    if (!empty($decision['delete']) && !food_ticket_engine_delete_source($sourceDb, $item, $config, $summary, (int) ($decision['existing_id'] ?? 0))) {
                        $summary['errors']++;
                        $summary['last_error'] = 'حذف رکورد دارای خطای ثبت‌شده از SOURCE_TABLE ناموفق بود (uid=' . ($item['uid'] ?? '') . ')';
                    }
                    continue;
                }

                if ($type === 'skipped_old') {
                    // فقط حذف از source_row بدون ثبت دوباره
                    if (!empty($decision['delete'])) {
                        @food_ticket_engine_delete_source($sourceDb, $item, $config, $summary, (int) ($decision['existing_id'] ?? 0));
                    }
                    $summary['skipped']++;
                    continue;
                }

                $eventId = 0;
                try {
                    $eventId = food_ticket_add_event($payload);
                } catch (PDOException $pdoEx) {
                    // نقض یکتایی ticket_key / source_key
                    if (str_contains($pdoEx->getMessage(), 'Duplicate') || (int) $pdoEx->getCode() === 23000) {
                        $summary['repeat']++;
                        $existingEvent = null;
                        $sourceKey = (string) ($payload['source_key'] ?? '');
                        if ($sourceKey !== '') {
                            $existingEvent = food_ticket_existing_source($sourceKey);
                        }
                        $ticketKey = (string) ($payload['ticket_key'] ?? '');
                        if (!$existingEvent && $ticketKey !== '') {
                            $existingEvent = food_ticket_existing_ticket($ticketKey);
                        }
                        if ($existingEvent && !food_ticket_engine_delete_source($sourceDb, $item, $config, $summary, (int) ($existingEvent['id'] ?? 0))) {
                            $summary['errors']++;
                            $summary['last_error'] = 'حذف منبع رویداد ثبت‌شده از SOURCE_TABLE ناموفق بود (uid=' . ($item['uid'] ?? '') . ')';
                        }
                        continue;
                    }
                    throw $pdoEx;
                }

                if ($eventId <= 0) {
                    throw new RuntimeException('ثبت پایدار رویداد پایش انجام نشد؛ رکورد SOURCE_TABLE حذف نشد.');
                }

                if (!empty($decision['print']) && $eventId > 0) {
                    food_ticket_enqueue_print($eventId, $config);
                    $summary['queued']++;
                    if ($type === 'guest') {
                        $summary['guest']++;
                    }
                    // چاپ فوری: فیش را همین‌جا، پیش از حذف از Access و پردازش ردیف بعدی، چاپ کن.
                    try {
                        $early = food_ticket_process_print_queue_locked($config, 3);
                        $summary['queue_early_printed'] = (int) ($summary['queue_early_printed'] ?? 0) + (int) ($early['printed'] ?? 0);
                        $summary['queue_early_failed'] = (int) ($summary['queue_early_failed'] ?? 0) + (int) ($early['failed'] ?? 0);
                    } catch (Throwable $earlyEx) {
                        error_log('[food-engine] early print failed: ' . $earlyEx->getMessage());
                    }
                } else {
                    if ($type === 'no_food' || $type === 'outside_meal') {
                        $summary['no_food']++;
                    } elseif ($type === 'unknown') {
                        $summary['unknown']++;
                    } elseif ($type === 'repeat') {
                        $summary['repeat']++;
                    } else {
                        $summary['skipped']++;
                    }
                }

                // رویداد/صف چاپ اکنون پایدار است؛ تکرار و تلاش مجدد از همین رکورد انجام می‌شود.
                $okDel = food_ticket_engine_delete_source($sourceDb, $item, $config, $summary, $eventId);
                if (!$okDel) {
                    $summary['errors']++;
                    $summary['last_error'] = 'حذف از SOURCE_TABLE ناموفق بود (uid=' . ($item['uid'] ?? '') . ')';
                    error_log('[food-engine] delete source_row failed uid=' . ($item['uid'] ?? ''));
                }

                $summary['processed']++;
            } catch (Throwable $ex) {
                $summary['errors']++;
                $summary['last_error'] = $ex->getMessage();
                error_log('[food-engine] row error: ' . $ex->getMessage());
                // ادامهٔ ردیف بعدی — worker متوقف نمی‌شود
            }
        }

        // تخلیه صف چاپ
        $queue = ['printed' => 0, 'failed' => 0];
        if (function_exists('food_ticket_process_print_queue')) {
            $queue = food_ticket_process_print_queue_locked($config);
        }
        $summary['queue_printed'] = (int) ($queue['printed'] ?? 0) + (int) ($summary['queue_early_printed'] ?? 0);
        $summary['queue_failed'] = (int) ($queue['failed'] ?? 0) + (int) ($summary['queue_early_failed'] ?? 0);
        $summary['printed'] = $summary['queue_printed'];
        $summary['errors'] += $summary['queue_failed'];
        if (!empty($summary['group_runs']) || !empty($summary['group_unknown'])) {
            $summary['message_groups'] = sprintf(
                'groups=%d | group_absent=%d | group_unknown=%d',
                (int) ($summary['group_runs'] ?? 0),
                (int) ($summary['absent'] ?? 0),
                (int) ($summary['group_unknown'] ?? 0)
            );
        }
        $summary['message'] = sprintf(
            'source_row=%d | raw=%d | old=%d | ok=%d | queued=%d | queue_print=%d | no_food=%d | unknown=%d | guest=%d | repeat=%d | err=%d | del=%d | del_fail=%d',
            count($items),
            (int) ($summary['source_raw'] ?? 0),
            (int) ($summary['source_old'] ?? 0),
            $summary['processed'],
            $summary['queued'],
            $summary['queue_printed'],
            $summary['no_food'],
            $summary['unknown'],
            $summary['guest'],
            $summary['repeat'],
            $summary['errors'],
            (int) ($summary['source_deleted'] ?? 0),
            (int) ($summary['source_delete_failed'] ?? 0)
        );
        if (!empty($summary['last_error'])) {
            $summary['message'] .= ' | detail: ' . $summary['last_error'];
        }
        if (!empty($summary['source_delete_detail'])) {
            $summary['message'] .= ' | delete_detail: ' . (string) $summary['source_delete_detail'];
        }
        return $summary;
    } finally {
        if (function_exists('food_ticket_access_close')) { food_ticket_access_close($sourceDb); } elseif (is_resource($sourceDb)) { @odbc_close($sourceDb); }
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
}

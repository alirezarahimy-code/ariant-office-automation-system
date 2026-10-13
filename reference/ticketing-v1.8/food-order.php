<?php
declare(strict_types=1);

/**
 * برنامه غذایی و سفارش داخلی.
 * فقط پنج جدول جدید این ماژول را ایجاد می‌کند؛ روی جدول‌های موجود DDL اجرا نمی‌شود.
 */

function food_order_schema_ensure(): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    $tables = ['food_catalog', 'food_calendar', 'food_calendar_items', 'food_orders', 'food_order_logs'];
    $placeholders = implode(',', array_fill(0, count($tables), '?'));
    try {
        $stmt = db()->prepare("SELECT TABLE_NAME FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})");
        $stmt->execute($tables);
        $present = array_fill_keys(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    } catch (Throwable $e) {
        error_log('[food-order] schema check failed: ' . $e->getMessage());
        throw new RuntimeException('بررسی جدول‌های برنامه غذایی انجام نشد؛ وضعیت اتصال و دسترسی MySQL را بررسی کنید.', 0, $e);
    }
    $missing = array_values(array_filter($tables, static fn (string $table): bool => !isset($present[$table])));
    if ($missing !== []) {
        throw new RuntimeException('جدول‌های برنامه غذایی ایجاد نشده‌اند (' . implode(', ', $missing) . ')؛ ابتدا upgrade-1.38-food-orders.sql را اجرا کنید.');
    }
    $ready = true;
    return true;
}

function food_order_normalize_digits(mixed $value): string
{
    return strtr((string) ($value ?? ''), [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
}

function food_order_digits(mixed $value): string
{
    return preg_replace('/\D+/', '', food_order_normalize_digits($value)) ?? '';
}

function food_order_iso_date(mixed $value): ?string
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d');
    }
    $raw = trim(food_order_normalize_digits($value));
    if ($raw === '') {
        return null;
    }
    if (function_exists('food_ticket_iso_date_input')) {
        $parsed = food_ticket_iso_date_input($raw);
        if (is_string($parsed) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $parsed) && checkdate((int) substr($parsed, 5, 2), (int) substr($parsed, 8, 2), (int) substr($parsed, 0, 4))) {
            return $parsed;
        }
    }
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $raw, $m)) {
        $y = (int) $m[1];
        $mo = (int) $m[2];
        $d = (int) $m[3];
        if ($y >= 1700 && $y <= 2100 && checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
        if ($y >= 1200 && $y <= 1600 && $mo >= 1 && $mo <= 12 && $d >= 1 && $d <= 31 && function_exists('jalali_to_gregorian')) {
            [$gy, $gm, $gd] = jalali_to_gregorian($y, $mo, $d);
            if (checkdate($gm, $gd, $gy)) {
                return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
            }
        }
    }
    if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $raw, $m)) {
        return food_order_iso_date($m[1] . '-' . $m[2] . '-' . $m[3]);
    }
    if (preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $raw, $m)) {
        return food_order_iso_date($m[1] . '-' . $m[2] . '-' . $m[3]);
    }
    return null;
}

function food_order_today(): string
{
    $timezone = (string) (function_exists('cfg') ? cfg('app.timezone', 'Asia/Tehran') : 'Asia/Tehran');
    try {
        $zone = new DateTimeZone($timezone !== '' ? $timezone : 'Asia/Tehran');
    } catch (Throwable) {
        $zone = new DateTimeZone('Asia/Tehran');
    }
    return (new DateTimeImmutable('now', $zone))->format('Y-m-d');
}

function food_order_jalali_label(string $isoDate): string
{
    $date = food_order_iso_date($isoDate);
    if ($date === null || !function_exists('gregorian_to_jalali')) {
        return $date ?? '';
    }
    [$jy, $jm, $jd] = gregorian_to_jalali((int) substr($date, 0, 4), (int) substr($date, 5, 2), (int) substr($date, 8, 2));
    return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
}

/** @return array{year:int,month:int,from:string,to:string,days:int} */
function food_order_jalali_month_range(string $month): array
{
    $month = trim(food_order_normalize_digits($month));
    if (!preg_match('/^(\d{4})[-\/](\d{1,2})$/', $month, $m)) {
        throw new RuntimeException('ماه شمسی معتبر نیست.');
    }
    $year = (int) $m[1];
    $monthNumber = (int) $m[2];
    if ($year < 1200 || $year > 1600 || $monthNumber < 1 || $monthNumber > 12 || !function_exists('jalali_to_gregorian')) {
        throw new RuntimeException('ماه شمسی خارج از بازهٔ مجاز است.');
    }
    [$gy, $gm, $gd] = jalali_to_gregorian($year, $monthNumber, 1);
    if (!checkdate($gm, $gd, $gy)) {
        throw new RuntimeException('تبدیل ماه شمسی معتبر نیست.');
    }
    if ($monthNumber === 12) {
        [$ny, $nm, $nd] = jalali_to_gregorian($year + 1, 1, 1);
    } else {
        [$ny, $nm, $nd] = jalali_to_gregorian($year, $monthNumber + 1, 1);
    }
    if (!checkdate($nm, $nd, $ny)) {
        throw new RuntimeException('مرز ماه شمسی معتبر نیست.');
    }
    $from = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $gy, $gm, $gd), new DateTimeZone('UTC'));
    $next = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $ny, $nm, $nd), new DateTimeZone('UTC'));
    $days = (int) $from->diff($next)->days;
    $to = $next->modify('-1 day');
    return ['year' => $year, 'month' => $monthNumber, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'days' => $days];
}

function food_order_is_primary_admin(array $user): bool
{
    return function_exists('user_is_primary_admin')
        ? user_is_primary_admin($user)
        : (((string) ($user['role'] ?? '') === 'primary_admin') || ((string) ($user['role'] ?? '') === 'admin' && (int) ($user['is_primary_admin'] ?? 0) === 1));
}

function food_order_user_can(array $user, string $permission): bool
{
    return function_exists('user_can') ? user_can($user, $permission) : food_order_is_primary_admin($user);
}

function food_order_require_permission(array $user, string $permission): void
{
    if (!$user || !food_order_user_can($user, $permission)) {
        throw new RuntimeException('دسترسی شما به این عملیات مجاز نیست.');
    }
}

function food_order_log(?int $orderId, ?int $calendarId, string $action, ?int $userId, mixed $oldValue = null, mixed $newValue = null, ?string $reason = null): void
{
    $encode = static function (mixed $value): ?string {
        if ($value === null) {
            return null;
        }
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return is_string($json) ? $json : (string) $value;
    };
    db()->prepare('INSERT INTO food_order_logs (order_id, calendar_id, action, user_id, old_value, new_value, reason) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$orderId, $calendarId, $action, $userId, $encode($oldValue), $encode($newValue), $reason !== null ? mb_substr($reason, 0, 500, 'UTF-8') : null]);
}

function food_order_audit(string $action, array $user, string $targetType, int $targetId, mixed $oldValue = null, mixed $newValue = null, ?string $reason = null): void
{
    if (!function_exists('food_ticket_audit')) {
        return;
    }
    food_ticket_audit($action, [
        'user_id' => (int) ($user['id'] ?? 0),
        'target_type' => $targetType,
        'target_id' => $targetId,
        'target_label' => $targetType . ':' . $targetId,
        'old' => $oldValue,
        'new' => $newValue,
        'reason' => $reason,
        'meta' => ['module' => 'food-order'],
    ]);
}

function food_order_raw_mode(): string
{
    $query = db()->prepare('SELECT value FROM settings WHERE `key` = ? LIMIT 1');
    $query->execute(['food_order_mode']);
    $value = $query->fetchColumn();
    return $value === false ? '' : strtoupper(trim((string) $value));
}

/**
 * Worker-safe source mode: direct SQL, 5-second cache (never setting()).
 * This build is internal-only for food orders. Legacy ACCESS/TEST values are treated as
 * INTERNAL-DB; they can never cause an ODBC order connection to be opened.
 */
function food_order_mode(bool $reload = false): string
{
    $cache = $GLOBALS['__food_order_mode_cache'] ?? null;
    $now = microtime(true);
    if (!$reload && is_array($cache) && isset($cache['at'], $cache['value']) && ($now - (float) $cache['at']) < 5.0) {
        return (string) $cache['value'];
    }
    // A MySQL/settings error intentionally propagates. It must not become an Access fallback or an empty map.
    $raw = food_order_raw_mode();
    if ($raw !== '' && !in_array($raw, ['INTERNAL-DB', 'INTERNAL_DB', 'INTERNAL', 'PRODUCTION', 'ACCESS', 'TEST'], true)) {
        throw new RuntimeException('وضعیت سفارش معتبر نیست.');
    }
    $GLOBALS['__food_order_mode_cache'] = ['at' => $now, 'value' => 'INTERNAL-DB'];
    return 'INTERNAL-DB';
}

function food_order_user_row(int $id): ?array
{
    if ($id < 1) {
        return null;
    }
    $stmt = db()->prepare('SELECT id, username, full_name, first_name, last_name, employee_number, national_code, is_active FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function food_order_full_name(array $row): string
{
    $first = trim((string) ($row['first_name'] ?? ''));
    $last = trim((string) ($row['last_name'] ?? ''));
    $name = trim($first . ' ' . $last);
    return $name !== '' ? $name : trim((string) ($row['full_name'] ?? $row['username'] ?? ''));
}

function food_order_employee_search(string $query): array
{
    $query = trim(preg_replace('/[%_]+/u', '', $query) ?? $query);
    $where = "WHERE is_active = 1 AND employee_number IS NOT NULL AND TRIM(employee_number) <> ''";
    $params = [];
    if ($query !== '') {
        if (mb_strlen($query, 'UTF-8') < 2) {
            return [];
        }
        $like = '%' . mb_substr($query, 0, 60, 'UTF-8') . '%';
        $where .= ' AND (full_name LIKE ? OR first_name LIKE ? OR last_name LIKE ?)';
        $params = [$like, $like, $like];
    }
    // Empty query populates the authorized user's searchable dropdown with names only.
    $stmt = db()->prepare("SELECT id, full_name, first_name, last_name FROM users {$where} ORDER BY full_name, id");
    $stmt->execute($params);
    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[] = ['id' => (int) $row['id'], 'name' => food_order_full_name($row)];
    }
    return $items;
}

function food_order_catalog(bool $includeInactive = true): array
{
    food_order_schema_ensure();
    $sql = 'SELECT id, food_name, active FROM food_catalog';
    if (!$includeInactive) {
        $sql .= ' WHERE active = 1';
    }
    $sql .= ' ORDER BY active DESC, food_name, id';
    $rows = db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    return array_map(static fn (array $r): array => [
        'id' => (int) $r['id'],
        'food_name' => (string) $r['food_name'],
        'active' => (bool) $r['active'],
    ], $rows);
}

/**
 * ماه ورودی شمسی است. فقط سفارش‌های صاحب غذا در پاسخ کارمند قرار می‌گیرد؛ created_by هرگز فیلتر نمایش نیست.
 */
function food_order_month_status(string $month, array $user, bool $includeCounts = false, bool $includeInactiveFoods = false): array
{
    food_order_schema_ensure();
    $range = food_order_jalali_month_range($month);
    $employeeId = (int) ($user['id'] ?? 0);
    $countSelect = $includeCounts ? 'COUNT(o.id)' : '0';
    $foodJoin = $includeInactiveFoods ? 'f.id = i.food_id' : 'f.id = i.food_id AND f.active = 1';
    $countJoin = $includeCounts ? " LEFT JOIN food_orders o ON o.calendar_item_id = i.id AND o.food_date = c.food_date AND o.status = 'active'" : '';
    $groupBy = $includeCounts ? ' GROUP BY c.id, c.food_date, c.order_status, c.note, i.id, i.food_id, i.sort_order, f.food_name, f.active' : '';
    $stmt = db()->prepare("SELECT c.id AS calendar_id, c.food_date, c.order_status, c.note,
            i.id AS item_id, i.food_id, i.sort_order, f.food_name, f.active AS food_active, {$countSelect} AS order_count
        FROM food_calendar c
        LEFT JOIN food_calendar_items i ON i.calendar_id = c.id AND i.active = 1
        LEFT JOIN food_catalog f ON {$foodJoin}
        {$countJoin}
        WHERE c.food_date BETWEEN ? AND ?{$groupBy}
        ORDER BY c.food_date, i.sort_order, i.id");
    $stmt->execute([$range['from'], $range['to']]);
    $days = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $date = (string) $row['food_date'];
        if (!isset($days[$date])) {
            $days[$date] = [
                'date' => $date,
                'calendar_id' => (int) $row['calendar_id'],
                'order_status' => (string) $row['order_status'],
                'note' => (string) ($row['note'] ?? ''),
                'items' => [],
                'my_order' => null,
                'holiday_title' => '',
            ];
        }
        if (!empty($row['item_id']) && $row['food_name'] !== null) {
            $item = [
                'item_id' => (int) $row['item_id'],
                'food_id' => (int) $row['food_id'],
                'food_name' => (string) $row['food_name'],
                'food_active' => (bool) ($row['food_active'] ?? false),
                'sort_order' => (int) $row['sort_order'],
            ];
            if ($includeCounts) {
                $item['order_count'] = (int) $row['order_count'];
            }
            $days[$date]['items'][] = $item;
        }
    }

    $holidayStmt = db()->prepare('SELECT holiday_date, title FROM holidays WHERE is_active = 1 AND holiday_date BETWEEN ? AND ?');
    $holidayStmt->execute([$range['from'], $range['to']]);
    foreach ($holidayStmt->fetchAll(PDO::FETCH_ASSOC) as $holiday) {
        $date = (string) $holiday['holiday_date'];
        if (!isset($days[$date])) {
            $days[$date] = [
                'date' => $date,
                'calendar_id' => 0,
                'order_status' => '',
                'note' => '',
                'items' => [],
                'my_order' => null,
                'holiday_title' => '',
            ];
        }
        $days[$date]['holiday_title'] = (string) $holiday['title'];
    }

    if ($employeeId > 0) {
        $own = db()->prepare("SELECT o.id, o.food_date, o.status, o.calendar_item_id, o.created_at, i.food_id, c.food_name
            FROM food_orders o
            JOIN food_calendar_items i ON i.id = o.calendar_item_id
            JOIN food_catalog c ON c.id = i.food_id
            WHERE o.employee_id = ? AND o.food_date BETWEEN ? AND ? AND o.status = 'active'");
        $own->execute([$employeeId, $range['from'], $range['to']]);
        foreach ($own->fetchAll(PDO::FETCH_ASSOC) as $order) {
            $date = (string) $order['food_date'];
            if (!isset($days[$date])) {
                $days[$date] = [
                    'date' => $date,
                    'calendar_id' => 0,
                    'order_status' => '',
                    'note' => '',
                    'items' => [],
                    'my_order' => null,
                    'holiday_title' => '',
                ];
            }
            $days[$date]['my_order'] = [
                'id' => (int) $order['id'],
                'food_id' => (int) $order['food_id'],
                'item_id' => (int) $order['calendar_item_id'],
                'food_name' => (string) $order['food_name'],
                'reserve_date' => substr((string) $order['created_at'], 0, 10),
                'reserve_time' => substr((string) $order['created_at'], 11, 8),
            ];
        }
    }
    ksort($days, SORT_STRING);
    return [
        'month' => sprintf('%04d-%02d', $range['year'], $range['month']),
        'month_start' => $range['from'],
        'month_end' => $range['to'],
        'month_days' => $range['days'],
        'today' => food_order_today(),
        'days' => $days,
    ];
}

/**
 * تبدیل ورودی تاریخ بازهٔ لیست به Y-m-d میلادی.
 * پذیرش: شمسی (۱۴۰۵/۰۷/۰۱ یا ۱۴۰۵-۰۷-۰۱، با ارقام فارسی) یا میلادی (۲۰۲۶-۱۰-۰۱).
 * خالی ⇒ null.
 */
function food_order_list_date(string $raw): ?string
{
    $s = trim(food_order_normalize_digits($raw));
    if ($s === '') {
        return null;
    }
    if (!preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/', $s, $m)) {
        throw new RuntimeException('تاریخ «' . $raw . '» معتبر نیست؛ مثال: ۱۴۰۵/۰۷/۰۱');
    }
    $y = (int) $m[1];
    $mo = (int) $m[2];
    $d = (int) $m[3];
    if ($y >= 1200 && $y <= 1600) {
        if (!function_exists('jalali_to_gregorian') || $mo < 1 || $mo > 12 || $d < 1 || $d > 31) {
            throw new RuntimeException('تاریخ شمسی «' . $raw . '» معتبر نیست.');
        }
        [$gy, $gm, $gd] = jalali_to_gregorian($y, $mo, $d);
        // بررسی برگشتی: روز ۳۱ مهر یا ۳۰ بهمن (طول ماه شمسی) را رد می‌کند؛ بدون آن به روز بعد می‌رفت.
        if (!checkdate($gm, $gd, $gy) || gregorian_to_jalali($gy, $gm, $gd) !== [$y, $mo, $d]) {
            throw new RuntimeException('تاریخ شمسی «' . $raw . '» معتبر نیست.');
        }
    } else {
        [$gy, $gm, $gd] = [$y, $mo, $d];
    }
    if (!checkdate($gm, $gd, $gy)) {
        throw new RuntimeException('تاریخ «' . $raw . '» معتبر نیست.');
    }
    return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
}

/**
 * بازهٔ نمایش سفارش‌ها.
 * پیش‌فرض: از امروز تا پایان ماه شمسی جاری. بنابراین غذای روزهای گذشته خودکار از لیست خارج می‌شود.
 * کاربر می‌تواند هر بازه‌ای (حداکثر ۴۰۰ روز) را انتخاب کند.
 * @return array{from:string,to:string}
 */
function food_order_list_range(string $fromRaw, string $toRaw): array
{
    $today = food_order_today();
    [$jy, $jm] = function_exists('gregorian_to_jalali')
        ? gregorian_to_jalali((int) substr($today, 0, 4), (int) substr($today, 5, 2), (int) substr($today, 8, 2))
        : [0, 0];
    $monthEnd = $jy > 0 ? food_order_jalali_month_range(sprintf('%04d/%02d', $jy, $jm))['to'] : $today;

    $from = food_order_list_date($fromRaw);
    $to = food_order_list_date($toRaw);
    if ($from === null && $to === null) {
        $from = $today;
        $to = $monthEnd;
    } elseif ($from === null) {
        $from = min($today, (string) $to);
    } elseif ($to === null) {
        $to = $monthEnd;
    }
    if ($from > $to) {
        throw new RuntimeException('تاریخ شروع بازه نباید بعد از تاریخ پایان باشد.');
    }
    $days = (int) (new DateTimeImmutable($from, new DateTimeZone('UTC')))->diff(new DateTimeImmutable($to, new DateTimeZone('UTC')))->days;
    if ($days > 400) {
        throw new RuntimeException('بازهٔ انتخاب‌شده بیش از ۴۰۰ روز است؛ بازه را کوتاه‌تر کنید.');
    }
    return ['from' => $from, 'to' => $to];
}

/**
 * سفارش‌های یک کارمند در بازه. اگر $from/$to داده نشود، همهٔ سفارش‌ها (تا $limit) برگردانده می‌شود.
 */
function food_order_my_orders(int $employeeId, int $limit = 120, ?string $from = null, ?string $to = null): array
{
    food_order_schema_ensure();
    $limit = max(1, min(400, $limit));
    $range = ($from !== null && $to !== null) ? ' AND o.food_date BETWEEN ? AND ?' : '';
    $params = [$employeeId];
    if ($range !== '') {
        array_push($params, $from, $to);
    }
    $stmt = db()->prepare("SELECT o.id, o.employee_id, o.calendar_item_id, o.food_date, o.status, o.created_at,
            u.full_name, u.first_name, u.last_name, u.national_code, c.food_name, i.food_id
        FROM food_orders o
        JOIN users u ON u.id = o.employee_id
        JOIN food_calendar_items i ON i.id = o.calendar_item_id
        JOIN food_catalog c ON c.id = i.food_id
        WHERE o.employee_id = ?{$range}
        ORDER BY o.food_date DESC, o.id DESC LIMIT {$limit}");
    $stmt->execute($params);
    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $created = (string) $row['created_at'];
        $items[] = [
            'id' => (int) $row['id'],
            'employee_id' => (int) $row['employee_id'],
            'national_code' => food_order_digits($row['national_code'] ?? ''),
            'first_name' => trim((string) ($row['first_name'] ?? '')),
            'last_name' => trim((string) ($row['last_name'] ?? '')),
            'full_name' => food_order_full_name($row),
            'food_name' => (string) $row['food_name'],
            'food_id' => (int) $row['food_id'],
            'food_date' => (string) $row['food_date'],
            'reserve_date' => substr($created, 0, 10),
            'reserve_time' => substr($created, 11, 8),
            'status' => (string) $row['status'],
        ];
    }
    return $items;
}

function food_order_manager_orders(string $date): array
{
    food_order_schema_ensure();
    $iso = food_order_iso_date($date);
    if ($iso === null) {
        throw new RuntimeException('تاریخ روز سفارش معتبر نیست.');
    }
    $stmt = db()->prepare("SELECT o.id, o.employee_id, o.calendar_item_id, o.food_date, o.status, o.created_at, o.created_by,
            u.full_name, u.first_name, u.last_name, u.employee_number, u.national_code, c.food_name, i.food_id
        FROM food_orders o
        JOIN users u ON u.id = o.employee_id
        JOIN food_calendar_items i ON i.id = o.calendar_item_id
        JOIN food_catalog c ON c.id = i.food_id
        WHERE o.food_date = ?
        ORDER BY CASE WHEN o.status = 'active' THEN 0 ELSE 1 END, u.full_name, o.id");
    $stmt->execute([$iso]);
    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $created = (string) $row['created_at'];
        $employeeId = (int) $row['employee_id'];
        $items[] = [
            'id' => (int) $row['id'],
            'employee_id' => $employeeId,
            'calendar_item_id' => (int) $row['calendar_item_id'],
            'food_id' => (int) $row['food_id'],
            'national_code' => food_order_digits($row['national_code'] ?? ''),
            'employee_number' => (string) ($row['employee_number'] ?? ''),
            'first_name' => trim((string) ($row['first_name'] ?? '')),
            'last_name' => trim((string) ($row['last_name'] ?? '')),
            'full_name' => food_order_full_name($row),
            'food_name' => (string) $row['food_name'],
            'food_date' => (string) $row['food_date'],
            'reserve_date' => substr($created, 0, 10),
            'reserve_time' => substr($created, 11, 8),
            'status' => (string) $row['status'],
            'is_proxy' => (int) ($row['created_by'] ?? 0) !== $employeeId,
        ];
    }
    return $items;
}

function food_order_statistics_range(string $fromDate, string $toDate, string $mode = 'food'): array
{
    food_order_schema_ensure();
    $mode = strtolower(trim($mode));
    if (!in_array($mode, ['food', 'employee'], true)) {
        throw new RuntimeException('نوع آمار سفارش معتبر نیست.');
    }
    $from = food_order_iso_date($fromDate);
    $to = food_order_iso_date($toDate);
    if ($from === null || $to === null || $from > $to) {
        throw new RuntimeException('بازهٔ تاریخ آمار معتبر نیست.');
    }
    $span = (new DateTimeImmutable($from, new DateTimeZone('UTC')))->diff(new DateTimeImmutable($to, new DateTimeZone('UTC')))->days;
    if ($span > 366) {
        throw new RuntimeException('بازهٔ آمار حداکثر یک سال است.');
    }
    if ($mode === 'employee') {
        $stmt = db()->prepare("SELECT u.id AS employee_id, u.full_name, u.first_name, u.last_name, COUNT(o.id) AS order_count
            FROM food_orders o
            JOIN users u ON u.id = o.employee_id
            WHERE o.food_date BETWEEN ? AND ? AND o.status = 'active'
            GROUP BY u.id, u.full_name, u.first_name, u.last_name
            ORDER BY order_count DESC, u.last_name, u.first_name, u.id");
    } else {
        $stmt = db()->prepare("SELECT f.id AS food_id, f.food_name, COUNT(o.id) AS order_count
            FROM food_orders o
            JOIN food_calendar_items i ON i.id = o.calendar_item_id
            JOIN food_catalog f ON f.id = i.food_id
            WHERE o.food_date BETWEEN ? AND ? AND o.status = 'active'
            GROUP BY f.id, f.food_name
            ORDER BY order_count DESC, f.food_name, f.id");
    }
    $stmt->execute([$from, $to]);
    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($mode === 'employee') {
            $items[] = [
                'full_name' => food_order_full_name($row),
                'count' => (int) $row['order_count'],
            ];
        } else {
            $items[] = [
                'food_id' => (int) $row['food_id'],
                'food_name' => (string) $row['food_name'],
                'count' => (int) $row['order_count'],
            ];
        }
    }
    return $items;
}

function food_order_ensure_orderable_day(PDO $pdo, string $isoDate): array
{
    $stmt = $pdo->prepare('SELECT id, order_status FROM food_calendar WHERE food_date = ? FOR UPDATE');
    $stmt->execute([$isoDate]);
    $calendar = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$calendar) {
        throw new RuntimeException('برای این تاریخ هنوز برنامهٔ غذایی ثبت نشده است.');
    }
    $today = food_order_today();
    $dateObj = new DateTimeImmutable($isoDate, new DateTimeZone('UTC'));
    $todayObj = new DateTimeImmutable($today, new DateTimeZone('UTC'));
    $isPast = $dateObj < $todayObj;
    $isClosed = (string) $calendar['order_status'] === 'closed';
    if ($isPast || $isClosed) {
        throw new RuntimeException($isPast ? 'تاریخ گذشته و فقط‌خواندنی است.' : 'سفارش این روز بسته شده است.');
    }
    return ['calendar_id' => (int) $calendar['id'], 'order_status' => (string) $calendar['order_status']];
}

/**
 * بستن سفارش‌گیری جلوی ثبت/تغییر سفارش را می‌گیرد؛ لغو سفارش آینده را مسدود نمی‌کند.
 * همچنان ردیف تقویم را قفل می‌کنیم تا با تغییر هم‌زمان برنامه وضعیت سفارش دقیق بماند.
 */
function food_order_lock_cancellable_day(PDO $pdo, string $isoDate): array
{
    if (food_order_iso_date($isoDate) === null) {
        throw new RuntimeException('تاریخ سفارش معتبر نیست.');
    }
    $stmt = $pdo->prepare('SELECT id, order_status FROM food_calendar WHERE food_date = ? FOR UPDATE');
    $stmt->execute([$isoDate]);
    $calendar = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$calendar) {
        throw new RuntimeException('برای این تاریخ برنامهٔ غذایی پیدا نشد.');
    }
    $dateObj = new DateTimeImmutable($isoDate, new DateTimeZone('UTC'));
    $todayObj = new DateTimeImmutable(food_order_today(), new DateTimeZone('UTC'));
    if ($dateObj < $todayObj) {
        throw new RuntimeException('تاریخ گذشته و فقط‌خواندنی است.');
    }
    return ['calendar_id' => (int) $calendar['id'], 'order_status' => (string) $calendar['order_status']];
}

function food_order_verify_employee_national_code(int $employeeId, string $provided, bool $requireEmployeeNumber = true, ?PDO $pdo = null): array
{
    $pdo ??= db();
    $stmt = $pdo->prepare('SELECT id, full_name, first_name, last_name, employee_number, national_code, is_active FROM users WHERE id = ? LIMIT 1' . ($pdo->inTransaction() ? ' FOR UPDATE' : ''));
    $stmt->execute([$employeeId]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$target || (int) ($target['is_active'] ?? 0) !== 1 || ($requireEmployeeNumber && trim((string) ($target['employee_number'] ?? '')) === '')) {
        throw new RuntimeException('کد ملی واردشده با اطلاعات فرد انتخاب‌شده مطابقت ندارد.');
    }
    $expected = food_order_digits($target['national_code'] ?? '');
    $actual = food_order_digits($provided);
    if ($expected === '' || strlen($actual) !== 10 || !hash_equals($expected, $actual)) {
        throw new RuntimeException('کد ملی واردشده با اطلاعات فرد انتخاب‌شده مطابقت ندارد.');
    }
    return $target;
}

function food_order_create_or_change(int $employeeId, int $actorId, string $dateInput, int $itemId, array $actor, ?string $proxyNationalCode = null): array
{
    food_order_schema_ensure();
    $iso = food_order_iso_date($dateInput);
    if ($iso === null || $itemId < 1 || $employeeId < 1 || $actorId < 1) {
        throw new RuntimeException('تاریخ یا انتخاب غذا معتبر نیست.');
    }
    $isProxy = $employeeId !== $actorId;
    if ($isProxy) {
        if ($proxyNationalCode === null) {
            throw new RuntimeException('کد ملی فرد انتخاب‌شده را وارد کنید.');
        }
        check_api_rate_limit('food_order_proxy_attempt_' . $actorId, 5, 60);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $day = food_order_ensure_orderable_day($pdo, $iso);
        if ($isProxy) {
            $target = food_order_verify_employee_national_code($employeeId, (string) $proxyNationalCode, true, $pdo);
        } else {
            $selfStmt = $pdo->prepare('SELECT id, full_name, first_name, last_name, employee_number, national_code, is_active FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
            $selfStmt->execute([$employeeId]);
            $target = $selfStmt->fetch(PDO::FETCH_ASSOC);
            if (!$target || (int) ($target['is_active'] ?? 0) !== 1) {
                throw new RuntimeException('حساب کاربری فعال پیدا نشد.');
            }
            $selfNational = food_order_digits($target['national_code'] ?? '');
            if ($selfNational === '') {
                throw new RuntimeException('کد ملی شما در سامانه ثبت نشده است؛ برای ثبت سفارش با منابع انسانی هماهنگ کنید.');
            }
            if (strlen($selfNational) !== 10) {
                throw new RuntimeException('کد ملی ثبت‌شدهٔ شما معتبر نیست؛ برای اصلاح اطلاعات با منابع انسانی هماهنگ کنید.');
            }
        }

        $item = $pdo->prepare("SELECT i.id, i.food_id, f.food_name FROM food_calendar_items i
            JOIN food_catalog f ON f.id = i.food_id
            WHERE i.id = ? AND i.calendar_id = ? AND i.active = 1 AND f.active = 1 FOR UPDATE");
        $item->execute([$itemId, $day['calendar_id']]);
        $food = $item->fetch(PDO::FETCH_ASSOC);
        if (!$food) {
            throw new RuntimeException('این غذا دیگر برای تاریخ انتخاب‌شده قابل سفارش نیست.');
        }

        $existingStmt = $pdo->prepare('SELECT id, calendar_item_id, status FROM food_orders WHERE employee_id = ? AND food_date = ? FOR UPDATE');
        $existingStmt->execute([$employeeId, $iso]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
        $old = $existing ? ['calendar_item_id' => (int) $existing['calendar_item_id'], 'status' => (string) $existing['status']] : null;
        $action = $isProxy ? 'order_proxy_create' : 'order_create';
        if ($existing) {
            if ((string) $existing['status'] === 'active' && (int) $existing['calendar_item_id'] === $itemId) {
                $pdo->commit();
                return ['ok' => true, 'order_id' => (int) $existing['id'], 'unchanged' => true, 'message' => 'همین غذا قبلاً برای این روز ثبت شده است.'];
            }
            $action = (string) $existing['status'] === 'cancelled' ? ($isProxy ? 'order_proxy_create' : 'order_create') : 'order_change';
            $pdo->prepare("UPDATE food_orders SET calendar_item_id = ?, created_by = ?, status = 'active', updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([$itemId, $actorId, (int) $existing['id']]);
            $orderId = (int) $existing['id'];
        } else {
            $pdo->prepare("INSERT INTO food_orders (employee_id, calendar_item_id, food_date, created_by, status) VALUES (?, ?, ?, ?, 'active')")
                ->execute([$employeeId, $itemId, $iso, $actorId]);
            $orderId = (int) $pdo->lastInsertId();
        }
        food_order_log($orderId, $day['calendar_id'], $action, $actorId, $old, ['calendar_item_id' => $itemId, 'food_name' => (string) $food['food_name'], 'employee_id' => $employeeId], null);
        $pdo->commit();
        food_order_audit($action, $actor, 'food_order', $orderId, $old, ['calendar_item_id' => $itemId, 'food_name' => (string) $food['food_name'], 'employee_id' => $employeeId]);
        return ['ok' => true, 'order_id' => $orderId, 'food_name' => (string) $food['food_name'], 'message' => $isProxy ? 'سفارش برای فرد انتخاب‌شده ثبت شد.' : 'سفارش شما ثبت شد.'];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ((string) $e->getCode() === '23000') {
            throw new RuntimeException('برای این نفر و این روز قبلاً سفارشی ثبت شده است؛ صفحه را تازه‌سازی کنید.');
        }
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function food_order_cancel(int $orderId, array $user): array
{
    food_order_schema_ensure();
    if ($orderId < 1) {
        throw new RuntimeException('سفارش پیدا نشد.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Read date first without locking; then lock day → order, matching the
        // day-save/create lock order to reduce deadlocks under concurrent use.
        $lookup = $pdo->prepare('SELECT id, employee_id, calendar_item_id, food_date, status FROM food_orders WHERE id = ? LIMIT 1');
        $lookup->execute([$orderId]);
        $initial = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!$initial || (int) $initial['employee_id'] !== (int) $user['id']) {
            throw new RuntimeException('سفارش قابل لغو نیست.');
        }
        $day = food_order_lock_cancellable_day($pdo, (string) $initial['food_date']);
        $stmt = $pdo->prepare('SELECT id, employee_id, calendar_item_id, food_date, status FROM food_orders WHERE id = ? FOR UPDATE');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order || (int) $order['employee_id'] !== (int) $user['id'] || (string) $order['food_date'] !== (string) $initial['food_date']) {
            throw new RuntimeException('سفارش قابل لغو نیست.');
        }
        if ((string) $order['status'] !== 'active') {
            $pdo->commit();
            return ['ok' => true, 'message' => 'این سفارش قبلاً لغو شده است.'];
        }
        $pdo->prepare("UPDATE food_orders SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$orderId]);
        $old = ['calendar_item_id' => (int) $order['calendar_item_id'], 'status' => 'active'];
        $new = ['status' => 'cancelled'];
        food_order_log($orderId, $day['calendar_id'], 'order_cancel', (int) $user['id'], $old, $new);
        $pdo->commit();
        food_order_audit('order_cancel', $user, 'food_order', $orderId, $old, $new);
        return ['ok' => true, 'message' => 'سفارش شما لغو شد.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function food_order_cancel_proxy(int $orderId, int $employeeId, string $nationalCode, array $actor): array
{
    food_order_schema_ensure();
    if ($orderId < 1 || $employeeId < 1) {
        throw new RuntimeException('سفارش قابل لغو نیست.');
    }
    check_api_rate_limit('food_order_proxy_cancel_' . (int) ($actor['id'] ?? 0), 5, 60);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Match the create lock order: order date → verified recipient → order row.
        $lookup = $pdo->prepare('SELECT id, employee_id, calendar_item_id, food_date, status FROM food_orders WHERE id = ? LIMIT 1');
        $lookup->execute([$orderId]);
        $initial = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!$initial || (int) $initial['employee_id'] !== $employeeId) {
            throw new RuntimeException('سفارش برای فرد تأییدشده پیدا نشد یا قابل لغو نیست.');
        }
        $day = food_order_lock_cancellable_day($pdo, (string) $initial['food_date']);
        $target = food_order_verify_employee_national_code($employeeId, $nationalCode, true, $pdo);
        $stmt = $pdo->prepare('SELECT id, employee_id, calendar_item_id, food_date, status FROM food_orders WHERE id = ? AND employee_id = ? FOR UPDATE');
        $stmt->execute([$orderId, $employeeId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order || (int) $order['employee_id'] !== (int) $target['id'] || (string) $order['food_date'] !== (string) $initial['food_date']) {
            throw new RuntimeException('سفارش برای فرد تأییدشده پیدا نشد یا قابل لغو نیست.');
        }
        if ((string) $order['status'] !== 'active') {
            $pdo->commit();
            return ['ok' => true, 'message' => 'این سفارش قبلاً لغو شده است.'];
        }
        $update = $pdo->prepare("UPDATE food_orders SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP WHERE id = ? AND employee_id = ? AND status = 'active'");
        $update->execute([$orderId, $employeeId]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('وضعیت سفارش هم‌زمان تغییر کرد؛ فهرست را تازه کنید و دوباره تلاش کنید.');
        }
        $old = ['calendar_item_id' => (int) $order['calendar_item_id'], 'status' => 'active', 'employee_id' => $employeeId];
        $new = ['status' => 'cancelled', 'employee_id' => $employeeId];
        food_order_log($orderId, $day['calendar_id'], 'order_proxy_cancel', (int) $actor['id'], $old, $new);
        $pdo->commit();
        food_order_audit('order_proxy_cancel', $actor, 'food_order', $orderId, $old, $new);
        return ['ok' => true, 'message' => 'سفارش فرد تأییدشده لغو شد.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function food_order_catalog_save(array $body, array $user): array
{
    food_order_schema_ensure();
    food_order_require_permission($user, 'food.menu_edit');
    $id = max(0, (int) ($body['id'] ?? 0));
    $name = trim((string) ($body['food_name'] ?? ''));
    $active = !empty($body['active']) ? 1 : 0;
    if ($name === '' || mb_strlen($name, 'UTF-8') > 190) {
        throw new RuntimeException('نام غذا الزامی و حداکثر ۱۹۰ نویسه است.');
    }
    if (preg_match('/[<>\x00-\x1F]/u', $name)) {
        throw new RuntimeException('نام غذا نویسهٔ نامعتبر دارد.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            $find = $pdo->prepare('SELECT id, food_name, active FROM food_catalog WHERE id = ? LIMIT 1 FOR UPDATE');
            $find->execute([$id]);
            $old = $find->fetch(PDO::FETCH_ASSOC);
            if (!$old) {
                throw new RuntimeException('غذا پیدا نشد.');
            }
            if ((string) $old['food_name'] !== $name) {
                $usage = $pdo->prepare('SELECT COUNT(*) FROM food_calendar_items WHERE food_id = ?');
                $usage->execute([$id]);
                if ((int) $usage->fetchColumn() > 0) {
                    throw new RuntimeException('نام غذایی که در برنامهٔ روزانه استفاده شده تغییر نمی‌کند؛ غذای تازه بسازید و مورد قبلی را غیرفعال کنید تا سوابق تاریخی حفظ شوند.');
                }
            }
            $pdo->prepare('UPDATE food_catalog SET food_name = ?, active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$name, $active, $id]);
            $action = $active ? 'food_catalog_update' : 'food_catalog_deactivate';
            food_order_log(null, null, $action, (int) $user['id'], $old, ['id' => $id, 'food_name' => $name, 'active' => (bool) $active]);
            $oldForAudit = $old;
        } else {
            $pdo->prepare('INSERT INTO food_catalog (food_name, active, created_by) VALUES (?, ?, ?)')->execute([$name, $active, (int) $user['id']]);
            $id = (int) $pdo->lastInsertId();
            $action = $active ? 'food_catalog_create' : 'food_catalog_deactivate';
            food_order_log(null, null, $action, (int) $user['id'], null, ['id' => $id, 'food_name' => $name, 'active' => (bool) $active]);
            $oldForAudit = null;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    food_order_audit($action, $user, 'food_catalog', $id, $oldForAudit, ['food_name' => $name, 'active' => (bool) $active]);
    return ['ok' => true, 'items' => food_order_catalog(true), 'message' => 'بانک غذا ذخیره شد.'];
}

function food_order_save_day(array $body, array $user): array
{
    food_order_schema_ensure();
    food_order_require_permission($user, 'food.menu_edit');
    $iso = food_order_iso_date($body['date'] ?? '');
    if ($iso === null) {
        throw new RuntimeException('تاریخ برنامه معتبر نیست.');
    }
    $foodIds = array_values(array_unique(array_filter(array_map(static fn ($x): int => max(0, (int) $x), (array) ($body['food_ids'] ?? []))))) ;
    if (count($foodIds) > 3) {
        throw new RuntimeException('برای هر روز حداکثر سه گزینهٔ غذا می‌توان ثبت کرد.');
    }
    $foodIds = array_values(array_filter($foodIds, static fn (int $id): bool => $id > 0));
    $note = trim((string) ($body['note'] ?? ''));
    if (mb_strlen($note, 'UTF-8') > 255) {
        throw new RuntimeException('یادداشت حداکثر ۲۵۵ نویسه باشد.');
    }
    $rawStatus = trim((string) ($body['order_status'] ?? ''));
    if ($rawStatus !== '' && !in_array($rawStatus, ['open', 'closed'], true)) {
        throw new RuntimeException('وضعیت سفارش روز معتبر نیست.');
    }
    $status = $rawStatus;
    if ($status === 'closed' || $status === 'open') {
        if (!food_order_user_can($user, 'food.order_close')) {
            throw new RuntimeException('بستن یا بازکردن روز به دسترسی food.order_close نیاز دارد.');
        }
    }
    $today = new DateTimeImmutable(food_order_today(), new DateTimeZone('UTC'));
    $dateObj = new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    if ($dateObj < $today && !food_order_is_primary_admin($user)) {
        throw new RuntimeException('ویرایش برنامهٔ روز گذشته فقط برای ادمین اصلی مجاز است.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $find = $pdo->prepare('SELECT id, order_status, note, closed_by, closed_at FROM food_calendar WHERE food_date = ? FOR UPDATE');
        $find->execute([$iso]);
        $oldDay = $find->fetch(PDO::FETCH_ASSOC);
        if (!$oldDay) {
            try {
                $pdo->prepare('INSERT INTO food_calendar (food_date, order_status, note, created_by) VALUES (?, ?, ?, ?)')
                    ->execute([$iso, $status === 'closed' ? 'closed' : 'open', $note !== '' ? $note : null, (int) $user['id']]);
            } catch (PDOException $e) {
                if ((string) $e->getCode() !== '23000') {
                    throw $e;
                }
            }
            $find->execute([$iso]);
            $oldDay = $find->fetch(PDO::FETCH_ASSOC);
        }
        if (!$oldDay) {
            throw new RuntimeException('ساخت ردیف تقویم انجام نشد.');
        }
        $calendarId = (int) $oldDay['id'];
        $wasClosed = (string) $oldDay['order_status'] === 'closed';
        if ($wasClosed && !food_order_user_can($user, 'food.order_close')) {
            throw new RuntimeException('ویرایش روز بسته به دسترسی food.order_close نیاز دارد.');
        }

        if ($foodIds !== []) {
            $placeholders = implode(',', array_fill(0, count($foodIds), '?'));
            $activeFoods = $pdo->prepare("SELECT id FROM food_catalog
                WHERE id IN ({$placeholders}) AND (active = 1 OR id IN (SELECT food_id FROM food_calendar_items WHERE calendar_id = ?)) FOR UPDATE");
            $activeFoods->execute(array_merge($foodIds, [$calendarId]));
            $valid = array_map('intval', $activeFoods->fetchAll(PDO::FETCH_COLUMN));
            sort($valid);
            $expected = $foodIds;
            sort($expected);
            if ($valid !== $expected) {
                throw new RuntimeException('یکی از غذاهای انتخاب‌شده غیرفعال یا نامعتبر است.');
            }
        }

        $existingStmt = $pdo->prepare('SELECT id, food_id, active FROM food_calendar_items WHERE calendar_id = ? FOR UPDATE');
        $existingStmt->execute([$calendarId]);
        $existingItems = $existingStmt->fetchAll(PDO::FETCH_ASSOC);
        $removed = [];
        foreach ($existingItems as $item) {
            if ((int) $item['active'] === 1 && !in_array((int) $item['food_id'], $foodIds, true)) {
                $removed[] = ['item_id' => (int) $item['id'], 'food_id' => (int) $item['food_id']];
            }
        }
        $affected = 0;
        foreach ($removed as $item) {
            $count = $pdo->prepare("SELECT COUNT(*) FROM food_orders WHERE calendar_item_id = ? AND food_date = ? AND status = 'active'");
            $count->execute([$item['item_id'], $iso]);
            $affected += (int) $count->fetchColumn();
        }
        $confirmedImpact = !empty($body['confirm_impact']);
        $expectedImpact = isset($body['expected_impact']) ? max(0, (int) $body['expected_impact']) : -1;
        if ($affected > 0 && (!$confirmedImpact || $expectedImpact !== $affected)) {
            $pdo->rollBack();
            return [
                'ok' => false,
                'confirmation_required' => true,
                'affected_orders' => $affected,
                'message' => "با حذف یا جایگزینی این غذا، {$affected} سفارش فعال لغو می‌شود. تعداد سفارش‌ها از زمان بررسی تغییر کرده یا باید دوباره تأیید شود.",
            ];
        }

        foreach ($removed as $item) {
            $ordersStmt = $pdo->prepare("SELECT id FROM food_orders WHERE calendar_item_id = ? AND food_date = ? AND status = 'active' FOR UPDATE");
            $ordersStmt->execute([$item['item_id'], $iso]);
            $orderIds = array_map('intval', $ordersStmt->fetchAll(PDO::FETCH_COLUMN));
            if ($orderIds !== []) {
                $pdo->prepare("UPDATE food_orders SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP WHERE calendar_item_id = ? AND food_date = ? AND status = 'active'")
                    ->execute([$item['item_id'], $iso]);
                foreach ($orderIds as $orderId) {
                    food_order_log($orderId, $calendarId, 'order_cancel', (int) $user['id'], ['status' => 'active', 'calendar_item_id' => $item['item_id']], ['status' => 'cancelled'], 'غذا از برنامهٔ روز حذف شد');
                }
            }
            $pdo->prepare('UPDATE food_calendar_items SET active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$item['item_id']]);
        }
        foreach ($foodIds as $index => $foodId) {
            $pdo->prepare('INSERT INTO food_calendar_items (calendar_id, food_id, sort_order, active) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order), active = 1, updated_at = CURRENT_TIMESTAMP')
                ->execute([$calendarId, $foodId, $index + 1]);
        }

        $newStatus = $status !== '' ? $status : (string) $oldDay['order_status'];
        $closedBy = $newStatus === 'closed' ? (int) $user['id'] : null;
        $closedAt = $newStatus === 'closed' ? date('Y-m-d H:i:s') : null;
        $pdo->prepare('UPDATE food_calendar SET order_status = ?, note = ?, closed_by = ?, closed_at = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$newStatus, $note !== '' ? $note : null, $closedBy, $closedAt, $calendarId]);

        $newDay = ['order_status' => $newStatus, 'note' => $note, 'food_ids' => $foodIds];
        food_order_log(null, $calendarId, 'day_save', (int) $user['id'], ['order_status' => (string) $oldDay['order_status'], 'note' => (string) ($oldDay['note'] ?? '')], $newDay);
        if ((string) $oldDay['order_status'] !== $newStatus) {
            food_order_log(null, $calendarId, $newStatus === 'closed' ? 'day_close' : 'day_open', (int) $user['id'], (string) $oldDay['order_status'], $newStatus);
        }
        $pdo->commit();
        food_order_audit('food_order_day_saved', $user, 'food_calendar', $calendarId, ['order_status' => (string) $oldDay['order_status'], 'note' => (string) ($oldDay['note'] ?? '')], $newDay);
        return ['ok' => true, 'calendar_id' => $calendarId, 'order_status' => $newStatus, 'message' => 'برنامهٔ روز ذخیره شد.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function food_order_status_summary(): array
{
    food_order_schema_ensure();
    $today = food_order_today();
    $stmt = db()->prepare("SELECT COUNT(*) FROM food_orders WHERE food_date = ? AND status = 'active'");
    $stmt->execute([$today]);
    return [
        'today' => $today,
        'active_orders_today' => (int) $stmt->fetchColumn(),
    ];
}

function food_order_api_body(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return is_array($_POST ?? null) ? $_POST : [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function food_order_send_json(array $payload, int $status = 200): never
{
    if (function_exists('food_ticket_api_json')) {
        food_ticket_api_json($payload, $status);
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function food_order_safe_error(Throwable $e): string
{
    if ($e instanceof PDOException) {
        error_log('[food-order] database API error: ' . $e->getMessage());
        return 'خطای پایگاه داده رخ داد؛ عملیات ثبت نشد. پس از بررسی وضعیت دیتابیس دوباره تلاش کنید.';
    }
    return $e->getMessage() !== '' ? $e->getMessage() : 'عملیات سفارش غذا انجام نشد.';
}

function food_order_export_safe_value(mixed $value): string
{
    $text = (string) $value;
    // Prevent formula execution when CSV/XLS is opened in spreadsheet software.
    if (preg_match('/^[\s\x{FEFF}]*[=+\-@]/u', $text)) {
        return "'" . $text;
    }
    return $text;
}

function food_order_export_manager_orders(string $date, array $user): never
{
    food_order_require_permission($user, 'food.order_close');
    $iso = food_order_iso_date($date);
    if ($iso === null) {
        food_order_send_json(['error' => 'تاریخ روز سفارش معتبر نیست.'], 400);
    }
    $items = food_order_manager_orders($iso);
    $dateLabel = food_order_jalali_label($iso);
    $headers = ['ردیف', 'کد ملی', 'کد پرسنلی', 'نام پرسنل', 'نوع غذا', 'تاریخ غذا', 'تاریخ رزرو', 'ساعت رزرو', 'وضعیت', 'نوع ثبت'];
    $data = [];
    foreach ($items as $index => $item) {
        $data[] = [
            $index + 1,
            $item['national_code'] ?? '',
            $item['employee_number'] ?? '',
            $item['full_name'] ?? '',
            $item['food_name'] ?? '',
            food_order_jalali_label((string) ($item['food_date'] ?? $iso)),
            food_order_jalali_label((string) ($item['reserve_date'] ?? '')),
            substr((string) ($item['reserve_time'] ?? ''), 0, 5),
            (string) ($item['status'] ?? '') === 'active' ? 'فعال' : 'لغوشده',
            !empty($item['is_proxy']) ? 'نیابتی' : 'شخصی',
        ];
    }
    foreach ($data as &$row) {
        foreach ($row as &$value) {
            $value = food_order_export_safe_value($value);
        }
        unset($value);
    }
    unset($row);
    food_order_audit('food_order_daily_orders_export', $user, 'food_orders', 0, null, [
        'food_date' => $iso,
        'row_count' => count($data),
    ]);
    $rangeToken = str_replace('-', '', $iso);
    $title = 'فهرست سفارش‌های روز ' . $dateLabel;
    if (function_exists('excel_download')) {
        excel_download('سفارش‌های-روز-' . $rangeToken . '.xls', $title, $headers, $data, ['date' => $dateLabel]);
    }
    $lines = [
        '"تاریخ شروع","' . str_replace('"', '""', $dateLabel) . '"',
        '"تاریخ پایان","' . str_replace('"', '""', $dateLabel) . '"',
        implode(',', array_map(static fn ($value): string => '"' . str_replace('"', '""', (string) $value) . '"', $headers)),
    ];
    foreach ($data as $row) {
        $lines[] = implode(',', array_map(static fn ($value): string => '"' . str_replace('"', '""', (string) $value) . '"', $row));
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="food-orders-' . $rangeToken . '.csv"');
    echo "\xEF\xBB\xBF" . implode("\r\n", $lines);
    exit;
}

function food_order_export_statistics_range(string $fromDate, string $toDate, array $user, string $mode = 'food'): never
{
    $mode = strtolower(trim($mode));
    if (!in_array($mode, ['food', 'employee'], true)) {
        food_order_send_json(['error' => 'نوع آمار سفارش معتبر نیست.'], 400);
    }
    $from = food_order_iso_date($fromDate);
    $to = food_order_iso_date($toDate);
    if ($from === null || $to === null || $from > $to) {
        food_order_send_json(['error' => 'بازهٔ تاریخ آمار معتبر نیست.'], 400);
    }
    $items = food_order_statistics_range($from, $to, $mode);
    $fromJalali = food_order_jalali_label($from);
    $toJalali = food_order_jalali_label($to);
    $rangeToken = str_replace('-', '', $from) . '-' . str_replace('-', '', $to);
    $rangeLabel = $from === $to ? $fromJalali : $fromJalali . ' تا ' . $toJalali;
    $title = $mode === 'employee' ? 'آمار سفارش نفرات' : 'آمار نوع غذا';
    $headers = $mode === 'employee'
        ? ['ردیف', 'نام پرسنل', 'تعداد سفارش فعال']
        : ['ردیف', 'نوع غذا', 'تعداد سفارش فعال'];
    $data = [];
    foreach ($items as $index => $item) {
        $label = $mode === 'employee' ? $item['full_name'] : $item['food_name'];
        $data[] = [$index + 1, $label, $item['count']];
    }
    foreach ($data as &$row) {
        foreach ($row as &$value) {
            $value = food_order_export_safe_value($value);
        }
        unset($value);
    }
    unset($row);
    food_order_audit('food_order_statistics_export', $user, 'food_orders', 0, null, [
        'mode' => $mode,
        'from_date' => $from,
        'to_date' => $to,
        'row_count' => count($data),
    ]);
    if (function_exists('excel_download')) {
        excel_download('آمار-سفارش-' . $mode . '-' . $rangeToken . '.xls', $title . ' ' . $rangeLabel, $headers, $data, ['from' => $from, 'to' => $to]);
    }
    $lines = [];
    $lines[] = implode(',', array_map(static fn ($v): string => '"' . str_replace('"', '""', (string) $v) . '"', $headers));
    foreach ($data as $row) {
        $lines[] = implode(',', array_map(static fn ($v): string => '"' . str_replace('"', '""', (string) $v) . '"', $row));
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="food-order-statistics-' . $mode . '-' . $rangeToken . '.csv"');
    echo "\xEF\xBB\xBF" . implode("\r\n", $lines);
    exit;
}

/** نقش‌های مدیر و بالاتر که می‌توانند برای مهمان برای روزهای آینده غذا سفارش دهند. */
function food_guest_request_roles(): array
{
    return ['primary_admin', 'supervisor', 'support_manager', 'manager', 'inspector'];
}

/** فقط کسانی که جایگاه مدیریتی در چارت دارند (مدیر واحدی که زیر یک معاونت است)؛ نقش سامانه ملاک نیست. */
function food_guest_request_allowed(array $user): bool
{
    return food_guest_user_deputy_id($user, true) > 0;
}

/** تعداد مهمان‌های درخواستی فعال برای یک روز (سقف حالت «درخواست مهمان»). null یعنی جدول در دسترس نیست. */
function food_guest_requested_total(string $isoDate): ?int
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $isoDate)) {
        return null;
    }
    try {
        $stmt = db()->prepare("SELECT COALESCE(SUM(guest_count), 0) FROM food_guest_requests WHERE request_date = ? AND status = 'active'");
        $stmt->execute([$isoDate]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('[food-order] guest request total failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * موارد ناقص سفارش مهمان را برمی‌گرداند (خالی یعنی آماده). بدون پرتاب خطا.
 * نام دیتابیس فعلی هم در پیام می‌آید تا اگر سامانه به دیتابیس دیگری وصل است معلوم شود.
 */
function food_guest_schema_missing(): array
{
    try {
        $db = (string) db()->query('SELECT DATABASE()')->fetchColumn();
        $tableStmt = db()->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_guest_requests'");
        if ((int) $tableStmt->fetchColumn() === 0) {
            return ['جدول food_guest_requests (upgrade-1.39)', 'دیتابیس فعلی: ' . $db];
        }
        $colStmt = db()->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food_guest_requests'"
        );
        $have = array_map('strval', $colStmt->fetchAll(PDO::FETCH_COLUMN));
        $missing = [];
        foreach (['request_date', 'organization', 'guest_count', 'food_id', 'requester_name', 'deputy_unit_id', 'status'] as $col) {
            if (!in_array($col, $have, true)) {
                $missing[] = 'ستون ' . $col . ' (upgrade-' . ($col === 'deputy_unit_id' ? '1.40' : '1.39') . ')';
            }
        }
        return $missing;
    } catch (Throwable $e) {
        error_log('[food-order] guest schema check failed: ' . $e->getMessage());
        return ['بررسی دیتابیس انجام نشد'];
    }
}

/** جدول و ستون‌های سفارش مهمان آماده‌اند؟ */
function food_guest_schema_ready(): bool
{
    return food_guest_schema_missing() === [];
}

/** برای API سفارش مهمان: اگر migration اجرا نشده، پیام فارسی روشن می‌دهد. */
function food_guest_schema_ensure(): void
{
    if (!food_guest_schema_ready()) {
        throw new RuntimeException('بخش سفارش مهمان هنوز فعال نشده است؛ ابتدا upgrade-1.39-food-guest-requests.sql و upgrade-1.40-food-guest-deputy.sql را روی دیتابیس اجرا کنید.');
    }
}

/** معاونت‌های فعال چارت سازمانی (فهرست انتخاب در فرم و گزارش). */
function food_guest_deputies(): array
{
    if (!function_exists('org_deputy_units')) {
        return [];
    }
    return array_map(
        static fn (array $u): array => ['id' => (int) $u['id'], 'name' => (string) $u['name']],
        org_deputy_units()
    );
}

/**
 * معاونتِ کاربر از چارت سازمانی: از واحد خود کاربر (users.org_unit_id) و واحدهایی که مدیرشان است
 * شروع می‌کند و در سلسلهٔ والدها بالا می‌رود تا به واحد نوع deputy برسد. 0 یعنی پیدا نشد.
 */
function food_guest_user_deputy_id(array $user, bool $managedOnly = false): int
{
    $uid = (int) ($user['id'] ?? 0);
    if ($uid <= 0) {
        return 0;
    }
    $starts = [];
    try {
        if ($managedOnly) {
            throw new RuntimeException('managed-only');
        }
        $own = db()->prepare('SELECT org_unit_id FROM users WHERE id = ?');
        $own->execute([$uid]);
        $ownUnit = (int) ($own->fetchColumn() ?: 0);
        if ($ownUnit > 0) {
            $starts[] = $ownUnit;
        }
    } catch (Throwable $e) {
        // ستون org_unit_id هنوز ساخته نشده است؛ فقط مسیر مدیریت واحد بررسی می‌شود.
    }
    try {
        $managed = db()->prepare('SELECT id FROM org_units WHERE manager_user_id = ? AND is_active = 1 UNION SELECT unit_id FROM org_unit_managers WHERE user_id = ?');
        $managed->execute([$uid, $uid]);
        foreach ($managed->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $starts[] = (int) $id;
        }
    } catch (Throwable $e) {
        // جدول‌های چارت سازمانی در دسترس نیستند.
    }
    $unitStmt = db()->prepare('SELECT id, parent_id, unit_type, is_active FROM org_units WHERE id = ?');
    foreach (array_values(array_unique(array_filter($starts))) as $start) {
        $current = (int) $start;
        for ($depth = 0; $depth < 10 && $current > 0; $depth++) {
            $unitStmt->execute([$current]);
            $unit = $unitStmt->fetch(PDO::FETCH_ASSOC);
            if (!$unit) {
                break;
            }
            if ((string) $unit['unit_type'] === 'deputy' && (int) $unit['is_active'] === 1) {
                return (int) $unit['id'];
            }
            $current = (int) ($unit['parent_id'] ?? 0);
        }
    }
    return 0;
}

function food_guest_request_list(array $user): array
{
    food_guest_schema_ensure();
    $today = food_order_today();
    $stmt = db()->prepare(
        "SELECT r.id, r.request_date, r.organization, r.guest_count, r.requester_name, r.note, r.status,
                r.created_at, r.food_id, c.food_name, r.deputy_unit_id, ou.name AS deputy_name,
                TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS created_by_name
           FROM food_guest_requests r
           LEFT JOIN food_catalog c ON c.id = r.food_id
           LEFT JOIN org_units ou ON ou.id = r.deputy_unit_id
           LEFT JOIN users u ON u.id = r.created_by
          WHERE r.request_date >= ?
          ORDER BY r.request_date ASC, r.id ASC
          LIMIT 300"
    );
    $stmt->execute([$today]);
    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items[] = [
            'id' => (int) $r['id'],
            'request_date' => (string) $r['request_date'],
            'request_jalali' => food_order_jalali_label((string) $r['request_date']),
            'organization' => (string) $r['organization'],
            'guest_count' => (int) $r['guest_count'],
            'food_id' => $r['food_id'] === null ? null : (int) $r['food_id'],
            'food_name' => $r['food_name'] === null ? '' : (string) $r['food_name'],
            'deputy_unit_id' => $r['deputy_unit_id'] === null ? null : (int) $r['deputy_unit_id'],
            'deputy_name' => (string) ($r['deputy_name'] ?? ''),
            'requester_name' => (string) $r['requester_name'],
            'note' => (string) ($r['note'] ?? ''),
            'status' => (string) $r['status'],
            'created_by_name' => (string) ($r['created_by_name'] ?? ''),
            'created_at' => (string) $r['created_at'],
            'editable' => $r['status'] === 'active' && (string) $r['request_date'] > $today,
        ];
    }
    $foods = array_values(array_map(
        static fn (array $f): array => ['id' => $f['id'], 'name' => $f['food_name']],
        array_filter(food_order_catalog(false), static fn (array $f): bool => $f['active'])
    ));
    $myDeputyId = food_guest_user_deputy_id($user);
    $myDeputy = null;
    foreach (food_guest_deputies() as $d) {
        if ($d['id'] === $myDeputyId) {
            $myDeputy = $d;
        }
    }
    return ['items' => $items, 'foods' => $foods, 'my_deputy' => $myDeputy, 'today' => $today, 'mode' => function_exists('food_guest_cap_mode') ? food_guest_cap_mode() : 'requested'];
}

function food_guest_request_create(array $user, array $body): array
{
    food_guest_schema_ensure();
    $today = food_order_today();
    $date = food_order_iso_date($body['request_date'] ?? '');
    if ($date === null) {
        throw new RuntimeException('تاریخ شمسی معتبر نیست.');
    }
    if ($date <= $today) {
        throw new RuntimeException('درخواست غذای مهمان فقط برای روزهای آینده ثبت می‌شود.');
    }
    $organization = trim((string) preg_replace('/\s+/u', ' ', (string) ($body['organization'] ?? '')));
    if (mb_strlen($organization) < 2 || mb_strlen($organization) > 190) {
        throw new RuntimeException('نام سازمان یا شرکت باید بین ۲ تا ۱۹۰ نویسه باشد.');
    }
    $count = filter_var(food_order_normalize_digits($body['guest_count'] ?? ''), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
    if ($count === false) {
        throw new RuntimeException('تعداد مهمان باید عددی بین ۱ تا ۵۰۰ باشد.');
    }
    // نوع غذا حذف شده است؛ غذای مهمان را سلف طبق تشخیص مدیر تعیین می‌کند.
    $foodId = null;
    // معاونت از چارت سازمانی کاربر خوانده می‌شود؛ مقدار ارسال‌شدهٔ فرم نادیده گرفته می‌شود.
    $deputyId = food_guest_user_deputy_id($user, true);
    if ($deputyId <= 0) {
        throw new RuntimeException('برای ثبت سفارش مهمان باید شما در چارت سازمانی زیر یک معاونت تعریف شده باشید (به‌عنوان عضو واحد یا مدیر واحد). با مدیر سامانه هماهنگ کنید.');
    }
    $requester = trim((string) preg_replace('/\s+/u', ' ', (string) ($body['requester_name'] ?? '')));
    if (mb_strlen($requester) < 2 || mb_strlen($requester) > 150) {
        throw new RuntimeException('نام درخواست‌دهنده باید بین ۲ تا ۱۵۰ نویسه باشد.');
    }
    $note = trim((string) ($body['note'] ?? ''));
    if (mb_strlen($note) > 500) {
        throw new RuntimeException('یادداشت نباید بیش از ۵۰۰ نویسه باشد.');
    }
    $stmt = db()->prepare(
        'INSERT INTO food_guest_requests (request_date, organization, guest_count, food_id, requester_name, deputy_unit_id, note, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, \'active\', ?)'
    );
    $stmt->execute([$date, $organization, $count, $foodId, $requester, $deputyId, $note === '' ? null : $note, (int) $user['id']]);
    $id = (int) db()->lastInsertId();
    food_order_audit('food_guest_request_create', $user, 'food_guest_request', $id, null, [
        'request_date' => $date, 'organization' => $organization, 'guest_count' => $count, 'food_id' => $foodId, 'requester_name' => $requester, 'deputy_unit_id' => $deputyId,
    ]);
    return ['id' => $id, 'request_date' => $date];
}

function food_guest_request_cancel(array $user, int $id): void
{
    food_guest_schema_ensure();
    $today = food_order_today();
    $stmt = db()->prepare('SELECT request_date, status, organization, guest_count FROM food_guest_requests WHERE id = ? FOR UPDATE');
    db()->beginTransaction();
    try {
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('درخواست پیدا نشد.');
        }
        if ($row['status'] !== 'active') {
            throw new RuntimeException('این درخواست قبلاً لغو شده است.');
        }
        if ((string) $row['request_date'] <= $today) {
            throw new RuntimeException('درخواست روزهای گذشته یا امروز قابل لغو نیست.');
        }
        db()->prepare("UPDATE food_guest_requests SET status = 'cancelled', cancelled_by = ?, cancelled_at = NOW() WHERE id = ?")
            ->execute([(int) $user['id'], $id]);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    food_order_audit('food_guest_request_cancel', $user, 'food_guest_request', $id, $row, ['status' => 'cancelled']);
}

function food_order_api_handle(string $route, array $user): never
{
    if (!$user) {
        food_order_send_json(['error' => 'برای استفاده از سفارش غذا ابتدا وارد سامانه شوید.'], 401);
    }
    $route = trim(rawurldecode($route), '/');
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'POST') {
        require_csrf();
    }
    try {
        if ($method === 'GET' && $route === 'month') {
            $month = (string) ($_GET['month'] ?? '');
            food_order_send_json(food_order_month_status($month, $user, false));
        }
        if ($method === 'POST' && $route === 'proxy-month') {
            check_api_rate_limit('food_order_proxy_month_' . (int) $user['id'], 30, 60);
            $body = food_order_api_body();
            $target = food_order_verify_employee_national_code(
                max(0, (int) ($body['employee_id'] ?? 0)),
                (string) ($body['national_code'] ?? ''),
                true
            );
            food_order_send_json(food_order_month_status((string) ($body['month'] ?? ''), $target, false));
        }
        if ($method === 'GET' && $route === 'guest-requests') {
            if (!food_guest_request_allowed($user)) {
                food_order_send_json(['error' => 'این بخش فقط برای مدیران و بالاتر فعال است.'], 403);
            }
            food_order_send_json(food_guest_request_list($user) + ['allowed' => true]);
        }
        if ($method === 'POST' && $route === 'guest-request') {
            if (!food_guest_request_allowed($user)) {
                food_order_send_json(['error' => 'این بخش فقط برای مدیران و بالاتر فعال است.'], 403);
            }
            check_api_rate_limit('food_guest_request_' . (int) $user['id'], 30, 60);
            $created = food_guest_request_create($user, food_order_api_body());
            food_order_send_json(['ok' => true, 'message' => 'درخواست غذای مهمان ثبت شد.'] + $created);
        }
        if ($method === 'POST' && $route === 'guest-request-cancel') {
            if (!food_guest_request_allowed($user)) {
                food_order_send_json(['error' => 'این بخش فقط برای مدیران و بالاتر فعال است.'], 403);
            }
            $body = food_order_api_body();
            food_guest_request_cancel($user, (int) ($body['id'] ?? 0));
            food_order_send_json(['ok' => true, 'message' => 'درخواست غذای مهمان لغو شد.']);
        }
        if ($method === 'GET' && $route === 'my-orders') {
            $range = food_order_list_range((string) ($_GET['from'] ?? ''), (string) ($_GET['to'] ?? ''));
            food_order_send_json([
                'items' => food_order_my_orders((int) $user['id'], 400, $range['from'], $range['to']),
                'range' => $range,
            ]);
        }
        if ($method === 'POST' && $route === 'proxy-orders') {
            check_api_rate_limit('food_order_proxy_orders_' . (int) $user['id'], 30, 60);
            $body = food_order_api_body();
            $target = food_order_verify_employee_national_code(
                max(0, (int) ($body['employee_id'] ?? 0)),
                (string) ($body['national_code'] ?? ''),
                true
            );
            $range = food_order_list_range((string) ($body['from'] ?? ''), (string) ($body['to'] ?? ''));
            food_order_send_json([
                'items' => food_order_my_orders((int) $target['id'], 400, $range['from'], $range['to']),
                'range' => $range,
            ]);
        }
        if ($method === 'GET' && $route === 'search-users') {
            check_api_rate_limit('food_order_search_' . (int) $user['id'], 30, 60);
            food_order_send_json(['items' => food_order_employee_search((string) ($_GET['q'] ?? ''))]);
        }
        if ($method === 'POST' && $route === 'verify-proxy') {
            check_api_rate_limit('food_order_proxy_verify_' . (int) $user['id'], 5, 60);
            $body = food_order_api_body();
            $target = food_order_verify_employee_national_code(
                max(0, (int) ($body['employee_id'] ?? 0)),
                (string) ($body['national_code'] ?? ''),
                true
            );
            food_order_send_json(['ok' => true, 'person' => [
                'id' => (int) $target['id'],
                'name' => food_order_full_name($target),
            ]]);
        }
        if ($method === 'POST' && $route === 'order') {
            $body = food_order_api_body();
            $employeeId = max(0, (int) ($body['employee_id'] ?? $user['id']));
            $result = food_order_create_or_change(
                $employeeId,
                (int) $user['id'],
                (string) ($body['food_date'] ?? ''),
                (int) ($body['calendar_item_id'] ?? 0),
                $user,
                isset($body['national_code']) ? (string) $body['national_code'] : null
            );
            food_order_send_json($result);
        }
        if ($method === 'POST' && $route === 'cancel') {
            $body = food_order_api_body();
            food_order_send_json(food_order_cancel((int) ($body['order_id'] ?? 0), $user));
        }
        if ($method === 'POST' && $route === 'cancel-proxy') {
            $body = food_order_api_body();
            food_order_send_json(food_order_cancel_proxy(
                (int) ($body['order_id'] ?? 0),
                (int) ($body['employee_id'] ?? 0),
                (string) ($body['national_code'] ?? ''),
                $user
            ));
        }
        food_order_send_json(['error' => 'مسیر API سفارش غذا پیدا نشد.'], 404);
    } catch (Throwable $e) {
        $status = str_contains($e->getMessage(), 'دسترسی') ? 403 : 400;
        food_order_send_json(['error' => food_order_safe_error($e)], $status);
    }
}

function food_order_menu_api_handle(string $route, array $user): never
{
    $route = trim(rawurldecode($route), '/');
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'POST') {
        require_csrf();
    }
    try {
        if ($method === 'GET' && $route === 'food-menu/status') {
            if (!food_order_user_can($user, 'food.menu') && !food_order_user_can($user, 'food.order_close')) {
                throw new RuntimeException('دسترسی food.menu یا food.order_close لازم است.');
            }
            food_order_send_json(food_order_status_summary());
        }
        if ($method === 'GET' && $route === 'food-menu/month') {
            if (!food_order_user_can($user, 'food.menu') && !food_order_user_can($user, 'food.order_close')) {
                throw new RuntimeException('دسترسی food.menu یا food.order_close لازم است.');
            }
            $includeCounts = food_order_user_can($user, 'food.order_close');
            food_order_send_json(food_order_month_status((string) ($_GET['month'] ?? ''), $user, $includeCounts, true));
        }
        if ($method === 'GET' && $route === 'food-menu/catalog') {
            food_order_require_permission($user, 'food.menu');
            food_order_send_json(['items' => food_order_catalog(true)]);
        }
        if ($method === 'POST' && $route === 'food-menu/catalog') {
            food_order_send_json(food_order_catalog_save(food_order_api_body(), $user));
        }
        if ($method === 'POST' && $route === 'food-menu/day') {
            $result = food_order_save_day(food_order_api_body(), $user);
            if (!empty($result['confirmation_required'])) {
                food_order_send_json($result, 409);
            }
            food_order_send_json($result);
        }
        if ($method === 'GET' && $route === 'food-menu/orders') {
            food_order_require_permission($user, 'food.order_close');
            $date = food_order_iso_date((string) ($_GET['date'] ?? ''));
            if ($date === null) {
                throw new RuntimeException('تاریخ روز سفارش معتبر نیست.');
            }
            food_order_send_json(['date' => $date, 'items' => food_order_manager_orders($date)]);
        }
        if ($method === 'GET' && $route === 'food-menu/orders-export') {
            food_order_require_permission($user, 'food.order_close');
            $date = food_order_iso_date((string) ($_GET['date'] ?? ''));
            if ($date === null) {
                throw new RuntimeException('تاریخ روز سفارش معتبر نیست.');
            }
            food_order_export_manager_orders($date, $user);
        }
        if ($method === 'GET' && $route === 'food-menu/statistics') {
            food_order_require_permission($user, 'food.order_close');
            $from = (string) ($_GET['from'] ?? '');
            $to = (string) ($_GET['to'] ?? '');
            $mode = strtolower(trim((string) ($_GET['mode'] ?? 'food')));
            if (!in_array($mode, ['food', 'employee'], true)) {
                throw new RuntimeException('نوع آمار سفارش معتبر نیست.');
            }
            $fromIso = food_order_iso_date($from);
            $toIso = food_order_iso_date($to);
            if ($fromIso === null || $toIso === null || $fromIso > $toIso) {
                throw new RuntimeException('بازهٔ تاریخ آمار معتبر نیست.');
            }
            food_order_send_json([
                'from' => $fromIso,
                'to' => $toIso,
                'mode' => $mode,
                'items' => food_order_statistics_range($fromIso, $toIso, $mode),
            ]);
        }
        if ($method === 'GET' && $route === 'food-menu/export') {
            food_order_require_permission($user, 'food.order_close');
            food_order_export_statistics_range(
                (string) ($_GET['from'] ?? ''),
                (string) ($_GET['to'] ?? ''),
                $user,
                (string) ($_GET['mode'] ?? 'food')
            );
        }
        food_order_send_json(['error' => 'مسیر API برنامه غذایی پیدا نشد.'], 404);
    } catch (Throwable $e) {
        $status = str_contains($e->getMessage(), 'دسترسی') || str_contains($e->getMessage(), 'فقط ادمین اصلی') ? 403 : 400;
        food_order_send_json(['error' => food_order_safe_error($e)], $status);
    }
}

function food_order_render_page(array $user): never
{
    food_order_schema_ensure();
    $profile = food_order_user_row((int) ($user['id'] ?? 0)) ?? $user;
    $today = food_order_today();
    $todayJalali = food_order_jalali_label($today);
    $selfNational = food_order_digits($profile['national_code'] ?? '');
    $hasNational = strlen($selfNational) === 10;
    $nationalIssue = $selfNational === ''
        ? 'کد ملی شما در سامانه ثبت نیست؛ ثبت سفارش برای خودتان غیرفعال است. برای اصلاح اطلاعات با منابع انسانی هماهنگ کنید.'
        : 'کد ملی ثبت‌شدهٔ شما معتبر نیست؛ ثبت سفارش برای خودتان غیرفعال است. برای اصلاح اطلاعات با منابع انسانی هماهنگ کنید.';
    $canProxy = true; // هر کاربر وارد‌شده می‌تواند برای دیگری سفارش ثبت کند (با کد ملی فرد)
    render_header('سفارش غذا', $user);
    echo '<link rel="stylesheet" href="assets/food-order.css?v=12">';
    echo '<section class="food-order-page"><header class="page-heading"><div><span class="eyebrow">برنامه غذایی سازمان</span><h1>سفارش غذا</h1></div><a class="button secondary" href="index.php">بازگشت به داشبورد</a></header>';
    if (!$hasNational) {
        echo '<div class="alert info" role="status">' . e($nationalIssue) . '</div>';
    }
    echo '<div id="food-order-app" data-api="index.php?page=food-order&amp;food_api=" data-csrf="' . e(csrf_token()) . '" data-self-id="' . (int) ($user['id'] ?? 0) . '" data-self-name="' . e(food_order_full_name($profile)) . '" data-today="' . e($today) . '" data-guest-allowed="' . (food_guest_request_allowed($user) && food_guest_schema_ready() ? '1' : '0') . '" data-today-jalali="' . e($todayJalali) . '" data-has-national="' . ($hasNational ? '1' : '0') . '" data-can-proxy="' . ($canProxy ? '1' : '0') . '"><div class="food-order-loading card">در حال بارگذاری تقویم و سفارش‌های شما…</div></div>';
    echo '</section>';
    if (food_guest_request_allowed($user) && !food_guest_schema_ready()) {
        $missingItems = food_guest_schema_missing();
        echo '<section class="food-guest-page"><div class="alert danger" role="alert"><b>بخش سفارش مهمان فعال نیست.</b> موارد ناقص: ' . e(implode('، ', $missingItems)) . '. migration را روی همان دیتابیسی اجرا کنید که سامانه به آن وصل است.</div></section>';
    }
    if (food_guest_request_allowed($user) && food_guest_schema_ready()) {
        echo '<div hidden id="food-guest-app" data-api="index.php?page=food-order&amp;food_api=" data-csrf="' . e(csrf_token()) . '" data-self-name="' . e(food_order_full_name($profile)) . '"></div>';
    }
    echo '<script defer src="assets/food-order-calendar.js?v=1"></script><script defer src="assets/food-order.js?v=14"></script><script defer src="assets/food-order-guest.js?v=3"></script>';
    render_footer();
    exit;
}

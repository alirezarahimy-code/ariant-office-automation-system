<?php
declare(strict_types=1);

/**
 * عیب‌یابی تطبیق کاربر و سفارش غذا (فقط CLI):
 *   C:\xampp\php\php.exe tools\diag_food_match.php
 *
 * خروجی:
 *  1) چند ردیف آخر SOURCE_TABLE: مقدار خام L_UID/C_Card و اینکه به کدام کاربر وصل می‌شود
 *  2) تعداد کاربران دارای کد پرسنلی (employee_number) و نمونه‌ای از آن‌ها
 *  3) تعداد تجمیعی سفارش‌های امروز از food_orders داخلی (بدون کد ملی و بدون Access سفارش)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/food-ticket.php';
foreach (['food-ticket-runtime.php', 'food-ticket-engine.php'] as $extra) {
    $p = dirname(__DIR__) . '/' . $extra;
    if (is_file($p)) {
        require_once $p;
    }
}

$line = static function (string $text): void {
    fwrite(STDOUT, $text . PHP_EOL);
};
$show = static fn (mixed $v): string => $v === null ? '(null)' : (is_scalar($v) ? var_export($v, true) : json_encode($v, JSON_UNESCAPED_UNICODE));

$config = food_ticket_config(true);
$today = date('Y-m-d');
$line('=== تنظیمات ===');
$line('attendance_path   : ' . ($config['attendance_path'] ?? ''));
$line('order_source : INTERNAL-DB (food_orders)');
$line('امروز (میلادی): ' . $today);
if (function_exists('gregorian_to_jalali')) {
    [$jy, $jm, $jd] = gregorian_to_jalali((int) date('Y'), (int) date('n'), (int) date('j'));
    $line('امروز (شمسی)  : ' . sprintf('%04d/%02d/%02d', $jy, $jm, $jd));
}

// ---- کاربران ----
$line('');
$line('=== کاربران با کد پرسنلی (employee_number) ===');
try {
    $index = food_ticket_user_index(true);
    $line('کل کاربران: ' . $index['count'] . ' | دارای کد پرسنلی: ' . $index['with_code']);
    $sample = array_slice(array_keys($index['emp']), 0, 10);
    $line('نمونه کدهای نرمال‌شده: ' . ($sample ? implode(', ', $sample) : '(هیچ)'));
    if ($index['with_code'] === 0) {
        $line('هشدار: هیچ کاربری کد پرسنلی ندارد؛ همهٔ تردّدها «ناشناس» می‌شوند. کد پرسنلی را در فرم کارمندان یا همگام‌سازی AD پر کنید.');
    }
} catch (Throwable $e) {
    $line('خطا: ' . $e->getMessage());
}

// ---- SOURCE_TABLE ----
$line('');
$line('=== آخرین ردیف‌های SOURCE_TABLE ===');
try {
    $sourceDb = food_ticket_odbc((string) ($config['attendance_path'] ?? ''), food_ticket_attendance_password($config));
    $rows = food_ticket_access_rows($sourceDb, food_ticket_source_table(), 15, 'C_Date DESC, C_Time DESC');
    foreach ($rows as $item) {
        $uid = (string) ($item['uid'] ?? '');
        $user = $uid !== '' ? food_ticket_find_user_strict($uid) : null;
        $cardRaw = (string) ($item['card'] ?? '');
        $mapped = (!$user && $cardRaw !== '') ? food_ticket_card_map_lookup($cardRaw) : null;
        $guest = (!$user && !$mapped && $cardRaw !== '') ? food_ticket_guest_card($cardRaw, $config) : null;
        $line('  تشخیص: ' . ($user ? 'کاربر (L_UID)' : ($mapped ? 'کاربر (نگاشت کارت)' : ($guest ? 'مهمان' : 'ناشناس'))));
        $raw = $item['row']['l_uid'] ?? ($item['row']['luid'] ?? null);
        $line(sprintf(
            'C_Date=%s C_Time=%s | L_UID(خام)=%s → کد=%s | C_Card=%s | کاربر=%s',
            $show($item['date_raw'] ?? null),
            $show($item['time_raw'] ?? null),
            $show($raw),
            $uid === '' ? '(خالی)' : $uid,
            $show($item['card'] ?? ''),
            $user ? ($user['full_name'] . ' [employee_number=' . $user['employee_number'] . ']') : 'پیدا نشد'
        ));
    }
    if (!$rows) {
        $line('(SOURCE_TABLE خالی است؛ یعنی ردیف‌ها قبلاً پردازش و حذف شده‌اند.)');
    } else {
        $line('ستون‌های SOURCE_TABLE: ' . implode(', ', array_values($rows[0]['columns'] ?? [])));
    }
    food_ticket_access_close($sourceDb);
} catch (Throwable $e) {
    $line('خطا: ' . $e->getMessage());
}

// ---- سفارش داخلی ----
$line('');
$line('=== سفارش‌های داخلی امروز ===');
try {
    food_order_schema_ensure();
    $count = db()->prepare("SELECT COUNT(*) FROM food_orders WHERE food_date = ? AND status = 'active'");
    $count->execute([$today]);
    $line('تعداد سفارش فعال امروز: ' . (int) $count->fetchColumn());
    $foods = db()->prepare("SELECT c.food_name, COUNT(*) AS n FROM food_orders o
        JOIN food_calendar_items i ON i.id = o.calendar_item_id JOIN food_catalog c ON c.id = i.food_id
        WHERE o.food_date = ? AND o.status = 'active' GROUP BY c.food_name ORDER BY c.food_name");
    $foods->execute([$today]);
    foreach ($foods->fetchAll(PDO::FETCH_ASSOC) as $food) {
        $line('  ' . $food['food_name'] . ': ' . (int) $food['n']);
    }
    $line('Access سفارش خوانده نشد؛ جزئیات هویتی چاپ نشده است.');
} catch (Throwable $e) {
    $line('خطا در food_orders داخلی: ' . $e->getMessage());
}

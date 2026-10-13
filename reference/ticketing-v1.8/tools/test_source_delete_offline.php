<?php
declare(strict_types=1);

/**
 * آزمون آفلاین «منطق انتخاب شرط حذف ردیف SOURCE_TABLE» — بدون MySQL و بدون Access
 * ============================================================================
 *   php tools/test_source_delete_offline.php
 *
 * چرا؟ باگ اصلی این بود که شرط حذف برای ستون‌های تاریخ در Jet/Access با تقایس
 * رشته‌ای ساخته می‌شد؛ نتیجه: «۰ تطبیق» و بعد هم گزارش موفقیت کاذب.
 * این آزمون همان توابع خالص (بدون دیتابیس) را واکشی و اجرا می‌کند تا ثابت شود:
 *   ۱) کلید اصلی درست تشخیص داده می‌شود (اولین و دقیق‌ترین شرط)
 *   ۲) ستون‌های تاریخ/زمان با قالب Access یعنی #yyyy-MM-dd HH:mm:ss# نوشته می‌شوند
 *   ۳) ستون‌های memo/باینری از شرط حذف بیرون می‌مانند (عامل تطبیق صفر و همچنین
 *      خطای Data type mismatch)
 *   ۴) اگر نوع ستون نامعلوم باشد (مسیر PowerShell)، شرط کمینه ساخته می‌شود
 *   ۵) مقدار غیرعددی هرگز بدون نقل‌قول داخل SQL نمی‌رود (جلوگیری از خطای نحوی)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

/* ───────── واکشی توابع خالص از فایل اصلی (بدون لود کل سامانه) ───────── */

/** بدنهٔ یک تابع را با تطبیق آکولاد از متن PHP بیرون می‌کشد. */
function extractFunction(string $source, string $name): string
{
    $needle = 'function ' . $name . '(';
    $start = strpos($source, $needle);
    if ($start === false) {
        throw new RuntimeException('تابع پیدا نشد: ' . $name);
    }
    $brace = strpos($source, '{', $start);
    if ($brace === false) {
        throw new RuntimeException('آکولاد تابع پیدا نشد: ' . $name);
    }
    $depth = 0;
    $length = strlen($source);
    $inString = null;
    for ($i = $brace; $i < $length; $i++) {
        $ch = $source[$i];
        if ($inString !== null) {
            if ($ch === $inString && $source[$i - 1] !== '\\') {
                $inString = null;
            }
            continue;
        }
        if ($ch === "'" || $ch === '"') {
            $inString = $ch;
            continue;
        }
        if ($ch === '{') {
            $depth++;
        } elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $start, $i - $start + 1);
            }
        }
    }
    throw new RuntimeException('انتهای تابع پیدا نشد: ' . $name);
}

$source = (string) file_get_contents(dirname(__DIR__) . '/food-ticket.php');
foreach ([
    'food_ticket_ident',
    'food_ticket_normalize_digits',
    'food_ticket_source_is_temporal',
    'food_ticket_source_time_literal',
    'food_ticket_source_sql_literal',
    'food_ticket_source_primary_key',
    'food_ticket_source_delete_where',
    'food_ticket_source_minimal_where',
    'food_ticket_source_delete_candidates',
] as $fn) {
    eval(extractFunction($source, $fn));
}

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo '[PASS] ' . $label . ($detail !== '' ? ' — ' . $detail : '') . "\n";
    } else {
        $fail++;
        echo '[FAIL] ' . $label . ($detail !== '' ? ' — ' . $detail : '') . "\n";
    }
}

/* ───────── ۱) تشخیص کلید اصلی ───────── */
$row = ['id' => 4321, 'l_uid' => '0377', 'c_date' => 20261006, 'c_time' => 143940];
$item = [
    'row' => $row,
    'columns' => ['id' => 'ID', 'l_uid' => 'L_UID', 'c_date' => 'C_Date', 'c_time' => 'C_Time'],
    'uid' => '0377',
    'card' => '',
    'date_raw' => 20261006,
    'time_raw' => 143940,
];
$pk = food_ticket_source_primary_key($item);
check('۱: کلید اصلی از ردیف کشف شد', is_array($pk) && $pk['where'] === '[ID] = 4321', json_encode($pk, JSON_UNESCAPED_UNICODE) ?: '');

$noPk = ['row' => ['l_uid' => '0377'], 'columns' => [], 'uid' => '0377', 'card' => '', 'date_raw' => '20261006', 'time_raw' => '143940'];
check('۲: ردیف بدون کلید اصلی → null', food_ticket_source_primary_key($noPk) === null);

/* ───────── ۲) ترتیب شرط‌ها: کلید اصلی → تطبیق کامل → شناسهٔ کمینه ───────── */
$candidates = food_ticket_source_delete_candidates($item);
$labels = array_map(static fn (array $c): string => $c[0], $candidates);
check('۳: ترتیب شرط‌ها درست است', $labels === ['primary_key:ID', 'exact_row', 'uid_date_time'], implode(' | ', $labels));

/* ───────── ۳) ستون‌های تاریخ/زمان با قالب Access ───────── */
$datetimeRow = [
    'l_uid' => '0377',
    'c_date' => '2026-10-06 00:00:00',
    'c_time' => '1899-12-30 14:39:40',
    'note' => 'ورود از درب شمالی',
];
$datetimeItem = [
    'row' => $datetimeRow,
    'columns' => ['l_uid' => 'L_UID', 'c_date' => 'C_Date', 'c_time' => 'C_Time', 'note' => 'Note'],
    'uid' => '0377',
    'card' => '',
    'date_raw' => '2026-10-06 00:00:00',
    'time_raw' => '1899-12-30 14:39:40',
];
$where = food_ticket_source_delete_where($datetimeItem);
check('۴: ستون تاریخ با قالب #...# نوشته شد', is_string($where) && str_contains($where, '[C_Date] = #2026-10-06 00:00:00#'), (string) $where);
check('۵: ستون ساعت با قالب #...# نوشته شد', is_string($where) && str_contains($where, '[C_Time] = #1899-12-30 14:39:40#'), (string) $where);
check('۶: متن ستون یادداشت نقل‌قول‌شده است', is_string($where) && str_contains($where, "[Note] = 'ورود از درب شمالی'"), (string) $where);

/* ───────── ۴) انواع ستون‌ها: numeric بدون نقل‌قول، memo حذف شود ───────── */
$typedItem = [
    'row' => ['l_uid' => '0377', 'c_date' => 20261006, 'big_note' => 'متن طولانی', 'flag' => true],
    'columns' => ['l_uid' => 'L_UID', 'c_date' => 'C_Date', 'big_note' => 'BigNote', 'flag' => 'Flag'],
    'types' => ['l_uid' => 'varchar', 'c_date' => 'integer', 'big_note' => 'longvarchar', 'flag' => 'boolean'],
    'uid' => '0377',
    'card' => '',
    'date_raw' => 20261006,
    'time_raw' => 143940,
];
$typedWhere = (string) food_ticket_source_delete_where($typedItem);
check('۷: ستون عددی بدون نقل‌قول', str_contains($typedWhere, '[C_Date] = 20261006'), $typedWhere);
check('۸: ستون memo از شرط حذف حذف شد', !str_contains($typedWhere, 'BigNote'), $typedWhere);
check('۹: ستون boolean از شرط حذف حذف شد', !str_contains($typedWhere, '[Flag]'), $typedWhere);

/* ───────── ۵) شناسهٔ کمینه و امنیت نوشتن مقدار ───────── */
$jalaliTextDate = [
    'row' => ['l_uid' => '0377', 'c_date' => '1404/07/14', 'c_time' => '14:39:40'],
    'columns' => ['l_uid' => 'L_UID', 'c_date' => 'C_Date', 'c_time' => 'C_Time'],
    'uid' => '0377',
    'card' => '',
    'date_raw' => '1404/07/14',
    'time_raw' => '14:39:40',
];
$minimal = (string) food_ticket_source_minimal_where($jalaliTextDate);
check('۱۰: تاریخ متنی شمسی نقل‌قول‌شده نوشته شد (نه SQL خراب)', str_contains($minimal, "[C_Date] = '1404/07/14'"), $minimal);
check('۱۱: ساعت استاندارد با قالب #...#', str_contains($minimal, '[C_Time] = #1899-12-30 14:39:40#'), $minimal);

$isoMinimal = (string) food_ticket_source_minimal_where($datetimeItem);
check('۱۲: شناسهٔ کمینه شامل uid و هر دو ستون زمان است', str_contains($isoMinimal, "[L_UID] = '0377'") && str_contains($isoMinimal, '[C_Date] = #2026-10-06 00:00:00#'), $isoMinimal);

$noUidItem = ['row' => ['x' => 1], 'columns' => [], 'uid' => '', 'card' => '', 'date_raw' => '2026-10-06', 'time_raw' => '10:00:00'];
check('۱۳: بدون uid/کارت → شرط کمینه ساخته نمی‌شود', food_ticket_source_minimal_where($noUidItem) === null);

/* ───────── ۶) آرایهٔ خالی/ناقص کرش نمی‌کند ───────── */
$empty = ['row' => [], 'columns' => [], 'uid' => '', 'card' => '', 'date_raw' => null, 'time_raw' => null];
check('۱۴: ردیف خالی → بدون شرط (رد امن)', food_ticket_source_delete_candidates($empty) === []);

echo "\n";
echo $fail === 0
    ? 'همهٔ بررسی‌های منطق حذف موفق بودند (' . $pass . " مورد).\n"
    : $fail . " مورد ناموفق از " . ($pass + $fail) . " مورد.\n";
exit($fail === 0 ? 0 : 1);

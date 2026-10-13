<?php
declare(strict_types=1);

/**
 * آزمون آفلاین «ورودی تاریخ» — شمسی/میلادی، بدون MySQL و بدون مرورگر
 * ============================================================================
 *   php tools/test_date_input_offline.php
 *
 * چرا؟ پنل غیبت روزانه فقط تاریخ شمسی می‌گیرد، اما قالب انتقال داده با API همان
 * میلادی Y-m-d است. اگر تبدیل شمسی→میلادی یک روز/یک سال خطا بدهد، غیبت روی تاریخ
 * اشتباه ثبت می‌شود. این آزمون:
 *   ۱) مبدل محلی food_ticket_jalali_to_gregorian را با مبدل خود سامانه
 *      (bootstrap.php → jalali_to_gregorian) روی صدها تاریخ مقایسه می‌کند
 *   ۲) چند تاریخ مرجع (نوروزها و ۳۰ اسفند سال کبیسه) را چک می‌کند
 *   ۳) food_ticket_iso_date_input را با ورودی شمسی، میلادی، رقم فارسی و ورودی خراب می‌آزماید
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

/** بدنهٔ یک تابع را با تطبیق آکولاد از متن PHP بیرون می‌کشد. */
function extractFunction(string $source, string $name, ?string $rename = null): string
{
    $needle = 'function ' . $name . '(';
    $start = strpos($source, $needle);
    if ($start === false) {
        throw new RuntimeException('تابع پیدا نشد: ' . $name);
    }
    $brace = strpos($source, '{', $start);
    $depth = 0;
    $inString = null;
    for ($i = $brace; $i < strlen($source); $i++) {
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
                $code = substr($source, $start, $i - $start + 1);
                if ($rename !== null) {
                    $code = preg_replace('/^function\s+' . preg_quote($name, '/') . '\s*\(/', 'function ' . $rename . '(', $code) ?? $code;
                }
                return $code;
            }
        }
    }
    throw new RuntimeException('انتهای تابع پیدا نشد: ' . $name);
}

$root = dirname(__DIR__);
$srcFood = (string) file_get_contents($root . '/food-ticket.php');
$srcApp = (string) file_get_contents($root . '/bootstrap.php');

// مبدل خود سامانه با نام مرجع (تا مسیر پشتیبان محلی هم واقعاً آزموده شود)
eval(extractFunction($srcApp, 'jalali_to_gregorian', 'jalali_to_gregorian_ref'));

// توابع فایل فیش غذا (food_ticket_parse_date در نبود jalali_to_gregorian از نسخهٔ محلی استفاده می‌کند)
eval(extractFunction($srcFood, 'food_ticket_jalali_to_gregorian'));
eval(extractFunction($srcFood, 'food_ticket_normalize_digits'));
eval(extractFunction($srcFood, 'food_ticket_parse_date'));
eval(extractFunction($srcFood, 'food_ticket_iso_date_input'));

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

check('۰: تابع سامانه در این آزمون تعریف نشده است (پس مسیر پشتیبان محلی آزموده می‌شود)', !function_exists('jalali_to_gregorian'));

/* ───────── ۱) تاریخ‌های مرجع ───────── */
$known = [
    '1405/07/14' => '2026-10-06',  // امروز — ۱۴ مهر ۱۴۰۵
    '1405/01/01' => '2026-03-21',  // نوروز ۱۴۰۵
    '1404/01/01' => '2025-03-21',  // نوروز ۱۴۰۴
    '1403/01/01' => '2024-03-20',  // نوروز ۱۴۰۳
    '1400/01/01' => '2021-03-21',  // نوروز ۱۴۰۰
    '1399/12/30' => '2021-03-20',  // ۳۰ اسفند سال کبیسه
    '1403/12/30' => '2025-03-20',  // ۳۰ اسفند سال کبیسه
    '1404/12/29' => '2026-03-20',  // پایان سال غیرکبیسه
];
foreach ($known as $jalali => $gregorian) {
    [$jy, $jm, $jd] = array_map('intval', explode('/', $jalali));
    $got = food_ticket_jalali_to_gregorian($jy, $jm, $jd);
    check('۱: ' . $jalali . ' → ' . $gregorian, $got === $gregorian, (string) $got);
}

/* ───────── ۲) هم‌ارزی با مبدل خود سامانه (نمونه‌برداری گسترده) ───────── */
$mismatch = [];
$checked = 0;
for ($jy = 1395; $jy <= 1410; $jy++) {
    foreach ([1, 3, 6, 7, 9, 12] as $jm) {
        for ($jd = 1; $jd <= 31; $jd += 3) {
            $mine = food_ticket_jalali_to_gregorian($jy, $jm, $jd);
            [$gy, $gm, $gd] = jalali_to_gregorian_ref($jy, $jm, $jd);
            $ref = sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
            $checked++;
            if ($mine !== $ref) {
                $mismatch[] = $jy . '/' . $jm . '/' . $jd . ' mine=' . var_export($mine, true) . ' ref=' . $ref;
            }
        }
    }
}
check('۲: مبدل محلی با مبدل سامانه یکی است (' . $checked . ' تاریخ)', $mismatch === [], implode(' | ', array_slice($mismatch, 0, 3)));

/* ───────── ۳) ورودی API: شمسی و میلادی ───────── */
$cases = [
    '1405/07/14' => '2026-10-06',
    '۱۴۰۵/۰۷/۱۴' => '2026-10-06',   // ارقام فارسی
    '1405-07-14' => '2026-10-06',
    '14050714' => '2026-10-06',
    '2026-10-06' => '2026-10-06',
    '20261006' => '2026-10-06',
    '2026/10/06' => '2026-10-06',
    '۱۴۰۵۰۷۱۴' => '2026-10-06',
    '' => null,
    '   ' => null,
    'abc' => null,
    '1405/13/01' => null,   // ماه ۱۳ نامعتبر
];
foreach ($cases as $input => $expected) {
    $got = food_ticket_iso_date_input($input);
    check('۳: ورودی «' . ($input === '' ? '(خالی)' : $input) . '» → ' . var_export($expected, true), $got === $expected, var_export($got, true));
}

/* ───────── ۴) کمکی: نرمال‌سازی ارقام ───────── */
check('۴: ارقام فارسی به لاتین', food_ticket_normalize_digits('۱۴۰۵/۰۷/۱۴') === '1405/07/14', food_ticket_normalize_digits('۱۴۰۵/۰۷/۱۴'));

echo "\n";
echo $fail === 0
    ? 'همهٔ بررسی‌های ورودی تاریخ موفق بودند (' . $pass . " مورد).\n"
    : $fail . " مورد ناموفق از " . ($pass + $fail) . " مورد.\n";
exit($fail === 0 ? 0 : 1);

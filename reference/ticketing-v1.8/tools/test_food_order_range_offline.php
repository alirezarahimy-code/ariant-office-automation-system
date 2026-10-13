<?php
declare(strict_types=1);

// آزمون آفلاین بازهٔ فهرست سفارش‌های غذا (my-orders / proxy-orders).
// توابع را مستقیم از فایل‌های اصلی استخراج می‌کند تا همان کدی تست شود که اجرا می‌شود.

$root = dirname(__DIR__);

function extract_php_function(string $file, string $name): string
{
    $src = (string) file_get_contents($file);
    $start = strpos($src, 'function ' . $name . '(');
    if ($start === false) {
        throw new RuntimeException("تابع {$name} در {$file} پیدا نشد");
    }
    $open = strpos($src, '{', $start);
    $depth = 0;
    $len = strlen($src);
    for ($i = $open; $i < $len; $i++) {
        if ($src[$i] === '{') {
            $depth++;
        } elseif ($src[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $start, $i - $start + 1);
            }
        }
    }
    throw new RuntimeException("پایان تابع {$name} پیدا نشد");
}

$foodOrder = $root . '/food-order.php';
$bootstrap = $root . '/bootstrap.php';
$code = '';
foreach (['gregorian_to_jalali', 'jalali_to_gregorian'] as $fn) {
    $code .= extract_php_function($bootstrap, $fn) . "\n";
}
foreach (['food_order_normalize_digits', 'food_order_today', 'food_order_list_date', 'food_order_list_range', 'food_order_jalali_month_range'] as $fn) {
    $code .= extract_php_function($foodOrder, $fn) . "\n";
}
eval($code);

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "✓ {$label}\n";
    } else {
        $fail++;
        echo "✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}
function throws(callable $fn): bool
{
    try {
        $fn();
        return false;
    } catch (RuntimeException $e) {
        return true;
    }
}

// ۱. تبدیل تاریخ ورودی
check('شمسی با ارقام فارسی: ۱۴۰۵/۰۷/۰۱ = 2026-09-23', food_order_list_date('۱۴۰۵/۰۷/۰۱') === '2026-09-23', (string) food_order_list_date('۱۴۰۵/۰۷/۰۱'));
check('شمسی با خط تیره: 1405-07-01 = 2026-09-23', food_order_list_date('1405-07-01') === '2026-09-23');
check('میلادی: 2026-10-10 همان‌طور می‌ماند', food_order_list_date('2026-10-10') === '2026-10-10');
check('خالی ⇒ null', food_order_list_date('   ') === null);
check('روز ۳۱ مهر (ماه ۳۰ روزه) رد شود', throws(fn() => food_order_list_date('1405/07/31')));
check('ماه ۱۳ رد شود', throws(fn() => food_order_list_date('1405/13/01')));
check('متن نامعتبر رد شود', throws(fn() => food_order_list_date('امروز')));
check('۳۱ فروردین (ماه ۳۱ روزه) پذیرفته شود', food_order_list_date('1405/01/31') !== null);

// ۲. پیش‌فرض: از امروز تا پایان ماه شمسی جاری
$r = food_order_list_range('', '');
$today = food_order_today();
check('پیش‌فرض: شروع = امروز', $r['from'] === $today, $r['from'] . ' vs ' . $today);
[$jy, $jm] = gregorian_to_jalali((int) substr($r['to'], 0, 4), (int) substr($r['to'], 5, 2), (int) substr($r['to'], 8, 2));
[$ty, $tm] = gregorian_to_jalali((int) substr($today, 0, 4), (int) substr($today, 5, 2), (int) substr($today, 8, 2));
check('پیش‌فرض: پایان در همان ماه شمسی امروز است', $jy === $ty && $jm === $tm);
$next = (new DateTimeImmutable($r['to'], new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
[$ny, $nm] = gregorian_to_jalali((int) substr($next, 0, 4), (int) substr($next, 5, 2), (int) substr($next, 8, 2));
check('پیش‌فرض: روز بعد از پایان، ماه شمسی دیگری است', $ny !== $jy || $nm !== $jm);

// ۳. بازهٔ دلخواه
$r2 = food_order_list_range('1405/07/01', '1405/07/30');
check('بازهٔ دلخواه: 1405/07/01 تا 1405/07/30', $r2 === ['from' => '2026-09-23', 'to' => food_order_list_date('1405/07/30')], json_encode($r2));
$r3 = food_order_list_range('1405/06/01', '');
check('فقط شروع داده شد: پایان = پایان ماه جاری', $r3['from'] === food_order_list_date('1405/06/01') && $r3['to'] === $r['to']);
check('شروع > پایان رد شود', throws(fn() => food_order_list_range('1405/07/10', '1405/07/01')));
check('بیش از ۴۰۰ روز رد شود', throws(fn() => food_order_list_range('1400/01/01', '1405/01/01')));
check('دقیقاً ۴۰۰ روز پذیرفته شود', food_order_list_range('2026-01-01', '2027-02-04')['to'] === '2027-02-04');

echo "\n{$pass} موفق، {$fail} ناموفق\n";
exit($fail === 0 ? 0 : 1);

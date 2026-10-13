<?php
declare(strict_types=1);

/**
 * آزمون منطق «برنامهٔ فیش گروه» بدون دیتابیس (فقط CLI) — قابل اجرا روی هر ماشینی با PHP.
 *
 *   php tools/test_food_group_logic_offline.php
 *
 * این آزمون فقط تابع خالص food_ticket_group_plan() را می‌سنجد:
 * محاسبهٔ حاضر/غایب/واجد شرایط/بدون سفارش/فیش‌گرفته و ساخت کلید فیش.
 * آزمون‌های ۱۵گانهٔ سرتاسری که به دیتابیس واقعی نیاز دارند در
 * tools/test_food_group_representative.php هستند.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

// ── جایگزین‌های حداقلی توابع کمکی (همان قرارداد food-ticket.php) ──
if (!function_exists('food_ticket_normalize_digits')) {
    function food_ticket_normalize_digits(string $value): string
    {
        return strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }
}
if (!function_exists('food_ticket_code')) {
    function food_ticket_code(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_numeric($value) && !is_string($value)) {
            return (string) (int) round((float) $value);
        }
        $clean = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}\x{00A0}\s]+/u', '', (string) $value);
        $value = food_ticket_normalize_digits(trim($clean ?? (string) $value));
        if (preg_match('/^(\d+)\.0+$/', $value, $m)) {
            $value = $m[1];
        }
        return ltrim($value, '0') === '' ? ($value === '' ? '' : '0') : ltrim($value, '0');
    }
}
if (!function_exists('food_ticket_digits')) {
    function food_ticket_digits(mixed $value): string
    {
        return preg_replace('/\D+/', '', food_ticket_normalize_digits((string) $value)) ?? '';
    }
}

require dirname(__DIR__) . '/food-ticket-groups.php';

$fail = 0;
$check = static function (string $label, bool $cond, string $detail = '') use (&$fail): void {
    if (!$cond) {
        $fail++;
    }
    echo ($cond ? '[PASS] ' : '[FAIL] ') . $label . ($detail !== '' ? ' — ' . $detail : '') . "\n";
};

$date = '2026-10-05';
$members = static function (): array {
    return [
        ['id' => 1, 'full_name' => 'الف', 'employee_number' => '1001', 'national_code' => '1111111111', 'is_active' => 1],
        ['id' => 2, 'full_name' => 'ب', 'employee_number' => '1002', 'national_code' => '2222222222', 'is_active' => 1],
        ['id' => 3, 'full_name' => 'پ', 'employee_number' => '1003', 'national_code' => '3333333333', 'is_active' => 1],
        ['id' => 4, 'full_name' => 'ت', 'employee_number' => '1004', 'national_code' => '4444444444', 'is_active' => 1],
        ['id' => 5, 'full_name' => 'ث', 'employee_number' => '1005', 'national_code' => '5555555555', 'is_active' => 0],
        ['id' => 6, 'full_name' => 'ج', 'employee_number' => '1006', 'national_code' => '', 'is_active' => 1],
    ];
};
$orders = static fn (array $nats): callable => static function (array $m) use ($nats): ?string {
    $nat = food_ticket_digits($m['national_code'] ?? '');
    return in_array($nat, $nats, true) ? 'چلوکباب' : null;
};

echo "=== آزمون منطق برنامهٔ فیش گروه (بدون دیتابیس) ===\n\n";

// ۱) محاسبهٔ حاضر/غایب/واجد شرایط/بدون سفارش/فیش‌گرفته
$plan = food_ticket_group_plan($members(), [2 => true], [], $orders(['1111111111', '2222222222']), $date);
$check('۱-الف: شمارش اعضا = ۶', $plan['total'] === 6, 'total=' . $plan['total']);
$check('۱-ب: غایب = ۱', $plan['absent'] === 1, 'absent=' . $plan['absent']);
$check('۱-پ: حاضر = ۴ (به‌جز غایب ۲ و غیرفعال ۵)', $plan['present'] === 4, 'present=' . $plan['present']);
$check('۱-ت: غیرفعال جدا شمرده می‌شود', $plan['inactive'] === 1, 'inactive=' . $plan['inactive']);
$check('۱-ث: بدون کد ملی جدا شمرده می‌شود', $plan['no_national'] === 1, 'no_national=' . $plan['no_national']);
$check('۱-ج: بدون سفارش = ۲ (۳ و ۴)', $plan['no_order'] === 2, 'no_order=' . $plan['no_order']);
$check('۱-چ: واجد شرایط = فقط الف (ب غایب است)', $plan['eligible'] === 1, 'eligible=' . $plan['eligible']);
$states = [];
foreach ($plan['rows'] as $r) {
    $states[$r['user_id']] = $r['state'];
}
$check('۱-ح: وضعیت عضو غایب = absent', $states[2] === 'absent', (string) ($states[2] ?? ''));
$check('۱-خ: وضعیت عضو غیرفعال = inactive', $states[5] === 'inactive', (string) ($states[5] ?? ''));
$check('۱-د: کلید فیش عضو واجد شرایط درست است', ($plan['rows'][0]['ticket_key'] ?? '') === 'food:' . $date . ':1111111111', (string) ($plan['rows'][0]['ticket_key'] ?? ''));

// ۲) عضوی که قبلاً فیش گرفته دوباره واجد شرایط نمی‌شود
$plan2 = food_ticket_group_plan($members(), [], ['food:' . $date . ':1111111111' => true], $orders(['1111111111', '2222222222']), $date);
$check('۲: فیش‌گرفته‌ها Eligible نمی‌شوند', $plan2['eligible'] === 1 && $plan2['already_ticketed'] === 1, 'eligible=' . $plan2['eligible'] . ' already=' . $plan2['already_ticketed']);

// ۳) استقلال تاریخ‌ها: با همان اعضا و بدون غیبت، در تاریخ دیگر همهٔ سفارش‌دارها Eligible می‌شوند
$otherDate = '2026-10-06';
$plan3 = food_ticket_group_plan($members(), [], [], $orders(['1111111111', '2222222222', '3333333333']), $otherDate);
$check('۳-الف: تاریخ دوم غیبت مستقل دارد', $plan3['absent'] === 0, 'absent=' . $plan3['absent']);
$check('۳-ب: تاریخ دوم ۳ نفر Eligible', $plan3['eligible'] === 3, 'eligible=' . $plan3['eligible']);
$check('۳-پ: کلید فیش به تاریخ دوم اشاره دارد', ($plan3['rows'][0]['ticket_key'] ?? '') === 'food:' . $otherDate . ':1111111111', (string) ($plan3['rows'][0]['ticket_key'] ?? ''));

// ۴) خالص بودن تابع: اجرای دوباره با همان ورودی‌ها نتیجهٔ یکسان می‌دهد (هیچ حالت پنهانی ندارد)
$again = food_ticket_group_plan($members(), [2 => true], [], $orders(['1111111111', '2222222222']), $date);
$check('۴: تابع خالص است (نتیجهٔ تکرار یکسان)', $again === $plan);

// ۵) گروه بدون عضو
$plan5 = food_ticket_group_plan([], [], [], $orders([]), $date);
$check('۵: گروه خالی → همهٔ شمارنده‌ها صفر', $plan5['total'] === 0 && $plan5['eligible'] === 0 && $plan5['present'] === 0);

// ۶) غیبت بر سفارش اولویت دارد (کسی که سفارش دارد ولی غایب است، فیش نمی‌گیرد)
$plan6 = food_ticket_group_plan($members(), [1 => true], [], $orders(['1111111111']), $date);
$check('۶: غایب حتی با سفارش، فیش نمی‌گیرد', $plan6['eligible'] === 0 && $plan6['absent'] === 1, 'eligible=' . $plan6['eligible']);

// ۷) نرمال‌سازی کد ملی (فارسی/صفر ابتدایی) در کلید فیش
$persian = [['id' => 9, 'full_name' => 'ک', 'employee_number' => '1009', 'national_code' => '۰۰۳۷۷', 'is_active' => 1]];
$plan7 = food_ticket_group_plan($persian, [], [], static fn (array $m): ?string => 'چلو', $date);
// نکته: موتور فیش (food_ticket_decide_punch) هم از food_ticket_digits استفاده می‌کند و صفرهای ابتدایی را
// حفظ می‌کند؛ پس کلیدهای گروهی و فردی دقیقاً یکسان ساخته می‌شوند و «یک فیش در روز» قابل تضمین است.
$check('۷: کد ملی فارسی به رقم لاتین تبدیل می‌شود', ($plan7['rows'][0]['national_code'] ?? '') === '00377', (string) ($plan7['rows'][0]['national_code'] ?? ''));
$check('۷-ب: کلید فیش همان قرارداد موتور است', ($plan7['rows'][0]['ticket_key'] ?? '') === 'food:' . $date . ':00377', (string) ($plan7['rows'][0]['ticket_key'] ?? ''));

echo "\n" . ($fail === 0 ? "همهٔ آزمون‌ها موفق بودند.\n" : ($fail . " آزمون ناموفق بود.\n"));
exit($fail === 0 ? 0 : 1);

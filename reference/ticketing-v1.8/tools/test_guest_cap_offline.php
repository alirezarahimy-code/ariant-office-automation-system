<?php
declare(strict_types=1);

/**
 * آزمون سقف فیش مهمان (بدون دیتابیس، فقط CLI).
 *   php tools/test_guest_cap_offline.php
 * قاعده: سقف متغیر = ورودها تا آن لحظه − خروج‌های قبل از ساعت قطع.
 * خروج بعد از ساعت قطع سقف را کم نمی‌کند (آن افراد فیش گرفته و غذا خورده‌اند).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once dirname(__DIR__) . '/food-ticket-engine.php';

// تنظیمات تست: مقدار را از $GLOBALS['test_settings'] می‌خواند تا ساعت‌های قابل تنظیم آزموده شوند.
$GLOBALS['test_settings'] = [];
function setting(string $key, $default = null)
{
    return $GLOBALS['test_settings'][$key] ?? $default;
}

$pass = 0;
$fail = 0;
function check(string $name, bool $ok): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . "\n";
}

$cap = static fn (int $entries, int $earlyExits): int => food_guest_variable_cap_from_counts($entries, $earlyExits);

// ۱) ساعت ۱۲:۱۰ — ۴ نفر وارد شده‌اند، هیچ‌کس قبل از ۱۲ خارج نشده ⇒ سقف ۴.
check('۱۲:۱۰ چهار ورودی، بدون خروج قبل از قطع ⇒ سقف ۴', $cap(4, 0) === 4);

// ۲) ۱۲:۳۰ — دو نفر که فیش گرفته‌اند بعد از ۱۲ خارج می‌شوند ⇒ سقف همچنان ۴، و ۲ فیش باقی است.
check('خروج بعد از ۱۲ سقف را کم نمی‌کند ⇒ سقف ۴', $cap(4, 0) === 4);
check('سقف ۴ با ۲ فیش صادرشده ⇒ هنوز مجاز', food_ticket_guest_cap_reached(2, 4, 0, 0, 0) === false);
check('سقف ۴ با ۴ فیش صادرشده ⇒ بسته', food_ticket_guest_cap_reached(4, 4, 0, 0, 0) === true);

// ۳) ۱۲:۴۵ — یک نفر جدید وارد می‌شود ⇒ ورودها ۵ ⇒ سقف ۵ و با ۲ فیش یکی دیگر مجاز است.
check('ورود نفر جدید ۱۲:۴۵ ⇒ سقف ۵', $cap(5, 0) === 5);
check('سقف ۵ با ۲ فیش صادرشده ⇒ مجاز', food_ticket_guest_cap_reached(2, 5, 0, 0, 0) === false);

// ۴) خروج قبل از ساعت قطع سقف را کم می‌کند.
check('یک خروج قبل از ۱۲ از ۴ ورودی ⇒ سقف ۳', $cap(4, 1) === 3);
check('سقف ۳ با ۳ فیش صادرشده ⇒ بسته', food_ticket_guest_cap_reached(3, 3, 0, 0, 0) === true);

// ۵) کسی نیامده ⇒ بسته؛ سقف هرگز منفی نمی‌شود.
check('بدون ورودی ⇒ سقف ۰ و بسته', $cap(0, 0) === 0 && food_ticket_guest_cap_reached(0, 0, 0, 0, 0) === true);
check('خروج بیشتر از ورودی ⇒ سقف صفر (نه منفی)', $cap(0, 2) === 0);

// ۶) تردد در دسترس نیست ⇒ سقف ثابت قدیمی.
check('سقف متغیر نامعلوم، سقف ثابت ۲۰: ۱۹ صادر ⇒ مجاز', food_ticket_guest_cap_reached(19, null, 20, 0, 0) === false);
check('سقف متغیر نامعلوم، سقف ثابت ۲۰: ۲۰ صادر ⇒ بسته', food_ticket_guest_cap_reached(20, null, 20, 0, 0) === true);
check('نه تردد نه سقف ثابت ⇒ بی‌سقف (رفتار قبلی)', food_ticket_guest_cap_reached(999, null, 0, 0, 0) === false);

// ۷) سقف هر کارت همچنان اعمال می‌شود.
check('سقف کارت ۲: صادرشده ۲ ⇒ بسته حتی اگر سقف کل جا باشد', food_ticket_guest_cap_reached(2, 10, 20, 2, 2) === true);
check('سقف کارت ۲: صادرشده ۱ ⇒ مجاز', food_ticket_guest_cap_reached(1, 10, 20, 1, 2) === false);

// ۸) پیش‌فرض‌ها بدون تنظیمات: حالت ثابت و ساعت قطع ۱۲:۰۰.
check('پیش‌فرض ساعت قطع ۱۲:۰۱:۰۰', food_guest_cap_cutoff() === '12:01:00');
// بازهٔ ۱۱ تا ۱۵: قبل از ۱۱ و از ۱۵ به بعد سقف صفر است.
check('ساعت ۱۰:۵۹ سقف صفر', food_guest_variable_cap_at('10:59:59', 3, 0) === 0);
check('ساعت ۱۱:۰۰ سقف = حاضرین', food_guest_variable_cap_at('11:00:00', 3, 0) === 3);
check('ساعت ۱۴:۵۹ هنوز فعال', food_guest_variable_cap_at('14:59:59', 4, 0) === 4);
check('ساعت ۱۵:۰۰ سقف صفر و روز بعد دوباره از ۱۱', food_guest_variable_cap_at('15:00:00', 4, 0) === 0);
check('خروج ۱۲:۰۰:۵۹ سقف را کم می‌کند', food_guest_variable_cap_at('12:01:00', 4, 1) === 3);
check('پیش‌فرض حالت سقف ثابت', food_guest_cap_mode() === 'fixed');

// ۹) ساعت‌های قابل تنظیم: شروع، قطع و پایان از فرم کارت مهمان خوانده می‌شوند.
$GLOBALS['test_settings'] = ['food_guest_cap_start' => '10:30', 'food_guest_cap_cutoff' => '12:30', 'food_guest_cap_end' => '14:00'];
check('شروع تنظیمی ۱۰:۳۰', food_guest_cap_start() === '10:30:00');
check('قطع تنظیمی ۱۲:۳۰', food_guest_cap_cutoff() === '12:30:00');
check('پایان تنظیمی ۱۴:۰۰', food_guest_cap_end() === '14:00:00');
check('تنظیمی: ۱۰:۲۹ هنوز صفر', food_guest_variable_cap_at('10:29:59', 3, 0) === 0);
check('تنظیمی: ۱۰:۳۰ سقف = حاضرین', food_guest_variable_cap_at('10:30:00', 3, 0) === 3);
check('تنظیمی: ۱۴:۰۰ صفر', food_guest_variable_cap_at('14:00:00', 3, 0) === 0);
$GLOBALS['test_settings'] = ['food_guest_cap_start' => '14:00', 'food_guest_cap_end' => '11:00'];
check('پایان قبل از شروع ⇒ پیش‌فرض ۱۱ تا ۱۵', food_guest_variable_cap_at('12:00:00', 2, 0) === 2 && food_guest_variable_cap_at('15:00:00', 2, 0) === 0);
$GLOBALS['test_settings'] = ['food_guest_cap_start' => 'abc'];
check('ساعت نامعتبر ⇒ پیش‌فرض', food_guest_cap_start() === '11:00:00');
$GLOBALS['test_settings'] = [];

echo "\nنتیجه: $pass موفق، $fail ناموفق\n";
exit($fail === 0 ? 0 : 1);

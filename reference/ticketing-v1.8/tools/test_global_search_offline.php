<?php
declare(strict_types=1);

/**
 * آزمون آفلاین «جست‌وجوی سراسری بخش‌ها» — ۱.۳۷.۷
 *
 * چه چیزی را ثابت می‌کند:
 *   ۱) نوشتن یک کلمهٔ منویی (مثل «تردد») همهٔ صفحه‌های مرتبط را پیدا می‌کند.
 *   ۲) نرمال‌سازی فارسی درست است (ی/ي، ک/ك، نیم‌فاصله، ارقام).
 *   ۳) فقط صفحه‌هایی نشان داده می‌شوند که کاربر اجازهٔ دیدنشان را دارد.
 *   ۴) کاتالوگ سالم است (کلید یکتا، عنوان و نشانی پر) و دروازهٔ آن به index.php وصل شده است.
 *
 * اجرا:  php tools\test_global_search_offline.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('این فایل فقط از خط فرمان اجرا می‌شود.');
}

define('APP_ROOT', dirname(__DIR__));

$pass = 0;
$fail = 0;
$lines = [];

function check(string $title, bool $condition, string $detail = ''): void
{
    global $pass, $fail, $lines;
    if ($condition) {
        $pass++;
        $lines[] = '  ✓ ' . $title;
        return;
    }
    $fail++;
    $lines[] = '  ✗ ' . $title . ($detail !== '' ? ' — ' . $detail : '');
}

require APP_ROOT . '/global-search.php';

$catalog = global_search_catalog();
$allowAll = static fn (array $section): bool => true;
$titlesOf = static function (array $sections): array {
    return array_map(static fn (array $section): string => (string) $section['title'], $sections);
};

$lines[] = '── ۱) نرمال‌سازی فارسی ──';
check('ي عربی ⇐ ی فارسی', global_search_normalize('تيكت') === global_search_normalize('تیکت'));
check('ك عربی ⇐ ک فارسی', global_search_normalize('كمك') === global_search_normalize('کمک'));
check('نیم‌فاصله حذف می‌شود', global_search_normalize('دانش‌نامه') === global_search_normalize('دانشنامه'));
check('ارقام فارسی ⇐ لاتین', global_search_normalize('فیش ۱۲') === global_search_normalize('فیش 12'));
check('فاصله‌های اضافی جمع می‌شوند', global_search_normalize('  چاپ   تردد ') === 'چاپ تردد');
check('متن خالی سالم برمی‌گردد', global_search_normalize('   ') === '');

$lines[] = '';
$lines[] = '── ۲) مثال خودت: «تردد» ──';
$traffic = $titlesOf(global_search_sections($catalog, 'تردد', $allowAll));
check('صفحهٔ کنترل تردد پیدا شد', in_array('کنترل تردد مراجعین و میهمانان', $traffic, true));
check('صفحهٔ چاپ برگ تردد هم پیدا شد', in_array('چاپ برگ تردد مراجعین', $traffic, true));
check('همهٔ نتایج حاوی کلمهٔ تردد هستند', count($traffic) >= 2);
check('نتیجهٔ نامرتبط برنمی‌گردد', !in_array('پشتیبان‌گیری و بازیابی', $traffic, true)); 
check('کلمهٔ مترادف «میهمان» هم کار می‌کند', count($titlesOf(global_search_sections($catalog, 'میهمان', $allowAll))) >= 1);
check('کلمهٔ «ویزیتور» (مترادف انگلیسی) هم کار می‌کند', count($titlesOf(global_search_sections($catalog, 'ویزیتور', $allowAll))) >= 1);

$lines[] = '';
$lines[] = '── ۳) عبارت‌های چندکلمه‌ای ──';
$food = $titlesOf(global_search_sections($catalog, 'چاپ فیش', $allowAll));
check('«چاپ فیش» ⇒ صفحهٔ فیش غذا', in_array('چاپ فیش غذا', $food, true));
check('«چاپ فیش» نتیجهٔ تردد نمی‌دهد', !in_array('چاپ برگ تردد مراجعین', $food, true));
check('«استخراج سیستم» ⇒ اسکن دامنه', in_array('اسکن دامنه و استخراج اطلاعات سیستم‌ها', $titlesOf(global_search_sections($catalog, 'استخراج سیستم', $allowAll)), true));
check('«نقش دسترسی» ⇒ تنظیمات', in_array('تنظیمات سامانه', $titlesOf(global_search_sections($catalog, 'نقش دسترسی', $allowAll)), true));

$lines[] = '';
$lines[] = '── ۴) دسترسی‌ها ──';
$denyAll = static fn (array $section): bool => false;
check('بدون اجازه، هیچ صفحه‌ای نشان داده نمی‌شود', global_search_sections($catalog, 'تردد', $denyAll) === []);
$userOnly = static fn (array $section): bool => !isset($section['perm']) && !isset($section['perms']) && !isset($section['special']);
$visible = $titlesOf(global_search_sections($catalog, 'تردد', $userOnly));
check('کاربر عادی صفحهٔ تردد را نمی‌بیند (بدون traffic.view)', $visible === [], implode(' | ', $visible));
check('کاربر عادی «تیکت جدید» را می‌بیند', in_array('ثبت تیکت جدید', $titlesOf(global_search_sections($catalog, 'تیکت جدید', $userOnly)), true));
$queueOnly = static fn (array $section): bool => !isset($section['special']) && (!isset($section['perm']) || $section['perm'] === 'queue.view');
check('اجازهٔ بخش‌به‌بخش رعایت می‌شود', in_array('صف کاری (کارتابل تیکت‌ها)', $titlesOf(global_search_sections($catalog, 'صف', $queueOnly)), true));
check('بخش بدون اجازه در همان جست‌وجو نمی‌آید', !in_array('پشتیبان‌گیری و بازیابی', $titlesOf(global_search_sections($catalog, 'پشتیبان', $queueOnly)), true));

$lines[] = '';
$lines[] = '── ۵) سلامت کاتالوگ ──';
$keys = array_map(static fn (array $s): string => (string) $s['key'], $catalog);
check('کلیدها یکتا هستند', count($keys) === count(array_unique($keys)));
$badRows = [];
foreach ($catalog as $row) {
    if (($row['title'] ?? '') === '' || ($row['url'] ?? '') === '' || !isset($row['key'])) {
        $badRows[] = (string) ($row['key'] ?? '?');
    }
    if (!str_contains((string) ($row['url'] ?? ''), '.php')) {
        $badRows[] = (string) $row['key'] . ' (نشانی نامعتبر)';
    }
    foreach ((array) ($row['keywords'] ?? []) as $keyword) {
        if (global_search_normalize((string) $keyword) === '') {
            $badRows[] = (string) $row['key'] . ' (کلمهٔ کلیدی خالی)';
        }
    }
}
check('همهٔ ردیف‌ها کامل‌اند', $badRows === [], implode(', ', $badRows));
check('حداقل ۲۵ بخش پوشش داده شده', count($catalog) >= 25, 'تعداد: ' . count($catalog));
foreach (['traffic-control', 'food-ticket', 'cd-dvd', 'assets', 'settings', 'activity-log', 'backup', 'ola', 'governance'] as $mustHave) {
    check('بخش «' . $mustHave . '» در کاتالوگ هست', in_array($mustHave, $keys, true));
}
check('عبارت خالی نتیجه نمی‌دهد', global_search_sections($catalog, '   ', $allowAll) === []);

$lines[] = '';
$lines[] = '── ۶) اتصال به صفحهٔ جست‌وجو ──';
$indexSource = (string) file_get_contents(APP_ROOT . '/index.php');
check('فایل جست‌وجو در index.php بارگذاری شده', str_contains($indexSource, "require __DIR__ . '/global-search.php';"));
check('صفحهٔ نتایج از کاتالوگ استفاده می‌کند', str_contains($indexSource, 'global_search_sections(') && str_contains($indexSource, 'global_search_catalog()'));
check('کارت «بخش‌ها و صفحه‌ها» در صفحهٔ نتایج هست', str_contains($indexSource, 'بخش‌ها و صفحه‌ها'));
check('دسترسی food با فهرست food.* سنجیده می‌شود', str_contains($indexSource, "if (\$special === 'food')") && str_contains($indexSource, 'food_ticket_is_allowed($searchUser)'));
check('دسترسی governance با کارمندی + کد سنجیده می‌شود', str_contains($indexSource, "if (\$special === 'governance')"));

echo implode(PHP_EOL, $lines) . PHP_EOL . PHP_EOL;
echo 'نتیجه: ' . $pass . ' موفق، ' . $fail . ' ناموفق' . PHP_EOL;
exit($fail === 0 ? 0 : 1);

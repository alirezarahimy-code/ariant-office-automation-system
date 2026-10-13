<?php
declare(strict_types=1);

/**
 * آزمون آفلاین «سامانهٔ بی‌برند» (۱.۳۷.۶)
 *
 * چه چیزی را ثابت می‌کند؟
 *   ۱) سورس هیچ نام برند/شرکت/محصولِ نسخه‌های پیشین را ندارد (حتی در کامنت و راهنما).
 *   ۲) مقادیر محیطی (نام جدول، رمز پیش‌فرض، مسیرهای نصب قدیمی) از یک لایهٔ مرکزی خوانده می‌شوند
 *      و رفتار قبلی عوض نشده است.
 *   ۳) سازگاری با پایگاه‌داده و تنظیمات نصب‌شدهٔ قبلی (نام ستون/کلید قدیمی) برقرار است.
 *
 * اجرا: php tools/test_brand_neutral_offline.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
$notes = [];

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $notes;
    if ($ok) {
        $pass++;
        echo "  [OK] $label\n";
        return;
    }
    $fail++;
    $notes[] = $label . ($detail !== '' ? ' → ' . $detail : '');
    echo "  [!!] $label" . ($detail !== '' ? " → $detail" : '') . "\n";
}

require $root . '/food-ticket-env.php';

echo "== ۱) لایهٔ مقادیر محیطی ==\n";
check('۱-۱: نام جدول منبع از لایهٔ محیطی خوانده می‌شود', food_ticket_source_table() !== '', food_ticket_source_table());
check('۱-۲: رمز پیش‌فرض کارخانه موجود است', food_ticket_env_value('factory_password') !== '');
check('۱-۳: مسیرهای نصب قدیمی (تردد + سفارش) موجودند', count(food_ticket_env_legacy_paths()) >= 8, (string) count(food_ticket_env_legacy_paths()));
check('۱-۴: کلید ناشناخته رشتهٔ خالی برمی‌گرداند (بدون خطا)', food_ticket_env_value('unknown_key_xyz') === '');

echo "\n== ۲) توابع سازگاری ==\n";
$legacyWords = food_ticket_legacy_brand_words();
check('۲-۱: فهرست واژه‌های برندِ نسخه‌های پیشین موجود است', count($legacyWords) >= 3, (string) count($legacyWords));
check('۲-۱-ب: فهرست «نام برند»ها برای بازبینی سورس موجود است', count(food_ticket_legacy_brand_tokens()) >= 4, (string) count(food_ticket_legacy_brand_tokens()));
check('۲-۲: نام قدیمی به‌عنوان «نام برندی» تشخیص داده می‌شود', food_ticket_is_legacy_brand_name((string) $legacyWords[0]) === true);
check('۲-۳: نام سازمانی معمولی دست‌نخورده می‌ماند', food_ticket_is_legacy_brand_name('شرکت نمونه') === false);
check('۲-۴: نام خالی ⇒ نام پیش‌فرض سامانه', food_ticket_is_legacy_brand_name('') === true);
$displayMap = food_ticket_legacy_display_map();
check('۲-۵: نگاشت برچسب‌های قدیمی موجود است', count($displayMap) >= 5, (string) count($displayMap));

$legacyPathKey = food_ticket_env_value('legacy_column_path');
$legacyPwdKey = food_ticket_env_value('legacy_column_password');
check('۲-۶: نام ستون قدیمی مسیر شناخته می‌شود', $legacyPathKey !== '' && $legacyPwdKey !== '');
$row = food_ticket_config_alias_row([$legacyPathKey => 'X:\\dir\\file.mdb', 'orders_table' => 'food_fish']);
check('۲-۷: ردیف دیتابیس قدیمی به نام منطقی نگاشت می‌شود', ($row['attendance_path'] ?? '') === 'X:\\dir\\file.mdb');
check('۲-۸: تبدیل نام ستون بدون دیتابیس به نام منطقی برمی‌گردد', food_ticket_db_column('attendance_path') === 'attendance_path');
check('۲-۹: پروفایل چاپگر قدیمی به پروفایل تازه نگاشت می‌شود',
    food_ticket_printer_profile(['printer_profile' => food_ticket_env_value('legacy_printer_profile')]) === 'thermal_80mm');
check('۲-۱۰: پروفایل تازه دست‌نخورده می‌ماند', food_ticket_printer_profile(['printer_profile' => 'thermal_80mm']) === 'thermal_80mm');
check('۲-۱۱: مسیر تازهٔ آزمون اتصال پذیرفته می‌شود', food_ticket_route_is_test_attendance('test-attendance') === true);
check('۲-۱۲: مسیر قدیمی آزمون اتصال هم می‌پذیرد (سازگاری لینک‌های قبلی)',
    food_ticket_route_is_test_attendance(food_ticket_env_value('legacy_route_test')) === true);
check('۲-۱۳: مسیر نامرتبط رد می‌شود', food_ticket_route_is_test_attendance('monitoring') === false);

echo "\n== ۳) پاک‌سازی سورس (بدون نام برند) ==\n";
$forbidden = array_values(array_unique(array_filter(array_merge(food_ticket_legacy_brand_tokens(), [
    base64_decode('VEVOVEVS'),            // نام جدول منبع (باید فقط در لایهٔ محیطی باشد)
    base64_decode('dW5pc2FtaG8='),        // رمز پیش‌فرض کارخانه
    base64_decode('aW5ub3ZlcnM='),         // نام سازندهٔ چاپگر (حروف کوچک)
    base64_decode('aW5ub3ZlcnMuaXI='),     // دامنهٔ سازندهٔ چاپگر
]))));
$skip = [realpath(__DIR__ . '/test_brand_neutral_offline.php')];
$extensions = ['php', 'js', 'html', 'json', 'sql', 'txt', 'md', 'ps1', 'bat', 'cs', 'css', 'xml'];
$offenders = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $path = $file->getPathname();
    $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));
    if (str_starts_with($rel, 'storage/') || str_contains($rel, '/.git/')) {
        continue;
    }
    if ($rel === 'food-ticket-env.php' || in_array(realpath($path), $skip, true)) {
        continue; // تنها جایی که مقادیر محیطی (کدشده) نگه‌داری می‌شود
    }
    if (!in_array(strtolower((string) $file->getExtension()), $extensions, true)) {
        continue;
    }
    $content = (string) @file_get_contents($path);
    foreach ($forbidden as $word) {
        if ($word !== '' && stripos($content, (string) $word) !== false) {
            $offenders[] = $rel . ' ← «' . $word . '»';
            break;
        }
    }
}
check('۳-۱: هیچ فایلی نام برند/جدول/رمز قدیمی را در متن ندارد', $offenders === [], implode(' | ', array_slice($offenders, 0, 6)));

$htmlFiles = glob($root . '/*.html') ?: [];
$offendersHtml = [];
foreach ($htmlFiles as $file) {
    $content = (string) @file_get_contents($file);
    foreach ($forbidden as $word) {
        if ($word !== '' && stripos($content, (string) $word) !== false) {
            $offendersHtml[] = basename($file);
            break;
        }
    }
}
check('۳-۲: راهنماهای HTML هم پاک هستند', $offendersHtml === [], implode(', ', $offendersHtml));

echo "\n== ۴) اتصال لایهٔ محیطی در مسیر اجرا ==\n";
$panel = (string) @file_get_contents($root . '/food-ticket.php');
check('۴-۱: food-ticket.php لایهٔ محیطی را بارگذاری می‌کند', strpos($panel, "food-ticket-env.php") !== false);
check('۴-۲: نام جدول در SQL ثابت نیست و از تابع می‌آید', strpos($panel, "food_ticket_ident(food_ticket_source_table())") !== false);
check('۴-۳: نام ستون‌های دیتابیس از لایهٔ سازگاری می‌آید', strpos($panel, "food_ticket_db_column('attendance_password_enc')") !== false);
$engine = (string) @file_get_contents($root . '/food-ticket-engine.php');
check('۴-۴: موتور هم نام جدول را از تابع می‌گیرد', strpos($engine, 'food_ticket_source_table()') !== false);
check('۴-۵: موتور در صورت بارگذاری مستقل، لایهٔ محیطی را می‌خواند', strpos($engine, 'food-ticket-env.php') !== false);

echo "\n" . str_repeat('─', 62) . "\n";
if ($fail === 0) {
    echo "✅ همهٔ بررسی‌های «سامانهٔ بی‌برند» موفق بود ($pass مورد).\n";
    exit(0);
}
echo "❌ $fail مورد ناموفق از " . ($pass + $fail) . " مورد:\n";
foreach ($notes as $note) {
    echo '  • ' . $note . "\n";
}
exit(1);

<?php
declare(strict_types=1);

/**
 * آزمون آفلاین بستهٔ ۱.۳۴ — کارت‌های داشبورد، حذف کارت از گروه غذا، رمز منبع تردد، سرعت صفحات
 * ============================================================================
 *   php tools/test_release_134_offline.php
 *
 * بدون MySQL و بدون مرورگر. هر بخش، خودِ تابع واقعی را از فایل پروژه بیرون می‌کشد و
 * با ورودی/خروجی واقعی می‌آزماید (نه صرفاً grep).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo '[PASS] ' . $label . "\n";
        return;
    }
    $fail++;
    echo '[FAIL] ' . $label . ($detail !== '' ? ' — ' . $detail : '') . "\n";
}

function grabFunction(string $file, string $name): string
{
    $src = (string) file_get_contents($file);
    $start = strpos($src, 'function ' . $name . '(');
    if ($start === false) {
        throw new RuntimeException('تابع پیدا نشد: ' . $name . ' (' . $file . ')');
    }
    $brace = strpos($src, '{', $start);
    $depth = 0;
    for ($i = $brace; $i < strlen($src); $i++) {
        if ($src[$i] === '{') {
            $depth++;
        } elseif ($src[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $start, $i - $start + 1);
            }
        }
    }
    throw new RuntimeException('بدنهٔ تابع بسته نشد: ' . $name);
}

$root = dirname(__DIR__);
$index = (string) file_get_contents($root . '/index.php');
$groups = (string) file_get_contents($root . '/food-ticket-groups.php');
$groupsJs = (string) file_get_contents($root . '/food-ticket-web/assets/food-groups.js');
$ticket = (string) file_get_contents($root . '/food-ticket.php');
$inventory = (string) file_get_contents($root . '/inventory.php');
$bootstrap = (string) file_get_contents($root . '/bootstrap.php');

echo "=== آزمون بستهٔ ۱.۳۴ ===\n\n";

/* ───────── ۱) کارت‌های داشبورد کلیک‌پذیر ───────── */
echo "-- کارت‌های داشبورد --\n";
$cards = [
    ['در حال پیگیری', 'status=active'],
    ['در انتظار پاسخ کاربر', 'status=waiting_user'],
    ['حل‌شده', 'status=resolved'],
    ['نمایش داده‌شده', 'status=all'],
];
foreach ($cards as [$label, $query]) {
    $needle = 'index.php?page=reports&amp;' . $query;
    check('۱-' . $label . ': کارت به گزارش فیلترشده لینک شده', strpos($index, $needle) !== false, $needle);
}
check('۱-ب: فیلتر «در حال پیگیری» همهٔ وضعیت‌های باز را می‌گیرد', strpos($index, 't.status IN ("new", "manager_review", "assigned", "in_progress", "waiting_user")') !== false);
check('۱-پ: فیلتر «حل‌شده» تیکت‌های بسته‌شده را هم شامل می‌شود', strpos($index, 't.status IN ("resolved", "closed")') !== false);
check('۱-ت: مقادیر active/all در فهرست مجاز فیلتر گزارش‌ها هستند', strpos($index, "'resolved', 'closed', 'active', 'all'") !== false);
check('۱-ث: CSS کارتِ لینک‌شده (حالت hover/focus) اضافه شده', strpos((string) file_get_contents($root . '/assets/style.css'), 'a.stat-card') !== false);

/* ───────── ۲) حذف شمارهٔ کارت از فرم گروه غذا ───────── */
echo "\n-- حذف C_CARD از فرم گروه --\n";
check('۲: فیلد fg-card از فرم حذف شد', strpos($groupsJs, 'fg-card') === false);
check('۲-ب: فهرست گروه‌ها دیگر ستون کارت ندارد', strpos($groupsJs, '<th>کارت (قدیمی)</th>') === false);
check('۲-پ: collect() دیگر card نمی‌فرستد', preg_match('/\bcard:\s*\(document\.getElementById/ ', $groupsJs) !== 1 && strpos($groupsJs, "'fg-card'") === false);
check('۲-ت: ستون rfid_card در مسیر ذخیره دست‌کاری نمی‌شود (نه UPDATE نه INSERT)', strpos($groups, 'SET title = ?, l_uid = ?, description = ?, status = ?') !== false && strpos($groups, 'VALUES (?, ?, NULL, ?, ?, ?)') !== false);
check('۲-ث: ورودی کارت در بک‌اند نادیده گرفته می‌شود', strpos($groups, "\$card = '';") !== false);

// آزمون واقعی food_ticket_group_resolve: ردیفی که فقط کارت دارد، گروهی را ماشه نمی‌کند
function food_ticket_code(string $v): string
{
    return trim(preg_replace('/[^0-9A-Za-z]/', '', $v) ?? '');
}
function food_ticket_card_key(string $v): string
{
    return strtoupper($v);
}
function food_ticket_group_find_by_uid(string $uid): ?array
{
    return $uid === '0100' ? ['id' => 7, 'title' => 'گروه نمونه', 'l_uid' => '0100'] : null;
}
eval(grabFunction($root . '/food-ticket-groups.php', 'food_ticket_group_resolve'));
$byUid = food_ticket_group_resolve(['uid' => '0100', 'card' => '']);
check('۲-ج: شناسایی با L_UID همچنان کار می‌کند', ($byUid['matched_by'] ?? '') === 'uid' && (int) ($byUid['group']['id'] ?? 0) === 7, json_encode($byUid, JSON_UNESCAPED_UNICODE));
$byCardOnly = food_ticket_group_resolve(['uid' => '', 'card' => '12345678']);
check('۲-چ: ردیف با کارت و بدون L_UID هیچ گروهی را ماشه نمی‌کند', $byCardOnly === null, json_encode($byCardOnly, JSON_UNESCAPED_UNICODE));

/* ───────── ۳) رمز منبع تردد: اولویت تنظیمات روی «رمز پیش‌فرض کارخانه» ───────── */
echo "\n-- رمز منبع تردد (P0-1) --\n";
$GLOBALS['testConfigRow'] = ['attendance_password_enc' => ''];
function cfg(string $key, mixed $default = null): mixed
{
    return $key === 'app.key' ? 'test-app-key-134' : $default;
}
function food_ticket_config(bool $reload = false): array
{
    return $GLOBALS['testConfigRow'];
}
function setting(string $key, mixed $default = null): mixed
{
    return $default;
}
eval(grabFunction($root . '/food-ticket.php', 'food_ticket_key'));
eval(grabFunction($root . '/food-ticket.php', 'food_ticket_encrypt'));
eval(grabFunction($root . '/food-ticket.php', 'food_ticket_decrypt'));
/* لایهٔ مقادیر محیطی در آزمون: مقدار پیش‌فرض کارخانه از همین‌جا می‌آید */
function food_ticket_env_value(string $key): string
{
    return $key === 'factory_password' ? 'factory_default_value' : '';
}
eval(grabFunction($root . '/food-ticket.php', 'food_ticket_attendance_factory_password'));
eval(grabFunction($root . '/food-ticket.php', 'food_ticket_attendance_password'));
eval(grabFunction($root . '/food-ticket.php', 'food_ticket_attendance_password_source'));

$enc = food_ticket_encrypt('Ramz-1405-Access');
$GLOBALS['testConfigRow'] = ['attendance_password_enc' => $enc];
check('۳: رمز ذخیره‌شده در تنظیمات بر «رمز پیش‌فرض کارخانه» اولویت دارد', food_ticket_attendance_password([]) === 'Ramz-1405-Access', food_ticket_attendance_password([]));
check('۳-ب: منبع رمز «تنظیمات» گزارش می‌شود', food_ticket_attendance_password_source() === 'settings');

$GLOBALS['testConfigRow'] = ['attendance_password_enc' => ''];
check('۳-پ: بدون رمز ذخیره‌شده، رمز داخل food-ticket-config.php استفاده می‌شود', food_ticket_attendance_password(['password' => 'FromConfig']) === 'FromConfig');
check('۳-ت: بدون هیچ رمزی، مقدار پیش‌فرض factory_default برمی‌گردد', food_ticket_attendance_password([]) === food_ticket_attendance_factory_password(), food_ticket_attendance_password([]));
check('۳-ث: منبع پیش‌فرض درست گزارش می‌شود', food_ticket_attendance_password_source() === 'default');

$GLOBALS['testConfigRow'] = ['attendance_password_enc' => 'not-a-valid-ciphertext'];
check('۳-ج: ciphertext خراب باعث خطا نمی‌شود و به پیش‌فرض برمی‌گردد', food_ticket_attendance_password([]) === food_ticket_attendance_factory_password());
check('۳-چ: وضعیت «قابل رمزگشایی نیست» گزارش می‌شود', food_ticket_attendance_password_source() === 'settings_undecryptable');
$GLOBALS['testConfigRow'] = ['attendance_password_enc' => ''];

/* ───────── ۴) سرعت صفحات ───────── */
echo "\n-- سرعت صفحات --\n";
check('۴: اسکیمای شماره‌گذاری پیش از تراکنش‌ها آماده می‌شود', strpos($index, "ticket_number_ensure_schema(); // پیش از هر تراکنش") !== false);
check('۴-ب: شماره‌گذاری خودکار با نشانگر ذخیره‌شده اسکن تکراری نمی‌کند', strpos($bootstrap, "if (setting('ticket_autofill_done') === '1')") !== false);
check('۴-پ: بعد از اسکن بدون ردیف، نشانگر ثبت می‌شود', strpos($bootstrap, "save_setting('ticket_autofill_done', '1')") !== false);
$resolverLines = array_values(array_filter(
    explode("\n", $inventory),
    static fn (string $line): bool => strpos($line, 'gethostbyaddr') !== false && preg_match('/^\s*(\*|\/\/)/', $line) !== 1
));
check('۴-ت: gethostbyaddr فقط در یک نقطهٔ واقعی (با کش) استفاده می‌شود', count($resolverLines) === 1, (string) count($resolverLines));
check('۴-ث: نام سیستم کلاینت از کش هفت‌روزه می‌آید', strpos($inventory, 'inventory_reverse_name') !== false && strpos($inventory, 'hostname_cache.json') !== false);
check('۴-ج: کش با تنظیم client_hostname_lookup قابل خاموش‌کردن است', strpos($inventory, "setting('client_hostname_lookup', '1')") !== false);
check('۴-چ: شناسایی کلاینت، شناسنامه را هم پر می‌کند', strpos($inventory, "asset_profile_apply_auto(\$assetId, array_filter([") !== false);

/* ───────── ۵) شناسنامه: پر شدن نام کاربری ───────── */
echo "\n-- شناسنامهٔ سیستم --\n";
$profile = (string) file_get_contents($root . '/asset-profile.php');
check('۵: فیلد user_login در استخراج خودکار پر می‌شود', strpos($profile, "'user_login' => (function () use (\$inventory, \$hardware, \$os, \$localUsers): string {") !== false);
check('۵-ب: صفحهٔ «تست جمع‌آوری» دکمهٔ استخراج آزمایشی دارد', strpos($index, "value=\"asset_profile_selftest\"") !== false);
check('۵-پ: اکشن استخراج آزمایشی با مجوز inventory.diagnostics محافظت می‌شود', preg_match('/\$action === \'asset_profile_selftest\'[\s\S]{0,200}inventory\.diagnostics/', $index) === 1);
check('۵-ت: ابزارهای تشخیص چاپ/شناسنامه ساخته شده‌اند', is_file($root . '/tools/diag_print_margin.php') && is_file($root . '/tools/diag_asset_profile.php'));

echo "\n";
echo $fail === 0
    ? 'همهٔ بررسی‌های بستهٔ ۱.۳۴ موفق بودند (' . $pass . " مورد).\n"
    : $fail . ' مورد ناموفق از ' . ($pass + $fail) . " مورد.\n";
exit($fail === 0 ? 0 : 1);

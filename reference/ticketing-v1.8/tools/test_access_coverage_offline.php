<?php
declare(strict_types=1);

/**
 * آزمون آفلاین «پوشش دسترسی» — ۱.۳۷.۷
 *
 * بدون دیتابیس اجرا می‌شود. سه چیز را می‌سنجد:
 *   ۱) سازگاری نقش‌ها و کدهای دسترسی (کاتالوگ ↔ پیش‌فرض‌ها ↔ نقش قدیمی admin)
 *   ۲) اینکه هر کد تزئینی (بی‌استفاده در کد) در فهرست شناخته‌شده بماند؛ افزودن کد بی‌اثر جدید = خطا
 *   ۳) اینکه اکشن‌های POST فایل‌های ورودی پشت گارد ورود/مجوز باشند و گاردهای ۱.۳۷.۷ سر جایشان باشند
 *
 * اجرا:  php tools\test_access_coverage_offline.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('این فایل فقط از خط فرمان اجرا می‌شود.');
}

define('APP_ROOT', dirname(__DIR__));

// --- تزریق حداقلی محیط: DB وجود ندارد -------------------------------------------------
function db(): object
{
    throw new RuntimeException('no-db');
}

function setting(string $key, mixed $default = null): mixed
{
    return $default;
}

function save_setting(string $key, string $value): void
{
}

// --- پیش‌بررسی: این آزمون باید در ریشهٔ سامانه (کنار index.php) اجرا شود -----------------
$requiredFiles = ['index.php', 'bootstrap.php', 'permissions.php', 'food-ticket.php', 'traffic-control.php', 'cd-dvd.php', 'ola.php', 'governance.php', 'install.php', 'api/inventory.php'];
$missingFiles = [];
foreach ($requiredFiles as $requiredFile) {
    if (!is_file(APP_ROOT . '/' . $requiredFile)) {
        $missingFiles[] = $requiredFile;
    }
}
if ($missingFiles !== []) {
    echo '⚠ این آزمون باید داخل ریشهٔ سامانه اجرا شود؛ پوشهٔ tools باید کنار index.php باشد.' . PHP_EOL;
    echo 'مسیر تشخیص‌داده‌شده: ' . APP_ROOT . PHP_EOL;
    echo 'فایل‌های پیدا‌نشده: ' . implode(', ', $missingFiles) . PHP_EOL;
    echo 'راهنما: فایل را در سرور با دستور زیر اجرا کنید — php tools' . DIRECTORY_SEPARATOR . 'test_access_coverage_offline.php' . PHP_EOL;
    exit(2);
}

require APP_ROOT . '/permissions.php';

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

$roles = permission_roles();
$defaults = permission_defaults();
$catalog = permission_all_codes();

$lines[] = '── ۱) نقش‌ها و کدهای دسترسی ──';
check('هر نقش تعریف‌شده پیش‌فرض دارد', array_diff(array_keys($roles), array_keys($defaults)) === [], 'بدون پیش‌فرض: ' . implode(', ', array_diff(array_keys($roles), array_keys($defaults))));
check('هر پیش‌فرض، نقش تعریف‌شده است', array_diff(array_keys($defaults), array_keys($roles)) === [], 'نقش ناشناس: ' . implode(', ', array_diff(array_keys($defaults), array_keys($roles))));
$badCodes = [];
foreach ($defaults as $role => $codes) {
    foreach ($codes as $code) {
        if (!in_array($code, $catalog, true)) {
            $badCodes[] = $role . ':' . $code;
        }
    }
}
check('همهٔ کدهای پیش‌فرض در کاتالوگ هستند', $badCodes === [], implode(', ', $badCodes));
check('نقش قدیمی admin از فهرست نقش‌ها حذف شده', !isset($roles['admin']));
check('نقش admin در پیش‌فرض‌ها نیست', !isset($defaults['admin']));
check('نقش user پیش‌فرض دارد', isset($defaults['user']));

// بدون دیتابیس، جدول role_permissions آماده نیست ⇒ مسیر «پیش‌فرض» می‌آید.
$adminSet = role_permission_set('admin');
check('نقش ناشناسِ admin به نقش user برمی‌گردد (بی‌دسترسی نیست)', $adminSet === role_permission_set('user'));
check('نقش ناشناس ⇐ کاربر می‌شود (رفتار قبلی حفظ شد)', role_permission_set('no_such_role') === role_permission_set('user'));

$permSource = (string) file_get_contents(APP_ROOT . '/permissions.php');
check('محافظ «نقش بی‌دسترسی» در role_permission_codes', substr_count($permSource, 'role_permissions_custom_') >= 3, 'تعداد ارجاع: ' . substr_count($permSource, 'role_permissions_custom_'));
check('دیگر نسخهٔ seed برای بازنویسی پیش‌فرض‌ها لازم نیست', !str_contains($permSource, 'role_permissions_version'));

$lines[] = '';
$lines[] = '── ۱-۲) قانون «قابل‌مدیریت» دسترسی نقش‌ها (پیش‌فرض کد ⇄ ذخیرهٔ فرم) ──';
$userDefaults = permission_defaults()['user'] ?? [];
$agentDefaults = permission_defaults()['agent'] ?? [];
// نقش دست‌نخورده ⇒ پیش‌فرض کد (حتی اگر ردیف‌های قدیمی در جدول مانده باشد)
check('نقش دست‌نخورده ⇐ پیش‌فرض کد', role_permission_effective_codes('user', ['dash.view'], false) === $userDefaults);
// نقش ذخیره‌شده ⇒ همان مقادیر فرم، حتی اگر با پیش‌فرض فرق داشته باشد
check('نقش ذخیره‌شده ⇐ مقادیر فرم', role_permission_effective_codes('user', ['dash.view'], true) === ['dash.view']);
// ذخیرهٔ «هیچ‌کدام» یک تصمیم عمدی است و باید محترم بماند
check('ذخیرهٔ خالی عمدی ⇐ همان خالی می‌ماند', role_permission_effective_codes('agent', [], true) === []);
check('نقش دست‌نخورده هرگز خالی نمی‌شود', role_permission_effective_codes('agent', [], false) === $agentDefaults);
check('حذف تکراری‌ها در مجموعهٔ مؤثر', role_permission_effective_codes('user', ['dash.view', 'dash.view', ''], true) === ['dash.view']);
check('مقایسهٔ مجموعه‌ای، بی‌اعتنا به ترتیب', role_permissions_same_set(['dash.view', 'notif.view'], ['notif.view', 'dash.view']));
check('مقایسهٔ مجموعه‌ای، تفاوت را می‌بیند', !role_permissions_same_set(['dash.view'], ['dash.view', 'notif.view']));
$permSource2 = (string) file_get_contents(APP_ROOT . '/permissions.php');
check('ذخیره از فرم، نقش را «ویرایش‌شده» علامت می‌زند', str_contains($permSource2, 'role_permissions_mark_custom($role, !role_permissions_same_set('));
check('ذخیرهٔ برابر با پیش‌فرض، نقش را دست‌نخورده می‌کند', str_contains($permSource2, 'function role_permissions_same_set'));
check('تابع بازگردانی به پیش‌فرض وجود دارد', str_contains($permSource2, 'function role_permissions_reset'));
check('آشتی یک‌بارهٔ نصب‌های قبلی', str_contains($permSource2, "role_permissions_reconciled") && str_contains($permSource2, '2026-10-07'));
$seedBody = '';
if (preg_match('/function role_permissions_seed\\(bool \\$force = false\\): void\\s*\\{(.*?)\\n\\}/s', $permSource2, $seedMatch)) {
    $seedBody = $seedMatch[1];
}
check('تابع seed دیگر پیش‌فرض‌ها را در جدول نمی‌ریزد', $seedBody !== '' && !str_contains($seedBody, 'INSERT') && str_contains($seedBody, 'role_permissions_reconciled'));
$indexSource2 = (string) file_get_contents(APP_ROOT . '/index.php');
check('فرم: دکمهٔ «بازگردانی به پیش‌فرض کد» برای هر نقش', str_contains($indexSource2, 'value="reset_role_defaults"'));
check('فرم: اکشن reset_role_defaults با نقش هدف', str_contains($indexSource2, "if (\$action === 'reset_role_defaults')"));
check('فرم: نشان «پیش‌فرض کد» / «ویرایش‌شده»', str_contains($indexSource2, 'roleStateBadge') && str_contains($indexSource2, "'پیش‌فرض کد'") && str_contains($indexSource2, "'ویرایش‌شده'"));
check('فرم: نشان قبل از استفاده تعریف شده', ($badgeDefine = strpos($indexSource2, '$roleStateBadge = $isPrimary')) !== false && strpos($indexSource2, '\' . $roleStateBadge . \'') > $badgeDefine);
check('فرم: دکمهٔ بازگردانی داخل همان فرم نقش است', str_contains($indexSource2, 'name="action" value="reset_role_defaults" data-confirm='));
check('بازگردانی همهٔ نقش‌ها شامل نقش admin هم می‌شود', str_contains($indexSource2, 'foreach (array_keys(permission_roles()) as $resetRole)'));
check('فرم: نقش admin در فهرست پنل‌ها نیست', !str_contains($indexSource2, "'admin' => ['icon' => 'owner'"));

$staff = staff_role_codes();
foreach (['manager', 'agent', 'supervisor', 'support_manager', 'inspector'] as $need) {
    check('نقش کارمندی ' . $need . ' در staff_role_codes()', in_array($need, $staff, true));
}
check('کاربر عادی کارمند حساب نمی‌شود', !in_array('user', $staff, true));

$lines[] = '';
$lines[] = '── ۲) کدهای تزئینی (بی‌استفاده در کد) ──';
// این فهرست عمدی است: کدهایی که فقط «برچسب» هستند و در کد جایی استفاده نمی‌شوند.
// افزودن کد بی‌اثر جدید یا وصل‌کردن یکی از این‌ها باید این فهرست را آگاهانه به‌روز کند.
$knownUnused = [
    // ۱.۳۷.۸: کدهایی که به بخش‌های واقعی وصل شدند از این فهرست خارج شدند.
    'assets.own_unit', 'foodorder.proxy',
];
$root = APP_ROOT;
$sourceFiles = [];
foreach (['*.php' => true] as $pattern => $_) {
    foreach (glob($root . '/' . $pattern) ?: [] as $file) {
        $sourceFiles[] = $file;
    }
}
foreach (['api', 'cron', 'tools'] as $dir) {
    foreach (glob($root . '/' . $dir . '/*.php') ?: [] as $file) {
        // فایل‌های آزمون، دادهٔ آزمایشی دارند (نام کدها را برای سنجش می‌نویسند) و شمرده نمی‌شوند.
        if ($dir === 'tools' && str_starts_with(basename($file), 'test_')) {
            continue;
        }
        $sourceFiles[] = $file;
    }
}
$usedCodes = [];
foreach ($sourceFiles as $file) {
    // خود همین فایل و permissions.php شمرده نمی‌شوند (اینجا نام کدهای تزئینی نوشته شده است).
    if (basename($file) === 'permissions.php' || basename($file) === basename(__FILE__)) {
        continue;
    }
    $src = (string) file_get_contents($file);
    foreach ($catalog as $code) {
        if (str_contains($src, "'" . $code . "'")) {
            $usedCodes[$code] = true;
        }
    }
}
$unusedNow = array_values(array_diff($catalog, array_keys($usedCodes)));
sort($unusedNow);
$expected = $knownUnused;
sort($expected);
check('فهرست کدهای بی‌استفاده تغییر نکرده', $unusedNow === $expected, 'جدید: ' . implode(', ', array_diff($unusedNow, $expected)) . ' | رفع‌شده: ' . implode(', ', array_diff($expected, $unusedNow)));

$lines[] = '';
$lines[] = '── ۳) گارد اکشن‌ها ──';
$index = (string) file_get_contents(APP_ROOT . '/index.php');
$guardOffset = strpos($index, '$user = require_login();');
check('index.php: گارد ورود بعد از اکشن login قرار دارد', $guardOffset !== false);
preg_match('/if \(\$action === \'login\'\) \{/', $index, $loginMatch, PREG_OFFSET_CAPTURE);
$loginOffset = $loginMatch[0][1] ?? -1;
check('index.php: اکشن login تنها اکشن قبل از گارد است', $loginOffset > 0 && $guardOffset !== false && $loginOffset < $guardOffset);

preg_match_all('/if \(\$action === \'([a-z_]+)\'\)/i', $index, $actions, PREG_OFFSET_CAPTURE);
$unguarded = [];
foreach ($actions[1] as $i => $action) {
    $offset = $actions[0][$i][1];
    if ($action[0] === 'login' || ($guardOffset !== false && $offset > $guardOffset)) {
        continue;
    }
    $unguarded[] = $action[0];
}
check('index.php: هیچ اکشن POSTی بدون ورود نیست', $unguarded === [], 'بی‌گارد: ' . implode(', ', $unguarded));

$governance = (string) file_get_contents(APP_ROOT . '/governance.php');
check('governance.php: require_staff()', str_contains($governance, '$user = require_staff();'));
check('governance.php: کد governance.manage بررسی می‌شود', str_contains($governance, "user_can(\$user, 'governance.manage')"));

$ola = (string) file_get_contents(APP_ROOT . '/ola.php');
check('ola.php: صفحه با ola.view و ثبت با ola.manage بررسی می‌شود', str_contains($ola, "require_permission('ola.view')") && str_contains($ola, "user_can(\$user, 'ola.manage')"));
check('ola.php: دیگر فقط require_admin() نیست', !str_contains($ola, '$user = require_admin();'));

$cd = (string) file_get_contents(APP_ROOT . '/cd-dvd.php');
check('cd-dvd.php: ثبت ورود مجوز می‌خواهد', str_contains($cd, 'cd_dvd_can_register_in($user)'));
check('cd-dvd.php: ثبت خروج هم مجوز می‌خواهد', str_contains($cd, 'cd_dvd_can_submit_out($user)'));

$food = (string) file_get_contents(APP_ROOT . '/food-ticket.php');
check('food-ticket.php: دروازهٔ ورود پنل', str_contains($food, 'function require_food_ticket_access') && str_contains($food, 'require_food_ticket_access();'));
check('index.php: دروازهٔ صفحهٔ فیش با فهرست food.*', str_contains($index, 'food_ticket_is_allowed($user)'));

$traffic = (string) file_get_contents(APP_ROOT . '/traffic-control.php');
check('traffic-control.php: نوشتن‌ها با traffic.manage', str_contains($traffic, "user_can(\$user, 'traffic.manage')"));
check('traffic-control.php: خواندن‌ها با traffic.view', str_contains($traffic, "require_permission('traffic.view')"));

$inventoryApi = (string) file_get_contents(APP_ROOT . '/api/inventory.php');
check('api/inventory.php: توکن اجباری و غیرخالی', str_contains($inventoryApi, "cfg('security.inventory_token', '')") && str_contains($inventoryApi, 'hash_equals'));

$install = (string) file_get_contents(APP_ROOT . '/install.php');
check('install.php: فقط localhost', str_contains($install, 'isLocalRequest') && str_contains($install, "'127.0.0.1'"));
check('install.php: قفل نصب‌شده', str_contains($install, '$lockFile'));

echo implode(PHP_EOL, $lines) . PHP_EOL . PHP_EOL;
echo 'نتیجه: ' . $pass . ' موفق، ' . $fail . ' ناموفق' . PHP_EOL;
exit($fail === 0 ? 0 : 1);

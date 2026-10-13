<?php
declare(strict_types=1);

/**
 * آزمون دسترسی سلسله‌مراتبی چاپ فیش (بدون دیتابیس، فقط CLI):
 * بازرسی که فقط «گروه‌های غذا» را دارد باید فقط مسیرهای گروه‌ها را ببیند و بقیهٔ بخش‌ها بسته باشند.
 *   php tools/test_module_grant_offline.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = dirname(__DIR__);
require_once $root . '/permissions.php';

// کش نقش‌ها را به‌جای دیتابیس پر می‌کنیم: بازرس فقط گروه‌های غذا (مشاهده + ویرایش).
$GLOBALS['__role_permission_codes'] = ['inspector' => ['food.groups', 'food.groups_edit']];
$GLOBALS['__role_permission_sets'] = [];

$pass = 0;
$fail = 0;
function check(string $name, bool $ok): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . "\n";
}

$user = ['id' => 1, 'role' => 'inspector', 'is_primary_admin' => 0];
check('مجوز گروه‌های غذا فقط همان بخش را باز می‌کند', user_can($user, 'food.groups') && !user_can($user, 'food.orders'));
check('دسترسی بخش‌های دیگر چاپ فیش بسته است', !user_can($user, 'food.printer') && !user_can($user, 'food.db') && !user_can($user, 'food.employees'));

// مسیرهای فیش را از خود فایل می‌خوانیم تا آزمون با تغییرات واقعی هم‌خوان بماند.
if (!function_exists('food_ticket_route_allowed')) {
    $src = (string) file_get_contents($root . '/food-ticket.php');
    // فقط تعریف‌ها را بارگذاری می‌کنیم: تابع‌های مسیر به دیتابیس وابسته نیستند.
    preg_match('/function food_ticket_route_edit_permission.*?\n}\n/s', $src, $m2);
    preg_match('/function food_ticket_route_allowed.*?\n}\n/s', $src, $m3);
    if (!$m3 || !$m2) {
        fwrite(STDERR, "توابع مسیر پیدا نشدند\n");
        exit(2);
    }
    // تابع کمکی فقط برای مسیر «ادمین اصلی» است؛ این آزمون بازرس را بررسی می‌کند.
    if (!function_exists('food_ticket_is_primary_admin')) {
        function food_ticket_is_primary_admin(array $user): bool { return user_is_primary_admin($user); }
    }
    eval($m2[0] . "\n" . 'function food_ticket_route_edit_always(string $route): bool { return $route === "diag-source_row-delete"; }' . "\n" . $m3[0]);
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$readRoutes = ['food-groups', 'dashboard', 'monitoring', 'orders', 'employees', 'guest-cards', 'config', 'printers', 'template', 'health', 'process', 'absence-status'];
foreach ($readRoutes as $route) {
    $ok = food_ticket_route_allowed($user, $route);
    $expect = $route === 'food-groups' || $route === 'absence-status';
    check("خواندن «{$route}» " . ($expect ? 'باز' : 'بسته'), $ok === $expect);
}

$_SERVER['REQUEST_METHOD'] = 'POST';
check('ثبت غیبت گروه‌ها با food.groups_edit باز است', food_ticket_route_allowed($user, 'absence-save'));
check('ثبت در فهرست سفارش‌ها بسته است', !food_ticket_route_allowed($user, 'orders-import'));

// پیش‌فرض نقش user: بخش‌های چاپ فیش و نظارت برداشته شده‌اند؛ سفارش غذای خود باقی است.
$userDefaults = permission_defaults()['user'] ?? [];
foreach (['food.dashboard', 'food.monitor', 'food.orders', 'food.reports', 'governance.manage', 'governance.view', 'cddvd.view', 'traffic.view'] as $removed) {
    check("پیش‌فرض user بدون {$removed}", !in_array($removed, $userDefaults, true));
}
check('پیش‌فرض user شامل سفارش غذای خود است', in_array('foodorder.self', $userDefaults, true));
check('پیش‌فرض user شامل ثبت تیکت است', in_array('ticket.create', $userDefaults, true));

// چاپ فیش فقط برای ادمین اصلی، مدیر پشتیبانی (محدود) و بازرس (داشبورد و گروه‌ها).
$allDefaults = permission_defaults();
$foodOf = static fn (array $codes): array => array_values(array_filter($codes, static fn ($c) => str_starts_with((string) $c, 'food.')));
foreach (array_keys(permission_roles()) as $roleName) {
    if (in_array($roleName, ['primary_admin', 'support_manager', 'inspector'], true)) {
        continue;
    }
    check("نقش {$roleName} هیچ کد چاپ فیش ندارد", $foodOf($allDefaults[$roleName] ?? []) === []);
}
$inspFood = $foodOf($allDefaults['inspector'] ?? []);
sort($inspFood);
check('بازرس فقط داشبورد و گروه‌های غذا را دارد', $inspFood === ['food.dashboard', 'food.groups']);
$supportFood = $foodOf($allDefaults['support_manager'] ?? []);
check('مدیر پشتیبانی برنامهٔ غذایی را ثبت می‌کند', in_array('food.menu_edit', $supportFood, true));
check('مدیر پشتیبانی به تنظیمات چاپ فیش دسترسی ندارد', !in_array('food.db', $supportFood, true) && !in_array('food.printer', $supportFood, true) && !in_array('food.design', $supportFood, true) && !in_array('food.health', $supportFood, true) && !in_array('food.guest', $supportFood, true));
check('کد food.orders از کاتالوگ حذف شده', !in_array('food.orders', permission_all_codes(), true) && !in_array('food.orders_edit', permission_all_codes(), true));

echo "\nنتیجه: $pass موفق، $fail ناموفق\n";
exit($fail === 0 ? 0 : 1);

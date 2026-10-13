<?php
declare(strict_types=1);

// آزمون واقعی (نیاز به دیتابیس تست و ITSM_CONFIG_PATH): درخواست غذای مهمان، معاونت خودکار از چارت سازمانی،
// لغو، گزارش با فیلتر معاونت، و سقف حالت «درخواست مهمان». داده‌های آزمون در پایان پاک می‌شوند.

$root = dirname(__DIR__);
if (!getenv('ITSM_CONFIG_PATH')) {
    fwrite(STDERR, "ITSM_CONFIG_PATH تنظیم نشده است.\n");
    exit(2);
}
require_once $root . '/bootstrap.php';
require_once $root . '/organization.php';
require_once $root . '/food-ticket.php'; // شامل food-order.php و food-ticket-engine.php

$pass = 0;
$fail = 0;
function check(string $label, bool $ok): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '[ok] ' : '[FAIL] ') . $label . "\n";
}
function expect_error(callable $fn): string
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e->getMessage();
    }
    return '';
}

$pdo = db();
$cleanupUnits = static function () use ($pdo): void {
    $pdo->prepare("DELETE FROM org_units WHERE code LIKE 'TST-GR%'")->execute();
};
$cleanupUnits();
$pdo->exec("DELETE FROM food_guest_requests WHERE organization IN ('شرکت آزمایشی','سازمان دوم')");

// چارت آزمایشی: دو معاونت؛ زیر معاونت اول یک اداره (مدیرش کاربر ۹۹۹۰۰۱)، زیر معاونت دوم یک اداره (مدیرش کاربر ۹۹۹۰۰۵)
$ins = $pdo->prepare('INSERT INTO org_units (name, code, parent_id, unit_type, manager_user_id, is_active) VALUES (?, ?, ?, ?, ?, 1)');
$ins->execute(['معاونت آزمایشی مالی', 'TST-GR-DEP', null, 'deputy', null]);
$depId = (int) $pdo->lastInsertId();
$ins->execute(['معاونت آزمایشی دوم', 'TST-GR-DEP2', null, 'deputy', null]);
$depId2 = (int) $pdo->lastInsertId();
$ins->execute(['حسابداری آزمایشی', 'TST-GR-DEPT', $depId, 'department', 999001]);
$ins->execute(['اداره آزمایشی دوم', 'TST-GR-DEPT2', $depId2, 'department', 999005]);

$manager = ['id' => 999001, 'role' => 'manager', 'first_name' => 'تست', 'last_name' => 'مدیر حسابداری'];
$manager2 = ['id' => 999005, 'role' => 'manager', 'first_name' => 'تست', 'last_name' => 'مدیر دوم'];
$orphanManager = ['id' => 999009, 'role' => 'manager', 'first_name' => 'تست', 'last_name' => 'بی‌واحد'];
$agent = ['id' => 999002, 'role' => 'agent'];
$inspector = ['id' => 999003, 'role' => 'inspector'];
$tomorrow = (new DateTimeImmutable(food_order_today() . ' +3 day'))->format('Y-m-d');
$createdIds = [];

check('مدیر واحدِ زیر معاونت مجاز است', food_guest_request_allowed($manager));
check('کارشناس بدون جایگاه در چارت مجاز نیست', !food_guest_request_allowed($agent));
check('بازرس بدون جایگاه در چارت مجاز نیست', !food_guest_request_allowed($inspector));
check('مدیر بدون معاونت در چارت مجاز نیست', !food_guest_request_allowed($orphanManager));

check('معاونت کاربر از واحدِ مدیریتی به معاونت بالا می‌رود (حسابداری ← مالی)', food_guest_user_deputy_id($manager) === $depId);
check('معاونت مدیر دوم، معاونت دوم است', food_guest_user_deputy_id($manager2) === $depId2);
check('کاربر بدون جایگاه در چارت، معاونت ندارد', food_guest_user_deputy_id($orphanManager) === 0);

$base = ['request_date' => $tomorrow, 'organization' => 'شرکت آزمایشی', 'guest_count' => '3', 'food_id' => 0, 'requester_name' => 'علی تست'];
$r1 = food_guest_request_create($manager, $base);
$createdIds[] = $r1['id'];
check('ثبت درخواست روز آینده', $r1['id'] > 0);
$stored = (int) $pdo->query('SELECT deputy_unit_id FROM food_guest_requests WHERE id = ' . (int) $r1['id'])->fetchColumn();
check('معاونت به‌صورت خودکار ذخیره شد', $stored === $depId);

// مقدار ارسالی فرم برای معاونت نادیده گرفته می‌شود
$r1b = food_guest_request_create($manager, array_merge($base, ['organization' => 'سازمان سوم', 'deputy_unit_id' => $depId2]));
$createdIds[] = $r1b['id'];
check('معاونت ارسالی از مرورگر نادیده گرفته می‌شود', (int) $pdo->query('SELECT deputy_unit_id FROM food_guest_requests WHERE id = ' . (int) $r1b['id'])->fetchColumn() === $depId);

$r2 = food_guest_request_create($manager2, ['request_date' => $tomorrow, 'organization' => 'سازمان دوم', 'guest_count' => '5', 'requester_name' => 'رضا تست']);
$createdIds[] = $r2['id'];
check('جمع درخواست‌های فعال همان روز = 3+3+5 = 11', food_guest_requested_total($tomorrow) === 11);

check('بدون جایگاه در چارت ثبت نمی‌شود', str_contains(expect_error(fn () => food_guest_request_create($orphanManager, $base)), 'چارت سازمانی'));
check('امروز قابل ثبت نیست', str_contains(expect_error(fn () => food_guest_request_create($manager, array_merge($base, ['request_date' => food_order_today()]))), 'آینده'));
check('تعداد صفر رد می‌شود', str_contains(expect_error(fn () => food_guest_request_create($manager, array_merge($base, ['guest_count' => '0']))), 'تعداد'));
check('تعداد بیشتر از ۵۰۰ رد می‌شود', str_contains(expect_error(fn () => food_guest_request_create($manager, array_merge($base, ['guest_count' => '501']))), 'تعداد'));
check('سازمان خالی رد می‌شود', str_contains(expect_error(fn () => food_guest_request_create($manager, array_merge($base, ['organization' => ' ']))), 'سازمان'));

// سقف حالت درخواست مهمان
$cap = food_guest_requested_total($tomorrow) ?? 0;
check('سقف درخواست‌شده ۱۱ است: فیش یازدهم مجاز، دوازدهمی بسته', food_ticket_guest_cap_reached(10, $cap, 0, 0, 0) === false && food_ticket_guest_cap_reached(11, $cap, 0, 0, 0) === true);
check('روز بدون درخواست: سقف صفر یعنی بسته', food_ticket_guest_cap_reached(0, food_guest_requested_total('2999-01-01') ?? 0, 20, 0, 0) === true);

// گزارش با فیلتر معاونت
$all = food_ticket_api_guest_requests_report($tomorrow, $tomorrow, 'all');
$allIds = array_map(fn ($i) => $i['id'], $all['items']);
sort($allIds);
$expectAll = [$r1['id'], $r1b['id'], $r2['id']];
sort($expectAll);
check('گزارش «همه» هر سه درخواست فعال را دارد', $allIds === $expectAll);
$onlyFirst = food_ticket_api_guest_requests_report($tomorrow, $tomorrow, (string) $depId);
$firstIds = array_map(fn ($i) => $i['id'], $onlyFirst['items']);
check('فیلتر معاونت مالی فقط درخواست‌های همان معاونت را دارد', in_array($r1['id'], $firstIds, true) && in_array($r1b['id'], $firstIds, true) && !in_array($r2['id'], $firstIds, true));
$onlySecond = food_ticket_api_guest_requests_report($tomorrow, $tomorrow, (string) $depId2);
check('فیلتر معاونت دوم فقط درخواست خودش را دارد', array_map(fn ($i) => $i['id'], $onlySecond['items']) === [$r2['id']]);
check('فهرست معاونت‌ها در گزارش برگردانده می‌شود', in_array($depId, array_column($all['deputies'], 'id'), true));
check('نام معاونت در ردیف گزارش است', $onlyFirst['items'][0]['deputy_name'] === 'معاونت آزمایشی مالی');
check('بازهٔ خارج از تاریخ، نتیجه خالی', food_ticket_api_guest_requests_report('2990-01-01', '2990-01-02', 'all')['items'] === []);

// فهرست: معاونت کاربر در پاسخ
check('فهرست، معاونت کاربر را برای نمایش می‌دهد', (food_guest_request_list($manager)['my_deputy']['id'] ?? 0) === $depId);
check('فهرست برای کاربر بی‌واحد معاونت خالی دارد', food_guest_request_list($orphanManager)['my_deputy'] === null);

food_guest_request_cancel($manager, (int) $r1b['id']);
check('درخواست لغوشده در گزارش نیست', !in_array($r1b['id'], array_map(fn ($i) => $i['id'], food_ticket_api_guest_requests_report($tomorrow, $tomorrow, 'all')['items']), true));
check('لغو درخواست، جمع را به ۸ می‌رساند', food_guest_requested_total($tomorrow) === 8);
check('لغو دوباره رد می‌شود', str_contains(expect_error(fn () => food_guest_request_cancel($manager, (int) $r1b['id'])), 'قبلاً'));
check('درخواست لغوشده در فهرست با وضعیت لغو است', (function () use ($r1b, $manager) {
    foreach (food_guest_request_list($manager)['items'] as $it) {
        if ($it['id'] === $r1b['id']) return $it['status'] === 'cancelled' && $it['editable'] === false;
    }
    return false;
})());

// پاک‌سازی
$pdo->exec("DELETE FROM food_guest_requests WHERE organization IN ('شرکت آزمایشی','سازمان دوم','سازمان سوم')");
$cleanupUnits();
check('داده‌های آزمون پاک شد', (int) $pdo->query("SELECT COUNT(*) FROM food_guest_requests WHERE organization IN ('شرکت آزمایشی','سازمان دوم','سازمان سوم')")->fetchColumn() === 0
    && (int) $pdo->query("SELECT COUNT(*) FROM org_units WHERE code LIKE 'TST-GR%'")->fetchColumn() === 0);

echo "\nنتیجه: {$pass} موفق، {$fail} ناموفق\n";
exit($fail > 0 ? 1 : 0);

<?php
declare(strict_types=1);

// آزمون آفلاین: (۱) خطای ltrim با کلید عددی در نقشهٔ سفارش، (۲) کد ملی یکتا در ثبت/ویرایش کارمند.
// توابع از فایل‌های اصلی استخراج می‌شوند و دیتابیس SQLite درون‌حافظه است.

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
    for ($i = $open; $i < strlen($src); $i++) {
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

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, password_hash TEXT, full_name TEXT, first_name TEXT, last_name TEXT,
    employee_number TEXT, national_code TEXT, role TEXT, auth_source TEXT, is_active INTEGER)');
$pdo->exec('CREATE TABLE food_catalog (id INTEGER PRIMARY KEY, food_name TEXT, active INTEGER)');
$pdo->exec('CREATE TABLE food_calendar_items (id INTEGER PRIMARY KEY, food_id INTEGER, active INTEGER)');
$pdo->exec('CREATE TABLE food_orders (id INTEGER PRIMARY KEY, employee_id INTEGER, calendar_item_id INTEGER, food_date TEXT, status TEXT)');
$GLOBALS['tdb'] = $pdo;

function db(): PDO
{
    return $GLOBALS['tdb'];
}
function food_order_schema_ensure(): bool
{
    return true;
}
function cfg(string $key, mixed $default = null): mixed
{
    return $default;
}

$code = '';
foreach ([[$root . '/bootstrap.php', 'normalize_employee_number'],
          [$root . '/food-order.php', 'food_order_normalize_digits'],
          [$root . '/food-order.php', 'food_order_digits'],
          [$root . '/food-order.php', 'food_order_iso_date'],
          [$root . '/food-ticket-order-source.php', 'food_order_internal_map'],
          [$root . '/food-ticket.php', 'food_ticket_api_save_employee']] as [$file, $fn]) {
    $code .= extract_php_function($file, $fn) . "\n";
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

// ── بخش ۱: نقشهٔ سفارش با کلید عددی و کد ملی تکراری (قبلاً TypeError می‌داد) ──
$pdo->exec("INSERT INTO food_catalog VALUES (1, 'چلوکباب', 1)");
$pdo->exec("INSERT INTO food_calendar_items VALUES (1, 1, 1)");
$pdo->exec("INSERT INTO users VALUES (10, 'a', NULL, 'الف', 'الف', 'ب', '1001', '1234567890', 'user', 'food', 1)");
$pdo->exec("INSERT INTO users VALUES (11, 'c', NULL, 'ج', 'ج', 'د', '1002', '1234567890', 'user', 'food', 1)");
$pdo->exec("INSERT INTO users VALUES (12, 'e', NULL, 'ه', 'و', 'ز', '1003', '0012345678', 'user', 'food', 1)");
$pdo->exec("INSERT INTO food_orders VALUES (1, 10, 1, '2026-10-10', 'active')");
$pdo->exec("INSERT INTO food_orders VALUES (2, 11, 1, '2026-10-10', 'active')");
$pdo->exec("INSERT INTO food_orders VALUES (3, 12, 1, '2026-10-10', 'active')");
try {
    $map = food_order_internal_map('2026-10-10');
    check('نقشهٔ سفارش با کد ملی تکراری (کلید عددی) بدون خطا اجرا می‌شود', true);
    check('کد ملی تکراری از نقشه حذف شده', !isset($map['1234567890']));
    check('کد ملی یکتا (با صفر ابتدایی) در نقشه می‌ماند', ($map['0012345678'] ?? null) === 'چلوکباب' || ($map['12345678'] ?? null) === 'چلوکباب', json_encode(array_keys($map)));
} catch (Throwable $e) {
    check('نقشهٔ سفارش با کد ملی تکراری (کلید عددی) بدون خطا اجرا می‌شود', false, $e->getMessage());
}

// ── بخش ۲: کد ملی یکتا در ثبت و ویرایش کارمند ──
$base = ['pc' => '2001', 'nat' => '2223334445', 'first' => 'علی', 'last' => 'رضایی'];
try {
    food_ticket_api_save_employee($base + ['id' => 0]);
    $newId = (int) db()->query("SELECT id FROM users WHERE employee_number = '2001'")->fetchColumn();
    check('ثبت کارمند با کد ملی جدید انجام می‌شود', $newId > 0);

    $msg = '';
    try {
        food_ticket_api_save_employee(['pc' => '2002', 'nat' => '2223334445', 'first' => 'تست', 'last' => 'دوم', 'id' => 0]);
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
    }
    check('ثبت کارمند جدید با کد ملی تکراری خطای مناسب می‌دهد', str_contains($msg, 'قبلاً') && str_contains($msg, 'علی رضایی'), $msg);
    check('رکورد تکراری ذخیره نشد', (int) db()->query("SELECT COUNT(*) FROM users WHERE employee_number = '2002'")->fetchColumn() === 0);

    // ویرایش همان کارمند با همان کد ملی مجاز است
    $msg = '';
    try {
        food_ticket_api_save_employee($base + ['id' => $newId, 'first' => 'علی ۲']);
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
    }
    check('ویرایش همان کارمند با کد ملی خودش خطا نمی‌دهد', $msg === '', $msg);

    // کارمند دیگری را به کد ملی همین کارمند تغییر دهیم → خطا
    food_ticket_api_save_employee(['pc' => '2003', 'nat' => '3334445556', 'first' => 'سوم', 'last' => 'کس', 'id' => 0]);
    $thirdId = (int) db()->query("SELECT id FROM users WHERE employee_number = '2003'")->fetchColumn();
    $msg = '';
    try {
        food_ticket_api_save_employee(['pc' => '2003', 'nat' => '2223334445', 'first' => 'سوم', 'last' => 'کس', 'id' => $thirdId]);
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
    }
    check('ویرایش کارمند به کد ملی تکراری رد می‌شود', str_contains($msg, 'قبلاً'), $msg);
    check('کد ملی کارمند سوم بدون تغییر ماند', (string) db()->query("SELECT national_code FROM users WHERE id = {$thirdId}")->fetchColumn() === '3334445556');
} catch (Throwable $e) {
    check('آزمون ثبت/ویرایش کارمند اجرا شد', false, $e->getMessage());
}

echo "\n{$pass} موفق، {$fail} ناموفق\n";
exit($fail === 0 ? 0 : 1);

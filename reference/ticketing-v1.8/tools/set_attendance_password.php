<?php
declare(strict_types=1);

/**
 * تنظیم مستقیم رمز فایل Access منبع تردد (بدون فرم وب)
 *
 * استفاده (از ریشه پروژه، با PHP CLI):
 *   php tools/set_attendance_password.php "<رمز فایل Access>"
 *   php tools/set_attendance_password.php "<رمز فایل Access>" --test
 *   php tools/set_attendance_password.php --diagnose
 *
 * --test     بعد از ذخیره، اتصال واقعی به SOURCE_TABLE را امتحان می‌کند
 * --diagnose فقط وضعیت فعلی را نشان می‌دهد (رمز را چاپ نمی‌کند)
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require $root . '/bootstrap.php';
require $root . '/food-ticket.php';

$args = array_slice($argv, 1);
$diagnoseOnly = in_array('--diagnose', $args, true);
$doTest = in_array('--test', $args, true);
$passwordArgs = array_values(array_filter($args, static fn($a) => !str_starts_with((string) $a, '--')));

$config = food_ticket_config(true);
$enc = (string) ($config['attendance_password_enc'] ?? '');
$path = (string) ($config['attendance_path'] ?? '');
$enabled = (int) ($config['enabled'] ?? 0);

echo "=== تشخیص رمز فایل منبع تردد ===
اکنون رمز ذخیره‌شده در food_ticket_config (attendance_password_enc) بر «رمز پیش‌فرض کارخانه» اولویت دارد (۱.۳۴).\n";
echo "attendance_path : " . ($path !== '' ? $path : '(خالی)') . "\n";
echo "enabled   : {$enabled}\n";
echo "enc length: " . strlen($enc) . "\n";
echo "app.key   : " . (trim((string) cfg('app.key', '')) !== '' ? 'تنظیم شده (' . strlen((string) cfg('app.key', '')) . ' کاراکتر)' : 'خالی یا پیش‌فرض') . "\n";

if ($enc !== '') {
    $plain = food_ticket_decrypt($enc);
    if ($plain === '') {
        echo "decrypt   : شکست (app.key احتمالاً عوض شده یا ciphertext خراب است)\n";
    } else {
        echo "decrypt   : موفق (طول رمز: " . strlen($plain) . ")\n";
        echo "رمز فعلی  : " . $plain . "\n";
    }
} else {
    echo "decrypt   : رمزی ذخیره نشده\n";
}

if ($path !== '') {
    echo "file exists: " . (is_file($path) ? 'بله' : 'خیر — مسیر از دید PHP پیدا نشد') . "\n";
}

if ($diagnoseOnly) {
    exit(0);
}

if ($passwordArgs === []) {
    fwrite(STDERR, "\nرمز جدید را به‌عنوان آرگومان بدهید، مثلاً:\n  php tools/set_attendance_password.php \"<رمز فایل Access>\" --test\n");
    exit(1);
}

$newPassword = (string) $passwordArgs[0];
$encNew = food_ticket_encrypt($newPassword);
if ($encNew === '' || food_ticket_decrypt($encNew) !== $newPassword) {
    fwrite(STDERR, "رمزنگاری ناموفق. app.key در config.php را بررسی کنید.\n");
    exit(1);
}

$colPwd = function_exists('food_ticket_db_column') ? food_ticket_db_column('attendance_password_enc') : 'attendance_password_enc';
$colPath = function_exists('food_ticket_db_column') ? food_ticket_db_column('attendance_path') : 'attendance_path';
$stmt = db()->prepare('UPDATE food_ticket_config SET ' . $colPwd . ' = ?, enabled = 1 WHERE id = 1');
$stmt->execute([$encNew]);
if ($stmt->rowCount() === 0) {
    // ردیف وجود ندارد
    $ins = db()->prepare('INSERT INTO food_ticket_config (id, enabled, ' . $colPath . ', ' . $colPwd . ') VALUES (1, 1, ?, ?) ON DUPLICATE KEY UPDATE ' . $colPwd . ' = VALUES(' . $colPwd . '), enabled = 1');
    $ins->execute([$path, $encNew]);
}

echo "\nرمز جدید ذخیره شد و enabled=1 شد.\n";

if ($doTest) {
    echo "آزمون اتصال SOURCE_TABLE...\n";
    try {
        $cfg = food_ticket_config(true);
        $conn = food_ticket_odbc((string) $cfg['attendance_path'], food_ticket_attendance_password($cfg));
        try {
            $rows = food_ticket_access_rows($conn, food_ticket_source_table(), 3);
            echo "OK — اتصال موفق. تعداد ردیف نمونه‌خوانی‌شده: " . count($rows) . "\n";
            if ($rows) {
                $first = $rows[0];
                $keys = array_slice(array_keys($first), 0, 8);
                echo "ستون‌های نمونه: " . implode(', ', $keys) . "\n";
            }
        } finally {
            food_ticket_access_close($conn);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "آزمون اتصال شکست: " . $e->getMessage() . "\n");
        exit(1);
    }
}

echo "حالا install-food-worker-SERVICE.ps1 را دوباره اجرا کنید.\n";
exit(0);

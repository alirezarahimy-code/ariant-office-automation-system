<?php
declare(strict_types=1);

/**
 * ابزار تشخیص و اصلاح ستون‌های جدول رویدادهای فیش غذا — نسخهٔ ۱.۳۷.۶
 *
 * چه کاری می‌کند؟
 *   ۱) ستون‌های واقعی جدول food_ticket_events را نشان می‌دهد (زنده، بدون کش).
 *   ۲) می‌گوید کدام ستون از Migration های ۱.۳۲ و ۱.۳۳ کم است
 *      (delivery_status / delivered_at / delivered_by / source_deleted / source_deleted_at / source_delete_note).
 *   ۳) با سوییچ --fix ستون‌های کم را می‌سازد (همان دستورهای Migration، بی‌خطر و تکرارپذیر).
 *   ۴) اگر ساخت ناموفق بود، **علت واقعی** دیتابیس را چاپ می‌کند (معمولاً نبود دسترسی ALTER).
 *
 * اجرا:
 *   php tools\fix_food_columns.php             فقط گزارش (هیچ تغییری نمی‌دهد)
 *   php tools\fix_food_columns.php --fix       ساخت ستون‌های کم + ایندکس‌ها
 *
 * نکته: این ابزار همان کاری را می‌کند که خود سامانه در اولین اجرا انجام می‌دهد؛
 *       اجرای دستی آن وقتی لازم است که کاربر دیتابیس اجازهٔ ALTER نداشته باشد یا
 *       برای اطمینان کامل، پیش از آزمون میدانی.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

// پیام روشن اگر فایل تنظیمات نیست (bootstrap در CLI بی‌صدا خارج می‌شود)
$configPath = (string) (getenv('ITSM_CONFIG_PATH') ?: dirname(__DIR__) . '/config.php');
if (!is_file($configPath)) {
    fwrite(STDERR, "❌ فایل تنظیمات پیدا نشد: {$configPath}\n"
        . "   این ابزار باید روی همان سروری اجرا شود که سامانه نصب شده است و فایل config.php را دارد.\n");
    exit(1);
}

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/food-ticket.php';

$fix = in_array('--fix', $argv ?? [], true);
$table = 'food_ticket_events';

/** ستون‌های لازم: نام ⇒ دستور افزودن (عیناً مطابق Migration ۱.۳۲/۱.۳۳) */
$required = [
    'delivery_status' => 'ALTER TABLE ' . $table . ' ADD COLUMN delivery_status ENUM("pending","delivered") NOT NULL DEFAULT "pending" AFTER print_status',
    'delivered_at' => 'ALTER TABLE ' . $table . ' ADD COLUMN delivered_at DATETIME NULL AFTER delivery_status',
    'delivered_by' => 'ALTER TABLE ' . $table . ' ADD COLUMN delivered_by INT UNSIGNED NULL AFTER delivered_at',
    'source_deleted' => 'ALTER TABLE ' . $table . ' ADD COLUMN source_deleted TINYINT(1) NOT NULL DEFAULT 0 AFTER delivered_by',
    'source_deleted_at' => 'ALTER TABLE ' . $table . ' ADD COLUMN source_deleted_at DATETIME NULL AFTER source_deleted',
    'source_delete_note' => 'ALTER TABLE ' . $table . ' ADD COLUMN source_delete_note VARCHAR(255) NULL AFTER source_deleted_at',
];
$indexes = [
    'idx_food_events_src_deleted' => 'CREATE INDEX idx_food_events_src_deleted ON ' . $table . ' (source_deleted, punch_date)',
];

echo "=== ستون‌های جدول {$table} — بررسی زنده ===\n";
echo 'دیتابیس: ' . (defined('DB_NAME') ? DB_NAME : (string) cfg('database.name', '?')) . "\n";
echo 'کاربر   : ' . (defined('DB_USER') ? DB_USER : (string) cfg('database.user', '?')) . "\n\n";

// ── ۱) وجود جدول ────────────────────────────────────────────────────────────
if (function_exists('db_table_exists') && !db_table_exists($table)) {
    fwrite(STDERR, "❌ جدول {$table} وجود ندارد؛ ابتدا Migration های ۱.۹ تا ۱.۳۳ را اجرا کنید.\n");
    exit(1);
}

// ── ۲) فهرست زندهٔ ستون‌ها (بدون تکیه بر کش) ────────────────────────────────
$live = [];
try {
    $q = db()->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position');
    $q->execute([$table]);
    $live = array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN));
} catch (Throwable $e) {
    fwrite(STDERR, '❌ خواندن ساختار جدول ناموفق بود: ' . $e->getMessage() . "\n");
    exit(1);
}
echo 'ستون‌های موجود (' . count($live) . "):\n  " . implode(', ', $live) . "\n\n";

$missing = array_values(array_filter(array_keys($required), static fn(string $c): bool => !in_array($c, $live, true)));

// ── ۳) تشخیص توابع سامانه (همان تابعی که دکمهٔ تحویل استفاده می‌کند) ────────
echo "— تشخیص سامانه (همان چیزی که دکمهٔ «ثبت تحویل» می‌بیند) —\n";
$delivery = function_exists('food_ticket_delivery_supported') ? food_ticket_delivery_supported(null) : null;
$exportDelete = function_exists('food_ticket_source_delete_supported') ? food_ticket_source_delete_supported(null) : null;
echo '  تحویل (delivery_status)      : ' . ($delivery === null ? 'نامعلوم' : ($delivery ? '✅ آماده' : '❌ آماده نیست')) . "\n";
echo '  حذف ردیف منبع (source_deleted): ' . ($exportDelete === null ? 'نامعلوم' : ($exportDelete ? '✅ آماده' : '❌ آماده نیست')) . "\n\n";

if ($missing === []) {
    echo "✅ هیچ ستونی کم نیست. اگر باز هم پیام «ستون ساخته نشده» دیدید، فایل‌های نسخهٔ تازه (۱.۳۷.۶) را روی سرور کپی کرده‌اید؟\n";
    echo "   (نسخه‌های پیش از ۱.۳۷.۶ این ستون‌ها را اشتباه «نبود» تشخیص می‌دادند.)\n";
    exit(0);
}

echo '❌ ستون‌های کم: ' . implode(', ', $missing) . "\n";
if (!$fix) {
    echo "\nبرای ساخت آن‌ها اجرا کنید:  php tools\\fix_food_columns.php --fix\n";
    exit(2);
}

echo "\n— ساخت ستون‌های کم —\n";
$errors = [];
foreach ($missing as $column) {
    try {
        db()->exec($required[$column]);
        echo "  ✔ {$column} ساخته شد\n";
    } catch (Throwable $e) {
        $errors[$column] = $e->getMessage();
        echo "  ✘ {$column}: " . $e->getMessage() . "\n";
    }
}
foreach ($indexes as $label => $sql) {
    try {
        db()->exec($sql);
        echo "  ✔ ایندکس {$label} ساخته شد\n";
    } catch (Throwable $e) {
        // ایندکس ممکن است از قبل باشد
        echo "  • ایندکس {$label}: " . $e->getMessage() . "\n";
    }
}

// ── ۴) بررسی پس از اصلاح (زنده) ────────────────────────────────────────────
$q->execute([$table]);
$after = array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN));
$stillMissing = array_values(array_filter($missing, static fn(string $c): bool => !in_array($c, $after, true)));

echo "\n— نتیجه پس از اصلاح —\n";
if ($stillMissing === []) {
    echo "✅ همهٔ ستون‌ها موجود شدند. یک بار صفحهٔ پایش غذا را در مرورگر با Ctrl+F5 باز کنید.\n";
    if (function_exists('food_ticket_delivery_supported')) {
        echo '   تشخیص سامانه حالا: ' . (food_ticket_delivery_supported(null) ? '✅ آماده' : '❌ آماده نیست') . "\n";
    }
    exit(0);
}

echo '❌ ساخته نشد: ' . implode(', ', $stillMissing) . "\n";
foreach ($errors as $column => $message) {
    echo "   {$column}: {$message}\n";
}
echo "\nراه‌حل: همین فایل Migration را با کاربرِ دارای دسترسی روی دیتابیس اجرا کنید:\n";
echo "   mysql -u root -p <دیتابیس> < upgrade-1.32-food-group-representative-absence.sql\n";
echo "   (و برای ستون‌های حذف ردیف منبع: upgrade-1.33-source-delete-truth.sql)\n";
exit(1);

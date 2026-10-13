<?php
declare(strict_types=1);

/**
 * ابزار تشخیص «اتصال‌ها» — نسخهٔ ۱.۳۵
 * ============================================================================
 *   C:\xampp\php\php.exe tools\diag_connection.php
 *   C:\xampp\php\php.exe tools\diag_connection.php --client=192.168.1.25   آزمون یک کلاینت دامنه
 *   C:\xampp\php\php.exe tools\diag_connection.php --json                  خروجی JSON (برای ارسال)
 *
 * چه چیزی را بررسی می‌کند (به ترتیب همان زنجیره‌ای که سامانه استفاده می‌کند):
 *   ۱) محیط: افزونه‌های لازم (odbc، ldap، pdo_mysql، mbstring، gd، openssl)
 *   ۲) تنظیمات ذخیره‌شده: مسیر/جدول/رمز منبع تردد، منبع داخلی food_orders، چاپگر، تنظیمات دامنه و LDAP
 *   ۳) فایل منبع تردد Access TENTER: وجود، خواندن، اتصال ODBC و شمارش ردیف‌های جدول
 *   ۴) سفارش غذا: فقط شمارش تجمیعی در MySQL داخلی؛ هیچ مسیر/فایل/API سفارش Access بررسی نمی‌شود
 *   ۵) چاپگر: دسترس‌بودن IP:پورت یا صف ویندوز
 *   ۶) کاربران: LDAP/AD — اتصال، ورود، جست‌وجو و تعداد کاربرانی که وارد سامانه می‌شوند
 *   ۷) کامپیوترها: خواندن فهرست کامپیوترهای دامنه + پینگ/دسترسی به یک کلاینت
 *
 * هیچ چیزی در سامانه تغییر نمی‌دهد (فقط خواندن). همهٔ خطاها به‌صورت «علت + راه‌حل» چاپ می‌شوند.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z_]+)(?:=(.*))?$/', (string) $arg, $m) === 1) {
        $options[$m[1]] = $m[2] ?? '1';
    }
}
$asJson = isset($options['json']);
$report = ['at' => date('c'), 'host' => gethostname() ?: '', 'os' => PHP_OS_FAMILY . ' ' . php_uname('r'), 'php' => PHP_VERSION, 'checks' => []];
$problems = 0;

/** ثبت یک بررسی: ok = true/false/null (null = قابل بررسی نبود) */
$add = static function (string $section, string $label, ?bool $ok, string $detail = '', string $fix = '') use (&$report, &$problems, $asJson): void {
    $report['checks'][] = ['section' => $section, 'label' => $label, 'ok' => $ok, 'detail' => $detail, 'fix' => $fix];
    if ($ok === false) {
        $problems++;
        $report['failed'][] = $section . ' — ' . $label;
    }
    if ($asJson) {
        return;
    }
    $mark = $ok === true ? '[ OK ]' : ($ok === false ? '[FAIL]' : '[SKIP]');
    echo $mark . ' ' . $label . ($detail !== '' ? ' — ' . $detail : '') . "\n";
    if ($ok === false && $fix !== '') {
        echo "        راه‌حل: " . $fix . "\n";
    }
};

if (!$asJson) {
    echo "=== تشخیص اتصال‌های سامانه (۱.۳۵) ===\n";
    echo 'PHP ' . PHP_VERSION . ' | ' . PHP_OS_FAMILY . ' ' . php_uname('r') . ' | ' . (gethostname() ?: '') . "\n\n";
}

/* ─────────────────────────── ۱) محیط ─────────────────────────── */
$exts = [
    'pdo_mysql' => 'اتصال به پایگاه‌دادهٔ سامانه (الزامی)',
    'mbstring' => 'متن فارسی و برش رشته‌ها (الزامی)',
    'openssl' => 'رمزنگاری رمز Access/LDAP (الزامی)',
    'odbc' => 'اتصال به فایل Access سامانهٔ تردد (الزامی برای ماژول غذا)',
    'ldap' => 'ورود کاربران شبکه و خواندن کاربران دامنه',
    'gd' => 'رسم فیش/تصویر',
];
foreach ($exts as $ext => $why) {
    $loaded = extension_loaded($ext);
    $add('محیط', 'افزونهٔ ' . $ext . ' (' . $why . ')', $loaded, $loaded ? 'فعال' : 'فعال نیست', 'در php.ini خط extension=' . $ext . ' را فعال و وب‌سرور سرویس را ری‌استارت کنید.');
}

try {
    require dirname(__DIR__) . '/bootstrap.php';
    foreach (['food-ticket.php', 'food-ticket-netprint.php', 'food-ticket-templates.php', 'domain-scan.php'] as $mod) {
        $path = dirname(__DIR__) . '/' . $mod;
        if (is_file($path)) {
            require_once $path;
        }
    }
    $add('محیط', 'بارگذاری برنامه و فایل‌های ماژول', true);
} catch (Throwable $e) {
    $add('محیط', 'بارگذاری برنامه', false, $e->getMessage(), 'config.php سالم است؟ (app.key و تنظیمات پایگاه‌داده)');
    if ($asJson) {
        echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    }
    exit(1);
}

/* ─────────────────────────── ۲) تنظیمات ذخیره‌شده ─────────────────────────── */
$cfg = [];
try {
    $cfg = function_exists('food_ticket_config') ? food_ticket_config(true) : [];
} catch (Throwable $e) {
    $add('تنظیمات', 'خواندن تنظیمات ماژول غذا', false, $e->getMessage());
}

$attendancePath = trim((string) ($cfg['attendance_path'] ?? ''));
$attendanceDefault = function_exists('food_ticket_attendance_default_path') ? (string) food_ticket_attendance_default_path() : '';
$attendanceTable = food_ticket_source_table();
$attendancePassword = '';
try {
    $attendancePassword = function_exists('food_ticket_attendance_password') ? (string) food_ticket_attendance_password($cfg) : '';
} catch (Throwable) {
}
$add('تنظیمات', 'مسیر فایل سامانهٔ تردد', $attendancePath !== '', $attendancePath !== '' ? $attendancePath : 'خالی است (مقدار پیش‌فرض سامانه: ' . $attendanceDefault . ')', 'در پنل غذا → تنظیمات اتصال، «مسیر فایل منبع تردد» را وارد و ذخیره کنید.');
$add('تنظیمات', 'رمز فایل سامانهٔ تردد', $attendancePassword !== '', $attendancePassword !== '' ? 'مقدار دارد (نمایش داده نمی‌شود)' : 'خالی/پیش‌فرض', 'با tools\set_attendance_password.php یا فرم «رمز فایل منبع تردد» در پنل، رمز درست را ذخیره کنید.');
$add('تنظیمات', 'جدول تردد', true, $attendanceTable);
$add('تنظیمات', 'منبع سفارش غذا', function_exists('food_order_mode') && food_order_mode() === 'INTERNAL-DB', 'فقط MySQL داخلی food_orders؛ Access سفارش در مسیر اجرا نیست');
$add('تنظیمات', 'وضعیت فعال‌بودن ماژول', !empty($cfg['enabled']), !empty($cfg['enabled']) ? 'فعال' : 'غیرفعال', 'در پنل غذا کلید فعال‌سازی را روشن کنید.');

$printerMode = function_exists('food_ticket_normalize_printer_mode') ? food_ticket_normalize_printer_mode((string) ($cfg['printer_mode'] ?? '')) : (string) ($cfg['printer_mode'] ?? '');
$printerHost = trim((string) ($cfg['printer_host'] ?? ''));
$printerShare = trim((string) ($cfg['printer_share'] ?? ''));
$printerPort = (int) ($cfg['printer_port'] ?? 9100);
$printerOk = $printerMode === 'windows_share' ? $printerShare !== '' : $printerHost !== '';
$add('تنظیمات', 'چاپگر', $printerOk, $printerMode === 'windows_share' ? ('صف ویندوز: ' . ($printerShare !== '' ? $printerShare : '—')) : ('شبکه: ' . ($printerHost !== '' ? $printerHost . ':' . $printerPort : '—')), 'تنظیمات چاپگر را در پنل غذا → چاپگر ذخیره کنید.');

/* ─────────────────────────── ۳) فایل سامانهٔ تردد ─────────────────────────── */
if ($attendancePath !== '') {
    $exists = @file_exists($attendancePath);
    $readable = $exists && @is_readable($attendancePath);
    $add('سامانهٔ تردد', 'دسترسی به فایل منبع تردد', $readable, $exists ? ($readable ? 'خوانا است' : 'فایل هست ولی خواندنش مجاز نیست') : 'فایل پیدا نشد', 'حساب سرویس وب باید به مسیر شبکه دسترسی داشته باشد؛ مسیر UNC را با همان حساب سرویس تست کنید.');
    if ($readable) {
        $size = @filesize($attendancePath);
        $add('سامانهٔ تردد', 'اندازهٔ فایل', $size !== false && $size > 0, $size !== false ? number_format((float) $size) . ' بایت' : 'نامعلوم');
        if (count(get_included_files()) > 0 && function_exists('food_ticket_odbc')) {
            try {
                $odbc = food_ticket_odbc($attendancePath, $attendancePassword);
                $add('سامانهٔ تردد', 'اتصال ODBC به فایل', true, 'اتصال برقرار شد');
                try {
                    $rows = $odbc->query('SELECT COUNT(*) AS c FROM ' . $attendanceTable)->fetch();
                    $count = (int) ($rows['c'] ?? 0);
                    $add('سامانهٔ تردد', 'خواندن جدول ' . $attendanceTable, true, 'تعداد ردیف: ' . $count);
                } catch (Throwable $e) {
                    $add('سامانهٔ تردد', 'خواندن جدول ' . $attendanceTable, false, $e->getMessage(), 'نام جدول/ستون‌ها را در پنل غذا → تنظیمات اتصال اصلاح کنید.');
                }
            } catch (Throwable $e) {
                $add('سامانهٔ تردد', 'اتصال ODBC به فایل', false, $e->getMessage(), 'رمز فایل و بیت‌بودن درایور ODBC (۳۲/۶۴ هم‌خوان با PHP) را بررسی کنید. با tools\set_attendance_password.php رمز را دوباره ذخیره کنید.');
            }
        }
    }
} else {
    $add('سامانهٔ تردد', 'آزمون اتصال', null, 'مسیر خالی است؛ آزمون انجام نشد');
}

/* ─────────────────────────── ۴) سفارش داخلی ─────────────────────────── */
try {
    if (!function_exists('food_order_schema_ensure')) {
        throw new RuntimeException('ماژول سفارش داخلی بارگذاری نشده است.');
    }
    food_order_schema_ensure();
    $today = function_exists('food_order_today') ? food_order_today() : date('Y-m-d');
    $stmt = db()->prepare("SELECT COUNT(*) FROM food_orders WHERE food_date = ? AND status = 'active'");
    $stmt->execute([$today]);
    $add('سفارش غذا', 'خواندن تجمیعی food_orders', true, (int) $stmt->fetchColumn() . ' سفارش فعال امروز؛ بدون خواندن Access سفارش');
} catch (Throwable $e) {
    $add('سفارش غذا', 'خواندن food_orders', false, $e->getMessage(), 'اجرای upgrade-1.38-food-orders.sql و دسترسی MySQL را بررسی کنید.');
}

/* ─────────────────────────── ۵) چاپگر ─────────────────────────── */
if ($printerMode !== 'windows_share' && $printerHost !== '') {
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($printerHost, $printerPort, $errno, $errstr, 4);
    if ($fp) {
        fclose($fp);
        $add('چاپگر', 'دسترسی به چاپگر شبکه', true, $printerHost . ':' . $printerPort . ' پاسخ داد');
    } else {
        $add('چاپگر', 'دسترسی به چاپگر شبکه', false, $errstr !== '' ? $errstr : ('کد ' . $errno), 'IP/پورت چاپگر و فایروال را بررسی کنید؛ برای صف ویندوز حالت چاپ را عوض کنید.');
    }
} elseif ($printerMode === 'windows_share' && $printerShare !== '') {
    if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
        $out = [];
        $code = 0;
        @exec('powershell -NoLogo -NoProfile -NonInteractive -Command "Get-Printer -Name ' . str_replace('"', '', $printerShare) . ' | Select-Object -ExpandProperty Name" 2>&1', $out, $code);
        $found = $code === 0 && trim(implode(' ', $out)) !== '';
        $add('چاپگر', 'صف چاپ ویندوز', $found, $found ? $printerShare : trim(implode(' ', $out)), 'نام دقیق چاپگر را از «چاپگرها و دستگاه‌ها» کپی کنید.');
    } else {
        $add('چاپگر', 'صف چاپ ویندوز', null, 'این سرور ویندوز نیست');
    }
}

/* ─────────────────────────── ۶) کاربران (LDAP/AD) ─────────────────────────── */
try {
    if (function_exists('ldap_diagnose')) {
        $diag = ldap_diagnose();
        $add('کاربران', 'زنجیرهٔ LDAP/AD', (bool) ($diag['ok'] ?? false), (string) ($diag['summary'] ?? ''), 'پیام‌های ردیف‌های [FAIL] بالا را ببینید (آدرس کنترلر، Base DN، حساب سرویس).');
        foreach ((array) ($diag['steps'] ?? []) as $step) {
            $label = (string) ($step['label'] ?? '');
            $ok = (bool) ($step['ok'] ?? false);
            $detail = (string) ($step['detail'] ?? '');
            $add('کاربران', ' ↳ ' . $label, $ok, $detail);
        }
    } else {
        $add('کاربران', 'تابع تشخیص LDAP', false, 'bootstrap.php نسخهٔ قدیمی است', 'فایل bootstrap.php را با نسخهٔ بستهٔ ۱.۳۵ به‌روز کنید.');
    }
    $add('کاربران', 'وضعیت «ورود کاربران شبکه»', setting('ldap_enabled', '0') === '1', setting('ldap_enabled', '0') === '1' ? 'فعال' : 'غیرفعال', 'در تنظیمات → اتصال Active Directory تیک «ورود کاربران شبکه فعال باشد» را بزنید.');
    $add('کاربران', 'شمار کاربران سامانه', null, (string) (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() . ' کاربر (کاربران از AD در نخستین ورود ساخته می‌شوند)');
    $ldapBound = trim((string) setting('ldap_bind_dn', '')) !== '';
    $add('کاربران', 'حساب سرویس دامنه', $ldapBound, $ldapBound ? (string) setting('ldap_bind_dn', '') : 'خالی است', 'اگر خالی باشد، فقط ورود کاربران کار می‌کند و نقش‌دهی گروهی انجام نمی‌شود.');
} catch (Throwable $e) {
    $add('کاربران', 'بررسی کاربران', false, $e->getMessage());
}

/* ─────────────────────────── ۷) کامپیوترها (دامنه) ─────────────────────────── */
$target = trim((string) ($options['client'] ?? ''));
if (function_exists('domain_scan_preflight')) {
    $pre = domain_scan_preflight();
    $add('کامپیوترها', 'پیش‌نیاز اسکن دامنه', $pre === null, $pre ?? 'آماده است');
    // ۱.۳۷: وضعیت ترتیب پروتکل و امکان استخراج محلی سرور
    $protocols = trim((string) setting('domain_scan_protocols', ''));
    $add('کامپیوترها', 'ترتیب پروتکل اتصال (۱.۳۷)', true, $protocols === '' ? 'خودکار: اول DCOM سپس WinRM' : $protocols, 'در «تنظیمات ← اسکن دامنه» می‌توانید برای شبکه‌هایی که DCOM بسته است، اول WinRM را انتخاب کنید.');
    if (function_exists('domain_scan_local_names')) {
        $localNames = domain_scan_local_names();
        $add('کامپیوترها', 'استخراج محلی سرور (بدون RPC)', true, 'نام‌های محلی شناخته‌شده: ' . implode(', ', array_slice($localNames, 0, 4)), 'سیستم خودِ سرور بدون نشست از راه دور خوانده می‌شود؛ پس خطای «دسترسی رد شد» روی خود سرور رخ نمی‌دهد.');
    }
}
try {
    if (function_exists('ldap_domain_computers')) {
        $computers = ldap_domain_computers(5000);
        $count = count($computers);
        $add('کامپیوترها', 'خواندن کامپیوترهای دامنه', $count > 0, $count > 0 ? ($count . ' سیستم در دامنه دیده شد') : 'هیچ کامپیوتری خوانده نشد', 'تنظیمات اتصال دامنه (Domain/حساب ادمین/رمز) را در «اسکن دامنه» ذخیره کنید؛ حساب باید اجازهٔ خواندن AD داشته باشد.');
    }
} catch (Throwable $e) {
    $add('کامپیوترها', 'خواندن کامپیوترهای دامنه', false, $e->getMessage(), 'تنظیمات «اسکن دامنه» را کامل کنید و از دسترسی سرور به کنترلر مطمئن شوید.');
}
try {
    $assets = (int) db()->query('SELECT COUNT(*) FROM assets')->fetchColumn();
    $add('کامپیوترها', 'شمار شناسنامه‌های ثبت‌شده', null, $assets . ' سیستم در جدول assets');
} catch (Throwable) {
}
if ($target !== '' && function_exists('domain_scan_online')) {
    $online = domain_scan_online($target);
    $add('کامپیوترها', 'دسترسی به کلاینت ' . $target, $online, $online ? 'پاسخ داد' : 'پاسخی نگرفت', 'پینگ/فایروال و آنلاین‌بودن سیستم را بررسی کنید.');
}

/* ─────────────────────────── جمع‌بندی ─────────────────────────── */
if ($asJson) {
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit($problems === 0 ? 0 : 1);
}

echo "\n=== جمع‌بندی ===\n";
if ($problems === 0) {
    echo "همهٔ حلقه‌های زنجیرهٔ اتصال سالم بودند.\n";
} else {
    echo $problems . " مورد نیاز به بررسی دارد:\n";
    foreach ((array) $report['failed'] as $f) {
        echo '  - ' . $f . "\n";
    }
    echo "\nنکته: اگر همهٔ موارد بالا «OK» است ولی کاربران/کامپیوترها وارد سامانه نمی‌شوند،\n";
    echo "در پنل غذا یک‌بار «آزمون اتصال‌ها» را بزنید و سپس tools\\diag_food_ticket.php را اجرا کنید.\n";
}
echo "\n=== پایان ===\n";
exit($problems === 0 ? 0 : 1);

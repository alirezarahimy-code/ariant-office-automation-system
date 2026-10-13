<?php
declare(strict_types=1);

/**
 * ابزار تشخیص «چرا شناسنامهٔ سیستم خالی است» — نسخهٔ ۱.۳۴
 *
 * اجرا (روی سرور، با همان حسابی که سرویس وب با آن اجرا می‌شود):
 *   C:\xampp\php\php.exe tools\diag_asset_profile.php
 *   C:\xampp\php\php.exe tools\diag_asset_profile.php --asset=12         بررسی شناسنامهٔ یک سیستم
 *   C:\xampp\php\php.exe tools\diag_asset_profile.php --asset=12 --apply  ذخیرهٔ فیلدهای خالی با مقادیر استخراج‌شده
 *   C:\xampp\php\php.exe tools\diag_asset_profile.php --client=192.168.1.25   استخراج یک سیستم دیگر شبکه (با حساب تنظیمات)
 *
 * چه چیزی نشان می‌دهد:
 *   ۱) محیط اجرا: shell_exec/proc_open، disable_functions، مسیر PowerShell، اسکریپت جمع‌آوری
 *   ۲) خروجی خام PowerShell و امتیاز سخت‌افزار (چرا صفر است)
 *   ۳) نگاشت فیلد‌به‌فیلد شناسنامه: چه چیزی استخراج شد و چه چیزی دستی است
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

$say = static function (string $line): void {
    echo $line . "\n";
};
$ok = static function (string $label, bool $pass, string $detail = '') use ($say): void {
    $say(($pass ? '[ OK ] ' : '[FAIL] ') . $label . ($detail !== '' ? ' — ' . $detail : ''));
};

$say('=== تشخیص شناسنامهٔ سیستم (۱.۳۴) ===');
$say('PHP ' . PHP_VERSION . ' | OS ' . PHP_OS_FAMILY . ' ' . php_uname('r') . ' | user ' . (getenv('USERNAME') ?: get_current_user()));

$ok('تابع shell_exec', function_exists('shell_exec'));
$ok('تابع proc_open', function_exists('proc_open'));
$disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
$blocked = array_values(array_intersect(['shell_exec', 'proc_open', 'exec', 'popen'], $disabled));
$ok('توابع مسدود‌شده در disable_functions', $blocked === [], $blocked !== [] ? implode(', ', $blocked) : 'هیچ‌کدام از توابع لازم مسدود نیست');
if (PHP_OS_FAMILY === 'Windows') {
    $where = function_exists('shell_exec') ? trim((string) @shell_exec('where powershell 2>NUL')) : '';
    $ok('مسیر powershell', $where !== '', $where !== '' ? $where : 'پیدا نشد — مسیر powershell را به PATH اضافه کنید');
}

try {
    require dirname(__DIR__) . '/bootstrap.php';
    require_once dirname(__DIR__) . '/inventory.php';
    require_once dirname(__DIR__) . '/asset-profile.php';
    require_once dirname(__DIR__) . '/domain-scan.php';
    $ok('بارگذاری برنامه', true);
} catch (Throwable $e) {
    $ok('بارگذاری برنامه', false, $e->getMessage());
    exit(1);
}

$scriptPath = inventory_server_script_path();
$ok('اسکریپت جمع‌آوری', is_file($scriptPath), $scriptPath);

$say('');
$say('--- تست جمع‌آوری از دید همین سرور ---');
$GLOBALS['itsm_inventory_last_error'] = '';
$inventory = inventory_collect_server_reliable();
$score = inventory_hardware_score($inventory);
$error = inventory_last_error();
$ok('اجرای جمع‌آوری', $score > 0, $score > 0 ? ('امتیاز سخت‌افزار: ' . $score) : ('امتیاز صفر؛ علت: ' . ($error !== '' ? $error : 'خروجی خالی')));
$say('  hostname: ' . (string) ($inventory['hostname'] ?? '') . ' | OS: ' . (string) ($inventory['operating_system'] ?? '') . ' | CPU: ' . (string) ($inventory['cpu'] ?? '') . ' | RAM: ' . (int) ($inventory['memory_mb'] ?? 0) . ' MB');
foreach (['computer', 'bios', 'processor', 'os', 'motherboard', 'memory_modules', 'physical_disks', 'graphics', 'network', 'printers', 'software'] as $key) {
    $rows = inventory_rows($inventory['hardware'][$key] ?? []);
    $say(sprintf('  %-18s %d ردیف', $key, count($rows)));
}

$values = asset_profile_auto_values($inventory);
$filled = 0;
$say('');
$say('--- نگاشت فیلدهای شناسنامه (با دادهٔ همین سرور) ---');
foreach ((array) asset_profile_sections() as $section) {
    $lines = [];
    foreach ((array) $section['fields'] as $field) {
        $key = (string) $field['key'];
        if (empty($field['auto'])) {
            continue;
        }
        $value = trim((string) ($values[$key] ?? ''));
        $lines[] = sprintf('    %-18s %s', $key, $value !== '' ? mb_substr($value, 0, 70) : '— (خالی)');
        if ($value !== '') {
            $filled++;
        }
    }
    if ($lines !== []) {
        $say('  ' . (string) $section['title']);
        foreach ($lines as $line) {
            $say($line);
        }
    }
}
$say('  → مجموع فیلدهای خودکارِ پر‌شده: ' . $filled . ' از ' . count(array_filter(asset_profile_sections(), static fn (array $s): bool => true)) . ' بخش');

$assetId = (int) ($options['asset'] ?? 0);
if ($assetId > 0) {
    $say('');
    $say('--- شناسنامهٔ سیستم #' . $assetId . ' ---');
    $profile = asset_profile_fetch($assetId);
    if ($profile === []) {
        $say('  ردیفی برای این سیستم ثبت نشده است (همهٔ فیلدها خالی).');
    } else {
        $count = 0;
        foreach ((array) asset_profile_sections() as $section) {
            foreach ((array) $section['fields'] as $field) {
                $key = (string) $field['key'];
                $value = trim((string) ($profile[$key] ?? ''));
                if ($value !== '') {
                    $count++;
                    $say(sprintf('    %-18s %s', $key, mb_substr($value, 0, 70)));
                }
            }
        }
        $say('  → ' . $count . ' فیلد پرشده در شناسنامه.');
    }
    if (isset($options['apply']) && $score > 0) {
        $written = asset_profile_apply_auto($assetId, $values, 0, false);
        $say('  → ' . $written . ' فیلد خالی با مقادیر استخراج‌شده پر شد (فیلدهای دستی دست‌نخورده ماندند).');
    }
}

$clientIp = trim((string) ($options['client'] ?? ''));
if ($clientIp !== '') {
    $say('');
    $say('--- استخراج شبکه‌ای سیستم ' . $clientIp . ' ---');
    $problem = domain_scan_preflight();
    if ($problem !== null) {
        $say('  [FAIL] ' . $problem);
    } else {
        $many = domain_scan_remote_inventory_many([$clientIp], 1);
        $row = $many[$clientIp] ?? ['inventory' => [], 'error' => 'نتیجه‌ای برنگشت'];
        if (($row['inventory'] ?? []) === []) {
            $say('  [FAIL] ' . (string) ($row['error'] ?? 'خطای نامشخص') . ' — حساب/رمز «استخراج شبکه» و دسترسی WMI را بررسی کنید.');
        } else {
            $say('  موفق. امتیاز سخت‌افزار: ' . inventory_hardware_score($row['inventory']));
            $clientValues = asset_profile_auto_values($row['inventory']);
            $clientFilled = 0;
            foreach ($clientValues as $v) {
                if (trim((string) $v) !== '') {
                    $clientFilled++;
                }
            }
            $say('  → ' . $clientFilled . ' فیلد خودکار برای این سیستم به‌دست آمد.');
        }
    }
}

$say('');
$say('=== پایان ===');

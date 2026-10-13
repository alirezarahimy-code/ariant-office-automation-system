<?php
declare(strict_types=1);

/**
 * تشخیص گام‌به‌گام اتصال Active Directory / LDAP
 * اجرا از خط فرمان:  php tools/diagnose_ldap.php
 * یا از مرورگر با نقش ادمین اصلی:  index.php?action=diagnose_ldap
 */

if (PHP_SAPI !== 'cli') {
    require dirname(__DIR__) . '/bootstrap.php';
    $currentUser = require_login();
    if (!function_exists('user_is_primary_admin') || !user_is_primary_admin($currentUser)) {
        http_response_code(403);
        exit('فقط ادمین اصلی می‌تواند تشخیص دامین را اجرا کند.');
    }
    header('Content-Type: text/plain; charset=utf-8');
} else {
    require_once dirname(__DIR__) . '/bootstrap.php';
}

$line = static function (string $label, bool $ok, string $detail = ''): void {
    echo ($ok ? '[OK]   ' : '[FAIL] ') . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
};

echo "=== تشخیص اتصال دامنه (LDAP/AD) ===" . PHP_EOL . PHP_EOL;

// ۱) افزونه LDAP
$hasExt = function_exists('ldap_connect');
$line('افزونه LDAP پیاچ‌پی', $hasExt, $hasExt ? 'نصب است' : 'نصب نیست؛ افزونه php-ldap را فعال کنید');
if (!$hasExt) {
    echo PHP_EOL . 'راه‌حل ویندوز: در php.ini خط ;extension=ldap را به extension=ldap تغییر دهید و وب‌سرور را ری‌استارت کنید.' . PHP_EOL;
    echo 'راه‌حل لینوکس: apt install php-ldap  (یا yum install php-ldap) و ری‌استارت سرویس وب.' . PHP_EOL;
    exit(1);
}

// ۲) تنظیمات
$enabled = (bool) ldap_cfg('enabled', false);
$host = trim((string) ldap_cfg('host', ''));
$port = (int) ldap_cfg('port', 389);
$ssl = (bool) ldap_cfg('ssl', false);
$baseDn = trim((string) ldap_cfg('base_dn', ''));
$bindDn = trim((string) ldap_cfg('bind_dn', ''));
$bindPassword = (string) ldap_cfg('bind_password', '');

$line('فعال بودن LDAP در تنظیمات', $enabled, $enabled ? '' : 'در تنظیمات، «ورود کاربران شبکه فعال باشد» را تیک بزنید');
$line('آدرس کنترلر دامین', $host !== '', $host !== '' ? $host : 'خالی است؛ مثلاً dc01.example.local');
$line('Base DN', $baseDn !== '', $baseDn !== '' ? $baseDn : 'خالی است؛ مثلاً DC=example,DC=local');
echo '       پورت: ' . $port . ' | ' . ($ssl ? 'LDAPS (SSL)' : 'LDAP ساده') . PHP_EOL;
echo '       حساب سرویس: ' . ($bindDn !== '' ? $bindDn : '(خالی — bind ناشناس)') . PHP_EOL;
echo PHP_EOL;
echo 'نکته: نام کاربری/رمز پرسنلی در پنل با auth_source=ldap و sAMAccountName تطبیق داده می‌شود.' . PHP_EOL;
echo PHP_EOL;

if ($host === '' || $baseDn === '') {
    echo 'برای ادامه باید آدرس کنترلر و Base DN را در تنظیمات ذخیره کنید.' . PHP_EOL;
    exit(1);
}

// ۳) اتصال
$scheme = $ssl ? 'ldaps://' : 'ldap://';
echo 'تلاش برای اتصال به ' . $scheme . $host . ':' . $port . ' ...' . PHP_EOL;
$connection = @ldap_connect($scheme . $host, $port);
$line('اتصال به کنترلر', (bool) $connection, $connection ? '' : 'برقرار نشد (آدرس/پورت/فایروال را چک کنید)');
if (!$connection) {
    exit(1);
}
@ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
@ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);
if (defined('LDAP_OPT_NETWORK_TIMEOUT')) { @ldap_set_option($connection, LDAP_OPT_NETWORK_TIMEOUT, 8); }

// ۴) Bind
if ($bindDn !== '') {
    $bound = @ldap_bind($connection, $bindDn, $bindPassword);
    $line('ورود با حساب سرویس', (bool) $bound, $bound ? '' : (function_exists('ldap_error') ? ldap_error($connection) : 'ناموفق'));
    if (!$bound) {
        echo PHP_EOL . 'اگر بایند با خطای «Invalid credentials» رد شد، DN یا رمز حساب سرویس را بازبینی کنید.' . PHP_EOL;
        exit(1);
    }
} else {
    $bound = @ldap_bind($connection);
    $line('ورود ناشناس (بدون حساب سرویس)', (bool) $bound, $bound ? '' : 'کنترلر اجازه نمی‌دهد؛ حساب سرویس را پر کنید');
    if (!$bound) {
        echo PHP_EOL . 'اکثر کنترلرها اجازهٔ bind ناشناس نمی‌دهند. یک حساب سرویس با رمز در تنظیمات وارد کنید.' . PHP_EOL;
        exit(1);
    }
}

// ۵) جست‌وجو
$configuredFilter = (string) cfg('ldap.user_filter', '(&(objectCategory=person)(objectClass=user)(sAMAccountName=%s))');
$filter = ldap_person_filter(str_contains($configuredFilter, '%s')
    ? str_replace('%s', '*', $configuredFilter)
    : '(&(objectClass=user)(sAMAccountName=*))');
echo 'فیلتر جست‌وجو: ' . $filter . PHP_EOL;

$search = @ldap_search($connection, $baseDn, $filter, ['sAMAccountName'], 0, 5);
$line('جست‌وجوی کاربران', (bool) $search, $search ? '' : (function_exists('ldap_error') ? ldap_error($connection) : 'ناموفق'));
if ($search) {
    $entries = @ldap_get_entries($connection, $search);
    $count = (int) ($entries['count'] ?? 0);
    $line('تعداد کاربران یافت‌شده (نمونه)', $count > 0, $count . ' مورد');
    for ($i = 0; $i < min($count, 5); $i++) {
        echo '       - ' . (string) ($entries[$i]['samaccountname'][0] ?? '(بدون نام)') . PHP_EOL;
    }
} else {
    echo PHP_EOL . 'اگر جست‌وجو رد شد، دسترسی خواندن حساب سرویس به Base DN را بررسی کنید.' . PHP_EOL;
}

if (function_exists('ldap_unbind')) { @ldap_unbind($connection); }
echo PHP_EOL . '=== پایان تشخیص ===' . PHP_EOL;

<?php
declare(strict_types=1);

/**
 * آزمون آفلاینِ «مدیریت خطاهای استخراج» — نسخهٔ ۱.۳۷
 * ============================================================================
 *   php tools/test_scan_errors_offline.php
 *
 * بررسی می‌کند که دو خطای رایج استخراج («سرویس RPC/WMI در دسترس نیست» و «دسترسی رد
 * شد») واقعاً به کد خطا و راه‌حل گام‌به‌گام نگاشت می‌شوند و صفحهٔ اسکن می‌تواند
 * شکست‌ها را گروه‌بندی کند. هیچ اتصال شبکه/دیتابیسی لازم نیست.
 */

$root = dirname(__DIR__);
require $root . '/domain-scan.php';

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo '[PASS] ' . $label . "\n";
    } else {
        $fail++;
        echo '[FAIL] ' . $label . ($detail !== '' ? ' — ' . $detail : '') . "\n";
    }
}

/* ── ۱) کاتالوگ خطاها کامل و عملی است ─────────────────────────────────────── */
$catalog = domain_scan_error_catalog();
check('۱: کاتالوگ خطاها شامل کدهای اصلی است', isset($catalog['rpc_unavailable'], $catalog['access_denied'], $catalog['not_reachable'], $catalog['bad_credentials']));
$thin = [];
foreach ($catalog as $code => $entry) {
    if (trim((string) ($entry['title'] ?? '')) === '' || count((array) ($entry['remedy'] ?? [])) < 2) {
        $thin[] = $code;
    }
}
check('۲: هر کد خطا عنوان و حداقل دو گام راه‌حل دارد', $thin === [], implode(',', $thin));

/* ── ۲) پیام‌های واقعی ویندوز → پیام فارسی → کد خطا ──────────────────────── */
$samples = [
    'rpc_unavailable' => 'The RPC server is unavailable. (Exception from HRESULT: 0x800706BA)',
    'access_denied' => 'Access is denied. (Exception from HRESULT: 0x80070005 (E_ACCESSDENIED))',
    'bad_credentials' => 'Logon failure: unknown user name or bad password.',
    'no_computer_info' => 'NO_COMPUTER_INFO: target returned no computer information (check WMI/DCOM access and firewall).',
    'policy_blocked' => 'File C:\\xampp\\htdocs\\tools\\collect_server_inventory.ps1 cannot be loaded because running scripts is disabled on this system.',
];
$mismatch = [];
foreach ($samples as $expectedCode => $raw) {
    $message = domain_scan_friendly_error($raw);
    $detected = domain_scan_error_code($message);
    if ($detected !== $expectedCode) {
        $mismatch[] = $expectedCode . '→' . $detected . ' (' . $message . ')';
    }
}
check('۳: هر پیام خام ویندوز به کد خطای درست نگاشت می‌شود', $mismatch === [], implode(' | ', $mismatch));

$rpcMessage = domain_scan_friendly_error('The RPC server is unavailable. (0x800706BA)');
$deniedMessage = domain_scan_friendly_error('Access is denied. (0x80070005)');
check('۴: پیام «RPC/WMI» همان جملهٔ آشنای کاربر است', str_contains($rpcMessage, 'سرویس RPC/WMI روی سیستم مقصد در دسترس نیست'), $rpcMessage);
check('۵: پیام «دسترسی رد شد» همان جملهٔ آشنای کاربر است', str_contains($deniedMessage, 'دسترسی رد شد؛ حساب اسکن باید «ادمین محلی» همان کامپیوتر باشد'), $deniedMessage);

/* ── ۳) گروه‌بندی شکست‌های یک نوبت ─────────────────────────────────────────── */
$rows = [
    ['hostname' => 'PC-023', 'status' => 'error', 'message' => $rpcMessage],
    ['hostname' => 'PC-024', 'status' => 'error', 'message' => $rpcMessage],
    ['hostname' => 'PC-025', 'status' => 'error', 'message' => $deniedMessage],
    ['hostname' => 'PC-026', 'status' => 'error', 'message' => 'ذخیرهٔ اطلاعات ناموفق: SQLSTATE[22001] Data too long'],
    ['hostname' => 'PC-027', 'status' => 'offline', 'message' => 'در شبکه پاسخ نداد'],
    ['hostname' => 'PC-028', 'status' => 'done', 'message' => '۱۲ فیلد شناسنامه پر شد'],
    ['hostname' => 'PC-029', 'status' => 'pending', 'message' => null],
];
$groups = domain_scan_failure_summary($rows);
check('۶: فقط ردیف‌های خطادار/آفلاین گروه‌بندی می‌شوند (۴ علت برای ۵ ردیف)', count($groups) === 4, (string) count($groups));
check('۷: گروه‌ها بر اساس تعداد نزولی مرتب‌اند', (int) $groups[0]['count'] === 2 && $groups[0]['code'] === 'rpc_unavailable', $groups[0]['code'] . ':' . $groups[0]['count']);
$byCode = [];
foreach ($groups as $group) {
    $byCode[$group['code']] = $group;
}
check('۸: کدهای تشخیص‌داده‌شده درست‌اند', isset($byCode['rpc_unavailable'], $byCode['access_denied'], $byCode['store_failed'], $byCode['not_reachable']), implode(',', array_keys($byCode)));
check('۹: نام سیستم‌های هر گروه ثبت می‌شود و ردیف موفق داخل هیچ گروهی نیست',
    $byCode['rpc_unavailable']['hosts'] === ['PC-023', 'PC-024']
    && !in_array('PC-028', array_merge(...array_map(static fn (array $g): array => $g['hosts'], $groups)), true),
    implode('|', $byCode['rpc_unavailable']['hosts']));
check('۱۰: راه‌حل هر گروه از کاتالوگ می‌آید و خالی نیست', count($byCode['access_denied']['remedy']) >= 3);
check('۱۱: پیام واقعی سیستم در گزارش گروه دیده می‌شود', $byCode['store_failed']['messages'] !== []);

/* ── ۴) استخراج محلی (بدون RPC) و ترتیب پروتکل‌ها ─────────────────────────── */
$localNames = domain_scan_local_names();
check('۱۲: فهرست نام‌های محلی شامل localhost و 127.0.0.1 است',
    in_array('localhost', $localNames, true) && in_array('127.0.0.1', $localNames, true), implode(',', $localNames));
check('۱۳: فهرست نام‌های محلی مقدار خالی یا تکراری ندارد', $localNames === array_values(array_unique($localNames)) && !in_array('', $localNames, true));

$source = (string) file_get_contents($root . '/domain-scan.php');
check('۱۴: مسیر محلی (بدون ComputerName) در تابع استخراج هست', str_contains($source, "\$isLocal = in_array(strtolower(\$host), \$localNames, true)"));
check('۱۵: ترتیب پروتکل از تنظیمات به اسکریپت پاس داده می‌شود', str_contains($source, "ITSM_SCAN_PROTOCOLS"));
$ps1 = (string) file_get_contents($root . '/tools/collect_server_inventory.ps1');
check('۱۶: اسکریپت PowerShell ترتیب پروتکل را می‌خواند و پیش‌فرض Dcom,Wsman می‌ماند',
    str_contains($ps1, 'ITSM_SCAN_PROTOCOLS') && str_contains($ps1, "@('Dcom', 'Wsman')"));

/* ── ۵) اکشن «تلاش دوباره» و کارت راه‌حل در رابط کاربری ───────────────────── */
$indexSource = (string) file_get_contents($root . '/index.php');
check('۱۷: اکشن تلاش دوبارهٔ ناموفق‌ها در index.php ثبت شده است', str_contains($indexSource, "domain_scan_retry") && str_contains($indexSource, 'domain_scan_retry_failed'));
check('۱۸: کارت «دلایل شکست استخراج و راه‌حل» در صفحهٔ اسکن رندر می‌شود', str_contains($indexSource, 'دلایل شکست استخراج و راه‌حل') && str_contains($indexSource, 'domain_scan_failure_summary'));
check('۱۹: ترتیب پروتکل در تنظیمات قابل انتخاب است', str_contains($indexSource, 'domain_scan_protocols') && str_contains($indexSource, 'اول WinRM، بعد DCOM'));
check('۲۰: «تلاش دوباره» فقط ردیف‌های خطادار را برمی‌دارد (نه آفلاین‌ها و نه موفق‌ها)',
    str_contains($source, 'WHERE run_id = ? AND status = "error" ORDER BY id ASC'));

echo "\n" . ($fail === 0
    ? 'همهٔ ' . $pass . " بررسی مدیریت خطای استخراج موفق بود.\n"
    : $fail . ' بررسی ناموفق از ' . ($pass + $fail) . " مورد.\n");
exit($fail === 0 ? 0 : 1);
